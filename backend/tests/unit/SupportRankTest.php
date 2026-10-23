<?php
// Test thuần PHP (không DB, không mạng) cho xếp hạng bài hỗ trợ — Bước 04.
// Chạy: php backend/tests/unit/SupportRankTest.php
require_once __DIR__ . '/../../app/services/SupportKnowledge.php';

$fail = 0;
$pass = 0;
$check = function (string $name, bool $ok) use (&$fail, &$pass) {
    echo ($ok ? 'PASS ' : 'FAIL ') . $name . PHP_EOL;
    $ok ? $pass++ : $fail++;
};

$k = new SupportKnowledge();
$data = $k->data();
$base = 'http://test.local';
$top = static fn(array $ranked): string => $ranked[0]['article']['slug'] ?? '(rỗng)';

// ---------- hằng số ----------
$check('LOW_THRESHOLD < HIGH_THRESHOLD', SupportKnowledge::LOW_THRESHOLD > 0 && SupportKnowledge::LOW_THRESHOLD < SupportKnowledge::HIGH_THRESHOLD);
$check('STOPWORDS là mảng đã fold (không dấu)', is_array(SupportKnowledge::STOPWORDS) && in_array('minh', SupportKnowledge::STOPWORDS, true) && in_array('ha', SupportKnowledge::STOPWORDS, true));

// ---------- 1. mỗi câu trong questions -> đúng bài, hạng 1 ----------
$qTotal = 0; $qBad = [];
foreach ($data['articles'] as $article) {
    foreach ($article['questions'] as $question) {
        $qTotal++;
        $ranked = $k->rank($question, 3, $base);
        if ($top($ranked) !== $article['slug']) $qBad[] = $question . ' => ' . $top($ranked);
        elseif ($ranked[0]['score'] < SupportKnowledge::HIGH_THRESHOLD) $qBad[] = $question . ' (điểm thấp)';
    }
}
$check("questions: $qTotal câu đều hạng 1 và >= HIGH_THRESHOLD" . ($qBad ? ' | ' . implode('; ', $qBad) : ''), $qBad === []);

// ---------- 2. diễn đạt khác ----------
$paraphrase = [
    'muốn trả lại hàng thì làm sao' => 'doi-tra',
    'ship bao nhiêu tiền' => 'van-chuyen',
    'quen pass' => 'quen-mat-khau',
    'ko nhan dc ma otp' => 'otp',
    'xoa tai khoan kieu gi' => 'xoa-tai-khoan',
    'xuất hóa đơn đỏ được không' => 'hoa-don',
    'giao sai hàng thì sao' => 'hang-loi',
    'số điện thoại shop' => 'lien-he',
    'huy don duoc khong' => 'huy-sua-don',
    'cookie de lam gi' => 'cookie',
    'shop có freeship không' => 'van-chuyen',
    'bao lâu thì nhận được hàng' => 'van-chuyen',
    'hoàn tiền mất mấy ngày' => 'doi-tra',
    'em muốn trả hàng đã mở nắp' => 'doi-tra',
    'chai bị vỡ lúc giao thì sao' => 'hang-loi',
    'thanh toán bằng gì' => 'thanh-toan',
    'chuyển khoản rồi mà đơn chưa lên' => 'thanh-toan',
    'có hỗ trợ cod không' => 'thanh-toan',
    'thanh toan bang vnpya bi loi' => 'thanh-toan',
    'đăng ký tài khoản mới' => 'dang-ky',
    'dang ki tai khoan' => 'dang-ky',
    'tôi muốn đổi email đăng nhập' => 'doi-lien-he',
    'doi sdt lien he' => 'doi-lien-he',
    'có người bảo đọc otp cho họ, lừa đảo à' => 'an-toan-tai-khoan',
    'đơn hàng của tôi đang ở đâu' => 'theo-doi-don',
    'tra cuu don' => 'theo-doi-don',
    'hotline của shop là gì' => 'lien-he',
    'mình muốn khiếu nại' => 'khieu-nai',
    'shop có bán dữ liệu cá nhân không' => 'bao-mat',
    'điều khoản sử dụng xem ở đâu' => 'dieu-khoan',
    'quy chế hoạt động sàn thương mại điện tử' => 'quy-che',
    'hướng dẫn đặt hàng' => 'dat-hang',
    'đổi địa chỉ giao hàng được không' => 'huy-sua-don',
    'mất email đăng ký thì sao' => 'quen-mat-khau',
    'otp het han chua' => 'otp',
    'xin hóa đơn công ty' => 'hoa-don',
    'khong dang nhap duoc tai khoan' => 'quen-mat-khau',
];
$pBad = [];
foreach ($paraphrase as $q => $slug) {
    $ranked = $k->rank($q, 3, $base);
    if ($top($ranked) !== $slug) $pBad[] = $q . ' => ' . $top($ranked) . ' (cần ' . $slug . ')';
}
$pTotal = count($paraphrase);
$check('diễn đạt khác: ' . ($pTotal - count($pBad)) . "/$pTotal đúng hạng 1" . ($pBad ? ' | ' . implode('; ', $pBad) : ''), $pBad === []);

