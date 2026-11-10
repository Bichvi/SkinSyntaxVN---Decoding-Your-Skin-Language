<?php
/**
 * Xáo thứ tự danh sách gợi ý có trọng số theo thứ hạng (Efraimidis–Spirakis).
 * SP liên quan nhất vẫn hay đứng đầu, nhưng mỗi lần tải trang thứ tự khác nhau.
 */
final class HomeRotation
{
    /**
     * @param array         $items Danh sách đã sắp theo độ liên quan giảm dần.
     * @param float         $decay Hệ số giảm trọng số theo hạng (0.05..1); 1 = xáo đều.
     * @param callable|null $rand  Trả về float trong (0,1); mặc định mt_rand.
     */
    public static function weightedShuffle(array $items, float $decay = 0.8, ?callable $rand = null): array
    {
        $items = array_values($items);
        if (count($items) < 2) {
            return $items;
        }
        $decay = max(0.05, min(1.0, $decay));
        if ($rand === null) {
            $rand = static function (): float {
                return mt_rand(1, mt_getrandmax() - 1) / mt_getrandmax();
            };
        }
        $keyed = [];
        foreach ($items as $i => $item) {
            $w = $decay ** $i;
            $u = (float)$rand();
            if ($u <= 0.0 || $u >= 1.0) {
                $u = 0.5;
            }
            $keyed[] = [$u ** (1 / $w), $i, $item];
        }
        usort($keyed, static function (array $a, array $b): int {
            return $b[0] <=> $a[0] ?: $a[1] <=> $b[1];
        });
        return array_values(array_map(static fn(array $k) => $k[2], $keyed));
    }
}
