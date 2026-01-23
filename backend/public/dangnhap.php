<?php
session_start();
require_once "../app/config/db.php";

$error = "";

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $email = $_POST['email'] ?? '';
    $password = $_POST['password'] ?? '';

    if ($email == "admin@skinsyntax.com" && $password == "123456") {
        $_SESSION['user'] = $email;
        header("Location: index.php");
        exit;
    } else {
        $error = "Sai tài khoản hoặc mật khẩu!";
    }
}
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Đăng nhập</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body>

<div class="container mt-5" style="max-width:400px;">
    <h3 class="mb-4">Đăng nhập</h3>

    <?php if ($error): ?>
        <div class="alert alert-danger"><?= $error ?></div>
    <?php endif; ?>

    <form method="post">
        <input class="form-control mb-3" name="email" placeholder="Email">
        <input class="form-control mb-3" type="password" name="password" placeholder="Mật khẩu">
        <button class="btn btn-success w-100">Đăng nhập</button>V
    </form>

    <p class="mt-3">
        Chưa có tài khoản? <a href="dangky.php">Đăng ký</a>
    </p>
</div>

</body>
</html>
