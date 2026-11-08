<?php
// frontend/views/partials/cart_insights.php
// U09: khối giỏ hàng "Lưu ý hoạt chất" + "Hoàn thiện routine của bạn". Được SanPhamController::cartInsights() render
// thành HTML, giohang.php nhúng bằng fetch (giỏ hàng luôn chạy dù khối này lỗi). Không chặn thanh toán.
// Biến vào: $cartInsights = CartInsights::build(...) ['conflicts','steps','suggestions','degraded']. Mọi chuỗi đều escape ở đây.
// Tracking dùng lại search-fallback.js: .ssf[data-ssf] > .ssf-section[data-ssf-section-key="conflict|routine"] > .ssf-card[data-ssf-section].
if (!isset($cartInsights) || !is_array($cartInsights) || empty($cartInsights['visible'])) {
  return;
}
$cinE = static function ($v): string {
  return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
};
$cinCsrf = class_exists('SearchEventTracker') ? SearchEventTracker::csrfToken() : '';
$cinConflicts = (array)($cartInsights['conflicts'] ?? []);
$cinSteps = (array)($cartInsights['steps'] ?? []);
$cinSuggestions = (array)($cartInsights['suggestions'] ?? []);
$cinDetail = static function (string $id): string {
  return BASE_URL . '/index.php?r=chitiet&id=' . rawurlencode($id);
};
?>
<div class="ssf ssf-cart cin" data-ssf data-ssf-degraded="<?= !empty($cartInsights['degraded']) ? '1' : '0' ?>"
  data-ssf-q="cart:analyze" data-ssf-csrf="<?= $cinE($cinCsrf) ?>"
  data-ssf-endpoint="<?= $cinE(BASE_URL . '/index.php?r=search_event') ?>">

  <?php if ($cinConflicts): ?>
    <section class="ssf-section cin-section cin-conflicts" aria-labelledby="cin-conflict-heading" data-ssf-section-key="conflict">
      <h4 class="ssf-section-title cin-title" id="cin-conflict-heading">Lưu ý khi dùng chung hoạt chất</h4>
      <ul class="cin-conflict-list">
        <?php foreach ($cinConflicts as $c):
          $sev = in_array($c['severity'], ['high', 'medium', 'low'], true) ? $c['severity'] : 'low';
          $icon = $sev === 'high' ? 'fa-triangle-exclamation' : 'fa-circle-info';
        ?>
          <li class="cin-conflict cin-sev-<?= $cinE($sev) ?>" data-severity="<?= $cinE($sev) ?>" data-rule="<?= $cinE($c['rule_id']) ?>">
            <div class="cin-conflict-head">
              <span class="cin-badge cin-badge-<?= $cinE($sev) ?>"><i class="fa-solid <?= $cinE($icon) ?>" aria-hidden="true"></i> <?= $cinE($c['severity_label']) ?></span>
              <?php if (!empty($c['ingredients'])): ?>
                <span class="cin-ings"><?= $cinE(implode(' + ', $c['ingredients'])) ?></span>
              <?php endif; ?>
            </div>
            <div class="cin-pair">
              <a href="<?= $cinE($cinDetail($c['a']['id'])) ?>"><?= $cinE($c['a']['name']) ?></a>
              <span class="cin-plus" aria-hidden="true">+</span>
              <a href="<?= $cinE($cinDetail($c['b']['id'])) ?>"><?= $cinE($c['b']['name']) ?></a>
            </div>
            <?php if ($c['advice'] !== ''): ?><p class="cin-advice"><?= $cinE($c['advice']) ?></p><?php endif; ?>
            <?php if ($c['timing_text'] !== ''): ?><p class="cin-timing"><?= $cinE($c['timing_text']) ?></p><?php endif; ?>
          </li>
        <?php endforeach; ?>
      </ul>
      <p class="cin-note">Chỉ là gợi ý tham khảo, bạn vẫn có thể thanh toán bình thường. Da nhạy cảm hoặc đang điều trị nên hỏi bác sĩ da liễu.</p>
    </section>
  <?php endif; ?>

  <?php if ($cinSuggestions): ?>
    <section class="ssf-section cin-section cin-routine" aria-labelledby="cin-routine-heading" data-ssf-section-key="routine">
      <h4 class="ssf-section-title cin-title" id="cin-routine-heading">Hoàn thiện routine của bạn</h4>
      <?php if ($cinSteps): ?>
        <ul class="cin-steps" aria-label="Các bước routine">
          <?php foreach ($cinSteps as $st): ?>
            <li class="cin-step cin-step-<?= $cinE($st['state']) ?>" data-step="<?= $cinE($st['key']) ?>">
              <i class="fa-solid <?= $st['state'] === 'has' ? 'fa-circle-check' : 'fa-circle-plus' ?>" aria-hidden="true"></i>
              <?= $cinE($st['label']) ?><span class="visually-hidden"><?= $st['state'] === 'has' ? ' (đã có trong giỏ)' : ' (còn thiếu)' ?></span>
            </li>
          <?php endforeach; ?>
        </ul>
      <?php endif; ?>

      <?php foreach ($cinSuggestions as $group): ?>
        <div class="cin-group" data-step="<?= $cinE($group['step']) ?>">
          <h5 class="cin-group-title">Còn thiếu: <?= $cinE($group['label']) ?></h5>
          <div class="ssim-track cin-track" data-cin-track role="list" tabindex="0" aria-label="<?= $cinE('Gợi ý ' . $group['label']) ?>">
            <?php foreach ($group['items'] as $p):
              if (!is_array($p)) continue;
              $productId = (string)($p['id'] ?? $p['ma_san_pham'] ?? '');
              if ($productId === '') continue;
              $img = resolve_image_url(function_exists('product_image_source') ? product_image_source($p) : (string)($p['link_hinh_anh'] ?? $p['hinh_anh'] ?? ''));
              $giaBan = (string)($p['gia_ban'] ?? '');
              $isOutOfStock = function_exists('product_is_out_of_stock') ? product_is_out_of_stock($p) : false;
              $reasonText = trim((string)($p['reason_text'] ?? ''));
              $detailUrl = $cinDetail($productId);
            ?>
              <div class="product-card ssf-card ssim-card cin-card" role="listitem" data-ssf-section="routine" data-ssf-reason="<?= $cinE($p['reason_code'] ?? '') ?>" data-product-id="<?= $cinE($productId) ?>">
                <div class="ssf-thumb">
                  <?php if ($reasonText !== ''): ?>
                    <span class="ssf-reason" title="<?= $cinE($reasonText) ?>" aria-label="Lý do gợi ý: <?= $cinE($reasonText) ?>"><?= $cinE($reasonText) ?></span>
                  <?php endif; ?>
                  <a href="<?= $cinE($detailUrl) ?>" class="ssf-thumb-link">
              <img src="<?= $cinE($img ?: default_placeholder_image()) ?>" loading="lazy" decoding="async" width="300" height="300" referrerpolicy="no-referrer" data-image-fallbacks="<?= function_exists('product_image_fallbacks_json') ? product_image_fallbacks_json($p, $img) : '[]' ?>" data-image-placeholder="<?= $cinE(default_placeholder_image()) ?>" onerror="window.SkinSyntaxImageFallback(this)" alt="<?= $cinE($p['ten_san_pham'] ?? '') ?>">
                  </a>
                </div>
                <div class="ssf-meta">
                  <div class="ssf-brand"><?= $cinE($p['thuong_hieu'] ?? 'SkinSyntax') ?></div>
                  <a class="ssf-name" href="<?= $cinE($detailUrl) ?>"><?= $cinE($p['ten_san_pham'] ?? '') ?></a>
                  <div class="ssf-price-wrap"><span class="ssf-price"><?= vnd($giaBan) ?></span></div>
                  <div class="product-card-actions ssf-actions">
                    <form method="post" action="<?= BASE_URL ?>/index.php?r=them_gio_hang_ajax" class="m-0 w-100">
                      <input type="hidden" name="action" value="add_to_cart">
                      <input type="hidden" name="product_id" value="<?= $cinE($productId) ?>">
                      <input type="hidden" name="ma_san_pham" value="<?= $cinE($productId) ?>">
                      <input type="hidden" name="quantity" value="1">
                      <input type="hidden" name="qty" value="1">
                      <button class="btn btn-sm w-100 ssf-btn-cart" type="submit" <?= $isOutOfStock ? 'disabled' : '' ?> title="Thêm vào giỏ">
                        <?= $isOutOfStock ? 'Hết hàng' : '<i class="fa-solid fa-cart-plus me-1" aria-hidden="true"></i><span>Thêm vào giỏ</span>' ?>
                      </button>
                    </form>
                  </div>
                </div>
              </div>
            <?php endforeach; ?>
          </div>
        </div>
      <?php endforeach; ?>
    </section>
  <?php endif; ?>
</div>
