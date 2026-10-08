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
        return (int)Database::query($db->insert($table)->rows([
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
            $query = Database::query($query->prepare((string)$query));
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

    /** 后台详情默认分页；仅用户明确要求全部排序时读完整列表。 */
    public static function pageFor(int $cid, int $page = 1, string $missing = '', bool $all = false): array
    {
        self::ensureSchema();
        $db = \Typecho\Db::get();
        if (!in_array($missing, ['', 'title', 'desc', 'address'], true)) { $missing = ''; }
        $filter = static function ($query) use ($cid, $missing, $db) {
            $query->where('cid = ?', $cid);
            if ($missing !== '') {
                $quote = stripos($db->getAdapterName(), 'mysql') !== false ? '`' : '"';
                $query->where('TRIM(COALESCE(' . $quote . $missing . $quote . ", '')) = ?", '');
            }
            return $query;
        };
        $counts = self::fetchRows($db, $filter($db->select('COUNT(*) AS total')->from(self::table())));
        $total = (int)($counts[0]['total'] ?? 0);
        $size = 40;
        $pages = max(1, (int)ceil($total / $size));
        $page = max(1, min($page, $pages));
        $all = $all && $missing === '';
        $query = $filter($db->select('id', 'cid', 'sort', 'full', 'thumb', 'width', 'height', 'exif', 'title', 'desc', 'address', 'created')->from(self::table()))
            ->order('sort', \Typecho\Db::SORT_ASC)->order('id', \Typecho\Db::SORT_ASC);
        if (!$all) { $query->limit($size)->offset(($page - 1) * $size); }
        $rows = self::fetchRows($db, $query);
        foreach ($rows as &$row) { $exif = json_decode($row['exif'] ?? '{}', true); $row['exif'] = is_array($exif) ? $exif : []; }
        unset($row);
        return ['rows' => $rows, 'total' => $total, 'page' => $page, 'pages' => $pages, 'missing' => $missing,
            'all' => $all, 'sortable' => $missing === '' && ($all || $total <= $size)];
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
        Database::query($db->update(self::table())->rows([
            'title' => $title,
            'desc' => $desc,
            'address' => $address,
        ])->where('id = ?', $rowId));
    }

    /** 图片元数据与公开聚合字段一并保存，失败保持两者的旧版本。 */
    public static function editImage(int $rowId, int $cid, string $title, string $desc, string $address): bool
    {
        $changed = false;
        self::fieldTransaction(static function (callable $write) use ($rowId, $cid, $title, $desc, $address, &$changed): void {
            $db = \Typecho\Db::get();
            $row = $db->fetchRow($write($db->select('id')->from(self::table())->where('id = ?', $rowId)->where('cid = ?', $cid)->limit(1)));
            if (!$row) { return; }
            $write($db->update(self::table())->rows(['title' => $title, 'desc' => $desc, 'address' => $address])
                ->where('id = ?', $rowId)->where('cid = ?', $cid));
            self::writePostFields($cid, false, $write);
            $changed = true;
        }, true);
        return $changed;
    }

    /** 批量元数据与聚合字段一起提交；任何越界/缺失照片均拒绝整批。 */
    public static function editImages(int $cid, array $ids, array $changes): int
    {
        if (!$ids || count($ids) > 100 || count(array_unique($ids)) !== count($ids)) { throw new \DomainException('请勾选1至100张不同照片'); }
        foreach ($ids as $id) { if (!is_int($id) || $id <= 0) { throw new \DomainException('图片标识无效'); } }
        if (!$changes || array_diff(array_keys($changes), ['desc', 'address'])) { throw new \DomainException('请选择需要统一修改的字段'); }
        foreach ($changes as $value) { if (!is_string($value) || strlen($value) > 8000) { throw new \DomainException('字段内容过长或无效'); } }
        self::fieldTransaction(static function (callable $write) use ($cid, $ids, $changes): void {
            $db = \Typecho\Db::get();
            $marks = implode(',', array_fill(0, count($ids), '?'));
            $rows = $db->fetchAll($write($db->select('id')->from(self::table())->where('cid = ?', $cid)->where('id IN (' . $marks . ')', ...$ids)));
            if (count($rows) !== count($ids)) { throw new \DomainException('照片已变化或不属于当前相册，请刷新后重试'); }
            $write($db->update(self::table())->rows($changes)->where('cid = ?', $cid)->where('id IN (' . $marks . ')', ...$ids));
            self::writePostFields($cid, false, $write);
        }, true);
        return count($ids);
    }

    /** 校验完整当前清单后原子排序；中途失败不会留下半套顺序。 */
    public static function sortImages(int $cid, array $rowIds): void
    {
        self::fieldTransaction(static function (callable $write) use ($cid, $rowIds): void {
            $db = \Typecho\Db::get();
            foreach ($rowIds as $id) {
                if (!is_int($id) || $id <= 0) { throw new \DomainException('图片列表包含无效标识，请刷新后重试'); }
            }
            $rows = $db->fetchAll($write($db->select('id')->from(self::table())->where('cid = ?', $cid)));
            $existing = array_map(static function ($row) { return (int)$row['id']; }, $rows);
            $requested = $rowIds; sort($requested); sort($existing);
            if ($requested !== $existing || count($rowIds) !== count(array_unique($rowIds))) {
                throw new \DomainException('图片列表已变化或包含其他图集图片，请刷新后重试');
            }
            foreach ($rowIds as $order => $rowId) {
                $write($db->update(self::table())->rows(['sort' => $order])->where('id = ?', $rowId)->where('cid = ?', $cid));
            }
            self::writePostFields($cid, false, $write);
        }, true);
    }

    /** 只改变公开 URL，数据库文件路径保持不变，删除和重建仍定位原文件。 */
    public static function versionUrl(string $url, array $exif): string
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

    /** 重建后同步实际输出尺寸、大小与响应式变体路径。 */
    public static function updateVariants(int $rowId, array $result): void
    {
        self::lockMedia();
        $db = \Typecho\Db::get();
        $rows = self::fetchRows($db, $db->select('exif')->from(self::table())->where('id = ?', $rowId)->limit(1));
        $row = $rows[0] ?? null;
        if (!$row) { throw new \RuntimeException('图片记录已变化，请刷新后重试'); }
        $exif = is_array($row['exif'] ?? null) ? $row['exif'] : (json_decode((string)($row['exif'] ?? '{}'), true) ?: []);
        if (!is_array($exif)) { $exif = []; }
        $exif['_infinity_media_version'] = bin2hex(random_bytes(16));
        $values = [
            'exif' => json_encode($exif, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR),
            'mid' => !empty($result['mid']) ? self::toWeb((string)$result['mid']) : null,
            'avif' => !empty($result['avif']) ? self::toWeb((string)$result['avif']) : null,
            'mid_avif' => !empty($result['mid_avif']) ? self::toWeb((string)$result['mid_avif']) : null,
        ];
        foreach (['width', 'height', 'size'] as $field) {
            if (isset($result[$field])) { $values[$field] = (int)$result[$field]; }
        }
        Database::query($db->update(self::table())->rows($values)->where('id = ?', $rowId));
    }

    /** 主库上的原子字段替换；保存点不会提交或回滚调用方的其他写入。 */
    public static function syncPostFields(int $cid, bool $clearEmpty = false): void
    {
        self::fieldTransaction(static function (callable $write) use ($cid, $clearEmpty): void {
            self::writePostFields($cid, $clearEmpty, $write);
        });
    }

    /** 共用事务协议；返回 true 仅表示本方法已提交自己开启的事务。 */
    private static function fieldTransaction(callable $operation, bool $mutatesImages = false, bool $mutatesContents = false): bool
    {
        self::lockMedia();
        $db = \Typecho\Db::get();
        $tables = [$db->getPrefix() . 'fields'];
        if ($mutatesImages) { $tables[] = self::table(); }
        if ($mutatesContents) { $tables[] = $db->getPrefix() . 'contents'; }
        return Database::transaction($operation, $tables);
    }

    /**
     * 删除图片行并同步文章字段。调用方先验证 cid 的编辑权限。
     * DB 回滚时保留实体文件；外层事务存在时不清理文件（留待提交后的孤儿清理）。
     * DB 与文件系统无法跨系统原子提交：提交后清理失败只留下可安全清理的孤儿。
     */
    public static function deleteImage(int $rowId, int $cid): bool
    {
        self::lockMedia();
        $deleted = false;
        $candidates = [];
        $committed = self::fieldTransaction(static function (callable $write) use ($rowId, $cid, &$deleted, &$candidates): void {
            $db = \Typecho\Db::get();
            $row = $db->fetchRow($write($db->select()->from(self::table())
                ->where('id = ?', $rowId)->where('cid = ?', $cid)->limit(1)));
            if (!$row) { return; }
            $candidates = self::deletionCandidates($row);
            $write($db->delete(self::table())->where('id = ?', $rowId)->where('cid = ?', $cid));
            self::writePostFields($cid, true, $write);
            $deleted = true;
        }, true);
        if ($deleted && $committed) { self::cleanupDeletedFiles($candidates); }
        return $deleted;
    }

    /** 删除图集的现有三表数据；不扩大到评论、分类关系或其他 cid。调用方先验证编辑权限。 */
    public static function deleteAlbum(int $cid): bool
    {
        if ($cid <= 0) { return false; }
        $deleted = false;
        $candidates = [];
        $committed = self::fieldTransaction(static function (callable $write) use ($cid, &$deleted, &$candidates): void {
            $db = \Typecho\Db::get();
            $prefix = $db->getPrefix();
            $content = $db->fetchRow($write($db->select('cid')->from($prefix . 'contents')->where('cid = ?', $cid)->limit(1)));
            if (!$content) { return; }
            // 单次扫描本图集路径，避免逐图同步及反复扫描全站引用的 O(N²) 开销。
            $rows = $db->fetchAll($write($db->select('original', 'full', 'thumb', 'mid', 'avif', 'mid_avif')
                ->from(self::table())->where('cid = ?', $cid)));
            foreach ($rows as $row) { $candidates += self::deletionCandidates($row); }
            $write($db->delete(self::table())->where('cid = ?', $cid));
            $write($db->delete($prefix . 'fields')->where('cid = ?', $cid));
            $write($db->delete($prefix . 'contents')->where('cid = ?', $cid));
            $deleted = true;
        }, true, true);
        // 不提交调用方事务；与单图删除相同，未自提交时保留文件供后续安全清理。
        if ($deleted && $committed) { self::cleanupDeletedFiles($candidates); }
        return $deleted;
    }

    private static function deletionCandidates(array $row): array
    {
        $candidates = [];
        foreach (['original', 'full', 'thumb', 'mid', 'avif', 'mid_avif'] as $field) {
            $path = $row[$field] ?? null;
            // 污染路径不能经 toAbs 的容错归一化变成另一个有效文件。
            if (!is_string($path) || $path === '' || $path[0] !== '/'
                || strpos($path, "\\") !== false || strpos($path, "\0") !== false
                || preg_match('#(?:^|/)\.\.?(?:/|$)#', $path)) { continue; }
            $candidate = self::orphanFingerprint(self::toAbs($path), false);
            if ($candidate !== null) { $candidates[$candidate['path']] = $candidate; }
        }
        return $candidates;
    }

    /** 仅在确认提交后调用；文件系统失败只留下孤儿，不改变已提交的成功结果。 */
    private static function cleanupDeletedFiles(array $candidates): void
    {
        if (!$candidates) { return; }
        // 锁持续覆盖提交后的引用重查；任何异常都不能把已提交的删除伪报为失败。
        try {
            $referenced = self::referencedPaths();
            foreach ($candidates as $path => $candidate) {
                if (!isset($referenced[$path]) && self::orphanFingerprint($path, false) === $candidate) {
                    if (!@unlink($path)) { Plugin::log('image delete left an orphan file for later cleanup'); }
                }
            }
        } catch (\Throwable $e) {
            Plugin::log('image delete cleanup deferred: ' . $e->getMessage());
        }
    }

    /** 按图片行的顺序同步路径与元数据，避免排序/删除后文章字段错位。 */
    private static function writePostFields(int $cid, bool $clearEmpty, callable $write): void
    {
        $db = \Typecho\Db::get();
        $prefix = $db->getPrefix();
        // 不在调用方可能已开启的事务内运行 schema 迁移（MySQL DDL 会隐式提交）。
        $query = $db->select()->from(self::table())->where('cid = ?', $cid)
            ->order('sort', \Typecho\Db::SORT_ASC)->order('id', \Typecho\Db::SORT_ASC);
        $rows = $db->fetchAll($write($query));
        foreach ($rows as &$row) { $parsed = json_decode($row['exif'] ?? '{}', true); $row['exif'] = is_array($parsed) ? $parsed : []; }
        unset($row);
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
            $images[] = self::versionUrl((string)($r['full'] ?? ''), is_array($r['exif'] ?? null) ? $r['exif'] : []);
            $thumbs[] = self::versionUrl((string)($r['thumb'] ?? ''), is_array($r['exif'] ?? null) ? $r['exif'] : []);
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
                'webp' => array_values(array_filter(array_map(static function ($url) use ($r) { return self::versionUrl($url, $r['exif']); }, [(string)($r['full'] ?? ''), (string)($r['mid'] ?? '')]))),
                'avif' => array_values(array_filter(array_map(static function ($url) use ($r) { return self::versionUrl($url, $r['exif']); }, [(string)($r['avif'] ?? ''), (string)($r['mid_avif'] ?? '')]))),
            ];
            // 重建文章里的 exif 字段时同样剔除 gps（历史数据也据此清掉）
            $e = is_array($r['exif'] ?? null) ? $r['exif'] : [];
            unset($e['gps'], $e['_infinity_media_version']);
            $exifs[] = $e;
        }
        foreach (['img' => $images, 'thumb' => $thumbs] as $name => $paths) {
            $write($db->delete($prefix . 'fields')->where('cid = ?', $cid)->where('name = ?', $name));
            // 保留空 img 标记，使删除最后一张图片后的空图集仍可识别。
            $write($db->insert($prefix . 'fields')->rows([
                'cid' => $cid, 'name' => $name, 'type' => 'str', 'str_value' => implode("\n", $paths),
            ]));
        }
        $map = ['addresses' => $addresses, 'titles' => $titles, 'descs' => $descs, 'panos' => $panos, 'dims' => $dims, 'variants' => $variants, 'exif' => $exifs, 'photo_ids' => $photoIds, 'months' => $months];
        foreach (['addresses', 'titles', 'descs', 'panos', 'dims', 'variants', 'exif', 'photo_ids', 'months'] as $f) {
            $write($db->delete($prefix . 'fields')->where('cid = ?', $cid)->where('name = ?', $f));
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
                $write($db->insert($prefix . 'fields')->rows([
                    'cid' => $cid, 'name' => $f, 'type' => 'str',
                    'str_value' => json_encode($val, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
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
        Database::query($db->delete(self::table())->where('cid = ?', $cid));
    }

    /** 仅接受白名单真实目录内的普通文件；孤儿扫描额外跳过宽限期内的新文件。 */
    private static function orphanFingerprint(string $path, bool $oldOnly = true): ?array
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
        if (!$stat || ($oldOnly && $stat['mtime'] > time() - 3600)) { return null; }
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
            if (preg_match('#^(?:https?:)?//#i', $value)) { $value = (string)parse_url($value, PHP_URL_PATH); }
            if ($value === '') { return; }
            // 历史字段可含缓存参数、片段和 URL 编码；浏览器仍在读取同一个实体文件。
            $urlPath = preg_split('/[?#]/', $value, 2)[0];
            foreach (array_unique([$value, $urlPath, rawurldecode($urlPath)]) as $candidate) {
                if (strpos($candidate, "\0") !== false) { continue; }
                // 浏览器会折叠 URL 点目录，即使被折叠的目录实际不存在也一样。
                // toAbs 的防穿越容错只删除 .. 段，不能用于判断这种历史 URL 引用。
                $segments = [];
                foreach (explode('/', str_replace("\\", '/', $candidate)) as $segment) {
                    if ($segment === '' || $segment === '.') { continue; }
                    if ($segment === '..') { array_pop($segments); }
                    else { $segments[] = $segment; }
                }
                // 额外保留旧解释，仅扩大保护集合，不放宽删除候选的路径白名单。
                foreach (array_unique([$candidate, '/' . implode('/', $segments)]) as $protected) {
                    $path = self::toAbs($protected);
                    $referenced[$path] = true;
                    $real = realpath($path);
                    if ($real !== false) { $referenced[$real] = true; }
                }
            }
        };
        // 只读引用路径；扫描孤儿文件不需要搬运可能很大的 EXIF/标题等元数据。
        foreach (self::fetchRows($db, $db->select('original', 'full', 'thumb', 'mid', 'avif', 'mid_avif')->from(self::table())) as $row) {
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

