<?php
// Test thuần PHP (không DB, không mạng) cho Trung tâm hỗ trợ — Bước 01 (+ bài guide Bước 02).
// Chạy: php backend/tests/unit/SupportContentTest.php
require_once __DIR__ . '/../../app/services/SupportKnowledge.php';

$fail = 0;
$pass = 0;
$check = function (string $name, bool $ok) use (&$fail, &$pass) {
    echo ($ok ? 'PASS ' : 'FAIL ') . $name . PHP_EOL;
    $ok ? $pass++ : $fail++;
};

// ---------- itemText / itemDetails ----------
$check('itemText: chuỗi giữ nguyên', SupportKnowledge::itemText('Xin chào') === 'Xin chào');
$check('itemText: object lấy text', SupportKnowledge::itemText(['text' => 'Bước 1', 'details' => ['a']]) === 'Bước 1');
$check('itemText: object thiếu text -> ""', SupportKnowledge::itemText(['details' => ['a']]) === '');
$check('itemText: text không phải chuỗi -> ""', SupportKnowledge::itemText(['text' => ['x']]) === '');
$check('itemText: rác (null/int/bool/array rỗng) -> ""', SupportKnowledge::itemText(null) === '' && SupportKnowledge::itemText(42) === '' && SupportKnowledge::itemText(true) === '' && SupportKnowledge::itemText([]) === '');
$check('itemDetails: chuỗi -> []', SupportKnowledge::itemDetails('Xin chào') === []);
$check('itemDetails: object trả danh sách chuỗi', SupportKnowledge::itemDetails(['text' => 'x', 'details' => ['a', 'b']]) === ['a', 'b']);
$check('itemDetails: bỏ phần tử không phải chuỗi/rỗng', SupportKnowledge::itemDetails(['text' => 'x', 'details' => ['a', 5, null, '', ['z'], 'b']]) === ['a', 'b']);
$check('itemDetails: details không phải mảng -> []', SupportKnowledge::itemDetails(['text' => 'x', 'details' => 'chuỗi']) === []);
$check('itemDetails: rác -> []', SupportKnowledge::itemDetails(null) === [] && SupportKnowledge::itemDetails(7) === [] && SupportKnowledge::itemDetails([]) === []);

// ---------- contact() ----------
$contact = SupportKnowledge::contact();
$check('contact: đúng 3 khóa phone/email/hours', is_array($contact) && array_keys($contact) === ['phone', 'email', 'hours']);
$check('contact: hotline 0398606281', ($contact['phone'] ?? '') === '0398606281');
$check('contact: email cskh@skinsyntax.com', ($contact['email'] ?? '') === 'cskh@skinsyntax.com');
$check('contact: giờ hỗ trợ chưa công bố (không có khung giờ cụ thể)', !preg_match('/\d{1,2}:\d{2}/', (string)($contact['hours'] ?? '')) && str_contains((string)$contact['hours'], 'chưa được công bố'));

// ---------- schema support.json ----------
$knowledge = new SupportKnowledge();
$data = $knowledge->data();
$check('version = 2026-10-21', ($data['version'] ?? '') === '2026-10-21');
$check('có nhóm guide đúng nội dung', ($data['groups']['guide']['title'] ?? '') === 'Hướng dẫn sử dụng'
    && ($data['groups']['guide']['icon'] ?? '') === 'fa-book-open'
    && ($data['groups']['guide']['description'] ?? '') === 'Các bước dùng tính năng trên SkinSyntax: khảo sát da, Syna, voucher, live…');
$groupsOk = true;
foreach ($data['groups'] as $key => $group) {
    foreach (['title', 'description', 'icon'] as $field) {
        if (!isset($group[$field]) || !is_string($group[$field]) || $group[$field] === '') $groupsOk = false;
    }
}
$check('mỗi nhóm có title/description/icon', $groupsOk);

