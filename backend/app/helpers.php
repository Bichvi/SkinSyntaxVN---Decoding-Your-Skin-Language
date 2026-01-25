<?php
// backend/app/helpers.php

function ensure_session(): void {
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }
}

function h($str) {
    return htmlspecialchars($str ?? '', ENT_QUOTES, 'UTF-8');
}

// Tách chuỗi "url1 | url2 | url3" -> lấy url hợp lệ đầu tiên
function first_image_url($raw) {
    if (!$raw) return null;

    $parts = preg_split('/\s*\|\s*/', trim((string)$raw));
    foreach ($parts as $p) {
        $p = trim($p);
        if ($p === '') continue;
        if (filter_var($p, FILTER_VALIDATE_URL)) return $p;
    }
    return null;
}

// Lấy nhiều ảnh cho gallery
function image_urls($raw, $max = 8) {
    if (!$raw) return [];
    $urls = [];

    $parts = preg_split('/\s*\|\s*/', trim((string)$raw));
    foreach ($parts as $p) {
        $p = trim($p);
        if ($p === '') continue;
        if (!filter_var($p, FILTER_VALIDATE_URL)) continue;
        $urls[] = $p;
        if (count($urls) >= (int)$max) break;
    }
    return $urls;
}

// Format tiền VNĐ
function vnd($value) {
    if ($value === null || $value === '') return '0 đ';
    if (!is_numeric($value)) {
        $num = preg_replace('/[^\d]/', '', (string)$value);
        $value = $num === '' ? 0 : (int)$num;
    }
    return number_format((float)$value, 0, ',', '.') . ' đ';
}

// Flash message (FIX: nếu flash bị lỗi kiểu dữ liệu -> reset về array)
function set_flash($key, $message): void {
    ensure_session();
    if (!isset($_SESSION['flash']) || !is_array($_SESSION['flash'])) {
        $_SESSION['flash'] = [];
    }
    $_SESSION['flash'][(string)$key] = (string)$message;
}

function get_flash($key) {
    ensure_session();
    if (!isset($_SESSION['flash']) || !is_array($_SESSION['flash'])) return null;

    $k = (string)$key;
    if (!array_key_exists($k, $_SESSION['flash'])) return null;

    $msg = $_SESSION['flash'][$k];
    unset($_SESSION['flash'][$k]);
    return $msg;
}

// Giữ xuống dòng an toàn
function nl2br_safe(?string $text): string {
    return nl2br(h($text ?? ''));
}
