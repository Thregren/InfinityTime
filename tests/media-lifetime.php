<?php
/** 通过位图生命期替身验证 GD 所有别名在响应式变体处理前已释放。 */
declare(strict_types=1);
namespace TypechoPlugin\InfinityTime\Lib {
    class LifetimeBitmap {
        public static int $live = 0;
        public int $w, $h;
        public function __construct(int $w, int $h) { $this->w = $w; $this->h = $h; self::$live++; }
        public function __destruct() { self::$live--; }
    }
    class ExifReader { public static function read($path): array { return ['orientation' => 1]; } }
    $dimensions = [4200, 2100]; $atVariants = []; $encodedSizes = [];
    function getimagesize($path): array { global $dimensions, $encodedSizes; return $encodedSizes[$path] ?? $dimensions; }
    function imagecreatefrompng($path): LifetimeBitmap { global $dimensions; return new LifetimeBitmap($dimensions[0], $dimensions[1]); }
    function imagesx(LifetimeBitmap $image): int { return $image->w; }
    function imagesy(LifetimeBitmap $image): int { return $image->h; }
    function imagescale(LifetimeBitmap $image, $w, $h, $mode): LifetimeBitmap { return new LifetimeBitmap($w, $h); }
    function imagecrop(LifetimeBitmap $image, array $rect): LifetimeBitmap { return new LifetimeBitmap($rect['width'], $rect['height']); }
    function imagewebp(LifetimeBitmap $image, $path, $quality): bool { global $encodedSizes; $encodedSizes[$path] = [$image->w, $image->h, 'mime' => 'image/webp']; return file_put_contents($path, 'isolated-webp-fixture') !== false; }
    function extension_loaded($name): bool {
        global $atVariants;
        if ($name === 'imagick') { $atVariants[] = LifetimeBitmap::$live; return false; }
        return \extension_loaded($name);
    }
}
namespace {
    use TypechoPlugin\InfinityTime\Lib\LifetimeBitmap;
    use TypechoPlugin\InfinityTime\Lib\MediaProcessor;
    require __DIR__ . '/../usr/plugins/InfinityTime/Lib/MediaProcessor.php';
    if (!defined('IMG_BILINEAR_FIXED')) { define('IMG_BILINEAR_FIXED', 5); }
    $root = sys_get_temp_dir() . '/infinity-lifetime-' . bin2hex(random_bytes(4));
    mkdir($root, 0700);
    $source = $root . '/source.png';
    file_put_contents($source, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+/lfoAAAAASUVORK5CYII='));
    try {
        foreach ([[4200, 2100, 2560, 1280], [4200, 2800, 2560, 1280], [600, 400, 0, 1280], [4200, 2100, 0, 1280]] as $case) {
            $dimensions = [$case[0], $case[1]];
            $result = MediaProcessor::process($source, $root . '/full.webp', $root . '/thumb.webp', $case[3], 76, $case[2], 82);
            if (end($atVariants) !== 0 || LifetimeBitmap::$live !== 0) { throw new RuntimeException('响应式变体启动时仍持有 GD 位图别名'); }
            if ($result['width'] !== ($case[2] ?: $case[0]) || $result['size'] <= 0) { throw new RuntimeException('生命期修复改变了尺寸或输出契约'); }
        }
        echo "位图生命期：普通缩放、全景裁剪、缩略图复用及不裁宽全景四种路径通过\n";
    } finally {
        foreach (glob($root . '/*') as $file) { unlink($file); }
        rmdir($root);
    }
}
