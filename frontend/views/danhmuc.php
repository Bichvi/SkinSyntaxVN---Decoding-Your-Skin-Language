<?php
// Trang "Tất cả danh mục": danh mục cha + danh mục lá (chip kèm số sản phẩm) dẫn tới tatca.
$categoryGroups = is_array($categoryGroups ?? null) ? $categoryGroups : [];
$categoryMessage = trim((string)($categoryMessage ?? ''));
$leafUrl = static function (array $ids): string {
    $q = [];
    foreach ($ids as $id) {
        $q[] = 'loai[]=' . (int)$id;
    }
    return BASE_URL . '/index.php?r=tatca' . ($q ? '&' . implode('&', $q) : '');
};
?>
<div class="container my-4 danhmuc-page">
  <nav aria-label="breadcrumb" class="mb-2">
    <ol class="breadcrumb small mb-0">
      <li class="breadcrumb-item"><a href="<?= BASE_URL ?>/index.php" class="text-decoration-none" style="color:#183B2B;">Trang chủ</a></li>
      <li class="breadcrumb-item active" aria-current="page">Tất cả danh mục</li>
    </ol>
  </nav>
  <div class="d-flex flex-wrap justify-content-between align-items-end gap-2 mb-4">
    <div>
      <span class="home-section-kicker">MUA SẮM THEO DANH MỤC</span>
      <h1 class="fw-bold m-0" style="color:#0F172A; font-size:1.75rem;">Tất cả danh mục</h1>
      <span class="text-muted small">Chọn danh mục cha để xem mọi sản phẩm, hoặc chọn danh mục con để lọc chính xác hơn.</span>
    </div>
    <a href="<?= BASE_URL ?>/index.php?r=tatca" class="fw-semibold text-decoration-none small" style="color:#183B2B;">Xem toàn bộ sản phẩm &rarr;</a>
  </div>

  <?php if ($categoryMessage !== ''): ?>
    <div class="alert alert-warning"><?= h($categoryMessage) ?></div>
  <?php elseif (!$categoryGroups): ?>
    <div class="alert alert-light border">Chưa có danh mục nào đang có sản phẩm.</div>
  <?php endif; ?>

  <div class="danhmuc-grid">
    <?php foreach ($categoryGroups as $g): ?>
      <?php
        $gProduct = is_array($g['product'] ?? null) ? $g['product'] : [];
        $gName = (string)$g['name'];
        $gImg = function_exists('product_image_source') ? product_image_source($gProduct) : (string)($gProduct['link_hinh_anh'] ?? $gProduct['hinh_anh'] ?? '');
        $gFallback = function_exists('homepage_visual_asset') ? homepage_visual_asset('category', $gName) : '';
        $gResolved = $gImg !== '' ? resolve_image_url($gImg) : '';
        if ($gResolved === '' || $gResolved === default_placeholder_image()) {
            $gResolved = $gFallback ?: default_placeholder_image();
        }
      ?>
      <section class="danhmuc-card" aria-label="<?= h($gName) ?>">
        <a class="danhmuc-card__head" href="<?= h($leafUrl((array)$g['leaf_ids'])) ?>">
          <span class="danhmuc-card__img"><img loading="lazy" decoding="async" src="<?= h($gResolved) ?>" alt="<?= h($gName) ?>" data-image-placeholder="<?= h($gFallback ?: default_placeholder_image()) ?>" onerror="window.SkinSyntaxImageFallback(this)"></span>
          <span class="danhmuc-card__title">
            <strong><?= h($gName) ?></strong>
            <small><?= number_format((int)$g['count'], 0, ',', '.') ?> sản phẩm</small>
          </span>
          <i class="fas fa-arrow-right" aria-hidden="true"></i>
        </a>
        <div class="danhmuc-card__chips">
          <?php foreach ((array)$g['leaves'] as $leaf): ?>
            <a class="danhmuc-chip" href="<?= h($leafUrl([(int)$leaf['id']])) ?>"><?= h((string)$leaf['name']) ?> <span><?= number_format((int)$leaf['count'], 0, ',', '.') ?></span></a>
          <?php endforeach; ?>
        </div>
      </section>
    <?php endforeach; ?>
  </div>
</div>

<style>
  .danhmuc-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 18px; }
  .danhmuc-card { background: #fff; border: 1px solid #D6E3DB; border-radius: 14px; padding: 16px; }
  .danhmuc-card__head { display: flex; align-items: center; gap: 14px; text-decoration: none; color: #183B2B; background: #EBF2EE; border-radius: 12px; padding: 10px 14px; transition: background .2s ease; }
  .danhmuc-card__head:hover { background: #DCE9E1; color: #183B2B; }
  .danhmuc-card__img { width: 64px; height: 64px; flex: 0 0 64px; border-radius: 12px; overflow: hidden; background: #fff; }
  .danhmuc-card__img img { width: 100%; height: 100%; object-fit: cover; }
  .danhmuc-card__title { display: flex; flex-direction: column; flex: 1; min-width: 0; }
  .danhmuc-card__title strong { font-size: 1.05rem; }
  .danhmuc-card__title small { color: #5B6B61; }
  .danhmuc-card__chips { display: flex; flex-wrap: wrap; gap: 8px; margin-top: 14px; }
  .danhmuc-chip { display: inline-flex; align-items: center; gap: 6px; padding: 6px 12px; border: 1px solid #D6E3DB; border-radius: 999px; background: #fff; color: #183B2B; font-size: .85rem; text-decoration: none; transition: background .2s ease, color .2s ease; }
  .danhmuc-chip span { color: #5B6B61; font-size: .75rem; }
  .danhmuc-chip:hover, .danhmuc-chip:focus-visible { background: #183B2B; border-color: #183B2B; color: #fff; }
  .danhmuc-chip:hover span, .danhmuc-chip:focus-visible span { color: #DCE9E1; }
  @media (max-width: 767.98px) { .danhmuc-grid { grid-template-columns: 1fr; } }
</style>
