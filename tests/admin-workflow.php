<?php
/** 基于 SQLite 的后台流程回归测试；上传传输与编码器使用替身。 */
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
        public const SORT_ASC = 'ASC', WRITE = 'WRITE', READ = 'READ';
        public static self $instance;
        public \PDO $pdo;
        public array $reads = [];
        public array $operations = [];
        public string $adapter = 'Pdo_SQLite';
        public function __construct() { $this->pdo = new \PDO('sqlite::memory:', null, null, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]); }
        public static function get(): self { return self::$instance; }
        public function getPrefix(): string { return 'admin_'; }
        public function getAdapterName(): string { return $this->adapter; }
        public function select(...$fields): Query { return new Query('select', '', $fields); }
        public function insert($table): Query { return new Query('insert', $table); }
        public function update($table): Query { return new Query('update', $table); }
        public function delete($table): Query { return new Query('delete', $table); }
        public function fetchRow($query) { if ($query instanceof \PDOStatement) { return $query->fetch(\PDO::FETCH_ASSOC); } $this->reads[] = (string)$query; return $this->pdo->query((string)$query)->fetch(\PDO::FETCH_ASSOC); }
        public function fetchAll($query): array { if ($query instanceof \PDOStatement) { return $query->fetchAll(\PDO::FETCH_ASSOC); } $this->reads[] = (string)$query; return $this->pdo->query((string)$query)->fetchAll(\PDO::FETCH_ASSOC); }
        public function query($query, $op = self::READ) {
            $this->operations[] = ['sql' => (string)$query, 'op' => $op, 'object' => is_object($query)];
            if (preg_match('/^SELECT /i', (string)$query)) { $this->reads[] = (string)$query; return $this->pdo->query((string)$query); }
            $affected = $this->pdo->exec((string)$query);
            return $query instanceof Query && $query->isInsert() ? (int)$this->pdo->lastInsertId() : $affected;
        }
    }
}
namespace TypechoPlugin\InfinityTime {
    class Plugin {
        public const DEFAULT_QUALITY = 76, DEFAULT_THUMB_MAX = 1280, DEFAULT_MAX_WIDTH = 2560,
            DEFAULT_FULL_QUALITY = 82, DEFAULT_PANO_WIDTH = 0, DEFAULT_PANO_QUALITY = 92, DEFAULT_KEEP_ORIGINAL = '1';
        public static function opt($name, $default) { return $default; }
    }
}
namespace TypechoPlugin\InfinityTime\Lib {
    function is_uploaded_file($path): bool { return is_file($path); }
    class ImageRepository {
        public static bool $locked = false;
        public static bool $busy = false;
        public static bool $failSync = false;
        public static int $ingested = 0;
        public static int $synced = 0;
        public static ?string $lastError = null;
        public static array $lastOptions = [];
        public static function lockMedia(): void { if (self::$busy) { throw new \RuntimeException('busy'); } self::$locked = true; }
        public static function ensureSchema(): void {}
        public static function table(): string { return 'admin_infinitytime_images'; }
        public static function ingest($file, $options): ?array {
            if (!self::$locked) { throw new \RuntimeException('入库前处理未持锁'); }
            self::$ingested++; self::$lastOptions = $options;
            if ($file['name'] === 'bad.heic') { self::$lastError = 'decoder failed'; return null; }
            return ['full' => '/full/' . self::$ingested . '.webp', 'exif' => ['make' => 'Camera', 'model' => 'Test']];
        }
        public static function insertRow($cid, $meta, $sort): int {
            if (!self::$locked) { throw new \RuntimeException('图片写入未持锁'); }
            return \Typecho\Db::get()->query(\Typecho\Db::get()->insert(self::table())->rows([
                'cid' => $cid, 'sort' => $sort, 'full' => $meta['full'], 'exif' => json_encode($meta['exif']),
                'upload_key' => $meta['upload_key'], 'upload_fingerprint' => $meta['upload_fingerprint'],
            ]));
        }
        public static function syncPostFields($cid): void {
            if (!self::$locked) { throw new \RuntimeException('字段同步未持锁'); }
            self::$synced++;
            if (self::$failSync) { throw new \RuntimeException('sync interrupted'); }
        }
    }
}
namespace {
    use TypechoPlugin\InfinityTime\Lib\AdminSecurity as Security;
    use TypechoPlugin\InfinityTime\Lib\AdminRepository as Repository;
    use TypechoPlugin\InfinityTime\Lib\AdminWorkflow as Workflow;
    use TypechoPlugin\InfinityTime\Lib\ImageRepository as Images;
    require __DIR__ . '/../usr/plugins/InfinityTime/Lib/AdminSecurity.php';
    require __DIR__ . '/../usr/plugins/InfinityTime/Lib/AdminRepository.php';
    require __DIR__ . '/../usr/plugins/InfinityTime/Lib/AdminWorkflow.php';
    $checks = 0;
    function check(bool $condition, string $label): void { global $checks; if (!$condition) { throw new RuntimeException($label); } $checks++; }
    function rejects(callable $fn, string $label): void { $rejected = false; try { $fn(); } catch (Throwable $e) { $rejected = true; } check($rejected, $label); }
    check(Security::validMutation('POST', 'secret', 'secret'), '接受有效 POST 令牌');
    foreach ([['GET', 'secret', 'secret'], ['POST', '', ''], ['POST', 'secret', null], ['POST', 'secret', ['secret']], ['POST', 'secret', 'wrong']] as $case) {
        check(!Security::validMutation(...$case), '拒绝 GET、不可用、缺失、数组或错误令牌');
    }
    check(Security::validKey(str_repeat('a', 32)) && !Security::validKey('../bad') && !Security::validKey(str_repeat('a', 65)), '上传标识格式及长度有界');
    $db = \Typecho\Db::$instance = new \Typecho\Db();
    $db->query('CREATE TABLE admin_contents (cid INTEGER PRIMARY KEY, title TEXT, slug TEXT, created INTEGER, modified INTEGER, text TEXT, authorId INTEGER, type TEXT, status TEXT, allowComment TEXT, allowPing TEXT, allowFeed TEXT, template TEXT, password TEXT)');
    $db->query('CREATE TABLE admin_fields (cid INTEGER, name TEXT, type TEXT, str_value TEXT, PRIMARY KEY(cid,name))');
    $db->query('CREATE TABLE admin_infinitytime_images (id INTEGER PRIMARY KEY, cid INTEGER, sort INTEGER, full TEXT, exif TEXT, upload_key TEXT, upload_fingerprint TEXT, UNIQUE(cid,upload_key))');
    $draft = Workflow::draft(7, ['title' => 'Draft A'], str_repeat('a', 32));
    $cid = (int)$draft['cid'];
    check($draft['status'] === 'draft', '新建图集先保存草稿');
    $same = Workflow::draft(7, ['title' => 'Changed title after lost response'], str_repeat('a', 32));
    check((int)$same['cid'] === $cid && $same['replayed'], '重试创建复用已保存图集');
    $other = Workflow::draft(8, ['title' => 'Other user'], str_repeat('a', 32));
    check((int)$other['cid'] !== $cid, '操作标识按创建者隔离');
    check(Repository::album($cid, 8, false) === null, '贡献者不能读取其他作者图集');
    check(Repository::album($cid, 8, true) !== null, '管理员可编辑真实图集');
    $db->query("INSERT INTO admin_contents (cid,title,created,authorId,type,status) VALUES (500,'Ordinary post',1,7,'post','publish'),(501,'Fake page',1,7,'page','publish')");
    Repository::setField(501, Repository::MARKER, '1');
    check(Repository::album(500, 7, true) === null && Repository::album(501, 7, true) === null && Repository::album(999, 7, true) === null, '管理员仍拒绝普通文章、页面和不存在标识');
    rejects(static function () use ($cid) { Workflow::publish($cid, 7, false, true); }, '空草稿不可发布');
    $tmp = tempnam(sys_get_temp_dir(), 'infinity-admin-'); file_put_contents($tmp, 'test image bytes');
    $file = ['name' => 'good.jpg', 'tmp_name' => $tmp, 'error' => UPLOAD_ERR_OK, 'size' => 16];
    try {
        $row = Workflow::upload($cid, 7, false, $file, ['title' => 'A'], str_repeat('1', 32));
        check($row['rowId'] > 0 && !$row['replayed'], '单张上传持久化图片行');
        check(Images::$lastOptions['keep_original'] === true && Images::$lastOptions['pano_quality'] === 92 && Images::$lastOptions['pano_width'] === 0, '保留原图与全景设置');
        check(Repository::field($cid, 'device') === 'Camera Test', '保留 EXIF 设备回填');
        $replay = Workflow::upload($cid, 7, false, $file, ['title' => 'A'], str_repeat('1', 32));
        check($replay['rowId'] === $row['rowId'] && $replay['replayed'] && Images::$ingested === 1, '重复重试不重新编码或插入');
        rejects(static function () use ($cid, $file) { Workflow::upload($cid, 7, false, $file, ['title' => 'Changed'], str_repeat('1', 32)); }, '同一标识对应不同元数据时拒绝');
        file_put_contents($tmp, 'different image bytes');
        rejects(static function () use ($cid, $file) { Workflow::upload($cid, 7, false, $file, ['title' => 'A'], str_repeat('1', 32)); }, '同一标识对应不同文件内容时拒绝');
        file_put_contents($tmp, 'test image bytes');
        rejects(static function () use ($cid, $file) { Workflow::upload($cid, 8, false, $file, [], str_repeat('2', 32)); }, '其他作者不可上传');
        $bad = $file; $bad['name'] = 'bad.heic';
        rejects(static function () use ($cid, $bad) { Workflow::upload($cid, 7, false, $bad, [], str_repeat('2', 32)); }, '单图转换失败不影响其他图片');
        check((Repository::counts([$cid])[$cid] ?? 0) === 1, '单项失败后成功图片保留');
        Images::$failSync = true;
        rejects(static function () use ($cid, $file) { Workflow::upload($cid, 7, false, $file, [], str_repeat('3', 32)); }, '入库后字段同步失败可见');
        $before = Images::$ingested;
        Images::$failSync = false;
        $repair = Workflow::upload($cid, 7, false, $file, [], str_repeat('3', 32));
        check($repair['replayed'] && Images::$ingested === $before && (Repository::counts([$cid])[$cid] ?? 0) === 2, '响应丢失或失败后重放修复字段且不重复入库');
        Images::$busy = true;
        rejects(static function () use ($cid, $file) { Workflow::upload($cid, 7, false, $file, [], str_repeat('4', 32)); }, '锁忙时在处理图片前拒绝');
        check(Images::$ingested === $before, '锁忙请求不处理文件');
        Images::$busy = false;
        rejects(static function () use ($cid) { Workflow::publish($cid, 7, false); }, '贡献者不能绕过发布审核');
        Workflow::publish($cid, 7, false, true);
        check(Repository::album($cid, 7, false)['status'] === 'publish', '明确发布操作改变状态');
        rejects(static function () use ($cid, $file) { Workflow::upload($cid, 7, false, $file, [], str_repeat('4', 32)); }, '贡献者不能追加到已发布图集');
        Workflow::upload($cid, 7, false, $file, [], str_repeat('4', 32), true);
        check(Repository::album($cid, 7, false)['status'] === 'publish' && (Repository::counts([$cid])[$cid] ?? 0) === 3, '追加保留原图集身份及发布状态');
        $legacy = Workflow::draft(7, ['title' => 'Legacy album'], str_repeat('l', 32));
        Repository::setField((int)$legacy['cid'], 'img', "/old-a.webp\n/old-b.webp");
        $before = Images::$ingested;
        rejects(static function () use ($legacy, $file) { Workflow::upload((int)$legacy['cid'], 7, false, $file, [], str_repeat('5', 32)); }, '历史图集追加在文件处理前拒绝');
        check(Images::$ingested === $before && Repository::field((int)$legacy['cid'], 'img') === "/old-a.webp\n/old-b.webp", '历史图集图片保持完整');
        foreach ($db->operations as $operation) {
            if (in_array($operation['sql'], ['BEGIN', 'COMMIT', 'ROLLBACK'], true) || strpos($operation['sql'], 'SELECT ') === 0) {
                check($operation['op'] === \Typecho\Db::WRITE && !$operation['object'], '事务与写流程读取使用预处理后的 SQL 主库连接');
            }
        }
        for ($i = 0; $i < 47; $i++) { Workflow::draft(7, ['title' => 'Search item ' . $i], str_pad((string)$i, 32, 'z', STR_PAD_LEFT)); }
        Workflow::draft(7, ['title' => 'Literal %_! quote\''], str_repeat('q', 32));
        $db->reads = [];
        $page = Repository::page(7, false, 2, 'Search item');
        check(count($page['items']) === 20 && $page['total'] === 47 && $page['pages'] === 3 && $page['page'] === 2, '支持搜索且分页有界');
        $queries = implode("\n", $db->reads);
        check(strpos($queries, 'LIMIT 20 OFFSET 20') !== false && strpos($queries, 'COUNT(*) AS image_count') !== false, 'SQL 摘要查询有界并使用聚合计数');
        check(strpos($queries, 'exif') === false && strpos($queries, 'SELECT *') === false, '列表不加载图片完整数据');
        check(Repository::page(7, false, 1, "%_! quote'")['total'] === 1, '搜索通配符及引号安全转义');
        check(Repository::page(8, false)['total'] === 1, '列表限制为贡献者自己的图集');
        $db->adapter = 'Pdo_Pgsql';
        $db->reads = [];
        check(Repository::album($cid, 7, false) !== null, 'PostgreSQL 权限查询 SQL 可执行');
        check(strpos($db->reads[0], 'c."authorId"') !== false && strpos($db->reads[0], 'c.authorId') === false, 'PostgreSQL 驼峰列名正确引用');
        $db->reads = [];
        Repository::draftForOperation(7, str_repeat('a', 32));
        check(strpos($db->reads[0], 'c."authorId"') !== false, 'PostgreSQL 操作查询正确引用作者列名');
        echo "后台流程：$checks 项检查通过\n";
    } finally { unlink($tmp); }
}
