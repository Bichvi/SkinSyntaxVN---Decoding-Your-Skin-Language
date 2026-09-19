<?php
declare(strict_types=1);
require_once __DIR__ . '/../models/SanPham.php';
require_once __DIR__ . '/SupportKnowledge.php';

final class ChatProductCatalog
{
    private $db;
    private SanPham $products;
    public function __construct($db) { $this->db = $db; $this->products = new SanPham($db); }

    private static function regex(string $term): string
    {
        $variants = ['toner' => '(toner|nuoc can bang|nuoc hoa hong)', 'cerave' => 'cera ?ve'];
        $pattern = $variants[$term] ?? preg_quote($term, '/');
        return strtr($pattern, ['a'=>'[aàáạảãâầấậẩẫăằắặẳẵ]', 'e'=>'[eèéẹẻẽêềếệểễ]', 'i'=>'[iìíịỉĩ]', 'o'=>'[oòóọỏõôồốộổỗơờớợởỡ]', 'u'=>'[uùúụủũưừứựửữ]', 'y'=>'[yỳýỵỷỹ]', 'd'=>'[dđ]']);
    }

    public static function rank(string $query, iterable $records): array
    {
        $terms = array_values(array_unique(preg_split('/\s+/', SupportKnowledge::fold($query), -1, PREG_SPLIT_NO_EMPTY)));
        if (!$terms || count($terms) > 16) return [];
        $ranked = [];
        foreach ($records as $record) {
            $record = (array)$record;
            $name = SupportKnowledge::fold((string)($record['ten_san_pham'] ?? ''));
            $name = str_replace(['cera ve', 'nuoc can bang', 'nuoc hoa hong'], ['cerave', 'toner', 'toner'], $name);
            $words = preg_split('/\s+/', $name);
            $score = 0;
            foreach ($terms as $term) {
                if (preg_match('/(?<![a-z0-9])' . preg_quote($term, '/') . '(?![a-z0-9])/', $name)) { $score += 10; continue; }
                $fuzzy = strlen($term) >= 5 && array_filter($words, static fn($word) => levenshtein($term, $word) === 1);
                if ($fuzzy) { $score += 6; continue; }
                // Missing requested terms are not replaced with popular unrelated products.
                $score = 0; break;
            }
            if ($score > 0) $ranked[] = ['score' => $score, 'record' => $record];
        }
        usort($ranked, static fn($a, $b) => $b['score'] <=> $a['score'] ?: strcmp((string)$a['record']['ma_san_pham'], (string)$b['record']['ma_san_pham']));
        return array_column($ranked, 'record');
    }

    public function search(string $query): array
    {
        $terms = array_values(array_unique(preg_split('/\s+/', $query, -1, PREG_SPLIT_NO_EMPTY)));
        if (!$terms || count($terms) > 16) return [];
        $candidates = [];
        foreach ($terms as $term) {
            if (strlen($term) < 3) continue;
            $cursor = $this->db->san_pham->find(
                ['ten_san_pham' => new MongoDB\BSON\Regex(self::regex($term), 'i')],
                ['projection' => ['ma_san_pham'=>1, 'ten_san_pham'=>1], 'sort'=>['ma_san_pham'=>1], 'limit'=>80, 'maxTimeMS'=>1500]
            );
            foreach ($cursor as $record) $candidates[(string)$record['ma_san_pham']] = (array)$record;
        }
        $choices = [];
        foreach (self::rank($query, $candidates) as $record) {
            $product = $this->find((string)$record['ma_san_pham']);
            if ($product && $product['available'] && $product['price'] > 0) $choices[] = $product;
            if (count($choices) === 5) break;
        }
        return $choices;
    }

    public function find(string $id): ?array
    {
        $product = $this->products->findById($id, true);
        if (!$product) return null;
        $product = (array)$product;
        $image = (string)($product['link_hinh_anh'] ?? $product['hinh_anh'] ?? '');
        return [
            'id'=>(string)$product['ma_san_pham'], 'name'=>(string)$product['ten_san_pham'],
            'price'=>(int)($product['gia_ban'] ?? 0), 'brand'=>(string)($product['thuong_hieu'] ?? ''),
            'image_url'=>function_exists('resolve_image_url') ? resolve_image_url($image) : $image,
            'detail_url'=>BASE_URL . '/index.php?r=chitiet&id=' . rawurlencode((string)$product['ma_san_pham']),
            'stock'=>$this->products->getProductStock($product), 'available'=>$this->products->isProductAvailable($product),
        ];
    }
}
