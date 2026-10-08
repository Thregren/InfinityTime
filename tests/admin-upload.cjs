// 使用可控的 DOM、网络与存储替身运行真实后台脚本。
// 无需浏览器依赖；同时覆盖成功、中断和重复操作。
const assert = require('node:assert/strict');
const vm = require('node:vm');
const fs = require('node:fs');
const source = fs.readFileSync(require.resolve('../usr/plugins/InfinityTime/assets/admin.js'), 'utf8');

let createdNodes = 0;
class Element {
  constructor(tag = 'div') {
    createdNodes++;
    this.tagName = tag.toUpperCase(); this.children = []; this.dataset = {}; this.style = {};
    this.listeners = {}; this.attributes = {}; this.className = ''; this.value = ''; this.disabled = false;
    this.classList = {
      contains: name => this.className.split(/\s+/).includes(name),
      add: (...names) => { this.className = [...new Set(this.className.split(/\s+/).filter(Boolean).concat(names))].join(' '); },
      remove: (...names) => { this.className = this.className.split(/\s+/).filter(n => !names.includes(n)).join(' '); },
      toggle: (name, force) => { const yes = force === undefined ? !this.classList.contains(name) : force; this.classList[yes ? 'add' : 'remove'](name); return yes; }
    };
  }
  set value(value) { this._value = value; if (value === '' && this.tagName === 'INPUT' && this.type === 'file') this.files = []; }
  get value() { return this._value; }
  addEventListener(name, fn) { (this.listeners[name] ||= []).push(fn); }
  emit(name, extra = {}) {
    const event = { target: this, preventDefault() { this.defaultPrevented = true; }, stopPropagation() {}, ...extra };
    for (const fn of this.listeners[name] || []) fn(event);
    return event;
  }
  click() { if (!this.disabled) this.emit('click'); }
  appendChild(child) { if (child.parentNode) child.parentNode.removeChild(child); child.parentNode = this; this.children.push(child); return child; }
  removeChild(child) { this.children.splice(this.children.indexOf(child), 1); child.parentNode = null; }
  insertBefore(child, next) { child.parentNode = this; const i = this.children.indexOf(next); this.children.splice(i < 0 ? this.children.length : i, 0, child); }
  get firstElementChild() { return this.children[0] || null; }
  get nextSibling() { if (!this.parentNode) return null; return this.parentNode.children[this.parentNode.children.indexOf(this) + 1] || null; }
  set textContent(value) { this._text = String(value); this.children = []; }
  get textContent() { return (this._text || '') + this.children.map(c => c.textContent).join(''); }
  set innerHTML(value) { this.children = []; this._text = String(value); }
  get innerHTML() { return this._text || ''; }
  set outerHTML(value) { this.replacedHTML = value; }
  get outerHTML() { return '<div id="pp-albums-card"></div>'; }
  setAttribute(name, value) {
    this.attributes[name] = String(value);
    if (name.startsWith('data-')) this.dataset[name.slice(5).replace(/-([a-z])/g, (_, letter) => letter.toUpperCase())] = String(value);
    else if (name === 'class') this.className = value;
    else if (['id', 'name', 'type'].includes(name)) this[name] = value;
  }
  getAttribute(name) {
    if (name.startsWith('data-')) return this.dataset[name.slice(5).replace(/-([a-z])/g, (_, letter) => letter.toUpperCase())] ?? null;
    return this.attributes[name] ?? this[name] ?? null;
  }
  matches(selector) {
    const attributes = [...selector.matchAll(/\[([^=\]]+)(?:=["']?([^\]"']+)["']?)?\]/g)];
    selector = selector.replace(/\[[^\]]+\]/g, '');
    const id = selector.match(/#([\w-]+)/), tag = selector.match(/^[\w-]+/), classes = [...selector.matchAll(/\.([\w-]+)/g)];
    return (!id || this.id === id[1]) && (!tag || this.tagName.toLowerCase() === tag[0]) && classes.every(m => this.classList.contains(m[1])) &&
      attributes.every(m => this.getAttribute(m[1]) !== null && (m[2] === undefined || this.getAttribute(m[1]) === m[2]));
  }
  querySelectorAll(selector) {
    if (selector.startsWith(':scope > ')) return this.children.filter(c => c.matches(selector.slice(9)));
    const selectors = selector.split(',').map(s => s.trim()), out = [];
    const visit = child => { if (selectors.some(s => child.matches(s))) out.push(child); child.children.forEach(visit); };
    this.children.forEach(visit); return out;
  }
  querySelector(selector) { return this.querySelectorAll(selector)[0] || null; }
  closest(selector) { return selector.split(',').some(s => this.matches(s.trim())) ? this : this.parentNode?.closest(selector) || null; }
  scrollIntoView() {}
  reset() { this.querySelectorAll('input,textarea').forEach(el => { el.value = el.defaultValue || ''; }); }
}
class FormDataMock {
  constructor(form) {
    this.entries = [];
    if (form) form.querySelectorAll('input,textarea,select').filter(el => el.name && !el.disabled).forEach(el => this.append(el.name, el.value));
  }
  set(key, value) { this.delete(key); this.append(key, value); }
  get(key) { return this.entries.find(entry => entry[0] === key)?.[1] ?? null; }
  delete(key) { this.entries = this.entries.filter(entry => entry[0] !== key); }
  append(key, value) { this.entries.push([key, value]); }
}
const flush = async () => { for (let i = 0; i < 15; i++) await new Promise(resolve => setImmediate(resolve)); };
const deferred = () => { let resolve, reject; const promise = new Promise((yes, no) => { resolve = yes; reject = no; }); return { promise, resolve, reject }; };
const response = (data, ok = true) => ({ ok, json: async () => data, text: async () => typeof data === 'string' ? data : JSON.stringify(data) });
function harness({ storage = new Map(), fetchHandler, initialCid = '', initialTarget = null, canPublish = true, withDataTransfer = false } = {}) {
  const document = new Element('document');
  const make = (tag, props, parent = document) => { const element = new Element(tag); Object.assign(element, props); parent.appendChild(element); return element; };
  const container = make('div', { className: 'container typecho-page-main' });
  const form = make('form', { id: 'pp-upload-form' }, container);
  ['title', 'address', 'device', 'tags', 'operation_key'].forEach(name => make('input', { name, type: name === 'operation_key' ? 'hidden' : 'text', value: name === 'title' ? '测试图集' : '' }, form));
  const cid = make('input', { id: 'pp-upload-cid', name: 'cid', type: 'hidden', value: initialCid }, form);
  const input = make('input', { id: 'pp-files-input', name: 'files[]', type: 'file', files: [] }, form);
  const previews = make('div', { id: 'pp-upload-previews' }, form);
  const target = make('div', { id: 'pp-upload-target' }, form);
  const summary = make('div', { id: 'pp-upload-summary' }, form);
  const dropzone = make('div', { id: 'pp-dropzone' }, form);
  const progress = make('div', { id: 'pp-upload-progress' }, form);
  make('div', { id: 'pp-upload-bar' }, progress); make('span', { className: 'pp-msg' }, progress);
  const submit = make('button', { id: 'pp-upload-submit', type: 'submit' }, form);
  const retry = make('button', { id: 'pp-upload-retry', type: 'button' }, form);
  const publish = make('button', { id: 'pp-upload-publish', type: 'button' }, form);
  const fresh = make('button', { id: 'pp-upload-new', type: 'button' }, form);
  const albums = make('div', { id: 'pp-albums-card', dataset: { page: '3', query: '旅行 & 海' } });
  const append = make('a', { dataset: { appendCid: '90', appendTitle: '已有图集' } }, albums);
  const details = make('details', { className: 'pp-album', open: false }, albums);
  const thumbs = make('div', { className: 'pp-thumbs', dataset: { cid: '90', loaded: '0' } }, details);
  const maintenance = make('button', { dataset: { run: 'rebuild' } });
  const maintenanceMsg = make('div', { id: 'pp-msg-rebuild' });
  document.createElement = tag => new Element(tag);
  const activeUrls = new Set(); let serial = 0;
  const URLMock = { createObjectURL() { const url = 'blob:test-' + ++serial; activeUrls.add(url); return url; }, revokeObjectURL(url) { assert.ok(activeUrls.delete(url), '每个预览 URL 最多回收一次'); } };
  const requests = [], uploads = [], window = new Element('window');
  Object.assign(window, { PP_ADMIN: { url: '/admin/endpoint', token: 'secret-csrf', userId: 7, uploadTarget: initialTarget, canPublish: canPublish }, URL: URLMock,
    sessionStorage: { getItem: key => storage.get(key), setItem: (key, value) => storage.set(key, value) } });
  let serverCount = 0, serverStatus = 'draft';
  const context = { window, document, URL: URLMock, FormData: FormDataMock, Promise, setTimeout,
    confirm: () => true, location: { hash: '' }, history: { replaceState() {} },
    DOMParser: class { parseFromString() { const doc = new Element('document'); make('div', { id: 'pp-albums-card' }, doc); return doc; } },
    fetch: async (url, options = {}) => {
      requests.push({ url, ...options });
      if (fetchHandler) { const handled = fetchHandler(url, options); if (handled !== undefined) return handled; }
      if (options.body?.get('action') === 'create_draft') return response({ ok: true, cid: 42, status: 'draft' });
      if (options.body?.get('action') === 'publish_album') { serverStatus = 'publish'; return response({ ok: true }); }
      if (options.body?.get('action') === 'maintenance') return response({ ok: true, finished: true, msg: '完成' });
      if (url.includes('job=album_status')) return response({ ok: true, cid: 42, title: '测试图集', count: serverCount, status: serverStatus });
      return response('<div id="pp-albums-card"></div>');
    },
    XMLHttpRequest: class {
      constructor() { this.upload = {}; }
      open(method, url) { this.method = method; this.url = url; }
      send(data) { this.data = data; uploads.push(this); }
      reply(data, status = 200) { if (data.ok && !data.replayed) serverCount++; this.status = status; this.responseText = JSON.stringify(data); this.onload(); }
    }
  };
  if (withDataTransfer) context.DataTransfer = class {
    constructor() { this.files = []; this.items = { add: file => this.files.push(file) }; }
  };
  vm.runInNewContext(source, context); document.emit('DOMContentLoaded');
  return { document, form, input, previews, target, summary, submit, retry, publish, fresh, cid, dropzone, append, details, thumbs, maintenance, maintenanceMsg,
    activeUrls, requests, uploads, storage, window,
    select(files) { input.files = files; input.emit('change'); },
    submitForm() { form.emit('submit'); },
    setCount(count) { serverCount = count; },
    setStatus(status) { serverStatus = status; },
    states: () => previews.children.map(c => c.dataset.state),
    field: name => form.querySelector('[name="' + name + '"]') };
}
const files = ['a.jpg', 'b.jpg', 'c.jpg'].map(name => ({ name, size: 10, lastModified: 1 }));
const drop = index => ({ dataTransfer: { getData() { return String(index); } } });

