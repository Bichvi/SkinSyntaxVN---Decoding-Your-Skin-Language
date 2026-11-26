<?php
require_once __DIR__ . '/../services/LiveOffer.php';
require_once __DIR__ . '/../services/LiveDiscovery.php';

require_once __DIR__ . '/../services/ProductEventTracker.php';
require_once __DIR__ . '/../services/AiIdolReplaySessions.php';
require_once __DIR__ . '/../services/LiveSyna.php';
require_once __DIR__ . '/../services/SupportKnowledge.php';
require_once __DIR__ . '/../models/PhienLive.php';
require_once __DIR__ . '/../services/CacheService.php';
require_once __DIR__ . '/../services/LiveKitEgressService.php';

class LiveController {
    private const VIEWER_ACTIVE_SECONDS = 45;

    private $pdo;

    public function __construct($pdo) {
        $this->pdo = $pdo;
    }

    private function recordingPlaybackSecret(): string {
        $secret = function_exists('ss_env') ? ss_env('LIVE_RECORDING_PLAYBACK_SECRET', '') : (getenv('LIVE_RECORDING_PLAYBACK_SECRET') ?: '');
        if (trim((string)$secret) === '') {
            $secret = function_exists('ss_env') ? ss_env('LIVEKIT_API_SECRET', '') : (getenv('LIVEKIT_API_SECRET') ?: '');
        }
        return trim((string)$secret);
    }

    private function recordingPlaybackUrl(string $liveId): string {
        $expires = time() + 3600;
        $signature = hash_hmac('sha256', $liveId . '|' . $expires, $this->recordingPlaybackSecret());
        return rtrim((string)BASE_URL, '/') . '/index.php?r=api_live_recording&id=' . rawurlencode($liveId)
            . '&exp=' . $expires . '&sig=' . rawurlencode($signature);
    }

    private function validRecordingPlaybackSignature(string $liveId): bool {
        $expires = (int)($_GET['exp'] ?? 0);
        $signature = trim((string)($_GET['sig'] ?? ''));
        $secret = $this->recordingPlaybackSecret();
        if ($liveId === '' || $expires < time() || $signature === '' || $secret === '') return false;
        return hash_equals(hash_hmac('sha256', $liveId . '|' . $expires, $secret), $signature);
    }

    private function localRecordingPath(array $live): ?string {
        $relative = trim((string)($live['recording_file_path'] ?? ''));
        if ($relative === '') {
            $raw = trim((string)($live['url_ban_ghi'] ?? ''));
            $path = parse_url($raw, PHP_URL_PATH);
            if (is_string($path) && preg_match('~^/uploads/live_recordings/([A-Za-z0-9_-]+\.(?:webm|mp4|ogv))$~i', $path, $m)) {
                $relative = 'uploads/live_recordings/' . $m[1];
            }
        }
        if (!preg_match('~^uploads/live_recordings/([A-Za-z0-9_-]+\.(?:webm|mp4|ogv))$~i', str_replace('\\', '/', $relative), $m)) return null;
        $root = rtrim((string)($_SERVER['DOCUMENT_ROOT'] ?? ''), '/\\');
        if ($root === '') return null;
        $path = $root . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'live_recordings' . DIRECTORY_SEPARATOR . $m[1];
        $realRoot = realpath($root . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'live_recordings');
        $realPath = is_file($path) ? realpath($path) : false;
        if ($realRoot === false || $realPath === false || !str_starts_with(strtolower($realPath), strtolower($realRoot . DIRECTORY_SEPARATOR))) return null;
        return $realPath;
    }

    private function localRecordingMetadata(array $live): array {
        $path = $this->localRecordingPath($live);
        if ($path === null || !is_file($path)) return ['ok' => false, 'status' => 'missing', 'path' => $path];
        $size = (int)@filesize($path);
        $mime = function_exists('mime_content_type') ? strtolower((string)@mime_content_type($path)) : '';
        if ($size < 1 || !in_array($mime, ['video/mp4', 'video/webm', 'video/ogg'], true)) {
            return ['ok' => false, 'status' => 'invalid', 'path' => $path, 'size' => $size, 'mime' => $mime];
        }
        return ['ok' => true, 'status' => 'complete', 'path' => $path, 'size' => $size, 'mime' => $mime];
    }

    private function recordingObjectKey(array $live): ?string {
        $key = trim((string)($live['recording_file_path'] ?? ''));
        $id = preg_replace('/[^A-Za-z0-9_-]+/', '_', (string)($live['id'] ?? $live['ma_phong'] ?? ''));
        // Older Antigravity records stored only a public R2 URL. Treat its
        // path as a candidate key, never as proof of availability; the
        // authenticated HEAD check below remains mandatory.
        if ($key === '') {
            $legacyPath = parse_url(trim((string)($live['url_ban_ghi'] ?? '')), PHP_URL_PATH);
            $key = is_string($legacyPath) ? ltrim($legacyPath, '/') : '';
        }
        if ($key === '' || $id === '' || !preg_match('~^live-recordings/live_' . preg_quote(trim((string)$id, '_'), '~') . '_[A-Za-z0-9_-]+\.mp4$~', $key)) return null;
        return $key;
    }

    private function recordingAvailability(PhienLive $model, array $live): array {
        if (($live['recording_status'] ?? '') !== 'replay_ready') return ['available' => false, 'live' => $live];
        if (($live['recording_storage'] ?? '') === 'local' || $this->localRecordingPath($live) !== null) {
            $meta = $this->localRecordingMetadata($live);
            return ['available' => !empty($meta['ok']), 'live' => $live, 'meta' => $meta];
        }
        $key = $this->recordingObjectKey($live);
        if ($key === null) return ['available' => false, 'live' => $live];
        $service = new LiveKitEgressService();
        $meta = $service->verifyObject($key);
        return ['available' => !empty($meta['ok']), 'live' => $live, 'meta' => $meta];
    }

    /**
     * Reconcile a recording without trusting StopEgress or a guessed URL.
     * A replay URL is written only after local file checks or an authenticated
     * R2 HEAD confirms a non-empty video object.
     */
    private function syncLiveRecording(PhienLive $model, array $live, ?array $eventInfo = null): array {
        if (($live['recording_status'] ?? '') === 'replay_ready') return $this->recordingAvailability($model, $live);
        $service = new LiveKitEgressService();
        $status = $eventInfo ? $service->normalizeEgressStatus($eventInfo) : '';
        if (in_array($status, ['EGRESS_FAILED', 'EGRESS_ABORTED', 'EGRESS_LIMIT_REACHED'], true)) {
            $message = trim((string)($eventInfo['error'] ?? 'Egress không hoàn tất bản ghi.'));
            $model->failRecording($live['id'], $message !== '' ? $message : 'Egress không hoàn tất bản ghi.');
            return ['available' => false, 'status' => 'failed', 'live' => $model->findById($live['id']) ?? $live];
        }
        if ($eventInfo !== null && $status !== 'EGRESS_COMPLETE') {
            return ['available' => false, 'status' => 'pending', 'live' => $live, 'message' => 'Egress chưa báo hoàn tất.'];
        }
        // A bare object HEAD is the localhost fallback, not proof that an
        // active recording has ended. Wait until the live session is ended;
        // a signed egress_ended event may bypass this guard because it is the
        // authoritative completion signal.
        $liveStatus = strtolower((string)($live['status'] ?? $live['trang_thai'] ?? ''));
        if ($eventInfo === null && in_array($liveStatus, ['live', 'ready', 'scheduled'], true)) {
            return ['available' => false, 'status' => 'pending', 'live' => $live, 'message' => 'Phiên vẫn đang phát; chờ Egress hoàn tất.'];
        }

        $storage = strtolower(trim((string)($live['recording_storage'] ?? '')));
        if ($storage === 'local' || $this->localRecordingPath($live) !== null) {
            $meta = $this->localRecordingMetadata($live);
            if (!empty($meta['ok'])) {
                $saved = $model->saveRecording($live['id'], $this->recordingPlaybackUrl((string)$live['id']), (string)$meta['mime'], (int)$meta['size'], [
                    'verified' => true,
                    'recording_file_path' => (string)($live['recording_file_path'] ?? ''),
                    'recording_storage' => 'local',
                    'recording_ended_at' => time()
                ]);
                $fresh = $model->findById($live['id']) ?? $live;
                return ['available' => $saved || ($fresh['recording_status'] ?? '') === 'replay_ready', 'status' => 'complete', 'live' => $fresh, 'meta' => $meta];
            }
            return ['available' => false, 'status' => $meta['status'] ?? 'pending', 'live' => $live, 'meta' => $meta];
        }

        $key = $this->recordingObjectKey($live);
        if ($key === null || !$service->isStorageConfigured()) {
            return ['available' => false, 'status' => 'pending', 'live' => $live, 'message' => 'Chưa có đường dẫn R2 hoặc cấu hình R2 chưa đầy đủ.'];
        }
        $meta = $service->verifyObject($key);
        if (!empty($meta['ok'])) {
            $fileMeta = $eventInfo ? $service->egressFileMetadata($eventInfo) : [];
            $saved = $model->saveRecording($live['id'], $this->recordingPlaybackUrl((string)$live['id']), (string)$meta['mime'], (int)$meta['size'], [
                'verified' => true,
                'recording_file_path' => $key,
                'recording_storage' => 'r2',
                'recording_duration' => (int)($fileMeta['duration'] ?? 0),
                'recording_started_at' => (int)($fileMeta['started_at'] ?? 0),
                'recording_ended_at' => (int)($fileMeta['ended_at'] ?? 0),
                'recording_etag' => (string)($meta['etag'] ?? ''),
                'egress_id' => (string)($live['egress_id'] ?? ($eventInfo['egress_id'] ?? $eventInfo['egressId'] ?? ''))
            ]);
            $fresh = $model->findById($live['id']) ?? $live;
            return ['available' => $saved || ($fresh['recording_status'] ?? '') === 'replay_ready', 'status' => 'complete', 'live' => $fresh, 'meta' => $meta];
        }
        if (!empty($meta['message'])) $model->setRecordingError($live['id'], (string)$meta['message']);
        return ['available' => false, 'status' => $meta['status'] ?? 'pending', 'live' => $model->findById($live['id']) ?? $live, 'meta' => $meta];
    }

    public function index(): void {
        if (!empty($_GET['studio']) && in_array(current_role(), ['admin', 'nhanvien'], true)) {
            $this->studio();
            return;
        }

        global $db;
        $mongoDb = $db ?? $this->pdo;
        
        $spModel = new SanPham($mongoDb);

        require_once __DIR__ . '/../models/PhienLive.php';
        $phienLiveModel = new PhienLive($mongoDb);
        $rawLives = $phienLiveModel->getAllLives();
        $now = time();
        $cutoff = $now - self::VIEWER_ACTIVE_SECONDS;
        $activeViewerMap = [];

        try {
            if (is_object($mongoDb)) { // MongoDB\Database has no __isset, so isset($db->coll) is always false
                $cursor = $mongoDb->phien_live_viewers->aggregate([
                    ['$match' => ['last_seen' => ['$gte' => $cutoff]]],
                    ['$group' => ['_id' => '$room_id', 'total' => ['$sum' => 1]]]
                ]);
                foreach ($cursor as $doc) {
                    $activeViewerMap[(string)$doc['_id']] = (int)$doc['total'];
                }
            }
        } catch (Throwable $e) {
            // Silence
        }

        $selectedId = trim((string)($_GET['id'] ?? $_GET['room_id'] ?? $_GET['room'] ?? ''));
        $liveDirectoryOnly = $selectedId === '' && ($_GET['view'] ?? '') === 'all';
        $preferAiLive = $selectedId === '' && ($_GET['view'] ?? '') === 'syna';

        // Gom id sản phẩm ghim -> 1 query thay vì N query tuần tự
        $pinnedIds = [];
        foreach ($rawLives as $live) {
            if (!empty($live['ma_san_pham_ghim'])) {
                $pinnedIds[] = (string)$live['ma_san_pham_ghim'];
            }
        }
        $productsById = $spModel->findManyByIds($pinnedIds);

        $liveSessions = [];
        foreach ($rawLives as $idx => $live) {
            $p = null;
            if (!empty($live['ma_san_pham_ghim'])) {
                $p = $productsById[(string)$live['ma_san_pham_ghim']] ?? null;
            }
            $thumbImg = !empty($p['link_hinh_anh']) ? resolve_image_url((string)$p['link_hinh_anh']) : '';

            $roomId = (string)($live['ma_phong'] ?? $live['id'] ?? ($idx + 1));
            $st = (string)($live['status'] ?? 'scheduled');

            $recordingAvailable = false;
            if ($roomId === $selectedId) {
                if (in_array(($live['recording_status'] ?? ''), ['recording', 'processing'], true)) {
                    $reconciled = $this->syncLiveRecording($phienLiveModel, $live);
                    $live = $reconciled['live'] ?? $live;
                }
                $recordingCheck = $this->recordingAvailability($phienLiveModel, $live);
                $recordingAvailable = !empty($recordingCheck['available']);
                $st = (string)($live['status'] ?? $st);
            }
            
            // EXACT REAL-TIME VIEWER COUNT (0 or 1+ from active session heartbeat)
            $realViewers = 0;
            if ($st === 'live') {
                $realViewers = (int)($activeViewerMap[$roomId] ?? 0);
            }

            $urlBanGhi = $recordingAvailable ? $this->recordingPlaybackUrl($roomId) : '';

            $totalViews = (int)($live['luot_xem'] ?? 0);
            $peakViewers = (int)($live['mat_do_nguoi_xem_dinh'] ?? 0);
            $totalRevenue = (float)($live['tong_doanh_thu'] ?? 0);
            $totalOrders = (int)($live['tong_don_hang'] ?? 0);
            $totalUnits = (int)($live['tong_san_pham_ban'] ?? 0);
            $cr = (float)($live['ty_le_chot_don'] ?? 0);

            $liveSessions[] = [
                'id' => $roomId,
                'title' => (string)($live['tieu_de'] ?? ''),
                'discovery_time' => LiveDiscovery::timestamp($st === 'replay_ready'
                    ? ($live['recording_ended_at'] ?: ($live['updated_at'] ?? $live['created_at'] ?? ''))
                    : ($live['created_at'] ?? '')),
                'streamer' => (string)($live['streamer'] ?? ''),
                'streamer_avatar' => account_avatar_url([
                    'avatar_key' => $live['publisher_avatar_key'] ?? '',
                    'avatar_version' => $live['publisher_avatar_version'] ?? '',
                ]),
                'viewers' => $realViewers,
                'status' => $st,
                'live_permissions' => array_intersect_key($this->liveAudience($live), array_flip(['publish', 'moderate', 'pin'])),
                'bat_ai_cohost' => $live['bat_ai_cohost'],
                'stream_source' => (string)($live['stream_source'] ?? ''),
                'publisher_staff_id' => (string)($live['publisher_staff_id'] ?? ''),
                'studio_videos' => $this->liveAudience($live)['publish'] ? (array)($live['studio_videos'] ?? []) : [],
                'thumbnail' => $thumbImg,
                'pinned_product' => $p,
                'gia_uu_dai_live' => (float)($live['gia_uu_dai_live'] ?? ($p['gia_ban'] ?? 0)),
                'server_livekit_url' => (string)($live['server_livekit_url'] ?? (defined('LIVEKIT_URL') ? LIVEKIT_URL : '')),
                'url_ban_ghi' => $urlBanGhi,
                'recording_available' => $recordingAvailable,
                'recording_status' => (string)($live['recording_status'] ?? 'not_started'),
                'tom_tat_phien_live' => (string)($live['tom_tat_phien_live'] ?? ''),
                'luot_xem' => $totalViews,
                'mat_do_nguoi_xem_dinh' => $peakViewers,
                'tong_doanh_thu' => $totalRevenue,
                'tong_don_hang' => $totalOrders,
                'tong_san_pham_ban' => $totalUnits,
                'ty_le_chot_don' => $cr,
                'danh_sach_deal' => $live['danh_sach_deal'] ?? [],
                'top_san_pham' => is_array($live['top_san_pham'] ?? null) ? $live['top_san_pham'] : [],
                'description' => 'Khung giờ Live: ' . ($live['khung_gio_bat_dau'] ?? '') . ' - Giá sốc trong Live: ' . number_format($live['gia_uu_dai_live'] ?? 0) . 'đ'
            ];
        }

        $aiReplaySessions = $this->findAiIdolReplays($mongoDb, $spModel);
        $skinKey = null;
        $email = trim((string)((current_user() ?? [])['email'] ?? ''));
        if ($email !== '' && current_role() === 'khach_hang') {
            try {
                require_once __DIR__ . '/../models/TaiKhoan.php';
                $account = new TaiKhoan($mongoDb);
                $customer = $account->getKhachHangByEmail($email);
                if (!empty($customer['recommendation_consent'] ?? $customer['privacy_consent'] ?? false)) {
                    $skinKey = LiveDiscovery::skinKey($account->getCustomerForSelectedProfileByEmail($email));
                }
            } catch (Throwable $error) {
                error_log('Live discovery profile unavailable: ' . get_class($error));
            }
        }
        $liveSessions = LiveDiscovery::rank(array_merge($liveSessions, $aiReplaySessions), $skinKey);

        $currentLive = null;

        if ($selectedId !== '') {
            foreach ($liveSessions as $session) {
                if ((string)$session['id'] === $selectedId) {
                    $currentLive = $session;
                    break;
                }
            }
        }

        if (!$currentLive && !$liveDirectoryOnly && !$preferAiLive) {
            $uncheckedReplay = true;
            foreach ($liveSessions as $session) {
                if (!in_array($session['status'], ['live', 'replay_ready', 'ready', 'interrupted', 'scheduled'], true)) continue;
                if ($session['status'] === 'replay_ready' && empty($session['recording_available'])) {
                    if (!$uncheckedReplay) continue;
                    $uncheckedReplay = false;
                    $doc = $phienLiveModel->findById($session['id']);
                    if (!$doc || empty($this->recordingAvailability($phienLiveModel, $doc)['available'])) continue;
                    $session['recording_available'] = true;
                    $session['url_ban_ghi'] = $this->recordingPlaybackUrl($session['id']);
                }
                $currentLive = $session;
                break;
            }
        }

        if ($currentLive && !empty($currentLive['id'])) {
            $curId = (string)$currentLive['id'];
            if (session_status() === PHP_SESSION_NONE) {
                @session_start();
            }
            // Unique live views are counted by api_live_ping after the room is
            // verified LIVE, not by a page refresh or a fabricated fallback.
        }

        // live.php only resolves the current session's deal products, so load just those.
        $dealProductIds = [];
        foreach ((array)($currentLive['danh_sach_deal'] ?? []) as $deal) {
            $pid = trim((string)(is_array($deal) ? ($deal['ma_san_pham'] ?? '') : ''));
            if ($pid !== '') $dealProductIds[$pid] = $pid;
        }
        $allProducts = $dealProductIds ? array_values($spModel->findManyByIds(array_values($dealProductIds))) : [];
        // AI Idol đang phát trên kênh nội bộ (null nếu không có) + sản phẩm theo thứ tự show.
        $aiIdolLive = $this->findAiIdolLive($mongoDb);
        $showAiIdolLive = !empty($aiIdolLive) && !$liveDirectoryOnly && $selectedId === '' &&
            ($preferAiLive || ($currentLive['status'] ?? '') !== 'live');
        if ($showAiIdolLive) $currentLive = null;
        $aiIdolProducts = [];
        if ($showAiIdolLive && !empty($aiIdolLive['product_ids'])) {
            try {
                $aiNumbers = (array)($aiIdolLive['numbers'] ?? []);
                $aiWanted = $aiNumbers ? array_map('strval', array_keys($aiNumbers)) : $aiIdolLive['product_ids'];
                $aiRows = $spModel->findManyByIds($aiWanted);
                $aiIdolProducts = self::orderAiIdolProducts($aiIdolLive['product_ids'], $aiNumbers, $aiRows);
            } catch (Throwable $e) {
                $aiIdolProducts = [];
            }
        }

        $viewDir = defined('VIEW_DIR') ? VIEW_DIR : __DIR__ . '/../views';
        require_once $viewDir . '/live.php';
    }

