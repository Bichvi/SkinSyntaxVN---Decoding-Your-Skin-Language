<?php
// backend/app/controllers/SanPhamController.php
require_once __DIR__ . '/../models/SanPham.php';
require_once __DIR__ . '/../models/TaiKhoan.php';
require_once __DIR__ . '/../models/QuanTri.php';
require_once __DIR__ . '/../models/DanhGia.php';
require_once __DIR__ . '/../models/HoiDap.php';
require_once __DIR__ . '/../services/ProductEventTracker.php';
require_once __DIR__ . '/../services/SearchSuggestService.php';

class SanPhamController {
    private const SEARCH_KEYWORD_MAX_LEN = 250;
    private const SEARCH_HISTORY_LIMIT = 8;
    private const VIDEO_REDIRECT_STATUSES = [301, 302, 303, 307, 308];

    private $pdo;
    private SanPham $model;

    public function __construct($pdo) {
        $this->pdo = $pdo;
        $this->model = new SanPham($pdo);
    }

    private function render($view, $data = []) {
        extract($data);
        try {
            $menuCats = $this->model->menuTree();
        } catch (Throwable $e) {
            error_log('layout menu MongoDB error: ' . $e->getMessage());
            $menuCats = [];
        }
        $viewDir = defined('VIEW_DIR') ? VIEW_DIR : __DIR__ . '/../views';
        require $viewDir . '/layouts/header.php';
        require $viewDir . '/' . $view . '.php';
        require $viewDir . '/layouts/footer.php';
    }

    private function cartCount(): int {
        $count = 0;
        foreach (($_SESSION['gio_hang'] ?? []) as $qty) {
            $count += max(0, (int)$qty);
        }
        return $count;
    }

    private function readCartRequestData(): array {
        $data = $_POST;
        $raw = file_get_contents('php://input');
        $json = [];

        if (empty($data) && is_string($raw) && trim($raw) !== '') {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                $json = $decoded;
                $data = $decoded;
            }
        }

