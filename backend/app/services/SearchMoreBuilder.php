<?php
// Khối "Gợi ý thêm" ở trang tìm kiếm có kết quả (tải lười qua ?r=search_more).
// Thuần (không DB, không mạng): ghép sản phẩm hợp da (đồ thị trang chủ) với fallback của Flask thành $searchFallback cho partial.
require_once __DIR__ . '/SearchReasonFormatter.php';

final class SearchMoreBuilder
{
    /**
     * @param array|null $skinProducts sản phẩm hợp da (đã có reason_text/reason_code) hoặc null
     * @param array|null $fallback     kết quả SearchFallbackBuilder::build hoặc null
     * @param string     $skinState    'guest' | 'no_profile' | 'has_profile'
     * @param array      $excludeIds   id đã hiện ở kết quả chính
     */
    public static function build(?array $skinProducts, ?array $fallback, string $skinState, array $excludeIds, int $perSection = 4): ?array
    {
        $perSection = max(1, $perSection);
        $used = [];
        foreach ($excludeIds as $id) {
            $used[(string)$id] = true;
        }
        $sections = [];

        if ($skinState === 'has_profile' && is_array($skinProducts)) {
            $items = self::take($skinProducts, $used, $perSection);
            if (count($items) >= 2) {
                $sections[] = ['key' => 'personalized', 'title' => SearchReasonFormatter::sectionTitle('personalized'), 'items' => $items];
            }
        }

        foreach ((array)($fallback['sections'] ?? []) as $section) {
            if (!is_array($section) || ($section['key'] ?? '') === 'semantic') {
                continue;
            }
            $items = self::take((array)($section['items'] ?? []), $used, $perSection);
            if ($items) {
                $sections[] = ['key' => (string)($section['key'] ?? ''), 'title' => (string)($section['title'] ?? ''), 'items' => $items];
            }
        }

        $cta = $skinState === 'guest' ? 'login' : ($skinState === 'no_profile' ? 'quiz' : null);
        if (!$sections && $cta === null) {
            return null;
        }
        $result = [
            'degraded' => (bool)($fallback['degraded'] ?? false),
            'intent' => null,
            'chips' => [],
            'sections' => $sections,
            'skin_cta' => $cta,
        ];
        if (is_array($fallback['experiment'] ?? null)) {
            $result['experiment'] = $fallback['experiment'];
        }
        return $result;
    }

    /** Lấy tối đa $cap sản phẩm chưa dùng; đánh dấu đã dùng để khử trùng giữa các khối. */
    private static function take(array $products, array &$used, int $cap): array
    {
        $out = [];
        foreach ($products as $p) {
            if (!is_array($p)) {
                continue;
            }
            $id = (string)($p['ma_san_pham'] ?? $p['id'] ?? '');
            if ($id === '' || isset($used[$id])) {
                continue;
            }
            $out[] = $p;
            $used[$id] = true;
            if (count($out) >= $cap) {
                break;
            }
        }
        return $out;
    }
}
