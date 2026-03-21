<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script src="<?= BASE_URL ?>/assets/js/live-search.js"></script>
<script src="<?= BASE_URL ?>/assets/js/smart-search.js"></script>
</main>

<footer class="site-footer">
  <div class="container">
    <section class="site-footer__cta">
      <div>
        <span class="site-footer__eyebrow">SkinSyntax beauty commerce</span>
        <h2 class="site-footer__title">Mua mỹ phẩm có định hướng hơn: tìm nhanh, hiểu da rõ và giữ mọi dữ liệu chăm da trong cùng một tài khoản.</h2>
      </div>
      <div class="site-footer__cta-actions">
        <a class="btn btn-brand" href="<?= BASE_URL ?>/index.php?r=goiy">Nhận gợi ý AI</a>
        <a class="btn btn-outline-brand" href="<?= BASE_URL ?>/index.php?r=tatca">Khám phá sản phẩm</a>
      </div>
    </section>

    <section class="site-footer__topline">
      <div class="site-footer__service-pill"><i class="fas fa-phone-volume"></i> Hotline hỗ trợ: 1900 0000</div>
      <div class="site-footer__service-pill"><i class="fas fa-truck-fast"></i> Luồng mua hàng gọn, rõ trạng thái đơn</div>
      <div class="site-footer__service-pill"><i class="fas fa-shield-heart"></i> Tập trung vào routine và dữ liệu da</div>
      <div class="site-footer__socials">
        <a href="https://www.facebook.com/conmeosuagaugauuu/" aria-label="Facebook" target="_blank" rel="noopener noreferrer"><i class="fa-brands fa-facebook-f"></i></a>
        <a href="https://www.youtube.com/@conmeosuagaugauuu" aria-label="YouTube" target="_blank" rel="noopener noreferrer"><i class="fa-brands fa-youtube"></i></a>
        <a href="https://www.instagram.com/bdefhijkp/" aria-label="Instagram" target="_blank" rel="noopener noreferrer"><i class="fa-brands fa-instagram"></i></a>
      </div>
    </section>

    <section class="site-footer__body">
      <div class="site-footer__brand">
        <a class="brand-lockup brand-lockup--footer" href="<?= BASE_URL ?>/index.php">
          <span class="brand-lockup__mark">S</span>
          <span class="brand-lockup__copy">
            <strong>SkinSyntax</strong>
            <small>Decoding Your Skin Language</small>
          </span>
        </a>
        <p class="site-footer__desc">
          Nền tảng khám phá mỹ phẩm tập trung vào trải nghiệm tìm kiếm, hồ sơ da, loyalty và gợi ý routine cá nhân hóa cho người dùng Việt.
        </p>
        <div class="site-footer__trust">
          <span><i class="fas fa-badge-check"></i> Lọc theo hồ sơ da</span>
          <span><i class="fas fa-stars"></i> Gợi ý theo khảo sát</span>
          <span><i class="fas fa-layer-group"></i> Quy trình mua sắm rõ ràng</span>
        </div>
      </div>

      <div class="site-footer__links">
        <div>
          <h6>Khám phá</h6>
          <a href="<?= BASE_URL ?>/index.php?r=home">Trang chủ</a>
          <a href="<?= BASE_URL ?>/index.php?r=tatca">Tất cả sản phẩm</a>
          <a href="<?= BASE_URL ?>/index.php?r=goiy">Gợi ý routine</a>
          <a href="<?= BASE_URL ?>/index.php?r=giohang">Giỏ hàng</a>
        </div>
        <div>
          <h6>Tài khoản</h6>
          <a href="<?= BASE_URL ?>/index.php?r=hoso">Hồ sơ cá nhân</a>
          <a href="<?= BASE_URL ?>/index.php?r=dangnhap">Đăng nhập</a>
          <a href="#" data-bs-toggle="modal" data-bs-target="#authModal" data-auth-tab="register">Đăng ký</a>
          <a href="<?= BASE_URL ?>/index.php?r=thanhtoan">Thanh toán</a>
        </div>
        <div>
          <h6>Hỗ trợ</h6>
          <span>Tra cứu thương hiệu và sản phẩm nhanh</span>
          <span>Khảo sát da để cá nhân hóa đề xuất</span>
          <span>Lưu lịch sử hồ sơ, điểm thưởng và đơn hàng</span>
        </div>
      </div>
    </section>

    <section class="site-footer__bottom">
      <span>SkinSyntax © 2026</span>
      <span>PHP + PostgreSQL + Flask AI service</span>
      <span>Decoding Your Skin Language</span>
    </section>
  </div>
</footer>
<?php
global $pdo;
if ($pdo instanceof PDO) {
  require __DIR__ . '/../components/ai_chat_widget.php';
  require __DIR__ . '/../components/support_chat_widget.php';
}
?>
</body>
</html> 
