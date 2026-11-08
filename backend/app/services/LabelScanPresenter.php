<?php
// Chụp bảng thành phần: gắn thông tin sản phẩm shop (tên, giá, ảnh, link) vào kết quả Flask đã validate.
// Thuần: không session, không DB; việc tra sản phẩm do caller truyền vào (callable $lookup).
// Chuỗi để nguyên (widget tự escape), chỉ kẹp độ dài.

final class LabelScanPresenter
{
    /**
     * @param array    $result  kết quả SearchRecommendClient::validateLabelResponse()
     * @param callable $lookup  fn(array $ids): array<string,array> — hàng sản phẩm theo id (ma_san_pham, ten_san_pham, gia_ban, hinh_anh...)
     */
    public static function present(array $result, callable $lookup): array
    {
        $ids = [];
        $matched = $result['product']['matched_product_id'] ?? null;
        if (is_string($matched) && $matched !== '') {
            $ids[] = $matched;
        }
        foreach ((array)($result['conflicts'] ?? []) as $c) {
            if (is_array($c) && is_string($c['cart_product_id'] ?? null)) {
                $ids[] = $c['cart_product_id'];
            }
        }
        foreach ((array)($result['alternatives'] ?? []) as $a) {
            if (is_array($a) && is_string($a['product_id'] ?? null)) {
                $ids[] = $a['product_id'];
            }
        }
        $ids = array_values(array_unique($ids));
        $rows = [];
        if ($ids) {
            $found = $lookup($ids);
            foreach (is_array($found) ? $found : [] as $key => $row) {
                if (is_array($row)) {
                    $rows[(string)($row['ma_san_pham'] ?? $key)] = $row;
                }
            }
        }

        if (is_string($matched) && $matched !== '') {
            if (isset($rows[$matched])) {
                $result['product']['url'] = self::detailUrl($matched);
            } else {
                $result['product']['matched_product_id'] = null;
                $result['product']['match_score'] = null;
            }
        }

        $conflicts = [];
        foreach ((array)($result['conflicts'] ?? []) as $c) {
            $id = is_array($c) ? (string)($c['cart_product_id'] ?? '') : '';
            if ($id === '' || !isset($rows[$id])) {
                continue;
            }
            $c['cart_product'] = self::card($id, $rows[$id]);
            $conflicts[] = $c;
        }
        $result['conflicts'] = $conflicts;

        $alts = [];
        foreach ((array)($result['alternatives'] ?? []) as $a) {
            $id = is_array($a) ? (string)($a['product_id'] ?? '') : '';
            if ($id === '' || !isset($rows[$id])) {
                continue;
            }
            $alts[] = self::card($id, $rows[$id]) + [
                'reason_code' => (string)($a['reason_code'] ?? ''),
                'reason_params' => array_values((array)($a['reason_params'] ?? [])),
            ];
        }
        $result['alternatives'] = array_slice($alts, 0, 3);

        if (isset($result['ingredients']['raw_text']) && is_string($result['ingredients']['raw_text'])) {
            $result['ingredients']['raw_text'] = mb_substr($result['ingredients']['raw_text'], 0, 6000, 'UTF-8');
        }
        if (isset($result['summary_vi']) && is_string($result['summary_vi'])) {
            $result['summary_vi'] = mb_substr($result['summary_vi'], 0, 600, 'UTF-8');
        }
        return $result;
    }

    /** @return array{id:string,name:string,price:int|float,image_url:string,url:string} */
    private static function card(string $id, array $row): array
    {
        $price = $row['gia_ban'] ?? $row['gia'] ?? 0;
        return [
            'id' => $id,
            'name' => mb_substr((string)($row['ten_san_pham'] ?? ''), 0, 200, 'UTF-8'),
            'price' => is_numeric($price) ? $price + 0 : 0,
            'image_url' => self::imageUrl((string)($row['link_hinh_anh'] ?? $row['hinh_anh'] ?? '')),
            'url' => self::detailUrl($id),
        ];
    }

    private static function detailUrl(string $id): string
    {
        $base = defined('BASE_URL') ? (string)BASE_URL : '.';
        return $base . '/index.php?r=chitiet&id=' . rawurlencode($id);
    }

    private static function imageUrl(string $raw): string
    {
        if (function_exists('resolve_image_url')) {
            return mb_substr((string)resolve_image_url($raw), 0, 500, 'UTF-8');
        }
        $parts = preg_split('/\s*\|\s*/', trim($raw)) ?: [];
        return mb_substr(trim((string)($parts[0] ?? '')), 0, 500, 'UTF-8');
    }
}
