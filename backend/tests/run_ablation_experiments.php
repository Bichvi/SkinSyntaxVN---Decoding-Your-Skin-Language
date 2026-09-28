<?php
require_once __DIR__ . '/../app/config/db.php';
global $db;

$cursor = $db->san_pham->find(
    ['trang_thai' => 'active', 'gia_ban' => ['$gt' => 0]],
    ['projection' => [
        'ma_san_pham' => 1,
        'ten_san_pham' => 1,
        'danh_muc_day_du' => 1,
        'ma_danh_muc' => 1,
        'thuong_hieu' => 1,
        'loai_da' => 1,
        'thanh_phan' => 1,
        'thanh_phan_chinh' => 1,
        'mo_ta_ngan' => 1,
        'mo_ta' => 1,
        'gia_ban' => 1
    ]]
);

$products = [];
$productMap = [];
foreach ($cursor as $doc) {
    $p = (array)$doc;
    $pid = (string)$p['ma_san_pham'];
    $products[] = $p;
    $productMap[$pid] = $p;
}
$N = count($products);
echo "Loaded $N active products from Atlas.\n";

function tokenizeText(string $text): array {
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

// Function to extract normalized product family key
function extractProductFamily(string $name, string $brand = ''): string {
    $clean = mb_strtolower($name, 'UTF-8');
    // Strip common promotional prefixes
    $clean = preg_replace('/^(\[.*?\]|\(.*?\))\s*/u', '', $clean);
    // Strip volume / weight patterns (e.g. 400ml, 200g, 50ml, 30g, 150ml, 1.5g, etc.)
    $clean = preg_replace('/\b\d+(\.\d+)?\s*(ml|g|kg|l|oz|miếng|gói|viên|set|combo)\b/ui', '', $clean);
    // Strip multipack markers (e.g. x2, x3, combo 2, combo 3)
    $clean = preg_replace('/\b(combo\s*\d*|x\d+)\b/ui', '', $clean);
    // Remove punctuation & extra whitespace
    $clean = preg_replace('/[^\p{L}\p{N}\s]+/u', ' ', $clean);
    $clean = trim(preg_replace('/\s+/u', ' ', $clean));
    return $clean;
}

// Build TF-IDF model with given weights
function buildModel(array $products, array $weights): array {
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
        $desc = (string)($p['mo_ta_ngan'] ?? $p['mo_ta'] ?? '');
        $descSnippet = mb_substr(strip_tags($desc), 0, 300, 'UTF-8');

        $text = str_repeat($name . " ", $weights['ten']) .
                str_repeat($brand . " ", $weights['brand']) .
                str_repeat($cat . " ", $weights['category']) .
                str_repeat($skin . " ", $weights['skin']) .
                str_repeat($ing . " ", $weights['ingredient']) .
                str_repeat($descSnippet . " ", $weights['description']);

        $tokens = tokenizeText($text);
        $termCounts = array_count_values($tokens);
        $docTokens[$pid] = $termCounts;

        foreach (array_keys($termCounts) as $t) {
            $docFreq[$t] = ($docFreq[$t] ?? 0) + 1;
        }
    }

    $filteredDocFreq = [];
    foreach ($docFreq as $t => $df) {
        if ($df >= 3 && $df <= $N * 0.80) {
            $filteredDocFreq[$t] = log((1 + $N) / (1 + $df)) + 1;
        }
    }

    $tfidfVectors = [];
    $vectorNorms = [];

    foreach ($docTokens as $pid => $termCounts) {
        $totalTerms = array_sum($termCounts);
        $scoredTerms = [];

        foreach ($termCounts as $t => $count) {
            if (isset($filteredDocFreq[$t])) {
                $tf = $count / $totalTerms;
                $idf = $filteredDocFreq[$t];
                $scoredTerms[$t] = $tf * $idf;
            }
        }
        arsort($scoredTerms);
        $topTerms = array_slice($scoredTerms, 0, 30, true);

        $vec = [];
        $normSq = 0.0;
        foreach ($topTerms as $t => $val) {
            $rval = round($val, 4);
            $vec[$t] = $rval;
            $normSq += $rval * $rval;
        }
        $norm = sqrt($normSq);
        $tfidfVectors[$pid] = $vec;
        $vectorNorms[$pid] = round($norm > 0 ? $norm : 1.0, 4);
    }

    return [
        'vectors' => $tfidfVectors,
        'norms' => $vectorNorms
    ];
}

