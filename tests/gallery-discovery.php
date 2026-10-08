<?php
/** 可执行的公开图集契约测试：需要 PHP 7.4+，无需安装 Typecho。 */
declare(strict_types=1);
namespace Typecho {
    class Query {
        public string $table = '';
        public array $conditions = [];
        public int $maximum = 0, $start = 0;
        public array $columns;
        public function __construct(array $columns) { $this->columns = $columns; }
        public function from(string $table): self { $this->table = $table; return $this; }
        public function where(string $sql, ...$args): self { $this->conditions[$sql] = $args; return $this; }
        public function order(...$args): self { return $this; }
        public function limit($n): self { $this->maximum = (int)$n; return $this; }
        public function offset($n): self { $this->start = (int)$n; return $this; }
        public function join(...$args): self { return $this; }
    }
    class Db {
        public const SORT_ASC = 'ASC', SORT_DESC = 'DESC';
        public static self $instance;
        public array $queries = [], $albums = [], $images = [], $fields = [], $metas = [];
        public bool $modernSchema = true, $repositoryAvailable = true;
        public int $photoFailures = 0;
        public static function get(): self { return self::$instance; }
        public function getPrefix(): string { return 'test_'; }
        public function select(...$columns): Query { return new Query($columns); }
        public function fetchAll(Query $query): array {
            $this->queries[] = $query;
            $columns = array_map(static function ($name) { $parts = explode('.', $name); return end($parts); }, $query->columns);
            if ($query->table === 'test_infinitytime_images') {
                if (!$this->repositoryAvailable) { throw new \RuntimeException('插件表不存在'); }
                if (!$this->modernSchema && array_intersect(['mid', 'avif', 'mid_avif'], $columns)) { throw new \RuntimeException('旧表没有变体列'); }
                if ($this->photoFailures > 0) { $this->photoFailures--; throw new \RuntimeException('临时查询失败：内部私有诊断'); }
            }
            $tables = ['test_contents' => $this->albums, 'test_infinitytime_images' => $this->images,
                'test_fields' => $this->fields, 'test_relationships' => $this->metas];
            if (!isset($tables[$query->table])) { throw new \RuntimeException('未预期的数据表：' . $query->table); }
            $rows = $tables[$query->table];
            foreach ($query->conditions as $sql => $values) {
                $rows = array_values(array_filter($rows, static function ($r) use ($sql, $values): bool {
                    switch ($sql) {
                        case 'type = ?': return $r['type'] === $values[0];
                        case 'status = ?': return $r['status'] === $values[0];
                        case '(password IS NULL OR password = ?)': return ($r['password'] ?? '') === '';
                        case 'created <= ?': return $r['created'] <= $values[0];
                        case 'cid <= ?': return $r['cid'] <= $values[0];
                        case 'cid = ?': return $r['cid'] === $values[0];
                        case 'id = ?': return $r['id'] === $values[0];
                        default: return strpos($sql, 'cid IN') !== false ? in_array($r['cid'], $values, true)
                            : (strpos($sql, 'name IN') !== false ? in_array($r['name'], $values, true) : true);
                    }
                }));
            }
            if ($query->table === 'test_contents') { usort($rows, static fn($a, $b) => $b['cid'] <=> $a['cid']); }
            $rows = array_slice($rows, $query->start, $query->maximum ?: null);
            $columns = array_map(static function ($name) { $parts = explode('.', $name); return end($parts); }, $query->columns);
            return array_map(static function ($row) use ($columns) { return array_intersect_key($row, array_flip($columns)); }, $rows);
        }
    }
}
namespace {
    require __DIR__ . '/../usr/themes/InfinityTime/functions.php';
    error_reporting(E_ALL);
    set_error_handler(static function ($severity, $message, $file, $line): void { throw new RuntimeException($message . ' at ' . $file . ':' . $line); });
    $checks = 0;
    function check(bool $ok, string $name): void { global $checks; if (!$ok) throw new RuntimeException($name); $checks++; }
    function album(int $id, array $extra = []): array {
        return array_merge(['cid' => $id, 'created' => strtotime('2026-08-15 UTC'), 'title' => '相册 ' . $id, 'text' => '', 'status' => 'publish', 'type' => 'post', 'password' => ''], $extra);
    }
    function photo(int $id, array $extra = []): array {
        return array_merge(['id' => $id, 'cid' => 7, 'full' => '/uploads/full/' . $id . '.webp', 'thumb' => '/uploads/thumb/' . $id . '.webp', 'width' => 2000, 'height' => 1000,
            'mid' => null, 'avif' => null, 'mid_avif' => null, 'title' => '照片 ' . $id, 'desc' => '日落风景', 'created' => strtotime('2026-09-01 UTC'), 'exif' => '{"datetime":"2026:04:30 12:00:00","iso":100,"gps":{"lat":51.5},"GPSLatitude":"private","original":"secret"}',
            'original' => '/private/original.jpg', 'gps_lat' => 51.5, 'gps_lng' => -0.1, 'hash' => 'private'], $extra);
    }
    class GalleryFixtureOptions {
        public function __get(string $name) { return $name === 'siteUrl' ? 'https://example.test/sub/' : ''; }
        public function __isset(string $name): bool { return false; }
        public function __call(string $name, array $args): void {
            if ($name === 'themeUrl') { echo 'https://example.test/theme/' . htmlspecialchars($args[0], ENT_QUOTES); }
            elseif ($name === 'charset') { echo 'UTF-8'; }
        }
    }
    class GalleryFixture {
        public GalleryFixtureOptions $options;
        public string $archiveType, $archiveTitle, $archiveSlug;
        public function __construct(string $archiveType = '', string $archiveTitle = '', string $archiveSlug = '') {
            $this->archiveType = $archiveType; $this->archiveTitle = $archiveTitle; $this->archiveSlug = $archiveSlug;
            $this->options = new GalleryFixtureOptions();
        }
        public function is(string $type): bool { return $type === $this->archiveType; }
        public function getArchiveTitle(): string { return $this->archiveTitle; }
        public function getArchiveSlug(): string { return $this->archiveSlug; }
        public function render(): void { include __DIR__ . '/../usr/themes/InfinityTime/index.php'; }
    }
    if (($argv[1] ?? '') === '--api-fixture') {
        $db = \Typecho\Db::$instance = new \Typecho\Db();
        $db->albums = [album(7), album(9, ['password' => 'hidden']), album(8, ['status' => 'draft'])];
        $db->images = [photo(91), photo(92, ['cid' => 9]), photo(93, ['cid' => 8])];
        $_GET = ['infinitytime_api' => 'gallery'];
        (new GalleryFixture())->render();
        exit;
    }
    $req = pp_gallery_request(['q' => ['bad'], 'kind' => 'invalid', 'month' => '2026-13', 'photo' => '../private', 'cursor' => '1 or 1=1', 'limit' => 999]);
    check($req['q'] === '' && $req['kind'] === 'all' && $req['month'] === '' && $req['cursor'] === '' && $req['photo'] === '' && $req['limit'] === 12, '无效请求参数被规范化且数量受限');
    check(pp_gallery_request(['limit' => 0])['limit'] === 1, '分页数量有下限');
    check(pp_gallery_month(['datetime' => '2026:02:30 12:00:00'], strtotime('2026-03-02 UTC')) === '2026-03', '无效 EXIF 日期回退到 UTC 上传月份');
    check(pp_gallery_month(['datetime' => '2024-02-29'], 0) === '2024-02', '有效闰日归入实际拍摄月份');
    check(pp_gallery_month([], 0) === '', '没有日期时保留未知状态');
    $safe = pp_gallery_photo(photo(91), 7, 0);
    check($safe['id'] === 'p-91' && $safe['month'] === '2026-04' && $safe['pano'], '仓库 ID、月份及全景标记来自实际照片');
    check(!preg_match('/gps|private|original|secret/i', json_encode($safe)), '公开照片白名单排除私有记录和 EXIF 字段');
    check(pp_gallery_media_url('javascript:alert(1)') === '' && pp_gallery_media_url('//evil.test/p.jpg') === '' && pp_gallery_media_url('/uploads/original/a.jpg') === '' && pp_gallery_media_url('/uploads/%2e%2e/a.jpg') === '', '拒绝危险 URL 协议、原图及路径穿越');
    check(pp_gallery_media_url('https://cdn.example.com/a.webp') !== '', '接受公开 HTTPS 派生图');
    check(pp_gallery_tags('城市, 夜景，城市', ['旅行', '夜景']) === ['城市', '夜景', '旅行'], '自定义及原生标签合并并去重');
    foreach (['draft', 'waiting', 'private'] as $status) { check(!pp_gallery_visible(album(1, ['status' => $status])), '不公开以下状态：' . $status); }
    check(!pp_gallery_visible(album(1, ['password' => 'locked'])) && !pp_gallery_visible(album(1, ['created' => time() + 1000])), '密码及未到发布时间的相册不公开');
    $fields = ['img' => "/a.webp\n\n/b.webp", 'titles' => '["A","must not shift","B"]', 'exif' => '[{"datetime":"2026:01:01"},null,{}]'];
    $legacy = pp_gallery_legacy_photos(album(7), $fields, 0, 60);
    check(count($legacy['photos']) === 2 && $legacy['photos'][1]['title'] === 'B', '历史空行不影响元数据对应关系');
    $reordered = pp_gallery_legacy_photos(album(7), ['img' => "/b.webp\n/a.webp"], 0, 60);
    check($legacy['photos'][0]['id'] === $reordered['photos'][1]['id'] && $legacy['photos'][1]['id'] === $reordered['photos'][0]['id'], '历史照片 ID 不随排序变化');
    check($legacy['photos'][1]['month'] === '2026-08', '历史照片无 EXIF 时使用相册创建时间的 UTC 月份');
    check(count(pp_gallery_legacy_photos(album(7), $fields, 0, 1, $legacy['photos'][1]['id'])['photos']) === 1, '历史照片深链接可定位首批之外的照片');
    $publicAlbum = ['title' => '京都旅行', 'description' => '旅途日记', 'tags' => ['城市'], 'category_slugs' => ['travel']];
    $photoA = pp_gallery_photo(photo(1, ['title' => 'Sunset', 'exif' => '{"datetime":"2026:01:02"}']), 7, 0);
    $photoB = pp_gallery_photo(photo(2, ['title' => 'Morning', 'height' => 1500, 'exif' => '{"datetime":"2026:02:02"}']), 7, 0);
    check(count(pp_gallery_filter($publicAlbum, [$photoA, $photoB], pp_gallery_request(['q' => '京都']))) === 2, '相册标题命中后仍需符合其他照片筛选条件');
    check(count(pp_gallery_filter($publicAlbum, [$photoA, $photoB], pp_gallery_request(['q' => 'sunSET']))) === 1, '照片标题搜索不区分大小写');
    check(count(pp_gallery_filter($publicAlbum, [$photoA, $photoB], pp_gallery_request(['q' => '日落']))) === 2, '照片描述搜索支持 Unicode');
    check(pp_gallery_filter($publicAlbum, [$photoA, $photoB], pp_gallery_request(['kind' => 'pano', 'month' => '2026-02'])) === [], '全景与月份必须命中同一照片，不能分别命中相册中的不同照片');
    check(count(pp_gallery_filter($publicAlbum, [$photoA, $photoB], pp_gallery_request(['tag' => '城市', 'category' => 'travel', 'month' => '2026-01']))) === 1, '标签、分类及拍摄月份取交集');
    check(pp_gallery_filter($publicAlbum, [$photoA], pp_gallery_request(['tag' => '城'])) === [], '标签按完整名称匹配');

