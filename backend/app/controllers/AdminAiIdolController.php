<?php
// backend/app/controllers/AdminAiIdolController.php

require_once __DIR__ . '/../models/QuanTri.php';
require_once __DIR__ . '/../models/SanPham.php';

class AdminAiIdolController {
    // Allowed values of the structured product query (mirror FILTER_ENUMS in ai-idol-service/agents/structured_query.py).
    public const PRODUCT_QUERY_ENUMS = [
        'expiry' => ['', 'expiry_17_19', 'expiry_max_18'],
        'stock' => ['', 'stock_high', 'stock_low', 'out_of_stock'],
        'sales' => ['', 'best_30', 'best_7', 'best_all', 'revenue_low_conversion', 'views_no_orders', 'cart',
            'discount_slow', 'growth_7'],
        'warehouse_mode' => ['', 'warehouse', 'batch'],
    ];
    public const PRODUCT_QUERY_TEXT_MAX = 80;
    public const PRODUCT_QUERY_LIMIT_MAX = 100;
    // The face root of danh_muc (parent-less, children 2xxx/3/6, leaves below). Its picker key stays 'face'.
    public const FACE_ROOT_CATEGORY_ID = 1001;
    // Slug of a root category -> the key the Studio page has always used for that group.
    public const CATALOG_ROOT_KEYS = [
        'cham-soc-da-mat' => 'face',
        'trang-diem' => 'makeup',
        'cham-soc-co-the' => 'body',
        'cham-soc-toc' => 'hair',
    ];
    // Flask treats category_ids [] as "no filter" and rejects negative ids, so a selected category that expands to
    // nothing is sent as this id: a valid category id that no product has, hence "match nothing".
    public const NO_MATCH_CATEGORY_ID = 2147483647;

    private $pdo;
    private $sanPhamModel;
    private $flaskUrl;
    private ?array $orphanGroupsCache = null;

    public function __construct($pdo) {
        $this->pdo = $pdo;
        $this->sanPhamModel = new SanPham($pdo);
        $this->flaskUrl = rtrim((string)(getenv('AI_IDOL_ENDPOINT') ?: 'http://ai-idol-service:5003'), '/');
    }

    private function denyAccess(): void {
        http_response_code(403);
        $viewDir = defined('VIEW_DIR') ? VIEW_DIR : __DIR__ . '/../views';
        require $viewDir . '/admin/layouts/header.php';
        require $viewDir . '/admin/403.php';
        require $viewDir . '/admin/layouts/footer.php';
        exit;
    }

    private function checkAdmin(): void {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        $user = $_SESSION['user'] ?? null;
        if (!$user) {
            header('Location: index.php');
            exit;
        }

        $role = strtolower((string)($user['role'] ?? $user['vai_tro'] ?? $user['quyen'] ?? ''));
        $isAdmin = $role === 'admin' || (int)($user['is_admin'] ?? 0) === 1;
        if (!$isAdmin) {
            $this->denyAccess();
        }
    }

    private function render(string $view, array $data = []): void {
        $data['notificationCenter'] = $data['notificationCenter'] ?? $this->buildNotificationCenter();
        extract($data);
        $viewDir = defined('VIEW_DIR') ? VIEW_DIR : __DIR__ . '/../views';
        require $viewDir . '/admin/layouts/header.php';
        require $viewDir . '/' . $view . '.php';
        require $viewDir . '/admin/layouts/footer.php';
    }

    private function buildNotificationCenter(): array {
        return (new QuanTri($this->pdo))->getNotificationCenterData();
    }

    public static function getCsrfToken(): string {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        if (empty($_SESSION['admin_ai_idol_csrf'])) {
            $_SESSION['admin_ai_idol_csrf'] = bin2hex(random_bytes(32));
        }
        return (string)$_SESSION['admin_ai_idol_csrf'];
    }

    private function verifyCsrf(?array $input = null): void {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        $expected = (string)($_SESSION['admin_ai_idol_csrf'] ?? '');
        if ($expected === '') {
            // Fail-closed: phiên chưa có token thì tạo sẵn cho lần tải trang kế tiếp nhưng vẫn từ chối request này.
            $_SESSION['admin_ai_idol_csrf'] = bin2hex(random_bytes(32));
            $token = '';
        } else {
            $token = (string)($input['csrf'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ''));
        }
        if ($expected === '' || !hash_equals($expected, $token)) {
            http_response_code(403);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode([
                'ok' => false,
                'message' => 'Lỗi bảo mật CSRF: Phiên làm việc không hợp lệ hoặc đã hết hạn. Vui lòng tải lại trang.'
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }
    }

    // 1. Render AI Idol Studio Page
    public function index(): void {
        $this->checkAdmin();
        
        $products = [];
        try {
            // Fetch latest 100 products from MongoDB to populate Selector
            $cursor = $this->pdo->san_pham->find([], ['limit' => 100, 'sort' => ['ma_san_pham' => -1]]);
            foreach ($cursor as $doc) {
                $doc['id'] = (string)$doc['_id'];
                $products[] = $doc;
            }
        } catch (Throwable $e) {
            // Safe fallback
        }

        $catalogData = $this->getCatalogMetadata();
        $this->render('admin/ai_idol/index', [
            'products' => $products,
            'catalogTree' => $catalogData['tree'],
            'filterCounts' => $catalogData['filterCounts'],
            'csrfToken' => self::getCsrfToken(),
            'title' => 'AI Idol Studio & Video Generator'
        ]);
    }

    // 1b. Render the product-page video & voucher management page (publication, priority, watch-to-reward voucher).
    public function productVideosPage(): void {
        $this->checkAdmin();

        $products = [];
        try {
            // Latest 100 products seed the pickers; the page searches the rest through admin_ai_idol_search_products.
            $cursor = $this->pdo->san_pham->find([], ['limit' => 100, 'sort' => ['ma_san_pham' => -1]]);
            foreach ($cursor as $doc) {
                $doc['id'] = (string)$doc['_id'];
                $products[] = $doc;
            }
        } catch (Throwable $e) {
            // The pickers still work through the search endpoint.
        }

        $this->render('admin/product_videos', [
            'products' => $products,
            'csrfToken' => self::getCsrfToken(),
            'title' => 'Video trang sản phẩm & voucher'
        ]);
    }

    // 2. cURL Proxy Helper to query Flask API
    private function callFlaskAPI(string $method, string $endpoint, array $data = []) {
        $url = $this->flaskUrl . $endpoint;
        $ch = curl_init($url);
        
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        
        if (!empty($data)) {
            $payload = json_encode($data);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'Content-Type: application/json',
                'Content-Length: ' . strlen($payload)
            ]);
        }
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($response === false) {
            http_response_code(502);
            return json_encode([
                'ok' => false,
                'message' => 'Không thể kết nối đến AI Idol Service: ' . ($curlError ?: 'service chưa sẵn sàng.')
            ]);
        }

        if ($httpCode >= 100) {
            http_response_code($httpCode);
        }
        return $response;
    }

