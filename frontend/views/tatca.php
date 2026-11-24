<?php
// backend/app/views/tatca.php
$total = $total ?? 0;
$perPage = $perPage ?? 24;
$page = $page ?? 1;
$q = $q ?? '';
$cap1 = $cap1 ?? '';
$cap2 = $cap2 ?? '';
$sort = $sort ?? ($_GET['sort'] ?? 'default');
$items = $items ?? [];
$pageTitle = trim((string)($pageTitle ?? 'Tất cả sản phẩm'));
$dbUnavailableMessage = trim((string)($dbUnavailableMessage ?? ''));
$listRoute = trim((string)($listRoute ?? 'tatca'));
$listType = trim((string)($listType ?? ''));
$didYouMean = is_array($didYouMean ?? null) ? $didYouMean : null; // U05: ['mode' => 'auto'|'suggest', 'original', 'corrected']
$exact = !empty($exact);
$pageQ = (string)($qEffective ?? $q); // phân trang dùng từ khóa đã sửa (nếu tự sửa) để trang 2+ không rỗng
$paginationBase = BASE_URL . '/index.php?r=' . rawurlencode($listRoute);
if ($listType !== '') {
  $paginationBase .= '&type=' . urlencode($listType);
}
if (!empty($sort) && $sort !== 'default') {
  $paginationBase .= '&sort=' . urlencode($sort);
}
if ($exact) {
  $paginationBase .= '&exact=1';
}
// Bộ lọc nâng cao: chỉ trang tatca (danhsach giữ nguyên)
$hasCatFilters = $listRoute === 'tatca';
$filters = is_array($filters ?? null) ? $filters : [];
$facets = is_array($facets ?? null) ? $facets : null; // null = chưa có facet -> ẩn khối lọc nâng cao
$fBrands = array_values(array_filter(array_map('strval', (array)($filters['thuong_hieu'] ?? [])), static fn($v) => $v !== ''));
$fSkins = array_values(array_filter(array_map('strval', (array)($filters['loai_da'] ?? [])), static fn($v) => $v !== ''));
$fLoai = array_values(array_unique(array_map('intval', (array)($filters['loai'] ?? []))));
$fPriceMin = trim((string)($filters['gia_tu'] ?? ''));
$fPriceMax = trim((string)($filters['gia_den'] ?? ''));
$fRating = trim((string)($filters['danh_gia'] ?? '')) === '4' ? '4' : '';
$fSale = trim((string)($filters['giam_gia'] ?? '')) === '1' ? '1' : '';
$fStock = trim((string)($filters['con_hang'] ?? '')) === '1' ? '1' : '';
$showFilterPanel = $hasCatFilters && $facets !== null;
$skinOptions = ['dau' => 'Da dầu', 'kho' => 'Da khô', 'nhay_cam' => 'Da nhạy cảm', 'mun' => 'Da mụn'];
$pricePresets = [
  ['key' => 'lt200', 'label' => 'Dưới 200k', 'min' => '0', 'max' => '199999'],
  ['key' => '200_500', 'label' => '200k - 500k', 'min' => '200000', 'max' => '499999'],
  ['key' => '500_1000', 'label' => '500k - 1tr', 'min' => '500000', 'max' => '999999'],
  ['key' => 'gt1000', 'label' => 'Trên 1tr', 'min' => '1000000', 'max' => ''],
];
$toggleOptions = [
  ['name' => 'giam_gia', 'value' => '1', 'label' => 'Đang giảm giá', 'facet' => 'on_sale', 'on' => $fSale === '1'],
  ['name' => 'con_hang', 'value' => '1', 'label' => 'Còn hàng', 'facet' => 'in_stock', 'on' => $fStock === '1'],
  ['name' => 'danh_gia', 'value' => '4', 'label' => 'Từ 4★', 'facet' => 'rating4', 'on' => $fRating === '4'],
];

// --- Danh mục: cây 1 cấp nhóm cha -> lá (checkbox, đa chọn, tri-state ở nhóm) ---
$lc = static fn(string $v): string => function_exists('mb_strtolower') ? mb_strtolower(trim($v), 'UTF-8') : strtolower(trim($v));
$facetLeaves = $showFilterPanel ? array_values((array)($facets['categories'] ?? [])) : [];
$leafById = [];
$totalLeafCnt = 0;
foreach ($facetLeaves as $fl) { $leafById[(int)$fl['id']] = $fl; $totalLeafCnt += (int)$fl['count']; }
// Gợi ý từ khóa: có q, chưa chọn danh mục -> đánh dấu lá chiếm >= 25% kết quả (không tự áp lọc)
$kwHint = $showFilterPanel && $pageQ !== '' && empty($fLoai) && $cap1 === '' && $cap2 === '' && $totalLeafCnt > 0;
$kwLeaf = [];
if ($kwHint) {
  foreach ($facetLeaves as $fl) {
    if ((int)$fl['count'] > 0 && (int)$fl['count'] * 4 >= $totalLeafCnt) $kwLeaf[(int)$fl['id']] = true;
  }
}
// Nhóm theo cha trực tiếp; nhóm/lá khớp từ khóa lên đầu, còn lại theo tên
$typeGroups = [];
foreach ($facetLeaves as $fl) $typeGroups[(string)$fl['parent']][] = $fl;
foreach ($typeGroups as $gName => &$gItems) {
  usort($gItems, static fn($a, $b) => [isset($kwLeaf[(int)$b['id']]) <=> isset($kwLeaf[(int)$a['id']]), (string)$a['name']] <=> [0, (string)$b['name']]);
}
unset($gItems);
$groupHasKw = static function (array $items) use ($kwLeaf): bool {
  foreach ($items as $it) if (isset($kwLeaf[(int)$it['id']])) return true;
  return false;
};
uksort($typeGroups, static function ($a, $b) use ($typeGroups, $groupHasKw): int {
  return [$groupHasKw($typeGroups[$b]) <=> $groupHasKw($typeGroups[$a]), $a] <=> [0, $b];
});
// Lá không có trong facet (id lạ): vẫn hiện chip để người dùng bỏ được
$selLeafNames = [];
foreach ($fLoai as $lid) $selLeafNames[$lid] = isset($leafById[$lid]) ? (string)$leafById[$lid]['name'] : 'Danh mục #' . $lid;

