<?php
namespace TypechoPlugin\InfinityTime\Lib;

/** 管理员只读自检。不写数据库/文件，不发起外部请求，也不修改服务器配置。 */
final class HealthDiagnostics
{
    public static function collect($db, string $uploadRoot, array $tools): array
    {
        $checks = [];
        $add = static function (string $name, string $status, string $detail) use (&$checks): void {
            $checks[] = ['name' => $name, 'status' => $status, 'detail' => $detail];
        };
        $directory = is_dir($uploadRoot);
        $add('上传目录', $directory && is_writable($uploadRoot) ? 'ok' : 'warning',
            $directory && is_writable($uploadRoot) ? '目录存在，PHP 报告可写；未实际写入测试文件。' : '目录不存在或 PHP 报告不可写，请由管理员检查上传目录。');
        $free = $directory && function_exists('disk_free_space') ? @disk_free_space($uploadRoot) : false;
        $add('可用磁盘', $free === false ? 'manual' : ($free < 104857600 ? 'warning' : 'ok'),
            $free === false ? '无法读取空间，请在主机面板核对。' : '当前可用约 ' . number_format($free / 1048576, 0) . ' MiB；转换时仍需为原图、中间文件和备份预留空间。');
        $gd = function_exists('imagewebp') && function_exists('imagecreatefromjpeg');
        $add('基础图片编码', $gd ? 'ok' : 'warning', $gd ? 'GD JPEG/WebP 函数可用；这不是编码样本测试。' : 'GD JPEG/WebP 函数不完整，请检查 PHP 扩展。');
        $advanced = !empty($tools['imagick']) || !empty($tools['magick']) || !empty($tools['convert']) || !empty($tools['heif']);
        $add('HEIC/AVIF 工具', $advanced ? 'manual' : 'warning', $advanced ? '发现候选工具；实际格式支持取决于编译选项，升级后请用样本试传。' : '未发现候选工具，HEIC/AVIF 输入可能无法转换。');
        $adapter = strtolower((string)$db->getAdapterName());
        if (strpos($adapter, 'mysql') !== false) {
            try {
                $all = true;
                foreach (['contents', 'fields', 'infinitytime_images'] as $suffix) {
                    $query = $db->select();
                    $sql = $query->prepare('SHOW TABLE STATUS WHERE Name = ' . $query->quoteValue($db->getPrefix() . $suffix));
                    $row = $db->fetchRow(Database::query($sql));
                    if (strcasecmp((string)($row['Engine'] ?? ''), 'InnoDB') !== 0) { $all = false; }
                }
                $add('数据库事务', $all ? 'ok' : 'warning', $all ? '相关三张表均为 InnoDB；未执行写入或回滚演练。' : '相关表缺失或不是 InnoDB，原子编辑会拒绝；请先备份，再由管理员处理。');
            } catch (\Throwable $e) {
                $add('数据库事务', 'warning', '无法读取表引擎信息；请检查数据库连接和只读诊断权限。');
            }
        } else {
            $known = strpos($adapter, 'sqlite') !== false || strpos($adapter, 'pgsql') !== false || strpos($adapter, 'postgres') !== false;
            $add('数据库事务', $known ? 'manual' : 'warning', $known ? '适配器支持事务；本页未执行写入、锁竞争或真实恢复测试。' : '未识别数据库适配器，请先在隔离副本验证兼容性。');
        }
        $add('上传限制', 'manual', 'PHP upload_max_filesize=' . (string)ini_get('upload_max_filesize') . '，post_max_size=' . (string)ini_get('post_max_size') . '，memory_limit=' . (string)ini_get('memory_limit') . '。代理限制和图片解码内存另需核对。');
        $add('私有原图保护', 'manual', '本页不能证明 Nginx/Apache/CDN 已禁止原图访问。请使用专用无敏感测试图片，核对 original 目录 URL 不能公开访问；不要用真实私密照片测试。');
        $add('备份与恢复', 'manual', '同时保存一致的数据库、上传目录和受保护的站点配置；在隔离环境按下方步骤恢复验证，备份文件存在不等于恢复成功。');
        return $checks;
    }
}
