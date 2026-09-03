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
            $tools['heif'] = @is_executable(INFINITYTIME_HEIF_CONVERT) ? INFINITYTIME_HEIF_CONVERT : false;
        }
        if (!$tools['heif'] && defined('INFINITYTIME_HEIF_CONVERT_DEFAULT')
            && @is_executable(INFINITYTIME_HEIF_CONVERT_DEFAULT)) {
            $tools['heif'] = INFINITYTIME_HEIF_CONVERT_DEFAULT;
        }
        // 管理员可在插件设置里手动指定 heif-convert 路径（无需修改代码）
        if (!$tools['heif'] && class_exists(\TypechoPlugin\InfinityTime\Plugin::class)) {
            $custom = \TypechoPlugin\InfinityTime\Plugin::opt('infinitytimeHeif', '');
            if ($custom && @is_executable($custom)) {
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
            if (@is_executable($path)) {
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
        int $quality = 76,
        int $maxWidth = 2560,
        int $fullQuality = 82
    ): array {
        // 大图（如手机高像素照片）解码需要的内存可能超过默认 128M，这里放宽到 256M（PHP 允许运行时提高）。
        @ini_set('memory_limit', '256M');

        if (!is_file($srcPath)) {
            throw new \RuntimeException('未找到源文件: ' . $srcPath);
        }

        // 解码前先做单图尺寸上限保护，避免超大图把内存撑爆。
        self::assertImageSize($srcPath);

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

        if (!imagewebp($img, $fullPath, $fullQuality)) {
            throw new \RuntimeException('全图 WebP 写入失败（最常见是目录不可写）: ' . $fullPath);
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
                throw new \RuntimeException('缩略图 WebP 写入失败（最常见是目录不可写）: ' . $thumbPath);
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
     * 返回 PHP 7.4 的 GD resource 或 PHP 8+ 的 GdImage 对象；不声明 GdImage 类型，
     * 以保持 PHP 7.4 主机兼容。
     */
    /** 单图允许的最大像素数（约 60MP），超过则解码前直接拒绝，防止 GD/Imagick 内存溢出。 */
    private const MAX_PIXELS = 60000000;

    private static function assertImageSize(string $srcPath): void
    {
        $w = 0;
        $h = 0;
        $info = @getimagesize($srcPath);
        if (is_array($info) && ($info[0] ?? 0) > 0 && ($info[1] ?? 0) > 0) {
            $w = (int)$info[0];
            $h = (int)$info[1];
        }
        if ($w > 0 && $h > 0 && $w * $h > self::MAX_PIXELS) {
            throw new \RuntimeException(
                '图片分辨率过大（' . $w . '×' . $h . '，约 ' . round($w * $h / 1000000) . 'MP，上限 ' . intdiv(self::MAX_PIXELS, 1000000)
                . 'MP），请先压缩后再上传'
            );
        }
    }

    private static function decodeToGd(string $srcPath)
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
    /** @return mixed GD image resource (PHP 7.4) or GdImage object (PHP 8+). */
    private static function decodeHeic(string $srcPath)
    {
        $tools = self::detectTools();

        // PHP Imagick 优先（Linux 生产推荐）
        if ($tools['imagick']) {
            try {
                $im = new \Imagick($srcPath);
                $im->setImageFormat('jpeg');
                $im->setImageColorspace(\Imagick::COLORSPACE_SRGB);
                $im->setIteratorIndex(0);
                $data = $im->getImageBlob();
                $im->destroy();
                return imagecreatefromstring($data);
            } catch (\Throwable $e) {
                Plugin::log('Imagick 解码 HEIC/HEIF 失败，尝试外部工具：' . $e->getMessage());
            }
        }

        $jpeg = tempnam(sys_get_temp_dir(), 'pp_heic_');
        $done = false;

        if ($tools['magick']) {
            $code = self::runCmd(escapeshellarg($tools['magick']) . ' ' . escapeshellarg($srcPath) . '[0] -colorspace sRGB -quality 92 ' . escapeshellarg($jpeg), 30);
            $done = $code === 0 && is_file($jpeg) && filesize($jpeg) > 0;
        }
        if (!$done && $tools['convert']) {
            $code = self::runCmd(escapeshellarg($tools['convert']) . ' ' . escapeshellarg($srcPath) . '[0] -colorspace sRGB -quality 92 ' . escapeshellarg($jpeg), 30);
            $done = $code === 0 && is_file($jpeg) && filesize($jpeg) > 0;
        }
        if (!$done && $tools['heif']) {
            $bin = is_string($tools['heif']) ? $tools['heif'] : 'heif-convert';
            // heif-convert 要求输出名带 .jpg；多图时会在扩展名前加 -N（base.jpg -> base-1.jpg）
            $code = self::runCmd(escapeshellarg($bin) . ' ' . escapeshellarg($srcPath) . ' ' . escapeshellarg($jpeg . '.jpg'), 30);
            $found = self::findProducedJpeg($jpeg);
            $done = $code === 0 && $found !== null;
            if ($done) {
                $jpeg = $found;
            }
        }

        if (!$done || !$jpeg || !is_file($jpeg) || filesize($jpeg) === 0) {
            @unlink($jpeg);
            throw new \RuntimeException(
                'HEIC 解码失败：Imagick 无法读取该文件，且没有可用的 heif-convert/ImageMagick（或服务器 PHP 禁用了 exec/shell_exec）。'
                . ' 请改传 JPG/PNG/WebP；若必须用 HEIC，请在插件设置填入 heif-convert 路径并确保 PHP 未禁用 exec。'
            );
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
    /** @return mixed GD image resource (PHP 7.4) or GdImage object (PHP 8+). */
    private static function decodeAvif(string $srcPath)
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
            $code = self::runCmd(escapeshellarg($tools['magick']) . ' ' . escapeshellarg($srcPath) . '[0] -quality 92 ' . escapeshellarg($jpeg), 30);
            if ($code === 0 && is_file($jpeg) && filesize($jpeg) > 0) {
                $img = imagecreatefromjpeg($jpeg);
                @unlink($jpeg);
                return $img;
            }
        }
        @unlink($jpeg);
        throw new \RuntimeException('AVIF 需要 ImageMagick（安装 libavif）支持');
    }

    /**
     * 以带超时的方式运行外部命令。
     *
     * PHP 的 exec() 会无限期阻塞在外部子进程上（max_execution_time 并不覆盖系统调用等待），
     * 一旦 magick/heif-convert 卡死会拖住整个 PHP 工作进程。这里改用 proc_open 并强制超时，
     * 超时即 kill，避免后端“网页卡死”。
     *
     * @param string $cmd     拼接好的命令行（参数已 escapeshellarg）
     * @param int    $timeout 超时秒数
     * @return int 退出码
     * @throws \RuntimeException 进程无法启动或超时
     */
    private static function runCmd(string $cmd, int $timeout = 60): int
    {
        if (!function_exists('proc_open')) {
            throw new \RuntimeException('proc_open 被禁用，无法执行外部命令');
        }
        $proc = @proc_open($cmd, [
            0 => ['file', '/dev/null', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ], $pipes);
        if (!is_resource($proc)) {
            throw new \RuntimeException('无法启动外部命令: ' . $cmd);
        }
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $start = microtime(true);
        $status = proc_get_status($proc);
        while ($status['running']) {
            // 尽量抽空子进程输出，避免管道填满导致子进程阻塞
            @stream_get_contents($pipes[1]);
            @stream_get_contents($pipes[2]);

            if ((microtime(true) - $start) > $timeout) {
                proc_terminate($proc, 9);
                usleep(300000);
                if (proc_get_status($proc)['running']) {
                    proc_terminate($proc, 9);
                    usleep(100000);
                }
                @fclose($pipes[1]);
                @fclose($pipes[2]);
                proc_close($proc);
                throw new \RuntimeException('外部命令超时（' . $timeout . 's）: ' . $cmd);
            }
            usleep(50000);
            $status = proc_get_status($proc);
        }

        $exit = isset($status['exitcode']) ? (int)$status['exitcode'] : -1;
        @fclose($pipes[1]);
        @fclose($pipes[2]);
        $closed = proc_close($proc);
        if ($closed !== -1) {
            $exit = $closed;
        }
        return $exit;
    }

    /** 依据 EXIF Orientation（1-8）对位图做旋转/镜像修正（GD 不会自动处理）。 */
    /** @param mixed $img GD image resource (PHP 7.4) or GdImage object (PHP 8+). */
    private static function applyOrientation($img, int $orientation)
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
        // 主机常为安全禁用 exec/shell_exec/proc_open；被禁用时视为“无此工具”，不要 fatal
        if (!function_exists('exec') && !function_exists('shell_exec')) {
            return $cache[$bin];
        }
        $modes = [PHP_OS_FAMILY === 'Windows' ? 'where' : 'command -v'];
        $out = [];
        $code = -1;
        if (function_exists('exec')) {
            @exec($modes[0] . ' ' . escapeshellarg($bin) . ' 2>/dev/null', $out, $code);
            if ($code === 0 && !empty($out)) {
                $cache[$bin] = trim($out[0]);
            }
        } elseif (function_exists('shell_exec')) {
            $res = @shell_exec($modes[0] . ' ' . escapeshellarg($bin) . ' 2>/dev/null');
            if ($res !== null && trim((string)$res) !== '') {
                $cache[$bin] = trim(explode("\n", (string)$res)[0]);
            }
        }
        return $cache[$bin];
    }

    private static function ensureDir(string $dir): void
    {
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new \RuntimeException('无法创建目录（请检查 PHP 运行用户对该路径的写权限）: ' . $dir);
        }
        if (!is_writable($dir)) {
            @chmod($dir, 0775);
            if (!is_writable($dir)) {
                throw new \RuntimeException(
                    '目录不可写: ' . $dir
                    . '。请给 PHP 运行用户赋写权限，例如执行：chmod -R 775 ' . $dir
                    . ' （仍失败则先 chown -R <运行用户>:<组> ' . dirname($dir, 3) . '）'
                );
            }
        }
    }
}
