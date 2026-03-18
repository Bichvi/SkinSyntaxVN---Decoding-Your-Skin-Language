<?php
$reviews = $reviews ?? [];
$q = trim((string)($q ?? ''));
?>

<div class="container-fluid p-4">
    <div class="mb-4">
        <h1 class="h3 mb-1">Phản hồi đánh giá sản phẩm</h1>
        <p class="text-muted mb-0">Nhân viên có thể theo dõi và phản hồi các đánh giá từ khách hàng.</p>
    </div>

    <form class="row g-2 mb-4" method="get" action="index.php">
        <input type="hidden" name="r" value="staff_reviews">
        <div class="col-md-9">
            <input type="text" class="form-control" name="q" value="<?= h($q) ?>" placeholder="Tìm theo sản phẩm, khách hàng hoặc nội dung đánh giá...">
        </div>
        <div class="col-md-3 d-grid">
            <button type="submit" class="btn btn-outline-primary">Lọc đánh giá</button>
        </div>
    </form>

    <div class="row g-4">
        <?php if (empty($reviews)): ?>
            <div class="col-12"><div class="card border-0 shadow-sm rounded-4"><div class="card-body p-5 text-center text-muted">Chưa có đánh giá nào.</div></div></div>
        <?php else: ?>
            <?php foreach ($reviews as $review): ?>
                <div class="col-12">
                    <div class="card border-0 shadow-sm rounded-4">
                        <div class="card-body p-4">
                            <div class="d-flex flex-column flex-lg-row justify-content-between gap-3 mb-3">
                                <div>
                                    <h5 class="fw-bold mb-1"><?= h($review['ten_san_pham'] ?? 'Sản phẩm') ?></h5>
                                    <div class="text-muted small">Khách hàng: <?= h($review['ten_khach_hang'] ?? 'Ẩn danh') ?> · <?= (int)($review['so_sao'] ?? 0) ?>/5 sao</div>
                                </div>
                                <div class="text-muted small"><?= h(!empty($review['ngay_danh_gia']) ? date('d/m/Y H:i', strtotime((string)$review['ngay_danh_gia'])) : '') ?></div>
                            </div>

                            <div class="mb-3 p-3 bg-light rounded-3"><?= nl2br_safe($review['noi_dung'] ?? '') ?></div>

                            <?php if (!empty($review['phan_hoi'])): ?>
                                <div class="mb-3 p-3 border rounded-3 bg-white">
                                    <div class="fw-semibold mb-1">Phản hồi đã gửi</div>
                                    <div><?= nl2br_safe($review['phan_hoi'] ?? '') ?></div>
                                    <div class="small text-muted mt-2">Nhân viên: <?= h($review['ten_nhan_vien_phan_hoi'] ?? 'N/A') ?></div>
                                </div>
                            <?php endif; ?>

                            <form method="post" action="index.php?r=staff_review_reply" class="row g-2">
                                <input type="hidden" name="ma_danh_gia" value="<?= h($review['ma_danh_gia'] ?? '') ?>">
                                <div class="col-lg-10">
                                    <textarea class="form-control" name="phan_hoi" rows="2" placeholder="Nhập phản hồi cho khách hàng..."><?= h($review['phan_hoi'] ?? '') ?></textarea>
                                </div>
                                <div class="col-lg-2 d-grid">
                                    <button type="submit" class="btn btn-primary">Gửi phản hồi</button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</div>