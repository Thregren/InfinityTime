<?php
/** 公开图集只读模型：不执行迁移，不使用登录用户的权限，也不直接序列化私有记录。 */

const PP_GALLERY_CHUNK = 60;
const PP_GALLERY_SCAN_CHUNKS = 16;
const PP_GALLERY_FIELD_NAMES = ['img', 'thumb', 'exif', 'addresses', 'titles', 'descs', 'panos', 'dims', 'variants', 'photo_ids', 'months', 'tags', 'device', 'location'];
const PP_GALLERY_ALBUM_FIELD_NAMES = ['tags', 'device', 'location'];

function pp_gallery_text($value, int $limit = 2000): string
{
    if (!is_scalar($value)) { return ''; }
    $value = trim(html_entity_decode(strip_tags((string)$value), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    return function_exists('mb_substr') ? mb_substr($value, 0, $limit, 'UTF-8') : substr($value, 0, $limit);
}

function pp_gallery_request(array $input): array
{
    $str = static function (string $key) use ($input): string { return is_scalar($input[$key] ?? null) ? trim((string)$input[$key]) : ''; };
    $month = $str('month');
    $cursor = $str('cursor');
    $photo = $str('photo');
    return [
        'q' => pp_gallery_text($str('q'), 120),
        'kind' => $str('kind') === 'pano' ? 'pano' : 'all',
        'month' => preg_match('/^[1-9]\d{3}-(0[1-9]|1[0-2])$/D', $month) ? $month : '',
        'tag' => pp_gallery_text($str('tag'), 100),
        'category' => pp_gallery_text($str('category'), 100),
        'album' => ctype_digit($str('album')) ? min(2147483647, (int)$str('album')) : 0,
        'photo' => preg_match('/^(?:p-[1-9]\d{0,18}|l-[a-f0-9]{20})$/D', $photo) ? $photo : '',
        'cursor' => preg_match('/^[1-9]\d{0,9}\.\d{1,9}$/D', $cursor) ? $cursor : '',
        'limit' => max(1, min(12, ctype_digit($str('limit')) ? (int)$str('limit') : 12)),
    ];
}

function pp_gallery_url(string $base, array $request = [], array $changes = []): string
{
    $request = array_merge($request, $changes);
    $out = [];
    foreach (['q', 'kind', 'month', 'tag', 'category', 'album', 'photo', 'cursor', 'limit', 'infinitytime_api'] as $key) {
        $value = $request[$key] ?? '';
        if ($value === '' || $value === 0 || ($key === 'kind' && $value === 'all') || ($key === 'limit' && $value === 12)) { continue; }
        $out[$key] = $value;
    }
    return rtrim($base, '/') . '/' . ($out ? '?' . http_build_query($out, '', '&', PHP_QUERY_RFC3986) : '');
}

function pp_gallery_json_array($value): array
{
    if (is_array($value)) { return $value; }
    $result = is_string($value) ? json_decode($value, true) : null;
    return is_array($result) ? $result : [];
}

/** 只接受公开派生图 URL；拒绝脚本、data URL、原图目录及路径穿越。 */
function pp_gallery_media_url($value): string
{
    if (!is_string($value)) { return ''; }
    $url = trim($value);
    if ($url === '' || strlen($url) > 4096 || preg_match('/[\x00-\x20\x7f\\\\]/', $url)) { return ''; }
    if (!preg_match('#^(?:https?://[^/]+/|/(?!/))#i', $url)) { return ''; }
    $path = rawurldecode((string)(parse_url($url, PHP_URL_PATH) ?? ''));
    if (preg_match('#(?:^|/)(?:original|\.\.?)(?:/|$)#i', $path)) { return ''; }
    return $url;
}

/** 显式白名单同时移除历史 GPS 字段、数组、文件名及厂商私有备注。 */
function pp_gallery_exif($input): array
{
    $input = pp_gallery_json_array($input);
    $out = [];
    foreach (['make', 'model', 'lens', 'iso', 'fnumber', 'exposure', 'focal', 'focal35', 'flash', 'datetime'] as $key) {
        if (isset($input[$key]) && is_scalar($input[$key])) {
            $value = pp_gallery_text($input[$key], 160);
            if ($value !== '') { $out[$key] = $value; }
        }
    }
    return $out;
}

/** 拍摄月份保留原日历值，不进行时区换算；时间戳回退统一使用 UTC。 */
function pp_gallery_month(array $exif, int $created): string
{
    if (preg_match('/^(\d{4})[:-](\d{2})[:-](\d{2})(?:[ T]|$)/', (string)($exif['datetime'] ?? ''), $m)
        && checkdate((int)$m[2], (int)$m[3], (int)$m[1])) {
        return $m[1] . '-' . $m[2];
    }
    return $created > 0 ? gmdate('Y-m', $created) : '';
}

function pp_gallery_tags($custom, array $native = []): array
{
    $tags = array_merge(preg_split('/[,，\r\n]+/u', is_string($custom) ? $custom : '') ?: [], $native);
    $out = [];
    foreach ($tags as $tag) {
        $tag = pp_gallery_text($tag, 100);
        if ($tag !== '' && !in_array($tag, $out, true)) { $out[] = $tag; }
    }
    return $out;
}

function pp_gallery_visible(array $album, ?int $now = null): bool
{
    return ($album['type'] ?? '') === 'post' && ($album['status'] ?? '') === 'publish'
        && ($album['password'] ?? '') === '' && (int)($album['created'] ?? 0) <= ($now ?? time());
}

function pp_gallery_variant($input): array
{
    $input = is_array($input) ? $input : [];
    $out = ['w' => max(0, (int)($input['w'] ?? 0)), 'webp' => [], 'avif' => []];
    foreach (['webp', 'avif'] as $format) {
        foreach (array_slice(is_array($input[$format] ?? null) ? $input[$format] : [], 0, 2) as $url) {
            $url = pp_gallery_media_url($url);
            if ($url !== '') { $out[$format][] = $url; }
        }
    }
    return $out;
}

/** 只改变公开 URL，数据库文件路径保持不变，删除和重建仍定位原文件。 */
function pp_gallery_version_url(string $url, array $exif): string
{
    $version = $exif['_infinity_media_version'] ?? '';
    if ($url === '' || !is_string($version) || !preg_match('/^[a-f0-9]{32}$/D', $version)) { return $url; }
    $fragment = explode('#', $url, 2);
    $parts = explode('?', $fragment[0], 2);
    $query = array_filter(explode('&', $parts[1] ?? ''), static function ($part) {
        return $part !== '' && rawurldecode(explode('=', $part, 2)[0]) !== 'itv';
    });
    $query[] = 'itv=' . $version;
    return $parts[0] . '?' . implode('&', $query) . (isset($fragment[1]) ? '#' . $fragment[1] : '');
}

/** 仓库照片和仅有自定义字段的历史照片使用相同公开结构。 */
function pp_gallery_photo(array $row, int $cid, int $albumCreated, bool $legacy = false): ?array
{
    $url = pp_gallery_media_url($row['full'] ?? '');
    if ($url === '') { return null; }
    $exif = pp_gallery_exif($row['exif'] ?? []);
    $width = max(0, (int)($row['width'] ?? 0));
    $height = max(0, (int)($row['height'] ?? 0));
    $id = (int)($row['id'] ?? 0) > 0 ? 'p-' . (int)$row['id'] : 'l-' . substr(hash('sha256', $cid . "\n" . $url), 0, 20);
    $month = pp_gallery_month($exif, (int)($row['created'] ?? 0) ?: $albumCreated);
    if ($legacy && preg_match('/^[1-9]\d{3}-(0[1-9]|1[0-2])$/D', (string)($row['month'] ?? ''))) { $month = $row['month']; }
    $revision = !$legacy ? pp_gallery_json_array($row['exif'] ?? []) : [];
    $variants = pp_gallery_variant($row['variants'] ?? []);
    foreach (['webp', 'avif'] as $format) {
        $variants[$format] = array_map(static function ($value) use ($revision) { return pp_gallery_version_url($value, $revision); }, $variants[$format]);
    }
    return [
        'id' => $id, 'url' => pp_gallery_version_url($url, $revision), 'preview' => pp_gallery_version_url(pp_gallery_media_url($row['thumb'] ?? '') ?: $url, $revision),
        'title' => pp_gallery_text($row['title'] ?? '', 300), 'description' => pp_gallery_text($row['desc'] ?? ''),
        'address' => pp_gallery_text($row['address'] ?? '', 300), 'exif' => $exif,
        'pano' => ($width > 0 && $height > 0) ? ($width / $height >= 1.98 && $width / $height <= 2.02) : !empty($row['pano']),
        'width' => $width, 'height' => $height, 'month' => $month,
        'variants' => $variants,
    ];
}

function pp_gallery_legacy_source(array $fields): array
{
    // 保留原始索引及空行，避免元数据错位到其他照片。
    $urls = preg_split('/\r?\n/', (string)($fields['img'] ?? '')) ?: [];
    $thumbs = preg_split('/\r?\n/', (string)($fields['thumb'] ?? '')) ?: [];
    $lists = [];
    foreach (['exif', 'addresses', 'titles', 'descs', 'panos', 'dims', 'variants', 'photo_ids', 'months'] as $name) { $lists[$name] = pp_gallery_json_array($fields[$name] ?? ''); }
    return ['urls' => $urls, 'thumbs' => $thumbs, 'lists' => $lists];
}

function pp_gallery_legacy_photos(array $album, array $fields, int $offset, int $take, string $target = '', ?array $source = null): array
{
    $source = $source ?? pp_gallery_legacy_source($fields);
    $urls = $source['urls']; $thumbs = $source['thumbs']; $lists = $source['lists'];
    $out = [];
    $end = min(count($urls), $offset + $take);
    // 历史照片的稳定哈希支持直接打开首批以外的照片。
    if ($target !== '') { $offset = 0; $end = count($urls); }
    for ($i = $offset; $i < $end; $i++) {
        $dims = explode('x', is_scalar($lists['dims'][$i] ?? null) ? (string)$lists['dims'][$i] : '');
        $row = ['full' => trim($urls[$i]), 'thumb' => $thumbs[$i] ?? '', 'exif' => $lists['exif'][$i] ?? [],
            'id' => $lists['photo_ids'][$i] ?? 0, 'month' => $lists['months'][$i] ?? '',
            'title' => $lists['titles'][$i] ?? '', 'desc' => $lists['descs'][$i] ?? '', 'address' => $lists['addresses'][$i] ?? '',
            'width' => $dims[0] ?? 0, 'height' => $dims[1] ?? 0, 'pano' => $lists['panos'][$i] ?? 0, 'variants' => $lists['variants'][$i] ?? []];
        $photo = pp_gallery_photo($row, (int)$album['cid'], (int)$album['created'], true);
        if ($photo && ($target === '' || $photo['id'] === $target)) { $out[] = $photo; }
        if ($target !== '' && $out) { break; }
    }
    return ['photos' => $out, 'has_more' => $target === '' && $end < count($urls), 'consumed' => $end - $offset];
}

function pp_gallery_contains(string $haystack, string $needle): bool
{
    return $needle === '' || (function_exists('mb_stripos') ? mb_stripos($haystack, $needle, 0, 'UTF-8') !== false : stripos($haystack, $needle) !== false);
}

function pp_gallery_filter(array $album, array $photos, array $request): array
{
    if ($request['tag'] !== '' && !in_array($request['tag'], $album['tags'], true)) { return []; }
    if ($request['category'] !== '' && !in_array($request['category'], $album['category_slugs'], true)) { return []; }
    $albumMatch = pp_gallery_contains($album['title'] . "\n" . $album['description'], $request['q']);
    return array_values(array_filter($photos, static function (array $photo) use ($albumMatch, $request): bool {
        return ($request['photo'] === '' || $photo['id'] === $request['photo'])
            && ($request['kind'] !== 'pano' || $photo['pano'])
            && ($request['month'] === '' || $photo['month'] === $request['month'])
            && ($albumMatch || pp_gallery_contains($photo['title'] . "\n" . $photo['description'], $request['q']));
    }));
}

/**
 * 固定图集批次和照片分片，为查询及响应设置上限。
 * 游标为包含当前相册的 CID 加照片偏移；扫描额度用尽时仍返回后续游标。
 * 可注入读取函数，便于独立测试筛选和分页语义。
 */
function pp_gallery_page(array $request, callable $loadAlbums, callable $loadPhotos, string $base): array
{
    $parts = explode('.', $request['cursor']);
    $upper = $request['cursor'] !== '' ? (int)$parts[0] : 0;
    $offset = $request['cursor'] !== '' ? (int)$parts[1] : 0;
    $result = ['albums' => [], 'next_cursor' => null, 'scan_limited' => false, 'scanned_chunks' => 0];
    $seen = [];
    $rows = $loadAlbums($upper, PP_GALLERY_SCAN_CHUNKS + 1, $request);
    foreach ($rows as $ri => $raw) {
        if ($ri >= PP_GALLERY_SCAN_CHUNKS) { $result['next_cursor'] = (int)$raw['cid'] . '.0'; break; }
        $cid = (int)$raw['cid'];
        $start = $ri === 0 && ($upper === 0 || $cid === $upper) ? $offset : 0;
        if (!pp_gallery_visible($raw)) { continue; }
        $album = [
            'id' => $cid, 'title' => pp_gallery_text($raw['title'] ?? '', 300),
            'description' => pp_gallery_text($raw['text'] ?? ''),
            'tags' => pp_gallery_tags($raw['fields']['tags'] ?? '', $raw['native_tags'] ?? []),
            'category_slugs' => $raw['category_slugs'] ?? [], 'categories' => $raw['categories'] ?? [],
            'device' => pp_gallery_text($raw['fields']['device'] ?? '', 200),
            'location' => pp_gallery_text($raw['fields']['location'] ?? '', 300),
            'url' => pp_gallery_url($base, ['album' => $cid]), 'photos' => [],
        ];
        do {
            $chunk = $loadPhotos($raw, $start, PP_GALLERY_CHUNK, $request['photo']);
            $result['scanned_chunks']++;
            $filtered = pp_gallery_filter($album, $chunk['photos'], $request);
            foreach ($filtered as $photo) {
                if (isset($seen[$cid . '/' . $photo['id']])) { continue; }
                $seen[$cid . '/' . $photo['id']] = true;
                $photo['url_link'] = pp_gallery_url($base, ['album' => $cid, 'photo' => $photo['id']]);
                $album['photos'][] = $photo;
            }
            $start += $chunk['consumed'];
            $hasMore = $chunk['has_more'] && $chunk['consumed'] > 0;
            $nextRow = $rows[$ri + 1] ?? null;
            $next = $hasMore ? $cid . '.' . $start : ($nextRow ? (int)$nextRow['cid'] . '.0' : null);
            if ($album['photos']) {
                $result['albums'][] = $album;
                $album['photos'] = [];
            }
            if (count($result['albums']) >= $request['limit'] || $result['scanned_chunks'] >= PP_GALLERY_SCAN_CHUNKS) {
                $result['next_cursor'] = $next;
                $result['scan_limited'] = $next !== null && $result['scanned_chunks'] >= PP_GALLERY_SCAN_CHUNKS;
                break 2;
            }
        } while ($hasMore);
    }
    $result['next_url'] = $result['next_cursor'] !== null ? pp_gallery_url($base, $request, ['cursor' => $result['next_cursor']]) : null;
    return $result;
}

/** 图集阶段只读三个小型相册字段；大相册的旧聚合字段在回退时按需读取。 */
function pp_gallery_db_albums($db, int $upper, int $take, array $request): array
{
    $prefix = $db->getPrefix();
    $query = $db->select('cid', 'title', 'text', 'created', 'status', 'type', 'password')->from($prefix . 'contents')
        ->where('type = ?', 'post')->where('status = ?', 'publish')
        ->where('(password IS NULL OR password = ?)', '')->where('created <= ?', time())
        ->order('cid', \Typecho\Db::SORT_DESC)->limit($take);
    if ($upper > 0) { $query->where('cid <= ?', $upper); }
    if ($request['album'] > 0) { $query->where('cid = ?', $request['album']); }
    $rows = $db->fetchAll($query);
    if (!$rows) { return []; }
    $cids = array_map('intval', array_column($rows, 'cid'));
    $ph = implode(',', array_fill(0, count($cids), '?'));
    $names = implode(',', array_fill(0, count(PP_GALLERY_ALBUM_FIELD_NAMES), '?'));
    $fields = $db->fetchAll($db->select('cid', 'name', 'str_value')->from($prefix . 'fields')
        ->where('cid IN (' . $ph . ')', ...$cids)->where('name IN (' . $names . ')', ...PP_GALLERY_ALBUM_FIELD_NAMES));
    $metas = $db->fetchAll($db->select($prefix . 'relationships.cid', $prefix . 'metas.name', $prefix . 'metas.slug', $prefix . 'metas.type')
        ->from($prefix . 'relationships')->join($prefix . 'metas', $prefix . 'relationships.mid = ' . $prefix . 'metas.mid')
        ->where($prefix . 'relationships.cid IN (' . $ph . ')', ...$cids)->where($prefix . 'metas.type IN (?, ?)', 'tag', 'category'));
    $byCid = [];
    foreach ($fields as $field) { $byCid[(int)$field['cid']]['fields'][$field['name']] = $field['str_value']; }
    foreach ($metas as $meta) {
        if ($meta['type'] === 'tag') { $byCid[(int)$meta['cid']]['native_tags'][] = pp_gallery_text($meta['name'], 100); }
        else {
            $byCid[(int)$meta['cid']]['category_slugs'][] = pp_gallery_text($meta['slug'], 100);
            $byCid[(int)$meta['cid']]['categories'][] = pp_gallery_text($meta['name'], 100);
        }
    }
    foreach ($rows as &$row) { $row = array_merge($row, $byCid[(int)$row['cid']] ?? []); }
    unset($row);
    return $rows;
}

/** 仅在确需兼容旧字段时读取，缓存限于本次请求的当前相册。 */
function pp_gallery_db_legacy_fields($db, array $album, array &$state): array
{
    $cid = (int)$album['cid'];
    if (($state['legacy_cid'] ?? 0) !== $cid) {
        $names = implode(',', array_fill(0, count(PP_GALLERY_FIELD_NAMES), '?'));
        $rows = $db->fetchAll($db->select('name', 'str_value')->from($db->getPrefix() . 'fields')
            ->where('cid = ?', $cid)->where('name IN (' . $names . ')', ...PP_GALLERY_FIELD_NAMES));
        $fields = $album['fields'] ?? [];
        foreach ($rows as $row) { $fields[$row['name']] = $row['str_value']; }
        $state['legacy_cid'] = $cid;
        $state['legacy_fields'] = $fields;
        unset($state['legacy_source'], $state['variant_ids'], $state['variant_urls']);
    }
    return $state['legacy_fields'];
}

/** 不查询或序列化私有原图、GPS 及哈希列，也不执行旧库迁移。 */
function pp_gallery_db_photos($db, array $album, int $offset, int $take, string $target = '', ?array &$state = null): array
{
    // 独立调用同样检查可见性，数据库暂时失败也不会放宽公开条件。
    if (!pp_gallery_visible($album)) { return ['photos' => [], 'has_more' => false, 'consumed' => 0]; }
    if ($state === null) { $state = []; }
    $columns = ['id', 'cid', 'full', 'thumb', 'width', 'height', 'exif', 'title', 'desc', 'address', 'created'];
    $load = static function (bool $modern) use ($db, $album, $offset, $take, $target, $columns): array {
        $table = $db->getPrefix() . 'infinitytime_images';
        // SQLite 会将未限定的缺失双引号列当作字符串；限定表名才能可靠检测旧表。
        $projection = array_map(static function ($name) use ($table) { return $table . '.' . $name; },
            $modern ? array_merge($columns, ['mid', 'avif', 'mid_avif']) : $columns);
        $query = $db->select(...$projection)
            ->from($table)->where('cid = ?', (int)$album['cid'])
            ->order('sort', \Typecho\Db::SORT_ASC)->order('id', \Typecho\Db::SORT_ASC)->limit($take + 1)->offset($offset);
        if (strpos($target, 'p-') === 0) { $query->where('id = ?', (int)substr($target, 2))->offset(0); }
        // 原生 SQLite/Pgsql 适配器可能先发 warning 再抛异常；兼容探测不向页面输出诊断。
        return @$db->fetchAll($query);
    };
    $rows = [];
    if (($state['repository'] ?? '') !== 'absent') {
        try {
            $modern = ($state['repository'] ?? '') !== 'legacy';
            try { $rows = $load($modern); }
            catch (\Throwable $e) {
                if (!$modern) { throw $e; }
                // 老表可能没有变体列；保留原白名单投影和按照片 ID/URL 匹配的旧字段。
                $modern = false;
                $rows = $load(false);
            }
            $state['repository'] = $modern ? 'modern' : 'legacy';
            $hasRepository = (bool)$rows;
            if (!$rows && ($offset > 0 || $target !== '')) {
                $hasRepository = (bool)@$db->fetchAll($db->select('id')->from($db->getPrefix() . 'infinitytime_images')
                    ->where('cid = ?', (int)$album['cid'])->limit(1));
            }
        } catch (\Throwable $e) {
            // 无插件表的历史安装继续读公开字段；状态只保留至本次请求结束。
            $state['repository'] = 'absent';
            $hasRepository = false;
        }
        if ($hasRepository) {
            if (!$modern) {
                $fields = pp_gallery_db_legacy_fields($db, $album, $state);
                if (!isset($state['variant_ids'])) {
                    $variants = pp_gallery_json_array($fields['variants'] ?? '');
                    $ids = pp_gallery_json_array($fields['photo_ids'] ?? '');
                    $state['variant_ids'] = []; $state['variant_urls'] = [];
                    foreach ($ids as $index => $id) { $state['variant_ids'][(int)$id] = $variants[$index] ?? []; }
                    // 未同步 photo_ids 的旧图集用精确 URL，避免排序变化导致错配。
                    foreach (preg_split('/\r?\n/', (string)($fields['img'] ?? '')) ?: [] as $index => $url) {
                        $url = pp_gallery_media_url(trim($url));
                        if ($url !== '') { $state['variant_urls'][$url] = $variants[$index] ?? []; }
                    }
                }
            }
            $out = [];
            foreach (array_slice($rows, 0, $take) as $row) {
                $row['variants'] = $modern ? ['w' => (int)($row['width'] ?? 0),
                    'webp' => array_values(array_filter([(string)($row['full'] ?? ''), (string)($row['mid'] ?? '')])),
                    'avif' => array_values(array_filter([(string)($row['avif'] ?? ''), (string)($row['mid_avif'] ?? '')]))]
                    : ($state['variant_ids'][(int)$row['id']] ?? $state['variant_urls'][(string)$row['full']] ?? []);
                $photo = pp_gallery_photo($row, (int)$album['cid'], (int)$album['created']);
                if ($photo) { $out[] = $photo; }
            }
            return ['photos' => $out, 'has_more' => $target === '' && count($rows) > $take, 'consumed' => min($take, count($rows))];
        }
    }
    $fields = pp_gallery_db_legacy_fields($db, $album, $state);
    if (!isset($state['legacy_source'])) { $state['legacy_source'] = pp_gallery_legacy_source($fields); }
    return pp_gallery_legacy_photos($album, $fields, $offset, $take, $target, $state['legacy_source']);
}

function pp_gallery_read(array $request, string $base): array
{
    $db = \Typecho\Db::get();
    $state = [];
    return pp_gallery_page($request,
        static function ($upper, $take, $r) use ($db) { return pp_gallery_db_albums($db, $upper, $take, $r); },
        static function ($album, $offset, $take, $target) use ($db, &$state) { return pp_gallery_db_photos($db, $album, $offset, $take, $target, $state); }, $base);
}

function pp_gallery_groups(array $albums): array
{
    $groups = [];
    foreach ($albums as $album) {
        foreach ($album['photos'] as $photo) {
            $month = $photo['month'] ?: 'unknown';
            if (!isset($groups[$month][$album['id']])) { $copy = $album; $copy['photos'] = []; $groups[$month][$album['id']] = $copy; }
            $groups[$month][$album['id']]['photos'][] = $photo;
        }
    }
    krsort($groups, SORT_STRING);
    if (isset($groups['unknown'])) { $unknown = $groups['unknown']; unset($groups['unknown']); $groups['unknown'] = $unknown; }
    return $groups;
}
