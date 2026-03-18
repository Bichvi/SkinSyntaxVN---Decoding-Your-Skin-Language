<?php
$product = $product ?? [];
$error = $error ?? null;
?>

<div class="container-fluid p-4" style="max-width: 1080px;">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h1 class="h3 mb-0">Cập nhật sản phẩm</h1>
        <a href="index.php?r=staff_products" class="btn btn-outline-secondary">Quay lại</a>
    </div>

    <?php if (!empty($error)): ?>
        <div class="alert alert-danger"><?= h($error) ?></div>
    <?php endif; ?>

    <form method="POST" action="index.php?r=staff_product_edit&id=<?= urlencode((string)($product['ma_san_pham'] ?? $product['id'] ?? '')) ?>" class="card border-0 shadow-sm rounded-4">
        <div class="card-body p-4">
            <div class="row g-3">
                <div class="col-md-8">
                    <label class="form-label">Tên sản phẩm</label>
                    <input type="text" class="form-control" name="ten_san_pham" value="<?= h($product['ten_san_pham'] ?? '') ?>" required>
                </div>
                <div class="col-md-4">
                    <label class="form-label">Giá bán</label>
                    <input type="number" class="form-control" name="gia_ban" value="<?= h($product['gia_ban'] ?? '') ?>">
                </div>
                <div class="col-md-4">
                    <label class="form-label">Dung tích</label>
                    <input type="text" class="form-control" name="dung_tich" value="<?= h($product['dung_tich'] ?? '') ?>">
                </div>
                <div class="col-md-4">
                    <label class="form-label">Loại da</label>
                    <input type="text" class="form-control" name="loai_da" value="<?= h($product['loai_da'] ?? '') ?>">
                </div>
                <div class="col-md-4">
                    <label class="form-label">Link hình ảnh</label>
                    <input type="text" class="form-control" name="link_hinh_anh" value="<?= h($product['link_hinh_anh'] ?? '') ?>">
                </div>
                <div class="col-12">
                    <label class="form-label">Mô tả</label>
                    <textarea class="form-control" name="mo_ta" rows="4"><?= h($product['mo_ta'] ?? '') ?></textarea>
                </div>
                <div class="col-12">
                    <label class="form-label">Thành phần chính</label>
                    <textarea class="form-control" name="thanh_phan_chinh" rows="3"><?= h($product['thanh_phan_chinh'] ?? '') ?></textarea>
                </div>
                <div class="col-12">
                    <label class="form-label">Thành phần đầy đủ</label>
                    <textarea class="form-control" name="thanh_phan_day_du" rows="3"><?= h($product['thanh_phan_day_du'] ?? '') ?></textarea>
                </div>
                <div class="col-12">
                    <label class="form-label">Hướng dẫn sử dụng</label>
                    <textarea class="form-control" name="hdsd" rows="3"><?= h($product['hdsd'] ?? '') ?></textarea>
                </div>
            </div>
        </div>
        <div class="card-footer bg-white d-flex justify-content-end gap-2">
            <a href="index.php?r=staff_products" class="btn btn-light border">Hủy</a>
            <button type="submit" class="btn btn-primary">Lưu cập nhật</button>
        </div>
    </form>
</div>