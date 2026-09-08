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

/** 供 AJAX 使用的 JSON 响应。 */
function pp_reply_json(bool $ok, string $msg): void
{
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => $ok, 'msg' => $msg], JSON_UNESCAPED_UNICODE);
    exit;
}

/** 后台 CSRF token（绑定当前登录用户，同一会话内稳定）。 */
function pp_csrf_token(): string
{
    try {
        return (string)\Widget\Security::alloc()->getToken('infinitytime-panel');
    } catch (\Throwable $e) {
        return '';
    }
}

/** 校验 CSRF：优先比对 token；缺失时回退到“非空且同源 referer”。 */
function pp_csrf_check(): bool
{
    $t = pp_csrf_token();
    if ($t !== '' && isset($_POST['_']) && hash_equals($t, (string)$_POST['_'])) {
        return true;
    }
    // AJAX 请求必须带有效 token（前端统一附带）；token 不可用/原生提交时回退到同源 referer
    if (!empty($_POST['ajax']) && $t !== '') {
        return false;
    }
    $ref = (string)($_SERVER['HTTP_REFERER'] ?? '');
    $reqHost = (string)($_SERVER['HTTP_HOST'] ?? '');
    $refHost = $ref !== '' ? (string)parse_url($ref, PHP_URL_HOST) : '';
    return $refHost !== '' && strcasecmp($refHost, $reqHost) === 0;
}

/** 清洗“关于介绍”里的 HTML：保留常规排版标签，去掉脚本/事件/危险协议。 */
function pp_sanitize_html(string $html): string
{
    $html = (string)preg_replace('#<(script|style|iframe|object|embed|link|meta)\b[^>]*>.*?</\1>#is', '', $html);
    $html = (string)preg_replace('#<(script|style|iframe|object|embed|link|meta)\b[^>]*/?>#is', '', $html);
    $html = (string)preg_replace('#\son[a-z]+\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)#is', '', $html);
    // 危险协议：同时覆盖带引号和不带引号的写法
    $html = (string)preg_replace(
        '#(href|src)\s*=\s*(?:"\s*(?:javascript|data):[^"]*"|\'\s*(?:javascript|data):[^\']*\'|(?:javascript|data):[^\s>]+)#is',
        '$1="#"',
        $html
    );
    return trim($html);
}

