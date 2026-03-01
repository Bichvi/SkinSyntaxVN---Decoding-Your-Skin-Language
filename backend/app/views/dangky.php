<?php
require_once "../app/config/db.php";

$msg = "";

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $hoten = $_POST['hoten'];
    $email = $_POST['email'];
    $matkhau = password_hash($_POST['matkhau'], PASSWORD_BCRYPT);

    try {
        $stmt = $pdo->prepare("INSERT INTO nguoidung(hoten, email, matkhau) VALUES (?, ?, ?)");
        $stmt->execute([$hoten, $email, $matkhau]);
        header("Location: dangnhap.php");
        exit;
    } catch (Exception $e) {
        $msg = "Email đã tồn tại!";
    }
}
?>
<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<title>Đăng ký</title>
<link href="assets/css/style.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body>

<div class="container">
    <form method="post">
        <h3>Đăng ký tài khoản</h3>

        <?php if($msg): ?>
            <div class="alert alert-danger"><?= $msg ?></div>
        <?php endif ?>

        <input name="hoten" class="form-control" placeholder="Họ tên" required>
        <input name="email" type="email" class="form-control" placeholder="Email" required>
        <input name="matkhau" type="password" class="form-control" placeholder="Mật khẩu" required>

        <button type="submit">Đăng ký</button>

        <a href="dangnhap.php">Đã có tài khoản? Đăng nhập</a>
    </form>
</div>

</body>
</html>
