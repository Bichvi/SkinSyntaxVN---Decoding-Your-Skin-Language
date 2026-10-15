<?php
declare(strict_types=1);

/**
 * Test taxonomy vấn đề da (GoiYContentBased::canonicalConcerns) cho đủ 6 lựa chọn của khảo sát.
 * Không cần DB. Chạy trong container: php /var/www/html/tests/canonical_concerns_test.php
 */

require_once __DIR__ . '/../app/models/GoiYContentBased.php';

$model = new GoiYContentBased(null);
$cases = [
    'Mụn viêm, sưng đỏ' => 'inflammatory_acne',
    'Mụn viêm' => 'inflammatory_acne',
    'sưng đỏ' => 'inflammatory_acne',
    'Mụn ẩn, mụn đầu đen' => 'comedonal_acne',
    'Lỗ chân lông to' => 'large_pores',
    'Thâm mụn, sạm nám, tàn nhang' => 'hyperpigmentation',
    'Lão hóa, nếp nhăn' => 'aging',
    'Da khô căng, bong tróc' => 'dry_flaky',
];

$failed = 0;
foreach ($cases as $raw => $expected) {
    $actual = $model->canonicalConcerns([$raw]);
    if ($actual === [$expected]) {
        echo "PASS {$raw} => {$expected}\n";
        continue;
    }
    $failed++;
    echo "FAIL {$raw} => " . json_encode($actual, JSON_UNESCAPED_UNICODE) . " (expected {$expected})\n";
}

// Chuỗi gộp bằng dấu phẩy (dạng lưu trong khach_hang.van_de_da) vẫn tách và giữ thứ tự.
$combined = $model->canonicalConcerns('Lỗ chân lông to, Lão hóa');
if ($combined === ['large_pores', 'aging']) {
    echo "PASS combined string\n";
} else {
    $failed++;
    echo 'FAIL combined string => ' . json_encode($combined) . "\n";
}

echo $failed === 0 ? "ALL PASS\n" : "{$failed} FAIL\n";
exit($failed === 0 ? 0 : 1);
