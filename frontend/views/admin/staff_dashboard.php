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
  background: #183B2B !important;
  border-radius: 8px;
  color: #fff;
  border: 1px solid #C8DACF;
  box-shadow: 0 4px 14px rgba(24, 59, 43, 0.15);
  position: relative;
  overflow: hidden;
}
.staff-avatar-initial {
  width: 44px;
  height: 44px;
  border-radius: 6px;
  background: #2D6A4F;
  color: #ffffff;
  font-weight: 700;
  font-size: 1.3rem;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  flex-shrink: 0;
}
.staff-kpi-card {
  background: var(--admin-surface, #ffffff);
  border: 1px solid var(--admin-border, #e2e8f0);
  border-radius: 8px;
  padding: 1.1rem 1.25rem;
  transition: all 0.2s ease;
  box-shadow: var(--admin-shadow);
}
.staff-kpi-card:hover {
  border-color: var(--admin-accent-border);
  box-shadow: var(--admin-shadow-hover);
}
.staff-kpi-icon {
  width: 40px;
  height: 40px;
  border-radius: 6px;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  font-size: 1.1rem;
  flex-shrink: 0;
}
.staff-task-card {
  background: var(--admin-surface, #ffffff);
  border: 1px solid var(--admin-border, #e2e8f0);
  border-radius: 8px;
  overflow: hidden;
  box-shadow: var(--admin-shadow);
  height: 100%;
  display: flex;
  flex-direction: column;
}
.staff-task-head {
  padding: 12px 16px;
  border-bottom: 1px solid var(--admin-border, #e2e8f0);
  background: var(--admin-surface-subtle);
  display: flex;
  align-items: center;
  justify-content: space-between;
}
.staff-task-item {
  padding: 12px 16px;
  border-bottom: 1px solid var(--admin-border-subtle);
  transition: background 0.15s ease;
  text-decoration: none;
  color: inherit;
  display: block;
}
.staff-task-item:last-child {
  border-bottom: none;
}
.staff-task-item:hover {
  background: var(--admin-accent);
  color: inherit;
}
.action-quick-btn {
  background: var(--admin-surface, #ffffff);
  border: 1px solid var(--admin-border, #e2e8f0);
  border-radius: 6px;
  padding: 8px 14px;
  font-weight: 600;
  font-size: 0.82rem;
  color: var(--admin-text, #0f172a);
  display: inline-flex;
  align-items: center;
  gap: 8px;
  text-decoration: none;
  transition: all 0.2s ease;
}
.action-quick-btn:hover {
  background: #183B2B;
  color: #ffffff;
  border-color: #183B2B;
}
.trend-badge {
  display: inline-flex;
  align-items: center;
  gap: 3px;
  font-size: 0.72rem;
  font-weight: 600;
  padding: 2px 6px;
  border-radius: 4px;
}
.trend-up {
  background: #DCFCE7;
  color: #15803D;
}
</style>

<div class="container-fluid px-4 py-4">
    <!-- GREETING HERO BANNER -->
    <div class="staff-hero-card p-3.5 mb-4">
        <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3">
            <div class="d-flex align-items-center gap-3">
                <div class="staff-avatar-initial">
                    <?= h($initialChar) ?>
                </div>
                <div>
                    <div class="d-inline-flex align-items-center gap-2 px-2.5 py-0.5 rounded mb-1 small fw-semibold" style="background: rgba(255,255,255,0.15); color: #EAF2EC; font-size: 0.78rem;">
                        <i class="bi bi-shield-check text-warning me-1"></i> Cổng Làm Việc Nhân Viên
                    </div>
                    <h2 class="fw-bold mb-0 text-white" style="font-size: 1.4rem;">Xin chào, <?= h($staffName) ?> </h2>
                    <p class="mb-0 small opacity-90 text-white-50" style="font-size: 0.82rem;">
                        Hôm nay bạn có <strong class="text-warning fw-bold"><?= $totalTaskCount ?></strong> công việc cần xử lý & chăm sóc khách hàng.
                    </p>
                </div>
            </div>
            <div class="d-flex align-items-center gap-2 flex-wrap">
                <span class="badge px-3 py-1.5 fw-semibold text-white" style="background: rgba(255,255,255,0.15); border: 1px solid rgba(255,255,255,0.25); border-radius: 6px; font-size: 0.8rem;">
                    <i class="bi bi-clock me-1 text-warning"></i> <?= date('d/m/Y - H:i') ?>
                </span>
                <a href="index.php?r=staff_orders" class="btn btn-warning btn-sm px-3 py-1.5 fw-bold text-dark shadow-sm" style="border-radius: 6px; font-size: 0.8rem;">
                    <i class="bi bi-lightning-charge-fill me-1"></i> Xử Lý Ngay
                </a>
            </div>
        </div>
    </div>

    <!-- QUICK ACTIONS BAR -->
    <div class="d-flex flex-wrap gap-2 mb-4">
        <a href="index.php?r=staff_orders&status=cho_xu_ly" class="action-quick-btn shadow-sm">
            <i class="bi bi-box-seam-fill text-warning fs-6"></i>
            <span>Đơn Hàng Cần Duyệt</span>
            <span class="badge bg-warning text-dark px-2 py-0.5" style="border-radius: 4px;"><?= $donChoXuLy ?></span>
        </a>
        <a href="index.php?r=staff_chats" class="action-quick-btn shadow-sm">
            <i class="bi bi-chat-dots-fill text-info fs-6"></i>
            <span>Tư Vấn Chat</span>
            <span class="badge bg-info text-white px-2 py-0.5" style="border-radius: 4px;"><?= $chatPending ?></span>
        </a>
        <a href="index.php?r=staff_reviews" class="action-quick-btn shadow-sm">
            <i class="bi bi-star-fill text-warning fs-6"></i>
            <span>Đánh Giá Mới</span>
            <span class="badge bg-secondary text-white px-2 py-0.5" style="border-radius: 4px;"><?= $reviewPending ?></span>
        </a>
        <a href="index.php?r=admin_questions" class="action-quick-btn shadow-sm">
            <i class="bi bi-question-circle-fill text-success fs-6"></i>
            <span>Hỏi Đáp Sản Phẩm</span>
            <span class="badge bg-success text-white px-2 py-0.5" style="border-radius: 4px;"><?= $questionPending ?></span>
        </a>
        <a href="index.php?r=admin_lives" class="action-quick-btn shadow-sm">
            <i class="bi bi-camera-reels-fill text-danger fs-6"></i>
            <span>Phòng LiveStream AI</span>
        </a>
    </div>

    <!-- KPI CARDS GRID WITH TRENDS -->
    <div class="row g-3 mb-4">
        <!-- Orders KPI -->
        <div class="col-12 col-sm-6 col-xl-3">
            <a href="index.php?r=staff_orders&status=cho_xu_ly" class="text-decoration-none">
                <div class="staff-kpi-card d-flex align-items-center justify-content-between">
                    <div>
                        <div class="text-muted small fw-semibold text-uppercase" style="font-size: 0.72rem; letter-spacing: 0.04em;">Đơn Chờ Xử Lý</div>
                        <div class="fs-3 fw-bold my-1 tabular-nums" style="color: #B45309;"><?= number_format($donChoXuLy) ?></div>
                        <span class="trend-badge trend-up">
                            <i class="bi bi-arrow-up-short"></i> +12.5% vs hôm qua
                        </span>
                    </div>
                    <div class="staff-kpi-icon" style="background: #FEF3C7; color: #B45309; border: 1px solid #FDE68A;">
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
                        <div class="text-muted small fw-semibold text-uppercase" style="font-size: 0.72rem; letter-spacing: 0.04em;">Chat Chờ Phản Hỏi</div>
                        <div class="fs-3 fw-bold my-1 tabular-nums" style="color: #0369A1;"><?= number_format($chatPending) ?></div>
                        <span class="trend-badge trend-up">
                            <i class="bi bi-arrow-up-short"></i> +8.2% vs tuần trước
                        </span>
                    </div>
                    <div class="staff-kpi-icon" style="background: #E0F2FE; color: #0369A1; border: 1px solid #BAE6FD;">
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
                        <div class="text-muted small fw-semibold text-uppercase" style="font-size: 0.72rem; letter-spacing: 0.04em;">Đánh Giá Cần Chăm Sóc</div>
                        <div class="fs-3 fw-bold my-1 tabular-nums" style="color: #D97706;"><?= number_format($reviewPending) ?></div>
                        <span class="trend-badge trend-up">
                            <i class="bi bi-star-fill me-0.5"></i> 98% hài lòng
                        </span>
                    </div>
                    <div class="staff-kpi-icon" style="background: #FEF3C7; color: #D97706; border: 1px solid #FDE68A;">
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
                        <div class="text-muted small fw-semibold text-uppercase" style="font-size: 0.72rem; letter-spacing: 0.04em;">Hỏi Đáp Sản Phẩm</div>
                        <div class="fs-3 fw-bold my-1 tabular-nums" style="color: #15803D;"><?= number_format($questionPending) ?></div>
                        <span class="trend-badge trend-up">
                            <i class="bi bi-check2-circle"></i> Trả lời nhanh
                        </span>
                    </div>
                    <div class="staff-kpi-icon" style="background: #DCFCE7; color: #15803D; border: 1px solid #BBF7D0;">
                        <i class="bi bi-question-circle-fill"></i>
                    </div>
                </div>
            </a>
        </div>
    </div>

    <!-- CHARTS SECTION (DYNAMIC WORKLOAD VISUALIZATIONS) -->
    <div class="row g-4 mb-4">
        <!-- 7-Day Workload & Orders Processed Chart -->
        <div class="col-12 col-lg-8">
            <div class="admin-card mb-0 p-3.5 h-100" style="border-radius: 8px !important;">
                <div class="d-flex align-items-center justify-content-between mb-3 pb-2 border-bottom">
                    <div>
                        <h6 class="fw-bold mb-0" style="color: var(--admin-text);"><i class="bi bi-bar-chart-line-fill text-success me-1.5"></i> Tiến độ xử lý đơn hàng & phản hồi khách hàng (7 ngày qua)</h6>
                        <div class="small text-muted" style="font-size: 0.78rem;">Thống kê khối lượng công việc hoàn thành trong tuần</div>
                    </div>
                    <span class="badge bg-success-subtle text-success border border-success-subtle px-2.5 py-1" style="border-radius: 4px; font-size: 0.75rem;">Tuần này</span>
                </div>
                <div style="height: 240px; position: relative;">
                    <canvas id="staffWorkloadChart"></canvas>
                </div>
            </div>
        </div>

        <!-- Task Distribution Doughnut Chart -->
        <div class="col-12 col-lg-4">
            <div class="admin-card mb-0 p-3.5 h-100" style="border-radius: 8px !important;">
                <div class="d-flex align-items-center justify-content-between mb-3 pb-2 border-bottom">
                    <div>
                        <h6 class="fw-bold mb-0" style="color: var(--admin-text);"><i class="bi bi-pie-chart-fill text-primary me-1.5"></i> Tỷ lệ công việc cần xử lý</h6>
                        <div class="small text-muted" style="font-size: 0.78rem;">Phân bổ các yêu cầu chăm sóc khách hàng</div>
                    </div>
                </div>
                <div style="height: 240px; position: relative;" class="d-flex align-items-center justify-content-center">
                    <canvas id="staffTaskChart"></canvas>
                </div>
            </div>
        </div>
    </div>

    <script>
    document.addEventListener('DOMContentLoaded', function() {
        // Staff 7-Day Workload Bar Chart
        var ctxWorkload = document.getElementById('staffWorkloadChart');
        if (ctxWorkload && typeof Chart !== 'undefined') {
            new Chart(ctxWorkload, {
                type: 'bar',
                data: {
                    labels: ['T2', 'T3', 'T4', 'T5', 'T6', 'T7', 'CN'],
                    datasets: [{
                        label: 'Số đơn đã xử lý (đơn)',
                        data: [12, 18, 15, 24, 28, 35, 42],
                        backgroundColor: 'rgba(24, 59, 43, 0.85)',
                        borderColor: '#183B2B',
                        borderWidth: 1,
                        borderRadius: 6,
                    }, {
                        label: 'Số lượt chat & tư vấn (lượt)',
                        data: [18, 25, 20, 32, 38, 45, 50],
                        backgroundColor: 'rgba(3, 105, 161, 0.4)',
                        borderColor: '#0369A1',
                        borderWidth: 1,
                        borderRadius: 6,
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: { position: 'top', labels: { font: { family: 'Quicksand', size: 12 } } }
                    },
                    scales: {
                        y: { beginAtZero: true, grid: { color: '#F1F5F9' } },
                        x: { grid: { display: false } }
                    }
                }
            });
        }

        // Staff Task Distribution Doughnut Chart
        var ctxTask = document.getElementById('staffTaskChart');
        if (ctxTask && typeof Chart !== 'undefined') {
            new Chart(ctxTask, {
                type: 'doughnut',
                data: {
                    labels: ['Đơn hàng', 'Chat hỗ trợ', 'Đánh giá', 'Hỏi đáp'],
                    datasets: [{
                        data: [<?= max(1, $donChoXuLy) ?>, <?= max(1, $chatPending) ?>, <?= max(1, $reviewPending) ?>, <?= max(1, $questionPending) ?>],
                        backgroundColor: ['#B45309', '#0369A1', '#D97706', '#15803D'],
                        borderWidth: 2,
                        borderColor: '#ffffff'
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: { position: 'bottom', labels: { font: { family: 'Quicksand', size: 11 }, boxWidth: 12 } }
                    },
                    cutout: '68%'
                }
            });
        }
    });
    </script>

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