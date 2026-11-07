<?php
/**
 * InteractionLogger.php
 * Static helper service for non-blocking unified interaction logging
 */

require_once dirname(__DIR__) . '/models/TuongTac.php';

class InteractionLogger {
    private static ?TuongTac $model = null;

    private static function getModel(): TuongTac {
        if (self::$model === null) {
            global $db;
            self::$model = new TuongTac($db);
        }
        return self::$model;
    }

    /**
     * Non-blocking safe logging wrapper
     */
    public static function log(
        string $type,
        string|int|null $productId,
        ?int $userId = null,
        ?string $sessionId = null,
        array $metadata = []
    ): bool {
        try {
            if ($userId === null && isset($_SESSION['user']['ma_kh'])) {
                $userId = (int)$_SESSION['user']['ma_kh'];
            }
            if ($sessionId === null && session_status() === PHP_SESSION_ACTIVE) {
                $sessionId = session_id();
            }
            if (!isset($metadata['source'])) {
                $metadata['source'] = 'organic';
            }
            return self::getModel()->logInteraction($type, $productId, $userId, $sessionId, $metadata);
        } catch (\Throwable $e) {
            // Fail silently so logging never breaks core user operations
            error_log('InteractionLogger::log error: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Lấy user id + session id hiện tại để truyền vào log chạy hoãn (AfterResponse) sau khi session đã đóng.
     * Cùng điều kiện với InteractionLogger::log nên document lưu ra không đổi.
     *
     * @return array{0: ?int, 1: ?string}
     */
    public static function captureContext(): array {
        $userId = isset($_SESSION['user']['ma_kh']) ? (int)$_SESSION['user']['ma_kh'] : null;
        $sessionId = session_status() === PHP_SESSION_ACTIVE ? session_id() : null;
        return [$userId, $sessionId];
    }

    public static function logView(string|int $productId, array $metadata = [], ?int $userId = null, ?string $sessionId = null): bool {
        return self::log(TuongTac::TYPE_VIEW, $productId, $userId, $sessionId, $metadata);
    }

    public static function logSearch(string $query, array $metadata = [], ?int $userId = null, ?string $sessionId = null): bool {
        $cleanQuery = trim($query);
        if ($cleanQuery === '') return false;
        $meta = array_merge($metadata, ['query' => $cleanQuery]);
        return self::log(TuongTac::TYPE_SEARCH, null, $userId, $sessionId, $meta);
    }

    public static function logSearchClick(string|int $productId, string $query, array $metadata = []): bool {
        $meta = array_merge($metadata, ['query' => trim($query)]);
        return self::log(TuongTac::TYPE_SEARCH_CLICK, $productId, null, null, $meta);
    }

    public static function logCart(string|int $productId, int $qty = 1, array $metadata = []): bool {
        $meta = array_merge($metadata, ['quantity' => max(1, $qty)]);
        return self::log(TuongTac::TYPE_ADD_TO_CART, $productId, null, null, $meta);
    }

    public static function logCartRemove(string|int $productId, array $metadata = []): bool {
        return self::log(TuongTac::TYPE_CART_REMOVE, $productId, null, null, $metadata);
    }

    public static function logPurchase(string|int $productId, ?int $userId, int $orderId, int $qty = 1, array $metadata = []): bool {
        $meta = array_merge($metadata, ['order_id' => $orderId, 'quantity' => max(1, $qty)]);
        return self::log(TuongTac::TYPE_PURCHASE, $productId, $userId, null, $meta);
    }
}
