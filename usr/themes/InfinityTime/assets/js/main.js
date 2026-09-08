/*
	Multiverse by HTML5 UP
	html5up.net | @ajlkn
	Free for personal and commercial use under the CCA 3.0 license (html5up.net/license)
*/

(function($) {

	var	$window = $(window),
		$body = $('body'),
		$wrapper = $('#wrapper');

	// Breakpoints.
		breakpoints({
			xlarge:  [ '1281px',  '1680px' ],
			large:   [ '981px',   '1280px' ],
			medium:  [ '737px',   '980px'  ],
			small:   [ '481px',   '736px'  ],
			xsmall:  [ null,      '480px'  ]
		});

	// Hack: Enable IE workarounds.
		if (browser.name == 'ie')
			$body.addClass('ie');

	// Touch?
		if (browser.mobile)
			$body.addClass('touch');

	// Transitions supported?
		if (browser.canUse('transition')) {

			// Play initial animations on page load.
				$window.on('load', function() {
					window.setTimeout(function() {
						$body.removeClass('is-preload');
					}, 100);
				});

			// Prevent transitions/animations on resize.
				var resizeTimeout;

				function debounce(func, wait) {
					let timeout;
					return function() {
						const context = this;
						const args = arguments;
						clearTimeout(timeout);
						timeout = setTimeout(() => func.apply(context, args), wait);
					};
				}

				$window.on('resize', debounce(function() {
					$body.addClass('is-resizing');
					setTimeout(function() {
						$body.removeClass('is-resizing');
					}, 100);
				}, 250));

		}

	// Scroll back to top.
		$window.scrollTop(0);

	// Panels.
		var $panels = $('.panel');

		$panels.each(function() {

			var $this = $(this),
				$toggles = $('[href="#' + $this.attr('id') + '"]'),
				$closer = $('<div class="closer" />').appendTo($this);

			// Closer.
				$closer
					.on('click', function(event) {
						$this.trigger('---hide');
					});

			// Events.
				$this
					.on('click', function(event) {
						event.stopPropagation();
					})
					.on('---toggle', function() {

						if ($this.hasClass('active'))
							$this.triggerHandler('---hide');
						else
							$this.triggerHandler('---show');

					})
					.on('---show', function() {

						// Hide other content.
							if ($body.hasClass('content-active'))
								$panels.trigger('---hide');

						// Activate content, toggles.
							$this.addClass('active');
							$toggles.addClass('active');

						// Activate body.
							$body.addClass('content-active');

					})
					.on('---hide', function() {

						// Deactivate content, toggles.
							$this.removeClass('active');
							$toggles.removeClass('active');

						// Deactivate body.
							$body.removeClass('content-active');

					});

			// Toggles.
				$toggles
					.removeAttr('href')
					.css('cursor', 'pointer')
					.on('click', function(event) {

						event.preventDefault();
						event.stopPropagation();

						$this.trigger('---toggle');

					});

		});

		// Global events.
			$body
				.on('click', function(event) {

					if ($body.hasClass('content-active')) {

						event.preventDefault();
						event.stopPropagation();

						$panels.trigger('---hide');

					}

				});

			$window
				.on('keyup', function(event) {

					if (event.keyCode == 27
					&&	$body.hasClass('content-active')) {

						event.preventDefault();
						event.stopPropagation();

						$panels.trigger('---hide');

					}

				});

	// Header.
		var $header = $('#header');

		// Links.
			$header.find('a').each(function() {

				var $this = $(this),
					href = $this.attr('href');

				// Internal link? Skip.
					if (!href
					||	href.charAt(0) == '#')
						return;

				// Redirect on click.
					$this
						.removeAttr('href')
						.css('cursor', 'pointer')
						.on('click', function(event) {

							event.preventDefault();
							event.stopPropagation();

							window.location.href = href;

						});

			});

	// Footer.
		var $footer = $('#footer');

		// Copyright.
		// This basically just moves the copyright line to the end of the *last* sibling of its current parent
		// when the "medium" breakpoint activates, and moves it back when it deactivates.
			$footer.find('.copyright').each(function() {

				var $this = $(this),
					$parent = $this.parent(),
					$lastParent = $parent.parent().children().last();

				breakpoints.on('<=medium', function() {
					$this.appendTo($lastParent);
				});

				breakpoints.on('>medium', function() {
					$this.appendTo($parent);
				});

			});

	// Main.
		var $main = $('#main');

		// Thumbs.
			$main.children('.thumb').each(function() {

				var $this = $(this),
					$image = $this.find('.image'), 
					$image_img = $image.children('img');

				// 如果没有图片则返回
				if ($image.length === 0) return;

				// 使用 loading="lazy" 属性实现懒加载
				$image_img
					.attr('loading', 'lazy')
					.css('display', 'block') // 确保图片显示
					.on('load', function() {
						// 图片加载完成后的处理
						$(this).css('opacity', '1');
					});

				// 如果有背景位置数据，设置它
				var position = $image_img.data('position');
				if (position) {
					$image.css('background-position', position);
				}

			});

})(jQuery);