// Configurations for Ablation
$configs = [
    'A (Current)' => ['ten' => 3, 'brand' => 2, 'category' => 2, 'skin' => 2, 'ingredient' => 1, 'description' => 1],
    'B'           => ['ten' => 2, 'brand' => 1, 'category' => 3, 'skin' => 3, 'ingredient' => 2, 'description' => 1],
    'C'           => ['ten' => 1, 'brand' => 1, 'category' => 3, 'skin' => 3, 'ingredient' => 3, 'description' => 1],
    'D (Uniform)' => ['ten' => 1, 'brand' => 1, 'category' => 1, 'skin' => 1, 'ingredient' => 1, 'description' => 1],
];

echo "\nBuilding models for 4 configurations...\n";
$models = [];
foreach ($configs as $cName => $w) {
    $models[$cName] = buildModel($products, $w);
}
echo "All 4 models built successfully.\n";

// Function to query recommendations
function queryRecs(array $model, array $productMap, array $recentIds, int $limit = 10, bool $applyDiversity = false, float $priceWeight = 0.0): array {
    $vectors = $model['vectors'];
    $norms = $model['norms'];

    $validRecent = [];
    foreach ($recentIds as $id) {
        $strId = (string)$id;
        if (isset($vectors[$strId])) $validRecent[] = $strId;
    }
    if (empty($validRecent)) return [];

    $weights = [1.0, 0.65, 0.50, 0.35, 0.20];
    $queryVec = [];
    $refPrices = [];

    foreach ($validRecent as $idx => $pid) {
        $w = $weights[$idx] ?? 0.1;
        foreach ($vectors[$pid] as $t => $val) {
            $queryVec[$t] = ($queryVec[$t] ?? 0.0) + $w * $val;
        }
        if (isset($productMap[$pid]['gia_ban'])) {
            $refPrices[] = (float)$productMap[$pid]['gia_ban'];
        }
    }

    $qNormSq = 0.0;
    foreach ($queryVec as $val) $qNormSq += $val * $val;
    $qNorm = sqrt($qNormSq);
    if ($qNorm <= 0) return [];

    $avgRefPrice = !empty($refPrices) ? array_sum($refPrices) / count($refPrices) : 0;

    $scores = [];
    foreach ($vectors as $pid => $vec) {
        if (in_array($pid, $validRecent)) continue;

        $dot = 0.0;
        foreach ($vec as $t => $vVal) {
            if (isset($queryVec[$t])) {
                $dot += $vVal * $queryVec[$t];
            }
        }

        if ($dot > 0 && $qNorm > 0) {
            $contentScore = $dot / ($qNorm * ($norms[$pid] ?? 1.0));

            $finalScore = $contentScore;
            $priceScore = 1.0;

            if ($priceWeight > 0.0 && $avgRefPrice > 0) {
                $candPrice = (float)($productMap[$pid]['gia_ban'] ?? 0);
                if ($candPrice > 0) {
                    $maxP = max($candPrice, $avgRefPrice);
                    $priceScore = max(0.0, 1.0 - (abs($candPrice - $avgRefPrice) / $maxP));
                    $finalScore = (1.0 - $priceWeight) * $contentScore + $priceWeight * $priceScore;
                }
            }

            $scores[$pid] = [
                'content_score' => round($contentScore, 4),
                'price_score' => round($priceScore, 4),
                'final_score' => round($finalScore, 4)
            ];
        }
    }

    // Sort by final_score DESC
    uasort($scores, fn($a, $b) => $b['final_score'] <=> $a['final_score']);

    // Diversity filter if enabled
    $results = [];
    $seenFamilies = [];

    foreach ($scores as $pid => $s) {
        $p = $productMap[$pid];
        if ($applyDiversity) {
            $fam = extractProductFamily($p['ten_san_pham'], $p['thuong_hieu'] ?? '');
            if (isset($seenFamilies[$fam])) {
                continue; // Skip duplicate variant family
            }
            $seenFamilies[$fam] = true;
        }

        $results[] = [
            'ma_san_pham' => $pid,
            'ten_san_pham' => $p['ten_san_pham'],
            'thuong_hieu' => $p['thuong_hieu'] ?? '',
            'danh_muc' => $p['danh_muc_day_du'] ?? '',
            'loai_da' => $p['loai_da'] ?? '',
            'gia_ban' => $p['gia_ban'] ?? 0,
            'content_score' => $s['content_score'],
            'price_score' => $s['price_score'],
            'final_score' => $s['final_score']
        ];

        if (count($results) >= $limit) break;
    }

    return $results;
}

