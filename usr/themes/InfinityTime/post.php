<?php if (!defined('__TYPECHO_ROOT_DIR__')) exit; ?>
<?php
/**
 * 单篇（相册）模板：纯图集站相册统一在首页网格展示，单篇入口自动回到首页。
 */
$home = rtrim((string)$this->options->siteUrl, '/');
?>
<!DOCTYPE html>
<html>
<head>
  <meta charset="<?php $this->options->charset(); ?>">
  <meta http-equiv="refresh" content="0;url=<?php echo htmlspecialchars($home); ?>">
  <title>相册</title>
</head>
<body>
  <p>正在跳转到相册首页…<a href="<?php echo htmlspecialchars($home); ?>">立即进入</a></p>
</body>
</html>
   
