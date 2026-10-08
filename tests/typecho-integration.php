<?php
/**
 * 使用官方原版 Typecho Db、Query、适配器及安装 SQL 的数据库集成测试。
 *
 * TYPECHO_ROOT=/path/to/typecho php tests/typecho-integration.php [Pdo_SQLite]
 * 支持适配器： Pdo_SQLite, SQLite, Pdo_Mysql, Mysqli, Pdo_Pgsql, Pgsql.
 * 网络数据库配置： IT_DB_HOST, IT_DB_PORT, IT_DB_NAME, IT_DB_USER, IT_DB_PASSWORD.
 * 仅对临时 CI 数据库运行，每次使用随机表前缀；两库隔离测试需要创建数据库权限。
 * 仅 options widget 使用边界 fixture，所有数据库操作均使用真实驱动。
 */
declare(strict_types=1);

namespace Utils {
    final class Helper
    {
        public static $options;
        public static function options(): object { return self::$options; }
    }
}

namespace {
    use Typecho\Db;
    use TypechoPlugin\InfinityTime\Plugin;
    use TypechoPlugin\InfinityTime\Lib\AdminRepository;
    use TypechoPlugin\InfinityTime\Lib\Database;
    use TypechoPlugin\InfinityTime\Lib\AdminSecurity;
    use TypechoPlugin\InfinityTime\Lib\AdminWorkflow;
    use TypechoPlugin\InfinityTime\Lib\ImageRepository;

    $typecho = realpath((string)getenv('TYPECHO_ROOT'));
    if (!$typecho || !is_file($typecho . '/var/Typecho/Db.php')) {
        fwrite(STDERR, "TYPECHO_ROOT must point to an official Typecho checkout.\n");
        exit(2);
    }
    $adapter = $argv[1] ?? 'Pdo_SQLite';
    $dialects = ['Pdo_SQLite' => 'SQLite', 'SQLite' => 'SQLite', 'Pdo_Mysql' => 'Mysql',
        'Mysqli' => 'Mysql', 'Pdo_Pgsql' => 'Pgsql', 'Pgsql' => 'Pgsql'];
    if (!isset($dialects[$adapter])) { throw new \InvalidArgumentException('Unsupported test adapter'); }
    $dialect = $dialects[$adapter];
    $prefix = 'it_test_' . bin2hex(random_bytes(6)) . '_';
    $tmp = sys_get_temp_dir() . '/' . $prefix;
    if (!mkdir($tmp, 0700)) { throw new \RuntimeException('Cannot create test directory'); }
    define('__TYPECHO_ROOT_DIR__', $typecho);
    define('__TYPECHO_PLUGIN_DIR__', '/usr/plugins');
    define('__TYPECHO_UPLOAD_ROOT_DIR__', $tmp . '/uploads');
    define('__TYPECHO_UPLOAD_DIR__', '/usr/uploads');
    // Same compatibility alias as Typecho's Widget\Init, with the real interface.
    define('__TYPECHO_CLASS_ALIASES__', ['Typecho_Plugin_Interface' => '\\Typecho\\Plugin\\PluginInterface']);
    require $typecho . '/var/Typecho/Common.php';
    spl_autoload_register(static function (string $class): void {
        $namespace = 'TypechoPlugin\\InfinityTime\\';
        if (strpos($class, $namespace) === 0) {
            $file = __DIR__ . '/../usr/plugins/InfinityTime/' . str_replace('\\', '/', substr($class, strlen($namespace))) . '.php';
            if (is_file($file)) { require_once $file; }
        }
    });
    \Utils\Helper::$options = new \stdClass();
    $db = new Db($adapter, $prefix);
    Db::set($db);
    $config = $dialect === 'SQLite' ? ['file' => $tmp . '/database.sqlite'] : [
        'host' => getenv('IT_DB_HOST') ?: '127.0.0.1',
        'port' => getenv('IT_DB_PORT') ?: ($dialect === 'Mysql' ? '3306' : '5432'),
        'database' => getenv('IT_DB_NAME') ?: 'infinitytime_test',
        'user' => getenv('IT_DB_USER') ?: ($dialect === 'Mysql' ? 'root' : 'postgres'),
        'password' => getenv('IT_DB_PASSWORD') ?: '',
        'charset' => $dialect === 'Mysql' ? 'utf8mb4' : 'UTF8',
    ];
    $db->addServer($config, Db::WRITE);
    // pg_connect reuses identical connection strings; distinct host spelling gives a real reader.
    if ($adapter === 'Pgsql' && $config['host'] === '127.0.0.1') { $config['host'] = 'localhost'; }
    $db->addServer($config, Db::READ);

