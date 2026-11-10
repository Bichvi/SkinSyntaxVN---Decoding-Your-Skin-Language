<?php
// Câu ngắn + nhãn nổi bật cho thẻ "Vì sao phù hợp" (khối "Vì bạn đã xem").
// Hàm thuần (không DB, không mạng). Chuỗi trả về chưa escape: view phải qua h().
final class HomeWhyFitCopy
{
    private const MAX_TAGS = 2;
    private const VALUE_ICONS = [
        'cheaper' => 'bi-cash-coin', 'similar' => 'bi-cash-coin', 'per_unit' => 'bi-box-seam',
        'discount' => 'bi-tag-fill', 'rating' => 'bi-star-fill',
    ];

    /** @var array<int,array{match:string,short:string,benefit:string}>|null */
    private static ?array $glossary = null;

    /**
     * @return array{headline:string,tags:list<array{kind:string,icon:string,text:string}>}
     */
    public static function build(array $reason, array $detail, string $category = ''): array
    {
        $headline = self::headline($reason, $detail, trim($category));
        return ['headline' => $headline, 'tags' => self::tags($detail, $headline)];
    }

    private const CONCERN_SHORT = [
        'mun' => 'mụn', 'tham_mun' => 'thâm mụn', 'nam_tan_nhang' => 'nám', 'lo_chan_long' => 'lỗ chân lông',
        'dau_thua' => 'dầu thừa', 'kho_da' => 'da khô', 'kich_ung' => 'kích ứng', 'lao_hoa' => 'lão hóa',
        'xin_mau' => 'da xỉn màu',
    ];

    /**
     * Câu + nhãn cho thẻ ở khối "Hợp với da…" / "Hợp với vấn đề…", dựng từ dữ liệu SP thật.
     *
     * @return array{headline:string,tags:list<array{kind:string,icon:string,text:string}>}
     */
    public static function forProduct(array $product, array $reason, string $section): array
    {
        $headline = self::productHeadline($product, $reason, $section);
        $hl = self::lower($headline);

        // Không gắn nhãn % giảm / số sao / đã bán: thẻ đã hiện badge "-X%", giá gạch, sao và "Đã bán" ở hàng giá.
        $value = [];

        $concern = null;
        if ($section === 'skin') {
            $concern = self::verifiedConcern($product, $reason);
            if ($concern !== null && $hl !== '' && str_contains($hl, self::lower($concern['text']))) {
                $concern = null;
            }
        }
        if ($concern !== null) {
            $tags = array_slice($value, 0, 1);
            $tags[] = $concern;
        } else {
            $tags = array_slice($value, 0, self::MAX_TAGS);
        }
        return ['headline' => $headline, 'tags' => array_slice($tags, 0, self::MAX_TAGS)];
    }

    /** @return array{kind:string,icon:string,text:string}|null */
    private static function verifiedConcern(array $product, array $reason): ?array
    {
        $names = self::catalog('concerns');
        $wanted = [];
        foreach (self::strings($reason['concerns'] ?? []) as $c) {
            $wanted[self::lower($c)] = true;
        }
        foreach (self::strings($product['van_de_da_keys'] ?? []) as $key) {
            $name = $names[$key] ?? null;
            if ($name !== null && isset($wanted[self::lower($name)]) && isset(self::CONCERN_SHORT[$key])) {
                return ['kind' => 'extra', 'icon' => '', 'text' => 'Hỗ trợ ' . self::CONCERN_SHORT[$key]];
            }
        }
        return null;
    }

    /** @return array<string,string> key => tên hiển thị */
    private static function catalog(string $group): array
    {
        if (!class_exists('TagCatalog')) {
            require_once __DIR__ . '/TagCatalog.php';
        }
        return TagCatalog::groups()[$group] ?? [];
    }

