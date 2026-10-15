<?php
// frontend/views/admin/flash_sale_form.php
$editing = $editing ?? null;
$items = $items ?? [];
$defaultStart = $defaultStart ?? '';
$defaultEnd = $defaultEnd ?? '';
$now = $now ?? time();

$isEditing = !empty($editing);
$campaignId = (int)($editing['ma_campaign'] ?? 0);
$nameValue = (string)($editing['ten_campaign'] ?? '');
$descValue = (string)($editing['mo_ta'] ?? '');
$isActive = $isEditing ? (!empty($editing['is_active'])) : true;

$startValue = $isEditing
    ? (!empty($editing['start_at']) ? date('Y-m-d\TH:i', strtotime((string)$editing['start_at'])) : '')
    : $defaultStart;

$endValue = $isEditing
    ? (!empty($editing['end_at']) ? date('Y-m-d\TH:i', strtotime((string)$editing['end_at'])) : '')
    : $defaultEnd;

$isActiveCampaign = $isEditing && (($editing['status'] ?? '') === FlashSaleCampaign::STATUS_ACTIVE);
?>

<div class="container-fluid px-4 py-4">
    <!-- Breadcrumb & Title -->
    <div class="d-flex flex-column flex-lg-row align-items-lg-center justify-content-between gap-3 mb-4">
        <div>
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb mb-1 small">
                    <li class="breadcrumb-item"><a href="index.php?r=admin_flash_sales" class="text-decoration-none text-muted">Flash Sale</a></li>
                    <li class="breadcrumb-item active text-dark fw-semibold" aria-current="page"><?= $isEditing ? 'Chỉnh sửa chiến dịch' : 'Tạo mới chiến dịch' ?></li>
                </ol>
            </nav>
            <h1 class="h4 fw-bold mb-0" style="color: var(--admin-text);">
                <?= $isEditing ? 'Chỉnh sửa chiến dịch: ' . h($nameValue) : 'Tạo chiến dịch Flash Sale mới' ?>
            </h1>
        </div>
        <div class="d-flex gap-2">
            <a href="index.php?r=admin_flash_sales" class="btn btn-sm btn-outline-secondary px-3 py-2" style="border-radius: 6px;">
                <i class="bi bi-arrow-left me-1"></i>Quay lại danh sách
            </a>
            <?php if ($isEditing): ?>
                <a href="index.php?r=admin_flash_sale_detail&id=<?= $campaignId ?>" class="btn btn-sm btn-outline-info px-3 py-2" style="border-radius: 6px;">
                    <i class="bi bi-eye me-1"></i>Xem trước chiến dịch
                </a>
            <?php endif; ?>
        </div>
    </div>

    <!-- Active Campaign Safety Warning -->
    <?php if ($isActiveCampaign): ?>
        <div class="alert alert-warning border border-warning-subtle shadow-sm py-3 px-3 mb-4 d-flex align-items-start gap-2.5" style="border-radius: 8px;">
            <i class="bi bi-exclamation-triangle-fill text-warning fs-5 mt-0.5"></i>
            <div>
                <strong class="d-block mb-1 text-dark">Chiến dịch đang diễn ra (ACTIVE)!</strong>
                <span class="small text-secondary">
                    Chiến dịch đang có hiệu lực trên hệ thống. Thay đổi giá hoặc sản phẩm có thể ảnh hưởng trực tiếp đến giỏ hàng và đơn giá thanh toán hiện tại của khách hàng. Hãy kiểm tra thật kỹ trước khi nhấn Lưu.
                </span>
            </div>
        </div>
    <?php endif; ?>

    <!-- Main Form -->
    <form method="post" action="index.php?r=admin_flash_sale_save" id="flashSaleForm" novalidate>
        <?php if ($isEditing): ?>
            <input type="hidden" name="ma_campaign" value="<?= $campaignId ?>">
        <?php endif; ?>

        <div class="row g-4">
            <!-- Left Column: Campaign Information -->
            <div class="col-xl-4 col-lg-5">
                <div class="admin-card p-3.5 shadow-sm" style="border-radius: 8px !important;">
                    <h6 class="fw-bold mb-3 border-bottom pb-2" style="color: var(--admin-text);">
                        <i class="bi bi-gear-fill me-1 text-secondary"></i>Thông tin cấu hình
                    </h6>

                    <div class="mb-3">
                        <label class="form-label small fw-semibold text-muted mb-1">Tên chiến dịch <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" name="ten_campaign" id="inputTenCampaign" value="<?= h($nameValue) ?>" placeholder="Ví dụ: Siêu Sale Giữa Tuần Rạng Rỡ" maxlength="255" required style="border-radius: 6px;">
                        <div class="small text-muted mt-1" style="font-size: 0.76rem;">Tên định danh hiển thị trên banner và quản trị.</div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label small fw-semibold text-muted mb-1">Mô tả chiến dịch</label>
                        <textarea class="form-control" name="mo_ta" rows="3" placeholder="Ghi chú nội bộ hoặc thông điệp khuyến mãi" maxlength="1000" style="border-radius: 6px; font-size: 0.86rem;"><?= h($descValue) ?></textarea>
                    </div>

                    <div class="row g-2 mb-3">
                        <div class="col-12">
                            <label class="form-label small fw-semibold text-muted mb-1">Thời gian bắt đầu (VN) <span class="text-danger">*</span></label>
                            <input type="datetime-local" class="form-control" name="start_at" id="inputStartAt" value="<?= h($startValue) ?>" required style="border-radius: 6px; font-size: 0.85rem;">
                        </div>
                        <div class="col-12">
                            <label class="form-label small fw-semibold text-muted mb-1">Thời gian kết thúc (VN) <span class="text-danger">*</span></label>
                            <input type="datetime-local" class="form-control" name="end_at" id="inputEndAt" value="<?= h($endValue) ?>" required style="border-radius: 6px; font-size: 0.85rem;">
                        </div>
                    </div>

                    <div class="mb-3 p-3 bg-light border rounded" style="border-radius: 6px !important;">
                        <div class="form-check form-switch m-0">
                            <input class="form-check-input" type="checkbox" role="switch" name="is_active" id="checkIsActive" value="1" <?= $isActive ? 'checked' : '' ?>>
                            <label class="form-check-label fw-semibold small text-dark" for="checkIsActive">
                                Kích hoạt chiến dịch (Enabled)
                            </label>
                        </div>
                        <div class="small text-muted mt-1" style="font-size: 0.75rem;">
                            Nếu tắt, chiến dịch sẽ chuyển sang trạng thái <strong>Đã tắt</strong> và giá Flash Sale sẽ lập tức ngừng áp dụng.
                        </div>
                    </div>

                    <div class="d-grid mt-4">
                        <button type="submit" class="btn text-white fw-semibold py-2 d-flex align-items-center justify-content-center gap-1 shadow-sm" style="background: #183B2B; border-radius: 6px;">
                            <i class="bi bi-check2-circle fs-5"></i>
                            <span><?= $isEditing ? 'Lưu cập nhật chiến dịch' : 'Tạo mới chiến dịch' ?></span>
                        </button>
                    </div>
                </div>
            </div>

            <!-- Right Column: Products in Campaign -->
            <div class="col-xl-8 col-lg-7">
                <div class="admin-card p-3.5 shadow-sm" style="border-radius: 8px !important;">
                    <div class="d-flex flex-column flex-sm-row align-items-sm-center justify-content-between gap-2 border-bottom pb-2 mb-3">
                        <h6 class="fw-bold mb-0" style="color: var(--admin-text);">
                            <i class="bi bi-box-seam me-1 text-secondary"></i>Sản phẩm tham gia Flash Sale
                        </h6>
                        <span class="badge bg-light text-dark border fw-medium px-2 py-1" id="productCountBadge" style="font-size: 0.8rem;">
                            Đã chọn: <strong id="productCountNum" class="text-success"><?= count($items) ?></strong> SP
                        </span>
                    </div>

                    <!-- Soft recommendation notice -->
                    <div class="alert alert-light border py-2 px-3 mb-3 d-flex align-items-center justify-content-between small text-muted flex-wrap gap-2" style="border-radius: 6px; font-size: 0.82rem;">
                        <span><i class="bi bi-lightbulb-fill text-warning me-1"></i>Gợi ý: Thêm từ <strong>24–40 sản phẩm</strong> để carousel phân trang trên trang chủ phong phú nhất. <em>(Không giới hạn số lượng tối đa)</em></span>
                        <span id="recommendationStatus" class="fw-semibold"></span>
                    </div>

                    <!-- Product Search Box -->
                    <div class="position-relative mb-3">
                        <div class="d-flex justify-content-between align-items-center mb-1">
                            <label class="form-label small fw-semibold text-muted mb-0">Thêm sản phẩm vào chiến dịch</label>
                            <span class="small text-muted" style="font-size: 0.75rem;">
                                <i class="bi bi-info-circle me-1"></i>Click vào ô để xem danh mục gợi ý, chọn liên tiếp nhiều sản phẩm
                            </span>
                        </div>
                        <div class="input-group">
                            <span class="input-group-text bg-white border-end-0"><i class="bi bi-search text-muted"></i></span>
                            <input type="text" class="form-control border-start-0" id="productSearchInput" placeholder="Gõ tên, mã SP hoặc click vào đây để chọn nhanh từ danh mục..." autocomplete="off" style="border-radius: 0 6px 6px 0;">
                        </div>

                        <!-- Dropdown Search Results -->
                        <div class="position-absolute w-100 bg-white border rounded shadow-lg d-none" id="searchResultsDropdown" style="z-index: 1050; max-height: 360px; overflow-y: auto; margin-top: 2px;">
                            <div class="p-2 text-center text-muted small" id="searchLoadingIndicator">
                                <span class="spinner-border spinner-border-sm me-1"></span> Đang tải danh sách sản phẩm...
                            </div>
                            <div class="list-group list-group-flush" id="searchResultsList"></div>
                        </div>
                    </div>

                    <!-- Selected Products Table -->
                    <div class="table-responsive" style="max-height: 520px; overflow-y: auto;">
                        <table class="table table-sm table-hover align-middle mb-0" id="selectedProductsTable" style="font-size: 0.86rem;">
                            <thead class="table-light text-muted small text-uppercase sticky-top" style="letter-spacing: 0.5px; z-index: 10;">
                                <tr>
                                    <th style="width: 50px;">Ảnh</th>
                                    <th style="width: 65px;" class="text-center">Mã SP</th>
                                    <th>Tên sản phẩm</th>
                                    <th style="width: 120px;" class="text-end">Giá niêm yết</th>
                                    <th style="width: 150px;">Giá Flash Sale (VNĐ)</th>
                                    <th style="width: 85px;" class="text-center">Mức giảm</th>
                                    <th style="width: 50px;" class="text-center">Xóa</th>
                                </tr>
                            </thead>
                            <tbody id="selectedProductsTbody">
                                <?php if (empty($items)): ?>
                                    <tr id="emptyProductsRow">
                                        <td colspan="7" class="text-center py-5 text-muted">
                                            <i class="bi bi-basket fs-2 d-block mb-1 text-secondary opacity-50"></i>
                                            Chưa có sản phẩm nào được chọn.<br>
                                            <span class="small">Sử dụng ô tìm kiếm phía trên để chọn sản phẩm thêm vào chiến dịch.</span>
                                        </td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($items as $idx => $it): ?>
                                        <?php
                                        $pid = (string)$it['ma_san_pham'];
                                        $regPrice = (int)$it['regular_price'];
                                        $flashPrice = (int)$it['flash_price'];
                                        $pct = (int)($it['phan_tram_giam'] ?? 0);
                                        $img = !empty($it['hinh_anh']) ? $it['hinh_anh'] : 'assets/images/sp-placeholder.png';
                                        ?>
                                        <tr data-product-id="<?= h($pid) ?>" data-regular-price="<?= $regPrice ?>">
                                            <td>
                                                <img src="<?= h($img) ?>" alt="" class="rounded border" style="width: 36px; height: 36px; object-fit: cover;" onerror="this.src='assets/images/sp-placeholder.png'">
                                            </td>
                                            <td class="text-center fw-bold text-muted tabular-nums">
                                                #<?= h($pid) ?>
                                                <input type="hidden" name="items[<?= $idx ?>][ma_san_pham]" value="<?= h($pid) ?>">
                                            </td>
                                            <td>
                                                <div class="fw-semibold text-truncate" style="max-width: 240px;" title="<?= h($it['ten_san_pham']) ?>">
                                                    <?= h($it['ten_san_pham']) ?>
                                                </div>
                                                <div class="small text-muted"><?= h($it['thuong_hieu'] ?? 'SkinSyntax') ?></div>
                                            </td>
                                            <td class="text-end tabular-nums fw-medium">
                                                <?= number_format($regPrice, 0, ',', '.') ?> đ
                                            </td>
                                            <td>
                                                <div class="input-group input-group-sm">
                                                    <input type="text" class="form-control tabular-nums flash-price-input text-end fw-bold text-danger" name="items[<?= $idx ?>][flash_price]" value="<?= number_format($flashPrice, 0, ',', '.') ?>" required placeholder="Nhập giá sale">
                                                    <span class="input-group-text">đ</span>
                                                </div>
                                                <div class="price-validation-msg small text-danger mt-0.5 d-none" style="font-size: 0.72rem;"></div>
                                            </td>
                                            <td class="text-center tabular-nums">
                                                <span class="badge discount-badge <?= $pct > 0 ? 'bg-danger' : 'bg-secondary' ?>" style="font-size: 0.75rem;">
                                                    <?= $pct > 0 ? "-{$pct}%" : "0%" ?>
                                                </span>
                                            </td>
                                            <td class="text-center">
                                                <button type="button" class="btn btn-sm btn-link text-danger p-0 remove-product-btn" title="Xóa khỏi danh sách">
                                                    <i class="bi bi-trash3-fill"></i>
                                                </button>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </form>
