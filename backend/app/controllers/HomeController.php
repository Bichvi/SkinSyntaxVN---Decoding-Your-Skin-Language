<?php
// backend/app/controllers/HomeController.php
require_once __DIR__ . '/../models/SanPham.php';
require_once __DIR__ . '/../models/GoiYContentBased.php';
require_once __DIR__ . '/../models/TaiKhoan.php';
require_once __DIR__ . '/../models/HoaDon.php';
require_once __DIR__ . '/../models/QuanTri.php';
require_once __DIR__ . '/../models/Voucher.php';

class HomeController {
    private $pdo;
    private SanPham $model;
    private GoiYContentBased $goiYModel;
    private HoaDon $hoaDonModel;
    private Voucher $voucherModel;
    private const POINT_VALUE_VND = 1000;
    private const VIP_THRESHOLD = 500;
    private const DIAMOND_THRESHOLD = 1500;
    private const AI_CHAT_CACHE_TTL = 604800;
    private const AI_CHAT_CACHE_MAX_ITEMS = 300;

    public function __construct($pdo) {
        $this->pdo = $pdo;
        $this->model = new SanPham($pdo);
        $this->goiYModel = new GoiYContentBased($pdo);
        $this->hoaDonModel = new HoaDon($pdo);
        $this->voucherModel = new Voucher($pdo);
    }

    private function getAiChatCachePath(): string {
        $cacheDir = dirname(__DIR__, 2) . '/storage/cache';
        if (!is_dir($cacheDir)) {
            @mkdir($cacheDir, 0777, true);
        }

        return $cacheDir . '/ai_chat_responses.json';
    }

    private function normalizeAiChatCacheKey(string $message, string $currentProductId = ''): string {
        $normalized = function_exists('mb_strtolower') ? mb_strtolower($message, 'UTF-8') : strtolower($message);
        $normalized = preg_replace('/\s+/u', ' ', trim($normalized)) ?? trim($normalized);
        return hash('sha256', $normalized . '|prod:' . $currentProductId);
    }

    private function loadAiChatCache(): array {
        $path = $this->getAiChatCachePath();
        if (!is_file($path)) {
            return [];
        }

        $decoded = json_decode((string)file_get_contents($path), true);
        return is_array($decoded) ? $decoded : [];
    }

    private function saveAiChatCache(array $cache): void {
        @file_put_contents(
            $this->getAiChatCachePath(),
            json_encode($cache, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        );
    }

    private function shouldUseAiResponseCache(string $message): bool {
        return in_array($this->detectAiIntent($message), ['ingredient_analysis', 'general'], true);
    }

    private function getCachedAiResponsePayload(string $message, string $currentProductId = ''): ?array {
        if (!$this->shouldUseAiResponseCache($message)) {
            return null;
        }

        $cache = $this->loadAiChatCache();
        $cacheKey = $this->normalizeAiChatCacheKey($message, $currentProductId);
        $entry = $cache[$cacheKey] ?? null;
        if (!is_array($entry)) {
            return null;
        }

        $createdAt = (int)($entry['created_at'] ?? 0);
        if ($createdAt <= 0 || (time() - $createdAt) > self::AI_CHAT_CACHE_TTL) {
            unset($cache[$cacheKey]);
            $this->saveAiChatCache($cache);
            return null;
        }

        $payload = $entry['payload'] ?? null;
        return is_array($payload) ? $payload : null;
    }

    private function storeAiResponsePayload(string $message, array $payload, string $currentProductId = ''): void {
        if (!$this->shouldUseAiResponseCache($message)) {
            return;
        }

        $answer = trim((string)($payload['answer'] ?? ''));
        if ($answer === '') {
            return;
        }

        $cache = $this->loadAiChatCache();
        $cache[$this->normalizeAiChatCacheKey($message, $currentProductId)] = [
            'created_at' => time(),
            'payload' => $payload,
        ];

        uasort($cache, static function ($left, $right): int {
            return (int)($right['created_at'] ?? 0) <=> (int)($left['created_at'] ?? 0);
        });

        if (count($cache) > self::AI_CHAT_CACHE_MAX_ITEMS) {
            $cache = array_slice($cache, 0, self::AI_CHAT_CACHE_MAX_ITEMS, true);
        }

        $this->saveAiChatCache($cache);
    }

    private function render($view, $data = []) {
        extract($data);
        // menuCats dÃ¹ng chung layout
        $menuCats = $this->model->menuTree();
        require __DIR__ . '/../views/layouts/header.php';
        require __DIR__ . '/../views/' . $view . '.php';
        require __DIR__ . '/../views/layouts/footer.php';
    }

    public function index() {
        $latest = $this->model->latest(12, true);
        $cats = $this->getHighlightedCategories();
        $homepageSections = method_exists($this->model, 'getHomepageProductSections')
            ? $this->model->getHomepageProductSections(4)
            : [];
        $this->render('home', ['latest' => $latest, 'cats' => $cats, 'homepageSections' => $homepageSections]);
    }

    public function otpGuide() {
        $this->render('info/otp-guide');
    }

    public function termsReference() {
        $this->renderPolicyReference([
            'title' => 'Äiá»u kiá»‡n giao dá»‹ch chung',
            'eyebrow' => 'Äiá»u khoáº£n SkinSyntax',
            'summary' => 'Äiá»u kiá»‡n giao dá»‹ch nÃ y do SkinSyntax ban hÃ nh vÃ  Ã¡p dá»¥ng cho toÃ n bá»™ hoáº¡t Ä‘á»™ng Ä‘Äƒng kÃ½ tÃ i khoáº£n, truy cáº­p ná»™i dung, mua sáº¯m, thanh toÃ¡n vÃ  sá»­ dá»¥ng dá»‹ch vá»¥ trÃªn website.',
            'highlights' => [
                'NgÆ°á»i dÃ¹ng cam káº¿t cung cáº¥p thÃ´ng tin Ä‘Ãºng sá»± tháº­t khi Ä‘Äƒng kÃ½ tÃ i khoáº£n, Ä‘áº·t hÃ ng, thanh toÃ¡n vÃ  lÃ m kháº£o sÃ¡t da. SkinSyntax cÃ³ quyá»n tá»« chá»‘i xá»­ lÃ½ khi phÃ¡t hiá»‡n thÃ´ng tin sai lá»‡ch hoáº·c cÃ³ dáº¥u hiá»‡u gian láº­n.',
                'Má»i Ä‘Æ¡n hÃ ng chá»‰ Ä‘Æ°á»£c xÃ¡c nháº­n sau khi SkinSyntax kiá»ƒm tra tÃ¬nh tráº¡ng sáº£n pháº©m, thÃ´ng tin nháº­n hÃ ng, phÆ°Æ¡ng thá»©c thanh toÃ¡n vÃ  cÃ¡c Ä‘iá»u kiá»‡n Ã¡p dá»¥ng cá»§a voucher hoáº·c chÆ°Æ¡ng trÃ¬nh khuyáº¿n mÃ£i.',
                'GiÃ¡ bÃ¡n, Æ°u Ä‘Ã£i, phÃ­ váº­n chuyá»ƒn vÃ  thá»i gian giao hÃ ng cÃ³ thá»ƒ thay Ä‘á»•i theo tá»«ng thá»i Ä‘iá»ƒm. SkinSyntax sáº½ hiá»ƒn thá»‹ thÃ´ng tin hiá»‡n hÃ nh trÃªn website trÆ°á»›c khi ngÆ°á»i dÃ¹ng hoÃ n táº¥t giao dá»‹ch.',
                'NgÆ°á»i dÃ¹ng cÃ³ trÃ¡ch nhiá»‡m báº£o máº­t tÃ i khoáº£n, máº­t kháº©u, mÃ£ OTP vÃ  cÃ¡c thiáº¿t bá»‹ Ä‘Äƒng nháº­p. Má»i thao tÃ¡c phÃ¡t sinh tá»« tÃ i khoáº£n Ä‘Ã£ xÃ¡c thá»±c Ä‘Æ°á»£c xem lÃ  do chÃ­nh chá»§ tÃ i khoáº£n thá»±c hiá»‡n, trá»« khi cÃ³ chá»©ng cá»© ngÆ°á»£c láº¡i.',
                'Ná»™i dung, hÃ¬nh áº£nh, logo, bá»‘ cá»¥c, dá»¯ liá»‡u sáº£n pháº©m vÃ  cÃ¡c tÃ i nguyÃªn hiá»ƒn thá»‹ trÃªn SkinSyntax thuá»™c quyá»n quáº£n lÃ½ cá»§a SkinSyntax hoáº·c Ä‘á»‘i tÃ¡c cáº¥p phÃ©p; khÃ´ng Ä‘Æ°á»£c sao chÃ©p, khai thÃ¡c láº¡i hoáº·c sá»­ dá»¥ng trÃ¡i phÃ©p.',
                'Khi phÃ¡t sinh khiáº¿u náº¡i, tranh cháº¥p hoáº·c yÃªu cáº§u há»— trá»£, hai bÃªn Æ°u tiÃªn giáº£i quyáº¿t trÃªn tinh tháº§n há»£p tÃ¡c. TrÆ°á»ng há»£p khÃ´ng thá»ƒ tá»± thá»a thuáº­n, váº¥n Ä‘á» sáº½ Ä‘Æ°á»£c xá»­ lÃ½ theo quy Ä‘á»‹nh phÃ¡p luáº­t Viá»‡t Nam.',
            ],
        ]);
    }

    public function privacyReference() {
        $this->renderPolicyReference([
            'title' => 'ChÃ­nh sÃ¡ch báº£o máº­t thÃ´ng tin',
            'eyebrow' => 'Báº£o máº­t táº¡i SkinSyntax',
            'summary' => 'SkinSyntax cam káº¿t báº£o vá»‡ thÃ´ng tin cÃ¡ nhÃ¢n, lá»‹ch sá»­ giao dá»‹ch, dá»¯ liá»‡u kháº£o sÃ¡t da vÃ  cÃ¡c dá»¯ liá»‡u ká»¹ thuáº­t phÃ¡t sinh trong quÃ¡ trÃ¬nh ngÆ°á»i dÃ¹ng sá»­ dá»¥ng website.',
            'highlights' => [
                'SkinSyntax chá»‰ thu tháº­p nhá»¯ng thÃ´ng tin cáº§n thiáº¿t cho viá»‡c táº¡o tÃ i khoáº£n, xÃ¡c thá»±c OTP, xá»­ lÃ½ Ä‘Æ¡n hÃ ng, chÄƒm sÃ³c khÃ¡ch hÃ ng, cÃ¡ nhÃ¢n hÃ³a gá»£i Ã½ sáº£n pháº©m vÃ  duy trÃ¬ váº­n hÃ nh há»‡ thá»‘ng.',
                'Dá»¯ liá»‡u cá»§a ngÆ°á»i dÃ¹ng Ä‘Æ°á»£c lÆ°u trá»¯ vá»›i cÃ¡c biá»‡n phÃ¡p kiá»ƒm soÃ¡t truy cáº­p phÃ¹ há»£p. Chá»‰ nhÃ¢n sá»±, bá»™ pháº­n hoáº·c dá»‹ch vá»¥ Ä‘Æ°á»£c á»§y quyá»n má»›i Ä‘Æ°á»£c tiáº¿p cáº­n dá»¯ liá»‡u trong pháº¡m vi cÃ´ng viá»‡c cáº§n thiáº¿t.',
                'SkinSyntax khÃ´ng bÃ¡n hoáº·c trao Ä‘á»•i thÃ´ng tin cÃ¡ nhÃ¢n cá»§a ngÆ°á»i dÃ¹ng cho bÃªn thá»© ba vÃ¬ má»¥c Ä‘Ã­ch thÆ°Æ¡ng máº¡i Ä‘á»™c láº­p. Viá»‡c chia sáº» chá»‰ diá»…n ra khi cáº§n thiáº¿t Ä‘á»ƒ giao hÃ ng, xá»­ lÃ½ thanh toÃ¡n, gá»­i thÃ´ng bÃ¡o hoáº·c tuÃ¢n thá»§ yÃªu cáº§u phÃ¡p luáº­t.',
                'NgÆ°á»i dÃ¹ng cÃ³ quyá»n yÃªu cáº§u xem láº¡i, cáº­p nháº­t, chá»‰nh sá»­a hoáº·c háº¡n cháº¿ xá»­ lÃ½ thÃ´ng tin cá»§a mÃ¬nh thÃ´ng qua cÃ¡c kÃªnh há»— trá»£ do SkinSyntax cÃ´ng bá»‘ trÃªn website.',
                'Trong trÆ°á»ng há»£p phÃ¡t hiá»‡n truy cáº­p trÃ¡i phÃ©p, rÃ² rá»‰ dá»¯ liá»‡u hoáº·c rá»§i ro an toÃ n thÃ´ng tin, SkinSyntax sáº½ Ä‘Ã¡nh giÃ¡ tÃ¡c Ä‘á»™ng, Ã¡p dá»¥ng biá»‡n phÃ¡p kháº¯c phá»¥c phÃ¹ há»£p vÃ  thÃ´ng bÃ¡o cho cÃ¡c bÃªn liÃªn quan khi cáº§n thiáº¿t.',
            ],
        ]);
    }

    public function personalDataReference() {
        $this->renderPolicyReference([
            'title' => 'ChÃ­nh sÃ¡ch xá»­ lÃ½ dá»¯ liá»‡u cÃ¡ nhÃ¢n',
            'eyebrow' => 'Quyá»n riÃªng tÆ° ngÆ°á»i dÃ¹ng',
            'summary' => 'ChÃ­nh sÃ¡ch nÃ y mÃ´ táº£ cÃ¡ch SkinSyntax tiáº¿p nháº­n, sá»­ dá»¥ng, lÆ°u trá»¯, chia sáº» cÃ³ kiá»ƒm soÃ¡t vÃ  báº£o vá»‡ dá»¯ liá»‡u cÃ¡ nhÃ¢n cá»§a ngÆ°á»i dÃ¹ng trong toÃ n bá»™ vÃ²ng Ä‘á»i dá»‹ch vá»¥.',
            'highlights' => [
                'Dá»¯ liá»‡u cÃ¡ nhÃ¢n cÃ³ thá»ƒ bao gá»“m thÃ´ng tin nháº­n dáº¡ng, thÃ´ng tin liÃªn há»‡, Ä‘á»‹a chá»‰ giao hÃ ng, lá»‹ch sá»­ mua sáº¯m, pháº£n há»“i sáº£n pháº©m, dá»¯ liá»‡u kháº£o sÃ¡t da vÃ  dá»¯ liá»‡u ká»¹ thuáº­t phá»¥c vá»¥ báº£o máº­t há»‡ thá»‘ng.',
                'SkinSyntax xá»­ lÃ½ dá»¯ liá»‡u cÃ¡ nhÃ¢n trÃªn cÆ¡ sá»Ÿ sá»± Ä‘á»“ng Ã½ cá»§a ngÆ°á»i dÃ¹ng, nhu cáº§u thá»±c hiá»‡n há»£p Ä‘á»“ng mua bÃ¡n, nghÄ©a vá»¥ phÃ¡p lÃ½ hoáº·c lá»£i Ã­ch há»£p phÃ¡p liÃªn quan Ä‘áº¿n báº£o máº­t vÃ  váº­n hÃ nh dá»‹ch vá»¥.',
                'Dá»¯ liá»‡u Ä‘Æ°á»£c lÆ°u giá»¯ trong thá»i gian cáº§n thiáº¿t Ä‘á»ƒ hoÃ n thÃ nh má»¥c Ä‘Ã­ch thu tháº­p, giáº£i quyáº¿t tranh cháº¥p, há»— trá»£ háº­u mÃ£i vÃ  Ä‘Ã¡p á»©ng yÃªu cáº§u lÆ°u trá»¯ theo quy Ä‘á»‹nh phÃ¡p luáº­t hiá»‡n hÃ nh.',
                'NgÆ°á»i dÃ¹ng cÃ³ quyá»n Ä‘á»“ng Ã½, tá»« chá»‘i, rÃºt láº¡i sá»± Ä‘á»“ng Ã½, yÃªu cáº§u cung cáº¥p báº£n sao dá»¯ liá»‡u, yÃªu cáº§u chá»‰nh sá»­a hoáº·c Ä‘á» nghá»‹ xÃ³a dá»¯ liá»‡u náº¿u viá»‡c xÃ³a khÃ´ng xung Ä‘á»™t vá»›i nghÄ©a vá»¥ lÆ°u trá»¯ báº¯t buá»™c.',
                'SkinSyntax cÃ³ thá»ƒ sá»­ dá»¥ng cookie hoáº·c cÃ´ng nghá»‡ tÆ°Æ¡ng Ä‘Æ°Æ¡ng cho chá»©c nÄƒng Ä‘Äƒng nháº­p, ghi nhá»› tÃ¹y chá»n, thá»‘ng kÃª vÃ  tá»‘i Æ°u tráº£i nghiá»‡m; ngÆ°á»i dÃ¹ng cÃ³ thá»ƒ tá»± Ä‘iá»u chá»‰nh báº±ng cÃ i Ä‘áº·t trÃ¬nh duyá»‡t cá»§a mÃ¬nh.',
                'Khi cÃ³ cáº­p nháº­t quan trá»ng liÃªn quan Ä‘áº¿n pháº¡m vi xá»­ lÃ½ dá»¯ liá»‡u cÃ¡ nhÃ¢n, SkinSyntax sáº½ cÃ´ng bá»‘ phiÃªn báº£n má»›i trÃªn website Ä‘á»ƒ ngÆ°á»i dÃ¹ng chá»§ Ä‘á»™ng theo dÃµi.',
            ],
        ]);
    }

    public function storeNetwork(): void {
        $this->render('info/store-network', [
            'title' => 'Há»‡ thá»‘ng cá»­a hÃ ng SkinSyntax',
            'eyebrow' => 'Há»‡ thá»‘ng phá»¥c vá»¥',
            'summary' => 'SkinSyntax Ä‘ang váº­n hÃ nh theo mÃ´ hÃ¬nh online-first: Æ°u tiÃªn tra cá»©u sáº£n pháº©m, tÆ° váº¥n routine, há»— trá»£ Ä‘Æ¡n hÃ ng vÃ  xá»­ lÃ½ sau mua ngay trÃªn website. ThÃ´ng tin Ä‘iá»ƒm há»— trá»£ trá»±c tiáº¿p sáº½ Ä‘Æ°á»£c cáº­p nháº­t theo tá»«ng giai Ä‘oáº¡n má»Ÿ rá»™ng.',
            'stats' => [
                ['value' => 'ToÃ n quá»‘c', 'label' => 'Pháº¡m vi phá»¥c vá»¥ qua kÃªnh online'],
                ['value' => '08:00 - 22:00', 'label' => 'Khung giá» há»— trá»£ khÃ¡ch hÃ ng'],
                ['value' => '1900 0000', 'label' => 'Hotline tiáº¿p nháº­n nhanh'],
            ],
            'channels' => [
                [
                    'title' => 'Mua sáº¯m online táº­p trung',
                    'text' => 'Tra cá»©u toÃ n bá»™ danh má»¥c, so sÃ¡nh thÃ nh pháº§n, kiá»ƒm tra giÃ¡ bÃ¡n vÃ  Ä‘áº·t hÃ ng trá»±c tiáº¿p trÃªn website SkinSyntax mÃ  khÃ´ng cáº§n chuyá»ƒn kÃªnh.',
                    'icon' => 'fa-solid fa-bag-shopping',
                ],
                [
                    'title' => 'TÆ° váº¥n AI vÃ  chat há»— trá»£',
                    'text' => 'Báº¡n cÃ³ thá»ƒ há»i AI vá» routine, thÃ nh pháº§n, sáº£n pháº©m phÃ¹ há»£p hoáº·c má»Ÿ khung chat há»— trá»£ Ä‘á»ƒ trao Ä‘á»•i trá»±c tiáº¿p vá»›i bá»™ pháº­n chÄƒm sÃ³c khÃ¡ch hÃ ng.',
                    'icon' => 'fa-solid fa-headset',
                ],
                [
                    'title' => 'Theo dÃµi Ä‘Æ¡n hÃ ng rÃµ tráº¡ng thÃ¡i',
                    'text' => 'Luá»“ng mua hÃ ng cá»§a SkinSyntax Æ°u tiÃªn tráº¡ng thÃ¡i rÃµ rÃ ng tá»« lÃºc Ä‘áº·t Ä‘Æ¡n, Ã¡p voucher, thanh toÃ¡n Ä‘áº¿n bÆ°á»›c hoÃ n táº¥t vÃ  háº­u mÃ£i.',
                    'icon' => 'fa-solid fa-truck-fast',
                ],
            ],
            'serviceSteps' => [
                'BÆ°á»›c 1: TÃ¬m sáº£n pháº©m hoáº·c lÃ m kháº£o sÃ¡t da Ä‘á»ƒ há»‡ thá»‘ng hiá»ƒu nhu cáº§u chÄƒm sÃ³c da cá»§a báº¡n.',
                'BÆ°á»›c 2: Äáº·t hÃ ng, theo dÃµi Ä‘Æ¡n vÃ  lÆ°u lá»‹ch sá»­ mua sáº¯m ngay trong tÃ i khoáº£n SkinSyntax.',
                'BÆ°á»›c 3: Khi cáº§n há»— trá»£ sau mua, má»Ÿ chat há»— trá»£ hoáº·c gá»i hotline Ä‘á»ƒ Ä‘Æ°á»£c hÆ°á»›ng dáº«n tiáº¿p tá»¥c.',
            ],
            'helpLinks' => [
                ['label' => 'KhÃ¡m phÃ¡ toÃ n bá»™ sáº£n pháº©m', 'url' => BASE_URL . '/index.php?r=tatca'],
                ['label' => 'Má»Ÿ gá»£i Ã½ routine AI', 'url' => BASE_URL . '/index.php?r=goiy'],
                ['label' => 'Trung tÃ¢m há»— trá»£ khÃ¡ch hÃ ng', 'url' => BASE_URL . '/index.php?r=ho_tro_khach_hang'],
            ],
        ]);
    }

    public function warrantyCenter(): void {
        $this->render('info/service-hub', [
            'title' => 'Báº£o hÃ nh vÃ  há»— trá»£ sau mua',
            'eyebrow' => 'ChÄƒm sÃ³c sau bÃ¡n',
            'summary' => 'SkinSyntax tiáº¿p nháº­n yÃªu cáº§u liÃªn quan Ä‘áº¿n lá»—i sáº£n pháº©m, hÆ°á»›ng dáº«n Ä‘á»•i tráº£ há»£p lá»‡, xÃ¡c minh hÃ³a Ä‘Æ¡n vÃ  Ä‘iá»u phá»‘i há»— trá»£ vá»›i nhÃ  cung cáº¥p khi cáº§n.',
            'sections' => [
                [
                    'title' => 'Pháº¡m vi tiáº¿p nháº­n',
                    'items' => [
                        'Sáº£n pháº©m nháº­n sai, thiáº¿u phá»¥ kiá»‡n, lá»—i do váº­n chuyá»ƒn hoáº·c cÃ³ dáº¥u hiá»‡u báº¥t thÆ°á»ng khi má»Ÿ há»™p.',
                        'Sáº£n pháº©m cÃ³ chÃ­nh sÃ¡ch báº£o hÃ nh riÃªng tá»« nhÃ  phÃ¢n phá»‘i hoáº·c cáº§n xÃ¡c minh tem, mÃ£ lÃ´, hÃ³a Ä‘Æ¡n mua hÃ ng.',
                        'YÃªu cáº§u kiá»ƒm tra tÃ¬nh tráº¡ng Ä‘Æ¡n hÃ ng sau mua, bá»• sung thÃ´ng tin hoáº·c hÆ°á»›ng dáº«n gá»­i láº¡i sáº£n pháº©m Ä‘á»ƒ Ä‘á»‘i soÃ¡t.',
                    ],
                ],
                [
                    'title' => 'ThÃ´ng tin cáº§n chuáº©n bá»‹',
                    'items' => [
                        'MÃ£ Ä‘Æ¡n hÃ ng hoáº·c sá»‘ Ä‘iá»‡n thoáº¡i dÃ¹ng khi mua sáº¯m.',
                        'TÃªn sáº£n pháº©m, sá»‘ lÆ°á»£ng gáº·p váº¥n Ä‘á» vÃ  mÃ´ táº£ tÃ¬nh tráº¡ng thá»±c táº¿.',
                        'HÃ¬nh áº£nh/video lÃºc má»Ÿ kiá»‡n hÃ ng náº¿u cÃ³, Ä‘á»ƒ SkinSyntax rÃºt ngáº¯n thá»i gian xÃ¡c minh.',
                    ],
                ],
                [
                    'title' => 'Quy trÃ¬nh xá»­ lÃ½',
                    'items' => [
                        'Tiáº¿p nháº­n yÃªu cáº§u qua hotline hoáº·c chat há»— trá»£ vÃ  xÃ¡c nháº­n thÃ´ng tin Ä‘Æ¡n.',
                        'ÄÃ¡nh giÃ¡ tÃ¬nh tráº¡ng sáº£n pháº©m, kiá»ƒm tra chá»©ng tá»« mua hÃ ng vÃ  hÆ°á»›ng xá»­ lÃ½ phÃ¹ há»£p.',
                        'Pháº£n há»“i phÆ°Æ¡ng Ã¡n tiáº¿p theo: Ä‘á»•i sáº£n pháº©m, hoÃ n tiá»n, bá»• sung thÃ´ng tin hoáº·c liÃªn há»‡ nhÃ  cung cáº¥p.',
                    ],
                ],
            ],
            'supportCard' => [
                'title' => 'LiÃªn há»‡ báº£o hÃ nh',
                'text' => 'Khung giá» Æ°u tiÃªn tiáº¿p nháº­n lÃ  08:00 - 22:00 má»—i ngÃ y. CÃ¡c yÃªu cáº§u cÃ³ Ä‘á»§ mÃ£ Ä‘Æ¡n vÃ  mÃ´ táº£ tÃ¬nh tráº¡ng sáº½ Ä‘Æ°á»£c xá»­ lÃ½ nhanh hÆ¡n.',
                'bullets' => [
                    'Hotline: 1900 0000',
                    'KÃªnh nhanh: chat há»— trá»£ trÃªn website',
                    'Äá»‘i chiáº¿u chÃ­nh sÃ¡ch chung: Ä‘á»•i tráº£, báº£o máº­t, Ä‘iá»u kiá»‡n giao dá»‹ch',
                ],
            ],
            'actions' => [
                ['label' => 'Má»Ÿ chat há»— trá»£', 'url' => BASE_URL . '/index.php?r=lichsuchat'],
                ['label' => 'Xem Ä‘iá»u kiá»‡n giao dá»‹ch', 'url' => BASE_URL . '/index.php?r=dieu_kien_giao_dich'],
                ['label' => 'ChÃ­nh sÃ¡ch báº£o máº­t', 'url' => BASE_URL . '/index.php?r=chinh_sach_bao_mat'],
            ],
        ]);
    }

    public function customerSupport(): void {
        $this->render('info/service-hub', [
            'title' => 'Trung tÃ¢m há»— trá»£ khÃ¡ch hÃ ng',
            'eyebrow' => 'Customer care',
            'summary' => 'Trang nÃ y tá»•ng há»£p cÃ¡c kÃªnh há»— trá»£, cÃ¢u há»i thÆ°á»ng gáº·p vÃ  nhá»¯ng Ä‘Æ°á»ng dáº«n quan trá»ng Ä‘á»ƒ báº¡n xá»­ lÃ½ nhanh cÃ¡c tÃ¬nh huá»‘ng trÆ°á»›c, trong vÃ  sau khi mua sáº¯m.',
            'sections' => [
                [
                    'title' => 'KÃªnh há»— trá»£ chÃ­nh',
                    'items' => [
                        'Hotline 1900 0000 Ä‘á»ƒ xá»­ lÃ½ cÃ¡c trÆ°á»ng há»£p cáº§n pháº£n há»“i ngay.',
                        'Chat há»— trá»£ trÃªn website Ä‘á»ƒ theo dÃµi lá»‹ch sá»­ trao Ä‘á»•i vÃ  cáº­p nháº­t tráº¡ng thÃ¡i xá»­ lÃ½.',
                        'AI chat Ä‘á»ƒ há»i nhanh vá» routine, thÃ nh pháº§n, xung Ä‘á»™t hoáº¡t cháº¥t vÃ  gá»£i Ã½ sáº£n pháº©m.',
                    ],
                ],
                [
                    'title' => 'NhÃ³m váº¥n Ä‘á» thÆ°á»ng gáº·p',
                    'items' => [
                        'ÄÄƒng kÃ½ tÃ i khoáº£n, xÃ¡c thá»±c OTP, quÃªn máº­t kháº©u vÃ  cáº­p nháº­t há»“ sÆ¡ cÃ¡ nhÃ¢n.',
                        'Äáº·t hÃ ng, Ã¡p voucher, Ã¡p Ä‘iá»ƒm, chá»n Ä‘á»‹a chá»‰ nháº­n hÃ ng vÃ  thanh toÃ¡n.',
                        'Theo dÃµi Ä‘Æ¡n, há»§y Ä‘Æ¡n há»£p lá»‡, Ä‘Ã¡nh giÃ¡ sáº£n pháº©m vÃ  há»— trá»£ sau mua.',
                    ],
                ],
                [
                    'title' => 'Äiá»ƒm Ä‘áº¿n nhanh',
                    'items' => [
                        'HÆ°á»›ng dáº«n nháº­n OTP náº¿u báº¡n chÆ°a láº¥y Ä‘Æ°á»£c mÃ£ xÃ¡c thá»±c.',
                        'Kháº£o sÃ¡t da vÃ  gá»£i Ã½ routine náº¿u báº¡n muá»‘n cÃ¡ nhÃ¢n hÃ³a tráº£i nghiá»‡m mua sáº¯m.',
                        'CÃ¡c chÃ­nh sÃ¡ch Ä‘iá»u kiá»‡n giao dá»‹ch, báº£o máº­t vÃ  dá»¯ liá»‡u cÃ¡ nhÃ¢n khi cáº§n tra cá»©u.',
                    ],
                ],
            ],
            'supportCard' => [
                'title' => 'Há»— trá»£ theo ngá»¯ cáº£nh',
                'text' => 'SkinSyntax Æ°u tiÃªn gom tráº£i nghiá»‡m há»— trá»£ ngay trong website Ä‘á»ƒ báº¡n khÃ´ng pháº£i chuyá»ƒn qua nhiá»u kÃªnh riÃªng láº».',
                'bullets' => [
                    'Tra cá»©u sáº£n pháº©m ngay trong header',
                    'Má»Ÿ AI chat á»Ÿ gÃ³c mÃ n hÃ¬nh Ä‘á»ƒ há»i nhanh',
                    'Khi Ä‘Äƒng nháº­p, báº¡n cÃ³ thá»ƒ lÆ°u lá»‹ch sá»­ chat vÃ  Ä‘Æ¡n hÃ ng trong cÃ¹ng má»™t tÃ i khoáº£n',
                ],
            ],
            'actions' => [
                ['label' => 'Xem hÆ°á»›ng dáº«n OTP', 'url' => BASE_URL . '/index.php?r=huong_dan_nhan_otp'],
                ['label' => 'Má»Ÿ routine AI', 'url' => BASE_URL . '/index.php?r=goiy'],
                ['label' => 'ChÃ­nh sÃ¡ch dá»¯ liá»‡u', 'url' => BASE_URL . '/index.php?r=chinh_sach_xu_ly_du_lieu'],
            ],
        ]);
    }

    private function renderPolicyReference(array $data): void {
        $this->render('info/policy-reference', $data);
    }

    private function getHighlightedCategories(): array {
        // Pipeline gom nhÃ³m vÃ  Ä‘áº¿m danh má»¥c báº±ng MongoDB
        $pipeline = [
            ['$match' => ['danh_muc_day_du' => ['$nin' => [null, '']]]],
            ['$group' => ['_id' => '$danh_muc_day_du', 'so_luong' => ['$sum' => 1]]],
            ['$sort' => ['so_luong' => -1]],
            ['$limit' => 6]
        ];

        $cursor = $this->pdo->san_pham->aggregate($pipeline);
        $result = [];
        
        foreach ($cursor as $doc) {
            $result[] = [
                'danh_muc_day_du' => (string) $doc['_id'],
                'so_luong' => (int) $doc['so_luong']
            ];
        }
        return $result;
    }

    public function giohang() {
        // Xá»­ lÃ½ POST requests
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $action = $_POST['action'] ?? null;
            $product_id = $_POST['product_id'] ?? null;
            
            if ($action === 'update_qty' && $product_id) {
                $qty = max(1, (int)($_POST['qty'] ?? 1));
                $_SESSION['gio_hang'][$product_id] = $qty;
                http_response_code(200);
                exit;
            } elseif ($action === 'delete' && $product_id) {
                unset($_SESSION['gio_hang'][$product_id]);
                http_response_code(200);
                exit;
            }
        }
        
        // Hiá»ƒn thá»‹ giá» hÃ ng
        $items = [];
        if (!empty($_SESSION['gio_hang'])) {
            foreach ($_SESSION['gio_hang'] as $product_id => $qty) {
                $product = $this->model->findById($product_id, true);
                if ($product && (!method_exists($this->model, 'isProductAvailable') || $this->model->isProductAvailable($product))) {
                    $stock = method_exists($this->model, 'getProductStock') ? $this->model->getProductStock($product) : null;
                    if ($stock !== null && (int)$qty > $stock) {
                        $_SESSION['gio_hang'][$product_id] = $stock;
                        $qty = $stock;
                    }
                    $items[$product_id] = [
                        'product' => $product,
                        'qty' => $qty
                    ];
                } else {
                    unset($_SESSION['gio_hang'][$product_id]);
                }
            }
        }
        $this->render('giohang', ['items' => $items]);
    }

