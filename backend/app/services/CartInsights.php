<?php
// U09: ghép phản hồi Flask `cart/analyze` (đã validate bởi SearchRecommendClient::validateCartResponse) với dữ liệu sản phẩm
// Mongo thành "view model" cho partials/cart_insights.php. Hàm thuần: không DB, không mạng, không escape HTML (view escape).
require_once __DIR__ . '/SearchReasonFormatter.php';

final class CartInsights
{
    public const SEVERITY_LABELS = [
        'high' => 'Nên tránh dùng cùng nhau',
        'medium' => 'Cần lưu ý khi dùng chung',
        'low' => 'Lưu ý nhẹ',
    ];
    public const TIMING_TEXT = [
        'same_routine' => 'Không nên dùng chung trong cùng một routine; hãy dùng xen kẽ ngày.',
        'same_session' => 'Tránh dùng cùng một buổi; có thể tách buổi sáng và buổi tối.',
        'ok_alternate_days' => 'Có thể dùng cách ngày.',
    ];

    /**
     * @param array      $analysis      kết quả SearchRecommendClient::cartAnalyze
     * @param array      $products      [product_id => document sản phẩm hiển thị được]
     * @param string[]   $cartIds       id trong giỏ (gợi ý không được trùng)
     * @return array{conflicts:array,steps:array,suggestions:array,degraded:bool,visible:bool}
     */
    public static function build(array $analysis, array $products, array $cartIds = []): array
    {
        $inCart = array_flip(array_map('strval', $cartIds));
        $conflicts = [];
        foreach ((array)($analysis['conflicts'] ?? []) as $c) {
            $a = $products[(string)$c['a']] ?? null;
            $b = $products[(string)$c['b']] ?? null;
            if (!is_array($a) || !is_array($b)) {
                continue;
            }
            $conflicts[] = [
                'severity' => (string)$c['severity'],
                'severity_label' => self::SEVERITY_LABELS[$c['severity']] ?? '',
                'timing_text' => self::TIMING_TEXT[$c['timing']] ?? '',
                'ingredients' => array_values($c['ingredients']),
                'advice' => (string)($c['advice_vi'] ?? ''),
                'rule_id' => (string)($c['rule_id'] ?? ''),
                'a' => ['id' => (string)$c['a'], 'name' => (string)($a['ten_san_pham'] ?? '')],
                'b' => ['id' => (string)$c['b'], 'name' => (string)($b['ten_san_pham'] ?? '')],
            ];
        }

        $routine = (array)($analysis['routine'] ?? []);
        $labels = (array)($routine['labels'] ?? []);
        $has = array_flip((array)($routine['has'] ?? []));
        $missing = array_flip((array)($routine['missing'] ?? []));
        $suggestions = [];
        foreach ((array)($analysis['suggestions'] ?? []) as $s) {
            $items = [];
            foreach ((array)$s['items'] as $item) {
                $pid = (string)$item['product_id'];
                $p = $products[$pid] ?? null;
                if (!is_array($p) || isset($inCart[$pid])) {
                    continue;
                }
                $p['reason_code'] = (string)$item['reason_code'];
                $p['reason_text'] = SearchReasonFormatter::format((string)$item['reason_code'], (array)$item['reason_params']);
                $items[] = $p;
            }
            if ($items) {
                $suggestions[] = ['step' => (string)$s['step'], 'label' => (string)($labels[$s['step']] ?? $s['step']), 'items' => $items];
            }
        }

        $steps = [];
        if ($suggestions) { // chip các bước chỉ có nghĩa khi có gợi ý đi kèm
            $order = (array)($routine['order'] ?? []);
            $keys = $order ?: array_keys($labels);
            foreach ($keys as $key) {
                $label = $labels[$key] ?? null;
                if ($label === null) {
                    continue;
                }
                if (isset($has[$key])) {
                    $steps[] = ['key' => (string)$key, 'label' => (string)$label, 'state' => 'has'];
                } elseif (isset($missing[$key])) {
                    $steps[] = ['key' => (string)$key, 'label' => (string)$label, 'state' => 'missing'];
                }
            }
        }
        return [
            'conflicts' => $conflicts,
            'steps' => $steps,
            'suggestions' => $suggestions,
            'degraded' => !empty($analysis['degraded']),
            'visible' => $conflicts !== [] || $suggestions !== [],
        ];
    }

    /** id sản phẩm cần tải từ Mongo để dựng view model (xung đột + gợi ý). */
    public static function productIds(array $analysis): array
    {
        $ids = [];
        foreach ((array)($analysis['conflicts'] ?? []) as $c) {
            $ids[(string)$c['a']] = true;
            $ids[(string)$c['b']] = true;
        }
        foreach ((array)($analysis['suggestions'] ?? []) as $s) {
            foreach ((array)$s['items'] as $item) {
                $ids[(string)$item['product_id']] = true;
            }
        }
        return array_map('strval', array_keys($ids));
    }
}
