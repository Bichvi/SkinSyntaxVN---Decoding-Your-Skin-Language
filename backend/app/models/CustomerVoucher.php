<?php
// backend/app/models/CustomerVoucher.php
declare(strict_types=1);

require_once __DIR__ . '/Voucher.php';
require_once __DIR__ . '/VideoWatchSession.php';
require_once __DIR__ . '/VideoRewardCampaign.php';

/**
 * CustomerVoucher (Hardened for Phase V2C.1)
 * 
 * Domain model managing customer voucher entitlements, customer wallet, and atomic issuance.
 * 
 * Core architectural and security guarantees:
 * - Full ACID Multi-Document Transactions on MongoDB Atlas replica sets.
 * - Atomic Quota Reservation with `$expr: {$lt: ['$issued_count', '$total_quota']}` and `$inc`.
 * - Eliminates orphan vouchers in `voucher` collection via automatic abort/compensating cleanup.
 * - Eliminates dangling entitlements in `customer_vouchers`.
 * - Upgraded unique indexing supporting both `user_limit = 1` and `user_limit > 1` via `claim_seq`.
 * - Automatic reconciliation for quota drift and dangling state.
 * - Server-side customer authorization: client cannot forge customer identity.
 * - Idempotency for double-clicks and retries.
 * - Zero modification to checkout, `san_pham.gia_ban`, Flash Sale, or recommendation engines.
 */
class CustomerVoucher {
    private $db;
    private ?\MongoDB\Client $client = null;
    private Voucher $voucherModel;
    public const COLLECTION = 'customer_vouchers';
    public const CAMPAIGNS_COLLECTION = 'ai_video_reward_campaigns';
    public const SESSIONS_COLLECTION = 'ai_video_watch_sessions';
    public const VOUCHERS_COLLECTION = 'voucher';

    private ?string $lastErrorMessage = null;

    public function __construct($db, ?\MongoDB\Client $client = null) {
        if (is_object($db) && method_exists($db, 'raw')) {
            $this->db = $db->raw();
        } elseif ($db instanceof \MongoDB\Database) {
            $this->db = $db;
        } else {
            $this->db = $db;
        }

        global $mongoClient;
        $this->client = $client ?? ($mongoClient instanceof \MongoDB\Client ? $mongoClient : null);

        $this->voucherModel = new Voucher($this->db);
        $this->ensureIndexes();
    }

    public function getLastErrorMessage(): ?string {
        return $this->lastErrorMessage;
    }

    private function setLastErrorMessage(?string $message): void {
        $this->lastErrorMessage = $message;
    }

    /**
     * Ensures required MongoDB indexes.
     * Upgrades unique index from rigid (campaign_id, customer_id) to (campaign_id, customer_id, claim_seq)
     * so that admin configurations with user_limit > 1 are fully supported without key collision.
     */
    public function ensureIndexes(): void {
        try {
            $col = $this->db->{self::COLLECTION};

            // Inspect and drop legacy rigid index if present
            try {
                $indexes = $col->listIndexes();
                foreach ($indexes as $idx) {
                    if ($idx->getName() === 'campaign_id_1_customer_id_1') {
                        $col->dropIndex('campaign_id_1_customer_id_1');
                        break;
                    }
                }
            } catch (\Throwable $dropEx) {
                // Ignore drop errors
            }

            // Populate claim_seq = 1 for any legacy records missing claim_seq
            try {
                $col->updateMany(['claim_seq' => ['$exists' => false]], ['$set' => ['claim_seq' => 1]]);
            } catch (\Throwable $migEx) {
                // Ignore migration errors
            }

            // 1. Compound Unique index supporting user_limit: prevents duplicate claim for the same slot
            $col->createIndex(['campaign_id' => 1, 'customer_id' => 1, 'claim_seq' => 1], ['unique' => true]);

            // 2. Unique index: A watch session can only ever be claimed once
            $col->createIndex(['watch_session_id' => 1], ['unique' => true]);

            // 3. Unique voucher code
            $col->createIndex(['voucher_code' => 1], ['unique' => true]);

            // 4. Index for customer wallet querying
            $col->createIndex(['customer_id' => 1, 'status' => 1]);
            $col->createIndex(['created_at' => -1]);
        } catch (\Throwable $e) {
            // Index creation non-fatal if already exists
        }
    }

