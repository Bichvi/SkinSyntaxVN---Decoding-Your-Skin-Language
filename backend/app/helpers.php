<?php
// backend/app/helpers.php

function h($str) {
    return htmlspecialchars($str ?? '', ENT_QUOTES, 'UTF-8');
}

// Tách chuỗi "url1 | url2 | url3" -> lấy url hợp lệ đầu tiên
function first_image_url($raw) {
    if (!$raw) return null;

    $parts = preg_split('/\s*\|\s*/', trim($raw));
    foreach ($parts as $p) {
        $p = trim($p);
        if ($p === '') continue;
        if (filter_var($p, FILTER_VALIDATE_URL)) return $p;
    }
    return null;
}

// Lấy nhiều ảnh cho gallery
function split_image_urls(?string $raw, int $max = 8): array {
    if (!$raw) return [];
    $parts = preg_split('/\s*\|\s*/', trim($raw));
    $urls = [];
    foreach ($parts as $p) {
        $p = trim($p);
        if ($p === '') continue;
        if (!filter_var($p, FILTER_VALIDATE_URL)) continue;
        $urls[] = $p;
        if (count($urls) >= $max) break;
    }
    return $urls;
}

function resolve_image_url(?string $raw): string {
    $raw = trim((string)($raw ?? ''));
    if ($raw === '') {
        return '';
    }

    $remoteUrl = first_image_url($raw);
    if ($remoteUrl !== null) {
        return $remoteUrl;
    }

    $parts = preg_split('/\s*\|\s*/', $raw) ?: [];
    $candidate = trim((string)($parts[0] ?? ''));
    if ($candidate === '') {
        return '';
    }

    if (preg_match('/^https?:\/\//i', $candidate)) {
        return $candidate;
    }

    return BASE_URL . '/uploads/products/' . rawurlencode($candidate);
}

function nl2br_safe(?string $text): string {
    return nl2br(h($text ?? ''));
}

function vnd($value) {
    if ($value === null || $value === '') return '0 đ';
    if (!is_numeric($value)) {
        $num = preg_replace('/[^\d]/', '', (string)$value);
        $value = $num === '' ? 0 : (int)$num;
    }
    return number_format((float)$value, 0, ',', '.') . ' đ';
}

/**
 * Fix lỗi bạn gặp: $_SESSION['flash'] bị set thành string đâu đó -> offset error.
 */
function set_flash($key, $message) {
    if (!isset($_SESSION['flash']) || !is_array($_SESSION['flash'])) {
        $_SESSION['flash'] = [];
    }
    $_SESSION['flash'][$key] = $message;
}

function get_flash($key) {
    if (!isset($_SESSION['flash']) || !is_array($_SESSION['flash'])) return null;
    if (!array_key_exists($key, $_SESSION['flash'])) return null;
    $msg = $_SESSION['flash'][$key];
    unset($_SESSION['flash'][$key]);
    return $msg;
}

function is_logged_in(): bool {
    return !empty($_SESSION['user']);
}

function current_user() {
    return $_SESSION['user'] ?? null;
}

function current_role(): string {
    $user = current_user() ?? [];
    return strtolower(trim((string)($user['role'] ?? $user['vai_tro'] ?? 'guest')));
}

function route_access_map(): array {
    return [
        'admin_dashboard' => ['admin'],
        'admin_sp' => ['admin'],
        'admin_sp_create' => ['admin'],
        'admin_sp_edit' => ['admin'],
        'admin_sp_delete' => ['admin'],
        'admin_categories' => ['admin'],
        'admin_category_save' => ['admin'],
        'admin_category_delete' => ['admin'],
        'admin_users' => ['admin'],
        'admin_customer_save' => ['admin'],
        'admin_customer_delete' => ['admin'],
        'admin_staff_save' => ['admin'],
        'admin_staff_delete' => ['admin'],
        'admin_orders' => ['admin'],
        'admin_order_status' => ['admin'],
        'admin_reports' => ['admin'],
        'staff_dashboard' => ['admin', 'nhanvien'],
        'staff_orders' => ['admin', 'nhanvien'],
        'staff_order_status' => ['admin', 'nhanvien'],
        'staff_products' => ['admin', 'nhanvien'],
        'staff_product_edit' => ['admin', 'nhanvien'],
        'staff_reviews' => ['admin', 'nhanvien'],
        'staff_review_reply' => ['admin', 'nhanvien'],
        'staff_chats' => ['admin', 'nhanvien'],
        'staff_chat_send' => ['admin', 'nhanvien'],
        'lichsuchat' => ['khach_hang'],
        'chat_send' => ['khach_hang'],
        'guidanhgia' => ['khach_hang'],
        'huydonhang' => ['khach_hang'],
    ];
}

function user_can_access_route(string $route, ?array $user = null): bool {
    $user = $user ?? (current_user() ?? []);
    $role = strtolower(trim((string)($user['role'] ?? $user['vai_tro'] ?? 'guest')));
    $map = route_access_map();

    if (!isset($map[$route])) {
        return true;
    }

    return in_array($role, $map[$route], true);
}

function redirect($url) {
    header('Location: ' . $url);
    exit;
}