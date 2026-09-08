/* ============================================================
   InfinityTime 后台面板脚本
   依赖 panel.php 注入的 window.PP_ADMIN（url / token / defaults / job）
   ============================================================ */
(function () {
  'use strict';

  var CFG = window.PP_ADMIN || {};
  var URL = CFG.url || '';
  var TOKEN = CFG.token || '';
  var JOB = CFG.job || null;
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
    return fetch(URL, { method: 'POST', body: fd, credentials: 'same-origin' })
      .then(function (r) { return r.json().catch(function () { return { ok: false, msg: '服务器返回异常' }; }); });
  }

  function refreshAlbums() {
    fetch(URL + (URL.indexOf('?') === -1 ? '?' : '&') + 'ajax=1&job=albums_html', { credentials: 'same-origin' })
      .then(function (r) { return r.text(); })
      .then(function (html) {
        var fresh = new DOMParser().parseFromString(html, 'text/html').querySelector('#pp-albums-card');
        var cur = $('#pp-albums-card');
        if (fresh && cur) {
          cur.outerHTML = fresh.outerHTML;
          Albums.bind();
        }
      })
      .catch(function () {});
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

  /* ---------- 上传发布 ---------- */
  var Upload = {
    sel: [],
    init: function () {
      var form = $('#pp-upload-form');
      var input = $('#pp-files-input');
      var wrap = $('#pp-upload-previews');
      if (!form || !input || !wrap) return;
      var submitBtn = form.querySelector('button[type=submit]');
      var dropzone = $('#pp-dropzone');
      var bar = $('#pp-upload-bar');
      var barOuter = $('#pp-upload-progress');

      function fileKey(f) { return f.name + '|' + f.size + '|' + (f.lastModified || 0); }

      function syncInput() {
        try {
          var dt = new DataTransfer();
          Upload.sel.forEach(function (item) { dt.items.add(item.file); });
          input.files = dt.files;
        } catch (e) {}
      }

      function humanSize(n) {
        if (!n) return '0 B';
        var u = ['B', 'KB', 'MB', 'GB'], i = 0;
        while (n >= 1024 && i < u.length - 1) { n /= 1024; i++; }
        return n.toFixed(i ? 1 : 0) + ' ' + u[i];
      }

      function updateSummary() {
        var el = $('#pp-upload-summary');
        if (!el) return;
        if (!Upload.sel.length) { el.textContent = ''; return; }
        var total = Upload.sel.reduce(function (s, it) { return s + (it.file.size || 0); }, 0);
        el.textContent = '已选 ' + Upload.sel.length + ' 张，共 ' + humanSize(total);
      }

      function render() {
        // 回收旧预览图的 object URL，避免反复增删造成内存累积
        $$('.pp-up-thumb', wrap).forEach(function (img) {
          if (img.dataset.objectUrl) { try { URL.revokeObjectURL(img.dataset.objectUrl); } catch (e) {} }
        });
        wrap.innerHTML = '';
        Upload.sel.forEach(function (item, idx) {
          var card = document.createElement('div');
          card.className = 'pp-up-item';
          card.dataset.idx = idx;

          var thumb = document.createElement('img');
          thumb.className = 'pp-up-thumb';
          thumb.alt = item.file.name;
          var url = '';
          try { url = URL.createObjectURL(item.file); } catch (e) {}
          if (url) { thumb.dataset.objectUrl = url; thumb.src = url; }

          var rm = document.createElement('button');
          rm.type = 'button';
          rm.className = 'pp-up-remove';
          rm.textContent = '×';
          rm.title = '移除这张图片';
          rm.addEventListener('click', function () {
            Upload.sel.splice(idx, 1);
            syncInput(); render(); updateSummary();
          });

          var name = document.createElement('div');
          name.className = 'pp-up-name';
          name.textContent = item.file.name;

          var tit = document.createElement('input');
          tit.type = 'text'; tit.className = 'pp-up-tit'; tit.placeholder = '图片标题（可选）'; tit.value = item.title;
          tit.addEventListener('input', function () { item.title = tit.value; card.classList.add('touched'); });

          var desc = document.createElement('textarea');
          desc.rows = 2; desc.className = 'pp-up-desc'; desc.placeholder = '图片描述（可选）'; desc.value = item.desc;
          desc.addEventListener('input', function () { item.desc = desc.value; card.classList.add('touched'); });

          card.appendChild(thumb); card.appendChild(rm); card.appendChild(name); card.appendChild(tit); card.appendChild(desc);
          Upload.bindDrag(card, idx, render);
          wrap.appendChild(card);
        });
        updateSummary();
      }

      function addFiles(files) {
        var existing = {};
        Upload.sel.forEach(function (item) { existing[fileKey(item.file)] = true; });
        Array.prototype.forEach.call(files, function (f) {
          var k = fileKey(f);
          if (existing[k]) return;
          existing[k] = true;
          Upload.sel.push({ file: f, title: '', desc: '' });
        });
        syncInput(); render();
      }

      input.addEventListener('change', function () { addFiles(input.files); });

      if (dropzone) {
        ['dragenter', 'dragover'].forEach(function (ev) {
          dropzone.addEventListener(ev, function (e) { e.preventDefault(); dropzone.classList.add('over'); });
        });
        ['dragleave', 'drop'].forEach(function (ev) {
          dropzone.addEventListener(ev, function (e) { e.preventDefault(); dropzone.classList.remove('over'); });
        });
        dropzone.addEventListener('drop', function (e) {
          if (e.dataTransfer && e.dataTransfer.files && e.dataTransfer.files.length) addFiles(e.dataTransfer.files);
        });
        dropzone.addEventListener('click', function () { input.click(); });
      }

      form.addEventListener('submit', function (e) {
        e.preventDefault();
        if (!Upload.sel.length) { notice('请至少选择一张图片', 'error'); return; }
        $$('input[name="img_titles[]"], textarea[name="img_descs[]"]', form).forEach(function (el) { el.parentNode.removeChild(el); });
        Upload.sel.forEach(function (item) {
          var t = document.createElement('input');
          t.type = 'hidden'; t.name = 'img_titles[]'; t.value = item.title || '';
          form.appendChild(t);
          var d = document.createElement('textarea');
          d.name = 'img_descs[]'; d.value = item.desc || ''; d.style.display = 'none';
          form.appendChild(d);
        });
        syncInput();
        if (submitBtn) { submitBtn.disabled = true; submitBtn.textContent = '发布中…'; }
        if (barOuter) barOuter.style.display = 'flex';
        if (bar) bar.style.width = '0%';

        var fd = new FormData(form);
        fd.set('ajax', '1');
        if (TOKEN) fd.set('_', TOKEN);
        var xhr = new XMLHttpRequest();
        xhr.open('POST', URL, true);
        xhr.withCredentials = true;
        xhr.upload.onprogress = function (ev) {
          if (ev.lengthComputable && bar) bar.style.width = Math.round(ev.loaded * 100 / ev.total) + '%';
        };
        xhr.onload = function () {
          var d = null;
          try { d = JSON.parse(xhr.responseText); } catch (e) { d = { ok: false, msg: '服务器返回异常' }; }
          if (d && d.ok) {
            notice(d.msg || '已发布图集', 'success');
            Upload.sel.forEach(function (it) { if (it.url) { try { URL.revokeObjectURL(it.url); } catch (e) {} } });
            Upload.sel = [];
            form.reset();
            try { input.value = ''; } catch (e) {}
            render();
            refreshAlbums();
          } else {
            notice((d && d.msg) ? d.msg : '发布失败', 'error');
          }
        };
        xhr.onerror = function () { notice('网络/上传出错，请重试', 'error'); };
        xhr.onloadend = function () {
          if (submitBtn) { submitBtn.disabled = false; submitBtn.textContent = '发布图集'; }
          if (barOuter) setTimeout(function () { barOuter.style.display = 'none'; }, 600);
        };
        xhr.send(fd);
      });

      render();
    },
    // 预览卡片拖拽排序
    bindDrag: function (card, idx, rerender) {
      // 只有从非输入区域按下时才允许拖拽，避免拖动标题/描述时误拖整张卡片
      card.draggable = false;
      card.addEventListener('mousedown', function (e) {
        card.draggable = !(e.target && e.target.closest && e.target.closest('input, textarea, button, a, select'));
      });
      card.addEventListener('dragstart', function (e) {
        e.dataTransfer.setData('text/plain', String(idx));
        e.dataTransfer.effectAllowed = 'move';
        card.classList.add('dragging');
      });
      card.addEventListener('dragend', function () { card.classList.remove('dragging'); });
      card.addEventListener('dragover', function (e) { e.preventDefault(); card.classList.add('drop-target'); });
      card.addEventListener('dragleave', function () { card.classList.remove('drop-target'); });
      card.addEventListener('drop', function (e) {
        e.preventDefault();
        card.classList.remove('drop-target');
        var from = parseInt(e.dataTransfer.getData('text/plain'), 10);
        if (isNaN(from) || from === idx) return;
        var moved = Upload.sel.splice(from, 1)[0];
        Upload.sel.splice(idx, 0, moved);
        rerender();
      });
    }
  };

  /* ---------- 已发布图集 ---------- */
  var Albums = {
    bind: function () {
      // 图集展开时才拉取图片列表（避免图集多时首屏 DOM 过大）
      $$('details.pp-album').forEach(function (d) {
        if (d.__ppLazyBound) return;
        d.__ppLazyBound = true;
        d.addEventListener('toggle', function () {
          if (!d.open) return;
          var box = d.querySelector('.pp-thumbs[data-cid]');
          if (!box || box.getAttribute('data-loaded') === '1') return;
          var cid = box.getAttribute('data-cid');
          box.setAttribute('data-loaded', '1');
          fetch(URL + (URL.indexOf('?') === -1 ? '?' : '&') + 'ajax=1&job=album_images&cid=' + encodeURIComponent(cid), { credentials: 'same-origin' })
            .then(function (r) { return r.text(); })
            .then(function (html) { box.innerHTML = html; Albums.bind(); })
            .catch(function () { box.setAttribute('data-loaded', '0'); box.innerHTML = '<div class="pp-meta">加载失败，请重试</div>'; });
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
  function runJob(job) {
    var bar = $('#pp-bar-' + job);
    var msg = $('#pp-msg-' + job);
    var btn = document.querySelector('[data-run="' + job + '"]');
    if (btn) btn.disabled = true;
    if (bar) bar.style.width = '0%'; // 每次开始先归零，避免上一次的 100% 残留
    if (msg) msg.textContent = '准备中…';
    var url = URL + (URL.indexOf('?') === -1 ? '?' : '&') + 'ajax=1&job=' + job + '&_=' + encodeURIComponent(TOKEN);
    function tick() {
      fetch(url, { credentials: 'same-origin' })
        .then(function (r) { return r.json(); })
        .then(function (d) {
          if (d.msg && d.finished) { if (msg) msg.textContent = d.msg; if (btn) btn.disabled = false; return; }
          var pct = d.total ? Math.round(d.done * 100 / d.total) : 100;
          if (bar) bar.style.width = pct + '%';
          if (d.total > 0) {
            if (msg) msg.textContent = d.done + ' / ' + d.total + (d.current ? ' — ' + d.current : '') + (d.failed > 0 ? '（失败 ' + d.failed + '）' : '');
          } else {
            var noJob = { cleanup: '没有需要清理的孤儿文件', rebuild: '没有需要重建的图片', resync: '没有需要重建字段的图集' };
            if (msg) msg.textContent = noJob[job] || '没有需要处理的项目';
          }
          if (!d.finished) { setTimeout(tick, 300); return; }
          if (msg) msg.textContent += d.failed > 0 ? (' ✓ 完成（' + d.failed + ' 张失败，请查看日志）') : ' ✓ 完成';
          if (btn) btn.disabled = false;
        })
        .catch(function () {
          if (msg) msg.textContent = '出错，请重试';
          if (btn) btn.disabled = false;
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
      var actions = ['save_site', 'save_contacts', 'save_settings', 'update_album', 'set_image_meta', 'delete_image'];
      if (actions.indexOf(act) === -1) return;
      if (act === 'delete_image' && !confirm('删除这张图片及其文件？')) { e.preventDefault(); return; }
      e.preventDefault();
      var btn = form.querySelector('button[type="submit"]');
      if (btn) btn.disabled = true;
      var fd = new FormData(form);
      post('', fd).then(function (d) {
        if (d && d.ok) {
          if (act === 'delete_image') {
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
      }).catch(function () { notice('网络/保存出错，请重试', 'error'); if (btn) btn.disabled = false; });
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
        runJob(JOB.job);
      });
    }
  });
})();
