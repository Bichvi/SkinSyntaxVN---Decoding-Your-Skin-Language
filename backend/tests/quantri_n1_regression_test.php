<?php
declare(strict_types=1);

/**
 * Test hồi quy N+1 cho QuanTri::listOrders() và QuanTri::getNotificationCenterData().
 *
 * CHỈ ĐỌC trên MongoDB Atlas. So sánh serialize() kết quả của bản mới với bản cũ (/tmp/QuanTriOld.php,
 * là bản sao QuanTri.php trước khi tối ưu, đổi tên lớp thành QuanTriOld). Nếu file cũ không có thì
 * các so sánh old-vs-new được SKIP, phần còn lại vẫn chạy.
 * Chạy trong container: php -d memory_limit=1024M /var/www/html/tests/quantri_n1_regression_test.php
 */

require_once '/var/www/html/app/config/db.php';
require_once '/var/www/html/app/models/QuanTri.php';

$oldFile = '/tmp/QuanTriOld.php';
$hasOld = is_file($oldFile);
if ($hasOld) {
    require_once $oldFile;
}

final class CommandCounter implements MongoDB\Driver\Monitoring\CommandSubscriber
{
    public int $count = 0;
    public array $byCommand = [];

    public function commandStarted(MongoDB\Driver\Monitoring\CommandStartedEvent $event): void
    {
        $this->count++;
        $name = $event->getCommandName();
        $this->byCommand[$name] = ($this->byCommand[$name] ?? 0) + 1;
    }

    public function commandSucceeded(MongoDB\Driver\Monitoring\CommandSucceededEvent $event): void
    {
    }

    public function commandFailed(MongoDB\Driver\Monitoring\CommandFailedEvent $event): void
    {
    }

    public function reset(): void
    {
        $this->count = 0;
        $this->byCommand = [];
    }
}

/**
 * Chữ ký so sánh: serialize từng phần tử riêng lẻ (đến $depth cấp) để tránh tham chiếu r:N giữa các đơn
 * dùng chung đối tượng BSON (vd. hai thông báo cùng một đơn) làm lệch kết quả dù giá trị giống hệt.
 */
function sig($value, int $depth)
{
    if ($depth > 0 && is_array($value)) {
        return array_map(fn($v) => sig($v, $depth - 1), $value);
    }
    return serialize($value);
}

$passed = 0;
$failed = 0;
function check(string $id, string $title, bool $ok, string $evidence = ''): void
{
    global $passed, $failed;
    if ($ok) {
        $passed++;
        echo "PASS {$id} - {$title}" . ($evidence !== '' ? " ({$evidence})" : '') . "\n";
        return;
    }
    $failed++;
    echo "FAIL {$id} - {$title}" . ($evidence !== '' ? " ({$evidence})" : '') . "\n";
}

$counter = new CommandCounter();
MongoDB\Driver\Monitoring\addSubscriber($counter);

$new = new QuanTri();
$old = $hasOld ? new QuanTriOld() : null;

// --- Số lệnh và thời gian của bản mới ---
$counter->reset();
$t = microtime(true);
$all = $new->listOrders('', '');
$elapsed = microtime(true) - $t;
$listCommands = $counter->count;
$listByCommand = $counter->byCommand;
$totalOrders = (int)$db->hoa_don->countDocuments([]);
echo sprintf("INFO new listOrders('', ''): %d orders, %.2fs, %d commands %s\n", count($all), $elapsed, $listCommands, json_encode($listByCommand));
check('L1', "listOrders('', '') tra du so don = hoa_don.countDocuments", count($all) === $totalOrders, count($all) . ' vs ' . $totalOrders);
check('L2', "listOrders('', '') chay duoi 10 giay", $elapsed < 10.0, sprintf('%.2fs', $elapsed));
check('L3', "listOrders('', '') dung it truy van (<= 10 lenh)", $listCommands <= 10, (string)$listCommands);

$counter->reset();
$t = microtime(true);
$notif = $new->getNotificationCenterData();
$notifElapsed = microtime(true) - $t;
echo sprintf("INFO new getNotificationCenterData(): %.2fs, %d commands %s\n", $notifElapsed, $counter->count, json_encode($counter->byCommand));
check('N1', 'getNotificationCenterData() dung it truy van (<= 16 lenh)', $counter->count <= 16, (string)$counter->count);

