<?php
/** 渲染真实后台模板，检查原生表单令牌、分页和草稿发布控件。 */
declare(strict_types=1);
namespace Widget {
    class User {
        public int $uid = 7;
        public static bool $administrator = true;
        public static function alloc(): self { return new self(); }
        public function pass($role, $return): bool { return $role === 'contributor' || self::$administrator; }
    }
    class Security {
        public static string $token = 'fixture-panel-token';
        public static function alloc(): self { return new self(); }
        public function getToken($scope): string { return self::$token; }
    }
}
namespace Utils {
    class Helper {
        public static object $options;
        public static function options(): object { return self::$options; }
        public static function url($path, $base = null): string { return '/admin/extending.php?panel=InfinityTime%2Fpanel.php'; }
    }
}
namespace TypechoPlugin\InfinityTime\Lib {
    class MediaProcessor {
        public static function detectTools(): array { return []; }
    }
}
namespace {
    function _t($message): string { return $message; }
    ob_start();
    require __DIR__ . '/admin-workflow.php';
    ob_end_clean();
    \Typecho\Db::get()->adapter = 'Pdo_SQLite';
    \Utils\Helper::$options = (object)[
        'siteUrl' => 'https://example.test/', 'theme' => 'InfinityTime',
        'request' => new class { public function get($key, $default = null) { return $default; } },
    ];
    define('__TYPECHO_ADMIN__', true);
    define('__TYPECHO_ROOT_DIR__', dirname(__DIR__));
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $_GET = []; $_POST = [];
    $mode = $argv[1] ?? 'render';
    if ($mode !== 'render') {
        $_SERVER['HTTP_HOST'] = 'example.test';
        $_SERVER['HTTP_REFERER'] = 'https://example.test/admin/';
        $expected = 403;
        if ($mode === 'get-maintenance') {
            $_GET = ['ajax' => '1', 'job' => 'cleanup', '_' => 'fixture-panel-token'];
            $expected = 405;
        } else {
            $_SERVER['REQUEST_METHOD'] = 'POST';
            $_POST = ['action' => 'update_album', 'ajax' => '1', 'cid' => (int)$draft['cid'], 'title' => '不应被写入', '_' => 'fixture-panel-token'];
            if ($mode === 'post-no-token') { unset($_POST['_']); }
            elseif ($mode === 'post-wrong-token') { $_POST['_'] = 'wrong-token'; }
            elseif ($mode === 'post-array-token') { $_POST['_'] = ['fixture-panel-token']; }
            elseif ($mode === 'post-token-unavailable') { \Widget\Security::$token = ''; }
            elseif ($mode === 'post-non-album') { $_POST['cid'] = 500; }
            elseif ($mode === 'post-foreign-album') { \Widget\User::$administrator = false; $_POST['cid'] = (int)$other['cid']; }
            elseif ($mode === 'post-contributor-published') { \Widget\User::$administrator = false; }
            else { throw new RuntimeException('未知请求测试'); }
        }
        $before = \Typecho\Db::get()->fetchAll('SELECT * FROM admin_contents ORDER BY cid');
        ob_start();
        register_shutdown_function(static function () use ($mode, $expected, $before) {
            $output = ob_get_clean();
            $result = json_decode($output, true);
            $after = \Typecho\Db::get()->fetchAll('SELECT * FROM admin_contents ORDER BY cid');
            if (http_response_code() !== $expected || !is_array($result) || ($result['ok'] ?? true) !== false || $before !== $after) {
                fwrite(STDERR, '后台请求拒绝或数据保持检查失败：' . $mode . ' ' . $output . "\n");
                exit(1);
            }
            echo '后台请求拒绝与数据保持检查通过：' . $mode . "\n";
        });
        require __DIR__ . '/../usr/plugins/InfinityTime/panel.php';
        throw new RuntimeException('应拒绝的请求继续执行了模板');
    }
    $adminDir = sys_get_temp_dir() . '/infinity-admin-shell-' . bin2hex(random_bytes(5));
    mkdir($adminDir, 0700);
    foreach (['header.php', 'menu.php', 'footer.php'] as $name) { file_put_contents($adminDir . '/' . $name, ''); }
    $_SERVER['SCRIPT_FILENAME'] = $adminDir . '/extending.php';
    $dataDir = dirname(__DIR__) . '/usr/plugins/InfinityTime/data';
    $hadData = is_dir($dataDir);
    try {
        ob_start();
        require __DIR__ . '/../usr/plugins/InfinityTime/panel.php';
        $html = ob_get_clean();
        preg_match_all('/<form\b[^>]*method="post"[^>]*>(.*?)<\/form>/s', $html, $forms);
        if (count($forms[1]) < 10) { throw new RuntimeException('应渲染足够的原生 POST 表单'); }
        foreach ($forms[1] as $body) {
            if (substr_count($body, 'name="_" value="fixture-panel-token"') !== 1) {
                throw new RuntimeException('每个原生 POST 表单必须且只能携带一个有效令牌');
            }
        }
        if (substr_count($html, 'class="pp-album"') !== 20 || strpos($html, 'name="action" value="publish_album"') === false
            || strpos($html, 'data-page="1"') === false || strpos($html, 'name="operation_key"') === false) {
            throw new RuntimeException('分页、明确发布或原生上传幂等字段缺失');
        }
        if (strpos($html, 'data-loaded="1"') !== false) { throw new RuntimeException('摘要首屏不应加载图片详情'); }
        if (strpos($html, '只读健康检查') === false || strpos($html, '在隔离环境做一次恢复演练') === false) { throw new RuntimeException('管理员健康检查与恢复步骤缺失'); }
        echo '后台模板：' . count($forms[1]) . " 个原生表单令牌、分页、草稿与按需详情检查通过\n";
    } finally {
        foreach (['header.php', 'menu.php', 'footer.php'] as $name) { unlink($adminDir . '/' . $name); }
        rmdir($adminDir);
        if (!$hadData && is_dir($dataDir)) { rmdir($dataDir); }
    }
}
