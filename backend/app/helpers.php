<?php
// backend/app/helpers.php

function fixMojibake($text) {
    if (!is_string($text) || $text === '') {
        return $text;
    }

    static $generatedMap = null;
    if ($generatedMap === null) {
        $generatedMap = [];
        $chars = preg_split('//u', 'ÀÁẢÃẠÂẦẤẨẪẬĂẰẮẲẴẶÈÉẺẼẸÊỀẾỂỄỆÌÍỈĨỊÒÓỎÕỌÔỒỐỔỖỘƠỜỚỞỠỢÙÚỦŨỤƯỪỨỬỮỰỲÝỶỸỴĐàáảãạâầấẩẫậăằắẳẵặèéẻẽẹêềếểễệìíỉĩịòóỏõọôồốổỗộơờớởỡợùúủũụưừứửữựỳýỷỹỵđ·©«»–—‘’“”', -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $toLatin1Text = static function (string $value): string {
            $windows1252 = [
                0x80 => '€', 0x82 => '‚', 0x83 => 'ƒ', 0x84 => '„', 0x85 => '…',
                0x86 => '†', 0x87 => '‡', 0x88 => 'ˆ', 0x89 => '‰', 0x8A => 'Š',
                0x8B => '‹', 0x8C => 'Œ', 0x8E => 'Ž', 0x91 => '‘', 0x92 => '’',
                0x93 => '“', 0x94 => '”', 0x95 => '•', 0x96 => '–', 0x97 => '—',
                0x98 => '˜', 0x99 => '™', 0x9A => 'š', 0x9B => '›', 0x9C => 'œ',
                0x9E => 'ž', 0x9F => 'Ÿ',
            ];
            $bytes = unpack('C*', $value) ?: [];
            $out = '';
            foreach ($bytes as $byte) {
                if (isset($windows1252[(int)$byte])) {
                    $out .= $windows1252[(int)$byte];
                    continue;
                }
                $out .= function_exists('mb_chr') ? mb_chr((int)$byte, 'UTF-8') : html_entity_decode('&#' . (int)$byte . ';', ENT_NOQUOTES, 'UTF-8');
            }
            return $out;
        };
        foreach ($chars as $char) {
            $once = $toLatin1Text($char);
            $twice = $toLatin1Text($once);
            $third = $toLatin1Text($twice);
            $fourth = $toLatin1Text($third);
            foreach ([$fourth, $third, $twice, $once] as $variant) {
                $generatedMap[$variant] = $char;
                // Some files were partly repaired before, leaving mixed fragments such as "ệ".
                // Add those reduced variants too so code text can be normalized without touching DB data.
                $reduced = str_replace(
                    [
                        "\xC3\x83\xC2\xA1\xC3\x82\xC2\xBB",
                        "\xC3\x83\xC2\xA1\xC3\x82\xC2\xBA",
                        "\xC3\x83\xE2\x82\xAC\xC5\xBE",
                        "\xC3\x83\xE2\x80\xA0",
                        "\xC3\x83\xE2\x80\xA6",
                    ],
                    ['á»', 'áº', 'Ä', 'Æ', 'Å'],
                    $variant
                );
                $generatedMap[$reduced] = $char;
            }
        }
        uksort($generatedMap, static fn($a, $b) => strlen($b) <=> strlen($a));
    }

    return strtr($text, $generatedMap);
}

function h($str) {
    return htmlspecialchars(fixMojibake((string)($str ?? '')), ENT_QUOTES, 'UTF-8');
}

function product_stock_quantity(array $product): ?int {
    foreach (['so_luong_ton_kho', 'ton_kho_hien_thi', 'so_luong_ton', 'ton_kho', 'stock', 'quantity'] as $field) {
        if (array_key_exists($field, $product) && $product[$field] !== null && $product[$field] !== '') {
            return max(0, (int)$product[$field]);
        }
    }
    return null;
}

function product_is_out_of_stock(array $product): bool {
    $status = strtolower(trim((string)($product['trang_thai_kho'] ?? '')));
    $stock = product_stock_quantity($product);
    return $status === 'het_hang' || ($stock !== null && $stock <= 0);
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
        $resolved = resolve_image_url($p);
        if ($resolved === '') continue;
        $urls[] = $resolved;
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

    $normalized = str_replace('\\', '/', $candidate);
    $normalized = preg_replace('#^\./+#', '', $normalized) ?: $normalized;

    if (strpos($normalized, 'backend/public/uploads/products/') !== false) {
        $normalized = substr($normalized, strpos($normalized, 'backend/public/uploads/products/') + strlen('backend/public/uploads/products/'));
    }

    if (strpos($normalized, 'uploads/products/') !== false) {
        $normalized = substr($normalized, strpos($normalized, 'uploads/products/') + strlen('uploads/products/'));
    }

    if (strpos($normalized, 'backend/public/uploads/reviews/') !== false) {
        $normalized = substr($normalized, strpos($normalized, 'backend/public/') + strlen('backend/public/'));
        return BASE_URL . '/' . str_replace('%2F', '/', rawurlencode(ltrim($normalized, '/')));
    }

    if (strpos($normalized, 'uploads/reviews/') === 0) {
        return BASE_URL . '/' . str_replace('%2F', '/', rawurlencode(ltrim($normalized, '/')));
    }

    $normalized = ltrim($normalized, '/');

    if ($normalized === '') {
        return '';
    }

    return BASE_URL . '/uploads/products/' . str_replace('%2F', '/', rawurlencode($normalized));
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

function product_discount_percent(array $product): ?int {
    $storedPercent = trim((string)($product['phan_tram_giam'] ?? ''));
    if ($storedPercent !== '' && is_numeric($storedPercent)) {
        $percent = (int)round((float)$storedPercent);
        return $percent > 0 ? $percent : null;
    }

    $giaBan = trim((string)($product['gia_ban'] ?? ''));
    $giaThiTruong = trim((string)($product['gia_thi_truong'] ?? ''));
    if ($giaBan === '' || $giaThiTruong === '' || !is_numeric($giaBan) || !is_numeric($giaThiTruong)) {
        return null;
    }

    $giaBanNum = (float)$giaBan;
    $giaThiTruongNum = (float)$giaThiTruong;
    if ($giaBanNum <= 0 || $giaThiTruongNum <= 0 || $giaThiTruongNum <= $giaBanNum) {
        return null;
    }

    $percent = (int)round((($giaThiTruongNum - $giaBanNum) / $giaThiTruongNum) * 100);
    return $percent > 0 ? $percent : null;
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
        'admin_sp_visibility' => ['admin'],
        'admin_categories' => ['admin'],
        'admin_category_save' => ['admin'],
        'admin_category_delete' => ['admin'],
        'admin_vouchers' => ['admin'],
        'admin_voucher_save' => ['admin'],
        'admin_voucher_delete' => ['admin'],
        'admin_users' => ['admin'],
        'admin_customer_save' => ['admin'],
        'admin_customer_delete' => ['admin'],
        'admin_staff_save' => ['admin'],
        'admin_staff_delete' => ['admin'],
        'admin_staff_hard_delete' => ['admin'],
        'admin_orders' => ['admin'],
        'admin_order_status' => ['admin'],
        'admin_reports' => ['admin'],
        'admin_questions' => ['admin', 'nhanvien'],
        'admin_question_reply' => ['admin', 'nhanvien'],
        'admin_question_hide' => ['admin', 'nhanvien'],
        'admin_notifications_seen' => ['admin', 'nhanvien'],
        'staff_dashboard' => ['admin', 'nhanvien'],
        'staff_orders' => ['admin', 'nhanvien'],
        'staff_order_status' => ['admin', 'nhanvien'],
        'staff_products' => ['admin', 'nhanvien'],
        'staff_product_create' => ['admin', 'nhanvien'],
        'staff_product_edit' => ['admin', 'nhanvien'],
        'staff_product_visibility' => ['admin', 'nhanvien'],
        'staff_reviews' => ['admin', 'nhanvien'],
        'staff_review_reply' => ['admin', 'nhanvien'],
        'staff_chats' => ['admin', 'nhanvien'],
        'staff_chat_state' => ['admin', 'nhanvien'],
        'staff_chat_send' => ['admin', 'nhanvien'],
        'lichsuchat' => ['khach_hang'],
        'chat_send' => ['khach_hang'],
        'mark_chat_read' => ['khach_hang'],
        'guidanhgia' => ['khach_hang'],
        'guicauhoi' => ['khach_hang'],
        'huydonhang' => ['khach_hang'],
        'apdung_voucher' => ['khach_hang'],
        'bo_voucher' => ['khach_hang'],
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