// -------------------------------------------------------------
// STEP 2: FEATURE ABLATION ON CASE 1 (Eucerin Cleanser 1000)
// -------------------------------------------------------------
echo "\n=======================================================\n";
echo "STEP 2: FEATURE ABLATION COMPARISON (No Diversity Filter)\n";
echo "Query: [1000] Gel Rửa Mặt Eucerin Cho Da Nhờn Mụn 75ml\n";
echo "=======================================================\n";

foreach ($configs as $cName => $w) {
    echo "\n--- Config $cName ---\n";
    $recs = queryRecs($models[$cName], $productMap, ['1000'], 10, false, 0.0);
    echo sprintf("%-4s | %-8s | %-6s | %-12s | %s\n", "#", "ID", "Sim", "Thương Hiệu", "Tên Sản Phẩm");
    echo str_repeat("-", 80) . "\n";
    foreach ($recs as $idx => $r) {
        echo sprintf("%-4d | %-8s | %-6.4f | %-12s | %s\n",
            $idx + 1,
            $r['ma_san_pham'],
            $r['final_score'],
            mb_substr($r['thuong_hieu'], 0, 12),
            mb_substr($r['ten_san_pham'], 0, 48)
        );
    }
}

// -------------------------------------------------------------
// STEP 3: DIVERSITY TEST WITH CONFIG B & C
// -------------------------------------------------------------
echo "\n=======================================================\n";
echo "STEP 3: WITH PRODUCT FAMILY DIVERSITY FILTER\n";
echo "Query: [1000] Gel Rửa Mặt Eucerin Cho Da Nhờn Mụn 75ml\n";
echo "=======================================================\n";

foreach (['A (Current)', 'B', 'C'] as $cName) {
    echo "\n--- Config $cName + Diversity Filter (Max 1 / Family) ---\n";
    $recs = queryRecs($models[$cName], $productMap, ['1000'], 10, true, 0.0);
    echo sprintf("%-4s | %-8s | %-6s | %-12s | %s\n", "#", "ID", "Sim", "Thương Hiệu", "Tên Sản Phẩm");
    echo str_repeat("-", 80) . "\n";
    foreach ($recs as $idx => $r) {
        echo sprintf("%-4d | %-8s | %-6.4f | %-12s | %s\n",
            $idx + 1,
            $r['ma_san_pham'],
            $r['final_score'],
            mb_substr($r['thuong_hieu'], 0, 12),
            mb_substr($r['ten_san_pham'], 0, 48)
        );
    }
}

// -------------------------------------------------------------
// STEP 4: PRICE WEIGHT EXPERIMENT
// -------------------------------------------------------------
echo "\n=======================================================\n";
echo "STEP 4: PRICE IMPACT (Pure Cosine vs 0.90 Content + 0.10 Price)\n";
echo "Query: [1000] Eucerin 75ml (Price: 163,000 đ)\n";
echo "=======================================================\n";

echo "--- Without Price (Pure Cosine, Config B + Diversity) ---\n";
$pure = queryRecs($models['B'], $productMap, ['1000'], 6, true, 0.0);
foreach ($pure as $idx => $r) {
    echo sprintf("%d. [%s] Sim: %.4f | Giá: %s đ | %s\n",
        $idx + 1, $r['ma_san_pham'], $r['final_score'], number_format($r['gia_ban']), mb_substr($r['ten_san_pham'], 0, 45));
}

echo "\n--- With Price (0.90 Content + 0.10 Price, Config B + Diversity) ---\n";
$withPrice = queryRecs($models['B'], $productMap, ['1000'], 6, true, 0.10);
foreach ($withPrice as $idx => $r) {
    echo sprintf("%d. [%s] Final: %.4f (Cont: %.4f, Prc: %.4f) | Giá: %s đ | %s\n",
        $idx + 1, $r['ma_san_pham'], $r['final_score'], $r['content_score'], $r['price_score'], number_format($r['gia_ban']), mb_substr($r['ten_san_pham'], 0, 45));
}
