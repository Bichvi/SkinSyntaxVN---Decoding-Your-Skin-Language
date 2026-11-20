<?php
/**
 * Pure unit test, never touches a database.
 *
 * Covers the PhienLive pin / deal-queue / chat / reaction / status logic with
 * in-memory fake collections (a superset of live_logic_test.php's fakes that
 * also supports $push, $pull, $inc, insertOne, deleteOne, countDocuments and
 * sort-aware findOne).
 *
 *   A. ghimSanPham      (single pinned product, validation, deal window, price)
 *   B. themDealSanPham  (ordered queue, unique deal_id, validation, independence)
 *   C. xoaDealSanPham   (removes only the targeted deal)
 *   D. chat             (luuTinNhanChat + getLichSuChat modes/limit/after)
 *   E. recordReaction   (counter increments)
 *   F. status           (doiTrangThai / resolveStatus / ended sessions)
 *   G. taoPhienLive / capNhatPhienLive / xoaPhienLive basics
 *
 * Run: php backend/tests/unit/live_pin_queue_test.php   (exits non-zero on failure)
 */

require_once __DIR__ . '/../../app/models/PhienLive.php';

final class FakeMatcher {
    public static function matches(array $doc, array $filter): bool {
        foreach ($filter as $key => $cond) {
            if ($key === '$or') {
                $ok = false;
                foreach ($cond as $sub) { if (self::matches($doc, $sub)) { $ok = true; break; } }
                if (!$ok) return false;
                continue;
            }
            if ($key === '$and') {
                foreach ($cond as $sub) { if (!self::matches($doc, $sub)) return false; }
                continue;
            }
            $has = array_key_exists($key, $doc);
            $value = $doc[$key] ?? null;
            if (is_array($cond) && self::isOperatorArray($cond)) {
                foreach ($cond as $op => $arg) {
                    switch ($op) {
                        case '$in': if (!in_array($value, $arg, true)) return false; break;
                        case '$gt': if (!$has || !($value > $arg)) return false; break;
                        case '$gte': if (!$has || !($value >= $arg)) return false; break;
                        default: throw new RuntimeException("Unsupported operator $op");
                    }
                }
            } elseif (!$has || $value !== $cond) {
                return false;
            }
        }
        return true;
    }

    private static function isOperatorArray(array $a): bool {
        foreach (array_keys($a) as $k) { if (!is_string($k) || $k === '' || $k[0] !== '$') return false; }
        return $a !== [];
    }
}

final class FakeUpdateResult {
    public function __construct(private int $matched, private int $modified) {}
    public function getMatchedCount(): int { return $this->matched; }
    public function getModifiedCount(): int { return $this->modified; }
}

final class FakeInsertResult {
    public function __construct(private int $n) {}
    public function getInsertedCount(): int { return $this->n; }
}

final class FakeDeleteResult {
    public function __construct(private int $n) {}
    public function getDeletedCount(): int { return $this->n; }
}

final class FakeCollection {
    public array $docs;
    public function __construct(array $docs = []) { $this->docs = $docs; }

    private function sorted(array $rows, array $options): array {
        foreach (($options['sort'] ?? []) as $field => $dir) {
            usort($rows, fn($a, $b) => $dir * (($a[$field] ?? 0) <=> ($b[$field] ?? 0)));
        }
        return $rows;
    }

    public function findOne(array $filter = [], array $options = []) {
        $rows = array_values(array_filter($this->docs, fn($d) => FakeMatcher::matches($d, $filter)));
        $rows = $this->sorted($rows, $options);
        return $rows[0] ?? null;
    }

    public function find(array $filter = [], array $options = []) {
        $rows = array_values(array_filter($this->docs, fn($d) => FakeMatcher::matches($d, $filter)));
        $rows = $this->sorted($rows, $options);
        if (isset($options['limit'])) $rows = array_slice($rows, 0, (int)$options['limit']);
        return new ArrayIterator($rows);
    }

