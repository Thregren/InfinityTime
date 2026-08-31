# InfinityTime · 无限时光

**一款用于 Typecho 的纯图片分享方案。** 上传一张图，自动得到 **WebP 全图 + 缩略图**，并读取 **EXIF**；前台是「图片网格 + 灯箱」，灯箱右侧是 EXIF 侧栏；后台一切可视化配置。

> 主题 `InfinityTime` 负责展示，插件 `InfinityTime` 负责处理。**只需安装这两部分，无需改动任何 Typecho 原生文件。**

## 功能特性

### 图片处理（后端核心）

- **上传即转 WebP**：拦截 Typecho 上传钩子，自动生成全尺寸 WebP 与缩略图。
- **质量 / 尺寸可调**：WebP 质量、缩略图最长边、全图最长边上限均可配置，且带**恢复默认最佳设置**按钮。
- **分目录存储**：`usr/uploads/original/ full/ thumb/` 三套目录，可选保留原始文件。
- **EXIF**：读取相机、ISO、光圈、快门、焦距、拍摄时间（**中文日期，如 `2026年06月21日 17:46:53`**）。
- **HEIC / HEIF / AVIF**：自动探测 `Imagick / ImageMagick(magick|convert) / heif-convert`，非标准路径可在后台指定，无需改代码。
- **批量维护**：`重建缩略图/全图`、`清理孤儿文件`，均带进度条与「无任务」提示，自动清理空目录。

### 图集与展示（前台体验）

- **图片网格**：响应式 CSS Grid，缩略图懒加载，悬停显示标题/设备/时间。
- **灯箱大图**：主图 + 上一张/下一张，支持**键盘方向键、滚轮、移动端横滑**切换；图片加载完成后才显示 EXIF 侧栏，切换时随图片一起出现/消失。
- **EXIF 侧栏**：主图外侧，显示该图「标题 / 描述 / 拍摄参数 / 地址」，多图切换自动联动、不遮挡主图。
- **每图信息**：每张图片可单独填**标题、描述、地址**（均可选，留空前台不显示）。
- **右下网站标签**：点击左下角头像+名称即打开「关于」面板。
- **页脚**：关于介绍、联系方式、备案号可控。

### 后台配置（管理体验）

- **WebP 转换设置**：质量滑块实时显示数值 + 一键恢复默认 + 转换工具状态（Imagick/ImageMagick/heif-convert/GD WebP）。
- **上传并发布图集**：标题、设备、地址、标签 + 多图上传。
- **已发布图集**：默认折叠、展示**发布时间 + 图片张数**；展开可编辑图集信息、每图标题/描述/地址、逐张删除。
- **站点信息 / 关于**：头像、站点名称、一句话说明、关于介绍。
- **联系方式 / 联系我**：可视化选择图标（本地 iconfont，无需敲类名）、增删/启停，前台页脚自动展示启用的项。
- **全部表单**：贴合 Typecho 原生后台风格，设置持久化、回显正确。

## 技术栈

### 后端

