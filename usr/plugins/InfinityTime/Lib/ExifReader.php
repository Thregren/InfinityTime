<?php
namespace TypechoPlugin\InfinityTime\Lib;

/**
 * EXIF 读取器：从原始上传文件中提取拍摄关键参数与 GPS。
 * 必须在转换成 WebP 之前调用，避免元数据丢失。
 */
class ExifReader
{
    /**
     * 读取 EXIF，返回归一化数组。
     *
     * @param string $path 源文件路径
     * @return array
     */
    public static function read(string $path): array
    {
        $out = [
            'make' => null, 'model' => null, 'lens' => null, 'iso' => null,
            'fnumber' => null, 'exposure' => null, 'focal' => null, 'focal35' => null,
            'flash' => null, 'datetime' => null, 'orientation' => 1,
            'gps' => null, // ['lat'=>x,'lng'=>y,'latRef'=>..,'lngRef'=>..]
        ];

        if (!is_file($path) || !function_exists('exif_read_data')) {
            return $out;
        }

        $data = @exif_read_data($path, null, true);
        if (!is_array($data)
            || (empty($data['IFD0']) && empty($data['EXIF']) && empty($data['GPS']))) {
            return self::readViaImagick($path);
        }

        $ifd0 = $data['IFD0'] ?? [];
        $exif = $data['EXIF'] ?? [];
        $gps  = $data['GPS'] ?? [];

        $out['make']        = self::norm($ifd0['Make'] ?? null);
        $out['model']       = self::norm($ifd0['Model'] ?? null);
        // lens 字段在不同 PHP/exif 版本下可能被命名为 LensModel/UndefinedTag:0xA434 等，兼容读取
        $lensModel = $exif['LensModel'] ?? $exif['UndefinedTag:0xA434'] ?? null;
        $lensSpec  = $exif['LensSpecification'] ?? $exif['UndefinedTag:0xA432'] ?? null;
        $lensMake  = $exif['LensMake'] ?? $exif['UndefinedTag:0xA433'] ?? null;
        $out['lens']        = self::normLens($lensModel, $lensSpec, $lensMake);
        $out['iso']         = isset($exif['ISOSpeedRatings']) ? intval($exif['ISOSpeedRatings']) : null;
        $out['fnumber']     = self::rational($exif['FNumber'] ?? null);
        $out['exposure']    = self::formatExposure($exif['ExposureTime'] ?? null);
        $out['focal']       = self::rational($exif['FocalLength'] ?? null);
        $out['focal35']     = self::rational($exif['FocalLengthIn35mmFilm'] ?? null);
        if ($out['focal'] !== null && $out['focal35'] === null) {
            $out['focal35'] = self::equivFocal($out['focal'], $exif, $out['make'], $out['model']);
        }
        $out['flash']       = self::flashFired($exif['Flash'] ?? null);
        $out['datetime']    = self::norm($ifd0['DateTimeOriginal'] ?? $ifd0['DateTime'] ?? null);
        $out['orientation'] = intval($ifd0['Orientation'] ?? 1);
        $out['gps']         = self::readGps($gps);

        return $out;
    }

    /** 解析镜头字段：优先真实型号；忽略 '----'/'Unknown' 等占位符；无型号时用规格字符串兜底。 */
    private static function normLens($model, $spec, $make): ?string
    {
        $m = self::norm($model);
        if ($m !== null
            && !in_array(strtolower($m), ['----', '--', 'n/a', 'unknown', 'none'], true)
            && stripos($m, 'unknown') === false) {
            $mk = self::norm($make);
            return ($mk && stripos($m, $mk) === false) ? $mk . ' ' . $m : $m;
        }
        if (is_array($spec) && count($spec) >= 2) {
            $a = self::rational($spec[0] ?? null);
            $b = self::rational($spec[1] ?? null);
            if ($a !== null && $b !== null) {
                return $a == $b ? rtrim(rtrim(sprintf('%g', $a), '0'), '.') . 'mm'
                                : rtrim(rtrim(sprintf('%g', $a), '0'), '.') . '-' . rtrim(rtrim(sprintf('%g', $b), '0'), '.') . 'mm';
            }
        }
        return null;
    }

