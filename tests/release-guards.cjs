'use strict';
// 离线 fixture：不连接 GitHub、不写 tag、不发布任何内容。
const { test } = require('node:test');
const assert = require('node:assert/strict');
const { guardRun, guardVersions, guardExisting, ASSETS } = require('../.github/scripts/release-guard.cjs');
const repository = 'Thregren/InfinityTime';
const sha = 'a'.repeat(40);
function fixture() {
  const run = { id: 123, workflow_id: 456, name: 'CI', path: '.github/workflows/ci.yml', repository: { full_name: repository }, head_repository: { full_name: repository }, event: 'push', head_branch: 'main', status: 'completed', conclusion: 'success', head_sha: sha };
  return { event: { repository: { full_name: repository, fork: false }, workflow_run: structuredClone(run) }, run, workflow: { id: 456, path: '.github/workflows/ci.yml', state: 'active' } };
}
function check(f, main = sha) { return guardRun(f.event, repository, main, f.run, f.workflow); }
test('唯一正常同仓 main push 成功 CI 通过', () => assert.equal(check(fixture()), sha));
for (const [name, change] of [
  ['PR 事件', f => { f.event.workflow_run.event = f.run.event = 'pull_request'; }],
  ['失败 CI', f => { f.run.conclusion = 'failure'; }],
  ['未结束 CI', f => { f.run.status = 'in_progress'; }],
  ['错误分支', f => { f.run.head_branch = 'feature'; }],
  ['fork 源', f => { f.run.head_repository.full_name = 'attacker/InfinityTime'; }],
  ['fork 仓库', f => { f.event.repository.fork = true; }],
  ['事件仓库错误', f => { f.event.repository.full_name = 'attacker/InfinityTime'; }],
  ['run 仓库错误', f => { f.run.repository.full_name = 'attacker/InfinityTime'; }],
  ['陈旧 SHA', f => { f.run.head_sha = 'b'.repeat(40); }],
  ['事件 SHA 与 API 不同', f => { f.event.workflow_run.head_sha = 'b'.repeat(40); }],
  ['伪装 CI 名称', f => { f.run.name = 'Other'; }],
  ['同名不同 workflow', f => { f.run.path = '.github/workflows/evil.yml'; }],
  ['workflow API path 不符', f => { f.workflow.path = '.github/workflows/evil.yml'; }],
  ['workflow 禁用', f => { f.workflow.state = 'disabled_manually'; }],
  ['run ID 不符', f => { f.run.id++; }],
  ['workflow ID 不符', f => { f.workflow.id++; }],
]) test(`拒绝${name}`, () => { const f = fixture(); change(f); assert.throws(() => check(f)); });
test('拒绝 main 已移动', () => assert.throws(() => check(fixture(), 'b'.repeat(40))));
test('允许唯一版本 1.15.0', () => guardVersions('@version 1.15.0', "const VERSION = '1.15.0'", '{"tag_name":"1.15.0"}'));
for (const index of [0, 1, 2]) test(`拒绝版本字段 ${index} 不一致或未来版本`, () => {
  const values = ['@version 1.15.0', "const VERSION = '1.15.0'", '{"tag_name":"1.15.0"}'];
  values[index] = values[index].replace('1.15.0', '1.15.1');
  assert.throws(() => guardVersions(...values));
});
const digests = Object.fromEntries(ASSETS.map(name => [name, 'f'.repeat(64)]));
function complete() { return { tag_name: 'v1.15.0', draft: false, prerelease: false, assets: ASSETS.map(name => ({ name, state: 'uploaded', size: 42, digest: `sha256:${digests[name]}` })) }; }
test('仅 tag/release 均不存在时允许创建', () => assert.equal(guardExisting(null, null, sha, digests), 'create'));
test('已有完整一致发布仅幂等成功', () => assert.equal(guardExisting(sha, complete(), sha, digests), 'complete'));
for (const [name, tag, release] of [
  ['tag SHA 冲突', 'b'.repeat(40), complete()], ['tag-only 部分发布', sha, null], ['release 无 tag', null, complete()],
  ['draft 部分发布', sha, { ...complete(), draft: true }], ['prerelease', sha, { ...complete(), prerelease: true }],
  ['错误 tag', sha, { ...complete(), tag_name: 'v1.15.1' }], ['缺资产', sha, { ...complete(), assets: complete().assets.slice(1) }],
  ['额外资产', sha, { ...complete(), assets: [...complete().assets, complete().assets[0]] }],
  ['摘要冲突', sha, { ...complete(), assets: complete().assets.map(a => ({ ...a, digest: 'sha256:wrong' })) }],
  ['空包', sha, { ...complete(), assets: complete().assets.map(a => ({ ...a, size: 0 })) }],
  ['未上传完成', sha, { ...complete(), assets: complete().assets.map(a => ({ ...a, state: 'new' })) }],
  ['重复包名', sha, { ...complete(), assets: [complete().assets[0], complete().assets[0]] }],
]) test(`拒绝${name}`, () => assert.throws(() => guardExisting(tag, release, sha, digests)));

