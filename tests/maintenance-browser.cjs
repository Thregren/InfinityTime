// 真实浏览器运行后台脚本；所有请求拦截，无线上数据或媒体操作。
const assert = require('node:assert/strict');
const fs = require('node:fs');
const playwright = require('playwright');
const engine = process.env.PLAYWRIGHT_BROWSER || 'chromium';
assert.ok(['chromium', 'firefox', 'webkit'].includes(engine));
const source = fs.readFileSync(require.resolve('../usr/plugins/InfinityTime/assets/admin.js'), 'utf8');
const parentId = 'a'.repeat(32), childId = 'b'.repeat(32);
const failures = Array.from({ length: 123 }, (_, i) => ({ task: i === 122 ? 'resync' : 'rebuild', id: i + 1, cid: 10, reason: i === 0 ? '<img src=x onerror=alert(1)>编码失败' : '图集字段同步失败；重试时只同步字段' }));
let state = { job: 'rebuild', job_id: parentId, total: 200, done: 200, failed: 123, finished: true };
function response(offset = 0) { return { ...state, failures: failures.slice(offset, offset + 50), failure_total: failures.length, failure_offset: offset, failure_next: offset + 50 < failures.length ? offset + 50 : null }; }
function html() {
  const job = state.finished && state.failed ? response() : { ...state, failures: [], failure_total: 0 };
  return `<!doctype html><html><meta charset="UTF-8"><body><main class="container typecho-page-main">
    <button data-run="rebuild">重建图片</button><button data-run="resync">重建字段</button>
    <div id="pp-bar-rebuild"></div><span id="pp-msg-rebuild"></span>
    <div id="pp-maintenance-failures" hidden><p id="pp-failure-summary"></p><ol id="pp-failure-list"></ol>
    <button id="pp-failure-prev">上一页</button><button id="pp-failure-next">下一页</button><button id="pp-retry-failures">仅重试失败项</button></div>
    </main><script>window.PP_ADMIN = ${JSON.stringify({ url: '/panel', token: 'test-token', job }).replace(/</g, '\\u003c')};</script><script src="/admin.js"></script></body></html>`;
}
(async () => {
  const browser = await playwright[engine].launch({ headless: true, ...(engine === 'chromium' && process.env.CHROMIUM_PATH ? { executablePath: process.env.CHROMIUM_PATH } : {}) });
  try {
    const page = await browser.newPage();
    const errors = [], requests = [], pending = [];
    page.on('pageerror', error => errors.push(error.message));
    await page.route('https://maintenance.test/**', async route => {
      const request = route.request(), path = new URL(request.url()).pathname;
      if (request.method() === 'POST') {
        const fields = {};
        for (const match of request.postData().matchAll(/name="([^"]+)"\r\n\r\n([^\r]*)/g)) fields[match[1]] = match[2];
        requests.push(fields); pending.push(route); return;
      }
      await route.fulfill({ contentType: path === '/admin.js' ? 'text/javascript' : 'text/html', body: path === '/admin.js' ? source : html() });
    });
    async function nextRequest(count) {
      await page.waitForFunction(() => document.querySelector('#pp-retry-failures').disabled || document.querySelector('#pp-failure-next').disabled);
      const deadline = Date.now() + 5000;
      while (requests.length < count && Date.now() < deadline) await new Promise(resolve => setTimeout(resolve, 10));
      assert.equal(requests.length, count, '请求数量应精确且没有重复');
      assert.equal(requests[count - 1]._, 'test-token');
      return requests[count - 1];
    }
    async function reply(data) { await pending.shift().fulfill({ contentType: 'application/json', body: JSON.stringify(data) }); }
    await page.goto('https://maintenance.test/');
    assert.equal(await page.locator('#pp-failure-list li').count(), 50, '刷新后立即显示有界失败清单');
    assert.equal(await page.locator('#pp-failure-list img').count(), 0, '错误内容只能显示为文本');
    await page.evaluate(() => { document.querySelector('#pp-failure-next').click(); document.querySelector('#pp-failure-next').click(); });
    assert.deepEqual(await nextRequest(1), { job: 'rebuild', mode: 'errors', job_id: parentId, offset: '50', ajax: '1', action: 'maintenance', _: 'test-token' });
    await reply(response(50));
    await page.waitForFunction(() => document.querySelector('#pp-failure-summary').textContent.includes('51–100'));
    await page.click('#pp-failure-next'); await nextRequest(2); await reply(response(100));
    await page.waitForFunction(() => document.querySelector('#pp-failure-summary').textContent.includes('101–123'));
    assert.equal(await page.locator('#pp-failure-list li').count(), 23);
    assert.match(await page.locator('#pp-failure-list li').last().textContent(), /相册 ID 123/);
    await page.evaluate(() => { document.querySelector('#pp-retry-failures').click(); document.querySelector('#pp-retry-failures').click(); document.querySelector('[data-run="resync"]').click(); });
    assert.equal((await nextRequest(3)).mode, 'retry');
    assert.equal(requests[2].job_id, parentId);
    // 服务端已建立子任务，但首次响应丢失。
    state = { job: 'rebuild', job_id: childId, retry_of: parentId, total: 123, done: 3, failed: 0, finished: false };
    await pending.shift().abort('failed');
    await page.waitForFunction(() => !document.querySelector('#pp-retry-failures').disabled);
    await page.click('#pp-retry-failures');
    assert.equal((await nextRequest(4)).mode, 'retry');
    assert.equal(requests[3].job_id, parentId, '丢失响应后必须重复同一原任务重试请求');
    await reply({ ...state, failures: [], failure_total: 0 });
    assert.equal((await nextRequest(5)).mode, 'poll');
    assert.equal(requests[4].job_id, childId);
    await pending.shift().abort('failed');
    await page.waitForFunction(() => !document.querySelector('[data-run="rebuild"]').disabled);
    await page.reload();
    await page.getByRole('button', { name: '继续', exact: true }).click();
    assert.equal((await nextRequest(6)).mode, 'resume');
    assert.equal(requests[5].job_id, childId, '刷新恢复继续同一个子任务身份');
    state = { ...state, done: 123, finished: true };
    await reply({ ...state, failures: [], failure_total: 0 });
    await page.waitForFunction(() => document.querySelector('#pp-msg-rebuild').textContent.includes('✓ 完成'));
    assert.equal(await page.locator('#pp-maintenance-failures').isVisible(), false);
    assert.deepEqual(errors, [], '后台脚本无浏览器异常');
    console.log(`维护真实浏览器 ${engine}：失败分页、文本安全、重复点击、丢失响应和刷新续跑通过`);
  } finally { await browser.close(); }
})().catch(error => { console.error(error); process.exitCode = 1; });