(async () => {
  const draft = deferred();
  const h = harness({ fetchHandler(url, options) { if (options.body?.get('action') === 'create_draft') return draft.promise; } });
  h.select(files); h.select(files);
  assert.equal(h.previews.children.length, 3, '忽略重复选择的文件');
  assert.equal(h.activeUrls.size, 3);
  assert.equal(h.input.required, false);
  h.previews.children[0].emit('drop', drop(999));
  h.previews.children[0].emit('drop', drop(2));
  assert.deepEqual(h.previews.children.map(c => c.children[0].alt), ['c.jpg', 'a.jpg', 'b.jpg']);
  h.previews.children[1].children[1].click();
  assert.deepEqual(h.previews.children.map(c => c.children[0].alt), ['c.jpg', 'b.jpg']);
  assert.equal(h.activeUrls.size, 2, '移除预览后释放其 URL');
  h.previews.children[0].querySelector('.pp-up-tit').value = '首张标题';
  h.previews.children[0].querySelector('.pp-up-tit').emit('input');
  h.submitForm(); h.submitForm();
  assert.equal(h.input.files.length, 0, '上传队列接管文件后清空原生 FileList，避免成功文件继续被输入框持有');
  assert.equal(h.requests.filter(r => r.body?.get('action') === 'create_draft').length, 1, '重复提交不会创建第二份草稿');
  h.previews.children[0].emit('drop', drop(1)); h.previews.children[0].children[1].emit('click'); h.select([files[0]]);
  assert.deepEqual(h.previews.children.map(c => c.children[0].alt), ['c.jpg', 'b.jpg'], '处理期间不能更改队列');
  assert.equal(h.input.disabled, true);
  draft.resolve(response({ ok: true, cid: 42, status: 'draft' })); await flush();
  assert.equal(h.uploads.length, 1, '同一时刻仅有首张图片请求在执行');
  assert.equal(h.uploads[0].url, '/admin/endpoint');
  assert.equal(h.uploads[0].method, 'POST');
  assert.equal(h.uploads[0].data.get('action'), 'upload_image');
  assert.equal(h.uploads[0].data.get('file').name, 'c.jpg', '没有 DataTransfer 时也按预览顺序串行提交');
  assert.equal(h.uploads[0].data.get('files[]'), null, '不提交批量文件字段');
  assert.equal(h.uploads[0].data.get('title'), '首张标题');
  assert.equal(h.uploads[0].data.get('_'), 'secret-csrf');
  assert.deepEqual(h.states(), ['uploading', 'queued']);
  const beforeEmptyProgress = createdNodes;
  h.uploads[0].upload.onprogress({ lengthComputable: true, loaded: 0, total: 0 });
  assert.equal(createdNodes, beforeEmptyProgress);
  assert.equal(h.document.querySelector('#pp-upload-bar').style.width, '0%', '零字节进度不会产生 NaN%');
  const untouchedCard = h.previews.children[1];
  h.uploads[0].upload.onprogress({ lengthComputable: true, loaded: 10, total: 10 });
  assert.equal(h.previews.children[1], untouchedCard, '当前项状态改变不会重建其他卡片');
  const convertedCard = h.previews.children[0], afterConversionNodes = createdNodes;
  h.uploads[0].upload.onload(); h.uploads[0].upload.onload();
  assert.equal(h.previews.children[0], convertedCard, '上传完成事件不会重复刷新转换状态');
  assert.equal(createdNodes, afterConversionNodes);
  assert.deepEqual(h.states(), ['converting', 'queued'], '转换阶段具有独立状态');
  h.uploads[0].reply({ ok: true, rowId: 100, cid: 42 }); await flush();
  assert.equal(h.uploads.length, 2);
  assert.deepEqual(h.states(), ['done', 'uploading']);
  assert.equal(h.activeUrls.size, 1, '已保存原图的 ObjectURL 立即释放，未完成项保留');
  assert.equal(h.previews.children[0].children[0].tagName, 'DIV', '成功项释放 File 引用后使用已保存占位卡片');
  assert.ok(h.previews.children[0].querySelector('.pp-up-saved'));
  const failedKey = h.uploads[1].data.get('item_key');
  h.uploads[1].reply({ ok: false, retryable: true, msg: '正忙' }); await flush();
  assert.deepEqual(h.states(), ['done', 'failed']);
  assert.equal(h.activeUrls.size, 1, '失败项保留原文件预览以便直接重试');
  assert.equal(h.publish.disabled, true, '部分失败时不能误发布');
  assert.equal(h.requests.some(r => r.body?.get('action') === 'publish_album'), false, '保存不会隐式发布图集');
  const listRead = h.requests.find(r => r.url.includes('job=albums_html'));
  assert.match(listRead.url, /page=3/);
  assert.match(listRead.url, /q=%E6%97%85%E8%A1%8C%20%26%20%E6%B5%B7/, 'AJAX 刷新保留搜索条件与页码');
  h.retry.click(); h.retry.click(); await flush();
  assert.equal(h.uploads.length, 3, '重试仅提交失败项，且不会重复提交');
  assert.equal(h.uploads[2].data.get('item_key'), failedKey, '重试保留原幂等键');
  h.uploads[2].reply({ ok: true, rowId: 101, cid: 42 }); await flush();
  assert.deepEqual(h.states(), ['done', 'done']);
  assert.equal(h.activeUrls.size, 0, '重试成功后释放最后一份原图预览');
  h.select([files[1], files[2]]);
  assert.equal(h.activeUrls.size, 0, '重选已保存文件不重新分配预览资源');
  assert.equal(h.previews.children.length, 2);
  assert.equal(h.input.files.length, 0, '无 DataTransfer 的浏览器也不持有被忽略的已保存文件');
  assert.equal(h.publish.disabled, false);
  h.publish.click(); h.publish.click(); await flush();
  assert.equal(h.requests.filter(r => r.body?.get('action') === 'publish_album').length, 1);
  assert.equal(h.publish.hidden, true);
  h.fresh.click(); assert.equal(h.activeUrls.size, 0, '新建另一草稿会释放旧预览');
  assert.equal(h.cid.value, '');

  // 响应丢失时，图片可能已保存入库；刷新后必须保留原图片幂等键。
  const interrupted = harness(); interrupted.select(files.slice(0, 2)); interrupted.submitForm(); await flush();
  interrupted.uploads[0].reply({ ok: true, rowId: 110 }); await flush();
  const uncertainKey = interrupted.uploads[1].data.get('item_key');
  interrupted.uploads[1].upload.onload(); interrupted.uploads[1].onerror(); await flush();
  const recovered = harness({ storage: interrupted.storage }); recovered.setCount(2); await flush();
  assert.deepEqual(recovered.states(), ['done', 'failed'], '刷新后仍保留服务器已保存项的状态');
  assert.equal(recovered.uploads.length, 0, '刷新后不会假设本地文件内容仍然可用');
  recovered.retry.click(); await flush(); assert.equal(recovered.uploads.length, 0, '文件内容缺失时必须重新选择');
  recovered.select(files.slice(0, 2)); recovered.retry.click(); await flush();
  assert.equal(recovered.uploads.length, 1, '重新选择整批文件时跳过已保存项');
  assert.equal(recovered.uploads[0].data.get('item_key'), uncertainKey);
  recovered.uploads[0].reply({ ok: true, replayed: true, rowId: 111 }); await flush();
  assert.deepEqual(recovered.states(), ['done', 'done']);
  assert.match(recovered.target.textContent, /已保存 2 张/, '幂等重试后的数量以服务器结果为准');

  // 创建响应丢失后必须复用原操作幂等键，避免重复创建图集。
  let creates = 0;
  const lostDraft = harness({ fetchHandler(url, options) {
    if (options.body?.get('action') === 'create_draft' && ++creates === 1) return Promise.reject(new Error('网络断开'));
  } });
  lostDraft.select([files[0]]); lostDraft.submitForm(); await flush(); lostDraft.submitForm(); await flush();
  const createCalls = lostDraft.requests.filter(r => r.body?.get('action') === 'create_draft');
  assert.equal(createCalls.length, 2); assert.equal(createCalls[0].body.get('operation_key'), createCalls[1].body.get('operation_key'));
  lostDraft.uploads[0].reply({ ok: true, rowId: 1 }); await flush();

  // 已有已发布图集支持追加图片，并保持发布状态。
  const append = harness(); append.setStatus('publish'); append.setCount(4); append.append.click(); await flush();
  append.select([files[0]]); append.submitForm(); await flush();
  assert.equal(append.uploads[0].data.get('cid'), '90');
  assert.equal(append.requests.some(r => r.body?.get('action') === 'create_draft'), false);
  append.uploads[0].reply({ ok: true, rowId: 90 }); await flush();
  assert.equal(append.publish.hidden, true);

  // 明确选中的、经服务器授权的图集优先于旧会话队列。
  const targeted = harness({ storage: interrupted.storage, initialTarget: { cid: 90, title: '明确选择的图集', status: 'publish', count: 4 } });
  targeted.setStatus('publish'); await flush();
  assert.equal(targeted.cid.value, '90'); assert.equal(targeted.previews.children.length, 0);
  assert.equal(targeted.fresh.hidden, false, '服务器初始隐藏的新建按钮可正常显示');

  // 贡献者可以保存草稿，但不能发布或修改已发布图集。
  const contributor = harness({ canPublish: false }); contributor.select([files[0]]); contributor.submitForm(); await flush();
  contributor.uploads[0].reply({ ok: true, rowId: 1 }); await flush();
  assert.equal(contributor.publish.hidden, true); contributor.publish.emit('click'); await flush();
  assert.equal(contributor.requests.some(r => r.body?.get('action') === 'publish_album'), false);
  assert.match(contributor.target.textContent, /编辑或管理员/);
  contributor.setStatus('publish'); contributor.append.click(); await flush();
  assert.equal(contributor.input.disabled, true); assert.equal(contributor.submit.disabled, true);
  contributor.select([files[1]]); contributor.submitForm(); await flush();
  assert.equal(contributor.uploads.length, 1, '贡献者只能只读查看已发布图集');
  assert.equal(contributor.fresh.disabled, false, '只读图集不会阻止用户新建草稿');
  contributor.fresh.click(); assert.equal(contributor.input.disabled, false);

  // 大队列只允许线性创建 DOM 节点；每个原文件仅上传一次。
  // 时间值受 DOM/XHR 替身和运行环境影响，不作为浏览器性能门槛。
  const costs = [];
  for (const count of [10, 50, 100, 200]) {
    const batch = harness(), baseline = createdNodes;
    const batchFiles = Array.from({ length: count }, (_, i) => ({ name: 'photo-' + i + '.jpg', size: 20 * 1024 * 1024, lastModified: 1 }));
    batch.select(batchFiles); batch.submitForm(); await flush();
    for (let i = 0; i < count; i++) {
      assert.equal(batch.uploads.length, i + 1, '前一个请求结束前不会上传下一项');
      const xhr = batch.uploads[i];
      assert.equal(xhr.data.get('file'), batchFiles[i]);
      xhr.upload.onprogress({ lengthComputable: true, loaded: 20, total: 20 });
      const convertedNodes = createdNodes;
      xhr.upload.onload();
      assert.equal(createdNodes, convertedNodes, '100% progress 与 onload 不重复创建节点');
      xhr.reply({ ok: true, rowId: i + 1 }); await flush();
      assert.equal(batch.activeUrls.size, count - i - 1, '逐项释放资源，待上传文件保持可用');
      const settledNodes = createdNodes;
      xhr.upload.onload(); xhr.onerror(); xhr.ontimeout();
      assert.equal(createdNodes, settledNodes, '已结算请求的迟到事件不改变队列或重复释放资源');
    }
    assert.equal(batch.uploads.length, count);
    assert.equal(new Set(batch.uploads.map(xhr => xhr.data.get('item_key'))).size, count, '每项保留独立幂等键');
    assert.equal(batch.states().filter(state => state === 'done').length, count);
    assert.equal(batch.previews.querySelectorAll('.pp-up-saved').length, count);
    assert.equal(batch.activeUrls.size, 0);
    const nodes = createdNodes - baseline;
    assert.ok(nodes <= 50 * count + 10, '节点数必须随队列线性增长：' + count + ' 张创建了 ' + nodes + ' 个节点');
    costs.push({ count, nodes });
    batch.fresh.click(); assert.equal(batch.activeUrls.size, 0, '清空成功队列不会再次回收 URL');
  }
  assert.ok(costs[3].nodes <= costs[2].nodes * 2 + 10, '队列翻倍不能导致节点数平方增长');

  // 支持 DataTransfer 的浏览器：重排/移除会同步 FileList，上传后清空但失败项仍能重试。
  const nativeInput = harness({ withDataTransfer: true });
  nativeInput.select(files); nativeInput.previews.children[0].emit('drop', drop(2));
  assert.deepEqual(Array.from(nativeInput.input.files, file => file.name), ['c.jpg', 'a.jpg', 'b.jpg']);
  nativeInput.previews.children[1].children[1].click();
  assert.deepEqual(Array.from(nativeInput.input.files, file => file.name), ['c.jpg', 'b.jpg']);
  nativeInput.submitForm(); await flush(); assert.equal(nativeInput.input.files.length, 0);
  nativeInput.uploads[0].reply({ ok: true, rowId: 1 }); await flush();
  nativeInput.uploads[1].onerror(); await flush();
  assert.equal(nativeInput.activeUrls.size, 1);
  const nativeFailedKey = nativeInput.uploads[1].data.get('item_key');
  nativeInput.retry.click(); await flush();
  assert.equal(nativeInput.uploads.length, 3);
  assert.equal(nativeInput.uploads[2].data.get('file'), files[1]);
  assert.equal(nativeInput.uploads[2].data.get('item_key'), nativeFailedKey);
  nativeInput.uploads[2].reply({ ok: true, rowId: 2 }); await flush();
  assert.equal(nativeInput.activeUrls.size, 0);
  nativeInput.select(files.slice(1));
  assert.equal(nativeInput.input.files.length, 0, '重选全部已保存文件也不会保留原生文件引用');
  assert.equal(nativeInput.activeUrls.size, 0);

  // 兼容旧响应：未提供 rowId 时，不激进释放无法确认为服务器记录的原文件。
  const legacyResponse = harness(); legacyResponse.select([files[0]]); legacyResponse.submitForm(); await flush();
  legacyResponse.uploads[0].reply({ ok: true }); await flush();
  assert.equal(legacyResponse.activeUrls.size, 1);
  assert.equal(legacyResponse.previews.children[0].children[0].tagName, 'IMG');
  legacyResponse.fresh.click(); assert.equal(legacyResponse.activeUrls.size, 0);

  // 图片详情加载失败可重试；维护操作仅使用带鉴权信息的 POST 请求。
  let detailAttempts = 0;
  const lazy = harness({ fetchHandler(url) { if (url.includes('job=album_images')) return response('detail', ++detailAttempts > 1); } });
  lazy.details.open = true; lazy.details.emit('toggle'); await flush();
  assert.equal(lazy.thumbs.dataset.loaded, '0');
  lazy.thumbs.querySelector('button').click(); await flush();
  assert.equal(detailAttempts, 2); assert.equal(lazy.thumbs.dataset.loaded, '1');
  lazy.maintenance.click(); lazy.maintenance.click(); await flush();
  const jobs = lazy.requests.filter(r => r.body?.get('action') === 'maintenance');
  assert.equal(jobs.length, 1); assert.equal(jobs[0].method, 'POST'); assert.equal(jobs[0].body.get('job'), 'rebuild');
  assert.equal(jobs[0].body.get('_'), 'secret-csrf');
  assert.equal(lazy.requests.some(r => r.url.includes('_=')), false, 'CSRF 令牌不会进入只读请求 URL');
  console.log('后台串行队列、部分重试、幂等、恢复、追加、发布、分页、懒加载重试和 POST 维护测试通过');
})().catch(error => { console.error(error); process.exitCode = 1; });

