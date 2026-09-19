<?php
$step = is_array($step ?? null) ? $step : [];
$product = is_array($step['recommended_product'] ?? null) ? $step['recommended_product'] : [];
$alternatives = is_array($step['alternatives'] ?? null) ? $step['alternatives'] : [];
$session = trim((string)($session ?? 'am')) === 'pm' ? 'pm' : 'am';
$stepKey = trim((string)($stepKey ?? ($session . '-' . ($step['step_order'] ?? 0))));
$productId = trim((string)($product['id'] ?? $product['product_id'] ?? ''));
$productName = trim((string)($product['name'] ?? $product['ten_san_pham'] ?? 'Sản phẩm SkinSyntax'));
$brand = trim((string)($product['brand'] ?? $product['thuong_hieu'] ?? ''));
$price = (int)($product['price'] ?? $product['gia_ban'] ?? 0);
$originalPrice = (int)($product['original_price'] ?? $product['gia_thi_truong'] ?? $product['market_price'] ?? 0);
$image = resolve_image_url((string)($product['image'] ?? $product['image_url'] ?? $product['link_hinh_anh'] ?? ''));
if ($image === '') {
    $image = default_placeholder_image();
}
$detailUrl = trim((string)($product['detail_url'] ?? ''));
if ($detailUrl === '' && $productId !== '') {
    $detailUrl = BASE_URL . '/index.php?r=chitiet&id=' . rawurlencode($productId);
}
$scoreRaw = $product['match_score'] ?? $product['match_percent'] ?? $product['score'] ?? null;
$score = is_numeric($scoreRaw) ? max(0, min(100, (float)$scoreRaw)) : null;
$scoreLabel = $score !== null ? rtrim(rtrim(number_format($score, 1, '.', ''), '0'), '.') . '%' : '';
$matchLabel = trim((string)($product['match_label'] ?? $product['fit_status'] ?? ''));
$ingredients = is_array($product['key_ingredients'] ?? null) ? $product['key_ingredients'] : [];
$ingredients = array_values(array_filter(array_map(static fn($item): string => trim((string)$item), $ingredients)));
$avoidFlags = is_array($product['avoid_flags'] ?? null) ? $product['avoid_flags'] : [];
$avoidFlags = array_values(array_filter(array_map(static fn($item): string => trim((string)$item), $avoidFlags)));
$safety = is_array($step['safety'] ?? null) ? $step['safety'] : [];
$safetyLevel = in_array(($safety['level'] ?? ''), ['warning', 'unknown'], true) ? $safety['level'] : 'safe';
$safetyText = trim((string)($safety['text'] ?? ''));
if ($safetyText === '') {
    $safetyText = $safetyLevel === 'safe'
        ? 'Đã kiểm tra: chưa ghi nhận cờ an toàn bất thường trong dữ liệu trả về.'
        : 'Chưa đủ dữ liệu thành phần để kết luận an toàn.';
}
$why = trim((string)($step['why_this_product'] ?? $product['why_this_product'] ?? $product['reason'] ?? ''));
if ($why === '') {
    $why = 'Sản phẩm được chọn từ catalog theo hồ sơ và mục tiêu chăm da hiện có.';
}
$stepName = trim((string)($step['step_name'] ?? 'Sản phẩm phù hợp'));
$stepOrder = (int)($step['step_order'] ?? 0);
$category = trim((string)($step['category'] ?? ''));
$searchText = trim(implode(' ', array_filter([
    $productName,
    $brand,
    $category,
    implode(' ', $ingredients),
    implode(' ', is_array($product['matched_concerns'] ?? null) ? $product['matched_concerns'] : []),
    $why,
])));
$isOutOfStock = in_array(strtolower(trim((string)($product['stock_status'] ?? ''))), ['out_of_stock', 'hidden', 'inactive'], true)
    || !empty($product['is_out_of_stock']);
