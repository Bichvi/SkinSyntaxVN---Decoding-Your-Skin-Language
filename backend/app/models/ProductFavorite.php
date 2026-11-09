<?php
// backend/app/models/ProductFavorite.php
declare(strict_types=1);

/**
 * Persistent product favorites keyed to the authenticated account.
 *
 * Favorites are intentionally stored in their own collection so they do not
 * mutate the existing product or customer documents. MongoDB creates the
 * collection on the first successful save; no relational migration is needed.
 */
class ProductFavorite {
    private const COLLECTION = 'product_favorites';
    private $db;
    private static bool $indexesEnsured = false;

    public function __construct($db) {
        $this->db = $db;
    }

    private function collection() {
        $collection = $this->db->{self::COLLECTION};
        if (!self::$indexesEnsured) {
            try {
                $collection->createIndex(
                    ['account_key' => 1, 'product_id' => 1],
                    ['unique' => true, 'name' => 'product_favorites_account_product']
                );
                $collection->createIndex(
                    ['account_key' => 1, 'saved_at' => -1],
                    ['name' => 'product_favorites_account_saved_at']
                );
                self::$indexesEnsured = true;
            } catch (Throwable $e) {
                // A missing index must not make the storefront unavailable.
                error_log('ProductFavorite index setup skipped: ' . $e->getMessage());
            }
        }
        return $collection;
    }

    public function idsForAccount(string $accountKey, int $limit = 200): array {
        $accountKey = trim($accountKey);
        if ($accountKey === '') {
            return [];
        }

        $ids = [];
        foreach ($this->collection()->find(
            ['account_key' => $accountKey],
            [
                'sort' => ['saved_at' => -1],
                'limit' => max(1, min(500, $limit)),
                'projection' => ['product_id' => 1],
            ]
        ) as $row) {
            $id = trim((string)($row['product_id'] ?? ''));
            if ($id !== '' && !in_array($id, $ids, true)) {
                $ids[] = $id;
            }
        }
        return $ids;
    }

    /** @return array{favorite: bool, product_id: string} */
    public function toggle(string $accountKey, string $productId): array {
        $accountKey = trim($accountKey);
        $productId = trim($productId);
        if ($accountKey === '' || $productId === '') {
            throw new InvalidArgumentException('Thiếu thông tin tài khoản hoặc sản phẩm.');
        }

        $collection = $this->collection();
        $filter = ['account_key' => $accountKey, 'product_id' => $productId];
        $existing = $collection->findOne($filter, ['projection' => ['_id' => 1]]);
        if ($existing !== null) {
            $collection->deleteOne($filter);
            return ['favorite' => false, 'product_id' => $productId];
        }

        try {
            $collection->insertOne([
                'account_key' => $accountKey,
                'product_id' => $productId,
                'saved_at' => new \MongoDB\BSON\UTCDateTime(),
            ]);
        } catch (Throwable $e) {
            // A concurrent double click can race the unique index. Treat an
            // already-created row as saved instead of reporting a false error.
            $existingAfterRace = $collection->findOne($filter, ['projection' => ['_id' => 1]]);
            if ($existingAfterRace === null) {
                throw $e;
            }
        }

        return ['favorite' => true, 'product_id' => $productId];
    }
}
