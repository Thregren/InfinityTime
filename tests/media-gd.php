<?php
/** 使用真实 GD WebP 编码器验证极端尺寸、空输出与失败时旧文件保持。 */
declare(strict_types=1);
namespace TypechoPlugin\InfinityTime {
    class Plugin { public static function log($message): void {} }
}
namespace TypechoPlugin\InfinityTime\Lib {
    $encodingFault = '';
    function imagewebp($image, $path, $quality): bool {
        global $encodingFault;
        if ($encodingFault && strpos($path, 'thumb.webp.tmp.') !== false) {
            file_put_contents($path, $encodingFault === 'empty' ? '' : 'bad');
            return $encodingFault === 'empty';
        }
        return \imagewebp($image, $path, $quality);
    }
}
namespace {
    use TypechoPlugin\InfinityTime\Lib\MediaProcessor;
    require __DIR__ . '/../usr/plugins/InfinityTime/Lib/ExifReader.php';
    require __DIR__ . '/../usr/plugins/InfinityTime/Lib/MediaProcessor.php';
    if (!extension_loaded('gd') || !function_exists('imagewebp')) { throw new RuntimeException('此测试需要带 WebP 的 GD 扩展'); }
    $dir = sys_get_temp_dir() . '/infinity-real-gd-' . bin2hex(random_bytes(4));
    mkdir($dir, 0700); $checks = 0;
    function expectGd(bool $yes, string $message): void { global $checks; if (!$yes) { throw new RuntimeException($message); } $checks++; }
    function sourceGd(string $path, int $w, int $h): void {
        $image = imagecreatetruecolor($w, $h);
        imagefilledrectangle($image, 0, 0, $w - 1, $h - 1, imagecolorallocate($image, 50, 80, 130));
        imagepng($image, $path); unset($image);
    }
    try {
        foreach ([[20000, 1, 2560, 2560, 1, 1280, 1], [1, 4000, 2560, 1, 4000, 1, 1280],
            [4096, 2048, 2560, 2560, 1280, 1280, 720], [901, 601, 0, 901, 601, 901, 601], [2, 1, 0, 2, 1, 2, 1]] as $case) {
            sourceGd($dir . '/source.png', $case[0], $case[1]);
            $out = MediaProcessor::process($dir . '/source.png', $dir . '/full.webp', $dir . '/thumb.webp', 1280, 76, $case[2], 82);
            $full = getimagesize($dir . '/full.webp'); $thumb = getimagesize($dir . '/thumb.webp');
            expectGd($out['width'] === $case[3] && $out['height'] === $case[4], '处理结果保持明确的尺寸设置');
            expectGd($full[0] === $case[3] && $full[1] === $case[4] && $thumb[0] === $case[5] && $thumb[1] === $case[6], '极窄图片最小边为1且全景缩略图裁剪正确');
            expectGd($out['size'] === filesize($dir . '/full.webp') && $out['size'] > 12, '输出文件真实完整可识别');
        }
        $before = [hash_file('sha256', $dir . '/full.webp'), hash_file('sha256', $dir . '/thumb.webp')];
        foreach ([[20000, 1], [1, 20000]] as $size) {
            sourceGd($dir . '/source.png', $size[0], $size[1]);
            $failed = false;
            try { MediaProcessor::process($dir . '/source.png', $dir . '/full.webp', $dir . '/thumb.webp', 1280, 76, 0, 82); }
            catch (RuntimeException $e) { $failed = strpos($e->getMessage(), '16383') !== false; }
            expectGd($failed, '超出WebP编码器硬限制应明确拒绝，不偷偷降低分辨率');
            expectGd($before === [hash_file('sha256', $dir . '/full.webp'), hash_file('sha256', $dir . '/thumb.webp')], '拒绝超限时保留旧完整文件');
        }
        sourceGd($dir . '/source.png', 1800, 1200);
        foreach (['empty', 'false'] as $fault) {
            $encodingFault = $fault; $failed = false;
            try { MediaProcessor::process($dir . '/source.png', $dir . '/full.webp', $dir . '/thumb.webp'); }
            catch (RuntimeException $e) { $failed = true; }
            expectGd($failed, '编码器返回true但空文件或明确失败均不可假成功');
            expectGd($before === [hash_file('sha256', $dir . '/full.webp'), hash_file('sha256', $dir . '/thumb.webp')], '缩略图编码失败不能提前覆盖旧全图');
            expectGd(glob($dir . '/*.tmp.*') === [], '失败后清理所有暂存文件');
        }
        echo '真实 GD 编码：' . $checks . " 项检查通过\n";
    } finally {
        foreach (glob($dir . '/*') as $file) { unlink($file); }
        rmdir($dir);
    }
}
