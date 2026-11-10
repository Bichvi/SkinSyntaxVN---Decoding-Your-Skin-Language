<?php
// Ghép câu lý do tiếng Việt từ `reason` của Flask (spec §3.5). Hàm thuần; không in tên thương hiệu.
// Chuỗi trả về chưa escape: view phải qua h() khi render.
final class HomeReasonText
{
    private const FALLBACK = 'Gợi ý cho bạn';

    /**
     * @param array{omit_seed_id?:string,category?:string,full_seed?:bool} $opts full_seed: không cắt tên SP gốc (dùng cho popover);
     *                                                           omit_seed_id: bỏ "với <SP>" khi seed trùng SP ở tiêu đề khối;
     *                                                           category: tên danh mục cuối của sản phẩm (cho "Cùng loại ...")
     */
    public static function fromReason(array $reason, array $opts = []): string
    {
        $omit = isset($opts['omit_seed_id']) && (string)$opts['omit_seed_id'] !== ''
            && isset($reason['seed_id']) && (is_string($reason['seed_id']) || is_int($reason['seed_id']))
            && (string)$reason['seed_id'] === (string)$opts['omit_seed_id'];
        $code = is_string($reason['code'] ?? null) ? $reason['code'] : '';
        switch ($code) {
            case 'seen_shared_ingredient':
                $ing = self::names($reason['ingredients'] ?? [], 2);
                $seed = self::seed($reason, !empty($opts['full_seed']));
                if ($omit && $ing) {
                    return 'Cùng chứa ' . implode(', ', $ing);
                }
                if (!$ing || $seed === '') {
                    return self::FALLBACK;
                }
                return 'Cùng chứa ' . implode(', ', $ing) . ' với ' . $seed;
            case 'seen_same_category':
                if ($omit) {
                    $cat = self::str($opts['category'] ?? '');
                    return $cat !== '' ? 'Cùng loại ' . $cat : 'Cùng loại sản phẩm';
                }
                $seed = self::seed($reason, !empty($opts['full_seed']));
                return $seed === '' ? self::FALLBACK : 'Cùng loại với ' . $seed;
            case 'skin_fit':
                $skin = self::str($reason['skin_type'] ?? '');
                if ($skin === '') {
                    return self::FALLBACK;
                }
                $concerns = self::names($reason['concerns'] ?? [], 2);
                return 'Phù hợp ' . $skin . ($concerns ? ' · Hỗ trợ ' . implode(', ', $concerns) : '');
            case 'concern_ingredient':
                $ing = self::names($reason['ingredients'] ?? [], 1);
                $concern = self::str($reason['concern'] ?? '');
                if (!$ing || $concern === '') {
                    return self::FALLBACK;
                }
                return 'Chứa ' . $ing[0] . ', hỗ trợ ' . mb_strtolower($concern, 'UTF-8');
            case 'concern_target':
                $concern = self::str($reason['concern'] ?? '');
                return $concern === '' ? self::FALLBACK : 'Dành cho ' . mb_strtolower($concern, 'UTF-8');
        }
        return self::FALLBACK;
    }

    private static function str($v): string
    {
        return is_string($v) ? trim($v) : '';
    }

    private static function seed(array $reason, bool $full = false): string
    {
        $name = self::str($reason['seed_name'] ?? ($reason['seed'] ?? ''));
        if ($name === '' || $full) {
            return $name;
        }
        return mb_strimwidth($name, 0, 30, '…', 'UTF-8');
    }

    /** @return string[] */
    private static function names($list, int $max): array
    {
        if (!is_array($list)) {
            return [];
        }
        $out = [];
        foreach ($list as $v) {
            $s = self::str($v);
            if ($s !== '') {
                $out[] = $s;
            }
            if (count($out) >= $max) {
                break;
            }
        }
        return $out;
    }
}
