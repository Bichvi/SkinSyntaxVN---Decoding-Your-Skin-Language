<?php
require_once __DIR__ . '/../app/config/db.php';
global $db;

// Step 1: Detailed Audit of Products 1000, 266, 728, 3358, 2009, 868
echo "=================================================================\n";
echo "STEP 1: AUDIT OF NEAR-DUPLICATES / VARIANTS\n";
echo "=================================================================\n";

// Step 1: Detailed Audit of Products 1000, 266, 728, 3358, 2009, 868
echo "=================================================================\n";
echo "STEP 1: AUDIT OF NEAR-DUPLICATES / VARIANTS\n";
echo "=================================================================\n";

// Load brand and category lookup maps
$brandMap = [];
foreach ($db->thuong_hieu->find([]) as $b) {
    $brandMap[(string)$b['ma_thuong_hieu']] = (string)$b['ten_thuong_hieu'];
}

$catMap = [];
foreach ($db->danh_muc->find([]) as $c) {
    $catMap[(string)$c['ma_danh_muc']] = (string)$c['ten_danh_muc'];
}

$targetIds = [1000, 266, 728, 3358, 2009, 868];
$auditDocs = $db->san_pham->find(['ma_san_pham' => ['$in' => $targetIds]])->toArray();

foreach ($auditDocs as $doc) {
    $brandName = $doc['thuong_hieu'] ?? ($brandMap[(string)($doc['ma_thuong_hieu'] ?? '')] ?? 'N/A');
    $catName = $doc['danh_muc_day_du'] ?? ($catMap[(string)($doc['ma_danh_muc'] ?? '')] ?? 'N/A');
    echo "-----------------------------------------------------------------\n";
    echo "ID: " . $doc['ma_san_pham'] . "\n";
    echo "Tên: " . $doc['ten_san_pham'] . "\n";
    echo "Thương hiệu: " . $brandName . "\n";
    echo "Danh mục: " . $catName . "\n";
    echo "Loại da: " . ($doc['loai_da'] ?? 'N/A') . "\n";
    echo "Dung tích: " . ($doc['dung_tich'] ?? 'N/A') . "\n";
    echo "Giá bán: " . number_format($doc['gia_ban'] ?? 0) . " đ\n";
    echo "Thành phần chính: " . ($doc['thanh_phan_chinh'] ?? 'N/A') . "\n";
    echo "Mô tả: " . mb_substr(strip_tags($doc['mo_ta_ngan'] ?? $doc['mo_ta'] ?? ''), 0, 150) . "...\n";
}

// Load all products for ablation & evaluation
$allDocs = $db->san_pham->find(
    ['trang_thai' => 'active', 'gia_ban' => ['$gt' => 0]],
    ['projection' => [
        'ma_san_pham' => 1,
        'ten_san_pham' => 1,
        'danh_muc_day_du' => 1,
        'ma_danh_muc' => 1,
        'ma_thuong_hieu' => 1,
        'thuong_hieu' => 1,
        'loai_da' => 1,
        'thanh_phan' => 1,
        'thanh_phan_chinh' => 1,
        'mo_ta_ngan' => 1,
        'mo_ta' => 1,
        'gia_ban' => 1
    ]]
)->toArray();

$productMap = [];
foreach ($allDocs as $doc) {
    $p = (array)$doc;
    $p['thuong_hieu'] = $doc['thuong_hieu'] ?? ($brandMap[(string)($doc['ma_thuong_hieu'] ?? '')] ?? '');
    $p['danh_muc_day_du'] = $doc['danh_muc_day_du'] ?? ($catMap[(string)($doc['ma_danh_muc'] ?? '')] ?? '');
    $productMap[(string)$p['ma_san_pham']] = $p;
}

function tokenizeText(string $text): array {
    $text = strip_tags($text);
    $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $text = mb_strtolower($text, 'UTF-8');
    $text = preg_replace('/[^\p{L}\p{N}\s]+/u', ' ', $text);
    $tokens = preg_split('/\s+/u', $text, -1, PREG_SPLIT_NO_EMPTY);
    $clean = [];
    foreach ($tokens as $t) {
        if (mb_strlen($t, 'UTF-8') >= 2 && !is_numeric($t)) {
            $clean[] = $t;
        }
    }
    return $clean;
}

