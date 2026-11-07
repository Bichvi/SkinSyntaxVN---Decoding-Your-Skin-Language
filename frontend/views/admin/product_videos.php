<?php
// Video trang sản phẩm & voucher: publish AI Idol videos to product pages, set their priority and
// attach the watch-to-reward voucher. Self-contained: it loads only admin-product-videos.js/css and
// talks to the admin_ai_idol_* JSON routes (CSRF in the body and in X-CSRF-Token).
$apvAssets = dirname(__DIR__, 2) . '/public/assets';
$apvAssetUrl = static function (string $path) use ($apvAssets): string {
    $file = $apvAssets . '/' . $path;
    return BASE_URL . '/assets/' . $path . '?v=' . (file_exists($file) ? (int)filemtime($file) : 0);
};
$apvCsrf = (string)($csrfToken ?? (class_exists('AdminAiIdolController', false) ? AdminAiIdolController::getCsrfToken() : ''));
$apvProducts = [];
foreach ((array)($products ?? []) as $apvProduct) {
    $apvRawImage = explode('|', (string)($apvProduct['link_hinh_anh'] ?? $apvProduct['hinh_anh'] ?? ''))[0];
    $apvProducts[] = [
        'id' => (string)($apvProduct['ma_san_pham'] ?? $apvProduct['id'] ?? ''),
        'name' => (string)($apvProduct['ten_san_pham'] ?? 'Sản phẩm chưa đặt tên'),
        'image' => resolve_image_url(trim($apvRawImage)),
        'price' => (float)($apvProduct['gia_ban'] ?? 0),
    ];
}
$apvConfig = [
    'csrf' => $apvCsrf,
    'products' => $apvProducts,
    'placeholder' => BASE_URL . '/assets/images/placeholder.svg',
];
$apvJsonFlags = JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_INVALID_UTF8_SUBSTITUTE;
?>
<link rel="stylesheet" href="<?= htmlspecialchars($apvAssetUrl('css/admin-product-videos.css'), ENT_QUOTES, 'UTF-8') ?>">
<main class="apv" id="adminProductVideos">
    <header class="apv-heading">
        <div>
            <div class="apv-eyebrow"><i class="bi bi-shop" aria-hidden="true"></i> XUẤT BẢN THƯƠNG MẠI</div>
            <h1>Video trang sản phẩm &amp; voucher</h1>
            <p>Chọn video AI Idol sẽ hiện trên trang chi tiết sản phẩm, đặt độ ưu tiên và gắn voucher cho khách xem video.</p>
        </div>
        <div class="apv-heading-actions">
            <a class="apv-btn secondary" href="index.php?r=admin_ai_idol"><i class="bi bi-stars" aria-hidden="true"></i> Về AI Idol Studio</a>
            <button type="button" class="apv-icon-btn" id="apvRefresh" aria-label="Làm mới danh sách video" title="Làm mới danh sách"><i class="bi bi-arrow-clockwise" aria-hidden="true"></i></button>
        </div>
    </header>

    <div id="apvNotice" class="apv-message" role="status" aria-live="polite" hidden></div>

    <section class="apv-panel" aria-label="Danh sách video">
        <div class="apv-toolbar">
            <div class="apv-filters" role="group" aria-label="Lọc video xuất bản">
                <button type="button" class="apv-filter is-active" data-apv-filter="all" aria-pressed="true">Tất cả video <span id="apvCountAll">(0)</span></button>
                <button type="button" class="apv-filter" data-apv-filter="published" aria-pressed="false">Đã xuất bản <span id="apvCountPublished">(0)</span></button>
                <button type="button" class="apv-filter" data-apv-filter="unpublished" aria-pressed="false">Chưa xuất bản <span id="apvCountUnpublished">(0)</span></button>
            </div>
            <div class="apv-search">
                <i class="bi bi-search" aria-hidden="true"></i>
                <input id="apvSearch" class="apv-input" type="search" placeholder="Tìm video, chương trình hoặc mã sản phẩm..." autocomplete="off" aria-label="Tìm video">
            </div>
        </div>
        <div class="apv-grid" id="apvGrid" aria-label="Danh sách video xuất bản" aria-busy="true">
            <div class="apv-loading" role="status"><span></span><span></span><span></span><p>Đang tải danh sách video…</p></div>
        </div>
    </section>

    <!-- Publish modal -->
    <div class="apv-backdrop" id="apvPubBackdrop" hidden>
        <div class="apv-modal" id="apvPubModal" role="dialog" aria-modal="true" aria-labelledby="apvPubTitle" tabindex="-1">
            <div class="apv-modal-header">
                <div>
                    <div class="apv-eyebrow"><i class="bi bi-sliders" aria-hidden="true"></i> THIẾT LẬP XUẤT BẢN</div>
                    <h2 id="apvPubTitle">Quản lý xuất bản video lên sản phẩm</h2>
                </div>
                <button type="button" class="apv-modal-close" data-apv-close="pub" aria-label="Đóng"><i class="bi bi-x-lg" aria-hidden="true"></i></button>
            </div>
            <div class="apv-modal-body">
                <div id="apvPubAlert" class="apv-message" role="alert" hidden></div>
                <div class="apv-summary">
                    <div class="apv-poster">
                        <img id="apvPubPoster" src="" alt="">
                        <span id="apvPubDuration" class="apv-duration">00:00</span>
                    </div>
                    <div class="apv-summary-meta">
                        <div class="apv-summary-top">
                            <span class="apv-campaign" id="apvPubCampaign">Chương trình AI Idol</span>
                            <span id="apvPubStatus" class="apv-badge unpublished">Chưa xuất bản</span>
                            <span id="apvPubAuto" class="apv-badge auto" hidden><i class="bi bi-robot" aria-hidden="true"></i> Tự động</span>
                        </div>
                        <div class="apv-field apv-field-flush">
                            <label for="apvPubVideoTitle">Tiêu đề video hiển thị trên trang sản phẩm</label>
                            <input type="text" id="apvPubVideoTitle" class="apv-input" placeholder="Tiêu đề video..." maxlength="200">
                        </div>
                    </div>
                </div>

                <div class="apv-field">
                    <label for="apvPubPriority">Độ ưu tiên hiển thị</label>
                    <input type="number" id="apvPubPriority" class="apv-input apv-input-narrow" min="1" max="1000" value="10" aria-describedby="apvPubPriorityHelp">
                    <small id="apvPubPriorityHelp" class="apv-help">Số càng lớn thì video xếp càng trước trên trang sản phẩm (1 đến 1000).</small>
                </div>

                <div class="apv-products">
                    <div class="apv-field-heading">
                        <span class="apv-strong" id="apvPubProductsLabel">Sản phẩm liên kết hiển thị video</span>
                        <span class="apv-count" id="apvPubCount" aria-live="polite">0 đã chọn</span>
                    </div>
                    <p class="apv-note">Video sẽ xuất hiện trên trang chi tiết của tất cả sản phẩm được chọn bên dưới.</p>
                    <div class="apv-picker-bar">
                        <div class="apv-search apv-grow">
                            <i class="bi bi-search" aria-hidden="true"></i>
                            <input id="apvPubSearch" class="apv-input" type="search" placeholder="Tìm tên hoặc mã sản phẩm trong kho..." autocomplete="off" aria-label="Tìm sản phẩm để liên kết">
                        </div>
                        <button type="button" class="apv-text-btn" id="apvPubSelectAll">Chọn tất cả</button>
                        <button type="button" class="apv-text-btn" id="apvPubClearAll">Bỏ chọn hết</button>
                    </div>
                    <div id="apvPubChips" class="apv-chips" hidden></div>
                    <div class="apv-field apv-field-top">
                        <label for="apvPubPrimary">Sản phẩm chính</label>
                        <select id="apvPubPrimary" class="apv-input"></select>
                    </div>
                    <div class="apv-product-list" id="apvPubList" role="group" aria-labelledby="apvPubProductsLabel"></div>
                </div>
            </div>
            <div class="apv-modal-footer">
                <button type="button" class="apv-btn danger-outline" id="apvPubUnpublish" hidden><i class="bi bi-eye-slash" aria-hidden="true"></i> Hủy xuất bản</button>
                <div class="apv-spacer"></div>
                <button type="button" class="apv-btn secondary" data-apv-close="pub">Đóng</button>
                <button type="button" class="apv-btn primary" id="apvPubSubmit"><i class="bi bi-cloud-arrow-up" aria-hidden="true"></i> Xuất bản lên trang sản phẩm</button>
            </div>
        </div>
    </div>

    <!-- Voucher (watch-to-reward) modal -->
    <div class="apv-backdrop" id="apvRewardBackdrop" hidden>
        <div class="apv-modal apv-modal-wide" id="apvRewardModal" role="dialog" aria-modal="true" aria-labelledby="apvRewardTitle" tabindex="-1">
            <div class="apv-modal-header">
                <div>
                    <div class="apv-eyebrow"><i class="bi bi-gift" aria-hidden="true"></i> VOUCHER XEM VIDEO</div>
                    <h2 id="apvRewardTitle">Cấu hình voucher xem video</h2>
                </div>
                <button type="button" class="apv-modal-close" data-apv-close="reward" aria-label="Đóng"><i class="bi bi-x-lg" aria-hidden="true"></i></button>
            </div>
            <div class="apv-modal-body">
                <div id="apvRewardAlert" class="apv-message" role="alert" hidden></div>
                <div class="apv-summary">
                    <div class="apv-poster">
                        <img id="apvRewardPoster" src="" alt="">
                        <span id="apvRewardDuration" class="apv-duration">00:00</span>
                    </div>
                    <div class="apv-summary-meta">
                        <div class="apv-summary-top">
                            <span class="apv-campaign" id="apvRewardCampaign">Chương trình AI Idol</span>
                            <span class="apv-badge published"><i class="bi bi-check-circle-fill" aria-hidden="true"></i> Đã xuất bản</span>
                        </div>
                        <h3 class="apv-summary-title" id="apvRewardVideoTitle">Tiêu đề video</h3>
                        <div class="apv-help">Mã video: <code id="apvRewardVideoId">—</code></div>
                    </div>
                </div>

                <div id="apvRewardStats" class="apv-stats" hidden>
                    <div class="apv-stats-header">
                        <span class="apv-eyebrow"><i class="bi bi-graph-up" aria-hidden="true"></i> DỮ LIỆU PHÁT HÀNH THỰC TẾ</span>
                        <span id="apvRewardStatsBadge" class="apv-badge published">Đang hoạt động</span>
                    </div>
                    <div class="apv-stats-grid">
                        <div class="apv-stat"><span class="apv-stat-label">Đã phát</span><strong id="apvStatIssued">0</strong><small>voucher</small></div>
                        <div class="apv-stat"><span class="apv-stat-label">Tổng hạn ngạch</span><strong id="apvStatQuota">0</strong><small>voucher</small></div>
                        <div class="apv-stat"><span class="apv-stat-label">Còn lại</span><strong id="apvStatRemaining">0</strong><small>voucher</small></div>
                        <div class="apv-stat"><span class="apv-stat-label">Giới hạn/khách</span><strong id="apvStatUserLimit">1</strong><small>lần</small></div>
                    </div>
                    <div class="apv-help apv-stats-meta" id="apvStatMeta"></div>
                </div>

                <form id="apvRewardForm" novalidate>
                    <input type="hidden" id="apvRewardCampaignId" value="">
                    <input type="hidden" id="apvRewardVideoIdField" value="">
                    <input type="hidden" id="apvRewardPublicationId" value="">

                    <div class="apv-field">
                        <label for="apvRewardName">Tên chiến dịch ưu đãi</label>
                        <input type="text" id="apvRewardName" class="apv-input" placeholder="Ví dụ: Voucher xem video Syna 3D - Giảm 15%" maxlength="150" required>
                    </div>

                    <div class="apv-row">
                        <div class="apv-field">
                            <label for="apvRewardType">Loại giảm giá</label>
                            <select id="apvRewardType" class="apv-input">
                                <option value="percentage">Phần trăm (%)</option>
                                <option value="fixed">Số tiền cố định (VNĐ)</option>
                            </select>
                        </div>
                        <div class="apv-field">
                            <label for="apvRewardValue" id="apvRewardValueLabel">Mức giảm (%)</label>
                            <input type="number" id="apvRewardValue" class="apv-input" min="1" max="100" value="10" required aria-describedby="apvRewardValueHelp">
                            <small class="apv-help" id="apvRewardValueHelp">Nhập từ 1 đến 100%</small>
                        </div>
                    </div>

                    <div class="apv-row">
                        <div class="apv-field" id="apvRewardMaxWrap">
                            <label for="apvRewardMax">Mức giảm tối đa (VNĐ) <span class="apv-optional">Tùy chọn</span></label>
                            <input type="number" id="apvRewardMax" class="apv-input" min="0" step="5000" placeholder="Để trống nếu không giới hạn" aria-describedby="apvRewardMaxHelp">
                            <small class="apv-help" id="apvRewardMaxHelp">Áp dụng cho giảm phần trăm</small>
                        </div>
                        <div class="apv-field">
                            <label for="apvRewardMin">Đơn hàng tối thiểu (VNĐ)</label>
                            <input type="number" id="apvRewardMin" class="apv-input" min="0" step="10000" value="0" aria-describedby="apvRewardMinHelp">
                            <small class="apv-help" id="apvRewardMinHelp">0 = không yêu cầu đơn tối thiểu</small>
                        </div>
                    </div>

                    <div class="apv-row">
                        <div class="apv-field">
                            <label for="apvRewardStart">Ngày bắt đầu</label>
                            <input type="datetime-local" id="apvRewardStart" class="apv-input" required>
                        </div>
                        <div class="apv-field">
                            <label for="apvRewardEnd">Ngày kết thúc</label>
                            <input type="datetime-local" id="apvRewardEnd" class="apv-input" required>
                        </div>
                    </div>

                    <div class="apv-row">
                        <div class="apv-field">
                            <label for="apvRewardQuota">Tổng hạn ngạch phát hành</label>
                            <input type="number" id="apvRewardQuota" class="apv-input" min="1" max="100000" value="100" required aria-describedby="apvRewardQuotaHelp">
                            <small class="apv-help" id="apvRewardQuotaHelp">Tối thiểu 1</small>
                        </div>
                        <div class="apv-field">
                            <label for="apvRewardUserLimit">Giới hạn nhận / khách hàng</label>
                            <input type="number" id="apvRewardUserLimit" class="apv-input" min="1" max="10" value="1" required aria-describedby="apvRewardUserLimitHelp">
                            <small class="apv-help" id="apvRewardUserLimitHelp">Mặc định mỗi khách nhận 1 lần</small>
                        </div>
                    </div>

                    <fieldset class="apv-field apv-policy">
                        <legend>Chính sách cộng dồn ưu đãi</legend>
                        <label class="apv-check">
                            <input type="checkbox" id="apvRewardStackFlash">
                            <span><strong>Cho phép áp dụng chung với Flash Sale</strong><small>Mặc định tắt. Khi bật, khách có thể dùng voucher này cho sản phẩm đang trong phiên Flash Sale.</small></span>
                        </label>
                        <label class="apv-check">
                            <input type="checkbox" id="apvRewardStackOther">
                            <span><strong>Cho phép cộng dồn với voucher khuyến mãi khác</strong><small>Mặc định tắt. Không khuyến khích để tránh chồng chéo chiết khấu.</small></span>
                        </label>
                    </fieldset>

                    <div class="apv-field">
                        <label class="apv-check apv-check-card">
                            <input type="checkbox" id="apvRewardActive" checked>
                            <span><strong>Kích hoạt chiến dịch ngay</strong><small>Mỗi video chỉ được có tối đa 1 chiến dịch voucher ở trạng thái kích hoạt.</small></span>
                        </label>
                    </div>

                    <div class="apv-products">
                        <div class="apv-field-heading">
                            <span class="apv-strong" id="apvRewardProductsLabel">Sản phẩm áp dụng voucher</span>
                            <span class="apv-count" id="apvRewardCount" aria-live="polite">0 đã chọn</span>
                        </div>
                        <p class="apv-note">Voucher chỉ có hiệu lực cho các sản phẩm được chọn bên dưới khi thanh toán.</p>
                        <div class="apv-picker-bar">
                            <div class="apv-search apv-grow">
                                <i class="bi bi-search" aria-hidden="true"></i>
                                <input id="apvRewardSearch" class="apv-input" type="search" placeholder="Tìm sản phẩm áp dụng..." autocomplete="off" aria-label="Tìm sản phẩm áp dụng voucher">
                            </div>
                            <button type="button" class="apv-text-btn" id="apvRewardSelectLinked">Chọn các sản phẩm của video</button>
                            <button type="button" class="apv-text-btn" id="apvRewardClearAll">Bỏ chọn hết</button>
                        </div>
                        <div id="apvRewardChips" class="apv-chips" hidden></div>
                        <div class="apv-product-list" id="apvRewardList" role="group" aria-labelledby="apvRewardProductsLabel"></div>
                    </div>
                </form>
            </div>
            <div class="apv-modal-footer">
                <button type="button" class="apv-btn danger-outline" id="apvRewardToggle" hidden><i class="bi bi-pause-circle" aria-hidden="true"></i> Tắt chiến dịch</button>
                <div class="apv-spacer"></div>
                <button type="button" class="apv-btn secondary" data-apv-close="reward">Đóng</button>
                <button type="button" class="apv-btn primary" id="apvRewardSubmit"><i class="bi bi-check2-circle" aria-hidden="true"></i> Lưu cấu hình voucher</button>
            </div>
        </div>
    </div>
</main>
<script type="application/json" id="apvConfig"><?= json_encode($apvConfig, $apvJsonFlags) ?></script>
<script src="<?= htmlspecialchars($apvAssetUrl('js/admin-product-videos.js'), ENT_QUOTES, 'UTF-8') ?>" defer></script>
