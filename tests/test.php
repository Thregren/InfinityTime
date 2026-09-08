<?php
/**
 * InfinityTime 轻量单测：只测不依赖 Typecho / 数据库 / GD 的纯逻辑。
 * 运行：php tests/test.php
 */
declare(strict_types=1);

require __DIR__ . '/../usr/plugins/InfinityTime/Lib/Sanitizer.php';
require __DIR__ . '/../usr/plugins/InfinityTime/Lib/ImageRepository.php';
require __DIR__ . '/../usr/plugins/InfinityTime/Lib/MediaProcessor.php';

use TypechoPlugin\InfinityTime\Lib\Sanitizer;
use TypechoPlugin\InfinityTime\Lib\ImageRepository;
use TypechoPlugin\InfinityTime\Lib\MediaProcessor;

$pass = 0;
$fail = 0;
function check(string $name, bool $cond): void
{
    global $pass, $fail;
    if ($cond) {
        $pass++;
        echo "  ok   $name\n";
    } else {
        $fail++;
        echo "  FAIL $name\n";
    }
}

echo "Sanitizer::sanitize\n";
$cases = [
    ['<p>hi <b>there</b></p>', '<p>hi <b>there</b></p>'],
    ['<script>alert(1)</script><p>ok</p>', '<p>ok</p>'],
    ['<img src=x onerror=alert(1)>', '<img src=x>'],
    ['<a href="javascript:alert(1)">x</a>', '<a href="#">x</a>'],
    ["<a href='javascript:alert(1)'>x</a>", '<a href="#">x</a>'],
    ['<a href=javascript:alert(1)>x</a>', '<a href="#">x</a>'],
    ['<a href="data:text/html,x">x</a>', '<a href="#">x</a>'],
    ['<iframe src="https://evil"></iframe>', ''],
];
foreach ($cases as $i => $pair) {
    check('case #' . $i, Sanitizer::sanitize($pair[0]) === $pair[1]);
}

echo "Sanitizer::validUrl\n";
check('https', Sanitizer::validUrl('https://example.com/a.png') === 'https://example.com/a.png');
check('http', Sanitizer::validUrl('http://example.com/a.png') === 'http://example.com/a.png');
check('protocol-relative', Sanitizer::validUrl('//cdn.example.com/a.png') === '//cdn.example.com/a.png');
check('relative', Sanitizer::validUrl('/usr/uploads/a.png') === '/usr/uploads/a.png');
check('javascript rejected', Sanitizer::validUrl('javascript:alert(1)') === '');
check('data rejected', Sanitizer::validUrl('data:text/html,x') === '');
check('empty', Sanitizer::validUrl('   ') === '');

echo "ImageRepository::isPano\n";
check('2:1', ImageRepository::isPano(8192, 4096) === true);
check('1.98', ImageRepository::isPano(1980, 1000) === true);
check('2.02', ImageRepository::isPano(2020, 1000) === true);
check('2.03 false', ImageRepository::isPano(2030, 1000) === false);
check('3:2 false', ImageRepository::isPano(3000, 2000) === false);
check('portrait false', ImageRepository::isPano(1000, 2000) === false);
check('zero height', ImageRepository::isPano(2000, 0) === false);

echo "MediaProcessor::supportedExtensions\n";
$ext = MediaProcessor::supportedExtensions();
foreach (['jpg', 'jpeg', 'png', 'gif', 'webp', 'heic', 'heif', 'avif'] as $e) {
    check('ext ' . $e, in_array($e, $ext, true));
}

echo "\n$pass passed, $fail failed\n";
exit($fail === 0 ? 0 : 1);
