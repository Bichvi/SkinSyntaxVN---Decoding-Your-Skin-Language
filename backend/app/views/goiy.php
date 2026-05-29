<?php
$isLoggedIn = (bool)($isLoggedIn ?? false);
$showPublicDiscovery = isset($showPublicDiscovery) ? (bool)$showPublicDiscovery : !$isLoggedIn;
$publicFilters = is_array($publicFilters ?? null) ? $publicFilters : [];
$publicSections = is_array($publicSections ?? null) ? $publicSections : [];
$brandOptions = is_array($brandOptions ?? null) ? $brandOptions : [];
$categoryOptions = is_array($categoryOptions ?? null) ? $categoryOptions : [];
$profile = is_array($recommendationProfile ?? null) ? $recommendationProfile : [];
$llamaRecommendation = is_array($llamaRecommendation ?? null) ? $llamaRecommendation : ['ok' => false, 'answer_text' => '', 'products' => []];
$mongoUnavailableMessage = trim((string)($mongoUnavailableMessage ?? ''));
$profileUnavailableMessage = trim((string)($profileUnavailableMessage ?? ''));
$skinProfilePromptMessage = trim((string)($skinProfilePromptMessage ?? ''));
$surveyUrl = trim((string)($surveyUrl ?? (BASE_URL . '/index.php?r=khaosat')));

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

$sections = [
    'best_seller' => ['title' => 'Sản phẩm bán chạy nhất', 'badge' => 'Bán chạy'],
    'top_rated' => ['title' => 'Sản phẩm được đánh giá cao', 'badge' => 'Đánh giá cao'],
    'discount' => ['title' => 'Sản phẩm đang giảm giá', 'badge' => 'Đang giảm giá'],
    'most_viewed' => ['title' => 'Sản phẩm được nhiều người quan tâm', 'badge' => 'Đang quan tâm'],
    'new' => ['title' => 'Sản phẩm mới', 'badge' => 'Mới lên kệ'],
];

$queryWith = static function (array $extra) use ($publicFilters): string {
    $query = array_filter([
        'keyword' => trim((string)($publicFilters['keyword'] ?? '')),
        'danh_muc' => trim((string)($publicFilters['danh_muc'] ?? '')),
        'thuong_hieu' => trim((string)($publicFilters['thuong_hieu'] ?? '')),
        'gia_tu' => trim((string)($publicFilters['gia_tu'] ?? '')),
        'gia_den' => trim((string)($publicFilters['gia_den'] ?? '')),
        'sort' => trim((string)($publicFilters['sort'] ?? 'default')),
    ], static fn($value) => $value !== '');
    return http_build_query(array_merge($query, $extra));
};

$renderCard = static function (array $product, string $badgeLabel = '', string $cardVariant = 'default'): void {
    include __DIR__ . '/partials/goiy_product_card.php';
};
?>

