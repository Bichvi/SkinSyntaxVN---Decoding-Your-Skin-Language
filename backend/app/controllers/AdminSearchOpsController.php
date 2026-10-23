<?php
// Trang admin chỉ đọc: sức khỏe hệ thống tìm kiếm (U11) - route admin_search_ops.
// Dữ liệu lấy từ Flask GET /api/ops/health (token nội bộ ở server, không lộ ra trình duyệt). Không ghi gì.

require_once __DIR__ . '/../models/QuanTri.php';
require_once __DIR__ . '/../services/SearchRecommendClient.php';

class AdminSearchOpsController
{
    private $pdo;

    public function __construct($pdo)
    {
        global $db;
        $this->pdo = $db ?? (is_object($pdo) && method_exists($pdo, 'raw') ? $pdo->raw() : $pdo);
    }

    private function requireAdmin(): void
    {
        if (!is_logged_in()) {
            set_flash('error', 'Vui lòng đăng nhập để truy cập khu vực quản trị.');
            redirect(BASE_URL . '/index.php?r=dangnhap');
        }
        if (current_role() !== 'admin') {
            http_response_code(403);
            $this->render('403', ['pageTitle' => 'Không có quyền truy cập']);
            exit;
        }
    }

    private function render(string $view, array $data = []): void
    {
        try {
            $data['notificationCenter'] = $data['notificationCenter'] ?? (new QuanTri($this->pdo))->getNotificationCenterData();
        } catch (Throwable $e) {
            $data['notificationCenter'] = [];
        }
        extract($data);
        $viewDir = defined('VIEW_DIR') ? VIEW_DIR : __DIR__ . '/../views';
        require $viewDir . '/admin/layouts/header.php';
        require $viewDir . '/admin/' . $view . '.php';
        require $viewDir . '/admin/layouts/footer.php';
    }

    public function index(): void
    {
        $this->requireAdmin();
        $client = SearchRecommendClient::fromEnv();
        $health = $client ? $client->opsHealth() : null;
        $this->render('search_ops', ['pageTitle' => 'Vận hành tìm kiếm', 'health' => $health]);
    }
}
