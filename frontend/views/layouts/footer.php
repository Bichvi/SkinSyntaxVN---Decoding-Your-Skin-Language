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
      <span>Nền Tảng Mỹ Phẩm & Tư Vấn Da Cá Nhân Hóa AI</span>
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

    function bounceCartHeader() {
      const cartIcon = document.querySelector('.header-icon-link--cart');
      if (cartIcon) {
        cartIcon.classList.remove('cart-bounce-anim');
        void cartIcon.offsetWidth;
        cartIcon.classList.add('cart-bounce-anim');
      }
    }

    function animateFlyToCart(form, button) {
      const cartIcon = document.querySelector('.header-icon-link--cart');
      if (!cartIcon) return;

      const card = form.closest('.product-card, .goiy-product-card, .rcm-product-card, .flash-product, article') || form.parentElement;
      let sourceImg = card ? card.querySelector('img') : null;

      const targetRect = cartIcon.getBoundingClientRect();
      let startX = 0, startY = 0, imgUrl = '';

      if (sourceImg) {
        const imgRect = sourceImg.getBoundingClientRect();
        startX = imgRect.left + imgRect.width / 2;
        startY = imgRect.top + imgRect.height / 2;
        imgUrl = sourceImg.src;
      } else if (button) {
        const btnRect = button.getBoundingClientRect();
        startX = btnRect.left + btnRect.width / 2;
        startY = btnRect.top + btnRect.height / 2;
      } else {
        return;
      }

      const flyEl = document.createElement(imgUrl ? 'img' : 'div');
      if (imgUrl) {
        flyEl.src = imgUrl;
      } else {
        flyEl.innerHTML = '<i class="fa-solid fa-cart-shopping text-white"></i>';
        flyEl.style.background = 'linear-gradient(135deg, #215427 0%, #162F18 100%)';
        flyEl.style.display = 'flex';
        flyEl.style.alignItems = 'center';
        flyEl.style.justifyContent = 'center';
      }

      flyEl.className = 'flying-cart-item';
      flyEl.style.cssText = `
        position: fixed;
        top: ${startY - 25}px;
        left: ${startX - 25}px;
        width: 50px;
        height: 50px;
        object-fit: cover;
        border-radius: 50%;
        border: 2px solid #215427;
        box-shadow: 0 8px 24px rgba(33, 84, 39, 0.4);
        z-index: 99999;
        pointer-events: none;
        transition: all 0.75s cubic-bezier(0.18, 0.89, 0.32, 1.28);
        transform: scale(1);
        opacity: 1;
      `;

      document.body.appendChild(flyEl);

      requestAnimationFrame(function() {
        flyEl.style.top = `${targetRect.top + targetRect.height / 2 - 12}px`;
        flyEl.style.left = `${targetRect.left + targetRect.width / 2 - 12}px`;
        flyEl.style.width = '24px';
        flyEl.style.height = '24px';
        flyEl.style.transform = 'scale(0.2) rotate(360deg)';
        flyEl.style.opacity = '0.3';
      });

      setTimeout(function() {
        if (flyEl && flyEl.parentNode) {
          flyEl.parentNode.removeChild(flyEl);
        }
        bounceCartHeader();
      }, 760);
    }

    document.addEventListener('submit', function (event) {
      const form = event.target;
      if (!(form instanceof HTMLFormElement)) return;
      
      let rawAction = form.getAttribute('action') || form.action || 'index.php?r=them_gio_hang_ajax';
      if (rawAction.startsWith('/index.php') && window.location.pathname.includes('/index.php')) {
        const pathPrefix = window.location.pathname.substring(0, window.location.pathname.indexOf('/index.php'));
        rawAction = pathPrefix + rawAction;
      }
      const targetUrl = new URL(rawAction, window.location.href).href;

      const hasAddCartAction = form.querySelector('input[name="action"][value="add_to_cart"]') || targetUrl.includes('them_gio_hang');
      const hasProductId = form.querySelector('input[name="product_id"]') || form.querySelector('input[name="ma_san_pham"]');
      if (!hasAddCartAction && !hasProductId) return;

      event.preventDefault();
      const button = (event.submitter && event.submitter.matches('button, input[type="submit"]')) ? event.submitter : form.querySelector('button[type="submit"]');
      if (button && button.disabled) return;

      const data = new FormData(form);
      if (event.submitter && event.submitter.name && !data.has(event.submitter.name)) {
        data.append(event.submitter.name, event.submitter.value);
      }

      const buyNowInput = form.querySelector('input[name="buy_now"]');
      const isBuyNow = data.get('buy_now') === '1' || (buyNowInput && buyNowInput.value === '1');

      if (!isBuyNow) {
        animateFlyToCart(form, button);
      }

      if (button) button.disabled = true;

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
              return {ok: false, message: 'Lỗi phản hồi từ server (' + (response.status || 'JSON') + '): ' + text.trim().substring(0, 120)};
            }
          });
        })
        .then(function (json) {
          if (json.ok && (json.redirect_url || isBuyNow)) {
            window.location.href = json.redirect_url || ('<?= BASE_URL ?>/index.php?r=giohang');
            return;
          }
          showCartToast(json.message || (json.ok ? 'Đã thêm sản phẩm vào giỏ hàng' : 'Không thể thêm sản phẩm'), !!json.ok);
          if (json.ok && typeof json.cart_count !== 'undefined') {
            updateCartBadge(parseInt(json.cart_count, 10) || 0);
            bounceCartHeader();
          }
        })
        .catch(function (error) {
          console.error('Add cart request failed', error);
          showCartToast('Không thể gửi yêu cầu: ' + (error.message || error), false);
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

<?php
$topSaleProducts = function_exists('get_top_10_sale_products') ? get_top_10_sale_products() : [];
?>

<!-- DYNAMIC TOP 10 HOT SALE PRODUCTS FLOATING TOAST -->
<style>
@keyframes cartBounceAnim {
  0% { transform: scale(1); }
  40% { transform: scale(1.35) rotate(-10deg); color: #215427; }
  70% { transform: scale(0.95) rotate(5deg); }
  100% { transform: scale(1); }
}

.cart-bounce-anim {
  animation: cartBounceAnim 0.65s cubic-bezier(0.175, 0.885, 0.32, 1.275) !important;
}

@keyframes pulseGlowBtn {
  0% {
    box-shadow: 0 0 0 0 rgba(33, 84, 39, 0.45), 0 4px 14px rgba(33, 84, 39, 0.25);
  }
  70% {
    box-shadow: 0 0 0 10px rgba(33, 84, 39, 0), 0 6px 20px rgba(33, 84, 39, 0.4);
  }
  100% {
    box-shadow: 0 0 0 0 rgba(33, 84, 39, 0), 0 4px 14px rgba(33, 84, 39, 0.25);
  }
}

@keyframes shimmerSweep {
  0% { transform: translateX(-150%) rotate(25deg); }
  100% { transform: translateX(250%) rotate(25deg); }
}

.btn-buy-now-pulse,
.btn-product-buy {
  position: relative !important;
  overflow: hidden !important;
  animation: pulseGlowBtn 2.6s infinite ease-in-out !important;
  transition: transform 0.22s ease, filter 0.22s ease !important;
}

.btn-buy-now-pulse:hover,
.btn-product-buy:hover {
  transform: scale(1.05) translateY(-1px) !important;
  filter: brightness(1.1) !important;
}

.btn-buy-now-pulse::after,
.btn-product-buy::after {
  content: '';
  position: absolute;
  top: -50%;
  left: -50%;
  width: 200%;
  height: 200%;
  background: linear-gradient(
    60deg,
    rgba(255, 255, 255, 0) 20%,
    rgba(255, 255, 255, 0.4) 50%,
    rgba(255, 255, 255, 0) 80%
  );
  transform: rotate(25deg);
  animation: shimmerSweep 3.2s infinite linear;
  pointer-events: none;
}
</style>

<?php if (!empty($topSaleProducts)): ?>
<div id="topSalesToast" class="top-sales-toast shadow-lg rounded-4 p-3 d-flex align-items-center gap-3" style="position: fixed; bottom: 24px; left: 24px; z-index: 1040; background: rgba(255, 255, 255, 0.98); backdrop-filter: blur(14px); border: 1.5px solid #C5DAC8; box-shadow: 0 16px 36px rgba(33, 84, 39, 0.2); width: 380px; max-width: calc(100vw - 48px); transform: translateY(160px); opacity: 0; transition: all 0.5s cubic-bezier(0.16, 1, 0.3, 1); pointer-events: auto;">
  <a id="topSalesToastLink" href="#" class="d-block flex-shrink-0 position-relative" style="width: 64px; height: 64px; border-radius: 12px; overflow: hidden; background: #F8FAF8; border: 1px solid #E2EADF;">
    <img id="topSalesToastImg" src="" alt="Top Sale Product" style="width: 100%; height: 100%; object-fit: cover;">
    <span id="topSalesToastBadge" class="position-absolute" style="top: 2px; left: 2px; background: linear-gradient(135deg, #E11D48 0%, #F43F5E 100%); color: #FFF; font-weight: 800; font-size: 0.65rem; padding: 2px 6px; border-radius: 999px;">-0%</span>
  </a>
  <div class="flex-grow-1 overflow-hidden" style="line-height: 1.3;">
    <div class="d-flex align-items-center justify-content-between mb-1">
      <span class="badge rounded-pill" style="background: #EAF0EB; color: #215427; font-size: 0.68rem; font-weight: 800;"><i class="fa-solid fa-fire text-danger me-1"></i>TOP SALE SỐC</span>
      <span class="text-muted extra-small" style="font-size: 0.7rem;" id="topSalesToastRank">#1 SẢN PHẨM</span>
    </div>
    <h6 class="fw-bold text-dark text-truncate mb-1" id="topSalesToastName" style="font-size: 0.85rem;">Tên sản phẩm</h6>
    <div class="d-flex align-items-baseline gap-2">
      <strong class="fw-bold" style="color: #215427; font-size: 0.95rem;" id="topSalesToastPrice">0đ</strong>
      <span class="text-muted text-decoration-line-through extra-small" style="font-size: 0.75rem;" id="topSalesToastMarket">0đ</span>
    </div>
  </div>
  <div class="d-flex flex-column gap-1 ms-1 flex-shrink-0">
    <div class="d-flex align-items-center gap-1 justify-content-end mb-auto">
      <button type="button" class="btn btn-sm btn-link text-muted p-0 text-decoration-none extra-small" onclick="muteSalesToast10Min()" style="font-size: 0.68rem; white-space: nowrap;" title="Tắt thông báo popup trong 10 phút">
        <i class="fa-solid fa-bell-slash me-1"></i>Tắt 10p
      </button>
      <button type="button" class="btn-close" onclick="document.getElementById('topSalesToast').style.transform='translateY(160px)'" style="font-size: 0.65rem;" title="Đóng"></button>
    </div>
    <form id="topSalesToastForm" method="post" action="<?= BASE_URL ?>/index.php?r=them_gio_hang_ajax" class="m-0">
      <input type="hidden" name="action" value="add_to_cart">
      <input type="hidden" name="buy_now" value="1">
      <input type="hidden" name="product_id" id="topSalesToastInputId" value="">
      <input type="hidden" name="quantity" value="1">
      <button type="submit" class="btn btn-sm text-white fw-bold btn-buy-now-pulse" style="background: linear-gradient(135deg, #215427 0%, #162F18 100%); border-radius: 999px; font-size: 0.72rem; padding: 5px 10px; border: none; white-space: nowrap;"> Mua Ngay</button>
    </form>
  </div>
</div>

<script>
window.isSalesToastMuted = function() {
  const mutedUntil = localStorage.getItem('hideSalesToastUntil');
  if (!mutedUntil) return false;
  if (Date.now() > parseInt(mutedUntil, 10)) {
    localStorage.removeItem('hideSalesToastUntil');
    return false;
  }
  return true;
};

window.muteSalesToast10Min = function() {
  const tenMinutesMs = 10 * 60 * 1000;
  localStorage.setItem('hideSalesToastUntil', String(Date.now() + tenMinutesMs));
  const toast = document.getElementById('topSalesToast');
  if (toast) {
    toast.style.transform = 'translateY(160px)';
    toast.style.opacity = '0';
  }
  window.dispatchEvent(new Event('salesToastMuteChanged'));
};

window.unmuteSalesToast = function() {
  localStorage.removeItem('hideSalesToastUntil');
  window.dispatchEvent(new Event('salesToastMuteChanged'));
};

document.addEventListener('DOMContentLoaded', function () {
  const topSalesData = <?= json_encode($topSaleProducts, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
  const toast = document.getElementById('topSalesToast');
  const imgEl = document.getElementById('topSalesToastImg');
  const badgeEl = document.getElementById('topSalesToastBadge');
  const nameEl = document.getElementById('topSalesToastName');
  const priceEl = document.getElementById('topSalesToastPrice');
  const marketEl = document.getElementById('topSalesToastMarket');
  const rankEl = document.getElementById('topSalesToastRank');
  const linkEl = document.getElementById('topSalesToastLink');
  const inputId = document.getElementById('topSalesToastInputId');

  if (!toast || !topSalesData || !topSalesData.length) return;

  let index = 0;
  function formatVnd(amount) {
    return new Intl.NumberFormat('vi-VN').format(amount) + 'đ';
  }

  function populateToastData(item, rankIndex) {
    if (!item) return;
    if (imgEl) { imgEl.src = item.image || ''; imgEl.alt = item.name || ''; }
    if (badgeEl) badgeEl.textContent = '-' + (item.discount || 0) + '%';
    if (nameEl) nameEl.textContent = item.name || '';
    if (priceEl) priceEl.textContent = formatVnd(item.price || 0);
    if (marketEl) marketEl.textContent = item.market_price > item.price ? formatVnd(item.market_price) : '';
    if (rankEl) rankEl.textContent = '#' + (rankIndex + 1) + ' DEAL';
    if (linkEl) linkEl.href = item.detail_url || '#';
    if (inputId) inputId.value = item.id || '';
  }

  // Populate first product immediately so inputId has a valid product ID from start
  populateToastData(topSalesData[0], 0);

  function showNextSaleProduct() {
    if (window.isSalesToastMuted && window.isSalesToastMuted()) return;
    const item = topSalesData[index % topSalesData.length];
    if (!item) return;

    populateToastData(item, index % topSalesData.length);

    toast.style.transform = 'translateY(0)';
    toast.style.opacity = '1';

    setTimeout(() => {
      toast.style.transform = 'translateY(160px)';
      toast.style.opacity = '0';
    }, 6000);

    index++;
  }

  setTimeout(showNextSaleProduct, 2500);
  setInterval(showNextSaleProduct, 14000);
});
</script>
<?php endif; ?>
</body>
</html>
