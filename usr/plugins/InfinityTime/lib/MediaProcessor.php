<?php
namespace TypechoPlugin\InfinityTime\Lib;

/**
 * 媒体处理器：
 *  - 把上传的原始文件（JPEG/PNG/GIF/WebP/HEIC/AVIF）解码为位图
 *  - 按 EXIF Orientation 纠正方向
 *  - 输出全尺寸 WebP（`full/`）与缩略图 WebP（`thumb/`）
 *  - 可选的原始文件存放在 `original/`（由调用方决定是否保留）
 *
 * HDR 说明：WebP 仅支持 8-bit SDR，无法携带 HEIC 的 HDR gain map/10-bit。
 * 解码 HEIC 时依赖 heif-convert / ImageMagick 的默认 HDR->SDR 色调映射，
 * 以尽量保留 HDR 观感；原始 HEIC 始终保留在 original/ 以备后续重处理。
 */
class MediaProcessor
{
    public const TOOL_GD      = 'gd';       // GD 直接解码（不含 HEIC）
    public const TOOL_HEIF    = 'heif-convert';
    public const TOOL_MAGICK  = 'magick';   // ImageMagick CLI
    public const TOOL_IMAGICK = 'imagick';  // PHP Imagick 扩展

    /** 支持的输入扩展名。 */
    public static function supportedExtensions(): array
    {
        return ['jpg', 'jpeg', 'png', 'gif', 'webp', 'heic', 'heif', 'avif'];
    }

    /**
     * 探测本机可用的转换工具。
     *
     * @return array 含 imagick/heif/magick/convert 是否可用
     */
    public static function detectTools(): array
    {
        static $cached = null;
        if ($cached !== null) {
            return $cached;
        }
        $tools = [
            'imagick'  => class_exists('\\Imagick'),
            'magick'   => self::which('magick'),
            'convert'  => self::which('convert'),
            'heif'     => self::which('heif-convert'),
        ];

        // 常见安装路径（不依赖 PATH），兼容 Homebrew / usr/local / 发行版默认路径
        if (!$tools['magick']) {
            $tools['magick'] = self::firstExecutable(['/opt/homebrew/bin/magick', '/usr/local/bin/magick', '/usr/bin/magick']);
        }
        if (!$tools['convert']) {
            $tools['convert'] = self::firstExecutable(['/opt/homebrew/bin/convert', '/usr/local/bin/convert', '/usr/bin/convert']);
        }
        if (!$tools['heif']) {
            $tools['heif'] = self::firstExecutable([
                '/opt/homebrew/bin/heif-convert',
                '/usr/local/bin/heif-convert',
                '/usr/bin/heif-convert',
                '/usr/local/opt/libheif/bin/heif-convert',
            ]);
        }

        // 兼容部分环境 libheif 工具在 PATH 之外
        if (!$tools['heif'] && defined('INFINITYTIME_HEIF_CONVERT')) {
            $tools['heif'] = is_executable(INFINITYTIME_HEIF_CONVERT) ? INFINITYTIME_HEIF_CONVERT : false;
        }
        if (!$tools['heif'] && defined('INFINITYTIME_HEIF_CONVERT_DEFAULT')
            && is_executable(INFINITYTIME_HEIF_CONVERT_DEFAULT)) {
            $tools['heif'] = INFINITYTIME_HEIF_CONVERT_DEFAULT;
        }
        // 管理员可在插件设置里手动指定 heif-convert 路径（无需修改代码）
        if (!$tools['heif'] && class_exists(\TypechoPlugin\InfinityTime\Plugin::class)) {
            $custom = \TypechoPlugin\InfinityTime\Plugin::opt('infinitytimeHeif', '');
            if ($custom && is_executable($custom)) {
                $tools['heif'] = $custom;
            }
        }

        $cached = $tools;
        return $cached;
    }

    /** 返回数组里第一个可执行文件路径，否则 null。 */
    private static function firstExecutable(array $paths): ?string
    {
        foreach ($paths as $path) {
            if (is_executable($path)) {
                return $path;
            }
        }
        return null;
    }

