<?php
/**
 * ChatController - Xử lý chat support
 */

class ChatController {
    private $pdo;
    private $db;

    public function __construct($pdo) {
        $this->pdo = $pdo;
        $this->db = $pdo;
    }

    /**
     * Gửi tin nhắn chat
     * POST /index.php?r=chat_send
     */
    public function sendAction() {
        try {
            // Check user logged in
            if (!is_logged_in()) {
                http_response_code(401);
                echo json_encode(['error' => 'Unauthorized']);
                return;
            }

            // Get data from POST
            $noi_dung = trim($_POST['noi_dung'] ?? '');
            
            if (empty($noi_dung)) {
                http_response_code(400);
                echo json_encode(['error' => 'Nội dung không được trống']);
                return;
            }

            // Get current user
            $user = current_user();
            if (empty($user['email'])) {
                http_response_code(400);
                echo json_encode(['error' => 'Không tìm thấy thông tin người dùng']);
                return;
            }

            // Get customer ID from QuanTri model
            if (!class_exists('QuanTri')) {
                require_once __DIR__ . '/../models/QuanTri.php';
            }
            
            $quanTri = new QuanTri($this->pdo);
            $customer = $quanTri->getCustomerByEmail((string)($user['email'] ?? ''), (string)($user['ho_ten'] ?? ''));
            $ma_kh = (int)($customer['ma_kh'] ?? 0);

            if (empty($ma_kh)) {
                http_response_code(400);
                echo json_encode(['error' => 'Không tìm thấy khách hàng']);
                return;
            }

            // Insert message to database
            $sql = "INSERT INTO ho_tro_khach_hang (ma_kh, noi_dung, loai_tin_nhan, thoi_gian) 
                    VALUES (:ma_kh, :noi_dung, :loai_tin_nhan, NOW())";
            
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([
                ':ma_kh' => $ma_kh,
                ':noi_dung' => $noi_dung,
                ':loai_tin_nhan' => 'khach_hang'
            ]);

            http_response_code(201);
            echo json_encode([
                'ok' => true,
                'message' => 'Tin nhắn đã gửi',
                'id' => $this->pdo->lastInsertId()
            ]);

        } catch (Exception $e) {
            http_response_code(500);
            error_log('ChatController.sendAction error: ' . $e->getMessage());
            echo json_encode(['error' => 'Lỗi server: ' . $e->getMessage()]);
        }
    }

    /**
     * Đánh dấu tin nhắn đã đọc
     * POST /index.php?r=mark_chat_read
     */
    public function markReadAction() {
        try {
            // Check user logged in
            if (!is_logged_in()) {
                http_response_code(401);
                echo json_encode(['error' => 'Unauthorized']);
                return;
            }

            // Get current user
            $user = current_user();
            if (empty($user['email'])) {
                http_response_code(400);
                echo json_encode(['error' => 'Không tìm thấy thông tin người dùng']);
                return;
            }

            // Get customer ID from QuanTri model
            if (!class_exists('QuanTri')) {
                require_once __DIR__ . '/../models/QuanTri.php';
            }
            
            $quanTri = new QuanTri($this->pdo);
            $customer = $quanTri->getCustomerByEmail((string)($user['email'] ?? ''), (string)($user['ho_ten'] ?? ''));
            $ma_kh = (int)($customer['ma_kh'] ?? 0);

            if (empty($ma_kh)) {
                http_response_code(400);
                echo json_encode(['error' => 'Không tìm thấy khách hàng']);
                return;
            }

            // Mark all unread messages from staff as read
            $sql = "UPDATE ho_tro_khach_hang 
                    SET da_doc = 1, thoi_gian_doc = NOW()
                    WHERE ma_kh = :ma_kh AND loai_tin_nhan = 'nhan_vien' AND da_doc = 0";
            
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([':ma_kh' => $ma_kh]);

            http_response_code(200);
            echo json_encode([
                'ok' => true,
                'message' => 'Đã đánh dấu đã đọc'
            ]);

        } catch (Exception $e) {
            http_response_code(500);
            error_log('ChatController.markReadAction error: ' . $e->getMessage());
            echo json_encode(['error' => 'Lỗi server']);
        }
    }
}
?>
