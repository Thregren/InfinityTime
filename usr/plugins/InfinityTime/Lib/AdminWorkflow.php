<?php
namespace TypechoPlugin\InfinityTime\Lib;

use Typecho\Db;
use TypechoPlugin\InfinityTime\Plugin;

/** 持久化草稿与逐图上传流程；所有写操作均持有仓库请求级锁。 */
final class AdminWorkflow
{
    public static function draft(int $uid, array $data, string $key): array
    {
        if ($uid <= 0 || !AdminSecurity::validKey($key)) { throw new \InvalidArgumentException('上传标识无效，请刷新页面'); }
        ImageRepository::lockMedia();
        $existing = AdminRepository::draftForOperation($uid, $key);
        if ($existing) { return $existing + ['replayed' => true]; }
        $title = trim((string)($data['title'] ?? ''));
        if ($title === '') { throw new \InvalidArgumentException('请填写图集标题'); }
        $db = Db::get();
        $now = time();
        $cid = 0;
        Database::transaction(static function () use ($db, $title, $now, $uid, $key, $data, &$cid): void {
            $cid = (int)Database::query($db->insert($db->getPrefix() . 'contents')->rows([
                'title' => $title, 'slug' => 'album-' . date('YmdHis', $now) . '-' . bin2hex(random_bytes(6)),
                'created' => $now, 'modified' => $now, 'text' => '', 'authorId' => $uid,
                'type' => 'post', 'status' => 'draft', 'allowComment' => '0', 'allowPing' => '0',
                'allowFeed' => '0', 'template' => '', 'password' => '',
            ]));
            if ($cid <= 0) { throw new \RuntimeException('图集创建失败'); }
            $fields = [AdminRepository::MARKER => '1', AdminRepository::OPERATION => $key];
            foreach (['device' => 'device', 'tags' => 'tags', 'location' => 'address'] as $name => $input) {
                $fields[$name] = trim((string)($data[$input] ?? ''));
            }
            AdminRepository::setFields($cid, $fields);
        }, [$db->getPrefix() . 'contents', $db->getPrefix() . 'fields']);
        return ['cid' => $cid, 'title' => $title, 'status' => 'draft', 'replayed' => false];
    }

    public static function requireAlbum(int $cid, int $uid, bool $admin, bool $canPublish = false): array
    {
        $album = AdminRepository::album($cid, $uid, $admin, true);
        if (!$album) { throw new \DomainException('图集不存在或没有权限'); }
        if ($album['status'] === 'publish' && !$canPublish) { throw new \DomainException('已发布图集需要编辑或管理员权限才能修改'); }
        return $album;
    }

    /** 图集标题及全部手动字段同事务更新；权限在持锁后重新检查。 */
    public static function updateAlbum(int $cid, int $uid, bool $admin, bool $canPublish, array $data): void
    {
        ImageRepository::lockMedia();
        self::requireAlbum($cid, $uid, $admin, $canPublish);
        $db = Db::get();
        $title = trim((string)($data['title'] ?? ''));
        $fields = [];
        foreach (['device' => 'device', 'tags' => 'tags', 'location' => 'address'] as $name => $input) {
            $fields[$name] = trim((string)($data[$input] ?? ''));
        }
        Database::transaction(static function () use ($db, $cid, $title, $fields): void {
            $values = ['modified' => time()];
            if ($title !== '') { $values['title'] = $title; }
            Database::query($db->update($db->getPrefix() . 'contents')->rows($values)->where('cid = ?', $cid));
            AdminRepository::setFields($cid, $fields);
        }, [$db->getPrefix() . 'contents', $db->getPrefix() . 'fields']);
    }