    public function goiy() {
        $user = current_user() ?? [];
        $email = trim((string)($user['email'] ?? ''));
        $profile = $email !== '' ? $this->buildRecommendationProfile($email) : null;

        $this->render('goiy', [
            'isLoggedIn' => $email !== '',
            'needsSurvey' => !$this->hasCompletedSurvey($profile),
            'recommendationProfile' => $profile,
            'surveyUrl' => BASE_URL . '/index.php?r=khaosat',
        ]);
    }

    private function buildRecommendationProfile(string $email): ?array {
        $taiKhoanModel = new TaiKhoan($this->pdo);
        $khachHang = $taiKhoanModel->getKhachHangByEmail($email);
        if (!$khachHang) {
            return null;
        }

        $skinProfile = $taiKhoanModel->getSkinProfileByEmail($email) ?? [];
        $concerns = $this->splitProfileValues($khachHang['van_de_da'] ?? null);
        $avoidIngredients = $this->splitProfileValues($khachHang['thanh_phan_tranh'] ?? null);
        $avoidIngredients = array_values(array_filter($avoidIngredients, function (string $item): bool {
            $normalized = mb_strtolower($item, 'UTF-8');
            return $normalized !== 'khÃ´ng cÃ³ / khÃ´ng quan tÃ¢m' && $normalized !== 'khong co';
        }));

        $budget = isset($khachHang['ngan_sach']) && $khachHang['ngan_sach'] !== null
            ? (int)$khachHang['ngan_sach']
            : null;

        $recentKeywords = $taiKhoanModel->getTuKhoaGanDay($email, 4);
        $orderHistory = $taiKhoanModel->getOrderHistory((int)($khachHang['ma_kh'] ?? 0));
        $recentOrders = [];
        foreach (array_slice($orderHistory, 0, 3) as $order) {
            $productNames = [];
            foreach (array_slice($order['items'] ?? [], 0, 3) as $item) {
                $name = trim((string)($item['ten_san_pham'] ?? ''));
                if ($name !== '') {
                    $productNames[] = $name;
                }
            }

            $recentOrders[] = [
                'order_id' => (int)($order['ma_hoa_don'] ?? 0),
                'status' => trim((string)($order['trang_thai'] ?? '')),
                'items' => $productNames,
            ];
        }

        return [
            'customer_id' => (int)($khachHang['ma_kh'] ?? 0),
            'display_name' => trim((string)($khachHang['ho_ten'] ?? 'báº¡n')),
            'gioi_tinh' => trim((string)($khachHang['gioi_tinh'] ?? '')),
            'nam_sinh' => trim((string)($khachHang['nam_sinh'] ?? '')),
            'skin_type' => trim((string)($skinProfile['loai_da'] ?? '')),
            'concerns' => $concerns,
            'avoid_ingredients' => $avoidIngredients,
            'budget' => $budget,
            'budget_label' => $budget !== null && $budget > 0
                ? number_format($budget, 0, ',', '.') . ' VND'
                : 'KhÃ´ng giá»›i háº¡n',
            'sensitivity' => trim((string)($khachHang['muc_do_nhay_cam'] ?? '')),
            'recent_keywords' => array_values(array_filter(array_map('strval', $recentKeywords))),
            'recent_orders' => $recentOrders,
        ];
    }

    private function hasCompletedSurvey(?array $profile): bool {
        if (!$profile) {
            return false;
        }

        return ($profile['gioi_tinh'] ?? '') !== ''
            && ($profile['nam_sinh'] ?? '') !== ''
            && (
                ($profile['skin_type'] ?? '') !== ''
                || !empty($profile['concerns'])
                || !empty($profile['budget'])
            );
    }

    private function splitProfileValues(?string $raw): array {
        $text = trim((string)($raw ?? ''));
        if ($text === '') {
            return [];
        }

        $parts = preg_split('/\s*[,|]\s*/u', $text) ?: [];
        $values = [];
        foreach ($parts as $part) {
            $value = trim((string)$part);
            if ($value !== '' && stripos($value, 'loaida:') !== 0) {
                $values[] = $value;
            }
        }

        return array_values(array_unique($values));
    }