    public function countDocuments(array $filter = []): int {
        return count(array_filter($this->docs, fn($d) => FakeMatcher::matches($d, $filter)));
    }

    public function insertOne(array $doc) {
        $this->docs[] = $doc;
        return new FakeInsertResult(1);
    }

    public function deleteOne(array $filter) {
        foreach ($this->docs as $i => $doc) {
            if (FakeMatcher::matches($doc, $filter)) {
                array_splice($this->docs, $i, 1);
                return new FakeDeleteResult(1);
            }
        }
        return new FakeDeleteResult(0);
    }

    public function updateOne(array $filter, array $update, array $options = []) {
        foreach ($this->docs as $i => $doc) {
            if (!FakeMatcher::matches($doc, $filter)) continue;
            foreach (($update['$set'] ?? []) as $k => $v) $this->docs[$i][$k] = $v;
            foreach (($update['$inc'] ?? []) as $k => $v) $this->docs[$i][$k] = ($this->docs[$i][$k] ?? 0) + $v;
            foreach (($update['$push'] ?? []) as $k => $v) {
                if (!isset($this->docs[$i][$k]) || !is_array($this->docs[$i][$k])) $this->docs[$i][$k] = [];
                $this->docs[$i][$k][] = $v;
            }
            foreach (($update['$pull'] ?? []) as $k => $cond) {
                $kept = [];
                foreach (($this->docs[$i][$k] ?? []) as $item) {
                    if (!(is_array($item) && FakeMatcher::matches($item, $cond))) $kept[] = $item;
                }
                $this->docs[$i][$k] = $kept;
            }
            return new FakeUpdateResult(1, 1);
        }
        return new FakeUpdateResult(0, 0);
    }
}

final class FakeDb {
    public FakeCollection $phien_live;
    public FakeCollection $phien_live_chats;
    public FakeCollection $phien_live_reactions;
    public function __construct() {
        $this->phien_live = new FakeCollection();
        $this->phien_live_chats = new FakeCollection();
        $this->phien_live_reactions = new FakeCollection();
    }
}

$failures = 0;
function check(string $name, bool $ok, string $detail = ''): void {
    global $failures;
    echo ($ok ? 'PASS' : 'FAIL') . '  ' . $name . ($ok || $detail === '' ? '' : "  ($detail)") . "\n";
    if (!$ok) $failures++;
}
function note(string $text): void { echo 'NOTE  ' . $text . "\n"; }
function freshModel(array $liveDocs = []): array {
    $db = new FakeDb();
    $db->phien_live->docs = $liveDocs;
    return [new PhienLive($db), $db];
}
function liveDoc(array $extra = []): array {
    return array_merge(['ma_phong' => 1, 'tieu_de' => 'T', 'streamer' => 'S', 'trang_thai' => 'ready',
        'stream_state' => 'offline', 'stream_last_seen' => 0, 'ma_san_pham_ghim' => '', 'danh_sach_deal' => []], $extra);
}

// ------------------------------------------------------------------ A. ghimSanPham
[$m, $db] = freshModel([liveDoc()]);
check('A1 empty product id is rejected', $m->ghimSanPham(1, '', 50000.0, 15, 5) === false);
check('A2 whitespace-only product id is rejected', $m->ghimSanPham(1, '   ', 50000.0, 15, 5) === false);
check('A3 dealStock=0 is rejected', $m->ghimSanPham(1, 'P1', 50000.0, 15, 0) === false);
check('A4 negative dealStock is rejected', $m->ghimSanPham(1, 'P1', 50000.0, 15, -3) === false);
check('A5 rejected pins leave the doc untouched', ($db->phien_live->docs[0]['ma_san_pham_ghim'] ?? null) === '' && !isset($db->phien_live->docs[0]['so_luong_kho_deal']));
check('A6 pin on unknown session returns false', $m->ghimSanPham(999, 'P1', 1000.0, 15, 5) === false);

