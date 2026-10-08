/* ============================================================
   InfinityTime 后台面板脚本
   依赖 panel.php 注入的 window.PP_ADMIN（url / token / defaults / job）
   ============================================================ */
(function () {
  'use strict';

  var CFG = window.PP_ADMIN || {};
  var ENDPOINT = CFG.url || '';
  var TOKEN = CFG.token || '';
  var JOB = CFG.job || null;
  var CAN_PUBLISH = CFG.canPublish === true;
  var $ = function (sel, root) { return (root || document).querySelector(sel); };
  var $$ = function (sel, root) { return Array.prototype.slice.call((root || document).querySelectorAll(sel)); };

  /* ---------- 基础工具 ---------- */
  function notice(msg, type) {
    var container = $('.container.typecho-page-main');
    if (!container) return;
    var old = container.querySelector(':scope > .notice');
    if (old) old.parentNode.removeChild(old);
    var n = document.createElement('div');
    n.className = 'notice ' + (type === 'error' ? 'error' : 'success');
    n.textContent = msg;
    n.style.margin = '12px 0';
    container.insertBefore(n, container.firstElementChild);
    try { n.scrollIntoView({ block: 'nearest' }); } catch (e) {}
  }

  function post(action, data, opts) {
    opts = opts || {};
    var fd = data instanceof FormData ? data : new FormData();
    if (!(data instanceof FormData) && data) {
      Object.keys(data).forEach(function (k) {
        var v = data[k];
        if (Array.isArray(v)) { v.forEach(function (x) { fd.append(k + '[]', x); }); }
        else { fd.set(k, v); }
      });
    }
    fd.set('ajax', '1');
    if (action) fd.set('action', action);
    if (TOKEN) fd.set('_', TOKEN);
    return fetch(ENDPOINT, { method: 'POST', body: fd, credentials: 'same-origin' })
      .then(function (r) {
        return r.json().catch(function () { return { ok: false, msg: '服务器返回异常' }; })
          .then(function (d) { if (!r.ok) return { ok: false, msg: (d && d.msg) || '请求失败，请重试' }; return d; });
      });
  }

  function readURL(job, params) {
    var query = 'ajax=1&job=' + encodeURIComponent(job);
    Object.keys(params || {}).forEach(function (key) { query += '&' + encodeURIComponent(key) + '=' + encodeURIComponent(params[key]); });
    return ENDPOINT + (ENDPOINT.indexOf('?') === -1 ? '?' : '&') + query;
  }

  var albumsRefresh = 0;
  function refreshAlbums() {
    var cur = $('#pp-albums-card');
    if (!cur) return Promise.resolve();
    var request = ++albumsRefresh;
    return fetch(readURL('albums_html', { page: cur.dataset.page || '1', q: cur.dataset.query || '' }), { credentials: 'same-origin' })
      .then(function (r) { if (!r.ok) throw new Error('list'); return r.text(); })
      .then(function (html) {
        if (request !== albumsRefresh) return;
        var fresh = new DOMParser().parseFromString(html, 'text/html').querySelector('#pp-albums-card');
        var current = $('#pp-albums-card');
        if (!fresh || !current) throw new Error('list');
        current.outerHTML = fresh.outerHTML;
        Albums.bind();
      })
      .catch(function () { notice('操作已保存，但图集列表刷新失败，请刷新页面查看。', 'error'); });
  }

  /* ---------- 标签页 ---------- */
  var Tabs = {
    init: function () {
      var wrap = $('.pp-wrap');
      if (!wrap) return;
      var tabs = $$('.pp-tab', wrap);
      var panels = $$('.pp-panel', wrap);
      if (!tabs.length || !panels.length) return;
      wrap.classList.add('pp-tabs-ready');
      function activate(name, push) {
        tabs.forEach(function (t) { t.classList.toggle('active', t.getAttribute('data-tab') === name); });
        panels.forEach(function (p) { p.classList.toggle('active', p.getAttribute('data-panel') === name); });
        if (push && history.replaceState) history.replaceState(null, '', '#' + name);
      }
      tabs.forEach(function (t) {
        t.addEventListener('click', function () { activate(t.getAttribute('data-tab'), true); });
      });
      var hash = (location.hash || '').replace('#', '');
      var valid = tabs.some(function (t) { return t.getAttribute('data-tab') === hash; });
      activate(valid ? hash : (tabs[0].getAttribute('data-tab')), false);
    }
  };

  /* ---------- 串行上传：先保存草稿，再明确发布 ---------- */
  var Upload = {
    sel: [],
    running: false,
    append: function () {},
    init: function () {
      var form = $('#pp-upload-form'), input = $('#pp-files-input'), wrap = $('#pp-upload-previews');
      if (!form || !input || !wrap) return;
      input.required = false;
      var submitBtn = $('#pp-upload-submit') || form.querySelector('button[type=submit]');
      var retryBtn = $('#pp-upload-retry'), publishBtn = $('#pp-upload-publish'), newBtn = $('#pp-upload-new');
      var cidInput = $('#pp-upload-cid'), target = $('#pp-upload-target');
      var operationInput = form.querySelector('[name="operation_key"]');
      var dropzone = $('#pp-dropzone'), bar = $('#pp-upload-bar'), barOuter = $('#pp-upload-progress');
      var barMsg = barOuter ? barOuter.querySelector('.pp-msg') : null;
      var storageKey = 'InfinityTime.upload:' + ENDPOINT + ':' + (CFG.userId || 'current');
      var cid = '', albumTitle = '', albumStatus = 'draft', albumCount = 0, verified = false;
      var operationKey = '', activeProgress = 0;
      var initialTarget = CFG.uploadTarget || null;
      var labels = { queued: '等待上传', uploading: '正在上传', converting: '正在转换并保存', done: '已保存', failed: '失败' };

      function field(name) { return form.querySelector('[name="' + name + '"]'); }
      function value(name) { var el = field(name); return el ? el.value : ''; }
      function key() {
        if (window.crypto && window.crypto.randomUUID) return window.crypto.randomUUID();
        return 'it-' + Date.now().toString(36) + '-' + Math.random().toString(36).slice(2) + '-' + Math.random().toString(36).slice(2);
      }
      function fileKey(f) { return f.name + '|' + f.size + '|' + (f.lastModified || 0); }
      function humanSize(n) {
        if (!n) return '0 B';
        var units = ['B', 'KB', 'MB', 'GB'], i = 0;
        while (n >= 1024 && i < units.length - 1) { n /= 1024; i++; }
        return n.toFixed(i ? 1 : 0) + ' ' + units[i];
      }
      function savedFields() {
        return { title: value('title'), address: value('address'), device: value('device'), tags: value('tags') };
      }
      function persist() {
        if (cidInput) cidInput.value = cid;
        if (operationInput) operationInput.value = operationKey;
        try {
          window.sessionStorage.setItem(storageKey, JSON.stringify({
            cid: cid, title: albumTitle, status: albumStatus, operationKey: operationKey, fields: savedFields(),
            items: Upload.sel.map(function (it) {
              return { fingerprint: it.fingerprint, name: it.name, size: it.size, itemKey: it.itemKey,
                title: it.title, desc: it.desc, address: it.address, state: it.state, rowId: it.rowId || null };
            })
          }));
        } catch (e) {}
      }
      function syncInput() {
        try {
          var dt = new DataTransfer();
          Upload.sel.forEach(function (item) { if (item.file && item.state !== 'done') dt.items.add(item.file); });
          input.files = dt.files;
        } catch (e) {}
      }
      function discard() {
        Upload.sel.forEach(function (item) { if (item.url) URL.revokeObjectURL(item.url); });
        Upload.sel = [];
        input.value = '';
      }
      function readOnlyTarget() { return !!cid && albumStatus === 'publish' && !CAN_PUBLISH; }
      function controls() {
        var busy = Upload.running || readOnlyTarget();
        input.disabled = busy;
        ['title', 'device', 'tags'].forEach(function (name) { var el = field(name); if (el) el.disabled = busy || !!cid; });
        var address = field('address'); if (address) address.disabled = busy;
        if (submitBtn) { submitBtn.disabled = busy; submitBtn.textContent = readOnlyTarget() ? '已发布图集（只读）' : (busy ? '处理中…' : (cid ? (verified ? '保存新增图片' : '重新核验图集') : '保存草稿并上传')); }
        if (retryBtn) { retryBtn.hidden = !Upload.sel.some(function (it) { return it.state === 'failed'; }); retryBtn.disabled = busy; }
        if (publishBtn) {
          publishBtn.hidden = !CAN_PUBLISH || !cid || albumStatus !== 'draft';
          publishBtn.disabled = busy || !verified || !albumCount || Upload.sel.some(function (it) { return it.state !== 'done'; });
        }
        if (newBtn) { newBtn.disabled = Upload.running; newBtn.hidden = !cid && !Upload.sel.length && !operationKey; }
        if (dropzone) { dropzone.classList.toggle('disabled', busy); dropzone.setAttribute('aria-disabled', String(busy)); }
        if (target) target.textContent = cid ?
          (albumStatus === 'publish' ? (readOnlyTarget() ? '已发布图集（只读）：' : '追加到已发布图集：') : '当前草稿：') + albumTitle + '（#' + cid + '，已保存 ' + albumCount + ' 张）' + (!verified ? '，待核验' : '') + (!CAN_PUBLISH && albumStatus === 'draft' ? '。请编辑或管理员审核发布。' : '') :
          (CAN_PUBLISH ? '新建草稿：图片保存后，点击“发布图集”才会公开。' : '新建草稿：保存后需编辑或管理员审核发布。');
      }
      function summary() {
        var counts = { queued: 0, uploading: 0, converting: 0, done: 0, failed: 0 }, total = 0;
        Upload.sel.forEach(function (it) { counts[it.state]++; total += it.size || 0; });
        var el = $('#pp-upload-summary');
        if (el) el.textContent = Upload.sel.length ? '本次 ' + Upload.sel.length + ' 张（' + humanSize(total) + '）：已保存 ' + counts.done + '，待上传 ' + counts.queued + '，失败 ' + counts.failed : '';
        if (barOuter) barOuter.style.display = Upload.sel.length ? 'flex' : 'none';
        var complete = counts.done + counts.failed;
        if (bar) bar.style.width = (Upload.sel.length ? Math.round((complete + activeProgress) * 100 / Upload.sel.length) : 0) + '%';
        if (barMsg) barMsg.textContent = Upload.running ? '已处理 ' + complete + ' / ' + Upload.sel.length : '已保存 ' + counts.done + ' / ' + Upload.sel.length;
        controls();
      }
      function render() {
        wrap.innerHTML = '';
        Upload.sel.forEach(function (item, idx) {
          var card = document.createElement('div');
          card.className = 'pp-up-item pp-up-' + item.state;
          card.dataset.idx = idx;
          card.dataset.state = item.state;
          var thumb = document.createElement(item.state === 'done' && !item.file ? 'div' : 'img');
          thumb.className = 'pp-up-thumb'; thumb.alt = item.name;
          if (item.state === 'done' && !item.file) { thumb.classList.add('pp-up-saved'); thumb.textContent = '✓ 图片已保存在图集中'; }
          if (item.file && !item.url) { try { item.url = URL.createObjectURL(item.file); } catch (e) {} }
          if (item.url) thumb.src = item.url;
          else thumb.classList.add('pp-up-missing');
          var rm = document.createElement('button');
          rm.type = 'button'; rm.className = 'pp-up-remove'; rm.textContent = '×';
          rm.title = item.state === 'done' ? '已保存图片请在图集内管理' : '从待上传队列移除';
          rm.disabled = Upload.running || item.state === 'done';
          rm.addEventListener('click', function () {
            if (Upload.running || item.state === 'done') return;
            if (item.url) URL.revokeObjectURL(item.url);
            Upload.sel.splice(idx, 1); syncInput(); persist(); render();
          });
          var name = document.createElement('div'); name.className = 'pp-up-name'; name.textContent = item.name;
          var state = document.createElement('div'); state.className = 'pp-up-state'; state.setAttribute('role', 'status');
          state.textContent = labels[item.state] + (item.error ? '：' + item.error : '');
          var tit = document.createElement('input');
          tit.type = 'text'; tit.className = 'pp-up-tit'; tit.placeholder = '图片标题（可选）'; tit.value = item.title;
          var desc = document.createElement('textarea');
          desc.rows = 2; desc.className = 'pp-up-desc'; desc.placeholder = '图片描述（可选）'; desc.value = item.desc;
          tit.disabled = desc.disabled = Upload.running || item.state === 'done' || typeof item.address !== 'undefined';
          tit.addEventListener('input', function () { item.title = tit.value; persist(); });
          desc.addEventListener('input', function () { item.desc = desc.value; persist(); });
          card.appendChild(thumb); card.appendChild(rm); card.appendChild(name); card.appendChild(state); card.appendChild(tit); card.appendChild(desc);
          Upload.bindDrag(card, idx, function () { syncInput(); persist(); render(); });
          wrap.appendChild(card);
        });
        summary();
      }
      function addFiles(files) {
        if (Upload.running || readOnlyTarget()) return;
        Array.prototype.forEach.call(files, function (f) {
          var fingerprint = fileKey(f);
          var existing = Upload.sel.filter(function (it) { return it.fingerprint === fingerprint; })[0];
          if (existing) {
            if (!existing.file && existing.state !== 'done') { existing.file = f; existing.error = '文件已重新选择，请点击“重试失败项”'; }
            return;
          }
          Upload.sel.push({ file: f, fingerprint: fingerprint, name: f.name, size: f.size,
            itemKey: key(), title: '', desc: '', state: 'queued', error: '' });
        });
        syncInput(); persist(); render();
      }
      function verifyTarget() {
        if (!cid) return Promise.resolve();
        return fetch(readURL('album_status', { cid: cid }), { credentials: 'same-origin' })
          .then(function (r) { if (!r.ok) throw new Error('无法核验图集，请稍后重试。'); return r.json(); })
          .then(function (d) {
            if (!d || !d.ok) throw new Error((d && d.msg) || '图集不存在或无权限，请选择其他图集或新建草稿。');
            albumTitle = d.title || albumTitle; albumStatus = d.status || 'draft'; albumCount = Number(d.count) || 0;
            verified = true; persist(); controls();
            if (readOnlyTarget()) throw new Error('已发布图集为只读，追加图片需编辑或管理员权限。');
          });
      }
      function ensureDraft() {
        if (cid) return verified ? Promise.resolve() : verifyTarget();
        operationKey = operationKey || key();
        persist();
        var data = savedFields(); data.operation_key = operationKey;
        return post('create_draft', data).then(function (d) {
          if (!d || !d.ok || !d.cid) throw new Error((d && d.msg) || '草稿保存失败，请重试。');
          cid = String(d.cid); albumTitle = data.title; albumStatus = d.status || 'draft'; verified = true;
          persist(); controls();
        });
      }
      function uploadOne(item) {
        return new Promise(function (resolve) {
          if (!item.file) { item.state = 'failed'; item.error = '请重新选择此文件，浏览器刷新后不会保留文件内容'; persist(); render(); resolve(); return; }
          item.state = 'uploading'; item.error = ''; activeProgress = 0;
          if (typeof item.address === 'undefined') item.address = value('address');
          persist(); render();
          var fd = new FormData();
          fd.set('action', 'upload_image'); fd.set('ajax', '1'); fd.set('cid', cid); fd.set('item_key', item.itemKey);
          fd.set('title', item.title || ''); fd.set('desc', item.desc || ''); fd.set('address', item.address || '');
          if (TOKEN) fd.set('_', TOKEN);
          fd.append('file', item.file, item.name);
          var xhr = new XMLHttpRequest(), settled = false;
          function finish(d) {
            if (settled) return;
            settled = true;
            if (d && d.ok) {
              item.state = 'done'; item.rowId = d.rowId; item.error = '';
              if (!d.replayed) albumCount++;
            } else {
              item.state = 'failed';
              item.error = (d && d.msg) || '网络或上传出错，请重试；同一图片不会重复保存';
            }
            activeProgress = 0; persist(); render(); resolve();
          }
          xhr.open('POST', ENDPOINT, true); xhr.withCredentials = true;
          // 超时后的保存结果不确定：重试时保留原幂等键。
          xhr.timeout = 180000;
          xhr.upload.onprogress = function (ev) {
            if (settled || !ev.lengthComputable) return;
            activeProgress = Math.min(0.95, ev.loaded / ev.total * 0.95); summary();
            if (ev.loaded >= ev.total && item.state === 'uploading') { item.state = 'converting'; persist(); render(); }
          };
          xhr.upload.onload = function () { if (!settled) { item.state = 'converting'; persist(); render(); } };
          xhr.onload = function () {
            var d; try { d = JSON.parse(xhr.responseText); } catch (e) { d = { ok: false, msg: '服务器响应异常，请重试此项' }; }
            if (xhr.status < 200 || xhr.status >= 300) d = { ok: false, msg: (d && d.msg) || '上传请求失败，请重试' };
            finish(d);
          };
          xhr.onerror = xhr.onabort = function () { finish(null); };
          xhr.ontimeout = function () { finish({ ok: false, msg: '请求超时，保存结果未确认，请重试此项' }); };
          try { xhr.send(fd); } catch (e) { finish(null); }
        });
      }
      function run(onlyFailed) {
        if (Upload.running) return;
        if (readOnlyTarget()) { notice('已发布图集为只读，请新建草稿或联系编辑 / 管理员。', 'error'); return; }
        var queue = Upload.sel.filter(function (it) { return it.state === (onlyFailed ? 'failed' : 'queued'); });
        if (!queue.length && cid && !verified) {
          Upload.running = true; render();
          verifyTarget().then(function () { notice('图集状态已更新。', 'success'); })
            .catch(function (err) { notice(err.message, 'error'); })
            .then(function () { Upload.running = false; render(); });
          return;
        }
        if (!queue.length) { notice(onlyFailed ? '没有失败项需要重试。' : '请先选择图片；失败项请使用“重试失败项”。', 'error'); return; }
        if (!cid && !value('title').trim()) { notice('请填写图集标题。', 'error'); return; }
        if (queue.some(function (it) { return !it.file; })) { notice('请重新选择未完成的原文件，再重试失败项。已保存图片仍在图集中。', 'error'); return; }
        Upload.running = true; render();
        ensureDraft().then(function () {
          return queue.reduce(function (previous, item) { return previous.then(function () { return uploadOne(item); }); }, Promise.resolve());
        }).then(function () {
          // 根据服务器持久化状态核对未确认响应及幂等重试结果。
          return verifyTarget().catch(function () { verified = false; });
        }).then(function () {
          var failed = Upload.sel.filter(function (it) { return it.state === 'failed'; }).length;
          notice(!verified ? '上传已结束，但图集状态核验失败。已保存图片不会丢失，请点击“重新核验图集”。' : (failed ? '已保留成功图片，' + failed + ' 项失败，请重试失败项。' : (albumStatus === 'draft' ? (CAN_PUBLISH ? '图片已保存为草稿，确认后点击“发布图集”。' : '图片已保存为草稿，请编辑或管理员审核发布。') : '图片已追加到已发布图集。')), (!verified || failed) ? 'error' : 'success');
          refreshAlbums();
        }).catch(function (err) { notice(err.message || '草稿保存失败，请重试。', 'error'); })
          .then(function () { Upload.running = false; activeProgress = 0; persist(); render(); });
      }
      input.addEventListener('change', function () { addFiles(input.files); });
      ['title', 'device', 'tags', 'address'].forEach(function (name) { var el = field(name); if (el) el.addEventListener('input', persist); });
      if (dropzone) {
        ['dragenter', 'dragover'].forEach(function (event) { dropzone.addEventListener(event, function (e) { e.preventDefault(); if (!Upload.running && !readOnlyTarget()) dropzone.classList.add('over'); }); });
        ['dragleave', 'drop'].forEach(function (event) { dropzone.addEventListener(event, function (e) { e.preventDefault(); dropzone.classList.remove('over'); }); });
        dropzone.addEventListener('drop', function (e) { if (e.dataTransfer && e.dataTransfer.files) addFiles(e.dataTransfer.files); });
        dropzone.addEventListener('click', function () { if (!Upload.running && !readOnlyTarget()) input.click(); });
      }
      form.addEventListener('submit', function (e) { e.preventDefault(); run(false); });
      if (retryBtn) retryBtn.addEventListener('click', function () { run(true); });
      if (publishBtn) publishBtn.addEventListener('click', function () {
        if (!CAN_PUBLISH || Upload.running || !cid || albumStatus !== 'draft') return;
        if (Upload.sel.some(function (it) { return it.state !== 'done'; })) { notice('请先处理未完成项，或将其从队列移除。', 'error'); return; }
        Upload.running = true; render();
        verifyTarget().then(function () {
          if (!albumCount) throw new Error('草稿中还没有图片，请先上传。');
          return post('publish_album', { cid: cid });
        }).then(function (d) {
          if (!d || !d.ok) throw new Error((d && d.msg) || '发布失败，请重试。');
          albumStatus = 'publish'; persist(); notice(d.msg || '图集已发布。', 'success'); refreshAlbums();
        }).catch(function (err) { notice(err.message || '发布请求未确认，请刷新图集状态后重试。', 'error'); })
          .then(function () { Upload.running = false; render(); });
      });
      function resetTarget() {
        discard(); cid = ''; albumTitle = ''; albumCount = 0; albumStatus = 'draft'; verified = false; operationKey = '';
        form.reset();
        ['title', 'device', 'tags', 'address'].forEach(function (name) { var el = field(name); if (el) el.value = ''; });
        persist(); render();
      }
      if (newBtn) newBtn.addEventListener('click', function () {
        if (Upload.running) return;
        if (Upload.sel.some(function (it) { return it.state !== 'done'; }) && !confirm('清空当前未完成的上传队列并新建草稿？已保存的图片和草稿会保留。')) return;
        resetTarget();
      });
      Upload.append = function (nextCid, title) {
        if (Upload.running) { notice('请等待当前上传完成后再切换图集。', 'error'); return; }
        if (cid && cid !== String(nextCid)) {
          if (Upload.sel.some(function (it) { return it.state !== 'done'; }) && !confirm('切换图集会清空当前未完成的队列，已保存内容会保留。继续？')) return;
          discard();
        }
        cid = String(nextCid); albumTitle = title || ''; albumCount = 0; verified = false; operationKey = '';
        var titleField = field('title'); if (titleField) titleField.value = albumTitle;
        Upload.running = true; persist(); render();
        var tab = document.querySelector('.pp-tab[data-tab="upload"]'); if (tab) tab.click();
        verifyTarget().catch(function (err) { notice(err.message, 'error'); })
          .then(function () { Upload.running = false; render(); });
      };
      window.addEventListener('beforeunload', function (e) {
        persist();
        if (Upload.running || Upload.sel.some(function (it) { return it.state !== 'done'; })) { e.preventDefault(); e.returnValue = ''; }
      });
      try {
        var stored = JSON.parse(window.sessionStorage.getItem(storageKey) || 'null');
        if (stored && typeof stored === 'object' && (!initialTarget || String(initialTarget.cid) === String(stored.cid))) {
          cid = /^\d+$/.test(String(stored.cid)) ? String(stored.cid) : '';
          operationKey = typeof stored.operationKey === 'string' ? stored.operationKey : '';
          albumTitle = stored.title || ''; albumStatus = stored.status || 'draft';
          ['title', 'device', 'tags', 'address'].forEach(function (name) { var el = field(name); if (el && stored.fields && typeof stored.fields[name] === 'string') el.value = stored.fields[name]; });
          (Array.isArray(stored.items) ? stored.items : []).forEach(function (it) {
            if (!it.itemKey || !it.fingerprint) return;
            Upload.sel.push({ fingerprint: it.fingerprint, name: it.name, size: it.size, itemKey: it.itemKey,
              title: it.title || '', desc: it.desc || '', address: it.address, rowId: it.rowId, state: it.state === 'done' ? 'done' : 'failed', error: it.state === 'done' ? '' : '请重新选择原文件后重试' });
          });
        }
      } catch (e) {}
      if (initialTarget && initialTarget.cid) {
        cid = String(initialTarget.cid); albumTitle = initialTarget.title || ''; albumStatus = initialTarget.status || 'draft';
        albumCount = Number(initialTarget.count) || 0; verified = false; operationKey = '';
        var initialTitle = field('title'); if (initialTitle) initialTitle.value = albumTitle;
      }
      render();
      if (cid) {
        Upload.running = true; render();
        verifyTarget().then(function () {
          notice('已恢复图集“' + albumTitle + '”，服务器已保存 ' + albumCount + ' 张。' + (Upload.sel.some(function (it) { return it.state !== 'done'; }) ? '未完成项需重新选择原文件；浏览器不会保留文件内容。' : '可在图集列表查看已保存图片，或继续追加。'), 'success');
        }).catch(function (err) { notice(err.message, 'error'); })
          .then(function () { Upload.running = false; render(); });
      } else if (Upload.sel.length) notice('已恢复未完成项的信息，请重新选择原文件并点击“重试失败项”。', 'error');
    },
    bindDrag: function (card, idx, rerender) {
      card.draggable = false;
      function movable() { return !Upload.running && Upload.sel[idx] && Upload.sel[idx].state !== 'done'; }
      card.addEventListener('mousedown', function (e) { card.draggable = movable() && !(e.target && e.target.closest && e.target.closest('input, textarea, button, a, select')); });
      card.addEventListener('dragstart', function (e) {
        if (!movable()) { e.preventDefault(); return; }
        e.dataTransfer.setData('text/plain', String(idx)); e.dataTransfer.effectAllowed = 'move'; card.classList.add('dragging');
      });
      card.addEventListener('dragend', function () { card.classList.remove('dragging'); card.draggable = false; });
      card.addEventListener('dragover', function (e) { if (movable()) { e.preventDefault(); card.classList.add('drop-target'); } });
      card.addEventListener('dragleave', function () { card.classList.remove('drop-target'); });
      card.addEventListener('drop', function (e) {
        e.preventDefault(); card.classList.remove('drop-target');
        if (!movable() || !e.dataTransfer) return;
        var from = parseInt(e.dataTransfer.getData('text/plain'), 10);
        if (isNaN(from) || from < 0 || from >= Upload.sel.length || from === idx || Upload.sel[from].state === 'done') return;
        Upload.sel.splice(idx, 0, Upload.sel.splice(from, 1)[0]); rerender();
      });
    }
  };

  /* ---------- 已发布图集 ---------- */
  var Albums = {
    bind: function () {
      // 详情请求失败时保持可重试状态，不将图集标记为已加载。
      $$('details.pp-album').forEach(function (d) {
        if (d.__ppLazyBound) return;
        d.__ppLazyBound = true;
        function load() {
          if (!d.open) return;
          var box = d.querySelector('.pp-thumbs[data-cid]');
          if (!box || box.getAttribute('data-loaded') === '1' || box.__ppLoading) return;
          box.__ppLoading = true;
          box.setAttribute('aria-busy', 'true');
          fetch(readURL('album_images', { cid: box.getAttribute('data-cid') }), { credentials: 'same-origin' })
            .then(function (r) { if (!r.ok) throw new Error('detail'); return r.text(); })
            .then(function (html) { box.innerHTML = html; box.setAttribute('data-loaded', '1'); box.__ppSortBound = false; Albums.bind(); })
            .catch(function () {
              box.setAttribute('data-loaded', '0'); box.innerHTML = '';
              var retry = document.createElement('button');
              retry.type = 'button'; retry.className = 'pp-btn gray pp-small'; retry.textContent = '加载失败，点击重试';
              retry.addEventListener('click', load); box.appendChild(retry);
            }).then(function () { box.__ppLoading = false; box.setAttribute('aria-busy', 'false'); });
        }
        d.addEventListener('toggle', load);
        if (d.open) load();
      });
      $$('[data-append-cid]').forEach(function (button) {
        if (button.__ppAppendBound) return;
        button.__ppAppendBound = true;
        button.addEventListener('click', function (e) {
          e.preventDefault(); Upload.append(button.getAttribute('data-append-cid'), button.getAttribute('data-append-title'));
        });
      });
      $$('.pp-thumbs').forEach(function (box) {
        if (box.__ppSortBound || !box.querySelector('.pp-img')) return;
        box.__ppSortBound = true;
        Albums.bindSort(box);
      });
    },
    bindSort: function (box) {
      var dragging = null;
      $$('.pp-img', box).forEach(function (card) {
        card.draggable = false;
        card.addEventListener('mousedown', function (e) {
          card.draggable = !(e.target && e.target.closest && e.target.closest('input, textarea, button, a, select'));
        });
        card.addEventListener('dragstart', function () {
          dragging = card; card.classList.add('dragging');
        });
        card.addEventListener('dragend', function () { card.classList.remove('dragging'); card.draggable = false; dragging = null; });
        card.addEventListener('dragover', function (e) { e.preventDefault(); if (card !== dragging) card.classList.add('drop-target'); });
        card.addEventListener('dragleave', function () { card.classList.remove('drop-target'); });
        card.addEventListener('drop', function (e) {
          e.preventDefault();
          card.classList.remove('drop-target');
          if (!dragging || dragging === card) return;
          var rect = card.getBoundingClientRect();
          var after = (e.clientX - rect.left) > rect.width / 2;
          box.insertBefore(dragging, after ? card.nextSibling : card);
          Albums.save(box);
        });
      });
    },
    save: function (box) {
      // 每张卡片有两个 form（编辑/删除）都带 rowId，这里每张只取第一个，避免重复
      var ids = $$('.pp-img', box).map(function (card) {
        var el = card.querySelector('input[name="rowId"]');
        return el ? el.value : '';
      }).filter(Boolean);
      if (!ids.length) return;
      var details = box.closest('details.pp-album');
      var cidEl = details ? details.querySelector('form.pp-delete-album input[name="cid"]') : null;
      var cid = cidEl ? cidEl.value : '';
      post('sort_images', { cid: cid, rowIds: ids }).then(function (d) {
        notice((d && d.msg) ? d.msg : (d && d.ok ? '已保存顺序' : '保存顺序失败'), (d && d.ok) ? 'success' : 'error');
      }).catch(function () { notice('保存顺序失败', 'error'); });
    }
  };

  /* ---------- 联系方式 ---------- */
  var PP_ICONS = [
    ['icon-shouye', '主页'], ['icon-weibo', '微博'], ['icon-github', 'GitHub'], ['icon-gengduo', '更多'],
    ['icon-map-pin-2-line', '地点'], ['icon-camera-lens-line', '相机'], ['icon-time-line', '时间'], ['icon-quanping', '全屏']
  ];
  function setupIconPicker(box) {
    if (!box || box.__ppIcons) return;
    box.__ppIcons = true;
    var hidden = box.querySelector('input[name="contactIcon[]"]');
    var trig = box.querySelector('.pp-icon-trigger');
    var pop = box.querySelector('.pp-icon-pop');
    if (!hidden || !trig || !pop) return;
    PP_ICONS.forEach(function (pair) {
      var b = document.createElement('button');
      b.type = 'button'; b.className = 'icn'; b.dataset.icon = pair[0]; b.title = pair[1];
      b.innerHTML = '<i class="iconfont ' + pair[0] + '"></i>';
      pop.appendChild(b);
    });
    function sync() {
      $$('.icn', pop).forEach(function (b) { b.classList.toggle('active', b.dataset.icon === hidden.value); });
    }
    trig.addEventListener('click', function (e) { e.stopPropagation(); if (pop.classList.toggle('open')) sync(); });
    $$('.icn', pop).forEach(function (b) {
      b.addEventListener('click', function (e) {
        e.stopPropagation();
        hidden.value = b.dataset.icon;
        trig.dataset.icon = b.dataset.icon;
        var i = trig.querySelector('i');
        if (i) i.className = 'iconfont ' + b.dataset.icon;
        pop.classList.remove('open');
        sync();
      });
    });
    sync();
  }
  function addContactRow() {
    var empty = $('#pp-contact-empty');
    if (empty) empty.parentNode.removeChild(empty);
    var row = document.createElement('div');
    row.className = 'pp-contact-row';
    row.innerHTML =
      '<input type="text" name="contactName[]" placeholder="名称（如 微博）">' +
      '<input type="url" name="contactUrl[]" placeholder="链接">' +
      '<div class="pp-contact-icon">' +
        '<input type="hidden" name="contactIcon[]" value="icon-github">' +
        '<button type="button" class="pp-icon-trigger" data-icon="icon-github" title="选择图标"><i class="iconfont icon-github"></i></button>' +
        '<div class="pp-icon-pop"></div>' +
      '</div>' +
      '<select name="contactStatus[]"><option value="1" selected>启用</option><option value="0">停用</option></select>' +
      '<button type="button" class="pp-btn red pp-small pp-remove-contact">删除</button>';
    $('#pp-contact-rows').appendChild(row);
    setupIconPicker(row.querySelector('.pp-contact-icon'));
  }

  /* ---------- 维护任务 ---------- */
  var runningJobs = {};
  function runJob(job, resume) {
    if (runningJobs[job]) return;
    runningJobs[job] = true;
    var bar = $('#pp-bar-' + job);
    var msg = $('#pp-msg-' + job);
    var btn = document.querySelector('[data-run="' + job + '"]');
    if (btn) btn.disabled = true;
    if (bar) bar.style.width = '0%'; // 每次开始先归零，避免上一次的 100% 残留
    if (msg) msg.textContent = '准备中…';
    var mode = resume ? 'resume' : 'start';
    var jobId = resume && JOB ? (JOB.job_id || '') : '';
    function tick() {
      post('maintenance', { job: job, mode: mode, job_id: jobId })
        .then(function (d) {
          if (!d || d.ok === false) throw new Error((d && d.msg) || '出错，请重试');
          if (d.msg && d.finished) { if (msg) msg.textContent = d.msg; if (btn) btn.disabled = false; runningJobs[job] = false; return; }
          jobId = d.job_id || jobId;
          mode = 'poll';
          var pct = d.total ? Math.round(d.done * 100 / d.total) : 100;
          if (bar) bar.style.width = pct + '%';
          if (d.total > 0) {
            if (msg) msg.textContent = d.done + ' / ' + d.total + (d.current ? ' — ' + d.current : '') + (d.failed > 0 ? '（失败 ' + d.failed + '）' : '');
          } else {
            var noJob = { cleanup: '没有需要清理的孤儿文件', rebuild: '没有需要重建的图片', resync: '没有需要重建字段的图集' };
            if (msg) msg.textContent = noJob[job] || '没有需要处理的项目';
          }
          if (!d.finished) { setTimeout(tick, 300); return; }
          if (msg) msg.textContent += d.failed > 0 ? (' ✓ 完成（' + d.failed + ' 项失败，请查看日志）') : ' ✓ 完成';
          if (btn) btn.disabled = false;
          runningJobs[job] = false;
        })
        .catch(function (err) {
          if (msg) msg.textContent = err.message || '出错，请重试';
          if (btn) btn.disabled = false;
          runningJobs[job] = false;
        });
    }
    tick();
  }

  /* ---------- 初始化 ---------- */
  document.addEventListener('DOMContentLoaded', function () {
    Tabs.init();
    Upload.init();
    Albums.bind();

    $$('[data-run]').forEach(function (b) {
      b.addEventListener('click', function () { runJob(b.getAttribute('data-run')); });
    });

    // 联系方式
    var addBtn = $('#pp-add-contact');
    if (addBtn) addBtn.addEventListener('click', addContactRow);
    document.addEventListener('click', function (e) {
      if (e.target && e.target.classList && e.target.classList.contains('pp-remove-contact')) {
        var row = e.target.closest('.pp-contact-row');
        if (row) row.parentNode.removeChild(row);
      }
      $$('.pp-icon-pop.open').forEach(function (p) { p.classList.remove('open'); });
    });
    $$('.pp-contact-icon').forEach(setupIconPicker);

    // 质量滑块
    $$('.pp-range').forEach(function (r) {
      var out = document.querySelector('output[for="' + r.id + '"]');
      if (!out) return;
      var sync = function () { out.textContent = r.value; };
      r.addEventListener('input', sync);
      sync();
    });

    // 恢复默认最佳设置：走 AJAX（原生 form.submit() 不会触发 submit 监听）
    var resetBtn = $('#pp-reset-webp');
    if (resetBtn) {
      resetBtn.addEventListener('click', function () {
        var f = resetBtn.closest('form');
        if (!f) return;
        var D = CFG.defaults || {};
        var set = function (name, val) { var el = f.querySelector('[name="' + name + '"]'); if (el) el.value = val; };
        set('quality', String(D.quality)); set('thumbMax', String(D.thumbMax)); set('maxWidth', String(D.maxWidth));
        set('keepOriginal', String(D.keepOriginal)); set('fullQuality', String(D.fullQuality));
        set('panoWidth', String(D.panoWidth)); set('panoQuality', String(D.panoQuality));
        $$('.pp-range', f).forEach(function (r) {
          var out = document.querySelector('output[for="' + r.id + '"]');
          if (out) out.textContent = r.value;
        });
        f.dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }));
      });
    }

    // 清理非插件文章
    var cleanPosts = $('#pp-clean-posts');
    if (cleanPosts) {
      cleanPosts.addEventListener('click', function () {
        cleanPosts.disabled = true;
        post('preview_non_plugin', {}).then(function (d) {
          cleanPosts.disabled = false;
          if (d && d.ok && d.count > 0) {
            var titleStr = (d.titles && d.titles.length) ? '（如：' + d.titles.join('、') + (d.count > d.titles.length ? '…' : '') + '）' : '';
            if (confirm('将删除 ' + d.count + ' 篇不是 InfinityTime 发布的文章' + titleStr + '。确认删除？此操作不可恢复！')) {
              cleanPosts.disabled = true;
              post('delete_non_plugin', {}).then(function (r2) {
                cleanPosts.disabled = false;
                notice((r2 && r2.ok) ? ('已清理 ' + (r2.deleted || 0) + ' 篇非插件文章') : '清理失败', (r2 && r2.ok) ? 'success' : 'error');
              }).catch(function () { cleanPosts.disabled = false; notice('清理出错，请重试', 'error'); });
            }
          } else if (d && d.ok) {
            notice('没有发现非插件文章', 'success');
          } else {
            notice((d && d.msg) ? d.msg : '查询失败', 'error');
          }
        }).catch(function () { cleanPosts.disabled = false; notice('查询出错，请重试', 'error'); });
      });
    }

    // 删除图集：事件委托，局部刷新后仍生效
    document.addEventListener('submit', function (e) {
      var form = e.target && e.target.closest ? e.target.closest('form.pp-delete-album') : null;
      if (!form) return;
      e.preventDefault();
      if (!confirm('删除整组图集及其文件？')) return;
      var btn = form.querySelector('button[type="submit"]');
      if (btn) { btn.disabled = true; if (btn.dataset.loading) btn.textContent = btn.dataset.loading; }
      var fd = new FormData(form);
      post('', fd).then(function (d) {
        if (d && d.ok) {
          var details = form.closest('details.pp-album');
          if (details && details.parentNode) details.parentNode.removeChild(details);
          var card = $('#pp-albums-card');
          if (card && !card.querySelector('details.pp-album') && card.textContent.indexOf('暂无图集') === -1) {
            var empty = document.createElement('div');
            empty.className = 'pp-meta';
            empty.textContent = '暂无图集。';
            var h2 = card.querySelector('h2');
            if (h2 && h2.nextSibling) card.insertBefore(empty, h2.nextSibling); else card.appendChild(empty);
          }
          notice((d && d.msg) ? d.msg : '已删除', 'success');
          refreshAlbums();
        } else {
          if (btn) { btn.disabled = false; if (btn.dataset.loading) btn.textContent = '删除'; }
          notice((d && d.msg) ? d.msg : '删除失败', 'error');
        }
      }).catch(function () {
        if (btn) { btn.disabled = false; if (btn.dataset.loading) btn.textContent = '删除'; }
        notice('删除失败，请重试', 'error');
      });
    });

    // 各表单保存：AJAX
    document.addEventListener('submit', function (e) {
      var form = e.target && e.target.closest ? e.target.closest('form') : null;
      if (!form || form.id === 'pp-upload-form' || form.classList.contains('pp-delete-album')) return;
      var actEl = form.querySelector('input[name="action"]');
      var act = actEl ? actEl.value : '';
      var actions = ['save_site', 'save_contacts', 'save_settings', 'update_album', 'set_image_meta', 'delete_image', 'publish_album'];
      if (actions.indexOf(act) === -1) return;
      if (act === 'publish_album' && !CAN_PUBLISH) { e.preventDefault(); notice('发布图集需编辑或管理员权限。', 'error'); return; }
      if (act === 'publish_album' && Upload.running) { e.preventDefault(); notice('请等待当前上传完成后再发布。', 'error'); return; }
      if (form.__ppSaving) { e.preventDefault(); return; }
      if (act === 'delete_image' && !confirm('删除这张图片及其文件？')) { e.preventDefault(); return; }
      e.preventDefault();
      form.__ppSaving = true;
      var btn = form.querySelector('button[type="submit"]');
      if (btn) btn.disabled = true;
      var fd = new FormData(form);
      post('', fd).then(function (d) {
        if (d && d.ok) {
          if (act === 'publish_album') {
            refreshAlbums();
          } else if (act === 'delete_image') {
            var pic = form.closest('.pp-img');
            if (pic && pic.parentNode) pic.parentNode.removeChild(pic);
          } else if (act === 'update_album') {
            var ab = form.closest('.pp-album');
            var t = form.querySelector('[name="title"]');
            var titleEl = ab ? ab.querySelector('summary strong') : null;
            if (titleEl && t) titleEl.textContent = t.value;
          }
        }
        notice((d && d.msg) ? d.msg : '已保存', (d && d.ok) ? 'success' : 'error');
        if (btn) btn.disabled = false;
        form.__ppSaving = false;
      }).catch(function () { notice('网络/保存出错，请重试', 'error'); if (btn) btn.disabled = false; form.__ppSaving = false; });
    });

    // 上次维护任务未完成：提示可继续
    if (JOB && JOB.job && JOB.total > 0 && !JOB.finished) {
      var names = { rebuild: '重建缩略图/全图', cleanup: '清理孤儿文件', resync: '重建尺寸字段' };
      var tip = document.createElement('div');
      tip.className = 'notice success';
      tip.style.margin = '12px 0';
      var jobName = names[JOB.job] || String(JOB.job || '').replace(/[<>&"]/g, '');
      tip.innerHTML = '上次的「' + jobName + '」任务未完成（' + (parseInt(JOB.done, 10) || 0) + ' / ' + (parseInt(JOB.total, 10) || 0) + '）。<button type="button" class="pp-btn pp-small" style="margin-left:8px">继续</button>';
      var container = $('.container.typecho-page-main');
      if (container) container.insertBefore(tip, container.firstElementChild);
      tip.querySelector('button').addEventListener('click', function () {
        tip.parentNode.removeChild(tip);
        runJob(JOB.job, true);
      });
    }
  });
})();

