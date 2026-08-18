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

        $liveSessions = [];
        foreach ($rawLives as $idx => $live) {
            $p = null;
            if (!empty($live['ma_san_pham_ghim'])) {
                $p = $spModel->findById($live['ma_san_pham_ghim']);
            }
            if (!$p && !empty($topSaleProducts[$idx])) {
                $p = $topSaleProducts[$idx];
            }
            $liveSessions[] = [
                'id' => (string)($live['ma_phong'] ?? $live['id'] ?? ($idx + 1)),
                'title' => (string)($live['tieu_de'] ?? ''),
                'streamer' => (string)($live['streamer'] ?? ''),
                'viewers' => (int)($live['luot_xem'] ?? 0),
                'status' => in_array($live['trang_thai'], ['danglive', 'live'], true) ? 'live' : ($live['trang_thai'] === 'chuamoi' ? 'upcoming' : 'ended'),
                'thumbnail' => BASE_URL . '/assets/images/' . ($idx === 1 ? 'hero_campaign_flash_sale.png' : ($idx === 2 ? 'hero_campaign_personalized.png' : 'hero_campaign_ai_skin.png')),
                'pinned_product' => $p,
                'gia_uu_dai_live' => (float)($live['gia_uu_dai_live'] ?? ($p['gia_ban'] ?? 0)),
                'server_livekit_url' => (string)($live['server_livekit_url'] ?? 'wss://skinsyntax-live.livekit.cloud'),
                'description' => 'Khung giờ Live: ' . ($live['khung_gio_bat_dau'] ?? '') . ' - Giá sốc trong Live: ' . number_format($live['gia_uu_dai_live'] ?? 0) . 'đ'
            ];
        }

        $selectedId = trim((string)($_GET['id'] ?? $_GET['room_id'] ?? $_GET['room'] ?? ''));
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

        require_once __DIR__ . '/../views/live.php';
    }

    public function apiLivekitToken(): void {
        header('Content-Type: application/json; charset=utf-8');
        $room = trim((string)($_GET['room'] ?? $_POST['room'] ?? 'skinsyntax-live-room'));
        $user = trim((string)($_GET['user'] ?? $_POST['user'] ?? 'khach_hang_' . rand(100, 999)));

        // LiveKit Token Structure for WebRTC Integration (Self-Hosted / LiveKit Cloud)
        $livekitConfig = [
            'api_key' => 'devkey',
            'api_secret' => 'secret',
            'ws_url' => 'wss://skinsyntax-live.livekit.cloud',
            'room' => $room,
            'identity' => $user,
            'token' => 'mock_livekit_jwt_token_' . md5($room . $user . time()),
            'status' => 'connected',
            'transcription_enabled' => true,
            'recording_enabled' => true
        ];

        echo json_encode([
            'ok' => true,
            'data' => $livekitConfig,
            'message' => 'LiveKit RTC Token generated successfully'
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
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
        $spModel = new SanPham($mongoDb);
        $pinnedProduct = $productId !== '' ? $spModel->findById($productId) : null;

        $productName = $pinnedProduct ? (string)($pinnedProduct['ten_san_pham'] ?? 'Sản phẩm ghim ưu đãi') : 'Sản phẩm ghim ưu đãi';
        $productBrand = $pinnedProduct ? (string)($pinnedProduct['thuong_hieu'] ?? 'SkinSyntax') : 'SkinSyntax';
        $productPrice = $pinnedPrice > 0 ? $pinnedPrice : ($pinnedProduct ? (float)($pinnedProduct['gia_ban'] ?? 0) : 0);

        $lowerMsg = mb_strtolower($message);
        $isOrderCommand = (strpos($lowerMsg, 'chốt đơn') !== false || strpos($lowerMsg, 'mua ngay') !== false || strpos($lowerMsg, 'đặt hàng') !== false);

        $aiReply = '';
        if ($isOrderCommand) {
            if (!isset($_SESSION['gio_hang'])) {
                $_SESSION['gio_hang'] = [];
            }
            $pIdStr = (string)($pinnedProduct['ma_san_pham'] ?? $pinnedProduct['id'] ?? $productId);
            if ($pIdStr !== '') {
                $found = false;
                foreach ($_SESSION['gio_hang'] as &$item) {
                    if ((string)($item['ma_san_pham'] ?? $item['product_id'] ?? '') === $pIdStr) {
                        $item['so_luong'] = (int)($item['so_luong'] ?? 1) + 1;
                        $found = true;
                        break;
                    }
                }
                unset($item);
                if (!$found) {
                    $_SESSION['gio_hang'][] = [
                        'ma_san_pham' => $pIdStr,
                        'product_id' => $pIdStr,
                        'ten_san_pham' => $productName,
                        'gia_ban' => $productPrice,
                        'link_hinh_anh' => $pinnedProduct['link_hinh_anh'] ?? '',
                        'so_luong' => 1
                    ];
                }
            }

            $userDisplayName = is_logged_in() ? (current_user()['ho_ten'] ?? 'bạn') : 'khách hàng';
            $aiReply = '⚡ [AI Agent Auto-Checkout]: Đã nhận lệnh chốt đơn 1x "' . $productName . '" với giá ưu đãi ' . number_format($productPrice) . 'đ cho ' . $userDisplayName . '! Sản phẩm đã được tự động thêm vào giỏ hàng. Bạn có thể bấm vào giỏ hàng ở góc phải để xem đơn!';
        } else if (strpos($lowerMsg, 'da dầu') !== false || strpos($lowerMsg, 'mụn') !== false || strpos($lowerMsg, 'thành phần') !== false || strpos($lowerMsg, 'hoạt chất') !== false) {
            $desc = !empty($pinnedProduct['mo_ta']) ? mb_substr(strip_tags($pinnedProduct['mo_ta']), 0, 150) : 'Chiết xuất dịu nhẹ, an toàn chuẩn y khoa.';
            $aiReply = '🤖 [AI Skin Co-Host - RAG Knowledge]: Qua phân tích thành phần sản phẩm "' . $productName . '" (' . $productBrand . '), sản phẩm giúp chăm sóc tối ưu cho làn da. Chi tiết: ' . $desc;
        } else if (strpos($lowerMsg, 'giá') !== false || strpos($lowerMsg, 'sale') !== false || strpos($lowerMsg, 'ưu đãi') !== false || strpos($lowerMsg, 'rẻ') !== false) {
            $aiReply = '🤖 [AI Skin Co-Host]: Sản phẩm "' . $productName . '" đang được ghim ưu đãi độc quyền trong phiên Live này chỉ còn ' . number_format($productPrice) . 'đ! Bạn gõ "chốt đơn" hoặc bấm nút "⚡ MUA NGAY TRONG LIVE" để sở hữu ngay nhé!';
        } else {
            $aiReply = '🤖 [AI Skin Co-Host]: Cảm ơn câu hỏi về "' . $productName . '"! AI Agent tư vấn đang sẵn sàng hỗ trợ thông tin chi tiết và xử lý chốt đơn tự động cho bạn ngay trong phiên Live này!';
        }

        echo json_encode([
            'ok' => true,
            'user_message' => $message,
            'ai_response' => $aiReply,
            'is_order' => $isOrderCommand,
            'cart_count' => isset($_SESSION['gio_hang']) ? count($_SESSION['gio_hang']) : 0,
            'timestamp' => date('H:i:s')
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
}