$before = time();
check('A7 valid pin returns true', $m->ghimSanPham(1, ' P1 ', 50000.0, 20, 7) === true);
$d = $db->phien_live->docs[0];
check('A8 ma_san_pham_ghim set (trimmed)', $d['ma_san_pham_ghim'] === 'P1', (string)$d['ma_san_pham_ghim']);
check('A9 so_luong_kho_deal set', $d['so_luong_kho_deal'] === 7);
check('A10 gia_uu_dai_live set when > 0', ($d['gia_uu_dai_live'] ?? null) === 50000.0);
$dur = strtotime($d['deal_ket_thuc']) - strtotime($d['deal_bat_dau']);
check('A11 deal window equals requested duration (20 min)', abs($dur - 1200) <= 2, (string)$dur);
check('A12 deal_bat_dau is now', abs(strtotime($d['deal_bat_dau']) - $before) <= 3);

$m->ghimSanPham(1, 'P1', null, 15, 7);
check('A13 null price keeps the previous gia_uu_dai_live', ($db->phien_live->docs[0]['gia_uu_dai_live'] ?? null) === 50000.0);
$m->ghimSanPham(1, 'P1', 0.0, 15, 7);
check('A14 zero price keeps the previous gia_uu_dai_live', ($db->phien_live->docs[0]['gia_uu_dai_live'] ?? null) === 50000.0);
$m->ghimSanPham(1, 'P1', -5.0, 15, 7);
check('A15 negative price keeps the previous gia_uu_dai_live', ($db->phien_live->docs[0]['gia_uu_dai_live'] ?? null) === 50000.0);
$m->ghimSanPham(1, 'P1', 1500.0, 15, 7);
check('A16 positive price overwrites', ($db->phien_live->docs[0]['gia_uu_dai_live'] ?? null) === 1500.0);
$m->ghimSanPham(1, 'P1', 1500.0, 0, 7);
$dur0 = strtotime($db->phien_live->docs[0]['deal_ket_thuc']) - strtotime($db->phien_live->docs[0]['deal_bat_dau']);
check('A17 duration 0 is clamped to at least 1 minute', abs($dur0 - 60) <= 2, (string)$dur0);

// Replace semantic: exactly one pinned product.
[$m, $db] = freshModel([liveDoc(), liveDoc(['ma_phong' => 2])]);
$m->ghimSanPham(1, 'C', 10000.0, 15, 3);
check('A18 pinned = C', $m->findById(1)['ma_san_pham_ghim'] === 'C');
$m->ghimSanPham(1, 'D', 20000.0, 15, 4);
$live = $m->findById(1);
check('A19 pinning D REPLACES C (exactly one pinned product)', $live['ma_san_pham_ghim'] === 'D' && $live['so_luong_kho_deal'] === 4);
check('A20 pinned field is a scalar string, not a list', is_string($live['ma_san_pham_ghim']));
check('A21 pinning in room 1 does not touch room 2', ($m->findById(2)['ma_san_pham_ghim'] ?? 'x') === '');

// ------------------------------------------------------------------ B. themDealSanPham
[$m, $db] = freshModel([liveDoc()]);
check('B1 empty product is rejected', $m->themDealSanPham(1, ['ma_san_pham' => '', 'so_luong_kho_deal' => 5]) === false);
check('B2 missing product key is rejected', $m->themDealSanPham(1, ['so_luong_kho_deal' => 5]) === false);
check('B3 qty 0 is rejected', $m->themDealSanPham(1, ['ma_san_pham' => 'B', 'so_luong_kho_deal' => 0]) === false);
check('B4 qty missing is rejected', $m->themDealSanPham(1, ['ma_san_pham' => 'B']) === false);
check('B5 negative qty is rejected', $m->themDealSanPham(1, ['ma_san_pham' => 'B', 'so_luong_kho_deal' => -2]) === false);
check('B6 rejected deals leave the queue empty', ($m->findById(1)['danh_sach_deal'] ?? null) === []);

