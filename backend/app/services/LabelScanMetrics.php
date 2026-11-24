<?php
// backend/app/services/LabelScanMetrics.php
// Đo lường tính năng "chụp bảng thành phần" để chứng minh nó có lời hay không:
// số lần chụp, xung đột bắt được, sản phẩm thay thế đã hiển thị -> thêm vào giỏ -> (đơn hàng, tính ở báo cáo) và chi phí LLM.
// Mongo `label_scan_events`. RIÊNG TƯ: không lưu ảnh, chữ thành phần, tên/hãng đọc từ ảnh, email, IP, user agent.
// Chỉ lưu: hash phiên/người dùng, mã sản phẩm của shop, số đếm, enum và số token.
// Hàm thuần (build*/remember*/attribution*) tách khỏi IO (record*/attribute*) để test không cần DB.

require_once __DIR__ . '/ProductEventTracker.php';

final class LabelScanMetrics
{
    public const COLLECTION = 'label_scan_events';
    public const SESSION_KEY = 'label_scan_shown';
    public const SHOWN_TTL = 86400;   // giây: add_to_cart trong 24h sau lần chụp mới được gán về tính năng này
    public const SHOWN_CAP = 30;
    public const MAX_ALTERNATIVES = 3;
    public const OUTCOMES = ['ok', 'not_label', 'unreadable', 'vision_unavailable', 'unavailable'];
    public const VERDICTS = ['good', 'caution', 'avoid', 'neutral', 'unknown'];
    public const PRODUCT_ID_PATTERN = '/^[A-Za-z0-9_-]{1,40}$/';

    public static function enabled(): bool
    {
        $raw = function_exists('ss_env') ? ss_env('LABEL_SCAN_METRICS_ENABLED', '1') : (string)(getenv('LABEL_SCAN_METRICS_ENABLED') ?: '1');
        return in_array(strtolower(trim((string)$raw)), ['1', 'true', 'yes', 'on'], true);
    }

    /** Dấu thời gian cho Mongo (UTCDateTime nếu có extension, ngược lại số giây). */
    public static function ts(int $now)
    {
        return class_exists('MongoDB\BSON\UTCDateTime') ? new \MongoDB\BSON\UTCDateTime($now * 1000) : $now;
    }

    // ------------------------------------------------------------------ pure helpers

    /**
     * @param array|null $r kết quả Flask ĐÃ validate (SearchRecommendClient::validateLabelResponse) hoặc null
     * @return array<string,mixed> tài liệu `scan`; không có khóa nào chứa tên/hãng/chữ thành phần/email
     */
    public static function buildScanDoc(?array $r, string $input, ?string $sessionHash, ?string $userHash, $ts): array
    {
        $r = is_array($r) ? $r : null;
        $status = $r['status'] ?? null;
        $outcome = (is_string($status) && in_array($status, self::OUTCOMES, true)) ? $status : 'unavailable';

        $warnings = is_array($r['warnings'] ?? null) ? $r['warnings'] : [];
        $total = 0;
        $high = 0;
        foreach (is_array($r['conflicts'] ?? null) ? $r['conflicts'] : [] as $c) {
            if (!is_array($c)) {
                continue;
            }
            $total++;
            if (($c['severity'] ?? null) === 'high') {
                $high++;
            }
        }
        $verdict = $r['skin_fit']['verdict'] ?? 'unknown';
        $matched = $r['product']['matched_product_id'] ?? null;
        $matched = (is_string($matched) && preg_match(self::PRODUCT_ID_PATTERN, $matched)) ? $matched : null;

        $alts = [];
        foreach (is_array($r['alternatives'] ?? null) ? $r['alternatives'] : [] as $a) {
            $pid = is_array($a) ? ($a['product_id'] ?? null) : null;
            if (is_string($pid) && preg_match(self::PRODUCT_ID_PATTERN, $pid) && !in_array($pid, $alts, true)) {
                $alts[] = $pid;
            }
        }

        return [
            'type' => 'scan',
            'ts' => $ts,
            'schema_version' => 1,
            'input' => $input === 'text' ? 'text' : 'photo',
            'outcome' => $outcome,
            'took_ms' => is_int($r['took_ms'] ?? null) ? max(0, $r['took_ms']) : 0,
            'has_cart' => !in_array('cart_empty', $warnings, true),
            'conflicts_total' => $total,
            'conflicts_high' => $high,
            'skin_verdict' => (is_string($verdict) && in_array($verdict, self::VERDICTS, true)) ? $verdict : 'unknown',
            'low_recognition' => in_array('low_recognition', $warnings, true),
            'in_shop' => $matched !== null,
            'matched_product_id' => $matched,
            'alternative_ids' => array_slice($alts, 0, self::MAX_ALTERNATIVES),
            'usage' => self::sanitizeUsage($r['usage'] ?? []),
            'session_hash' => $sessionHash,
            'user_hash' => $userHash,
        ];
    }

