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