    /**
     * Chiến dịch AI Idol đang LIVE trên kênh nội bộ và còn trong khung giờ phát, hoặc null.
     * Không bao giờ ném lỗi để trang /live không bị hỏng.
     */
    private function findAiIdolLive($mongoDb, string $campaignId = ''): ?array {
        try {
            // isset() on MongoDB\Database collection properties is always false, so check the type instead.
            if (!($mongoDb instanceof MongoDB\Database)) {
                return null;
            }
            $filter = [
                'status' => 'LIVE',
                'platforms' => 'internal',
                'master_video_path' => ['$nin' => [null, '']],
                'ends_at' => ['$gt' => new MongoDB\BSON\UTCDateTime(time() * 1000)],
            ];
            if ($campaignId !== '') {
                $filter['_id'] = new MongoDB\BSON\ObjectId($campaignId);
            }
            $doc = $mongoDb->ai_idol_campaigns->findOne($filter, ['sort' => ['started_at' => -1]]);
            if (!$doc) {
                return null;
            }
            $toTs = static function ($v): int {
                return $v instanceof MongoDB\BSON\UTCDateTime ? (int)floor(((int)(string)$v) / 1000) : 0;
            };
            $pids = [];
            foreach ((array)($doc['product_ids'] ?? []) as $pid) {
                $pid = trim((string)$pid);
                if ($pid !== '') {
                    $pids[] = $pid;
                }
            }
            return [
                'id' => (string)$doc['_id'],
                'name' => (string)($doc['name'] ?? ''),
                'started_at_ts' => $toTs($doc['started_at'] ?? null),
                'ends_at_ts' => $toTs($doc['ends_at'] ?? null),
                'duration_seconds' => (float)($doc['duration_seconds'] ?? 0),
                'product_ids' => $pids,
                'numbers' => $this->aiIdolShowNumbers($doc),
            ];
        } catch (Throwable $e) {
            return null;
        }
    }

    /**
     * Số thứ tự món theo show_plan, chỉ cho món đã lên sóng (có trong program, kind=product).
     * Chiến dịch cũ (không phải clip_library_v1) hoặc thiếu program/show_plan -> mảng rỗng.
     */
    private function aiIdolShowNumbers($doc): array {
        try {
            if ((string)($doc['show_format'] ?? '') !== 'clip_library_v1') {
                return [];
            }
            $toArr = static function ($v): array {
                if (is_array($v)) {
                    return $v;
                }
                return $v instanceof Traversable ? iterator_to_array($v) : [];
            };
            $program = $toArr($doc['program'] ?? null);
            $plan = $toArr($doc['show_plan'] ?? null);
            $planProducts = $toArr($plan['products'] ?? null);
            if (!$program || !$planProducts) {
                return [];
            }
            $aired = [];
            foreach ($program as $item) {
                $item = $toArr($item);
                if ((string)($item['kind'] ?? '') === 'product') {
                    $pid = trim((string)($item['product_id'] ?? ''));
                    if ($pid !== '') {
                        $aired[$pid] = true;
                    }
                }
            }
            $numbers = [];
            foreach ($planProducts as $entry) {
                $entry = $toArr($entry);
                $pid = trim((string)($entry['product_id'] ?? ''));
                $num = (int)($entry['number'] ?? 0);
                if ($pid !== '' && $num > 0 && isset($aired[$pid])) {
                    $numbers[$pid] = $num;
                }
            }
            asort($numbers);
            return $numbers;
        } catch (Throwable $e) {
            return [];
        }
    }

    /**
     * Lọc + sắp xếp dòng sản phẩm AI Idol. Có $numbers: chỉ món có số, theo số tăng dần.
     * Không có: giữ thứ tự $productIds như cũ.
     */
    private static function orderAiIdolProducts(array $productIds, array $numbers, array $rows): array {
        $order = $productIds;
        if ($numbers) {
            asort($numbers);
            $order = array_map('strval', array_keys($numbers));
        }
        $out = [];
        foreach ($order as $pid) {
            if (isset($rows[$pid])) {
                $out[] = $rows[$pid];
            }
        }
        return $out;
    }

    /**
     * Finished Syna AI Idol campaigns with a stored replay, mapped to live.php session entries.
     * Never throws so the /live page cannot break.
     */
    private function findAiIdolReplays($mongoDb, SanPham $spModel, int $limit = 30): array {
        try {
            if (!($mongoDb instanceof MongoDB\Database)) {
                return [];
            }
            $cursor = $mongoDb->ai_idol_campaigns->find([
                'platforms' => 'internal',
                'status' => ['$in' => ['COMPLETED', 'PARTIAL_FAILURE']],
                'replay.video_url' => ['$exists' => true, '$nin' => [null, '']],
            ], [
                'sort' => ['completed_at' => -1],
                'limit' => $limit,
                'projection' => ['name' => 1, 'replay' => 1, 'product_ids' => 1, 'completed_at' => 1],
                'typeMap' => ['root' => 'array', 'document' => 'array', 'array' => 'array'],
            ]);
            $campaigns = [];
            $firstIds = [];
            foreach ($cursor as $doc) {
                $doc['_id'] = (string)$doc['_id'];
                $first = '';
                foreach ((array)($doc['product_ids'] ?? []) as $pid) {
                    $pid = trim((string)$pid);
                    if ($pid !== '') {
                        $first = $pid;
                        break;
                    }
                }
                $doc['_first_pid'] = $first;
                if ($first !== '') {
                    $firstIds[] = $first;
                }
                $campaigns[] = $doc;
            }
            if (!$campaigns) {
                return [];
            }
            $products = $firstIds ? $spModel->findManyByIds(array_values(array_unique($firstIds))) : [];
            $sessions = [];
            foreach ($campaigns as $doc) {
                $p = $doc['_first_pid'] !== '' ? ($products[$doc['_first_pid']] ?? null) : null;
                $img = !empty($p['link_hinh_anh']) ? resolve_image_url((string)$p['link_hinh_anh']) : '';
                $session = AiIdolReplaySessions::toSession($doc, is_array($p) ? $p : null, $img);
                if ($session !== null) {
                    $session['discovery_time'] = LiveDiscovery::timestamp($doc['completed_at'] ?? null);
                    $session['recording_available'] = true;
                    $sessions[] = $session;
                }
            }
            return $sessions;
        } catch (Throwable $e) {
            return [];
        }
    }

    /** Proxy video master của chiến dịch AI Idol đang phát (chỉ khi chiến dịch đó đang LIVE). */
    public function streamAiIdolLiveVideo(): void {
        $id = trim((string)($_GET['id'] ?? ''));
        $deny = static function (): void {
            http_response_code(404);
            header('Content-Type: text/plain; charset=utf-8');
            header('Cache-Control: no-store');
            echo 'Video not found or unavailable.';
            exit;
        };
        if (!preg_match('/^[a-f0-9]{24}$/i', $id)) {
            $deny();
        }
        global $db;
        $mongoDb = $db ?? $this->pdo;
        if (!$this->findAiIdolLive($mongoDb, $id)) {
            $deny();
        }
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }
        while (ob_get_level() > 0) {
            @ob_end_clean();
        }

