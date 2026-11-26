<?php
declare(strict_types=1);

/** Opaque mobile bearer sessions. Only a SHA-256 digest is persisted. */
final class MobileSessionStore
{
    public const TTL_SECONDS = 2592000; // 30 days
    private $sessions;

    public function __construct($db)
    {
        $this->sessions = $db->mobile_sessions;
    }

    /** @return array{token:string,expires_at:int} */
    public function issue(int $customerId): array
    {
        if ($customerId <= 0) {
            throw new InvalidArgumentException('Customer id is required.');
        }

        $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $expiresAt = time() + self::TTL_SECONDS;
        $this->sessions->insertOne([
            'customer_id' => $customerId,
            'token_hash' => hash('sha256', $token),
            'created_at' => new \MongoDB\BSON\UTCDateTime(),
            'expires_at' => new \MongoDB\BSON\UTCDateTime($expiresAt * 1000),
        ]);

        return ['token' => $token, 'expires_at' => $expiresAt];
    }

    public function customerIdForToken(string $token): ?int
    {
        if (!preg_match('/^[A-Za-z0-9_-]{40,64}$/D', $token)) {
            return null;
        }

        $row = $this->sessions->findOne([
            'token_hash' => hash('sha256', $token),
            'revoked_at' => ['$exists' => false],
        ]);
        if ($row === null) {
            return null;
        }

        $row = (array)$row;
        $expiresAt = $row['expires_at'] ?? null;
        if ($expiresAt instanceof \MongoDB\BSON\UTCDateTime) {
            $expiry = $expiresAt->toDateTime()->getTimestamp();
        } elseif (is_numeric($expiresAt)) {
            $expiry = (int)$expiresAt;
        } else {
            return null;
        }

        $customerId = (int)($row['customer_id'] ?? 0);
        return $expiry > time() && $customerId > 0 ? $customerId : null;
    }

    public function revoke(string $token): bool
    {
        if (!preg_match('/^[A-Za-z0-9_-]{40,64}$/D', $token)) {
            return false;
        }

        $filter = ['token_hash' => hash('sha256', $token)];
        $this->sessions->updateOne(
            $filter + ['revoked_at' => ['$exists' => false]],
            ['$set' => ['revoked_at' => new \MongoDB\BSON\UTCDateTime()]]
        );

        return $this->sessions->findOne($filter) !== null;
    }
}
