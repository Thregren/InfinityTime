# InfinityTime（无限时光）主题 · v1.4.18

Typecho 纯图片分享前端主题，需搭配配套插件 **InfinityTime** 使用。

## 特性

- 响应式图片网格 + 懒加载；
- 灯箱大图（上一张/下一张、键盘、滚轮、移动端横滑）；
- 主图外侧 **EXIF 侧栏**：标题、描述、拍摄参数、地址，随图片加载/切换自动显示与隐藏；
- 后台上传改用**原生表单提交**，浏览器兼容性好，选图预览 / 删除 / 逐图标题描述与文件顺序严格对齐；
- 卡片显示**拍摄时间**（中文日期）；
- 左下角网站标签点击打开「关于」，页脚联系方式可后台配置；
- 本地内嵌 `iconfont` 图标，不依赖 CDN。

## 技术栈

- PHP ≥ 7.4 + Typecho 1.3；
- 原生 HTML/CSS/JS + jQuery + poptrox 灯箱；
- `MutationObserver` + `ResizeObserver` + 轮询同步 EXIF 侧栏；
- 自定义字段：`img/thumb/exif/titles/descs/addresses/device/location`。

## 环境要求

- PHP ≥ 7.4，`php-gd`、`php-exif`；
- HEIC 可选：ImageMagick + libheif 或 `heif-convert`。

## 完整说明

安装与体验详见仓库 [README.md](https://github.com/Thregren/InfinityTime) 与 [`部署与可移植性说明.md`](../../部署与可移植性说明.md)。

## 许可

MIT。由 [TimePlus](https://github.com/zhheo/TimePlus)（zhheo）二次开发而来。
