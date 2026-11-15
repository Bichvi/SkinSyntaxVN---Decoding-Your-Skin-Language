<?php
// backend/app/models/RewardCheckin.php

/**
 * Chuỗi điểm danh (mỗi tài khoản một dòng mỗi ngày VN). Là nguồn sự thật của chuỗi 7 ngày,
 * độc lập với sổ xu để trần 300 xu/ngày không làm mất chuỗi.
 */
class RewardCheckin
{
    public const COLLECTION = 'reward_checkins';

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
            $this->col->createIndex(['ma_kh' => 1, 'date_vn' => 1], ['unique' => true]);
        } catch (\Throwable $e) {
            error_log('RewardCheckin ensureIndexes failed: ' . $e->getCode());
        }
    }

    /** @return bool true nếu ghi mới, false nếu hôm nay đã điểm danh (trùng khóa). */
    public function insert(string $customerId, int $maKh, string $dateVn, int $streakDay, int $xuAwarded): bool
    {
        try {
            $this->col->insertOne([
                'customer_id' => $customerId,
                'ma_kh' => $maKh,
                'date_vn' => $dateVn,
                'streak_day' => $streakDay,
                'xu_awarded' => $xuAwarded,
                'created_at' => class_exists('MongoDB\BSON\UTCDateTime') ? new \MongoDB\BSON\UTCDateTime() : time(),
            ]);
        } catch (\Throwable $e) {
            if ((int) $e->getCode() === 11000 || strpos($e->getMessage(), 'E11000') !== false) {
                return false;
            }
            throw $e;
        }
        return true;
    }

    public function setXuAwarded(int $maKh, string $dateVn, int $xu): void
    {
        $this->col->updateOne(['ma_kh' => $maKh, 'date_vn' => $dateVn], ['$set' => ['xu_awarded' => $xu]]);
    }

    /** Gỡ dòng điểm danh của một ngày (dùng khi ghi sổ xu thất bại tạm thời). */
    public function delete(int $maKh, string $dateVn): void
    {
        $this->col->deleteOne(['ma_kh' => $maKh, 'date_vn' => $dateVn]);
    }

    /** Dòng điểm danh mới nhất (theo date_vn) hoặc null. */
    public function last(int $maKh): ?array
    {
        $doc = $this->col->findOne(['ma_kh' => $maKh], ['sort' => ['date_vn' => -1]]);
        if ($doc === null) {
            return null;
        }
        if ($doc instanceof \MongoDB\Model\BSONDocument) {
            return $doc->getArrayCopy();
        }
        return (array) $doc;
    }
}
