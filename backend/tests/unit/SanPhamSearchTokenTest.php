<?php
// Test thuần PHP cho tách token + regex không dấu của SanPham. Không kết nối DB.
// Chạy: php backend/tests/unit/SanPhamSearchTokenTest.php
require_once __DIR__ . '/../../app/models/SanPham.php';

$ref = new ReflectionClass('SanPham');
$call = function (string $method, ...$args) use ($ref) {
    $m = $ref->getMethod($method);
    $m->setAccessible(true);
    $obj = $m->isStatic() ? null : $ref->newInstanceWithoutConstructor();
    return $m->invoke($obj, ...$args);
};

$fail = 0;
$pass = 0;
$check = function (string $name, bool $ok) use (&$fail, &$pass) {
    echo ($ok ? 'PASS ' : 'FAIL ') . $name . PHP_EOL;
    $ok ? $pass++ : $fail++;
};

// --- tokenizeSearchQuery
$check('tách + chữ thường', $call('tokenizeSearchQuery', '  Torriden   Sữa Rửa  Mặt ') === ['torriden', 'sữa', 'rửa', 'mặt']);
$check('bỏ stopword', $call('tokenizeSearchQuery', 'serum cho da dầu và mụn') === ['serum', 'da', 'dầu', 'mụn']);
$check('tối đa 6 token', count($call('tokenizeSearchQuery', 'a1 a2 a3 a4 a5 a6 a7 a8')) === 6);
$check('chuỗi rỗng', $call('tokenizeSearchQuery', '   ') === []);
$check('chỉ stopword', $call('tokenizeSearchQuery', 'cho và của') === []);

// --- regex không dấu
$rx = function (string $tok) use ($call) {
    $r = $call('buildAccentInsensitiveRegex', $tok);
    return '/' . $r->getPattern() . '/iu';
};
$matches = fn(string $tok, string $text) => preg_match($rx($tok), $text) === 1;

$check('sua khớp Sữa', $matches('sua', 'Sữa Rửa Mặt Torriden'));
$check('sữa khớp Sua (dữ liệu không dấu)', $matches('sữa', 'Sua rua mat'));
$check('mat khớp Mặt', $matches('mat', 'Sữa Rửa Mặt'));
$check('d khớp đ', $matches('duong', 'Kem Dưỡng Ẩm'));
$check('hoa thường', $matches('TORRIDEN', 'Sữa Rửa Mặt Torriden'));
$check('không khớp sai', !$matches('xyzabc', 'Sữa Rửa Mặt Torriden'));
$check('ký tự đặc biệt được escape', $matches('c+', 'Vitamin C+ serum') && !$matches('c+', 'Vitamin Cc'));
$check('dấu chấm escape', !$matches('b.o', 'bao'));

echo PHP_EOL . "$pass passed, $fail failed" . PHP_EOL;
exit($fail > 0 ? 1 : 0);
