<?php

require_once __DIR__ . '/../../app/services/LiveKitEgressService.php';

$failures = 0;
$check = static function (string $name, bool $ok) use (&$failures): void {
    echo ($ok ? "PASS" : "FAIL") . " $name\n";
    if (!$ok) $failures++;
};
$encode = static fn(string $value): string => rtrim(strtr(base64_encode($value), '+/', '-_'), '=');

$body = json_encode(['event' => 'egress_ended', 'egressInfo' => ['egress_id' => 'EG_test', 'status' => 'EGRESS_COMPLETE']], JSON_UNESCAPED_SLASHES);
$key = 'test-key';
$secret = 'test-secret';
$header = $encode(json_encode(['alg' => 'HS256', 'typ' => 'JWT']));
$payload = $encode(json_encode([
    'iss' => $key,
    'sub' => 'webhook-test',
    'exp' => time() + 60,
    'sha256' => hash('sha256', $body),
]));
$token = $header . '.' . $payload . '.' . $encode(hash_hmac('sha256', $header . '.' . $payload, $secret, true));
$verified = LiveKitEgressService::verifyWebhookAuthorization($body, 'Bearer ' . $token, $key, $secret);
$check('valid webhook signature and raw-body hash accepted', is_array($verified) && ($verified['iss'] ?? '') === $key);
$check('changed webhook body rejected', LiveKitEgressService::verifyWebhookAuthorization($body . ' ', 'Bearer ' . $token, $key, $secret) === null);
$check('changed webhook signature rejected', LiveKitEgressService::verifyWebhookAuthorization($body, 'Bearer ' . $token . 'x', $key, $secret) === null);

$service = new LiveKitEgressService();
$check('numeric Egress complete status normalized', $service->normalizeEgressStatus(['status' => 3]) === 'EGRESS_COMPLETE');
$check('numeric Egress failed status normalized', $service->normalizeEgressStatus(['status' => 4]) === 'EGRESS_FAILED');
$meta = $service->egressFileMetadata([
    'egress_id' => 'EG_test',
    'file_results' => [[
        'filename' => 'live-recordings/live_8_test.mp4',
        'duration' => 2500000000,
        'size' => 12345,
        'location' => 's3://private/live-recordings/live_8_test.mp4',
    ]],
]);
$check('file metadata extracts path, duration seconds and size',
    $meta['file_path'] === 'live-recordings/live_8_test.mp4' && $meta['duration'] === 3 && $meta['size'] === 12345);

echo $failures === 0 ? "ALL PASSED\n" : $failures . " FAILED\n";
exit($failures === 0 ? 0 : 1);
