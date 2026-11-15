<?php
// backend/app/models/VideoWatchDaily.php

/**
 * Số giây xem video mỗi ngày (theo giờ VN) của từng tài khoản.
 * Giây cộng thêm bị kẹp theo thời gian thực (RewardRules::clampCredit).
 */
class VideoWatchDaily
{
    public const COLLECTION = 'video_watch_daily';

    private $col;

    public function __construct($db, bool $ensureIndexes = true)
    {
        if (is_object($db) && method_exists($db, 'raw')) {
            $db = $db->raw();
        }
        $name = self::COLLECTION;
        $this->col = $db->$name;
        if (!$ensureIndexes) {
            return;
        }
        try {
            $this->col->createIndex(['customer_id' => 1, 'date_vn' => 1], ['unique' => true]);
        } catch (\Throwable $e) {
            error_log('VideoWatchDaily ensureIndexes failed: ' . $e->getCode());
        }
    }

    /** Trả về số giây thực sự được cộng. */
    public function credit(string $customerId, string $dateVn, float $delta, float $now): float
    {
        $filter = ['customer_id' => $customerId, 'date_vn' => $dateVn];
        $doc = $this->col->findOne($filter);
        $last = ($doc !== null && isset($doc['last_credit_at'])) ? (float) $doc['last_credit_at'] : null;
        $granted = RewardRules::clampCredit($delta, $now, $last);
        if ($granted > 0) {
            $this->col->updateOne(
                $filter,
                ['$inc' => ['seconds' => $granted], '$set' => ['last_credit_at' => $now]],
                ['upsert' => true]
            );
        }
        return $granted;
    }

    public function secondsToday(string $customerId, string $dateVn): int
    {
        $doc = $this->col->findOne(['customer_id' => $customerId, 'date_vn' => $dateVn]);
        return $doc === null ? 0 : (int) floor((float) ($doc['seconds'] ?? 0));
    }
}
