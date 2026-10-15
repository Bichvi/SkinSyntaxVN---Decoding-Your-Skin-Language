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
$safety = is_array($step['safety'] ?? null) ? $step['safety'] : [];
$safetyLevel = in_array(($safety['level'] ?? ''), ['warning', 'unknown'], true) ? $safety['level'] : 'safe';
$safetyText = trim((string)($safety['text'] ?? ''));
if ($safetyText === '') {
    $safetyText = $safetyLevel === 'safe'
        ? 'Đã kiểm tra: chưa ghi nhận cờ an toàn bất thường trong dữ liệu trả về.'
        : 'Chưa đủ dữ liệu thành phần để kết luận an toàn.';
}
$safetyPill = [
    'safe' => ['icon' => 'fa-shield-halved', 'label' => 'Đã kiểm tra thành phần'],
    'unknown' => ['icon' => 'fa-circle-question', 'label' => 'Thiếu dữ liệu thành phần'],
    'warning' => ['icon' => 'fa-triangle-exclamation', 'label' => 'Cần lưu ý'],
][$safetyLevel];
// Lý do gợi ý dạng danh sách ngắn: ưu tiên mảng lý do từ engine, nếu không có thì dùng câu giải thích.
$reasonList = static function (array $item, string $fallback = ''): array {
    $reasons = array_merge(
        is_array($item['reasons'] ?? null) ? $item['reasons'] : [],
        is_array($item['personalization_reasons'] ?? null) ? $item['personalization_reasons'] : []
    );
    $reasons = array_values(array_unique(array_filter(array_map(static fn($reason): string => trim((string)$reason), $reasons))));
    if (!$reasons && $fallback !== '') {
        $reasons = [$fallback];
    }
    return array_slice($reasons, 0, 3);
};
$why = trim((string)($step['why_this_product'] ?? $product['why_this_product'] ?? $product['reason'] ?? ''));
$whyItems = $reasonList($product, $why !== '' ? $why : 'Sản phẩm được chọn từ catalog theo hồ sơ và mục tiêu chăm da hiện có.');
$stepName = trim((string)($step['step_name'] ?? 'Sản phẩm phù hợp'));
$stepOrder = (int)($step['step_order'] ?? 0);
$category = trim((string)($step['category'] ?? ''));
$searchText = trim(implode(' ', array_filter([
    $productName,
    $brand,
    $category,
    implode(' ', $ingredients),
    implode(' ', is_array($product['matched_concerns'] ?? null) ? $product['matched_concerns'] : []),
    implode(' ', $whyItems),
])));
$isOutOfStock = in_array(strtolower(trim((string)($product['stock_status'] ?? ''))), ['out_of_stock', 'hidden', 'inactive'], true)
    || !empty($product['is_out_of_stock']);
