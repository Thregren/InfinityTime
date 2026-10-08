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
use TypechoPlugin\InfinityTime\Lib\AdminRepository;
use TypechoPlugin\InfinityTime\Lib\AdminSecurity;
use TypechoPlugin\InfinityTime\Lib\AdminWorkflow;
use TypechoPlugin\InfinityTime\Lib\MaintenanceState;
use TypechoPlugin\InfinityTime\Lib\Database;
require_once __DIR__ . '/Lib/MaintenanceState.php';

$db = Db::get();
$user = User::alloc();
$prefix = $db->getPrefix();
$options = Helper::options();

if (!$user->pass('contributor', true)) {
    throw new \Typecho\Widget\Exception(_t('没有权限'), 403);
}

/* ---------------------------------- 工具函数 ---------------------------------- */

function pp_reply(string $msg = '', string $type = 'success', int $cid = 0): void
{
    $options = Helper::options();
    $url = Helper::url('InfinityTime/panel.php');
    if ($msg) {
        $sep = strpos($url, '?') === false ? '?' : '&';
        $url .= $sep . 'notice=' . urlencode($msg) . '&noticeType=' . $type;
    }
    if ($cid > 0) { $url .= (strpos($url, '?') === false ? '?' : '&') . 'append=' . $cid . '#upload'; }
    \Typecho\Response::getInstance()->setStatus(302);
    @header('Location: ' . $url);
    exit;
}

/** 供 AJAX 使用的 JSON 响应。 */
function pp_reply_json(bool $ok, string $msg, array $data = [], int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode(['ok' => $ok, 'msg' => $msg] + $data, JSON_UNESCAPED_UNICODE);
    exit;
}

/** 是否管理员。 */
function pp_is_admin(): bool
{
    global $user;
    return $user->pass('administrator', true);
}

function pp_can_publish(): bool
{
    global $user;
    return $user->pass('editor', true);
}

/** 统一权限拒绝：AJAX 返回 JSON，普通请求跳回面板提示。 */
function pp_deny(string $msg): void
{
    if (!empty($_POST['ajax']) || !empty($_GET['ajax'])) {
        pp_reply_json(false, _t($msg), [], 403);
    }
    pp_reply(_t($msg), 'error');
}

/** 需要管理员权限（站点信息 / 转换设置 / 维护 / 联系方式）。 */
function pp_require_admin(): void
{
    if (!pp_is_admin()) {
        pp_deny('需要管理员权限');
    }
}

