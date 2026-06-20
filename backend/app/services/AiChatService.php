<?php

require_once __DIR__ . '/../models/SanPham.php';
require_once __DIR__ . '/../models/TaiKhoan.php';
require_once __DIR__ . '/HttpJsonClient.php';
require_once __DIR__ . '/RedisChatCache.php';
require_once __DIR__ . '/AiChatCommerce.php';

class AiChatService {
    private SanPham $products;
    private TaiKhoan $accounts;
    private RedisChatCache $responseCache;
    private AiChatCommerce $commerce;
    private $pdo;

    public function __construct($pdo) {
        $this->pdo = $pdo;
        $this->products = new SanPham($pdo);
        $this->accounts = new TaiKhoan($pdo);
        $this->responseCache = new RedisChatCache();
        $this->commerce = new AiChatCommerce($pdo);
    }


    private function isComplexQuery(string $message): bool {
        $m = function_exists('mb_strtolower') ? mb_strtolower($message, 'UTF-8') : strtolower($message);
        $signals = [
            ' và ', ' + ', ' cộng ', ' kèm ',
            'dùng chung', 'kết hợp', 'xung đột', 'có dùng được không', 'dùng được không',
            'có kết hợp', 'có thể dùng', 'trộn',
            'routine', 'chu trình', 'bộ sản phẩm', 'bước dưỡng',
            'tổng dưới', 'tổng không quá', 'ngân sách tổng',
            'retinol', 'retinoid', 'tretinoin', 'aha', 'bha', 'vitamin c',
            'niacinamide', 'peptide', 'ceramide', 'bakuchiol',
            'thành phần', 'ingredient', 'công thức', 'chi tiết sản phẩm',
        ];
        $hits = 0;
        foreach ($signals as $signal) {
            if (str_contains($m, $signal)) {
                $hits++;
            }
        }
        return $hits >= 2;
    }

    private function shouldUseResponseCache(string $message, array $history, array $cartItems, array $conflicts): bool {
        if (!$this->responseCache->isEnabled()) {
            return false;
        }
        if ($history !== []) {
            return false;
        }
        if ($cartItems !== []) {
            return false;
        }
        if ($conflicts !== []) {
            return false;
        }
        if ($this->isComplexQuery($message)) {
            return false;
        }
        return in_array($this->detectIntent($message), ['ingredient_analysis', 'general', 'product_recommendation'], true);
    }

    private function getCachedResponsePayload(string $message, string $currentProductId, array $profile, array $history, array $cartItems, array $conflicts): ?array {
        if (!$this->shouldUseResponseCache($message, $history, $cartItems, $conflicts)) {
            return null;
        }
        $cached = $this->responseCache->get(
            $this->responseCache->buildCacheKey($message, $currentProductId, $profile)
        );
        if (!is_array($cached)) {
            return null;
        }
        $cached['cached'] = true;
        $cached['mode'] = (string)($cached['mode'] ?? 'pipeline');
        $cached['latency_ms'] = 0;
        return $cached;
    }

    private function storeResponsePayload(string $message, array $payload, string $currentProductId, array $profile, array $history, array $cartItems, array $conflicts): void {
        if (!$this->shouldUseResponseCache($message, $history, $cartItems, $conflicts)) {
            return;
        }
        $answer = trim((string)($payload['answer'] ?? ''));
        if ($answer === '' || ($payload['ok'] ?? true) === false) {
            return;
        }
        $this->responseCache->set(
            $this->responseCache->buildCacheKey($message, $currentProductId, $profile),
            $payload
        );
    }

    private function emitCachedStream(array $payload): void {
        $answer = trim((string)($payload['answer'] ?? ''));
        echo 'data: ' . json_encode(['type' => 'status', 'message' => 'Đã có câu trả lời trước đó'], JSON_UNESCAPED_UNICODE) . "\n\n";
        echo 'data: ' . json_encode(['type' => 'token', 'delta' => $answer], JSON_UNESCAPED_UNICODE) . "\n\n";
        echo 'data: ' . json_encode([
            'type' => 'done',
            'products' => is_array($payload['products'] ?? null) ? $payload['products'] : [],
            'conflicts' => is_array($payload['conflicts'] ?? null) ? $payload['conflicts'] : [],
            'suggestions' => is_array($payload['suggestions'] ?? null) ? $payload['suggestions'] : [],
            'intent' => (string)($payload['intent'] ?? ''),
            'analysis' => is_array($payload['analysis'] ?? null) ? $payload['analysis'] : [],
            'cart_actions' => is_array($payload['cart_actions'] ?? null) ? $payload['cart_actions'] : [],
            'commerce' => is_array($payload['commerce'] ?? null) ? $payload['commerce'] : null,
            'cached' => true,
        ], JSON_UNESCAPED_UNICODE) . "\n\n";
        echo "data: [DONE]\n\n";
    }

    private function emitLocalStream(array $payload): void {
        $answer = trim((string)($payload['answer'] ?? ''));
        $statusMessage = trim((string)($payload['status'] ?? 'Đang phân tích giỏ hàng...'));
        echo 'data: ' . json_encode(['type' => 'status', 'message' => $statusMessage], JSON_UNESCAPED_UNICODE) . "\n\n";
        echo 'data: ' . json_encode(['type' => 'token', 'delta' => $answer], JSON_UNESCAPED_UNICODE) . "\n\n";
        echo 'data: ' . json_encode([
            'type' => 'done',
            'products' => is_array($payload['products'] ?? null) ? $payload['products'] : [],
            'conflicts' => is_array($payload['conflicts'] ?? null) ? $payload['conflicts'] : [],
            'suggestions' => is_array($payload['suggestions'] ?? null) ? $payload['suggestions'] : [],
            'intent' => (string)($payload['intent'] ?? 'CART_ANALYSIS'),
            'analysis' => is_array($payload['analysis'] ?? null) ? $payload['analysis'] : ['mode' => 'local', 'intent' => 'CART_ANALYSIS'],
            'cart_actions' => is_array($payload['cart_actions'] ?? null) ? $payload['cart_actions'] : [],
            'commerce' => is_array($payload['commerce'] ?? null) ? $payload['commerce'] : null,
            'mode' => (string)($payload['mode'] ?? 'local'),
        ], JSON_UNESCAPED_UNICODE) . "\n\n";
        echo "data: [DONE]\n\n";
    }

