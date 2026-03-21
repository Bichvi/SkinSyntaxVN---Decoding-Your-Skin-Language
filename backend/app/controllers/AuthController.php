<?php
// backend/app/controllers/AuthController.php

require_once __DIR__ . "/../models/NguoiDung.php";

class AuthController {
    private PDO $pdo;
    private NguoiDung $model;

    public function __construct(PDO $pdo) {
        $this->pdo = $pdo;
        $this->model = new NguoiDung($pdo);
    }

    private function render(string $view, array $data = []) {
        extract($data);
        require __DIR__ . '/../views/layouts/header.php';
        require __DIR__ . '/../views/' . $view . '.php';
        require __DIR__ . '/../views/layouts/footer.php';
    }

    private function appBaseUrl(): string {
        $isHttps = !empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off';
        $scheme = $isHttps ? 'https' : 'http';
        $host = (string)($_SERVER['HTTP_HOST'] ?? 'localhost');
        return $scheme . '://' . $host . rtrim((string)BASE_URL, '/');
    }

    private function buildRouteUrl(string $route, array $params = []): string {
        $query = http_build_query(array_merge(['r' => $route], $params));
        return $this->appBaseUrl() . '/index.php?' . $query;
    }

    private function sendHtmlEmail(string $to, string $subject, string $html): bool {
        $fromName = defined('MAIL_FROM_NAME') ? MAIL_FROM_NAME : 'SkinSyntax';
        $fromAddress = defined('MAIL_FROM_ADDRESS') ? MAIL_FROM_ADDRESS : 'no-reply@skinsyntax.local';
        $encodedSubject = '=?UTF-8?B?' . base64_encode($subject) . '?=';
        $headers = [
            'MIME-Version: 1.0',
            'Content-type: text/html; charset=UTF-8',
            'From: ' . $fromName . ' <' . $fromAddress . '>',
            'Reply-To: ' . $fromAddress,
            'X-Mailer: PHP/' . phpversion(),
        ];

        if (DIRECTORY_SEPARATOR === "\\") {
            $sendmailPath = 'D:\\xampp\\sendmail\\sendmail.exe';
            if (is_file($sendmailPath)) {
                $message = implode("\r\n", [
                    'To: ' . $to,
                    'From: ' . $fromName . ' <' . $fromAddress . '>',
                    'Subject: ' . $encodedSubject,
                    ...$headers,
                    '',
                    $html,
                    '',
                ]);

                $tmpFile = tempnam(sys_get_temp_dir(), 'skinsyntax-mail-');
                if ($tmpFile !== false) {
                    file_put_contents($tmpFile, $message);
                    $command = 'cmd /C ""' . $sendmailPath . '" -t < "' . $tmpFile . '""';
                    $output = [];
                    $exitCode = 1;
                    @exec($command, $output, $exitCode);
                    @unlink($tmpFile);

                    if ($exitCode === 0) {
                        return true;
                    }

                    error_log('SkinSyntax sendmail.exe failed. Exit code: ' . $exitCode . ' | output: ' . trim(implode(' ', $output)));
                }
            }
        }

        return @mail($to, $encodedSubject, $html, implode("\r\n", $headers));
    }

