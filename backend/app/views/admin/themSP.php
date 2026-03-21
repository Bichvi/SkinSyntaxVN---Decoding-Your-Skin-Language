<?php
$product = $product ?? [];
$error = $error ?? null;
$brandOptions = $brandOptions ?? [];
$categoryOptions = $categoryOptions ?? [];
$nextProductCode = (string)($nextProductCode ?? '');

$rawImages = preg_split('/\s*\|\s*/', trim((string)($product['link_hinh_anh'] ?? ''))) ?: [];
$currentImage = trim((string)($rawImages[0] ?? ''));
$imagePreview = resolve_image_url($currentImage);
$selectedBrandId = (string)($product['ma_thuong_hieu'] ?? '');
$selectedCategoryId = (string)($product['ma_danh_muc'] ?? '');
$selectedBrandLabel = trim((string)($product['ten_thuong_hieu_input'] ?? ''));
$selectedCategoryLabel = trim((string)($product['ten_danh_muc_input'] ?? ''));

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
?>

<div class="container py-4">
	<div class="d-flex justify-content-between align-items-center mb-3">
		<h1 class="h3 mb-0">Thêm sản phẩm</h1>
		<a href="index.php?r=admin_sp" class="btn btn-outline-secondary">Quay lại</a>
	</div>

	<?php if (!empty($error)): ?>
		<div class="alert alert-danger"><?= h($error) ?></div>
	<?php endif; ?>

	<form method="POST" action="index.php?r=admin_sp_create" enctype="multipart/form-data" class="card border-0 shadow-sm">
		<div class="card-body">
			<div class="row g-3">
				<div class="col-md-4">
					<label class="form-label">Mã sản phẩm *</label>
					<input type="text" class="form-control" name="ma_san_pham" value="<?= h($product['ma_san_pham'] ?? $nextProductCode) ?>" readonly>
					<div class="form-text">Mã được tự động lấy theo mã sản phẩm cuối cùng trong hệ thống.</div>
				</div>
				<div class="col-md-8">
					<label class="form-label">Tên sản phẩm *</label>
					<input type="text" class="form-control" name="ten_san_pham" value="<?= h($product['ten_san_pham'] ?? '') ?>" required>
				</div>

				<div class="col-md-4">
					<label class="form-label">Thương hiệu</label>
					<input type="hidden" name="ma_thuong_hieu" value="<?= h($selectedBrandId) ?>">
					<input type="text" class="form-control" name="ten_thuong_hieu_input" value="<?= h($selectedBrandLabel) ?>" placeholder="Nhập tên thương hiệu để tìm..." list="brand-options" data-lookup-input data-target-hidden="ma_thuong_hieu">
					<div class="form-text">Nếu chưa có trong DB, hệ thống sẽ tự tạo thương hiệu mới.</div>
				</div>
				<div class="col-md-4">
					<label class="form-label">Danh mục</label>
					<input type="hidden" name="ma_danh_muc" value="<?= h($selectedCategoryId) ?>">
					<input type="text" class="form-control" name="ten_danh_muc_input" value="<?= h($selectedCategoryLabel) ?>" placeholder="Nhập tên danh mục để tìm..." list="category-options" data-lookup-input data-target-hidden="ma_danh_muc">
					<div class="form-text">Nếu chưa có trong DB, hệ thống sẽ tự tạo danh mục mới.</div>
				</div>
				<div class="col-md-4">
					<label class="form-label">Dung tích</label>
					<input type="text" class="form-control" name="dung_tich" value="<?= h($product['dung_tich'] ?? '') ?>">
				</div>

				<div class="col-md-4">
					<label class="form-label">Giá bán</label>
					<input type="number" class="form-control" name="gia_ban" value="<?= h($product['gia_ban'] ?? '') ?>" min="1" step="1" required>
				</div>
				<div class="col-md-4">
					<label class="form-label">Giá thị trường</label>
					<input type="number" class="form-control" name="gia_thi_truong" value="<?= h($product['gia_thi_truong'] ?? '') ?>" min="1" step="1">
				</div>
				<div class="col-md-4">
					<label class="form-label">Loại da</label>
					<input type="text" class="form-control" name="loai_da" value="<?= h($product['loai_da'] ?? '') ?>">
				</div>

				<div class="col-12">
					<label class="form-label">Ảnh sản phẩm (upload)</label>
					<input type="file" class="form-control" name="hinh_anh" accept="image/*" id="product-upload-input">
				</div>

				<div class="col-12">
					<label class="form-label">Hoặc nhập link/tên ảnh</label>
					<input type="text" class="form-control" name="link_hinh_anh" value="<?= h($product['link_hinh_anh'] ?? '') ?>" placeholder="vd: https://... hoặc chuỗi nhiều ảnh ngăn cách bằng |" id="product-link-input">
				</div>

				<div class="col-12">
					<label class="form-label">Ảnh xem trước</label>
					<div class="d-flex align-items-center gap-3 flex-wrap rounded-4 border bg-light-subtle p-3">
						<?php if ($imagePreview !== ''): ?>
							<img src="<?= h($imagePreview) ?>" alt="<?= h($product['ten_san_pham'] ?? '') ?>" class="rounded-3 border" width="92" height="92" style="object-fit: cover;" id="product-preview-image">
						<?php else: ?>
							<div class="rounded-3 border bg-white d-inline-flex align-items-center justify-content-center text-muted" style="width: 92px; height: 92px;" id="product-preview-placeholder">
								<i class="fa-regular fa-image fs-4"></i>
							</div>
							<img src="" alt="<?= h($product['ten_san_pham'] ?? '') ?>" class="rounded-3 border d-none" width="92" height="92" style="object-fit: cover;" id="product-preview-image">
						<?php endif; ?>
						<div>
							<div class="fw-semibold">Đang hiển thị ảnh đầu tiên</div>
							<div class="text-muted small" id="product-preview-text"><?= h($currentImage !== '' ? $currentImage : 'Chưa có ảnh nào được nhập') ?></div>
						</div>
					</div>
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
			<button type="submit" class="btn btn-primary">Lưu sản phẩm</button>
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

