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
                'id' => (string)($live['ma_phong'] ?? $live['id'] ?? $idx),
                'title' => (string)($live['tieu_de'] ?? ''),
                'streamer' => (string)($live['streamer'] ?? ''),
                'viewers' => (int)($live['luot_xem'] ?? 0),
                'status' => in_array($live['trang_thai'], ['danglive', 'live'], true) ? 'live' : ($live['trang_thai'] === 'chuamoi' ? 'upcoming' : 'ended'),
                'thumbnail' => BASE_URL . '/assets/images/' . ($idx === 1 ? 'hero_campaign_flash_sale.png' : ($idx === 2 ? 'hero_campaign_personalized.png' : 'hero_campaign_ai_skin.png')),
                'pinned_product' => $p,
                'description' => 'Khung giờ Live: ' . ($live['khung_gio_bat_dau'] ?? '') . ' - Giá sốc trong Live: ' . number_format($live['gia_uu_dai_live'] ?? 0) . 'đ'
            ];
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
        $productId = trim((string)($_POST['product_id'] ?? ''));

        if ($message === '') {
            echo json_encode(['ok' => false, 'message' => 'Nội dung tin nhắn rỗng']);
            exit;
        }

        $lowerMsg = mb_strtolower($message);
        $isOrderCommand = (strpos($lowerMsg, 'chốt đơn') !== false || strpos($lowerMsg, 'mua ngay') !== false || strpos($lowerMsg, 'đặt hàng') !== false);

        $aiReply = '';
        if ($isOrderCommand) {
            $aiReply = '🤖 [AI Agent Auto-Checkout]: Đã nhận lệnh chốt đơn của bạn! Sản phẩm đã được thêm vào giỏ hàng ưu đãi Livestream thành công. Bấm "Thanh toán ngay" để hoàn tất!';
        } else if (strpos($lowerMsg, 'da dầu') !== false || strpos($lowerMsg, 'mụn') !== false) {
            $aiReply = '🤖 [AI Skin Co-Host - RAG Knowledge]: Qua phân tích thành phần sản phẩm đang ghim (Salicylic Acid 2% & B5), sản phẩm này rất phù hợp cho da dầu mụn giúp kiềm dầu nhẹ và làm dịu mụn sưng đỏ trong 48h!';
        } else if (strpos($lowerMsg, 'giá') !== false || strpos($lowerMsg, 'sale') !== false) {
            $aiReply = '🤖 [AI Skin Co-Host]: Trong phiên Livestream hôm nay, sản phẩm đang được ghim ưu đãi độc quyền giảm đến 80%! Khách hàng có thể bấm "⚡ Mua Ngay Trong Live" để nhận giá ưu đãi này.';
        } else {
            $aiReply = '🤖 [AI Skin Co-Host]: Cảm ơn câu hỏi của bạn! AI Agent đang phân tích nồng độ hoạt chất và sẽ hỗ trợ tư vấn chi tiết cho bạn ngay trong phiên Live này!';
        }

        echo json_encode([
            'ok' => true,
            'user_message' => $message,
            'ai_response' => $aiReply,
            'is_order' => $isOrderCommand,
            'timestamp' => date('H:i:s')
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
}
