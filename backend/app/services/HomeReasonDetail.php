<?php
// Chi tiết "vì sao gợi ý" cho thẻ khối "Vì bạn đã xem": thành phần chung, công dụng chung/thêm, 1 dòng giá trị.
// Hàm thuần (không DB). Chuỗi trả về chưa escape: view phải qua h().
require_once __DIR__ . '/TagCatalog.php';

final class HomeReasonDetail
{
    private const MAX_SAME = 3;
    private const MAX_EXTRA = 2;
    private const CHEAPER_MIN_DIFF = 1000;   // đ
    private const SIMILAR_RATIO = 0.10;      // đắt hơn dưới 10% => tương đương
    private const PER_UNIT_MIN_SAVING = 0.10;
    private const DISCOUNT_MIN = 30;         // %
    private const RATING_MIN_GAP = 0.3;
    private const RATING_MIN_COUNT = 10;

    /**
     * @return array{shared:string[],same_benefits:string[],extra_benefits:string[],value:?array{kind:string,text:string}}|array{}
     */
    public static function build(array $reason, array $product, ?array $seed): array
    {
        $shared = [];
        $ingredients = $reason['ingredients'] ?? [];
        foreach (is_array($ingredients) ? $ingredients : [] as $v) {
            if (is_string($v) && trim($v) !== '') {
                $shared[] = trim($v);
            }
        }
        $same = [];
        $extra = [];
        $value = null;
        if ($seed !== null) {
            [$same, $extra] = self::benefits($product, $seed);
            $value = self::value($product, $seed);
        }
        if (!$shared && !$same && !$extra && $value === null) {
            return [];
        }
        return ['shared' => $shared, 'same_benefits' => $same, 'extra_benefits' => $extra, 'value' => $value];
    }

    /** @return array{0:string[],1:string[]} */
    private static function benefits(array $product, array $seed): array
    {
        $names = TagCatalog::groups()['benefits'] ?? [];
        $keys = static function (array $p) use ($names): array {
            $out = [];
            foreach ((array)($p['cong_dung_keys'] ?? []) as $k) {
                if (is_string($k) && isset($names[$k]) && !in_array($k, $out, true)) {
                    $out[] = $k;
                }
            }
            return $out;
        };
        $pk = $keys($product);
        $sk = $keys($seed);
        $same = [];
        $extra = [];
        foreach ($pk as $k) {
            if (in_array($k, $sk, true)) {
                if (count($same) < self::MAX_SAME) {
                    $same[] = $names[$k];
                }
            } elseif (count($extra) < self::MAX_EXTRA) {
                $extra[] = $names[$k];
            }
        }
        return [$same, $extra];
    }

    private static function value(array $p, array $s): ?array
    {
        $pp = self::num($p['gia_ban'] ?? null);
        $sp = self::num($s['gia_ban'] ?? null);
        if ($pp > 0 && $sp > 0) {
            $diff = $sp - $pp;
            if ($diff >= self::CHEAPER_MIN_DIFF) {
                return ['kind' => 'cheaper', 'text' => 'Rẻ hơn ' . number_format((int)round($diff), 0, ',', '.') . 'đ so với SP bạn đã xem'];
            }
            if ($pp - $sp < $sp * self::SIMILAR_RATIO) {
                return ['kind' => 'similar', 'text' => 'Giá tương đương SP bạn đã xem'];
            }
            $unit = self::perUnit($p, $s, $pp, $sp);
            if ($unit !== null) {
                return $unit;
            }
        }
        $disc = $p['phan_tram_giam'] ?? null;
        if ((is_int($disc) || (is_string($disc) && ctype_digit($disc))) && (int)$disc >= self::DISCOUNT_MIN) {
            return ['kind' => 'discount', 'text' => 'Đang giảm ' . (int)$disc . '%'];
        }
        $pr = self::num($p['diem_danh_gia'] ?? null);
        $sr = self::num($s['diem_danh_gia'] ?? null);
        if ($sr > 0 && $pr >= $sr + self::RATING_MIN_GAP - 1e-9 && (int)self::num($p['so_luong_danh_gia'] ?? null) >= self::RATING_MIN_COUNT) {
            return ['kind' => 'rating', 'text' => 'Đánh giá cao hơn (' . number_format($pr, 1, ',', '') . ' so với ' . number_format($sr, 1, ',', '') . ')'];
        }
        return null;
    }

    private static function perUnit(array $p, array $s, float $pp, float $sp): ?array
    {
        $pv = self::volume($p['dung_tich'] ?? null);
        $sv = self::volume($s['dung_tich'] ?? null);
        if ($pv === null || $sv === null || $pv['unit'] !== $sv['unit'] || !self::sameCategory($p, $s)) {
            return null;
        }
        $pu = $pp / $pv['value'];
        $su = $sp / $sv['value'];
        if ($su <= 0 || $pu > $su * (1 - self::PER_UNIT_MIN_SAVING) + 1e-9) {
            return null;
        }
        $pct = (int)floor((1 - $pu / $su) * 100 + 1e-9);
        return ['kind' => 'per_unit', 'text' => $pv['label'] . ', tính ra rẻ hơn ' . $pct . '% mỗi ' . $pv['unit']];
    }

    /** @return array{value:float,unit:string,label:string}|null */
    private static function volume($raw): ?array
    {
        if (!is_string($raw) || !preg_match('/^\s*(\d+(?:[.,]\d+)?)\s*(ml|g)\s*$/i', $raw, $m)) {
            return null;
        }
        $num = str_replace(',', '.', $m[1]);
        $value = (float)$num;
        if ($value <= 0) {
            return null;
        }
        if (str_contains($num, '.')) {
            $num = rtrim(rtrim($num, '0'), '.');
        }
        $unit = strtolower($m[2]);
        return ['value' => $value, 'unit' => $unit, 'label' => $num . $unit];
    }

    private static function sameCategory(array $p, array $s): bool
    {
        $a = $p['ma_danh_muc'] ?? null;
        $b = $s['ma_danh_muc'] ?? null;
        if ($a !== null && $a !== '' && $b !== null && $b !== '') {
            return (string)$a === (string)$b;
        }
        $la = self::lastSegment($p['danh_muc_day_du'] ?? null);
        $lb = self::lastSegment($s['danh_muc_day_du'] ?? null);
        return $la !== '' && $la === $lb;
    }

    private static function lastSegment($path): string
    {
        if (!is_string($path) || trim($path) === '') {
            return '';
        }
        $parts = explode('->', $path);
        return trim((string)end($parts));
    }

    private static function num($v): float
    {
        return (is_int($v) || is_float($v) || (is_string($v) && is_numeric($v))) ? (float)$v : 0.0;
    }
}
