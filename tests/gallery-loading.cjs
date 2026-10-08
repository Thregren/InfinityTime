// 无依赖测试直接执行生产辅助函数；真实浏览器中的响应式候选选择
// 由 gallery-loading-browser.cjs 覆盖。
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const path = require('node:path');
const read = name => fs.readFileSync(path.resolve(__dirname, '../usr/themes/InfinityTime/assets/js/' + name + '.js'), 'utf8');
function helper(source, name, indent) {
  const spaces = ' '.repeat(indent);
  const start = source.indexOf(spaces + 'function ' + name + '(');
  assert.ok(start >= 0, `${name} 已定义`);
  const end = source.indexOf('\n' + spaces + '}', start);
  return source.slice(start, end + indent + 2);
}
class Element {
  constructor(tag = 'div', classes = '') {
    this.tagName = tag.toUpperCase(); this.children = []; this.attrs = {}; this.style = {};
    this.events = {}; this.isConnected = true; this.classes = new Set(classes.split(' ').filter(Boolean));
    this.classList = {
      contains: c => this.classes.has(c), add: c => this.classes.add(c), remove: c => this.classes.delete(c),
      toggle: (c, value) => value ? this.classes.add(c) : this.classes.delete(c)
    };
  }
  set className(value) { this.classes = new Set(value.split(' ')); }
  get className() { return [...this.classes].join(' '); }
  get parentNode() { return this.parent; }
  appendChild(node) { node.remove(); node.parent = this; this.children.push(node); return node; }
  insertBefore(node, before) { node.remove(); node.parent = this; this.children.splice(this.children.indexOf(before), 0, node); }
  remove() { if (this.parent) { this.parent.children.splice(this.parent.children.indexOf(this), 1); this.parent = null; } }
  setAttribute(key, value) { this.attrs[key] = String(value); }
  getAttribute(key) { return this.attrs[key] ?? null; }
  hasAttribute(key) { return key in this.attrs; }
  removeAttribute(key) { delete this.attrs[key]; }
  contains(node) { return this === node || this.children.some(child => child.contains(node)); }
  matches(selector) { return selector.split(',').some(sel => {
    sel = sel.trim();
    if (sel === 'picture.pp-picture') return this.tagName === 'PICTURE' && this.classes.has('pp-picture');
    if (sel === 'source[type="image/avif"]') return this.tagName === 'SOURCE' && this.type === 'image/avif';
    if (sel === 'a[rel="next"]') return this.tagName === 'A' && this.attrs.rel === 'next';
    return sel.startsWith('.') && this.classes.has(sel.slice(1));
  }); }
  querySelectorAll(selector) {
    if (selector === ':scope > .thumb') return this.children.filter(n => n.classes.has('thumb'));
    return this.children.flatMap(n => [...(n.matches(selector) ? [n] : []), ...n.querySelectorAll(selector)]);
  }
  querySelector(selector) { return this.querySelectorAll(selector)[0] || null; }
  addEventListener(type, fn) { (this.events[type] ||= []).push(fn); }
}
(async () => {
  // 预加载复用主图的 <picture>/sizes 逻辑，在 src 前配置响应式属性；
  // 失败后允许重试，并限制常驻对象数量。
  {
    const source = read('main');
    const images = [];
    const context = {
      document: { createElement: tag => new Element(tag) }, window: { innerWidth: 390, devicePixelRatio: 2 },
      variantMap: { '/b.webp': { w: 3200, webp: ['/b.webp', '/b-1600.webp'], avif: ['/b.avif', '/b-1600.avif'] } },
      isPanoUrl: url => url === '/pano.webp', normUrl: url => url,
      __preloaded: new Map(),
      Image: class extends Element {
        constructor() { super('img'); images.push(this); }
        set src(value) { this.assigned = value; this.sourceWhenAssigned = this.parentNode.tagName; this.srcsetWhenAssigned = this.getAttribute('srcset'); }
      }
    };
    vm.createContext(context);
    vm.runInContext(['removeResponsivePicture', 'applyResponsive', 'preloadUrl'].map(name => helper(source, name, 4)).join('\n'), context);
    context.preloadUrl('/b.webp');
    assert.equal(images.length, 1);
    assert.equal(images[0].sourceWhenAssigned, 'PICTURE');
    assert.equal(images[0].srcsetWhenAssigned, '/b.webp 3200w, /b-1600.webp 1600w');
    assert.equal(images[0].parentNode.querySelector('source[type="image/avif"]').getAttribute('srcset'), '/b.avif 3200w, /b-1600.avif 1600w');
    assert.equal(images[0].fetchPriority, 'low');
    context.preloadUrl('/b.webp'); assert.equal(images.length, 1);
    images[0].onerror(); context.preloadUrl('/b.webp'); assert.equal(images.length, 2, '相邻图预加载失败后允许重试');
    context.window.innerWidth = 1280; context.preloadUrl('/b.webp'); assert.equal(images.length, 3, '视口变化后重新选择候选图');
    context.preloadUrl('/pano.webp');
    assert.equal(images.at(-1).sourceWhenAssigned, 'DIV');
    assert.equal(images.at(-1).assigned, '/pano.webp');
    for (let i = 0; i < 100; i++) context.preloadUrl('/' + i + '.webp');
    assert.equal(context.__preloaded.size, 64);
  }
  // 主题色采样复用已解码主图，并保留原有三种代表色。
  // 跨域限制导致采样失败时，只能请求独立缩略图。
  {
    const displayed = { complete: true, naturalWidth: 3, naturalHeight: 1, currentSrc: '/small.avif' };
    let drawn, taint = false, result, requests = [];
    const source = read('lightbox');
    const context = {
      __photoCache: {}, __photoPending: {},
      document: { createElement: () => ({ getContext: () => ({
        drawImage(image) { drawn = image; },
        getImageData() { if (taint && drawn === displayed) throw new Error('tainted'); return { data: [0,255,0,255, 255,0,255,255, 0,0,255,255] }; }
      }) }) },
      Image: class { constructor() { this.naturalWidth = 3; this.naturalHeight = 1; requests.push(this); } set src(src) { this.requested = src; } }
    };
    vm.createContext(context);
    vm.runInContext(['rgbToHex', 'analyzePhoto'].map(name => helper(source, name, 8)).join('\n'), context);
    context.analyzePhoto('/full.webp', displayed, '/preview.webp', value => result = value);
    assert.equal(drawn, displayed); assert.equal(requests.length, 0);
    assert.deepEqual(Array.from(result.colors), ['#00FF00', '#FF00FF', '#0000FF']);
    context.analyzePhoto('/full.webp', displayed, '/preview.webp', value => result = value);
    assert.equal(requests.length, 0, '重复采样使用缓存');
    taint = true;
    let callbackCount = 0;
    context.analyzePhoto('/cors.webp', displayed, '/preview.webp', () => callbackCount++);
    context.analyzePhoto('/cors.webp', displayed, '/preview.webp', () => callbackCount++);
    assert.equal(requests.length, 1, '同一缩略图的并发采样请求合并');
    assert.equal(requests[0].requested, '/preview.webp');
    assert.equal(requests[0].crossOrigin, 'anonymous');
    requests[0].onload(); assert.equal(callbackCount, 2);
    context.analyzePhoto('/no-preview.webp', displayed, '/no-preview.webp', value => result = value);
    assert.equal(requests.length, 1, '没有独立缩略图时不再解码第二份全尺寸图片');
    assert.deepEqual(Array.from(result.colors), []);
  }
  // 通过可控网络响应验证真实分页加载器的失败、去重、取消、失效响应、
  // 月份标题和末尾空批次处理。
  {
    const source = read('init');
    const wf = new Element(), lm = new Element(), nav = new Element();
    const next = nav.appendChild(new Element('a')); next.setAttribute('rel', 'next');
    lm.setAttribute('data-next-url', '/page2');
    const nodes = { waterfall: wf, 'load-more': lm, 'gallery-pagination': nav, 'month-2026-10': new Element('h2') };
    const pending = [], events = {}, docs = {};
    let rebinds = 0;
    const context = {
      document: { getElementById: id => nodes[id] || null, createElement: tag => new Element(tag), documentElement: { offsetHeight: 3000 }, dispatchEvent() {} },
      window: { innerHeight: 800, scrollY: 0, addEventListener: (name, fn) => events[name] = fn, __rebindPoptrox: () => rebinds++ },
      location: { href: 'https://gallery.test/' }, URL, Promise, AbortController,
      setTimeout, clearTimeout, applyDims() {}, initImageFallback() {},
      fetch: (url, options) => new Promise(resolve => pending.push({ url, options, resolve })),
      DOMParser: class { parseFromString(body) { return docs[body]; } },
      CustomEvent: class { constructor(name, options) { this.type = name; this.detail = options.detail; } }
    };
    function response(key, nextUrl, cards = 1) {
      const grid = new Element(), pager = new Element(); pager.setAttribute('data-next-url', nextUrl);
      const heading = grid.appendChild(new Element('h2', 'gallery-month-label')); heading.id = 'month-2026-10';
      for (let i = 0; i < cards; i++) grid.appendChild(new Element('article', 'thumb'));
      docs[key] = { getElementById: id => ({ waterfall: grid, 'load-more': pager }[id]) };
      return { ok: true, text: () => Promise.resolve(key) };
    }
    vm.createContext(context); vm.runInContext(helper(source, 'initWaterfall', 2), context); context.initWaterfall();
    const api = context.window.InfinityWaterfall;
    const failed = api.loadMore();
    assert.equal(await api.loadMore(), false); assert.equal(pending.length, 1, '并发请求不会重复加载同一页');
    assert.equal(lm.getAttribute('aria-busy'), 'true');
    pending.shift().resolve({ ok: false, status: 503 });
    assert.equal(await failed, false);
    assert.equal(lm.getAttribute('aria-busy'), 'false');
    assert.match(lm.children[0].textContent, /加载失败/);
    context.window.scrollY = 4000; events.scroll(); assert.equal(pending.length, 0, '自动加载失败后等待主动重试');
    const retried = api.loadMore(); pending.shift().resolve(response('page2', '/page3'));
    assert.equal(await retried, true); assert.equal(wf.children.length, 2); assert.equal(rebinds, 1);
    assert.equal(wf.children[0].getAttribute('id'), null, '追加标题的重复 ID 已移除');
    assert.equal(next.getAttribute('href'), '/page3');
    const stale = api.loadMore(); const staleRequest = pending.shift();
    api.reset({ nextUrl: '/page4' }); assert.equal(staleRequest.options.signal.aborted, true);
    staleRequest.resolve(response('stale', '/page5', 3));
    assert.equal(await stale, false); assert.equal(wf.children.length, 2, '重置后旧响应不得追加卡片');
    const fresh = api.loadMore(); pending.shift().resolve(response('fresh', '', 1));
    assert.equal(await fresh, true); assert.equal(wf.children.length, 4);
    assert.equal(lm.children[1].hidden, true);
    api.reset({ nextUrl: '/empty' });
    const empty = api.loadMore(); pending.shift().resolve(response('empty', '', 0));
    assert.equal(await empty, true); assert.equal(lm.children[1].hidden, true);
    api.reset({ nextUrl: '/invalid' });
    docs.invalid = { getElementById: () => null };
    const invalid = api.loadMore(); pending.shift().resolve({ ok: true, text: () => Promise.resolve('invalid') });
    assert.equal(await invalid, false); assert.match(lm.children[0].textContent, /加载失败/);
    api.cancel();
  }
  console.log('图册预加载、主题色和分页生命周期回归测试通过');
})().catch(error => { console.error(error); process.exitCode = 1; });