    /** @return array<int,array{provider:string,model:string,ok:bool,prompt_tokens:int,completion_tokens:int}> */
    private static function sanitizeUsage($usage): array
    {
        $out = [];
        foreach (is_array($usage) ? array_slice(array_values($usage), 0, 4) : [] as $u) {
            if (!is_array($u) || !in_array($u['provider'] ?? null, ['groq', 'gemini'], true) || !is_string($u['model'] ?? null) || !is_bool($u['ok'] ?? null)) {
                continue;
            }
            $out[] = [
                'provider' => $u['provider'],
                'model' => mb_substr($u['model'], 0, 100, 'UTF-8'),
                'ok' => $u['ok'],
                'prompt_tokens' => is_int($u['prompt_tokens'] ?? null) ? max(0, min(1000000, $u['prompt_tokens'])) : 0,
                'completion_tokens' => is_int($u['completion_tokens'] ?? null) ? max(0, min(1000000, $u['completion_tokens'])) : 0,
            ];
        }
        return $out;
    }

    /** @return string[] mã sản phẩm đã hiển thị cho khách: sản phẩm thay thế + sản phẩm khớp, không trùng. */
    public static function shownIds(array $doc): array
    {
        return array_map(static fn($e) => $e['id'], self::shownMap($doc));
    }

    /** @return array<int,array{id:string,kind:string}> kind = 'alternative'|'matched' (danh sách, không dùng khóa là mã vì PHP đổi "12" thành int) */
    public static function shownMap(array $doc): array
    {
        $out = [];
        $seen = [];
        foreach (is_array($doc['alternative_ids'] ?? null) ? $doc['alternative_ids'] : [] as $pid) {
            if (is_string($pid) && $pid !== '' && !isset($seen[$pid])) {
                $seen[$pid] = true;
                $out[] = ['id' => $pid, 'kind' => 'alternative'];
            }
        }
        $m = $doc['matched_product_id'] ?? null;
        if (is_string($m) && $m !== '' && !isset($seen[$m])) {
            $out[] = ['id' => $m, 'kind' => 'matched'];
        }
        return $out;
    }

    /**
     * Ghi nhớ trong session: pid => ['ts'=>..,'kind'=>..]; bỏ mục hết hạn, giữ tối đa SHOWN_CAP mục mới nhất.
     * @param array $ids mỗi phần tử là pid (kind 'alternative') hoặc ['id'=>pid,'kind'=>'alternative'|'matched']
     */
    public static function rememberShown(array &$sessionStore, array $ids, int $now): void
    {
        $cur = is_array($sessionStore[self::SESSION_KEY] ?? null) ? $sessionStore[self::SESSION_KEY] : [];
        $keep = [];
        foreach ($cur as $pid => $e) {
            if (is_array($e) && is_int($e['ts'] ?? null) && ($now - $e['ts']) <= self::SHOWN_TTL) {
                $keep[(string)$pid] = ['ts' => $e['ts'], 'kind' => ($e['kind'] ?? '') === 'matched' ? 'matched' : 'alternative'];
            }
        }
        foreach ($ids as $item) {
            $pid = is_array($item) ? ($item['id'] ?? null) : $item;
            $kind = is_array($item) ? ($item['kind'] ?? 'alternative') : 'alternative';
            if (!is_string($pid) || !preg_match(self::PRODUCT_ID_PATTERN, $pid)) {
                continue;
            }
            unset($keep[$pid]);
            $keep[$pid] = ['ts' => $now, 'kind' => $kind === 'matched' ? 'matched' : 'alternative'];
        }
        uasort($keep, static fn($a, $b) => $b['ts'] <=> $a['ts']);
        $sessionStore[self::SESSION_KEY] = array_slice($keep, 0, self::SHOWN_CAP, true);
    }

