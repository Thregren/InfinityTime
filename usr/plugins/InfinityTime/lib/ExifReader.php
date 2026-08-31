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
            'datetime' => null, 'orientation' => 1,
            'gps' => null, // ['lat'=>x,'lng'=>y,'latRef'=>..,'lngRef'=>..]
        ];

        if (!is_file($path) || !function_exists('exif_read_data')) {
            return $out;
        }

        $data = @exif_read_data($path, null, true);
        if (!is_array($data)) {
            return $out;
        }

        $ifd0 = $data['IFD0'] ?? [];
        $exif = $data['EXIF'] ?? [];
        $gps  = $data['GPS'] ?? [];

        $out['make']        = self::norm($ifd0['Make'] ?? null);
        $out['model']       = self::norm($ifd0['Model'] ?? null);
        $out['lens']        = self::norm($exif['LensModel'] ?? $exif['LensSpecification'] ?? null);
        $out['iso']         = isset($exif['ISOSpeedRatings']) ? intval($exif['ISOSpeedRatings']) : null;
        $out['fnumber']     = self::rational($exif['FNumber'] ?? null);
        $out['exposure']    = self::formatExposure($exif['ExposureTime'] ?? null);
        $out['focal']       = self::rational($exif['FocalLength'] ?? null);
        $out['focal35']     = self::rational($exif['FocalLengthIn35mmFilm'] ?? null);
        $out['datetime']    = self::norm($ifd0['DateTimeOriginal'] ?? $ifd0['DateTime'] ?? null);
        $out['orientation'] = intval($ifd0['Orientation'] ?? 1);
        $out['gps']         = self::readGps($gps);

        return $out;
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
}
