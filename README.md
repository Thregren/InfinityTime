# InfinityTime · 无限时光

一款面向 [Typecho](https://typecho.org) 的**纯图片分享方案**。上传一张图，自动得到 **WebP 全图 + 缩略图**并读取 **EXIF**；前台是**瀑布流图片墙 + 灯箱**，灯箱侧栏展示拍摄参数；后台可视化配置一切。

> 从 **v1.4.15/1.4.16** 起：上传改用**原生表单提交**（各类浏览器可靠），并加入**目录可写自愈**、**失败原因明确提示**、**大图内存放宽**、**HEIC 自动回退 `heif-convert`**、**图集列表批量查询（N+1）**、**单图 60MP 上限保护**、**HEIC EXIF 的 Imagick 兜底**；v1.4.17–v1.4.24 还带来**灯箱单实例（合并重复 poptrox）、移动端左右滑动切图、各宽度保留圆角、全图默认最长边 2560px、移除未引用 webfonts与死库 util.js、去冗余样式、灯箱渐进加载（blur-up）、灯箱全图质量独立可调** 等性能/体验优化；**v1.4.25** 让**多图图集在灯箱内可直接 上一张/下一张/左右滑动**（不再只能看第一张），且 **EXIF 侧栏随当前图片联动**。

> 主题 `InfinityTime` 负责展示，插件 `InfinityTime` 负责处理。**只需安装这两部分，无需改动任何 Typecho 原生文件。**

> **v1.4.26 / v1.5.0**：后台**头像 / 站点图标支持直接上传**（自动转 WebP、最长边 ≤512px、质量 78、自动清理旧图）；**删除图集 / 发布图集改为 AJAX**，页面不整页刷新；修复后台成功提示被边栏拉伸的 bug；前台「关于」标题改为固定「关于」、新增 InfinityTime Theme 链接、打开遮罩调淡。

## 功能特性

### 图片处理（后端核心）

- **上传即转 WebP**：拦截 Typecho 上传钩子，自动生成全尺寸 WebP 与缩略图。
- **上传可靠**：后台面板用 **AJAX 提交**（`fetch` + `FormData`，保持 `DataTransfer` 同步文件选区与隐藏字段），发布图集不整页刷新；支持选图预览、逐张删除、每图标题/描述，文件顺序严格对齐。
- **质量 / 尺寸可调**：WebP 质量、缩略图最长边、全图最长边上限、**灯箱全图质量**（默认 82，独立于缩略图质量）均可配置，附「恢复默认」。
- **分目录存储**：`usr/uploads/original/ full/ thumb/`，可保留原始文件（关闭上传后即删，重建依赖原图）。
- **权限自愈**：写入前校验目标目录可写，能修复时自动 `chmod`；仍不可写时给出**具体目录与一条可执行修复命令**。
- **错误可见**：入库失败原因（目录 / 写入 / 解码错误）**直接显示在面板**，不再只报笼统“没有图片成功入库”。
- **大图放宽**：处理前把 `memory_limit` 放宽到 256M，降低高像素手机照片因内存不足失败的概率。
- **单图 60MP 上限保护**：解码前校验分辨率，超大图给出明确提示，避免 GD/Imagick 内存溢出。
- **全图默认 2560px**：默认「全图最长边上限」为 2560px，灯箱大图更小、打开更快；后台可调整，或设为 0 不裁剪。
- **EXIF 读取**：相机、ISO、光圈、快门、焦距（等效焦距优先）、闪光、拍摄时间（中文格式）、`lens`（兼容 `UndefinedTag:0xA434`）、GPS / 方向。
- **HEIC / HEIF / AVIF**：自动探测 `Imagick / ImageMagick(magick|convert) / heif-convert`，**Imagick 失败自动回退 `heif-convert`**；非标准路径可在后台指定。
- **转码健壮性**：外部工具带**超时**（避免卡死），重建采用**单次请求时间预算 + 失败计数**，进度文件**原子写**。
- **批量维护**：`重建缩略图/全图`、`清理孤儿文件`，带进度条与结果统计，自动清理空目录。

### 首页与展示（前台体验）

- **瀑布流**：JS 把图片卡按响应式列数（≥1300px 4 列、≥900px 3 列、其余 2 列）分配到**弹性列**，**首行完全顶对齐**。
- **懒加载**：原生 `loading="lazy"` + 滚动检测按需加载。
- **统一边距与圆角**：照片与页边、照片之间均为 14px；照片 10px 圆角。
- **悬浮底栏**：仿苹果 Newsroom 的**居中圆角胶囊**；点击左侧站点信息打开「关于」面板，右侧全屏按钮。
- **灯箱大图**：poptrox 弹窗（**单一实例，避免重复弹窗 DOM**），上一张/下一张，**移动端左右滑动切换**；**渐进加载（blur-up）**——先显示缩略图模糊预览、全图加载后约 0.45s 渐入，兼顾速度与清晰度；宽幅/全景照片会**收缩主图宽度**为 EXIF 侧栏让位。**多图图集组内切换**：图集内即可 上一张/下一张/左右滑动，到图集边界再切到相邻图集，**EXIF 侧栏随当前图片联动**。
- **EXIF 侧栏**：主图外侧显示标题 / 描述 / 拍摄参数 / 地址；多图切换自动联动，侧栏只在灯箱开启时刷新。
- **每图信息**：每张图片可单独填标题、描述、地址（可选，留空不显示）。
- **统一圆角**：网格卡与灯箱照片在**各宽度（含窄屏/移动端）**均保留圆角。

### 性能与体积

- 移除主题中未引用的 **`assets/webfonts/`**（Font Awesome，约 2.7M，主题发布包由 ~2.3M 降到 ~1.1M）。
- 移除死库 **`assets/js/util.js`**，去除 `<head>` 重复加载的 `main.css` 与无条件加载的 `noscript.css`。
- 首页内嵌 **`data-exif` 去掉 null/空字段**，多图/大图集时首屏 HTML 更轻。
- 静态资源 `css/js` 缓存 12h；`webp/avif` 图片由服务器 Nginx 配置 **30 天缓存**（部署时生效）。

### 后台配置（管理体验）

- **上传并发布图集**：标题、设备、地址、标签 + 多图上传；选择后展示**大图预览**，可**逐张填写标题/描述**，可移除已选图片（追加选图不覆盖）。
- **已发布图集**：默认折叠展示标题 + 发布时间 + 张数；展开可编辑图集信息、每图标题/描述/地址、逐张删除；**删除图集为 AJAX 局部删除**。
- **站点信息 / 关于**：头像（**支持直接上传，自动转 WebP ≤512px**）、站点名称、一句话说明、关于介绍。
- **联系方式 / 联系我**：可视化选择图标（本地 iconfont），增删 / 启停。
- **WebP 转换设置**：质量滑块 + 恢复默认 + 转换工具状态（含 `imagick / magick / convert / heif-convert` 是否可用）。

## 技术栈

### 后端

| 层 | 技术 |
| --- | --- |
| 平台 | PHP ≥ 7.4（推荐 8.x）+ Typecho 1.3 |
| 图片 | `php-gd`：`imagewebp` / `imagescale` |
| EXIF | `php-exif` + 自研 `Lib/ExifReader.php` |
| HEIC | 可选 `php-imagick` / `ImageMagick` / `heif-convert`（自动探测 + 后台指定路径 + 超时保护 + Imagick 失败回退） |
| 存储 | 独立表 `typecho_infinitytime_images`（兼容 MySQL / SQLite / PostgreSQL），图集复用 Typecho `contents` + `fields` |
| 插件机制 | `Helper::addMenu/addPanel`、上传钩子 `Widget_Upload::uploadHandle`、`Helper::options()` |

### 前端

| 层 | 技术 |
| --- | --- |
| 布局 | 原生 HTML + CSS（Flex 弹性列瀑布流、响应式、圆角胶囊） |
| 交互 | 原生 JS + jQuery 3.4 |
| 灯箱 | [poptrox](https://github.com/ajlkn/jquery.poptrox) 二次封装 |
| 同步 | `MutationObserver` + 轻量轮询（EXIF 侧栏开箱才轮询） |
| 懒加载 | 原生 `loading="lazy"` |
| 图标 | 本地内嵌 `iconfont`（`woff`/`ttf`），不依赖 CDN |
| 字段 | 自定义字段：`img/thumb/exif/titles/descs/addresses/device/location` |

## 安装

### 方式一：直接放入 Typecho

把仓库里的两份目录拷到 Typecho 站点：

```text
usr/themes/InfinityTime/     # 主题
usr/plugins/InfinityTime/    # 插件
```

或用 GitHub Release 里的 `infinitytime-theme.zip` / `infinitytime-plugin.zip`，解压后分别放入 `usr/themes/InfinityTime/` 与 `usr/plugins/InfinityTime/`。

> 部署包（`dist/`）已打包好主题与插件，`usr/data/`、`usr/uploads/{original,full,thumb}/` 已预建为空目录，上传即用。

### 启用

1. 后台 **设置 → 插件**，启用 **InfinityTime**（自动建表、自检上传目录，并注册「InfinityTime」菜单）。
2. 后台 **外观 → 主题**，启用 **InfinityTime**。
3. 进入 **InfinityTime** 菜单上传图片发布图集，回前台即可看到瀑布流图片墙。

### 环境要求

- PHP ≥ 7.4，启用 `php-gd`、`php-exif`。
- 想支持 iPhone 的 **HEIC**：安装 `php-imagick + libheif`，或 `ImageMagick + libheif`，或 `libheif-examples`（`heif-convert`）；非标准路径在插件设置里填。若服务器 PHP 禁用了 `exec`/`shell_exec`/`proc_open`，插件会给出明确提示（此时 JPG/PNG/WebP 不受影响，HEIC 需放开对应函数或改用 JPG）。

## 使用体验

### 后台

1. **WebP 转换设置** — 质量滑块、缩略图最大边、全图上限、是否保留原图；「恢复默认」回到 `82 / 1280 / 0 / 保留`。
2. **上传并发布图集** — 填标题（必填）、设备、地址、标签；多选图片后按大图预览展示，可逐张填标题/描述、移除；**AJAX 发布**，成功后图集列表局部刷新并清空表单，失败原因在顶部明确提示。
3. **已发布图集** — 点击展开可编辑整组信息，或给每张图填标题/描述/地址、删除；**删除为 AJAX 局部删除**。
4. **站点信息 / 关于 / 联系方式** — 可视化配置头像（可**直接上传**）、名称、关于介绍、联系方式图标。
5. **维护** — 重建缩略图/全图、清理孤儿文件（带进度与失败统计）。

### 前台

- 首页是瀑布流图片墙，照片**首行完全顶对齐**；滚动时缩略图按需加载。
- 点击缩略图打开灯箱；灯箱外侧悬浮 EXIF 侧栏：标题、描述、拍摄参数（相机 / 镜头 / ISO / 光圈 / 快门 / 焦距 / 闪光 / 时间）、地址。
- 宽幅照片会自动收缩主图宽度，避免 EXIF 侧栏遮挡。
- 底部悬浮胶囊：左侧站点信息可点开「关于」面板，右侧全屏按钮可切换全屏。

## 目录结构

```text
InfinityTime-release/
├─ README.md
├─ 部署与可移植性说明.md
└─ usr/
   ├─ themes/InfinityTime/
   │  ├─ index.php          # 首页模板（瀑布流 + 灯箱 + EXIF 侧栏）
   │  ├─ functions.php      # 主题配置、pp_opt/pp_date_cn/pp_exif_lens 等
   │  ├─ post.php / comments.php
   │  ├─ assets/ (css / js / fonts / img)
   │  └─ screenshot.png
   └─ plugins/InfinityTime/
      ├─ Plugin.php         # 激活/停用/配置/上传钩子/建表/自检
      ├─ panel.php          # 后台管理面板（原生表单上传）
      └─ Lib/ (ExifReader / MediaProcessor / ImageRepository)
```

## 常见问题

- **上传没反应 / 失败？** 1.4.15 起面板用**原生表单提交**，请确认已加载新 `panel.php`（浏览器强制刷新：Mac `Cmd+Shift+R`，Win `Ctrl+F5`）。若提示目录不可写，按面板给出的目录执行 `chmod -R 775 目录` 或 `chown -R <运行用户>:<组> 目录`。
- **HEIC 不转码？** 在插件设置确认「转换工具」状态；不可用则安装 `imagemagick+libheif` 或 `heif-convert`，或在「heif-convert 路径」填服务端绝对路径；若因 PHP 禁用 `exec`/`proc_open`，需放开对应函数（插件会在启用/上传时明确提示）。
- **改了 WebP 设置但旧图没变？** 去「维护」点「重建缩略图/全图」。
- **某些照片没有镜头数据？** 部分手机（如 iPhone）与个别相机不写入镜头型号，此时不显示「镜头」行；拍到带镜头信息的照片会自动显示，兼容 `UndefinedTag:0xA434`。
- **HEIC 的 HDR 能保留吗？** WebP/缩略图仅支持 8-bit SDR，无法保留 HEIC 的 10-bit / HDR gain map；转码时依赖 libheif/ImageMagick 的色调映射尽量保观感，**原始 HEIC 会保留在 `original/`**。
- **图标不显示？** 已内嵌本地字体，无需联网；旧浏览器不支持 `woff`/`ttf` 时忽略即可（链接仍可用）。

## 发版（自动）

推送 `vX.Y.Z` 标签即可自动发版（GitHub Actions）：

```bash
# 1. 确认版本号一致（主题 @version / 插件 VERSION / releases.json 三处）
# 2. 打标签并推送
git tag v1.5.0
git push origin v1.5.0
```

工作流 `.github/workflows/release.yml` 会自动：
1. 校验 `tag` 与三处版本号是否一致（不一致则失败）；
2. 打包主题与插件为两个 zip；
3. 创建对应 Release 并上传 zip，**正文自动带上 `update.md` 当前版本的 Changelog**。

## 许可与致谢

MIT。主题由 [TimePlus](https://github.com/zhheo/TimePlus)（原作者 zhheo）二次开发而来；特此致谢。