check('B7 add deal B', $m->themDealSanPham(1, ['ma_san_pham' => 'B', 'gia_uu_dai_live' => 11000, 'so_luong_kho_deal' => 2]) === true);
check('B8 add deal C', $m->themDealSanPham(1, ['ma_san_pham' => 'C', 'gia_uu_dai_live' => 12000, 'so_luong_kho_deal' => 3]) === true);
check('B9 add deal D', $m->themDealSanPham(1, ['ma_san_pham' => 'D', 'gia_uu_dai_live' => 13000, 'so_luong_kho_deal' => 4]) === true);
$queue = $m->findById(1)['danh_sach_deal'];
check('B10 queue has 3 deals in insertion order', array_column($queue, 'ma_san_pham') === ['B', 'C', 'D'], json_encode(array_column($queue, 'ma_san_pham')));
$ids = array_column($queue, 'deal_id');
check('B11 deal_ids are unique and non-empty', count(array_unique($ids)) === 3 && !in_array('', $ids, true));
check('B12 deal_id has the deal_ prefix', count(array_filter($ids, fn($i) => str_starts_with($i, 'deal_'))) === 3);
check('B13 deal fields stored (price/qty/status)', (float)$queue[1]['gia_uu_dai_live'] === 12000.0 && (int)$queue[1]['so_luong_kho_deal'] === 3 && $queue[1]['trang_thai'] === 'scheduled');
check('B14 product id is trimmed', ($m->themDealSanPham(1, ['ma_san_pham' => '  E  ', 'so_luong_kho_deal' => 1]) && end($db->phien_live->docs[0]['danh_sach_deal'])['ma_san_pham'] === 'E'));
$m->xoaDealSanPham(1, end($db->phien_live->docs[0]['danh_sach_deal'])['deal_id']); // remove E again
check('B15 add deal on unknown session returns false', $m->themDealSanPham(404, ['ma_san_pham' => 'B', 'so_luong_kho_deal' => 1]) === false);
check('B16 same product can be queued twice with distinct deal_ids',
    $m->themDealSanPham(1, ['ma_san_pham' => 'B', 'so_luong_kho_deal' => 1])
    && count(array_unique(array_column($m->findById(1)['danh_sach_deal'], 'deal_id'))) === 4);
$db->phien_live->docs[0]['danh_sach_deal'] = array_slice($db->phien_live->docs[0]['danh_sach_deal'], 0, 3); // back to B,C,D

// queue independent of pin
$m->ghimSanPham(1, 'C', 12000.0, 15, 3);
check('B17 pinning C does not wipe the queue', count($m->findById(1)['danh_sach_deal']) === 3);
$m->ghimSanPham(1, 'D', 13000.0, 15, 4);
$live = $m->findById(1);
check('B18 pinning D: pinned=D and queue still B,C,D', $live['ma_san_pham_ghim'] === 'D' && array_column($live['danh_sach_deal'], 'ma_san_pham') === ['B', 'C', 'D']);
$m->themDealSanPham(1, ['ma_san_pham' => 'A', 'so_luong_kho_deal' => 9]);
check('B19 adding a deal does not change the pinned product', $m->findById(1)['ma_san_pham_ghim'] === 'D');
$m->xoaDealSanPham(1, $m->findById(1)['danh_sach_deal'][3]['deal_id']);

// B-bson: the real driver returns nested arrays as MongoDB\Model\BSONArray (an ArrayObject),
// not as a PHP array. normalizeDoc() must still expose the stored queue.
foreach ([__DIR__ . '/../../../vendor/autoload.php', __DIR__ . '/../../vendor/autoload.php'] as $autoload) {
    if (is_file($autoload)) { require_once $autoload; break; }
}
$bsonClass = class_exists('MongoDB\Model\BSONArray') ? 'MongoDB\Model\BSONArray' : 'ArrayObject';
$storedQueue = new $bsonClass([
    ['deal_id' => 'deal_x1', 'ma_san_pham' => 'B', 'gia_uu_dai_live' => 1000.0, 'so_luong_kho_deal' => 2],
    ['deal_id' => 'deal_x2', 'ma_san_pham' => 'C', 'gia_uu_dai_live' => 2000.0, 'so_luong_kho_deal' => 3],
]);
[$mb, $dbb] = freshModel([liveDoc(['ma_phong' => 77, 'danh_sach_deal' => $storedQueue])]);
$qb = $mb->findById(77)['danh_sach_deal'] ?? null;
check('B20 queue stored as ' . $bsonClass . ' (what the Mongo driver returns) is exposed by findById (PhienLive.php normalizeDoc is_array bug)',
    is_array($qb) && array_column($qb, 'ma_san_pham') === ['B', 'C'], 'got ' . json_encode($qb));

