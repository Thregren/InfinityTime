<?php
/**
 * 一款简约的相册主题
 * @package 无限时光
 * @author InfinityTime
 * @version 1.14.2
 * @link https://github.com/InfinityTime/InfinityTime
 */
?>
<?php
// HTML、无 JavaScript 导航与 JSON 接口共用同一公开读取模型。
$__galleryBase = (string)$this->options->siteUrl;
$__galleryInput = $_GET;
if ($this->is('category') && empty($__galleryInput['category'])) { $__galleryInput['category'] = $this->getArchiveSlug(); }
if ($this->is('tag') && empty($__galleryInput['tag'])) { $__galleryInput['tag'] = (string)$this->getArchiveTitle(); }
$__galleryRequest = pp_gallery_request($__galleryInput);
$__galleryError = false;
try { $__gallery = pp_gallery_read($__galleryRequest, $__galleryBase); }
catch (\Throwable $e) {
    $__galleryError = true;
    $__gallery = ['albums' => [], 'next_cursor' => null, 'next_url' => null, 'scan_limited' => false];
    if (!headers_sent()) { http_response_code(503); }
}
if (($_GET['infinitytime_api'] ?? '') === 'gallery') {
    if (!headers_sent()) {
        header('Content-Type: application/json; charset=UTF-8');
        header('Cache-Control: no-store');
        header('X-Content-Type-Options: nosniff');
    }
    if ($__gallery['next_cursor'] !== null) {
        $__gallery['next_url'] = pp_gallery_url($__galleryBase, $__galleryRequest, ['cursor' => $__gallery['next_cursor'], 'infinitytime_api' => 'gallery']);
    }
    echo json_encode(['version' => 1, 'ok' => !$__galleryError, 'filters' => $__galleryRequest] + $__gallery, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}
$__galleryGroups = pp_gallery_groups($__gallery['albums']);
// 静态资源版本号（以文件 mtime 生成，改动即失效缓存，避免改后还看到旧的 CSS/JS）
$__assetVer = substr(md5((string)@filemtime(__DIR__ . '/assets/css/main.css') . (string)@filemtime(__DIR__ . '/assets/js/main.js') . (string)@filemtime(__DIR__ . '/assets/js/lightbox.js') . (string)@filemtime(__DIR__ . '/assets/js/init.js') . (string)@filemtime(__DIR__ . '/assets/js/data-saver.js') . (string)@filemtime(__DIR__ . '/assets/js/gallery-navigation.js') . (string)@filemtime(__DIR__ . '/assets/css/gallery.css')), 0, 8);
// JSON 嵌入 HTML 属性时的安全标志：把 ' " & < > 转成 \uXXXX，
// 防止用户标题/描述/文件名等含引号或尖括号时破坏属性或注入脚本（存储型 XSS）。
$__jsonFlags = JSON_UNESCAPED_UNICODE | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_HEX_TAG;
// HTML 内联了灯箱/全景 JS，改动后必须立即生效。禁止浏览器缓存页面本体，
// 否则即使 CSS/JS 带了 ?v= 版本号，用户仍会拿到旧的 HTML（看不到新按钮/新逻辑）。
if (!headers_sent()) {
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    header('Expires: 0');
}
?>
<!DOCTYPE html>
<html lang="zh-CN">

<head>
  <title><?php echo htmlspecialchars(pp_opt('infinitytimeSiteName', (string)$this->options->IndexName, $this->options)); ?> - <?php echo htmlspecialchars(pp_opt('infinitytimeSiteTagline', (string)$this->options->Indexdict, $this->options)); ?> </title>
  <meta http-equiv="content-type" content="text/html; charset=<?php $this->options->charset(); ?>" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <meta name="keywords" content="<?php $this->options->keywords(); ?>" />
  <meta name="description" content="<?php $this->options->description(); ?>" />
  <link rel="apple-touch-icon" href="<?php $this->options->AppleIcon(); ?>">
  <meta name="apple-mobile-web-app-title" content="<?php echo htmlspecialchars(pp_opt('infinitytimeSiteName', (string)$this->options->IndexName, $this->options), ENT_QUOTES); ?>">
  <link rel="bookmark" href="<?php $this->options->AppleIcon(); ?>">
  <link rel="apple-touch-icon-precomposed" sizes="180x180" href="<?php $this->options->AppleIcon(); ?>">
  <link rel="icon" href="<?php echo htmlspecialchars(pp_opt('infinitytimeSiteLogo', (string)$this->options->IconUrl, $this->options), ENT_QUOTES); ?>">
  <link rel="stylesheet" type="text/css" href="<?php $this->options->themeUrl('assets/css/main.css?v=' . $__assetVer); ?>" />
  <link rel="stylesheet" href="<?php $this->options->themeUrl('assets/css/iconfont.css'); ?>">
  <link rel="stylesheet" href="<?php $this->options->themeUrl('assets/css/gallery.css?v=' . $__assetVer); ?>">
  <link rel="stylesheet" type="text/css" href="<?php $this->options->themeUrl('assets/css/pannellum.css'); ?>" />
  <noscript>
    <link rel="stylesheet" href="<?php $this->options->themeUrl('assets/css/noscript.css'); ?>" />
  </noscript>
</head>

<body class="is-preload">
  <header id="header">
    <a href="#footer" class="pp-site-toggle" title="关于">
      <img class="site-logo" src="<?php echo htmlspecialchars(pp_opt('infinitytimeSiteLogo', (string)$this->options->IconUrl, $this->options), ENT_QUOTES); ?>">
      <h1><strong><?php echo htmlspecialchars(pp_opt('infinitytimeSiteName', (string)$this->options->zmkiabout, $this->options)); ?></strong></h1>
      <span class="discription"><?php echo htmlspecialchars(pp_opt('infinitytimeSiteTagline', (string)$this->options->zmkiabouts, $this->options)); ?></span>
    </a>
    <nav>
      <ul class="nav_links">


        <li><a type="button" id="fullscreen" class="btn btn-default visible-lg visible-md" aria-label="切换全屏" title="切换全屏">
          <i class="iconfont icon-quanping"></i>
              <use xlink:href="#icon-zmki-ziyuan-copy"></use>
            </svg></a></li>
      </ul>
    </nav>
  </header>

  <!-- Wrapper -->
  <div id="wrapper">
    <!-- Header -->
    <!-- Main -->
    <div id="main">

      <section class="gallery-discovery" aria-label="查找照片">
        <form method="get" action="<?php echo htmlspecialchars(pp_gallery_url($__galleryBase), ENT_QUOTES); ?>" id="gallery-search">
          <label>搜索标题和描述<input type="search" name="q" maxlength="120" value="<?php echo htmlspecialchars($__galleryRequest['q'], ENT_QUOTES); ?>" placeholder="相册或照片关键词" /></label>
          <label>照片类型<select name="kind"><option value="all">全部</option><option value="pano"<?php if ($__galleryRequest['kind'] === 'pano') echo ' selected'; ?>>全景</option></select></label>
          <label>拍摄月份<input type="month" name="month" value="<?php echo htmlspecialchars($__galleryRequest['month'], ENT_QUOTES); ?>" /></label>
          <label>标签<input type="text" name="tag" maxlength="100" value="<?php echo htmlspecialchars($__galleryRequest['tag'], ENT_QUOTES); ?>" placeholder="完整标签" /></label>
          <?php if ($__galleryRequest['category']): ?><input type="hidden" name="category" value="<?php echo htmlspecialchars($__galleryRequest['category'], ENT_QUOTES); ?>" /><?php endif; ?>
          <?php if ($__galleryRequest['album']): ?><input type="hidden" name="album" value="<?php echo $__galleryRequest['album']; ?>" /><?php endif; ?>
          <button type="submit">查找</button>
          <a href="<?php echo htmlspecialchars(pp_gallery_url($__galleryBase), ENT_QUOTES); ?>">全部照片</a>
        </form>
        <label class="gallery-data-saver" hidden>照片加载<select id="gallery-data-saver"><option value="auto">跟随网络建议</option><option value="on">开启省流</option><option value="off">正常加载</option></select><span id="gallery-data-saver-status" role="status"></span></label>
        <p class="gallery-filter-help">关键词匹配标题或描述；多个筛选条件同时生效。月份优先使用拍摄时间，无拍摄时间时使用上传时间（历史照片使用相册时间）。</p>
        <?php if ($__galleryGroups): ?><nav class="gallery-month-nav" aria-label="本页月份"><?php foreach ($__galleryGroups as $month => $items): ?><a href="#month-<?php echo htmlspecialchars($month, ENT_QUOTES); ?>"><?php echo $month === 'unknown' ? '日期未知' : htmlspecialchars($month); ?></a><?php endforeach; ?></nav><?php endif; ?>
      </section>
      <p id="gallery-status" role="status"><?php
        if ($__galleryError) echo '照片暂时无法加载，请稍后重试。';
        elseif (!$__gallery['albums']) echo $__gallery['next_url'] ? '本批次没有匹配的照片，继续搜索可查看更早的相册。' : '没有符合条件的照片。';
      ?></p>
      <div id="waterfall">
        <?php foreach ($__galleryGroups as $month => $items): ?>
          <h2 class="gallery-month-label" id="month-<?php echo htmlspecialchars($month, ENT_QUOTES); ?>"><?php echo $month === 'unknown' ? '日期未知' : htmlspecialchars($month); ?></h2>
          <?php foreach ($items as $album): include __DIR__ . '/lib/gallery-card.php'; endforeach; ?>
        <?php endforeach; ?>
      </div>
      <nav id="gallery-pagination" aria-label="照片分页">
        <?php if ($__galleryRequest['cursor']): ?><a class="gallery-first" href="<?php echo htmlspecialchars(pp_gallery_url($__galleryBase, $__galleryRequest, ['cursor' => '']), ENT_QUOTES); ?>">回到第一页</a><?php endif; ?>
        <?php if ($__gallery['next_url']): ?><a rel="next" href="<?php echo htmlspecialchars($__gallery['next_url'], ENT_QUOTES); ?>"><?php echo $__gallery['scan_limited'] ? '继续搜索更早的照片' : '下一页'; ?></a><?php endif; ?>
      </nav>
      <div id="load-more" data-page="1" data-total-pages="<?php echo $__gallery['next_url'] ? 2 : 1; ?>" data-next-url="<?php echo htmlspecialchars($__gallery['next_url'] ?? '', ENT_QUOTES); ?>"></div>
    </div>

      <!-- Footer -->
      <footer id="footer" class="panel">
            <div id="about">
              <section>
                <h2>关于</h2>
                <div class="about-text"><?php echo pp_safe_about((string)pp_opt('infinitytimeAbout', (string)$this->options->Biglogo, $this->options)); ?></div>
              </section>
              <section>
                <h2>联系我</h2>
                <?php
                $__contacts = json_decode((string)$this->options->infinitytimeContacts, true);
                $__enabled = [];
                foreach (is_array($__contacts) ? $__contacts : [] as $__c) {
                    if (!is_array($__c) || empty($__c['enabled']) || !is_string($__c['url'] ?? null)) {
                        continue;
                    }
                    $__c['url'] = pp_safe_contact_url($__c['url']);
                    if ($__c['url'] !== '') {
                        $__enabled[] = $__c;
                    }
                }
                ?>
                <?php if ($__enabled): ?>
                <ul class="icons">
                  <?php foreach ($__enabled as $__c): $__icon = is_string($__c['icon'] ?? null) && $__c['icon'] !== '' ? $__c['icon'] : 'icon-shouye'; ?>
                    <li><a class="contact_link" target="_blank" rel="noopener nofollow"
                        title="<?php echo htmlspecialchars(is_string($__c['name'] ?? null) ? $__c['name'] : ''); ?>"
                        href="<?php echo htmlspecialchars($__c['url']); ?>"><i class="iconfont <?php echo htmlspecialchars($__icon); ?>"></i></a></li>
                  <?php endforeach; ?>
                </ul>
                <?php else: ?>
                <div style="color:#999">暂未设置联系方式</div>
                <?php endif; ?>
              </section>
              <span style="color: #b5b5b5; font-size: 0.8em;">
                <?php $this->options->cnzz() ?>
                <div class="copyright-info">
                    <?php if ($this->options->police): ?>
                    <span class="police">
                        <img src="<?php $this->options->themeUrl('assets/img/police.png'); ?>" alt="公安备案" style="vertical-align: middle; width: 14px;">
                        <a href="https://beian.mps.gov.cn/#/query/webSearch" target="_blank" rel="noopener nofollow"><?php $this->options->police(); ?></a>
                    </span>
                    <?php endif; ?>
                    <?php if ($this->options->icp): ?>
                    <span class="icp">
                        <a href="http://beian.miit.gov.cn/" target="_blank" rel="noopener nofollow"><?php $this->options->icp(); ?></a>
                    </span>
                    <?php endif; ?>
                    <span class="theme"><a href="https://github.com/Thregren/InfinityTime" target="_blank" rel="noopener nofollow">InfinityTime Theme</a></span>
                </div>
      </footer>
      <script src="<?php $this->options->themeUrl('assets/js/data-saver.js?v=' . $__assetVer); ?>"></script>
      <script src="<?php $this->options->themeUrl('assets/js/init.js?v=' . $__assetVer); ?>"></script>
  </div>
  <!-- Scripts -->
  <script src="<?php $this->options->themeUrl('assets/js/jquery.min.js'); ?>"></script>
  <script src="<?php $this->options->themeUrl('assets/js/jquery.poptrox.min.js'); ?>"></script>
  <script src="<?php $this->options->themeUrl('assets/js/browser.min.js'); ?>"></script>
  <script src="<?php $this->options->themeUrl('assets/js/breakpoints.min.js'); ?>"></script>
  <script src="<?php $this->options->themeUrl('assets/js/pannellum.js'); ?>"></script>
  <script src="<?php $this->options->themeUrl('assets/js/lightbox.js?v=' . $__assetVer); ?>"></script>
  <script src="<?php $this->options->themeUrl('assets/js/main.js?v=' . $__assetVer); ?>"></script>
  <script src="<?php $this->options->themeUrl('assets/js/gallery-navigation.js?v=' . $__assetVer); ?>"></script>
</body>

</html>

