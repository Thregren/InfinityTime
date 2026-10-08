'use strict';
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const source = fs.readFileSync(require.resolve('../usr/themes/InfinityTime/assets/js/data-saver.js'), 'utf8');
function run(saved, network, blocked = false) {
  const listeners = {}, networkListeners = {}, changes = [];
  const control = { value: '', parentNode: { hidden: true }, addEventListener(type, fn) { this[type] = fn; } };
  const status = {};
  const document = { hidden: false, getElementById: id => id.endsWith('-status') ? status : control };
  const connection = network && { ...network, addEventListener(type, fn) { networkListeners[type] = fn; } };
  const localStorage = { getItem() { if (blocked) throw Error('blocked'); return saved; }, setItem(key, value) { if (blocked) throw Error('blocked'); saved = value; } };
  const window = { addEventListener(type, fn) { listeners[type] = fn; }, dispatchEvent(event) { changes.push(event.detail); } };
  vm.runInNewContext(source, { document, window, navigator: { connection }, localStorage, CustomEvent: class { constructor(type, options) { this.detail = options.detail; } } });
  return { window, document, control, status, changes, connection, listeners, networkListeners, select(value) { control.value = value; control.change(); } };
}
for (const network of [null, {}, { saveData: false, effectiveType: '4g' }]) {
  const h = run(null, network);
  assert.equal(h.window.InfinityDataSaver.isEnabled(), false);
  assert.equal(h.control.parentNode.hidden, false);
  h.select('on'); assert.equal(h.window.InfinityDataSaver.allowBackground(), false);
  h.select('off'); assert.equal(h.window.InfinityDataSaver.allowBackground(), true);
  h.document.hidden = true; assert.equal(h.window.InfinityDataSaver.allowBackground(), false);
}
for (const network of [{ saveData: true }, { effectiveType: '2g' }, { effectiveType: 'slow-2g' }]) {
  const h = run('auto', network);
  assert.equal(h.window.InfinityDataSaver.isEnabled(), true);
  h.select('off'); assert.equal(h.window.InfinityDataSaver.isEnabled(), false, '显式正常模式覆盖系统省流');
  h.select('auto'); assert.equal(h.window.InfinityDataSaver.isEnabled(), true);
}
const blocked = run('off', { saveData: true }, true);
blocked.select('off'); assert.equal(blocked.window.InfinityDataSaver.isEnabled(), false, '存储拒绝不影响当前选择');
const h = run('on', {});
h.listeners.storage({ key: 'unrelated', newValue: 'off' }); assert.equal(h.window.InfinityDataSaver.isEnabled(), true);
h.listeners.storage({ key: 'InfinityTime.dataSaver', newValue: 'off' }); assert.equal(h.window.InfinityDataSaver.isEnabled(), false);
h.connection.saveData = true; h.select('auto'); h.networkListeners.change(); assert.equal(h.window.InfinityDataSaver.isEnabled(), true);
h.listeners.storage({ key: null, newValue: null }); assert.equal(h.control.value, 'auto');
assert.equal(run('invalid', {}).control.value, 'auto');
console.log('省流策略：系统建议、显式覆盖、存储拒绝、跨页同步和后台暂停通过');
