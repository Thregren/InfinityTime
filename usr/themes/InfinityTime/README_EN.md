# InfinityTime For Typecho · v1.13.4

A photo-sharing theme for Typecho, powered by the companion **InfinityTime** plugin.

This release hardens HTML and link filtering, restores upload previews, preserves upload order without DataTransfer, fixes avatar saving and rebuild settings, and keeps image paths in sync after sorting or deletion. See `update.md` for the full changelog.

- Infinite waterfall (no pagination; auto-load next page on scroll);
- Lightbox: poptrox single instance, unified bottom prev/next buttons on desktop & mobile, progressive blur-up loading, in-album switching;
- **360° panorama**: auto-detect ~2:1 aspect, viewable with Pannellum in the lightbox (drag/zoom), no accidental close when releasing outside; panorama lightbox has a top-right **fullscreen / exit-fullscreen** button and cards show a **"panorama" badge**;
- EXIF sidebar (title / desc / camera params / address) synced with the current photo;
- Lightbox "theme palette": extracts 3 representative colors (swatch + uppercase hex) below camera params;
- Lightbox **RGB histogram**: shares the same sampling as the palette for a live exposure/color-curve view;
- All admin operations are AJAX (no full-page reload), including avatar upload; submitted files follow the preview order without requiring DataTransfer;
- Local embedded iconfont & Pannellum, no CDN.

## Requirements
- PHP ≥ 7.4 with `php-gd` and `php-exif`;
- Optional HEIC support: ImageMagick + libheif or `heif-convert`.

## Credits
Forked from [TimePlus](https://github.com/zhheo/TimePlus) by zhheo (MIT).
