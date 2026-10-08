'use strict';
const assert = require('node:assert/strict');
const install = require('../usr/themes/InfinityTime/assets/js/gallery-navigation.js');
function fixture(url = 'https://example.test/?q=sunset&tag=city') {
  const listeners = new Map(), docListeners = new Map();
  const stack = [{ url, state: {} }]; let index = 0;
  const location = { href: url, reloads: 0, reload() { this.reloads++; } };
  const status = { textContent: '' };
  const doc = { addEventListener: (n, fn) => docListeners.set(n, fn), getElementById: () => status };
  const emit = (n, detail) => { const fn = docListeners.get('infinitygallery:' + n); if (fn) fn({ detail }); };
  const win = {
    location, scrollX: 0, scrollY: 430, requestAnimationFrame: fn => fn(),
    scrollTo(x, y) { this.scrollX = x; this.scrollY = y; },
    addEventListener: (n, fn) => listeners.set(n, fn),
    InfinityGallery: {
      current: null,
      async open(detail) { this.current = detail; emit('open', detail); emit('change', detail); return true; },
      close() { this.current = null; emit('close'); }
    },
    history: {
      get state() { return stack[index].state; },
      replaceState(state, title, url) { stack[index] = { state, url }; location.href = url; },
      pushState(state, title, url) { stack.splice(++index); stack.push({ state, url }); location.href = url; },
      back() { if (index > 0) { location.href = stack[--index].url; listeners.get('popstate')(); } },
      forward() { if (index + 1 < stack.length) { location.href = stack[++index].url; listeners.get('popstate')(); } }
    }
  };
  const controller = install(win, doc);
  return { win, doc, emit, stack, controller, status };
}
const tick = () => new Promise(resolve => setImmediate(resolve));
(async () => {
  const f = fixture(); await tick();
  assert.equal(f.controller.selection('https://a.test/?album=7&photo=invalid'), null);
  assert.deepEqual(f.controller.selection('https://a.test/?album=7&photo=p-91'), { albumId: '7', photoId: 'p-91' });
  f.emit('open', { albumId: '7', photoId: 'p-91' });
  assert.equal(f.stack.length, 2, '打开灯箱只新增一条历史记录');
  let current = new URL(f.win.location.href);
  assert.equal(current.searchParams.get('q'), 'sunset'); assert.equal(current.searchParams.get('tag'), 'city');
  assert.equal(current.searchParams.get('photo'), 'p-91');
  f.emit('change', { albumId: '7', photoId: 'p-92' });
  assert.equal(f.stack.length, 2, '切换照片替换灯箱历史记录');
  f.win.history.back(); await tick();
  assert.equal(f.win.InfinityGallery.current, null, '后退关闭灯箱');
  assert.equal(f.win.scrollY, 430, '后退恢复列表滚动位置');
  assert.equal(new URL(f.win.location.href).searchParams.get('q'), 'sunset', '后退恢复筛选条件');
  f.win.history.forward(); await tick();
  assert.equal(f.win.InfinityGallery.current.photoId, 'p-92', '前进重新打开稳定照片链接');
  assert.equal(f.stack.length, 2, '恢复历史不新增记录');
  f.emit('close'); await tick();
  assert.equal(f.win.InfinityGallery.current, null, '关闭使用本次灯箱历史记录');
  const deep = fixture('https://example.test/?album=7&photo=p-149'); await tick();
  assert.equal(deep.win.InfinityGallery.current.photoId, 'p-149', '直接深链接在就绪后打开');
  assert.equal(deep.stack.length, 1, '初始深链接不新增历史记录');
  deep.emit('close');
  assert.equal(new URL(deep.win.location.href).searchParams.get('photo'), null, '直接链接关闭后清除选中照片且不离开本站');
  assert.equal(new URL(deep.win.location.href).searchParams.get('album'), '7');
  const unavailable = fixture(); await tick();
  unavailable.win.InfinityGallery.open = async () => false;
  unavailable.win.history.pushState({}, '', 'https://example.test/?album=99&photo=p-3');
  await unavailable.controller.applyLocation();
  assert.equal(unavailable.win.location.reloads, 1, '历史目标不在当前 DOM 中时重新读取该服务端页面');
  const missing = fixture('https://example.test/?album=99&photo=p-3'); await tick();
  missing.win.InfinityGallery.open = async () => false;
  await missing.controller.applyLocation();
  assert.match(missing.status.textContent, /不可用/, '服务端当前页面也没有目标时提示不可用，不循环刷新');
  assert.equal(missing.win.location.reloads, 0);
  const refreshed = fixture('https://example.test/?album=7&photo=p-91'); await tick();
  refreshed.win.history.replaceState({ infinityGridScroll: [0, 810] }, '', 'https://example.test/?q=city');
  await refreshed.controller.applyLocation();
  assert.equal(refreshed.win.location.reloads, 1, '深链接刷新后后退会重新读取原筛选列表');
  assert.equal(refreshed.win.history.state.infinityGridScroll[1], 810, '重新读取列表保留滚动恢复信息');
  assert.equal(deep.win.location.reloads, 1, '直接照片链接关闭时重新读取完整相册');
  console.log('图集历史导航：刷新后回退及 24 项导航检查通过');
})().catch(error => { console.error(error); process.exitCode = 1; });