// ------------------------------------------------------------------ C. xoaDealSanPham
$live = $m->findById(1);
$bId = $live['danh_sach_deal'][0]['deal_id'];
check('C1 delete deal B returns true', $m->xoaDealSanPham(1, $bId) === true);
$live = $m->findById(1);
check('C2 only B was removed (queue is C,D in order)', array_column($live['danh_sach_deal'], 'ma_san_pham') === ['C', 'D']);
check('C3 deleting a deal does not change the pin', $live['ma_san_pham_ghim'] === 'D');
$m->xoaDealSanPham(1, 'deal_does_not_exist');
check('C4 unknown deal_id removes nothing', count($m->findById(1)['danh_sach_deal']) === 2);
$m->xoaDealSanPham(1, '');
check('C5 empty deal_id removes nothing', count($m->findById(1)['danh_sach_deal']) === 2);
check('C6 deleting the same deal twice is harmless', $m->xoaDealSanPham(1, $bId) !== null && count($m->findById(1)['danh_sach_deal']) === 2);
$m->xoaDealSanPham(2, $live['danh_sach_deal'][0]['deal_id']);
check('C7 delete against another session id does not touch this queue', count($m->findById(1)['danh_sach_deal']) === 2);

// ------------------------------------------------------------------ D. chat
[$m, $db] = freshModel();
$base = 1700000000000;
$rows = [
    ['m1', 'room1', 'hello',          false, false, false, $base + 1],
    ['m2', 'room1', 'what is this?',  false, false, true,  $base + 2],
    ['m3', 'room1', 'chốt đơn',       false, true,  false, $base + 3],
    ['m4', 'room1', 'pinned note',    false, false, false, $base + 4],
    ['m5', 'room1', 'how to use?',    false, false, true,  $base + 5],
    ['m6', 'room2', 'other room q?',  false, false, true,  $base + 6],
];
foreach ($rows as [$id, $room, $msg, $ai, $order, $q, $ts]) {
    $db->phien_live_chats->docs[] = ['_id' => $id, 'room_id' => $room, 'message' => $msg, 'is_ai' => $ai, 'is_order' => $order,
        'is_question' => $q, 'is_pinned' => $id === 'm4', 'timestamp_ms' => $ts, 'created_at' => '2026-01-01 00:00:00', 'sender_name' => 'u'];
}
$m = new PhienLive($db);
$all = $m->getLichSuChat('room1', 100, 'all');
check('D1 mode=all returns all 5 messages of the room only', count($all) === 5);
check('D2 chronological order', array_column($all, 'message') === ['hello', 'what is this?', 'chốt đơn', 'pinned note', 'how to use?']);
$feat = $m->getLichSuChat('room1', 100, 'featured');
check('D3 mode=featured returns pinned + order messages', array_column($feat, 'message') === ['chốt đơn', 'pinned note'], json_encode(array_column($feat, 'message')));
$qa = $m->getLichSuChat('room1', 100, 'qa');
check('D4 mode=qa returns only questions', array_column($qa, 'message') === ['what is this?', 'how to use?']);
check('D5 unknown mode behaves like all', count($m->getLichSuChat('room1', 100, 'bogus')) === 5);
check('D6 other room isolated', array_column($m->getLichSuChat('room2'), 'message') === ['other room q?']);
check('D7 unknown room returns []', $m->getLichSuChat('nope') === []);
$lim = $m->getLichSuChat('room1', 2, 'all');
check('D8 limit=2 returns the NEWEST two, still ascending', array_column($lim, 'message') === ['pinned note', 'how to use?']);
check('D9 limit<1 is clamped to 1', count($m->getLichSuChat('room1', 0, 'all')) === 1);
check('D10 limit>200 is clamped (no crash, returns all 5)', count($m->getLichSuChat('room1', 5000, 'all')) === 5);
$after = $m->getLichSuChat('room1', 100, 'all', $base + 3);
check('D11 after=ts(m3) returns m4,m5', array_column($after, 'message') === ['pinned note', 'how to use?']);
check('D12 after + featured', array_column($m->getLichSuChat('room1', 100, 'featured', $base + 3), 'message') === ['pinned note']);
check('D13 after + qa', array_column($m->getLichSuChat('room1', 100, 'qa', $base + 2), 'message') === ['how to use?']);
check('D14 items expose flags and ts', $all[2]['is_order'] === true && $all[1]['is_question'] === true && $all[3]['is_pinned'] === true && $all[0]['ts'] === $base + 1);

