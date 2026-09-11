# WordPress 7.1 客户端媒体处理（Client-Side Media Processing）分析笔记

> 参考文章：
>
> 1. [Client-Side Media Processing in WordPress 7.1](https://make.wordpress.org/core/2026/07/22/client-side-media-processing-in-wordpress-7-1/) — Make WordPress Core dev note，Adam Silverstein，2026-07-22
> 2. [Client-Side Media Processing](https://developer.wordpress.org/block-editor/explanations/architecture/client-side-media-architecture/) — Block Editor Handbook · Explanations · Architecture，首发 2026-07-17，最后更新 2026-09-04

我读完这两篇之后，最大的感受是：WordPress 把「图片处理」这件事的归属权从服务器彻底挪到了浏览器。下面按定位差异、收益与代价、架构、hook 契约、兼容性、REST 扩展、特殊格式路径、副作用和社区争议分别记录。

---

## 一、两篇文章的定位差异

两篇讲的是同一个特性，但读者和目的完全不同。我的建议是先读第一篇建立心智模型，再回第二篇查实现细节。

| | 文章 1（dev note） | 文章 2（Handbook） |
|---|---|---|
| 定位 | 发布公告，面向插件/主题作者的「你需要知道什么」 | 架构说明 + 开发者参考 |
| 视角 | 行为、契约、兼容性、FAQ、要测什么 | 内部实现、完整 hook 审计、REST 参数表、内存与性能细节 |
| 时间 | 2026-07-22 | 首发 2026-07-17，最后更新 2026-09-04 |

**一句话概括**：WordPress 7.1 把图片处理（压缩、缩放、裁剪、格式转换、EXIF 旋转、缩略图生成）从服务端 PHP（GD/Imagick）整体搬到浏览器，用 wasm-vips（libvips 的 WebAssembly 编译）在 Web Worker 里跑，处理完把原图 + 每一个子尺寸分别上传到服务器。在支持的浏览器上默认开启。

---

## 二、收益与代价

### 收益

- **输出质量一致**：不再取决于主机装的是 GD 还是 Imagick、装的是哪个版本。
- **访客下载更快**：libvips 的 JPEG 比 GD/Imagick 小约 15%（类似 MozJPEG 编码），且始终支持现代格式。
- **绕开 PHP 内存上限**：大图处理不再因为 PHP memory limit 失败，这是共享主机最常见的超时/内存溢出来源之一。
- **服务器负载下降**：图像处理被卸载到用户设备上，CPU 和内存都省出来了。
- **HEIC/HEIF**：iPhone 照片在浏览器里解码后转成 JPEG 上传，即使主机没有服务端 HEIC 支持也能用。
- **AVIF 端到端**：服务端 PHP 图像编辑器不支持 AVIF 的主机也能接收客户端处理好的 AVIF。
- **Gain Map HDR**：UltraHDR JPEG 的 gain map 能端到端保留，包括每一张生成的子尺寸。
- **动图 GIF 变视频**：不透明动图在浏览器里转成 MP4/WebM，播放效果和原 GIF 一样但流量大幅下降。
- **上传更健壮**：子尺寸是独立请求，指数退避 + jitter 重试最多 4 次，离线自动暂停、恢复在线后继续，中途网络抖动不会丢掉整批。

### 代价

官方在 FAQ 里自己点明了一个反直觉的地方：**上传时总流量其实是变大的**，因为原图加每一个子尺寸都要上行；省下来的是「访客下载」那一侧。所以我不应该把它当带宽优化来理解，它本质上是**服务器 CPU/内存优化**——真正的收益是主机不再为生成子尺寸支付 GD/Imagick 的成本。

---

## 三、架构：几个包 + 六步流水线

### 包的分工

- **@wordpress/upload-media** — 队列与编排。并发上限：上传 5 个、图片处理 2 个、GIF 转码 1 个。状态机 `queued / processing / paused / uploaded / error`，blob URL 的生命周期管理也在这一层。失败请求自动重试，指数退避加抖动，最多 4 次。
- **@wordpress/vips** — wasm-vips 的 Web Worker 封装，负责格式转换、缩放裁剪（含 smart crop）、EXIF 旋转、透明度检测、质量 0–1（默认 0.82）。
- **@wordpress/media-utils** — REST API 的 HTTP 传输层（`uploadMedia()` / `sideloadMedia()`）。
- **@wordpress/video-conversion** — mediabunny + WebCodecs 的 GIF→视频，同样是 Worker 模式，并发限制为 1。

### 六步流水线

1. **Prepare** — `prepareItem()` 判断文件类型。WASM 支持的图片走完整流水线，其他类型（视频、音频、文档）直接上传交给服务端。
2. **Transcode（可选）** — 站点配置了格式转换时（`image_editor_output_format`），用 `@wordpress/vips` 转换。带透明通道的 PNG 不会被转成 JPEG。
3. **上传原图** — 带 `generate_sub_sizes: false`，明确告诉服务端不要生成缩略图。
4. **生成缩略图** — 服务端返回 `missing_image_sizes`，客户端据此建立子尺寸任务。
5. **Sideload 子尺寸** — 每个子尺寸走 `POST /wp/v2/media/{id}/sideload`。同一篇文章的 sideload 会串行化，防止元数据竞态。
6. **Finalize** — `POST /wp/v2/media/{id}/finalize`，以 `'update'` 上下文触发 `wp_generate_attachment_metadata`。

### 几个容易忽略的实现细节

- **尺寸去重**：按「有效输出尺寸」去重。例如 Twenty Eleven 的 `large` 和核心的 `medium_large` 都是 768×1024 时，只生成一个物理文件，用数组形式的 `image_size` 参数把同一个文件注册到多个名字下。这和服务端处理重复尺寸的方式一致。
- **`-scaled` 命名**：超过 `big_image_size_threshold`（默认 2560px）会另外生成缩放副本。原图不带 `-scaled` 后缀，子尺寸文件名基于未缩放原图的 basename，只有缩放副本带 `-scaled`——跟 `wp_create_image_subsizes()` 的命名保持一致。
- **finalize 的响应很关键**：编辑器拿 finalize 的响应把块的 URL 更新为最终的服务端文件（比如 `-scaled` 版本）。没有这一步刷新，`wp_calculate_image_srcset()` 匹配不上子尺寸文件，前端就完全不会输出 srcset。
- **finalize 是 best-effort**：失败了只记日志，上传仍算成功。设计上不让插件错误阻塞用户。

---

## 四、对扩展开发者最重要的一节：hook 契约

这是我认为风险最集中的地方，单独拎出来记。

### `wp_generate_attachment_metadata` 触发两次

- 初次上传时以 `'create'` 触发（此时子尺寸还不存在）；
- finalize 之后再以 `'update'` 触发（此时子尺寸齐全）。

做水印、CDN 同步、自定义元数据的插件理论上不用改，但必须写成**幂等**的、能正确处理这两遍。好消息是这个「双触发」模式跟服务端大图延后生成子尺寸的行为本来就一致，不是 WordPress 新发明的语义。另外 finalize 失败不影响上传成功，所以插件不能靠 finalize 来判断上传是否完成。

### 仍然生效、但调用时机变了的 filter

这批 filter 从「每次编码时」变成「服务端计算好设置、通过 REST index 和上传响应下发」时：

| Filter | 值被用在哪里 |
|---|---|
| `image_editor_output_format` | 构建客户端应用的按 MIME 输出格式映射 |
| `wp_editor_set_quality` / `jpeg_quality` | 按注册尺寸逐个解析，结果放进响应的 `image_quality` 字段 |
| `big_image_size_threshold` | 经 REST index 下发，缩放发生在浏览器 |
| `image_save_progressive` | 上传时读取，客户端据此决定渐进式/隔行编码 |
| `image_strip_meta` | 经 REST index 导出，返回 false 时客户端保留全部元数据 |
| `image_max_bit_depth` | 经 REST index 导出，限制生成图片的位深 |
| `intermediate_image_sizes(_advanced)` | 计算注册尺寸和 `missing_image_sizes` 时消费 |

这些回调仍然会工作，但参数值和调用次数可能和服务端路径不一样，我需要自己确认。

### 客户端路径下永远不触发的 hook

| Hook | 原因 | 替代方案 |
|---|---|---|
| `wp_image_editors` | 没有实例化服务端 `WP_Image_Editor` | 依赖自定义图像编辑器的站点可用 `wp_client_side_media_processing_enabled` 关闭特性 |
| `image_make_intermediate_size` | 子尺寸不由服务端编辑器生成 | `wp_handle_upload` 现在为每个 sideload 的子尺寸各触发一次；finalize 那一遍给出完整元数据 |
| `image_memory_limit` | 图像处理已不涉及 PHP 内存 | 不需要 |

官方明确说这三个是**故意保持沉默**的——人为触发会让插件误以为还能改写已经编码完成、已经在浏览器里生成好的文件。

### 一个容易读错的地方

`wp_image_maybe_exif_rotate` 在 create 请求上仍然会触发，但媒体端点会把它的值**强制为 false**，客户端改从响应的 `exif_orientation` 字段自己读方向、自己旋转。文章 1 把它笼统列在「仍然生效的 filter」里，文章 2 解释得更准确——只读文章 1 会产生误解。

### 关闭与调试

```php
add_filter( 'wp_client_side_media_processing_enabled', '__return_false' );
```

调试时可以在控制台看 `window.__clientSideMediaProcessing` 判断特性是否启用。

---

## 五、兼容性与降级

整条 WASM 链路的硬依赖是 `Document-Isolation-Policy` → `SharedArrayBuffer`，所以实际上只有 Chromium 系能用：

| 浏览器 | 情况 |
|---|---|
| Chrome / Edge 137+ | 完整支持（Chrome Android 146+） |
| Firefox | 不支持，自动回退服务端 |
| Safari | 不支持 WASM 流水线，但 HEIC 的 canvas 路径仍可解码 iPhone 照片 |

### 运行时检测（任何一项不过就静默回退）

| 检查项 | 阈值 | 原因 |
|---|---|---|
| 设备内存 | > 2 GB | WASM 处理需要同时持有整图和缓冲区，低内存机器会 OOM |
| CPU 核数 | >= 2 | WASM worker 编码时可能独占一个核数十秒 |
| 网络 | 非 2g/slow-2g，且无 Save-Data | worker 包约 13 MB，不能给慢连接用户下 |
| CSP | 必须允许 `blob:` worker | worker 从 blob URL 创建，严格策略会拦掉 |

回退是完全透明的——用户看不到任何提示，也没有错误。官方还有一个插件可以用 COEP/COOP 让 Firefox/Safari 也启用，代价是部分嵌入内容在编辑器里显示不正常。

---

## 六、REST API 的扩展面

### 新增请求参数

| 参数 | 端点 | 说明 |
|---|---|---|
| `generate_sub_sizes` | `POST /wp/v2/media`、`/sideload` | 默认 true；false 时服务端跳过缩略图生成 |
| `convert_format` | `POST /wp/v2/media`、`/sideload` | 默认 true；false 时跳过 `image_editor_output_format` 转换 |
| `replace_file` | `/sideload` | 默认 false；true 时用上传的文件替换附件主文件并删旧文件，HEIC→JPEG 用 |
| `image_size` | `/sideload` | 尺寸名，可以是数组，用于把同一物理文件注册到多个同名尺寸 |
| `url` | `POST /wp/v2/media` | 传远程图片 URL，服务端下载并 sideload，避免浏览器跨源 fetch |

`generate_sub_sizes: false` 时，服务端还会临时禁用 `intermediate_image_sizes_advanced`、`fallback_intermediate_image_sizes`、`wp_image_maybe_exif_rotate`、`big_image_size_threshold` 这几个 filter。

### 新增响应字段

`exif_orientation`、`missing_image_sizes`、`filename`、`filesize`、`image_quality`。

`image_quality` 是按尺寸感知的质量值，例如：

```json
"image_quality": { "default": 82, "sizes": { "thumbnail": 60 } }
```

客户端把 WordPress 的 1–100 刻度转成 vips 的 0–1；字段缺失时（老服务端）回退到硬编码的 0.82。

### REST index 增补

对有 `upload_files` 权限的用户，`GET /` 会额外返回 `image_sizes`、`image_size_threshold`、`image_strip_meta`、`image_max_bit_depth`。

### sideload 端点的尺寸校验

我觉得这是设计得挺好的一处：original 必须与附件已存尺寸精确一致；普通注册尺寸不得超过注册的宽高（1px 舍入容差）；`scaled` / `full` / `original-heic` 豁免最大尺寸检查。校验失败就删掉上传的文件并返回 400（`rest_upload_dimension_mismatch` / `rest_upload_invalid_dimensions` / `rest_upload_unknown_size`）。这避免了「把全尺寸图伪装成 thumbnail 上传」污染响应式图片集。

---

## 七、三条特殊格式的处理路径

### HEIC / HEIF

不走 vips——HEVC 有专利授权问题，没法把解码器打包进浏览器。走 canvas 路径，三级降级：

1. `createImageBitmap()`（Safari 通过 macOS 平台编解码器）
2. `HTMLImageElement` + `OffscreenCanvas`
3. 解析 ISOBMFF 容器 + WebCodecs `VideoDecoder`（Chromium 107+，macOS 走 VideoToolbox，Windows 需 HEVC 扩展）

导出成 JPEG 上传，原 HEIC 作为 companion 文件存在 `$metadata['original']`。**注意这条路径仍是服务端生成子尺寸**（`generate_sub_sizes: true`），所以 `image_editor_output_format` 对 JPEG 子尺寸照常生效。

### UltraHDR JPEG

策略是「原样保留」：检测到 gain map 就原图不加修改地上传；子尺寸走 libvips 的 `uhdrload` / `uhdrsave`，gain map 和底图同步缩放裁剪，用 `keep: 'icc|gainmap'` 保存，所以每张缩略图本身也是合法的 UltraHDR JPEG。并且**主动跳过** `image_editor_output_format` 的格式转换，因为换编码格式会剥掉 gain map。

### 动图 GIF 转视频

完全独立于 vips 流水线，只用浏览器原生 WebCodecs + mediabunny。`isAnimatedGif()` 检查 GIF89a 的 GCE 块确认确实是动图；透明 GIF 被排除（`<video>` 还原不了 GIF 透明）。编码成 H.264/MP4 或 VP9/WebM，尺寸强制偶数。视频和首帧 poster 作为 companion 文件存在 `media_details.animated_video` 和 `_poster`，GIF 本体仍是单个 `image/gif` 附件。

编辑器侧：

- 提供「Display as video」工具栏控件，只在独立的 `core/image` 上出现（Gallery 里不提供，因为 Gallery 只接受 image 块），并且**没有 render-time PHP filter**，是纯编辑器侧的块切换。
- 可逆：「Display as GIF」能切回原来的 `core/image`。
- `core/video` 的「GIF」变体是靠属性组合 `!controls && loop && autoplay && muted && playsInline` 区分的，**没有引入新的块属性**，也没有出现在插入器里。

PHP 侧只做三件事：enqueue loader、允许 video/poster 作为合法 sideload 尺寸、删除附件时清理 companion（`gutenberg_delete_animated_gif_video`，因为核心的 `wp_delete_attachment_files()` 不认识它们）。

---

## 八、两个容易被低估的副作用

### 1. 跨源隔离的外溢

因为要 `SharedArrayBuffer`，WordPress 在 `load-post.php`、`load-post-new.php`、`load-site-editor.php`、`load-widgets.php` 上发送：

```
Document-Isolation-Policy: isolate-and-credentialless
```

由于 DIP 是 per-document 的，不会带来 COOP/COEP 那种全页约束，但有两个连带效果值得注意：

- **编辑器里现在任何代码都能拿到 `SharedArrayBuffer` 和高精度计时器**。这既是风险也是机会——插件可以借此做自己的多线程或 WASM 功能。
- **跨源资源被自动加 CORS 属性**：服务端通过 `wp_add_crossorigin_attributes()` 给跨源的 `audio` / `link` / `script` / `video` / `source` 加 `crossorigin="anonymous"`，客户端还有 MutationObserver 处理动态插入的元素。但 `img` 被**刻意排除**，否则会破坏大量第三方图片预览。

另外，`action` 参数不是 `edit` 的 admin 页面会跳过 DIP，为的是保护依赖同源 iframe 的页面构建器。`gutenberg_use_document_isolation_policy` filter 可以控制是否应用 DIP，关掉它也会一并关掉客户端媒体处理。

### 2. WASM 内存管理

WASM 线性内存只增不减，所以官方做了一套相当克制的回收策略：

- 启动时 `Cache.max(0)` 关掉 libvips 的操作缓存——否则跨批次的缓存会让内存无界增长直到 OOM。
- 每完成 50 次 vips 操作就终止并重建 worker（失败也计数，防止连续失败绕过预算），但在途操作不会被中途杀掉。
- 批量子尺寸生成只解码一次源图，`copyMemory()` 之后逐尺寸 `thumbnailImage()`。

---

## 九、并发、进度与保存锁

- **并发限制**：上传 5、图片处理 2、视频转码 1。达到上限时新任务排队，操作完成后自动出队。
- **sideload 串行化**：同一篇文章的 sideload 彼此串行，避免附件元数据更新的竞态。
- **上传进度**：编辑器里用 snackbar（`UploadProgressSnackbar`）显示批次进度，只统计用户上传的原图，忽略生成的子尺寸。运行时用 `wp.a11y.speak()` 播报开始和完成，不做逐 tick 播报，避免屏幕阅读器被刷屏。
- **保存锁**：队列非空时 `useUploadSaveLock` 会 `lockPostSaving`，防止用户发布或保存引用了尚未完成 sideload 的附件的草稿。「保存草稿」「发布」和 Ctrl/Cmd+S 都会检查这个锁。

---

## 十、社区争议与未决问题

dev note 的评论区比正文信息量还大，我记几条关键的：

- **每个子尺寸一个请求带来的服务器压力**（clorith 提出）：一个电商站 5 个自定义尺寸加 5 个核心尺寸就是 10 个请求，每个都占一个 worker 槽位。官方回复是队列 + 并发上限 5，目前**不可调**。
- **建议 PHP 侧也上 libvips、甚至弃用 GD**：官方指到 trac #65473，并承认主机对 libvips 的支持度是推广障碍，而「把处理搬到浏览器正好越过这个限制」。
- **按需生成尺寸**（首次被请求时才生成并缓存）：trac #21295，**不在 7.1 范围内**。官方说一个阻塞点是 WordPress 不直接负责图片服务，拦截不存在的图片文件来做按需生成不好做。
- **Chromium-only 是不是开放网络的坏信号**：官方说 Firefox 已表达支持 DIP 的意向，过渡期方案就是那个插件。
- **插件作者想要明确的「处理契约」**：怎么判断走的是客户端还是服务端路径、哪些子尺寸是客户端生成的、有没有发生回退、处理何时算完成。官方的立场是插件**不应该需要关心**走哪条路径——文件产物和 meta 最终一致就行（就像不同主机的行为本来就有差异）。我觉得这个回答有点回避，评论区提的那个可观测性需求后续大概率会被反复追问。
- **缺少真实基准数据**：有人希望能有端到端上传耗时、服务端 CPU/内存、生成文件体积、总流量这几项在新旧两条路径上、跨不同设备等级的对比数据。

---

## 十一、如果要适配，我的行动清单

1. 审计所有挂 `wp_generate_attachment_metadata` 的代码，确保幂等、能正确处理 `create` 和 `update` 两遍。
2. 检查 `wp_image_editors` / `image_make_intermediate_size` / `image_memory_limit` 的使用——如果必须依赖它们，准备用 `wp_client_side_media_processing_enabled` 关掉特性。
3. 插件如果会设 CSP，确保 `worker-src 'self' blob:`，否则 worker 建不起来会静默回退。
4. 导入远程媒体改成把 URL 交给服务端（`url` 参数），不要在前端 `fetch()` 图片字节——在 credentialless 隔离文档里跨源 fetch 直接失败。
5. 检查编辑器里加载的跨源脚本和嵌入在 DIP 下是否正常，注意 `img` 不会被加 `crossorigin`。
6. 测 HEIC、测服务端无 AVIF 支持的主机上的 AVIF、测 UltraHDR JPEG（子尺寸仍应是带 gain map 的 UltraHDR）、测动图 GIF 的往返切换和 companion 清理。
7. 用 `wp_client_side_media_processing_enabled` 返回 false 跑一遍，确认回退路径可用。

---

## 十二、两篇文档之间的出入（读的时候要留意）

- 跨源隔离函数的命名不一致：文章 1 写 `wp_start_cross_origin_isolation_output_buffer()`，文章 2 写 `gutenberg_start_cross_origin_isolation_output_buffer()`（在 `lib/media/load.php`）。
- `wp_image_maybe_exif_rotate` 的描述文章 1 过简，文章 2 才是准确行为。

结论：以文章 2（Handbook）为准更稳妥，它是跟着代码更新的参考文档；文章 1 更适合作为速览和「要测什么」的清单。
