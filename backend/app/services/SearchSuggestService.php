<?php
// Autocomplete nhiều nhóm cho ô tìm kiếm (U04): sản phẩm, thương hiệu, danh mục, từ khóa phổ biến.
//
// Response (đưa vào api_smart_search, type=live_search):
//   results            : sản phẩm (<=5) - định dạng cũ, mỗi phần tử có thêm name_html (đã escape + <mark>)
//   groups.brands      : [{key,name,name_html,q}]   (<=3)
//   groups.categories  : [{key,name,name_html,q}]   (<=3)
//   groups.queries     : [{q,q_html,score}]         (<=5, từ Redis; Redis lỗi => mảng rỗng)
//   cached             : true nếu lấy từ cache Redis
// Cache Redis theo tiền tố 60s: search:suggest:v1:g<gen>:<sha1> (gen = search:cachegen:v1, U03). Redis chết => bỏ cache.

require_once __DIR__ . '/RedisLite.php';
require_once __DIR__ . '/SearchSkinProfile.php';
require_once __DIR__ . '/SearchDictionary.php';
require_once __DIR__ . '/SearchPopularity.php';
require_once __DIR__ . '/SearchSuggestIndex.php';
require_once __DIR__ . '/AfterResponse.php';

final class SearchSuggestService
{
    public const CACHE_PREFIX = 'search:suggest:v1:';
    public const CACHE_GEN_KEY = 'search:cachegen:v1';
    public const CACHE_TTL = 60;
    public const MIN_CHARS = 2;
    public const MAX_Q_CHARS = 100;
    public const LIMIT_PRODUCTS = 5;
    public const LIMIT_BRANDS = 3;
    public const LIMIT_CATEGORIES = 3;
    public const LIMIT_QUERIES = 5;

    /** @var object model SanPham (searchSuggestions) */
    private $products;
    private ?RedisLite $redis;
    private string $source = 'mongo';

    public function __construct($products, ?RedisLite $redis = null)
    {
        $this->products = $products;
        $this->redis = $redis ?? RedisLite::shared();
    }

    public static function emptyPayload(): array
    {
        return ['results' => [], 'groups' => ['brands' => [], 'categories' => [], 'queries' => []], 'cached' => false];
    }

    /** Khóa cache: chỉ phụ thuộc chuỗi đã chuẩn hóa (không dấu, gộp khoảng trắng) và thế hệ cache. */
    public static function cacheKey(string $q, int $gen): string
    {
        return self::CACHE_PREFIX . 'g' . $gen . ':' . sha1(SearchSkinProfile::fold($q));
    }

    public function suggest(string $q): array
    {
        $q = mb_substr(trim((string)preg_replace('/\s+/u', ' ', $q)), 0, self::MAX_Q_CHARS, 'UTF-8');
        if (mb_strlen($q, 'UTF-8') < self::MIN_CHARS) {
            return self::emptyPayload();
        }

        $cacheKey = null;
        if ($this->redis !== null) {
            $gen = $this->redis->get(self::CACHE_GEN_KEY);
            $cacheKey = self::cacheKey($q, ($gen !== null && ctype_digit($gen)) ? (int)$gen : 0);
            $raw = $this->redis->get($cacheKey);
            $hit = $raw !== null ? json_decode($raw, true) : null;
            if (is_array($hit) && isset($hit['results'], $hit['groups'])) {
                $hit['cached'] = true;
                $this->source = 'cache';
                return $hit;
            }
        }

        $payload = $this->build($q);
        if ($cacheKey !== null && $this->redis !== null) {
            $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
            if ($json !== false) {
                $this->redis->setex($cacheKey, self::CACHE_TTL, $json);
            }
        }
        return $payload;
    }

