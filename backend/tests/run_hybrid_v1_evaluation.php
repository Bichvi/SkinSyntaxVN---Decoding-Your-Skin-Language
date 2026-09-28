<?php
require_once __DIR__ . '/../app/config/db.php';
require_once __DIR__ . '/../app/models/SanPham.php';
require_once __DIR__ . '/../app/models/TaiKhoan.php';
require_once __DIR__ . '/../app/services/ContentBasedRecommender.php';
global $db;

echo "=================================================================\n";
echo "HYBRID RECOMMENDER V1 - OFFLINE EVALUATION & WEIGHT EXPERIMENT\n";
echo "=================================================================\n";

$taiKhoanModel = new TaiKhoan($db);
$cbr = new ContentBasedRecommender();

// Load TF-IDF cache
$cacheFile = dirname(__DIR__) . '/app/content/tfidf_cache.json';
$cacheData = json_decode(file_get_contents($cacheFile), true);
$vectors = $cacheData['vectors'];
$norms = $cacheData['norms'];
$prices = $cacheData['prices'];
$names = $cacheData['names'];

// Load all active products with metadata
$products = [];
foreach ($db->san_pham->find(['trang_thai' => 'active', 'gia_ban' => ['$gt' => 0]]) as $doc) {
    $products[(string)$doc['ma_san_pham']] = (array)$doc;
}
echo "Loaded " . count($products) . " products from database.\n";

// Function to tokenize and vectorize text using the exact same logic
function tokenize(string $text): array {
    $text = strip_tags($text);
    $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $text = mb_strtolower($text, 'UTF-8');
    $text = preg_replace('/[^\p{L}\p{N}\s]+/u', ' ', $text);
    $tokens = preg_split('/\s+/u', $text, -1, PREG_SPLIT_NO_EMPTY);
    $cleanTokens = [];
    foreach ($tokens as $t) {
        if (mb_strlen($t, 'UTF-8') >= 2 && !is_numeric($t)) {
            $cleanTokens[] = $t;
        }
    }
    return $cleanTokens;
}

// Build profile query vector
function buildProfileVector(array $profile, array $vectors): array {
    $parts = [];
    if (!empty($profile['skin_type'])) $parts[] = $profile['skin_type'];
    if (!empty($profile['van_de_da'])) {
        $parts[] = is_array($profile['van_de_da']) ? implode(' ', $profile['van_de_da']) : (string)$profile['van_de_da'];
    }
    if (!empty($profile['muc_tieu_cham_soc'])) $parts[] = (string)$profile['muc_tieu_cham_soc'];

    $text = implode(' ', $parts);
    $tokens = tokenize($text);
    $counts = array_count_values($tokens);
    $total = array_sum($counts);
    if ($total <= 0) return [];

    $vec = [];
    foreach ($counts as $t => $c) {
        $vec[$t] = round($c / $total, 4);
    }
    return $vec;
}

// Skin type compatibility score
function getSkinTypeScore(string $prodLoaiDa, ?string $custSkinType): float {
    if (empty($custSkinType)) return 0.50; // Neutral if user didn't specify
    $p = mb_strtolower(trim($prodLoaiDa), 'UTF-8');
    $c = mb_strtolower(trim($custSkinType), 'UTF-8');

    if ($p === $c) {
        return 1.0; // Exact match
    }
    if ($p === 'da thường/mọi loại da') {
        return 0.70; // Compatible universal fallback
    }
    if ($p === 'unknown') {
        return 0.30; // Unknown status
    }
    return 0.0; // Incompatible
}

// Soft budget score
function getBudgetScore(float $candPrice, ?int $budget): float {
    if (!$budget || $budget <= 0) return 1.0;
    if ($candPrice <= $budget) return 1.0;
    $over = $candPrice - $budget;
    return max(0.10, 1.0 - ($over / $budget));
}

