// Dependency-free functional regressions for the real gallery helpers and handlers.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const source = fs.readFileSync(require.resolve('../usr/themes/InfinityTime/assets/js/main.js'), 'utf8');
function helper(name) {
  const start = source.indexOf('    function ' + name + '(');
  assert.ok(start >= 0, name + ' exists');
  return source.slice(start, source.indexOf('\n    }', start) + 6);
}
class Element {
  constructor(tag, cls = '') {
    this.tagName = tag.toUpperCase(); this.children = []; this.attrs = {}; this.style = {};
    this.classes = new Set(cls.split(' ').filter(Boolean)); this.isConnected = true;
    this.classList = { contains: v => this.classes.has(v), add: v => this.classes.add(v), remove: v => this.classes.delete(v) };
  }
  set className(v) { this.classes = new Set(v.split(' ')); }
  get className() { return [...this.classes].join(' '); }
  get parentNode() { return this.parent; }
  appendChild(el) { el.remove(); el.parent = this; this.children.push(el); return el; }
  insertBefore(el, before) { el.remove(); el.parent = this; this.children.splice(this.children.indexOf(before), 0, el); }
  remove() { if (this.parent) { this.parent.children.splice(this.parent.children.indexOf(this), 1); this.parent = null; } }
  removeChild(el) { el.remove(); return el; }
  setAttribute(k, v) { this.attrs[k] = String(v); }
  getAttribute(k) { return this.attrs[k] ?? null; }
  hasAttribute(k) { return k in this.attrs; }
  removeAttribute(k) { delete this.attrs[k]; }
  contains(el) { return this === el || this.children.some(c => c.contains(el)); }
  querySelectorAll(selector) {
    const matches = e => selector === 'picture.pp-picture' ? e.tagName === 'PICTURE' && e.classList.contains('pp-picture')
      : selector === 'source[type="image/avif"]' ? e.tagName === 'SOURCE' && e.type === 'image/avif'
      : selector === '.pic img' || selector === 'img' ? e.tagName === 'IMG'
      : selector.startsWith('.') ? e.classList.contains(selector.slice(1)) : false;
    return this.children.flatMap(child => [...(matches(child) ? [child] : []), ...child.querySelectorAll(selector)]);
  }
  querySelector(selector) { return this.querySelectorAll(selector)[0] || null; }
}
const popup = new Element('div', 'poptrox-popup');
const pic = popup.appendChild(new Element('div', 'pic'));
const img = pic.appendChild(new Element('img'));
const timers = [];
const context = {
  document: { createElement: tag => new Element(tag) },
  variantMap: {
    '/a.webp': { w: 2400, webp: ['/a.webp', '/a-small.webp'], avif: ['/a.avif', '/a-small.avif'] },
    '/b.webp': { w: 2200, webp: ['/b.webp'], avif: ['/b.avif'] },
    '/webp-only.webp': { w: 2000, webp: ['/webp-only.webp'] }
  },
  isPanoUrl: src => src === '/pano.webp', normUrl: src => src.split('?')[0],
  setTimeout: fn => timers.push(fn), window: {}, isPopupActive: true,
  albumForSrc: () => ({ album: { images: ['/a.webp', '/b.webp', '/c.webp'] }, idx: 0 })
};
vm.createContext(context);
vm.runInContext(['removeResponsivePicture', 'fallbackAvif', 'applyResponsive', 'inAlbumNav'].map(helper).join('\n'), context);
function show(src) { img.setAttribute('src', src); context.applyResponsive(popup, img); }
show('/a.webp');
assert.equal(img.parentNode.tagName, 'PICTURE');
assert.match(img.parentNode.querySelector('source[type="image/avif"]').getAttribute('srcset'), /a\.avif/);
show('/legacy.webp');
assert.equal(img.parentNode, pic, 'no-variant fallback unwraps the reused image');
assert.equal(pic.querySelectorAll('picture.pp-picture').length, 0);
assert.equal(img.getAttribute('srcset'), null);
assert.equal(img.getAttribute('sizes'), null);
show('/a.webp'); show('/pano.webp');
assert.equal(img.parentNode, pic, 'panorama fallback removes the old AVIF source');
assert.equal(img.getAttribute('srcset'), null);
show('/a.webp'); show('/b.webp');
assert.equal(pic.querySelectorAll('picture.pp-picture').length, 1);
assert.equal(img.parentNode.querySelector('source[type="image/avif"]').getAttribute('srcset'), '/b.avif 2200w');
show('/webp-only.webp');
assert.equal(img.parentNode, pic, 'WebP-only variants unwrap old AVIF picture');
assert.equal(img.getAttribute('srcset'), '/webp-only.webp 2000w');
show('/a.webp');
img.remove(); pic.appendChild(img); show('/legacy.webp');
assert.equal(pic.querySelectorAll('picture.pp-picture').length, 0, 'detached-image wrappers are removed');

