<?php
/**
 * InfinityTime 轻量单测：只测不依赖 Typecho / 数据库 / GD 的纯逻辑。
 * 运行：php tests/test.php
 */
declare(strict_types=1);

require_once __DIR__ . '/../usr/plugins/InfinityTime/Lib/Database.php';
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

echo "Sanitizer::sanitize — 安全内容保留\n";
check('段落 + 加粗', Sanitizer::sanitize('<p>hi <b>there</b></p>') === '<p>hi <b>there</b></p>');
check('http 链接保留', strpos(Sanitizer::sanitize('<a href="https://example.com">x</a>'), 'https://example.com') !== false);
check('mailto 链接保留', strpos(Sanitizer::sanitize('<a href="mailto:a@b.c">x</a>'), 'mailto:a@b.c') !== false);

echo "Sanitizer::sanitize — 危险 payload 必须被中和\n";
$payloads = [
    '<script>alert(1)</script><p>ok</p>',
    '<img src=x onerror=alert(1)>',
    '<svg/onload=alert(1)>',
    '<img/src="x"/onerror=alert(1)>',
    '<a href="javascript:alert(1)">x</a>',
    '<a href="&#x6a;avascript:alert(1)">x</a>',
    '<a href="&#106;avascript:alert(1)">x</a>',
    "<a href=\"java\nscript:alert(1)\">x</a>",
    '<form action="javascript:alert(1)">x</form>',
    '<iframe src="https://evil"></iframe>',
    '<a href="data:text/html,x">x</a>',
    '<div><img src=x onerror=alert(1)><p onclick="alert(1)">ok</p></div>',
    '<section><div><svg/onload=alert(1)><script>alert(1)</script></svg></div></section>',
    '<div><a href="java&#x09;script:alert(1)">x</a></div>',
    '<a href="java&NewLine;script:alert(1)">x</a>',
    '</div><div><a href="javascript:alert(1)">x</a></div>',
    '<table><tr><td><a onclick="alert(1)" href="data:text/html,x">x</a></td></tr></table>',
];
foreach ($payloads as $i => $payload) {
    $out = Sanitizer::sanitize($payload);
    $bad = preg_match('/javascript\s*:/i', $out)
        || preg_match('/\bon[a-z]+\s*=/i', $out)
        || preg_match('#<\s*(script|svg|iframe|img|form|object|embed|math)\b#i', $out);
    check('payload #' . $i . ' 已中和', $bad === false);
    // 重新解析输出，检查实际节点/属性，避免只靠正则漏掉实体或嵌套节点。
    $doc = new DOMDocument();
    $doc->loadHTML('<html><body>' . $out . '</body></html>', LIBXML_NONET);
    $safe = true;
    foreach ($doc->getElementsByTagName('*') as $element) {
        $tag = $element->tagName;
        $safe = $safe && in_array($tag, ['html', 'body', 'p', 'br', 'strong', 'em', 'b', 'i', 'ul', 'ol', 'li', 'blockquote', 'h2', 'h3', 'h4', 'a'], true);
        foreach ($element->attributes as $attribute) {
            $safe = $safe && $tag === 'a' && in_array($attribute->name, ['href', 'title'], true);
            if ($attribute->name === 'href') {
                $href = preg_replace('/[\x00-\x20\x7f]/', '', $attribute->value);
                $safe = $safe && !preg_match('/^(?:javascript|data|vbscript):/i', $href);
            }
        }
    }
    check('payload #' . $i . ' DOM 白名单', $safe);
}
check('多层容器展开保留安全内容', Sanitizer::sanitize('<section><div><p>中文 <b>内容</b></p></div></section>') === '<p>中文 <b>内容</b></p>');

echo "Sanitizer::validUrl\n";
check('https', Sanitizer::validUrl('https://example.com/a.png') === 'https://example.com/a.png');
check('http', Sanitizer::validUrl('http://example.com/a.png') === 'http://example.com/a.png');
check('protocol-relative', Sanitizer::validUrl('//cdn.example.com/a.png') === '//cdn.example.com/a.png');
check('relative', Sanitizer::validUrl('/usr/uploads/a.png') === '/usr/uploads/a.png');
check('javascript rejected', Sanitizer::validUrl('javascript:alert(1)') === '');
check('data rejected', Sanitizer::validUrl('data:text/html,x') === '');
check('empty', Sanitizer::validUrl('   ') === '');

echo "Sanitizer::safeLink\n";
check('http', Sanitizer::safeLink('https://x/y') === 'https://x/y');
check('mailto', Sanitizer::safeLink('mailto:a@b.c') === 'mailto:a@b.c');
check('relative', Sanitizer::safeLink('/a/b') === '/a/b');
check('protocol-relative', Sanitizer::safeLink('//cdn/x') === '//cdn/x');
check('javascript rejected', Sanitizer::safeLink('javascript:alert(1)') === '');
check('entity-encoded javascript rejected', Sanitizer::safeLink('&#x6a;avascript:alert(1)') === '');
check('newline-in-scheme javascript rejected', Sanitizer::safeLink("java\nscript:alert(1)") === '');
check('data rejected', Sanitizer::safeLink('data:text/html,x') === '');
check('实体制表符协议被拒绝', Sanitizer::safeLink('java&#x09;script:alert(1)') === '');
check('实体换行协议被拒绝', Sanitizer::safeLink('java&NewLine;script:alert(1)') === '');
check('实体回车协议被拒绝', Sanitizer::safeLink('java&#13;script:alert(1)') === '');
check('安全查询参数保持原样', Sanitizer::safeLink('https://example.com/?a=1&b=2') === 'https://example.com/?a=1&b=2');

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
