<?php
// Run: php backend/tests/home_recommend_client_test.php  (exit 0 = PASS). Pure: no DB/network/Redis.
declare(strict_types=1);

require_once __DIR__ . '/../app/services/HomeRecommendClient.php';

$failures = 0;
function check(bool $condition, string $label): void
{
    global $failures;
    if ($condition) {
        echo "PASS: {$label}\n";
        return;
    }
    $failures++;
    echo "FAIL: {$label}\n";
}

function memStore(): array
{
    $data = [];
    return [
        'get' => static function (string $k) use (&$data) { return $data[$k] ?? null; },
        'set' => static function (string $k, array $v) use (&$data): void { $data[$k] = $v; },
    ];
}

$validBody = json_encode([
    'degraded' => false,
    'took_ms' => 12,
    'sections' => [
        ['key' => 'seen', 'seed' => ['id' => '5', 'name' => 'Serum A'], 'items' => [
            ['product_id' => '12', 'reason' => ['code' => 'seen_same_category', 'seed_name' => 'Serum A']],
            ['product_id' => 'abc', 'reason' => ['code' => 'seen_same_category']],
        ]],
        ['key' => 'bogus', 'items' => [['product_id' => '1', 'reason' => ['code' => 'skin_fit']]]],
    ],
]);

$mk = static fn(array $res, ?int &$calls = null, ?array &$seenHeaders = null): HomeRecommendClient =>
    new HomeRecommendClient('http://flask/api/home/recommend', 'tok', 800, 300,
        static function (string $url, string $body, array $headers, int $t, int $c) use ($res, &$calls, &$seenHeaders): array {
            $calls = ($calls ?? 0) + 1;
            $seenHeaders = $headers;
            return $res;
        }, memStore());

$payload = ['sections' => ['seen']];

check($mk(['status' => 0, 'body' => '', 'error' => 'timeout'])->recommend($payload) === null, 'transport error yields null');
check($mk(['status' => 500, 'body' => '{}', 'error' => ''])->recommend($payload) === null, '500 yields null');
check($mk(['status' => 200, 'body' => '{bad', 'error' => ''])->recommend($payload) === null, 'malformed JSON yields null');
check($mk(['status' => 200, 'body' => '{"sections":[]}', 'error' => ''])->recommend($payload) === null, 'missing degraded yields null');

$headers = null;
$calls = null;
$ok = $mk(['status' => 200, 'body' => $validBody, 'error' => ''], $calls, $headers)->recommend($payload);
check(is_array($ok) && $ok['degraded'] === false, 'valid response returns array');
check(is_array($ok) && count($ok['sections']) === 1 && $ok['sections'][0]['key'] === 'seen', 'unknown section key dropped');
check(is_array($ok) && count($ok['sections'][0]['items']) === 1 && $ok['sections'][0]['items'][0]['product_id'] === '12', 'item with non-digit product_id dropped');
check(is_array($ok) && $ok['sections'][0]['seed']['name'] === 'Serum A', 'seed carried through');
check(in_array('X-Internal-Token: tok', (array)$headers, true), 'X-Internal-Token header sent');

$calls = 0;
$c = $mk(['status' => 500, 'body' => '', 'error' => ''], $calls);
for ($i = 0; $i < 4; $i++) {
    $c->recommend($payload);
}
check($calls === 3, 'breaker: 4th call skips transport (calls=' . $calls . ')');
check($c->isOpen() === true, 'breaker open after 3 failures');

putenv('HOME_GRAPH_ENABLED=0');
check(HomeRecommendClient::fromEnv() === null, 'fromEnv null when HOME_GRAPH_ENABLED=0');
putenv('HOME_GRAPH_ENABLED');

exit($failures > 0 ? 1 : 0);
