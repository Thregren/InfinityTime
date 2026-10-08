<?php
/** 真实 SQLite 字段原子性；Typecho Query 的读写路由在替身中保持一致。 */
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
        public static function log($message): void {}
    }
}
namespace {
    use TypechoPlugin\InfinityTime\Lib\ImageRepository as Repository;
    require_once __DIR__ . '/../usr/plugins/InfinityTime/Lib/Database.php';
    require __DIR__ . '/../usr/plugins/InfinityTime/Lib/ImageRepository.php';
    $root = sys_get_temp_dir() . '/infinitytime-sync-' . bin2hex(random_bytes(8));
    mkdir($root, 0700);
    define('__TYPECHO_ROOT_DIR__', $root);
    define('__TYPECHO_UPLOAD_ROOT_DIR__', $root);
    $db = \Typecho\Db::$instance = new \Typecho\Db();
    $pdo = $db->pdo;
    $checks = 0;
    function expect(bool $ok, string $message): void {
        global $checks;
        if (!$ok) { throw new \RuntimeException($message); }
        $checks++;
    }
    function fields(): array {
        global $pdo;
        return $pdo->query('SELECT * FROM sync_fields ORDER BY cid,name')->fetchAll(\PDO::FETCH_ASSOC);
    }
    function failSync(): void {
        $thrown = false;
        try { Repository::syncPostFields(1); }
        catch (\Throwable $e) { $thrown = strpos($e->getMessage(), 'injected failure') !== false; }
        expect($thrown, '同步应传播中途写入错误');
    }
    function failTrigger(bool $on, string $field = 'dims'): void {
        global $pdo;
        if ($on) {
            $pdo->exec("CREATE TRIGGER fail_sync BEFORE INSERT ON sync_fields WHEN NEW.name = '$field' BEGIN SELECT RAISE(ABORT, 'injected failure'); END");
        } else { $pdo->exec('DROP TRIGGER fail_sync'); }
    }
    try {
        $resource = $pdo->query('SELECT 1');
        expect(\TypechoPlugin\InfinityTime\Lib\Database::query($resource) === $resource, '已执行的 PDOStatement 句柄原样返回，不误调用 getAttribute(action)');
        $resource->closeCursor();
        $pdo->exec('CREATE TABLE sync_fields (cid INTEGER, name TEXT, type TEXT, str_value TEXT, int_value INTEGER DEFAULT 0, float_value REAL DEFAULT 0, PRIMARY KEY(cid,name))');
        $pdo->exec('CREATE TABLE sync_infinitytime_images (id INTEGER PRIMARY KEY, cid INTEGER, sort INTEGER, full TEXT, thumb TEXT, width INTEGER, height INTEGER, exif TEXT, created INTEGER)');
        $pdo->exec("INSERT INTO sync_infinitytime_images VALUES (1,1,0,'/new.webp','/new-thumb.webp',200,100,'{}',1704067200),(2,2,0,'/second.webp','/second-thumb.webp',100,100,'{}',1704067200)");
        foreach ([1,2] as $cid) {
            foreach (['img','thumb','addresses','titles','descs','panos','dims','variants','exif','photo_ids','months','unrelated'] as $name) {
                $q = $pdo->prepare('INSERT INTO sync_fields VALUES (?,?,?,?,?,?)');
                $q->execute([$cid,$name,'str','old-' . $name,11,1.25]);
            }
        }
        $original = fields();
        failTrigger(true);
        failSync();
        expect(fields() === $original, '独立事务中途失败必须完整保留所有旧字段及类型列');
        failTrigger(false);
        $pdo->exec('BEGIN');
        $pdo->exec("UPDATE sync_fields SET str_value = 'caller-before' WHERE cid=1 AND name='unrelated'");
        $outer = fields();
        failTrigger(true);
        failSync();
        expect(fields() === $outer, '嵌套失败不得回滚调用方此前的修改');
        failTrigger(false);
        $pdo->exec("UPDATE sync_fields SET str_value = 'caller-after' WHERE cid=2 AND name='unrelated'");
        $pdo->exec('COMMIT');
        expect($pdo->query("SELECT str_value FROM sync_fields WHERE cid=2 AND name='unrelated'")->fetchColumn() === 'caller-after', '失败后外层事务应可继续并提交');

        $beforeSuccess = fields();
        $pdo->exec('BEGIN');
        Repository::syncPostFields(1);
        expect($pdo->query("SELECT str_value FROM sync_fields WHERE cid=1 AND name='img'")->fetchColumn() === '/new.webp', '同步使用主库图片行');
        $pdo->exec('ROLLBACK');
        expect(fields() === $beforeSuccess, '成功同步不得提前提交外层 BEGIN');

        $pdo->exec('SAVEPOINT caller');
        Repository::syncPostFields(1);
        Repository::syncPostFields(2);
        $pdo->exec('ROLLBACK TO SAVEPOINT caller');
        $pdo->exec('RELEASE SAVEPOINT caller');
        expect(fields() === $beforeSuccess, '连续同步不能越过调用方 SAVEPOINT 边界');

        $db->beforeWrite = static function (): void {
            Repository::syncPostFields(2);
            throw new \RuntimeException('injected failure');
        };
        failSync();
        expect(fields() === $beforeSuccess, '重入同步独立命名保存点，外层失败回滚内部成功同步');

        Repository::syncPostFields(1);
        $afterSuccess = fields();
        expect($afterSuccess !== $beforeSuccess, '独立同步成功应持久化');
        expect($pdo->query("SELECT str_value FROM sync_fields WHERE cid=1 AND name='dims'")->fetchColumn() === '["200x100"]', '字段内容与图片顺序一致');
        expect($pdo->query("SELECT str_value FROM sync_fields WHERE cid=1 AND name='unrelated'")->fetchColumn() === 'caller-before', '同步不改无关字段');
        $pdo->exec('ALTER TABLE sync_infinitytime_images ADD COLUMN title TEXT');
        $badTitle = $pdo->prepare('UPDATE sync_infinitytime_images SET title=? WHERE cid=1');
        $badTitle->execute(["bad-\xff"]);
        $badJson = false;
        try { Repository::syncPostFields(1); } catch (\JsonException $e) { $badJson = true; }
        expect($badJson && fields() === $afterSuccess, 'JSON编码错误也须完整回滚，不能把false写入字段');
        $pdo->exec('DELETE FROM sync_infinitytime_images WHERE cid=1');
        Repository::syncPostFields(1);
        expect(fields() === $afterSuccess, '历史字段无图片行时保持原样');
        failTrigger(true, 'thumb');
        $clearFailed = false;
        try { Repository::syncPostFields(1, true); } catch (\Throwable $e) { $clearFailed = true; }
        expect($clearFailed && fields() === $afterSuccess, '清空字段中途失败也须保留全部旧字段');
        failTrigger(false);
        Repository::syncPostFields(1, true);
        $empty = $pdo->query("SELECT name,str_value FROM sync_fields WHERE cid=1 AND name <> 'unrelated' ORDER BY name")->fetchAll(\PDO::FETCH_ASSOC);
        expect($empty === [['name'=>'img','str_value'=>''],['name'=>'thumb','str_value'=>'']], '显式清空保留空图集标记并清除旧元数据');
        expect(\TypechoPlugin\InfinityTime\Plugin::$migrations === 0, '同步不得触发 schema DDL 或隐式提交');
        foreach ($db->operations as $operation) {
            expect($operation['op'] === \Typecho\Db::WRITE && !$operation['object'], '同步的查询和事务语句全部在主库执行');
        }
        $db->adapter = 'unknown';
        $beforeUnsupported = fields();
        $rejected = false;
        try { Repository::syncPostFields(2); } catch (\RuntimeException $e) { $rejected = strpos($e->getMessage(), '不支持') !== false; }
        expect($rejected && fields() === $beforeUnsupported, '未知适配器安全拒绝且不修改字段');
        echo "字段同步原子性、嵌套事务与主库路由检查通过（$checks 项）\n";
    } finally {
        Repository::unlockMedia();
        @unlink($root . '/.infinitytime-media.lock');
        @rmdir($root);
    }
}