| 层 | 技术 |
| --- | --- |
| 平台 | PHP ≥ 7.4（推荐 8.x）+ [Typecho](https://typecho.org) 1.3 |
| 图片 | `php-gd`：`imagewebp` / `imagescale`（WebP 编码、缩放） |
| EXIF | `php-exif` + 自研 `lib/ExifReader.php` |
| HEIC | 可选 `php-imagick` / `ImageMagick` / `heif-convert`（自动探测 + 后台指定路径） |
| 存储 | 独立表 `typecho_infinitytime_images`（兼容 MySQL / SQLite / PostgreSQL），图集数据复用 Typecho `contents` + `fields` |
| 插件机制 | `Helper::addMenu/addPanel`、上传钩子 `Widget_Upload::uploadHandle`、`Helper::options()` 读写配置 |

### 前端

| 层 | 技术 |
| --- | --- |
| 结构/样式 | 原生 HTML + CSS（CSS Grid 网格、Flex 布局、响应式） |
| 交互 | 原生 JS + jQuery 3.4 |
| 灯箱 | [poptrox](https://github.com/ajlkn/jquery.poptrox)（jQuery 灯箱库）二次封装 |
| 同步逻辑 | `MutationObserver` + `ResizeObserver` + 轻量轮询（EXIF 侧栏跟随图片加载/切换显示） |
| 图标 | 本地内嵌 `iconfont`（`woff`/`ttf` + 本地 CSS），**不依赖 CDN**，断网可用 |
| 主题渲染 | Typecho 模板引擎 + 自定义字段（`img/thumb/exif/titles/descs/addresses/device/location`） |

## 安装

### 方式一：直接放入 Typecho

将仓库里的两份目录拷到你的 Typecho 站点：

```text
usr/themes/InfinityTime/     # 主题
usr/plugins/InfinityTime/    # 插件
```

> 或用 GitHub Release 里的 `infinitytime-theme.zip` / `infinitytime-plugin.zip` 解压后分别放入对应目录。

### 启用

1. 后台 → **设置 → 插件**，启用 **InfinityTime**（会自动建表并注册「InfinityTime」菜单）。
2. 后台 → **外观 → 主题**，启用 **InfinityTime**。
3. 进入 **InfinityTime** 菜单，上传图片发布图集；回前台即可看到图片墙。

### 环境要求

- PHP ≥ 7.4，启用 `php-gd`、`php-exif`。
- 想支持 iPhone 的 **HEIC**：安装 `php-imagick + libheif`，或 `ImageMagick + libheif`，或 `libheif-examples`（提供 `heif-convert`）。装在非标准路径时在插件设置里填路径即可。

## 使用体验

### 后台

1. **WebP 转换设置** — 拖质量滑块、设缩略图最大边、全图上限、是否保留原图；点「恢复默认最佳设置」一键回 `82 / 1280 / 0 / 保留` 并保存。
2. **上传并发布图集** — 填标题（必填）、设备、地址、标签，多选图片上传；每张自动转 WebP + 缩略图 + EXIF。
3. **已发布图集** — 默认折叠只显示「标题 + 张数 + 发布时间」；点开后可编辑整组信息，或给**每张图**填标题/描述/地址、删除。
4. **站点信息 / 关于** — 头像、站点名称、副标题、关于介绍（支持 HTML），留空则用主题自带设置。
5. **联系方式 / 联系我** — 点图标按钮可视化选头像图标，可增删、启用/停用。
6. **维护** — 重建缩略图/全图、清理孤儿文件，带进度。

### 前台

- 首页是响应式图片墙；点击缩略图打开灯箱。
- 灯箱右侧悬浮 EXIF 侧栏：**标题、描述、拍摄参数（相机/ISO/光圈/快门/焦距/时间）、地址**；左右切换到下一张会自动刷新，侧栏只在「当前图片加载完成后」出现，切换时随图片一起隐藏/重现，位置不残留。
- 左下角「头像 + 名称」点击即打开「关于」；页脚展示关于介绍与启用的联系方式。

## 目录结构

```text
InfinityTime-release/
├─ README.md
├─ 部署与可移植性说明.md
└─ usr/
   ├─ themes/InfinityTime/
   │  ├─ index.php          # 首页模板（网格 + 灯箱 + EXIF 侧栏）
   │  ├─ functions.php      # 主题配置、pp_opt/pp_date_cn 等
   │  ├─ post.php / comments.php
   │  ├─ assets/
   │  │  ├─ css/  (main.css / iconfont.css)
   │  │  ├─ js/   (main.js / jquery.poptrox.min.js …)
   │  │  └─ fonts/ (iconfont.woff / .ttf，本地图标)
   │  └─ infos / screenshot.png
   └─ plugins/InfinityTime/
      ├─ Plugin.php         # 插件主体：激活/停用/配置/上传钩子/建表
      ├─ panel.php          # 后台管理面板（已嵌入 Typecho 后台壳）
      └─ lib/
         ├─ ExifReader.php
         ├─ MediaProcessor.php
         └─ ImageRepository.php
```

## 常见问题

- **HEIC 不转码？** 先在插件设置里确认「转换工具」状态；若三项都不可用，安装 `imagemagick+libheif` 或 `heif-convert`，或在「heif-convert 路径」填服务端绝对路径。
- **改了 WebP 设置但旧图没变？** 去「维护」点「重建缩略图/全图」。
- **图标不显示？** 已内嵌本地字体，正常情况下无需联网；若旧浏览器不支持 woof/ttf，可忽略（链接仍可用）。

## 发版（自动）

推送 `vX.Y.Z` 标签即可自动发版（GitHub Actions）：

```bash
# 1. 确认版本号一致（主题 @version / 插件 VERSION / releases.json）
# 2. 打标签并推送
git tag v1.1.0
git push origin v1.1.0
```

工作流 `.github/workflows/release.yml` 会自动：
1. 校验 `tag` 与三处版本号是否一致（不一致则失败）；
2. 打包 `usr/themes/InfinityTime` → `infinitytime-theme.zip`、`usr/plugins/InfinityTime` → `infinitytime-plugin.zip`；
3. 创建对应 `vX.Y.Z` 的 Release 并上传这两个 zip（自动生成更新日志）。

## 许可与致谢

MIT。主题由 [TimePlus](https://github.com/zhheo/TimePlus)（原作者 zhheo）二次开发而来；特此致谢。
