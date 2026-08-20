<?php
    session_destroy();
    $_SESSION['flash'] = "Đăng xuất thành công.";
    header("Location: " . BASE_URL . "/index.php?r=home");
    exit;
?>