    /**
     * 处理一张上传文件：解码->纠正->转 WebP 全图 + WebP 缩略图。
     *
     * @param string $srcPath   上传后的临时/源文件路径
     * @param string $fullPath  full/ 目标路径（.webp）
     * @param string $thumbPath thumb/ 目标路径（.webp）
     * @param int    $thumbMax  缩略图最长边像素
     * @param int    $quality  WebP 质量（0-100）
     * @param int    $maxWidth  全图最长边上限（0=不裁剪）
     * @return array{width:int,height:int,size:int,mime:string,exif:array}
     * @throws \RuntimeException
     */
    public static function process(
        string $srcPath,
        string $fullPath,
        string $thumbPath,
        int $thumbMax = 1280,
        int $quality = 82,
        int $maxWidth = 0
    ): array {
        if (!is_file($srcPath)) {
            throw new \RuntimeException('未找到源文件: ' . $srcPath);
        }

        // 前提：先在转换成 WebP 之前读 EXIF（避免丢失）
        $exif = ExifReader::read($srcPath);

        $img = self::decodeToGd($srcPath);
        $img = self::applyOrientation($img, $exif['orientation'] ?? 1);

        $width = imagesx($img);
        $height = imagesy($img);

        // 全图宽度上限：先把超宽图片等比缩小，再编码 WebP
        if ($maxWidth > 0 && $width > $maxWidth) {
            $scale = $maxWidth / $width;
            $nw = (int)round($width * $scale);
            $nh = (int)round($height * $scale);
            $scaled = imagescale($img, $nw, $nh, IMG_BILINEAR_FIXED);
            if ($scaled) {
                $img = $scaled;
                $width = imagesx($img);
                $height = imagesy($img);
            }
        }

        self::ensureDir(dirname($fullPath));
        self::ensureDir(dirname($thumbPath));

        if (!imagewebp($img, $fullPath, $quality)) {
            throw new \RuntimeException('全图 WebP 写入失败');
        }

        // 缩略图
        if ($thumbMax > 0) {
            $scale = min(1.0, $thumbMax / max($width, $height));
            $tw = (int)round($width * $scale);
            $th = (int)round($height * $scale);
            if ($scale < 1.0) {
                $thumb = imagescale($img, $tw, $th, IMG_BILINEAR_FIXED);
                if (!$thumb) {
                    throw new \RuntimeException('缩略图缩放失败');
                }
            } else {
                // 图片已不比缩略图目标大，直接复用原图
                $thumb = $img;
            }
            if (!imagewebp($thumb, $thumbPath, max(60, $quality - 7))) {
                throw new \RuntimeException('缩略图 WebP 写入失败');
            }
        }

        return [
            'width' => $width,
            'height' => $height,
            'size' => filesize($fullPath),
            'mime' => 'image/webp',
            'exif' => $exif,
        ];
    }

    /**
     * 解码为 GD 图像。非 GD 支持格式（HEIC/AVIF）先经外部工具转成 JPEG 再交给 GD。
     */
    private static function decodeToGd(string $srcPath): \GdImage
    {
        $mime = self::mime($srcPath);

        switch ($mime) {
            case 'image/jpeg':
                return imagecreatefromjpeg($srcPath);
            case 'image/png':
                return imagecreatefrompng($srcPath);
            case 'image/gif':
                return imagecreatefromgif($srcPath);
            case 'image/webp':
                return imagecreatefromwebp($srcPath);
        }

        // HEIC / HEIF / AVIF -> 外部工具转 JPEG
        if (in_array($mime, ['image/heic', 'image/heif', 'image/heic-sequence'], true) || self::isHeicExt($srcPath)) {
            return self::decodeHeic($srcPath);
        }
        if ($mime === 'image/avif' || strtolower(pathinfo($srcPath, PATHINFO_EXTENSION)) === 'avif') {
            return self::decodeAvif($srcPath);
        }

        throw new \RuntimeException('不支持的图片格式: ' . $mime);
    }

    /** 使用外部工具解码 HEIC/HEIF 为 JPEG，再加载进 GD。 */
    private static function decodeHeic(string $srcPath): \GdImage
    {
        $tools = self::detectTools();

        // PHP Imagick 优先（Linux 生产推荐）
        if ($tools['imagick']) {
            $im = new \Imagick($srcPath);
            $im->setImageFormat('jpeg');
            $im->setImageColorspace(\Imagick::COLORSPACE_SRGB);
            $im->setIteratorIndex(0);
            $data = $im->getImageBlob();
            $im->destroy();
            return imagecreatefromstring($data);
        }

        $jpeg = tempnam(sys_get_temp_dir(), 'pp_heic_');
        $done = false;

        if ($tools['magick']) {
            exec(escapeshellarg($tools['magick']) . ' ' . escapeshellarg($srcPath) . '[0] -colorspace sRGB -quality 92 ' . escapeshellarg($jpeg), $o, $code);
            $done = $code === 0 && is_file($jpeg) && filesize($jpeg) > 0;
        }
        if (!$done && $tools['convert']) {
            exec(escapeshellarg($tools['convert']) . ' ' . escapeshellarg($srcPath) . '[0] -colorspace sRGB -quality 92 ' . escapeshellarg($jpeg), $o, $code);
            $done = $code === 0 && is_file($jpeg) && filesize($jpeg) > 0;
        }
        if (!$done && $tools['heif']) {
            $bin = is_string($tools['heif']) ? $tools['heif'] : 'heif-convert';
            // heif-convert 要求输出名带 .jpg；多图时会在扩展名前加 -N（base.jpg -> base-1.jpg）
            exec(escapeshellarg($bin) . ' ' . escapeshellarg($srcPath) . ' ' . escapeshellarg($jpeg . '.jpg'), $o, $code);
            $found = self::findProducedJpeg($jpeg);
            $done = $code === 0 && $found !== null;
            if ($done) {
                $jpeg = $found;
            }
        }

        if (!$done || !$jpeg || !is_file($jpeg) || filesize($jpeg) === 0) {
            @unlink($jpeg);
            throw new \RuntimeException('当前环境缺少可用的 HEIC 转换工具（需安装 ImageMagick+libheif 或 heif-convert）');
        }

        $img = imagecreatefromjpeg($jpeg);
        @unlink($jpeg);
        return $img;
    }

