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

// omit_seed_id: seed trùng SP ở tiêu đề khối -> câu ngắn, không "với ..."
$ing2 = ['code' => 'seen_shared_ingredient', 'seed_id' => '7', 'seed_name' => 'Serum A', 'ingredients' => ['Niacinamide', 'Retinol']];
$cat = ['code' => 'seen_same_category', 'seed_id' => '7', 'seed_name' => 'Serum A'];
check(HomeReasonText::fromReason($ing2, ['omit_seed_id' => '7']) === 'Cùng chứa Niacinamide, Retinol', 'omit seed: shared two');
check(HomeReasonText::fromReason(['seed_id' => 7] + $ing2, ['omit_seed_id' => '7']) === 'Cùng chứa Niacinamide, Retinol', 'omit seed: int seed_id compared as string');
check(HomeReasonText::fromReason(['code' => 'seen_shared_ingredient', 'seed_id' => '7', 'ingredients' => ['Niacinamide']], ['omit_seed_id' => '7']) === 'Cùng chứa Niacinamide', 'omit seed: shared one, no seed name needed');
check(HomeReasonText::fromReason($cat, ['omit_seed_id' => '7', 'category' => 'Serum / Tinh Chất']) === 'Cùng loại Serum / Tinh Chất', 'omit seed: same category with category');
check(HomeReasonText::fromReason($cat, ['omit_seed_id' => '7']) === 'Cùng loại sản phẩm', 'omit seed: same category without category');
check(HomeReasonText::fromReason($cat, ['omit_seed_id' => '7', 'category' => '  ']) === 'Cùng loại sản phẩm', 'omit seed: blank category');
check(HomeReasonText::fromReason($ing2, ['omit_seed_id' => '8']) === 'Cùng chứa Niacinamide, Retinol với Serum A', 'different seed keeps "với"');
check(HomeReasonText::fromReason($cat, ['omit_seed_id' => '8', 'category' => 'Serum']) === 'Cùng loại với Serum A', 'different seed same_category unchanged');
check(HomeReasonText::fromReason($ing2, ['category' => 'Serum']) === 'Cùng chứa Niacinamide, Retinol với Serum A', 'no omit option unchanged');
check(HomeReasonText::fromReason(['code' => 'seen_shared_ingredient', 'seed_id' => '7', 'ingredients' => []], ['omit_seed_id' => '7']) === 'Gợi ý cho bạn', 'omit seed: no ingredients falls back');
$longSeed = ['code' => 'seen_shared_ingredient', 'seed_id' => '9', 'seed_name' => "Combo L'Oreal Serum Hyaluronic Acid Cấp Ẩm 30ml", 'ingredients' => ['Sodium Hyaluronate']];
check(str_ends_with(HomeReasonText::fromReason($longSeed), '…'), 'long seed name is cut by default');
check(HomeReasonText::fromReason($longSeed, ['full_seed' => true]) === "Cùng chứa Sodium Hyaluronate với Combo L'Oreal Serum Hyaluronic Acid Cấp Ẩm 30ml", 'full_seed keeps whole seed name');

exit($failures > 0 ? 1 : 0);
