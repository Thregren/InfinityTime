<?php
/**
 * 一款简约的相册主题
 * @package 无限时光
 * @author InfinityTime
 * @version 1.4.0
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
  <link rel="stylesheet" type="text/css" href="<?php $this->options->themeUrl('assets/css/noscript.css'); ?>" />
  <noscript>
    <link rel="stylesheet" href="<?php $this->options->themeUrl('assets/css/noscript.css'); ?>" />
  </noscript>
  <link rel="stylesheet" href="<?php $this->options->themeUrl('assets/css/main.css'); ?>" />
  <link rel="stylesheet" href="<?php $this->options->themeUrl('assets/css/iconfont.css'); ?>">
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


        <li><a type="button" id="fullscreen" class="btn btn-default visible-lg visible-md" alt="切换全屏">
          <i class="iconfont icon-quanping"></i>
              <use xlink:href="#icon-zmki-ziyuan-copy"></use>
            </svg></a></li>
        <li><a href="#footer">关于</a></li>
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
          if (!is_array($exifList)) { $exifList = []; }
          if (!is_array($addrList)) { $addrList = []; }
          if (!is_array($imgTitles)) { $imgTitles = []; }
          if (!is_array($imgDescs)) { $imgDescs = []; }
          $exif0 = $exifList[0] ?? [];
          $addr0 = $addrList[0] ?? ($this->fields->location ? $this->fields->location : '');
          ?>
          <a class="image my-photo" alt="loading" href="<?php echo $firstImage; ?>"
             data-images='<?php echo json_encode($images, JSON_UNESCAPED_UNICODE); ?>'
             data-exif='<?php echo json_encode($exifList, JSON_UNESCAPED_UNICODE); ?>'
             data-addresses='<?php echo json_encode($addrList, JSON_UNESCAPED_UNICODE); ?>'
             data-titles='<?php echo json_encode($imgTitles, JSON_UNESCAPED_UNICODE); ?>'
             data-descs='<?php echo json_encode($imgDescs, JSON_UNESCAPED_UNICODE); ?>'>
            <img class="zmki_px my-photo"
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
      
      <!-- 分页导航 -->
      <?php
        $total = ceil($this->getTotal() / $this->parameter->pageSize);
        if($total > 1):
      ?>
      <div class="pagination-container">
        <?php 
          $current = $this->_currentPage;
          $max_pages = 6; // 最多显示的页码数
          
          // 计算显示的页码范围
          $start = max(1, min($current - floor($max_pages/2), $total - $max_pages + 1));
          $end = min($start + $max_pages - 1, $total);
          
          // 获取当前分类路径
          $category = '';
          if ($this->is('category')) {
            $category = $this->getArchiveSlug();
          }
          
          // 上一页按钮
          if ($current > 1): 
            $prevUrl = $category ? $this->options->siteUrl . 'index.php/category/' . $category . '/' . ($current-1) . '/' : $this->options->siteUrl . 'index.php/page/' . ($current-1);
            echo '<a href="' . $prevUrl . '" class="page-btn prev-btn">上一页</a>';
          endif;

          // 页码按钮
          for ($i = $start; $i <= $end; $i++):
            if ($i == $current): ?>
              <span class="page-btn current"><?php echo $i; ?></span>
            <?php else: 
              $pageUrl = $category ? $this->options->siteUrl . 'index.php/category/' . $category . '/' . $i . '/' : $this->options->siteUrl . 'index.php/page/' . $i;
            ?>
              <a href="<?php echo $pageUrl; ?>" class="page-btn"><?php echo $i; ?></a>
            <?php endif;
          endfor;

          // 下一页按钮
          if ($current < $total): 
            $nextUrl = $category ? $this->options->siteUrl . 'index.php/category/' . $category . '/' . ($current+1) . '/' : $this->options->siteUrl . 'index.php/page/' . ($current+1);
            echo '<a href="' . $nextUrl . '" class="page-btn next-btn">下一页</a>';
          endif; ?>
      </div>
      <?php endif; ?>

      <!-- 原有的 load-more div -->
      <div id="load-more" data-page="1" data-total-pages="<?php echo $total; ?>"></div>
    </div>

    <body>
      <!-- Footer -->
      <footer id="footer" class="panel">
            <div id="about">
              <section>
                <h2>关于<?php echo pp_opt('infinitytimeSiteName', (string)$this->options->IndexName, $this->options); ?></h2>
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
                </div>
      </footer>
      <script type="text/javascript">
        function isInSight(el) {
          const bound = el.getBoundingClientRect();
          const clientHeight = window.innerHeight;
          //如果只考虑向下滚动加载
          //const clientWidth=window.innerWeight;
          return bound.top <= clientHeight + 100;
        }

        let index = 0;
        function checkImgs() {
          const imgs = document.querySelectorAll('.my-photo');
          for (let i = index; i < imgs.length; i++) {
            if (isInSight(imgs[i])) {
              loadImg(imgs[i]);
              index = i;
            }
          }
          // Array.from(imgs).forEach(el => {
          //   if (isInSight(el)) {
          //     loadImg(el);
          //   }
          // })
        }

        function loadImg(el) {
          if (!el.src) {
            const source = el.dataset.src;
            el.src = source;
          }
        }

        function throttle(fn, mustRun = 10) {
          const timer = null;
          let previous = null;
          return function () {
            const now = new Date();
            const context = this;
            const args = arguments;
            if (!previous) {
              previous = now;
            }
            const remaining = now - previous;
            if (mustRun && remaining >= mustRun) {
              fn.apply(context, args);
              previous = now;
            }
          }
        }
      </script>
      <script>
        window.onload = checkImgs;
        window.onscroll = throttle(checkImgs);
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
          const d = { images: [], exif: [], addr: [], titles: [], descs: [] };
          try { d.images = JSON.parse(src.dataset.images || '[]'); } catch (e) {}
          try { d.exif = JSON.parse(src.dataset.exif || '[]'); } catch (e) {}
          try { d.addr = JSON.parse(src.dataset.addresses || '[]'); } catch (e) {}
          try { d.titles = JSON.parse(src.dataset.titles || '[]'); } catch (e) {}
          try { d.descs = JSON.parse(src.dataset.descs || '[]'); } catch (e) {}
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
          }
        }
        function startExifPoll() {
          if (exifTimer) return;
          exifTimer = setInterval(function() {
            const vis = overlayVisible();
            applyExifState(vis);
            if (vis) syncDockExif();
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
  <script src="<?php $this->options->themeUrl('assets/js/util.js'); ?>"></script>
  <script src="<?php $this->options->themeUrl('assets/js/main.js'); ?>"></script>
</body>

</html>