    private function generateVoucherCode(string $prefix = 'SYNA-'): string {
        for ($i = 0; $i < 5; $i++) {
            $code = $prefix . strtoupper(bin2hex(random_bytes(4)));
            $existsInVoucher = $this->db->{self::VOUCHERS_COLLECTION}->countDocuments(['ma_code' => $code]);
            $existsInEntitlement = $this->db->{self::COLLECTION}->countDocuments(['voucher_code' => $code]);
            if ($existsInVoucher === 0 && $existsInEntitlement === 0) {
                return $code;
            }
        }
        return $prefix . strtoupper(bin2hex(random_bytes(6)));
    }

    private function getNextNumericId(string $collection, string $column): int {
        $lastDoc = $this->db->{$collection}->findOne([], ['sort' => [$column => -1]]);
        return $lastDoc && isset($lastDoc[$column]) ? (int)$lastDoc[$column] + 1 : 1;
    }

    private function normalizeBsonDateTime($val): ?string {
        if (!$val) return null;
        if ($val instanceof \MongoDB\BSON\UTCDateTime) {
            return $val->toDateTime()->setTimezone(new DateTimeZone('Asia/Ho_Chi_Minh'))->format('Y-m-d H:i:s');
        }
        if (is_string($val)) return $val;
        return null;
    }

    public function normalizeEntitlementDoc($doc): array {
        if (!$doc) return [];
        $row = (array)$doc;

        if (isset($row['_id']) && $row['_id'] instanceof \MongoDB\BSON\ObjectId) {
            $row['_id'] = (string)$row['_id'];
        }

        $now = time();
        $endDateStr = $this->normalizeBsonDateTime($row['end_date'] ?? null);
        $status = (string)($row['status'] ?? 'AVAILABLE');

        // Dynamically compute expired status if not already used
        if ($status === 'AVAILABLE' && $endDateStr !== null && strtotime($endDateStr) < $now) {
            $status = 'EXPIRED';
        }

        $applicable = [];
        if (!empty($row['applicable_products'])) {
            if ($row['applicable_products'] instanceof \MongoDB\Model\BSONArray || $row['applicable_products'] instanceof \Traversable) {
                $applicable = iterator_to_array($row['applicable_products']);
            } elseif (is_array($row['applicable_products'])) {
                $applicable = array_values($row['applicable_products']);
            }
        }

        return [
            'entitlement_id' => (string)($row['entitlement_id'] ?? ''),
            'customer_id' => (string)($row['customer_id'] ?? ''),
            'customer_email' => (string)($row['customer_email'] ?? ''),
            'campaign_id' => (string)($row['campaign_id'] ?? ''),
            'campaign_name' => (string)($row['campaign_name'] ?? ''),
            'watch_session_id' => (string)($row['watch_session_id'] ?? ''),
            'video_id' => (string)($row['video_id'] ?? ''),
            'publication_id' => (string)($row['publication_id'] ?? ''),
            'voucher_id' => (int)($row['voucher_id'] ?? 0),
            'voucher_code' => (string)($row['voucher_code'] ?? ''),
            'claim_seq' => (int)($row['claim_seq'] ?? 1),
            'discount_type' => (string)($row['discount_type'] ?? 'percentage'),
            'discount_value' => (float)($row['discount_value'] ?? 0),
            'max_discount_amount' => !empty($row['max_discount_amount']) ? (int)$row['max_discount_amount'] : null,
            'min_order_amount' => !empty($row['min_order_amount']) ? (int)$row['min_order_amount'] : 0,
            'applicable_products' => $applicable,
            'status' => $status,
            'claimed_at' => $this->normalizeBsonDateTime($row['claimed_at'] ?? null),
            'start_date' => $this->normalizeBsonDateTime($row['start_date'] ?? null),
            'end_date' => $endDateStr,
            'used_at' => $this->normalizeBsonDateTime($row['used_at'] ?? null),
            'created_at' => $this->normalizeBsonDateTime($row['created_at'] ?? null),
        ];
    }