function extractProductFamily(string $name): string {
    $clean = mb_strtolower($name, 'UTF-8');
    $clean = preg_replace('/^(\[.*?\]|\(.*?\))\s*/u', '', $clean);
    $clean = preg_replace('/\b\d+(\.\d+)?\s*(ml|g|kg|l|oz|miếng|gói|viên|set|combo)\b/ui', '', $clean);
    $clean = preg_replace('/\b(combo\s*\d*|x\d+)\b/ui', '', $clean);
    $clean = preg_replace('/[^\p{L}\p{N}\s]+/u', ' ', $clean);
    return trim(preg_replace('/\s+/u', ' ', $clean));
}

function buildTfidfModel(array $products, array $w): array {
    $N = count($products);
    $docTokens = [];
    $docFreq = [];

    foreach ($products as $p) {
        $pid = (string)$p['ma_san_pham'];
        $name = (string)($p['ten_san_pham'] ?? '');
        $cat = (string)($p['danh_muc_day_du'] ?? '');
        $brand = (string)($p['thuong_hieu'] ?? '');
        $skin = (string)($p['loai_da'] ?? '');
        $ing = (string)($p['thanh_phan'] ?? $p['thanh_phan_chinh'] ?? '');
        $desc = mb_substr(strip_tags((string)($p['mo_ta_ngan'] ?? $p['mo_ta'] ?? '')), 0, 300, 'UTF-8');

        $text = str_repeat($name . " ", $w['ten']) .
                str_repeat($brand . " ", $w['brand']) .
                str_repeat($cat . " ", $w['category']) .
                str_repeat($skin . " ", $w['skin']) .
                str_repeat($ing . " ", $w['ingredient']) .
                str_repeat($desc . " ", $w['description']);

        $tokens = tokenizeText($text);
        $counts = array_count_values($tokens);
        $docTokens[$pid] = $counts;
        foreach (array_keys($counts) as $t) {
            $docFreq[$t] = ($docFreq[$t] ?? 0) + 1;
        }
    }

    $filteredDf = [];
    foreach ($docFreq as $t => $df) {
        if ($df >= 3 && $df <= $N * 0.80) {
            $filteredDf[$t] = log((1 + $N) / (1 + $df)) + 1;
        }
    }

    $vectors = [];
    $norms = [];
    foreach ($docTokens as $pid => $counts) {
        $tot = array_sum($counts);
        $scored = [];
        foreach ($counts as $t => $c) {
            if (isset($filteredDf[$t])) {
                $scored[$t] = ($c / $tot) * $filteredDf[$t];
            }
        }
        arsort($scored);
        $top = array_slice($scored, 0, 30, true);
        $vec = [];
        $normSq = 0.0;
        foreach ($top as $t => $val) {
            $rval = round($val, 4);
            $vec[$t] = $rval;
            $normSq += $rval * $rval;
        }
        $vectors[$pid] = $vec;
        $norms[$pid] = round($normSq > 0 ? sqrt($normSq) : 1.0, 4);
    }

    return ['vectors' => $vectors, 'norms' => $norms];
}

