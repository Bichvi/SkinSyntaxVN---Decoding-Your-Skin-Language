<?php
// backend/app/controllers/VideoWatchController.php
declare(strict_types=1);

require_once __DIR__ . '/../models/VideoWatchSession.php';
require_once __DIR__ . '/../models/VideoRewardCampaign.php';
require_once __DIR__ . '/../models/CustomerVoucher.php';
require_once __DIR__ . '/../services/RewardService.php';

class VideoWatchController {
    private $pdo;
    private VideoWatchSession $sessionModel;
    private CustomerVoucher $voucherModel;

    public function __construct($pdo) {
        $this->pdo = $pdo;
        $this->sessionModel = new VideoWatchSession($pdo);
        $this->voucherModel = new CustomerVoucher($pdo);
    }

    private function readJsonInput(): array {
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

    private function getCustomerIdentity(): array {
        return self::customerIdentity();
    }

    /** Identity đã chốt cho request chỉ-đọc (sau khi nhả khóa session). */
    private static ?array $frozenIdentity = null;

    /**
     * Endpoint chỉ-đọc session (start/heartbeat/status/complete): đọc identity một lần rồi nhả khóa session
     * để các request video song song cùng phiên không xếp hàng. Không có đường nào ở đây ghi $_SESSION.
     */
    private function releaseSessionLock(): void {
        try {
            self::$frozenIdentity = self::customerIdentity();
            if (session_status() === PHP_SESSION_ACTIVE) {
                session_write_close();
            }
        } catch (\Throwable $e) {
            self::$frozenIdentity = null;
        }
    }

    public static function customerIdentity(): array {
        if (self::$frozenIdentity !== null) {
            return self::$frozenIdentity;
        }
        $customerId = null;
        $customerEmail = null;
        $guestSessionId = null;

        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        if (function_exists('is_logged_in') && is_logged_in()) {
            $user = function_exists('current_user') ? current_user() : ($_SESSION['user'] ?? null);
            if (!empty($user)) {
                $customerId = (string)($user['id'] ?? $user['ma_kh'] ?? $user['email'] ?? '');
                $customerEmail = (string)($user['email'] ?? '');
            }
        }

        // Keep the active PHP session id even after login. This lets a guest
        // watch session be resumed/migrated when login redirects away from
        // the product page and the customer returns later.
        $activeSessionId = session_id();
        if (!empty($activeSessionId)) {
            $guestSessionId = $activeSessionId;
        } elseif (empty($customerId)) {
            $guestSessionId = 'guest_' . bin2hex(random_bytes(12));
        }

        return [
            'customer_id' => $customerId,
            'customer_email' => $customerEmail,
            'guest_session_id' => $guestSessionId,
        ];
    }

    /**
     * Issue the reward automatically for authenticated customers once the
     * server-side watch session becomes eligible. Guests keep the eligible
     * watch session and can claim it after logging in from the same session.
     */
    private function autoClaimEligibleReward(array $result, string $sessionId): array {
        $sessionEligible = !empty($result['session']['is_eligible'])
            || (($result['session']['watch_state'] ?? '') === 'ELIGIBLE');
        if ((empty($result['is_eligible']) && !$sessionEligible) || trim($sessionId) === '') {
            return $result;
        }

        $identity = $this->getCustomerIdentity();
        if (empty($identity['customer_id'])) {
            return $result;
        }

        $claim = $this->voucherModel->claimReward(
            $sessionId,
            $identity['customer_id'],
            $identity['customer_email'],
            $identity['guest_session_id']
        );

        if (!empty($claim['ok']) && !empty($claim['voucher'])) {
            $voucher = $claim['voucher'];
            $result['auto_claimed'] = true;
            $result['is_rewarded'] = true;
            $result['watch_state'] = 'REWARDED';
            $result['voucher'] = $voucher;
            $result['reward_voucher_code'] = $voucher['voucher_code'] ?? '';
            $result['reward_claimed_at'] = $voucher['claimed_at'] ?? null;
            $result['message'] = 'Voucher đã được tự động cấp vào ví tài khoản của bạn.';
            if (isset($result['session']) && is_array($result['session'])) {
                $result['session']['watch_state'] = 'REWARDED';
                $result['session']['is_rewarded'] = true;
                $result['session']['reward_voucher_code'] = $result['reward_voucher_code'];
                $result['session']['reward_claimed_at'] = $result['reward_claimed_at'];
            }
        }

        return $result;
    }

    /**
     * Quyết định có xét cộng xu theo video hay không (thuần, dễ kiểm thử).
     * Cần: phiên đủ điều kiện, khách đã đăng nhập, phiên thuộc chính khách đó,
     * không phải mode 'time', có chiến dịch và chưa xử lý xu xong.
     */
    public static function shouldAwardVideoXu(array $session, array $identity, array $result): bool {
        $eligible = !empty($result['is_eligible'])
            || !empty($result['session']['is_eligible'])
            || (($result['session']['watch_state'] ?? '') === 'ELIGIBLE');
        if (!$eligible) {
            return false;
        }
        $customerId = (string)($identity['customer_id'] ?? '');
        $owner = (string)($session['customer_id'] ?? '');
        if ($customerId === '' || $owner === '' || !hash_equals($owner, $customerId)) {
            return false;
        }
        if (($session['mode'] ?? 'campaign') === 'time' || empty($session['campaign_id'])) {
            return false;
        }
        return empty($session['xu_settled']);
    }

    /**
     * Cộng xu theo video (reward_xu của chiến dịch) khi phiên đủ điều kiện và khách đã đăng nhập.
     * Không bao giờ làm hỏng response voucher.
     */
    private function awardVideoXuIfEligible(array $result, string $sessionId): array {
        try {
            if (trim($sessionId) === '') {
                return $result;
            }
            $identity = self::customerIdentity();
            if (empty($identity['customer_id'])) {
                return $result;
            }
            $session = $this->sessionModel->getSessionById($sessionId);
            if (!$session || !self::shouldAwardVideoXu($session, $identity, $result)) {
                return $result;
            }
            $campaign = (new VideoRewardCampaign($this->pdo))->getCampaignById((string)$session['campaign_id']);
            if (!$campaign || (int)($campaign['reward_xu'] ?? 0) <= 0) {
                $this->sessionModel->markXuSettled($sessionId);
                return $result;
            }
            $xu = (new RewardService($this->pdo))->awardVideoXu(
                $identity,
                $campaign,
                (string)($session['video_id'] ?? '')
            );
            $result['xu_reward'] = [
                'code' => $xu['code'],
                'xu_awarded' => $xu['xu_awarded'],
                'xu_balance' => $xu['xu_balance'],
            ];
            if (in_array($xu['code'], ['OK', 'ALREADY_CLAIMED', 'NO_XU'], true)) {
                try {
                    $this->sessionModel->markXuSettled($sessionId);
                } catch (\Throwable $e) {
                    error_log('VideoWatchController markXuSettled failed: ' . get_class($e));
                }
            }
        } catch (\Throwable $e) {
            error_log('VideoWatchController awardVideoXuIfEligible failed: ' . get_class($e));
        }
        return $result;
    }

    /**
     * POST / GET: index.php?r=video_watch_start
     */
    public function start(): void {
        header('Content-Type: application/json; charset=utf-8');
        $this->releaseSessionLock();
        $input = $this->readJsonInput();
        if (empty($input)) {
            $input = $_GET;
        }

        $identity = $this->getCustomerIdentity();
        $input['customer_id'] = $identity['customer_id'];
        $input['guest_session_id'] = $identity['guest_session_id'];

        $result = $this->sessionModel->startOrResumeSession($input);
        $result = $this->autoClaimEligibleReward(
            $result,
            (string)($result['session']['watch_session_id'] ?? '')
        );
        $result = $this->awardVideoXuIfEligible($result, (string)($result['session']['watch_session_id'] ?? ''));
        if (empty($result['ok'])) {
            http_response_code(400);
        }
        echo json_encode($result, JSON_UNESCAPED_UNICODE);
        exit;
    }

    /**
     * POST: index.php?r=video_watch_heartbeat
     */
    public function heartbeat(): void {
        header('Content-Type: application/json; charset=utf-8');
        $this->releaseSessionLock();
        $input = $this->readJsonInput();

        $sessionId = trim((string)($input['watch_session_id'] ?? $_GET['watch_session_id'] ?? ''));
        $currentTime = (float)($input['current_time'] ?? $_GET['current_time'] ?? 0);
        $playbackRate = (float)($input['playback_rate'] ?? $_GET['playback_rate'] ?? 1.0);
        $isPaused = (bool)($input['is_paused'] ?? $_GET['is_paused'] ?? false);

        if ($sessionId === '') {
            http_response_code(400);
            echo json_encode(['ok' => false, 'code' => 'MISSING_SESSION', 'message' => 'Thiếu mã watch_session_id.']);
            exit;
        }

        $result = $this->sessionModel->recordHeartbeat($sessionId, $currentTime, $playbackRate, $isPaused);
        $result = $this->autoClaimEligibleReward($result, $sessionId);
        $result = $this->awardVideoXuIfEligible($result, $sessionId);
        if (empty($result['ok'])) {
            if (($result['code'] ?? '') === 'RATE_LIMITED') {
                http_response_code(429);
            } else {
                http_response_code(400);
            }
        }
        echo json_encode($result, JSON_UNESCAPED_UNICODE);
        exit;
    }

    /**
     * GET: index.php?r=video_watch_status
     */
    public function status(): void {
        header('Content-Type: application/json; charset=utf-8');
        $this->releaseSessionLock();
        $sessionId = trim((string)($_GET['watch_session_id'] ?? ''));

        if ($sessionId === '') {
            http_response_code(400);
            echo json_encode(['ok' => false, 'code' => 'MISSING_SESSION', 'message' => 'Thiếu mã watch_session_id.']);
            exit;
        }

        $session = $this->sessionModel->getSessionById($sessionId);
        if (!$session) {
            http_response_code(404);
            echo json_encode(['ok' => false, 'code' => 'NOT_FOUND', 'message' => 'Không tìm thấy phiên xem video.']);
            exit;
        }

        echo json_encode(['ok' => true, 'session' => $session], JSON_UNESCAPED_UNICODE);
        exit;
    }

    /**
     * POST: index.php?r=video_watch_complete
     */
    public function complete(): void {
        header('Content-Type: application/json; charset=utf-8');
        $this->releaseSessionLock();
        $input = $this->readJsonInput();
        $sessionId = trim((string)($input['watch_session_id'] ?? $_GET['watch_session_id'] ?? ''));

        if ($sessionId === '') {
            http_response_code(400);
            echo json_encode(['ok' => false, 'code' => 'MISSING_SESSION', 'message' => 'Thiếu mã watch_session_id.']);
            exit;
        }

        $result = $this->sessionModel->validateCompletion($sessionId);
        $result = $this->autoClaimEligibleReward($result, $sessionId);
        $result = $this->awardVideoXuIfEligible($result, $sessionId);
        if (empty($result['ok'])) {
            http_response_code(400);
        }
        echo json_encode($result, JSON_UNESCAPED_UNICODE);
        exit;
    }

    /**
     * POST: index.php?r=video_watch_claim_reward
     */
    public function claimReward(): void {
        header('Content-Type: application/json; charset=utf-8');
        $input = $this->readJsonInput();
        $sessionId = trim((string)($input['watch_session_id'] ?? $_GET['watch_session_id'] ?? ''));

        if ($sessionId === '') {
            http_response_code(400);
            echo json_encode(['ok' => false, 'code' => 'MISSING_SESSION', 'message' => 'Thiếu mã watch_session_id.']);
            exit;
        }

        $identity = $this->getCustomerIdentity();
        if (empty($identity['customer_id'])) {
            http_response_code(401);
            echo json_encode([
                'ok' => false,
                'code' => 'LOGIN_REQUIRED',
                'message' => 'Vui lòng đăng nhập để nhận voucher ưu đãi.'
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }

        $result = $this->voucherModel->claimReward(
            $sessionId,
            $identity['customer_id'],
            $identity['customer_email'],
            $identity['guest_session_id']
        );

        if (!empty($result['ok'])) {
            $withXu = $this->awardVideoXuIfEligible($result + ['is_eligible' => true], $sessionId);
            if (isset($withXu['xu_reward'])) {
                $result['xu_reward'] = $withXu['xu_reward'];
            }
        }

        if (empty($result['ok'])) {
            $code = $result['code'] ?? '';
            if (in_array($code, ['UNAUTHENTICATED', 'LOGIN_REQUIRED'], true)) {
                http_response_code(401);
            } elseif (in_array($code, ['FORBIDDEN_SESSION', 'SESSION_MISMATCH'], true)) {
                http_response_code(403);
            } elseif (in_array($code, ['QUOTA_EXHAUSTED', 'CAMPAIGN_EXPIRED', 'CAMPAIGN_INACTIVE', 'USER_LIMIT_REACHED'], true)) {
                http_response_code(409); // Conflict / Resource constraint
            } else {
                http_response_code(400);
            }
        }

        echo json_encode($result, JSON_UNESCAPED_UNICODE);
        exit;
    }

    /**
     * GET: index.php?r=customer_vouchers
     */
    public function customerVouchers(): void {
        header('Content-Type: application/json; charset=utf-8');
        $identity = $this->getCustomerIdentity();
        if (empty($identity['customer_id'])) {
            http_response_code(401);
            echo json_encode([
                'ok' => false,
                'code' => 'LOGIN_REQUIRED',
                'vouchers' => [],
                'message' => 'Vui lòng đăng nhập để xem ví voucher.'
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }

        $vouchers = $this->voucherModel->getCustomerVouchers($identity['customer_id']);
        echo json_encode([
            'ok' => true,
            'customer_id' => $identity['customer_id'],
            'vouchers' => $vouchers
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
}
