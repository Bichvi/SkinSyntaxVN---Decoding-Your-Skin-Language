<?php

require_once __DIR__ . '/../../app/services/LiveOffer.php';

$now = strtotime('2026-11-20 10:00:00');
$live = [
    'status' => 'live', 'ma_san_pham_ghim' => 'face-wash',
    'deal_bat_dau' => '2026-11-20 09:55:00', 'deal_ket_thuc' => '2026-11-20 10:01:00',
    'gia_uu_dai_live' => 90000, 'so_luong_kho_deal' => 2,
    'danh_sach_deal' => [
        ['deal_id' => 'legacy', 'ma_san_pham' => 'face-wash', 'gia_uu_dai_live' => 100000],
        ['deal_id' => 'new', 'ma_san_pham' => 'serum', 'starts_at' => $now, 'ends_at' => $now + 60],
        ['deal_id' => 'future', 'ma_san_pham' => 'cream', 'starts_at' => $now + 1, 'ends_at' => $now + 120],
    ],
];
$cases = [
    'pinned SKU wins for existing clients' => LiveOffer::find($live, 'face-wash', $now)['deal_id'] === 'pinned',
    'queued offer can be selected explicitly' => LiveOffer::find($live, 'face-wash', $now, 'legacy')['deal_id'] === 'legacy',
    'wrong deal cannot authorize another SKU' => LiveOffer::find($live, 'serum', $now, 'legacy') === null,
    'availability begins inclusively' => LiveOffer::find($live, 'serum', $now) !== null,
    'availability ends exclusively' => LiveOffer::find($live, 'serum', $now + 60) === null,
    'expired pin cannot fall back to same SKU in queue' => LiveOffer::find($live, 'face-wash', $now + 60, 'pinned') === null,
    'future offer cannot be purchased' => LiveOffer::find($live, 'cream', $now) === null,
    'replay offers cannot be purchased' => LiveOffer::find(array_replace($live, ['status' => 'replay_ready']), 'face-wash', $now) === null,
    'regular cart prices require no live database or session offer' => LiveOffer::cartPricing(null, 'face-wash', 1, ['effective_price' => 140000]) === ['effective_price' => 140000],
];
$collection = new class {
    public array $room;
    public function findOne(array $filter): array { return $this->room; }
};
$collection->room = [
    'ma_phong' => 'A', 'trang_thai' => 'live', 'stream_state' => 'live', 'stream_last_seen' => time(),
    'danh_sach_deal' => [
        ['deal_id' => 'window', 'ma_san_pham' => 'serum', 'starts_at' => time() - 60,
            'ends_at' => time() + 60, 'gia_uu_dai_live' => 90000, 'so_luong_kho_deal' => 2],
    ],
];
$db = (object)['phien_live' => $collection];
$regular = ['effective_price' => 140000];
$_SESSION['live_cart_offers']['serum'] = ['room' => 'A', 'deal' => 'window', 'quantity' => 2];
$cases['live price is resolved from persisted offer'] =
    LiveOffer::cartPricing($db, 'serum', 2, $regular)['effective_price'] === 90000;
$cases['extra cart quantity cannot expand the live entitlement'] =
    LiveOffer::cartPricing($db, 'serum', 3, $regular) === $regular;
$cases['existing flash sale price is kept when lower than live'] =
    LiveOffer::cartPricing($db, 'serum', 1, ['effective_price' => 80000]) === ['effective_price' => 80000];
$collection->room['danh_sach_deal'][0]['ends_at'] = time();
$cases['expired offer is repriced on checkout'] =
    LiveOffer::cartPricing($db, 'serum', 2, $regular) === $regular;
unset($_SESSION['live_cart_offers']);
foreach ($cases as $name => $valid) {
    echo ($valid ? 'PASS ' : 'FAIL ') . $name . PHP_EOL;
}
exit(in_array(false, $cases, true) ? 1 : 0);