    $db = \Typecho\Db::$instance = new \Typecho\Db();
    $db->albums = [album(7), album(8, ['status' => 'draft']), album(9, ['password' => 'hidden']), album(10, ['created' => time() + 20000])];
    $db->images = [photo(1), photo(2, ['cid' => 9])];
    $db->fields = [['cid' => 7, 'name' => 'tags', 'str_value' => '城市'], ['cid' => 7, 'name' => 'secret', 'str_value' => 'never public']];
    $db->metas = [['cid' => 7, 'type' => 'tag', 'name' => '旅行', 'slug' => 'travel'], ['cid' => 7, 'type' => 'category', 'name' => '摄影', 'slug' => 'photo']];
    $page = pp_gallery_read(pp_gallery_request([]), 'https://example.test/sub/');
    check(count($page['albums']) === 1 && $page['albums'][0]['id'] === 7, '数据库页面排除草稿、密码及未来发布的相册');
    check($page['albums'][0]['tags'] === ['城市', '旅行'], '数据库页面合并两种标签来源');
    check(!preg_match('/gps|private|original|secret|password|draft|hidden/i', json_encode($page)), '公开页面和 API 结构不包含私有字段');
    check($page['albums'][0]['photos'][0]['url_link'] === 'https://example.test/sub/?album=7&photo=p-1', '稳定照片链接支持子目录安装');
    foreach ($db->queries as $query) {
        if ($query->table === 'test_contents') { check($query->maximum === 17 && isset($query->conditions['status = ?'], $query->conditions['(password IS NULL OR password = ?)']), '图集查询有上限并执行发布状态和密码过滤'); }
        if ($query->table === 'test_fields') { check($query->conditions['name IN (?,?,?)'] === ['tags', 'device', 'location'], '现代仓库相册阶段仅读三个小型字段'); }
        if ($query->table === 'test_infinitytime_images') { check($query->maximum <= 61 && !preg_match('/(?:^|\.)(?:\*|original|gps_lat|gps_lng|hash)$/m', implode("\n", $query->columns)), '照片查询有上限且不读取私有列'); }
    }
    // 直接定位仓库中首批以外的照片。
    $db->images = []; for ($i = 1; $i <= 150; $i++) { $db->images[] = photo($i); }
    $targetPage = pp_gallery_read(pp_gallery_request(['album' => 7, 'photo' => 'p-149']), 'https://example.test/');
    check(array_column($targetPage['albums'][0]['photos'], 'id') === ['p-149'], '仓库深链接可定位首批之外的照片');
    $cursor = ''; $all = []; $rounds = 0;
    do {
        $page = pp_gallery_read(pp_gallery_request(['cursor' => $cursor, 'limit' => 1]), 'https://example.test/');
        foreach ($page['albums'] as $a) { $all = array_merge($all, array_column($a['photos'], 'id')); }
        $cursor = $page['next_cursor']; $rounds++;
    } while ($cursor !== null && $rounds < 10);
    check(count($all) === 150 && count(array_unique($all)) === 150 && $rounds === 3, '大图集分页不遗漏、不重复照片');

