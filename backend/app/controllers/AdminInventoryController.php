<?php

require_once __DIR__ . '/../models/InventoryBatch.php';
require_once __DIR__ . '/../models/QuanTri.php';

class AdminInventoryController
{
    private $db;
    private InventoryBatch $batches;

    public function __construct($db)
    {
        $this->db = is_object($db) && method_exists($db, 'raw') ? $db->raw() : $db;
        $this->batches = new InventoryBatch($this->db);
    }

    private function requireAdmin(): array
    {
        if (!function_exists('is_logged_in') || !is_logged_in()) {
            set_flash('error', 'Vui lòng đăng nhập để quản lý kho.');
            redirect(BASE_URL . '/index.php?r=dangnhap');
        }
        if (function_exists('current_role') && current_role() !== 'admin') {
            http_response_code(403);
            exit('Bạn không có quyền truy cập chức năng này.');
        }
        return function_exists('current_user') ? (current_user() ?? []) : ($_SESSION['user'] ?? []);
    }

    private function render(string $view, array $data = []): void
    {
        $data['notificationCenter'] = $data['notificationCenter'] ?? (new QuanTri($this->db))->getNotificationCenterData();
        extract($data);
        $viewDir = defined('VIEW_DIR') ? VIEW_DIR : __DIR__ . '/../views';
        require $viewDir . '/admin/layouts/header.php';
        require $viewDir . '/admin/' . $view . '.php';
        require $viewDir . '/admin/layouts/footer.php';
    }

    public function index(): void
    {
        $this->requireAdmin();
        // live-filter.js chỉ lấy vùng #batchResults: bỏ các truy vấn quét toàn collection mà vùng đó không dùng.
        $isLiveFilterRequest = ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'live-filter';
        if (!$isLiveFilterRequest) {
            $this->batches->ensureIndexes();
        }
        $filters = [
            'q' => trim((string)($_GET['q'] ?? '')),
            'product_id' => trim((string)($_GET['product_id'] ?? '')),
            'warehouse_id' => trim((string)($_GET['warehouse_id'] ?? '')),
            'status' => trim((string)($_GET['status'] ?? '')),
            'expires_from' => trim((string)($_GET['expires_from'] ?? '')),
            'expires_to' => trim((string)($_GET['expires_to'] ?? '')),
            'show_expired' => trim((string)($_GET['show_expired'] ?? '')),
            'limit' => 200,
        ];
        $this->render('inventory_batches', [
            'filters' => $filters,
            'batches' => $this->batches->listBatches($filters),
            'productOptions' => $isLiveFilterRequest ? [] : $this->batches->listProductOptions(),
            'autoSeedPreview' => $isLiveFilterRequest ? [] : $this->batches->previewAutoSeed([
                'warehouse_id' => 'AUTO-DEFAULT',
                'shelf_life_months' => 24,
                'remaining_months' => 18,
                'limit' => 500,
            ]),
            'diverseSeedPreview' => $isLiveFilterRequest ? [] : $this->batches->previewDiverseSeed([
                'warehouse_id' => 'AUTO-SCENARIOS',
                'limit' => 1000,
            ]),
            'batchError' => null,
        ]);
    }

    public function autoSeed(): void
    {
        $actor = $this->requireAdmin();
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            redirect(BASE_URL . '/index.php?r=admin_inventory_batches');
        }

        $warehouseId = trim((string)($_POST['warehouse_id'] ?? 'AUTO-DEFAULT')) ?: 'AUTO-DEFAULT';
        $shelfLifeMonths = min(60, max(1, (int)($_POST['shelf_life_months'] ?? 24)));
        $remainingMonths = min($shelfLifeMonths - 1, max(1, (int)($_POST['remaining_months'] ?? 18)));
        $result = $this->batches->autoSeedMissingBatches([
            'warehouse_id' => $warehouseId,
            'shelf_life_months' => $shelfLifeMonths,
            'remaining_months' => $remainingMonths,
            'limit' => 500,
        ], $actor);