<style>
  .goiy-page { background: #f4faf8; border-radius: 24px; padding: 28px; }
  .goiy-hero {
    background: linear-gradient(135deg, #0f2b3a 0%, #1e5b67 100%);
    border-radius: 24px;
    color: #fff;
    min-height: 260px;
    padding: 42px;
    display: grid;
    grid-template-columns: minmax(0, 1.4fr) minmax(280px, 0.7fr);
    gap: 28px;
    align-items: center;
    box-shadow: 0 22px 55px rgba(15, 43, 58, 0.18);
  }
  .goiy-eyebrow {
    display: inline-flex;
    align-items: center;
    border-radius: 999px;
    background: rgba(255,255,255,0.16);
    padding: 8px 14px;
    font-size: .78rem;
    font-weight: 800;
    letter-spacing: .04em;
    text-transform: uppercase;
  }
  .goiy-hero h1 { font-size: clamp(2rem, 4vw, 3.1rem); font-weight: 900; margin: 22px 0 12px; }
  .goiy-hero p { color: rgba(255,255,255,.78); font-size: 1.05rem; margin: 0; }
  .goiy-hero-card {
    background: rgba(255,255,255,.14);
    border: 1px solid rgba(255,255,255,.16);
    border-radius: 22px;
    padding: 28px;
    backdrop-filter: blur(10px);
  }
  .goiy-hero-card h3 { font-size: 1.65rem; line-height: 1.3; margin: 12px 0 0; }
  .goiy-filter {
    background: #fff;
    border: 1px solid #dcebe7;
    border-radius: 18px;
    box-shadow: 0 16px 42px rgba(16, 80, 70, .08);
    padding: 20px;
  }
  .goiy-filter-grid {
    display: grid;
    grid-template-columns: minmax(220px,1.3fr) repeat(5, minmax(140px,1fr)) auto auto;
    gap: 14px;
    align-items: end;
  }
  .goiy-filter label { color: #425466; font-weight: 700; margin-bottom: 8px; }
  .goiy-filter .form-control, .goiy-filter .form-select { height: 46px; border-radius: 10px; border-color: #cfdfdb; }
  .goiy-filter .btn { height: 46px; border-radius: 10px; font-weight: 800; white-space: nowrap; }
  .goiy-survey-alert {
    background: #fff7e6;
    border: 1px solid #f3d29b;
    border-radius: 16px;
    color: #7a4c04;
    padding: 16px 18px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 14px;
  }
  .goiy-section {
    background: #fff;
    border: 1px solid #e0ece9;
    border-radius: 18px;
    padding: 20px;
    box-shadow: 0 14px 34px rgba(14, 70, 62, .06);
  }
  .goiy-section__head {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 16px;
    margin-bottom: 16px;
  }
  .goiy-section__title { font-size: 1.45rem; font-weight: 900; color: #173545; margin: 0; }
  .goiy-section__tools { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; justify-content: flex-end; }
  .goiy-section__sort, .goiy-section__more {
    border: 1px solid #cfe2dc;
    border-radius: 999px;
    padding: 8px 12px;
    color: #0f6f58;
    text-decoration: none;
    font-weight: 800;
    background: #f3fbf8;
    font-size: .88rem;
  }
  .goiy-product-grid {
    display: grid;
    grid-template-columns: repeat(3, minmax(0, 1fr));
    gap: 20px;
    align-items: stretch;
  }
  .goiy-empty {
    border: 1px dashed #cbded9;
    border-radius: 14px;
    background: #f8fcfb;
    color: #61717b;
    padding: 20px;
    text-align: center;
    font-weight: 700;
  }
  .goiy-product-card {
    height: 100%;
    display: flex;
    flex-direction: column;
  }
  .goiy-product-card .flash-product__image { position: relative; display: block; aspect-ratio: 1 / 1; background: #f6faf9; border-radius: 14px; overflow: hidden; }
  .goiy-product-card .flash-product__image img { width: 100%; height: 100%; object-fit: cover; }
  .goiy-product-card .flash-product__badge {
    position: absolute; top: 10px; left: 10px; background: #ef4444; color: #fff; border-radius: 999px; padding: 5px 9px; font-weight: 900; font-size: .78rem;
  }
  .goiy-product-card__group-badge {
    position: absolute; bottom: 10px; left: 10px; background: #0f7b55; color: #fff; border-radius: 999px; padding: 5px 9px; font-weight: 800; font-size: .74rem;
  }
  .goiy-product-card__stock-badge {
    position: absolute; top: 10px; right: 10px; background: #64748b; color: #fff; border-radius: 999px; padding: 5px 9px; font-weight: 800; font-size: .74rem;
  }
  .goiy-product-card .flash-product__body { padding: 12px 0 0; display: flex; flex-direction: column; gap: 6px; flex: 1; min-height: 220px; }
  .goiy-product-card .flash-product__brand { color: #6b7b85; font-size: .78rem; text-transform: uppercase; font-weight: 800; margin: 0; }
  .goiy-product-card .flash-product__name { color: #1e3441; font-size: .95rem; line-height: 1.35; min-height: 42px; margin: 0; font-weight: 800; }
  .goiy-product-card .flash-product__price { color: #0e7b5f; font-size: 1rem; font-weight: 900; }
  .goiy-product-card .flash-product__market { color: #94a3b8; text-decoration: line-through; font-size: .86rem; }
  .goiy-product-card__meta { color: #64748b; display: flex; flex-wrap: wrap; gap: 6px 10px; font-size: .82rem; }
  .goiy-product-card .flash-product__actions { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; margin-top: auto; padding-top: 12px; }
  .goiy-product-card .flash-product__detail,
  .goiy-product-card .flash-product__cart {
    width: 100%; min-height: 42px; border: 0; border-radius: 10px; display: inline-flex; align-items: center; justify-content: center; text-decoration: none; font-weight: 800; padding: 0 10px; white-space: nowrap;
  }
  .goiy-product-card .flash-product__detail { background: #eef8f4; color: #0f6f58; }
  .goiy-product-card .flash-product__cart { width: 100%; background: #127a4f; color: #fff; }
  .goiy-product-card .flash-product__cart:disabled { background: #94a3b8; cursor: not-allowed; }
  .goiy-advice {
    background: #fff;
    border: 1px solid #dcebe7;
    border-radius: 16px;
    padding: 18px;
    color: #264653;
    margin-bottom: 18px;
  }
  .rcm-products-grid {
    display: grid;
    grid-template-columns: repeat(3, minmax(0, 1fr));
    gap: 20px;
    align-items: stretch;
  }
  .rcm-product-card {
    height: 100%;
    min-height: 100%;
    display: flex;
    flex-direction: column;
    background: #fff;
    border-radius: 18px;
    overflow: hidden;
    box-shadow: 0 8px 24px rgba(15, 23, 42, 0.06);
    border: 1px solid #e5efec;
  }
  .rcm-product-image-wrap {
    position: relative;
    height: 230px;
    background: #f8fafc;
    display: flex;
    align-items: center;
    justify-content: center;
    text-decoration: none;
  }
  .rcm-product-image-wrap img {
    width: 100%;
    height: 100%;
    object-fit: contain;
    padding: 16px;
  }
  .rcm-discount-badge {
    position: absolute;
    top: 14px;
    left: 14px;
    background: #ff4b4b;
    color: #fff;
    border-radius: 999px;
    padding: 6px 10px;
    font-weight: 800;
    font-size: 13px;
  }
  .rcm-match-badge {
    position: absolute;
    top: 14px;
    right: 14px;
    background: #0f3d56;
    color: #fff;
    border-radius: 999px;
    padding: 7px 12px;
    font-weight: 800;
    font-size: 12px;
    text-transform: uppercase;
  }
  .rcm-product-body {
    display: flex;
    flex-direction: column;
    flex: 1;
    padding: 16px;
  }
  .rcm-product-brand {
    color: #0f7b55;
    font-size: .82rem;
    font-weight: 900;
    margin: 0 0 8px;
    text-transform: uppercase;
  }
  .rcm-product-name {
    min-height: 48px;
    margin: 0 0 10px;
    color: #1f2937;
    font-size: 1rem;
    line-height: 1.45;
    font-weight: 800;
    display: -webkit-box;
    -webkit-line-clamp: 2;
    -webkit-box-orient: vertical;
    overflow: hidden;
  }
  .rcm-product-price {
    color: #0f7b55;
    font-size: 1.08rem;
    font-weight: 900;
    margin-bottom: 4px;
  }
  .rcm-product-market {
    color: #94a3b8;
    text-decoration: line-through;
    font-size: .9rem;
  }
  .rcm-product-actions {
    margin-top: auto;
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 10px;
    padding-top: 16px;
  }
  .rcm-product-detail,
  .rcm-product-cart {
    width: 100%;
    min-height: 42px;
    border-radius: 12px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    text-decoration: none;
    font-weight: 800;
    font-size: .92rem;
    border: 1px solid #cfe2dc;
    white-space: nowrap;
  }
  .rcm-product-detail {
    background: #f4fbf8;
    color: #0f5f4c;
  }
  .rcm-product-cart {
    background: #12835f;
    border-color: #12835f;
    color: #fff;
  }
  .rcm-product-cart:disabled {
    background: #94a3b8;
    border-color: #94a3b8;
    cursor: not-allowed;
  }
  @media (max-width: 1399px) {
    .goiy-filter-grid { grid-template-columns: repeat(4, minmax(0, 1fr)); }
  }
  @media (max-width: 992px) {
    .goiy-product-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); }
  }
  @media (max-width: 992px) {
    .rcm-products-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); }
  }
  @media (max-width: 767px) {
    .goiy-page { padding: 14px; }
    .goiy-hero { grid-template-columns: 1fr; padding: 28px; }
    .goiy-filter-grid, .goiy-product-grid, .rcm-products-grid { grid-template-columns: 1fr; }
    .goiy-survey-alert, .goiy-section__head { align-items: stretch; flex-direction: column; }
    .goiy-section__tools { justify-content: flex-start; }
  }
</style>

<div class="container my-4">
  <div class="goiy-page">
    <?php if ($showPublicDiscovery): ?>
      <section class="goiy-hero mb-4">
        <div>
          <span class="goiy-eyebrow"><?= $isLoggedIn ? 'Cập nhật hồ sơ da để cá nhân hóa' : 'Public Recommendation' ?></span>
          <h1>Khám phá sản phẩm phù hợp</h1>
          <p>Tìm sản phẩm theo từ khóa, danh mục, thương hiệu, khoảng giá và xem các nhóm sản phẩm nổi bật từ SkinSyntax.</p>
        </div>
        <div class="goiy-hero-card">
          <span class="goiy-eyebrow">Sản phẩm nổi bật</span>
          <h3>Bán chạy, đánh giá cao, giảm giá, được quan tâm và mới lên kệ</h3>
        </div>
      </section>

      <?php if ($skinProfilePromptMessage !== ''): ?>
        <div class="goiy-survey-alert mb-4">
          <strong><?= h($skinProfilePromptMessage) ?></strong>
          <a class="btn btn-brand" href="<?= h($surveyUrl) ?>">Khảo sát ngay</a>
        </div>
      <?php endif; ?>

      <form class="goiy-filter mb-4" method="get" action="<?= h(BASE_URL . '/index.php') ?>">
        <input type="hidden" name="r" value="goiy">
        <div class="goiy-filter-grid">
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
          <a class="btn btn-outline-secondary" href="<?= h(BASE_URL . '/index.php?r=goiy') ?>">Xóa</a>
        </div>
      </form>

      <?php if ($mongoUnavailableMessage !== ''): ?>
        <div class="goiy-empty mb-4"><?= h($mongoUnavailableMessage) ?></div>
      <?php endif; ?>

      <?php foreach ($sections as $key => $meta): ?>
        <section class="goiy-section mb-4">
          <div class="goiy-section__head">
            <h2 class="goiy-section__title"><?= h($meta['title']) ?></h2>
            <div class="goiy-section__tools">
              <a class="goiy-section__more" href="<?= h(BASE_URL . '/index.php?r=product_collection&type=' . rawurlencode($key) . '&' . $queryWith([])) ?>">Xem tất cả</a>
            </div>
          </div>
          <?php $items = is_array($publicSections[$key] ?? null) ? $publicSections[$key] : []; ?>
          <?php if (empty($items)): ?>
            <div class="goiy-empty">Chưa có sản phẩm phù hợp trong nhóm này.</div>
          <?php else: ?>
            <div class="goiy-product-grid">
              <?php foreach ($items as $product): ?>
                <?php $renderCard($product, $meta['badge']); ?>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
        </section>
      <?php endforeach; ?>
    <?php else: ?>
      <section class="goiy-hero mb-4">
        <div>
          <span class="goiy-eyebrow">Gợi ý cá nhân hóa</span>
          <h1>Gợi ý dành riêng cho bạn</h1>
          <p>Dựa trên hồ sơ da và lịch sử mua hàng của bạn.</p>
        </div>
        <div class="goiy-hero-card">
          <span class="goiy-eyebrow"><?= h((string)($profile['skin_type'] ?? 'Hồ sơ da')) ?></span>
          <h3>SkinSyntax chọn sản phẩm phù hợp với nhu cầu chăm sóc da của bạn</h3>
        </div>
      </section>

      <?php if (empty($llamaRecommendation['ok'])): ?>
        <div class="goiy-empty"><?= h($profileUnavailableMessage !== '' ? $profileUnavailableMessage : 'Hiện chưa thể tạo gợi ý cá nhân hóa. Vui lòng thử lại sau.') ?></div>
      <?php else: ?>
        <?php if (!empty($llamaRecommendation['answer_text'])): ?>
          <div class="goiy-advice"><?= h((string)$llamaRecommendation['answer_text']) ?></div>
        <?php endif; ?>
        <?php if (empty($llamaRecommendation['products'])): ?>
          <div class="goiy-empty">Hiện chưa có sản phẩm cá nhân hóa phù hợp.</div>
        <?php else: ?>
          <section class="goiy-section">
            <div class="goiy-section__head">
              <h2 class="goiy-section__title">Sản phẩm dành cho hồ sơ da của bạn</h2>
            </div>
            <div class="rcm-products-grid">
              <?php foreach ($llamaRecommendation['products'] as $product): ?>
                <?php $renderCard($product, 'PHÙ HỢP', 'rcm'); ?>
              <?php endforeach; ?>
            </div>
          </section>
        <?php endif; ?>
      <?php endif; ?>
    <?php endif; ?>
  </div>
</div>
