<?php
// backend/app/models/VideoWatchSession.php
declare(strict_types=1);

/**
 * VideoWatchSession
 * 
 * Domain model managing authoritative backend video watch tracking sessions
 * for SkinSyntaxVN Watch-to-Reward (Phase V2B).
 * 
 * Enforces security & business rules:
 * - Server-authoritative video duration from verified publication records.
 * - Non-guessable random session tokens (bin2hex(random_bytes(16))).
 * - Interval union (merging) coverage calculation: no naive progress summation.
 * - Rate-limited heartbeats (min interval between requests).
 * - Real elapsed server time vs playback progress delta comparison to detect seeking/skipping.
 * - Re-watching or loops do not double-count towards total coverage.
 * - Paused time does not advance watched intervals.
 * - Client cannot self-certify ELIGIBLE status; state is calculated 100% on server.
 * - STRICTLY decoupled from voucher creation (does NOT issue vouchers or mutate quotas).
 */
class VideoWatchSession {
    private $db;
    public const COLLECTION = 'ai_video_watch_sessions';
    public const VIDEOS_COLLECTION = 'ai_product_videos';
    public const CAMPAIGNS_COLLECTION = 'ai_video_reward_campaigns';

    public const MIN_HEARTBEAT_INTERVAL = 1.8; // Minimum seconds between heartbeats
    public const MAX_PLAYBACK_RATE = 2.0;       // Maximum allowable playback rate
    public const JITTER_BUFFER = 2.0;           // Seconds tolerance for network jitter
    public const COMPLETION_THRESHOLD_PERCENT = 95.0; // 95% coverage threshold
    public const COMPLETION_BUFFER_SECONDS = 2.5;     // Or within 2.5s of total duration

