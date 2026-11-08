<?php
$product = $product ?? [];
$error = $error ?? null;
$brandOptions = $brandOptions ?? [];
$categoryOptions = $categoryOptions ?? [];

$rawImages = preg_split('/\s*\|\s*/', trim((string)($product['link_hinh_anh'] ?? ''))) ?: [];
$currentImage = trim((string)($rawImages[0] ?? ''));
$imagePreview = resolve_image_url($currentImage);
$selectedBrandId = (string)($product['ma_thuong_hieu'] ?? '');
$selectedCategoryId = (string)($product['ma_danh_muc'] ?? '');
$selectedStatus = strtolower(trim((string)($product['trang_thai'] ?? $product['status'] ?? 'active')));
$selectedStatus = in_array($selectedStatus, ['inactive', 'hidden', 'tam_an', 'taman', 'disabled', 'off', '0'], true) ? 'inactive' : 'active';
$selectedBrandLabel = '';
$selectedCategoryLabel = '';

foreach ($brandOptions as $option) {
	if ((string)($option['ma_thuong_hieu'] ?? '') === $selectedBrandId) {
		$selectedBrandLabel = trim((string)($option['ten_thuong_hieu'] ?? '')) . ' (#' . $selectedBrandId . ')';
		break;
	}
}

foreach ($categoryOptions as $option) {
	if ((string)($option['ma_danh_muc'] ?? '') === $selectedCategoryId) {
		$selectedCategoryLabel = trim((string)($option['ten_danh_muc'] ?? '')) . ' (#' . $selectedCategoryId . ')';
		break;
	}
}

if ($selectedBrandLabel === '' && !empty($product['thuong_hieu'])) {
	$selectedBrandLabel = trim((string)$product['thuong_hieu']) . ($selectedBrandId !== '' ? ' (#' . $selectedBrandId . ')' : '');
}

if ($selectedCategoryLabel === '' && !empty($product['loai_san_pham'])) {
	$selectedCategoryLabel = trim((string)$product['loai_san_pham']) . ($selectedCategoryId !== '' ? ' (#' . $selectedCategoryId . ')' : '');
}
?>

