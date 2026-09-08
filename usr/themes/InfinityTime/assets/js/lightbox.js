/* InfinityTime 灯箱 / EXIF / 全景 / 主题色逻辑（从 index.php 内联脚本外置，便于缓存与压缩） */
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
        let dockShownOnce = false; // 本次灯箱会话里 EXIF 侧栏是否已经显示过（切图加载时保持常驻）
        // 获取（或创建）主图外侧的 EXIF 停靠侧栏
        function getExifDock() {
          if (!exifDock) {
            exifDock = document.createElement('div');
            exifDock.className = 'poptrox-exif-dock';
            exifDock.innerHTML =
              '<div class="exif-dock-handle" role="button" tabindex="0" aria-label="展开或收起拍摄参数">'
              + '<span class="exif-dock-handle-text">拍摄参数</span>'
              + '<span class="exif-dock-arrow" aria-hidden="true"></span>'
              + '</div>'
              + '<div class="exif-imgtitle"></div>'
              + '<div class="exif-imgdesc"></div>'
              + '<div class="exif-title">拍摄参数</div>'
              + '<div class="exif-grid"></div>'
              + '<div class="exif-addr"><i class="iconfont icon-map-pin-2-line"></i><span class="exif-addr-text"></span></div>'
              + '<div class="exif-palette"><div class="exif-title">主题色</div><div class="palette-list"></div>'
              + '<canvas class="hist-canvas" width="240" height="88"></canvas></div>';
            // 移动端抽屉：点/回车把手展开或收起（桌面端把手隐藏，不影响布局）
            var setDockExpanded = function (v) {
              exifDock.classList.toggle('expanded', !!v);
              document.body.classList.toggle('pp-dock-expanded', !!v);
            };
            var toggleDock = function (e) {
              var h = e && e.target && e.target.closest ? e.target.closest('.exif-dock-handle') : null;
              if (h) setDockExpanded(!exifDock.classList.contains('expanded'));
            };
            exifDock.addEventListener('click', toggleDock);
            exifDock.addEventListener('keydown', function (e) {
              if (e.key !== 'Enter' && e.key !== ' ') return;
              var h = e.target && e.target.closest ? e.target.closest('.exif-dock-handle') : null;
              if (h) { e.preventDefault(); setDockExpanded(!exifDock.classList.contains('expanded')); }
            });
            document.body.appendChild(exifDock);
          }
          return exifDock;
        }
        // 移动端底部切图按钮：灯箱打开时显示，EXIF 抽屉展开时隐藏（替代滑动切图）。
        (function () {
          var nav = document.createElement('div');
          nav.className = 'pp-mobile-nav';
          nav.innerHTML =
            '<button type="button" class="pp-mnav-btn pp-mnav-prev" aria-label="上一张">'
            + '<svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M15 5l-7 7 7 7"/></svg>'
            + '</button>'
            + '<button type="button" class="pp-mnav-btn pp-mnav-next" aria-label="下一张">'
            + '<svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 5l7 7-7 7"/></svg>'
            + '</button>';
          document.body.appendChild(nav);
          nav.addEventListener('click', function (e) {
            var btn = e.target && e.target.closest ? e.target.closest('.pp-mnav-btn') : null;
            if (!btn) return;
            e.preventDefault();
            e.stopPropagation();
            var popup = document.querySelector('.poptrox-popup');
            if (!popup) return;
            var sel = btn.classList.contains('pp-mnav-next') ? '.nav-next' : '.nav-previous';
            var target = popup.querySelector(sel);
            if (target) target.click();
          });
          // 按压反馈用 JS 类控制：iOS Safari 上 :active 常会卡住不恢复
          nav.addEventListener('pointerdown', function (e) {
            var btn = e.target && e.target.closest ? e.target.closest('.pp-mnav-btn') : null;
            if (btn) btn.classList.add('is-pressed');
          });
          function ppClearNavPressed() {
            document.querySelectorAll('.pp-mnav-btn.is-pressed').forEach(function (b) { b.classList.remove('is-pressed'); });
          }
          ['pointerup', 'pointercancel', 'touchend', 'touchcancel'].forEach(function (ev) {
            document.addEventListener(ev, ppClearNavPressed, true);
          });
        })();
        // 移动端图片上方标题容器（内容由 renderExif 填充）
        (function () {
          var cap = document.createElement('div');
          cap.className = 'pp-mobile-caption';
          cap.innerHTML = '<div class="pp-cap-title"></div><div class="pp-cap-sub"></div>';
          document.body.appendChild(cap);
        })();
        function rgbToHex(r, g, b) {
          function h(v) {
            var s = Number(v) & 255;
            var x = s.toString(16).toUpperCase();
            return x.length < 2 ? '0' + x : x;
          }
          return '#' + h(r) + h(g) + h(b);
        }
        // 单次采样照片：返回 3 个真实存在的代表性颜色 + RGB 直方图 + 主色（用于氛围光）。
        // 结果按 src 缓存，并合并同一张图的并发请求：来回切图不再重复解码 + 采样。
        var __photoCache = {};   // src -> result
        var __photoPending = {}; // src -> [cb, ...]
        function analyzePhoto(src, cb) {
          if (!src) { cb({ colors: [], hist: null, top: null }); return; }
          if (__photoCache[src]) { cb(__photoCache[src]); return; }
          if (__photoPending[src]) { __photoPending[src].push(cb); return; }
          __photoPending[src] = [cb];
          function finish(res) {
            __photoCache[src] = res;
            var list = __photoPending[src] || [];
            delete __photoPending[src];
            for (var i = 0; i < list.length; i++) { try { list[i](res); } catch (e) {} }
          }
          var img = new Image();
          img.crossOrigin = 'anonymous';
          img.onload = function () {
            try {
              var w = img.naturalWidth, h = img.naturalHeight;
              if (!w || !h) return finish({ colors: [], hist: null, top: null });
              var maxDim = 300;
              var scale = Math.min(1, maxDim / Math.max(w, h));
              var cw = Math.max(1, Math.round(w * scale)), ch = Math.max(1, Math.round(h * scale));
              var c = document.createElement('canvas');
              c.width = cw; c.height = ch;
              var ctx = c.getContext('2d', { willReadFrequently: true });
              ctx.drawImage(img, 0, 0, cw, ch);
              var data = ctx.getImageData(0, 0, cw, ch).data;
              var histR = [], histG = [], histB = [];
              for (var b0 = 0; b0 < 64; b0++) { histR[b0] = 0; histG[b0] = 0; histB[b0] = 0; }
              // 按“色相”分桶：把同一色系的颜色合并（绿色水母、粉色水母分别成桶），
              // 这样小面积的醒目色不会被巨大的背景/剪影淹没；低饱和度(灰/黑)单独压低权重。
              var hueB = {};   // 色相桶 -> { n, satSum, exact:{ek:{r,g,b,c}} }
              var grayB = {};  // dark/mid/light -> { n, exact:{...} }
              for (var i = 0; i < data.length; i += 4) {
                var r = data[i], g = data[i + 1], b = data[i + 2];
                if (data[i + 3] < 128) continue;
                histR[(r >> 2) & 63]++;
                histG[(g >> 2) & 63]++;
                histB[(b >> 2) & 63]++;
                var mx = Math.max(r, g, b), mn = Math.min(r, g, b), diff = mx - mn;
                var sat = mx ? (diff / mx) : 0;
                var hue = 0;
                if (diff > 0) {
                  if (mx === r) hue = 60 * ((g - b) / diff);
                  else if (mx === g) hue = 60 * (((b - r) / diff) + 2);
                  else hue = 60 * (((r - g) / diff) + 4);
                  if (hue < 0) hue += 360;
                }
                var ek = (r << 16) | (g << 8) | b;
                if (sat < 0.14) {
                  var gk = mx < 72 ? 'dark' : (mx > 200 ? 'light' : 'mid');
                  var g0 = grayB[gk] || (grayB[gk] = { n: 0, exact: {} });
                  g0.n++;
                  if (g0.exact[ek]) g0.exact[ek].c++; else g0.exact[ek] = { r: r, g: g, b: b, c: 1 };
                } else {
                  var hb = Math.floor(hue / 15);
                  var o = hueB[hb] || (hueB[hb] = { n: 0, satSum: 0, exact: {} });
                  o.n++; o.satSum += sat;
                  if (o.exact[ek]) o.exact[ek].c++; else o.exact[ek] = { r: r, g: g, b: b, c: 1 };
                }
              }
              function modeOf(exact) {
                var best = null, bc = -1;
                for (var ek in exact) { if (exact[ek].c > bc) { bc = exact[ek].c; best = exact[ek]; } }
                return best;
              }
              // 候选：彩色桶按 “数量 × (0.2 + 平均饱和度²)” 打分（醒目彩色占优），灰色桶仅 0.06 权重
              var cands = [];
              Object.keys(hueB).forEach(function (hb) {
                var o = hueB[hb];
                if (!o.n) return;
                var m = modeOf(o.exact);
                if (!m) return;
                var avgSat = o.satSum / o.n;
                cands.push({ r: m.r, g: m.g, b: m.b, score: o.n * (0.2 + avgSat * avgSat), n: o.n });
              });
              Object.keys(grayB).forEach(function (gk) {
                var g0 = grayB[gk];
                if (!g0.n) return;
                var m = modeOf(g0.exact);
                if (!m) return;
                cands.push({ r: m.r, g: m.g, b: m.b, score: g0.n * 0.06, n: g0.n });
              });
              cands.sort(function (a, b) { return b.score - a.score; });
              // 只在“足够显著”的颜色里做“最大最小距离”挑选，避免选到极小噪点；
              // 选满 3 个，颜色尽量分散，照片色够多时不会出现两个很接近的色。
              var pool = cands.slice(0, 15);
              var picked = [];
              if (pool.length) {
                picked.push(pool[0]);
                while (picked.length < 3) {
                  var selected = null, bestMin = -1;
                  for (var ci = 0; ci < pool.length; ci++) {
                    var ci2 = pool[ci];
                    var already = picked.indexOf(ci2) >= 0;
                    if (already) continue;
                    var minD = 765;
                    for (var pi = 0; pi < picked.length; pi++) {
                      var d = Math.abs(ci2.r - picked[pi].r) + Math.abs(ci2.g - picked[pi].g) + Math.abs(ci2.b - picked[pi].b);
                      if (d < minD) minD = d;
                    }
                    if (minD > bestMin) { bestMin = minD; selected = ci2; }
                  }
                  if (!selected) break;
                  picked.push(selected);
                }
              }
              finish({
                colors: picked.slice(0, 3).map(function (e) { return rgbToHex(e.r, e.g, e.b); }),
                hist: [histR, histG, histB]
              });
            } catch (e) { finish({ colors: [], hist: null, top: null }); }
          };
          img.onerror = function () { finish({ colors: [], hist: null, top: null }); };
          img.src = src;
        }
        // 画 RGB 直方图（三条半透明色带）
        function drawHistogram(cv, hist) {
          try {
            if (!cv || !hist || !hist.length || !hist[0] || !hist[0].length) return;
            // 按实际显示尺寸 × 设备像素比渲染，避免被 CSS 拉伸导致模糊
            var dpr = window.devicePixelRatio || 1;
            var cw = cv.clientWidth || 240, ch = cv.clientHeight || 88;
            cv.width = Math.max(1, Math.round(cw * dpr));
            cv.height = Math.max(1, Math.round(ch * dpr));
            var ctx = cv.getContext('2d');
            ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
            var W = cw, H = ch;
            ctx.clearRect(0, 0, W, H);
            var cols = ['rgba(255,86,86,.55)', 'rgba(96,221,96,.5)', 'rgba(86,150,255,.55)'];
            for (var ch = 0; ch < 3; ch++) {
              var bins = hist[ch];
              var max = 1;
              for (var i = 0; i < bins.length; i++) { if (bins[i] > max) max = bins[i]; }
              ctx.beginPath();
              ctx.moveTo(0, H);
              for (var x = 0; x < bins.length; x++) {
                var px = x / (bins.length - 1) * W;
                var py = H - (bins[x] / max) * H;
                ctx.lineTo(px, py);
              }
              ctx.lineTo(W, H);
              ctx.closePath();
              ctx.fillStyle = cols[ch];
              ctx.fill();
            }
            ctx.strokeStyle = 'rgba(255,255,255,.14)';
            ctx.lineWidth = 1;
            ctx.strokeRect(.5, .5, W - 1, H - 1);
          } catch (e) {}
        }
        // 把主题色 + 直方图渲染到 EXIF 侧栏，并用主色给弹窗加氛围光
        function renderThemePalette(popup) {
          const dock = getExifDock();
          const list = dock.querySelector('.palette-list');
          const box = dock.querySelector('.exif-palette');
          const histCv = dock.querySelector('.hist-canvas');
          const img = popup && popup.querySelector('.pic img');
          if (!list || !box || !img) return;
          const src = (img.getAttribute('src') || '').split('?')[0];
          if (!src) { box.style.display = 'none'; if (histCv) histCv.style.display = 'none'; return; }
          box.style.display = '';
          if (histCv) histCv.style.display = '';
          list.innerHTML = '<span class="palette-loading">提取中…</span>';
          analyzePhoto(src, function (res) {
            // 快速切图时旧图的采样回调可能晚到：确认当前主图仍是这张，避免把旧主题色刷到新图上
            if (!img || (img.getAttribute('src') || '').split('?')[0] !== src) return;
            if (!res || !res.colors || !res.colors.length) { box.style.display = 'none'; if (histCv) histCv.style.display = 'none'; return; }
            list.innerHTML = res.colors.map(function (hex) {
              return '<span class="palette-item"><span class="palette-swatch" style="background:' + hex + '"></span><span class="palette-hex">' + hex + '</span></span>';
            }).join('');
            if (histCv && res.hist) drawHistogram(histCv, res.hist);
          });
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
          // 移动端图片上方标题：图集标题 + 图片名字（图片名字与图集标题相同则不重复）
          var cap = document.querySelector('.pp-mobile-caption');
          if (cap) {
            var art = popup && popup.__article;
            var h2 = art ? art.querySelector('h2') : null;
            var albumTitle = h2 ? (h2.textContent || '').trim() : '';
            var ct = cap.querySelector('.pp-cap-title');
            var cs = cap.querySelector('.pp-cap-sub');
            if (ct) { ct.textContent = albumTitle; ct.style.display = albumTitle ? '' : 'none'; }
            if (cs) { cs.textContent = title; cs.style.display = (title && title !== albumTitle) ? '' : 'none'; }
          }
          renderThemePalette(popup);
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
        let ppPanoDownX = 0, ppPanoDownY = 0, ppPanoMoved = false;
        let ppPanoFullscreen = false;
        // 点击捕获：
        //  - 全屏时：不响应“点击退出/关闭”，但全屏/退出按钮与 Pannellum 控件仍可点；
        //  - 非全屏：仅当发生真实拖拽（位移>8px）才吞掉紧随其后的 click（无论松手在哪）；纯点击不拦
        function ppPanoClickGuard(e) {
          if (ppPanoFullscreen) {
            var t = e.target;
            if (t && t.closest && t.closest('.pp-pano-fullscreen, .pp-pano-fullscreen-exit, .pnlm-control, .pnlm-hotspot, .pnlm-hotspot-base')) { ppPanoMoved = false; return; }
            e.stopPropagation(); e.preventDefault(); ppPanoMoved = false; return;
          }
          if (ppPanoMoved) { e.stopPropagation(); e.preventDefault(); ppPanoMoved = false; }
        }
        // 按压捕获：记录按下位置，重置拖拽标记
        function ppPanoDownGuard(e) {
          ppPanoMoved = false;
          try { if (e.target && e.target.closest && e.target.closest('.pp-pano-viewer')) { ppPanoDownX = e.clientX; ppPanoDownY = e.clientY; } } catch (err) {}
        }
        // 移动捕获：在全景内移动超过阈值判定为拖拽
        function ppPanoMoveGuard(e) {
          try {
            if (e.target && e.target.closest && e.target.closest('.pp-pano-viewer')) {
              var dx = e.clientX - ppPanoDownX, dy = e.clientY - ppPanoDownY;
              if (dx * dx + dy * dy > 64) ppPanoMoved = true;
            }
          } catch (err) {}
        }
        function ppBindPanoGuard() {
          if (ppPanoGuardBound) return;
          document.addEventListener('click', ppPanoClickGuard, true);
          document.addEventListener('pointerdown', ppPanoDownGuard, true);
          ['pointermove', 'mousemove', 'touchmove'].forEach(function (ev) {
            document.addEventListener(ev, ppPanoMoveGuard, true);
          });
          ppPanoGuardBound = true;
        }
        // 全景销毁时解绑 document 级捕获监听，避免灯箱关闭后仍常驻消耗
        function ppUnbindPanoGuard() {
          if (!ppPanoGuardBound) return;
          document.removeEventListener('click', ppPanoClickGuard, true);
          document.removeEventListener('pointerdown', ppPanoDownGuard, true);
          ['pointermove', 'mousemove', 'touchmove'].forEach(function (ev) {
            document.removeEventListener(ev, ppPanoMoveGuard, true);
          });
          ppPanoGuardBound = false;
        }
        function ppSetPanoActive(popup, v) {
          if (popup) popup.__panoActive = !!v;
          document.querySelectorAll('.poptrox-popup').forEach(function (p) { if (p !== popup) p.__panoActive = false; });
        }
        function ppDestroyPano() {
          ppPanoFullscreen = false;
          ppUnbindPanoGuard();
          if (ppPanoState) {
            try { if (ppPanoState.viewer && ppPanoState.viewer.destroy) ppPanoState.viewer.destroy(); } catch (e) {}
            try { if (ppPanoState.ro && ppPanoState.ro.disconnect) ppPanoState.ro.disconnect(); } catch (e) {}
            try { if (ppPanoState.onFsChange) document.removeEventListener('fullscreenchange', ppPanoState.onFsChange); } catch (e) {}
            if (document.fullscreenElement) { try { document.exitFullscreen(); } catch (e) {} }
            if (ppPanoState.wrap && ppPanoState.wrap.parentNode) ppPanoState.wrap.parentNode.removeChild(ppPanoState.wrap);
            if (ppPanoState.fsBtn && ppPanoState.fsBtn.parentNode) ppPanoState.fsBtn.parentNode.removeChild(ppPanoState.fsBtn);
            if (ppPanoState.exitBtn && ppPanoState.exitBtn.parentNode) ppPanoState.exitBtn.parentNode.removeChild(ppPanoState.exitBtn);
            if (ppPanoState.img) ppPanoState.img.style.visibility = '';
            ppPanoState = null;
          }
          ppSetPanoActive(null, false);
        }
        function ppMountPano(popup, url) {
          if (ppPanoState && ppPanoState.popup === popup && ppPanoState.url === url && ppPanoState.viewer) return;
          ppDestroyPano();
          if (typeof window.pannellum === 'undefined') { console.error('[InfinityTime pano] pannellum not loaded'); return; } // 库未加载（异常兜底）
          const pic = popup.querySelector('.pic');
          if (!pic) return;
          const img = pic.querySelector('img');
          // 移除 blur 预览遮层，避免盖住 Pannellum（遮层挂在 popup 下，不在 .pic 内）
          const lq = popup.querySelector('.pp-lqip');
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
              showZoomCtrl: false, showFullscreenCtrl: false, showControls: false,
              compass: false, driftEnabled: false, autoRotate: 0
            });
          } catch (e) {
            if (img) img.style.visibility = '';
            if (wrap.parentNode) wrap.parentNode.removeChild(wrap);
            console.error('[InfinityTime pano] viewer init failed:', e && e.message);
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
          // 自定义全屏按钮（匹配站点圆角），隐藏 Pannellum 默认全屏控制。
          // 直接挂到 .pic 下（与 .pp-pano-viewer 平级），使其落在灯箱自身的堆叠上下文，
          // 用高 z-index 盖过 Pannellum 画布与 poptrox 的导航/关闭钮，避免被遮挡。
          const fsBtn = document.createElement('button');
          fsBtn.type = 'button';
          fsBtn.className = 'pp-pano-fullscreen';
          fsBtn.setAttribute('aria-label', '全屏 / 退出全屏');
          fsBtn.setAttribute('title', '全屏 / 退出全屏');
          // 内联兜底定位（不依赖外部 CSS），即使样式表没命中也能看到并点得到
          fsBtn.style.cssText = 'position:absolute;top:12px;right:12px;z-index:9999;width:44px;height:44px;'
            + 'border:2px solid rgba(255,255,255,.92);border-radius:50%;background:rgba(0,0,0,.48);'
            + 'color:#fff;cursor:pointer;display:flex;align-items:center;justify-content:center;'
            + '-webkit-backdrop-filter:blur(6px);backdrop-filter:blur(6px);padding:0;';
          fsBtn.innerHTML = '<svg viewBox="0 0 24 24" width="19" height="19" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" style="display:block"><path d="M4 9V4h5M20 9V4h-5M4 15v5h5M20 15v5h-5"/></svg>';
          pic.appendChild(fsBtn);
          // 移动端 Safari 不支持对 WebGL/普通元素做 element 级全屏（Pannellum 亦如此）。
          // 在不支持全屏的设备上隐藏全屏按钮，避免出现“点了没反应”的死按钮。
          if (!(document.fullscreenEnabled || (document.documentElement && document.documentElement.requestFullscreen))) {
            fsBtn.style.display = 'none';
          }
          // 退出全屏按钮：挂到 Pannellum 容器（inner 即 .pnlm-container，全屏时的顶层元素）内部，
          // 这样全屏时才不会被画布盖住。平时隐藏，仅在全屏态显示。
          const exitBtn = document.createElement('button');
          exitBtn.type = 'button';
          exitBtn.className = 'pp-pano-fullscreen-exit';
          exitBtn.setAttribute('aria-label', '退出全屏');
          exitBtn.setAttribute('title', '退出全屏');
          exitBtn.style.cssText = 'position:absolute;top:12px;right:12px;z-index:9999;width:44px;height:44px;'
            + 'border:2px solid rgba(255,255,255,.92);border-radius:50%;background:rgba(0,0,0,.48);'
            + 'color:#fff;cursor:pointer;display:none;align-items:center;justify-content:center;'
            + 'box-sizing:border-box;-webkit-backdrop-filter:blur(6px);backdrop-filter:blur(6px);padding:0;';
          exitBtn.innerHTML = '<svg viewBox="0 0 24 24" width="19" height="19" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" style="display:block"><path d="M4 4l5 5M20 4l-5 5M4 20l5-5M20 20l-5-5"/></svg>';
          inner.appendChild(exitBtn);
          const onFsChange = function () {
            fsBtn.classList.toggle('active', !!document.fullscreenElement);
            const fs = !!document.fullscreenElement;
            ppPanoFullscreen = fs;
            fsBtn.style.display = fs ? 'none' : 'flex';
            exitBtn.style.display = fs ? 'flex' : 'none';
            // 全屏时按视口撑满，否则按图片区尺寸
            try {
              if (viewer && typeof viewer.setSize === 'function') {
                viewer.setSize(fs ? window.innerWidth : (pic.clientWidth || 0), fs ? window.innerHeight : (pic.clientHeight || 0));
              }
            } catch (e) {}
          };
          document.addEventListener('fullscreenchange', onFsChange);
          // 用 Pannellum 原生全屏（公开方法是 toggleFullscreen；它会在全景容器上做 requestFullscreen，
          // Safari/iOS 均支持，效果与 Pannellum 自带的全屏按钮一致）。
          fsBtn.addEventListener('click', function (e) {
            e.stopPropagation(); // 不触发“点弹窗外部关闭”
            try {
              if (viewer && typeof viewer.toggleFullscreen === 'function') { viewer.toggleFullscreen(); }
              else if (document.fullscreenElement) { document.exitFullscreen(); }
              else { try { wrap.requestFullscreen(); } catch (e2) {} }
            } catch (err) {}
          });
          // 退出全屏：容器内 mousedown/pointerdown 停止冒泡，避免触发 Pannellum 的拖拽
          ['mousedown', 'pointerdown', 'touchstart'].forEach(function (ev) {
            exitBtn.addEventListener(ev, function (e) { e.stopPropagation(); });
          });
          exitBtn.addEventListener('click', function (e) {
            e.stopPropagation();
            try {
              if (viewer && typeof viewer.toggleFullscreen === 'function') { viewer.toggleFullscreen(); }
              else { document.exitFullscreen(); }
            } catch (err) { try { document.exitFullscreen(); } catch (e2) {} }
          });
          // 兜底诊断：挂载后打印按钮几何信息，便于排查“按钮不显示”
          setTimeout(function () {
            try {
              const r = fsBtn.getBoundingClientRect();
              const cs = getComputedStyle(fsBtn);
              console.info('[InfinityTime pano] fsBtn', {
                connected: fsBtn.isConnected,
                rect: [Math.round(r.x), Math.round(r.y), Math.round(r.width), Math.round(r.height)],
                display: cs.display, visibility: cs.visibility, opacity: cs.opacity, z: cs.zIndex
              });
            } catch (e) {}
          }, 500);
          // 防“视窗内拖拽、视窗外松手”误退出：document 捕获阶段按“拖拽后时间窗”拦截 click，无论松手在哪都不关闭；
          // 不拦 pointerup/mouseup，避免 Pannellum 拖拽结束不了（鼠标锁住）。仍需点弹窗外部空白可正常关闭。
          ppBindPanoGuard();
          ppPanoState = { popup: popup, url: url, viewer: viewer, wrap: wrap, img: img, ro: ro, fsBtn: fsBtn, exitBtn: exitBtn, onFsChange: onFsChange };
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
        // 桌面端切图按钮：固定死在右下角，完全不跟随 EXIF 面板的位置/宽度变化，
        // 避免切图时面板变宽变窄导致按钮横向跳变。
        function ppPlaceDockNav(dock, on) {
          const nav = document.querySelector('.pp-mobile-nav');
          if (!nav) return;
          if (!on) {
            nav.classList.remove('pp-nav-placed');
            nav.style.left = ''; nav.style.top = ''; nav.style.right = ''; nav.style.bottom = ''; nav.style.width = '';
            return;
          }
          nav.style.left = 'auto';
          nav.style.right = '40px';
          nav.style.width = 'auto';
          nav.style.top = 'auto';
          nav.style.bottom = '40px';
          nav.classList.add('pp-nav-placed');
        }
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
          if (vw <= 900) { ppPlaceDockNav(dock, false); return; }
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
          ppPlaceDockNav(dock, true);
        }
        // 根据当前显示的主图 src 反查所属相册并刷新侧栏
        function syncDockExif() {
          const overlay = document.querySelector('.poptrox-overlay');
          const vis = overlay && getComputedStyle(overlay).display !== 'none'
            && overlay.style.display !== 'none' && overlay.style.visibility !== 'hidden';
          const dock = getExifDock();
          if (!vis) { dock.classList.remove('show'); dockShownOnce = false; return; }
          const popup = currentPopupExif();
          const img = popup ? popup.querySelector('.pic img') : null;
          const ready = popup && img && img.complete && img.naturalWidth > 0;
          if (!ready) {
            // 移动端：切图加载时保持常驻，避免底部抽屉反复淡入淡出；
            // 桌面端：整块面板淡出，等新图就绪后再带新内容淡入（保留淡入淡出质感）
            if (window.innerWidth <= 900 && dockShownOnce) return;
            dock.classList.remove('show');
            return;
          }
          dockShownOnce = true;
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
            if (exifDock) { exifDock.classList.remove('show'); exifDock.classList.remove('expanded'); }
            document.body.classList.remove('pp-dock-expanded');
            ppDestroyPano();
          }
        }
        function startExifPoll() {
          if (exifTimer) return;
          exifTimer = setInterval(function() {
            try {
              const vis = overlayVisible();
              applyExifState(vis);
              if (vis) { syncDockExif(); syncPano(); }
            } catch (e) {} // 避免单个异常导致每 120ms 在控制台刷报错
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


        // 暴露给 main.js 的无限滚动重绑使用
        window.ensureExifObserver = ensureExifObserver;
        window.syncDockExif = syncDockExif;
      });
