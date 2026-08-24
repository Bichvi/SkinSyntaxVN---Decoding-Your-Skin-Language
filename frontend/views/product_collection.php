<?php
$publicFilters = is_array($publicFilters ?? null) ? $publicFilters : [];
$brandOptions = is_array($brandOptions ?? null) ? $brandOptions : [];
$categoryOptions = is_array($categoryOptions ?? null) ? $categoryOptions : [];
$result = is_array($collectionResult ?? null) ? $collectionResult : ['items' => [], 'total' => 0, 'page' => 1, 'perPage' => 20, 'pages' => 1];
$type = (string)($collectionType ?? $result['type'] ?? 'best_seller');
$message = trim((string)($collectionMessage ?? ''));

$titles = [
    'best_seller' => 'Sản Phẩm Bán Chạy Nhất',
    'top_rated' => 'Sản Phẩm Đánh Giá Cao',
    'discount' => 'Sản Phẩm Đang Giảm Giá',
    'most_viewed' => 'Sản Phẩm Được Quan Tâm Nhiều',
    'new' => 'Sản Phẩm Mới Lên Kệ',
];
$badges = [
    'best_seller' => 'Bán chạy',
    'top_rated' => 'Đánh giá cao',
    'discount' => 'Đang giảm giá',
    'most_viewed' => 'Đang quan tâm',
    'new' => 'Mới lên kệ',
];
$sortOptions = [
    'default' => 'Mặc định',
    'price_asc' => 'Giá tăng dần',
    'price_desc' => 'Giá giảm dần',
    'best_seller' => 'Bán chạy',
    'top_rated' => 'Đánh giá cao',
    'discount' => 'Giảm giá nhiều',
    'newest' => 'Mới nhất',
    'most_viewed' => 'Nhiều lượt xem',
];
$page = max(1, (int)($result['page'] ?? 1));
$pages = max(1, (int)($result['pages'] ?? 1));
$title = $titles[$type] ?? $titles['best_seller'];
$renderCard = static function (array $product, string $badgeLabel = ''): void {
    include __DIR__ . '/partials/goiy_product_card.php';
};
$pageUrl = static function (int $targetPage) use ($type, $publicFilters): string {
    $query = array_filter([
        'r' => 'product_collection',
        'type' => $type,
        'keyword' => trim((string)($publicFilters['keyword'] ?? '')),
        'danh_muc' => trim((string)($publicFilters['danh_muc'] ?? '')),
        'thuong_hieu' => trim((string)($publicFilters['thuong_hieu'] ?? '')),
        'gia_tu' => trim((string)($publicFilters['gia_tu'] ?? '')),
        'gia_den' => trim((string)($publicFilters['gia_den'] ?? '')),
        'sort' => trim((string)($publicFilters['sort'] ?? 'default')),
        'page' => $targetPage,
    ], static fn($value) => $value !== '');
    return BASE_URL . '/index.php?' . http_build_query($query);
};
?>

