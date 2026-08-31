# InfinityTime 插件与部署说明

## 解决的需求

| 需求 | 方案 |
| --- | --- |
| 上传自动转 WebP | 拦截 `Widget_Upload` 上传钩子，并内置于后台「照片与图集」页 |
| 缩略图生成 | 转码同时按 `infinitytimeThumbMax`（默认 1280px）生成缩略图 |
| 原文/缩略图分目录 | `usr/uploads/original/ full/ thumb/` 三个独立目录 |
| 后台提交照片/图集 | 管理页「照片与图集」支持多图上传、手写地址、设备、标签 |
| EXIF + 地址 | 转码前读 EXIF，主题在灯箱展示；**地址由后台手写**（已取消 GPS 反查） |
| HEIC | 本地/服务器用 ImageMagick+libheif 或 heif-convert 解码 |

## 目录约定

```
usr/uploads/
├── original/YYYY/MM/<hash>_<rand>.<ext>   # 原始上传（可关闭保留）
├── full/    YYYY/MM/<hash>_<rand>.webp    # 全尺寸 WebP（灯箱展示）
└── thumb/   YYYY/MM/<hash>_<rand>.webp    # 缩略图（网格展示）
```

数据库新增表（前缀 `typecho_infinitytime_images`，取决于你的前缀）：
`id, cid, original, full, thumb, width, height, size, sort, hash, exif(json), gps_lat, gps_lng, address, created`。

发布的图集仍是一篇 `type=post` 文章，主题读取自定义字段：
`img`（多行=多图全图 URL）、`thumb`（多行=缩略图 URL）、`exif`（JSON 数组）、`addresses`（JSON 数组）、`device`、`location`。

## 服务器依赖（Linux）

必须：
- `php-gd`（WebP 编码、缩略图）— 需要 `imagewebp`/`imagescale`。
- 若只传 JPG/PNG/WebP，GD 即可，无需额外依赖。

要支持 HEIC（iPhone 照片）需三选一：
- `php-imagick` + `libheif`（推荐，PHP 内处理）
- `imagemagick`（`magick`/`convert`）+ `libheif`
- `libheif-examples`（提供 `heif-convert`）

安装示例（Debian/Ubuntu）：
```bash
sudo apt install php-gd php-imagick libheif1 libheif-examples
```
或
```bash
sudo apt install php-gd imagemagick libheif1
```

> 本机 macOS 调试环境已有 `heif-convert`，可直接用。生产环境请按上面装依赖。

## 使用

1. 后台启用插件（「外观/插件」或用上面 CLI 脚本激活）。
2. 后台左侧应出现「InfinityTime 图片 → 照片与图集」。
3. 在该页：填标题、可选设备/地址/标签，多选图片上传，即发布图集。
4. 灯箱内会展示相机、ISO、光圈、快门、焦距、时间与手写地址；多图切换时参数联动。
5. 维护区可点「清理孤儿文件」或「重建缩略图/全图」。

## 图集字段与主题改动

主题（TimePlus）已改动：
- `index.php`：网格用 `thumb`，灯箱用 `img`（全图）；新增 EXIF 面板与面包屑 `data-exif/addresses`；切换图片时刷新参数。
- `assets/css/main.css`：新增 `.exif-panel` 样式（卡片隐藏、灯箱显示）。

## 注意

- 后台上传/发布走的仍是只允许被 `allowedAttachmentTypes` 放行的格式；插件对不支持的格式回退到 Typecho 默认附件处理。
- WebP 只支持 8-bit，HEIC 的 HDR gain map/10-bit 无法保留；转换依赖 libheif 的 HDR→SDR 色调映射保观感，原始 HEIC 保留在 `original/`。
- 若关闭「保留原始文件」，上传后原图即删，`rebuild` 将无原图可重建。