// 可选真实浏览器回归： PLAYWRIGHT_BROWSER=chromium|firefox|webkit node tests/admin-upload.cjs --browser
// 系统 Chromium 可额外指定 CHROMIUM_PATH=/usr/bin/chromium。
// 使用本地隔离 HTTP 服务；无需 PHP 服务器或真实账号。
async function browserRegression() {
  const browserName = process.env.PLAYWRIGHT_BROWSER || 'chromium';
  assert.ok(['chromium', 'firefox', 'webkit'].includes(browserName), '不支持的 PLAYWRIGHT_BROWSER: ' + browserName);
  const browserType = require('playwright')[browserName];
  const path = require('node:path');
  const os = require('node:os');
  const http = require('node:http');
  const directory = fs.mkdtempSync(path.join(os.tmpdir(), 'infinitytime-admin-'));
  const png = Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jRZkAAAAASUVORK5CYII=', 'base64');
  const paths = ['a.png', 'b.png'].map(name => { const file = path.join(directory, name); fs.writeFileSync(file, png); return file; });
  let browser, server;
  const html = `<!doctype html><html lang="zh-CN"><head><meta name="viewport" content="width=device-width,initial-scale=1"><link rel="stylesheet" href="/admin.css"></head>
  <body><div class="container typecho-page-main"><div class="pp-wrap"><div class="pp-tabs"><button class="pp-tab" data-tab="upload">上传</button><button class="pp-tab" data-tab="albums">图集</button></div>
  <div class="pp-panel" data-panel="upload"><div class="pp-card"><form id="pp-upload-form"><input name="action" type="hidden" value="create_album"><input name="operation_key" type="hidden"><input id="pp-upload-cid" name="cid" type="hidden">
  <div class="pp-row"><label>标题</label><input name="title" value="浏览器测试图集" required></div><input name="device"><input name="address"><input name="tags">
  <div id="pp-upload-target"></div><input id="pp-files-input" name="files[]" type="file" multiple required><div class="pp-dropzone" id="pp-dropzone">选择或拖入图片</div><div id="pp-upload-summary"></div><div id="pp-upload-previews"></div>
  <div id="pp-upload-progress" class="pp-progress"><div class="pp-bar-outer"><div id="pp-upload-bar" class="pp-bar"></div></div><span class="pp-msg"></span></div>
  <div class="pp-foot"><button type="submit" id="pp-upload-submit" class="pp-btn">保存</button><button type="button" id="pp-upload-retry" class="pp-btn" hidden>重试失败项</button><button type="button" id="pp-upload-publish" class="pp-btn" hidden>发布图集</button><button type="button" id="pp-upload-new" class="pp-btn" hidden>新建草稿</button></div></form></div></div>
  <div class="pp-panel" data-panel="albums"><div id="pp-albums-card" data-page="2" data-query="sky"><a href="?append=90" data-append-cid="90" data-append-title="Existing">追加</a></div></div></div></div>
  <script>window.PP_ADMIN={url:'/admin/endpoint',token:'test-token',userId:7,canPublish:true};</script><script src="/admin.js"></script></body></html>`;
  const fields = body => {
    const result = {};
    const pattern = /Content-Disposition: form-data; name="([^"]+)"(?:; filename="([^"]*)")?\r\n(?:Content-Type:[^\r]+\r\n)?\r\n([\s\S]*?)(?=\r\n--)/g;
    for (const match of body.matchAll(pattern)) result[match[1]] = match[2] || match[3];
    return result;
  };
  try {
    browser = await browserType.launch({ headless: true, ...(browserName === 'chromium' && process.env.CHROMIUM_PATH ? { executablePath: process.env.CHROMIUM_PATH } : {}) });
    const page = await browser.newPage({ viewport: { width: 1100, height: 850 } });
    await page.addInitScript(() => {
      const active = new Set(), create = URL.createObjectURL.bind(URL), revoke = URL.revokeObjectURL.bind(URL);
      window.uploadPreviewResources = active;
      URL.createObjectURL = file => { const url = create(file); active.add(url); return url; };
      URL.revokeObjectURL = url => { active.delete(url); revoke(url); };
    });
    const errors = [], calls = [], rows = new Map();
    page.on('pageerror', error => errors.push(error.message));
    page.on('dialog', dialog => dialog.accept());
    let draftCount = 0, published = false, failedOnce = false, active = 0, maxActive = 0, releaseFirst;
    const firstGate = new Promise(resolve => { releaseFirst = resolve; });
    // Playwright 路由拦截会把尚未发出的请求挂起，不能用它等待真实上传完成事件。
    // 本地服务先读完请求体，仅延迟服务器响应，才能准确观察“上传 → 转换”边界。
    server = http.createServer(async (request, response) => {
      const url = new URL(request.url, 'http://127.0.0.1');
      const reply = (body, contentType = 'text/html', status = 200) => {
        response.writeHead(status, { 'Content-Type': contentType, 'Cache-Control': 'no-store' });
        response.end(body);
      };
      try {
        if (url.pathname === '/') return reply(html);
        if (url.pathname === '/admin.js') return reply(source, 'text/javascript');
        if (url.pathname === '/admin.css') return reply(fs.readFileSync(require.resolve('../usr/plugins/InfinityTime/assets/admin.css'), 'utf8'), 'text/css');
        if (url.pathname !== '/admin/endpoint') return reply('', 'text/plain', 404);
        const chunks = [];
        for await (const chunk of request) chunks.push(chunk);
        const data = fields(Buffer.concat(chunks).toString('utf8'));
        calls.push({ method: request.method, url: request.url, data });
        const json = object => reply(JSON.stringify(object), 'application/json');
        if (data.action === 'create_draft') { draftCount++; return json({ ok: true, cid: 42, status: 'draft' }); }
        if (data.action === 'upload_image') {
          active++; maxActive = Math.max(maxActive, active);
          if (!rows.size && data.file === 'a.png') await firstGate;
          active--;
          if (data.file === 'b.png' && !failedOnce) { failedOnce = true; return json({ ok: false, retryable: true, msg: '媒体处理锁正忙' }); }
          const replayed = rows.has(data.item_key); rows.set(data.item_key, data.file);
          return json({ ok: true, rowId: rows.size, cid: 42, replayed });
        }
        if (data.action === 'publish_album') { published = true; return json({ ok: true, msg: '已发布' }); }
        if (url.searchParams.get('job') === 'album_status') return json({ ok: true, cid: 42, title: '浏览器测试图集', status: published ? 'publish' : 'draft', count: rows.size });
        return reply('<div id="pp-albums-card" data-page="2" data-query="sky"></div>');
      } catch (error) {
        errors.push(error.message);
        if (!response.headersSent) reply('测试服务错误', 'text/plain', 500);
        else response.destroy(error);
      }
    });
    await new Promise((resolve, reject) => {
      server.once('error', reject);
      server.listen(0, '127.0.0.1', resolve);
    });
    await page.goto('http://127.0.0.1:' + server.address().port + '/');
    await page.locator('#pp-files-input').setInputFiles(paths);
    assert.equal(await page.locator('.pp-up-item').count(), 2);
    await page.locator('#pp-upload-submit').click();
    await page.waitForFunction(() => document.querySelector('.pp-up-converting'));
    await page.locator('#pp-upload-form').evaluate(form => form.dispatchEvent(new Event('submit', { bubbles: true, cancelable: true })));
    assert.equal(draftCount, 1);
    assert.equal(await page.locator('#pp-files-input').isDisabled(), true);
    assert.equal(await page.locator('.pp-up-remove').first().isDisabled(), true);
    assert.equal(calls.filter(call => call.data.action === 'upload_image').length, 1);
    releaseFirst();
    await page.waitForFunction(() => !document.querySelector('#pp-upload-submit').disabled && document.querySelector('.pp-up-failed'));
    assert.equal(rows.size, 1); assert.equal(maxActive, 1);
    assert.equal(await page.evaluate(() => window.uploadPreviewResources.size), 1, '真实浏览器只保留失败项预览');
    assert.equal(await page.locator('.pp-up-done .pp-up-saved').count(), 1);
    assert.equal(await page.locator('#pp-files-input').evaluate(input => input.files.length), 0, '原生 FileList 已清空');
    assert.equal(published, false);
    assert.equal(await page.locator('#pp-upload-publish').isDisabled(), true);
    const failedKey = calls.find(call => call.data.file === 'b.png').data.item_key;
    await page.reload();
    await page.waitForFunction(() => !document.querySelector('#pp-upload-submit').disabled);
    assert.equal(await page.locator('.pp-up-done').count(), 1);
    assert.equal(await page.locator('.pp-up-failed').count(), 1);
    await page.locator('#pp-files-input').setInputFiles(paths);
    await page.locator('#pp-upload-retry').click();
    await page.waitForFunction(() => document.querySelectorAll('.pp-up-done').length === 2 && !document.querySelector('#pp-upload-submit').disabled);
    assert.equal(rows.size, 2);
    assert.equal(await page.evaluate(() => window.uploadPreviewResources.size), 0, '真实浏览器全部保存后无活跃 ObjectURL');
    assert.equal(await page.locator('.pp-up-done .pp-up-saved').count(), 2);
    const imageCalls = calls.filter(call => call.data.action === 'upload_image');
    assert.deepEqual(imageCalls.map(call => call.data.file), ['a.png', 'b.png', 'b.png']);
    assert.equal(imageCalls[2].data.item_key, failedKey);
    await page.locator('#pp-upload-publish').click();
    await page.waitForFunction(() => document.querySelector('#pp-upload-publish').hidden);
    assert.equal(published, true);
    assert.equal(calls.filter(call => call.data.action === 'publish_album').length, 1);
    assert.ok(calls.filter(call => call.method === 'GET').every(call => !call.url.includes('test-token')));
    assert.ok(calls.filter(call => call.url.includes('albums_html')).every(call => call.url.includes('page=2') && call.url.includes('q=sky')));
    await page.setViewportSize({ width: 390, height: 844 });
    assert.equal(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), true, '移动端队列布局不超出视口');
    await page.locator('#pp-upload-new').click();
    assert.equal(await page.locator('.pp-up-item').count(), 0);
    assert.equal(await page.locator('#pp-upload-cid').inputValue(), '');
    assert.deepEqual(errors, []);
    console.log('后台 ' + browserName + ' 回归通过：串行转换、部分重试、刷新后重选、明确发布与移动布局');
  } finally {
    if (browser) await browser.close();
    if (server) {
      server.closeAllConnections();
      await new Promise(resolve => server.close(resolve));
    }
    fs.rmSync(directory, { recursive: true, force: true });
  }
}
if (process.argv.includes('--browser')) browserRegression().catch(error => { console.error(error); process.exitCode = 1; });