// --- Kiểm tra ngẫu nhiên 25 đơn với truy vấn trực tiếp ---
if ($all) {
    $keys = array_rand($all, min(25, count($all)));
    $keys = is_array($keys) ? $keys : [$keys];
    $bad = [];
    foreach ($keys as $k) {
        $o = $all[$k];
        $kh = $db->khach_hang->findOne(['ma_kh' => $o['ma_kh'] ?? null]);
        $lines = $db->chi_tiet_hoa_don->countDocuments(['ma_hoa_don' => $o['ma_hoa_don'] ?? null]);
        $okRow = ($o['so_dong_hang'] ?? null) === $lines && is_int($o['so_dong_hang']);
        if ($kh) {
            $okRow = $okRow && ($o['ho_ten'] ?? null) === $kh['ho_ten']
                && ($o['email'] ?? null) === $kh['email']
                && ($o['so_dien_thoai'] ?? null) === ($kh['so_dien_thoai'] ?? null);
        } else {
            $okRow = $okRow && !array_key_exists('ho_ten', $o);
        }
        if (!$okRow) {
            $bad[] = (string)($o['ma_hoa_don'] ?? '?');
        }
    }
    check('S1', 'spot-check ' . count($keys) . ' don ngau nhien khop truy van truc tiep', $bad === [], $bad ? 'sai: ' . implode(',', $bad) : '');
}

// --- So sánh old vs new ---
if (!$hasOld) {
    echo "SKIP C1-C6 - {$oldFile} khong ton tai, bo qua so sanh old-vs-new\n";
} else {
    $cmp = function (string $id, string $title, callable $oldFn, callable $newFn): void {
        $t0 = microtime(true);
        $a = $oldFn();
        $t1 = microtime(true);
        $b = $newFn();
        $t2 = microtime(true);
        $n = is_array($a['orders'] ?? null) && array_key_exists('pending_orders_count', $a) ? count($a['orders']) : count($a);
        check($id, $title, serialize(sig($a, 2)) === serialize(sig($b, 2)), sprintf('items=%d old=%.1fs new=%.2fs', $n, $t1 - $t0, $t2 - $t1));
    };

    $cmp('C1', 'getNotificationCenterData() old == new', fn() => $old->getNotificationCenterData(), fn() => $new->getNotificationCenterData());
    $cmp('C2', 'getNotificationCenterData(10, 10) old == new', fn() => $old->getNotificationCenterData(10, 10), fn() => $new->getNotificationCenterData(10, 10));

    // Chọn trạng thái có số đơn vừa phải (1..150) để bản cũ chạy được trong thời gian hợp lý.
    $statusPick = null;
    foreach (['pending', 'confirmed', 'shipping', 'completed', 'cancelled'] as $st) {
        $c = count($new->listOrders('', $st));
        echo "INFO status {$st}: {$c} orders\n";
        if ($c >= 1 && $c <= 150 && ($statusPick === null || $c > $statusPick[1])) {
            $statusPick = [$st, $c];
        }
    }
    if ($statusPick !== null) {
        $cmp('C3', "listOrders('', '{$statusPick[0]}') old == new", fn() => $old->listOrders('', $statusPick[0]), fn() => $new->listOrders('', $statusPick[0]));
    } else {
        echo "SKIP C3 - khong co trang thai nao co 1..150 don\n";
    }
    if ($statusPick !== null && $statusPick[0] !== 'pending' && count($new->listOrders('', 'pending')) <= 150) {
        $cmp('C4', "listOrders('', 'pending') old == new", fn() => $old->listOrders('', 'pending'), fn() => $new->listOrders('', 'pending'));
    }

    // Từ khóa trả về một tập con không tầm thường (2..120 đơn): bản mới lọc nhanh để chọn.
    $kwPick = null;
    foreach (['Nguyen', 'Nguyễn', 'Tran', 'Trần', 'Le', 'Lê', 'Pham', 'Phạm', '@gmail', '098', '0912', '77'] as $kw) {
        $c = count($new->listOrders($kw, ''));
        echo "INFO keyword '{$kw}': {$c} orders\n";
        if ($c >= 2 && $c <= 120 && ($kwPick === null || $c > $kwPick[1])) {
            $kwPick = [$kw, $c];
        }
    }
    if ($kwPick !== null) {
        $cmp('C5', "listOrders('{$kwPick[0]}', '') old == new", fn() => $old->listOrders($kwPick[0], ''), fn() => $new->listOrders($kwPick[0], ''));
        $cmp('C6', "listOrders('{$kwPick[0]}', 'completed') old == new", fn() => $old->listOrders($kwPick[0], 'completed'), fn() => $new->listOrders($kwPick[0], 'completed'));
    } else {
        echo "SKIP C5-C6 - khong tim duoc tu khoa phu hop\n";
    }
}

MongoDB\Driver\Monitoring\removeSubscriber($counter);
$total = $passed + $failed;
echo "\nQuanTri N+1 regression tests: {$passed}/{$total} passed, {$failed} failed.\n";
exit($failed === 0 ? 0 : 1);
