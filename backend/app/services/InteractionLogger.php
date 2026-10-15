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

    public static function logView(string|int $productId, array $metadata = []): bool {
        return self::log(TuongTac::TYPE_VIEW, $productId, null, null, $metadata);
    }

    public static function logSearch(string $query, array $metadata = []): bool {
        $cleanQuery = trim($query);
        if ($cleanQuery === '') return false;
        $meta = array_merge($metadata, ['query' => $cleanQuery]);
        return self::log(TuongTac::TYPE_SEARCH, null, null, null, $meta);
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