    /** 上传标识绑定文件内容与逐图元数据，不受转换设置变化影响。 */
    public static function fingerprint(array $file, array $data): string
    {
        $hash = @hash_file('sha256', (string)($file['tmp_name'] ?? ''));
        if (!is_string($hash)) { throw new \InvalidArgumentException('无法读取上传文件'); }
        return hash('sha256', json_encode([$hash, trim((string)($data['title'] ?? '')),
            trim((string)($data['desc'] ?? '')), trim((string)($data['address'] ?? ''))], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }

    public static function upload(int $cid, int $uid, bool $admin, array $file, array $data, string $key, bool $canPublish = false): array
    {
        if (!AdminSecurity::validKey($key)) { throw new \InvalidArgumentException('图片上传标识无效，请重新选择文件'); }
        ImageRepository::lockMedia();
        self::requireAlbum($cid, $uid, $admin, $canPublish);
        ImageRepository::ensureSchema();
        $managed = AdminRepository::readRow(Db::get()->select('COUNT(*) AS total')->from(ImageRepository::table())->where('cid = ?', $cid), true);
        if ((int)($managed['total'] ?? 0) === 0 && AdminRepository::field($cid, 'img', true) !== '') {
            throw new \DomainException('此历史图集只有旧图片字段，暂不支持追加。请新建图集上传；原图集未改动');
        }
        $error = (int)($file['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($error !== UPLOAD_ERR_OK) {
            $messages = [UPLOAD_ERR_INI_SIZE => '图片超过服务器上传限制', UPLOAD_ERR_FORM_SIZE => '图片超过表单上传限制',
                UPLOAD_ERR_PARTIAL => '图片只上传了一部分，请重试', UPLOAD_ERR_NO_FILE => '没有收到图片文件',
                UPLOAD_ERR_NO_TMP_DIR => '服务器缺少临时目录', UPLOAD_ERR_CANT_WRITE => '服务器无法写入临时文件',
                UPLOAD_ERR_EXTENSION => '服务器扩展阻止了上传'];
            throw new \InvalidArgumentException($messages[$error] ?? '上传失败');
        }
        if (empty($file['tmp_name']) || !is_uploaded_file($file['tmp_name'])) { throw new \InvalidArgumentException('非法上传文件'); }
        $bytes = @filesize($file['tmp_name']);
        if ($bytes === false || $bytes <= 0 || $bytes > 64 * 1024 * 1024) { throw new \InvalidArgumentException('图片为空或超过 64MB 上限'); }
        $fingerprint = self::fingerprint($file, $data);
        $db = Db::get();
        $row = AdminRepository::readRow($db->select()->from(ImageRepository::table())
            ->where('cid = ?', $cid)->where('upload_key = ?', $key)->limit(1), true);
        if ($row) {
            if (!is_string($row['upload_fingerprint'] ?? null) || !hash_equals($row['upload_fingerprint'], $fingerprint)) {
                throw new \DomainException('同一上传标识不能用于不同图片或描述，请重新选择文件');
            }
            self::sync($cid, $row);
            return ['cid' => $cid, 'rowId' => (int)$row['id'], 'replayed' => true];
        }
        $dim = @getimagesize($file['tmp_name']);
        if (is_array($dim) && (($dim[0] ?? 0) > 20000 || ($dim[1] ?? 0) > 20000)) {
            throw new \InvalidArgumentException('图片单边超过 20000px 上限');
        }
        $meta = ImageRepository::ingest($file, [
            'quality' => (int)Plugin::opt('infinitytimeQuality', Plugin::DEFAULT_QUALITY),
            'thumb_max' => (int)Plugin::opt('infinitytimeThumbMax', Plugin::DEFAULT_THUMB_MAX),
            'max_width' => (int)Plugin::opt('infinitytimeMaxWidth', Plugin::DEFAULT_MAX_WIDTH),
            'full_quality' => (int)Plugin::opt('infinitytimeFullQuality', Plugin::DEFAULT_FULL_QUALITY),
            'pano_width' => (int)Plugin::opt('infinitytimePanoWidth', Plugin::DEFAULT_PANO_WIDTH),
            'pano_quality' => (int)Plugin::opt('infinitytimePanoQuality', Plugin::DEFAULT_PANO_QUALITY),
            'keep_original' => (bool)Plugin::opt('infinitytimeKeepOriginal', Plugin::DEFAULT_KEEP_ORIGINAL),
            'address' => trim((string)($data['address'] ?? '')),
        ]);
        if (!$meta) { throw new \RuntimeException(ImageRepository::$lastError ?: '图片转换失败，请重试'); }
        $meta['title'] = trim((string)($data['title'] ?? ''));
        $meta['desc'] = trim((string)($data['desc'] ?? ''));
        $meta['upload_key'] = $key;
        $meta['upload_fingerprint'] = $fingerprint;
        $max = AdminRepository::readRow($db->select('MAX(sort) AS last_sort')->from(ImageRepository::table())->where('cid = ?', $cid), true);
        $sort = isset($max['last_sort']) ? (int)$max['last_sort'] + 1 : 0;
        // 标识与图片行原子入库；字段同步失败后仍可幂等重试。
        $rowId = ImageRepository::insertRow($cid, $meta, $sort);
        if ($rowId <= 0) { throw new \RuntimeException('图片记录写入失败，请重试'); }
        self::sync($cid, $meta);
        return ['cid' => $cid, 'rowId' => $rowId, 'replayed' => false];
    }

    private static function sync(int $cid, array $row): void
    {
        ImageRepository::syncPostFields($cid);
        // 保留手动填写的设备；否则从成功图片的 EXIF 提取设备。
        if (AdminRepository::field($cid, 'device', true) === '') {
            $exif = is_array($row['exif'] ?? null) ? $row['exif'] : (json_decode((string)($row['exif'] ?? '{}'), true) ?: []);
            $device = trim((string)($exif['make'] ?? '') . ' ' . (string)($exif['model'] ?? ''));
            if ($device !== '') { AdminRepository::setField($cid, 'device', $device); }
        }
    }

    public static function publish(int $cid, int $uid, bool $admin, bool $canPublish = false): void
    {
        if (!$canPublish) { throw new \DomainException('发布图集需要编辑或管理员权限'); }
        ImageRepository::lockMedia();
        self::requireAlbum($cid, $uid, $admin, $canPublish);
        $counts = AdminRepository::counts([$cid], true);
        if (($counts[$cid] ?? 0) < 1) { throw new \InvalidArgumentException('请先上传至少一张图片再发布'); }
        ImageRepository::syncPostFields($cid);
        $db = Db::get();
        Database::query($db->update($db->getPrefix() . 'contents')->rows(['status' => 'publish', 'modified' => time()])->where('cid = ?', $cid));
    }
}
