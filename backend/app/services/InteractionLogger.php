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
        string|int $productId,
        ?int $userId = null,
        ?string $sessionId = null,
        array $metadata = []
    ): void {
        try {
            if ($userId === null && isset($_SESSION['user']['ma_kh'])) {
                $userId = (int)$_SESSION['user']['ma_kh'];
            }
            if ($sessionId === null && session_status() === PHP_SESSION_ACTIVE) {
                $sessionId = session_id();
            }
            self::getModel()->logInteraction($type, $productId, $userId, $sessionId, $metadata);
        } catch (\Throwable $e) {
            // Fail silently so logging never breaks core user operations
            error_log('InteractionLogger::log error: ' . $e->getMessage());
        }
    }

    public static function logView(string|int $productId, array $metadata = []): void {
        self::log(TuongTac::TYPE_VIEW, $productId, null, null, $metadata);
    }

    public static function logCart(string|int $productId, int $qty = 1): void {
        self::log(TuongTac::TYPE_ADD_TO_CART, $productId, null, null, ['quantity' => $qty]);
    }

    public static function logPurchase(string|int $productId, ?int $userId, int $orderId, int $qty = 1): void {
        self::log(TuongTac::TYPE_PURCHASE, $productId, $userId, null, ['order_id' => $orderId, 'quantity' => $qty]);
    }

    public static function logSearchClick(string|int $productId, string $query): void {
        self::log(TuongTac::TYPE_SEARCH_CLICK, $productId, null, null, ['query' => $query]);
    }
}
