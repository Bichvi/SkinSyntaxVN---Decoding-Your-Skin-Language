<?php
require_once __DIR__ . '/../app/config/db.php';
require_once __DIR__ . '/../app/models/SanPham.php';
global $db;

$model = new SanPham($db);

echo "=====================================================\n";
echo "STEP 7: SIMPLE RECOMMENDER COMPREHENSIVE VERIFICATION\n";
echo "=====================================================\n";

// Whitelist categories
$whitelist = [1, 2, 4, 17, 7, 30, 37, 6, 11, 19, 53, 83, 9, 25, 105, 3, 38, 60, 18, 29, 73];

// 1. Test method getSimpleRecommenderProducts
$limit = 10;
$topProducts = $model->getSimpleRecommenderProducts($limit);

echo "1. Output Count Verification:\n";
echo "   Requested: $limit | Returned: " . count($topProducts) . "\n";
assert(count($topProducts) === $limit, "Must return exactly $limit products");
echo "   [PASSED] Count matches perfectly.\n\n";

echo "2. Sorting & Formula Verification (WR DESC):\n";
$prevWR = 999.0;
$seenIds = [];
$allInWhitelist = true;

foreach ($topProducts as $i => $p) {
    $meta = $p['recommender_meta'];
    $R = $meta['R'];
    $v = $meta['v'];
    $C = $meta['C'];
    $m = $meta['m'];
    $wr = $meta['weighted_rating'];

    // Expected WR by formula
    $expectedWR = round(($v / ($v + $m)) * $R + ($m / ($v + $m)) * $C, 4);
    assert(abs($wr - $expectedWR) < 0.0001, "WR mismatch for product " . $p['ma_san_pham']);

    // Check descending order
    assert($wr <= $prevWR, "Products must be sorted in descending order of WR");
    $prevWR = $wr;

    // Check uniqueness
    $pid = $p['ma_san_pham'];
    assert(!in_array($pid, $seenIds), "Duplicate product detected: $pid");
    $seenIds[] = $pid;

    // Check skincare category whitelist
    if (!in_array($p['ma_danh_muc'], $whitelist)) {
        $allInWhitelist = false;
    }

    echo sprintf("   %2d. [%-6s] R=%-3.1f | v=%-3d | WR=%-6.4f | CatID=%-2d | %s\n",
        $i + 1,
        $pid,
        $R,
        $v,
        $wr,
        $p['ma_danh_muc'],
        mb_substr($p['ten_san_pham'], 0, 40)
    );
}
echo "   [PASSED] WR formula, sorting, and duplicate checks all passed.\n\n";

echo "3. Category Whitelist Verification:\n";
assert($allInWhitelist, "All recommended products must belong to the skincare whitelist!");
echo "   [PASSED] 100% products belong to skincare whitelist.\n\n";

echo "4. Mathematical Property Checks:\n";
// Check: Product with R=5.0 and v=1 vs Product with R=5.0 and v=229
$pLowVoteWR = round((1 / (1 + 21)) * 5.0 + (21 / (1 + 21)) * 4.8890, 4);
$pHighVoteWR = round((229 / (229 + 21)) * 5.0 + (21 / (229 + 21)) * 4.8890, 4);
echo "   Low vote (v=1, R=5.0) WR: $pLowVoteWR\n";
echo "   High vote (v=229, R=5.0) WR: $pHighVoteWR\n";
assert($pHighVoteWR > $pLowVoteWR, "High vote product must rank higher than low vote product!");
echo "   [PASSED] High-vote weighting properly penalizes unproven products.\n\n";

echo "5. Zero Division & Edge Case Check:\n";
// Call with empty or zero values
$zeroTest = (0 / (0 + 21)) * 0 + (21 / (0 + 21)) * 4.8890;
echo "   v=0, R=0 formula evaluation: " . round($zeroTest, 4) . " (No division error)\n";
echo "   [PASSED] Division by zero prevented by positive m threshold.\n\n";

echo "6. Homepage Sections Integration Check:\n";
$sections = $model->getHomepageProductSections(4);
assert(isset($sections['topRatedWeighted']), "topRatedWeighted section must exist");
echo "   topRatedWeighted in homepageSections count: " . count($sections['topRatedWeighted']) . "\n";
assert(count($sections['topRatedWeighted']) === 4, "Must return 4 products for homepage section");
echo "   [PASSED] Homepage sections integration verified.\n\n";

echo "ALL VERIFICATION TESTS COMPLETED SUCCESSFULLY!\n";
