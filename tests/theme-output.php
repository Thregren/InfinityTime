<?php
declare(strict_types=1);
require __DIR__ . '/../usr/themes/InfinityTime/functions.php';
error_reporting(E_ALL);
set_error_handler(static function ($severity, $message) { throw new RuntimeException($message); });

function expectOutput(bool $result, string $message): void {
    if (!$result) { throw new RuntimeException($message); }
}
expectOutput(pp_safe_about('<p onclick="x()">中文</p>') === '中文', '无插件时关于内容安全降级');
expectOutput(pp_safe_contact_url('javascript:alert(1)') === '', '无插件时拒绝危险链接');
expectOutput(pp_safe_contact_url('mailto:a@example.com') === 'mailto:a@example.com', '无插件时正常链接可用');
expectOutput(pp_date_cn('2026:09:14') === '2026年09月14日', '仅日期无时间不产生 warning');

require __DIR__ . '/../usr/plugins/InfinityTime/Lib/Sanitizer.php';
expectOutput(pp_safe_about('<section><p onclick="x()">中文</p></section>') === '<p>中文</p>', '插件可用时保留安全排版');
expectOutput(pp_safe_contact_url('java&#13;script:alert(1)') === '', '历史链接输出仍需校验');
echo "Theme output: 6 checks passed\n";
