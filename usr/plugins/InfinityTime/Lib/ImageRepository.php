<?php
namespace TypechoPlugin\InfinityTime\Lib;

use TypechoPlugin\InfinityTime\Plugin;

/**
 * 图片存储仓库：
 *  - 目录约定（均相对于 upload 根目录，web 可访问）：
 *      original/<y>/<m>/<base>.<ext>   原始上传
 *      full/<y>/<m>/<base>.webp       转换后全尺寸 WebP
 *      thumb/<y>/<m>/<base>.webp      缩略图 WebP
 *  - 负责上传落盘、去重、元数据（EXIF/GPS/地址）入库，以及清理与重建。
 */
class ImageRepository
{
    public const T_ORIGINAL = 'original';
    public const T_FULL     = 'full';
    public const T_THUMB    = 'thumb';

    /** 最近一次入库失败的可读原因（供面板展示）。 */
    public static ?string $lastError = null;

    /** 插件数据表全名（带前缀）。 */
    public static function table(): string
    {
        return \Typecho\Db::get()->getPrefix() . 'infinitytime_images';
    }

    /** 请求级互斥锁覆盖“文件落盘 → 入库”，进程退出自动释放；不删除锁文件。 */
    private static $mediaLock = null;
    public static function lockMedia(): void
    {
        if (is_resource(self::$mediaLock)) {
            return;
        }
        self::ensureDir(self::uploadRoot());
        $handle = @fopen(self::uploadRoot() . '/.infinitytime-media.lock', 'c');
        if (!$handle || !flock($handle, LOCK_EX | LOCK_NB)) {
            if (is_resource($handle)) { fclose($handle); }
            throw new \RuntimeException('图片上传或维护正在进行，请稍后重试');
        }
        self::$mediaLock = $handle;
        register_shutdown_function([self::class, 'unlockMedia']);
    }

    public static function unlockMedia(): void
    {
        if (is_resource(self::$mediaLock)) {
            flock(self::$mediaLock, LOCK_UN);
            fclose(self::$mediaLock);
        }
        self::$mediaLock = null;
    }

    /** 是否 360 全景：等距圆柱即宽高比约 2:1（1.98~2.02），排除 XPAN 等更宽画幅。 */
    public static function isPano(int $w, int $h): bool
    {
        return $h > 0 && $w / $h >= 1.98 && $w / $h <= 2.02;
    }

    /** 上传根目录（文件系统绝对路径）。 */
    public static function uploadRoot(): string
    {
        return rtrim((defined('__TYPECHO_UPLOAD_ROOT_DIR__') ? __TYPECHO_UPLOAD_ROOT_DIR__ : __TYPECHO_ROOT_DIR__ . '/usr/uploads'), '/');
    }

    /** 上传目录对应的站点 URL 路径（如 /usr/uploads）。 */
    public static function uploadWebRoot(): string
    {
        $dir = defined('__TYPECHO_UPLOAD_DIR__')
            ? __TYPECHO_UPLOAD_DIR__
            : (\Utils\Helper::options()->uploadDir ?? '/usr/uploads');
        return '/' . trim((string)$dir, '/');
    }

    /** 根据绝对路径得到站点根相对的 web URL（如 /usr/uploads/...）。 */
    public static function toWeb(string $abs): string
    {
        $abs = str_replace('\\', '/', $abs);
        $uploadRoot = self::uploadRoot();
        if (strpos($abs, $uploadRoot . '/') === 0) {
            return self::uploadWebRoot() . substr($abs, strlen($uploadRoot));
        }
        $root = rtrim(__TYPECHO_ROOT_DIR__, '/');
        if (strpos($abs, $root . '/') === 0) {
            return substr($abs, strlen($root));
        }
        return $abs;
    }

    /** 根据 web 相对路径得到文件系统绝对路径。 */
    public static function toAbs(string $web): string
    {
        $web = '/' . ltrim(str_replace('\\', '/', $web), '/');
        // 防御路径穿越：先剥掉 .. 段，数据库/字段里的路径若被写脏也不能越过上传根/站点根读写外部文件
        $clean = preg_replace('#(?:^|/)\.\.(?=/|$)#', '', $web);
        if (is_string($clean)) {
            $web = $clean;
        }
        $uploadWebRoot = self::uploadWebRoot();
        if ($web === $uploadWebRoot || strpos($web, $uploadWebRoot . '/') === 0) {
            return self::uploadRoot() . substr($web, strlen($uploadWebRoot));
        }
        $root = rtrim(__TYPECHO_ROOT_DIR__, '/');
        return $root . $web;
    }

