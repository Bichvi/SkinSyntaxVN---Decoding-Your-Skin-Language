<?php
$summary = $summary ?? [];
$user = $user ?? [];
$pendingOrders = $pendingOrders ?? [];
$conversations = $conversations ?? [];
$reviews = $reviews ?? [];
?>

<div class="container-fluid p-4">
    <div class="mb-4">
        <h1 class="h3 mb-1">Bảng làm việc nhân viên</h1>
        <p class="text-muted mb-0">Xin chào <?= h($user['ho_ten'] ?? 'Nhân viên') ?>, đây là các đầu việc cần xử lý hôm nay.</p>
    </div>

    <div class="row g-3 mb-4">
        <div class="col-md-4"><div class="card border-0 shadow-sm rounded-4 p-3"><div class="small text-muted">Đơn chờ xử lý</div><div class="fs-4 fw-bold"><?= number_format((int)($summary['don_cho_xu_ly'] ?? 0), 0, ',', '.') ?></div></div></div>
        <div class="col-md-4"><div class="card border-0 shadow-sm rounded-4 p-3"><div class="small text-muted">Chat chờ phản hồi</div><div class="fs-4 fw-bold"><?= number_format((int)($summary['chat_cho_tra_loi'] ?? 0), 0, ',', '.') ?></div></div></div>
        <div class="col-md-4"><div class="card border-0 shadow-sm rounded-4 p-3"><div class="small text-muted">Đánh giá chờ phản hồi</div><div class="fs-4 fw-bold"><?= number_format((int)($summary['danh_gia_cho_phan_hoi'] ?? 0), 0, ',', '.') ?></div></div></div>
    </div>

    <div class="row g-4">
        <div class="col-lg-4">
            <div class="card border-0 shadow-sm rounded-4 h-100">
                <div class="card-body p-4">
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <h5 class="fw-bold mb-0">Đơn cần xác nhận</h5>
                        <a href="index.php?r=staff_orders" class="small text-decoration-none">Xem tất cả</a>
                    </div>
                    <?php if (empty($pendingOrders)): ?>
                        <div class="text-muted">Không có đơn chờ xử lý.</div>
                    <?php else: ?>
                        <div class="list-group list-group-flush">
                            <?php foreach (array_slice($pendingOrders, 0, 6) as $order): ?>
                                <a href="index.php?r=staff_orders&detail=<?= (int)($order['ma_hoa_don'] ?? 0) ?>" class="list-group-item list-group-item-action px-0">
                                    <div class="fw-semibold">#<?= h($order['ma_hoa_don'] ?? '') ?> · <?= h($order['ho_ten'] ?? 'Khách hàng') ?></div>
                                    <div class="small text-muted"><?= vnd($order['tong_tien'] ?? 0) ?></div>
                                </a>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <div class="col-lg-4">
            <div class="card border-0 shadow-sm rounded-4 h-100">
                <div class="card-body p-4">
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <h5 class="fw-bold mb-0">Chat hỗ trợ</h5>
                        <a href="index.php?r=staff_chats" class="small text-decoration-none">Mở hộp chat</a>
                    </div>
                    <?php if (empty($conversations)): ?>
                        <div class="text-muted">Chưa có hội thoại nào.</div>
                    <?php else: ?>
                        <div class="list-group list-group-flush">
                            <?php foreach (array_slice($conversations, 0, 6) as $chat): ?>
                                <a href="index.php?r=staff_chats&ma_kh=<?= (int)($chat['ma_kh'] ?? 0) ?>" class="list-group-item list-group-item-action px-0">
                                    <div class="fw-semibold"><?= h($chat['ho_ten'] ?? 'Khách hàng') ?></div>
                                    <div class="small text-muted"><?= h($chat['tin_nhan_moi'] ?? '') ?></div>
                                </a>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <div class="col-lg-4">
            <div class="card border-0 shadow-sm rounded-4 h-100">
                <div class="card-body p-4">
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <h5 class="fw-bold mb-0">Đánh giá cần phản hồi</h5>
                        <a href="index.php?r=staff_reviews" class="small text-decoration-none">Xem tất cả</a>
                    </div>
                    <?php if (empty($reviews)): ?>
                        <div class="text-muted">Chưa có đánh giá nào.</div>
                    <?php else: ?>
                        <div class="list-group list-group-flush">
                            <?php foreach (array_slice($reviews, 0, 6) as $review): ?>
                                <a href="index.php?r=staff_reviews" class="list-group-item list-group-item-action px-0">
                                    <div class="fw-semibold"><?= h($review['ten_san_pham'] ?? 'Sản phẩm') ?></div>
                                    <div class="small text-muted"><?= h($review['noi_dung'] ?? '') ?></div>
                                </a>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>