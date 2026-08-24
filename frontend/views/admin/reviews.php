<?php
use MongoDB\BSON\UTCDateTime;

$reviews = $reviews ?? [];
$selectedReview = $selectedReview ?? null;
$selectedReviewId = (int)($selectedReviewId ?? 0);
$q = trim((string)($q ?? ''));
$filters = $filters ?? [];
$filterOptions = $filterOptions ?? [];
$soSao = (int)($filters['so_sao'] ?? 0);
$trangThaiPhanHoi = trim((string)($filters['trang_thai_phan_hoi'] ?? ''));
$trangThaiDon = trim((string)($filters['trang_thai_don'] ?? ''));
$khoangNgay = trim((string)($filters['khoang_ngay'] ?? ''));
$maKh = trim((string)($filters['ma_kh'] ?? ''));
$maVanDon = trim((string)($filters['ma_van_don'] ?? ''));
$sdtKhachHang = trim((string)($filters['sdt_khach_hang'] ?? ''));
$limit = max(10, min(200, (int)($filters['limit'] ?? 60)));

$formatDate = static function ($value): string {
    if ($value instanceof UTCDateTime) {
        return $value->toDateTime()->setTimezone(new DateTimeZone('Asia/Ho_Chi_Minh'))->format('d/m/Y H:i');
    }
    $text = trim((string)($value ?? ''));
    if ($text === '' || $text === '0') return 'Chưa có ngày';
    $ts = strtotime($text);
    return ($ts !== false && $ts > 0) ? date('d/m/Y H:i', $ts) : 'Chưa có ngày';
};

$baseQuery = [
    'r' => 'staff_reviews',
    'q' => $q,
    'so_sao' => $soSao > 0 ? (string)$soSao : '',
    'trang_thai_phan_hoi' => $trangThaiPhanHoi,
    'trang_thai_don' => $trangThaiDon,
    'khoang_ngay' => $khoangNgay,
    'ma_kh' => $maKh,
    'ma_van_don' => $maVanDon,
    'sdt_khach_hang' => $sdtKhachHang,
    'limit' => (string)$limit,
];
$returnQuery = $baseQuery;
$returnQuery['detail'] = (string)$selectedReviewId;
unset($returnQuery['r']);
$returnQueryString = http_build_query(array_filter($returnQuery, static fn($v) => (string)$v !== ''));
?>