show('/a.webp');
assert.equal(context.inAlbumNav(popup, 1), true);
assert.equal(context.inAlbumNav(popup, 1), true, 'rapid clicks are consumed, not delegated across albums');
assert.equal(timers.length, 2, 'a repeated click schedules no extra transition');
timers[1]();
assert.equal(img.getAttribute('src'), '/b.webp');
timers[0]();
context.inAlbumNav(popup, 1);
context.isPopupActive = false;
timers[3]();
assert.equal(img.getAttribute('src'), '/b.webp', 'closing cancels deferred image switch');
context.isPopupActive = true;
popup.__switchSeq++;
timers[3]();
assert.equal(img.getAttribute('src'), '/b.webp', 'reopening cannot revive the previous session callback');
popup.__switching = false;
popup.classList.add('loading');
assert.equal(context.inAlbumNav(popup, 1), true, 'loading consumes navigation');

// Run the actual delegated keyboard handler, without exporting production internals.
const keyStart = source.indexOf("    document.addEventListener('keydown', function(e) {");
const keyEnd = source.indexOf('    }, true);', keyStart) + '    }, true);'.length;
let handler, clicks = [], focus;
const opener = { click: () => clicks.push('open') };
const button = { closest: () => null };
const overlay = { querySelector: () => popup, focus: () => { focus = overlay; } };
popup.querySelector = selector => ({ click: () => clicks.push(selector) });
Object.assign(context, {
  document: { addEventListener: (_, fn) => { handler = fn; }, querySelector: () => overlay, activeElement: null },
  dialogTabStops: () => context.stops || [],
  $: () => ({ trigger: name => clicks.push(name) })
});
vm.runInContext(source.slice(keyStart, keyEnd), context);
function key(value, target = button, shiftKey = false) {
  const event = { key: value, target, shiftKey, preventDefault() { this.prevented = true; }, stopImmediatePropagation() { this.stopped = true; } };
  handler(event); return event;
}
context.isPopupActive = false;
assert.ok(key('Enter', { closest: () => opener }).prevented);
assert.ok(key(' ', { closest: () => opener }).prevented);
assert.deepEqual(clicks, ['open', 'open']);
context.isPopupActive = true;
key('ArrowRight'); key('ArrowLeft');
assert.deepEqual(clicks.slice(-2), ['.nav-next', '.nav-previous'], 'arrows use the same within-album click path');
const first = { focus: () => { focus = first; } }, last = { focus: () => { focus = last; } };
context.stops = [first, last]; context.document.activeElement = last;
key('Tab'); assert.equal(focus, first, 'Tab wraps inside the dialog');
context.document.activeElement = first;
key('Tab', button, true); assert.equal(focus, last, 'Shift+Tab wraps inside the dialog');
context.stops = []; key('Tab'); assert.equal(focus, overlay, 'empty dialog is a safe focus fallback');
key('Escape'); assert.equal(clicks.at(-1), 'poptrox_close');
console.log('Gallery responsive fallback, navigation cancellation, and keyboard regressions passed');

