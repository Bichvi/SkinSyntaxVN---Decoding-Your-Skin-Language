<?php
// backend/app/models/VideoRewardCampaign.php
declare(strict_types=1);

/**
 * VideoRewardCampaign
 * 
 * Domain model managing Watch-to-Reward voucher campaign configurations
 * tied to published AI Idol Product Videos.
 * 
 * Enforces business rules:
 * - Only published READY videos can be linked
 * - Exactly one active reward campaign per video
 * - Discount bounds: percentage (1..100) or fixed (> 0)
 * - Minimum order amount >= 0, maximum discount validated
 * - Start date strictly before end date
 * - Quota > 0, cannot be reduced below already-issued count
 * - Stacking with Flash Sale defaults to disabled (false)
 * - Strictly configuration template: does NOT issue vouchers or modify product prices
 */
class VideoRewardCampaign {
    private $db;
    public const COLLECTION = 'ai_video_reward_campaigns';
    public const VIDEOS_COLLECTION = 'ai_product_videos';
    public const TIMEZONE = 'Asia/Ho_Chi_Minh';

    private ?string $lastErrorMessage = null;
    private static bool $indexesEnsured = false;

    public function __construct($db) {
        if (is_object($db) && method_exists($db, 'raw')) {
            $this->db = $db->raw();
        } elseif ($db instanceof \MongoDB\Database) {
            $this->db = $db;
        } else {
            $this->db = $db;
        }
        $this->ensureIndexes();
    }

    public function getLastErrorMessage(): ?string {
        return $this->lastErrorMessage;
    }

    private function setLastErrorMessage(?string $message): void {
        $this->lastErrorMessage = $message;
    }

    private function ensureIndexes(): void {
        if (self::$indexesEnsured) {
            return;
        }
        try {
            $col = $this->db->{self::COLLECTION};
            $col->createIndex(['campaign_id' => 1], ['unique' => true]);
            $col->createIndex(['video_id' => 1]);
            $col->createIndex(['publication_id' => 1]);
            $col->createIndex(['video_id' => 1, 'is_active' => 1]);
            $col->createIndex(['is_active' => 1, 'start_date' => 1, 'end_date' => 1]);
            self::$indexesEnsured = true;
        } catch (Throwable $e) {
            // Index creation failure should not halt runtime if already exists
        }
    }

    private function toObjectId(string $id): ?\MongoDB\BSON\ObjectId {
        $id = trim($id);
        if (!preg_match('/^[a-f0-9]{24}$/i', $id)) {
            return null;
        }
        try {
            return new \MongoDB\BSON\ObjectId($id);
        } catch (Throwable $e) {
            return null;
        }
    }

    private function parseDateTime($value): ?DateTimeImmutable {
        if ($value === null || $value === '') {
            return null;
        }
        if ($value instanceof \MongoDB\BSON\UTCDateTime) {
            return DateTimeImmutable::createFromMutable($value->toDateTime())
                ->setTimezone(new DateTimeZone(self::TIMEZONE));
        }
        if ($value instanceof DateTimeInterface) {
            return DateTimeImmutable::createFromInterface($value)
                ->setTimezone(new DateTimeZone(self::TIMEZONE));
        }
        try {
            $dt = new DateTimeImmutable((string)$value, new DateTimeZone(self::TIMEZONE));
            return $dt;
        } catch (Throwable $e) {
            return null;
        }
    }