test('真实 ZIP 构建确定性、单根目录、排除 data，拒绝 symlink', () => {
  const fs = require('node:fs');
  const os = require('node:os');
  const path = require('node:path');
  const { execFileSync } = require('node:child_process');
  const { buildArchives } = require('../.github/scripts/release-guard.cjs');
  const root = fs.mkdtempSync(path.join(os.tmpdir(), 'release-guard-test-'));
  const old = process.cwd();
  try {
    process.chdir(root); execFileSync('git', ['init', '-q']);
    for (const kind of ['themes', 'plugins']) {
      fs.mkdirSync(`usr/${kind}/InfinityTime/data`, { recursive: true });
      fs.writeFileSync(`usr/${kind}/InfinityTime/index.php`, 'safe entry');
      fs.writeFileSync(`usr/${kind}/InfinityTime/data/private`, 'exclude plugin data');
      fs.writeFileSync(`usr/${kind}/InfinityTime/.DS_Store`, 'exclude');
    }
    execFileSync('git', ['add', 'usr']);
    const build = name => {
      const temp = path.join(root, name); const dist = path.join(temp, 'dist');
      fs.mkdirSync(dist, { recursive: true });
      const digest = buildArchives(temp, dist);
      for (const asset of ASSETS) {
        const names = execFileSync('unzip', ['-Z1', path.join(dist, asset)], { encoding: 'utf8' }).trim().split('\n');
        assert(names.every(n => n.startsWith('InfinityTime/') && !n.includes('..') && !n.includes('.DS_Store')));
        assert(names.includes('InfinityTime/index.php'));
        if (asset.includes('plugin')) assert(!names.some(n => n.startsWith('InfinityTime/data/')));
      }
      return digest;
    };
    const first = build('first');
    fs.utimesSync('usr/themes/InfinityTime/index.php', new Date(), new Date());
    assert.deepEqual(first, build('second'));
    fs.symlinkSync('/etc/passwd', 'usr/themes/InfinityTime/link');
    execFileSync('git', ['add', 'usr']);
    assert.throws(() => build('unsafe'), /非普通文件/);
  } finally { process.chdir(old); fs.rmSync(root, { recursive: true, force: true }); }
});

test('API 错误诊断仅输出白名单和截断 message，隐藏令牌与敏感头', () => {
  const { formatApiError } = require('../.github/scripts/release-guard.cjs');
  const response = { status: 403, headers: new Headers({
    'x-ratelimit-remaining': '0', 'x-ratelimit-reset': '1234567890',
    'x-ratelimit-resource': 'core', 'retry-after': '60',
    authorization: 'Bearer secret-header', 'set-cookie': 'private-cookie',
  }) };
  const result = formatApiError('GET', '/actions/runs/123?secret=query-secret', response,
    { message: `rate limited known-token Bearer unknown-token\n${'x'.repeat(500)}`, secret: 'raw-body-secret' }, ['known-token']);
  assert.match(result, /GET \/actions\/runs\/123: 403/);
  for (const detail of ['x-ratelimit-remaining=0', 'x-ratelimit-reset=1234567890', 'x-ratelimit-resource=core', 'retry-after=60']) assert(result.includes(detail));
  for (const secret of ['known-token', 'unknown-token', 'secret-header', 'private-cookie', 'raw-body-secret', 'query-secret', '\n']) assert(!result.includes(secret));
  assert(result.includes('[REDACTED]'));
  assert(result.split('; message=')[1].length <= 300);
  assert.match(formatApiError('POST', 'https://uploads.github.com/repos/a/b/releases/1/assets?name=test', response, null), /POST \/repos\/a\/b\/releases\/1\/assets: 403/);
});
test('checkout 前 inline API 错误格式与共享测试函数完全一致', () => {
  const fs = require('node:fs');
  const { formatApiError } = require('../.github/scripts/release-guard.cjs');
  const workflow = fs.readFileSync('.github/workflows/release.yml', 'utf8');
  const lines = workflow.split('\n').map(line => line.startsWith('            ') ? line.slice(12) : line).join('\n');
  assert(lines.includes(formatApiError.toString()));
});
