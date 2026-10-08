<?php
declare(strict_types=1);

namespace Typecho {
    class Plugin {
        public static function factory($name): object { return new \stdClass(); }
    }
    class Query {
        public array $values = [];
        public function from($table): self { return $this; }
        public function where(...$args): self { return $this; }
        public function limit($limit): self { return $this; }
        public function rows($values): self { $this->values = $values; return $this; }
    }
    class Db {
        public static self $instance;
        public array $columns = [];
        public array $alters = [];
        public ?string $failColumn = null;
        public bool $race = false;
        public string $adapter = 'SQLite';
        public static function get(): self { return self::$instance; }
        public function getPrefix(): string { return 'test_'; }
        public function getAdapterName(): string { return $this->adapter; }
        public function select(...$args): Query { return new Query(); }
        public function insert($table): Query { return new Query(); }
        public function update($table): Query { return new Query(); }
        public function fetchRow($query) { return false; }
        public function query($query): void {
            if (!is_string($query)) { return; }
            if (preg_match('/^SELECT ["`]test_infinitytime_images["`]\.["`]([a-z_]+)["`]/', $query, $match)) {
                if (!in_array($match[1], $this->columns, true)) { throw new \RuntimeException('missing column'); }
                return;
            }
            if (preg_match('/ADD COLUMN ["`]([a-z_]+)["`]/', $query, $match)) {
                $this->alters[] = $match[1];
                if ($match[1] === $this->failColumn) { throw new \RuntimeException('permission denied'); }
                $this->columns[] = $match[1];
                if ($this->race) { throw new \RuntimeException('duplicate column from concurrent migration'); }
                return;
            }
            throw new \RuntimeException('Unexpected SQL: ' . $query);
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
    require __DIR__ . '/../usr/plugins/InfinityTime/Plugin.php';
    require __DIR__ . '/../usr/plugins/InfinityTime/Lib/ImageRepository.php';
    use TypechoPlugin\InfinityTime\Plugin;
    use TypechoPlugin\InfinityTime\Lib\ImageRepository;
    function checkMigration(bool $condition, string $message): void {
        if (!$condition) { throw new RuntimeException($message); }
    }
    $db = \Typecho\Db::$instance = new \Typecho\Db();
    \Utils\Helper::$options = (object)['infinitytimeSchemaVersion' => 0];
    $db->columns = ['title'];
    $db->failColumn = 'avif';
    $failed = false;
    try { ImageRepository::ensureSchema(); } catch (RuntimeException $e) { $failed = true; }
    checkMigration($failed, 'DDL failure must surface');
    checkMigration(\Utils\Helper::$options->infinitytimeSchemaVersion === 0, 'Failure must not advance schema version');
    $db->failColumn = null;
    ImageRepository::ensureSchema();
    checkMigration((int)\Utils\Helper::$options->infinitytimeSchemaVersion === 3, 'Same-process retry completes');
    checkMigration(count(array_filter($db->alters, static function ($c) { return $c === 'desc'; })) === 1, 'Retry skips already-created columns');
    checkMigration(!in_array('title', $db->alters, true), 'Existing columns need no ALTER');
    $count = count($db->alters);
    Plugin::migrateSchema();
    checkMigration(count($db->alters) === $count, 'Current version is idempotent');
    $db->columns = ['title', 'desc'];
    \Utils\Helper::$options->infinitytimeSchemaVersion = 2;
    Plugin::migrateSchema();
    checkMigration(in_array('mid', $db->columns, true) && in_array('avif', $db->columns, true) && (int)\Utils\Helper::$options->infinitytimeSchemaVersion === 3, 'Repair old falsely successful v2 migration');
    foreach (['SQLite', 'Mysql', 'Pgsql'] as $adapter) {
        $db->adapter = $adapter;
        $db->columns = [];
        $db->race = true;
        \Utils\Helper::$options->infinitytimeSchemaVersion = 0;
        Plugin::migrateSchema();
        checkMigration(count($db->columns) === 5 && (int)\Utils\Helper::$options->infinitytimeSchemaVersion === 3, 'Concurrent duplicate is accepted only after verification: ' . $adapter);
    }
    echo "Migration failure / retry / verification passed\n";
}