// round-trip through luuTinNhanChat
[$m, $db] = freshModel();
check('D15 luuTinNhanChat returns true', $m->luuTinNhanChat('r9', '  Alice  ', '  hi there  ', false, false, 'guest', 'guest_1', 'cm-1', false) === true);
usleep(3000);
$m->luuTinNhanChat('r9', 'Bob', 'giá bao nhiêu?', false, false, 'customer', 'u2', 'cm-2', true);
usleep(3000);
$m->luuTinNhanChat('r9', 'Carol', 'chốt đơn', false, true, 'customer', 'u3', 'cm-3', false);
usleep(3000);
$m->luuTinNhanChat('r9', 'Syna', 'reply', true, false, 'ai', 'ai_cohost', '', false, 'cm-2', 'giá bao nhiêu?', 'Bob');
$hist = $m->getLichSuChat('r9');
check('D16 round trip keeps 4 messages in order', array_column($hist, 'message') === ['hi there', 'giá bao nhiêu?', 'chốt đơn', 'reply']);
check('D17 sender name and message are trimmed', $hist[0]['sender_name'] === 'Alice' && $hist[0]['message'] === 'hi there');
check('D18 client_message_id/sender fields stored', $hist[0]['client_message_id'] === 'cm-1' && $hist[0]['sender_id'] === 'guest_1' && $hist[0]['sender_type'] === 'guest');
check('D19 empty sender_type falls back to ai for AI messages', $hist[3]['sender_type'] === 'ai' && $hist[3]['is_ai'] === true);
check('D19b AI reply keeps the original message and sender snapshot', $hist[3]['reply_to_client_message_id'] === 'cm-2' && $hist[3]['reply_to_message'] === 'giá bao nhiêu?' && $hist[3]['reply_to_sender_name'] === 'Bob');
check('D19c chat parent lookup enforces room, owner, and AI flag', $m->findChatMessage('r9', 'cm-2', 'u2', false)['message'] === 'giá bao nhiêu?'
    && $m->findChatMessage('r9', 'cm-2', 'u3', false) === null
    && $m->findChatMessage('r9', 'cm-2', 'u2', true) === null);
check('D20 qa mode via model finds the question', array_column($m->getLichSuChat('r9', 100, 'qa'), 'message') === ['giá bao nhiêu?']);
check('D21 featured mode via model finds the order message', array_column($m->getLichSuChat('r9', 100, 'featured'), 'message') === ['chốt đơn']);
usleep(3000);
$m->luuTinNhanChat('r9', str_repeat('n', 200), str_repeat('x', 1500));
$long = $m->getLichSuChat('r9', 1)[0];
check('D22 message truncated to 1000 chars, sender to 80', mb_strlen($long['message']) === 1000 && mb_strlen($long['sender_name']) === 80);
$tsList = array_column($m->getLichSuChat('r9'), 'ts');
$sorted = $tsList; sort($sorted);
check('D23 timestamps are monotonically non-decreasing', $tsList === $sorted);
// `after` equals newest ts -> nothing
check('D24 after=newest ts returns nothing', $m->getLichSuChat('r9', 100, 'all', end($tsList)) === []);

