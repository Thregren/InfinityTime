/*!
 * InfinityTime 主题初始化脚本
 *
 * 原先内联写在 index.php 里。为了让 nginx 的 CSP 能去掉 script-src 'unsafe-inline'，
 * 整块外链到本文件。执行时机与原来一致：同步执行，在 jQuery / main.js 之前。
 */
(function () {
  'use strict';

  /* ------------------------------------------------------------------
   * 1) 缩略图占位（aspect-ratio）与骨架屏
   * ------------------------------------------------------------------ */
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

  /* ------------------------------------------------------------------
   * 2) 缩略图加载失败时换成占位图（替代原来的 onerror 内联属性）
   * ------------------------------------------------------------------ */
  function initImageFallback() {
    document.querySelectorAll('img.my-photo[data-fallback]').forEach(function (img) {
      var fallback = img.getAttribute('data-fallback');
      if (!fallback) return;
      var swapped = false;
      var swap = function () {
        if (swapped) return;
        swapped = true;
        img.removeEventListener('error', swap);
        img.src = fallback;
      };
      // 本脚本执行时图片可能已经加载失败过（监听来不及绑上），这里补判一次
      if (img.complete && img.naturalWidth === 0) { swap(); return; }
      img.addEventListener('error', swap, { once: true });
    });
  }

  /* ------------------------------------------------------------------
   * 3) 无限瀑布流
   * ------------------------------------------------------------------ */
  function initWaterfall() {
    var wf = document.getElementById('waterfall');
    if (!wf) return;
    // 列布局交给 CSS column 实现，DOM 保持源码顺序（灯箱 poptrox 因此按源码顺序切图）。
    // 无限瀑布流：滚动到底自动加载下一页并追加到容器。
    var lm = document.getElementById('load-more');
    // 分页基址由模板写在 data-pager-base 上（原先由 PHP 内联注入）
    var PAGER_BASE = lm ? (lm.getAttribute('data-pager-base') || '') : '';
    var curPage = lm ? (parseInt(lm.getAttribute('data-page'), 10) || 1) : 1;
    var totPages = lm ? (parseInt(lm.getAttribute('data-total-pages'), 10) || 1) : 1;
    var loadingMore = false;

    function loadMore() {
      if (!lm || loadingMore || curPage >= totPages || !PAGER_BASE) return;
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
  }

  /* ------------------------------------------------------------------
   * 4) 为灯箱控制按钮补无障碍 aria-label / title（poptrox 生成的弹窗）
   * ------------------------------------------------------------------ */
  function initLightboxA11y() {
    var labels = { '.closer': '关闭', '.nav-previous': '上一张', '.nav-next': '下一张' };
    function labelPopup(popup) {
      Object.keys(labels).forEach(function (sel) {
        var el = popup.querySelector(sel);
        if (el) {
          if (!el.getAttribute('aria-label')) el.setAttribute('aria-label', labels[sel]);
          if (!el.getAttribute('title')) el.setAttribute('title', labels[sel]);
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
  }

  applyDims(document);
  initImageFallback();
  initWaterfall();
  initLightboxA11y();

  // 无限瀑布流翻页后要重新给新卡片占位，暴露给其它脚本调用。
  window.applyDims = applyDims;
})();