    /**
     * 定位 heif-convert 实际产出的 JPEG。以无扩展名的基底 `$base` 搜索，
     * 匹配 `$base*.jpg`（单图 `base.jpg`；多图 `base-1.jpg`/`base-2.jpg`）。
     * 优先取体积最大的帧（通常为主图，也含 HDR 主渲染）。
     */
    private static function findProducedJpeg(string $base): ?string
    {
        if (is_file($base) && filesize($base) > 0) {
            return $base;
        }

        $candidates = glob($base . '*.jpg');
        if (!$candidates) {
            return null;
        }

        $best = null;
        $bestSize = -1;
        foreach ($candidates as $c) {
            if (!is_file($c)) {
                continue;
            }
            $sz = filesize($c);
            if ($sz > $bestSize) {
                $bestSize = $sz;
                $best = $c;
            }
        }
        return $best;
    }

    /** AVIF 解码（ImageMagick 或 fallback）。 */
    private static function decodeAvif(string $srcPath): \GdImage
    {
        $tools = self::detectTools();
        if ($tools['imagick']) {
            $im = new \Imagick($srcPath);
            $im->setImageFormat('jpeg');
            $im->setIteratorIndex(0);
            $data = $im->getImageBlob();
            $im->destroy();
            return imagecreatefromstring($data);
        }

        $jpeg = tempnam(sys_get_temp_dir(), 'pp_avif_');
        if ($tools['magick']) {
            exec(escapeshellarg($tools['magick']) . ' ' . escapeshellarg($srcPath) . '[0] -quality 92 ' . escapeshellarg($jpeg), $o, $code);
            if ($code === 0 && is_file($jpeg) && filesize($jpeg) > 0) {
                $img = imagecreatefromjpeg($jpeg);
                @unlink($jpeg);
                return $img;
            }
        }
        @unlink($jpeg);
        throw new \RuntimeException('AVIF 需要 ImageMagick（安装 libavif）支持');
    }

    /** 依据 EXIF Orientation（1-8）对位图做旋转/镜像修正（GD 不会自动处理）。 */
    private static function applyOrientation(\GdImage $img, int $orientation): \GdImage
    {
        switch ($orientation) {
            case 2:  // 水平翻转
                imageflip($img, IMG_FLIP_HORIZONTAL);
                break;
            case 3:  // 旋转180
                $img = imagerotate($img, 180, 0);
                break;
            case 4:  // 垂直翻转
                imageflip($img, IMG_FLIP_VERTICAL);
                break;
            case 5:  // 水平+逆时针90
                $img = imagerotate($img, 90, 0);
                imageflip($img, IMG_FLIP_HORIZONTAL);
                break;
            case 6:  // 顺时针90
                $img = imagerotate($img, 270, 0);
                break;
            case 7:  // 垂直+顺时针90
                $img = imagerotate($img, 90, 0);
                imageflip($img, IMG_FLIP_VERTICAL);
                break;
            case 8:  // 逆时针90
                $img = imagerotate($img, 90, 0);
                break;
        }
        return $img;
    }

    private static function mime(string $path): string
    {
        $fi = new \finfo(FILEINFO_MIME_TYPE);
        return (string)$fi->file($path);
    }

    private static function isHeicExt(string $path): bool
    {
        return in_array(strtolower(pathinfo($path, PATHINFO_EXTENSION)), ['heic', 'heif'], true);
    }

    private static function which(string $bin): ?string
    {
        static $cache = [];
        if (array_key_exists($bin, $cache)) {
            return $cache[$bin];
        }
        $cache[$bin] = false;
        $modes = [PHP_OS_FAMILY === 'Windows' ? 'where' : 'command -v'];
        $out = [];
        @exec($modes[0] . ' ' . escapeshellarg($bin) . ' 2>/dev/null', $out, $code);
        if ($code === 0 && !empty($out)) {
            $cache[$bin] = trim($out[0]);
        }
        return $cache[$bin];
    }

    private static function ensureDir(string $dir): void
    {
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new \RuntimeException('无法创建目录: ' . $dir);
        }
    }
}