    private ?string $lastErrorMessage = null;

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
        try {
            $col = $this->db->{self::COLLECTION};
            $col->createIndex(['watch_session_id' => 1], ['unique' => true]);
            $col->createIndex(['video_id' => 1, 'campaign_id' => 1]);
            $col->createIndex(['publication_id' => 1]);
            $col->createIndex(['customer_id' => 1]);
            $col->createIndex(['guest_session_id' => 1]);
            $col->createIndex(['updated_at' => -1]);
        } catch (Throwable $e) {
            // Index creation non-fatal
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

    /**
     * Merge overlapping or contiguous time intervals using standard Interval Union algorithm.
     * Intervals are sorted by start time and merged within an epsilon tolerance.
     * 
     * @param array $intervals Array of [start, end] pairs
     * @param float $duration Maximum video duration
     * @param float $epsilon Tolerance to join contiguous blocks (default 0.5s)
     * @return array Normalized merged intervals
     */
    public function mergeIntervals(array $intervals, float $duration, float $epsilon = 0.5): array {
        if (empty($intervals)) {
            return [];
        }

        $clean = [];
        foreach ($intervals as $pair) {
            if ($pair instanceof \MongoDB\Model\BSONArray || $pair instanceof \Traversable) {
                $pair = iterator_to_array($pair);
            } elseif (is_object($pair)) {
                $pair = (array)$pair;
            }
            if (!is_array($pair) || count($pair) < 2) continue;
            $vals = array_values($pair);
            $start = max(0.0, (float)$vals[0]);
            $end = min($duration, (float)$vals[1]);
            if ($end > $start) {
                $clean[] = [$start, $end];
            }
        }

        if (empty($clean)) {
            return [];
        }

        usort($clean, static function ($a, $b) {
            return $a[0] <=> $b[0];
        });

        $merged = [];
        $current = $clean[0];

        for ($i = 1, $count = count($clean); $i < $count; $i++) {
            $next = $clean[$i];
            if ($next[0] <= ($current[1] + $epsilon)) {
                $current[1] = max($current[1], $next[1]);
            } else {
                $merged[] = [round($current[0], 3), round($current[1], 3)];
                $current = $next;
            }
        }
        $merged[] = [round($current[0], 3), round($current[1], 3)];

        return $merged;
    }

    /**
     * Calculates total watched seconds from merged intervals.
     */
    public function calculateWatchedSeconds(array $mergedIntervals): float {
        $total = 0.0;
        foreach ($mergedIntervals as $interval) {
            $total += max(0.0, (float)($interval[1] - $interval[0]));
        }
        return round($total, 3);
    }

    /**
     * Normalizes a MongoDB document for JSON response.
     */
    public function normalizeDocument($doc): ?array {
        if (!$doc) return null;
        $row = (array)$doc;

        $row['id'] = (string)($row['_id'] ?? '');
        $row['_id'] = (string)($row['_id'] ?? '');
        $row['watch_session_id'] = (string)($row['watch_session_id'] ?? '');
        $row['customer_id'] = isset($row['customer_id']) && $row['customer_id'] !== null ? (string)$row['customer_id'] : null;
        $row['guest_session_id'] = isset($row['guest_session_id']) && $row['guest_session_id'] !== null ? (string)$row['guest_session_id'] : null;
        $row['video_id'] = (string)($row['video_id'] ?? '');
        $row['publication_id'] = (string)($row['publication_id'] ?? '');
        $row['campaign_id'] = (string)($row['campaign_id'] ?? '');
        $row['video_duration'] = (float)($row['video_duration'] ?? 0);
        $row['playback_progress'] = (float)($row['playback_progress'] ?? 0);
        $normIntervals = [];
        foreach ((array)($row['valid_watched_intervals'] ?? []) as $iv) {
            if ($iv instanceof \MongoDB\Model\BSONArray || $iv instanceof \Traversable) {
                $iv = iterator_to_array($iv);
            } elseif (is_object($iv)) {
                $iv = (array)$iv;
            }
            if (is_array($iv) && count($iv) >= 2) {
                $v = array_values($iv);
                $normIntervals[] = [(float)$v[0], (float)$v[1]];
            }
        }
        $row['valid_watched_intervals'] = $normIntervals;
        $row['watched_seconds'] = (float)($row['watched_seconds'] ?? 0);
        $row['watched_coverage_percent'] = (float)($row['watched_coverage_percent'] ?? 0);
        $row['watch_state'] = (string)($row['watch_state'] ?? 'IN_PROGRESS');
        $row['is_eligible'] = (bool)($row['is_eligible'] ?? false);
        $row['heartbeat_count'] = (int)($row['heartbeat_count'] ?? 0);
        $row['is_guest'] = empty($row['customer_id']);

        foreach (['created_at', 'updated_at', 'eligible_at', 'last_heartbeat_at'] as $df) {
            if (isset($row[$df]) && $row[$df] instanceof \MongoDB\BSON\UTCDateTime) {
                $row[$df] = $row[$df]->toDateTime()->setTimezone(new DateTimeZone('Asia/Ho_Chi_Minh'))->format('Y-m-d H:i:s');
            }
        }

        return $row;
    }

    /**
     * Retrieves watch session by watch_session_id.
     */
    public function getSessionById(string $sessionId): ?array {
        $sid = trim($sessionId);
        if ($sid === '') return null;
        $doc = $this->db->{self::COLLECTION}->findOne(['watch_session_id' => $sid]);
        return $this->normalizeDocument($doc);
    }

    /**
     * Starts a new watch session or resumes an existing in-progress/eligible session.
     */
    public function startOrResumeSession(array $input): array {
        $this->setLastErrorMessage(null);

        $publicationId = trim((string)($input['publication_id'] ?? ''));
        $videoId = trim((string)($input['video_id'] ?? ''));
        $campaignId = trim((string)($input['campaign_id'] ?? ''));
        $customerId = isset($input['customer_id']) && trim((string)$input['customer_id']) !== '' ? trim((string)$input['customer_id']) : null;
        $guestSessionId = isset($input['guest_session_id']) && trim((string)$input['guest_session_id']) !== '' ? trim((string)$input['guest_session_id']) : null;

        if ($publicationId === '' && $videoId === '') {
            return ['ok' => false, 'code' => 'MISSING_VIDEO', 'message' => 'Yêu cầu mã video hoặc mã xuất bản.'];
        }

        // 1. Verify published video
        $pubQuery = ['is_published' => true];
        if ($publicationId !== '') {
            $poid = $this->toObjectId($publicationId);
            if ($poid) {
                $pubQuery['_id'] = $poid;
            } else {
                $pubQuery['video_id'] = $publicationId;
            }
        } elseif ($videoId !== '') {
            $pubQuery['video_id'] = $videoId;
        }

        $pubDoc = $this->db->{self::VIDEOS_COLLECTION}->findOne($pubQuery);
        if (!$pubDoc) {
            return ['ok' => false, 'code' => 'VIDEO_NOT_FOUND', 'message' => 'Video không tồn tại hoặc chưa được xuất bản.'];
        }

        $resolvedPubId = (string)$pubDoc['_id'];
        $resolvedVideoId = (string)($pubDoc['video_id'] ?? $videoId);
        $videoDuration = max(1.0, (float)($pubDoc['duration'] ?? 0));

        // 2. Verify active reward campaign
        require_once __DIR__ . '/VideoRewardCampaign.php';
        $rewardCampaignModel = new VideoRewardCampaign($this->db);
        $campaign = null;
        if ($campaignId !== '') {
            $campaign = $rewardCampaignModel->getActiveCampaignForVideo($resolvedVideoId, $resolvedPubId);
            if (!$campaign || (string)$campaign['campaign_id'] !== $campaignId) {
                return [
                    'ok' => false,
                    'code' => 'NO_ACTIVE_CAMPAIGN',
                    'message' => 'Chiến dịch ưu đãi được yêu cầu không hoạt động hoặc đã hết hạn.',
                ];
            }
        } else {
            $campaign = $rewardCampaignModel->getActiveCampaignForVideo($resolvedVideoId, $resolvedPubId);
        }

        if (!$campaign) {
            return [
                'ok' => false,
                'code' => 'NO_ACTIVE_CAMPAIGN',
                'message' => 'Hiện không có chiến dịch ưu đãi hợp lệ cho video này.',
            ];
        }

        $resolvedCampaignId = (string)$campaign['campaign_id'];

        // 3. Search for existing session for this user/guest on this video & campaign
        $lookup = [
            'video_id' => $resolvedVideoId,
            'campaign_id' => $resolvedCampaignId,
            'watch_state' => ['$in' => ['IN_PROGRESS', 'ELIGIBLE']],
        ];
        if ($customerId !== null) {
            $lookup['customer_id'] = $customerId;
        } elseif ($guestSessionId !== null) {
            $lookup['guest_session_id'] = $guestSessionId;
        } else {
            // Cannot bind without identity
            $guestSessionId = 'guest_' . bin2hex(random_bytes(12));
            $lookup['guest_session_id'] = $guestSessionId;
        }

        $existing = $this->db->{self::COLLECTION}->findOne($lookup, [
            'sort' => ['updated_at' => -1]
        ]);

        $nowUtc = new \MongoDB\BSON\UTCDateTime();
        $nowFloat = microtime(true);

        if ($existing) {
            // Resume existing session
            $existingId = (string)$existing['watch_session_id'];
            $this->db->{self::COLLECTION}->updateOne(
                ['watch_session_id' => $existingId],
                ['$set' => [
                    'last_server_time' => $nowFloat,
                    'updated_at' => $nowUtc,
                ]]
            );
            $normalized = $this->getSessionById($existingId);
            return [
                'ok' => true,
                'resumed' => true,
                'session' => $normalized,
                'campaign' => [
                    'campaign_id' => $campaign['campaign_id'],
                    'campaign_name' => $campaign['campaign_name'],
                    'discount_type' => $campaign['discount_type'],
                    'discount_value' => $campaign['discount_value'],
                    'max_discount_amount' => $campaign['max_discount_amount'],
                    'min_order_amount' => $campaign['min_order_amount'],
                    'is_active' => $campaign['is_active'],
                ],
                'message' => 'Khôi phục phiên xem video thành công.'
            ];
        }

        // 4. Create new watch session
        $newSessionId = bin2hex(random_bytes(16)); // 32 hex chars unguessable
        $clientMeta = [
            'ip' => (string)($_SERVER['REMOTE_ADDR'] ?? '127.0.0.1'),
            'user_agent' => substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255),
        ];

        $doc = [
            'watch_session_id' => $newSessionId,
            'customer_id' => $customerId,
            'guest_session_id' => $guestSessionId,
            'video_id' => $resolvedVideoId,
            'publication_id' => $resolvedPubId,
            'campaign_id' => $resolvedCampaignId,
            'video_duration' => $videoDuration,
            'playback_progress' => 0.0,
            'valid_watched_intervals' => [],
            'watched_seconds' => 0.0,
            'watched_coverage_percent' => 0.0,
            'watch_state' => 'IN_PROGRESS',
            'is_eligible' => false,
            'eligible_at' => null,
            'heartbeat_count' => 0,
            'last_heartbeat_at' => $nowUtc,
            'last_server_time' => $nowFloat,
            'last_playback_time' => 0.0,
            'client_metadata' => $clientMeta,
            'created_at' => $nowUtc,
            'updated_at' => $nowUtc,
        ];

        $this->db->{self::COLLECTION}->insertOne($doc);
        $created = $this->getSessionById($newSessionId);

        return [
            'ok' => true,
            'resumed' => false,
            'session' => $created,
            'campaign' => [
                'campaign_id' => $campaign['campaign_id'],
                'campaign_name' => $campaign['campaign_name'],
                'discount_type' => $campaign['discount_type'],
                'discount_value' => $campaign['discount_value'],
                'max_discount_amount' => $campaign['max_discount_amount'],
                'min_order_amount' => $campaign['min_order_amount'],
                'is_active' => $campaign['is_active'],
            ],
            'message' => 'Khởi tạo phiên theo dõi video thành công.'
        ];
    }