// --- Khoảng giá: định dạng + dòng "Đang lọc" (server đã đảo nếu từ > đến) ---
$fmtVnd = static fn($n): string => number_format((int)$n, 0, ',', '.') . 'đ';
$fmtShort = static function ($n): string {
  $n = (int)$n;
  if ($n >= 1000000) return str_replace('.', ',', rtrim(rtrim(number_format($n / 1000000, 1, '.', ''), '0'), '.')) . 'tr';
  if ($n >= 1000) return (string)round($n / 1000) . 'k';
  return $n . 'đ';
};
$hasPrice = $fPriceMin !== '' || $fPriceMax !== '';
$priceNote = '';
$priceShortSel = '';
if ($hasPrice) {
  if ($fPriceMin !== '' && $fPriceMax !== '') {
    $priceNote = $fmtVnd($fPriceMin) . ' – ' . $fmtVnd($fPriceMax);
    $priceShortSel = $fmtShort($fPriceMin) . ' – ' . $fmtShort($fPriceMax);
  } elseif ($fPriceMin !== '') {
    $priceNote = 'từ ' . $fmtVnd($fPriceMin);
    $priceShortSel = '≥ ' . $fmtShort($fPriceMin);
  } else {
    $priceNote = 'đến ' . $fmtVnd($fPriceMax);
    $priceShortSel = '≤ ' . $fmtShort($fPriceMax);
  }
  foreach ($pricePresets as $preset) {
    if ($fPriceMin === $preset['min'] && $fPriceMax === $preset['max']) $priceShortSel = $preset['label'];
  }
}
// Tham số lọc đang bật (bỏ rỗng; mảng giữ nguyên) để nối vào link phân trang / gợi ý
$activeFilterParams = $hasCatFilters ? array_filter([
  'loai' => $fLoai, 'thuong_hieu' => $fBrands, 'gia_tu' => $fPriceMin, 'gia_den' => $fPriceMax,
  'loai_da' => $fSkins, 'danh_gia' => $fRating, 'giam_gia' => $fSale, 'con_hang' => $fStock,
], static fn($v) => $v !== '' && $v !== []) : [];
if (!empty($activeFilterParams)) {
  $paginationBase .= '&' . http_build_query($activeFilterParams);
}
// Link đặt/bỏ một số bộ lọc, giữ từ khóa/sắp xếp/danh mục (no-JS vẫn dùng được)
$filterUrl = function (array $override) use ($listRoute, $q, $sort, $exact, $cap1, $cap2, $activeFilterParams): string {
  $query = array_merge(['r' => $listRoute, 'q' => $q, 'cap1' => $cap1, 'cap2' => $cap2, 'sort' => ($sort !== 'default' ? $sort : ''), 'exact' => $exact ? '1' : ''], $activeFilterParams, $override);
  return BASE_URL . '/index.php?' . http_build_query(array_filter($query, static fn($v) => $v !== '' && $v !== null && $v !== []));
};
// Mỗi giá trị đã chọn = một chip; bỏ chip chỉ bỏ đúng giá trị đó
$activeChips = [];
if ($showFilterPanel) {
  foreach ($fLoai as $lid) {
    $activeChips[] = ['label' => 'Loại: ' . $selLeafNames[$lid], 'set' => ['loai' => array_values(array_diff($fLoai, [$lid]))], 'uncheck' => ['name' => 'loai[]', 'value' => (string)$lid]];
  }
  foreach ($fSkins as $sk) {
    if (isset($skinOptions[$sk])) $activeChips[] = ['label' => $skinOptions[$sk], 'set' => ['loai_da' => array_values(array_diff($fSkins, [$sk]))], 'uncheck' => ['name' => 'loai_da[]', 'value' => $sk]];
  }
  foreach ($fBrands as $br) {
    $activeChips[] = ['label' => 'Hãng: ' . $br, 'set' => ['thuong_hieu' => array_values(array_diff($fBrands, [$br]))], 'uncheck' => ['name' => 'thuong_hieu[]', 'value' => $br]];
  }
  if ($hasPrice) $activeChips[] = ['label' => 'Giá: ' . $priceShortSel, 'set' => ['gia_tu' => '', 'gia_den' => '']];
  if ($fSale === '1') $activeChips[] = ['label' => 'Đang giảm giá', 'set' => ['giam_gia' => '']];
  if ($fStock === '1') $activeChips[] = ['label' => 'Còn hàng', 'set' => ['con_hang' => '']];
  if ($fRating === '4') $activeChips[] = ['label' => 'Từ 4★', 'set' => ['danh_gia' => '']];
}
// data-live-set: mảng rỗng = bỏ chọn cả nhóm checkbox; chuỗi rỗng = xóa trường
$clearAllSet = ['loai[]' => [], 'loai_da[]' => [], 'thuong_hieu[]' => [], 'gia_tu' => '', 'gia_den' => '', 'giam_gia' => '', 'con_hang' => '', 'danh_gia' => ''];
$clearAllHref = $filterUrl(['loai' => [], 'loai_da' => [], 'thuong_hieu' => [], 'gia_tu' => '', 'gia_den' => '', 'giam_gia' => '', 'con_hang' => '', 'danh_gia' => '', 'cap1' => '', 'cap2' => '']);
// Link tìm lại bằng từ khóa khác, giữ danh mục/sắp xếp/bộ lọc hiện tại.
$searchLink = function (string $keyword, bool $asExact = false) use ($listRoute, $cap1, $cap2, $sort, $activeFilterParams): string {
  $url = BASE_URL . '/index.php?r=' . rawurlencode($listRoute) . '&q=' . urlencode($keyword);
  if ($cap1 !== '') $url .= '&cap1=' . urlencode($cap1);
  if ($cap2 !== '') $url .= '&cap2=' . urlencode($cap2);
  if (!empty($sort) && $sort !== 'default') $url .= '&sort=' . urlencode($sort);
  if (!empty($activeFilterParams)) $url .= '&' . http_build_query($activeFilterParams);
  if ($asExact) $url .= '&exact=1';
  return $url;
};
// cap1/cap2 chỉ còn khi danh mục lạ không map được sang id lá
$capQuery = ($cap1 !== '' ? '&cap1=' . urlencode($cap1) : '') . ($cap2 !== '' ? '&cap2=' . urlencode($cap2) : '');

$totalPages = max(1, (int)ceil($total / $perPage));
$maxVisible = 10;
$startPage = max(1, (int)$page - (int)floor($maxVisible / 2));
$endPage = min($totalPages, $startPage + $maxVisible - 1);
$startPage = max(1, $endPage - $maxVisible + 1);
?>

<?php
// Tiêu đề trang + số kết quả (dùng chung cho giao diện mới và bản đơn giản)
$summaryCap = $showFilterPanel ? '' : implode(' / ', array_filter([$cap1, $cap2], static fn($v) => $v !== ''));
$sortOptions = [
  'default' => ['Mặc định', ['default', 'mac_dinh', '']],
  'price_asc' => ['Giá tăng dần', ['price_asc', 'gia_asc', 'gia_tang']],
  'price_desc' => ['Giá giảm dần', ['price_desc', 'gia_desc', 'gia_giam']],
  'best_seller' => ['Bán chạy', ['best_seller', 'ban_chay']],
  'top_rated' => ['Đánh giá cao', ['top_rated', 'high_rating', 'danh_gia_cao']],
  'discount' => ['Giảm giá nhiều', ['discount', 'discount_desc', 'giam_gia']],
  'newest' => ['Mới nhất', ['newest', 'moi_nhat']],
  'most_viewed' => ['Nhiều lượt xem', ['most_viewed', 'views_desc', 'nhieu_luot_xem']],
];
$hiddenFields = function () use ($listRoute, $listType, $exact, $showFilterPanel, $cap1, $cap2, $fLoai): void {
  echo '<input type="hidden" name="r" value="' . h($listRoute) . '">';
  if ($listType) echo '<input type="hidden" name="type" value="' . h($listType) . '">';
  // cap1/cap2 chỉ còn khi danh mục lạ không map được sang id lá
  if ($cap1) echo '<input type="hidden" name="cap1" value="' . h($cap1) . '">';
  if ($cap2) echo '<input type="hidden" name="cap2" value="' . h($cap2) . '">';
  // Bản đơn giản (không có panel): giữ danh mục đã chọn qua loai[]
  if (!$showFilterPanel) {
    foreach ($fLoai as $lid) echo '<input type="hidden" name="loai[]" value="' . (int)$lid . '">';
  }
  if ($exact) echo '<input type="hidden" name="exact" value="1">';
};
$sortSelect = function (string $class) use ($sortOptions, $sort): void {
  echo '<select id="catalogSortSelect" class="' . $class . '" name="sort">';
  foreach ($sortOptions as $val => [$label, $aliases]) {
    echo '<option value="' . h($val) . '"' . (in_array($sort, $aliases, true) ? ' selected' : '') . '>' . h($label) . '</option>';
  }
  echo '</select>';
};
$activeCount = count($activeChips);
?>
<link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/catalog-filter.css?v=<?= @filemtime(dirname(__DIR__) . '/public/assets/css/catalog-filter.css') ?: '1' ?>">