        $message = sprintf(
            'Đã tạo %d lô dữ liệu mẫu; bỏ qua %d sản phẩm cần xác minh hoặc đã có lô.',
            (int)$result['created'],
            (int)$result['skipped']
        );
        if (!empty($result['errors'])) {
            $message .= ' Có ' . count($result['errors']) . ' lỗi cần kiểm tra.';
        }
        set_flash(empty($result['errors']) ? 'success' : 'warning', $message);
        redirect(BASE_URL . '/index.php?r=admin_inventory_batches');
    }

    public function seedDiverse(): void
    {
        $actor = $this->requireAdmin();
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            redirect(BASE_URL . '/index.php?r=admin_inventory_batches');
        }

        $warehouseId = trim((string)($_POST['warehouse_id'] ?? 'AUTO-SCENARIOS')) ?: 'AUTO-SCENARIOS';
        $limit = min(3000, max(10, (int)($_POST['limit'] ?? 1000)));
        $result = $this->batches->seedDiverseDemoBatches([
            'warehouse_id' => $warehouseId,
            'limit' => $limit,
        ], $actor);

        $message = sprintf(
            'Đã tạo %d lô demo đa dạng; lô hết hạn được tách vào AUTO-EXPIRED, các lô còn lại ở %s; bỏ qua %d lô đã tồn tại.',
            (int)$result['created'],
            $warehouseId,
            (int)$result['skipped']
        );
        if (!empty($result['errors'])) {
            $message .= ' Có ' . count($result['errors']) . ' lỗi cần kiểm tra.';
        }
        set_flash(empty($result['errors']) ? 'success' : 'warning', $message);
        redirect(BASE_URL . '/index.php?r=admin_inventory_batches');
    }

    public function seedFixedExpiry(): void
    {
        $actor = $this->requireAdmin();
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            redirect(BASE_URL . '/index.php?r=admin_inventory_batches');
        }

        $warehouseId = trim((string)($_POST['warehouse_id'] ?? 'AUTO-FIXED-EXPIRY')) ?: 'AUTO-FIXED-EXPIRY';
        $result = $this->batches->seedFixedExpiryDemoBatches([
            'warehouse_id' => $warehouseId,
        ], $actor);
        $message = sprintf(
            'Đã tạo %d/10 lô theo ngày HSD yêu cầu trong %s; bỏ qua %d lô đã tồn tại.',
            (int)$result['created'],
            $warehouseId,
            (int)$result['skipped']
        );
        if (!empty($result['errors'])) {
            $message .= ' Có ' . count($result['errors']) . ' lỗi cần kiểm tra.';
        }
        set_flash(empty($result['errors']) ? 'success' : 'warning', $message);
        redirect(BASE_URL . '/index.php?r=admin_inventory_batches&warehouse_id=' . rawurlencode($warehouseId));
    }

    public function saveBatch(): void
    {
        $actor = $this->requireAdmin();
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            redirect(BASE_URL . '/index.php?r=admin_inventory_batches');
        }

        $created = $this->batches->createBatch($_POST, $actor);
        if ($created === null) {
            set_flash('error', $this->batches->getLastErrorMessage() ?? 'Không thể lưu lô hàng.');
        } else {
            set_flash('success', 'Đã thêm lô hàng và ghi nhận movement nhập kho.');
        }
        redirect(BASE_URL . '/index.php?r=admin_inventory_batches');
    }

    public function addMovement(): void
    {
        $actor = $this->requireAdmin();
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            redirect(BASE_URL . '/index.php?r=admin_inventory_batches');
        }

        $batchId = trim((string)($_POST['batch_id'] ?? ''));
        $type = trim((string)($_POST['type'] ?? ''));
        $quantity = max(0, (int)($_POST['quantity'] ?? 0));
        $note = trim((string)($_POST['note'] ?? ''));
        $updated = $this->batches->addMovement($batchId, $type, $quantity, $note, $actor);
        if ($updated === null) {
            set_flash('error', $this->batches->getLastErrorMessage() ?? 'Không thể điều chỉnh lô hàng.');
        } else {
            set_flash('success', 'Đã ghi nhận điều chỉnh tồn kho.');
        }
        redirect(BASE_URL . '/index.php?r=admin_inventory_batches');
    }
}
