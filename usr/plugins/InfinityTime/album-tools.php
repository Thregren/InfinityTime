<?php
if (!defined('__TYPECHO_ADMIN__') || !isset($result, $cid, $canEdit)) { return; }
$link = static function (int $page, string $missing, bool $all = false) use ($cid): string {
    return pp_panel_url(['open' => $cid, 'image_page' => $page, 'image_missing' => $missing, 'image_all' => $all ? '1' : '0']) . '#albums';
};
?>
<div class="pp-album-tools" data-album-sortable="<?php echo $canEdit && $result['sortable'] ? '1' : '0'; ?>">
  <p>本次筛选共 <?php echo (int)$result['total']; ?> 张；<?php echo $result['all'] ? '已加载全部照片，可拖拽排序' : '每页最多40张'; ?>。</p>
  <nav aria-label="照片筛选">
    <?php foreach (['' => '全部照片', 'title' => '缺标题', 'desc' => '缺说明', 'address' => '缺位置'] as $value => $label): ?>
      <a href="<?php echo htmlspecialchars($link(1, $value), ENT_QUOTES); ?>" data-image-page="1" data-image-missing="<?php echo $value; ?>"><?php echo $label; ?></a>
    <?php endforeach; ?>
  </nav>
  <?php if (!$result['all']): ?><nav aria-label="相册照片分页">
    <?php if ($result['page'] > 1): ?><a href="<?php echo htmlspecialchars($link($result['page'] - 1, $result['missing']), ENT_QUOTES); ?>" data-image-page="<?php echo $result['page'] - 1; ?>" data-image-missing="<?php echo $result['missing']; ?>">上一页</a><?php endif; ?>
    <span>第 <?php echo $result['page']; ?> / <?php echo $result['pages']; ?> 页</span>
    <?php if ($result['page'] < $result['pages']): ?><a href="<?php echo htmlspecialchars($link($result['page'] + 1, $result['missing']), ENT_QUOTES); ?>" data-image-page="<?php echo $result['page'] + 1; ?>" data-image-missing="<?php echo $result['missing']; ?>">下一页</a><?php endif; ?>
  </nav><?php endif; ?>
  <?php if ($canEdit && !$result['sortable']): ?><p class="pp-meta">分页或筛选时不拖拽排序，避免遗漏其他照片。<a href="<?php echo htmlspecialchars($link(1, '', true), ENT_QUOTES); ?>" data-image-page="1" data-image-missing="" data-image-all="1">加载全部照片后排序（大相册可能较慢）</a></p><?php endif; ?>
  <?php if ($canEdit && $result['rows']): ?><form id="pp-batch-<?php echo $cid; ?>" method="post" action="<?php echo htmlspecialchars(pp_panel_url(), ENT_QUOTES); ?>" class="pp-batch-form">
    <?php echo pp_csrf_input(); ?><input type="hidden" name="action" value="batch_image_meta"><input type="hidden" name="cid" value="<?php echo $cid; ?>">
    <p>统一修改本页勾选的照片（最多100张）。只覆盖下方勾选的字段；内容留空表示清空该字段。</p>
    <label><input type="checkbox" name="apply_desc" value="1"> 统一说明</label><textarea name="desc" rows="2" maxlength="2000"></textarea>
    <label><input type="checkbox" name="apply_address" value="1"> 统一位置</label><input type="text" name="address" maxlength="1000">
    <button class="pp-btn" type="submit">保存勾选照片</button>
  </form><?php endif; ?>
</div>