// Fault injection uses the actual load handler: recover before the vendor's
// terminal error handler, retain WebP candidates, and ignore superseded errors.
{
  const p = new Element('div', 'poptrox-popup');
  const box = p.appendChild(new Element('div', 'pic'));
  const image = box.appendChild(new Element('img'));
  image.setAttribute('src', '/a.webp');
  image.complete = false;
  image.naturalWidth = 0;
  const listeners = {};
  image.addEventListener = (type, fn, capture) => { listeners[type] = { fn, capture }; };
  image.removeEventListener = (type, fn, capture) => {
    if (listeners[type]?.fn === fn && listeners[type].capture === capture) delete listeners[type];
  };
  let terminalErrors = 0, loaded = 0;
  const c = {
    document: { createElement: tag => new Element(tag) }, variantMap: context.variantMap,
    isPopupActive: true, isPanoUrl: () => false, normUrl: src => src,
    albumForSrc: () => null, lqipFor: src => src, clearImageError() {}, clearLqip() {},
    captionFadeInAfterImage() {}, showImageError() { terminalErrors++; },
    galleryEvent(name) { if (name === 'loaded') loaded++; }, lastSelection: null,
    setTimeout() {}, clearTimeout() {}
  };
  vm.createContext(c);
  vm.runInContext(['removeResponsivePicture', 'fallbackAvif', 'applyResponsive', 'applyLqip'].map(helper).join('\n'), c);
  c.applyLqip(p);
  assert.equal(listeners.error.capture, true, 'fallback runs before vendor bubbling listeners');
  let stopped = false;
  listeners.error.fn({ stopImmediatePropagation() { stopped = true; } });
  assert.equal(stopped, true, 'recoverable AVIF failure preserves vendor load handlers');
  assert.equal(image.parentNode, box);
  assert.equal(image.getAttribute('srcset'), '/a.webp 2400w, /a-small.webp 1600w');
  assert.equal(terminalErrors, 0);
  c.applyLqip(p);
  assert.equal(image.parentNode, box, 'repeat setup cannot restore a failed AVIF source');
  image.complete = true; image.naturalWidth = 640;
  listeners.load.fn();
  assert.equal(image.style.opacity, '1');
  assert.equal(loaded, 1, 'WebP recovery reveals the existing image');
  listeners.error.fn({ stopImmediatePropagation() { throw new Error('WebP error must reach vendor'); } });
  assert.equal(terminalErrors, 1, 'WebP failure remains terminal and retryable');
  const staleFailure = listeners.error.fn;
  image.complete = false; image.naturalWidth = 0; image.setAttribute('src', '/b.webp');
  c.applyLqip(p);
  staleFailure({ stopImmediatePropagation() { throw new Error('stale error'); } });
  assert.equal(image.parentNode.tagName, 'PICTURE', 'old errors cannot downgrade the new selection');
  assert.match(image.parentNode.querySelector('source[type="image/avif"]').getAttribute('srcset'), /b\.avif/);
  c.isPopupActive = false;
  listeners.error.fn({ stopImmediatePropagation() { throw new Error('closed error'); } });
  assert.equal(image.parentNode.tagName, 'PICTURE', 'closed lightbox ignores late errors');
  c.isPopupActive = true;
  c.URL = URL; c.document.baseURI = 'https://gallery.test/';
  image.currentSrc = 'https://gallery.test/b.webp';
  listeners.error.fn({ stopImmediatePropagation() { throw new Error('unselected AVIF must not swallow WebP error'); } });
  assert.equal(terminalErrors, 2, 'unselected AVIF source does not hide a terminal WebP failure');
  console.log('AVIF failure, WebP recovery, terminal failure, and stale-event regressions passed');
}

