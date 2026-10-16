<?php
// AI Idol Studio shell: header, overview, service health, the two-mode switch (Tự động / Thủ công)
// and the shared Activity column. The panels start empty; studio-auto.js, studio-manual.js and
// studio-activity.js render into #autoFlow, #manualFlow and #activityPanel.
$studioAssets = dirname(__DIR__, 3) . '/public/assets';
// Script files that a later step has not created yet must not raise a PHP warning (they just 404).
$studioAssetUrl = static function (string $path) use ($studioAssets): string {
    $file = $studioAssets . '/' . $path;
    return BASE_URL . '/assets/' . $path . '?v=' . (file_exists($file) ? (int)filemtime($file) : 0);
};
// Load order matters: pure logic -> shared core -> manual wizard -> auto flow -> activity panel.
$studioScripts = ['ai-idol-approval.js', 'studio-logic.js', 'studio-core.js', 'studio-manual.js', 'studio-auto.js', 'studio-activity.js'];
$studioCsrf = (string)($csrfToken ?? (class_exists('AdminAiIdolController', false) ? AdminAiIdolController::getCsrfToken() : ''));
$studioJsonFlags = JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_INVALID_UTF8_SUBSTITUTE;

// Tab Thủ công (#manualFlow): the filter labels, values and counts of the product filters. $filterCounts and
// $catalogTree come from AdminAiIdolController::index(); the fallbacks are the counts the old page showed.
$wzEsc = static fn($value): string => htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
$wzNumber = static fn($value): string => number_format((int)$value, 0, ',', '.');
$wzCounts = is_array($filterCounts ?? null) ? $filterCounts : [];
$wzTree = is_array($catalogTree ?? null) ? $catalogTree : [];
$wzTotal = $wzCounts['total'] ?? 2473;
// Group select (Nhóm): a root category found in the tree is offered by its numeric id with the count the tree
// holds; a group with no root in the database keeps the static option the page always had.
// [tree key => [label, static value, static count text]]
$wzGroups = [
    'face' => ['Chăm Sóc Da Mặt', 'face', 'Có ' . $wzNumber(2473)],
    'makeup' => ['Trang Điểm', 'makeup', '0'],
    'body' => ['Chăm Sóc Cơ Thể', 'body', '0'],
    'hair' => ['Chăm Sóc Tóc', 'hair', '0'],
];
$wzGroupOptions = [];
foreach ($wzGroups as $wzKey => [$wzLabel, $wzValue, $wzCountText]) {
    $wzNode = $wzTree[$wzKey] ?? null;
    if (is_array($wzNode) && (int)($wzNode['id'] ?? 0) > 0) {
        $wzValue = (string)(int)$wzNode['id'];
        $wzLabel = trim((string)($wzNode['label'] ?? '')) !== '' ? (string)$wzNode['label'] : $wzLabel;
        $wzCountText = 'Có ' . $wzNumber($wzNode['count'] ?? 0);
    }
    $wzGroupOptions[] = [$wzValue, $wzLabel, $wzCountText];
}
foreach ($wzTree as $wzKey => $wzNode) { // any other root the database has
    if (!isset($wzGroups[$wzKey]) && is_array($wzNode) && (int)($wzNode['id'] ?? 0) > 0) {
        $wzGroupOptions[] = [(string)(int)$wzNode['id'], (string)($wzNode['label'] ?? ''), 'Có ' . $wzNumber($wzNode['count'] ?? 0)];
    }
}
// [value, label, count|null]: a null count shows no "(N sản phẩm)".
$wzExpiry = [
    ['', 'Hạn dùng: không lọc', null],
    ['expiry_17_19', 'Hạn còn 17–19 tháng', $wzCounts['expiry_17_19'] ?? 0],
    ['expiry_max_18', 'Hạn tối đa 18 tháng', $wzCounts['expiry_max_18'] ?? 0],
];
$wzStock = [
    ['', 'Tồn kho: không lọc', null],
    ['stock_high', 'Tồn kho nhiều (>100)', $wzCounts['stock_high'] ?? 0],
    ['stock_low', 'Tồn kho thấp (≤10)', $wzCounts['stock_low'] ?? 0],
    ['out_of_stock', 'Đã hết hàng', $wzCounts['out_of_stock'] ?? 0],
];
$wzSales = [
    ['', 'Không chọn', null],
    ['best_30', 'Bán chạy · 30 ngày', $wzCounts['best_30'] ?? 45],
    ['best_7', 'Bán tốt · 7 ngày', $wzCounts['best_7'] ?? 20],
    ['best_all', 'Bán chạy · toàn kỳ', $wzCounts['best_all'] ?? 2108],
    ['revenue_low_conversion', 'Doanh thu cao, chuyển đổi thấp', null],
    ['views_no_orders', 'Xem nhiều, chưa mua', null],
    ['cart', 'Thêm giỏ nhiều', null],
    ['discount_slow', 'Giảm giá, bán chậm', null],
    ['growth_7', 'Tăng trưởng · 7 ngày', null],
];
$wzRadios = static function (string $name, array $options) use ($wzEsc, $wzNumber): void {
    foreach ($options as [$value, $label, $count]) {
        echo '<label class="wz-option"><input type="radio" name="', $name, '" value="', $wzEsc($value), '"', $value === '' ? ' checked' : '', '>',
            '<span>', $wzEsc($label), $count === null ? '' : ' <span class="wz-count">(' . $wzNumber($count) . ' sản phẩm)</span>', '</span></label>';
    }
};
?>
<link rel="stylesheet" href="<?= $studioAssetUrl('css/ai-idol-studio.css') ?>">
<main class="idol-studio" id="idolStudio">
    <header class="studio-heading">
        <div>
            <div class="studio-eyebrow"><span class="studio-mark" aria-hidden="true"><i class="bi bi-camera-reels"></i></span> SKINSYNTAX / STUDIO</div>
            <h1>Chăm chút từng buổi live.</h1>
            <p>Từ sản phẩm của bạn đến một chương trình sẵn sàng lên sóng.</p>
        </div>
        <a class="studio-link" href="index.php?r=admin_lives">Quản lý phiên live <i class="bi bi-arrow-up-right" aria-hidden="true"></i></a>
    </header>
    <div class="studio-overview" aria-label="Tổng quan chương trình đã tải">
        <div class="studio-stat"><i class="bi bi-collection-play" aria-hidden="true"></i><strong id="totalCampaigns">—</strong><span>Chương trình gần đây</span></div>
        <div class="studio-stat"><i class="bi bi-pencil-square" aria-hidden="true"></i><strong id="approvalCount">—</strong><span>Chờ duyệt kịch bản</span></div>
        <div class="studio-stat"><i class="bi bi-calendar2-check" aria-hidden="true"></i><strong id="scheduledCount">—</strong><span>Đã lên lịch</span></div>
        <div class="studio-sync" id="syncStatus" role="status"><span class="sync-dot"></span> Đang kết nối</div>
    </div>
    <div class="idol-health" id="idolHealth" role="status" aria-live="polite" aria-label="Tình trạng dịch vụ"><span class="health-title">Dịch vụ</span><span class="health-note">Đang kiểm tra…</span></div>
    <div class="studio-layout">
        <div class="studio-main">
            <div class="studio-flow-switch" role="tablist" aria-label="Chọn cách tạo chương trình">
                <button type="button" class="flow-tab is-active" id="autoFlowTab" role="tab" aria-selected="true" aria-controls="autoFlow" tabindex="0" data-studio-flow="auto"><i class="bi bi-stars" aria-hidden="true"></i><span><strong>Tự động</strong><small>Giao việc cho Syna bằng một câu</small></span></button>
                <button type="button" class="flow-tab" id="manualFlowTab" role="tab" aria-selected="false" aria-controls="manualFlow" tabindex="-1" data-studio-flow="manual"><i class="bi bi-sliders" aria-hidden="true"></i><span><strong>Thủ công</strong><small>Tự chọn từng bước</small></span></button>
            </div>
            <section class="studio-flow-panel" id="autoFlow" role="tabpanel" aria-labelledby="autoFlowTab">
                <form class="studio-panel au" id="autoForm" novalidate>
                    <div class="panel-heading"><div><span class="studio-eyebrow">TỰ ĐỘNG</span><h2>Giao việc cho Syna</h2></div></div>
                    <div class="au-body">
                        <div class="idol-field">
                            <label for="autoBrief">Bạn muốn làm gì? <span class="au-count" id="autoBriefCount" aria-hidden="true">0 / 4000</span></label>
                            <textarea id="autoBrief" rows="5" maxlength="4000" aria-describedby="autoBriefHint autoMessage" placeholder="Ví dụ: Làm video sản phẩm cho 5 kem chống nắng bán chạy nhất trong 30 ngày qua."></textarea>
                            <p class="au-hint" id="autoBriefHint">Ghi sản phẩm (tên, mã, hoặc điều kiện như bán chạy 30 ngày, hạn 17–19 tháng), mục đích (video sản phẩm / livestream), giờ phát và giọng dẫn nếu muốn. Thiếu gì Syna sẽ tự quyết và ghi rõ.</p>
                        </div>
                        <div class="au-chips" id="autoChips" role="group" aria-label="Mẫu gợi ý"></div>
                        <div class="studio-message" id="autoMessage" role="alert" hidden></div>
                        <div class="au-actions">
                            <button type="button" class="idol-btn secondary" id="autoUndo" hidden><i class="bi bi-arrow-counterclockwise" aria-hidden="true"></i> Khôi phục lệnh trước</button>
                            <button type="submit" class="idol-btn primary" id="autoSubmit"><i class="bi bi-send" aria-hidden="true"></i> Giao cho Syna</button>
                        </div>
                    </div>
                </form>
                <div class="studio-panel au-card" id="autoCard" role="region" aria-label="Syna đã quyết định" aria-live="polite" hidden></div>
            </section>
            <section class="studio-flow-panel" id="manualFlow" role="tabpanel" aria-labelledby="manualFlowTab" hidden>
                <div class="studio-panel wz">
                    <div class="panel-heading"><div><span class="studio-eyebrow">THỦ CÔNG</span><h2>Tạo chương trình từng bước</h2></div><span class="step-counter" id="manualStepCounter">Bước 1 / 4</span></div>
                    <ol class="wz-steps" role="list" aria-label="Các bước tạo chương trình">
                        <li><button type="button" class="wz-step is-active" data-wz-step="1" aria-current="step"><span class="wz-step-no" aria-hidden="true">1</span><span class="wz-step-name"><span class="visually-hidden">Bước 1: </span>Sản phẩm<span class="wz-step-hint visually-hidden"></span></span></button></li>
                        <li><button type="button" class="wz-step" data-wz-step="2"><span class="wz-step-no" aria-hidden="true">2</span><span class="wz-step-name"><span class="visually-hidden">Bước 2: </span>Mục đích &amp; lịch<span class="wz-step-hint visually-hidden"></span></span></button></li>
                        <li><button type="button" class="wz-step" data-wz-step="3"><span class="wz-step-no" aria-hidden="true">3</span><span class="wz-step-name"><span class="visually-hidden">Bước 3: </span>Nội dung &amp; nhân vật<span class="wz-step-hint visually-hidden"></span></span></button></li>
                        <li><button type="button" class="wz-step" data-wz-step="4"><span class="wz-step-no" aria-hidden="true">4</span><span class="wz-step-name"><span class="visually-hidden">Bước 4: </span>Xem lại &amp; tạo<span class="wz-step-hint visually-hidden"></span></span></button></li>
                    </ol>
                    <form id="manualForm" class="wz-form" novalidate>
                        <section class="wz-step-panel" id="manualStep1" aria-labelledby="manualStepTitle1">
                            <h3 class="wz-step-title" id="manualStepTitle1" tabindex="-1">Bước 1 · Chọn sản phẩm</h3>
                            <p class="wz-lead">Lọc theo danh mục, kho, bán hàng rồi tick các sản phẩm bạn muốn. Lựa chọn được giữ lại khi bạn đổi bộ lọc.</p>
                            <div class="wz-split">
                                <div class="wz-filters" id="manualFilters">
                                    <fieldset class="wz-group">
                                        <legend>1 · Danh mục sản phẩm <span>chọn tối đa 1 nhánh</span></legend>
                                        <label class="wz-field">Nhóm<select id="manualCategoryLevel1" class="idol-select"><option value="">Không giới hạn (Tất cả <?= $wzNumber($wzTotal) ?> sản phẩm)</option><?php foreach ($wzGroupOptions as [$wzValue, $wzLabel, $wzCountText]): ?><option value="<?= $wzEsc($wzValue) ?>"><?= $wzEsc($wzLabel) ?> (<?= $wzEsc($wzCountText) ?> sản phẩm)</option><?php endforeach; ?></select></label>
                                        <label class="wz-field">Danh mục<select id="manualCategoryLevel2" class="idol-select" disabled><option value="">Chọn nhóm trước</option></select></label>
                                        <label class="wz-field">Phân loại<select id="manualCategoryLevel3" class="idol-select" disabled><option value="">Chọn danh mục trước</option></select></label>
                                    </fieldset>
                                    <fieldset class="wz-group">
                                        <legend>2 · Kho &amp; hạn sử dụng <span>mỗi nhóm chọn 1</span></legend>
                                        <div class="wz-options" role="radiogroup" aria-label="Hạn sử dụng"><?php $wzRadios('manualExpiry', $wzExpiry); ?></div>
                                        <div class="wz-options" role="radiogroup" aria-label="Tồn kho"><?php $wzRadios('manualStock', $wzStock); ?></div>
                                        <label class="wz-field">Phạm vi kho<select id="manualWarehouseMode" class="idol-select"><option value="">Tất cả kho</option><option value="warehouse">Chỉ lấy một kho</option><option value="batch">Chỉ lấy một lô hàng</option></select></label>
                                        <label class="wz-field" id="manualWarehouseValue" hidden><span id="manualWarehouseLabel">Tên hoặc mã kho</span><input id="manualWarehouse" class="idol-input" maxlength="80" placeholder="Ví dụ: KHO-HN-01" autocomplete="off"></label>
                                    </fieldset>
                                    <fieldset class="wz-group">
                                        <legend>3 · Phân tích bán hàng <span>chọn 1</span></legend>
                                        <div class="wz-options" role="radiogroup" aria-label="Phân tích bán hàng"><?php $wzRadios('manualSales', $wzSales); ?></div>
                                    </fieldset>
                                    <fieldset class="wz-group">
                                        <legend>4 · Số lượng &amp; thương hiệu <span>mỗi loại chỉ chọn 1</span></legend>
                                        <label class="wz-field">Số lượng<select id="manualQuantity" class="idol-select"><option value="">Tự chọn phù hợp</option><option value="1">Top 1</option><option value="3">Top 3</option><option value="5">Top 5</option><option value="10">Top 10</option></select></label>
                                        <label class="wz-field">Thương hiệu ưu tiên <span class="field-optional">Không bắt buộc</span><input id="manualBrand" class="idol-input" maxlength="80" placeholder="Ví dụ: CeraVe · để trống nếu muốn nhiều thương hiệu" autocomplete="off"></label>
                                        <div class="wz-options">
                                            <label class="wz-option"><input type="checkbox" id="manualBrandCap" value="1"><span>Mỗi thương hiệu tối đa 2 sản phẩm</span></label>
                                            <label class="wz-option"><input type="checkbox" id="manualEnoughStock" value="1"><span>Ưu tiên sản phẩm còn đủ hàng để live</span></label>
                                        </div>
                                    </fieldset>
                                </div>
                                <div class="wz-results" id="manualResults" role="region" aria-labelledby="manualResultsTitle" aria-busy="false">
                                    <div class="wz-results-head"><h4 id="manualResultsTitle">Sản phẩm phù hợp</h4><span class="wz-selected-count" id="manualSelectedCount">0 đã chọn</span></div>
                                    <div class="wz-toolbar">
                                        <div class="studio-search"><i class="bi bi-search" aria-hidden="true"></i><input type="search" id="manualSearch" class="idol-input" placeholder="Lọc nhanh tên hoặc mã SP trong kết quả…" aria-label="Lọc nhanh tên hoặc mã sản phẩm trong kết quả" autocomplete="off"></div>
                                        <button type="button" class="text-button" id="manualSelectAll">Chọn tất cả</button>
                                        <button type="button" class="text-button" id="manualDeselect" title="Bỏ chọn mọi sản phẩm đã chọn" disabled>Bỏ chọn</button>
                                    </div>
                                    <div class="wz-live" aria-live="polite">
                                        <p class="wz-status" id="manualResultStatus"></p>
                                        <ul class="wz-notes" id="manualResultNotes" role="list" hidden></ul>
                                    </div>
                                    <div class="wz-error" id="manualResultError" role="alert" hidden>
                                        <p id="manualResultErrorText"></p>
                                        <button type="button" class="idol-btn secondary" id="manualRetry"><i class="bi bi-arrow-clockwise" aria-hidden="true"></i> Thử lại</button>
                                    </div>
                                    <ul class="wz-product-list" id="manualProductList" role="list" aria-label="Danh sách sản phẩm" hidden></ul>
                                    <div class="wz-chips" id="manualChips" role="group" aria-label="Sản phẩm đã chọn" hidden></div>
                                </div>
                            </div>
                        </section>

                        <section class="wz-step-panel" id="manualStep2" aria-labelledby="manualStepTitle2" hidden>
                            <h3 class="wz-step-title" id="manualStepTitle2" tabindex="-1">Bước 2 · Mục đích &amp; lịch phát</h3>
                            <p class="wz-lead">Chọn mục đích trước vì nó quyết định phong cách dẫn nào được dùng ở bước sau.</p>
                            <div class="wz-purposes" role="radiogroup" aria-label="Chế độ phát sóng">
                                <label class="wz-purpose"><input type="radio" name="manualPurpose" value="product_video" checked><span><strong>Video sản phẩm <em>Mặc định</em></strong><small>Không phát sóng. Không nói giá, nói kỹ thành phần và thông số. Tự hiện trên trang sản phẩm khi video sẵn sàng.</small></span></label>
                                <label class="wz-purpose"><input type="radio" name="manualPurpose" value="live_and_product"><span><strong>Livestream + video sản phẩm</strong><small>Livestream được nói giá live. Phần giới thiệu không có giá tự lên trang sản phẩm sau khi phát xong.</small></span></label>
                                <label class="wz-purpose"><input type="radio" name="manualPurpose" value="live_only"><span><strong>Chỉ livestream</strong><small>Được nói giá live, khuyến mãi có hạn, kêu gọi chốt đơn. Không lưu video lên trang sản phẩm.</small></span></label>
                            </div>
                            <div id="manualLiveFields" hidden>
                                <div class="idol-row">
                                    <div class="idol-field"><label for="manualScheduledAt">Ngày &amp; giờ phát</label><input id="manualScheduledAt" type="datetime-local" class="idol-input"></div>
                                    <div class="idol-field"><label for="manualDuration">Thời lượng <span class="field-optional">phút</span></label><input id="manualDuration" type="number" min="1" max="720" value="60" class="idol-input"></div>
                                </div>
                                <p class="idol-note" id="manualPrepNote"></p>
                                <fieldset class="wz-platform-group">
                                    <legend>Nơi phát chương trình</legend>
                                    <div class="platforms">
                                        <label class="platform"><input type="checkbox" name="manualPlatform" value="internal" checked><i class="bi bi-display" aria-hidden="true"></i><span>Xem nội bộ</span></label>
                                        <label class="platform"><input type="checkbox" name="manualPlatform" value="youtube"><i class="bi bi-youtube" aria-hidden="true"></i><span>YouTube</span></label>
                                        <label class="platform"><input type="checkbox" name="manualPlatform" value="facebook"><i class="bi bi-facebook" aria-hidden="true"></i><span>Facebook</span></label>
                                    </div>
                                </fieldset>
                                <p class="idol-note">YouTube và Facebook cần được cấu hình khóa phát trước khi lên sóng.</p>
                                <p class="wz-warn" id="manualRtmpWarn" role="status" hidden></p>
                            </div>
                            <div class="approval-reminder" id="manualProductVideoNote"><i class="bi bi-magic" aria-hidden="true"></i><div><strong>Video sản phẩm tự duyệt và tự đăng.</strong><p>Kịch bản qua bộ kiểm tra sẽ được tạo video ngay, rồi tự lên trang sản phẩm.</p></div></div>
                            <div class="approval-reminder" id="manualApprovalNote" hidden><i class="bi bi-pencil-square" aria-hidden="true"></i><div><strong>Bạn duyệt xong, video mới được tạo.</strong><p>Kịch bản sẽ xuất hiện trong chương trình để bạn chỉnh sửa và chấp nhận.</p></div></div>
                        </section>

                        <section class="wz-step-panel" id="manualStep3" aria-labelledby="manualStepTitle3" hidden>
                            <h3 class="wz-step-title" id="manualStepTitle3" tabindex="-1">Bước 3 · Nội dung &amp; nhân vật</h3>
                            <div class="idol-field"><label for="manualName">Tên chương trình <span class="field-optional">Không bắt buộc</span></label><input id="manualName" class="idol-input" maxlength="160" placeholder="Ví dụ: Một chút chăm da, tối thứ Sáu" autocomplete="off"></div>
                            <div class="idol-field"><label for="manualContentMode">Cách kể câu chuyện</label><select id="manualContentMode" class="idol-select"><option value="Product Intro">Giới thiệu sản phẩm</option><option value="Problem-Solution">Vấn đề &amp; giải pháp</option><option value="Scientific Review">Phân tích thành phần</option></select></div>
                            <fieldset class="wz-group wz-styles">
                                <legend>Cách dẫn chuyện của Syna <span>có thể chọn nhiều</span></legend>
                                <div class="wz-style-grid" id="manualStyleGrid"></div>
                                <p class="wz-style-note" id="manualStyleNote" hidden></p>
                            </fieldset>
                            <div class="idol-field"><label for="manualCharacter">Nhân vật dẫn chương trình</label><select id="manualCharacter" class="idol-select"><option value="syna_3d">Syna 3D · mèo rau má của SkinSyntax</option><option value="custom">Ảnh hoặc video nhân vật riêng</option></select><p class="idol-note" id="manualSynaHelp">Syna nói theo kịch bản bạn duyệt; giữ ảnh, tên, giá sản phẩm và có bảng thành phần riêng. Không cần tải video mẫu. Bản này hỗ trợ khung ngang 16:9.</p></div>
                            <div class="idol-field"><label for="manualFormat">Khung hình</label><select id="manualFormat" class="idol-select"><option value="16:9">Ngang 16:9 · phù hợp livestream</option><option value="9:16">Dọc 9:16 · phù hợp điện thoại</option></select></div>
                            <div class="asset-grid">
                                <div class="asset-picker" id="manualCustomAvatar" hidden>
                                    <label class="asset-drop" for="manualAvatarFile"><span class="asset-icon"><i class="bi bi-person-video3" aria-hidden="true"></i></span><strong>Nhân vật chính</strong><span id="manualAvatarFilename">Chọn video hoặc ảnh</span><small>Video ≤ 35 MB · ảnh ≤ 10 MB</small><input id="manualAvatarFile" type="file" accept="image/png,image/jpeg,image/webp,video/mp4,video/webm"></label>
                                    <img id="manualAvatarImage" class="asset-preview" alt="Xem trước nhân vật">
                                    <video id="manualAvatarVideo" class="asset-preview" muted loop controls playsinline aria-label="Xem trước video nhân vật"></video>
                                    <div class="asset-caption"><span class="small-tag">Gợi ý</span> Video 5–30 giây có cử động tự nhiên.</div>
                                </div>
                                <div class="asset-picker">
                                    <label class="asset-drop" for="manualBackgroundFile"><span class="asset-icon peach"><i class="bi bi-image" aria-hidden="true"></i></span><strong>Phông nền</strong><span id="manualBackgroundFilename">Chọn ảnh nền · tùy chọn</span><small>PNG, JPG, WebP ≤ 10 MB</small><input id="manualBackgroundFile" type="file" accept="image/png,image/jpeg,image/webp"></label>
                                    <img id="manualBackgroundImage" class="asset-preview" alt="Xem trước phông nền">
                                    <div class="asset-caption">Ảnh ngang 1920 × 1080 cho khung 16:9.</div>
                                </div>
                            </div>
                            <div id="manualAvatarNote" class="avatar-mode-note" hidden>Chọn nhân vật để xem trước tại đây.</div>
                            <section class="syna-live-preview" id="manualSynaPreview" aria-labelledby="manualSynaTitle">
                                <div class="syna-live-preview-heading">
                                    <div><span class="studio-eyebrow">NHÂN VẬT CỦA BẠN</span><h3 id="manualSynaTitle">Syna 3D</h3></div>
                                    <span class="small-tag">Xem thử chuyển động</span>
                                </div>
                                <p class="idol-note">Khung dưới chỉ minh họa chuyển động. Video chương trình sẽ dùng sản phẩm, giá và giọng đọc của kịch bản bạn duyệt — không dùng lại clip mẫu này.</p>
                                <iframe class="syna-live-preview-frame" title="Bản thử Syna 3D cho livestream" src="<?= BASE_URL ?>/ai-idol-mascot-3d/index.html?embed=1&amp;rev=syna-native-v3" loading="lazy"></iframe>
                                <p class="idol-note">Khi tạo video, cần bật Docker và dịch vụ Syna local. Không sử dụng Wav2Lip hay SadTalker cho Syna.</p>
                            </section>
                            <details class="studio-tips"><summary>Chuẩn bị hình ảnh thế nào cho đẹp?</summary><p>Dùng video 720p, camera cố định, mặt rõ và có sẵn chớp mắt, chuyển động đầu, vai hoặc tay. Hệ thống giữ các chuyển động đó và tạo lại khẩu hình.</p><p>Với ảnh tĩnh, chỉ miệng và đầu cử động nhẹ. Với video, phông nền đã có trong clip vẫn được giữ; ảnh nền bạn chọn chỉ lấp phần viền khi khác tỉ lệ.</p></details>
                        </section>

                        <section class="wz-step-panel" id="manualStep4" aria-labelledby="manualStepTitle4" hidden>
                            <h3 class="wz-step-title" id="manualStepTitle4" tabindex="-1">Bước 4 · Xem lại &amp; tạo</h3>
                            <section class="wz-intent" aria-labelledby="manualIntentTitle">
                                <h4 id="manualIntentTitle">Ý định của bạn</h4>
                                <p id="manualIntent" aria-live="polite"></p>
                            </section>
                            <div class="wz-review" id="manualReview"></div>
                        </section>

                        <div class="studio-message" id="manualMessage" role="alert" hidden></div>
                        <div class="wz-footer">
                            <p class="wz-summary" id="manualSummary"></p>
                            <div class="wz-nav">
                                <button type="button" class="idol-btn secondary" id="manualPrev" hidden><i class="bi bi-arrow-left" aria-hidden="true"></i> Quay lại</button>
                                <button type="button" class="idol-btn primary" id="manualNext">Tiếp tục <i class="bi bi-arrow-right" aria-hidden="true"></i></button>
                                <button type="submit" class="idol-btn primary" id="manualCreate" hidden>Tạo chương trình</button>
                            </div>
                        </div>
                    </form>
                </div>
            </section>
        </div>
        <aside class="studio-activity" id="activityPanel" aria-label="Hoạt động">
            <section class="studio-panel act">
                <div class="panel-heading"><div><span class="studio-eyebrow">HOẠT ĐỘNG</span><h2>Việc Syna đang làm</h2></div><button type="button" class="icon-button" id="activityRefresh" aria-label="Làm mới hoạt động" title="Làm mới"><i class="bi bi-arrow-clockwise" aria-hidden="true"></i></button></div>
                <div class="act-filters" role="group" aria-label="Lọc hoạt động">
                    <button type="button" class="act-filter is-active" data-act-filter="all" aria-pressed="true">Tất cả <span class="act-count">0</span></button>
                    <button type="button" class="act-filter" data-act-filter="needs_you" aria-pressed="false">Cần bạn <span class="act-count">0</span></button>
                    <button type="button" class="act-filter" data-act-filter="scheduled" aria-pressed="false">Đã lên lịch <span class="act-count">0</span></button>
                </div>
                <div class="visually-hidden" id="activityLive" role="status" aria-live="polite"></div>
                <div class="act-list" id="activityList" aria-busy="true"><p class="act-empty">Đang tải hoạt động…</p></div>
                <div class="detail act-detail" id="activityDetail" role="region" aria-labelledby="actDetailTitle" tabindex="-1" hidden>
                    <div id="activityDetailBody"></div>
                    <div class="studio-message" id="activityDetailMessage" role="alert" hidden></div>
                </div>
            </section>
        </aside>
    </div>
</main>
<script>
window.AI_IDOL_CSRF = <?= json_encode($studioCsrf, $studioJsonFlags) ?>;
</script>
<script>
window.__IDOL_CATALOG__ = <?= json_encode($catalogTree ?? [], $studioJsonFlags) ?>;
window.__IDOL_FILTER_COUNTS__ = <?= json_encode($filterCounts ?? [], $studioJsonFlags) ?>;
</script>
<?php foreach ($studioScripts as $studioScript): ?>
<script src="<?= $studioAssetUrl('js/' . $studioScript) ?>" defer></script>
<?php endforeach; ?>