// ---------- 3. câu sản phẩm/da -> rỗng ----------
$product = [
    'serum cho da dầu', 'kem chống nắng nào tốt', 'retinol dùng với aha được không', 'da mụn nên dùng gì',
    'gợi ý toner cho da nhạy cảm', 'niacinamide có tác dụng gì', 'routine buổi sáng cho da khô',
    'sữa rửa mặt cerave giá bao nhiêu', 'vitamin c dùng ban đêm được không', 'có sản phẩm nào trị thâm mụn không',
    'phân tích thành phần serum này', 'shop có bán kem dưỡng ẩm không', 'mua serum cerave retinol',
    'da nhạy cảm dùng retinol được không', 'tôi bị mụn ẩn nên làm sao', 'so sánh serum cerave và svr',
    'kem dưỡng nào hợp da hỗn hợp', 'có nên dùng aha và bha cùng lúc', 'da tôi bị bong tróc phải làm sao',
    'sản phẩm nào giúp mờ thâm', 'tư vấn mua serum cho da nhạy cảm', 'toner và serum khác nhau thế nào',
    'cách dùng retinol cho người mới', 'Tôi không muốn mua retinol nữa', 'giỏ hàng của tôi có xung đột thành phần không',
];
$prodBad = [];
foreach ($product as $q) {
    $ranked = $k->rank($q, 3, $base);
    if ($ranked !== []) $prodBad[] = $q . ' => ' . $top($ranked) . ' ' . round($ranked[0]['score'], 2);
}
$check('câu sản phẩm/da: ' . (count($product) - count($prodBad)) . '/' . count($product) . ' không bị hút' . ($prodBad ? ' | ' . implode('; ', $prodBad) : ''), $prodBad === []);

// ---------- 4. lời chào/cảm ơn/rỗng -> rỗng ----------
$greetBad = [];
foreach (['xin chào', 'chào shop', 'hello', 'hi', 'cảm ơn bạn nhé', 'cam on shop', 'ok', 'thanks', '', '   ', '???'] as $q) {
    if ($k->rank($q, 3, $base) !== []) $greetBad[] = $q;
}
$check('lời chào/cảm ơn/rỗng -> rỗng' . ($greetBad ? ' | ' . implode('; ', $greetBad) : ''), $greetBad === []);

// ---------- 5. hình dạng kết quả ----------
$ranked = $k->rank('phí ship bao nhiêu', 3, $base);
$check('rank: tối đa limit phần tử, giảm dần, >= LOW_THRESHOLD', count($ranked) >= 1 && count($ranked) <= 3
    && array_reduce(array_keys($ranked), static fn($ok, $i) => $ok && $ranked[$i]['score'] >= SupportKnowledge::LOW_THRESHOLD && ($i === 0 || $ranked[$i - 1]['score'] >= $ranked[$i]['score']), true));
$check('rank: phần tử có article/score/url, url đúng mẫu', isset($ranked[0]['article']['slug'], $ranked[0]['score'], $ranked[0]['url'])
    && $ranked[0]['url'] === $base . '/index.php?r=tro_giup&bai=' . rawurlencode($ranked[0]['article']['slug']));
$check('rank: limit=1 chỉ trả 1', count($k->rank('phí ship bao nhiêu', 1, $base)) === 1);
$check('rank: gọi lặp cho cùng kết quả (không trạng thái ẩn)', json_encode($k->rank('đổi trả', 3, $base)) === json_encode($k->rank('đổi trả', 3, $base)));