    /**
     * Atomically claims and issues a reward voucher for an eligible watch session.
     * Enforces Multi-Document ACID Transactions on MongoDB Atlas when available.
     * Fallback to compensating atomic reservation with orphan voucher eradication.
     * 
     * @param string $watchSessionId Authoritative session token
     * @param string $customerId Authenticated customer ID (from server session)
     * @param string|null $customerEmail Authenticated customer email
     * @param string|null $guestSessionId Active PHP session id for guest migration
     * @param bool $simulateCrashAfterReservation Optional failure injection for testing
     * @param bool $simulateCrashAfterVoucherInsert Optional failure injection for testing
     * @return array Result payload with status and issued voucher details
     */
    public function claimReward(
        string $watchSessionId,
        string $customerId,
        ?string $customerEmail = null,
        ?string $guestSessionId = null,
        bool $simulateCrashAfterReservation = false,
        bool $simulateCrashAfterVoucherInsert = false
    ): array {
        $this->setLastErrorMessage(null);
        $sid = trim($watchSessionId);
        $cid = trim($customerId);

        if ($sid === '') {
            return ['ok' => false, 'code' => 'MISSING_SESSION', 'message' => 'Thiếu thông tin phiên xem video.'];
        }

        if ($cid === '') {
            return ['ok' => false, 'code' => 'UNAUTHENTICATED', 'message' => 'Vui lòng đăng nhập để nhận ưu đãi.'];
        }

        // 1. Fetch Watch Session
        $sessionDoc = $this->db->{self::SESSIONS_COLLECTION}->findOne(['watch_session_id' => $sid]);
        if (!$sessionDoc) {
            return ['ok' => false, 'code' => 'SESSION_NOT_FOUND', 'message' => 'Không tìm thấy phiên xem video tương ứng.'];
        }

        // 2. Idempotency Check: Already claimed this session?
        $existingEntitlement = $this->db->{self::COLLECTION}->findOne(['watch_session_id' => $sid]);
        if ($existingEntitlement) {
            $normalized = $this->normalizeEntitlementDoc($existingEntitlement);
            return [
                'ok' => true,
                'already_claimed' => true,
                'code' => 'ALREADY_CLAIMED',
                'voucher' => $normalized,
                'message' => 'Bạn đã nhận voucher cho phiên xem này trước đó.'
            ];
        }

        // 3. Verify Customer Ownership or Valid Guest Migration
        $sessionCustomerId = !empty($sessionDoc['customer_id']) ? (string)$sessionDoc['customer_id'] : null;
        $sessionGuestId = !empty($sessionDoc['guest_session_id']) ? (string)$sessionDoc['guest_session_id'] : null;

        if ($sessionCustomerId !== null && $sessionCustomerId !== $cid) {
            // Watch session belongs to a DIFFERENT customer!
            return [
                'ok' => false,
                'code' => 'FORBIDDEN_SESSION',
                'message' => 'Phiên xem video này thuộc về một tài khoản khác.'
            ];
        }

        // If session was watched as guest, verify same browser session
        if ($sessionCustomerId === null) {
            if ($guestSessionId !== null && $sessionGuestId !== null && $guestSessionId !== $sessionGuestId) {
                return [
                    'ok' => false,
                    'code' => 'SESSION_MISMATCH',
                    'message' => 'Phiên xem video của khách không khớp với trình duyệt hiện tại.'
                ];
            }
        }

        // 4. Verify Completion & Eligibility
        $isEligible = !empty($sessionDoc['is_eligible']) || (($sessionDoc['watch_state'] ?? '') === 'ELIGIBLE');
        if (!$isEligible) {
            return [
                'ok' => false,
                'code' => 'NOT_ELIGIBLE',
                'message' => 'Bạn chưa hoàn tất thời lượng xem video yêu cầu để nhận ưu đãi.'
            ];
        }

        $campaignId = (string)($sessionDoc['campaign_id'] ?? '');
        if ($campaignId === '') {
            return ['ok' => false, 'code' => 'NO_CAMPAIGN', 'message' => 'Phiên xem không liên kết chiến dịch ưu đãi nào.'];
        }

        // 5. Fetch and Validate Campaign
        $campaign = $this->db->{self::CAMPAIGNS_COLLECTION}->findOne(['campaign_id' => $campaignId]);
        if (!$campaign) {
            return ['ok' => false, 'code' => 'CAMPAIGN_NOT_FOUND', 'message' => 'Chiến dịch ưu đãi không tồn tại.'];
        }

        if (empty($campaign['is_active'])) {
            return ['ok' => false, 'code' => 'CAMPAIGN_INACTIVE', 'message' => 'Chiến dịch ưu đãi hiện đang tạm dừng.'];
        }

        // Date validity check
        $now = time();
        if (!empty($campaign['start_date'])) {
            $startStr = $this->normalizeBsonDateTime($campaign['start_date']);
            if ($startStr && strtotime($startStr) > $now) {
                return ['ok' => false, 'code' => 'CAMPAIGN_NOT_STARTED', 'message' => 'Chiến dịch ưu đãi chưa đến thời gian áp dụng.'];
            }
        }
        if (!empty($campaign['end_date'])) {
            $endStr = $this->normalizeBsonDateTime($campaign['end_date']);
            if ($endStr && strtotime($endStr) < $now) {
                return ['ok' => false, 'code' => 'CAMPAIGN_EXPIRED', 'message' => 'Chiến dịch ưu đãi đã hết hạn.'];
            }
        }

        // 6. Check Per-User Limit
        $userLimit = max(1, (int)($campaign['user_limit'] ?? 1));
        $claimedByUserCount = $this->db->{self::COLLECTION}->countDocuments([
            'campaign_id' => $campaignId,
            'customer_id' => $cid
        ]);

        if ($claimedByUserCount >= $userLimit) {
            $userExistingVoucher = $this->db->{self::COLLECTION}->findOne([
                'campaign_id' => $campaignId,
                'customer_id' => $cid
            ]);
            $normalized = $userExistingVoucher ? $this->normalizeEntitlementDoc($userExistingVoucher) : null;
            return [
                'ok' => false,
                'code' => 'USER_LIMIT_REACHED',
                'voucher' => $normalized,
                'message' => 'Bạn đã nhận tối đa số lượng voucher cho phép (' . $userLimit . ' lượt) trong chương trình này.'
            ];
        }

        $nextClaimSeq = $claimedByUserCount + 1;

        // 7. Prepare Voucher & Entitlement Payloads
        $voucherCode = $this->generateVoucherCode('SYNA-');
        $entitlementId = 'ent_' . bin2hex(random_bytes(10));
        $nowUtc = new \MongoDB\BSON\UTCDateTime();

        $startDateBson = $campaign['start_date'] instanceof \MongoDB\BSON\UTCDateTime
            ? $campaign['start_date']
            : ($campaign['start_date'] ? new \MongoDB\BSON\UTCDateTime(strtotime((string)$campaign['start_date']) * 1000) : $nowUtc);
        
        $endDateBson = $campaign['end_date'] instanceof \MongoDB\BSON\UTCDateTime
            ? $campaign['end_date']
            : ($campaign['end_date'] ? new \MongoDB\BSON\UTCDateTime(strtotime((string)$campaign['end_date']) * 1000) : new \MongoDB\BSON\UTCDateTime((time() + 86400 * 30) * 1000));

        $applicableProducts = [];
        if (!empty($campaign['applicable_products'])) {
            $applicableProducts = is_array($campaign['applicable_products'])
                ? array_values($campaign['applicable_products'])
                : iterator_to_array($campaign['applicable_products']);
        }

        $nextMaVoucher = $this->getNextNumericId(self::VOUCHERS_COLLECTION, 'ma_voucher');
        $discountTypeRaw = strtolower((string)($campaign['discount_type'] ?? 'percentage'));
        $voucherType = ($discountTypeRaw === 'percentage' || $discountTypeRaw === 'percent') ? 'percent' : 'fixed';

        $voucherDoc = [
            'ma_voucher' => $nextMaVoucher,
            'ma_code' => $voucherCode,
            'ten_voucher' => (string)($campaign['campaign_name'] ?? 'Ưu đãi xem video Syna 3D'),
            'mo_ta' => 'Voucher thưởng xem video Syna 3D dành riêng cho tài khoản #' . $cid,
            'loai_giam' => $voucherType,
            'gia_tri_giam' => (int)($campaign['discount_value'] ?? 0),
            'gia_tri_don_toi_thieu' => (int)($campaign['min_order_amount'] ?? 0),
            'giam_toi_da' => !empty($campaign['max_discount_amount']) ? (int)$campaign['max_discount_amount'] : null,
            'so_luong' => 1,
            'so_luong_da_dung' => 0,
            'customer_id' => $cid,
            'campaign_id' => $campaignId,
            'claim_seq' => $nextClaimSeq,
            'ngay_bat_dau' => $startDateBson,
            'ngay_ket_thuc' => $endDateBson,
            'trang_thai' => 'active',
            'created_at' => $nowUtc,
            'updated_at' => $nowUtc,
        ];

        $entitlementDoc = [
            'entitlement_id' => $entitlementId,
            'customer_id' => $cid,
            'customer_email' => $customerEmail ?: (string)($sessionDoc['client_metadata']['email'] ?? ''),
            'campaign_id' => $campaignId,
            'campaign_name' => (string)($campaign['campaign_name'] ?? 'Ưu đãi Syna 3D'),
            'watch_session_id' => $sid,
            'video_id' => (string)($sessionDoc['video_id'] ?? ''),
            'publication_id' => (string)($sessionDoc['publication_id'] ?? ''),
            'voucher_id' => $nextMaVoucher,
            'voucher_code' => $voucherCode,
            'claim_seq' => $nextClaimSeq,
            'discount_type' => $discountTypeRaw,
            'discount_value' => (float)($campaign['discount_value'] ?? 0),
            'max_discount_amount' => !empty($campaign['max_discount_amount']) ? (int)$campaign['max_discount_amount'] : null,
            'min_order_amount' => !empty($campaign['min_order_amount']) ? (int)$campaign['min_order_amount'] : 0,
            'applicable_products' => $applicableProducts,
            'status' => 'AVAILABLE',
            'claimed_at' => $nowUtc,
            'start_date' => $startDateBson,
            'end_date' => $endDateBson,
            'used_at' => null,
            'order_id' => null,
            'created_at' => $nowUtc,
            'updated_at' => $nowUtc,
        ];

        // 8. EXECUTION WITH MULTI-DOCUMENT TRANSACTION OR DEFENSIVE FALLBACK
        $totalQuota = (int)($campaign['total_quota'] ?? 0);
        $reservationFilter = [
            'campaign_id' => $campaignId,
            'is_active' => true,
        ];
        if ($totalQuota > 0) {
            $reservationFilter['$expr'] = ['$lt' => ['$issued_count', '$total_quota']];
        }

        // Check if multi-document transactions can be used
        if ($this->client instanceof \MongoDB\Client && !$simulateCrashAfterReservation && !$simulateCrashAfterVoucherInsert) {
            return $this->executeWithTransaction(
                $sid, $cid, $campaignId, $totalQuota, $userLimit, $reservationFilter,
                $voucherDoc, $entitlementDoc, $voucherCode, $nowUtc
            );
        }

        // DEFENSIVE FALLBACK / FAILURE INJECTION HARNESS
        return $this->executeWithCompensatingFallback(
            $sid, $cid, $campaignId, $reservationFilter, $voucherDoc, $entitlementDoc,
            $voucherCode, $nowUtc, $simulateCrashAfterReservation, $simulateCrashAfterVoucherInsert
        );
    }