    private function isCartAnalysisQuery(string $message): bool {
        $normalized = function_exists('mb_strtolower') ? mb_strtolower($message, 'UTF-8') : strtolower($message);
        if (preg_match(
            '/phan tich.*gio hang|phân tích.*giỏ hàng|gio hang.*xung|giỏ hàng.*xung|canh bao.*xung|cảnh báo.*xung|'
            . 'xung dot.*gio|xung đột.*giỏ|routine hien tai|routine hiện tại|phan tich nhanh gio hang|phân tích nhanh giỏ hàng/u',
            $normalized
        ) === 1) {
            return true;
        }

        $hasCart = str_contains($normalized, 'gio hang') || str_contains($normalized, 'giỏ hàng');
        $hasSignal = str_contains($normalized, 'xung dot')
            || str_contains($normalized, 'xung đột')
            || str_contains($normalized, 'phan tich')
            || str_contains($normalized, 'phân tích')
            || str_contains($normalized, 'canh bao')
            || str_contains($normalized, 'cảnh báo');

        return $hasCart && $hasSignal;
    }

    private function buildCartAnalysisResponse(array $cartItems, array $conflicts, array $profile = []): string {
        if ($cartItems === []) {
            return implode("\n", [
                'Giỏ hàng của bạn đang trống.',
                '',
                'Thêm sản phẩm vào giỏ rồi mình sẽ phân tích xung đột hoạt chất giúp bạn nhé.',
            ]);
        }

        $lines = [
            '**Phân tích nhanh giỏ hàng** (' . count($cartItems) . ' sản phẩm)',
            '',
        ];
        $total = 0;

        foreach ($cartItems as $item) {
            if (!is_array($item)) {
                continue;
            }
            $name = trim((string)($item['name'] ?? ''));
            $qty = max(1, (int)($item['qty'] ?? 1));
            $price = (int)($item['price'] ?? 0);
            $total += $price * $qty;
            $suffix = $qty > 1 ? (' ×' . $qty) : '';
            $lines[] = '• **' . $name . '**' . $suffix . ' — ' . number_format($price, 0, ',', '.') . ' đ';
        }

        $lines[] = '';
        $lines[] = 'Tạm tính: **' . number_format($total, 0, ',', '.') . ' đ**';

        $skinType = trim((string)($profile['skin_type'] ?? ''));
        if ($skinType !== '') {
            $lines[] = 'Loại da hồ sơ: **' . $skinType . '**';
        }

        $lines[] = '';
        if ($conflicts === []) {
            $lines[] = '✅ **Không phát hiện xung đột hoạt chất** giữa các sản phẩm trong giỏ '
                . '(retinol/Vit C, retinol/AHA-BHA, BPO/retinol, AHA-BHA/Vit C).';
            $lines[] = 'Bạn vẫn nên patch test và tăng tần suất dần nếu da nhạy cảm.';
            return implode("\n", $lines);
        }

        $lines[] = '⚠️ **Phát hiện ' . count($conflicts) . ' cặp có thể xung đột:**';
        foreach ($conflicts as $conflict) {
            if (!is_array($conflict)) {
                continue;
            }
            $productA = trim((string)($conflict['product_a'] ?? ''));
            $productB = trim((string)($conflict['product_b'] ?? ''));
            $warning = trim((string)($conflict['warning'] ?? ''));
            $recommendation = trim((string)($conflict['recommendation'] ?? ''));
            $lines[] = '';
            if ($productA !== '' && $productB !== '') {
                $lines[] = '**' . $productA . '** ↔ **' . $productB . '**';
            }
            if ($warning !== '') {
                $lines[] = '- ' . $warning;
            }
            if ($recommendation !== '') {
                $lines[] = '- Gợi ý: ' . $recommendation;
            }
        }

        return implode("\n", $lines);
    }

    /**
     * Bổ sung hồ sơ da từ phân tích chat khi người dùng chưa có (hoặc thiếu) dữ liệu.
     * Chỉ ghi các trường còn trống; không ghi đè loại da / ngân sách đã lưu.
     */
    private function maybeMergeProfileFromAnalysis(string $email, array $analysis, array $profile): ?array {
        $email = trim($email);
        if ($email === '' || $analysis === []) {
            return null;
        }

        $intent = trim((string)($analysis['intent'] ?? ''));
        if ($intent !== '' && $intent !== 'PRODUCT_INQUIRY') {
            return null;
        }

        $existingLoaiDa = trim((string)($profile['skin_type'] ?? ''));
        $existingConcerns = array_values($profile['concerns'] ?? []);
        $existingBudget = (int)($profile['budget'] ?? 0);

        $newLoaiDa = trim((string)($analysis['loai_da'] ?? ''));
        if ($newLoaiDa === '' || mb_strtolower($newLoaiDa, 'UTF-8') === 'unknown') {
            $newLoaiDa = '';
        }

        $newConcerns = [];
        $rawConcerns = $analysis['tinh_trang_da'] ?? null;
        if (is_array($rawConcerns)) {
            foreach ($rawConcerns as $item) {
                $part = trim((string)$item);
                if ($part !== '') {
                    $newConcerns[] = $part;
                }
            }
        }

        $newBudget = (int)($analysis['ngan_sach'] ?? 0);
        if ($newBudget <= 0) {
            $newBudget = 0;
        }

        $mergedLoaiDa = $existingLoaiDa !== '' ? $existingLoaiDa : $newLoaiDa;
        $mergedConcerns = array_values(array_unique(array_merge($existingConcerns, $newConcerns)));
        $mergedBudget = $existingBudget > 0 ? $existingBudget : $newBudget;

        $changed = ($existingLoaiDa === '' && $newLoaiDa !== '')
            || count($mergedConcerns) > count($existingConcerns)
            || ($existingBudget === 0 && $newBudget > 0);

        if (!$changed || $mergedLoaiDa === '') {
            return null;
        }

        $user = current_user() ?? [];
        $hoTen = trim((string)($user['ho_ten'] ?? $profile['display_name'] ?? 'bạn'));
        $ok = $this->accounts->saveSkinProfileByEmail($hoTen, $email, $mergedLoaiDa, $mergedConcerns, $mergedBudget > 0 ? $mergedBudget : null);

        if (!$ok) {
            return null;
        }

        return [
            'profile_updated' => true,
            'skin_type' => $mergedLoaiDa,
            'concerns' => $mergedConcerns,
            'budget' => $mergedBudget,
        ];
    }

