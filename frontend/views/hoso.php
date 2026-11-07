<?php
$account = $account ?? [];
$orders = $orders ?? [];
$cartItems = $cartItems ?? [];
$skinProfile = $skinProfile ?? [];
$loaiDaOptions = $loaiDaOptions ?? [];
$khachHang = $khachHang ?? [];
$latestQuickSkinUpdate = $latestQuickSkinUpdate ?? null;
$customerVouchers = $customerVouchers ?? [];

$vanDeDaSaved = [];
if (!empty($skinProfile['van_de_da'])) {
  $vanDeDaSaved = array_map('trim', explode(',', (string)$skinProfile['van_de_da']));
}

$splitProfileValues = static function (?string $raw, array $excludePrefixes = []): array {
  $text = trim((string)($raw ?? ''));
  if ($text === '') {
    return [];
  }

  $parts = preg_split('/\s*[,|]\s*/u', $text) ?: [];
  $values = [];
  foreach ($parts as $part) {
    $value = trim((string)$part);
    if ($value === '') {
      continue;
    }

    $skip = false;
    foreach ($excludePrefixes as $prefix) {
      if (mb_stripos($value, $prefix) === 0) {
        $skip = true;
        break;
      }
    }

    if (!$skip) {
      $values[] = $value;
    }
  }

  return array_values(array_unique($values));
};

$parseConcernsForHoso = static function (?string $rawText) use ($splitProfileValues): array {
    $text = trim((string)($rawText ?? ''));
    if ($text === '') {
        return [];
    }

    $knownLabels = [
        'Da khô căng, bong tróc',
        'Mụn viêm (sưng đỏ, có mủ)',
        'Mụn ẩn, mụn đầu đen, sợi bã nhờn',
        'Thâm mụn, tàn nhang, sạm da',
        'Lão hóa, nếp nhăn, da chảy xệ',
        'Lỗ chân lông to'
    ];

    $foundConcerns = [];
    $remaining = $text;

    foreach ($knownLabels as $label) {
        if (mb_stripos($remaining, $label) !== false) {
            $foundConcerns[] = $label;
            $remaining = str_ireplace($label, '', $remaining);
        }
    }

    $canonicalIdMap = [
        'dry_flaky' => 'Da khô căng, bong tróc',
        'inflammatory_acne' => 'Mụn viêm (sưng đỏ, có mủ)',
        'comedonal_acne' => 'Mụn ẩn, mụn đầu đen, sợi bã nhờn',
        'large_pores' => 'Lỗ chân lông to',
        'hyperpigmentation' => 'Thâm mụn, tàn nhang, sạm da',
        'aging' => 'Lão hóa, nếp nhăn, da chảy xệ',
    ];

    $leftoverParts = preg_split('/\s*[,|]\s*/u', $remaining) ?: [];
    foreach ($leftoverParts as $part) {
        $p = trim($part);
        if ($p === '') continue;
        if (isset($canonicalIdMap[strtolower($p)])) {
            $foundConcerns[] = $canonicalIdMap[strtolower($p)];
        } else {
            if (mb_stripos($text, 'Da khô căng') !== false && (mb_stripos($p, 'da khô căng') !== false || mb_stripos($p, 'bong tróc') !== false)) {
                continue;
            }
            $foundConcerns[] = $p;
        }
    }

    return array_values(array_unique($foundConcerns));
};

$parsePrioritiesForHoso = static function (?string $rawText): array {
    $text = trim((string)($rawText ?? ''));
    $textures = [];
    $ingredients = [];

    if ($text === '') {
        return ['textures' => [], 'ingredients' => []];
    }

    $parts = explode('|', $text);
    foreach ($parts as $part) {
        $p = trim($part);
        if (mb_stripos($p, 'Kết cấu:') === 0) {
            $sub = trim(mb_substr($p, mb_strlen('Kết cấu:')));
            if ($sub !== '') {
                $textures = array_map('trim', explode(',', $sub));
            }
        } elseif (mb_stripos($p, 'Hoạt chất:') === 0) {
            $sub = trim(mb_substr($p, mb_strlen('Hoạt chất:')));
            if ($sub !== '') {
                $ingredients = array_map('trim', explode(',', $sub));
            }
        } else {
            if (mb_stripos($p, 'Kết cấu') === false && mb_stripos($p, 'Hoạt chất') === false) {
                $ingredients[] = $p;
            }
        }
    }

    return [
        'textures' => array_values(array_unique(array_filter($textures))),
        'ingredients' => array_values(array_unique(array_filter($ingredients))),
    ];
};

$splitSpecialStates = static function (?string $raw): array {
    $text = trim((string)($raw ?? ''));
    if ($text === '') return [];
    $parts = explode('|', $text);
    $clean = [];
    foreach ($parts as $part) {
        $p = trim($part);
        if ($p === '') continue;
        if (mb_stripos($p, 'loaida:') === 0
            || mb_stripos($p, 'khảo sát') !== false
            || mb_stripos($p, 'xuất xứ') !== false
            || mb_stripos($p, 'thương hiệu') !== false) {
            continue;
        }
        $clean[] = $p;
    }
    return array_values(array_unique($clean));
};

$renderTagList = static function (array $items, string $emptyText = 'Chưa có dữ liệu'): string {
  if (empty($items)) {
    return '<span class="text-muted">' . h($emptyText) . '</span>';
  }

  $html = [];
  foreach ($items as $item) {
    $html[] = '<span class="badge rounded-pill text-bg-light border me-2 mb-2 px-3 py-2">' . h((string)$item) . '</span>';
  }

  return implode('', $html);
};

$surveySkinIssues = $parseConcernsForHoso(!empty($skinProfile['van_de_da']) ? (string)$skinProfile['van_de_da'] : (string)($khachHang['van_de_da'] ?? ''));
$surveySpecialStates = $splitSpecialStates((string)($khachHang['tinh_trang_dac_biet'] ?? ''));

$prioritiesParsed = $parsePrioritiesForHoso((string)($khachHang['tieu_chi_uu_tien'] ?? ''));
$surveyTextures = $prioritiesParsed['textures'];
$surveyIngredients = $prioritiesParsed['ingredients'];

$surveyAvoidIngredients = $splitProfileValues((string)($khachHang['thanh_phan_tranh'] ?? ''));
$surveyPreferredCountries = $splitProfileValues((string)($khachHang['kinh_nghiem_skincare'] ?? ''));
$surveyPreferredBrands = $splitProfileValues((string)($khachHang['so_buoc_skincare'] ?? ''));

$formattedBudget = !empty($skinProfile['ngan_sach'])
  ? number_format((int)$skinProfile['ngan_sach'], 0, ',', '.') . 'đ'
  : 'Chưa có dữ liệu';

$vipThreshold = 500;
$diamondThreshold = 1500;
$currentPoints = max(0, (int)($khachHang['diemtl'] ?? 0));
$currentTier = trim((string)($khachHang['loaikh'] ?? 'Thuong')) ?: 'Thuong';
$tierLabelMap = [
  'thuong' => 'Thường',
  'vip' => 'VIP',
  'kim cuong' => 'Kim Cương',
  'kim cương' => 'Kim Cương',
];
$normalizedTierKey = function_exists('mb_strtolower') ? mb_strtolower($currentTier, 'UTF-8') : strtolower($currentTier);
$displayTier = $tierLabelMap[$normalizedTierKey] ?? $currentTier;
$pointsToVip = max(0, $vipThreshold - $currentPoints);
$pointsToDiamond = max(0, $diamondThreshold - $currentPoints);
$loyaltyHint = 'Bạn đã ở hạng Thường.';
$loyaltyProgressPercent = 0;
$loyaltyProgressLabel = '';
$loyaltyProgressHint = '';
$nextTierLabel = '';

if ($currentPoints >= $diamondThreshold) {
  $loyaltyHint = 'Bạn đang ở hạng Kim Cương, đây là hạng thành viên cao nhất hiện tại.';
  $loyaltyProgressPercent = 100;
  $loyaltyProgressLabel = number_format($diamondThreshold, 0, ',', '.') . ' / ' . number_format($diamondThreshold, 0, ',', '.') . ' điểm';
  $loyaltyProgressHint = 'Đã mở khóa toàn bộ quyền lợi hạng thành viên hiện tại.';
} elseif ($currentPoints >= $vipThreshold) {
  $loyaltyHint = 'Bạn đang ở hạng VIP. Cần thêm ' . number_format($pointsToDiamond, 0, ',', '.') . ' điểm để lên Kim Cương.';
  $loyaltyProgressPercent = (($currentPoints - $vipThreshold) / max(1, $diamondThreshold - $vipThreshold)) * 100;
  $loyaltyProgressLabel = number_format($currentPoints, 0, ',', '.') . ' / ' . number_format($diamondThreshold, 0, ',', '.') . ' điểm';
  $loyaltyProgressHint = 'Còn ' . number_format($pointsToDiamond, 0, ',', '.') . ' điểm để lên Kim Cương.';
  $nextTierLabel = 'Kim Cương';
} else {
  $loyaltyHint = 'Cần thêm ' . number_format($pointsToVip, 0, ',', '.') . ' điểm để lên VIP và ' . number_format($pointsToDiamond, 0, ',', '.') . ' điểm để lên Kim Cương.';
  $loyaltyProgressPercent = ($currentPoints / max(1, $vipThreshold)) * 100;
  $loyaltyProgressLabel = number_format($currentPoints, 0, ',', '.') . ' / ' . number_format($vipThreshold, 0, ',', '.') . ' điểm';
  $loyaltyProgressHint = 'Còn ' . number_format($pointsToVip, 0, ',', '.') . ' điểm để lên VIP.';
  $nextTierLabel = 'VIP';
}

