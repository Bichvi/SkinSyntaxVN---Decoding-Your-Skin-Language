<?php
// Lý do ngắn trên thẻ sản phẩm trang chủ, ưu tiên: nhãn vấn đề/loại da -> điểm đánh giá -> lượt bán.
// Không escape ở đây (view dùng h()).

final class HomeCardReason
{
    private static ?array $labels = null;

    /** Nhãn gọn cho thẻ (tên gốc có dấu "/" đọc lủng củng trong câu "Hỗ trợ ..."). */
    private const SHORT_CONCERNS = [
        'nam_tan_nhang' => 'nám da',
        'lo_chan_long' => 'lỗ chân lông to',
        'kho_da' => 'da khô',
        'kich_ung' => 'kích ứng',
        'lao_hoa' => 'lão hóa',
        'xin_mau' => 'da xỉn màu',
    ];

    /** Nhãn tiếng Việt từ tag_keys.json: ['concerns'=>[key=>name], 'skin_types'=>[...]]. */
    private static function labels(): array
    {
        if (self::$labels !== null) {
            return self::$labels;
        }
        self::$labels = ['concerns' => [], 'skin_types' => []];
        $file = __DIR__ . '/../data/tag_keys.json';
        $json = is_file($file) ? json_decode((string)file_get_contents($file), true) : null;
        foreach (['concerns', 'skin_types'] as $g) {
            foreach ((array)($json['groups'][$g] ?? []) as $row) {
                if (isset($row['key'], $row['name'])) {
                    self::$labels[$g][(string)$row['key']] = (string)$row['name'];
                }
            }
        }
        return self::$labels;
    }

    public static function for(array $p): string
    {
        $labels = self::labels();

        $concerns = [];
        foreach ((array)($p['van_de_da_keys'] ?? []) as $k) {
            $name = $labels['concerns'][(string)$k] ?? null;
            if ($name !== null) {
                $concerns[] = self::SHORT_CONCERNS[(string)$k] ?? mb_strtolower($name, 'UTF-8');
            }
            if (count($concerns) >= 2) {
                break;
            }
        }
        $skin = '';
        foreach ((array)($p['loai_da_keys'] ?? []) as $k) {
            $k = (string)$k;
            if ($k !== 'moi_loai_da' && isset($labels['skin_types'][$k])) {
                $skin = mb_strtolower($labels['skin_types'][$k], 'UTF-8');
                break;
            }
        }
        $parts = [];
        if ($concerns) {
            $parts[] = 'Hỗ trợ ' . implode(', ', $concerns);
        }
        if ($skin !== '') {
            $parts[] = 'Hợp ' . $skin;
        }
        if ($parts) {
            return implode(' · ', $parts);
        }

        $rating = is_numeric($p['diem_danh_gia'] ?? null) ? (float)$p['diem_danh_gia'] : 0.0;
        $reviews = is_numeric($p['so_luong_danh_gia'] ?? null) ? (int)$p['so_luong_danh_gia'] : 0;
        if ($rating >= 4.5 && $reviews >= 20) {
            return number_format($rating, 1, ',', '') . '★ từ ' . number_format($reviews, 0, ',', '.') . ' đánh giá';
        }
        $sold = is_numeric($p['so_luong_da_ban'] ?? null) ? (int)$p['so_luong_da_ban'] : 0;
        if ($sold >= 10) {
            return 'Đã bán ' . number_format($sold, 0, ',', '.');
        }
        return '';
    }
}