        return [
            'data' => is_array($data) ? $data : [],
            'raw' => is_string($raw) ? $raw : '',
            'json' => $json,
        ];
    }

    /**
     * Tạo query tìm sản phẩm "linh hoạt" theo nhiều kiểu id khác nhau.
     * Đầu vào: $productId có thể là string/int, ma_san_pham/id/_id(ObjectId string).
     * Trả về: MongoDB filter dạng ['$or' => [...]] để tìm được cả "111" và 111.
     */
    private function buildProductIdQuery($productId): array {
        return $this->model->buildProductIdQuery($productId);
    }

    /**
     * Chuẩn hoá và đọc quantity từ nhiều tên field frontend có thể gửi.
     * Ưu tiên: qty / quantity / so_luong. Default: 1.
     */
    private function readRequestedQty(array $data = []): int {
        $raw = $data['quantity'] ?? $data['so_luong'] ?? $data['qty'] ?? $_GET['quantity'] ?? $_GET['so_luong'] ?? $_GET['qty'] ?? 1;
        return max(1, (int)$raw);
    }

    private function addToCartResult(string $id, int $qty, array $debugContext = []): array {
        $id = trim($id);
        $qty = max(1, $qty);
        if ($id === '') {
            error_log('add_to_cart missing_product_id: ' . json_encode([
                'route' => $_GET['r'] ?? '',
                'method' => $_SERVER['REQUEST_METHOD'] ?? '',
                'get' => $_GET,
                'post' => $_POST,
                'raw_input' => $debugContext['raw_input'] ?? '',
                'parsed_data' => $debugContext['parsed_data'] ?? [],
                'product_id' => $id,
                'qty' => $qty,
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            return ['ok' => false, 'message' => 'Thiếu mã sản phẩm.', 'cart_count' => $this->cartCount()];
        }

        $query = $this->buildProductIdQuery($id);
        $product = null;
        $stock = null;
        $stockStatus = null;
        $cartProductId = $id;
        try {
            // Tìm sản phẩm linh hoạt theo ma_san_pham/id/_id (string/int).
            // Không phụ thuộc duy nhất 1 field vì dữ liệu crawl có thể không đồng nhất kiểu.
            $product = $this->model->findById($id, true);
            if (!$product && isset($this->pdo->san_pham)) {
                // Fallback: query trực tiếp collection nếu model findById bị lệch field/kiểu.
                $product = $this->pdo->san_pham->findOne($query);
                if ($product) {
                    $product = (array)$product;
                }
            }

            if (!$product) {
                error_log('add_to_cart product not found: ' . json_encode([
                    'route' => $_GET['r'] ?? '',
                    'method' => $_SERVER['REQUEST_METHOD'] ?? '',
                    'get' => $_GET,
                    'post' => $_POST,
                    'raw_input' => $debugContext['raw_input'] ?? '',
                    'parsed_data' => $debugContext['parsed_data'] ?? [],
                    'product_id' => $id,
                    'qty' => $qty,
                    'query' => $query,
                    'found' => false,
                    'so_luong_ton_kho' => null,
                    'trang_thai_kho' => null,
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
                return ['ok' => false, 'message' => 'Không tìm thấy sản phẩm.', 'cart_count' => $this->cartCount()];
            }

            $productArray = (array)$product;
            $cartProductId = (string)($productArray['ma_san_pham'] ?? $productArray['id'] ?? $id);

            // Add-cart chỉ đọc field kho chính của collection san_pham.
            // Nếu dữ liệu cũ thiếu so_luong_ton_kho thì tạm mặc định 300 như yêu cầu nghiệp vụ.
            $stock = array_key_exists('so_luong_ton_kho', $productArray) && $productArray['so_luong_ton_kho'] !== null && $productArray['so_luong_ton_kho'] !== ''
                ? max(0, (int)$productArray['so_luong_ton_kho'])
                : null;
            $stockEffective = ($stock === null) ? 300 : $stock;
            $stockStatus = strtolower(trim((string)($productArray['trang_thai_kho'] ?? '')));

            if ($stockStatus === 'het_hang' || $stockEffective <= 0) {
                error_log('add_to_cart out_of_stock: ' . json_encode([
                    'route' => $_GET['r'] ?? '',
                    'method' => $_SERVER['REQUEST_METHOD'] ?? '',
                    'get' => $_GET,
                    'post' => $_POST,
                    'raw_input' => $debugContext['raw_input'] ?? '',
                    'parsed_data' => $debugContext['parsed_data'] ?? [],
                    'product_id' => $id,
                    'cart_product_id' => $cartProductId,
                    'qty' => $qty,
                    'query' => $query,
                    'found' => true,
                    'ma_san_pham' => $productArray['ma_san_pham'] ?? null,
                    'so_luong_ton_kho' => $stock,
                    'stock_effective' => $stockEffective,
                    'trang_thai_kho' => $stockStatus,
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
                return ['ok' => false, 'message' => 'Sản phẩm hiện đã tạm hết hàng.', 'cart_count' => $this->cartCount()];
            }

            $currentQty = (int)($_SESSION['gio_hang'][$cartProductId] ?? 0);
            if (($currentQty + $qty) > $stockEffective) {
                error_log('add_to_cart exceed_stock: ' . json_encode([
                    'route' => $_GET['r'] ?? '',
                    'method' => $_SERVER['REQUEST_METHOD'] ?? '',
                    'get' => $_GET,
                    'post' => $_POST,
                    'raw_input' => $debugContext['raw_input'] ?? '',
                    'parsed_data' => $debugContext['parsed_data'] ?? [],
                    'product_id' => $id,
                    'cart_product_id' => $cartProductId,
                    'qty' => $qty,
                    'current_qty' => $currentQty,
                    'query' => $query,
                    'found' => true,
                    'ma_san_pham' => $productArray['ma_san_pham'] ?? null,
                    'so_luong_ton_kho' => $stock,
                    'stock_effective' => $stockEffective,
                    'trang_thai_kho' => $stockStatus,
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
                return ['ok' => false, 'message' => 'Số lượng vượt quá tồn kho hiện có.', 'cart_count' => $this->cartCount()];
            }

            $_SESSION['gio_hang'] = $_SESSION['gio_hang'] ?? [];
            $_SESSION['gio_hang'][$cartProductId] = $currentQty + $qty;
            ProductEventTracker::record($this->pdo, 'add_to_cart', $cartProductId);

            // Phase B: Unified Interaction Logging
            require_once dirname(__DIR__) . '/services/InteractionLogger.php';
            InteractionLogger::logCart($cartProductId, (int)$qty);
            error_log('add_to_cart ok: ' . json_encode([
                'route' => $_GET['r'] ?? '',
                'method' => $_SERVER['REQUEST_METHOD'] ?? '',
                'product_id' => $id,
                'cart_product_id' => $cartProductId,
                'qty' => $qty,
                'current_qty' => $currentQty,
                'new_qty' => $_SESSION['gio_hang'][$cartProductId],
                'ma_san_pham' => $productArray['ma_san_pham'] ?? null,
                'so_luong_ton_kho' => $stock,
                'stock_effective' => $stockEffective,
                'trang_thai_kho' => $stockStatus,
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            return ['ok' => true, 'message' => 'Đã thêm sản phẩm vào giỏ hàng', 'cart_count' => $this->cartCount()];
        } catch (Throwable $e) {
            error_log('add_to_cart exception: ' . $e->getMessage() . "\n" . $e->getTraceAsString());
            return ['ok' => false, 'message' => 'Không thể thêm sản phẩm: ' . $e->getMessage(), 'cart_count' => $this->cartCount()];
        }
    }

    private function isAjaxRequest(): bool {
        $requestedWith = strtolower(trim((string)($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')));
        $accept = strtolower(trim((string)($_SERVER['HTTP_ACCEPT'] ?? '')));
        return $requestedWith === 'xmlhttprequest' || strpos($accept, 'application/json') !== false;
    }

    private function respondCartResult(array $result, string $fallbackUrl): void {
        if ($this->isAjaxRequest()) {
            // Trả JSON cho AJAX: phải tránh mọi output buffering/filter (vd fixMojibake)
            // vì có thể làm hỏng JSON khiến frontend parse fail và rơi vào catch().
            while (ob_get_level() > 0) {
                @ob_end_clean();
            }
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            exit;
        }

        set_flash(!empty($result['ok']) ? 'success' : 'error', (string)($result['message'] ?? ''));
        redirect($fallbackUrl);
    }

    public function addToCartAjax(): void {
        $request = $this->readCartRequestData();
        $data = $request['data'];
        $id = trim((string)($data['product_id'] ?? $data['ma_san_pham'] ?? $data['id'] ?? $_GET['product_id'] ?? $_GET['ma_san_pham'] ?? $_GET['id'] ?? ''));
        $qty = $this->readRequestedQty($data);
        $isBuyNow = !empty($data['buy_now']) || !empty($_POST['buy_now']) || !empty($_GET['buy_now']) || (isset($data['action']) && $data['action'] === 'buy_now');

        $result = $this->addToCartResult($id, $qty, ['raw_input' => $request['raw'], 'parsed_data' => $data]);
        if (!empty($result['ok']) && $isBuyNow) {
            $result['redirect_url'] = BASE_URL . '/index.php?r=giohang';
        }

        $fallbackUrl = $isBuyNow ? (BASE_URL . '/index.php?r=giohang') : (BASE_URL . '/index.php?r=tatca');
        $this->respondCartResult($result, $fallbackUrl);
    }

    private function normalizeKeyword(?string $keyword): string {
        return mb_substr(trim((string)$keyword), 0, self::SEARCH_KEYWORD_MAX_LEN);
    }

    private function saveSearchHistory(string $keyword): void {
        $keyword = $this->normalizeKeyword($keyword);
        if ($keyword === '') {
            return;
        }

        $sessionHistory = $_SESSION['search_history'] ?? [];
        if (!is_array($sessionHistory)) {
            $sessionHistory = [];
        }

        $normalizedKeyword = mb_strtolower($keyword);
        $sessionHistory = array_values(array_filter(
            $sessionHistory,
            static fn($item) => mb_strtolower(trim((string)$item)) !== $normalizedKeyword
        ));

        array_unshift($sessionHistory, $keyword);
        $_SESSION['search_history'] = array_slice($sessionHistory, 0, self::SEARCH_HISTORY_LIMIT);

        if (isset($_SESSION['user']['email'])) {
            $tkModel = new TaiKhoan($this->pdo);
            $tkModel->luuLichSuTimKiem((string)$_SESSION['user']['email'], $keyword);
        }
    }

    private function getSearchHistory(): array {
        $history = [];

        $sessionHistory = $_SESSION['search_history'] ?? [];
        if (is_array($sessionHistory)) {
            foreach ($sessionHistory as $keyword) {
                $keyword = $this->normalizeKeyword((string)$keyword);
                if ($keyword !== '') {
                    $history[] = $keyword;
                }
            }
        }

        if (isset($_SESSION['user']['email'])) {
            $tkModel = new TaiKhoan($this->pdo);
            $history = array_merge(
                $history,
                $tkModel->getTuKhoaGanDay((string)$_SESSION['user']['email'], self::SEARCH_HISTORY_LIMIT)
            );
        }

        $uniqueHistory = [];
        $seen = [];
        foreach ($history as $keyword) {
            $keyword = $this->normalizeKeyword((string)$keyword);
            if ($keyword === '') {
                continue;
            }

            $normalizedKeyword = mb_strtolower($keyword);
            if (isset($seen[$normalizedKeyword])) {
                continue;
            }

            $seen[$normalizedKeyword] = true;
            $uniqueHistory[] = $keyword;

            if (count($uniqueHistory) >= self::SEARCH_HISTORY_LIMIT) {
                break;
            }
        }

        return $uniqueHistory;
    }

    public function timkiem() {
        $this->tatca();
    }

    public function tatca() {

        $page = (int)($_GET['page'] ?? 1);
        $q    = $this->normalizeKeyword($_GET['q'] ?? '');
        $cap1 = trim((string)($_GET['cap1'] ?? ''));
        $cap2 = trim((string)($_GET['cap2'] ?? ''));
        $maDm = trim((string)($_GET['ma_danh_muc'] ?? $_GET['category_id'] ?? ''));
        $sort = trim((string)($_GET['sort'] ?? 'default'));
        $exact = (string)($_GET['exact'] ?? '') === '1'; // U05: tắt tự sửa từ khóa, tìm đúng như đã gõ

        if ($maDm !== '' && $cap1 === '' && $cap2 === '') {
            $cap2 = $maDm;
        }

        // Live-filter (gõ tới đâu lọc tới đó) gọi lại trang này ở mỗi lần dừng gõ:
        // không ghi lịch sử tìm kiếm cho các request đó để tránh lưu từ khóa dở dang ("ser", "seru"...).
        // Khi người dùng bấm Enter/nút Tìm, live-filter.js gửi thêm "X-Live-Commit: 1" => ghi lịch sử như submit thường.
        $isLiveFilterRequest = ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'live-filter';
        $isLiveCommit = $isLiveFilterRequest && ($_SERVER['HTTP_X_LIVE_COMMIT'] ?? '') === '1';
        if ($q !== '') {
            if (!$isLiveFilterRequest || $isLiveCommit) {
                $this->saveSearchHistory($q);
            }
        }

        $perPage = 24;
        $res = $this->fetchCatalogPage($page, $perPage, $q, $cap1, $cap2, $sort);

        // Bước 08: log tìm kiếm ghi sau khi có kết quả để có result_count (zero-result rate);
        // live = request live-filter chưa commit (từ khóa đang gõ dở) -> báo cáo chỉ số bỏ qua.
        if ($q !== '') {
            require_once dirname(__DIR__) . '/services/InteractionLogger.php';
            require_once dirname(__DIR__) . '/services/AfterResponse.php';
            // Ghi log sau khi đã trả response (đỡ 2 round-trip Atlas); chụp user/session trước vì session sẽ đóng.
            [$logUserId, $logSessionId] = InteractionLogger::captureContext();
            $logMeta = [
                'result_count' => (int)($res['total'] ?? 0),
                'live' => $isLiveFilterRequest && !$isLiveCommit,
                'page' => $page,
            ];
            AfterResponse::defer(static function () use ($q, $logMeta, $logUserId, $logSessionId): void {
                InteractionLogger::logSearch($q, $logMeta, $logUserId, $logSessionId);
            });
            // U04: từ khóa phổ biến cho autocomplete - chỉ tính tìm kiếm đã commit (không phải live-filter đang gõ dở),
            // trang 1, có kết quả; SearchPopularity tự lọc PII và nuốt lỗi Redis.
            if (!$logMeta['live'] && $page === 1 && $logMeta['result_count'] > 0) {
                $popularCount = $logMeta['result_count'];
                AfterResponse::defer(static function () use ($q, $popularCount): void {
                    SearchPopularity::record($q, $popularCount);
                });
            }
        }

        // U05 "Có phải bạn muốn tìm...": gõ sai tên hãng (fuzzy) -> q gốc 0 kết quả thì tự tìm theo câu đã sửa (kèm banner
        // + link exact=1), q gốc có kết quả thì chỉ gợi ý bằng link. Lỗi Flask/timeout -> bỏ qua, trang không đổi.
        $searchQ = $q;
        $didYouMean = null;
        if ($q !== '' && $page === 1 && !$exact) {
            $corrected = $this->fuzzyCorrectedQuery($q);
            if ($corrected !== null) {
                $correctedRes = $this->fetchCatalogPage(1, $perPage, $corrected, $cap1, $cap2, $sort);
                if ((int)($correctedRes['total'] ?? 0) > 0) {
                    if ((int)($res['total'] ?? 0) === 0) {
                        $res = $correctedRes;
                        $searchQ = $corrected;
                        $didYouMean = ['mode' => 'auto', 'original' => $q, 'corrected' => $corrected];
                    } else {
                        $didYouMean = ['mode' => 'suggest', 'original' => $q, 'corrected' => $corrected];
                    }
                }
            }
        }

        // Smart Search Bước 06: ít/không có kết quả -> gợi ý từ Flask (không cache cùng $res).
        $searchFallback = null;
        if ($searchQ !== '' && $page === 1 && (int)($res['total'] ?? 0) < 4) {
            $searchFallback = $this->buildSearchFallback($searchQ, $res['items'] ?? []);
        }

        $this->render('tatca', [
            'items' => $res['items'] ?? [],
            'total' => $res['total'] ?? 0,
            'page' => $page,
            'perPage' => $perPage,
            'q' => $q,
            'cap1' => $cap1,
            'cap2' => $cap2,
            'sort' => $sort,
            'searchFallback' => $searchFallback,
            'qEffective' => $searchQ,
            'didYouMean' => $didYouMean,
            'exact' => $exact,
        ]);
    }

    /** Một trang danh mục/tìm kiếm công khai, có cache CacheService (nếu bật). Cache v3: thêm xếp hạng độ phù hợp (U05). */
    private function fetchCatalogPage(int $page, int $perPage, string $q, string $cap1, string $cap2, string $sort): array {
        $filterKey = 'catalog:tatca:' . md5(json_encode([
            'p' => $page,
            'per' => $perPage,
            'q' => $q,
            'c1' => $cap1,
            'c2' => $cap2,
            'sort' => $sort,
            'v' => 3
        ]));

        $res = null;
        if (class_exists('CacheService')) {
            $res = CacheService::getInstance()->get($filterKey);
        }

        if (!is_array($res) || !isset($res['items'])) {
            $res = $this->model->paginate($page, $perPage, $q, $cap1, $cap2, '', true, '', $sort);
            if (class_exists('CacheService') && is_array($res) && isset($res['items'])) {
                CacheService::getInstance()->set($filterKey, $res, 300);
            }
        }
        return is_array($res) ? $res : ['items' => [], 'total' => 0];
    }

    /**
     * U05: câu truy vấn đã sửa nếu Flask báo gõ sai tên hãng (fuzzy). Không bao giờ ném lỗi; null nếu tắt/lỗi/không cần sửa.
     * Bỏ qua q là số hoặc quá ngắn (mã sản phẩm, đang gõ dở).
     */
    private function fuzzyCorrectedQuery(string $q): ?string {
        try {
            if (mb_strlen($q, 'UTF-8') < 4 || is_numeric($q)
                || in_array(strtolower(ss_env('SEARCH_CORRECTION_ENABLED', '1')), ['0', 'false', 'off', 'no'], true)) {
                return null;
            }
            require_once dirname(__DIR__) . '/services/SearchRecommendClient.php';
            $client = SearchRecommendClient::fromEnv();
            if ($client === null || !$this->searchIntentAllowed()) {
                return null;
            }
            return SearchRecommendClient::fuzzyCorrectedQuery($client->intent($q), $q);
        } catch (Throwable $e) {
            error_log('[SEARCH_INTENT] correction skipped: ' . get_class($e));
            return null;
        }
    }

    /** Rate limit gọi intent: 120 lượt/phút theo phiên (U03 RateLimiter). Redis lỗi -> cho qua. */
    private function searchIntentAllowed(): bool {
        try {
            require_once dirname(__DIR__) . '/services/RateLimiter.php';
            $sid = session_id();
            if ($sid !== '' && !RateLimiter::shared()->hit('search_intent', substr(hash('sha256', 'ssi-sid|' . $sid), 0, 32), 120, 60)['allowed']) {
                return false;
            }
            $ip = RateLimiter::clientIdentity();
            if ($ip !== null && !RateLimiter::shared()->hit('search_intent_ip', $ip, 240, 60)['allowed']) {
                return false;
            }
        } catch (Throwable $e) {
            // fail-open
        }
        return true;
    }

    /** Gợi ý khi tìm kiếm ít kết quả. Không bao giờ ném lỗi; null nếu tính năng tắt hoặc lỗi hoàn toàn. */
    private function buildSearchFallback(string $q, array $currentItems): ?array {
        try {
            require_once dirname(__DIR__) . '/services/SearchRecommendClient.php';
            require_once dirname(__DIR__) . '/services/SearchFallbackBuilder.php';
            require_once dirname(__DIR__) . '/services/SearchEventTracker.php'; // csrf cho beacon trong partial
            if (in_array(strtolower(ss_env('SEARCH_FALLBACK_ENABLED', '1')), ['0', 'false', 'off', 'no'], true)) {
                return null;
            }
            if (!$this->searchFallbackAllowed()) {
                return null; // U03: vượt giới hạn -> chỉ bỏ khối gợi ý, trang tìm kiếm vẫn hiển thị bình thường
            }

            $excludeIds = [];
            foreach ($currentItems as $it) {
                $id = (string)($it['ma_san_pham'] ?? $it['id'] ?? '');
                if ($id !== '') {
                    $excludeIds[] = $id;
                }
            }
            $cartIds = [];
            if (!empty($_SESSION['gio_hang']) && is_array($_SESSION['gio_hang'])) {
                $cartIds = array_slice(array_map('strval', array_keys($_SESSION['gio_hang'])), 0, 50);
            }
            $userHash = null;
            $salt = ss_env('SEARCH_USER_HASH_SALT', '');
            $email = (string)($_SESSION['user']['email'] ?? '');
            if ($salt !== '' && $email !== '') {
                $userHash = hash('sha256', mb_strtolower(trim($email)) . $salt);
            }
            // Bước 08: hồ sơ da rút gọn {skin_type, concerns} (key đồ thị), không tên/email.
            $skinProfile = $email !== '' ? $this->searchSkinProfile($email) : null;

            $client = SearchRecommendClient::fromEnv();
            $response = $client ? $client->recommend($q, $userHash, $skinProfile, $cartIds, $excludeIds, 8, SearchEventTracker::sessionHash()) : null;

            $model = $this->model;
            return SearchFallbackBuilder::build(
                $response,
                function (array $ids) use ($model): array { return $model->findVisibleByIds($ids); },
                function (int $limit) use ($model): array { return $model->bestSellers($limit); },
                $q
            );
        } catch (Throwable $e) {
            error_log('[SEARCH_RECOMMEND] fallback build error: ' . get_class($e));
            return null;
        }
    }

    /**
     * Rate limit khối gợi ý (U03): 120 lượt/phút theo phiên, và theo IP công khai nếu nhận diện được.
     * Redis lỗi/không có -> cho qua (fail-open). Không bao giờ chặn trang.
     */
    private function searchFallbackAllowed(): bool {
        try {
            require_once dirname(__DIR__) . '/services/RateLimiter.php';
            $limiter = RateLimiter::shared();
            $sid = session_id();
            if ($sid !== '' && !$limiter->hit('search_fallback', substr(hash('sha256', 'ssf-sid|' . $sid), 0, 32), 120, 60)['allowed']) {
                return false;
            }
            $ip = RateLimiter::clientIdentity();
            if ($ip !== null && !$limiter->hit('search_fallback_ip', $ip, 120, 60)['allowed']) {
                return false;
            }
        } catch (Throwable $e) {
            error_log('[SEARCH_RECOMMEND] rate limit skipped: ' . get_class($e));
        }
        return true;
    }

    /** Hồ sơ da của khách đăng nhập cho tìm kiếm (chỉ đọc khach_hang). Lỗi/không có -> null. */
    private function searchSkinProfile(string $email): ?array {
        try {
            require_once dirname(__DIR__) . '/services/SearchSkinProfile.php';
            $kh = (new TaiKhoan($this->pdo))->getCustomerForSelectedProfileByEmail($email);
            return SearchSkinProfile::fromCustomer($kh);
        } catch (Throwable $e) {
            error_log('[SEARCH_RECOMMEND] skin profile skipped: ' . get_class($e));
            return null;
        }
    }

    public function danhsach() {
        $page = (int)($_GET['page'] ?? 1);
        $type = trim((string)($_GET['type'] ?? ''));
        $perPage = 24;
        $items = [];
        $total = 0;
        $dbUnavailableMessage = '';
        $titleMap = [
            'best-seller' => 'Sản phẩm bán chạy nhất',
            'high-rating' => 'Sản phẩm được đánh giá cao',
            'discount' => 'Sản phẩm đang giảm giá',
            'popular' => 'Sản phẩm được nhiều người quan tâm',
            'flash-sale' => 'Flash Sale',
            'new' => 'Sản phẩm mới',
        ];

        try {
            $res = method_exists($this->model, 'getProductsByType')
                ? $this->model->getProductsByType($type, $_GET, $page, $perPage)
                : $this->model->paginate($page, $perPage, '', '', '', '', true);
            $items = $res['items'] ?? [];
            $total = (int)($res['total'] ?? 0);
        } catch (Throwable $e) {
            error_log('danhsach MongoDB error: ' . $e->getMessage());
            $dbUnavailableMessage = 'Hiện chưa thể tải danh sách sản phẩm. Vui lòng kiểm tra MongoDB hoặc thử lại sau.';
        }

        $this->render('tatca', [
            'items' => $items,
            'total' => $total,
            'page' => max(1, $page),
            'perPage' => $perPage,
            'q' => '',
            'cap1' => '',
            'cap2' => '',
            'pageTitle' => $titleMap[$type] ?? 'Danh sách sản phẩm',
            'dbUnavailableMessage' => $dbUnavailableMessage,
            'listRoute' => 'danhsach',
            'listType' => $type,
        ]);
    }

    public function chitiet() {
        $q = $this->normalizeKeyword($_GET['q'] ?? '');

        // Xử lý thêm vào giỏ hàng.
        // - Nếu là AJAX: trả JSON (không redirect) để UI hiển thị toast + cập nhật badge.
        // - Nếu submit thường: giữ redirect/flash như cũ.
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'add_to_cart') {
            $request = $this->readCartRequestData();
            $data = $request['data'];
            $id = trim((string)($data['product_id'] ?? $data['ma_san_pham'] ?? $data['id'] ?? $_GET['id'] ?? ''));
            $qty = $this->readRequestedQty($data);
            $this->respondCartResult(
                $this->addToCartResult($id, $qty, ['raw_input' => $request['raw'], 'parsed_data' => $data]),
                BASE_URL . '/index.php?r=chitiet&id=' . urlencode($id)
            );
            return;
        }
        $id = trim((string)($_GET['id'] ?? ''));
        if ($id === '') {
            $this->render('404');
            return;
        }

        // Phase P2.1 Decision: Read single product directly from MongoDB Atlas indexed find()
        // Single indexed lookup in Atlas takes ~50ms, avoiding remote TLS latency hop and ensuring 100% fresh pricing/inventory.
        $p = null;
        try {
            $p = $this->model->find($id, true);
        } catch (Throwable $e) {
            error_log('product detail MongoDB error: ' . $e->getMessage());
            $this->render('chitiet', [
                'p' => [],
                'reviews' => [],
                'reviewPermission' => ['has_purchased' => false, 'has_reviewed' => false],
                'activeTab' => trim((string)($_GET['tab'] ?? '')),
                'detailUnavailableMessage' => 'Hiện chưa thể tải chi tiết sản phẩm. Vui lòng kiểm tra MongoDB hoặc thử lại sau.',
                'reviewErrorMessage' => '',
            ]);
            return;
        }
        if (!$p) {
            $this->render('404');
            return;
        }

        // Mỗi lần truy cập trang chi tiết sẽ tăng 1 lượt xem.
        $pending = null;
        try {
            $pending = $this->model->tangLuotXem($id);
        } catch (Throwable $e) {
            error_log('product view counter MongoDB error: ' . $e->getMessage());
        }
        $p['luot_xem'] = (int)($p['luot_xem'] ?? 0) + ($pending !== null ? $pending : 1);

        // Lưu vào recent viewed products của session (tối đa 5 sản phẩm).
        // Giữ lại các sản phẩm trước đó để trang chi tiết có thể hiển thị khối "Đã xem gần đây".
        $viewedProductId = (string)($p['ma_san_pham'] ?? $id);
        $this->trackRecentViewedProduct($viewedProductId);
        $recentViewedProducts = [];
        try {
            $recentViewedIds = is_array($_SESSION['recent_viewed_products'] ?? null)
                ? array_values(array_filter(array_map('strval', $_SESSION['recent_viewed_products']), static fn(string $recentId): bool => $recentId !== '' && $recentId !== $viewedProductId))
                : [];
            if ($recentViewedIds !== []) {
                $recentViewedProducts = $this->model->findVisibleByIds(array_slice($recentViewedIds, 0, 4));
            }
        } catch (Throwable $e) {
            error_log('recent viewed products error: ' . get_class($e));
        }
        ProductEventTracker::record($this->pdo, 'product_view', (string)($p['ma_san_pham'] ?? $p['id'] ?? $id));

        if ($q !== '') {
            $this->saveSearchHistory($q);
            require_once dirname(__DIR__) . '/services/InteractionLogger.php';
            InteractionLogger::logSearchClick((string)($p['ma_san_pham'] ?? $id), $q);
        }

        $reviewModel = new DanhGia($this->pdo);
        $questionModel = new HoiDap($this->pdo);
        $reviewPermission = [
            'has_purchased' => false,
            'has_reviewed' => false,
        ];
        $reviews = [];
        $reviewStats = [
            'average' => (float)($p['diem_danh_gia'] ?? 0),
            'total' => (int)($p['so_luong_danh_gia'] ?? 0),
            'breakdown' => [5 => 0, 4 => 0, 3 => 0, 2 => 0, 1 => 0],
            'stars' => [5 => 0, 4 => 0, 3 => 0, 2 => 0, 1 => 0],
            'user_review_count' => 0,
            'crawl_review_count' => (int)($p['so_luong_danh_gia'] ?? 0),
            'with_images' => 0,
        ];
        $reviewErrorMessage = '';
        $questions = [];
        $questionCount = 0;
        $questionErrorMessage = '';

        try {
            if (is_logged_in()) {
                $user = current_user() ?? [];
                $email = trim((string)($user['email'] ?? ''));
                if ($email !== '') {
                    $customer = $reviewModel->resolveCustomerByEmail($email, trim((string)($user['ho_ten'] ?? '')) ?: 'Khách hàng');
                    if ($customer && !empty($customer['ma_kh'])) {
                        $reviewPermission = $reviewModel->canUserReviewProduct((int)$customer['ma_kh'], $id);
                    }
                }
            }
            $reviewFilter = [];
            $reviewStar = (int)($_GET['review_star'] ?? 0);
            if ($reviewStar >= 1 && $reviewStar <= 5) {
                $reviewFilter['star'] = $reviewStar;
            }
            if ((string)($_GET['review_filter'] ?? '') === 'images') {
                $reviewFilter['has_image'] = true;
            }
            $reviews = $reviewModel->getReviewsByProduct($id, $reviewFilter, 1, 8);
            $reviewStats = $reviewModel->getReviewStats($id, $p);
        } catch (Throwable $e) {
            error_log('product reviews MongoDB error: ' . $e->getMessage());
            $reviewErrorMessage = 'Hiện chưa thể tải đánh giá sản phẩm. Vui lòng thử lại sau.';
        }

        try {
            $questions = $questionModel->getQuestionsByProduct($id, 20);
            $questionCount = $questionModel->countQuestionsByProduct($id);
        } catch (Throwable $e) {
            error_log('product questions MongoDB error: ' . $e->getMessage());
            $questionErrorMessage = 'Hiện chưa thể tải hỏi đáp sản phẩm. Vui lòng thử lại sau.';
        }

        $prodKeySuffix = (string)($p['ma_san_pham'] ?? $id);
        $freqKey = 'product:freq_bought:' . $prodKeySuffix;
        $videoKey = 'product:videos:' . $prodKeySuffix;
        $menuKey = 'menu:tree:14';
        $cachedBatch = [];
        if (class_exists('CacheService')) {
            // Bundle freq_bought, videos, and layout menu into 1 single Valkey roundtrip
            $cachedBatch = CacheService::getInstance()->mget([$freqKey, $videoKey, $menuKey]);
            if (!empty($cachedBatch[$menuKey]) && is_array($cachedBatch[$menuKey])) {
                if (method_exists('SanPham', 'setMenuTreeCache')) {
                    SanPham::setMenuTreeCache($cachedBatch[$menuKey]);
                }
            }
        }

        $frequentlyBought = null;
        if (isset($cachedBatch[$freqKey]) && is_array($cachedBatch[$freqKey])) {
            $frequentlyBought = $cachedBatch[$freqKey];
        } else {
            try {
                require_once dirname(__DIR__) . '/services/AssociationRuleRecommender.php';
                $assocRecommender = new AssociationRuleRecommender($this->pdo);
                $frequentlyBought = $assocRecommender->getFrequentlyBoughtTogether($prodKeySuffix, 4);
                if (class_exists('CacheService') && is_array($frequentlyBought)) {
                    CacheService::getInstance()->set($freqKey, $frequentlyBought, 3600);
                }
            } catch (Throwable $e) {
                error_log('chitiet frequentlyBought error: ' . $e->getMessage());
                $frequentlyBought = [];
            }
        }

        $productVideos = null;
        if (isset($cachedBatch[$videoKey]) && is_array($cachedBatch[$videoKey])) {
            // Cached for up to 30 min: drop videos whose spoken flash price has since expired.
            require_once __DIR__ . '/../models/ProductVideo.php';
            $productVideos = ProductVideo::dropExpiredPriceClaims($cachedBatch[$videoKey], time());
        } else {
            try {
                require_once __DIR__ . '/../models/ProductVideo.php';
                require_once __DIR__ . '/../models/VideoRewardCampaign.php';
                $productVideoModel = new ProductVideo($this->pdo);
                $rewardCampaignModel = new VideoRewardCampaign($this->pdo);
                $productVideos = $productVideoModel->getPublishedVideosByProductId($prodKeySuffix);
                foreach ($productVideos as &$pv) {
                    $pv['reward_campaign'] = $rewardCampaignModel->getActiveCampaignForVideo((string)($pv['video_id'] ?? ''), (string)($pv['id'] ?? ''));
                }
                unset($pv);
                if (class_exists('CacheService') && is_array($productVideos)) {
                    CacheService::getInstance()->set($videoKey, $productVideos, 1800);
                }
            } catch (Throwable $e) {
                error_log('chitiet productVideos error: ' . $e->getMessage());
                $productVideos = [];
            }
        }

        // Publication metadata and media storage are deliberately separate. The publication in
        // MongoDB grants storefront eligibility/rewards, while this read-only client supplies the
        // already-uploaded shared R2 rendition so a missing local AI Idol volume cannot collapse
        // the player into a broken 404 box.
        if (is_array($productVideos) && $productVideos !== []) {
            try {
                require_once __DIR__ . '/../services/ProductVideoClient.php';
                $videoAssets = ProductVideoClient::fromEnv()->forProduct($prodKeySuffix)['videos'] ?? [];
                $assetsByVideoId = [];
                foreach ((array)$videoAssets as $asset) {
                    if (is_array($asset) && (string)($asset['video_id'] ?? '') !== '') {
                        $assetsByVideoId[(string)$asset['video_id']] = $asset;
                    }
                }
                foreach ($productVideos as &$publishedVideo) {
                    $sourceId = (string)($publishedVideo['video_id'] ?? '');
                    $asset = $assetsByVideoId[$sourceId] ?? null;
                    if (is_array($asset)) {
                        foreach (['video_url', 'poster_url'] as $mediaField) {
                            if (!empty($asset[$mediaField])) {
                                $publishedVideo[$mediaField] = (string)$asset[$mediaField];
                            }
                        }
                        if ((float)($publishedVideo['duration'] ?? 0) <= 0 && isset($asset['duration'])) {
                            $publishedVideo['duration'] = (float)$asset['duration'];
                        }
                    }
                }
                unset($publishedVideo);
            } catch (Throwable $e) {
                error_log('chitiet product video asset hydration error: ' . get_class($e));
            }
        }

        $this->render('chitiet', [
            'p' => $p,
            'reviews' => $reviews,
            'reviewStats' => $reviewStats,
            'reviewPermission' => $reviewPermission,
            'questions' => $questions,
            'questionCount' => $questionCount,
            'frequentlyBought' => $frequentlyBought,
            'recentViewedProducts' => $recentViewedProducts,
            'productVideos' => $productVideos,
            'activeTab' => trim((string)($_GET['tab'] ?? '')),
            'reviewErrorMessage' => $reviewErrorMessage,
            'questionErrorMessage' => $questionErrorMessage,
        ]);
    }

    /**
     * Đổi riêng khối mua kèm/routine trên trang chi tiết, không tải lại toàn bộ PDP.
     * Chỉ đọc dữ liệu đề xuất hiện có; không thay đổi đơn hàng, giỏ hàng hay catalog.
     */
    public function routineRecommendations(): void {
        header('Cache-Control: private, no-store');
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
            http_response_code(405);
            return;
        }

        $id = trim((string)($_GET['id'] ?? ''));
        if ($id === '' || !preg_match('/^[A-Za-z0-9_-]{1,32}$/', $id)) {
            http_response_code(400);
            return;
        }

        $offset = max(0, min(200, (int)($_GET['offset'] ?? 0)));
        try {
            require_once dirname(__DIR__) . '/services/AssociationRuleRecommender.php';
            $recommender = new AssociationRuleRecommender($this->pdo);
            $allItems = $recommender->getFrequentlyBoughtTogether($id, 24);
            if (!is_array($allItems) || $allItems === []) {
                http_response_code(204);
                return;
            }

            $allItems = array_values(array_filter($allItems, static function ($item) use ($id): bool {
                if (!is_array($item)) {
                    return false;
                }
                $productId = (string)($item['ma_san_pham'] ?? $item['id'] ?? '');
                return $productId !== '' && $productId !== $id;
            }));
            if ($allItems === []) {
                http_response_code(204);
                return;
            }

            $total = count($allItems);
            $offset = $total > 0 ? ($offset % $total) : 0;
            $frequentlyBought = [];
            for ($i = 0; $i < min(4, $total); $i++) {
                $frequentlyBought[] = $allItems[($offset + $i) % $total];
            }
            $frequentlyBoughtOffset = $offset;
            $frequentlyBoughtNextOffset = ($offset + count($frequentlyBought)) % $total;

            $hasRealRule = false;
            foreach ($frequentlyBought as $item) {
                if (($item['recommendation_source'] ?? '') === 'ASSOCIATION_RULE') {
                    $hasRealRule = true;
                    break;
                }
            }
            $sectionTitle = $hasRealRule ? 'Sản phẩm khách hàng thường mua cùng' : 'Gợi ý kết hợp cho routine';
            $sectionBadge = $hasRealRule ? 'Thường được mua cùng' : 'Gợi ý chu trình';
            $frequentlyBoughtRefreshUrl = BASE_URL . '/index.php?r=routine_recommendations&id=' . rawurlencode($id);

            header('Content-Type: text/html; charset=utf-8');
            $viewDir = defined('VIEW_DIR') ? VIEW_DIR : __DIR__ . '/../views';
            require $viewDir . '/partials/routine_recommendations.php';
        } catch (Throwable $e) {
            error_log('routine recommendations error: ' . get_class($e));
            http_response_code(204);
        }
    }

    /**
     * U08: khối "Sản phẩm tương tự" tải lười từ trang chi tiết. GET ?r=similar_products&id=<ma_san_pham>
     * -> 200 + HTML partial (partials/similar_products.php) khi có >= 2 sản phẩm hiển thị được; 204 (rỗng) khi tắt,
     * Flask lỗi/breaker mở hoặc không đủ sản phẩm; 400 id sai; 429 vượt giới hạn. Không bao giờ ném lỗi ra trang.
     */
    public function similarProducts() {
        header('Cache-Control: private, no-store');
        $id = trim((string)($_GET['id'] ?? ''));
        if (!preg_match('/^[A-Za-z0-9_-]{1,32}$/', $id)) {
            http_response_code(400);
            return;
        }
        $similarOffset = max(0, min(200, (int)($_GET['offset'] ?? 0)));
        if (in_array(strtolower(ss_env('SIMILAR_PRODUCTS_ENABLED', '1')), ['0', 'false', 'off', 'no'], true)) {
            http_response_code(204);
            return;
        }
        // Đọc trạng thái phiên rồi nhả khóa ngay: request này chỉ đọc, không nên chặn các request song song cùng phiên.
        $cartIds = [];
        if (!empty($_SESSION['gio_hang']) && is_array($_SESSION['gio_hang'])) {
            $cartIds = array_slice(array_map('strval', array_keys($_SESSION['gio_hang'])), 0, 40);
        }
        $email = (string)($_SESSION['user']['email'] ?? '');
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }
        try {
            require_once dirname(__DIR__) . '/services/SearchRecommendClient.php';
            require_once dirname(__DIR__) . '/services/SearchEventTracker.php'; // csrf cho beacon trong partial
            require_once dirname(__DIR__) . '/services/SearchReasonFormatter.php';
            if (!$this->similarProductsAllowed()) {
                http_response_code(429);
                header('Retry-After: 30');
                return;
            }
            $client = SearchRecommendClient::fromEnv();
            $skinType = null;
            if ($email !== '') {
                $profile = $this->searchSkinProfile($email);
                $skinType = is_array($profile) ? ($profile['skin_type'] ?? null) : null;
            }
            $response = $client
                ? $client->similar($id, is_string($skinType) ? $skinType : null, array_merge([$id], $cartIds), 20)
                : null;
            $response = is_array($response) ? $response : [];
            $ids = [];
            foreach ((array)($response['items'] ?? []) as $item) {
                $ids[] = (string)$item['product_id'];
            }
            $products = [];
            if ($ids) {
                foreach ($this->model->findVisibleByIds($ids) as $product) {
                    $key = (string)($product['ma_san_pham'] ?? $product['id'] ?? '');
                    if ($key !== '') {
                        $products[$key] = $product;
                    }
                }
            }
            $similarItems = [];
            foreach ((array)($response['items'] ?? []) as $item) {
                $pid = (string)$item['product_id'];
                if (!isset($products[$pid]) || $pid === $id) {
                    continue; // ẩn/hết hiệu lực trong Mongo
                }
                $product = $products[$pid];
                $product['reason_code'] = (string)$item['reason_code'];
                $product['reason_text'] = SearchReasonFormatter::format((string)$item['reason_code'], (array)$item['reason_params']);
                $similarItems[] = $product;
            }

            // Khi AI/Neo4j chưa đồng bộ hoặc breaker đang mở, vẫn hiển thị sản phẩm
            // cùng thương hiệu/danh mục từ catalog để khối không biến mất khỏi PDP.
            if (count($similarItems) < 2) {
                $sourceProduct = $this->model->find($id, true);
                if (is_array($sourceProduct) && $sourceProduct !== []) {
                    $fallbackProducts = $this->model->findSimilarFallback($sourceProduct, array_merge([$id], $cartIds), 20);
                    foreach ($fallbackProducts as $product) {
                        $pid = (string)($product['ma_san_pham'] ?? $product['id'] ?? '');
                        if ($pid === '' || $pid === $id) {
                            continue;
                        }
                        $sameBrand = trim((string)($sourceProduct['thuong_hieu'] ?? '')) !== ''
                            && strcasecmp(trim((string)($sourceProduct['thuong_hieu'] ?? '')), trim((string)($product['thuong_hieu'] ?? ''))) === 0;
                        $product['reason_code'] = $sameBrand ? 'same_brand' : 'same_category';
                        $product['reason_text'] = SearchReasonFormatter::format((string)$product['reason_code'], []);
                        $similarItems[] = $product;
                    }
                }
            }
            if (count($similarItems) < 2) {
                http_response_code(204);
                return;
            }
            if (count($similarItems) > 8) {
                $similarCount = count($similarItems);
                $similarOffset = $similarOffset % $similarCount;
                $similarItems = array_slice(
                    array_merge($similarItems, $similarItems),
                    $similarOffset,
                    8
                );
            }
            $similarProductId = $id;
            $similarDegraded = !empty($response['degraded']);
            $similarTitle = SearchReasonFormatter::sectionTitle('similar');
            header('Content-Type: text/html; charset=utf-8');
            $viewDir = defined('VIEW_DIR') ? VIEW_DIR : __DIR__ . '/../views';
            require $viewDir . '/partials/similar_products.php';
        } catch (Throwable $e) {
            error_log('[SEARCH_SIMILAR] block error: ' . get_class($e));
            if (!headers_sent()) {
                http_response_code(204);
            }
        }
    }

    /**
     * U09: GET index.php?r=cart_analyze -> khối "Lưu ý hoạt chất + Hoàn thiện routine" của giỏ hàng (HTML partial).
     * Đọc giỏ từ phiên, gọi Flask `cart/analyze` (timeout 1s, breaker riêng). 200 + HTML khi có cảnh báo hoặc gợi ý;
     * 204 khi tắt (CART_INSIGHTS_ENABLED=0), giỏ trống, Flask lỗi/breaker mở, hoặc không có gì để hiện; 429 vượt giới hạn.
     * Không bao giờ ném lỗi ra trang giỏ hàng, không chặn thanh toán.
     */
    public function cartInsights() {
        header('Cache-Control: private, no-store');
        if (in_array(strtolower(ss_env('CART_INSIGHTS_ENABLED', '1')), ['0', 'false', 'off', 'no'], true)) {
            http_response_code(204);
            return;
        }
        $cartIds = [];
        if (!empty($_SESSION['gio_hang']) && is_array($_SESSION['gio_hang'])) {
            $cartIds = array_slice(array_map('strval', array_keys($_SESSION['gio_hang'])), 0, 50);
        }
        $email = (string)($_SESSION['user']['email'] ?? '');
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close(); // chỉ đọc: không giữ khóa phiên khi chờ Flask
        }
        if (!$cartIds) {
            http_response_code(204);
            return;
        }
        try {
            require_once dirname(__DIR__) . '/services/SearchRecommendClient.php';
            require_once dirname(__DIR__) . '/services/SearchEventTracker.php'; // csrf cho beacon trong partial
            require_once dirname(__DIR__) . '/services/SearchReasonFormatter.php';
            require_once dirname(__DIR__) . '/services/CartInsights.php';
            if (!$this->insightsAllowed('cart_insights')) {
                http_response_code(429);
                header('Retry-After: 30');
                return;
            }
            $client = SearchRecommendClient::fromEnv();
            if (!$client) {
                http_response_code(204);
                return;
            }
            $profile = $email !== '' ? $this->searchSkinProfile($email) : null;
            $analysis = $client->cartAnalyze($cartIds, is_array($profile) ? $profile : null);
            if ($analysis === null) {
                http_response_code(204);
                return;
            }
            $want = array_values(array_unique(array_merge(CartInsights::productIds($analysis))));
            $products = [];
            if ($want) {
                foreach ($this->model->findVisibleByIds($want) as $product) {
                    $key = (string)($product['ma_san_pham'] ?? $product['id'] ?? '');
                    if ($key !== '') {
                        $products[$key] = $product;
                    }
                }
            }
            $cartInsights = CartInsights::build($analysis, $products, $cartIds);
            if (empty($cartInsights['visible'])) {
                http_response_code(204);
                return;
            }
            header('Content-Type: text/html; charset=utf-8');
            $viewDir = defined('VIEW_DIR') ? VIEW_DIR : __DIR__ . '/../views';
            require $viewDir . '/partials/cart_insights.php';
        } catch (Throwable $e) {
            error_log('[SEARCH_CART] block error: ' . get_class($e));
            if (!headers_sent()) {
                http_response_code(204);
            }
        }
    }

    /** Giới hạn khối giỏ hàng: 60 lượt/phút theo phiên, 120/phút theo IP công khai. Redis lỗi -> cho qua. */
    private function insightsAllowed(string $scope): bool {
        try {
            require_once dirname(__DIR__) . '/services/RateLimiter.php';
            $limiter = RateLimiter::shared();
            $sid = session_id();
            if ($sid !== '' && !$limiter->hit($scope, substr(hash('sha256', $scope . '-sid|' . $sid), 0, 32), 60, 60)['allowed']) {
                return false;
            }
            $ip = RateLimiter::clientIdentity();
            if ($ip !== null && !$limiter->hit($scope . '_ip', $ip, 120, 60)['allowed']) {
                return false;
            }
        } catch (Throwable $e) {
            error_log('[SEARCH_CART] rate limit skipped: ' . get_class($e));
        }
        return true;
    }

    /** Giới hạn khối tương tự: 60 lượt/phút theo phiên, 120/phút theo IP công khai. Redis lỗi -> cho qua. */
    private function similarProductsAllowed(): bool {
        try {
            require_once dirname(__DIR__) . '/services/RateLimiter.php';
            $limiter = RateLimiter::shared();
            $sid = session_id();
            if ($sid !== '' && !$limiter->hit('similar_products', substr(hash('sha256', 'ssim-sid|' . $sid), 0, 32), 60, 60)['allowed']) {
                return false;
            }
            $ip = RateLimiter::clientIdentity();
            if ($ip !== null && !$limiter->hit('similar_products_ip', $ip, 120, 60)['allowed']) {
                return false;
            }
        } catch (Throwable $e) {
            error_log('[SEARCH_SIMILAR] rate limit skipped: ' . get_class($e));
        }
        return true;
    }

    public function liveSearch() {
        header('Content-Type: application/json; charset=utf-8');

        $q = $this->normalizeKeyword($_GET['q'] ?? '');
        $limit = (int)($_GET['limit'] ?? 8);
        $limit = max(1, min(20, $limit));

        if (mb_strlen($q) < 2) {
            echo json_encode([
                'ok' => true,
                'items' => []
            ], JSON_UNESCAPED_UNICODE);
            return;
        }

        try {
            $items = $this->model->searchSuggestions($q, $limit, true);
            echo json_encode([
                'ok' => true,
                'items' => $items
            ], JSON_UNESCAPED_UNICODE);
        } catch (Throwable $e) {
            http_response_code(500);
            echo json_encode([
                'ok' => false,
                'items' => [],
                'message' => 'Không thể tải gợi ý lúc này.'
            ], JSON_UNESCAPED_UNICODE);
        }
    }

    public function apiSmartSearch() {
        header('Content-Type: application/json; charset=utf-8');

        $q = $this->normalizeKeyword($_GET['q'] ?? '');

        try {
            if ($q === '') {
                $history = $this->getSearchHistory();

                $trending = $this->model->getTopTrending(5, true);

                echo json_encode([
                    'type' => 'zero_query',
                    'history' => $history,
                    'trending' => $trending,
                ], JSON_UNESCAPED_UNICODE);
                return;
            }

            // U04: sản phẩm khớp theo từng từ (token cuối là tiền tố) + thương hiệu/danh mục/từ khóa phổ biến.
            // `results` giữ nguyên là danh sách sản phẩm (app mobile dùng); các nhóm khác nằm trong `groups`.
            $t0 = microtime(true);
            $suggest = new SearchSuggestService($this->model);
            $payload = $suggest->suggest($q);
            // Server-Timing: đo thời gian xử lý phía server (không gồm nginx/mạng) để theo dõi p95 gợi ý;
            // desc = cache (Redis theo tiền tố) | index (chỉ mục Redis) | mongo (truy vấn Atlas)
            header(sprintf('Server-Timing: suggest;dur=%.1f;desc="%s"', (microtime(true) - $t0) * 1000, $suggest->lastSource()));
            echo json_encode([
                'type' => 'live_search',
                'results' => $payload['results'],
                'groups' => $payload['groups'],
                'cached' => $payload['cached'],
            ], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        } catch (Throwable $e) {
            http_response_code(500);
            echo json_encode([
                'type' => 'error',
                'message' => 'Không thể tải dữ liệu tìm kiếm lúc này.'
            ], JSON_UNESCAPED_UNICODE);
        }
    }

    private function trackRecentViewedProduct(string $productId): void {
        $productId = trim($productId);
        if ($productId === '') {
            return;
        }

        if (session_status() === PHP_SESSION_NONE) {
            @session_start();
        }

        $list = $_SESSION['recent_viewed_products'] ?? [];
        if (!is_array($list)) {
            $list = [];
        }

        // Loại bỏ sản phẩm nếu đã có để đưa lên đầu danh sách (MRU)
        $list = array_values(array_filter($list, fn($item) => (string)$item !== $productId));

        // Đưa sản phẩm vừa xem lên đầu danh sách
        array_unshift($list, $productId);

        // Giới hạn tối đa 5 sản phẩm gần nhất
        $_SESSION['recent_viewed_products'] = array_slice($list, 0, 5);

        // Phase B: Unified Interaction Logging
        require_once dirname(__DIR__) . '/services/InteractionLogger.php';
        require_once dirname(__DIR__) . '/services/AfterResponse.php';
        [$logUserId, $logSessionId] = InteractionLogger::captureContext();
        AfterResponse::defer(static function () use ($productId, $logUserId, $logSessionId): void {
            InteractionLogger::logView($productId, [], $logUserId, $logSessionId);
        });
    }

    /**
     * Public secure streaming proxy for published AI product videos.
     * Prevents path traversal, blocks unpublished drafts, supports byte ranges.
     */
    public function streamProductVideo(): void {
        $pubId = trim((string)($_GET['id'] ?? $_GET['publication_id'] ?? ''));
        $productId = trim((string)($_GET['product_id'] ?? ''));

        // Path traversal and format safety: ObjectId must be exactly 24 hex characters
        if (!preg_match('/^[a-f0-9]{24}$/i', $pubId)) {
            http_response_code(404);
            header('Content-Type: text/plain; charset=utf-8');
            echo 'Video not found or invalid identifier.';
            exit;
        }

        require_once __DIR__ . '/../models/ProductVideo.php';
        $model = new ProductVideo($this->pdo);
        $publication = $model->getPublishedVideoById($pubId);

        // Security: Unpublished drafts or missing records return 404
        if (!$publication || empty($publication['is_published'])) {
            http_response_code(404);
            header('Content-Type: text/plain; charset=utf-8');
            echo 'Video not found or unavailable.';
            exit;
        }

        // Product association check if product_id is specified
        if ($productId !== '' && !in_array($productId, (array)($publication['associated_product_ids'] ?? []), true)) {
            http_response_code(404);
            header('Content-Type: text/plain; charset=utf-8');
            echo 'Video not associated with requested product.';
            exit;
        }

        $videoId = trim((string)($publication['video_id'] ?? ''));
        if (!preg_match('/^[a-f0-9]{24}$/i', $videoId)) {
            http_response_code(404);
            header('Content-Type: text/plain; charset=utf-8');
            echo 'Source video identifier invalid.';
            exit;
        }

        // Unlock session to allow parallel requests and video seeking
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }

        $flaskUrl = rtrim((string)(getenv('AI_IDOL_ENDPOINT') ?: 'http://ai-idol-service:5003'), '/');
        // Product pages always play the price-free clip, never the full render (spec D12).
        $endpoint = $flaskUrl . '/api/ai-idol/videos/' . rawurlencode($videoId) . '?rendition=product_page';
        $r2Base = function_exists('ss_env') ? ss_env('R2_PUBLIC_BASE_URL', '') : (string)(getenv('R2_PUBLIC_BASE_URL') ?: '');

        // A published video may already have been uploaded to the shared R2 bucket while the
        // local AI Idol container no longer has its temporary /app/storage copy. Prefer that
        // catalog URL when it is available; the publication and product association checks above
        // still gate access, and productVideoRedirectTarget() rejects every non-whitelisted host.
        $catalogTarget = self::productVideoCatalogTarget($flaskUrl, $productId, $videoId, $r2Base);
        if ($catalogTarget !== null) {
            header('Location: ' . $catalogTarget, true, 302);
            exit;
        }

        $ch = curl_init($endpoint);
        $requestHeaders = [];
        if (!empty($_SERVER['HTTP_RANGE'])) {
            $requestHeaders[] = 'Range: ' . $_SERVER['HTTP_RANGE'];
        }

        // Flask answers with a redirect once the local copy was moved to R2. It is never passed through
        // blindly: the status, headers and body of a redirect stay here until its target is validated.
        $upstream = ['status' => 0, 'location' => ''];
        $isRedirect = static fn(array $state): bool => in_array($state['status'], self::VIDEO_REDIRECT_STATUSES, true);

        curl_setopt_array($ch, [
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_TIMEOUT => 0,
            CURLOPT_HTTPHEADER => $requestHeaders,
            CURLOPT_HEADERFUNCTION => static function ($curl, string $line) use (&$upstream, $isRedirect): int {
                $length = strlen($line);
                if (preg_match('#^HTTP/\\S+\\s+(\\d+)#i', trim($line), $match)) {
                    $upstream = ['status' => (int)$match[1], 'location' => ''];
                    if (!$isRedirect($upstream)) {
                        http_response_code($upstream['status']);
                    }
                } elseif ($isRedirect($upstream)) {
                    if (preg_match('/^Location:\\s*(.+)$/i', trim($line), $match)) {
                        $upstream['location'] = trim($match[1]);
                    }
                } elseif (preg_match('/^(Content-Type|Content-Length|Content-Range|Accept-Ranges|Cache-Control):/i', $line)) {
                    header(trim($line), true);
                }
                return $length;
            },
            CURLOPT_WRITEFUNCTION => static function ($curl, string $chunk) use (&$upstream, $isRedirect): int {
                if (!$isRedirect($upstream)) {
                    echo $chunk;
                    flush();
                }
                return strlen($chunk);
            },
        ]);

        $ok = curl_exec($ch);
        if ($ok === false && !headers_sent()) {
            http_response_code(502);
            header('Content-Type: text/plain; charset=utf-8');
            echo 'Cannot stream video source.';
        } elseif ($ok !== false && $isRedirect($upstream) && !headers_sent()) {
            $target = self::productVideoRedirectTarget($upstream['location'], $r2Base);
            if ($target === null) {
                http_response_code(404);
                header('Content-Type: text/plain; charset=utf-8');
                echo 'Video not found or unavailable.';
            } else {
                header('Location: ' . $target, true, 302);
            }
        }
        curl_close($ch);
        exit;
    }

    /**
     * Resolve a published product video to its shared R2 rendition without requiring the AI Idol
     * container to retain the original local render. This is intentionally read-only and bounded:
     * on any timeout, malformed response, id mismatch, or untrusted URL the normal proxy path runs.
     */
    private static function productVideoCatalogTarget(string $flaskUrl, string $productId, string $videoId, string $r2Base): ?string {
        $productId = trim($productId);
        $videoId = trim($videoId);
        if ($productId === '' || $videoId === '' || trim($r2Base) === '') {
            return null;
        }

        $url = rtrim($flaskUrl, '/') . '/api/ai-idol/products/' . rawurlencode($productId) . '/videos?limit=30';
        $ch = curl_init($url);
        if ($ch === false) {
            return null;
        }
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => 2,
            CURLOPT_TIMEOUT => 5,
            CURLOPT_HTTPHEADER => ['Accept: application/json'],
        ]);
        $body = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if (!is_string($body) || $status < 200 || $status >= 300) {
            return null;
        }

        $payload = json_decode($body, true);
        $videos = is_array($payload) ? ($payload['data']['videos'] ?? []) : [];
        if (!is_array($videos)) {
            return null;
        }
        foreach ($videos as $video) {
            if (!is_array($video) || (string)($video['video_id'] ?? '') !== $videoId) {
                continue;
            }
            $target = self::productVideoRedirectTarget((string)($video['video_url'] ?? ''), $r2Base);
            if ($target !== null) {
                return $target;
            }
        }
        return null;
    }

    /**
     * The only redirect the product-video proxy hands to a browser: a file on the public R2 bucket.
     * Anything else (another host, a lookalike host such as "<base>.evil.com", a header-injection attempt,
     * or R2 not configured) is rejected so the stream can never bounce visitors elsewhere.
     */
    public static function productVideoRedirectTarget(string $location, string $r2PublicBase): ?string {
        $base = rtrim(trim($r2PublicBase), '/');
        $location = trim($location);
        if ($base === '' || $location === '' || preg_match('/[\\x00-\\x20\\x7f]/', $location)) {
            return null;
        }
        return strncmp($location, $base . '/', strlen($base) + 1) === 0 ? $location : null;
    }
}