    public function normalizeDocument($doc): ?array {
        if (!$doc) return null;
        $row = (array)$doc;

        $row['id'] = (string)($row['_id'] ?? '');
        $row['_id'] = (string)($row['_id'] ?? '');
        $row['campaign_id'] = (string)($row['campaign_id'] ?? '');
        $row['campaign_name'] = (string)($row['campaign_name'] ?? 'Voucher thưởng xem video');
        $row['video_id'] = (string)($row['video_id'] ?? '');
        $row['publication_id'] = (string)($row['publication_id'] ?? '');
        $row['discount_type'] = (string)($row['discount_type'] ?? 'percentage');
        $row['discount_value'] = (float)($row['discount_value'] ?? 0);
        $row['max_discount_amount'] = isset($row['max_discount_amount']) && $row['max_discount_amount'] !== null && $row['max_discount_amount'] !== ''
            ? (int)$row['max_discount_amount'] : null;
        $row['min_order_amount'] = (int)($row['min_order_amount'] ?? 0);
        $row['total_quota'] = (int)($row['total_quota'] ?? 100);
        $row['issued_count'] = (int)($row['issued_count'] ?? 0);
        $row['user_limit'] = (int)($row['user_limit'] ?? 1);
        $row['is_active'] = (bool)($row['is_active'] ?? true);
        $row['stack_with_flash_sale'] = (bool)($row['stack_with_flash_sale'] ?? false);
        $row['stack_with_other_vouchers'] = (bool)($row['stack_with_other_vouchers'] ?? false);

        // Normalize applicable products
        $pids = [];
        foreach ((array)($row['applicable_product_ids'] ?? []) as $pid) {
            $p = trim((string)$pid);
            if ($p !== '') $pids[] = $p;
        }
        $row['applicable_product_ids'] = array_values(array_unique($pids));

        // Format dates for display
        foreach (['start_date', 'end_date', 'created_at', 'updated_at'] as $df) {
            if (isset($row[$df]) && $row[$df] instanceof \MongoDB\BSON\UTCDateTime) {
                $row[$df] = $row[$df]->toDateTime()->setTimezone(new DateTimeZone(self::TIMEZONE))->format('Y-m-d H:i:s');
            } elseif (isset($row[$df]) && is_string($row[$df])) {
                $parsed = $this->parseDateTime($row[$df]);
                if ($parsed) {
                    $row[$df] = $parsed->format('Y-m-d H:i:s');
                }
            }
        }

        return $row;
    }

    /**
     * Find campaign by campaign_id
     */
    public function getCampaignById(string $campaignId): ?array {
        $cid = trim($campaignId);
        if ($cid === '') return null;
        $doc = $this->db->{self::COLLECTION}->findOne(['campaign_id' => $cid]);
        return $this->normalizeDocument($doc);
    }

    /**
     * Find campaign for a specific video_id
     */
    public function getCampaignByVideoId(string $videoId): ?array {
        $vid = trim($videoId);
        if ($vid === '') return null;
        $doc = $this->db->{self::COLLECTION}->findOne(['video_id' => $vid]);
        return $this->normalizeDocument($doc);
    }

    /**
     * Find campaign by publication_id
     */
    public function getCampaignByPublicationId(string $publicationId): ?array {
        $pid = trim($publicationId);
        if ($pid === '') return null;
        $doc = $this->db->{self::COLLECTION}->findOne(['publication_id' => $pid]);
        return $this->normalizeDocument($doc);
    }

    /**
     * Lấy chiến dịch đang hoạt động và có hiệu lực cho video.
     * Kiểm tra: is_active = true, thời hạn bắt đầu/kết thúc, còn hạn ngạch.
     */
    public function getActiveCampaignForVideo(string $videoId, ?string $publicationId = null): ?array {
        $vid = trim($videoId);
        $pid = trim((string)($publicationId ?? ''));
        if ($vid === '' && $pid === '') return null;

        $fallbackQuery = ['is_active' => true];
        if ($vid !== '' && $pid !== '') {
            $fallbackQuery['$or'] = [
                ['video_id' => $vid],
                ['publication_id' => $pid],
            ];
        } elseif ($vid !== '') {
            $fallbackQuery['video_id'] = $vid;
        } else {
            $fallbackQuery['publication_id'] = $pid;
        }

        $cursor = $this->db->{self::COLLECTION}->find($fallbackQuery);
        $nowTs = time();
        foreach ($cursor as $item) {
            $norm = $this->normalizeDocument($item);
            if (!$norm || empty($norm['is_active'])) continue;
            $startDt = $this->parseDateTime($norm['start_date'] ?? null);
            $endDt = $this->parseDateTime($norm['end_date'] ?? null);
            if ($startDt && $endDt) {
                if ($startDt->getTimestamp() <= $nowTs && $nowTs <= $endDt->getTimestamp()) {
                    if ((int)($norm['total_quota'] ?? 0) > (int)($norm['issued_count'] ?? 0)) {
                        return $norm;
                    }
                }
            }
        }
        return null;
    }

    /**
     * List all campaigns
     */
    public function listCampaigns(array $filter = [], int $limit = 100): array {
        $options = [
            'sort' => ['updated_at' => -1, 'created_at' => -1],
            'limit' => max(1, min($limit, 200)),
        ];
        $cursor = $this->db->{self::COLLECTION}->find($filter, $options);
        $results = [];
        foreach ($cursor as $doc) {
            $item = $this->normalizeDocument($doc);
            if ($item) $results[] = $item;
        }
        return $results;
    }

