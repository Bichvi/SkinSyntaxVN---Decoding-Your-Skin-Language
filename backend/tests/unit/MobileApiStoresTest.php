<?php
declare(strict_types=1);

require_once __DIR__ . '/../../app/services/MobileSessionStore.php';
require_once __DIR__ . '/../../app/services/CustomerCartStore.php';

final class MobileStoreFakeResult
{
    public function __construct(private int $matchedCount) {}
    public function getMatchedCount(): int { return $this->matchedCount; }
}

final class MobileStoreFakeDb
{
    private array $collections = [];
    public function __get(string $name): MobileStoreFakeCollection
    {
        return $this->collections[$name] ??= new MobileStoreFakeCollection();
    }
}

final class MobileStoreFakeCollection
{
    public array $docs = [];

    public function insertOne(array $document): object
    {
        if (isset($document['_id'])) {
            foreach ($this->docs as $existing) {
                if (($existing['_id'] ?? null) === $document['_id']) {
                    throw new RuntimeException('duplicate key');
                }
            }
        }
        $this->docs[] = $document;
        return (object)['insertedCount' => 1];
    }

    public function find(array $filter = [], array $options = []): ArrayIterator
    {
        return new ArrayIterator(array_values(array_filter($this->docs, fn(array $row): bool => $this->matches($row, $filter))));
    }

    public function findOne(array $filter, array $options = []): ?array
    {
        foreach ($this->docs as $row) if ($this->matches($row, $filter)) return $row;
        return null;
    }

    public function updateOne(array $filter, array $update, array $options = []): MobileStoreFakeResult
    {
        foreach ($this->docs as $index => $row) {
            if (!$this->matches($row, $filter)) continue;
            if (isset($update['$set'])) {
                foreach ($update['$set'] as $key => $value) $this->setPath($row, $key, $value);
            }
            if (isset($update['$inc'])) {
                foreach ($update['$inc'] as $key => $value) {
                    if ($key === 'revision') $row[$key] = (int)($row[$key] ?? 0) + (int)$value;
                    elseif (preg_match('/^items\.\$\.quantity$/', $key)) {
                        // The current test exercises idempotent add of one new product only.
                        throw new RuntimeException('unexpected positional increment');
                    }
                }
            }
            if (isset($update['$push']['items'])) $row['items'][] = $update['$push']['items'];
            if (isset($update['$pull']['items'])) {
                $target = (string)($update['$pull']['items']['product_id'] ?? '');
                $row['items'] = array_values(array_filter($row['items'] ?? [], static fn(array $item): bool => (string)($item['product_id'] ?? '') !== $target));
            }
            $this->docs[$index] = $row;
            return new MobileStoreFakeResult(1);
        }
        return new MobileStoreFakeResult(0);
    }

    private function matches(array $row, array $filter): bool
    {
        foreach ($filter as $key => $expected) {
            if ($key === 'items' && isset($expected['$elemMatch'])) {
                $found = false;
                foreach ($row['items'] ?? [] as $item) {
                    $sub = $expected['$elemMatch'];
                    if (($item['product_id'] ?? null) === ($sub['product_id'] ?? null)
                        && (int)($item['quantity'] ?? 0) <= (int)($sub['quantity']['$lte'] ?? PHP_INT_MAX)) $found = true;
                }
                if (!$found) return false;
                continue;
            }
            if ($key === 'items.product_id' && isset($expected['$ne'])) {
                foreach ($row['items'] ?? [] as $item) if (($item['product_id'] ?? null) === $expected['$ne']) return false;
                continue;
            }
            if (is_array($expected) && isset($expected['$exists'])) {
                if (array_key_exists($key, $row) !== (bool)$expected['$exists']) return false;
                continue;
            }
            if (($row[$key] ?? null) !== $expected) return false;
        }
        return true;
    }

    private function setPath(array &$row, string $path, mixed $value): void
    {
        $row[$path] = $value;
    }
}

function mobileStoreAssert(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

$db = new MobileStoreFakeDb();
$sessions = new MobileSessionStore($db);
$issued = $sessions->issue(42);
$stored = $db->mobile_sessions->findOne(['customer_id' => 42]);
mobileStoreAssert(strlen($issued['token']) === 43, 'Issued bearer tokens must contain 32 random bytes in base64url form.');
mobileStoreAssert($stored !== null && hash('sha256', $issued['token']) === $stored['token_hash'], 'Only a token digest must be persisted.');
mobileStoreAssert(!array_key_exists('token', $stored), 'Raw bearer tokens must never be stored.');
mobileStoreAssert($sessions->customerIdForToken($issued['token']) === 42, 'Valid tokens must resolve to their customer.');
mobileStoreAssert($sessions->revoke($issued['token']), 'Logout must revoke the issued token.');
mobileStoreAssert($sessions->customerIdForToken($issued['token']) === null, 'Revoked tokens must not authenticate.');

$cart = new CustomerCartStore($db);
$first = $cart->addOnce(42, 'sku-1', 2, 5, 'operation-12345');
$retry = $cart->addOnce(42, 'sku-1', 2, 5, 'operation-12345');
$snapshot = $cart->get(42);
mobileStoreAssert(!empty($first['ok']) && empty($first['duplicate']), 'First add must complete as a new operation.');
mobileStoreAssert(!empty($retry['ok']) && !empty($retry['duplicate']), 'Retry with the same idempotency key must return the stored outcome.');
mobileStoreAssert(($snapshot['items']['sku-1'] ?? 0) === 2, 'A retried add must not double the cart quantity.');

echo "MobileApiStoresTest: PASS (9 assertions)" . PHP_EOL;
