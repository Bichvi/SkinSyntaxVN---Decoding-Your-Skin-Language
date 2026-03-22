<?php
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
        <p class="text-muted mb-0">Danh sách mới nhất ở bên trái, bấm chọn để mở chi tiết và trả lời ở bên phải.</p>
    </div>

    <form class="row g-2 mb-4" method="get" action="index.php" data-live-filter="true">
        <input type="hidden" name="r" value="staff_reviews">
        <div class="col-lg-3 col-md-6">
            <input type="text" class="form-control" name="q" value="<?= h($q) ?>" placeholder="Từ khóa sản phẩm/khách/nội dung">
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
            <input type="text" class="form-control" name="ma_van_don" value="<?= h($maVanDon) ?>" placeholder="Mã vận đơn">
        </div>
        <div class="col-lg-2 col-md-4">
            <input type="text" class="form-control" name="sdt_khach_hang" value="<?= h($sdtKhachHang) ?>" placeholder="SĐT khách hàng">
        </div>
        <div class="col-lg-2 col-md-6 d-grid">
            <button type="submit" class="btn btn-outline-primary">Lọc thông minh</button>
        </div>
        <div class="col-lg-2 col-md-6 d-grid">
            <a href="index.php?r=staff_reviews" class="btn btn-outline-secondary">Xóa bộ lọc</a>
        </div>
    </form>

    <div class="row g-4">
        <div class="col-xl-5">
            <div class="card border-0 shadow-sm rounded-4 h-100">
                <div class="card-body p-3 p-lg-4">
                    <h5 class="fw-bold mb-3">Danh sách phản hồi gần nhất</h5>
                    <?php if (empty($reviews)): ?>
                        <div class="text-muted text-center py-5">Chưa có phản hồi phù hợp bộ lọc.</div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-hover align-middle mb-0">
                                <thead class="table-light">
                                    <tr>
                                        <th>Mã PH</th>
                                        <th>Mã KH</th>
                                        <th>Mã vận đơn</th>
                                        <th>Khách hàng</th>
                                        <th>Số sao</th>
                                        <th>SĐT</th>
                                        <th>Tình trạng</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($reviews as $review): ?>
                                        <?php
                                        $itemId = (int)($review['ma_danh_gia'] ?? 0);
                                        $query = $baseQuery;
                                        $query['detail'] = (string)$itemId;
                                        $detailUrl = 'index.php?' . http_build_query(array_filter($query, static fn($v) => (string)$v !== ''));
                                        $isActive = $itemId > 0 && $itemId === $selectedReviewId;
                                        $daPhanHoi = trim((string)($review['phan_hoi'] ?? '')) !== '';
                                        ?>
                                        <tr class="<?= $isActive ? 'table-primary' : '' ?>" style="cursor:pointer" onclick="window.location.href='<?= h($detailUrl) ?>'">
                                            <td>#<?= h((string)($review['ma_danh_gia'] ?? '')) ?></td>
                                            <td>#<?= h((string)($review['ma_kh'] ?? '')) ?></td>
                                            <td>#<?= h((string)($review['ma_van_don'] ?? 'N/A')) ?></td>
                                            <td>
                                                <div class="fw-semibold"><?= h((string)($review['ten_khach_hang'] ?? 'Ẩn danh')) ?></div>
                                                <div class="small text-muted"><?= h((string)($review['email'] ?? '')) ?></div>
                                            </td>
                                            <td>
                                                <span class="badge text-bg-warning"><?= (int)($review['so_sao'] ?? 0) ?>/5</span>
                                            </td>
                                            <td><?= h((string)($review['sdt_khach_hang'] ?? 'N/A')) ?></td>
                                            <td>
                                                <span class="badge <?= $daPhanHoi ? 'text-bg-success' : 'text-bg-warning' ?>"><?= $daPhanHoi ? 'Đã xử lý' : 'Chưa xử lý' ?></span>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <div class="col-xl-7">
            <div class="card border-0 shadow-sm rounded-4 h-100">
                <div class="card-body p-3 p-lg-4">
                    <?php if (!$selectedReview): ?>
                        <div class="text-center text-muted py-5">Chọn một phản hồi ở danh sách bên trái để xem chi tiết.</div>
                    <?php else: ?>
                        <div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-3">
                            <div>
                                <h4 class="fw-bold mb-1">Chi tiết phản hồi khách hàng</h4>
                                <div class="fs-5 fw-semibold">Khách hàng: <?= h((string)($selectedReview['ten_khach_hang'] ?? 'Ẩn danh')) ?> · <?= (int)($selectedReview['so_sao'] ?? 0) ?>/5 sao</div>
                            </div>
                            <div class="text-end small text-muted">
                                <div>Mã phản hồi: #<?= h((string)($selectedReview['ma_danh_gia'] ?? 'N/A')) ?></div>
                                <div>Ngày gửi: <?= h(!empty($selectedReview['ngay_danh_gia']) ? date('d/m/Y H:i', strtotime((string)$selectedReview['ngay_danh_gia'])) : 'N/A') ?></div>
                            </div>
                        </div>

                        <div class="row g-2 small mb-3">
                            <div class="col-md-6"><strong>Mã khách hàng:</strong> #<?= h((string)($selectedReview['ma_kh'] ?? 'N/A')) ?></div>
                            <div class="col-md-6"><strong>Mã vận đơn:</strong> #<?= h((string)($selectedReview['ma_van_don'] ?? 'N/A')) ?></div>
                            <div class="col-md-6"><strong>Tên khách:</strong> <?= h((string)($selectedReview['ten_khach_hang'] ?? 'Ẩn danh')) ?></div>
                            <div class="col-md-6"><strong>SĐT:</strong> <?= h((string)($selectedReview['sdt_khach_hang'] ?? 'N/A')) ?></div>
                            <div class="col-md-12"><strong>Trạng thái đơn hàng:</strong> <?= h((string)($selectedReview['trang_thai_don_hang'] ?? 'Chưa xác định')) ?></div>
                        </div>

                        <div class="mb-3 p-3 bg-light rounded-3 border">
                            <div class="fw-semibold mb-1">Nội dung phản hồi từ khách hàng</div>
                            <div><?= nl2br_safe((string)($selectedReview['noi_dung'] ?? '')) ?></div>
                        </div>

                        <?php if (!empty($selectedReview['phan_hoi'])): ?>
                            <div class="mb-3 p-3 border rounded-3 bg-white">
                                <div class="fw-semibold mb-1">Lịch sử phản hồi đã gửi</div>
                                <div><?= nl2br_safe((string)($selectedReview['phan_hoi'] ?? '')) ?></div>
                                <div class="small text-muted mt-2">Nhân viên gần nhất: <?= h((string)($selectedReview['ten_nhan_vien_phan_hoi'] ?? 'N/A')) ?></div>
                            </div>
                        <?php endif; ?>

                        <form method="post" action="index.php?r=staff_review_reply" class="row g-2">
                            <input type="hidden" name="ma_danh_gia" value="<?= h((string)($selectedReview['ma_danh_gia'] ?? '')) ?>">
                            <input type="hidden" name="row_ref" value="<?= h((string)($selectedReview['row_ref'] ?? '')) ?>">
                            <input type="hidden" name="return_query" value="<?= h($returnQueryString) ?>">
                            <div class="col-12">
                                <label class="form-label fw-semibold"><?= !empty($selectedReview['phan_hoi']) ? 'Trả lời khách hàng (bổ sung)' : 'Trả lời khách hàng' ?></label>
                                <textarea class="form-control" name="phan_hoi" rows="4" placeholder="Nhập nội dung phản hồi..." required data-word-limit="1000"></textarea>
                                <div class="form-text" data-word-counter>Số từ: 0/1000</div>
                            </div>
                            <div class="col-md-4 d-grid">
                                <button type="submit" class="btn btn-primary" data-reply-submit><?= !empty($selectedReview['phan_hoi']) ? 'Gửi thêm phản hồi' : 'Gửi phản hồi' ?></button>
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
    if (!textarea || !counter || !submitBtn) {
        return;
    }

    var limit = parseInt(textarea.getAttribute('data-word-limit') || '1000', 10);
    if (!Number.isFinite(limit) || limit <= 0) {
        limit = 1000;
    }

    var countWords = function (value) {
        var text = (value || '').trim();
        if (text === '') {
            return 0;
        }
        return text.split(/\s+/u).filter(function (token) { return token !== ''; }).length;
    };

    var updateCounter = function () {
        var words = countWords(textarea.value);
        var isOverLimit = words > limit;
        counter.textContent = 'Số từ: ' + words + '/' + limit;
        counter.classList.toggle('text-danger', isOverLimit);
        submitBtn.disabled = isOverLimit;
        textarea.setCustomValidity(isOverLimit ? 'Nội dung phản hồi không được vượt quá ' + limit + ' từ.' : '');
    };

    textarea.addEventListener('input', updateCounter);
    updateCounter();
});
</script>