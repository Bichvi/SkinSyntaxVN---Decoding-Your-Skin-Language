<?php
// Chỉ mục gợi ý sản phẩm trong Redis (U04) để đường "cache miss" của autocomplete không phải chờ Atlas
// (mỗi truy vấn Atlas ~60 ms, p95 ~110-180 ms; mục tiêu p95 < 80 ms).
//
//   search:suggestidx:v1  STRING JSON {built_at, gen, items:[...]}  TTL 3600s (dài hạn hơn "soft TTL")
//
// - Mỗi phần tử = bản ghi sản phẩm rút gọn (đúng các trường JS/app dùng) + trường tìm kiếm đã bỏ dấu:
//   _n tên, _b hãng, _c loại/danh mục, _k mã sản phẩm.
// - Stale-while-revalidate: quá SOFT_TTL (300s) hoặc đổi generation (search:cachegen:v1) thì vẫn phục vụ bản cũ
//   (nếu cùng generation) và dựng lại SAU response (khóa lock:search_suggest_index:v1 chống dồn). Chưa có chỉ mục
//   => trả null để caller dùng đường Mongo (SanPham::searchSuggestions) và dựng chỉ mục nền.
// - Redis chết => null (caller dùng Mongo). Dữ liệu gốc vẫn là Mongo Atlas; chỉ mục là bản sao dẫn xuất, xóa được.
// - Khớp theo cùng quy tắc với SanPham::buildTokenSearchFilter ở chế độ gợi ý: AND giữa token, OR giữa trường
//   (tên/hãng/danh mục/mã), token cuối khớp tiền tố từ, token trước khớp đủ từ, không dấu.

require_once __DIR__ . '/RedisLite.php';
require_once __DIR__ . '/SearchSkinProfile.php';

final class SearchSuggestIndex
{
    public const KEY = 'search:suggestidx:v1';
    public const LOCK_KEY = 'lock:search_suggest_index:v1';
    public const GEN_KEY = 'search:cachegen:v1';
    public const SOFT_TTL = 300;
    public const HARD_TTL = 3600;
    public const LOCK_MS = 60000;
    public const MAX_TOKENS = 6;
    public const STOPWORDS = ['cho', 'và', 'của', 'các', 'loại', 'dành', 'the', 'for'];

    /** Trường công khai giữ lại cho mỗi sản phẩm (JSON gợi ý + app mobile). */
    public const KEEP_FIELDS = [
        'ma_san_pham', 'id', 'ten_san_pham', 'thuong_hieu', 'gia_ban', 'link_hinh_anh', 'ma_thuong_hieu', 'ma_danh_muc',
        'loai_san_pham', 'so_luong_da_ban', 'so_luong_ton_kho', 'trang_thai_kho', 'trang_thai',
        'is_available', 'ton_kho_hien_thi', 'diem_danh_gia', 'so_luong_danh_gia',
    ];

    /** Chuỗi chữ-số thường, không dấu, ký hiệu -> khoảng trắng, gộp khoảng trắng. */
    public static function plain(string $text): string
    {
        return trim((string)preg_replace('/[^a-z0-9]+/', ' ', SearchSkinProfile::fold($text)));
    }

    /** Thêm các trường tìm kiếm đã bỏ dấu vào một bản ghi sản phẩm rút gọn. */
    public static function decorate(array $product): array
    {
        $item = array_intersect_key($product, array_flip(self::KEEP_FIELDS));
        // link_hinh_anh có thể chứa hàng chục URL nối bằng "|" (~4KB/sản phẩm): gợi ý chỉ cần ảnh đầu tiên
        $item['link_hinh_anh'] = self::firstImage((string)($product['link_hinh_anh'] ?? ''));
        $item['_n'] = self::plain((string)($product['ten_san_pham'] ?? ''));
        $item['_b'] = self::plain((string)($product['thuong_hieu'] ?? ''));
        $item['_c'] = self::plain(trim((string)($product['loai_san_pham'] ?? '') . ' ' . (string)($product['danh_muc_day_du'] ?? '')));
        $item['_k'] = strtolower(trim((string)($product['ma_san_pham'] ?? $product['id'] ?? '')));
        return $item;
    }

    public static function firstImage(string $raw): string
    {
        foreach (explode('|', $raw) as $part) {
            $part = trim($part);
            if ($part !== '') {
                return $part;
            }
        }
        return '';
    }

