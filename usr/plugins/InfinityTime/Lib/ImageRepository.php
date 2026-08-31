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

    /** 插件数据表全名（带前缀）。 */
    public static function table(): string
    {
        return \Typecho\Db::get()->getPrefix() . 'infinitytime_images';
    }

    /** 上传根目录（文件系统绝对路径）。 */
    public static function uploadRoot(): string
    {
        return rtrim((defined('__TYPECHO_UPLOAD_ROOT_DIR__') ? __TYPECHO_UPLOAD_ROOT_DIR__ : __TYPECHO_ROOT_DIR__ . '/usr/uploads'), '/');
    }

    /** 根据绝对路径得到站点根相对的 web URL（如 /usr/uploads/...）。 */
    public static function toWeb(string $abs): string
    {
        $root = rtrim(__TYPECHO_ROOT_DIR__, '/');
        $abs = str_replace('\\', '/', $abs);
        if (strpos($abs, $root . '/') === 0) {
            return substr($abs, strlen($root));
        }
        return $abs;
    }

    /** 根据 web 相对路径得到文件系统绝对路径。 */
    public static function toAbs(string $web): string
    {
        $root = rtrim(__TYPECHO_ROOT_DIR__, '/');
        $web = '/' . ltrim($web, '/');
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
        $opts = array_merge([
            'quality' => 82,
            'thumb_max' => 1280,
            'keep_original' => true,
            'max_width' => 0,
            'dirs' => self::defaultDirs(),
        ], $opts);

        if (empty($file['tmp_name']) || !is_uploaded_file($file['tmp_name'])) {
            if (empty($file['tmp_name'])) {
                return null;
            }
        }
        $src = $file['tmp_name'];
        if (!is_file($src)) {
            return null;
        }

        $ext = strtolower(pathinfo($file['name'] ?? '', PATHINFO_EXTENSION));
        if (!in_array($ext, MediaProcessor::supportedExtensions(), true)) {
            return null;
        }

        try {
            $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($src) ?: '';
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
                    return null;
                }
                $origRel = $origWebDir . '/' . $base . '.' . $ext;
            }

            self::ensureDir($fullAbsDir);
            self::ensureDir($thumbAbsDir);

            $fullPath  = $fullAbsDir  . '/' . $base . '.webp';
            $thumbPath = $thumbAbsDir . '/' . $base . '.webp';

            $result = MediaProcessor::process($src, $fullPath, $thumbPath, $opts['thumb_max'], $opts['quality'], $opts['max_width']);

            $gps = $result['exif']['gps'] ?? null;

            return [
                'original' => $origRel,
                'full'     => $fullWebDir . '/' . $base . '.webp',
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
            return null;
        }
    }

    /** 默认目录配置（web 相对路径）。 */
    public static function defaultDirs(): array
    {
        $root = \Utils\Helper::options()->uploadDir ?? '/usr/uploads';
        $root = '/' . trim($root, '/');
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
        $db = \Typecho\Db::get();
        $table = self::table();
        return (int)$db->query($db->insert($table)->rows([
            'cid' => $cid,
            'original' => $meta['original'],
            'full' => $meta['full'],
            'thumb' => $meta['thumb'],
            'width' => $meta['width'],
            'height' => $meta['height'],
            'size' => $meta['size'],
            'sort' => $sort,
            'hash' => $meta['hash'] ?? '',
            'exif' => json_encode($meta['exif'] ?? [], JSON_UNESCAPED_UNICODE),
            'gps_lat' => $meta['gps_lat'] ?? null,
            'gps_lng' => $meta['gps_lng'] ?? null,
            'address' => (string)($meta['address'] ?? ''),
            'title' => (string)($meta['title'] ?? ''),
            'desc' => (string)($meta['desc'] ?? ''),
            'created' => time(),
        ]));
    }

    /** 从插件表取某 cid 的图片列表。 */
    public static function rowsFor(int $cid): array
    {
        self::ensureSchema();
        $db = \Typecho\Db::get();
        $rows = $db->fetchAll($db->select()->from(self::table())->where('cid = ?', $cid)->order('sort', \Typecho\Db::SORT_ASC)->order('id', \Typecho\Db::SORT_ASC));
        return array_map(function ($r) {
            $r['exif'] = json_decode($r['exif'] ?? '{}', true);
            return $r;
        }, $rows);
    }

    /** 运行时确保 title/desc 列存在（兼容旧库升级；每进程一次）。 */
    private static $schemaEnsured = false;
    public static function ensureSchema(): void
    {
        if (self::$schemaEnsured) {
            return;
        }
        self::$schemaEnsured = true;
        $db = \Typecho\Db::get();
        $q = (stripos($db->getAdapterName(), 'mysql') !== false) ? '`' : '"';
        $table = self::table();
        foreach (['title', 'desc'] as $col) {
            try {
                $db->query("ALTER TABLE {$q}{$table}{$q} ADD COLUMN {$q}{$col}{$q} text");
            } catch (\Throwable $e) {
                // 列已存在则忽略
            }
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

    /** 根据某图集的图片行，重建文章里的 addresses/titles/descs JSON 字段。 */
    public static function syncPostFields(int $cid): void
    {
        $db = \Typecho\Db::get();
        $prefix = $db->getPrefix();
        $rows = self::rowsFor($cid);
        $addresses = [];
        $titles = [];
        $descs = [];
        foreach ($rows as $r) {
            $addresses[] = (string)($r['address'] ?? '');
            $titles[] = (string)($r['title'] ?? '');
            $descs[] = (string)($r['desc'] ?? '');
        }
        foreach (['addresses', 'titles', 'descs'] as $f) {
            $db->query($db->delete($prefix . 'fields')->where('cid = ?', $cid)->where('name = ?', $f));
            $val = $f === 'addresses' ? $addresses : ($f === 'titles' ? $titles : $descs);
            if (count(array_filter($val, 'strlen')) > 0) {
                $db->query($db->insert($prefix . 'fields')->rows([
                    'cid' => $cid, 'name' => $f, 'type' => 'str',
                    'str_value' => json_encode($val, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                ]));
            }
        }
    }

    /** 删除某 cid 的所有图片（含文件）。 */
    public static function removeFor(int $cid): void
    {
        $rows = self::rowsFor($cid);
        foreach ($rows as $r) {
            self::unlinkFiles($r['original'], $r['full'], $r['thumb']);
        }
        $db = \Typecho\Db::get();
        $db->query($db->delete(self::table())->where('cid = ?', $cid));
    }

    /** 清理：删除所有不再关联任何 cid 的孤儿文件与记录（可选保留空目录）。 */
    public static function cleanupOrphans(): array
    {
        $db = \Typecho\Db::get();
        $rows = $db->fetchAll($db->select()->from(self::table()));
        // 收集所有仍被引用的绝对路径
        $referenced = [];
        foreach ($rows as $r) {
            foreach (['original', 'full', 'thumb'] as $f) {
                if (!empty($r[$f])) {
                    $referenced[self::toAbs($r[$f])] = true;
                }
            }
        }
        $removed = 0;
        foreach (self::defaultDirs() as $type => $webDir) {
            $absDir = self::toAbs($webDir);
            if (!is_dir($absDir)) {
                continue;
            }
            $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($absDir, \FilesystemIterator::SKIP_DOTS));
            foreach ($it as $file) {
                if ($file->isFile() && !isset($referenced[$file->getPathname()])) {
                    @unlink($file->getPathname());
                    $removed++;
                }
            }
            // 清理空目录
            self::pruneEmpty($absDir);
        }
        return ['removed_files' => $removed];
    }

    /**
     * 重建：对每一行，若存在 original 则用它重新生成 full/thumb（覆盖）。
     * 用于调整压缩质量/缩略图尺寸后批量重建，或修复丢失的产物。
     *
     * @return array{rebuilt:int,failed:int}
     */
    public static function rebuild(array $opts = []): array
    {
        $opts = array_merge([
            'quality' => (int)Plugin::opt('infinitytimeQuality', 82),
            'thumb_max' => (int)Plugin::opt('infinitytimeThumbMax', 1280),
            'max_width' => (int)Plugin::opt('infinitytimeMaxWidth', 0),
        ], $opts);

        $db = \Typecho\Db::get();
        $rows = $db->fetchAll($db->select()->from(self::table()));
        $rebuilt = 0;
        $failed = 0;
        foreach ($rows as $r) {
            if (empty($r['original'])) {
                continue;
            }
            $src = self::toAbs($r['original']);
            if (!is_file($src)) {
                $failed++;
                continue;
            }
            try {
                MediaProcessor::process($src, self::toAbs($r['full']), self::toAbs($r['thumb']), $opts['thumb_max'], $opts['quality'], $opts['max_width'] ?? 0);
                $rebuilt++;
            } catch (\Throwable $e) {
                Plugin::log('rebuild failed for id=' . $r['id'] . ': ' . $e->getMessage());
                $failed++;
            }
        }
        return ['rebuilt' => $rebuilt, 'failed' => $failed];
    }

    /** 删除三个文件。 */
    public static function unlinkFiles(?string ...$rels): void
    {
        foreach ($rels as $rel) {
            if ($rel) {
                @unlink(self::toAbs($rel));
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
            throw new \RuntimeException('无法创建目录: ' . $dir);
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
