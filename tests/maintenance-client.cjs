// 使用真实后台脚本和可控 DOM/网络/定时器，验证维护任务协议及故障恢复。
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const source = fs.readFileSync(require.resolve('../usr/plugins/InfinityTime/assets/admin.js'), 'utf8');
class Element {
  constructor(job = '') { this.job = job; this.children = []; this.style = {}; this.listeners = {}; this.disabled = false; this.textContent = ''; }
  set textContent(value) { this._text = value; this.children = []; }
  get textContent() { return this._text + this.children.map(child => child.textContent).join(''); }
  appendChild(child) { this.children.push(child); child.parentNode = this; }
  addEventListener(name, listener) { this.listeners[name] = listener; }
  emit(name) { this.listeners[name]?.({ target: this }); }
  getAttribute(name) { return name === 'data-run' ? this.job || 'rebuild' : null; }
  querySelector(selector) { return selector === 'button' ? (this.button ||= new Element()) : null; }
  insertBefore(child) { this.child = child; child.parentNode = this; }
  removeChild(child) { assert.equal(this.child, child, '继续任务提示应移除自身'); this.child = null; }
}
class FormDataMock {
  constructor() { this.fields = new Map(); }
  set(name, value) { this.fields.set(name, value); }
  append(name, value) { this.set(name, value); }
  get(name) { return this.fields.get(name); }
}
const flush = async () => { for (let i = 0; i < 8; i++) await new Promise(resolve => setImmediate(resolve)); };
function harness(job = null) {
  const button = new Element('rebuild'), otherButton = new Element('resync'), bar = new Element(), message = new Element(), container = new Element();
  const nodes = { '[data-run="rebuild"]': button, '[data-run="resync"]': otherButton, '#pp-bar-rebuild': bar, '#pp-msg-rebuild': message, '.container.typecho-page-main': container };
  for (const id of ['pp-maintenance-failures', 'pp-failure-summary', 'pp-failure-list', 'pp-failure-prev', 'pp-failure-next', 'pp-retry-failures']) nodes['#' + id] = new Element();
  const listeners = {}, requests = [], timers = [], replies = [];
  const document = {
    querySelector: selector => nodes[selector] || null,
    querySelectorAll: selector => selector === '[data-run]' ? [button, otherButton] : [],
    addEventListener: (name, listener) => { listeners[name] = listener; },
    createElement: () => new Element(),
  };
  vm.runInNewContext(source, {
    window: { PP_ADMIN: { url: '/admin/panel', token: 'fixture-token', job } }, document, FormData: FormDataMock,
    setTimeout: (fn, delay) => { timers.push({ fn, delay }); },
    fetch: (url, options) => {
      requests.push({ url, ...options });
      return new Promise((resolve, reject) => replies.push({ resolve, reject }));
    },
  });
  listeners.DOMContentLoaded();
  return { button, otherButton, bar, message, container, requests, timers, nodes,
    reply(data, ok = true) { replies.shift().resolve({ ok, json: async () => data }); },
    reject() { replies.shift().reject(new Error('模拟网络中断')); },
    malformed() { replies.shift().resolve({ ok: true, json: async () => { throw new Error('JSON'); } }); },
    poll() { assert.equal(timers.length, 1, '每次只能排队一次轮询'); const timer = timers.shift(); assert.equal(timer.delay, 300); timer.fn(); },
  };
}
(async () => {
  const h = harness();
  h.button.emit('click'); h.button.emit('click');
  assert.equal(h.requests.length, 1, '双击不得启动重复任务');
  assert.equal(h.button.disabled, true, '运行时禁用启动按钮');
  const first = h.requests[0];
  assert.equal(first.method, 'POST', '维护任务必须使用 POST');
  assert.equal(first.credentials, 'same-origin', '维护请求必须保留同源凭据');
  for (const [key, value] of Object.entries({ action: 'maintenance', job: 'rebuild', mode: 'start', job_id: '', _: 'fixture-token', ajax: '1' })) {
    assert.equal(first.body.get(key), value, '开始请求字段正确：' + key);
  }
  h.reply({ job_id: 'current-job', total: 5, done: 3, failed: 0, finished: false }); await flush();
  assert.equal(h.bar.style.width, '60%', '进度反映已完成比例');
  assert.equal(h.button.disabled, true, '批次之间保持按钮禁用');
  h.poll();
  assert.equal(h.requests[1].body.get('mode'), 'poll', '后续请求必须改为轮询');
  assert.equal(h.requests[1].body.get('job_id'), 'current-job', '轮询必须发送服务器返回的任务身份');
  h.reply({ job_id: 'current-job', total: 5, done: 5, failed: 1, finished: true }); await flush();
  assert.match(h.message.textContent, /失败 1/, '部分失败计数必须可见');
  assert.match(h.message.textContent, /1 项失败，请查看下方清单/, '完成时必须显示失败处理提示');
  assert.equal(h.button.disabled, false, '完成后恢复启动按钮');
  assert.equal(h.timers.length, 0, '完成后不再轮询');
  h.button.emit('click');
  assert.equal(h.requests[2].body.get('mode'), 'start', '再次点击必须开始新任务');
  assert.equal(h.requests[2].body.get('job_id'), '', '再次开始不得带上旧任务身份');
  assert.equal(h.bar.style.width, '0%', '新任务清除旧进度');
  h.reply({ job_id: 'fresh-job', total: 0, done: 0, failed: 0, finished: true }); await flush();
  assert.match(h.message.textContent, /没有需要重建的图片/, '空任务显示明确说明');

  const resumed = harness({ job: 'rebuild', job_id: 'saved-job', total: 6, done: 3, finished: false });
  resumed.container.child.querySelector('button').emit('click');
  assert.equal(resumed.requests[0].body.get('mode'), 'resume', '继续入口必须发送 resume');
  assert.equal(resumed.requests[0].body.get('job_id'), 'saved-job', '继续入口必须使用持久化任务身份');
  resumed.reply({ ok: false, msg: '维护任务已更新' }, false); await flush();
  assert.equal(resumed.message.textContent, '维护任务已更新', '过期任务拒绝必须显示真实错误');
  assert.equal(resumed.button.disabled, false, '拒绝后允许重新开始');
  assert.equal(resumed.timers.length, 0, '过期任务不得继续轮询');

  for (const fault of ['network', 'malformed', 'lease']) {
    const broken = harness(); broken.button.emit('click');
    if (fault === 'network') broken.reject();
    if (fault === 'malformed') broken.malformed();
    if (fault === 'lease') broken.reply({ finished: true, msg: '另一个维护任务正在进行' });
    await flush();
    assert.equal(broken.button.disabled, false, '故障或租约拒绝必须恢复按钮：' + fault);
    assert.equal(broken.timers.length, 0, '故障或租约拒绝必须停止轮询：' + fault);
    assert.ok(broken.message.textContent && !broken.message.textContent.includes('✓'), '故障不得显示成功：' + fault);
    broken.button.emit('click');
    assert.equal(broken.requests.length, 2, '故障必须释放运行状态以允许重试：' + fault);
    broken.reply({ total: 0, done: 0, failed: 0, finished: true }); await flush();
  }
  const parentId = 'a'.repeat(32), childId = 'b'.repeat(32);
  const failure = (id, task = 'rebuild') => ({ task, id, cid: 10, reason: task === 'resync' ? '图集字段同步失败；重试时只同步字段' : '图片编码失败，请检查原图与转换工具' });
  const completed = { job: 'rebuild', job_id: parentId, total: 6, done: 6, failed: 2, finished: true,
    failures: [failure(1), failure(10, 'resync')], failure_total: 2, failure_offset: 0, failure_next: null };
  const retry = harness(completed);
  assert.equal(retry.nodes['#pp-maintenance-failures'].hidden, false, '刷新后完成任务的失败清单保留');
  assert.equal(retry.nodes['#pp-failure-list'].children.length, 2, '刷新后立即展示所有当前页失败项');
  assert.match(retry.nodes['#pp-failure-list'].children[0].textContent, /图片 ID 1 \/ 相册 ID 10/, '图片失败显示图片与相册身份');
  assert.match(retry.nodes['#pp-failure-list'].children[1].textContent, /相册 ID 10.*只同步字段/, '字段失败显示单独恢复方式');
  const retryButton = retry.nodes['#pp-retry-failures'];
  retryButton.emit('click'); retryButton.emit('click'); retry.otherButton.emit('click');
  assert.equal(retry.requests.length, 1, '重试双击及其他维护按钮不能并行启动');
  assert.equal(retry.requests[0].body.get('mode'), 'retry');
  assert.equal(retry.requests[0].body.get('job_id'), parentId, '失败重试必须带原任务身份');
  assert.equal(retry.otherButton.disabled, true, '重试时禁用所有维护启动按钮');
  retry.reject(); await flush();
  retryButton.emit('click');
  assert.equal(retry.requests[1].body.get('mode'), 'retry', '重试响应丢失必须保留重试语义');
  assert.equal(retry.requests[1].body.get('job_id'), parentId, '重试响应丢失必须保留同一原任务身份');
  const unfinished = { job: 'rebuild', job_id: childId, retry_of: parentId, total: 2, done: 1, failed: 0, failures: [], failure_total: 0, finished: false };
  retry.reply(unfinished); await flush(); retry.poll(); retry.reject(); await flush();
  retry.button.emit('click');
  assert.equal(retry.requests[3].body.get('mode'), 'poll', '轮询失败再次点击不能变成全库开始');
  assert.equal(retry.requests[3].body.get('job_id'), childId, '轮询恢复必须使用子任务身份');
  retry.reply({ ...unfinished, done: 2, finished: true }); await flush();
  assert.equal(retry.nodes['#pp-maintenance-failures'].hidden, true, '失败全部恢复后隐藏空清单');
  const refreshRetry = harness(unfinished);
  refreshRetry.container.child.querySelector('button').emit('click');
  assert.equal(refreshRetry.requests[0].body.get('mode'), 'resume', '重试任务刷新后提供续跑');
  assert.equal(refreshRetry.requests[0].body.get('job_id'), childId, '刷新续跑必须保留服务器注入的完整身份');
  refreshRetry.reply({ ...unfinished, done: 2, finished: true }); await flush();

  const pages = harness({ ...completed, failed: 123, failure_total: 123, failures: Array.from({ length: 50 }, (_, i) => failure(i + 1)), failure_next: 50 });
  pages.nodes['#pp-failure-next'].emit('click'); pages.nodes['#pp-failure-next'].emit('click');
  assert.equal(pages.requests.length, 1, '翻页重复点击只能发一个请求');
  assert.equal(pages.requests[0].body.get('mode'), 'errors', '翻页是独立只读维护模式');
  assert.equal(pages.requests[0].body.get('job_id'), parentId, '翻页必须绑定当前任务');
  assert.equal(pages.requests[0].body.get('offset'), 50);
  pages.reply({ ...completed, failed: 123, failure_total: 123, failures: Array.from({ length: 50 }, (_, i) => failure(i + 51)), failure_offset: 50, failure_next: 100 }); await flush();
  assert.equal(pages.nodes['#pp-failure-list'].children.length, 50, '翻页替换当前页，DOM保持有界');
  assert.match(pages.nodes['#pp-failure-summary'].textContent, /共 123 项失败，显示 51–100 项/);
  pages.nodes['#pp-failure-next'].emit('click');
  pages.reply({ ...completed, failed: 123, failure_total: 123, failures: Array.from({ length: 23 }, (_, i) => failure(i + 101)), failure_offset: 100, failure_next: null }); await flush();
  assert.equal(pages.nodes['#pp-failure-list'].children.length, 23);
  assert.match(pages.nodes['#pp-failure-list'].children[22].textContent, /图片 ID 123/);
  assert.equal(pages.nodes['#pp-failure-next'].disabled, true, '末页禁用下一页');
  pages.nodes['#pp-failure-prev'].emit('click'); pages.reject(); await flush();
  pages.nodes['#pp-failure-prev'].emit('click');
  assert.equal(pages.requests[3].body.get('offset'), 50, '分页网络失败后可以重试同一页');
  pages.reply({ ...completed, failure_offset: 0 }); await flush();
  const legacy = harness({ job: 'rebuild', job_id: parentId, failed: 4, finished: true, unlisted_failed: 4, failure_total: 0 });
  assert.match(legacy.nodes['#pp-failure-summary'].textContent, /4 项来自旧版任务/);
  assert.equal(legacy.nodes['#pp-retry-failures'].disabled, true, '缺少旧版明细不能发起空重试');
  const panelSource = fs.readFileSync(require.resolve('../usr/plugins/InfinityTime/panel.php'), 'utf8');
  assert.match(panelSource, /'job' => \$ppJobState/, '后台必须注入带job_id的完整有界任务响应，不能重新遗漏身份');
  console.log('维护前端：身份、重复点击、失败清单、分页、刷新及定向重试响应丢失恢复检查通过');
})().catch(error => { console.error(error); process.exitCode = 1; });