    /** EXIF Flash 是否触发（位 0）。未记录则返回 null，展示时据此隐藏该行。 */
    private static function flashFired($v): ?bool
    {
        if ($v === null || $v === '') {
            return null;
        }
        $n = is_numeric($v) ? (int)$v : null;
        if ($n === null) {
            return null;
        }
        return ($n & 1) === 1;
    }

    /** 推算 35mm 等效焦距：优先传感器尺寸法，其次机型裁切系数；无法判定则返回 null。 */
    private static function equivFocal(float $focal, array $exif, ?string $make, ?string $model): ?float
    {
        $crop = self::cropFromSensor($exif);
        if ($crop === null) {
            $crop = self::cropFromModel($make, $model);
        }
        if ($crop === null || $crop <= 0) {
            return null;
        }
        return round($focal * $crop, 1);
    }

    /** 由焦平面分辨率与像素宽算出传感器宽度，得到 35mm 裁切系数。 */
    private static function cropFromSensor(array $exif): ?float
    {
        $unit = (int)($exif['FocalPlaneResolutionUnit'] ?? 0);
        $xres = self::rational($exif['FocalPlaneXResolution'] ?? null);
        $w = (int)($exif['ExifImageWidth'] ?? 0);
        if (!$unit || !$xres || !$w) {
            return null;
        }
        $mmPerUnit = ($unit === 2) ? 25.4 : (($unit === 3) ? 10.0 : (($unit === 4) ? 1.0 : (($unit === 5) ? 0.001 : null)));
        if ($mmPerUnit === null) {
            return null;
        }
        $sensorW = ($w / $xres) * $mmPerUnit; // mm
        if ($sensorW <= 0) {
            return null;
        }
        return round(36.0 / $sensorW, 2);
    }

    /** 依据品牌/型号给出常见的裁切系数（回退方案）。 */
    private static function cropFromModel(?string $make, ?string $model): ?float
    {
        $s = strtolower(trim(($make ?? '') . ' ' . ($model ?? '')));
        // Micro Four Thirds：Olympus / OM System / Panasonic G/GF/DMC
        if (preg_match('/olympus|om system|panasonic|dmc-|\\bg\\d|\\bgh\\d|\\bgf\\d|\\bgx\\d/', $s)) {
            return 2.0;
        }
        // 1 英寸传感器常见机型
        if (preg_match('/rx100|zv-1|g7x|g9x|g5x|sx70|hx99|dsc-.*hx/', $s)) {
            return 2.7;
        }
        // Canon APS-C
        if (preg_match('/^canon .*\\b(eos\\s?(7d|60d|70d|80d|90d|\\d{2,3}d)|eos\\s?r7|eos\\s?r10|eos\\s?r50|eos\\s?m\\d?|kiss)/', $s)) {
            return 1.6;
        }
        // Nikon / Sony / Fujifilm / Pentax APS-C
        if (preg_match('/nikon\\s?d\\d{3,4}|nikon\\s?z(50|fc|f)\\b|sony.*\\b(a\\d{3}|a\\d{4}|a7c)\\b|fujifilm\\s?x|pentax\\s?\\*?k/', $s)) {
            return 1.5;
        }
        return null;
    }

    /** 归一化字符串（去除尾部 \0、多余空白）。 */
    private static function norm($v): ?string
    {
        if ($v === null) {
            return null;
        }
        $v = trim(str_replace("\0", '', (string)$v));
        return $v === '' ? null : $v;
    }

