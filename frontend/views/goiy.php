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
  .goiy-page { background: #F9FBF8; border-radius: 28px; padding: 32px; border: 1px solid #E2EADF; box-shadow: 0 10px 30px rgba(45, 90, 39, 0.04); }
  .goiy-hero {
    background: linear-gradient(135deg, #162F18 0%, #2D5A27 60%, #4A7C59 100%);
    border-radius: 28px;
    color: #fff;
    padding: 36px 42px;
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
  .goiy-hero h1 { font-size: clamp(1.8rem, 3.5vw, 2.7rem); font-weight: 800; color: #FFFFFF; margin: 12px 0 8px; }
  .goiy-hero p { color: #EAF2EC; opacity: 0.92; font-size: 1.02rem; margin: 0; line-height: 1.6; }
  .goiy-hero-card {
    background: rgba(255,255,255,.12);
    border: 1px solid rgba(255,255,255,.18);
    border-radius: 22px;
    padding: 24px;
    backdrop-filter: blur(12px);
  }
  .goiy-hero-card h3 { font-size: 1.35rem; line-height: 1.35; margin: 10px 0 0; color: #FFFFFF; font-weight: 700; }
  .goiy-filter {
    background: #fff;
    border: 1px solid #E2EADF;
    border-radius: 22px;
    box-shadow: 0 8px 24px rgba(45, 90, 39, 0.06);
    padding: 24px;
  }
  .goiy-filter-grid {
    display: grid;
    grid-template-columns: minmax(200px, 1.2fr) repeat(5, minmax(130px, 1fr)) auto auto;
    gap: 14px;
    align-items: end;
  }
  .goiy-filter label { color: #1A2F1A; font-weight: 700; font-size: 0.84rem; margin-bottom: 6px; }
  .goiy-filter .form-control, .goiy-filter .form-select { height: 46px; border-radius: 12px; border-color: #E2EADF; background: #F0F4F1; font-weight: 600; color: #1A2F1A; }
  .goiy-filter .form-control:focus, .goiy-filter .form-select:focus { border-color: #2D5A27; box-shadow: 0 0 0 3px rgba(45,90,39,0.12); }
  .goiy-filter .btn { height: 46px; border-radius: 12px; font-weight: 700; white-space: nowrap; }
  .price-pills { display: flex; flex-wrap: wrap; gap: 8px; margin-top: 14px; }
  .price-pill {
    font-size: 0.82rem;
    font-weight: 700;
    padding: 6px 14px;
    border-radius: 999px;
    border: 1px solid #E2EADF;
    background: #F0F4F1;
    color: #2D5A27;
    text-decoration: none;
    transition: all 0.2s ease;
  }
  .price-pill:hover, .price-pill.active { background: #2D5A27; color: #FFFFFF; border-color: #2D5A27; }
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
    border-radius: 22px;
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
  .goiy-section__title { font-size: 1.45rem; font-weight: 800; color: #1A2F1A; margin: 0; }
  .goiy-section__tools { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; justify-content: flex-end; }
  .goiy-section__more {
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
  .goiy-section__more:hover { background: #2D5A27; color: #FFFFFF; }
  .goiy-product-grid, .rcm-products-grid {
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
    padding: 28px;
    text-align: center;
    font-weight: 700;
  }
  .profile-summary-box {
    background: #FFFFFF;
    border: 1px solid #E2EADF;
    border-radius: 22px;
    padding: 24px 28px;
    box-shadow: 0 8px 24px rgba(45, 90, 39, 0.06);
    margin-bottom: 24px;
  }
  .profile-tag {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    background: #EAF0EB;
    color: #2D5A27;
    border-radius: 999px;
    padding: 6px 14px;
    font-size: 0.85rem;
    font-weight: 700;
    margin-right: 8px;
    margin-bottom: 8px;
  }
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
            <span class="goiy-eyebrow"><i class="fa-solid fa-wand-magic-sparkles me-1"></i> GỢI Ý CÁ NHÂN HÓA LANGCHAIN RAG</span>
            <h1>Gợi ý dành riêng cho bạn</h1>
            <p class="mb-3">SkinSyntax RAG AI tự động phân tích hồ sơ làn da và lịch sử tương tác để đưa ra chu trình sản phẩm tối ưu nhất cho bạn.</p>

            <!-- Ô Hỏi AI tư vấn theo nhu cầu của bạn nằm ngay trong khung này -->
            <div class="p-3 rounded-4 mt-2" style="background: rgba(255, 255, 255, 0.15); backdrop-filter: blur(10px); border: 1px solid rgba(255, 255, 255, 0.25);">
              <label class="form-label fw-bold mb-1.5 text-white" style="font-size: 0.92rem;"><i class="fa-solid fa-comments me-2 text-warning"></i> Hỏi AI tư vấn theo nhu cầu của bạn:</label>
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
            <div class="fw-bold mb-2 text-success fs-5"><i class="fa-solid fa-robot me-2"></i> Lời khuyên tư vấn từ AI:</div>
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
