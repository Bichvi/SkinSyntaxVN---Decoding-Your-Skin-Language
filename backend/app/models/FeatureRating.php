<?php
// backend/app/models/FeatureRating.php
declare(strict_types=1);

/**
 * Đánh giá nhanh (1-5 sao) của khách hàng đã đăng nhập cho từng tính năng AI.
 * Dữ liệu phục vụ chương đánh giá luận văn (mức hài lòng theo thuật toán).
 *
 * Collection riêng `feature_ratings`; mỗi tài khoản tối đa 1 đánh giá / tính năng / ngày
 * (đánh giá mới trong ngày ghi đè đánh giá cũ, giữ first_created_at).
 */
class FeatureRating {
    public const COLLECTION = 'feature_ratings';
    public const FEATURES = ['recommendation', 'chatbot', 'cart_conflict'];
    public const MAX_COMMENT = 500;
    public const TIMEZONE = 'Asia/Ho_Chi_Minh';

    private $db;
    private static bool $indexesEnsured = false;

    public function __construct($db) {
        $this->db = $db;
    }

    private function collection() {
        $collection = $this->db->{self::COLLECTION};
        $marker = rtrim(sys_get_temp_dir(), '/\\') . '/skinsyntax_feature_ratings_indexes_v1';
        if (!self::$indexesEnsured && is_file($marker)) {
            self::$indexesEnsured = true;
        }
        if (!self::$indexesEnsured) {
            try {
                $collection->createIndex(
                    ['account_key' => 1, 'feature' => 1, 'day' => 1],
                    ['unique' => true, 'name' => 'feature_ratings_account_feature_day']
                );
                $collection->createIndex(
                    ['feature' => 1, 'created_at' => -1],
                    ['name' => 'feature_ratings_feature_created_at']
                );
                self::$indexesEnsured = true;
                @touch($marker);
            } catch (Throwable $e) {
                // Thiếu index không được làm hỏng storefront.
                error_log('FeatureRating index setup skipped: ' . $e->getMessage());
            }
        }
        return $collection;
    }

    /**
     * Kiểm tra và chuẩn hóa dữ liệu đầu vào (thuần, không chạm DB).
     * @return array{feature: string, stars: int, comment: string, context: array{page: string, rule_ids: string[]}}
     * @throws InvalidArgumentException
     */
    public static function normalize(array $input): array {
        $feature = is_string($input['feature'] ?? null) ? trim($input['feature']) : '';
        if (!in_array($feature, self::FEATURES, true)) {
            throw new InvalidArgumentException('Tính năng đánh giá không hợp lệ.');
        }

        $rawStars = $input['stars'] ?? null;
        if (is_string($rawStars) && preg_match('/^[1-5]$/', trim($rawStars))) {
            $rawStars = (int)trim($rawStars);
        }
        if (!is_int($rawStars) && !(is_float($rawStars) && floor($rawStars) === $rawStars)) {
            throw new InvalidArgumentException('Số sao không hợp lệ.');
        }
        $stars = (int)$rawStars;
        if ($stars < 1 || $stars > 5) {
            throw new InvalidArgumentException('Số sao phải từ 1 đến 5.');
        }

        $comment = is_string($input['comment'] ?? null) ? $input['comment'] : '';
        $comment = self::cleanText($comment, self::MAX_COMMENT, true);

        $context = ['page' => '', 'rule_ids' => []];
        $rawContext = $input['context'] ?? null;
        if (is_array($rawContext)) {
            if (is_string($rawContext['page'] ?? null)) {
                $context['page'] = self::cleanText($rawContext['page'], 60, false);
            }
            if (is_array($rawContext['rule_ids'] ?? null)) {
                foreach ($rawContext['rule_ids'] as $rule) {
                    if (!is_string($rule)) {
                        continue;
                    }
                    $rule = self::cleanText($rule, 60, false);
                    if ($rule !== '' && !in_array($rule, $context['rule_ids'], true)) {
                        $context['rule_ids'][] = $rule;
                    }
                    if (count($context['rule_ids']) >= 10) {
                        break;
                    }
                }
            }
        }

        return ['feature' => $feature, 'stars' => $stars, 'comment' => $comment, 'context' => $context];
    }