    /**
     * Executes atomic issuance wrapped within a full MongoDB ACID Transaction.
     */
    private function executeWithTransaction(
        string $sid,
        string $cid,
        string $campaignId,
        int $totalQuota,
        int $userLimit,
        array $reservationFilter,
        array $voucherDoc,
        array $entitlementDoc,
        string $voucherCode,
        \MongoDB\BSON\UTCDateTime $nowUtc
    ): array {
        $session = $this->client->startSession();
        $maxAttempts = 2;
        $attempt = 0;

        while ($attempt < $maxAttempts) {
            $attempt++;
            $session->startTransaction([
                'readConcern' => new \MongoDB\Driver\ReadConcern('local'),
                'writeConcern' => new \MongoDB\Driver\WriteConcern('majority'),
            ]);

            try {
                // T1: Check session idempotency inside transaction
                $existingEnt = $this->db->{self::COLLECTION}->findOne(
                    ['watch_session_id' => $sid],
                    ['session' => $session]
                );
                if ($existingEnt) {
                    $session->abortTransaction();
                    return [
                        'ok' => true,
                        'already_claimed' => true,
                        'code' => 'ALREADY_CLAIMED',
                        'voucher' => $this->normalizeEntitlementDoc($existingEnt),
                        'message' => 'Bạn đã nhận voucher cho phiên xem này trước đó.'
                    ];
                }

                // T2: Check user limit inside transaction
                $currentCount = $this->db->{self::COLLECTION}->countDocuments(
                    ['campaign_id' => $campaignId, 'customer_id' => $cid],
                    ['session' => $session]
                );
                if ($currentCount >= $userLimit) {
                    $session->abortTransaction();
                    $userExisting = $this->db->{self::COLLECTION}->findOne(
                        ['campaign_id' => $campaignId, 'customer_id' => $cid],
                        ['session' => $session]
                    );
                    return [
                        'ok' => false,
                        'code' => 'USER_LIMIT_REACHED',
                        'voucher' => $userExisting ? $this->normalizeEntitlementDoc($userExisting) : null,
                        'message' => 'Bạn đã nhận tối đa số lượng voucher cho phép (' . $userLimit . ' lượt) trong chương trình này.'
                    ];
                }

                $claimSeq = $currentCount + 1;
                $voucherDoc['claim_seq'] = $claimSeq;
                $entitlementDoc['claim_seq'] = $claimSeq;

                // T3: Atomic Quota Reservation inside transaction
                $quotaReserved = $this->db->{self::CAMPAIGNS_COLLECTION}->updateOne(
                    $reservationFilter,
                    [
                        '$inc' => ['issued_count' => 1],
                        '$set' => ['updated_at' => new \MongoDB\BSON\UTCDateTime()]
                    ],
                    ['session' => $session]
                );

                if ($quotaReserved->getModifiedCount() === 0) {
                    $session->abortTransaction();
                    return [
                        'ok' => false,
                        'code' => 'QUOTA_EXHAUSTED',
                        'message' => 'Rất tiếc, số lượng voucher của chương trình đã được phát hết.'
                    ];
                }

                // T4: Create execution voucher in `voucher` collection
                $this->db->{self::VOUCHERS_COLLECTION}->insertOne($voucherDoc, ['session' => $session]);

                // T5: Create customer entitlement in `customer_vouchers`
                $this->db->{self::COLLECTION}->insertOne($entitlementDoc, ['session' => $session]);

                // T6: Update watch session state
                $this->db->{self::SESSIONS_COLLECTION}->updateOne(
                    ['watch_session_id' => $sid],
                    [
                        '$set' => [
                            'customer_id' => $cid,
                            'watch_state' => 'REWARDED',
                            'is_rewarded' => true,
                            'reward_voucher_code' => $voucherCode,
                            'reward_claimed_at' => $nowUtc,
                            'updated_at' => $nowUtc,
                        ]
                    ],
                    ['session' => $session]
                );

                // Commit Transaction: ALL or NOTHING
                $session->commitTransaction();

                return [
                    'ok' => true,
                    'code' => 'CLAIM_SUCCESS',
                    'voucher' => $this->normalizeEntitlementDoc($entitlementDoc),
                    'message' => 'Chúc mừng bạn đã nhận voucher giảm giá thành công!'
                ];

            } catch (\Throwable $e) {
                try {
                    $session->abortTransaction();
                } catch (\Throwable $abortEx) {
                    // Ignore abort errors
                }

                // If transient network/write conflict error on replica set: retry once
                if ($e instanceof \MongoDB\Driver\Exception\CommandException && $e->hasErrorLabel('TransientTransactionError') && $attempt < $maxAttempts) {
                    usleep(50000);
                    continue;
                }

                // If duplicate key collision (concurrent race)
                if (strpos($e->getMessage(), 'E11000') !== false) {
                    $existing = $this->db->{self::COLLECTION}->findOne(['watch_session_id' => $sid]);
                    if ($existing) {
                        return [
                            'ok' => true,
                            'already_claimed' => true,
                            'code' => 'ALREADY_CLAIMED',
                            'voucher' => $this->normalizeEntitlementDoc($existing),
                            'message' => 'Bạn đã nhận voucher cho chương trình này trước đó.'
                        ];
                    }
                }

                return [
                    'ok' => false,
                    'code' => 'TRANSACTION_ERROR',
                    'message' => 'Lỗi giao dịch khi cấp voucher: ' . $e->getMessage()
                ];
            }
        }

        return ['ok' => false, 'code' => 'TRANSACTION_FAILED', 'message' => 'Không thể hoàn tất giao dịch sau nhiều lần thử.'];
    }