    private static function productHeadline(array $product, array $reason, string $section): string
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
            $g = self::lookup($name);
            if ($g !== null && !isset($known[$g['short']])) {
                $known[$g['short']] = ['g' => $g, 'conc' => $conc];
            }
        };
        if ($section === 'concern' && ($reason['code'] ?? '') === 'concern_ingredient') {
            foreach (self::strings($reason['ingredients'] ?? []) as $ing) {
                $g = self::lookup($ing);
                $conc = '';
                foreach ($items as [$n, $c]) {
                    $a = self::lookup($n);
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
        $known = array_slice(array_values($known), 0, 2);
        $label = static fn(array $k): string => ($k['conc'] !== '' ? $k['conc'] . ' ' : '') . $k['g']['short'];
        if (count($known) === 2) {
            $a = $known[0]['g'];
            $benefit = $a['benefit'];
            $bl = self::lower($known[1]['g']['benefit']);
            foreach (explode(',', $a['benefit']) as $clause) {
                $clause = trim($clause);
                if ($clause !== '' && str_contains($bl, self::lower($clause))) {
                    $benefit = $clause;
                    break;
                }
            }
            return 'Có ' . $label($known[0]) . ' + ' . $label($known[1]) . ' – ' . $benefit;
        }
        if (count($known) === 1) {
            return 'Có ' . $label($known[0]) . ' – ' . $known[0]['g']['benefit'];
        }
        $names = self::catalog('benefits');
        $out = [];
        foreach (self::strings($product['cong_dung_keys'] ?? []) as $key) {
            if (isset($names[$key])) {
                $out[] = self::lower($names[$key]);
            }
            if (count($out) >= 2) {
                break;
            }
        }
        if ($out) {
            return 'Giúp ' . implode(', ', $out);
        }
        // Không có hoạt chất/công dụng (vd. nước tẩy trang chỉ có mô tả): nói loại SP + nhu cầu, để thẻ vẫn giữ được nhãn giá/đánh giá.
        $type = trim((string)($product['loai_san_pham'] ?? ''));
        if ($type === '') {
            return '';
        }
        $type = mb_strtoupper(mb_substr($type, 0, 1, 'UTF-8'), 'UTF-8') . self::lower(mb_substr($type, 1, null, 'UTF-8'));
        $skin = self::lower(trim((string)($reason['skin_type'] ?? '')));
        $concern = self::lower(trim((string)($reason['concern'] ?? '')));
        if ($section === 'skin' && $skin !== '') {
            return $type . ' hợp ' . $skin;
        }
        if ($section === 'concern' && $concern !== '') {
            return $type . ' hỗ trợ ' . $concern;
        }
        return '';
    }

    private static function headline(array $reason, array $detail, string $category): string
    {
        $shared = self::strings($detail['shared'] ?? []);
        if (!$shared) {
            $shared = self::strings($reason['ingredients'] ?? []);
        }
        $same = self::strings($detail['same_benefits'] ?? []);

        if ($shared) {
            $known = [];
            foreach ($shared as $ing) {
                $g = self::lookup($ing);
                if ($g !== null && !isset($known[$g['short']])) {
                    $known[$g['short']] = $g;
                }
            }
            $known = array_slice(array_values($known), 0, 2);
            if (count($known) === 2) {
                [$a, $b] = $known;
                $benefit = $a['benefit'];
                $bl = self::lower($b['benefit']);
                foreach (explode(',', $a['benefit']) as $clause) {
                    $clause = trim($clause);
                    if ($clause !== '' && str_contains($bl, self::lower($clause))) {
                        $benefit = $clause;
                        break;
                    }
                }
                return 'Cũng có ' . $a['short'] . ' + ' . $b['short'] . ' – ' . $benefit;
            }
            if (count($known) === 1) {
                return 'Cũng có ' . $known[0]['short'] . ' – ' . $known[0]['benefit'];
            }
            $text = 'Cũng có ' . $shared[0];
            if ($same) {
                $text .= ' – ' . self::lower(implode(', ', array_slice($same, 0, 2)));
            }
            return $text;
        }
        if ($same) {
            return 'Cũng ' . self::lower(implode(', ', array_slice($same, 0, 3)));
        }
        if ($category !== '') {
            return 'Cùng loại ' . $category;
        }
        return '';
    }

    /** @return list<array{kind:string,icon:string,text:string}> */
    private static function tags(array $detail, string $headline): array
    {
        $tags = [];
        $value = is_array($detail['value'] ?? null) ? $detail['value'] : null;
        if ($value !== null) {
            $kind = (string)($value['kind'] ?? '');
            $text = self::valueTag($kind, (string)($value['text'] ?? ''));
            if ($text !== null) {
                $tags[] = ['kind' => 'value', 'icon' => self::VALUE_ICONS[$kind], 'text' => $text];
            }
        }
        $hl = self::lower($headline);
        foreach (self::strings($detail['extra_benefits'] ?? []) as $b) {
            if (count($tags) >= self::MAX_TAGS) {
                break;
            }
            if ($hl !== '' && str_contains($hl, self::lower($b))) {
                continue;
            }
            $tags[] = ['kind' => 'extra', 'icon' => '', 'text' => '+ ' . $b];
        }
        return array_slice($tags, 0, self::MAX_TAGS);
    }

    private static function valueTag(string $kind, string $text): ?string
    {
        switch ($kind) {
            case 'cheaper':
                if (!preg_match('/(\d[\d.]*)\s*đ/u', $text, $m)) {
                    return 'Rẻ hơn';
                }
                $n = (int)str_replace('.', '', $m[1]);
                if ($n <= 0) {
                    return 'Rẻ hơn';
                }
                if ($n < 1000000) {
                    return 'Rẻ hơn ' . (int)round($n / 1000) . 'K';
                }
                $s = number_format($n / 1000000, 1, ',', '');
                if (str_ends_with($s, ',0')) {
                    $s = substr($s, 0, -2);
                }
                return 'Rẻ hơn ' . $s . ' triệu';
            case 'similar':
                return 'Giá tương đương';
            case 'per_unit':
                return preg_match('/rẻ hơn\s*(\d+)\s*%\s*mỗi\s*(ml|g)/ui', $text, $m)
                    ? 'Rẻ hơn ' . $m[1] . '%/' . strtolower($m[2]) : 'Rẻ hơn theo ml';
            case 'discount':
                // Badge "-X%" trên ảnh đã nói rồi, không lặp lại.
                return null;
            case 'rating':
                // Chỉ giữ ý so sánh; điểm sao đã có ở dòng đánh giá bên dưới.
                return 'Đánh giá cao hơn';
        }
        return null;
    }

    /** @return array{match:string,short:string,benefit:string}|null */
    public static function lookup(string $ingredient): ?array
    {
        if (self::$glossary === null) {
            $g = require __DIR__ . '/../config/ingredient_glossary.php';
            self::$glossary = is_array($g) ? $g : [];
        }
        $name = self::lower($ingredient);
        foreach (self::$glossary as $e) {
            if (@preg_match('/' . str_replace('/', '\/', $e['match']) . '/iu', $name) === 1) {
                return $e;
            }
        }
        return null;
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
