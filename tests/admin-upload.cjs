// 在无 DataTransfer 的环境运行真实后台脚本，验证预览、移除、排序和提交。
const assert = require('node:assert/strict');
const vm = require('node:vm');
const fs = require('node:fs');
class Element {
  constructor() { this.children = []; this.dataset = {}; this.style = {}; this.listeners = {}; this.classList = { add() {}, remove() {} }; }
  addEventListener(name, fn) { this.listeners[name] = fn; }
  appendChild(child) { child.parentNode = this; this.children.push(child); }
  removeChild(child) { this.children.splice(this.children.indexOf(child), 1); }
  set innerHTML(value) { this.children = []; }
  querySelector() { return null; }
  querySelectorAll(selector) {
    if (selector === '.pp-up-thumb') { return this.children.map(c => c.children[0]); }
    return [];
  }
}
const form = new Element(), input = new Element(), previews = new Element();
input.name = 'files[]';
const selectors = { '#pp-upload-form': form, '#pp-files-input': input, '#pp-upload-previews': previews };
const document = new Element();
document.querySelector = selector => selectors[selector] || null;
document.createElement = () => new Element();
let serial = 0, sent, endpoint;
const activeUrls = new Set();
const urlApi = {
  createObjectURL() { const url = 'blob:test-' + ++serial; activeUrls.add(url); return url; },
  revokeObjectURL(url) { assert.ok(activeUrls.delete(url), 'URL 仅回收一次'); }
};
class FormDataMock {
  constructor() { this.entries = []; }
  set(key, value) { this.delete(key); this.append(key, value); }
  delete(key) { this.entries = this.entries.filter(entry => entry[0] !== key); }
  append(key, value) { this.entries.push([key, value]); }
}
const context = {
  window: { PP_ADMIN: { url: '/admin/endpoint', token: 'test' }, URL: urlApi },
  URL: urlApi, document, FormData: FormDataMock,
  XMLHttpRequest: class {
    constructor() { this.upload = {}; }
    open(method, url) { endpoint = url; }
    send(data) { sent = data; }
  }
};
vm.runInNewContext(fs.readFileSync(require.resolve('../usr/plugins/InfinityTime/assets/admin.js'), 'utf8'), context);
document.listeners.DOMContentLoaded();
const files = ['a.jpg', 'b.jpg', 'c.jpg'].map(name => ({ name, size: 10, lastModified: 1 }));
input.files = files;
input.listeners.change();
assert.equal(previews.children.length, 3);
assert.equal(activeUrls.size, 3, '必须生成真实预览 URL');
assert.equal(input.required, false, '文件队列由 JS 校验');
const drop = value => ({ preventDefault() {}, dataTransfer: { getData() { return value; } } });
previews.children[0].listeners.drop(drop('999'));
assert.equal(previews.children.length, 3, '越界拖拽不破坏队列');
previews.children[0].listeners.drop(drop('2'));
assert.deepEqual(previews.children.map(c => c.children[0].alt), ['c.jpg', 'a.jpg', 'b.jpg']);
assert.equal(activeUrls.size, 3, '重排回收旧 URL');
previews.children[1].children[1].listeners.click();
assert.deepEqual(previews.children.map(c => c.children[0].alt), ['c.jpg', 'b.jpg']);
assert.equal(activeUrls.size, 2, '移除回收旧 URL');
form.listeners.submit({ preventDefault() {} });
assert.equal(endpoint, '/admin/endpoint', '提交地址不受原生 URL API 影响');
assert.deepEqual(sent.entries.filter(([key]) => key === 'files[]').map(([, file]) => file.name), ['c.jpg', 'b.jpg'], '无 DataTransfer 也按预览顺序提交');
console.log('Admin upload preview / removal / sorting / submission passed');