    private function buildCheckoutPreview(array $checkoutItems): array {
        $items = [];
        $subtotal = 0;

        foreach ($checkoutItems as $productId => $qty) {
            $product = $this->model->findById((string)$productId);
            if (!$product) {
                continue;
            }

            $qty = max(1, (int)$qty);
            $unitPrice = (int)($product['gia_ban'] ?? 0);
            if ($unitPrice <= 0) {
                $unitPrice = (int)($product['gia_thi_truong'] ?? 0);
            }

            $lineTotal = $unitPrice * $qty;
            $subtotal += $lineTotal;

            $items[] = [
                'id' => (string)$productId,
                'product' => $product,
                'qty' => $qty,
                'unit_price' => $unitPrice,
                'line_total' => $lineTotal,
            ];
        }

        return [
            'items' => $items,
            'subtotal' => $subtotal,
        ];
    }

    private function getAppliedVoucher(int $subtotal, bool $flashWhenInvalid = false): ?array {
        $voucherCode = trim((string)(($_SESSION['checkout_voucher']['code'] ?? '')));
        if ($voucherCode === '') {
            return null;
        }

        $result = $this->voucherModel->validateForCheckout($voucherCode, $subtotal);
        if (empty($result['ok'])) {
            unset($_SESSION['checkout_voucher']);
            if ($flashWhenInvalid && !empty($result['message'])) {
                set_flash('error', (string)$result['message']);
            }
            return null;
        }

        $_SESSION['checkout_voucher'] = [
            'code' => (string)($result['voucher']['ma_code'] ?? $voucherCode),
        ];

        return $result;
    }

    private function getCurrentCheckoutCustomer(): ?array {
        $user = current_user() ?? [];
        $email = trim((string)($user['email'] ?? ''));
        if ($email === '') {
            return null;
        }

        $tkModel = new TaiKhoan($this->pdo);
        return $tkModel->getKhachHangByEmail($email);
    }

    private function getAppliedPointsRedemption(int $discountableSubtotal, ?array $customer = null, bool $flashWhenInvalid = false): array {
        $customer = $customer ?? $this->getCurrentCheckoutCustomer();
        $availablePoints = max(0, (int)($customer['diemtl'] ?? 0));
        $requestedPoints = max(0, (int)($_SESSION['checkout_points']['points'] ?? 0));
        $maxPointsByAmount = (int)floor(max(0, $discountableSubtotal) / self::POINT_VALUE_VND);
        $usablePoints = min($requestedPoints, $availablePoints, $maxPointsByAmount);

        if ($requestedPoints > 0 && $usablePoints <= 0) {
            unset($_SESSION['checkout_points']);
            if ($flashWhenInvalid) {
                set_flash('error', 'Äiá»ƒm tÃ­ch lÅ©y khÃ´ng cÃ²n Ä‘á»§ Ä‘á»ƒ Ã¡p dá»¥ng cho Ä‘Æ¡n hÃ ng hiá»‡n táº¡i.');
            }
        } elseif ($requestedPoints > 0 && $usablePoints !== $requestedPoints) {
            $_SESSION['checkout_points'] = ['points' => $usablePoints];
            if ($flashWhenInvalid) {
                set_flash('error', 'Äiá»ƒm Ã¡p dá»¥ng Ä‘Ã£ Ä‘Æ°á»£c Ä‘iá»u chá»‰nh theo sá»‘ dÆ° hiá»‡n cÃ³ hoáº·c giÃ¡ trá»‹ Ä‘Æ¡n hÃ ng.');
            }
        }

        return [
            'available_points' => $availablePoints,
            'points' => max(0, $usablePoints),
            'discount' => max(0, $usablePoints * self::POINT_VALUE_VND),
            'point_value_vnd' => self::POINT_VALUE_VND,
        ];
    }

    private function isQrTransferEnabled(): bool {
        return trim((string)(defined('BANK_TRANSFER_BANK_ID') ? BANK_TRANSFER_BANK_ID : '')) !== ''
            && trim((string)(defined('BANK_TRANSFER_ACCOUNT_NO') ? BANK_TRANSFER_ACCOUNT_NO : '')) !== ''
            && trim((string)(defined('BANK_TRANSFER_ACCOUNT_NAME') ? BANK_TRANSFER_ACCOUNT_NAME : '')) !== '';
    }

    private function getQrTransferConfig(): array {
        return [
            'enabled' => $this->isQrTransferEnabled(),
            'bank_id' => trim((string)(defined('BANK_TRANSFER_BANK_ID') ? BANK_TRANSFER_BANK_ID : '')),
            'bank_name' => trim((string)(defined('BANK_TRANSFER_BANK_NAME') ? BANK_TRANSFER_BANK_NAME : '')),
            'account_no' => trim((string)(defined('BANK_TRANSFER_ACCOUNT_NO') ? BANK_TRANSFER_ACCOUNT_NO : '')),
            'account_name' => trim((string)(defined('BANK_TRANSFER_ACCOUNT_NAME') ? BANK_TRANSFER_ACCOUNT_NAME : '')),
            'template' => trim((string)(defined('BANK_TRANSFER_QR_TEMPLATE') ? BANK_TRANSFER_QR_TEMPLATE : 'compact2')) ?: 'compact2',
        ];
    }

    private function buildVietQrUrl(int $amount, string $content): string {
        $config = $this->getQrTransferConfig();
        if (empty($config['enabled'])) {
            return '';
        }

        $base = 'https://img.vietqr.io/image/'
            . rawurlencode((string)$config['bank_id'])
            . '-'
            . rawurlencode((string)$config['account_no'])
            . '-'
            . rawurlencode((string)$config['template'])
            . '.png';

        $query = http_build_query([
            'amount' => max(0, $amount),
            'addInfo' => $content,
            'accountName' => (string)$config['account_name'],
        ]);

        return $base . '?' . $query;
    }

    private function getPaymentMethodLabel(string $method): string {
        $method = strtolower(trim($method));
        if ($method === 'bank_transfer_qr') {
            return 'Chuyá»ƒn khoáº£n qua QR';
        }

        return 'Thanh toÃ¡n khi nháº­n hÃ ng (COD)';
    }

    private function getSelectedCheckoutPaymentMethod(): string {
        $method = strtolower(trim((string)($_SESSION['checkout_payment_method'] ?? 'cod')));
        $allowed = ['cod'];
        if ($this->isQrTransferEnabled()) {
            $allowed[] = 'bank_transfer_qr';
        }

        return in_array($method, $allowed, true) ? $method : 'cod';
    }

    private function storeCheckoutPaymentMethodFromRequest(): void {
        $method = strtolower(trim((string)($_POST['hinh_thuc_thanh_toan'] ?? '')));
        if ($method === 'bank_transfer_qr' && $this->isQrTransferEnabled()) {
            $_SESSION['checkout_payment_method'] = 'bank_transfer_qr';
            return;
        }

        $_SESSION['checkout_payment_method'] = 'cod';
    }

    private function getDefaultCheckoutReceiver(?array $customer = null, array $user = []): array {
        $customer = $customer ?? $this->getCurrentCheckoutCustomer() ?? [];

        return [
            'ten_nguoi_nhan' => trim((string)($customer['ho_ten'] ?? ($user['ho_ten'] ?? ''))),
            'sdt_nguoi_nhan' => trim((string)($customer['so_dien_thoai'] ?? '')),
            'dia_chi_giao_hang' => trim((string)($customer['dia_chi'] ?? '')),
        ];
    }

    private function hasCompleteCheckoutReceiver(array $receiver): bool {
        return trim((string)($receiver['ten_nguoi_nhan'] ?? '')) !== ''
            && trim((string)($receiver['sdt_nguoi_nhan'] ?? '')) !== ''
            && trim((string)($receiver['dia_chi_giao_hang'] ?? '')) !== '';
    }

    private function extractCheckoutNewReceiverFromRequest(array $defaultReceiver = []): array {
        return [
            'ten_nguoi_nhan' => trim((string)($_POST['ten_nguoi_nhan'] ?? ($defaultReceiver['ten_nguoi_nhan'] ?? ''))),
            'sdt_nguoi_nhan' => trim((string)($_POST['sdt_nguoi_nhan'] ?? ($defaultReceiver['sdt_nguoi_nhan'] ?? ''))),
            'dia_chi_chi_tiet' => trim((string)($_POST['dia_chi_chi_tiet'] ?? '')),
            'phuong_xa' => trim((string)($_POST['phuong_xa'] ?? '')),
            'quan_huyen' => trim((string)($_POST['quan_huyen'] ?? '')),
            'tinh_thanh' => trim((string)($_POST['tinh_thanh'] ?? '')),
            'ghi_chu_giao_hang' => trim((string)($_POST['ghi_chu_giao_hang'] ?? '')),
            'save_as_default' => !empty($_POST['save_as_default']) ? '1' : '0',
        ];
    }

    private function buildDetailedShippingAddress(array $receiver): string {
        $parts = array_values(array_filter([
            trim((string)($receiver['dia_chi_chi_tiet'] ?? '')),
            trim((string)($receiver['phuong_xa'] ?? '')),
            trim((string)($receiver['quan_huyen'] ?? '')),
            trim((string)($receiver['tinh_thanh'] ?? '')),
        ], static fn(string $part): bool => $part !== ''));

        $address = implode(', ', $parts);
        $note = trim((string)($receiver['ghi_chu_giao_hang'] ?? ''));
        if ($address !== '' && $note !== '') {
            $address .= ' | Ghi chÃº: ' . $note;
        }

        return $address;
    }

    private function getCheckoutAddressChoice(bool $hasDefaultReceiver): string {
        $choice = strtolower(trim((string)($_SESSION['checkout_address_choice'] ?? '')));
        if (!in_array($choice, ['default', 'new'], true)) {
            $choice = $hasDefaultReceiver ? 'default' : 'new';
        }

        if ($choice === 'default' && !$hasDefaultReceiver) {
            return 'new';
        }

        return $choice;
    }

    private function storeCheckoutReceiverFromRequest(array $defaultReceiver): void {
        $hasDefaultReceiver = $this->hasCompleteCheckoutReceiver($defaultReceiver);
        $choice = strtolower(trim((string)($_POST['address_choice'] ?? ($hasDefaultReceiver ? 'default' : 'new'))));
        if (!in_array($choice, ['default', 'new'], true)) {
            $choice = $hasDefaultReceiver ? 'default' : 'new';
        }
        if ($choice === 'default' && !$hasDefaultReceiver) {
            $choice = 'new';
        }

        $_SESSION['checkout_address_choice'] = $choice;
        $_SESSION['checkout_new_receiver'] = $this->extractCheckoutNewReceiverFromRequest($defaultReceiver);
    }

    private function getCheckoutAddressState(array $defaultReceiver): array {
        $hasDefaultReceiver = $this->hasCompleteCheckoutReceiver($defaultReceiver);
        $storedNewReceiver = is_array($_SESSION['checkout_new_receiver'] ?? null)
            ? $_SESSION['checkout_new_receiver']
            : [];

        $newReceiver = array_merge([
            'ten_nguoi_nhan' => (string)($defaultReceiver['ten_nguoi_nhan'] ?? ''),
            'sdt_nguoi_nhan' => (string)($defaultReceiver['sdt_nguoi_nhan'] ?? ''),
            'dia_chi_chi_tiet' => '',
            'phuong_xa' => '',
            'quan_huyen' => '',
            'tinh_thanh' => '',
            'ghi_chu_giao_hang' => '',
            'save_as_default' => '0',
        ], $storedNewReceiver);
        $newReceiver['save_as_default'] = !empty($newReceiver['save_as_default']) ? '1' : '0';

        $choice = $this->getCheckoutAddressChoice($hasDefaultReceiver);
        $newAddress = $this->buildDetailedShippingAddress($newReceiver);
        $selectedReceiver = $choice === 'default' && $hasDefaultReceiver
            ? $defaultReceiver
            : [
                'ten_nguoi_nhan' => trim((string)($newReceiver['ten_nguoi_nhan'] ?? '')),
                'sdt_nguoi_nhan' => trim((string)($newReceiver['sdt_nguoi_nhan'] ?? '')),
                'dia_chi_giao_hang' => $newAddress,
            ];

        return [
            'address_choice' => $choice,
            'has_default_receiver' => $hasDefaultReceiver,
            'default_receiver' => $defaultReceiver,
            'new_receiver' => array_merge($newReceiver, ['dia_chi_giao_hang' => $newAddress]),
            'selected_receiver' => $selectedReceiver,
        ];
    }

    private function resolveCheckoutReceiverFromRequest(array $defaultReceiver): array {
        $hasDefaultReceiver = $this->hasCompleteCheckoutReceiver($defaultReceiver);
        $choice = strtolower(trim((string)($_POST['address_choice'] ?? ($hasDefaultReceiver ? 'default' : 'new'))));
        if (!in_array($choice, ['default', 'new'], true)) {
            $choice = $hasDefaultReceiver ? 'default' : 'new';
        }

        if ($choice === 'default') {
            if (!$hasDefaultReceiver) {
                return [
                    'ok' => false,
                    'message' => 'Äá»‹a chá»‰ máº·c Ä‘á»‹nh chÆ°a Ä‘á»§ thÃ´ng tin. Vui lÃ²ng chá»n Ä‘á»‹a chá»‰ má»›i Ä‘á»ƒ tiáº¿p tá»¥c.',
                ];
            }

            return [
                'ok' => true,
                'choice' => 'default',
                'receiver' => $defaultReceiver,
                'new_receiver' => $this->extractCheckoutNewReceiverFromRequest($defaultReceiver),
            ];
        }

        $newReceiver = $this->extractCheckoutNewReceiverFromRequest($defaultReceiver);
        $address = $this->buildDetailedShippingAddress($newReceiver);
        if (
            trim((string)($newReceiver['ten_nguoi_nhan'] ?? '')) === ''
            || trim((string)($newReceiver['sdt_nguoi_nhan'] ?? '')) === ''
            || trim((string)($newReceiver['dia_chi_chi_tiet'] ?? '')) === ''
            || trim((string)($newReceiver['phuong_xa'] ?? '')) === ''
            || trim((string)($newReceiver['quan_huyen'] ?? '')) === ''
            || trim((string)($newReceiver['tinh_thanh'] ?? '')) === ''
        ) {
            return [
                'ok' => false,
                'message' => 'Vui lÃ²ng Ä‘iá»n Ä‘áº§y Ä‘á»§ Ä‘á»‹a chá»‰ má»›i, bao gá»“m sá»‘ nhÃ , phÆ°á»ng xÃ£, quáº­n huyá»‡n vÃ  tá»‰nh thÃ nh.',
            ];
        }

        return [
            'ok' => true,
            'choice' => 'new',
            'receiver' => [
                'ten_nguoi_nhan' => trim((string)($newReceiver['ten_nguoi_nhan'] ?? '')),
                'sdt_nguoi_nhan' => trim((string)($newReceiver['sdt_nguoi_nhan'] ?? '')),
                'dia_chi_giao_hang' => $address,
            ],
            'new_receiver' => array_merge($newReceiver, ['dia_chi_giao_hang' => $address]),
        ];
    }

