<?php
// Smart Search Bước 06: ghép response Flask (đã validate) với dữ liệu SP từ Mongo thành $searchFallback cho view.
// Thuần (không DB): dữ liệu SP lấy qua callable để test được.
require_once __DIR__ . '/SearchReasonFormatter.php';

final class SearchFallbackBuilder
{
    public const BEST_SELLER_SORT = 'ban_chay';
    public const BEST_SELLER_LIMIT = 8;

    /**
     * @param array|null $response kết quả SearchRecommendClient::recommend (null = lỗi/circuit mở)
     * @param callable $findVisibleByIds fn(array $ids): array  — trả SP theo đúng thứ tự ids
     * @param callable $bestSellers fn(int $limit): array
     * @param string $q truy vấn hiện tại (chip "Bán chạy" giữ q)
     * @return array{degraded:bool,intent:?array,chips:array,sections:array}
     */
    public static function build(?array $response, callable $findVisibleByIds, callable $bestSellers, string $q = ''): array
    {
        $intent = is_array($response['intent'] ?? null) ? $response['intent'] : null;
        $chips = self::chips($intent, $q);
        if ($response === null) {
            return self::bestSellerResult(true, $intent, $chips, $bestSellers);
        }

        $allIds = [];
        foreach ($response['sections'] ?? [] as $section) {
            foreach ($section['items'] ?? [] as $item) {
                $allIds[(string)$item['product_id']] = true;
            }
        }
        $byId = [];
        if ($allIds) {
            foreach ($findVisibleByIds(array_keys($allIds)) as $p) {
                $key = (string)($p['ma_san_pham'] ?? $p['id'] ?? '');
                if ($key !== '') {
                    $byId[$key] = $p;
                }
            }
        }

        $sections = [];
        $used = [];
        foreach ($response['sections'] ?? [] as $section) {
            $items = [];
            foreach ($section['items'] ?? [] as $item) {
                $pid = (string)$item['product_id'];
                if (!isset($byId[$pid]) || isset($used[$pid])) {
                    continue; // không tìm thấy/ẩn hoặc đã hiện ở section trước
                }
                $used[$pid] = true;
                $product = $byId[$pid];
                $product['reason_code'] = (string)$item['reason_code'];
                $product['reason_text'] = SearchReasonFormatter::format((string)$item['reason_code'], (array)($item['reason_params'] ?? []));
                $items[] = $product;
            }
            if (count($items) < 1) {
                continue;
            }
            $sections[] = [
                'key' => (string)$section['key'],
                'title' => SearchReasonFormatter::sectionTitle((string)$section['key']),
                'items' => $items,
            ];
        }
        if (!$sections) {
            return self::bestSellerResult((bool)($response['degraded'] ?? false), $intent, $chips, $bestSellers);
        }
        return [
            'degraded' => (bool)($response['degraded'] ?? false),
            'intent' => $intent,
            'chips' => $chips,
            'sections' => $sections,
        ];
    }

    private static function bestSellerResult(bool $degraded, ?array $intent, array $chips, callable $bestSellers): array
    {
        $items = [];
        foreach ((array)$bestSellers(self::BEST_SELLER_LIMIT) as $p) {
            $p['reason_code'] = 'trending';
            $p['reason_text'] = SearchReasonFormatter::format('trending', []);
            $items[] = $p;
        }
        return [
            'degraded' => $degraded,
            'intent' => $intent,
            'chips' => $chips,
            'sections' => $items ? [[
                'key' => 'best_sellers',
                'title' => SearchReasonFormatter::sectionTitle('best_sellers'),
                'items' => $items,
            ]] : [],
        ];
    }

    /**
     * Chips (dữ liệu, chưa render). Mode A: intent=null -> chỉ "Bán chạy".
     * Mode B: intent.brands/categories là key đồ thị (torriden, sua_rua_mat) -> ưu tiên
     * brand_names/category_names (tên thật, cap2 khớp theo tên danh mục). "Bán chạy" giữ q hiện tại.
     * 'kind' (same_brand|category|best_sellers) dùng cho tracking chip_click (Bước 08).
     */
    public static function chips(?array $intent, string $q = ''): array
    {
        $chips = [];
        $brand = trim((string)($intent['brand_names'][0] ?? $intent['brands'][0] ?? ''));
        if ($brand !== '') {
            $chips[] = ['label' => 'Cùng thương hiệu', 'url' => 'index.php?' . http_build_query(['r' => 'tatca', 'q' => mb_substr($brand, 0, 80, 'UTF-8')]), 'kind' => 'same_brand'];
        }
        $category = trim((string)($intent['category_names'][0] ?? $intent['categories'][0] ?? ''));
        if ($category !== '') {
            $category = mb_substr($category, 0, 80, 'UTF-8');
            // cap2 nhận mã hoặc tên danh mục (SanPham::resolveCategoryLeafIds).
            $chips[] = ['label' => $category, 'url' => 'index.php?' . http_build_query(['r' => 'tatca', 'cap2' => $category]), 'kind' => 'category'];
        }
        $best = ['r' => 'tatca'];
        $q = trim($q);
        if ($q !== '') {
            $best['q'] = mb_substr($q, 0, 200, 'UTF-8');
        }
        $best['sort'] = self::BEST_SELLER_SORT;
        $chips[] = ['label' => 'Bán chạy', 'url' => 'index.php?' . http_build_query($best), 'kind' => 'best_sellers'];
        return $chips;
    }
}