$slugs = array_column($data['articles'], 'slug');
$check('slug duy nhất', count($slugs) === count(array_unique($slugs)));
$allOk = true; $bad = [];
$itemsOk = true; $badItem = [];
$optOk = true;
foreach ($data['articles'] as $article) {
    $slug = $article['slug'] ?? '?';
    foreach (['slug', 'group', 'title', 'summary', 'answer'] as $field) {
        if (!isset($article[$field]) || !is_string($article[$field]) || $article[$field] === '') { $allOk = false; $bad[] = "$slug:$field"; }
    }
    if (!isset($data['groups'][$article['group'] ?? ''])) { $allOk = false; $bad[] = "$slug:group-không-tồn-tại"; }
    if (empty($article['sections']) || !is_array($article['sections'])) { $allOk = false; $bad[] = "$slug:không-có-section"; continue; }
    foreach ($article['sections'] as $section) {
        if (!is_string($section['title'] ?? null) || $section['title'] === '' || empty($section['items']) || !is_array($section['items'])) { $allOk = false; $bad[] = "$slug:section-sai"; continue; }
        foreach ($section['items'] as $item) {
            $text = SupportKnowledge::itemText($item);
            $valid = $text !== '' && (is_string($item) || (is_array($item) && (!isset($item['details']) || (is_array($item['details']) && SupportKnowledge::itemDetails($item) === $item['details']))));
            if (!$valid) { $itemsOk = false; $badItem[] = $slug; }
        }
    }
    if (isset($article['route_sources']) && (!is_array($article['route_sources']) || array_filter($article['route_sources'], fn($r) => !is_string($r) || $r === ''))) $optOk = false;
    if (isset($article['updated']) && !(is_string($article['updated']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $article['updated']))) $optOk = false;
}
$check('mỗi bài: trường bắt buộc + group tồn tại + ≥1 section' . ($bad ? ' [' . implode(',', array_unique($bad)) . ']' : ''), $allOk);
$check('mọi item hợp lệ (chuỗi hoặc {text,details[]})' . ($badItem ? ' [' . implode(',', array_unique($badItem)) . ']' : ''), $itemsOk);
$check('route_sources là mảng chuỗi, updated là YYYY-MM-DD (nếu có)', $optOk);

$json = (string)file_get_contents(__DIR__ . '/../../app/content/support.json');
$check('support.json không chứa 1900 0000', !str_contains($json, '1900 0000'));
$check('support.json không chứa 08:00 - 22:00', !str_contains($json, '08:00 - 22:00'));
$check('không còn tham chiếu route lichsuchat trong nội dung', !str_contains($json, 'lichsuchat'));

// ---------- nội dung đã gộp (Bước 01) ----------
$text = function (string $slug) use ($knowledge): string {
    $a = $knowledge->article($slug);
    $out = [];
    foreach ($a['sections'] as $s) foreach ($s['items'] as $i) { $out[] = SupportKnowledge::itemText($i); array_push($out, ...SupportKnowledge::itemDetails($i)); }
    return implode("\n", $out);
};
$check('otp không nhắc số điện thoại là kênh nhận OTP', !str_contains(SupportKnowledge::fold($text('otp')), 'email hoac so dien thoai'));
$check('otp không còn "3 lần gửi lại"', !str_contains($text('otp'), '3 lần'));
$check('dieu-khoan đã gộp ý khiếu nại/tranh chấp theo pháp luật', str_contains($text('dieu-khoan'), 'pháp luật Việt Nam'));
$check('bao-mat đã gộp ý không bán thông tin cá nhân', str_contains($text('bao-mat'), 'không bán'));
$check('doi-tra đã gộp ý thiếu phụ kiện', str_contains($text('doi-tra'), 'thiếu phụ kiện'));
$check('giữ nguyên chính sách 7 ngày của doi-tra', str_contains($text('doi-tra'), '7 ngày'));

