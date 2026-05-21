<?php
$latest = isset($latest) && is_array($latest) ? $latest : [];
$cats = isset($cats) && is_array($cats) ? $cats : [];
$homepageSections = isset($homepageSections) && is_array($homepageSections) ? $homepageSections : [];

$heroProducts = array_slice($latest, 0, 4);
$newProducts = array_slice($latest, 0, 4);

$shortcutCards = [
  ['icon' => 'fa-wand-magic-sparkles', 'title' => 'Routine AI', 'desc' => 'Nhận gợi ý theo hồ sơ da đã lưu.', 'url' => BASE_URL . '/index.php?r=goiy'],
  ['icon' => 'fa-pump-soap', 'title' => 'Routine Làm Sạch', 'desc' => 'Mở nhanh nhóm sữa rửa mặt và tẩy trang.', 'url' => BASE_URL . '/index.php?r=tatca&q=' . urlencode('Làm sạch')],
  ['icon' => 'fa-sun', 'title' => 'Chống Nắng', 'desc' => 'Đi thẳng vào nhóm bảo vệ da mỗi ngày.', 'url' => BASE_URL . '/index.php?r=tatca&q=' . urlencode('Chống nắng')],
  ['icon' => 'fa-droplet', 'title' => 'Phục Hồi Ẩm', 'desc' => 'Chọn nhanh sản phẩm khóa ẩm, làm dịu.', 'url' => BASE_URL . '/index.php?r=tatca&q=' . urlencode('Dưỡng ẩm')],
  ['icon' => 'fa-flask-vial', 'title' => 'Đặc Trị', 'desc' => 'Tập trung vào mụn, thâm và bề mặt da.', 'url' => BASE_URL . '/index.php?r=tatca&q=' . urlencode('Đặc trị')],
];

$brandNames = [];
foreach ($latest as $item) {
  $brand = trim((string)($item['thuong_hieu'] ?? ''));
  if ($brand !== '' && !in_array($brand, $brandNames, true)) {
    $brandNames[] = $brand;
  }
  if (count($brandNames) >= 6) {
    break;
  }
}

