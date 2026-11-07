<?php
declare(strict_types=1);

/**
 * Tab "Lịch sử đơn hàng" kiểu Shopee: phân loại đơn theo trạng thái + thanh toán, đếm, tìm kiếm, phân trang
 * và các thao tác được phép. Không cần MongoDB. Chạy: php tests/order_tabs_test.php
 * Các tổ hợp trạng thái lấy từ dữ liệu thật trên Atlas (viết có dấu / không dấu / thiếu trang_thai_normalized).
 */

require_once dirname(__DIR__) . '/app/services/OrderTabs.php';

$passed = 0;
$failed = 0;
$check = static function (string $title, $actual, $expected) use (&$passed, &$failed): void {
    if ($actual === $expected) {
        $passed++;
        echo "PASS {$title}\n";
        return;
    }
    $failed++;
    echo "FAIL {$title}\n      expected " . var_export($expected, true) . ', got ' . var_export($actual, true) . "\n";
};

$order = static fn(string $status, ?string $normalized, string $method, string $payment, array $extra = []): array => array_merge([
    'ma_hoa_don' => 500001, 'trang_thai' => $status, 'trang_thai_normalized' => $normalized,
    'hinh_thuc_thanh_toan' => $method, 'status_thanh_toan' => $payment, 'items' => [],
], $extra);

// Phân loại tab
$cases = [
    'không dấu, thiếu normalized → Hoàn thành' => [$order('Hoan thanh', null, 'cod', 'Chua thanh toan'), 'hoan_thanh'],
    'hoàn thành QR dù còn ghi chờ chuyển khoản' => [$order('Hoan thanh', null, 'bank_transfer_qr', 'Cho chuyen khoan'), 'hoan_thanh'],
    'QR chờ xử lý + chờ chuyển khoản → Chờ thanh toán' => [$order('Chờ xử lý', 'pending', 'bank_transfer_qr', 'Chờ chuyển khoản'), 'cho_thanh_toan'],
    'QR đã xác nhận nhưng chưa trả → Chờ thanh toán' => [$order('Đã xác nhận', 'confirmed', 'bank_transfer_qr', 'Chờ chuyển khoản'), 'cho_thanh_toan'],
    'QR chờ xử lý đã trả → Chờ xác nhận' => [$order('Chờ xử lý', 'pending', 'bank_transfer_qr', 'Đã thanh toán'), 'cho_xac_nhan'],
    'COD chờ xử lý không dấu → Chờ xác nhận' => [$order('Cho xu ly', null, 'cod', 'Chua thanh toan'), 'cho_xac_nhan'],
    'COD đã xác nhận → Chờ lấy hàng' => [$order('Đã xác nhận', 'confirmed', 'cod', 'Chưa thanh toán'), 'cho_lay_hang'],
    'đang giao không dấu → Đang giao' => [$order('Dang giao', null, 'cod', 'Chua thanh toan'), 'dang_giao'],
    'đang giao QR đã trả → Đang giao' => [$order('Đang giao', 'shipping', 'bank_transfer_qr', 'Đã thanh toán'), 'dang_giao'],
    'Da huy không dấu → Đã hủy' => [$order('Da huy', null, 'bank_transfer_qr', 'Qua han thanh toan'), 'da_huy'],
    'Đã hủy viết hoa có dấu → Đã hủy' => [$order('Đã hủy', 'cancelled', 'cod', 'Chưa thanh toán'), 'da_huy'],
    'normalized cũ sai nhưng trang_thai rõ → theo normalized hợp lệ' => [$order('Hoàn thành', 'completed', 'cod', 'Đã thanh toán'), 'hoan_thanh'],
    'trạng thái lạ → chỉ ở Tất cả' => [$order('???', null, 'cod', ''), 'khac'],
];
foreach ($cases as $title => [$o, $tab]) {
    $check("tab: {$title}", OrderTabs::tabOf($o), $tab);
}

// Trạng thái thanh toán
foreach ([['Đã thanh toán', 'paid'], ['Da thanh toan', 'paid'], ['Chờ chuyển khoản', 'awaiting'], ['Cho chuyen khoan', 'awaiting'],
          ['Quá hạn thanh toán', 'expired'], ['Chưa thanh toán', 'unpaid'], ['', 'unpaid']] as [$raw, $state]) {
    $check("payment: '{$raw}' → {$state}", OrderTabs::paymentState(['status_thanh_toan' => $raw]), $state);
}