$hasStrongActive = (bool)preg_match('/retinol|tretinoin|bha|aha|benzoyl|salicylic|glycolic/i', $searchText);
$modalData = static function (array $item): string {
    return json_encode($item, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?: '{}';
};
?>
<article
  class="recommendation-step-card"
  data-step-card
  data-step-key="<?= h($stepKey) ?>"
  data-step-order="<?= h((string)$stepOrder) ?>"
  data-category="<?= h($category) ?>"
  data-search="<?= h(mb_strtolower($searchText, 'UTF-8')) ?>"
  data-selected-product="<?= h($productId) ?>"
  tabindex="-1"
>
  <div class="step-card__head">
    <div>
      <p class="step-card__step-name"><?= h($stepName) ?></p>
      <span class="step-card__type" data-session-label><?= $session === 'am' ? 'Buổi sáng' : 'Buổi tối' ?></span>
    </div>
    <?php if ($isOutOfStock): ?>
      <span class="step-card__type">Tạm hết hàng</span>
    <?php endif; ?>
  </div>

  <div class="step-card__safety <?= h($safetyLevel === 'safe' ? '' : 'is-' . $safetyLevel) ?>" data-safety-block>
    <i class="fa-solid <?= $safetyLevel === 'safe' ? 'fa-shield-check' : 'fa-triangle-exclamation' ?>" aria-hidden="true"></i>
    <span><strong>Kiểm tra an toàn:</strong> <?= h($safetyText) ?></span>
  </div>

  <div class="step-card__product">
      <a class="step-card__image-wrap" data-product-link href="<?= h($detailUrl !== '' ? $detailUrl : '#') ?>" aria-label="Xem <?= h($productName) ?>">
      <img
        class="step-card__image"
        data-product-image
        src="<?= h($image) ?>"
        alt="<?= h($productName) ?>"
        loading="lazy"
        referrerpolicy="no-referrer"
        data-fallback-image="<?= h(default_placeholder_image()) ?>"
      >
    </a>
    <div>
      <p class="step-card__brand" data-product-brand <?= $brand === '' ? 'hidden' : '' ?>><?= h($brand) ?></p>
      <h3 class="step-card__product-name"><a href="<?= h($detailUrl !== '' ? $detailUrl : '#') ?>" data-product-link data-product-name><?= h($productName) ?></a></h3>
      <div class="step-card__price-row">
        <strong class="step-card__price" data-product-price><?= h(vnd($price)) ?></strong>
        <del class="step-card__original-price" data-product-original-price <?= $originalPrice > $price ? '' : 'hidden' ?>><?= $originalPrice > $price ? h(vnd($originalPrice)) : '' ?></del>
      </div>
      <?php if ($score !== null || $matchLabel !== ''): ?>
        <div class="step-card__score <?= $score === null ? 'step-card__score--label' : '' ?>" aria-label="Độ phù hợp <?= h($scoreLabel !== '' ? $scoreLabel : $matchLabel) ?>">
          <span>Độ phù hợp</span>
          <?php if ($score !== null): ?>
            <span class="step-card__score-bar"><span class="step-card__score-fill" data-product-score data-score="<?= h((string)$score) ?>"></span></span>
            <strong data-product-score-label><?= h($scoreLabel) ?></strong>
          <?php else: ?>
            <strong data-product-score-label><?= h($matchLabel) ?></strong>
          <?php endif; ?>
        </div>
      <?php endif; ?>
      <div class="step-card__ingredients" data-product-ingredients <?= !$ingredients ? 'hidden' : '' ?>>
        <?php foreach (array_slice($ingredients, 0, 8) as $ingredient): ?><span class="ingredient-chip"><?= h($ingredient) ?></span><?php endforeach; ?>
      </div>
      <p class="step-card__muted-data" data-product-ingredients-empty <?= $ingredients ? 'hidden' : '' ?>>Chưa có dữ liệu hoạt chất trong response.</p>
    </div>
  </div>

  <div class="step-card__actions">
    <?php if ($alternatives): ?>
      <button type="button" class="alternatives-toggle" data-alternatives-toggle aria-expanded="false" aria-controls="alternatives-<?= h($stepKey) ?>">
        <i class="fa-solid fa-arrow-right-arrow-left" aria-hidden="true"></i> Đổi sản phẩm khác
      </button>
    <?php else: ?>
      <button type="button" class="alternatives-toggle" disabled title="Backend chưa trả alternatives">Chưa có lựa chọn thay thế</button>
    <?php endif; ?>
    <?php if ($detailUrl !== ''): ?><a href="<?= h($detailUrl) ?>" data-product-link>Xem chi tiết</a><?php endif; ?>
    <?php if ($productId !== ''): ?>
      <form method="post" action="<?= h(BASE_URL . '/index.php?r=them_gio_hang_ajax') ?>">
        <input type="hidden" name="action" value="add_to_cart">
        <input type="hidden" name="product_id" value="<?= h($productId) ?>" data-product-id-input>
        <input type="hidden" name="ma_san_pham" value="<?= h($productId) ?>" data-product-ma-id-input>
        <input type="hidden" name="quantity" value="1">
        <input type="hidden" name="qty" value="1">
        <button class="step-card__add" type="submit" <?= $isOutOfStock ? 'disabled' : '' ?>>
          <i class="fa-solid fa-bag-shopping" aria-hidden="true"></i> Thêm vào giỏ
        </button>
      </form>
    <?php endif; ?>
  </div>

  <?php if ($alternatives): ?>
    <div class="step-card__alternatives" id="alternatives-<?= h($stepKey) ?>" data-alternatives-panel hidden>
      <p class="step-card__alternatives-title">Chọn sản phẩm khác trong cùng bước</p>
      <div class="alternative-list">
        <?php foreach ($alternatives as $alternative): ?>
          <?php
            $alt = is_array($alternative) ? $alternative : [];
            $altId = trim((string)($alt['id'] ?? $alt['product_id'] ?? ''));
            if ($altId === '') continue;
            $altName = trim((string)($alt['name'] ?? $alt['ten_san_pham'] ?? 'Sản phẩm thay thế'));
            $altPrice = (int)($alt['price'] ?? $alt['gia_ban'] ?? 0);
            $altImage = resolve_image_url((string)($alt['image'] ?? $alt['image_url'] ?? $alt['link_hinh_anh'] ?? ''));
            if ($altImage === '') $altImage = default_placeholder_image();
            $altData = array_merge($alt, ['id' => $altId, 'name' => $altName, 'price' => $altPrice, 'image' => $altImage]);
          ?>
          <button type="button" class="alternative-option" data-alternative data-step-key="<?= h($stepKey) ?>" data-product="<?= h($modalData($altData)) ?>">
            <img src="<?= h($altImage) ?>" alt="" loading="lazy" referrerpolicy="no-referrer">
            <span><strong><?= h($altName) ?></strong><span><?= h(vnd($altPrice)) ?></span></span>
            <span class="alternative-chip">Chọn</span>
          </button>
        <?php endforeach; ?>
      </div>
    </div>
  <?php endif; ?>

  <div class="step-card__why">
    <strong><i class="fa-solid fa-sparkles" aria-hidden="true"></i> Cơ sở gợi ý</strong>
    <span data-product-why><?= h($why) ?></span>
    <?php if ($hasStrongActive): ?><small class="step-card__disclaimer">Gợi ý tự động, không thay thế tư vấn da liễu.</small><?php endif; ?>
  </div>
</article>
