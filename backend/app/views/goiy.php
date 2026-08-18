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
  .goiy-page { background: #F9FBF8; border-radius: 28px; padding: 32px; border: 1px solid #E2EADF; box-shadow: 0 10px 30px rgba(45, 90, 39, 0.04); }
  .goiy-hero {
    background: linear-gradient(135deg, #162F18 0%, #2D5A27 60%, #4A7C59 100%);
    border-radius: 28px;
    color: #fff;
    min-height: 240px;
    padding: 36px 42px;
    display: grid;
    grid-template-columns: minmax(0, 1.4fr) minmax(280px, 0.7fr);
    gap: 28px;
    align-items: center;
    box-shadow: 0 20px 45px rgba(45, 90, 39, 0.2);
    border: 1px solid rgba(226, 234, 223, 0.2);
  }
  .goiy-eyebrow {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    border-radius: 999px;
    background: rgba(255,255,255,0.18);
    color: #D2E5D5;
    padding: 6px 14px;
    font-size: .78rem;
    font-weight: 700;
    letter-spacing: .05em;
    text-transform: uppercase;
  }
  .goiy-hero h1 { font-size: clamp(2rem, 4vw, 3rem); font-weight: 800; color: #FFFFFF; margin: 16px 0 10px; }
  .goiy-hero p { color: #EAF2EC; opacity: 0.9; font-size: 1.05rem; margin: 0; line-height: 1.6; }
  .goiy-hero-card {
    background: rgba(255,255,255,.12);
    border: 1px solid rgba(255,255,255,.18);
    border-radius: 22px;
    padding: 24px;
    backdrop-filter: blur(12px);
  }
  .goiy-hero-card h3 { font-size: 1.45rem; line-height: 1.35; margin: 12px 0 0; color: #FFFFFF; font-weight: 700; }
  .goiy-filter {
    background: #fff;
    border: 1px solid #E2EADF;
    border-radius: 20px;
    box-shadow: 0 8px 24px rgba(45, 90, 39, 0.06);
    padding: 24px;
  }
  .goiy-filter-grid {
    display: grid;
    grid-template-columns: minmax(220px,1.3fr) repeat(5, minmax(140px,1fr)) auto auto;
    gap: 14px;
    align-items: end;
  }
  .goiy-filter label { color: #1A2F1A; font-weight: 700; font-size: 0.85rem; margin-bottom: 6px; }
  .goiy-filter .form-control, .goiy-filter .form-select { height: 46px; border-radius: 12px; border-color: #E2EADF; background: #F0F4F1; font-weight: 600; color: #1A2F1A; }
  .goiy-filter .form-control:focus, .goiy-filter .form-select:focus { border-color: #2D5A27; box-shadow: 0 0 0 3px rgba(45,90,39,0.12); }
  .goiy-filter .btn { height: 46px; border-radius: 12px; font-weight: 700; white-space: nowrap; }
  .goiy-survey-alert {
    background: #F0F4F1;
    border: 1px solid #84A98C;
    border-radius: 18px;
    color: #1A2F1A;
    padding: 18px 22px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 14px;
  }
  .goiy-section {
    background: #fff;
    border: 1px solid #E2EADF;
    border-radius: 20px;
    padding: 24px;
    box-shadow: 0 8px 24px rgba(45, 90, 39, 0.05);
  }
  .goiy-section__head {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 16px;
    margin-bottom: 18px;
  }
  .goiy-section__title { font-size: 1.5rem; font-weight: 800; color: #1A2F1A; margin: 0; }
  .goiy-section__tools { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; justify-content: flex-end; }
  .goiy-section__sort, .goiy-section__more {
    border: 1px solid #E2EADF;
    border-radius: 999px;
    padding: 8px 16px;
    color: #2D5A27;
    text-decoration: none;
    font-weight: 700;
    background: #EAF0EB;
    font-size: .88rem;
    transition: all 0.2s ease;
  }
  .goiy-section__more:hover {
    background: #2D5A27;
    color: #FFFFFF;
  }
  .goiy-product-grid {
    display: grid;
    grid-template-columns: repeat(3, minmax(0, 1fr));
    gap: 20px;
    align-items: stretch;
  }
  .goiy-empty {
    border: 1px dashed #84A98C;
    border-radius: 18px;
    background: #F9FBF8;
    color: #5C705E;
    padding: 24px;
    text-align: center;
    font-weight: 700;
  }
  .goiy-advice {
    background: #F0F4F1;
    border: 1px solid #84A98C;
    border-radius: 20px;
    padding: 24px;
    color: #1A2F1A;
    margin-bottom: 22px;
    font-size: 1.02rem;
    line-height: 1.7;
    box-shadow: 0 8px 24px rgba(45, 90, 39, 0.06);
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
    border-radius: 20px;
    overflow: hidden;
    box-shadow: 0 8px 24px rgba(45, 90, 39, 0.06);
    border: 1px solid #E2EADF;
    transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
  }
  .rcm-product-card:hover {
    transform: translateY(-4px);
    box-shadow: 0 14px 35px rgba(45, 90, 39, 0.12);
    border-color: #84A98C;
  }
  .rcm-product-image-wrap {
    position: relative;
    height: 230px;
    background: #F0F4F1;
    display: flex;
    align-items: center;
    justify-content: center;
    text-decoration: none;
    overflow: hidden;
  }
  .rcm-product-image-wrap img {
    width: 100%;
    height: 100%;
    object-fit: cover;
    transition: transform 0.4s ease;
  }
  .rcm-product-card:hover .rcm-product-image-wrap img {
    transform: scale(1.05);
  }
  .rcm-discount-badge {
    position: absolute;
    top: 12px;
    left: 12px;
    background: #E11D48;
    color: #fff;
    border-radius: 999px;
    padding: 4px 10px;
    font-weight: 800;
    font-size: 12px;
    z-index: 2;
  }
  .rcm-match-badge {
    position: absolute;
    top: 12px;
    right: 12px;
    background: #2D5A27;
    color: #fff;
    border-radius: 999px;
    padding: 6px 12px;
    font-weight: 800;
    font-size: 11px;
    text-transform: uppercase;
    z-index: 2;
  }
  .rcm-product-body {
    display: flex;
    flex-direction: column;
    flex: 1;
    padding: 18px;
  }
  .rcm-product-brand {
    color: #5C705E;
    font-size: .8rem;
    font-weight: 800;
    margin: 0 0 6px;
    text-transform: uppercase;
    letter-spacing: 0.05em;
  }
  .rcm-product-name {
    min-height: 44px;
    margin: 0 0 10px;
    color: #1A2F1A;
    font-size: 1rem;
    line-height: 1.4;
    font-weight: 700;
    display: -webkit-box;
    -webkit-line-clamp: 2;
    line-clamp: 2;
    -webkit-box-orient: vertical;
    overflow: hidden;
  }
  .rcm-product-price {
    color: #2D5A27;
    font-size: 1.1rem;
    font-weight: 800;
    margin-bottom: 4px;
  }
  .rcm-product-market {
    color: #8DA090;
    text-decoration: line-through;
    font-size: .88rem;
  }
  .recommend-match {
    margin-top: 12px;
    border-radius: 14px;
    background: #EAF0EB;
    border: 1px solid #E2EADF;
    padding: 10px 14px;
  }
  .recommend-match__head {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 10px;
    color: #1A2F1A;
    font-size: .85rem;
    font-weight: 700;
    margin-bottom: 6px;
  }
  .recommend-match__head strong {
    color: #2D5A27;
    font-size: .95rem;
    font-weight: 800;
  }
  .recommend-match__bar {
    height: 7px;
    border-radius: 999px;
    background: #E2EADF;
    overflow: hidden;
  }
  .recommend-match__fill {
    display: block;
    height: 100%;
    border-radius: inherit;
    background: linear-gradient(90deg, #2D5A27 0%, #4A7C59 100%);
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
    min-height: 40px;
    border-radius: 12px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    text-decoration: none;
    font-weight: 700;
    font-size: .88rem;
    border: none;
    white-space: nowrap;
    transition: all 0.2s ease;
  }
  .rcm-product-detail {
    background: #EAF0EB;
    color: #2D5A27;
  }
  .rcm-product-detail:hover {
    background: #2D5A27;
    color: #FFFFFF;
  }
  .rcm-product-cart {
    background: #2D5A27;
    color: #fff;
  }
  .rcm-product-cart:hover {
    background: #21431D;
  }
  .rcm-product-cart:disabled {
    background: #94A3B8;
    cursor: not-allowed;
  }
  @media (max-width: 1399px) {
    .goiy-filter-grid { grid-template-columns: repeat(4, minmax(0, 1fr)); }
  }
  @media (max-width: 992px) {
    .goiy-product-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); }
    .rcm-products-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); }
  }
  @media (max-width: 767px) {
    .goiy-page { padding: 18px; }
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
