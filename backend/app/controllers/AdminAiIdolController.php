<?php
// backend/app/controllers/AdminAiIdolController.php

require_once __DIR__ . '/../models/QuanTri.php';
require_once __DIR__ . '/../models/SanPham.php';

class AdminAiIdolController {
    private $pdo;
    private $sanPhamModel;
    private $flaskUrl;

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
            $_SESSION['admin_ai_idol_csrf'] = bin2hex(random_bytes(32));
            return;
        }
        $token = (string)($input['csrf'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ''));
        if (!hash_equals($expected, $token)) {
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

        $this->render('admin/ai_idol/index', [
            'products' => $products,
            'csrfToken' => self::getCsrfToken(),
            'title' => 'AI Idol Studio & Video Generator'
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
        $campaignId = trim((string)($_GET['campaign_id'] ?? ''));
        header('Content-Type: application/json; charset=utf-8');
        echo $this->callFlaskAPI('POST', '/api/ai-idol/campaigns/' . rawurlencode($campaignId) . '/retry');
        exit;
    }

    public function approveScript(): void {
        $this->checkAdmin();
        $jobId = trim((string)($_GET['job_id'] ?? ''));
        $input = json_decode(file_get_contents('php://input'), true);
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