    $checks = 0;
    function check(bool $passed, string $message): void
    {
        global $checks;
        if (!$passed) { throw new \RuntimeException($message); }
        $checks++;
    }
    function rejected(callable $operation, string $exception, string $message): void
    {
        try { $operation(); } catch (\Throwable $e) {
            check($e instanceof $exception, $message . ': wrong exception ' . get_class($e) . ': ' . $e->getMessage());
            return;
        }
        check(false, $message . ': operation unexpectedly succeeded');
    }
    function injectedFailure(callable $operation, string $message, string $expected = 'Integration failure'): void
    {
        try { $operation(); } catch (\Throwable $e) {
            // Upstream native Pgsql passes false to pg_result_error_field on SQL errors (PHP 8 TypeError).
            global $adapter;
            $upstreamPgError = $adapter === 'Pgsql' && strpos($e->getMessage(), 'pg_result_error_field') !== false;
            check(stripos($e->getMessage(), $expected) !== false || $upstreamPgError, $message . ': unexpected error ' . $e->getMessage());
            return;
        }
        check(false, $message . ': operation unexpectedly succeeded');
    }
    function table(string $name): string
    {
        global $prefix, $dialect;
        $quote = $dialect === 'Mysql' ? '`' : '"';
        return $quote . $prefix . $name . $quote;
    }
    function rowCount(string $name): int
    {
        return (int)Db::get()->fetchRow('SELECT COUNT(*) AS n FROM ' . table($name))['n'];
    }
    function fields(int $cid): array
    {
        return Db::get()->fetchAll(Db::get()->select()->from('table.fields')->where('cid = ?', $cid)->order('name', Db::SORT_ASC));
    }
    function post(array $data): int
    {
        return (int)Database::query(Db::get()->insert('table.contents')->rows($data + [
            'title' => 'Fixture post', 'slug' => bin2hex(random_bytes(10)), 'created' => 100,
            'modified' => 100, 'authorId' => 7, 'type' => 'post', 'status' => 'draft',
        ]));
    }
    function photo(int $cid, array $data = [], int $sort = 0): int
    {
        return ImageRepository::insertRow($cid, $data + [
            'original' => null, 'full' => '/usr/uploads/full/a.webp', 'thumb' => '/usr/uploads/thumb/a.webp',
            'width' => 1200, 'height' => 600, 'size' => 1000,
            'title' => 'photo', 'desc' => 'description', 'address' => 'Location',
            'exif' => ['make' => 'Camera', 'datetime' => '2024:05:06 10:20:30', 'gps' => ['lat' => 1]],
        ], $sort);
    }
    // Real database errors, after earlier writes in the same operation have succeeded.
    function failureTrigger(bool $enabled, string $field = 'tags'): void
    {
        global $db, $prefix, $dialect;
        $name = $prefix . 'reject_field';
        $fn = $prefix . 'reject_field_fn';
        if (!$enabled) {
            Database::query('DROP TRIGGER IF EXISTS ' . $name . ($dialect === 'Pgsql' ? ' ON ' . table('fields') : ''), Db::WRITE);
            if ($dialect === 'Pgsql') { Database::query('DROP FUNCTION IF EXISTS ' . $fn . '()', Db::WRITE); }
            return;
        }
        if (!in_array($field, ['tags', 'titles'], true)) { throw new \InvalidArgumentException('Invalid trigger field'); }
        if ($dialect === 'SQLite') {
            $sql = 'CREATE TRIGGER ' . $name . ' BEFORE INSERT ON ' . table('fields')
                . " WHEN NEW.name = '$field' BEGIN SELECT RAISE(ABORT, 'Integration failure'); END";
        } elseif ($dialect === 'Mysql') {
            $sql = 'CREATE TRIGGER ' . $name . ' BEFORE INSERT ON ' . table('fields')
                . " FOR EACH ROW BEGIN IF NEW.name = '$field' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Integration failure'; END IF; END";
        } else {
            Database::query('CREATE FUNCTION ' . $fn . "() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN IF NEW.name = '$field' THEN RAISE EXCEPTION 'Integration failure'; END IF; RETURN NEW; END $$", Db::WRITE);
            $sql = 'CREATE TRIGGER ' . $name . ' BEFORE INSERT ON ' . table('fields')
                . ' FOR EACH ROW EXECUTE PROCEDURE ' . $fn . '()';
        }
        Database::query($sql, Db::WRITE);
    }