<div class="container my-4 catf-page">
<?php if ($showFilterPanel): ?>
  <div class="catf-layout">
    <form class="catf-form" id="catalogFilterForm" method="get" action="<?= BASE_URL ?>/index.php"
          data-live-filter data-live-target="#catalogResults" data-live-also="#catalogSummary,#catfChips,#catfSideHead,#catfSelSkin,#catfSelType,#catfSelBrand,#catfSelPrice,#catfSelDeal,#catfSwitches,#catfSkin,#catfPrice,#catfPriceNote,#catfType,#catfBrand,#catfApplyN,#catfFabBadge" data-live-reset="page">
      <?php $hiddenFields(); ?>

      <!-- 1. Header -->
      <div class="catf-head">
        <div>
          <span class="catf-eyebrow">TẤT CẢ SẢN PHẨM</span>
          <h2 class="catf-title"><?= h($pageTitle) ?></h2>
          <div id="catalogSummary" class="catf-count" aria-live="polite"><strong><?= (int)$total ?></strong> sản phẩm phù hợp</div>
        </div>
        <a class="catf-ai" href="<?= BASE_URL ?>/index.php?r=goiy"><i class="fas fa-wand-magic-sparkles"></i> Khám phá bằng AI</a>
      </div>

      <!-- 2. Toolbar -->
      <div class="catf-toolbar">
        <div class="catf-search">
          <label for="catalogSearchInput" class="visually-hidden">Từ khóa tìm kiếm</label>
          <i class="fas fa-magnifying-glass catf-search-ico" aria-hidden="true"></i>
          <input id="catalogSearchInput" name="q" type="search" autocomplete="off" placeholder="Tìm tên sản phẩm, thương hiệu (La Roche-Posay, Paula's Choice...)" value="<?= h($q) ?>">
          <button type="button" class="catf-search-clear" id="catfSearchClear" aria-label="Xóa từ khóa"><i class="fas fa-xmark" aria-hidden="true"></i></button>
        </div>
        <div class="catf-sort">
          <label for="catalogSortSelect">Sắp xếp:</label>
          <?php $sortSelect('catf-sort-select'); ?>
        </div>
        <noscript><button class="catf-noscript" type="submit">Áp dụng</button></noscript>
      </div>

      <!-- 3. Chip đang lọc -->
      <div class="catf-chips" id="catfChips"><?php if (!empty($activeChips)): ?><?php foreach ($activeChips as $chip): ?><a class="catf-chip" href="<?= h($filterUrl($chip['set'])) ?>" <?= isset($chip['uncheck']) ? 'data-live-uncheck' : 'data-live-set' ?>="<?= h(json_encode($chip['uncheck'] ?? $chip['set'], JSON_UNESCAPED_UNICODE)) ?>" title="Bỏ lọc này"><?= h($chip['label']) ?> <i class="fas fa-xmark" aria-hidden="true"></i><span class="visually-hidden">Bỏ lọc</span></a><?php endforeach; ?><a class="catf-clear-all" href="<?= h($clearAllHref) ?>" data-live-set="<?= h(json_encode($clearAllSet)) ?>">Xóa tất cả</a><?php endif; ?></div>

      <!-- 4. Sidebar (offcanvas bottom dưới lg) -->
      <aside class="catf-side offcanvas-lg offcanvas-bottom" tabindex="-1" id="catfSheet" aria-labelledby="catfSheetTitle">
        <div class="catf-sheet-head d-lg-none">
          <span class="catf-grab" aria-hidden="true"></span>
          <div class="catf-sheet-row">
            <h3 id="catfSheetTitle">Bộ lọc</h3>
            <button type="button" class="catf-sheet-close" data-bs-dismiss="offcanvas" data-bs-target="#catfSheet" aria-label="Đóng bộ lọc"><i class="fas fa-xmark" aria-hidden="true"></i></button>
          </div>
        </div>
        <div class="catf-card">
          <!-- Đầu sidebar: số kết quả (desktop) + gợi ý đọc số -->
          <div class="catf-side-head">
            <div id="catfSideHead" class="catf-side-count d-none d-lg-flex">
              <span><strong><?= (int)$total ?></strong> sản phẩm</span>
              <?php if ($activeCount > 0): ?><a class="catf-side-reset" href="<?= h($clearAllHref) ?>" data-live-set="<?= h(json_encode($clearAllSet)) ?>">Xóa lọc</a><?php endif; ?>
            </div>
            <p class="catf-hint"><i class="fa-solid fa-circle-info" aria-hidden="true"></i> Số bên cạnh = số sản phẩm nếu bạn chọn thêm mục đó</p>
          </div>

          <!-- a. Loại sản phẩm: cây nhóm cha -> lá, checkbox đa chọn -->
          <details class="catf-sec" open>
            <summary class="catf-sec-h"><span class="catf-sec-t"><i class="fa-solid fa-list" aria-hidden="true"></i> Loại sản phẩm</span>
              <span class="catf-sel" id="catfSelType"><?php if (!empty($fLoai)): ?><span class="catf-sel-t"><?= count($fLoai) === 1 ? h($selLeafNames[$fLoai[0]]) : count($fLoai) . ' đã chọn' ?></span><a class="catf-sel-x" href="<?= h($filterUrl(['loai' => [], 'cap1' => '', 'cap2' => ''])) ?>" data-live-set="<?= h(json_encode(['loai[]' => []])) ?>" aria-label="Bỏ chọn loại sản phẩm">×</a><?php endif; ?></span>
              <i class="fa-solid fa-chevron-down catf-caret" aria-hidden="true"></i></summary>
            <div class="catf-sec-b" id="catfType">
              <div class="catf-tree" role="group" aria-label="Loại sản phẩm">
                <?php foreach ($typeGroups as $parentName => $groupItems): ?>
                  <?php
                    $gSum = 0; $gSel = 0; $gOn = 0; $gAvail = 0;
                    foreach ($groupItems as $it) {
                      $gSum += (int)$it['count'];
                      $isOn = in_array((int)$it['id'], $fLoai, true);
                      if ($isOn) $gSel++;
                      if ((int)$it['count'] > 0 || $isOn) { $gAvail++; if ($isOn) $gOn++; }
                    }
                    $gState = $gSel === 0 ? 'none' : (($gAvail > 0 && $gOn === $gAvail) ? 'all' : 'some');
                    $gOpen = $gSel > 0 || $groupHasKw($groupItems);
                    $gKey = substr(md5((string)$parentName), 0, 8);
                  ?>
                  <details class="catf-grp" data-gkey="<?= h($gKey) ?>"<?= $gOpen ? ' open' : '' ?>>
                    <summary class="catf-grp-h">
                      <input type="checkbox" class="catf-par" data-state="<?= h($gState) ?>" <?= $gState === 'all' ? 'checked' : '' ?> <?= ($gAvail === 0) ? 'disabled' : '' ?> aria-label="Chọn cả nhóm <?= h($parentName !== '' ? $parentName : 'Khác') ?>">
                      <span class="catf-grp-name"><?= h($parentName !== '' ? $parentName : 'Khác') ?></span>
                      <span class="catf-badge"><?= $gSum ?></span>
                      <i class="fa-solid fa-chevron-down catf-caret" aria-hidden="true"></i>
                    </summary>
                    <div class="catf-grp-b">
                      <?php foreach ($groupItems as $fc): ?>
                        <?php
                          $lid = (int)$fc['id']; $isSel = in_array($lid, $fLoai, true);
                          $fcCnt = (int)$fc['count']; $fcOff = $fcCnt === 0 && !$isSel;
                        ?>
                        <label class="catf-row <?= $isSel ? 'is-on' : '' ?> <?= $fcOff ? 'is-off' : '' ?>" for="catf-leaf-<?= $lid ?>">
                          <input type="checkbox" name="loai[]" id="catf-leaf-<?= $lid ?>" value="<?= $lid ?>" <?= $isSel ? 'checked' : '' ?> <?= $fcOff ? 'disabled' : '' ?>>
                          <span class="catf-box" aria-hidden="true"></span><span class="catf-row-name"><?= h($fc['name']) ?><?php if (isset($kwLeaf[$lid])): ?><span class="catf-kw" title="Nhiều sản phẩm khớp từ khóa nằm ở loại này">Khớp từ khóa</span><?php endif; ?></span>
                          <span class="catf-badge"><?= $fcCnt ?></span>
                        </label>
                      <?php endforeach; ?>
                    </div>
                  </details>
                <?php endforeach; ?>
                <?php foreach ($fLoai as $lid): if (isset($leafById[$lid])) continue; ?>
                  <label class="catf-row is-on" for="catf-leaf-<?= (int)$lid ?>"><input type="checkbox" name="loai[]" id="catf-leaf-<?= (int)$lid ?>" value="<?= (int)$lid ?>" checked><span class="catf-box" aria-hidden="true"></span><span class="catf-row-name"><?= h($selLeafNames[$lid]) ?></span><span class="catf-badge">0</span></label>
                <?php endforeach; ?>
              </div>
            </div>
          </details>

          <!-- b. Loại da (đa chọn) -->
          <details class="catf-sec" open>
            <summary class="catf-sec-h"><span class="catf-sec-t"><i class="fa-solid fa-face-smile" aria-hidden="true"></i> Loại da</span>
              <?php $skinSelLabels = array_values(array_filter(array_map(static fn($k) => $skinOptions[$k] ?? null, $fSkins))); ?>
              <span class="catf-sel" id="catfSelSkin"><?php if (!empty($skinSelLabels)): ?><span class="catf-sel-t"><?= count($skinSelLabels) === 1 ? h($skinSelLabels[0]) : count($skinSelLabels) . ' đã chọn' ?></span><a class="catf-sel-x" href="<?= h($filterUrl(['loai_da' => []])) ?>" data-live-set="<?= h(json_encode(['loai_da[]' => []])) ?>" aria-label="Bỏ chọn loại da">×</a><?php endif; ?></span>
              <i class="fa-solid fa-chevron-down catf-caret" aria-hidden="true"></i></summary>
            <div class="catf-sec-b" id="catfSkin">
              <div class="catf-tiles" role="group" aria-label="Loại da">
                <?php $skinIcons = ['dau' => 'fa-droplet', 'kho' => 'fa-sun', 'nhay_cam' => 'fa-feather', 'mun' => 'fa-circle-dot']; ?>
                <?php foreach ($skinOptions as $skinKey => $skinLabel): ?>
                  <?php $skinCnt = (int)($facets['skin'][$skinKey] ?? 0); $skinOn = in_array($skinKey, $fSkins, true); $skinOff = $skinCnt === 0 && !$skinOn; ?>
                  <label class="catf-tile <?= $skinOn ? 'is-on' : '' ?> <?= $skinOff ? 'is-off' : '' ?>" for="catf-skin-<?= h($skinKey) ?>">
                    <input type="checkbox" name="loai_da[]" id="catf-skin-<?= h($skinKey) ?>" value="<?= h($skinKey) ?>" <?= $skinOn ? 'checked' : '' ?> <?= $skinOff ? 'disabled' : '' ?>>
                    <i class="fa-solid <?= h($skinIcons[$skinKey] ?? 'fa-circle') ?> catf-tile-ico" aria-hidden="true"></i>
                    <span class="catf-tile-name"><?= h($skinLabel) ?></span>
                    <span class="catf-tile-cnt"><?= $skinCnt ?></span>
                    <?php if ($skinOn): ?><i class="fa-solid fa-circle-check catf-tile-check" aria-hidden="true"></i><?php endif; ?>
                  </label>
                <?php endforeach; ?>
              </div>
            </div>
          </details>

          <!-- c. Thương hiệu (đa chọn) -->
          <details class="catf-sec" open>
            <summary class="catf-sec-h"><span class="catf-sec-t"><i class="fa-solid fa-award" aria-hidden="true"></i> Thương hiệu</span>
              <span class="catf-sel" id="catfSelBrand"><?php if (!empty($fBrands)): ?><span class="catf-sel-t"><?= count($fBrands) === 1 ? h($fBrands[0]) : count($fBrands) . ' đã chọn' ?></span><a class="catf-sel-x" href="<?= h($filterUrl(['thuong_hieu' => []])) ?>" data-live-set="<?= h(json_encode(['thuong_hieu[]' => []])) ?>" aria-label="Bỏ chọn thương hiệu">×</a><?php endif; ?></span>
              <i class="fa-solid fa-chevron-down catf-caret" aria-hidden="true"></i></summary>
            <div class="catf-sec-b">
              <label class="catf-bsearch"><span class="visually-hidden">Tìm thương hiệu</span><i class="fas fa-magnifying-glass" aria-hidden="true"></i><input type="text" id="catfBrandSearch" autocomplete="off" placeholder="Tìm thương hiệu..."></label>
              <div id="catfBrand">
                <?php $brandSeen = []; $bi = 0; ?>
                <div class="catf-list catf-list--brand" role="group" aria-label="Thương hiệu">
                  <?php foreach ((array)($facets['brands'] ?? []) as $fb): ?>
                    <?php
                      $bName = (string)$fb['name']; $isSel = in_array($bName, $fBrands, true); $brandSeen[] = $bName;
                      $bCnt = (int)$fb['count']; $bi++;
                      $bLetter = function_exists('mb_substr') ? mb_strtoupper(mb_substr($bName, 0, 1)) : strtoupper(substr($bName, 0, 1));
                    ?>
                    <label class="catf-row <?= $isSel ? 'is-on' : '' ?> <?= ($bCnt === 0 && !$isSel) ? 'is-off' : '' ?>" for="catf-br-<?= $bi ?>" data-name="<?= h($bName) ?>">
                      <input type="checkbox" name="thuong_hieu[]" id="catf-br-<?= $bi ?>" value="<?= h($bName) ?>" <?= $isSel ? 'checked' : '' ?> <?= ($bCnt === 0 && !$isSel) ? 'disabled' : '' ?>>
                      <span class="catf-box" aria-hidden="true"></span><span class="catf-avatar" aria-hidden="true"><?= h($bLetter) ?></span><span class="catf-row-name"><?= h($bName) ?></span><span class="catf-badge"><?= $bCnt ?></span>
                    </label>
                  <?php endforeach; ?>
                  <?php foreach ($fBrands as $fbSel): if (in_array($fbSel, $brandSeen, true)) continue; $bi++; ?>
                    <label class="catf-row is-on" for="catf-br-<?= $bi ?>" data-name="<?= h($fbSel) ?>"><input type="checkbox" name="thuong_hieu[]" id="catf-br-<?= $bi ?>" value="<?= h($fbSel) ?>" checked><span class="catf-box" aria-hidden="true"></span><span class="catf-avatar" aria-hidden="true"><?= h(function_exists('mb_substr') ? mb_strtoupper(mb_substr($fbSel, 0, 1)) : strtoupper(substr($fbSel, 0, 1))) ?></span><span class="catf-row-name"><?= h($fbSel) ?></span><span class="catf-badge">0</span></label>
                  <?php endforeach; ?>
                </div>
                <p class="catf-empty-brand" hidden>Không có thương hiệu phù hợp</p>
              </div>
            </div>
          </details>

          <!-- e. Khoảng giá -->
          <details class="catf-sec"<?= $hasPrice ? ' open' : '' ?>>
            <summary class="catf-sec-h"><span class="catf-sec-t"><i class="fa-solid fa-tag" aria-hidden="true"></i> Khoảng giá</span>
              <span class="catf-sel" id="catfSelPrice"><?php if ($hasPrice): ?><span class="catf-sel-t"><?= h($priceShortSel) ?></span><a class="catf-sel-x" href="<?= h($filterUrl(['gia_tu' => '', 'gia_den' => ''])) ?>" data-live-set="<?= h(json_encode(['gia_tu' => '', 'gia_den' => ''])) ?>" aria-label="Bỏ chọn khoảng giá">×</a><?php endif; ?></span>
              <i class="fa-solid fa-chevron-down catf-caret" aria-hidden="true"></i></summary>
            <div class="catf-sec-b">
              <div id="catfPrice">
                <?php
                  $priceShort = ['lt200' => '<200k', '200_500' => '200–500k', '500_1000' => '500k–1tr', 'gt1000' => '>1tr'];
                  $maxBucket = 0;
                  foreach ($pricePresets as $pp) $maxBucket = max($maxBucket, (int)($facets['price'][$pp['key']] ?? 0));
                  $rangeLo = $fPriceMin === '' ? 0 : (int)$fPriceMin;
                  $rangeHi = $fPriceMax === '' ? PHP_INT_MAX : (int)$fPriceMax;
                ?>
                <div class="catf-hist" role="group" aria-label="Khoảng giá">
                  <?php foreach ($pricePresets as $preset): ?>
                    <?php
                      $isActive = $fPriceMin === $preset['min'] && $fPriceMax === $preset['max'];
                      $presetCnt = (int)($facets['price'][$preset['key']] ?? 0);
                      $presetSet = $isActive ? ['gia_tu' => '', 'gia_den' => ''] : ['gia_tu' => $preset['min'], 'gia_den' => $preset['max']];
                      $barH = $maxBucket > 0 ? max(6, (int)round($presetCnt / $maxBucket * 100)) : 6;
                      // Cột nằm trong khoảng giá đang lọc (gõ tay hay chọn sẵn) -> sáng, còn lại mờ
                      $bLo = (int)$preset['min']; $bHi = $preset['max'] === '' ? PHP_INT_MAX : (int)$preset['max'];
                      $inRange = $hasPrice && $rangeLo <= $bHi && $rangeHi >= $bLo;
                    ?>
                    <a class="catf-bar <?= $isActive ? 'is-on' : '' ?> <?= ($hasPrice && $inRange && !$isActive) ? 'is-in' : '' ?> <?= ($hasPrice && !$inRange) ? 'is-dim' : '' ?> <?= ($presetCnt === 0 && !$isActive && !$hasPrice) ? 'is-off' : '' ?>" href="<?= h($filterUrl($presetSet)) ?>" data-live-set="<?= h(json_encode($presetSet)) ?>" role="button" aria-pressed="<?= $isActive ? 'true' : 'false' ?>" title="<?= h($preset['label'] . ': ' . $presetCnt . ' sản phẩm') ?>">
                      <span class="catf-bar-n"><?= $presetCnt ?></span>
                      <span class="catf-bar-col"><span class="catf-bar-fill" style="height: <?= $barH ?>%"></span></span>
                      <span class="catf-bar-l"><?= h($priceShort[$preset['key']] ?? $preset['label']) ?></span>
                    </a>
                  <?php endforeach; ?>
                </div>
              </div>
              <div class="catf-price-inputs">
                <label class="catf-pi"><span>Từ</span><input type="number" min="0" step="10000" name="gia_tu" id="catfPriceMin" value="<?= h($fPriceMin) ?>" placeholder="0" inputmode="numeric"><em>đ</em></label>
                <label class="catf-pi"><span>Đến</span><input type="number" min="0" step="10000" name="gia_den" id="catfPriceMax" value="<?= h($fPriceMax) ?>" placeholder="1.000.000" inputmode="numeric"><em>đ</em></label>
              </div>
              <div id="catfPriceNote" class="catf-price-note"><span class="catf-pn-data" hidden data-min="<?= h($fPriceMin) ?>" data-max="<?= h($fPriceMax) ?>"></span><?php if ($hasPrice): ?><span class="catf-pn-text">Đang lọc: <strong><?= h($priceNote) ?></strong></span><?php endif; ?></div>
            </div>
          </details>

          <!-- f. Ưu đãi & đánh giá -->
          <?php $dealOn = array_values(array_filter($toggleOptions, static fn($t) => $t['on'])); ?>
          <details class="catf-sec"<?= !empty($dealOn) ? ' open' : '' ?>>
            <summary class="catf-sec-h"><span class="catf-sec-t"><i class="fa-solid fa-bolt" aria-hidden="true"></i> Ưu đãi &amp; đánh giá</span>
              <span class="catf-sel" id="catfSelDeal"><?php if (!empty($dealOn)): ?><span class="catf-sel-t"><?= count($dealOn) === 1 ? h($dealOn[0]['label']) : count($dealOn) . ' mục' ?></span><a class="catf-sel-x" href="<?= h($filterUrl(['giam_gia' => '', 'con_hang' => '', 'danh_gia' => ''])) ?>" data-live-set="<?= h(json_encode(['giam_gia' => '', 'con_hang' => '', 'danh_gia' => ''])) ?>" aria-label="Bỏ chọn ưu đãi và đánh giá">×</a><?php endif; ?></span>
              <i class="fa-solid fa-chevron-down catf-caret" aria-hidden="true"></i></summary>
            <div class="catf-sec-b" id="catfSwitches">
              <?php foreach ($toggleOptions as $tg): ?>
                <?php $tgCnt = (int)($facets[$tg['facet']] ?? 0); $tgId = 'catf-tg-' . $tg['name']; ?>
                <label class="catf-switch <?= ($tgCnt === 0 && !$tg['on']) ? 'is-empty' : '' ?>" for="<?= h($tgId) ?>">
                  <input type="checkbox" role="switch" id="<?= h($tgId) ?>" name="<?= h($tg['name']) ?>" value="<?= h($tg['value']) ?>" <?= $tg['on'] ? 'checked' : '' ?>>
                  <span class="catf-track" aria-hidden="true"></span>
                  <span class="catf-switch-label"><?= h($tg['label']) ?></span>
                  <span class="catf-badge"><?= $tgCnt ?></span>
                </label>
              <?php endforeach; ?>
            </div>
          </details>
        </div>

        <div class="catf-sheet-foot d-lg-none">
          <button type="button" class="catf-apply" data-bs-dismiss="offcanvas" data-bs-target="#catfSheet">Xem <span id="catfApplyN"><?= (int)$total ?></span> sản phẩm</button>
        </div>
      </aside>

      <button type="button" class="catf-fab d-lg-none" data-bs-toggle="offcanvas" data-bs-target="#catfSheet" aria-controls="catfSheet">
        <i class="fa-solid fa-sliders" aria-hidden="true"></i> Bộ lọc<span id="catfFabBadge"><?php if ($activeCount > 0): ?><span class="catf-fab-badge"><?= (int)$activeCount ?></span><?php endif; ?></span>
      </button>
    </form>

    <div class="catf-main">
