<?php
// Run: php backend/tests/product_video_price_claim_test.php  (exit 0 = PASS)
declare(strict_types=1);

require_once __DIR__ . '/../app/models/ProductVideo.php';

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

// Spec section 7: product 1024, gia_ban 299000, gia_thi_truong 350000, flash campaign 12 at 249000 ending 1792256340.
$END = 1792256340;
$now = 1792206000; // 2026-10-16T03:00:00Z
$claim = [
    'product_id' => '1024', 'regular_price' => 299000, 'market_price' => 350000,
    'flash' => ['campaign_id' => 12, 'flash_price' => 249000, 'end_ts' => $END],
    'valid_until_ts' => $END,
];
$pricing = static fn(array $over = []): array => array_merge([
    'regular_price' => 299000, 'market_price' => 350000, 'is_flash_sale' => true,
    'campaign_id' => 12, 'effective_price' => 249000,
], $over);
$noFlash = ['is_flash_sale' => false, 'campaign_id' => null, 'effective_price' => 299000];

check(ProductVideo::priceClaimHolds(null, $pricing(), $now) === true, 'claim null -> true');
check(ProductVideo::priceClaimHolds($claim, $pricing(), $now) === true, 'unchanged -> holds');
check(ProductVideo::priceClaimHolds($claim, $pricing(), $END + 1) === false, 'flash_ended: now after valid_until_ts');
check(ProductVideo::priceClaimHolds($claim, $pricing(), $END) === false, 'flash_ended: now equals valid_until_ts');
check(ProductVideo::priceClaimHolds($claim, $pricing(['regular_price' => 279000]), $now) === false, 'regular_changed');
check(ProductVideo::priceClaimHolds($claim, $pricing(['market_price' => 360000]), $now) === false, 'market_changed');
check(ProductVideo::priceClaimHolds($claim, $pricing($noFlash), $now) === false, 'flash_changed: campaign turned off');
check(ProductVideo::priceClaimHolds($claim, $pricing(['effective_price' => 239000]), $now) === false, 'flash_changed: flash price differs');
check(ProductVideo::priceClaimHolds($claim, $pricing(['campaign_id' => 13]), $now) === false, 'flash_changed: campaign id differs');

$regularClaim = ['product_id' => '1024', 'regular_price' => 299000, 'market_price' => 350000, 'flash' => null, 'valid_until_ts' => null];
check(ProductVideo::priceClaimHolds($regularClaim, $pricing(), $now) === true, 'claim without flash still holds when a flash starts');
check(ProductVideo::priceClaimHolds($regularClaim, $pricing($noFlash), $now) === true, 'claim without flash holds with regular pricing');
$noMarket = array_merge($regularClaim, ['market_price' => null]);
check(ProductVideo::priceClaimHolds($noMarket, $pricing(['market_price' => 999]), $now) === true, 'null claim market_price is not compared');

// BSON-like object claim is normalized (nested stdClass stands in for BSONDocument).
$objClaim = (object)array_merge($claim, ['flash' => (object)$claim['flash']]);
check(ProductVideo::priceClaimHolds($objClaim, $pricing(), $now) === true, 'BSON-like object claim is normalized (holds)');
check(ProductVideo::priceClaimHolds($objClaim, $pricing(['regular_price' => 1]), $now) === false, 'BSON-like object claim is normalized (mismatch)');

// dropExpiredPriceClaims
$items = [
    ['video_id' => 'a'],                                  // cache entry written before this feature
    ['video_id' => 'b', 'price_valid_until_ts' => null],
    ['video_id' => 'c', 'price_valid_until_ts' => $now - 1],
    ['video_id' => 'd', 'price_valid_until_ts' => $now],
    ['video_id' => 'e', 'price_valid_until_ts' => $now + 60],
];
check(array_column(ProductVideo::dropExpiredPriceClaims($items, $now), 'video_id') === ['a', 'b', 'e'],
    'expired item dropped, future item kept, null kept');
check(array_keys(ProductVideo::dropExpiredPriceClaims($items, $now)) === [0, 1, 2], 'result is re-indexed');
check(array_column(ProductVideo::dropExpiredPriceClaims([['video_id' => 'a'], ['video_id' => 'b']], $now), 'video_id') === ['a', 'b'],
    'cache items without price_valid_until_ts are kept');

exit($failures === 0 ? 0 : 1);