    /**
     * Validates and verifies that the linked video exists, is published, and has render_status READY.
     * Returns the publication document or null.
     */
    public function verifyPublishedReadyVideo(string $videoId, ?string $publicationId = null): ?array {
        $vid = trim($videoId);
        $pid = trim((string)($publicationId ?? ''));

        $query = [];
        if ($vid !== '') {
            $query['video_id'] = $vid;
        }
        if ($pid !== '') {
            $oid = $this->toObjectId($pid);
            if ($oid) {
                $query['$or'] = [
                    ['_id' => $oid],
                    ['video_id' => $vid !== '' ? $vid : $pid]
                ];
            }
        }
        if (empty($query)) {
            return null;
        }

        $doc = $this->db->{self::VIDEOS_COLLECTION}->findOne($query);
        if (!$doc) {
            return null;
        }

        $row = (array)$doc;
        $isPublished = (bool)($row['is_published'] ?? false);
        if (!$isPublished) {
            $this->setLastErrorMessage('Video chưa được xuất bản lên sản phẩm.');
            return null;
        }

        $renderStatus = strtoupper((string)($row['render_status'] ?? ''));
        if ($renderStatus !== '' && $renderStatus !== 'READY') {
            $this->setLastErrorMessage('Chỉ video có trạng thái kết xuất READY mới được tạo voucher xem video.');
            return null;
        }

        return $row;
    }

