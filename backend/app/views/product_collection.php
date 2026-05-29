<?php
$publicFilters = is_array($publicFilters ?? null) ? $publicFilters : [];
$brandOptions = is_array($brandOptions ?? null) ? $brandOptions : [];
$categoryOptions = is_array($categoryOptions ?? null) ? $categoryOptions : [];
$result = is_array($collectionResult ?? null) ? $collectionResult : ['items' => [], 'total' => 0, 'page' => 1, 'perPage' => 20, 'pages' => 1];
$type = (string)($collectionType ?? $result['type'] ?? 'best_seller');
$message = trim((string)($collectionMessage ?? ''));

$titles = [
    'best_seller' => 'Sản phẩm bán chạy nhất',
    'top_rated' => 'Sản phẩm được đánh giá cao',
    'discount' => 'Sản phẩm đang giảm giá',
    'most_viewed' => 'Sản phẩm được quan tâm nhiều',
    'new' => 'Sản phẩm mới',
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

<style>
  .collection-shell { background:#f4faf8; border-radius:24px; padding:28px; }
  .collection-head { display:flex; justify-content:space-between; gap:16px; align-items:flex-end; margin-bottom:18px; }
  .collection-head h1 { color:#173545; font-weight:900; margin:0; }
  .collection-filter { background:#fff; border:1px solid #dcebe7; border-radius:18px; padding:20px; margin-bottom:20px; box-shadow:0 16px 42px rgba(16,80,70,.08); }
  .collection-filter-grid { display:grid; grid-template-columns:minmax(220px,1.3fr) repeat(5,minmax(140px,1fr)) auto auto; gap:14px; align-items:end; }
  .collection-filter label { color:#425466; font-weight:700; margin-bottom:8px; }
  .collection-filter .form-control,.collection-filter .form-select { height:46px; border-radius:10px; border-color:#cfdfdb; }
  .collection-filter .btn { height:46px; border-radius:10px; font-weight:800; white-space:nowrap; }
  .collection-grid { display:grid; grid-template-columns:repeat(5,minmax(0,1fr)); gap:16px; }
  .collection-empty { background:#fff; border:1px dashed #cbded9; border-radius:16px; padding:24px; text-align:center; color:#61717b; font-weight:700; }
  .goiy-product-card .flash-product__image { position:relative; display:block; aspect-ratio:1/1; background:#f6faf9; border-radius:14px; overflow:hidden; }
  .goiy-product-card .flash-product__image img { width:100%; height:100%; object-fit:cover; }
  .goiy-product-card .flash-product__badge { position:absolute; top:10px; left:10px; background:#ef4444; color:#fff; border-radius:999px; padding:5px 9px; font-weight:900; font-size:.78rem; }
  .goiy-product-card__group-badge { position:absolute; bottom:10px; left:10px; background:#0f7b55; color:#fff; border-radius:999px; padding:5px 9px; font-weight:800; font-size:.74rem; }
  .goiy-product-card__stock-badge { position:absolute; top:10px; right:10px; background:#64748b; color:#fff; border-radius:999px; padding:5px 9px; font-weight:800; font-size:.74rem; }
  .goiy-product-card .flash-product__body { padding:12px 0 0; display:flex; flex-direction:column; gap:6px; min-height:220px; }
  .goiy-product-card .flash-product__brand { color:#6b7b85; font-size:.78rem; text-transform:uppercase; font-weight:800; margin:0; }
  .goiy-product-card .flash-product__name { color:#1e3441; font-size:.95rem; line-height:1.35; min-height:42px; margin:0; font-weight:800; }
  .goiy-product-card .flash-product__price { color:#0e7b5f; font-size:1rem; font-weight:900; }
  .goiy-product-card .flash-product__market { color:#94a3b8; text-decoration:line-through; font-size:.86rem; }
  .goiy-product-card__meta { color:#64748b; display:flex; flex-wrap:wrap; gap:6px 10px; font-size:.82rem; }
  .goiy-product-card .flash-product__actions { display:grid; grid-template-columns:1fr; gap:8px; margin-top:auto; }
  .goiy-product-card .flash-product__detail,.goiy-product-card .flash-product__cart { min-height:38px; border:0; border-radius:10px; display:inline-flex; align-items:center; justify-content:center; text-decoration:none; font-weight:800; padding:0 10px; }
  .goiy-product-card .flash-product__detail { background:#eef8f4; color:#0f6f58; }
  .goiy-product-card .flash-product__cart { width:100%; background:#127a4f; color:#fff; }
  .goiy-product-card .flash-product__cart:disabled { background:#94a3b8; cursor:not-allowed; }
  @media (max-width:1399px){ .collection-filter-grid{grid-template-columns:repeat(4,minmax(0,1fr));} .collection-grid{grid-template-columns:repeat(3,minmax(0,1fr));} }
  @media (max-width:767px){ .collection-shell{padding:14px;} .collection-head{align-items:stretch; flex-direction:column;} .collection-filter-grid,.collection-grid{grid-template-columns:1fr;} }
</style>

<div class="container my-4">
  <div class="collection-shell">
    <div class="collection-head">
      <div>
        <span class="text-uppercase fw-bold text-success small">SkinSyntax Collection</span>
        <h1><?= h($title) ?></h1>
      </div>
      <a class="btn btn-outline-brand" href="<?= h(BASE_URL . '/index.php?r=goiy') ?>">Quay lại gợi ý</a>
    </div>

    <form class="collection-filter" method="get" action="<?= h(BASE_URL . '/index.php') ?>">
      <input type="hidden" name="r" value="product_collection">
      <input type="hidden" name="type" value="<?= h($type) ?>">
      <div class="collection-filter-grid">
        <div>
          <label class="form-label">Từ khóa</label>
          <input class="form-control" type="search" name="keyword" value="<?= h((string)($publicFilters['keyword'] ?? '')) ?>" placeholder="serum, chống nắng, BHA...">
        </div>
        <div>
          <label class="form-label">Danh mục</label>
          <select class="form-select" name="danh_muc">
            <option value="">Tất cả danh mục</option>
            <?php foreach ($categoryOptions as $cat): ?>
              <?php $catName = (string)($cat['ten_danh_muc'] ?? $cat['danh_muc_day_du'] ?? ''); ?>
              <?php if ($catName !== ''): ?>
                <option value="<?= h($catName) ?>" <?= (($publicFilters['danh_muc'] ?? '') === $catName ? 'selected' : '') ?>><?= h($catName) ?></option>
              <?php endif; ?>
            <?php endforeach; ?>
          </select>
        </div>
        <div>
          <label class="form-label">Thương hiệu</label>
          <select class="form-select" name="thuong_hieu">
            <option value="">Tất cả thương hiệu</option>
            <?php foreach ($brandOptions as $brand): ?>
              <?php $brandName = (string)($brand['ten_thuong_hieu'] ?? $brand['thuong_hieu'] ?? ''); ?>
              <?php if ($brandName !== ''): ?>
                <option value="<?= h($brandName) ?>" <?= (($publicFilters['thuong_hieu'] ?? '') === $brandName ? 'selected' : '') ?>><?= h($brandName) ?></option>
              <?php endif; ?>
            <?php endforeach; ?>
          </select>
        </div>
        <div>
          <label class="form-label">Giá từ</label>
          <input class="form-control" type="number" min="0" name="gia_tu" value="<?= h((string)($publicFilters['gia_tu'] ?? '')) ?>">
        </div>
        <div>
          <label class="form-label">Giá đến</label>
          <input class="form-control" type="number" min="0" name="gia_den" value="<?= h((string)($publicFilters['gia_den'] ?? '')) ?>">
        </div>
        <div>
          <label class="form-label">Sắp xếp</label>
          <select class="form-select" name="sort">
            <?php foreach ($sortOptions as $value => $label): ?>
              <option value="<?= h($value) ?>" <?= (($publicFilters['sort'] ?? 'default') === $value ? 'selected' : '') ?>><?= h($label) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <button class="btn btn-brand" type="submit">Lọc</button>
        <a class="btn btn-outline-secondary" href="<?= h(BASE_URL . '/index.php?r=product_collection&type=' . rawurlencode($type)) ?>">Xóa</a>
      </div>
    </form>

    <?php if ($message !== ''): ?>
      <div class="collection-empty mb-3"><?= h($message) ?></div>
    <?php endif; ?>

    <?php $items = is_array($result['items'] ?? null) ? $result['items'] : []; ?>
    <?php if (empty($items)): ?>
      <div class="collection-empty">Chưa có sản phẩm phù hợp với bộ lọc hiện tại.</div>
    <?php else: ?>
      <div class="collection-grid">
        <?php foreach ($items as $product): ?>
          <?php $renderCard($product, $badges[$type] ?? 'Sản phẩm'); ?>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>

    <?php if ($pages > 1): ?>
      <nav class="mt-4" aria-label="Phân trang sản phẩm">
        <ul class="pagination justify-content-center">
          <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>"><a class="page-link" href="<?= h($pageUrl(max(1, $page - 1))) ?>">Trước</a></li>
          <?php for ($i = max(1, $page - 2); $i <= min($pages, $page + 2); $i++): ?>
            <li class="page-item <?= $i === $page ? 'active' : '' ?>"><a class="page-link" href="<?= h($pageUrl($i)) ?>"><?= h((string)$i) ?></a></li>
          <?php endfor; ?>
          <li class="page-item <?= $page >= $pages ? 'disabled' : '' ?>"><a class="page-link" href="<?= h($pageUrl(min($pages, $page + 1))) ?>">Sau</a></li>
        </ul>
      </nav>
    <?php endif; ?>
  </div>
</div>
