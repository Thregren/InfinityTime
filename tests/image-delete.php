<?php
/** 图片删除：真实 SQLite 故障注入、外层事务与隔离临时媒体文件。 */
declare(strict_types=1);
namespace Typecho {
    class Query {
        private string $kind = 'select';
        private string $table = '';
        private array $fields = [];
        private array $rows = [];
        private array $where = [];
        private array $params = [];
        private string $order = '';
        private string $limit = '';
        public function __construct(string $kind = 'select', string $table = '', array $fields = []) { $this->kind = $kind; $this->table = $table; $this->fields = $fields ?: ['*']; }
        public function from($table): self { $this->table = $table; return $this; }
        public function rows($rows): self { $this->rows = $rows; return $this; }
        public function where($clause, ...$values): self {
            foreach ($values as $value) { $clause = substr_replace($clause, $this->quoteValue($value), strpos($clause, '?'), 1); }
            $this->where[] = '(' . $clause . ')'; return $this;
        }
        public function limit($limit): self { $this->limit = ' LIMIT ' . (int)$limit; return $this; }
        public function order($column, $direction): self { $this->order .= ($this->order ? ',' : ' ORDER BY ') . '"' . $column . '" ' . $direction; return $this; }
        public function quoteValue($value): string { $this->params[] = $value; return '#param:' . (count($this->params) - 1) . '#'; }
        public function prepare(string $sql): string {
            return preg_replace_callback('/#param:(\d+)#/', function ($match) { $value = $this->params[(int)$match[1]]; return $value === null ? 'NULL' : Db::get()->pdo->quote((string)$value); }, $sql);
        }
        public function __toString(): string {
            $table = '"' . $this->table . '"';
            if ($this->kind === 'insert') {
                $names = implode(',', array_map(static function ($name) { return '"' . $name . '"'; }, array_keys($this->rows)));
                $values = implode(',', array_map(function ($value) { return $this->quoteValue($value); }, array_values($this->rows)));
                $sql = "INSERT INTO $table ($names) VALUES ($values)";
            } elseif ($this->kind === 'update') {
                $values = [];
                foreach ($this->rows as $name => $value) { $values[] = '"' . $name . '" = ' . $this->quoteValue($value); }
                $sql = "UPDATE $table SET " . implode(',', $values);
            } elseif ($this->kind === 'delete') { $sql = "DELETE FROM $table"; }
            else { $sql = 'SELECT ' . implode(',', $this->fields) . " FROM $table"; }
            return $this->prepare($sql . ($this->where ? ' WHERE ' . implode(' AND ', $this->where) : '') . $this->order . $this->limit);
        }
        public function isInsert(): bool { return $this->kind === 'insert'; }
    }
    class Db {
        public const SORT_ASC = 'ASC', WRITE = 2, READ = 1;
        public static self $instance;
        public \PDO $pdo;
        public \PDO $replica;
        public array $operations = [];
        public string $adapter = 'Pdo_SQLite';
        public $beforeWrite = null;
        public $beforeSql = null;
        public function __construct() {
            $this->pdo = new \PDO('sqlite::memory:', null, null, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
            $this->replica = new \PDO('sqlite::memory:', null, null, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
        }
        public static function get(): self { return self::$instance; }
        public function getPrefix(): string { return 'sync_'; }
        public function getAdapterName(): string { return $this->adapter; }
        public function selectDb($op) { return $op === self::WRITE ? $this->pdo : $this->replica; }
        public function select(...$fields): Query { return new Query('select', '', $fields); }
        public function insert($table): Query { return new Query('insert', $table); }
        public function delete($table): Query { return new Query('delete', $table); }
        public function fetchRow($query) { return $this->query($query)->fetch(\PDO::FETCH_ASSOC); }
        public function fetchAll($query): array { return $this->query($query)->fetchAll(\PDO::FETCH_ASSOC); }
        public function query($query, $op = self::READ) {
            if ($query instanceof \PDOStatement) { return $query; }
            if ($query instanceof Query) {
                // 真实 Db::query 覆盖传入的 WRITE；未编译的 SELECT 应故意去空副本失败。
                $op = preg_match('/^SELECT /', (string)$query) ? self::READ : self::WRITE;
            }
            $sql = (string)$query;
            if ($this->beforeSql) { ($this->beforeSql)($sql); }
            $this->operations[] = ['sql' => $sql, 'op' => $op, 'object' => is_object($query)];
            if ($this->beforeWrite && preg_match('/^(INSERT|DELETE)/', $sql)) {
                $callback = $this->beforeWrite;
                $this->beforeWrite = null;
                $callback();
            }
            return $this->selectDb($op)->query($sql);
        }
    }
}
namespace TypechoPlugin\InfinityTime {
    class Plugin {
        public static int $migrations = 0;
        public static function migrateSchema(): void { self::$migrations++; throw new \RuntimeException('事务内禁止 DDL'); }
        public static array $logs = [];
        public static function log($message): void { self::$logs[] = $message; }
    }
}
namespace {
    use TypechoPlugin\InfinityTime\Lib\ImageRepository as Repository;
    require_once __DIR__ . '/../usr/plugins/InfinityTime/Lib/Database.php';
    require __DIR__ . '/../usr/plugins/InfinityTime/Lib/ImageRepository.php';
    $root = sys_get_temp_dir() . '/infinitytime-delete-' . bin2hex(random_bytes(8));
    mkdir($root, 0700);
    define('__TYPECHO_ROOT_DIR__', $root);
    define('__TYPECHO_UPLOAD_ROOT_DIR__', $root . '/uploads');
    define('__TYPECHO_UPLOAD_DIR__', '/uploads');
    foreach (['original', 'full', 'thumb'] as $dir) { mkdir($root . '/uploads/' . $dir, 0700, true); }
    $db = \Typecho\Db::$instance = new \Typecho\Db();
    $pdo = $db->pdo;
    $checks = 0;
    function expect(bool $ok, string $message): void {
        global $checks;
        if (!$ok) { throw new \RuntimeException($message); }
        $checks++;
    }
    function snapshot(): array {
        global $pdo;
        return [
            $pdo->query('SELECT * FROM sync_infinitytime_images ORDER BY id')->fetchAll(\PDO::FETCH_ASSOC),
            $pdo->query('SELECT * FROM sync_fields ORDER BY cid,name')->fetchAll(\PDO::FETCH_ASSOC),
            $pdo->query('SELECT * FROM sync_contents ORDER BY cid')->fetchAll(\PDO::FETCH_ASSOC),
        ];
    }
    function addImage(int $id, int $cid): array {
        global $pdo, $root;
        $paths = ['/uploads/original/' . $id . '.jpg', '/uploads/full/' . $id . '.webp', '/uploads/thumb/' . $id . '.webp'];
        foreach ($paths as $path) { file_put_contents($root . $path, 'fixture-' . $id); }
        $query = $pdo->prepare('INSERT INTO sync_infinitytime_images (id,cid,sort,original,full,thumb,width,height,exif,created) VALUES (?,?,0,?,?,?,200,100,?,1704067200)');
        $query->execute([$id,$cid,$paths[0],$paths[1],$paths[2],json_encode(['camera'=>'fixture', 'gps'=>['lat'=>10,'lng'=>20]])]);
        Repository::syncPostFields($cid);
        return $paths;
    }
    function filesExist(array $paths): bool {
        global $root;
        foreach ($paths as $path) { if (!is_file($root . $path)) { return false; } }
        return true;
    }
    function failDelete(int $id, int $cid, string $message): void {
        $thrown = false;
        try { Repository::deleteImage($id, $cid); } catch (\Throwable $e) { $thrown = true; }
        expect($thrown, $message);
    }
    try {
        $pdo->exec('PRAGMA foreign_keys = ON');
        $pdo->exec('CREATE TABLE sync_contents (cid INTEGER PRIMARY KEY, title TEXT)');
        $pdo->exec('CREATE TABLE sync_fields (cid INTEGER, name TEXT, type TEXT, str_value TEXT, int_value INTEGER DEFAULT 0, float_value REAL DEFAULT 0, PRIMARY KEY(cid,name))');
        $pdo->exec('CREATE TABLE sync_infinitytime_images (id INTEGER PRIMARY KEY, cid INTEGER, sort INTEGER, original TEXT, full TEXT, thumb TEXT, mid TEXT, avif TEXT, mid_avif TEXT, width INTEGER, height INTEGER, exif TEXT, created INTEGER)');
        $paths = addImage(1, 1);
        $variants = ['/uploads/full/1@1600.webp', '/uploads/full/1.avif', '/uploads/full/1@1600.avif'];
        foreach ($variants as $path) { file_put_contents($root . $path, 'variant'); $paths[] = $path; }
        $q = $pdo->prepare('UPDATE sync_infinitytime_images SET mid=?,avif=?,mid_avif=? WHERE id=1');
        $q->execute($variants);
        Repository::syncPostFields(1);
        $original = snapshot();
        $pdo->exec("CREATE TRIGGER fail_delete BEFORE DELETE ON sync_infinitytime_images BEGIN SELECT RAISE(ABORT, 'injected row failure'); END");
        failDelete(1, 1, '图片行删除失败必须传播');
        expect(snapshot() === $original && filesExist($paths), '行删除失败保持数据库和文件');
        $pdo->exec('DROP TRIGGER fail_delete');
        $pdo->exec("CREATE TRIGGER fail_sync BEFORE INSERT ON sync_fields WHEN NEW.name = 'thumb' BEGIN SELECT RAISE(ABORT, 'injected field failure'); END");
        failDelete(1, 1, '删除最后一图时字段失败必须传播');
        expect(snapshot() === $original && filesExist($paths), '字段写入失败完整回滚行和字段，文件不变');
        $pdo->exec('BEGIN');
        $pdo->exec("INSERT INTO sync_fields (cid,name,type,str_value) VALUES (99,'unrelated','str','caller-before')");
        $outer = snapshot();
        failDelete(1, 1, '外层事务中同样传播字段错误');
        expect(snapshot() === $outer && filesExist($paths), '失败不回滚调用方其他写入');
        $pdo->exec('DROP TRIGGER fail_sync');
        $pdo->exec('COMMIT');
        $before = snapshot();
        $pdo->exec('BEGIN');
        expect(Repository::deleteImage(1, 1), '外层事务内删除成功');
        expect(filesExist($paths), '未提交外层事务时绝不能删除文件');
        expect($pdo->query('SELECT COUNT(*) FROM sync_infinitytime_images WHERE id=1')->fetchColumn() == 0, '事务内图片行已删除');
        $pdo->exec('ROLLBACK');
        expect(snapshot() === $before && filesExist($paths), '调用方回滚后行字段文件均完好');
        $pdo->exec('SAVEPOINT caller');
        expect(Repository::deleteImage(1, 1), '外层保存点内删除成功');
        $pdo->exec('ROLLBACK TO SAVEPOINT caller');
        $pdo->exec('RELEASE SAVEPOINT caller');
        expect(snapshot() === $before && filesExist($paths), '调用方保存点不能被提前提交');

        $pdo->exec('CREATE TABLE parents (id INTEGER PRIMARY KEY)');
        $pdo->exec('CREATE TABLE deferred_failure (id INTEGER REFERENCES parents(id) DEFERRABLE INITIALLY DEFERRED)');
        $pdo->exec('CREATE TRIGGER fail_commit AFTER DELETE ON sync_infinitytime_images BEGIN INSERT INTO deferred_failure VALUES (99); END');
        failDelete(1, 1, '真实 COMMIT 约束错误必须传播');
        expect(snapshot() === $before && filesExist($paths), '提交失败回滚且不删除文件');
        $pdo->exec('DROP TRIGGER fail_commit');
        expect(!Repository::deleteImage(1, 2) && !Repository::deleteImage(999, 1), '不匹配 cid 或不存在的 id 不修改任何数据');
        expect(snapshot() === $before && filesExist($paths), '错误目标无副作用');
        expect(Repository::deleteImage(1, 1), '独立删除成功');
        foreach ($paths as $path) { expect(!is_file($root . $path), '提交后清理原图及公开变体'); }
        $empty = $pdo->query('SELECT name,str_value FROM sync_fields WHERE cid=1 ORDER BY name')->fetchAll(\PDO::FETCH_ASSOC);
        expect($empty === [['name'=>'img','str_value'=>''],['name'=>'thumb','str_value'=>'']], '最后一图清空派生元数据并保留空图集标记');

        $paths2 = addImage(2, 2);
        addImage(3, 3);
        $q = $pdo->prepare('UPDATE sync_infinitytime_images SET original=?,full=?,thumb=? WHERE id=3');
        $q->execute($paths2);
        Repository::syncPostFields(3);
        expect(Repository::deleteImage(2, 2) && filesExist($paths2), '其他仓库行共享路径时保留全部文件');
        $q = $pdo->prepare('INSERT INTO sync_fields (cid,name,type,str_value) VALUES (?,?,?,?)');
        $q->execute([9,'img','str','https://legacy.example' . $paths2[0] . "\n" . $paths2[1] . "?cache=1#preview"]);
        $q->execute([9,'variants','str',json_encode([['webp'=>[str_replace('/2.webp', '/%32.webp', $paths2[2])],'avif'=>[]]])]);
        expect(Repository::deleteImage(3, 3) && filesExist($paths2), '历史字段及 variants 引用保护共享实体');

        foreach ([
            42 => '/uploads/full/temp/../42.webp',
            43 => '/uploads/full/temp/%2e%2e/43.webp?cache=1#preview',
            44 => '/uploads/full/./44.webp',
            45 => '/uploads/full/%2E/45.webp',
            46 => '//legacy.example/uploads/full/46.webp?cache=1#preview',
            47 => '/uploads/full/temp\\..\\47.webp',
        ] as $id => $legacyPath) {
            $legacyPaths = addImage($id, $id);
            $q = $pdo->prepare('INSERT INTO sync_fields (cid,name,type,str_value) VALUES (?,?,?,?)');
            $q->execute([$id + 100,'img','str',$legacyPath]);
            expect(Repository::deleteImage($id, $id), '历史点目录引用测试删除行成功');
            expect(is_file($root . $legacyPaths[1]), '浏览器规范化后的点目录 URL 必须保护真实图片');
            touch($root . $legacyPaths[1], time() - 7200);
            $legacyCandidates = Repository::orphanCandidates();
            foreach ($legacyCandidates as $candidate) {
                expect($candidate['path'] !== $root . $legacyPaths[1], '旧图片孤儿扫描同样保护点目录引用');
            }
        }

        $paths4 = addImage(4, 4);
        addImage(5, 4);
        expect(Repository::deleteImage(4, 4), '多图删除成功');
        $public = $pdo->query('SELECT str_value FROM sync_fields WHERE cid=4')->fetchAll(\PDO::FETCH_COLUMN);
        $public = implode("\n", $public);
        expect(strpos($public, '/original/') === false && strpos($public, '"gps"') === false, '字段同步不公开原图路径和 GPS');
        expect(strpos($public, '/uploads/full/5.webp') !== false && strpos($public, '/uploads/full/4.webp') === false, '剩余图路径按行重新同步');

        $paths6 = addImage(6, 6);
        $committed = false;
        $db->beforeSql = static function ($sql) use (&$committed): void {
            if ($sql === 'COMMIT') { $committed = true; }
            if ($committed && strpos($sql, 'SELECT original,full,thumb') === 0) { throw new \RuntimeException('injected cleanup read failure'); }
        };
        expect(Repository::deleteImage(6, 6), '提交后引用读取失败不得伪报删除失败');
        $db->beforeSql = null;
        expect(filesExist($paths6) && $pdo->query('SELECT COUNT(*) FROM sync_infinitytime_images WHERE id=6')->fetchColumn() == 0, '清理失败保留孤儿文件且数据库删除已提交');
        expect(count(\TypechoPlugin\InfinityTime\Plugin::$logs) > 0, '延迟清理原因可诊断');

        $paths7 = addImage(7, 7);
        chmod($root . '/uploads/original', 0500);
        $canUnlink = is_writable($root . '/uploads/original');
        expect(Repository::deleteImage(7, 7), 'unlink 失败不影响已提交的成功结果');
        if (!$canUnlink) { expect(is_file($root . $paths7[0]), '真实权限错误留下原图孤儿'); }
        expect(!is_file($root . $paths7[1]), '单个 unlink 失败不妨碍后续文件清理');
        chmod($root . '/uploads/original', 0700);
        expect($pdo->query('SELECT COUNT(*) FROM sync_infinitytime_images WHERE id=7')->fetchColumn() == 0, '清理部分失败不会恢复数据库引用');

        $paths8 = addImage(8, 8);
        file_put_contents($root . '/outside.jpg', 'do not delete');
        symlink($root . '/outside.jpg', $root . '/uploads/original/link.jpg');
        $pdo->exec("UPDATE sync_infinitytime_images SET original='/uploads/original/link.jpg', full='/outside.jpg', thumb='/uploads/thumb/../thumb/8.webp' WHERE id=8");
        expect(Repository::deleteImage(8, 8), '污染路径也可安全移除数据库行');
        expect(is_link($root . '/uploads/original/link.jpg') && is_file($root . '/outside.jpg') && filesExist($paths8), '软链接、白名单外路径和穿越路径全部跳过');

        $paths9 = addImage(9, 9);
        $pdo->exec('BEGIN');
        Repository::deleteImage(9, 9);
        $pdo->exec('COMMIT');
        expect(filesExist($paths9), '调用方提交仍保守保留文件，直到安全孤儿清理');
        foreach ($paths9 as $path) { touch($root . $path, time() - 7200); }
        $candidates = Repository::orphanCandidates();
        expect(Repository::removeOrphanCandidates($candidates) >= 3 && !filesExist($paths9), '已提交外层事务留下的孤儿可由现有安全流程清理');
        expect(filesExist($paths2), '后续孤儿清理仍保护历史引用');
        // 图集删除只改变授权 cid 的原有三表；失败和外层事务边界必须完整保留。
        $pdo->exec("INSERT INTO sync_contents VALUES (100,'target'),(200,'other'),(300,'legacy')");
        $albumPaths = array_merge(addImage(100, 100), addImage(101, 100));
        $otherPaths = addImage(200, 200);
        $albumBefore = snapshot();
        foreach (['sync_infinitytime_images', 'sync_fields', 'sync_contents'] as $table) {
            $pdo->exec("CREATE TRIGGER fail_album BEFORE DELETE ON $table WHEN OLD.cid=100 BEGIN SELECT RAISE(ABORT, 'injected album failure'); END");
            $failed = false;
            try { Repository::deleteAlbum(100); } catch (\Throwable $e) { $failed = true; }
            expect($failed && snapshot() === $albumBefore && filesExist($albumPaths), '任一图集表删除失败均保留三表与全部文件');
            $pdo->exec('DROP TRIGGER fail_album');
        }
        $pdo->exec('BEGIN');
        $pdo->exec("UPDATE sync_contents SET title='caller-before' WHERE cid=200");
        $albumOuter = snapshot();
        $pdo->exec("CREATE TRIGGER fail_album BEFORE DELETE ON sync_contents WHEN OLD.cid=100 BEGIN SELECT RAISE(ABORT, 'injected album failure'); END");
        $failed = false;
        try { Repository::deleteAlbum(100); } catch (\Throwable $e) { $failed = true; }
        expect($failed && snapshot() === $albumOuter && filesExist($albumPaths), '图集失败不回滚外层其他内容修改');
        $pdo->exec('DROP TRIGGER fail_album');
        expect(Repository::deleteAlbum(100) && filesExist($albumPaths), '外层事务内图集删除不清理文件');
        $pdo->exec('ROLLBACK');
        expect(snapshot() === $albumBefore && filesExist($albumPaths), '外层回滚恢复完整图集及文件');
        $pdo->exec('SAVEPOINT album_caller');
        expect(Repository::deleteAlbum(100) && filesExist($albumPaths), '图集删除不跨越外层保存点');
        $pdo->exec('ROLLBACK TO SAVEPOINT album_caller');
        $pdo->exec('RELEASE SAVEPOINT album_caller');
        expect(snapshot() === $albumBefore && filesExist($albumPaths), '图集保存点回滚后完整恢复');
        $pdo->exec('CREATE TRIGGER fail_album_commit AFTER DELETE ON sync_contents WHEN OLD.cid=100 BEGIN INSERT INTO deferred_failure VALUES (99); END');
        $failed = false;
        try { Repository::deleteAlbum(100); } catch (\Throwable $e) { $failed = true; }
        expect($failed && snapshot() === $albumBefore && filesExist($albumPaths), '图集真实提交约束失败不清理文件');
        $pdo->exec('DROP TRIGGER fail_album_commit');
        expect(!Repository::deleteAlbum(0) && !Repository::deleteAlbum(99999), '不存在图集不改任何数据');
        expect(snapshot() === $albumBefore, '错误图集目标无副作用');
        // 外图集仓库行及仅有字段的图集分别引用一张，其他文件正常回收。
        $q = $pdo->prepare('UPDATE sync_infinitytime_images SET original=? WHERE id=200');
        $q->execute([$albumPaths[0]]);
        $q = $pdo->prepare('INSERT INTO sync_fields (cid,name,type,str_value) VALUES (?,?,?,?)');
        $q->execute([300,'img','str','/uploads/full/temp/../101.webp?cache=1']);
        $otherBefore = $pdo->query('SELECT * FROM sync_contents WHERE cid<>100 ORDER BY cid')->fetchAll(\PDO::FETCH_ASSOC);
        $operationStart = count($db->operations);
        expect(Repository::deleteAlbum(100), '独立图集删除成功');
        expect($pdo->query('SELECT COUNT(*) FROM sync_contents WHERE cid=100')->fetchColumn() == 0
            && $pdo->query('SELECT COUNT(*) FROM sync_fields WHERE cid=100')->fetchColumn() == 0
            && $pdo->query('SELECT COUNT(*) FROM sync_infinitytime_images WHERE cid=100')->fetchColumn() == 0, '图集三表完整删除');
        expect($pdo->query('SELECT * FROM sync_contents WHERE cid<>100 ORDER BY cid')->fetchAll(\PDO::FETCH_ASSOC) === $otherBefore, '保留其他图集内容');
        expect(is_file($root . $albumPaths[0]) && is_file($root . $albumPaths[4]), '图集共享原图及历史点目录引用受到保护');
        foreach ([1,2,3,5] as $index) { expect(!is_file($root . $albumPaths[$index]), '其余无引用图集文件在提交后删除'); }
        expect(filesExist($otherPaths), '其他图集文件不受影响');
        $referenceScans = 0;
        foreach (array_slice($db->operations, $operationStart) as $operation) {
            if (strpos($operation['sql'], 'SELECT original,full,thumb,mid,avif,mid_avif FROM "sync_infinitytime_images"') === 0
                && strpos($operation['sql'], ' WHERE ') === false) { $referenceScans++; }
        }
        expect($referenceScans === 1, '整图集仅执行一次全站图片引用扫描');
        expect(!Repository::deleteAlbum(100), '重复删除图集返回不存在');
        expect(Repository::deleteAlbum(300), '仅有历史字段的空仓库图集仍可删除');
        expect(is_file($root . $albumPaths[4]), '没有仓库行的历史图片不越权直接删除');
        $pdo->exec("INSERT INTO sync_contents VALUES (400,'cleanup-failure')");
        $cleanupPaths = addImage(400, 400);
        $committed = false;
        $db->beforeSql = static function ($sql) use (&$committed): void {
            if ($sql === 'COMMIT') { $committed = true; }
            if ($committed && strpos($sql, 'SELECT original,full,thumb') === 0) { throw new \RuntimeException('album cleanup failure'); }
        };
        expect(Repository::deleteAlbum(400), '图集提交后清理失败仍返回成功');
        $db->beforeSql = null;
        expect(filesExist($cleanupPaths) && $pdo->query('SELECT COUNT(*) FROM sync_contents WHERE cid=400')->fetchColumn() == 0, '图集清理失败仅保留孤儿');
        expect(\TypechoPlugin\InfinityTime\Plugin::$migrations === 0, '整个删除流程不运行隐式提交 DDL');
        foreach ($db->operations as $operation) {
            expect($operation['op'] === \Typecho\Db::WRITE && !$operation['object'], '删除、同步和引用检查固定主库');
        }
        echo "图片删除原子性： $checks 项检查通过\n";
    } finally {
        $db->beforeSql = null;
        Repository::unlockMedia();
        @chmod($root . '/uploads/original', 0700);
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($it as $file) { if ($file->isDir() && !$file->isLink()) { @rmdir($file->getPathname()); } else { @unlink($file->getPathname()); } }
        @rmdir($root);
    }
}
