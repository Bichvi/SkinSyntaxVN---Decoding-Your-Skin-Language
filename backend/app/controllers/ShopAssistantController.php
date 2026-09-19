<?php
declare(strict_types=1);
require_once __DIR__ . '/../services/SupportKnowledge.php';
require_once __DIR__ . '/../services/PurchaseIntent.php';
require_once __DIR__ . '/../services/ChatProductCatalog.php';
require_once __DIR__ . '/../services/ChatPurchaseService.php';

final class ShopAssistantController
{
    private $db;
    public function __construct($db) { $this->db = $db; }

    public static function csrfToken(): string
    {
        if (empty($_SESSION['chat_purchase_csrf'])) $_SESSION['chat_purchase_csrf'] = bin2hex(random_bytes(32));
        return $_SESSION['chat_purchase_csrf'];
    }

    private function service(): ChatPurchaseService
    {
        return new ChatPurchaseService(new ChatProductCatalog($this->db), $_SESSION);
    }

    public function reply(string $message): ?array
    {
        if (mb_strlen($message) > 2000) return null;
        $support = (new SupportKnowledge())->answer($message, BASE_URL);
        $intent = PurchaseIntent::parse($message);
        if ($intent === null) return $support;
        try {
            $response = $this->service()->offer($intent);
            if ($support) {
                $response['answer'] .= "\n\n" . $support['answer'];
                $response['sources'] = $support['sources'];
                $response['support_topic'] = $support['support_topic'];
            }
            return $response;
        }
        catch (DomainException $error) { return ['ok'=>false, 'message'=>$error->getMessage()]; }
        catch (Throwable $error) {
            error_log('Chat purchase catalog lookup failed: ' . get_class($error) . ' code=' . $error->getCode());
            return ['ok'=>false, 'message'=>'Mình chưa đọc được danh mục lúc này. Bạn thử lại hoặc tìm sản phẩm bằng thanh tìm kiếm nhé.'];
        }
    }

    public function action(): void
    {
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        try {
            if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') throw new DomainException('Chỉ nhận yêu cầu POST.', 405);
            $raw = file_get_contents('php://input', false, null, 0, 8193);
            if (strlen($raw) > 8192) throw new DomainException('Yêu cầu quá dài.', 413);
            $data = json_decode($raw, true);
            if (!is_array($data)) throw new DomainException('Yêu cầu không hợp lệ.', 400);
            if (!is_string($data['csrf'] ?? null) || !hash_equals(self::csrfToken(), $data['csrf'])) throw new DomainException('Phiên xác nhận không hợp lệ. Vui lòng tải lại trang.', 403);
            foreach (['offer', 'product_id', 'action'] as $key) {
                if (!is_string($data[$key] ?? null) || strlen($data[$key]) > 128) throw new DomainException('Thông tin lựa chọn không hợp lệ.', 422);
            }
            if (!in_array($data['action'], ['select', 'checkout'], true)) throw new DomainException('Thao tác không hợp lệ.', 422);
            if (in_array(current_role(), ['admin', 'nhanvien'], true)) throw new DomainException('Vui lòng dùng tài khoản khách hàng để mua sắm.', 403);
            $service = $this->service();
            if ($data['action'] === 'select') {
                $quote = $service->quote($data['offer'], $data['product_id'], $data['quantity'] ?? null);
                echo json_encode(['ok'=>true, 'commerce'=>$quote], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                return;
            }
            $service->checkout($data['offer'], $data['product_id'], $data['quantity'] ?? null, $data['expected_price'] ?? null);
            if (!is_logged_in()) {
                $_SESSION['chat_checkout_resume_until'] = time() + 600;
                $redirect = BASE_URL . '/index.php?r=dangnhap';
            } else {
                unset($_SESSION['chat_checkout_resume_until']);
                $redirect = BASE_URL . '/index.php?r=thanhtoan';
            }
            echo json_encode(['ok'=>true, 'redirect_url'=>$redirect], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        } catch (DomainException $error) {
            http_response_code($error->getCode() ?: 422);
            echo json_encode(['ok'=>false, 'message'=>$error->getMessage()], JSON_UNESCAPED_UNICODE);
        } catch (Throwable $error) {
            http_response_code(503);
            error_log('Chat purchase action failed: ' . get_class($error) . ' code=' . $error->getCode());
            echo json_encode(['ok'=>false, 'message'=>'Chưa kiểm tra được sản phẩm. Vui lòng thử lại; chưa có đơn hàng nào được tạo.'], JSON_UNESCAPED_UNICODE);
        }
    }
}
