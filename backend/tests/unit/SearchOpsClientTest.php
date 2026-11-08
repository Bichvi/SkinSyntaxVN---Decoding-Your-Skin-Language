<?php
// Test thuần PHP (transport giả, không mạng) cho SearchRecommendClient::opsHealth() và trang admin_search_ops (U11).
// Chạy: php backend/tests/unit/SearchOpsClientTest.php
require_once __DIR__ . '/../../app/services/SearchRecommendClient.php';

$fail = 0;
$pass = 0;
$check = function (string $name, bool $ok) use (&$fail, &$pass) {
    echo ($ok ? 'PASS ' : 'FAIL ') . $name . PHP_EOL;
    $ok ? $pass++ : $fail++;
};

$calls = [];
$make = function (int $status, string $body) use (&$calls): SearchRecommendClient {
    return new SearchRecommendClient('http://ai-service:5001/api/search/recommend', 'tok-secret', 1200, 300,
        function (string $url, string $b, array $headers, int $timeoutMs, int $connectMs, string $method = 'POST') use (&$calls, $status, $body): array {
            $calls[] = [$url, $headers, $timeoutMs, $method];
            return ['status' => $status, 'body' => $body, 'error' => ''];
        });
};

$ok = $make(200, json_encode(['ok' => true, 'alerts' => [], 'neo4j' => ['ok' => true, 'count' => 2853], 'secret_extra' => 'x', 'jobs' => ['graph_sync_full' => null]]));
$h = $ok->opsHealth();
$check('opsHealth: gọi GET /api/ops/health với token nội bộ', $calls[0][0] === 'http://ai-service:5001/api/ops/health'
    && $calls[0][3] === 'GET' && in_array('X-Internal-Token: tok-secret', $calls[0][1], true) && $calls[0][2] === 4000);
$check('opsHealth: chỉ giữ khóa đã biết', is_array($h) && $h['neo4j']['count'] === 2853 && !isset($h['secret_extra']) && array_key_exists('jobs', $h));
$check('opsHealth: HTTP 401/503 => null', $make(401, '{"error":"unauthorized"}')->opsHealth() === null && $make(503, '{}')->opsHealth() === null);
$check('opsHealth: JSON hỏng hoặc thiếu alerts => null', $make(200, 'not json')->opsHealth() === null && $make(200, '{"ok":true}')->opsHealth() === null);

// View: escape mọi chuỗi từ health (XSS qua message cảnh báo / lỗi job)
function h($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
$health = [
    'ok' => false, 'generated_at' => '2026-10-23T00:00:00+00:00',
    'alerts' => [['code' => 'job_failed_repeatedly', 'level' => 'critical', 'message' => '<script>alert(1)</script> Job x']],
    'neo4j' => ['ok' => true, 'count' => 2853, 'mem_pct' => 82.4, 'mem_used_mb' => 843.9, 'mem_limit_mb' => 1024.0],
    'chroma' => ['ok' => true, 'count' => 2853], 'mongo' => ['ok' => true, 'neo4j_expected' => 2853, 'chroma_expected' => 2853, 'total_products' => 2853],
    'redis' => ['ok' => true, 'used_memory_mb' => 14.8, 'catalog_stream' => ['length' => 4, 'pending' => 0, 'oldest_pending_idle_s' => 0, 'dead' => 0]],
    'drift' => ['neo4j_pct' => 0.0, 'chroma_pct' => 0.0],
    'jobs' => ['graph_sync_full' => ['status' => 'fail', 'finished_at' => 1700000000, 'duration_s' => 3.2, 'consecutive_failures' => 2, 'error' => '<img src=x onerror=alert(1)>']],
];
ob_start();
include __DIR__ . '/../../../frontend/views/admin/search_ops.php';
$html = ob_get_clean();
$check('view: cảnh báo hiển thị, HTML trong message bị escape', strpos($html, 'data-alert="job_failed_repeatedly"') !== false
    && strpos($html, '<script>alert(1)</script>') === false && strpos($html, '&lt;script&gt;') !== false);
$check('view: lỗi job bị escape', strpos($html, '<img src=x') === false && strpos($html, '&lt;img src=x') !== false);
$check('view: số liệu Neo4j/Chroma/Redis và job chưa chạy', strpos($html, '2.853') !== false && strpos($html, '82,4') !== false
    && strpos($html, 'data-job="co_purchase"') !== false && strpos($html, 'chưa chạy') !== false);
$health = null;
ob_start();
include __DIR__ . '/../../../frontend/views/admin/search_ops.php';
$html = ob_get_clean();
$check('view: Flask không trả lời => thông báo, không lỗi', strpos($html, 'data-testid="ops-unavailable"') !== false);

echo PHP_EOL . "Kết quả: $pass pass, $fail fail" . PHP_EOL;
exit($fail > 0 ? 1 : 0);
