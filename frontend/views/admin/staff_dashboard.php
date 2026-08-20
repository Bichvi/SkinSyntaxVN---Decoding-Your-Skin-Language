<?php
$summary = $summary ?? [];
$user = $user ?? [];
$pendingOrders = $pendingOrders ?? [];
$conversations = $conversations ?? [];
$reviews = $reviews ?? [];
$questions = $questions ?? [];

$staffName = $user['ho_ten'] ?? $_SESSION['admin_name'] ?? 'Nhân viên';
$donChoXuLy = (int)($summary['don_cho_xu_ly'] ?? count($pendingOrders));
$chatPending = (int)($summary['chat_cho_tra_loi'] ?? count($conversations));
$reviewPending = (int)($summary['danh_gia_cho_phan_hoi'] ?? count($reviews));
$questionPending = count($questions);

$totalTaskCount = $donChoXuLy + $chatPending + $reviewPending + $questionPending;
$initialChar = mb_strtoupper(mb_substr(trim($staffName), 0, 1));
?>

<style>
.staff-hero-card {
  background: linear-gradient(135deg, #162F18 0%, #2D5A27 50%, #1A3C1E 100%) !important;
  border-radius: 20px;
  color: #fff;
  border: 1px solid rgba(255,255,255,0.12);
  box-shadow: 0 10px 30px rgba(22, 47, 24, 0.18);
  position: relative;
  overflow: hidden;
}
.staff-hero-card::after {
  content: '';
  position: absolute;
  right: -40px;
  top: -40px;
  width: 220px;
  height: 220px;
  background: radial-gradient(circle, rgba(132, 169, 140, 0.25) 0%, rgba(255,255,255,0) 70%);
  border-radius: 50%;
  pointer-events: none;
}
.staff-avatar-initial {
  width: 56px;
  height: 56px;
  border-radius: 50%;
  background: #ffffff;
  color: #215427;
  font-weight: 800;
  font-size: 1.6rem;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  line-height: 1;
  text-align: center;
  flex-shrink: 0;
  box-shadow: 0 4px 12px rgba(0,0,0,0.15);
}
.staff-kpi-card {
  background: var(--admin-surface, #ffffff);
  border: 1px solid var(--admin-border, #e2eadf);
  border-radius: 18px;
  padding: 20px 24px;
  transition: all 0.25s cubic-bezier(0.4, 0, 0.2, 1);
  box-shadow: 0 4px 16px rgba(45, 90, 39, 0.04);
}
.staff-kpi-card:hover {
  transform: translateY(-3px);
  box-shadow: 0 12px 28px rgba(45, 90, 39, 0.1);
  border-color: #84A98C;
}
.staff-kpi-icon {
  width: 52px;
  height: 52px;
  border-radius: 14px;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  font-size: 1.4rem;
  flex-shrink: 0;
}
.staff-task-card {
  background: var(--admin-surface, #ffffff);
  border: 1px solid var(--admin-border, #e2eadf);
  border-radius: 20px;
  overflow: hidden;
  box-shadow: 0 4px 20px rgba(45, 90, 39, 0.04);
  height: 100%;
  display: flex;
  flex-direction: column;
}
.staff-task-head {
  padding: 18px 22px;
  border-bottom: 1px solid var(--admin-border, #e2eadf);
  background: rgba(240, 244, 241, 0.5);
  display: flex;
  align-items: center;
  justify-content: space-between;
}
.staff-task-item {
  padding: 16px 20px;
  border-bottom: 1px solid var(--admin-border, #f0f4f1);
  transition: background 0.2s ease;
  text-decoration: none;
  color: inherit;
  display: block;
}
.staff-task-item:last-child {
  border-bottom: none;
}
.staff-task-item:hover {
  background: rgba(45, 90, 39, 0.03);
  color: inherit;
}
.action-quick-btn {
  background: var(--admin-surface, #ffffff);
  border: 1px solid var(--admin-border, #e2eadf);
  border-radius: 14px;
  padding: 12px 18px;
  font-weight: 700;
  font-size: 0.88rem;
  color: var(--admin-text, #1a2f1a);
  display: inline-flex;
  align-items: center;
  gap: 10px;
  text-decoration: none;
  transition: all 0.2s ease;
}
.action-quick-btn:hover {
  background: #2D5A27;
  color: #ffffff;
  border-color: #2D5A27;
  transform: translateY(-2px);
  box-shadow: 0 6px 18px rgba(45, 90, 39, 0.15);
}
</style>

<div class="container-fluid px-4 py-4">
    <!-- GREETING HERO BANNER -->
    <div class="staff-hero-card p-4 mb-4">
        <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3">
            <div class="d-flex align-items-center gap-3">
                <div class="staff-avatar-initial">
                    <?= h($initialChar) ?>
                </div>
                <div>
                    <div class="d-inline-flex align-items-center gap-2 px-3 py-0.5 rounded-pill mb-1 small fw-bold" style="background: rgba(255,255,255,0.18); color: #D2E5D5;">
                        <i class="bi bi-shield-check text-warning me-1"></i> Cổng Làm Việc Nhân Viên
                    </div>
                    <h2 class="fw-bold mb-1 text-white" style="font-size: 1.75rem;">Xin chào, <?= h($staffName) ?> 👋</h2>
                    <p class="mb-0 small opacity-90" style="color: #EAF2EC;">
                        Hôm nay bạn có <strong class="text-warning fw-bold"><?= $totalTaskCount ?></strong> công việc cần chăm sóc và phản hồi khách hàng.
                    </p>
                </div>
            </div>
            <div class="d-flex align-items-center gap-2 flex-wrap">
                <span class="badge rounded-pill px-3 py-2 fw-bold text-white" style="background: rgba(255,255,255,0.15); border: 1px solid rgba(255,255,255,0.25);">
                    <i class="bi bi-clock me-1 text-warning"></i> <?= date('d/m/Y - H:i') ?>
                </span>
                <a href="index.php?r=staff_orders" class="btn btn-warning rounded-pill px-3 py-2 fw-bold text-dark btn-sm shadow-sm">
                    <i class="bi bi-lightning-charge-fill me-1"></i> Xử Lý Ngay
                </a>
            </div>
        </div>
    </div>

    <!-- QUICK ACTIONS BAR -->
    <div class="d-flex flex-wrap gap-2.5 gap-2 mb-4">
        <a href="index.php?r=staff_orders&status=cho_xu_ly" class="action-quick-btn shadow-sm">
            <i class="bi bi-box-seam-fill text-warning fs-5"></i>
            <span>Đơn Hàng Cần Duyệt</span>
            <span class="badge rounded-pill bg-warning text-dark px-2"><?= $donChoXuLy ?></span>
        </a>
        <a href="index.php?r=staff_chats" class="action-quick-btn shadow-sm">
            <i class="bi bi-chat-dots-fill text-info fs-5"></i>
            <span>Tư Vấn Chat</span>
            <span class="badge rounded-pill bg-info text-white px-2"><?= $chatPending ?></span>
        </a>
        <a href="index.php?r=staff_reviews" class="action-quick-btn shadow-sm">
            <i class="bi bi-star-fill text-warning fs-5"></i>
            <span>Đánh Giá Mới</span>
            <span class="badge rounded-pill bg-secondary text-white px-2"><?= $reviewPending ?></span>
        </a>
        <a href="index.php?r=admin_questions" class="action-quick-btn shadow-sm">
            <i class="bi bi-question-circle-fill text-success fs-5"></i>
            <span>Hỏi Đáp Sản Phẩm</span>
            <span class="badge rounded-pill bg-success text-white px-2"><?= $questionPending ?></span>
        </a>
        <a href="index.php?r=admin_lives" class="action-quick-btn shadow-sm">
            <i class="bi bi-camera-reels-fill text-danger fs-5"></i>
            <span>Phòng LiveStream</span>
        </a>
    </div>

    <!-- KPI CARDS GRID -->
    <div class="row g-3 mb-4">
        <!-- Orders KPI -->
        <div class="col-12 col-sm-6 col-xl-3">
            <a href="index.php?r=staff_orders&status=cho_xu_ly" class="text-decoration-none">
                <div class="staff-kpi-card d-flex align-items-center justify-content-between">
                    <div>
                        <div class="text-muted small fw-bold text-uppercase" style="letter-spacing: 0.05em;">Đơn Chờ Xử Lý</div>
                        <div class="fs-2 fw-extrabold my-1" style="color: #D97706; font-weight: 800;"><?= number_format($donChoXuLy) ?></div>
                        <span class="badge bg-warning-subtle text-warning-emphasis rounded-pill px-2.5 py-1 small fw-semibold">
                            <i class="bi bi-clock me-1"></i> Cần xác nhận
                        </span>
                    </div>
                    <div class="staff-kpi-icon" style="background: rgba(245, 158, 11, 0.12); color: #F59E0B;">
                        <i class="bi bi-hourglass-split"></i>
                    </div>
                </div>
            </a>
        </div>

        <!-- Chat KPI -->
        <div class="col-12 col-sm-6 col-xl-3">
            <a href="index.php?r=staff_chats" class="text-decoration-none">
                <div class="staff-kpi-card d-flex align-items-center justify-content-between">
                    <div>
                        <div class="text-muted small fw-bold text-uppercase" style="letter-spacing: 0.05em;">Chat Chờ Phản Hỏi</div>
                        <div class="fs-2 fw-extrabold my-1" style="color: #0284C7; font-weight: 800;"><?= number_format($chatPending) ?></div>
                        <span class="badge bg-info-subtle text-info-emphasis rounded-pill px-2.5 py-1 small fw-semibold">
                            <i class="bi bi-chat-text me-1"></i> Tư vấn 24/7
                        </span>
                    </div>
                    <div class="staff-kpi-icon" style="background: rgba(14, 165, 233, 0.12); color: #0EA5E9;">
                        <i class="bi bi-headset"></i>
                    </div>
                </div>
            </a>
        </div>

        <!-- Review KPI -->
        <div class="col-12 col-sm-6 col-xl-3">
            <a href="index.php?r=staff_reviews" class="text-decoration-none">
                <div class="staff-kpi-card d-flex align-items-center justify-content-between">
                    <div>
                        <div class="text-muted small fw-bold text-uppercase" style="letter-spacing: 0.05em;">Đánh Giá Cần Chăm Sóc</div>
                        <div class="fs-2 fw-extrabold my-1" style="color: #B45309; font-weight: 800;"><?= number_format($reviewPending) ?></div>
                        <span class="badge bg-warning-subtle text-warning-emphasis rounded-pill px-2.5 py-1 small fw-semibold">
                            <i class="bi bi-star me-1"></i> Chăm sóc phản hồi
                        </span>
                    </div>
                    <div class="staff-kpi-icon" style="background: rgba(217, 119, 6, 0.12); color: #D97706;">
                        <i class="bi bi-star-half"></i>
                    </div>
                </div>
            </a>
        </div>

        <!-- Q&A KPI -->
        <div class="col-12 col-sm-6 col-xl-3">
            <a href="index.php?r=admin_questions" class="text-decoration-none">
                <div class="staff-kpi-card d-flex align-items-center justify-content-between">
                    <div>
                        <div class="text-muted small fw-bold text-uppercase" style="letter-spacing: 0.05em;">Hỏi Đáp Sản Phẩm</div>
                        <div class="fs-2 fw-extrabold my-1" style="color: #15803D; font-weight: 800;"><?= number_format($questionPending) ?></div>
                        <span class="badge bg-success-subtle text-success-emphasis rounded-pill px-2.5 py-1 small fw-semibold">
                            <i class="bi bi-check-circle me-1"></i> Giải đáp ngay
                        </span>
                    </div>
                    <div class="staff-kpi-icon" style="background: rgba(34, 197, 94, 0.12); color: #22C55E;">
                        <i class="bi bi-question-circle-fill"></i>
                    </div>
                </div>
            </a>
        </div>
    </div>

    <!-- WORKSPACE QUEUES GRID (4 QUEUES) -->
    <div class="row g-4 mb-4">
        <!-- 1. Orders Queue -->
        <div class="col-12 col-lg-6 col-xl-3">
            <div class="staff-task-card">
                <div class="staff-task-head">
                    <div class="d-flex align-items-center gap-2">
                        <i class="bi bi-clock-history text-warning fs-5"></i>
                        <h6 class="fw-bold mb-0">Đơn Cần Xác Nhận</h6>
                    </div>
                    <a href="index.php?r=staff_orders" class="small fw-bold text-decoration-none text-success">Xem tất cả &rarr;</a>
                </div>
                <div class="card-body p-0 flex-grow-1">
                    <?php if (empty($pendingOrders)): ?>
                        <div class="text-center text-muted py-5 px-3">
                            <i class="bi bi-check-circle-fill text-success fs-1 mb-2 d-block opacity-50"></i>
                            <div class="fw-semibold">Tốt lắm! Không có đơn chờ duyệt.</div>
                        </div>
                    <?php else: ?>
                        <?php foreach (array_slice($pendingOrders, 0, 5) as $order): ?>
                            <?php
                                $orderDateRaw = $order['ngay_tao'] ?? $order['created_at'] ?? $order['ngay_dat'] ?? $order['thoi_gian'] ?? null;
                                $orderDateStr = '';
                                if ($orderDateRaw instanceof \MongoDB\BSON\UTCDateTime) {
                                    $orderDateStr = $orderDateRaw->toDateTime()->setTimezone(new DateTimeZone('Asia/Ho_Chi_Minh'))->format('d/m H:i');
                                } else if (!empty($orderDateRaw)) {
                                    $ts = strtotime((string)$orderDateRaw);
                                    $orderDateStr = ($ts !== false && $ts > 0) ? date('d/m H:i', $ts) : (string)$orderDateRaw;
                                }
                                if ($orderDateStr === '') {
                                    $orderDateStr = 'Mới đặt';
                                }
                            ?>
                            <a href="index.php?r=staff_orders&detail=<?= (int)($order['ma_hoa_don'] ?? 0) ?>" class="staff-task-item">
                                <div class="d-flex justify-content-between align-items-start mb-1">
                                    <strong class="text-dark">#<?= h($order['ma_hoa_don'] ?? '') ?> · <?= h($order['ho_ten'] ?? 'Khách hàng') ?></strong>
                                    <span class="badge bg-warning text-dark rounded-pill small">Chờ duyệt</span>
                                </div>
                                <div class="d-flex justify-content-between align-items-center small text-muted">
                                    <span class="fw-bold text-success"><?= vnd($order['tong_tien'] ?? 0) ?></span>
                                    <span><i class="bi bi-clock me-1"></i><?= h($orderDateStr) ?></span>
                                </div>
                            </a>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- 2. Live Chat Queue -->
        <div class="col-12 col-lg-6 col-xl-3">
            <div class="staff-task-card">
                <div class="staff-task-head">
                    <div class="d-flex align-items-center gap-2">
                        <i class="bi bi-headset text-info fs-5"></i>
                        <h6 class="fw-bold mb-0">Chat Khách Hàng</h6>
                    </div>
                    <a href="index.php?r=staff_chats" class="small fw-bold text-decoration-none text-info">Mở hộp chat &rarr;</a>
                </div>
                <div class="card-body p-0 flex-grow-1">
                    <?php if (empty($conversations)): ?>
                        <div class="text-center text-muted py-5 px-3">
                            <i class="bi bi-chat-square-text-fill text-info fs-1 mb-2 d-block opacity-50"></i>
                            <div class="fw-semibold">Hiện chưa có tin nhắn chat mới.</div>
                        </div>
                    <?php else: ?>
                        <?php foreach (array_slice($conversations, 0, 5) as $chat): ?>
                            <a href="index.php?r=staff_chats&ma_kh=<?= (int)($chat['ma_kh'] ?? 0) ?>" class="staff-task-item">
                                <div class="d-flex justify-content-between align-items-center mb-1">
                                    <strong class="text-dark"><i class="bi bi-person-circle text-secondary me-1"></i><?= h($chat['ho_ten'] ?? 'Khách hàng') ?></strong>
                                    <span class="badge bg-info text-white rounded-pill small">Trả lời</span>
                                </div>
                                <div class="small text-muted text-truncate mb-1" style="max-width: 220px;">
                                    <?= h($chat['tin_nhan_moi'] ?? 'Đã gửi tin nhắn...') ?>
                                </div>
                            </a>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- 3. Product Reviews Queue -->
        <div class="col-12 col-lg-6 col-xl-3">
            <div class="staff-task-card">
                <div class="staff-task-head">
                    <div class="d-flex align-items-center gap-2">
                        <i class="bi bi-star-fill text-warning fs-5"></i>
                        <h6 class="fw-bold mb-0">Đánh Giá Cần Trả Lời</h6>
                    </div>
                    <a href="index.php?r=staff_reviews" class="small fw-bold text-decoration-none text-warning">Xem tất cả &rarr;</a>
                </div>
                <div class="card-body p-0 flex-grow-1">
                    <?php if (empty($reviews)): ?>
                        <div class="text-center text-muted py-5 px-3">
                            <i class="bi bi-star-fill text-warning fs-1 mb-2 d-block opacity-50"></i>
                            <div class="fw-semibold">Đã phản hồi hết tất cả đánh giá.</div>
                        </div>
                    <?php else: ?>
                        <?php foreach (array_slice($reviews, 0, 5) as $review): ?>
                            <a href="index.php?r=staff_reviews" class="staff-task-item">
                                <div class="fw-semibold text-dark text-truncate mb-1" style="max-width: 220px;">
                                    <?= h($review['ten_san_pham'] ?? 'Sản phẩm mỹ phẩm') ?>
                                </div>
                                <div class="d-flex align-items-center justify-content-between small text-muted">
                                    <span class="text-warning"><i class="bi bi-star-fill me-1"></i><?= (int)($review['so_sao'] ?? 5) ?>/5</span>
                                    <span class="text-truncate" style="max-width: 140px;"><?= h($review['noi_dung'] ?? '') ?></span>
                                </div>
                            </a>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- 4. Product Questions Queue -->
        <div class="col-12 col-lg-6 col-xl-3">
            <div class="staff-task-card">
                <div class="staff-task-head">
                    <div class="d-flex align-items-center gap-2">
                        <i class="bi bi-question-circle-fill text-success fs-5"></i>
                        <h6 class="fw-bold mb-0">Hỏi Đáp Cần Giải Đáp</h6>
                    </div>
                    <a href="index.php?r=admin_questions" class="small fw-bold text-decoration-none text-success">Xem tất cả &rarr;</a>
                </div>
                <div class="card-body p-0 flex-grow-1">
                    <?php if (empty($questions)): ?>
                        <div class="text-center text-muted py-5 px-3">
                            <i class="bi bi-patch-check-fill text-success fs-1 mb-2 d-block opacity-50"></i>
                            <div class="fw-semibold">Tất cả thắc mắc sản phẩm đã được giải đáp.</div>
                        </div>
                    <?php else: ?>
                        <?php foreach (array_slice($questions, 0, 5) as $qItem): ?>
                            <a href="index.php?r=admin_questions" class="staff-task-item">
                                <div class="d-flex justify-content-between align-items-center mb-1">
                                    <strong class="text-dark">#<?= h($qItem['ma_hoi_dap'] ?? '') ?> · <?= h($qItem['ten_khach_hang'] ?? 'Khách hàng') ?></strong>
                                    <span class="badge bg-success text-white rounded-pill small">Hỏi đáp</span>
                                </div>
                                <div class="small text-muted text-truncate" style="max-width: 220px;">
                                    <?= h($qItem['cau_hoi'] ?? '') ?>
                                </div>
                            </a>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>