    // 旧表没有变体列且尚未同步 photo_ids 时，仍按 URL 对应而非位置。
    $db->modernSchema = false;
    $db->fields = [
        ['cid' => 7, 'name' => 'img', 'str_value' => "/uploads/full/2.webp\n/uploads/full/1.webp"],
        ['cid' => 7, 'name' => 'variants', 'str_value' => '[{"w":2000,"webp":["/uploads/full/2.webp","/uploads/full/2-mid.webp"]},{"w":2000,"webp":["/uploads/full/1.webp","/uploads/full/1-mid.webp"]}]'],
    ];
    $variantPage = pp_gallery_read(pp_gallery_request(['album' => 7, 'photo' => 'p-1']), 'https://example.test/');
    check($variantPage['albums'][0]['photos'][0]['variants']['webp'][1] === '/uploads/full/1-mid.webp', '旧图集变体按 URL 关联且无需先执行重建');
    $db->modernSchema = true;
    // 插件表存在但没有对应记录时，历史字段相册仍可继续分页。
    $db->images = [];
    $urls = []; for ($i = 1; $i <= 130; $i++) { $urls[] = '/legacy/' . $i . '.webp'; }
    $db->fields = [['cid' => 7, 'name' => 'img', 'str_value' => implode("\n", $urls)]];
    $second = pp_gallery_read(pp_gallery_request(['cursor' => '7.60', 'limit' => 1]), 'https://example.test/');
    check(count($second['albums'][0]['photos']) === 60 && $second['albums'][0]['photos'][0]['url'] === '/legacy/61.webp', '插件表存在时字段相册仍可进入第二批');