    private function jsonResponse(array $payload, int $status = 200): void {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        if (!array_key_exists('success', $payload)) {
            $payload['success'] = !empty($payload['ok']);
        }
        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    private function getWebhookSecret(): string {
        return trim((string)(defined('BANK_TRANSFER_WEBHOOK_SECRET') ? BANK_TRANSFER_WEBHOOK_SECRET : ''));
    }

    private function isSePayPollingEnabled(): bool {
        return (defined('SEPAY_POLLING_ENABLED') ? (bool)SEPAY_POLLING_ENABLED : false)
            && trim((string)(defined('SEPAY_API_TOKEN') ? SEPAY_API_TOKEN : '')) !== ''
            && trim((string)(defined('SEPAY_ACCOUNT_NUMBER') ? SEPAY_ACCOUNT_NUMBER : '')) !== '';
    }

    private function getSePayApiToken(): string {
        return trim((string)(defined('SEPAY_API_TOKEN') ? SEPAY_API_TOKEN : ''));
    }

    private function getSePayAuthorizationHeaders(): array {
        $token = $this->getSePayApiToken();
        if ($token === '') {
            return [];
        }

        return [
            'Authorization: Apikey ' . $token,
            'Authorization: Bearer ' . $token,
        ];
    }

    private function getSePayAccountNumber(): string {
        return trim((string)(defined('SEPAY_ACCOUNT_NUMBER') ? SEPAY_ACCOUNT_NUMBER : ''));
    }

    private function getAiRecommendationEndpoint(): string {
        $configured = defined('AI_RECOMMENDATION_ENDPOINT') ? (string)AI_RECOMMENDATION_ENDPOINT : '';
        if (trim($configured) !== '') {
            return trim($configured);
        }

        $envValue = getenv('AI_RECOMMENDATION_ENDPOINT');
        if ($envValue !== false && trim((string)$envValue) !== '') {
            return trim((string)$envValue);
        }

        return 'http://127.0.0.1:5000/api/recommend/explain';
    }

    private function getAiRecommendationTimeout(): int {
        $configured = defined('AI_RECOMMENDATION_TIMEOUT') ? (int)AI_RECOMMENDATION_TIMEOUT : 0;
        if ($configured > 0) {
            return $configured;
        }

        $envValue = getenv('AI_RECOMMENDATION_TIMEOUT');
        if ($envValue !== false && ctype_digit((string)$envValue)) {
            return max(3, (int)$envValue);
        }

        return 20;
    }

    private function getAiHybridRecommendationEndpoint(): string {
        $configured = defined('AI_HYBRID_RECOMMENDATION_ENDPOINT') ? (string)AI_HYBRID_RECOMMENDATION_ENDPOINT : '';
        if (trim($configured) !== '') {
            return trim($configured);
        }

        $envValue = getenv('AI_HYBRID_RECOMMENDATION_ENDPOINT');
        if ($envValue !== false && trim((string)$envValue) !== '') {
            return trim((string)$envValue);
        }

        return 'http://127.0.0.1:5000/api/recommend/hybrid';
    }

    private function getAiHybridRecommendationTimeout(): int {
        $configured = defined('AI_HYBRID_RECOMMENDATION_TIMEOUT') ? (int)AI_HYBRID_RECOMMENDATION_TIMEOUT : 0;
        if ($configured > 0) {
            return $configured;
        }

        $envValue = getenv('AI_HYBRID_RECOMMENDATION_TIMEOUT');
        if ($envValue !== false && ctype_digit((string)$envValue)) {
            return max(5, (int)$envValue);
        }

        return 25;
    }

    private function getAiChatEndpoint(): string {
        $configured = defined('AI_CHAT_ENDPOINT') ? (string)AI_CHAT_ENDPOINT : '';
        if (trim($configured) !== '') {
            return trim($configured);
        }

        $envValue = getenv('AI_CHAT_ENDPOINT');
        if ($envValue !== false && trim((string)$envValue) !== '') {
            return trim((string)$envValue);
        }

        return 'http://127.0.0.1:5001/api/chat';
    }

    private function getAiChatTimeout(): int {
        $configured = defined('AI_CHAT_TIMEOUT') ? (int)AI_CHAT_TIMEOUT : 0;
        if ($configured > 0) {
            return $configured;
        }

        $envValue = getenv('AI_CHAT_TIMEOUT');
        if ($envValue !== false && ctype_digit((string)$envValue)) {
            return max(5, (int)$envValue);
        }

        return 25;
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

    private function buildAiCartContext(): array {
        $cart = $_SESSION['gio_hang'] ?? [];
        if (!is_array($cart) || empty($cart)) {
            return [];
        }

        $items = [];
        foreach ($cart as $productId => $qty) {
            $product = $this->model->findById((string)$productId);
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

    private function buildAiRelevantProducts(string $message, int $limit = 4): array {
        $normalizedMessage = function_exists('mb_strtolower') ? mb_strtolower($message, 'UTF-8') : strtolower($message);
        $queries = [$message];

        $keywordGroups = [
            ['da dầu mụn', 'mụn', 'acne', 'oil control', 'salicylic acid', 'niacinamide'],
            ['dưỡng ẩm', 'cap am', 'cấp ẩm', 'hyaluronic acid', 'ceramide', 'b5'],
            ['thâm', 'nám', 'vitamin c', 'tranexamic acid', 'arbutin'],
            ['lão hóa', 'retinol', 'peptide', 'collagen'],
            ['làm sạch', 'sữa rửa mặt', 'tẩy trang', 'cleanser', 'micellar'],
        ];

        $stopWords = [
            'goi y', 'gợi ý', 'de xuat', 'đề xuất', 'san pham', 'sản phẩm', 'cho', 'toi', 'tôi',
            'minh', 'mình', 'voi', 'với', 'loai', 'loại', 'da', 'can', 'cần', 'tu van', 'tư vấn',
            'routine', 'skincare', 'giup', 'giúp', 'nhe', 'nhé', 'nha', 'ạ', 'a', 'oi', 'ơi'
        ];

        $messageForSearch = preg_replace('/[^\p{L}\p{N}\s]+/u', ' ', $normalizedMessage) ?? $normalizedMessage;
        $messageForSearch = preg_replace('/\s+/u', ' ', trim($messageForSearch)) ?? trim($messageForSearch);

        foreach ($stopWords as $stopWord) {
            $pattern = '/\b' . preg_quote($stopWord, '/') . '\b/u';
            $messageForSearch = preg_replace($pattern, ' ', $messageForSearch) ?? $messageForSearch;
        }

        $messageForSearch = preg_replace('/\s+/u', ' ', trim($messageForSearch)) ?? trim($messageForSearch);
        if ($messageForSearch !== '' && $messageForSearch !== $normalizedMessage) {
            $queries[] = $messageForSearch;
        }

        $tokens = preg_split('/\s+/u', $messageForSearch, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        if (count($tokens) >= 2) {
            $queries[] = implode(' ', array_slice($tokens, 0, min(4, count($tokens))));
        }

        if (str_contains($normalizedMessage, 'da dầu') || str_contains($normalizedMessage, 'da dau')) {
            $queries = array_merge($queries, ['kiềm dầu', 'oil control', 'mụn']);
        }

        if (str_contains($normalizedMessage, 'mụn') || str_contains($normalizedMessage, 'mun')) {
            $queries = array_merge($queries, ['mụn', 'salicylic acid', 'niacinamide']);
        }

        if (str_contains($normalizedMessage, 'nhạy cảm') || str_contains($normalizedMessage, 'nhay cam')) {
            $queries = array_merge($queries, ['da nhạy cảm', 'ceramide', 'panthenol']);
        }

        foreach ($keywordGroups as $groupKeywords) {
            foreach ($groupKeywords as $keyword) {
                $normalizedKeyword = function_exists('mb_strtolower') ? mb_strtolower($keyword, 'UTF-8') : strtolower($keyword);
                if (str_contains($normalizedMessage, $normalizedKeyword)) {
                    $queries = array_merge($queries, $groupKeywords);
                    break;
                }
            }
        }

        $products = [];
        $seen = [];

        $appendProduct = function (string $productId) use (&$products, &$seen, $limit): void {
            if ($productId === '' || isset($seen[$productId]) || count($products) >= $limit) {
                return;
            }

            $detail = $this->model->findById($productId, true);
            if (!$detail || !is_array($detail)) {
                return;
            }

            $seen[$productId] = true;
            $products[] = [
                'id' => (string)($detail['ma_san_pham'] ?? $productId),
                'name' => trim((string)($detail['ten_san_pham'] ?? '')),
                'brand' => trim((string)($detail['thuong_hieu'] ?? '')),
                'price' => (int)($detail['gia_ban'] ?? 0),
                'image_url' => resolve_image_url((string)($detail['link_hinh_anh'] ?? $detail['hinh_anh'] ?? '')),
                'detail_url' => BASE_URL . '/index.php?r=chitiet&id=' . rawurlencode((string)($detail['ma_san_pham'] ?? $productId)),
                'description' => trim((string)($detail['mo_ta'] ?? '')),
                'ingredients' => $this->extractIngredientSource($detail),
            ];
        };

        foreach (array_unique(array_filter(array_map('trim', $queries))) as $query) {
            if (count($products) >= $limit) {
                break;
            }

            foreach ($this->model->searchSuggestions($query, $limit, true) as $suggestion) {
                $appendProduct(trim((string)($suggestion['id'] ?? '')));
            }

            if (count($products) >= $limit) {
                break;
            }

            foreach ($this->model->searchLive($query, $limit, true) as $item) {
                $appendProduct(trim((string)($item['ma_san_pham'] ?? $item['id'] ?? '')));
                if (count($products) >= $limit) {
                    break;
                }
            }
        }

        if (count($products) < $limit) {
            foreach ($this->model->getTopTrending($limit * 2, true) as $item) {
                $appendProduct(trim((string)($item['id'] ?? '')));
                if (count($products) >= $limit) {
                    break;
                }
            }
        }

        return array_slice($products, 0, $limit);
    }

    /**
     * Chuáº©n hÃ³a sáº£n pháº©m tá»« Flask hybrid RAG (/api/chat) sang format widget chat.
     *
     * @param array<int, mixed> $rows
     * @return array<int, array<string, mixed>>
     */
    private function mapHybridProductsForChatWidget(array $rows): array {
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

    private function shouldAttachAiProducts(string $message, array $conflicts = []): bool {
        $normalized = function_exists('mb_strtolower') ? mb_strtolower($message, 'UTF-8') : strtolower($message);

        if (!empty($conflicts) && preg_match('/gio hang|giỏ hàng|routine|xung dot|xung đột|ket hop|kết hợp/u', $normalized)) {
            return true;
        }

        return preg_match(
            '/goi y|gợi ý|de xuat|đề xuất|nen mua|nên mua|nen dung|nên dùng|chon giup|chọn giúp|san pham nao|sản phẩm nào|serum nao|kem nao|toner nao|sua rua mat nao|tẩy trang nào|kem chống nắng nào|compare|so sanh|so sánh|dua ra vai san pham|đưa ra vài sản phẩm/u',
            $normalized
        ) === 1;
    }

    private function trimAiAnswer(string $answer): string {
        $answer = trim($answer);
        if ($answer === '') {
            return '';
        }

        $answer = preg_replace('/\n{3,}/', "\n\n", $answer) ?? $answer;

        $paragraphs = preg_split('/\n\s*\n/u', $answer) ?: [];
        $paragraphs = array_values(array_filter(array_map('trim', $paragraphs), static function (string $part): bool {
            return $part !== '';
        }));

        if (count($paragraphs) > 4) {
            $paragraphs = array_slice($paragraphs, 0, 4);
        }

        return trim(implode("\n\n", $paragraphs));
    }

    private function buildGenericIngredientSafetyGuidance(): string {
        $lines = [];
        $lines[] = 'Mình tóm tắt nhanh cách nhìn các nhóm treatment phổ biến để bạn tham khảo ngay:';
        $lines[] = '- Retinoid: hỗ trợ mụn và chống lão hóa, nên bắt đầu 2-3 tối mỗi tuần rồi tăng dần.';
        $lines[] = '- AHA/BHA: thiên về làm sạch bề mặt da, hỗ trợ bí tắc và sần; không nên chồng cùng retinoid trong một buổi nếu da còn yếu.';
        $lines[] = '- Vitamin C: thường hợp buổi sáng để hỗ trợ làm sáng và chống oxy hóa; nhớ đi kèm kem chống nắng.';
        $lines[] = '- Niacinamide, B5, ceramide, HA: nhóm phục hồi và cấp ẩm, thường dễ ghép hơn với treatment mạnh.';
        $lines[] = 'Cách dùng an toàn: chỉ nên ưu tiên 1 treatment mạnh trong mỗi routine, test từ tần suất thấp, dưỡng ẩm đủ và chống nắng đều.';
        $lines[] = 'Nếu bạn muốn, mình có thể giải thích kỹ hơn từng chất như retinol, BHA, vitamin C hoặc gợi ý cách xếp routine sáng/tối.';

        return implode("\n", $lines);
    }

    private function buildAiCommonKnowledgeResponse(string $message, array $profile = []): ?string {
        $normalized = function_exists('mb_strtolower') ? mb_strtolower($message, 'UTF-8') : strtolower($message);
        $skinType = trim((string)($profile['skin_type'] ?? ''));

        if (preg_match('/toner.*serum|serum.*toner/u', $normalized)) {
            return implode("\n", [
                'Toner và serum khác nhau ở mục đích và độ cô đặc:',
                '- Toner là bước mỏng nhẹ sau rửa mặt, giúp cân bằng da và chuẩn bị cho các bước sau.',
                '- Serum là bước tinh chất cô đặc hơn, dùng để nhắm vào mục tiêu cụ thể như cấp ẩm, làm sáng, phục hồi hay hỗ trợ mụn.',
                '- Thứ tự cơ bản là toner trước, serum sau, rồi mới đến kem dưỡng.'
            ]);
        }

        if (preg_match('/bha.*retinol|retinol.*bha/u', $normalized)) {
            return implode("\n", [
                'BHA và retinol đều mạnh, nhưng vai trò khác nhau:',
                '- BHA đi sâu vào lỗ chân lông, hợp khi da bí tắc, dầu nhiều, mụn đầu đen hoặc mụn ẩn.',
                '- Retinol thiên về hỗ trợ mụn kéo dài, bề mặt da, thâm sau mụn và chống lão hóa.',
                '- Nếu mới bắt đầu, không nên chồng cả hai trong cùng một tối. Hãy tách lịch, ví dụ BHA 2 tối và retinol 2 tối mỗi tuần.',
                '- Luôn dưỡng ẩm và chống nắng kỹ vì cả hai đều có thể làm da nhạy hơn.'
            ]);
        }

        if (preg_match('/retinol|retinoid/u', $normalized)) {
            return implode("\n", [
                'Cách dùng retinol an toàn cho người mới:',
                '- Bắt đầu 2-3 tối mỗi tuần, lượng bằng hạt đậu cho toàn mặt.',
                '- Dùng trên da khô, sau đó khóa ẩm bằng kem dưỡng phục hồi.',
                '- Tránh dùng chung buổi với AHA/BHA nồng độ mạnh nếu da còn yếu.',
                '- Nếu đỏ rát hoặc bong nhiều, giảm tần suất trước khi tăng tiếp.'
            ]);
        }

        if (preg_match('/vitamin c/u', $normalized)) {
            return implode("\n", [
                'Vitamin C thường hợp buổi sáng hơn:',
                '- Mục tiêu chính là hỗ trợ làm sáng da và chống oxy hóa.',
                '- Nên đi cùng kem chống nắng để tối ưu hiệu quả bảo vệ da ban ngày.',
                '- Nếu da nhạy cảm, hãy bắt đầu từ tần suất thấp hoặc chọn dẫn xuất nhẹ hơn dạng L-AA mạnh.'
            ]);
        }

        if (preg_match('/niacinamide/u', $normalized)) {
            return implode("\n", [
                'Niacinamide là hoạt chất khá dễ phối trong routine:',
                '- Hỗ trợ điều tiết dầu, làm dịu, cải thiện bề mặt và hỗ trợ đều màu da.',
                '- Có thể dùng sáng hoặc tối, thường ghép được với B5, HA, ceramide và đa số routine phục hồi.',
                '- Nếu sản phẩm nồng độ cao làm châm chích, hãy giảm tần suất hoặc đổi sang nồng độ vừa hơn.'
            ]);
        }

        if (preg_match('/ceramide/u', $normalized)) {
            return implode("\n", [
                'Ceramide là nhóm hỗ trợ phục hồi hàng rào bảo vệ da:',
                '- Hợp khi da khô, yếu, dễ kích ứng hoặc đang treatment mạnh.',
                '- Thường nên ghép cùng kem dưỡng hoặc routine phục hồi buổi tối.',
                '- Ceramide không phải treatment mạnh, nên nhìn chung khá dễ kết hợp.'
            ]);
        }

        if (preg_match('/ha\b|hyaluronic/u', $normalized)) {
            return implode("\n", [
                'HA hay hyaluronic acid chủ yếu thiên về cấp nước:',
                '- Hợp với da thiếu ẩm, da treatment hoặc thời điểm da căng rít.',
                '- Nên dùng trên nền da còn hơi ẩm rồi khóa lại bằng kem dưỡng để giữ nước tốt hơn.',
                '- Nếu chỉ dùng HA mà không có bước khóa ẩm, da vẫn có thể thấy khô lại nhanh.'
            ]);
        }

        if (preg_match('/aha.*bha|bha.*aha|aha va bha|aha vá»›i bha/u', $normalized)) {
            return implode("\n", [
                'AHA và BHA khác nhau ở vùng tác động chính:',
                '- AHA thiên về bề mặt da, hỗ trợ da xỉn màu, sần và bề mặt không đều.',
                '- BHA đi sâu vào lỗ chân lông hơn, hợp da dầu, bí tắc và mụn đầu đen.',
                '- Nếu mới dùng acid, không cần chồng cả hai ngay từ đầu. Hãy chọn loại sát vấn đề da hơn.'
            ]);
        }

        if (preg_match('/kem chong nang|kem chá»‘ng náº¯ng|sunscreen/u', $normalized)) {
            return implode("\n", [
                'Kem chống nắng là bước bảo vệ bắt buộc vào ban ngày:',
                '- Bôi ở cuối routine sáng.',
                '- Nếu có treatment như retinol, AHA/BHA hoặc vitamin C, chống nắng càng quan trọng hơn.',
                '- Thoa đủ lượng và dặm lại khi ở ngoài nắng lâu mới hiệu quả thực tế.'
            ]);
        }

        if (preg_match('/tay trang|táº©y trang|double cleansing|lam sach kep|lÃ m sáº¡ch kÃ©p/u', $normalized)) {
            return implode("\n", [
                'Làm sạch kép phù hợp khi bạn có chống nắng đậm, makeup hoặc da dầu dễ bí:',
                '- Bước 1 là tẩy trang để hòa tan lớp chống nắng, dầu và bụi bẩn bám chặt.',
                '- Bước 2 là sữa rửa mặt để làm sạch lại nền da.',
                '- Nếu da rất khô hoặc ít makeup, không phải lúc nào cũng cần làm sạch kép quá mạnh.'
            ]);
        }

        if (preg_match('/da nhay cam|da nháº¡y cáº£m|sensitive skin/u', $normalized)) {
            return implode("\n", [
                'Da nhạy cảm nên đi theo hướng ít bước nhưng ổn định:',
                '- Ưu tiên làm sạch dịu, dưỡng phục hồi và chống nắng đều.',
                '- Khi thêm treatment, chỉ nên thêm từng món một để dễ theo dõi phản ứng.',
                '- Các nhóm như B5, HA, ceramide, niacinamide nồng độ vừa thường dễ bắt đầu hơn treatment mạnh.'
            ]);
        }

        if (preg_match('/má»¥n áº©n|mun an|má»¥n Ä‘áº§u Ä‘en|mun dau den/u', $normalized)) {
            return implode("\n", [
                'Với bí tắc, mụn ẩn hoặc mụn đầu đen, hướng xử lý thường là làm sạch vừa đủ và giảm tắc nghẽn:',
                '- BHA là lựa chọn hay gặp vì thiên về lỗ chân lông.',
                '- Không cần dùng quá nhiều treatment cùng lúc vì da dễ kích ứng rồi mụn kéo dài hơn.',
                '- Hãy đi kèm dưỡng phục hồi và chống nắng để da chịu treatment tốt hơn.'
            ]);
        }

        if (preg_match('/routine sÃ¡ng|routine sang|buá»•i sÃ¡ng|buoi sang/u', $normalized)) {
            return implode("\n", [
                'Routine sáng cơ bản nên đi theo thứ tự nhẹ và bảo vệ:',
                '- Sữa rửa mặt dịu nhẹ.',
                '- Serum mục tiêu nếu cần, ví dụ vitamin C hoặc niacinamide.',
                '- Kem dưỡng nếu da cần thêm ẩm.',
                '- Kem chống nắng là bước bắt buộc.'
            ]);
        }

        if (preg_match('/routine tá»‘i|routine toi|buá»•i tá»‘i|buoi toi/u', $normalized)) {
            return implode("\n", [
                'Routine tối nên ưu tiên làm sạch và treatment có kiểm soát:',
                '- Tẩy trang nếu có chống nắng hoặc makeup.',
                '- Sữa rửa mặt.',
                '- Treatment chính như retinol hoặc BHA, chỉ nên chọn 1 treatment mạnh mỗi buổi.',
                '- Kem dưỡng phục hồi để giảm kích ứng.'
            ]);
        }

        if (preg_match('/da dầu mụn|da dau mun/u', $normalized)) {
            $skinHint = $skinType !== '' ? (' Với hồ sơ hiện tại của bạn đang nghiêng về ' . $skinType . ',') : '';
            return implode("\n", [
                'Da dầu mụn nên ưu tiên routine gọn và ổn định.' . $skinHint,
                '- Làm sạch dịu nhưng đủ, tránh chà rửa quá mạnh.',
                '- Chọn 1 treatment chính như BHA hoặc retinoid thay vì dùng nhiều hoạt chất mạnh cùng lúc.',
                '- Bổ sung bước phục hồi với B5, HA, niacinamide hoặc ceramide để da chịu treatment tốt hơn.',
                '- Chống nắng đều mỗi ngày để hạn chế thâm sau mụn.'
            ]);
        }

        if (preg_match('/da khô|da kho/u', $normalized)) {
            return implode("\n", [
                'Da khô nên ưu tiên giữ nước và giảm mất nước qua da:',
                '- Dùng sữa rửa mặt dịu, tránh làm sạch quá gắt.',
                '- Tăng các bước cấp nước và khóa ẩm như HA, B5, ceramide và kem dưỡng phù hợp.',
                '- Nếu dùng treatment, hãy tăng chậm hơn và theo dõi độ khô rát của da.'
            ]);
        }

        if (preg_match('/da hỗn hợp|da hon hop/u', $normalized)) {
            return implode("\n", [
                'Da hỗn hợp thường cần routine cân bằng hơn là quá nặng về một phía:',
                '- Tập trung kiểm dầu vừa phải ở vùng dễ đổ dầu nhưng vẫn giữ ẩm cho vùng khô.',
                '- Có thể chọn serum nhẹ và kem dưỡng không quá bí để dùng toàn mặt.',
                '- Treatment nên tăng từ từ để tránh vùng khô bị kích ứng trước.'
            ]);
        }

        return null;
    }

    private function detectAiIntent(string $message): string {
        $normalized = function_exists('mb_strtolower') ? mb_strtolower($message, 'UTF-8') : strtolower($message);

        if (preg_match('/xin chao|chao ai|hello|\bhi\b/u', $normalized)) {
            return 'greeting';
        }

        if (preg_match('/gio hang|giỏ hàng|xung dot|xung đột|ket hop|kết hợp|routine hiện tại|routine hien tai|phan tich nhanh gio hang|phân tích nhanh giỏ hàng/u', $normalized)) {
            return 'cart_conflict';
        }

        if (preg_match('/thanh phan|thành phần|ingredient|retinol|vitamin c|aha|bha|niacinamide|ceramide|treatment/u', $normalized)) {
            return 'ingredient_analysis';
        }

        if ($this->shouldAttachAiProducts($message)) {
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
            'mình là SkinSyntax AI. Mình có thể hỗ trợ phân tích thành phần, kiểm tra routine, cảnh báo xung đột trong giỏ hàng và gợi ý sản phẩm khi bạn cần.',
            'Bạn có thể hỏi tiếp như: retinol dùng sao cho an toàn, routine da dầu mụn nên ưu tiên gì, hoặc nhờ mình phân tích giỏ hàng hiện tại.'
        ]);
    }

    private function buildAiAssistantPrompt(string $message, array $history, array $profile, array $cartItems, array $conflicts, array $products, string $currentProductId = ''): string {
        $historyLines = [];
        foreach (array_slice($history, -6) as $turn) {
            if (!is_array($turn)) {
                continue;
            }
            $role = trim((string)($turn['role'] ?? 'user')) === 'assistant' ? 'AI' : 'Khach';
            $content = trim((string)($turn['content'] ?? ''));
            if ($content === '') {
                continue;
            }
            $historyLines[] = $role . ': ' . $content;
        }

        $profileSummary = [
            'customer_id' => (int)($profile['customer_id'] ?? 0),
            'skin_type' => (string)($profile['skin_type'] ?? ''),
            'concerns' => array_values($profile['concerns'] ?? []),
            'avoid_ingredients' => array_values($profile['avoid_ingredients'] ?? []),
            'budget' => (int)($profile['budget'] ?? 0),
        ];

        $payload = [
            'system_role' => 'Ban la AI Agent cua SkinSyntax. Nhiem vu: phan tich thanh phan, truy xuat du lieu that tu cua hang, phat hien conflict trong gio hang, va tra loi bang tieng Viet ro rang. Chi duoc dua vao du lieu context ben duoi; neu thieu du lieu hay noi ro la can kiem tra them. Tuyet doi khong bịa thông tin.',
            'customer_question' => $message,
            'current_product_id' => $currentProductId,
            'conversation_history' => $historyLines,
            'customer_profile' => $profileSummary,
            'cart_items' => $cartItems,
            'cart_conflicts' => $conflicts,
            'retrieved_products' => $products,
            'response_requirements' => [
                'Neu co conflict trong gio hang, phai canh bao som o dau cau tra loi.',
                'Chi goi y retrieved_products khi nguoi dung dang thuc su xin de xuat, so sanh, chon mua, hoac muon xem san pham cu the.',
                'Neu nguoi dung chi hoi kien thuc skincare, thanh phan, cach dung, routine, thi tra loi truc tiep va khong tu dong de xuat san pham.',
                'Khong copy nguyen van mo ta san pham dai dong. Neu can nhac san pham, chi tom tat 1 y chinh ngan gon.',
                'Neu khong du du lieu, noi ro gioi han thay vi doan.',
                'Tra loi gon, co cau truc, uu tien bullet khi can, toi da khoang 4 doan ngan.',
            ],
        ];

        return json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: $message;
    }

    private function buildAiAssistantFallback(string $message, array $conflicts, array $products, array $profile = []): string {
        $commonAnswer = $this->buildAiCommonKnowledgeResponse($message, $profile);
        if ($commonAnswer !== null) {
            return $commonAnswer;
        }

        $intent = $this->detectAiIntent($message);
        $lines = [];

        if ($intent === 'greeting') {
            $lines[] = 'Xin chào, mình là SkinSyntax AI.';
            $lines[] = 'Mình có thể hỗ trợ phân tích thành phần, kiểm tra giỏ hàng và gợi ý sản phẩm theo nhu cầu da.';
            return implode("\n", $lines);
        }

        if ($intent === 'cart_conflict') {
            if (empty($conflicts)) {
                $lines[] = 'Mình chưa phát hiện cặp hoạt chất xung đột rõ ràng trong giỏ hàng hiện tại dựa trên dữ liệu thành phần đang có.';
                $lines[] = 'Nếu routine có treatment mạnh, bạn vẫn nên giãn tần suất và theo dõi phản ứng của da.';
            } else {
                $lines[] = 'Mình đã quét giỏ hàng và thấy một số cặp cần lưu ý:';
                foreach (array_slice($conflicts, 0, 3) as $conflict) {
                    $lines[] = '- ' . $conflict['product_a'] . ' + ' . $conflict['product_b'] . ': ' . $conflict['warning'];
                    $lines[] = '  Gợi ý: ' . $conflict['recommendation'];
                }
            }

            if (!empty($products)) {
                $lines[] = 'Mình cũng đã ghim vài sản phẩm liên quan ở phần dữ liệu truy xuất bên dưới để bạn đối chiếu nhanh.';
            }

            return implode("\n", $lines);
        }

        if ($intent === 'ingredient_analysis') {
            $lines[] = 'AI đang tạm chuyển sang chế độ tư vấn nội bộ, nhưng mình vẫn có thể hỗ trợ theo nguyên tắc skincare an toàn.';
            if (!empty($products)) {
                $lines[] = 'Mình đã tìm thấy một số sản phẩm hoặc dữ liệu thành phần liên quan ở phần bên dưới.';
                $lines[] = 'Bạn có thể hỏi rõ hơn tên hoạt chất hoặc tên sản phẩm để mình tập trung vào công dụng, độ kích ứng và cách phối routine.';
            } else {
                return $this->buildGenericIngredientSafetyGuidance();
            }
            return implode("\n", $lines);
        }

        if ($intent === 'product_recommendation') {
            if (!empty($products)) {
                $lines[] = 'Mình đã tìm thấy vài sản phẩm liên quan với nhu cầu bạn đang hỏi.';
                $lines[] = 'Mình chỉ ghim danh sách ngắn ở bên dưới để bạn đối chiếu nhanh, không lặp lại toàn bộ mô tả dài.';
                $lines[] = 'Nếu muốn, bạn có thể hỏi tiếp: sản phẩm nào hợp da dầu mụn hơn, hoặc nên dùng sáng hay tối.';
            } else {
                $lines[] = 'Mình chưa lấy ra được sản phẩm thật sự sát với nhu cầu này từ dữ liệu hiện tại.';
                $lines[] = 'Bạn hãy nói rõ hơn loại da, vấn đề da và mức ngân sách để mình truy xuất chính xác hơn.';
            }
            return implode("\n", $lines);
        }

        $lines[] = 'AI đang tạm chuyển sang chế độ dự phòng của SkinSyntax để hỗ trợ bạn.';
        if (!empty($products)) {
            $lines[] = 'Mình đã truy xuất được ' . count($products) . ' sản phẩm liên quan ở phần bên dưới.';
        }
        if (!empty($conflicts)) {
            $lines[] = 'Giỏ hàng hiện cũng có cảnh báo conflict cần lưu ý.';
        }
        $lines[] = 'Bạn có thể hỏi cụ thể hơn về thành phần, loại da hoặc yêu cầu mình kiểm tra routine hiện tại.';

        return implode("\n", $lines);
    }

    private function resolveAiFallbackMeta(array $response, ?array $decodedBody = null): array {
        $status = (int)($response['status'] ?? 0);
        $errorText = strtolower(trim((string)($response['error'] ?? '')));
        $bodyError = strtolower(trim((string)($decodedBody['error'] ?? $decodedBody['message'] ?? '')));
        $combined = trim($errorText . ' ' . $bodyError);

        if ($status === 429 || strpos($combined, 'quota exceeded') !== false || strpos($combined, 'rate limit') !== false || strpos($combined, 'retry in') !== false) {
            return [
                'reason' => 'quota',
                'status_message' => 'AI đang dùng chế độ nội bộ vì quota Gemini tạm chạm giới hạn.',
                'fallback_note' => 'Phản hồi nội bộ do quota AI tạm đạt giới hạn',
            ];
        }

        return [
            'reason' => 'service_unavailable',
            'status_message' => 'Đang dùng dữ liệu dự phòng do AI service chưa phản hồi.',
            'fallback_note' => 'Phản hồi dự phòng từ dữ liệu hệ thống',
        ];
    }

    public function aiChatAssistant(): void {
        header('Content-Type: application/json; charset=utf-8');
        @set_time_limit(120);
        @ini_set('max_execution_time', '120');

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(405);
            echo json_encode(['ok' => false, 'message' => 'Method not allowed.'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            return;
        }

        $raw = file_get_contents('php://input');
        $data = json_decode((string)$raw, true);
        if (!is_array($data)) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'message' => 'Payload không hợp lệ.'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            return;
        }

        $message = trim((string)($data['message'] ?? ''));
        $history = is_array($data['history'] ?? null) ? $data['history'] : [];
        $currentProductId = isset($data['current_product_id']) ? trim((string)$data['current_product_id']) : '';

        if ($message === '') {
            http_response_code(400);
            echo json_encode(['ok' => false, 'message' => 'Vui lòng nhập nội dung cần hỏi AI.'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            return;
        }

        $user = current_user() ?? [];
        $email = trim((string)($user['email'] ?? ''));
        $profile = $email !== '' ? ($this->buildRecommendationProfile($email) ?? []) : [];

        if ($this->isGreetingMessage($message)) {
            $payload = [
                'ok' => true,
                'answer' => $this->buildDefaultGreetingResponse($profile),
                'conflicts' => [],
                'products' => [],
                'fallback' => false,
            ];
            echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            return;
        }

        $cachedPayload = $this->getCachedAiResponsePayload($message, $currentProductId);
        if ($cachedPayload !== null) {
            echo json_encode($cachedPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            return;
        }

        $cartItems = $this->buildAiCartContext();
        $conflicts = $this->detectCartIngredientConflicts($cartItems);

        // Check for brand mentions to automatically attach products
        $hasBrandMention = false;
        $commonBrands = ['cerave', 'bioderma', 'senka', 'cetaphil', 'b.o.m', 'bom', 'klairs', 'la roche', 'laroche', 'vichy', 'eucerin', 'neutrogena', 'loreal', 'l\'oreal', 'simple', 'innisfree', 'cosrx', 'some by mi', 'anessa', 'sunplay', 'skin1004', 'cocoon', 'hada labo', 'hadalabo'];
        $normalizedMsg = function_exists('mb_strtolower') ? mb_strtolower($message, 'UTF-8') : strtolower($message);
        foreach ($commonBrands as $brand) {
            if (str_contains($normalizedMsg, $brand)) {
                $hasBrandMention = true;
                break;
            }
        }

        $products = [];
        $seenProductIds = [];

        // 1. Fetch current product first if viewing a product detail page
        if ($currentProductId !== '') {
            $detail = $this->model->findById($currentProductId, true);
            if ($detail && is_array($detail)) {
                $formatted = [
                    'id' => (string)($detail['ma_san_pham'] ?? $currentProductId),
                    'name' => trim((string)($detail['ten_san_pham'] ?? '')),
                    'brand' => trim((string)($detail['thuong_hieu'] ?? '')),
                    'price' => (int)($detail['gia_ban'] ?? 0),
                    'image_url' => resolve_image_url((string)($detail['link_hinh_anh'] ?? $detail['hinh_anh'] ?? '')),
                    'detail_url' => BASE_URL . '/index.php?r=chitiet&id=' . rawurlencode((string)($detail['ma_san_pham'] ?? $currentProductId)),
                    'description' => trim((string)($detail['mo_ta'] ?? '')),
                    'ingredients' => $this->extractIngredientSource($detail),
                ];
                $products[] = $formatted;
                $seenProductIds[(string)($detail['ma_san_pham'] ?? $currentProductId)] = true;
            }
        }

        // 2. Fetch relevant products if general recommendations or brand mention or currently viewing a product
        if ($this->shouldAttachAiProducts($message, $conflicts) || $hasBrandMention || $currentProductId !== '') {
            $relevant = $this->buildAiRelevantProducts($message, 6);
            foreach ($relevant as $rSp) {
                $rId = (string)($rSp['id'] ?? '');
                if ($rId !== '' && !isset($seenProductIds[$rId])) {
                    $products[] = $rSp;
                    $seenProductIds[$rId] = true;
                }
            }
        }

        $products = array_slice($products, 0, 5);

        $endpoint = $this->getAiChatEndpoint();

        $payload = [
            'message' => $this->buildAiAssistantPrompt($message, $history, $profile, $cartItems, $conflicts, $products, $currentProductId),
        ];

        $response = $this->postJsonRequest($endpoint, $payload, $this->getAiChatTimeout());
        $answer = '';
        $usedFallback = false;
        $decoded = null;
        $fallbackMeta = [
            'reason' => '',
            'status_message' => '',
            'fallback_note' => '',
        ];

        if ((int)($response['status'] ?? 0) >= 200 && (int)($response['status'] ?? 0) < 300) {
            $decoded = json_decode((string)($response['body'] ?? ''), true);
            $answer = $this->trimAiAnswer((string)($decoded['answer'] ?? ''));
            if (is_array($decoded) && !empty($decoded['products']) && is_array($decoded['products'])) {
                $mapped = $this->mapHybridProductsForChatWidget($decoded['products']);
                if (!empty($mapped)) {
                    $products = $mapped;
                }
            }
        } else {
            $decoded = json_decode((string)($response['body'] ?? ''), true);
        }

        if ($answer === '') {
            $usedFallback = true;
            $fallbackMeta = $this->resolveAiFallbackMeta($response, is_array($decoded) ? $decoded : null);
            $answer = $this->buildAiAssistantFallback($message, $conflicts, $products, $profile);
        } else {
            $answer = $this->trimAiAnswer($answer);
        }

        $payload = [
            'ok' => true,
            'answer' => $answer,
            'conflicts' => $conflicts,
            'products' => $products,
            'fallback' => $usedFallback,
            'fallback_reason' => $fallbackMeta['reason'],
            'status_message' => $fallbackMeta['status_message'],
            'fallback_note' => $fallbackMeta['fallback_note'],
        ];

        if (!$usedFallback) {
            $this->storeAiResponsePayload($message, $payload, $currentProductId);
        }

        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    private function postJsonRequest(string $url, array $payload, int $timeout = 20): array {
        $body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($body === false) {
            return [
                'status' => 0,
                'body' => '',
                'error' => 'JSON encoding failed.',
            ];
        }

        $headers = [
            'Accept: application/json',
            'Content-Type: application/json',
            'Content-Length: ' . strlen($body),
        ];

        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
            curl_setopt($ch, CURLOPT_TIMEOUT, max(3, $timeout));
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
            $responseBody = curl_exec($ch);
            $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $error = curl_error($ch);
            curl_close($ch);

            return [
                'status' => $status,
                'body' => (string)$responseBody,
                'error' => $error,
            ];
        }

        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => implode("\r\n", $headers),
                'content' => $body,
                'ignore_errors' => true,
                'timeout' => max(3, $timeout),
            ],
        ]);

        $responseBody = @file_get_contents($url, false, $context);
        $status = 0;
        foreach (($http_response_header ?? []) as $line) {
            if (preg_match('#HTTP/\S+\s+(\d{3})#', $line, $matches)) {
                $status = (int)$matches[1];
                break;
            }
        }

        return [
            'status' => $status,
            'body' => (string)$responseBody,
            'error' => '',
        ];
    }

    private function buildRecommendationFallbackExplanation(array $profile, array $product): string {
        // Hàm này tạo câu giải thích tự nhiên khi AI service không trả được lời giải thích chi tiết.
        // Dữ liệu dùng để viết câu đều lấy từ sản phẩm trong MongoDB và hồ sơ người dùng, không bịa sản phẩm ngoài shop.
        $cleanText = static function ($value): string {
            // Chuẩn hóa một giá trị text đơn lẻ: ép về string và bỏ khoảng trắng thừa.
            $text = trim((string)$value);
            if ($text === '') {
                return '';
            }
            if (false && preg_match('/Ã|Â|â|Ä|Æ|áº|á»/u', $text)) {
                $fixed = @iconv('UTF-8', 'Windows-1252//IGNORE', $text);
                if (is_string($fixed) && trim($fixed) !== '') {
                    $text = trim($fixed);
                }
            }
            return $text;
        };

        $mapClean = static function ($items) use ($cleanText): array {
            // Chuẩn hóa một danh sách text, bỏ phần tử rỗng và bỏ trùng.
            if (!is_array($items)) {
                return [];
            }
            $out = [];
            foreach ($items as $item) {
                $v = $cleanText($item);
                if ($v !== '') {
                    $out[] = $v;
                }
            }
            return array_values(array_unique($out));
        };

        // Các mảng này được tạo từ model recommendation: vấn đề da khớp, thành phần chính, thành phần cần tránh, lý do chấm điểm.
        $concerns = $mapClean($product['matched_concerns'] ?? []);
        $keyIngredients = $mapClean($product['key_ingredients'] ?? []);
        $avoidHits = $mapClean($product['avoid_ingredient_hits'] ?? []);
        $reasons = $mapClean($product['reasons'] ?? []);

        // Tên sản phẩm dùng trong câu tư vấn. Nếu thiếu tên thì dùng câu mặc định.
        $name = $cleanText($product['ten_san_pham'] ?? '');
        if ($name === '') {
            $name = 'sản phẩm này';
        }

        // Câu người dùng nhập ở ô nhu cầu chi tiết, ví dụ "tui muốn mua sữa rửa mặt".
        $userQuery = $cleanText($profile['user_query'] ?? '');
        if (function_exists('mb_substr') && mb_strlen($userQuery, 'UTF-8') > 180) {
            // Giới hạn câu hỏi quá dài để phần giải thích không bị rối giao diện.
            $userQuery = mb_substr($userQuery, 0, 180, 'UTF-8') . '...';
        } elseif (strlen($userQuery) > 180) {
            $userQuery = substr($userQuery, 0, 180) . '...';
        }

        // Các thông tin hồ sơ dùng để giải thích vì sao sản phẩm phù hợp.
        $skin = $cleanText($profile['skin_type'] ?? '');
        $budget = (int)($profile['budget'] ?? 0);
        $price = (int)($product['gia_ban'] ?? 0);

        // Ghép từng mảnh câu thành một đoạn tư vấn giống người thật.
        $chunks = [];
        $chunks[] = 'Tui đọc lại mô tả của ' . $name . ' trong shop và thấy sản phẩm này khá khớp với nhu cầu bạn đang tìm.';
        if ($userQuery !== '') {
            $chunks[] = 'Bạn có ghi là "' . $userQuery . '", nên hệ thống ưu tiên các dòng có dữ liệu khớp với ý đó.';
        }
        if ($skin !== '') {
            $chunks[] = 'Hồ sơ da của bạn là ' . $skin . ', nên mình cân nhắc độ phù hợp với loại da này trước.';
        }
        if ($price > 0 && $budget > 0 && $price <= $budget) {
            // Nếu giá không vượt ngân sách thì nói rõ để người dùng hiểu vì sao được ưu tiên.
            $chunks[] = 'Giá khoảng ' . number_format($price, 0, ',', '.') . 'đ, nằm trong ngân sách bạn chọn.';
        } elseif ($price > 0) {
            // Nếu không có ngân sách hoặc vượt ngân sách thì vẫn báo giá để người dùng tự cân nhắc.
            $chunks[] = 'Giá khoảng ' . number_format($price, 0, ',', '.') . 'đ, bạn có thể cân nhắc thêm voucher nếu muốn tiết kiệm hơn.';
        }
        if (!empty($keyIngredients)) {
            $chunks[] = 'Thành phần nổi bật: ' . implode(', ', array_slice($keyIngredients, 0, 3)) . '.';
        }
        if (!empty($concerns)) {
            $chunks[] = 'Sản phẩm có tín hiệu liên quan đến vấn đề da: ' . implode(', ', array_slice($concerns, 0, 2)) . '.';
        }
        if (!empty($reasons)) {
            $chunks[] = implode(' ', array_slice($reasons, 0, 2));
        }
        if (!empty($avoidHits)) {
            // Cảnh báo nhẹ nếu sản phẩm có thành phần người dùng từng nhập là muốn tránh.
            $chunks[] = 'Lưu ý: sản phẩm có thành phần bạn muốn tránh (' . implode(', ', array_slice($avoidHits, 0, 3)) . '), nên đọc kỹ bảng thành phần trước khi dùng.';
        }

        // Trả về một chuỗi hoàn chỉnh để frontend hiển thị dưới mỗi sản phẩm.
        return trim(implode(' ', $chunks));
    }
    private function fetchAiRecommendationExplanations(array $profile, array $products): array {
        // Gửi hồ sơ người dùng + danh sách sản phẩm đã lọc sang AI để nhờ viết lời giải thích chi tiết hơn.
        // Nếu AI lỗi thì controller sẽ dùng buildRecommendationFallbackExplanation() ở trên.
        if (empty($products)) {
            return [
                'ok' => true,
                'items' => [],
                'message' => 'KhÃ´ng cÃ³ sáº£n pháº©m Ä‘á»ƒ giáº£i thÃ­ch.',
            ];
        }

        // Payload chỉ gửi tối đa 5 sản phẩm để tiết kiệm token và tránh AI xử lý quá nhiều dữ liệu.
        $payload = [
            'user_profile' => [
                'gioi_tinh' => (string)($profile['gioi_tinh'] ?? ''),
                'nam_sinh' => (string)($profile['nam_sinh'] ?? ''),
                'skin_type' => (string)($profile['skin_type'] ?? ''),
                'concerns' => array_values($profile['concerns'] ?? []),
                'avoid_ingredients' => array_values($profile['avoid_ingredients'] ?? []),
                'budget' => (int)($profile['budget'] ?? 0),
                'sensitivity' => (string)($profile['sensitivity'] ?? ''),
                'user_query' => trim((string)($profile['user_query'] ?? '')),
            ],
            'products' => array_map(function (array $item): array {
                // Chuẩn hóa mỗi sản phẩm thành schema gọn cho AI: id, tên, giá, danh mục, mô tả, thành phần, lý do.
                return [
                    'id' => (string)($item['id'] ?? ''),
                    'name' => (string)($item['ten_san_pham'] ?? ''),
                    'brand' => (string)($item['thuong_hieu'] ?? ''),
                    'price' => (int)($item['gia_ban'] ?? 0),
                    'category' => (string)($item['danh_muc'] ?? ''),
                    'description' => (string)($item['mo_ta'] ?? ''),
                    'ingredients' => (string)($item['thanh_phan_chinh'] ?? $item['thanh_phan_day_du'] ?? ''),
                    'key_ingredients' => array_values($item['key_ingredients'] ?? []),
                    'reasons' => array_values($item['reasons'] ?? []),
                    'matched_concerns' => array_values($item['matched_concerns'] ?? []),
                    'avoid_ingredient_hits' => array_values($item['avoid_ingredient_hits'] ?? []),
                    'score' => (float)($item['score'] ?? 0),
                ];
            }, array_slice($products, 0, 5)),
        ];

        $endpoint = $this->getAiRecommendationEndpoint();
        if ($endpoint === '') {
            return [
                'ok' => false,
                'items' => [],
                'message' => 'AI recommendation endpoint chÆ°a Ä‘Æ°á»£c cáº¥u hÃ¬nh.',
            ];
        }

        $response = $this->postJsonRequest($endpoint, $payload, $this->getAiRecommendationTimeout());
        if ((int)($response['status'] ?? 0) < 200 || (int)($response['status'] ?? 0) >= 300) {
            return [
                'ok' => false,
                'items' => [],
                'message' => 'KhÃ´ng gá»i Ä‘Æ°á»£c AI recommendation service.',
                'debug_status' => (int)($response['status'] ?? 0),
                'debug_error' => (string)($response['error'] ?? ''),
            ];
        }

        $decoded = json_decode((string)($response['body'] ?? ''), true);
        if (!is_array($decoded)) {
            return [
                'ok' => false,
                'items' => [],
                'message' => 'Pháº£n há»“i AI recommendation service khÃ´ng há»£p lá»‡.',
            ];
        }

        $items = [];
        foreach (($decoded['recommendations'] ?? []) as $row) {
            if (!is_array($row)) {
                continue;
            }

            $productId = trim((string)($row['product_id'] ?? ''));
            $explanation = trim((string)($row['llm_explanation'] ?? ''));
            if ($productId === '' || $explanation === '') {
                continue;
            }

            $items[$productId] = [
                'llm_explanation' => $explanation,
                'source' => trim((string)($row['source'] ?? 'llm')) ?: 'llm',
            ];
        }

        return [
            'ok' => !empty($decoded['status']) ? (string)$decoded['status'] === 'success' : !empty($items),
            'items' => $items,
            'message' => (string)($decoded['message'] ?? ''),
        ];
    }

    private function fetchAiHybridRecommendations(array $profile, string $queryText = ''): array {
        $endpoint = $this->getAiHybridRecommendationEndpoint();
        if ($endpoint === '') {
            return [
                'ok' => false,
                'items' => [],
                'summary' => '',
                'cached' => false,
                'message' => 'AI hybrid recommendation endpoint chÆ°a Ä‘Æ°á»£c cáº¥u hÃ¬nh.',
            ];
        }

        $queryText = trim($queryText);
        if ($queryText === '') {
            $segments = [];
            if (!empty($profile['skin_type'])) {
                $segments[] = 'Da ' . (string)$profile['skin_type'];
            }
            if (!empty($profile['concerns']) && is_array($profile['concerns'])) {
                $segments[] = 'quan tÃ¢m ' . implode(', ', array_slice($profile['concerns'], 0, 3));
            }
            if (!empty($profile['recent_keywords']) && is_array($profile['recent_keywords'])) {
                $segments[] = 'Ä‘ang tÃ¬m ' . implode(', ', array_slice($profile['recent_keywords'], 0, 3));
            }
            if (!empty($profile['budget_label'])) {
                $segments[] = 'ngÃ¢n sÃ¡ch ' . (string)$profile['budget_label'];
            }
            $queryText = implode('. ', $segments);
        }

        $interactionMode = 'chatbot';
        if (isset($profile['interaction_mode'])) {
            $mode = strtolower(trim((string)$profile['interaction_mode']));
            if ($mode === 'chatbot' || $mode === 'advisor') {
                $interactionMode = $mode;
            }
        }

        $payload = [
            'user_profile' => $profile,
            'query_text' => $queryText,
            'user_query' => $queryText,
            'interaction_mode' => $interactionMode,
        ];

        $response = $this->postJsonRequest($endpoint, $payload, $this->getAiHybridRecommendationTimeout());
        if ((int)($response['status'] ?? 0) < 200 || (int)($response['status'] ?? 0) >= 300) {
            return [
                'ok' => false,
                'items' => [],
                'summary' => '',
                'cached' => false,
                'message' => 'KhÃ´ng gá»i Ä‘Æ°á»£c AI hybrid recommendation service.',
                'debug_status' => (int)($response['status'] ?? 0),
                'debug_error' => (string)($response['error'] ?? ''),
            ];
        }

        $decoded = json_decode((string)($response['body'] ?? ''), true);
        if (!is_array($decoded)) {
            return [
                'ok' => false,
                'items' => [],
                'summary' => '',
                'cached' => false,
                'message' => 'Pháº£n há»“i AI hybrid recommendation service khÃ´ng há»£p lá»‡.',
            ];
        }

        $items = [];
        foreach (($decoded['products'] ?? []) as $row) {
            if (!is_array($row)) {
                continue;
            }

            $row['image_url'] = resolve_image_url((string)($row['image_url'] ?? $row['link_hinh_anh'] ?? ''));
            $row['link_hinh_anh'] = (string)($row['image_url'] ?? '');
            $items[] = $row;
        }

        return [
            'ok' => ((string)($decoded['status'] ?? '') === 'success') || !empty($items),
            'items' => $items,
            'summary' => trim((string)($decoded['summary'] ?? '')),
            'cached' => !empty($decoded['cached']),
            'query' => trim((string)($decoded['query'] ?? $queryText)),
            'message' => trim((string)($decoded['message'] ?? '')),
        ];
    }

    private function sepayRequest(string $url): array {
        $baseHeaders = [
            'Accept: application/json',
            'Content-Type: application/json',
        ];
        $authHeaders = $this->getSePayAuthorizationHeaders();
        $attempts = [];

        if (function_exists('curl_init')) {
            foreach ($authHeaders as $authHeader) {
                $headers = array_merge($baseHeaders, [$authHeader]);
                $ch = curl_init($url);
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
                curl_setopt($ch, CURLOPT_TIMEOUT, 15);
                curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
                if (defined('CURL_SSLVERSION_TLSv1_2')) {
                    curl_setopt($ch, CURLOPT_SSLVERSION, CURL_SSLVERSION_TLSv1_2);
                }

                $body = curl_exec($ch);
                $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
                $error = curl_error($ch);
                curl_close($ch);

                $attempts[] = [
                    'auth' => stripos($authHeader, 'apikey ') !== false ? 'Apikey' : 'Bearer',
                    'status' => $status,
                    'error' => $error,
                ];

                if ($status >= 200 && $status < 300) {
                    return [
                        'status' => $status,
                        'body' => (string)$body,
                        'error' => $error,
                        'attempts' => $attempts,
                    ];
                }

                if ($status > 0 && !in_array($status, [401, 403], true)) {
                    return [
                        'status' => $status,
                        'body' => (string)$body,
                        'error' => $error,
                        'attempts' => $attempts,
                    ];
                }

                if ($status === 0 && $error !== '') {
                    return [
                        'status' => $status,
                        'body' => (string)$body,
                        'error' => $error,
                        'attempts' => $attempts,
                    ];
                }
            }
        }

        foreach ($authHeaders as $authHeader) {
            $headers = array_merge($baseHeaders, [$authHeader]);
            $context = stream_context_create([
                'http' => [
                    'method' => 'GET',
                    'header' => implode("\r\n", $headers),
                    'ignore_errors' => true,
                    'timeout' => 15,
                ],
                'ssl' => [
                    'crypto_method' => defined('STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT')
                        ? STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT
                        : STREAM_CRYPTO_METHOD_TLS_CLIENT,
                ],
            ]);

            $body = @file_get_contents($url, false, $context);
            $status = 0;
            foreach (($http_response_header ?? []) as $line) {
                if (preg_match('#HTTP/\S+\s+(\d{3})#', $line, $matches)) {
                    $status = (int)$matches[1];
                    break;
                }
            }

            $attempts[] = [
                'auth' => stripos($authHeader, 'apikey ') !== false ? 'Apikey' : 'Bearer',
                'status' => $status,
                'error' => $body === false ? 'stream_request_failed' : '',
            ];

            if ($status >= 200 && $status < 300) {
                return [
                    'status' => $status,
                    'body' => (string)$body,
                    'error' => '',
                    'attempts' => $attempts,
                ];
            }

            if ($status > 0 && !in_array($status, [401, 403], true)) {
                return [
                    'status' => $status,
                    'body' => (string)$body,
                    'error' => '',
                    'attempts' => $attempts,
                ];
            }
        }

        $lastAttempt = !empty($attempts) ? $attempts[count($attempts) - 1] : [];

        return [
            'status' => (int)($lastAttempt['status'] ?? 0),
            'body' => '',
            'error' => (string)($lastAttempt['error'] ?? 'SePay request failed.'),
            'attempts' => $attempts,
        ];
    }

    private function resolveSePayFailureMessage(array $response): string {
        $status = (int)($response['status'] ?? 0);
        $error = strtolower(trim((string)($response['error'] ?? '')));

        if (in_array($status, [401, 403], true)) {
            return 'SePay tá»« chá»‘i xÃ¡c thá»±c API. HÃ£y kiá»ƒm tra láº¡i API token hoáº·c quyá»n User API trÃªn tÃ i khoáº£n SePay.';
        }

        if (
            $status === 0
            && (
                strpos($error, 'ssl') !== false
                || strpos($error, 'tls') !== false
                || strpos($error, 'unexpected error occurred on a send') !== false
                || strpos($error, 'connection was closed') !== false
                || strpos($error, 'stream_request_failed') !== false
            )
        ) {
            return 'MÃ¡y chá»§ hiá»‡n khÃ´ng thiáº¿t láº­p Ä‘Æ°á»£c káº¿t ná»‘i báº£o máº­t TLS tá»›i SePay. Náº¿u báº¡n Ä‘ang cháº¡y localhost/XAMPP, hÃ£y Æ°u tiÃªn webhook trÃªn mÃ´i trÆ°á»ng public hoáº·c kiá»ƒm tra tÆ°á»ng lá»­a/chá»©ng chá»‰ outbound.';
        }

        if ($status === 429) {
            return 'SePay Ä‘ang giá»›i háº¡n táº§n suáº¥t gá»i API. HÃ£y Ä‘á»£i vÃ i giÃ¢y rá»“i thá»­ láº¡i.';
        }

        return 'KhÃ´ng gá»i Ä‘Æ°á»£c SePay API.';
    }

    private function fetchSePayTransactionsForOrder(array $order): array {
        if (!$this->isSePayPollingEnabled()) {
            return [
                'ok' => false,
                'message' => 'SePay polling chÆ°a Ä‘Æ°á»£c báº­t hoáº·c thiáº¿u cáº¥u hÃ¬nh.',
                'transactions' => [],
            ];
        }

        $params = [
            'account_number' => $this->getSePayAccountNumber(),
            'limit' => 30,
            'amount_in' => (int)($order['tong_tien'] ?? 0),
        ];

        $orderDate = trim((string)($order['ngay_dat'] ?? ''));
        $timestamp = $orderDate !== '' ? strtotime($orderDate) : false;
        if ($timestamp !== false) {
            $params['transaction_date_min'] = date('Y-m-d', $timestamp);
        }

        $url = 'https://my.sepay.vn/userapi/transactions/list?' . http_build_query($params);
        $response = $this->sepayRequest($url);

        if ((int)($response['status'] ?? 0) < 200 || (int)($response['status'] ?? 0) >= 300) {
            return [
                'ok' => false,
                'message' => $this->resolveSePayFailureMessage($response),
                'transactions' => [],
                'debug_status' => (int)($response['status'] ?? 0),
                'debug_error' => (string)($response['error'] ?? ''),
                'debug_attempts' => $response['attempts'] ?? [],
            ];
        }

        $payload = json_decode((string)($response['body'] ?? ''), true);
        if (!is_array($payload)) {
            return [
                'ok' => false,
                'message' => 'Pháº£n há»“i SePay API khÃ´ng há»£p lá»‡.',
                'transactions' => [],
            ];
        }

        $transactions = $payload['transactions'] ?? [];
        if (!is_array($transactions)) {
            $transactions = [];
        }

        $normalized = [];
        foreach ($transactions as $item) {
            if (!is_array($item)) {
                continue;
            }

            $content = trim((string)($item['transaction_content'] ?? ''));
            $amount = (int)round((float)($item['amount_in'] ?? 0));
            $reference = trim((string)($item['reference_number'] ?? ''));
            $transactionId = trim((string)($item['id'] ?? ''));

            if ($content === '' || $amount <= 0) {
                continue;
            }

            $normalized[] = [
                'content' => $content,
                'amount' => $amount,
                'reference' => $reference !== '' ? $reference : $transactionId,
                'transaction_id' => $transactionId,
            ];
        }

        return [
            'ok' => true,
            'message' => 'ÄÃ£ láº¥y giao dá»‹ch tá»« SePay.',
            'transactions' => $normalized,
        ];
    }

    private function getHeaderValue(string $name): string {
        $serverKey = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
        return trim((string)($_SERVER[$serverKey] ?? ''));
    }

    private function isValidWebhookRequest(): bool {
        $secret = $this->getWebhookSecret();
        if ($secret === '') {
            return true;
        }

        $incoming = trim((string)($_GET['token'] ?? ''));
        if ($incoming === '') {
            $incoming = $this->getHeaderValue('X-Webhook-Secret');
        }
        if ($incoming === '') {
            $incoming = $this->getHeaderValue('Authorization');
            if (stripos($incoming, 'Bearer ') === 0) {
                $incoming = trim(substr($incoming, 7));
            } elseif (stripos($incoming, 'Apikey ') === 0) {
                $incoming = trim(substr($incoming, 7));
            }
        }

        return hash_equals($secret, $incoming);
    }

    private function normalizeWebhookTransactions(array $payload): array {
        $candidates = [];

        if (isset($payload['data']) && is_array($payload['data'])) {
            $candidates = $payload['data'];
        } elseif (isset($payload['transactions']) && is_array($payload['transactions'])) {
            $candidates = $payload['transactions'];
        } elseif (isset($payload['items']) && is_array($payload['items'])) {
            $candidates = $payload['items'];
        } else {
            $candidates = [$payload];
        }

        $normalized = [];
        foreach ($candidates as $item) {
            if (!is_array($item)) {
                continue;
            }

            $content = trim((string)(
                $item['description']
                ?? $item['content']
                ?? $item['transactionContent']
                ?? $item['remark']
                ?? $item['addDescription']
                ?? ''
            ));

            $amountRaw = $item['amount']
                ?? $item['transferAmount']
                ?? $item['creditAmount']
                ?? $item['transactionAmount']
                ?? 0;
            $amount = (int)preg_replace('/[^\d-]/', '', (string)$amountRaw);

            $transferType = strtolower(trim((string)($item['transferType'] ?? $item['type'] ?? $item['transactionType'] ?? '')));
            $reference = trim((string)(
                $item['referenceCode']
                ?? $item['reference']
                ?? $item['txnId']
                ?? $item['id']
                ?? ''
            ));

            if ($content === '' || $amount <= 0) {
                continue;
            }

            if ($transferType !== '' && !in_array($transferType, ['in', 'credit', 'incoming'], true)) {
                continue;
            }

            $normalized[] = [
                'content' => $content,
                'amount' => $amount,
                'reference' => $reference,
            ];
        }

        return $normalized;
    }

    private function extractOrderIdFromTransferContent(string $content): ?int {
        $content = strtoupper(trim($content));
        if ($content === '') {
            return null;
        }

        if (preg_match('/SKIN\s*HD\s*(\d{1,12})/i', $content, $matches)) {
            return (int)$matches[1];
        }

        return null;
    }

    public function chuandaithanhtoan() {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            redirect(BASE_URL . '/index.php?r=giohang');
        }

        $selectedIds = $_POST['selected_items'] ?? [];
        if (!is_array($selectedIds)) {
            $selectedIds = [$selectedIds];
        }

        $cart = $_SESSION['gio_hang'] ?? [];
        $checkoutItems = [];

        foreach ($selectedIds as $idSp) {
            $idSp = trim((string)$idSp);
            if ($idSp === '') {
                continue;
            }

            if (!isset($cart[$idSp])) {
                continue;
            }

            $checkoutItems[$idSp] = max(1, (int)$cart[$idSp]);
        }

        if (empty($checkoutItems)) {
            set_flash('error', 'Vui lÃ²ng chá»n sáº£n pháº©m Ä‘á»ƒ thanh toÃ¡n.');
            redirect(BASE_URL . '/index.php?r=giohang');
        }

        $_SESSION['checkout_items'] = $checkoutItems;
        unset($_SESSION['checkout_voucher'], $_SESSION['checkout_points'], $_SESSION['checkout_address_choice'], $_SESSION['checkout_new_receiver']);
        $_SESSION['checkout_payment_method'] = 'cod';
        redirect(BASE_URL . '/index.php?r=thanhtoan');
    }

    public function thanhtoan() {
        if (!is_logged_in()) {
            set_flash('error', 'Vui lÃ²ng Ä‘Äƒng nháº­p Ä‘á»ƒ thanh toÃ¡n.');
            redirect(BASE_URL . '/index.php?r=dangnhap');
        }

        $checkoutItems = $_SESSION['checkout_items'] ?? [];
        if (empty($checkoutItems) || !is_array($checkoutItems)) {
            set_flash('error', 'KhÃ´ng cÃ³ sáº£n pháº©m Ä‘á»ƒ thanh toÃ¡n.');
            redirect(BASE_URL . '/index.php?r=giohang');
        }

        $checkoutPreview = $this->buildCheckoutPreview($checkoutItems);
        $items = $checkoutPreview['items'];
        $subtotal = (int)($checkoutPreview['subtotal'] ?? 0);

        if (empty($items)) {
            unset($_SESSION['checkout_items']);
            set_flash('error', 'Sáº£n pháº©m trong danh sÃ¡ch thanh toÃ¡n khÃ´ng cÃ²n tá»“n táº¡i.');
            redirect(BASE_URL . '/index.php?r=giohang');
        }

        $user = current_user() ?? [];
        $tkModel = new TaiKhoan($this->pdo);
        $kh = $tkModel->getKhachHangByEmail((string)($user['email'] ?? ''));

        $appliedVoucher = $this->getAppliedVoucher($subtotal, true);
        $voucherDiscountAmount = (int)($appliedVoucher['discount'] ?? 0);
        $pointRedemption = $this->getAppliedPointsRedemption(max(0, $subtotal - $voucherDiscountAmount), $kh, true);
        $pointsDiscountAmount = (int)($pointRedemption['discount'] ?? 0);
        $discountAmount = $voucherDiscountAmount + $pointsDiscountAmount;
        $shippingFee = 30000;
        $grandTotal = max(0, $subtotal - $discountAmount) + $shippingFee;
        $selectedPaymentMethod = $this->getSelectedCheckoutPaymentMethod();
        $qrTransfer = $this->getQrTransferConfig();
        $transferPreview = null;
        if (!empty($qrTransfer['enabled'])) {
            $previewContent = 'SKINSYNTAX TAM GIU';
            $transferPreview = [
                'bank_name' => $qrTransfer['bank_name'] !== '' ? $qrTransfer['bank_name'] : $qrTransfer['bank_id'],
                'account_no' => $qrTransfer['account_no'],
                'account_name' => $qrTransfer['account_name'],
                'content' => $previewContent,
                'qr_url' => $this->buildVietQrUrl($grandTotal, $previewContent),
            ];
        }

        $defaultReceiver = $this->getDefaultCheckoutReceiver($kh, $user);
        $addressState = $this->getCheckoutAddressState($defaultReceiver);
        $receiver = $addressState['selected_receiver'];

        $this->render('thanhtoan', [
            'items' => $items,
            'receiver' => $receiver,
            'defaultReceiver' => $addressState['default_receiver'],
            'newReceiver' => $addressState['new_receiver'],
            'selectedReceiver' => $addressState['selected_receiver'],
            'addressChoice' => $addressState['address_choice'],
            'hasDefaultReceiver' => $addressState['has_default_receiver'],
            'subtotal' => $subtotal,
            'shippingFee' => $shippingFee,
            'discountAmount' => $discountAmount,
            'voucherDiscountAmount' => $voucherDiscountAmount,
            'pointRedemption' => $pointRedemption,
            'khachHang' => $kh,
            'grandTotal' => $grandTotal,
            'appliedVoucher' => $appliedVoucher,
            'selectedPaymentMethod' => $selectedPaymentMethod,
            'qrTransfer' => $qrTransfer,
            'transferPreview' => $transferPreview,
        ]);
    }

    public function apDungVoucher(): void {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            redirect(BASE_URL . '/index.php?r=thanhtoan');
        }

        if (!is_logged_in()) {
            set_flash('error', 'Vui lÃ²ng Ä‘Äƒng nháº­p Ä‘á»ƒ Ã¡p dá»¥ng mÃ£ giáº£m giÃ¡.');
            redirect(BASE_URL . '/index.php?r=dangnhap');
        }

        $this->storeCheckoutPaymentMethodFromRequest();
        $this->storeCheckoutReceiverFromRequest($this->getDefaultCheckoutReceiver($this->getCurrentCheckoutCustomer(), current_user() ?? []));

        $checkoutItems = $_SESSION['checkout_items'] ?? [];
        if (empty($checkoutItems) || !is_array($checkoutItems)) {
            set_flash('error', 'KhÃ´ng cÃ³ sáº£n pháº©m Ä‘á»ƒ Ã¡p dá»¥ng mÃ£ giáº£m giÃ¡.');
            redirect(BASE_URL . '/index.php?r=giohang');
        }

        $preview = $this->buildCheckoutPreview($checkoutItems);
        if (empty($preview['items'])) {
            unset($_SESSION['checkout_items'], $_SESSION['checkout_voucher']);
            set_flash('error', 'Sáº£n pháº©m trong danh sÃ¡ch thanh toÃ¡n khÃ´ng cÃ²n tá»“n táº¡i.');
            redirect(BASE_URL . '/index.php?r=giohang');
        }

        $voucherCode = trim((string)($_POST['voucher_code'] ?? ''));
        $result = $this->voucherModel->validateForCheckout($voucherCode, (int)($preview['subtotal'] ?? 0));
        if (empty($result['ok'])) {
            unset($_SESSION['checkout_voucher']);
            set_flash('error', (string)($result['message'] ?? 'KhÃ´ng thá»ƒ Ã¡p dá»¥ng mÃ£ giáº£m giÃ¡.'));
            redirect(BASE_URL . '/index.php?r=thanhtoan');
        }

        $_SESSION['checkout_voucher'] = [
            'code' => (string)($result['voucher']['ma_code'] ?? ''),
        ];
        set_flash('success', (string)($result['message'] ?? 'Ãp dá»¥ng mÃ£ giáº£m giÃ¡ thÃ nh cÃ´ng.'));
        redirect(BASE_URL . '/index.php?r=thanhtoan');
    }

    public function boVoucher(): void {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            redirect(BASE_URL . '/index.php?r=thanhtoan');
        }

        $this->storeCheckoutPaymentMethodFromRequest();
        $this->storeCheckoutReceiverFromRequest($this->getDefaultCheckoutReceiver($this->getCurrentCheckoutCustomer(), current_user() ?? []));
        unset($_SESSION['checkout_voucher']);
        set_flash('success', 'ÄÃ£ gá»¡ mÃ£ giáº£m giÃ¡ khá»i Ä‘Æ¡n hÃ ng.');
        redirect(BASE_URL . '/index.php?r=thanhtoan');
    }

    public function apDungDiem(): void {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            redirect(BASE_URL . '/index.php?r=thanhtoan');
        }

        if (!is_logged_in()) {
            set_flash('error', 'Vui lÃ²ng Ä‘Äƒng nháº­p Ä‘á»ƒ dÃ¹ng Ä‘iá»ƒm tÃ­ch lÅ©y.');
            redirect(BASE_URL . '/index.php?r=dangnhap');
        }

        $this->storeCheckoutPaymentMethodFromRequest();
        $this->storeCheckoutReceiverFromRequest($this->getDefaultCheckoutReceiver($this->getCurrentCheckoutCustomer(), current_user() ?? []));
        $checkoutItems = $_SESSION['checkout_items'] ?? [];
        if (empty($checkoutItems) || !is_array($checkoutItems)) {
            set_flash('error', 'KhÃ´ng cÃ³ sáº£n pháº©m Ä‘á»ƒ Ã¡p dá»¥ng Ä‘iá»ƒm.');
            redirect(BASE_URL . '/index.php?r=giohang');
        }

        $preview = $this->buildCheckoutPreview($checkoutItems);
        if (empty($preview['items'])) {
            unset($_SESSION['checkout_items'], $_SESSION['checkout_voucher'], $_SESSION['checkout_points']);
            set_flash('error', 'Sáº£n pháº©m trong danh sÃ¡ch thanh toÃ¡n khÃ´ng cÃ²n tá»“n táº¡i.');
            redirect(BASE_URL . '/index.php?r=giohang');
        }

        $customer = $this->getCurrentCheckoutCustomer();
        $availablePoints = max(0, (int)($customer['diemtl'] ?? 0));
        $requestedPoints = max(0, (int)($_POST['points_to_use'] ?? 0));
        $appliedVoucher = $this->getAppliedVoucher((int)($preview['subtotal'] ?? 0), false);
        $voucherDiscount = (int)($appliedVoucher['discount'] ?? 0);
        $maxPointsByAmount = (int)floor(max(0, ((int)($preview['subtotal'] ?? 0) - $voucherDiscount)) / self::POINT_VALUE_VND);

        if ($availablePoints <= 0) {
            unset($_SESSION['checkout_points']);
            set_flash('error', 'TÃ i khoáº£n cá»§a báº¡n hiá»‡n chÆ°a cÃ³ Ä‘iá»ƒm tÃ­ch lÅ©y Ä‘á»ƒ sá»­ dá»¥ng.');
            redirect(BASE_URL . '/index.php?r=thanhtoan');
        }

        if ($requestedPoints <= 0) {
            unset($_SESSION['checkout_points']);
            set_flash('error', 'Vui lÃ²ng nháº­p sá»‘ Ä‘iá»ƒm há»£p lá»‡ Ä‘á»ƒ Ã¡p dá»¥ng.');
            redirect(BASE_URL . '/index.php?r=thanhtoan');
        }

        $usablePoints = min($requestedPoints, $availablePoints, $maxPointsByAmount);
        if ($usablePoints <= 0) {
            unset($_SESSION['checkout_points']);
            set_flash('error', 'GiÃ¡ trá»‹ Ä‘Æ¡n hÃ ng hiá»‡n táº¡i chÆ°a Ä‘á»§ Ä‘á»ƒ quy Ä‘á»•i Ä‘iá»ƒm thÃ nh giáº£m giÃ¡.');
            redirect(BASE_URL . '/index.php?r=thanhtoan');
        }

        $_SESSION['checkout_points'] = ['points' => $usablePoints];
        set_flash('success', 'ÄÃ£ Ã¡p dá»¥ng ' . number_format($usablePoints, 0, ',', '.') . ' Ä‘iá»ƒm cho Ä‘Æ¡n hÃ ng.');
        redirect(BASE_URL . '/index.php?r=thanhtoan');
    }

    public function boDiem(): void {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            redirect(BASE_URL . '/index.php?r=thanhtoan');
        }

        $this->storeCheckoutPaymentMethodFromRequest();
        $this->storeCheckoutReceiverFromRequest($this->getDefaultCheckoutReceiver($this->getCurrentCheckoutCustomer(), current_user() ?? []));
        unset($_SESSION['checkout_points']);
        set_flash('success', 'ÄÃ£ gá»¡ Ä‘iá»ƒm tÃ­ch lÅ©y khá»i Ä‘Æ¡n hÃ ng.');
        redirect(BASE_URL . '/index.php?r=thanhtoan');
    }