        $flaskUrl = rtrim((string)(getenv('AI_IDOL_ENDPOINT') ?: 'http://ai-idol-service:5003'), '/');
        $ch = curl_init($flaskUrl . '/api/ai-idol/campaigns/' . rawurlencode($id) . '/video');
        $requestHeaders = [];
        if (!empty($_SERVER['HTTP_RANGE'])) {
            $requestHeaders[] = 'Range: ' . $_SERVER['HTTP_RANGE'];
        }
        $redirected = false;
        curl_setopt_array($ch, [
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_TIMEOUT => 0,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_HTTPHEADER => $requestHeaders,
            CURLOPT_HEADERFUNCTION => static function ($curl, string $line) use (&$redirected): int {
                $length = strlen($line);
                if (preg_match('#^HTTP/\S+\s+(\d+)#i', trim($line), $m)) {
                    $status = (int)$m[1];
                    $redirected = $status >= 300 && $status < 400;
                    if (!$redirected) {
                        http_response_code($status);
                        header('Cache-Control: no-store', true);
                    }
                } elseif (!$redirected && preg_match('/^(Content-Type|Content-Length|Content-Range|Accept-Ranges):/i', $line)) {
                    header(trim($line), true);
                }
                return $length;
            },
            CURLOPT_WRITEFUNCTION => static function ($curl, string $chunk) use (&$redirected): int {
                if (!$redirected) {
                    echo $chunk;
                    flush();
                }
                return strlen($chunk);
            },
        ]);
        $ok = curl_exec($ch);
        if (!headers_sent()) {
            if ($ok === false) {
                http_response_code(502);
                header('Content-Type: text/plain; charset=utf-8');
                echo 'Cannot stream video source.';
            } elseif ($redirected) {
                http_response_code(404);
                header('Content-Type: text/plain; charset=utf-8');
                header('Cache-Control: no-store');
                echo 'Video not found or unavailable.';
            }
        }
        curl_close($ch);
        exit;
    }

    public function apiLivekitToken(): void {
        while (ob_get_level() > 0) {
            @ob_end_clean();
        }
        header('Content-Type: application/json; charset=utf-8');

        $liveId = trim((string)($_GET['id'] ?? $_POST['id'] ?? $_GET['room'] ?? $_POST['room'] ?? ''));
        if ($liveId === '') {
            http_response_code(422);
            echo json_encode(['ok' => false, 'message' => 'Thiếu mã phiên livestream.'], JSON_UNESCAPED_UNICODE);
            exit;
        }

        global $db;
        $mongoDb = $db ?? $this->pdo;
        require_once __DIR__ . '/../models/PhienLive.php';
        $phienLiveModel = new PhienLive($mongoDb);
        $liveDoc = $phienLiveModel->findById($liveId);

        if (!$liveDoc) {
            http_response_code(404);
            echo json_encode(['ok' => false, 'message' => 'Phiên LiveStream không tồn tại'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            exit;
        }

        // Every browser, server-side track probe and Egress call must address
        // the same canonical room. `ma_phong` is an admin display number and
        // can differ from the document id after imports.
        $roomName = 'skinsyntax_room_' . (string)($liveDoc['id'] ?? $liveId);
        $status = (string)($liveDoc['status'] ?? 'scheduled');
        $audience = $this->liveAudience($liveDoc);
        $isHostOrAdmin = $audience['publish'] && ($_GET['studio'] ?? '') === '1';

        if ((!$isHostOrAdmin && $status !== 'live') ||
            ($isHostOrAdmin && !in_array($status, ['scheduled', 'ready', 'live', 'interrupted'], true))) {
            http_response_code(409);
            echo json_encode(['ok' => false, 'code' => 'stream_not_live', 'message' => $status === 'replay_ready' ? 'Phiên này chỉ có bản ghi xem lại.' : 'Streamer chưa phát luồng trực tiếp.'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            exit;
        }

        $displayName = (string)((current_user() ?? [])['ho_ten'] ?? 'Khách vãng lai');
        $identity = $isHostOrAdmin ? 'publisher_' . substr(hash('sha256', $roomName), 0, 24) : 'viewer_' . bin2hex(random_bytes(18));

        $canPublish = $isHostOrAdmin;

        $configuredWsUrl = function_exists('ss_env') ? ss_env('LIVEKIT_URL', '') : (getenv('LIVEKIT_URL') ?: '');
        // The API key/secret belong to ONE LiveKit project, so the configured URL must win.
        // Sessions created under an older project still store that project's URL, and
        // using it yields "invalid API key for domain" (401) for host and viewers alike.
        $wsUrl = trim((string)$configuredWsUrl) ?: trim((string)($liveDoc['server_livekit_url'] ?? ''));
        $apiKey = function_exists('ss_env') ? ss_env('LIVEKIT_API_KEY', '') : (getenv('LIVEKIT_API_KEY') ?: '');
        $apiSecret = function_exists('ss_env') ? ss_env('LIVEKIT_API_SECRET', '') : (getenv('LIVEKIT_API_SECRET') ?: '');

        if (!preg_match('~^wss?://~i', $wsUrl) || !$configuredWsUrl ||
            parse_url($wsUrl, PHP_URL_HOST) !== parse_url($configuredWsUrl, PHP_URL_HOST) ||
            $apiKey === '' || $apiSecret === '' || in_array(strtolower($apiKey), ['your_key', 'devkey'], true) || in_array(strtolower($apiSecret), ['your_secret', 'secret'], true)) {
            http_response_code(503);
            echo json_encode(['ok' => false, 'code' => 'livekit_not_configured', 'message' => 'Hệ thống phát video chưa được cài đặt. Vui lòng liên hệ quản trị hệ thống.'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            exit;
        }

        $ttlSeconds = 3600; // TTL ngắn: 1 giờ
        $metadata = $canPublish ? hash_hmac('sha256', session_id(), $apiSecret) : '';
        $token = $this->buildLivekitJwtToken($apiKey, $apiSecret, $roomName, $identity, $displayName, $canPublish, $ttlSeconds, false, $metadata, $audience['type'] !== 'guest');
        error_log('[SkinSyntax Live] token_issued live_id=' . (string)$liveDoc['id'] . ' role=' . ($canPublish ? 'publisher' : 'viewer') . ' status=' . $status);

        echo json_encode([
            'ok' => true,
            'data' => [
                'url' => $wsUrl,
                'server_url' => $wsUrl,
                'token' => $token,
                'participant_token' => $token,
                'roomName' => $roomName,
                'identity' => $identity,
                'display_name' => $displayName,
                'can_publish' => $canPublish,
                'stream_status' => $status
            ],
            'message' => 'Đã kết nối với hệ thống phát video'
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    public function apiSearchCatalogProducts(): void {
        while (ob_get_level() > 0) {
            @ob_end_clean();
        }
        header('Content-Type: application/json; charset=utf-8');

        $q = trim((string)($_GET['q'] ?? $_POST['q'] ?? ''));
        $liveId = trim((string)($_GET['live_id'] ?? $_POST['live_id'] ?? ''));
        if (!in_array(current_role(), ['admin', 'nhanvien'], true) || $liveId === '') {
            $this->liveJson(['ok' => false, 'message' => 'Bạn không có quyền tìm sản phẩm cho phiên này.'], 403);
            return;
        }

        global $db;
        $mongoDb = $db ?? $this->pdo;
        require_once __DIR__ . '/../models/SanPham.php';
        require_once __DIR__ . '/../models/PhienLive.php';
        $live = (new PhienLive($mongoDb))->findById($liveId);
        if (!$live || !$this->liveAudience($live)['pin']) {
            $this->liveJson(['ok' => false, 'message' => 'Bạn chưa được phân công quản lý sản phẩm của phiên này.'], 403);
            return;
        }
        if (mb_strlen($q) < 2) {
            $this->liveJson(['ok' => true, 'query' => $q, 'products' => [], 'items' => []]);
            return;
        }
        $spModel = new SanPham($mongoDb);

        $results = $spModel->paginate(1, 20, $q);
        $items = array_slice((array)($results['items'] ?? []), 0, 20);

        $out = [];
        foreach ($items as $p) {
            $pId = (string)($p['ma_san_pham'] ?? $p['id'] ?? '');
            $pName = (string)($p['ten_san_pham'] ?? '');
            $pPrice = (float)($p['gia_ban'] ?? 0);
            $pBrand = (string)($p['thuong_hieu'] ?? 'SkinSyntax');
            $pStock = $p['ton_kho_hien_thi'] ?? $p['so_luong_ton_kho'] ?? $p['so_luong_ton'] ?? $p['so_luong_kho'] ?? $p['so_luong'] ?? $p['ton_kho'] ?? $p['stock'] ?? $p['quantity'] ?? null;
            $pStock = $pStock === null || $pStock === '' ? null : max(0, (int)$pStock);
            $pImg = resolve_image_url((string)($p['link_hinh_anh'] ?? $p['hinh_anh'] ?? ''));

            $out[] = [
                'id' => $pId,
                'ma_san_pham' => $pId,
                'ten_san_pham' => $pName,
                'thuong_hieu' => $pBrand,
                'name' => $pName,
                'price' => $pPrice,
                'gia_ban' => $pPrice,
                'formatted_price' => function_exists('vnd') ? vnd($pPrice) : number_format($pPrice) . ' đ',
                'stock' => $pStock,
                'stock_known' => $pStock !== null,
                'img' => $pImg,
                'hinh_anh' => $pImg
            ];
        }

        echo json_encode([
            'ok' => true,
            'query' => $q,
            'products' => $out,
            'items' => $out
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }


    private function buildLivekitJwtToken(string $apiKey, string $apiSecret, string $room, string $identity, string $name, bool $canPublish = false, int $ttlSeconds = 3600, bool $roomAdmin = false, string $metadata = '', bool $canPublishData = true): string {
        $header = json_encode(['alg' => 'HS256', 'typ' => 'JWT']);
        $now = time();
        $payload = json_encode([
            'iss' => $apiKey,
            'sub' => $identity,
            'name' => $name,
            'metadata' => $metadata,
            'nbf' => $now - 5,
            'exp' => $now + $ttlSeconds,
            'video' => [
                'room' => $room,
                'roomJoin' => !$roomAdmin,
                'canPublish' => $canPublish,
                'canSubscribe' => true,
                'canPublishData' => $canPublishData,
                'canUpdateOwnMetadata' => false,
                'roomAdmin' => $roomAdmin,
                'roomRecord' => $roomAdmin,
                'roomList' => $roomAdmin,
            ]
        ]);

        $b64Header = rtrim(strtr(base64_encode($header), '+/', '-_'), '=');
        $b64Payload = rtrim(strtr(base64_encode($payload), '+/', '-_'), '=');

        $signature = hash_hmac('sha256', $b64Header . "." . $b64Payload, $apiSecret, true);
        $b64Signature = rtrim(strtr(base64_encode($signature), '+/', '-_'), '=');

        return $b64Header . "." . $b64Payload . "." . $b64Signature;
    }

    public function apiLiveStreamState(): void {
        while (ob_get_level() > 0) @ob_end_clean();
        header('Content-Type: application/json; charset=utf-8');
        if (!is_logged_in()) {
            $this->liveJson(['ok' => false, 'code' => 'login_required', 'message' => 'Phiên đăng nhập đã hết hạn. Hãy đăng nhập lại để tiếp tục phát.'], 401);
            return;
        }
        if (!$this->livePost()) return;

        $liveId = trim((string)($_POST['live_id'] ?? $_GET['live_id'] ?? $_POST['id'] ?? $_GET['id'] ?? ''));
        $state = strtolower(trim((string)($_POST['state'] ?? $_GET['state'] ?? '')));
        if ($liveId === '' || !in_array($state, ['offline', 'live', 'interrupted', 'ended', 'heartbeat'], true)) {
            http_response_code(422);
            echo json_encode(['ok' => false, 'message' => 'Thiếu phiên live hoặc trạng thái không hợp lệ.'], JSON_UNESCAPED_UNICODE);
            exit;
        }

        global $db;
        $mongoDb = $db ?? $this->pdo;
        require_once __DIR__ . '/../models/PhienLive.php';
        $model = new PhienLive($mongoDb);
        $live = $model->findById($liveId);
        if (!$live) {
            http_response_code(404);
            echo json_encode(['ok' => false, 'message' => 'Phiên livestream không tồn tại.'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        if (!$this->liveAudience($live)['publish']) {
            $this->liveJson(['ok' => false, 'message' => 'Bạn chưa được phân công phát phiên này.'], 403);
            return;
        }
        $source = (string)($_POST['source'] ?? '');
        if ($state === 'live' || $state === 'heartbeat') {
            $publisherToken = trim((string)($_POST['publisher_token'] ?? ''));
            if (!$this->verifyLivekitPublisherToken($publisherToken, $live['id'])) {
                http_response_code(403);
                echo json_encode(['ok' => false, 'message' => 'Không xác minh được phiên publisher LiveKit.'], JSON_UNESCAPED_UNICODE);
                exit;
            }
            if ($state === 'live') {
                $sourceValid = in_array($source, ['camera', 'video', 'ai_video', 'screen'], true);
                $videoConfirmed = $sourceValid && $this->hasLiveVideoTrack($live);
                error_log('[SkinSyntax Live] publish_confirm live_id=' . (string)$live['id'] . ' source=' . ($source !== '' ? $source : 'missing') . ' video_track=' . ($videoConfirmed ? '1' : '0'));
                if (!$videoConfirmed) {
                $this->liveJson(['ok' => false, 'message' => 'LiveKit chưa xác nhận luồng hình ảnh đang phát.'], 409);
                return;
                }
            }
        }
        if (in_array(($live['status'] ?? ''), ['ended', 'replay_ready', 'recording_failed'], true) && $state !== 'offline') {
            http_response_code(409);
            echo json_encode(['ok' => false, 'message' => 'Phiên đã kết thúc và không thể phát lại.'], JSON_UNESCAPED_UNICODE);
            exit;
        }

        if ($state === 'heartbeat') {
            if (!$model->touchStream($live['id'])) {
                http_response_code(409);
                echo json_encode(['ok' => false, 'code' => 'not_live', 'message' => 'Phiên không còn ở trạng thái phát.'], JSON_UNESCAPED_UNICODE);
                exit;
            }
            echo json_encode(['ok' => true, 'live_id' => $liveId, 'state' => 'live'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            exit;
        }

        $ok = $model->updateStreamState($live['id'], $state, $source);
        if ($ok && $state === 'live') {
            $publisher = current_user() ?? [];
            $model->capNhatPhienLive($live['id'], [
                'publisher_avatar_key' => (string)($publisher['avatar_key'] ?? ''),
                'publisher_avatar_version' => (string)($publisher['avatar_version'] ?? ''),
            ]);
            // Trigger LiveKit Egress if configured and requested
            try {
                require_once __DIR__ . '/../services/LiveKitEgressService.php';
                $egress = new LiveKitEgressService();
                if ($egress->isStorageConfigured() && !empty($live['tu_dong_recording']) && empty($live['egress_id'])) {
                    $room = 'skinsyntax_room_' . $live['id'];
                    $egressRes = $egress->startRoomRecording($room, (string)$live['id']);
                    if (!empty($egressRes['ok']) && !empty($egressRes['egress_id'])) {
                        $marked = $model->markRecordingStarted($live['id'], (string)$egressRes['egress_id'], (string)$egressRes['file_path'], 'r2');
                        if ($marked) {
                            error_log('[SkinSyntax Live] egress_started live_id=' . (string)$live['id'] . ' storage=r2');
                        } else {
                            $model->setRecordingError($live['id'], 'Không lưu được thông tin Egress vào phiên live.');
                            $cleanup = $egress->stopRecording((string)$egressRes['egress_id']);
                            error_log('[SkinSyntax Live] egress_metadata_save_failed live_id=' . (string)$live['id'] . ' cleanup=' . (!empty($cleanup['ok']) ? '1' : '0'));
                        }
                    } elseif (!empty($egressRes['message'])) {
                        $model->setRecordingError($live['id'], (string)$egressRes['message']);
                    }
                }
            } catch (Throwable $e) {
                $model->setRecordingError($live['id'], 'Không khởi động được ghi hình LiveKit.');
                error_log('Auto LiveKit Egress failed: ' . $e->getMessage());
            }
        } elseif ($ok && $state === 'ended') {
            // Stop LiveKit Egress if active
            if (!empty($live['egress_id'])) {
                try {
                    require_once __DIR__ . '/../services/LiveKitEgressService.php';
                    $egress = new LiveKitEgressService();
                    $stop = $egress->stopRecording((string)$live['egress_id']);
                    error_log('[SkinSyntax Live] egress_stop_requested live_id=' . (string)$live['id'] . ' ok=' . (!empty($stop['ok']) ? '1' : '0'));
                    if (empty($stop['ok']) && !empty($stop['message'])) $model->setRecordingError($live['id'], (string)$stop['message']);
                } catch (Throwable $e) {
                    $model->setRecordingError($live['id'], 'Không thể gửi lệnh dừng ghi hình LiveKit.');
                }
            }
            if ((string)($_POST['no_recording'] ?? '') === '1' && empty($live['egress_id'])) {
                $model->failRecording($live['id'], 'Phiên phát không có bản ghi video.');
            }
        }
        echo json_encode(['ok' => $ok, 'live_id' => $liveId, 'state' => $state], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    private function verifyLivekitPublisherToken(string $token, string $liveId): bool {
        $parts = explode('.', $token);
        if (count($parts) !== 3) return false;
        $decode = static function (string $value): ?array {
            $value = strtr($value, '-_', '+/');
            $value .= str_repeat('=', (4 - strlen($value) % 4) % 4);
            $decoded = base64_decode($value, true);
            $json = $decoded === false ? null : json_decode($decoded, true);
            return is_array($json) ? $json : null;
        };
        $payload = $decode($parts[1]);
        $room = 'skinsyntax_room_' . $liveId;
        if (!$payload || (int)($payload['exp'] ?? 0) < time() ||
            ($payload['sub'] ?? '') !== 'publisher_' . substr(hash('sha256', $room), 0, 24)) return false;
        $video = $payload['video'] ?? [];
        if (($video['room'] ?? '') !== $room || empty($video['canPublish'])) return false;
        $secret = function_exists('ss_env') ? ss_env('LIVEKIT_API_SECRET', '') : (getenv('LIVEKIT_API_SECRET') ?: '');
        if ($secret === '' || in_array(strtolower($secret), ['secret', 'your_secret'], true)) return false;
        if (!hash_equals(hash_hmac('sha256', session_id(), $secret), (string)($payload['metadata'] ?? ''))) return false;
        $expected = rtrim(strtr(base64_encode(hash_hmac('sha256', $parts[0] . '.' . $parts[1], $secret, true)), '+/', '-_'), '=');
        return hash_equals($expected, $parts[2]);
    }

    private function hasLiveVideoTrack(array $live): bool {
        $env = static fn(string $name): string => function_exists('ss_env') ? ss_env($name, '') : (getenv($name) ?: '');
        $public = $env('LIVEKIT_URL');
        $roomUrl = $public ?: ($live['server_livekit_url'] ?? '');
        // The room document cannot select a host for server-side HTTP requests.
        if (!$public || parse_url($roomUrl, PHP_URL_HOST) !== parse_url($public, PHP_URL_HOST)) return false;
        $url = preg_replace('/^ws(s?):/i', 'http$1:', $env('LIVEKIT_INTERNAL_URL') ?: $public);
        $apiKey = $env('LIVEKIT_API_KEY');
        $secret = $env('LIVEKIT_API_SECRET');
        if (!is_string($url) || !preg_match('~^https?://~i', $url) || !$apiKey || !$secret || !function_exists('curl_init')) return false;
        $room = 'skinsyntax_room_' . $live['id'];
        $identity = 'publisher_' . substr(hash('sha256', $room), 0, 24);
        $token = $this->buildLivekitJwtToken($apiKey, $secret, $room, 'live-room-check', '', false, 30, true);

        // Retry loop: up to 3 attempts with 400ms delay to accommodate WebRTC track publication negotiation
        for ($attempt = 1; $attempt <= 3; $attempt++) {
            $ch = curl_init(rtrim($url, '/') . '/twirp/livekit.RoomService/GetParticipant');
            curl_setopt_array($ch, [
                CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 4, CURLOPT_CONNECTTIMEOUT => 2,
                CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Authorization: Bearer ' . $token],
                CURLOPT_POSTFIELDS => json_encode(['room' => $room, 'identity' => $identity]),
            ]);
            $body = curl_exec($ch);
            $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            error_log('[SkinSyntax Live] track_probe live_id=' . (string)$live['id'] . ' attempt=' . $attempt . ' http=' . $status);
            if ($status === 200 && is_string($body)) {
                $participant = json_decode($body, true);
                if (hash_equals(hash_hmac('sha256', session_id(), $secret), (string)($participant['metadata'] ?? ''))) {
                    $trackCount = is_array($participant['tracks'] ?? null) ? count($participant['tracks']) : 0;
                    error_log('[SkinSyntax Live] track_probe_result live_id=' . (string)$live['id'] . ' tracks=' . $trackCount);
                    foreach (($participant['tracks'] ?? []) as $track) {
                        if (in_array(($track['type'] ?? null), ['VIDEO', 1], true) && empty($track['muted'])) return true;
                    }
                }
            }
            if ($attempt < 3) {
                usleep(400000);
            }
        }
        return false;
    }

    public function apiLiveReaction(): void {
        while (ob_get_level() > 0) @ob_end_clean();
        header('Content-Type: application/json; charset=utf-8');
        $liveId = trim((string)($_POST['live_id'] ?? $_GET['live_id'] ?? $_POST['id'] ?? $_GET['id'] ?? ''));
        if ($liveId === '') {
            http_response_code(422);
            echo json_encode(['ok' => false, 'message' => 'Thiếu phiên livestream.'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        global $db;
        $mongoDb = $db ?? $this->pdo;
        require_once __DIR__ . '/../models/PhienLive.php';
        $model = new PhienLive($mongoDb);
        $live = $model->findById($liveId);
        if (!$live || ($live['status'] ?? '') !== 'live') {
            http_response_code(409);
            echo json_encode(['ok' => false, 'message' => 'Chỉ có thể thả tim khi phiên đang phát thật.'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        if (session_status() === PHP_SESSION_NONE) @session_start();
        $identity = is_logged_in() ? 'usr_' . (string)(current_user()['ma_nd'] ?? '') : 'guest_' . substr(hash('sha256', session_id()), 0, 20);
        $count = $model->recordReaction($liveId, $identity);
        echo json_encode(['ok' => $count > 0, 'reaction_count' => $count], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    private function liveJson(array $data, int $code = 200): void {
        http_response_code($code);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: private, no-store');
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    }

    private function publicLiveChat(array $message): array {
        return [
            'id' => (string)($message['id'] ?? $message['_id'] ?? ''),
            'client_message_id' => (string)($message['client_message_id'] ?? ''),
            'sender_id' => (string)($message['sender_id'] ?? ''),
            'sender_type' => (string)($message['sender_type'] ?? (!empty($message['is_ai']) ? 'ai' : 'customer')),
            'sender_name' => (string)($message['sender_name'] ?? 'Khách hàng'),
            'sender_avatar' => (string)($message['sender_avatar'] ?? ''),
            'message' => (string)($message['message'] ?? ''),
            'is_ai' => !empty($message['is_ai']),
            'is_question' => !empty($message['is_question']),
            'is_pinned' => !empty($message['is_pinned']),
            'reply_to' => (string)($message['reply_to'] ?? ''),
            'reply_to_sender_name' => (string)($message['reply_to_sender_name'] ?? ''),
            'created_at' => (string)($message['created_at'] ?? ''),
            'ts' => (int)($message['ts'] ?? $message['timestamp_ms'] ?? 0),
        ];
    }

    private function livePost(): bool {
        if (session_status() === PHP_SESSION_NONE) session_start();
        $token = (string)($_SESSION['live_csrf'] ?? '');
        if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && $token !== '' &&
            hash_equals($token, (string)($_POST['csrf'] ?? ''))) return true;
        $this->liveJson(['ok' => false, 'message' => 'Vui lòng mở lại trang livestream rồi thử lại.'], 403);
        return false;
    }

    private function liveAudience(array $live): array {
        $role = current_role();
        $user = current_user() ?? [];
        $staffId = preg_replace('/^staff-/', '', (string)($user['ma_nv'] ?? $user['id'] ?? ''));
        $assigned = $role === 'nhanvien' && $staffId !== '' &&
            hash_equals((string)($live['publisher_staff_id'] ?? ''), $staffId);
        $staffRoles = $role === 'nhanvien' && $staffId !== '' ? (array)($live['live_staff'] ?? []) : [];
        $permissions = (array)($staffRoles[$staffId] ?? []);
        return [
            'type' => $assigned ? 'streamer' : match ($role) {
                'admin' => 'admin', 'nhanvien' => 'employee', 'guest' => 'guest', default => 'customer',
            },
            'publish' => $role === 'admin' || $assigned,
            'moderate' => $role === 'admin' || $assigned || !empty($permissions['moderate']),
            'pin' => $role === 'admin' || $assigned || !empty($permissions['pin']),
            'id' => $role === 'guest' ? 'guest:' . hash('sha256', session_id())
                : ($role . ':' . (string)($user['ma_nv'] ?? $user['ma_kh'] ?? $user['ma_nd'] ?? $user['id'] ?? hash('sha256', session_id()))),
        ];
    }

    public function apiLiveChat(): void {
        if (!$this->livePost()) return;
        if (!is_logged_in()) {
            $this->liveJson(['ok' => false, 'message' => 'Vui lòng đăng nhập để bình luận trong livestream.'], 401);
            return;
        }
        global $db;
        $model = new PhienLive($db ?? $this->pdo);
        $live = $model->findById(trim((string)($_POST['room_id'] ?? '')));
        $message = trim((string)($_POST['message'] ?? ''));
        $nonce = (string)($_POST['client_message_id'] ?? '');
        if (!$live || ($live['status'] !== 'live' && !in_array(current_role(), ['admin', 'nhanvien'], true))) {
            $this->liveJson(['ok' => false, 'message' => 'Phiên này chưa phát hoặc đã kết thúc.'], 409);
            return;
        }
        if ($message === '' || mb_strlen($message) > 1000 || !preg_match('/^[a-zA-Z0-9_-]{12,80}$/D', $nonce)) {
            $this->liveJson(['ok' => false, 'message' => 'Tin nhắn hoặc mã gửi không hợp lệ.'], 422);
            return;
        }
        $room = $live['id'];
        $sender = $this->liveAudience($live);
        $question = LiveSyna::question($message);
        $aiStatus = $question === null ? 'none' : (empty($live['bat_ai_cohost']) || $live['status'] !== 'live' ? 'disabled' : 'pending');
        if ($question === '' && $aiStatus === 'pending') $aiStatus = 'canned_greeting';
        $user = current_user() ?? [];
        $name = (string)($user['ho_ten'] ?? $user['ten'] ?? 'Thành viên SkinSyntax');
        $avatar = account_avatar_url($user, true);
        $id = 'chat-' . hash('sha256', $room . ':' . $sender['id'] . ':' . $nonce);
        try {
            $saved = $model->getChat($room, $id);
            if (!$saved) {
                if ($model->isChatMuted($room, $sender['id'])) {
                    $this->liveJson(['ok' => false, 'message' => 'Bạn đang bị tạm tắt quyền chat trong phòng này.'], 403);
                    return;
                }
                $rateKey = 'live_chat_rate_' . $room;
                $recent = array_values(array_filter((array)($_SESSION[$rateKey] ?? []), fn($time) => $time > time() - 20));
                if (count($recent) >= 5) {
                    $this->liveJson(['ok' => false, 'message' => 'Bạn gửi hơi nhanh. Vui lòng chờ vài giây.'], 429);
                    return;
                }
                $replyTo = (string)($_POST['reply_to'] ?? '');
                if ($replyTo !== '' && !$model->getChat($room, $replyTo)) {
                    $this->liveJson(['ok' => false, 'message' => 'Tin nhắn được trả lời đã bị ẩn hoặc không còn tồn tại.'], 422);
                    return;
                }
                $document = [
                    '_id' => $id, 'room_id' => $room, 'sender_id' => $sender['id'], 'sender_type' => $sender['type'],
                    'sender_name' => mb_substr($name, 0, 40), 'message' => $message, 'client_message_id' => $nonce,
                    'sender_avatar' => $avatar, 'is_question' => $question !== null || str_contains($message, '?'), 'reply_to' => $replyTo,
                ];
                if ($aiStatus === 'pending') {
                    $document += [
                        'ai_pending' => true, 'ai_owner' => hash('sha256', session_id()),
                        'ai_question' => $question, 'ai_pinned_id' => $live['ma_san_pham_ghim'],
                        'ai_pinned_price' => $live['gia_uu_dai_live'], 'syna_revision' => $live['syna_revision'] ?? '',
                    ];
                }
                $saved = $model->saveChat($document);
                if (!$saved) throw new RuntimeException('Chat was not saved');
                $_SESSION[$rateKey] = [...$recent, time()];
            }
            $reply = null;
            if ($aiStatus === 'canned_greeting' && LiveSyna::question((string)$saved['message']) === '') {
                $reply = $model->saveChat([
                    '_id' => 'syna-' . substr($id, 5), 'room_id' => $room, 'sender_id' => 'syna',
                    'sender_type' => 'ai', 'sender_name' => 'SYNA',
                    'message' => 'Syna đây! Bạn muốn hỏi về sản phẩm nào trong phiên live?',
                    'is_ai' => true, 'reply_to' => $id, 'reply_to_sender_name' => (string)($saved['sender_name'] ?? ''),
                ]);
            }
            $this->liveJson(['ok' => true, 'id' => (string)$saved['_id'],
                'chat' => $this->publicLiveChat($saved),
                'syna_reply' => $reply['message'] ?? null,
                'syna_chat' => $reply ? $this->publicLiveChat($reply) : null,
                'ai_status' => empty($saved['ai_pending']) && $aiStatus === 'pending' ? 'none' : $aiStatus]);
        } catch (Throwable $e) {
            error_log('Live chat persistence failed: ' . get_class($e));
            $this->liveJson(['ok' => false, 'message' => 'Không thể lưu tin nhắn. Vui lòng thử lại.'], 503);
        }
    }

    public function apiLiveSyna(): void {
        if (!$this->livePost()) return;
        if (!is_logged_in()) {
            $this->liveJson(['ok' => false, 'ai_status' => 'disabled'], 401);
            return;
        }
        global $db;
        $mongo = $db ?? $this->pdo;
        $model = new PhienLive($mongo);
        $live = $model->findById(trim((string)($_POST['room_id'] ?? '')));
        if (!$live || $live['status'] !== 'live' || !$live['bat_ai_cohost']) {
            $this->liveJson(['ok' => false, 'ai_status' => 'disabled'], 409);
            return;
        }
        $room = $live['id'];
        $id = (string)($_POST['message_id'] ?? '');
        if (!preg_match('/^chat-[a-f0-9]{64}$/D', $id)) {
            $this->liveJson(['ok' => false, 'ai_status' => 'unavailable'], 422);
            return;
        }
        $owner = hash('sha256', session_id());
        session_write_close();
        try {
            $job = $model->claimSyna($room, $id, $owner);
            if (!$job || $job['syna_revision'] !== ($live['syna_revision'] ?? '')) {
                $this->liveJson(['ok' => false, 'ai_status' => 'handled'], 409);
                return;
            }
            $result = LiveSyna::run($room, $job['sender_id'], function () use ($model, $room, $id, $job, $mongo) {
                $current = $model->findById($room);
                if (!$current || $current['status'] !== 'live' || !$current['bat_ai_cohost'] ||
                    ($current['syna_revision'] ?? '') !== $job['syna_revision'] ||
                    !$model->getChat($room, $id) || $model->isChatMuted($room, $job['sender_id'])) return '';
                $job['live_offers'] = LiveOffer::openDeals($current, time());
                $job['live_offer_prices'] = [];
                foreach ($job['live_offers'] as $offer) {
                    $productId = (string)($offer['ma_san_pham'] ?? '');
                    if ($productId !== '' && !array_key_exists($productId, $job['live_offer_prices'])) {
                        $job['live_offer_prices'][$productId] = (float)($offer['gia_uu_dai_live'] ?? 0);
                    }
                }
                return $this->liveSynaAnswer(new SanPham($mongo), $job);
            });
            if ($result['status'] === 'answered') {
                $current = $model->findById($room);
                if (!$current || $current['status'] !== 'live' || !$current['bat_ai_cohost'] ||
                    ($current['syna_revision'] ?? '') !== $job['syna_revision']) {
                    $result['status'] = 'disabled';
                } elseif (!$model->getChat($room, $id) || !($savedReply = $model->saveChat([
                    '_id' => 'syna-' . substr($id, 5), 'room_id' => $room, 'sender_id' => 'syna',
                    'sender_type' => 'ai', 'sender_name' => 'SYNA', 'message' => mb_substr($result['answer'], 0, 1800),
                    'is_ai' => true, 'reply_to' => $id,
                    'reply_to_sender_name' => (string)($job['sender_name'] ?? ''),
                ]))) {
                    $result['status'] = 'unavailable';
                }
            }
            $this->liveJson(['ok' => $result['status'] === 'answered', 'ai_status' => $result['status'],
                'syna_chat' => isset($savedReply) ? $this->publicLiveChat($savedReply) : null]);
        } catch (Throwable $e) {
            error_log('Live SYNA request failed: ' . get_class($e));
            $this->liveJson(['ok' => false, 'ai_status' => 'unavailable'], 503);
        }
    }

    private function liveSynaAnswer(SanPham $catalog, array $job): string {
        $question = $job['ai_question'];
        $folded = SupportKnowledge::fold($question);
        $support = (new SupportKnowledge())->match($question);
        if ($support) return strip_tags((string)($support['answer'] ?? ''));

        $offers = $job['live_offers'] ?? [];
        if ($offers && preg_match('/\b(dang ghim|duoc ghim|dang gioi thieu|trong phien|tren live|dang mo ban|dang sale|bao nhieu san pham)\b/', $folded)) {
            $products = $catalog->findManyByIds(array_values(array_unique(array_column($offers, 'ma_san_pham'))));
            $lines = [];
            $seen = [];
            foreach ($offers as $offer) {
                $productId = (string)($offer['ma_san_pham'] ?? '');
                if ($productId === '' || isset($seen[$productId]) || empty($products[$productId]) || !$catalog->isProductAvailable($products[$productId])) continue;
                $seen[$productId] = true;
                $product = $products[$productId];
                $price = (float)($offer['gia_uu_dai_live'] ?? 0);
                if (count($lines) < 8) {
                    $lines[] = '• ' . (string)($product['ten_san_pham'] ?? 'Sản phẩm') . ' (mã ' . $productId . ')' .
                        ($price > 0 ? ' · ' . number_format($price, 0, ',', '.') . ' đ' : '');
                }
            }
            if ($lines) return 'Hiện có ' . count($seen) . ' sản phẩm đang mở bán trong phiên:\n' . implode("\n", $lines) .
                "\nBạn mở mục Sản phẩm trên màn hình live để xem từng ưu đãi và đặt hàng nhé.";
        }

        $matches = [];
        preg_match_all('/["“]([^"”]{3,160})["”]/u', $question, $quoted);
        if (!$quoted[1] && preg_match('/\b(serum|kem duong|sua rua mat|kem chong nang|nuoc tay trang)\b/', $folded, $category) &&
            preg_match('/\b(co|nao|goi y|tu van)\b/', $folded)) {
            $offers = $job['live_offers'] ?? [];
            $products = $catalog->findManyByIds(array_values(array_unique(array_column($offers, 'ma_san_pham'))));
            $recommendations = [];
            foreach ($offers as $offer) {
                $id = (string)($offer['ma_san_pham'] ?? '');
                if (isset($recommendations[$id]) || empty($products[$id]) || !$catalog->isProductAvailable($products[$id])) continue;
                $name = (string)$products[$id]['ten_san_pham'];
                $text = SupportKnowledge::fold($name . ' ' . ($products[$id]['loai_san_pham'] ?? ''));
                if (!str_contains(' ' . $text . ' ', ' ' . $category[1] . ' ')) continue;
                $price = (float)($offer['gia_uu_dai_live'] ?? 0);
                $recommendations[$id] = '• ' . $name . ($price > 0 ? ' · ' . number_format($price, 0, ',', '.') . ' đ' : '');
                if (count($recommendations) === 3) break;
            }
            if ($recommendations) {
                return "Syna tìm thấy các sản phẩm đang mở bán trong phiên:\n" . implode("\n", $recommendations) .
                    "\nBạn có thể mở mục Sản phẩm để xem ưu đãi đang áp dụng.";
            }
        }
        $catalogQuestion = preg_match('/\b(gia|sale|ton kho|con hang|co hang|het hang|thanh phan|so sanh|san pham|hang nao|xuat xu|dang ghim|cach dung|cong dung)\b/', $folded);
        if ($catalogQuestion || $quoted[1]) {
            $terms = $quoted[1];
            if (!$terms && !preg_match('/\b(dang ghim|duoc ghim|nay|tren live)\b/', $folded)) {
                $lookup = preg_replace('/\b(?:so sanh|thanh phan|ton kho|con hang|co hang|het hang|bao nhieu|xuat xu|san pham|syna oi|giup minh|the nao|cach dung|cong dung|gia(?: cua)?|sale|khong|a)\b/', ' ', $folded);
                $terms = preg_split('/\b(?:voi|hay|va)\b/', $lookup);
            }
            foreach (array_slice($terms, 0, 2) as $term) {
                $term = trim(preg_replace('/\s+/u', ' ', $term), " \t\n\r?.,!:;");
                if (mb_strlen($term) < 3) continue;
                $items = $catalog->paginate(1, 15, $term, '', '', '', true)['items'] ?? [];
                $candidates = [];
                foreach ($items as $item) {
                    $name = SupportKnowledge::fold((string)($item['ten_san_pham'] ?? ''));
                    $target = SupportKnowledge::fold($term);
                    if ($name === $target) {
                        $candidates = [$item];
                        break;
                    }
                    if ($name !== '' && str_contains(' ' . $name . ' ', ' ' . $target . ' ')) $candidates[] = $item;
                }
                if (count($candidates) === 1) {
                    $full = $catalog->findById((string)($candidates[0]['ma_san_pham'] ?? ''), true);
                    if ($full) $matches[] = $full;
                }
                elseif ($quoted[1] || count($terms) > 1 || count($candidates) > 1) {
                    return 'SYNA cần tên chính xác của sản phẩm “' . $term . '” để đối chiếu danh mục. Bạn có thể gửi tên đầy đủ trong dấu ngoặc kép.';
                }
            }
            if (!$matches && !empty($job['ai_pinned_id']) &&
                preg_match('/\b(ghim|san pham nay|mon nay|gia bao nhieu|con hang khong|co hang khong)\b/', $folded)) {
                $product = $catalog->findById($job['ai_pinned_id'], true);
                if ($product) $matches[] = $product;
            }
            if ($matches) {
                $lines = [];
                foreach ($matches as $item) {
                    $name = (string)($item['ten_san_pham'] ?? '');
                    $price = (float)($item['gia_ban'] ?? 0);
                    $productId = (string)($item['ma_san_pham'] ?? '');
                    $isPinned = isset(($job['live_offer_prices'] ?? [])[$productId]);
                    $livePrice = (float)(($job['live_offer_prices'] ?? [])[$productId] ?? 0);
                    $stock = $catalog->getProductStock($item);
                    $ingredients = $item['thanh_phan_day_du'] ?? $item['thanh_phan_full'] ?? $item['thanh_phan_chinh'] ?? '';
                    if (is_iterable($ingredients)) {
                        $ingredients = implode(', ', array_filter(array_map(
                            static fn($item) => SupportKnowledge::itemText(is_object($item) ? (array)$item : $item),
                            is_array($ingredients) ? $ingredients : iterator_to_array($ingredients)
                        )));
                    } else $ingredients = SupportKnowledge::itemText($ingredients);
                    $line = $name . ' · ' . ($price > 0 ? number_format($price, 0, ',', '.') . ' đ' : 'chưa có giá niêm yết');
                    if ($isPinned && $livePrice > 0) $line .= ' · giá ưu đãi trong phiên ' . number_format($livePrice, 0, ',', '.') . ' đ';
                    $line .= ' · ' . ($stock === null ? 'chưa xác nhận tồn kho' : ($stock > 0 ? 'còn ' . $stock : 'hết hàng'));
                    if (count($matches) > 1 || str_contains($folded, 'thanh phan')) {
                        $line .= "\nThành phần: " . ($ingredients !== '' ? mb_substr(strip_tags($ingredients), 0, 450) : 'chưa có dữ liệu');
                    }
                    if (str_contains($folded, 'xuat xu')) $line .= ' · xuất xứ: ' . ((string)($item['xuat_xu'] ?? '') ?: 'chưa có dữ liệu');
                    if (preg_match('/\b(phu hop|co hop|cach dung|cong dung|huong dan)\b/', $folded)) {
                        $field = preg_match('/\b(cach dung|huong dan)\b/', $folded) ? 'hdsd' : 'mo_ta';
                        $description = trim(strip_tags(SupportKnowledge::itemText($item[$field] ?? '')));
                        $line .= "\nMô tả từ danh mục: " . ($description ? mb_substr($description, 0, 360) : 'chưa có dữ liệu đủ để đánh giá hoặc hướng dẫn.');
                    }
                    $lines[] = $line;
                }
                return implode("\n\n", $lines);
            }
            if (preg_match('/\b(gia|sale|ton kho|con hang|het hang|so sanh|xuat xu)\b/', $folded)) {
                return 'Bạn gửi tên đầy đủ của sản phẩm trong dấu ngoặc kép, hoặc ghi rõ “sản phẩm ghim”, để SYNA tra đúng thông tin.';
            }
        }
        if (preg_match('/^(?:(?:tu van|goi y)\s+)?((?:serum|kem duong|sua rua mat|kem chong nang|nuoc tay trang)\b.*?)\s+nao\b/', $folded, $recommend)) {
            $term = trim($recommend[1]);
            $words = explode(' ', $term);
            $items = $catalog->paginate(1, 12, $term, '', '', '', true)['items'] ?? [];
            $names = [];
            foreach ($items as $item) {
                $name = (string)($item['ten_san_pham'] ?? '');
                $text = ' ' . SupportKnowledge::fold($name . ' ' . ($item['loai_san_pham'] ?? '')) . ' ';
                if (!$catalog->isProductAvailable($item) ||
                    array_filter($words, static fn($word) => !str_contains($text, ' ' . $word . ' '))) continue;
                $names[] = $name;
                if (count($names) >= 3) break;
            }
            if ($names) return "Danh mục SkinSyntax có các sản phẩm khớp từ khóa bạn hỏi:\n• " . implode("\n• ", $names) .
                "\nBạn có thể gửi tên cụ thể để SYNA kiểm tra thành phần và tồn kho.";
            return 'Danh mục hiện chưa có kết quả đủ rõ cho “' . $term . '”. Bạn gửi thêm tên hoặc thương hiệu để SYNA tra tiếp nhé.';
        }
        if (!$quoted[1] && ($guide = LiveSyna::skinGuide($folded)) !== '') return $guide;
        $endpoint = defined('AI_CHAT_ENDPOINT') ? AI_CHAT_ENDPOINT : 'http://127.0.0.1:5001/api/chat/auto';
        $response = $this->callAiRagService($endpoint, ['message' => $question, 'interaction_mode' => 'live_public'], 8);
        if (!empty($response['products'])) {
            $lines = [];
            foreach (array_slice($response['products'], 0, 6) as $suggestion) {
                $id = is_array($suggestion) ? (string)($suggestion['id'] ?? '') : '';
                if ($id === '' || isset($lines[$id])) continue;
                $item = $catalog->findById($id, true);
                if (!$item || empty($item['ten_san_pham'])) continue;
                $stock = $catalog->getProductStock($item);
                if ($stock === 0) continue;
                $lines[$id] = '• ' . $item['ten_san_pham'];
            }
            return $lines ? "Các sản phẩm gợi ý đã đối chiếu danh mục SkinSyntax:\n" . implode("\n", $lines) : '';
        }
        return $response['answer'] ?? '';
    }

    public function apiLiveManage(): void {
        if (!$this->livePost()) return;
        global $db;
        $model = new PhienLive($db ?? $this->pdo);
        $live = $model->findById(trim((string)($_POST['room_id'] ?? '')));
        if (!$live) {
            $this->liveJson(['ok' => false], 404);
            return;
        }
        $action = (string)($_POST['action'] ?? '');
        $access = $this->liveAudience($live);
        $ok = false;
        try {
            if ($action === 'syna' && current_role() === 'admin') {
                $ok = $model->capNhatPhienLive($live['id'], [
                    'bat_ai_cohost' => ($_POST['enabled'] ?? '') === '1', 'syna_revision' => bin2hex(random_bytes(12)),
                ]);
            } elseif ($action === 'video_upload' && $access['publish']) {
                $this->uploadStudioVideo($model, $live['id']);
                return;
            } elseif ($action === 'assign' && current_role() === 'admin') {
                $publisher = trim((string)($_POST['publisher'] ?? ''));
                $staff = trim((string)($_POST['staff'] ?? ''));
                if (!preg_match('/^[a-zA-Z0-9_-]{0,80}$/D', $publisher) || !preg_match('/^[a-zA-Z0-9_-]{0,80}$/D', $staff)) {
                    $this->liveJson(['ok' => false], 422);
                    return;
                }
                $permissions = (array)($live['live_staff'] ?? []);
                if ($staff !== '') {
                    $permissions[$staff] = ['pin' => !empty($_POST['pin']), 'moderate' => !empty($_POST['moderate'])];
                }
                $ok = $model->capNhatPhienLive($live['id'], ['publisher_staff_id' => $publisher, 'live_staff' => (object)$permissions]);
            } elseif ($access['pin'] && in_array($action, ['product_pin', 'deal_add'], true)) {
                $id = trim((string)($_POST['product_id'] ?? ''));
                $catalog = new SanPham($db ?? $this->pdo);
                $product = $id !== '' ? $catalog->findById($id, true) : null;
                $stock = $product ? $catalog->getProductStock($product) : null;
                $limit = (int)($_POST['so_luong_kho_deal'] ?? 0);
                $price = (float)($_POST['gia_uu_dai_live'] ?? 0);
                if (!$product || $limit < 1 || ($stock !== null && $limit > $stock) || $price < 0 ||
                    in_array($live['status'], ['ended', 'replay_ready', 'recording_failed'], true)) {
                    $this->liveJson(['ok' => false, 'message' => 'Kiểm tra mã sản phẩm, giá và tồn kho trước khi ghim.'], 422);
                    return;
                }
                $price = $price ?: (float)($product['gia_ban'] ?? 0);
                $durationRaw = trim((string)($_POST['duration_minutes'] ?? '15'));
                $duration = $durationRaw === '0' ? 0 : min(240, max(5, (int)$durationRaw));
                $ok = $action === 'product_pin' ? $model->ghimSanPham($live['id'], $id, $price, $duration, $limit)
                    : $model->themDealSanPham($live['id'], [
                        'ma_san_pham' => $id, 'so_luong_kho_deal' => $limit, 'gia_uu_dai_live' => $price,
                        'starts_at' => time(), 'ends_at' => $duration > 0 ? time() + $duration * 60 : 0,
                        'trang_thai' => 'active',
                    ]);
            } elseif ($access['pin'] && $action === 'deal_delete') {
                $ok = $model->xoaDealSanPham($live['id'], (string)($_POST['deal_id'] ?? ''));
            } elseif ($access['pin'] && $action === 'product_unpin') {
                $ok = $model->capNhatPhienLive($live['id'], [
                    'ma_san_pham_ghim' => '',
                    'gia_uu_dai_live' => 0,
                    'deal_bat_dau' => '',
                    'deal_ket_thuc' => '',
                    'pin_duration_minutes' => 0,
                ]);
            } elseif ($access['moderate'] && in_array($action, ['pin', 'unpin', 'hide', 'mute'], true)) {
                $ok = $model->moderateChat($live['id'], (string)($_POST['message_id'] ?? ''), $action);
            } else {
                $this->liveJson(['ok' => false], 403);
                return;
            }
        } catch (Throwable $e) {
            error_log('Live management request failed: ' . get_class($e));
        }
        $this->liveJson(['ok' => $ok], $ok ? 200 : 422);
    }

    private function uploadStudioVideo(PhienLive $model, string $room): void {
        $file = $_FILES['source_video'] ?? [];
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK ||
            ($file['size'] ?? 0) < 1 || $file['size'] > 18 * 1024 * 1024 ||
            !is_uploaded_file($file['tmp_name']) || mime_content_type($file['tmp_name']) !== 'video/mp4') {
            $this->liveJson(['ok' => false, 'message' => 'Chọn tệp MP4 hợp lệ, tối đa 18 MB.'], 422);
            return;
        }
        $root = rtrim((string)$_SERVER['DOCUMENT_ROOT'], '/\\');
        $dir = $root . '/uploads/live_studio';
        if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
            $this->liveJson(['ok' => false, 'message' => 'Không mở được thư mục lưu video.'], 503);
            return;
        }
        $fileName = bin2hex(random_bytes(16)) . '.mp4';
        $target = $dir . '/' . $fileName;
        if (!move_uploaded_file($file['tmp_name'], $target)) {
            $this->liveJson(['ok' => false, 'message' => 'Không tải lên được tệp.'], 503);
            return;
        }
        $video = ['url' => BASE_URL . '/uploads/live_studio/' . $fileName,
            'name' => mb_substr(basename((string)$file['name']), 0, 120),
            'source' => ($_POST['ai_video'] ?? '') === '1' ? 'ai_video' : 'video'];
        try {
            if (!$model->addStudioVideo($room, $video)) throw new RuntimeException('Video metadata not saved');
        } catch (Throwable $e) {
            unlink($target);
            throw $e;
        }
        $this->liveJson(['ok' => true, 'video' => $video]);
    }

    public function apiLiveChatHistory(): void {
        while (ob_get_level() > 0) {
            @ob_end_clean();
        }
        header('Content-Type: application/json; charset=utf-8');
        if (session_status() === PHP_SESSION_ACTIVE) session_write_close();

        global $db;
        $mongoDb = $db ?? $this->pdo;

        $roomId = trim((string)($_GET['room_id'] ?? $_GET['id'] ?? $_POST['room_id'] ?? $_POST['id'] ?? ''));
        if ($roomId === '') {
            http_response_code(422);
            echo json_encode(['ok' => false, 'message' => 'Thiếu mã phiên livestream.'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        $mode = strtolower(trim((string)($_GET['mode'] ?? $_POST['mode'] ?? 'all')));
        if (!in_array($mode, ['all', 'featured', 'qa'], true)) $mode = 'all';

        require_once __DIR__ . '/../models/PhienLive.php';
        $phienLiveModel = new PhienLive($mongoDb);
        $afterMs = max(0, (int)($_GET['after'] ?? $_POST['after'] ?? 0));
        $live = $phienLiveModel->findById($roomId);
        $history = $live ? $phienLiveModel->getLichSuChat($live['id'], 100, $mode, $afterMs) : [];
        header('Cache-Control: no-store');

        echo json_encode([
            'ok' => true,
            'data' => $history,
            // Compatibility alias for the dedicated Studio while older
            // customer clients continue to read `data`.
            'chats' => $history,
            'server_time_ms' => (int)round(microtime(true) * 1000),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    private function callAiRagService(string $url, array $payload, int $timeout = 4): array {
        $body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($body === false) {
            return ['ok' => false];
        }

        $headers = [
            'Accept: application/json',
            'Content-Type: application/json',
            'Content-Length: ' . strlen($body),
        ];

        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
            curl_setopt($ch, CURLOPT_TIMEOUT, max(2, $timeout));
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 2);
            $responseBody = curl_exec($ch);
            $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($status >= 200 && $status < 300 && $responseBody !== false) {
                $decoded = json_decode((string)$responseBody, true);
                if (is_array($decoded) && ($decoded['ok'] ?? false) === true && empty($decoded['fallback']) &&
                    empty($decoded['degraded']) && is_string($decoded['answer'] ?? null) &&
                    !str_contains($decoded['answer'], 'quicksend:')) {
                    return ['answer' => trim($decoded['answer']), 'products' => (array)($decoded['products'] ?? [])];
                }
            }
        }
        return ['ok' => false];
    }

    public function apiLiveEgressWebhook(): void {
        while (ob_get_level() > 0) @ob_end_clean();
        $raw = (string)file_get_contents('php://input');
        $authorization = (string)($_SERVER['HTTP_AUTHORIZATION'] ?? '');
        if ($authorization === '' && function_exists('getallheaders')) {
            foreach ((array)getallheaders() as $name => $value) {
                if (strcasecmp((string)$name, 'Authorization') === 0) { $authorization = (string)$value; break; }
            }
        }
        $service = new LiveKitEgressService();
        $claims = $service->verifyWebhook($raw, $authorization);
        if ($claims === null) {
            $this->liveJson(['ok' => false, 'message' => 'Webhook LiveKit không hợp lệ.'], 401);
            return;
        }
        $event = json_decode($raw, true);
        if (!is_array($event)) {
            $this->liveJson(['ok' => false, 'message' => 'Payload webhook không hợp lệ.'], 400);
            return;
        }
        $info = $service->extractWebhookEgressInfo($event);
        $egressId = trim((string)($info['egress_id'] ?? $info['egressId'] ?? ''));
        $roomName = trim((string)($info['room_name'] ?? $info['roomName'] ?? ''));
        global $db;
        $model = new PhienLive($db ?? $this->pdo);
        $live = $egressId !== '' ? $model->findByEgressId($egressId) : null;
        if (!$live && preg_match('/^skinsyntax_room_(.+)$/', $roomName, $m)) $live = $model->findById($m[1]);
        if (!$live) {
            $this->liveJson(['ok' => true, 'ignored' => true, 'message' => 'Webhook không thuộc phiên SkinSyntax nào.'], 202);
            return;
        }
        $eventName = strtolower(trim((string)($event['event'] ?? '')));
        if ($eventName !== 'egress_ended') {
            $this->liveJson(['ok' => true, 'event' => $eventName, 'live_id' => (string)$live['id']], 200);
            return;
        }
        $result = $this->syncLiveRecording($model, $live, $info);
        $this->liveJson([
            'ok' => true,
            'live_id' => (string)$live['id'],
            'recording_status' => (string)($result['live']['recording_status'] ?? ($result['status'] ?? 'processing')),
            'recording_available' => !empty($result['available'])
        ], !empty($result['available']) || ($result['status'] ?? '') === 'failed' ? 200 : 202);
    }

    public function apiLiveRecordingStatus(): void {
        while (ob_get_level() > 0) @ob_end_clean();
        $liveId = trim((string)($_GET['id'] ?? $_GET['live_id'] ?? ''));
        if ($liveId === '') { $this->liveJson(['ok' => false, 'message' => 'Thiếu mã phiên livestream.'], 422); return; }
        global $db;
        $model = new PhienLive($db ?? $this->pdo);
        $live = $model->findById($liveId);
        if (!$live) { $this->liveJson(['ok' => false, 'message' => 'Phiên livestream không tồn tại.'], 404); return; }
        if (in_array(($live['recording_status'] ?? ''), ['recording', 'processing'], true)) {
            $live = $this->syncLiveRecording($model, $live)['live'] ?? $live;
        }
        $check = $this->recordingAvailability($model, $live);
        $available = !empty($check['available']);
        $this->liveJson([
            'ok' => true,
            'live_id' => (string)$live['id'],
            'recording_status' => $available ? 'replay_ready' : (string)($live['recording_status'] ?? 'not_started'),
            'recording_available' => $available,
            'recording_url' => $available ? $this->recordingPlaybackUrl((string)$live['id']) : '',
            'recording_mime' => $available ? (string)($check['meta']['mime'] ?? $live['recording_mime'] ?? '') : '',
            'recording_size' => $available ? (int)($check['meta']['size'] ?? $live['recording_size'] ?? 0) : 0,
            'recording_duration' => (int)($live['recording_duration'] ?? 0),
            'recording_error' => (string)($live['recording_error'] ?? '')
        ]);
    }

    public function apiLiveRecording(): void {
        $liveId = trim((string)($_GET['id'] ?? $_GET['live_id'] ?? ''));
        if ($liveId === '') { http_response_code(404); header('Content-Type: text/plain; charset=utf-8'); echo 'Bản ghi không khả dụng'; return; }
        if (!$this->validRecordingPlaybackSignature($liveId)) {
            http_response_code(404);
            header('Content-Type: text/plain; charset=utf-8');
            header('Cache-Control: no-store');
            echo 'Bản ghi không khả dụng';
            return;
        }
        global $db;
        $model = new PhienLive($db ?? $this->pdo);
        $live = $model->findById($liveId);
        if (!$live) { http_response_code(404); header('Content-Type: text/plain; charset=utf-8'); echo 'Bản ghi không khả dụng'; return; }
        if (in_array(($live['recording_status'] ?? ''), ['recording', 'processing'], true)) $live = $this->syncLiveRecording($model, $live)['live'] ?? $live;
        $check = $this->recordingAvailability($model, $live);
        if (empty($check['available'])) {
            http_response_code(404); header('Content-Type: text/plain; charset=utf-8'); header('Cache-Control: no-store'); echo 'Bản ghi không khả dụng'; return;
        }
        while (ob_get_level() > 0) @ob_end_clean();
        $method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
        $range = trim((string)($_SERVER['HTTP_RANGE'] ?? ''));
        if (($live['recording_storage'] ?? '') !== 'local' && $this->localRecordingPath($live) === null) {
            $key = $this->recordingObjectKey($live);
            if ($key === null) { http_response_code(404); header('Content-Type: text/plain; charset=utf-8'); echo 'Bản ghi không khả dụng'; return; }
            $service = new LiveKitEgressService();
            $result = $service->streamObject($key, $method === 'HEAD' ? 'HEAD' : 'GET', $range !== '' ? $range : null,
                static function (int $status, array $headers): void {
                    http_response_code($status);
                    foreach (['content-type', 'content-length', 'content-range', 'accept-ranges', 'etag', 'last-modified', 'content-disposition'] as $key) {
                        if (isset($headers[$key])) header(ucwords(str_replace('-', '-', $key), '-') . ': ' . $headers[$key], true);
                    }
                    if (!isset($headers['content-type'])) header('Content-Type: video/mp4');
                    if (!isset($headers['accept-ranges'])) header('Accept-Ranges: bytes');
                    header('Cache-Control: private, no-store');
                }, static function (string $chunk): int { echo $chunk; flush(); return strlen($chunk); });
            if (empty($result['ok']) && !headers_sent()) { http_response_code(404); header('Content-Type: text/plain; charset=utf-8'); echo 'Bản ghi không khả dụng'; }
            return;
        }
        $path = $this->localRecordingPath($live);
        $size = (int)@filesize((string)$path);
        $mime = (string)($check['meta']['mime'] ?? $live['recording_mime'] ?? 'video/mp4');
        $start = 0; $end = max(0, $size - 1); $partial = false;
        if ($range !== '' && preg_match('/^bytes=(\d*)-(\d*)$/', $range, $m)) {
            if ($m[1] === '' && $m[2] !== '') $start = max(0, $size - (int)$m[2]);
            else { $start = (int)$m[1]; if ($m[2] !== '') $end = min($end, (int)$m[2]); }
            if ($start > $end || $start >= $size) { http_response_code(416); header('Content-Range: bytes */' . $size); return; }
            $partial = true;
        }
        http_response_code($partial ? 206 : 200);
        header('Content-Type: ' . $mime);
        header('Accept-Ranges: bytes');
        header('Content-Length: ' . (string)($end - $start + 1));
        if ($partial) header('Content-Range: bytes ' . $start . '-' . $end . '/' . $size);
        header('Cache-Control: private, no-store');
        if ($method === 'HEAD') return;
        $fh = @fopen((string)$path, 'rb');
        if (!$fh) { http_response_code(404); return; }
        fseek($fh, $start); $remaining = $end - $start + 1;
        while ($remaining > 0 && !feof($fh)) { $chunk = fread($fh, min(1024 * 1024, $remaining)); if ($chunk === false || $chunk === '') break; echo $chunk; $remaining -= strlen($chunk); flush(); }
        fclose($fh);
    }

    public function apiLiveProducts(): void {
        while (ob_get_level() > 0) {
            @ob_end_clean();
        }
        header('Content-Type: application/json; charset=utf-8');
        if (session_status() === PHP_SESSION_ACTIVE) session_write_close();

        global $db;
        $mongoDb = $db ?? $this->pdo;

        $roomId = trim((string)($_GET['id'] ?? $_GET['room_id'] ?? $_GET['room'] ?? ''));
        if ($roomId === '') {
            http_response_code(422);
            echo json_encode(['ok' => false, 'message' => 'Thiếu mã phiên livestream.'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        require_once __DIR__ . '/../models/PhienLive.php';
        $phienLiveModel = new PhienLive($mongoDb);
        $live = $phienLiveModel->findById($roomId);

        if (!$live) {
            echo json_encode(['ok' => false, 'message' => 'Phiên Live không tồn tại']);
            exit;
        }

        if (in_array(($live['recording_status'] ?? ''), ['recording', 'processing'], true)) {
            $live = $this->syncLiveRecording($phienLiveModel, $live)['live'] ?? $live;
        }
        $recordingCheck = $this->recordingAvailability($phienLiveModel, $live);
        $recordingAvailable = !empty($recordingCheck['available']);

        $spModel = new SanPham($mongoDb);
        $pinnedProduct = null;
        if (!empty($live['ma_san_pham_ghim'])) {
            $pinnedProduct = $spModel->findById($live['ma_san_pham_ghim'], true);
        }
        $now = time();
        $deals = $live['danh_sach_deal'];
        $activeOffers = LiveOffer::openDeals($live, $now);
        $activeIds = array_fill_keys(array_column($activeOffers, 'deal_id'), true);
        $dealProducts = $spModel->findManyByIds(array_column($deals, 'ma_san_pham'));
        $publicDeals = [];
        foreach ($deals as $deal) {
            $id = (string)($deal['ma_san_pham'] ?? '');
            if ($id === '' || !isset($dealProducts[$id]) || !$spModel->isProductAvailable($dealProducts[$id])) continue;
            $publicDeals[] = [
                'id' => (string)($deal['deal_id'] ?? ''), 'product_id' => $id,
                'name' => (string)($dealProducts[$id]['ten_san_pham'] ?? ''),
                'brand' => (string)($dealProducts[$id]['thuong_hieu'] ?? ''),
                'image' => resolve_image_url(product_image_source($dealProducts[$id])),
                'original_price' => (float)($dealProducts[$id]['gia_ban'] ?? 0),
                'price' => (float)($deal['gia_uu_dai_live'] ?? 0),
                'limit' => (int)($deal['so_luong_kho_deal'] ?? 0),
                'stock' => $spModel->getProductStock($dealProducts[$id]),
                'ends_at' => (int)($deal['ends_at'] ?? 0),
                'available_now' => $live['status'] === 'live' && isset($activeIds[(string)($deal['deal_id'] ?? '')]),
            ];
        }

        $pinStartedAt = strtotime((string)($live['deal_bat_dau'] ?? '')) ?: 0;
        $pinEndsAt = strtotime((string)($live['deal_ket_thuc'] ?? '')) ?: 0;
        $pinUnlimited = !empty($live['ma_san_pham_ghim']) && $pinEndsAt === 0;
        $pinActive = !empty($live['ma_san_pham_ghim']) && ($pinUnlimited || $pinEndsAt > $now);
        $countdownSeconds = $pinActive && $pinEndsAt > 0 ? max(0, $pinEndsAt - $now) : 0;

        $livePrice = (float)($live['gia_uu_dai_live'] ?? ($pinnedProduct['gia_ban'] ?? 0));
        $originalPrice = (float)($pinnedProduct['gia_ban'] ?? $livePrice);
        $marketPrice = (float)($pinnedProduct['gia_thi_truong'] ?? 0);
        
        $discountPercent = 0;
        if ($marketPrice > $livePrice && $marketPrice > 0) {
            $discountPercent = round((($marketPrice - $livePrice) / $marketPrice) * 100);
        }

        echo json_encode([
            'ok' => true,
            'data' => [
                'live_id' => (string)$live['id'],
                'title' => (string)$live['tieu_de'],
                'status' => (string)($live['status'] ?? 'scheduled'),
                'bat_ai_cohost' => $live['bat_ai_cohost'],
                'stream_source' => (string)($live['stream_source'] ?? ''),
                'streamer_avatar' => account_avatar_url([
                    'avatar_key' => $live['publisher_avatar_key'] ?? '',
                    'avatar_version' => $live['publisher_avatar_version'] ?? '',
                ]),
                'syna_queue' => current_role() === 'admin' && ($_GET['queue'] ?? '') === '1' ? LiveSyna::queued($live['id']) : null,
                'syna_limit' => current_role() === 'admin' && ($_GET['queue'] ?? '') === '1' ? LiveSyna::roomLimit() : null,
                'recording_url' => $recordingAvailable ? $this->recordingPlaybackUrl((string)$live['id']) : '',
                'recording_available' => $recordingAvailable,
                'recording_status' => (string)($live['recording_status'] ?? 'not_started'),
                'recording_mime' => $recordingAvailable ? (string)($recordingCheck['meta']['mime'] ?? $live['recording_mime'] ?? '') : '',
                'recording_size' => $recordingAvailable ? (int)($recordingCheck['meta']['size'] ?? $live['recording_size'] ?? 0) : 0,
                'recording_duration' => (int)($live['recording_duration'] ?? 0),
                'recording_error' => (string)($live['recording_error'] ?? ''),
                'active_voucher' => $live['active_voucher'] ?? null,
                'deals' => $publicDeals,
                'server_time' => $now,
                'pinned_product_id' => $pinActive ? (string)$live['ma_san_pham_ghim'] : '',
                'expired_pinned_product_id' => !$pinActive ? (string)($live['ma_san_pham_ghim'] ?? '') : '',
                'pin_started_at' => $pinStartedAt,
                'pin_ends_at' => $pinEndsAt,
                'pin_duration_minutes' => (int)($live['pin_duration_minutes'] ?? 0),
                'pin_unlimited' => $pinUnlimited,
                'pin_active' => $pinActive,
                'gia_uu_dai_live' => $livePrice,
                'gia_ban_goc' => $originalPrice,
                'gia_thi_truong' => $marketPrice,
                'discount_percent' => $discountPercent,
                'countdown_seconds' => $countdownSeconds,
                'pinned_product' => $pinnedProduct && $pinActive ? [
                    'ma_san_pham' => (string)($pinnedProduct['ma_san_pham'] ?? ''),
                    'ten_san_pham' => (string)($pinnedProduct['ten_san_pham'] ?? ''),
                    'thuong_hieu' => (string)($pinnedProduct['thuong_hieu'] ?? 'SkinSyntax'),
                    'deal_limit' => (int)($live['so_luong_kho_deal'] ?? 0),
                    'link_hinh_anh' => resolve_image_url((string)($pinnedProduct['link_hinh_anh'] ?? '')),
                    'so_luong_ton_kho' => isset($pinnedProduct['so_luong_ton_kho']) && $pinnedProduct['so_luong_ton_kho'] !== '' ? (int)$pinnedProduct['so_luong_ton_kho'] : null,
                    'trang_thai_kho' => (string)($pinnedProduct['trang_thai_kho'] ?? 'con_hang')
                ] : null
            ]
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    public function adminLivePinProduct(): void {
        while (ob_get_level() > 0) {
            @ob_end_clean();
        }
        if (!$this->livePost()) return;

        $role = current_role();
        $liveId = trim((string)($_POST['live_id'] ?? $_GET['live_id'] ?? $_POST['id'] ?? $_GET['id'] ?? ''));
        $productId = trim((string)($_POST['product_id'] ?? $_GET['product_id'] ?? $_POST['ma_san_pham'] ?? $_GET['ma_san_pham'] ?? ''));
        $livePrice = isset($_POST['gia_uu_dai_live']) ? (float)$_POST['gia_uu_dai_live'] : (isset($_GET['gia_uu_dai_live']) ? (float)$_GET['gia_uu_dai_live'] : null);
        $durationRaw = trim((string)($_POST['duration_minutes'] ?? $_GET['duration_minutes'] ?? '15'));
        $durationMinutes = $durationRaw === '0' ? 0 : min(240, max(5, (int)$durationRaw));
        $dealStock = (int)($_POST['so_luong_kho_deal'] ?? $_GET['so_luong_kho_deal'] ?? 0);

        $isAjax = (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest')
            || (!empty($_POST['ajax']) || !empty($_GET['ajax']));

        $targetUrl = BASE_URL . '/index.php?r=live' . ($liveId !== '' ? '&id=' . urlencode($liveId) : '');

        if (!in_array($role, ['admin', 'nhanvien'], true)) {
            if ($isAjax) {
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode(['ok' => false, 'message' => 'Bạn không có quyền thực hiện thao tác này'], JSON_UNESCAPED_UNICODE);
                exit;
            }
            set_flash('error', 'Bạn không có quyền thực hiện thao tác này');
            header('Location: ' . $targetUrl);
            exit;
        }

        if ($liveId === '' || $productId === '' || $dealStock < 1) {
            if ($isAjax) {
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode(['ok' => false, 'message' => 'Vui lòng chọn phiên, sản phẩm và nhập số lượng bán tối đa.'], JSON_UNESCAPED_UNICODE);
                exit;
            }
            set_flash('error', 'Vui lòng chọn phiên, sản phẩm và nhập số lượng bán tối đa.');
            header('Location: ' . $targetUrl);
            exit;
        }

        global $db;
        $mongoDb = $db ?? $this->pdo;
        require_once __DIR__ . '/../models/PhienLive.php';
        $phienLiveModel = new PhienLive($mongoDb);

        $targetLive = $phienLiveModel->getLiveById($liveId);
        if (!$targetLive || !$this->liveAudience($targetLive)['pin']) {
            $this->liveJson(['ok' => false, 'message' => 'Bạn chưa được phân công quản lý sản phẩm của phiên này.'], 403);
            return;
        }
        $st = (string)($targetLive['status'] ?? 'ready');

        if (in_array($st, ['ended', 'replay_ready', 'recording_failed'], true)) {
            if ($isAjax) {
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode(['ok' => false, 'message' => 'Phiên LiveStream này đã KẾT THÚC. Không thể ghim sản phẩm mới!'], JSON_UNESCAPED_UNICODE);
                exit;
            }
            set_flash('error', 'Phiên LiveStream này đã KẾT THÚC. Không thể ghim sản phẩm mới!');
            header('Location: ' . $targetUrl);
            exit;
        }

        $ok = $phienLiveModel->ghimSanPham($liveId, $productId, $livePrice, $durationMinutes, $dealStock);

        if ($isAjax) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode([
                'ok' => $ok,
                'message' => $ok ? ($durationMinutes > 0 ? "Đã ghim sản phẩm #{$productId} trong {$durationMinutes} phút." : "Đã ghim sản phẩm #{$productId} không giới hạn thời gian.") : 'Không thể chọn sản phẩm',
                'live_id' => $liveId,
                'pinned_product_id' => $productId,
                'gia_uu_dai_live' => $livePrice,
                'pin_duration_minutes' => $durationMinutes,
                'pin_started_at' => time(),
                'pin_ends_at' => $durationMinutes > 0 ? time() + ($durationMinutes * 60) : 0,
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            exit;
        }

        set_flash($ok ? 'success' : 'error', $ok ? ($durationMinutes > 0 ? "Đã ghim sản phẩm #{$productId} trong {$durationMinutes} phút." : "Đã ghim sản phẩm #{$productId} không giới hạn thời gian.") : 'Không thể chọn sản phẩm');
        header('Location: ' . $targetUrl);
        exit;
    }

    public function apiUploadLiveRecording(): void {
        if (!$this->livePost()) return;
        global $db;
        $model = new PhienLive($db ?? $this->pdo);
        $room = $model->findById(trim((string)($_POST['live_id'] ?? '')));
        if (!$room || !$this->liveAudience($room)['publish']) {
            $this->liveJson(['ok' => false, 'message' => 'Bạn chưa được phân công lưu bản ghi phiên này.'], 403);
            return;
        }
        if ($room['status'] === 'replay_ready') {
            $available = $this->recordingAvailability($model, $room);
            if (!empty($available['available'])) {
                $this->liveJson(['ok' => true, 'url' => $this->recordingPlaybackUrl((string)$room['id'])]);
            } else {
                $this->liveJson(['ok' => false, 'message' => 'Bản ghi không khả dụng.'], 404);
            }
            return;
        }
        // Keep the upstream incremental-upload contract behind ownership and CSRF guards.
        if (isset($_POST['upload_id'])) {
            if ((string)($_POST['final'] ?? '') === '1' && !in_array($room['status'], ['ended', 'recording_failed'], true)) {
                $this->liveJson(['ok' => false, 'message' => 'Kết thúc phiên trước khi hoàn tất bản ghi.'], 409);
                return;
            }
            $this->handleChunkedRecordingUpload();
            return;
        }
        if (!in_array($room['status'], ['ended', 'recording_failed'], true)) {
            $this->liveJson(['ok' => false, 'message' => 'Phiên phải kết thúc trước khi tải bản ghi.'], 409);
            return;
        }
        $file = $_FILES['video_blob'] ?? [];
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name']) ||
            ($file['size'] ?? 0) < 1 || $file['size'] > 1024 * 1024 * 1024) {
            $this->liveJson(['ok' => false, 'message' => 'Tệp bản ghi trống hoặc vượt mức tải lên.'], 422);
            return;
        }
        $uploadId = (string)($_POST['recording_id'] ?? '');
        if ($uploadId === '') {
            $this->storeRecording($model, $room['id'], $file['tmp_name'], (int)$file['size']);
            return;
        }
        $partSize = 4 * 1024 * 1024;
        $index = filter_var($_POST['part_index'] ?? '', FILTER_VALIDATE_INT);
        $count = filter_var($_POST['part_count'] ?? '', FILTER_VALIDATE_INT);
        if (!preg_match('/^[a-z0-9_-]{12,80}$/iD', $uploadId) || $index === false || $count === false ||
            $count < 1 || $count > 256 || $index < 0 || $index >= $count ||
            $file['size'] > $partSize || ($index !== $count - 1 && $file['size'] !== $partSize)) {
            $this->liveJson(['ok' => false, 'message' => 'Phần bản ghi không hợp lệ.'], 422);
            return;
        }
        $directory = sys_get_temp_dir() . '/skinsyntax-recording-parts';
        if (!is_dir($directory) && !@mkdir($directory, 0700) && !is_dir($directory)) {
            $this->liveJson(['ok' => false, 'message' => 'Không mở được thư mục ghép bản ghi.'], 503);
            return;
        }
        $key = hash('sha256', $_SERVER['DOCUMENT_ROOT'] . ':' . $room['id'] . ':' . session_id() . ':' . $uploadId);
        $path = $directory . '/' . $key . '.part';
        $dest = @fopen($path, 'c+b');
        if (!$dest || !flock($dest, LOCK_EX)) {
            if ($dest) fclose($dest);
            $this->liveJson(['ok' => false, 'message' => 'Không thể ghi phần bản ghi.'], 503);
            return;
        }
        $completed = false;
        try {
            $start = $index * $partSize;
            $bytes = fstat($dest)['size'];
            if ($bytes === $start) {
                $source = fopen($file['tmp_name'], 'rb');
                if (!$source || fseek($dest, $start) !== 0) throw new RuntimeException('Cannot read recording part');
                try {
                    if (stream_copy_to_stream($source, $dest) !== (int)$file['size'] || !fflush($dest)) {
                        ftruncate($dest, $start);
                        throw new RuntimeException('Cannot append recording part');
                    }
                } finally {
                    fclose($source);
                }
            } elseif ($bytes < $start + $file['size'] || fseek($dest, $start) !== 0 ||
                hash('sha256', fread($dest, $file['size'])) !== hash_file('sha256', $file['tmp_name'])) {
                $this->liveJson(['ok' => false, 'message' => 'Phần bản ghi bị thiếu hoặc đã thay đổi.'], 409);
                return;
            }
            if ($index < $count - 1) {
                $this->liveJson(['ok' => true]);
                return;
            }
            $completeSize = $start + $file['size'];
            if (fstat($dest)['size'] !== $completeSize) {
                $this->liveJson(['ok' => false, 'message' => 'Độ dài bản ghi không khớp.'], 409);
                return;
            }
            $completed = $this->storeRecording($model, $room['id'], $path, $completeSize, $dest);
        } catch (Throwable $e) {
            $this->liveJson(['ok' => false, 'message' => 'Không thể ghép bản ghi.'], 503);
        } finally {
            flock($dest, LOCK_UN);
            fclose($dest);
            if ($completed) unlink($path);
        }
    }

    private function storeRecording(PhienLive $model, string $room, string $source, int $size, $parts = null): bool {
        if ($parts) {
            rewind($parts);
            $mime = (new finfo(FILEINFO_MIME_TYPE))->buffer(fread($parts, min($size, 32768)));
        } else $mime = mime_content_type($source);
        $extension = ['video/webm' => 'webm', 'video/mp4' => 'mp4', 'video/ogg' => 'ogv'][$mime] ?? null;
        if (!$extension) {
            $model->failRecording($room, 'Không nhận dạng được định dạng video.');
            $this->liveJson(['ok' => false, 'message' => 'Chỉ chấp nhận bản ghi WebM, MP4 hoặc OGG.'], 415);
            return false;
        }
        $directory = rtrim((string)$_SERVER['DOCUMENT_ROOT'], '/\\') . '/uploads/live_recordings';
        if (!is_dir($directory) && !@mkdir($directory, 0755, true) && !is_dir($directory)) {
            $this->liveJson(['ok' => false, 'message' => 'Không mở được thư mục lưu bản ghi.'], 503);
            return false;
        }
        $filename = 'live_rec_' . bin2hex(random_bytes(16)) . '.' . $extension;
        $target = $directory . '/' . $filename;
        if ($parts) {
            rewind($parts);
            $output = @fopen($target, 'xb');
            $saved = $output && stream_copy_to_stream($parts, $output) === $size;
            if ($output) fclose($output);
        } else $saved = move_uploaded_file($source, $target);
        if (!$saved) {
            if (is_file($target)) unlink($target);
            $this->liveJson(['ok' => false, 'message' => 'Không thể lưu tệp bản ghi.'], 503);
            return false;
        }
        $relativePath = 'uploads/live_recordings/' . $filename;
        $url = $this->recordingPlaybackUrl($room);
        if (!$model->saveRecording($room, $url, $mime, $size, [
            'verified' => true,
            'recording_file_path' => $relativePath,
            'recording_storage' => 'local',
            'recording_ended_at' => time()
        ])) {
            unlink($target);
            $this->liveJson(['ok' => false, 'message' => 'Không lưu được thông tin bản ghi.'], 503);
            return false;
        }
        $this->liveJson(['ok' => true, 'url' => $url]);
        return true;
    }


    /**
     * Chunked append upload for live recordings (called from apiUploadLiveRecording
     * when POST upload_id is present). Chunks must arrive in order (seq 0,1,2...).
     */
    private function handleChunkedRecordingUpload(): void {
        $reply = static function (int $code, array $body): void {
            http_response_code($code);
            echo json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            exit;
        };

        $liveId = trim((string)($_POST['live_id'] ?? $_GET['live_id'] ?? ''));
        $uploadId = trim((string)($_POST['upload_id'] ?? ''));
        $seqRaw = (string)($_POST['seq'] ?? '');
        $finalRaw = (string)($_POST['final'] ?? '');
        if ($liveId === '' || !preg_match('/^[A-Za-z0-9-]{8,64}$/', $uploadId)
            || !preg_match('/^\d+$/', $seqRaw) || !in_array($finalRaw, ['0', '1'], true)) {
            $reply(422, ['ok' => false, 'message' => 'Tham số upload bản ghi không hợp lệ.']);
        }
        $seq = (int)$seqRaw;
        $final = $finalRaw === '1';

        global $db;
        $mongoDb = $db ?? $this->pdo;
        require_once __DIR__ . '/../models/PhienLive.php';
        $model = new PhienLive($mongoDb);
        $live = $model->findById($liveId);
        if (!$live) {
            $reply(404, ['ok' => false, 'message' => 'Phiên livestream không tồn tại.']);
        }
        // failRecording() sets trang_thai=recording_failed, which would end a stream that is
        // still on air. Mid-stream chunk errors only fail this request; the client then
        // finalizes with what the server already has once the host ends the session.
        $failRec = static function (string $message) use ($final, $model, $liveId): void {
            if ($final) $model->failRecording($liveId, $message);
        };
        // A retried final call after a successful finalize must stay idempotent.
        if ($final && ($live['status'] ?? '') === 'replay_ready') {
            $available = $this->recordingAvailability($model, $live);
            if (!empty($available['available'])) {
                $reply(200, ['ok' => true, 'url' => $this->recordingPlaybackUrl((string)$live['id']), 'message' => 'Bản ghi đã được lưu trước đó.']);
            }
            $reply(404, ['ok' => false, 'message' => 'Bản ghi không khả dụng.']);
        }

        $file = $_FILES['video_blob'] ?? null;
        $fileError = $file ? (int)($file['error'] ?? UPLOAD_ERR_NO_FILE) : UPLOAD_ERR_NO_FILE;
        if ($file && !in_array($fileError, [UPLOAD_ERR_OK, UPLOAD_ERR_NO_FILE], true)) {
            $reply(422, ['ok' => false, 'message' => 'Upload thất bại với mã lỗi ' . $fileError]);
        }
        $hasChunk = $file && $fileError === UPLOAD_ERR_OK && (int)($file['size'] ?? 0) > 0;
        if (!$hasChunk && !$final) {
            $reply(422, ['ok' => false, 'message' => 'Thiếu dữ liệu video của đoạn recording.']);
        }

        $baseDir = rtrim((string)$_SERVER['DOCUMENT_ROOT'], '/\\') . '/uploads/live_recordings';
        $tmpDir = sys_get_temp_dir() . '/skinsyntax-live-recording-chunks';
        if (!is_dir($tmpDir)) @mkdir($tmpDir, 0700, true);
        if (!is_dir($baseDir)) @mkdir($baseDir, 0755, true);
        $safeLiveId = preg_replace('/[^A-Za-z0-9_-]/', '', $liveId);
        $stem = $tmpDir . '/' . hash('sha256', $_SERVER['DOCUMENT_ROOT'] . ':' . $live['id'] . ':' . session_id() . ':' . $uploadId);
        $partPath = $stem . '.part';
        $metaPath = $stem . '.json';
        $maxTotal = 2 * 1024 * 1024 * 1024;

        $metaFh = @fopen($metaPath, 'c+');
        if (!$metaFh || !flock($metaFh, LOCK_EX)) {
            if ($metaFh) fclose($metaFh);
            $failRec('Không thể khóa file tạm của recording.');
            $reply(500, ['ok' => false, 'message' => 'Không thể ghi file tạm recording.']);
        }
        $release = static function () use ($metaFh): void {
            flock($metaFh, LOCK_UN);
            fclose($metaFh);
        };
        $meta = json_decode((string)stream_get_contents($metaFh), true);
        if (!is_array($meta)) $meta = ['next_seq' => 0, 'bytes' => 0, 'mime' => ''];
        $nextSeq = (int)($meta['next_seq'] ?? 0);

        if ($seq > $nextSeq) {
            $release();
            $reply(409, ['ok' => false, 'code' => 'out_of_order', 'expected' => $nextSeq, 'message' => 'Đoạn recording không đúng thứ tự.']);
        }

        if ($seq === $nextSeq && $hasChunk) {
            if ($seq === 0) {
                $mime = function_exists('mime_content_type') ? (string)(mime_content_type($file['tmp_name']) ?: $file['type']) : (string)$file['type'];
                $mimeBase = strtolower(trim(explode(';', $mime)[0]));
                if (!in_array($mimeBase, ['video/webm', 'video/mp4', 'video/ogg'], true)) {
                    $release();
                    @unlink($metaPath);
                    @unlink($partPath);
                    $failRec('Định dạng recording không được hỗ trợ: ' . $mimeBase);
                    $reply(415, ['ok' => false, 'message' => 'Chỉ chấp nhận recording WebM, MP4 hoặc OGG.']);
                }
                $meta['mime'] = $mimeBase;
            }
            $size = (int)$file['size'];
            if ((int)($meta['bytes'] ?? 0) + $size > $maxTotal) {
                $release();
                @unlink($metaPath);
                @unlink($partPath);
                $failRec('Bản ghi vượt quá giới hạn 2 GB.');
                $reply(413, ['ok' => false, 'message' => 'Bản ghi vượt quá giới hạn 2 GB.']);
            }
            $in = @fopen($file['tmp_name'], 'rb');
            $out = @fopen($partPath, 'ab');
            $copied = ($in && $out) ? stream_copy_to_stream($in, $out) : false;
            if ($in) fclose($in);
            if ($out) fclose($out);
            if ($copied === false || $copied !== $size) {
                $release();
                $failRec('Không thể ghi đoạn recording vào vùng lưu trữ tạm.');
                $reply(500, ['ok' => false, 'message' => 'Không thể ghi đoạn recording.']);
            }
            $meta['bytes'] = (int)($meta['bytes'] ?? 0) + $size;
            $meta['next_seq'] = $nextSeq + 1;
            ftruncate($metaFh, 0);
            rewind($metaFh);
            fwrite($metaFh, json_encode($meta));
            fflush($metaFh);
        }
        // seq < next_seq: idempotent retry, nothing appended.

        if (!$final) {
            $release();
            $reply(200, ['ok' => true, 'seq' => $seq, 'next_seq' => (int)($meta['next_seq'] ?? 0)]);
        }

        $totalBytes = (int)($meta['bytes'] ?? 0);
        if ($totalBytes <= 0 || !is_file($partPath)) {
            $release();
            @unlink($metaPath);
            @unlink($partPath);
            $failRec('Không có dữ liệu video được ghi.');
            $reply(422, ['ok' => false, 'message' => 'Không có dữ liệu video được ghi.']);
        }

        $mimeBase = (string)($meta['mime'] ?? 'video/webm');
        $ext = $mimeBase === 'video/mp4' ? 'mp4' : ($mimeBase === 'video/ogg' ? 'ogv' : 'webm');
        $filename = 'live_rec_' . $safeLiveId . '_' . time() . '.' . $ext;
        $targetPath = $baseDir . '/' . $filename;
        if (!@rename($partPath, $targetPath)) {
            $release();
            $failRec('Không thể lưu file recording vào vùng lưu trữ bền vững.');
            $reply(500, ['ok' => false, 'message' => 'Không thể hoàn tất file video đã quay']);
        }
        $release();
        @unlink($metaPath);

        $relativePath = 'uploads/live_recordings/' . $filename;
        $publicUrl = $this->recordingPlaybackUrl($liveId);
        $saved = $model->saveRecording($liveId, $publicUrl, $mimeBase, $totalBytes, [
            'verified' => true,
            'recording_file_path' => $relativePath,
            'recording_storage' => 'local',
            'recording_ended_at' => time()
        ]);
        if (!$saved) {
            @unlink($targetPath);
            $reply(503, ['ok' => false, 'message' => 'Không lưu được thông tin bản ghi.']);
        }
        $reply(200, [
            'ok' => true,
            'url' => $publicUrl,
            'message' => 'Đã tự động lưu bản ghi video Livestream thành công!'
        ]);
    }

    public function adminLiveAddDeal(): void {
        while (ob_get_level() > 0) {
            @ob_end_clean();
        }
        if (!$this->livePost()) return;

        $role = current_role();
        if (!in_array($role, ['admin', 'nhanvien'], true)) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['ok' => false, 'message' => 'Bạn không có quyền thực hiện thao tác này']);
            exit;
        }

        $liveId = trim((string)($_POST['live_id'] ?? $_GET['live_id'] ?? ''));
        $productId = trim((string)($_POST['product_id'] ?? $_GET['product_id'] ?? ''));
        $price = (float)($_POST['gia_uu_dai_live'] ?? $_GET['gia_uu_dai_live'] ?? 0);
        $qty = (int)($_POST['so_luong_kho_deal'] ?? 0);
        $start = trim((string)($_POST['khung_gio_bat_dau'] ?? date('H:i')));
        $end = trim((string)($_POST['khung_gio_ket_thuc'] ?? date('H:i', strtotime('+30 minutes'))));
        $isAjax = (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest')
            || !empty($_POST['ajax']);

        if ($liveId === '' || $productId === '' || $qty < 1 || $price <= 0) {
            if ($isAjax) {
                $this->liveJson(['ok' => false, 'message' => 'Hãy chọn sản phẩm, nhập giá Live và số lượng bán hợp lệ.'], 422);
                return;
            }
            set_flash('error', 'Vui lòng chọn phiên, sản phẩm và nhập số lượng bán tối đa.');
            header('Location: ' . BASE_URL . '/index.php?r=admin_lives');
            exit;
        }

        global $db;
        $mongoDb = $db ?? $this->pdo;
        require_once __DIR__ . '/../models/PhienLive.php';
        $phienLiveModel = new PhienLive($mongoDb);

        $live = $phienLiveModel->findById($liveId);
        if (!$live || !$this->liveAudience($live)['pin']) {
            $this->liveJson(['ok' => false, 'message' => 'Bạn chưa được phân công quản lý sản phẩm của phiên này.'], 403);
            return;
        }
        require_once __DIR__ . '/../models/SanPham.php';
        $catalog = new SanPham($mongoDb);
        $product = $catalog->findById($productId, true);
        $stock = $product ? $catalog->getProductStock($product) : null;
        if (!$product || !$catalog->isProductAvailable($product) || ($stock !== null && $qty > $stock)) {
            $this->liveJson(['ok' => false, 'message' => 'Sản phẩm không còn bán hoặc số lượng Live vượt tồn kho hiện tại.'], 422);
            return;
        }
        $ok = $phienLiveModel->themDealSanPham($liveId, [
            'ma_san_pham' => $productId,
            'gia_uu_dai_live' => $price,
            'so_luong_kho_deal' => $qty,
            'khung_gio_bat_dau' => $start,
            'khung_gio_ket_thuc' => $end,
            'starts_at' => isset($_POST['duration_minutes']) ? time() : ($start !== '' ? (strtotime($start) ?: 0) : 0),
            'ends_at' => isset($_POST['duration_minutes'])
                ? ((int)$_POST['duration_minutes'] === 0 ? 0 : time() + min(240, max(5, (int)$_POST['duration_minutes'])) * 60)
                : ($end !== '' ? (strtotime($end . ((strcmp($end, $start) < 0) ? ' +1 day' : '')) ?: 0) : 0),
            'trang_thai' => 'sap_dien_ra'
        ]);

        if ($isAjax) {
            $this->liveJson([
                'ok' => $ok,
                'message' => $ok ? 'Đã thêm sản phẩm vào phiên phát.' : 'Không thể thêm sản phẩm.',
                'product' => $ok ? [
                    'id' => $productId,
                    'name' => (string)($product['ten_san_pham'] ?? ''),
                    'brand' => (string)($product['thuong_hieu'] ?? ''),
                    'image' => resolve_image_url(product_image_source($product)),
                    'original_price' => (float)($product['gia_ban'] ?? 0),
                    'live_price' => $price,
                    'stock' => $stock,
                    'deal_limit' => $qty,
                ] : null,
            ], $ok ? 200 : 422);
            return;
        }

        $redirectUrl = !empty($_POST['redirect_live']) ? (BASE_URL . '/index.php?r=live&id=' . urlencode($liveId)) : (BASE_URL . '/index.php?r=admin_lives');
        set_flash($ok ? 'success' : 'error', $ok ? 'Đã thêm sản phẩm ưu đãi mới vào phiên phát!' : 'Không thể thêm sản phẩm');
        header('Location: ' . $redirectUrl);
        exit;
    }


    public function adminLiveDeleteDeal(): void {
        while (ob_get_level() > 0) {
            @ob_end_clean();
        }
        if (!$this->livePost()) return;

        $role = current_role();
        if (!in_array($role, ['admin', 'nhanvien'], true)) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['ok' => false, 'message' => 'Bạn không có quyền thực hiện thao tác này']);
            exit;
        }

        $liveId = trim((string)($_POST['live_id'] ?? $_GET['live_id'] ?? ''));
        $dealId = trim((string)($_POST['deal_id'] ?? $_GET['deal_id'] ?? ''));
        $isAjax = (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest')
            || !empty($_POST['ajax']);

        global $db;
        $mongoDb = $db ?? $this->pdo;
        require_once __DIR__ . '/../models/PhienLive.php';
        $phienLiveModel = new PhienLive($mongoDb);

        $live = $phienLiveModel->findById($liveId);
        if (!$live || !$this->liveAudience($live)['pin']) {
            $this->liveJson(['ok' => false, 'message' => 'Bạn chưa được phân công quản lý sản phẩm của phiên này.'], 403);
            return;
        }
        $ok = $phienLiveModel->xoaDealSanPham($liveId, $dealId);

        if ($isAjax) {
            $this->liveJson(['ok' => $ok, 'message' => $ok ? 'Đã xóa sản phẩm khỏi phiên phát.' : 'Không thể xóa deal.'], $ok ? 200 : 422);
            return;
        }

        set_flash($ok ? 'success' : 'error', $ok ? 'Đã xóa sản phẩm khỏi danh sách deal Live!' : 'Không thể xóa deal');
        header('Location: ' . BASE_URL . '/index.php?r=admin_lives');
        exit;
    }

    public function apiLiveAddToCart(): void {
        while (ob_get_level() > 0) {
            @ob_end_clean();
        }
        header('Content-Type: application/json; charset=utf-8');
        if (!$this->livePost()) return;

        global $db;
        $mongoDb = $db ?? $this->pdo;

        $liveId = trim((string)($_POST['live_id'] ?? $_GET['live_id'] ?? $_POST['room_id'] ?? $_GET['room_id'] ?? $_POST['id'] ?? $_GET['id'] ?? ''));
        $productId = trim((string)($_POST['product_id'] ?? $_GET['product_id'] ?? $_POST['ma_san_pham'] ?? $_GET['ma_san_pham'] ?? ''));
        $qty = max(1, (int)($_POST['quantity'] ?? $_GET['quantity'] ?? $_POST['qty'] ?? $_GET['qty'] ?? 1));

        $liveDoc = $liveId !== '' ? (new PhienLive($mongoDb))->findById($liveId) : null;
        $campaignId = (string)($_POST['campaign_id'] ?? '');
        $aiLive = !$liveDoc && preg_match('/^[a-f0-9]{24}$/iD', $campaignId) ? $this->findAiIdolLive($mongoDb, $campaignId) : null;
        if ((!$liveDoc || $liveDoc['status'] !== 'live') && !$aiLive) {
            $this->liveJson(['ok' => false, 'message' => 'Phiên livestream hiện chưa phát hoặc đã kết thúc.'], 409);
            return;
        }
        $deal = $liveDoc ? LiveOffer::find($liveDoc, $productId, time(), (string)($_POST['deal_id'] ?? '')) : null;
        $limit = (int)($deal['so_luong_kho_deal'] ?? 0);
        $offered = $deal !== null || in_array($productId, $aiLive['product_ids'] ?? [], true);
        $catalog = new SanPham($mongoDb);
        $product = $productId !== '' && $offered ? $catalog->findById($productId, true) : null;
        if (!$product || !$catalog->isProductAvailable($product)) {
            $this->liveJson(['ok' => false, 'message' => 'Sản phẩm đã hết hàng hoặc không còn trong phiên.'], 422);
            return;
        }
        $stock = $catalog->getProductStock($product);
        $total = (int)($_SESSION['gio_hang'][$productId] ?? 0) + $qty;
        if (($stock !== null && $total > $stock) || ($limit > 0 && $total > $limit)) {
            $this->liveJson(['ok' => false, 'message' => 'Số lượng vượt tồn kho hoặc giới hạn mua của phiên.'], 422);
            return;
        }

        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        if (!isset($_SESSION['gio_hang']) || !is_array($_SESSION['gio_hang'])) {
            $_SESSION['gio_hang'] = [];
        }

        $_SESSION['gio_hang'][$productId] = $total;
        if ($deal !== null) {
            $_SESSION['live_cart_offers'][$productId] = [
                'room' => (string)$liveDoc['id'], 'deal' => (string)($deal['deal_id'] ?? ''), 'quantity' => $total,
            ];
        }
        ProductEventTracker::record($mongoDb, 'add_to_cart', $productId);

        $cartCount = 0;
        foreach ($_SESSION['gio_hang'] as $q) {
            $cartCount += (int)$q;
        }

        echo json_encode([
            'ok' => true,
            'message' => ' Đã thêm sản phẩm ưu đãi Live vào giỏ hàng thành công!',
            'cart_count' => $cartCount,
            'product_id' => $productId,
            'quantity' => $qty
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    public function apiLivePing(): void {
        while (ob_get_level() > 0) {
            @ob_end_clean();
        }
        header('Content-Type: application/json; charset=utf-8');

        $roomId = trim((string)($_GET['room'] ?? $_POST['room'] ?? $_GET['id'] ?? $_POST['id'] ?? ''));
        if ($roomId === '') {
            http_response_code(422);
            echo json_encode(['ok' => false, 'message' => 'Thiếu mã phiên livestream.'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        $sessionId = session_id() ?: 'guest_' . substr(hash('sha256', (string)($_SERVER['REMOTE_ADDR'] ?? '') . '|' . (string)($_SERVER['HTTP_USER_AGENT'] ?? '')), 0, 20);

        global $db;
        $mongoDb = $db ?? $this->pdo;

        $now = time();
        $cutoff = $now - self::VIEWER_ACTIVE_SECONDS;
        $activeCount = 0;

        require_once __DIR__ . '/../models/PhienLive.php';
        $phienLiveModel = new PhienLive($mongoDb);
        $live = $phienLiveModel->findById($roomId);
        if (!$live || ($live['status'] ?? '') !== 'live') {
            echo json_encode(['ok' => true, 'room_id' => $roomId, 'active_viewers' => 0, 'stream_status' => $live['status'] ?? 'missing', 'pinned_product_id' => (string)($live['ma_san_pham_ghim'] ?? '')], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            exit;
        }

        try {
            if (is_object($mongoDb)) { // MongoDB\Database has no __isset, so isset($db->coll) is always false
                $mongoDb->phien_live_viewers->updateOne(
                    ['room_id' => (string)$roomId, 'session_id' => (string)$sessionId],
                    ['$set' => ['last_seen' => $now, 'room_id' => (string)$roomId, 'session_id' => (string)$sessionId]],
                    ['upsert' => true]
                );
                $countViewers = static fn() => $mongoDb->phien_live_viewers->countDocuments([
                    'room_id' => (string)$roomId,
                    'last_seen' => ['$gte' => $cutoff]
                ]);
                try {
                    $activeCount = (int)CacheService::getInstance()->remember('live:viewers:' . $roomId, 5, $countViewers);
                } catch (Throwable $cacheError) {
                    $activeCount = (int)$countViewers();
                }
                if (empty($_SESSION['counted_live_view_' . $roomId])) {
                    $_SESSION['counted_live_view_' . $roomId] = true;
                    $phienLiveModel->tangLuotXem($roomId);
                }
            }
        } catch (Throwable $e) {
            $activeCount = 0;
        }

        echo json_encode([
            'ok' => true,
            'room_id' => $roomId,
            'active_viewers' => max(0, (int)$activeCount),
            'stream_status' => 'live',
            'pinned_product_id' => (string)($live['ma_san_pham_ghim'] ?? '')
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    public function apiLiveActiveViewers(): void {
        while (ob_get_level() > 0) {
            @ob_end_clean();
        }
        header('Content-Type: application/json; charset=utf-8');

        global $db;
        $mongoDb = $db ?? $this->pdo;

        $now = time();
        $cutoff = $now - self::VIEWER_ACTIVE_SECONDS;
        $counts = [];

        try {
            if (is_object($mongoDb)) { // MongoDB\Database has no __isset, so isset($db->coll) is always false
                $cursor = $mongoDb->phien_live_viewers->aggregate([
                    ['$match' => ['last_seen' => ['$gte' => $cutoff]]],
                    ['$group' => ['_id' => '$room_id', 'total' => ['$sum' => 1]]]
                ]);
                foreach ($cursor as $doc) {
                    $counts[(string)$doc['_id']] = (int)$doc['total'];
                }
            }
        } catch (Throwable $e) {
            // Silence
        }

        echo json_encode([
            'ok' => true,
            'counts' => $counts
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    public function studio(): void {
        $user = current_user();
        $role = current_role();
        if (!in_array($role, ['admin', 'nhanvien'], true)) {
            redirect(BASE_URL . '/index.php?r=admin_login');
            return;
        }

        $liveId = trim((string)($_GET['id'] ?? $_GET['room_id'] ?? ''));
        if ($liveId === '') {
            redirect(BASE_URL . '/index.php?r=admin_lives');
            return;
        }

        global $db;
        $mongoDb = $db ?? $this->pdo;
        require_once __DIR__ . '/../models/PhienLive.php';
        require_once __DIR__ . '/../models/SanPham.php';
        require_once __DIR__ . '/../models/Voucher.php';

        $phienLiveModel = new PhienLive($mongoDb);
        $live = $phienLiveModel->findById($liveId);
        if (!$live) {
            set_flash('error', 'Phiên livestream không tồn tại.');
            redirect(BASE_URL . '/index.php?r=admin_lives');
            return;
        }

        $spModel = new SanPham($mongoDb);
        $dealProductIds = array_values(array_filter(array_map(
            static fn(array $deal): string => trim((string)($deal['ma_san_pham'] ?? '')),
            (array)($live['danh_sach_deal'] ?? [])
        )));
        $dealProducts = $dealProductIds ? $spModel->findManyByIds($dealProductIds) : [];
        $enrichedDeals = [];
        foreach ((array)($live['danh_sach_deal'] ?? []) as $deal) {
            $productId = trim((string)($deal['ma_san_pham'] ?? ''));
            $product = $dealProducts[$productId] ?? null;
            $deal['ten_san_pham'] = (string)($product['ten_san_pham'] ?? $productId);
            $deal['thuong_hieu'] = (string)($product['thuong_hieu'] ?? '');
            $deal['gia_kho'] = (float)($product['gia_ban'] ?? 0);
            $deal['hinh_anh'] = $product ? resolve_image_url(product_image_source($product)) : '';
            $deal['ton_kho'] = $product ? $spModel->getProductStock($product) : null;
            $enrichedDeals[] = $deal;
        }
        $live['danh_sach_deal'] = $enrichedDeals;

        $pinnedProduct = null;
        if (!empty($live['ma_san_pham_ghim'])) {
            $pinnedProduct = $spModel->findById($live['ma_san_pham_ghim'], true);
        }

        $voucherModel = new Voucher($mongoDb);
        $availableVouchers = $voucherModel->listVouchers();

        // The studio must never render the legacy URL field as a playable
        // recording. Reuse the same local/R2 verification gate as playback
        // and the admin list so stale historical metadata cannot create a
        // broken replay button.
        $recordingCheck = $this->recordingAvailability($phienLiveModel, $live);
        $live['recording_available'] = !empty($recordingCheck['available']);
        $live['recording_url'] = $live['recording_available'] ? $this->recordingPlaybackUrl((string)$live['id']) : '';

        if (session_status() === PHP_SESSION_NONE) session_start();
        $_SESSION['live_csrf'] ??= bin2hex(random_bytes(24));

        $pageTitle = 'Live Studio - ' . ($live['tieu_de'] ?? 'SkinSyntax Live');
        $viewDir = defined('VIEW_DIR') ? VIEW_DIR : __DIR__ . '/../views';
        $studioFile = $viewDir . '/live_studio.php';
        if (is_file($studioFile)) {
            require_once $studioFile;
        } else {
            $_GET['studio'] = '1';
            $this->index();
        }
    }

    public function apiLiveClaimVoucher(): void {
        while (ob_get_level() > 0) @ob_end_clean();
        header('Content-Type: application/json; charset=utf-8');

        $liveId = trim((string)($_POST['room_id'] ?? $_POST['live_id'] ?? $_GET['id'] ?? ''));
        if ($liveId === '') {
            $this->liveJson(['ok' => false, 'message' => 'Thiếu mã phiên live.'], 422);
            return;
        }

        global $db;
        $mongoDb = $db ?? $this->pdo;
        require_once __DIR__ . '/../models/PhienLive.php';
        require_once __DIR__ . '/../models/Voucher.php';
        require_once __DIR__ . '/../models/CustomerVoucher.php';

        $phienLiveModel = new PhienLive($mongoDb);
        $live = $phienLiveModel->findById($liveId);
        if (!$live || empty($live['active_voucher'])) {
            $this->liveJson(['ok' => false, 'message' => 'Hiện tại không có voucher nào đang phát trong phiên.'], 404);
            return;
        }

        $activeVoucher = (array)$live['active_voucher'];
        $code = trim((string)($activeVoucher['ma_code'] ?? ''));
        if ($code === '') {
            $this->liveJson(['ok' => false, 'message' => 'Voucher không hợp lệ.'], 422);
            return;
        }

        $voucherModel = new Voucher($mongoDb);
        $voucherDoc = $voucherModel->getVoucherByCode($code);
        if (!$voucherDoc) {
            $this->liveJson(['ok' => false, 'message' => 'Voucher không tồn tại trên hệ thống.'], 404);
            return;
        }

        if (($voucherDoc['trang_thai'] ?? '') !== 'active') {
            $this->liveJson(['ok' => false, 'message' => 'Voucher đã tạm ngưng hoặc kết thúc.'], 400);
            return;
        }
        $now = time();
        if (!empty($voucherDoc['ngay_ket_thuc']) && strtotime($voucherDoc['ngay_ket_thuc']) < $now) {
            $this->liveJson(['ok' => false, 'message' => 'Voucher đã hết hạn sử dụng.'], 400);
            return;
        }

        $totalQty = $voucherDoc['so_luong'] ?? null;
        $usedQty = (int)($voucherDoc['so_luong_da_dung'] ?? 0);
        if ($totalQty !== null && $usedQty >= $totalQty) {
            $this->liveJson(['ok' => false, 'message' => 'Voucher đã được nhận hết số lượng giới hạn.'], 400);
            return;
        }

        if (session_status() === PHP_SESSION_NONE) session_start();
        $isLoggedIn = is_logged_in();
        $user = current_user() ?? [];
        $userId = $user['ma_nd'] ?? $user['id'] ?? null;

        $_SESSION['claimed_live_vouchers'] ??= [];
        if (!$isLoggedIn && isset($_SESSION['claimed_live_vouchers'][$code])) {
            $this->liveJson(['ok' => true, 'already_claimed' => true, 'code' => $code, 'message' => 'Bạn đã nhận voucher này rồi.']);
            return;
        }

        if ($isLoggedIn && $userId) {
            $existing = $mongoDb->customer_vouchers->findOne([
                'customer_id' => (string)$userId,
                'voucher_id' => (int)$voucherDoc['ma_voucher']
            ]);
            if ($existing) {
                $this->liveJson(['ok' => true, 'already_claimed' => true, 'code' => $code, 'message' => 'Bạn đã lưu voucher này vào ví rồi! Hãy dùng khi thanh toán.']);
                return;
            }

            try {
                $mongoDb->customer_vouchers->insertOne([
                    'customer_id' => (string)$userId,
                    'voucher_id' => (int)$voucherDoc['ma_voucher'],
                    'code' => $code,
                    'status' => 'ACTIVE',
                    'claimed_at' => date('Y-m-d H:i:s'),
                    'source' => 'livestream',
                    'live_room_id' => $liveId
                ]);
            } catch (Throwable $e) {}
        }

        $_SESSION['claimed_live_vouchers'][$code] = [
            'code' => $code,
            'name' => $voucherDoc['ten_voucher'] ?? $code,
            'discount' => $voucherDoc['gia_tri_giam'] ?? 0,
            'type' => $voucherDoc['loai_giam'] ?? 'fixed',
            'min_order' => $voucherDoc['gia_tri_don_toi_thieu'] ?? 0,
            'claimed_at' => time()
        ];

        $mongoDb->voucher->updateOne(
            ['ma_code' => $code],
            ['$inc' => ['so_luong_da_dung' => 1]]
        );

        $this->liveJson([
            'ok' => true,
            'code' => $code,
            'name' => $voucherDoc['ten_voucher'] ?? $code,
            'discount' => $voucherDoc['gia_tri_giam'] ?? 0,
            'type' => $voucherDoc['loai_giam'] ?? 'fixed',
            'min_order' => $voucherDoc['gia_tri_don_toi_thieu'] ?? 0,
            'message' => 'Chúc mừng! Đã nhận voucher ' . $code . ' thành công! Mã đã lưu vào phiên mua sắm.'
        ]);
    }

    public function apiLiveQuickVoucher(): void {
        while (ob_get_level() > 0) @ob_end_clean();
        header('Content-Type: application/json; charset=utf-8');
        if (!is_logged_in() || current_role() !== 'admin') {
            $this->liveJson(['ok' => false, 'message' => 'Bạn không có quyền quản lý voucher.'], 403);
            return;
        }
        if (!$this->livePost()) return;

        global $db;
        $mongoDb = $db ?? $this->pdo;
        require_once __DIR__ . '/../models/PhienLive.php';
        require_once __DIR__ . '/../models/Voucher.php';

        $phienLiveModel = new PhienLive($mongoDb);
        $liveId = trim((string)($_POST['room_id'] ?? $_POST['live_id'] ?? ''));
        $live = $phienLiveModel->findById($liveId);
        if (!$live) {
            $this->liveJson(['ok' => false, 'message' => 'Phiên live không tồn tại.'], 404);
            return;
        }

        $code = strtoupper(trim(preg_replace('/\s+/u', '', (string)($_POST['ma_code'] ?? ''))));
        $name = trim((string)($_POST['ten_voucher'] ?? ''));
        $type = strtolower(trim((string)($_POST['loai_giam'] ?? 'fixed')));
        $value = (float)($_POST['gia_tri_giam'] ?? 0);
        $minOrder = (float)($_POST['gia_tri_don_toi_thieu'] ?? 0);
        $maxDiscount = !empty($_POST['giam_toi_da']) ? (float)$_POST['giam_toi_da'] : null;
        $quantity = !empty($_POST['so_luong']) ? (int)$_POST['so_luong'] : 100;
        $durationMinutes = max(5, (int)($_POST['thoi_luong_phut'] ?? 30));

        if ($code === '' || $name === '' || $value <= 0) {
            $this->liveJson(['ok' => false, 'message' => 'Vui lòng nhập đầy đủ mã, tên và giá trị giảm.'], 422);
            return;
        }

        $startAt = date('Y-m-d H:i:s');
        $endAt = date('Y-m-d H:i:s', strtotime("+{$durationMinutes} minutes"));

        $voucherModel = new Voucher($mongoDb);
        $existing = $voucherModel->getVoucherByCode($code);
        if (!$existing) {
            $saveOk = $voucherModel->saveVoucher([
                'ma_code' => $code,
                'ten_voucher' => $name,
                'mo_ta' => "Voucher độc quyền phiên livestream #{$liveId}",
                'loai_giam' => $type,
                'gia_tri_giam' => $value,
                'gia_tri_don_toi_thieu' => $minOrder,
                'giam_toi_da' => $maxDiscount,
                'so_luong' => $quantity,
                'trang_thai' => 'active',
                'ngay_bat_dau' => $startAt,
                'ngay_ket_thuc' => $endAt,
            ]);
            if (!$saveOk) {
                $this->liveJson(['ok' => false, 'message' => $voucherModel->getLastErrorMessage() ?: 'Không thể tạo voucher.'], 422);
                return;
            }
        }

        $voucherData = [
            'ma_code' => $code,
            'ten_voucher' => $name,
            'loai_giam' => $type,
            'gia_tri_giam' => $value,
            'giam_toi_da' => $maxDiscount,
            'gia_tri_don_toi_thieu' => $minOrder,
            'so_luong' => $quantity,
            'bat_dau' => $startAt,
            'ket_thuc' => $endAt,
            'duration_minutes' => $durationMinutes,
            'created_at' => time()
        ];
        $phienLiveModel->setActiveVoucher($liveId, $voucherData);

        $phienLiveModel->saveChat([
            '_id' => 'voucher-' . bin2hex(random_bytes(12)),
            'room_id' => (string)$live['id'],
            'sender_id' => 'system',
            'sender_type' => 'admin',
            'sender_name' => 'Quản lý Live',
            'message' => "🎉 ƯU ĐÃI NÓNG: Voucher [{$code}] giảm " . ($type === 'percent' ? "{$value}%" : number_format($value) . "đ") . " vừa được phát lên màn hình! Bấm 'Nhận voucher' ngay!",
            'is_pinned' => true
        ]);

        $this->liveJson([
            'ok' => true,
            'message' => "Đã tạo và phát voucher [{$code}] thành công lên màn hình live!",
            'voucher' => $voucherData
        ]);
    }

    public function apiLiveDropVoucher(): void {
        while (ob_get_level() > 0) @ob_end_clean();
        header('Content-Type: application/json; charset=utf-8');
        if (!is_logged_in() || current_role() !== 'admin') {
            $this->liveJson(['ok' => false, 'message' => 'Bạn không có quyền quản lý voucher.'], 403);
            return;
        }
        if (!$this->livePost()) return;

        global $db;
        $mongoDb = $db ?? $this->pdo;
        require_once __DIR__ . '/../models/PhienLive.php';
        require_once __DIR__ . '/../models/Voucher.php';

        $phienLiveModel = new PhienLive($mongoDb);
        $liveId = trim((string)($_POST['room_id'] ?? $_POST['live_id'] ?? ''));
        $live = $phienLiveModel->findById($liveId);
        if (!$live) {
            $this->liveJson(['ok' => false, 'message' => 'Phiên live không tồn tại.'], 404);
            return;
        }

        $code = strtoupper(trim(preg_replace('/\s+/u', '', (string)($_POST['ma_code'] ?? ''))));
        if ($code === '') {
            $this->liveJson(['ok' => false, 'message' => 'Thiếu mã voucher.'], 422);
            return;
        }

        $voucherModel = new Voucher($mongoDb);
        $voucherDoc = $voucherModel->getVoucherByCode($code);
        if (!$voucherDoc) {
            $this->liveJson(['ok' => false, 'message' => 'Voucher không tồn tại trên hệ thống.'], 404);
            return;
        }

        $durationMinutes = max(5, (int)($_POST['thoi_luong_phut'] ?? 30));
        $voucherData = [
            'ma_code' => $code,
            'ten_voucher' => $voucherDoc['ten_voucher'] ?? $code,
            'loai_giam' => $voucherDoc['loai_giam'] ?? 'fixed',
            'gia_tri_giam' => $voucherDoc['gia_tri_giam'] ?? 0,
            'giam_toi_da' => $voucherDoc['giam_toi_da'] ?? null,
            'gia_tri_don_toi_thieu' => $voucherDoc['gia_tri_don_toi_thieu'] ?? 0,
            'so_luong' => $voucherDoc['so_luong'] ?? null,
            'bat_dau' => date('Y-m-d H:i:s'),
            'ket_thuc' => date('Y-m-d H:i:s', strtotime("+{$durationMinutes} minutes")),
            'duration_minutes' => $durationMinutes,
            'created_at' => time()
        ];
        $phienLiveModel->setActiveVoucher($liveId, $voucherData);

        $this->liveJson([
            'ok' => true,
            'message' => "Đã phát voucher [{$code}] lên màn hình live!",
            'voucher' => $voucherData
        ]);
    }

    public function apiLiveEndVoucher(): void {
        while (ob_get_level() > 0) @ob_end_clean();
        header('Content-Type: application/json; charset=utf-8');
        if (!is_logged_in() || current_role() !== 'admin') {
            $this->liveJson(['ok' => false, 'message' => 'Bạn không có quyền quản lý voucher.'], 403);
            return;
        }
        if (!$this->livePost()) return;
        global $db;
        $phienLiveModel = new PhienLive($db ?? $this->pdo);
        $liveId = trim((string)($_POST['room_id'] ?? $_POST['live_id'] ?? ''));
        $phienLiveModel->clearActiveVoucher($liveId);
        $this->liveJson(['ok' => true, 'message' => 'Đã kết thúc phát voucher trên màn hình.']);
    }

    public function apiLiveSynaReminder(): void {
        while (ob_get_level() > 0) @ob_end_clean();
        header('Content-Type: application/json; charset=utf-8');
        if (!$this->livePost()) return;
        $liveId = trim((string)($_POST['room_id'] ?? $_POST['live_id'] ?? ''));
        global $db;
        $phienLiveModel = new PhienLive($db ?? $this->pdo);
        $live = $phienLiveModel->findById($liveId);
        if (!$live || ($live['status'] ?? '') !== 'live') {
            $this->liveJson(['ok' => false, 'message' => 'Phiên chưa phát sóng thật.'], 400);
            return;
        }
        if (!$this->liveAudience($live)['moderate']) {
            $this->liveJson(['ok' => false, 'message' => 'Bạn không có quyền gửi lời nhắc trong phiên này.'], 403);
            return;
        }

        $reminderText = "Bạn có câu hỏi về sản phẩm hoặc skincare? Hãy nhập @syna kèm câu hỏi để tui hỗ trợ nhé! 💚";
        $phienLiveModel->saveChat([
            '_id' => 'reminder-' . bin2hex(random_bytes(12)),
            'room_id' => (string)$live['id'],
            'sender_id' => 'syna_reminder',
            'sender_type' => 'ai',
            'sender_name' => 'SYNA',
            'message' => $reminderText,
            'is_ai' => true
        ]);

        $this->liveJson(['ok' => true, 'message' => 'Đã gửi lời nhắc SYNA vào chat.']);
    }
}