$hasStrongActive = (bool)preg_match('/retinol|tretinoin|bha|aha|benzoyl|salicylic|glycolic/i', $searchText);
$modalData = static function (array $item): string {
    return json_encode($item, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?: '{}';
};
// Chuẩn hoá fit_explanation (Tình trạng -> Vấn đề -> Giải pháp). Trả null khi thiếu hoặc không có nội dung
// để view/JS rơi về danh sách lý do cũ (ví dụ cache session cũ chưa có khoá này).
$normalizeFit = static function (mixed $raw): ?array {
    if (!is_array($raw)) {
        return null;
    }
    $text = static fn($value): string => is_scalar($value) ? trim((string)$value) : '';
    $skin = is_array($raw['skin'] ?? null) ? $raw['skin'] : [];
    $concerns = [];
    foreach (is_array($raw['concerns'] ?? null) ? $raw['concerns'] : [] as $concern) {
        if (!is_array($concern) || $text($concern['label'] ?? '') === '') {
            continue;
        }
        $concerns[] = [
            'id' => $text($concern['id'] ?? ''),
            'label' => $text($concern['label']),
            'matched' => ($concern['matched'] ?? false) === true,
            'handled_by' => $text($concern['handled_by'] ?? ''),
        ];
    }
    $goal = null;
    if (is_array($raw['goal'] ?? null) && $text($raw['goal']['label'] ?? '') !== '') {
        $goal = ['label' => $text($raw['goal']['label']), 'matched' => ($raw['goal']['matched'] ?? false) === true];
    }
    $actives = [];
    foreach (is_array($raw['actives'] ?? null) ? $raw['actives'] : [] as $active) {
        if (!is_array($active) || $text($active['name'] ?? '') === '') {
            continue;
        }
        $actives[] = ['name' => $text($active['name']), 'benefit' => $text($active['benefit'] ?? '')];
        if (count($actives) >= 2) {
            break;
        }
    }
    $fit = [
        'skin' => ['label' => $text($skin['label'] ?? ''), 'sensitivity' => $text($skin['sensitivity'] ?? ''), 'note' => $text($skin['note'] ?? '')],
        'concerns' => $concerns,
        'goal' => $goal,
        'solution' => $text($raw['solution'] ?? ''),
        'actives' => $actives,
        'caution' => $text($raw['caution'] ?? ''),
    ];
    $hasContent = $fit['skin']['label'] !== '' || $fit['concerns'] || $fit['solution'] !== '' || $fit['actives'];
    return $hasContent ? $fit : null;
};
$fit = $normalizeFit($product['fit_explanation'] ?? null);
// Dữ liệu tối thiểu để JS đổi qua lại giữa sản phẩm chính và lựa chọn thay thế.
$swapPayload = static function (array $item) use ($reasonList, $normalizeFit): array {
    $id = trim((string)($item['id'] ?? $item['product_id'] ?? ''));
    $image = resolve_image_url((string)($item['image'] ?? $item['image_url'] ?? $item['link_hinh_anh'] ?? ''));
    $scoreRaw = $item['match_score'] ?? $item['match_percent'] ?? $item['score'] ?? null;
    return [
        'id' => $id,
        'name' => trim((string)($item['name'] ?? $item['ten_san_pham'] ?? 'Sản phẩm SkinSyntax')),
        'brand' => trim((string)($item['brand'] ?? $item['thuong_hieu'] ?? '')),
        'price' => (int)($item['price'] ?? $item['gia_ban'] ?? 0),
        'original_price' => (int)($item['original_price'] ?? $item['gia_thi_truong'] ?? 0),
        'image' => $image !== '' ? $image : default_placeholder_image(),
        'match_score' => is_numeric($scoreRaw) ? max(0, min(100, (float)$scoreRaw)) : null,
        'key_ingredients' => array_values(array_filter(array_map('strval', is_array($item['key_ingredients'] ?? null) ? $item['key_ingredients'] : []))),
        'why' => $reasonList($item, trim((string)($item['reason'] ?? ''))),
        'fit' => $normalizeFit($item['fit_explanation'] ?? null),
        'detail_url' => trim((string)($item['detail_url'] ?? '')),
    ];
};
$formatScore = static fn($value): string => is_numeric($value) ? (string)(int)round((float)$value) . '%' : '';
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
  <header class="step-card__head">
    <span class="step-card__index" aria-hidden="true"><?= h((string)$stepOrder) ?></span>
    <div class="step-card__titles">
      <p class="step-card__step-name"><?= h($stepName) ?></p>
      <span class="step-card__type" data-session-label><?= $session === 'am' ? 'Buổi sáng' : 'Buổi tối' ?></span>
    </div>
    <?php if ($isOutOfStock): ?>
      <span class="step-card__pill is-unknown">Tạm hết hàng</span>
    <?php endif; ?>
    <span class="step-card__pill is-<?= h($safetyLevel) ?>" title="<?= h($safetyText) ?>" data-safety-block>
      <i class="fa-solid <?= h($safetyPill['icon']) ?>" aria-hidden="true"></i> <?= h($safetyPill['label']) ?>
    </span>
  </header>

  <?php if ($safetyLevel === 'warning'): ?>
    <div class="step-card__safety is-warning" role="note">
      <i class="fa-solid fa-triangle-exclamation" aria-hidden="true"></i>
      <span><?= h($safetyText) ?></span>
    </div>
  <?php endif; ?>

  <div class="step-card__body">
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
      <div class="step-card__info">
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
          <?php foreach (array_slice($ingredients, 0, 5) as $ingredient): ?><span class="ingredient-chip"><?= h($ingredient) ?></span><?php endforeach; ?>
        </div>
        <p class="step-card__muted-data" data-product-ingredients-empty <?= $ingredients ? 'hidden' : '' ?>>Chưa có dữ liệu hoạt chất.</p>
      </div>
    </div>

    <div class="step-card__why">
      <strong class="step-card__why-title"><i class="fa-solid fa-wand-magic-sparkles" aria-hidden="true"></i> Vì sao Syna chọn cho bạn</strong>
      <div class="fit-explain" data-product-fit <?= $fit === null ? 'hidden' : '' ?>>
        <?php if ($fit !== null): ?>
          <?php if ($fit['skin']['label'] !== ''): ?>
            <div class="fit-explain__row">
              <span class="fit-explain__label">Tình trạng</span>
              <p class="fit-explain__text"><b><?= h($fit['skin']['label']) ?></b><?= $fit['skin']['sensitivity'] !== '' ? h(' · ' . $fit['skin']['sensitivity']) : '' ?><?php if ($fit['skin']['note'] !== ''): ?> <span class="fit-explain__note"><?= h($fit['skin']['note']) ?></span><?php endif; ?></p>
            </div>
          <?php endif; ?>
          <?php if ($fit['concerns']): ?>
            <div class="fit-explain__row">
              <span class="fit-explain__label">Vấn đề</span>
              <ul class="fit-explain__concerns">
                <?php foreach ($fit['concerns'] as $concern): ?>
                  <?php if ($concern['matched']): ?>
                    <li class="is-matched"><i class="fa-solid fa-check" aria-hidden="true"></i> <?= h($concern['label']) ?></li>
                  <?php else: ?>
                    <li class="is-pending" title="Sản phẩm này chưa nhắm trực tiếp vấn đề này"><?= h($concern['label']) ?><?php if ($concern['handled_by'] !== ''): ?><small> · bước <?= h($concern['handled_by']) ?> đảm nhận</small><?php endif; ?></li>
                  <?php endif; ?>
                <?php endforeach; ?>
              </ul>
            </div>
          <?php endif; ?>
          <?php if ($fit['solution'] !== '' || $fit['actives']): ?>
            <div class="fit-explain__row">
              <span class="fit-explain__label">Giải pháp</span>
              <div class="fit-explain__text">
                <?php if ($fit['solution'] !== ''): ?><p><?= h($fit['solution']) ?></p><?php endif; ?>
                <?php if ($fit['actives']): ?>
                  <ul class="fit-explain__actives">
                    <?php foreach ($fit['actives'] as $active): ?><li><b><?= h($active['name']) ?></b><?= $active['benefit'] !== '' ? h(' — ' . $active['benefit']) : '' ?></li><?php endforeach; ?>
                  </ul>
                <?php endif; ?>
              </div>
            </div>
          <?php endif; ?>
          <?php if (is_array($fit['goal']) && $fit['goal']['matched']): ?>
            <p class="fit-explain__goal"><i class="fa-solid fa-bullseye" aria-hidden="true"></i> Đúng mục tiêu: <?= h($fit['goal']['label']) ?></p>
          <?php endif; ?>
          <?php if ($fit['caution'] !== ''): ?>
            <p class="fit-explain__caution"><i class="fa-solid fa-circle-info" aria-hidden="true"></i> <?= h($fit['caution']) ?></p>
          <?php endif; ?>
        <?php endif; ?>
      </div>
      <ul data-product-why <?= $fit !== null ? 'hidden' : '' ?>>
        <?php foreach ($whyItems as $item): ?><li><?= h($item) ?></li><?php endforeach; ?>
      </ul>
      <?php if ($hasStrongActive): ?><small class="step-card__disclaimer">Gợi ý tự động, không thay thế tư vấn da liễu.</small><?php endif; ?>
      <div class="step-card__actions">
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
    </div>
  </div>

  <?php if ($alternatives): ?>
    <div class="step-card__alternatives" id="alternatives-<?= h($stepKey) ?>" data-alternatives-panel>
      <p class="step-card__alternatives-title">
        <i class="fa-solid fa-arrow-right-arrow-left" aria-hidden="true"></i>
        <?= h((string)count($alternatives)) ?> lựa chọn khác cho bước này <span>· chạm để đổi</span>
      </p>
      <div class="alternative-list">
        <?php foreach ($alternatives as $alternative): ?>
          <?php
            $altData = $swapPayload(is_array($alternative) ? $alternative : []);
            if ($altData['id'] === '') continue;
          ?>
          <button type="button" class="alternative-option" data-alternative data-step-key="<?= h($stepKey) ?>" data-product="<?= h($modalData($altData)) ?>" aria-label="Đổi sang <?= h($altData['name']) ?>">
            <img src="<?= h($altData['image']) ?>" alt="" loading="lazy" referrerpolicy="no-referrer" data-alternative-image>
            <span class="alternative-option__body">
              <strong data-alternative-name><?= h($altData['name']) ?></strong>
              <span class="alternative-option__meta">
                <span data-alternative-price><?= h(vnd($altData['price'])) ?></span>
                <span class="alternative-option__score" data-alternative-score <?= $altData['match_score'] === null ? 'hidden' : '' ?>><?= h($formatScore($altData['match_score'])) ?></span>
              </span>
            </span>
          </button>
        <?php endforeach; ?>
      </div>
    </div>
  <?php endif; ?>
  <script type="application/json" data-current-product><?= $modalData($swapPayload($product)) ?></script>
</article>