    private function enrichPayloadWithProfileMerge(array $payload, string $email, array $profile): array {
        $analysis = is_array($payload['analysis'] ?? null) ? $payload['analysis'] : [];
        if ($analysis === [] && !empty($payload['intent'])) {
            $analysis['intent'] = (string)$payload['intent'];
        }

        $merge = $this->maybeMergeProfileFromAnalysis($email, $analysis, $profile);
        if ($merge === null) {
            return $payload;
        }

        $payload['profile_updated'] = true;
        $note = 'Mình đã ghi nhận hồ sơ da từ cuộc trò chuyện (loại da: ' . $merge['skin_type'] . '). Bạn xem lại tại trang Hồ sơ.';
        $suggestions = is_array($payload['suggestions'] ?? null) ? $payload['suggestions'] : [];
        if (!in_array($note, $suggestions, true)) {
            $suggestions[] = $note;
        }
        $payload['suggestions'] = $suggestions;

        return $payload;
    }

    private function splitProfileValues(?string $raw): array {
        $text = trim((string)($raw ?? ''));
        if ($text === '') {
            return [];
        }
        $parts = preg_split('/\s*[,|]\s*/u', $text) ?: [];
        $values = [];
        foreach ($parts as $part) {
            $part = trim($part);
            if ($part !== '') {
                $values[] = $part;
            }
        }
        return array_values(array_unique($values));
    }

    private function loadChatProfile(string $email): array {
        $email = trim($email);
        if ($email === '') {
            return [];
        }
        $khachHang = $this->accounts->getKhachHangByEmail($email);
        if (!$khachHang) {
            return [];
        }
        $skinProfile = $this->accounts->getSkinProfileByEmail($email) ?? [];
        $concerns = $this->splitProfileValues($khachHang['van_de_da'] ?? null);
        $avoidIngredients = $this->splitProfileValues($khachHang['thanh_phan_tranh'] ?? null);
        $avoidIngredients = array_values(array_filter($avoidIngredients, static function (string $item): bool {
            $normalized = mb_strtolower($item, 'UTF-8');
            return $normalized !== 'không có / không quan tâm' && $normalized !== 'khong co';
        }));
        $budget = isset($khachHang['ngan_sach']) && $khachHang['ngan_sach'] !== null
            ? (int)$khachHang['ngan_sach']
            : 0;
        return [
            'customer_id' => (int)($khachHang['ma_kh'] ?? 0),
            'display_name' => trim((string)($khachHang['ho_ten'] ?? 'bạn')),
            'skin_type' => trim((string)($skinProfile['loai_da'] ?? '')),
            'concerns' => $concerns,
            'avoid_ingredients' => $avoidIngredients,
            'budget' => $budget,
        ];
    }

    private function getTimeout(): int {
        $configured = defined('AI_CHAT_TIMEOUT') ? (int)AI_CHAT_TIMEOUT : 0;
        if ($configured > 0) {
            return $configured;
        }

        $envValue = getenv('AI_CHAT_TIMEOUT');
        if ($envValue !== false && ctype_digit((string)$envValue)) {
            return max(5, (int)$envValue);
        }

        return 60;
    }

    private function getAutoEndpoint(): string {
        $configured = defined('AI_CHAT_ENDPOINT') ? trim((string)AI_CHAT_ENDPOINT) : '';
        if ($configured !== '') {
            return $configured;
        }

        $envValue = getenv('AI_CHAT_ENDPOINT');
        if ($envValue !== false && trim((string)$envValue) !== '') {
            return trim((string)$envValue);
        }

        return 'http://127.0.0.1:5001/api/chat/auto';
    }

    private function getStreamEndpoint(): string {
        $configured = defined('AI_CHAT_STREAM_ENDPOINT') ? trim((string)AI_CHAT_STREAM_ENDPOINT) : '';
        if ($configured !== '') {
            return $configured;
        }

        $envValue = getenv('AI_CHAT_STREAM_ENDPOINT');
        if ($envValue !== false && trim((string)$envValue) !== '') {
            return trim((string)$envValue);
        }

        return 'http://127.0.0.1:5001/api/chat/stream';
    }

    private function buildSessionId(): string {
        $user = current_user() ?? [];
        $email = trim((string)($user['email'] ?? ''));
        if ($email !== '') {
            return 'user-' . hash('sha256', strtolower($email));
        }

        if (!empty($_SESSION['ai_chat_guest_id'])) {
            return (string)$_SESSION['ai_chat_guest_id'];
        }

        $_SESSION['ai_chat_guest_id'] = 'guest-' . bin2hex(random_bytes(8));
        return (string)$_SESSION['ai_chat_guest_id'];
    }