function recommendItems(array $model, array $productMap, array $recentIds, int $limit = 10, bool $diversity = true, float $priceWeight = 0.10): array {
    $vectors = $model['vectors'];
    $norms = $model['norms'];

    $valid = [];
    foreach ($recentIds as $id) {
        $sid = (string)$id;
        if (isset($vectors[$sid])) $valid[] = $sid;
    }
    if (empty($valid)) return [];

    $weights = [1.0, 0.65, 0.50, 0.35, 0.20];
    $qVec = [];
    $refPrices = [];
    foreach ($valid as $i => $pid) {
        $w = $weights[$i] ?? 0.1;
        foreach ($vectors[$pid] as $t => $v) {
            $qVec[$t] = ($qVec[$t] ?? 0.0) + $w * $v;
        }
        if (isset($productMap[$pid]['gia_ban'])) {
            $refPrices[] = (float)$productMap[$pid]['gia_ban'];
        }
    }

    $qNormSq = 0.0;
    foreach ($qVec as $v) $qNormSq += $v * $v;
    $qNorm = sqrt($qNormSq);
    if ($qNorm <= 0) return [];

    $avgRefPrice = !empty($refPrices) ? array_sum($refPrices) / count($refPrices) : 0;

    $scores = [];
    foreach ($vectors as $pid => $vec) {
        if (in_array($pid, $valid)) continue;

        $dot = 0.0;
        foreach ($vec as $t => $vVal) {
            if (isset($qVec[$t])) {
                $dot += $vVal * $qVec[$t];
            }
        }

        if ($dot > 0) {
            $cScore = $dot / ($qNorm * ($norms[$pid] ?? 1.0));
            $pScore = 1.0;
            $fScore = $cScore;

            if ($priceWeight > 0.0 && $avgRefPrice > 0) {
                $candPrice = (float)($productMap[$pid]['gia_ban'] ?? 0);
                if ($candPrice > 0) {
                    $maxP = max($candPrice, $avgRefPrice);
                    $pScore = max(0.0, 1.0 - (abs($candPrice - $avgRefPrice) / $maxP));
                    $fScore = (1.0 - $priceWeight) * $cScore + $priceWeight * $pScore;
                }
            }

            // Find matched features / terms
            $matchedTerms = [];
            foreach ($vec as $t => $vVal) {
                if (isset($qVec[$t])) {
                    $matchedTerms[$t] = $qVec[$t] * $vVal;
                }
            }
            arsort($matchedTerms);
            $topMatched = array_slice(array_keys($matchedTerms), 0, 4);

            $scores[$pid] = [
                'content_score' => round($cScore, 4),
                'price_score' => round($pScore, 4),
                'final_score' => round($fScore, 4),
                'matched_terms' => implode(', ', $topMatched)
            ];
        }
    }

    uasort($scores, fn($a, $b) => $b['final_score'] <=> $a['final_score']);

    $results = [];
    $seenFamilies = [];
    foreach ($scores as $pid => $s) {
        $p = $productMap[$pid];
        if ($diversity) {
            $fam = extractProductFamily($p['ten_san_pham']);
            if (isset($seenFamilies[$fam])) continue;
            $seenFamilies[$fam] = true;
        }

        $results[] = [
            'ma_san_pham' => $pid,
            'ten_san_pham' => $p['ten_san_pham'],
            'thuong_hieu' => $p['thuong_hieu'] ?? 'N/A',
            'category' => $p['danh_muc_day_du'] ?? 'N/A',
            'loai_da' => $p['loai_da'] ?? 'N/A',
            'gia_ban' => $p['gia_ban'] ?? 0,
            'content_score' => $s['content_score'],
            'price_score' => $s['price_score'],
            'final_score' => $s['final_score'],
            'matched_terms' => $s['matched_terms']
        ];
        if (count($results) >= $limit) break;
    }

    return $results;
}

// Build Config B Model (Optimal)
echo "\nBuilding Config B Model...\n";
$modelB = buildTfidfModel($productMap, [
    'ten' => 2, 'brand' => 1, 'category' => 3, 'skin' => 3, 'ingredient' => 2, 'description' => 1
]);

// 5 Evaluation Cases
$evalCases = [
    'Case 1: Sữa rửa mặt da dầu/mụn' => '1000',
    'Case 2: Serum' => '68',
    'Case 3: Kem dưỡng' => '83',
    'Case 4: Chống nắng' => '10',
    'Case 5: Son dưỡng môi' => '71'
];

echo "\n=================================================================\n";
echo "STEP 5: EVALUATION CASES (TOP 10 PER PROFILE WITH CONFIG B + DIVERSITY + PRICE 0.10)\n";
echo "=================================================================\n";

foreach ($evalCases as $caseTitle => $srcId) {
    $src = $productMap[$srcId];
    echo "\n-----------------------------------------------------------------\n";
    echo "PROFILE: $caseTitle\n";
    echo "SOURCE PRODUCT(S): [ID $srcId] " . $src['ten_san_pham'] . "\n";
    echo "  - Thương hiệu: " . ($src['thuong_hieu'] ?? 'N/A') . "\n";
    echo "  - Danh mục: " . ($src['danh_muc_day_du'] ?? 'N/A') . "\n";
    echo "  - Loại da: " . ($src['loai_da'] ?? 'N/A') . "\n";
    echo "  - Giá: " . number_format($src['gia_ban'] ?? 0) . " đ\n";
    echo "\nTOP 10 RECOMMENDATIONS:\n";

    $recs = recommendItems($modelB, $productMap, [$srcId], 10, true, 0.10);

    echo sprintf("%-3s | %-6s | %-8s | %-8s | %-12s | %-20s | %-18s | %s\n",
        "#", "ID", "Sim", "Final", "Thương hiệu", "Danh mục", "Matched Terms", "Tên Sản Phẩm");
    echo str_repeat("-", 120) . "\n";

    foreach ($recs as $idx => $r) {
        echo sprintf("%-3d | %-6s | %-8.4f | %-8.4f | %-12s | %-20s | %-18s | %s\n",
            $idx + 1,
            $r['ma_san_pham'],
            $r['content_score'],
            $r['final_score'],
            mb_substr($r['thuong_hieu'], 0, 12),
            mb_substr($r['category'], 0, 20),
            mb_substr($r['matched_terms'], 0, 18),
            mb_substr($r['ten_san_pham'], 0, 45)
        );
    }
}