<div class="container-fluid px-4 py-4">
    <div class="d-flex flex-column flex-lg-row align-items-lg-center justify-content-between gap-3 mb-3">
        <div>
            <h1 class="h4 fw-bold mb-1" style="color: var(--admin-text);">Xử lý phản hồi Đánh giá sản phẩm</h1>
            <p class="text-muted mb-0 small">Ưu tiên phản hồi các đánh giá sao thấp, thắc mắc khách hàng và nhận xét mới nhất.</p>
        </div>
    </div>

    <div class="admin-card mb-3 p-3" style="border-radius: 8px !important;">
        <form class="row g-2" method="get" action="index.php" data-live-filter="true">
            <input type="hidden" name="r" value="staff_reviews">
            <div class="col-lg-3 col-md-6">
                <input type="text" class="form-control" name="q" value="<?= h($q) ?>" placeholder="Mã đánh giá, mã SP, tên SP..." style="border-radius: 6px; border-color: var(--admin-border); font-size: 0.85rem;">
            </div>
            <div class="col-lg-2 col-md-3">
                <select class="form-select" name="so_sao" style="border-radius: 6px; border-color: var(--admin-border); font-size: 0.85rem;">
                    <option value="">Tất cả sao</option>
                    <?php foreach ([5,4,3,2,1] as $star): ?>
                        <option value="<?= $star ?>" <?= $soSao === $star ? 'selected' : '' ?>><?= $star ?> Sao</option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-lg-2 col-md-3">
                <select class="form-select" name="trang_thai_phan_hoi" style="border-radius: 6px; border-color: var(--admin-border); font-size: 0.85rem;">
                    <option value="">Trạng thái phản hồi</option>
                    <option value="chua_phan_hoi" <?= $trangThaiPhanHoi === 'chua_phan_hoi' ? 'selected' : '' ?>>Chưa phản hồi</option>
                    <option value="da_phan_hoi" <?= $trangThaiPhanHoi === 'da_phan_hoi' ? 'selected' : '' ?>>Đã phản hồi</option>
                </select>
            </div>
            <div class="col-lg-2 col-md-4">
                <input type="text" class="form-control" name="ma_van_don" value="<?= h($maVanDon) ?>" placeholder="Mã đơn hàng" style="border-radius: 6px; border-color: var(--admin-border); font-size: 0.85rem;">
            </div>
            <div class="col-lg-3 col-md-8 d-flex gap-2">
                <button type="submit" class="btn text-white fw-semibold w-100" style="background: #183B2B; border-radius: 6px; font-size: 0.85rem;">Lọc đánh giá</button>
                <a href="index.php?r=staff_reviews" class="btn btn-outline-secondary fw-semibold text-nowrap" style="border-radius: 6px; font-size: 0.85rem;">Xóa lọc</a>
            </div>
        </form>
    </div>

    <div class="row g-4">
        <!-- Reviews List Panel (Left) -->
        <div class="col-xl-5">
            <div class="admin-card p-0 overflow-hidden mb-0" style="border-radius: 8px !important;">
                <div class="p-3 border-bottom background-subtle d-flex align-items-center justify-content-between">
                    <h6 class="fw-bold mb-0" style="color: var(--admin-text);">Danh sách đánh giá</h6>
                    <span class="badge bg-secondary-subtle text-secondary px-2 py-0.5" style="border-radius: 4px; font-size: 0.75rem;"><?= count($reviews) ?> đánh giá</span>
                </div>
                <div class="card-body p-0">
                    <?php if (empty($reviews)): ?>
                        <div class="text-muted text-center py-5">Hiện chưa có đánh giá nào cần phản hồi.</div>
                    <?php else: ?>
                        <div class="list-group list-group-flush">
                            <?php foreach ($reviews as $review): ?>
                                <?php
                                    $itemId = (int)($review['ma_danh_gia'] ?? 0);
                                    $query = $baseQuery;
                                    $query['detail'] = (string)$itemId;
                                    $detailUrl = 'index.php?' . http_build_query(array_filter($query, static fn($v) => (string)$v !== ''));
                                    $isActive = $itemId > 0 && $itemId === $selectedReviewId;
                                    $hasReply = trim((string)($review['phan_hoi'] ?? '')) !== '';
                                    $starCount = (int)($review['so_sao'] ?? 5);
                                ?>
                                <a class="list-group-item list-group-item-action p-3 border-bottom <?= $isActive ? 'bg-light border-start border-3 border-dark' : '' ?>" href="<?= h($detailUrl) ?>">
                                    <div class="d-flex gap-2.5 gap-2 align-items-start">
                                        <img src="<?= h(resolve_image_url($review['link_hinh_anh'] ?? '')) ?>" alt="" style="width:44px;height:44px;object-fit:cover;border-radius:6px;border:1px solid var(--admin-border);" onerror="this.style.display='none'">
                                        <div class="flex-grow-1 min-w-0">
                                            <div class="d-flex justify-content-between align-items-center mb-1">
                                                <div class="fw-semibold small" style="color: var(--admin-text);">#<?= h((string)$itemId) ?> · <span class="text-warning"><?= str_repeat('★', $starCount) ?></span></div>
                                                <span class="status-pill <?= $hasReply ? 'status-pill-completed' : 'status-pill-pending' ?>"><?= $hasReply ? 'Đã trả lời' : 'Chờ trả lời' ?></span>
                                            </div>
                                            <div class="small fw-semibold text-truncate mb-0.5" style="color: var(--admin-text); font-size: 0.82rem;"><?= h((string)($review['ten_khach_hang'] ?? 'Khách hàng')) ?></div>
                                            <div class="small text-muted text-truncate" style="font-size: 0.76rem;"><?= h((string)($review['ten_san_pham'] ?? 'Sản phẩm')) ?></div>
                                        </div>
                                    </div>
                                </a>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Detail & Reply Panel (Right) -->
        <div class="col-xl-7">
            <div class="admin-card p-3.5 mb-0" style="border-radius: 8px !important;">
                <?php if (!$selectedReview): ?>
                    <div class="text-center text-muted py-5 d-flex flex-column align-items-center justify-content-center">
                        <i class="bi bi-star-half fs-1 text-muted mb-2 opacity-50"></i>
                        <h6 class="fw-bold mb-1">Chưa chọn đánh giá nào</h6>
                        <p class="small text-muted mb-0">Bấm một đánh giá từ danh sách bên trái để xem nội dung và viết phản hồi.</p>
                    </div>
                <?php else: ?>
                    <?php $selectedProductId = (string)($selectedReview['ma_san_pham'] ?? ''); $productUrl = $selectedProductId !== '' ? 'index.php?r=chitiet&id=' . rawurlencode($selectedProductId) : '#'; ?>
                    <div class="d-flex flex-wrap justify-content-between align-items-start gap-3 mb-3 pb-2 border-bottom">
                        <div>
                            <h5 class="fw-bold mb-1" style="color: var(--admin-text);">Đánh giá #<?= h((string)($selectedReview['ma_danh_gia'] ?? '')) ?></h5>
                            <div class="small text-muted"><?= h((string)($selectedReview['ten_khach_hang'] ?? 'Khách hàng')) ?> · <span class="text-warning fw-bold"><?= (int)($selectedReview['so_sao'] ?? 0) ?>/5 sao</span></div>
                        </div>
                        <span class="status-pill <?= trim((string)($selectedReview['phan_hoi'] ?? '')) !== '' ? 'status-pill-completed' : 'status-pill-pending' ?>">
                            <?= trim((string)($selectedReview['phan_hoi'] ?? '')) !== '' ? 'Đã phản hồi' : 'Chưa phản hồi' ?>
                        </span>
                    </div>

                    <div class="p-3 mb-3 rounded" style="background: var(--admin-surface-subtle); border: 1px solid var(--admin-border);">
                        <div class="d-flex gap-3 align-items-start">
                            <img src="<?= h(resolve_image_url($selectedReview['link_hinh_anh'] ?? '')) ?>" alt="" style="width:64px;height:64px;object-fit:cover;border-radius:6px;border:1px solid var(--admin-border);" onerror="this.style.display='none'">
                            <div>
                                <div class="small text-muted" style="font-size: 0.76rem;">Mã SP: #<?= h((string)($selectedReview['ma_san_pham'] ?? '')) ?></div>
                                <div class="fw-semibold" style="color: var(--admin-text); font-size: 0.88rem;"><?= h((string)($selectedReview['ten_san_pham'] ?? 'Sản phẩm')) ?></div>
                                <div class="small text-muted" style="font-size: 0.78rem;"><?= h((string)($selectedReview['thuong_hieu'] ?? '')) ?></div>
                                <div class="mt-2 d-flex flex-wrap gap-2">
                                    <a class="btn btn-sm btn-outline-secondary px-2.5 py-1 <?= $selectedProductId === '' ? 'disabled' : '' ?>" href="<?= h($productUrl) ?>" target="_blank" style="border-radius: 4px; font-size: 0.78rem;"><i class="bi bi-box-arrow-up-right me-1"></i> Xem sản phẩm</a>
                                </div>
                            </div>
                        </div>
                            <div class="col-md-6"><strong>Ngày đánh giá:</strong> <?= h($formatDate($selectedReview['ngay_danh_gia'] ?? null)) ?></div>
                            <div class="col-md-6"><strong>Mã đơn:</strong> #<?= h((string)($selectedReview['ma_van_don'] ?? 'N/A')) ?></div>
                            <div class="col-md-6"><strong>Mã khách:</strong> #<?= h((string)($selectedReview['ma_kh'] ?? 'N/A')) ?></div>
                            <div class="col-md-6"><strong>SĐT:</strong> <?= h((string)($selectedReview['sdt_khach_hang'] ?? 'N/A')) ?></div>
                        </div>

                        <div class="mb-3 p-3 bg-light rounded-3 border">
                            <div class="fw-semibold mb-1">Nội dung đánh giá</div>
                            <div><?= nl2br_safe((string)($selectedReview['noi_dung'] ?? '')) ?></div>
                        </div>

                        <?php if (!empty($selectedReview['phan_hoi'])): ?>
                            <div class="mb-3 p-3 border rounded-3 bg-white">
                                <div class="fw-semibold mb-1">Phản hồi đã gửi</div>
                                <div><?= nl2br_safe((string)($selectedReview['phan_hoi'] ?? '')) ?></div>
                                <div class="small text-muted mt-2">Nhân viên: <?= h((string)($selectedReview['ten_nhan_vien_phan_hoi'] ?? 'N/A')) ?></div>
                            </div>
                        <?php endif; ?>

                        <form method="post" action="index.php?r=staff_review_reply" class="row g-2">
                            <input type="hidden" name="ma_danh_gia" value="<?= h((string)($selectedReview['ma_danh_gia'] ?? '')) ?>">
                            <input type="hidden" name="row_ref" value="<?= h((string)($selectedReview['_source'] ?? 'danh_gia_san_pham')) ?>">
                            <input type="hidden" name="return_query" value="<?= h($returnQueryString) ?>">
                            <div class="col-12">
                                <label class="form-label fw-semibold"><?= !empty($selectedReview['phan_hoi']) ? 'Cập nhật phản hồi' : 'Phản hồi đánh giá' ?></label>
                                <textarea class="form-control" name="phan_hoi" rows="4" placeholder="Nhập nội dung phản hồi..." required data-word-limit="1000"></textarea>
                                <div class="form-text" data-word-counter>Số từ: 0/1000</div>
                            </div>
                            <div class="col-md-4 d-grid">
                                <button type="submit" class="btn btn-primary" data-reply-submit>Gửi phản hồi</button>
                            </div>
                        </form>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    var textarea = document.querySelector('textarea[name="phan_hoi"][data-word-limit]');
    var counter = document.querySelector('[data-word-counter]');
    var submitBtn = document.querySelector('[data-reply-submit]');
    if (!textarea || !counter || !submitBtn) return;
    var limit = parseInt(textarea.getAttribute('data-word-limit') || '1000', 10);
    var updateCounter = function () {
        var words = (textarea.value || '').trim() === '' ? 0 : textarea.value.trim().split(/\s+/u).length;
        counter.textContent = 'Số từ: ' + words + '/' + limit;
        counter.classList.toggle('text-danger', words > limit);
        submitBtn.disabled = words > limit;
        textarea.setCustomValidity(words > limit ? 'Nội dung phản hồi không được vượt quá ' + limit + ' từ.' : '');
    };
    textarea.addEventListener('input', updateCounter);
    updateCounter();
});
</script>