    private function buildFlaskPayload(
        string $message,
        array $history,
        array $profile,
        array $cartItems,
        array $conflicts,
        array $products,
        string $currentProductId,
        string $sessionId
    ): array {
        $historyLines = [];
        foreach (array_slice($history, -10) as $turn) {
            if (!is_array($turn)) {
                continue;
            }
            $role = trim((string)($turn['role'] ?? 'user'));
            $content = trim((string)($turn['content'] ?? ''));
            if ($content === '') {
                continue;
            }
            $historyLines[] = [
                'sender' => $role === 'assistant' ? 'bot' : 'user',
                'text' => function_exists('mb_substr') ? mb_substr($content, 0, 500) : substr($content, 0, 500),
            ];
        }

        $profileSummary = [
            'customer_id' => (int)($profile['customer_id'] ?? 0),
            'skin_type' => (string)($profile['skin_type'] ?? ''),
            'skin_issues' => array_values($profile['concerns'] ?? []),
            'concerns' => array_values($profile['concerns'] ?? []),
            'avoid_ingredients' => array_values($profile['avoid_ingredients'] ?? []),
            'budget' => (int)($profile['budget'] ?? 0),
        ];

        return [
            'message' => $message,
            'customer_question' => $message,
            'session_id' => $sessionId,
            'conversation_history' => $historyLines,
            'current_product_id' => $currentProductId,
            'customer_profile' => $profileSummary,
            'cart_items' => $cartItems,
            'cart_conflicts' => $conflicts,
            'retrieved_products' => $products,
        ];
    }

    private function enrichProducts(array $products): array {
        $rows = [];
        $ids = [];

        foreach ($products as $row) {
            if (!is_array($row)) {
                continue;
            }
            $id = trim((string)($row['id'] ?? ''));
            if ($id === '') {
                continue;
            }
            $rows[] = $row;
            $ids[$id] = true;
        }

        if ($rows === []) {
            return [];
        }

        $details = $this->products->findByIds(array_keys($ids), true);
        $out = [];
        $seen = [];

        foreach ($rows as $row) {
            $id = trim((string)($row['id'] ?? ''));
            if ($id === '' || isset($seen[$id])) {
                continue;
            }
            $seen[$id] = true;

            $detail = $details[$id] ?? null;
            if (!$detail && ctype_digit($id)) {
                foreach ($details as $key => $candidate) {
                    if ((string)$key === $id || (string)(int)$key === $id) {
                        $detail = $candidate;
                        break;
                    }
                }
            }

            if ($detail && is_array($detail)) {
                $imageRaw = (string)($detail['link_hinh_anh'] ?? $detail['hinh_anh'] ?? $row['image_url'] ?? '');
                $out[] = [
                    'id' => $id,
                    'name' => trim((string)($detail['ten_san_pham'] ?? $row['name'] ?? $row['ten_san_pham'] ?? '')),
                    'brand' => trim((string)($detail['thuong_hieu'] ?? $row['brand'] ?? $row['thuong_hieu'] ?? '')),
                    'price' => (int)($detail['gia_ban'] ?? $row['price'] ?? $row['gia_ban'] ?? 0),
                    'image_url' => resolve_image_url($imageRaw),
                    'detail_url' => BASE_URL . '/index.php?r=chitiet&id=' . rawurlencode($id),
                    'description' => trim((string)($row['summary'] ?? $row['llm_explanation'] ?? $row['description'] ?? $detail['mo_ta'] ?? '')),
                    'ingredients' => $this->extractIngredientSource($detail),
                ];
                continue;
            }

            $mapped = $this->mapHybridProducts([$row]);
            if (!empty($mapped)) {
                $out[] = $mapped[0];
            }
        }

        return $out;
    }

    private function buildResponseFromFlask(
        ?array $decoded,
        array $localConflicts,
        array $localProducts,
        int $latencyMs = 0
    ): array {
        if (!is_array($decoded)) {
            return [
                'ok' => false,
                'answer' => 'Không kết nối được tới AI service. Vui lòng thử lại sau.',
                'conflicts' => $localConflicts,
                'products' => $this->enrichProducts($localProducts),
                'suggestions' => [],
                'intent' => '',
                'mode' => '',
                'route_reason' => '',
                'latency_ms' => $latencyMs,
                'fallback' => false,
                'fallback_reason' => '',
                'status_message' => '',
                'fallback_note' => '',
            ];
        }

        $answer = $this->trimAnswer((string)($decoded['answer'] ?? ''));
        $conflicts = !empty($decoded['conflicts']) && is_array($decoded['conflicts'])
            ? $decoded['conflicts']
            : $localConflicts;

        $products = $this->enrichProducts($localProducts);
        if (!empty($decoded['products']) && is_array($decoded['products'])) {
            $mapped = $this->enrichProducts($decoded['products']);
            if (!empty($mapped)) {
                $products = $mapped;
            }
        }

        $analysis = is_array($decoded['analysis'] ?? null) ? $decoded['analysis'] : [];
        $intent = trim((string)($decoded['intent'] ?? $analysis['intent'] ?? ''));
        $suggestions = is_array($decoded['suggestions'] ?? null) ? $decoded['suggestions'] : [];

        return [
            'ok' => ($decoded['ok'] ?? true) !== false,
            'answer' => $answer,
            'conflicts' => $conflicts,
            'products' => $products,
            'suggestions' => array_values(array_filter(array_map(static function ($item): string {
                return trim((string)$item);
            }, $suggestions), static function (string $item): bool {
                return $item !== '';
            })),
            'intent' => $intent,
            'analysis' => $analysis,
            'mode' => trim((string)($decoded['_mode'] ?? '')),
            'route_reason' => trim((string)($decoded['_route_reason'] ?? '')),
            'latency_ms' => (int)($decoded['latency_ms'] ?? $latencyMs),
            'fallback' => false,
            'fallback_reason' => '',
            'status_message' => '',
            'fallback_note' => '',
            'cart_actions' => is_array($decoded['cart_actions'] ?? null) ? $decoded['cart_actions'] : [],
            'commerce' => is_array($decoded['commerce'] ?? null) ? $decoded['commerce'] : null,
            'profile_updated' => false,
        ];
    }