<?php else: ?>
  <div class="catf-simple">
    <div class="d-flex flex-wrap justify-content-between align-items-end gap-3 mb-3">
      <div>
        <span class="catf-eyebrow">TẤT CẢ SẢN PHẨM</span>
        <h2 class="catf-title"><?= h($pageTitle) ?></h2>
        <div id="catalogSummary" class="catf-count" aria-live="polite"><strong><?= (int)$total ?></strong> sản phẩm phù hợp</div>
        <?php if ($summaryCap !== ''): ?><span class="catf-chip mt-2 d-inline-block">Đang lọc: <?= h($summaryCap) ?></span><?php endif; ?>
      </div>
      <a class="catf-ai" href="<?= BASE_URL ?>/index.php?r=goiy"><i class="fas fa-wand-magic-sparkles"></i> Khám phá bằng AI</a>
    </div>
    <form class="catf-toolbar mb-4" method="get" action="<?= BASE_URL ?>/index.php" data-live-filter data-live-target="#catalogResults" data-live-also="#catalogSummary" data-live-reset="page">
      <?php $hiddenFields(); ?>
      <div class="catf-search">
        <label for="catalogSearchInput" class="visually-hidden">Từ khóa tìm kiếm</label>
        <i class="fas fa-magnifying-glass catf-search-ico" aria-hidden="true"></i>
        <input id="catalogSearchInput" name="q" type="search" autocomplete="off" placeholder="Tìm tên sản phẩm, thương hiệu..." value="<?= h($q) ?>">
        <button type="button" class="catf-search-clear" id="catfSearchClear" aria-label="Xóa từ khóa"><i class="fas fa-xmark" aria-hidden="true"></i></button>
      </div>
      <div class="catf-sort">
        <label for="catalogSortSelect">Sắp xếp:</label>
        <?php $sortSelect('catf-sort-select'); ?>
      </div>
      <button class="catf-btn" type="submit"><i class="fas fa-magnifying-glass"></i> Tìm</button>
      <a class="catf-btn catf-btn--ghost" href="<?= BASE_URL ?>/index.php?r=<?= h($listRoute) ?><?= $listType ? '&type=' . urlencode($listType) : '' ?>">Xóa bộ lọc</a>
    </form>
  </div>
  <div class="catf-main catf-main--full">