// ---------- 6. match()/answer() không đổi: bộ câu của check_store_cases ----------
$cases = json_decode(file_get_contents(__DIR__ . '/../store_chat_cases.json'), true, 512, JSON_THROW_ON_ERROR);
$matchBad = [];
foreach ($cases['support'] as $topic => $questions) {
    foreach ($questions as $question) {
        if (($k->match($question)['slug'] ?? 'unhandled') !== $topic) $matchBad[] = $question;
        $ans = $k->answer($question, $base);
        if (($ans['support_topic'] ?? '') !== $topic || ($ans['intent_mode'] ?? '') !== 'STORE_SUPPORT') $matchBad[] = 'answer: ' . $question;
    }
}
$check('match()/answer() bộ check_store_cases không đổi' . ($matchBad ? ' | ' . implode('; ', $matchBad) : ''), $matchBad === []);
$check('match() câu sản phẩm vẫn null', $k->match('serum cho da dầu') === null);

// ---------- 7. toChatContext ----------
$ranked = $k->rank('Điều kiện đổi trả là gì?', 3, $base);
$ctx = $k->toChatContext($ranked);
$check('toChatContext: đủ khóa', $ctx !== [] && array_keys($ctx[0]) === ['slug', 'title', 'summary', 'answer', 'sections', 'url', 'version', 'score', 'questions']);
$check('toChatContext: version khớp support.json, url giữ nguyên', $ctx[0]['version'] === $data['version'] && $ctx[0]['url'] === $ranked[0]['url']);
$shapeOk = true;
foreach ($ctx[0]['sections'] as $section) {
    if (!is_string($section['title']) || !is_array($section['items'])) $shapeOk = false;
    foreach ($section['items'] as $item) {
        if (!is_string($item['text'] ?? null) || !is_array($item['details'] ?? null)) $shapeOk = false;
    }
}
$check('toChatContext: sections chuẩn hóa {title, items[{text, details[]}]}', $shapeOk && $ctx[0]['sections'] !== []);

$measure = static function (array $a): int {
    $n = mb_strlen($a['title']) + mb_strlen($a['summary']) + mb_strlen($a['answer']);
    foreach ($a['questions'] as $q) $n += mb_strlen($q);
    foreach ($a['sections'] as $s) {
        $n += mb_strlen($s['title']);
        foreach ($s['items'] as $i) { $n += mb_strlen($i['text']); foreach ($i['details'] as $d) $n += mb_strlen($d); }
    }
    return $n;
};
$allWithin = true;
foreach ($data['articles'] as $article) {
    $c = $k->toChatContext([['article' => $article, 'score' => 9.0, 'url' => $base . '/x']]);
    if ($measure($c[0]) > SupportKnowledge::CONTEXT_CHAR_LIMIT) $allWithin = false;
}
$check('toChatContext: mọi bài thật <= ' . SupportKnowledge::CONTEXT_CHAR_LIMIT . ' ký tự', $allWithin);

$long = ['slug' => 'dai', 'group' => 'x', 'title' => 'Bài dài', 'summary' => 'Tóm tắt', 'answer' => 'Trả lời ngắn.', 'triggers' => ['dai'], 'questions' => ['Câu hỏi?'],
    'sections' => [
        ['title' => 'Mục một', 'items' => ['Mục đầu tiên ' . str_repeat('từ ', 200), 'Mục thứ hai ' . str_repeat('chữ ', 200)]],
        ['title' => 'Mục hai', 'items' => [['text' => 'Mục có chi tiết ' . str_repeat('ý ', 200), 'details' => ['chi tiết ' . str_repeat('x ', 300)]], 'Mục cuối cùng ' . str_repeat('đoạn ', 200)]],
    ]];
$c = $k->toChatContext([['article' => $long, 'score' => 5.0, 'url' => $base . '/x']])[0];
$flat = [];
foreach ($c['sections'] as $s) foreach ($s['items'] as $i) $flat[] = $i['text'];
$check('toChatContext: bài dài bị cắt xuống <= giới hạn', $measure($c) <= SupportKnowledge::CONTEXT_CHAR_LIMIT);
$check('toChatContext: cắt item cuối trước (item còn lại là tiền tố của danh sách gốc, nguyên vẹn)',
    count($flat) >= 1 && count($flat) < 4 && $flat[0] === $long['sections'][0]['items'][0]);
$check('toChatContext: không để section rỗng', array_reduce($c['sections'], static fn($ok, $s) => $ok && $s['items'] !== [], true));

