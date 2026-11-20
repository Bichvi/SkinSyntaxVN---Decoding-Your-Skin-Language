<?php

require_once __DIR__ . '/../models/PhienLive.php';

final class LiveOffer {
    public static function openDeals(array $live, int $now): array {
        $deals = array_filter($live['danh_sach_deal'] ?? [], static function (array $deal) use ($now): bool {
            $start = (int)($deal['starts_at'] ?? 0);
            $end = (int)($deal['ends_at'] ?? 0);
            return ($start === 0 || $start <= $now) && ($end === 0 || $now < $end);
        });
        $pinned = (string)($live['ma_san_pham_ghim'] ?? '');
        $start = strtotime((string)($live['deal_bat_dau'] ?? '')) ?: 0;
        $end = strtotime((string)($live['deal_ket_thuc'] ?? '')) ?: 0;
        if ($pinned !== '' && $start <= $now && ($end === 0 || $now < $end)) {
            array_unshift($deals, [
                'deal_id' => 'pinned', 'ma_san_pham' => $pinned,
                'gia_uu_dai_live' => $live['gia_uu_dai_live'] ?? 0,
                'so_luong_kho_deal' => $live['so_luong_kho_deal'] ?? 0, 'ends_at' => $end,
            ]);
        }
        return $deals;
    }

    public static function find(array $live, string $productId, int $now, string $dealId = ''): ?array {
        if (($live['status'] ?? '') !== 'live') return null;
        foreach (self::openDeals($live, $now) as $deal) {
            if ((string)($deal['ma_san_pham'] ?? '') !== $productId) continue;
            if ($dealId === '' || $dealId === (string)($deal['deal_id'] ?? '')) return $deal;
        }
        return null;
    }

    public static function cartPricing($db, string $productId, int $qty, array $pricing): array {
        $reference = $_SESSION['live_cart_offers'][$productId] ?? null;
        if (($reference['quantity'] ?? 0) < $qty) return $pricing;
        try {
            $live = (new PhienLive($db))->findById((string)$reference['room']);
            $deal = $live ? self::find($live, $productId, time(), (string)$reference['deal']) : null;
        } catch (Throwable $error) {
            error_log('Live cart pricing unavailable: ' . get_class($error));
            return $pricing;
        }
        $limit = (int)($deal['so_luong_kho_deal'] ?? 0);
        $price = (int)round((float)($deal['gia_uu_dai_live'] ?? 0));
        if (($limit > 0 && $qty > $limit) || $price <= 0 || $price >= (int)$pricing['effective_price']) {
            return $pricing;
        }
        $pricing['effective_price'] = $price;
        $pricing['is_live_offer'] = true;
        return $pricing;
    }
}
