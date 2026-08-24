<?php
$isLoggedIn = (bool)($isLoggedIn ?? false);
$showPublicDiscovery = isset($showPublicDiscovery) ? (bool)$showPublicDiscovery : !$isLoggedIn;
$publicFilters = is_array($publicFilters ?? null) ? $publicFilters : [];
$publicProducts = is_array($publicProducts ?? null) ? $publicProducts : [];
$totalFiltered = (int)($totalFiltered ?? count($publicProducts));
$publicSections = is_array($publicSections ?? null) ? $publicSections : [];
$profileSections = is_array($profileSections ?? null) ? $profileSections : [];
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

$pricePresets = [
    ['label' => 'Dưới 200k', 'min' => '0', 'max' => '200000'],
    ['label' => '200k - 500k', 'min' => '200000', 'max' => '500000'],
    ['label' => '500k - 1tr', 'min' => '500000', 'max' => '1000000'],
    ['label' => 'Trên 1tr', 'min' => '1000000', 'max' => ''],
];

$sections = [
    'best_seller' => ['title' => 'Sản phẩm bán chạy nhất', 'badge' => 'Bán chạy'],
    'top_rated' => ['title' => 'Sản phẩm được đánh giá cao', 'badge' => 'Đánh giá cao'],
    'discount' => ['title' => 'Sản phẩm đang giảm giá', 'badge' => 'Đang giảm giá'],
    'most_viewed' => ['title' => 'Sản phẩm được nhiều người quan tâm', 'badge' => 'Đang quan tâm'],
    'new' => ['title' => 'Sản phẩm mới', 'badge' => 'Mới lên kệ'],
];