(function () {
	var fileInput = document.getElementById('product-upload-input');
	var linkInput = document.getElementById('product-link-input');
	var previewImage = document.getElementById('product-preview-image');
	var previewPlaceholder = document.getElementById('product-preview-placeholder');
	var previewText = document.getElementById('product-preview-text');
	var objectUrl = null;

	function setPreview(src, label) {
		if (!previewImage || !previewText) {
			return;
		}

		if (src) {
			previewImage.src = src;
			previewImage.classList.remove('d-none');
			if (previewPlaceholder) {
				previewPlaceholder.classList.add('d-none');
			}
		} else {
			previewImage.src = '';
			previewImage.classList.add('d-none');
			if (previewPlaceholder) {
				previewPlaceholder.classList.remove('d-none');
			}
		}

		previewText.textContent = label;
	}

	if (fileInput) {
		fileInput.addEventListener('change', function () {
			var file = fileInput.files && fileInput.files[0];
			if (!file) {
				var firstLink = (linkInput && linkInput.value.split('|')[0] || '').trim();
				setPreview(firstLink, firstLink || 'Chưa có ảnh nào được nhập');
				return;
			}

			if (objectUrl) {
				URL.revokeObjectURL(objectUrl);
			}

			objectUrl = URL.createObjectURL(file);
			setPreview(objectUrl, file.name);
		});
	}

	if (linkInput) {
		linkInput.addEventListener('input', function () {
			if (fileInput && fileInput.files && fileInput.files.length > 0) {
				return;
			}

			var firstLink = (linkInput.value.split('|')[0] || '').trim();
			setPreview(firstLink, firstLink || 'Chưa có ảnh nào được nhập');
		});
	}
})();
</script>
