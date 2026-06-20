<?php
session_start();
require_once "../app/config/db.php";

$msg = "";

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $email = $_POST['email'];
    $matkhau = $_POST['matkhau'];

    $stmt = $pdo->prepare("SELECT * FROM nguoidung WHERE email = ?");
    $stmt->execute([$email]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($user && password_verify($matkhau, $user['matkhau'])) {
        $_SESSION['user'] = $user;
        header("Location: index.php");
        exit;
    } else {
        $msg = "Sai email hoặc mật khẩu!";
    }
}
?>
<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<title>Đăng nhập</title>
<link href="assets/css/style.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body>

<div class="container">
    <form method="post">
        <h3>Đăng nhập</h3>

        <?php if($msg): ?>
            <div class="alert alert-danger"><?= $msg ?></div>
        <?php endif ?>

        <input name="email" type="email" class="form-control" placeholder="Email" required>
        <input name="matkhau" type="password" class="form-control" placeholder="Mật khẩu" required>

        <button type="submit">Đăng nhập</button>

        <a href="dangky.php">Chưa có tài khoản? Đăng ký</a>
    </form>
</div>

</body>
</html>
