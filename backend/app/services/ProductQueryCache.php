<?php
// backend/app/services/ProductQueryCache.php
//
// Cache ngắn hạn (file) cho dữ liệu đọc nhiều từ MongoDB Atlas: mỗi round-trip tới Atlas ~40 ms
// nên các truy vấn listing/lookup ít đổi được giữ tạm vài phút. Mặc định lưu ở sys_get_temp_dir()
// (/tmp trong container, FS Linux gốc, nhanh hơn bind mount Windows). Cache chỉ là tối ưu:
// mọi lỗi filesystem đều bị bỏ qua, lỗi của producer thì ném ra nguyên vẹn như khi chưa cache.

final class ProductQueryCache
{
    private const VERSION = 'v1';

    // Chỉ cho phép unserialize các class giá trị của MongoDB (dữ liệu san_pham chứa ObjectId, UTCDateTime, BSONArray...).
    private const ALLOWED_CLASSES = [
        \MongoDB\BSON\ObjectId::class,
        \MongoDB\BSON\UTCDateTime::class,
        \MongoDB\BSON\Decimal128::class,
        \MongoDB\BSON\Int64::class,
        \MongoDB\BSON\Timestamp::class,
        \MongoDB\BSON\Binary::class,
        \MongoDB\BSON\Regex::class,
        \MongoDB\BSON\Document::class,
        \MongoDB\BSON\PackedArray::class,
        \MongoDB\Model\BSONArray::class,
        \MongoDB\Model\BSONDocument::class,
    ];

    private static ?string $directory = null;

    /** Đổi thư mục lưu cache (null = mặc định). Dùng cho test để CLI chạy bằng root không tạo file root trong thư mục của FPM. */
    public static function setDirectory(?string $dir): void
    {
        self::$directory = ($dir === null || $dir === '') ? null : rtrim($dir, '/\\');
    }

    /**
     * Trả giá trị còn hạn trong cache; nếu không có thì gọi $producer, lưu kết quả
     * (trừ null và []) rồi trả về. Exception từ $producer được ném ra nguyên vẹn.
     */
    public static function remember(string $key, int $ttlSeconds, callable $producer)
    {
        $hit = self::read($key, $ttlSeconds);
        if ($hit !== null) {
            return $hit['v'];
        }

        $value = $producer();
        self::put($key, $value);
        return $value;
    }

    /** Ghi đè giá trị cache (bỏ qua null và [] để lỗi tạm thời của Atlas không bị cache lại). */
    public static function put(string $key, $value): void
    {
        if ($value === null || $value === []) {
            return;
        }

        $tmp = null;
        try {
            $dir = self::directory();
            if (!is_dir($dir)) {
                @mkdir($dir, 0777, true);
            }

            $path = self::pathFor($key);
            $tmp = $path . '.' . bin2hex(random_bytes(6)) . '.tmp';
            if (@file_put_contents($tmp, serialize(['v' => $value]), LOCK_EX) === false) {
                @unlink($tmp);
                return;
            }
            // rename cùng thư mục là atomic: reader không bao giờ thấy file ghi dở
            if (!@rename($tmp, $path)) {
                @unlink($tmp);
            }
        } catch (\Throwable $e) {
            if ($tmp !== null) {
                @unlink($tmp);
            }
        }
    }

    /** Xóa toàn bộ file cache (gọi khi admin sửa sản phẩm/danh mục để dữ liệu hiện ngay). */
    public static function flush(): void
    {
        try {
            $files = @glob(self::directory() . '/*.cache');
            if (!is_array($files)) {
                return;
            }
            foreach ($files as $file) {
                @unlink($file);
            }
        } catch (\Throwable $e) {
            // Cache là best-effort.
        }
    }

    private static function read(string $key, int $ttlSeconds): ?array
    {
        try {
            $path = self::pathFor($key);
            clearstatcache(true, $path);
            $mtime = @filemtime($path);
            if ($mtime === false || (time() - $mtime) >= $ttlSeconds) {
                return null;
            }

            $raw = @file_get_contents($path);
            if (!is_string($raw) || $raw === '') {
                return null;
            }

            $data = @unserialize($raw, ['allowed_classes' => self::ALLOWED_CLASSES]);
            if (!is_array($data) || !array_key_exists('v', $data)) {
                return null;
            }
            return $data;
        } catch (\Throwable $e) {
            return null;
        }
    }

    private static function directory(): string
    {
        return self::$directory ?? (sys_get_temp_dir() . '/skinsyntax-query-cache');
    }

    private static function pathFor(string $key): string
    {
        return self::directory() . '/' . sha1(self::VERSION . '|' . $key) . '.cache';
    }
}
