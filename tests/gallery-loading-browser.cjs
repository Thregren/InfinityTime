// 全量拦截请求，不访问线上图册。PLAYWRIGHT_BROWSER 可选 chromium/firefox/webkit。
// 本地 Chromium：CHROMIUM_PATH=/usr/bin/chromium node tests/gallery-loading-browser.cjs
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const playwright = require('playwright');
const browserName = process.env.PLAYWRIGHT_BROWSER || 'chromium';
assert.ok(['chromium', 'firefox', 'webkit'].includes(browserName), 'valid PLAYWRIGHT_BROWSER');
const theme = path.resolve(__dirname, '../usr/themes/InfinityTime');
const origin = 'https://gallery.test';
const url = name => `${origin}/${name}.webp`;
const attr = value => JSON.stringify(value).replace(/&/g, '&amp;').replace(/'/g, '&#39;');
const variants = name => ({ w: 3200, webp: [url(name), url(name + '-1600')], avif: [`${origin}/${name}.avif`, `${origin}/${name}-1600.avif`] });
function card(id, cid, images, ids, options = {}) {
  const previews = images.map((_, i) => `${origin}/${id}-thumb-${i}.webp`);
  return `<article class="thumb img-area" data-cid="${cid}"><a id="${id}" class="image my-photo" data-cid="${cid}" aria-label="Album ${id}" href="${images[0]}"
    data-images='${attr(images)}' data-photo-ids='${attr(ids)}' data-previews='${attr(previews)}' data-titles='${attr(images.map((_, i) => id + ' photo ' + i))}'
    data-variants='${attr(options.variants || [])}' data-panos='${attr(options.panos || [])}' data-dims='["3200x2000"]'
    data-exif='[]' data-descs='[]' data-addresses='[]'><img class="my-photo" src="${previews[0]}" alt="Album ${id}"></a><h2>Album ${id}</h2></article>`;
}
function pageHtml(cards, next = '/page2', navigation = false) {
  return `<!doctype html><html><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"><link rel="stylesheet" href="/assets/css/main.css"><link rel="stylesheet" href="/assets/css/gallery.css"><style>#main{display:block;min-height:2000px}.thumb{max-width:240px}</style></head><body><header id="header"></header><div id="wrapper"><main id="main"><div id="waterfall"><h2 class="gallery-month-label" id="month-2026-10">2026-10</h2>${cards}</div><nav id="gallery-pagination"><a rel="next" href="${next}">下一页</a></nav><div id="load-more" data-next-url="${next}" data-page="1" data-total-pages="2"></div></main></div>
  ${['init', 'jquery.min', 'jquery.poptrox.min', 'browser.min', 'breakpoints.min', 'lightbox', 'main'].concat(navigation ? ['gallery-navigation'] : []).map(n => `<script src="/assets/js/${n}.js"></script>`).join('')}</body></html>`;
}
const initial = card('a', 1, [url('a'), url('b'), url('pano'), url('slow')], ['p-1', 'p-2', 'p-3', 'p-4'], {
  variants: [variants('a'), variants('b'), variants('pano')], panos: [0, 0, 1, 0]
}) + card('same-album', 1, [url('other-month')], ['p-5']) + card('broken', 2, [url('broken')], ['p-6']);
const pixel = '<svg xmlns="http://www.w3.org/2000/svg" width="640" height="400"><rect width="640" height="400" fill="#556677"/></svg>';
(async () => {
  const browser = await playwright[browserName].launch({ headless: true, ...(browserName === 'chromium' && process.env.CHROMIUM_PATH ? { executablePath: process.env.CHROMIUM_PATH } : {}) });
  try {
    for (const viewport of [{ width: 1280, height: 900 }, { width: 390, height: 844 }]) {
      const context = await browser.newContext({ viewport });
      await context.addInitScript(() => {
        window.__samples = [];
        window.__viewerCalls = 0;
        const drawImage = CanvasRenderingContext2D.prototype.drawImage;
        CanvasRenderingContext2D.prototype.drawImage = function(image, ...args) {
          window.__samples.push({ connected: image.isConnected, source: image.currentSrc || image.src });
          return drawImage.call(this, image, ...args);
        };
        const getContext = HTMLCanvasElement.prototype.getContext;
        HTMLCanvasElement.prototype.getContext = function(type, ...args) {
          if (/webgl/.test(type)) return null;
          return getContext.call(this, type, ...args);
        };
        window.pannellum = { viewer() { window.__viewerCalls++; throw new Error('挂载查看器前应先检测 WebGL'); } };
        window.__galleryEvents = [];
        ['open', 'change', 'close', 'error'].forEach(type => document.addEventListener('infinitygallery:' + type, event => {
          const d = event.detail;
          window.__galleryEvents.push({ type, albumId: d && d.albumId, photoId: d && d.photoId });
        }));
      });
      const page = await context.newPage();
      const errors = [], requests = [];
      page.on('pageerror', e => errors.push(e.message));
      let broken = true, page2Fails = true, includeHistory = false, fallbackWebpFails = true;
      const delayed = new Map();
      await page.route('**/*', async route => {
        const pathname = new URL(route.request().url()).pathname;
        requests.push(pathname);
        if (pathname === '/') return route.fulfill({ contentType: 'text/html', body: pageHtml(initial, '/page2', includeHistory) });
        if (pathname.startsWith('/assets/')) {
          const filename = path.join(theme, pathname.slice(1));
          return fs.existsSync(filename) ? route.fulfill({ path: filename }) : route.fulfill({ status: 404, body: '' });
        }
        if (pathname === '/page2') return route.fulfill(page2Fails
          ? { status: 503, contentType: 'text/html', body: '<h1>稍后重试</h1>' }
          : { contentType: 'text/html', body: pageHtml(card('appended', 3, [url('appended')], ['p-7']), '/page3') });
        if (pathname === '/page3') {
          await new Promise(resolve => delayed.set('page3', resolve));
          return route.fulfill({ contentType: 'text/html', body: pageHtml(card('stale', 4, [url('stale')], ['p-8']), '') }).catch(() => {});
        }
        if (pathname === '/page4') return route.fulfill({ contentType: 'text/html', body: pageHtml(card('fresh', 5, [url('fresh')], ['p-9']), '') });
        if (pathname === '/empty') return route.fulfill({ contentType: 'text/html', body: pageHtml('', '') });
        if (/^\/fallback-.*\.avif$/.test(pathname)) return route.fulfill(pathname.startsWith('/fallback-decode')
          ? { contentType: 'image/avif', body: 'corrupt AVIF bytes' }
          : { status: 404, body: '' });
        if (/^\/fallback-all.*\.webp$/.test(pathname) && fallbackWebpFails) return route.fulfill({ status: 503, body: '' });
        if (pathname === '/broken.webp' && broken) return route.fulfill({ status: 503, body: '' });
        if (pathname === '/slow.webp') {
          await new Promise(resolve => delayed.set('slow', resolve));
          return route.fulfill({ status: 503, body: '' }).catch(() => {});
        }
        return route.fulfill({ contentType: 'image/svg+xml', body: pixel });
      });
      await page.goto(origin);
      await page.waitForFunction(() => !!window.InfinityGallery && !!window.InfinityWaterfall);
      async function open(albumId, photoId) {
        assert.equal(await page.evaluate(target => InfinityGallery.open(target), { albumId, photoId }), true);
      }
      async function ready(name) {
        await page.waitForFunction(source => {
          const p = document.querySelector('.poptrox-popup'), img = p && p.querySelector('.pic img');
          return img && img.getAttribute('src') === source && img.complete && img.naturalWidth && !p.classList.contains('loading') && img.style.opacity === '1';
        }, url(name));
        await page.waitForTimeout(500);
      }
      async function close() {
        await page.evaluate(() => InfinityGallery.close());
        await page.waitForFunction(() => getComputedStyle(document.querySelector('.poptrox-overlay')).display === 'none');
      }
      await open('1', 'p-1'); await ready('a');
      await page.waitForFunction(() => document.querySelector('.palette-hex'));
      assert.equal(await page.locator('.palette-hex').first().innerText(), '#556677', '代表色保持原有精确色值');
      assert.ok((await page.evaluate(() => window.__samples)).every(sample => sample.connected), '主题色采样复用已显示、已解码的位图');
      assert.equal(requests.filter(p => p === '/b.webp').length, 0, '相邻图预加载不下载全尺寸 WebP');
      assert.ok(requests.includes('/b-1600.avif'), '相邻图预加载与响应式主图候选一致');
      await page.keyboard.press('ArrowRight'); await ready('b');
      assert.ok(await page.locator('.pic img').evaluate(img => img.currentSrc.endsWith('/b-1600.avif')));
      assert.ok(requests.includes('/pano.webp'), '全景预加载原始分辨率');
      assert.equal(requests.filter(p => /pano.*\.avif|pano-1600/.test(p)).length, 0);
      await page.keyboard.press('ArrowRight'); await ready('pano');
      await page.waitForFunction(() => !!document.querySelector('.pp-pano-fallback'));
      assert.equal(await page.locator('.pp-pano-viewer').count(), 0);
      assert.equal(await page.locator('.poptrox-popup').evaluate(p => p.classList.contains('pp-pano-mode')), false);
      assert.equal(await page.locator('.pic img').evaluate(img => getComputedStyle(img).visibility), 'visible');
      assert.equal(await page.evaluate(() => window.__viewerCalls), 0, '不支持 WebGL 时不创建全景查看器');
      await open('1', 'p-5'); await ready('other-month');
      assert.equal(await page.evaluate(() => InfinityGallery.getCurrent().opener.id), 'same-album', '稳定 ID 能区分同一相册的多张月份卡片');
      await open('2', 'p-6');
      await page.waitForSelector('.pp-image-error button', { state: 'visible' });
      assert.equal(await page.locator('.poptrox-popup').evaluate(p => p.classList.contains('loading')), false, '加载失败后释放 Poptrox 导航锁');
      broken = false;
      await page.locator('.pp-image-error button').click(); await ready('broken');
      assert.equal(await page.locator('.pp-image-error').count(), 0);
      await page.keyboard.press('ArrowRight'); await ready('a');
      await page.evaluate(() => InfinityGallery.close());
      await open('1', 'p-5'); await ready('other-month');
      assert.equal(await page.locator('.pp-pano-fallback').count(), 0, '全景降级提示不会残留到下一张普通照片');
      await open('1', 'p-4');
      await page.waitForFunction(() => document.querySelector('.pic img').getAttribute('src').endsWith('/slow.webp'));
      await close();
      if (delayed.has('slow')) delayed.get('slow')();
      await page.waitForTimeout(500);
      assert.equal(await page.locator('.pp-image-error').count(), 0, '迟到的图片错误不会在灯箱关闭后重新显示');
      assert.equal(await page.locator('.pic img').count(), 0);
      await open('1', 'p-1'); await ready('a'); await close();
      assert.equal(await page.evaluate(() => {
        window.__superseded = InfinityGallery.open({ albumId: '1', photoId: 'p-1' });
        return InfinityGallery.open({ albumId: '1', photoId: 'p-5' });
      }), true);
      await ready('other-month');
      assert.equal(await page.evaluate(() => window.__superseded), false, '被新请求取代的打开操作不会恢复旧淡入');
      await close();
      assert.ok((await page.evaluate(() => window.__galleryEvents)).some(event => event.type === 'change' && event.photoId === 'p-5'));
      // 显示失败状态；失败后暂停自动请求，等待用户主动重试。
      assert.equal(await page.evaluate(() => InfinityWaterfall.loadMore()), false);
      assert.equal(await page.locator('#load-more').getAttribute('aria-busy'), 'false');
      assert.match(await page.locator('.pp-load-status').innerText(), /加载失败/);
      const beforeAuto = requests.filter(p => p === '/page2').length;
      await page.evaluate(() => window.dispatchEvent(new Event('scroll')));
      await page.waitForTimeout(100);
      assert.equal(requests.filter(p => p === '/page2').length, beforeAuto);
      page2Fails = false;
      await page.locator('.pp-load-retry').click();
      await page.waitForSelector('#appended[role="button"]');
      assert.equal(await page.locator('#month-2026-10').count(), 1, '追加月份标题不重复 DOM ID');
      assert.equal(await page.locator('#gallery-pagination a[rel="next"]').getAttribute('href'), '/page3');
      // 取消旧分页并发起新请求，旧响应迟到后不能追加内容。
      await page.evaluate(() => { window.__pendingPage = InfinityWaterfall.loadMore(); });
      while (!delayed.has('page3')) await new Promise(resolve => setTimeout(resolve, 20));
      await page.evaluate(() => InfinityWaterfall.reset({ nextUrl: '/page4' }));
      delayed.get('page3')();
      assert.equal(await page.evaluate(() => InfinityWaterfall.loadMore()), true);
      assert.equal(await page.locator('#stale').count(), 0);
      assert.equal(await page.locator('#fresh').count(), 1);
      assert.equal(await page.locator('.pp-load-retry').isVisible(), false);
      await page.evaluate(() => InfinityWaterfall.reset({ nextUrl: '/empty' }));
      assert.equal(await page.evaluate(() => InfinityWaterfall.loadMore()), true, '合法的搜索末尾空批次能正常结束');
      assert.equal(await page.locator('.pp-load-retry').isVisible(), false);
      // AVIF 请求和解码失败均必须尝试可用 WebP，不能仅显示永久失败。
      await page.evaluate(html => {
        document.querySelector('#waterfall').insertAdjacentHTML('beforeend', html);
        window.__rebindPoptrox();
      }, card('fallback', 6, ['fallback-404', 'fallback-decode', 'fallback-all'].map(url), ['p-10', 'p-11', 'p-12'], {
        variants: ['fallback-404', 'fallback-decode', 'fallback-all'].map(variants)
      }));
      await open('6', 'p-10'); await ready('fallback-404');
      assert.ok(await page.locator('.pic img').evaluate(img => img.currentSrc.endsWith('/fallback-404-1600.webp')));
      assert.equal(await page.locator('.pp-image-error').count(), 0);
      assert.equal(await page.locator('.pic source[type="image/avif"]').count(), 0);
      await page.keyboard.press('ArrowRight'); await ready('fallback-decode');
      assert.ok(await page.locator('.pic img').evaluate(img => img.currentSrc.endsWith('/fallback-decode-1600.webp')));
      await page.keyboard.press('ArrowRight');
      await page.waitForSelector('.pp-image-error button', { state: 'visible' });
      assert.equal(await page.locator('.poptrox-popup').evaluate(p => p.classList.contains('loading')), false);
      fallbackWebpFails = false;
      await page.locator('.pp-image-error button').click(); await ready('fallback-all');
      assert.equal(await page.locator('.pp-image-error').count(), 0, '全格式失败仍可手动重试');
      await close();
      // 与真实历史模块整合：切图只替换一条灯箱记录，返回恢复列表滚动；
      // 前进和刷新恢复同一照片，直接分享链接关闭后保留相册筛选。
      includeHistory = true;
      await page.goto(origin);
      await page.waitForFunction(() => !!window.InfinityGallery);
      await page.evaluate(() => { window.scrollTo(0, 600); document.querySelector('#a').click(); });
      await ready('a');
      assert.equal(new URL(page.url()).searchParams.get('photo'), 'p-1');
      await page.keyboard.press('ArrowRight'); await ready('b');
      assert.equal(new URL(page.url()).searchParams.get('photo'), 'p-2');
      await page.goBack();
      await page.waitForFunction(() => getComputedStyle(document.querySelector('.poptrox-overlay')).display === 'none');
      assert.equal(new URL(page.url()).searchParams.has('photo'), false);
      await page.waitForFunction(() => Math.abs(window.scrollY - 600) < 2);
      await page.goForward(); await ready('b');
      await page.reload(); await ready('b');
      await page.keyboard.press('Escape');
      await page.waitForFunction(() => !new URL(location.href).searchParams.has('photo'));
      await page.waitForFunction(() => getComputedStyle(document.querySelector('.poptrox-overlay')).display === 'none');
      await page.goto(origin + '/?album=1&photo=p-5'); await ready('other-month');
      await page.keyboard.press('Escape');
      await page.waitForFunction(() => !new URL(location.href).searchParams.has('photo'));
      assert.equal(new URL(page.url()).searchParams.get('album'), '1');
      assert.deepEqual(errors, []);
      await context.close();
      console.log(`图册加载、降级和重试回归测试通过：${browserName} ${viewport.width}px`);
    }
  } finally { await browser.close(); }
})().catch(error => { console.error(error); process.exitCode = 1; });
