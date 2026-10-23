<?php
// Nhãn công dụng / vấn đề da / loại da do người chịu trách nhiệm (U07):
//   admin_sp_tags         POST  Lưu nhãn nhập tay cho 1 sản phẩm (tag_source = manual)
//   admin_tag_review      GET   Danh sách gợi ý LLM (chờ duyệt / đã duyệt / đã từ chối)
//   admin_tag_review_act  POST  Duyệt / từ chối / sửa rồi duyệt (từng cái và hàng loạt)
// Chỉ admin. Mọi POST bắt buộc CSRF (token theo phiên). Ghi Mongo chỉ qua TagSuggestion (6 trường nhãn
// + tag_suggestions); mỗi lần ghi nhãn đẩy sự kiện catalog:changes:v1 cho ETL (U11).

require_once __DIR__ . '/../models/TagSuggestion.php';
require_once __DIR__ . '/../models/QuanTri.php';
require_once __DIR__ . '/../services/TagCuration.php';

class AdminTagController
{
    private const BULK_LIMIT = 100;

    private $pdo;
    private TagSuggestion $model;

    public function __construct($pdo)
    {
        global $db;
        $this->pdo = $db ?? (is_object($pdo) && method_exists($pdo, 'raw') ? $pdo->raw() : $pdo);
        $this->model = new TagSuggestion($this->pdo);
    }

    // ---- quyền + khung trang ------------------------------------------------------------------------------

    private function requireAdmin(): array
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
        return current_user() ?? [];
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

    private function csrfOk(): bool
    {
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
            return false;
        }
        $token = $_POST['csrf'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
        return TagCuration::csrfValid($_SESSION ?? [], $token);
    }

    private function actor(array $user): string
    {
        return TagCuration::actorHash($user);
    }

    // ---- Phần A: nhập tay ---------------------------------------------------------------------------------

    public function saveProductTags(): void
    {
        $user = $this->requireAdmin();
        $id = trim((string)($_POST['id'] ?? ''));
        $back = BASE_URL . '/index.php?r=admin_sp_sua&id=' . urlencode($id);
        if (!$this->csrfOk()) {
            http_response_code(403);
            set_flash('error', 'Phiên làm việc không hợp lệ (CSRF). Vui lòng tải lại trang và thử lại.');
            redirect($id === '' ? BASE_URL . '/index.php?r=admin_sp' : $back);
        }
        if ($id === '' || !preg_match('/^[A-Za-z0-9_\-]{1,40}$/', $id)) {
            set_flash('error', 'Thiếu hoặc sai mã sản phẩm.');
            redirect(BASE_URL . '/index.php?r=admin_sp');
        }

        $parsed = TagCuration::selectionFromInput($_POST);
        if ($parsed['rejected'] !== []) {
            set_flash('error', 'Có nhãn không thuộc danh sách chuẩn, không lưu gì cả. Vui lòng chọn lại từ danh sách.');
            redirect($back);
        }
        $result = $this->model->saveManual($id, $parsed['selection'], $this->actor($user));
        if ($result['ok']) {
            TagCuration::publishChange($id, TagCuration::SOURCE_MANUAL);
        }
        set_flash($result['ok'] ? 'success' : 'error', $result['message']);
        redirect($back);
    }

    // ---- Phần B: trang duyệt gợi ý LLM --------------------------------------------------------------------

    public function reviewIndex(): void
    {
        $this->requireAdmin();
        $status = (string)($_GET['status'] ?? 'pending');
        if (!in_array($status, TagSuggestion::STATUSES, true)) {
            $status = 'pending';
        }
        $onlyDiff = (string)($_GET['diff'] ?? '') === '1';
        $page = max(1, (int)($_GET['page'] ?? 1));
        $perPage = 25;
        $counts = $this->model->countByStatus();
        $items = $this->model->listByStatus($status, $perPage, ($page - 1) * $perPage, $onlyDiff);

        $this->render('tag_review', [
            'pageTitle' => 'Duyệt nhãn công dụng',
            'items' => $items,
            'counts' => $counts,
            'status' => $status,
            'onlyDiff' => $onlyDiff,
            'page' => $page,
            'perPage' => $perPage,
            'csrf' => TagCuration::csrfToken($_SESSION),
            'groups' => TagCatalog::groups(),
            'labels' => TagCatalog::LABELS,
            'fields' => TagCatalog::FIELDS,
        ]);
    }

    public function reviewAction(): void
    {
        $user = $this->requireAdmin();
        $back = BASE_URL . '/index.php?' . http_build_query([
            'r' => 'admin_tag_review',
            'status' => in_array((string)($_POST['status'] ?? ''), TagSuggestion::STATUSES, true) ? $_POST['status'] : 'pending',
        ]);
        if (!$this->csrfOk()) {
            http_response_code(403);
            set_flash('error', 'Phiên làm việc không hợp lệ (CSRF). Vui lòng tải lại trang và thử lại.');
            redirect($back);
        }

        $act = (string)($_POST['act'] ?? '');
        [$verb, $singleId] = array_pad(explode(':', $act, 2), 2, '');
        $isId = static fn($v): bool => is_string($v) && preg_match('/^[a-f0-9]{24}$/i', $v) === 1;
        $actor = $this->actor($user);

        $ids = [];
        if (in_array($verb, ['approve', 'reject', 'approve_edit'], true)) {
            $ids = $isId($singleId) ? [$singleId] : [];
        } elseif (in_array($verb, ['approve_bulk', 'reject_bulk'], true)) {
            $posted = is_array($_POST['ids'] ?? null) ? $_POST['ids'] : [];
            $ids = array_slice(array_values(array_unique(array_filter($posted, $isId))), 0, self::BULK_LIMIT);
        } else {
            set_flash('error', 'Thao tác không hợp lệ.');
            redirect($back);
        }
        if ($ids === []) {
            set_flash('error', 'Chưa chọn gợi ý nào.');
            redirect($back);
        }

        $done = 0;
        $skipped = [];
        foreach ($ids as $id) {
            if ($verb === 'reject' || $verb === 'reject_bulk') {
                $res = $this->model->reject($id, $actor);
                $res['ok'] ? $done++ : $skipped[] = $res['message'];
                continue;
            }
            $override = null;
            if ($verb === 'approve_edit') {
                $parsed = TagCuration::selectionFromInput(is_array($_POST['edit'][$id] ?? null) ? $_POST['edit'][$id] : []);
                if ($parsed['rejected'] !== []) {
                    $skipped[] = 'Nhãn không thuộc danh sách chuẩn';
                    continue;
                }
                $override = $parsed['selection'];
            }
            $res = $this->model->approve($id, $override, $actor);
            if ($res['ok']) {
                $done++;
                TagCuration::publishChange($res['product_id'], TagCuration::SOURCE_LLM);
            } else {
                $skipped[] = $res['message'];
            }
        }

        $message = ($verb === 'reject' || $verb === 'reject_bulk' ? 'Đã từ chối ' : 'Đã duyệt ') . $done . ' gợi ý.';
        if ($skipped !== []) {
            $message .= ' Bỏ qua ' . count($skipped) . ' (' . implode('; ', array_slice(array_unique($skipped), 0, 3)) . ').';
        }
        set_flash($done > 0 ? 'success' : 'error', $message);
        redirect($back);
    }
}