/** 只允许 http(s) 绝对地址或站内相对路径作为头像 URL。 */
function pp_valid_logo_url(string $url): string
{
    $url = trim($url);
    if ($url === '') {
        return '';
    }
    if (preg_match('#^(https?:)?//#i', $url) || (strpos($url, '/') === 0 && strpos($url, '//') !== 0)) {
        return $url;
    }
    return '';
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

/** 清理上一次由插件生成的站点头像文件（仅本插件专属目录，避免误删用户手动填写的其它图）。 */
function pp_clear_site_avatar(string $url, ?string $keepAbs = null): void
{
    if ($url === '') {
        return;
    }
    $path = parse_url($url, PHP_URL_PATH);
    if (!$path) {
        return;
    }
    $prefix = ImageRepository::uploadWebRoot() . '/infinitytime/';
    if (strpos($path, $prefix) !== 0) {
        return; // 不在本插件专属目录，跳过
    }
    $abs = ImageRepository::toAbs($path);
    $candidates = [$abs];
    // 兼容早期可能生成过的同级 thumb/original 文件
    foreach (['/full/', '/thumb/', '/original/'] as $seg) {
        if (strpos($abs, $seg) !== false) {
            $candidates[] = str_replace($seg, '/thumb/', $abs);
            $candidates[] = str_replace($seg, '/original/', $abs);
        }
    }
    foreach (array_unique($candidates) as $f) {
        if ($f && $f !== $keepAbs && is_file($f)) {
            @unlink($f);
        }
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
    // 写操作类 job 需要 CSRF token：否则可被 <img src="...&job=cleanup"> 之类的 GET 请求触发
    if (in_array($job, ['rebuild', 'cleanup', 'resync'], true)) {
        $__t = pp_csrf_token();
        $__ok = $__t !== '' && isset($_GET['_']) && hash_equals($__t, (string)$_GET['_']);
        if (!$__ok && $__t === '') {
            // token 不可用时回退到同源 referer（GET 由前端同源 fetch 发起）
            $__ref = (string)($_SERVER['HTTP_REFERER'] ?? '');
            $__refHost = $__ref !== '' ? (string)parse_url($__ref, PHP_URL_HOST) : '';
            $__ok = $__refHost !== '' && strcasecmp($__refHost, (string)($_SERVER['HTTP_HOST'] ?? '')) === 0;
        }
        if (!$__ok) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['finished' => true, 'total' => 0, 'done' => 0, 'current' => '', 'failed' => 0, 'msg' => '安全校验失败，请刷新后台页面后重试'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        // 简单并发锁：同一时间只允许一个维护任务（不同管理员 90 秒内不能抢跑）
        $__uid = (int)($user->uid ?? 0);
        $__lockFile = pp_data_file() . '/job.lock';
        $__lock = is_file($__lockFile) ? (json_decode((string)@file_get_contents($__lockFile), true) ?: []) : [];
        if ($__lock && (time() - (int)($__lock['time'] ?? 0)) < 90 && (int)($__lock['uid'] ?? 0) !== $__uid) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['finished' => true, 'total' => 0, 'done' => 0, 'current' => '', 'failed' => 0, 'msg' => '另一个维护任务正在进行，请稍后再试'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        @file_put_contents($__lockFile, json_encode(['uid' => $__uid, 'job' => $job, 'time' => time()]));
    }
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
        $quality = (int)Plugin::opt('infinitytimeQuality', Plugin::DEFAULT_QUALITY);
        $thumbMax = (int)Plugin::opt('infinitytimeThumbMax', Plugin::DEFAULT_THUMB_MAX);
        $maxWidth = (int)Plugin::opt('infinitytimeMaxWidth', Plugin::DEFAULT_MAX_WIDTH);
        $fullQuality = (int)Plugin::opt('infinitytimeFullQuality', Plugin::DEFAULT_FULL_QUALITY);
        $panoWidth = (int)Plugin::opt('infinitytimePanoWidth', Plugin::DEFAULT_PANO_WIDTH);
        $panoQuality = (int)Plugin::opt('infinitytimePanoQuality', Plugin::DEFAULT_PANO_QUALITY);
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
                $mw = $maxWidth;
                $fq = $fullQuality;
                $__info = @getimagesize($src);
                if (is_array($__info) && ($__info[0] ?? 0) > 0 && ($__info[1] ?? 0) > 0
                    && ImageRepository::isPano((int)$__info[0], (int)$__info[1])) {
                    $mw = $panoWidth > 0 ? (int)min((int)$__info[0], $panoWidth) : 0; // 全景：独立宽度，0=不裁剪
                    $fq = $panoQuality;
                }
                try {
                    MediaProcessor::process($src, ImageRepository::toAbs($item[2]), ImageRepository::toAbs($item[3]), $thumbMax, $quality, $mw, $fq);
                } catch (\Throwable $e) {
                    Plugin::log('rebuild ajax: id=' . $item[0] . ' ' . $e->getMessage());
                    $failed++;
                }
                $state['current'] = basename($src);
            } else {
                // 原图缺失：无法重建，计为失败（不要静默跳过）
                $failed++;
                $state['current'] = basename($src) . '（缺原图）';
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
    } elseif ($job === 'resync') {
        // 重算每篇图集的聚合字段（addresses/titles/descs/panos，并补上 dims 宽高数组）。
        // 用于给已发布的历史文章补齐文章字段，使首页瀑布流能拿到图片比例做占位。
        $listFile = pp_data_file() . '/resync_list.json';
        $jobFile = pp_data_file() . '/job.json';
        $state = pp_read_json($jobFile);
        if (($state['job'] ?? '') !== 'resync' || !file_exists($listFile)) {
            $cids = [];
            foreach ($db->fetchAll($db->select('cid')->from(ImageRepository::table())) as $r) {
                $cids[(int)$r['cid']] = true;
            }
            foreach ($db->fetchAll($db->select('cid')->from($prefix . 'fields')->where('name = ?', 'img')) as $r) {
                $cids[(int)$r['cid']] = true;
            }
            $list = array_values(array_filter(array_map('intval', array_keys($cids))));
            sort($list);
            pp_write_json($listFile, $list);
            pp_write_json($jobFile, ['job' => 'resync', 'total' => count($list), 'done' => 0, 'current' => '']);
        }
        $list = pp_read_json($listFile);
        $state = pp_read_json($jobFile);
        $idx = (int)($state['done'] ?? 0);
        $failed = (int)($state['failed'] ?? 0);
        $batch = 12; // 每次处理 12 篇，避免单次 AJAX 超时
        $started = microtime(true);
        $budget = 20;
        $total = count($list);
        for ($i = 0; $i < $batch && $idx < $total; $i++) {
            if ((microtime(true) - $started) > $budget) {
                break;
            }
            $cid = (int)($list[$idx] ?? 0);
            if ($cid > 0) {
                try {
                    ImageRepository::syncPostFields($cid);
                    $state['current'] = 'cid ' . $cid;
                } catch (\Throwable $e) {
                    Plugin::log('resync ajax: cid=' . $cid . ' ' . $e->getMessage());
                    $failed++;
                    $state['current'] = 'cid ' . $cid . '（失败）';
                }
            }
            $idx++;
        }
        $state['done'] = $idx;
        $state['failed'] = $failed;
        $state['finished'] = $idx >= $total;
        if ($state['finished']) {
            $state = ['job' => 'resync', 'total' => 0, 'done' => 0, 'current' => '', 'finished' => true, 'failed' => $failed];
            @unlink($listFile);
        }
        pp_write_json($jobFile, $state);
        $result = ['finished' => $idx >= $total, 'total' => $total, 'done' => $idx, 'current' => $state['current'], 'failed' => $failed];
    } elseif ($job === 'albums_html') {
        // 局部刷新图集列表：只返回卡片 HTML，避免为刷新列表重新渲染整个后台页面
        header('Content-Type: text/html; charset=utf-8');
        echo pp_render_albums_card(pp_albums($prefix), $options);
        exit;
    }

    header('Content-Type: application/json');
    if (!empty($result['finished'])) { @unlink(pp_data_file() . '/job.lock'); }
    echo json_encode($result, JSON_UNESCAPED_UNICODE);
    exit;
}

/* ---------------------------------- POST 处理 ---------------------------------- */

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // CSRF：优先校验 Typecho token；旧表单/无 token 时至少要求“非空且同源”的 referer
    if (!pp_csrf_check()) {
        pp_reply_json(false, _t('安全校验失败，请刷新后台页面后重试'));
    }
    $action = (string)($_POST['action'] ?? '');

    if ($action === 'create_album') {
        $ajax = !empty($_POST['ajax']);
        $title = trim((string)($_POST['title'] ?? ''));
        $address = trim((string)($_POST['address'] ?? ''));
        $device = trim((string)($_POST['device'] ?? ''));
        $tags = trim((string)($_POST['tags'] ?? ''));
        if ($title === '') {
            if ($ajax) { pp_reply_json(false, _t('请填写图集标题')); }
            pp_reply(_t('请填写图集标题'), 'error');
        }
        // When post_max_size is exceeded PHP drops the entire $_FILES array.
        // Report that explicitly instead of silently returning to the panel.
        if (empty($_FILES['files']) && !empty($_SERVER['CONTENT_LENGTH'])) {
            if ($ajax) { pp_reply_json(false, _t('上传内容超过服务器 post_max_size 限制，请调大 post_max_size 后重试')); }
            pp_reply(_t('上传内容超过服务器 post_max_size 限制，请调大 post_max_size 后重试'), 'error');
        }
        if (empty($_FILES['files']['name'][0]) || !is_array($_FILES['files']['name'])) {
            if ($ajax) { pp_reply_json(false, _t('请至少选择一张图片')); }
            pp_reply(_t('请至少选择一张图片'), 'error');
        }

        $now = time();
        $slug = 'album-' . date('YmdHis', $now) . '-' . random_int(100, 999);
        $cid = (int)$db->query($db->insert($prefix . 'contents')->rows([
            'title' => $title, 'slug' => $slug, 'created' => $now, 'modified' => $now,
            'text' => '', 'authorId' => $user->uid, 'type' => 'post', 'status' => 'publish',
            'allowComment' => '0', 'allowPing' => '0', 'allowFeed' => '0', 'template' => '', 'password' => '',
        ]));

        $imgs = []; $thumbs = []; $exifs = []; $addrs = []; $titles = []; $descs = []; $panos = [];
        $firstExif = null; $index = 0; $fail = 0;
        $quality = (int)Plugin::opt('infinitytimeQuality', Plugin::DEFAULT_QUALITY);
        $thumbMax = (int)Plugin::opt('infinitytimeThumbMax', Plugin::DEFAULT_THUMB_MAX);
        $maxWidth = (int)Plugin::opt('infinitytimeMaxWidth', Plugin::DEFAULT_MAX_WIDTH);
        $fullQuality = (int)Plugin::opt('infinitytimeFullQuality', Plugin::DEFAULT_FULL_QUALITY);
        $keep = (bool)Plugin::opt('infinitytimeKeepOriginal', Plugin::DEFAULT_KEEP_ORIGINAL);
        $panoWidth = (int)Plugin::opt('infinitytimePanoWidth', Plugin::DEFAULT_PANO_WIDTH);
        $panoQuality = (int)Plugin::opt('infinitytimePanoQuality', Plugin::DEFAULT_PANO_QUALITY);

        $count = count($_FILES['files']['name']);
        // 逐图标题/描述（与 files[] 同序，后端据此写入每张图的 title/desc）
        $postTitles = (array)($_POST['img_titles'] ?? []);
        $postDescs = (array)($_POST['img_descs'] ?? []);
        $uploadErr = 0;
        $oversize = 0;
        $overpx = 0;
        $maxFileBytes = 64 * 1024 * 1024; // 单文件 64MB 上限
        $maxPixels = 20000;               // 单边 20000px 上限
        for ($i = 0; $i < $count; $i++) {
            $fe = (int)($_FILES['files']['error'][$i] ?? UPLOAD_ERR_NO_FILE);
            if ($fe !== UPLOAD_ERR_OK) {
                if ($uploadErr === 0) {
                    $uploadErr = $fe;
                }
                $fail++;
                continue;
            }
            // 前置校验：超大文件 / 超大像素直接跳过，避免拖垮 GD 内存
            if ((int)($_FILES['files']['size'][$i] ?? 0) > $maxFileBytes) {
                $oversize++;
                $fail++;
                continue;
            }
            $__dim = @getimagesize((string)($_FILES['files']['tmp_name'][$i] ?? ''));
            if (is_array($__dim) && (($__dim[0] ?? 0) > $maxPixels || ($__dim[1] ?? 0) > $maxPixels)) {
                $overpx++;
                $fail++;
                continue;
            }
            $meta = ImageRepository::ingest([
                'name' => $_FILES['files']['name'][$i],
                'tmp_name' => $_FILES['files']['tmp_name'][$i],
                'size' => $_FILES['files']['size'][$i] ?? 0,
                'error' => $_FILES['files']['error'][$i] ?? UPLOAD_ERR_OK,
            ], ['quality' => $quality, 'thumb_max' => $thumbMax, 'max_width' => $maxWidth, 'pano_width' => $panoWidth, 'pano_quality' => $panoQuality, 'full_quality' => $fullQuality, 'keep_original' => $keep, 'address' => $address]);
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
            $pw = (int)($meta['width'] ?? 0); $ph = (int)($meta['height'] ?? 0);
            $panos[] = ImageRepository::isPano($pw, $ph) ? 1 : 0;
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
                : ($oversize > 0
                    ? sprintf(_t('有 %d 张图片超过 64MB 上限，已跳过'), $oversize)
                    : ($overpx > 0
                        ? sprintf(_t('有 %d 张图片单边超过 20000px 上限，已跳过'), $overpx)
                        : ($reason !== '' ? $reason : _t('没有图片成功入库（可能格式不支持或缺少转换工具）'))));
            if ($ajax) { pp_reply_json(false, $msg); }
            pp_reply($msg, 'error');
        }

        foreach ([
            'img' => implode("\n", $imgs),
            'thumb' => implode("\n", $thumbs),
            'exif' => json_encode($exifs, JSON_UNESCAPED_UNICODE),
            'addresses' => json_encode($addrs, JSON_UNESCAPED_UNICODE),
            'titles' => json_encode($titles, JSON_UNESCAPED_UNICODE),
            'descs' => json_encode($descs, JSON_UNESCAPED_UNICODE),
            'panos' => json_encode($panos, JSON_UNESCAPED_UNICODE),
            'device' => $device !== '' ? $device : trim((string)($firstExif['make'] ?? '') . ' ' . (string)($firstExif['model'] ?? '')),
            'location' => $address,
        ] as $name => $val) {
            pp_set_field($cid, $name, $val);
        }
        if ($tags !== '') {
            pp_set_field($cid, 'tags', $tags);
        }
        $msg = sprintf(_t('已发布图集「%s」，共 %d 张图片'), $title, count($imgs));
        if ($ajax) { pp_reply_json(true, $msg); }
        pp_reply($msg);
    }

    if ($action === 'update_album') {
        $ajax = !empty($_POST['ajax']);
        $cid = (int)($_POST['cid'] ?? 0);
        if ($cid > 0) {
            $title = trim((string)($_POST['title'] ?? ''));
            if ($title !== '') {
                $db->query($db->update($prefix . 'contents')->rows(['title' => $title])->where('cid = ?', $cid));
            }
            pp_set_field($cid, 'device', trim((string)($_POST['device'] ?? '')));
            pp_set_field($cid, 'tags', trim((string)($_POST['tags'] ?? '')));
            pp_set_field($cid, 'location', trim((string)($_POST['address'] ?? '')));
            if ($ajax) { pp_reply_json(true, _t('已更新图集信息')); }
            pp_reply(_t('已更新图集信息'));
        }
    }

    if ($action === 'set_image_meta') {
        $ajax = !empty($_POST['ajax']);
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
        if ($ajax) { pp_reply_json(true, _t('已保存图片信息')); }
        pp_reply(_t('已保存图片信息'));
    }

    if ($action === 'sort_images') {
        $ajax = !empty($_POST['ajax']);
        $cid = (int)($_POST['cid'] ?? 0);
        $rowIds = (array)($_POST['rowIds'] ?? []);
        $order = 0;
        foreach ($rowIds as $rid) {
            $rid = (int)$rid;
            if ($rid <= 0) {
                continue;
            }
            $db->query($db->update(ImageRepository::table())->rows(['sort' => $order])->where('id = ?', $rid)->where('cid = ?', $cid));
            $order++;
        }
        if ($cid > 0) {
            ImageRepository::syncPostFields($cid);
        }
        if ($ajax) { pp_reply_json(true, _t('已保存图片顺序')); }
        pp_reply(_t('已保存图片顺序'));
    }

    if ($action === 'delete_image') {
        $ajax = !empty($_POST['ajax']);
        $rowId = (int)($_POST['rowId'] ?? 0);
        if ($rowId > 0) {
            $row = $db->fetchRow($db->select()->from(ImageRepository::table())->where('id = ?', $rowId)->limit(1));
            if ($row) {
                ImageRepository::unlinkFiles($row['original'], $row['full'], $row['thumb']);
                $db->query($db->delete(ImageRepository::table())->where('id = ?', $rowId));
            }
        }
        if ($ajax) { pp_reply_json(true, _t('已删除该图片')); }
        pp_reply(_t('已删除该图片'));
    }

    if ($action === 'preview_non_plugin' || $action === 'delete_non_plugin') {
        // 汇总“本插件发布的图集”cid：有 img 自定义字段 或 在 infinitytime_images 表里
        $pluginCids = [];
        foreach ($db->fetchAll($db->select('cid')->from($prefix . 'fields')->where('name = ?', 'img')) as $f) {
            $pluginCids[(int)$f['cid']] = true;
        }
        foreach ($db->fetchAll($db->select('cid')->from(ImageRepository::table())) as $f) {
            $pluginCids[(int)$f['cid']] = true;
        }
        $rows = $db->fetchAll($db->select('cid', 'title')->from($prefix . 'contents')->where('type = ?', 'post'));
        $nonCount = 0;
        $titles = [];
        $isDelete = ($action === 'delete_non_plugin');
        if ($isDelete) { $db->query('START TRANSACTION'); }
        try {
            foreach ($rows as $r) {
                $cid = (int)$r['cid'];
                if (isset($pluginCids[$cid])) {
                    continue;
                }
                $nonCount++;
                if ($isDelete) {
                    $db->query($db->delete($prefix . 'fields')->where('cid = ?', $cid));
                    $db->query($db->delete($prefix . 'contents')->where('cid = ?', $cid));
                } elseif (count($titles) < 20) {
                    $titles[] = (string)($r['title'] ?? '');
                }
            }
            if ($isDelete) { $db->query('COMMIT'); }
        } catch (\Throwable $e) {
            if ($isDelete) { $db->query('ROLLBACK'); }
            Plugin::log('delete_non_plugin failed: ' . $e->getMessage());
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['ok' => false, 'msg' => '清理失败：' . $e->getMessage()], JSON_UNESCAPED_UNICODE);
            exit;
        }
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($isDelete
            ? ['ok' => true, 'deleted' => $nonCount]
            : ['ok' => true, 'count' => $nonCount, 'titles' => $titles],
            JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($action === 'save_settings') {
        $ajax = !empty($_POST['ajax']);
        Plugin::setOption('infinitytimeQuality', max(1, min(100, (int)($_POST['quality'] ?? Plugin::DEFAULT_QUALITY))));
        Plugin::setOption('infinitytimeThumbMax', max(200, min(4096, (int)($_POST['thumbMax'] ?? Plugin::DEFAULT_THUMB_MAX))));
        Plugin::setOption('infinitytimeMaxWidth', max(0, min(20000, (int)($_POST['maxWidth'] ?? Plugin::DEFAULT_MAX_WIDTH))));
        Plugin::setOption('infinitytimeFullQuality', max(1, min(100, (int)($_POST['fullQuality'] ?? Plugin::DEFAULT_FULL_QUALITY))));
        Plugin::setOption('infinitytimeKeepOriginal', ($_POST['keepOriginal'] ?? '1') === '1' ? '1' : '0');
        Plugin::setOption('infinitytimePanoWidth', max(0, min(20000, (int)($_POST['panoWidth'] ?? Plugin::DEFAULT_PANO_WIDTH))));
        Plugin::setOption('infinitytimePanoQuality', max(1, min(100, (int)($_POST['panoQuality'] ?? Plugin::DEFAULT_PANO_QUALITY))));
        if ($ajax) { pp_reply_json(true, _t('已保存 WebP 转换设置')); }
        pp_reply(_t('已保存 WebP 转换设置'));
    }

    if ($action === 'delete_album') {
        $cid = (int)($_POST['cid'] ?? 0);
        $ajax = !empty($_POST['ajax']);
        if ($cid > 0) {
            ImageRepository::removeFor($cid);
            $db->query($db->delete($prefix . 'contents')->where('cid = ?', $cid));
            $db->query($db->delete($prefix . 'fields')->where('cid = ?', $cid));
        }
        if ($ajax) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['ok' => true, 'msg' => _t('已删除图集及其图片文件')], JSON_UNESCAPED_UNICODE);
            exit;
        }
        pp_reply(_t('已删除图集及其图片文件'));
    }

    if ($action === 'save_site') {
        $ajax = !empty($_POST['ajax']);
        $oldLogo = (string)Plugin::opt('infinitytimeSiteLogo', '');
        $logoInput = trim((string)($_POST['siteLogo'] ?? ''));
        $logo = pp_valid_logo_url($logoInput);
        if ($logoInput !== '' && $logo === '') {
            if ($ajax) { pp_reply_json(false, _t('头像链接只支持 http(s) 或站内相对路径')); }
            pp_reply(_t('头像链接只支持 http(s) 或站内相对路径'), 'error');
        }
        $newAvatarAbs = null;
        // 支持直接上传头像：选了文件就转成 WebP 存入独立目录（不参与「清理孤儿文件」），并自动生成链接。
        if (!empty($_FILES['siteLogoFile']['tmp_name'])) {
            $uploadWebRoot = ImageRepository::uploadWebRoot();
            $meta = ImageRepository::ingest([
                'name' => $_FILES['siteLogoFile']['name'],
                'tmp_name' => $_FILES['siteLogoFile']['tmp_name'],
                'size' => $_FILES['siteLogoFile']['size'] ?? 0,
                'error' => $_FILES['siteLogoFile']['error'] ?? UPLOAD_ERR_OK,
            ], [
                // 头像做压缩与尺寸处理：最长边 ≤512px、WebP 质量 78；不生成用不到的缩略图。
                'quality' => 78,
                'thumb_max' => 0,
                'max_width' => 512,
                'full_quality' => 78,
                'keep_original' => false,
                'dirs' => [
                    ImageRepository::T_ORIGINAL => $uploadWebRoot . '/infinitytime/original',
                    ImageRepository::T_FULL     => $uploadWebRoot . '/infinitytime/full',
                    ImageRepository::T_THUMB    => $uploadWebRoot . '/infinitytime/thumb',
                ],
            ]);
            if (!$meta) {
                if ($ajax) { pp_reply_json(false, _t('头像上传失败：') . (ImageRepository::$lastError ?: '未知错误')); }
                pp_reply(_t('头像上传失败：') . (ImageRepository::$lastError ?: '未知错误'), 'error');
            }
            $logo = rtrim((string)$options->siteUrl, '/') . $meta['full'];
            $newAvatarAbs = ImageRepository::toAbs($meta['full']);
        }
        // 上传新头像成功后，清理上一次由本插件生成的旧头像文件（不误删本次新文件）。
        pp_clear_site_avatar($oldLogo, $newAvatarAbs);
        Plugin::setOption('infinitytimeSiteLogo', $logo);
        Plugin::setOption('infinitytimeSiteName', trim((string)($_POST['siteName'] ?? '')));
        Plugin::setOption('infinitytimeSiteTagline', trim((string)($_POST['siteTagline'] ?? '')));
        Plugin::setOption('infinitytimeAbout', pp_sanitize_html((string)($_POST['aboutText'] ?? '')));
        if ($ajax) { pp_reply_json(true, _t('已保存站点信息')); }
        pp_reply(_t('已保存站点信息'));
    }

    if ($action === 'save_contacts') {
        $ajax = !empty($_POST['ajax']);
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
        if ($ajax) { pp_reply_json(true, _t('已保存联系方式')); }
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

/** 渲染「已发布图集」卡片：面板主视图与 AJAX 局部刷新共用。 */
function pp_render_albums_card(array $albums, $options): string
{
    ob_start();
    ?>
    <div class="pp-card" id="pp-albums-card">
      <h2>已发布图集 <span class="pp-sub">拖动图片可调整顺序</span></h2>
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
              <form method="post" style="display:inline" class="pp-delete-album">
                <input type="hidden" name="action" value="delete_album">
                <input type="hidden" name="cid" value="<?php echo $al['cid']; ?>">
                <button class="pp-btn red pp-small" type="submit" data-loading="删除中…">删除</button>
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
                <img src="<?php echo htmlspecialchars(ImageRepository::toWeb(ImageRepository::toAbs($img['thumb']))); ?>" alt="" loading="lazy" decoding="async">
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
                <form method="post" action="<?php echo htmlspecialchars(Helper::url('InfinityTime/panel.php')); ?>">
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
    <?php
    return (string)ob_get_clean();
}

$notice = $options->request->get('notice');
$noticeType = $options->request->get('noticeType', 'success');
$albums = pp_albums($prefix);
$quality = (int)Plugin::opt('infinitytimeQuality', Plugin::DEFAULT_QUALITY);
$thumbMax = (int)Plugin::opt('infinitytimeThumbMax', Plugin::DEFAULT_THUMB_MAX);
$maxWidth = (int)Plugin::opt('infinitytimeMaxWidth', Plugin::DEFAULT_MAX_WIDTH);
$fullQuality = (int)Plugin::opt('infinitytimeFullQuality', Plugin::DEFAULT_FULL_QUALITY);
$keepOriginal = (bool)Plugin::opt('infinitytimeKeepOriginal', Plugin::DEFAULT_KEEP_ORIGINAL);
$panoWidth = (int)Plugin::opt('infinitytimePanoWidth', Plugin::DEFAULT_PANO_WIDTH);
$panoQuality = (int)Plugin::opt('infinitytimePanoQuality', Plugin::DEFAULT_PANO_QUALITY);
$tools = MediaProcessor::detectTools();
$siteLogo = (string)Plugin::opt('infinitytimeSiteLogo', '');
$siteName = (string)Plugin::opt('infinitytimeSiteName', '');
$siteTagline = (string)Plugin::opt('infinitytimeSiteTagline', '');
$aboutText = (string)Plugin::opt('infinitytimeAbout', '');
$contacts = json_decode((string)Plugin::opt('infinitytimeContacts', '[]'), true) ?: [];
// 插件目录对应的站点 URL（用于引用 admin.css / admin.js）
$ppRel = str_replace('\\', '/', substr(__DIR__, strlen(rtrim((string)__TYPECHO_ROOT_DIR__, '/'))));
if (strpos($ppRel, '/') !== 0) { $ppRel = '/usr/plugins/InfinityTime'; }
$ppPluginWeb = rtrim((string)$options->siteUrl, '/') . $ppRel;
$ppCsrf = pp_csrf_token();
$ppJobState = pp_read_json(pp_data_file() . '/job.json');

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
    <link rel="stylesheet" href="<?php echo htmlspecialchars($ppPluginWeb . '/assets/admin.css'); ?>">

    <div class="pp-wrap">
      <div class="typecho-page-title"><h2>InfinityTime 图片分享</h2></div>
      <nav class="pp-tabs" role="tablist">
        <button type="button" class="pp-tab active" data-tab="upload">上传发布</button>
        <button type="button" class="pp-tab" data-tab="albums">已发布图集</button>
        <button type="button" class="pp-tab" data-tab="site">站点信息</button>
        <button type="button" class="pp-tab" data-tab="contacts">联系方式</button>
        <button type="button" class="pp-tab" data-tab="convert">转换设置</button>
        <button type="button" class="pp-tab" data-tab="maintain">维护</button>
      </nav>
      <!-- 上传发布 -->
      <section class="pp-panel active" data-panel="upload">
      <div class="pp-card">
        <h2>上传并发布图集</h2>
        <form id="pp-upload-form" method="post" enctype="multipart/form-data" action="<?php echo htmlspecialchars(Helper::url('InfinityTime/panel.php')); ?>">
          <input type="hidden" name="action" value="create_album">
          <input type="hidden" name="_" value="<?php echo htmlspecialchars($ppCsrf); ?>">
          <div class="pp-grid">
            <div>
              <div class="pp-row"><label>图集标题 *</label><input type="text" name="title" required></div>
              <div class="pp-row"><label>拍摄设备</label><input type="text" name="device" placeholder="如 Sony A7M4 / iPhone 17 Pro（留空取首图 EXIF）"></div>
              <div class="pp-row"><label>地点 / 地址</label><input type="text" name="address" placeholder="如 福建 福州 三坊七巷（手动填写）"></div>
              <div class="pp-row"><label>标签</label><input type="text" name="tags" placeholder="如 城市,夜景"></div>
            </div>
            <div>
              <div class="pp-row" style="display:block">
                <label style="display:block;margin-bottom:6px">选择图片</label>
                <input type="file" id="pp-files-input" name="files[]" multiple accept=".jpg,.jpeg,.png,.gif,.webp,.heic,.heif,.avif" required>
                <div id="pp-dropzone" class="pp-dropzone"><strong>拖拽图片到这里</strong>，或点击选择（可多选）</div>
                <div class="pp-meta" id="pp-upload-summary" style="margin-top:8px"></div>
              </div>
            </div>
          </div>
          <!-- 已选图片预览：整行自适应网格，避免把两列表单撑得高低不平 -->
          <div id="pp-upload-previews"></div>
          <div class="pp-progress" id="pp-upload-progress" style="display:none">
            <div class="pp-bar-outer"><div class="pp-bar" id="pp-upload-bar"></div></div>
            <span class="pp-msg">上传中…</span>
          </div>
          <div class="pp-foot">
            <button class="pp-btn" type="submit">发布图集</button>
            <span class="pp-meta">每张自动转 WebP 全图 + 缩略图，并按设置保留原图；选择后可逐张填写标题/描述，拖动卡片可排序。</span>
          </div>
        </form>
      </div>
      </section>
      <!-- 站点信息 / 关于 -->
      <section class="pp-panel" data-panel="site">
      <div class="pp-card">
        <h2>站点信息 / 关于</h2>
        <form method="post" enctype="multipart/form-data" action="<?php echo htmlspecialchars(Helper::url('InfinityTime/panel.php')); ?>">
          <input type="hidden" name="action" value="save_site">
          <div class="pp-row"><label>头像 / 站点图标</label>
            <div class="pp-col">
              <input type="text" name="siteLogo" value="<?php echo htmlspecialchars($siteLogo); ?>" placeholder="图片 URL，如 https://.../avatar.webp">
              <span class="pp-meta">或直接上传：</span>
              <input type="file" name="siteLogoFile" accept="image/*">
            </div>
          </div>
          <div class="pp-row"><label>站点名称</label><input type="text" name="siteName" value="<?php echo htmlspecialchars($siteName); ?>" placeholder="首页左下角名称 + 页脚「关于」标题"></div>
          <div class="pp-row"><label>一句话说明</label><input type="text" name="siteTagline" value="<?php echo htmlspecialchars($siteTagline); ?>" placeholder="首页名称下方的副标题 / 底栏说明"></div>
          <div class="pp-row"><label>关于介绍</label><textarea name="aboutText" rows="4" placeholder="页脚「关于」区的介绍，支持 HTML"><?php echo htmlspecialchars($aboutText); ?></textarea></div>
          <div class="pp-note"><span class="pp-meta">留空则使用 InfinityTime 主题自带设置；填写后覆盖前台首页左下角头像/名称/说明，以及页脚「关于」介绍。</span></div>
          <div class="pp-foot"><button class="pp-btn" type="submit">保存站点信息</button></div>
        </form>
      </div>
      </section>
      <!-- 联系方式 / 联系我 -->
      <section class="pp-panel" data-panel="contacts">
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
      </section>
      <!-- 转换设置 -->
      <section class="pp-panel" data-panel="convert">
      <div class="pp-card">
        <h2>WebP 转换设置</h2>
        <form method="post" action="<?php echo htmlspecialchars(Helper::url('InfinityTime/panel.php')); ?>">
          <input type="hidden" name="action" value="save_settings">
          <div class="pp-group">通用 · 上传保留原图</div>
          <div class="pp-row"><label>保留原图</label>
            <select name="keepOriginal">
              <option value="1" <?php echo $keepOriginal ? 'selected' : ''; ?>>保留（original/）</option>
              <option value="0" <?php echo !$keepOriginal ? 'selected' : ''; ?>>不保留（省空间）</option>
            </select>
          </div>

          <div class="pp-group">缩略图（通用）</div>
          <div class="pp-row"><label>最长边</label><input type="number" name="thumbMax" min="200" max="4096" value="<?php echo $thumbMax; ?>"></div>
          <div class="pp-row"><label>质量</label>
            <div class="pp-col">
              <input type="range" name="quality" id="pp-quality" class="pp-range" min="1" max="100" step="1" value="<?php echo $quality; ?>">
              <output id="pp-quality-out" class="pp-hint" for="pp-quality"><?php echo $quality; ?></output>
            </div>
          </div>

          <div class="pp-group">普通图（灯箱大图）</div>
          <div class="pp-row"><label>宽度上限</label><div class="pp-col"><input type="number" name="maxWidth" min="0" max="20000" value="<?php echo $maxWidth; ?>"><span class="pp-hint">0=不裁剪</span></div></div>
          <div class="pp-row"><label>质量</label>
            <div class="pp-col">
              <input type="range" name="fullQuality" id="pp-full-quality" class="pp-range" min="1" max="100" step="1" value="<?php echo $fullQuality; ?>">
              <output id="pp-full-quality-out" class="pp-hint" for="pp-full-quality"><?php echo $fullQuality; ?></output>
            </div>
          </div>

          <div class="pp-group">全景图（宽高比 2:1）</div>
          <div class="pp-row"><label>宽度上限</label><div class="pp-col"><input type="number" name="panoWidth" min="0" max="20000" value="<?php echo $panoWidth; ?>"><span class="pp-hint">0=不裁剪</span></div></div>
          <div class="pp-row"><label>质量</label>
            <div class="pp-col">
              <input type="range" name="panoQuality" id="pp-pano-quality" class="pp-range" min="1" max="100" step="1" value="<?php echo $panoQuality; ?>">
              <output id="pp-pano-quality-out" class="pp-hint" for="pp-pano-quality"><?php echo $panoQuality; ?></output>
            </div>
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
      </section>

      <!-- 维护 -->
      <section class="pp-panel" data-panel="maintain">
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
          <div>
            <button class="pp-btn gray" type="button" data-run="resync">重建尺寸字段</button>
            <div class="pp-progress"><div class="pp-bar-outer"><div class="pp-bar" id="pp-bar-resync"></div></div><span class="pp-msg" id="pp-msg-resync"></span></div>
            <div class="pp-meta" style="margin-top:8px">重算所有图集的文章字段（含缩略图宽高），供首页瀑布流按比例预占位，避免图片加载时顺序跳变。升级后跑一次即可。</div>
          </div>
          <div>
            <button class="pp-btn red" type="button" id="pp-clean-posts">清理非插件文章</button>
            <div class="pp-meta" style="margin-top:8px">删除所有不是 InfinityTime 发布的 type=post 文章（不含本插件图集；会连同自定义字段一起删除）。</div>
          </div>
        </div>
      </div>
      </section>

      <section class="pp-panel" data-panel="albums">
      <?php echo pp_render_albums_card($albums, $options); ?>
      </section>

    </div>
  </div>
    <script>
    window.PP_ADMIN = <?php echo json_encode([
      'url' => Helper::url('InfinityTime/panel.php'),
      'token' => $ppCsrf,
      'defaults' => [
        'quality' => Plugin::DEFAULT_QUALITY,
        'thumbMax' => Plugin::DEFAULT_THUMB_MAX,
        'maxWidth' => Plugin::DEFAULT_MAX_WIDTH,
        'keepOriginal' => Plugin::DEFAULT_KEEP_ORIGINAL,
        'fullQuality' => Plugin::DEFAULT_FULL_QUALITY,
        'panoWidth' => Plugin::DEFAULT_PANO_WIDTH,
        'panoQuality' => Plugin::DEFAULT_PANO_QUALITY,
      ],
      'job' => [
        'job' => (string)($ppJobState['job'] ?? ''),
        'total' => (int)($ppJobState['total'] ?? 0),
        'done' => (int)($ppJobState['done'] ?? 0),
        'finished' => !empty($ppJobState['finished']),
      ],
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
    </script>
    <script src="<?php echo htmlspecialchars($ppPluginWeb . '/assets/admin.js'); ?>"></script>
</main>
<?php include $adminDir . '/footer.php'; ?>
