/* 稳定、可分享的照片链接；服务端搜索和分页无需 JavaScript 也可使用。 */
(function (root, factory) {
    'use strict';
    if (typeof module === 'object' && module.exports) module.exports = factory;
    else factory(root, root.document);
})(typeof window !== 'undefined' ? window : this, function (win, doc) {
    'use strict';
    if (!win || !doc) return null;
    var applying = 0;
    var closingFromHistory = false;
    var revision = 0;
    var ready = false;
    var marker = 'infinityGallery';
    var renderedUrl = win.location.href;
    function gridKey(value) {
        var url = new URL(value, win.location.href);
        url.hash = '';
        url.searchParams.sort();
        return url.href;
    }
    function reloadGridIfNeeded() {
        if (gridKey(win.location.href) === gridKey(renderedUrl)) return false;
        // 刷新过的深链接页面只含单张照片，不能把地址回退误当成完整列表恢复。
        win.location.reload();
        return true;
    }

    function selection(url) {
        var params = new URL(url, win.location.href).searchParams;
        var albumId = params.get('album') || '';
        var photoId = params.get('photo') || '';
        return /^\d+$/.test(albumId) && /^(p-[1-9]\d*|l-[a-f0-9]{20})$/.test(photoId)
            ? { albumId: albumId, photoId: photoId } : null;
    }
    function state(extra) {
        return Object.assign({}, win.history.state || {}, extra);
    }
    function photoUrl(detail) {
        var url = new URL(win.location.href);
        url.searchParams.set('album', detail.albumId);
        url.searchParams.set('photo', detail.photoId);
        url.searchParams.delete('cursor');
        url.hash = '';
        return url.href;
    }
    function rememberPosition() {
        // 新增灯箱历史记录前，保留实际列表地址和滚动位置。
        win.history.replaceState(state({ infinityGridScroll: [win.scrollX || 0, win.scrollY || 0] }), '', win.location.href);
    }
    function onOpen(event) {
        if (applying || !event.detail || !event.detail.photoId) return;
        var detail = event.detail;
        var existing = win.history.state && win.history.state[marker];
        if (existing) {
            win.history.replaceState(state({}), '', photoUrl(detail));
        } else {
            rememberPosition();
            win.history.pushState(state({ infinityGallery: true }), '', photoUrl(detail));
        }
    }
    function onChange(event) {
        if (applying || !event.detail || !event.detail.photoId) return;
        if (!selection(win.location.href)) { onOpen(event); return; }
        win.history.replaceState(state({}), '', photoUrl(event.detail));
    }
    function onClose() {
        if (closingFromHistory) return;
        revision++;
        if (win.history.state && win.history.state[marker]) {
            win.history.back();
        } else if (selection(win.location.href)) {
            // 直接打开的分享链接没有可返回的列表历史记录。
            var url = new URL(win.location.href);
            url.searchParams.delete('photo');
            win.history.replaceState(state({ infinityGallery: false }), '', url.href);
            reloadGridIfNeeded();
        }
    }
    async function applyLocation() {
        if (!ready || !win.InfinityGallery) return;
        var ownRevision = ++revision;
        var target = selection(win.location.href);
        applying++;
        try {
            if (target) {
                var opened = await win.InfinityGallery.open(target);
                if (!opened && ownRevision === revision) {
                    if (reloadGridIfNeeded()) return;
                    var status = doc.getElementById('gallery-status');
                    if (status) status.textContent = '这张照片不可用，可能已删除或不符合当前筛选条件。';
                }
            } else {
                if (reloadGridIfNeeded()) return;
                closingFromHistory = true;
                try { win.InfinityGallery.close(); } finally { closingFromHistory = false; }
                var pos = win.history.state && win.history.state.infinityGridScroll;
                if (Array.isArray(pos)) win.requestAnimationFrame(function () {
                    if (ownRevision === revision) win.scrollTo(pos[0], pos[1]);
                });
            }
        } finally { applying--; }
    }
    function onReady() {
        if (ready) return;
        ready = true;
        applyLocation();
    }
    doc.addEventListener('infinitygallery:open', onOpen);
    doc.addEventListener('infinitygallery:change', onChange);
    doc.addEventListener('infinitygallery:close', onClose);
    doc.addEventListener('infinitygallery:ready', onReady);
    win.addEventListener('popstate', applyLocation);
    if (win.InfinityGallery) onReady();
    return { selection: selection, applyLocation: applyLocation };
});
