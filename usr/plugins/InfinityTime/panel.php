<?php
/**
 * InfinityTime 后台管理页（照片/图集上传 + 维护）。
 * 由 extending.php?panel=InfinityTime/panel.php 引入，已嵌入 Typecho 后台壳。
 */
if (!defined('__TYPECHO_ADMIN__')) {
    exit;
}

use Typecho\Db;
use Widget\User;
use Utils\Helper;
use TypechoPlugin\InfinityTime\Plugin;
use TypechoPlugin\InfinityTime\Lib\ImageRepository;
use TypechoPlugin\InfinityTime\Lib\MediaProcessor;

$db = Db::get();
$user = User::alloc();
$prefix = $db->getPrefix();
$options = Helper::options();

if (!$user->pass('contributor', true)) {
    throw new \Typecho\Widget\Exception(_t('没有权限'), 403);
}

/* ---------------------------------- 工具函数 ---------------------------------- */

function pp_reply(string $msg = '', string $type = 'success'): void
{
    $options = Helper::options();
    $url = Helper::url('InfinityTime/panel.php');
    if ($msg) {
        $sep = strpos($url, '?') === false ? '?' : '&';
        $url .= $sep . 'notice=' . urlencode($msg) . '&noticeType=' . $type;
    }
    \Typecho\Response::getInstance()->setStatus(302);
    @header('Location: ' . $url);
    exit;
}

function pp_field(int $cid, string $name): string
{
    $db = Db::get();
    $r = $db->fetchRow($db->select('str_value')->from($db->getPrefix() . 'fields')
        ->where('cid = ?', $cid)->where('name = ?', $name)->limit(1));
    return (string)($r['str_value'] ?? '');
}

/** 把上传错误码转成可读文案。 */
function pp_upload_error(int $code): string
{
    switch ($code) {
        case UPLOAD_ERR_INI_SIZE:
        case UPLOAD_ERR_FORM_SIZE:
            return _t('图片过大，超过服务器上传限制（upload_max_filesize / post_max_size）');
        case UPLOAD_ERR_PARTIAL:
            return _t('图片只上传了一部分，请重试');
        case UPLOAD_ERR_NO_FILE:
            return _t('没有收到图片文件');
        case UPLOAD_ERR_NO_TMP_DIR:
            return _t('服务器缺少临时目录，无法上传');
        case UPLOAD_ERR_CANT_WRITE:
            return _t('服务器无法写入临时文件，无法上传');
        case UPLOAD_ERR_EXTENSION:
            return _t('服务器扩展阻止了上传');
        default:
            return _t('上传失败（错误码 ' . $code . '）');
    }
}

function pp_set_field(int $cid, string $name, string $value): void
{
    $db = Db::get();
    $prefix = $db->getPrefix();
    $db->query($db->delete($prefix . 'fields')->where('cid = ?', $cid)->where('name = ?', $name));
    if ($value !== '') {
        $db->query($db->insert($prefix . 'fields')->rows([
            'cid' => $cid, 'name' => $name, 'type' => 'str', 'str_value' => $value,
        ]));
    }
}

function pp_data_file(): string
{
    $dir = __DIR__ . '/data';
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    return $dir;
}

function pp_read_json(string $file): array
{
    return is_file($file) ? (json_decode((string)@file_get_contents($file), true) ?: []) : [];
}

function pp_write_json(string $file, array $data): void
{
    $tmp = $file . '.tmp.' . getmypid();
    if (@file_put_contents($tmp, json_encode($data, JSON_UNESCAPED_UNICODE)) !== false) {
        @rename($tmp, $file);
    }
}

/** 清理 original/full/thumb 下因删除文件而空出的目录（自底向上）。 */
function pp_prune_empty_dirs(): void
{
    foreach (ImageRepository::defaultDirs() as $type => $webDir) {
        $abs = ImageRepository::toAbs($webDir);
        if (!is_dir($abs)) {
            continue;
        }
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($abs, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($it as $file) {
            if (!$file->isDir()) {
                continue;
            }
            $items = @scandir($file->getPathname());
            if ($items && count($items) === 2) { // 仅 . 与 ..
                @rmdir($file->getPathname());
            }
        }
    }
}

/** 提取 EXIF 摘要用于列表展示。 */
function pp_exif_summary(array $exif): string
{
    $parts = [];
    if (!empty($exif['make']) || !empty($exif['model'])) {
        $parts[] = trim((string)($exif['make'] ?? '') . ' ' . (string)($exif['model'] ?? ''));
    }
    if (!empty($exif['iso'])) {
        $parts[] = 'ISO ' . $exif['iso'];
    }
    if (!empty($exif['fnumber'])) {
        $parts[] = 'f/' . $exif['fnumber'];
    }
    if (!empty($exif['exposure'])) {
        $parts[] = $exif['exposure'];
    }
    if (!empty($exif['focal']) || !empty($exif['focal35'])) {
        $parts[] = ($exif['focal35'] ?? $exif['focal']) . 'mm';
    }
    return implode(' · ', $parts);
}

/* ---------------------------------- AJAX 进度 ---------------------------------- */

if (!empty($_GET['ajax'])) {
    $job = (string)($_GET['job'] ?? '');
    set_time_limit(60);
    $result = ['finished' => true, 'total' => 0, 'done' => 0, 'current' => ''];

    if ($job === 'rebuild') {
        $listFile = pp_data_file() . '/rebuild_list.json';
        $jobFile = pp_data_file() . '/job.json';
        $state = pp_read_json($jobFile);
        if (($state['job'] ?? '') !== 'rebuild' || !file_exists($listFile)) {
            $rows = $db->fetchAll($db->select()->from(ImageRepository::table()));
            $list = [];
            foreach ($rows as $r) {
                if (!empty($r['original'])) {
                    $list[] = [$r['id'], $r['original'], $r['full'], $r['thumb']];
                }
            }
            pp_write_json($listFile, $list);
            pp_write_json($jobFile, ['job' => 'rebuild', 'total' => count($list), 'done' => 0, 'current' => '', 'failed' => 0]);
        }
        $list = pp_read_json($listFile);
        $state = pp_read_json($jobFile);
        $idx = (int)($state['done'] ?? 0);
        $failed = (int)($state['failed'] ?? 0);
        $batch = 3;
        $quality = (int)Plugin::opt('infinitytimeQuality', 82);
        $thumbMax = (int)Plugin::opt('infinitytimeThumbMax', 1280);
        $maxWidth = (int)Plugin::opt('infinitytimeMaxWidth', 2560);
        $started = microtime(true);
        $budget = 25; // 单次 AJAX 最多秒数，避免重建拖着后台页面
        $total = count($list);
        for ($i = 0; $i < $batch && $idx < $total; $i++) {
            // 预算不足时立即返回，让下一轮 poll 继续，保证每轮请求都在短时间内完成
            if ((microtime(true) - $started) > $budget) {
                break;
            }
            $item = $list[$idx];
            $src = ImageRepository::toAbs($item[1]);
            if (is_file($src)) {
                try {
                    MediaProcessor::process($src, ImageRepository::toAbs($item[2]), ImageRepository::toAbs($item[3]), $thumbMax, $quality, $maxWidth);
                } catch (\Throwable $e) {
                    Plugin::log('rebuild ajax: id=' . $item[0] . ' ' . $e->getMessage());
                    $failed++;
                }
                $state['current'] = basename($src);
            }
            $idx++;
        }
        $state['done'] = $idx;
        $state['failed'] = $failed;
        $state['finished'] = $idx >= $total;
        if ($state['finished']) {
            $state = ['job' => 'rebuild', 'total' => 0, 'done' => 0, 'current' => '', 'finished' => true, 'failed' => $failed];
        }
        pp_write_json($jobFile, $state);
        $result = ['finished' => $idx >= $total, 'total' => $total, 'done' => $idx, 'current' => $state['current'], 'failed' => $failed];
    } elseif ($job === 'cleanup') {
        $listFile = pp_data_file() . '/cleanup_list.json';
        $jobFile = pp_data_file() . '/job.json';
        $state = pp_read_json($jobFile);
        if (($state['job'] ?? '') !== 'cleanup' || !file_exists($listFile)) {
            $rows = $db->fetchAll($db->select()->from(ImageRepository::table()));
            $ref = [];
            foreach ($rows as $r) {
                foreach (['original', 'full', 'thumb'] as $k) {
                    if (!empty($r[$k])) {
                        $ref[ImageRepository::toAbs($r[$k])] = true;
                    }
                }
            }
            $list = [];
            foreach (ImageRepository::defaultDirs() as $type => $webDir) {
                $abs = ImageRepository::toAbs($webDir);
                if (!is_dir($abs)) {
                    continue;
                }
                $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($abs, \FilesystemIterator::SKIP_DOTS));
                foreach ($it as $file) {
                    if ($file->isFile() && !isset($ref[$file->getPathname()])) {
                        $list[] = $file->getPathname();
                    }
                }
            }
            pp_write_json($listFile, $list);
            pp_write_json($jobFile, ['job' => 'cleanup', 'total' => count($list), 'done' => 0, 'current' => '']);
        }
        $list = pp_read_json($listFile);
        $state = pp_read_json($jobFile);
        $idx = (int)($state['done'] ?? 0);
        $batch = 50;
        for ($i = 0; $i < $batch && $idx < count($list); $i++) {
            @unlink($list[$idx]);
            $state['current'] = basename($list[$idx]);
            $idx++;
        }
        $state['done'] = $idx;
        $state['finished'] = $idx >= count($list);
        if ($state['finished']) {
            pp_prune_empty_dirs();
            $state = ['job' => 'cleanup', 'total' => 0, 'done' => 0, 'current' => '', 'finished' => true];
        }
        pp_write_json($jobFile, $state);
        $result = ['finished' => $idx >= count($list), 'total' => count($list), 'done' => $idx, 'current' => $state['current']];
    }

    header('Content-Type: application/json');
    echo json_encode($result, JSON_UNESCAPED_UNICODE);
    exit;
}