    /**
     * Reports playback heartbeat with strict anti-tampering validation.
     */
    public function recordHeartbeat(string $sessionId, float $currentTime, float $playbackRate = 1.0, bool $isPaused = false): array {
        $sid = trim($sessionId);
        if ($sid === '') {
            return ['ok' => false, 'code' => 'INVALID_SESSION', 'message' => 'Mã phiên không hợp lệ.'];
        }

        $sessionDoc = $this->db->{self::COLLECTION}->findOne(['watch_session_id' => $sid]);
        if (!$sessionDoc) {
            return ['ok' => false, 'code' => 'SESSION_NOT_FOUND', 'message' => 'Không tìm thấy phiên theo dõi xem video.'];
        }

        $nowUtc = new \MongoDB\BSON\UTCDateTime();
        $nowFloat = microtime(true);
        $duration = (float)($sessionDoc['video_duration'] ?? 1.0);
        $campaignId = (string)($sessionDoc['campaign_id'] ?? '');

        // 1. Verify campaign is still active
        require_once __DIR__ . '/VideoRewardCampaign.php';
        $rewardCampaignModel = new VideoRewardCampaign($this->db);
        $campaign = $rewardCampaignModel->getActiveCampaignForVideo((string)$sessionDoc['video_id'], (string)$sessionDoc['publication_id']);
        if (!$campaign || (string)$campaign['campaign_id'] !== $campaignId) {
            $this->db->{self::COLLECTION}->updateOne(
                ['watch_session_id' => $sid],
                ['$set' => ['watch_state' => 'EXPIRED', 'updated_at' => $nowUtc]]
            );
            return [
                'ok' => false,
                'code' => 'CAMPAIGN_EXPIRED',
                'watch_state' => 'EXPIRED',
                'is_eligible' => false,
                'message' => 'Chiến dịch ưu đãi đã kết thúc hoặc không còn hoạt động.'
            ];
        }

        // 2. Heartbeat rate limit (minimum time between heartbeats)
        $lastServerTime = (float)($sessionDoc['last_server_time'] ?? 0);
        $elapsedServerTime = $lastServerTime > 0 ? ($nowFloat - $lastServerTime) : 5.0;

        if ($elapsedServerTime < self::MIN_HEARTBEAT_INTERVAL) {
            return [
                'ok' => false,
                'code' => 'RATE_LIMITED',
                'message' => 'Nhịp tim gửi quá dày. Vui lòng thử lại sau giây lát.'
            ];
        }

        // 3. Playback position & speed validation
        $playbackRate = max(0.5, min(self::MAX_PLAYBACK_RATE, $playbackRate));
        $currentTime = max(0.0, min($duration, $currentTime));
        $lastPlaybackTime = max(0.0, min($duration, (float)($sessionDoc['last_playback_time'] ?? 0)));

        $deltaPlay = $currentTime - $lastPlaybackTime;
        $maxAllowedDelta = ($elapsedServerTime * $playbackRate) + self::JITTER_BUFFER;

        // Current intervals in session
        $rawIntervals = $sessionDoc['valid_watched_intervals'] ?? [];
        $currentIntervals = [];
        foreach ($rawIntervals as $item) {
            if ($item instanceof \MongoDB\Model\BSONArray || $item instanceof \Traversable) {
                $item = iterator_to_array($item);
            } elseif (is_object($item)) {
                $item = (array)$item;
            }
            if (is_array($item) && count($item) >= 2) {
                $vals = array_values($item);
                $currentIntervals[] = [(float)$vals[0], (float)$vals[1]];
            }
        }
        $wasSeek = false;

        if ($isPaused || abs($deltaPlay) < 0.05) {
            // Player is paused or position unchanged: no new interval added
        } elseif ($deltaPlay < 0) {
            // Player rewound/seeked backward: reset anchor, do not add forward interval
            $wasSeek = true;
        } elseif ($deltaPlay > $maxAllowedDelta) {
            // Player fast-forwarded / seeked ahead beyond allowed playback speed:
            // Reject the seek jump segment from being credited!
            $wasSeek = true;
        } else {
            // Legitimate forward playback: add interval [lastPlaybackTime, currentTime]
            $currentIntervals[] = [$lastPlaybackTime, $currentTime];
        }

        // Merge intervals & calculate coverage
        $mergedIntervals = $this->mergeIntervals($currentIntervals, $duration);
        $watchedSeconds = $this->calculateWatchedSeconds($mergedIntervals);
        $coveragePercent = round(min(100.0, ($watchedSeconds / $duration) * 100.0), 2);

        // 4. Eligibility check based on server-verified intervals and tail reach
        $isEligible = (bool)($sessionDoc['is_eligible'] ?? false);
        $watchState = (string)($sessionDoc['watch_state'] ?? 'IN_PROGRESS');
        $eligibleAt = $sessionDoc['eligible_at'] ?? null;

        $endLast = 0.0;
        if (!empty($mergedIntervals)) {
            $lastPair = end($mergedIntervals);
            $endLast = (float)($lastPair[1] ?? 0.0);
        }

        if (!$isEligible) {
            $hasSufficientCoverage = ($coveragePercent >= self::COMPLETION_THRESHOLD_PERCENT);
            $hasReachedNearEnd = ($endLast >= ($duration - self::COMPLETION_BUFFER_SECONDS));

            // Combined rule: must satisfy BOTH sufficient coverage (>=95%) AND played into tail segment
            if ($hasSufficientCoverage && $hasReachedNearEnd) {
                $isEligible = true;
                $watchState = 'ELIGIBLE';
                $eligibleAt = $nowUtc;
            }
        }

        // 5. Persist updates to MongoDB
        $heartbeatCount = (int)($sessionDoc['heartbeat_count'] ?? 0) + 1;
        $updateFields = [
            'playback_progress' => $currentTime,
            'valid_watched_intervals' => $mergedIntervals,
            'watched_seconds' => $watchedSeconds,
            'watched_coverage_percent' => $coveragePercent,
            'watch_state' => $watchState,
            'is_eligible' => $isEligible,
            'heartbeat_count' => $heartbeatCount,
            'last_heartbeat_at' => $nowUtc,
            'last_server_time' => $nowFloat,
            'last_playback_time' => $currentTime,
            'updated_at' => $nowUtc,
        ];
        if ($eligibleAt !== null && empty($sessionDoc['eligible_at'])) {
            $updateFields['eligible_at'] = $eligibleAt;
        }

        $this->db->{self::COLLECTION}->updateOne(
            ['watch_session_id' => $sid],
            ['$set' => $updateFields]
        );

        return [
            'ok' => true,
            'watch_session_id' => $sid,
            'playback_progress' => $currentTime,
            'watched_seconds' => $watchedSeconds,
            'watched_coverage_percent' => $coveragePercent,
            'watch_state' => $watchState,
            'is_eligible' => $isEligible,
            'was_seek' => $wasSeek,
            'heartbeat_count' => $heartbeatCount,
            'message' => $isEligible ? 'Đã đủ điều kiện nhận ưu đãi!' : 'Ghi nhận tiến độ xem video thành công.'
        ];
    }