$skinSyntaxSignals = [
  ['number' => 'AI', 'label' => 'Routine & giải thích sản phẩm'],
  ['number' => '2H', 'label' => 'Từ khám phá sang checkout gọn'],
  ['number' => '1 hồ sơ', 'label' => 'Đồng bộ khảo sát và lịch sử mua'],
];
$renderHomeProductCard = static function (array $p, string $tag = ''): void {
  $productId = (string)($p['id'] ?? $p['ma_san_pham'] ?? '');
  $img = resolve_image_url((string)($p['link_hinh_anh'] ?? $p['hinh_anh'] ?? ''));
  $giaBan = (string)($p['gia_ban'] ?? '');
  $giaThiTruong = trim((string)($p['gia_thi_truong'] ?? ''));
  $phanTramGiam = function_exists('product_discount_percent') ? product_discount_percent($p) : null;
  ?>
    <a class="product-card product-card--showcase product-card--market" href="<?= BASE_URL ?>/index.php?r=chitiet&id=<?= h($productId) ?>">
      <div class="product-thumb">
        <?php if ($phanTramGiam !== null): ?><span class="badge-sale">-<?= h((string)$phanTramGiam) ?>%</span><?php endif; ?>
        <?php if ($tag !== ''): ?><span class="market-card__tag"><?= h($tag) ?></span><?php endif; ?>
        <img src="<?= h($img ?: 'https://via.placeholder.com/450x450?text=No+Image') ?>" referrerpolicy="no-referrer" onerror="this.src='https://via.placeholder.com/450x450?text=No+Image';" alt="<?= h($p['ten_san_pham'] ?? '') ?>">
      </div>
      <div class="product-meta">
        <div class="brand"><?= h($p['thuong_hieu'] ?? 'SkinSyntax') ?></div>
        <div class="name"><?= h($p['ten_san_pham'] ?? '') ?></div>
        <div class="price-wrap">
          <div class="price"><?= vnd($giaBan) ?></div>
          <?php if ($giaThiTruong !== '' && is_numeric($giaThiTruong) && (float)$giaThiTruong > (float)$giaBan): ?><div class="price-market"><?= vnd($giaThiTruong) ?></div><?php endif; ?>
        </div>
      </div>
    </a>
  <?php
};
?>
<div class="container mt-4 home-shell home-shell--marketplace">
  <section class="market-hero">
    <div class="market-stage">
      <article class="market-stage__hero">
        <div class="market-stage__content">
          <span class="hero-kicker">SkinSyntax Marketplace</span>
          <h1 class="market-stage__title">Skinsyntax</h1>
          <p class="market-stage__subtitle">Khám phá sản phẩm, đi vào danh mục nhanh, xem deal rõ ràng và vẫn giữ trải nghiệm khảo sát da, gợi ý AI, lịch sử chăm da và hồ sơ cá nhân trên cùng một flow.</p>
          <div class="market-stage__actions">
            <a class="btn btn-brand btn-lg" href="<?= BASE_URL ?>/index.php?r=goiy">
              <i class="fas fa-clipboard-list"></i> Làm khảo sát da
            </a>
            <a class="btn btn-market-ghost btn-lg" href="<?= BASE_URL ?>/index.php?r=tatca">
              <i class="fas fa-bag-shopping"></i> Mua sắm ngay
            </a>
          </div>
          <div class="market-stage__signals">
            <?php foreach ($skinSyntaxSignals as $signal): ?>
              <div class="market-signal">
                <strong><?= h($signal['number']) ?></strong>
                <span><?= h($signal['label']) ?></span>
              </div>
            <?php endforeach; ?>
          </div>
        </div>
      </article>

      <div class="market-banner-grid">
        <a class="market-banner market-banner--mint" href="<?= BASE_URL ?>/index.php?r=goiy">
          <span class="market-banner__eyebrow">Skin quiz</span>
          <strong>Phân tích routine cá nhân</strong>
          <p>Lấy hồ sơ da làm trung tâm, không chỉ là một trang bán sản phẩm.</p>
        </a>
        <a class="market-banner market-banner--sand" href="<?= BASE_URL ?>/index.php?r=thanhtoan">
          <span class="market-banner__eyebrow">Loyalty</span>
          <strong>Dùng điểm ngay ở checkout</strong>
          <p>Kết hợp voucher, điểm tích lũy và thanh toán trong cùng một flow.</p>
        </a>
        <a class="market-banner market-banner--ink" href="<?= BASE_URL ?>/index.php?r=hoso">
          <span class="market-banner__eyebrow">Skin profile</span>
          <strong>Hồ sơ da đi cùng tài khoản</strong>
          <p>Theo dõi hạng thành viên, điểm tích lũy và đơn hàng ngay trong hồ sơ.</p>
        </a>
      </div>
    </div>

    <aside class="market-support-stack">
      <a class="support-card support-card--highlight" href="<?= BASE_URL ?>/index.php?r=goiy">
        <span class="support-card__icon"><i class="fas fa-wand-magic-sparkles"></i></span>
        <strong>Routine AI cá nhân</strong>
        <p>Khối nổi bật của SkinSyntax, giữ nguyên tinh thần tư vấn da thay vì chỉ sale.</p>
      </a>
      <a class="support-card" href="<?= BASE_URL ?>/index.php?r=tatca&q=serum">
        <span class="support-card__icon"><i class="fas fa-vial"></i></span>
        <strong>Serum hot</strong>
        <p>Đi nhanh vào nhóm điều trị và phục hồi đang được tìm nhiều.</p>
      </a>
      <a class="support-card" href="<?= BASE_URL ?>/index.php?r=thanhtoan">
        <span class="support-card__icon"><i class="fas fa-qrcode"></i></span>
        <strong>Checkout liền mạch</strong>
        <p>QR chuyển khoản, voucher, điểm thưởng và xác nhận đơn trong cùng flow.</p>
      </a>
    </aside>
  </section>

  <section class="market-shortcut-strip mt-4">
    <?php foreach ($shortcutCards as $shortcut): ?>
      <a class="market-shortcut" href="<?= h($shortcut['url']) ?>">
        <span class="market-shortcut__icon"><i class="fas <?= h($shortcut['icon']) ?>"></i></span>
        <div>
          <strong><?= h($shortcut['title']) ?></strong>
          <p><?= h($shortcut['desc']) ?></p>
        </div>
      </a>
    <?php endforeach; ?>
  </section>

  <section class="market-trust-strip mt-4">
    <div class="trust-pill"><i class="fas fa-shield-heart"></i> Chính hãng và rõ nguồn gốc</div>
    <div class="trust-pill"><i class="fas fa-truck-fast"></i> Mua nhanh, checkout gọn</div>
    <div class="trust-pill"><i class="fas fa-user-doctor"></i> Gợi ý theo hồ sơ da</div>
    <div class="trust-pill"><i class="fas fa-award"></i> Loyalty tích hợp trong tài khoản</div>
  </section>

  <?php if (!empty($newProducts)): ?>
    <section class="market-section mt-4">
      <div class="section-header section-header--compact">
        <div>
          <span class="section-kicker">Vừa cập nhật</span>
          <h4 class="section-title mb-0">Sản phẩm mới!!!</h4>
        </div>
        <a class="link-more" href="<?= BASE_URL ?>/index.php?r=tatca">Xem catalog</a>
      </div>
      <div class="market-product-grid mt-3">
        <?php foreach (array_slice($newProducts, 0, 4) as $p): ?>
          <?php $renderHomeProductCard($p, 'Mới lên kệ'); ?>
        <?php endforeach; ?>
      </div>
    </section>
  <?php endif; ?>

  <?php
    $homeBlocks = [
      'flashDeals' => ['kicker' => 'Deal chớp nhoáng', 'title' => 'Flash deals', 'tag' => 'Flash deal', 'url' => BASE_URL . '/index.php?r=tatca&sort=deal'],
      'bestSellers' => ['kicker' => 'Đang được mua nhiều', 'title' => 'Bán chạy', 'tag' => 'Ban chay', 'url' => BASE_URL . '/index.php?r=tatca&sort=bestseller'],
      'topSearches' => ['kicker' => 'Nhiều lượt xem', 'title' => 'Top tìm kiếm', 'tag' => 'Top tim kiem', 'url' => BASE_URL . '/index.php?r=tatca&sort=trend'],
      'forYou' => ['kicker' => 'Gợi ý nhanh', 'title' => 'Dành cho bạn', 'tag' => 'Phu hop', 'url' => BASE_URL . '/index.php?r=goiy'],
    ];
  ?>
  <?php foreach ($homeBlocks as $blockKey => $blockMeta): ?>
    <?php $blockItems = array_slice(array_values(array_filter($homepageSections[$blockKey] ?? [])), 0, 4); ?>
    <?php if (!empty($blockItems)): ?>
      <section class="market-section mt-4">
        <div class="section-header section-header--compact">
          <div>
            <span class="section-kicker"><?= h($blockMeta['kicker']) ?></span>
            <h4 class="section-title mb-0"><?= h($blockMeta['title']) ?></h4>
          </div>
          <a class="link-more" href="<?= h($blockMeta['url']) ?>">Xem tất cả</a>
        </div>
        <div class="market-product-grid mt-3">
          <?php foreach ($blockItems as $p): ?>
            <?php $renderHomeProductCard($p, (string)$blockMeta['tag']); ?>
          <?php endforeach; ?>
        </div>
      </section>
    <?php endif; ?>
  <?php endforeach; ?>

  <section class="market-section mt-4">
    <div class="section-header section-header--compact">
      <div>
        <span class="section-kicker">Deal đang lên sóng</span>
        <h4 class="section-title mb-0">Khối sản phẩm nổi bật theo form marketplace</h4>
      </div>
      <a class="link-more" href="<?= BASE_URL ?>/index.php?r=tatca">Xem tất cả</a>
    </div>

    <div class="market-product-grid mt-3">
      <?php foreach ($heroProducts as $p):
        $img = resolve_image_url((string)($p['link_hinh_anh'] ?? ''));
        $giaBan = (string)($p['gia_ban'] ?? '');
        $giaThiTruong = trim((string)($p['gia_thi_truong'] ?? ''));
        $phanTramGiam = function_exists('product_discount_percent') ? product_discount_percent($p) : null;
      ?>
        <a class="product-card product-card--showcase product-card--market" href="<?= BASE_URL ?>/index.php?r=chitiet&id=<?= (int)$p['id'] ?>">
          <div class="product-thumb">
            <?php if ($phanTramGiam !== null): ?>
              <span class="badge-sale">-<?= h((string)$phanTramGiam) ?>%</span>
            <?php endif; ?>
            <span class="market-card__tag">Đang quan tâm</span>
            <img src="<?= h($img ?: 'https://via.placeholder.com/450x450?text=No+Image') ?>"
                 referrerpolicy="no-referrer"
                 onerror="this.src='https://via.placeholder.com/450x450?text=No+Image';"
                 alt="<?= h($p['ten_san_pham']) ?>">
          </div>
          <div class="product-meta">
            <div class="brand"><?= h($p['thuong_hieu'] ?? 'SkinSyntax') ?></div>
            <div class="name"><?= h($p['ten_san_pham']) ?></div>
            <div class="price-wrap">
              <div class="price"><?= vnd($giaBan) ?></div>
              <?php if ($giaThiTruong !== '' && (float)$giaThiTruong > 0): ?>
                <div class="price-market"><?= vnd($giaThiTruong) ?></div>
              <?php endif; ?>
            </div>
          </div>
        </a>
      <?php endforeach; ?>
    </div>
  </section>

  <section class="market-ai-panel mt-4">
    <div class="market-ai-panel__content">
      <span class="section-kicker">Giữ chất SkinSyntax</span>
      <h3>Marketplace được thiết kế xung quanh skin science, không chỉ là cửa hàng. Mỗi lựa chọn sản phẩm đều có intelligence về làn da của bạn.</h3>
      <p>Khác biệt chính: phân tích da thực, recommendation cá nhân, giải thích rõ từng sản phẩm, xây dựng loyalty qua hồ sơ da và lịch sử mua hàng. Interface dễ dùng như marketplace, nhưng lõi là expert knowledge về skincare.</p>
      <div class="market-ai-panel__actions">
        <a class="btn btn-brand" href="<?= BASE_URL ?>/index.php?r=goiy">Mở trang gợi ý</a>
        <a class="btn btn-outline-brand" href="<?= BASE_URL ?>/index.php?r=hoso">Xem hồ sơ da</a>
      </div>
    </div>
    <div class="market-ai-panel__stack">
      <div class="ai-note-card">
        <strong>Phễu 1</strong>
        <p>Người dùng mới khám phá sản phẩm qua danh mục hoặc deal.</p>
      </div>
      <div class="ai-note-card">
        <strong>Phễu 2</strong>
        <p>Cần tư vấn skin? Chuyển sang khảo sát da và AI routine builder.</p>
      </div>
      <div class="ai-note-card ai-note-card--accent">
        <strong>Phễu 3</strong>
        <p>Mua xong vẫn quay lại. Follow routine, cập nhật profile da, nhận rewards.</p>
      </div>
    </div>
  </section>

  <section class="market-section mt-4">
    <div class="section-header section-header--compact">
      <div>
        <span class="section-kicker">Chủ đề mua sắm</span>
        <h4 class="section-title mb-0">Khối khám phá nhanh theo nhu cầu</h4>
      </div>
      <a class="link-more" href="<?= BASE_URL ?>/index.php?r=tatca">Đi tới catalog</a>
    </div>
    <div class="market-cluster-grid mt-3">
      <a class="market-cluster market-cluster--sage" href="<?= BASE_URL ?>/index.php?r=tatca&q=mụn">
        <strong>Da mụn</strong>
        <span>Tập trung làm sạch, phục hồi và giảm thâm.</span>
      </a>
      <a class="market-cluster market-cluster--cream" href="<?= BASE_URL ?>/index.php?r=tatca&q=nhạy cảm">
        <strong>Da nhạy cảm</strong>
        <span>Ưu tiên nền công thức dịu, ít gây kích ứng.</span>
      </a>
      <a class="market-cluster market-cluster--blue" href="<?= BASE_URL ?>/index.php?r=tatca&q=serum">
        <strong>Serum cần xem</strong>
        <span>Vitamin C, B5, Hyaluronic Acid và nhiều hơn.</span>
      </a>
      <a class="market-cluster market-cluster--rose" href="<?= BASE_URL ?>/index.php?r=tatca&q=chống nắng">
        <strong>Daily UV</strong>
        <span>Chống nắng dễ dùng hằng ngày với nhiều texture.</span>
      </a>
    </div>
  </section>

  <?php if (!empty($brandNames)): ?>
    <section class="market-brand-ribbon mt-4">
      <div class="market-brand-ribbon__title">Thương hiệu đang xuất hiện nhiều</div>
      <div class="market-brand-ribbon__chips">
        <?php foreach ($brandNames as $brand): ?>
          <a href="<?= BASE_URL ?>/index.php?r=tatca&q=<?= urlencode($brand) ?>"><?= h($brand) ?></a>
        <?php endforeach; ?>
      </div>
    </section>
  <?php endif; ?>
</div>

