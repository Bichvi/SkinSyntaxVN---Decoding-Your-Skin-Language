<?php
// Từ khóa tìm kiếm phổ biến trên Redis (U04).
//
//   search:popular:v1  ZSET  member = q_norm (chữ thường, giữ dấu, gộp khoảng trắng, <= 60 ký tự), score = số lần tìm có kết quả
//   search:prefix:v1   ZSET  điểm 0 (lex), member = fold(q_norm) . "\x1f" . q_norm  -> ZRANGEBYLEX tìm theo tiền tố KHÔNG DẤU
//
// Chọn ZRANGEBYLEX (<= 100 member) + ZSCORE pipeline thay vì quét ZSET popular: tra cứu theo tiền tố là O(log N + M),
// một round-trip cho lex + một cho điểm, không phụ thuộc kích thước tập (đã cắt ~5000). Tên key theo docs/redis/conventions.md.
// Cắt tỉa/decay hằng ngày: shared/redis_kit/popular.py (U11 lên lịch). Redis lỗi => trả [] / false, không ném lỗi.
// Lọc PII: số điện thoại, email, URL, chuỗi quá dài hoặc có ký tự lạ KHÔNG bao giờ được ghi.

require_once __DIR__ . '/RedisLite.php';
require_once __DIR__ . '/SearchSkinProfile.php';

final class SearchPopularity
{
    public const POPULAR_KEY = 'search:popular:v1';
    public const PREFIX_KEY = 'search:prefix:v1';
    public const SEP = "\x1f";
    public const MAX_LEN = 60;
    public const MIN_LEN = 2;
    public const KEEP_TOP = 5000;
    /** Vượt ngưỡng này khi ghi thì cắt ngay về KEEP_TOP (job hằng ngày dọn nốt phần lex mồ côi). */
    public const TRIM_AT = 6000;
    public const LEX_LIMIT = 100;

    /** q_norm hoặc null nếu không được phép đưa vào danh sách phổ biến. */
    public static function normalize(string $q): ?string
    {
        $q = mb_strtolower(trim((string)preg_replace('/\s+/u', ' ', $q)), 'UTF-8');
        $len = mb_strlen($q, 'UTF-8');
        if ($len < self::MIN_LEN || $len > self::MAX_LEN) {
            return null;
        }
        if (self::looksLikePii($q)) {
            return null;
        }
        // Chỉ chữ, số, khoảng trắng và vài dấu câu thường gặp ở tên sản phẩm; loại <, >, ", ` , điều khiển...
        if (preg_match('/^[\p{L}\p{N} \-\'+&.%\/()]+$/u', $q) !== 1) {
            return null;
        }
        return $q;
    }

    public static function looksLikePii(string $q): bool
    {
        if (strpos($q, '@') !== false || preg_match('~https?:|www\.|\.(com|vn|net|org)\b~i', $q) === 1) {
            return true;
        }
        // Dãy >= 7 chữ số (kể cả có cách quãng bằng khoảng trắng, chấm, gạch): SĐT, số thẻ, CCCD...
        if (preg_match('/(?:\d[\s.\-]?){7,}/', $q) === 1) {
            return true;
        }
        return preg_match('/(?:\+?84|0)[\s.\-]?\d{2,3}[\s.\-]?\d{3}[\s.\-]?\d{3,4}/', $q) === 1;
    }

    /** Ghi 1 lượt tìm có kết quả. Trả true nếu đã ghi vào Redis. */
    public static function record(string $q, int $resultCount, ?RedisLite $redis = null): bool
    {
        if ($resultCount <= 0) {
            return false;
        }
        $norm = self::normalize($q);
        if ($norm === null) {
            return false;
        }
        $redis = $redis ?? RedisLite::shared();
        if ($redis === null) {
            return false;
        }
        $member = SearchSkinProfile::fold($norm) . self::SEP . $norm;
        $replies = $redis->pipeline([
            ['ZINCRBY', self::POPULAR_KEY, '1', $norm],
            ['ZADD', self::PREFIX_KEY, '0', $member],
            ['ZCARD', self::POPULAR_KEY],
        ]);
        if ($replies === null || ($replies[0] ?? null) instanceof RedisLiteReplyError) {
            return false;
        }
        if (is_int($replies[2] ?? null) && $replies[2] > self::TRIM_AT) {
            $redis->command(['ZREMRANGEBYRANK', self::POPULAR_KEY, '0', (string)-(self::KEEP_TOP + 1)]);
        }
        return true;
    }

    /**
     * Từ khóa phổ biến bắt đầu bằng $prefix (không dấu), nhiều lượt nhất trước.
     * @return array<int,array{q:string,score:int}>
     */
    public static function lookup(string $prefix, int $limit = 5, ?RedisLite $redis = null): array
    {
        $fp = SearchSkinProfile::fold($prefix);
        if (mb_strlen($fp, 'UTF-8') < self::MIN_LEN || mb_strlen($fp, 'UTF-8') > self::MAX_LEN) {
            return [];
        }
        $redis = $redis ?? RedisLite::shared();
        if ($redis === null) {
            return [];
        }
        $members = $redis->command([
            'ZRANGEBYLEX', self::PREFIX_KEY, '[' . $fp, '[' . $fp . "\xff", 'LIMIT', '0', (string)self::LEX_LIMIT,
        ]);
        if (!is_array($members) || $members === []) {
            return [];
        }
        $terms = [];
        foreach ($members as $m) {
            $pos = is_string($m) ? strpos($m, self::SEP) : false;
            if ($pos === false) {
                continue;
            }
            $term = substr($m, $pos + 1);
            if ($term !== '' && SearchSkinProfile::fold($term) !== $fp) {
                $terms[$term] = true;
            }
        }
        $terms = array_keys($terms);
        if ($terms === []) {
            return [];
        }
        $scores = $redis->pipeline(array_map(static fn(string $t): array => ['ZSCORE', self::POPULAR_KEY, $t], $terms));
        if ($scores === null) {
            return [];
        }
        $out = [];
        foreach ($terms as $i => $term) {
            $s = $scores[$i] ?? null;
            if (is_string($s) && is_numeric($s) && (float)$s >= 1) {
                $out[] = ['q' => (string)$term, 'score' => (int)round((float)$s)];
            }
        }
        usort($out, static fn(array $a, array $b): int => [$b['score'], mb_strlen($a['q'], 'UTF-8'), $a['q']] <=> [$a['score'], mb_strlen($b['q'], 'UTF-8'), $b['q']]);
        return array_slice($out, 0, max(0, $limit));
    }
}
