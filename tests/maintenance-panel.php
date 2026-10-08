<?php
/** 真实 panel.php 请求级维护回归；每种模式独立进程，编码器与媒体存储使用替身。
 * php tests/maintenance-panel.php <mode>（默认 rebuild-sync-failure）。
 * 测试复制未经修改的面板到独立临时目录，不接触插件真实 data 目录。 */
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
        public bool $failDelete = false;
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
            if ($this->failDelete && strpos((string)$query, 'DELETE FROM "admin_contents"') === 0) { throw new \RuntimeException('模拟文章删除故障'); }
            $affected = $this->pdo->exec((string)$query);
            return $query instanceof Query && $query->isInsert() ? (int)$this->pdo->lastInsertId() : $affected;
        }
    }
}
namespace Widget {
    class User {
        public int $uid = 7;
        public static function alloc(): self { return new self(); }
        public function pass($role, $return): bool { return true; }
    }
    class Security {
        public static function alloc(): self { return new self(); }
        public function getToken($scope): string { return 'fixture-maintenance-token'; }
    }
}
namespace Utils {
    class Helper {
        public static function options(): object { return (object)['siteUrl' => 'https://example.test/']; }
    }
}
namespace TypechoPlugin\InfinityTime {
    class Plugin {
        public const DEFAULT_QUALITY = 76, DEFAULT_THUMB_MAX = 1280, DEFAULT_MAX_WIDTH = 2560,
            DEFAULT_FULL_QUALITY = 82, DEFAULT_PANO_WIDTH = 0, DEFAULT_PANO_QUALITY = 92;
        public static array $logs = [];
        public static function opt($name, $default) { return $default; }
        public static function log($message): void { self::$logs[] = $message; }
    }
}
namespace TypechoPlugin\InfinityTime\Lib {
    class ImageRepository {
        public static string $root;
        public static bool $locked = false;
        public static bool $failSync = false;
        public static array $updated = [];
        public static array $synced = [];
        public static function lockMedia(): void { self::$locked = true; }
        public static function table(): string { return 'admin_infinitytime_images'; }
        public static function toAbs(string $path): string { return self::$root . '/' . ltrim($path, '/'); }
        public static function isPano(int $w, int $h): bool { return $w > $h * 2; }
        public static function updateVariants(int $id, array $result): void {
            if (!self::$locked) { throw new \RuntimeException('变体更新必须持有媒体锁'); }
            self::$updated[] = $id;
        }
        public static function syncPostFields(int $cid): void {
            if (!self::$locked) { throw new \RuntimeException('字段同步必须持有媒体锁'); }
            self::$synced[] = $cid;
            if (self::$failSync) { throw new \RuntimeException('模拟字段同步故障 /private/server/database.sqlite'); }
        }
    }
    class MediaProcessor {
        public static bool $fail = false;
        public static int $calls = 0;
        public static function process(...$args): array {
            self::$calls++;
            if (self::$fail) { throw new \RuntimeException('模拟编码故障 /private/server/original.png'); }
            return ['width' => 1, 'height' => 1];
        }
    }
}
namespace {
    use TypechoPlugin\InfinityTime\Lib\ImageRepository as Images;
    use TypechoPlugin\InfinityTime\Lib\MaintenanceState as State;
    use TypechoPlugin\InfinityTime\Lib\MediaProcessor as Media;
    use TypechoPlugin\InfinityTime\Plugin;
    function _t($message): string { return $message; }
    function expect(bool $condition, string $message): void { if (!$condition) { throw new RuntimeException($message); } }
    function removeFixture(string $dir): void {
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $item) {
            if ($item->isDir()) { rmdir($item->getPathname()); } else { unlink($item->getPathname()); }
        }
        rmdir($dir);
    }
    $mode = $argv[1] ?? 'rebuild-sync-failure';
    $modes = ['rebuild-sync-failure', 'rebuild-success', 'rebuild-batch', 'rebuild-restart', 'rebuild-resume', 'rebuild-start-reuse',
        'rebuild-no-original', 'rebuild-missing-original', 'rebuild-conversion-failure', 'stale-poll', 'finished-poll',
        'delete-non-plugin', 'delete-rollback', 'lease-conflict',
        'retry-image', 'retry-fields', 'retry-mixed', 'retry-resync', 'retry-repeat', 'retry-finished',
        'retry-stale', 'retry-empty', 'retry-unfinished', 'retry-other-job', 'errors-page', 'errors-stale', 'resync-failure'];
    expect(in_array($mode, $modes, true), '未知测试模式：' . $mode);
    $source = dirname(__DIR__) . '/usr/plugins/InfinityTime';
    require $source . '/Lib/Database.php';
    require $source . '/Lib/AdminSecurity.php';
    require $source . '/Lib/AdminRepository.php';
    require $source . '/Lib/MaintenanceState.php';
    $root = sys_get_temp_dir() . '/infinity-maintenance-panel-' . bin2hex(random_bytes(8));
    mkdir($root, 0700); mkdir($root . '/Lib', 0700); mkdir($root . '/data', 0700);
    copy($source . '/panel.php', $root . '/panel.php');
    // 继续使用已加载的真实状态类，避免重复声明。
    file_put_contents($root . '/Lib/MaintenanceState.php', '<?php require_once ' . var_export($source . '/Lib/MaintenanceState.php', true) . ';');
    Images::$root = $root;
    file_put_contents($root . '/original.png', base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+/p9sAAAAASUVORK5CYII='));
    $db = \Typecho\Db::$instance = new \Typecho\Db();
    $db->query('CREATE TABLE admin_infinitytime_images (id INTEGER PRIMARY KEY, cid INTEGER, original TEXT, full TEXT, thumb TEXT)');
    $db->query('CREATE TABLE admin_contents (cid INTEGER PRIMARY KEY, title TEXT, type TEXT)');
    $db->query('CREATE TABLE admin_fields (cid INTEGER, name TEXT, str_value TEXT)');
    $db->query("INSERT INTO admin_infinitytime_images VALUES (1,10,'/original.png','/full.webp','/thumb.webp')");
    $oldId = str_repeat('a', 32);
    $state = ['job' => 'rebuild', 'job_id' => $oldId, 'total' => 3, 'done' => 1, 'failed' => 0, 'current' => 'old', 'finished' => false];
    $_SERVER['REQUEST_METHOD'] = 'POST'; $_GET = []; $_FILES = [];
    $_POST = ['action' => 'maintenance', 'job' => 'rebuild', 'mode' => 'start', 'ajax' => '1', '_' => 'fixture-maintenance-token'];
    if ($mode === 'rebuild-batch') {
        for ($id = 2; $id <= 5; $id++) {
            $db->query("INSERT INTO admin_infinitytime_images VALUES ($id,10,'/original.png','/full$id.webp','/thumb$id.webp')");
        }
    }
    if ($mode === 'rebuild-sync-failure') { Images::$failSync = true; }
    if ($mode === 'rebuild-conversion-failure') { Media::$fail = true; }
    if ($mode === 'rebuild-no-original') { $db->query("UPDATE admin_infinitytime_images SET original = ''"); }
    if ($mode === 'rebuild-missing-original') { unlink($root . '/original.png'); }
    if (in_array($mode, ['rebuild-restart', 'rebuild-resume', 'rebuild-start-reuse', 'stale-poll', 'finished-poll'], true)) {
        if (in_array($mode, ['rebuild-restart', 'finished-poll'], true)) { $state['done'] = 3; $state['finished'] = true; }
        State::write($root . '/data/job.json', $state);
        State::write(State::listFile($root . '/data', $state), [1, 2, 3]);
        // 快照中的第 2 行已删除，第 3 行仍存在，第 4 行是快照之后的新图片。
        $db->query("INSERT INTO admin_infinitytime_images VALUES (3,30,'/original.png','/full3.webp','/thumb3.webp'),(4,40,'/original.png','/full4.webp','/thumb4.webp')");
        if ($mode === 'rebuild-resume') { $_POST['mode'] = 'resume'; $_POST['job_id'] = $oldId; }
        if (in_array($mode, ['stale-poll', 'finished-poll'], true)) {
            $_POST['mode'] = 'poll'; $_POST['job_id'] = $mode === 'stale-poll' ? str_repeat('b', 32) : $oldId;
            State::write($root . '/data/job.lock', ['uid' => 7, 'job' => 'rebuild', 'time' => time() - 10]);
        }
    }
    if (in_array($mode, ['delete-non-plugin', 'delete-rollback'], true)) {
        $_POST = ['action' => 'delete_non_plugin', 'ajax' => '1', '_' => 'fixture-maintenance-token'];
        $db->query("INSERT INTO admin_contents VALUES (10,'Image-backed','post'),(11,'Marked draft','post'),(12,'Legacy album','post'),(13,'Ordinary post','post'),(14,'Page','page')");
        $db->query("INSERT INTO admin_fields VALUES (11,'infinitytime_album','1'),(12,'img','legacy.webp'),(13,'description','ordinary')");
        $db->failDelete = $mode === 'delete-rollback';
    }
    if ($mode === 'lease-conflict') {
        State::write($root . '/data/job.lock', ['uid' => 7, 'job' => 'cleanup', 'time' => time()]);
    }
    $retryModes = ['retry-image', 'retry-fields', 'retry-mixed', 'retry-resync', 'retry-repeat', 'retry-finished',
        'retry-stale', 'retry-empty', 'retry-unfinished', 'retry-other-job', 'errors-page', 'errors-stale'];
    if (in_array($mode, $retryModes, true)) {
        $state['done'] = 3; $state['finished'] = true;
        $db->query("INSERT INTO admin_infinitytime_images VALUES (2,20,'/original.png','/full2.webp','/thumb2.webp'),(3,30,'/original.png','/full3.webp','/thumb3.webp')");
        if ($mode === 'retry-resync') { $state['job'] = 'resync'; }
        if (!in_array($mode, ['retry-fields', 'retry-resync', 'retry-empty', 'errors-page'], true)) { State::failure($state, 'rebuild', 1, 10, 'conversion'); }
        if (in_array($mode, ['retry-fields', 'retry-mixed'], true)) { State::failure($state, 'resync', 30, 30, 'field_sync'); }
        if ($mode === 'retry-resync') { State::failure($state, 'resync', 10, 10, 'field_sync'); }
        if (in_array($mode, ['retry-repeat', 'retry-finished'], true)) { State::failure($state, 'rebuild', 3, 30, 'conversion'); }
        if ($mode === 'errors-page') {
            for ($id = 1; $id <= 123; $id++) { State::failure($state, 'rebuild', $id, 10, 'conversion'); }
        }
        if (in_array($mode, ['retry-unfinished', 'retry-other-job'], true)) {
            $state['done'] = 1; $state['finished'] = false;
            State::write(State::listFile($root . '/data', $state), [1, 2, 3]);
        }
        State::save($root . '/data', $state);
        $_POST['job'] = $state['job']; $_POST['mode'] = 'retry'; $_POST['job_id'] = $oldId;
        if ($mode === 'retry-repeat' || $mode === 'retry-finished') {
            [$state] = State::open($root . '/data', 'rebuild', 'retry', $oldId, static function () { throw new RuntimeException('不得重扫'); });
            $state['done'] = $mode === 'retry-repeat' ? 1 : 2;
            $state['finished'] = $mode === 'retry-finished';
            State::save($root . '/data', $state);
        }
        if ($mode === 'retry-stale' || $mode === 'errors-stale') { $_POST['job_id'] = str_repeat('b', 32); }
        if ($mode === 'retry-other-job') { $_POST['job'] = 'cleanup'; $_POST['mode'] = 'start'; unset($_POST['job_id']); }
        if (strpos($mode, 'errors-') === 0) { $_POST['mode'] = 'errors'; $_POST['offset'] = 100; }
    }
    if ($mode === 'resync-failure') { $_POST['job'] = 'resync'; Images::$failSync = true; }
    $beforeState = is_file($root . '/data/job.json') ? file_get_contents($root . '/data/job.json') : null;
    $beforeLock = is_file($root . '/data/job.lock') ? file_get_contents($root . '/data/job.lock') : null;
    $beforeFiles = glob($root . '/data/*');
    $db->operations = []; $db->reads = [];
    define('__TYPECHO_ADMIN__', true);
    ob_start();
    register_shutdown_function(static function () use ($root, $mode, $db, $oldId, $beforeState, $beforeLock, $beforeFiles) {
        $output = ob_get_clean();
        try {
            $json = json_decode($output, true);
            expect(is_array($json), '响应必须为 JSON：' . $output);
            foreach ($db->operations as $operation) {
                if (strpos($operation['sql'], 'SELECT ') === 0 || in_array($operation['sql'], ['BEGIN', 'COMMIT', 'ROLLBACK'], true)) {
                    expect($operation['op'] === \Typecho\Db::WRITE && !$operation['object'], '快照、实时读取与事务必须使用主库连接');
                }
            }
            expect(strpos($output, '/private/server/') === false && strpos($output, $root) === false, '失败响应不能泄漏服务器路径');
            if (in_array($mode, ['retry-stale', 'retry-empty', 'retry-unfinished', 'retry-other-job', 'errors-stale'], true)) {
                expect($json['ok'] === false, '不合法重试必须明确拒绝');
                expect(file_get_contents($root . '/data/job.json') === $beforeState && glob($root . '/data/*') === $beforeFiles, '拒绝不能覆盖或增添检查点');
                expect(Media::$calls === 0 && Images::$synced === [] && $db->operations === [], '拒绝必须先于任何扫描或媒体操作');
            } elseif ($mode === 'errors-page') {
                expect($json['ok'] === true && $json['failure_total'] === 123 && $json['failure_offset'] === 100 && count($json['failures']) === 23 && $json['failure_next'] === null, '最后一页失败必须完整可达');
                expect($json['failures'][22]['id'] === 123 && $json['job_id'] === $oldId, '分页必须保留原任务身份且包含最后项目');
                expect(file_get_contents($root . '/data/job.json') === $beforeState && glob($root . '/data/*') === $beforeFiles && $db->operations === [], '翻页不能处理媒体或改写状态');
            } elseif (in_array($mode, ['retry-image', 'retry-fields', 'retry-mixed', 'retry-resync', 'retry-repeat', 'retry-finished'], true)) {
                expect($json['finished'] === true && $json['failed'] === 0 && $json['retry_of'] === $oldId && $json['job_id'] !== $oldId, '重试必须完成独立子任务并保留原任务身份');
                $expectedImages = $mode === 'retry-repeat' ? [3] : (in_array($mode, ['retry-image', 'retry-mixed'], true) ? [1] : []);
                $expectedAlbums = $mode === 'retry-finished' ? [] : (in_array($mode, ['retry-fields', 'retry-repeat'], true) ? [30] : ($mode === 'retry-mixed' ? [10, 30] : [10]));
                expect(Images::$updated === $expectedImages && Media::$calls === count($expectedImages) && Images::$synced === $expectedAlbums, '只能处理指定失败图片及字段失败图集');
                foreach ($db->reads as $sql) { expect(strpos($sql, 'WHERE (id = ') !== false, '重试不得再次扫描全库图片或字段'); }
                $persisted = State::read($root . '/data/job.json');
                unset($json['ok'], $json['msg']);
                expect(State::response($persisted) === $json, '重试响应必须与持久化检查点一致');
                if ($mode === 'retry-finished') { expect(file_get_contents($root . '/data/job.json') === $beforeState && $db->operations === [], '重试首批完成且丢失响应后不能再次执行'); }
            } elseif ($mode === 'resync-failure') {
                expect($json['finished'] === true && $json['failed'] === 1 && Media::$calls === 0 && Images::$synced === [10], '字段失败独立于图片编码');
                expect($json['failures'][0]['task'] === 'resync' && $json['failures'][0]['id'] === 10 && $json['failures'][0]['code'] === 'field_sync', '字段失败必须保留图集身份及安全原因');
            } elseif ($mode === 'lease-conflict') {
                expect($json['finished'] === true && strpos($json['msg'], '另一个维护任务') !== false, '同一管理员不同任务的有效租约必须拒绝');
                expect(file_get_contents($root . '/data/job.lock') === $beforeLock && glob($root . '/data/*') === $beforeFiles, '租约冲突不得写入检查点或修改锁');
                expect(Media::$calls === 0 && Images::$updated === [] && Images::$synced === [] && $db->operations === [], '租约冲突必须在数据库读取和媒体处理前停止');
            } elseif (in_array($mode, ['stale-poll', 'finished-poll'], true)) {
                expect(file_get_contents($root . '/data/job.json') === $beforeState, '轮询不得重写检查点');
                expect(file_get_contents($root . '/data/job.lock') === $beforeLock, '轮询不得刷新或删除锁');
                expect(glob($root . '/data/*') === $beforeFiles, '轮询不得创建状态文件');
                expect(Media::$calls === 0 && Images::$synced === [], '轮询不得处理过期或已完成任务');
                expect($mode === 'stale-poll' ? (http_response_code() === 500 && $json['ok'] === false) : $json['finished'] === true, '轮询响应必须报告拒绝或完成');
            } elseif (in_array($mode, ['delete-non-plugin', 'delete-rollback'], true)) {
                $remaining = $db->pdo->query('SELECT cid FROM admin_contents ORDER BY cid')->fetchAll(PDO::FETCH_COLUMN);
                $remaining = array_map('intval', $remaining);
                $sql = array_column($db->operations, 'sql');
                expect(count(array_filter($sql, static function ($q) { return strpos($q, 'SELECT ') === 0; })) === 3, '三类身份分类快照必须全部从主库读取');
                expect(in_array('BEGIN', $sql, true), '删除必须开启事务');
                if ($mode === 'delete-rollback') {
                    expect($json['ok'] === false && $remaining === [10, 11, 12, 13, 14], '删除失败必须保留每篇文章');
                    expect($db->pdo->query('SELECT COUNT(*) FROM admin_fields WHERE cid=13')->fetchColumn() == 1, '回滚必须恢复已删除的自定义字段');
                    expect(in_array('ROLLBACK', $sql, true) && !in_array('COMMIT', $sql, true), '删除失败必须回滚');
                } else {
                    expect($json['ok'] === true && $json['deleted'] === 1 && $remaining === [10, 11, 12, 14], '仅删除普通文章，保留所有图集身份及页面');
                    expect(in_array('COMMIT', $sql, true), '成功删除必须提交事务');
                }
            } elseif ($mode === 'rebuild-batch') {
                expect($json['finished'] === false && $json['total'] === 5 && $json['done'] === 3 && $json['failed'] === 0, '首个请求必须在批次上限停止并保留可继续进度');
                expect(Images::$updated === [1, 2, 3] && Media::$calls === 3, '首批必须恰好处理三条图片记录');
                expect(Images::$synced === [10], '同一图集的多图每批只同步一次聚合字段');
                expect(State::response(State::read($root . '/data/job.json')) === $json, '未完成的持久化检查点必须与响应一致');
                expect(State::read(State::listFile($root . '/data', $json)) === [1, 2, 3, 4, 5], '未完成任务必须保留完整稳定快照');
                $lock = State::read($root . '/data/job.lock');
                expect($lock['uid'] === 7 && $lock['job'] === 'rebuild', '未完成任务必须保留用户及任务锁');
            } else {
                expect(($json['finished'] ?? false) === true && !isset($json['ok']), '维护必须返回终态进度而非错误响应');
                $failed = in_array($mode, ['rebuild-sync-failure', 'rebuild-missing-original', 'rebuild-conversion-failure'], true) ? 1 : 0;
                expect($json['failed'] === $failed, '失败计数必须在响应中可见');
                expect($json['failure_total'] === $failed && count($json['failures']) === $failed, '新任务每个失败必须有可恢复的明细');
                if ($failed) {
                    $failure = $json['failures'][0];
                    expect($failure['cid'] === 10 && !empty($failure['reason']), '失败必须包含相册身份和安全原因');
                    expect($failure['task'] === ($mode === 'rebuild-sync-failure' ? 'resync' : 'rebuild'), '字段失败只能计划字段重试');
                    expect($failure['id'] === ($mode === 'rebuild-sync-failure' ? 10 : 1), '失败项必须指向真实图片或相册身份');
                }
                $expectedIds = in_array($mode, ['rebuild-resume', 'rebuild-start-reuse'], true) ? [3] : ($mode === 'rebuild-restart' ? [1,3,4] : (in_array($mode, ['rebuild-no-original', 'rebuild-missing-original', 'rebuild-conversion-failure'], true) ? [] : [1]));
                expect(Images::$updated === $expectedIds, '重建必须使用正确的快照与实时记录');
                expect($json['total'] === ($mode === 'rebuild-no-original' ? 0 : (in_array($mode, ['rebuild-restart', 'rebuild-resume', 'rebuild-start-reuse'], true) ? 3 : 1)), '快照总数必须准确');
                expect($json['done'] === $json['total'], '终态偏移必须等于总数');
                expect(!is_file($root . '/data/job.lock'), '完成维护后必须释放任务锁');
                expect(State::response(State::read($root . '/data/job.json')) === $json, '持久化状态必须与响应一致');
                expect(!is_file(State::listFile($root . '/data', $json)), '已完成的快照必须清理');
                if (in_array($mode, ['rebuild-resume', 'rebuild-start-reuse'], true)) { expect($json['job_id'] === $oldId, '未完成任务必须复用身份与偏移'); }
                if ($mode === 'rebuild-restart') { expect($json['job_id'] !== $oldId, '重新开始必须生成新身份并包含新增图片'); }
                if ($mode === 'rebuild-sync-failure') {
                    expect(strpos($json['current'], '字段同步失败') !== false && Images::$synced === [10], '同步失败必须可见并带修复提示');
                    expect(count(Plugin::$logs) === 1 && strpos(Plugin::$logs[0], '模拟字段同步故障') !== false, '同步异常必须记录日志');
                }
            }
            echo '维护面板请求检查通过：' . $mode . "\n";
        } catch (Throwable $e) {
            fwrite(STDERR, '维护面板请求检查失败：' . $mode . ' ' . $e->getMessage() . "\n");
            removeFixture($root);
            exit(1);
        }
        removeFixture($root);
    });
    require $root . '/panel.php';
    throw new RuntimeException('AJAX 请求意外进入页面渲染');
}
