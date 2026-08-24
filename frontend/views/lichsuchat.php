<?php
$messages = $messages ?? [];
?>

<div class="container py-4 py-lg-5" style="max-width: 980px;">
	<div class="d-flex flex-column flex-md-row align-items-md-center justify-content-between gap-3 mb-4">
		<div>
			<h2 class="mb-1">Chat với nhân viên hỗ trợ</h2>
			<div class="text-muted">Gửi câu hỏi về đơn hàng, sản phẩm hoặc routine chăm sóc da của bạn.</div>
		</div>
		<a class="btn btn-outline-brand" href="<?= BASE_URL ?>/index.php?r=hoso">Quay về tài khoản</a>
	</div>

	<div class="card border-0 shadow-sm rounded-4 overflow-hidden">
		<div class="card-body p-0">
			<div class="p-4 bg-light border-bottom">
				<strong>Hộp thư hỗ trợ SkinSyntax</strong>
			</div>

			<div class="p-4" style="max-height: 520px; overflow-y: auto; background: linear-gradient(180deg, #f8fbff 0%, #ffffff 100%);">
				<?php if (empty($messages)): ?>
					<div class="text-center text-muted py-5">Chưa có tin nhắn nào. Hãy bắt đầu cuộc trò chuyện với nhân viên hỗ trợ.</div>
				<?php else: ?>
					<?php foreach ($messages as $message): ?>
						<?php $isStaff = !empty($message['ma_nv']); ?>
						<?php $isCustomer = !$isStaff; ?>
						<div class="d-flex <?= $isCustomer ? 'justify-content-end' : 'justify-content-start' ?> mb-3">
							<div class="rounded-4 p-3 <?= $isCustomer ? 'bg-primary text-white' : 'bg-white border' ?>" style="max-width: 78%; box-shadow: 0 10px 24px rgba(15, 23, 42, 0.05);">
								<div class="small fw-semibold mb-1"><?= h($isCustomer ? 'Bạn' : ($message['ten_nhan_vien'] ?? 'Nhân viên hỗ trợ')) ?></div>
								<div><?= nl2br_safe($message['noi_dung'] ?? '') ?></div>
								<div class="small mt-2 <?= $isCustomer ? 'text-white-50' : 'text-muted' ?>"><?= h(!empty($message['thoi_gian']) ? date('d/m/Y H:i', strtotime((string)$message['thoi_gian'])) : '') ?></div>
							</div>
						</div>
					<?php endforeach; ?>
				<?php endif; ?>
			</div>

			<form method="post" action="<?= BASE_URL ?>/index.php?r=chat_send" class="p-4 border-top bg-white">
				<div class="row g-2 align-items-end">
					<div class="col-md-10">
						<label for="supportChatContentInput" class="form-label">Nội dung cần hỗ trợ</label>
						<textarea id="supportChatContentInput" class="form-control" name="noi_dung" rows="3" placeholder="Nhập vấn đề bạn đang gặp..." required></textarea>
					</div>
					<div class="col-md-2 d-grid">
						<button type="submit" class="btn btn-brand">Gửi tin nhắn</button>
					</div>
				</div>
			</form>
		</div>
	</div>
</div>
