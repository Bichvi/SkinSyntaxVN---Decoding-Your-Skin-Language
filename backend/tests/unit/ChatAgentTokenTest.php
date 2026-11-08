<?php
declare(strict_types=1);

require_once __DIR__ . '/../../app/services/ChatAgentToken.php';

$fail = 0;
$pass = 0;
$check = function (string $name, bool $ok) use (&$fail, &$pass): void {
    echo ($ok ? 'PASS ' : 'FAIL ') . $name . PHP_EOL;
    $ok ? $pass++ : $fail++;
};

$throws = function (callable $fn, int $code): bool {
    try {
        $fn();
        return false;
    } catch (DomainException $e) {
        return $e->getCode() === $code;
    }
};

$secret = 'test-secret-0123456789';
$t = ChatAgentToken::issue(
    ['sub' => '42', 'em' => 'a@b.c', 'sid' => '', 'scope' => ['chat', 'order:read']],
    $secret,
    300,
    1_800_000_000
);
$c = ChatAgentToken::verify($t, $secret, 1_800_000_100);
$check('roundtrip giữ claims', $c['sub'] === '42' && $c['exp'] === 1_800_000_300 && strlen($c['jti']) === 32);
$check('hết hạn bị từ chối', $throws(fn() => ChatAgentToken::verify($t, $secret, 1_800_000_301), 401));
$check('sai chữ ký bị từ chối', $throws(fn() => ChatAgentToken::verify($t . 'x', $secret, 1_800_000_100), 401));
$check('secret rỗng bị từ chối', $throws(fn() => ChatAgentToken::verify($t, '', 1_800_000_100), 401));
$check('hasScope', ChatAgentToken::hasScope($c, 'order:read') && !ChatAgentToken::hasScope($c, 'cart:write'));

// Vector dùng chung với Flask (Task 2): in ra để chép sang test Python.
$fixed = ChatAgentToken::issue(
    ['sub' => '7', 'em' => 'x@y.z', 'sid' => 'c1', 'scope' => ['chat'], 'exp' => 1_900_000_000, 'jti' => str_repeat('a', 32)],
    'vector-secret'
);
$check('vector cố định', $fixed === 'eyJzdWIiOiI3IiwiZW0iOiJ4QHkueiIsInNpZCI6ImMxIiwic2NvcGUiOlsiY2hhdCJdLCJleHAiOjE5MDAwMDAwMDAsImp0aSI6ImFhYWFhYWFhYWFhYWFhYWFhYWFhYWFhYWFhYWFhYWFhIn0.ZO3zgrq5otSKzQ2ViudDSqFTFmf3bgel-rRuShpAUOI');
echo "VECTOR {$fixed}" . PHP_EOL;

echo PHP_EOL . "{$pass} passed, {$fail} failed" . PHP_EOL;
exit($fail ? 1 : 0);