    // 现代仓库直接投影变体，忽略陈旧聚合字段；缓存版本与 URL/EXIF 白名单仍生效。
    $db->images = [photo(501, ['full' => '/fresh.webp', 'mid' => '/fresh-mid.webp', 'avif' => '/fresh.avif', 'mid_avif' => '/fresh-mid.avif',
        'exif' => json_encode(['make' => 'Camera', '_infinity_media_version' => str_repeat('a', 32), 'gps' => ['lat' => 1]])])];
    $db->fields = [['cid' => 7, 'name' => 'img', 'str_value' => '/stale.webp'], ['cid' => 7, 'name' => 'variants', 'str_value' => str_repeat('stale', 10000)]];
    $db->queries = [];
    $fresh = pp_gallery_read(pp_gallery_request(['album' => 7]), 'https://example.test/');
    $freshPhoto = $fresh['albums'][0]['photos'][0];
    $versionSuffix = '?itv=' . str_repeat('a', 32);
    check($freshPhoto['variants'] === ['w' => 2000, 'webp' => ['/fresh.webp' . $versionSuffix, '/fresh-mid.webp' . $versionSuffix],
        'avif' => ['/fresh.avif' . $versionSuffix, '/fresh-mid.avif' . $versionSuffix]], '现代投影保留完整 WebP/AVIF 变体及缓存版本');
    check($freshPhoto['exif'] === ['make' => 'Camera'] && count($db->queries) === 4, '现代查询不解析历史聚合字段且仍只公开 EXIF 白名单');
    $db->images[0]['mid'] = '/uploads/original/private.jpg'; $db->images[0]['avif'] = 'javascript:alert(1)';
    $unsafe = pp_gallery_read(pp_gallery_request(['album' => 7]), 'https://example.test/');
    check(count($unsafe['albums'][0]['photos'][0]['variants']['webp']) === 1 && !preg_match('/private|javascript|gps/', json_encode($unsafe)), '直接投影同样拒绝变体中的原图和脚本 URL');