// 安全解析 JSON 数组：非法/缺失时回退为空数组，避免一个脏 data-* 弄瘫整站灯箱
function ppParseArr(s) { try { var v = JSON.parse(s || '[]'); return Array.isArray(v) ? v : []; } catch (e) { return []; } }

// 优化全屏切换功能
const fullscreenAPI = {
	enter: document.documentElement.requestFullscreen ||
		   document.documentElement.mozRequestFullScreen ||
		   document.documentElement.webkitRequestFullScreen ||
		   document.documentElement.msRequestFullscreen,
	exit: document.exitFullscreen ||
		  document.mozCancelFullScreen ||
		  document.webkitCancelFullScreen ||
		  document.msExitFullscreen
};

function toggleFullscreen() {
	const isFullscreen = document.fullscreenElement ||
						document.mozFullScreenElement ||
						document.webkitFullscreenElement ||
						document.msFullscreenElement;
	
	if (!isFullscreen) {
		$("#fullscreen").html("退出全屏");
		fullscreenAPI.enter.call(document.documentElement);
	} else {
		$("#fullscreen").html('<i class="iconfont icon-quanping"></i><use xlink:href="#icon-zmki-ziyuan-copy"></use></svg>');
		fullscreenAPI.exit.call(document);
	}
}

// 简化全屏切换事件监听
$('#fullscreen').on('click', toggleFullscreen);

// 为分页按钮添加动画效果
$(document).ready(function() {
    $('.next-page-btn').hover(
        function() {
            $(this).addClass('btn-hover');
        },
        function() {
            $(this).removeClass('btn-hover');
        }
    );
});