// Feature-gap probe: nothing in the model can mark a message as pinned, so the
// "Nổi bật" tab only ever shows order messages.
usleep(3000);
$m->luuTinNhanChat('r9', 'Host', 'PINNED ANNOUNCEMENT', false, false, 'streamer', 'h1', 'cm-9', false);
$pinnedViaModel = array_filter($m->getLichSuChat('r9'), fn($x) => $x['is_pinned']);
note('is_pinned is never written by PhienLive::luuTinNhanChat (featured tab = order messages only): pinned messages created=' . count($pinnedViaModel));

// ------------------------------------------------------------------ E. reactions
[$m, $db] = freshModel([liveDoc(['ma_phong' => 5, 'reaction_count' => 0])]);
$c1 = $m->recordReaction('5', 'guest_a');
$c2 = $m->recordReaction('5', 'guest_b');
$c3 = $m->recordReaction('5', 'usr_7');
check('E1 reaction counter increments 1,2,3', [$c1, $c2, $c3] === [1, 2, 3], json_encode([$c1, $c2, $c3]));
check('E2 reaction_count field on the live doc incremented to 3', ($m->findById(5)['reaction_count'] ?? -1) === 3);
$db->phien_live_reactions->docs[] = ['room_id' => '5', 'identity' => 'old', 'created_at' => time() - 7200];
check('E3 reactions older than 1h are not part of the returned recent count', $m->recordReaction('5', 'guest_c') === 4);
check('E4 reactions of another room are not counted', $m->recordReaction('6', 'guest_a') === 1);

// ------------------------------------------------------------------ F. status
[$m, $db] = freshModel([
    liveDoc(['ma_phong' => 'a', 'trang_thai' => 'scheduled']),
    liveDoc(['ma_phong' => 'b', 'trang_thai' => 'ready']),
    liveDoc(['ma_phong' => 'c', 'trang_thai' => 'live', 'stream_state' => 'live', 'stream_last_seen' => time()]),
]);
check('F1 doiTrangThai(danglive) maps to ready (go-live needs real stream)', $m->doiTrangThai('a', 'danglive') && $m->findById('a')['status'] === 'ready');
check('F2 doiTrangThai(live) with no fresh stream resolves to ready', $m->doiTrangThai('a', 'live') && $m->findById('a')['status'] === 'ready');
check('F3 invalid status string falls back to scheduled', $m->doiTrangThai('a', 'garbage') && $m->findById('a')['status'] === 'scheduled');
check('F4 doiTrangThai on unknown id returns false', $m->doiTrangThai('zzz', 'ready') === false);
check('F5 live doc with fresh heartbeat resolves to live', $m->findById('c')['status'] === 'live');
$m->doiTrangThai('c', 'ketthuc');
$c = $m->findById('c');
check('F6 ketthuc -> status ended, stream offline, last_seen cleared', $c['status'] === 'ended' && $c['stream_state'] === 'offline' && $c['stream_last_seen'] === 0);
check('F7 ending marks recording_status=processing', $c['recording_status'] === 'processing');
check('F8 ended session is not live even if a stale heartbeat arrives (touchStream refused)', $m->touchStream('c') === false && $m->findById('c')['status'] === 'ended');
$db->phien_live->docs[2]['stream_state'] = 'live';
$db->phien_live->docs[2]['stream_last_seen'] = time();
check('F9 ended + stale live stream fields still resolves to ended', $m->findById('c')['status'] === 'ended');
$db->phien_live->docs[2]['recording_status'] = 'replay_ready';
$db->phien_live->docs[2]['trang_thai'] = 'replay_ready';
check('F10 replay_ready stays replay_ready', $m->findById('c')['status'] === 'replay_ready');
$m->touchStream('c');
check('F11 touchStream cannot revive a replay_ready session', $m->findById('c')['status'] === 'replay_ready');
check('F12 saveRecording moves ended -> replay_ready', $m->saveRecording('c', '/u/x.webm', 'video/webm', 10) && $m->findById('c')['status'] === 'replay_ready');
check('F13 legacy ketthuc without a recording resolves to ended', (new PhienLive((function () { $d = new FakeDb(); $d->phien_live->docs = [liveDoc(['ma_phong' => 'k', 'trang_thai' => 'ketthuc'])]; return $d; })()))->findById('k')['status'] === 'ended');
$m->updateStreamState('a', 'live');
check('F14 updateStreamState(live) makes the session live', $m->findById('a')['status'] === 'live');
$m->updateStreamState('a', 'ended');
check('F15 updateStreamState(ended) -> ended', $m->findById('a')['status'] === 'ended');
check('F16 updateStreamState with a bogus state is rejected', $m->updateStreamState('a', 'bogus') === false);
// Model-level defense in depth (the controller returns HTTP 409 and the e2e covers it).
$m->updateStreamState('a', 'live');
if ($m->findById('a')['status'] === 'live') {
    note('PhienLive::updateStreamState(live) can revive an ENDED session at model level; only LiveController::apiLiveStreamState (HTTP 409) and adminLiveStatus guard it.');
}

