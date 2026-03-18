<?php
$conversations = $conversations ?? [];
$activeConversationId = (int)($activeConversationId ?? 0);
$messages = $messages ?? [];
?>

<div class="container-fluid p-4">
    <div class="mb-4">
        <h1 class="h3 mb-1">Hỗ trợ khách hàng qua chat</h1>
        <p class="text-muted mb-0">Theo dõi lịch sử hội thoại và phản hồi trực tiếp cho khách hàng.</p>
    </div>

    <div class="row g-4">
        <div class="col-lg-4">
            <div class="card border-0 shadow-sm rounded-4 h-100">
                <div class="card-body p-0">
                    <div class="list-group list-group-flush">
                        <?php if (empty($conversations)): ?>
                            <div class="p-4 text-center text-muted">Chưa có cuộc hội thoại nào.</div>
                        <?php else: ?>
                            <?php foreach ($conversations as $conversation): ?>
                                <?php $maKh = (int)($conversation['ma_kh'] ?? 0); ?>
                                <a href="index.php?r=staff_chats&ma_kh=<?= $maKh ?>" class="list-group-item list-group-item-action p-3 <?= $maKh === $activeConversationId ? 'active' : '' ?>">
                                    <div class="fw-semibold"><?= h($conversation['ho_ten'] ?? 'Khách hàng') ?></div>
                                    <div class="small <?= $maKh === $activeConversationId ? 'text-white-50' : 'text-muted' ?>"><?= h($conversation['email'] ?? '') ?></div>
                                    <div class="small mt-1 <?= $maKh === $activeConversationId ? 'text-white-50' : 'text-muted' ?>"><?= h($conversation['tin_nhan_moi'] ?? '') ?></div>
                                </a>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-lg-8">
            <div class="card border-0 shadow-sm rounded-4 h-100">
                <div class="card-body p-4 d-flex flex-column" style="min-height: 620px;">
                    <?php if ($activeConversationId <= 0): ?>
                        <div class="m-auto text-center text-muted">Chọn một khách hàng để xem lịch sử chat.</div>
                    <?php else: ?>
                        <div class="flex-grow-1 overflow-auto mb-3 pe-2" style="max-height: 500px;">
                            <?php foreach ($messages as $message): ?>
                                <?php $isStaff = !empty($message['ma_nv']); ?>
                                <div class="d-flex <?= $isStaff ? 'justify-content-end' : 'justify-content-start' ?> mb-3">
                                    <div class="p-3 rounded-4 <?= $isStaff ? 'bg-primary text-white' : 'bg-light' ?>" style="max-width: 78%;">
                                        <div class="small fw-semibold mb-1"><?= h($isStaff ? ($message['ten_nhan_vien'] ?? 'Nhân viên') : ($message['ten_khach_hang'] ?? 'Khách hàng')) ?></div>
                                        <div><?= nl2br_safe($message['noi_dung'] ?? '') ?></div>
                                        <div class="small mt-2 <?= $isStaff ? 'text-white-50' : 'text-muted' ?>"><?= h(!empty($message['thoi_gian']) ? date('d/m/Y H:i', strtotime((string)$message['thoi_gian'])) : '') ?></div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>

                        <form method="post" action="index.php?r=staff_chat_send" class="row g-2 mt-auto">
                            <input type="hidden" name="ma_kh" value="<?= $activeConversationId ?>">
                            <div class="col-md-10">
                                <textarea class="form-control" name="noi_dung" rows="3" placeholder="Nhập phản hồi cho khách hàng..."></textarea>
                            </div>
                            <div class="col-md-2 d-grid">
                                <button type="submit" class="btn btn-primary">Gửi</button>
                            </div>
                        </form>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>