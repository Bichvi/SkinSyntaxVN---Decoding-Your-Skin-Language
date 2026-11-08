<?php
// U11/U13: chứng minh việc phát sự kiện catalog trong HoaDon (emitStockEdge) KHÔNG đổi hành vi hoàn/trừ kho.
// Không chạm DB/Redis thật: Mongo giả (đối tượng trong bộ nhớ) + Redis giả; mỗi kịch bản chạy trong một tiến trình
// PHP riêng (vì class HoaDon/CatalogEvents bị khai báo lại ở sandbox).
//
// So sánh kết quả (lệnh updateOne, thông báo, giá trị trả về, ngoại lệ) của các kịch bản:
//   noop          CatalogEvents.publish không làm gì                      (đường chuẩn)
//   real-ok       CatalogEvents thật + Redis giả nhận XADD
//   real-dead     CatalogEvents thật + Redis từ chối (xadd = null)
//   real-throw    CatalogEvents thật + Redis giả ném RuntimeException
//   real-noredis  CatalogEvents thật + không có Redis (shared = null)
//   stub-throws   CatalogEvents::publish ném RuntimeException ra ngoài (ép try/catch của emitStockEdge hoạt động)
//   stub-parse    file CatalogEvents.php hỏng cú pháp (ParseError là Throwable)
// Kỳ vọng: mọi kịch bản có kết quả giống hệt `noop`; chỉ real-ok mới có sự kiện XADD, và chỉ ở điểm chuyển còn/hết hàng.
//
// Chạy: php backend/tests/unit/HoaDonStockEventTest.php

if (PHP_SAPI !== 'cli') { exit(1); }

// ======================================================================================================== worker
if (isset($argv[1]) && strpos($argv[1], '--scenario=') === 0) {
    $scenario = substr($argv[1], strlen('--scenario='));
    runScenario($scenario);
    exit(0);
}

// ======================================================================================================== orchestrator
$fail = 0;
$pass = 0;
$check = function (string $name, bool $ok, string $detail = '') use (&$fail, &$pass) {
    echo ($ok ? 'PASS ' : 'FAIL ') . $name . ($ok || $detail === '' ? '' : ' :: ' . $detail) . PHP_EOL;
    $ok ? $pass++ : $fail++;
};

$run = function (string $scenario): ?array {
    $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__) . ' --scenario=' . escapeshellarg($scenario);
    $out = shell_exec($cmd . ' 2>&1');
    $out = is_string($out) ? trim($out) : '';
    // Dòng JSON cuối cùng là kết quả (error_log của kịch bản có thể in ra trước).
    $lines = preg_split('/\R/', $out) ?: [];
    $last = end($lines);
    $data = is_string($last) ? json_decode($last, true) : null;
    return is_array($data) ? $data : ['_raw' => $out];
};

$base = $run('noop');
$check('noop: chạy được, có kết quả hoàn/trừ kho', isset($base['result']['restore']) && isset($base['result']['reserve']), json_encode($base));
if (!isset($base['result'])) { echo "ABORT\n"; exit(1); }

$r = $base['result'];
// Sanity của đường chuẩn (logic kho thật sự chạy):
$check('restore: SP hết hàng (0) +2 => 2, con_hang', ($r['restore']['updates'][0]['set']['so_luong_ton_kho'] ?? null) === 2 && ($r['restore']['updates'][0]['set']['trang_thai_kho'] ?? '') === 'con_hang');
$check('restore: SP còn 5 +1 => 6', ($r['restore']['updates'][1]['set']['so_luong_ton_kho'] ?? null) === 6);
$check('restore: SP không tồn tại: vẫn ghi theo before=0', ($r['restore']['updates'][2]['set']['so_luong_ton_kho'] ?? null) === 3);
$check('restore: ghi đúng 3 lần updateOne', count($r['restore']['updates']) === 3);
$check('restore: không ném lỗi', ($r['restore']['exception'] ?? null) === null);
$check('restoreStockForOrder: hoàn kho + đánh dấu hoa_don', ($r['order']['hoa_don_set']['da_hoan_ton_kho'] ?? false) === true && count($r['order']['updates']) === 1);
$check('restoreStockForOrder: đơn đã hoàn trước đó => không làm gì', count($r['order_again']['updates']) === 0 && $r['order_again']['hoa_don_set'] === null);
$check('reserve: trả true, trừ kho 1 -> 0 => het_hang + thông báo', ($r['reserve']['return'] ?? null) === true
    && ($r['reserve']['updates'][0]['set']['trang_thai_kho'] ?? '') === 'het_hang' && count($r['reserve']['notifications']) === 1);
$check('reserve: thiếu hàng => false + lastStockError, không ghi', ($r['reserve_short']['return'] ?? null) === false && count($r['reserve_short']['updates']) === 0);
$check('noop: không có sự kiện nào', ($base['events'] ?? null) === 0);