// ---------- bài hướng dẫn sử dụng (nhóm guide) — Bước 02 ----------
$routerFiles = [
    __DIR__ . '/../../public/index.php',
    __DIR__ . '/../../../frontend/public/index.php',
    __DIR__ . '/../../../index.php',
];
$routerSource = '';
foreach ($routerFiles as $routerFile) $routerSource .= (string)@file_get_contents($routerFile) . "\n";
$assistanceSource = (string)@file_get_contents(__DIR__ . '/../../app/routes/store_assistance.php');
$routeExists = static function (string $route) use ($routerSource, $assistanceSource): bool {
    if ($route === '' || preg_match('/^[a-z0-9_]+$/', $route) !== 1) return false;
    // `case 'route':` ở ít nhất một trong 3 router, hoặc có mặt trong store_assistance.php (alias / chat_purchase_action).
    return preg_match("/case\\s+'" . preg_quote($route, '/') . "'\\s*:/", $routerSource) === 1
        || preg_match("/'" . preg_quote($route, '/') . "'/", $assistanceSource) === 1;
};
$check('routeExists: route thật (khaosat, giohang) có case', $routeExists('khaosat') && $routeExists('giohang'));
$check('routeExists: alias store_assistance (tro_giup, chat_purchase_action) được nhận', $routeExists('tro_giup') && $routeExists('chat_purchase_action'));
$check('routeExists: route không có case (lichsuchat, route_bia) bị từ chối', !$routeExists('lichsuchat') && !$routeExists('route_bia') && !$routeExists("a'b"));

$guides = array_values(array_filter($data['articles'], static fn($a) => ($a['group'] ?? '') === 'guide'));
$check('có ít nhất 1 bài guide (' . count($guides) . ' bài)', count($guides) >= 1);