    private function httpRequest(string $url, array $options = []): array {
        $method = strtoupper((string)($options['method'] ?? 'GET'));
        $headers = $options['headers'] ?? [];
        $body = $options['body'] ?? null;

        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 20);
            if (!empty($headers)) {
                curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
            }
            if ($body !== null) {
                curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
            }
            $responseBody = curl_exec($ch);
            $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            return ['status' => $status, 'body' => (string)$responseBody];
        }

        $context = stream_context_create([
            'http' => [
                'method' => $method,
                'header' => implode("\r\n", $headers),
                'content' => $body ?? '',
                'ignore_errors' => true,
                'timeout' => 20,
            ],
        ]);
        $responseBody = @file_get_contents($url, false, $context);
        $status = 0;
        foreach (($http_response_header ?? []) as $headerLine) {
            if (preg_match('#HTTP/\S+\s+(\d{3})#', $headerLine, $matches)) {
                $status = (int)$matches[1];
                break;
            }
        }
        return ['status' => $status, 'body' => (string)$responseBody];
    }

    private function getOAuthConfig(string $provider): ?array {
        $provider = strtolower(trim($provider));
        if ($provider === 'google') {
            return [
                'provider' => 'google',
                'client_id' => defined('GOOGLE_OAUTH_CLIENT_ID') ? GOOGLE_OAUTH_CLIENT_ID : '',
                'client_secret' => defined('GOOGLE_OAUTH_CLIENT_SECRET') ? GOOGLE_OAUTH_CLIENT_SECRET : '',
                'auth_url' => 'https://accounts.google.com/o/oauth2/v2/auth',
                'token_url' => 'https://oauth2.googleapis.com/token',
                'scope' => 'openid email profile',
            ];
        }

        if ($provider === 'facebook') {
            return [
                'provider' => 'facebook',
                'client_id' => defined('FACEBOOK_OAUTH_CLIENT_ID') ? FACEBOOK_OAUTH_CLIENT_ID : '',
                'client_secret' => defined('FACEBOOK_OAUTH_CLIENT_SECRET') ? FACEBOOK_OAUTH_CLIENT_SECRET : '',
                'auth_url' => 'https://www.facebook.com/v19.0/dialog/oauth',
                'token_url' => 'https://graph.facebook.com/v19.0/oauth/access_token',
                'scope' => 'email,public_profile',
            ];
        }

        return null;
    }

    private function socialEnabled(string $provider): bool {
        $config = $this->getOAuthConfig($provider);
        return $config !== null && trim((string)($config['client_id'] ?? '')) !== '' && trim((string)($config['client_secret'] ?? '')) !== '';
    }

    private function loginCustomerSession(array $user): void {
        $_SESSION['user'] = [
            'id' => $user['id'],
            'ho_ten' => $user['ho_ten'],
            'email' => $user['email'],
            'role' => 'khach_hang',
            'vai_tro' => 'khach_hang',
        ];
    }

    public function dangnhap() {
        header('Location: ' . BASE_URL . '/index.php?auth=login');
        exit;
    }

    public function xulydangnhap() {
        $email = trim($_POST['email'] ?? '');
        $matkhau = $_POST['mat_khau'] ?? '';

        $staff = $this->model->timNhanVienTheoEmail($email);
        if ($staff && !empty($staff['mat_khau']) && password_verify($matkhau, (string)$staff['mat_khau'])) {
            $staffStatus = strtolower(trim((string)($staff['trang_thai'] ?? 'active')));
            $isDeleted = !empty($staff['deleted_at'] ?? null);
            if ($isDeleted || in_array($staffStatus, ['inactive', 'deleted', 'locked', 'disabled', 'tam_khoa'], true)) {
                set_flash('error', 'Tài khoản nhân viên hiện đang bị tạm khóa hoặc ngừng hoạt động.');
                header('Location: ' . BASE_URL . '/index.php?auth=login');
                exit;
            }

            $roleName = strtolower(trim((string)($staff['ten_vai_tro'] ?? 'nhanvien')));
            if ($roleName === 'nhanvien') {
                $roleName = 'nhanvien';
            } elseif ($roleName === 'admin') {
                $roleName = 'admin';
            }

            $_SESSION['user'] = [
                'id' => 'staff-' . (int)$staff['ma_nv'],
                'ma_nv' => (int)$staff['ma_nv'],
                'ho_ten' => $staff['ho_ten'],
                'email' => $staff['email'],
                'role' => $roleName,
                'vai_tro' => $roleName,
            ];

            set_flash('success', 'Đăng nhập thành công.');
            $target = $roleName === 'admin' ? 'admin_dashboard' : 'staff_dashboard';
            header("Location: " . BASE_URL . "/index.php?r={$target}");
            exit;
        }

        if ($staff) {
            set_flash('error', 'Email hoặc mật khẩu không đúng.');
            header('Location: ' . BASE_URL . '/index.php?auth=login');
            exit;
        }

        $u = $this->model->timTheoEmail($email);
        if ($u && password_verify($matkhau, (string)$u['mat_khau'])) {
            $this->loginCustomerSession($u);

            set_flash('success', 'Đăng nhập thành công.');
            header("Location: " . BASE_URL . "/index.php?r=home");
            exit;
        }

        set_flash('error', 'Email hoặc mật khẩu không đúng.');
        header('Location: ' . BASE_URL . '/index.php?auth=login');
        exit;
    }

    public function quenMatKhau(): void {
        header('Location: ' . BASE_URL . '/index.php?auth=forgot');
        exit;
    }

    public function guiLienKetDatLai(): void {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            header('Location: ' . BASE_URL . '/index.php?r=quen_mat_khau');
            exit;
        }

        $email = trim((string)($_POST['email'] ?? ''));
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            set_flash('error', 'Vui lòng nhập email hợp lệ để nhận liên kết đặt lại mật khẩu.');
            header('Location: ' . BASE_URL . '/index.php?auth=forgot');
            exit;
        }

        $account = $this->model->timTheoEmail($email);
        if (!$account) {
            set_flash('success', 'Nếu email tồn tại trong hệ thống, liên kết đặt lại mật khẩu sẽ được gửi tới hộp thư của bạn.');
            header('Location: ' . BASE_URL . '/index.php?r=dangnhap');
            exit;
        }

        $token = $this->model->createPasswordResetToken($email);
        if (!$token) {
            set_flash('error', 'Không thể tạo yêu cầu đặt lại mật khẩu lúc này.');
            header('Location: ' . BASE_URL . '/index.php?auth=forgot');
            exit;
        }

        $resetUrl = $this->buildRouteUrl('dat_lai_mat_khau', ['token' => $token]);
        $subject = 'SkinSyntax - Dat lai mat khau';
        $html = '<div style="font-family:Arial,sans-serif;line-height:1.6;color:#1f2937">'
            . '<h2 style="margin-bottom:12px">Dat lai mat khau</h2>'
            . '<p>Xin chao ' . h((string)($account['ho_ten'] ?? 'ban')) . ',</p>'
            . '<p>Ban vua yeu cau dat lai mat khau cho tai khoan SkinSyntax. Nhan vao nut ben duoi de tiep tuc:</p>'
            . '<p><a href="' . h($resetUrl) . '" style="display:inline-block;padding:12px 20px;background:#0f6b3e;color:#fff;border-radius:999px;text-decoration:none;font-weight:700">Dat lai mat khau</a></p>'
            . '<p>Hoac mo truc tiep lien ket nay:</p>'
            . '<p><a href="' . h($resetUrl) . '">' . h($resetUrl) . '</a></p>'
            . '<p>Lien ket co hieu luc trong 30 phut.</p>'
            . '</div>';

        if (!$this->sendHtmlEmail($email, $subject, $html)) {
            set_flash('error', 'Khong gui duoc email dat lai mat khau. Vui long cau hinh mail server cho PHP/XAMPP de su dung chuc nang nay.');
            header('Location: ' . BASE_URL . '/index.php?auth=forgot');
            exit;
        }

        set_flash('success', 'Lien ket dat lai mat khau da duoc gui toi email cua ban.');
        header('Location: ' . BASE_URL . '/index.php?r=dangnhap');
        exit;
    }

    public function datLaiMatKhau(): void {
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $token = trim((string)($_POST['token'] ?? ''));
            $matKhauMoi = (string)($_POST['mat_khau_moi'] ?? '');
            $xacNhan = (string)($_POST['xac_nhan_mat_khau'] ?? '');
            $tokenRow = $this->model->validatePasswordResetToken($token);

            if (!$tokenRow) {
                set_flash('error', 'Lien ket dat lai mat khau khong hop le hoac da het han.');
                header('Location: ' . BASE_URL . '/index.php?auth=forgot');
                exit;
            }

            if (strlen($matKhauMoi) < 6) {
                set_flash('error', 'Mat khau moi phai co it nhat 6 ky tu.');
                header('Location: ' . BASE_URL . '/index.php?r=dat_lai_mat_khau&token=' . urlencode($token));
                exit;
            }

            if ($matKhauMoi !== $xacNhan) {
                set_flash('error', 'Xac nhan mat khau khong khop.');
                header('Location: ' . BASE_URL . '/index.php?r=dat_lai_mat_khau&token=' . urlencode($token));
                exit;
            }

            $result = $this->model->capNhatMatKhauTheoEmail((string)($tokenRow['email'] ?? ''), $matKhauMoi);
            if (!empty($result['ok'])) {
                $this->model->consumePasswordResetToken((int)($tokenRow['id'] ?? 0));
                set_flash('success', 'Dat lai mat khau thanh cong. Ban co the dang nhap ngay bay gio.');
                header('Location: ' . BASE_URL . '/index.php?r=dangnhap');
                exit;
            }

            set_flash('error', (string)($result['message'] ?? 'Khong the dat lai mat khau.'));
            header('Location: ' . BASE_URL . '/index.php?r=dat_lai_mat_khau&token=' . urlencode($token));
            exit;
        }

        $token = trim((string)($_GET['token'] ?? ''));
        $tokenValid = $token !== '' && $this->model->validatePasswordResetToken($token) !== null;
        $this->render('auth/quenmatkhau', [
            'mode' => 'reset',
            'tokenValid' => $tokenValid,
            'token' => $token,
        ]);
    }

    public function authSocial(): void {
        $provider = strtolower(trim((string)($_GET['provider'] ?? '')));
        $config = $this->getOAuthConfig($provider);
        if (!$config || !$this->socialEnabled($provider)) {
            set_flash('error', 'Dang nhap bang ' . ucfirst($provider) . ' chua duoc cau hinh. Vui long them client ID va secret trong file cau hinh.');
            header('Location: ' . BASE_URL . '/index.php?auth=login');
            exit;
        }

        $state = bin2hex(random_bytes(16));
        $_SESSION['oauth_state_' . $provider] = $state;
        $redirectUri = $this->buildRouteUrl('auth_social_callback', ['provider' => $provider]);

        if ($provider === 'google') {
            $query = http_build_query([
                'client_id' => $config['client_id'],
                'redirect_uri' => $redirectUri,
                'response_type' => 'code',
                'scope' => $config['scope'],
                'state' => $state,
                'prompt' => 'select_account',
            ]);
        } else {
            $query = http_build_query([
                'client_id' => $config['client_id'],
                'redirect_uri' => $redirectUri,
                'response_type' => 'code',
                'scope' => $config['scope'],
                'state' => $state,
            ]);
        }

        header('Location: ' . $config['auth_url'] . '?' . $query);
        exit;
    }

    public function authSocialCallback(): void {
        $provider = strtolower(trim((string)($_GET['provider'] ?? '')));
        $config = $this->getOAuthConfig($provider);
        if (!$config || !$this->socialEnabled($provider)) {
            set_flash('error', 'Dang nhap bang ' . ucfirst($provider) . ' chua duoc cau hinh.');
            header('Location: ' . BASE_URL . '/index.php?auth=login');
            exit;
        }

        $state = trim((string)($_GET['state'] ?? ''));
        $code = trim((string)($_GET['code'] ?? ''));
        if ($state === '' || $code === '' || $state !== (string)($_SESSION['oauth_state_' . $provider] ?? '')) {
            set_flash('error', 'Phien dang nhap ' . ucfirst($provider) . ' khong hop le.');
            header('Location: ' . BASE_URL . '/index.php?auth=login');
            exit;
        }
        unset($_SESSION['oauth_state_' . $provider]);

        $redirectUri = $this->buildRouteUrl('auth_social_callback', ['provider' => $provider]);
        if ($provider === 'google') {
            $tokenResponse = $this->httpRequest($config['token_url'], [
                'method' => 'POST',
                'headers' => ['Content-Type: application/x-www-form-urlencoded'],
                'body' => http_build_query([
                    'code' => $code,
                    'client_id' => $config['client_id'],
                    'client_secret' => $config['client_secret'],
                    'redirect_uri' => $redirectUri,
                    'grant_type' => 'authorization_code',
                ]),
            ]);
            $tokenPayload = json_decode((string)($tokenResponse['body'] ?? ''), true) ?: [];
            $accessToken = trim((string)($tokenPayload['access_token'] ?? ''));
            $profileResponse = $accessToken !== ''
                ? $this->httpRequest('https://www.googleapis.com/oauth2/v2/userinfo?access_token=' . urlencode($accessToken))
                : ['status' => 0, 'body' => ''];
            $profile = json_decode((string)($profileResponse['body'] ?? ''), true) ?: [];
            $email = trim((string)($profile['email'] ?? ''));
            $name = trim((string)($profile['name'] ?? ''));
        } else {
            $tokenResponse = $this->httpRequest($config['token_url'] . '?' . http_build_query([
                'client_id' => $config['client_id'],
                'client_secret' => $config['client_secret'],
                'redirect_uri' => $redirectUri,
                'code' => $code,
            ]));
            $tokenPayload = json_decode((string)($tokenResponse['body'] ?? ''), true) ?: [];
            $accessToken = trim((string)($tokenPayload['access_token'] ?? ''));
            $profileResponse = $accessToken !== ''
                ? $this->httpRequest('https://graph.facebook.com/me?' . http_build_query([
                    'fields' => 'id,name,email',
                    'access_token' => $accessToken,
                ]))
                : ['status' => 0, 'body' => ''];
            $profile = json_decode((string)($profileResponse['body'] ?? ''), true) ?: [];
            $email = trim((string)($profile['email'] ?? ''));
            $name = trim((string)($profile['name'] ?? ''));
        }

        if ($email === '') {
            set_flash('error', 'Khong lay duoc email tu tai khoan ' . ucfirst($provider) . '. Vui long kiem tra quyen truy cap email.');
            header('Location: ' . BASE_URL . '/index.php?auth=login');
            exit;
        }

        if ($this->model->timNhanVienTheoEmail($email)) {
            set_flash('error', 'Tai khoan nhan vien khong ho tro dang nhap bang ' . ucfirst($provider) . '. Vui long dang nhap bang email va mat khau.');
            header('Location: ' . BASE_URL . '/index.php?auth=login');
            exit;
        }

        $name = $name !== '' ? $name : (strstr($email, '@', true) ?: $email);
        $account = $this->model->findOrCreateCustomerAccount($name, $email);
        if (!$account) {
            set_flash('error', 'Khong the khoi tao tai khoan tu ' . ucfirst($provider) . ' luc nay.');
            header('Location: ' . BASE_URL . '/index.php?auth=login');
            exit;
        }

        $this->loginCustomerSession($account);
        set_flash('success', 'Dang nhap bang ' . ucfirst($provider) . ' thanh cong.');
        header('Location: ' . BASE_URL . '/index.php?r=home');
        exit;
    }

    public function dangky() {
        header('Location: ' . BASE_URL . '/index.php?auth=register');
        exit;
    }

    private function buildSignupOldInput(): array {
        return [
            'ho_ten' => trim((string)($_POST['ho_ten'] ?? '')),
            'email' => trim((string)($_POST['email'] ?? '')),
            'gioi_tinh' => trim((string)($_POST['gioi_tinh'] ?? 'Khong xac dinh')),
            'ngay_sinh' => trim((string)($_POST['ngay_sinh'] ?? '')),
            'thang_sinh' => trim((string)($_POST['thang_sinh'] ?? '')),
            'nam_sinh' => trim((string)($_POST['nam_sinh'] ?? '')),
            'terms_agree' => isset($_POST['terms_agree']) ? '1' : '0',
            'email_opt_in' => isset($_POST['email_opt_in']) ? '1' : '0',
            'privacy_consent' => isset($_POST['privacy_consent']) ? '1' : '0',
        ];
    }

    private function validatePasswordStrength(string $password): bool {
        return (bool)preg_match('/^(?=.*[a-z])(?=.*[A-Z])(?=.*\d)(?=.*[^A-Za-z\d]).{8,32}$/', $password);
    }

    public function xulydangky() {
        $hoTen = trim($_POST['ho_ten'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $matkhau = $_POST['mat_khau'] ?? '';
        $matkhau2 = $_POST['mat_khau2'] ?? '';

        $_SESSION['signup_old'] = $this->buildSignupOldInput();

        if ($hoTen === '' || $email === '' || $matkhau === '' || $matkhau2 === '') {
            set_flash('error', 'Vui lòng nhập đầy đủ thông tin.');
            header('Location: ' . BASE_URL . '/index.php?auth=register');
            exit;
        }

        if (!$this->validatePasswordStrength($matkhau)) {
            set_flash('error', 'Mật khẩu phải dài 8-32 ký tự và có chữ in hoa, chữ in thường, số, ký tự đặc biệt.');
            header('Location: ' . BASE_URL . '/index.php?auth=register');
            exit;
        }

        if ($matkhau !== $matkhau2) {
            set_flash('error', 'Mật khẩu nhập lại không khớp.');
            header('Location: ' . BASE_URL . '/index.php?auth=register');
            exit;
        }

        if (!isset($_POST['terms_agree']) || !isset($_POST['privacy_consent'])) {
            set_flash('error', 'Bạn cần đồng ý với điều kiện giao dịch và chính sách dữ liệu của SkinSyntax để đăng ký.');
            header('Location: ' . BASE_URL . '/index.php?auth=register');
            exit;
        }

        if ($this->model->timTheoEmail($email)) {
            set_flash('error', 'Email đã tồn tại.');
            header('Location: ' . BASE_URL . '/index.php?auth=register');
            exit;
        }

        $this->model->taoMoi($hoTen, $email, $matkhau);
        unset($_SESSION['signup_old']);

        $_SESSION['pending_survey'] = [
            'ho_ten' => $hoTen,
            'email' => $email,
            'source' => 'signup',
        ];

        set_flash('success', 'Đăng ký thành công. Bạn có thể làm khảo sát để nhận gợi ý chính xác hơn.');
        header("Location: " . BASE_URL . "/index.php?r=khaosat");
        exit;
    }

    public function khaosat() {
        $pending = $_SESSION['pending_survey'] ?? null;

        if (!$pending) {
            $user = $_SESSION['user'] ?? null;
            if ($user && !empty($user['email'])) {
                $pending = [
                    'ho_ten' => (string)($user['ho_ten'] ?? ''),
                    'email' => (string)($user['email'] ?? ''),
                    'source' => 'login',
                ];
                $_SESSION['pending_survey'] = $pending;
            } else {
                set_flash('error', 'Vui lòng đăng ký hoặc đăng nhập trước khi làm khảo sát.');
                header("Location: " . BASE_URL . "/index.php?r=dangnhap");
                exit;
            }
        }

        $this->render('auth/khaosat', [
            'pending' => $pending,
        ]);
    }

    public function xulykhaosat() {
        $pending = $_SESSION['pending_survey'] ?? null;
        if (!$pending) {
            set_flash('error', 'Phiên khảo sát đã hết hạn.');
            header("Location: " . BASE_URL . "/index.php?r=dangnhap");
            exit;
        }

        $hoTen = trim((string)($pending['ho_ten'] ?? ''));
        $email = trim((string)($pending['email'] ?? ''));

        if ($hoTen === '' || $email === '') {
            set_flash('error', 'Không xác định được người dùng khảo sát.');
            header("Location: " . BASE_URL . "/index.php?r=dangnhap");
            exit;
        }

        if (($_POST['skip'] ?? '') === '1') {
            $this->model->ensureKhachHang($hoTen, $email);
            $source = (string)($pending['source'] ?? 'signup');
            unset($_SESSION['pending_survey']);
            set_flash('success', 'Bạn đã bỏ qua khảo sát. Có thể cập nhật lại sau bất kỳ lúc nào.');
            if ($source === 'login') {
                header("Location: " . BASE_URL . "/index.php?r=home");
            } else {
                header("Location: " . BASE_URL . "/index.php?r=dangnhap");
            }
            exit;
        }

        $q5 = $_POST['q5'] ?? [];
        $q7 = $_POST['q7'] ?? [];
        $q8 = $_POST['q8'] ?? [];
        $q9 = $_POST['q9'] ?? [];
        $q11 = $_POST['q11'] ?? [];
        $q12 = $_POST['q12'] ?? [];

        if (!is_array($q5)) $q5 = [$q5];
        if (!is_array($q7)) $q7 = [$q7];
        if (!is_array($q8)) $q8 = [$q8];
        if (!is_array($q9)) $q9 = [$q9];
        if (!is_array($q11)) $q11 = [$q11];
        if (!is_array($q12)) $q12 = [$q12];

        $q5 = array_values(array_filter(array_map('trim', $q5), fn($v) => $v !== ''));
        $q7 = array_values(array_filter(array_map('trim', $q7), fn($v) => $v !== ''));
        $q8 = array_values(array_filter(array_map('trim', $q8), fn($v) => $v !== ''));
        $q9 = array_values(array_filter(array_map('trim', $q9), fn($v) => $v !== ''));
        $q11 = array_values(array_filter(array_map('trim', $q11), fn($v) => $v !== ''));
        $q12 = array_values(array_filter(array_map('trim', $q12), fn($v) => $v !== ''));

        $q1 = trim((string)($_POST['q1'] ?? '')); // gioi_tinh
        $q2 = trim((string)($_POST['q2'] ?? '')); // nam_sinh
        $q3 = trim((string)($_POST['q3'] ?? '')); // loai_da
        $q4 = trim((string)($_POST['q4'] ?? '')); // nhay cam
        $q6 = trim((string)($_POST['q6'] ?? '')); // muc tieu
        $q10 = trim((string)($_POST['q10'] ?? '')); // ngan_sach bucket

        $requiredFailed = ($q1 === '' || $q2 === '' || $q3 === '' || $q4 === '' || empty($q5) || empty($q9) || $q10 === '');
        if ($requiredFailed) {
            set_flash('error', 'Vui lòng trả lời đầy đủ các câu bắt buộc hoặc bấm Bỏ qua khảo sát.');
            header("Location: " . BASE_URL . "/index.php?r=khaosat");
            exit;
        }

        $budgetMap = [
            'duoi_200k' => 200000,
            '200_500k' => 500000,
            '500_1000k' => 1000000,
            'tren_1000k' => 1500000,
        ];

        $currentYear = (int)date('Y');
        $namSinh = ctype_digit($q2) ? (int)$q2 : null;
        if ($namSinh === null || $namSinh < 1970 || $namSinh > max($currentYear, 2010)) {
            set_flash('error', 'Năm sinh không hợp lệ. Vui lòng chọn lại.');
            header("Location: " . BASE_URL . "/index.php?r=khaosat");
            exit;
        }

        $thanhPhanTranh = $q9;
        if (in_array('KhongCo', $thanhPhanTranh, true)) {
            $thanhPhanTranh = ['Không có / Không quan tâm'];
        }

        $tieuChiUuTienParts = [];
        if (!empty($q7)) $tieuChiUuTienParts[] = 'Kết cấu: ' . implode(', ', $q7);
        if (!empty($q8)) $tieuChiUuTienParts[] = 'Hoạt chất: ' . implode(', ', $q8);

        $tinhTrangParts = ['Khảo sát đăng nhập'];
        if (!empty($q11)) $tinhTrangParts[] = 'Xuất xứ ưa thích: ' . implode(', ', $q11);
        if (!empty($q12)) $tinhTrangParts[] = 'Thương hiệu ưu tiên: ' . implode(', ', $q12);

        $payload = [
            'gioi_tinh' => $q1,
            'loai_da' => $q3,
            'nam_sinh' => $namSinh,
            'muc_do_nhay_cam' => $q4,
            'van_de_da' => implode(', ', $q5),
            'muc_do_mun' => null,
            'muc_tieu_cham_soc' => ($q6 !== '' ? $q6 : null),
            'tieu_chi_uu_tien' => (!empty($tieuChiUuTienParts) ? implode(' | ', $tieuChiUuTienParts) : null),
            'tinh_trang_dac_biet' => implode(' | ', $tinhTrangParts),
            'kinh_nghiem_skincare' => (!empty($q11) ? implode(', ', $q11) : null),
            'so_buoc_skincare' => (!empty($q12) ? implode(', ', $q12) : null),
            'thanh_phan_tranh' => (!empty($thanhPhanTranh) ? implode(', ', $thanhPhanTranh) : null),
            'ngan_sach' => $budgetMap[$q10] ?? null,
        ];

        $ok = $this->model->luuKhaoSatKhachHang($hoTen, $email, $payload);
        if (!$ok) {
            set_flash('error', 'Không thể lưu khảo sát. Vui lòng thử lại.');
            header("Location: " . BASE_URL . "/index.php?r=khaosat");
            exit;
        }

        $source = (string)($pending['source'] ?? 'signup');
        unset($_SESSION['pending_survey']);
        set_flash('success', 'Cảm ơn bạn đã hoàn thành khảo sát. Dữ liệu đã được lưu để cải thiện gợi ý.');
        if ($source === 'login') {
            header("Location: " . BASE_URL . "/index.php?r=goiy");
        } else {
            header("Location: " . BASE_URL . "/index.php?r=dangnhap");
        }
        exit;
    }

    public function dangxuat() {
        unset($_SESSION['user']);
        $_SESSION['flash'] = [];
        set_flash('success', 'Đã đăng xuất.');
        header("Location: " . BASE_URL . "/index.php?r=home");
        exit;
    }
}
