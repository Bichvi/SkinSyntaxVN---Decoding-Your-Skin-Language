<?php
// Logic thuần (dễ test) cho nhãn công dụng do người duyệt (U07): CSRF, kiểm key, dựng $set cho san_pham,
// băm danh tính admin, sự kiện catalog:changes:v1. Không đụng DB; model TagSuggestion lo phần Mongo.

require_once __DIR__ . '/TagCatalog.php';
require_once __DIR__ . '/RedisLite.php';

final class TagCuration
{
    public const SOURCE_MANUAL = 'manual';
    public const SOURCE_LLM = 'llm_approved';
    public const CSRF_SESSION_KEY = 'admin_tag_csrf';
    public const STREAM = 'catalog:changes:v1';
    public const STREAM_MAXLEN = 10000;

    // ---- CSRF (token ngẫu nhiên theo phiên) -------------------------------------------------------------

    /** @param array $session thường là $_SESSION (truyền tham chiếu để lưu token mới). */
    public static function csrfToken(array &$session): string
    {
        $token = $session[self::CSRF_SESSION_KEY] ?? '';
        if (!is_string($token) || !preg_match('/^[a-f0-9]{64}$/', $token)) {
            $token = bin2hex(random_bytes(32));
            $session[self::CSRF_SESSION_KEY] = $token;
        }
        return $token;
    }

    /** Bắt buộc có token trong phiên và khớp token gửi lên (so sánh hằng thời gian). */
    public static function csrfValid(array $session, $posted): bool
    {
        $expected = $session[self::CSRF_SESSION_KEY] ?? '';
        return is_string($expected) && $expected !== '' && is_string($posted) && hash_equals($expected, $posted);
    }

    // ---- Danh tính người sửa (không lưu email thô) -------------------------------------------------------

    public static function actorHash(array $user, ?string $salt = null): string
    {
        $identity = '';
        foreach (['email', 'ma_nv', 'id'] as $field) {
            if (!empty($user[$field])) {
                $identity = strtolower(trim((string)$user[$field]));
                break;
            }
        }
        if ($salt === null) {
            $salt = (string)(function_exists('ss_env') ? ss_env('SEARCH_USER_HASH_SALT', '') : (getenv('SEARCH_USER_HASH_SALT') ?: ''));
        }
        return 'adm_' . substr(hash_hmac('sha256', $identity === '' ? 'unknown' : $identity, $salt !== '' ? $salt : 'ssv-tag-actor'), 0, 16);
    }

    // ---- Chọn nhãn từ form / body -----------------------------------------------------------------------

    /**
     * Đọc 3 nhóm key từ input (`cong_dung_keys[]`, `van_de_da_keys[]`, `loai_da_keys[]`).
     *
     * @return array{selection:array<string,string[]>,rejected:string[]}
     */
    public static function selectionFromInput(array $input): array
    {
        $selection = [];
        $rejected = [];
        foreach (TagCatalog::FIELDS as $group => $field) {
            $res = TagCatalog::validate($group, $input[$field] ?? []);
            $selection[$group] = $res['valid'];
            foreach ($res['rejected'] as $bad) {
                $rejected[] = $field . ':' . $bad;
            }
        }
        return ['selection' => $selection, 'rejected' => $rejected];
    }

    /**
     * `$set` cho san_pham. Chỉ chạm 6 trường nhãn; KHÔNG sửa updated_at (ETL --since đọc tag_updated_at).
     *
     * @param array<string,string[]> $selection đã qua selectionFromInput
     * @param mixed $now UTCDateTime (hoặc giá trị bất kỳ khi test)
     */
    public static function productTagSet(array $selection, string $source, string $actorHash, $now): array
    {
        if (!in_array($source, [self::SOURCE_MANUAL, self::SOURCE_LLM], true)) {
            throw new InvalidArgumentException('tag_source không hợp lệ');
        }
        $set = [];
        foreach (TagCatalog::FIELDS as $group => $field) {
            $set[$field] = array_values(TagCatalog::validate($group, $selection[$group] ?? [])['valid']);
        }
        $set['tag_source'] = $source;
        $set['tag_updated_at'] = $now;
        $set['tag_updated_by'] = $actorHash;
        return $set;
    }

    // ---- Sự kiện đồng bộ (U11 tiêu thụ) ------------------------------------------------------------------

    /** @return array<string,string> */
    public static function changeEvent(string $productId, string $source, ?int $nowMs = null): array
    {
        return [
            'type' => 'product_updated',
            'ma_san_pham' => $productId,
            'reason' => 'tags',
            'source' => $source,
            'ts' => (string)($nowMs ?? (int)round(microtime(true) * 1000)),
        ];
    }

    /** XADD catalog:changes:v1. Redis lỗi => false (không ném, không chặn thao tác admin). */
    public static function publishChange(string $productId, string $source, ?RedisLite $redis = null): bool
    {
        $redis = $redis ?? RedisLite::shared();
        if ($redis === null) {
            return false;
        }
        try {
            return $redis->xadd(self::STREAM, self::changeEvent($productId, $source), self::STREAM_MAXLEN) !== null;
        } catch (Throwable $e) {
            return false;
        }
    }

    // ---- Dữ liệu cho view -------------------------------------------------------------------------------

    /**
     * Ngữ cảnh cho khối checkbox trong form sửa sản phẩm.
     *
     * @param array $product bản ghi san_pham (mảng)
     * @param array $session thường là $_SESSION
     */
    public static function formContext(array $product, array &$session): array
    {
        $selected = [];
        foreach (TagCatalog::FIELDS as $group => $field) {
            $value = $product[$field] ?? [];
            if ($value instanceof Traversable) {
                $value = iterator_to_array($value, false);
            }
            $selected[$group] = TagCatalog::validate($group, is_array($value) ? $value : [])['valid'];
        }
        $updatedAt = $product['tag_updated_at'] ?? null;
        if (is_object($updatedAt) && method_exists($updatedAt, 'toDateTime')) {
            $updatedAt = $updatedAt->toDateTime()->setTimezone(new DateTimeZone('Asia/Ho_Chi_Minh'))->format('d/m/Y H:i');
        } elseif (!is_string($updatedAt)) {
            $updatedAt = '';
        }
        $source = (string)($product['tag_source'] ?? '');
        return [
            'groups' => TagCatalog::groups(),
            'labels' => TagCatalog::LABELS,
            'fields' => TagCatalog::FIELDS,
            'selected' => $selected,
            'source' => in_array($source, [self::SOURCE_MANUAL, self::SOURCE_LLM], true) ? $source : '',
            'updated_at' => $updatedAt,
            'csrf' => self::csrfToken($session),
        ];
    }
}