    /**
     * 处理一个上传文件，返回入库元数据。
     *
     * @param array $file  $_FILES 单文件项（含 tmp_name/name/size/error）
     * @param array $opts  {quality:int,thumb_max:int,keep_original:bool}
     * @return array|null  null 表示处理失败
     */
    public static function ingest(array $file, array $opts = []): ?array
    {
        self::$lastError = null;

        $opts = array_merge([
            'quality' => 82,
            'thumb_max' => 1280,
            'keep_original' => true,
            'max_width' => 0,
            'full_quality' => 82,
            'dirs' => self::defaultDirs(),
        ], $opts);

        if (empty($file['tmp_name']) || !is_uploaded_file($file['tmp_name'])) {
            // 注意：tmp_name 非空但 is_uploaded_file=false 时也必须拒绝，避免把任意本地路径当上传图处理
            self::$lastError = empty($file['tmp_name'])
                ? '没有收到图片文件（tmp_name 为空）'
                : '非法上传：临时文件校验失败';
            return null;
        }
        $src = $file['tmp_name'];
        if (!is_file($src)) {
            self::$lastError = '找不到已上传的临时文件：' . $src;
            return null;
        }

        $ext = strtolower(pathinfo($file['name'] ?? '', PATHINFO_EXTENSION));
        if (!in_array($ext, MediaProcessor::supportedExtensions(), true)) {
            self::$lastError = '不支持的图片格式：' . ($file['name'] ?? '');
            return null;
        }

        try {
            self::lockMedia();
            $hash = hash_file('sha256', $src);

            $y = date('Y');
            $m = date('m');
            $base = self::uniqueName($hash);

            // 站内相对目录（用于入库）与文件系统绝对目录（用于落盘）
            $origWebDir = $opts['dirs'][self::T_ORIGINAL] . '/' . $y . '/' . $m;
            $fullWebDir = $opts['dirs'][self::T_FULL] . '/' . $y . '/' . $m;
            $thumbWebDir = $opts['dirs'][self::T_THUMB] . '/' . $y . '/' . $m;
            $origAbsDir = self::toAbs($origWebDir);
            $fullAbsDir = self::toAbs($fullWebDir);
            $thumbAbsDir = self::toAbs($thumbWebDir);

            // 原始文件（保留）
            $origRel = null;
            if ($opts['keep_original']) {
                self::ensureDir($origAbsDir);
                $origPath = $origAbsDir . '/' . $base . '.' . $ext;
                if (!@copy($src, $origPath)) {
                    self::$lastError = '无法把原图写入 ' . $origAbsDir . '（请检查该目录写权限）';
                    return null;
                }
                $origRel = $origWebDir . '/' . $base . '.' . $ext;
            }

            self::ensureDir($fullAbsDir);
            self::ensureDir($thumbAbsDir);

            $fullPath  = $fullAbsDir  . '/' . $base . '.webp';
            $thumbPath = $thumbAbsDir . '/' . $base . '.webp';

            // 全景（宽高比 1.98～2.02）使用独立宽度；0=保留原尺寸。
            $panoW = (int)($opts['pano_width'] ?? 0);
            $srcInfo = @getimagesize($src);
            if (is_array($srcInfo) && ($srcInfo[0] ?? 0) > 0 && ($srcInfo[1] ?? 0) > 0) {
                $srcW = (int)$srcInfo[0];
                $srcH = (int)$srcInfo[1];
                if (self::isPano($srcW, $srcH)) {
                    $opts['max_width'] = $panoW > 0 ? (int)min($srcW, $panoW) : 0;
                    // 全景用独立质量（pano_quality），比普通全图更高，兼顾清晰度
                    $opts['full_quality'] = (int)($opts['pano_quality'] ?? $opts['full_quality']);
                }
            }

            $result = MediaProcessor::process($src, $fullPath, $thumbPath, $opts['thumb_max'], $opts['quality'], $opts['max_width'], $opts['full_quality']);

            $gps = $result['exif']['gps'] ?? null;
            // GPS 只保留在私有列 gps_lat/gps_lng（后台/未来反向地理编码用），
            // 不写进会被前台 data-exif 原样输出的 exif JSON，避免精确坐标公开。
            unset($result['exif']['gps']);

            return [
                'original' => $origRel,
                'full'     => $fullWebDir . '/' . $base . '.webp',
                'mid'      => !empty($result['mid']) ? self::toWeb((string)$result['mid']) : null,
                'avif'     => !empty($result['avif']) ? self::toWeb((string)$result['avif']) : null,
                'mid_avif' => !empty($result['mid_avif']) ? self::toWeb((string)$result['mid_avif']) : null,
                'thumb'    => $thumbWebDir . '/' . $base . '.webp',
                'width'    => $result['width'],
                'height'   => $result['height'],
                'size'     => $result['size'],
                'mime'     => $result['mime'],
                'hash'     => $hash,
                'exif'     => $result['exif'],
                'gps_lat'  => $gps['lat'] ?? null,
                'gps_lng'  => $gps['lng'] ?? null,
                'address'  => trim((string)($opts['address'] ?? '')),
            ];
        } catch (\Throwable $e) {
            Plugin::log('ingest failed: ' . $e->getMessage());
            self::$lastError = $e->getMessage();
            return null;
        }
    }

