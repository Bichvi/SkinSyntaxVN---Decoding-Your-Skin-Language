<?php
// backend/app/models/ProductVideo.php
declare(strict_types=1);

class ProductVideo {
    private $db;
    private const COLLECTION = 'ai_product_videos';
    private static bool $indexesEnsured = false;

    public function __construct($db) {
        $this->db = $db;
        $this->ensureIndexes();
    }

    private function ensureIndexes(): void {
        if (self::$indexesEnsured) {
            return;
        }
        try {
            $col = $this->db->{self::COLLECTION};
            $col->createIndex(['associated_product_ids' => 1, 'is_published' => 1]);
            $col->createIndex(['video_id' => 1]);
            $col->createIndex(['display_priority' => -1, 'created_at' => -1]);
            self::$indexesEnsured = true;
        } catch (Throwable $e) {
            // Index creation failure is non-fatal
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

    public const INELIGIBLE_MESSAGE = 'Video này là bản livestream có nói giá nên không thể đăng lên trang sản phẩm.';

    /** Mirrors ai-idol-service core/purpose.py: only these purposes may appear on product pages. */
    public static function purposePublishesToProductPage($purpose): bool {
        return in_array(strtolower(trim((string)$purpose)), ['product_video', 'live_and_product'], true);
    }

    /**
     * Mirrors product_page_state() in ai-idol-service core/product_videos.py: a video is eligible by purpose or
     * because it was vetted by the one-time legacy backfill (its product-page clip is price-free).
     */
    public static function videoPublishesToProductPage($video): bool {
        $row = (array)$video;
        return !empty($row['product_page_backfill']) || self::purposePublishesToProductPage($row['purpose'] ?? '');
    }

    /**
     * Whether each source video may appear on product pages, per video id (string). A video with no document,
     * no purpose or an unknown purpose counts as live_only unless it carries the legacy product_page_backfill flag.
     *
     * @param string[] $videoIds
     * @return array<string,bool>
     */
    private function loadVideoEligibility(array $videoIds): array {
        $oids = [];
        foreach ($videoIds as $vid) {
            $oid = $this->toObjectId((string)$vid);
            if ($oid) {
                $oids[] = $oid;
            }
        }
        $purposes = [];
        if (!$oids) {
            return $purposes;
        }
        $projection = ['projection' => ['_id' => 1, 'purpose' => 1, 'product_page_backfill' => 1]];
        foreach ($this->db->ai_idol_videos->find(['_id' => ['$in' => $oids]], $projection) as $doc) {
            $row = (array)$doc;
            $purposes[(string)($row['_id'] ?? '')] = self::videoPublishesToProductPage($row);
        }
        return $purposes;
    }

    private function normalizeDocument($doc): ?array {
        if (!$doc) return null;
        $row = (array)$doc;
        $row['id'] = (string)($row['_id'] ?? '');
        $row['_id'] = (string)($row['_id'] ?? '');
        $row['video_id'] = (string)($row['video_id'] ?? '');
        $row['campaign_id'] = (string)($row['campaign_id'] ?? '');
        $row['segment_id'] = (string)($row['segment_id'] ?? '');
        $row['title'] = (string)($row['title'] ?? 'Video tư vấn sản phẩm Syna 3D');
        $row['duration'] = (float)($row['duration'] ?? 0);
        $row['format'] = (string)($row['format'] ?? '16:9');
        $row['resolution'] = (string)($row['resolution'] ?? '1280x720');
        $row['avatar_mode'] = (string)($row['avatar_mode'] ?? 'syna_3d');
        $row['is_published'] = (bool)($row['is_published'] ?? false);
        $row['display_priority'] = (int)($row['display_priority'] ?? 10);
        
        $products = [];
        foreach ((array)($row['associated_product_ids'] ?? []) as $pid) {
            $pidStr = trim((string)$pid);
            if ($pidStr !== '') {
                $products[] = $pidStr;
            }
        }
        $row['associated_product_ids'] = array_values(array_unique($products));
        $row['primary_product_id'] = (string)($row['primary_product_id'] ?? ($products[0] ?? ''));

        foreach (['created_at', 'updated_at', 'published_at'] as $dateField) {
            if (isset($row[$dateField]) && $row[$dateField] instanceof \MongoDB\BSON\UTCDateTime) {
                $row[$dateField] = $row[$dateField]->toDateTime()->setTimezone(new DateTimeZone('Asia/Ho_Chi_Minh'))->format('Y-m-d H:i:s');
            }
        }
        return $row;
    }

    /**
     * Lấy danh sách video ĐÃ XUẤT BẢN cho một mã sản phẩm cụ thể.
     * Sắp xếp ưu tiên: display_priority giảm dần, created_at giảm dần.
     */
    public function getPublishedVideosByProductId(string $productId): array {
        $pid = trim($productId);
        if ($pid === '') return [];

        $filter = [
            'is_published' => true,
            'associated_product_ids' => $pid,
        ];
        $options = [
            'sort' => [
                'display_priority' => -1,
                'created_at' => -1,
            ],
            'limit' => 10,
        ];

        $cursor = $this->db->{self::COLLECTION}->find($filter, $options);
        $items = [];
        foreach ($cursor as $doc) {
            $item = $this->normalizeDocument($doc);
            if ($item) {
                $items[] = $item;
            }
        }
        if (!$items) {
            return [];
        }
        // A publication whose source video spoke prices (live_only / unknown purpose) must never reach the storefront.
        $purposes = $this->loadVideoEligibility(array_column($items, 'video_id'));
        return array_values(array_filter($items, static function (array $item) use ($purposes): bool {
            return $purposes[$item['video_id']] ?? false;
        }));
    }

    /**
     * Lấy thông tin video đã xuất bản theo ID bản ghi xuất bản.
     * Dùng cho public stream endpoint - từ chối nếu chưa publish.
     */
    public function getPublishedVideoById(string $publicationId): ?array {
        $oid = $this->toObjectId($publicationId);
        if (!$oid) return null;

        $doc = $this->db->{self::COLLECTION}->findOne([
            '_id' => $oid,
            'is_published' => true,
        ]);
        return $this->normalizeDocument($doc);
    }

    /**
     * Lấy thông tin video theo ID cho Admin (cả nháp và đã xuất bản).
     */
    public function getVideoByIdForAdmin(string $publicationId): ?array {
        $oid = $this->toObjectId($publicationId);
        if (!$oid) return null;

        $doc = $this->db->{self::COLLECTION}->findOne(['_id' => $oid]);
        return $this->normalizeDocument($doc);
    }

    /**
     * Lấy bản ghi xuất bản theo ID bản ghi (cả nháp và đã xuất bản). Dùng cho getRewardCampaign của admin.
     */
    public function getPublicationById(string $publicationId): ?array {
        return $this->getVideoByIdForAdmin($publicationId);
    }

    /**
     * Tìm bản ghi xuất bản theo video_id của AI Idol.
     */
    public function getPublicationByVideoId(string $videoId): ?array {
        $vid = trim($videoId);
        if ($vid === '') return null;

        $doc = $this->db->{self::COLLECTION}->findOne(['video_id' => $vid]);
        return $this->normalizeDocument($doc);
    }

    /**
     * Xuất bản hoặc cập nhật liên kết video với danh sách sản phẩm.
     */
    public function publishVideo(array $data): array {
        $videoId = trim((string)($data['video_id'] ?? ''));
        if ($videoId === '') {
            return ['ok' => false, 'message' => 'Thiếu mã video_id.'];
        }

        // Kiểm tra tài sản video thực tế từ collection ai_idol_videos
        $videoOid = $this->toObjectId($videoId);
        $videoDoc = $videoOid ? $this->db->ai_idol_videos->findOne(['_id' => $videoOid]) : null;
        if (!$videoDoc) {
            // Thử tìm theo segment video_id string
            $segmentDoc = $this->db->ai_idol_segments->findOne(['video_id' => $videoId]);
            if (!$segmentDoc || ($segmentDoc['status'] ?? '') !== 'READY') {
                return ['ok' => false, 'message' => 'Video chưa hoàn tất hoặc không tồn tại trong hệ thống.'];
            }
        }
        if ($videoDoc && !self::videoPublishesToProductPage($videoDoc)) {
            return ['ok' => false, 'message' => self::INELIGIBLE_MESSAGE];
        }

        // Chuẩn hóa danh sách sản phẩm liên kết
        $rawProducts = (array)($data['associated_product_ids'] ?? []);
        $cleanProducts = [];
        foreach ($rawProducts as $p) {
            $pStr = trim((string)$p);
            if ($pStr !== '' && preg_match('/^[a-zA-Z0-9_-]+$/', $pStr)) {
                $cleanProducts[] = $pStr;
            }
        }
        if (empty($cleanProducts)) {
            $fallbackProduct = trim((string)($data['primary_product_id'] ?? ($videoDoc['product_id'] ?? '')));
            if ($fallbackProduct !== '') {
                $cleanProducts[] = $fallbackProduct;
            }
        }
        if (empty($cleanProducts)) {
            return ['ok' => false, 'message' => 'Chọn ít nhất một mã sản phẩm liên kết.'];
        }

        $now = new \MongoDB\BSON\UTCDateTime();
        $title = trim((string)($data['title'] ?? '')) ?: 'Syna 3D - Giới thiệu sản phẩm';
        $priority = max(1, min(1000, (int)($data['display_priority'] ?? 10)));
        $isPublished = isset($data['is_published']) ? (bool)$data['is_published'] : true;

        $primaryProduct = trim((string)($data['primary_product_id'] ?? ''));
        if ($primaryProduct === '' || !in_array($primaryProduct, $cleanProducts, true)) {
            $primaryProduct = $cleanProducts[0];
        }

        $existing = $this->getPublicationByVideoId($videoId);
        if ($existing) {
            $pubOid = $this->toObjectId($existing['id']);
            $updateFields = [
                'title' => $title,
                'associated_product_ids' => $cleanProducts,
                'primary_product_id' => $primaryProduct,
                'display_priority' => $priority,
                'is_published' => $isPublished,
                'updated_at' => $now,
            ];
            if ($isPublished && empty($existing['is_published'])) {
                $updateFields['published_at'] = $now;
            }
            $this->db->{self::COLLECTION}->updateOne(['_id' => $pubOid], ['$set' => $updateFields]);
            if (class_exists('CacheService')) {
                CacheService::getInstance()->deleteByPattern('product:videos:*');
            }
            $updated = $this->getVideoByIdForAdmin($existing['id']);
            return [
                'ok' => true,
                'publication' => $updated,
                'publication_id' => $existing['id'],
                'message' => 'Đã cập nhật xuất bản video thành công.'
            ];
        }

        // Tạo bản ghi xuất bản mới
        $doc = [
            'video_id' => $videoId,
            'job_id' => (string)($data['job_id'] ?? ($videoDoc['job_id'] ?? '')),
            'campaign_id' => (string)($data['campaign_id'] ?? ($videoDoc['campaign_id'] ?? '')),
            'segment_id' => (string)($data['segment_id'] ?? ''),
            'title' => $title,
            'duration' => (float)($data['duration'] ?? ($videoDoc['duration'] ?? 0)),
            'format' => (string)($data['format'] ?? ($videoDoc['format'] ?? '16:9')),
            'resolution' => (string)($data['resolution'] ?? ($videoDoc['resolution'] ?? '1280x720')),
            'avatar_mode' => (string)($data['avatar_mode'] ?? 'syna_3d'),
            'associated_product_ids' => $cleanProducts,
            'primary_product_id' => $primaryProduct,
            'display_priority' => $priority,
            'is_published' => $isPublished,
            'created_at' => $now,
            'updated_at' => $now,
            'published_at' => $isPublished ? $now : null,
        ];

        $res = $this->db->{self::COLLECTION}->insertOne($doc);
        if (class_exists('CacheService')) {
            CacheService::getInstance()->deleteByPattern('product:videos:*');
        }
        $newId = (string)$res->getInsertedId();
        $created = $this->getVideoByIdForAdmin($newId);
        return [
            'ok' => true,
            'publication' => $created,
            'publication_id' => $newId,
            'message' => 'Đã xuất bản video lên trang sản phẩm thành công.'
        ];
    }

    /**
     * Bật / Tắt trạng thái xuất bản (publish/unpublish) mà không xóa video gốc.
     */
    public function setPublishedStatus(string $publicationId, bool $publish): array {
        $oid = $this->toObjectId($publicationId);
        if (!$oid) {
            return ['ok' => false, 'message' => 'Mã xuất bản không hợp lệ.'];
        }

        $now = new \MongoDB\BSON\UTCDateTime();
        $update = [
            'is_published' => $publish,
            'updated_at' => $now,
        ];
        if ($publish) {
            $update['published_at'] = $now;
        }

        $res = $this->db->{self::COLLECTION}->updateOne(['_id' => $oid], ['$set' => $update]);
        if ($res->getMatchedCount() === 0) {
            return ['ok' => false, 'message' => 'Không tìm thấy bản ghi xuất bản.'];
        }

        if (class_exists('CacheService')) {
            CacheService::getInstance()->deleteByPattern('product:videos:*');
        }

        $current = $this->getVideoByIdForAdmin($publicationId);
        return [
            'ok' => true,
            'is_published' => $publish,
            'publication' => $current,
            'message' => $publish ? 'Đã kích hoạt xuất bản video lên trang sản phẩm.' : 'Đã gỡ video khỏi trang sản phẩm (chuyển sang nháp).',
        ];
    }

    public static function invalidateCache(): void {
        if (class_exists('CacheService')) {
            CacheService::getInstance()->deleteByPattern('product:videos:*');
        }
    }

    /**
     * Liệt kê tất cả bản ghi xuất bản (dành cho Admin).
     */
    public function listPublications(array $filter = [], int $limit = 50): array {
        $mongoFilter = [];
        if (!empty($filter['campaign_id'])) {
            $mongoFilter['campaign_id'] = (string)$filter['campaign_id'];
        }
        if (!empty($filter['product_id'])) {
            $mongoFilter['associated_product_ids'] = (string)$filter['product_id'];
        }
        if (isset($filter['is_published'])) {
            $mongoFilter['is_published'] = (bool)$filter['is_published'];
        }

        $cursor = $this->db->{self::COLLECTION}->find($mongoFilter, [
            'sort' => ['display_priority' => -1, 'created_at' => -1],
            'limit' => max(1, min(100, $limit)),
        ]);

        $items = [];
        foreach ($cursor as $doc) {
            $item = $this->normalizeDocument($doc);
            if ($item) {
                $items[] = $item;
            }
        }
        return $items;
    }

    /**
     * Liệt kê tất cả video đã tạo trong AI Idol để quản trị viên xuất bản lên trang sản phẩm.
     * Tự động nối thông tin chiến dịch, trạng thái kết xuất và trạng thái xuất bản.
     */
    public function getManageableVideos(): array {
        $campaignsMap = [];
        try {
            foreach ($this->db->ai_idol_campaigns->find([]) as $c) {
                $campaignsMap[(string)$c['_id']] = (string)($c['name'] ?? 'Chương trình AI Idol');
            }
        } catch (Throwable $e) {}

        $segmentsMap = [];
        try {
            foreach ($this->db->ai_idol_segments->find([]) as $s) {
                $vid = (string)($s['video_id'] ?? '');
                if ($vid !== '') {
                    $segmentsMap[$vid] = [
                        'campaign_id' => (string)($s['campaign_id'] ?? ''),
                        'product_id' => (string)($s['product_id'] ?? ''),
                        'status' => (string)($s['status'] ?? 'READY'),
                        'script_text' => (string)($s['script_text'] ?? '')
                    ];
                }
            }
        } catch (Throwable $e) {}

        $pubsMap = [];
        try {
            foreach ($this->db->{self::COLLECTION}->find([]) as $p) {
                $vid = (string)($p['video_id'] ?? '');
                if ($vid !== '') {
                    $pubsMap[$vid] = $this->normalizeDocument($p);
                }
            }
        } catch (Throwable $e) {}

        $productThumbs = [];
        $videos = [];
        try {
            $cursor = $this->db->ai_idol_videos->find([], ['sort' => ['created_at' => -1]]);
            foreach ($cursor as $v) {
                $vid = (string)$v['_id'];
                $seg = $segmentsMap[$vid] ?? [];
                $cid = $seg['campaign_id'] ?? '';
                $pub = $pubsMap[$vid] ?? null;
                $defaultPid = (string)($v['product_id'] ?? ($seg['product_id'] ?? ''));

                $associatedProducts = $pub ? (array)($pub['associated_product_ids'] ?? []) : ($defaultPid !== '' ? [$defaultPid] : []);
                $primaryPid = $pub ? (string)($pub['primary_product_id'] ?? '') : $defaultPid;

                foreach ($associatedProducts as $ap) {
                    $productThumbs[$ap] = true;
                }

                $videos[] = [
                    'video_id' => $vid,
                    'job_id' => (string)($v['job_id'] ?? ''),
                    'campaign_id' => $cid,
                    'campaign_name' => $campaignsMap[$cid] ?? 'Chương trình AI Idol',
                    'default_product_id' => $defaultPid,
                    'duration' => (float)($v['duration'] ?? 0),
                    'format' => (string)($v['format'] ?? '16:9'),
                    'resolution' => (string)($v['resolution'] ?? '1280x720'),
                    'render_status' => 'READY',
                    'is_published' => $pub ? (bool)($pub['is_published'] ?? false) : false,
                    'publication_id' => $pub ? (string)($pub['id'] ?? '') : '',
                    'title' => $pub ? (string)($pub['title'] ?? '') : ('Syna 3D - Giới thiệu sản phẩm ' . $defaultPid),
                    'associated_product_ids' => $associatedProducts,
                    'primary_product_id' => $primaryPid,
                    'display_priority' => $pub ? (int)($pub['display_priority'] ?? 10) : 10,
                    'can_publish' => true,
                    'product_page_eligible' => self::videoPublishesToProductPage($v),
                    // 'auto' = the pipeline published it (ai-idol-service); '' = never published or created by an admin.
                    'source' => $pub ? (string)($pub['source'] ?? '') : '',
                    'created_at' => isset($v['created_at']) && $v['created_at'] instanceof \MongoDB\BSON\UTCDateTime 
                        ? $v['created_at']->toDateTime()->setTimezone(new DateTimeZone('Asia/Ho_Chi_Minh'))->format('Y-m-d H:i:s') 
                        : '',
                ];
            }
        } catch (Throwable $e) {}

        // Resolve product info for thumbnails & names
        $productInfoMap = [];
        if (!empty($productThumbs)) {
            try {
                $pCursor = $this->db->san_pham->find([
                    'ma_san_pham' => ['$in' => array_keys($productThumbs)]
                ]);
                foreach ($pCursor as $pDoc) {
                    $pid = (string)($pDoc['ma_san_pham'] ?? $pDoc['_id']);
                    $rawImg = explode('|', (string)($pDoc['link_hinh_anh'] ?? $pDoc['hinh_anh'] ?? ''))[0];
                    $productInfoMap[$pid] = [
                        'name' => (string)($pDoc['ten_san_pham'] ?? ''),
                        'image' => trim($rawImg)
                    ];
                }
            } catch (Throwable $e) {}
        }

        foreach ($videos as &$item) {
            $pId = $item['primary_product_id'] ?: ($item['default_product_id'] ?: ($item['associated_product_ids'][0] ?? ''));
            $info = $productInfoMap[$pId] ?? null;
            $item['thumbnail_url'] = $info ? $info['image'] : '';
            $item['primary_product_name'] = $info ? $info['name'] : '';
        }
        unset($item);

        return $videos;
    }
}