// Thao tác: lỗi cũ là đơn QR đã thanh toán vẫn hiện banner "đang chờ chuyển khoản" (#502228)
$paidQrDone = $order('Hoàn thành', 'completed', 'bank_transfer_qr', 'Đã thanh toán');
$check('QR hoàn thành đã trả: không mở QR', OrderTabs::actions($paidQrDone)['pay_qr'], false);
$check('QR hoàn thành đã trả: được mua lại', OrderTabs::actions($paidQrDone)['reorder'], true);
$check('QR hoàn thành đã trả: không hủy', OrderTabs::actions($paidQrDone)['cancel'], false);
$awaiting = $order('Chờ xử lý', 'pending', 'bank_transfer_qr', 'Chờ chuyển khoản');
$check('chờ thanh toán: mở QR + hủy', [OrderTabs::actions($awaiting)['pay_qr'], OrderTabs::actions($awaiting)['cancel']], [true, true]);
$check('chờ lấy hàng: vẫn được hủy', OrderTabs::actions($order('Đã xác nhận', 'confirmed', 'cod', 'Chưa thanh toán'))['cancel'], true);
$check('đang giao: không hủy, không mua lại', array_values(array_intersect_key(OrderTabs::actions($order('Đang giao', 'shipping', 'cod', 'Chưa thanh toán')), ['cancel' => 1, 'reorder' => 1])), [false, false]);
$cancelled = $order('Đã hủy', 'cancelled', 'cod', 'Chưa thanh toán');
$check('đã hủy: không hủy lại, được mua lại', [OrderTabs::actions($cancelled)['cancel'], OrderTabs::actions($cancelled)['reorder']], [false, true]);
$check('đã hủy: review chỉ khi hoàn thành', OrderTabs::actions($cancelled)['review'], false);
$check('hoàn thành: được đánh giá', OrderTabs::actions($paidQrDone)['review'], true);

// Đếm, lọc, tìm kiếm
$orders = [
    $order('Hoàn thành', 'completed', 'cod', 'Đã thanh toán', ['ma_hoa_don' => 502291, 'items' => [['ten_san_pham' => 'Nước Tẩy Trang Senka Ngừa Mụn']]]),
    $order('Hoàn thành', 'completed', 'bank_transfer_qr', 'Đã thanh toán', ['ma_hoa_don' => 502228, 'items' => [['ten_san_pham' => 'Combo 2 Kem Chống Nắng Cell Fusion C']]]),
    $order('Đang giao', 'shipping', 'cod', 'Chưa thanh toán', ['ma_hoa_don' => 502300]),
    $order('Da huy', null, 'cod', 'Chua thanh toan', ['ma_hoa_don' => 38]),
];
$counts = OrderTabs::counts($orders);
$check('đếm: Tất cả', $counts['all'], 4);
$check('đếm: Hoàn thành / Đang giao / Đã hủy / Chờ xác nhận', [$counts['hoan_thanh'], $counts['dang_giao'], $counts['da_huy'], $counts['cho_xac_nhan']], [2, 1, 1, 0]);
$check('đếm có đủ mọi tab', array_keys($counts), array_keys(OrderTabs::TABS));
$ids = static fn(array $list): array => array_map(static fn($o) => $o['ma_hoa_don'], $list);
$check('lọc tab Hoàn thành', $ids(OrderTabs::filter($orders, 'hoan_thanh')), [502291, 502228]);
$check('tìm theo mã đơn (có #)', $ids(OrderTabs::filter($orders, 'all', '#502228')), [502228]);
$check('tìm tên sản phẩm không dấu', $ids(OrderTabs::filter($orders, 'all', 'nuoc tay trang')), [502291]);
$check('tìm tên sản phẩm có dấu, hoa thường', $ids(OrderTabs::filter($orders, 'all', 'CHỐNG NẮNG')), [502228]);
$check('tìm trong tab khác không thấy', $ids(OrderTabs::filter($orders, 'dang_giao', 'senka')), []);
$withApostrophe = [$order('Hoàn thành', 'completed', 'cod', 'Đã thanh toán', ['ma_hoa_don' => 502274, 'items' => [['ten_san_pham' => "Nước Tẩy Trang L'Oreal Tươi Mát Cho Da Dầu"]]])];
$check('tìm từng từ, bỏ dấu nháy: "nuoc tay trang loreal"', $ids(OrderTabs::filter($withApostrophe, 'all', 'nuoc tay trang loreal')), [502274]);
$check('tìm từ không liền nhau: "tẩy trang da dầu"', $ids(OrderTabs::filter($withApostrophe, 'all', 'tẩy trang da dầu')), [502274]);
$check('thiếu một từ thì không khớp', $ids(OrderTabs::filter($withApostrophe, 'all', 'tay trang senka')), []);
$check('tab lạ → Tất cả', OrderTabs::normalizeTab('xyz'), 'all');
$check('tab hợp lệ giữ nguyên', OrderTabs::normalizeTab('cho_lay_hang'), 'cho_lay_hang');

// Phân trang
$many = array_map(static fn(int $i) => $order('Hoàn thành', 'completed', 'cod', 'Đã thanh toán', ['ma_hoa_don' => $i]), range(1, 25));
$page3 = OrderTabs::paginate($many, 3, 10);
$check('trang 3/3 có 5 đơn', [count($page3['items']), $page3['page'], $page3['pages']], [5, 3, 3]);
$check('trang vượt quá → trang cuối', OrderTabs::paginate($many, 99, 10)['page'], 3);
$check('trang < 1 → trang 1', OrderTabs::paginate($many, 0, 10)['items'][0]['ma_hoa_don'], 1);
$check('danh sách rỗng → 1 trang', [OrderTabs::paginate([], 1, 10)['pages'], OrderTabs::paginate([], 1, 10)['items']], [1, []]);

echo "\n{$passed} passed, {$failed} failed\n";
exit($failed === 0 ? 0 : 1);
