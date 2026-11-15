<?php
// backend/app/services/RewardCsrf.php
declare(strict_types=1);

class RewardCsrf
{
    private static function ensureSession(): void
    {
        if (session_status() === PHP_SESSION_NONE && PHP_SAPI !== 'cli' && !headers_sent()) {
            session_start();
        }
        if (!isset($_SESSION) || !is_array($_SESSION)) {
            $_SESSION = [];
        }
    }

    public static function token(): string
    {
        self::ensureSession();
        $t = $_SESSION['reward_csrf'] ?? '';
        if (!is_string($t) || !preg_match('/^[a-f0-9]{64}$/', $t)) {
            $t = bin2hex(random_bytes(32));
            $_SESSION['reward_csrf'] = $t;
        }
        return $t;
    }

    public static function valid(?string $given): bool
    {
        if ($given === null || $given === '') {
            return false;
        }
        self::ensureSession();
        $t = $_SESSION['reward_csrf'] ?? '';
        return is_string($t) && $t !== '' && hash_equals($t, $given);
    }
}