    private function enrichCommercePayload(array $payload, string $message): array {
        $cartActions = is_array($payload['cart_actions'] ?? null) ? $payload['cart_actions'] : [];
        if ($cartActions === []) {
            return $payload;
        }

        $items = [];
        foreach ($cartActions as $action) {
            if (!is_array($action)) {
                continue;
            }
            $id = trim((string)($action['product_id'] ?? $action['id'] ?? ''));
            if ($id === '') {
                continue;
            }
            $items[] = [
                'id' => $id,
                'qty' => max(1, (int)($action['qty'] ?? 1)),
            ];
        }

        if ($items === []) {
            return $payload;
        }

        $addResult = $this->commerce->addItems($items);
        if (($addResult['ok'] ?? false) === true) {
            $payload['commerce'] = [
                'action' => 'cart_updated',
                'cart' => $addResult['cart'] ?? [],
                'added' => $addResult['added'] ?? [],
            ];
            if (empty($payload['answer']) || !str_contains((string)$payload['answer'], 'giỏ')) {
                $payload['answer'] = trim((string)($payload['answer'] ?? ''))
                    . "\n\n✅ " . (string)($addResult['message'] ?? 'Đã cập nhật giỏ hàng.');
            }
        }

        return $payload;
    }

    private function prepareContext(string $message, array $history, string $currentProductId): array {
        $user = current_user() ?? [];
        $email = trim((string)($user['email'] ?? ''));
        $profile = $email !== '' ? ($this->loadChatProfile($email)) : [];
        $cartItems = $this->buildCartContext();
        $conflicts = $this->detectCartIngredientConflicts($cartItems);

        $products = [];
        if ($currentProductId !== '') {
            $detail = $this->products->findById($currentProductId, true);
            if ($detail && is_array($detail)) {
                $products[] = [
                    'id' => (string)($detail['ma_san_pham'] ?? $currentProductId),
                    'name' => trim((string)($detail['ten_san_pham'] ?? '')),
                    'brand' => trim((string)($detail['thuong_hieu'] ?? '')),
                    'price' => (int)($detail['gia_ban'] ?? 0),
                    'image_url' => resolve_image_url((string)($detail['link_hinh_anh'] ?? $detail['hinh_anh'] ?? '')),
                    'detail_url' => BASE_URL . '/index.php?r=chitiet&id=' . rawurlencode((string)($detail['ma_san_pham'] ?? $currentProductId)),
                    'description' => trim((string)($detail['mo_ta'] ?? '')),
                    'ingredients' => $this->extractIngredientSource($detail),
                ];
            }
        }

        $sessionId = $this->buildSessionId();
        $flaskPayload = $this->buildFlaskPayload(
            $message,
            $history,
            $profile,
            $cartItems,
            $conflicts,
            $products,
            $currentProductId,
            $sessionId
        );

        return [
            'profile' => $profile,
            'cartItems' => $cartItems,
            'conflicts' => $conflicts,
            'products' => $products,
            'sessionId' => $sessionId,
            'flaskPayload' => $flaskPayload,
        ];
    }

    private function parseRequestBody(): ?array {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            return null;
        }

        $raw = file_get_contents('php://input');
        $data = json_decode((string)$raw, true);
        if (!is_array($data)) {
            return null;
        }

        $message = trim((string)($data['message'] ?? ''));
        if ($message === '') {
            return null;
        }