    public function xulydathang() {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            redirect(BASE_URL . '/index.php?r=giohang');
        }

        if (!is_logged_in()) {
            set_flash('error', 'Vui lÃ²ng Ä‘Äƒng nháº­p Ä‘á»ƒ Ä‘áº·t hÃ ng.');
            redirect(BASE_URL . '/index.php?r=dangnhap');
        }

        $checkoutItems = $_SESSION['checkout_items'] ?? [];
        if (empty($checkoutItems) || !is_array($checkoutItems)) {
            set_flash('error', 'KhÃ´ng cÃ³ sáº£n pháº©m Ä‘á»ƒ Ä‘áº·t hÃ ng.');
            redirect(BASE_URL . '/index.php?r=giohang');
        }

        $checkoutPreview = $this->buildCheckoutPreview($checkoutItems);
        if (empty($checkoutPreview['items'])) {
            unset($_SESSION['checkout_items'], $_SESSION['checkout_voucher']);
            set_flash('error', 'Sáº£n pháº©m trong danh sÃ¡ch thanh toÃ¡n khÃ´ng cÃ²n tá»“n táº¡i.');
            redirect(BASE_URL . '/index.php?r=giohang');
        }

        $subtotal = (int)($checkoutPreview['subtotal'] ?? 0);

        $hinhThucThanhToan = strtolower(trim((string)($_POST['hinh_thuc_thanh_toan'] ?? 'cod')));

