<?php
// backend/app/views/home.php
?>
<div class="container mt-4 home-shell">
  <section class="home-hero-grid">
    <div class="hero hero--beauty" style="background-image: url('https://images.unsplash.com/photo-1556228578-8c89e6adf883?w=1200&q=80');">
      <div class="hero-overlay hero-overlay--beauty">
        <span class="hero-kicker">Beauty e-commerce experience</span>
        <h1 class="hero-title">Khám phá routine, sản phẩm và dữ liệu làn da trong một trải nghiệm mua sắm mượt hơn</h1>
        <p class="hero-subtitle">Tìm sản phẩm chăm da theo thương hiệu, loại da, vấn đề da và ngân sách chỉ trong vài bước.</p>
        <div class="hero-feature-list">
          <span><i class="fas fa-circle-check"></i> Tìm kiếm nhanh kiểu marketplace</span>
          <span><i class="fas fa-circle-check"></i> Gợi ý routine dựa trên khảo sát</span>
          <span><i class="fas fa-circle-check"></i> Hồ sơ làn da đồng bộ với tài khoản</span>
        </div>
        <div class="hero-buttons">
          <a class="btn btn-brand btn-lg" href="<?= BASE_URL ?>/index.php?r=goiy">
            <i class="fas fa-clipboard-list"></i> Làm khảo sát để nhận gợi ý sản phẩm
          </a>
          <a class="btn btn-outline-light btn-lg" href="<?= BASE_URL ?>/index.php?r=tatca">
            <i class="fas fa-search"></i> Khám phá sản phẩm
          </a>
        </div>
      </div>
    </div>

    <div class="hero-side-stack">
      <a class="hero-mini-card hero-mini-card--mint" href="<?= BASE_URL ?>/index.php?r=goiy">
        <span class="hero-mini-card__icon"><i class="fas fa-wand-magic-sparkles"></i></span>
        <strong>Routine AI cá nhân</strong>
        <p>Nhận gợi ý sản phẩm từ dữ liệu khảo sát đã lưu.</p>
      </a>

      <a class="hero-mini-card hero-mini-card--peach" href="<?= BASE_URL ?>/index.php?r=tatca">
        <span class="hero-mini-card__icon"><i class="fas fa-vial-circle-check"></i></span>
        <strong>Tra cứu theo nhu cầu</strong>
        <p>Lọc danh mục, tìm thương hiệu và xem mô tả sản phẩm rõ ràng.</p>
      </a>

      <div class="hero-mini-card hero-mini-card--navy">
        <span class="hero-mini-card__label">Hot categories</span>
        <div class="hero-mini-tags">
          <span>Serum</span>
          <span>Chống nắng</span>
          <span>Dưỡng ẩm</span>
          <span>Làm sạch</span>
        </div>
      </div>
    </div>
  </section>

  <section class="home-benefits-row mt-4">
    <article class="home-benefit-card">
      <i class="fas fa-bolt"></i>
      <div>
        <strong>Tìm nhanh hơn</strong>
        <p>Thanh search và gợi ý thông minh cho trải nghiệm mua sắm tức thì.</p>
      </div>
    </article>
    <article class="home-benefit-card">
      <i class="fas fa-user-doctor"></i>
      <div>
        <strong>Hồ sơ da rõ ràng</strong>
        <p>Lưu lại dữ liệu khảo sát để dùng cho gợi ý và so sánh sản phẩm.</p>
      </div>
    </article>
    <article class="home-benefit-card">
      <i class="fas fa-bag-shopping"></i>
      <div>
        <strong>Mua sắm liền mạch</strong>
        <p>Đi từ khám phá đến giỏ hàng trong cùng một flow trực quan.</p>
      </div>
    </article>
  </section>

  <section class="mt-4 home-category-section">
    <div class="section-header">
      <div>
        <span class="section-kicker">Danh mục nổi bật</span>
        <h4 class="section-title mb-0">Đi nhanh vào nhóm sản phẩm bạn quan tâm</h4>
      </div>
      <a class="link-more" href="<?= BASE_URL ?>/index.php?r=tatca">Xem toàn bộ</a>
    </div>
    <div class="category-promo-grid mt-3">
      <a href="<?= BASE_URL ?>/index.php?r=tatca&cap1=Chăm+Sóc+Da+Mặt&cap2=Bộ+Chăm+Sóc+Da+Mặt" class="category-promo-card">
        <strong>Chăm sóc da</strong>
        <span>Routine trọn bộ cho từng loại da</span>
      </a>
      <a href="<?= BASE_URL ?>/index.php?r=tatca&cap1=Chăm+Sóc+Da+Mặt&cap2=Làm+Sạch+Da" class="category-promo-card">
        <strong>Làm sạch da</strong>
        <span>Sữa rửa mặt, tẩy trang và làm sạch sâu</span>
      </a>
      <a href="<?= BASE_URL ?>/index.php?r=tatca&cap1=Chăm+Sóc+Da+Mặt&cap2=Dưỡng+Ẩm" class="category-promo-card">
        <strong>Dưỡng ẩm</strong>
        <span>Khoá ẩm, phục hồi và làm dịu nền da</span>
      </a>
      <a href="<?= BASE_URL ?>/index.php?r=tatca&cap1=Chăm+Sóc+Da+Mặt&cap2=Chống+Nắng+Da+Mặt" class="category-promo-card">
        <strong>Chống nắng</strong>
        <span>Bảo vệ da mỗi ngày với nhiều texture</span>
      </a>
      <a href="<?= BASE_URL ?>/index.php?r=tatca&cap1=Chăm+Sóc+Da+Mặt&cap2=Đặc+Trị" class="category-promo-card">
        <strong>Đặc trị</strong>
        <span>Tập trung xử lý mụn, thâm và lão hóa</span>
      </a>
    </div>
  </section>

  <section class="mt-4 home-product-section">
    <div class="section-header">
      <div>
        <span class="section-kicker">New arrivals</span>
        <h4 class="section-title mb-0">Sản phẩm mới cập nhật</h4>
      </div>
      <a class="link-more" href="<?= BASE_URL ?>/index.php?r=tatca">Xem tất cả</a>
    </div>

    <div class="row g-3 mt-2">
      <?php foreach (($latest ?? []) as $p):
        $img = first_image_url($p['link_hinh_anh']);
      ?>
        <div class="col-6 col-md-3">
          <a class="product-card product-card--showcase" href="<?= BASE_URL ?>/index.php?r=chitiet&id=<?= (int)$p['id'] ?>">
            <div class="product-thumb">
              <?php if (!empty($p['phan_tram_giam'])): ?>
                <span class="badge-sale">-<?= h($p['phan_tram_giam']) ?>%</span>
              <?php endif; ?>
              <img src="<?= h($img ?: 'https://via.placeholder.com/450x450?text=No+Image') ?>"
                   referrerpolicy="no-referrer"
                   onerror="this.src='https://via.placeholder.com/450x450?text=No+Image';"
                   alt="<?= h($p['ten_san_pham']) ?>">
            </div>
            <div class="product-meta">
              <div class="brand"><?= h($p['thuong_hieu'] ?? '') ?></div>
              <div class="name"><?= h($p['ten_san_pham']) ?></div>
              <div class="price"><?= vnd($p['gia_ban']) ?></div>
            </div>
          </a>
        </div>
      <?php endforeach; ?>
    </div>
  </section>
</div>

