<?php
// Chi tiết cho popover "Vì sao phù hợp" ở khối "Hợp với da…" / "Hợp với vấn đề…", dựng từ dữ liệu SP thật.
// Hàm thuần (không DB, không mạng). Chuỗi trả về chưa escape: view phải qua h().
require_once __DIR__ . '/TagCatalog.php';
require_once __DIR__ . '/HomeWhyFitCopy.php';

final class HomeFitDetail
{
    private const MAX_ACTIVES = 4;
    private const MAX_SKIN_TYPES = 3;
    private const MAX_BENEFITS = 3;
    private const MAX_CONCERNS = 2;

    /**
     * @return array{actives:list<string>,skin_types:list<string>,benefits:list<string>,concerns:list<string>}|array{}
     */
    public static function build(array $product, array $reason, string $section): array
    {
        $groups = TagCatalog::groups();
        $out = [
            'actives' => self::actives($product, $reason, $section),
            'skin_types' => self::skinTypes($product, $reason, $section, $groups['skin_types'] ?? []),
            'benefits' => self::benefits($product, $groups['benefits'] ?? []),
            'concerns' => self::concerns($product, $reason, $section, $groups['concerns'] ?? []),
        ];
        foreach ($out as $list) {
            if ($list) {
                return $out;
            }
        }
        return [];
    }

    /** @return list<string> */
    private static function actives(array $product, array $reason, string $section): array
    {
        $items = [];
        $raw = $product['thanh_phan_sach'] ?? '';
        foreach (is_string($raw) ? explode(',', $raw) : [] as $part) {
            $part = trim($part);
            $conc = '';
            if (preg_match('/^(\d+(?:[.,]\d+)?)\s*%\s*(.*)$/u', $part, $m)) {
                $conc = $m[1] . '%';
                $part = trim($m[2]);
            }
            if ($part !== '') {
                $items[] = [$part, $conc];
            }
        }
        $known = [];
        $add = static function (string $name, string $conc) use (&$known): void {
            $g = HomeWhyFitCopy::lookup($name);
            if ($g !== null && !isset($known[$g['short']])) {
                $known[$g['short']] = ($conc !== '' ? $conc . ' ' : '') . $g['short'];
            }
        };
        if ($section === 'concern' && ($reason['code'] ?? '') === 'concern_ingredient') {
            foreach (self::strings($reason['ingredients'] ?? []) as $ing) {
                $g = HomeWhyFitCopy::lookup($ing);
                $conc = '';
                foreach ($items as [$n, $c]) {
                    $a = HomeWhyFitCopy::lookup($n);
                    if ($g !== null && $a !== null && $a['short'] === $g['short']) {
                        $conc = $c;
                        break;
                    }
                }
                $add($ing, $conc);
            }
        }
        foreach ($items as [$n, $c]) {
            $add($n, $c);
        }
        $labels = array_slice(array_values($known), 0, self::MAX_ACTIVES);
        return array_map(static fn(string $s): string => mb_strtoupper(mb_substr($s, 0, 1, 'UTF-8'), 'UTF-8') . mb_substr($s, 1, null, 'UTF-8'), $labels);
    }

    /** @return list<string> */
    private static function skinTypes(array $product, array $reason, string $section, array $names): array
    {
        $keys = array_values(array_unique(self::strings($product['loai_da_keys'] ?? [])));
        $list = [];
        foreach ($keys as $k) {
            if ($k !== 'moi_loai_da' && isset($names[$k])) {
                $list[] = $names[$k];
            }
        }
        if (!$list && in_array('moi_loai_da', $keys, true) && isset($names['moi_loai_da'])) {
            $list[] = $names['moi_loai_da'];
        }
        if ($section === 'skin') {
            $want = self::lower(trim((string)($reason['skin_type'] ?? '')));
            if ($want !== '') {
                usort($list, static fn(string $a, string $b): int => (self::lower($b) === $want) <=> (self::lower($a) === $want));
            }
        }
        return array_slice($list, 0, self::MAX_SKIN_TYPES);
    }

    /** @return list<string> */
    private static function benefits(array $product, array $names): array
    {
        $out = [];
        foreach (self::strings($product['cong_dung_keys'] ?? []) as $k) {
            if (isset($names[$k]) && !in_array($names[$k], $out, true)) {
                $out[] = $names[$k];
            }
        }
        return array_slice($out, 0, self::MAX_BENEFITS);
    }

    /** @return list<string> */
    private static function concerns(array $product, array $reason, string $section, array $names): array
    {
        $wanted = [];
        $src = $section === 'skin' ? ($reason['concerns'] ?? []) : ($section === 'concern' ? [$reason['concern'] ?? null] : []);
        foreach (self::strings($src) as $c) {
            $wanted[self::lower($c)] = true;
        }
        $out = [];
        foreach (self::strings($product['van_de_da_keys'] ?? []) as $k) {
            $name = $names[$k] ?? null;
            if ($name !== null && isset($wanted[self::lower($name)]) && !in_array($name, $out, true)) {
                $out[] = $name;
            }
        }
        return array_slice($out, 0, self::MAX_CONCERNS);
    }

    /** @return string[] */
    private static function strings($v): array
    {
        $out = [];
        foreach (is_array($v) ? $v : [] as $x) {
            if (is_string($x) && trim($x) !== '') {
                $out[] = trim($x);
            }
        }
        return $out;
    }

    private static function lower(string $s): string
    {
        return mb_strtolower($s, 'UTF-8');
    }
}