/* ---------------------------------- POST 处理 ---------------------------------- */

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string)($_POST['action'] ?? '');

    if ($action === 'create_album') {
        $title = trim((string)($_POST['title'] ?? ''));
        $address = trim((string)($_POST['address'] ?? ''));
        $device = trim((string)($_POST['device'] ?? ''));
        $tags = trim((string)($_POST['tags'] ?? ''));
        if ($title === '') {
            pp_reply(_t('请填写图集标题'), 'error');
        }
        // When post_max_size is exceeded PHP drops the entire $_FILES array.
        // Report that explicitly instead of silently returning to the panel.
        if (empty($_FILES['files']) && !empty($_SERVER['CONTENT_LENGTH'])) {
            pp_reply(_t('上传内容超过服务器 post_max_size 限制，请调大 post_max_size 后重试'), 'error');
        }
        if (empty($_FILES['files']['name'][0]) || !is_array($_FILES['files']['name'])) {
            pp_reply(_t('请至少选择一张图片'), 'error');
        }

        $now = time();
        $slug = 'album-' . date('YmdHis', $now) . '-' . random_int(100, 999);
        $cid = (int)$db->query($db->insert($prefix . 'contents')->rows([
            'title' => $title, 'slug' => $slug, 'created' => $now, 'modified' => $now,
            'text' => '', 'authorId' => $user->uid, 'type' => 'post', 'status' => 'publish',
            'allowComment' => '0', 'allowPing' => '0', 'allowFeed' => '0', 'template' => '', 'password' => '',
        ]));

        $imgs = []; $thumbs = []; $exifs = []; $addrs = []; $titles = []; $descs = [];
        $firstExif = null; $index = 0; $fail = 0;
        $quality = (int)Plugin::opt('infinitytimeQuality', 82);
        $thumbMax = (int)Plugin::opt('infinitytimeThumbMax', 1280);
        $maxWidth = (int)Plugin::opt('infinitytimeMaxWidth', 2560);
        $keep = (bool)Plugin::opt('infinitytimeKeepOriginal', '1');

        $count = count($_FILES['files']['name']);
        // 逐图标题/描述（与 files[] 同序，后端据此写入每张图的 title/desc）
        $postTitles = (array)($_POST['img_titles'] ?? []);
        $postDescs = (array)($_POST['img_descs'] ?? []);
        $uploadErr = 0;
        for ($i = 0; $i < $count; $i++) {
            $fe = (int)($_FILES['files']['error'][$i] ?? UPLOAD_ERR_NO_FILE);
            if ($fe !== UPLOAD_ERR_OK) {
                if ($uploadErr === 0) {
                    $uploadErr = $fe;
                }
                $fail++;
                continue;
            }
            $meta = ImageRepository::ingest([
                'name' => $_FILES['files']['name'][$i],
                'tmp_name' => $_FILES['files']['tmp_name'][$i],
                'size' => $_FILES['files']['size'][$i] ?? 0,
                'error' => $_FILES['files']['error'][$i] ?? UPLOAD_ERR_OK,
            ], ['quality' => $quality, 'thumb_max' => $thumbMax, 'max_width' => $maxWidth, 'keep_original' => $keep, 'address' => $address]);
            if (!$meta) {
                $fail++;
                continue;
            }
            $rowId = ImageRepository::insertRow($cid, $meta, $index);
            $imgTitle = isset($postTitles[$i]) ? trim((string)$postTitles[$i]) : '';
            $imgDesc = isset($postDescs[$i]) ? trim((string)$postDescs[$i]) : '';
            if ($imgTitle !== '' || $imgDesc !== '') {
                ImageRepository::setImageMeta($rowId, $imgTitle, $imgDesc, (string)$address);
            }
            $imgs[] = $meta['full']; $thumbs[] = $meta['thumb']; $exifs[] = $meta['exif']; $addrs[] = $address;
            $titles[] = $imgTitle; $descs[] = $imgDesc;
            if ($firstExif === null) {
                $firstExif = $meta['exif'];
            }
            $index++;
        }

        if (empty($imgs)) {
            ImageRepository::removeFor($cid);
            $db->query($db->delete($prefix . 'contents')->where('cid = ?', $cid));
            $reason = trim((string)(ImageRepository::$lastError ?? ''));
            $msg = $uploadErr !== 0
                ? pp_upload_error($uploadErr)
                : ($reason !== '' ? $reason : _t('没有图片成功入库（可能格式不支持或缺少转换工具）'));
            pp_reply($msg, 'error');
        }

        foreach ([
            'img' => implode("\n", $imgs),
            'thumb' => implode("\n", $thumbs),
            'exif' => json_encode($exifs, JSON_UNESCAPED_UNICODE),
            'addresses' => json_encode($addrs, JSON_UNESCAPED_UNICODE),
            'titles' => json_encode($titles, JSON_UNESCAPED_UNICODE),
            'descs' => json_encode($descs, JSON_UNESCAPED_UNICODE),
            'device' => $device !== '' ? $device : trim((string)($firstExif['make'] ?? '') . ' ' . (string)($firstExif['model'] ?? '')),
            'location' => $address,
        ] as $name => $val) {
            pp_set_field($cid, $name, $val);
        }
        if ($tags !== '') {
            pp_set_field($cid, 'tags', $tags);
        }
        pp_reply(sprintf(_t('已发布图集「%s」，共 %d 张图片'), $title, count($imgs)));
    }

    if ($action === 'update_album') {
        $cid = (int)($_POST['cid'] ?? 0);
        if ($cid > 0) {
            $title = trim((string)($_POST['title'] ?? ''));
            if ($title !== '') {
                $db->query($db->update($prefix . 'contents')->rows(['title' => $title])->where('cid = ?', $cid));
            }
            pp_set_field($cid, 'device', trim((string)($_POST['device'] ?? '')));
            pp_set_field($cid, 'tags', trim((string)($_POST['tags'] ?? '')));
            pp_set_field($cid, 'location', trim((string)($_POST['address'] ?? '')));
            pp_reply(_t('已更新图集信息'));
        }
    }

    if ($action === 'set_image_meta') {
        $rowId = (int)($_POST['rowId'] ?? 0);
        $title = trim((string)($_POST['title'] ?? ''));
        $desc = trim((string)($_POST['desc'] ?? ''));
        $addr = trim((string)($_POST['address'] ?? ''));
        if ($rowId > 0) {
            $row = $db->fetchRow($db->select('cid')->from(ImageRepository::table())->where('id = ?', $rowId)->limit(1));
            ImageRepository::setImageMeta($rowId, $title, $desc, $addr);
            if ($row && (int)$row['cid'] > 0) {
                ImageRepository::syncPostFields((int)$row['cid']);
            }
        }
        pp_reply(_t('已保存图片信息'));
    }

    if ($action === 'delete_image') {
        $rowId = (int)($_POST['rowId'] ?? 0);
        if ($rowId > 0) {
            $row = $db->fetchRow($db->select()->from(ImageRepository::table())->where('id = ?', $rowId)->limit(1));
            if ($row) {
                ImageRepository::unlinkFiles($row['original'], $row['full'], $row['thumb']);
                $db->query($db->delete(ImageRepository::table())->where('id = ?', $rowId));
            }
        }
        pp_reply(_t('已删除该图片'));
    }

    if ($action === 'save_settings') {
        Plugin::setOption('infinitytimeQuality', max(1, min(100, (int)($_POST['quality'] ?? 82))));
        Plugin::setOption('infinitytimeThumbMax', max(200, min(4096, (int)($_POST['thumbMax'] ?? 1280))));
        Plugin::setOption('infinitytimeMaxWidth', max(0, min(20000, (int)($_POST['maxWidth'] ?? 0))));
        Plugin::setOption('infinitytimeKeepOriginal', ($_POST['keepOriginal'] ?? '1') === '1' ? '1' : '0');
        pp_reply(_t('已保存 WebP 转换设置'));
    }

    if ($action === 'delete_album') {
        $cid = (int)($_POST['cid'] ?? 0);
        if ($cid > 0) {
            ImageRepository::removeFor($cid);
            $db->query($db->delete($prefix . 'contents')->where('cid = ?', $cid));
            $db->query($db->delete($prefix . 'fields')->where('cid = ?', $cid));
        }
        pp_reply(_t('已删除图集及其图片文件'));
    }

    if ($action === 'save_site') {
        Plugin::setOption('infinitytimeSiteLogo', trim((string)($_POST['siteLogo'] ?? '')));
        Plugin::setOption('infinitytimeSiteName', trim((string)($_POST['siteName'] ?? '')));
        Plugin::setOption('infinitytimeSiteTagline', trim((string)($_POST['siteTagline'] ?? '')));
        Plugin::setOption('infinitytimeAbout', trim((string)($_POST['aboutText'] ?? '')));
        pp_reply(_t('已保存站点信息'));
    }

    if ($action === 'save_contacts') {
        $names = (array)($_POST['contactName'] ?? []);
        $urls = (array)($_POST['contactUrl'] ?? []);
        $icons = (array)($_POST['contactIcon'] ?? []);
        $status = (array)($_POST['contactStatus'] ?? []);
        $contacts = [];
        foreach ($names as $i => $name) {
            $name = trim((string)$name);
            $url = trim((string)($urls[$i] ?? ''));
            if ($name === '' && $url === '') {
                continue;
            }
            $contacts[] = [
                'name' => $name,
                'url' => $url,
                'icon' => trim((string)($icons[$i] ?? '')) ?: 'icon-shouye',
                'enabled' => (($status[$i] ?? '1') == '1'),
            ];
        }
        Plugin::setOption('infinitytimeContacts', json_encode($contacts, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        pp_reply(_t('已保存联系方式'));
    }

    pp_reply(_t('未知操作'), 'error');
}

/* ---------------------------------- 数据准备 ---------------------------------- */

function pp_albums(string $prefix): array
{
    $db = Db::get();
    $rows = $db->fetchAll($db->select('cid', 'title')->from($prefix . 'contents')
        ->where('type = ?', 'post')->where('status = ?', 'publish')->order('created', Db::SORT_DESC));
    if (!$rows) {
        return [];
    }

    // 一次性取回所有图集的 img/device/tags/location 字段，避免 N+1 查询
    $cids = array_map(fn($r) => (int)$r['cid'], $rows);
    $markers = implode(',', array_fill(0, count($cids), '?'));
    $all = $db->fetchAll(
        $db->select('cid', 'name', 'str_value')->from($prefix . 'fields')
            ->where('cid IN (' . $markers . ')', ...$cids)
            ->where("name IN ('img','device','tags','location')")
    );
    $byCid = [];
    foreach ($all as $f) {
        $byCid[(int)$f['cid']][$f['name']] = $f['str_value'];
    }

    $out = [];
    foreach ($rows as $r) {
        $cid = (int)$r['cid'];
        $img = $byCid[$cid]['img'] ?? '';
        if ($img !== '') {
            $r['img_count'] = count(array_filter(explode("\n", $img)));
            $r['device'] = $byCid[$cid]['device'] ?? '';
            $r['tags'] = $byCid[$cid]['tags'] ?? '';
            $r['location'] = $byCid[$cid]['location'] ?? '';
            $out[] = $r;
        }
    }
    return $out;
}

$notice = $options->request->get('notice');
$noticeType = $options->request->get('noticeType', 'success');
$albums = pp_albums($prefix);
$quality = (int)Plugin::opt('infinitytimeQuality', 82);
$thumbMax = (int)Plugin::opt('infinitytimeThumbMax', 1280);
$maxWidth = (int)Plugin::opt('infinitytimeMaxWidth', 2560);
$keepOriginal = (bool)Plugin::opt('infinitytimeKeepOriginal', '1');
$tools = MediaProcessor::detectTools();
$siteLogo = (string)Plugin::opt('infinitytimeSiteLogo', '');
$siteName = (string)Plugin::opt('infinitytimeSiteName', '');
$siteTagline = (string)Plugin::opt('infinitytimeSiteTagline', '');
$aboutText = (string)Plugin::opt('infinitytimeAbout', '');
$contacts = json_decode((string)Plugin::opt('infinitytimeContacts', '[]'), true) ?: [];

/* ---------------------------------- 视图 ---------------------------------- */
$adminDir = dirname($_SERVER['SCRIPT_FILENAME']);
include $adminDir . '/header.php';
include $adminDir . '/menu.php';
?>
<main class="main">
  <div class="container typecho-page-main">
    <?php if ($notice): ?>
      <div class="notice <?php echo $noticeType === 'error' ? 'error' : 'success'; ?>" style="margin:12px 0">
        <?php echo htmlspecialchars($notice); ?>
      </div>
    <?php endif; ?>

    <link rel="stylesheet" href="<?php echo htmlspecialchars(rtrim((string)$options->siteUrl, '/') . '/usr/themes/' . rawurlencode((string)$options->theme) . '/assets/css/iconfont.css'); ?>">
    <style>
      .pp-wrap{max-width:1000px}
      .pp-card{background:#fff;border:1px solid #F0F0EC;border-radius:2px;padding:18px 20px;margin-bottom:18px}
      .pp-card>h2{font-size:1.14286em;margin:0 0 1em;padding-bottom:.6em;border-bottom:1px solid #F0F0EC;font-weight:bold;color:#444}
      .pp-grid{display:grid;grid-template-columns:1fr 1fr;gap:0 28px}
      .pp-meta{font-size:12px;color:#999;line-height:1.7}
      .pp-row{display:grid;grid-template-columns:150px 1fr;gap:8px 16px;align-items:center;margin:12px 0}
      .pp-row>label{font-size:13px;color:#444;text-align:left}
      .pp-row .pp-col{display:flex;align-items:center;gap:8px;flex-wrap:wrap}
      .pp-row input[type=text],.pp-row input[type=number],.pp-row select,.pp-row textarea{width:100%;box-sizing:border-box;padding:7px 9px;border:1px solid #D9D9D6;border-radius:2px;font-size:14px;background:#fff;height:34px}
      .pp-row textarea{min-height:64px;height:auto}
      .pp-row select{height:34px}
      .pp-row input:focus,.pp-row select:focus,.pp-row textarea:focus{outline:none;border-color:#467B96;box-shadow:0 0 0 3px rgba(70,123,150,.12)}
      .pp-row .pp-hint{font-size:12px;color:#999;white-space:nowrap}
      .pp-row .pp-range{flex:1;min-width:120px;accent-color:#467B96}
      .pp-row output.pp-hint{flex:0 0 auto;min-width:34px;text-align:right}
      .pp-btn{display:inline-flex;align-items:center;justify-content:center;background-color:#467B96;color:#fff;border:0;padding:0 14px;height:32px;border-radius:2px;cursor:pointer;font-size:14px;line-height:1.4}
      .pp-btn:hover{background-color:#3c6a81}
      .pp-btn.gray{background-color:#E9E9E6;color:#666}
      .pp-btn.gray:hover{background-color:#dbdbd6}
      .pp-btn.red{background-color:#B94A48;color:#fff}
      .pp-btn.red:hover{background-color:#a4403f}
      .pp-small{height:25px;padding:0 10px;font-size:13px}
      .pp-note{background:#F6F6F3;border:1px solid #ECECEC;border-radius:2px;padding:12px 16px;margin-top:16px}
      .pp-note .pp-tools{margin:6px 0}
      .pp-note .pp-warn{color:#B94A48;margin-top:6px}
      .pp-tools span{display:inline-flex;align-items:center;gap:4px;background:#D8E7EE;color:#467B96;border-radius:20px;padding:3px 10px;margin:0 6px 6px 0;font-size:12px}
      .pp-tools .ok{background:#E6EFC2;color:#264409}
      .pp-tools .no{background:#FBE3E4;color:#8A1F11}
      .pp-album{border:1px solid #F0F0EC;border-radius:2px;padding:16px;margin-bottom:18px;background:#fff}
      .pp-album-head{display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;margin-bottom:6px}
      .pp-album-head strong{font-size:1.05em;color:#444;font-weight:bold}
      .pp-album-actions{display:flex;align-items:center;gap:10px;flex-wrap:wrap}
      .pp-album>summary{list-style:none;cursor:pointer;display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;font-size:1.05em;font-weight:bold;color:#444}
      .pp-album>summary::-webkit-details-marker{display:none}
      .pp-album>summary::after{content:'▸ 展开';font-size:12px;font-weight:normal;color:#467B96;flex:0 0 auto}
      .pp-album[open]>summary::after{content:'▾ 收起'}
      .pp-album-toolbar{display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;border-top:1px solid #F0F0EC;padding-top:12px;margin-top:12px}
      .pp-album-edit{margin-top:10px}
      .pp-thumbs{display:grid;grid-template-columns:repeat(auto-fill,minmax(220px,1fr));gap:16px;margin-top:12px}
      .pp-img{background:#fff;border:1px solid #E9E9E6;border-radius:2px;padding:10px;display:flex;flex-direction:column;gap:6px}
      .pp-img>img{width:100%;height:200px;object-fit:cover;border-radius:2px;background:#F0F0EC;display:block}
      .pp-img .cap{font-size:12px;color:#777;line-height:1.6;word-break:break-word;white-space:normal}
      .pp-img .dims{font-size:11px;color:#999}
      .pp-img .addr-label{font-size:11px;color:#999}
      .pp-img input[type=text]{width:100%;box-sizing:border-box;padding:5px 7px;font-size:12px;border:1px solid #D9D9D6;border-radius:2px;margin:2px 0}
      .pp-img textarea{width:100%;box-sizing:border-box;padding:5px 7px;font-size:12px;border:1px solid #D9D9D6;border-radius:2px;margin:2px 0;resize:vertical;min-height:40px}
      .pp-img input[type=text]:focus{outline:none;border-color:#467B96;box-shadow:0 0 0 3px rgba(70,123,150,.12)}
      .pp-img form{margin:0}
      .pp-img .pp-btn{width:100%}
      .pp-progress{display:flex;align-items:center;gap:10px;margin-top:12px}
      .pp-bar-outer{flex:1;height:8px;background:#E9E9E6;border-radius:4px;overflow:hidden}
      .pp-bar{height:100%;width:0;background:#467B96;transition:width .2s}
      .pp-msg{font-size:12px;color:#999;min-width:90px}
      .pp-maintain{display:grid;grid-template-columns:1fr 1fr;gap:18px}
      .pp-maintain>div{background:#F6F6F3;border:1px solid #ECECEC;border-radius:2px;padding:14px 16px}
      .pp-maintain .pp-btn{margin-bottom:8px}
      .pp-foot{margin-top:16px}
      details{margin-top:12px}
      summary{cursor:pointer;color:#467B96;font-size:13px;margin-bottom:6px}
      .pp-contact-row{display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin:8px 0;background:#F6F6F3;border:1px solid #ECECEC;border-radius:2px;padding:8px}
      .pp-contact-row>input[type=text],.pp-contact-row>input[type=url]{flex:1 1 150px;min-width:120px;box-sizing:border-box;padding:6px 8px;border:1px solid #D9D9D6;border-radius:2px;font-size:13px}
      .pp-contact-row>select{flex:0 0 80px;height:30px}
      .pp-contact-row>.pp-btn{flex:0 0 auto}
      .pp-contact-icon{position:relative;flex:0 0 auto}
      .pp-icon-trigger{width:32px;height:32px;border:1px solid #D9D9D6;border-radius:2px;background:#fff;cursor:pointer;display:flex;align-items:center;justify-content:center;color:#444;font-size:16px}
      .pp-icon-trigger:hover{border-color:#467B96}
      .pp-icon-pop{display:none;position:absolute;top:36px;left:0;z-index:20;width:200px;padding:8px;background:#fff;border:1px solid #D9D9D6;border-radius:4px;box-shadow:0 8px 24px rgba(16,24,32,.18);grid-template-columns:repeat(4,1fr);gap:6px}
      .pp-icon-pop.open{display:grid}
      .pp-icon-pop .icn{width:100%;height:34px;border:1px solid transparent;background:#F6F6F3;border-radius:3px;cursor:pointer;color:#444;font-size:15px;display:flex;align-items:center;justify-content:center}
      .pp-icon-pop .icn:hover{border-color:#467B96;background:#edf1f4}
      .pp-icon-pop .icn.active{border-color:#467B96;background:#e3eaf0;color:#467B96}
      /* 上传预览：已选文件卡片（大图预览 + 逐图标题/描述） */
      #pp-upload-previews{display:grid;grid-template-columns:repeat(auto-fill,minmax(220px,1fr));gap:12px;margin-top:12px}
      .pp-up-item{position:relative;background:#fff;border:1px solid #E3E3E0;border-radius:6px;overflow:hidden;padding:8px;box-sizing:border-box;display:flex;flex-direction:column;gap:6px;box-shadow:0 1px 3px rgba(0,0,0,.06)}
      .pp-up-thumb{width:100%;height:170px;object-fit:contain;border-radius:4px;background:#F6F6F3}
      .pp-up-remove{position:absolute;top:6px;right:6px;width:22px;height:22px;border-radius:50%;border:0;background:rgba(0,0,0,.55);color:#fff;font-size:15px;line-height:22px;text-align:center;cursor:pointer;z-index:2}
      .pp-up-remove:hover{background:#d33}
      .pp-up-name{font-size:12px;color:#777;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
      .pp-up-tit,.pp-up-desc{width:100%;box-sizing:border-box;padding:5px 7px;border:1px solid #D9D9D6;border-radius:3px;font-size:13px;font-family:inherit}
      .pp-up-tit{height:30px}
      .pp-up-desc{min-height:56px;resize:vertical;line-height:1.5}
      .pp-up-item.touched .pp-up-tit,.pp-up-item.touched .pp-up-desc{border-color:#467B96}
    </style>

    <div class="pp-wrap">
      <div class="typecho-page-title"><h2>InfinityTime 图片分享</h2></div>
      <!-- 上传发布 -->
      <div class="pp-card">
        <h2>上传并发布图集</h2>
        <form id="pp-upload-form" method="post" enctype="multipart/form-data" action="<?php echo htmlspecialchars(Helper::url('InfinityTime/panel.php')); ?>">
          <input type="hidden" name="action" value="create_album">
          <div class="pp-grid">
            <div>
              <div class="pp-row"><label>图集标题 *</label><input type="text" name="title" required></div>
              <div class="pp-row"><label>拍摄设备</label><input type="text" name="device" placeholder="如 Sony A7M4 / iPhone 17 Pro（留空取首图 EXIF）"></div>
              <div class="pp-row"><label>地点 / 地址</label><input type="text" name="address" placeholder="如 福建 福州 三坊七巷（手动填写）"></div>
              <div class="pp-row"><label>标签</label><input type="text" name="tags" placeholder="如 城市,夜景"></div>
            </div>
            <div>
              <div class="pp-row" style="display:flex;flex-wrap:wrap;align-items:center;gap:8px 12px">
                <label style="flex:0 0 auto;min-width:0">选择图片</label>
                <input type="file" id="pp-files-input" name="files[]" multiple accept=".jpg,.jpeg,.png,.gif,.webp,.heic,.heif,.avif" required style="flex:0 1 auto">
                <span class="pp-meta" style="flex-basis:100%">多选即可；每张自动转 WebP 全图 + 缩略图，并按设置保留原图。选择后可逐张填写标题/描述。</span>
              </div>
            </div>
          </div>
          <!-- 已选图片预览：整行自适应网格，避免把两列表单撑得高低不平 -->
          <div id="pp-upload-previews"></div>
          <button class="pp-btn" style="margin-top:10px" type="submit">发布图集</button>
        </form>
        <script>
        (function () {
          var form = document.getElementById('pp-upload-form');
          var input = document.getElementById('pp-files-input');
          var wrap = document.getElementById('pp-upload-previews');
          if (!form || !input || !wrap) return;

          // 用 JS 数组持有已选文件与逐图标题/描述，保证移除后与 files[] 严格对齐
          var sel = [];
          var submitBtn = form.querySelector('button[type=submit]');

          function render() {
            wrap.innerHTML = '';
            sel.forEach(function (item, idx) {
              var card = document.createElement('div');
              card.className = 'pp-up-item';

              var thumb = document.createElement('img');
              thumb.className = 'pp-up-thumb';
              thumb.alt = item.file.name;
              thumb.src = URL.createObjectURL(item.file);

              var rm = document.createElement('button');
              rm.type = 'button';
              rm.className = 'pp-up-remove';
              rm.textContent = '×';
              rm.title = '移除这张图片';
              rm.addEventListener('click', function () {
                sel.splice(idx, 1);
                syncInput();
                render();
              });

              var name = document.createElement('div');
              name.className = 'pp-up-name';
              name.textContent = item.file.name;

              var tit = document.createElement('input');
              tit.type = 'text';
              tit.className = 'pp-up-tit';
              tit.placeholder = '图片标题（可选）';
              tit.value = item.title;
              tit.addEventListener('input', function () {
                item.title = tit.value;
                card.classList.add('touched');
              });

              var desc = document.createElement('textarea');
              desc.rows = 2;
              desc.className = 'pp-up-desc';
              desc.placeholder = '图片描述（可选）';
              desc.value = item.desc;
              desc.addEventListener('input', function () {
                item.desc = desc.value;
                card.classList.add('touched');
              });

              card.appendChild(thumb);
              card.appendChild(rm);
              card.appendChild(name);
              card.appendChild(tit);
              card.appendChild(desc);
              wrap.appendChild(card);
            });
          }

          function fileKey(f) {
            return f.name + '|' + f.size + '|' + (f.lastModified || 0);
          }
          // 把受控的 sel 同步回文件输入，使原生提交发送的就是当前选区（支持删除后仍对齐）。
          function syncInput() {
            try {
              var dt = new DataTransfer();
              sel.forEach(function (item) { dt.items.add(item.file); });
              input.files = dt.files;
            } catch (e) { /* 不支持 DataTransfer 时保持原选择（完整上传） */ }
          }
          input.addEventListener('change', function () {
            // 追加而非替换：后续选择应加进序列，而不是清空前序；同名同尺寸去重
            var existing = {};
            sel.forEach(function (item) { existing[fileKey(item.file)] = true; });
            Array.prototype.forEach.call(input.files, function (f) {
              var k = fileKey(f);
              if (existing[k]) return;
              existing[k] = true;
              sel.push({ file: f, title: '', desc: '' });
            });
            syncInput();
            render();
          });

          form.addEventListener('submit', function (e) {
            // 不拦截默认提交：让浏览器走原生 multipart POST，后端 302 后由浏览器自然跳回面板并显示 notice。
            var olds = form.querySelectorAll('input[name="img_titles[]"], textarea[name="img_descs[]"]');
            for (var i = 0; i < olds.length; i++) { olds[i].parentNode.removeChild(olds[i]); }
            sel.forEach(function (item) {
              var t = document.createElement('input');
              t.type = 'hidden'; t.name = 'img_titles[]'; t.value = item.title || '';
              form.appendChild(t);
              var d = document.createElement('textarea');
              d.name = 'img_descs[]'; d.value = item.desc || ''; d.style.display = 'none';
              form.appendChild(d);
            });
            syncInput();
            if (submitBtn) { submitBtn.disabled = true; submitBtn.textContent = '发布中…'; }
            // 原生提交：无需手动跳转，浏览器会跟随 302 回到带 notice 的面板。
          });
        })();
        </script>
      </div>
      <!-- 站点信息 / 关于 -->
      <div class="pp-card">
        <h2>站点信息 / 关于</h2>
        <form method="post" action="<?php echo htmlspecialchars(Helper::url('InfinityTime/panel.php')); ?>">
          <input type="hidden" name="action" value="save_site">
          <div class="pp-row"><label>头像 / 站点图标</label><input type="text" name="siteLogo" value="<?php echo htmlspecialchars($siteLogo); ?>" placeholder="图片 URL，如 https://.../avatar.webp"></div>
          <div class="pp-row"><label>站点名称</label><input type="text" name="siteName" value="<?php echo htmlspecialchars($siteName); ?>" placeholder="首页左下角名称 + 页脚「关于」标题"></div>
          <div class="pp-row"><label>一句话说明</label><input type="text" name="siteTagline" value="<?php echo htmlspecialchars($siteTagline); ?>" placeholder="首页名称下方的副标题 / 底栏说明"></div>
          <div class="pp-row"><label>关于介绍</label><textarea name="aboutText" rows="4" placeholder="页脚「关于」区的介绍，支持 HTML"><?php echo htmlspecialchars($aboutText); ?></textarea></div>
          <div class="pp-note"><span class="pp-meta">留空则使用 InfinityTime 主题自带设置；填写后覆盖前台首页左下角头像/名称/说明，以及页脚「关于」介绍。</span></div>
          <div class="pp-foot"><button class="pp-btn" type="submit">保存站点信息</button></div>
        </form>
      </div>
      <!-- 联系方式 / 联系我 -->
      <div class="pp-card">
        <h2>联系方式 / 联系我</h2>
        <form method="post" action="<?php echo htmlspecialchars(Helper::url('InfinityTime/panel.php')); ?>">
          <input type="hidden" name="action" value="save_contacts">
          <div class="pp-note"><span class="pp-meta">加一个填一个；「状态」选“停用”即在前台隐藏（数据保留）。点击图标按钮即可选择联系方式图标。</span></div>
          <div id="pp-contact-rows">
            <?php if (empty($contacts)): ?>
              <div class="pp-meta" id="pp-contact-empty">暂无联系方式，点击下方“添加”。</div>
            <?php endif; ?>
            <?php foreach ($contacts as $c): ?>
              <div class="pp-contact-row">
                <input type="text" name="contactName[]" value="<?php echo htmlspecialchars((string)($c['name'] ?? '')); ?>" placeholder="名称（如 微博）">
                <input type="url" name="contactUrl[]" value="<?php echo htmlspecialchars((string)($c['url'] ?? '')); ?>" placeholder="链接">
                <div class="pp-contact-icon">
                  <input type="hidden" name="contactIcon[]" value="<?php echo htmlspecialchars((string)($c['icon'] ?? 'icon-github')); ?>">
                  <button type="button" class="pp-icon-trigger" data-icon="<?php echo htmlspecialchars((string)($c['icon'] ?? 'icon-github')); ?>" title="选择图标"><i class="iconfont <?php echo htmlspecialchars((string)($c['icon'] ?? 'icon-github')); ?>"></i></button>
                  <div class="pp-icon-pop"></div>
                </div>
                <select name="contactStatus[]">
                  <option value="1" <?php echo !empty($c['enabled']) ? 'selected' : ''; ?>>启用</option>
                  <option value="0" <?php echo empty($c['enabled']) ? 'selected' : ''; ?>>停用</option>
                </select>
                <button type="button" class="pp-btn red pp-small pp-remove-contact">删除</button>
              </div>
            <?php endforeach; ?>
          </div>
          <button type="button" id="pp-add-contact" class="pp-btn gray pp-small" style="margin-top:10px">添加联系方式</button>
          <div class="pp-foot"><button class="pp-btn" type="submit">保存联系方式</button></div>
        </form>
      </div>
      <!-- 转换设置 -->
      <div class="pp-card">
        <h2>WebP 转换设置</h2>
        <form method="post" action="<?php echo htmlspecialchars(Helper::url('InfinityTime/panel.php')); ?>">
          <input type="hidden" name="action" value="save_settings">
          <div class="pp-row"><label>WebP 质量</label>
            <div class="pp-col">
              <input type="range" name="quality" id="pp-quality" class="pp-range" min="1" max="100" step="1" value="<?php echo $quality; ?>">
              <output id="pp-quality-out" class="pp-hint" for="pp-quality"><?php echo $quality; ?></output>
            </div>
          </div>
          <div class="pp-row"><label>缩略图最长边</label><input type="number" name="thumbMax" min="200" max="4096" value="<?php echo $thumbMax; ?>"></div>
          <div class="pp-row"><label>全图最长边上限</label><div class="pp-col"><input type="number" name="maxWidth" min="0" max="20000" value="<?php echo $maxWidth; ?>"><span class="pp-hint">0=不裁剪</span></div></div>
          <div class="pp-row"><label>保留原图</label>
            <select name="keepOriginal">
              <option value="1" <?php echo $keepOriginal ? 'selected' : ''; ?>>保留（original/）</option>
              <option value="0" <?php echo !$keepOriginal ? 'selected' : ''; ?>>不保留（省空间）</option>
            </select>
          </div>
          <div class="pp-note">
            <div class="pp-meta">转换工具：</div>
            <div class="pp-tools">
              <span class="<?php echo empty($tools['imagick']) ? 'no' : 'ok'; ?>">PHP Imagick <?php echo empty($tools['imagick']) ? '✗' : '✓'; ?></span>
              <span class="<?php echo empty($tools['magick']) ? 'no' : 'ok'; ?>">ImageMagick <?php echo empty($tools['magick']) ? '✗' : '✓'; ?></span>
              <span class="<?php echo empty($tools['heif']) ? 'no' : 'ok'; ?>">heif-convert <?php echo empty($tools['heif']) ? '✗' : '✓'; ?></span>
              <span class="ok">GD WebP ✓</span>
            </div>
            <?php if (empty($tools['imagick']) && empty($tools['magick']) && empty($tools['heif'])): ?>
              <div class="pp-warn">当前环境缺少 HEIC 解码工具。服务器请安装 imagemagick+libheif 或 heif-convert；JPG/PNG 不受影响。</div>
            <?php endif; ?>
            <div class="pp-meta" style="margin-top:6px">改动后对「已发布图集」重新点击下方「重建缩略图」即可批量重做。</div>
          </div>
          <div class="pp-foot">
            <button class="pp-btn gray pp-small" type="button" id="pp-reset-webp">恢复默认最佳设置</button>
            <button class="pp-btn" type="submit">保存设置</button>
          </div>
        </form>
      </div>

      <!-- 图集列表 -->
      <div class="pp-card">
        <h2>已发布图集</h2>
        <?php if (!$albums): ?>
          <div class="pp-meta">暂无图集。</div>
        <?php else:
            $imagesByCid = ImageRepository::rowsForCids(array_column($albums, 'cid'));
            foreach ($albums as $al): $images = $imagesByCid[(int)$al['cid']] ?? []; ?>
          <details class="pp-album">
            <summary class="pp-album-summary">
              <strong><?php echo htmlspecialchars($al['title']); ?>
                <span class="pp-meta">（<?php echo count($images) ?: $al['img_count']; ?> 张）</span>
              </strong>
            </summary>
            <div class="pp-album-toolbar">
              <span class="pp-meta">共 <?php echo count($images) ?: $al['img_count']; ?> 张</span>
              <span class="pp-album-actions">
                <a class="pp-meta" target="_blank" href="<?php echo htmlspecialchars(Helper::url('index.php', $options->siteUrl)); ?>">前台查看</a>
                <form method="post" style="display:inline" onsubmit="return confirm('删除整组图集及其文件？')">
                  <input type="hidden" name="action" value="delete_album">
                  <input type="hidden" name="cid" value="<?php echo $al['cid']; ?>">
                  <button class="pp-btn red pp-small" type="submit">删除</button>
                </form>
              </span>
            </div>

            <details class="pp-album-edit">
              <summary>编辑图集信息</summary>
              <form method="post" action="<?php echo htmlspecialchars(Helper::url('InfinityTime/panel.php')); ?>">
                <input type="hidden" name="action" value="update_album">
                <input type="hidden" name="cid" value="<?php echo $al['cid']; ?>">
                <div class="pp-grid" style="margin-top:10px">
                  <div class="pp-row"><label>标题</label><input type="text" name="title" value="<?php echo htmlspecialchars($al['title']); ?>"></div>
                  <div class="pp-row"><label>设备</label><input type="text" name="device" value="<?php echo htmlspecialchars($al['device']); ?>"></div>
                  <div class="pp-row"><label>标签</label><input type="text" name="tags" value="<?php echo htmlspecialchars($al['tags']); ?>"></div>
                  <div class="pp-row"><label>地点 / 地址</label><input type="text" name="address" value="<?php echo htmlspecialchars($al['location']); ?>"></div>
                </div>
                <button class="pp-btn" type="submit" style="margin-top:6px">保存</button>
              </form>
            </details>

            <div class="pp-thumbs">
              <?php foreach ($images as $img): ?>
                <div class="pp-img">
                  <img src="<?php echo htmlspecialchars(ImageRepository::toWeb(ImageRepository::toAbs($img['thumb']))); ?>" alt="">
                  <div class="cap"><?php echo htmlspecialchars(pp_exif_summary($img['exif'])); ?></div>
                  <div class="dims"><?php echo $img['width']; ?>×<?php echo $img['height']; ?></div>
                  <form method="post" action="<?php echo htmlspecialchars(Helper::url('InfinityTime/panel.php')); ?>">
                    <input type="hidden" name="action" value="set_image_meta">
                    <input type="hidden" name="rowId" value="<?php echo $img['id']; ?>">
                    <label class="addr-label">图片标题</label>
                    <input type="text" name="title" value="<?php echo htmlspecialchars($img['title'] ?? ''); ?>" placeholder="图片标题（可选）">
                    <label class="addr-label">图片描述</label>
                    <textarea name="desc" rows="2" placeholder="图片描述（可选）"><?php echo htmlspecialchars($img['desc'] ?? ''); ?></textarea>
                    <label class="addr-label">拍摄地址</label>
                    <input type="text" name="address" value="<?php echo htmlspecialchars($img['address']); ?>" placeholder="写地址">
                    <button class="pp-btn gray" type="submit">保存图片信息</button>
                  </form>
                  <form method="post" action="<?php echo htmlspecialchars(Helper::url('InfinityTime/panel.php')); ?>" onsubmit="return confirm('删除这张图片及其文件？')">
                    <input type="hidden" name="action" value="delete_image">
                    <input type="hidden" name="rowId" value="<?php echo $img['id']; ?>">
                    <button class="pp-btn red" type="submit">删除</button>
                  </form>
                </div>
              <?php endforeach; ?>
            </div>
          </details>
        <?php endforeach; endif; ?>
      </div>

      <!-- 维护 -->
      <div class="pp-card">
        <h2>维护</h2>
        <div class="pp-maintain">
          <div>
            <button class="pp-btn gray" type="button" data-run="cleanup">清理孤儿文件</button>
            <div class="pp-progress"><div class="pp-bar-outer"><div class="pp-bar" id="pp-bar-cleanup"></div></div><span class="pp-msg" id="pp-msg-cleanup"></span></div>
            <div class="pp-meta" style="margin-top:8px">删除所有不被任何图集引用的 original/full/thumb 文件，并清理空目录。</div>
          </div>
          <div>
            <button class="pp-btn gray" type="button" data-run="rebuild">重建缩略图/全图</button>
            <div class="pp-progress"><div class="pp-bar-outer"><div class="pp-bar" id="pp-bar-rebuild"></div></div><span class="pp-msg" id="pp-msg-rebuild"></span></div>
            <div class="pp-meta" style="margin-top:8px">按当前质量/尺寸设置，用原图重新生成全部 full/thumb（改设置后批量重做）。</div>
          </div>
        </div>
      </div>
    </div>
  </div>
</main>
<script>
function runJob(job) {
  var bar = document.getElementById('pp-bar-' + job);
  var msg = document.getElementById('pp-msg-' + job);
  var btn = document.querySelector('[data-run="' + job + '"]');
  if (btn) btn.disabled = true;
  var url = <?php echo json_encode(Helper::url('InfinityTime/panel.php')); ?> + '&ajax=1&job=' + job;
  function tick() {
    fetch(url, {credentials:'same-origin'})
      .then(function(r){ return r.json(); })
      .then(function(d){
        var pct = d.total ? Math.round(d.done * 100 / d.total) : 100;
        if (bar) bar.style.width = pct + '%';
        if (d.total > 0) {
          if (msg) msg.textContent = d.done + ' / ' + d.total + (d.current ? ' — ' + d.current : '');
          if (d.failed > 0) {
            if (msg) msg.textContent += '（失败 ' + d.failed + '）';
          }
        } else {
          if (msg) msg.textContent = job === 'cleanup' ? '没有需要清理的孤儿文件' : '没有需要重建的图片';
        }
        if (!d.finished) {
          setTimeout(tick, 300);
        }
        else {
          if (d.failed > 0) {
            if (msg) msg.textContent += ' ✓ 完成（' + d.failed + ' 张失败，请查看日志）';
          } else {
            if (msg) msg.textContent += ' ✓ 完成';
          }
          if (btn) btn.disabled = false;
          setTimeout(function(){ location.reload(); }, 800);
        }
      })
      .catch(function(){
        if (msg) msg.textContent = '出错，请重试';
        if (btn) btn.disabled = false;
      });
  }
  tick();
}
document.querySelectorAll('[data-run]').forEach(function(b){
  b.addEventListener('click', function(){ runJob(this.getAttribute('data-run')); });
});

// 联系方式：添加 / 删除行
function ppAddContactRow() {
  var empty = document.getElementById('pp-contact-empty');
  if (empty) empty.parentNode.removeChild(empty);
  var row = document.createElement('div');
  row.className = 'pp-contact-row';
  row.innerHTML =
    '<input type="text" name="contactName[]" placeholder="名称（如 微博）">' +
    '<input type="url" name="contactUrl[]" placeholder="链接">' +
    '<div class="pp-contact-icon">' +
      '<input type="hidden" name="contactIcon[]" value="icon-github">' +
      '<button type="button" class="pp-icon-trigger" data-icon="icon-github" title="选择图标"><i class="iconfont icon-github"></i></button>' +
      '<div class="pp-icon-pop"></div>' +
    '</div>' +
    '<select name="contactStatus[]"><option value="1" selected>启用</option><option value="0">停用</option></select>' +
    '<button type="button" class="pp-btn red pp-small pp-remove-contact">删除</button>';
  document.getElementById('pp-contact-rows').appendChild(row);
  setupIconPicker(row.querySelector('.pp-contact-icon'));
}
var addBtn = document.getElementById('pp-add-contact');
if (addBtn) addBtn.addEventListener('click', ppAddContactRow);
document.addEventListener('click', function (e) {
  if (e.target && e.target.classList && e.target.classList.contains('pp-remove-contact')) {
    var row = e.target.closest('.pp-contact-row');
    if (row) row.parentNode.removeChild(row);
  }
});

// 联系方式图标：可视化选择
var PP_ICONS = [
  ['icon-shouye', '主页'], ['icon-weibo', '微博'], ['icon-github', 'GitHub'], ['icon-gengduo', '更多'],
  ['icon-map-pin-2-line', '地点'], ['icon-camera-lens-line', '相机'], ['icon-time-line', '时间'], ['icon-quanping', '全屏']
];
function setupIconPicker(box) {
  if (!box || box.__ppIcons) return;
  box.__ppIcons = true;
  var hidden = box.querySelector('input[name="contactIcon[]"]');
  var trig = box.querySelector('.pp-icon-trigger');
  var pop = box.querySelector('.pp-icon-pop');
  if (!hidden || !trig || !pop) return;
  PP_ICONS.forEach(function (pair) {
    var b = document.createElement('button');
    b.type = 'button'; b.className = 'icn'; b.dataset.icon = pair[0]; b.title = pair[1];
    b.innerHTML = '<i class="iconfont ' + pair[0] + '"></i>';
    pop.appendChild(b);
  });
  function sync() {
    Array.from(pop.querySelectorAll('.icn')).forEach(function (b) {
      b.classList.toggle('active', b.dataset.icon === hidden.value);
    });
  }
  trig.addEventListener('click', function (e) {
    e.stopPropagation();
    if (pop.classList.toggle('open')) sync();
  });
  Array.from(pop.querySelectorAll('.icn')).forEach(function (b) {
    b.addEventListener('click', function (e) {
      e.stopPropagation();
      hidden.value = b.dataset.icon;
      trig.dataset.icon = b.dataset.icon;
      var i = trig.querySelector('i');
      if (i) i.className = 'iconfont ' + b.dataset.icon;
      pop.classList.remove('open');
      sync();
    });
  });
  sync();
}
document.querySelectorAll('.pp-contact-icon').forEach(setupIconPicker);
document.addEventListener('click', function () {
  document.querySelectorAll('.pp-icon-pop.open').forEach(function (p) { p.classList.remove('open'); });
});

// WebP 质量滑块：实时显示数值
(function () {
  var q = document.getElementById('pp-quality');
  var out = document.getElementById('pp-quality-out');
  if (q && out) {
    var sync = function () { out.textContent = q.value; };
    q.addEventListener('input', sync);
    sync();
  }
})();

// 恢复默认最佳设置：填回默认值并保存
var resetBtn = document.getElementById('pp-reset-webp');
if (resetBtn) {
  resetBtn.addEventListener('click', function () {
    var f = resetBtn.closest('form');
    if (!f) return;
    var set = function (name, val) { var el = f.querySelector('[name="' + name + '"]'); if (el) el.value = val; };
    set('quality', '82'); set('thumbMax', '1280'); set('maxWidth', '2560'); set('keepOriginal', '1');
    var out = document.getElementById('pp-quality-out');
    if (out) out.textContent = '82';
    f.submit();
  });
}
</script>
<?php include $adminDir . '/footer.php'; ?>