// 灯箱交互（触摸滚动锁定、EXIF 侧栏、切图）
document.addEventListener('DOMContentLoaded', function() {
    let isPopupActive = false;
    const $main = $('#main');
    const $body = $('body');

    // 监听弹窗状态（配置复用：无限瀑布流翻页后需重绑一次）
    const PP_CONFIG = {
        baseZIndex: 20000,
        caption: function($a) { return $a.next('h2').text().trim(); },
        fadeSpeed: 420,
        onPopupClose: function() { 
            isPopupActive = false;
            $body.removeClass('modal-active');
            document.querySelectorAll('.pic-swipe-wrapper').forEach(function(w) { w.remove(); });
            $('html, body').css({
                'overflow': '',
                'position': '',
                'height': '',
                'width': ''
            });
            // 清理本项目挂在弹窗上的临时状态，避免关闭后再开残留锁/全景态
            document.querySelectorAll('.poptrox-popup').forEach(function(p) {
                clearLqip(p);
                p.classList.remove('pp-pano-mode');
                delete p.__switching;
                delete p.__panoActive;
                delete p.__ppIndex;
                delete p.__ppArticle;
            });
        },
        onPopupOpen: function() { 
            isPopupActive = true;
            $body.addClass('modal-active');
            // 移动端用原始宽高预置弹窗尺寸，避免首次打开时先闪一个 150×150 的小方块
            // （桌面端有 EXIF 侧栏让位逻辑，尺寸交给 poptrox 自己算，避免预设偏宽再回缩）
            try {
                var popup = document.querySelector('.poptrox-popup');
                var dims = window.__ppOpenDims;
                if (popup && dims && dims[0] > 0 && dims[1] > 0 && window.innerWidth <= 900) {
                    // 用 poptrox 实例的实时边距（窄屏时会被 breakpoints 改成 0），
                    // 不能用 PP_CONFIG.windowMargin——它还是初始的 50，会把宽度算小 100px。
                    var inst = $main[0] && $main[0]._poptrox;
                    var margin = inst ? inst.windowMargin : PP_CONFIG.windowMargin;
                    var availW = Math.max(120, window.innerWidth - 2 * margin);
                    var availH = Math.max(120, window.innerHeight - 2 * margin);
                    var k = Math.min(1, availW / dims[0], availH / dims[1]);
                    $(popup).data('width', Math.round(dims[0] * k)).data('height', Math.round(dims[1] * k));
                }
            } catch (e) {}
            // 灯箱渐进加载：先铺缩略图模糊预览，全图加载后渐入
            setTimeout(function() {
                var p = document.querySelector('.poptrox-popup');
                if (p) applyLqip(p);
            }, 60);
        },
        overlayOpacity: 0,
        popupCloserText: '',
        popupHeight: 150,
        popupLoaderText: '',
        popupSpeed: 420,
        popupWidth: 150,
        selector: '.thumb > a.image',
        usePopupCaption: false,
        usePopupCloser: true,
        usePopupDefaultStyling: false,
        usePopupForceClose: true,
        usePopupLoader: true,
        usePopupNav: true,
        windowMargin: 50
    };
    // 灯箱淡入淡出改用柔和缓动（easeInOutQuad），替代 jQuery 默认的 swing 正弦式。
    // poptrox 的 fadeTo/fadeIn/fadeOut 以及弹窗尺寸过渡都会应用这条曲线；
    // 比 easeInOutCubic 更缓（中段速度峰值大幅降低），开/关不会有生硬的「冲」感。
    // 只影响本页 jQuery 动画，不影响 CSS transition。
    $.easing.swing = function (p) {
      return p < 0.5 ? 2 * p * p : 1 - Math.pow(-2 * p + 2, 2) / 2;
    };
    $main.poptrox(PP_CONFIG);

    // 无限瀑布流翻页后，把新卡片也绑定到灯箱。
    // poptrox 只绑定“还有 href”的锚点，而早已绑定过的锚点 href 已被 poptrox 移除，
    // 所以重绑前要把 href 从 data-images[0] 补回来；否则重绑会跳过旧卡片（导致“所有灯箱打不开”）。
    window.__rebindPoptrox = function() {
        if ($('.poptrox-overlay').is(':visible')) return; // 灯箱打开时不重建
        $main.find('.thumb > a.image').each(function() {
            var a = this;
            if (!a.getAttribute('href') && a.dataset.images) {
                try { var imgs = ppParseArr(a.dataset.images); if (imgs[0]) a.setAttribute('href', imgs[0]); } catch (e) {}
            }
        });
        $('.poptrox-overlay').remove();
        $('.poptrox-popup').remove();
        $main.find('.thumb > a.image').off('click');
        $main.poptrox(PP_CONFIG);
        if (typeof ensureExifObserver === 'function') ensureExifObserver();
    };

    // 适配窄屏：xsmall 时把弹窗边距归零（对第二个（当前）poptrox 实例生效，带守卫防未初始化时报错）。
    breakpoints.on('<=xsmall', function() {
        if ($main[0]._poptrox) { $main[0]._poptrox.windowMargin = 0; }
    });
    breakpoints.on('>xsmall', function() {
        if ($main[0]._poptrox) { $main[0]._poptrox.windowMargin = 50; }
    });

    // ---- 灯箱渐进加载（blur-up）：用缩略图当作模糊预览，全图加载后淡出 ----
    var previewMap = {};
    var panoMap = {};
    document.querySelectorAll('#main a.image[data-images]').forEach(function(a) {
        var imgs = ppParseArr(a.dataset.images);
        var pre = ppParseArr(a.dataset.previews);
        var panos = ppParseArr(a.dataset.panos);
        imgs.forEach(function(u, i) { previewMap[u] = pre[i] || u; });
        imgs.forEach(function(u, i) { panoMap[u] = !!panos[i]; });
    });
    // 判断某张图是不是全景（用于切到全景时立刻切成 4:3 视窗，避免挂载后再跳尺寸）
    function isPanoUrl(u) {
        if (!u) return false;
        return !!panoMap[u] || !!panoMap[normUrl(u)];
    }
    // 记录点击的是哪张图（原始宽高），供 onPopupOpen 预置弹窗尺寸。
    document.addEventListener('click', function(e) {
        var a = e.target && e.target.closest ? e.target.closest('#main a.image[data-dims]') : null;
        if (!a) return;
        try {
            var dims = JSON.parse(a.dataset.dims || '[]');
            var m = String(dims[0] || '').split('x');
            var w = parseInt(m[0], 10), h = parseInt(m[1], 10);
            window.__ppOpenDims = (w > 0 && h > 0) ? [w, h] : null;
        } catch (err) { window.__ppOpenDims = null; }
    }, true);
    function lqipFor(full) { return previewMap[full] || full; }
    function clearLqip(popup) {
        var lq = popup && popup.querySelector('.pp-lqip');
        if (lq) lq.remove();
    }
    function applyLqip(popup) {
        if (!popup) return;
        var pic = popup.querySelector('.pic');
        var img = pic ? pic.querySelector('img') : null;
        if (!img || !pic) return;
        var full = img.getAttribute('src') || '';
        var pre = lqipFor(full);
        if (!pre || pre === full) { clearLqip(popup); img.style.opacity = '1'; return; }
        // 每次切图递增序号：上一张的 onload/decode 回调晚到时会自动作废，
        // 避免把「已经切走」的图淡入回来或残留透明状态。
        var seq = popup.__lqipSeq = (popup.__lqipSeq || 0) + 1;
        var lq = popup.querySelector('.pp-lqip');
        if (!lq) {
            lq = document.createElement('div');
            lq.className = 'pp-lqip';
            // 插在 .pic 前面（而非 .pic 内）：poptrox 加载完成会对 .pic 做一次 hide().fadeIn()，
            // 若遮罩在 .pic 内会跟着一起闪；放在 .pic 前面则按文档顺序垫底，全图淡入时遮罩保持稳定。
            // 不用 z-index 抬 .pic，避免破坏全景/全屏按钮依赖的原有堆叠关系。
            popup.insertBefore(lq, pic);
        }
        // 缩略图立刻垫底：不能从透明淡入，否则切图瞬间会先露出弹窗外面的背景（更生硬）。
        lq.style.backgroundImage = 'url("' + pre + '")';
        lq.style.opacity = '1';
        // 原图先透明：这里必须临时关掉过渡，否则新 <img> 的默认 opacity:1 会先
        // 「反向淡出」一段，等于加载期间把下一张照片提前露出来。
        img.style.transition = 'none';
        img.style.opacity = '0';
        void img.offsetWidth; // 强制 reflow，确保下一帧从 opacity:0 起步
        img.style.transition = ''; // 恢复 CSS 里的柔和淡入过渡
        function reveal() {
            if (seq !== popup.__lqipSeq) return; // 已切到下一张，丢弃过期回调
            img.style.opacity = '1';
            // 把弹窗尺寸回写为实际渲染尺寸：poptrox 在加载阶段量到的宽度受上一帧的
            // 弹窗尺寸影响（移动端 img 是 width:100%），会把它当作下一张的起始尺寸，
            // 于是切图先缩成小框再放大。这里在稳定后用真实尺寸覆盖，切图只保留高度方向的柔和变化。
            if (!popup.classList.contains('loading')) {
                try {
                    var r = popup.getBoundingClientRect();
                    if (r.width > 0 && r.height > 0) {
                        $(popup).data('width', r.width).data('height', r.height);
                    }
                } catch (e) {}
            }
            setTimeout(function() {
                if (seq !== popup.__lqipSeq) return;
                clearLqip(popup);
            }, 1000); // 覆盖 poptrox 的尺寸过渡(420ms) + .pic 淡入(420ms)，避免中途露出背景
        }
        // 两个条件都满足才显示原图：①浏览器已解码完成（避免边解码边变清晰）；
        // ②poptrox 已结束 loading（此时它会对 .pic 做淡入）。这样缩略图会一直垫在下面，
        // 原图是随 .pic 的淡入柔和盖上去，不会先整张弹出、再被 .pic 的淡入闪一下。
        function whenDecoded() {
            var gate = function() {
                if (seq !== popup.__lqipSeq) return;
                if (!popup.classList.contains('loading')) { reveal(); return; }
                var mo = new MutationObserver(function() {
                    if (popup.classList.contains('loading')) return;
                    mo.disconnect();
                    if (seq === popup.__lqipSeq) reveal();
                });
                mo.observe(popup, { attributes: true, attributeFilter: ['class'] });
                setTimeout(function() { // 兜底：极端情况下 class 未变化也要显示
                    mo.disconnect();
                    if (seq === popup.__lqipSeq) reveal();
                }, 2000);
            };
            if (img.decode) { img.decode().then(gate, gate); }
            else { gate(); }
        }
        if (img.complete) {
            if (img.naturalWidth > 0) { whenDecoded(); }
            else { reveal(); } // 加载失败也恢复可见，避免图片一直透明
        } else {
            img.onload = whenDecoded;
            img.onerror = reveal;
        }
    }
    // 监听灯箱图片 src 变化（上一张/下一张/滑动），重新铺预览
    new MutationObserver(function(muts) {
        muts.forEach(function(m) {
            if (m.type === 'attributes' && m.attributeName === 'src') {
                var img = m.target;
                var popup = img.closest ? img.closest('.poptrox-popup') : null;
                if (popup) {
                    // 全景统一 4:3 视窗：在 src 变化当下就切换类，LQIP 盖着时完成尺寸变化，不会挂载后再跳
                    popup.classList.toggle('pp-pano-mode', isPanoUrl(img.getAttribute('src')));
                    applyLqip(popup);
                    preloadNeighbors(popup);
                }
            }
        });
    }).observe(document.body, { attributes: true, subtree: true, attributeFilter: ['src'] });

    // ---- 图集内多图切换：上一张/下一张/滑动在“同一图集内”循环，边界再切到相邻图集 ----
    var ALBUMS = [];
    document.querySelectorAll('#main a.image[data-images]').forEach(function(a) {
        function nonEmpty(arr) {
            return (Array.isArray(arr) ? arr : []).filter(function(v) { return v !== null && v !== undefined && String(v) !== ''; });
        }
        ALBUMS.push({
            images: nonEmpty(ppParseArr(a.dataset.images)),
            previews: nonEmpty(ppParseArr(a.dataset.previews)),
            exifs: nonEmpty(ppParseArr(a.dataset.exif)),
            titles: nonEmpty(ppParseArr(a.dataset.titles)),
            descs: nonEmpty(ppParseArr(a.dataset.descs)),
            addrs: nonEmpty(ppParseArr(a.dataset.addresses))
        });
    });
    // 清理 URL 上的缓存/分片参数，避免 src 带 ?v= 或 # 导致精确匹配失败
    function normUrl(u) { return String(u || '').split('#')[0].split('?')[0]; }
    // 在图集列表里找当前 src 的图集：先精确，再退化为前缀匹配（容错相对/绝对路径差异）
    function albumForSrc(src) {
        var s = normUrl(src);
        if (!s) return null;
        for (var i = 0; i < ALBUMS.length; i++) {
            var imgs = ALBUMS[i].images;
            for (var k = 0; k < imgs.length; k++) {
                if (normUrl(imgs[k]) === s) return { album: ALBUMS[i], idx: k };
            }
        }
        for (var i2 = 0; i2 < ALBUMS.length; i2++) {
            var imgs2 = ALBUMS[i2].images;
            for (var k2 = 0; k2 < imgs2.length; k2++) {
                var u = normUrl(imgs2[k2]);
                // 只对“非空且非极短”的路径做前缀匹配，避免空串或 '/' 误配
                if (u && u.length > 1 && (s.indexOf(u) === 0 || u.indexOf(s) === 0)) return { album: ALBUMS[i2], idx: k2 };
            }
        }
        return null;
    }
    function inAlbumNav(popup, delta) {
        var img = popup && popup.querySelector('.pic img');
        if (!img || !img.getAttribute('src')) return false;
        var cur = albumForSrc(img.getAttribute('src'));
        if (!cur || cur.album.images.length <= 1) return false;
        var ni = cur.idx + delta;
        if (ni < 0 || ni >= cur.album.images.length) return false; // 边界：交给外部跨图集
        if (popup.__switching) return false; // 动画进行中，忽略重复切换，避免 opacity 状态错乱
        popup.__switching = true;
        setTimeout(function() { popup.__switching = false; }, 460); // 与过渡总时长一致
        // 同步比例切换也要带淡出→淡入，避免“直接换图”的生硬感。
        // 过渡走 .poptrox-popup .pic img 的 CSS opacity transition（柔和缓动）。
        var nextSrc = cur.album.images[ni];
        img.style.opacity = '0';
        setTimeout(function() {
            // 灯箱可能已关闭 / 图片元素已被移除：此时不再操作 DOM，避免报错
            if (!img || !img.isConnected || !popup || !popup.isConnected) return;
            img.setAttribute('src', nextSrc);
            img.style.opacity = '1';
        }, 220); // 略大于半程，让淡出先发生，再换图并淡入
        if (typeof applyLqip === 'function' && !cur.album.previews[ni]) { /* 预览依赖 src 变化触发的 observer */ }
        if (window.syncDockExif) { try { syncDockExif(); } catch (e) {} }
        return true;
    }
    // 预加载当前图的相邻图（同图集前后 + 相邻图集首图），让键盘/按钮/滑动切换更跟手。
    // 每张只预加载一次；用 new Image() 走浏览器缓存，不阻塞主线程。
    var __preloaded = {};
    function preloadUrl(u) {
        if (!u || __preloaded[u]) return;
        __preloaded[u] = true;
        try { var im = new Image(); im.decoding = 'async'; im.src = u; } catch (e) {}
    }
    function preloadNeighbors(popup) {
        try {
            var img = popup && popup.querySelector('.pic img');
            var src = img && img.getAttribute('src');
            var cur = albumForSrc(src);
            if (!cur) return;
            var album = cur.album, idx = cur.idx;
            preloadUrl(album.images[idx - 1]);
            preloadUrl(album.images[idx + 1]);
            var ai = ALBUMS.indexOf(album);
            if (ai > 0) preloadUrl(ALBUMS[ai - 1].images[0]);
            if (ai >= 0 && ai + 1 < ALBUMS.length) preloadUrl(ALBUMS[ai + 1].images[0]);
        } catch (e) {}
    }
    // 捕获阶段拦截上一张/下一张按钮，避免 poptrox 直接跳到相邻图集
    document.addEventListener('click', function(e) {
        var t = e.target && e.target.closest ? e.target.closest('.poptrox-popup .nav-previous, .poptrox-popup .nav-next') : null;
        if (!t) return;
        var popup = t.closest('.poptrox-popup');
        var delta = t.classList.contains('nav-next') ? 1 : -1;
        if (inAlbumNav(popup, delta)) { e.preventDefault(); e.stopImmediatePropagation(); }
    }, true);

    // 灯箱内禁止页面滚动（切图改用底部按钮，已移除滑动切图）
    document.body.addEventListener('touchmove', function(e) {
        const popup = e.target.closest('.poptrox-popup');
        if (!isPopupActive || !popup) return;
        if (popup.__panoActive) return; // 全景激活：拖动交给 Pannellum 旋转
        e.preventDefault();
    }, { passive: false, capture: true });

    // 添加图片查看器状态变化监听
    const observer = new MutationObserver(function(mutations) {
        mutations.forEach(function(mutation) {
            if (mutation.target.classList.contains('poptrox-popup')) {
                isPopupActive = mutation.target.style.display !== 'none';
            }
        });
    });

    // 开始观察 body 的变化
    observer.observe(document.body, {
        childList: true,
        subtree: true,
        attributes: true,
        attributeFilter: ['style', 'class']
    });
});
