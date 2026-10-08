// Run with Playwright installed: node tests/gallery-browser.cjs
// All page/assets/image requests are intercepted; no live site is contacted.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const { chromium } = require('playwright');
const theme = path.resolve(__dirname, '../usr/themes/InfinityTime');
const origin = 'https://gallery.test';
const url = name => origin + '/' + name + '.webp';
const attr = value => JSON.stringify(value).replace(/&/g, '&amp;').replace(/'/g, '&#39;');
function card(id, images, titles, variants = [], panos = []) {
  return `<article class="thumb img-area"><a id="${id}" class="image my-photo" aria-label="Album ${id}" href="${images[0]}"
    data-images='${attr(images)}' data-previews='${attr(images)}' data-titles='${attr(titles)}'
    data-variants='${attr(variants)}' data-panos='${attr(panos)}' data-dims='["640x400"]'
    data-exif='[]' data-descs='[]' data-addresses='[]'><img src="${images[0]}" alt="Album ${id}"></a><h2>Album ${id}</h2></article>`;
}
const fixture = `<!doctype html><html lang="zh-CN"><head><meta name="viewport" content="width=device-width, initial-scale=1">
<link rel="stylesheet" href="/assets/css/main.css"><style>#main{display:block}.thumb{max-width:240px}</style></head>
<body><header id="header"><a id="outside" href="#main">Gallery</a></header><div id="wrapper"><div id="main">
${card('a', [url('a'), url('plain'), url('pano')], ['Sunrise', 'Plain landscape', 'Panorama'], [{ w: 2400, webp: [url('a')], avif: [origin + '/a.avif'] }], [0, 0, 1])}
${card('b', [url('b'), url('b2')], ['', 'Blue sky'])}
</div></div><div id="preexisting-hidden" inert aria-hidden="true">Hidden</div>
<script>window.pannellum={viewer:function(){return {destroy:function(){},setSize:function(){}}}};</script>
${['jquery.min', 'jquery.poptrox.min', 'browser.min', 'breakpoints.min', 'lightbox', 'main'].map(name => `<script src="/assets/js/${name}.js"></script>`).join('')}
</body></html>`;
const image = '<svg xmlns="http://www.w3.org/2000/svg" width="640" height="400"><rect width="640" height="400" fill="#567"/></svg>';
(async () => {
  const browser = await chromium.launch({ headless: true, ...(process.env.CHROMIUM_PATH ? { executablePath: process.env.CHROMIUM_PATH } : {}) });
  try {
    for (const viewport of [{ width: 1280, height: 900 }, { width: 390, height: 844 }]) {
      const context = await browser.newContext({ viewport });
      const page = await context.newPage();
      const errors = [];
      page.on('pageerror', err => errors.push(err.message));
      const releaseSlow = [];
      await page.route('**/*', async route => {
        const pathname = new URL(route.request().url()).pathname;
        if (pathname === '/') return route.fulfill({ contentType: 'text/html', body: fixture });
        if (pathname.startsWith('/assets/')) {
          const filename = path.join(theme, pathname.slice(1));
          if (fs.existsSync(filename)) return route.fulfill({ path: filename });
          return route.fulfill({ status: 404, body: '' });
        }
        if (pathname === '/slow.webp') await new Promise(resolve => { releaseSlow.push(resolve); });
        return route.fulfill({ contentType: 'image/svg+xml', body: image });
      });
      await page.goto(origin);
      await page.waitForFunction(() => typeof window.__rebindPoptrox === 'function');
      async function ready(name) {
        await page.waitForFunction(expected => {
          const p = document.querySelector('.poptrox-popup'), img = p && p.querySelector('.pic img');
          return img && img.getAttribute('src') === expected && img.complete && img.naturalWidth > 0 && !p.classList.contains('loading');
        }, url(name));
        await page.waitForTimeout(500); // Poptrox's 420ms fade completes after loading.
      }
      async function close() {
        await page.keyboard.press('Escape');
        await page.waitForFunction(() => getComputedStyle(document.querySelector('.poptrox-overlay')).display === 'none');
      }
      async function open(id, key = 'Enter') {
        await page.locator('#' + id).focus();
        await page.keyboard.press(key);
        await page.waitForFunction(() => document.querySelector('.poptrox-overlay').getAttribute('aria-hidden') === 'false');
      }
      const handlerCount = () => page.evaluate(() => {
        const events = jQuery._data(window, 'events') || {};
        return Object.values(events).flat().filter(h => /^poptrox\d+$/.test(h.namespace || '')).length;
      });
      const initialHandlers = await handlerCount();
      if (initialHandlers !== 3) {
        console.error('Poptrox initialization diagnostics', await page.evaluate(() => ({
          jquery: jQuery.fn.jquery,
          handlers: Object.entries(jQuery._data(window, 'events') || {}).flatMap(([type, handlers]) => handlers.map(h => ({ type, namespace: h.namespace }))),
          hasDestroy: typeof document.querySelector('#main')._poptroxDestroy,
          patchedVendor: jQuery.fn.poptrox.toString().includes('eventNamespace'),
          cards: document.querySelectorAll('#main .thumb > a.image').length,
          dialogs: document.querySelectorAll('.poptrox-overlay').length
        })), errors);
      }
      assert.equal(initialHandlers, 3, 'one instance has resize, orientation and keyboard handlers');
      assert.equal(await page.locator('#a').getAttribute('role'), 'button');
      assert.equal(await page.locator('#a').getAttribute('tabindex'), '0');
      await open('a'); await ready('a');
      assert.equal(await page.locator('.poptrox-overlay').getAttribute('role'), 'dialog');
      assert.equal(await page.locator('.poptrox-overlay').getAttribute('aria-modal'), 'true');
      assert.ok(await page.locator('#wrapper').evaluate(el => el.inert));
      assert.match(await page.locator('.pic img').getAttribute('alt'), /Sunrise/);
      assert.ok(await page.locator('.pic img').evaluate(el => el.currentSrc.endsWith('/a.avif')));
      for (let i = 0; i < 10; i++) {
        await page.keyboard.press(i % 2 ? 'Shift+Tab' : 'Tab');
        assert.ok(await page.evaluate(() => document.querySelector('.poptrox-overlay').contains(document.activeElement)), 'focus remains in modal');
      }
      await page.keyboard.press('ArrowRight'); await page.keyboard.press('ArrowRight'); await ready('plain');
      assert.equal(await page.locator('.pic picture').count(), 0, 'AVIF to unvarianted image removes picture');
      assert.ok(await page.locator('.pic img').evaluate(el => el.currentSrc.endsWith('/plain.webp')));
      assert.match(await page.locator('.pic img').getAttribute('alt'), /Plain landscape/);
      await page.keyboard.press('ArrowLeft'); await ready('a');
      // Jump directly from AVIF to panorama on the reused image, exercising that fallback too.
      await page.locator('.pic img').evaluate((el, src) => el.setAttribute('src', src), url('pano'));
      await ready('pano');
      assert.equal(await page.locator('.pic picture').count(), 0, 'AVIF to panorama removes picture');
      await page.keyboard.press('ArrowRight'); await ready('b');
      assert.match(await page.locator('.pic img').getAttribute('alt'), /Album b/);
      await page.locator('.pp-mnav-next').focus(); await page.keyboard.press('Space'); await ready('b2');
      assert.match(await page.locator('.pic img').getAttribute('alt'), /Blue sky/, 'empty first title preserves per-image alignment');
      if (viewport.width < 900) {
        const handle = page.locator('.exif-dock-handle');
        await handle.click();
        assert.equal(await handle.getAttribute('aria-expanded'), 'true');
        assert.equal(await page.locator('.poptrox-overlay').getAttribute('aria-hidden'), 'false', 'drawer click does not close modal');
        await handle.focus(); await page.keyboard.press('Space');
        assert.equal(await handle.getAttribute('aria-expanded'), 'false', 'drawer keyboard activation remains local');
        assert.equal(await page.locator('.pic img').getAttribute('src'), url('b2'), 'Space does not trigger Poptrox previous');
      }
      await close();
      assert.equal(await page.evaluate(() => document.activeElement.id), 'a', 'restores original gallery opener');
      assert.equal(await page.locator('#wrapper').getAttribute('inert'), null);
      assert.equal(await page.locator('#preexisting-hidden').getAttribute('inert'), '', 'preserves preexisting inert state');
      await open('a', 'Space'); await ready('a');
      // Closing during the custom image transition cannot resurrect the old image.
      await page.keyboard.press('ArrowRight'); await close();
      await open('b'); await ready('b');
      assert.equal(await page.locator('.pic img').getAttribute('src'), url('b'));
      await close();
      // Cancel the vendor opening fade before its queued switch callback runs.
      await open('a'); await close();
      await page.waitForTimeout(700);
      assert.equal(await page.locator('.poptrox-popup').isVisible(), false);
      assert.equal(await page.locator('.pic img').count(), 0);
      for (let i = 0; i < 4; i++) await page.evaluate(() => window.__rebindPoptrox());
      assert.equal(await handlerCount(), initialHandlers, 'rebind does not accumulate window handlers');
      assert.equal(await page.locator('.pp-mobile-nav').count(), 1, 'shared controls survive without duplication');
      await open('a'); await ready('a');
      await page.evaluate(html => {
        document.querySelector('#main').insertAdjacentHTML('beforeend', html);
        window.__rebindPoptrox();
      }, card('new', [url('new')], ['New card']));
      assert.equal(await page.locator('#new').getAttribute('role'), null, 'open dialog defers rebind');
      await close();
      await page.waitForFunction(() => document.querySelector('#new').getAttribute('role') === 'button');
      await open('new'); await ready('new'); await close();
      assert.equal(await handlerCount(), initialHandlers);
      // Late load completion after Close must not restart Poptrox or show controls.
      await page.evaluate(html => { document.querySelector('#main').insertAdjacentHTML('beforeend', html); window.__rebindPoptrox(); }, card('slow', [url('slow')], ['Slow card']));
      await open('slow');
      await page.waitForFunction(expected => { const p = document.querySelector('.poptrox-popup'), img = p.querySelector('.pic img'); return p.classList.contains('loading') && img && img.getAttribute('src') === expected && !img.complete; }, url('slow'));
      await close();
      releaseSlow.forEach(resolve => resolve());
      await page.waitForTimeout(900);
      assert.equal(await page.locator('.poptrox-popup').isVisible(), false);
      assert.equal(await page.locator('.pic img').count(), 0);
      await open('new'); await ready('new'); await close();
      assert.deepEqual(errors, [], 'no uncaught script errors');
      await context.close();
      console.log(`Gallery browser regressions passed at ${viewport.width}px`);
    }
  } finally { await browser.close(); }
})().catch(error => { console.error(error); process.exitCode = 1; });
