<?php
/** 仓库行为测试：DB/编码器使用测试替身，文件引用/清理使用隔离临时目录。 */
declare(strict_types=1);

namespace Typecho {
    class Query {
        public string $table = '';
        public array $values = [];
        public array $conditions = [];
        public function __construct(public string $kind) {}
        public function from($table): self { $this->table = $table; return $this; }
        public function rows($values): self { $this->values = $values; return $this; }
        public function where($clause, ...$values): self { $this->conditions[$clause] = $values; return $this; }
        public function order(...$args): self { return $this; }
    }
    class Db {
        public const SORT_ASC = 'ASC';
        public static self $instance;
        public array $images = [];
        public array $fields = [];
        public static function get(): self { return self::$instance; }
        public function getPrefix(): string { return 'test_'; }
        public function select(...$args): Query { return new Query('select'); }
        public function insert($table): Query { return (new Query('insert'))->from($table); }
        public function delete($table): Query { return (new Query('delete'))->from($table); }
        public function update($table): Query { return (new Query('update'))->from($table); }
        public function fetchAll($query): array { return $this->images; }
        public function query($query): void {
            if ($query->table !== 'test_fields') { return; }
            if ($query->kind === 'delete') { unset($this->fields[$query->conditions['name = ?'][0]]); }
            if ($query->kind === 'insert') { $this->fields[$query->values['name']] = $query->values['str_value']; }
        }
    }
}

namespace TypechoPlugin\InfinityTime {
    class Plugin {
        public const DEFAULT_QUALITY = 76, DEFAULT_THUMB_MAX = 1280, DEFAULT_MAX_WIDTH = 2560;
        public const DEFAULT_PANO_WIDTH = 0, DEFAULT_FULL_QUALITY = 82, DEFAULT_PANO_QUALITY = 92;
        public static function opt($key, $default) { return $default; }
        public static function migrateSchema(): void {}
        public static function log($message): void {}
    }
}

namespace TypechoPlugin\InfinityTime\Lib {
    class MediaProcessor {
        public static array $calls = [];
        public static function process(...$args): array { self::$calls[] = $args; return []; }
    }
}

namespace {
    use TypechoPlugin\InfinityTime\Lib\ImageRepository as Repository;
    use TypechoPlugin\InfinityTime\Lib\MediaProcessor;

    require __DIR__ . '/../usr/plugins/InfinityTime/Lib/ImageRepository.php';
    $root = sys_get_temp_dir() . '/infinitytime-test-' . bin2hex(random_bytes(8));
    mkdir($root, 0700);
    define('__TYPECHO_ROOT_DIR__', $root);
    define('__TYPECHO_UPLOAD_DIR__', '/uploads');
    define('__TYPECHO_UPLOAD_ROOT_DIR__', $root . '/uploads');
    $db = \Typecho\Db::$instance = new \Typecho\Db();
    $checks = 0;
    function expect(bool $condition, string $name): void {
        global $checks;
        if (!$condition) { throw new RuntimeException($name); }
        $checks++;
    }
    try {
        foreach (['original', 'full', 'thumb'] as $dir) { mkdir($root . '/uploads/' . $dir, 0700, true); }
        // 小型有效 GIF 头；仓库只读尺寸，编码器在此测试边界外。
        file_put_contents($root . '/uploads/original/pano.gif', 'GIF89a' . pack('vv', 200, 100) . str_repeat("\0", 16));
        file_put_contents($root . '/uploads/original/photo.gif', 'GIF89a' . pack('vv', 150, 100) . str_repeat("\0", 16));
        $db->images = [
            ['id' => 1, 'cid' => 0, 'original' => '/uploads/original/pano.gif', 'full' => '/uploads/full/pano.webp', 'thumb' => '/uploads/thumb/pano.webp'],
            ['id' => 2, 'cid' => 0, 'original' => '/uploads/original/photo.gif', 'full' => '/uploads/full/photo.webp', 'thumb' => '/uploads/thumb/photo.webp'],
        ];
        $result = Repository::rebuild(['max_width' => 120, 'full_quality' => 51, 'pano_width' => 180, 'pano_quality' => 94]);
        expect($result === ['rebuilt' => 2, 'failed' => 0], '混合图片重建成功');
        expect(array_slice(MediaProcessor::$calls[0], 5) === [180, 94], '全景独立压缩设置');
        expect(array_slice(MediaProcessor::$calls[1], 5) === [120, 51], '后续普通图不继承全景设置');
        $db->images[0]['mid'] = '/uploads/full/pano@1600.webp';
        $db->images[0]['avif'] = '/uploads/full/pano.avif';
        $db->images[0]['mid_avif'] = '/uploads/full/pano@1600.avif';
        foreach ($db->images as $row) {
            foreach (['full', 'thumb', 'mid', 'avif', 'mid_avif'] as $field) {
                if (isset($row[$field])) { file_put_contents(Repository::toAbs($row[$field]), 'fixture'); }
            }
        }
        file_put_contents($root . '/uploads/full/orphan.webp', 'fixture');
        expect(Repository::cleanupOrphans()['removed_files'] === 1, '只清理一个孤儿文件');
        foreach (['mid', 'avif', 'mid_avif'] as $field) {
            expect(is_file(Repository::toAbs($db->images[0][$field])), '保留引用 ' . $field);
        }

        $db->images = [
            ['full' => '/b.webp', 'thumb' => '/b-thumb.webp', 'title' => 'B', 'exif' => '{"gps":"private","iso":100}'],
            ['full' => '/a.webp', 'thumb' => '/a-thumb.webp', 'title' => 'A', 'exif' => '{}'],
        ];
        Repository::syncPostFields(1);
        expect($db->fields['img'] === "/b.webp\n/a.webp", '图片路径与仓库排序一致');
        expect($db->fields['thumb'] === "/b-thumb.webp\n/a-thumb.webp", '缩略图同序');
        expect(json_decode($db->fields['titles']) === ['B', 'A'], '标题同序');
        expect(strpos($db->fields['exif'], 'private') === false, 'GPS 不写入公开字段');
        $db->images = [$db->images[1]];
        Repository::syncPostFields(1, true);
        expect($db->fields['img'] === '/a.webp', '删除后不留下旧图片地址');
        $db->images = [];
        Repository::syncPostFields(1);
        expect($db->fields['img'] === '/a.webp', '无仓库记录的历史图集不被覆盖');
        Repository::syncPostFields(1, true);
        expect($db->fields['img'] === '' && $db->fields['thumb'] === '' && !isset($db->fields['titles']), '删除最后一张清空地址及元数据');
        echo "Repository: $checks checks passed\n";
    } finally {
        // 仅移除本次随机创建的 fixture 目录。
        $entries = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($entries as $entry) { $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname()); }
        rmdir($root);
    }
}