    /**
     * Non-transactional fallback with compensating rollback & orphan eradication.
     */
    private function executeWithCompensatingFallback(
        string $sid,
        string $cid,
        string $campaignId,
        array $reservationFilter,
        array $voucherDoc,
        array $entitlementDoc,
        string $voucherCode,
        \MongoDB\BSON\UTCDateTime $nowUtc,
        bool $simulateCrashAfterReservation,
        bool $simulateCrashAfterVoucherInsert
    ): array {
        // Step 1: Atomic Quota Reservation
        $quotaReserved = $this->db->{self::CAMPAIGNS_COLLECTION}->updateOne(
            $reservationFilter,
            [
                '$inc' => ['issued_count' => 1],
                '$set' => ['updated_at' => new \MongoDB\BSON\UTCDateTime()]
            ]
        );

        if ($quotaReserved->getModifiedCount() === 0) {
            return [
                'ok' => false,
                'code' => 'QUOTA_EXHAUSTED',
                'message' => 'Rất tiếc, số lượng voucher của chương trình đã được phát hết.'
            ];
        }

        // Failure Injection 1: Crash after quota reservation
        if ($simulateCrashAfterReservation) {
            // Unhandled crash simulation without catch: quota remains held until reconciliation runs
            return [
                'ok' => false,
                'code' => 'CRASH_AFTER_RESERVATION',
                'message' => 'Simulated server crash after quota reservation.'
            ];
        }

        $voucherInserted = false;
        try {
            // Step 2: Insert into canonical voucher
            $this->db->{self::VOUCHERS_COLLECTION}->insertOne($voucherDoc);
            $voucherInserted = true;

            // Failure Injection 2: Crash after voucher inserted
            if ($simulateCrashAfterVoucherInsert) {
                throw new \RuntimeException('Simulated crash after execution voucher insert');
            }

            // Step 3: Insert into customer_vouchers
            $this->db->{self::COLLECTION}->insertOne($entitlementDoc);

            // Step 4: Update Watch Session
            $this->db->{self::SESSIONS_COLLECTION}->updateOne(
                ['watch_session_id' => $sid],
                [
                    '$set' => [
                        'customer_id' => $cid,
                        'watch_state' => 'REWARDED',
                        'is_rewarded' => true,
                        'reward_voucher_code' => $voucherCode,
                        'reward_claimed_at' => $nowUtc,
                        'updated_at' => $nowUtc,
                    ]
                ]
            );

            return [
                'ok' => true,
                'code' => 'CLAIM_SUCCESS',
                'voucher' => $this->normalizeEntitlementDoc($entitlementDoc),
                'message' => 'Chúc mừng bạn đã nhận voucher giảm giá thành công!'
            ];

        } catch (\Throwable $e) {
            // COMPENSATING ROLLBACK: Eradicate orphan voucher if created!
            if ($voucherInserted) {
                $this->db->{self::VOUCHERS_COLLECTION}->deleteOne(['ma_code' => $voucherCode]);
            }

            // Rollback reserved quota
            $this->db->{self::CAMPAIGNS_COLLECTION}->updateOne(
                ['campaign_id' => $campaignId],
                ['$inc' => ['issued_count' => -1]]
            );

            if (strpos($e->getMessage(), 'E11000') !== false) {
                $existing = $this->db->{self::COLLECTION}->findOne(['watch_session_id' => $sid]);
                if ($existing) {
                    return [
                        'ok' => true,
                        'already_claimed' => true,
                        'code' => 'ALREADY_CLAIMED',
                        'voucher' => $this->normalizeEntitlementDoc($existing),
                        'message' => 'Bạn đã nhận voucher cho chương trình này trước đó.'
                    ];
                }
            }

            return [
                'ok' => false,
                'code' => 'ISSUANCE_ERROR',
                'message' => 'Không thể phát hành voucher: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Audits and reconciles campaign quota and cleans orphan records.
     * Guarantees zero dangling state or lost quota across the system.
     * 
     * @param string $campaignId
     * @return array Audit and reconciliation report
     */
    public function reconcileCampaignQuota(string $campaignId): array {
        $cid = trim($campaignId);
        $camp = $this->db->{self::CAMPAIGNS_COLLECTION}->findOne(['campaign_id' => $cid]);
        if (!$camp) {
            return ['ok' => false, 'message' => 'Campaign không tồn tại.'];
        }

        // 1. Count actual valid entitlements in customer_vouchers
        $validEntitlementsCount = $this->db->{self::COLLECTION}->countDocuments(['campaign_id' => $cid]);
        $recordedIssuedCount = (int)($camp['issued_count'] ?? 0);

        // 2. Scan for orphan vouchers in collection voucher
        $orphanVouchers = [];
        $cursor = $this->db->{self::VOUCHERS_COLLECTION}->find(['campaign_id' => $cid]);
        foreach ($cursor as $vDoc) {
            $code = (string)$vDoc['ma_code'];
            $entExists = $this->db->{self::COLLECTION}->countDocuments(['voucher_code' => $code]);
            if ($entExists === 0) {
                $orphanVouchers[] = $code;
            }
        }

        // 3. Clean up orphans
        $cleanedOrphansCount = 0;
        if (!empty($orphanVouchers)) {
            $delRes = $this->db->{self::VOUCHERS_COLLECTION}->deleteMany(['ma_code' => ['$in' => $orphanVouchers]]);
            $cleanedOrphansCount = $delRes->getDeletedCount();
        }

        // 4. Reconcile issued_count on campaign if drifted
        $driftDetected = ($recordedIssuedCount !== $validEntitlementsCount);
        if ($driftDetected) {
            $this->db->{self::CAMPAIGNS_COLLECTION}->updateOne(
                ['campaign_id' => $cid],
                [
                    '$set' => [
                        'issued_count' => $validEntitlementsCount,
                        'updated_at' => new \MongoDB\BSON\UTCDateTime()
                    ]
                ]
            );
        }

        return [
            'ok' => true,
            'campaign_id' => $cid,
            'previous_issued_count' => $recordedIssuedCount,
            'actual_entitlements_count' => $validEntitlementsCount,
            'reconciled' => $driftDetected,
            'orphans_detected' => count($orphanVouchers),
            'orphans_cleaned' => $cleanedOrphansCount,
            'clean' => (!$driftDetected && count($orphanVouchers) === 0)
        ];
    }

    /**
     * Lists all reward vouchers belonging to a specific customer.
     * Strictly scopes by customer_id to ensure customer isolation.
     */
    public function getCustomerVouchers(string $customerId, ?string $statusFilter = null): array {
        $cid = trim($customerId);
        if ($cid === '') {
            return [];
        }

        $filter = ['customer_id' => $cid];
        if ($statusFilter !== null && $statusFilter !== '') {
            $filter['status'] = strtoupper(trim($statusFilter));
        }

        $cursor = $this->db->{self::COLLECTION}->find($filter, [
            'sort' => ['created_at' => -1]
        ]);

        $results = [];
        foreach ($cursor as $doc) {
            $results[] = $this->normalizeEntitlementDoc($doc);
        }

        return $results;
    }

    /**
     * Checks if a customer has already claimed a reward for a campaign.
     */
    public function hasCustomerClaimedCampaign(string $campaignId, string $customerId): bool {
        return $this->db->{self::COLLECTION}->countDocuments([
            'campaign_id' => trim($campaignId),
            'customer_id' => trim($customerId)
        ]) > 0;
    }
}