    /** 把 "a/b" 有理数转成 float，支持 "1/60"、"5" 等。 */
    private static function rational($v): ?float
    {
        if ($v === null) {
            return null;
        }
        if (is_array($v)) {
            return $v[0] != 0 ? round($v[0] / ($v[1] ?: 1), 4) : null;
        }
        if (is_numeric($v)) {
            return (float)$v;
        }
        if (preg_match('#^([-+]?\d+)/(\d+)$#', $v, $m)) {
            return $m[2] != 0 ? round($m[1] / $m[2], 4) : null;
        }
        $v = (float)$v;
        return $v > 0 ? $v : null;
    }

    /** 曝光时间格式化：>=1s 直接秒，<1s 显示为 1/x。 */
    private static function formatExposure($v): ?string
    {
        $f = self::rational($v);
        if ($f === null) {
            return null;
        }
        if ($f >= 1) {
            return rtrim(rtrim(sprintf('%.2f', $f), '0'), '.') . 's';
        }
        return '1/' . intval(round(1 / $f)) . 's';
    }

    /** 读取 GPS 与反向解析用到的经纬度。 */
    private static function readGps(array $gps): ?array
    {
        if (empty($gps['GPSLatitude']) || empty($gps['GPSLongitude'])) {
            return null;
        }

        $lat = self::dms($gps['GPSLatitude']);
        $lng = self::dms($gps['GPSLongitude']);
        $latRef = strtoupper($gps['GPSLatitudeRef'] ?? 'N');
        $lngRef = strtoupper($gps['GPSLongitudeRef'] ?? 'E');

        if ($latRef === 'S') {
            $lat = -$lat;
        }
        if ($lngRef === 'W') {
            $lng = -$lng;
        }

        return [
            'lat' => round($lat, 6),
            'lng' => round($lng, 6),
            'latRef' => $latRef,
            'lngRef' => $lngRef,
        ];
    }

    /** EXIF DMS（度分秒有理数数组）转十进制度。 */
    private static function dms(array $dms): float
    {
        $d = self::rational($dms[0] ?? 0) ?? 0;
        $m = self::rational($dms[1] ?? 0) ?? 0;
        $s = self::rational($dms[2] ?? 0) ?? 0;
        return round($d + ($m / 60) + ($s / 3600), 6);
    }

    /**
     * 兜底：当 PHP exif 扩展读不出 HEIC/HEIF 的 EXIF 时，改用 Imagick 的图像属性读取。
     * @return array 与 read() 相同的归一化结构
     */
    private static function readViaImagick(string $path): array
    {
        $out = [
            'make' => null, 'model' => null, 'lens' => null, 'iso' => null,
            'fnumber' => null, 'exposure' => null, 'focal' => null, 'focal35' => null,
            'flash' => null, 'datetime' => null, 'orientation' => 1, 'gps' => null,
        ];
        if (!class_exists('\Imagick')) {
            return $out;
        }
        try {
            $im = new \Imagick($path);
            $p = $im->getImageProperties('exif:*');
            $im->destroy();
        } catch (\Throwable $e) {
            return $out;
        }
        if (!$p) {
            return $out;
        }
        $g = function (string $k) use ($p) {
            return $p[$k] ?? null;
        };
        $out['make'] = self::norm($g('exif:Make'));
        $out['model'] = self::norm($g('exif:Model'));
        $out['lens'] = self::norm($g('exif:LensModel')) ?? self::norm($g('exif:UndefinedTag:0xA434'));
        $iso = $g('exif:ISOSpeedRatings');
        $out['iso'] = ($iso !== null && $iso !== '') ? intval($iso) : null;
        $out['fnumber'] = self::rational($g('exif:FNumber'));
        $out['exposure'] = self::formatExposure(self::rational($g('exif:ExposureTime')));
        $out['focal'] = self::rational($g('exif:FocalLength'));
        $out['focal35'] = self::rational($g('exif:FocalLengthIn35mmFilm'));
        $out['flash'] = self::flashFired($g('exif:Flash'));
        $out['datetime'] = self::norm($g('exif:DateTimeOriginal') ?? $g('exif:DateTime'));
        $out['orientation'] = intval($g('exif:Orientation') ?? 1);
        return $out;
    }
}
