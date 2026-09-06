<?php
/**
 * 一款简约的相册主题
 * @package 无限时光
 * @author InfinityTime
 * @version 1.6.0
 * @link https://github.com/InfinityTime/InfinityTime
 */
?>
<!DOCTYPE html>
<html>

<head>
  <title><?php echo pp_opt('infinitytimeSiteName', (string)$this->options->IndexName, $this->options); ?> - <?php echo pp_opt('infinitytimeSiteTagline', (string)$this->options->Indexdict, $this->options); ?> </title>
  <meta http-equiv="content-type" content="text/html; charset=<?php $this->options->charset(); ?>" />
  <meta name="viewport" content="width=device-width, initial-scale=1, user-scalable=no" />
  <meta name="keywords" content="<?php $this->options->keywords(); ?>" />
  <meta name="description" content="<?php $this->options->description(); ?>" />
  <link rel="apple-touch-icon" href="<?php $this->options->AppleIcon(); ?>">
  <meta name="apple-mobile-web-app-title" content="<?php echo pp_opt('infinitytimeSiteName', (string)$this->options->IndexName, $this->options); ?>">
  <link rel="bookmark" href="<?php $this->options->AppleIcon(); ?>">
  <link rel="apple-touch-icon-precomposed" sizes="180x180" href="<?php $this->options->AppleIcon(); ?>">
  <link rel="icon" href="<?php echo pp_opt('infinitytimeSiteLogo', (string)$this->options->IconUrl, $this->options); ?>">
  <link rel="stylesheet" type="text/css" href="<?php $this->options->themeUrl('assets/css/main.css'); ?>" />
  <link rel="stylesheet" href="<?php $this->options->themeUrl('assets/css/iconfont.css'); ?>">
  <link rel="stylesheet" type="text/css" href="<?php $this->options->themeUrl('assets/css/pannellum.css'); ?>" />
  <noscript>
    <link rel="stylesheet" href="<?php $this->options->themeUrl('assets/css/noscript.css'); ?>" />
  </noscript>
</head>

