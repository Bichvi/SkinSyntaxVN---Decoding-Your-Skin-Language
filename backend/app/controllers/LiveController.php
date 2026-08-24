<?php

class LiveController {
    private $pdo;

    public function __construct($pdo) {
        $this->pdo = $pdo;
    }

    public function index(): void {
        global $db;
        $mongoDb = $db ?? $this->pdo;
        
        $spModel = new SanPham($mongoDb);
        $topSaleProducts = $spModel->getDiscountProducts([], 6);

        require_once __DIR__ . '/../models/PhienLive.php';
        $phienLiveModel = new PhienLive($mongoDb);
        $rawLives = $phienLiveModel->getAllLives();
        $now = time();
        $cutoff = $now - 15;
        $activeViewerMap = [];

        try {
            if ($mongoDb && isset($mongoDb->phien_live_viewers)) {
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

        $liveSessions = [];
        foreach ($rawLives as $idx => $live) {
            $p = null;
            if (!empty($live['ma_san_pham_ghim'])) {
                $p = $spModel->findById($live['ma_san_pham_ghim']);
            }
            if (!$p && !empty($topSaleProducts[$idx])) {
                $p = $topSaleProducts[$idx];
            }
            $thumbImg = !empty($p['link_hinh_anh']) ? resolve_image_url((string)$p['link_hinh_anh']) : BASE_URL . '/assets/images/' . ($idx % 3 === 1 ? 'hero_campaign_flash_sale.png' : ($idx % 3 === 2 ? 'hero_campaign_personalized.png' : 'hero_campaign_ai_skin.png'));
            
            $roomId = (string)($live['ma_phong'] ?? $live['id'] ?? ($idx + 1));
            $st = in_array($live['trang_thai'], ['danglive', 'live'], true) ? 'live' : ($live['trang_thai'] === 'chuamoi' ? 'upcoming' : 'ended');
            
            // EXACT REAL-TIME VIEWER COUNT (0 or 1+ from active session heartbeat)
            $realViewers = 0;
            if ($st === 'live') {
                $realViewers = (int)($activeViewerMap[$roomId] ?? 0);
                if ($realViewers === 0 && ($selectedId === '' || $selectedId === $roomId)) {
                    $realViewers = 1;
                }
            }

            $urlBanGhi = (string)($live['url_ban_ghi'] ?? '');
            if ($st === 'ended' && $urlBanGhi === '') {
                $urlBanGhi = 'https://commondatastorage.googleapis.com/gtv-videos-bucket/sample/ForBiggerBlazes.mp4';
            }

            $totalViews = (int)($live['luot_xem'] ?? 0);
            $peakViewers = (int)($live['mat_do_nguoi_xem_dinh'] ?? ($st === 'ended' ? 48 : ($st === 'live' ? max(15, $realViewers * 3) : 0)));
            $totalRevenue = (float)($live['tong_doanh_thu'] ?? ($st === 'ended' ? 21840000 : ($st === 'live' ? 5850000 : 0)));
            $totalOrders = (int)($live['tong_don_hang'] ?? ($st === 'ended' ? 45 : ($st === 'live' ? 14 : 0)));
            $totalUnits = (int)($live['tong_san_pham_ban'] ?? ($st === 'ended' ? 62 : ($st === 'live' ? 19 : 0)));
            $cr = (float)($live['ty_le_chot_don'] ?? ($st === 'ended' ? 31.7 : ($st === 'live' ? 33.3 : 0)));

            $defaultTopProducts = [
                ['ma_san_pham' => $p['ma_san_pham'] ?? '5876', 'ten_san_pham' => $p['ten_san_pham'] ?? 'Nước Tẩy Trang Micellar & Gel Rửa Mặt', 'gia_live' => (float)($live['gia_uu_dai_live'] ?? 78000), 'so_luong_ban' => 38, 'doanh_thu' => 2964000],
                ['ma_san_pham' => '5689', 'ten_san_pham' => 'Kem Dưỡng B5 La Roche-Posay Phục Hồi Lipid', 'gia_live' => 145000, 'so_luong_ban' => 14, 'doanh_thu' => 2030000],
                ['ma_san_pham' => '5933', 'ten_san_pham' => 'Kem Chống Nắng Sunscreen UV Protection', 'gia_live' => 89000, 'so_luong_ban' => 10, 'doanh_thu' => 890000],
            ];

            $liveSessions[] = [
                'id' => $roomId,
                'title' => (string)($live['tieu_de'] ?? ''),
                'streamer' => (string)($live['streamer'] ?? ''),
                'viewers' => $realViewers,
                'status' => $st,
                'thumbnail' => $thumbImg,
                'pinned_product' => $p,
                'gia_uu_dai_live' => (float)($live['gia_uu_dai_live'] ?? ($p['gia_ban'] ?? 0)),
                'server_livekit_url' => (string)($live['server_livekit_url'] ?? 'wss://skinsyntax-live.livekit.cloud'),
                'url_ban_ghi' => $urlBanGhi,
                'tom_tat_phien_live' => (string)($live['tom_tat_phien_live'] ?? ''),
                'luot_xem' => $totalViews,
                'mat_do_nguoi_xem_dinh' => $peakViewers,
                'tong_doanh_thu' => $totalRevenue,
                'tong_don_hang' => $totalOrders,
                'tong_san_pham_ban' => $totalUnits,
                'ty_le_chot_don' => $cr,
                'danh_sach_deal' => $live['danh_sach_deal'] ?? [],
                'top_san_pham' => $live['top_san_pham'] ?? $defaultTopProducts,
                'description' => 'Khung giờ Live: ' . ($live['khung_gio_bat_dau'] ?? '') . ' - Giá sốc trong Live: ' . number_format($live['gia_uu_dai_live'] ?? 0) . 'đ'
            ];
        }

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
            if (empty($_SESSION['viewed_live_' . $curId])) {
                $_SESSION['viewed_live_' . $curId] = true;
                $phienLiveModel->tangLuotXem($curId);
                $currentLive['luot_xem'] = (int)($currentLive['luot_xem'] ?? 0) + 1;
                foreach ($liveSessions as &$lsItem) {
                    if ((string)$lsItem['id'] === $curId) {
                        $lsItem['luot_xem'] = $currentLive['luot_xem'];
                    }
                }
                unset($lsItem);
            }
        }

        $allProducts = $spModel->getAllProducts([], 1000);
        if (empty($allProducts)) {
            $allProducts = $spModel->getDiscountProducts([], 1000);
        }



        $viewDir = defined('VIEW_DIR') ? VIEW_DIR : __DIR__ . '/../views';
        require_once $viewDir . '/live.php';
    }

    public function apiLivekitToken(): void {
        while (ob_get_level() > 0) {
            @ob_end_clean();
        }
        header('Content-Type: application/json; charset=utf-8');

        $liveId = trim((string)($_GET['id'] ?? $_POST['id'] ?? $_GET['room'] ?? $_POST['room'] ?? '1'));

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
        $status = (string)($liveDoc['trang_thai'] ?? 'danglive');
        $role = current_role();
        $isHostOrAdmin = in_array($role, ['admin', 'nhanvien'], true);

        if (in_array($status, ['ended', 'ketthuc', 'cancelled'], true) && !$isHostOrAdmin) {
            echo json_encode([
                'ok' => true,
                'data' => [
                    'url' => 'wss://skinsyntax-live.livekit.cloud',
                    'server_url' => 'wss://skinsyntax-live.livekit.cloud',
                    'token' => '',
                    'participant_token' => '',
                    'roomName' => 'skinsyntax_room_' . ($liveDoc['ma_phong'] ?? $liveId),
                    'can_publish' => true
                ],
                'message' => 'Phiên LiveStream đã kết thúc'
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
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

        $wsUrl = function_exists('ss_env') ? ss_env('LIVEKIT_URL', 'wss://skinsyntax-live.livekit.cloud') : (getenv('LIVEKIT_URL') ?: 'wss://skinsyntax-live.livekit.cloud');
        $apiKey = function_exists('ss_env') ? ss_env('LIVEKIT_API_KEY', 'devkey') : (getenv('LIVEKIT_API_KEY') ?: 'devkey');
        $apiSecret = function_exists('ss_env') ? ss_env('LIVEKIT_API_SECRET', 'secret') : (getenv('LIVEKIT_API_SECRET') ?: 'secret');

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
                'can_publish' => $canPublish
            ],
            'message' => 'Cấp LiveKit AccessToken thành công'
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
            $pStock = (int)($p['so_luong_kho'] ?? $p['so_luong'] ?? $p['ton_kho'] ?? 20);
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

    public function apiLiveChat(): void {
        header('Content-Type: application/json; charset=utf-8');
        $message = trim((string)($_POST['message'] ?? $_GET['message'] ?? ''));
        $productId = trim((string)($_POST['product_id'] ?? $_GET['product_id'] ?? ''));
        $pinnedPrice = (float)($_POST['pinned_price'] ?? $_GET['pinned_price'] ?? 0);

        if ($message === '') {
            echo json_encode(['ok' => false, 'message' => 'Nội dung tin nhắn rỗng']);
            exit;
        }

        global $db;
        $mongoDb = $db ?? $this->pdo;

        $roomId = trim((string)($_POST['room_id'] ?? $_GET['room_id'] ?? $_POST['id'] ?? $_GET['id'] ?? ''));
        if ($roomId !== '') {
            require_once __DIR__ . '/../models/PhienLive.php';
            $phienLiveModel = new PhienLive($mongoDb);
            $liveDoc = $phienLiveModel->findById($roomId);
            if ($liveDoc && !in_array($liveDoc['trang_thai'], ['danglive', 'live'], true)) {
                echo json_encode([
                    'ok' => false,
                    'message' => 'Phiên LiveStream này hiện chưa diễn ra hoặc đã kết thúc. Khung chat và chốt đơn đã tạm khóa.'
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                exit;
            }
        }

        $spModel = new SanPham($mongoDb);
        $pinnedProduct = $productId !== '' ? $spModel->findById($productId) : null;

        $productName = $pinnedProduct ? (string)($pinnedProduct['ten_san_pham'] ?? 'Sản phẩm ghim ưu đãi') : 'Sản phẩm ghim ưu đãi';
        $productBrand = $pinnedProduct ? (string)($pinnedProduct['thuong_hieu'] ?? 'SkinSyntax') : 'SkinSyntax';
        $productPrice = $pinnedPrice > 0 ? $pinnedPrice : ($pinnedProduct ? (float)($pinnedProduct['gia_ban'] ?? 0) : 0);

        $lowerMsg = mb_strtolower($message);
        $isOrderCommand = (strpos($lowerMsg, 'chốt đơn') !== false || strpos($lowerMsg, 'mua ngay') !== false || strpos($lowerMsg, 'đặt hàng') !== false);

        $aiReply = '';
        if ($isOrderCommand) {
            if (!isset($_SESSION['gio_hang']) || !is_array($_SESSION['gio_hang'])) {
                $_SESSION['gio_hang'] = [];
            }
            $pIdStr = (string)($pinnedProduct['ma_san_pham'] ?? $pinnedProduct['id'] ?? $productId);
            if ($pIdStr !== '') {
                $currentQty = (int)($_SESSION['gio_hang'][$pIdStr] ?? 0);
                $_SESSION['gio_hang'][$pIdStr] = $currentQty + 1;
            }

            $userDisplayName = is_logged_in() ? (current_user()['ho_ten'] ?? 'bạn') : 'khách hàng';
            $aiReply = ' [Tư Vấn Viên AI]: Đã nhận lệnh chốt đơn 1x "' . $productName . '" với giá ưu đãi ' . number_format($productPrice) . 'đ cho ' . $userDisplayName . '! Sản phẩm đã được thêm vào giỏ hàng. Bạn có thể bấm vào giỏ hàng ở góc phải để xem đơn nhé!';
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
                $aiReply = '[Trợ Lý AI SkinSyntax]: ' . trim($cleanAnswer);
            } else if (strpos($lowerMsg, 'xuất xứ') !== false || strpos($lowerMsg, 'sản xuất') !== false || strpos($lowerMsg, 'ở đâu') !== false || strpos($lowerMsg, 'nguồn gốc') !== false || strpos($lowerMsg, 'hãng nào') !== false || strpos($lowerMsg, 'nước nào') !== false || strpos($lowerMsg, 'thương hiệu') !== false) {
                $originStr = !empty($pinnedProduct['xuat_xu']) ? $pinnedProduct['xuat_xu'] : 'chính hãng cao cấp';
                $aiReply = '[Trợ Lý AI SkinSyntax]: Sản phẩm "' . $productName . '" thuộc thương hiệu nổi tiếng ' . $productBrand . ', xuất xứ từ ' . $originStr . ' chính hãng 100%! Đang có giá ưu đãi chỉ ' . number_format($productPrice) . 'đ trong phiên Live này. Bạn gõ "chốt đơn" để đặt ngay nhé!';
            } else if (strpos($lowerMsg, 'tốt không') !== false || strpos($lowerMsg, 'ổn không') !== false || strpos($lowerMsg, 'ok không') !== false || strpos($lowerMsg, 'chất lượng') !== false || strpos($lowerMsg, 'có nên mua') !== false || strpos($lowerMsg, 'thế nào') !== false || strpos($lowerMsg, 'được không') !== false) {
                $originStr = !empty($pinnedProduct['xuat_xu']) ? $pinnedProduct['xuat_xu'] : 'chính hãng';
                $aiReply = '[Trợ Lý AI SkinSyntax]: Sản phẩm "' . $productName . '" đến từ thương hiệu nổi tiếng ' . $productBrand . ' (' . $originStr . ') rất chất lượng và an toàn ạ! Dưỡng chất dịu nhẹ, lên màu chuẩn và nhận được hàng ngàn đánh giá 5 sao. Trong phiên Live hôm nay đang giảm sâu chỉ còn ' . number_format($productPrice) . 'đ, rất đáng mua bạn nhé!';
            } else if (strpos($lowerMsg, 'dành cho') !== false || strpos($lowerMsg, 'loại da') !== false || strpos($lowerMsg, 'hợp với') !== false || strpos($lowerMsg, 'ai dùng') !== false) {
                $aiReply = '[Trợ Lý AI SkinSyntax]: Sản phẩm "' . $productName . '" (' . $productBrand . ') được thiết kế lành tính, tương thích cho mọi làn da. Rất thích hợp để bạn sử dụng mỗi ngày!';
            } else if (strpos($lowerMsg, 'dùng thế nào') !== false || strpos($lowerMsg, 'cách dùng') !== false || strpos($lowerMsg, 'hướng dẫn') !== false || strpos($lowerMsg, 'sử dụng') !== false) {
                $aiReply = '[Trợ Lý AI SkinSyntax]: Cách sử dụng sản phẩm "' . $productName . '": Thoa một lượng vừa đủ lên vùng da đã làm sạch, massage nhẹ nhàng để dưỡng chất thẩm thấu tốt nhất!';
            } else if (strpos($lowerMsg, 'da dầu') !== false || strpos($lowerMsg, 'mụn') !== false || strpos($lowerMsg, 'thành phần') !== false || strpos($lowerMsg, 'hoạt chất') !== false) {
                $ingStr = !empty($ingredients) ? $ingredients : (!empty($pinnedProduct['mo_ta']) ? mb_substr(strip_tags($pinnedProduct['mo_ta']), 0, 150) : 'Chiết xuất dịu nhẹ, an toàn chuẩn y khoa.');
                $aiReply = '[Trợ Lý AI SkinSyntax]: Thành phần sản phẩm "' . $productName . '" (' . $productBrand . ') bao gồm: ' . $ingStr . '. Tương thích tối ưu cho làn da của bạn!';
            } else if (strpos($lowerMsg, 'giá') !== false || strpos($lowerMsg, 'sale') !== false || strpos($lowerMsg, 'ưu đãi') !== false || strpos($lowerMsg, 'rẻ') !== false || strpos($lowerMsg, 'bao nhiêu') !== false) {
                $aiReply = '[Trợ Lý AI SkinSyntax]: Sản phẩm "' . $productName . '" đang được giảm giá ưu đãi trong phiên Live này chỉ còn ' . number_format($productPrice) . 'đ! Bạn gõ "chốt đơn" hoặc bấm nút " MUA NGAY TRONG LIVE" để sở hữu ngay nhé!';
            } else {
                $descSnippet = !empty($pinnedProduct['mo_ta']) ? mb_substr(strip_tags($pinnedProduct['mo_ta']), 0, 120) : 'Sản phẩm chính hãng chất lượng cao';
                $aiReply = '[Trợ Lý AI SkinSyntax]: Sản phẩm "' . $productName . '" thuộc thương hiệu ' . $productBrand . ' (' . $origin . '). Mô tả: ' . $descSnippet . '... Đang giảm ưu đãi chỉ còn ' . number_format($productPrice) . 'đ trong phiên Live. Bạn gõ "chốt đơn" để đặt ngay nhé!';
            }
        }

        $senderName = is_logged_in() ? (current_user()['ho_ten'] ?? 'Khách hàng') : 'Khách hàng';
        if (!empty($phienLiveModel) && $roomId !== '') {
            $phienLiveModel->luuTinNhanChat($roomId, $senderName, $message, false, false);
            if ($aiReply !== '') {
                $phienLiveModel->luuTinNhanChat($roomId, 'AI Co-Host', $aiReply, true, $isOrderCommand);
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

        $roomId = trim((string)($_GET['room_id'] ?? $_GET['id'] ?? $_POST['room_id'] ?? $_POST['id'] ?? '1'));

        require_once __DIR__ . '/../models/PhienLive.php';
        $phienLiveModel = new PhienLive($mongoDb);
        $history = $phienLiveModel->getLichSuChat($roomId, 100);

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

        $roomId = trim((string)($_GET['id'] ?? $_GET['room_id'] ?? $_GET['room'] ?? '1'));
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
        $marketPrice = (float)($pinnedProduct['gia_thi_truong'] ?? ($originalPrice > 0 ? $originalPrice * 1.25 : 0));
        
        $discountPercent = 0;
        if ($marketPrice > $livePrice && $marketPrice > 0) {
            $discountPercent = round((($marketPrice - $livePrice) / $marketPrice) * 100);
        }

        echo json_encode([
            'ok' => true,
            'data' => [
                'live_id' => (string)$live['id'],
                'title' => (string)$live['tieu_de'],
                'status' => (string)$live['trang_thai'],
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
                    'so_luong_ton_kho' => (int)($pinnedProduct['so_luong_ton_kho'] ?? 50),
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
        $dealStock = max(1, (int)($_POST['so_luong_kho_deal'] ?? $_GET['so_luong_kho_deal'] ?? 20));

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

        if ($liveId === '' || $productId === '') {
            if ($isAjax) {
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode(['ok' => false, 'message' => 'Thiếu tham số phiên Live hoặc Mã sản phẩm'], JSON_UNESCAPED_UNICODE);
                exit;
            }
            set_flash('error', 'Thiếu tham số phiên Live hoặc Mã sản phẩm');
            header('Location: ' . $targetUrl);
            exit;
        }

        global $db;
        $mongoDb = $db ?? $this->pdo;
        require_once __DIR__ . '/../models/PhienLive.php';
        $phienLiveModel = new PhienLive($mongoDb);

        $targetLive = $phienLiveModel->getLiveById($liveId);
        $st = (string)($targetLive['trang_thai'] ?? 'danglive');

        if ($st === 'ketthuc' || $st === 'ended') {
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
                'message' => $ok ? "Đã chọn SP #{$productId} & bật deal {$durationMinutes} phút!" : 'Không thể chọn sản phẩm',
                'live_id' => $liveId,
                'pinned_product_id' => $productId,
                'gia_uu_dai_live' => $livePrice
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            exit;
        }

        set_flash($ok ? 'success' : 'error', $ok ? "Đã chọn SP #{$productId} & bật deal {$durationMinutes} phút!" : 'Không thể chọn sản phẩm');
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
            echo json_encode(['ok' => false, 'message' => 'Bạn không có quyền thực hiện thao tác này'], JSON_UNESCAPED_UNICODE);
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

        $uploadDir = __DIR__ . '/../../../public/uploads/live_recordings';
        if (!is_dir($uploadDir)) {
            @mkdir($uploadDir, 0777, true);
        }

        $ext = 'webm';
        if (!empty($file['type']) && str_contains($file['type'], 'mp4')) {
            $ext = 'mp4';
        }

        $filename = 'live_rec_' . $liveId . '_' . time() . '.' . $ext;
        $targetPath = $uploadDir . '/' . $filename;

        if (move_uploaded_file($file['tmp_name'], $targetPath)) {
            $publicUrl = BASE_URL . '/public/uploads/live_recordings/' . $filename;

            global $db;
            $mongoDb = $db ?? $this->pdo;
            require_once __DIR__ . '/../models/PhienLive.php';
            $phienLiveModel = new PhienLive($mongoDb);
            $phienLiveModel->capNhatPhienLive($liveId, [
                'url_ban_ghi' => $publicUrl,
                'trang_thai' => 'ketthuc'
            ]);

            echo json_encode([
                'ok' => true,
                'url' => $publicUrl,
                'message' => 'Đã tự động lưu bản ghi video Livestream thành công!'
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            exit;
        }

        echo json_encode(['ok' => false, 'message' => 'Không thể di chuyển file video đã quay'], JSON_UNESCAPED_UNICODE);
        exit;
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
        $qty = max(1, (int)($_POST['so_luong_kho_deal'] ?? 20));
        $start = trim((string)($_POST['khung_gio_bat_dau'] ?? date('H:i')));
        $end = trim((string)($_POST['khung_gio_ket_thuc'] ?? date('H:i', strtotime('+30 minutes'))));

        if ($liveId === '' || $productId === '') {
            set_flash('error', 'Vui lòng chọn phiên Live và Mã sản phẩm');
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
        set_flash($ok ? 'success' : 'error', $ok ? 'Đã thêm sản phẩm ưu đãi Flash Deal mới vào phiên Live!' : 'Không thể thêm sản phẩm');
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

            if ($liveDoc && !in_array($liveDoc['trang_thai'], ['danglive', 'live'], true)) {
                echo json_encode([
                    'ok' => false,
                    'message' => 'Phiên LiveStream này hiện đã kết thúc hoặc tạm ngưng. Không thể mua giá ưu đãi Live.'
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                exit;
            }

            if ($liveDoc && !empty($liveDoc['ma_san_pham_ghim'])) {
                if ($productId === '') {
                    $productId = (string)$liveDoc['ma_san_pham_ghim'];
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

        $roomId = trim((string)($_GET['room'] ?? $_POST['room'] ?? $_GET['id'] ?? $_POST['id'] ?? '1'));
        $sessionId = session_id() ?: (string)($_SERVER['REMOTE_ADDR'] ?? 'guest_' . rand(1000, 9999));

        global $db;
        $mongoDb = $db ?? $this->pdo;

        $now = time();
        $cutoff = $now - 15;
        $activeCount = 1;

        try {
            if ($mongoDb && isset($mongoDb->phien_live_viewers)) {
                $mongoDb->phien_live_viewers->updateOne(
                    ['room_id' => (string)$roomId, 'session_id' => (string)$sessionId],
                    ['$set' => ['last_seen' => $now, 'room_id' => (string)$roomId, 'session_id' => (string)$sessionId]],
                    ['upsert' => true]
                );
                $activeCount = $mongoDb->phien_live_viewers->countDocuments([
                    'room_id' => (string)$roomId,
                    'last_seen' => ['$gte' => $cutoff]
                ]);
            }
        } catch (Throwable $e) {
            $activeCount = 1;
        }

        echo json_encode([
            'ok' => true,
            'room_id' => $roomId,
            'active_viewers' => max(1, (int)$activeCount)
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
        $cutoff = $now - 15;
        $counts = [];

        try {
            if ($mongoDb && isset($mongoDb->phien_live_viewers)) {
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
