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

    public function dangnhap() {
        $this->render('auth/dangnhap');
    }

    public function xulydangnhap() {
        $email = trim($_POST['email'] ?? '');
        $matkhau = $_POST['mat_khau'] ?? '';

        $u = $this->model->timTheoEmail($email);
        if ($u && password_verify($matkhau, (string)$u['mat_khau'])) {
            $_SESSION['user'] = [
                'id' => $u['id'],
                'ho_ten' => $u['ho_ten'],
                'email' => $u['email'],
                'role' => 'khach_hang',
                'vai_tro' => 'khach_hang',
            ];

            set_flash('success', 'Đăng nhập thành công.');
            header("Location: " . BASE_URL . "/index.php?r=home");
            exit;
        }

        $staff = $this->model->timNhanVienTheoEmail($email);
        if ($staff && !empty($staff['mat_khau']) && password_verify($matkhau, (string)$staff['mat_khau'])) {
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

        set_flash('error', 'Email hoặc mật khẩu không đúng.');
        header("Location: " . BASE_URL . "/index.php?r=dangnhap");
        exit;
    }

    public function dangky() {
        $this->render('auth/dangky');
    }

    public function xulydangky() {
        $hoTen = trim($_POST['ho_ten'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $matkhau = $_POST['mat_khau'] ?? '';
        $matkhau2 = $_POST['mat_khau2'] ?? '';

        if ($hoTen === '' || $email === '' || $matkhau === '' || $matkhau2 === '') {
            set_flash('error', 'Vui lòng nhập đầy đủ thông tin.');
            header("Location: " . BASE_URL . "/index.php?r=dangky");
            exit;
        }

        if ($matkhau !== $matkhau2) {
            set_flash('error', 'Mật khẩu nhập lại không khớp.');
            header("Location: " . BASE_URL . "/index.php?r=dangky");
            exit;
        }

        if ($this->model->timTheoEmail($email)) {
            set_flash('error', 'Email đã tồn tại.');
            header("Location: " . BASE_URL . "/index.php?r=dangky");
            exit;
        }

        $this->model->taoMoi($hoTen, $email, $matkhau);

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
        set_flash('success', 'Đã đăng xuất.');
        header("Location: " . BASE_URL . "/index.php?r=home");
        exit;
    }
}
