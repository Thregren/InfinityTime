# InfinityTime For Typecho · v1.7.3

A photo-sharing theme for Typecho, powered by the companion **InfinityTime** plugin.

- Infinite waterfall (no pagination; auto-load next page on scroll);
- Lightbox: poptrox single instance, prev/next, swipe, progressive blur-up loading, in-album switching;
- **360° panorama**: auto-detect ~2:1 aspect, viewable with Pannellum in the lightbox (drag/zoom), no accidental close when releasing outside; panorama lightbox has a top-right **fullscreen / exit-fullscreen** button and cards show a **"panorama" badge**;
- EXIF sidebar (title / desc / camera params / address) synced with the current photo;
- All admin operations are AJAX (no full-page reload), including avatar upload with DataTransfer file sync;
- Local embedded iconfont & Pannellum, no CDN.

## Requirements
- PHP ≥ 7.4 with `php-gd` and `php-exif`;
- Optional HEIC support: ImageMagick + libheif or `heif-convert`.

## Credits
Forked from [TimePlus](https://github.com/zhheo/TimePlus) by zhheo (MIT).
