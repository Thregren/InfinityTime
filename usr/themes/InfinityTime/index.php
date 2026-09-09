<?php
/**
 * 一款简约的相册主题
 * @package 无限时光
 * @author InfinityTime
 * @version 1.13.2
 * @link https://github.com/InfinityTime/InfinityTime
 */
?>
<?php
// 静态资源版本号（以文件 mtime 生成，改动即失效缓存，避免改后还看到旧的 CSS/JS）
$__assetVer = substr(md5((string)@filemtime(__DIR__ . '/assets/css/main.css') . (string)@filemtime(__DIR__ . '/assets/js/main.js') . (string)@filemtime(__DIR__ . '/assets/js/lightbox.js')), 0, 8);
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
<html>

<head>
  <title><?php echo htmlspecialchars(pp_opt('infinitytimeSiteName', (string)$this->options->IndexName, $this->options)); ?> - <?php echo htmlspecialchars(pp_opt('infinitytimeSiteTagline', (string)$this->options->Indexdict, $this->options)); ?> </title>
  <meta http-equiv="content-type" content="text/html; charset=<?php $this->options->charset(); ?>" />
  <meta name="viewport" content="width=device-width, initial-scale=1, user-scalable=no" />
  <meta name="keywords" content="<?php $this->options->keywords(); ?>" />
  <meta name="description" content="<?php $this->options->description(); ?>" />
  <link rel="apple-touch-icon" href="<?php $this->options->AppleIcon(); ?>">
  <meta name="apple-mobile-web-app-title" content="<?php echo htmlspecialchars(pp_opt('infinitytimeSiteName', (string)$this->options->IndexName, $this->options), ENT_QUOTES); ?>">
  <link rel="bookmark" href="<?php $this->options->AppleIcon(); ?>">
  <link rel="apple-touch-icon-precomposed" sizes="180x180" href="<?php $this->options->AppleIcon(); ?>">
  <link rel="icon" href="<?php echo htmlspecialchars(pp_opt('infinitytimeSiteLogo', (string)$this->options->IconUrl, $this->options), ENT_QUOTES); ?>">
  <link rel="stylesheet" type="text/css" href="<?php $this->options->themeUrl('assets/css/main.css?v=' . $__assetVer); ?>" />
  <link rel="stylesheet" href="<?php $this->options->themeUrl('assets/css/iconfont.css'); ?>">
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

      <div id="waterfall">
      <?php while ($this->next()): ?>
        <article class="thumb img-area">
          <?php
          // 将多行图片链接分割成数组；无图文章直接跳过
          $images = array_values(array_filter(array_map('trim', explode("\n", (string)$this->fields->img))));
          $firstImage = $images[0] ?? '';
          if (!$firstImage) {
              continue;
          }
          // 缩略图字段（InfinityTime 插件生成），无则回退使用原图
          $thumbs = $this->fields->thumb ? array_map('trim', array_filter(explode("\n", $this->fields->thumb))) : null;
          $firstThumb = $thumbs ? $thumbs[0] : $firstImage;
          // EXIF / 地址（插件写入 JSON，按图片顺序）
          $exifList = json_decode($this->fields->exif, true);
          $addrList = json_decode($this->fields->addresses, true);
          $imgTitles = json_decode($this->fields->titles, true);
          $imgDescs = json_decode($this->fields->descs, true);
          $panoList = json_decode($this->fields->panos, true);
          $dimsList = json_decode($this->fields->dims, true);
          $variantsList = json_decode($this->fields->variants, true);
          if (!is_array($exifList)) { $exifList = []; }
          if (!is_array($addrList)) { $addrList = []; }
          if (!is_array($imgTitles)) { $imgTitles = []; }
          if (!is_array($imgDescs)) { $imgDescs = []; }
          if (!is_array($panoList)) { $panoList = []; }
          if (!is_array($dimsList)) { $dimsList = []; }
          if (!is_array($variantsList)) { $variantsList = []; }
          // 去掉 null/空 字段，压缩内嵌 JSON；前端对缺失字段同样按“无”处理，展示不受影响。
          $exifList = array_map(function ($e) {
              return is_array($e) ? array_filter($e, function ($v) { return $v !== null && $v !== ''; }) : $e;
          }, $exifList);
          $exif0 = $exifList[0] ?? [];
          $addr0 = $addrList[0] ?? ($this->fields->location ? $this->fields->location : '');
          ?>
          <a class="image my-photo" aria-label="<?php echo htmlspecialchars((string)$this->title, ENT_QUOTES); ?>" href="<?php echo htmlspecialchars($firstImage, ENT_QUOTES); ?>"
             data-images='<?php echo json_encode($images, $__jsonFlags); ?>'
             data-previews='<?php echo json_encode($thumbs ?: $images ?: [], $__jsonFlags); ?>'
             data-exif='<?php echo json_encode($exifList, $__jsonFlags); ?>'
             data-addresses='<?php echo json_encode($addrList, $__jsonFlags); ?>'
             data-titles='<?php echo json_encode($imgTitles, $__jsonFlags); ?>'
             data-descs='<?php echo json_encode($imgDescs, $__jsonFlags); ?>'
             data-panos='<?php echo json_encode($panoList, $__jsonFlags); ?>'
             data-dims='<?php echo json_encode($dimsList, $__jsonFlags); ?>'
             data-variants='<?php echo json_encode($variantsList, $__jsonFlags); ?>'>
            <img class="zmki_px my-photo"
              alt="<?php echo htmlspecialchars((string)$this->title, ENT_QUOTES); ?>"
              src="<?php echo htmlspecialchars($firstThumb, ENT_QUOTES); ?>"
              loading="lazy" decoding="async"
              onerror="this.src='<?php $this->options->themeUrl('assets/img/loading.gif'); ?>';this.onerror=null"
              data-src="<?php echo htmlspecialchars($firstThumb, ENT_QUOTES); ?>" />
          </a>
          <h2><?php echo htmlspecialchars((string)$this->title); ?></h2>
          <?php if($this->content): ?>
          <div class="content-wrapper">
            <p><?php $this->content('内容加载中...'); ?></p>
          </div>
          <?php endif; ?>
          <li class="tag-info tag-info-bottom">
            <?php if($this->fields->device): ?>
            <span class="tag-device"><i class="iconfont icon-camera-lens-line"></i><?php echo htmlspecialchars((string)$this->fields->device); ?></span>
            <?php endif; ?>
            <?php if($this->fields->location): ?>
            <span class="tag-location"><i class="iconfont icon-map-pin-2-line"></i><?php echo htmlspecialchars((string)$this->fields->location); ?></span>
            <?php endif; ?>
            <?php if (!empty($exif0['datetime'])): ?>
            <span class="tag-time"><i class="iconfont icon-time-line"></i><?php echo htmlspecialchars(pp_date_cn((string)$exif0['datetime'])); ?></span>
            <?php endif; ?>
          </li>
          <li class="tag-info">
            <span class="tag-categorys"><?php $this->category(''); ?></span>
            <?php if($this->tags): ?>
            <span class="tag-list"><?php $this->tags('', true); ?></span>
            <?php endif; ?>
          </li>
          <!-- EXIF 参数面板（灯箱内显示，卡片上隐藏） -->
          <div class="exif-panel">
            <div class="exif-title">拍摄参数</div>
            <div class="exif-grid">
              <?php if(!empty($exif0['make']) || !empty($exif0['model'])): ?>
                <div class="exif-item"><span>相机</span><b><?php echo htmlspecialchars(trim(($exif0['make'] ?? '') . ' ' . ($exif0['model'] ?? ''))); ?></b></div>
              <?php endif; ?>
              <?php $exifLens = pp_exif_lens($exif0); ?>
              <?php if($exifLens !== ''): ?>
                <div class="exif-item"><span>镜头</span><b><?php echo htmlspecialchars($exifLens); ?></b></div>
              <?php endif; ?>
              <?php if(!empty($exif0['iso'])): ?><div class="exif-item"><span>ISO</span><b><?php echo (int)$exif0['iso']; ?></b></div><?php endif; ?>
              <?php if(!empty($exif0['fnumber'])): ?><div class="exif-item"><span>光圈</span><b>f/<?php echo htmlspecialchars((string)$exif0['fnumber']); ?></b></div><?php endif; ?>
              <?php if(!empty($exif0['exposure'])): ?><div class="exif-item"><span>快门</span><b><?php echo htmlspecialchars($exif0['exposure']); ?></b></div><?php endif; ?>
              <?php $focalShow = $exif0['focal35'] ?? $exif0['focal'] ?? ''; ?>
              <?php if(!empty($focalShow)): ?><div class="exif-item"><span>焦距</span><b><?php echo htmlspecialchars((string)$focalShow); ?>mm</b></div><?php endif; ?>
              <?php if(!empty($exif0['flash'])): ?><div class="exif-item"><span>闪光</span><b>是</b></div><?php endif; ?>
              <?php if(!empty($exif0['datetime'])): ?><div class="exif-item"><span>时间</span><b><?php echo htmlspecialchars(pp_date_cn((string)$exif0['datetime'])); ?></b></div><?php endif; ?>
            </div>
            <div class="exif-addr"><i class="iconfont icon-map-pin-2-line"></i><span class="exif-addr-text"><?php echo htmlspecialchars($addr0 ?: ''); ?></span></div>
          </div>
          <?php if (in_array(1, $panoList, true)): ?>
          <span class="pano-badge">全景</span>
          <?php endif; ?>
        </article>
      <?php endwhile; ?>
      </div>
      
      <!-- 无限瀑布流：不渲染分页页码，仅计算总量供 #load-more 滚动加载使用 -->
      <?php
        $total = ceil($this->getTotal() / $this->parameter->pageSize);
        $category = $this->is('category') ? $this->getArchiveSlug() : '';
      ?>

      <!-- 原有的 load-more div -->
      <div id="load-more" data-page="1" data-total-pages="<?php echo $total; ?>"></div>
    </div>

    <body>
      <!-- Footer -->
      <footer id="footer" class="panel">
            <div id="about">
              <section>
                <h2>关于</h2>
                <div class="about-text"><?php echo pp_opt('infinitytimeAbout', (string)$this->options->Biglogo, $this->options); ?></div>
              </section>
              <section>
                <h2>联系我</h2>
                <?php
                $__contacts = json_decode((string)$this->options->infinitytimeContacts, true) ?: [];
                $__enabled = array_values(array_filter($__contacts, function ($c) {
                    return !empty($c['url']) && !empty($c['enabled']);
                }));
                ?>
                <?php if ($__enabled): ?>
                <ul class="icons">
                  <?php foreach ($__enabled as $__c): $__icon = !empty($__c['icon']) ? $__c['icon'] : 'icon-shouye'; ?>
                    <li><a class="contact_link" target="_blank" rel="noopener nofollow"
                        title="<?php echo htmlspecialchars((string)($__c['name'] ?? '')); ?>"
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
      <script type="text/javascript">
        // 缩略图懒加载已交给原生 loading="lazy"（模板里 src 与 data-src 相同），
        // 原来的 checkImgs/loadImg 滚动监听因条件永不成立而成为冗余，已移除。
        // 用插件写入的 data-dims 给缩略图预占位（aspect-ratio），
        // 让 CSS 多列布局在图片加载前就有确定高度，避免加载后高度突变导致图片顺序跳变。
        function applyDims(scope) {
          (scope || document).querySelectorAll('a.image.my-photo').forEach(function (a) {
            var host = a.querySelector('img');
            if (!host) return;
            // 骨架屏：图片加载完成（或失败）后给卡片加 img-loaded，移除 shimmer 占位
            var thumb = a.closest ? a.closest('.thumb') : null;
            if (thumb && !host.__ppLoadedBound) {
              host.__ppLoadedBound = true;
              var markLoaded = function () { thumb.classList.add('img-loaded'); };
              if (host.complete && host.naturalWidth > 0) { markLoaded(); }
              else {
                host.addEventListener('load', markLoaded, { once: true });
                host.addEventListener('error', markLoaded, { once: true });
              }
            }
            var dims = [];
            try { dims = JSON.parse(a.dataset.dims || '[]'); } catch (e) {}
            if (!dims.length) return;
            var m = String(dims[0] || '').split('x');
            var w = parseInt(m[0], 10), h = parseInt(m[1], 10);
            if (w > 0 && h > 0) {
              // 宽高百分比回退：height:auto 会被 aspect-ratio + width 推出来；
              // 这里同时给 width/height 属性，让现代浏览器在图片解码前也能按比例占空间。
              host.style.aspectRatio = w + ' / ' + h;
              host.setAttribute('width', String(w));
              host.setAttribute('height', String(h));
            }
          });
        }
        applyDims(document);
        // 无限瀑布流翻页后要重新给新卡片占位，暴露给其它内联脚本调用。
        window.applyDims = applyDims;
      </script>
      <script>
      // 瀑布流：按响应式列数把卡片分配到弹性列，保证首行完全顶对齐
      (function () {
        var wf = document.getElementById('waterfall');
        if (!wf) return;
        // 列布局交给 CSS column 实现，DOM 保持源码顺序（灯箱 poptrox 因此按源码顺序切图）。
        // 无限瀑布流：滚动到底自动加载下一页并追加到容器。
        var lm = document.getElementById('load-more');
        var PAGER_BASE = <?php echo json_encode($this->is('category')
            ? (rtrim((string)$this->options->siteUrl, '/') . '/index.php/category/' . $this->getArchiveSlug() . '/')
            : (rtrim((string)$this->options->siteUrl, '/') . '/index.php/page/')); ?>;
        var curPage = lm ? (parseInt(lm.getAttribute('data-page'), 10) || 1) : 1;
        var totPages = lm ? (parseInt(lm.getAttribute('data-total-pages'), 10) || 1) : 1;
        var loadingMore = false;
        function loadMore() {
          if (!lm || loadingMore || curPage >= totPages) return;
          loadingMore = true;
          fetch(PAGER_BASE + (curPage + 1), { credentials: 'same-origin' })
            .then(function (r) { return r.text(); })
            .then(function (html) {
              try {
                var doc = new DOMParser().parseFromString(html, 'text/html');
                var cards = Array.prototype.slice.call(doc.querySelectorAll('#waterfall > .thumb'));
                if (cards.length) {
                  cards.forEach(function (card) { wf.appendChild(card); });
                  curPage += 1;
                  if (lm) lm.setAttribute('data-page', String(curPage));
                  // 新卡片需绑定灯箱（poptrox 只在初始化时逐个绑定），否则点击会直接跳原图
                  if (typeof window.__rebindPoptrox === 'function') {
                    try { window.__rebindPoptrox(); } catch (e) {}
                  }
                  if (typeof window.applyDims === 'function') window.applyDims(document);
                }
              } catch (e) {}
              loadingMore = false;
            })
            .catch(function () { loadingMore = false; });
        }
        window.addEventListener('scroll', function () {
          if ((window.innerHeight + window.scrollY) >= (document.documentElement.offsetHeight - 600)) loadMore();
        }, { passive: true });
        if (document.documentElement.offsetHeight <= window.innerHeight + 600) loadMore();
      })();
      </script>
      <script>
      // 为灯箱控制按钮补无障碍 aria-label / title（poptrox 生成的弹窗）
      (function () {
        var map = { '.closer': '关闭', '.nav-previous': '上一张', '.nav-next': '下一张' };
        function labelPopup(popup) {
          Object.keys(map).forEach(function (sel) {
            var el = popup.querySelector(sel);
            if (el) {
              if (!el.getAttribute('aria-label')) el.setAttribute('aria-label', map[sel]);
              if (!el.getAttribute('title')) el.setAttribute('title', map[sel]);
            }
          });
        }
        document.querySelectorAll('.poptrox-popup').forEach(labelPopup);
        var obs = new MutationObserver(function (muts) {
          muts.forEach(function (m) {
            m.addedNodes.forEach(function (n) {
              if (n.nodeType !== 1) return;
              if (n.classList && n.classList.contains('poptrox-popup')) labelPopup(n);
              var p = n.querySelector && n.querySelector('.poptrox-popup');
              if (p) labelPopup(p);
            });
          });
        });
        obs.observe(document.body, { childList: true, subtree: true });
      })();
      </script>
  </div>
  <!-- Scripts -->
  <script src="<?php $this->options->themeUrl('assets/js/jquery.min.js'); ?>"></script>
  <script src="<?php $this->options->themeUrl('assets/js/jquery.poptrox.min.js'); ?>"></script>
  <script src="<?php $this->options->themeUrl('assets/js/browser.min.js'); ?>"></script>
  <script src="<?php $this->options->themeUrl('assets/js/breakpoints.min.js'); ?>"></script>
  <script src="<?php $this->options->themeUrl('assets/js/pannellum.js'); ?>"></script>
  <script src="<?php $this->options->themeUrl('assets/js/lightbox.js?v=' . $__assetVer); ?>"></script>
  <script src="<?php $this->options->themeUrl('assets/js/main.js?v=' . $__assetVer); ?>"></script>
</body>

</html>