<?php endif; ?>
  <div id="catalogResults" aria-live="polite">
  <?php if ($didYouMean !== null): ?>
    <div class="alert alert-info border-0 shadow-sm py-2 px-3 mb-3 search-did-you-mean" role="status" data-did-you-mean="<?= h($didYouMean['mode']) ?>" style="border-radius: 8px; font-size: 0.9rem;">
      <?php if ($didYouMean['mode'] === 'auto'): ?>
        Đang hiển thị kết quả cho «<strong><?= h($didYouMean['corrected']) ?></strong>».
        Tìm chính xác «<a href="<?= h($searchLink($didYouMean['original'], true)) ?>" class="fw-semibold" data-did-you-mean-exact><?= h($didYouMean['original']) ?></a>»
      <?php else: ?>
        Có phải bạn muốn tìm «<a href="<?= h($searchLink($didYouMean['corrected'])) ?>" class="fw-semibold" data-did-you-mean-link><?= h($didYouMean['corrected']) ?></a>»?
      <?php endif; ?>
    </div>
  <?php endif; ?>
  <?php if ($dbUnavailableMessage !== ''): ?>
    <div class="alert alert-warning border-0 shadow-sm" style="border-radius: 8px;"><?= h($dbUnavailableMessage) ?></div>
  <?php elseif (empty($items) && is_array($searchFallback ?? null) && !empty($searchFallback['sections'])): ?>
    <?php $ssfHasExact = false; include __DIR__ . '/partials/search_fallback.php'; ?>
  <?php elseif (empty($items)): ?>
    <div class="p-5 text-center bg-white border text-muted" style="border-radius: 12px; border-color: var(--border) !important;">
      <i class="fas fa-box-open fs-1 mb-3" style="color: #183B2B;"></i>
      <h4 class="fw-bold text-dark">Không tìm thấy sản phẩm phù hợp</h4>
      <p style="font-size: 0.9rem;">Thử tìm kiếm với từ khóa khác hoặc bấm Xóa bộ lọc để quay lại danh mục đầy đủ.</p>
      <a href="<?= BASE_URL ?>/index.php?r=tatca" class="btn text-white fw-semibold px-4 mt-2" style="background: #183B2B; border-radius: 6px; font-size: 0.88rem;">Xem tất cả sản phẩm</a>
    </div>
  <?php else: ?>
    <div class="row g-3">
      <?php foreach ($items as $idx => $p):
        $productId = (string)($p['id'] ?? $p['ma_san_pham'] ?? '');
        $img = resolve_image_url(function_exists('product_image_source') ? product_image_source($p) : (string)($p['link_hinh_anh'] ?? $p['hinh_anh'] ?? ''));
        $giaBan = (string)($p['gia_ban'] ?? '');
        $giaThiTruong = trim((string)($p['gia_thi_truong'] ?? ''));
        $phanTramGiam = function_exists('product_discount_percent') ? product_discount_percent($p) : null;
        $rating = isset($p['diem_danh_gia']) && (float)$p['diem_danh_gia'] > 0 ? (float)$p['diem_danh_gia'] : 4.9;
        $isOutOfStock = function_exists('product_is_out_of_stock') ? product_is_out_of_stock($p) : false;
        $isLcp = ($idx === 0);
        $isAboveFold = ($idx < 4);
      ?>
        <div class="col-6 col-lg-4 col-xxl-3">
          <div class="product-card product-card--catalog h-100 d-flex flex-column" style="border-radius: 12px; border: 1px solid var(--border); background: #FFF; transition: border-color 0.2s ease, box-shadow 0.2s ease;">
            <div class="product-thumb position-relative p-2" style="aspect-ratio: 1/1; overflow: hidden; background: #F8FAF8; border-radius: 12px 12px 0 0;">
              <?php if ($phanTramGiam !== null): ?>
                <span class="badge-sale position-absolute" style="top: 12px; left: 12px; background: #E11D48; color: #FFF; font-weight: 700; font-size: 0.72rem; padding: 3px 8px; border-radius: 4px; z-index: 2;">
                  -<?= h((string)$phanTramGiam) ?>%
                </span>
              <?php endif; ?>

              <a href="<?= BASE_URL ?>/index.php?r=chitiet&id=<?= h($productId) ?>" class="d-block w-100 h-100 overflow-hidden" style="border-radius: 8px;">
                <img src="<?= h($img ?: default_placeholder_image()) ?>"
                     <?= $isLcp ? 'fetchpriority="high"' : ($isAboveFold ? '' : 'loading="lazy"') ?>
                     decoding="async"
                     width="280"
                     height="280"
                     referrerpolicy="no-referrer"
                     data-image-fallbacks="<?= function_exists('product_image_fallbacks_json') ? product_image_fallbacks_json($p, $img) : '[]' ?>"
                     data-image-placeholder="<?= h(default_placeholder_image()) ?>"
                     onerror="window.SkinSyntaxImageFallback(this)"
                     alt="<?= h($p['ten_san_pham'] ?? '') ?>"
                     style="width: 100%; height: 100%; object-fit: cover; transition: transform 0.3s ease;">
              </a>
            </div>

            <div class="product-meta p-3 d-flex flex-column flex-grow-1">
              <div class="brand text-uppercase fw-bold mb-2 align-self-start" style="font-size: 0.68rem; color: #183B2B; background: #EBF2EE; padding: 2px 7px; border-radius: 4px; letter-spacing: 0.04em; font-weight: 700; display: inline-block;"><?= h($p['thuong_hieu'] ?? 'SkinSyntax') ?></div>
              <a class="name fw-semibold mb-2 text-decoration-none" href="<?= BASE_URL ?>/index.php?r=chitiet&id=<?= h($productId) ?>" style="color: #0F172A; font-size: 0.88rem; line-height: 1.35; display: -webkit-box; -webkit-line-clamp: 2; line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden; min-height: 2.4em;">
                <?= h($p['ten_san_pham'] ?? '') ?>
              </a>

              <div class="d-flex align-items-center gap-1 mb-2" style="font-size: 0.76rem;">
                <span class="d-inline-flex align-items-center gap-1 text-warning font-weight-bold">
                  <i class="fas fa-star" style="color: #F59E0B; font-size: 0.72rem;"></i> <?= number_format($rating, 1) ?>
                </span>
                <span class="text-muted small ms-1">(<?= (int)($p['so_luong_danh_gia'] ?? 128) ?>)</span>
              </div>

              <div class="price-wrap mb-3 mt-auto d-flex align-items-baseline gap-2">
                <div class="price fw-bold" style="color: #183B2B; font-size: 1.05rem; font-variant-numeric: tabular-nums;"><?= vnd($giaBan) ?></div>
                <?php if ($giaThiTruong !== '' && is_numeric($giaThiTruong) && (float)$giaThiTruong > (float)$giaBan): ?>
                  <div class="price-market text-muted text-decoration-line-through" style="font-size: 0.8rem; font-variant-numeric: tabular-nums; color: #94A3B8 !important;"><?= vnd($giaThiTruong) ?></div>
                <?php endif; ?>
              </div>

              <div class="product-card-actions d-grid gap-2" style="grid-template-columns: 1fr 1fr;">
                <form method="post" action="<?= BASE_URL ?>/index.php?r=them_gio_hang_ajax" class="m-0 w-100">
                  <input type="hidden" name="action" value="add_to_cart">
                  <input type="hidden" name="product_id" value="<?= h($productId) ?>">
                  <input type="hidden" name="ma_san_pham" value="<?= h($productId) ?>">
                  <input type="hidden" name="quantity" value="1">
                  <input type="hidden" name="qty" value="1">
                  <button class="btn btn-sm w-100 text-nowrap d-inline-flex align-items-center justify-content-center" type="submit" style="background: #F1F5F9; color: #183B2B; border: 1px solid #CBD5E1; border-radius: 8px; font-weight: 600; font-size: 0.78rem; height: 38px; padding: 0 4px; transition: all 0.2s ease;" <?= $isOutOfStock ? 'disabled' : '' ?> title="Thêm vào giỏ">
                    <?= $isOutOfStock ? 'Hết hàng' : '<i class="fa-solid fa-cart-plus me-1" style="font-size: 0.74rem;"></i> <span>Thêm vào giỏ</span>' ?>
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
        </div>
      <?php endforeach; ?>
    </div>
    <?php if (is_array($searchFallback ?? null) && !empty($searchFallback['sections'])): ?>
      <?php $ssfHasExact = true; include __DIR__ . '/partials/search_fallback.php'; ?>
    <?php endif; ?>
  <?php endif; ?>

  <?php if ($totalPages > 1): ?>
    <nav class="mt-5">
      <ul class="pagination justify-content-center gap-1">
        <?php if ($page > 1): ?>
          <li class="page-item">
            <a class="page-link d-grid place-items-center" href="<?= h($paginationBase . '&page=1&q=' . urlencode($pageQ) . $capQuery) ?>" style="width: 36px; height: 36px; border-radius: 6px; border-color: var(--border); color: #183B2B;">
              «
            </a>
          </li>
        <?php endif; ?>

        <?php for ($i = $startPage; $i <= $endPage; $i++): ?>
          <li class="page-item <?= ($i === (int)$page) ? 'active' : '' ?>">
            <a class="page-link d-grid place-items-center fw-semibold" href="<?= h($paginationBase . '&page=' . $i . '&q=' . urlencode($pageQ) . $capQuery) ?>" style="width: 36px; height: 36px; border-radius: 6px; border-color: var(--border); <?= ($i === (int)$page) ? 'background: #183B2B; color: #FFF; border-color: #183B2B;' : 'color: #0F172A;' ?>">
              <?= $i ?>
            </a>
          </li>
        <?php endfor; ?>

        <?php if ($page < $totalPages): ?>
          <li class="page-item">
            <a class="page-link d-grid place-items-center" href="<?= h($paginationBase . '&page=' . $totalPages . '&q=' . urlencode($pageQ) . $capQuery) ?>" style="width: 36px; height: 36px; border-radius: 6px; border-color: var(--border); color: #183B2B;">
              »
            </a>
          </li>
        <?php endif; ?>
      </ul>
    </nav>
  <?php endif; ?>
  <?php
    // "Gợi ý thêm cho bạn": có kết quả và khối gợi ý phía máy chủ không hiện -> tải lười qua ?r=search_more.
    $ssmQ = trim((string)($qEffective ?? $q ?? ''));
    $ssmServerFallback = is_array($searchFallback ?? null) && !empty($searchFallback['sections']);
    if ($ssmQ !== '' && $listRoute === 'tatca' && !empty($items) && $dbUnavailableMessage === '' && !$ssmServerFallback):
      $ssmIds = [];
      foreach ($items as $ssmIt) {
        $ssmId = (string)($ssmIt['ma_san_pham'] ?? $ssmIt['id'] ?? '');
        if ($ssmId !== '' && count($ssmIds) < 48) $ssmIds[] = $ssmId;
      }
  ?>
    <div class="ssm-root" data-search-more data-url="<?= h(BASE_URL . '/index.php?r=search_more&q=' . rawurlencode($ssmQ) . '&exclude=' . rawurlencode(implode(',', $ssmIds))) ?>" aria-busy="true"></div>
  <?php endif; ?>
  </div>
