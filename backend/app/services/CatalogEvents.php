<?php
// Phát sự kiện thay đổi catalog (U11): XADD catalog:changes:v1 MAXLEN ~ 50000 * type <..> ma_san_pham <..> ts <ms> ...
// Consumer: ai-service-flask/workers/catalog_sync.py (group catalog_sync, debounce 30s) -> Neo4j + Chroma + xả cache.
//
// - Redis lỗi => ghi log cảnh báo, trả false; KHÔNG BAO GIỜ ném lỗi hay chặn thao tác admin.
// - Điểm gọi tập trung ở model SanPham (adminInsert / adminUpdate / updateProductVisibility / updateInventory)
//   và HoaDon (hết/còn hàng do đơn). Job "đồng bộ tăng dần mỗi giờ" bù các sự kiện bị lỡ.
// - Loại sự kiện: price_changed / stock_changed không đổi điểm SIMILAR_TO nên worker bỏ qua bước similarity.

require_once __DIR__ . '/RedisLite.php';

final class CatalogEvents
{
    public const STREAM = 'catalog:changes:v1';
    public const MAXLEN = 50000;

    public const TYPE_CREATED = 'product_created';
    public const TYPE_UPDATED = 'product_updated';
    public const TYPE_DELETED = 'product_deleted';
    public const TYPE_HIDDEN = 'product_hidden';
    public const TYPE_SHOWN = 'product_shown';
    public const TYPE_PRICE = 'price_changed';
    public const TYPE_STOCK = 'stock_changed';
    public const TYPE_BULK = 'bulk_import';

    public const TYPES = [
        self::TYPE_CREATED, self::TYPE_UPDATED, self::TYPE_DELETED, self::TYPE_HIDDEN, self::TYPE_SHOWN,
        self::TYPE_PRICE, self::TYPE_STOCK, self::TYPE_BULK,
    ];

    /** Giống graph_sync.transform.HIDDEN_STATUSES / SanPham::visibleProductFilter(). */
    public const HIDDEN_STATUSES = ['inactive', 'hidden', 'tam_an', 'taman', 'disabled', 'off', '0'];

    /** Trường chỉ ảnh hưởng giá / tồn kho (không vào điểm tương tự). */
    public const PRICE_FIELDS = ['gia_ban', 'gia_thi_truong', 'gia_goc', 'gia_giam', 'phan_tram_giam_gia'];
    public const STOCK_FIELDS = ['so_luong_ton_kho', 'so_luong_ton', 'ton_kho', 'stock', 'quantity', 'trang_thai_kho', 'da_khoi_tao_kho'];
    /** Trường hệ thống luôn đổi khi ghi, bỏ qua khi so sánh. */
    public const IGNORED_FIELDS = ['updated_at', 'ngay_cap_nhat', 'ngay_tao', 'created_at', '_id'];

    /** @return array<string,string> */
    public static function event(string $type, string $productId, array $extra = [], ?int $nowMs = null): array
    {
        $event = [
            'type' => in_array($type, self::TYPES, true) ? $type : self::TYPE_UPDATED,
            'ma_san_pham' => trim($productId),
            'ts' => (string)($nowMs ?? (int)round(microtime(true) * 1000)),
        ];
        foreach ($extra as $key => $value) {
            if (is_string($key) && !isset($event[$key]) && (is_scalar($value) || $value === null)) {
                $event[$key] = mb_substr((string)$value, 0, 200);
            }
        }
        return $event;
    }

    /** Một sự kiện. Redis lỗi/không cấu hình => false (đã ghi log), không ném. */
    public static function publish(string $type, $productId, array $extra = [], ?RedisLite $redis = null): bool
    {
        $id = trim((string)$productId);
        if ($id === '') {
            return false;
        }
        try {
            $redis = $redis ?? RedisLite::shared();
            if ($redis === null) {
                error_log('CatalogEvents: Redis không khả dụng, bỏ qua sự kiện ' . $type . ' #' . $id . ' (job đồng bộ hằng giờ sẽ bù)');
                return false;
            }
            $ok = $redis->xadd(self::STREAM, self::event($type, $id, $extra), self::MAXLEN) !== null;
            if (!$ok) {
                error_log('CatalogEvents: XADD thất bại cho ' . $type . ' #' . $id . ' (job đồng bộ hằng giờ sẽ bù)');
            }
            return $ok;
        } catch (\Throwable $e) {
            error_log('CatalogEvents: lỗi khi phát sự kiện ' . $type . ': ' . get_class($e));
            return false;
        }
    }

    /** Nhiều id cùng loại (nhập hàng loạt). Trả về số sự kiện đã đẩy. */
    public static function publishMany(string $type, array $productIds, array $extra = [], ?RedisLite $redis = null): int
    {
        $sent = 0;
        foreach (array_unique(array_map('strval', $productIds)) as $id) {
            if (self::publish($type, $id, $extra, $redis)) {
                $sent++;
            }
        }
        return $sent;
    }

    /** Trạng thái hiển thị mới -> loại sự kiện. */
    public static function visibilityType(string $normalizedStatus): string
    {
        return in_array(strtolower(trim($normalizedStatus)), self::HIDDEN_STATUSES, true) ? self::TYPE_HIDDEN : self::TYPE_SHOWN;
    }

    /**
     * Phân loại một lần sửa sản phẩm từ trước/sau: chỉ giá => price_changed, chỉ tồn kho => stock_changed,
     * còn lại (hoặc không chắc) => product_updated. So sánh theo chuỗi nên khác kiểu int/string vẫn bằng nhau;
     * nhầm sang product_updated là hướng an toàn (chỉ tốn thêm bước similarity).
     *
     * @param array $before bản ghi hiện tại; @param array $after phần $set sắp ghi
     * @return array{0:string,1:array<int,string>} [type, trường đã đổi]
     */
    public static function classifyChange(array $before, array $after): array
    {
        $changed = [];
        foreach ($after as $field => $value) {
            if (!is_string($field) || in_array($field, self::IGNORED_FIELDS, true)) {
                continue;
            }
            if (self::scalarize($before[$field] ?? null) !== self::scalarize($value)) {
                $changed[] = $field;
            }
        }
        if ($changed === []) {
            return [self::TYPE_UPDATED, []];
        }
        $priceOnly = array_diff($changed, self::PRICE_FIELDS) === [];
        $stockOnly = array_diff($changed, self::STOCK_FIELDS) === [];
        $priceOrStock = array_diff($changed, array_merge(self::PRICE_FIELDS, self::STOCK_FIELDS)) === [];
        if ($priceOnly) {
            return [self::TYPE_PRICE, $changed];
        }
        if ($stockOnly) {
            return [self::TYPE_STOCK, $changed];
        }
        return [$priceOrStock ? self::TYPE_PRICE : self::TYPE_UPDATED, $changed];
    }

    /** Chuẩn hóa giá trị để so sánh (số thực nguyên == chuỗi số, BSON/mảng -> JSON). */
    private static function scalarize($value): string
    {
        if ($value === null) {
            return '';
        }
        if (is_bool($value)) {
            return $value ? '1' : '0';
        }
        if (is_int($value) || is_float($value)) {
            return (string)(float)$value === (string)(int)$value ? (string)(int)$value : (string)$value;
        }
        if (is_string($value)) {
            $trim = trim($value);
            return is_numeric($trim) && (string)(float)$trim === (string)(int)(float)$trim ? (string)(int)(float)$trim : $trim;
        }
        if ($value instanceof \Traversable) {
            $value = iterator_to_array($value, false);
        }
        $json = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);
        return $json === false ? '' : $json;
    }
}
