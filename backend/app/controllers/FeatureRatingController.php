<?php
// backend/app/controllers/FeatureRatingController.php
declare(strict_types=1);

require_once __DIR__ . '/../models/FeatureRating.php';

class FeatureRatingController {
    private $pdo;
    private FeatureRating $model;

    public function __construct($pdo) {
        $this->pdo = $pdo;
        $this->model = new FeatureRating($pdo);
    }

    private function json(array $payload, int $status = 200): void {
        http_response_code($status);
        if (!headers_sent()) {
            header('Content-Type: application/json; charset=utf-8');
            header('Cache-Control: no-store');
        }
        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    private function readInput(): array {
        $input = is_array($_POST) ? $_POST : [];
        $raw = file_get_contents('php://input');
        if (is_string($raw) && trim($raw) !== '') {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                $input = array_merge($input, $decoded);
            }
        }
        return $input;
    }

    /** Cùng cách tạo khóa tài khoản với FavoriteController. */
    public static function accountKey(): string {
        $user = function_exists('current_user') ? (current_user() ?? []) : ($_SESSION['user'] ?? []);
        $email = trim((string)($user['email'] ?? ''));
        if ($email !== '') {
            return 'email:' . strtolower($email);
        }
        $id = trim((string)($user['id'] ?? $user['ma_nd'] ?? $user['ma_kh'] ?? ''));
        return $id !== '' ? 'user:' . $id : '';
    }

    public static function userHash(string $accountKey): string {
        return FeatureRating::userHash($accountKey);
    }

    /** Token CSRF 64 hex lưu trong session. */
    public static function csrfToken(): string {
        $token = $_SESSION['feature_rating_csrf'] ?? '';
        if (!is_string($token) || !preg_match('/^[0-9a-f]{64}$/', $token)) {
            $token = bin2hex(random_bytes(32));
            $_SESSION['feature_rating_csrf'] = $token;
        }
        return $token;
    }

    public static function enabled(): bool {
        $value = function_exists('ss_env') ? (string)ss_env('FEATURE_RATING_ENABLED', '1') : '1';
        return !in_array(strtolower(trim($value)), ['0', 'false', 'off', 'no'], true);
    }

    private function requireAdmin(): void {
        $role = function_exists('current_role') ? current_role() : 'guest';
        if ($role !== 'admin') {
            http_response_code(403);
            header('Content-Type: text/plain; charset=utf-8');
            echo 'Bạn không có quyền truy cập.';
            exit;
        }
    }

    public function submit(): void {
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
            $this->json(['ok' => false, 'message' => 'Phương thức không được hỗ trợ.'], 405);
        }
        if (!function_exists('is_logged_in') || !is_logged_in()) {
            $this->json(['ok' => false, 'code' => 'login_required', 'message' => 'Đăng nhập để gửi đánh giá.'], 401);
        }
        if (!self::enabled()) {
            $this->json(['ok' => false, 'message' => 'Tính năng đánh giá đang tắt.'], 404);
        }

        $input = $this->readInput();
        $sent = (string)($input['csrf'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ''));
        $expected = (string)($_SESSION['feature_rating_csrf'] ?? '');
        if ($expected === '' || $sent === '' || !hash_equals($expected, $sent)) {
            $this->json(['ok' => false, 'code' => 'csrf', 'message' => 'Phiên làm việc đã hết hạn. Vui lòng tải lại trang.'], 419);
        }

        $accountKey = self::accountKey();
        if ($accountKey === '') {
            $this->json(['ok' => false, 'code' => 'login_required', 'message' => 'Đăng nhập để gửi đánh giá.'], 401);
        }

        // Giới hạn 20 lượt / 10 phút / tài khoản (fail-open nếu Redis không sẵn sàng).
        try {
            $limiterFile = __DIR__ . '/../services/RateLimiter.php';
            if (is_file($limiterFile)) {
                require_once $limiterFile;
                $hit = RateLimiter::shared()->hit('feature_rating', self::userHash($accountKey), 20, 600);
                if (!$hit['allowed']) {
                    if (!headers_sent()) {
                        header('Retry-After: ' . (int)$hit['retry_after']);
                    }
                    $this->json(['ok' => false, 'message' => 'Bạn gửi quá nhanh. Vui lòng thử lại sau.'], 429);
                }
            }
        } catch (Throwable $e) {
            error_log('feature_rating rate limiter skipped: ' . get_class($e));
        }

        try {
            $rating = FeatureRating::normalize($input);
        } catch (InvalidArgumentException $e) {
            $this->json(['ok' => false, 'message' => $e->getMessage()], 422);
        }

        try {
            $role = function_exists('current_role') ? current_role() : 'khach_hang';
            $this->model->save($accountKey, $role, $rating);
            $this->json(['ok' => true]);
        } catch (Throwable $e) {
            error_log('feature_rating_submit error: ' . get_class($e));
            $this->json(['ok' => false, 'message' => 'Chưa thể lưu đánh giá. Vui lòng thử lại.'], 503);
        }
    }

    public function summary(): void {
        $this->requireAdmin();
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
            $this->json(['ok' => false, 'message' => 'Phương thức không được hỗ trợ.'], 405);
        }
        try {
            $this->json(['ok' => true, 'features' => $this->model->summary()]);
        } catch (Throwable $e) {
            error_log('feature_ratings_summary error: ' . get_class($e));
            $this->json(['ok' => false, 'message' => 'Chưa thể tải thống kê.'], 503);
        }
    }

    public function exportCsv(): void {
        $this->requireAdmin();
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
            $this->json(['ok' => false, 'message' => 'Phương thức không được hỗ trợ.'], 405);
        }
        try {
            $rows = iterator_to_array($this->model->allForExport(), false);
        } catch (Throwable $e) {
            error_log('feature_ratings_export error: ' . get_class($e));
            $this->json(['ok' => false, 'message' => 'Chưa thể xuất dữ liệu.'], 503);
        }

        $tz = new DateTimeZone(FeatureRating::TIMEZONE);
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="feature_ratings_' . date('Ymd_His') . '.csv"');
        header('Cache-Control: no-store');
        echo "\xEF\xBB\xBF";
        $out = fopen('php://output', 'w');
        fputcsv($out, ['created_at', 'day', 'feature', 'stars', 'comment', 'role', 'context_rule_ids', 'user']);
        foreach ($rows as $row) {
            $created = '';
            if (($row['created_at'] ?? null) instanceof \MongoDB\BSON\UTCDateTime) {
                $created = $row['created_at']->toDateTime()->setTimezone($tz)->format('Y-m-d H:i:s');
            }
            $rules = [];
            $ctx = $row['context'] ?? null;
            if (is_iterable($ctx) || is_array($ctx) || $ctx instanceof ArrayAccess) {
                foreach (($ctx['rule_ids'] ?? []) as $r) {
                    $rules[] = (string)$r;
                }
            }
            fputcsv($out, [
                $created,
                (string)($row['day'] ?? ''),
                (string)($row['feature'] ?? ''),
                (int)($row['stars'] ?? 0),
                self::csvSafe((string)($row['comment'] ?? '')),
                (string)($row['role'] ?? ''),
                implode('|', $rules),
                FeatureRating::userHash((string)($row['account_key'] ?? '')),
            ]);
        }
        fclose($out);
        exit;
    }

    /** Chặn CSV/Excel formula injection trong ô góp ý. */
    private static function csvSafe(string $value): string {
        $value = str_replace(["\r", "\n"], ' ', $value);
        return preg_match('/^[=+\-@\t]/', $value) ? "'" . $value : $value;
    }
}