    /**
     * Creates or updates a Video Reward Campaign.
     * Enforces all domain business rules.
     */
    public function saveCampaign(array $input): array {
        $this->setLastErrorMessage(null);

        // 1. Identify Video / Publication reference
        $videoId = trim((string)($input['video_id'] ?? ''));
        $publicationId = trim((string)($input['publication_id'] ?? ''));
        $campaignId = trim((string)($input['campaign_id'] ?? ''));

        // If updating existing campaign, load it
        $existing = null;
        if ($campaignId !== '') {
            $existing = $this->getCampaignById($campaignId);
            if (!$existing) {
                return ['ok' => false, 'message' => "Không tìm thấy chiến dịch voucher #{$campaignId} để cập nhật."];
            }
            if ($videoId === '') $videoId = $existing['video_id'];
            if ($publicationId === '') $publicationId = $existing['publication_id'];
        }

        if ($videoId === '' && $publicationId === '') {
            return ['ok' => false, 'message' => 'Yêu cầu mã video (video_id) hoặc mã xuất bản (publication_id).'];
        }

        // 2. Business Rule: Video must be published and READY
        $pubDoc = $this->verifyPublishedReadyVideo($videoId, $publicationId);
        if (!$pubDoc) {
            $msg = $this->getLastErrorMessage() ?: 'Không tìm thấy video hợp lệ hoặc video chưa được xuất bản/kết xuất READY.';
            return ['ok' => false, 'message' => $msg];
        }

        $resolvedVideoId = (string)($pubDoc['video_id'] ?? $videoId);
        $resolvedPublicationId = (string)($pubDoc['_id'] ?? $publicationId);

        // 3. Discount Type & Value Validation
        $discountType = strtolower(trim((string)($input['discount_type'] ?? 'percentage')));
        if (!in_array($discountType, ['percentage', 'fixed'], true)) {
            return ['ok' => false, 'message' => 'Loại giảm giá không hợp lệ. Chỉ chấp nhận "percentage" hoặc "fixed".'];
        }

        $discountValue = (float)($input['discount_value'] ?? 0);
        if ($discountType === 'percentage') {
            if ($discountValue <= 0 || $discountValue > 100) {
                return ['ok' => false, 'message' => 'Tỉ lệ giảm giá phần trăm phải từ 1% đến 100%.'];
            }
        } else {
            if ($discountValue <= 0) {
                return ['ok' => false, 'message' => 'Số tiền giảm giá cố định phải lớn hơn 0 VNĐ.'];
            }
        }

        // Max discount (for percentage)
        $maxDiscountAmount = null;
        if (isset($input['max_discount_amount']) && $input['max_discount_amount'] !== '' && $input['max_discount_amount'] !== null) {
            $m = (int)$input['max_discount_amount'];
            if ($m <= 0) {
                return ['ok' => false, 'message' => 'Mức giảm tối đa (VNĐ) phải là số dương lớn hơn 0.'];
            }
            $maxDiscountAmount = $m;
        }

        // Minimum order amount
        $minOrderAmount = isset($input['min_order_amount']) ? (int)$input['min_order_amount'] : 0;
        if ($minOrderAmount < 0) {
            return ['ok' => false, 'message' => 'Giá trị đơn hàng tối thiểu không được âm.'];
        }

        // 4. Dates Validation
        $startStr = trim((string)($input['start_date'] ?? ''));
        $endStr = trim((string)($input['end_date'] ?? ''));
        if ($startStr === '' || $endStr === '') {
            return ['ok' => false, 'message' => 'Vui lòng cung cấp cả ngày bắt đầu và ngày kết thúc chiến dịch.'];
        }

        $dtStart = $this->parseDateTime($startStr);
        $dtEnd = $this->parseDateTime($endStr);
        if (!$dtStart || !$dtEnd) {
            return ['ok' => false, 'message' => 'Định dạng ngày bắt đầu hoặc ngày kết thúc không hợp lệ.'];
        }
        if ($dtStart >= $dtEnd) {
            return ['ok' => false, 'message' => 'Thời gian bắt đầu phải diễn ra trước thời gian kết thúc.'];
        }

        // 5. Quota & Limits Validation
        $totalQuota = isset($input['total_quota']) ? (int)$input['total_quota'] : 100;
        if ($totalQuota <= 0) {
            return ['ok' => false, 'message' => 'Tổng số lượng voucher phát hành phải lớn hơn 0.'];
        }

        $currentIssuedCount = $existing ? (int)($existing['issued_count'] ?? 0) : 0;
        if ($totalQuota < $currentIssuedCount) {
            return ['ok' => false, 'message' => "Tổng hạn ngạch ({$totalQuota}) không được nhỏ hơn số lượng voucher đã phát hành thực tế ({$currentIssuedCount})."];
        }

        $userLimit = isset($input['user_limit']) ? (int)$input['user_limit'] : 1;
        if ($userLimit < 1) {
            return ['ok' => false, 'message' => 'Giới hạn số lần nhận cho mỗi khách hàng tối thiểu là 1.'];
        }

        // 6. Active State & Single Active Campaign Rule
        $isActive = isset($input['is_active']) ? (bool)$input['is_active'] : true;

        if ($isActive) {
            // Check if another active campaign exists for this video
            $dupFilter = [
                'video_id' => $resolvedVideoId,
                'is_active' => true,
            ];
            if ($campaignId !== '') {
                $dupFilter['campaign_id'] = ['$ne' => $campaignId];
            }
            $duplicate = $this->db->{self::COLLECTION}->findOne($dupFilter);
            if ($duplicate) {
                return [
                    'ok' => false,
                    'message' => "Video này đã có một chiến dịch voucher đang hoạt động (#{$duplicate['campaign_id']}). Vui lòng tắt chiến dịch cũ trước khi kích hoạt chiến dịch mới."
                ];
            }
        }

        // 7. Applicable Products
        $applicableProducts = [];
        if (isset($input['applicable_product_ids']) && is_array($input['applicable_product_ids'])) {
            foreach ($input['applicable_product_ids'] as $pid) {
                $p = trim((string)$pid);
                if ($p !== '') $applicableProducts[] = $p;
            }
        }
        // Fallback to video associated products if none selected
        if (empty($applicableProducts) && !empty($pubDoc['associated_product_ids'])) {
            foreach ((array)$pubDoc['associated_product_ids'] as $pid) {
                $p = trim((string)$pid);
                if ($p !== '') $applicableProducts[] = $p;
            }
        }
        $applicableProducts = array_values(array_unique($applicableProducts));

        // 8. Stacking Policy (defaults to disabled)
        $stackFlashSale = isset($input['stack_with_flash_sale']) ? (bool)$input['stack_with_flash_sale'] : false;
        $stackOtherVouchers = isset($input['stack_with_other_vouchers']) ? (bool)$input['stack_with_other_vouchers'] : false;

        $campaignName = trim((string)($input['campaign_name'] ?? ''));
        if ($campaignName === '') {
            $campaignName = 'Voucher xem video: ' . (string)($pubDoc['title'] ?? 'Syna 3D');
        }

        $nowUtc = new \MongoDB\BSON\UTCDateTime();
        $startUtc = new \MongoDB\BSON\UTCDateTime($dtStart->getTimestamp() * 1000);
        $endUtc = new \MongoDB\BSON\UTCDateTime($dtEnd->getTimestamp() * 1000);

        if ($campaignId === '') {
            // Generate stable unique campaign ID
            $campaignId = 'rew_' . substr(md5($resolvedVideoId . '_' . time()), 0, 10);
            $doc = [
                'campaign_id' => $campaignId,
                'campaign_name' => $campaignName,
                'video_id' => $resolvedVideoId,
                'publication_id' => $resolvedPublicationId,
                'discount_type' => $discountType,
                'discount_value' => $discountValue,
                'max_discount_amount' => $maxDiscountAmount,
                'min_order_amount' => $minOrderAmount,
                'applicable_product_ids' => $applicableProducts,
                'start_date' => $startUtc,
                'end_date' => $endUtc,
                'total_quota' => $totalQuota,
                'issued_count' => 0,
                'user_limit' => $userLimit,
                'is_active' => $isActive,
                'stack_with_flash_sale' => $stackFlashSale,
                'stack_with_other_vouchers' => $stackOtherVouchers,
                'created_at' => $nowUtc,
                'updated_at' => $nowUtc,
            ];
            $res = $this->db->{self::COLLECTION}->insertOne($doc);
            $doc['_id'] = (string)$res->getInsertedId();
            return [
                'ok' => true,
                'message' => 'Đã tạo cấu hình chiến dịch voucher xem video thành công.',
                'campaign' => $this->normalizeDocument($doc),
            ];
        } else {
            // Update existing
            $update = [
                '$set' => [
                    'campaign_name' => $campaignName,
                    'discount_type' => $discountType,
                    'discount_value' => $discountValue,
                    'max_discount_amount' => $maxDiscountAmount,
                    'min_order_amount' => $minOrderAmount,
                    'applicable_product_ids' => $applicableProducts,
                    'start_date' => $startUtc,
                    'end_date' => $endUtc,
                    'total_quota' => $totalQuota,
                    'user_limit' => $userLimit,
                    'is_active' => $isActive,
                    'stack_with_flash_sale' => $stackFlashSale,
                    'stack_with_other_vouchers' => $stackOtherVouchers,
                    'updated_at' => $nowUtc,
                ]
            ];
            $this->db->{self::COLLECTION}->updateOne(['campaign_id' => $campaignId], $update);
            $updated = $this->getCampaignById($campaignId);
            return [
                'ok' => true,
                'message' => 'Đã cập nhật cấu hình chiến dịch voucher xem video thành công.',
                'campaign' => $updated,
            ];
        }
    }