    private static function cleanText(string $text, int $max, bool $keepNewlines): string {
        $pattern = $keepNewlines ? '/[\x00-\x09\x0B\x0C\x0E-\x1F\x7F]/u' : '/[\x00-\x1F\x7F]/u';
        $text = preg_replace($pattern, '', $text) ?? '';
        if (!$keepNewlines) {
            $text = preg_replace('/\s+/u', ' ', $text) ?? '';
        }
        $text = trim($text);
        return function_exists('mb_substr') ? mb_substr($text, 0, $max, 'UTF-8') : substr($text, 0, $max);
    }

    public static function dayKey(?int $timestamp = null): string {
        $tz = new DateTimeZone(self::TIMEZONE);
        return (new DateTimeImmutable('@' . ($timestamp ?? time())))->setTimezone($tz)->format('Y-m-d');
    }

    public static function userHash(string $accountKey): string {
        return substr(hash('sha256', $accountKey), 0, 12);
    }

    /** Lưu (upsert) một đánh giá đã qua normalize(). */
    public function save(string $accountKey, string $role, array $rating): void {
        $accountKey = trim($accountKey);
        if ($accountKey === '') {
            throw new InvalidArgumentException('Thiếu thông tin tài khoản.');
        }
        $now = new \MongoDB\BSON\UTCDateTime();
        $day = self::dayKey();
        $filter = ['account_key' => $accountKey, 'feature' => $rating['feature'], 'day' => $day];
        $update = [
            '$set' => [
                'role' => $role,
                'stars' => (int)$rating['stars'],
                'comment' => (string)$rating['comment'],
                'context' => $rating['context'],
                'created_at' => $now,
            ],
            '$setOnInsert' => ['first_created_at' => $now],
        ];
        $collection = $this->collection();
        try {
            $collection->updateOne($filter, $update, ['upsert' => true]);
        } catch (Throwable $e) {
            // Hai request đồng thời có thể đua nhau chèn; thử lại một lần sẽ rơi vào nhánh cập nhật.
            $collection->updateOne($filter, $update, ['upsert' => true]);
        }
    }

    /**
     * Thống kê theo tính năng.
     * @return array<string, array{count:int, mean:float, std:float, pct_ge4:float, distribution:array<int,int>}>
     */
    public function summary(): array {
        $stars = [];
        foreach (self::FEATURES as $f) {
            $stars[$f] = [];
        }
        foreach ($this->collection()->find([], ['projection' => ['feature' => 1, 'stars' => 1]]) as $row) {
            $f = (string)($row['feature'] ?? '');
            $s = (int)($row['stars'] ?? 0);
            if (isset($stars[$f]) && $s >= 1 && $s <= 5) {
                $stars[$f][] = $s;
            }
        }
        $out = [];
        foreach ($stars as $f => $list) {
            $out[$f] = self::stats($list);
        }
        return $out;
    }

    /** @param int[] $list */
    public static function stats(array $list): array {
        $n = count($list);
        $dist = [1 => 0, 2 => 0, 3 => 0, 4 => 0, 5 => 0];
        foreach ($list as $s) {
            $dist[$s]++;
        }
        if ($n === 0) {
            return ['count' => 0, 'mean' => 0.0, 'std' => 0.0, 'pct_ge4' => 0.0, 'distribution' => $dist];
        }
        $mean = array_sum($list) / $n;
        $var = 0.0;
        foreach ($list as $s) {
            $var += ($s - $mean) ** 2;
        }
        // Độ lệch chuẩn mẫu (n-1); n = 1 -> 0.
        $std = $n > 1 ? sqrt($var / ($n - 1)) : 0.0;
        return [
            'count' => $n,
            'mean' => round($mean, 2),
            'std' => round($std, 2),
            'pct_ge4' => round(($dist[4] + $dist[5]) * 100 / $n, 1),
            'distribution' => $dist,
        ];
    }

    /** @return iterable<array> các tài liệu đánh giá, mới nhất trước (dùng để xuất CSV). */
    public function allForExport(int $limit = 50000): iterable {
        return $this->collection()->find([], ['sort' => ['created_at' => -1], 'limit' => max(1, $limit)]);
    }
}
