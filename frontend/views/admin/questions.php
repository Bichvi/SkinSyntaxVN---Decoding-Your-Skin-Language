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

<div class="container-fluid px-4 py-4">
    <div class="d-flex flex-column flex-lg-row align-items-lg-center justify-content-between gap-3 mb-3">
        <div>
            <h1 class="h4 fw-bold mb-1" style="color: var(--admin-text);">Quản lý Hỏi đáp sản phẩm</h1>
            <p class="text-muted mb-0 small">Giải đáp thắc mắc của khách hàng trên trang chi tiết sản phẩm và quản lý hiển thị.</p>
        </div>
    </div>

    <?php if ($error !== ''): ?>
        <div class="alert alert-warning p-3 rounded-3 mb-3"><?= h($error) ?></div>
    <?php endif; ?>

    <div class="admin-card mb-3 p-3" style="border-radius: 8px !important;">
        <form class="row g-2" method="get" action="index.php">
            <input type="hidden" name="r" value="admin_questions">
            <div class="col-md-5">
                <input class="form-control" name="q" value="<?= h($q) ?>" placeholder="Mã hỏi đáp, mã SP, tên SP, khách hàng, nội dung..." style="border-radius: 6px; border-color: var(--admin-border); font-size: 0.85rem;">
            </div>
            <div class="col-md-3">
                <select class="form-select" name="status" style="border-radius: 6px; border-color: var(--admin-border); font-size: 0.85rem;">
                    <option value="" <?= $status === '' ? 'selected' : '' ?>>Tất cả câu hỏi</option>
                    <option value="pending" <?= $status === 'pending' ? 'selected' : '' ?>>Chưa trả lời</option>
                    <option value="answered" <?= $status === 'answered' ? 'selected' : '' ?>>Đã trả lời</option>
                    <option value="hidden" <?= $status === 'hidden' ? 'selected' : '' ?>>Đã ẩn</option>
                </select>
            </div>
            <div class="col-md-2 d-grid">
                <button class="btn text-white fw-semibold" type="submit" style="background: #183B2B; border-radius: 6px; font-size: 0.85rem;">Lọc câu hỏi</button>
            </div>
            <div class="col-md-2 d-grid">
                <a class="btn btn-outline-secondary fw-semibold" href="index.php?r=admin_questions" style="border-radius: 6px; font-size: 0.85rem;">Xóa lọc</a>
            </div>
        </form>
    </div>

    <div class="admin-card p-0 overflow-hidden mb-0" style="border-radius: 8px !important;">
        <div class="card-body p-0">
            <?php if (empty($questions)): ?>
                <div class="text-center text-muted py-5">Chưa có câu hỏi sản phẩm nào phù hợp.</div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table admin-table align-middle mb-0">
                        <thead>
                            <tr>
                                <th style="width: 80px;">Mã</th>
                                <th>Sản phẩm</th>
                                <th>Khách hàng & Câu hỏi</th>
                                <th>Trạng thái</th>
                                <th style="width: 320px;">Gửi phản hồi</th>
                                <th class="text-end">Thao tác</th>
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
                                    <td><code class="fw-bold" style="color: #183B2B;">#<?= h((string)($question['ma_hoi_dap'] ?? '')) ?></code></td>
                                    <td style="min-width: 240px;">
                                        <div class="d-flex gap-2.5 gap-2 align-items-start">
                                            <img src="<?= h(resolve_image_url($question['link_hinh_anh'] ?? '')) ?>" alt="" style="width:48px;height:48px;object-fit:cover;border-radius:6px;border:1px solid var(--admin-border);" onerror="this.style.display='none'">
                                            <div>
                                                <div class="fw-semibold" style="color: var(--admin-text); font-size: 0.85rem;"><?= h((string)($question['ten_san_pham'] ?? 'SP #' . $productId)) ?></div>
                                                <div class="small text-muted" style="font-size: 0.76rem;">Mã SP: #<?= h($productId) ?></div>
                                                <div class="mt-1">
                                                    <a class="btn btn-sm btn-outline-secondary px-2 py-0.5 <?= $productId === '' ? 'disabled' : '' ?>" href="<?= h($productUrl) ?>" target="_blank" style="border-radius: 4px; font-size: 0.74rem;"><i class="bi bi-box-arrow-up-right me-1"></i>Xem sản phẩm</a>
                                                </div>
                                            </div>
                                        </div>
                                    </td>
                                    <td style="min-width: 260px;">
                                        <div class="fw-semibold" style="color: var(--admin-text); font-size: 0.85rem;"><?= h((string)($question['ten_khach_hang'] ?? 'Khách hàng')) ?></div>
                                        <div class="small text-muted mb-1 tabular-nums" style="font-size: 0.74rem;"><?= h($formatQuestionDate($question['ngay_hoi'] ?? '')) ?></div>
                                        <div class="small" style="color: var(--admin-text); font-size: 0.82rem;"><?= nl2br_safe((string)($question['cau_hoi'] ?? '')) ?></div>
                                    </td>
                                    <td>
                                        <?php if ($isHidden): ?>
                                            <span class="status-pill status-pill-cancelled">Đã ẩn</span>
                                        <?php elseif ($answered): ?>
                                            <span class="status-pill status-pill-completed">Đã trả lời</span>
                                        <?php else: ?>
                                            <span class="status-pill status-pill-pending">Chưa trả lời</span>
                                        <?php endif; ?>
                                        <?php if ($answered): ?>
                                            <div class="p-2 rounded mt-2 small" style="background: var(--admin-surface-subtle); border: 1px solid var(--admin-border); font-size: 0.78rem;">
                                                <strong class="text-success"><i class="bi bi-reply-fill me-1"></i>Trả lời:</strong> <?= nl2br_safe((string)($answer['noi_dung'] ?? '')) ?>
                                            </div>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if (!$isHidden): ?>
                                            <form method="post" action="index.php?r=admin_question_reply" class="d-grid gap-1.5 gap-1">
                                                <input type="hidden" name="ma_hoi_dap" value="<?= h((string)($question['ma_hoi_dap'] ?? '')) ?>">
                                                <textarea class="form-control" name="tra_loi" rows="2" placeholder="Nhập nội dung phản hồi..." required style="border-radius: 6px; border-color: var(--admin-border); font-size: 0.82rem;"><?= h((string)($answer['noi_dung'] ?? '')) ?></textarea>
                                                <button class="btn btn-sm text-white fw-semibold" type="submit" style="background: #183B2B; border-radius: 4px; font-size: 0.78rem;"><i class="bi bi-send me-1"></i><?= $answered ? 'Cập nhật trả lời' : 'Gửi trả lời' ?></button>
                                            </form>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-end">
                                        <?php if (!$isHidden): ?>
                                            <form method="post" action="index.php?r=admin_question_hide" onsubmit="return confirm('Bạn có chắc muốn ẩn câu hỏi này không?');">
                                                <input type="hidden" name="ma_hoi_dap" value="<?= h((string)($question['ma_hoi_dap'] ?? '')) ?>">
                                                <button class="btn btn-sm btn-outline-danger px-2 py-0.5" type="submit" style="border-radius: 4px; font-size: 0.78rem;" title="Ẩn câu hỏi"><i class="bi bi-eye-slash me-1"></i> Ẩn</button>
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