$loyaltyProgressPercent = max(0, min(100, (float)$loyaltyProgressPercent));

$ngayThamGia = $account['ngay_tao'] ?? null;
$membershipDurationHint = 'Mốc thời gian hệ thống bắt đầu ghi nhận tài khoản của bạn.';
if (!empty($ngayThamGia)) {
  $joinedTimestamp = strtotime((string)$ngayThamGia);
  if ($joinedTimestamp !== false) {
    $daysSinceJoined = max(0, (int)floor((time() - $joinedTimestamp) / 86400));
    if ($daysSinceJoined === 0) {
      $membershipDurationHint = 'Bạn vừa tham gia SkinSyntax hôm nay.';
    } elseif ($daysSinceJoined === 1) {
      $membershipDurationHint = 'Bạn đã đồng hành cùng SkinSyntax được 1 ngày.';
    } else {
      $membershipDurationHint = 'Bạn đã đồng hành cùng SkinSyntax được ' . number_format($daysSinceJoined, 0, ',', '.') . ' ngày.';
    }
  }
}

// $orders chỉ là trang đơn đang xem (10 đơn); tổng số đơn/đánh giá do controller đếm trên toàn bộ.
$orderTab = $orderTab ?? 'all';
$orderQuery = $orderQuery ?? '';
$orderCounts = $orderCounts ?? OrderTabs::counts($orders);
$orderPage = $orderPage ?? ['page' => 1, 'pages' => 1, 'matched' => count($orders)];
$orderCount = (int)($orderCounts['all'] ?? count($orders));
$reviewSentCount = (int)($reviewSentCount ?? 0);

$accountVerificationLabel = !empty($account['email']) ? 'Đã xác thực' : 'Chưa xác thực';
$accountVerificationHint = !empty($account['email'])
  ? 'Email đăng nhập đã được ghi nhận trong hệ thống.'
  : 'Tài khoản chưa có đủ thông tin xác thực email.';
?>