        $user = current_user() ?? [];
        $defaultReceiver = $this->getDefaultCheckoutReceiver($this->getCurrentCheckoutCustomer(), $user);
        $this->storeCheckoutPaymentMethodFromRequest();
        $this->storeCheckoutReceiverFromRequest($defaultReceiver);
        $receiverResolution = $this->resolveCheckoutReceiverFromRequest($defaultReceiver);
        if (empty($receiverResolution['ok'])) {
            set_flash('error', (string)($receiverResolution['message'] ?? 'Vui long kiem tra lai thong tin nhan hang.'));
            redirect(BASE_URL . '/index.php?r=thanhtoan');
        }

        $tenNguoiNhan = trim((string)($receiverResolution['receiver']['ten_nguoi_nhan'] ?? ''));
        $sdtNguoiNhan = trim((string)($receiverResolution['receiver']['sdt_nguoi_nhan'] ?? ''));
        $diaChiGiaoHang = trim((string)($receiverResolution['receiver']['dia_chi_giao_hang'] ?? ''));

        if ($tenNguoiNhan === '' || $sdtNguoiNhan === '' || $diaChiGiaoHang === '') {
            set_flash('error', 'Vui lÃ²ng Ä‘iá»n Ä‘áº§y Ä‘á»§ thÃ´ng tin nháº­n hÃ ng.');
            redirect(BASE_URL . '/index.php?r=thanhtoan');
        }