$hugeAnswer = $long;
$hugeAnswer['answer'] = str_repeat('Câu trả lời rất dài không dấu cách ', 120);
$c2 = $k->toChatContext([['article' => $hugeAnswer, 'score' => 5.0, 'url' => $base . '/x']])[0];
$lastWord = (string)preg_replace('/^.*\s/us', '', rtrim($c2['answer'], '…'));
$check('toChatContext: answer quá dài vẫn <= giới hạn và không cắt giữa từ',
    $measure($c2) <= SupportKnowledge::CONTEXT_CHAR_LIMIT && str_ends_with($c2['answer'], '…')
    && in_array($lastWord, ['Câu', 'trả', 'lời', 'rất', 'dài', 'không', 'dấu', 'cách'], true));
$check('toChatContext: rỗng -> []', $k->toChatContext([]) === []);

// ---------- 8. gợi ý + fallback ----------
$ranked = $k->rank('Phí vận chuyển bao nhiêu?', 3, $base);
$sug = $k->suggestedQuestions($ranked, 'Phí vận chuyển bao nhiêu?');
$check('suggestedQuestions: <= 3, bỏ câu trùng câu khách', count($sug) >= 1 && count($sug) <= 3 && !in_array('Phí vận chuyển bao nhiêu?', $sug, true) && $sug === array_values(array_unique($sug)));
$check('suggestedQuestions: rỗng -> []', $k->suggestedQuestions([], 'x') === []);

$text = $k->formatFallbackAnswer($ranked);
$art = $ranked[0]['article'];
$check('formatFallbackAnswer: bắt đầu bằng answer của bài đầu', str_starts_with($text, $art['answer']));
$check('formatFallbackAnswer: có bước đánh số "1. " lấy từ section đầu', str_contains($text, "\n1. " . SupportKnowledge::itemText($art['sections'][0]['items'][0])));
$check('formatFallbackAnswer: dòng Nguồn có link đúng', str_ends_with($text, 'Nguồn: [' . $art['title'] . '](' . $ranked[0]['url'] . ')'));
$check('formatFallbackAnswer: rỗng -> ""', $k->formatFallbackAnswer([]) === '');

$fb = $k->fallbackResponse($k->rank('Điều kiện đổi trả là gì?', 3, $base), 'Điều kiện đổi trả là gì?');
$check('fallbackResponse: bài khớp nguyên câu -> payload đầy đủ', is_array($fb) && $fb['pipeline_mode'] === 'verified_store_content' && $fb['intent_mode'] === 'STORE_SUPPORT'
    && $fb['fallback'] === true && $fb['support_topic'] === 'doi-tra' && $fb['sources'][0]['url'] !== '' && $fb['sources'][0]['version'] === $data['version']
    && isset($fb['sources'][0]['title']) && count($fb['suggested_questions']) <= 3 && $fb['answer'] !== '');
$weak = [['article' => $data['articles'][0], 'score' => SupportKnowledge::HIGH_THRESHOLD - 0.01, 'url' => $base . '/x']];
$check('fallbackResponse: bài đầu dưới HIGH_THRESHOLD -> null', $k->fallbackResponse($weak, 'x') === null);
$check('fallbackResponse: rỗng -> null', $k->fallbackResponse([], 'x') === null);

// ---------- 9. payload gửi sang Flask ----------
$base_payload = ['customer_question' => 'x', 'retrieved_products' => [], 'response_requirements' => ['a']];
$check('withSupportArticles: rỗng -> payload GIỐNG HỆT', SupportKnowledge::withSupportArticles($base_payload, []) === $base_payload
    && json_encode(SupportKnowledge::withSupportArticles($base_payload, [])) === json_encode($base_payload));
$with = SupportKnowledge::withSupportArticles($base_payload, $ctx);
$check('withSupportArticles: có bài -> thêm đúng một khóa, khóa cũ giữ nguyên', array_diff_key($with, $base_payload) === ['store_support_articles' => $ctx]
    && array_intersect_key($with, $base_payload) === $base_payload);
$check('withSupportArticles: khóa mới đặt sau khóa cũ', array_key_last($with) === 'store_support_articles');
$check('withSupportArticles: JSON giữ UTF-8/ký tự', is_string(json_encode($with, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)));

