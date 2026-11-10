<?php
// Thẻ sản phẩm trang chủ (chuyển nguyên văn từ home.php); require trả về closure.
return static function (array $p, string $tag = '', string $whyFitText = '', string $whyLabel = 'Vì sao phù hợp'): void {
    $productId = (string)($p['id'] ?? $p['ma_san_pham'] ?? '');
    $img = resolve_image_url((string)($p['link_hinh_anh'] ?? $p['hinh_anh'] ?? ''));
    $giaBan = (string)($p['gia_ban'] ?? '');
    $giaThiTruong = trim((string)($p['gia_thi_truong'] ?? ''));
    $phanTramGiam = function_exists('product_discount_percent') ? product_discount_percent($p) : null;
    $rawRating = isset($p['diem_danh_gia']) && is_numeric($p['diem_danh_gia']) ? (float)$p['diem_danh_gia'] : 0.0;
    $rawReviewCount = isset($p['so_luong_danh_gia']) && is_numeric($p['so_luong_danh_gia']) ? (int)$p['so_luong_danh_gia'] : 0;
    $hasRealReview = ($rawRating > 0 && $rawReviewCount > 0);
    $isOutOfStock = function_exists('product_is_out_of_stock') ? product_is_out_of_stock($p) : false;

    // Grounded recommendation badge from recommender_meta
    $meta = $p['recommender_meta'] ?? null;
    $algoMode = strtoupper((string)($meta['algorithm_mode'] ?? ''));
    $dominantSignal = strtoupper((string)($meta['dominant_signal'] ?? ''));
    $isPersonalized = !empty($meta) && $algoMode !== 'SIMPLE' && $dominantSignal !== 'SIMPLE';
    $reasonTags = (array)($meta['reason_tags'] ?? []);
    ?>
    <div class="product-card product-card--editorial h-100 d-flex flex-column">
      <div class="product-thumb position-relative p-2" style="background: #F8FAF8; border-radius: 12px 12px 0 0;">
        <?php if ($phanTramGiam !== null && $phanTramGiam > 0): ?>
          <span class="badge-sale position-absolute" style="top: 10px; left: 10px; background: #183B2B; color: #FFFFFF; font-weight: 700; font-size: 0.72rem; padding: 3px 8px; border-radius: 4px; z-index: 3;">
            -<?= h((string)$phanTramGiam) ?>%
          </span>
        <?php endif; ?>

        <?php if ($tag !== ''): ?>
          <span class="market-card__tag position-absolute" style="top: 10px; right: 10px; background: #F1F5F9; color: #475569; font-size: 0.7rem; font-weight: 600; padding: 3px 8px; border-radius: 4px; z-index: 3; border: 1px solid #E2E8F0;">
            <?= h($tag) ?>
          </span>
        <?php endif; ?>

        <a href="<?= BASE_URL ?>/index.php?r=chitiet&id=<?= h($productId) ?>" class="d-block w-100 overflow-hidden" style="border-radius: 8px; aspect-ratio: 1/1;">
          <img class="product-card-img" loading="lazy" decoding="async" src="<?= h($img ?: default_placeholder_image()) ?>" referrerpolicy="no-referrer" onerror="this.onerror=null;this.src='<?= default_placeholder_image() ?>';" alt="<?= h(!empty($p['ten_san_pham']) ? $p['ten_san_pham'] : 'Sản phẩm SkinSyntax') ?>" style="width: 100%; height: 100%; object-fit: cover; transition: transform 0.3s ease;">
        </a>
      </div>

      <div class="product-meta p-3 d-flex flex-column flex-grow-1">
        <div class="mb-2 d-flex align-items-center justify-content-between">
          <span class="brand text-uppercase" style="font-size: 0.68rem; color: #183B2B; background: #EBF2EE; padding: 2px 7px; border-radius: 4px; letter-spacing: 0.04em; font-weight: 700;">
            <?= h($p['thuong_hieu'] ?? 'SkinSyntax') ?>
          </span>

          <?php if ($hasRealReview): ?>
            <span class="pc-rating" aria-label="Đánh giá <?= number_format($rawRating, 1) ?> trên 5, <?= $rawReviewCount ?> lượt"><i class="fas fa-star" aria-hidden="true"></i> <?= number_format($rawRating, 1) ?><span class="pc-rating__count">· <?= $rawReviewCount ?></span></span>
          <?php elseif ($isPersonalized && (in_array('Khớp loại da', $reasonTags, true) || in_array('Phù hợp mọi loại da', $reasonTags, true) || in_array('Trong ngân sách', $reasonTags, true))): ?>
            <span class="skin-match-status" style="font-size: 0.72rem; color: var(--muted);">
              <?php if (in_array('Khớp loại da', $reasonTags, true)): ?>
                <span class="text-success fw-semibold"><i class="bi bi-check2-circle"></i> Khớp loại da</span>
              <?php elseif (in_array('Phù hợp mọi loại da', $reasonTags, true)): ?>
                <span class="text-success fw-semibold"><i class="bi bi-check2-circle"></i> Mọi loại da</span>
              <?php else: ?>
                <span class="text-success fw-semibold"><i class="bi bi-tag"></i> Trong ngân sách</span>
              <?php endif; ?>
            </span>
          <?php else: ?>
            <span class="pc-rating pc-rating--none">Chưa có đánh giá</span>
          <?php endif; ?>
        </div>

        <a class="name fw-semibold mb-2 text-decoration-none" href="<?= BASE_URL ?>/index.php?r=chitiet&id=<?= h($productId) ?>" style="color: #0F172A; font-size: 0.88rem; line-height: 1.35; display: -webkit-box; -webkit-line-clamp: 2; line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden; min-height: 2.4em;">
          <?= h($p['ten_san_pham'] ?? '') ?>
        </a>

        <?php if ($whyFitText !== ''): ?>
          <?php
            // Chi tiết lý do (chỉ khối "Vì bạn đã xem" có): thành phần chung, công dụng, 1 dòng giá trị.
            $wfDetail = is_array($p['reason_detail'] ?? null) ? $p['reason_detail'] : [];
            $wfShared = array_values(array_filter((array)($wfDetail['shared'] ?? []), 'is_string'));
            $wfSame = array_values(array_filter((array)($wfDetail['same_benefits'] ?? []), 'is_string'));
            $wfExtra = array_values(array_filter((array)($wfDetail['extra_benefits'] ?? []), 'is_string'));
            $wfValue = is_array($wfDetail['value'] ?? null) ? $wfDetail['value'] : null;
            $wfIcons = ['cheaper' => 'bi-cash-coin', 'similar' => 'bi-cash-coin', 'per_unit' => 'bi-box-seam', 'discount' => 'bi-tag-fill', 'rating' => 'bi-star-fill'];
            $wfValueIcon = $wfValue !== null ? ($wfIcons[(string)($wfValue['kind'] ?? '')] ?? null) : null;
            if ($wfValueIcon === null || trim((string)($wfValue['text'] ?? '')) === '') {
                $wfValue = null;
            }
            $wfHasDetail = $wfShared || $wfSame || $wfExtra || $wfValue !== null;
            // Khối "Hợp với da…" / "Hợp với vấn đề…": chi tiết từ dữ liệu SP thật (fit_detail), cùng điều kiện khớp với câu đang hiện.
            $wfFit = (is_array($p['fit_detail'] ?? null) && ($p['reason_text'] ?? null) === $whyFitText) ? $p['fit_detail'] : [];
            $wfFitList = static fn(string $k): array => array_values(array_filter((array)($wfFit[$k] ?? []), static fn($x) => is_string($x) && trim($x) !== ''));
            $wfFitActives = $wfFitList('actives');
            $wfFitSkin = $wfFitList('skin_types');
            $wfFitBenefits = $wfFitList('benefits');
            $wfFitConcerns = $wfFitList('concerns');
            // Chỉ mở popup khi có thêm thông tin so với dòng đang hiện (tránh popup lặp lại).
            $wfHasDetail = $wfHasDetail || $wfFitSkin || $wfFitBenefits || $wfFitConcerns || count($wfFitActives) > 2;
            // Câu ngắn + nhãn nổi bật (chỉ khi đúng là lý do khối "Vì bạn đã xem" đang hiển thị).
            $wfCopy = (is_array($p['why_fit'] ?? null) && ($p['reason_text'] ?? null) === $whyFitText && trim((string)($p['why_fit']['headline'] ?? '')) !== '') ? $p['why_fit'] : null;
            $wfTags = [];
            foreach ($wfCopy !== null ? (array)($wfCopy['tags'] ?? []) : [] as $t) {
                if (is_array($t) && trim((string)($t['text'] ?? '')) !== '') {
                    $wfTags[] = $t;
                }
            }
            $wfTagTexts = array_map(static fn($t) => (string)$t['text'], array_filter($wfTags, static fn($t) => ($t['kind'] ?? '') === 'extra'));
            $wfShowValueLine = $wfValue !== null && !array_filter($wfTags, static fn($t) => ($t['kind'] ?? '') === 'value');
            $wfShowExtraLine = (bool)$wfExtra;
            if ($wfExtra && $wfTagTexts) {
                $wfShowExtraLine = (bool)array_filter($wfExtra, static fn($b) => !in_array('+ ' . $b, $wfTagTexts, true));
            }
            // Popover hiện câu đầy đủ (không cắt tên SP gốc) khi đó đúng là lý do đang hiển thị.
            $wfFullText = (($p['reason_text'] ?? null) === $whyFitText && is_string($p['reason_text_full'] ?? null) && trim($p['reason_text_full']) !== '')
                ? $p['reason_text_full'] : $whyFitText;
          ?>
          <?php
            $wfShownText = $wfCopy !== null ? (string)$wfCopy['headline'] : $whyFitText;
            // Chip công dụng: khối "đã xem" đã có tag công dụng riêng nên bỏ; còn lại chừa chỗ cho tối đa 3 nhãn.
            $isSeenCard = !empty($p['reason_detail']);
            $bcBudget = $isSeenCard ? 0 : max(0, min(2, 3 - count($wfTags)));
            $bc = class_exists('HomeBenefitChips') ? HomeBenefitChips::for($p, $wfShownText, $bcBudget) : ['shown' => [], 'more' => 0];
            if ($isSeenCard) {
                $bc['more'] = 0;
            }
          ?>
          <?php // Chỉ có popup khi có chi tiết thật để xem; không thì hộp chỉ để đọc (tránh popup lặp lại đúng câu đang hiện). ?>
          <div class="why-fit<?= $wfHasDetail ? ' why-fit--has-detail' : '' ?>"<?php if ($wfHasDetail): ?> tabindex="0" role="button" aria-expanded="false" aria-label="Xem chi tiết lý do gợi ý"<?php endif; ?>>
            <span class="why-fit__label visually-hidden"><i class="bi bi-lightbulb-fill" aria-hidden="true"></i><?= h($whyLabel) ?></span>
            <?php if ($wfHasDetail): ?><i class="bi bi-chevron-up why-fit__more" aria-hidden="true"></i><?php endif; ?>
            <span class="why-fit__text"<?= $wfHasDetail ? ' aria-hidden="true"' : '' ?> title="<?= h($wfShownText) ?>"><i class="bi bi-stars why-fit__icon" aria-hidden="true"></i><?= h($wfShownText) ?></span>
            <?php if ($wfTags || $bc['shown'] || $bc['more'] > 0): ?>
              <span class="why-fit__tags"<?= $wfHasDetail ? ' aria-hidden="true"' : '' ?>>
                <?php foreach ($wfTags as $t): ?><span class="why-fit__tag why-fit__tag--<?= h((string)($t['kind'] ?? 'extra')) ?>"><?php if (trim((string)($t['icon'] ?? '')) !== ''): ?><i class="bi <?= h((string)$t['icon']) ?>" aria-hidden="true"></i><?php endif; ?><?= h((string)$t['text']) ?></span><?php endforeach; ?>
                <?php foreach ($bc['shown'] as $bn): ?><span class="why-fit__tag why-fit__tag--benefit"><?= h($bn) ?></span><?php endforeach; ?>
                <?php if ($bc['more'] > 0): ?><span class="why-fit__tag why-fit__tag--more">+<?= (int)$bc['more'] ?></span><?php endif; ?>
              </span>
            <?php endif; ?>
            <?php if ($wfHasDetail): ?>
            <div class="why-fit__pop">
              <span class="why-fit__label"><i class="bi bi-lightbulb-fill" aria-hidden="true"></i><?= h($whyLabel) ?></span>
              <span class="why-fit__full"><?= h($wfCopy !== null ? $wfShownText : $wfFullText) ?></span>
              <?php if ($wfTags): // popup che kín hộp gốc nên lặp lại hàng nhãn, không để mất thông tin khi mở ?>
                <span class="why-fit__tags">
                  <?php foreach ($wfTags as $t): ?><span class="why-fit__tag why-fit__tag--<?= h((string)($t['kind'] ?? 'extra')) ?>"><?php if (trim((string)($t['icon'] ?? '')) !== ''): ?><i class="bi <?= h((string)$t['icon']) ?>" aria-hidden="true"></i><?php endif; ?><?= h((string)$t['text']) ?></span><?php endforeach; ?>
                </span>
              <?php endif; ?>
              <?php if ($wfHasDetail): ?>
                <div class="why-fit__detail">
                  <?php if ($wfFitActives): ?>
                    <div class="why-fit__sec">
                      <span class="why-fit__title">Thành phần nổi bật</span>
                      <span class="why-fit__chips">
                        <?php foreach ($wfFitActives as $act): ?><span class="why-fit__chip"><?= h($act) ?></span><?php endforeach; ?>
                      </span>
                    </div>
                  <?php endif; ?>
                  <?php if ($wfFitSkin): ?>
                    <div class="why-fit__line">Hợp loại da: <?= h(implode(', ', $wfFitSkin)) ?></div>
                  <?php endif; ?>
                  <?php if ($wfFitBenefits): ?>
                    <div class="why-fit__line">Công dụng: <?= h(implode(', ', $wfFitBenefits)) ?></div>
                  <?php endif; ?>
                  <?php if ($wfFitConcerns): ?>
                    <div class="why-fit__line">Hỗ trợ vấn đề: <?= h(implode(', ', $wfFitConcerns)) ?></div>
                  <?php endif; ?>
                  <?php if ($wfShared): ?>
                    <div class="why-fit__sec">
                      <span class="why-fit__title">Thành phần giống nhau</span>
                      <span class="why-fit__chips">
                        <?php foreach ($wfShared as $ing): ?><span class="why-fit__chip"><?= h($ing) ?></span><?php endforeach; ?>
                      </span>
                    </div>
                  <?php endif; ?>
                  <?php if ($wfSame): ?>
                    <div class="why-fit__line">Cùng công dụng: <?= h(implode(', ', $wfSame)) ?></div>
                  <?php endif; ?>
                  <?php if ($wfShowExtraLine): ?>
                    <div class="why-fit__line">Có thêm: <?= h(implode(', ', $wfExtra)) ?></div>
                  <?php endif; ?>
                  <?php if ($wfShowValueLine): ?>
                    <div class="why-fit__line why-fit__value"><i class="bi <?= h($wfValueIcon) ?>" aria-hidden="true"></i><?= h((string)$wfValue['text']) ?></div>
                  <?php endif; ?>
                </div>
              <?php endif; ?>
            </div>
            <?php endif; ?>
          </div>
        <?php endif; ?>

        <?php if ($whyFitText === ''): ?>
          <?php $bc = class_exists('HomeBenefitChips') ? HomeBenefitChips::for($p, '', 3) : ['shown' => [], 'more' => 0]; ?>
          <?php if ($bc['shown']): ?>
            <div class="pc-benefits">
              <?php foreach ($bc['shown'] as $bn): ?><span class="why-fit__tag why-fit__tag--benefit"><?= h($bn) ?></span><?php endforeach; ?>
              <?php if ($bc['more'] > 0): ?><span class="why-fit__tag why-fit__tag--more">+<?= (int)$bc['more'] ?></span><?php endif; ?>
            </div>
          <?php endif; ?>
        <?php endif; ?>

        <?php // Giá bán + "Đã bán" chung một hàng; giá gốc gạch ngang xuống dòng dưới để hàng không bị tràn ở thẻ hẹp. ?>
        <div class="price-wrap pc-price mb-3 mt-auto">
          <div class="price fw-bold" style="color: #183B2B; font-size: 1.05rem; font-variant-numeric: tabular-nums;"><?= vnd($giaBan) ?></div>
          <?php
            // Đã bán: <1000 giữ nguyên số, từ 1000 rút gọn dạng 1,2K.
            // Đã bán: <1000 giữ nguyên số, từ 1000 rút gọn dạng 1,2K.
            $soldN = (int)($p['so_luong_da_ban'] ?? 0);
            $soldLabel = '';
            if ($soldN >= 100) {
                if ($soldN < 1000) {
                    $soldLabel = (string)$soldN;
                } else {
                    $soldLabel = number_format($soldN / 1000, 1, ',', '');
                    if (str_ends_with($soldLabel, ',0')) {
                        $soldLabel = substr($soldLabel, 0, -2);
                    }
                    $soldLabel .= 'K';
                }
            }
          ?>
          <?php if ($soldN >= 100): ?><span class="pc-sold">Đã bán <?= h($soldLabel) ?></span><?php endif; ?>
          <?php if ($giaThiTruong !== '' && is_numeric($giaThiTruong) && (float)$giaThiTruong > (float)$giaBan): ?>
            <div class="price-market text-decoration-line-through"><?= vnd($giaThiTruong) ?></div>
          <?php endif; ?>
        </div>

        <div class="product-card-actions d-grid gap-2" style="grid-template-columns: 40px 1fr;">
          <form method="post" action="<?= BASE_URL ?>/index.php?r=them_gio_hang_ajax" class="m-0 w-100">
            <input type="hidden" name="action" value="add_to_cart">
            <input type="hidden" name="product_id" value="<?= h($productId) ?>">
            <input type="hidden" name="ma_san_pham" value="<?= h($productId) ?>">
            <input type="hidden" name="quantity" value="1">
            <input type="hidden" name="qty" value="1">
            <button class="btn btn-sm w-100 text-nowrap d-inline-flex align-items-center justify-content-center" type="submit" style="background: #F1F5F9; color: #183B2B; border: 1px solid #CBD5E1; border-radius: 8px; font-weight: 600; font-size: 0.78rem; height: 38px; padding: 0; transition: all 0.2s ease;" <?= $isOutOfStock ? 'disabled' : '' ?> title="Thêm vào giỏ" aria-label="Thêm vào giỏ">
              <i class="fa-solid fa-cart-plus" aria-hidden="true"></i>
            </button>
          </form>
          <form method="post" action="<?= BASE_URL ?>/index.php?r=them_gio_hang_ajax" class="m-0 w-100">
            <input type="hidden" name="action" value="add_to_cart">
            <input type="hidden" name="buy_now" value="1">
            <input type="hidden" name="product_id" value="<?= h($productId) ?>">
            <input type="hidden" name="ma_san_pham" value="<?= h($productId) ?>">
            <input type="hidden" name="quantity" value="1">
            <input type="hidden" name="qty" value="1">
            <button class="btn btn-sm w-100 text-white text-nowrap d-inline-flex align-items-center justify-content-center" type="submit" style="background: #183B2B; border-radius: 8px; font-weight: 600; font-size: 0.78rem; height: 38px; padding: 0 4px; border: none; transition: background 0.2s ease;" <?= $isOutOfStock ? 'disabled' : '' ?> title="Mua ngay">
              <?= $isOutOfStock ? 'Hết hàng' : '<span>Mua ngay</span>' ?>
            </button>
          </form>
        </div>
      </div>
    </div>
    <?php
};