    /** 默认目录配置（web 相对路径）。 */
    public static function defaultDirs(): array
    {
        $root = self::uploadWebRoot();
        return [
            self::T_ORIGINAL => $root . '/original',
            self::T_FULL     => $root . '/full',
            self::T_THUMB    => $root . '/thumb',
        ];
    }

    /**
     * 把单张图片元数据写入插件表（绑定到 cid）。
     *
     * @return int 新行 id
     */
    public static function insertRow(int $cid, array $meta, int $sort = 0): int
    {
        self::ensureSchema();
        self::lockMedia();
        $db = \Typecho\Db::get();
        $table = self::table();
        return (int)$db->query($db->insert($table)->rows([
            'cid' => $cid,
            'original' => $meta['original'],
            'full' => $meta['full'],
            'mid' => $meta['mid'] ?? null,
            'avif' => $meta['avif'] ?? null,
            'mid_avif' => $meta['mid_avif'] ?? null,
            'thumb' => $meta['thumb'],
            'width' => $meta['width'],
            'height' => $meta['height'],
            'size' => $meta['size'],
            'sort' => $sort,
            'hash' => $meta['hash'] ?? '',
            'upload_key' => $meta['upload_key'] ?? null,
            'upload_fingerprint' => $meta['upload_fingerprint'] ?? null,
            'exif' => json_encode($meta['exif'] ?? [], JSON_UNESCAPED_UNICODE),
            'gps_lat' => $meta['gps_lat'] ?? null,
            'gps_lng' => $meta['gps_lng'] ?? null,
            'address' => (string)($meta['address'] ?? ''),
            'title' => (string)($meta['title'] ?? ''),
            'desc' => (string)($meta['desc'] ?? ''),
            'created' => time(),
        ]));
    }

    /** 持锁后的变更及引用检查必须读取主库，避免从库延迟造成重复或误删。 */
    private static function fetchRows($db, $query): array
    {
        if (is_resource(self::$mediaLock)) {
            // Typecho 会按 Query 类型重新选择连接池；先编译绑定参数，再显式选择主库。
            $query = $db->query($query->prepare((string)$query), \Typecho\Db::WRITE);
        }
        return $db->fetchAll($query);
    }

    /** 从插件表取某 cid 的图片列表。 */
    public static function rowsFor(int $cid): array
    {
        self::ensureSchema();
        $db = \Typecho\Db::get();
        $query = $db->select()->from(self::table())->where('cid = ?', $cid)->order('sort', \Typecho\Db::SORT_ASC)->order('id', \Typecho\Db::SORT_ASC);
        $rows = self::fetchRows($db, $query);
        return array_map(function ($r) {
            $r['exif'] = json_decode($r['exif'] ?? '{}', true);
            return $r;
        }, $rows);
    }

