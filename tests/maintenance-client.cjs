// 使用真实后台脚本和可控 DOM/网络/定时器，验证维护任务协议及故障恢复。
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const source = fs.readFileSync(require.resolve('../usr/plugins/InfinityTime/assets/admin.js'), 'utf8');
class Element {
  constructor() { this.style = {}; this.listeners = {}; this.disabled = false; this.textContent = ''; }
  addEventListener(name, listener) { this.listeners[name] = listener; }
  emit(name) { this.listeners[name]?.({ target: this }); }
  getAttribute(name) { return name === 'data-run' ? 'rebuild' : null; }
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
  const button = new Element(), bar = new Element(), message = new Element(), container = new Element();
  const nodes = { '[data-run="rebuild"]': button, '#pp-bar-rebuild': bar, '#pp-msg-rebuild': message, '.container.typecho-page-main': container };
  const listeners = {}, requests = [], timers = [], replies = [];
  const document = {
    querySelector: selector => nodes[selector] || null,
    querySelectorAll: selector => selector === '[data-run]' ? [button] : [],
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
  return { button, bar, message, container, requests, timers,
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
  assert.match(h.message.textContent, /1 项失败，请查看日志/, '完成时必须显示失败处理提示');
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
  console.log('维护前端：开始/轮询/续跑身份、批次、重复点击、失败可见及错误恢复检查通过');
})().catch(error => { console.error(error); process.exitCode = 1; });
