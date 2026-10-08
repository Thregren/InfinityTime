<?php
require __DIR__ . '/../usr/plugins/InfinityTime/Lib/HealthDiagnostics.php';
use TypechoPlugin\InfinityTime\Lib\HealthDiagnostics;
final class HealthDb {
    public function getAdapterName(): string { return 'Pdo_SQLite'; }
}
$dir = sys_get_temp_dir() . '/infinity-health-' . bin2hex(random_bytes(6));
mkdir($dir, 0700);
try {
    $before = scandir($dir);
    $report = HealthDiagnostics::collect(new HealthDb(), $dir, ['imagick' => false]);
    if (count($report) !== 8 || scandir($dir) !== $before) { throw new RuntimeException('自检条目或只读约束失败'); }
    $byName = array_column($report, null, 'name');
    if ($byName['私有原图保护']['status'] !== 'manual' || $byName['数据库事务']['status'] !== 'manual' || $byName['HEIC/AVIF 工具']['status'] !== 'warning') { throw new RuntimeException('未知结果不能冒充验证通过'); }
    $missing = HealthDiagnostics::collect(new HealthDb(), $dir . '/missing', ['heif' => '/private/tool']);
    if ($missing[0]['status'] !== 'warning' || $missing[1]['status'] !== 'manual') { throw new RuntimeException('缺失目录需清楚报告'); }
    if (strpos(json_encode($missing), '/private/tool') !== false || strpos(json_encode($report), $dir) !== false) { throw new RuntimeException('诊断不得暴露服务器路径'); }
    if (is_dir($dir . '/missing')) { throw new RuntimeException('不能自动创建目录'); }
    define('__TYPECHO_ADMIN__', true);
    $user = new class { public function pass($role, $return): bool { return false; } };
    ob_start();
    include __DIR__ . '/../usr/plugins/InfinityTime/health-panel.php';
    if (ob_get_clean() !== '') { throw new RuntimeException('非管理员不得读取健康报告'); }
    echo "只读健康检查：目录/磁盘/工具/手工核验边界及无写入检查通过\n";
} finally { rmdir($dir); }
