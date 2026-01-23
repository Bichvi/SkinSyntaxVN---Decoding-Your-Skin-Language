<?php
require_once "../app/config/db.php";

// Lấy 8 sản phẩm bất kỳ
$stmt = $pdo->query("SELECT title, brand, image_url, safety_score FROM sanpham LIMIT 8");
$products = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="utf-8">
    <title>SkinSyntax</title>

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="assets/css/style.css" rel="stylesheet">
</head>
<body>

<!-- HEADER -->
<nav class="navbar navbar-expand-lg navbar-dark">
    <div class="container">
        <a class="navbar-brand fw-bold" href="#">SkinSyntax</a>

        <input class="form-control w-50" placeholder="Tìm kiếm sản phẩm...">

        <a class="btn btn-light" href="dangnhap.php">Đăng nhập</a>
    </div>
</nav>

<!-- BANNER -->
<div class="container mt-4">
    <div class="p-5 bg-white text-center rounded shadow-sm">
        <h1 class="fw-bold">TRA CỨU THÀNH PHẦN MỸ PHẨM</h1>
        <p class="text-muted">Hiểu rõ làn da của bạn</p>
    </div>
</div>

<!-- SẢN PHẨM -->
<div class="container mt-5">
    <h3 class="mb-4">Sản phẩm nổi bật</h3>

    <div class="row">

        <?php foreach($products as $p): ?>

        <div class="col-md-3">
            <a href="detail.php?title=<?= urlencode($p['title']) ?>" class="text-decoration-none text-dark">
            <div class="card mb-4 product-card shadow-sm">

                <img 
                    src="<?= htmlspecialchars($p['image_url']) ?>" 
                    class="card-img-top"
                    onerror="this.src='https://via.placeholder.com/300x300?text=No+Image';"
                >

                <div class="card-body">
                    <h6 class="card-title" style="min-height:48px">
                        <?= htmlspecialchars($p['title']) ?>
                    </h6>

                    <p class="text-muted mb-1">
                        <?= htmlspecialchars($p['brand']) ?>
                    </p>

                    <span class="badge bg-success">
                        Độ an toàn: <?= htmlspecialchars($p['safety_score']) ?>/10
                    </span>
                

                </div>
               
            </div>
            </a>
        </div>

        <?php endforeach ?>

    </div>
</div>

</body>
</html>
