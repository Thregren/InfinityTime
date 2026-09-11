# InfinityTime 优化方案：把图片处理搬到浏览器端

> 缘起：读完 WordPress 7.1 客户端媒体处理的两篇官方文档后，我发现 InfinityTime 当前的服务端管线，恰好落在 WordPress 刚刚花一整个大版本搬走的那几个痛点上。
>
> 参考文章：
>
> 1. [Client-Side Media Processing in WordPress 7.1](https://make.wordpress.org/core/2026/07/22/client-side-media-processing-in-wordpress-7-1/) — Make WordPress Core dev note
> 2. [Client-Side Media Processing](https://developer.wordpress.org/block-editor/explanations/architecture/client-side-media-architecture/) — Block Editor Handbook · Architecture

---

## 0. 结论先说

三句话：

1. InfinityTime 现在把「解码 + 缩放 + 编码 WebP 两次」全放在**一次 PHP 请求里同步做完**，而且一个相册的多张照片挤在同一个请求里。这正是 WordPress 7.1 要消灭的东西。
2. 但 InfinityTime **不需要 wasm-vips**。WordPress 上 WebAssembly 是因为它要 libvips 的压缩率和格式广度；InfinityTime 只需要 WebP，浏览器的 Canvas 原生就能编码 WebP。
3. 所以最划算的路线是：**浏览器负责解码/缩放/编码，服务端退化成「校验 + 落盘 + finalize」**，并保留现有服务端管线作为透明回退。现有管线正好就是 WordPress 必须自己再实现一遍的那个 fallback——我们的回退是免费的。

按我的判断，收益最大、风险最低的顺序是：**P0（拆请求 + 草稿化）→ P1（浏览器端预处理）→ P2（HEIC 专项）→ P3（前端与元数据）**。P0 不改任何处理逻辑，一两天就能落地，却能消掉最危险的超时和半成品问题。

---

## 1. 我读到的现状（代码事实）

### 上传链路

- **后台发布页** `panel.php` 的 `create_album`：一次 multipart POST 收全部文件 → **先建 post（`status=publish`）** → 再在 `for` 循环里逐张 `ImageRepository::ingest()` → 最后写 `img/thumb/exif/addresses/...` 等字段。
- **全局钩子** `Plugin::uploadHandle()`：拦截任何 Typecho 上传（含写作页的附件上传），走同一条 ingest。
- **`ImageRepository::ingest()`**：可选 copy 原图 → 全景判定 → `MediaProcessor::process()` → 组装元数据返回。
- **`MediaProcessor::process()`**：`assertImageSize()`（60MP 上限）→ 按像素数动态 `ini_set('memory_limit')` → `ExifReader::read()` → 解码（GD；HEIC/AVIF 走 Imagick / `magick` / `convert` / `heif-convert` 子进程）→ 按 EXIF Orientation 旋转 → 超宽等比缩放 → `imagewebp()` 全图 → `imagescale()` → `imagewebp()` 缩略图。

### 一个很说明问题的信号

代码里有一大批**只为"处理发生在服务器上"而存在**的防御逻辑：

- `assertImageSize()` 的 60MP 硬上限；
- 按像素数动态改 `memory_limit`；
- `runCmd()` 用 `proc_open` 手写超时并 `proc_terminate`——因为 `exec()` 会无限期阻塞在子进程上，`max_execution_time` 管不到；
- `detectTools()` 要探测 4 种外部工具 + 3 个常见安装路径 + 插件设置里手填路径；
- `ensureDir()` 里大段的权限诊断文案。

这些代码没有一行是"业务"，全部是"在服务器上跑图像处理"这个决定的衍生成本。搬到浏览器，这一整类代码就消失了。

---

## 2. 五个痛点 ↔ WordPress 的解法

| # | InfinityTime 现在的痛点 | WP 7.1 的对应做法 | 对我们的启示 |
|---|---|---|---|
| 1 | **一次请求处理 N 张**。20 张 iPhone 照片在一个请求里串行解码，很容易撞 `max_execution_time` / FastCGI 超时；PHP fatal 后是**已发布但不完整**的图集（post 已插入、fields 没写、孤儿行残留） | 每个子尺寸做成**独立请求**，队列 + 并发上限 + 指数退避重试 | 拆成「每张图一个请求 + 前端队列」 |
| 2 | **HEIC 依赖服务器工具**。共享主机常禁用 `exec`/`proc_open`，没装 Imagick/libheif 就直接传不了 iPhone 照片 | 在**浏览器里**解码 HEIC，服务端只负责收 JPEG | 浏览器解码，服务端只兜底 |
| 3 | **PHP 内存与 CPU**。要靠 60MP 上限、动态 `memory_limit`、子进程超时来兜 | 整个处理移出 PHP | 同样移出 |
| 4 | **没有进度 / 重试 / 断点**。一次 `fetch`，失败就是整批失败，只有"发布中…"没有进度 | snackbar 进度 + 自动重试 + 离线暂停恢复 | 每张独立请求天然带上这些 |
| 5 | **半成品不可恢复**。只能事后点"清理孤儿文件"善后 | finalize 步骤：所有子项完成才收尾，另有保存锁防止提前发布 | 草稿 → finalize → 发布 |

核心结论：**痛点 1、2、3 是同一个根因的三个症状——把有状态的重计算放在了无状态的请求里。**

---

## 3. 目标架构

```
选择文件
   ↓
前端队列（并发 2，失败重试 3 次，离线暂停）
   ↓  每张图
[浏览器] 解码（含 EXIF 方向）
   ↓
[浏览器] 解析 EXIF（exifr，转换前必须读）
   ↓
[浏览器] 计算尺寸：全景判定 → full 目标尺寸 / thumb 目标尺寸
   ↓
[浏览器] 缩放 + 编码 WebP
   ↓        full.webp  (maxWidth, fullQuality)
   ↓        thumb.webp (thumbMax, quality)
   ↓        original   (可选，仅当"保留原图")
   ↓
POST 单张 → [服务端] 校验 → 去重 → 落盘 → 插入 row(cid=草稿)
   ↓
全部完成
   ↓
POST finalize → 写 fields + 绑定 cid + status: draft → publish
```

关键点：**服务端从"计算者"变成"守门人"**。它不再解码图片，但仍然要校验每一样东西。

---

## 4. 分阶段落地

### P0 · 拆请求 + 草稿化（不改处理逻辑）

**为什么先做**：这一步完全不碰 `MediaProcessor`，却能消掉最危险的超时和半成品问题，也是 P1 的必要前置——P1 要在"每张一个请求"的骨架上才能愉快地插进去。

**改什么**

1. 新增单张上传端点（`Helper::addAction()` 注册，或 `panel.php` 的 `action=upload_one`），一次只收一张图，另外接收该图的 `title` / `desc`。
2. 前端队列：并发 2；失败按 `1s → 2s → 4s` 退避重试 3 次；`navigator.onLine === false` 时暂停、`online` 事件恢复；逐张更新进度条。
3. 发布流程改为两段：
   - 建 post 时 `status = 'draft'`；
   - 全部图片入库并绑定后，发一个 finalize 请求：写 `img/thumb/exif/addresses/titles/descs/panos/device/location` 字段 → 改成 `publish`。
4. 单张失败不阻塞整批：收集失败列表，最后统一提示「9 张成功，1 张失败（HEIC 解码失败）」并提供「重试失败项」。
5. 清理遗留的 draft：进入面板时把超过 N 小时、`status=draft` 且无图片行的空图集删掉（现在的 `empty($imgs)` 分支只覆盖了同步路径，覆盖不了 PHP 超时）。

**验收**

- 传 20 张 HEIC，中途断网 → 恢复后继续，不丢不重。
- 人为让第 7 张失败 → 其余 19 张正常成集，失败项可单独重试。
- 上传未完成时，前台看不到任何半成品。

---

### P1 · 浏览器端预处理（核心）

**前端做的事**

```js
// 1) 解码（含 EXIF 方向）
//    <img> + drawImage 的兼容性最好：现代浏览器对 <img> 默认按 EXIF 方向解码。
//    OffscreenCanvas + createImageBitmap 更适合放进 Worker。
async function decode(file) {
  try {
    return await createImageBitmap(file, { imageOrientation: 'from-image' });
  } catch (e) {
    const url = URL.createObjectURL(file);
    try {
      const img = await new Promise((res, rej) => {
        const i = new Image();
        i.onload = () => res(i); i.onerror = rej; i.src = url;
      });
      return await createImageBitmap(img);
    } finally { URL.revokeObjectURL(url); }
  }
}

// 2) 缩放 + 编码
async function encode(bitmap, maxEdge, quality) {
  const scale = Math.min(1, maxEdge / Math.max(bitmap.width, bitmap.height));
  const w = Math.max(1, Math.round(bitmap.width * scale));
  const h = Math.max(1, Math.round(bitmap.height * scale));
  const canvas = new OffscreenCanvas(w, h);          // 回退：document.createElement('canvas')
  const ctx = canvas.getContext('2d');
  ctx.imageSmoothingQuality = 'high';
  ctx.drawImage(bitmap, 0, 0, w, h);
  const blob = await canvas.convertToBlob({ type: 'image/webp', quality: quality / 100 });
  //                            回退：canvas.toBlob(cb, 'image/webp', quality / 100)
  if (blob.type !== 'image/webp') throw new Error('webp-unsupported');   // 关键！
  return { blob, width: w, height: h };
}
```

**必须做的一件事：feature-detect。** 浏览器对 `toBlob` / `convertToBlob` 不支持的类型会**静默回退到 PNG**，不会报错。所以一定要检查 `blob.type === 'image/webp'`，不是就整体回退服务端管线——否则会把 PNG 当成 WebP 存进去，体积反而变大。

**质量与尺寸不再硬编码**，由服务端下发，对应 WordPress 的 REST index + `image_quality` 字段：

```json
{
  "quality": 76, "thumbMax": 1280, "maxWidth": 2560,
  "fullQuality": 82, "panoWidth": 0, "panoQuality": 92,
  "keepOriginal": true, "maxPixels": 60000000
}
```

这样滑块调完立刻生效，不用改前端代码；也避免两端各写一份默认值。

**全景判定**：客户端可以算宽高比（1.98~2.02），但**建议仍以服务端 `getimagesize()` 的结果为唯一真相**——客户端产物不可信，而 `panos` 字段会影响前端渲染。

**EXIF 必须在转换前读**（现有代码已经遵守这个顺序）。搬到客户端后有两条路：

- 用 [exifr](https://github.com/MikeKovarik/exifr)（支持 JPEG/HEIC/AVIF，按需引入体积可控）在浏览器解析，随上传一起提交 JSON；
- 或者保留服务端 `ExifReader::read()`，但注意 HEIC 在服务端本来就常常读不到（现有的 `readViaImagick()` 就是为这个加的兜底），所以客户端解析对 HEIC 反而更可靠。

保险做法：客户端解析为主，服务端对 JPEG/PNG 用 `exif_read_data()` 复核一遍，取两者的并集。

**服务端改造**

1. `ingest()` 增加「已处理」分支：收到成品 WebP 时**不再解码**，只做校验 + 落盘 + 入库。对应 WordPress 的 `generate_sub_sizes: false`——客户端告诉服务端"别再做缩略图了，我已经做完了"。
2. 新增校验，对应 WordPress sideload 端点的尺寸校验（防止"把全图伪装成 thumbnail"污染响应式图片集）：
   - `finfo` 检查真实 MIME 必须是 `image/webp`；
   - `getimagesize()` 得到的宽高必须与客户端声明的 `width/height` **完全一致**；
   - thumb 最长边 ≤ `thumbMax`（1px 容差）；
   - full 最长边 ≤ `maxWidth`（当 `maxWidth > 0`）；
   - 像素数 ≤ `MAX_PIXELS`（保留现有的 60MP 上限）。
   任一项不过 → 删除文件 + 返回 400，让前端回退重试。

**一句话原则**（直接抄 WordPress 的说法）：客户端处理是**性能优化，不是信任边界**。服务端永远重新校验。

---

### P2 · HEIC 专项

现在的 HEIC 依赖是项目最大的部署门槛。三级策略，对应 WordPress 的三级降级：

| 层级 | 环境 | 做法 |
|---|---|---|
| 1 | Safari / iOS / macOS | `createImageBitmap()` 直接用平台编解码器解 HEIC，这是 iPhone 用户的主场景，几乎零成本 |
| 2 | Chromium | 先试 `createImageBitmap()`；不行再用 `heic2any` 或 `libheif-wasm` |
| 3 | 都不行 | 回退到现有服务端管线（Imagick / `magick` / `heif-convert`） |

关于第 2 层有个**必须知道的坑**：WordPress 官方明确说，HEVC 有专利和许可限制，所以他们**不在浏览器包里打包解码器**，而是绕道「解析 ISOBMFF + WebCodecs `VideoDecoder`」走平台编解码器（macOS 的 VideoToolbox、Windows 的 HEVC 扩展）。

`heic2any` / `libheif-wasm` 是把 libheif 编进 WASM，能解决问题，但同样带着这个许可问题。**自用站点风险可接受，如果将来要公开分发插件，需要评估。**

还有一个更省事的选择：既然 iPhone 用户主要用 Safari，可以只在 Safari 上启用客户端 HEIC，Chromium 直接回退服务端。这样能拿到 80% 的收益，代码量最小。

---

### P3 · 前端与元数据

- **多一档中等尺寸**：现在灯箱直接加载 `full`。加一个 1600px 的中间尺寸（`view/`），移动端流量能再砍一半，桌面端也更稳。网格用 `thumb` + `srcset`/`sizes`。
- **懒加载**：`loading="lazy" decoding="async"` 已经在用，可再升级为 `IntersectionObserver`，参考 WordPress 把滚动监听换掉的思路。
- **去重前移**：用 `crypto.subtle.digest('SHA-256', buffer)` 在客户端算哈希，上传前先查重，省掉一次无用上传。**注意 `crypto.subtle` 需要安全上下文**（HTTPS 或 localhost），纯 HTTP 站点要有回退。
- **幂等契约**：给 `uploadHandle` 加「已处理」标记（表单字段 `pp_processed=1`，或文件名后缀）。否则客户端产出的 WebP 会被服务端再解一次码、再编一次 WebP——**二次有损压缩，画质白白下降，还白烧 CPU**。这就是 WordPress 的「插件必须写成幂等的、能正确处理两遍」在本项目的对应物。
- **进度与保存锁**：对应 WordPress 的 `UploadProgressSnackbar` 和 `useUploadSaveLock`。在 InfinityTime 里就是"未完成时不允许发布/离开页面"。

---

## 5. 与 WordPress 7.1 的异同

| 维度 | WordPress 7.1 | InfinityTime 建议方案 |
|---|---|---|
| 处理引擎 | wasm-vips（libvips 的 WASM 版） | 浏览器原生 Canvas / OffscreenCanvas |
| 为什么用 WASM | 需要 libvips 的压缩率与格式广度 | 只要 WebP，原生 API 够用 |
| 硬依赖 | `SharedArrayBuffer` → `Document-Isolation-Policy` → **只有 Chromium 137+** | **无**，所有现代浏览器都能跑（HEIC 解码是唯一分叉） |
| Firefox / Safari | 完全不支持，回退服务端 | 正常工作 |
| 浏览器覆盖 | 需要浏览器/设备内存/CPU/网络/CSP 五重检测 | 一次 `toBlob` 类型探测即可 |
| 原图上传 | 原图必须上传（服务器要存） | 可不传原图 → **上行流量净减少** |
| 服务端职责 | 存储 + 校验 + finalize | 存储 + 校验 + finalize（相同） |
| 幂等要求 | filter 双触发，插件要处理 `create`/`update` 两遍 | ingest 对「已处理」文件跳过，避免二次压缩 |
| 回退成本 | 必须同时维护两套管线 | 服务端管线本来就在，**回退是免费的** |
| 上线策略 | 从服务端处理"搬走" | 服务端处理"降级为兜底" |

三个值得强调的差异：

1. **浏览器覆盖**：WordPress 为了 libvips 付了 Chromium-only 的代价，评论区里为此吵了一整轮。InfinityTime 用 Canvas 走的是完全不同的技术路线，可以直接避开这个争议。
2. **上传流量方向相反**：WordPress 自己承认上传总流量是**变大的**（原图 + 每个子尺寸都上行）。InfinityTime 在「不保留原图」模式下只上传两个 WebP，是**净减少**——因为客户端把 2.5MB 的 HEIC 换成了 600KB 的 WebP，而 WordPress 的原图是必须原样上传的。
3. **回退成本**：WordPress 是"新增一条路径"，所以必须长期维护两套。InfinityTime 是"新增一条快路径"，现有服务端管线天然就是回退——这也是为什么我们的迁移风险比 WordPress 低得多。

---

## 6. 预期收益（按单张 12MP iPhone HEIC ≈ 2.5MB 估算）

| 指标 | 现在 | P1 改造后 | 变化 |
|---|---|---|---|
| 上行流量（不保留原图） | ~2.5 MB | ~0.6 MB（full 450KB + thumb 150KB） | **↓ ~75%** |
| 服务器解码次数 | 1 次 HEIC + 2 次 WebP 编码 | 0 | **↓ 100%** |
| 峰值内存 | 80~150 MB（还专门 `ini_set` 调过） | 几乎为 0 | — |
| 单张服务端耗时 | 1.5~4s（依赖 `heif-convert` 子进程） | < 0.3s（两次写盘） | **↓ 90%+** |
| 20 张相册 | 单个请求 30~80s，大概率超时 | 并发 2，每张一个短请求 | 不再超时 |
| 服务器依赖 | GD + **HEIC 三选一外部工具** | GD（仅回退时用） | 部署门槛大幅降低 |

最后一行是重点：**装不上 Imagick/libheif 的主机，现在也能传 iPhone 照片了。** 这可能是整个改动对实际使用体验影响最大的一条。

---

## 7. 风险与应对

| 风险 | 应对 |
|---|---|
| 浏览器编码质量与 libwebp 默认参数不同，压缩率可能略差 | 插件设置里保留「始终使用服务端处理」开关（对应 WordPress 的 `wp_client_side_media_processing_enabled`）；或引入 `@jsquash/webp`（WASM libwebp，可指定 `method`/`effort`），体积远小于 wasm-vips |
| iOS Safari 有画布面积上限（约 16.7M 像素），48MP 照片会失败 | 用 `createImageBitmap` 的 `resizeWidth/Height` 做解码期缩放；失败则回退服务端。需实测确认各版本支持情况 |
| 客户端产物不可信 | 服务端完整校验（见 P1），尤其是尺寸与 MIME |
| 用户中途关闭页面 | 草稿状态 + 「未完成图集」提示 + 遗留 draft 清理 |
| 老浏览器不支持 Canvas WebP 编码 | `blob.type` 探测 + 整体回退服务端管线，行为对用户透明 |
| 纯 HTTP 站点用不了 `crypto.subtle` | 哈希去重降级为可选，不做硬依赖 |

**回滚方案**：整个过程由一个开关控制。客户端探测失败、开关关闭、或任何一张图处理异常，都走现有服务端管线。P0 的拆请求改造与服务端处理逻辑解耦，可以独立保留。

---

## 8. 明确不做的事

- **不引入 wasm-vips**。它带来的压缩率提升，抵不上 13MB 的 worker 包，以及 `SharedArrayBuffer` / `Document-Isolation-Policy` 带来的浏览器限制——WordPress 为此付出的 Chromium-only 代价，我们不值得付。
- **不做 GIF → 视频**。WordPress 做这个是因为动图在博客里很常见；InfinityTime 是照片站，不是场景。
- **不做 HDR gain map 保留**。WebP 本身只支持 8-bit SDR，现有文档（插件说明 + 待优化清单第 12 条）已经说明这个限制。原图保留在 `original/` 以备重处理，这个策略继续沿用。

---

## 9. 建议的落地顺序

1. **P0**（1~2 天）：拆单张请求 + 前端队列 + 草稿/finalize。独立可测，先上。
2. **P1**（3~5 天）：浏览器解码/缩放/编码 + 服务端校验分支 + 设置下发。
3. **P2**（1~2 天）：HEIC 三级降级，先在 Safari 打通。
4. **P3**：中间尺寸 + `srcset`、EXIF 客户端解析、哈希去重、幂等标记。

每阶段结束都应该能独立上线，且任何时候都能退回当前的服务端管线。
