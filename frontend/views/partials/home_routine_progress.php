<?php
// Khối "Hoàn thiện routine của bạn" cho thành viên đã khảo sát.
// Biến vào: $routineProgress (list [step,label,status,source,product]), $renderHomeProductCard (closure thẻ sản phẩm).
$rpItems = isset($routineProgress) && is_array($routineProgress) ? array_values($routineProgress) : [];
$rpOwnedCount = 0;
foreach ($rpItems as $rpItem) {
    if (($rpItem['status'] ?? '') === 'owned') {
        $rpOwnedCount++;
    }
}
$rpTotal = max(count($rpItems), 1);
$rpPercent = (int)round($rpOwnedCount / $rpTotal * 100);
$rpSearchByStep = [
    'lam_sach' => 'l%C3%A0m+s%E1%BA%A1ch',
    'dac_tri' => 'serum',
    'duong_am' => 'd%C6%B0%E1%BB%A1ng+%E1%BA%A9m',
    'chong_nang' => 'ch%E1%BB%91ng+n%E1%BA%AFng',
];
?>
<section id="home-routine-progress" class="mb-5 p-4 bg-white border" style="border-radius: 16px; border-color: #E2E8F0 !important;" aria-label="Tiến độ routine của bạn">
  <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-2 mb-3">
    <div>
      <span class="text-uppercase fw-semibold small" style="color: #183B2B; letter-spacing: 0.05em; font-size: 0.72rem;">ROUTINE CỦA BẠN</span>
      <h2 class="fw-bold m-0" style="color: #0F172A; font-size: 1.4rem;">Hoàn thiện routine của bạn</h2>
      <span class="text-muted small" style="font-size: 0.82rem;">Bạn đã có <?= h((string)$rpOwnedCount) ?>/<?= h((string)$rpTotal) ?> bước</span>
    </div>
    <a href="<?= BASE_URL ?>/index.php?r=goiy" class="btn btn-sm text-white fw-semibold px-3 py-1.5" style="background: #183B2B; border-radius: 6px; font-size: 0.8rem;">
      Xem routine đầy đủ &rarr;
    </a>
  </div>

  <div class="home-routine-progress__bar" role="progressbar" aria-valuemin="0" aria-valuemax="<?= h((string)$rpTotal) ?>" aria-valuenow="<?= h((string)$rpOwnedCount) ?>" aria-label="Số bước routine đã có">
    <span style="width: <?= h((string)$rpPercent) ?>%;"></span>
  </div>

  <?php if ($rpOwnedCount === $rpTotal): ?>
    <div class="home-routine-progress__done">
      <i class="fas fa-circle-check" aria-hidden="true"></i>
      <span class="fw-bold">Routine đủ 4 bước rồi!</span>
    </div>
  <?php endif; ?>

  <div class="row g-3 mt-1">
    <?php foreach ($rpItems as $i => $rpItem): ?>
      <?php
        $rpNo = $i + 1;
        $rpProduct = is_array($rpItem['product'] ?? null) ? $rpItem['product'] : null;
        $rpOwned = ($rpItem['status'] ?? '') === 'owned';
      ?>
      <div class="col-6 col-md-3">
        <div class="d-flex flex-column h-100" data-rec-section="routine-progress">
          <div class="mb-2 d-flex align-items-center gap-2">
            <span class="routine-step-num" style="flex: 0 0 auto;"><?= h(sprintf('%02d', $rpNo)) ?></span>
            <span class="text-uppercase fw-bold" style="font-size: 0.7rem; color: #183B2B; letter-spacing: 0.03em;">BƯỚC <?= h((string)$rpNo) ?> · <?= h((string)($rpItem['label'] ?? '')) ?></span>
          </div>
          <div class="flex-grow-1">
            <?php if ($rpOwned && $rpProduct !== null): ?>
              <?php
                $rpPid = (string)($rpProduct['id'] ?? $rpProduct['ma_san_pham'] ?? '');
                $rpImg = resolve_image_url(function_exists('product_image_source') ? product_image_source($rpProduct) : (string)($rpProduct['link_hinh_anh'] ?? $rpProduct['hinh_anh'] ?? ''));
                $rpName = (string)($rpProduct['ten_san_pham'] ?? 'Sản phẩm SkinSyntax');
                $rpSource = ($rpItem['source'] ?? '') === 'cart' ? 'Trong giỏ' : 'Đã mua';
              ?>
              <div class="home-routine-progress__owned">
                <div class="d-flex align-items-center gap-2 mb-2">
                  <i class="fas fa-circle-check" style="color: #2D6A4F;" aria-hidden="true"></i>
                  <span class="fw-bold" style="font-size: 0.85rem; color: #183B2B;">Đã có</span>
                  <span class="home-routine-progress__pill"><?= h($rpSource) ?></span>
                </div>
                <a href="<?= BASE_URL ?>/index.php?r=chitiet&id=<?= h($rpPid) ?>" class="d-flex align-items-center gap-2 text-decoration-none">
                  <img src="<?= h($rpImg ?: default_placeholder_image()) ?>" alt="<?= h($rpName) ?>" loading="lazy" decoding="async" referrerpolicy="no-referrer" onerror="this.onerror=null;this.src='<?= h(default_placeholder_image()) ?>'" style="width: 52px; height: 52px; object-fit: cover; border-radius: 8px; background: #F8FAF8; flex: 0 0 auto;">
                  <span class="home-routine-progress__name"><?= h($rpName) ?></span>
                </a>
              </div>
            <?php elseif (!$rpOwned && $rpProduct !== null): ?>
              <?php $renderHomeProductCard($rpProduct, 'Còn thiếu', HomeCardReason::for($rpProduct), 'Vì sao gợi ý'); ?>
            <?php else: ?>
              <a href="<?= BASE_URL ?>/index.php?r=tatca&q=<?= h($rpSearchByStep[$rpItem['step'] ?? ''] ?? '') ?>" class="routine-step-pill text-decoration-none">
                <div>
                  <div class="text-uppercase text-muted fw-bold" style="font-size: 0.65rem;">Còn thiếu</div>
                  <div class="fw-bold text-dark" style="font-size: 0.9rem;">Tìm sản phẩm <?= h(function_exists('mb_strtolower') ? mb_strtolower((string)($rpItem['label'] ?? ''), 'UTF-8') : (string)($rpItem['label'] ?? '')) ?></div>
                </div>
              </a>
            <?php endif; ?>
          </div>
        </div>
      </div>
    <?php endforeach; ?>
  </div>
</section>
