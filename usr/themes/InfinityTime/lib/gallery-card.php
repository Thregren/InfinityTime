<?php
/** $album 只包含 gallery.php 返回的已筛选公开照片。 */
$photos = $album['photos'];
$first = $photos[0];
$flags = JSON_UNESCAPED_UNICODE | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_HEX_TAG;
$attrs = [
    'images' => array_column($photos, 'url'), 'previews' => array_column($photos, 'preview'),
    'photo-ids' => array_column($photos, 'id'), 'exif' => array_column($photos, 'exif'),
    'addresses' => array_column($photos, 'address'), 'titles' => array_column($photos, 'title'),
    'descs' => array_column($photos, 'description'), 'panos' => array_map(static function ($p) { return $p['pano'] ? 1 : 0; }, $photos),
    'dims' => array_map(static function ($p) { return $p['width'] && $p['height'] ? $p['width'] . 'x' . $p['height'] : ''; }, $photos),
    'variants' => array_column($photos, 'variants'),
];
$alt = $first['title'] ?: ($album['title'] ?: '照片');
?>
<article class="thumb img-area" data-cid="<?php echo $album['id']; ?>" data-month="<?php echo htmlspecialchars($month, ENT_QUOTES); ?>">
  <a class="image my-photo" data-cid="<?php echo $album['id']; ?>" aria-label="<?php echo htmlspecialchars($album['title'] ?: $alt, ENT_QUOTES); ?>" href="<?php echo htmlspecialchars($first['url'], ENT_QUOTES); ?>"
    <?php foreach ($attrs as $name => $value): ?> data-<?php echo $name; ?>='<?php echo json_encode($value, $flags); ?>'<?php endforeach; ?>>
    <img class="zmki_px my-photo" alt="<?php echo htmlspecialchars($alt, ENT_QUOTES); ?>" src="<?php echo htmlspecialchars($first['preview'], ENT_QUOTES); ?>" loading="lazy" decoding="async"
      data-src="<?php echo htmlspecialchars($first['preview'], ENT_QUOTES); ?>"
      <?php if ($first['width'] && $first['height']): ?>width="<?php echo $first['width']; ?>" height="<?php echo $first['height']; ?>"<?php endif; ?> />
  </a>
  <h2><?php echo htmlspecialchars($album['title']); ?></h2>
  <?php if ($album['description'] !== ''): ?><div class="content-wrapper"><p><?php echo nl2br(htmlspecialchars($album['description'])); ?></p></div><?php endif; ?>
  <div class="tag-info tag-info-bottom">
    <?php if ($album['device']): ?><span class="tag-device"><i class="iconfont icon-camera-lens-line"></i><?php echo htmlspecialchars($album['device']); ?></span><?php endif; ?>
    <?php if ($album['location']): ?><span class="tag-location"><i class="iconfont icon-map-pin-2-line"></i><?php echo htmlspecialchars($album['location']); ?></span><?php endif; ?>
    <?php if ($first['month']): ?><span class="tag-time"><i class="iconfont icon-time-line"></i><?php echo htmlspecialchars($first['month']); ?></span><?php endif; ?>
  </div>
  <div class="gallery-card-links">
    <?php foreach ($album['categories'] as $i => $name): ?><a class="tag-categorys" href="<?php echo htmlspecialchars(pp_gallery_url($__galleryBase, $__galleryRequest, ['category' => $album['category_slugs'][$i], 'cursor' => '', 'album' => 0, 'photo' => '']), ENT_QUOTES); ?>"><?php echo htmlspecialchars($name); ?></a><?php endforeach; ?>
    <?php foreach ($album['tags'] as $tag): ?><a class="tag-list" href="<?php echo htmlspecialchars(pp_gallery_url($__galleryBase, $__galleryRequest, ['tag' => $tag, 'cursor' => '', 'album' => 0, 'photo' => '']), ENT_QUOTES); ?>"><?php echo htmlspecialchars($tag); ?></a><?php endforeach; ?>
    <a class="gallery-album-link" href="<?php echo htmlspecialchars($album['url'], ENT_QUOTES); ?>">相册链接</a>
  </div>
  <?php if (in_array(1, $attrs['panos'], true)): ?><span class="pano-badge">全景</span><?php endif; ?>
  <noscript>
    <ul class="gallery-photo-list">
      <?php foreach ($photos as $photo): ?><li id="photo-<?php echo $album['id'] . '-' . $photo['id']; ?>"><a href="<?php echo htmlspecialchars($photo['url'], ENT_QUOTES); ?>"><img src="<?php echo htmlspecialchars($photo['preview'], ENT_QUOTES); ?>" alt="<?php echo htmlspecialchars($photo['title'] ?: $album['title'], ENT_QUOTES); ?>" loading="lazy" decoding="async" /><?php echo htmlspecialchars($photo['title'] ?: '查看照片'); ?></a><?php if ($photo['description']): ?><p><?php echo htmlspecialchars($photo['description']); ?></p><?php endif; ?><a href="<?php echo htmlspecialchars($photo['url_link'], ENT_QUOTES); ?>">照片链接</a></li><?php endforeach; ?>
    </ul>
  </noscript>
</article>
