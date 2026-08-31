# InfinityTime（无限时光）

用于 Typecho 的**纯图片分享**主题 + 配套插件。上传一张图，自动得到 WebP 全图、缩略图和 EXIF；前台是图片网格 + 灯箱（右侧 EXIF 侧栏）。

## 包含

- `usr/themes/InfinityTime/`：主题（图片网格、灯箱、EXIF 侧栏、页脚关于/联系我）。
- `usr/plugins/InfinityTime/`：插件（上传转 WebP、缩略图、图集发布、站点信息、联系方式、维护）。

## 安装

1. 将整个仓库导入到 Typecho 站点根目录（或手动拷贝上面两个目录）。
2. 后台启用插件 `InfinityTime`，再启用主题 `InfinityTime`。
3. 在「InfinityTime」菜单上传图片发布图集即可。

详见 [`部署与可移植性说明.md`](部署与可移植性说明.md)。

## 环境要求

- PHP ≥ 7.4，`php-gd`、`php-exif`；
- HEIC 可选：ImageMagick + libheif 或 `heif-convert`。

## 许可

MIT。主题由 [TimePlus](https://github.com/zhheo/TimePlus)（zhheo）二次开发而来。
