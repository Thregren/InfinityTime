'use strict';
// 仅用于本次已授权的 v1.15.0；失败或部分发布必须人工核查，绝不覆盖资产。
const fs = require('node:fs');
const path = require('node:path');
const os = require('node:os');
const crypto = require('node:crypto');
const { execFileSync } = require('node:child_process');
const VERSION = '1.15.0';
const TAG = `v${VERSION}`;
const ASSETS = ['infinitytime-theme.zip', 'infinitytime-plugin.zip'];
function insist(ok, message) { if (!ok) throw new Error(message); }
function guardRun(event, repository, mainSha, run, workflow) {
  insist(event.repository?.full_name === repository && event.repository?.fork === false, '仓库不匹配或为 fork');
  for (const r of [event.workflow_run, run]) {
    insist(r && r.repository?.full_name === repository && r.head_repository?.full_name === repository, 'CI 来自其他仓库');
    insist(r.event === 'push' && r.head_branch === 'main' && r.status === 'completed' && r.conclusion === 'success', 'CI 必须是 main push 成功');
    insist(/^[a-f0-9]{40}$/.test(r.head_sha) && r.head_sha === mainSha, 'CI SHA 已过时或不合法');
    insist(r.name === 'CI' && r.path === '.github/workflows/ci.yml', 'CI 工作流不匹配');
  }
  insist(run.id === event.workflow_run.id && run.workflow_id === event.workflow_run.workflow_id, 'CI run 标识不一致');
  insist(workflow.id === run.workflow_id && workflow.path === '.github/workflows/ci.yml' && workflow.state === 'active', 'CI workflow 标识不匹配');
  return mainSha;
}
function guardVersions(theme, plugin, release) {
  insist(theme.match(/@version\s+([\d.]+)/)?.[1] === VERSION, '主题版本不是 1.15.0');
  insist(plugin.match(/const VERSION = '([\d.]+)'/)?.[1] === VERSION, '插件版本不是 1.15.0');
  insist(JSON.parse(release).tag_name === VERSION, '版本清单不是 1.15.0');
}
function guardExisting(tagSha, release, sha, digests) {
  if (!tagSha && !release) return 'create';
  insist(tagSha === sha, '已有 tag SHA 冲突或 release 缺 tag');
  insist(release && release.tag_name === TAG && !release.draft && !release.prerelease, '部分发布或 release 状态冲突，需人工核查');
  insist(release.assets?.length === ASSETS.length, '发布资产数量不完整或冲突');
  for (const name of ASSETS) {
    const matches = release.assets.filter(a => a.name === name);
    insist(matches.length === 1 && matches[0].state === 'uploaded' && matches[0].size > 0, '发布资产缺失或未完成');
    insist(matches[0].digest === `sha256:${digests[name]}`, `发布资产摘要冲突：${name}`);
  }
  return 'complete';
}
function buildArchives(temp, dist) {
  const digests = {};
  for (const [kind, asset] of [['themes', ASSETS[0]], ['plugins', ASSETS[1]]]) {
    const prefix = `usr/${kind}/InfinityTime/`;
    const files = execFileSync('git', ['ls-files', '-z', '--', prefix], { encoding: 'utf8' }).split('\0').filter(Boolean).sort();
    const stage = path.join(temp, kind); fs.mkdirSync(stage);
    const names = [];
    for (const file of files) {
      const relative = file.slice(prefix.length);
      if (path.basename(file) === '.DS_Store' || (kind === 'plugins' && relative.startsWith('data/'))) continue;
      insist(fs.lstatSync(file).isFile() && !/[\r\n]/.test(file), '发布包包含非普通文件或异常路径');
      const name = `InfinityTime/${relative}`;
      const destination = path.join(stage, name);
      fs.mkdirSync(path.dirname(destination), { recursive: true }); fs.copyFileSync(file, destination);
      fs.chmodSync(destination, 0o644); fs.utimesSync(destination, 946684800, 946684800); names.push(name);
    }
    insist(names.length > 0, '发布包为空');
    execFileSync('zip', ['-X', '-q', path.join(dist, asset), '-@'], { cwd: stage, input: names.join('\n') + '\n', env: { ...process.env, TZ: 'UTC' } });
    execFileSync('unzip', ['-t', path.join(dist, asset)]);
    digests[asset] = crypto.createHash('sha256').update(fs.readFileSync(path.join(dist, asset))).digest('hex');
  }
  return digests;
}
// 仅输出诊断白名单；禁止输出令牌、完整响应头或原始响应正文。
function formatApiError(method, endpoint, response, body, secrets = []) {
  const clean = value => {
    let text = String(value ?? '');
    for (const secret of secrets.filter(Boolean)) text = text.split(secret).join('[REDACTED]');
    return text.replace(/Bearer\s+[^\s,;]+/gi, 'Bearer [REDACTED]').replace(/[\r\n\x00-\x1f\x7f]/g, ' ');
  };
  const route = endpoint.startsWith('https://') ? new URL(endpoint).pathname : endpoint.split('?')[0];
  const details = ['x-ratelimit-remaining', 'x-ratelimit-reset', 'x-ratelimit-resource', 'retry-after']
    .map(name => `${name}=${clean(response.headers.get(name) ?? 'unknown').slice(0, 100)}`).join(' ');
  const message = typeof body?.message === 'string' ? clean(body.message).slice(0, 300) : 'unavailable';
  return `GitHub API ${clean(method)} ${clean(route)}: ${response.status}; ${details}; message=${message}`;
}
async function main() {
  insist(process.env.GITHUB_EVENT_NAME === 'workflow_run', '仅允许 workflow_run');
  const repo = process.env.GITHUB_REPOSITORY;
  const event = JSON.parse(fs.readFileSync(process.env.GITHUB_EVENT_PATH, 'utf8'));
  const token = process.env.GH_TOKEN;
  insist(repo === 'Thregren/InfinityTime' && token, '缺少令牌或目标仓库错误');
  const root = `https://api.github.com/repos/${repo}`;
  // 公开仓库 Actions 元数据匿名读取；不增加 actions 权限，限流/拒绝即失败。
  async function api(endpoint, { method = 'GET', body, optional = false, binary = false } = {}) {
    const url = endpoint.startsWith('https://') ? endpoint : `${root}${endpoint}`;
    insist(url.startsWith(`${root}/`) || url.startsWith(`https://uploads.github.com/repos/${repo}/`), '非预期 API 目标');
    const response = await fetch(url, { signal: AbortSignal.timeout(30000), method, headers: { ...(endpoint.startsWith('/actions/') && method === 'GET' ? {} : { Authorization: `Bearer ${token}` }), Accept: 'application/vnd.github+json', 'X-GitHub-Api-Version': '2022-11-28', ...(body ? { 'Content-Type': binary ? 'application/zip' : 'application/json' } : {}) }, body: body ? (binary ? body : JSON.stringify(body)) : undefined });
    if (optional && response.status === 404) return null;
    if (!response.ok) {
      const errorBody = await response.json().catch(() => null);
      throw new Error(formatApiError(method, endpoint, response, errorBody, [token]));
    }
    return response.json();
  }
  const verifyDownloads = async release => {
    for (const name of ASSETS) {
      const asset = release.assets.find(item => item.name === name);
      const expected = `https://github.com/${repo}/releases/download/${TAG}/${name}`;
      insist(asset.browser_download_url === expected, '下载地址不是目标发布资产');
      // 公共下载不携带令牌；下载后的真实字节必须匹配本次确定性构建。
      const response = await fetch(expected, { signal: AbortSignal.timeout(30000) });
      insist(response.ok, `发布资产无法下载：${name} ${response.status}`);
      const digest = crypto.createHash('sha256').update(Buffer.from(await response.arrayBuffer())).digest('hex');
      insist(digest === digests[name], `发布资产下载摘要冲突：${name}`);
    }
  };
  const latestMain = async () => (await api('/git/ref/heads/main')).object.sha;
  const sha = await latestMain();
  const run = await api(`/actions/runs/${event.workflow_run.id}`);
  const workflow = await api(`/actions/workflows/${run.workflow_id}`);
  guardRun(event, repo, sha, run, workflow);
  insist(execFileSync('git', ['rev-parse', 'HEAD'], { encoding: 'utf8' }).trim() === sha, 'checkout SHA 不匹配');
  guardVersions(fs.readFileSync('usr/themes/InfinityTime/index.php', 'utf8'), fs.readFileSync('usr/plugins/InfinityTime/Plugin.php', 'utf8'), fs.readFileSync('usr/themes/InfinityTime/releases.json', 'utf8'));
  // 只打包 git 跟踪的普通文件；固定时间与顺序，支持幂等摘要验证。
  const temp = fs.mkdtempSync(path.join(os.tmpdir(), 'infinitytime-release-'));
  const dist = path.join(temp, 'dist'); fs.mkdirSync(dist);
  const digests = {};
  try {
    Object.assign(digests, buildArchives(temp, dist));
    const getTagSha = async () => {
      const ref = await api(`/git/ref/tags/${TAG}`, { optional: true });
      if (!ref) return null;
      let object = ref.object;
      for (let depth = 0; object.type === 'tag' && depth < 5; depth++) object = (await api(`/git/tags/${object.sha}`)).object;
      insist(object.type === 'commit', 'tag 不是 commit'); return object.sha;
    };
    let release = await api(`/releases/tags/${TAG}`, { optional: true });
    const tagSha = await getTagSha();
    if (guardExisting(tagSha, release, sha, digests) === 'complete') {
      await verifyDownloads(release);
      console.log(`已完整发布且 SHA/两包摘要一致：${release.html_url}`); return;
    }
    const changelog = fs.readFileSync('usr/themes/InfinityTime/update.md', 'utf8');
    const notes = changelog.split(`## ${VERSION}\n`)[1]?.split(/^## /m)[0]?.trim();
    insist(notes, '缺少本版本 changelog');
    // 所有打包与只读验证完成后，再验证 main，随后才执行不可覆盖的创建操作。
    insist(await latestMain() === sha, '创建 tag 前 main 已改变');
    await api('/git/refs', { method: 'POST', body: { ref: `refs/tags/${TAG}`, sha } });
    release = await api('/releases', { method: 'POST', body: { tag_name: TAG, target_commitish: sha, name: `InfinityTime ${TAG}`, body: notes, draft: true, prerelease: false } });
    for (const name of ASSETS) {
      const upload = `${release.upload_url.split('{')[0]}?name=${encodeURIComponent(name)}`;
      const asset = await api(upload, { method: 'POST', binary: true, body: fs.readFileSync(path.join(dist, name)) });
      insist(asset.name === name && asset.state === 'uploaded' && asset.digest === `sha256:${digests[name]}`, '上传资产摘要校验失败');
    }
    insist(await latestMain() === sha, '发布前 main 已改变，保留 draft 待核查');
    insist(await getTagSha() === sha, '发布前 tag SHA 改变');
    release = await api(`/releases/${release.id}`);
    guardExisting(sha, { ...release, draft: false }, sha, digests);
    await api(`/releases/${release.id}`, { method: 'PATCH', body: { draft: false } });
    release = await api(`/releases/${release.id}`);
    guardExisting(await getTagSha(), release, sha, digests);
    await verifyDownloads(release);
    console.log(`发布成功：${release.html_url}；SHA=${sha}；两份 ZIP SHA-256 已验证`);
  } finally { fs.rmSync(temp, { recursive: true, force: true }); }
}
module.exports = { guardRun, guardVersions, guardExisting, buildArchives, formatApiError, VERSION, TAG, ASSETS };
if (require.main === module) main().catch(error => { console.error(error.message); process.exitCode = 1; });