    // 老表按稳定 ID 取变体，即便字段与图片顺序不同；同一请求只探测一次缺列并读取一次旧字段。
    $db->modernSchema = false; $db->images = []; $urls = []; $variants = []; $ids = [];
    for ($i = 1; $i <= 130; $i++) { $db->images[] = photo($i); $urls[] = '/different/' . $i . '.webp'; $ids[] = $i; $variants[] = ['w' => 2000, 'webp' => ['/stable/' . $i . '.webp']]; }
    $db->fields = [['cid' => 7, 'name' => 'img', 'str_value' => implode("\n", array_reverse($urls))],
        ['cid' => 7, 'name' => 'photo_ids', 'str_value' => json_encode($ids)], ['cid' => 7, 'name' => 'variants', 'str_value' => json_encode($variants)]];
    $db->queries = [];
    $oldSchema = pp_gallery_read(pp_gallery_request(['album' => 7]), 'https://example.test/');
    check(count($oldSchema['albums']) === 3 && $oldSchema['albums'][1]['photos'][0]['variants']['webp'] === ['/stable/61.webp'], '旧表多分片仍按稳定 ID 关联变体');
    $modernAttempts = array_filter($db->queries, static function ($q) { return $q->table === 'test_infinitytime_images' && in_array('test_infinitytime_images.mid', $q->columns, true); });
    $legacyReads = array_filter($db->queries, static function ($q) { return $q->table === 'test_fields' && isset($q->conditions['cid = ?']); });
    check(count($modernAttempts) === 1 && count($legacyReads) === 1, '旧表缺列探测及聚合字段读取均限制为每请求每相册一次');