foreach (['real-ok', 'real-dead', 'real-throw', 'real-noredis', 'stub-throws', 'stub-parse'] as $sc) {
    $o = $run($sc);
    $same = isset($o['result']) && $o['result'] === $base['result'];
    $check("$sc: kết quả hoàn/trừ kho GIỐNG HỆT noop", $same, $same ? '' : json_encode($o));
}

$ok = $run('real-ok');
// real-ok: restore (SP0: 0->2 chuyển sang còn hàng; SP2 không tồn tại before=0 -> 3 cũng chuyển) = 2 sự kiện
//          order (SP0 lại 0->2) = 1; reserve (1->0 hết hàng) = 1. Tổng 4. SP1 (5->6) không phát.
$check('real-ok: chỉ phát ở điểm chuyển còn/hết hàng (4 sự kiện)', ($ok['events'] ?? -1) === 4, json_encode($ok['stream'] ?? null));
$check('real-ok: SP5->6 không phát sự kiện', !in_array('P_STEADY', array_column($ok['stream'] ?? [], 'ma_san_pham'), true));
$check('real-ok: reason restock_order_cancel / sold_out đúng chiều',
    in_array('sold_out', array_column($ok['stream'] ?? [], 'reason'), true) && in_array('restock_order_cancel', array_column($ok['stream'] ?? [], 'reason'), true));
$check('real-ok: type stock_changed, source order', ($ok['stream'][0]['type'] ?? '') === 'stock_changed' && ($ok['stream'][0]['source'] ?? '') === 'order');
foreach (['real-dead', 'real-throw', 'real-noredis', 'stub-throws', 'stub-parse'] as $sc) {
    $o = $run($sc);
    $check("$sc: không có sự kiện nhưng không lỗi", ($o['events'] ?? -1) === 0 && ($o['result']['restore']['exception'] ?? null) === null);
}

echo PHP_EOL . "PASS: $pass, FAIL: $fail" . PHP_EOL;
exit($fail > 0 ? 1 : 0);

// ======================================================================================================== worker code
function runScenario(string $scenario): void
{
    $root = realpath(__DIR__ . '/../../app');
    $events = 0;
    $stream = [];

    // ---- Chọn bộ CatalogEvents / thư mục HoaDon theo kịch bản
    $hoaDonPath = $root . '/models/HoaDon.php';
    $sandbox = null;
    if (in_array($scenario, ['noop', 'stub-throws', 'stub-parse'], true)) {
        $sandbox = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'hoadon_u13_' . getmypid();
        @mkdir($sandbox . '/models', 0777, true);
        @mkdir($sandbox . '/services', 0777, true);
        copy($root . '/models/HoaDon.php', $sandbox . '/models/HoaDon.php');
        $stub = [
            'noop' => "<?php final class CatalogEvents { const TYPE_STOCK = 'stock_changed'; public static function publish(string \$t, \$id, array \$e = []): bool { return true; } }",
            'stub-throws' => "<?php final class CatalogEvents { const TYPE_STOCK = 'stock_changed'; public static function publish(string \$t, \$id, array \$e = []): bool { throw new RuntimeException('publish exploded'); } }",
            'stub-parse' => "<?php final class CatalogEvents { const TYPE_STOCK = 'x'; public static function publish( { ",
        ][$scenario];
        file_put_contents($sandbox . '/services/CatalogEvents.php', $stub);
        require_once $sandbox . '/models/HoaDon.php';
    } else {
        require_once $root . '/services/RedisLite.php';
        require_once $hoaDonPath;
        $redis = null;
        if ($scenario !== 'real-noredis') {
            $redis = new class('redis://127.0.0.1:1') extends RedisLite {
                public array $added = [];
                public string $mode = 'ok';
                public function xadd(string $stream, array $fields, int $maxlen = self::STREAM_MAXLEN): ?string {
                    if ($this->mode === 'throw') { throw new RuntimeException('redis boom'); }
                    if ($this->mode === 'dead') { return null; }
                    $this->added[] = $fields;
                    return '1-0';
                }
            };
            $redis->mode = ['real-ok' => 'ok', 'real-dead' => 'dead', 'real-throw' => 'throw'][$scenario];
        }
        RedisLite::setShared($redis);
    }

    // ---- Mongo giả
    $db = new class {
        public $san_pham; public $hoa_don; public $chi_tiet_hoa_don; public $thong_bao;
        public function __construct() {
            $this->san_pham = new FakeColl([
                ['ma_san_pham' => 'P_OOS', 'ten_san_pham' => 'Het hang', 'so_luong_ton_kho' => 0],
                ['ma_san_pham' => 'P_STEADY', 'ten_san_pham' => 'Con nhieu', 'so_luong_ton_kho' => 5],
                ['ma_san_pham' => 'P_LAST', 'ten_san_pham' => 'Con mot', 'so_luong_ton_kho' => 1],
            ]);
            $this->hoa_don = new FakeColl([['ma_hoa_don' => 77, 'da_tru_ton_kho' => true]]);
            $this->chi_tiet_hoa_don = new FakeColl([['ma_hoa_don' => 77, 'ma_san_pham' => 'P_OOS', 'so_luong' => 2]]);
            $this->thong_bao = new FakeColl([]);
        }
    };
    $hd = new HoaDon($db);
    $call = function (string $method, array $args) use ($hd) {
        $m = new ReflectionMethod($hd, $method);
        $m->setAccessible(true);
        try {
            return ['return' => $m->invokeArgs($hd, $args), 'exception' => null];
        } catch (Throwable $e) {
            return ['return' => null, 'exception' => get_class($e)];
        }
    };
    $snapshot = function () use ($db) {
        $s = [
            'updates' => $db->san_pham->updates,
            'notifications' => $db->thong_bao->inserts,
            'hoa_don_set' => $db->hoa_don->lastSet,
        ];
        $db->san_pham->updates = [];
        $db->thong_bao->inserts = [];
        $db->hoa_don->lastSet = null;
        return $s;
    };

    $result = [];
    // 1) restoreStockForItems (private): hết->còn, còn->còn, SP không tồn tại
    $r = $call('restoreStockForItems', [[
        ['ma_san_pham' => 'P_OOS', 'so_luong' => 2],
        ['ma_san_pham' => 'P_STEADY', 'so_luong' => 1],
        ['ma_san_pham' => 'P_MISSING', 'so_luong' => 3],
    ]]);
    $result['restore'] = $snapshot() + ['exception' => $r['exception']];

    // 2) restoreStockForOrder (public, đường hủy đơn): SP P_OOS đã bị đặt lại về 2 ở bước 1 trong fake -> đưa về 0 trước
    $db->san_pham->docs[0]['so_luong_ton_kho'] = 0;
    $hd->restoreStockForOrder(77);
    $result['order'] = $snapshot();
    // Gọi lần nữa khi hoa_don đã có cờ da_hoan_ton_kho => no-op
    $db->hoa_don->docs[0]['da_hoan_ton_kho'] = true;
    $hd->restoreStockForOrder(77);
    $result['order_again'] = $snapshot();

    // 3) reserveStockForItems (private): 1 -> 0 = hết hàng (+ thông báo), rồi thiếu hàng
    $r = $call('reserveStockForItems', [[['ma_san_pham' => 'P_LAST', 'so_luong' => 1]]]);
    $result['reserve'] = $snapshot() + ['return' => $r['return'], 'exception' => $r['exception']];
    $r = $call('reserveStockForItems', [[['ma_san_pham' => 'P_STEADY', 'so_luong' => 99]]]);
    $result['reserve_short'] = $snapshot() + ['return' => $r['return'], 'exception' => $r['exception']];

    if (isset($redis) && $redis !== null && isset($redis->added)) {
        $stream = $redis->added;
        $events = count($stream);
    }
    if ($sandbox !== null) {
        @unlink($sandbox . '/models/HoaDon.php');
        @unlink($sandbox . '/services/CatalogEvents.php');
        @rmdir($sandbox . '/models');
        @rmdir($sandbox . '/services');
        @rmdir($sandbox);
    }
    echo json_encode(['scenario' => $scenario, 'result' => $result, 'events' => $events, 'stream' => $stream], JSON_UNESCAPED_UNICODE) . PHP_EOL;
}

