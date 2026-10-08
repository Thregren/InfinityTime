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
        fwrite(STDERR, "TYPECHO_ROOT 必须指向官方 Typecho 源码目录。\n");
        exit(2);
    }
    $adapter = $argv[1] ?? 'Pdo_SQLite';
    $dialects = ['Pdo_SQLite' => 'SQLite', 'SQLite' => 'SQLite', 'Pdo_Mysql' => 'Mysql',
        'Mysqli' => 'Mysql', 'Pdo_Pgsql' => 'Pgsql', 'Pgsql' => 'Pgsql'];
    if (!isset($dialects[$adapter])) { throw new \InvalidArgumentException('不支持的测试适配器'); }
    $dialect = $dialects[$adapter];
    $prefix = 'it_test_' . bin2hex(random_bytes(6)) . '_';
    $tmp = sys_get_temp_dir() . '/' . $prefix;
    if (!mkdir($tmp, 0700)) { throw new \RuntimeException('无法创建临时测试目录'); }
    define('__TYPECHO_ROOT_DIR__', $typecho);
    define('__TYPECHO_PLUGIN_DIR__', '/usr/plugins');
    define('__TYPECHO_UPLOAD_ROOT_DIR__', $tmp . '/uploads');
    define('__TYPECHO_UPLOAD_DIR__', '/usr/uploads');
    // 采用 Typecho Widget\Init 的兼容别名，并加载真实插件接口。
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
    // pg_connect 会复用相同连接串；使用不同主机写法确保读写为独立连接。
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
            check($e instanceof $exception, $message . '：异常类型不符 ' . get_class($e) . ': ' . $e->getMessage());
            return;
        }
        check(false, $message . '：本应拒绝的操作意外成功');
    }
    function injectedFailure(callable $operation, string $message, string $expected = 'Integration failure'): void
    {
        try { $operation(); } catch (\Throwable $e) {
            // 上游原生 Pgsql 在 SQL 失败时向 pg_result_error_field 传入 false，PHP 8 会抛 TypeError。
            global $adapter;
            $upstreamPgError = $adapter === 'Pgsql' && strpos($e->getMessage(), 'pg_result_error_field') !== false;
            check(stripos($e->getMessage(), $expected) !== false || $upstreamPgError, $message . '：非预期错误 ' . $e->getMessage());
            return;
        }
        check(false, $message . '：本应拒绝的操作意外成功');
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
    // 同一操作已有部分写入成功后，通过真实数据库触发器注入错误。
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
        if (!in_array($field, ['tags', 'titles'], true)) { throw new \InvalidArgumentException('触发器字段无效'); }
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
    $fixtureFiles = [];
    try {
        // 使用官方建表 SQL，包含真实唯一约束及需要引号的驼峰列名。
        $schema = file_get_contents($typecho . '/install/' . $dialect . '.sql');
        if ($schema === false) { throw new \RuntimeException('缺少官方安装 SQL'); }
        $schema = preg_replace('/^\s*--[^\n]*(?:\n|$)/m', '', $schema);
        $schema = str_replace(['typecho_', '%engine%', '%charset%'], [$prefix, 'InnoDB', 'utf8mb4'], $schema);
        foreach (explode(';', $schema) as $statement) {
            if (trim($statement) !== '') { Database::query($statement, Db::WRITE); }
        }
        // 通过反射调用生产建表代码，避免激活时修改目录、工具和菜单。
        $create = new \ReflectionMethod(Plugin::class, 'createTables');
        $create->setAccessible(true);
        $create->invoke(null);
        check((int)Plugin::opt('infinitytimeSchemaVersion') === 4, '首次建表必须完成版本迁移');
        check(rowCount('infinitytime_images') === 0, '首次建表不得产生图片记录');
        $create->invoke(null);
        check(rowCount('options') === 1, '重复初始化不得重复插入版本选项');
        check($db->selectDb(Db::READ) !== $db->selectDb(Db::WRITE), '测试必须使用独立的 Typecho 读写连接');

        $resource = Database::query('SELECT 1 AS probe');
        check(Database::query($resource) === $resource, '已执行的真实查询资源必须原样返回');
        unset($resource);

        // 真实 Typecho 延迟展开查询参数，覆盖标点、占位符字面量及 Unicode。
        $text = "图集 ? 50%_! O'Reilly \\ #param:42#";
        $draft = AdminWorkflow::draft(7, ['title' => $text, 'device' => 'Camera ? 日本', 'tags' => 'night', 'address' => '杭州'], 'draft-operation-0001');
        $cid = (int)$draft['cid'];
        check($cid > 0 && $draft['status'] === 'draft' && !$draft['replayed'], '创建持久化草稿');
        check(AdminRepository::album($cid, 7, false)['title'] === $text, '真实适配器必须保留引号及 Unicode');
        check(AdminRepository::field($cid, 'device') === 'Camera ? 日本', '草稿元数据成功保存');
        check(AdminRepository::field($cid, AdminRepository::MARKER) === '1', '空草稿具有图集标记');
        $again = AdminWorkflow::draft(7, ['title' => 'Do not overwrite'], 'draft-operation-0001');
        check((int)$again['cid'] === $cid && $again['replayed'], '重试返回原有草稿');
        check(rowCount('contents') === 1, '重试不得创建重复文章');
        $other = AdminWorkflow::draft(8, ['title' => 'Other author'], 'draft-operation-0001');
        check((int)$other['cid'] !== $cid, '操作标识按作者隔离');
        check(AdminRepository::album($cid, 8, false) === null, '其他作者不得访问图集');
        check(AdminRepository::album($cid, 8, true) !== null, '管理员可访问其他作者图集');
        check(AdminRepository::album($cid, 0, true) === null, '缺少用户身份时拒绝访问');
        rejected(static function () use ($cid) { AdminWorkflow::requireAlbum($cid, 8, false); }, \DomainException::class, '写操作拒绝修改其他作者图集');
        rejected(static function () { AdminWorkflow::draft(7, ['title' => 'bad'], 'short'); }, \InvalidArgumentException::class, '拒绝无效操作标识');
        rejected(static function () { AdminWorkflow::draft(7, ['title' => '  '], 'draft-operation-0002'); }, \InvalidArgumentException::class, '拒绝空白标题');
        check(AdminSecurity::validMutation('POST', 'token', 'token') && !AdminSecurity::validMutation('GET', 'token', 'token')
            && !AdminSecurity::validMutation('POST', '', '') && !AdminSecurity::validMutation('POST', 'token', ['token']), '写操作校验缺失时必须拒绝');

        $ordinary = post([]);
        check(AdminRepository::album($ordinary, 7, false) === null, '普通文章不应被识别为图集');
        foreach (['attachment', 'page'] as $type) {
            $excluded = post(['type' => $type]);
            AdminRepository::setField($excluded, AdminRepository::MARKER, '1');
            check(AdminRepository::album($excluded, 7, true) === null, '拒绝非文章内容类型：' . $type);
        }
        $hidden = post(['status' => 'private']);
        AdminRepository::setField($hidden, AdminRepository::MARKER, '1');
        check(AdminRepository::album($hidden, 7, true) === null, '拒绝不支持的文章状态');
        rejected(static function () use ($cid) { AdminWorkflow::publish($cid, 7, false, true); }, \InvalidArgumentException::class, '空图集不能发布');
        check(AdminRepository::page(7, false, 1, '50%_!')['total'] === 1, '搜索中的 LIKE 通配符按字面处理');
        check(AdminRepository::page(7, false, 1, "' OR 1=1 --")['total'] === 0, '搜索不得注入 SQL');
        check(AdminRepository::page(8, false)['total'] === 1, '分页仅返回有权限的作者内容');

        // Typecho 会将传给 Db::query(..., WRITE) 的 SELECT Query 对象重新路由到 READ。
        // 生产辅助函数必须先编译字符串，再选择主库连接。
        AdminRepository::setField($cid, 'device', 'before transaction');
        Database::query('BEGIN', Db::WRITE);
        try {
            AdminRepository::setField($cid, 'device', 'inside transaction');
            check(AdminRepository::field($cid, 'device', true) === 'inside transaction', '主库读取可见尚未提交的元数据');
            check(AdminRepository::field($cid, 'device') === 'before transaction', '读库不能看见主库未提交的数据');
            $query = $db->select('str_value')->from('table.fields')->where('cid = ?', $cid)->where('name = ?', 'device');
            check(AdminRepository::readAll($query, true)[0]['str_value'] === 'inside transaction', 'readAll 编译查询并读取主库');
        } finally { Database::query('ROLLBACK', Db::WRITE); }
        check(AdminRepository::field($cid, 'device') === 'before transaction', '回滚恢复原有字段');

        $before = rowCount('contents');
        failureTrigger(true);
        try {
            injectedFailure(static function () { AdminWorkflow::draft(7, ['title' => 'Rollback draft', 'device' => 'first write', 'tags' => 'fail'], 'draft-operation-fail'); },
                '草稿字段失败必须抛出数据库错误');
        } finally { failureTrigger(false); }
        check(rowCount('contents') === $before, '草稿及此前字段写入必须共同回滚');
        check(AdminRepository::draftForOperation(7, 'draft-operation-fail') === null, '失败草稿不得残留操作标记');

        $a = photo($cid, ['full' => '/usr/uploads/full/late.webp', 'title' => 'Late', 'upload_key' => 'photo-operation-0001'], 20);
        $b = photo($cid, ['full' => '/usr/uploads/full/early.webp', 'title' => "Early ' ?", 'desc' => 'Unicode 雨', 'width' => 800, 'height' => 600], 10);
        check($a > 0 && $b > $a, '图片插入返回真实自增主键');
        injectedFailure(static function () use ($cid) { photo($cid, ['upload_key' => 'photo-operation-0001']); },
            '同一图集内上传重试标识必须唯一', $dialect === 'SQLite' ? 'UNIQUE' : 'duplicate');
        check(rowCount('infinitytime_images') === 2, '拒绝重复上传时不得增加图片记录');
        check(photo((int)$other['cid'], ['upload_key' => 'photo-operation-0001']) > 0, '不同图集可使用相同上传标识');
        check(photo((int)$other['cid']) > 0 && photo((int)$other['cid']) > 0, '兼容多条旧记录的 NULL 上传标识');
        ImageRepository::syncPostFields($cid);
        check(AdminRepository::field($cid, 'img') === "/usr/uploads/full/early.webp\n/usr/uploads/full/late.webp", '图片路径按排序字段排列');
        check(json_decode(AdminRepository::field($cid, 'photo_ids'), true) === [$b, $a], '图片 ID 与路径逐项对齐');
        check(json_decode(AdminRepository::field($cid, 'titles'), true) === ["Early ' ?", 'Late'], '标题与路径逐项对齐');
        check(json_decode(AdminRepository::field($cid, 'dims'), true) === ['800x600', '1200x600'], '尺寸与路径逐项对齐');
        check(json_decode(AdminRepository::field($cid, 'panos'), true) === [0, 1], '全景标记与图片逐项对齐');
        $exif = json_decode(AdminRepository::field($cid, 'exif'), true);
        check(!isset($exif[0]['gps']) && !isset($exif[1]['gps']), '公开 EXIF 字段不含 GPS');
        check(AdminRepository::counts([$cid, $cid, 0, -1]) === [$cid => 2], '图片计数去重并忽略无效图集 ID');
        check(count(ImageRepository::rowsForCids([$cid, (int)$other['cid']])) === 2, '批量图片查询按图集分组');
        $legacy = post(['title' => 'Legacy album']);
        AdminRepository::setField($legacy, 'img', "/legacy/a.webp\n/legacy/b.webp");
        ImageRepository::syncPostFields($legacy);
        check(AdminRepository::counts([$legacy]) === [$legacy => 2], '旧图集按换行计数的 SQL 跨数据库兼容');
        check(AdminRepository::album($legacy, 7, false) !== null, '旧字段仍可识别图集');
        check(AdminRepository::field($legacy, 'img') === "/legacy/a.webp\n/legacy/b.webp", '同步保留只有旧字段的图片');

        // 原子替换：在 img/thumb 已重写之后，令后续字段插入失败。
        $snapshot = fields($cid);
        Database::query($db->update(ImageRepository::table())->rows(['title' => 'Changed'])->where('id = ?', $b));
        failureTrigger(true, 'titles');
        try {
            injectedFailure(static function () use ($cid) { ImageRepository::syncPostFields($cid); },
                '字段同步失败必须抛出数据库错误');
        } finally { failureTrigger(false); }
        check(fields($cid) === $snapshot, '字段同步失败必须完整保留旧快照');
        ImageRepository::syncPostFields($cid);
        check(json_decode(AdminRepository::field($cid, 'titles'), true)[0] === 'Changed', '重试能够更新全部字段');
        check(json_decode(AdminRepository::field($cid, 'months'), true) === ['2024-05', '2024-05'], 'EXIF 拍摄月份与图片逐项对齐');

        // 调用方已有事务时，同步不得提交或丢弃调用方的其他修改。
        $outerSnapshot = fields($cid);
        Database::query('BEGIN', Db::WRITE);
        try {
            AdminRepository::setField($cid, 'device', 'outer successful sync');
            Database::query($db->update(ImageRepository::table())->rows(['title' => 'Uncommitted image'])->where('id = ?', $b));
            ImageRepository::syncPostFields($cid);
            check(json_decode(AdminRepository::field($cid, 'titles', true), true)[0] === 'Uncommitted image', '嵌套同步读取主库未提交图片');
            check(AdminRepository::field($cid, 'device') === 'before transaction', '嵌套同步不得提交调用方写入');
        } finally { Database::query('ROLLBACK', Db::WRITE); }
        check(fields($cid) === $outerSnapshot, '调用方回滚可撤销成功的嵌套同步');

        failureTrigger(true, 'titles');
        Database::query('BEGIN', Db::WRITE);
        try {
            AdminRepository::setField($cid, 'device', 'outer survives failure');
            injectedFailure(static function () use ($cid) { ImageRepository::syncPostFields($cid); }, '嵌套同步正确报告注入错误');
            check(AdminRepository::field($cid, 'device', true) === 'outer survives failure', '嵌套同步失败保留调用方此前写入');
            check(json_decode(AdminRepository::field($cid, 'titles', true), true)[0] === 'Changed', '嵌套同步失败后事务内标题恢复旧值');
            AdminRepository::setField($cid, 'device', 'outer remains usable');
            check(AdminRepository::field($cid, 'device', true) === 'outer remains usable', '同步失败后调用方仍可继续使用事务');
        } finally {
            Database::query('ROLLBACK', Db::WRITE);
            failureTrigger(false);
        }
        check(fields($cid) === $outerSnapshot, '同步失败后调用方回滚恢复全部字段');

        if ($dialect === 'Mysql') {
            // autocommit=0 的隐式事务也属于调用方，不能被同步函数提交。
            Database::query('SET autocommit = 0');
            try {
                AdminRepository::setField($cid, 'device', 'autocommit-off');
                ImageRepository::syncPostFields($cid);
                check(AdminRepository::field($cid, 'device', true) === 'autocommit-off', '同步保留 autocommit=0 事务中的修改');
                check(AdminRepository::field($cid, 'device') === 'before transaction', '同步不提交 autocommit=0 的调用方事务');
            } finally {
                Database::query('ROLLBACK');
                Database::query('SET autocommit = 1');
            }
            check(fields($cid) === $outerSnapshot, '隐式事务回滚后原字段完整保留');
            Database::query('ALTER TABLE ' . table('fields') . ' ENGINE=MyISAM');
            try {
                rejected(static function () use ($cid) { ImageRepository::syncPostFields($cid); }, \RuntimeException::class, '不支持回滚的 MyISAM 必须拒绝同步');
                check(fields($cid) === $outerSnapshot, '拒绝 MyISAM 时不能改动任何字段');
            } finally { Database::query('ALTER TABLE ' . table('fields') . ' ENGINE=InnoDB'); }
        }

        rejected(static function () use ($cid) { AdminWorkflow::publish($cid, 7, false, false); }, \DomainException::class, '贡献者不能发布图集');
        AdminWorkflow::publish($cid, 7, false, true);
        check(AdminRepository::album($cid, 7, false)['status'] === 'publish', '编辑可发布非空图集');
        rejected(static function () use ($cid) { AdminWorkflow::requireAlbum($cid, 7, false, false); }, \DomainException::class, '贡献者不能修改已发布图集');
        check(AdminWorkflow::requireAlbum($cid, 7, false, true)['status'] === 'publish', '编辑可修改自己已发布的图集');

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
            Plugin::setOption('infinitytimeReplicaProbe', '仅主库');
            $probeQuery = $splitDb->select('value')->from('table.options')->where('name = ?', 'infinitytimeReplicaProbe');
            check(AdminRepository::readRow($probeQuery, true)['value'] === '仅主库', '选项写入只发生在主库');
            check($splitDb->fetchRow($probeQuery) === null, '选项写入没有污染副本');
            Plugin::setOption('infinitytimeSchemaVersion', '3');
            Plugin::migrateSchema();
            $versionQuery = $splitDb->select('value')->from('table.options')->where('name = ?', 'infinitytimeSchemaVersion');
            check((int)AdminRepository::readRow($versionQuery, true)['value'] === 4, '主库迁移完成后版本正确');
            check($splitDb->fetchRow($versionQuery) === null, '迁移没有写入副本版本');

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
            unset($connection, $splitDb, $replicaDb, $probeQuery, $versionQuery);
            if ($replicaCreated) {
                Database::query('DROP DATABASE ' . ($dialect === 'Mysql' ? '`' : '"') . $replicaName . ($dialect === 'Mysql' ? '`' : '"'));
            }
            foreach (glob($tmp . '/replica.sqlite*') ?: [] as $file) { @unlink($file); }
        }

        // 原子编辑、嵌套事务、故障注入与非事务引擎回归。
        require __DIR__ . '/edit-workflow.php';

        // 真实删除事务：数据库字段失败、外层回滚都不能提前移除实体文件。
        $deleteDraft = AdminWorkflow::draft(7, ['title' => '删除事务验证', 'device' => '删除前设备'], 'draft-delete-tests');
        $deleteCid = (int)$deleteDraft['cid'];
        $makeFiles = static function (string $name) use (&$fixtureFiles): array {
            $paths = [];
            foreach (['original' => 'original', 'full' => 'full', 'thumb' => 'thumb', 'mid' => 'full', 'avif' => 'full', 'mid_avif' => 'full'] as $variant => $directory) {
                $relative = '/usr/uploads/' . $directory . '/' . $name . '-' . $variant . '.webp';
                $absolute = ImageRepository::toAbs($relative);
                if (!is_dir(dirname($absolute)) && !mkdir(dirname($absolute), 0700, true)) {
                    throw new \RuntimeException('无法创建删除测试图片目录');
                }
                if (file_put_contents($absolute, 'integration-image-' . $name . '-' . $variant) === false) {
                    throw new \RuntimeException('无法创建删除测试图片');
                }
                $fixtureFiles[] = $absolute;
                $paths[$variant] = $relative;
            }
            return $paths;
        };
        $targetPaths = $makeFiles('delete-target');
        $keeperPaths = $makeFiles('delete-keeper');
        $allFilesExist = static function (array $paths): bool {
            foreach ($paths as $relative) {
                $absolute = ImageRepository::toAbs($relative);
                clearstatcache(true, $absolute);
                if (!is_file($absolute)) { return false; }
            }
            return true;
        };
        $allFilesGone = static function (array $paths): bool {
            foreach ($paths as $relative) {
                $absolute = ImageRepository::toAbs($relative);
                clearstatcache(true, $absolute);
                if (file_exists($absolute)) { return false; }
            }
            return true;
        };
        $deleteId = photo($deleteCid, $targetPaths + ['title' => '待删除图片'], 0);
        $keeperId = photo($deleteCid, $keeperPaths + ['title' => '保留图片'], 1);
        ImageRepository::syncPostFields($deleteCid);
        $deleteSnapshot = fields($deleteCid);
        check(!ImageRepository::deleteImage($deleteId, $deleteCid + 1000000), '删除必须同时匹配图片 ID 与图集 ID');
        check(count(ImageRepository::rowsFor($deleteCid)) === 2 && fields($deleteCid) === $deleteSnapshot, '图集 ID 不匹配时不改动图片或字段');
        check($allFilesExist($targetPaths), '图集 ID 不匹配时保留全部实体变体');
        failureTrigger(true, 'titles');
        try {
            injectedFailure(static function () use ($deleteId, $deleteCid) { ImageRepository::deleteImage($deleteId, $deleteCid); }, '删除图片时字段失败必须抛出错误');
        } finally { failureTrigger(false); }
        check(count(ImageRepository::rowsFor($deleteCid)) === 2, '删除后字段失败必须回滚图片行');
        check(fields($deleteCid) === $deleteSnapshot, '删除后字段失败必须恢复全部字段快照');
        check($allFilesExist($targetPaths) && $allFilesExist($keeperPaths), '删除失败必须保留全部实体变体');
        Database::query('BEGIN');
        try {
            AdminRepository::setField($deleteCid, 'device', '调用方未提交设备');
            check(ImageRepository::deleteImage($deleteId, $deleteCid), '外层事务内允许删除图片记录');
            check(count(ImageRepository::rowsFor($deleteCid)) === 1, '外层事务内可见图片已删除');
            check(AdminRepository::field($deleteCid, 'img', true) === $keeperPaths['full'], '外层事务内字段同步保留剩余图片');
            check(AdminRepository::field($deleteCid, 'device', true) === '调用方未提交设备', '删除操作保留外层事务此前写入');
            check(AdminRepository::field($deleteCid, 'device') === '删除前设备', '删除操作不得提交外层事务');
            check($allFilesExist($targetPaths), '外层事务未结束前不能清理已删除图片的文件');
        } finally { Database::query('ROLLBACK'); }
        check(count(ImageRepository::rowsFor($deleteCid)) === 2, '调用方回滚恢复被删除的图片行');
        check(fields($deleteCid) === $deleteSnapshot, '调用方回滚恢复删除前全部字段');
        check($allFilesExist($targetPaths), '调用方回滚后全部图片变体仍存在');
        if ($dialect === 'Mysql') {
            Database::query('ALTER TABLE ' . table('infinitytime_images') . ' ENGINE=MyISAM');
            try {
                rejected(static function () use ($deleteId, $deleteCid) { ImageRepository::deleteImage($deleteId, $deleteCid); }, \RuntimeException::class, '图片表为 MyISAM 时必须拒绝删除');
                check(count(ImageRepository::rowsFor($deleteCid)) === 2 && fields($deleteCid) === $deleteSnapshot, '拒绝 MyISAM 图片表时不改动数据库记录');
                check($allFilesExist($targetPaths), '拒绝 MyISAM 图片表时保留全部实体变体');
            } finally { Database::query('ALTER TABLE ' . table('infinitytime_images') . ' ENGINE=InnoDB'); }
        }
        check(ImageRepository::deleteImage($deleteId, $deleteCid), '独立删除事务成功提交');
        check(count(ImageRepository::rowsFor($deleteCid)) === 1, '提交后只删除目标图片行');
        check(AdminRepository::field($deleteCid, 'img') === $keeperPaths['full'], '提交后字段只保留剩余图片');
        check(json_decode(AdminRepository::field($deleteCid, 'photo_ids'), true) === [$keeperId], '删除后图片 ID 与路径保持对齐');
        check($allFilesGone($targetPaths), '独立事务提交后清理全部无引用图片变体');
        check($allFilesExist($keeperPaths), '删除目标图片不能清理其他图片变体');
        check(!ImageRepository::deleteImage($deleteId, $deleteCid), '重复删除返回未找到而不重复修改');
        $sharedId = photo($deleteCid, $keeperPaths + ['title' => '共用实体文件'], 2);
        ImageRepository::syncPostFields($deleteCid);
        check(ImageRepository::deleteImage($sharedId, $deleteCid), '允许删除引用共用文件的图片行');
        check($allFilesExist($keeperPaths), '仍被其他图片引用的全部变体必须保留');
        check(ImageRepository::deleteImage($keeperId, $deleteCid), '允许删除图集最后一张图片');
        check(ImageRepository::rowsFor($deleteCid) === [], '删除最后一张图片后图集无图片记录');
        check(AdminRepository::field($deleteCid, 'img') === '' && AdminRepository::field($deleteCid, 'photo_ids') === '', '删除最后一张图片清空路径及对齐元数据');
        check(AdminRepository::album($deleteCid, 7, false) !== null, '删除最后一张图片后仍保留图集身份');
        check($allFilesGone($keeperPaths), '最后一个引用删除后才清理共用变体文件');

        // 整图集删除必须在同一事务内处理 images、fields 和 contents 三张表。
        $albumDraft = AdminWorkflow::draft(7, ['title' => '整图集删除', 'device' => '图集原设备'], 'draft-delete-album');
        $albumCid = (int)$albumDraft['cid'];
        $sharedDraft = AdminWorkflow::draft(7, ['title' => '跨图集共享引用'], 'draft-shared-album');
        $sharedCid = (int)$sharedDraft['cid'];
        $privateAlbumPaths = $makeFiles('album-private');
        $sharedAlbumPaths = $makeFiles('album-shared');
        photo($albumCid, $privateAlbumPaths, 0);
        photo($albumCid, $sharedAlbumPaths, 1);
        photo($sharedCid, $sharedAlbumPaths);
        ImageRepository::syncPostFields($albumCid);
        ImageRepository::syncPostFields($sharedCid);
        $albumFields = fields($albumCid);
        $albumImages = ImageRepository::rowsFor($albumCid);
        $contentFor = static function (int $id): array {
            return AdminRepository::readRow(Db::get()->select()->from('table.contents')->where('cid = ?', $id), true);
        };
        $albumContent = $contentFor($albumCid);
        $deleteTrigger = static function (bool $enabled, string $target) use ($prefix, $dialect): void {
            if (!in_array($target, ['fields', 'contents'], true)) { throw new \InvalidArgumentException('删除触发器表无效'); }
            $name = $prefix . 'reject_album_delete';
            $fn = $prefix . 'reject_album_delete_fn';
            if (!$enabled) {
                Database::query('DROP TRIGGER IF EXISTS ' . $name . ($dialect === 'Pgsql' ? ' ON ' . table($target) : ''));
                if ($dialect === 'Pgsql') { Database::query('DROP FUNCTION IF EXISTS ' . $fn . '()'); }
                return;
            }
            if ($dialect === 'SQLite') {
                $sql = 'CREATE TRIGGER ' . $name . ' BEFORE DELETE ON ' . table($target) . " BEGIN SELECT RAISE(ABORT, 'Integration failure'); END";
            } elseif ($dialect === 'Mysql') {
                $sql = 'CREATE TRIGGER ' . $name . ' BEFORE DELETE ON ' . table($target) . " FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Integration failure'";
            } else {
                Database::query('CREATE FUNCTION ' . $fn . "() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN RAISE EXCEPTION 'Integration failure'; RETURN OLD; END $$");
                $sql = 'CREATE TRIGGER ' . $name . ' BEFORE DELETE ON ' . table($target) . ' FOR EACH ROW EXECUTE PROCEDURE ' . $fn . '()';
            }
            Database::query($sql);
        };
        foreach (['fields', 'contents'] as $faultTable) {
            $deleteTrigger(true, $faultTable);
            try {
                injectedFailure(static function () use ($albumCid) { ImageRepository::deleteAlbum($albumCid); }, '整图集删除遇到 ' . $faultTable . ' 故障必须失败');
            } finally { $deleteTrigger(false, $faultTable); }
            check(ImageRepository::rowsFor($albumCid) === $albumImages, $faultTable . ' 删除失败后图片行完整回滚');
            check(fields($albumCid) === $albumFields && $contentFor($albumCid) === $albumContent, $faultTable . ' 删除失败后文章和全部字段完整回滚');
            check($allFilesExist($privateAlbumPaths) && $allFilesExist($sharedAlbumPaths), $faultTable . ' 删除失败后全部实体文件保留');
        }
        Database::query('BEGIN');
        try {
            AdminRepository::setField($cid, 'device', '整图集删除外层写入');
            check(ImageRepository::deleteAlbum($albumCid), '外层事务内整图集删除成功');
            check(ImageRepository::rowsFor($albumCid) === [] && $contentFor($albumCid) === [], '外层事务内可见图集已删除');
            check(AdminRepository::field($cid, 'device', true) === '整图集删除外层写入', '整图集删除保留调用方其他修改');
            check(AdminRepository::field($cid, 'device') === 'before transaction', '整图集删除不得提交调用方事务');
            check($allFilesExist($privateAlbumPaths) && $allFilesExist($sharedAlbumPaths), '外层事务内整图集删除不清理文件');
        } finally { Database::query('ROLLBACK'); }
        check(ImageRepository::rowsFor($albumCid) === $albumImages && fields($albumCid) === $albumFields && $contentFor($albumCid) === $albumContent, '外层回滚恢复整图集三张表的全部记录');
        check($allFilesExist($privateAlbumPaths) && $allFilesExist($sharedAlbumPaths), '整图集外层回滚后全部文件仍存在');
        if ($dialect === 'Mysql') {
            Database::query('ALTER TABLE ' . table('contents') . ' ENGINE=MyISAM');
            try {
                rejected(static function () use ($albumCid) { ImageRepository::deleteAlbum($albumCid); }, \RuntimeException::class, 'contents 非 InnoDB 必须拒绝整图集删除');
                check(ImageRepository::rowsFor($albumCid) === $albumImages && fields($albumCid) === $albumFields && $contentFor($albumCid) === $albumContent, '拒绝非事务文章表时三表数据均不变');
                check($allFilesExist($privateAlbumPaths), '拒绝非事务文章表时不删除文件');
            } finally { Database::query('ALTER TABLE ' . table('contents') . ' ENGINE=InnoDB'); }
        }
        check(ImageRepository::deleteAlbum($albumCid), '独立整图集删除事务成功提交');
        check(ImageRepository::rowsFor($albumCid) === [] && fields($albumCid) === [] && $contentFor($albumCid) === [], '整图集提交后移除三表记录');
        check($allFilesGone($privateAlbumPaths), '整图集提交后清理无共享引用的全部变体');
        check($allFilesExist($sharedAlbumPaths) && $contentFor($sharedCid) !== [], '整图集删除保留其他图集的共享文件与记录');
        check(!ImageRepository::deleteAlbum($albumCid) && !ImageRepository::deleteAlbum(0), '重复或无效整图集删除不产生额外操作');
        check(ImageRepository::deleteAlbum($sharedCid) && $allFilesGone($sharedAlbumPaths), '最后一个共享图集删除后清理全部共用变体');

        Database::query($db->delete(ImageRepository::table())->where('cid = ?', $cid));
        ImageRepository::syncPostFields($cid, true);
        check(AdminRepository::field($cid, 'img') === '' && AdminRepository::field($cid, 'photo_ids') === '', '显式清空删除最后一张图的元数据');
        check(AdminRepository::album($cid, 7, false) !== null, '清空后的图集保留识别标记');

        // 重建改变公开资源版本而不改数据库路径；重复同秒重建也产生新版本。
        $versionCid = post([]);
        $versionPhoto = photo($versionCid);
        $rawBefore = AdminRepository::readRow($db->select()->from(ImageRepository::table())->where('id = ?', $versionPhoto), true);
        ImageRepository::updateVariants($versionPhoto, ['width' => 1200, 'height' => 600]);
        ImageRepository::syncPostFields($versionCid);
        $rawAfter = AdminRepository::readRow($db->select()->from(ImageRepository::table())->where('id = ?', $versionPhoto), true);
        $versionExif = json_decode($rawAfter['exif'], true);
        check($rawAfter['full'] === $rawBefore['full'] && $rawAfter['thumb'] === $rawBefore['thumb'], '重建版本不改变物理路径');
        check($versionExif['make'] === 'Camera' && isset($versionExif['gps']), '重建版本保留私有原始EXIF');
        $firstUrl = AdminRepository::field($versionCid, 'img', true);
        check(strpos($firstUrl, '?itv=' . $versionExif['_infinity_media_version']) !== false, '公开聚合图片URL带重建版本');
        $publicExif = json_decode(AdminRepository::field($versionCid, 'exif', true), true);
        check(!isset($publicExif[0]['gps']) && !isset($publicExif[0]['_infinity_media_version']), '公开EXIF不包含GPS或内部版本键');
        ImageRepository::updateVariants($versionPhoto, ['width' => 1200, 'height' => 600]);
        ImageRepository::syncPostFields($versionCid);
        check(AdminRepository::field($versionCid, 'img', true) !== $firstUrl, '同秒再次重建也更新缓存标识');
        require_once __DIR__ . '/../usr/plugins/InfinityTime/Lib/HealthDiagnostics.php';
        $healthRowsBefore = fields($versionCid);
        $health = \TypechoPlugin\InfinityTime\Lib\HealthDiagnostics::collect($db, $tmp, []);
        $health = array_column($health, null, 'name');
        check($health['数据库事务']['status'] === ($dialect === 'Mysql' ? 'ok' : 'manual'), '真实适配器健康检查不虚报表引擎');
        check(fields($versionCid) === $healthRowsBefore, '健康检查不改动已存字段');
        // 真实旧表迁移：缺少元数据及重试列时，补齐列并保留已有记录。
        Database::query('DROP TABLE ' . table('infinitytime_images'), Db::WRITE);
        $pk = $dialect === 'Mysql' ? 'id INTEGER NOT NULL AUTO_INCREMENT PRIMARY KEY'
            : ($dialect === 'Pgsql' ? 'id SERIAL PRIMARY KEY' : 'id INTEGER PRIMARY KEY AUTOINCREMENT');
        Database::query('CREATE TABLE ' . table('infinitytime_images') . ' (' . $pk . ', cid INTEGER, ' . ($dialect === 'Mysql' ? '`full`' : '"full"') . ' TEXT)', Db::WRITE);
        Database::query($db->insert(ImageRepository::table())->rows(['cid' => 1, 'full' => '/preserved.webp']));
        Plugin::setOption('infinitytimeSchemaVersion', '2');
        Plugin::migrateSchema();
        $preserved = $db->fetchRow($db->select()->from(ImageRepository::table()));
        check($preserved['full'] === '/preserved.webp' && $preserved['upload_key'] === null, '迁移保留已有图片，重试标识默认为 NULL');
        check(array_key_exists('mid_avif', $preserved) && array_key_exists('desc', $preserved), '迁移修复所有历史缺失列');
        Plugin::setOption('infinitytimeSchemaVersion', '3');
        Plugin::migrateSchema();
        check((int)Plugin::opt('infinitytimeSchemaVersion') === 4, '中断后重试正确验证已有唯一索引');
        check(rowCount('infinitytime_images') === 1, '重复迁移不得增加图片');
        // 同名但非唯一的索引不能被误认为成功升级。
        $index = 'it_upload_' . substr(sha1(ImageRepository::table()), 0, 16);
        $quote = $dialect === 'Mysql' ? '`' : '"';
        $dropIndex = 'DROP INDEX ' . $quote . $index . $quote . ($dialect === 'Mysql' ? ' ON ' . table('infinitytime_images') : '');
        Database::query($dropIndex);
        Database::query('CREATE INDEX ' . $quote . $index . $quote . ' ON ' . table('infinitytime_images') . ' (cid, upload_key)');
        Plugin::setOption('infinitytimeSchemaVersion', '3');
        rejected(static function () { Plugin::migrateSchema(); }, \RuntimeException::class, '错误的非唯一索引必须阻止升级');
        check((int)Plugin::opt('infinitytimeSchemaVersion') === 3, '索引验证失败不能推进版本');
        check(rowCount('infinitytime_images') === 1, '索引验证失败不能破坏图片');
        Database::query($dropIndex);
        Plugin::migrateSchema();
        check((int)Plugin::opt('infinitytimeSchemaVersion') === 4, '管理员移除错误索引后可以重试升级');
        echo '通过：' . $checks . ' 项真实 Typecho ' . \Typecho\Common::VERSION . ' / PHP ' . PHP_VERSION . ' / ' . $adapter . " 数据库集成检查\n";
    } catch (\Throwable $e) {
        $failed = $e;
    } finally {
        ImageRepository::unlockMedia();
        // 即使断言失败也只清理本次随机前缀的临时数据库对象。
        foreach (['infinitytime_images', 'fields', 'contents', 'comments', 'metas', 'options', 'relationships', 'users'] as $name) {
            try { Database::query('DROP TABLE IF EXISTS ' . table($name) . ($dialect === 'Pgsql' ? ' CASCADE' : ''), Db::WRITE); }
            catch (\Throwable $cleanup) { fwrite(STDERR, '清理提示：' . $cleanup->getMessage() . "\n"); }
        }
        if ($dialect === 'Pgsql') {
            foreach (['comments', 'contents', 'metas', 'users'] as $name) {
                try { Database::query('DROP SEQUENCE IF EXISTS ' . table($name . '_seq'), Db::WRITE); } catch (\Throwable $cleanup) {}
            }
            try { Database::query('DROP FUNCTION IF EXISTS ' . $prefix . 'reject_field_fn()', Db::WRITE); } catch (\Throwable $cleanup) {}
        }
        $db->flushPool();
        foreach (glob($tmp . '/database.sqlite*') ?: [] as $file) { @unlink($file); }
        foreach ($fixtureFiles as $file) { @unlink($file); }
        foreach (['original', 'full', 'thumb'] as $directory) { @rmdir($tmp . '/uploads/' . $directory); }
        @unlink($tmp . '/uploads/.infinitytime-media.lock');
        @rmdir($tmp . '/uploads');
        @rmdir($tmp);
    }
    if ($failed) {
        fwrite(STDERR, '失败：' . get_class($failed) . ': ' . $failed->getMessage() . "\n" . $failed->getTraceAsString() . "\n");
        exit(1);
    }
}
