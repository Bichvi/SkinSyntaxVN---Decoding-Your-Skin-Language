<?php
// backend/app/services/OrderTabs.php
// Tab con của "Lịch sử đơn hàng" kiểu Shopee, suy ra từ trạng thái đơn + thanh toán sẵn có (không thêm trạng thái mới).
// Dữ liệu thật lẫn "Hoàn thành"/"Hoan thanh", có đơn thiếu trang_thai_normalized, nên mọi so sánh đều qua fold().

final class OrderTabs
{
    public const TABS = [
        'all' => 'Tất cả',
        'cho_thanh_toan' => 'Chờ thanh toán',
        'cho_xac_nhan' => 'Chờ xác nhận',
        'cho_lay_hang' => 'Chờ lấy hàng',
        'dang_giao' => 'Đang giao',
        'hoan_thanh' => 'Hoàn thành',
        'da_huy' => 'Đã hủy',
    ];

    private const STATUS_BY_TEXT = [
        'cho xu ly' => 'pending', 'cho thanh toan' => 'pending', 'moi' => 'pending', 'pending' => 'pending',
        'da xac nhan' => 'confirmed', 'confirmed' => 'confirmed',
        'dang giao' => 'shipping', 'shipping' => 'shipping',
        'hoan thanh' => 'completed', 'completed' => 'completed',
        'da huy' => 'cancelled', 'huy' => 'cancelled', 'cancelled' => 'cancelled', 'canceled' => 'cancelled',
    ];

    private const TAB_BY_STATUS = [
        'pending' => 'cho_xac_nhan', 'confirmed' => 'cho_lay_hang', 'shipping' => 'dang_giao',
        'completed' => 'hoan_thanh', 'cancelled' => 'da_huy',
    ];

    /** Chữ thường, bỏ dấu tiếng Việt, gộp khoảng trắng/gạch: "Đã Hủy" -> "da huy". */
    public static function fold($value): string
    {
        $text = mb_strtolower(trim((string)($value ?? '')), 'UTF-8');
        $text = preg_replace('/\p{Mn}+/u', '', $text) ?? $text;  // dấu tổ hợp (chuỗi dạng NFD)
        $text = preg_replace(
            ['/[àáạảãâầấậẩẫăằắặẳẵ]/u', '/[èéẹẻẽêềếệểễ]/u', '/[ìíịỉĩ]/u', '/[òóọỏõôồốộổỗơờớợởỡ]/u',
             '/[ùúụủũưừứựửữ]/u', '/[ỳýỵỷỹ]/u', '/đ/u', '/[_\-\s]+/u'],
            ['a', 'e', 'i', 'o', 'u', 'y', 'd', ' '],
            $text
        ) ?? $text;
        return trim($text);
    }

    /** pending|confirmed|shipping|completed|cancelled, hoặc '' nếu không nhận ra. */
    public static function orderStatus(array $order): string
    {
        $normalized = (string)($order['trang_thai_normalized'] ?? '');
        if (array_key_exists($normalized, self::TAB_BY_STATUS)) {
            return $normalized;
        }
        return self::STATUS_BY_TEXT[self::fold($order['trang_thai'] ?? '')] ?? '';
    }

    /** paid | awaiting (chờ chuyển khoản) | expired (quá hạn) | unpaid. */
    public static function paymentState(array $order): string
    {
        $text = self::fold($order['status_thanh_toan'] ?? $order['payment_status'] ?? '');
        if (in_array($text, ['da thanh toan', 'paid', 'thanh cong', 'completed'], true)) {
            return 'paid';
        }
        if (str_contains($text, 'qua han') || $text === 'expired') {
            return 'expired';
        }
        if (str_contains($text, 'cho chuyen khoan') || $text === 'awaiting') {
            return 'awaiting';
        }
        return 'unpaid';
    }

    public static function isQrPayment(array $order): bool
    {
        return self::fold($order['hinh_thuc_thanh_toan'] ?? 'cod') === 'bank transfer qr';
    }

    public static function tabOf(array $order): string
    {
        $status = self::orderStatus($order);
        if (in_array($status, ['pending', 'confirmed'], true) && self::isQrPayment($order) && self::paymentState($order) !== 'paid') {
            return 'cho_thanh_toan';
        }
        return self::TAB_BY_STATUS[$status] ?? 'khac';
    }

    /** Thao tác được phép, cùng luật với nút cũ: chỉ hủy khi chưa giao; mua lại khi đã kết thúc. */
    public static function actions(array $order): array
    {
        $tab = self::tabOf($order);
        return [
            'pay_qr' => $tab === 'cho_thanh_toan',
            'cancel' => in_array($tab, ['cho_thanh_toan', 'cho_xac_nhan', 'cho_lay_hang'], true),
            'reorder' => in_array($tab, ['hoan_thanh', 'da_huy'], true),
            'review' => $tab === 'hoan_thanh',
        ];
    }

    public static function normalizeTab($tab): string
    {
        $tab = (string)($tab ?? '');
        return array_key_exists($tab, self::TABS) ? $tab : 'all';
    }

    /** Số đơn theo từng tab (đủ mọi key của TABS, theo đúng thứ tự). */
    public static function counts(array $orders): array
    {
        $counts = array_fill_keys(array_keys(self::TABS), 0);
        foreach ($orders as $order) {
            $counts['all']++;
            $tab = self::tabOf($order);
            if (isset($counts[$tab])) {
                $counts[$tab]++;
            }
        }
        return $counts;
    }

    /** Khoá tìm kiếm: fold() rồi bỏ dấu câu, vd "L'Oréal" -> "loreal". */
    private static function searchKey($value): string
    {
        return trim(preg_replace('/\s+/', ' ', preg_replace('/[^a-z0-9 ]+/', '', self::fold($value)) ?? '') ?? '');
    }

    /** Đơn thuộc tab, khớp từ khoá: mã đơn, hoặc mọi từ đều có trong tên một sản phẩm (không phân biệt dấu/hoa thường). */
    public static function filter(array $orders, string $tab, string $query = ''): array
    {
        $tab = self::normalizeTab($tab);
        $needle = self::searchKey(ltrim(trim($query), '#'));
        $words = $needle === '' ? [] : explode(' ', $needle);
        return array_values(array_filter($orders, static function (array $order) use ($tab, $needle, $words): bool {
            if ($tab !== 'all' && self::tabOf($order) !== $tab) {
                return false;
            }
            if ($needle === '') {
                return true;
            }
            if (str_contains((string)($order['ma_hoa_don'] ?? ''), $needle)) {
                return true;
            }
            foreach (($order['items'] ?? []) as $item) {
                $name = self::searchKey($item['ten_san_pham'] ?? '');
                if (!array_filter($words, static fn(string $word): bool => !str_contains($name, $word))) {
                    return true;
                }
            }
            return false;
        }));
    }

    /** ['items' => [...], 'page' => n, 'pages' => m]; trang ngoài khoảng được kẹp về 1..m. */
    public static function paginate(array $orders, int $page, int $perPage = 10): array
    {
        $perPage = max(1, $perPage);
        $pages = max(1, (int)ceil(count($orders) / $perPage));
        $page = min(max(1, $page), $pages);
        return ['items' => array_slice($orders, ($page - 1) * $perPage, $perPage), 'page' => $page, 'pages' => $pages];
    }
}