        $allowedMethods = ['cod'];
        if ($this->isQrTransferEnabled()) {
            $allowedMethods[] = 'bank_transfer_qr';
        }
        if (!in_array($hinhThucThanhToan, $allowedMethods, true)) {
            set_flash('error', 'PhÆ°Æ¡ng thá»©c thanh toÃ¡n khÃ´ng há»£p lá»‡.');
            redirect(BASE_URL . '/index.php?r=thanhtoan');
        }

    $_SESSION['checkout_payment_method'] = $hinhThucThanhToan;
        $email = (string)($user['email'] ?? '');

        $appliedVoucher = $this->getAppliedVoucher($subtotal, false);
        $voucherDiscountAmount = 0;
        if (trim((string)(($_SESSION['checkout_voucher']['code'] ?? ''))) !== '' && $appliedVoucher === null) {
            set_flash('error', 'MÃ£ giáº£m giÃ¡ khÃ´ng cÃ²n há»£p lá»‡. Vui lÃ²ng kiá»ƒm tra láº¡i Ä‘Æ¡n hÃ ng.');
            redirect(BASE_URL . '/index.php?r=thanhtoan');
        }
        if ($appliedVoucher) {
            $voucherDiscountAmount = (int)($appliedVoucher['discount'] ?? 0);
        }

        $customer = $this->getCurrentCheckoutCustomer();
        $pointRedemption = $this->getAppliedPointsRedemption(max(0, $subtotal - $voucherDiscountAmount), $customer, false);
        $pointsDiscountAmount = (int)($pointRedemption['discount'] ?? 0);

