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
}
