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
  setAttribute(k, v) { this.attrs[k] = String(v); }
  getAttribute(k) { return this.attrs[k] ?? null; }
  hasAttribute(k) { return k in this.attrs; }
  removeAttribute(k) { delete this.attrs[k]; }
  contains(el) { return this === el || this.children.some(c => c.contains(el)); }
  querySelectorAll(selector) {
    const matches = e => selector === 'picture.pp-picture' ? e.tagName === 'PICTURE' && e.classList.contains('pp-picture')
      : selector === 'source[type="image/avif"]' ? e.tagName === 'SOURCE' && e.type === 'image/avif'
      : selector === '.pic img' ? e.tagName === 'IMG'
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
vm.runInContext(['removeResponsivePicture', 'applyResponsive', 'inAlbumNav'].map(helper).join('\n'), context);
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
