# InfinityTime（无限时光）主题 · v1.7.3

Typecho 纯图片分享前端主题，需搭配配套插件 **InfinityTime** 使用。

## 特性

- **无限瀑布流**（无分页，滚到底自动加载下一页）；
- 灯箱大图：poptrox 单实例、上一张/下一张、移动端滑动、**渐进加载 blur-up**；多图图集内切换；
- **360° 全景**：宽高比 ≈2:1 自动识别，灯箱内用 Pannellum 可拖拽旋转/滚轮缩放，拖拽松手（含视窗外）不误关；全景图灯箱右上角有**全屏/退出全屏按钮**，卡片带**「全景」角标**；
- 主图外侧 **EXIF 侧栏**：标题、描述、拍摄参数、地址，随图片切换联动；
- 后台上传与图集/图片/设置操作 **全部 AJAX**（不整页刷新），支持头像直传、`DataTransfer` 同步文件选区；
- 卡片显示**拍摄时间**（中文日期）；左下角网站标签打开「关于」面板（标题固定「关于」）；
- 本地内嵌 `iconfont` 与 `pannellum`（全景库），不依赖 CDN。

## 环境要求

- PHP ≥ 7.4，`php-gd`、`php-exif`；
- HEIC 可选：ImageMagick + libheif 或 `heif-convert`。

## 说明

安装/体验详见仓库（[Thregren/InfinityTime](https://github.com/Thregren/InfinityTime)）与《部署与可移植性说明.md》。

## 许可
MIT。由 [TimePlus](https://github.com/zhheo/TimePlus)（zhheo）二次开发而来。