/** 管理员与作者都必须指向真实的 InfinityTime 图集。 */
function pp_can_edit_cid(int $cid): bool
{
    global $user;
    $album = AdminRepository::album($cid, (int)$user->uid, pp_is_admin(), ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST');
    return $album !== null && ($album['status'] === 'draft' || pp_can_publish());
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

/** 严格校验，不回退到 Referer；原生表单及维护任务同样适用。 */
function pp_csrf_check(): bool
{
    return AdminSecurity::validMutation((string)($_SERVER['REQUEST_METHOD'] ?? ''), pp_csrf_token(), $_POST['_'] ?? null);
}

function pp_csrf_input(): string
{
    return '<input type="hidden" name="_" value="' . htmlspecialchars(pp_csrf_token(), ENT_QUOTES, 'UTF-8') . '">';
}

function pp_panel_url(array $query = []): string
{
    $url = Helper::url('InfinityTime/panel.php');
    return $url . ($query ? (strpos($url, '?') === false ? '?' : '&') . http_build_query($query) : '');
}

/** 清洗“关于介绍”里的 HTML：保留常规排版标签，去掉脚本/事件/危险协议。 */
function pp_sanitize_html(string $html): string
{
    return \TypechoPlugin\InfinityTime\Lib\Sanitizer::sanitize($html);
}

/** 只允许 http(s) 绝对地址或站内相对路径作为头像 URL。 */
function pp_valid_logo_url(string $url): string
{
    return \TypechoPlugin\InfinityTime\Lib\Sanitizer::validUrl($url);
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
    Database::query($db->delete($prefix . 'fields')->where('cid = ?', $cid)->where('name = ?', $name));
    if ($value !== '') {
        Database::query($db->insert($prefix . 'fields')->rows([
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
    return MaintenanceState::read($file);
}

function pp_write_json(string $file, array $data): void
{
    MaintenanceState::write($file, $data);
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

$ppMethod = (string)($_SERVER['REQUEST_METHOD'] ?? 'GET');
$ppAction = is_string($_POST['action'] ?? null) ? $_POST['action'] : '';
$ppMaintenance = $ppMethod === 'POST' && $ppAction === 'maintenance';
if ($ppMethod === 'POST') {
    if (empty($_POST) && empty($_FILES) && (int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
        pp_reply_json(false, '没有收到表单内容，可能超过服务器 post_max_size 限制。请减少单次上传大小后重试', [], 413);
    }
    if (!pp_csrf_check()) {
        pp_reply_json(false, _t('安全校验失败，请刷新后台页面后重试'), [], 403);
    }
    // 锁覆盖权限检查、数据库及文件写入，避免并发丢失更新。
    try { ImageRepository::lockMedia(); } catch (\Throwable $e) {
        pp_reply_json(false, $e->getMessage(), ['retryable' => true, 'finished' => true], 409);
    }
}
if (!$ppMaintenance && in_array((string)($_GET['job'] ?? ''), ['rebuild', 'cleanup', 'resync'], true)) {
    header('Allow: POST');
    pp_reply_json(false, '维护任务只接受 POST 请求', ['finished' => true], 405);
}

if (!empty($_GET['ajax']) || $ppMaintenance) {
    header('Cache-Control: no-store');
    $job = $ppMaintenance ? (string)($_POST['job'] ?? '') : (string)($_GET['job'] ?? '');
    if ($ppMaintenance && !in_array($job, ['rebuild', 'cleanup', 'resync'], true)) {
        pp_reply_json(false, '未知维护任务', ['finished' => true], 400);
    }
    if ($ppMaintenance) {
        pp_require_admin();
        // 简单并发锁：同一时间只允许一个维护任务（不同管理员 90 秒内不能抢跑）
        $__uid = (int)($user->uid ?? 0);
        $__lockFile = pp_data_file() . '/job.lock';
        $__lock = is_file($__lockFile) ? (json_decode((string)@file_get_contents($__lockFile), true) ?: []) : [];
        if ($__lock && (time() - (int)($__lock['time'] ?? 0)) < 90 && ((int)($__lock['uid'] ?? 0) !== $__uid || (string)($__lock['job'] ?? '') !== $job)) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['finished' => true, 'total' => 0, 'done' => 0, 'current' => '', 'failed' => 0, 'msg' => '另一个维护任务正在进行，请稍后再试'], JSON_UNESCAPED_UNICODE);
            exit;
        }

    }
    set_time_limit(60);
    $result = ['finished' => true, 'total' => 0, 'done' => 0, 'current' => ''];

    try {
    if ($job === 'rebuild') {
        [$state, $list] = MaintenanceState::open(pp_data_file(), $job, (string)($_POST['mode'] ?? 'start'), (string)($_POST['job_id'] ?? ''), static function () use ($db) {
            $rows = AdminRepository::readAll($db->select('id')->from(ImageRepository::table())->where('original <> ?', ''), true);
            return array_map(static function ($row) { return (int)$row['id']; }, $rows);
        });
        if (!empty($state['finished'])) { pp_reply_json(true, '', $state); }
        pp_write_json($__lockFile, ['uid' => $__uid, 'job' => $job, 'time' => time()]);
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
        $syncedCids = [];
        for ($i = 0; $i < $batch && $idx < $total; $i++) {
            // 预算不足时立即返回，让下一轮 poll 继续，保证每轮请求都在短时间内完成
            if ((microtime(true) - $started) > $budget) {
                break;
            }
            $snapshot = $list[$idx];
            $rowId = is_array($snapshot) ? (int)$snapshot[0] : (int)$snapshot;
            // 快照只决定候选身份；路径与归属在每批主库重读，避免删除/改绑后重建旧记录。
            $live = AdminRepository::readRow($db->select()->from(ImageRepository::table())->where('id = ?', $rowId)->limit(1), true);
            if (!$live || empty($live['original'])) { $idx++; continue; }
            $item = [$rowId, $live['original'], $live['full'], $live['thumb'], (int)($live['cid'] ?? 0)];
            $src = ImageRepository::toAbs((string)$item[1]);
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
                    $res = MediaProcessor::process($src, ImageRepository::toAbs($item[2]), ImageRepository::toAbs($item[3]), $thumbMax, $quality, $mw, $fq);
                    ImageRepository::updateVariants((int)$item[0], $res);
                    if (!empty($item[4])) {
                        $syncedCids[(int)$item[4]] = true;
                    }
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
        foreach (array_keys($syncedCids) as $__c) {
            try { ImageRepository::syncPostFields((int)$__c); } catch (\Throwable $e) {
                Plugin::log('rebuild sync: cid=' . $__c . ' ' . $e->getMessage());
                $failed++;
                $state['current'] = 'cid ' . $__c . '（字段同步失败，可重建字段重试）';
            }
        }
        $state['done'] = $idx;
        $state['failed'] = $failed;
        $state['finished'] = $idx >= $total;
        MaintenanceState::save(pp_data_file(), $state);
        $result = $state;
    } elseif ($job === 'cleanup') {
        [$state, $list] = MaintenanceState::open(pp_data_file(), $job, (string)($_POST['mode'] ?? 'start'), (string)($_POST['job_id'] ?? ''), static function () {
            return ImageRepository::orphanCandidates();
        });
        if (!empty($state['finished'])) { pp_reply_json(true, '', $state); }
        pp_write_json($__lockFile, ['uid' => $__uid, 'job' => $job, 'time' => time()]);
        $idx = (int)($state['done'] ?? 0);
        $batch = 50;
        ImageRepository::removeOrphanCandidates(array_slice($list, $idx, $batch));
        for ($i = 0; $i < $batch && $idx < count($list); $i++) {
            $state['current'] = basename(is_array($list[$idx]) ? (string)($list[$idx]['path'] ?? '') : (string)$list[$idx]);
            $idx++;
        }
        $state['done'] = $idx;
        $state['finished'] = $idx >= count($list);
        MaintenanceState::save(pp_data_file(), $state);
        $result = $state;
    } elseif ($job === 'resync') {
        // 重算每篇图集的聚合字段（addresses/titles/descs/panos，并补上 dims 宽高数组）。
        // 用于给已发布的历史文章补齐文章字段，使首页瀑布流能拿到图片比例做占位。
        [$state, $list] = MaintenanceState::open(pp_data_file(), $job, (string)($_POST['mode'] ?? 'start'), (string)($_POST['job_id'] ?? ''), static function () use ($db, $prefix) {
            $cids = [];
            foreach (AdminRepository::readAll($db->select('cid')->from(ImageRepository::table()), true) as $r) {
                $cids[(int)$r['cid']] = true;
            }
            foreach (AdminRepository::readAll($db->select('cid')->from($prefix . 'fields')->where('name = ?', 'img'), true) as $r) {
                $cids[(int)$r['cid']] = true;
            }
            $list = array_values(array_filter(array_map('intval', array_keys($cids))));
            sort($list);
            return $list;
        });
        if (!empty($state['finished'])) { pp_reply_json(true, '', $state); }
        pp_write_json($__lockFile, ['uid' => $__uid, 'job' => $job, 'time' => time()]);
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
        MaintenanceState::save(pp_data_file(), $state);
        $result = $state;
    } elseif ($job === 'albums_html') {
        // 局部刷新图集列表：只返回卡片 HTML，避免为刷新列表重新渲染整个后台页面
        header('Content-Type: text/html; charset=utf-8');
        echo pp_render_albums_card(pp_albums(), $options);
        exit;
    } elseif ($job === 'album_status') {
        $cid = (int)($_GET['cid'] ?? 0);
        $album = AdminRepository::album($cid, (int)$user->uid, pp_is_admin());
        if (!$album) { pp_reply_json(false, '图集不存在或没有权限', [], 404); }
        $counts = AdminRepository::counts([$cid]);
        pp_reply_json(true, '', ['cid' => $cid, 'title' => (string)$album['title'], 'status' => $album['status'], 'count' => $counts[$cid] ?? 0]);
    } elseif ($job === 'album_images') {
        // 图集图片按需加载：展开某个图集时才拉取它的图片列表
        header('Content-Type: text/html; charset=utf-8');
        $cid = (int)($_GET['cid'] ?? 0);
        if (!AdminRepository::album($cid, (int)$user->uid, pp_is_admin())) {
            http_response_code(403);
            echo '<div class="pp-meta">图集不存在或没有权限查看</div>';
            exit;
        }
        echo pp_render_album_thumbs($cid > 0 ? ImageRepository::rowsFor($cid) : [], pp_can_edit_cid($cid));
        exit;
    }

    } catch (\Throwable $e) {
        Plugin::log('maintenance: ' . $e->getMessage());
        pp_reply_json(false, $e->getMessage(), ['retryable' => true], 500);
    }

    if ($ppMaintenance && !empty($result['finished'])) { @unlink(pp_data_file() . '/job.lock'); }
    if ($ppMaintenance && empty($_POST['ajax'])) {
        pp_reply('维护任务已处理 ' . (int)($result['done'] ?? 0) . ' / ' . (int)($result['total'] ?? 0) . (!empty($result['finished']) ? '，已完成' : '；再次提交可继续'));
    }
    header('Content-Type: application/json');
    echo json_encode($result, JSON_UNESCAPED_UNICODE);
    exit;
}

/* ---------------------------------- POST 处理 ---------------------------------- */

if ($ppMethod === 'POST') {
    $action = $ppAction;
    $ajax = !empty($_POST['ajax']);

    if (in_array($action, ['create_draft', 'upload_image', 'publish_album', 'create_album'], true)) {
        try {
            if ($action === 'create_draft') {
                $draft = AdminWorkflow::draft((int)$user->uid, $_POST, (string)($_POST['operation_key'] ?? ''));
                pp_reply_json(true, '草稿已保存', $draft);
            }
            $cid = (int)($_POST['cid'] ?? 0);
            if ($action === 'publish_album') {
                AdminWorkflow::publish($cid, (int)$user->uid, pp_is_admin(), pp_can_publish());
                if ($ajax) { pp_reply_json(true, '图集已发布', ['cid' => $cid, 'status' => 'publish']); }
                pp_reply('图集已发布');
            }
            if ($action === 'upload_image') {
                $result = AdminWorkflow::upload($cid, (int)$user->uid, pp_is_admin(), (array)($_FILES['file'] ?? []), $_POST, (string)($_POST['item_key'] ?? ''), pp_can_publish());
                pp_reply_json(true, !empty($result['replayed']) ? '图片已保存（已恢复上传结果）' : '图片已保存', $result);
            }
            // 无 JavaScript 的原生回退：沿用草稿与幂等规则，每次处理有上限的一批。
            $key = (string)($_POST['operation_key'] ?? '');
            if (!AdminSecurity::validKey($key)) { throw new \InvalidArgumentException('上传标识无效，请刷新页面'); }
            if (empty($_FILES['files']['name']) || !is_array($_FILES['files']['name'])) {
                throw new \InvalidArgumentException('没有收到图片，请检查上传限制并重新选择文件');
            }
            if ($cid > 0) { AdminWorkflow::requireAlbum($cid, (int)$user->uid, pp_is_admin(), pp_can_publish()); }
            else {
                $draft = AdminWorkflow::draft((int)$user->uid, $_POST, $key);
                $cid = (int)$draft['cid'];
            }
            $saved = 0; $failed = 0; $errors = [];
            $count = count($_FILES['files']['name']);
            for ($i = 0; $i < min(50, $count); $i++) {
                $file = [];
                foreach (['name', 'tmp_name', 'size', 'error'] as $field) { $file[$field] = $_FILES['files'][$field][$i] ?? null; }
                try {
                    AdminWorkflow::upload($cid, (int)$user->uid, pp_is_admin(), $file, [
                        'title' => $_POST['img_titles'][$i] ?? '', 'desc' => $_POST['img_descs'][$i] ?? '',
                        'address' => $_POST['address'] ?? '',
                    ], hash('sha256', $key . ':' . $i), pp_can_publish());
                    $saved++;
                } catch (\Throwable $e) {
                    $failed++;
                    $errors[] = (string)$file['name'] . '：' . $e->getMessage();
                }
            }
            $failed += max(0, $count - 50);
            $msg = '图集 #' . $cid . ' 已保存 ' . $saved . ' 张图片。新图集保留为草稿，请在图集列表中确认发布。';
            if ($failed) { $msg .= '失败 ' . $failed . ' 张；成功项目已保留。' . implode('；', array_slice($errors, 0, 3)); }
            if ($ajax) { pp_reply_json(!$failed, $msg, ['cid' => $cid, 'saved' => $saved, 'failed' => $failed]); }
            pp_reply($msg, $failed ? 'error' : 'success', $cid);
        } catch (\Throwable $e) {
            Plugin::log('admin upload workflow: ' . $e->getMessage());
            if ($ajax) { pp_reply_json(false, $e->getMessage(), ['retryable' => $e instanceof \RuntimeException], $e instanceof \DomainException ? 403 : 400); }
            pp_reply($e->getMessage(), 'error');
        }
    }

    if ($action === 'update_album') {
        $ajax = !empty($_POST['ajax']);
        $cid = (int)($_POST['cid'] ?? 0);
        if (!pp_can_edit_cid($cid)) { pp_deny('没有权限修改该图集'); }
        if ($cid > 0) {
            $title = trim((string)($_POST['title'] ?? ''));
            if ($title !== '') {
                Database::query($db->update($prefix . 'contents')->rows(['title' => $title])->where('cid = ?', $cid));
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
            $row = AdminRepository::readRow($db->select('cid')->from(ImageRepository::table())->where('id = ?', $rowId)->limit(1), true);
            if (!$row || !pp_can_edit_cid((int)($row['cid'] ?? 0))) { pp_deny('没有权限修改该图片'); }
            ImageRepository::setImageMeta($rowId, $title, $desc, $addr);
            if ($row && (int)$row['cid'] > 0) {
                ImageRepository::syncPostFields((int)$row['cid']);
            }
        } else {
            pp_deny('没有权限修改该图片');
        }
        if ($ajax) { pp_reply_json(true, _t('已保存图片信息')); }
        pp_reply(_t('已保存图片信息'));
    }

    if ($action === 'sort_images') {
        $ajax = !empty($_POST['ajax']);
        $cid = (int)($_POST['cid'] ?? 0);
        if (!pp_can_edit_cid($cid)) { pp_deny('没有权限修改该图集'); }
        $rowIds = array_map('intval', (array)($_POST['rowIds'] ?? []));
        $existing = array_map(static function ($row) { return (int)$row['id']; }, AdminRepository::readAll($db->select('id')->from(ImageRepository::table())->where('cid = ?', $cid), true));
        $requested = $rowIds; sort($requested); sort($existing);
        if ($requested !== $existing || count($rowIds) !== count(array_unique($rowIds))) {
            pp_deny('图片列表已变化或包含其他图集图片，请刷新后重试');
        }
        $order = 0;
        foreach ($rowIds as $rid) {
            $rid = (int)$rid;
            if ($rid <= 0) {
                continue;
            }
            Database::query($db->update(ImageRepository::table())->rows(['sort' => $order])->where('id = ?', $rid)->where('cid = ?', $cid));
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
            $row = AdminRepository::readRow($db->select()->from(ImageRepository::table())->where('id = ?', $rowId)->limit(1), true);
            if (!$row || !pp_can_edit_cid((int)($row['cid'] ?? 0))) { pp_deny('没有权限删除该图片'); }
            try {
                if (!ImageRepository::deleteImage($rowId, (int)$row['cid'])) {
                    if ($ajax) { pp_reply_json(false, '图片已变化，请刷新后重试', [], 409); }
                    pp_reply('图片已变化，请刷新后重试', 'error');
                }
            } catch (\Throwable $e) {
                Plugin::log('delete_image: ' . $e->getMessage());
                // 提交结果未知时不能声称数据库已回滚；实体文件仍保留供核对与重试。
                if ($ajax) { pp_reply_json(false, '删除暂未确认，请刷新核对后重试', ['retryable' => true], 500); }
                pp_reply('删除暂未确认，请刷新核对后重试', 'error');
            }
        } else {
            pp_deny('没有权限删除该图片');
        }
        if ($ajax) { pp_reply_json(true, _t('已删除该图片')); }
        pp_reply(_t('已删除该图片'));
    }

    if ($action === 'preview_non_plugin' || $action === 'delete_non_plugin') {
        pp_require_admin(); // 一键清理全站非插件文章：仅管理员
        // 汇总“本插件发布的图集”cid：有 img 自定义字段 或 在 infinitytime_images 表里
        $pluginCids = [];
        foreach (AdminRepository::readAll($db->select('cid')->from($prefix . 'fields')->where('name IN (?, ?)', 'img', AdminRepository::MARKER), true) as $f) {
            $pluginCids[(int)$f['cid']] = true;
        }
        foreach (AdminRepository::readAll($db->select('cid')->from(ImageRepository::table()), true) as $f) {
            $pluginCids[(int)$f['cid']] = true;
        }
        $rows = AdminRepository::readAll($db->select('cid', 'title')->from($prefix . 'contents')->where('type = ?', 'post'), true);
        $nonCount = 0;
        $titles = [];
        $isDelete = ($action === 'delete_non_plugin');
        // 注意：SQLite 只支持 BEGIN，不支持 MySQL 的 START TRANSACTION（BEGIN 在两者都可用）
        if ($isDelete) { Database::query('BEGIN'); }
        try {
            foreach ($rows as $r) {
                $cid = (int)$r['cid'];
                if (isset($pluginCids[$cid])) {
                    continue;
                }
                $nonCount++;
                if ($isDelete) {
                    Database::query($db->delete($prefix . 'fields')->where('cid = ?', $cid));
                    Database::query($db->delete($prefix . 'contents')->where('cid = ?', $cid));
                } elseif (count($titles) < 20) {
                    $titles[] = (string)($r['title'] ?? '');
                }
            }
            if ($isDelete) { Database::query('COMMIT'); }
        } catch (\Throwable $e) {
            if ($isDelete) { Database::query('ROLLBACK'); }
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
        pp_require_admin(); // 全站图片处理参数：仅管理员
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
        if (!pp_can_edit_cid($cid)) { pp_deny('没有权限删除该图集'); }
        if ($cid > 0) {
            try {
                if (!ImageRepository::deleteAlbum($cid)) {
                    if ($ajax) { pp_reply_json(false, '图集已变化，请刷新后重试', [], 409); }
                    pp_reply('图集已变化，请刷新后重试', 'error');
                }
            } catch (\Throwable $e) {
                Plugin::log('delete_album: ' . $e->getMessage());
                if ($ajax) { pp_reply_json(false, '删除暂未确认，请刷新核对后重试', ['retryable' => true], 500); }
                pp_reply('删除暂未确认，请刷新核对后重试', 'error');
            }
        }
        if ($ajax) { pp_reply_json(true, _t('已删除图集')); }
        pp_reply(_t('已删除图集'));
    }

    if ($action === 'save_site') {
        pp_require_admin(); // 站点信息 / 关于：仅管理员
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
        Plugin::setOption('infinitytimeSiteLogo', $logo);
        // 先保存新引用；仅实际上传替换成功时清理旧头像，普通设置保存不触碰文件。
        if ($newAvatarAbs !== null && $logo !== $oldLogo) {
            pp_clear_site_avatar($oldLogo, $newAvatarAbs);
        }
        Plugin::setOption('infinitytimeSiteName', trim((string)($_POST['siteName'] ?? '')));
        Plugin::setOption('infinitytimeSiteTagline', trim((string)($_POST['siteTagline'] ?? '')));
        Plugin::setOption('infinitytimeAbout', pp_sanitize_html((string)($_POST['aboutText'] ?? '')));
        if ($ajax) { pp_reply_json(true, _t('已保存站点信息')); }
        pp_reply(_t('已保存站点信息'));
    }

    if ($action === 'save_contacts') {
        pp_require_admin(); // 联系方式：仅管理员
        $ajax = !empty($_POST['ajax']);
        $names = (array)($_POST['contactName'] ?? []);
        $urls = (array)($_POST['contactUrl'] ?? []);
        $icons = (array)($_POST['contactIcon'] ?? []);
        $status = (array)($_POST['contactStatus'] ?? []);
        $contacts = [];
        foreach ($names as $i => $name) {
            $name = trim((string)$name);
            $urlRaw = trim((string)($urls[$i] ?? ''));
            if ($name === '' && $urlRaw === '') {
                continue;
            }
            // 联系方式是前台 href，必须校验协议（否则可存 javascript: 链接）
            $url = \TypechoPlugin\InfinityTime\Lib\Sanitizer::safeLink($urlRaw);
            if ($urlRaw !== '' && $url === '') {
                if ($ajax) { pp_reply_json(false, _t('联系方式链接只支持 http(s) / mailto / tel / 站内相对路径')); }
                pp_reply(_t('联系方式链接只支持 http(s) / mailto / tel / 站内相对路径'), 'error');
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

function pp_albums(): array
{
    global $user;
    return AdminRepository::page((int)$user->uid, pp_is_admin(), max(1, (int)($_GET['page'] ?? 1)), is_string($_GET['q'] ?? null) ? $_GET['q'] : '');
}

/** 仅渲染当前页的图集摘要；图片列表按需读取。 */
function pp_render_albums_card(array $result, $options): string
{
    $albums = $result['items'];
    $page = (int)$result['page'];
    $query = (string)$result['query'];
    ob_start();
    ?>
    <div class="pp-card" id="pp-albums-card" data-page="<?php echo $page; ?>" data-query="<?php echo htmlspecialchars($query); ?>">
      <h2>图集 <span class="pp-sub">共 <?php echo (int)$result['total']; ?> 组 · 每页 <?php echo AdminRepository::PAGE_SIZE; ?> 组</span></h2>
      <form method="get" action="<?php echo htmlspecialchars(Helper::url('InfinityTime/panel.php')); ?>#albums" class="pp-album-search">
        <input type="hidden" name="panel" value="InfinityTime/panel.php">
        <label for="pp-album-query">搜索图集标题</label>
        <input id="pp-album-query" type="search" name="q" maxlength="100" value="<?php echo htmlspecialchars($query); ?>">
        <button type="submit" class="pp-btn gray pp-small">搜索</button>
      </form>
      <?php if (!$albums): ?>
        <div class="pp-meta">暂无图集。</div>
      <?php else: foreach ($albums as $al):
          $cid = (int)$al['cid'];
          $open = (int)($_GET['open'] ?? 0) === $cid;
          $count = (int)$al['img_count'];
          $canMutate = $al['status'] === 'draft' || pp_can_publish(); ?>
        <details class="pp-album"<?php echo $open ? ' open' : ''; ?>>
          <summary class="pp-album-summary">
            <strong><?php echo htmlspecialchars($al['title']); ?></strong>
            <span class="pp-meta">（<?php echo $count; ?> 张）· <?php echo $al['status'] === 'draft' ? '草稿' : '已发布'; ?></span>
          </summary>
          <div class="pp-album-toolbar">
            <span class="pp-meta">共 <?php echo $count; ?> 张<?php echo $canMutate ? ' · 拖动图片可调整顺序' : ' · 只读'; ?></span>
            <span class="pp-album-actions">
              <a class="pp-meta" href="<?php echo htmlspecialchars(pp_panel_url(['page' => $page, 'q' => $query, 'open' => $cid])); ?>#albums">查看图片 / 无脚本编辑</a>
              <?php if ($canMutate): ?>
              <a class="pp-btn gray pp-small" href="<?php echo htmlspecialchars(pp_panel_url(['append' => $cid])); ?>#upload" data-append-cid="<?php echo $cid; ?>" data-append-title="<?php echo htmlspecialchars($al['title']); ?>">追加图片</a>
              <?php if ($al['status'] === 'draft' && pp_can_publish()): ?>
              <form method="post" style="display:inline" action="<?php echo htmlspecialchars(pp_panel_url()); ?>">
                <?php echo pp_csrf_input(); ?>
                <input type="hidden" name="action" value="publish_album">
                <input type="hidden" name="cid" value="<?php echo $cid; ?>">
                <button class="pp-btn pp-small" type="submit">确认发布图集</button>
              </form>
              <?php endif; ?>
              <form method="post" action="<?php echo htmlspecialchars(pp_panel_url()); ?>" style="display:inline" class="pp-delete-album">
                <?php echo pp_csrf_input(); ?>
                <input type="hidden" name="action" value="delete_album">
                <input type="hidden" name="cid" value="<?php echo $cid; ?>">
                <button class="pp-btn red pp-small" type="submit" data-loading="删除中…">删除</button>
              </form>
              <?php else: ?><span class="pp-meta">已发布图集只读；修改需编辑或管理员权限</span><?php endif; ?>
            </span>
          </div>
          <?php if ($canMutate): ?>
          <details class="pp-album-edit">
            <summary>编辑图集信息</summary>
            <form method="post" action="<?php echo htmlspecialchars(pp_panel_url()); ?>">
              <?php echo pp_csrf_input(); ?>
              <input type="hidden" name="action" value="update_album">
              <input type="hidden" name="cid" value="<?php echo $cid; ?>">
              <div class="pp-grid" style="margin-top:10px">
                <div class="pp-row"><label>标题</label><input type="text" name="title" required value="<?php echo htmlspecialchars($al['title']); ?>"></div>
                <div class="pp-row"><label>设备</label><input type="text" name="device" value="<?php echo htmlspecialchars($al['device']); ?>"></div>
                <div class="pp-row"><label>标签</label><input type="text" name="tags" value="<?php echo htmlspecialchars($al['tags']); ?>"></div>
                <div class="pp-row"><label>地点 / 地址</label><input type="text" name="address" value="<?php echo htmlspecialchars($al['location']); ?>"></div>
              </div>
              <button class="pp-btn" type="submit" style="margin-top:6px">保存</button>
            </form>
          </details>
          <?php endif; ?>
          <div class="pp-thumbs" data-cid="<?php echo $cid; ?>" data-loaded="<?php echo $open ? '1' : '0'; ?>"><?php
            if ($open) { echo pp_render_album_thumbs(ImageRepository::rowsFor($cid), $canMutate); }
            else { echo '<div class="pp-meta">展开后加载图片…</div>'; }
          ?></div>
        </details>
      <?php endforeach; endif; ?>
      <nav class="pp-album-pagination pp-pagination" aria-label="图集分页">
        <?php if ($page > 1): ?><a class="pp-btn gray pp-small" href="<?php echo htmlspecialchars(pp_panel_url(['page' => $page - 1, 'q' => $query])); ?>#albums">上一页</a><?php endif; ?>
        <span>第 <?php echo $page; ?> / <?php echo (int)$result['pages']; ?> 页</span>
        <?php if ($page < (int)$result['pages']): ?><a class="pp-btn gray pp-small" href="<?php echo htmlspecialchars(pp_panel_url(['page' => $page + 1, 'q' => $query])); ?>#albums">下一页</a><?php endif; ?>
      </nav>
    </div>
    <?php
    return (string)ob_get_clean();
}

/** 渲染单个图集的图片列表（展开时 AJAX 按需加载）。 */
function pp_render_album_thumbs(array $images, bool $canEdit = true): string
{
    ob_start();
    ?>
    <?php foreach ($images as $img): ?>
      <div class="pp-img">
        <img src="<?php echo htmlspecialchars(ImageRepository::toWeb(ImageRepository::toAbs($img['thumb']))); ?>" alt="" loading="lazy" decoding="async">
        <div class="cap"><?php echo htmlspecialchars(pp_exif_summary((array)($img['exif'] ?? []))); ?></div>
        <div class="dims"><?php echo $img['width']; ?>×<?php echo $img['height']; ?></div>
        <?php if ($canEdit): ?>
        <form method="post" action="<?php echo htmlspecialchars(Helper::url('InfinityTime/panel.php')); ?>">
          <?php echo pp_csrf_input(); ?>
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
          <?php echo pp_csrf_input(); ?>
          <input type="hidden" name="action" value="delete_image">
          <input type="hidden" name="rowId" value="<?php echo $img['id']; ?>">
          <button class="pp-btn red" type="submit">删除</button>
        </form>
        <?php endif; ?>
      </div>
    <?php endforeach; ?>
    <?php
    return (string)ob_get_clean();
}

$notice = $options->request->get('notice');
$noticeType = $options->request->get('noticeType', 'success');
$albums = pp_albums();
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
$ppUploadTarget = null;
if (!empty($_GET['append'])) {
    $ppUploadTarget = AdminRepository::album((int)$_GET['append'], (int)$user->uid, pp_is_admin());
    if ($ppUploadTarget) {
        $counts = AdminRepository::counts([(int)$ppUploadTarget['cid']]);
        $ppUploadTarget['count'] = $counts[(int)$ppUploadTarget['cid']] ?? 0;
    } else {
        $notice = '图集不存在或没有权限追加图片'; $noticeType = 'error';
    }
}
$ppOperationKey = bin2hex(random_bytes(16));
try { $ppJobState = pp_read_json(pp_data_file() . '/job.json'); }
catch (\Throwable $e) { $ppJobState = []; $notice = $e->getMessage(); $noticeType = 'error'; }

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
        <button type="button" class="pp-tab" data-tab="albums">图集与草稿</button>
        <button type="button" class="pp-tab" data-tab="site">站点信息</button>
        <button type="button" class="pp-tab" data-tab="contacts">联系方式</button>
        <button type="button" class="pp-tab" data-tab="convert">转换设置</button>
        <button type="button" class="pp-tab" data-tab="maintain">维护</button>
      </nav>
      <!-- 上传发布 -->
      <section class="pp-panel active" data-panel="upload">
      <div class="pp-card">
        <h2>上传图片</h2>
        <p class="pp-meta" id="pp-upload-target"><?php echo $ppUploadTarget ? '追加到「' . htmlspecialchars($ppUploadTarget['title']) . '」' : '新图集先保存草稿，上传完成后再确认发布。'; ?></p>
        <form id="pp-upload-form" method="post" enctype="multipart/form-data" action="<?php echo htmlspecialchars(Helper::url('InfinityTime/panel.php')); ?>">
          <?php echo pp_csrf_input(); ?>
          <input type="hidden" name="action" value="create_album">
          <input type="hidden" name="operation_key" value="<?php echo $ppOperationKey; ?>">
          <input type="hidden" name="cid" id="pp-upload-cid" value="<?php echo (int)($ppUploadTarget['cid'] ?? 0); ?>">
          <div class="pp-grid">
            <div>
              <div class="pp-row"><label>图集标题 *</label><input type="text" name="title" required value="<?php echo htmlspecialchars($ppUploadTarget['title'] ?? ''); ?>"></div>
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
            <button class="pp-btn" id="pp-upload-submit" type="submit">保存图片到图集</button>
            <button class="pp-btn gray" id="pp-upload-retry" type="button" hidden>仅重试失败图片</button>
            <button class="pp-btn" id="pp-upload-publish" type="button" hidden>确认发布图集</button>
            <button class="pp-btn gray" id="pp-upload-new" type="button" hidden>新建另一图集</button>
            <span class="pp-meta">逐张上传并转换 WebP，按设置保留原图。已保存图片刷新后仍在图集中；未完成图片需重新选择本地文件。</span>
          </div>
        </form>
      </div>
      </section>
      <!-- 站点信息 / 关于 -->
      <section class="pp-panel" data-panel="site">
      <div class="pp-card">
        <h2>站点信息 / 关于</h2>
        <form method="post" enctype="multipart/form-data" action="<?php echo htmlspecialchars(Helper::url('InfinityTime/panel.php')); ?>">
          <?php echo pp_csrf_input(); ?>
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
          <?php echo pp_csrf_input(); ?>
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
          <?php echo pp_csrf_input(); ?>
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
            <noscript><form method="post" action="<?php echo htmlspecialchars(pp_panel_url()); ?>">
              <?php echo pp_csrf_input(); ?>
              <input type="hidden" name="action" value="maintenance">
              <input type="hidden" name="job" value="cleanup">
              <button class="pp-btn gray" type="submit">清理孤儿文件（处理 / 继续一批）</button>
            </form></noscript>
            <div class="pp-progress"><div class="pp-bar-outer"><div class="pp-bar" id="pp-bar-cleanup"></div></div><span class="pp-msg" id="pp-msg-cleanup"></span></div>
            <div class="pp-meta" style="margin-top:8px">删除所有不被任何图集引用的 original/full/thumb 文件，并清理空目录。</div>
          </div>
          <div>
            <button class="pp-btn gray" type="button" data-run="rebuild">重建缩略图/全图</button>
            <noscript><form method="post" action="<?php echo htmlspecialchars(pp_panel_url()); ?>">
              <?php echo pp_csrf_input(); ?>
              <input type="hidden" name="action" value="maintenance">
              <input type="hidden" name="job" value="rebuild">
              <button class="pp-btn gray" type="submit">重建缩略图/全图（处理 / 继续一批）</button>
            </form></noscript>
            <div class="pp-progress"><div class="pp-bar-outer"><div class="pp-bar" id="pp-bar-rebuild"></div></div><span class="pp-msg" id="pp-msg-rebuild"></span></div>
            <div class="pp-meta" style="margin-top:8px">按当前质量/尺寸设置，用原图重新生成全部 full/thumb（改设置后批量重做）。</div>
          </div>
          <div>
            <button class="pp-btn gray" type="button" data-run="resync">重建尺寸字段</button>
            <noscript><form method="post" action="<?php echo htmlspecialchars(pp_panel_url()); ?>">
              <?php echo pp_csrf_input(); ?>
              <input type="hidden" name="action" value="maintenance">
              <input type="hidden" name="job" value="resync">
              <button class="pp-btn gray" type="submit">重建尺寸字段（处理 / 继续一批）</button>
            </form></noscript>
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
      'userId' => (int)$user->uid,
      'canPublish' => pp_can_publish(),
      'uploadTarget' => $ppUploadTarget,
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
    ], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
    </script>
    <script src="<?php echo htmlspecialchars($ppPluginWeb . '/assets/admin.js'); ?>"></script>
</main>
<?php include $adminDir . '/footer.php'; ?>

