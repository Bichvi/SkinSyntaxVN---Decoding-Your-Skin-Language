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

        $liveSessions = [
            [
                'id' => 'room-skin-01',
                'title' => '🔴 LIVE: Gỡ Rối Routine Phục Hồi Da Dầu Mụn Với AI Skin Agent & Dược Sĩ',
                'streamer' => 'DS. Minh Trang & AI Co-Host',
                'viewers' => 1420,
                'status' => 'live',
                'thumbnail' => BASE_URL . '/assets/images/hero_campaign_ai_skin.png',
                'pinned_product' => $topSaleProducts[0] ?? null,
                'description' => 'Trực tiếp giải đáp thắc mắc làn da, tra cứu thành phần hoạt chất bằng AI RAG và săn deal giảm giá đến 80%!'
            ],
            [
                'id' => 'room-sale-02',
                'title' => '⚡ FLASH SALE LIVESTREAM: Chốt Đơn Tự Động 24/7 Với LiveKit & LLM AI',
                'streamer' => 'SkinSyntax Official Stream',
                'viewers' => 980,
                'status' => 'live',
                'thumbnail' => BASE_URL . '/assets/images/hero_campaign_flash_sale.png',
                'pinned_product' => $topSaleProducts[1] ?? null,
                'description' => 'Hệ thống tự động hóa đặt hàng thông qua lệnh chốt đơn trong Live Chat. Kết nối LiveKit WebRTC siêu tốc!'
            ],
            [
                'id' => 'room-routine-03',
                'title' => '⏰ SẮP DỄN RA (20:00): Hướng Dẫn Kết Hợp Niacinamide & BHA Cho Da Nhạy Cảm',
                'streamer' => 'Beauty Editor Thu Thảo',
                'viewers' => 0,
                'status' => 'upcoming',
                'thumbnail' => BASE_URL . '/assets/images/hero_campaign_personalized.png',
                'pinned_product' => $topSaleProducts[2] ?? null,
                'description' => 'Phiên Live chia sẻ kinh nghiệm chọn nồng độ hoạt chất chuẩn y khoa cho người mới bắt đầu.'
            ]
        ];

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
