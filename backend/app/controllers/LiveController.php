<?php

require_once __DIR__ . '/../services/ProductEventTracker.php';
require_once __DIR__ . '/../services/AiIdolReplaySessions.php';
require_once __DIR__ . '/../services/CacheService.php';

class LiveController {
    private const VIEWER_ACTIVE_SECONDS = 45;

    private $pdo;

    public function __construct($pdo) {
        $this->pdo = $pdo;
    }

    public function index(): void {
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
            
            // EXACT REAL-TIME VIEWER COUNT (0 or 1+ from active session heartbeat)
            $realViewers = 0;
            if ($st === 'live') {
                $realViewers = (int)($activeViewerMap[$roomId] ?? 0);
            }

            $urlBanGhi = (string)($live['url_ban_ghi'] ?? '');

            $totalViews = (int)($live['luot_xem'] ?? 0);
            $peakViewers = (int)($live['mat_do_nguoi_xem_dinh'] ?? 0);
            $totalRevenue = (float)($live['tong_doanh_thu'] ?? 0);
            $totalOrders = (int)($live['tong_don_hang'] ?? 0);
            $totalUnits = (int)($live['tong_san_pham_ban'] ?? 0);
            $cr = (float)($live['ty_le_chot_don'] ?? 0);

            $liveSessions[] = [
                'id' => $roomId,
                'title' => (string)($live['tieu_de'] ?? ''),
                'streamer' => (string)($live['streamer'] ?? ''),
                'viewers' => $realViewers,
                'status' => $st,
                'thumbnail' => $thumbImg,
                'pinned_product' => $p,
                'gia_uu_dai_live' => (float)($live['gia_uu_dai_live'] ?? ($p['gia_ban'] ?? 0)),
                'server_livekit_url' => (string)($live['server_livekit_url'] ?? (defined('LIVEKIT_URL') ? LIVEKIT_URL : '')),
                'url_ban_ghi' => $urlBanGhi,
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

        $liveSessions = array_merge($liveSessions, $this->findAiIdolReplays($mongoDb, $spModel));

        $currentLive = null;

        if ($selectedId !== '') {
            foreach ($liveSessions as $session) {
                if ((string)$session['id'] === $selectedId) {
                    $currentLive = $session;
                    break;
                }
            }
        }

        if (!$currentLive && !empty($liveSessions)) {
            $currentLive = $liveSessions[0];
            foreach ($liveSessions as $session) {
                if ($session['status'] === 'live') {
                    $currentLive = $session;
                    break;
                }
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
        $aiIdolProducts = [];
        if ($aiIdolLive && !empty($aiIdolLive['product_ids'])) {
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

        $roomName = 'skinsyntax_room_' . ($liveDoc['ma_phong'] ?? $liveDoc['id'] ?? $liveId);
        $status = (string)($liveDoc['status'] ?? 'scheduled');
        $role = current_role();
        $isHostOrAdmin = in_array($role, ['admin', 'nhanvien'], true);

        if (!$isHostOrAdmin && $status !== 'live') {
            http_response_code(409);
            echo json_encode(['ok' => false, 'code' => 'stream_not_live', 'message' => $status === 'replay_ready' ? 'Phiên này chỉ có bản ghi xem lại.' : 'Streamer chưa phát luồng trực tiếp.'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            exit;
        }

        $currentUser = current_user() ?? [];
        if (is_logged_in() && !empty($currentUser['ma_nd'])) {
            $identity = 'usr_' . $currentUser['ma_nd'];
            $displayName = (string)($currentUser['ho_ten'] ?? 'Khách hàng');
        } else {
            $sessionHash = md5(session_id() ?: 'skinsyntax');
            $identity = 'guest_' . substr($sessionHash, 0, 8) . '_' . substr(uniqid(), -6);
            $displayName = $isHostOrAdmin ? 'Streamer / Admin' : 'Khách vãng lai';
        }

        $canPublish = $isHostOrAdmin;

        $configuredWsUrl = function_exists('ss_env') ? ss_env('LIVEKIT_URL', '') : (getenv('LIVEKIT_URL') ?: '');
        // The API key/secret belong to ONE LiveKit project, so the configured URL must win.
        // Sessions created under an older project still store that project's URL, and
        // using it yields "invalid API key for domain" (401) for host and viewers alike.
        $wsUrl = trim((string)$configuredWsUrl) ?: trim((string)($liveDoc['server_livekit_url'] ?? ''));
        $apiKey = function_exists('ss_env') ? ss_env('LIVEKIT_API_KEY', '') : (getenv('LIVEKIT_API_KEY') ?: '');
        $apiSecret = function_exists('ss_env') ? ss_env('LIVEKIT_API_SECRET', '') : (getenv('LIVEKIT_API_SECRET') ?: '');

        if ($wsUrl === '' || $apiKey === '' || $apiSecret === '' || in_array(strtolower($apiKey), ['your_key', 'devkey'], true) || in_array(strtolower($apiSecret), ['your_secret', 'secret'], true)) {
            http_response_code(503);
            echo json_encode(['ok' => false, 'code' => 'livekit_not_configured', 'message' => 'Hệ thống phát video chưa được cài đặt. Vui lòng liên hệ quản trị hệ thống.'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            exit;
        }

        $ttlSeconds = 3600; // TTL ngắn: 1 giờ
        $token = $this->buildLivekitJwtToken($apiKey, $apiSecret, $roomName, $identity, $displayName, $canPublish, $ttlSeconds);

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

        global $db;
        $mongoDb = $db ?? $this->pdo;
        require_once __DIR__ . '/../models/SanPham.php';
        $spModel = new SanPham($mongoDb);

        $results = $spModel->paginate(1, 30, $q);
        $items = $results['items'] ?? [];

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


    private function buildLivekitJwtToken(string $apiKey, string $apiSecret, string $room, string $identity, string $name, bool $canPublish = false, int $ttlSeconds = 3600): string {
        $header = json_encode(['alg' => 'HS256', 'typ' => 'JWT']);
        $now = time();
        $payload = json_encode([
            'iss' => $apiKey,
            'sub' => $identity,
            'name' => $name,
            'nbf' => $now - 5,
            'exp' => $now + $ttlSeconds,
            'video' => [
                'room' => $room,
                'roomJoin' => true,
                'canPublish' => $canPublish,
                'canSubscribe' => true,
                'canPublishData' => true
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
            // Usually the PHP session was lost (expired, or the container restarted) while the
            // studio page was open: say so instead of the misleading "staff only" message.
            http_response_code(401);
            echo json_encode(['ok' => false, 'code' => 'login_required', 'message' => 'Phiên đăng nhập đã hết hạn. Hãy tải lại trang và đăng nhập lại để tiếp tục phát.'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        if (!in_array(current_role(), ['admin', 'nhanvien'], true)) {
            http_response_code(403);
            echo json_encode(['ok' => false, 'message' => 'Chỉ streamer hoặc quản trị viên mới được điều khiển luồng phát.'], JSON_UNESCAPED_UNICODE);
            exit;
        }

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
        $normalizedLiveId = (string)($live['id'] ?? $liveId);
        if ($state === 'live' || $state === 'heartbeat') {
            $publisherToken = trim((string)($_POST['publisher_token'] ?? ''));
            if (!$this->verifyLivekitPublisherToken($publisherToken, $normalizedLiveId)) {
                http_response_code(403);
                echo json_encode(['ok' => false, 'message' => 'Không xác minh được phiên publisher LiveKit.'], JSON_UNESCAPED_UNICODE);
                exit;
            }
        }
        if (in_array(($live['status'] ?? ''), ['ended', 'replay_ready', 'recording_failed'], true) && $state !== 'offline') {
            http_response_code(409);
            echo json_encode(['ok' => false, 'message' => 'Phiên đã kết thúc và không thể phát lại.'], JSON_UNESCAPED_UNICODE);
            exit;
        }

        if ($state === 'heartbeat') {
            if (!$model->touchStream($liveId)) {
                http_response_code(409);
                echo json_encode(['ok' => false, 'code' => 'not_live', 'message' => 'Phiên không còn ở trạng thái phát.'], JSON_UNESCAPED_UNICODE);
                exit;
            }
            echo json_encode(['ok' => true, 'live_id' => $liveId, 'state' => 'live'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            exit;
        }

        $ok = $model->updateStreamState($liveId, $state);
        if ($ok && $state === 'ended' && (string)($_POST['no_recording'] ?? '') === '1') {
            $model->failRecording($liveId, 'Phiên phát không có bản ghi video.');
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
        if (!$payload || (int)($payload['exp'] ?? 0) < time() || empty($payload['sub'])) return false;
        $room = 'skinsyntax_room_' . $liveId;
        $video = $payload['video'] ?? [];
        if (($video['room'] ?? '') !== $room || empty($video['canPublish'])) return false;
        $secret = function_exists('ss_env') ? ss_env('LIVEKIT_API_SECRET', '') : (getenv('LIVEKIT_API_SECRET') ?: '');
        if ($secret === '' || in_array(strtolower($secret), ['secret', 'your_secret'], true)) return false;
        $expected = rtrim(strtr(base64_encode(hash_hmac('sha256', $parts[0] . '.' . $parts[1], $secret, true)), '+/', '-_'), '=');
        return hash_equals($expected, $parts[2]);
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

    public function apiLiveChat(): void {
        header('Content-Type: application/json; charset=utf-8');
        $message = trim((string)($_POST['message'] ?? $_GET['message'] ?? ''));
        $productId = trim((string)($_POST['product_id'] ?? $_GET['product_id'] ?? ''));
        $pinnedPrice = 0.0;

        if ($message === '') {
            echo json_encode(['ok' => false, 'message' => 'Nội dung tin nhắn rỗng']);
            exit;
        }

        global $db;
        $mongoDb = $db ?? $this->pdo;

        $roomId = trim((string)($_POST['room_id'] ?? $_GET['room_id'] ?? $_POST['id'] ?? $_GET['id'] ?? ''));
        if ($roomId === '') {
            http_response_code(422);
            echo json_encode(['ok' => false, 'message' => 'Thiếu phiên livestream.'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        if (session_status() === PHP_SESSION_NONE) @session_start();
        $rateKey = 'live_chat_rate_' . $roomId;
        $rate = array_values(array_filter((array)($_SESSION[$rateKey] ?? []), fn($time) => (int)$time > time() - 20));
        if (count($rate) >= 5) {
            http_response_code(429);
            echo json_encode(['ok' => false, 'message' => 'Bạn gửi hơi nhanh. Vui lòng thử lại sau ít giây.'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        $rate[] = time();
        $_SESSION[$rateKey] = $rate;

        if ($roomId !== '') {
            require_once __DIR__ . '/../models/PhienLive.php';
            $phienLiveModel = new PhienLive($mongoDb);
            $liveDoc = $phienLiveModel->findById($roomId);
            if (!$liveDoc || ($liveDoc['status'] ?? '') !== 'live') {
                echo json_encode([
                    'ok' => false,
                    'message' => 'Phiên livestream chưa phát luồng thật hoặc đã kết thúc.'
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                exit;
            }
            $productId = (string)($liveDoc['ma_san_pham_ghim'] ?? $productId);
            $pinnedPrice = (float)($liveDoc['gia_uu_dai_live'] ?? 0);
        }

        $spModel = new SanPham($mongoDb);
        $pinnedProduct = $productId !== '' ? $spModel->findById($productId) : null;

        $productName = $pinnedProduct ? (string)($pinnedProduct['ten_san_pham'] ?? 'Sản phẩm ghim ưu đãi') : 'Sản phẩm ghim ưu đãi';
        $productBrand = $pinnedProduct ? (string)($pinnedProduct['thuong_hieu'] ?? 'SkinSyntax') : 'SkinSyntax';
        $productPrice = $pinnedPrice > 0 ? $pinnedPrice : ($pinnedProduct ? (float)($pinnedProduct['gia_ban'] ?? 0) : 0);

        $lowerMsg = mb_strtolower($message);
        $isOrderCommand = (strpos($lowerMsg, 'chốt đơn') !== false || strpos($lowerMsg, 'mua ngay') !== false || strpos($lowerMsg, 'đặt hàng') !== false);

        $aiReply = '';
        if ($isOrderCommand && $pinnedProduct && $productPrice > 0) {
            if (!isset($_SESSION['gio_hang']) || !is_array($_SESSION['gio_hang'])) {
                $_SESSION['gio_hang'] = [];
            }
            $pIdStr = (string)($pinnedProduct['ma_san_pham'] ?? $pinnedProduct['id'] ?? $productId);
            if ($pIdStr !== '') {
                $currentQty = (int)($_SESSION['gio_hang'][$pIdStr] ?? 0);
                $_SESSION['gio_hang'][$pIdStr] = $currentQty + 1;
                ProductEventTracker::record($mongoDb, 'add_to_cart', $pIdStr);
            }

            $userDisplayName = is_logged_in() ? (current_user()['ho_ten'] ?? 'bạn') : 'khách hàng';
            $aiReply = 'Tư vấn SkinSyntax: Đã nhận lệnh chốt đơn 1x "' . $productName . '" với giá ưu đãi ' . number_format($productPrice) . 'đ cho ' . $userDisplayName . '! Sản phẩm đã được thêm vào giỏ hàng. Bạn có thể bấm vào giỏ hàng ở góc phải để xem đơn nhé!';
        } else {
            $ingredients = !empty($pinnedProduct['thanh_phan_chinh']) ? $pinnedProduct['thanh_phan_chinh'] : (!empty($pinnedProduct['thanh_phan_day_du']) ? $pinnedProduct['thanh_phan_day_du'] : '');
            $origin = !empty($pinnedProduct['xuat_xu']) ? $pinnedProduct['xuat_xu'] : 'chính hãng';
            $description = !empty($pinnedProduct['mo_ta']) ? mb_substr(strip_tags($pinnedProduct['mo_ta']), 0, 200) : '';

            // Try calling Python AI service on port 5001 if configured
            $aiEndpoint = defined('AI_CHAT_ENDPOINT') ? AI_CHAT_ENDPOINT : 'http://127.0.0.1:5001/api/chat/auto';
            $ragPayload = [
                'message' => $message,
                'live_context' => [
                    'streamer' => (string)($liveDoc['streamer'] ?? 'SkinSyntax Streamer'),
                    'pinned_product_id' => (string)($pinnedProduct['ma_san_pham'] ?? $productId),
                    'pinned_product' => $productName,
                    'brand' => $productBrand,
                    'origin' => $origin,
                    'ingredients' => $ingredients,
                    'description' => $description,
                    'live_price' => $productPrice,
                    'original_price' => (float)($pinnedProduct['gia_ban'] ?? $productPrice),
                    'stock' => (int)($pinnedProduct['so_luong_ton_kho'] ?? 0),
                    'user_role' => current_role()
                ]
            ];

            $response = $this->callAiRagService($aiEndpoint, $ragPayload, 4);
            if (!empty($response['ok']) && !empty($response['answer'])) {
                $cleanAnswer = trim($response['answer']);
                $cleanAnswer = preg_replace('/\[(RAG|AI|LLM|Knowledge|Evaluation|Service|Agent)[^\]]*\]/i', '', $cleanAnswer);
                $aiReply = 'Tư vấn SkinSyntax: ' . trim($cleanAnswer);
            } else if (strpos($lowerMsg, 'xuất xứ') !== false || strpos($lowerMsg, 'sản xuất') !== false || strpos($lowerMsg, 'ở đâu') !== false || strpos($lowerMsg, 'nguồn gốc') !== false || strpos($lowerMsg, 'hãng nào') !== false || strpos($lowerMsg, 'nước nào') !== false || strpos($lowerMsg, 'thương hiệu') !== false) {
                $originStr = !empty($pinnedProduct['xuat_xu']) ? $pinnedProduct['xuat_xu'] : 'chính hãng cao cấp';
                $aiReply = 'Tư vấn SkinSyntax: Sản phẩm "' . $productName . '" thuộc thương hiệu nổi tiếng ' . $productBrand . ', xuất xứ từ ' . $originStr . ' chính hãng 100%! Đang có giá ưu đãi chỉ ' . number_format($productPrice) . 'đ trong phiên Live này. Bạn gõ "chốt đơn" để đặt ngay nhé!';
            } else if (strpos($lowerMsg, 'tốt không') !== false || strpos($lowerMsg, 'ổn không') !== false || strpos($lowerMsg, 'ok không') !== false || strpos($lowerMsg, 'chất lượng') !== false || strpos($lowerMsg, 'có nên mua') !== false || strpos($lowerMsg, 'thế nào') !== false || strpos($lowerMsg, 'được không') !== false) {
                $originStr = !empty($pinnedProduct['xuat_xu']) ? $pinnedProduct['xuat_xu'] : 'chính hãng';
                $aiReply = 'Tư vấn SkinSyntax: Sản phẩm "' . $productName . '" đến từ thương hiệu nổi tiếng ' . $productBrand . ' (' . $originStr . ') rất chất lượng và an toàn ạ! Dưỡng chất dịu nhẹ, lên màu chuẩn và nhận được hàng ngàn đánh giá 5 sao. Trong phiên Live hôm nay đang giảm sâu chỉ còn ' . number_format($productPrice) . 'đ, rất đáng mua bạn nhé!';
            } else if (strpos($lowerMsg, 'dành cho') !== false || strpos($lowerMsg, 'loại da') !== false || strpos($lowerMsg, 'hợp với') !== false || strpos($lowerMsg, 'ai dùng') !== false) {
                $aiReply = 'Tư vấn SkinSyntax: Sản phẩm "' . $productName . '" (' . $productBrand . ') được thiết kế lành tính, tương thích cho mọi làn da. Rất thích hợp để bạn sử dụng mỗi ngày!';
            } else if (strpos($lowerMsg, 'dùng thế nào') !== false || strpos($lowerMsg, 'cách dùng') !== false || strpos($lowerMsg, 'hướng dẫn') !== false || strpos($lowerMsg, 'sử dụng') !== false) {
                $aiReply = 'Tư vấn SkinSyntax: Cách sử dụng sản phẩm "' . $productName . '": Thoa một lượng vừa đủ lên vùng da đã làm sạch, massage nhẹ nhàng để dưỡng chất thẩm thấu tốt nhất!';
            } else if (strpos($lowerMsg, 'da dầu') !== false || strpos($lowerMsg, 'mụn') !== false || strpos($lowerMsg, 'thành phần') !== false || strpos($lowerMsg, 'hoạt chất') !== false) {
                $ingStr = !empty($ingredients) ? $ingredients : (!empty($pinnedProduct['mo_ta']) ? mb_substr(strip_tags($pinnedProduct['mo_ta']), 0, 150) : 'Chiết xuất dịu nhẹ, an toàn dựa trên hồ sơ da.');
                $aiReply = 'Tư vấn SkinSyntax: Thành phần sản phẩm "' . $productName . '" (' . $productBrand . ') bao gồm: ' . $ingStr . '. Tương thích tối ưu cho làn da của bạn!';
            } else if (strpos($lowerMsg, 'giá') !== false || strpos($lowerMsg, 'sale') !== false || strpos($lowerMsg, 'ưu đãi') !== false || strpos($lowerMsg, 'rẻ') !== false || strpos($lowerMsg, 'bao nhiêu') !== false) {
                $aiReply = 'Tư vấn SkinSyntax: Sản phẩm "' . $productName . '" đang được giảm giá ưu đãi trong phiên Live này chỉ còn ' . number_format($productPrice) . 'đ! Bạn gõ "chốt đơn" hoặc bấm nút " MUA NGAY TRONG LIVE" để sở hữu ngay nhé!';
            } else {
                $descSnippet = !empty($pinnedProduct['mo_ta']) ? mb_substr(strip_tags($pinnedProduct['mo_ta']), 0, 120) : 'Sản phẩm chính hãng chất lượng cao';
                $aiReply = 'Tư vấn SkinSyntax: Sản phẩm "' . $productName . '" thuộc thương hiệu ' . $productBrand . ' (' . $origin . '). Mô tả: ' . $descSnippet . '... Đang giảm ưu đãi chỉ còn ' . number_format($productPrice) . 'đ trong phiên Live. Bạn gõ "chốt đơn" để đặt ngay nhé!';
            }
            // When the catalog-backed AI service is unavailable, keep the
            // user's message but do not invent product claims or promotions.
            if (empty($response['ok'])) $aiReply = '';
        }

        $guestNickname = trim((string)($_POST['guest_nickname'] ?? $_GET['guest_nickname'] ?? ''));
        $senderId = '';
        $senderType = 'guest';
        if (is_logged_in()) {
            $user = current_user() ?? [];
            $senderId = (string)($user['ma_nd'] ?? $user['id'] ?? '');
            $senderName = (string)($user['ho_ten'] ?? $user['email'] ?? 'Khách hàng');
            $senderType = in_array(current_role(), ['admin', 'nhanvien'], true) ? 'streamer' : 'customer';
        } else {
            $senderId = 'guest_' . substr(hash('sha256', session_id()), 0, 20);
            $senderName = $guestNickname !== '' ? mb_substr($guestNickname, 0, 40) : 'Khách vãng lai';
        }
        $clientMessageId = trim((string)($_POST['client_message_id'] ?? ''));
        $isQuestion = str_contains($message, '?') || preg_match('/\b(da|loai da|thanh phan|cach dung|dung sao|phu hop|co duoc|bao nhieu)\b/ui', $message);
        if (!empty($phienLiveModel) && $roomId !== '') {
            $saved = $phienLiveModel->luuTinNhanChat($roomId, $senderName, $message, false, $isOrderCommand, $senderType, $senderId, $clientMessageId, (bool)$isQuestion);
            if (!$saved) {
                http_response_code(503);
                echo json_encode(['ok' => false, 'message' => 'Không thể lưu tin nhắn vào phiên live.'], JSON_UNESCAPED_UNICODE);
                exit;
            }
            if ($aiReply !== '') {
                $phienLiveModel->luuTinNhanChat($roomId, 'Tư vấn SkinSyntax', $aiReply, true, $isOrderCommand, 'ai', 'ai_cohost', '', false);
            }
        }

        $cartCount = 0;
        foreach (($_SESSION['gio_hang'] ?? []) as $qVal) {
            $cartCount += (int)$qVal;
        }

        echo json_encode([
            'ok' => true,
            'user_message' => $message,
            'ai_response' => $aiReply,
            'is_order' => $isOrderCommand,
            'client_message_id' => $clientMessageId,
            'cart_count' => $cartCount,
            'timestamp' => date('H:i:s')
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    public function apiLiveChatHistory(): void {
        while (ob_get_level() > 0) {
            @ob_end_clean();
        }
        header('Content-Type: application/json; charset=utf-8');

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
        $history = $phienLiveModel->getLichSuChat($roomId, 100, $mode, $afterMs);

        echo json_encode([
            'ok' => true,
            'data' => $history
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
                if (is_array($decoded) && !empty($decoded['answer'])) {
                    return ['ok' => true, 'answer' => (string)$decoded['answer']];
                }
            }
        }
        return ['ok' => false];
    }

    public function apiLiveProducts(): void {
        while (ob_get_level() > 0) {
            @ob_end_clean();
        }
        header('Content-Type: application/json; charset=utf-8');

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

        $spModel = new SanPham($mongoDb);
        $pinnedProduct = null;
        if (!empty($live['ma_san_pham_ghim'])) {
            $pinnedProduct = $spModel->findById($live['ma_san_pham_ghim']);
        }

        $endTime = strtotime($live['khung_gio_ket_thuc'] ?? '');
        $now = time();
        $countdownSeconds = ($endTime && $endTime > $now) ? ($endTime - $now) : 0;

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
                'pinned_product_id' => (string)$live['ma_san_pham_ghim'],
                'gia_uu_dai_live' => $livePrice,
                'gia_ban_goc' => $originalPrice,
                'gia_thi_truong' => $marketPrice,
                'discount_percent' => $discountPercent,
                'countdown_seconds' => $countdownSeconds,
                'pinned_product' => $pinnedProduct ? [
                    'ma_san_pham' => (string)($pinnedProduct['ma_san_pham'] ?? ''),
                    'ten_san_pham' => (string)($pinnedProduct['ten_san_pham'] ?? ''),
                    'thuong_hieu' => (string)($pinnedProduct['thuong_hieu'] ?? 'SkinSyntax'),
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

        $role = current_role();
        $liveId = trim((string)($_POST['live_id'] ?? $_GET['live_id'] ?? $_POST['id'] ?? $_GET['id'] ?? ''));
        $productId = trim((string)($_POST['product_id'] ?? $_GET['product_id'] ?? $_POST['ma_san_pham'] ?? $_GET['ma_san_pham'] ?? ''));
        $livePrice = isset($_POST['gia_uu_dai_live']) ? (float)$_POST['gia_uu_dai_live'] : (isset($_GET['gia_uu_dai_live']) ? (float)$_GET['gia_uu_dai_live'] : null);
        $durationMinutes = max(5, (int)($_POST['duration_minutes'] ?? $_GET['duration_minutes'] ?? 15));
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
                'message' => $ok ? "Đã chọn sản phẩm #{$productId} trong {$durationMinutes} phút!" : 'Không thể chọn sản phẩm',
                'live_id' => $liveId,
                'pinned_product_id' => $productId,
                'gia_uu_dai_live' => $livePrice
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            exit;
        }

        set_flash($ok ? 'success' : 'error', $ok ? "Đã chọn sản phẩm #{$productId} trong {$durationMinutes} phút!" : 'Không thể chọn sản phẩm');
        header('Location: ' . $targetUrl);
        exit;
    }

    public function apiUploadLiveRecording(): void {
        while (ob_get_level() > 0) {
            @ob_end_clean();
        }
        header('Content-Type: application/json; charset=utf-8');

        $role = current_role();
        if (!in_array($role, ['admin', 'nhanvien'], true)) {
            http_response_code(403);
            echo json_encode(['ok' => false, 'message' => 'Bạn không có quyền thực hiện thao tác này'], JSON_UNESCAPED_UNICODE);
            exit;
        }

        if (isset($_POST['upload_id'])) {
            $this->handleChunkedRecordingUpload();
            exit;
        }

        $liveId = trim((string)($_POST['live_id'] ?? $_GET['live_id'] ?? ''));
        if ($liveId === '' || empty($_FILES['video_blob'])) {
            echo json_encode(['ok' => false, 'message' => 'Thiếu ID phiên Live hoặc file video bản ghi'], JSON_UNESCAPED_UNICODE);
            exit;
        }

        $file = $_FILES['video_blob'];
        if ($file['error'] !== UPLOAD_ERR_OK) {
            echo json_encode(['ok' => false, 'message' => 'Upload thất bại với mã lỗi ' . $file['error']], JSON_UNESCAPED_UNICODE);
            exit;
        }

        global $db;
        $mongoDb = $db ?? $this->pdo;
        require_once __DIR__ . '/../models/PhienLive.php';
        $phienLiveModel = new PhienLive($mongoDb);
        $live = $phienLiveModel->findById($liveId);
        if (!$live) {
            http_response_code(404);
            echo json_encode(['ok' => false, 'message' => 'Phiên livestream không tồn tại.'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        $maxBytes = 1024 * 1024 * 1024;
        if ((int)($file['size'] ?? 0) <= 0 || (int)($file['size'] ?? 0) > $maxBytes) {
            $phienLiveModel->failRecording($liveId, 'File recording rỗng hoặc vượt quá giới hạn 1 GB.');
            http_response_code(422);
            echo json_encode(['ok' => false, 'message' => 'File recording không hợp lệ hoặc vượt quá 1 GB.'], JSON_UNESCAPED_UNICODE);
            exit;
        }

        $uploadDir = dirname(__DIR__, 2) . '/public/uploads/live_recordings';
        if (!is_dir($uploadDir)) {
            @mkdir($uploadDir, 0777, true);
        }

        $mime = function_exists('mime_content_type') ? (string)(mime_content_type($file['tmp_name']) ?: $file['type']) : (string)$file['type'];
        $allowedMimes = ['video/webm', 'video/mp4', 'video/ogg'];
        $mimeBase = strtolower(trim(explode(';', $mime)[0]));
        if (!in_array($mimeBase, $allowedMimes, true)) {
            $phienLiveModel->failRecording($liveId, 'Định dạng recording không được hỗ trợ: ' . $mimeBase);
            http_response_code(415);
            echo json_encode(['ok' => false, 'message' => 'Chỉ chấp nhận recording WebM, MP4 hoặc OGG.'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        $ext = $mimeBase === 'video/mp4' ? 'mp4' : ($mimeBase === 'video/ogg' ? 'ogv' : 'webm');

        $filename = 'live_rec_' . $liveId . '_' . time() . '.' . $ext;
        $targetPath = $uploadDir . '/' . $filename;

        if (move_uploaded_file($file['tmp_name'], $targetPath)) {
            $publicUrl = BASE_URL . '/uploads/live_recordings/' . $filename;

            $phienLiveModel->saveRecording($liveId, $publicUrl, $mimeBase, (int)$file['size']);

            echo json_encode([
                'ok' => true,
                'url' => $publicUrl,
                'message' => 'Đã tự động lưu bản ghi video Livestream thành công!'
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            exit;
        }

        $phienLiveModel->failRecording($liveId, 'Không thể lưu file recording vào vùng lưu trữ bền vững.');
        echo json_encode(['ok' => false, 'message' => 'Không thể di chuyển file video đã quay'], JSON_UNESCAPED_UNICODE);
        exit;
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
        if ($final && ($live['status'] ?? '') === 'replay_ready' && !empty($live['url_ban_ghi'])) {
            $reply(200, ['ok' => true, 'url' => (string)$live['url_ban_ghi'], 'message' => 'Bản ghi đã được lưu trước đó.']);
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

        $baseDir = dirname(__DIR__, 2) . '/public/uploads/live_recordings';
        $tmpDir = $baseDir . '/tmp';
        if (!is_dir($tmpDir)) @mkdir($tmpDir, 0777, true);
        $safeLiveId = preg_replace('/[^A-Za-z0-9_-]/', '', $liveId);
        $stem = $tmpDir . '/live_' . $safeLiveId . '_' . $uploadId;
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

        $publicUrl = BASE_URL . '/uploads/live_recordings/' . $filename;
        $model->saveRecording($liveId, $publicUrl, $mimeBase, $totalBytes);
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

        if ($liveId === '' || $productId === '' || $qty < 1) {
            set_flash('error', 'Vui lòng chọn phiên, sản phẩm và nhập số lượng bán tối đa.');
            header('Location: ' . BASE_URL . '/index.php?r=admin_lives');
            exit;
        }

        global $db;
        $mongoDb = $db ?? $this->pdo;
        require_once __DIR__ . '/../models/PhienLive.php';
        $phienLiveModel = new PhienLive($mongoDb);

        $ok = $phienLiveModel->themDealSanPham($liveId, [
            'ma_san_pham' => $productId,
            'gia_uu_dai_live' => $price,
            'so_luong_kho_deal' => $qty,
            'khung_gio_bat_dau' => $start,
            'khung_gio_ket_thuc' => $end,
            'trang_thai' => 'sap_dien_ra'
        ]);

        $redirectUrl = !empty($_POST['redirect_live']) ? (BASE_URL . '/index.php?r=live&id=' . urlencode($liveId)) : (BASE_URL . '/index.php?r=admin_lives');
        set_flash($ok ? 'success' : 'error', $ok ? 'Đã thêm sản phẩm ưu đãi mới vào phiên phát!' : 'Không thể thêm sản phẩm');
        header('Location: ' . $redirectUrl);
        exit;
    }


    public function adminLiveDeleteDeal(): void {
        while (ob_get_level() > 0) {
            @ob_end_clean();
        }

        $role = current_role();
        if (!in_array($role, ['admin', 'nhanvien'], true)) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['ok' => false, 'message' => 'Bạn không có quyền thực hiện thao tác này']);
            exit;
        }

        $liveId = trim((string)($_POST['live_id'] ?? $_GET['live_id'] ?? ''));
        $dealId = trim((string)($_POST['deal_id'] ?? $_GET['deal_id'] ?? ''));

        global $db;
        $mongoDb = $db ?? $this->pdo;
        require_once __DIR__ . '/../models/PhienLive.php';
        $phienLiveModel = new PhienLive($mongoDb);

        $ok = $phienLiveModel->xoaDealSanPham($liveId, $dealId);

        set_flash($ok ? 'success' : 'error', $ok ? 'Đã xóa sản phẩm khỏi danh sách deal Live!' : 'Không thể xóa deal');
        header('Location: ' . BASE_URL . '/index.php?r=admin_lives');
        exit;
    }

    public function apiLiveAddToCart(): void {
        while (ob_get_level() > 0) {
            @ob_end_clean();
        }
        header('Content-Type: application/json; charset=utf-8');

        global $db;
        $mongoDb = $db ?? $this->pdo;

        $liveId = trim((string)($_POST['live_id'] ?? $_GET['live_id'] ?? $_POST['room_id'] ?? $_GET['room_id'] ?? $_POST['id'] ?? $_GET['id'] ?? ''));
        $productId = trim((string)($_POST['product_id'] ?? $_GET['product_id'] ?? $_POST['ma_san_pham'] ?? $_GET['ma_san_pham'] ?? ''));
        $qty = max(1, (int)($_POST['quantity'] ?? $_GET['quantity'] ?? $_POST['qty'] ?? $_GET['qty'] ?? 1));

        if ($liveId !== '') {
            require_once __DIR__ . '/../models/PhienLive.php';
            $phienLiveModel = new PhienLive($mongoDb);
            $liveDoc = $phienLiveModel->findById($liveId);

            if ($liveDoc && ($liveDoc['status'] ?? '') !== 'live') {
                echo json_encode([
                    'ok' => false,
                    'message' => 'Phiên LiveStream này hiện đã kết thúc hoặc tạm ngưng. Không thể mua giá ưu đãi Live.'
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                exit;
            }

            if ($liveDoc) {
                // The browser cannot choose an arbitrary product for a live deal:
                // only the pinned SKU or a SKU queued in this session is allowed.
                $pinnedId = (string)($liveDoc['ma_san_pham_ghim'] ?? '');
                $allowed = $pinnedId !== '' ? [$pinnedId] : [];
                foreach ((array)($liveDoc['danh_sach_deal'] ?? []) as $deal) {
                    $dealPid = (string)($deal['ma_san_pham'] ?? '');
                    if ($dealPid !== '') $allowed[] = $dealPid;
                }
                if ($productId === '' || ($allowed && !in_array($productId, $allowed, true))) {
                    // Missing or unknown SKU falls back to the pinned product (previous behavior).
                    $productId = $pinnedId !== '' ? $pinnedId : ($allowed ? '' : $productId);
                }
            }
        }

        if ($productId === '') {
            echo json_encode(['ok' => false, 'message' => 'Vui lòng chọn sản phẩm cần mua trong Live']);
            exit;
        }

        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        if (!isset($_SESSION['gio_hang']) || !is_array($_SESSION['gio_hang'])) {
            $_SESSION['gio_hang'] = [];
        }

        $_SESSION['gio_hang'][$productId] = (int)($_SESSION['gio_hang'][$productId] ?? 0) + $qty;
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
}