// Run hybrid recommendation query
function queryHybrid(
    array $recentIds,
    array $profile,
    float $wSession,
    float $wProfile,
    array $vectors,
    array $norms,
    array $prices,
    array $names,
    array $products,
    ContentBasedRecommender $cbr,
    int $limit = 10
): array {
    // 1. Build session vector
    $sessionVec = [];
    $validRecent = [];
    $recencyWeights = [1.0, 0.65, 0.50, 0.35, 0.20];
    foreach ($recentIds as $idx => $id) {
        $sid = (string)$id;
        if (isset($vectors[$sid])) {
            $validRecent[] = $sid;
            $rw = $recencyWeights[$idx] ?? 0.1;
            foreach ($vectors[$sid] as $t => $v) {
                $sessionVec[$t] = ($sessionVec[$t] ?? 0.0) + $rw * $v;
            }
        }
    }

    // 2. Build profile vector
    $profileVec = buildProfileVector($profile, $vectors);

    // 3. Combine query vector
    $combinedQuery = [];
    if (!empty($validRecent) && !empty($profileVec)) {
        foreach ($sessionVec as $t => $v) {
            $combinedQuery[$t] = ($combinedQuery[$t] ?? 0.0) + $wSession * $v;
        }
        foreach ($profileVec as $t => $v) {
            $combinedQuery[$t] = ($combinedQuery[$t] ?? 0.0) + $wProfile * $v;
        }
    } elseif (!empty($validRecent)) {
        $combinedQuery = $sessionVec;
    } elseif (!empty($profileVec)) {
        $combinedQuery = $profileVec;
    } else {
        return [];
    }

    // Normalize query vector
    $qNormSq = 0.0;
    foreach ($combinedQuery as $v) $qNormSq += $v * $v;
    $qNorm = sqrt($qNormSq);
    if ($qNorm <= 0) return [];

    $custSkinType = $profile['skin_type'] ?? null;
    $custBudget = $profile['ngan_sach'] ?? null;

    $candidates = [];
    foreach ($vectors as $pid => $vec) {
        if (in_array($pid, $validRecent, true)) continue;

        $dot = 0.0;
        foreach ($vec as $t => $vVal) {
            if (isset($combinedQuery[$t])) {
                $dot += $vVal * $combinedQuery[$t];
            }
        }

        if ($dot > 0) {
            $cScore = $dot / ($qNorm * ($norms[$pid] ?? 1.0));
            $pData = $products[$pid] ?? [];
            $skinScore = getSkinTypeScore($pData['loai_da'] ?? '', $custSkinType);
            $candPrice = (float)($prices[$pid] ?? 0);
            $bScore = getBudgetScore($candPrice, $custBudget);

            // Final blended score: 70% Content + 20% Skin Compatibility + 10% Budget
            $finalScore = (0.70 * $cScore) + (0.20 * $skinScore) + (0.10 * $bScore);

            $candidates[$pid] = [
                'ma_san_pham' => $pid,
                'ten_san_pham' => $names[$pid] ?? '',
                'loai_da' => $pData['loai_da'] ?? 'Unknown',
                'gia_ban' => $candPrice,
                'content_score' => round($cScore, 4),
                'skin_type_match' => round($skinScore, 2),
                'budget_score' => round($bScore, 4),
                'final_score' => round($finalScore, 4)
            ];
        }
    }

    uasort($candidates, fn($a, $b) => $b['final_score'] <=> $a['final_score']);

    // Diversity filter: max 1 per product family
    $results = [];
    $seenFamilies = [];
    foreach ($candidates as $pid => $item) {
        $fam = $cbr->extractProductFamily($item['ten_san_pham']);
        if ($fam !== '') {
            if (isset($seenFamilies[$fam])) continue;
            $seenFamilies[$fam] = true;
        }

        // Generate explainable reason tags
        $reasons = [];
        if ($item['skin_type_match'] >= 1.0) {
            $reasons[] = "Đúng loại da của bạn";
        } elseif ($item['skin_type_match'] >= 0.70) {
            $reasons[] = "Phù hợp mọi loại da";
        }
        if ($item['budget_score'] >= 1.0) {
            $reasons[] = "Trong ngân sách";
        }
        if (!empty($validRecent)) {
            $reasons[] = "Tương tự sản phẩm bạn vừa xem";
        }
        $item['reason'] = implode(' • ', $reasons);

        $results[] = $item;
        if (count($results) >= $limit) break;
    }

    return $results;
}

// -------------------------------------------------------------
// STEP 5: TEST WEIGHTING COMBINATIONS (A, B, C, D)
// -------------------------------------------------------------
$testProfile = [
    'skin_type' => 'Da khô/Hỗn hợp khô',
    'van_de_da' => 'Da khô căng, bong tróc',
    'muc_tieu_cham_soc' => 'Dưỡng sáng, mờ thâm nám',
    'ngan_sach' => 500000
];
$recentTest = ['1000']; // Eucerin Cleanser

echo "\n--- EXPERIMENTING SESSION/PROFILE COMBINATION WEIGHTS ---\n";
echo "Profile: Da khô/Hỗn hợp khô | Ngân sách: 500k | Recent: SP1000 (Eucerin Gel Rửa Mặt)\n";

$weightConfigs = [
    'A (0.75 Session / 0.25 Profile)' => [0.75, 0.25],
    'B (0.60 Session / 0.40 Profile)' => [0.60, 0.40],
    'C (0.50 Session / 0.50 Profile)' => [0.50, 0.50],
    'D (0.40 Session / 0.60 Profile)' => [0.40, 0.60],
];

