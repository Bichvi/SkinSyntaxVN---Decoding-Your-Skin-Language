<?php
// Run: php backend/tests/home_reason_text_test.php  (exit 0 = PASS). Pure.
declare(strict_types=1);

require_once __DIR__ . '/../app/services/HomeReasonText.php';

$failures = 0;
function check(bool $condition, string $label): void
{
    global $failures;
    if ($condition) {
        echo "PASS: {$label}\n";
        return;
    }
    $failures++;
    echo "FAIL: {$label}\n";
}

$eq = static fn(array $r, string $exp): bool => HomeReasonText::fromReason($r) === $exp;

check($eq(['code' => 'concern_ingredient', 'concern' => 'Thâm mụn', 'ingredients' => ['Niacinamide']], 'Chứa Niacinamide, hỗ trợ thâm mụn'), 'concern_ingredient');
check($eq(['code' => 'concern_target', 'concern' => 'Lỗ chân lông to'], 'Dành cho lỗ chân lông to'), 'concern_target');
check($eq(['code' => 'skin_fit', 'skin_type' => 'Da dầu', 'concerns' => []], 'Phù hợp Da dầu'), 'skin_fit without concerns');
check($eq(['code' => 'skin_fit', 'skin_type' => 'Da dầu', 'concerns' => ['Mụn']], 'Phù hợp Da dầu · Hỗ trợ Mụn'), 'skin_fit one concern');
check($eq(['code' => 'skin_fit', 'skin_type' => 'Da dầu', 'concerns' => ['Mụn', 'Dầu thừa']], 'Phù hợp Da dầu · Hỗ trợ Mụn, Dầu thừa'), 'skin_fit two concerns');
check($eq(['code' => 'seen_shared_ingredient', 'seed_name' => 'Serum A', 'ingredients' => ['Niacinamide', 'Retinol']], 'Cùng chứa Niacinamide, Retinol với Serum A'), 'seen_shared_ingredient two');
check($eq(['code' => 'seen_shared_ingredient', 'seed_name' => 'Serum A', 'ingredients' => ['Niacinamide']], 'Cùng chứa Niacinamide với Serum A'), 'seen_shared_ingredient one');
check($eq(['code' => 'seen_same_category', 'seed_name' => 'Serum A'], 'Cùng loại với Serum A'), 'seen_same_category');

$long = str_repeat('a', 40);
$exp = 'Cùng loại với ' . mb_strimwidth($long, 0, 30, '…');
check($eq(['code' => 'seen_same_category', 'seed_name' => $long], $exp) && mb_strlen($exp) === mb_strlen('Cùng loại với ') + 30, 'seed truncated to 30 chars');
check($eq(['code' => 'weird'], 'Gợi ý cho bạn'), 'unknown code fallback');
check($eq([], 'Gợi ý cho bạn'), 'empty reason fallback');

exit($failures > 0 ? 1 : 0);