    /** Token tìm kiếm đã bỏ dấu (cùng quy tắc tách với SanPham::tokenizeSearchQuery). */
    public static function tokens(string $q): array
    {
        $q = mb_strtolower(trim((string)preg_replace('/\s+/u', ' ', $q)), 'UTF-8');
        $out = [];
        foreach ($q === '' ? [] : explode(' ', $q) as $t) {
            if ($t === '' || in_array($t, self::STOPWORDS, true)) {
                continue;
            }
            $p = self::plain($t);
            if ($p !== '') {
                $out[] = $p;
            }
        }
        $out = array_slice($out, 0, self::MAX_TOKENS);
        if ($out === []) {
            $whole = self::plain($q);
            $out = $whole === '' ? [] : [$whole];
        }
        return $out;
    }

    /**
     * Sản phẩm khớp, xếp theo SanPham::rankSuggestions (cần SanPham được nạp), đã bỏ trường _*.
     * @param array<int,array> $items
     */
    public static function match(array $items, string $q, int $limit): array
    {
        $tokens = self::tokens($q);
        if ($tokens === []) {
            return [];
        }
        $last = count($tokens) - 1;
        $numeric = is_numeric(trim($q)) ? strtolower(trim($q)) : null;
        $hits = [];
        foreach ($items as $it) {
            $n = ' ' . ($it['_n'] ?? '') . ' ';
            $b = ' ' . ($it['_b'] ?? '') . ' ';
            $c = ' ' . ($it['_c'] ?? '') . ' ';
            $k = (string)($it['_k'] ?? '');
            $ok = true;
            foreach ($tokens as $i => $tok) {
                // token cuối: đầu từ; token trước: đủ từ
                $needle = $i === $last ? ' ' . $tok : ' ' . $tok . ' ';
                if (strpos($n, $needle) === false && strpos($b, $needle) === false && strpos($c, $needle) === false
                    && !($k !== '' && strncmp($k, $tok, strlen($tok)) === 0)) {
                    $ok = false;
                    break;
                }
            }
            if (!$ok && $numeric !== null && $k === $numeric) {
                $ok = true;
            }
            if ($ok) {
                $hits[] = $it;
            }
        }
        if ($hits === []) {
            return [];
        }
        $ranked = class_exists('SanPham') ? SanPham::rankSuggestions($hits, $q, $limit) : array_slice($hits, 0, $limit);
        return array_map(static function (array $row): array {
            return array_filter($row, static fn($key): bool => !is_string($key) || $key === '' || $key[0] !== '_', ARRAY_FILTER_USE_KEY);
        }, $ranked);
    }

    /**
     * Danh sách chỉ mục dùng được, hoặc null (chưa có / Redis lỗi). Có thể xếp việc dựng lại chạy sau response.
     * @param callable():array<int,array> $builder trả về danh sách sản phẩm rút gọn (chưa decorate)
     */
    public static function items(?RedisLite $redis, callable $builder): ?array
    {
        if ($redis === null) {
            return null;
        }
        $genRaw = $redis->get(self::GEN_KEY);
        $gen = ($genRaw !== null && ctype_digit($genRaw)) ? (int)$genRaw : 0;
        $raw = $redis->get(self::KEY);
        $doc = $raw !== null ? json_decode($raw, true) : null;
        $valid = is_array($doc) && isset($doc['items'], $doc['built_at']) && is_array($doc['items']);
        if ($valid && (int)($doc['gen'] ?? 0) === $gen && (time() - (int)$doc['built_at']) <= self::SOFT_TTL) {
            return $doc['items'];
        }
        self::scheduleRefresh($redis, $builder, $gen);
        return $valid && (int)($doc['gen'] ?? 0) === $gen ? $doc['items'] : null;
    }

    private static function scheduleRefresh(RedisLite $redis, callable $builder, int $gen): void
    {
        $task = static function () use ($redis, $builder, $gen): void {
            self::rebuild($redis, $builder, $gen);
        };
        if (class_exists('AfterResponse')) {
            AfterResponse::defer($task);
        } else {
            $task();
        }
    }

    /** Dựng lại chỉ mục (có khóa). Trả true nếu đã ghi. */
    public static function rebuild(RedisLite $redis, callable $builder, int $gen): bool
    {
        if (!$redis->set(self::LOCK_KEY, '1', ['px' => self::LOCK_MS, 'nx' => true])) {
            return false; // request khác đang dựng (hoặc Redis lỗi)
        }
        try {
            $items = array_map([self::class, 'decorate'], $builder());
            if ($items === []) {
                return false;
            }
            $json = json_encode(['built_at' => time(), 'gen' => $gen, 'items' => $items], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
            return $json !== false && $redis->setex(self::KEY, self::HARD_TTL, $json);
        } catch (\Throwable $e) {
            error_log('SearchSuggestIndex::rebuild failed: ' . $e->getMessage());
            return false;
        } finally {
            $redis->del(self::LOCK_KEY);
        }
    }
}
