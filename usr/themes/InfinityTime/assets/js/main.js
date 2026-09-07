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

// 添加触摸滑动支持（多图时支持拖动跟手，分页滚动）
document.addEventListener('DOMContentLoaded', function() {
    let touchStartX = 0;
    let touchEndX = 0;
    let isTransitioning = false;
    let isPopupActive = false;
    const minSwipeDistance = 50;
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
            touchStartX = 0;
            touchEndX = 0;
            isTransitioning = false;
        },
        onPopupOpen: function() { 
            isPopupActive = true;
            $body.addClass('modal-active');
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
        if (typeof checkImgs === 'function') checkImgs();
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
    document.querySelectorAll('#main a.image[data-images]').forEach(function(a) {
        var imgs = ppParseArr(a.dataset.images);
        var pre = ppParseArr(a.dataset.previews);
        imgs.forEach(function(u, i) { previewMap[u] = pre[i] || u; });
    });
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
        if (!pre || pre === full) { clearLqip(popup); return; }
        var lq = popup.querySelector('.pp-lqip');
        if (!lq) {
            lq = document.createElement('div');
            lq.className = 'pp-lqip';
            pic.appendChild(lq);
        }
        lq.style.backgroundImage = 'url("' + pre + '")';
        lq.style.opacity = '1';
        function done() {
            if (!lq || !lq.isConnected) return;
            lq.style.opacity = '0';
            setTimeout(function() { if (lq && lq.isConnected) lq.remove(); }, 500);
        }
        if (img.complete && img.naturalWidth > 0) { done(); }
        else {
            img.onload = done;
            if (img.complete) { img.onload(); }
        }
    }
    // 监听灯箱图片 src 变化（上一张/下一张/滑动），重新铺预览
    new MutationObserver(function(muts) {
        muts.forEach(function(m) {
            if (m.type === 'attributes' && m.attributeName === 'src') {
                var img = m.target;
                var popup = img.closest ? img.closest('.poptrox-popup') : null;
                if (popup) applyLqip(popup);
            }
        });
    }).observe(document.body, { attributes: true, subtree: true, attributeFilter: ['src'] });

    // ---- 图集内多图切换：上一张/下一张/滑动在“同一图集内”循环，边界再切到相邻图集 ----
    var ALBUMS = [];
    document.querySelectorAll('#main a.image[data-images]').forEach(function(a) {
        ALBUMS.push({
            images: ppParseArr(a.dataset.images),
            previews: ppParseArr(a.dataset.previews),
            exifs: ppParseArr(a.dataset.exif),
            titles: ppParseArr(a.dataset.titles),
            descs: ppParseArr(a.dataset.descs),
            addrs: ppParseArr(a.dataset.addresses)
        });
    });
    function albumForSrc(src) {
        for (var i = 0; i < ALBUMS.length; i++) {
            var idx = ALBUMS[i].images.indexOf(src);
            if (idx >= 0) return { album: ALBUMS[i], idx: idx };
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
        img.setAttribute('src', cur.album.images[ni]);
        if (typeof applyLqip === 'function' && !cur.album.previews[ni]) { /* 预览依赖 src 变化触发的 observer */ }
        if (window.syncDockExif) { try { syncDockExif(); } catch (e) {} }
        return true;
    }
    // 捕获阶段拦截上一张/下一张按钮，避免 poptrox 直接跳到相邻图集
    document.addEventListener('click', function(e) {
        var t = e.target && e.target.closest ? e.target.closest('.poptrox-popup .nav-previous, .poptrox-popup .nav-next') : null;
        if (!t) return;
        var popup = t.closest('.poptrox-popup');
        var delta = t.classList.contains('nav-next') ? 1 : -1;
        if (inAlbumNav(popup, delta)) { e.preventDefault(); e.stopImmediatePropagation(); }
    }, true);

    // 触摸事件：多图时拖动跟手，松手分页切换
    document.body.addEventListener('touchstart', function(e) {
        const popup = e.target.closest('.poptrox-popup');
        if (!isPopupActive || !popup) return;
        if (popup.__panoActive) return; // 全景激活：拖动交给 Pannellum 旋转，不做切图
        touchStartX = e.touches[0].clientX;
    }, { passive: true });

    document.body.addEventListener('touchmove', function(e) {
        const popup = e.target.closest('.poptrox-popup');
        if (!isPopupActive || !popup) return;
        if (popup.__panoActive) return;
        e.preventDefault(); // 灯箱内禁止触发页面滚动
    }, { passive: false, capture: true });

    document.body.addEventListener('touchend', function(e) {
        const popup = e.target.closest('.poptrox-popup');
        if (!isPopupActive || !popup) return;
        if (popup.__panoActive) return;
        touchEndX = e.changedTouches[0].clientX;
        const moved = Math.abs(touchEndX - touchStartX);
        if (moved > minSwipeDistance) {
            handleSwipe(popup);
        }
    }, { passive: false });

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

    function handleSwipe(popup) {
        if (isTransitioning) return;
        if (popup && popup.__panoActive) return;

        const swipeDistance = touchEndX - touchStartX;
        if (Math.abs(swipeDistance) < minSwipeDistance) return;

        // 图集内优先切换；到边界再交给 poptrox 上一张/下一张（跨图集）
        if (typeof inAlbumNav === 'function' && inAlbumNav(popup, swipeDistance < 0 ? 1 : -1)) {
            return;
        }
        const prev = popup.querySelector('.nav-previous');
        const next = popup.querySelector('.nav-next');
        const btn = swipeDistance > 0 ? prev : next;
        if (!btn) return;
        isTransitioning = true;
        btn.click();
        setTimeout(function() { isTransitioning = false; }, 400);
    }
});
