# InfinityTime For Typecho · v1.5.0

A photo-sharing theme for Typecho, powered by the companion **InfinityTime** plugin.

- Auto-convert uploads to WebP full image + thumbnail (full image defaults to a 2560px longest-edge cap);
- Uploading and album management use AJAX (publish / delete album without a full page reload), with image preview, removal and per-image title/description;
- The site avatar / icon can be **uploaded directly** in the admin (auto WebP, ≤512px, quality 78, old file auto-cleaned);
- Show EXIF (camera / ISO / aperture / shutter / focal / shoot time, Chinese date);
- Optional per-image title, description and address;
- WebP settings, site info and contact icons are all configurable in the admin.

## Install

1. Put the `InfinityTime` theme folder in `usr/themes/` and enable it in 外观 → 主题.
2. Put the `InfinityTime` plugin folder in `usr/plugins/` and enable it in 设置 → 插件.
3. Publish photo albums via the `InfinityTime` admin menu.

## Requirements

- PHP ≥ 7.4 with `php-gd` and `php-exif`;
- Optional HEIC support: ImageMagick + libheif or `heif-convert`.

## Credits

Forked from [TimePlus](https://github.com/zhheo/TimePlus) by zhheo (MIT).