        try {
            if (($receiverResolution['choice'] ?? '') === 'new' && !empty($receiverResolution['new_receiver']['save_as_default']) && $email !== '') {
                $tkModel = new TaiKhoan($this->pdo);
                $tkModel->saveThongTinKhachHang($email, [
                    'ho_ten' => $tenNguoiNhan,
                    'so_dien_thoai' => $sdtNguoiNhan,
                    'dia_chi' => $diaChiGiaoHang,
                ]);
            }

            $maHoaDon = $this->hoaDonModel->taoDonHang([ 
                'email' => $email,
                'ho_ten_mac_dinh' => (string)($user['ho_ten'] ?? ''),
                'ten_nguoi_nhan' => $tenNguoiNhan,
                'sdt_nguoi_nhan' => $sdtNguoiNhan,
                'dia_chi_giao_hang' => $diaChiGiaoHang,
                'hinh_thuc_thanh_toan' => $hinhThucThanhToan,
                'phi_van_chuyen' => 30000,
                'tam_tinh' => $subtotal,
                'so_tien_giam' => $voucherDiscountAmount,
                'diem_su_dung' => (int)($pointRedemption['points'] ?? 0),
                'tien_giam_diem' => $pointsDiscountAmount,
                'ma_voucher' => $appliedVoucher['voucher']['ma_voucher'] ?? null,
                'ma_giam_gia' => $appliedVoucher['voucher']['ma_code'] ?? '',
                'checkout_items' => $checkoutItems,
            ]);

            foreach ($checkoutItems as $idSp => $_qty) {
                unset($_SESSION['gio_hang'][$idSp]);
            }
            unset($_SESSION['checkout_items'], $_SESSION['checkout_voucher'], $_SESSION['checkout_points'], $_SESSION['checkout_payment_method'], $_SESSION['checkout_address_choice'], $_SESSION['checkout_new_receiver']);

            if ($hinhThucThanhToan === 'bank_transfer_qr') {
                set_flash('success', 'ÄÆ¡n hÃ ng Ä‘Ã£ Ä‘Æ°á»£c táº¡o. Vui lÃ²ng quÃ©t QR vÃ  chuyá»ƒn khoáº£n theo Ä‘Ãºng ná»™i dung Ä‘á»ƒ hoÃ n táº¥t thanh toÃ¡n.');
            } else {
                set_flash('success', 'Äáº·t hÃ ng thÃ nh cÃ´ng. Cáº£m Æ¡n báº¡n Ä‘Ã£ mua sáº¯m táº¡i SkinSyntax.');
            }
            redirect(BASE_URL . '/index.php?r=camon&ma_hoa_don=' . urlencode((string)$maHoaDon));
        } catch (Throwable $e) {
            error_log('xulydathang error: ' . $e->getMessage());
            set_flash('error', 'KhÃ´ng thá»ƒ Ä‘áº·t hÃ ng lÃºc nÃ y. Vui lÃ²ng thá»­ láº¡i.');
            redirect(BASE_URL . '/index.php?r=thanhtoan');
        }
    }

    public function camon() {
        $maHoaDon = trim((string)($_GET['ma_hoa_don'] ?? ''));
        $order = null;
        $transferData = null;
        $autoCheckEnabled = false;

        if ($maHoaDon !== '' && ctype_digit($maHoaDon)) {
            $currentUser = current_user() ?? [];
            $email = trim((string)($currentUser['email'] ?? ''));
            $order = method_exists($this->hoaDonModel, 'getOrderById')
                ? $this->hoaDonModel->{'getOrderById'}((int)$maHoaDon, $email !== '' ? $email : null)
                : null;

            if ($order && strtolower(trim((string)($order['hinh_thuc_thanh_toan'] ?? ''))) === 'bank_transfer_qr' && $this->isQrTransferEnabled()) {
                $content = 'SKIN HD' . (string)($order['ma_hoa_don'] ?? $maHoaDon);
                $qrConfig = $this->getQrTransferConfig();
                $transferData = [
                    'bank_name' => $qrConfig['bank_name'] !== '' ? $qrConfig['bank_name'] : $qrConfig['bank_id'],
                    'account_no' => $qrConfig['account_no'],
                    'account_name' => $qrConfig['account_name'],
                    'content' => $content,
                    'amount' => (int)($order['tong_tien'] ?? 0),
                    'payment_status' => (string)($order['status_thanh_toan'] ?? 'Chua thanh toan'),
                    'qr_url' => $this->buildVietQrUrl((int)($order['tong_tien'] ?? 0), $content),
                ];
                $autoCheckEnabled = $this->isSePayPollingEnabled()
                    && strtolower(trim((string)($order['status_thanh_toan'] ?? ''))) !== 'da thanh toan';
            }
        }

        $this->render('camon', [
            'maHoaDon' => $maHoaDon,
            'order' => $order,
            'paymentMethodLabel' => $order ? $this->getPaymentMethodLabel((string)($order['hinh_thuc_thanh_toan'] ?? 'cod')) : $this->getPaymentMethodLabel('cod'),
            'transferData' => $transferData,
            'autoCheckEnabled' => $autoCheckEnabled,
            'autoCheckUrl' => $order ? (BASE_URL . '/index.php?r=payment_autocheck&order_id=' . urlencode((string)($order['ma_hoa_don'] ?? ''))) : '',
        ]);
    }

    public function paymentAutoCheck(): void {
        if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
            $this->jsonResponse([
                'ok' => false,
                'message' => 'Method not allowed',
            ], 405);
        }

        if (!is_logged_in()) {
            $this->jsonResponse([
                'ok' => false,
                'message' => 'Unauthorized',
            ], 401);
        }

        if (!$this->isSePayPollingEnabled()) {
            $this->jsonResponse([
                'ok' => false,
                'message' => 'SePay API polling chÆ°a Ä‘Æ°á»£c cáº¥u hÃ¬nh.',
            ], 200);
        }

        $orderId = max(0, (int)($_GET['order_id'] ?? 0));
        if ($orderId <= 0) {
            $this->jsonResponse([
                'ok' => false,
                'message' => 'MÃ£ Ä‘Æ¡n hÃ ng khÃ´ng há»£p lá»‡.',
            ], 400);
        }

        $currentUser = current_user() ?? [];
        $email = trim((string)($currentUser['email'] ?? ''));
        $order = method_exists($this->hoaDonModel, 'getOrderById')
            ? $this->hoaDonModel->{'getOrderById'}($orderId, $email !== '' ? $email : null)
            : null;

        if (!$order) {
            $this->jsonResponse([
                'ok' => false,
                'message' => 'KhÃ´ng tÃ¬m tháº¥y Ä‘Æ¡n hÃ ng.',
            ], 404);
        }

        $paymentMethod = strtolower(trim((string)($order['hinh_thuc_thanh_toan'] ?? 'cod')));
        $paymentStatus = trim((string)($order['status_thanh_toan'] ?? 'Chua thanh toan'));
        if ($paymentMethod !== 'bank_transfer_qr') {
            $this->jsonResponse([
                'ok' => true,
                'paid' => strtolower($paymentStatus) === 'da thanh toan',
                'payment_status' => $paymentStatus,
                'message' => 'ÄÆ¡n hÃ ng nÃ y khÃ´ng dÃ¹ng chuyá»ƒn khoáº£n QR.',
                'order_id' => $orderId,
            ]);
        }

        if (strtolower($paymentStatus) === 'da thanh toan') {
            $this->jsonResponse([
                'ok' => true,
                'paid' => true,
                'payment_status' => $paymentStatus,
                'message' => 'ÄÆ¡n hÃ ng Ä‘Ã£ Ä‘Æ°á»£c thanh toÃ¡n.',
                'order_id' => $orderId,
            ]);
        }

        $transactionsResult = $this->fetchSePayTransactionsForOrder($order);
        if (empty($transactionsResult['ok'])) {
            $this->jsonResponse([
                'ok' => false,
                'paid' => false,
                'payment_status' => $paymentStatus,
                'message' => (string)($transactionsResult['message'] ?? 'KhÃ´ng thá»ƒ kiá»ƒm tra giao dá»‹ch SePay.'),
                'order_id' => $orderId,
            ]);
        }

        $matched = null;
        foreach (($transactionsResult['transactions'] ?? []) as $transaction) {
            $matchedOrderId = $this->extractOrderIdFromTransferContent((string)($transaction['content'] ?? ''));
            if ($matchedOrderId !== $orderId) {
                continue;
            }

            $matched = $transaction;
            $result = method_exists($this->hoaDonModel, 'markPaidByTransfer')
                ? $this->hoaDonModel->{'markPaidByTransfer'}(
                    $orderId,
                    (int)($transaction['amount'] ?? 0),
                    (string)($transaction['reference'] ?? ''),
                    (string)($transaction['content'] ?? '')
                )
                : [
                    'ok' => false,
                    'message' => 'Transfer reconciliation is unavailable.',
                ];

            if (!empty($result['ok'])) {
                $updatedOrder = method_exists($this->hoaDonModel, 'getOrderById')
                    ? $this->hoaDonModel->{'getOrderById'}($orderId, $email !== '' ? $email : null)
                    : $order;
                $updatedStatus = trim((string)($updatedOrder['status_thanh_toan'] ?? 'Da thanh toan'));

                $this->jsonResponse([
                    'ok' => true,
                    'paid' => true,
                    'payment_status' => $updatedStatus,
                    'message' => 'ÄÃ£ nháº­n giao dá»‹ch chuyá»ƒn khoáº£n vÃ  cáº­p nháº­t Ä‘Æ¡n hÃ ng.',
                    'order_id' => $orderId,
                    'matched_transaction' => $matched,
                ]);
            }
        }

        $this->jsonResponse([
            'ok' => true,
            'paid' => false,
            'payment_status' => $paymentStatus,
            'message' => 'ChÆ°a tÃ¬m tháº¥y giao dá»‹ch phÃ¹ há»£p. Há»‡ thá»‘ng sáº½ tiáº¿p tá»¥c kiá»ƒm tra.',
            'order_id' => $orderId,
            'matched_transaction' => $matched,
        ]);
    }

    public function paymentWebhook(): void {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->jsonResponse([
                'ok' => false,
                'message' => 'Method not allowed',
            ], 405);
        }

        if (!$this->isValidWebhookRequest()) {
            $this->jsonResponse([
                'ok' => false,
                'message' => 'Unauthorized webhook request',
            ], 401);
        }

        $raw = file_get_contents('php://input');
        $payload = json_decode((string)$raw, true);
        if (!is_array($payload)) {
            $this->jsonResponse([
                'ok' => false,
                'message' => 'Invalid JSON payload',
            ], 400);
        }

        $transactions = $this->normalizeWebhookTransactions($payload);
        if (empty($transactions)) {
            $this->jsonResponse([
                'ok' => true,
                'message' => 'No eligible incoming transaction found',
                'processed' => 0,
            ]);
        }

        $processed = [];
        $ignored = [];

        foreach ($transactions as $transaction) {
            $orderId = $this->extractOrderIdFromTransferContent((string)$transaction['content']);
            if ($orderId === null || $orderId <= 0) {
                $ignored[] = [
                    'reason' => 'missing_order_code',
                    'content' => $transaction['content'],
                ];
                continue;
            }

            $result = method_exists($this->hoaDonModel, 'markPaidByTransfer')
                ? $this->hoaDonModel->{'markPaidByTransfer'}(
                    $orderId,
                    (int)$transaction['amount'],
                    (string)($transaction['reference'] ?? ''),
                    (string)$transaction['content']
                )
                : [
                    'ok' => false,
                    'message' => 'Transfer reconciliation is unavailable.',
                ];

            if (!empty($result['ok'])) {
                $processed[] = [
                    'order_id' => $orderId,
                    'amount' => (int)$transaction['amount'],
                    'already_paid' => !empty($result['already_paid']),
                ];
            } else {
                $ignored[] = [
                    'order_id' => $orderId,
                    'reason' => (string)($result['message'] ?? 'update_failed'),
                ];
            }
        }

        $this->jsonResponse([
            'ok' => true,
            'processed' => count($processed),
            'items' => $processed,
            'ignored' => $ignored,
        ]);
    }

    public function xulygoiy(): void {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(405);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['ok' => false, 'message' => 'Method not allowed'], JSON_UNESCAPED_UNICODE);
            exit;
        }

        $gioiTinh = trim((string)($_POST['gioi_tinh'] ?? ''));
        $namSinhRaw = trim((string)($_POST['nam_sinh'] ?? ''));

        // Bắt buộc có giới tính để hồ sơ gợi ý không bị thiếu dữ liệu cơ bản.
        if ($gioiTinh === '') {
            http_response_code(422);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['ok' => false, 'message' => 'Vui lÃ²ng chá»n giá»›i tÃ­nh (CÃ¢u 1).'], JSON_UNESCAPED_UNICODE);
            exit;
        }

        // Năm sinh phải là số để tính/kiểm tra hồ sơ hợp lệ.
        if ($namSinhRaw === '' || !ctype_digit($namSinhRaw)) {
            http_response_code(422);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['ok' => false, 'message' => 'Vui lÃ²ng nháº­p nÄƒm sinh há»£p lá»‡.'], JSON_UNESCAPED_UNICODE);
            exit;
        }

        $year = (int)$namSinhRaw;
        $currentYear = (int)date('Y');
        // Chặn năm sinh quá vô lý, ví dụ nhỏ hơn 1900 hoặc lớn hơn năm hiện tại.
        if ($year < 1900 || $year > $currentYear) {
            http_response_code(422);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['ok' => false, 'message' => 'NÄƒm sinh khÃ´ng há»£p lá»‡.'], JSON_UNESCAPED_UNICODE);
            exit;
        }

        try {
            // Gom dữ liệu từ form thành hồ sơ recommendation.
            // Hồ sơ này sẽ được đưa sang hybrid AI trước, nếu lỗi thì dùng fallback MongoDB.
            $profileForRecommendation = [
                'gioi_tinh' => $gioiTinh,
                'nam_sinh' => $year,
                'skin_type' => trim((string)($_POST['skin_type'] ?? '')),
                'concerns' => array_values(is_array($_POST['concerns'] ?? null) ? $_POST['concerns'] : []),
                'avoid_ingredients' => array_values(array_filter(array_map('trim', preg_split('/[,;\n\r]+/u', (string)($_POST['avoid_ingredients'] ?? '')) ?: []))),
                'budget' => (int)preg_replace('/[^\d]/', '', (string)($_POST['budget'] ?? '0')),
                'sensitivity' => trim((string)($_POST['sensitivity'] ?? '')),
            ];

            $queryText = trim((string)($_POST['query_text'] ?? ''));

            // Nếu người dùng đã đăng nhập thì ưu tiên lấy thêm hồ sơ da đã lưu trong tài khoản.
            $loggedInEmail = '';
            if (function_exists('is_logged_in') && is_logged_in()) {
                $user = current_user() ?? [];
                $loggedInEmail = trim((string)($user['email'] ?? ''));
                if ($loggedInEmail !== '') {
                    $savedProfile = $this->buildRecommendationProfile($loggedInEmail);
                    if (is_array($savedProfile)) {
                        // Trộn hồ sơ đã lưu với dữ liệu form hiện tại; dữ liệu form mới sẽ được ưu tiên.
                        $profileForRecommendation = array_merge($savedProfile, $profileForRecommendation);
                        $profileForRecommendation['concerns'] = !empty($profileForRecommendation['concerns'])
                            ? array_values($profileForRecommendation['concerns'])
                            : array_values($savedProfile['concerns'] ?? []);
                        $profileForRecommendation['avoid_ingredients'] = !empty($profileForRecommendation['avoid_ingredients'])
                            ? array_values($profileForRecommendation['avoid_ingredients'])
                            : array_values($savedProfile['avoid_ingredients'] ?? []);
                    }
                }
            }

            // Luôn dùng giọng tư vấn tự nhiên (chatbot prompt), không cần chọn mode trên giao diện.
            $profileForRecommendation['interaction_mode'] = 'chatbot';
            // Lưu câu hỏi người dùng vào profile để fallback explanation có thể nhắc lại đúng nhu cầu.
            $profileForRecommendation['user_query'] = $queryText;

            // Gọi AI/RAG hybrid trước: service này tìm sản phẩm bằng MongoDB + keyword/semantic + LLM.
            $hybridResult = $this->fetchAiHybridRecommendations($profileForRecommendation, $queryText);
            $results = [];
            // Mặc định là fallback; nếu hybrid thành công thì đổi thành "hybrid" để frontend/debug biết nguồn dữ liệu.
            $searchMode = 'fallback';
            $adviceText = trim((string)($hybridResult['summary'] ?? ''));

            if (!empty($hybridResult['ok']) && !empty($hybridResult['items'])) {
                // Trường hợp AI/RAG trả sản phẩm hợp lệ: dùng luôn danh sách từ hybrid.
                $results = $hybridResult['items'];
                $searchMode = 'hybrid';
                foreach ($results as &$item) {
                    // Nếu hybrid có sản phẩm nhưng thiếu lời giải thích thì tự dựng câu giải thích từ dữ liệu DB.
                    $aiExplanation = trim((string)($item['llm_explanation'] ?? ''));
                    $explanationSource = trim((string)($item['explanation_source'] ?? ''));
                    if ($aiExplanation === '') {
                        $aiExplanation = $this->buildRecommendationFallbackExplanation($profileForRecommendation, $item);
                        $explanationSource = 'rag';
                    } elseif ($explanationSource === '') {
                        $explanationSource = 'llm';
                    }

                    $item['llm_explanation'] = $aiExplanation;
                    $item['explanation_source'] = $explanationSource;
                    // Chuẩn hóa ảnh về URL đầu tiên dùng được để frontend hiển thị.
                    $item['image_url'] = resolve_image_url((string)($item['image_url'] ?? $item['link_hinh_anh'] ?? ''));
                }
                unset($item);
                $aiResult = [
                    'ok' => true,
                    'message' => trim((string)($hybridResult['message'] ?? '')),
                ];
            } else {
                // Nếu AI/RAG lỗi hoặc không tìm được sản phẩm, dùng thuật toán fallback trong PHP đọc trực tiếp MongoDB.
                $results = $this->goiYModel->recommendFromPost($_POST, 12);
                foreach ($results as &$item) {
                    // Chuẩn hóa ảnh của sản phẩm fallback.
                    $item['image_url'] = resolve_image_url((string)($item['link_hinh_anh'] ?? ''));
                }
                unset($item);

                // Sau khi fallback đã lọc được sản phẩm, thử gọi AI chỉ để viết lời giải thích tự nhiên hơn.
                $aiResult = $this->fetchAiRecommendationExplanations($profileForRecommendation, $results);
                foreach ($results as &$item) {
                    $productId = trim((string)($item['id'] ?? ''));
                    $aiExplanation = $productId !== '' ? trim((string)($aiResult['items'][$productId]['llm_explanation'] ?? '')) : '';
                    if ($aiExplanation === '') {
                        // Nếu AI không trả lời, dùng câu giải thích fallback từ dữ liệu sản phẩm để giao diện vẫn có nội dung.
                        $aiExplanation = $this->buildRecommendationFallbackExplanation($profileForRecommendation, $item);
                        $item['explanation_source'] = 'rag';
                    } else {
                        $item['explanation_source'] = trim((string)($aiResult['items'][$productId]['source'] ?? 'llm')) ?: 'llm';
                    }

                    $item['llm_explanation'] = $aiExplanation;
                }
                unset($item);

                if ($adviceText === '') {
                    // Summary mặc định cho phần đầu kết quả khi AI không trả summary riêng.
                    $adviceText = 'Đây là danh sách ưu tiên dựa trên hồ sơ da, ngân sách và mối quan tâm bạn vừa cung cấp.';
                }
            }
        } catch (Throwable $e) {
            // Bắt mọi lỗi để backend không trả fatal error ra frontend.
            error_log('xulygoiy error: ' . $e->getMessage());
            http_response_code(500);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode([
                'ok' => false,
                'message' => 'Hệ gợi ý đang gặp lỗi khi xử lý dữ liệu sản phẩm. Vui lòng thử lại sau.',
            ], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
            exit;
        }

        if (function_exists('is_logged_in') && is_logged_in()) {
            // Nếu người dùng đăng nhập, lưu lại một phần thông tin cơ bản để cá nhân hóa lần sau.
            $user = current_user() ?? [];
            $email = trim((string)($user['email'] ?? ''));
            if ($email !== '') {
                $taiKhoanModel = new TaiKhoan($this->pdo);
                $taiKhoanModel->saveThongTinKhachHang($email, [
                    'ho_ten' => trim((string)($user['ho_ten'] ?? '')),
                    'gioi_tinh' => $gioiTinh,
                    'nam_sinh' => $year,
                    'so_dien_thoai' => '',
                    'dia_chi' => '',
                ]);
            }
        }

        header('Content-Type: application/json; charset=utf-8');
        // Trả JSON cho frontend goiy.php. JSON_INVALID_UTF8_SUBSTITUTE giúp tránh lỗi JSON rỗng khi có text encoding xấu.
        echo json_encode([
            'ok' => true,
            'count' => count($results),
            'data' => $results,
            'advice_text' => $adviceText,
            'cached' => !empty($hybridResult['cached']),
            'search_mode' => $searchMode,
            'query' => trim((string)($hybridResult['query'] ?? $queryText ?? '')),
            'ai' => [
                'enabled' => !empty($aiResult['ok']),
                'message' => (string)($aiResult['message'] ?? ''),
            ],
        ], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        exit;
    }
}