// ---------- 10. chuyển tiếp từ Flask ----------
$flask = ['intent_mode' => 'STORE_SUPPORT', 'support_topic' => 'doi-tra', 'sources' => [['title' => 'T', 'url' => 'u', 'version' => 'v']], 'suggested_questions' => ['a', 'b']];
$check('flaskSupportFields: STORE_SUPPORT -> chuyển tiếp 3 khóa', SupportKnowledge::flaskSupportFields($flask) === ['support_topic' => 'doi-tra', 'sources' => $flask['sources'], 'suggested_questions' => ['a', 'b']]);
$check('flaskSupportFields: intent khác -> []', SupportKnowledge::flaskSupportFields(['intent_mode' => 'PRODUCT_INQUIRY', 'sources' => [['x']]]) === []);
$check('flaskSupportFields: null/rác -> []', SupportKnowledge::flaskSupportFields(null) === []);
$check('flaskSupportFields: sai kiểu bị lọc', SupportKnowledge::flaskSupportFields(['intent_mode' => 'STORE_SUPPORT', 'support_topic' => ['x'], 'sources' => 'str', 'suggested_questions' => [1, 'ok']]) === ['support_topic' => '', 'sources' => [], 'suggested_questions' => ['ok']]);

// ---------- 11. nhánh Flask lỗi (giả lập kết quả postJsonRequest) ----------
$check('flaskFailed: timeout/status 0, 500, 404 -> lỗi', SupportKnowledge::flaskFailed(['status' => 0, 'body' => ''], null) && SupportKnowledge::flaskFailed(['status' => 500, 'body' => '{}'], []) && SupportKnowledge::flaskFailed(['status' => 404], null));
$check('flaskFailed: 200 + JSON -> không lỗi; 200 + thân rác -> lỗi', !SupportKnowledge::flaskFailed(['status' => 200], ['answer' => 'x']) && SupportKnowledge::flaskFailed(['status' => 200, 'body' => '<html>'], null));
$sim = $k->rank('đổi trả trong bao lâu', 3, $base);
$simFb = SupportKnowledge::flaskFailed(['status' => 0, 'body' => '', 'error' => 'timeout'], null) ? $k->fallbackResponse($sim, 'đổi trả trong bao lâu') : null;
$check('mô phỏng Flask timeout: "đổi trả trong bao lâu" -> fallback doi-tra có link', is_array($simFb) && $simFb['support_topic'] === 'doi-tra' && str_contains($simFb['answer'], '(' . $base . '/index.php?r=tro_giup&bai=doi-tra)'));
$simProduct = $k->fallbackResponse($k->rank('serum cho da dầu', 3, $base), 'serum cho da dầu');
$check('mô phỏng Flask timeout: câu sản phẩm -> null (giữ câu lỗi cũ)', $simProduct === null);

// ---------- 12. bỏ qua cache phản hồi AI khi câu hỏi khớp bài hỗ trợ (Bước 05b) ----------
$check('shouldBypassChatCache: rank rỗng -> false (cache giữ nguyên)', SupportKnowledge::shouldBypassChatCache([]) === false);
$check('shouldBypassChatCache: có bài >= LOW_THRESHOLD -> true', SupportKnowledge::shouldBypassChatCache([['article' => ['slug' => 'x'], 'score' => SupportKnowledge::LOW_THRESHOLD, 'url' => 'u']]) === true);
$check('shouldBypassChatCache: điểm < LOW_THRESHOLD hoặc phần tử rác -> false', SupportKnowledge::shouldBypassChatCache([['article' => ['slug' => 'x'], 'score' => 1.0], 'rác', ['score' => 'abc']]) === false);
$check('shouldBypassChatCache: "đổi trả trong bao lâu" -> true', SupportKnowledge::shouldBypassChatCache($k->rank('đổi trả trong bao lâu', 3, $base)) === true);
$check('shouldBypassChatCache: "tôi muốn xem dữ liệu cá nhân của mình thì làm thế nào" -> true', SupportKnowledge::shouldBypassChatCache($k->rank('tôi muốn xem dữ liệu cá nhân của mình thì làm thế nào', 3, $base)) === true);
foreach (['serum cho da dầu', 'chào shop', 'kem chống nắng nào tốt cho da nhạy cảm'] as $productQ) {
    $check("shouldBypassChatCache: \"$productQ\" -> false (giữ cache cũ)", SupportKnowledge::shouldBypassChatCache($k->rank($productQ, 3, $base)) === false);
}

echo PHP_EOL . "Tổng: $pass PASS, $fail FAIL" . PHP_EOL;
exit($fail > 0 ? 1 : 0);
