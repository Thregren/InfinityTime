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
        public array $legacyFields = [];
        public static function get(): self { return self::$instance; }
        public function getPrefix(): string { return 'test_'; }
        public function select(...$args): Query { return new Query('select'); }
        public function insert($table): Query { return (new Query('insert'))->from($table); }
        public function delete($table): Query { return (new Query('delete'))->from($table); }
        public function update($table): Query { return (new Query('update'))->from($table); }
        public function fetchAll($query): array { return $query->table === 'test_fields' ? $this->legacyFields : $this->images; }
        public function query($query): void {
            if ($query->table === 'test_infinitytime_images' && $query->kind === 'update') {
                foreach ($this->images as &$image) {
                    if (($image['id'] ?? null) === $query->conditions['id = ?'][0]) { $image = array_merge($image, $query->values); }
                }
                unset($image);
                return;
            }
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
        public static function process(...$args): array {
            self::$calls[] = $args;
            return ['width' => $args[5], 'height' => 80, 'size' => 321,
                'mid' => null, 'avif' => null, 'mid_avif' => null];
        }
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
        expect($db->images[0]['width'] === 180 && $db->images[1]['width'] === 120, '重建持久化实际输出宽度');
        expect($db->images[1]['height'] === 80 && $db->images[1]['size'] === 321, '重建持久化高度和字节数');
        $db->images[0]['mid'] = '/uploads/full/pano@1600.webp';
        $db->images[0]['avif'] = '/uploads/full/pano.avif';
        $db->images[0]['mid_avif'] = '/uploads/full/pano@1600.avif';
        foreach ($db->images as $row) {
            foreach (['full', 'thumb', 'mid', 'avif', 'mid_avif'] as $field) {
                if (isset($row[$field])) { file_put_contents(Repository::toAbs($row[$field]), 'fixture'); }
            }
        }
        file_put_contents($root . '/uploads/full/orphan.webp', 'fixture');
        touch($root . '/uploads/full/orphan.webp', time() - 7200);
        expect(Repository::cleanupOrphans()['removed_files'] === 1, '只清理一个孤儿文件');
        foreach (['mid', 'avif', 'mid_avif'] as $field) {
            expect(is_file(Repository::toAbs($db->images[0][$field])), '保留引用 ' . $field);
        }

        // 快照生成后补入引用，恢复旧任务不得删掉该图。
        $late = $root . '/uploads/full/late.webp';
        file_put_contents($late, 'late'); touch($late, time() - 7200);
        $snapshot = Repository::orphanCandidates();
        $db->images[] = ['full' => '/uploads/full/late.webp'];
        expect(Repository::removeOrphanCandidates($snapshot) === 0 && is_file($late), '删除前重查新增数据库引用');
        $changed = $root . '/uploads/full/changed.webp';
        file_put_contents($changed, 'old'); touch($changed, time() - 7200);
        $snapshot = Repository::orphanCandidates();
        file_put_contents($changed, 'replacement with different size'); touch($changed, time() - 7200);
        expect(Repository::removeOrphanCandidates($snapshot) === 0 && is_file($changed), '文件身份变化后跳过旧候选');
        $fresh = $root . '/uploads/full/fresh.webp'; file_put_contents($fresh, 'new upload');
        expect(!in_array($fresh, array_column(Repository::orphanCandidates(), 'path'), true), '新上传文件享有宽限期');
        expect(Repository::removeOrphanCandidates([$changed]) === 0, '拒绝旧版纯路径删除快照');
        $outside = $root . '/outside.webp'; file_put_contents($outside, 'outside'); touch($outside, time() - 7200);
        symlink($outside, $root . '/uploads/full/link.webp');
        expect(!in_array($root . '/uploads/full/link.webp', array_column(Repository::orphanCandidates(), 'path'), true), '不收集指向外部的软链接');
        expect(Repository::removeOrphanCandidates([['path' => $outside]]) === 0 && is_file($outside), '拒绝上传目录外的路径');
        $legacy = $root . '/uploads/full/legacy.webp';
        $legacyAvif = $root . '/uploads/full/legacy.avif';
        foreach ([$legacy, $legacyAvif] as $path) { file_put_contents($path, 'legacy'); touch($path, time() - 7200); }
        $db->legacyFields = [
            ['name' => 'img', 'str_value' => "https://example.com/uploads/full/legacy.webp\n/uploads/full/other.webp"],
            ['name' => 'variants', 'str_value' => '[{"avif":["/uploads/full/legacy.avif"]}]'],
        ];
        $legacyCandidates = array_column(Repository::orphanCandidates(), 'path');
        expect(!in_array($legacy, $legacyCandidates, true) && !in_array($legacyAvif, $legacyCandidates, true), '保留仅由历史文章字段引用的全图和变体');
        // 模拟其他进程持锁：失败时绝不执行清理。
        Repository::unlockMedia();
        $other = fopen($root . '/uploads/.infinitytime-media.lock', 'c');
        flock($other, LOCK_EX | LOCK_NB);
        $blocked = false;
        try { Repository::cleanupOrphans(); } catch (RuntimeException $e) { $blocked = true; }
        expect($blocked && is_file($changed), '上传/维护锁竞争时安全失败');
        flock($other, LOCK_UN); fclose($other);
        Repository::lockMedia();
        Repository::lockMedia(); // 同一请求可复用锁，覆盖 ingest 到 insertRow 的间隙。

        $db->images = [
            ['id' => 21, 'created' => 1767225600, 'full' => '/b.webp', 'thumb' => '/b-thumb.webp', 'title' => 'B', 'width' => 2400, 'height' => 1600, 'mid' => '/b@1600.webp', 'avif' => '/b.avif', 'exif' => '{"gps":"private","iso":100}'],
            ['id' => 15, 'created' => 1735689600, 'full' => '/a.webp', 'thumb' => '/a-thumb.webp', 'title' => 'A', 'exif' => '{}'],
        ];
        expect(Repository::photoMonth(['datetime' => '2024:02:29 10:11:12'], 1735689600) === '2024-02', 'EXIF valid leap date keeps camera month');
        expect(Repository::photoMonth(['datetime' => '2025:02:29 10:11:12'], 1735689600) === '2025-01', 'Invalid EXIF day uses creation UTC month');
        expect(Repository::photoMonth([], 0) === '', 'Missing dates remain unknown');
        Repository::syncPostFields(1);
        expect(json_decode($db->fields['photo_ids'], true) === [21, 15], 'Stable IDs follow sorted rows, not positions');
        expect(json_decode($db->fields['months'], true) === ['2026-01', '2025-01'], 'Photo months synced in same order');
        expect($db->fields['img'] === "/b.webp\n/a.webp", '图片路径与仓库排序一致');
        expect($db->fields['thumb'] === "/b-thumb.webp\n/a-thumb.webp", '缩略图同序');
        expect(json_decode($db->fields['dims'], true) === ['2400x1600', ''], '首次同步包含尺寸字段');
        $variants = json_decode($db->fields['variants'], true);
        expect($variants[0]['w'] === 2400 && $variants[0]['webp'] === ['/b.webp', '/b@1600.webp'] && $variants[0]['avif'] === ['/b.avif'], '首次同步包含响应式变体');
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
        Repository::unlockMedia();
        // 仅移除本次随机创建的 fixture 目录。
        $entries = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($entries as $entry) { $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname()); }
        rmdir($root);
    }
}

