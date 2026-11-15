<?php
// backend/app/controllers/RewardController.php
declare(strict_types=1);

require_once __DIR__ . '/VideoWatchController.php';
require_once __DIR__ . '/../services/RewardRules.php';
require_once __DIR__ . '/../services/RewardService.php';
require_once __DIR__ . '/../services/RewardCsrf.php';

class RewardController
{
    private $pdo;
    private RewardService $service;
    /** @var callable */
    private $identity;

    public function __construct($pdo, ?RewardService $service = null, ?callable $identity = null)
    {
        $this->pdo = $pdo;
        $this->service = $service ?? new RewardService($pdo);
        $this->identity = $identity ?? [VideoWatchController::class, 'customerIdentity'];
    }

    private static function statusFor(string $code): int
    {
        switch ($code) {
            case 'OK':
                return 200;
            case 'LOGIN_REQUIRED':
                return 401;
            case 'CSRF_INVALID':
                return 403;
            case 'METHOD_NOT_ALLOWED':
                return 405;
            case 'ALREADY_CHECKED_IN':
            case 'ALREADY_CLAIMED':
            case 'DAILY_CAP_REACHED':
                return 409;
            case 'TEMPORARY_ERROR':
                return 503;
            default:
                return 400; // NOT_ENOUGH_WATCH, INVALID_MILESTONE, NO_CUSTOMER_PROFILE, NO_XU, unknown
        }
    }

    private function fail(string $code, string $message): array
    {
        return ['status' => self::statusFor($code), 'body' => ['ok' => false, 'code' => $code, 'message' => $message]];
    }

    private function identity(): array
    {
        $id = ($this->identity)();
        return is_array($id) ? $id : [];
    }

    private function isGuest(array $id): bool
    {
        return trim((string) ($id['customer_id'] ?? '')) === '';
    }

    private function markCheckedIn(): void
    {
        $_SESSION['reward_checked_in_date'] = RewardRules::dateVn(time());
    }

    private function guard(string $method, ?string $csrf): ?array
    {
        if (strtoupper($method) !== 'POST') {
            return $this->fail('METHOD_NOT_ALLOWED', 'Phương thức không được hỗ trợ.');
        }
        if (!RewardCsrf::valid($csrf)) {
            return $this->fail('CSRF_INVALID', 'Phiên làm việc không hợp lệ, vui lòng tải lại trang.');
        }
        return null;
    }

    public function summaryResult(): array
    {
        $id = $this->identity();
        if ($this->isGuest($id)) {
            return $this->fail('LOGIN_REQUIRED', 'Vui lòng đăng nhập để xem phần thưởng.');
        }
        $summary = $this->service->summary($id);
        if (!empty($summary['checkin']['checked_in_today'])) {
            $this->markCheckedIn();
        }
        $code = (string) ($summary['code'] ?? 'OK');
        if ($code !== 'OK') {
            return ['status' => self::statusFor($code), 'body' => array_merge(['ok' => false], $summary)];
        }
        return ['status' => 200, 'body' => array_merge(['ok' => true, 'code' => 'OK'], $summary)];
    }

    public function historyResult(): array
    {
        $id = $this->identity();
        if ($this->isGuest($id)) {
            return $this->fail('LOGIN_REQUIRED', 'Vui lòng đăng nhập để xem lịch sử xu.');
        }
        $history = $this->service->history($id);
        return ['status' => 200, 'body' => ['ok' => true, 'code' => 'OK', 'items' => is_array($history) ? array_values($history) : []]];
    }

    private function actionBody(array $r): array
    {
        $code = (string) ($r['code'] ?? 'OK');
        $r['ok'] = $code === 'OK';
        return ['status' => self::statusFor($code), 'body' => $r];
    }

    public function checkinResult(string $method, ?string $csrf): array
    {
        if ($err = $this->guard($method, $csrf)) {
            return $err;
        }
        $r = $this->service->checkin($this->identity());
        $code = (string) ($r['code'] ?? '');
        if ($code === 'OK' || $code === 'ALREADY_CHECKED_IN') {
            $this->markCheckedIn();
        }
        return $this->actionBody($r);
    }

    public function milestoneResult(string $method, ?string $csrf, array $input): array
    {
        if ($err = $this->guard($method, $csrf)) {
            return $err;
        }
        $minutes = filter_var($input['minutes'] ?? null, FILTER_VALIDATE_INT);
        if ($minutes === false) {
            return $this->fail('INVALID_MILESTONE', 'Mốc xem không hợp lệ.');
        }
        return $this->actionBody($this->service->claimMilestone($this->identity(), (int) $minutes));
    }

    private function emit(array $res): void
    {
        http_response_code($res['status']);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($res['body'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    private function readJsonInput(): array
    {
        $input = $_POST;
        $raw = file_get_contents('php://input');
        if (is_string($raw) && trim($raw) !== '') {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                $input = array_merge($input, $decoded);
            }
        }
        return is_array($input) ? $input : [];
    }

    public function summary(): void
    {
        $this->emit($this->summaryResult());
    }

    public function history(): void
    {
        $this->emit($this->historyResult());
    }

    public function checkin(): void
    {
        $this->emit($this->checkinResult((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'), $_SERVER['HTTP_X_CSRF_TOKEN'] ?? null));
    }

    public function milestoneClaim(): void
    {
        $method = (string) ($_SERVER['REQUEST_METHOD'] ?? 'GET');
        $this->emit($this->milestoneResult($method, $_SERVER['HTTP_X_CSRF_TOKEN'] ?? null, $this->readJsonInput()));
    }

    private function featuredProductId(): ?string
    {
        try {
            $doc = $this->pdo->ai_product_videos->findOne(
                ['is_published' => true],
                ['sort' => ['created_at' => -1]]
            );
            if ($doc === null) {
                return null;
            }
            $ids = $doc['associated_product_ids'] ?? [];
            if ($ids instanceof \Traversable) {
                $ids = iterator_to_array($ids, false);
            }
            if (is_array($ids) && $ids) {
                $first = trim((string) reset($ids));
                return $first !== '' ? $first : null;
            }
        } catch (\Throwable $e) {
            error_log('reward featuredProductId failed: ' . get_class($e));
        }
        return null;
    }

    public function page(): void
    {
        $id = $this->identity();
        $data = [
            'rewardCsrf' => RewardCsrf::token(),
            'isLoggedIn' => !$this->isGuest($id),
            'featuredProductId' => $this->featuredProductId(),
        ];
        extract($data);
        $menuCats = [];
        try {
            require_once __DIR__ . '/../models/SanPham.php';
            $menuCats = (new SanPham($this->pdo))->menuTree();
        } catch (\Throwable $e) {
            error_log('reward page menu error: ' . get_class($e));
        }
        $viewDir = defined('VIEW_DIR') ? VIEW_DIR : __DIR__ . '/../views';
        require $viewDir . '/layouts/header.php';
        require $viewDir . '/phan_thuong.php';
        require $viewDir . '/layouts/footer.php';
    }
}