    /** 一次性取多个图集的图片，按 cid 分组，避免后台图集列表 N+1。 */
    public static function rowsForCids(array $cids): array
    {
        self::ensureSchema();
        $out = [];
        $cids = array_values(array_unique(array_map('intval', $cids)));
        if (!$cids) {
            return $out;
        }
        $db = \Typecho\Db::get();
        $ph = implode(',', array_fill(0, count($cids), '?'));
        $rows = self::fetchRows($db,
            $db->select()->from(self::table())
                ->where('cid IN (' . $ph . ')', ...$cids)
                ->order('sort', \Typecho\Db::SORT_ASC)
                ->order('id', \Typecho\Db::SORT_ASC)
        );
        foreach ($rows as $r) {
            $r['exif'] = json_decode($r['exif'] ?? '{}', true);
            $out[(int)$r['cid']][] = $r;
        }
        return $out;
    }

    /** 运行时确保 title/desc 列存在（兼容旧库升级；每进程一次）。 */
    private static $schemaEnsured = false;
    public static function ensureSchema(): void
    {
        if (self::$schemaEnsured) {
            return;
        }
        // 按 schema 版本补齐缺失列（幂等；版本一致时 Plugin::migrateSchema 直接返回，不跑 DDL）
        try {
            Plugin::migrateSchema();
            self::$schemaEnsured = true;
        } catch (\Throwable $e) {
            Plugin::log('migrateSchema failed: ' . $e->getMessage());
            throw $e;
        }
    }

    /** 更新单张图片的标题/描述/地址。 */
    public static function setImageMeta(int $rowId, string $title, string $desc, string $address): void
    {
        $db = \Typecho\Db::get();
        $db->query($db->update(self::table())->rows([
            'title' => $title,
            'desc' => $desc,
            'address' => $address,
        ])->where('id = ?', $rowId));
    }

    /** 重建后同步实际输出尺寸、大小与响应式变体路径。 */
    public static function updateVariants(int $rowId, array $result): void
    {
        self::lockMedia();
        $db = \Typecho\Db::get();
        $values = [
            'mid' => !empty($result['mid']) ? self::toWeb((string)$result['mid']) : null,
            'avif' => !empty($result['avif']) ? self::toWeb((string)$result['avif']) : null,
            'mid_avif' => !empty($result['mid_avif']) ? self::toWeb((string)$result['mid_avif']) : null,
        ];
        foreach (['width', 'height', 'size'] as $field) {
            if (isset($result[$field])) { $values[$field] = (int)$result[$field]; }
        }
        $db->query($db->update(self::table())->rows($values)->where('id = ?', $rowId));
    }

