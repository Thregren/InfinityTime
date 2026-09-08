<?php
/**
 * 一款简约的相册主题
 * @package 无限时光
 * @author InfinityTime
 * @version 1.8.3
 * @link https://github.com/InfinityTime/InfinityTime
 */
?>
<?php
// 静态资源版本号（以文件 mtime 生成，改动即失效缓存，避免改后还看到旧的 CSS/JS）
$__assetVer = substr(md5((string)@filemtime(__DIR__ . '/assets/css/main.css') . (string)@filemtime(__DIR__ . '/assets/js/main.js')), 0, 8);
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
          if (!is_array($exifList)) { $exifList = []; }
          if (!is_array($addrList)) { $addrList = []; }
          if (!is_array($imgTitles)) { $imgTitles = []; }
          if (!is_array($imgDescs)) { $imgDescs = []; }
          if (!is_array($panoList)) { $panoList = []; }
          if (!is_array($dimsList)) { $dimsList = []; }
          // 去掉 null/空 字段，压缩内嵌 JSON；前端对缺失字段同样按“无”处理，展示不受影响。
          $exifList = array_map(function ($e) {
              return is_array($e) ? array_filter($e, function ($v) { return $v !== null && $v !== ''; }) : $e;
          }, $exifList);
          $exif0 = $exifList[0] ?? [];
          $addr0 = $addrList[0] ?? ($this->fields->location ? $this->fields->location : '');
          ?>
          <a class="image my-photo" aria-label="<?php echo htmlspecialchars($this->title()); ?>" href="<?php echo htmlspecialchars($firstImage, ENT_QUOTES); ?>"
             data-images='<?php echo json_encode($images, $__jsonFlags); ?>'
             data-previews='<?php echo json_encode($thumbs ?: $images ?: [], $__jsonFlags); ?>'
             data-exif='<?php echo json_encode($exifList, $__jsonFlags); ?>'
             data-addresses='<?php echo json_encode($addrList, $__jsonFlags); ?>'
             data-titles='<?php echo json_encode($imgTitles, $__jsonFlags); ?>'
             data-descs='<?php echo json_encode($imgDescs, $__jsonFlags); ?>'
             data-panos='<?php echo json_encode($panoList, $__jsonFlags); ?>'
             data-dims='<?php echo json_encode($dimsList, $__jsonFlags); ?>'>
            <img class="zmki_px my-photo"
              alt="<?php echo htmlspecialchars($this->title()); ?>"
              src="<?php echo htmlspecialchars($firstThumb, ENT_QUOTES); ?>"
              loading="lazy" decoding="async"
              onerror="this.src='<?php $this->options->themeUrl('assets/img/loading.gif'); ?>';this.onerror=null"
              data-src="<?php echo htmlspecialchars($firstThumb, ENT_QUOTES); ?>" />
          </a>
          <h2><?php $this->title() ?></h2>
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
      <script>
      document.addEventListener('DOMContentLoaded', function() {

        // 把 EXIF 生成成 HTML（按字段）
        // 镜头标签：直接使用 EXIF 读取到的镜头描述；无则不显示（不做推测）
        function lensLabel(exif) {
          if (exif.lens) return exif.lens;
          return '';
        }
        function exifItemHtml(exif) {
          let html = '';
          if (exif.make || exif.model) html += '<div class="exif-item"><span>相机</span><b>' + esc((exif.make||'') + ' ' + (exif.model||'')) + '</b></div>';
          var lens = lensLabel(exif);
          if (lens) html += '<div class="exif-item"><span>镜头</span><b>' + esc(lens) + '</b></div>';
          if (exif.iso) html += '<div class="exif-item"><span>ISO</span><b>' + esc(exif.iso) + '</b></div>';
          if (exif.fnumber) html += '<div class="exif-item"><span>光圈</span><b>f/' + esc(exif.fnumber) + '</b></div>';
          if (exif.exposure) html += '<div class="exif-item"><span>快门</span><b>' + esc(exif.exposure) + '</b></div>';
          if (exif.focal || exif.focal35) {
            var fl = exif.focal35 || exif.focal;
            html += '<div class="exif-item"><span>焦距</span><b>' + esc(fl) + 'mm</b></div>';
          }
          if (exif.flash) html += '<div class="exif-item"><span>闪光</span><b>是</b></div>';
          if (exif.datetime) html += '<div class="exif-item"><span>时间</span><b>' + esc(cnDate(exif.datetime)) + '</b></div>';
          return html;
        }
        // EXIF 时间转中文：2026:06:21 17:46:53 -> 2026年06月21日 17:46:53
        function cnDate(s) {
          var m = String(s || '').match(/^(\d{4}):(\d{2}):(\d{2})[ T]?(\d{2}:\d{2}(?::\d{2})?)?/);
          if (m) return m[1] + '年' + m[2] + '月' + m[3] + '日' + (m[4] ? ' ' + m[4] : '');
          return s;
        }
        let exifDock = null;
        // 获取（或创建）主图外侧的 EXIF 停靠侧栏
        function getExifDock() {
          if (!exifDock) {
            exifDock = document.createElement('div');
            exifDock.className = 'poptrox-exif-dock';
            exifDock.innerHTML =
              '<div class="exif-dock-handle" role="button" tabindex="0" aria-label="展开或收起拍摄参数">'
              + '<span class="exif-dock-grip"></span><span class="exif-dock-handle-text">拍摄参数</span>'
              + '</div>'
              + '<div class="exif-imgtitle"></div>'
              + '<div class="exif-imgdesc"></div>'
              + '<div class="exif-title">拍摄参数</div>'
              + '<div class="exif-grid"></div>'
              + '<div class="exif-addr"><i class="iconfont icon-map-pin-2-line"></i><span class="exif-addr-text"></span></div>'
              + '<div class="exif-palette"><div class="exif-title">主题色</div><div class="palette-list"></div>'
              + '<canvas class="hist-canvas" width="240" height="88"></canvas></div>';
            // 移动端抽屉：点/回车把手展开或收起（桌面端把手隐藏，不影响布局）
            var toggleDock = function (e) {
              var h = e && e.target && e.target.closest ? e.target.closest('.exif-dock-handle') : null;
              if (h) exifDock.classList.toggle('expanded');
            };
            exifDock.addEventListener('click', toggleDock);
            exifDock.addEventListener('keydown', function (e) {
              if (e.key !== 'Enter' && e.key !== ' ') return;
              var h = e.target && e.target.closest ? e.target.closest('.exif-dock-handle') : null;
              if (h) { e.preventDefault(); exifDock.classList.toggle('expanded'); }
            });
            document.body.appendChild(exifDock);
          }
          return exifDock;
        }
        function rgbToHex(r, g, b) {
          function h(v) {
            var s = Number(v) & 255;
            var x = s.toString(16).toUpperCase();
            return x.length < 2 ? '0' + x : x;
          }
          return '#' + h(r) + h(g) + h(b);
        }
        // 单次采样照片：返回 3 个真实存在的代表性颜色 + RGB 直方图 + 主色（用于氛围光）。
        // 结果按 src 缓存，并合并同一张图的并发请求：来回切图不再重复解码 + 采样。
        var __photoCache = {};   // src -> result
        var __photoPending = {}; // src -> [cb, ...]
        function analyzePhoto(src, cb) {
          if (!src) { cb({ colors: [], hist: null, top: null }); return; }
          if (__photoCache[src]) { cb(__photoCache[src]); return; }
          if (__photoPending[src]) { __photoPending[src].push(cb); return; }
          __photoPending[src] = [cb];
          function finish(res) {
            __photoCache[src] = res;
            var list = __photoPending[src] || [];
            delete __photoPending[src];
            for (var i = 0; i < list.length; i++) { try { list[i](res); } catch (e) {} }
          }
          var img = new Image();
          img.crossOrigin = 'anonymous';
          img.onload = function () {
            try {
              var w = img.naturalWidth, h = img.naturalHeight;
              if (!w || !h) return finish({ colors: [], hist: null, top: null });
              var maxDim = 300;
              var scale = Math.min(1, maxDim / Math.max(w, h));
              var cw = Math.max(1, Math.round(w * scale)), ch = Math.max(1, Math.round(h * scale));
              var c = document.createElement('canvas');
              c.width = cw; c.height = ch;
              var ctx = c.getContext('2d', { willReadFrequently: true });
              ctx.drawImage(img, 0, 0, cw, ch);
              var data = ctx.getImageData(0, 0, cw, ch).data;
              var histR = [], histG = [], histB = [];
              for (var b0 = 0; b0 < 64; b0++) { histR[b0] = 0; histG[b0] = 0; histB[b0] = 0; }
              // 按“色相”分桶：把同一色系的颜色合并（绿色水母、粉色水母分别成桶），
              // 这样小面积的醒目色不会被巨大的背景/剪影淹没；低饱和度(灰/黑)单独压低权重。
              var hueB = {};   // 色相桶 -> { n, satSum, exact:{ek:{r,g,b,c}} }
              var grayB = {};  // dark/mid/light -> { n, exact:{...} }
              for (var i = 0; i < data.length; i += 4) {
                var r = data[i], g = data[i + 1], b = data[i + 2];
                if (data[i + 3] < 128) continue;
                histR[(r >> 2) & 63]++;
                histG[(g >> 2) & 63]++;
                histB[(b >> 2) & 63]++;
                var mx = Math.max(r, g, b), mn = Math.min(r, g, b), diff = mx - mn;
                var sat = mx ? (diff / mx) : 0;
                var hue = 0;
                if (diff > 0) {
                  if (mx === r) hue = 60 * ((g - b) / diff);
                  else if (mx === g) hue = 60 * (((b - r) / diff) + 2);
                  else hue = 60 * (((r - g) / diff) + 4);
                  if (hue < 0) hue += 360;
                }
                var ek = (r << 16) | (g << 8) | b;
                if (sat < 0.14) {
                  var gk = mx < 72 ? 'dark' : (mx > 200 ? 'light' : 'mid');
                  var g0 = grayB[gk] || (grayB[gk] = { n: 0, exact: {} });
                  g0.n++;
                  if (g0.exact[ek]) g0.exact[ek].c++; else g0.exact[ek] = { r: r, g: g, b: b, c: 1 };
                } else {
                  var hb = Math.floor(hue / 15);
                  var o = hueB[hb] || (hueB[hb] = { n: 0, satSum: 0, exact: {} });
                  o.n++; o.satSum += sat;
                  if (o.exact[ek]) o.exact[ek].c++; else o.exact[ek] = { r: r, g: g, b: b, c: 1 };
                }
              }
              function modeOf(exact) {
                var best = null, bc = -1;
                for (var ek in exact) { if (exact[ek].c > bc) { bc = exact[ek].c; best = exact[ek]; } }
                return best;
              }
              // 候选：彩色桶按 “数量 × (0.2 + 平均饱和度²)” 打分（醒目彩色占优），灰色桶仅 0.06 权重
              var cands = [];
              Object.keys(hueB).forEach(function (hb) {
                var o = hueB[hb];
                if (!o.n) return;
                var m = modeOf(o.exact);
                if (!m) return;
                var avgSat = o.satSum / o.n;
                cands.push({ r: m.r, g: m.g, b: m.b, score: o.n * (0.2 + avgSat * avgSat), n: o.n });
              });
              Object.keys(grayB).forEach(function (gk) {
                var g0 = grayB[gk];
                if (!g0.n) return;
                var m = modeOf(g0.exact);
                if (!m) return;
                cands.push({ r: m.r, g: m.g, b: m.b, score: g0.n * 0.06, n: g0.n });
              });
              cands.sort(function (a, b) { return b.score - a.score; });
              // 只在“足够显著”的颜色里做“最大最小距离”挑选，避免选到极小噪点；
              // 选满 3 个，颜色尽量分散，照片色够多时不会出现两个很接近的色。
              var pool = cands.slice(0, 15);
              var picked = [];
              if (pool.length) {
                picked.push(pool[0]);
                while (picked.length < 3) {
                  var selected = null, bestMin = -1;
                  for (var ci = 0; ci < pool.length; ci++) {
                    var ci2 = pool[ci];
                    var already = picked.indexOf(ci2) >= 0;
                    if (already) continue;
                    var minD = 765;
                    for (var pi = 0; pi < picked.length; pi++) {
                      var d = Math.abs(ci2.r - picked[pi].r) + Math.abs(ci2.g - picked[pi].g) + Math.abs(ci2.b - picked[pi].b);
                      if (d < minD) minD = d;
                    }
                    if (minD > bestMin) { bestMin = minD; selected = ci2; }
                  }
                  if (!selected) break;
                  picked.push(selected);
                }
              }
              finish({
                colors: picked.slice(0, 3).map(function (e) { return rgbToHex(e.r, e.g, e.b); }),
                hist: [histR, histG, histB]
              });
            } catch (e) { finish({ colors: [], hist: null, top: null }); }
          };
          img.onerror = function () { finish({ colors: [], hist: null, top: null }); };
          img.src = src;
        }
        // 画 RGB 直方图（三条半透明色带）
        function drawHistogram(cv, hist) {
          try {
            if (!cv || !hist || !hist.length || !hist[0] || !hist[0].length) return;
            // 按实际显示尺寸 × 设备像素比渲染，避免被 CSS 拉伸导致模糊
            var dpr = window.devicePixelRatio || 1;
            var cw = cv.clientWidth || 240, ch = cv.clientHeight || 88;
            cv.width = Math.max(1, Math.round(cw * dpr));
            cv.height = Math.max(1, Math.round(ch * dpr));
            var ctx = cv.getContext('2d');
            ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
            var W = cw, H = ch;
            ctx.clearRect(0, 0, W, H);
            var cols = ['rgba(255,86,86,.55)', 'rgba(96,221,96,.5)', 'rgba(86,150,255,.55)'];
            for (var ch = 0; ch < 3; ch++) {
              var bins = hist[ch];
              var max = 1;
              for (var i = 0; i < bins.length; i++) { if (bins[i] > max) max = bins[i]; }
              ctx.beginPath();
              ctx.moveTo(0, H);
              for (var x = 0; x < bins.length; x++) {
                var px = x / (bins.length - 1) * W;
                var py = H - (bins[x] / max) * H;
                ctx.lineTo(px, py);
              }
              ctx.lineTo(W, H);
              ctx.closePath();
              ctx.fillStyle = cols[ch];
              ctx.fill();
            }
            ctx.strokeStyle = 'rgba(255,255,255,.14)';
            ctx.lineWidth = 1;
            ctx.strokeRect(.5, .5, W - 1, H - 1);
          } catch (e) {}
        }
        // 把主题色 + 直方图渲染到 EXIF 侧栏，并用主色给弹窗加氛围光
        function renderThemePalette(popup) {
          const dock = getExifDock();
          const list = dock.querySelector('.palette-list');
          const box = dock.querySelector('.exif-palette');
          const histCv = dock.querySelector('.hist-canvas');
          const img = popup && popup.querySelector('.pic img');
          if (!list || !box || !img) return;
          const src = (img.getAttribute('src') || '').split('?')[0];
          if (!src) { box.style.display = 'none'; if (histCv) histCv.style.display = 'none'; return; }
          box.style.display = '';
          if (histCv) histCv.style.display = '';
          list.innerHTML = '<span class="palette-loading">提取中…</span>';
          analyzePhoto(src, function (res) {
            if (!res || !res.colors || !res.colors.length) { box.style.display = 'none'; if (histCv) histCv.style.display = 'none'; return; }
            list.innerHTML = res.colors.map(function (hex) {
              return '<span class="palette-item"><span class="palette-swatch" style="background:' + hex + '"></span><span class="palette-hex">' + hex + '</span></span>';
            }).join('');
            if (histCv && res.hist) drawHistogram(histCv, res.hist);
          });
        }
        // 从相册文章读取该相册的图片 / EXIF / 地址 / 标题 / 描述数组
        function articleData(article) {
          if (!article) return null;
          const a = article.querySelector ? article.querySelector('a.image') : null;
          const src = a || article;
          const d = { images: [], previews: [], exif: [], addr: [], titles: [], descs: [], panos: [] };
          try { d.images = JSON.parse(src.dataset.images || '[]'); } catch (e) {}
          try { d.previews = JSON.parse(src.dataset.previews || '[]'); } catch (e) {}
          try { d.exif = JSON.parse(src.dataset.exif || '[]'); } catch (e) {}
          try { d.addr = JSON.parse(src.dataset.addresses || '[]'); } catch (e) {}
          try { d.titles = JSON.parse(src.dataset.titles || '[]'); } catch (e) {}
          try { d.descs = JSON.parse(src.dataset.descs || '[]'); } catch (e) {}
          try { d.panos = JSON.parse(src.dataset.panos || '[]'); } catch (e) {}
          return d;
        }
        // 根据当前激活图片索引刷新 EXIF 侧栏（数据源：popup.__article 相册）
        function renderExif(popup, index) {
          const dock = getExifDock();
          const d = articleData(popup && popup.__article);
          if (!d) return;
          const exif = d.exif[index] || {};
          const addr = d.addr[index] || '';
          const title = d.titles[index] || '';
          const desc = d.descs[index] || '';
          const tt = dock.querySelector('.exif-imgtitle');
          if (tt) { tt.textContent = title; tt.style.display = title ? '' : 'none'; }
          const dd = dock.querySelector('.exif-imgdesc');
          if (dd) { dd.textContent = desc; dd.style.display = desc ? '' : 'none'; }
          const g = dock.querySelector('.exif-grid');
          if (g) g.innerHTML = exifItemHtml(exif);
          const t = dock.querySelector('.exif-addr-text');
          if (t) t.textContent = addr;
          const ar = dock.querySelector('.exif-addr');
          if (ar) ar.style.display = addr ? '' : 'none';
          renderThemePalette(popup);
        }

        // 根据当前弹窗显示的主图 src，匹配到该相册里的图片下标
        function currentImgIndex(popup, d) {
          const img = popup && popup.querySelector('.pic img');
          if (!img || !d) return -1;
          const src = (img.getAttribute('src') || '').split('?')[0];
          if (!src) return -1;
          let idx = d.images.indexOf(src);
          if (idx < 0) {
            for (let i = 0; i < d.images.length; i++) {
              if (src.indexOf(d.images[i]) === 0) { idx = i; break; }
            }
          }
          return idx;
        }
        // 从弹窗主图 URL 反查对应的相册文章
        function findArtForPopup(popup) {
          const img = popup.querySelector('.pic img');
          if (!img) return null;
          const src = (img.getAttribute('src') || '').split('?')[0];
          let art = null;
          document.querySelectorAll('a.image').forEach(function(a) {
            if (art || !a.dataset.images) return;
            try {
              const imgs = JSON.parse(a.dataset.images || '[]');
              if (imgs.some(function(u) { return src.indexOf(u) === 0; })) art = a.closest('.thumb');
            } catch (e) {}
          });
          return art;
        }
        function esc(s) { return String(s).replace(/[&<>"]/g, function(c){ return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]; }); }

        // ===== 灯箱全景（Pannellum）：长宽比 ≥2 自动进入可拖拽/缩放的 360 浏览 =====
        let ppPanoState = null;
        let ppPanoGuardBound = false;
        let ppPanoDownX = 0, ppPanoDownY = 0, ppPanoMoved = false;
        let ppPanoFullscreen = false;
        // 点击捕获：
        //  - 全屏时：不响应“点击退出/关闭”，但全屏/退出按钮与 Pannellum 控件仍可点；
        //  - 非全屏：仅当发生真实拖拽（位移>8px）才吞掉紧随其后的 click（无论松手在哪）；纯点击不拦
        function ppPanoClickGuard(e) {
          if (ppPanoFullscreen) {
            var t = e.target;
            if (t && t.closest && t.closest('.pp-pano-fullscreen, .pp-pano-fullscreen-exit, .pnlm-control, .pnlm-hotspot, .pnlm-hotspot-base')) { ppPanoMoved = false; return; }
            e.stopPropagation(); e.preventDefault(); ppPanoMoved = false; return;
          }
          if (ppPanoMoved) { e.stopPropagation(); e.preventDefault(); ppPanoMoved = false; }
        }
        // 按压捕获：记录按下位置，重置拖拽标记
        function ppPanoDownGuard(e) {
          ppPanoMoved = false;
          try { if (e.target && e.target.closest && e.target.closest('.pp-pano-viewer')) { ppPanoDownX = e.clientX; ppPanoDownY = e.clientY; } } catch (err) {}
        }
        // 移动捕获：在全景内移动超过阈值判定为拖拽
        function ppPanoMoveGuard(e) {
          try {
            if (e.target && e.target.closest && e.target.closest('.pp-pano-viewer')) {
              var dx = e.clientX - ppPanoDownX, dy = e.clientY - ppPanoDownY;
              if (dx * dx + dy * dy > 64) ppPanoMoved = true;
            }
          } catch (err) {}
        }
        function ppBindPanoGuard() {
          if (ppPanoGuardBound) return;
          document.addEventListener('click', ppPanoClickGuard, true);
          document.addEventListener('pointerdown', ppPanoDownGuard, true);
          ['pointermove', 'mousemove', 'touchmove'].forEach(function (ev) {
            document.addEventListener(ev, ppPanoMoveGuard, true);
          });
          ppPanoGuardBound = true;
        }
        // 全景销毁时解绑 document 级捕获监听，避免灯箱关闭后仍常驻消耗
        function ppUnbindPanoGuard() {
          if (!ppPanoGuardBound) return;
          document.removeEventListener('click', ppPanoClickGuard, true);
          document.removeEventListener('pointerdown', ppPanoDownGuard, true);
          ['pointermove', 'mousemove', 'touchmove'].forEach(function (ev) {
            document.removeEventListener(ev, ppPanoMoveGuard, true);
          });
          ppPanoGuardBound = false;
        }
        function ppSetPanoActive(popup, v) {
          if (popup) popup.__panoActive = !!v;
          document.querySelectorAll('.poptrox-popup').forEach(function (p) { if (p !== popup) p.__panoActive = false; });
        }
        function ppDestroyPano() {
          ppPanoFullscreen = false;
          ppUnbindPanoGuard();
          if (ppPanoState) {
            try { if (ppPanoState.viewer && ppPanoState.viewer.destroy) ppPanoState.viewer.destroy(); } catch (e) {}
            try { if (ppPanoState.ro && ppPanoState.ro.disconnect) ppPanoState.ro.disconnect(); } catch (e) {}
            try { if (ppPanoState.onFsChange) document.removeEventListener('fullscreenchange', ppPanoState.onFsChange); } catch (e) {}
            if (document.fullscreenElement) { try { document.exitFullscreen(); } catch (e) {} }
            if (ppPanoState.wrap && ppPanoState.wrap.parentNode) ppPanoState.wrap.parentNode.removeChild(ppPanoState.wrap);
            if (ppPanoState.fsBtn && ppPanoState.fsBtn.parentNode) ppPanoState.fsBtn.parentNode.removeChild(ppPanoState.fsBtn);
            if (ppPanoState.exitBtn && ppPanoState.exitBtn.parentNode) ppPanoState.exitBtn.parentNode.removeChild(ppPanoState.exitBtn);
            if (ppPanoState.img) ppPanoState.img.style.visibility = '';
            ppPanoState = null;
          }
          ppSetPanoActive(null, false);
        }
        function ppMountPano(popup, url) {
          if (ppPanoState && ppPanoState.popup === popup && ppPanoState.url === url && ppPanoState.viewer) return;
          ppDestroyPano();
          if (typeof window.pannellum === 'undefined') { console.error('[InfinityTime pano] pannellum not loaded'); return; } // 库未加载（异常兜底）
          const pic = popup.querySelector('.pic');
          if (!pic) return;
          const img = pic.querySelector('img');
          // 移除 blur 预览遮层（z-index 4）避免盖住 Pannellum
          const lq = pic.querySelector('.pp-lqip');
          if (lq && lq.parentNode) lq.parentNode.removeChild(lq);
          const wrap = document.createElement('div');
          wrap.className = 'pp-pano-viewer';
          // 内联绝对定位：不依赖外部 CSS（避免某些场景下未生效），铺满 .pic
          wrap.style.cssText = 'position:absolute;top:0;left:0;right:0;bottom:0;z-index:1;';
          // Pannellum 会把传入容器设为 position:relative，故放一个内层容器，外层保持绝对定位铺满 .pic
          const inner = document.createElement('div');
          inner.className = 'pp-pano-inner';
          inner.style.cssText = 'width:100%;height:100%;position:relative;';
          wrap.appendChild(inner);
          if (img) img.style.visibility = 'hidden'; // 保留盒子尺寸，让 Pannellum 填充
          pic.appendChild(wrap);
          let viewer;
          try {
            viewer = window.pannellum.viewer(inner, {
              type: 'equirectangular', panorama: url, autoLoad: true,
              showZoomCtrl: false, showFullscreenCtrl: false, showControls: false,
              compass: false, driftEnabled: false, autoRotate: 0
            });
          } catch (e) {
            if (img) img.style.visibility = '';
            if (wrap.parentNode) wrap.parentNode.removeChild(wrap);
            console.error('[InfinityTime pano] viewer init failed:', e && e.message);
            return;
          }
          // 跟随容器尺寸（poptrox 弹窗有放大动画，叠加 ResizeObserver 保证 Pannellum 画布尺寸正确）
          const ro = new ResizeObserver(function () {
            if (viewer && typeof viewer.setSize === 'function') {
              try { viewer.setSize(pic.clientWidth || 0, pic.clientHeight || 0); } catch (e) {}
            }
          });
          try { ro.observe(pic); } catch (e) {}
          setTimeout(function () {
            if (viewer && typeof viewer.setSize === 'function') {
              try { viewer.setSize(pic.clientWidth || 0, pic.clientHeight || 0); } catch (e) {}
            }
          }, 60);
          // 自定义全屏按钮（匹配站点圆角），隐藏 Pannellum 默认全屏控制。
          // 直接挂到 .pic 下（与 .pp-pano-viewer 平级），使其落在灯箱自身的堆叠上下文，
          // 用高 z-index 盖过 Pannellum 画布与 poptrox 的导航/关闭钮，避免被遮挡。
          const fsBtn = document.createElement('button');
          fsBtn.type = 'button';
          fsBtn.className = 'pp-pano-fullscreen';
          fsBtn.setAttribute('aria-label', '全屏 / 退出全屏');
          fsBtn.setAttribute('title', '全屏 / 退出全屏');
          // 内联兜底定位（不依赖外部 CSS），即使样式表没命中也能看到并点得到
          fsBtn.style.cssText = 'position:absolute;top:12px;right:12px;z-index:9999;width:44px;height:44px;'
            + 'border:2px solid rgba(255,255,255,.92);border-radius:50%;background:rgba(0,0,0,.48);'
            + 'color:#fff;cursor:pointer;display:flex;align-items:center;justify-content:center;'
            + '-webkit-backdrop-filter:blur(6px);backdrop-filter:blur(6px);padding:0;';
          fsBtn.innerHTML = '<svg viewBox="0 0 24 24" width="19" height="19" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" style="display:block"><path d="M4 9V4h5M20 9V4h-5M4 15v5h5M20 15v5h-5"/></svg>';
          pic.appendChild(fsBtn);
          // 移动端 Safari 不支持对 WebGL/普通元素做 element 级全屏（Pannellum 亦如此）。
          // 在不支持全屏的设备上隐藏全屏按钮，避免出现“点了没反应”的死按钮。
          if (!(document.fullscreenEnabled || (document.documentElement && document.documentElement.requestFullscreen))) {
            fsBtn.style.display = 'none';
          }
          // 退出全屏按钮：挂到 Pannellum 容器（inner 即 .pnlm-container，全屏时的顶层元素）内部，
          // 这样全屏时才不会被画布盖住。平时隐藏，仅在全屏态显示。
          const exitBtn = document.createElement('button');
          exitBtn.type = 'button';
          exitBtn.className = 'pp-pano-fullscreen-exit';
          exitBtn.setAttribute('aria-label', '退出全屏');
          exitBtn.setAttribute('title', '退出全屏');
          exitBtn.style.cssText = 'position:absolute;top:12px;right:12px;z-index:9999;width:44px;height:44px;'
            + 'border:2px solid rgba(255,255,255,.92);border-radius:50%;background:rgba(0,0,0,.48);'
            + 'color:#fff;cursor:pointer;display:none;align-items:center;justify-content:center;'
            + 'box-sizing:border-box;-webkit-backdrop-filter:blur(6px);backdrop-filter:blur(6px);padding:0;';
          exitBtn.innerHTML = '<svg viewBox="0 0 24 24" width="19" height="19" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" style="display:block"><path d="M4 4l5 5M20 4l-5 5M4 20l5-5M20 20l-5-5"/></svg>';
          inner.appendChild(exitBtn);
          const onFsChange = function () {
            fsBtn.classList.toggle('active', !!document.fullscreenElement);
            const fs = !!document.fullscreenElement;
            ppPanoFullscreen = fs;
            fsBtn.style.display = fs ? 'none' : 'flex';
            exitBtn.style.display = fs ? 'flex' : 'none';
            // 全屏时按视口撑满，否则按图片区尺寸
            try {
              if (viewer && typeof viewer.setSize === 'function') {
                viewer.setSize(fs ? window.innerWidth : (pic.clientWidth || 0), fs ? window.innerHeight : (pic.clientHeight || 0));
              }
            } catch (e) {}
          };
          document.addEventListener('fullscreenchange', onFsChange);
          // 用 Pannellum 原生全屏（公开方法是 toggleFullscreen；它会在全景容器上做 requestFullscreen，
          // Safari/iOS 均支持，效果与 Pannellum 自带的全屏按钮一致）。
          fsBtn.addEventListener('click', function (e) {
            e.stopPropagation(); // 不触发“点弹窗外部关闭”
            try {
              if (viewer && typeof viewer.toggleFullscreen === 'function') { viewer.toggleFullscreen(); }
              else if (document.fullscreenElement) { document.exitFullscreen(); }
              else { try { wrap.requestFullscreen(); } catch (e2) {} }
            } catch (err) {}
          });
          // 退出全屏：容器内 mousedown/pointerdown 停止冒泡，避免触发 Pannellum 的拖拽
          ['mousedown', 'pointerdown', 'touchstart'].forEach(function (ev) {
            exitBtn.addEventListener(ev, function (e) { e.stopPropagation(); });
          });
          exitBtn.addEventListener('click', function (e) {
            e.stopPropagation();
            try {
              if (viewer && typeof viewer.toggleFullscreen === 'function') { viewer.toggleFullscreen(); }
              else { document.exitFullscreen(); }
            } catch (err) { try { document.exitFullscreen(); } catch (e2) {} }
          });
          // 兜底诊断：挂载后打印按钮几何信息，便于排查“按钮不显示”
          setTimeout(function () {
            try {
              const r = fsBtn.getBoundingClientRect();
              const cs = getComputedStyle(fsBtn);
              console.info('[InfinityTime pano] fsBtn', {
                connected: fsBtn.isConnected,
                rect: [Math.round(r.x), Math.round(r.y), Math.round(r.width), Math.round(r.height)],
                display: cs.display, visibility: cs.visibility, opacity: cs.opacity, z: cs.zIndex
              });
            } catch (e) {}
          }, 500);
          // 防“视窗内拖拽、视窗外松手”误退出：document 捕获阶段按“拖拽后时间窗”拦截 click，无论松手在哪都不关闭；
          // 不拦 pointerup/mouseup，避免 Pannellum 拖拽结束不了（鼠标锁住）。仍需点弹窗外部空白可正常关闭。
          ppBindPanoGuard();
          ppPanoState = { popup: popup, url: url, viewer: viewer, wrap: wrap, img: img, ro: ro, fsBtn: fsBtn, exitBtn: exitBtn, onFsChange: onFsChange };
          ppSetPanoActive(popup, true);
        }
        function syncPano() {
          const overlay = document.querySelector('.poptrox-overlay');
          const vis = overlay && getComputedStyle(overlay).display !== 'none'
            && overlay.style.display !== 'none' && overlay.style.visibility !== 'hidden';
          if (!vis) { ppDestroyPano(); return; }
          const popup = currentPopupExif();
          if (!popup) { ppDestroyPano(); return; }
          const img = popup.querySelector('.pic img');
          if (!img || !img.complete || img.naturalWidth === 0) return; // 图片加载后再判定，保证 .pic 有盒子尺寸
          const article = findArtForPopup(popup) || activeArticle;
          if (!article) { ppDestroyPano(); return; }
          const d = articleData(article);
          const idx = currentImgIndex(popup, d);
          if (idx < 0) { ppDestroyPano(); return; }
          const isPano = !!(d.panos && d.panos[idx]);
          if (isPano) ppMountPano(popup, d.images[idx] || img.getAttribute('src'));
          else ppDestroyPano();
        }

        // ===== 灯箱 EXIF 侧栏（轮询驱动，单数据源） =====
        let activeArticle = null;
        // 当前正在展示图片的弹窗（可见且有主图）
        function currentPopupExif() {
          return Array.from(document.querySelectorAll('.poptrox-popup')).find(function(p) {
            if (p.classList.contains('loading')) return false;
            const img = p.querySelector('.pic img');
            if (!img || !img.getAttribute('src')) return false;
            const st = getComputedStyle(p);
            return st.display !== 'none' && st.visibility !== 'hidden' && p.getBoundingClientRect().width > 0;
          }) || null;
        }
        // 定位并显示侧栏：灯箱开着就显示，放到主图外侧。
        // 当主图太宽、左右都放不下侧栏时，收缩主图宽度，保证 EXIF 面板不遮挡照片。
        function positionDockExif(popupArg) {
          const dock = getExifDock();
          const overlay = document.querySelector('.poptrox-overlay');
          if (!overlay || getComputedStyle(overlay).display === 'none'
              || overlay.style.display === 'none' || overlay.style.visibility === 'hidden') {
            dock.classList.remove('show');
            document.body.classList.remove('exif-dock-open');
            return;
          }
          dock.classList.add('show');
          document.body.classList.add('exif-dock-open');
          const popup = popupArg || currentPopupExif();
          if (!popup) return;
          const vw = window.innerWidth;
          if (vw <= 900) return;
          const img = popup.querySelector('.pic img');
          const dw = dock.offsetWidth || 250;
          const gap = 16;
          const edge = 12;
          const need = dw + gap;
          let rect = popup.getBoundingClientRect();
          if (rect.width < 10) return;
          let left = rect.right + gap;
          // 右侧放不下再试左侧；两侧都放不下则收缩主图，为侧栏腾出空间
          if (left + dw > vw - edge) {
            const spaceLeft = rect.left - edge;
            if (spaceLeft >= need) {
              left = rect.left - dw - gap;
            } else {
              const available = Math.max(320, vw - 2 * (need + edge));
              if (img && img.style.maxWidth !== available + 'px') img.style.maxWidth = available + 'px';
              void (img && img.offsetWidth); // 强制回流，让居中重新计算
              rect = popup.getBoundingClientRect();
              left = rect.right + gap;
              if (left + dw > vw - edge) left = Math.max(edge, vw - dw - edge);
            }
          }
          if (left < edge) left = Math.max(edge, vw - dw - edge);
          dock.style.left = left + 'px';
          dock.style.top = Math.max(12, rect.top) + 'px';
        }
        // 根据当前显示的主图 src 反查所属相册并刷新侧栏
        function syncDockExif() {
          const overlay = document.querySelector('.poptrox-overlay');
          const vis = overlay && getComputedStyle(overlay).display !== 'none'
            && overlay.style.display !== 'none' && overlay.style.visibility !== 'hidden';
          if (!vis) { getExifDock().classList.remove('show'); return; }
          const popup = currentPopupExif();
          // 图片未加载完成前不出现侧栏（也不停留旧位置）
          if (!popup) { getExifDock().classList.remove('show'); return; }
          const img = popup.querySelector('.pic img');
          if (!img || !img.complete || img.naturalWidth === 0) { getExifDock().classList.remove('show'); return; }
          positionDockExif(popup);
          let article = findArtForPopup(popup) || activeArticle;
          if (!article) return;
          activeArticle = article;
          popup.__article = article;
          const d = articleData(article);
          if (!d) return;
          const idx = currentImgIndex(popup, d);
          if (idx >= 0 && (idx !== popup.__ppIndex || article !== popup.__ppArticle)) {
            popup.__ppIndex = idx;
            popup.__ppArticle = article;
            renderExif(popup, idx);
          }
        }
        // EXIF 侧栏：只在灯箱打开时运行轮询，关闭时停止，避免后台空转
        let exifTimer = null;
        let exifObserver = null;
        let exifObservedEl = null;
        function overlayVisible() {
          const overlay = document.querySelector('.poptrox-overlay');
          return !!(overlay && getComputedStyle(overlay).display !== 'none'
            && overlay.style.display !== 'none' && overlay.style.visibility !== 'hidden');
        }
        function applyExifState(vis) {
          document.body.classList.toggle('exif-dock-open', vis);
          if (vis) {
            if (document.body.style.overflow !== 'hidden') document.body.style.overflow = 'hidden';
          } else {
            if (document.body.style.overflow !== '') document.body.style.overflow = '';
            if (exifDock) { exifDock.classList.remove('show'); exifDock.classList.remove('expanded'); }
            ppDestroyPano();
          }
        }
        function startExifPoll() {
          if (exifTimer) return;
          exifTimer = setInterval(function() {
            try {
              const vis = overlayVisible();
              applyExifState(vis);
              if (vis) { syncDockExif(); syncPano(); }
            } catch (e) {} // 避免单个异常导致每 120ms 在控制台刷报错
          }, 120);
        }
        function stopExifPoll() {
          if (exifTimer) { clearInterval(exifTimer); exifTimer = null; }
        }
        // 监听灯箱 overlay 显隐（poptrox 会改写它的 style），按需开/停轮询
        function ensureExifObserver() {
          const overlay = document.querySelector('.poptrox-overlay');
          if (!overlay) return;
          if (exifObservedEl === overlay) return;
          if (exifObserver) exifObserver.disconnect();
          exifObservedEl = overlay;
          exifObserver = new MutationObserver(function() {
            const vis = overlayVisible();
            if (vis) { applyExifState(true); startExifPoll(); }
            else { applyExifState(false); stopExifPoll(); }
          });
          exifObserver.observe(overlay, { attributes: true, attributeFilter: ['style', 'class'] });
          if (overlayVisible()) startExifPoll();
        }
        // 记录点击的相册
        document.addEventListener('click', function(e) {
          const a = e.target.closest ? e.target.closest('a.image.my-photo') : null;
          if (a) {
            activeArticle = a.closest('.thumb');
            ensureExifObserver();
            // 打开瞬间就标记“需要预留 EXIF 侧栏宽度”，避免首图闪一下原尺寸再收缩
            document.body.classList.add('exif-dock-open');
            syncDockExif();
          }
        }, true);

      });
      </script>
  </div>
  <!-- Scripts -->
  <script src="<?php $this->options->themeUrl('assets/js/jquery.min.js'); ?>"></script>
  <script src="<?php $this->options->themeUrl('assets/js/jquery.poptrox.min.js'); ?>"></script>
  <script src="<?php $this->options->themeUrl('assets/js/browser.min.js'); ?>"></script>
  <script src="<?php $this->options->themeUrl('assets/js/breakpoints.min.js'); ?>"></script>
  <script src="<?php $this->options->themeUrl('assets/js/pannellum.js'); ?>"></script>
  <script src="<?php $this->options->themeUrl('assets/js/main.js?v=' . $__assetVer); ?>"></script>
</body>

</html>
