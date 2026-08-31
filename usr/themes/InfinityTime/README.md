# 无限时光（Infinity Time）

一款用于 Typecho 的纯图片分享主题。搭配官方配套插件 **InfinityTime** 即可：

- 上传图片自动转 WebP 全图 + 缩略图，并按设置保留原图；
- 读取并展示 EXIF（相机/ISO/光圈/快门/焦距/拍摄时间，中文日期）；
- 每张图片支持可选标题、描述、拍摄地址；
- 图集发布、站点信息、联系方式（可视化选图标）都在后台可视化配置；
- 无需改动 Typecho 原生文件，安装主题 + 插件即可使用。

## 安装

1. 将 `InfinityTime` 主题目录放到 `usr/themes/`，在后台「外观 → 主题」启用。
2. 将配套 `InfinityTime` 插件放到 `usr/plugins/`，在后台「设置 → 插件」启用。
3. 进入「InfinityTime」菜单，上传图片发布图集；前台即为图片网格 + 灯箱（EXIF 侧栏）。

> 详见仓库根目录的 `部署与可移植性说明.md`。

## 环境要求

- PHP ≥ 7.4（推荐 8.x），启用 `php-gd`（WebP/缩略图）、`php-exif`；
- HEIC/HEIF 可选：安装 ImageMagick + libheif 或 `heif-convert`（非标准路径可在插件设置里指定）。

## 版权与致谢

本主题由 [TimePlus](https://github.com/zhheo/TimePlus)（原作者 zhheo）二次开发而来，保留其原始 MIT 许可。
