<?php
$latest = isset($latest) && is_array($latest) ? $latest : [];
$cats = isset($cats) && is_array($cats) ? $cats : [];
$homepageSections = isset($homepageSections) && is_array($homepageSections) ? $homepageSections : [];
$dbUnavailableMessage = trim((string)($dbUnavailableMessage ?? ''));

$heroProducts = array_slice($latest, 0, 4);
$newProducts = array_slice($latest, 0, 4);
$flashSaleProducts = array_slice(array_values(array_filter($homepageSections['flashDeals'] ?? [])), 0, 8);

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
<style>
  .home-flash-sale {
    background: linear-gradient(135deg, #0f3443 0%, #145c50 58%, #ffcf5a 100%);
    border-radius: 20px;
    color: #fff;
    padding: 22px;
    box-shadow: 0 22px 55px rgba(15, 52, 67, 0.18);
  }

  .home-flash-sale__head {
    display: flex;
    justify-content: space-between;
    gap: 18px;
    align-items: center;
    margin-bottom: 18px;
  }

  .home-flash-sale__title {
    margin: 0;
    font-size: clamp(1.6rem, 3vw, 2.4rem);
    font-weight: 900;
  }

  .home-flash-sale__countdown {
    display: flex;
    gap: 8px;
    flex-wrap: wrap;
  }

  .home-flash-sale__time {
    min-width: 64px;
    border-radius: 10px;
    background: rgba(255, 255, 255, 0.18);
    border: 1px solid rgba(255, 255, 255, 0.25);
    padding: 8px 10px;
    text-align: center;
    backdrop-filter: blur(8px);
  }

  .home-flash-sale__time strong {
    display: block;
    font-size: 1.1rem;
    line-height: 1;
  }

  .home-flash-sale__time span {
    display: block;
    font-size: 0.72rem;
    opacity: 0.9;
    margin-top: 4px;
  }

  .home-flash-sale__more {
    color: #12323c;
    background: #fff3c2;
    border-radius: 999px;
    padding: 10px 15px;
    font-weight: 800;
    text-decoration: none;
    white-space: nowrap;
  }

  .home-flash-sale__grid {
    display: grid;
    grid-template-columns: repeat(4, minmax(0, 1fr));
    gap: 14px;
  }

  .flash-product {
    background: #fff;
    color: #122533;
    border-radius: 12px;
    overflow: hidden;
    display: flex;
    flex-direction: column;
    min-height: 100%;
    text-decoration: none;
    box-shadow: 0 12px 28px rgba(15, 38, 48, 0.12);
  }

  .flash-product__image {
    position: relative;
    aspect-ratio: 1 / 1;
    background: #f5faf8;
  }

  .flash-product__image img {
    width: 100%;
    height: 100%;
    object-fit: cover;
    display: block;
  }

  .flash-product__badge {
    position: absolute;
    top: 10px;
    left: 10px;
    background: #e53935;
    color: #fff;
    border-radius: 999px;
    padding: 5px 9px;
    font-weight: 800;
    font-size: 0.8rem;
  }

  .flash-product__body {
    padding: 12px;
    display: flex;
    flex-direction: column;
    gap: 6px;
    flex: 1;
  }

  .flash-product__brand {
    color: #60727b;
    font-size: 0.82rem;
    font-weight: 700;
    text-transform: uppercase;
  }

  .flash-product__name {
    color: #132b34;
    font-weight: 800;
    line-height: 1.35;
    min-height: 2.7em;
  }

  .flash-product__price {
    color: #e53935;
    font-weight: 900;
  }

  .flash-product__market {
    color: #88959b;
    text-decoration: line-through;
    font-size: 0.88rem;
  }

  .flash-product__actions {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 8px;
    margin-top: auto;
  }

  .flash-product__detail,
  .flash-product__cart {
    border: 0;
    border-radius: 8px;
    min-height: 38px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    font-weight: 800;
    text-decoration: none;
  }

  .flash-product__detail {
    color: #0f614c;
    background: #e7f5ef;
  }

  .flash-product__cart {
    color: #fff;
    background: #0f7b55;
    width: 100%;
  }

  @media (max-width: 991.98px) {
    .home-flash-sale__grid {
      grid-template-columns: repeat(2, minmax(0, 1fr));
    }

    .home-flash-sale__head {
      align-items: flex-start;
      flex-direction: column;
    }
  }

  @media (max-width: 575.98px) {
    .home-flash-sale {
      border-radius: 16px;
      padding: 16px;
    }

    .home-flash-sale__grid {
      grid-template-columns: 1fr;
    }
  }
</style>
<div class="container mt-4 home-shell home-shell--marketplace">
  <?php if ($dbUnavailableMessage !== ''): ?>
    <div class="alert alert-warning border-0 shadow-sm mb-4"><?= h($dbUnavailableMessage) ?></div>
  <?php endif; ?>

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

  <?php if (!empty($flashSaleProducts)): ?>
    <section class="home-flash-sale mt-4" data-flash-sale-countdown>
      <div class="home-flash-sale__head">
        <div>
          <span class="section-kicker text-black-50">Deal chớp nhoáng</span>
          <h2 class="home-flash-sale__title">Flash Sale</h2>
        </div>
        <div class="home-flash-sale__countdown" aria-label="Thời gian còn lại">
          <div class="home-flash-sale__time"><strong data-flash-days>00</strong><span>Ngày</span></div>
          <div class="home-flash-sale__time"><strong data-flash-hours>00</strong><span>Giờ</span></div>
          <div class="home-flash-sale__time"><strong data-flash-minutes>00</strong><span>Phút</span></div>
          <div class="home-flash-sale__time"><strong data-flash-seconds>00</strong><span>Giây</span></div>
        </div>
        <a class="home-flash-sale__more" href="<?= BASE_URL ?>/index.php?r=danhsach&type=flash-sale">Xem tất cả</a>
      </div>

      <div class="home-flash-sale__grid">
        <?php foreach ($flashSaleProducts as $p):
          $productId = (string)($p['id'] ?? $p['ma_san_pham'] ?? '');
          $img = resolve_image_url((string)($p['link_hinh_anh'] ?? $p['hinh_anh'] ?? ''));
          $giaThiTruong = trim((string)($p['gia_thi_truong'] ?? ''));
          $phanTramGiam = function_exists('product_discount_percent') ? product_discount_percent($p) : null;
          $detailUrl = BASE_URL . '/index.php?r=chitiet&id=' . rawurlencode($productId);
          $isOutOfStock = function_exists('product_is_out_of_stock') ? product_is_out_of_stock($p) : false;
        ?>
          <article class="flash-product">
            <a class="flash-product__image" href="<?= h($detailUrl) ?>">
              <?php if ($phanTramGiam !== null): ?><span class="flash-product__badge">-<?= h((string)$phanTramGiam) ?>%</span><?php endif; ?>
              <?php if ($isOutOfStock): ?><span class="flash-product__badge" style="left:auto;right:10px;background:#64748b;">Hết hàng</span><?php endif; ?>
              <img src="<?= h($img ?: 'https://via.placeholder.com/450x450?text=No+Image') ?>" referrerpolicy="no-referrer" onerror="this.src='https://via.placeholder.com/450x450?text=No+Image';" alt="<?= h($p['ten_san_pham'] ?? '') ?>">
            </a>
            <div class="flash-product__body">
              <div class="flash-product__brand"><?= h($p['thuong_hieu'] ?? 'SkinSyntax') ?></div>
              <a class="flash-product__name" href="<?= h($detailUrl) ?>"><?= h($p['ten_san_pham'] ?? '') ?></a>
              <div class="flash-product__price"><?= vnd($p['gia_ban'] ?? 0) ?></div>
              <?php if ($giaThiTruong !== '' && is_numeric($giaThiTruong) && (float)$giaThiTruong > (float)($p['gia_ban'] ?? 0)): ?>
                <div class="flash-product__market"><?= vnd($giaThiTruong) ?></div>
              <?php endif; ?>
              <?php if (!empty($p['diem_danh_gia'])): ?>
                <div class="small text-muted"><i class="fa-solid fa-star text-warning"></i> <?= h((string)$p['diem_danh_gia']) ?>/5</div>
              <?php endif; ?>
              <div class="flash-product__actions">
                <a class="flash-product__detail" href="<?= h($detailUrl) ?>">Chi tiết</a>
                <form method="post" action="<?= BASE_URL ?>/index.php?r=them_gio_hang_ajax" class="m-0">
                  <input type="hidden" name="action" value="add_to_cart">
                  <input type="hidden" name="product_id" value="<?= h($productId) ?>">
                  <input type="hidden" name="ma_san_pham" value="<?= h($productId) ?>">
                  <input type="hidden" name="quantity" value="1">
                  <input type="hidden" name="qty" value="1">
                  <button class="flash-product__cart" type="submit" <?= $isOutOfStock ? 'disabled' : '' ?>><?= $isOutOfStock ? 'Tạm hết hàng' : 'Thêm' ?></button>
                </form>
              </div>
            </div>
          </article>
        <?php endforeach; ?>
      </div>
    </section>
  <?php endif; ?>

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
      <h3>Không chỉ là nơi mua mỹ phẩm. SkinSyntax giúp bạn hiểu làn da, chọn đúng sản phẩm và xây dựng routine phù hợp hơn mỗi ngày. </h3>
      <p>SkinSyntax đồng hành cùng bạn từ lúc khám phá sản phẩm, tạo hồ sơ da, nhận gợi ý cá nhân hóa đến theo dõi quá trình chăm sóc da sau mua. 
        Mỗi lựa chọn đều được hỗ trợ bởi dữ liệu sản phẩm, hồ sơ da và AI tư vấn thông minh.</p>
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

<script>
(function () {
  var root = document.querySelector('[data-flash-sale-countdown]');
  if (!root) return;

  var dayEl = root.querySelector('[data-flash-days]');
  var hourEl = root.querySelector('[data-flash-hours]');
  var minuteEl = root.querySelector('[data-flash-minutes]');
  var secondEl = root.querySelector('[data-flash-seconds]');
  var target = new Date();
  target.setHours(23, 59, 59, 999);

  function pad(value) {
    return String(Math.max(0, value)).padStart(2, '0');
  }

  function tick() {
    var diff = Math.max(0, target.getTime() - Date.now());
    var totalSeconds = Math.floor(diff / 1000);
    var days = Math.floor(totalSeconds / 86400);
    var hours = Math.floor((totalSeconds % 86400) / 3600);
    var minutes = Math.floor((totalSeconds % 3600) / 60);
    var seconds = totalSeconds % 60;

    if (dayEl) dayEl.textContent = pad(days);
    if (hourEl) hourEl.textContent = pad(hours);
    if (minuteEl) minuteEl.textContent = pad(minutes);
    if (secondEl) secondEl.textContent = pad(seconds);
  }

  tick();
  window.setInterval(tick, 1000);
})();
</script>

