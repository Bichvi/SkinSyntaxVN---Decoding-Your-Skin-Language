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

<div class="container-fluid p-4">
    <div class="mb-4">
        <h1 class="h3 mb-1">Xử lý phản hồi đánh giá sản phẩm</h1>
        <p class="text-muted mb-0">Ưu tiên đánh giá chưa phản hồi, sao thấp và gửi sớm nhất.</p>
    </div>

    <form class="row g-2 mb-4" method="get" action="index.php" data-live-filter="true">
        <input type="hidden" name="r" value="staff_reviews">
        <div class="col-lg-3 col-md-6">
            <input type="text" class="form-control" name="q" value="<?= h($q) ?>" placeholder="Mã đánh giá, mã SP, tên SP, khách, số sao">
        </div>
        <div class="col-lg-2 col-md-3">
            <select class="form-select" name="so_sao">
                <option value="">Tất cả sao</option>
                <?php foreach (($filterOptions['so_sao'] ?? []) as $sao): ?>
                    <option value="<?= (int)$sao ?>" <?= $soSao === (int)$sao ? 'selected' : '' ?>><?= (int)$sao ?> sao</option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-lg-2 col-md-3">
            <select class="form-select" name="trang_thai_phan_hoi">
                <option value="">Tất cả phản hồi</option>
                <?php foreach (($filterOptions['trang_thai_phan_hoi'] ?? []) as $value => $label): ?>
                    <option value="<?= h((string)$value) ?>" <?= $trangThaiPhanHoi === (string)$value ? 'selected' : '' ?>><?= h((string)$label) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-lg-2 col-md-3">
            <select class="form-select" name="trang_thai_don">
                <option value="">Tất cả trạng thái đơn</option>
                <?php foreach (($filterOptions['trang_thai_don'] ?? []) as $value => $label): ?>
                    <option value="<?= h((string)$value) ?>" <?= $trangThaiDon === (string)$value ? 'selected' : '' ?>><?= h((string)$label) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-lg-2 col-md-3">
            <select class="form-select" name="khoang_ngay">
                <option value="">Tất cả thời gian</option>
                <?php foreach (($filterOptions['khoang_ngay'] ?? []) as $value => $label): ?>
                    <option value="<?= h((string)$value) ?>" <?= $khoangNgay === (string)$value ? 'selected' : '' ?>><?= h((string)$label) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-lg-1 col-md-3">
            <input type="number" min="10" max="200" class="form-control" name="limit" value="<?= (int)$limit ?>" title="Số dòng">
        </div>
        <div class="col-lg-2 col-md-4">
            <input type="text" class="form-control" name="ma_kh" value="<?= h($maKh) ?>" placeholder="Mã KH">
        </div>
        <div class="col-lg-2 col-md-4">
            <input type="text" class="form-control" name="ma_van_don" value="<?= h($maVanDon) ?>" placeholder="Mã đơn">
        </div>
        <div class="col-lg-2 col-md-4">
            <input type="text" class="form-control" name="sdt_khach_hang" value="<?= h($sdtKhachHang) ?>" placeholder="SĐT khách hàng">
        </div>
        <div class="col-lg-2 col-md-6 d-grid"><button type="submit" class="btn btn-outline-primary">Lọc</button></div>
        <div class="col-lg-2 col-md-6 d-grid"><a href="index.php?r=staff_reviews" class="btn btn-outline-secondary">Xóa bộ lọc</a></div>
    </form>

    <div class="row g-4">
        <div class="col-xl-5">
            <div class="card border-0 shadow-sm rounded-4 h-100">
                <div class="card-body p-3 p-lg-4">
                    <h5 class="fw-bold mb-3">Đánh giá cần phản hồi</h5>
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
                                ?>
                                <a class="list-group-item list-group-item-action <?= $isActive ? 'active' : '' ?>" href="<?= h($detailUrl) ?>">
                                    <div class="d-flex gap-3">
                                        <img src="<?= h(resolve_image_url($review['link_hinh_anh'] ?? '')) ?>" alt="" style="width:56px;height:56px;object-fit:cover;border-radius:12px;background:#f1f5f9" onerror="this.style.display='none'">
                                        <div class="flex-grow-1">
                                            <div class="d-flex justify-content-between gap-2">
                                                <div class="fw-semibold">#<?= h((string)$itemId) ?> · <?= (int)($review['so_sao'] ?? 0) ?> sao</div>
                                                <span class="badge <?= $hasReply ? 'text-bg-success' : 'text-bg-warning' ?>"><?= $hasReply ? 'Đã phản hồi' : 'Chưa phản hồi' ?></span>
                                            </div>
                                            <div class="small"><?= h((string)($review['ten_khach_hang'] ?? 'Khách hàng')) ?></div>
                                            <div class="small text-muted">SP #<?= h((string)($review['ma_san_pham'] ?? '')) ?> · <?= h((string)($review['ten_san_pham'] ?? 'Không tìm thấy sản phẩm')) ?></div>
                                            <div class="small text-muted"><?= h($formatDate($review['ngay_danh_gia'] ?? null)) ?></div>
                                        </div>
                                    </div>
                                </a>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <div class="col-xl-7">
            <div class="card border-0 shadow-sm rounded-4 h-100">
                <div class="card-body p-3 p-lg-4">
                    <?php if (!$selectedReview): ?>
                        <div class="text-center text-muted py-5">Chọn một đánh giá để xem chi tiết và phản hồi.</div>
                    <?php else: ?>
                        <?php $selectedProductId = (string)($selectedReview['ma_san_pham'] ?? ''); $productUrl = $selectedProductId !== '' ? 'index.php?r=chitiet&id=' . rawurlencode($selectedProductId) : '#'; ?>
                        <div class="d-flex flex-wrap justify-content-between align-items-start gap-3 mb-3">
                            <div>
                                <h4 class="fw-bold mb-1">Đánh giá #<?= h((string)($selectedReview['ma_danh_gia'] ?? '')) ?></h4>
                                <div class="fs-6"><?= h((string)($selectedReview['ten_khach_hang'] ?? 'Khách hàng')) ?> · <?= (int)($selectedReview['so_sao'] ?? 0) ?>/5 sao</div>
                            </div>
                            <span class="badge <?= trim((string)($selectedReview['phan_hoi'] ?? '')) !== '' ? 'text-bg-success' : 'text-bg-warning' ?>">
                                <?= trim((string)($selectedReview['phan_hoi'] ?? '')) !== '' ? 'Đã phản hồi' : 'Chưa phản hồi' ?>
                            </span>
                        </div>

                        <div class="border rounded-4 p-3 mb-3">
                            <div class="d-flex gap-3 align-items-start">
                                <img src="<?= h(resolve_image_url($selectedReview['link_hinh_anh'] ?? '')) ?>" alt="" style="width:84px;height:84px;object-fit:cover;border-radius:14px;background:#f1f5f9" onerror="this.style.display='none'">
                                <div>
                                    <div class="small text-muted">SP #<?= h((string)($selectedReview['ma_san_pham'] ?? '')) ?></div>
                                    <div class="fw-semibold"><?= h((string)($selectedReview['ten_san_pham'] ?? 'Không tìm thấy thông tin sản phẩm')) ?></div>
                                    <div class="small text-muted"><?= h((string)($selectedReview['thuong_hieu'] ?? '')) ?></div>
                                    <?php if (!empty($selectedReview['product_missing'])): ?><div class="small text-warning mt-1">Không tìm thấy thông tin sản phẩm</div><?php endif; ?>
                                    <div class="mt-2 d-flex flex-wrap gap-2">
                                        <a class="btn btn-sm btn-outline-primary <?= $selectedProductId === '' ? 'disabled' : '' ?>" href="<?= h($productUrl) ?>" target="_blank">Xem sản phẩm</a>
                                        <a class="btn btn-sm btn-outline-success" href="<?= h($selectedProductId !== '' ? $productUrl . '#danhgia' : '#') ?>" target="_blank">Xem đánh giá trên trang sản phẩm</a>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="row g-2 small mb-3">
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

