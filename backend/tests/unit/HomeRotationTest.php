<?php
// Test thuần PHP (không DB, không mạng) cho HomeRotation::weightedShuffle.
// Chạy: php backend/tests/unit/HomeRotationTest.php
require_once __DIR__ . '/../../app/services/HomeRotation.php';

$fail = 0;
$pass = 0;
$check = function (string $name, bool $ok) use (&$fail, &$pass) {
    echo ($ok ? 'PASS ' : 'FAIL ') . $name . PHP_EOL;
    $ok ? $pass++ : $fail++;
};

$items = range(1, 10);

// hoán vị hợp lệ
$out = HomeRotation::weightedShuffle($items);
$sorted = $out;
sort($sorted);
$check('hoán vị: cùng tập phần tử', $sorted === $items);
$check('hoán vị: khóa được đánh lại 0..n-1', array_keys($out) === range(0, 9));
$assoc = HomeRotation::weightedShuffle(['a' => 'x', 'b' => 'y', 'c' => 'z']);
$check('khóa chuỗi được đánh lại', array_keys($assoc) === [0, 1, 2]);

// rỗng / một phần tử
$check('rỗng giữ nguyên', HomeRotation::weightedShuffle([]) === []);
$check('một phần tử giữ nguyên', HomeRotation::weightedShuffle([7]) === [7]);

// decay = 1 với rand hằng
$out = HomeRotation::weightedShuffle($items, 1.0, static fn() => 0.5);
$s = $out;
sort($s);
$check('decay=1, rand hằng vẫn là hoán vị', $s === $items && count($out) === 10);

// decay ngoài khoảng được kẹp
$out = HomeRotation::weightedShuffle($items, -5.0, static fn() => 0.5);
$s = $out;
sort($s);
$check('decay âm được kẹp, vẫn là hoán vị', $s === $items);

// xác định với dãy rand cho trước
$seq = [0.9, 0.1, 0.5, 0.7];
$mk = function () use ($seq) {
    $i = 0;
    return static function () use ($seq, &$i) {
        return $seq[$i++ % count($seq)];
    };
};
$a = HomeRotation::weightedShuffle([1, 2, 3, 4], 0.8, $mk());
$b = HomeRotation::weightedShuffle([1, 2, 3, 4], 0.8, $mk());
$check('xác định với rand cho trước', $a === $b);
$check('kết quả với dãy rand cho trước đúng kỳ vọng', $a === [1, 4, 3, 2]);

// thống kê
mt_srand(42);
$runs = 3000;
$firstCount = array_fill(0, 10, 0);
$prev = null;
$diffPairs = 0;
$pairs = 0;
for ($r = 0; $r < $runs; $r++) {
    $o = HomeRotation::weightedShuffle($items);
    $firstCount[$o[0] - 1]++;
    $top4 = array_slice($o, 0, 4);
    if ($prev !== null) {
        $pairs++;
        if ($top4 !== $prev) {
            $diffPairs++;
        }
    }
    $prev = $top4;
}
$check('hạng 0 đứng đầu nhiều hơn hạng 5', $firstCount[0] > $firstCount[5]);
$check('hạng 5 đứng đầu nhiều hơn hạng 9', $firstCount[5] > $firstCount[9]);
$distinct = count(array_filter($firstCount, static fn($c) => $c > 0));
$check('vị trí đầu xoay vòng qua >= 8 SP khác nhau (' . $distinct . ')', $distinct >= 8);
$check('4 vị trí đầu khác nhau giữa >= 90% cặp lần tải liên tiếp (' . round(100 * $diffPairs / $pairs, 1) . '%)', $diffPairs / $pairs >= 0.9);

echo PHP_EOL . "$pass passed, $fail failed" . PHP_EOL;
exit($fail > 0 ? 1 : 0);
