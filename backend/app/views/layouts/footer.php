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
if ($pdo !== null) {
  require __DIR__ . '/../components/ai_chat_widget.php';
  require __DIR__ . '/../components/support_chat_widget.php';
}
?>
<script>
  document.addEventListener('DOMContentLoaded', function () {
    const showCartToast = function (message, ok) {
      let toast = document.querySelector('[data-cart-toast]');
      if (!toast) {
        toast = document.createElement('div');
        toast.setAttribute('data-cart-toast', 'true');
        toast.style.position = 'fixed';
        toast.style.right = '24px';
        toast.style.bottom = '24px';
        toast.style.zIndex = '2200';
        toast.style.maxWidth = '320px';
        toast.style.padding = '12px 16px';
        toast.style.borderRadius = '12px';
        toast.style.boxShadow = '0 16px 38px rgba(15, 23, 42, 0.18)';
        toast.style.fontWeight = '700';
        toast.style.transition = 'opacity .2s ease, transform .2s ease';
        document.body.appendChild(toast);
      }
      toast.textContent = message || '';
      toast.style.background = ok ? '#0f8d63' : '#b42318';
      toast.style.color = '#fff';
      toast.style.opacity = '1';
      toast.style.transform = 'translateY(0)';
      window.clearTimeout(toast._timer);
      toast._timer = window.setTimeout(function () {
        toast.style.opacity = '0';
        toast.style.transform = 'translateY(8px)';
      }, 2600);
    };

    const updateCartBadge = function (count) {
      const cartLink = document.querySelector('.header-icon-link--cart');
      if (!cartLink) return;
      let badge = cartLink.querySelector('.header-cart-badge');
      if (count > 0 && !badge) {
        badge = document.createElement('em');
        badge.className = 'header-cart-badge';
        cartLink.appendChild(badge);
      }
      if (badge) {
        badge.textContent = String(count);
        badge.style.display = count > 0 ? '' : 'none';
      }
    };

    document.addEventListener('submit', function (event) {
      const form = event.target;
      if (!(form instanceof HTMLFormElement)) return;
      if (!form.querySelector('input[name="action"][value="add_to_cart"]')) return;

      event.preventDefault();
      const button = form.querySelector('button[type="submit"]');
      if (button && button.disabled) return;
      if (button) button.disabled = true;

      const data = new FormData(form);
      const targetUrl = form.getAttribute('action') || form.action || window.location.href;
      fetch(targetUrl, {
        method: 'POST',
        body: data,
        headers: {'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json'}
      })
        .then(function (response) {
          return response.text().then(function (text) {
            try {
              const json = JSON.parse(text);
              if (!response.ok) {
                console.error('Add cart HTTP error', response.status, json);
              }
              return json;
            } catch (error) {
              console.error('Add cart invalid JSON response', {
                status: response.status,
                responseText: text,
                error: error
              });
              return {ok: false, message: 'Không thể thêm sản phẩm lúc này. Vui lòng thử lại.'};
            }
          });
        })
        .then(function (json) {
          showCartToast(json.message || (json.ok ? 'Đã thêm sản phẩm vào giỏ hàng' : 'Không thể thêm sản phẩm'), !!json.ok);
          if (json.ok && typeof json.cart_count !== 'undefined') updateCartBadge(parseInt(json.cart_count, 10) || 0);
        })
        .catch(function (error) {
          console.error('Add cart request failed', error);
          showCartToast('Không thể thêm sản phẩm lúc này. Vui lòng thử lại.', false);
        })
        .finally(function () {
          if (button) button.disabled = false;
        });
    });

    if (!document.querySelector('[data-support-chat-widget]')) {
      document.querySelectorAll('[data-support-chat-toggle]').forEach(function (button) {
        button.addEventListener('click', function (event) {
          event.preventDefault();
          window.location.href = '<?= BASE_URL ?>/index.php?r=lichsuchat';
        });
      });
    }
  });
</script>
</body>
</html> 