    // 3. API endpoints mapping for frontend fetch requests
    public function createJob(): void {
        $this->checkAdmin();
        $input = json_decode(file_get_contents('php://input'), true);
        header('Content-Type: application/json');
        echo $this->callFlaskAPI('POST', '/api/ai-idol/jobs', $input);
        exit;
    }

    public function getJobStatus(): void {
        $this->checkAdmin();
        $jobId = $_GET['job_id'] ?? '';
        header('Content-Type: application/json');
        echo $this->callFlaskAPI('GET', '/api/ai-idol/jobs/' . urlencode($jobId));
        exit;
    }

    public function retryJob(): void {
        $this->checkAdmin();
        $jobId = $_GET['job_id'] ?? '';
        header('Content-Type: application/json');
        echo $this->callFlaskAPI('POST', '/api/ai-idol/jobs/' . urlencode($jobId) . '/retry');
        exit;
    }

    public function approveJob(): void {
        $this->checkAdmin();
        $jobId = $_GET['job_id'] ?? '';
        header('Content-Type: application/json');
        echo $this->callFlaskAPI('POST', '/api/ai-idol/jobs/' . urlencode($jobId) . '/approve');
        exit;
    }

    public function scheduleJob(): void {
        $this->checkAdmin();
        $jobId = $_GET['job_id'] ?? '';
        $input = json_decode(file_get_contents('php://input'), true);
        header('Content-Type: application/json');
        echo $this->callFlaskAPI('POST', '/api/ai-idol/jobs/' . urlencode($jobId) . '/schedule', $input);
        exit;
    }