$currentSort = trim((string)($publicFilters['sort'] ?? 'default'));
$hasActiveFilter = (!empty($publicFilters['keyword'])) || (!empty($publicFilters['gia_tu'])) || (!empty($publicFilters['gia_den'])) || (!empty($publicFilters['danh_muc'])) || (!empty($publicFilters['thuong_hieu'])) || ($currentSort !== '' && $currentSort !== 'default');

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
  .goiy-page { background: #FAFAFA; border-radius: 12px; padding: 24px; border: 1px solid var(--border); }
  .goiy-hero {
    background: #183B2B;
    border-radius: 12px;
    color: #fff;
    padding: 32px 36px;
    border: 1px solid var(--border);
  }
  .goiy-eyebrow {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    border-radius: 4px;
    background: rgba(255,255,255,0.14);
    color: #F1F5F9;
    padding: 4px 10px;
    font-size: .75rem;
    font-weight: 600;
    letter-spacing: .04em;
    text-transform: uppercase;
  }
  .goiy-hero h1 { font-size: clamp(1.6rem, 3vw, 2.3rem); font-weight: 700; color: #FFFFFF; margin: 10px 0 8px; letter-spacing: -0.02em; }
  .goiy-hero p { color: #E2E8F0; font-size: 0.95rem; margin: 0; line-height: 1.6; }
  .goiy-hero-card {
    background: rgba(255,255,255,.1);
    border: 1px solid rgba(255,255,255,.16);
    border-radius: 8px;
    padding: 20px;
  }
  .goiy-hero-card h3 { font-size: 1.2rem; line-height: 1.35; margin: 8px 0 0; color: #FFFFFF; font-weight: 700; }
  .goiy-filter {
    background: #fff;
    border: 1px solid var(--border);
    border-radius: 12px;
    padding: 20px;
  }
  .goiy-filter-grid {
    display: grid;
    grid-template-columns: minmax(200px, 1.2fr) repeat(5, minmax(130px, 1fr)) auto auto;
    gap: 12px;
    align-items: end;
  }
  .goiy-filter label { color: #0F172A; font-weight: 600; font-size: 0.82rem; margin-bottom: 6px; }
  .goiy-filter .form-control, .goiy-filter .form-select { height: 42px; border-radius: 6px; border-color: var(--border); background: #FAFAFA; font-weight: 500; color: #0F172A; font-size: 0.86rem; }
  .goiy-filter .form-control:focus, .goiy-filter .form-select:focus { border-color: #183B2B; box-shadow: 0 0 0 2px rgba(24,59,43,0.12); }
  .goiy-filter .btn { height: 42px; border-radius: 6px; font-weight: 600; white-space: nowrap; font-size: 0.86rem; }
  .price-pills { display: flex; flex-wrap: wrap; gap: 8px; margin-top: 14px; }
  .price-pill {
    font-size: 0.78rem;
    font-weight: 600;
    padding: 5px 12px;
    border-radius: 6px;
    border: 1px solid var(--border);
    background: #FAFAFA;
    color: #183B2B;
    text-decoration: none;
    transition: all 0.2s ease;
  }
  .price-pill:hover, .price-pill.active { background: #183B2B; color: #FFFFFF; border-color: #183B2B; }
  .goiy-survey-alert {
    background: #FAFAFA;
    border: 1px solid #C8DACF;
    border-radius: 8px;
    color: #0F172A;
    padding: 16px 20px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 14px;
  }
  .goiy-section {
    background: #fff;
    border: 1px solid var(--border);
    border-radius: 12px;
    padding: 20px;
  }
  .goiy-section__head {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 16px;
    margin-bottom: 16px;
  }
  .goiy-section__title { font-size: 1.3rem; font-weight: 700; color: #0F172A; margin: 0; }
  .goiy-section__tools { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; justify-content: flex-end; }
  .goiy-section__more {
    border: 1px solid var(--border);
    border-radius: 6px;
    padding: 6px 14px;
    color: #0F172A;
    text-decoration: none;
    font-weight: 600;
    background: #F1F5F9;
    font-size: .82rem;
    transition: all 0.2s ease;
  }
  .goiy-section__more:hover { background: #183B2B; color: #FFFFFF; border-color: #183B2B; }
  .goiy-product-grid, .rcm-products-grid {
    display: grid;
    grid-template-columns: repeat(3, minmax(0, 1fr));
    gap: 16px;
    align-items: stretch;
  }
  .goiy-empty {
    border: 1px dashed #C8DACF;
    border-radius: 8px;
    background: #FAFAFA;
    color: #64748B;
    padding: 24px;
    text-align: center;
    font-weight: 600;
  }
  .profile-summary-box {
    background: #FFFFFF;
    border: 1px solid var(--border);
    border-radius: 12px;
    padding: 20px 24px;
    margin-bottom: 20px;
  }
  .profile-tag {
    display: inline-flex;
    align-items: center;
    gap: 6px;
  .goiy-advice {
    background: linear-gradient(135deg, #F0F4F1 0%, #EAF0EB 100%);
    border: 1px solid #84A98C;
    border-radius: 22px;
    padding: 28px;
    color: #1A2F1A;
    margin-bottom: 24px;
    font-size: 1.04rem;
    line-height: 1.75;
    box-shadow: 0 8px 24px rgba(45, 90, 39, 0.06);
  }
  @media (max-width: 1399px) {
    .goiy-filter-grid { grid-template-columns: repeat(4, minmax(0, 1fr)); }
  }
  @media (max-width: 992px) {
    .goiy-product-grid, .rcm-products-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); }
  }
  @media (max-width: 767px) {
    .goiy-page { padding: 18px; }
    .goiy-hero { padding: 28px; }
    .goiy-filter-grid, .goiy-product-grid, .rcm-products-grid { grid-template-columns: 1fr; }
    .goiy-survey-alert, .goiy-section__head { align-items: stretch; flex-direction: column; }
    .goiy-section__tools { justify-content: flex-start; }
  }
</style>

<div class="container my-4">
  <div class="goiy-page">
    <?php if ($showPublicDiscovery): ?>
      <section class="goiy-hero mb-4">
        <div class="row align-items-center g-4">
          <div class="col-lg-8">
            <span class="goiy-eyebrow"><i class="fa-solid fa-compass me-1"></i> Gợi ý & Tìm kiếm sản phẩm</span>
            <h1>Khám phá sản phẩm phù hợp</h1>
            <p>Lọc sản phẩm theo từ khóa, mức giá, danh mục, thương hiệu và khám phá các dòng sản phẩm bán chạy nhất tại SkinSyntax.</p>
          </div>
          <div class="col-lg-4">
            <div class="goiy-hero-card h-100">
              <span class="goiy-eyebrow"><i class="fa-solid fa-fire me-1"></i> Xu hướng làm đẹp</span>
              <h3>Top sản phẩm bán chạy, đánh giá cao & đang giảm giá</h3>
            </div>
          </div>
        </div>
      </section>

      <?php if ($skinProfilePromptMessage !== ''): ?>
        <div class="goiy-survey-alert mb-4">
          <div>
            <i class="fa-solid fa-sparkles text-success me-2 fs-5"></i>
            <strong><?= h($skinProfilePromptMessage) ?></strong>
          </div>
          <a class="btn btn-brand" href="<?= h($surveyUrl) ?>">Khảo sát ngay!!</a>
        </div>
      <?php endif; ?>

      <form class="goiy-filter mb-4" method="get" action="<?= h(BASE_URL . '/index.php') ?>">
        <input type="hidden" name="r" value="goiy">
        <div class="goiy-filter-grid">
          <div>
            <label class="form-label"><i class="fa-solid fa-magnifying-glass me-1"></i> Từ khóa</label>
            <input class="form-control" type="search" name="keyword" value="<?= h((string)($publicFilters['keyword'] ?? '')) ?>" placeholder="Serum B5, kem chống nắng, BHA...">
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
            <label class="form-label">Giá từ (VNĐ)</label>
            <input class="form-control" type="number" min="0" step="10000" name="gia_tu" value="<?= h((string)($publicFilters['gia_tu'] ?? '')) ?>" placeholder="0">
          </div>
          <div>
            <label class="form-label">Giá đến (VNĐ)</label>
            <input class="form-control" type="number" min="0" step="10000" name="gia_den" value="<?= h((string)($publicFilters['gia_den'] ?? '')) ?>" placeholder="1.000.000">
          </div>
          <div>
            <label class="form-label">Sắp xếp</label>
            <select class="form-select" name="sort" onchange="this.form.submit()">
              <?php foreach ($sortOptions as $value => $label): ?>
                <option value="<?= h($value) ?>" <?= ($currentSort === $value ? 'selected' : '') ?>><?= h($label) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <button class="btn btn-brand" type="submit"><i class="fa-solid fa-filter me-1"></i> Lọc</button>
          <a class="btn btn-outline-secondary" href="<?= h(BASE_URL . '/index.php?r=goiy') ?>">Xóa</a>
        </div>

        <div class="price-pills">
          <span class="small text-muted me-1 align-self-center fw-bold">Chọn nhanh mức giá:</span>
          <?php foreach ($pricePresets as $preset): ?>
            <?php
              $isActive = ((string)($publicFilters['gia_tu'] ?? '') === (string)$preset['min']) && ((string)($publicFilters['gia_den'] ?? '') === (string)$preset['max']);
              $presetUrl = BASE_URL . '/index.php?' . $queryWith(['gia_tu' => $preset['min'], 'gia_den' => $preset['max']]);
            ?>
            <a class="price-pill <?= $isActive ? 'active' : '' ?>" href="<?= h($presetUrl) ?>"><?= h($preset['label']) ?></a>
          <?php endforeach; ?>
        </div>
      </form>

      <?php if ($mongoUnavailableMessage !== ''): ?>
        <div class="goiy-empty mb-4"><?= h($mongoUnavailableMessage) ?></div>
      <?php endif; ?>

      <?php if ($hasActiveFilter): ?>
        <section class="goiy-section mb-4">
          <div class="goiy-section__head">
            <h2 class="goiy-section__title">
              <i class="fa-solid fa-list-check me-2 text-success"></i>Kết quả lọc & sắp xếp (<?= number_format($totalFiltered) ?> sản phẩm)
            </h2>
            <div class="goiy-section__tools">
              <a class="goiy-section__more" href="<?= h(BASE_URL . '/index.php?r=goiy') ?>">Xóa bộ lọc</a>
            </div>
          </div>
          <?php if (empty($publicProducts)): ?>
            <div class="goiy-empty">Không tìm thấy sản phẩm nào phù hợp với bộ lọc hiện tại. Hãy thử chọn mức giá hoặc từ khóa khác.</div>
          <?php else: ?>
            <div class="goiy-product-grid">
              <?php foreach ($publicProducts as $product): ?>
                <?php $renderCard($product, 'LỌC KHỚP'); ?>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
        </section>
      <?php else: ?>
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
      <?php endif; ?>

    <?php else: ?>

      <!-- KHUNG GỢI Ý DÀNH RIÊNG CHO BẠN (TÍCH HỢP HỎI AI TƯ VẤN) -->
      <section class="goiy-hero mb-4">
        <div class="row align-items-center g-4">
          <div class="col-lg-8">
            <span class="goiy-eyebrow"><i class="fa-solid fa-wand-magic-sparkles me-1"></i> GỢI Ý CÁ NHÂN HÓA </span>
            <h1>Gợi ý dành riêng cho bạn</h1>
            <p class="mb-3">SkinSyntax sẽ tự động phân tích hồ sơ làn da và lịch sử tương tác để đưa ra chu trình sản phẩm tối ưu nhất cho bạn.</p>

            <!-- Ô Hỏi AI tư vấn theo nhu cầu của bạn nằm ngay trong khung này -->
            <div class="p-3 rounded-4 mt-2" style="background: rgba(255, 255, 255, 0.15); backdrop-filter: blur(10px); border: 1px solid rgba(255, 255, 255, 0.25);">
              <label class="form-label fw-bold mb-1.5 text-white" style="font-size: 0.92rem;"><i class="fa-solid fa-comments me-2 text-warning"></i> Nhu cầu của bạn là gì?</label>
              <form id="aiConsultForm" class="d-flex gap-2">
                <input type="text" id="aiConsultInput" class="form-control rounded-pill px-4 border-0" placeholder="Nhập ở đây" required style="height: 48px; background: rgba(255, 255, 255, 0.95); font-weight: 600; color: #1A2F1A;">
                <button type="submit" id="aiConsultBtn" class="btn btn-light rounded-pill px-4 fw-bold text-nowrap" style="height: 48px; color: #215427; background: #FFFFFF; border: none; box-shadow: 0 4px 14px rgba(0,0,0,0.15);">
                  <i class="fa-solid fa-paper-plane me-1"></i> Tư vấn ngay
                </button>
              </form>
            </div>
          </div>
          <div class="col-lg-4">
            <div class="goiy-hero-card h-100 d-flex flex-column justify-content-center">
              <span class="goiy-eyebrow"><i class="fa-solid fa-user-check me-1"></i> <?= h((string)($profile['skin_type'] ?? 'Hồ sơ da')) ?></span>
              <h3>Tự động phân tích & đề xuất sản phẩm phù hợp 100%</h3>
            </div>
          </div>
        </div>
      </section>

      <?php if (!empty($profile)): ?>
        <div class="profile-summary-box mb-4">
          <div class="d-flex align-items-center justify-content-between flex-wrap gap-2 mb-3">
            <h5 class="fw-bold m-0" style="color: #1A2F1A;"><i class="fa-solid fa-id-card text-success me-2"></i> Hồ sơ da của <?= h((string)($profile['display_name'] ?? 'bạn')) ?></h5>
            <a class="btn btn-sm btn-outline-success rounded-pill fw-bold" href="<?= h($surveyUrl) ?>"><i class="fa-solid fa-pen-to-square me-1"></i> Khảo sát ngay!!</a>
          </div>
          <div>
            <?php if (!empty($profile['skin_type'])): ?>
              <span class="profile-tag"><i class="fa-solid fa-droplet me-1"></i> Loại da: <?= h((string)$profile['skin_type']) ?></span>
            <?php endif; ?>

            <?php
              $concerns = is_array($profile['concerns'] ?? null) ? $profile['concerns'] : [];
              if (!empty($concerns)):
            ?>
              <span class="profile-tag"><i class="fa-solid fa-triangle-exclamation me-1"></i> Vấn đề: <?= h(implode(', ', $concerns)) ?></span>
            <?php endif; ?>

            <?php
              $avoid = is_array($profile['avoid_ingredients'] ?? null) ? $profile['avoid_ingredients'] : [];
              if (!empty($avoid)):
            ?>
              <span class="profile-tag"><i class="fa-solid fa-ban me-1"></i> Tránh: <?= h(implode(', ', $avoid)) ?></span>
            <?php endif; ?>

            <?php if (!empty($profile['budget'])): ?>
              <span class="profile-tag"><i class="fa-solid fa-wallet me-1"></i> Ngân sách: <?= number_format((int)$profile['budget']) ?> đ</span>
            <?php endif; ?>
          </div>
        </div>
      <?php endif; ?>

      <?php if (empty($llamaRecommendation['ok'])): ?>
        <div class="goiy-empty"><?= h($profileUnavailableMessage !== '' ? $profileUnavailableMessage : 'Hiện chưa thể tạo gợi ý cá nhân hóa. Vui lòng thử lại sau.') ?></div>
      <?php else: ?>
        <?php if (!empty($llamaRecommendation['answer_text'])): ?>
          <div class="goiy-advice mb-4" id="aiAdviceContent">
            <div class="fw-bold mb-2 text-success fs-5"><i class="fa-solid fa-robot me-2"></i> Lời khuyên tư vấn:</div>
            <div class="advice-text"><?= nl2br(h((string)$llamaRecommendation['answer_text'])) ?></div>
          </div>
        <?php endif; ?>

        <!-- Khối 1: Chu trình sản phẩm AI chọn riêng cho bạn -->
        <?php if (!empty($llamaRecommendation['products'])): ?>
          <section class="goiy-section mb-4">
            <div class="goiy-section__head">
              <h2 class="goiy-section__title"><i class="fa-solid fa-sparkles me-2 text-warning"></i> Chu trình gợi ý cá nhân hóa hàng đầu</h2>
            </div>
            <div class="rcm-products-grid" id="aiProductGrid">
              <?php foreach ($llamaRecommendation['products'] as $product): ?>
                <?php
                  $matchLabel = (string)($product['match_label'] ?? 'PHÙ HỢP');
                  $renderCard($product, $matchLabel, 'rcm');
                ?>
              <?php endforeach; ?>
            </div>
          </section>
        <?php endif; ?>

        <!-- Khối 2: Sản phẩm bán chạy nhất phù hợp với bạn -->
        <?php if (!empty($profileSections['best_seller'])): ?>
          <section class="goiy-section mb-4">
            <div class="goiy-section__head">
              <h2 class="goiy-section__title"><i class="fa-solid fa-fire me-2 text-danger"></i> Sản phẩm bán chạy nhất phù hợp với làn da bạn</h2>
            </div>
            <div class="rcm-products-grid">
              <?php foreach ($profileSections['best_seller'] as $product): ?>
                <?php $renderCard($product, 'BÁN CHẠY', 'rcm'); ?>
              <?php endforeach; ?>
            </div>
          </section>
        <?php endif; ?>

        <!-- Khối 3: Sản phẩm được đánh giá cao phù hợp với bạn -->
        <?php if (!empty($profileSections['top_rated'])): ?>
          <section class="goiy-section mb-4">
            <div class="goiy-section__head">
              <h2 class="goiy-section__title"><i class="fa-solid fa-star me-2 text-warning"></i> Sản phẩm được đánh giá cao dành cho bạn</h2>
            </div>
            <div class="rcm-products-grid">
              <?php foreach ($profileSections['top_rated'] as $product): ?>
                <?php $renderCard($product, 'ĐÁNH GIÁ CAO', 'rcm'); ?>
              <?php endforeach; ?>
            </div>
          </section>
        <?php endif; ?>

        <!-- Khối 4: Sản phẩm đang giảm giá dành cho bạn -->
        <?php if (!empty($profileSections['discount'])): ?>
          <section class="goiy-section mb-4">
            <div class="goiy-section__head">
              <h2 class="goiy-section__title"><i class="fa-solid fa-tags me-2 text-success"></i> Sản phẩm ưu đãi giảm giá dành cho bạn</h2>
            </div>
            <div class="rcm-products-grid">
              <?php foreach ($profileSections['discount'] as $product): ?>
                <?php $renderCard($product, 'ĐANG GIẢM GIÁ', 'rcm'); ?>
              <?php endforeach; ?>
            </div>
          </section>
        <?php endif; ?>

      <?php endif; ?>
    <?php endif; ?>
  </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
  const consultForm = document.getElementById('aiConsultForm');
  const consultInput = document.getElementById('aiConsultInput');
  const consultBtn = document.getElementById('aiConsultBtn');
  const adviceBox = document.getElementById('aiAdviceContent');

  if (!consultForm) return;

  consultForm.addEventListener('submit', async function (e) {
    e.preventDefault();
    const q = (consultInput.value || '').trim();
    if (!q) return;

    consultBtn.disabled = true;
    consultBtn.innerHTML = '<i class="fa-solid fa-spinner fa-spin me-1"></i> Đang tư vấn...';

    try {
      const resp = await fetch('<?= BASE_URL ?>/index.php?r=ai_chat_api', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ message: q })
      });
      const data = await resp.json();

      if (data && data.ok && data.answer) {
        if (adviceBox) {
          const textEl = adviceBox.querySelector('.advice-text');
          if (textEl) {
            textEl.innerHTML = data.answer.replace(/\n/g, '<br>');
          }
          adviceBox.scrollIntoView({ behavior: 'smooth' });
        }
      }
    } catch (err) {
      console.error('AI Consult error:', err);
    } finally {
      consultBtn.disabled = false;
      consultBtn.innerHTML = '<i class="fa-solid fa-paper-plane me-1"></i> Tư vấn ngay';
    }
  });
});
</script>