foreach ($weightConfigs as $cfgName => $weights) {
    echo "\n>>> Configuration $cfgName:\n";
    $recs = queryHybrid($recentTest, $testProfile, $weights[0], $weights[1], $vectors, $norms, $prices, $names, $products, $cbr, 5);
    echo sprintf("%-3s | %-6s | %-8s | %-6s | %-6s | %-8s | %-20s | %s\n",
        "#", "ID", "Final", "Skin", "Budg", "Giá", "Loại da SP", "Tên Sản Phẩm");
    echo str_repeat("-", 100) . "\n";
    foreach ($recs as $idx => $r) {
        echo sprintf("%-3d | %-6s | %-8.4f | %-6.2f | %-6.2f | %-8s | %-20s | %s\n",
            $idx + 1, $r['ma_san_pham'], $r['final_score'], $r['skin_type_match'], $r['budget_score'],
            number_format($r['gia_ban']), mb_substr($r['loai_da'], 0, 20), mb_substr($r['ten_san_pham'], 0, 40));
    }
}

// -------------------------------------------------------------
// STEP 12: EVALUATE 3 REAL PROFILES
// -------------------------------------------------------------
echo "\n=================================================================\n";
echo "STEP 12: OFFLINE EVALUATION ON 3 PROFILES (CONFIG C: 0.50 / 0.50 BASELINE)\n";
echo "=================================================================\n";

$evalProfiles = [
    'Profile 1: Da khô' => [
        'profile' => [
            'skin_type' => 'Da khô/Hỗn hợp khô',
            'van_de_da' => 'Da khô căng, bong tróc',
            'muc_tieu_cham_soc' => 'Dưỡng sáng, mờ thâm nám',
            'ngan_sach' => 500000
        ],
        'recent' => ['83'] // Kem Dưỡng CeraVe Cho Da Khô 340g
    ],
    'Profile 2: Da nhạy cảm' => [
        'profile' => [
            'skin_type' => 'Da nhạy cảm',
            'van_de_da' => 'Mụn viêm, sưng đỏ',
            'muc_tieu_cham_soc' => 'Sạch mụn, giảm viêm',
            'ngan_sach' => 300000
        ],
        'recent' => ['68'] // Serum Timeless B5 Phục Hồi
    ],
    'Profile 3: Da mụn' => [
        'profile' => [
            'skin_type' => 'Da mụn',
            'van_de_da' => 'Mụn viêm, sưng đỏ, Lỗ chân lông to',
            'muc_tieu_cham_soc' => 'Sạch mụn, giảm viêm',
            'ngan_sach' => 400000
        ],
        'recent' => ['1000'] // Gel Rửa Mặt Eucerin Cho Da Nhờn Mụn
    ]
];

foreach ($evalProfiles as $title => $data) {
    echo "\n-----------------------------------------------------------------\n";
    echo "TEST CASE: $title\n";
    echo "PROFILE DETAILS:\n";
    echo "  - Loại da: " . $data['profile']['skin_type'] . "\n";
    echo "  - Vấn đề da: " . $data['profile']['van_de_da'] . "\n";
    echo "  - Mục tiêu: " . $data['profile']['muc_tieu_cham_soc'] . "\n";
    echo "  - Ngân sách: " . number_format($data['profile']['ngan_sach']) . " đ\n";
    echo "RECENT VIEWED: [" . implode(', ', $data['recent']) . "] - " . ($names[$data['recent'][0]] ?? '') . "\n";

    $recs = queryHybrid($data['recent'], $data['profile'], 0.50, 0.50, $vectors, $norms, $prices, $names, $products, $cbr, 10);

    echo "\nTOP 10 RECOMMENDATIONS:\n";
    echo sprintf("%-3s | %-6s | %-8s | %-8s | %-6s | %-6s | %-10s | %-20s | %s\n",
        "#", "ID", "Final", "Content", "Skin", "Budg", "Giá", "Loại da SP", "Tên Sản Phẩm");
    echo str_repeat("-", 110) . "\n";
    foreach ($recs as $idx => $r) {
        echo sprintf("%-3d | %-6s | %-8.4f | %-8.4f | %-6.2f | %-6.2f | %-10s | %-20s | %s\n",
            $idx + 1,
            $r['ma_san_pham'],
            $r['final_score'],
            $r['content_score'],
            $r['skin_type_match'],
            $r['budget_score'],
            number_format($r['gia_ban']) . " đ",
            mb_substr($r['loai_da'], 0, 20),
            mb_substr($r['ten_san_pham'], 0, 38)
        );
    }
}
