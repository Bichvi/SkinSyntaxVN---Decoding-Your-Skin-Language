<?php
/**
 * TuongTac.php
 * Model for logging and managing unified user interactions in collection tuong_tac_nguoi_dung
 */

class TuongTac {
    private $db;

    public const TYPE_VIEW = 'view';
    public const TYPE_SEARCH = 'search';
    public const TYPE_SEARCH_CLICK = 'search_click';
    public const TYPE_ADD_TO_CART = 'add_to_cart';
    public const TYPE_CART_REMOVE = 'cart_remove';
    public const TYPE_PURCHASE = 'purchase';

    public const WEIGHTS = [
        self::TYPE_VIEW => 1.0,
        self::TYPE_SEARCH => 0.0,
        self::TYPE_SEARCH_CLICK => 1.5,
        self::TYPE_ADD_TO_CART => 3.0,
        self::TYPE_CART_REMOVE => 0.0,
        self::TYPE_PURCHASE => 5.0,
    ];

    /**
     * Anti-spam backend engineering heuristic window:
     * Same user/session viewing the same product within 30 minutes will not log duplicate view events.
     */
    public const VIEW_DEDUP_WINDOW_SECONDS = 1800;

    public function __construct($db = null) {
        if ($db === null) {
            global $db;
            $this->db = $db;
        } else {
            $this->db = $db;
        }
    }

    private function getNextNumericId(string $collection, string $column): int {
        $lastDoc = $this->db->{$collection}->findOne([], ['sort' => [$column => -1]]);
        return $lastDoc ? (int)$lastDoc[$column] + 1 : 1;
    }

    /**
     * Check if a view event for this product by this user/session is throttled within 30 minutes
     */
    public function isViewThrottled(string|int $productId, ?int $userId = null, ?string $sessionId = null): bool {
        if (!$this->db) return false;
        $pidInt = is_numeric($productId) ? (int)$productId : null;
        $pidStr = (string)$productId;
        $pids = $pidInt !== null ? array_values(array_unique([$pidInt, $pidStr])) : [$pidStr];
        $windowStart = new \MongoDB\BSON\UTCDateTime((time() - self::VIEW_DEDUP_WINDOW_SECONDS) * 1000);

        $filter = [
            'loai_tuong_tac' => self::TYPE_VIEW,
            'ma_san_pham' => ['$in' => $pids],
            'created_at' => ['$gte' => $windowStart],
        ];

        if ($userId && $userId > 0) {
            $filter['ma_kh'] = (int)$userId;
        } elseif ($sessionId) {
            $filter['session_id'] = (string)$sessionId;
        } else {
            return false;
        }

        try {
            $existing = $this->db->tuong_tac_nguoi_dung->findOne($filter, ['projection' => ['_id' => 1]]);
            return $existing !== null;
        } catch (\Throwable $e) {
            error_log('isViewThrottled error: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Log user interaction
     */
    public function logInteraction(
        string $type,
        string|int|null $productId,
        ?int $userId = null,
        ?string $sessionId = null,
        array $metadata = []
    ): bool {
        if (!$this->db) return false;

        $type = trim($type);
        if (!isset(self::WEIGHTS[$type])) {
            $type = self::TYPE_VIEW;
        }

        $sid = $sessionId ?: (session_id() ?: 'guest_' . bin2hex(random_bytes(8)));
        $uid = $userId && $userId > 0 ? (int)$userId : null;
        $pid = $productId !== null ? (is_numeric($productId) ? (int)$productId : (string)$productId) : null;

        // View deduplication / throttle (Requirement 6)
        if ($type === self::TYPE_VIEW && $pid !== null) {
            if ($this->isViewThrottled($pid, $uid, $sid)) {
                return false; // Throttled: duplicate view within 30 min window
            }
        }

        // Purchase idempotency (Requirement 9)
        if ($type === self::TYPE_PURCHASE && !empty($metadata['order_id']) && $pid !== null) {
            try {
                $pidInt = is_numeric($pid) ? (int)$pid : null;
                $pidStr = (string)$pid;
                $pids = $pidInt !== null ? array_values(array_unique([$pidInt, $pidStr])) : [$pidStr];
                $existingOrderLog = $this->db->tuong_tac_nguoi_dung->findOne([
                    'loai_tuong_tac' => self::TYPE_PURCHASE,
                    'metadata.order_id' => (int)$metadata['order_id'],
                    'ma_san_pham' => ['$in' => $pids],
                ], ['projection' => ['_id' => 1]]);
                if ($existingOrderLog !== null) {
                    return false; // Idempotent skip: purchase already logged for this order item
                }
            } catch (\Throwable $e) {
                error_log('Purchase idempotency check error: ' . $e->getMessage());
            }
        }

        $weight = self::WEIGHTS[$type] ?? 1.0;
        $source = (string)($metadata['source'] ?? 'organic');

        $doc = [
            'ma_tuong_tac' => $this->getNextNumericId('tuong_tac_nguoi_dung', 'ma_tuong_tac'),
            'ma_kh' => $uid,
            'session_id' => (string)$sid,
            'ma_san_pham' => $pid,
            'loai_tuong_tac' => $type,
            'trong_so' => (float)$weight,
            'source' => $source,
            'metadata' => $metadata,
            'created_at' => new \MongoDB\BSON\UTCDateTime(),
        ];

        try {
            $this->db->tuong_tac_nguoi_dung->insertOne($doc);
            return true;
        } catch (\Throwable $e) {
            error_log('logInteraction error: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Get interactions by user ID or session ID
     */
    public function getInteractions(?int $userId = null, ?string $sessionId = null, int $limit = 100): array {
        if (!$this->db) return [];
        $filter = [];
        if ($userId && $userId > 0) {
            $filter['ma_kh'] = (int)$userId;
        } elseif ($sessionId) {
            $filter['session_id'] = (string)$sessionId;
        }
        try {
            $cursor = $this->db->tuong_tac_nguoi_dung->find($filter, [
                'limit' => $limit,
                'sort' => ['created_at' => -1]
            ]);
            return iterator_to_array($cursor);
        } catch (\Throwable $e) {
            error_log('getInteractions error: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Get interactions for a session
     */
    public function getSessionInteractions(string $sessionId, int $limit = 100): array {
        return $this->getInteractions(null, $sessionId, $limit);
    }

    /**
     * Get interactions for building CF matrix
     */
    public function getAllInteractions(int $limit = 50000): array {
        if (!$this->db) return [];
        try {
            $cursor = $this->db->tuong_tac_nguoi_dung->find([], [
                'limit' => $limit,
                'sort' => ['created_at' => -1]
            ]);
            return iterator_to_array($cursor);
        } catch (\Throwable $e) {
            error_log('getAllInteractions error: ' . $e->getMessage());
            return [];
        }
    }
}
