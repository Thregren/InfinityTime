<?php
declare(strict_types=1);
namespace Typecho {
    class Plugin {
        public static function factory($name): object { return new \stdClass(); }
    }
}
namespace {
    // Typecho 最小启动替身；验证钩子失败返回约定，无需数据库或上传文件。
    interface Typecho_Plugin_Interface {}
    require __DIR__ . '/../usr/plugins/InfinityTime/Lib/MediaProcessor.php';
    require_once __DIR__ . '/../usr/plugins/InfinityTime/Lib/Database.php';
    require __DIR__ . '/../usr/plugins/InfinityTime/Plugin.php';
    if (\TypechoPlugin\InfinityTime\Plugin::uploadHandle([]) !== false) {
        throw new RuntimeException('空上传应返回 false');
    }
    echo "Upload fallback failure contract passed\n";
}