<div class="container my-4">
  <div class="p-4 p-md-5 bg-white border" style="border-radius: 28px !important; border-color: #E2EADF !important; box-shadow: 0 10px 30px rgba(33, 84, 39, 0.04);">
    <div class="d-flex flex-wrap justify-content-between align-items-end gap-3 mb-4">
      <div>
        <span class="text-uppercase fw-bold text-success small" style="letter-spacing: 0.08em;">SKINSYNTAX COLLECTION</span>
        <h2 class="fw-bold m-0" style="color: #1A2F1A; font-size: 2rem;"><?= h($title) ?></h2>
      </div>
      <a class="btn btn-outline-success rounded-pill px-4 fw-bold" href="<?= h(BASE_URL . '/index.php?r=goiy') ?>">
        <i class="fas fa-wand-magic-sparkles me-2"></i> Quay lại gợi ý AI
      </a>
    </div>

    <form class="p-4 mb-4 rounded-4" method="get" action="<?= h(BASE_URL . '/index.php') ?>" style="background: #F8FAF8; border: 1px solid #E2EADF;">
      <input type="hidden" name="r" value="product_collection">
      <input type="hidden" name="type" value="<?= h($type) ?>">

      <div class="row g-3 align-items-end">
        <div class="col-12 col-md-3">
          <label for="collectionKeywordInput" class="form-label text-uppercase fw-bold small text-muted">Từ khóa</label>
          <input id="collectionKeywordInput" class="form-control" type="search" name="keyword" value="<?= h((string)($publicFilters['keyword'] ?? '')) ?>" placeholder="serum, chống nắng, BHA..." style="border-radius: 999px; background: #FFF; border-color: #E2EADF;">
        </div>

        <div class="col-6 col-md-2">
          <label for="collectionCategorySelect" class="form-label text-uppercase fw-bold small text-muted">Danh mục</label>
          <select id="collectionCategorySelect" class="form-select" name="danh_muc" style="border-radius: 999px; background: #FFF; border-color: #E2EADF; font-size: 0.88rem;">
            <option value="">Tất cả danh mục</option>
            <?php foreach ($categoryOptions as $cat): ?>
              <?php $catName = (string)($cat['ten_danh_muc'] ?? $cat['danh_muc_day_du'] ?? ''); ?>
              <?php if ($catName !== ''): ?>
                <option value="<?= h($catName) ?>" <?= (($publicFilters['danh_muc'] ?? '') === $catName ? 'selected' : '') ?>><?= h($catName) ?></option>
              <?php endif; ?>
            <?php endforeach; ?>
          </select>
        </div>

        <div class="col-6 col-md-2">
          <label for="collectionBrandSelect" class="form-label text-uppercase fw-bold small text-muted">Thương hiệu</label>
          <select id="collectionBrandSelect" class="form-select" name="thuong_hieu" style="border-radius: 999px; background: #FFF; border-color: #E2EADF; font-size: 0.88rem;">
            <option value="">Tất cả thương hiệu</option>
            <?php foreach ($brandOptions as $brand): ?>
              <?php $brandName = (string)($brand['ten_thuong_hieu'] ?? $brand['thuong_hieu'] ?? ''); ?>
              <?php if ($brandName !== ''): ?>
                <option value="<?= h($brandName) ?>" <?= (($publicFilters['thuong_hieu'] ?? '') === $brandName ? 'selected' : '') ?>><?= h($brandName) ?></option>
              <?php endif; ?>
            <?php endforeach; ?>
          </select>
        </div>

        <div class="col-6 col-md-2">
          <label for="collectionSortSelect" class="form-label text-uppercase fw-bold small text-muted">Sắp xếp</label>
          <select id="collectionSortSelect" class="form-select" name="sort" style="border-radius: 999px; background: #FFF; border-color: #E2EADF; font-size: 0.88rem;">
            <?php foreach ($sortOptions as $value => $label): ?>
              <option value="<?= h($value) ?>" <?= (($publicFilters['sort'] ?? 'default') === $value ? 'selected' : '') ?>><?= h($label) ?></option>
            <?php endforeach; ?>
          </select>
        </div>

        <div class="col-6 col-md-3 d-flex gap-2">
          <button class="btn w-100 text-white fw-bold" type="submit" style="background: #215427; border-radius: 999px;">Lọc sản phẩm</button>
          <a class="btn btn-outline-secondary fw-bold" href="<?= h(BASE_URL . '/index.php?r=product_collection&type=' . rawurlencode($type)) ?>" style="border-radius: 999px;">Xóa</a>
        </div>
      </div>
    </form>

    <?php if ($message !== ''): ?>
      <div class="alert alert-info rounded-4 mb-4"><?= h($message) ?></div>
    <?php endif; ?>

    <?php $items = is_array($result['items'] ?? null) ? $result['items'] : []; ?>
    <?php if (empty($items)): ?>
      <div class="p-5 text-center bg-white rounded-4 border text-muted" style="border-radius: 20px !important; border-color: #E2EADF !important;">
        <i class="fas fa-filter fs-2 text-success mb-3"></i>
        <h4 class="fw-bold text-dark">Chưa có sản phẩm phù hợp bộ lọc</h4>
        <p>Bấm nút "Xóa" bộ lọc để tìm lại tất cả sản phẩm thuộc bộ sưu tập này.</p>
      </div>
    <?php else: ?>
      <div class="row g-3">
        <?php foreach ($items as $product): ?>
          <div class="col-6 col-md-3">
            <?php $renderCard($product, $badges[$type] ?? 'Sản phẩm'); ?>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>

    <?php if ($pages > 1): ?>
      <nav class="mt-5" aria-label="Phân trang sản phẩm">
        <ul class="pagination justify-content-center gap-1">
          <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>">
            <a class="page-link rounded-circle d-grid place-items-center" href="<?= h($pageUrl(max(1, $page - 1))) ?>" style="width: 40px; height: 40px; border-color: #E2EADF; color: #215427;">Trước</a>
          </li>
          <?php for ($i = max(1, $page - 2); $i <= min($pages, $page + 2); $i++): ?>
            <li class="page-item <?= $i === $page ? 'active' : '' ?>">
              <a class="page-link rounded-circle d-grid place-items-center fw-bold" href="<?= h($pageUrl($i)) ?>" style="width: 40px; height: 40px; border-color: #E2EADF; <?= ($i === $page) ? 'background: #215427; color: #FFF; border-color: #215427;' : 'color: #1A2F1A;' ?>"><?= h((string)$i) ?></a>
            </li>
          <?php endfor; ?>
          <li class="page-item <?= $page >= $pages ? 'disabled' : '' ?>">
            <a class="page-link rounded-circle d-grid place-items-center" href="<?= h($pageUrl(min($pages, $page + 1))) ?>" style="width: 40px; height: 40px; border-color: #E2EADF; color: #215427;">Sau</a>
          </li>
        </ul>
      </nav>
    <?php endif; ?>
  </div>
</div>
