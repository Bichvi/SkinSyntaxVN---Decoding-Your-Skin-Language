<?php
// Khối 1 "Khám phá nhanh" dưới hero: từ khóa mọi người đang tìm + tìm theo hoạt chất.
// Biến vào: $homeDiscovery = ['trending' => [string], 'actives' => [['q','name','desc']]]. Rỗng -> ẩn khối.
$homeDiscovery = isset($homeDiscovery) && is_array($homeDiscovery) ? $homeDiscovery : [];
$discTrending = array_values(array_filter((array)($homeDiscovery['trending'] ?? []), 'is_string'));
$discActives = array_values(array_filter((array)($homeDiscovery['actives'] ?? []), 'is_array'));
$discSearchUrl = static fn(string $q): string => BASE_URL . '/index.php?r=tatca&q=' . urlencode($q);
if (!$discTrending && !$discActives) {
    return;
}
?>
<section class="home-discovery mb-5 mt-4 p-3 p-md-4 bg-white border" style="border-radius: 16px; border-color: #E2E8F0 !important;" aria-label="Khám phá nhanh">
  <?php if ($discTrending): ?>
    <div class="mb-4">
      <h2 class="fw-bold m-0 mb-2" style="color: #0F172A; font-size: 1.05rem;">
        <i class="fas fa-fire me-2" style="color: #D9480F;" aria-hidden="true"></i>Mọi người đang tìm
      </h2>
      <div class="home-discovery__chips d-flex flex-wrap gap-2">
        <?php foreach ($discTrending as $term): ?>
          <a href="<?= h($discSearchUrl($term)) ?>" class="home-discovery__chip"><?= h($term) ?></a>
        <?php endforeach; ?>
      </div>
    </div>
  <?php endif; ?>

  <?php if ($discActives): ?>
    <div>
      <h2 class="fw-bold m-0 mb-2" style="color: #0F172A; font-size: 1.05rem;">
        <i class="fas fa-flask me-2" style="color: #183B2B;" aria-hidden="true"></i>Tìm theo hoạt chất
      </h2>
      <div class="row g-2">
        <?php foreach ($discActives as $a): ?>
          <div class="col-6 col-md-3">
            <a href="<?= h($discSearchUrl((string)($a['q'] ?? $a['name'] ?? ''))) ?>" class="home-discovery__active d-block h-100 text-decoration-none">
              <span class="d-block fw-bold" style="color: #183B2B; font-size: 0.9rem;"><?= h((string)($a['name'] ?? '')) ?></span>
              <span class="d-block text-muted" style="font-size: 0.76rem; line-height: 1.35;"><?= h((string)($a['desc'] ?? '')) ?></span>
            </a>
          </div>
        <?php endforeach; ?>
      </div>
    </div>
  <?php endif; ?>
</section>
<style>
.home-discovery__chip {
  display: inline-block; padding: 5px 14px; border: 1px solid #CBD5E1; border-radius: 999px;
  background: #fff; color: #183B2B; font-size: 0.82rem; font-weight: 600; text-decoration: none;
  transition: background .15s ease, border-color .15s ease; max-width: 100%; overflow-wrap: anywhere;
}
.home-discovery__chip:hover, .home-discovery__chip:focus-visible { background: #EBF2EE; border-color: #183B2B; color: #183B2B; }
.home-discovery__active {
  padding: 10px 12px; border: 1px solid #E2E8F0; border-radius: 12px; background: #F8FAF8;
  transition: border-color .15s ease, box-shadow .15s ease;
}
.home-discovery__active:hover, .home-discovery__active:focus-visible { border-color: #183B2B; box-shadow: 0 2px 8px rgba(24, 59, 43, .12); }
</style>