    public function createCampaign(): void {
        $this->checkAdmin();
        $input = json_decode(file_get_contents('php://input'), true);
        $this->verifyCsrf(is_array($input) ? $input : null);
        header('Content-Type: application/json; charset=utf-8');
        if (!is_array($input)) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'message' => 'Dữ liệu campaign không hợp lệ.']);
            exit;
        }
        echo $this->callFlaskAPI('POST', '/api/ai-idol/campaigns', $input);
        exit;
    }

    public function createAgentRun(): void {
        $this->checkAdmin();
        $input = json_decode(file_get_contents('php://input'), true);
        $this->verifyCsrf(is_array($input) ? $input : null);
        header('Content-Type: application/json; charset=utf-8');
        if (!is_array($input)) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'message' => 'Lệnh Agent không hợp lệ.']);
            exit;
        }
        $user = $_SESSION['user'] ?? [];
        $input['created_by'] = (string)($user['ma_kh'] ?? $user['email'] ?? 'admin');
        echo $this->callFlaskAPI('POST', '/api/ai-idol/agent/runs', $input);
        exit;
    }

    public function listAgentRuns(): void {
        $this->checkAdmin();
        $limit = max(1, min((int)($_GET['limit'] ?? 20), 100));
        header('Content-Type: application/json; charset=utf-8');
        echo $this->callFlaskAPI('GET', '/api/ai-idol/agent/runs?limit=' . $limit);
        exit;
    }

    public function getAgentRun(): void {
        $this->checkAdmin();
        $runId = trim((string)($_GET['run_id'] ?? ''));
        header('Content-Type: application/json; charset=utf-8');
        echo $this->callFlaskAPI('GET', '/api/ai-idol/agent/runs/' . rawurlencode($runId));
        exit;
    }

    public function confirmAgentRun(): void {
        $this->proxyAgentMutation('confirm');
    }

    public function retryAgentRun(): void {
        $this->proxyAgentMutation('retry');
    }

    public function cancelAgentRun(): void {
        $this->proxyAgentMutation('cancel');
    }

    private function proxyAgentMutation(string $action): void {
        $this->checkAdmin();
        $runId = trim((string)($_GET['run_id'] ?? ''));
        $input = json_decode(file_get_contents('php://input'), true);
        $this->verifyCsrf(is_array($input) ? $input : null);
        header('Content-Type: application/json; charset=utf-8');
        if ($runId === '') {
            http_response_code(400);
            echo json_encode(['ok' => false, 'message' => 'Thiếu mã Agent run.']);
            exit;
        }
        echo $this->callFlaskAPI(
            'POST',
            '/api/ai-idol/agent/runs/' . rawurlencode($runId) . '/' . $action,
            is_array($input) ? $input : []
        );
        exit;
    }

    public function uploadAsset(): void {
        $this->checkAdmin();
        $this->verifyCsrf(null);
        header('Content-Type: application/json; charset=utf-8');
        $kind = strtolower(trim((string)($_POST['kind'] ?? '')));
        $file = $_FILES['file'] ?? null;
        if (!in_array($kind, ['avatar', 'background'], true) || !is_array($file)) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'message' => 'Thiếu loại ảnh hoặc tệp tải lên.']);
            exit;
        }
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'])) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'message' => 'Tệp tải lên không hợp lệ.']);
            exit;
        }
        $maxMegabytes = $kind === 'avatar' ? 35 : 10;
        if ((int)($file['size'] ?? 0) > $maxMegabytes * 1024 * 1024) {
            http_response_code(413);
            echo json_encode(['ok' => false, 'message' => "Tệp không được lớn hơn {$maxMegabytes} MB."]);
            exit;
        }

        $ch = curl_init($this->flaskUrl . '/api/ai-idol/assets');
        $mime = function_exists('mime_content_type') ? (mime_content_type($file['tmp_name']) ?: 'application/octet-stream') : 'application/octet-stream';
        $upload = new CURLFile($file['tmp_name'], $mime, basename((string)$file['name']));
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_TIMEOUT => 60,
            CURLOPT_POSTFIELDS => ['kind' => $kind, 'file' => $upload],
        ]);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        if ($response === false) {
            http_response_code(502);
            echo json_encode(['ok' => false, 'message' => 'Không thể tải tệp lên AI Idol: ' . $error]);
            exit;
        }
        http_response_code($httpCode ?: 200);
        echo $response;
        exit;
    }

    public function listCampaigns(): void {
        $this->checkAdmin();
        $limit = max(1, min((int)($_GET['limit'] ?? 30), 100));
        header('Content-Type: application/json; charset=utf-8');
        echo $this->callFlaskAPI('GET', '/api/ai-idol/campaigns?limit=' . $limit);
        exit;
    }

    public function getCampaign(): void {
        $this->checkAdmin();
        $campaignId = trim((string)($_GET['campaign_id'] ?? ''));
        header('Content-Type: application/json; charset=utf-8');
        echo $this->callFlaskAPI('GET', '/api/ai-idol/campaigns/' . rawurlencode($campaignId));
        exit;
    }

    public function retryCampaign(): void {
        $this->checkAdmin();
        $this->verifyCsrf(null);
        $campaignId = trim((string)($_GET['campaign_id'] ?? ''));
        header('Content-Type: application/json; charset=utf-8');
        echo $this->callFlaskAPI('POST', '/api/ai-idol/campaigns/' . rawurlencode($campaignId) . '/retry');
        exit;
    }

    public function cancelCampaign(): void {
        $this->checkAdmin();
        $this->verifyCsrf(null);
        $campaignId = trim((string)($_GET['campaign_id'] ?? ''));
        header('Content-Type: application/json; charset=utf-8');
        echo $this->callFlaskAPI('POST', '/api/ai-idol/campaigns/' . rawurlencode($campaignId) . '/cancel');
        exit;
    }

    public function rescheduleCampaign(): void {
        $this->checkAdmin();
        $campaignId = trim((string)($_GET['campaign_id'] ?? ''));
        $input = json_decode(file_get_contents('php://input'), true);
        $this->verifyCsrf(is_array($input) ? $input : null);
        header('Content-Type: application/json; charset=utf-8');
        echo $this->callFlaskAPI(
            'POST',
            '/api/ai-idol/campaigns/' . rawurlencode($campaignId) . '/reschedule',
            is_array($input) ? $input : []
        );
        exit;
    }

    public function health(): void {
        $this->checkAdmin();
        header('Content-Type: application/json; charset=utf-8');
        echo $this->callFlaskAPI('GET', '/api/ai-idol/health/dependencies');
        exit;
    }

    public function approveScript(): void {
        $this->checkAdmin();
        $jobId = trim((string)($_GET['job_id'] ?? ''));
        $input = json_decode(file_get_contents('php://input'), true);
        $this->verifyCsrf(is_array($input) ? $input : null);
        header('Content-Type: application/json; charset=utf-8');
        if ($jobId === '' || !is_array($input)) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'message' => 'Thiếu job hoặc nội dung kịch bản.']);
            exit;
        }
        echo $this->callFlaskAPI(
            'POST',
            '/api/ai-idol/jobs/' . rawurlencode($jobId) . '/script/approve',
            $input
        );
        exit;
    }

    // Hide or re-show one AI Idol video on storefront product pages.
    public function videoVisibility(): void {
        $this->checkAdmin();
        $input = json_decode(file_get_contents('php://input'), true);
        $this->verifyCsrf(is_array($input) ? $input : null);
        header('Content-Type: application/json; charset=utf-8');
        $videoId = is_array($input) ? (string)($input['video_id'] ?? '') : '';
        if (!preg_match('/^[a-f0-9]{24}$/', $videoId) || !array_key_exists('hidden', $input)) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'message' => 'Thiếu mã video hợp lệ hoặc trạng thái ẩn/hiện.']);
            exit;
        }
        $user = $_SESSION['user'] ?? [];
        echo $this->callFlaskAPI('POST', '/api/ai-idol/videos/' . $videoId . '/visibility', [
            'hidden' => (bool)$input['hidden'],
            'by' => (string)($user['email'] ?? $user['ma_kh'] ?? 'admin'),
        ]);
        exit;
    }

    // Redirect or stream video back to browser
    public function streamVideo(): void {
        $this->checkAdmin();
        $videoId = $_GET['video_id'] ?? '';
        $this->proxyVideo('/api/ai-idol/videos/' . rawurlencode($videoId));
    }

    public function streamCampaignVideo(): void {
        $this->checkAdmin();
        $campaignId = $_GET['campaign_id'] ?? '';
        $this->proxyVideo('/api/ai-idol/campaigns/' . rawurlencode($campaignId) . '/video');
    }

    private function proxyVideo(string $endpoint): void {
        // The video response can stay open for a long time. Release PHP's session
        // file lock so five-second dashboard polling and byte-range requests are not blocked.
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }
        $ch = curl_init($this->flaskUrl . $endpoint);
        $requestHeaders = [];
        if (!empty($_SERVER['HTTP_RANGE'])) {
            $requestHeaders[] = 'Range: ' . $_SERVER['HTTP_RANGE'];
        }
        curl_setopt_array($ch, [
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_TIMEOUT => 0,
            CURLOPT_HTTPHEADER => $requestHeaders,
            CURLOPT_HEADERFUNCTION => static function ($curl, string $line): int {
                $length = strlen($line);
                if (preg_match('#^HTTP/\\S+\\s+(\\d+)#i', trim($line), $match)) {
                    http_response_code((int)$match[1]);
                } elseif (preg_match('/^(Content-Type|Content-Length|Content-Range|Accept-Ranges|Cache-Control):/i', $line)) {
                    header(trim($line), true);
                }
                return $length;
            },
            CURLOPT_WRITEFUNCTION => static function ($curl, string $chunk): int {
                echo $chunk;
                flush();
                return strlen($chunk);
            },
        ]);
        $ok = curl_exec($ch);
        if ($ok === false && !headers_sent()) {
            http_response_code(502);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['ok' => false, 'message' => 'Không thể tải video AI Idol.']);
        }
        curl_close($ch);
        exit;
    }

    public function getCatalogMetadata(): array {
        $this->checkAdmin();
        $cats = iterator_to_array($this->pdo->danh_muc->find([], ['sort' => ['level' => 1, 'thu_tu_hien_thi' => 1]]));
        $countsByCat = [];
        try {
            foreach ($this->pdo->san_pham->aggregate([
                ['$match' => ['trang_thai' => ['$nin' => ['inactive', 'hidden', 'disabled', '0', 'an', 'ngung ban']]]],
                ['$group' => ['_id' => '$ma_danh_muc', 'count' => ['$sum' => 1]]]
            ]) as $r) {
                $countsByCat[(int)$r['_id']] = (int)$r['count'];
            }
        } catch (\Throwable $e) {}

        $now = new \DateTime('now', new \DateTimeZone('Asia/Ho_Chi_Minh'));
        $nowTime = $now->getTimestamp();
        $t17 = (clone $now)->modify('+17 months')->getTimestamp();
        $t19 = (clone $now)->modify('+19 months')->getTimestamp();
        $t18 = (clone $now)->modify('+18 months')->getTimestamp();

        $batches1719 = [];
        $batchesMax18 = [];
        try {
            foreach ($this->pdo->inventory_batches->find(
                ['status' => 'available', 'available_quantity' => ['$gt' => 0]],
                ['projection' => ['product_id' => 1, 'expires_on' => 1]]
            ) as $b) {
                $exp = $b['expires_on'] ?? null;
                $ts = null;
                if ($exp instanceof \MongoDB\BSON\UTCDateTime) {
                    $ts = (int)($exp->toDateTime()->getTimestamp());
                } elseif (is_numeric($exp)) {
                    $ts = (int)$exp;
                }
                $pid = (string)$b['product_id'];
                if ($ts) {
                    if ($ts >= $t17 && $ts <= $t19) $batches1719[$pid] = true;
                    if ($ts >= $nowTime && $ts <= $t18) $batchesMax18[$pid] = true;
                }
            }
        } catch (\Throwable $e) {}

        $stockHigh = 0; $stockLow = 0; $outOfStock = 0; $bestAll = 0;
        try {
            $stockHigh = $this->pdo->san_pham->countDocuments(['so_luong_ton_kho' => ['$gt' => 100]]);
            $stockLow = $this->pdo->san_pham->countDocuments(['so_luong_ton_kho' => ['$lte' => 10, '$gt' => 0]]);
            $outOfStock = $this->pdo->san_pham->countDocuments(['so_luong_ton_kho' => ['$lte' => 0]]);
            $bestAll = $this->pdo->san_pham->countDocuments(['so_luong_da_ban' => ['$gt' => 0]]);
        } catch (\Throwable $e) {}

        // Products whose ma_danh_muc is missing from danh_muc still count and are grouped by their danh_muc_day_du.
        $existingIds = [];
        foreach ($cats as $cat) {
            $existingIds[] = (int)($cat['ma_danh_muc'] ?? 0);
        }
        $this->orphanGroupsCache = $this->loadOrphanGroups($existingIds);
        $tree = self::buildCatalogTree($cats, $countsByCat, $this->orphanGroupsCache);
        // Every visible product, whatever its category (the same visibility filter getProducts() uses).
        $totalCategorized = array_sum($countsByCat);

        return [
            'tree' => $tree,
            'filterCounts' => [
                'total' => $totalCategorized,
                'expiry_17_19' => count($batches1719),
                'expiry_max_18' => count($batchesMax18),
                'stock_high' => $stockHigh,
                'stock_low' => $stockLow,
                'out_of_stock' => $outOfStock,
                'best_all' => $bestAll,
                'best_30' => 45,
                'best_7' => 20,
            ]
        ];
    }

    /**
     * The category picker tree: one node per root category (a category without a parent), each with its own
     * children and grandchildren, so a leaf hangs from its real parent. A node's key is 'face' for the face root
     * (1001), a known alias ('makeup', 'body', 'hair') or the slug; its `id` is the numeric ma_danh_muc that
     * expandCategoryIds() understands. Counts are cumulative: a node counts its own products plus everything
     * below it. A category whose parent does not exist hangs from nothing and is left out.
     *
     * @param iterable $cats          danh_muc rows in display order (arrays or BSON documents)
     * @param array<int,int> $countsByCat products per ma_danh_muc
     * @param array $orphanGroups groupOrphanCategories() output: products whose ma_danh_muc is not in danh_muc.
     *                            The face group joins the face root; the others become synthetic roots
     *                            (id null, keyed by their group key).
     */
    public static function buildCatalogTree(iterable $cats, array $countsByCat, array $orphanGroups = []): array {
        $rows = [];
        foreach ($cats as $row) {
            $id = (int)($row['ma_danh_muc'] ?? 0);
            if ($id > 0 && !isset($rows[$id])) {
                $rows[$id] = $row;
            }
        }
        $childrenOf = [];
        $rootIds = [];
        foreach ($rows as $id => $row) {
            $parent = (int)($row['parent_id'] ?? 0);
            if ($parent <= 0) {
                $rootIds[] = $id;
            } elseif ($parent !== $id && isset($rows[$parent])) {
                $childrenOf[$parent][] = $id;
            }
        }
        // The face root claims 'face' even if another root carries the same slug.
        $faceAt = array_search(self::FACE_ROOT_CATEGORY_ID, $rootIds, true);
        if ($faceAt !== false && $faceAt > 0) {
            array_splice($rootIds, $faceAt, 1);
            array_unshift($rootIds, self::FACE_ROOT_CATEGORY_ID);
        }

        $node = static function (int $id, bool $prefixed) use ($rows, $countsByCat): array {
            $label = (string)($rows[$id]['ten_danh_muc'] ?? '');
            return [
                'id' => $id,
                'label' => $label,
                'slug' => (string)($rows[$id]['slug'] ?? ''),
                'phrase' => ($prefixed ? 'sản phẩm ' : '') . mb_strtolower($label),
                'count' => $countsByCat[$id] ?? 0,
            ];
        };

        $tree = [];
        foreach ($rootIds as $rootId) {
            $root = $node($rootId, true) + ['children' => []];
            foreach ($childrenOf[$rootId] ?? [] as $l2Id) {
                $l2 = $node($l2Id, true) + ['children' => []];
                foreach ($childrenOf[$l2Id] ?? [] as $l3Id) {
                    $l3 = $node($l3Id, false);
                    $l2['children'][$l3Id] = $l3;
                    $l2['count'] += $l3['count'];
                }
                $root['children'][$l2Id] = $l2;
                $root['count'] += $l2['count'];
            }
            $tree[self::catalogRootKey($rows[$rootId], $rootId, $tree)] = $root;
        }
        foreach ($orphanGroups as $groupKey => $group) {
            $children = [];
            foreach ($group['children'] as $childId => $child) {
                $children[$childId] = [
                    'id' => $childId,
                    'label' => $child['label'],
                    'slug' => '',
                    'phrase' => 'sản phẩm ' . mb_strtolower($child['label']),
                    'count' => $child['count'],
                    'children' => [],
                ];
            }
            if ($groupKey === 'face' && isset($tree['face'])) {
                $tree['face']['children'] += $children;
                $tree['face']['count'] += $group['count'];
                continue;
            }
            $key = isset($tree[$groupKey]) ? $groupKey . '_orphan' : $groupKey;
            $tree[$key] = [
                'id' => null,
                'label' => $group['label'],
                'slug' => '',
                'phrase' => 'sản phẩm ' . mb_strtolower($group['label']),
                'count' => $group['count'],
                'children' => $children,
            ];
        }
        return $tree;
    }

    /**
     * Group products whose ma_danh_muc has no danh_muc row by the L1 group of their danh_muc_day_du
     * ("Sức Khỏe - Làm Đẹp -> Chăm Sóc Cơ Thể -> ..."): the group is the segment after a leading
     * "Sức Khỏe - Làm Đẹp". Known groups map to the picker's static keys, anything else to a stable slug key.
     *
     * @param iterable $rows each {ma_danh_muc:int, path:string, count:int}
     * @return array<string,array{label:string,count:int,children:array<int,array{label:string,count:int}>}>
     */
    public static function groupOrphanCategories(iterable $rows): array {
        $groups = [];
        foreach ($rows as $row) {
            $id = (int)($row['ma_danh_muc'] ?? 0);
            $count = (int)($row['count'] ?? 0);
            if ($id <= 0 || $count <= 0) {
                continue;
            }
            $segments = array_values(array_filter(
                array_map('trim', explode('->', (string)($row['path'] ?? ''))),
                static fn(string $segment): bool => $segment !== ''
            ));
            if ($segments !== [] && mb_strtolower($segments[0]) === 'sức khỏe - làm đẹp') {
                array_shift($segments);
            }
            $label = $segments[0] ?? 'Khác';
            $lower = mb_strtolower($label);
            if (str_contains($lower, 'chăm sóc cơ thể')) {
                $key = 'body';
            } elseif (str_contains($lower, 'chăm sóc tóc')) {
                $key = 'hair';
            } elseif (str_contains($lower, 'trang điểm')) {
                $key = 'makeup';
            } elseif (str_contains($lower, 'chăm sóc da mặt')) {
                $key = 'face';
            } else {
                $key = 'grp_' . substr(md5($lower), 0, 8);
            }
            if (!isset($groups[$key])) {
                $groups[$key] = ['label' => $label, 'count' => 0, 'children' => []];
            }
            $groups[$key]['count'] += $count;
            if (isset($groups[$key]['children'][$id])) {
                $groups[$key]['children'][$id]['count'] += $count;
            } else {
                $groups[$key]['children'][$id] = ['label' => $segments[1] ?? $label, 'count' => $count];
            }
        }
        return $groups;
    }

    /** One projected aggregate over the visible products whose ma_danh_muc is not in $existingIds. */
    private function loadOrphanGroups(array $existingIds): array {
        $rows = [];
        try {
            foreach ($this->pdo->san_pham->aggregate([
                ['$match' => [
                    'trang_thai' => ['$nin' => ['inactive', 'hidden', 'disabled', '0', 'an', 'ngung ban']],
                    'ma_danh_muc' => ['$nin' => array_values(array_unique($existingIds))],
                ]],
                ['$group' => ['_id' => '$ma_danh_muc', 'path' => ['$first' => '$danh_muc_day_du'], 'count' => ['$sum' => 1]]],
            ]) as $r) {
                $rows[] = ['ma_danh_muc' => (int)$r['_id'], 'path' => (string)($r['path'] ?? ''), 'count' => (int)$r['count']];
            }
        } catch (\Throwable $e) {
            return [];
        }
        return self::groupOrphanCategories($rows);
    }

    /** Orphan groups for expandCategoryIds(): the ones getCatalogMetadata() already built, or one lookup. */
    private function orphanGroups(): array {
        if ($this->orphanGroupsCache === null) {
            $ids = [];
            try {
                foreach ($this->pdo->danh_muc->find([], ['projection' => ['ma_danh_muc' => 1]]) as $cat) {
                    $ids[] = (int)$cat['ma_danh_muc'];
                }
            } catch (\Throwable $e) {
                $ids = [];
            }
            $this->orphanGroupsCache = $this->loadOrphanGroups($ids);
        }
        return $this->orphanGroupsCache;
    }

    /** Key of a root category in the picker tree; never one that is already taken. */
    private static function catalogRootKey($row, int $id, array $taken): string {
        $slug = strtolower(trim((string)($row['slug'] ?? '')));
        $key = $id === self::FACE_ROOT_CATEGORY_ID ? 'face' : (self::CATALOG_ROOT_KEYS[$slug] ?? '');
        if ($key === '') {
            $key = (string)preg_replace('/[^a-z0-9_-]/', '', $slug);
        }
        return ($key === '' || isset($taken[$key])) ? 'cat_' . $id : $key;
    }

    /**
     * Expand the category picker (L1/L2/L3, most specific wins) into the ma_danh_muc ids it covers.
     * null = no category filter.
     */
    private function expandCategoryIds(string $catL1, string $catL2, string $catL3): ?array {
        if ($catL3 !== '') {
            return [(int)$catL3];
        }
        if ($catL2 !== '') {
            $l2Id = (int)$catL2;
            $subCats = iterator_to_array($this->pdo->danh_muc->find(['parent_id' => $l2Id], ['projection' => ['ma_danh_muc' => 1]]));
            $catIds = [$l2Id];
            foreach ($subCats as $sc) {
                $catIds[] = (int)$sc['ma_danh_muc'];
            }
            return $catIds;
        }
        if ($catL1 !== '' && $catL1 !== 'all') {
            $l1Id = ($catL1 === 'face') ? 1001 : (is_numeric($catL1) ? (int)$catL1 : null);
            if ($l1Id === null) {
                // A synthetic group (body, hair, ...): the orphan ma_danh_muc of that group.
                $group = $this->orphanGroups()[$catL1] ?? null;
                return $group === null ? null : array_map('intval', array_keys($group['children']));
            }
            if ($l1Id) {
                $l2Cats = iterator_to_array($this->pdo->danh_muc->find(['parent_id' => $l1Id], ['projection' => ['ma_danh_muc' => 1]]));
                $allIds = [$l1Id];
                foreach ($l2Cats as $l2) {
                    $l2Id = (int)$l2['ma_danh_muc'];
                    $allIds[] = $l2Id;
                    $l3Cats = iterator_to_array($this->pdo->danh_muc->find(['parent_id' => $l2Id], ['projection' => ['ma_danh_muc' => 1]]));
                    foreach ($l3Cats as $l3) {
                        $allIds[] = (int)$l3['ma_danh_muc'];
                    }
                }
                if ($l1Id === self::FACE_ROOT_CATEGORY_ID) {
                    foreach (array_keys($this->orphanGroups()['face']['children'] ?? []) as $orphanId) {
                        $allIds[] = (int)$orphanId;
                    }
                }
                return array_values(array_unique($allIds));
            }
        }
        return null;
    }

    public function getProducts(): void {
        $this->checkAdmin();
        header('Content-Type: application/json; charset=utf-8');

        $catL1 = trim((string)($_GET['cat_l1'] ?? ''));
        $catL2 = trim((string)($_GET['cat_l2'] ?? ''));
        $catL3 = trim((string)($_GET['cat_l3'] ?? ''));
        $inventory = trim((string)($_GET['inventory'] ?? ''));
        $sales = trim((string)($_GET['sales'] ?? ''));
        $brand = trim((string)($_GET['brand'] ?? ''));
        $q = trim((string)($_GET['q'] ?? ''));
        $limit = max(1, min((int)($_GET['limit'] ?? 80), 300));
        $offset = max(0, (int)($_GET['offset'] ?? 0));

        $filter = [
            'trang_thai' => ['$nin' => ['inactive', 'hidden', 'disabled', '0', 'an', 'ngung ban']]
        ];

        $categoryIds = $this->expandCategoryIds($catL1, $catL2, $catL3);
        if ($categoryIds !== null) {
            $filter['ma_danh_muc'] = ['$in' => $categoryIds];
        }

        if ($inventory !== '') {
            $invList = array_filter(array_map('trim', explode(',', $inventory)));
            foreach ($invList as $inv) {
                if ($inv === 'expiry_17_19' || $inv === 'expiry_max_18') {
                    $now = new \DateTime('now', new \DateTimeZone('Asia/Ho_Chi_Minh'));
                    $nowTime = $now->getTimestamp();
                    $t17 = (clone $now)->modify('+17 months')->getTimestamp();
                    $t19 = (clone $now)->modify('+19 months')->getTimestamp();
                    $t18 = (clone $now)->modify('+18 months')->getTimestamp();
                    $matchedPids = [];
                    foreach ($this->pdo->inventory_batches->find(
                        ['status' => 'available', 'available_quantity' => ['$gt' => 0]],
                        ['projection' => ['product_id' => 1, 'expires_on' => 1]]
                    ) as $b) {
                        $exp = $b['expires_on'] ?? null;
                        $ts = $exp instanceof \MongoDB\BSON\UTCDateTime ? (int)($exp->toDateTime()->getTimestamp()) : null;
                        if ($ts) {
                            $isMatch = ($inv === 'expiry_17_19' && $ts >= $t17 && $ts <= $t19) || ($inv === 'expiry_max_18' && $ts >= $nowTime && $ts <= $t18);
                            if ($isMatch) {
                                $matchedPids[] = (string)$b['product_id'];
                                if (is_numeric($b['product_id'])) $matchedPids[] = (int)$b['product_id'];
                            }
                        }
                    }
                    $filter['ma_san_pham'] = ['$in' => array_values(array_unique($matchedPids))];
                } elseif ($inv === 'stock_high') {
                    $filter['so_luong_ton_kho'] = ['$gt' => 100];
                } elseif ($inv === 'stock_low') {
                    $filter['so_luong_ton_kho'] = ['$lte' => 10, '$gt' => 0];
                } elseif ($inv === 'out_of_stock') {
                    $filter['so_luong_ton_kho'] = ['$lte' => 0];
                }
            }
        }

        if ($sales !== '') {
            if ($sales === 'best_all' || $sales === 'best_30' || $sales === 'best_7') {
                $filter['so_luong_da_ban'] = ['$gt' => 0];
            }
        }

        if ($brand !== '') {
            $brandRegex = new \MongoDB\BSON\Regex(preg_quote($brand, '/'), 'i');
            $filter['$or'] = [
                ['thuong_hieu' => $brandRegex],
                ['ten_san_pham' => $brandRegex]
            ];
        }

        if ($q !== '') {
            $qRegex = new \MongoDB\BSON\Regex(preg_quote($q, '/'), 'i');
            $qOr = [
                ['ten_san_pham' => $qRegex],
            ];
            if (is_numeric($q)) {
                $qOr[] = ['ma_san_pham' => (int)$q];
                $qOr[] = ['ma_san_pham' => $q];
            } else {
                $qOr[] = ['ma_san_pham' => $qRegex];
            }
            if (isset($filter['$or'])) {
                $filter['$and'] = [
                    ['$or' => $filter['$or']],
                    ['$or' => $qOr]
                ];
                unset($filter['$or']);
            } else {
                $filter['$or'] = $qOr;
            }
        }

        $total = 0;
        $products = [];
        try {
            $total = $this->pdo->san_pham->countDocuments($filter);
            $cursor = $this->pdo->san_pham->find($filter, [
                'sort' => ['so_luong_da_ban' => -1, 'ma_san_pham' => -1],
                'skip' => $offset,
                'limit' => $limit
            ]);
            foreach ($cursor as $doc) {
                $docId = (string)($doc['ma_san_pham'] ?? $doc['_id']);
                $rawImg = trim(explode('|', (string)($doc['link_hinh_anh'] ?? $doc['hinh_anh'] ?? ''))[0]);
                $imgUrl = $rawImg;
                if (function_exists('resolve_image_url')) {
                    $imgUrl = resolve_image_url($rawImg);
                } elseif ($rawImg !== '' && !preg_match('#^https?://#i', $rawImg) && !str_starts_with($rawImg, '/')) {
                    $imgUrl = (defined('BASE_URL') ? BASE_URL : '') . '/' . ltrim($rawImg, '/');
                }
                $products[] = [
                    'id' => $docId,
                    'ma_san_pham' => $docId,
                    'ten_san_pham' => (string)($doc['ten_san_pham'] ?? ''),
                    'gia_ban' => (float)($doc['gia_ban'] ?? $doc['gia'] ?? 0),
                    'so_luong_ton_kho' => (int)($doc['so_luong_ton_kho'] ?? 0),
                    'so_luong_da_ban' => (int)($doc['so_luong_da_ban'] ?? 0),
                    'ma_danh_muc' => (int)($doc['ma_danh_muc'] ?? 0),
                    'danh_muc_day_du' => (string)($doc['danh_muc_day_du'] ?? ''),
                    'image' => $imgUrl
                ];
            }
        } catch (\Throwable $e) {}

        $stockSum = 0;
        foreach ($products as $p) {
            $stockSum += (int)$p['so_luong_ton_kho'];
        }
        $syncHash = md5($total . '_' . count($products) . '_' . $stockSum);

        echo json_encode([
            'ok' => true,
            'total' => $total,
            'products' => $products,
            'sync_hash' => $syncHash
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    /**
     * Validate the manual-mode filter form and return exactly the keys the structured query uses.
     * Text is trimmed and cut to PRODUCT_QUERY_TEXT_MAX characters, flags become bool, limit is clamped to 1..100
     * (100 when absent). A value outside its enum, or of the wrong kind, throws InvalidArgumentException.
     * Unknown keys (csrf, ...) are dropped.
     */
    public static function normalizeProductQuery(array $input): array {
        $query = [];
        foreach (['cat_l1', 'cat_l2', 'cat_l3'] as $key) {
            $query[$key] = self::normalizeQueryText($input[$key] ?? null, $key);
        }
        foreach (['expiry', 'stock', 'sales', 'warehouse_mode'] as $key) {
            $query[$key] = self::normalizeQueryEnum($input[$key] ?? null, $key);
        }
        foreach (['scope_code', 'brand', 'q'] as $key) {
            $query[$key] = self::normalizeQueryText($input[$key] ?? null, $key);
        }
        foreach (['brand_cap', 'enough_stock'] as $key) {
            $query[$key] = self::normalizeQueryFlag($input[$key] ?? null, $key);
        }
        $query['limit'] = self::normalizeQueryLimit($input['limit'] ?? null);
        return $query;
    }

    private static function invalidQueryFilter(string $key): InvalidArgumentException {
        return new InvalidArgumentException('Bộ lọc không hợp lệ: ' . $key);
    }

    private static function normalizeQueryText($value, string $key): string {
        if ($value === null) {
            return '';
        }
        if (is_int($value) || is_float($value)) {
            $value = (string)$value;
        }
        if (!is_string($value) || !mb_check_encoding($value, 'UTF-8')) {
            throw self::invalidQueryFilter($key);
        }
        return trim(mb_substr(trim($value), 0, self::PRODUCT_QUERY_TEXT_MAX));
    }

    private static function normalizeQueryEnum($value, string $key): string {
        if ($value === null) {
            return '';
        }
        if (!is_string($value) || !in_array(trim($value), self::PRODUCT_QUERY_ENUMS[$key], true)) {
            throw self::invalidQueryFilter($key);
        }
        return trim($value);
    }

    private static function normalizeQueryFlag($value, string $key): bool {
        if ($value === null || is_bool($value)) {
            return (bool)$value;
        }
        if (is_int($value) || is_float($value)) {
            if ($value == 0 || $value == 1) {
                return (bool)$value;
            }
        } elseif (is_string($value)) {
            $text = strtolower(trim($value));
            if (in_array($text, ['1', 'true', 'on', 'yes'], true)) {
                return true;
            }
            if (in_array($text, ['0', 'false', 'off', 'no', ''], true)) {
                return false;
            }
        }
        throw self::invalidQueryFilter($key);
    }

    private static function normalizeQueryLimit($value): int {
        if (is_string($value)) {
            $value = trim($value);
        }
        if ($value === null || $value === '') {
            return self::PRODUCT_QUERY_LIMIT_MAX;
        }
        if (is_bool($value) || !is_numeric($value) || (is_float($value) && is_nan($value))) {
            throw self::invalidQueryFilter('limit');
        }
        $number = $value + 0;
        if ($number >= self::PRODUCT_QUERY_LIMIT_MAX) {
            return self::PRODUCT_QUERY_LIMIT_MAX;
        }
        return $number < 1 ? 1 : (int)$number;
    }

    /**
     * Body for Flask POST /api/ai-idol/products/query: the normalized filters with the cat_* picker replaced by
     * category_ids. No category selected = no key; a selected category that expands to no usable id = the
     * NO_MATCH_CATEGORY_ID sentinel (never [], which Flask reads as "no filter").
     */
    private function buildProductQueryPayload(array $query): array {
        $catL1 = (string)($query['cat_l1'] ?? '');
        $catL2 = (string)($query['cat_l2'] ?? '');
        $catL3 = (string)($query['cat_l3'] ?? '');
        $payload = $query;
        unset($payload['cat_l1'], $payload['cat_l2'], $payload['cat_l3']);

        $categorySelected = $catL3 !== '' || $catL2 !== '' || ($catL1 !== '' && $catL1 !== 'all');
        if ($categorySelected) {
            $ids = array_values(array_filter(
                $this->expandCategoryIds($catL1, $catL2, $catL3) ?? [],
                static fn(int $id): bool => $id > 0
            ));
            $payload['category_ids'] = $ids !== [] ? $ids : [self::NO_MATCH_CATEGORY_ID];
        }
        return $payload;
    }

    /**
     * Replace every product's image_raw by image = resolve_image_url(image_raw). Anything that is not a JSON
     * body with a products list (a 400/500 error from Flask, a non-JSON gateway page) is returned untouched.
     * Objects are kept as objects so empty {} members survive the round trip.
     */
    private function mapQueryProductImages(string $body): string {
        $data = json_decode($body);
        if (!is_object($data) || !isset($data->products) || !is_array($data->products)) {
            return $body;
        }
        foreach ($data->products as $product) {
            if (!is_object($product)) {
                continue;
            }
            $raw = isset($product->image_raw) && is_string($product->image_raw) ? $product->image_raw : '';
            unset($product->image_raw);
            $product->image = function_exists('resolve_image_url') ? resolve_image_url($raw) : $raw;
        }
        $encoded = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
        return $encoded === false ? $body : $encoded;
    }

    // Manual-mode product list: structured filters -> Flask, same ranking as the Agent. Flask's status and body pass through.
    public function queryProducts(): void {
        $this->checkAdmin();
        header('Content-Type: application/json; charset=utf-8');
        $input = json_decode(file_get_contents('php://input'), true);
        if (!is_array($input)) {
            $input = null;
        }
        $this->verifyCsrf($input);
        if ($input === null) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'message' => 'Dữ liệu bộ lọc không hợp lệ.'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        try {
            $query = self::normalizeProductQuery($input);
        } catch (InvalidArgumentException $e) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'message' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
            exit;
        }
        echo $this->mapQueryProductImages(
            $this->callFlaskAPI('POST', '/api/ai-idol/products/query', $this->buildProductQueryPayload($query))
        );
        exit;
    }

    // Approve one external broadcast platform (youtube/facebook) of a campaign; Flask validates platform and state.
    public function approvePlatform(): void {
        $this->checkAdmin();
        header('Content-Type: application/json; charset=utf-8');
        $input = json_decode(file_get_contents('php://input'), true);
        if (!is_array($input)) {
            $input = null;
        }
        $this->verifyCsrf($input);
        $campaignId = is_string($_GET['campaign_id'] ?? null) ? trim($_GET['campaign_id']) : '';
        $platform = is_string($input['platform'] ?? null) ? trim($input['platform']) : '';
        if ($campaignId === '' || $platform === '') {
            http_response_code(400);
            echo json_encode(['ok' => false, 'message' => 'Thiếu mã chiến dịch hoặc kênh phát cần duyệt.'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        $user = $_SESSION['user'] ?? [];
        echo $this->callFlaskAPI(
            'POST',
            '/api/ai-idol/campaigns/' . rawurlencode($campaignId) . '/platforms/approve',
            ['platform' => $platform, 'by' => (string)($user['ma_kh'] ?? $user['email'] ?? 'admin')]
        );
        exit;
    }

    public function listProductVideos(): void {
        $this->checkAdmin();
        header('Content-Type: application/json; charset=utf-8');
        require_once __DIR__ . '/../models/ProductVideo.php';
        require_once __DIR__ . '/../models/VideoRewardCampaign.php';
        $model = new ProductVideo($this->pdo);
        $rewardModel = new VideoRewardCampaign($this->pdo);
        $campaignId = trim((string)($_GET['campaign_id'] ?? ''));
        $productId = trim((string)($_GET['product_id'] ?? ''));
        $filter = [];
        if ($campaignId !== '') $filter['campaign_id'] = $campaignId;
        if ($productId !== '') $filter['product_id'] = $productId;
        $items = $model->listPublications($filter, 100);
        $videos = $model->getManageableVideos();

        $rewardCampaigns = $rewardModel->listCampaigns([], 200);
        $rewardByVid = [];
        foreach ($rewardCampaigns as $rc) {
            $vid = $rc['video_id'];
            if ($vid !== '') {
                $rewardByVid[$vid] = $rc;
            }
        }
        foreach ($videos as &$v) {
            $vid = (string)($v['video_id'] ?? '');
            $v['reward_campaign'] = $rewardByVid[$vid] ?? null;
        }
        unset($v);

        echo json_encode([
            'ok' => true,
            'videos' => $videos,
            'publications' => $items,
            'reward_campaigns' => $rewardCampaigns
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    public function searchProducts(): void {
        $this->checkAdmin();
        header('Content-Type: application/json; charset=utf-8');
        $q = trim((string)($_GET['q'] ?? ''));
        try {
            $filter = [];
            if ($q !== '') {
                $orConditions = [
                    ['ten_san_pham' => new MongoDB\BSON\Regex(preg_quote($q, '/'), 'i')],
                    ['ma_san_pham' => $q],
                ];
                if (is_numeric($q)) {
                    $orConditions[] = ['ma_san_pham' => (int)$q];
                }
                $filter = ['$or' => $orConditions];
            }
            $cursor = $this->pdo->san_pham->find($filter, [
                'limit' => 50,
                'sort' => ['ma_san_pham' => -1]
            ]);
            $items = [];
            foreach ($cursor as $doc) {
                $rawImg = explode('|', (string)($doc['link_hinh_anh'] ?? $doc['hinh_anh'] ?? ''))[0];
                $pid = (string)($doc['ma_san_pham'] ?? $doc['_id']);
                $items[] = [
                    'id' => $pid,
                    'name' => (string)($doc['ten_san_pham'] ?? 'Sản phẩm ' . $pid),
                    'price' => (float)($doc['gia_ban'] ?? 0),
                    'image' => trim($rawImg)
                ];
            }
            echo json_encode(['ok' => true, 'products' => $items], JSON_UNESCAPED_UNICODE);
        } catch (Throwable $e) {
            http_response_code(500);
            echo json_encode(['ok' => false, 'message' => 'Lỗi tìm kiếm sản phẩm: ' . $e->getMessage()]);
        }
        exit;
    }

    public function publishProductVideo(): void {
        $this->checkAdmin();
        header('Content-Type: application/json; charset=utf-8');
        $input = json_decode(file_get_contents('php://input'), true);
        if (!is_array($input)) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'message' => 'Dữ liệu không hợp lệ.']);
            exit;
        }
        $this->verifyCsrf($input);
        require_once __DIR__ . '/../models/ProductVideo.php';
        $model = new ProductVideo($this->pdo);
        $result = $model->publishVideo($input);
        if (empty($result['ok'])) {
            http_response_code(400);
        }
        echo json_encode($result, JSON_UNESCAPED_UNICODE);
        exit;
    }

    public function togglePublishProductVideo(): void {
        $this->checkAdmin();
        header('Content-Type: application/json; charset=utf-8');
        $input = json_decode(file_get_contents('php://input'), true);
        if (!is_array($input)) {
            $input = [];
        }
        $this->verifyCsrf($input);
        $publicationId = trim((string)($input['publication_id'] ?? $_GET['publication_id'] ?? ''));
        $isPublished = (bool)($input['is_published'] ?? true);
        require_once __DIR__ . '/../models/ProductVideo.php';
        $model = new ProductVideo($this->pdo);

        if ($publicationId === '') {
            $videoId = trim((string)($input['video_id'] ?? ''));
            if ($videoId !== '') {
                $pub = $model->getPublicationByVideoId($videoId);
                if ($pub) {
                    $publicationId = $pub['id'];
                }
            }
        }

        if ($publicationId === '') {
            http_response_code(400);
            echo json_encode(['ok' => false, 'message' => 'Thiếu mã publication_id hoặc video chưa từng được xuất bản.']);
            exit;
        }
        $result = $model->setPublishedStatus($publicationId, $isPublished);
        if (empty($result['ok'])) {
            http_response_code(400);
        }
        echo json_encode($result, JSON_UNESCAPED_UNICODE);
        exit;
    }

    /**
     * Lấy cấu hình chiến dịch voucher cho một video hoặc publication
     */
    public function getRewardCampaign(): void {
        $this->checkAdmin();
        header('Content-Type: application/json; charset=utf-8');
        require_once __DIR__ . '/../models/ProductVideo.php';
        require_once __DIR__ . '/../models/VideoRewardCampaign.php';

        $videoId = trim((string)($_GET['video_id'] ?? ''));
        $publicationId = trim((string)($_GET['publication_id'] ?? ''));
        $campaignId = trim((string)($_GET['campaign_id'] ?? ''));

        $rewardModel = new VideoRewardCampaign($this->pdo);
        $pubModel = new ProductVideo($this->pdo);

        $campaign = null;
        if ($campaignId !== '') {
            $campaign = $rewardModel->getCampaignById($campaignId);
        } elseif ($videoId !== '') {
            $campaign = $rewardModel->getCampaignByVideoId($videoId);
        } elseif ($publicationId !== '') {
            $campaign = $rewardModel->getCampaignByPublicationId($publicationId);
        }

        $publication = null;
        if ($publicationId !== '') {
            $publication = $pubModel->getPublicationById($publicationId);
        } elseif ($videoId !== '') {
            $publication = $pubModel->getPublicationByVideoId($videoId);
        } elseif ($campaign && !empty($campaign['publication_id'])) {
            $publication = $pubModel->getPublicationById($campaign['publication_id']);
        }

        echo json_encode([
            'ok' => true,
            'campaign' => $campaign,
            'publication' => $publication,
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    /**
     * Tạo mới hoặc cập nhật cấu hình chiến dịch voucher xem video
     */
    public function saveRewardCampaign(): void {
        $this->checkAdmin();
        header('Content-Type: application/json; charset=utf-8');
        $input = json_decode(file_get_contents('php://input'), true);
        if (!is_array($input)) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'message' => 'Dữ liệu không hợp lệ.']);
            exit;
        }

        $this->verifyCsrf($input);

        require_once __DIR__ . '/../models/VideoRewardCampaign.php';
        $rewardModel = new VideoRewardCampaign($this->pdo);
        $result = $rewardModel->saveCampaign($input);

        if (empty($result['ok'])) {
            http_response_code(400);
        }
        echo json_encode($result, JSON_UNESCAPED_UNICODE);
        exit;
    }

    /**
     * Bật / tắt kích hoạt chiến dịch voucher xem video
     */
    public function toggleRewardCampaign(): void {
        $this->checkAdmin();
        header('Content-Type: application/json; charset=utf-8');
        $input = json_decode(file_get_contents('php://input'), true);
        if (!is_array($input)) {
            $input = [];
        }

        $this->verifyCsrf($input);

        $campaignId = trim((string)($input['campaign_id'] ?? $_GET['campaign_id'] ?? ''));
        $isActive = (bool)($input['is_active'] ?? true);

        if ($campaignId === '') {
            http_response_code(400);
            echo json_encode(['ok' => false, 'message' => 'Thiếu mã chiến dịch (campaign_id).']);
            exit;
        }

        require_once __DIR__ . '/../models/VideoRewardCampaign.php';
        $rewardModel = new VideoRewardCampaign($this->pdo);
        $result = $rewardModel->toggleActive($campaignId, $isActive);

        if (empty($result['ok'])) {
            http_response_code(400);
        }
        echo json_encode($result, JSON_UNESCAPED_UNICODE);
        exit;
    }
}