<div class="container py-4">
	<div class="d-flex justify-content-between align-items-center mb-3">
		<h1 class="h3 mb-0">Sửa sản phẩm</h1>
		<a href="index.php?r=admin_sp" class="btn btn-outline-secondary">Quay lại</a>
	</div>

	<?php if (!empty($error)): ?>
		<div class="alert alert-danger"><?= h($error) ?></div>
	<?php endif; ?>

	<form method="POST" action="index.php?r=admin_sp_edit&id=<?= urlencode((string)($product['ma_san_pham'] ?? $product['id'] ?? '')) ?>" enctype="multipart/form-data" class="card border-0 shadow-sm">
		<div class="card-body">
			<input type="hidden" name="id" value="<?= h($product['ma_san_pham'] ?? $product['id'] ?? '') ?>">

			<div class="row g-3">
				<div class="col-md-4">
					<label class="form-label">Mã sản phẩm</label>
					<input type="text" class="form-control" value="<?= h($product['ma_san_pham'] ?? $product['id'] ?? '') ?>" disabled>
				</div>
				<div class="col-md-8">
					<label class="form-label">Tên sản phẩm *</label>
					<input type="text" class="form-control" name="ten_san_pham" value="<?= h($product['ten_san_pham'] ?? '') ?>" required>
				</div>

				<div class="col-md-4">
					<label class="form-label">Thương hiệu</label>
					<input type="hidden" name="ma_thuong_hieu" value="<?= h($selectedBrandId) ?>">
					<input type="text" class="form-control" value="<?= h($selectedBrandLabel) ?>" placeholder="Nhập tên thương hiệu để tìm..." list="brand-options" data-lookup-input data-target-hidden="ma_thuong_hieu">
					<div class="form-text">Gõ tên thương hiệu và chọn từ danh sách gợi ý.</div>
				</div>
				<div class="col-md-4">
					<label class="form-label">Danh mục</label>
					<input type="hidden" name="ma_danh_muc" value="<?= h($selectedCategoryId) ?>">
					<input type="text" class="form-control" value="<?= h($selectedCategoryLabel) ?>" placeholder="Nhập tên danh mục để tìm..." list="category-options" data-lookup-input data-target-hidden="ma_danh_muc">
					<div class="form-text">Chọn đúng danh mục để tự lấy mã.</div>
				</div>
				<div class="col-md-4">
					<label class="form-label">Dung tích</label>
					<input type="text" class="form-control" name="dung_tich" value="<?= h($product['dung_tich'] ?? '') ?>">
				</div>

				<div class="col-md-4">
					<label class="form-label">Giá bán</label>
					<input type="number" class="form-control" name="gia_ban" value="<?= h($product['gia_ban'] ?? '') ?>">
				</div>
				<div class="col-md-4">
					<label class="form-label">Giá thị trường</label>
					<input type="number" class="form-control" name="gia_thi_truong" value="<?= h($product['gia_thi_truong'] ?? '') ?>">
				</div>
				<div class="col-md-4">
					<label class="form-label">Loại da</label>
					<input type="text" class="form-control" name="loai_da" value="<?= h($product['loai_da'] ?? '') ?>">
				</div>
				<div class="col-md-4">
					<label class="form-label">Trạng thái hiển thị</label>
					<select class="form-select" name="trang_thai">
						<option value="active" <?= $selectedStatus === 'active' ? 'selected' : '' ?>>Đang hiển thị trên website</option>
						<option value="inactive" <?= $selectedStatus === 'inactive' ? 'selected' : '' ?>>Tạm ẩn trên website</option>
					</select>
				</div>

				<div class="col-md-8">
					<label class="form-label">Ảnh mới (giữ ảnh cũ nếu bỏ trống)</label>
					<input type="file" class="form-control" name="hinh_anh" accept="image/*">
				</div>
				<div class="col-md-4">
					<label class="form-label d-block">Ảnh hiện tại</label>
					<?php if ($imagePreview !== ''): ?>
						<img src="<?= h($imagePreview) ?>" alt="Ảnh sản phẩm" style="width:96px;height:96px;object-fit:cover;border-radius:10px;">
					<?php else: ?>
						<span class="text-muted small">Không có ảnh</span>
					<?php endif; ?>
					<div class="text-muted small mt-2"><?= h($currentImage !== '' ? $currentImage : 'Sản phẩm chưa có ảnh') ?></div>
				</div>

				<div class="col-12">
					<label class="form-label">Link/tên ảnh (nếu muốn sửa trực tiếp)</label>
					<input type="text" class="form-control" name="link_hinh_anh" value="<?= h($product['link_hinh_anh'] ?? '') ?>">
				</div>

				<div class="col-12">
					<label class="form-label">Thành phần chính</label>
					<textarea class="form-control" name="thanh_phan_chinh" rows="2"><?= h($product['thanh_phan_chinh'] ?? '') ?></textarea>
				</div>

				<div class="col-12">
					<label class="form-label">Thành phần đầy đủ</label>
					<textarea class="form-control" name="thanh_phan_day_du" rows="2"><?= h($product['thanh_phan_day_du'] ?? '') ?></textarea>
				</div>

				<div class="col-12">
					<label class="form-label">Mô tả</label>
					<textarea class="form-control" name="mo_ta" rows="3"><?= h($product['mo_ta'] ?? '') ?></textarea>
				</div>

				<div class="col-12">
					<label class="form-label">Hướng dẫn sử dụng</label>
					<textarea class="form-control" name="hdsd" rows="3"><?= h($product['hdsd'] ?? '') ?></textarea>
				</div>
			</div>
		</div>

		<div class="card-footer bg-white d-flex justify-content-end gap-2">
			<a href="index.php?r=admin_sp" class="btn btn-light border">Hủy</a>
			<button type="submit" class="btn btn-primary">Cập nhật</button>
		</div>
	</form>

	<?php
	$tagCtx = class_exists('TagCuration') ? TagCuration::formContext(is_array($product) ? $product : [], $_SESSION) : null;
	$tagProductId = (string)($product['ma_san_pham'] ?? $product['id'] ?? '');
	$tagSourceLabels = ['manual' => 'Nhập tay', 'llm_approved' => 'AI gợi ý, đã duyệt'];
	?>
	<?php if ($tagCtx !== null && $tagProductId !== ''): ?>
	<form method="POST" action="index.php?r=admin_sp_tags" class="card border-0 shadow-sm mt-4" id="tagForm" data-testid="tag-form">
		<div class="card-header bg-white d-flex flex-wrap justify-content-between align-items-center gap-2">
			<div>
				<h2 class="h5 mb-0">Nhãn công dụng &amp; vấn đề da</h2>
				<div class="text-muted small">Dữ liệu do người chịu trách nhiệm xác nhận, được ưu tiên hơn nhãn suy luận từ thành phần. Lưu riêng, không cần bấm &ldquo;Cập nhật&rdquo; phía trên.</div>
			</div>
			<div class="small text-muted text-end">
				<?php if ($tagCtx['source'] !== ''): ?>
					<span class="badge text-bg-success" data-testid="tag-source"><?= h($tagSourceLabels[$tagCtx['source']] ?? $tagCtx['source']) ?></span>
					<?php if ($tagCtx['updated_at'] !== ''): ?><span class="ms-1">cập nhật <?= h($tagCtx['updated_at']) ?></span><?php endif; ?>
				<?php else: ?>
					<span class="badge text-bg-secondary">Chưa có nhãn nhập tay (đang dùng nhãn suy luận)</span>
				<?php endif; ?>
			</div>
		</div>
		<div class="card-body">
			<input type="hidden" name="csrf" value="<?= h($tagCtx['csrf']) ?>">
			<input type="hidden" name="id" value="<?= h($tagProductId) ?>">
			<div class="row g-4">
				<?php foreach ($tagCtx['groups'] as $tagGroup => $tagItems): ?>
					<?php $tagField = $tagCtx['fields'][$tagGroup]; ?>
					<div class="col-md-4">
						<fieldset>
							<legend class="form-label fw-semibold fs-6"><?= h($tagCtx['labels'][$tagGroup] ?? $tagGroup) ?></legend>
							<?php if ($tagItems === []): ?>
								<div class="text-muted small">Chưa nạp được danh sách key chuẩn.</div>
							<?php endif; ?>
							<?php foreach ($tagItems as $tagKey => $tagName): ?>
								<div class="form-check">
									<input class="form-check-input" type="checkbox" name="<?= h($tagField) ?>[]" value="<?= h($tagKey) ?>" id="tag_<?= h($tagField . '_' . $tagKey) ?>" <?= in_array($tagKey, $tagCtx['selected'][$tagGroup], true) ? 'checked' : '' ?>>
									<label class="form-check-label" for="tag_<?= h($tagField . '_' . $tagKey) ?>"><?= h($tagName) ?></label>
								</div>
							<?php endforeach; ?>
						</fieldset>
					</div>
				<?php endforeach; ?>
			</div>
		</div>
		<div class="card-footer bg-white d-flex justify-content-end">
			<button type="submit" class="btn btn-success" data-testid="tag-save">Lưu nhãn</button>
		</div>
	</form>
	<?php endif; ?>
