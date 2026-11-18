<?php
/**
 * Pure unit test, never touches a database.
 *
 * Covers PhienLive logic with in-memory fake collections:
 *   a) resolveStatus freshness window (via findById/normalizeDoc)
 *   b) touchStream heartbeat cannot resurrect an ended session
 *   c) getLichSuChat returns the latest N chronologically, and supports `after`
 *
 * Run: php backend/tests/unit/live_logic_test.php   (exits non-zero on failure)
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
                        case '$ne': if ($has && $value === $arg) return false; break;
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

final class FakeCollection {
    public array $docs;
    public function __construct(array $docs = []) { $this->docs = $docs; }

    public function findOne(array $filter = [], array $options = []) {
        foreach ($this->docs as $doc) { if (FakeMatcher::matches($doc, $filter)) return $doc; }
        return null;
    }

    public function find(array $filter = [], array $options = []) {
        $rows = array_values(array_filter($this->docs, fn($d) => FakeMatcher::matches($d, $filter)));
        usort($rows, static function ($a, $b) use ($options) {
            foreach (($options['sort'] ?? []) as $field => $dir) {
                $order = $dir * (($a[$field] ?? 0) <=> ($b[$field] ?? 0));
                if ($order !== 0) return $order;
            }
            return 0;
        });
        if (isset($options['limit'])) $rows = array_slice($rows, 0, (int)$options['limit']);
        return new ArrayIterator($rows);
    }

    public function updateOne(array $filter, array $update, array $options = []) {
        foreach ($this->docs as $i => $doc) {
            if (FakeMatcher::matches($doc, $filter)) {
                foreach (($update['$set'] ?? []) as $k => $v) $this->docs[$i][$k] = $v;
                return new FakeUpdateResult(1, 1);
            }
        }
        return new FakeUpdateResult(0, 0);
    }
}

final class FakeDb {
    public FakeCollection $phien_live;
    public FakeCollection $phien_live_chats;
    public function __construct() {
        $this->phien_live = new FakeCollection();
        $this->phien_live_chats = new FakeCollection();
    }
}

$failures = 0;
function check(string $name, bool $ok, string $detail = ''): void {
    global $failures;
    echo ($ok ? 'PASS' : 'FAIL') . '  ' . $name . ($ok || $detail === '' ? '' : "  ($detail)") . "\n";
    if (!$ok) $failures++;
}

// a) resolveStatus freshness window
$db = new FakeDb();
$db->phien_live->docs = [
    ['ma_phong' => 'r50', 'trang_thai' => 'live', 'stream_state' => 'live', 'stream_last_seen' => time() - 50],
    ['ma_phong' => 'r70', 'trang_thai' => 'live', 'stream_state' => 'live', 'stream_last_seen' => time() - 70],
];
$model = new PhienLive($db);
check('a1 live, last_seen 50s ago -> live', ($model->findById('r50')['status'] ?? '') === 'live');
check('a2 live, last_seen 70s ago -> ready', ($model->findById('r70')['status'] ?? '') === 'ready');
check('a3 freshness constant is 60', PhienLive::STREAM_FRESH_SECONDS === 60);

// b) touchStream
$db = new FakeDb();
$db->phien_live->docs = [
    ['ma_phong' => 'ended1', 'trang_thai' => 'ended', 'stream_state' => 'offline', 'stream_last_seen' => 1],
    ['ma_phong' => 'live1', 'trang_thai' => 'live', 'stream_state' => 'live', 'stream_last_seen' => 1],
];
$model = new PhienLive($db);
check('b1 touchStream on ended returns false', $model->touchStream('ended1') === false);
check('b2 ended doc is unchanged', $db->phien_live->docs[0]['trang_thai'] === 'ended'
    && $db->phien_live->docs[0]['stream_state'] === 'offline' && $db->phien_live->docs[0]['stream_last_seen'] === 1);
check('b3 touchStream on live returns true', $model->touchStream('live1') === true);
check('b4 live doc stream_last_seen refreshed', $db->phien_live->docs[1]['stream_last_seen'] >= time() - 2);
check('b5 touchStream on unknown id returns false', $model->touchStream('nope') === false);

// c) getLichSuChat
$db = new FakeDb();
$base = 1700000000000;
for ($i = 1; $i <= 150; $i++) {
    $db->phien_live_chats->docs[] = [
        '_id' => 'm' . $i, 'room_id' => 'room1', 'message' => 'msg ' . $i, 'timestamp_ms' => $base + $i,
        'created_at' => '2026-01-01 00:00:00', 'sender_name' => 'u',
    ];
}
$db->phien_live_chats->docs[] = ['_id' => 'other', 'room_id' => 'room2', 'message' => 'x', 'timestamp_ms' => $base + 999];
$model = new PhienLive($db);
$latest = $model->getLichSuChat('room1', 100, 'all', 0);
check('c1 returns 100 items', count($latest) === 100, (string)count($latest));
check('c2 last item is newest (msg 150)', ($latest[99]['message'] ?? '') === 'msg 150');
check('c3 first item is msg 51 (latest 100 window)', ($latest[0]['message'] ?? '') === 'msg 51');
$chronological = true;
for ($i = 1; $i < count($latest); $i++) { if ($latest[$i]['ts'] <= $latest[$i - 1]['ts']) $chronological = false; }
check('c4 chronological ascending order', $chronological);
check('c5 items carry ts', ($latest[99]['ts'] ?? 0) === $base + 150);
$newer = $model->getLichSuChat('room1', 100, 'all', $base + 140);
check('c6 after=ts(140) returns 10 newer items', count($newer) === 10, (string)count($newer));
check('c7 after window starts at 141 and ends at 150', ($newer[0]['message'] ?? '') === 'msg 141' && ($newer[9]['message'] ?? '') === 'msg 150');
check('c8 after newest returns nothing', $model->getLichSuChat('room1', 100, 'all', $base + 150) === []);

echo $failures === 0 ? "\nALL PASSED\n" : "\n$failures FAILED\n";
exit($failures === 0 ? 0 : 1);
