<?php
require_once "../app/config/db.php";

if (!isset($_GET['title'])) {
    die("Thiếu sản phẩm!");
}

$title = $_GET['title'];

$stmt = $pdo->prepare("SELECT * FROM sanpham WHERE title = ?");
$stmt->execute([$title]);
$product = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$product) {
    die("Không tìm thấy sản phẩm!");
}
?>
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="utf-8">
    <title><?= htmlspecialchars($product['title']) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body>

<div class="container mt-4">
    <a href="index.php">← Quay về</a>

    <div class="row mt-4">
        <div class="col-md-4">
            <img src="<?= htmlspecialchars($product['image_url']) ?>" class="img-fluid">
        </div>

        <div class="col-md-8">
            <h2><?= htmlspecialchars($product['title']) ?></h2>
            <p><b>Thương hiệu:</b> <?= htmlspecialchars($product['brand']) ?></p>
            <p><b>Danh mục:</b> <?= htmlspecialchars($product['category']) ?></p>

            <p>
                <span class="badge bg-success fs-6">
                    Độ an toàn: <?= htmlspecialchars($product['safety_score']) ?>/10
                </span>
            </p>

            <hr>

            <h5>Thành phần:</h5>
            <p><?= nl2br(htmlspecialchars($product['ingredients'])) ?></p>

            <h5>Phân tích nhanh:</h5>
            <p><?= nl2br(htmlspecialchars($product['quick_analysis'])) ?></p>

            <div class="mt-3">
                <?php if (!empty($product['link_shopee'])): ?>
                    <a target="_blank" href="<?= htmlspecialchars($product['link_shopee']) ?>" class="btn btn-warning">Mua trên Shopee</a>
                <?php endif; ?>

                <?php if (!empty($product['link_lazada'])): ?>
                    <a target="_blank" href="<?= htmlspecialchars($product['link_lazada']) ?>" class="btn btn-primary">Mua trên Lazada</a>
                <?php endif; ?>
            </div>

        </div>
    </div>
</div>

</body>
</html>
