<?php
// Test HTTP regression via internal Nginx in Docker network or local web server

$baseUrl = getenv('TEST_BASE_URL') ?: 'http://nginx';

$routes = [
    '/index.php?r=home' => 200,
    '/index.php?r=admin_categories' => 302, // Expect 302 redirect to admin login when unauthenticated
    '/index.php?r=tatca' => 200,
    '/index.php?r=tatca&ma_danh_muc=1' => 200,
    '/index.php?r=tatca&ma_danh_muc=2002' => 200,
];

require_once __DIR__ . '/../app/config/db.php';
global $db;
$sp = $db->san_pham->findOne([]);
if ($sp && isset($sp['_id'])) {
    $routes['/index.php?r=chitiet&id=' . (string)$sp['_id']] = 200;
}

echo "=== HTTP ENDPOINT REGRESSION TEST ===" . PHP_EOL;
echo "Base URL: " . $baseUrl . PHP_EOL;

$allPass = true;
foreach ($routes as $path => $expectedStatus) {
    $fullUrl = $baseUrl . $path;
    $ctx = stream_context_create([
        'http' => [
            'follow_location' => 0,
            'timeout' => 8,
            'ignore_errors' => true
        ]
    ]);
    
    $fp = @fopen($fullUrl, 'r', false, $ctx);
    $headers = $http_response_header ?? [];
    $statusLine = $headers[0] ?? '';
    preg_match('/HTTP\/\S+\s+(\d+)/', $statusLine, $matches);
    $statusCode = isset($matches[1]) ? (int)$matches[1] : 0;
    
    $location = '';
    foreach ($headers as $h) {
        if (stripos($h, 'Location:') === 0) {
            $location = trim(substr($h, 9));
            break;
        }
    }
    
    $pass = ($statusCode === $expectedStatus);
    if (!$pass) $allPass = false;
    
    echo sprintf("[%s] %s -> Status: %d (Expected: %d)%s\n",
        $pass ? 'PASS' : 'FAIL',
        $path,
        $statusCode,
        $expectedStatus,
        $location ? ' -> Redirect: ' . $location : ''
    );
}

if ($allPass) {
    echo ">>> ALL HTTP REGRESSION CHECKS PASSED SUCCESSFULLY!" . PHP_EOL;
    exit(0);
} else {
    echo ">>> SOME CHECKS FAILED!" . PHP_EOL;
    exit(1);
}