    // 无插件安装继续读字段相册，深链接和多分片不需要迁移。
    $db->repositoryAvailable = false; $db->fields = [['cid' => 7, 'name' => 'img', 'str_value' => implode("\n", $urls)]];
    $db->queries = [];
    $noPlugin = pp_gallery_read(pp_gallery_request(['album' => 7]), 'https://example.test/');
    check(count($noPlugin['albums']) === 3 && count($noPlugin['albums'][2]['photos']) === 10, '无插件表仍完整分页读取历史字段');
    $legacyReads = array_filter($db->queries, static function ($q) { return $q->table === 'test_fields' && isset($q->conditions['cid = ?']); });
    check(count($legacyReads) === 1, '字段相册多个分片复用请求内解析和读取');
    $lastLegacy = $noPlugin['albums'][2]['photos'][9]['id'];
    $deepLegacy = pp_gallery_read(pp_gallery_request(['album' => 7, 'photo' => $lastLegacy]), 'https://example.test/');
    check(array_column($deepLegacy['albums'][0]['photos'], 'id') === [$lastLegacy], '无插件表仍可打开首批之外的历史照片深链接');
    $db->queries = [];
    $privateState = [];
    check(pp_gallery_db_photos($db, album(7, ['password' => 'secret']), 0, 60, '', $privateState)['photos'] === [] && !$db->queries, '回退读取在查询前仍拒绝密码相册');

    // 可用性状态不跨请求保存；暂时失败不得留下永久回退或泄漏内部错误。
    $db->repositoryAvailable = true; $db->modernSchema = true; $db->images = [photo(91)]; $db->photoFailures = 2;
    $temporaryFailure = pp_gallery_read(pp_gallery_request(['album' => 7, 'limit' => 1]), 'https://example.test/');
    check(!preg_match('/内部|private|secret|gps/', json_encode($temporaryFailure)), '暂时查询失败的公开字段回退不暴露诊断或私有记录');
    $recovered = pp_gallery_read(pp_gallery_request(['album' => 7]), 'https://example.test/');
    check($recovered['albums'][0]['photos'][0]['id'] === 'p-91', '下一请求恢复现代仓库读取，不复用旧失败状态');

    // 稀疏匹配超出扫描额度：空批次不代表已经遍历完毕。
    $db->albums = []; $db->images = []; $db->fields = [];
    for ($i = 40; $i > 0; $i--) { $db->albums[] = album($i); $db->images[] = photo($i, ['cid' => $i, 'title' => $i === 1 ? 'needle' : 'ordinary', 'desc' => '']); }
    $cursor = ''; $found = false; $rounds = 0;
    do {
        $page = pp_gallery_read(pp_gallery_request(['q' => 'needle', 'cursor' => $cursor]), 'https://example.test/');
        if ($rounds === 0) check(!$page['albums'] && $page['scan_limited'] && $page['next_url'] !== null, '稀疏搜索的空批次保留继续搜索游标');
        if ($page['albums']) { $found = $page['albums'][0]['id'] === 1; }
        check($page['scanned_chunks'] <= 16, '每次请求的扫描额度有上限');
        $cursor = $page['next_cursor']; $rounds++;
    } while ($cursor !== null && $rounds < 10);
    check($found && $rounds === 3, '前两个扫描窗口之后的稀疏结果仍可发现');
    $groups = pp_gallery_groups([['id' => 7, 'photos' => [$photoA, $photoB]]]);
    check(array_keys($groups) === ['2026-02', '2026-01'], '按实际照片月份分组且页内月份降序');

