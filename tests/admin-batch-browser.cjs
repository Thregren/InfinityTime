'use strict';
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const engine = process.env.PLAYWRIGHT_BROWSER || 'chromium';
const origin = 'https://admin.test';
const plugin = path.resolve(__dirname, '../usr/plugins/InfinityTime');
function details(page = 1, all = false) {
  const ids = all ? [1, 2, 3, 4] : (page === 1 ? [1, 2] : [3, 4]);
  return `<div class="pp-album-tools" data-album-sortable="${all ? 1 : 0}"><a data-image-page="${page === 1 ? 2 : 1}" data-image-missing="" href="#">换页</a><a data-image-page="1" data-image-missing="desc" href="#">缺说明</a><a data-image-page="1" data-image-all="1" href="#">全部排序</a>
  <form id="pp-batch-7" method="post" class="pp-batch-form"><input name="action" value="batch_image_meta" type="hidden"><input name="cid" value="7" type="hidden"><input name="_" value="fixture-token" type="hidden"><label><input type="checkbox" name="apply_desc" value="1">统一说明</label><textarea name="desc"></textarea><label><input type="checkbox" name="apply_address" value="1">统一位置</label><input name="address"><button type="submit">保存勾选照片</button></form></div>
  ${ids.map(id => `<div class="pp-img" data-id="${id}"><label><input type="checkbox" name="rowIds[]" value="${id}" form="pp-batch-7">勾选整理</label><form method="post"><input name="action" value="set_album_cover" type="hidden"><input name="cid" value="7" type="hidden"><input name="rowId" value="${id}" type="hidden"><button type="submit">设为相册封面</button></form></div>`).join('')}`;
}
function html() { return `<!doctype html><html><meta name="viewport" content="width=device-width,initial-scale=1"><link rel="stylesheet" href="/admin.css"><body><div class="container typecho-page-main"><div class="pp-wrap"><details open class="pp-album"><summary>相册</summary><div class="pp-thumbs" data-cid="7" data-loaded="1">${details()}</div></details></div></div><script>window.PP_ADMIN={url:'/endpoint',token:'fixture-token',canPublish:true};</script><script src="/admin.js"></script></body></html>`; }
function multipart(body) {
  const result = {};
  const pattern = /Content-Disposition: form-data; name="([^"]+)"\r\n\r\n([\s\S]*?)(?=\r\n--)/g;
  for (const match of body.matchAll(pattern)) (result[match[1]] ||= []).push(match[2]);
  return result;
}
(async () => {
  const browser = await require('playwright')[engine].launch({ headless: true });
  try {
    for (const width of [1280, 390]) {
      const context = await browser.newContext({ viewport: { width, height: 844 } });
      const page = await context.newPage(), errors = [], writes = [], reads = [];
      let failRead = false, failWrite = true, releaseWrite;
      page.on('pageerror', error => errors.push(error.message));
      await page.route('**/*', async route => {
        const url = new URL(route.request().url());
        if (url.pathname === '/admin.js') return route.fulfill({ path: path.join(plugin, 'assets/admin.js') });
        if (url.pathname === '/admin.css') return route.fulfill({ path: path.join(plugin, 'assets/admin.css') });
        if (url.pathname !== '/endpoint') return route.fulfill({ contentType: 'text/html', body: html() });
        if (route.request().method() === 'POST') {
          const fields = multipart(route.request().postData() || ''); writes.push(fields);
          if (writes.length === 1) await new Promise(resolve => { releaseWrite = resolve; });
          return route.fulfill({ contentType: 'application/json', body: JSON.stringify({ ok: !failWrite, msg: failWrite ? '模拟保存失败' : '保存成功' }) });
        }
        reads.push(Object.fromEntries(url.searchParams));
        if (failRead) return route.fulfill({ status: 503, body: 'fail' });
        return route.fulfill({ contentType: 'text/html', body: details(Number(url.searchParams.get('image_page') || 1), url.searchParams.get('image_all') === '1') });
      });
      await page.goto(origin);
      for (const input of await page.locator('[name="rowIds[]"]').all()) await input.check();
      await page.check('[name="apply_desc"]'); await page.fill('textarea[name="desc"]', '批量说明');
      await page.evaluate(() => { const form = document.querySelector('#pp-batch-7'); form.dispatchEvent(new Event('submit', { bubbles: true, cancelable: true })); form.dispatchEvent(new Event('submit', { bubbles: true, cancelable: true })); });
      await page.waitForFunction(() => document.querySelector('#pp-batch-7 button').disabled);
      const deadline = Date.now() + 10000;
      while (!releaseWrite && Date.now() < deadline) await new Promise(resolve => setTimeout(resolve, 10));
      assert.ok(releaseWrite, '保存请求必须启动');
      assert.equal(writes.length, 1, '重复提交只发送一次');
      assert.deepEqual(writes[0]['rowIds[]'], ['1', '2'], '外部关联复选框全部进入FormData');
      assert.deepEqual(writes[0]._, ['fixture-token']);
      assert.equal(writes[0].apply_address, undefined, '未勾选的位置字段不会被应用');
      releaseWrite();
      await page.waitForSelector('.notice.error');
      assert.equal(await page.locator('[name="rowIds[]"]:checked').count(), 2, '保存失败保留选择');
      failWrite = false;
      await Promise.all([page.waitForRequest(request => request.method() === 'GET' && new URL(request.url()).pathname === '/endpoint'), page.click('#pp-batch-7 button')]);
      await page.waitForFunction(() => document.querySelector('.pp-thumbs').getAttribute('aria-busy') === 'false');
      await page.waitForSelector('.notice.success');
      assert.equal(writes.length, 2);
      failRead = true;
      await page.click('[data-image-page="2"]'); await page.waitForSelector('.pp-detail-retry');
      assert.equal(await page.locator('.pp-img[data-id="1"]').count(), 1, '下一页失败保留当前内容');
      failRead = false; await page.click('.pp-detail-retry');
      await page.waitForSelector('.pp-img[data-id="3"]');
      assert.equal(await page.locator('.pp-img[data-id="1"]').count(), 0);
      await page.click('[data-image-missing="desc"]');
      await page.waitForSelector('.pp-img[data-id="1"]');
      assert.equal(reads.at(-1).image_missing, 'desc');
      await Promise.all([page.waitForRequest(request => request.method() === 'GET' && new URL(request.url()).pathname === '/endpoint'), page.locator('.pp-img').first().locator('button').click()]);
      await page.waitForSelector('.notice.success');
      await page.waitForFunction(() => document.querySelector('.pp-thumbs').getAttribute('aria-busy') === 'false');
      assert.deepEqual(writes.at(-1).action, ['set_album_cover']);
      assert.deepEqual(writes.at(-1).rowId, ['1']);
      assert.equal(reads.at(-1).image_missing, 'desc', '改封面后保留当前照片筛选');
      assert.deepEqual(errors, []);
      await context.close();
      console.log(`批量整理与照片分页浏览器回归通过：${engine} ${width}px`);
    }
  } finally { await browser.close(); }
})().catch(error => { console.error(error); process.exitCode = 1; });