// Thumbnail recovery tries the fallback image before exposing the retry button.
{
  const init = fs.readFileSync(require.resolve('../usr/themes/InfinityTime/assets/js/init.js'), 'utf8');
  const start = init.indexOf('  function initImageFallback(');
  const code = init.slice(start, init.indexOf('\n  }', start) + 4);
  const article = new Element('article', 'thumb');
  const picture = article.appendChild(new Element('picture'));
  const avif = picture.appendChild(new Element('source'));
  avif.setAttribute('type', 'image/avif'); avif.setAttribute('srcset', '/thumb.avif');
  const image = picture.appendChild(new Element('img'));
  image.setAttribute('src', '/thumb.webp'); image.setAttribute('srcset', '/thumb-small.webp 640w');
  image.setAttribute('data-fallback', '/placeholder.svg');
  image.closest = selector => selector === '.thumb' ? article : picture;
  picture.querySelectorAll = selector => selector === 'source' ? [avif] : [];
  const events = {};
  image.addEventListener = (type, fn) => { events[type] = fn; };
  const buttons = [];
  const c = { document: {
    querySelectorAll: () => [image],
    createElement(tag) {
      const el = new Element(tag);
      el.addEventListener = (type, fn) => { el[type] = fn; };
      if (tag === 'button') buttons.push(el);
      return el;
    }
  } };
  vm.createContext(c); vm.runInContext(code, c); c.initImageFallback();
  events.error();
  assert.equal(avif.getAttribute('srcset'), null);
  assert.equal(image.getAttribute('srcset'), '/thumb-small.webp 640w');
  assert.equal(buttons.length, 0, 'AVIF-only failure does not show a premature error');
  events.error(); events.error();
  assert.equal(buttons.length, 1, 'all formats failing yields one retry control');
  assert.equal(image.src, '/placeholder.svg');
  buttons[0].click({ preventDefault() {}, stopPropagation() {} });
  assert.equal(avif.getAttribute('srcset'), '/thumb.avif', 'manual retry restores format candidates once');
  events.error();
  assert.equal(avif.getAttribute('srcset'), null);
  events.load();
  assert.equal(buttons[0].parentNode, null, 'successful WebP retry removes the error control');
  avif.setAttribute('srcset', '/thumb.avif');
  image.currentSrc = 'https://gallery.test/thumb-small.webp';
  c.URL = URL; c.document.baseURI = 'https://gallery.test/';
  events.error();
  assert.equal(buttons.length, 2, 'unselected AVIF must expose the WebP retry control immediately');
  console.log('Thumbnail AVIF fallback and retry regressions passed');
}

// Missing or unusable ResizeObserver must not interrupt panorama registration.
// Execute production mount/destroy helpers with a lightweight viewer double.
{
  const lightbox = fs.readFileSync(require.resolve('../usr/themes/InfinityTime/assets/js/lightbox.js'), 'utf8');
  function panoHelper(name) {
    const start = lightbox.indexOf('        function ' + name + '(');
    return lightbox.slice(start, lightbox.indexOf('\n        }', start) + 10);
  }
  for (const mode of ['missing', 'throws', 'available']) {
    const p = new Element('div', 'poptrox-popup');
    const box = p.appendChild(new Element('div', 'pic'));
    box.clientWidth = 640; box.clientHeight = 400;
    const image = box.appendChild(new Element('img'));
    image.setAttribute('src', '/pano.webp');
    let viewers = 0, destroyed = 0, resized = 0, observed = 0, disconnected = 0;
    const pending = [];
    const c = {
      document: {
        createElement(tag) { const el = new Element(tag); el.addEventListener = () => {}; return el; },
        querySelectorAll: () => [p], documentElement: {},
        addEventListener() {}, removeEventListener() {}
      },
      window: { pannellum: { viewer() { viewers++; return {
        destroy() { destroyed++; }, setSize() { resized++; }
      }; } } },
      ppPanoState: null, ppCanUseWebgl: () => true,
      ppBindPanoGuard() {}, ppUnbindPanoGuard() {},
      ppFlatPano() { throw new Error('optional observer must not degrade panorama'); },
      setTimeout(fn) { pending.push(fn); }
    };
    if (mode !== 'missing') c.ResizeObserver = class {
      constructor() { if (mode === 'throws') throw new Error('unsupported'); }
      observe() { observed++; }
      disconnect() { disconnected++; }
    };
    vm.createContext(c);
    vm.runInContext(['ppSetPanoActive', 'ppDestroyPano', 'ppMountPano'].map(panoHelper).join('\n'), c);
    for (let tick = 0; tick < 10; tick++) c.ppMountPano(p, '/pano.webp');
    assert.equal(viewers, 1, mode + ': polling reuses one registered viewer');
    assert.equal(box.querySelectorAll('.pp-pano-viewer').length, 1);
    assert.equal(p.__panoActive, true);
    assert.equal(image.style.visibility, 'hidden');
    pending.shift()();
    assert.equal(resized, 1, 'initial deferred sizing remains available');
    assert.equal(observed, mode === 'available' ? 1 : 0);
    c.ppDestroyPano();
    assert.equal(destroyed, 1);
    assert.equal(disconnected, mode === 'available' ? 1 : 0);
    assert.equal(box.querySelectorAll('.pp-pano-viewer').length, 0);
    assert.equal(image.style.visibility, '');
    assert.equal(c.ppPanoState, null);
  }
  console.log('Panorama optional ResizeObserver registration and cleanup regressions passed');
}