    // 执行实际 PHP 卡片渲染，覆盖无 JavaScript 照片及属性转义。
    $album = $targetPage['albums'][0];
    $album['title'] = "'\"><script>alert(1)</script>";
    $album['photos'][0]['title'] = "'\"><script>alert(2)</script>";
    $__galleryBase = 'https://example.test/'; $__galleryRequest = pp_gallery_request([]); $month = '2026-04';
    ob_start(); include __DIR__ . '/../usr/themes/InfinityTime/lib/gallery-card.php'; $html = ob_get_clean();
    check(!(strpos($html, '<script>') !== false) && (strpos($html, 'data-photo-ids=') !== false) && (strpos($html, 'data-cid="7"') !== false), '渲染器转义存储文本并提供稳定数据接口');
    check((strpos($html, '<noscript>') !== false) && (strpos($html, 'class="gallery-photo-list"') !== false) && (strpos($html, '/uploads/full/149.webp') !== false), '无 JavaScript 渲染包含可直接打开的照片链接');
    // 执行完整 index.php，而不只测可注入的数据函数或片段模板。
    $db->albums = [album(7, ['title' => '城市风景']), album(6, ['title' => '更早的照片'])];
    $db->images = [photo(91), photo(92, ['cid' => 6])];
    $db->fields = [['cid' => 7, 'name' => 'tags', 'str_value' => '城市'], ['cid' => 6, 'name' => 'tags', 'str_value' => '城市']];
    $db->metas = [];
    $_GET = ['tag' => '城市', 'limit' => '1'];
    ob_start(); (new GalleryFixture())->render(); $fullHtml = ob_get_clean();
    check((strpos($fullHtml, 'id="gallery-search"') !== false) && (strpos($fullHtml, 'type="month"') !== false), '完整首页包含可直接提交的搜索和月份表单');
    check((strpos($fullHtml, 'rel="next"') !== false) && (strpos($fullHtml, 'cursor=6.0') !== false) && (strpos($fullHtml, 'tag=%E5%9F%8E%E5%B8%82') !== false), '完整首页无需 JS 的分页链接保留同一筛选条件');
    check((strpos($fullHtml, 'data-photo-ids=') !== false) && (strpos($fullHtml, 'gallery-navigation.js') !== false) && (strpos($fullHtml, 'gallery-photo-list') !== false), '完整首页输出稳定 ID、历史导航和无 JS 照片');
    check(!preg_match('/gps_lat|GPSLatitude|private\/original|secret/', $fullHtml), '完整首页 HTML 不包含私有图片字段');
    $_GET = [];
    ob_start(); (new GalleryFixture('tag', '城市', 'city'))->render(); $nativeTagHtml = ob_get_clean();
    check((strpos($nativeTagHtml, 'name="tag" maxlength="100" value="城市"') !== false), '原生标签归档进入同一标签筛选模型');
    $deletedCursor = pp_gallery_read(pp_gallery_request(['cursor' => '8.60', 'limit' => 1]), 'https://example.test/');
    check($deletedCursor['albums'][0]['id'] === 7 && count($deletedCursor['albums'][0]['photos']) === 1, '上一页相册删除后不会把旧照片偏移误用到下一相册');
    if (function_exists('shell_exec') && !extension_loaded('wasm_memory_storage')) {
        $apiCode = '$argv = ["fixture", "--api-fixture"]; require ' . var_export(__FILE__, true) . ';';
        $apiOutput = shell_exec(escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg($apiCode));
        $api = json_decode((string)$apiOutput, true);
        check(is_array($api) && $api['ok'] === true && count($api['albums']) === 1, '实际 index.php API 路径只输出可解析的公开 JSON');
        check($api['albums'][0]['photos'][0]['id'] === 'p-91' && !preg_match('/gps|private|original|secret|password|draft|hidden/i', (string)$apiOutput), '实际 API 不泄漏草稿、密码相册或私有字段');
    }
    if (extension_loaded('wasm_memory_storage')) { echo "PHP-WASM 不传递子进程参数；API 夹具须用独立进程运行，原生 CI 仍自动执行该检查。\n"; }
    echo "公开图集：$checks 项检查通过\n";

}