</div><!-- /catf-main -->
<?php if ($showFilterPanel): ?></div><!-- /catf-layout --><?php endif; ?>
</div>
<?php if ($showFilterPanel): ?>
<script>
(function () {
  // Ô tìm kiếm: nút xóa; lọc thương hiệu phía client
  var form = document.getElementById('catalogFilterForm');
  var q = document.getElementById('catalogSearchInput');
  var clear = document.getElementById('catfSearchClear');
  if (clear && q) clear.addEventListener('click', function () {
    q.value = '';
    q.dispatchEvent(new Event('input', { bubbles: true }));
    q.focus();
  });
  var bs = document.getElementById('catfBrandSearch');
  function norm(s) { return String(s || '').normalize('NFD').replace(/[\u0300-\u036f]/g, '').replace(/đ/g, 'd').toLowerCase(); }
  function applyBrandFilter() {
    var box = document.getElementById('catfBrand');
    if (!bs || !box) return;
    var term = norm(bs.value.trim()), shown = 0;
    box.querySelectorAll('.catf-row[data-name]').forEach(function (row) {
      var ok = term === '' || norm(row.getAttribute('data-name')).indexOf(term) !== -1;
      row.hidden = !ok;
      if (ok) shown++;
    });
    var empty = box.querySelector('.catf-empty-brand');
    if (empty) empty.hidden = term === '' || shown > 0;
  }
  if (bs) bs.addEventListener('input', applyBrandFilter);
  if (form) form.addEventListener('live-filter:updated', applyBrandFilter);
  // Chặn Enter trong ô tìm thương hiệu (không gửi form)
  if (bs) bs.addEventListener('keydown', function (e) { if (e.key === 'Enter') e.preventDefault(); });
  // Ẩn chat AI khi sheet lọc (mobile) đang mở
  var sheet = document.getElementById('catfSheet');
  if (sheet) {
    sheet.addEventListener('shown.bs.offcanvas', function () { document.body.classList.add('catf-sheet-open'); });
    sheet.addEventListener('hidden.bs.offcanvas', function () { document.body.classList.remove('catf-sheet-open'); });
  }
  // Cây loại sản phẩm: checkbox nhóm tri-state (chọn nhóm = chọn mọi lá còn dùng được) + nhớ nhóm đã mở thủ công
  var openGroups = {};
  function syncTri() {
    document.querySelectorAll('#catfType .catf-par').forEach(function (par) {
      var st = par.getAttribute('data-state');
      par.indeterminate = st === 'some';
      par.checked = st === 'all';
    });
    document.querySelectorAll('#catfType details.catf-grp').forEach(function (d) {
      if (openGroups[d.getAttribute('data-gkey')]) d.open = true;
    });
  }
  function leavesOf(par) {
    var grp = par.closest('details.catf-grp');
    return grp ? grp.querySelectorAll('input[name="loai[]"]:not(:disabled)') : [];
  }
  if (form) {
    // Pha capture: cập nhật các lá TRƯỚC khi live-filter đọc form
    form.addEventListener('change', function (e) {
      var t = e.target;
      if (!t || !t.classList) return;
      if (t.classList.contains('catf-par')) {
        var on = t.checked;
        leavesOf(t).forEach(function (cb) { cb.checked = on; });
        t.setAttribute('data-state', on ? 'all' : 'none');
      } else if (t.name === 'loai[]') {
        var grp = t.closest('details.catf-grp');
        var par = grp && grp.querySelector('.catf-par');
        if (par) {
          var all = leavesOf(par), n = 0;
          all.forEach(function (cb) { if (cb.checked) n++; });
          par.setAttribute('data-state', n === 0 ? 'none' : (n === all.length ? 'all' : 'some'));
          par.indeterminate = n > 0 && n < all.length;
          par.checked = n === all.length && n > 0;
        }
      }
    }, true);
    form.addEventListener('toggle', function (e) {
      var d = e.target;
      if (d && d.matches && d.matches('details.catf-grp')) openGroups[d.getAttribute('data-gkey')] = d.open ? 1 : 0;
    }, true);
    form.addEventListener('live-filter:updated', syncTri);
  }
  syncTri();
  // Đồng bộ ô giá với giá trị server (vd. đã đảo từ > đến); không ghi đè khi đang gõ
  function syncPrice() {
    var note = document.querySelector('#catfPriceNote .catf-pn-data');
    if (!note) return;
    var ae = document.activeElement;
    if (ae && (ae.id === 'catfPriceMin' || ae.id === 'catfPriceMax')) return; // đang gõ giá: không ghi đè
    [['catfPriceMin', 'min'], ['catfPriceMax', 'max']].forEach(function (p) {
      var el = document.getElementById(p[0]);
      var v = note.getAttribute('data-' + p[1]) || '';
      if (el && document.activeElement !== el && el.value !== v) el.value = v;
    });
  }
  if (form) {
    form.addEventListener('live-filter:updated', syncPrice);
    form.addEventListener('focusout', function (e) {
      if (e.target && (e.target.id === 'catfPriceMin' || e.target.id === 'catfPriceMax')) setTimeout(syncPrice, 450);
    });
  }

  // Sidebar desktop: hiện dải "còn nội dung bên dưới" khi thẻ lọc còn cuộn được
  var side = document.querySelector('.catf-side');
  var card = side && side.querySelector('.catf-card');
  if (card) {
    var syncMore = function () {
      side.classList.toggle('has-more', window.innerWidth >= 992 && card.scrollHeight - card.clientHeight - card.scrollTop > 8);
    };
    card.addEventListener('scroll', syncMore, { passive: true });
    window.addEventListener('resize', syncMore);
    card.addEventListener('toggle', syncMore, true); // mở/đóng <details>
    if (form) form.addEventListener('live-filter:updated', syncMore);
    syncMore();
  }
})();
</script>
<?php endif; ?>
<link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/search-fallback.css?v=<?= @filemtime(dirname(__DIR__) . '/public/assets/css/search-fallback.css') ?: '1' ?>">
<script src="<?= BASE_URL ?>/assets/js/search-fallback.js?v=<?= @filemtime(dirname(__DIR__) . '/public/assets/js/search-fallback.js') ?: '1' ?>" defer></script>
<script src="<?= BASE_URL ?>/assets/js/search-more.js?v=<?= @filemtime(dirname(__DIR__) . '/public/assets/js/search-more.js') ?: '1' ?>" defer></script>
