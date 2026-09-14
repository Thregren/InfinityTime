<?php
declare(strict_types=1);

namespace TypechoPlugin\InfinityTime\Lib {
    // 仅此隔离进程模拟缺少 DOM 扩展，不改变生产类或依赖本机 PHP 编译选项。
    function class_exists(string $name): bool
    {
        return $name === '\\DOMDocument' ? false : \class_exists($name);
    }
}

namespace {
    require __DIR__ . '/../usr/plugins/InfinityTime/Lib/Sanitizer.php';
    $result = \TypechoPlugin\InfinityTime\Lib\Sanitizer::sanitize(
        '<p onclick="alert(1)">中文 &lt;img src=x onerror=alert(1)&gt;<a href="javascript:alert(1)">链接</a></p>'
    );
    if (strpos($result, '<') !== false || strpos($result, '中文') === false || strpos($result, '链接') === false) {
        throw new RuntimeException('无 DOM 扩展时必须降级为安全文字');
    }
    echo "Sanitizer no-DOM fallback passed\n";
}