    /**
     * Validates watch completion on demand.
     */
    public function validateCompletion(string $sessionId): array {
        $sid = trim($sessionId);
        if ($sid === '') {
            return ['ok' => false, 'code' => 'INVALID_SESSION', 'message' => 'Mã phiên không hợp lệ.'];
        }

        $sessionDoc = $this->db->{self::COLLECTION}->findOne(['watch_session_id' => $sid]);
        if (!$sessionDoc) {
            return ['ok' => false, 'code' => 'SESSION_NOT_FOUND', 'message' => 'Không tìm thấy phiên xem video.'];
        }

        $duration = (float)($sessionDoc['video_duration'] ?? 1.0);
        $watchedSeconds = (float)($sessionDoc['watched_seconds'] ?? 0);
        $coveragePercent = (float)($sessionDoc['watched_coverage_percent'] ?? 0);
        $isEligible = (bool)($sessionDoc['is_eligible'] ?? false);

        $rawIntervals = $sessionDoc['valid_watched_intervals'] ?? [];
        $currentIntervals = [];
        foreach ($rawIntervals as $item) {
            if ($item instanceof \MongoDB\Model\BSONArray || $item instanceof \Traversable) {
                $item = iterator_to_array($item);
            } elseif (is_object($item)) {
                $item = (array)$item;
            }
            if (is_array($item) && count($item) >= 2) {
                $vals = array_values($item);
                $currentIntervals[] = [(float)$vals[0], (float)$vals[1]];
            }
        }
        $mergedIntervals = $this->mergeIntervals($currentIntervals, $duration);
        $endLast = 0.0;
        if (!empty($mergedIntervals)) {
            $lastPair = end($mergedIntervals);
            $endLast = (float)($lastPair[1] ?? 0.0);
        }

        $hasSufficientCoverage = ($coveragePercent >= self::COMPLETION_THRESHOLD_PERCENT);
        $hasReachedNearEnd = ($endLast >= ($duration - self::COMPLETION_BUFFER_SECONDS));

        if ($isEligible || ($hasSufficientCoverage && $hasReachedNearEnd)) {
            if (!$isEligible) {
                $nowUtc = new \MongoDB\BSON\UTCDateTime();
                $this->db->{self::COLLECTION}->updateOne(
                    ['watch_session_id' => $sid],
                    ['$set' => [
                        'watch_state' => 'ELIGIBLE',
                        'is_eligible' => true,
                        'eligible_at' => $nowUtc,
                        'updated_at' => $nowUtc,
                    ]]
                );
            }
            return [
                'ok' => true,
                'is_eligible' => true,
                'watch_state' => 'ELIGIBLE',
                'watched_seconds' => $watchedSeconds,
                'watched_coverage_percent' => max($coveragePercent, 95.0),
                'message' => 'Đã đủ điều kiện nhận voucher ưu đãi!'
            ];
        }

        $remainingSeconds = max(0.0, round($duration - $watchedSeconds, 1));
        $missingMessage = !$hasReachedNearEnd
            ? 'Bạn cần xem đến đoạn kết video để hoàn tất điều kiện nhận ưu đãi.'
            : 'Chưa xem đủ thời lượng yêu cầu để mở khóa ưu đãi.';

        return [
            'ok' => false,
            'is_eligible' => false,
            'watch_state' => (string)($sessionDoc['watch_state'] ?? 'IN_PROGRESS'),
            'watched_seconds' => $watchedSeconds,
            'watched_coverage_percent' => $coveragePercent,
            'remaining_seconds' => $remainingSeconds,
            'has_reached_end' => $hasReachedNearEnd,
            'message' => $missingMessage
        ];
    }

    /**
     * Query status of current session or for a specific video & user context.
     */
    public function queryProgress(string $sessionId, ?string $customerId = null, ?string $guestSessionId = null): ?array {
        $sid = trim($sessionId);
        if ($sid !== '') {
            return $this->getSessionById($sid);
        }
        return null;
    }
}
