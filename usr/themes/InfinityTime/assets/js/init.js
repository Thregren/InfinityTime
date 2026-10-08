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
  function initImageFallback(scope) {
    (scope || document).querySelectorAll('a.image.my-photo img').forEach(function (img) {
      if (img.__ppFallbackBound) return;
      img.__ppFallbackBound = true;
      var fallback = img.getAttribute('data-fallback');
      var original = img.getAttribute('src');
      var originalSet = img.getAttribute('srcset');
      var article = img.closest('.thumb');
      var sources = Array.from((img.closest('picture') || document.createElement('picture')).querySelectorAll('source'))
        .map(function(source) { return { node: source, srcset: source.getAttribute('srcset') }; });
      var swapped = false, avifFallback = false, retry;
      function failed() {
        if (swapped) return;
        // 浏览器支持 AVIF 但文件损坏/缺失时，先尝试既有 WebP 候选。
        var avifSources = sources.filter(function(source) {
          var candidates = source.node.getAttribute('srcset');
          if (source.node.getAttribute('type') !== 'image/avif' || !candidates) return false;
          if (!img.currentSrc) return true;
          return candidates.split(',').some(function(candidate) {
            try { return new URL(candidate.trim().split(/\s+/)[0], document.baseURI).href === img.currentSrc; }
            catch (e) { return false; }
          });
        });
        if (!avifFallback && avifSources.length) {
          avifFallback = true;
          avifSources.forEach(function(source) { source.node.removeAttribute('srcset'); });
          return;
        }
        swapped = true;
        img.removeAttribute('srcset');
        sources.forEach(function(source) { source.node.removeAttribute('srcset'); });
        if (fallback && img.getAttribute('src') !== fallback) img.src = fallback;
        if (!article || retry) return;
        retry = document.createElement('button');
        retry.type = 'button';
        retry.className = 'pp-thumb-retry';
        retry.textContent = '缩略图加载失败 · 重试';
        retry.addEventListener('click', function(event) {
          event.preventDefault(); event.stopPropagation();
          swapped = false; avifFallback = false;
          retry.disabled = true;
          retry.textContent = '正在重试…';
          sources.forEach(function(source) { if (source.srcset) source.node.setAttribute('srcset', source.srcset); });
          if (originalSet) img.setAttribute('srcset', originalSet);
          img.src = original;
        });
        article.appendChild(retry);
      }
      img.addEventListener('load', function() {
        if (!swapped && retry) { retry.remove(); retry = null; }
      });
      img.addEventListener('error', function() {
        if (retry) { retry.disabled = false; retry.textContent = '缩略图加载失败 · 重试'; }
        failed();
      });
      if (img.complete && !img.naturalWidth) failed();
    });
  }

  /* ------------------------------------------------------------------
   * 3) 无限瀑布流
   * ------------------------------------------------------------------ */
  function initWaterfall() {
    var wf = document.getElementById('waterfall');
    var lm = document.getElementById('load-more');
    if (!wf || !lm) return;
    var pagerBase = lm.getAttribute('data-pager-base') || '';
    var curPage = parseInt(lm.getAttribute('data-page'), 10) || 1;
    var totPages = parseInt(lm.getAttribute('data-total-pages'), 10) || 1;
    var cursorMode = lm.hasAttribute('data-next-url');
    var nextUrl = cursorMode ? lm.getAttribute('data-next-url') : '';
    var loadingMore = false, failed = false, requestId = 0, controller = null, timeout = null;
    var status = document.createElement('span');
    status.className = 'pp-load-status';
    status.setAttribute('role', 'status');
    status.setAttribute('aria-live', 'polite');
    var button = document.createElement('button');
    button.type = 'button';
    button.className = 'pp-load-retry';
    button.textContent = '加载更多照片';
    lm.appendChild(status); lm.appendChild(button);
    function hasMore() { return cursorMode ? !!nextUrl : curPage < totPages && !!pagerBase; }
    function update() {
      lm.setAttribute('aria-busy', loadingMore ? 'true' : 'false');
      button.disabled = loadingMore;
      button.hidden = !hasMore();
      button.textContent = failed ? '重试加载' : (loadingMore ? '正在加载…' : '加载更多照片');
      status.textContent = failed ? '照片加载失败，请重试。' : (loadingMore ? '正在加载更多照片…' : '');
      lm.classList.toggle('pp-load-error', failed);
    }
    function cancel() {
      requestId++;
      if (controller) controller.abort();
      controller = null;
      clearTimeout(timeout);
      loadingMore = false;
      update();
    }
    function loadMore(manual) {
      if (loadingMore || !hasMore() || (failed && !manual)) return Promise.resolve(false);
      var id = ++requestId;
      var requestedUrl = cursorMode ? nextUrl : pagerBase + (curPage + 1);
      loadingMore = true; failed = false; update();
      controller = typeof AbortController !== 'undefined' ? new AbortController() : null;
      var options = { credentials: 'same-origin' };
      if (controller) options.signal = controller.signal;
      timeout = setTimeout(function() { if (id === requestId && controller) controller.abort(); }, 20000);
      return fetch(requestedUrl, options)
        .then(function(response) {
          if (!response.ok) throw new Error('HTTP ' + response.status);
          return response.text();
        })
        .then(function(html) {
          if (id !== requestId || !wf.isConnected) return false;
          var doc = new DOMParser().parseFromString(html, 'text/html');
          var source = doc.getElementById('waterfall');
          var cards = source ? Array.from(source.querySelectorAll(':scope > .thumb')) : [];
          var newPager = doc.getElementById('load-more');
          if (!source || (cursorMode && !newPager) || (!cursorMode && !cards.length)) throw new Error('图册分页内容无效');
          var next = cursorMode ? (newPager.getAttribute('data-next-url') || '') : '';
          if (next && new URL(next, location.href).href === new URL(requestedUrl, location.href).href) throw new Error('图册分页游标未推进');
          Array.from(source.children).forEach(function(node) {
            if (!node.matches('.thumb, .gallery-month-label')) return;
            if (node.id && document.getElementById(node.id)) node.removeAttribute('id');
            wf.appendChild(node);
          });
          curPage++;
          lm.setAttribute('data-page', String(curPage));
          if (cursorMode) { nextUrl = next; lm.setAttribute('data-next-url', next); }
          var pagination = document.getElementById('gallery-pagination');
          var nextLink = pagination && pagination.querySelector('a[rel="next"]');
          if (nextLink) {
            if (next) nextLink.setAttribute('href', next);
            else nextLink.remove();
          }
          applyDims(wf);
          initImageFallback(wf);
          if (typeof window.__rebindPoptrox === 'function') window.__rebindPoptrox();
          document.dispatchEvent(new CustomEvent('infinitygallery:append', { detail: { count: cards.length, page: curPage, nextUrl: nextUrl } }));
          return true;
        })
        .catch(function() { if (id === requestId) failed = true; return false; })
        .then(function(result) {
          if (id === requestId) { clearTimeout(timeout); controller = null; loadingMore = false; update(); }
          return result;
        });
    }
    button.addEventListener('click', function() { loadMore(true); });
    window.addEventListener('scroll', function() {
      if (window.innerHeight + window.scrollY >= document.documentElement.offsetHeight - 600) loadMore(false);
    }, { passive: true });
    window.addEventListener('pagehide', cancel);
    window.InfinityWaterfall = {
      loadMore: function() { return loadMore(true); },
      cancel: cancel,
      reset: function(options) {
        cancel(); options = options || {};
        curPage = options.page || 1; totPages = options.totalPages || 1;
        pagerBase = options.pagerBase || '';
        cursorMode = Object.prototype.hasOwnProperty.call(options, 'nextUrl');
        nextUrl = options.nextUrl || ''; failed = false; update();
      }
    };
    update();
    if (document.documentElement.offsetHeight <= window.innerHeight + 600) loadMore(false);
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

