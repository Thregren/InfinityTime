/*
	Multiverse by HTML5 UP
	html5up.net | @ajlkn
	Free for personal and commercial use under the CCA 3.0 license (html5up.net/license)
*/

(function($) {

	var	$window = $(window),
		$body = $('body');

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

})(jQuery);

// ---- InfinityTime 自定义逻辑（收敛到独立作用域，避免往 window 上挂多余全局）----
(function () {

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
		if (!fullscreenAPI.enter) return; // 浏览器不支持元素全屏：直接忽略，避免抛错
		$("#fullscreen").html("退出全屏");
		fullscreenAPI.enter.call(document.documentElement);
	} else {
		if (!fullscreenAPI.exit) return;
		$("#fullscreen").html('<i class="iconfont icon-quanping"></i><use xlink:href="#icon-zmki-ziyuan-copy"></use></svg>');
		fullscreenAPI.exit.call(document);
	}
}

// 简化全屏切换事件监听
$('#fullscreen').on('click', toggleFullscreen);

// 灯箱交互（触摸滚动锁定、EXIF 侧栏、切图）
document.addEventListener('DOMContentLoaded', function() {
    let isPopupActive = false;
    const $main = $('#main');
    const $body = $('body');
    var galleryOpener = null;
    var backgroundState = [];
    var poptroxRebindPending = false;

    // Poptrox removes href and owns its popup markup. Restore keyboard semantics
    // here, including controls rendered outside .poptrox-popup by lightbox.js.
    function prepareGalleryDialog() {
        $main.find('.thumb > a.image').attr({ role: 'button', tabindex: '0', 'aria-haspopup': 'dialog' });
        var overlay = document.querySelector('.poptrox-overlay');
        if (!overlay) return;
        overlay.setAttribute('role', 'dialog');
        overlay.setAttribute('aria-modal', 'true');
        overlay.setAttribute('aria-label', '图片浏览');
        overlay.setAttribute('aria-hidden', isPopupActive ? 'false' : 'true');
        if (isPopupActive) overlay.removeAttribute('inert');
        else overlay.setAttribute('inert', '');
        overlay.setAttribute('tabindex', '-1');
        document.querySelectorAll('.poptrox-exif-dock, .pp-mobile-nav, .pp-mobile-caption').forEach(function(el) {
            if (el.parentNode !== overlay) overlay.appendChild(el);
        });
        if (!overlay.querySelector('.pp-lightbox-close')) {
            var close = document.createElement('button');
            close.type = 'button';
            close.className = 'pp-lightbox-close';
            close.setAttribute('aria-label', '关闭图片浏览');
            close.textContent = '×';
            close.addEventListener('click', function(e) {
                e.preventDefault();
                e.stopPropagation();
                $(overlay.querySelector('.poptrox-popup')).trigger('poptrox_close');
            });
            overlay.appendChild(close);
        }
    }
    function openGalleryDialog() {
        prepareGalleryDialog();
        var overlay = document.querySelector('.poptrox-overlay');
        if (!galleryOpener) galleryOpener = document.activeElement;
        backgroundState = [];
        Array.from(document.body.children).forEach(function(el) {
            if (el === overlay || /^(SCRIPT|STYLE|LINK)$/.test(el.tagName)) return;
            backgroundState.push({ el: el, inert: el.hasAttribute('inert'), hidden: el.getAttribute('aria-hidden') });
            el.setAttribute('inert', '');
            el.setAttribute('aria-hidden', 'true');
        });
        // onPopupOpen runs just before Poptrox reveals the overlay.
        setTimeout(function() {
            if (isPopupActive && overlay && overlay.isConnected) overlay.querySelector('.pp-lightbox-close').focus();
        }, 0);
    }
    function closeGalleryDialog() {
        backgroundState.forEach(function(state) {
            if (!state.inert) state.el.removeAttribute('inert');
            if (state.hidden === null) state.el.removeAttribute('aria-hidden');
            else state.el.setAttribute('aria-hidden', state.hidden);
        });
        backgroundState = [];
        if (galleryOpener && galleryOpener.isConnected) galleryOpener.focus({ preventScroll: true });
        galleryOpener = null;
        var overlay = document.querySelector('.poptrox-overlay');
        if (overlay) { overlay.setAttribute('aria-hidden', 'true'); overlay.setAttribute('inert', ''); }
    }
    function dialogTabStops(overlay) {
        return Array.from(overlay.querySelectorAll('button, a[href], input, select, textarea, [tabindex]')).filter(function(el) {
            if (el.tabIndex < 0 || el.disabled || el.closest('[inert]') || !el.getClientRects().length) return false;
            var style = getComputedStyle(el);
            return style.display !== 'none' && style.visibility !== 'hidden';
        });
    }
    document.addEventListener('focusin', function(e) {
        var overlay = document.querySelector('.poptrox-overlay');
        if (isPopupActive && overlay && !overlay.contains(e.target)) overlay.querySelector('.pp-lightbox-close').focus();
    });
    document.addEventListener('keydown', function(e) {
        var opener = e.target && e.target.closest ? e.target.closest('#main .thumb > a.image') : null;
        if (!isPopupActive) {
            if (opener && (e.key === 'Enter' || e.key === ' ')) {
                e.preventDefault();
                e.stopImmediatePropagation();
                opener.click();
            }
            return;
        }
        var overlay = document.querySelector('.poptrox-overlay');
        var popup = overlay && overlay.querySelector('.poptrox-popup');
        if (!popup) return;
        if (e.key === 'Tab') {
            var stops = dialogTabStops(overlay);
            var index = stops.indexOf(document.activeElement);
            var next = e.shiftKey ? (index <= 0 ? stops.length - 1 : index - 1) : (index + 1) % stops.length;
            e.preventDefault();
            e.stopImmediatePropagation();
            (stops[next] || overlay).focus();
        } else if (e.key === 'Escape') {
            e.preventDefault();
            e.stopImmediatePropagation();
            $(popup).trigger('poptrox_close');
        } else if ((e.key === 'ArrowLeft' || e.key === 'ArrowRight') && !e.target.closest('input, textarea, select, [contenteditable="true"]')) {
            // Use the same path as the visible buttons, so arrows visit album
            // images rather than jumping directly to the next gallery card.
            e.preventDefault();
            e.stopImmediatePropagation();
            var nav = popup.querySelector(e.key === 'ArrowRight' ? '.nav-next' : '.nav-previous');
            if (nav) nav.click();
        }
    }, true);
    // Poptrox treats Space as "previous" at window level. Let real controls
    // handle it normally without also navigating the gallery.
    document.addEventListener('keydown', function(e) {
        if (isPopupActive && e.key === ' ') e.stopPropagation();
    });

    // 监听弹窗状态（配置复用：无限瀑布流翻页后需重绑一次）
    const PP_CONFIG = {
        baseZIndex: 20000,
        caption: function($a) { return $a.next('h2').text().trim(); },
        fadeSpeed: 420,
        onPopupClose: function() { 
            isPopupActive = false;
            closeGalleryDialog();
            captionFadeOut();
            $body.removeClass('modal-active');
            $('html, body').css({
                'overflow': '',
                'position': '',
                'height': '',
                'width': ''
            });
            // 清理本项目挂在弹窗上的临时状态，避免关闭后再开残留锁/全景态
            document.querySelectorAll('.poptrox-popup').forEach(function(p) {
                clearLqip(p);
                p.__lqipSeq = (p.__lqipSeq || 0) + 1;
                p.classList.remove('pp-pano-mode');
                delete p.__switching;
                p.__switchSeq = (p.__switchSeq || 0) + 1;
                delete p.__panoActive;
                delete p.__ppIndex;
                delete p.__ppArticle;
            });
            // Newly loaded cards may have arrived while the modal was open.
            // Rebuild only after its closing animation no longer owns the overlay.
            if (poptroxRebindPending) setTimeout(function() {
                if (poptroxRebindPending) window.__rebindPoptrox();
            }, PP_CONFIG.fadeSpeed + 20);
        },
        onPopupOpen: function() { 
            isPopupActive = true;
            openGalleryDialog();
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
    prepareGalleryDialog();

    // 无限瀑布流翻页后，把新卡片也绑定到灯箱。
    // poptrox 只绑定“还有 href”的锚点，而早已绑定过的锚点 href 已被 poptrox 移除，
    // 所以重绑前要把 href 从 data-images[0] 补回来；否则重绑会跳过旧卡片（导致“所有灯箱打不开”）。
    window.__rebindPoptrox = function() {
        if (isPopupActive || $('.poptrox-overlay').is(':visible')) { poptroxRebindPending = true; return; }
        poptroxRebindPending = false;
        if ($main[0]._poptroxDestroy) $main[0]._poptroxDestroy();
        $main.find('.thumb > a.image').each(function() {
            var a = this;
            if (!a.getAttribute('href') && a.dataset.images) {
                try { var imgs = ppParseArr(a.dataset.images); if (imgs[0]) a.setAttribute('href', imgs[0]); } catch (e) {}
            }
        });
        // Preserve the shared controls before disposing of the old dialog.
        document.querySelectorAll('.poptrox-exif-dock, .pp-mobile-nav, .pp-mobile-caption').forEach(function(el) {
            document.body.appendChild(el);
        });
        $('.poptrox-overlay').remove();
        $('.poptrox-popup').remove();
        $main.find('.thumb > a.image').off('click');
        $main.poptrox(PP_CONFIG);
        prepareGalleryDialog();
        rebuildLightboxData(); // 新卡片加入后重建 blur-up/全景/图集数据
        if (typeof ensureExifObserver === 'function') ensureExifObserver();
    };

    // 适配窄屏：xsmall 时把弹窗边距归零（对第二个（当前）poptrox 实例生效，带守卫防未初始化时报错）。
    breakpoints.on('<=xsmall', function() {
        if ($main[0]._poptrox) { $main[0]._poptrox.windowMargin = 0; }
    });
    breakpoints.on('>xsmall', function() {
        if ($main[0]._poptrox) { $main[0]._poptrox.windowMargin = 50; }
    });

    // ---- 灯箱数据（blur-up 预览 / 全景标记 / 图集索引）----
    // 无限瀑布流翻页会新增卡片，重绑 poptrox 时同步重建这些数据，否则新图没有 blur-up / 全景识别。
    var previewMap = {}, panoMap = {}, ALBUMS = [], variantMap = {};
    function rebuildLightboxData() {
        previewMap = {};
        panoMap = {};
        ALBUMS = [];
        variantMap = {};
        function nonEmpty(arr) {
            return (Array.isArray(arr) ? arr : []).filter(function(v) { return v !== null && v !== undefined && String(v) !== ''; });
        }
        document.querySelectorAll('#main a.image[data-images]').forEach(function(a) {
            var imgs = ppParseArr(a.dataset.images);
            var pre = ppParseArr(a.dataset.previews);
            var panos = ppParseArr(a.dataset.panos);
            var variants = ppParseArr(a.dataset.variants);
            imgs.forEach(function(u, i) { previewMap[u] = pre[i] || u; });
            imgs.forEach(function(u, i) { panoMap[u] = !!panos[i]; });
            imgs.forEach(function(u, i) { variantMap[u] = variants[i] || null; });
            ALBUMS.push({
                title: a.getAttribute('aria-label') || '照片',
                images: nonEmpty(imgs),
                previews: nonEmpty(pre),
                exifs: nonEmpty(ppParseArr(a.dataset.exif)),
                titles: ppParseArr(a.dataset.titles),
                descs: nonEmpty(ppParseArr(a.dataset.descs)),
                addrs: nonEmpty(ppParseArr(a.dataset.addresses))
            });
        });
    }
    rebuildLightboxData();
    // 判断某张图是不是全景（用于切到全景时立刻切成 4:3 视窗，避免挂载后再跳尺寸）
    function isPanoUrl(u) {
        if (!u) return false;
        return !!panoMap[u] || !!panoMap[normUrl(u)];
    }
    // 记录点击的是哪张图（原始宽高），供 onPopupOpen 预置弹窗尺寸。
    document.addEventListener('click', function(e) {
        var a = e.target && e.target.closest ? e.target.closest('#main .thumb > a.image') : null;
        if (!a) return;
        galleryOpener = a;
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
    // ---- 灯箱标题（.pp-mobile-caption）：照片淡入完成后才淡入；按下切图按钮时立即淡出 ----
    var captionSeq = 0;
    function captionFadeOut() {
        captionSeq++;
        var c = document.querySelector('.pp-mobile-caption');
        if (c) c.classList.remove('pp-cap-show');
    }
    function captionFadeInAfterImage() {
        var seq = ++captionSeq;
        setTimeout(function() {
            if (seq !== captionSeq || !isPopupActive) return;
            var p = document.querySelector('.poptrox-popup');
            if (!p || getComputedStyle(p).display === 'none') return;
            var c = document.querySelector('.pp-mobile-caption');
            if (c) c.classList.add('pp-cap-show');
        }, 440); // 等 poptrox 的 .pic 淡入（420ms）完成后再显示标题
    }
    // ---- 响应式图片：给灯箱主图设置 WebP srcset，并在有 AVIF 变体时用 <picture> 包一层 ----
    function removeResponsivePicture(img) {
        var picture = img.parentNode;
        if (picture && picture.tagName === 'PICTURE' && picture.classList.contains('pp-picture')) {
            picture.parentNode.insertBefore(img, picture);
            picture.remove();
        }
    }
    function applyResponsive(popup, img) {
        if (!popup || !img) return;
        var src = img.getAttribute('src') || '';
        var pic = popup.querySelector('.pic');
        // 全景：隐藏的 <img> 只用于「加载完成」判定，真正渲染由 Pannellum 读全图；
        // 这里若走 srcset 会额外下一份 1600 变体，反而多流量，直接跳过。
        if (isPanoUrl(src)) {
            img.removeAttribute('srcset');
            img.removeAttribute('sizes');
            removeResponsivePicture(img);
            if (pic) pic.querySelectorAll('picture.pp-picture').forEach(function (p) { if (!p.contains(img)) p.remove(); });
            return;
        }
        var v = variantMap[src] || variantMap[normUrl(src)] || null;
        // 清理上一张切走后遗留的 <picture>（poptrox 会 detach 旧 img，留下空包装）
        if (pic) pic.querySelectorAll('picture.pp-picture').forEach(function (p) { if (!p.contains(img)) p.remove(); });
        if (!v) {
            img.removeAttribute('srcset');
            img.removeAttribute('sizes');
            removeResponsivePicture(img);
            return;
        }
        var webp = v.webp || [];
        var avif = v.avif || [];
        var w = parseInt(v.w, 10) || img.naturalWidth || 0;
        var midW = Math.min(1600, w);
        var sizes = '(max-width: 900px) 100vw, min(1400px, calc(100vw - 100px))';
        var webpSet = [];
        if (webp[0] && w) webpSet.push(webp[0] + ' ' + w + 'w');
        if (webp[1] && midW && midW < w) webpSet.push(webp[1] + ' ' + midW + 'w');
        if (webpSet.length) {
            img.setAttribute('srcset', webpSet.join(', '));
            img.setAttribute('sizes', sizes);
        } else {
            img.removeAttribute('srcset');
            img.removeAttribute('sizes');
        }
        var avifSet = [];
        if (avif[0] && w) avifSet.push(avif[0] + ' ' + w + 'w');
        if (avif[1] && midW && midW < w) avifSet.push(avif[1] + ' ' + midW + 'w');
        if (avifSet.length) {
            var picture = (img.parentNode && img.parentNode.tagName === 'PICTURE') ? img.parentNode : null;
            if (!picture) {
                picture = document.createElement('picture');
                picture.className = 'pp-picture';
                img.parentNode.insertBefore(picture, img);
                picture.appendChild(img);
            }
            var source = picture.querySelector('source[type="image/avif"]');
            if (!source) {
                source = document.createElement('source');
                source.type = 'image/avif';
                picture.insertBefore(source, img);
            }
            source.setAttribute('srcset', avifSet.join(', '));
            source.setAttribute('sizes', sizes);
        } else {
            removeResponsivePicture(img);
        }
    }
    function applyLqip(popup) {
        if (!popup) return;
        var pic = popup.querySelector('.pic');
        var img = pic ? pic.querySelector('img') : null;
        if (!img || !pic) return;
        applyResponsive(popup, img);
        var full = img.getAttribute('src') || '';
        var current = albumForSrc(full);
        if (current) {
            var title = current.album.titles[current.idx] || current.album.title;
            img.alt = title + (current.album.images.length > 1 ? '（' + (current.idx + 1) + '/' + current.album.images.length + '）' : '');
        } else { img.alt = '照片'; }
        var pre = lqipFor(full);
        if (!pre || pre === full) { clearLqip(popup); img.style.opacity = '1'; captionFadeInAfterImage(); return; }
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
            captionFadeInAfterImage();
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
                if (!img || img.tagName !== 'IMG') return; // 只关心图片 src，忽略其它元素的 src 变化
                var popup = img.closest ? img.closest('.poptrox-popup') : null;
                if (popup) {
                    // 全景统一 4:3 视窗：在 src 变化当下就切换类，LQIP 盖着时完成尺寸变化，不会挂载后再跳
                    popup.classList.toggle('pp-pano-mode', isPanoUrl(img.getAttribute('src')));
                    captionFadeOut();
                    applyLqip(popup);
                    preloadNeighbors(popup);
                }
            }
        });
    }).observe(document.body, { attributes: true, subtree: true, attributeFilter: ['src'] });

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
        if (!isPopupActive) return true;
        if (popup && (popup.__switching || popup.classList.contains('loading'))) return true;
        var img = popup && popup.querySelector('.pic img');
        if (!img || !img.getAttribute('src')) return false;
        var cur = albumForSrc(img.getAttribute('src'));
        if (!cur || cur.album.images.length <= 1) return false;
        var ni = cur.idx + delta;
        if (ni < 0 || ni >= cur.album.images.length) return false; // 边界：交给外部跨图集
        popup.__switching = true;
        var seq = popup.__switchSeq = (popup.__switchSeq || 0) + 1;
        setTimeout(function() { if (seq === popup.__switchSeq) popup.__switching = false; }, 460); // 与过渡总时长一致
        // 同步比例切换也要带淡出→淡入，避免“直接换图”的生硬感。
        // 过渡走 .poptrox-popup .pic img 的 CSS opacity transition（柔和缓动）。
        var nextSrc = cur.album.images[ni];
        img.style.opacity = '0';
        setTimeout(function() {
            // 灯箱可能已关闭 / 图片元素已被移除：此时不再操作 DOM，避免报错
            if (!isPopupActive || seq !== popup.__switchSeq || !img.isConnected || !popup.isConnected) return;
            img.setAttribute('src', nextSrc);
            img.style.opacity = '1';
        }, 220); // 略大于半程，让淡出先发生，再换图并淡入
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
        captionFadeOut(); // 按下切图按钮：标题立即淡出
        var popup = t.closest('.poptrox-popup');
        var delta = t.classList.contains('nav-next') ? 1 : -1;
        if (inAlbumNav(popup, delta)) { e.preventDefault(); e.stopImmediatePropagation(); }
    }, true);

    // 灯箱内禁止页面滚动（切图改用底部按钮，已移除滑动切图）
    document.body.addEventListener('touchmove', function(e) {
        const popup = e.target && e.target.closest ? e.target.closest('.poptrox-popup') : null;
        if (!isPopupActive || !popup) return;
        if (popup.__panoActive) return; // 全景激活：拖动交给 Pannellum 旋转
        e.preventDefault();
    }, { passive: false, capture: true });
});

})();

