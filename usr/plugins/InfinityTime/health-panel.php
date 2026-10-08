<?php
if (!defined('__TYPECHO_ADMIN__') || !isset($user) || !$user->pass('administrator', true)) { return; }
require_once __DIR__ . '/Lib/HealthDiagnostics.php';
$ppHealth = \TypechoPlugin\InfinityTime\Lib\HealthDiagnostics::collect($db, \TypechoPlugin\InfinityTime\Lib\ImageRepository::uploadRoot(), $tools);
$ppHealthLabels = ['ok' => '已检查', 'warning' => '需处理', 'manual' => '需人工核对'];
?>
<div class="pp-card">
  <h2>只读健康检查</h2>
  <p class="pp-meta">刷新后台时读取当前环境，不写测试文件、不修改数据库、不探测外部地址。以下结果不代替真实图片上传与备份恢复演练。</p>
  <ul>
    <?php foreach ($ppHealth as $check): ?>
      <li><strong><?php echo htmlspecialchars($check['name'], ENT_QUOTES); ?></strong> · <?php echo $ppHealthLabels[$check['status']]; ?>
        <p class="pp-meta"><?php echo htmlspecialchars($check['detail'], ENT_QUOTES); ?></p>
      </li>
    <?php endforeach; ?>
  </ul>
  <details>
    <summary>在隔离环境做一次恢复演练</summary>
    <ol>
      <li>记录当前主题、插件和 Typecho 版本，安排一致备份窗口；同时备份数据库、上传目录及受保护的站点配置。备份放在网站公开目录之外。</li>
      <li>将备份恢复到隔离副本，使用副本专用数据库和上传目录。暂停该副本的邮件、通知、计划任务及任何外部写入，避免影响正式站点。</li>
      <li>核对相册与照片数量、草稿权限、排序和中文说明；抽查普通图、全景图、缩略图及原图访问保护。用无敏感样本测试上传、编辑和重建。</li>
      <li>记录备份时间、恢复耗时、缺失项及验证结果；确认能够恢复后再清理过期备份。这里不会自动恢复或覆盖正式数据。</li>
    </ol>
  </details>
</div>
