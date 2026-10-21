<?php
// backend/app/services/AfterResponse.php
//
// Chạy các tác vụ phụ (ghi log tương tác...) SAU khi đã gửi xong response cho trình duyệt,
// để không cộng thêm các round-trip tới MongoDB Atlas (~40-60 ms/lần) vào thời gian chờ của người dùng.
// Ở CLI (không có fastcgi_finish_request) các tác vụ chỉ chạy lúc shutdown.

final class AfterResponse
{
    /** @var array<int, callable> */
    private static array $tasks = [];
    private static bool $registered = false;

    /** Xếp tác vụ chạy sau response. Tác vụ cần tự mang theo dữ liệu (session id, user id...) vì session đã đóng. */
    public static function defer(callable $task): void
    {
        self::$tasks[] = $task;
        if (self::$registered) {
            return;
        }
        self::$registered = true;
        register_shutdown_function([self::class, 'run']);
    }

    /** @internal Gọi bởi register_shutdown_function. */
    public static function run(): void
    {
        // Xả mọi output buffer (ob_start callback ở index.php vẫn được áp dụng khi flush).
        while (ob_get_level() > 0) {
            @ob_end_flush();
        }
        if (session_status() === PHP_SESSION_ACTIVE) {
            @session_write_close();
        }
        if (function_exists('fastcgi_finish_request')) {
            @fastcgi_finish_request();
        }

        while (self::$tasks !== []) {
            $task = array_shift(self::$tasks);
            try {
                $task();
            } catch (\Throwable $e) {
                error_log('AfterResponse task error: ' . $e->getMessage());
            }
        }
    }
}
