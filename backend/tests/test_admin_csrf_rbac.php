<?php
// Test CSRF and RBAC protection on admin_ai_idol_publish_video
require_once __DIR__ . '/../app/config/db.php';
require_once __DIR__ . '/../app/controllers/AdminAiIdolController.php';

session_start();
$_SESSION['admin_ai_idol_csrf'] = 'valid_secret_csrf_token_test_123';
$_SESSION['user'] = [
    'id' => 'staff-1',
    'role' => 'admin',
    'email' => 'admin@skinsyntax.vn'
];
$sessId = session_id();
session_write_close();
@chown("/tmp/sess_{$sessId}", 'www-data');
@chmod("/tmp/sess_{$sessId}", 0666);

echo "=== RBAC & CSRF SECURITY VERIFICATION ===\n";

// 1. Invalid CSRF test
$ch = curl_init("http://skinsyntax-nginx/index.php?r=admin_ai_idol_publish_video");
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => json_encode(['video_id' => '6acc27e526e9d8acf59c457e', 'csrf' => 'wrong_token']),
    CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Cookie: PHPSESSID=' . session_id()]
]);
$res1 = curl_exec($ch);
$code1 = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);
echo "1. Invalid CSRF token: HTTP Code = $code1 (Expected 403)\n";
echo "   Response: $res1\n";

// 2. Missing CSRF test
$ch = curl_init("http://skinsyntax-nginx/index.php?r=admin_ai_idol_publish_video");
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => json_encode(['video_id' => '6acc27e526e9d8acf59c457e']),
    CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Cookie: PHPSESSID=' . session_id()]
]);
$res2 = curl_exec($ch);
$code2 = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);
echo "2. Missing CSRF token: HTTP Code = $code2 (Expected 403)\n";
echo "   Response: $res2\n";

// 3. Unauthenticated guest test (No session cookie)
$ch = curl_init("http://skinsyntax-nginx/index.php?r=admin_ai_idol_publish_video");
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => json_encode(['video_id' => '6acc27e526e9d8acf59c457e', 'csrf' => 'any']),
    CURLOPT_HTTPHEADER => ['Content-Type: application/json']
]);
$res3 = curl_exec($ch);
$code3 = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);
echo "3. Unauthenticated Guest: HTTP Code = $code3 (Expected 302 or 403)\n";

// 4. Valid CSRF test
$ch = curl_init("http://skinsyntax-nginx/index.php?r=admin_ai_idol_publish_video");
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => json_encode([
        'video_id' => '6acc27e526e9d8acf59c457e',
        'associated_product_ids' => ['6376'],
        'primary_product_id' => '6376',
        'title' => "Syna 3D - Giới thiệu Combo Nước Tẩy Trang L'Oreal",
        'display_priority' => 10,
        'csrf' => 'valid_secret_csrf_token_test_123'
    ]),
    CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Cookie: PHPSESSID=' . session_id()]
]);
$res4 = curl_exec($ch);
$code4 = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);
echo "4. Valid CSRF & Admin Session: HTTP Code = $code4 (Expected 200)\n";
echo "   Response: $res4\n";

// 5. Toggle publish invalid CSRF test
$ch = curl_init("http://skinsyntax-nginx/index.php?r=admin_ai_idol_toggle_publish");
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => json_encode(['publication_id' => '6acc6bd54e1a7ffa0e0672b2', 'is_published' => false, 'csrf' => 'bad_csrf']),
    CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Cookie: PHPSESSID=' . $sessId]
]);
$res5 = curl_exec($ch);
$code5 = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);
echo "5. Toggle publish Invalid CSRF: HTTP Code = $code5 (Expected 403)\n";

// 6. Toggle publish Valid CSRF test
$ch = curl_init("http://skinsyntax-nginx/index.php?r=admin_ai_idol_toggle_publish");
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => json_encode(['publication_id' => '6acc6bd54e1a7ffa0e0672b2', 'is_published' => true, 'csrf' => 'valid_secret_csrf_token_test_123']),
    CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Cookie: PHPSESSID=' . $sessId]
]);
$res6 = curl_exec($ch);
$code6 = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);
echo "6. Toggle publish Valid CSRF: HTTP Code = $code6 (Expected 200)\n";
echo "   Response: $res6\n";