    $failed = null;
    try {
        // Use the exact upstream schema, including unique keys and quoted camel-case columns.
        $schema = file_get_contents($typecho . '/install/' . $dialect . '.sql');
        if ($schema === false) { throw new \RuntimeException('Missing upstream install schema'); }
        $schema = preg_replace('/^\s*--[^\n]*(?:\n|$)/m', '', $schema);
        $schema = str_replace(['typecho_', '%engine%', '%charset%'], [$prefix, 'InnoDB', 'utf8mb4'], $schema);
        foreach (explode(';', $schema) as $statement) {
            if (trim($statement) !== '') { Database::query($statement, Db::WRITE); }
        }
        // Avoid activation's filesystem/tool/menu side effects, but execute production DDL.
        $create = new \ReflectionMethod(Plugin::class, 'createTables');
        $create->setAccessible(true);
        $create->invoke(null);
        check((int)Plugin::opt('infinitytimeSchemaVersion') === 4, 'Fresh schema migration must finish');
        check(rowCount('infinitytime_images') === 0, 'Fresh schema must be empty');
        $create->invoke(null);
        check(rowCount('options') === 1, 'Repeated setup must not duplicate schema options');
        check($db->selectDb(Db::READ) !== $db->selectDb(Db::WRITE), 'Test must exercise separate Typecho read/write connections');

        $resource = Database::query('SELECT 1 AS probe');
        check(Database::query($resource) === $resource, '已执行的真实查询资源必须原样返回');
        unset($resource);

        // Query placeholders are deferred by real Typecho. Include punctuation and Unicode.
        $text = "图集 ? 50%_! O'Reilly \\ #param:42#";
        $draft = AdminWorkflow::draft(7, ['title' => $text, 'device' => 'Camera ? 日本', 'tags' => 'night', 'address' => '杭州'], 'draft-operation-0001');
        $cid = (int)$draft['cid'];
        check($cid > 0 && $draft['status'] === 'draft' && !$draft['replayed'], 'Create a persisted draft');
        check(AdminRepository::album($cid, 7, false)['title'] === $text, 'Real adapters must preserve quoted and Unicode values');
        check(AdminRepository::field($cid, 'device') === 'Camera ? 日本', 'Draft metadata persisted');
        check(AdminRepository::field($cid, AdminRepository::MARKER) === '1', 'Empty draft has album marker');
        $again = AdminWorkflow::draft(7, ['title' => 'Do not overwrite'], 'draft-operation-0001');
        check((int)$again['cid'] === $cid && $again['replayed'], 'Retry returns the existing draft');
        check(rowCount('contents') === 1, 'Retry must not create duplicate posts');
        $other = AdminWorkflow::draft(8, ['title' => 'Other author'], 'draft-operation-0001');
        check((int)$other['cid'] !== $cid, 'Operation keys are scoped to their author');
        check(AdminRepository::album($cid, 8, false) === null, 'Foreign author cannot access an album');
        check(AdminRepository::album($cid, 8, true) !== null, 'Administrator can access foreign album');
        check(AdminRepository::album($cid, 0, true) === null, 'Missing user identity is rejected');
        rejected(static function () use ($cid) { AdminWorkflow::requireAlbum($cid, 8, false); }, \DomainException::class, 'Mutation refuses foreign album');
        rejected(static function () { AdminWorkflow::draft(7, ['title' => 'bad'], 'short'); }, \InvalidArgumentException::class, 'Invalid operation key rejected');
        rejected(static function () { AdminWorkflow::draft(7, ['title' => '  '], 'draft-operation-0002'); }, \InvalidArgumentException::class, 'Blank title rejected');
        check(AdminSecurity::validMutation('POST', 'token', 'token') && !AdminSecurity::validMutation('GET', 'token', 'token')
            && !AdminSecurity::validMutation('POST', '', '') && !AdminSecurity::validMutation('POST', 'token', ['token']), 'Mutation guard fails closed');

        $ordinary = post([]);
        check(AdminRepository::album($ordinary, 7, false) === null, 'Ordinary post is not an album');
        foreach (['attachment', 'page'] as $type) {
            $excluded = post(['type' => $type]);
            AdminRepository::setField($excluded, AdminRepository::MARKER, '1');
            check(AdminRepository::album($excluded, 7, true) === null, 'Wrong content type rejected: ' . $type);
        }
        $hidden = post(['status' => 'private']);
        AdminRepository::setField($hidden, AdminRepository::MARKER, '1');
        check(AdminRepository::album($hidden, 7, true) === null, 'Unsupported post status rejected');
        rejected(static function () use ($cid) { AdminWorkflow::publish($cid, 7, false, true); }, \InvalidArgumentException::class, 'Empty draft cannot publish');
        check(AdminRepository::page(7, false, 1, '50%_!')['total'] === 1, 'LIKE wildcard characters searched literally');
        check(AdminRepository::page(7, false, 1, "' OR 1=1 --")['total'] === 0, 'Search cannot inject SQL');
        check(AdminRepository::page(8, false)['total'] === 1, 'Pagination respects author scope');

        // A Query passed to Db::query(..., WRITE) is silently rerouted to READ by Typecho.
        // Production helpers must compile it to a string before choosing the writer.
        AdminRepository::setField($cid, 'device', 'before transaction');
        Database::query('BEGIN', Db::WRITE);
        try {
            AdminRepository::setField($cid, 'device', 'inside transaction');
            check(AdminRepository::field($cid, 'device', true) === 'inside transaction', 'Writer sees uncommitted metadata');
            check(AdminRepository::field($cid, 'device') === 'before transaction', 'Reader remains isolated from writer');
            $query = $db->select('str_value')->from('table.fields')->where('cid = ?', $cid)->where('name = ?', 'device');
            check(AdminRepository::readAll($query, true)[0]['str_value'] === 'inside transaction', 'readAll compiles and routes to writer');
        } finally { Database::query('ROLLBACK', Db::WRITE); }
        check(AdminRepository::field($cid, 'device') === 'before transaction', 'Rollback restores existing field');

        $before = rowCount('contents');
        failureTrigger(true);
        try {
            injectedFailure(static function () { AdminWorkflow::draft(7, ['title' => 'Rollback draft', 'device' => 'first write', 'tags' => 'fail'], 'draft-operation-fail'); },
                'Draft field failure surfaces database error');
        } finally { failureTrigger(false); }
        check(rowCount('contents') === $before, 'Draft and earlier fields rollback together');
        check(AdminRepository::draftForOperation(7, 'draft-operation-fail') === null, 'Failed draft leaves no operation marker');

        $a = photo($cid, ['full' => '/usr/uploads/full/late.webp', 'title' => 'Late', 'upload_key' => 'photo-operation-0001'], 20);
        $b = photo($cid, ['full' => '/usr/uploads/full/early.webp', 'title' => "Early ' ?", 'desc' => 'Unicode 雨', 'width' => 800, 'height' => 600], 10);
        check($a > 0 && $b > $a, 'Image inserts return real generated primary keys');
        injectedFailure(static function () use ($cid) { photo($cid, ['upload_key' => 'photo-operation-0001']); },
            'Same-album upload retry key is unique', $dialect === 'SQLite' ? 'UNIQUE' : 'duplicate');
        check(rowCount('infinitytime_images') === 2, 'Rejected duplicate must not add a row');
        check(photo((int)$other['cid'], ['upload_key' => 'photo-operation-0001']) > 0, 'Upload keys can repeat in different albums');
        check(photo((int)$other['cid']) > 0 && photo((int)$other['cid']) > 0, 'Multiple legacy NULL upload keys allowed');
        ImageRepository::syncPostFields($cid);
        check(AdminRepository::field($cid, 'img') === "/usr/uploads/full/early.webp\n/usr/uploads/full/late.webp", 'Image paths follow sort order');
        check(json_decode(AdminRepository::field($cid, 'photo_ids'), true) === [$b, $a], 'Photo IDs aligned with paths');
        check(json_decode(AdminRepository::field($cid, 'titles'), true) === ["Early ' ?", 'Late'], 'Titles aligned with paths');
        check(json_decode(AdminRepository::field($cid, 'dims'), true) === ['800x600', '1200x600'], 'Dimensions aligned with paths');
        check(json_decode(AdminRepository::field($cid, 'panos'), true) === [0, 1], 'Panorama metadata aligned');
        $exif = json_decode(AdminRepository::field($cid, 'exif'), true);
        check(!isset($exif[0]['gps']) && !isset($exif[1]['gps']), 'Public EXIF fields omit GPS');
        check(AdminRepository::counts([$cid, $cid, 0, -1]) === [$cid => 2], 'Managed image counts deduplicate IDs');
        check(count(ImageRepository::rowsForCids([$cid, (int)$other['cid']])) === 2, 'Batch image lookup groups albums');
        $legacy = post(['title' => 'Legacy album']);
        AdminRepository::setField($legacy, 'img', "/legacy/a.webp\n/legacy/b.webp");
        ImageRepository::syncPostFields($legacy);
        check(AdminRepository::counts([$legacy]) === [$legacy => 2], 'Legacy line-count syntax is portable');
        check(AdminRepository::album($legacy, 7, false) !== null, 'Legacy fields identify an album');
        check(AdminRepository::field($legacy, 'img') === "/legacy/a.webp\n/legacy/b.webp", 'Sync preserves legacy-only images');

        // Atomic replacement: fail after img/thumb have already been rewritten.
        $snapshot = fields($cid);
        Database::query($db->update(ImageRepository::table())->rows(['title' => 'Changed'])->where('id = ?', $b));
        failureTrigger(true, 'titles');
        try {
            injectedFailure(static function () use ($cid) { ImageRepository::syncPostFields($cid); },
                'Field-sync failure surfaces database error');
        } finally { failureTrigger(false); }
        check(fields($cid) === $snapshot, 'Failed field sync must retain the entire old snapshot');
        ImageRepository::syncPostFields($cid);
        check(json_decode(AdminRepository::field($cid, 'titles'), true)[0] === 'Changed', 'Retry successfully updates all fields');
        check(json_decode(AdminRepository::field($cid, 'months'), true) === ['2024-05', '2024-05'], 'EXIF dates are kept aligned');

        // Caller-owned transaction: sync must neither commit nor discard unrelated work.
        $outerSnapshot = fields($cid);
        Database::query('BEGIN', Db::WRITE);
        try {
            AdminRepository::setField($cid, 'device', 'outer successful sync');
            Database::query($db->update(ImageRepository::table())->rows(['title' => 'Uncommitted image'])->where('id = ?', $b));
            ImageRepository::syncPostFields($cid);
            check(json_decode(AdminRepository::field($cid, 'titles', true), true)[0] === 'Uncommitted image', 'Nested sync reads uncommitted writer image rows');
            check(AdminRepository::field($cid, 'device') === 'before transaction', 'Nested sync must not commit caller writes');
        } finally { Database::query('ROLLBACK', Db::WRITE); }
        check(fields($cid) === $outerSnapshot, 'Caller rollback restores successful nested field sync');

        failureTrigger(true, 'titles');
        Database::query('BEGIN', Db::WRITE);
        try {
            AdminRepository::setField($cid, 'device', 'outer survives failure');
            injectedFailure(static function () use ($cid) { ImageRepository::syncPostFields($cid); }, 'Nested sync reports injected error');
            check(AdminRepository::field($cid, 'device', true) === 'outer survives failure', 'Failed nested sync preserves earlier caller writes');
            check(json_decode(AdminRepository::field($cid, 'titles', true), true)[0] === 'Changed', 'Failed nested sync restores old title within caller transaction');
            AdminRepository::setField($cid, 'device', 'outer remains usable');
            check(AdminRepository::field($cid, 'device', true) === 'outer remains usable', 'Caller can continue using transaction after failed sync');
        } finally {
            Database::query('ROLLBACK', Db::WRITE);
            failureTrigger(false);
        }
        check(fields($cid) === $outerSnapshot, 'Caller rollback after failed sync restores all fields');

        rejected(static function () use ($cid) { AdminWorkflow::publish($cid, 7, false, false); }, \DomainException::class, 'Contributor cannot publish');
        AdminWorkflow::publish($cid, 7, false, true);
        check(AdminRepository::album($cid, 7, false)['status'] === 'publish', 'Editor publishes nonempty album');
        rejected(static function () use ($cid) { AdminWorkflow::requireAlbum($cid, 7, false, false); }, \DomainException::class, 'Contributor cannot modify published album');
        check(AdminWorkflow::requireAlbum($cid, 7, false, true)['status'] === 'publish', 'Editor can modify own published album');

        // 模拟真正滞后的副本：读库完全没有主库的图集、图片和自定义字段。
        $primaryDb = $db;
        $primaryConfig = $primaryDb->getConfig(Db::WRITE)->toArray();
        $replicaConfig = $primaryConfig;
        $replicaName = $prefix . 'replica';
        $replicaDb = null;
        $splitDb = null;
        $replicaCreated = false;
        try {
            if ($dialect === 'SQLite') {
                $replicaConfig['file'] = $tmp . '/replica.sqlite';
            } else {
                Database::query('CREATE DATABASE ' . ($dialect === 'Mysql' ? '`' : '"') . $replicaName . ($dialect === 'Mysql' ? '`' : '"'));
                $replicaConfig['database'] = $replicaName;
                $replicaCreated = true;
            }
            $replicaDb = new Db($adapter, $prefix);
            $replicaDb->addServer($replicaConfig, Db::READ | Db::WRITE);
            Db::set($replicaDb);
            foreach (explode(';', $schema) as $statement) {
                if (trim($statement) !== '') { Database::query($statement); }
            }
            $create->invoke(null);
            $splitDb = new Db($adapter, $prefix);
            $splitDb->addServer($primaryConfig, Db::WRITE);
            $splitDb->addServer($replicaConfig, Db::READ);
            Db::set($splitDb);
            // 先连接主库，再连接副本，复现官方 Mysqli 的内部连接被后一次 connect 覆盖。
            $splitDb->selectDb(Db::WRITE);
            check(AdminRepository::page(7, true)['total'] === 0, '滞后副本确实不含主库图集');
            check(AdminRepository::album($cid, 7, false) === null, '普通读取仍使用副本');
            check(AdminWorkflow::requireAlbum($cid, 7, false, true)['status'] === 'publish', '写权限检查必须读取主库');
            check(AdminRepository::field($cid, 'device', true) === 'before transaction', '字段写前检查必须读取主库');
            check((int)AdminRepository::draftForOperation(7, 'draft-operation-0001')['cid'] === $cid, '草稿重试必须读取主库');
            check(count(ImageRepository::rowsFor($cid)) === 2, '持锁图片读取必须使用主库');
            check(count(ImageRepository::rowsForCids([$cid])[$cid]) === 2, '持锁批量图片读取必须使用主库');
            check(AdminRepository::counts([$cid], true) === [$cid => 2], '发布计数必须读取主库');
            $isolatedDraft = AdminWorkflow::draft(7, ['title' => '主库新建草稿'], 'draft-primary-only');
            $newCid = (int)$isolatedDraft['cid'];
            check($newCid > 0 && AdminRepository::album($newCid, 7, false) === null, '新增草稿返回主库 ID，副本仍为空');
            check(AdminRepository::album($newCid, 7, false, true) !== null, '新增草稿确实写入主库');
            $newPhoto = photo($newCid, ['title' => '主库新图片']);
            check($newPhoto > 0 && count(ImageRepository::rowsFor($newCid)) === 1, '图片入库返回主库 ID');
            ImageRepository::syncPostFields($newCid);
            check(AdminRepository::field($newCid, 'img', true) === '/usr/uploads/full/a.webp', '字段同步读取并写入主库');
            check(AdminRepository::field($newCid, 'img') === '', '字段同步没有污染副本');
            check(Database::query($splitDb->update(ImageRepository::table())->rows(['title' => '修改主库图片'])->where('id = ?', $newPhoto)) === 1, '更新返回主库实际影响行数');
            check(Database::query($splitDb->delete(ImageRepository::table())->where('id = ?', $newPhoto)) === 1, '删除返回主库实际影响行数');
            check(count(ImageRepository::rowsFor($newCid)) === 0, '删除确实发生在主库');
        } finally {
            Db::set($primaryDb);
            foreach ([$splitDb, $replicaDb] as $connection) {
                if ($connection !== null) {
                    // native pg_connect 会缓存连接，显式断开后才允许 DROP DATABASE。
                    if ($adapter === 'Pgsql') {
                        foreach ([Db::READ, Db::WRITE] as $operation) {
                            try {
                                $handle = $connection->selectDb($operation);
                                if ($handle !== $primaryDb->selectDb(Db::WRITE) && $handle !== $primaryDb->selectDb(Db::READ)) { pg_close($handle); }
                            } catch (\Throwable $ignored) {}
                        }
                    }
                    $connection->flushPool();
                }
            }
            unset($connection, $splitDb, $replicaDb);
            if ($replicaCreated) {
                Database::query('DROP DATABASE ' . ($dialect === 'Mysql' ? '`' : '"') . $replicaName . ($dialect === 'Mysql' ? '`' : '"'));
            }
            foreach (glob($tmp . '/replica.sqlite*') ?: [] as $file) { @unlink($file); }
        }

        Database::query($db->delete(ImageRepository::table())->where('cid = ?', $cid));
        ImageRepository::syncPostFields($cid, true);
        check(AdminRepository::field($cid, 'img') === '' && AdminRepository::field($cid, 'photo_ids') === '', 'Explicit clear removes last-image metadata');
        check(AdminRepository::album($cid, 7, false) !== null, 'Cleared album keeps its marker');

        // Genuine old-install migration: absent metadata/retry columns, existing rows preserved.
        Database::query('DROP TABLE ' . table('infinitytime_images'), Db::WRITE);
        $pk = $dialect === 'Mysql' ? 'id INTEGER NOT NULL AUTO_INCREMENT PRIMARY KEY'
            : ($dialect === 'Pgsql' ? 'id SERIAL PRIMARY KEY' : 'id INTEGER PRIMARY KEY AUTOINCREMENT');
        Database::query('CREATE TABLE ' . table('infinitytime_images') . ' (' . $pk . ', cid INTEGER, ' . ($dialect === 'Mysql' ? '`full`' : '"full"') . ' TEXT)', Db::WRITE);
        Database::query($db->insert(ImageRepository::table())->rows(['cid' => 1, 'full' => '/preserved.webp']));
        Plugin::setOption('infinitytimeSchemaVersion', '2');
        Plugin::migrateSchema();
        $preserved = $db->fetchRow($db->select()->from(ImageRepository::table()));
        check($preserved['full'] === '/preserved.webp' && $preserved['upload_key'] === null, 'Migration preserves existing rows with nullable retry keys');
        check(array_key_exists('mid_avif', $preserved) && array_key_exists('desc', $preserved), 'Migration repairs every historical missing column');
        Plugin::setOption('infinitytimeSchemaVersion', '3');
        Plugin::migrateSchema();
        check((int)Plugin::opt('infinitytimeSchemaVersion') === 4, 'Interrupted migration validates existing unique index');
        check(rowCount('infinitytime_images') === 1, 'Repeated migration never duplicates images');
        echo 'PASS: ' . $checks . ' real Typecho ' . \Typecho\Common::VERSION . ' / PHP ' . PHP_VERSION . ' / ' . $adapter . " checks\n";
    } catch (\Throwable $e) {
        $failed = $e;
    } finally {
        ImageRepository::unlockMedia();
        // Only our random-prefixed objects are removed, including after a failing assertion.
        foreach (['infinitytime_images', 'fields', 'contents', 'comments', 'metas', 'options', 'relationships', 'users'] as $name) {
            try { Database::query('DROP TABLE IF EXISTS ' . table($name) . ($dialect === 'Pgsql' ? ' CASCADE' : ''), Db::WRITE); }
            catch (\Throwable $cleanup) { fwrite(STDERR, 'Cleanup: ' . $cleanup->getMessage() . "\n"); }
        }
        if ($dialect === 'Pgsql') {
            foreach (['comments', 'contents', 'metas', 'users'] as $name) {
                try { Database::query('DROP SEQUENCE IF EXISTS ' . table($name . '_seq'), Db::WRITE); } catch (\Throwable $cleanup) {}
            }
            try { Database::query('DROP FUNCTION IF EXISTS ' . $prefix . 'reject_field_fn()', Db::WRITE); } catch (\Throwable $cleanup) {}
        }
        $db->flushPool();
        foreach (glob($tmp . '/database.sqlite*') ?: [] as $file) { @unlink($file); }
        @unlink($tmp . '/uploads/.infinitytime-media.lock');
        @rmdir($tmp . '/uploads');
        @rmdir($tmp);
    }
    if ($failed) {
        fwrite(STDERR, 'FAIL: ' . get_class($failed) . ': ' . $failed->getMessage() . "\n" . $failed->getTraceAsString() . "\n");
        exit(1);
    }
}
