<?php
// backend/app/models/RewardLedger.php

/**
 * Sổ xu thưởng (video, điểm danh, mốc xem). Mỗi source_key chỉ ghi một lần;
 * tổng xu mỗi ngày bị chặn ở RewardRules::DAILY_CAP_XU.
 */
class RewardLedger
{
    public const COLLECTION = 'reward_xu_ledger';
    private const SOURCES = ['video', 'checkin', 'watch_milestone'];

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
            $this->col->createIndex(['source_key' => 1], ['unique' => true]);
            $this->col->createIndex(['ma_kh' => 1, 'date_vn' => 1]);
        } catch (\Throwable $e) {
            error_log('RewardLedger ensureIndexes failed: ' . $e->getCode());
        }
    }

    public function award(array $entry): array
    {
        $sourceKey = (string) ($entry['source_key'] ?? '');
        $amount = (int) ($entry['amount_xu'] ?? 0);
        $maKh = (int) ($entry['ma_kh'] ?? 0);
        $dateVn = (string) ($entry['date_vn'] ?? '');
        $source = (string) ($entry['source'] ?? '');
        if ($sourceKey === '' || $amount <= 0 || $maKh <= 0 || $dateVn === ''
            || !in_array($source, self::SOURCES, true)) {
            return ['ok' => false, 'code' => 'INVALID', 'xu_awarded' => 0];
        }
        if ($this->hasKey($sourceKey)) {
            return ['ok' => false, 'code' => 'DUPLICATE', 'xu_awarded' => 0];
        }
        $grant = RewardRules::trimToCap($amount, $this->sumXu($maKh, $dateVn));
        if ($grant <= 0) {
            return ['ok' => false, 'code' => 'DAILY_CAP_REACHED', 'xu_awarded' => 0];
        }
        try {
            $this->col->insertOne([
                'customer_id' => (string) ($entry['customer_id'] ?? ''),
                'ma_kh' => $maKh,
                'source' => $source,
                'source_key' => $sourceKey,
                'amount_xu' => $grant,
                'date_vn' => $dateVn,
                'meta' => is_array($entry['meta'] ?? null) ? $entry['meta'] : [],
                'created_at' => self::now(),
            ]);
        } catch (\Throwable $e) {
            if (self::isDuplicateKey($e)) {
                return ['ok' => false, 'code' => 'DUPLICATE', 'xu_awarded' => 0];
            }
            throw $e;
        }
        return ['ok' => true, 'code' => 'OK', 'xu_awarded' => $grant];
    }

    public function sumXu(int $maKh, ?string $dateVn = null): int
    {
        $filter = ['ma_kh' => $maKh];
        if ($dateVn !== null) {
            $filter['date_vn'] = $dateVn;
        }
        $total = 0;
        foreach ($this->col->find($filter) as $doc) {
            $total += (int) ($doc['amount_xu'] ?? 0);
        }
        return $total;
    }

    public function lastCheckin(int $maKh): ?array
    {
        $doc = $this->col->findOne(
            ['ma_kh' => $maKh, 'source' => 'checkin'],
            ['sort' => ['date_vn' => -1]]
        );
        return $doc === null ? null : self::toArray($doc);
    }

    public function history(int $maKh, int $limit = 30): array
    {
        $rows = [];
        foreach ($this->col->find(['ma_kh' => $maKh], ['sort' => ['created_at' => -1], 'limit' => $limit]) as $doc) {
            $rows[] = self::toArray($doc);
        }
        return $rows;
    }

    public function hasKey(string $sourceKey): bool
    {
        return $this->col->findOne(['source_key' => $sourceKey]) !== null;
    }

    private static function isDuplicateKey(\Throwable $e): bool
    {
        return (int) $e->getCode() === 11000 || strpos($e->getMessage(), 'E11000') !== false;
    }

    private static function now()
    {
        return class_exists('MongoDB\\BSON\\UTCDateTime') ? new \MongoDB\BSON\UTCDateTime() : time();
    }

    private static function toArray($doc): array
    {
        if (is_array($doc)) {
            return $doc;
        }
        if ($doc instanceof \MongoDB\Model\BSONDocument) {
            return $doc->getArrayCopy();
        }
        return (array) $doc;
    }
}
