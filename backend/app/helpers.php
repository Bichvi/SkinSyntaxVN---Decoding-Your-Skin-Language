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
function redirect($url) {
    header('Location: ' . $url);
    exit;
}