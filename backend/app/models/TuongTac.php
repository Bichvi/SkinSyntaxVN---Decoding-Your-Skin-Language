<?php
/**
 * TuongTac.php
 * Model for logging and managing unified user interactions in collection tuong_tac_nguoi_dung
 */

class TuongTac {
    private $db;

    public const TYPE_VIEW = 'view';
    public const TYPE_SEARCH_CLICK = 'search_click';
    public const TYPE_ADD_TO_CART = 'add_to_cart';
    public const TYPE_PURCHASE = 'purchase';

    public const WEIGHTS = [
        self::TYPE_VIEW => 1.0,
        self::TYPE_SEARCH_CLICK => 1.5,
        self::TYPE_ADD_TO_CART => 3.0,
        self::TYPE_PURCHASE => 5.0,
    ];

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
     * Log user interaction
     */
    public function logInteraction(
        string $type,
        string|int $productId,
        ?int $userId = null,
        ?string $sessionId = null,
        array $metadata = []
    ): bool {
        if (!$this->db) return false;

        $type = trim($type);
        if (!isset(self::WEIGHTS[$type])) {
            $type = self::TYPE_VIEW;
        }

        $pid = is_numeric($productId) ? (int)$productId : (string)$productId;
        $sid = $sessionId ?: (session_id() ?: 'guest_' . bin2hex(random_bytes(8)));
        $weight = self::WEIGHTS[$type] ?? 1.0;

        $doc = [
            'ma_tuong_tac' => $this->getNextNumericId('tuong_tac_nguoi_dung', 'ma_tuong_tac'),
            'ma_kh' => $userId && $userId > 0 ? (int)$userId : null,
            'session_id' => (string)$sid,
            'ma_san_pham' => $pid,
            'loai_tuong_tac' => $type,
            'trong_so' => (float)$weight,
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
