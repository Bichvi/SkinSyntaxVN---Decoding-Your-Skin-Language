<?php
use MongoDB\BSON\UTCDateTime;

$questions = $questions ?? [];
$status = trim((string)($status ?? ''));
$q = trim((string)($q ?? ''));
$error = trim((string)($error ?? ''));
$formatQuestionDate = static function ($value): string {
    if ($value instanceof UTCDateTime) {
        return $value->toDateTime()->setTimezone(new DateTimeZone('Asia/Ho_Chi_Minh'))->format('d/m/Y H:i');
    }
    $text = trim((string)($value ?? ''));
    if ($text === '' || $text === '0') return 'Chưa có ngày';
    $timestamp = strtotime($text);
    return ($timestamp !== false && $timestamp > 0) ? date('d/m/Y H:i', $timestamp) : 'Chưa có ngày';
};
?>

<div class="container-fluid p-4">
    <div class="d-flex flex-column flex-lg-row justify-content-between align-items-lg-center gap-3 mb-4">
        <div>
            <h1 class="h3 mb-1">Hỏi đáp sản phẩm</h1>
            <p class="text-muted mb-0">Xem, trả lời và ẩn câu hỏi của khách hàng theo từng sản phẩm.</p>
        </div>
    </div>

    <?php if ($error !== ''): ?>
        <div class="alert alert-warning"><?= h($error) ?></div>
    <?php endif; ?>

    <form class="row g-2 mb-4" method="get" action="index.php">
        <input type="hidden" name="r" value="admin_questions">
        <div class="col-md-5">
            <input class="form-control" name="q" value="<?= h($q) ?>" placeholder="Mã hỏi đáp, mã SP, tên SP, khách hàng, nội dung hỏi...">
        </div>
        <div class="col-md-3">
            <select class="form-select" name="status">
                <option value="" <?= $status === '' ? 'selected' : '' ?>>Tất cả câu hỏi</option>
                <option value="pending" <?= $status === 'pending' ? 'selected' : '' ?>>Chưa trả lời</option>
                <option value="answered" <?= $status === 'answered' ? 'selected' : '' ?>>Đã trả lời</option>
                <option value="hidden" <?= $status === 'hidden' ? 'selected' : '' ?>>Đã ẩn</option>
            </select>
        </div>
        <div class="col-md-2 d-grid"><button class="btn btn-primary" type="submit">Lọc</button></div>
        <div class="col-md-2 d-grid"><a class="btn btn-outline-secondary" href="index.php?r=admin_questions">Xóa</a></div>
    </form>

    <div class="card border-0 shadow-sm rounded-4">
        <div class="card-body p-0">
            <?php if (empty($questions)): ?>
                <div class="text-center text-muted py-5">Chưa có câu hỏi sản phẩm nào phù hợp.</div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th style="width: 90px;">Mã</th>
                                <th>Sản phẩm</th>
                                <th>Khách hàng / câu hỏi</th>
                                <th>Trạng thái</th>
                                <th style="width: 360px;">Trả lời</th>
                                <th class="text-end">Ẩn</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($questions as $question): ?>
                                <?php
                                    $answer = $question['tra_loi'] ?? null;
                                    if (is_object($answer)) $answer = (array)$answer;
                                    $answered = is_array($answer) && trim((string)($answer['noi_dung'] ?? '')) !== '';
                                    $isHidden = (string)($question['trang_thai'] ?? '') === 'an';
                                    $productId = (string)($question['ma_san_pham'] ?? '');
                                    $productUrl = $productId !== '' ? 'index.php?r=chitiet&id=' . rawurlencode($productId) : '#';
                                ?>
                                <tr>
                                    <td>#<?= h((string)($question['ma_hoi_dap'] ?? '')) ?></td>
                                    <td style="min-width: 260px;">
                                        <div class="d-flex gap-3 align-items-start">
                                            <img src="<?= h(resolve_image_url($question['link_hinh_anh'] ?? '')) ?>" alt="" style="width:64px;height:64px;object-fit:cover;border-radius:12px;background:#f1f5f9" onerror="this.style.display='none'">
                                            <div>
                                                <div class="fw-semibold"><?= h((string)($question['ten_san_pham'] ?? 'SP #' . $productId)) ?></div>
                                                <div class="small text-muted">SP #<?= h($productId) ?><?= !empty($question['thuong_hieu']) ? ' · ' . h((string)$question['thuong_hieu']) : '' ?></div>
                                                <?php if (!empty($question['product_missing'])): ?><div class="small text-warning">Không tìm thấy thông tin sản phẩm</div><?php endif; ?>
                                                <div class="mt-2 d-flex flex-wrap gap-2">
                                                    <a class="btn btn-sm btn-outline-primary <?= $productId === '' ? 'disabled' : '' ?>" href="<?= h($productUrl) ?>" target="_blank">Xem sản phẩm</a>
                                                    <a class="btn btn-sm btn-outline-success" href="<?= h($productId !== '' ? $productUrl . '#hoidap' : '#') ?>" target="_blank">Xem hỏi đáp trên trang sản phẩm</a>
                                                </div>
                                            </div>
                                        </div>
                                    </td>
                                    <td style="min-width: 280px;">
                                        <div class="fw-semibold"><?= h((string)($question['ten_khach_hang'] ?? 'Khách hàng')) ?></div>
                                        <div class="small text-muted mb-2"><?= h($formatQuestionDate($question['ngay_hoi'] ?? '')) ?></div>
                                        <div><?= nl2br_safe((string)($question['cau_hoi'] ?? '')) ?></div>
                                    </td>
                                    <td>
                                        <?php if ($isHidden): ?>
                                            <span class="badge text-bg-secondary">Đã ẩn</span>
                                        <?php elseif ($answered): ?>
                                            <span class="badge text-bg-success">Đã trả lời</span>
                                        <?php else: ?>
                                            <span class="badge text-bg-warning">Chưa trả lời</span>
                                        <?php endif; ?>
                                        <?php if ($answered): ?>
                                            <div class="small text-muted mt-2"><?= nl2br_safe((string)($answer['noi_dung'] ?? '')) ?></div>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if (!$isHidden): ?>
                                            <form method="post" action="index.php?r=admin_question_reply" class="d-grid gap-2">
                                                <input type="hidden" name="ma_hoi_dap" value="<?= h((string)($question['ma_hoi_dap'] ?? '')) ?>">
                                                <textarea class="form-control" name="tra_loi" rows="3" placeholder="Nhập câu trả lời..." required></textarea>
                                                <button class="btn btn-sm btn-primary" type="submit"><?= $answered ? 'Cập nhật trả lời' : 'Gửi trả lời' ?></button>
                                            </form>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-end">
                                        <?php if (!$isHidden): ?>
                                            <form method="post" action="index.php?r=admin_question_hide" onsubmit="return confirm('Ẩn câu hỏi này?');">
                                                <input type="hidden" name="ma_hoi_dap" value="<?= h((string)($question['ma_hoi_dap'] ?? '')) ?>">
                                                <button class="btn btn-sm btn-outline-danger" type="submit">Ẩn</button>
                                            </form>
                                        <?php endif; ?>
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