$phones = [$contact['phone'] ?? ''];
$emails = [strtolower((string)($contact['email'] ?? ''))];
$guideProblems = [];
foreach ($guides as $guide) {
    $slug = (string)($guide['slug'] ?? '?');
    // route_sources: bắt buộc, mỗi route có thật
    $sources = $guide['route_sources'] ?? null;
    if (!is_array($sources) || $sources === []) { $guideProblems[] = "$slug:thiếu-route_sources"; $sources = []; }
    foreach ($sources as $route) {
        if (!is_string($route) || !$routeExists($route)) $guideProblems[] = "$slug:route-không-có[" . (is_string($route) ? $route : '?') . ']';
        if ($route === 'lichsuchat') $guideProblems[] = "$slug:route-lichsuchat";
    }
    // action.url (nếu có) phải trỏ route có thật
    if (isset($guide['action']['url'])) {
        parse_str((string)parse_url((string)$guide['action']['url'], PHP_URL_QUERY), $actionQuery);
        $actionRoute = (string)($actionQuery['r'] ?? '');
        if ($actionRoute === '' || !$routeExists($actionRoute)) $guideProblems[] = "$slug:action-route-không-có[$actionRoute]";
    }
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($guide['updated'] ?? ''))) $guideProblems[] = "$slug:thiếu-updated";
    // triggers: chữ thường không dấu, đã fold, 5–12 cụm, không trùng
    $triggers = $guide['triggers'] ?? [];
    if (!is_array($triggers) || count($triggers) < 5 || count($triggers) > 12) $guideProblems[] = "$slug:số-triggers-" . (is_array($triggers) ? count($triggers) : 0);
    foreach ((array)$triggers as $trigger) {
        if (!is_string($trigger) || $trigger === '' || SupportKnowledge::fold($trigger) !== $trigger) $guideProblems[] = "$slug:trigger-chưa-fold[" . (is_string($trigger) ? $trigger : '?') . ']';
    }
    if (is_array($triggers) && count($triggers) !== count(array_unique($triggers))) $guideProblems[] = "$slug:trigger-trùng";
    // độ dài + số lượng phần theo prompt Bước 02
    if (mb_strlen((string)($guide['title'] ?? '')) > 60) $guideProblems[] = "$slug:title>60";
    if (mb_strlen((string)($guide['summary'] ?? '')) > 140) $guideProblems[] = "$slug:summary>140";
    $sectionCount = is_array($guide['sections'] ?? null) ? count($guide['sections']) : 0;
    if ($sectionCount < 2 || $sectionCount > 4) $guideProblems[] = "$slug:số-section-$sectionCount";
    $questionCount = is_array($guide['questions'] ?? null) ? count($guide['questions']) : 0;
    if ($questionCount < 2 || $questionCount > 4) $guideProblems[] = "$slug:số-questions-$questionCount";
    // toàn bộ văn bản hiển thị: không SĐT/email khác contact, không lichsuchat
    $texts = [(string)($guide['title'] ?? ''), (string)($guide['summary'] ?? ''), (string)($guide['answer'] ?? ''), (string)($guide['action']['label'] ?? ''), (string)($guide['action']['url'] ?? '')];
    foreach ((array)($guide['questions'] ?? []) as $question) $texts[] = (string)$question;
    foreach ((array)($guide['sections'] ?? []) as $section) {
        $texts[] = (string)($section['title'] ?? '');
        foreach ((array)($section['items'] ?? []) as $item) { $texts[] = SupportKnowledge::itemText($item); array_push($texts, ...SupportKnowledge::itemDetails($item)); }
    }
    $blob = implode("\n", $texts);
    if (preg_match_all('/[A-Za-z0-9._%+\-]+@[A-Za-z0-9.\-]+\.[A-Za-z]{2,}/', $blob, $found)) {
        foreach ($found[0] as $email) if (!in_array(strtolower($email), $emails, true)) $guideProblems[] = "$slug:email-lạ[$email]";
    }
    if (preg_match_all('/(?<![\w])(?:\+?84|0)(?:[\s.\-]?\d){8,10}(?!\d)/', $blob, $found)) {
        foreach ($found[0] as $phone) {
            $digits = preg_replace('/\D/', '', $phone);
            if (str_starts_with($digits, '84')) $digits = '0' . substr($digits, 2);
            if (!in_array($digits, $phones, true)) $guideProblems[] = "$slug:sđt-lạ[$phone]";
        }
    }
    if (stripos($blob . json_encode($sources), 'lichsuchat') !== false) $guideProblems[] = "$slug:lichsuchat";
    if (str_contains($blob, '1900') || preg_match('/\b\d{1,2}:\d{2}\b/', $blob)) $guideProblems[] = "$slug:có-giờ-hoặc-1900";
}
$check('guide: route_sources/updated/triggers đã fold/độ dài/liên hệ đúng' . ($guideProblems ? ' [' . implode('; ', $guideProblems) . ']' : ''), $guideProblems === []);
$guideSlugs = array_column($guides, 'slug');
$check('guide: slug không trùng bài ngoài nhóm guide', count(array_intersect($guideSlugs, array_column(array_filter($data['articles'], static fn($a) => ($a['group'] ?? '') !== 'guide'), 'slug'))) === 0);

// Trigger của bài guide không được trùng nguyên văn trigger của bài khác (trùng phải có chủ ý + ghi handoff).
$triggerOwners = [];
foreach ($data['articles'] as $article) foreach ((array)$article['triggers'] as $trigger) $triggerOwners[$trigger][] = $article['slug'];
$dupTriggers = [];
foreach ($triggerOwners as $trigger => $owners) if (count($owners) > 1 && array_intersect($owners, $guideSlugs)) $dupTriggers[] = $trigger . ' => ' . implode(',', $owners);
$check('guide: không có trigger trùng với bài khác' . ($dupTriggers ? ' [' . implode('; ', $dupTriggers) . ']' : ''), $dupTriggers === []);

// ---------- tương thích ngược: dữ liệu cũ vẫn là chuỗi ----------
$old = new SupportKnowledge();
$check('article() + match() không đổi hành vi', $old->article('otp')['slug'] === 'otp' && ($old->match('OTP đăng ký bao lâu hết hạn?')['slug'] ?? '') === 'otp');

echo PHP_EOL . "Tổng: $pass PASS, $fail FAIL" . PHP_EOL;
exit($fail === 0 ? 0 : 1);