    /** 按图片行的顺序同步路径与元数据，避免排序/删除后文章字段错位。 */
    public static function syncPostFields(int $cid, bool $clearEmpty = false): void
    {
        $db = \Typecho\Db::get();
        $prefix = $db->getPrefix();
        $rows = self::rowsFor($cid);
        // 历史文章可能仅有自定义字段。无图片行时不覆盖，删除最后一张图则显式清空。
        if (!$rows && !$clearEmpty) {
            return;
        }
        $addresses = [];
        $titles = [];
        $descs = [];
        $panos = [];
        $dims = [];
        $variants = [];
        $exifs = [];
        $photoIds = [];
        $months = [];
        $images = [];
        $thumbs = [];
        foreach ($rows as $r) {
            $photoIds[] = (int)($r['id'] ?? 0);
            $months[] = self::photoMonth(is_array($r['exif'] ?? null) ? $r['exif'] : [], (int)($r['created'] ?? 0));
            $images[] = (string)($r['full'] ?? '');
            $thumbs[] = (string)($r['thumb'] ?? '');
            $addresses[] = (string)($r['address'] ?? '');
            $titles[] = (string)($r['title'] ?? '');
            $descs[] = (string)($r['desc'] ?? '');
            $w = (int)($r['width'] ?? 0);
            $h = (int)($r['height'] ?? 0);
            // 每张图的实际尺寸（缩略图/全图同比例），供首页瀑布流用 aspect-ratio 预占位，
            // 避免图片懒加载后高度突变导致 CSS 多列重新平衡（图片顺序跳变）。
            $dims[] = ($w > 0 && $h > 0) ? ($w . 'x' . $h) : '';
            // 长宽比 1.98～2.02 视为全景，排除 XPAN 等更宽画幅。
            $panos[] = self::isPano($w, $h) ? 1 : 0;
            // 响应式变体：webp=[full, mid]，avif=[full, mid]（缺失的自动过滤）
            $variants[] = [
                'w' => $w,
                'webp' => array_values(array_filter([(string)($r['full'] ?? ''), (string)($r['mid'] ?? '')])),
                'avif' => array_values(array_filter([(string)($r['avif'] ?? ''), (string)($r['mid_avif'] ?? '')])),
            ];
            // 重建文章里的 exif 字段时同样剔除 gps（历史数据也据此清掉）
            $e = is_array($r['exif'] ?? null) ? $r['exif'] : [];
            unset($e['gps']);
            $exifs[] = $e;
        }
        foreach (['img' => $images, 'thumb' => $thumbs] as $name => $paths) {
            $db->query($db->delete($prefix . 'fields')->where('cid = ?', $cid)->where('name = ?', $name));
            // 保留空 img 标记，使删除最后一张图片后的空图集仍可识别。
            $db->query($db->insert($prefix . 'fields')->rows([
                'cid' => $cid, 'name' => $name, 'type' => 'str', 'str_value' => implode("\n", $paths),
            ]));
        }
        $map = ['addresses' => $addresses, 'titles' => $titles, 'descs' => $descs, 'panos' => $panos, 'dims' => $dims, 'variants' => $variants, 'exif' => $exifs, 'photo_ids' => $photoIds, 'months' => $months];
        foreach (['addresses', 'titles', 'descs', 'panos', 'dims', 'variants', 'exif', 'photo_ids', 'months'] as $f) {
            $db->query($db->delete($prefix . 'fields')->where('cid = ?', $cid)->where('name = ?', $f));
            $val = $map[$f];
            // 注意：variants 的元素是数组，不能直接用 array_filter($val, 'strlen')（PHP 8 会对数组调 strlen 报错）
            $nonEmpty = false;
            foreach ($val as $item) {
                if (is_array($item) ? count($item) > 0 : (string)$item !== '') {
                    $nonEmpty = true;
                    break;
                }
            }
            if ($nonEmpty) {
                $db->query($db->insert($prefix . 'fields')->rows([
                    'cid' => $cid, 'name' => $f, 'type' => 'str',
                    'str_value' => json_encode($val, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                ]));
            }
        }
    }

    /** EXIF 日期按相机记录的日历日期解释；缺失时按入库时间的 UTC 月份回退。 */
    public static function photoMonth(array $exif, int $created): string
    {
        $date = (string)($exif['datetime'] ?? '');
        if (preg_match('/^(\d{4})[:-](\d{2})[:-](\d{2})(?:[ T]|$)/', $date, $parts)
            && checkdate((int)$parts[2], (int)$parts[3], (int)$parts[1])) {
            return $parts[1] . '-' . $parts[2];
        }
        return $created > 0 ? gmdate('Y-m', $created) : '';
    }

    /** 删除某 cid 的所有图片（含文件）。 */
    public static function removeFor(int $cid): void
    {
        $rows = self::rowsFor($cid);
        foreach ($rows as $r) {
            self::unlinkFiles($r['original'], $r['full'], $r['mid'] ?? null, $r['avif'] ?? null, $r['mid_avif'] ?? null, $r['thumb']);
        }
        $db = \Typecho\Db::get();
        $db->query($db->delete(self::table())->where('cid = ?', $cid));
    }

    /** 仅处理真实上传目录内的普通文件；旧快照、软链接与新文件一律跳过。 */
    private static function orphanFingerprint(string $path): ?array
    {
        clearstatcache(true, $path);
        if (is_link($path) || !is_file($path)) { return null; }
        $real = realpath($path);
        if ($real === false || $real !== $path) { return null; }
        $inside = false;
        foreach (self::defaultDirs() as $webDir) {
            $dir = self::toAbs($webDir);
            if (is_link($dir) || realpath($dir) !== $dir) { continue; }
            if (strpos($real, $dir . '/') === 0) { $inside = true; break; }
        }
        if (!$inside) { return null; }
        $stat = @stat($path);
        // 保守宽限期：给升级前的上传进程及失败后的人工恢复留出时间。
        if (!$stat || $stat['mtime'] > time() - 3600) { return null; }
        return ['path' => $path, 'dev' => $stat['dev'], 'ino' => $stat['ino'],
            'size' => $stat['size'], 'mtime' => $stat['mtime'], 'ctime' => $stat['ctime']];
    }

    private static function referencedPaths(): array
    {
        $db = \Typecho\Db::get();
        $referenced = [];
        $add = static function ($value) use (&$referenced): void {
            if (!is_string($value) || trim($value) === '') { return; }
            $value = trim($value);
            if (preg_match('#^https?://#i', $value)) { $value = (string)parse_url($value, PHP_URL_PATH); }
            if ($value === '') { return; }
            $path = self::toAbs($value);
            $referenced[$path] = true;
            $real = realpath($path);
            if ($real !== false) { $referenced[$real] = true; }
        };
        foreach (self::fetchRows($db, $db->select()->from(self::table())) as $row) {
            foreach (['original', 'full', 'thumb', 'mid', 'avif', 'mid_avif'] as $field) {
                $add($row[$field] ?? null);
            }
        }
        // 历史图集可能只有文章字段，没有仓库行，也必须保留其公开图片。
        $fields = self::fetchRows($db, $db->select('name', 'str_value')->from($db->getPrefix() . 'fields')
            ->where('name IN (?, ?, ?)', 'img', 'thumb', 'variants'));
        foreach ($fields as $field) {
            if (($field['name'] ?? '') === 'variants') {
                $variants = json_decode((string)($field['str_value'] ?? ''), true);
                if (!is_array($variants)) { continue; }
                foreach ($variants as $variant) {
                    if (!is_array($variant)) { continue; }
                    foreach (['webp', 'avif'] as $format) {
                        if (!is_array($variant[$format] ?? null)) { continue; }
                        foreach ($variant[$format] as $path) { $add($path); }
                    }
                }
            } else {
                foreach (preg_split('/\\r?\\n/', (string)($field['str_value'] ?? '')) as $path) { $add($path); }
            }
        }
        return $referenced;
    }

    /** 快照仅是候选，删除前必须在同一锁内重新校验引用和文件身份。 */
    public static function orphanCandidates(): array
    {
        self::lockMedia();
        $referenced = self::referencedPaths();
        $list = [];
        foreach (self::defaultDirs() as $webDir) {
            $dir = self::toAbs($webDir);
            if (!is_dir($dir) || is_link($dir)) { continue; }
            $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));
            foreach ($it as $file) {
                $path = $file->getPathname();
                $candidate = self::orphanFingerprint($path);
                if ($candidate !== null && !isset($referenced[$path])) { $list[] = $candidate; }
            }
        }
        return $list;
    }

    public static function removeOrphanCandidates(array $candidates): int
    {
        self::lockMedia();
        $referenced = self::referencedPaths();
        $removed = 0;
        foreach ($candidates as $candidate) {
            // 升级前缓存的纯路径列表也不得直接用于删除。
            if (!is_array($candidate) || !isset($candidate['path'])) { continue; }
            $path = (string)$candidate['path'];
            if (isset($referenced[$path]) || self::orphanFingerprint($path) !== $candidate) { continue; }
            if (@unlink($path)) { $removed++; }
        }
        return $removed;
    }

    public static function cleanupOrphans(): array
    {
        return ['removed_files' => self::removeOrphanCandidates(self::orphanCandidates())];
    }

    /**
     * 重建：对每一行，若存在 original 则用它重新生成 full/thumb（覆盖）。
     * 用于调整压缩质量/缩略图尺寸后批量重建，或修复丢失的产物。
     *
     * @return array{rebuilt:int,failed:int}
     */
    public static function rebuild(array $opts = []): array
    {
        self::lockMedia();
        $opts = array_merge([
            'quality' => (int)Plugin::opt('infinitytimeQuality', Plugin::DEFAULT_QUALITY),
            'thumb_max' => (int)Plugin::opt('infinitytimeThumbMax', Plugin::DEFAULT_THUMB_MAX),
            'max_width' => (int)Plugin::opt('infinitytimeMaxWidth', Plugin::DEFAULT_MAX_WIDTH),
            'pano_width' => (int)Plugin::opt('infinitytimePanoWidth', Plugin::DEFAULT_PANO_WIDTH),
            'full_quality' => (int)Plugin::opt('infinitytimeFullQuality', Plugin::DEFAULT_FULL_QUALITY),
            'pano_quality' => (int)Plugin::opt('infinitytimePanoQuality', Plugin::DEFAULT_PANO_QUALITY),
        ], $opts);

        $db = \Typecho\Db::get();
        $rows = self::fetchRows($db, $db->select()->from(self::table()));
        $rebuilt = 0;
        $failed = 0;
        $syncedCids = [];
        foreach ($rows as $r) {
            if (empty($r['original'])) {
                continue;
            }
            $src = self::toAbs($r['original']);
            if (!is_file($src)) {
                $failed++;
                continue;
            }
            $pw = (int)($opts['pano_width'] ?? 0);
            $maxWidth = (int)$opts['max_width'];
            $fullQuality = (int)$opts['full_quality'];
            $info = @getimagesize($src);
            if (is_array($info) && ($info[0] ?? 0) > 0 && ($info[1] ?? 0) > 0) {
                $sw = (int)$info[0]; $sh = (int)$info[1];
                if (self::isPano($sw, $sh)) {
                    // 全景用独立宽度（0=不裁剪）
                    $maxWidth = $pw > 0 ? (int)min($sw, $pw) : 0;
                    $fullQuality = (int)$opts['pano_quality'];
                }
            }
            try {
                $res = MediaProcessor::process($src, self::toAbs($r['full']), self::toAbs($r['thumb']), $opts['thumb_max'], $opts['quality'], $maxWidth, $fullQuality);
                self::updateVariants((int)$r['id'], $res);
                if ((int)($r['cid'] ?? 0) > 0) {
                    $syncedCids[(int)$r['cid']] = true;
                }
                $rebuilt++;
            } catch (\Throwable $e) {
                Plugin::log('rebuild failed for id=' . $r['id'] . ': ' . $e->getMessage());
                $failed++;
            }
        }
        foreach (array_keys($syncedCids) as $c) {
            try { self::syncPostFields((int)$c); } catch (\Throwable $e) {}
        }
        return ['rebuilt' => $rebuilt, 'failed' => $failed];
    }

    /** 删除指定的原图及派生文件。 */
    public static function unlinkFiles(?string ...$rels): void
    {
        foreach ($rels as $rel) {
            if (!$rel) continue;
            try {
                @unlink(self::toAbs($rel));
            } catch (\Throwable $e) {
                Plugin::log('unlinkFiles skipped: ' . $e->getMessage());
            }
        }
    }

    private static function uniqueName(string $hash): string
    {
        return substr($hash, 0, 12) . '_' . sprintf('%06x', random_int(0, 0xFFFFFF));
    }

    private static function ensureDir(string $dir): void
    {
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new \RuntimeException('无法创建目录（请检查 PHP 运行用户对该路径的写权限）: ' . $dir);
        }
        if (!is_writable($dir)) {
            // 目录属于当前用户但权限过严时，尝试放宽；仍失败则给出可执行的修复命令。
            @chmod($dir, 0775);
            if (!is_writable($dir)) {
                throw new \RuntimeException(
                    '目录不可写: ' . $dir
                    . '。请给 PHP 运行用户赋写权限，例如执行：chmod -R 775 ' . $dir
                    . ' （仍失败则先 chown -R <运行用户>:<组> ' . dirname($dir, 3) . '）'
                );
            }
        }
    }

    private static function pruneEmpty(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST) as $item) {
            if ($item->isDir()) {
                @rmdir($item->getPathname());
            }
        }
    }
}