        return [
            'message' => $message,
            'history' => is_array($data['history'] ?? null) ? $data['history'] : [],
            'currentProductId' => isset($data['current_product_id']) ? trim((string)$data['current_product_id']) : '',
            'preferStream' => !empty($data['prefer_stream']),
        ];
    }

    private function proxyStream(array $payload, string $email = '', array $profile = []): void {
        $url = $this->getStreamEndpoint();
        $body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($body === false) {
            echo 'data: ' . json_encode(['type' => 'error', 'message' => 'Payload không hợp lệ.'], JSON_UNESCAPED_UNICODE) . "\n\n";
            echo "data: [DONE]\n\n";
            return;
        }

        if (!function_exists('curl_init')) {
            echo 'data: ' . json_encode(['type' => 'error', 'message' => 'Streaming không khả dụng trên server.'], JSON_UNESCAPED_UNICODE) . "\n\n";
            echo "data: [DONE]\n\n";
            return;
        }

        $service = $this;
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Accept: text/event-stream',
            'Content-Type: application/json',
        ]);
        curl_setopt($ch, CURLOPT_TIMEOUT, max(120, $this->getTimeout()));
        curl_setopt($ch, CURLOPT_WRITEFUNCTION, static function ($handle, string $chunk) use ($service, $email, $profile): int {
            if ($email !== '' && str_contains($chunk, '"type"')) {
                $parts = preg_split("/\r?\n\r?\n/", $chunk) ?: [];
                $out = '';
                foreach ($parts as $part) {
                    if ($part === '') {
                        continue;
                    }
                    $lines = explode("\n", $part);
                    $rewritten = [];
                    foreach ($lines as $line) {
                        if (str_starts_with($line, 'data:')) {
                            $raw = trim(substr($line, 5));
                            if ($raw !== '' && $raw !== '[DONE]') {
                                $decoded = json_decode($raw, true);
                                if (is_array($decoded) && ($decoded['type'] ?? '') === 'done') {
                                    $analysis = is_array($decoded['analysis'] ?? null) ? $decoded['analysis'] : [];
                                    if ($analysis !== []) {
                                        $enriched = $service->enrichPayloadWithProfileMerge([
                                            'analysis' => $analysis,
                                            'intent' => (string)($decoded['intent'] ?? $analysis['intent'] ?? ''),
                                            'suggestions' => is_array($decoded['suggestions'] ?? null) ? $decoded['suggestions'] : [],
                                        ], $email, $profile);
                                        if (!empty($enriched['profile_updated'])) {
                                            $decoded['profile_updated'] = true;
                                            $decoded['suggestions'] = $enriched['suggestions'] ?? $decoded['suggestions'];
                                        }
                                    }
                                    $raw = json_encode($decoded, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                                    $line = 'data: ' . $raw;
                                }
                            }
                        }
                        $rewritten[] = $line;
                    }
                    $out .= implode("\n", $rewritten) . "\n\n";
                }
                $chunk = $out !== '' ? $out : $chunk;
            }

            echo $chunk;
            if (function_exists('ob_get_level') && ob_get_level() > 0) {
                @ob_flush();
            }
            @flush();
            return strlen($chunk);
        });
        curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($status < 200 || $status >= 300) {
            echo 'data: ' . json_encode(['type' => 'error', 'message' => 'AI stream service không phản hồi.'], JSON_UNESCAPED_UNICODE) . "\n\n";
            echo "data: [DONE]\n\n";
        }
    }

    private function normalizeIngredientText(?string $value): string {
        $text = trim((string)($value ?? ''));
        if ($text === '') {
            return '';
        }

        $text = function_exists('mb_strtolower') ? mb_strtolower($text, 'UTF-8') : strtolower($text);
        $text = str_replace(['\r', '\n', ';', '|'], ', ', $text);
        return preg_replace('/\s+/u', ' ', $text) ?? $text;
    }

    private function extractIngredientSource(array $product): string {
        $candidates = [
            $product['thanh_phan_day_du'] ?? null,
            $product['thanh_phan_chinh'] ?? null,
            $product['thanh_phan_clean'] ?? null,
            $product['mo_ta'] ?? null,
        ];

        foreach ($candidates as $candidate) {
            $normalized = $this->normalizeIngredientText((string)$candidate);
            if ($normalized !== '') {
                return $normalized;
            }
        }

        return '';
    }

    private function buildCartContext(): array {
        $cart = $_SESSION['gio_hang'] ?? [];
        if (!is_array($cart) || empty($cart)) {
            return [];
        }

        $details = $this->products->findByIds(array_map('strval', array_keys($cart)), false);
        $items = [];

        foreach ($cart as $productId => $qty) {
            $id = trim((string)$productId);
            $product = $details[$id] ?? null;
            if (!$product && ctype_digit($id)) {
                foreach ($details as $key => $candidate) {
                    if ((string)$key === $id) {
                        $product = $candidate;
                        break;
                    }
                }
            }
            if (!$product || !is_array($product)) {
                continue;
            }

            $items[] = [
                'id' => (string)($product['ma_san_pham'] ?? $productId),
                'name' => trim((string)($product['ten_san_pham'] ?? '')),
                'brand' => trim((string)($product['thuong_hieu'] ?? '')),
                'qty' => max(1, (int)$qty),
                'ingredients' => $this->extractIngredientSource($product),
                'price' => (int)($product['gia_ban'] ?? 0),
            ];
        }

        return $items;
    }

    private function detectCartIngredientConflicts(array $cartItems): array {
        $rules = [
            [
                'id' => 'retinol_vitamin_c',
                'left_patterns' => ['retinol', 'retinal', 'tretinoin', 'adapalene'],
                'right_patterns' => ['vitamin c', 'ascorbic acid', 'l-ascorbic acid', 'ethyl ascorbic acid'],
                'warning' => 'Retinol và Vitamin C mạnh có thể làm routine dễ kích ứng nếu dùng cùng buổi.',
                'recommendation' => 'Nên tách sáng và tối, hoặc tăng tần suất từ từ nếu da nhạy cảm.',
            ],
            [
                'id' => 'retinol_aha_bha',
                'left_patterns' => ['retinol', 'retinal', 'tretinoin', 'adapalene'],
                'right_patterns' => ['aha', 'bha', 'salicylic acid', 'glycolic acid', 'lactic acid', 'mandelic acid'],
                'warning' => 'Retinol đi cùng AHA/BHA dễ làm da châm chích, bong tróc hoặc đỏ rát.',
                'recommendation' => 'Nên luân phiên ngày dùng hoặc giảm nồng độ của một trong hai nhóm.',
            ],
            [
                'id' => 'benzoyl_peroxide_retinol',
                'left_patterns' => ['benzoyl peroxide'],
                'right_patterns' => ['retinol', 'retinal', 'tretinoin', 'adapalene'],
                'warning' => 'Benzoyl Peroxide có thể làm routine với retinoid khô và kích ứng hơn.',
                'recommendation' => 'Nên tách buổi hoặc dùng cách ngày để da dễ thích nghi hơn.',
            ],
            [
                'id' => 'aha_bha_vitamin_c',
                'left_patterns' => ['aha', 'bha', 'salicylic acid', 'glycolic acid', 'lactic acid', 'mandelic acid'],
                'right_patterns' => ['vitamin c', 'ascorbic acid', 'l-ascorbic acid'],
                'warning' => 'AHA/BHA và Vitamin C acid mạnh dùng chung có thể làm da nhạy cảm hơn.',
                'recommendation' => 'Nên tách lịch sử dụng nếu bạn mới bắt đầu treatment hoặc da dễ kích ứng.',
            ],
        ];

        $matchesPatternGroup = static function (string $haystack, array $patterns): bool {
            foreach ($patterns as $pattern) {
                if ($pattern !== '' && str_contains($haystack, $pattern)) {
                    return true;
                }
            }
            return false;
        };

        $conflicts = [];
        for ($i = 0, $count = count($cartItems); $i < $count; $i++) {
            for ($j = $i + 1; $j < $count; $j++) {
                $left = $cartItems[$i];
                $right = $cartItems[$j];
                $leftIngredients = $this->normalizeIngredientText((string)($left['ingredients'] ?? ''));
                $rightIngredients = $this->normalizeIngredientText((string)($right['ingredients'] ?? ''));

                if ($leftIngredients === '' || $rightIngredients === '') {
                    continue;
                }

                foreach ($rules as $rule) {
                    $directMatch = $matchesPatternGroup($leftIngredients, $rule['left_patterns'])
                        && $matchesPatternGroup($rightIngredients, $rule['right_patterns']);
                    $reverseMatch = $matchesPatternGroup($leftIngredients, $rule['right_patterns'])
                        && $matchesPatternGroup($rightIngredients, $rule['left_patterns']);

                    if (!$directMatch && !$reverseMatch) {
                        continue;
                    }

                    $conflicts[] = [
                        'id' => $rule['id'],
                        'product_a' => (string)($left['name'] ?? ''),
                        'product_b' => (string)($right['name'] ?? ''),
                        'warning' => (string)$rule['warning'],
                        'recommendation' => (string)$rule['recommendation'],
                    ];
                }
            }
        }

        $unique = [];
        foreach ($conflicts as $conflict) {
            $key = implode('|', [$conflict['id'], $conflict['product_a'], $conflict['product_b']]);
            $unique[$key] = $conflict;
        }

        return array_values($unique);
    }


    private function mapHybridProducts(array $rows): array {
        $out = [];
        foreach ($rows as $r) {
            if (!is_array($r)) {
                continue;
            }
            $id = trim((string)($r['id'] ?? ''));
            if ($id === '') {
                continue;
            }
            $out[] = [
                'id' => $id,
                'name' => trim((string)($r['ten_san_pham'] ?? $r['name'] ?? '')),
                'brand' => trim((string)($r['thuong_hieu'] ?? $r['brand'] ?? '')),
                'price' => (int)($r['gia_ban'] ?? $r['price'] ?? 0),
                'image_url' => resolve_image_url((string)($r['image_url'] ?? $r['link_hinh_anh'] ?? '')),
                'detail_url' => BASE_URL . '/index.php?r=chitiet&id=' . rawurlencode($id),
                'description' => trim((string)($r['llm_explanation'] ?? $r['description'] ?? '')),
                'ingredients' => '',
            ];
        }

        return $out;
    }

    private function shouldAttachProducts(string $message, array $conflicts = []): bool {
        $normalized = function_exists('mb_strtolower') ? mb_strtolower($message, 'UTF-8') : strtolower($message);

        if (!empty($conflicts) && preg_match('/gio hang|giỏ hàng|routine|xung dot|xung đột|ket hop|kết hợp/u', $normalized)) {
            return true;
        }

        return preg_match(
            '/goi y|gợi ý|de xuat|đề xuất|nen mua|nên mua|nen dung|nên dùng|chon giup|chọn giúp|san pham nao|sản phẩm nào|serum nao|kem nao|toner nao|sua rua mat nao|tẩy trang nào|kem chống nắng nào|compare|so sanh|so sánh|dua ra vai san pham|đưa ra vài sản phẩm|chu trình|chu trinh|skincare|routine|bộ dưỡng|bo duong/u',
            $normalized
        ) === 1;
    }

    private function trimAnswer(string $answer): string {
        $answer = trim($answer);
        if ($answer === '') {
            return '';
        }

        $answer = preg_replace('/\n{3,}/', "\n\n", $answer) ?? $answer;

        $paragraphs = preg_split('/\n\s*\n/u', $answer) ?: [];
        $paragraphs = array_values(array_filter(array_map('trim', $paragraphs), static function (string $part): bool {
            return $part !== '';
        }));

        return trim(implode("\n\n", $paragraphs));
    }


    private function detectIntent(string $message): string {
        $normalized = function_exists('mb_strtolower') ? mb_strtolower($message, 'UTF-8') : strtolower($message);

        if (preg_match('/xin chao|chao ai|hello|\bhi\b/u', $normalized)) {
            return 'greeting';
        }

        if (preg_match('/dat hang|đặt hàng|dat don|đặt đơn|mua ngay|checkout|thanh toan|thanh toán/u', $normalized)) {
            return 'order';
        }

        if (preg_match('/gio hang|giỏ hàng|xung dot|xung đột|ket hop|kết hợp|routine hiện tại|routine hien tai|phan tich nhanh gio hang|phân tích nhanh giỏ hàng/u', $normalized)) {
            return 'cart_conflict';
        }

        if (preg_match('/thanh phan|thành phần|ingredient|retinol|vitamin c|aha|bha|niacinamide|ceramide|treatment/u', $normalized)) {
            return 'ingredient_analysis';
        }

        if ($this->shouldAttachProducts($message)) {
            return 'product_recommendation';
        }

        return 'general';
    }

    private function isGreetingMessage(string $message): bool {
        $normalized = function_exists('mb_strtolower') ? mb_strtolower($message, 'UTF-8') : strtolower($message);
        return preg_match('/^\s*(xin chao|xin chào|chao|hello|hi|hey|alo|ad ơi|ai ơi)\s*[!,.?]*\s*$/u', $normalized) === 1;
    }

    private function buildDefaultGreetingResponse(array $profile = []): string {
        $displayName = trim((string)($profile['display_name'] ?? ''));
        $salutation = $displayName !== '' ? ('Xin chào ' . $displayName . ',') : 'Xin chào,';

        return implode("\n", [
            $salutation,
            'mình là SkinSyntax AI. Mình có thể hỗ trợ phân tích thành phần, kiểm tra routine, cảnh báo xung đột trong giỏ hàng, gợi ý sản phẩm và **đặt hàng ngay trong chat**.',
            'Bạn có thể hỏi tiếp như: retinol dùng sao cho an toàn, "cho tôi 2 serum vitamin C", hoặc "xem giỏ hàng".'
        ]);
    }

    public function handleAssistant(): void {
        header('Content-Type: application/json; charset=utf-8');
        @set_time_limit(120);
        @ini_set('max_execution_time', '120');

        $request = $this->parseRequestBody();
        if ($request === null) {
            http_response_code($_SERVER['REQUEST_METHOD'] !== 'POST' ? 405 : 400);
            echo json_encode([
                'ok' => false,
                'message' => $_SERVER['REQUEST_METHOD'] !== 'POST' ? 'Method not allowed.' : 'Payload không hợp lệ.',
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            return;
        }

        $message = $request['message'];
        $history = $request['history'];
        $currentProductId = $request['currentProductId'];

        $user = current_user() ?? [];
        $email = trim((string)($user['email'] ?? ''));
        $profile = $email !== '' ? ($this->loadChatProfile($email)) : [];
        $cartItems = $this->buildCartContext();
        $conflicts = $this->detectCartIngredientConflicts($cartItems);

        if ($this->isGreetingMessage($message)) {
            $payload = [
                'ok' => true,
                'answer' => $this->buildDefaultGreetingResponse($profile),
                'conflicts' => [],
                'products' => [],
                'suggestions' => [],
                'intent' => 'CHITCHAT',
                'mode' => 'local',
                'route_reason' => '',
                'latency_ms' => 0,
                'fallback' => false,
                'cart_actions' => [],
                'commerce' => null,
            ];
            echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            return;
        }

        $localCommerce = $this->commerce->tryLocalCommerceReply($message);
        if ($localCommerce !== null) {
            $localCommerce['products'] = [];
            $localCommerce['conflicts'] = $conflicts;
            $localCommerce['suggestions'] = [];
            $localCommerce['mode'] = 'local';
            $localCommerce['latency_ms'] = 0;
            $localCommerce['cart_actions'] = [];
            echo json_encode($localCommerce, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            return;
        }

        if ($this->isCartAnalysisQuery($message)) {
            echo json_encode([
                'ok' => true,
                'answer' => $this->buildCartAnalysisResponse($cartItems, $conflicts, $profile),
                'conflicts' => $conflicts,
                'products' => [],
                'suggestions' => [],
                'intent' => 'CART_ANALYSIS',
                'mode' => 'local',
                'route_reason' => 'cart analysis fast path',
                'latency_ms' => 0,
                'fallback' => false,
                'cart_actions' => [],
                'commerce' => null,
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            return;
        }

        $cachedPayload = $this->getCachedResponsePayload($message, $currentProductId, $profile, $history, $cartItems, $conflicts);
        if ($cachedPayload !== null) {
            echo json_encode($cachedPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            return;
        }

        $context = $this->prepareContext($message, $history, $currentProductId);
        $endpoint = $this->getAutoEndpoint();
        $startedAt = microtime(true);

        $response = HttpJsonClient::post($endpoint, $context['flaskPayload'], $this->getTimeout());
        $latencyMs = (int)round((microtime(true) - $startedAt) * 1000);
        $decoded = null;

        if ((int)($response['status'] ?? 0) >= 200 && (int)($response['status'] ?? 0) < 300) {
            $decoded = json_decode((string)($response['body'] ?? ''), true);
        }

        $payload = $this->buildResponseFromFlask(
            is_array($decoded) ? $decoded : null,
            $context['conflicts'],
            $context['products'],
            $latencyMs
        );
        $payload = $this->enrichCommercePayload($payload, $message);
        if ($email !== '') {
            $payload = $this->enrichPayloadWithProfileMerge($payload, $email, $profile);
        }

        if ($payload['answer'] === '') {
            $payload['ok'] = false;
            $payload['answer'] = 'Không kết nối được tới AI service. Vui lòng thử lại sau.';
        }

        if ($payload['ok'] === true && $payload['answer'] !== 'Không kết nối được tới AI service. Vui lòng thử lại sau.') {
            $this->storeResponsePayload($message, $payload, $currentProductId, $profile, $history, $cartItems, $conflicts);
        }

        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    public function handleStream(): void {
        @set_time_limit(0);
        @ini_set('max_execution_time', '0');

        header('Content-Type: text/event-stream; charset=utf-8');
        header('Cache-Control: no-cache, no-store, must-revalidate');
        header('Connection: keep-alive');
        header('X-Accel-Buffering: no');

        if (function_exists('apache_setenv')) {
            @apache_setenv('no-gzip', '1');
        }
        @ini_set('zlib.output_compression', '0');
        @ini_set('output_buffering', 'off');
        while (ob_get_level() > 0) {
            @ob_end_flush();
        }

        $request = $this->parseRequestBody();
        if ($request === null) {
            echo 'data: ' . json_encode([
                'type' => 'error',
                'message' => $_SERVER['REQUEST_METHOD'] !== 'POST' ? 'Method not allowed.' : 'Payload không hợp lệ.',
            ], JSON_UNESCAPED_UNICODE) . "\n\n";
            echo "data: [DONE]\n\n";
            return;
        }

        $context = $this->prepareContext($request['message'], $request['history'], $request['currentProductId']);

        if ($this->isCartAnalysisQuery($request['message'])) {
            $this->emitLocalStream([
                'answer' => $this->buildCartAnalysisResponse(
                    $context['cartItems'],
                    $context['conflicts'],
                    is_array($context['profile'] ?? null) ? $context['profile'] : []
                ),
                'conflicts' => $context['conflicts'],
                'products' => [],
                'intent' => 'CART_ANALYSIS',
                'mode' => 'local',
            ]);
            return;
        }

        $email = trim((string)(current_user()['email'] ?? ''));
        $this->proxyStream(
            $context['flaskPayload'],
            $email,
            is_array($context['profile'] ?? null) ? $context['profile'] : []
        );
    }


}
