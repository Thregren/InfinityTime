<?php
/** 使用真实 SQLite 验证升级语句及上传重试唯一性约束。 */
declare(strict_types=1);
namespace Typecho {
    class Plugin {
        public static function factory($name): object { return new \stdClass(); }
    }
    class Query {
        public function from($table): self { return $this; }
        public function where(...$args): self { return $this; }
        public function limit($limit): self { return $this; }
        public function rows($values): self { return $this; }
        public function __toString(): string { return 'SELECT 1 WHERE 0'; }
        public function prepare(string $sql): string { return $sql; }
    }
    class Db {
        public const WRITE = 2;
        public static self $instance;
        public \PDO $pdo;
        public function __construct() { $this->pdo = new \PDO('sqlite::memory:', null, null, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]); }
        public static function get(): self { return self::$instance; }
        public function getPrefix(): string { return 'fixture_'; }
        public function getAdapterName(): string { return 'Pdo_SQLite'; }
        public function select(...$args): Query { return new Query(); }
        public function insert($table): Query { return new Query(); }
        public function update($table): Query { return new Query(); }
        public function fetchRow($query) { return false; }
        public function fetchAll($query): array { return ($query instanceof \PDOStatement ? $query : $this->pdo->query($query))->fetchAll(\PDO::FETCH_ASSOC); }
        public function query($query, $op = null) {
            if (!is_string($query)) { return null; }
            return preg_match('/^(SELECT|PRAGMA)/', $query) ? $this->pdo->query($query) : $this->pdo->exec($query);
        }
    }
}
namespace Utils {
    class Helper {
        public static object $options;
        public static function options(): object { return self::$options; }
    }
}
namespace {
    interface Typecho_Plugin_Interface {}
    require_once __DIR__ . '/../usr/plugins/InfinityTime/Lib/Database.php';
    require __DIR__ . '/../usr/plugins/InfinityTime/Plugin.php';
    use TypechoPlugin\InfinityTime\Plugin;
    $db = \Typecho\Db::$instance = new \Typecho\Db();
    \Utils\Helper::$options = (object)['infinitytimeSchemaVersion' => 3];
    $db->query('CREATE TABLE fixture_infinitytime_images (id INTEGER PRIMARY KEY, cid INTEGER, full TEXT, title TEXT, "desc" TEXT, mid TEXT, avif TEXT, mid_avif TEXT)');
    $db->query("INSERT INTO fixture_infinitytime_images (id,cid,full) VALUES (1,10,'/keep-a.webp'),(2,10,'/keep-b.webp')");
    Plugin::migrateSchema();
    $db->query("INSERT INTO fixture_infinitytime_images (id,cid,upload_key,upload_fingerprint) VALUES (3,10,'retry-key','hash-a')");
    $rejected = false;
    try { $db->query("INSERT INTO fixture_infinitytime_images (id,cid,upload_key) VALUES (4,10,'retry-key')"); }
    catch (\PDOException $e) { $rejected = true; }
    if (!$rejected) { throw new \RuntimeException('同一相册内上传重试键必须唯一'); }
    $db->query("INSERT INTO fixture_infinitytime_images (id,cid,upload_key) VALUES (4,11,'retry-key')");
    // 模拟索引已建好、版本选项尚未更新时中断，再次升级必须安全完成。
    \Utils\Helper::$options->infinitytimeSchemaVersion = 3;
    Plugin::migrateSchema();
    $rows = $db->fetchAll('SELECT id,full,upload_key FROM fixture_infinitytime_images ORDER BY id');
    if (count($rows) !== 4 || $rows[0]['full'] !== '/keep-a.webp' || $rows[1]['full'] !== '/keep-b.webp'
        || $rows[0]['upload_key'] !== null || (int)\Utils\Helper::$options->infinitytimeSchemaVersion !== 4) {
        throw new \RuntimeException('升级必须保留已有图片及空重试键');
    }
    echo "SQLite 升级数据保留、重试键唯一约束及中断恢复检查通过\n";
}
