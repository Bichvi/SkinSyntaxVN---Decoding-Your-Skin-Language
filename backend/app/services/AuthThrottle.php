<?php
// Giới hạn số lần thử cho các luồng xác thực (đăng nhập, quên mật khẩu, gửi OTP đăng ký).
// Dùng RateLimiter (Redis, cửa sổ cố định). Redis lỗi -> cho qua (fail-open).

require_once __DIR__ . '/RateLimiter.php';

final class AuthThrottle
{
    // [hậu tố scope => [giới hạn, cửa sổ giây]]
    const RULES = [
        'login'      => ['email' => [10, 900], 'ip' => [30, 900], 'session' => [20, 900]],
        'reset'      => ['email' => [3, 900],  'ip' => [10, 900], 'session' => [5, 900]],
        'signup_otp' => ['email' => [3, 600],  'ip' => [10, 600], 'session' => [5, 600]],
    ];

    // Số lần nhập sai OTP tối đa trước khi phải lấy mã mới.
    const OTP_MAX_FAILS = 5;

    /** @return array{allowed:bool,retry_after:int} */
    public static function check(string $action, string $email, ?RateLimiter $limiter = null, ?string $ipIdent = null, ?string $sessionId = null): array
    {
        $rules = self::RULES[$action] ?? null;
        if ($rules === null) {
            return ['allowed' => true, 'retry_after' => 0];
        }
        $limiter = $limiter ?? RateLimiter::shared();
        $ipIdent = $ipIdent ?? RateLimiter::clientIdentity();
        $sessionId = $sessionId ?? (string)session_id();

        // Danh sách [tên scope, ident, giới hạn, cửa sổ]; ident luôn là hash cắt ngắn.
        $targets = [];
        $emailNorm = strtolower(trim($email));
        if ($emailNorm !== '') {
            $targets[] = ['auth_' . $action . '_email', substr(hash('sha256', 'auth-email|' . $emailNorm), 0, 32), $rules['email']];
        }
        if ($ipIdent !== null && $ipIdent !== '') {
            $targets[] = ['auth_' . $action . '_ip', $ipIdent, $rules['ip']];
        } elseif ($sessionId !== '') {
            // IP không tin được (sau nginx/private) -> đếm theo phiên.
            $targets[] = ['auth_' . $action . '_session', substr(hash('sha256', 'auth-sid|' . $sessionId), 0, 32), $rules['session']];
        }

        $allowed = true;
        $retry = 0;
        foreach ($targets as [$scope, $ident, $rule]) {
            // Không dừng sớm để các bộ đếm luôn nhất quán.
            $r = $limiter->hit($scope, $ident, $rule[0], $rule[1]);
            if (!$r['allowed']) {
                $allowed = false;
                $retry = max($retry, (int)$r['retry_after']);
                error_log('[auth-throttle] blocked action=' . $action . ' scope=' . $scope);
            }
        }
        return ['allowed' => $allowed, 'retry_after' => $retry];
    }

    public static function message(int $retryAfter): string
    {
        return 'Bạn thao tác quá nhiều lần. Vui lòng thử lại sau ' . max(1, (int)ceil($retryAfter / 60)) . ' phút.';
    }

    // OTP nằm trong session nên bộ đếm sai theo session, mã mới sẽ ghi đè và reset.
    public static function otpLocked(array $otpState): bool
    {
        return (int)($otpState['failed_attempts'] ?? 0) >= self::OTP_MAX_FAILS;
    }

    /** @return array{state:array,locked:bool} */
    public static function otpFailed(array $otpState): array
    {
        $otpState['failed_attempts'] = (int)($otpState['failed_attempts'] ?? 0) + 1;
        return ['state' => $otpState, 'locked' => self::otpLocked($otpState)];
    }
}
