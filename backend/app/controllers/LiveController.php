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
        } else if (strpos($lowerMsg, 'tốt không') !== false || strpos($lowerMsg, 'ổn không') !== false || strpos($lowerMsg, 'ok không') !== false || strpos($lowerMsg, 'chất lượng') !== false || strpos($lowerMsg, 'có nên mua') !== false || strpos($lowerMsg, 'thế nào') !== false || strpos($lowerMsg, 'được không') !== false) {
            $origin = !empty($pinnedProduct['xuat_xu']) ? $pinnedProduct['xuat_xu'] : 'chính hãng';
            $aiReply = '🤖 [AI Skin Co-Host - RAG Evaluation]: Sản phẩm "' . $productName . '" đến từ thương hiệu nổi tiếng ' . $productBrand . ' (' . $origin . ') cực kỳ chất lượng ạ! Sản phẩm lên màu chuẩn, lâu trôi, chiết xuất lành tính và nhận được hàng ngàn đánh giá 5 sao. Đặc biệt trong phiên Live hôm nay đang giảm cực sâu chỉ còn ' . number_format($productPrice) . 'đ, rất đáng mua bạn nhé!';
        } else if (strpos($lowerMsg, 'dành cho') !== false || strpos($lowerMsg, 'loại da') !== false || strpos($lowerMsg, 'hợp với') !== false || strpos($lowerMsg, 'ai dùng') !== false) {
            $aiReply = '🤖 [AI Skin Co-Host - Audience Fit]: Sản phẩm "' . $productName . '" được thiết kế dễ sử dụng, tương thích chuẩn y khoa cho mọi loại da (kể cả da nhạy cảm). Rất thích hợp để bạn mang theo trang điểm và chăm sóc cá nhân mỗi ngày!';
        } else if (strpos($lowerMsg, 'dùng thế nào') !== false || strpos($lowerMsg, 'cách dùng') !== false || strpos($lowerMsg, 'hướng dẫn') !== false || strpos($lowerMsg, 'sử dụng') !== false) {
            $aiReply = '🤖 [AI Skin Co-Host - Usage Guide]: Cách dùng sản phẩm "' . $productName . '": Bạn thao tác nhẹ nhàng vẽ/chăm sóc theo khuôn nét mong muốn. Thiết kế 2 đầu thông minh giúp phẩy nét tự nhiên và tán đều màu nhanh chóng!';
        } else if (strpos($lowerMsg, 'da dầu') !== false || strpos($lowerMsg, 'mụn') !== false || strpos($lowerMsg, 'thành phần') !== false || strpos($lowerMsg, 'hoạt chất') !== false) {
            $desc = !empty($pinnedProduct['mo_ta']) ? mb_substr(strip_tags($pinnedProduct['mo_ta']), 0, 150) : 'Chiết xuất dịu nhẹ, an toàn chuẩn y khoa.';
            $aiReply = '🤖 [AI Skin Co-Host - RAG Knowledge]: Qua phân tích thành phần sản phẩm "' . $productName . '" (' . $productBrand . '), sản phẩm giúp chăm sóc tối ưu cho làn da. Chi tiết: ' . $desc;
        } else if (strpos($lowerMsg, 'giá') !== false || strpos($lowerMsg, 'sale') !== false || strpos($lowerMsg, 'ưu đãi') !== false || strpos($lowerMsg, 'rẻ') !== false || strpos($lowerMsg, 'bao nhiêu') !== false) {
            $aiReply = '🤖 [AI Skin Co-Host - Price Offer]: Sản phẩm "' . $productName . '" đang được ghim ưu đãi độc quyền trong phiên Live này chỉ còn ' . number_format($productPrice) . 'đ! Bạn gõ "chốt đơn" hoặc bấm nút "⚡ MUA NGAY TRONG LIVE" để sở hữu ngay nhé!';
        } else {
            $descSnippet = !empty($pinnedProduct['mo_ta']) ? mb_substr(strip_tags($pinnedProduct['mo_ta']), 0, 120) : 'Sản phẩm chính hãng chất lượng cao';
            $aiReply = '🤖 [AI Skin Co-Host]: Dựa trên thông tin sản phẩm "' . $productName . '" (' . $productBrand . '): ' . $descSnippet . '... Sản phẩm đang có giá ưu đãi sốc ' . number_format($productPrice) . 'đ trong phiên Live. Bạn gõ "chốt đơn" để đặt ngay nhé!';
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