// ------------------------------------------------------------------ G. CRUD basics
[$m, $db] = freshModel();
check('G1 taoPhienLive rejects empty title', $m->taoPhienLive(['tieu_de' => '', 'streamer' => 'S']) === false);
check('G2 taoPhienLive rejects empty streamer', $m->taoPhienLive(['tieu_de' => 'T', 'streamer' => '  ']) === false);
check('G3 taoPhienLive creates a session', $m->taoPhienLive(['tieu_de' => 'E2E', 'streamer' => 'Host', 'ma_san_pham_ghim' => 'A', 'gia_uu_dai_live' => 5000, 'so_luong_kho_deal' => 3]) === true);
$m->taoPhienLive(['tieu_de' => 'E2E 2', 'streamer' => 'Host', 'trang_thai' => 'live']);
check('G4 ma_phong auto-increments', array_column($db->phien_live->docs, 'ma_phong') === [1, 2], json_encode(array_column($db->phien_live->docs, 'ma_phong')));
check('G5 new session starts scheduled; requesting live is downgraded to ready', $m->findById(1)['status'] === 'scheduled' && $m->findById(2)['status'] === 'ready');
check('G6 new session has an empty deal queue and zeroed counters', $m->findById(1)['danh_sach_deal'] === [] && $m->findById(1)['luot_xem'] === 0 && $m->findById(1)['reaction_count'] === 0);
check('G7 capNhatPhienLive edits title and note', $m->capNhatPhienLive(1, ['tieu_de' => ' New ', 'tom_tat_phien_live' => ' note ']) && $m->findById(1)['tieu_de'] === 'New' && $m->findById(1)['tom_tat_phien_live'] === 'note');
check('G8 capNhatPhienLive with no allowed fields returns false', $m->capNhatPhienLive(1, ['nonsense' => 1]) === false);
check('G9 capNhatPhienLive cannot overwrite the deal queue', $m->capNhatPhienLive(1, ['danh_sach_deal' => 'x']) === false);
check('G10 xoaPhienLive removes the session', $m->xoaPhienLive(2) === true && $m->findById(2) === null);
check('G11 xoaPhienLive on a missing id returns false', $m->xoaPhienLive(2) === false);
check('G12 tangLuotXem increments luot_xem', $m->tangLuotXem(1) && $m->tangLuotXem(1) && $m->findById(1)['luot_xem'] === 2);

echo $failures === 0 ? "\nALL PASSED\n" : "\n$failures FAILED\n";
exit($failures === 0 ? 0 : 1);
