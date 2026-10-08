<?php
require __DIR__ . '/../usr/plugins/InfinityTime/Lib/ImageRepository.php';
require __DIR__ . '/../usr/themes/InfinityTime/lib/gallery.php';
use TypechoPlugin\InfinityTime\Lib\ImageRepository;
$token = str_repeat('a', 32);
$meta = ['make' => '相机', '_infinity_media_version' => $token, 'gps' => ['lat' => 1]];
$cases = [
    '/full/a.webp' => '/full/a.webp?itv=' . $token,
    '/full/a.webp?x=hello%20world#zoom' => '/full/a.webp?x=hello%20world&itv=' . $token . '#zoom',
    '/full/a.webp?itv=old&x=1&itv=duplicate' => '/full/a.webp?x=1&itv=' . $token,
    '/full/a.webp?%69tv=old' => '/full/a.webp?itv=' . $token,
    '' => '',
];
foreach ($cases as $url => $expected) {
    if (ImageRepository::versionUrl($url, $meta) !== $expected || pp_gallery_version_url($url, $meta) !== $expected) { throw new RuntimeException('前后台版本URL不一致'); }
    if (ImageRepository::versionUrl($expected, $meta) !== $expected) { throw new RuntimeException('同版本重复展示不应累加参数'); }
}
foreach ([null, [], 'javascript:bad', str_repeat('a', 31)] as $invalid) {
    if (pp_gallery_version_url('/a.webp', ['_infinity_media_version' => $invalid]) !== '/a.webp') { throw new RuntimeException('无效版本需忽略'); }
}
$row = ['id' => 7, 'full' => '/full/a.webp', 'thumb' => '/thumb/a.webp', 'exif' => json_encode($meta), 'variants' => ['webp' => ['/full/a.webp', '/mid/a.webp'], 'avif' => ['/avif/a.avif']]];
$photo = pp_gallery_photo($row, 1, 100);
if ($photo['id'] !== 'p-7' || $photo['exif'] !== ['make' => '相机'] || strpos($photo['preview'], $token) === false || strpos($photo['variants']['avif'][0], $token) === false) { throw new RuntimeException('公开展示需保留ID、过滤私有EXIF及更新所有派生图'); }
$legacy = pp_gallery_photo(['full' => '/legacy.webp', 'exif' => $meta], 1, 100, true);
if ($legacy['url'] !== '/legacy.webp') { throw new RuntimeException('不得擅改旧field-only照片链接'); }
echo "图片缓存版本：前后台一致、参数幂等、元数据保护与旧图链接检查通过\n";