</div>

<!-- JavaScript for Dynamic Product Selector & Live Discount Calculation -->
<script>
(function() {
    const searchInput = document.getElementById('productSearchInput');
    const searchDropdown = document.getElementById('searchResultsDropdown');
    const searchResultsList = document.getElementById('searchResultsList');
    const loadingIndicator = document.getElementById('searchLoadingIndicator');
    const tbody = document.getElementById('selectedProductsTbody');
    const emptyRow = document.getElementById('emptyProductsRow');
    const productCountNum = document.getElementById('productCountNum');
    const recommendationStatus = document.getElementById('recommendationStatus');

    let debounceTimer = null;
    let selectedProductIds = new Set();

    // Initialize set of already selected product IDs
    document.querySelectorAll('#selectedProductsTbody tr[data-product-id]').forEach(tr => {
        selectedProductIds.add(String(tr.getAttribute('data-product-id')));
    });

    updateCounters();

    // -----------------------------------------------------------------
    // Product Search & Catalog Browsing
    // -----------------------------------------------------------------
    function performSearch(query) {
        clearTimeout(debounceTimer);
        searchDropdown.classList.remove('d-none');
        loadingIndicator.classList.remove('d-none');
        searchResultsList.innerHTML = '';

        fetch('index.php?r=api_admin_flash_sale_search_products&limit=30&q=' + encodeURIComponent(query))
            .then(res => res.json())
            .then(data => {
                loadingIndicator.classList.add('d-none');
                searchResultsList.innerHTML = '';

                if (!data.ok || !data.items || data.items.length === 0) {
                    searchResultsList.innerHTML = '<div class="p-3 text-center text-muted small">Không tìm thấy sản phẩm phù hợp.</div>';
                    return;
                }

                // Header in dropdown
                const headerDiv = document.createElement('div');
                headerDiv.className = 'p-2 bg-light border-bottom text-muted small d-flex justify-content-between align-items-center sticky-top';
                headerDiv.innerHTML = `
                    <span class="fw-semibold text-dark"><i class="bi bi-box-seam me-1 text-success"></i>${query ? 'Kết quả tìm kiếm' : 'Sản phẩm gợi ý / mới nhất'} (${data.items.length} SP)</span>
                    <button type="button" class="btn btn-sm btn-link text-muted p-0 text-decoration-none" id="btnCloseDropdown" style="font-size: 0.75rem;">Đóng <i class="bi bi-x-lg ms-0.5"></i></button>
                `;
                searchResultsList.appendChild(headerDiv);
                headerDiv.querySelector('#btnCloseDropdown').addEventListener('click', (e) => {
                    e.stopPropagation();
                    searchDropdown.classList.add('d-none');
                });

                data.items.forEach(p => {
                    const pid = String(p.ma_san_pham);
                    const isAlreadySelected = selectedProductIds.has(pid);
                    const itemBtn = document.createElement('button');
                    itemBtn.type = 'button';
                    itemBtn.setAttribute('data-dropdown-pid', pid);
                    itemBtn.className = 'list-group-item list-group-item-action d-flex align-items-center justify-content-between p-2' + (isAlreadySelected ? ' disabled bg-light' : '');
                    itemBtn.style.fontSize = '0.84rem';

                    const formattedPrice = new Intl.NumberFormat('vi-VN').format(p.gia_ban);

                    itemBtn.innerHTML = `
                        <div class="d-flex align-items-center gap-2">
                            <img src="${p.hinh_anh || 'assets/images/sp-placeholder.png'}" class="rounded border" style="width: 32px; height: 32px; object-fit: cover;" onerror="this.src='assets/images/sp-placeholder.png'">
                            <div>
                                <div class="fw-semibold text-truncate" style="max-width: 320px;">#${p.ma_san_pham} - ${p.ten_san_pham}</div>
                                <div class="small text-muted">${p.thuong_hieu || 'SkinSyntax'} · ${p.danh_muc || 'Mỹ phẩm'}</div>
                            </div>
                        </div>
                        <div class="text-end ms-2">
                            <div class="fw-bold text-dark">${formattedPrice} đ</div>
                            <span class="badge ${isAlreadySelected ? 'bg-secondary' : 'bg-success-subtle text-success border border-success-subtle'}" style="font-size: 0.72rem;">
                                ${isAlreadySelected ? 'Đã thêm' : '+ Chọn'}
                            </span>
                        </div>
                    `;

                    itemBtn.addEventListener('click', (e) => {
                        e.stopPropagation();
                        if (selectedProductIds.has(pid)) return;

                        addProductToTable(p);

                        // Keep dropdown open and keep query!
                        itemBtn.classList.add('disabled', 'bg-light');
                        const badge = itemBtn.querySelector('.badge');
                        if (badge) {
                            badge.className = 'badge bg-secondary';
                            badge.textContent = 'Đã thêm';
                        }
                    });

                    searchResultsList.appendChild(itemBtn);
                });
            })
            .catch(() => {
                loadingIndicator.classList.add('d-none');
                searchResultsList.innerHTML = '<div class="p-3 text-center text-danger small">Lỗi kết nối khi tìm sản phẩm.</div>';
            });
    }

    searchInput.addEventListener('input', function() {
        const query = this.value.trim();
        clearTimeout(debounceTimer);
        debounceTimer = setTimeout(() => {
            performSearch(query);
        }, 200);
    });

    searchInput.addEventListener('focus', function() {
        performSearch(this.value.trim());
    });

    searchInput.addEventListener('click', function(e) {
        e.stopPropagation();
        if (searchDropdown.classList.contains('d-none')) {
            performSearch(this.value.trim());
        }
    });

    // Close search dropdown on click outside
    document.addEventListener('click', function(e) {
        if (!searchInput.contains(e.target) && !searchDropdown.contains(e.target)) {
            searchDropdown.classList.add('d-none');
        }
    });

    // -----------------------------------------------------------------
    // Add product to table
    // -----------------------------------------------------------------
    function addProductToTable(product) {
        const pid = String(product.ma_san_pham);
        if (selectedProductIds.has(pid)) return;

        if (emptyRow && emptyRow.parentNode) {
            emptyRow.remove();
        }

        selectedProductIds.add(pid);
        const idx = tbody.querySelectorAll('tr[data-product-id]').length;
        const regPrice = parseInt(product.gia_ban, 10) || 0;
        // Default suggested flash price: 20% discount rounded to thousands
        const defaultFlash = Math.max(1000, Math.round((regPrice * 0.8) / 1000) * 1000);
        const initialDiscount = regPrice > 0 ? Math.round(((regPrice - defaultFlash) / regPrice) * 100) : 0;
        const formattedReg = new Intl.NumberFormat('vi-VN').format(regPrice);
        const formattedFlash = new Intl.NumberFormat('vi-VN').format(defaultFlash);

        const tr = document.createElement('tr');
        tr.setAttribute('data-product-id', pid);
        tr.setAttribute('data-regular-price', regPrice);
        tr.className = 'table-success-brief';

        tr.innerHTML = `
            <td>
                <img src="${product.hinh_anh || 'assets/images/sp-placeholder.png'}" class="rounded border" style="width: 36px; height: 36px; object-fit: cover;" onerror="this.src='assets/images/sp-placeholder.png'">
            </td>
            <td class="text-center fw-bold text-muted tabular-nums">
                #${pid}
                <input type="hidden" name="items[${idx}][ma_san_pham]" value="${pid}">
            </td>
            <td>
                <div class="fw-semibold text-truncate" style="max-width: 240px;" title="${product.ten_san_pham}">${product.ten_san_pham}</div>
                <div class="small text-muted">${product.thuong_hieu || 'SkinSyntax'}</div>
            </td>
            <td class="text-end tabular-nums fw-medium">
                ${formattedReg} đ
            </td>
            <td>
                <div class="input-group input-group-sm">
                    <input type="text" class="form-control tabular-nums flash-price-input text-end fw-bold text-danger" name="items[${idx}][flash_price]" value="${formattedFlash}" required placeholder="Nhập giá sale">
                    <span class="input-group-text">đ</span>
                </div>
                <div class="price-validation-msg small text-danger mt-0.5 d-none" style="font-size: 0.72rem;"></div>
            </td>
            <td class="text-center tabular-nums">
                <span class="badge discount-badge ${initialDiscount > 0 ? 'bg-danger' : 'bg-secondary'}" style="font-size: 0.75rem;">
                    -${initialDiscount}%
                </span>
            </td>
            <td class="text-center">
                <button type="button" class="btn btn-sm btn-link text-danger p-0 remove-product-btn" title="Xóa khỏi danh sách">
                    <i class="bi bi-trash3-fill"></i>
                </button>
            </td>
        `;

        tbody.appendChild(tr);
        bindRowEvents(tr);
        reindexRows();
        updateCounters();

        // Smooth scroll to new row
        setTimeout(() => {
            tr.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
        }, 50);
    }

    // -----------------------------------------------------------------
    // Bind events for rows: remove, currency input, live discount preview
    // -----------------------------------------------------------------
    function bindRowEvents(tr) {
        const removeBtn = tr.querySelector('.remove-product-btn');
        const priceInput = tr.querySelector('.flash-price-input');
        const regPrice = parseInt(tr.getAttribute('data-regular-price'), 10) || 0;
        const discountBadge = tr.querySelector('.discount-badge');
        const validationMsg = tr.querySelector('.price-validation-msg');
        const pid = tr.getAttribute('data-product-id');

        removeBtn.addEventListener('click', () => {
            selectedProductIds.delete(pid);
            tr.remove();
            reindexRows();
            updateCounters();

            // Re-enable in dropdown if currently shown
            const dropdownBtn = searchResultsList.querySelector(`[data-dropdown-pid="${pid}"]`);
            if (dropdownBtn) {
                dropdownBtn.classList.remove('disabled', 'bg-light');
                const badge = dropdownBtn.querySelector('.badge');
                if (badge) {
                    badge.className = 'badge bg-success-subtle text-success border border-success-subtle';
                    badge.textContent = '+ Chọn';
                }
            }
        });

        priceInput.addEventListener('input', function() {
            // Clean raw number
            const rawDigits = this.value.replace(/[^\d]/g, '');
            const num = parseInt(rawDigits, 10) || 0;

            // Format input nicely
            if (rawDigits !== '') {
                this.value = new Intl.NumberFormat('vi-VN').format(num);
            } else {
                this.value = '';
            }

            // Live discount preview and instant validation feedback
            if (num <= 0) {
                discountBadge.textContent = '0%';
                discountBadge.className = 'badge discount-badge bg-secondary';
                validationMsg.textContent = 'Giá phải > 0 đ';
                validationMsg.classList.remove('d-none');
            } else if (num >= regPrice) {
                discountBadge.textContent = '0%';
                discountBadge.className = 'badge discount-badge bg-secondary';
                validationMsg.textContent = 'Phải < giá gốc (' + new Intl.NumberFormat('vi-VN').format(regPrice) + ' đ)';
                validationMsg.classList.remove('d-none');
            } else {
                const discountPct = Math.round(((regPrice - num) / regPrice) * 100);
                discountBadge.textContent = '-' + discountPct + '%';
                discountBadge.className = 'badge discount-badge bg-danger';
                validationMsg.classList.add('d-none');
                validationMsg.textContent = '';
            }
        });
    }

    // Bind existing rows
    tbody.querySelectorAll('tr[data-product-id]').forEach(tr => bindRowEvents(tr));

    function reindexRows() {
        const rows = tbody.querySelectorAll('tr[data-product-id]');
        rows.forEach((tr, i) => {
            const pidInput = tr.querySelector('input[name*="[ma_san_pham]"]');
            const priceInput = tr.querySelector('input[name*="[flash_price]"]');
            if (pidInput) pidInput.name = `items[${i}][ma_san_pham]`;
            if (priceInput) priceInput.name = `items[${i}][flash_price]`;
        });
    }

    function updateCounters() {
        const count = selectedProductIds.size;
        productCountNum.textContent = count;

        if (count === 0) {
            recommendationStatus.innerHTML = `<span class="text-muted"><i class="bi bi-info-circle me-1"></i>Chưa chọn SP</span>`;
        } else if (count < 24) {
            recommendationStatus.innerHTML = `<span class="text-primary"><i class="bi bi-check2 me-1"></i>Đã chọn <strong>${count}</strong> SP <span class="text-muted fw-normal">(Khuyến nghị: 24–40 SP cho carousel)</span></span>`;
        } else if (count <= 40) {
            recommendationStatus.innerHTML = `<span class="text-success"><i class="bi bi-check-circle-fill me-1"></i>Phạm vi tối ưu (<strong>${count}</strong> SP)</span>`;
        } else {
            recommendationStatus.innerHTML = `<span class="text-info"><i class="bi bi-check-circle me-1"></i>Đã chọn <strong>${count}</strong> SP</span>`;
        }
    }

    // Form client-side sanity check before submit
    document.getElementById('flashSaleForm').addEventListener('submit', function(e) {
        const nameInput = document.getElementById('inputTenCampaign');
        const startInput = document.getElementById('inputStartAt');
        const endInput = document.getElementById('inputEndAt');

        if (!nameInput.value.trim()) {
            alert('Vui lòng nhập tên chiến dịch.');
            nameInput.focus();
            e.preventDefault();
            return;
        }

        if (!startInput.value || !endInput.value) {
            alert('Vui lòng chọn thời gian bắt đầu và kết thúc.');
            e.preventDefault();
            return;
        }

        const startTs = new Date(startInput.value).getTime();
        const endTs = new Date(endInput.value).getTime();

        if (endTs <= startTs) {
            alert('Thời gian kết thúc phải lớn hơn thời gian bắt đầu.');
            endInput.focus();
            e.preventDefault();
            return;
        }

        if (selectedProductIds.size === 0) {
            if (!confirm('Chiến dịch này chưa chọn sản phẩm nào. Bạn có chắc chắn muốn lưu chiến dịch rỗng?')) {
                e.preventDefault();
                return;
            }
        }
    });
})();
</script>

<style>
.table-success-brief {
    animation: briefGreenHighlight 1.2s ease-out forwards;
}
@keyframes briefGreenHighlight {
    0% { background-color: rgba(25, 135, 84, 0.18); }
    100% { background-color: transparent; }
}
</style>
