<?php

require_once __DIR__ . '/../models/SanPham.php';
require_once __DIR__ . '/../models/TaiKhoan.php';
require_once __DIR__ . '/../models/HoaDon.php';

class AiChatCommerce {
    private SanPham $products;
    private TaiKhoan $accounts;
    private HoaDon $orders;
    private const SHIPPING_FEE = 30000;

    public function __construct($pdo) {
        $this->products = new SanPham($pdo);
        $this->accounts = new TaiKhoan($pdo);
        $this->orders = new HoaDon($pdo);
    }

    public function handleRequest(): void {
        header('Content-Type: application/json; charset=utf-8');

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(405);
            echo json_encode(['ok' => false, 'message' => 'Method not allowed.'], JSON_UNESCAPED_UNICODE);
            return;
        }

        $raw = file_get_contents('php://input');
        $data = json_decode((string)$raw, true);
        if (!is_array($data)) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'message' => 'Payload không hợp lệ.'], JSON_UNESCAPED_UNICODE);
            return;
        }

        $action = trim((string)($data['action'] ?? ''));
        $payload = is_array($data['payload'] ?? null) ? $data['payload'] : $data;

        try {
            $result = match ($action) {
                'get_cart' => $this->getCartResponse(),
                'add_items' => $this->addItems($this->normalizeItems($payload['items'] ?? [])),
                'update_qty' => $this->updateQty(
                    trim((string)($payload['product_id'] ?? '')),
                    max(1, (int)($payload['qty'] ?? 1))
                ),
                'remove_item' => $this->removeItem(trim((string)($payload['product_id'] ?? ''))),
                'clear_cart' => $this->clearCart(),
                'checkout_preview' => $this->checkoutPreview($this->normalizeItems($payload['items'] ?? [])),
                'place_order' => $this->placeOrder($payload),
                default => ['ok' => false, 'message' => 'Hành động không hợp lệ.'],
            };
        } catch (Throwable $e) {
            error_log('AiChatCommerce error: ' . $e->getMessage());
            http_response_code(500);
            echo json_encode(['ok' => false, 'message' => 'Không thể xử lý yêu cầu. Vui lòng thử lại.'], JSON_UNESCAPED_UNICODE);
            return;
        }

        echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /** @return list<array{id:string,qty:int}> */
    private function normalizeItems(array $items): array {
        $normalized = [];
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            $id = trim((string)($item['id'] ?? $item['product_id'] ?? ''));
            if ($id === '') {
                continue;
            }
            $normalized[] = [
                'id' => $id,
                'qty' => max(1, min(99, (int)($item['qty'] ?? 1))),
            ];
        }
        return $normalized;
    }

    private function ensureCartSession(): void {
        if (!isset($_SESSION['gio_hang']) || !is_array($_SESSION['gio_hang'])) {
            $_SESSION['gio_hang'] = [];
        }
    }

    private function normalizeCartProductId(string $productId): string {
        $productId = trim($productId);
        if ($productId === '') {
            return '';
        }
        if (str_starts_with($productId, 'product_')) {
            $productId = substr($productId, 8);
        }
        return trim($productId);
    }

    private function validateAndGetStock(string $productId, int $requestedQty, int $currentInCart = 0): array {
        $productId = $this->normalizeCartProductId($productId);
        if ($productId === '' || preg_match('/^doc_\d+$/i', $productId)) {
            return ['ok' => false, 'message' => 'Mã sản phẩm không hợp lệ. Hãy thêm lại từ thẻ sản phẩm trong chat.'];
        }
        $product = $this->products->findById($productId, true);
        if (!$product || !is_array($product)) {
            return ['ok' => false, 'message' => 'Sản phẩm không tồn tại.'];
        }
        if (method_exists($this->products, 'isProductAvailable') && !$this->products->isProductAvailable($product)) {
            return ['ok' => false, 'message' => 'Sản phẩm đã hết hàng hoặc tạm ngưng bán.'];
        }

        $stock = method_exists($this->products, 'getProductStock') ? $this->products->getProductStock($product) : null;
        if ($stock !== null && ($currentInCart + $requestedQty) > (int)$stock) {
            return [
                'ok' => false,
                'message' => 'Số lượng vượt tồn kho (' . (int)$stock . ').',
                'stock' => (int)$stock,
            ];
        }

        return ['ok' => true, 'product' => $product, 'stock' => $stock];
    }

    private function mapCartLine(array $product, int $qty): array {
        $id = (string)($product['ma_san_pham'] ?? '');
        $price = (int)($product['gia_ban'] ?? 0);
        if ($price <= 0) {
            $price = (int)($product['gia_thi_truong'] ?? 0);
        }

        return [
            'id' => $id,
            'name' => trim((string)($product['ten_san_pham'] ?? '')),
            'brand' => trim((string)($product['thuong_hieu'] ?? '')),
            'qty' => $qty,
            'price' => $price,
            'line_total' => $price * $qty,
            'image_url' => resolve_image_url((string)($product['link_hinh_anh'] ?? $product['hinh_anh'] ?? '')),
            'detail_url' => BASE_URL . '/index.php?r=chitiet&id=' . rawurlencode($id),
        ];
    }

    private function buildCartSummary(): array {
        $this->ensureCartSession();
        $cart = $_SESSION['gio_hang'];
        if ($cart === []) {
            return [
                'items' => [],
                'item_count' => 0,
                'total_qty' => 0,
                'subtotal' => 0,
                'shipping_fee' => self::SHIPPING_FEE,
                'grand_total' => self::SHIPPING_FEE,
            ];
        }

        $details = $this->products->findByIds(array_map('strval', array_keys($cart)), false);
        $items = [];
        $subtotal = 0;
        $totalQty = 0;

        foreach ($cart as $productId => $qty) {
            $id = trim((string)$productId);
            $qty = max(1, (int)$qty);
            $product = $details[$id] ?? null;
            if (!$product && ctype_digit($id)) {
                foreach ($details as $key => $candidate) {
                    if ((string)$key === $id) {
                        $product = $candidate;
                        break;
                    }
                }
            }
            if (!$product || !is_array($product)) {
                unset($_SESSION['gio_hang'][$productId]);
                continue;
            }

            $line = $this->mapCartLine($product, $qty);
            $items[] = $line;
            $subtotal += (int)$line['line_total'];
            $totalQty += $qty;
        }

        return [
            'items' => $items,
            'item_count' => count($items),
            'total_qty' => $totalQty,
            'subtotal' => $subtotal,
            'shipping_fee' => self::SHIPPING_FEE,
            'grand_total' => $subtotal + self::SHIPPING_FEE,
        ];
    }

    private function getCartResponse(): array {
        return [
            'ok' => true,
            'cart' => $this->buildCartSummary(),
            'is_logged_in' => is_logged_in(),
        ];
    }

    public function addItems(array $items): array {
        if ($items === []) {
            return ['ok' => false, 'message' => 'Không có sản phẩm để thêm.'];
        }

        $this->ensureCartSession();
        $added = [];
        $errors = [];

        foreach ($items as $item) {
            $id = $this->normalizeCartProductId((string)($item['id'] ?? ''));
            $qty = $item['qty'];
            if ($id === '') {
                $errors[] = ['id' => (string)($item['id'] ?? ''), 'message' => 'Mã sản phẩm không hợp lệ.'];
                continue;
            }
            $current = (int)($_SESSION['gio_hang'][$id] ?? 0);
            $check = $this->validateAndGetStock($id, $qty, $current);
            if (($check['ok'] ?? false) !== true) {
                $errors[] = ['id' => $id, 'message' => (string)($check['message'] ?? 'Không thêm được.')];
                continue;
            }

            $_SESSION['gio_hang'][$id] = $current + $qty;
            $added[] = ['id' => $id, 'qty' => $qty, 'name' => trim((string)($check['product']['ten_san_pham'] ?? ''))];
        }

        if ($added === []) {
            return [
                'ok' => false,
                'message' => 'Không thêm được sản phẩm nào.',
                'errors' => $errors,
                'cart' => $this->buildCartSummary(),
            ];
        }

        return [
            'ok' => true,
            'message' => 'Đã thêm ' . count($added) . ' sản phẩm vào giỏ hàng.',
            'added' => $added,
            'errors' => $errors,
            'cart' => $this->buildCartSummary(),
        ];
    }

    private function updateQty(string $productId, int $qty): array {
        if ($productId === '') {
            return ['ok' => false, 'message' => 'Thiếu mã sản phẩm.'];
        }

        $this->ensureCartSession();
        if (!isset($_SESSION['gio_hang'][$productId])) {
            return ['ok' => false, 'message' => 'Sản phẩm không có trong giỏ.'];
        }

        $check = $this->validateAndGetStock($productId, $qty, 0);
        if (($check['ok'] ?? false) !== true) {
            return ['ok' => false, 'message' => (string)($check['message'] ?? 'Cập nhật thất bại.')];
        }

        $_SESSION['gio_hang'][$productId] = $qty;

        return [
            'ok' => true,
            'message' => 'Đã cập nhật số lượng.',
            'cart' => $this->buildCartSummary(),
        ];
    }

    private function removeItem(string $productId): array {
        if ($productId === '') {
            return ['ok' => false, 'message' => 'Thiếu mã sản phẩm.'];
        }

        $this->ensureCartSession();
        unset($_SESSION['gio_hang'][$productId]);

        return [
            'ok' => true,
            'message' => 'Đã xóa sản phẩm khỏi giỏ.',
            'cart' => $this->buildCartSummary(),
        ];
    }

    private function clearCart(): array {
        $_SESSION['gio_hang'] = [];

        return [
            'ok' => true,
            'message' => 'Đã xóa giỏ hàng.',
            'cart' => $this->buildCartSummary(),
        ];
    }

    private function checkoutPreview(array $overrideItems): array {
        $checkoutItems = [];

        if ($overrideItems !== []) {
            foreach ($overrideItems as $item) {
                $checkoutItems[$item['id']] = $item['qty'];
            }
        } else {
            $this->ensureCartSession();
            foreach ($_SESSION['gio_hang'] as $id => $qty) {
                $checkoutItems[(string)$id] = max(1, (int)$qty);
            }
        }

        if ($checkoutItems === []) {
            return ['ok' => false, 'message' => 'Giỏ hàng trống.'];
        }

        $items = [];
        $subtotal = 0;
        foreach ($checkoutItems as $productId => $qty) {
            $product = $this->products->findById((string)$productId, true);
            if (!$product) {
                continue;
            }
            $line = $this->mapCartLine($product, max(1, (int)$qty));
            $items[] = $line;
            $subtotal += (int)$line['line_total'];
        }

        if ($items === []) {
            return ['ok' => false, 'message' => 'Không có sản phẩm hợp lệ để thanh toán.'];
        }

        $user = current_user() ?? [];
        $email = trim((string)($user['email'] ?? ''));
        $customer = $email !== '' ? $this->accounts->getKhachHangByEmail($email) : null;

        $defaultReceiver = [
            'ten_nguoi_nhan' => trim((string)($customer['ho_ten'] ?? ($user['ho_ten'] ?? ''))),
            'sdt_nguoi_nhan' => trim((string)($customer['so_dien_thoai'] ?? '')),
            'dia_chi_giao_hang' => trim((string)($customer['dia_chi'] ?? '')),
        ];

        return [
            'ok' => true,
            'preview' => [
                'items' => $items,
                'subtotal' => $subtotal,
                'shipping_fee' => self::SHIPPING_FEE,
                'grand_total' => $subtotal + self::SHIPPING_FEE,
                'default_receiver' => $defaultReceiver,
                'payment_methods' => $this->getPaymentMethods(),
            ],
            'is_logged_in' => is_logged_in(),
            'login_url' => BASE_URL . '/index.php?r=dangnhap',
        ];
    }

    private function getPaymentMethods(): array {
        $methods = [
            ['id' => 'cod', 'label' => 'Thanh toán khi nhận hàng (COD)'],
        ];

        if ($this->isQrTransferEnabled()) {
            $methods[] = ['id' => 'bank_transfer_qr', 'label' => 'Chuyển khoản QR'];
        }

        return $methods;
    }

    private function isQrTransferEnabled(): bool {
        return trim((string)(defined('BANK_TRANSFER_BANK_ID') ? BANK_TRANSFER_BANK_ID : '')) !== ''
            && trim((string)(defined('BANK_TRANSFER_ACCOUNT_NO') ? BANK_TRANSFER_ACCOUNT_NO : '')) !== ''
            && trim((string)(defined('BANK_TRANSFER_ACCOUNT_NAME') ? BANK_TRANSFER_ACCOUNT_NAME : '')) !== '';
    }

    private function buildShippingAddress(array $receiver): string {
        $parts = array_values(array_filter([
            trim((string)($receiver['dia_chi_chi_tiet'] ?? '')),
            trim((string)($receiver['phuong_xa'] ?? '')),
            trim((string)($receiver['quan_huyen'] ?? '')),
            trim((string)($receiver['tinh_thanh'] ?? '')),
        ], static fn(string $part): bool => $part !== ''));

        $address = implode(', ', $parts);
        if ($address === '') {
            $address = trim((string)($receiver['dia_chi_giao_hang'] ?? ''));
        }

        $note = trim((string)($receiver['ghi_chu_giao_hang'] ?? ''));
        if ($address !== '' && $note !== '') {
            $address .= ' | Ghi chú: ' . $note;
        }

        return $address;
    }

    private function placeOrder(array $payload): array {
        if (!is_logged_in()) {
            return [
                'ok' => false,
                'message' => 'Vui lòng đăng nhập để đặt hàng.',
                'login_url' => BASE_URL . '/index.php?r=dangnhap',
            ];
        }

        $overrideItems = $this->normalizeItems($payload['items'] ?? []);
        $checkoutItems = [];

        if ($overrideItems !== []) {
            foreach ($overrideItems as $item) {
                $checkoutItems[$item['id']] = $item['qty'];
            }
        } else {
            $this->ensureCartSession();
            foreach ($_SESSION['gio_hang'] as $id => $qty) {
                $checkoutItems[(string)$id] = max(1, (int)$qty);
            }
        }

        if ($checkoutItems === []) {
            return ['ok' => false, 'message' => 'Giỏ hàng trống, không thể đặt hàng.'];
        }

        $receiver = is_array($payload['receiver'] ?? null) ? $payload['receiver'] : [];
        $tenNguoiNhan = trim((string)($receiver['ten_nguoi_nhan'] ?? ''));
        $sdtNguoiNhan = trim((string)($receiver['sdt_nguoi_nhan'] ?? ''));
        $diaChiGiaoHang = $this->buildShippingAddress($receiver);

        if ($tenNguoiNhan === '' || $sdtNguoiNhan === '' || $diaChiGiaoHang === '') {
            return ['ok' => false, 'message' => 'Vui lòng điền đầy đủ thông tin nhận hàng.'];
        }

        $paymentMethod = strtolower(trim((string)($payload['payment_method'] ?? 'cod')));
        $allowed = ['cod'];
        if ($this->isQrTransferEnabled()) {
            $allowed[] = 'bank_transfer_qr';
        }
        if (!in_array($paymentMethod, $allowed, true)) {
            return ['ok' => false, 'message' => 'Phương thức thanh toán không hợp lệ.'];
        }

        $subtotal = 0;
        foreach ($checkoutItems as $productId => $qty) {
            $product = $this->products->findById((string)$productId, true);
            if (!$product) {
                return ['ok' => false, 'message' => 'Một số sản phẩm không còn tồn tại.'];
            }
            $price = (int)($product['gia_ban'] ?? 0);
            if ($price <= 0) {
                $price = (int)($product['gia_thi_truong'] ?? 0);
            }
            $subtotal += $price * max(1, (int)$qty);
        }

        $user = current_user() ?? [];
        $email = trim((string)($user['email'] ?? ''));

        try {
            if (!empty($receiver['save_as_default'])) {
                $this->accounts->saveThongTinKhachHang($email, [
                    'ho_ten' => $tenNguoiNhan,
                    'so_dien_thoai' => $sdtNguoiNhan,
                    'dia_chi' => $diaChiGiaoHang,
                ]);
            }

            $maHoaDon = $this->orders->taoDonHang([
                'email' => $email,
                'ho_ten_mac_dinh' => (string)($user['ho_ten'] ?? ''),
                'ten_nguoi_nhan' => $tenNguoiNhan,
                'sdt_nguoi_nhan' => $sdtNguoiNhan,
                'dia_chi_giao_hang' => $diaChiGiaoHang,
                'hinh_thuc_thanh_toan' => $paymentMethod,
                'phi_van_chuyen' => self::SHIPPING_FEE,
                'tam_tinh' => $subtotal,
                'so_tien_giam' => 0,
                'checkout_items' => $checkoutItems,
            ]);

            foreach (array_keys($checkoutItems) as $idSp) {
                unset($_SESSION['gio_hang'][$idSp]);
            }

            $successMessage = $paymentMethod === 'bank_transfer_qr'
                ? 'Đơn hàng đã được tạo. Vui lòng chuyển khoản theo hướng dẫn.'
                : 'Đặt hàng thành công! Cảm ơn bạn đã mua sắm tại SkinSyntax.';

            return [
                'ok' => true,
                'message' => $successMessage,
                'order_id' => $maHoaDon,
                'payment_method' => $paymentMethod,
                'success_url' => BASE_URL . '/index.php?r=camon&ma_hoa_don=' . rawurlencode((string)$maHoaDon),
                'cart' => $this->buildCartSummary(),
            ];
        } catch (Throwable $e) {
            error_log('placeOrder chat: ' . $e->getMessage());
            return ['ok' => false, 'message' => 'Không thể đặt hàng lúc này: ' . $e->getMessage()];
        }
    }

    /**
     * Xử lý tin nhắn commerce cục bộ (không cần gọi AI).
     * @return array<string,mixed>|null
     */
    public function tryLocalCommerceReply(string $message): ?array {
        $normalized = function_exists('mb_strtolower') ? mb_strtolower(trim($message), 'UTF-8') : strtolower(trim($message));

        if (preg_match('/\b(xem|kiểm tra|kiem tra|mở|mo)\b.{0,20}(giỏ|gio)\b/u', $normalized)
            || preg_match('/\b(giỏ hàng|gio hang)\b.{0,15}(của tôi|cua toi|hiện tại|hien tai)?\b/u', $normalized)) {
            $cart = $this->buildCartSummary();
            if (($cart['item_count'] ?? 0) === 0) {
                return [
                    'ok' => true,
                    'answer' => 'Giỏ hàng của bạn đang trống. Bạn có thể nhờ mình gợi ý sản phẩm rồi thêm vào giỏ ngay trong chat nhé!',
                    'intent' => 'VIEW_CART',
                    'commerce' => ['action' => 'show_cart', 'cart' => $cart],
                ];
            }

            return [
                'ok' => true,
                'answer' => 'Đây là giỏ hàng hiện tại của bạn (' . (int)$cart['item_count'] . ' mặt hàng, tổng ' . number_format((int)$cart['grand_total'], 0, ',', '.') . ' đ). Bạn có thể chỉnh số lượng hoặc bấm **Đặt hàng** để hoàn tất.',
                'intent' => 'VIEW_CART',
                'commerce' => ['action' => 'show_cart', 'cart' => $cart],
            ];
        }

        if (preg_match('/\b(dat hang|đặt hàng|dat don|đặt đơn|thanh toan|thanh toán|checkout)\b/u', $normalized)) {
            $cart = $this->buildCartSummary();
            if (($cart['item_count'] ?? 0) === 0) {
                return [
                    'ok' => true,
                    'answer' => 'Giỏ hàng đang trống nên chưa thể đặt hàng. Hãy gợi ý sản phẩm trước, hoặc nói kiểu "cho tôi 2 serum X" để mình thêm giúp nhé!',
                    'intent' => 'ORDER',
                    'commerce' => ['action' => 'show_cart', 'cart' => $cart],
                ];
            }

            return [
                'ok' => true,
                'answer' => 'Giỏ hàng có ' . (int)$cart['item_count'] . ' mặt hàng (tổng ' . number_format((int)$cart['grand_total'], 0, ',', '.') . ' đ). Bấm **Đặt hàng ngay** bên dưới để điền thông tin giao hàng và xác nhận.',
                'intent' => 'ORDER',
                'commerce' => ['action' => 'show_cart', 'cart' => $cart],
            ];
        }

        return null;
    }
}