    /** @return string|null 'alternative'|'matched' nếu sản phẩm vừa được hiển thị trong 24h qua, ngược lại null */
    public static function attributionFor(array $sessionStore, string $pid, int $now): ?string
    {
        $e = $sessionStore[self::SESSION_KEY][$pid] ?? null;
        if (!is_array($e) || !is_int($e['ts'] ?? null) || ($now - $e['ts']) > self::SHOWN_TTL) {
            return null;
        }
        return ($e['kind'] ?? null) === 'matched' ? 'matched' : (($e['kind'] ?? null) === 'alternative' ? 'alternative' : null);
    }

    /** @return array<string,mixed> */
    public static function buildAddToCartDoc(string $pid, string $kind, ?string $sessionHash, ?string $userHash, $ts): array
    {
        return [
            'type' => 'add_to_cart',
            'product_id' => $pid,
            'kind' => $kind === 'matched' ? 'matched' : 'alternative',
            'session_hash' => $sessionHash,
            'user_hash' => $userHash,
            'ts' => $ts,
            'schema_version' => 1,
            'event_id' => hash('sha256', 'label_atc|' . (string)$sessionHash . '|' . $pid . '|' . date('Y-m-d')),
        ];
    }

    // ------------------------------------------------------------------ hash helpers (cùng cách với SearchEventTracker)

    public static function sessionHash(): ?string
    {
        $h = ProductEventTracker::sessionHash();
        if ($h !== null) {
            return $h;
        }
        self::loadSearchTracker();
        return class_exists('SearchEventTracker') ? SearchEventTracker::sessionHash() : null;
    }

    public static function userHash(): ?string
    {
        self::loadSearchTracker();
        return class_exists('SearchEventTracker') ? SearchEventTracker::userHash() : null;
    }

    private static function loadSearchTracker(): void
    {
        if (!class_exists('SearchEventTracker', false)) {
            require_once __DIR__ . '/SearchEventTracker.php';
        }
    }

    // ------------------------------------------------------------------ IO (không bao giờ ném)

    public static function recordScan($db, array $doc): void
    {
        try {
            if (!self::enabled() || self::isExcluded() || $db === null) {
                return;
            }
            $db->{self::COLLECTION}->insertOne($doc);
        } catch (Throwable $e) {
            error_log('label_scan_metrics skipped: ' . get_class($e));
        }
    }

    /** Gọi sau khi thêm vào giỏ: nếu sản phẩm vừa được màn chụp ảnh hiển thị (trong session) thì ghi một sự kiện, dedupe theo phiên/sản phẩm/ngày. */
    public static function attributeAddToCart($db, string $pid): void
    {
        try {
            if (!self::enabled() || $db === null || !preg_match(self::PRODUCT_ID_PATTERN, $pid) || self::isExcluded()) {
                return;
            }
            if (!isset($_SESSION) || !is_array($_SESSION)) {
                return;
            }
            $kind = self::attributionFor($_SESSION, $pid, time());
            if ($kind === null) {
                return;
            }
            $doc = self::buildAddToCartDoc($pid, $kind, self::sessionHash(), self::userHash(), self::ts(time()));
            $eventId = $doc['event_id'];
            unset($doc['event_id']);
            $db->{self::COLLECTION}->updateOne(['event_id' => $eventId], ['$setOnInsert' => $doc], ['upsert' => true]);
        } catch (Throwable $e) {
            error_log('label_scan_metrics atc skipped: ' . get_class($e));
        }
    }

    private static function isExcluded(): bool
    {
        return ProductEventTracker::isExcluded();
    }
}