// Execute the actual vendor integration with queued-animation/event doubles.
// This covers the close-before-open / late-load races and per-instance cleanup.
{
  const animations = [];
  const windowNode = new Element('window'), bodyNode = new Element('body');
  const root = bodyNode.appendChild(new Element('main'));
  const anchor = root.appendChild(new Element('a'));
  anchor.setAttribute('href', '/first.webp'); anchor.appendChild(new Element('img'));
  function descendants(node) { return node.children.flatMap(c => [c, ...descendants(c)]); }
  function matches(node, selector) { return selector && (selector[0] === '.' ? node.classes.has(selector.slice(1)) : node.tagName === selector.toUpperCase()); }
  class Collection extends Array {
    constructor(items = []) { super(); this.push(...items); }
    css(key, value) { if (typeof key === 'object') this.forEach(n => Object.assign(n.style, key)); else if (value !== undefined) this.forEach(n => n.style[key] = value); return this; }
    attr(key, value) { if (value === undefined) return this[0]?.getAttribute(key); this.forEach(n => n.setAttribute(key, value)); return this; }
    removeAttr(key) { this.forEach(n => n.removeAttribute(key)); return this; }
    prop(key, value) { if (value === undefined) return this[0]?.[key]; this.forEach((n, i) => n[key] = typeof value === 'function' ? value.call(n, i, n[key]) : value); return this; }
    data(key, value) { this.forEach(n => n.data ||= {}); if (value === undefined) return this[0]?.data[key]; this.forEach(n => n.data[key] = value); return this; }
    find(selector) { return new Collection(this.flatMap(n => descendants(n).filter(c => matches(c, selector)))); }
    children() { return new Collection(this.flatMap(n => n.children)); }
    add(other) { return new Collection([...this, ...other]); }
    each(fn) { this.forEach((n, i) => fn.call(n, i)); return this; }
    append(other) { const nodes = $(other); this.forEach(n => nodes.forEach(c => n.appendChild(c))); return this; }
    appendTo(parent) { $(parent).append(this); return this; }
    prepend() { return this; }
    html() { return this; }
    width() { return arguments.length ? this : 640; }
    height() { return arguments.length ? this : 400; }
    outerWidth() { return 640; }
    outerHeight() { return 400; }
    show() { this.forEach(n => n.visible = true); return this; }
    hide() { this.forEach(n => n.visible = false); return this; }
    is() { return !!this[0]?.visible; }
    addClass(name) { this.forEach(n => n.classes.add(name)); return this; }
    removeClass(name) { this.forEach(n => n.classes.delete(name)); return this; }
    detach() { this.forEach(n => n.remove()); return this; }
    remove() { return this.detach(); }
    on(names, callback) { if (typeof callback !== 'function') return this; this.forEach(n => { n.events ||= []; names.split(' ').forEach(name => n.events.push({ name, callback })); }); return this; }
    off(name) { this.forEach(n => n.events = (n.events || []).filter(e => name[0] === '.' ? !e.name.endsWith(name) : e.name.split('.')[0] !== name)); return this; }
    trigger(name, args = []) { this.forEach(n => (n.events || []).filter(e => e.name.split('.')[0] === name).forEach(e => e.callback.call(n, { preventDefault() {}, stopPropagation() {} }, ...args))); return this; }
    fadeTo(speed, opacity, callback) { this.show(); this.forEach(n => animations.push({ node: n, callback })); return this; }
    fadeIn(speed, callback) { return this.fadeTo(speed, 1, callback); }
    fadeOut(speed, callback) { this.forEach(n => animations.push({ node: n, callback: () => { n.visible = false; if (callback) callback(); } })); return this; }
    animate(properties, speed, easing, callback) { return this.fadeTo(speed, 1, callback); }
    stop() { this.forEach(n => { for (let i = animations.length - 1; i >= 0; i--) if (animations[i].node === n) animations.splice(i, 1); }); return this; }
  }
  // Array methods must produce plain arrays, not call our collection constructor.
  Object.defineProperty(Collection, Symbol.species, { value: Array });
  function $(value) {
    if (value instanceof Collection) return value;
    if (value === undefined || value === null) return new Collection();
    if (value === 'body') return new Collection([bodyNode]);
    if (typeof value !== 'string') return new Collection([value]);
    const tag = value.match(/^<(\w+)/)?.[1] || 'div';
    const cls = value.match(/class="([^"]+)"/)?.[1] || '';
    const node = new Element(tag, cls);
    if (cls === 'poptrox-popup') {
      for (const c of ['pic', 'closer', 'nav-previous', 'nav-next']) node.appendChild(new Element('div', c));
    }
    return new Collection([node]);
  }
  $.fn = Collection.prototype; $.extend = Object.assign;
  const vendorWindow = windowNode; vendorWindow.location = { protocol: 'https:' };
  vm.runInNewContext(fs.readFileSync(require.resolve('../usr/themes/InfinityTime/assets/js/jquery.poptrox.min.js'), 'utf8'), {
    jQuery: $, window: vendorWindow, document: { createElement: tag => new Element(tag) }
  });
  let opens = 0, closes = 0;
  $(root).poptrox({ usePopupDefaultStyling: false, usePopupLoader: false, usePopupCaption: false, usePopupNav: true, usePopupForceClose: true,
    onPopupOpen() { opens++; }, onPopupClose() { closes++; } });
  const dialog = $(bodyNode).find('.poptrox-popup'), picture = dialog.find('.pic');
  assert.equal(windowNode.events.length, 3);
  function flush() { let n = 0; while (animations.length) { assert.ok(n++ < 100, 'animation queue terminates'); animations.shift().callback?.(); } }
  $(anchor).trigger('click');
  dialog.trigger('poptrox_close');
  flush();
  assert.equal(picture.children().length, 0, 'closing opening fade cancels its deferred switch');
  assert.equal(dialog.is(':visible'), false);
  $(anchor).trigger('click');
  // Only complete overlay opening, leaving the image load pending.
  animations.shift().callback();
  const pendingImage = picture.children();
  assert.equal(pendingImage.length, 1);
  assert.equal(pendingImage[0].events.filter(e => e.name === 'load').length, 1);
  dialog.trigger('poptrox_close');
  assert.equal(pendingImage[0].events.filter(e => e.name === 'load').length, 0, 'closing detaches the pending image load handler');
  pendingImage.trigger('load'); flush();
  assert.equal(dialog.is(':visible'), false, 'late load cannot show a closed popup');
  root._poptroxDestroy();
  assert.equal(windowNode.events.length, 0, 'destroy removes this instance window handlers');
  assert.equal(opens, 2); assert.equal(closes, 2);
  console.log('Poptrox opening/load cancellation and event teardown regressions passed');
}