    private function build(string $q): array
    {
        $items = [];
        foreach ($this->findProducts($q) as $p) {
            $p['name_html'] = self::highlight((string)($p['ten_san_pham'] ?? ''), $q);
            $items[] = $p;
        }

        $brands = [];
        foreach (SearchDictionary::matchBrands($q, self::LIMIT_BRANDS) as $b) {
            $brands[] = ['key' => $b['key'], 'name' => $b['name'], 'name_html' => self::highlight($b['name'], $q), 'q' => $b['name']];
        }
        $categories = [];
        foreach (SearchDictionary::matchCategories($q, self::LIMIT_CATEGORIES) as $c) {
            $categories[] = ['key' => $c['key'], 'name' => $c['name'], 'name_html' => self::highlight($c['name'], $q), 'q' => $c['name']];
        }
        $queries = [];
        foreach (SearchPopularity::lookup($q, self::LIMIT_QUERIES, $this->redis) as $row) {
            $queries[] = ['q' => $row['q'], 'q_html' => self::highlight($row['q'], $q), 'score' => $row['score']];
        }

        return [
            'results' => $items,
            'groups' => ['brands' => $brands, 'categories' => $categories, 'queries' => $queries],
            'cached' => false,
        ];
    }

    /**
     * Sản phẩm khớp: ưu tiên chỉ mục Redis (không chờ Atlas); chưa có chỉ mục hoặc Redis lỗi thì dùng truy vấn Mongo.
     * @return array<int,array>
     */
    private function findProducts(string $q): array
    {
        if ($this->redis !== null && method_exists($this->products, 'buildSuggestIndex')) {
            $index = SearchSuggestIndex::items($this->redis, fn(): array => $this->products->buildSuggestIndex());
            if ($index !== null) {
                $this->source = 'index';
                return SearchSuggestIndex::match($index, $q, self::LIMIT_PRODUCTS);
            }
        }
        $this->source = 'mongo';
        return $this->products->searchSuggestions($q, self::LIMIT_PRODUCTS, true, false);
    }

    /** Nguồn sản phẩm của lần suggest() gần nhất: cache | index | mongo (để đo/log). */
    public function lastSource(): string
    {
        return $this->source;
    }

    /**
     * HTML an toàn: escape toàn bộ $text rồi bọc <mark> quanh phần đầu-từ khớp các token của $query
     * (không phân biệt hoa thường và dấu). Văn bản gốc không bao giờ được chèn thô vào HTML.
     */
    public static function highlight(string $text, string $query): string
    {
        $esc = static fn(string $s): string => htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        if ($text === '') {
            return '';
        }
        if (class_exists('Normalizer')) {
            $norm = \Normalizer::normalize($text, \Normalizer::FORM_C);
            $text = is_string($norm) ? $norm : $text;
        }
        $tokens = array_values(array_unique(array_filter(
            explode(' ', SearchSkinProfile::fold($query)),
            static fn(string $t): bool => $t !== ''
        )));
        if ($tokens === []) {
            return $esc($text);
        }

        $orig = mb_str_split($text, 1, 'UTF-8');
        $fold = [];   // ký tự đã fold
        $owner = [];  // chỉ số ký tự gốc tương ứng
        foreach ($orig as $i => $ch) {
            $f = trim($ch) === '' ? ' ' : SearchSkinProfile::fold($ch);
            foreach (mb_str_split($f === '' ? ' ' : $f, 1, 'UTF-8') as $fc) {
                $fold[] = $fc;
                $owner[] = $i;
            }
        }

        $marked = array_fill(0, count($orig), false);
        $n = count($fold);
        foreach ($tokens as $tok) {
            $tc = mb_str_split($tok, 1, 'UTF-8');
            $tl = count($tc);
            for ($s = 0; $s + $tl <= $n; $s++) {
                if ($s > 0 && ctype_alnum($fold[$s - 1])) {
                    continue; // chỉ khớp ở đầu từ
                }
                $ok = true;
                for ($k = 0; $k < $tl; $k++) {
                    if ($fold[$s + $k] !== $tc[$k]) {
                        $ok = false;
                        break;
                    }
                }
                if ($ok) {
                    for ($k = 0; $k < $tl; $k++) {
                        $marked[$owner[$s + $k]] = true;
                    }
                }
            }
        }

        $out = '';
        $run = '';
        $runMarked = false;
        $flush = static function () use (&$out, &$run, &$runMarked, $esc): void {
            if ($run === '') {
                return;
            }
            $out .= $runMarked ? '<mark>' . $esc($run) . '</mark>' : $esc($run);
            $run = '';
        };
        foreach ($orig as $i => $ch) {
            if ($run !== '' && $marked[$i] !== $runMarked) {
                $flush();
            }
            $runMarked = $marked[$i];
            $run .= $ch;
        }
        $flush();
        return $out;
    }
}
