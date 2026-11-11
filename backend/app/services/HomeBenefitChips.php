<?php
// Chip công dụng cho thẻ trang chủ, lấy từ cong_dung_keys thật; bỏ công dụng đã nói trong dòng lý do.
require_once __DIR__ . '/TagCatalog.php';

final class HomeBenefitChips
{
    /** Từ đồng nghĩa theo key công dụng (ngoài chính tên công dụng). */
    private const ALIASES = [
        'cap_am' => ['cấp nước', 'dưỡng ẩm', 'cấp ẩm'],
        'lam_diu' => ['dịu'],
        'chong_tia_uv' => ['chống nắng', 'uv'],
        'lam_sach_sau' => ['làm sạch'],
        'chong_lao_hoa' => ['lão hóa'],
        'sang_da' => ['sáng da'],
        'kiem_dau' => ['kiềm dầu', 'dầu thừa'],
        'tay_da_chet' => ['tẩy da chết', 'bha', 'aha'],
        'khang_khuan' => ['kháng khuẩn', 'mụn viêm'],
    ];

    /** @return array{shown:list<string>,more:int} */
    public static function for(array $product, string $shownText, int $max = 2): array
    {
        $names = TagCatalog::groups()['benefits'] ?? [];
        $text = mb_strtolower($shownText, 'UTF-8');
        $raw = $product['cong_dung_keys'] ?? [];
        $keys = is_array($raw) ? $raw : ($raw instanceof \Traversable ? iterator_to_array($raw, false) : []);

        $seen = [];
        $left = [];
        foreach ($keys as $key) {
            if (!is_string($key) || isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $name = $names[$key] ?? null;
            if (!is_string($name) || $name === '') {
                continue;
            }
            $words = array_merge([mb_strtolower($name, 'UTF-8')], self::ALIASES[$key] ?? []);
            $said = false;
            foreach ($words as $w) {
                if ($text !== '' && str_contains($text, $w)) {
                    $said = true;
                    break;
                }
            }
            if (!$said) {
                $left[] = $name;
            }
        }
        if ($max <= 0) {
            return ['shown' => [], 'more' => count($left)];
        }
        return ['shown' => array_slice($left, 0, $max), 'more' => max(0, count($left) - $max)];
    }
}