</div>

<datalist id="brand-options">
	<?php foreach ($brandOptions as $option): ?>
		<option value="<?= h(trim((string)($option['ten_thuong_hieu'] ?? '')) . ' (#' . (string)($option['ma_thuong_hieu'] ?? '') . ')') ?>" data-id="<?= h($option['ma_thuong_hieu'] ?? '') ?>"></option>
	<?php endforeach; ?>
</datalist>

<datalist id="category-options">
	<?php foreach ($categoryOptions as $option): ?>
		<option value="<?= h(trim((string)($option['ten_danh_muc'] ?? '')) . ' (#' . (string)($option['ma_danh_muc'] ?? '') . ')') ?>" data-id="<?= h($option['ma_danh_muc'] ?? '') ?>"></option>
	<?php endforeach; ?>
</datalist>

<script>
document.querySelectorAll('[data-lookup-input]').forEach(function (input) {
	var hiddenName = input.getAttribute('data-target-hidden');
	var hidden = document.querySelector('input[name="' + hiddenName + '"]');
	var listId = input.getAttribute('list');
	var options = Array.from(document.querySelectorAll('#' + listId + ' option'));

	function syncHiddenValue() {
		var matched = options.find(function (option) {
			return option.value === input.value;
		});

		hidden.value = matched ? (matched.getAttribute('data-id') || '') : '';
	}

	input.addEventListener('change', syncHiddenValue);
	input.addEventListener('blur', syncHiddenValue);
});
</script>