    /**
     * Toggles active state of a campaign
     */
    public function toggleActive(string $campaignId, bool $isActive): array {
        $cid = trim($campaignId);
        $campaign = $this->getCampaignById($cid);
        if (!$campaign) {
            return ['ok' => false, 'message' => "Không tìm thấy chiến dịch voucher #{$cid}."];
        }

        if ($isActive) {
            // Verify video is still published & READY
            $pubDoc = $this->verifyPublishedReadyVideo($campaign['video_id'], $campaign['publication_id']);
            if (!$pubDoc) {
                return ['ok' => false, 'message' => 'Không thể kích hoạt: Video liên kết chưa xuất bản hoặc chưa ở trạng thái READY.'];
            }

            // Check duplicate active
            $duplicate = $this->db->{self::COLLECTION}->findOne([
                'video_id' => $campaign['video_id'],
                'is_active' => true,
                'campaign_id' => ['$ne' => $cid]
            ]);
            if ($duplicate) {
                return [
                    'ok' => false,
                    'message' => "Video này đã có chiến dịch voucher #{$duplicate['campaign_id']} đang kích hoạt. Hãy tắt chiến dịch kia trước."
                ];
            }
        }

        $nowUtc = new \MongoDB\BSON\UTCDateTime();
        $this->db->{self::COLLECTION}->updateOne(
            ['campaign_id' => $cid],
            ['$set' => ['is_active' => $isActive, 'updated_at' => $nowUtc]]
        );

        $campaign['is_active'] = $isActive;
        $statusText = $isActive ? 'kích hoạt' : 'tạm dừng';
        return [
            'ok' => true,
            'is_active' => $isActive,
            'message' => "Đã {$statusText} chiến dịch voucher xem video thành công.",
            'campaign' => $this->getCampaignById($cid),
        ];
    }

    /**
     * Safely deletes a campaign ONLY if no vouchers have been issued (for test isolation/cleanup)
     */
    public function deleteCampaign(string $campaignId): array {
        $cid = trim($campaignId);
        $campaign = $this->getCampaignById($cid);
        if (!$campaign) {
            return ['ok' => false, 'message' => 'Không tìm thấy chiến dịch.'];
        }
        if ((int)($campaign['issued_count'] ?? 0) > 0) {
            return ['ok' => false, 'message' => 'Không thể xóa chiến dịch đã có voucher phát hành thực tế. Hãy chuyển sang tắt hoạt động.'];
        }

        $this->db->{self::COLLECTION}->deleteOne(['campaign_id' => $cid]);
        return ['ok' => true, 'message' => 'Đã xóa chiến dịch thành công.'];
    }
}
