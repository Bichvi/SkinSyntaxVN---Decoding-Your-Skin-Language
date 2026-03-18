<?php
$product = $product ?? [];
$error = $error ?? null;
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
					<input type="text" class="form-control" name="ma_san_pham" value="<?= h($product['ma_san_pham'] ?? '') ?>" required>
				</div>
				<div class="col-md-8">
					<label class="form-label">Tên sản phẩm *</label>
					<input type="text" class="form-control" name="ten_san_pham" value="<?= h($product['ten_san_pham'] ?? '') ?>" required>
				</div>

				<div class="col-md-4">
					<label class="form-label">Mã thương hiệu</label>
					<input type="number" class="form-control" name="ma_thuong_hieu" value="<?= h($product['ma_thuong_hieu'] ?? '') ?>">
				</div>
				<div class="col-md-4">
					<label class="form-label">Mã danh mục</label>
					<input type="number" class="form-control" name="ma_danh_muc" value="<?= h($product['ma_danh_muc'] ?? '') ?>">
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

				<div class="col-12">
					<label class="form-label">Ảnh sản phẩm (upload)</label>
					<input type="file" class="form-control" name="hinh_anh" accept="image/*">
				</div>

				<div class="col-12">
					<label class="form-label">Hoặc nhập link/tên ảnh</label>
					<input type="text" class="form-control" name="link_hinh_anh" value="<?= h($product['link_hinh_anh'] ?? '') ?>" placeholder="vd: https://... hoặc ten-anh.jpg">
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
