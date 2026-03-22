<?php
// backend/app/controllers/HomeController.php
require_once __DIR__ . '/../models/SanPham.php';
require_once __DIR__ . '/../models/GoiYContentBased.php';
require_once __DIR__ . '/../models/TaiKhoan.php';
require_once __DIR__ . '/../models/HoaDon.php';
require_once __DIR__ . '/../models/QuanTri.php';
require_once __DIR__ . '/../models/Voucher.php';

class HomeController {
    private PDO $pdo;
    private SanPham $model;
    private GoiYContentBased $goiYModel;
    private HoaDon $hoaDonModel;
    private Voucher $voucherModel;
    private const POINT_VALUE_VND = 1000;
    private const VIP_THRESHOLD = 500;
    private const DIAMOND_THRESHOLD = 1500;
    private const AI_CHAT_CACHE_TTL = 604800;
    private const AI_CHAT_CACHE_MAX_ITEMS = 300;

    public function __construct(PDO $pdo) {
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

    private function normalizeAiChatCacheKey(string $message): string {
        $normalized = function_exists('mb_strtolower') ? mb_strtolower($message, 'UTF-8') : strtolower($message);
        $normalized = preg_replace('/\s+/u', ' ', trim($normalized)) ?? trim($normalized);
        return hash('sha256', $normalized);
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

    private function getCachedAiResponsePayload(string $message): ?array {
        if (!$this->shouldUseAiResponseCache($message)) {
            return null;
        }

        $cache = $this->loadAiChatCache();
        $cacheKey = $this->normalizeAiChatCacheKey($message);
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

    private function storeAiResponsePayload(string $message, array $payload): void {
        if (!$this->shouldUseAiResponseCache($message)) {
            return;
        }

        $answer = trim((string)($payload['answer'] ?? ''));
        if ($answer === '') {
            return;
        }

        $cache = $this->loadAiChatCache();
        $cache[$this->normalizeAiChatCacheKey($message)] = [
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
        // menuCats dùng chung layout
        $menuCats = $this->model->menuTree();
        require __DIR__ . '/../views/layouts/header.php';
        require __DIR__ . '/../views/' . $view . '.php';
        require __DIR__ . '/../views/layouts/footer.php';
    }

    public function index() {
        $latest = $this->model->latest(8, true);
        $cats = $this->getHighlightedCategories();
        $this->render('home', ['latest' => $latest, 'cats' => $cats]);
    }

    public function otpGuide() {
        $this->render('info/otp-guide');
    }

    public function termsReference() {
        $this->renderPolicyReference([
            'title' => 'Điều kiện giao dịch chung',
            'eyebrow' => 'Điều khoản SkinSyntax',
            'summary' => 'Điều kiện giao dịch này do SkinSyntax ban hành và áp dụng cho toàn bộ hoạt động đăng ký tài khoản, truy cập nội dung, mua sắm, thanh toán và sử dụng dịch vụ trên website.',
            'highlights' => [
                'Người dùng cam kết cung cấp thông tin đúng sự thật khi đăng ký tài khoản, đặt hàng, thanh toán và làm khảo sát da. SkinSyntax có quyền từ chối xử lý khi phát hiện thông tin sai lệch hoặc có dấu hiệu gian lận.',
                'Mọi đơn hàng chỉ được xác nhận sau khi SkinSyntax kiểm tra tình trạng sản phẩm, thông tin nhận hàng, phương thức thanh toán và các điều kiện áp dụng của voucher hoặc chương trình khuyến mãi.',
                'Giá bán, ưu đãi, phí vận chuyển và thời gian giao hàng có thể thay đổi theo từng thời điểm. SkinSyntax sẽ hiển thị thông tin hiện hành trên website trước khi người dùng hoàn tất giao dịch.',
                'Người dùng có trách nhiệm bảo mật tài khoản, mật khẩu, mã OTP và các thiết bị đăng nhập. Mọi thao tác phát sinh từ tài khoản đã xác thực được xem là do chính chủ tài khoản thực hiện, trừ khi có chứng cứ ngược lại.',
                'Nội dung, hình ảnh, logo, bố cục, dữ liệu sản phẩm và các tài nguyên hiển thị trên SkinSyntax thuộc quyền quản lý của SkinSyntax hoặc đối tác cấp phép; không được sao chép, khai thác lại hoặc sử dụng trái phép.',
                'Khi phát sinh khiếu nại, tranh chấp hoặc yêu cầu hỗ trợ, hai bên ưu tiên giải quyết trên tinh thần hợp tác. Trường hợp không thể tự thỏa thuận, vấn đề sẽ được xử lý theo quy định pháp luật Việt Nam.',
            ],
        ]);
    }

    public function privacyReference() {
        $this->renderPolicyReference([
            'title' => 'Chính sách bảo mật thông tin',
            'eyebrow' => 'Bảo mật tại SkinSyntax',
            'summary' => 'SkinSyntax cam kết bảo vệ thông tin cá nhân, lịch sử giao dịch, dữ liệu khảo sát da và các dữ liệu kỹ thuật phát sinh trong quá trình người dùng sử dụng website.',
            'highlights' => [
                'SkinSyntax chỉ thu thập những thông tin cần thiết cho việc tạo tài khoản, xác thực OTP, xử lý đơn hàng, chăm sóc khách hàng, cá nhân hóa gợi ý sản phẩm và duy trì vận hành hệ thống.',
                'Dữ liệu của người dùng được lưu trữ với các biện pháp kiểm soát truy cập phù hợp. Chỉ nhân sự, bộ phận hoặc dịch vụ được ủy quyền mới được tiếp cận dữ liệu trong phạm vi công việc cần thiết.',
                'SkinSyntax không bán hoặc trao đổi thông tin cá nhân của người dùng cho bên thứ ba vì mục đích thương mại độc lập. Việc chia sẻ chỉ diễn ra khi cần thiết để giao hàng, xử lý thanh toán, gửi thông báo hoặc tuân thủ yêu cầu pháp luật.',
                'Người dùng có quyền yêu cầu xem lại, cập nhật, chỉnh sửa hoặc hạn chế xử lý thông tin của mình thông qua các kênh hỗ trợ do SkinSyntax công bố trên website.',
                'Trong trường hợp phát hiện truy cập trái phép, rò rỉ dữ liệu hoặc rủi ro an toàn thông tin, SkinSyntax sẽ đánh giá tác động, áp dụng biện pháp khắc phục phù hợp và thông báo cho các bên liên quan khi cần thiết.',
            ],
        ]);
    }

    public function personalDataReference() {
        $this->renderPolicyReference([
            'title' => 'Chính sách xử lý dữ liệu cá nhân',
            'eyebrow' => 'Quyền riêng tư người dùng',
            'summary' => 'Chính sách này mô tả cách SkinSyntax tiếp nhận, sử dụng, lưu trữ, chia sẻ có kiểm soát và bảo vệ dữ liệu cá nhân của người dùng trong toàn bộ vòng đời dịch vụ.',
            'highlights' => [
                'Dữ liệu cá nhân có thể bao gồm thông tin nhận dạng, thông tin liên hệ, địa chỉ giao hàng, lịch sử mua sắm, phản hồi sản phẩm, dữ liệu khảo sát da và dữ liệu kỹ thuật phục vụ bảo mật hệ thống.',
                'SkinSyntax xử lý dữ liệu cá nhân trên cơ sở sự đồng ý của người dùng, nhu cầu thực hiện hợp đồng mua bán, nghĩa vụ pháp lý hoặc lợi ích hợp pháp liên quan đến bảo mật và vận hành dịch vụ.',
                'Dữ liệu được lưu giữ trong thời gian cần thiết để hoàn thành mục đích thu thập, giải quyết tranh chấp, hỗ trợ hậu mãi và đáp ứng yêu cầu lưu trữ theo quy định pháp luật hiện hành.',
                'Người dùng có quyền đồng ý, từ chối, rút lại sự đồng ý, yêu cầu cung cấp bản sao dữ liệu, yêu cầu chỉnh sửa hoặc đề nghị xóa dữ liệu nếu việc xóa không xung đột với nghĩa vụ lưu trữ bắt buộc.',
                'SkinSyntax có thể sử dụng cookie hoặc công nghệ tương đương cho chức năng đăng nhập, ghi nhớ tùy chọn, thống kê và tối ưu trải nghiệm; người dùng có thể tự điều chỉnh bằng cài đặt trình duyệt của mình.',
                'Khi có cập nhật quan trọng liên quan đến phạm vi xử lý dữ liệu cá nhân, SkinSyntax sẽ công bố phiên bản mới trên website để người dùng chủ động theo dõi.',
            ],
        ]);
    }

    public function storeNetwork(): void {
        $this->render('info/store-network', [
            'title' => 'Hệ thống cửa hàng SkinSyntax',
            'eyebrow' => 'Hệ thống phục vụ',
            'summary' => 'SkinSyntax đang vận hành theo mô hình online-first: ưu tiên tra cứu sản phẩm, tư vấn routine, hỗ trợ đơn hàng và xử lý sau mua ngay trên website. Thông tin điểm hỗ trợ trực tiếp sẽ được cập nhật theo từng giai đoạn mở rộng.',
            'stats' => [
                ['value' => 'Toàn quốc', 'label' => 'Phạm vi phục vụ qua kênh online'],
                ['value' => '08:00 - 22:00', 'label' => 'Khung giờ hỗ trợ khách hàng'],
                ['value' => '1900 0000', 'label' => 'Hotline tiếp nhận nhanh'],
            ],
            'channels' => [
                [
                    'title' => 'Mua sắm online tập trung',
                    'text' => 'Tra cứu toàn bộ danh mục, so sánh thành phần, kiểm tra giá bán và đặt hàng trực tiếp trên website SkinSyntax mà không cần chuyển kênh.',
                    'icon' => 'fa-solid fa-bag-shopping',
                ],
                [
                    'title' => 'Tư vấn AI và chat hỗ trợ',
                    'text' => 'Bạn có thể hỏi AI về routine, thành phần, sản phẩm phù hợp hoặc mở khung chat hỗ trợ để trao đổi trực tiếp với bộ phận chăm sóc khách hàng.',
                    'icon' => 'fa-solid fa-headset',
                ],
                [
                    'title' => 'Theo dõi đơn hàng rõ trạng thái',
                    'text' => 'Luồng mua hàng của SkinSyntax ưu tiên trạng thái rõ ràng từ lúc đặt đơn, áp voucher, thanh toán đến bước hoàn tất và hậu mãi.',
                    'icon' => 'fa-solid fa-truck-fast',
                ],
            ],
            'serviceSteps' => [
                'Bước 1: Tìm sản phẩm hoặc làm khảo sát da để hệ thống hiểu nhu cầu chăm sóc da của bạn.',
                'Bước 2: Đặt hàng, theo dõi đơn và lưu lịch sử mua sắm ngay trong tài khoản SkinSyntax.',
                'Bước 3: Khi cần hỗ trợ sau mua, mở chat hỗ trợ hoặc gọi hotline để được hướng dẫn tiếp tục.',
            ],
            'helpLinks' => [
                ['label' => 'Khám phá toàn bộ sản phẩm', 'url' => BASE_URL . '/index.php?r=tatca'],
                ['label' => 'Mở gợi ý routine AI', 'url' => BASE_URL . '/index.php?r=goiy'],
                ['label' => 'Trung tâm hỗ trợ khách hàng', 'url' => BASE_URL . '/index.php?r=ho_tro_khach_hang'],
            ],
        ]);
    }

    public function warrantyCenter(): void {
        $this->render('info/service-hub', [
            'title' => 'Bảo hành và hỗ trợ sau mua',
            'eyebrow' => 'Chăm sóc sau bán',
            'summary' => 'SkinSyntax tiếp nhận yêu cầu liên quan đến lỗi sản phẩm, hướng dẫn đổi trả hợp lệ, xác minh hóa đơn và điều phối hỗ trợ với nhà cung cấp khi cần.',
            'sections' => [
                [
                    'title' => 'Phạm vi tiếp nhận',
                    'items' => [
                        'Sản phẩm nhận sai, thiếu phụ kiện, lỗi do vận chuyển hoặc có dấu hiệu bất thường khi mở hộp.',
                        'Sản phẩm có chính sách bảo hành riêng từ nhà phân phối hoặc cần xác minh tem, mã lô, hóa đơn mua hàng.',
                        'Yêu cầu kiểm tra tình trạng đơn hàng sau mua, bổ sung thông tin hoặc hướng dẫn gửi lại sản phẩm để đối soát.',
                    ],
                ],
                [
                    'title' => 'Thông tin cần chuẩn bị',
                    'items' => [
                        'Mã đơn hàng hoặc số điện thoại dùng khi mua sắm.',
                        'Tên sản phẩm, số lượng gặp vấn đề và mô tả tình trạng thực tế.',
                        'Hình ảnh/video lúc mở kiện hàng nếu có, để SkinSyntax rút ngắn thời gian xác minh.',
                    ],
                ],
                [
                    'title' => 'Quy trình xử lý',
                    'items' => [
                        'Tiếp nhận yêu cầu qua hotline hoặc chat hỗ trợ và xác nhận thông tin đơn.',
                        'Đánh giá tình trạng sản phẩm, kiểm tra chứng từ mua hàng và hướng xử lý phù hợp.',
                        'Phản hồi phương án tiếp theo: đổi sản phẩm, hoàn tiền, bổ sung thông tin hoặc liên hệ nhà cung cấp.',
                    ],
                ],
            ],
            'supportCard' => [
                'title' => 'Liên hệ bảo hành',
                'text' => 'Khung giờ ưu tiên tiếp nhận là 08:00 - 22:00 mỗi ngày. Các yêu cầu có đủ mã đơn và mô tả tình trạng sẽ được xử lý nhanh hơn.',
                'bullets' => [
                    'Hotline: 1900 0000',
                    'Kênh nhanh: chat hỗ trợ trên website',
                    'Đối chiếu chính sách chung: đổi trả, bảo mật, điều kiện giao dịch',
                ],
            ],
            'actions' => [
                ['label' => 'Mở chat hỗ trợ', 'url' => BASE_URL . '/index.php?r=lichsuchat'],
                ['label' => 'Xem điều kiện giao dịch', 'url' => BASE_URL . '/index.php?r=dieu_kien_giao_dich'],
                ['label' => 'Chính sách bảo mật', 'url' => BASE_URL . '/index.php?r=chinh_sach_bao_mat'],
            ],
        ]);
    }

    public function customerSupport(): void {
        $this->render('info/service-hub', [
            'title' => 'Trung tâm hỗ trợ khách hàng',
            'eyebrow' => 'Customer care',
            'summary' => 'Trang này tổng hợp các kênh hỗ trợ, câu hỏi thường gặp và những đường dẫn quan trọng để bạn xử lý nhanh các tình huống trước, trong và sau khi mua sắm.',
            'sections' => [
                [
                    'title' => 'Kênh hỗ trợ chính',
                    'items' => [
                        'Hotline 1900 0000 để xử lý các trường hợp cần phản hồi ngay.',
                        'Chat hỗ trợ trên website để theo dõi lịch sử trao đổi và cập nhật trạng thái xử lý.',
                        'AI chat để hỏi nhanh về routine, thành phần, xung đột hoạt chất và gợi ý sản phẩm.',
                    ],
                ],
                [
                    'title' => 'Nhóm vấn đề thường gặp',
                    'items' => [
                        'Đăng ký tài khoản, xác thực OTP, quên mật khẩu và cập nhật hồ sơ cá nhân.',
                        'Đặt hàng, áp voucher, áp điểm, chọn địa chỉ nhận hàng và thanh toán.',
                        'Theo dõi đơn, hủy đơn hợp lệ, đánh giá sản phẩm và hỗ trợ sau mua.',
                    ],
                ],
                [
                    'title' => 'Điểm đến nhanh',
                    'items' => [
                        'Hướng dẫn nhận OTP nếu bạn chưa lấy được mã xác thực.',
                        'Khảo sát da và gợi ý routine nếu bạn muốn cá nhân hóa trải nghiệm mua sắm.',
                        'Các chính sách điều kiện giao dịch, bảo mật và dữ liệu cá nhân khi cần tra cứu.',
                    ],
                ],
            ],
            'supportCard' => [
                'title' => 'Hỗ trợ theo ngữ cảnh',
                'text' => 'SkinSyntax ưu tiên gom trải nghiệm hỗ trợ ngay trong website để bạn không phải chuyển qua nhiều kênh riêng lẻ.',
                'bullets' => [
                    'Tra cứu sản phẩm ngay trong header',
                    'Mở AI chat ở góc màn hình để hỏi nhanh',
                    'Khi đăng nhập, bạn có thể lưu lịch sử chat và đơn hàng trong cùng một tài khoản',
                ],
            ],
            'actions' => [
                ['label' => 'Xem hướng dẫn OTP', 'url' => BASE_URL . '/index.php?r=huong_dan_nhan_otp'],
                ['label' => 'Mở routine AI', 'url' => BASE_URL . '/index.php?r=goiy'],
                ['label' => 'Chính sách dữ liệu', 'url' => BASE_URL . '/index.php?r=chinh_sach_xu_ly_du_lieu'],
            ],
        ]);
    }

    private function renderPolicyReference(array $data): void {
        $this->render('info/policy-reference', $data);
    }

    private function getHighlightedCategories(): array {
        // Lấy top 6 danh mục có nhiều sản phẩm nhất
        // Bảng hiện tại là "san_pham" (theo schema_new.sql),
        // đồng thời cột "danh_muc_day_du" nằm trong bảng này (dữ liệu import từ hasaki).
        $sql = "SELECT danh_muc_day_du, COUNT(*)::int AS so_luong
                FROM san_pham
                WHERE danh_muc_day_du IS NOT NULL AND danh_muc_day_du <> ''
                GROUP BY danh_muc_day_du
                ORDER BY so_luong DESC
                LIMIT 6";
        return $this->pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
    }

    public function giohang() {
        // Xử lý POST requests
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
        
        // Hiển thị giỏ hàng
        $items = [];
        if (!empty($_SESSION['gio_hang'])) {
            foreach ($_SESSION['gio_hang'] as $product_id => $qty) {
                $product = $this->model->findById($product_id);
                if ($product) {
                    $items[$product_id] = [
                        'product' => $product,
                        'qty' => $qty
                    ];
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
            return $normalized !== 'không có / không quan tâm' && $normalized !== 'khong co';
        }));

        $budget = isset($khachHang['ngan_sach']) && $khachHang['ngan_sach'] !== null
            ? (int)$khachHang['ngan_sach']
            : null;

        return [
            'display_name' => trim((string)($khachHang['ho_ten'] ?? 'bạn')),
            'gioi_tinh' => trim((string)($khachHang['gioi_tinh'] ?? '')),
            'nam_sinh' => trim((string)($khachHang['nam_sinh'] ?? '')),
            'skin_type' => trim((string)($skinProfile['loai_da'] ?? '')),
            'concerns' => $concerns,
            'avoid_ingredients' => $avoidIngredients,
            'budget' => $budget,
            'budget_label' => $budget !== null && $budget > 0
                ? number_format($budget, 0, ',', '.') . ' VND'
                : 'Không giới hạn',
            'sensitivity' => trim((string)($khachHang['muc_do_nhay_cam'] ?? '')),
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
                set_flash('error', 'Điểm tích lũy không còn đủ để áp dụng cho đơn hàng hiện tại.');
            }
        } elseif ($requestedPoints > 0 && $usablePoints !== $requestedPoints) {
            $_SESSION['checkout_points'] = ['points' => $usablePoints];
            if ($flashWhenInvalid) {
                set_flash('error', 'Điểm áp dụng đã được điều chỉnh theo số dư hiện có hoặc giá trị đơn hàng.');
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
            return 'Chuyển khoản qua QR';
        }

        return 'Thanh toán khi nhận hàng (COD)';
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
            $address .= ' | Ghi chú: ' . $note;
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
                    'message' => 'Địa chỉ mặc định chưa đủ thông tin. Vui lòng chọn địa chỉ mới để tiếp tục.',
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
                'message' => 'Vui lòng điền đầy đủ địa chỉ mới, bao gồm số nhà, phường xã, quận huyện và tỉnh thành.',
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

    private function getAiChatEndpoint(): string {
        $configured = defined('AI_CHAT_ENDPOINT') ? (string)AI_CHAT_ENDPOINT : '';
        if (trim($configured) !== '') {
            return trim($configured);
        }

        $envValue = getenv('AI_CHAT_ENDPOINT');
        if ($envValue !== false && trim((string)$envValue) !== '') {
            return trim((string)$envValue);
        }

        return 'http://127.0.0.1:5000/api/chat';
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

        if (preg_match('/aha.*bha|bha.*aha|aha va bha|aha với bha/u', $normalized)) {
            return implode("\n", [
                'AHA và BHA khác nhau ở vùng tác động chính:',
                '- AHA thiên về bề mặt da, hỗ trợ da xỉn màu, sần và bề mặt không đều.',
                '- BHA đi sâu vào lỗ chân lông hơn, hợp da dầu, bí tắc và mụn đầu đen.',
                '- Nếu mới dùng acid, không cần chồng cả hai ngay từ đầu. Hãy chọn loại sát vấn đề da hơn.'
            ]);
        }

        if (preg_match('/kem chong nang|kem chống nắng|sunscreen/u', $normalized)) {
            return implode("\n", [
                'Kem chống nắng là bước bảo vệ bắt buộc vào ban ngày:',
                '- Bôi ở cuối routine sáng.',
                '- Nếu có treatment như retinol, AHA/BHA hoặc vitamin C, chống nắng càng quan trọng hơn.',
                '- Thoa đủ lượng và dặm lại khi ở ngoài nắng lâu mới hiệu quả thực tế.'
            ]);
        }

        if (preg_match('/tay trang|tẩy trang|double cleansing|lam sach kep|làm sạch kép/u', $normalized)) {
            return implode("\n", [
                'Làm sạch kép phù hợp khi bạn có chống nắng đậm, makeup hoặc da dầu dễ bí:',
                '- Bước 1 là tẩy trang để hòa tan lớp chống nắng, dầu và bụi bẩn bám chặt.',
                '- Bước 2 là sữa rửa mặt để làm sạch lại nền da.',
                '- Nếu da rất khô hoặc ít makeup, không phải lúc nào cũng cần làm sạch kép quá mạnh.'
            ]);
        }

        if (preg_match('/da nhay cam|da nhạy cảm|sensitive skin/u', $normalized)) {
            return implode("\n", [
                'Da nhạy cảm nên đi theo hướng ít bước nhưng ổn định:',
                '- Ưu tiên làm sạch dịu, dưỡng phục hồi và chống nắng đều.',
                '- Khi thêm treatment, chỉ nên thêm từng món một để dễ theo dõi phản ứng.',
                '- Các nhóm như B5, HA, ceramide, niacinamide nồng độ vừa thường dễ bắt đầu hơn treatment mạnh.'
            ]);
        }

        if (preg_match('/mụn ẩn|mun an|mụn đầu đen|mun dau den/u', $normalized)) {
            return implode("\n", [
                'Với bí tắc, mụn ẩn hoặc mụn đầu đen, hướng xử lý thường là làm sạch vừa đủ và giảm tắc nghẽn:',
                '- BHA là lựa chọn hay gặp vì thiên về lỗ chân lông.',
                '- Không cần dùng quá nhiều treatment cùng lúc vì da dễ kích ứng rồi mụn kéo dài hơn.',
                '- Hãy đi kèm dưỡng phục hồi và chống nắng để da chịu treatment tốt hơn.'
            ]);
        }

        if (preg_match('/routine sáng|routine sang|buổi sáng|buoi sang/u', $normalized)) {
            return implode("\n", [
                'Routine sáng cơ bản nên đi theo thứ tự nhẹ và bảo vệ:',
                '- Sữa rửa mặt dịu nhẹ.',
                '- Serum mục tiêu nếu cần, ví dụ vitamin C hoặc niacinamide.',
                '- Kem dưỡng nếu da cần thêm ẩm.',
                '- Kem chống nắng là bước bắt buộc.'
            ]);
        }

        if (preg_match('/routine tối|routine toi|buổi tối|buoi toi/u', $normalized)) {
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

    private function buildAiAssistantPrompt(string $message, array $history, array $profile, array $cartItems, array $conflicts, array $products): string {
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
            'skin_type' => (string)($profile['skin_type'] ?? ''),
            'concerns' => array_values($profile['concerns'] ?? []),
            'avoid_ingredients' => array_values($profile['avoid_ingredients'] ?? []),
            'budget' => (int)($profile['budget'] ?? 0),
        ];

        $payload = [
            'system_role' => 'Ban la AI Agent cua SkinSyntax. Nhiem vu: phan tich thanh phan, truy xuat du lieu that tu cua hang, phat hien conflict trong gio hang, va tra loi bang tieng Viet ro rang. Chi duoc dua vao du lieu context ben duoi; neu thieu du lieu hay noi ro la can kiem tra them. Tuyet doi khong bịa thông tin.',
            'customer_question' => $message,
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

    private function buildAiAssistantFallback(string $message, array $conflicts, array $products): string {
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
                    $lines[] = '  Goi y: ' . $conflict['recommendation'];
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

        $cachedPayload = $this->getCachedAiResponsePayload($message);
        if ($cachedPayload !== null) {
            echo json_encode($cachedPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            return;
        }

        $commonAnswer = $this->buildAiCommonKnowledgeResponse($message, $profile);
        if ($commonAnswer !== null) {
            $payload = [
                'ok' => true,
                'answer' => $commonAnswer,
                'conflicts' => [],
                'products' => [],
                'fallback' => false,
                'status_message' => '',
                'fallback_note' => '',
            ];
            $this->storeAiResponsePayload($message, $payload);
            echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            return;
        }

        $cartItems = $this->buildAiCartContext();
        $conflicts = $this->detectCartIngredientConflicts($cartItems);
        $products = $this->shouldAttachAiProducts($message, $conflicts)
            ? $this->buildAiRelevantProducts($message, 4)
            : [];

        $endpoint = $this->getAiChatEndpoint();

        $payload = [
            'message' => $this->buildAiAssistantPrompt($message, $history, $profile, $cartItems, $conflicts, $products),
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
        } else {
            $decoded = json_decode((string)($response['body'] ?? ''), true);
        }

        if ($answer === '') {
            $usedFallback = true;
            $fallbackMeta = $this->resolveAiFallbackMeta($response, is_array($decoded) ? $decoded : null);
            $answer = $this->buildAiAssistantFallback($message, $conflicts, $products);
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
            $this->storeAiResponsePayload($message, $payload);
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
        $reasons = array_values(array_filter(array_map('trim', $product['reasons'] ?? [])));
        $concerns = array_values(array_filter(array_map('trim', $product['matched_concerns'] ?? [])));
        $keyIngredients = array_values(array_filter(array_map('trim', $product['key_ingredients'] ?? [])));
        $avoidHits = array_values(array_filter(array_map('trim', $product['avoid_ingredient_hits'] ?? [])));

        $parts = [];
        if (!empty($profile['skin_type'])) {
            $parts[] = 'Sản phẩm này phù hợp với nền da ' . trim((string)$profile['skin_type']) . ' của bạn';
        } else {
            $parts[] = 'Sản phẩm này phù hợp với hồ sơ chăm sóc da bạn đã cung cấp';
        }

        if (!empty($concerns)) {
            $parts[] = 'và ưu tiên xử lý các vấn đề như ' . implode(', ', array_slice($concerns, 0, 3));
        }

        $sentence = implode(' ', $parts) . '.';

        if (!empty($keyIngredients)) {
            $sentence .= ' Thành phần nổi bật gồm ' . implode(', ', array_slice($keyIngredients, 0, 3)) . '.';
        } elseif (!empty($reasons)) {
            $sentence .= ' ' . implode(' ', array_slice($reasons, 0, 2));
        }

        if (!empty($avoidHits)) {
            $sentence .= ' Lưu ý sản phẩm có chứa thành phần bạn muốn tránh: ' . implode(', ', array_slice($avoidHits, 0, 3)) . '.';
        }

        return trim($sentence);
    }

    private function fetchAiRecommendationExplanations(array $profile, array $products): array {
        if (empty($products)) {
            return [
                'ok' => true,
                'items' => [],
                'message' => 'Không có sản phẩm để giải thích.',
            ];
        }

        $payload = [
            'user_profile' => [
                'gioi_tinh' => (string)($profile['gioi_tinh'] ?? ''),
                'nam_sinh' => (string)($profile['nam_sinh'] ?? ''),
                'skin_type' => (string)($profile['skin_type'] ?? ''),
                'concerns' => array_values($profile['concerns'] ?? []),
                'avoid_ingredients' => array_values($profile['avoid_ingredients'] ?? []),
                'budget' => (int)($profile['budget'] ?? 0),
                'sensitivity' => (string)($profile['sensitivity'] ?? ''),
            ],
            'products' => array_map(function (array $item): array {
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
                'message' => 'AI recommendation endpoint chưa được cấu hình.',
            ];
        }

        $response = $this->postJsonRequest($endpoint, $payload, $this->getAiRecommendationTimeout());
        if ((int)($response['status'] ?? 0) < 200 || (int)($response['status'] ?? 0) >= 300) {
            return [
                'ok' => false,
                'items' => [],
                'message' => 'Không gọi được AI recommendation service.',
                'debug_status' => (int)($response['status'] ?? 0),
                'debug_error' => (string)($response['error'] ?? ''),
            ];
        }

        $decoded = json_decode((string)($response['body'] ?? ''), true);
        if (!is_array($decoded)) {
            return [
                'ok' => false,
                'items' => [],
                'message' => 'Phản hồi AI recommendation service không hợp lệ.',
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
            return 'SePay từ chối xác thực API. Hãy kiểm tra lại API token hoặc quyền User API trên tài khoản SePay.';
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
            return 'Máy chủ hiện không thiết lập được kết nối bảo mật TLS tới SePay. Nếu bạn đang chạy localhost/XAMPP, hãy ưu tiên webhook trên môi trường public hoặc kiểm tra tường lửa/chứng chỉ outbound.';
        }

        if ($status === 429) {
            return 'SePay đang giới hạn tần suất gọi API. Hãy đợi vài giây rồi thử lại.';
        }

        return 'Không gọi được SePay API.';
    }

    private function fetchSePayTransactionsForOrder(array $order): array {
        if (!$this->isSePayPollingEnabled()) {
            return [
                'ok' => false,
                'message' => 'SePay polling chưa được bật hoặc thiếu cấu hình.',
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
                'message' => 'Phản hồi SePay API không hợp lệ.',
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
            'message' => 'Đã lấy giao dịch từ SePay.',
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
            set_flash('error', 'Vui lòng chọn sản phẩm để thanh toán.');
            redirect(BASE_URL . '/index.php?r=giohang');
        }

        $_SESSION['checkout_items'] = $checkoutItems;
        unset($_SESSION['checkout_voucher'], $_SESSION['checkout_points'], $_SESSION['checkout_address_choice'], $_SESSION['checkout_new_receiver']);
        $_SESSION['checkout_payment_method'] = 'cod';
        redirect(BASE_URL . '/index.php?r=thanhtoan');
    }

    public function thanhtoan() {
        if (!is_logged_in()) {
            set_flash('error', 'Vui lòng đăng nhập để thanh toán.');
            redirect(BASE_URL . '/index.php?r=dangnhap');
        }

        $checkoutItems = $_SESSION['checkout_items'] ?? [];
        if (empty($checkoutItems) || !is_array($checkoutItems)) {
            set_flash('error', 'Không có sản phẩm để thanh toán.');
            redirect(BASE_URL . '/index.php?r=giohang');
        }

        $checkoutPreview = $this->buildCheckoutPreview($checkoutItems);
        $items = $checkoutPreview['items'];
        $subtotal = (int)($checkoutPreview['subtotal'] ?? 0);

        if (empty($items)) {
            unset($_SESSION['checkout_items']);
            set_flash('error', 'Sản phẩm trong danh sách thanh toán không còn tồn tại.');
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
            set_flash('error', 'Vui lòng đăng nhập để áp dụng mã giảm giá.');
            redirect(BASE_URL . '/index.php?r=dangnhap');
        }

        $this->storeCheckoutPaymentMethodFromRequest();
        $this->storeCheckoutReceiverFromRequest($this->getDefaultCheckoutReceiver($this->getCurrentCheckoutCustomer(), current_user() ?? []));

        $checkoutItems = $_SESSION['checkout_items'] ?? [];
        if (empty($checkoutItems) || !is_array($checkoutItems)) {
            set_flash('error', 'Không có sản phẩm để áp dụng mã giảm giá.');
            redirect(BASE_URL . '/index.php?r=giohang');
        }

        $preview = $this->buildCheckoutPreview($checkoutItems);
        if (empty($preview['items'])) {
            unset($_SESSION['checkout_items'], $_SESSION['checkout_voucher']);
            set_flash('error', 'Sản phẩm trong danh sách thanh toán không còn tồn tại.');
            redirect(BASE_URL . '/index.php?r=giohang');
        }

        $voucherCode = trim((string)($_POST['voucher_code'] ?? ''));
        $result = $this->voucherModel->validateForCheckout($voucherCode, (int)($preview['subtotal'] ?? 0));
        if (empty($result['ok'])) {
            unset($_SESSION['checkout_voucher']);
            set_flash('error', (string)($result['message'] ?? 'Không thể áp dụng mã giảm giá.'));
            redirect(BASE_URL . '/index.php?r=thanhtoan');
        }

        $_SESSION['checkout_voucher'] = [
            'code' => (string)($result['voucher']['ma_code'] ?? ''),
        ];
        set_flash('success', (string)($result['message'] ?? 'Áp dụng mã giảm giá thành công.'));
        redirect(BASE_URL . '/index.php?r=thanhtoan');
    }

    public function boVoucher(): void {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            redirect(BASE_URL . '/index.php?r=thanhtoan');
        }

        $this->storeCheckoutPaymentMethodFromRequest();
        $this->storeCheckoutReceiverFromRequest($this->getDefaultCheckoutReceiver($this->getCurrentCheckoutCustomer(), current_user() ?? []));
        unset($_SESSION['checkout_voucher']);
        set_flash('success', 'Đã gỡ mã giảm giá khỏi đơn hàng.');
        redirect(BASE_URL . '/index.php?r=thanhtoan');
    }

    public function apDungDiem(): void {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            redirect(BASE_URL . '/index.php?r=thanhtoan');
        }

        if (!is_logged_in()) {
            set_flash('error', 'Vui lòng đăng nhập để dùng điểm tích lũy.');
            redirect(BASE_URL . '/index.php?r=dangnhap');
        }

        $this->storeCheckoutPaymentMethodFromRequest();
        $this->storeCheckoutReceiverFromRequest($this->getDefaultCheckoutReceiver($this->getCurrentCheckoutCustomer(), current_user() ?? []));
        $checkoutItems = $_SESSION['checkout_items'] ?? [];
        if (empty($checkoutItems) || !is_array($checkoutItems)) {
            set_flash('error', 'Không có sản phẩm để áp dụng điểm.');
            redirect(BASE_URL . '/index.php?r=giohang');
        }

        $preview = $this->buildCheckoutPreview($checkoutItems);
        if (empty($preview['items'])) {
            unset($_SESSION['checkout_items'], $_SESSION['checkout_voucher'], $_SESSION['checkout_points']);
            set_flash('error', 'Sản phẩm trong danh sách thanh toán không còn tồn tại.');
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
            set_flash('error', 'Tài khoản của bạn hiện chưa có điểm tích lũy để sử dụng.');
            redirect(BASE_URL . '/index.php?r=thanhtoan');
        }

        if ($requestedPoints <= 0) {
            unset($_SESSION['checkout_points']);
            set_flash('error', 'Vui lòng nhập số điểm hợp lệ để áp dụng.');
            redirect(BASE_URL . '/index.php?r=thanhtoan');
        }

        $usablePoints = min($requestedPoints, $availablePoints, $maxPointsByAmount);
        if ($usablePoints <= 0) {
            unset($_SESSION['checkout_points']);
            set_flash('error', 'Giá trị đơn hàng hiện tại chưa đủ để quy đổi điểm thành giảm giá.');
            redirect(BASE_URL . '/index.php?r=thanhtoan');
        }

        $_SESSION['checkout_points'] = ['points' => $usablePoints];
        set_flash('success', 'Đã áp dụng ' . number_format($usablePoints, 0, ',', '.') . ' điểm cho đơn hàng.');
        redirect(BASE_URL . '/index.php?r=thanhtoan');
    }

    public function boDiem(): void {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            redirect(BASE_URL . '/index.php?r=thanhtoan');
        }

        $this->storeCheckoutPaymentMethodFromRequest();
        $this->storeCheckoutReceiverFromRequest($this->getDefaultCheckoutReceiver($this->getCurrentCheckoutCustomer(), current_user() ?? []));
        unset($_SESSION['checkout_points']);
        set_flash('success', 'Đã gỡ điểm tích lũy khỏi đơn hàng.');
        redirect(BASE_URL . '/index.php?r=thanhtoan');
    }

    public function xulydathang() {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            redirect(BASE_URL . '/index.php?r=giohang');
        }

        if (!is_logged_in()) {
            set_flash('error', 'Vui lòng đăng nhập để đặt hàng.');
            redirect(BASE_URL . '/index.php?r=dangnhap');
        }

        $checkoutItems = $_SESSION['checkout_items'] ?? [];
        if (empty($checkoutItems) || !is_array($checkoutItems)) {
            set_flash('error', 'Không có sản phẩm để đặt hàng.');
            redirect(BASE_URL . '/index.php?r=giohang');
        }

        $checkoutPreview = $this->buildCheckoutPreview($checkoutItems);
        if (empty($checkoutPreview['items'])) {
            unset($_SESSION['checkout_items'], $_SESSION['checkout_voucher']);
            set_flash('error', 'Sản phẩm trong danh sách thanh toán không còn tồn tại.');
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
            set_flash('error', 'Vui lòng điền đầy đủ thông tin nhận hàng.');
            redirect(BASE_URL . '/index.php?r=thanhtoan');
        }

        $allowedMethods = ['cod'];
        if ($this->isQrTransferEnabled()) {
            $allowedMethods[] = 'bank_transfer_qr';
        }
        if (!in_array($hinhThucThanhToan, $allowedMethods, true)) {
            set_flash('error', 'Phương thức thanh toán không hợp lệ.');
            redirect(BASE_URL . '/index.php?r=thanhtoan');
        }

    $_SESSION['checkout_payment_method'] = $hinhThucThanhToan;
        $email = (string)($user['email'] ?? '');

        $appliedVoucher = $this->getAppliedVoucher($subtotal, false);
        $voucherDiscountAmount = 0;
        if (trim((string)(($_SESSION['checkout_voucher']['code'] ?? ''))) !== '' && $appliedVoucher === null) {
            set_flash('error', 'Mã giảm giá không còn hợp lệ. Vui lòng kiểm tra lại đơn hàng.');
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
                set_flash('success', 'Đơn hàng đã được tạo. Vui lòng quét QR và chuyển khoản theo đúng nội dung để hoàn tất thanh toán.');
            } else {
                set_flash('success', 'Đặt hàng thành công. Cảm ơn bạn đã mua sắm tại SkinSyntax.');
            }
            redirect(BASE_URL . '/index.php?r=camon&ma_hoa_don=' . urlencode((string)$maHoaDon));
        } catch (Throwable $e) {
            error_log('xulydathang error: ' . $e->getMessage());
            set_flash('error', 'Không thể đặt hàng lúc này. Vui lòng thử lại.');
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
                'message' => 'SePay API polling chưa được cấu hình.',
            ], 200);
        }

        $orderId = max(0, (int)($_GET['order_id'] ?? 0));
        if ($orderId <= 0) {
            $this->jsonResponse([
                'ok' => false,
                'message' => 'Mã đơn hàng không hợp lệ.',
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
                'message' => 'Không tìm thấy đơn hàng.',
            ], 404);
        }

        $paymentMethod = strtolower(trim((string)($order['hinh_thuc_thanh_toan'] ?? 'cod')));
        $paymentStatus = trim((string)($order['status_thanh_toan'] ?? 'Chua thanh toan'));
        if ($paymentMethod !== 'bank_transfer_qr') {
            $this->jsonResponse([
                'ok' => true,
                'paid' => strtolower($paymentStatus) === 'da thanh toan',
                'payment_status' => $paymentStatus,
                'message' => 'Đơn hàng này không dùng chuyển khoản QR.',
                'order_id' => $orderId,
            ]);
        }

        if (strtolower($paymentStatus) === 'da thanh toan') {
            $this->jsonResponse([
                'ok' => true,
                'paid' => true,
                'payment_status' => $paymentStatus,
                'message' => 'Đơn hàng đã được thanh toán.',
                'order_id' => $orderId,
            ]);
        }

        $transactionsResult = $this->fetchSePayTransactionsForOrder($order);
        if (empty($transactionsResult['ok'])) {
            $this->jsonResponse([
                'ok' => false,
                'paid' => false,
                'payment_status' => $paymentStatus,
                'message' => (string)($transactionsResult['message'] ?? 'Không thể kiểm tra giao dịch SePay.'),
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
                    'message' => 'Đã nhận giao dịch chuyển khoản và cập nhật đơn hàng.',
                    'order_id' => $orderId,
                    'matched_transaction' => $matched,
                ]);
            }
        }

        $this->jsonResponse([
            'ok' => true,
            'paid' => false,
            'payment_status' => $paymentStatus,
            'message' => 'Chưa tìm thấy giao dịch phù hợp. Hệ thống sẽ tiếp tục kiểm tra.',
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

        if ($gioiTinh === '') {
            http_response_code(422);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['ok' => false, 'message' => 'Vui lòng chọn giới tính (Câu 1).'], JSON_UNESCAPED_UNICODE);
            exit;
        }

        if ($namSinhRaw === '' || !ctype_digit($namSinhRaw)) {
            http_response_code(422);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['ok' => false, 'message' => 'Vui lòng nhập năm sinh hợp lệ.'], JSON_UNESCAPED_UNICODE);
            exit;
        }

        $year = (int)$namSinhRaw;
        $currentYear = (int)date('Y');
        if ($year < 1900 || $year > $currentYear) {
            http_response_code(422);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['ok' => false, 'message' => 'Năm sinh không hợp lệ.'], JSON_UNESCAPED_UNICODE);
            exit;
        }

        try {
            $profileForRecommendation = [
                'gioi_tinh' => $gioiTinh,
                'nam_sinh' => $year,
                'skin_type' => trim((string)($_POST['skin_type'] ?? '')),
                'concerns' => array_values(is_array($_POST['concerns'] ?? null) ? $_POST['concerns'] : []),
                'avoid_ingredients' => array_values(array_filter(array_map('trim', preg_split('/[,;\n\r]+/u', (string)($_POST['avoid_ingredients'] ?? '')) ?: []))),
                'budget' => (int)preg_replace('/[^\d]/', '', (string)($_POST['budget'] ?? '0')),
                'sensitivity' => trim((string)($_POST['sensitivity'] ?? '')),
            ];

            $results = $this->goiYModel->recommendFromPost($_POST, 12);
            foreach ($results as &$item) {
                $item['image_url'] = resolve_image_url((string)($item['link_hinh_anh'] ?? ''));
            }
            unset($item);

            $aiResult = $this->fetchAiRecommendationExplanations($profileForRecommendation, $results);
            foreach ($results as &$item) {
                $productId = trim((string)($item['id'] ?? ''));
                $aiExplanation = $productId !== '' ? trim((string)($aiResult['items'][$productId]['llm_explanation'] ?? '')) : '';
                if ($aiExplanation === '') {
                    $aiExplanation = $this->buildRecommendationFallbackExplanation($profileForRecommendation, $item);
                    $item['explanation_source'] = 'fallback';
                } else {
                    $item['explanation_source'] = trim((string)($aiResult['items'][$productId]['source'] ?? 'llm')) ?: 'llm';
                }

                $item['llm_explanation'] = $aiExplanation;
            }
            unset($item);
        } catch (Throwable $e) {
            error_log('xulygoiy error: ' . $e->getMessage());
            http_response_code(500);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode([
                'ok' => false,
                'message' => 'Hệ gợi ý đang gặp lỗi khi xử lý dữ liệu sản phẩm. Vui lòng thử lại sau.',
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }

        if (function_exists('is_logged_in') && is_logged_in()) {
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
        echo json_encode([
            'ok' => true,
            'count' => count($results),
            'data' => $results,
            'ai' => [
                'enabled' => !empty($aiResult['ok']),
                'message' => (string)($aiResult['message'] ?? ''),
            ],
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
}