<body class="is-preload">
  <header id="header">
    <a href="#footer" class="pp-site-toggle" title="关于">
      <img class="site-logo" src="<?php echo pp_opt('infinitytimeSiteLogo', (string)$this->options->IconUrl, $this->options); ?>">
      <h1><strong><?php echo pp_opt('infinitytimeSiteName', (string)$this->options->zmkiabout, $this->options); ?></strong></h1>
      <span class="discription"><?php echo pp_opt('infinitytimeSiteTagline', (string)$this->options->zmkiabouts, $this->options); ?></span>
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
          if (!is_array($exifList)) { $exifList = []; }
          if (!is_array($addrList)) { $addrList = []; }
          if (!is_array($imgTitles)) { $imgTitles = []; }
          if (!is_array($imgDescs)) { $imgDescs = []; }
          if (!is_array($panoList)) { $panoList = []; }
          // 去掉 null/空 字段，压缩内嵌 JSON；前端对缺失字段同样按“无”处理，展示不受影响。
          $exifList = array_map(function ($e) {
              return is_array($e) ? array_filter($e, function ($v) { return $v !== null && $v !== ''; }) : $e;
          }, $exifList);
          $exif0 = $exifList[0] ?? [];
          $addr0 = $addrList[0] ?? ($this->fields->location ? $this->fields->location : '');
          ?>
          <a class="image my-photo" aria-label="<?php echo htmlspecialchars($this->title()); ?>" href="<?php echo $firstImage; ?>"
             data-images='<?php echo json_encode($images, JSON_UNESCAPED_UNICODE); ?>'
             data-previews='<?php echo json_encode($thumbs ?: $images ?: [], JSON_UNESCAPED_UNICODE); ?>'
             data-exif='<?php echo json_encode($exifList, JSON_UNESCAPED_UNICODE); ?>'
             data-addresses='<?php echo json_encode($addrList, JSON_UNESCAPED_UNICODE); ?>'
             data-titles='<?php echo json_encode($imgTitles, JSON_UNESCAPED_UNICODE); ?>'
             data-descs='<?php echo json_encode($imgDescs, JSON_UNESCAPED_UNICODE); ?>'
             data-panos='<?php echo json_encode($panoList, JSON_UNESCAPED_UNICODE); ?>'>
            <img class="zmki_px my-photo"
              alt="<?php echo htmlspecialchars($this->title()); ?>"
              src="<?php echo $firstThumb; ?>"
              loading="lazy" decoding="async"
              onerror="this.src='<?php $this->options->themeUrl('assets/img/loading.gif'); ?>';this.onerror=null"
              data-src="<?php echo $firstThumb; ?>" />
          </a>
          <h2><?php $this->title() ?></h2>
          <?php if($this->content): ?>
          <div class="content-wrapper">
            <p><?php $this->content('内容加载中...'); ?></p>
          </div>
          <?php endif; ?>
          <li class="tag-info tag-info-bottom">
            <?php if($this->fields->device): ?>
            <span class="tag-device"><i class="iconfont icon-camera-lens-line"></i><?php echo $this->fields->device(); ?></span>
            <?php endif; ?>
            <?php if($this->fields->location): ?>
            <span class="tag-location"><i class="iconfont icon-map-pin-2-line"></i><?php echo $this->fields->location(); ?></span>
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
              <?php if(!empty($exif0['fnumber'])): ?><div class="exif-item"><span>光圈</span><b>f/<?php echo $exif0['fnumber']; ?></b></div><?php endif; ?>
              <?php if(!empty($exif0['exposure'])): ?><div class="exif-item"><span>快门</span><b><?php echo htmlspecialchars($exif0['exposure']); ?></b></div><?php endif; ?>
              <?php $focalShow = $exif0['focal35'] ?? $exif0['focal'] ?? ''; ?>
              <?php if(!empty($focalShow)): ?><div class="exif-item"><span>焦距</span><b><?php echo $focalShow; ?>mm</b></div><?php endif; ?>
              <?php if(!empty($exif0['flash'])): ?><div class="exif-item"><span>闪光</span><b>是</b></div><?php endif; ?>
              <?php if(!empty($exif0['datetime'])): ?><div class="exif-item"><span>时间</span><b><?php echo htmlspecialchars(pp_date_cn((string)$exif0['datetime'])); ?></b></div><?php endif; ?>
            </div>
            <div class="exif-addr"><i class="iconfont icon-map-pin-2-line"></i><span class="exif-addr-text"><?php echo htmlspecialchars($addr0 ?: ''); ?></span></div>
          </div>
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
        function isInSight(el) {
          const bound = el.getBoundingClientRect();
          return bound.top <= window.innerHeight + 100;
        }
        function loadImg(el) {
          if (!el.src && el.dataset.src) {
            el.src = el.dataset.src;
          }
        }
        function checkImgs() {
          // 每次全量检查：已加载的图片（有 src）会被 loadImg 跳过，避免 index 跳过导致的漏加载
          document.querySelectorAll('.my-photo').forEach(function (el) {
            if (isInSight(el)) loadImg(el);
          });
        }
        function throttle(fn, mustRun = 16) {
          var last = 0;
          return function () {
            var now = Date.now();
            if (now - last >= mustRun) {
              last = now;
              fn.apply(this, arguments);
            }
          };
        }
      </script>
      <script>
        window.addEventListener('load', checkImgs);
        window.addEventListener('scroll', throttle(checkImgs), { passive: true });
      </script>
      <script>
      // 瀑布流：按响应式列数把卡片分配到弹性列，保证首行完全顶对齐
      (function () {
        var wf = document.getElementById('waterfall');
        if (!wf) return;
        function colCount() {
          var w = window.innerWidth;
          if (w >= 1300) return 4;
          if (w >= 900) return 3;
          return 2;
        }
        function build() {
          var cards = Array.prototype.slice.call(wf.querySelectorAll('.thumb'));
          wf.querySelectorAll('.wf-col').forEach(function (c) { c.remove(); });
          if (!cards.length) return; // 无卡片则不创建空列
          var N = Math.max(1, colCount());
          var cols = [];
          for (var i = 0; i < N; i++) {
            var col = document.createElement('div');
            col.className = 'wf-col';
            wf.appendChild(col);
            cols.push(col);
          }
          var idx = 0;
          cards.forEach(function (card) { cols[idx++ % N].appendChild(card); });
        }
        build();
        if (typeof checkImgs === 'function') checkImgs(); // 重建后立即加载首屏可见图片
        var rt;
        window.addEventListener('resize', function () {
          clearTimeout(rt);
          rt = setTimeout(build, 120);
        });
        // 无限瀑布流：滚动到底自动加载下一页并追加到列
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
                  var N = Math.max(1, colCount());
                  var cols = wf.querySelectorAll('.wf-col');
                  if (!cols.length) { build(); cols = wf.querySelectorAll('.wf-col'); }
                  var idx = wf.querySelectorAll('.thumb').length % N;
                  cards.forEach(function (card) { cols[idx++ % N].appendChild(card); });
                  curPage += 1;
                  if (lm) lm.setAttribute('data-page', String(curPage));
                  if (typeof checkImgs === 'function') checkImgs();
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
            exifDock.innerHTML = '<div class="exif-imgtitle"></div>'
              + '<div class="exif-imgdesc"></div>'
              + '<div class="exif-title">拍摄参数</div>'
              + '<div class="exif-grid"></div>'
              + '<div class="exif-addr"><i class="iconfont icon-map-pin-2-line"></i><span class="exif-addr-text"></span></div>';
            document.body.appendChild(exifDock);
          }
          return exifDock;
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
        let ppPanoDragActive = false;
        // 点击捕获：刚在全景内按下/拖拽过，则吞掉紧随其后的 click（无论松手在哪），避免误关
        function ppPanoClickGuard(e) {
          if (ppPanoDragActive) { e.stopPropagation(); e.preventDefault(); ppPanoDragActive = false; }
        }
        // 按压捕获：每次新的按下先清标记，若落在全景容器内则标记为全景交互
        function ppPanoDownGuard(e) {
          ppPanoDragActive = false;
          try { if (e.target && e.target.closest && e.target.closest('.pp-pano-viewer')) ppPanoDragActive = true; } catch (err) {}
        }
        function ppBindPanoGuard() {
          if (ppPanoGuardBound) return;
          document.addEventListener('click', ppPanoClickGuard, true);
          document.addEventListener('pointerdown', ppPanoDownGuard, true);
          ppPanoGuardBound = true;
        }
        function ppSetPanoActive(popup, v) {
          if (popup) popup.__panoActive = !!v;
          document.querySelectorAll('.poptrox-popup').forEach(function (p) { if (p !== popup) p.__panoActive = false; });
        }
        function ppDestroyPano() {
          if (ppPanoState) {
            try { if (ppPanoState.viewer && ppPanoState.viewer.destroy) ppPanoState.viewer.destroy(); } catch (e) {}
            try { if (ppPanoState.ro && ppPanoState.ro.disconnect) ppPanoState.ro.disconnect(); } catch (e) {}
            if (ppPanoState.wrap && ppPanoState.wrap.parentNode) ppPanoState.wrap.parentNode.removeChild(ppPanoState.wrap);
            if (ppPanoState.img) ppPanoState.img.style.visibility = '';
            ppPanoState = null;
          }
          ppSetPanoActive(null, false);
        }
        function ppMountPano(popup, url) {
          if (ppPanoState && ppPanoState.popup === popup && ppPanoState.url === url && ppPanoState.viewer) return;
          ppDestroyPano();
          if (typeof window.pannellum === 'undefined') return; // 库未加载（异常兜底）
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
              showZoomCtrl: false, showFullscreenCtrl: false, compass: false, driftEnabled: false, autoRotate: 0
            });
          } catch (e) {
            if (img) img.style.visibility = '';
            if (wrap.parentNode) wrap.parentNode.removeChild(wrap);
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
          // 防“视窗内拖拽、视窗外松手”误退出：document 捕获阶段按“拖拽后时间窗”拦截 click，无论松手在哪都不关闭；
          // 不拦 pointerup/mouseup，避免 Pannellum 拖拽结束不了（鼠标锁住）。仍需点弹窗外部空白可正常关闭。
          ppBindPanoGuard();
          ppPanoState = { popup: popup, url: url, viewer: viewer, wrap: wrap, img: img, ro: ro };
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
            if (exifDock) exifDock.classList.remove('show');
            ppDestroyPano();
          }
        }
        function startExifPoll() {
          if (exifTimer) return;
          exifTimer = setInterval(function() {
            const vis = overlayVisible();
            applyExifState(vis);
            if (vis) { syncDockExif(); syncPano(); }
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
  <script src="<?php $this->options->themeUrl('assets/js/main.js'); ?>"></script>
</body>

</html>