<style>
  .loyalty-progress-card {
    margin-top: 14px;
    padding: 16px;
    border-radius: 12px;
    background: #FFFFFF;
    border: 1px solid var(--border);
  }

  .loyalty-progress-head {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    margin-bottom: 8px;
  }

  .loyalty-progress-title {
    font-size: 0.82rem;
    font-weight: 600;
    color: #0F172A;
    letter-spacing: 0.01em;
  }

  .loyalty-progress-target {
    font-size: 0.78rem;
    font-weight: 600;
    color: #183B2B;
    background: #EBF2EE;
    border-radius: 4px;
    padding: 4px 10px;
    border: 1px solid #C8DACF;
  }

  .loyalty-tier-row {
    display: flex;
    align-items: center;
    justify-content: flex-start;
    gap: 12px;
    flex-wrap: wrap;
    margin-top: 4px;
  }

  .profile-overview-grid {
    display: grid;
    grid-template-columns: repeat(6, minmax(0, 1fr));
    gap: 16px;
    align-items: stretch;
  }

  .profile-overview-grid__wide {
    grid-column: span 2;
  }

  .profile-overview-grid__compact {
    grid-column: span 1;
  }

  .profile-overview-grid .profile-stat {
    height: 100%;
    min-height: 100%;
    padding: 16px;
    display: flex;
    flex-direction: column;
    justify-content: space-between;
    border-radius: 12px;
    border: 1px solid var(--border);
    background: #FFFFFF;
  }

  .profile-overview-grid > div {
    min-width: 0;
    display: flex;
  }

  .profile-overview-grid .profile-stat .value {
    word-break: break-word;
    font-variant-numeric: tabular-nums;
  }

  .profile-stat-stack {
    display: grid;
    gap: 10px;
  }

  .profile-stat-badge {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: fit-content;
    min-height: 28px;
    padding: 0 10px;
    border-radius: 4px;
    background: #EBF2EE;
    color: #183B2B;
    border: 1px solid #C8DACF;
    font-size: 0.78rem;
    font-weight: 600;
  }

  .profile-stat-note {
    color: #64748B;
    font-size: 0.82rem;
    line-height: 1.5;
  }

  .loyalty-tier-name {
    font-size: 1.6rem;
    line-height: 1;
    font-weight: 700;
    color: #183B2B;
  }

  .loyalty-progress-bar {
    position: relative;
    overflow: hidden;
    height: 8px;
    border-radius: 4px;
    background: #E2E8F0;
    margin-top: 10px;
  }

  .loyalty-progress-fill {
    height: 100%;
    border-radius: inherit;
    background: #183B2B;
  }

  .loyalty-progress-scale {
    display: flex;
    justify-content: space-between;
    gap: 10px;
    margin-top: 8px;
    color: #64748B;
    font-size: 0.75rem;
    font-weight: 500;
  }

  .loyalty-progress-meta {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    margin-top: 12px;
    flex-wrap: wrap;
  }

  .loyalty-progress-points {
    font-size: 0.95rem;
    font-weight: 700;
    color: #0F172A;
    font-variant-numeric: tabular-nums;
  }

  .loyalty-progress-note {
    color: #64748B;
    font-size: 0.82rem;
    line-height: 1.5;
  }

  /* Lịch sử đơn hàng: tab con kiểu Shopee */
  .order-tabs {
    display: flex;
    gap: 4px;
    overflow-x: auto;
    border-bottom: 1px solid var(--border);
    margin-bottom: 14px;
    scrollbar-width: thin;
  }

  .order-tab {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    flex: 0 0 auto;
    padding: 10px 14px;
    color: #4b5563;
    font-weight: 600;
    text-decoration: none;
    border-bottom: 2px solid transparent;
    margin-bottom: -1px;
    white-space: nowrap;
  }

  .order-tab:hover {
    color: #1f7a53;
  }

  .order-tab.is-active {
    color: #1f7a53;
    border-bottom-color: #1f7a53;
  }

  .order-tab:focus-visible {
    outline: 2px solid #1f7a53;
    outline-offset: 2px;
    border-radius: 6px;
  }

  .order-tab-count {
    min-width: 22px;
    padding: 1px 7px;
    border-radius: 999px;
    background: #eef5f0;
    color: #236142;
    font-size: 0.78rem;
    font-variant-numeric: tabular-nums;
    text-align: center;
  }

  .order-tab.is-active .order-tab-count {
    background: #1f7a53;
    color: #fff;
  }

  .order-search {
    display: flex;
    gap: 8px;
    align-items: center;
    margin-bottom: 16px;
  }

  .order-search .form-control {
    max-width: 420px;
  }

  #orderResults[aria-busy="true"] {
    opacity: 0.55;
    transition: opacity 0.15s ease;
  }

  .order-empty {
    padding: 36px 16px;
    border: 1px dashed var(--border);
    border-radius: 12px;
    color: #6b7280;
    text-align: center;
  }

  .order-empty a {
    display: block;
    margin-top: 6px;
    color: #1f7a53;
    font-weight: 600;
  }

  .order-card-list {
    display: grid;
    gap: 14px;
  }

  .order-card {
    border: 1px solid var(--border);
    border-radius: 12px;
    background: #fff;
    overflow: hidden;
  }

  .order-card-head {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    gap: 12px;
    padding: 12px 16px;
    border-bottom: 1px solid #eef2f0;
  }

  .order-card-title {
    margin: 0;
    font-size: 1rem;
    font-weight: 700;
    color: #16324d;
  }

  .order-card-sub {
    margin-top: 2px;
    color: #6b7280;
    font-size: 0.86rem;
  }

  .order-status {
    flex: 0 0 auto;
    padding: 4px 12px;
    border-radius: 999px;
    font-size: 0.82rem;
    font-weight: 700;
    white-space: nowrap;
  }

  .order-status-success { background: #e8f5ee; color: #1f7a53; }
  .order-status-warning { background: #fff4db; color: #8a5a00; }
  .order-status-info { background: #e8f1fb; color: #1f5f99; }
  .order-status-primary { background: #e9ecfb; color: #3442a8; }
  .order-status-danger { background: #fdecec; color: #b42318; }
  .order-status-secondary { background: #f1f3f5; color: #4b5563; }

  .order-card-alert {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 12px;
    margin: 12px 16px 0;
    padding: 10px 14px;
    border-radius: 10px;
    background: #fff8e6;
    color: #6b4a00;
  }

  .order-card .order-product-list {
    padding: 12px 16px;
  }

  .order-card-note {
    margin: 0 16px 12px;
    color: #6b7280;
    font-size: 0.88rem;
  }

  .order-card-foot {
    display: grid;
    grid-template-columns: minmax(0, 1fr) auto;
    align-items: center;
    gap: 8px 16px;
    padding: 12px 16px;
    border-top: 1px solid #eef2f0;
    background: #fafcfb;
  }

  .order-card-points {
    display: flex;
    flex-wrap: wrap;
    gap: 6px;
    align-items: center;
    font-size: 0.88rem;
  }

  .order-card-total {
    text-align: right;
    color: #4b5563;
  }

  .order-card-total strong {
    color: #b42318;
    font-size: 1.08rem;
    font-variant-numeric: tabular-nums;
  }

  .order-card-actions {
    grid-column: 1 / -1;
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
    justify-content: flex-end;
    align-items: flex-start;
  }

  .order-card-actions form {
    margin: 0;
  }

  .order-cancel > summary {
    list-style: none;
  }

  .order-cancel > summary::-webkit-details-marker {
    display: none;
  }

  .order-cancel-form {
    display: grid;
    gap: 8px;
    width: min(320px, 100%);
    margin-top: 8px;
  }

  .order-status-note.is-muted {
    background: #f3f4f6;
    color: #6b7280;
  }

  .order-pagination {
    display: flex;
    justify-content: center;
    align-items: center;
    gap: 12px;
    margin-top: 16px;
  }

  .order-pagination-info {
    color: #6b7280;
    font-size: 0.9rem;
  }

  @media (max-width: 575.98px) {
    .order-search .form-control {
      max-width: none;
    }

    .order-card-head,
    .order-card-alert {
      flex-direction: column;
      align-items: flex-start;
    }

    .order-card-foot {
      grid-template-columns: 1fr;
    }

    .order-card-total {
      text-align: left;
    }

    .order-card-actions {
      justify-content: stretch;
    }

    .order-card-actions > * {
      flex: 1 1 auto;
    }
  }

  .order-product-list {
    display: grid;
    gap: 10px;
  }

  .order-product-card {
    display: grid;
    grid-template-columns: minmax(0, 1fr) auto;
    gap: 14px;
    align-items: center;
    padding: 12px 14px;
    border: 1px solid var(--border);
    border-radius: 8px;
    background: #fff;
  }

  .order-product-card[data-detail-url] {
    cursor: pointer;
  }

  .order-product-main {
    display: grid;
    grid-template-columns: 56px minmax(0, 1fr);
    gap: 14px;
    align-items: center;
    min-width: 0;
  }

  .order-product-thumb {
    width: 56px;
    height: 56px;
    border-radius: 14px;
    object-fit: cover;
    background: #f4f7f5;
  }

  .order-product-name {
    font-weight: 700;
    color: #16324d;
  }

  .order-product-link {
    display: block;
    color: inherit;
    text-decoration: none;
  }

  .order-product-link:hover {
    color: inherit;
  }

  .order-product-link:hover .order-product-name {
    color: #1f7a53;
  }

  .order-product-meta {
    color: #6b7280;
    font-size: 0.92rem;
    margin-top: 2px;
  }

  .order-product-actions {
    display: flex;
    gap: 10px;
    flex-wrap: wrap;
    justify-content: flex-end;
    align-items: center;
  }

  .order-product-actions form {
    margin: 0;
  }

  .order-detail-toggle {
    white-space: nowrap;
  }

  .order-detail-panel {
    border-top: 1px solid #e5ecef;
  }

  .order-status-note {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    padding: 7px 12px;
    border-radius: 999px;
    background: #f6faf7;
    color: #236142;
    font-size: 0.84rem;
    font-weight: 700;
  }

  @media (max-width: 1199.98px) {
    .profile-overview-grid {
      grid-template-columns: repeat(2, minmax(0, 1fr));
      gap: 14px;
    }

    .profile-overview-grid__wide,
    .profile-overview-grid__compact {
      grid-column: span 1;
    }
  }

  @media (max-width: 767.98px) {
    .profile-overview-grid {
      grid-template-columns: 1fr;
      gap: 12px;
    }

    .profile-overview-grid .profile-stat {
      min-height: 0;
    }

    .loyalty-progress-card {
      padding: 12px 14px;
    }

    .order-product-card {
      grid-template-columns: 1fr;
    }

    .order-product-actions {
      justify-content: flex-start;
    }
  }
</style>

<script>
window.switchHosoTab = function (targetId, evt) {
  if (evt) {
    if (typeof evt.preventDefault === 'function') evt.preventDefault();
    if (typeof evt.stopPropagation === 'function') evt.stopPropagation();
  }

  if (!targetId) return false;
  var cleanId = targetId.indexOf('#') === 0 ? targetId : '#' + targetId;
  var cleanPaneId = cleanId.replace('#', '');

  var buttons = document.querySelectorAll('#accountTabs .nav-link');
  buttons.forEach(function (btn) {
    var href = btn.getAttribute('href') || btn.getAttribute('onclick') || '';
    if (href.indexOf(cleanPaneId) !== -1 || btn.id === 'btn-' + cleanPaneId) {
      btn.classList.add('active');
      btn.setAttribute('aria-selected', 'true');
    } else if (href.indexOf('#tab-') !== -1) {
      btn.classList.remove('active');
      btn.setAttribute('aria-selected', 'false');
    }
  });

  var panes = document.querySelectorAll('.tab-content > .tab-pane');
  panes.forEach(function (pane) {
    if (pane.id === cleanPaneId) {
      pane.classList.add('show', 'active');
      pane.style.display = 'block';
    } else {
      pane.classList.remove('show', 'active');
      pane.style.display = 'none';
    }
  });

  return false;
};

window.openSupportChat = function (evt) {
  if (evt) {
    if (typeof evt.preventDefault === 'function') evt.preventDefault();
    if (typeof evt.stopPropagation === 'function') evt.stopPropagation();
  }
  var widgetPanel = document.querySelector('[data-support-chat-panel]');
  var triggerBtn = document.querySelector('.support-chat-widget__trigger');
  if (widgetPanel) {
    widgetPanel.hidden = false;
    var widget = widgetPanel.closest('[data-support-chat-widget]');
    if (widget) widget.classList.add('is-open');
    var textarea = widgetPanel.querySelector('textarea');
    if (textarea) textarea.focus();
    return false;
  }
  if (triggerBtn) {
    triggerBtn.click();
    return false;
  }
  window.location.href = '<?= BASE_URL ?>/index.php?r=lichsuchat';
  return false;
};
</script>

<div class="container mt-4 profile-shell">
  <div class="d-flex flex-wrap align-items-center justify-content-between mb-3">
    <h3 class="section-title mb-2 mb-md-0">Tài khoản của tôi</h3>
    <a class="btn btn-outline-brand" href="<?= BASE_URL ?>/index.php?r=tatca">Tiếp tục mua sắm</a>
  </div>

  <div class="auth-card">
    <ul class="nav nav-tabs" id="accountTabs" role="tablist" style="border-bottom: 2px solid #E2E8F0; position: relative; z-index: 20;">
      <li class="nav-item" role="presentation">
        <a class="nav-link active" id="btn-tab-overview" href="#tab-overview" role="tab" style="cursor: pointer; pointer-events: auto;" onclick="return switchHosoTab('#tab-overview', event)">Tổng quan</a>
      </li>
      <li class="nav-item" role="presentation">
        <a class="nav-link" id="btn-tab-orders" href="#tab-orders" role="tab" style="cursor: pointer; pointer-events: auto;" onclick="return switchHosoTab('#tab-orders', event)">Lịch sử đơn hàng</a>
      </li>
      <li class="nav-item" role="presentation">
        <a class="nav-link" id="btn-tab-cart" href="#tab-cart" role="tab" style="cursor: pointer; pointer-events: auto;" onclick="return switchHosoTab('#tab-cart', event)">Giỏ hàng của tôi</a>
      </li>
      <li class="nav-item" role="presentation">
        <a class="nav-link" id="btn-tab-skin" href="#tab-skin" role="tab" style="cursor: pointer; pointer-events: auto;" onclick="return switchHosoTab('#tab-skin', event)">Hồ sơ Làn da</a>
      </li>
      <li class="nav-item" role="presentation">
        <a class="nav-link" id="btn-tab-vouchers" href="#tab-vouchers" role="tab" style="cursor: pointer; pointer-events: auto;" onclick="return switchHosoTab('#tab-vouchers', event)">
          <i class="fas fa-ticket-alt text-success me-1"></i>Ví Voucher <span class="badge bg-success rounded-pill ms-1" style="font-size: 0.72rem;"><?= count($customerVouchers) ?></span>
        </a>
      </li>
      <li class="nav-item" role="presentation">
        <button class="nav-link" type="button" data-support-chat-toggle style="cursor: pointer; pointer-events: auto;" onclick="return openSupportChat(event)">Chat hỗ trợ</button>
      </li>
    </ul>

    <div class="tab-content pt-3">
      <div class="tab-pane fade show active" id="tab-overview" role="tabpanel" style="display: block;">
        <div class="profile-overview-grid">
          <div class="profile-overview-grid__wide">
            <div class="profile-stat">
              <div class="label">Loại KH</div>
              <div class="loyalty-tier-row">
                <div class="loyalty-tier-name"><?= h($displayTier) ?></div>
              </div>
              <div class="small text-muted mt-2"><?= h($loyaltyHint) ?></div>
              <div class="loyalty-progress-card">
                <div class="loyalty-progress-head">
                  <div class="loyalty-progress-title">Tiến độ hạng thành viên</div>
                  <?php if ($nextTierLabel !== ''): ?>
                    <div class="loyalty-progress-target">Mục tiêu: <?= h($nextTierLabel) ?></div>
                  <?php else: ?>
                    <div class="loyalty-progress-target">Đã đạt tối đa</div>
                  <?php endif; ?>
                </div>
                <div class="loyalty-progress-bar" aria-hidden="true">
                  <div class="loyalty-progress-fill" style="width: <?= h(number_format($loyaltyProgressPercent, 2, '.', '')) ?>%;"></div>
                </div>
                <div class="loyalty-progress-scale">
                  <span>0</span>
                  <span><?= number_format($vipThreshold, 0, ',', '.') ?></span>
                  <span><?= number_format($diamondThreshold, 0, ',', '.') ?></span>
                </div>
                <div class="loyalty-progress-meta">
                  <div class="loyalty-progress-points"><?= h($loyaltyProgressLabel) ?></div>
                  <div class="loyalty-progress-note"><?= h($loyaltyProgressHint) ?></div>
                </div>
              </div>
            </div>
          </div>
          <div class="profile-overview-grid__wide">
            <div class="profile-stat">
              <div class="label">Ngày tham gia</div>
              <div class="profile-stat-stack">
                <div class="value"><?= h($ngayThamGia ? date('d/m/Y', strtotime((string)$ngayThamGia)) : 'N/A') ?></div>
                <div class="profile-stat-badge">Tài khoản <?= h($accountVerificationLabel) ?></div>
              </div>
              <div class="profile-stat-note mt-2"><?= h($membershipDurationHint) ?></div>
              <div class="profile-stat-note"><?= h($accountVerificationHint) ?></div>
            </div>
          </div>
          <div class="profile-overview-grid__compact">
            <div class="profile-stat">
              <div class="label">Số đơn hàng</div>
              <div class="value"><?= number_format($orderCount, 0, ',', '.') ?></div>
              <div class="profile-stat-note mt-2">Tổng số đơn hàng đã được ghi nhận trên tài khoản của bạn.</div>
            </div>
          </div>
          <div class="profile-overview-grid__compact">
            <div class="profile-stat">
              <div class="label">Đánh giá đã gửi</div>
              <div class="value"><?= number_format($reviewSentCount, 0, ',', '.') ?></div>
              <div class="profile-stat-note mt-2">Số lượt đánh giá sản phẩm bạn đã gửi thành công từ lịch sử mua hàng.</div>
            </div>
          </div>
        </div>

        <div class="mt-3">
          <div class="row g-3">
            <div class="col-12 col-lg-7">
              <div class="border rounded-3 p-3 bg-white">
                <h6 class="mb-3">Cập nhật thông tin tài khoản</h6>
                <form id="accountInfoForm" class="row g-2">
                  <div class="col-md-6">
                    <label for="profileFullName" class="form-label">Họ tên</label>
                    <input class="form-control" type="text" id="profileFullName" name="ho_ten" autocomplete="name" value="<?= h($khachHang['ho_ten'] ?? ($account['ho_ten'] ?? '')) ?>" required>
                  </div>
                  <div class="col-md-6">
                    <label for="profileEmail" class="form-label">Email</label>
                    <input class="form-control" type="email" id="profileEmail" autocomplete="email" value="<?= h($account['email'] ?? '') ?>" disabled>
                  </div>
                  <div class="col-md-6">
                    <label for="profileGender" class="form-label">Giới tính</label>
                    <?php $selectedGender = (string)($khachHang['gioi_tinh'] ?? ''); ?>
                    <select class="form-select" id="profileGender" name="gioi_tinh">
                      <option value="">-- Chọn giới tính --</option>
                      <option value="Nữ" <?= ($selectedGender === 'Nữ' ? 'selected' : '') ?>>Nữ</option>
                      <option value="Nam" <?= ($selectedGender === 'Nam' ? 'selected' : '') ?>>Nam</option>
                      <option value="Khác" <?= ($selectedGender === 'Khác' ? 'selected' : '') ?>>Khác</option>
                    </select>
                  </div>
                  <div class="col-md-6">
                    <label for="profileBirthYear" class="form-label">Năm sinh</label>
                    <input class="form-control" type="number" id="profileBirthYear" min="1900" max="<?= date('Y') ?>" name="nam_sinh" autocomplete="bday-year" value="<?= h($khachHang['nam_sinh'] ?? '') ?>">
                  </div>
                  <div class="col-md-6">
                    <label for="profilePhone" class="form-label">Số điện thoại</label>
                    <input class="form-control" type="text" id="profilePhone" name="so_dien_thoai" autocomplete="tel" value="<?= h($khachHang['so_dien_thoai'] ?? '') ?>">
                  </div>
                  <div class="col-md-6">
                    <label for="profileAddress" class="form-label">Địa chỉ</label>
                    <input class="form-control" type="text" id="profileAddress" name="dia_chi" autocomplete="street-address" value="<?= h($khachHang['dia_chi'] ?? '') ?>">
                  </div>
                  <div class="col-12 d-flex align-items-center gap-2">
                    <button class="btn btn-brand" type="submit">Lưu thông tin</button>
                    <span id="accountInfoMsg" class="small"></span>
                  </div>
                </form>
              </div>
            </div>

            <div class="col-12 col-lg-5">
              <div class="border rounded-3 p-3 bg-white">
                <h6 class="mb-3">Đổi mật khẩu</h6>
                <form id="changePasswordForm" class="row g-2">
                  <div class="col-12">
                    <label for="profileCurrentPassword" class="form-label">Mật khẩu hiện tại</label>
                    <input class="form-control" type="password" id="profileCurrentPassword" name="mat_khau_hien_tai" autocomplete="current-password" placeholder="Có thể bỏ trống khi đã đăng nhập">
                  </div>
                  <div class="col-12">
                    <label for="profileNewPassword" class="form-label">Mật khẩu mới</label>
                    <input class="form-control" type="password" id="profileNewPassword" name="mat_khau_moi" autocomplete="new-password" required>
                  </div>
                  <div class="col-12">
                    <label for="profileConfirmPassword" class="form-label">Xác nhận mật khẩu mới</label>
                    <input class="form-control" type="password" id="profileConfirmPassword" name="xac_nhan_mat_khau" autocomplete="new-password" required>
                  </div>
                  <div class="col-12 d-flex align-items-center gap-2">
                    <button class="btn btn-outline-brand" type="submit">Đổi mật khẩu</button>
                    <span id="changePasswordMsg" class="small"></span>
                  </div>
                </form>
              </div>
            </div>
          </div>
        </div>
      </div>

      <div class="tab-pane fade" id="tab-orders" role="tabpanel">
        <?php
          $orderTabUrl = static function (string $tab, int $page = 1) use ($orderQuery): string {
              $params = ['r' => 'hoso', 'tab' => 'orders', 'don' => $tab];
              if ($orderQuery !== '') $params['q_don'] = $orderQuery;
              if ($page > 1) $params['trang_don'] = $page;
              return BASE_URL . '/index.php?' . http_build_query($params) . '#tab-orders';
          };
          $orderTabTone = [
              'cho_thanh_toan' => 'warning', 'cho_xac_nhan' => 'info', 'cho_lay_hang' => 'info',
              'dang_giao' => 'primary', 'hoan_thanh' => 'success', 'da_huy' => 'danger',
          ];
          $orderEmptyText = [
              'cho_thanh_toan' => 'Không có đơn nào đang chờ thanh toán.',
              'cho_xac_nhan' => 'Không có đơn nào đang chờ shop xác nhận.',
              'cho_lay_hang' => 'Không có đơn nào đang chờ lấy hàng.',
              'dang_giao' => 'Không có đơn nào đang trên đường giao.',
              'hoan_thanh' => 'Chưa có đơn nào hoàn thành.',
              'da_huy' => 'Không có đơn nào đã hủy.',
          ];
          $placeholderImage = 'data:image/svg+xml,%3Csvg xmlns=%22http://www.w3.org/2000/svg%22 width=%2256%22 height=%2256%22 viewBox=%220 0 56 56%22%3E%3Crect width=%2256%22 height=%2256%22 rx=%2214%22 fill=%22%23edf4ef%22/%3E%3Cpath d=%22M16 37l8-9 6 7 5-5 6 7H16z%22 fill=%22%2396aca0%22/%3E%3Ccircle cx=%2222%22 cy=%2221%22 r=%224%22 fill=%22%23bfd0c5%22/%3E%3C/svg%3E';
        ?>
        <nav class="order-tabs" id="orderTabsNav" aria-label="Lọc đơn hàng theo trạng thái">
          <?php foreach (OrderTabs::TABS as $tabKey => $tabLabel): ?>
            <?php $isActiveTab = $tabKey === $orderTab; ?>
            <a class="order-tab<?= $isActiveTab ? ' is-active' : '' ?>" href="<?= h($orderTabUrl($tabKey)) ?>"<?= $isActiveTab ? ' aria-current="page"' : '' ?>>
              <span><?= h($tabLabel) ?></span>
              <?php if ((int)($orderCounts[$tabKey] ?? 0) > 0): ?>
                <span class="order-tab-count"><?= number_format((int)$orderCounts[$tabKey], 0, ',', '.') ?></span>
              <?php endif; ?>
            </a>
          <?php endforeach; ?>
        </nav>

        <form class="order-search" method="get" action="<?= BASE_URL ?>/index.php" role="search"
              data-live-filter data-live-target="#orderResults" data-live-also="#orderTabsNav" data-live-reset="trang_don">
          <input type="hidden" name="r" value="hoso">
          <input type="hidden" name="tab" value="orders">
          <input type="hidden" name="don" value="<?= h($orderTab) ?>">
          <label class="visually-hidden" for="orderSearchInput">Tìm đơn hàng</label>
          <input class="form-control" id="orderSearchInput" type="search" name="q_don" maxlength="80" value="<?= h($orderQuery) ?>" placeholder="Tìm theo mã đơn hoặc tên sản phẩm" autocomplete="off">
          <button class="btn btn-outline-brand" type="submit">Tìm</button>
        </form>

        <div id="orderResults" aria-live="polite">
        <?php if ($orderCount === 0): ?>
          <div class="order-empty">Bạn chưa có đơn hàng nào.</div>
        <?php elseif (empty($orders)): ?>
          <div class="order-empty">
            <?= h($orderQuery !== '' ? 'Không tìm thấy đơn nào khớp "' . $orderQuery . '" ở mục này.' : ($orderEmptyText[$orderTab] ?? 'Không có đơn nào ở mục này.')) ?>
            <?php if ($orderTab !== 'all' || $orderQuery !== ''): ?>
              <a href="<?= h(BASE_URL . '/index.php?r=hoso&tab=orders#tab-orders') ?>">Xem tất cả đơn</a>
            <?php endif; ?>
          </div>
        <?php else: ?>
          <div class="order-card-list">
            <?php foreach ($orders as $o): ?>
              <?php $orderId = (string)($o['ma_hoa_don'] ?? ''); ?>
              <?php $tabKey = (string)($o['order_tab'] ?? OrderTabs::tabOf($o)); ?>
              <?php $actions = $o['order_actions'] ?? OrderTabs::actions($o); ?>
              <?php $isQr = OrderTabs::isQrPayment($o); ?>
              <?php $paymentState = (string)($o['payment_state'] ?? OrderTabs::paymentState($o)); ?>
              <?php $statusLabel = OrderTabs::TABS[$tabKey] ?? (string)($o['trang_thai'] ?? 'Không rõ'); ?>
              <article class="order-card" aria-labelledby="order-title-<?= h($orderId) ?>">
                <header class="order-card-head">
                  <div>
                    <h3 class="order-card-title" id="order-title-<?= h($orderId) ?>">Đơn #<?= h($orderId) ?></h3>
                    <div class="order-card-sub">
                      <?= h(!empty($o['ngay_dat']) ? date('d/m/Y H:i', strtotime((string)$o['ngay_dat'])) : '') ?>
                      · <?= $isQr ? 'QR chuyển khoản' : 'COD' ?>
                      · <?= h($paymentState === 'paid' ? 'Đã thanh toán' : ($paymentState === 'expired' ? 'Quá hạn thanh toán' : ($paymentState === 'awaiting' ? 'Chờ chuyển khoản' : 'Chưa thanh toán'))) ?>
                    </div>
                  </div>
                  <span class="order-status order-status-<?= h($orderTabTone[$tabKey] ?? 'secondary') ?>"><?= h($statusLabel) ?></span>
                </header>

                <?php if (!empty($actions['pay_qr'])): ?>
                  <div class="order-card-alert">
                    <div>
                      <strong>Đơn này đang chờ chuyển khoản.</strong>
                      <div class="small">Mở lại màn hình QR để thanh toán đúng mã đơn #<?= h($orderId) ?>.</div>
                    </div>
                    <a class="btn btn-sm btn-success" href="<?= BASE_URL ?>/index.php?r=camon&ma_hoa_don=<?= urlencode($orderId) ?>">Thanh toán ngay</a>
                  </div>
                <?php endif; ?>

                <?php if (empty($o['items'])): ?>
                  <div class="small text-muted px-3 py-2">Đơn hàng này chưa có chi tiết sản phẩm hiển thị.</div>
                <?php else: ?>
                  <div class="order-product-list">
                    <?php foreach ($o['items'] as $item): ?>
                      <?php $canReviewFromOrder = !empty($item['has_purchased']) || !empty($actions['review']); ?>
                      <?php $productId = trim((string)($item['ma_san_pham'] ?? '')); ?>
                      <?php $resolvedDetailUrl = $productId !== '' ? (BASE_URL . '/index.php?r=chitiet&id=' . rawurlencode($productId) . '&tab=danh-gia') : ''; ?>
                      <?php $productImage = trim((string)($item['image_url'] ?? '')); ?>
                      <?php if ($productImage === ''): ?>
                        <?php $productImage = resolve_image_url((string)($item['link_hinh_anh'] ?? '')); ?>
                      <?php endif; ?>
                      <div class="order-product-card"<?= $resolvedDetailUrl !== '' ? ' data-detail-url="' . h($resolvedDetailUrl) . '"' : '' ?>>
                        <a class="order-product-link order-product-main" href="<?= h($resolvedDetailUrl !== '' ? $resolvedDetailUrl : '#') ?>">
                          <img class="order-product-thumb" src="<?= h($productImage !== '' ? $productImage : $placeholderImage) ?>" referrerpolicy="no-referrer" crossorigin="anonymous" onerror="this.onerror=null;this.src='<?= h($placeholderImage) ?>';" alt="<?= h((string)($item['ten_san_pham'] ?? 'Sản phẩm')) ?>">
                          <div class="min-w-0">
                            <div class="order-product-name"><?= h((string)($item['ten_san_pham'] ?? ('SP #' . ($item['ma_san_pham'] ?? '')))) ?></div>
                            <div class="order-product-meta">
                              <?= h((string)($item['thuong_hieu'] ?? '')) ?>
                              <?php if (!empty($item['thuong_hieu']) && !empty($item['so_luong'])): ?>•<?php endif; ?>
                              SL: <?= number_format((int)($item['so_luong'] ?? 0), 0, ',', '.') ?>
                              <?php if ((int)($item['don_gia'] ?? 0) > 0): ?>• <?= vnd($item['don_gia'] ?? 0) ?><?php endif; ?>
                            </div>
                          </div>
                        </a>
                        <div class="order-product-actions">
                          <?php if (!empty($item['has_reviewed'])): ?>
                            <?php if ($resolvedDetailUrl !== ''): ?>
                              <form method="get" action="<?= BASE_URL ?>/index.php">
                                <input type="hidden" name="r" value="chitiet">
                                <input type="hidden" name="id" value="<?= h($productId) ?>">
                                <input type="hidden" name="tab" value="danh-gia">
                                <button type="submit" class="btn btn-sm btn-outline-secondary">Xem lại phần đánh giá</button>
                              </form>
                            <?php endif; ?>
                            <span class="order-status-note">Đã đánh giá và nhận ưu đãi</span>
                          <?php elseif ($canReviewFromOrder && $resolvedDetailUrl !== ''): ?>
                            <form method="get" action="<?= BASE_URL ?>/index.php">
                              <input type="hidden" name="r" value="chitiet">
                              <input type="hidden" name="id" value="<?= h($productId) ?>">
                              <input type="hidden" name="tab" value="danh-gia">
                              <button type="submit" class="btn btn-sm btn-outline-brand">Đánh giá ngay để nhận ưu đãi</button>
                            </form>
                            <span class="order-status-note">Đủ điều kiện nhận thêm 1 điểm</span>
                          <?php elseif (!in_array($tabKey, ['da_huy'], true)): ?>
                            <span class="order-status-note is-muted">Đánh giá được sau khi nhận hàng</span>
                          <?php endif; ?>
                        </div>
                      </div>
                    <?php endforeach; ?>
                  </div>
                <?php endif; ?>

                <?php if ($tabKey === 'da_huy' && !empty($o['ly_do_huy'])): ?>
                  <div class="order-card-note">Lý do hủy: <?= h((string)$o['ly_do_huy']) ?></div>
                <?php endif; ?>

                <footer class="order-card-foot">
                  <div class="order-card-points">
                    <?php if ($tabKey === 'da_huy'): ?>
                      <span class="text-muted">Đơn đã hủy, không tích điểm</span>
                    <?php elseif (!empty($o['da_tich_diem'])): ?>
                      <span class="badge text-bg-success">+<?= number_format((int)($o['diem_cong'] ?? 0), 0, ',', '.') ?> điểm</span>
                    <?php else: ?>
                      <span class="text-muted">Tích điểm khi đơn hoàn thành</span>
                    <?php endif; ?>
                    <?php if ((int)($o['diem_su_dung'] ?? 0) > 0): ?>
                      <span class="text-muted">· Đã dùng <?= number_format((int)($o['diem_su_dung'] ?? 0), 0, ',', '.') ?> điểm</span>
                    <?php endif; ?>
                  </div>
                  <div class="order-card-total">Thành tiền: <strong><?= vnd($o['tong_tien'] ?? 0) ?></strong></div>
                  <div class="order-card-actions">
                    <?php if (!empty($actions['pay_qr'])): ?>
                      <a class="btn btn-sm btn-success" href="<?= BASE_URL ?>/index.php?r=camon&ma_hoa_don=<?= urlencode($orderId) ?>">Mở QR chuyển khoản</a>
                    <?php endif; ?>
                    <?php if (!empty($actions['reorder'])): ?>
                      <form method="post" action="<?= BASE_URL ?>/index.php?r=hoso&reorder=<?= urlencode($orderId) ?>">
                        <button type="submit" class="btn btn-sm btn-success">Mua lại</button>
                      </form>
                    <?php endif; ?>
                    <?php if (!empty($actions['cancel'])): ?>
                      <details class="order-cancel">
                        <summary class="btn btn-sm btn-outline-danger">Hủy đơn</summary>
                        <form method="post" action="<?= BASE_URL ?>/index.php?r=huydonhang" class="order-cancel-form" onsubmit="return confirm('Bạn chắc chắn muốn hủy đơn hàng này?');">
                          <input type="hidden" name="ma_hoa_don" value="<?= h($orderId) ?>">
                          <label class="visually-hidden" for="cancel-reason-<?= h($orderId) ?>">Lý do hủy đơn</label>
                          <select class="form-select form-select-sm" id="cancel-reason-<?= h($orderId) ?>" name="ly_do_huy" required>
                            <option value="">Chọn lý do hủy đơn</option>
                            <?php foreach (($cancelReasonOptions ?? []) as $value => $label): ?>
                              <option value="<?= h((string)$value) ?>"><?= h((string)$label) ?></option>
                            <?php endforeach; ?>
                          </select>
                          <textarea class="form-control form-control-sm" name="ly_do_huy_bo_sung" rows="2" placeholder="Ghi chú thêm cho lý do hủy (nếu có)"></textarea>
                          <button type="submit" class="btn btn-sm btn-danger">Xác nhận hủy</button>
                        </form>
                      </details>
                    <?php endif; ?>
                  </div>
                </footer>
              </article>
            <?php endforeach; ?>
          </div>

          <?php if ((int)$orderPage['pages'] > 1): ?>
            <nav class="order-pagination" aria-label="Phân trang đơn hàng">
              <?php $currentPage = (int)$orderPage['page']; $lastPage = (int)$orderPage['pages']; ?>
              <?php if ($currentPage > 1): ?>
                <a class="btn btn-sm btn-outline-secondary" href="<?= h($orderTabUrl($orderTab, $currentPage - 1)) ?>">‹ Trước</a>
              <?php endif; ?>
              <span class="order-pagination-info">Trang <?= $currentPage ?>/<?= $lastPage ?> · <?= number_format((int)$orderPage['matched'], 0, ',', '.') ?> đơn</span>
              <?php if ($currentPage < $lastPage): ?>
                <a class="btn btn-sm btn-outline-secondary" href="<?= h($orderTabUrl($orderTab, $currentPage + 1)) ?>">Sau ›</a>
              <?php endif; ?>
            </nav>
          <?php endif; ?>
        <?php endif; ?>
        </div>
      </div>

      <div class="tab-pane fade" id="tab-cart" role="tabpanel">
        <?php if (empty($cartItems)): ?>
          <div class="alert alert-info">Giỏ hàng hiện trống.</div>
        <?php else: ?>
          <div class="table-responsive">
            <table class="table align-middle">
              <thead>
                <tr>
                  <th>Sản phẩm</th>
                  <th>Số lượng</th>
                  <th>Đơn giá</th>
                  <th>Tạm tính</th>
                </tr>
              </thead>
              <tbody>
                <?php $cartTotal = 0; ?>
                <?php foreach ($cartItems as $it): ?>
                  <?php
                    $gia = (int)($it['gia_ban'] ?? 0);
                    $qty = (int)($it['so_luong'] ?? 0);
                    $lineTotal = $gia * $qty;
                    $cartTotal += $lineTotal;
                  ?>
                  <tr>
                    <td><?= h($it['ten_san_pham'] ?? ('SP #' . ($it['ma_san_pham'] ?? ''))) ?></td>
                    <td><?= $qty ?></td>
                    <td><?= vnd($gia) ?></td>
                    <td><?= vnd($lineTotal) ?></td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
              <tfoot>
                <tr>
                  <th colspan="3" class="text-end">Tổng</th>
                  <th><?= vnd($cartTotal) ?></th>
                </tr>
              </tfoot>
            </table>
          </div>
          <a class="btn btn-brand" href="<?= BASE_URL ?>/index.php?r=giohang">Checkout</a>
        <?php endif; ?>
      </div>

      <div class="tab-pane fade" id="tab-skin" role="tabpanel">
        <div class="alert alert-light border mb-3">
          Hồ sơ làn da được lấy từ dữ liệu khảo sát và các lần cập nhật gần đây của bạn.
        </div>

        <div class="row g-3 mb-4">
          <div class="col-md-6 col-xl-3">
            <div class="profile-stat h-100">
              <div class="label">Loại da</div>
              <?php
                $rawSkinProfileType = trim((string)($skinProfile['loai_da'] ?? ''));
                $canonicalSkinTypeVal = 'unknown';

                if (class_exists('GoiYContentBased')) {
                    $tempEngine = new GoiYContentBased(null);
                    $canonicalSkinTypeVal = $tempEngine->canonicalSkinType($rawSkinProfileType);
                } else {
                    $possibleModelPaths = [
                        __DIR__ . '/../../backend/app/models/GoiYContentBased.php',
                        __DIR__ . '/../../../backend/app/models/GoiYContentBased.php',
                        dirname(__DIR__, 3) . '/backend/app/models/GoiYContentBased.php',
                        dirname(__DIR__, 2) . '/app/models/GoiYContentBased.php'
                    ];
                    foreach ($possibleModelPaths as $pmp) {
                        if (file_exists($pmp)) {
                            require_once $pmp;
                            break;
                        }
                    }
                    if (class_exists('GoiYContentBased')) {
                        $tempEngine = new GoiYContentBased(null);
                        $canonicalSkinTypeVal = $tempEngine->canonicalSkinType($rawSkinProfileType);
                    }
                }

                if ($canonicalSkinTypeVal === 'unknown' && $rawSkinProfileType !== '') {
                    $sNorm = mb_strtolower($rawSkinProfileType, 'UTF-8');
                    if (mb_strpos($sNorm, 'dầu') !== false || mb_strpos($sNorm, 'dau') !== false || mb_strpos($sNorm, 'oily') !== false) {
                        $canonicalSkinTypeVal = 'oily';
                    } elseif (mb_strpos($sNorm, 'khô') !== false || mb_strpos($sNorm, 'kho') !== false || mb_strpos($sNorm, 'dry') !== false) {
                        $canonicalSkinTypeVal = 'dry';
                    } elseif (mb_strpos($sNorm, 'hỗn hợp') !== false || mb_strpos($sNorm, 'hon hop') !== false || mb_strpos($sNorm, 'combination') !== false) {
                        $canonicalSkinTypeVal = 'combination';
                    } elseif (mb_strpos($sNorm, 'thường') !== false || mb_strpos($sNorm, 'thuong') !== false || mb_strpos($sNorm, 'normal') !== false) {
                        $canonicalSkinTypeVal = 'normal';
                    }
                }

                $displaySkinTypeHoso = '';
                if ($canonicalSkinTypeVal === 'oily') $displaySkinTypeHoso = 'Da dầu';
                elseif ($canonicalSkinTypeVal === 'dry') $displaySkinTypeHoso = 'Da khô';
                elseif ($canonicalSkinTypeVal === 'combination') $displaySkinTypeHoso = 'Da hỗn hợp';
                elseif ($canonicalSkinTypeVal === 'normal') $displaySkinTypeHoso = 'Da thường';
                else $displaySkinTypeHoso = 'Chưa xác định';
              ?>
              <div class="value">
                <?= h($displaySkinTypeHoso) ?>
                <button type="button" class="badge bg-light text-success border ms-1 fw-normal text-decoration-none btn p-1" data-bs-toggle="modal" data-bs-target="#quickUpdateSkinTypeModal" style="font-size: 0.72rem;">
                  <i class="fa-solid fa-pen-to-square me-1"></i>Cập nhật loại da
                </button>
              </div>
            </div>
          </div>
          <div class="col-md-6 col-xl-3">
            <div class="profile-stat h-100">
              <div class="label">Mức độ nhạy cảm</div>
              <div class="value"><?= h($khachHang['muc_do_nhay_cam'] ?? 'Chưa có dữ liệu') ?></div>
            </div>
          </div>
          <div class="col-md-6 col-xl-3">
            <div class="profile-stat h-100">
              <div class="label">Mục tiêu chăm sóc</div>
              <div class="value"><?= h($khachHang['muc_tieu_cham_soc'] ?? 'Chưa có dữ liệu') ?></div>
            </div>
          </div>
          <div class="col-md-6 col-xl-3">
            <div class="profile-stat h-100">
              <div class="label">Ngân sách trung bình</div>
              <div class="value"><?= h($formattedBudget) ?></div>
            </div>
          </div>
        </div>

        <div class="alert alert-info border-0 shadow-sm mb-4 d-flex align-items-center justify-content-between gap-3 flex-wrap">
          <div>
            <strong class="d-block mb-1">Chọn cách cập nhật phù hợp với bạn</strong>
            <span class="small">Khảo sát đầy đủ tạo lại hồ sơ nền; khảo sát nhanh chỉ cập nhật tình trạng da hiện tại trong khoảng 1 phút.</span>
            <?php if (!empty($latestQuickSkinUpdate['updated_at_display'])): ?>
              <span class="d-block small mt-1 text-muted">Lần cập nhật nhanh gần nhất: <?= h((string)$latestQuickSkinUpdate['updated_at_display']) ?></span>
            <?php endif; ?>
          </div>
          <div class="d-flex flex-wrap gap-2">
            <a class="btn btn-brand" href="<?= BASE_URL ?>/index.php?r=khaosatnhanh"><i class="fa-solid fa-bolt me-1"></i> Cập nhật nhanh</a>
            <a class="btn btn-outline-brand" href="<?= BASE_URL ?>/index.php?r=khaosat">Khảo sát đầy đủ</a>
          </div>
        </div>

        <div class="row g-3 mb-4">
          <div class="col-lg-6">
            <div class="border rounded-3 p-3 bg-white h-100">
              <h6 class="mb-3">Vấn đề da và tình trạng hiện tại</h6>
              <div class="mb-3">
                <div class="small text-muted mb-2">Vấn đề da đang gặp phải</div>
                <?= $renderTagList($surveySkinIssues, 'Chưa có dữ liệu vấn đề da') ?>
              </div>
              <div>
                <div class="small text-muted mb-2">Tình trạng đặc biệt</div>
                <?= $renderTagList($surveySpecialStates, 'Chưa ghi nhận tình trạng đặc biệt') ?>
              </div>
            </div>
          </div>

          <div class="col-lg-6">
            <div class="border rounded-3 p-3 bg-white h-100">
              <h6 class="mb-3">Ưu tiên khi chọn sản phẩm</h6>
              <div class="mb-3">
                <div class="small text-muted mb-2">Kết cấu yêu thích</div>
                <?= $renderTagList($surveyTextures, 'Không chọn / Mọi kết cấu') ?>
              </div>
              <div class="mb-3">
                <div class="small text-muted mb-2">Hoạt chất quan tâm</div>
                <?= $renderTagList($surveyIngredients, 'Không có / Không quan tâm') ?>
              </div>
              <div>
                <div class="small text-muted mb-2">Thành phần muốn tránh</div>
                <?= $renderTagList($surveyAvoidIngredients, 'Không có / Không quan tâm') ?>
              </div>
            </div>
          </div>

          <div class="col-lg-6">
            <div class="border rounded-3 p-3 bg-white h-100">
              <h6 class="mb-3">Xuất xứ & Thương hiệu ưu tiên</h6>
              <div class="mb-3">
                <div class="small text-muted mb-2">Xuất xứ ưu tiên</div>
                <?= $renderTagList($surveyPreferredCountries, 'Không có / Không quan tâm') ?>
              </div>
              <div>
                <div class="small text-muted mb-2">Thương hiệu ưu tiên</div>
                <?= $renderTagList($surveyPreferredBrands, 'Không có / Không quan tâm') ?>
              </div>
            </div>
          </div>

          <div class="col-lg-6">
            <div class="border rounded-3 p-3 bg-white h-100">
              <h6 class="mb-3">Thông tin khảo sát đã lưu</h6>
              <div class="row g-3">
                <div class="col-sm-6">
                  <div class="small text-muted">Giới tính</div>
                  <div class="fw-semibold"><?= h($khachHang['gioi_tinh'] ?? 'Chưa có dữ liệu') ?></div>
                </div>
                <div class="col-sm-6">
                  <div class="small text-muted">Năm sinh</div>
                  <div class="fw-semibold"><?= h($khachHang['nam_sinh'] ?? 'Chưa có dữ liệu') ?></div>
                </div>
              </div>
            </div>
          </div>
        </div>
</div>

      <!-- TAB VÍ VOUCHER -->
      <div class="tab-pane fade" id="tab-vouchers" role="tabpanel" style="display: none;">
        <div class="d-flex justify-content-between align-items-center mb-3">
          <h5 class="fw-bold mb-0 text-dark">
            <i class="fas fa-wallet text-success me-2"></i>Ví Voucher của tôi
          </h5>
          <span class="badge bg-success-subtle text-success border border-success-subtle px-3 py-1.5 fw-semibold" style="font-size: 0.82rem;">
            <?= count($customerVouchers) ?> voucher
          </span>
        </div>

        <?php if (empty($customerVouchers)): ?>
          <div class="text-center py-5 bg-white rounded-3 border" style="border-color: #E2E8F0;">
            <div class="mb-3">
              <span class="d-inline-flex align-items-center justify-content-center rounded-circle bg-light text-success" style="width: 64px; height: 64px; font-size: 1.8rem;">
                <i class="fas fa-ticket-alt"></i>
              </span>
            </div>
            <h6 class="fw-bold text-dark mb-1">Bạn chưa có voucher nào trong ví</h6>
            <p class="text-muted small mb-3" style="max-width: 420px; margin: 0 auto;">
              Hãy xem video tư vấn Syna 3D tại các trang chi tiết sản phẩm để hoàn thành thử thách và nhận voucher ưu đãi độc quyền!
            </p>
            <a href="<?= BASE_URL ?>/index.php?r=tatca" class="btn btn-outline-brand btn-sm px-4 fw-semibold rounded-pill">
              Khám phá sản phẩm có video
            </a>
          </div>
        <?php else: ?>
          <div class="row g-3">
            <?php foreach ($customerVouchers as $cv): ?>
              <?php
                $cvStatus = strtoupper((string)($cv['status'] ?? 'AVAILABLE'));
                $isAvailable = ($cvStatus === 'AVAILABLE');
                $isUsed = ($cvStatus === 'USED');
                $isExpired = ($cvStatus === 'EXPIRED');

                $statusBadgeClass = $isAvailable ? 'bg-success text-white' : ($isUsed ? 'bg-secondary text-white' : 'bg-danger text-white');
                $statusText = $isAvailable ? 'Khả dụng' : ($isUsed ? 'Đã sử dụng' : 'Hết hạn');

                $discountDisplay = ($cv['discount_type'] ?? '') === 'percentage'
                  ? 'Giảm ' . (float)$cv['discount_value'] . '%'
                  : 'Giảm ' . number_format((float)$cv['discount_value'], 0, ',', '.') . 'đ';
              ?>
              <div class="col-md-6 col-lg-4">
                <div class="card h-100 border rounded-3 shadow-sm overflow-hidden" style="border-color: #E2E8F0 !important; background: #ffffff;">
                  <div class="card-header bg-light border-bottom d-flex justify-content-between align-items-center py-2 px-3">
                    <span class="badge <?= $statusBadgeClass ?> px-2 py-1 fw-bold text-uppercase" style="font-size: 0.7rem; letter-spacing: 0.03em;">
                      <?= $statusText ?>
                    </span>
                    <small class="text-muted" style="font-size: 0.72rem;">
                      Nhận: <?= h($cv['claimed_at'] ?? $cv['created_at'] ?? '') ?>
                    </small>
                  </div>
                  <div class="card-body p-3 d-flex flex-column justify-content-between">
                    <div>
                      <h6 class="fw-bold text-dark mb-1" style="font-size: 0.95rem;">
                        <?= h($cv['campaign_name'] ?? 'Voucher thưởng Syna 3D') ?>
                      </h6>
                      <div class="fs-5 fw-bold text-success mb-2">
                        <?= $discountDisplay ?>
                        <?php if (!empty($cv['max_discount_amount'])): ?>
                          <span class="small text-muted fw-normal" style="font-size: 0.78rem;">
                            (Tối đa <?= number_format((float)$cv['max_discount_amount'], 0, ',', '.') ?>đ)
                          </span>
                        <?php endif; ?>
                      </div>

                      <div class="p-2 rounded bg-light border mb-2 d-flex justify-content-between align-items-center">
                        <span class="font-monospace fw-bold text-dark user-select-all" style="font-size: 0.95rem; letter-spacing: 0.05em;">
                          <?= h($cv['voucher_code'] ?? '') ?>
                        </span>
                        <button type="button" class="btn btn-sm btn-outline-success py-0.5 px-2" style="font-size: 0.72rem;" onclick="navigator.clipboard.writeText('<?= h($cv['voucher_code'] ?? '') ?>'); this.textContent = 'Đã chép!'; setTimeout(() => this.innerHTML = '<i class=\'fas fa-copy\'></i> Chép', 1500);">
                          <i class="fas fa-copy"></i> Chép
                        </button>
                      </div>

                      <ul class="list-unstyled small text-muted mb-0" style="font-size: 0.78rem; line-height: 1.6;">
                        <?php if (!empty($cv['min_order_amount'])): ?>
                          <li><i class="fas fa-shopping-bag me-1 text-secondary"></i> Đơn tối thiểu: <?= number_format((float)$cv['min_order_amount'], 0, ',', '.') ?>đ</li>
                        <?php endif; ?>
                        <?php if (!empty($cv['end_date'])): ?>
                          <li><i class="fas fa-clock me-1 text-secondary"></i> Hạn dùng: <?= h($cv['end_date']) ?></li>
                        <?php endif; ?>
                        <li><i class="fas fa-tv me-1 text-secondary"></i> Nguồn gốc: Xem video Syna 3D</li>
                      </ul>
                    </div>

                    <?php if ($isAvailable): ?>
                      <div class="mt-3 pt-2 border-top">
                        <a href="<?= BASE_URL ?>/index.php?r=tatca" class="btn btn-outline-success btn-sm w-100 fw-semibold" style="font-size: 0.8rem; border-radius: 6px;">
                          Dùng ngay tại cửa hàng
                        </a>
                      </div>
                    <?php endif; ?>
                  </div>
                </div>
              </div>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>

<div class="modal fade" id="quickUpdateSkinTypeModal" tabindex="-1" aria-labelledby="quickUpdateSkinTypeModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content border-0 shadow">
      <div class="modal-header">
        <h5 class="modal-title fs-6 fw-bold" id="quickUpdateSkinTypeModalLabel"><i class="fa-solid fa-droplet me-2 text-success"></i>Cập nhật loại da</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Đóng"></button>
      </div>
      <form id="quickUpdateSkinTypeForm">
        <div class="modal-body">
          <p class="text-muted small mb-3">Chỉ cập nhật Loại da của bạn. Các thông tin khảo sát khác (mục tiêu, thành phần tránh, v.v.) sẽ được giữ nguyên không thay đổi.</p>
          <div class="mb-3">
            <label for="modalSkinTypeSelect" class="form-label fw-semibold">Chọn loại da chính xác của bạn:</label>
            <select class="form-select" id="modalSkinTypeSelect" name="loai_da" required>
              <option value="">-- Chọn loại da --</option>
              <option value="Da dầu / Hỗn hợp thiên dầu" <?= $canonicalSkinTypeVal === 'oily' ? 'selected' : '' ?>>Da dầu / Hỗn hợp thiên dầu</option>
              <option value="Da khô / Hỗn hợp thiên khô" <?= $canonicalSkinTypeVal === 'dry' ? 'selected' : '' ?>>Da khô / Hỗn hợp thiên khô</option>
              <option value="Da hỗn hợp" <?= $canonicalSkinTypeVal === 'combination' ? 'selected' : '' ?>>Da hỗn hợp</option>
              <option value="Da thường / Không có vấn đề đặc biệt" <?= $canonicalSkinTypeVal === 'normal' ? 'selected' : '' ?>>Da thường / Không có vấn đề đặc biệt</option>
            </select>
          </div>
          <div id="quickUpdateSkinTypeMsg" class="small mt-2"></div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-light" data-bs-dismiss="modal">Hủy</button>
          <button type="submit" class="btn btn-brand">Lưu thay đổi</button>
        </div>
      </form>
    </div>
  </div>
</div>

<script>
(function () {
  const form = document.getElementById('accountInfoForm');
  const msgEl = document.getElementById('accountInfoMsg');
  if (!form || !msgEl) return;

  form.addEventListener('submit', async function (e) {
    e.preventDefault();
    msgEl.textContent = 'Đang lưu...';
    msgEl.className = 'small text-muted';

    try {
      const res = await fetch('<?= BASE_URL ?>/index.php?r=capnhatthongtin', {
        method: 'POST',
        body: new FormData(form),
        headers: { 'X-Requested-With': 'XMLHttpRequest' }
      });

      const data = await res.json();
      if (!res.ok || !data.ok) {
        msgEl.textContent = data.message || 'Không thể cập nhật thông tin.';
        msgEl.className = 'small text-danger';
        return;
      }

      msgEl.textContent = data.message || 'Đã cập nhật thông tin.';
      msgEl.className = 'small text-success';
    } catch (err) {
      msgEl.textContent = 'Kết nối thất bại. Vui lòng thử lại.';
      msgEl.className = 'small text-danger';
    }
  });
})();

(function () {
  const form = document.getElementById('changePasswordForm');
  const msgEl = document.getElementById('changePasswordMsg');
  if (!form || !msgEl) return;

  form.addEventListener('submit', async function (e) {
    e.preventDefault();
    msgEl.textContent = 'Đang cập nhật...';
    msgEl.className = 'small text-muted';

    try {
      const res = await fetch('<?= BASE_URL ?>/index.php?r=doimatkhau', {
        method: 'POST',
        body: new FormData(form),
        headers: { 'X-Requested-With': 'XMLHttpRequest' }
      });

      const data = await res.json();
      if (!res.ok || !data.ok) {
        msgEl.textContent = data.message || 'Không thể đổi mật khẩu.';
        msgEl.className = 'small text-danger';
        return;
      }

      msgEl.textContent = data.message || 'Đổi mật khẩu thành công.';
      msgEl.className = 'small text-success';
      form.reset();
    } catch (err) {
      msgEl.textContent = 'Kết nối thất bại. Vui lòng thử lại.';
      msgEl.className = 'small text-danger';
    }
  });
})();

(function () {
  const form = document.getElementById('quickUpdateSkinTypeForm');
  const msgEl = document.getElementById('quickUpdateSkinTypeMsg');
  if (!form || !msgEl) return;

  form.addEventListener('submit', async function (e) {
    e.preventDefault();
    msgEl.textContent = 'Đang lưu...';
    msgEl.className = 'small text-muted';

    try {
      const targetUrl = 'index.php?r=capnhat_loaida';

      const res = await fetch(targetUrl, {
        method: 'POST',
        body: new FormData(form),
        headers: { 'X-Requested-With': 'XMLHttpRequest' }
      });

      let data = {};
      try {
        data = await res.json();
      } catch (jsonErr) {
        console.error('Non-JSON response:', jsonErr);
      }

      if (!res.ok || !data.ok) {
        msgEl.textContent = data.message || 'Không thể cập nhật loại da. Vui lòng kiểm tra lại trạng thái đăng nhập.';
        msgEl.className = 'small text-danger';
        return;
      }

      msgEl.textContent = data.message || 'Đã cập nhật loại da thành công!';
      msgEl.className = 'small text-success';
      setTimeout(function () {
        window.location.reload();
      }, 500);
    } catch (err) {
      console.error('Submit error:', err);
      msgEl.textContent = 'Kết nối thất bại. Vui lòng thử lại.';
      msgEl.className = 'small text-danger';
    }
  });
})();

window.switchHosoTab = function (targetId, evt) {
  if (evt) {
    if (typeof evt.preventDefault === 'function') evt.preventDefault();
    if (typeof evt.stopPropagation === 'function') evt.stopPropagation();
  }

  if (!targetId) return false;
  var cleanId = targetId.indexOf('#') === 0 ? targetId : '#' + targetId;
  var cleanPaneId = cleanId.replace('#', '');

  var buttons = document.querySelectorAll('#accountTabs .nav-link');
  buttons.forEach(function (btn) {
    var btnId = btn.id || '';
    if (btnId === 'btn-' + cleanPaneId || (btn.getAttribute('onclick') || '').indexOf(cleanPaneId) !== -1) {
      btn.classList.add('active');
      btn.setAttribute('aria-selected', 'true');
    } else if (btn.hasAttribute('onclick')) {
      btn.classList.remove('active');
      btn.setAttribute('aria-selected', 'false');
    }
  });

  var panes = document.querySelectorAll('.tab-content > .tab-pane');
  panes.forEach(function (pane) {
    if (pane.id === cleanPaneId) {
      pane.classList.add('show', 'active');
      pane.style.display = 'block';
    } else {
      pane.classList.remove('show', 'active');
      pane.style.display = 'none';
    }
  });

  return false;
};

document.addEventListener('DOMContentLoaded', function () {
  var hash = window.location.hash;
  var urlParams = new URLSearchParams(window.location.search);
  var tabParam = urlParams.get('tab');

  if (hash && hash.indexOf('#tab-') === 0) {
    window.switchHosoTab(hash);
  } else if (tabParam) {
    window.switchHosoTab('#tab-' + tabParam);
  }
});
</script>