class FakeColl
{
    public array $docs;
    public array $updates = [];
    public array $inserts = [];
    public ?array $lastSet = null;
    public function __construct(array $docs) { $this->docs = $docs; }

    private function matches(array $doc, array $filter): bool
    {
        if (isset($filter['$or'])) {
            foreach ($filter['$or'] as $cond) {
                foreach ($cond as $k => $v) {
                    if (is_object($v)) { continue; }
                    if (isset($doc[$k]) && (string)$doc[$k] === (string)$v) { return true; }
                }
            }
            return false;
        }
        foreach ($filter as $k => $v) {
            if (!isset($doc[$k]) || (string)$doc[$k] !== (string)$v) { return false; }
        }
        return true;
    }
    public function findOne($filter = [], $opts = [])
    {
        foreach ($this->docs as $doc) {
            if ($this->matches($doc, (array)$filter)) { return $doc; }
        }
        return null;
    }
    public function find($filter = [], $opts = [])
    {
        return new ArrayIterator(array_values(array_filter($this->docs, fn($d) => $this->matches($d, (array)$filter))));
    }
    public function updateOne($filter, $update)
    {
        $set = $update['$set'] ?? [];
        foreach ($set as $k => $v) {
            if (is_object($v)) { $set[$k] = '<utc>'; }
        }
        $this->lastSet = $set;
        foreach ($this->docs as $i => $doc) {
            if ($this->matches($doc, (array)$filter)) {
                // Ghi trực tiếp vào bản ghi giả để các bước sau thấy tồn kho mới (giống Mongo thật)
                foreach ($set as $k => $v) { $this->docs[$i][$k] = $v; }
                break;
            }
        }
        $this->updates[] = ['set' => $set];
    }
    public function insertOne($doc)
    {
        foreach ($doc as $k => $v) { if (is_object($v)) { $doc[$k] = '<utc>'; } }
        $this->inserts[] = $doc;
        $this->docs[] = $doc;
    }
}
