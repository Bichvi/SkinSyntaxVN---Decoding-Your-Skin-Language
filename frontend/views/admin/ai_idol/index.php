<?php
$studioAssets = dirname(__DIR__, 3) . '/public/assets';
$studioEscape = static fn($value): string => htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
?>
<link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/ai-idol-studio.css?v=<?= filemtime($studioAssets . '/css/ai-idol-studio.css') ?>">
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
    <?php
    $activeFlow = $_GET['flow'] ?? $_GET['tab'] ?? 'manual';
    if (!in_array($activeFlow, ['manual', 'agent', 'publication'], true)) {
        $activeFlow = 'manual';
    }
    ?>
    <div class="studio-flow-switch" role="tablist" aria-label="Chọn chế độ làm việc">
        <button type="button" class="flow-tab <?= $activeFlow === 'manual' ? 'is-active' : '' ?>" id="manualFlowTab" role="tab" aria-selected="<?= $activeFlow === 'manual' ? 'true' : 'false' ?>" aria-controls="manualFlow" data-studio-flow="manual"><i class="bi bi-sliders" aria-hidden="true"></i><span><strong>Wizard thủ công</strong><small>Chủ động chọn từng thiết lập</small></span></button>
        <button type="button" class="flow-tab <?= $activeFlow === 'agent' ? 'is-active' : '' ?>" id="agentFlowTab" role="tab" aria-selected="<?= $activeFlow === 'agent' ? 'true' : 'false' ?>" aria-controls="agentFlow" data-studio-flow="agent"><i class="bi bi-stars" aria-hidden="true"></i><span><strong>Agent tự động</strong><small>Mô tả một lần, Agent tự lập kế hoạch</small></span></button>
        <button type="button" class="flow-tab <?= $activeFlow === 'publication' ? 'is-active' : '' ?>" id="pubFlowTab" role="tab" aria-selected="<?= $activeFlow === 'publication' ? 'true' : 'false' ?>" aria-controls="pubFlow" data-studio-flow="publication"><i class="bi bi-film" aria-hidden="true"></i><span><strong>Xuất bản sản phẩm</strong><small>Video đã tạo → Lên web</small></span></button>
    </div>
    <section class="agent-flow" id="agentFlow" role="tabpanel" aria-labelledby="agentFlowTab" <?= $activeFlow !== 'agent' ? 'hidden' : '' ?>>
        <div class="agent-compose studio-panel">
            <div class="panel-heading"><div><span class="studio-eyebrow">SYNA AGENT</span><h2>Giao việc bằng một câu</h2></div><span class="small-tag">BETA AN TOÀN</span></div>
            <form id="agentForm" class="agent-form" novalidate>
                <div class="studio-message" id="agentMessage" role="alert" hidden></div>
                <div class="idol-field">
                    <label for="agentBrief">Bạn muốn Syna tạo chương trình gì?</label>
                    <textarea id="agentBrief" class="idol-input agent-brief" maxlength="4000" placeholder="Ví dụ: Tối mai lúc 20 giờ, tạo livestream 60 phút giới thiệu sản phẩm mã 995 theo phong cách phân tích thành phần. Trước mắt chỉ phát nội bộ để tôi xem thử."></textarea>
                    <p class="idol-note">Có thể ghi tên hoặc mã sản phẩm, giờ phát, thời lượng và phong cách. Phần còn thiếu sẽ được Agent đề xuất an toàn.</p>
                </div>
                <div class="agent-policy-grid">
                    <label class="agent-policy"><input type="radio" name="agentPolicy" value="auto_preview" checked><span><i class="bi bi-play-circle" aria-hidden="true"></i><strong>Tự tạo bản xem trước</strong><small>Tự duyệt sau kiểm duyệt, chỉ phát nội bộ.</small></span></label>
                    <label class="agent-policy"><input type="radio" name="agentPolicy" value="assisted"><span><i class="bi bi-person-check" aria-hidden="true"></i><strong>Duyệt trước khi render</strong><small>Dừng ở kịch bản để nhân viên chỉnh sửa.</small></span></label>
                </div>
                <div class="agent-form-footer"><span><i class="bi bi-shield-check" aria-hidden="true"></i> Tự phát YouTube/Facebook vẫn đang khóa.</span><button type="submit" class="idol-btn primary" id="runAgent">Lập kế hoạch & chạy <i class="bi bi-arrow-right" aria-hidden="true"></i></button></div>
            </form>
        </div>
        <div class="agent-monitor studio-panel">
            <div class="panel-heading"><div><span class="studio-eyebrow">TIẾN ĐỘ</span><h2>Agent đang làm gì?</h2></div><button type="button" class="icon-button" id="refreshAgentRuns" aria-label="Làm mới Agent"><i class="bi bi-arrow-clockwise" aria-hidden="true"></i></button></div>
            <div class="agent-run-list" id="agentRunList"><div class="empty"><i class="bi bi-stars" aria-hidden="true"></i><p>Chưa có yêu cầu Agent nào.</p></div></div>
            <div class="agent-run-detail" id="agentRunDetail" hidden></div>
        </div>
    </section>
    <div class="studio-workspace" id="manualFlow" role="tabpanel" aria-labelledby="manualFlowTab" <?= $activeFlow !== 'manual' ? 'hidden' : '' ?>>
        <div class="studio-setup">
            <section class="studio-panel" aria-labelledby="setupTitle">
                <div class="panel-heading"><div><span class="studio-eyebrow">CHUẨN BỊ</span><h2 id="setupTitle">Tạo chương trình mới</h2></div><span class="step-counter" id="stepCounter">Bước 1 / 3</span></div>
                <div class="studio-steps" role="tablist" aria-label="Thiết lập chương trình">
                    <button type="button" role="tab" id="stepTab1" class="step-tab is-active" data-step="1" aria-selected="true" aria-controls="stepPanel1"><span>01</span> Nội dung</button>
                    <button type="button" role="tab" id="stepTab2" class="step-tab" data-step="2" aria-selected="false" aria-controls="stepPanel2" tabindex="-1"><span>02</span> Không gian</button>
                    <button type="button" role="tab" id="stepTab3" class="step-tab" data-step="3" aria-selected="false" aria-controls="stepPanel3" tabindex="-1"><span>03</span> Lịch phát</button>
                </div>
                <form id="studioForm" novalidate>
                    <div class="studio-message" id="formMessage" role="alert" hidden></div>
                    <section class="step-panel" id="stepPanel1" role="tabpanel" aria-labelledby="stepTab1">
                        <div class="idol-field"><label for="campaignName">Tên chương trình <span class="field-optional">Không bắt buộc</span></label><input id="campaignName" class="idol-input" maxlength="160" placeholder="Ví dụ: Một chút chăm da, tối thứ Sáu" autocomplete="off"></div>
                        <div class="idol-field">
                            <div class="field-heading"><label for="productSearch">Sản phẩm lên sóng</label><span class="selection-count" id="selectionCount">0 đã chọn</span></div>
                            <div class="product-toolbar"><div class="studio-search"><i class="bi bi-search" aria-hidden="true"></i><input id="productSearch" class="idol-input" type="search" placeholder="Tìm tên hoặc mã sản phẩm" autocomplete="off"></div><button type="button" class="text-button" id="selectVisible">Chọn tất cả</button></div>
                            <div id="productList" class="product-list" role="group" aria-label="Chọn sản phẩm">
                                <?php foreach ($products as $p):
                                    $productId = (string)($p['ma_san_pham'] ?? $p['id'] ?? '');
                                    $productName = (string)($p['ten_san_pham'] ?? 'Sản phẩm chưa đặt tên');
                                    $rawImage = explode('|', (string)($p['link_hinh_anh'] ?? $p['hinh_anh'] ?? ''))[0];
                                    $imageUrl = resolve_image_url(trim($rawImage));
                                ?>
                                <label class="product-item" data-search="<?= $studioEscape(mb_strtolower($productId . ' ' . $productName)) ?>">
                                    <input type="checkbox" class="product-check" value="<?= $studioEscape($productId) ?>" data-name="<?= $studioEscape($productName) ?>">
                                    <span class="product-thumb"><img src="<?= $studioEscape($imageUrl) ?>" alt="" loading="lazy" width="42" height="48"></span>
                                    <span class="product-copy"><span class="product-name"><?= $studioEscape($productName) ?></span><small>Mã sản phẩm <?= $studioEscape($productId) ?></small></span>
                                    <i class="bi bi-check2 selected-tick" aria-hidden="true"></i>
                                </label>
                                <?php endforeach; ?>
                                <div class="empty compact" id="productEmpty" <?= count($products) ? 'hidden' : '' ?>><i class="bi bi-search" aria-hidden="true"></i><p>Không tìm thấy sản phẩm.<br>Thử tên hoặc mã khác nhé.</p></div>
                            </div>
                            <div id="selectedChips" class="selected-chips" aria-label="Sản phẩm đã chọn" hidden></div>
                            <p id="selectionNote" class="idol-note" aria-live="polite">Chọn sản phẩm bạn muốn giới thiệu trong buổi live.</p>
                        </div>
                        <div class="idol-field"><label for="contentMode">Cách kể câu chuyện</label><select id="contentMode" class="idol-select"><option value="Product Intro">Giới thiệu sản phẩm</option><option value="Problem-Solution">Vấn đề & giải pháp</option><option value="Scientific Review">Phân tích thành phần</option></select></div>
                    </section>
                    <section class="step-panel" id="stepPanel2" role="tabpanel" aria-labelledby="stepTab2" hidden>
                        <div class="step-intro"><h3>Chọn gương mặt, đặt không gian.</h3><p>Nhân vật và phông nền tạo nên phong cách buổi live.</p></div>
                        <div class="idol-field"><label for="characterMode">Nhân vật dẫn chương trình</label><select id="characterMode" class="idol-select"><option value="syna_3d">Syna 3D · mèo rau má của SkinSyntax</option><option value="custom">Ảnh hoặc video nhân vật riêng</option></select><p class="idol-note" id="synaModeHelp">Syna nói theo kịch bản bạn duyệt; giữ ảnh, tên, giá sản phẩm và có bảng thành phần riêng. Không cần tải video mẫu. Bản này hỗ trợ khung ngang 16:9.</p></div>
                        <div class="idol-field"><label for="videoFormat">Khung hình</label><select id="videoFormat" class="idol-select"><option value="16:9">Ngang 16:9 · phù hợp livestream</option><option value="9:16">Dọc 9:16 · phù hợp điện thoại</option></select></div>
                        <div class="asset-grid">
                            <div class="asset-picker" id="customAvatarPicker" hidden>
                                <label class="asset-drop" for="avatarFile"><span class="asset-icon"><i class="bi bi-person-video3" aria-hidden="true"></i></span><strong>Nhân vật chính</strong><span id="avatarFilename">Chọn video hoặc ảnh</span><small>Video ≤ 35 MB · ảnh ≤ 10 MB</small><input id="avatarFile" type="file" accept="image/png,image/jpeg,image/webp,video/mp4,video/webm"></label>
                                <img id="avatarImagePreview" class="asset-preview" alt="Xem trước nhân vật">
                                <video id="avatarVideoPreview" class="asset-preview" muted loop controls playsinline aria-label="Xem trước video nhân vật"></video>
                                <div class="asset-caption"><span class="small-tag">Gợi ý</span> Video 5–30 giây có cử động tự nhiên.</div>
                            </div>
                            <div class="asset-picker">
                                <label class="asset-drop" for="backgroundFile"><span class="asset-icon peach"><i class="bi bi-image" aria-hidden="true"></i></span><strong>Phông nền</strong><span id="backgroundFilename">Chọn ảnh nền · tùy chọn</span><small>PNG, JPG, WebP ≤ 10 MB</small><input id="backgroundFile" type="file" accept="image/png,image/jpeg,image/webp"></label>
                                <img id="backgroundPreview" class="asset-preview" alt="Xem trước phông nền">
                                <div class="asset-caption">Ảnh ngang 1920 × 1080 cho khung 16:9.</div>
                            </div>
                        </div>
                        <div id="avatarModeNote" class="avatar-mode-note" hidden>Chọn nhân vật để xem trước tại đây.</div>
                        <section class="syna-live-preview" aria-labelledby="synaPreviewTitle">
                            <div class="syna-live-preview-heading">
                                <div><span class="studio-eyebrow">NHÂN VẬT CỦA BẠN</span><h3 id="synaPreviewTitle">Syna 3D</h3></div>
                                <span class="small-tag">Xem thử chuyển động</span>
                            </div>
                            <p class="idol-note">Khung dưới chỉ minh họa chuyển động. Video chương trình sẽ dùng sản phẩm, giá và giọng đọc của kịch bản bạn duyệt — không dùng lại clip mẫu này.</p>
                            <iframe class="syna-live-preview-frame" title="Bản thử Syna 3D cho livestream" src="<?= BASE_URL ?>/ai-idol-mascot-3d/index.html?embed=1&amp;rev=syna-native-v3" loading="lazy"></iframe>
                            <p class="idol-note">Khi tạo video, cần bật Docker và dịch vụ Syna local. Không sử dụng Wav2Lip hay SadTalker cho Syna.</p>
                        </section>
                        <details class="studio-tips"><summary>Chuẩn bị hình ảnh thế nào cho đẹp?</summary><p>Dùng video 720p, camera cố định, mặt rõ và có sẵn chớp mắt, chuyển động đầu, vai hoặc tay. Hệ thống giữ các chuyển động đó và tạo lại khẩu hình.</p><p>Với ảnh tĩnh, chỉ miệng và đầu cử động nhẹ. Với video, phông nền đã có trong clip vẫn được giữ; ảnh nền bạn chọn chỉ lấp phần viền khi khác tỉ lệ.</p></details>
                    </section>
                    <section class="step-panel" id="stepPanel3" role="tabpanel" aria-labelledby="stepTab3" hidden>
                        <div class="step-intro"><h3>Hẹn giờ cho buổi live của bạn.</h3><p>Chừa thời gian để duyệt kịch bản và tạo video trước giờ phát.</p></div>
                        <div class="schedule-summary"><i class="bi bi-bag-check" aria-hidden="true"></i><div><strong id="summaryProducts">Chưa chọn sản phẩm</strong><span id="summaryPreparation">Quay lại Nội dung để chọn sản phẩm.</span></div></div>
                        <div class="idol-row"><div class="idol-field"><div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:6px;"><label for="scheduledAt" style="margin-bottom:0;">Ngày &amp; giờ phát</label><button type="button" id="btnEarliestSchedule" class="small-tag" style="background:rgba(217,119,6,0.12);color:var(--brand);border:1px solid rgba(217,119,6,0.3);cursor:pointer;padding:3px 8px;border-radius:6px;font-size:11px;font-weight:600;" title="Bấm để đặt mốc giờ sớm nhất hợp lệ">Sớm nhất (+<span id="leadMinutesBadge">8</span>p)</button></div><input id="scheduledAt" type="datetime-local" class="idol-input" required><p class="idol-note" id="scheduleLeadNote" style="margin-top:6px;font-size:11px;">Cần tối thiểu <strong id="leadMinutesText">8 phút</strong> chuẩn bị video trước giờ phát.</p></div><div class="idol-field"><label for="durationMinutes">Thời lượng <span class="field-optional">phút</span></label><input id="durationMinutes" type="number" min="1" max="720" value="60" class="idol-input" required></div></div>
                        <div class="idol-field"><label>Nơi phát chương trình</label><div class="platforms">
                            <label class="platform"><input type="checkbox" name="platform" value="internal" checked><i class="bi bi-display" aria-hidden="true"></i><span>Xem nội bộ</span></label>
                            <label class="platform"><input type="checkbox" name="platform" value="youtube"><i class="bi bi-youtube" aria-hidden="true"></i><span>YouTube</span></label>
                            <label class="platform"><input type="checkbox" name="platform" value="facebook"><i class="bi bi-facebook" aria-hidden="true"></i><span>Facebook</span></label>
                        </div><p class="idol-note">YouTube và Facebook cần được cấu hình khóa phát trước khi lên sóng.</p></div>
                        <div class="approval-reminder"><i class="bi bi-pencil-square" aria-hidden="true"></i><div><strong>Bạn duyệt xong, video mới được tạo.</strong><p>Kịch bản sẽ xuất hiện trong chương trình để bạn chỉnh sửa và chấp nhận.</p></div></div>
                    </section>
                    <div class="form-footer"><button type="button" id="previousStep" class="idol-btn secondary" hidden><i class="bi bi-arrow-left" aria-hidden="true"></i> Quay lại</button><span class="footer-note" id="footerNote">Bắt đầu bằng những sản phẩm bạn yêu thích.</span><button type="button" id="nextStep" class="idol-btn primary">Chọn không gian <i class="bi bi-arrow-right" aria-hidden="true"></i></button><button type="submit" id="createCampaign" class="idol-btn primary" hidden>Tạo kịch bản <i class="bi bi-arrow-right" aria-hidden="true"></i></button></div>
                </form>
            </section>
            <div class="studio-footnote"><i class="bi bi-check2-circle" aria-hidden="true"></i><span>Chuẩn bị nội dung <span aria-hidden="true">→</span> Duyệt kịch bản <span aria-hidden="true">→</span> Lên sóng theo lịch</span></div>
        </div>
        <section class="studio-panel studio-library" aria-labelledby="libraryTitle">
            <div class="panel-heading"><div><span class="studio-eyebrow">THƯ VIỆN</span><h2 id="libraryTitle">Chương trình của bạn</h2></div><button type="button" class="icon-button" id="refreshCampaigns" aria-label="Làm mới danh sách" title="Làm mới danh sách"><i class="bi bi-arrow-clockwise" aria-hidden="true"></i></button></div>
            <div class="campaign-filters" role="group" aria-label="Lọc chương trình"><button type="button" class="filter-button is-active" data-filter="all" aria-pressed="true">Tất cả <span id="libraryCount">—</span></button><button type="button" class="filter-button" data-filter="approval" aria-pressed="false">Chờ duyệt</button><button type="button" class="filter-button" data-filter="scheduled" aria-pressed="false">Đã lên lịch</button></div>
            <div id="campaignList" class="campaign-list" aria-label="Danh sách chương trình"><div class="studio-loading" role="status"><span></span><span></span><span></span><p>Đang tải chương trình…</p></div></div>
            <div id="campaignDetail" class="detail" hidden></div>
            <div class="library-idle" id="libraryIdle"><div class="idle-frame" aria-hidden="true"><i class="bi bi-play-circle"></i></div><h3>Một không gian cho câu chuyện của bạn.</h3><p>Chọn chương trình để xem video, duyệt kịch bản<br>và theo dõi tiến độ tại đây.</p></div>
        </section>
    </div>

    <!-- Phase V1.1: Panel Quản lý Xuất bản Video Lên Sản Phẩm -->
    <section class="studio-publication-panel" id="pubFlow" role="tabpanel" aria-labelledby="pubFlowTab" <?= $activeFlow !== 'publication' ? 'hidden' : '' ?>>
        <div class="panel-heading">
            <div>
                <span class="studio-eyebrow"><i class="bi bi-shop me-1"></i> XUẤT BẢN THƯƠNG MẠI</span>
                <h2>Video đã tạo → Xuất bản lên sản phẩm</h2>
                <p class="idol-note" style="margin-top: 4px;">Quản lý gắn video thuyết minh AI Idol đã kết xuất vào các trang chi tiết sản phẩm SkinSyntaxVN.</p>
            </div>
            <button type="button" class="icon-button" id="refreshPubVideos" aria-label="Làm mới danh sách video" title="Làm mới danh sách"><i class="bi bi-arrow-clockwise"></i></button>
        </div>
        <div class="publication-toolbar">
            <div class="pub-filter-group" role="group" aria-label="Lọc video xuất bản">
                <button type="button" class="pub-filter-btn is-active" data-pub-filter="all">Tất cả video <span id="pubCountAll">(0)</span></button>
                <button type="button" class="pub-filter-btn" data-pub-filter="published">Đã xuất bản <span id="pubCountPublished">(0)</span></button>
                <button type="button" class="pub-filter-btn" data-pub-filter="unpublished">Chưa xuất bản <span id="pubCountUnpublished">(0)</span></button>
            </div>
            <div class="studio-search" style="max-width: 300px; width: 100%;">
                <i class="bi bi-search" aria-hidden="true"></i>
                <input id="pubSearchInput" class="idol-input" type="search" placeholder="Tìm video hoặc chiến dịch..." autocomplete="off">
            </div>
        </div>
        <div id="pubVideoMessage" class="studio-message m-3" role="alert" hidden></div>
        <div class="publication-grid" id="pubVideoGrid" aria-label="Danh sách video xuất bản">
            <div class="studio-loading" role="status"><span></span><span></span><span></span><p>Đang tải danh sách video…</p></div>
        </div>
    </section>

    <!-- Modal Quản Lý Xuất Bản Video Sản Phẩm -->
    <div class="pub-modal-backdrop" id="pubModalBackdrop" hidden>
        <div class="pub-modal" id="pubModal" role="dialog" aria-modal="true" aria-labelledby="pubModalTitle">
            <div class="pub-modal-header">
                <div>
                    <span class="studio-eyebrow"><i class="bi bi-sliders me-1"></i> THIẾT LẬP XUẤT BẢN</span>
                    <h3 id="pubModalTitle">Quản lý xuất bản video lên sản phẩm</h3>
                </div>
                <button type="button" class="pub-modal-close" id="closePubModalBtn" aria-label="Đóng"><i class="bi bi-x-lg"></i></button>
            </div>
            <div class="pub-modal-body">
                <div id="pubModalAlert" class="studio-message" role="alert" hidden></div>

                <!-- Video Info Banner -->
                <div class="pub-video-summary">
                    <div class="pub-video-poster-box">
                        <img id="pubModalPoster" src="<?= BASE_URL ?>/favicon.ico" alt="" class="pub-video-poster">
                        <span id="pubModalDurationBadge" class="pub-duration-badge">00:00</span>
                    </div>
                    <div class="pub-video-meta">
                        <div class="pub-video-meta-top">
                            <span class="pub-campaign-tag" id="pubModalCampaignName">Chương trình AI Idol</span>
                            <span id="pubModalStatusBadge" class="pub-badge-status published">Đã xuất bản</span>
                            <span id="pubModalRenderStatusBadge" class="pub-badge-status unready" hidden>Chưa render</span>
                        </div>
                        <div class="idol-field" style="margin-top: 6px; margin-bottom: 0;">
                            <label for="pubModalVideoTitle" style="font-size: 11.5px; margin-bottom: 4px;">Tiêu đề video hiển thị trên PDP</label>
                            <input type="text" id="pubModalVideoTitle" class="idol-input" placeholder="Tiêu đề video..." maxlength="200">
                        </div>
                    </div>
                </div>

                <!-- Display Priority -->
                <div class="idol-field">
                    <div style="display: flex; justify-content: space-between; align-items: baseline;">
                        <label for="pubModalPriority" style="margin-bottom: 0;">Độ ưu tiên hiển thị (Display Priority)</label>
                        <small class="text-muted" style="font-size: 11px;">Số càng lớn thì video xếp càng trước trên trang sản phẩm</small>
                    </div>
                    <input type="number" id="pubModalPriority" class="idol-input" min="1" max="1000" value="10" style="max-width: 140px; margin-top: 6px;">
                </div>

                <!-- Product Association Section -->
                <div class="pub-products-section">
                    <div class="field-heading">
                        <label style="font-weight: 650; margin-bottom: 0;">Sản phẩm liên kết hiển thị video</label>
                        <span class="selection-count" id="pubModalSelectedCount">0 đã chọn</span>
                    </div>
                    <p class="idol-note" style="margin-top: 2px;">Video sẽ xuất hiện trên trang chi tiết của tất cả sản phẩm được chọn bên dưới.</p>

                    <!-- Search Toolbar -->
                    <div class="pub-search-bar mt-2">
                        <div class="studio-search" style="flex: 1;">
                            <i class="bi bi-search" aria-hidden="true"></i>
                            <input id="pubModalProductSearch" data-alias="pubProductSearchInput" class="idol-input" type="search" placeholder="Tìm tên hoặc mã sản phẩm trong kho..." autocomplete="off">
                        </div>
                        <button type="button" class="text-button" id="pubModalSelectAllVisible">Chọn tất cả</button>
                        <button type="button" class="text-button" id="pubModalClearAll">Bỏ chọn hết</button>
                    </div>

                    <!-- Selected Chips Container -->
                    <div id="pubModalSelectedChips" data-alias="pubSelectedChips" class="selected-chips mt-2" hidden></div>

                    <!-- Primary Product Selector -->
                    <div class="idol-field mt-2" id="pubModalPrimaryWrap">
                        <label for="pubModalPrimaryProduct" style="font-size: 11.5px;">Sản phẩm chính (Primary Product)</label>
                        <select id="pubModalPrimaryProduct" data-alias="pubPrimarySelect" class="idol-select"></select>
                    </div>

                    <!-- Product Checklist -->
                    <div class="pub-product-list mt-2" id="pubModalProductList" role="group" aria-label="Danh sách sản phẩm">
                        <!-- Rendered dynamically -->
                    </div>
                </div>
            </div>
            <div class="pub-modal-footer">
                <button type="button" class="idol-btn danger-outline" id="pubModalUnpublishBtn" data-alias="pubUnpublishBtn" hidden>
                    <i class="bi bi-eye-slash me-1"></i> Hủy xuất bản
                </button>
                <div style="flex: 1;"></div>
                <button type="button" class="idol-btn secondary" id="pubModalCloseBtn">Đóng</button>
                <button type="button" class="idol-btn primary" id="pubModalSubmitBtn" data-alias="pubSubmitPublishBtn">
                    <i class="bi bi-cloud-arrow-up me-1"></i> Xuất bản lên trang sản phẩm
                </button>
            </div>
        </div>
    </div>

    <!-- Modal Cấu hình Voucher Xem Video (Watch-to-Reward Campaign) -->
    <div class="pub-modal-backdrop" id="rewardModalBackdrop" hidden>
        <div class="pub-modal reward-modal" id="rewardModal" role="dialog" aria-modal="true" aria-labelledby="rewardModalTitle" style="max-width: 680px;">
            <div class="pub-modal-header">
                <div>
                    <span class="studio-eyebrow"><i class="bi bi-gift me-1"></i> WATCH-TO-REWARD</span>
                    <h3 id="rewardModalTitle">Cấu hình voucher xem video</h3>
                </div>
                <button type="button" class="pub-modal-close" id="closeRewardModalBtn" aria-label="Đóng"><i class="bi bi-x-lg"></i></button>
            </div>
            <div class="pub-modal-body">
                <div id="rewardModalAlert" class="studio-message" role="alert" hidden></div>

                <!-- Video Summary Card -->
                <div class="pub-video-summary">
                    <div class="pub-video-poster-box">
                        <img id="rewardModalPoster" src="<?= BASE_URL ?>/favicon.ico" alt="" class="pub-video-poster">
                        <span id="rewardModalDurationBadge" class="pub-duration-badge">00:00</span>
                    </div>
                    <div class="pub-video-meta">
                        <div class="pub-video-meta-top">
                            <span class="pub-campaign-tag" id="rewardModalCampaignName">Chương trình AI Idol</span>
                            <span id="rewardModalVideoStatusBadge" class="pub-badge-status published">Đã xuất bản</span>
                        </div>
                        <h4 id="rewardModalVideoTitle" style="font-size: 14px; font-weight: 650; margin: 6px 0 2px; color: var(--text);">Tiêu đề video</h4>
                        <div style="font-size: 11.5px; color: var(--muted);" id="rewardModalVideoSub">Mã video: <code id="rewardModalVideoId">—</code></div>
                    </div>
                </div>

                <!-- Real Statistics Panel (Only shown when real campaign & data exists) -->
                <div id="rewardStatsCard" class="reward-stats-card mt-3" hidden>
                    <div class="reward-stats-header">
                        <span class="studio-eyebrow" style="font-size: 10px;"><i class="bi bi-graph-up me-1"></i> DỮ LIỆU PHÁT HÀNH THỰC TẾ</span>
                        <span id="rewardStatsStatusBadge" class="pub-badge-status published">Đang hoạt động</span>
                    </div>
                    <div class="reward-stats-grid">
                        <div class="reward-stat-box">
                            <span class="reward-stat-label">Đã phát</span>
                            <strong class="reward-stat-val" id="rewardStatIssuedCount">0</strong>
                            <small class="text-muted">voucher</small>
                        </div>
                        <div class="reward-stat-box">
                            <span class="reward-stat-label">Tổng hạn ngạch</span>
                            <strong class="reward-stat-val" id="rewardStatTotalQuota">0</strong>
                            <small class="text-muted">voucher</small>
                        </div>
                        <div class="reward-stat-box">
                            <span class="reward-stat-label">Còn lại</span>
                            <strong class="reward-stat-val" id="rewardStatRemaining">0</strong>
                            <small class="text-muted">voucher</small>
                        </div>
                        <div class="reward-stat-box">
                            <span class="reward-stat-label">Giới hạn/User</span>
                            <strong class="reward-stat-val" id="rewardStatUserLimit">1</strong>
                            <small class="text-muted">lần</small>
                        </div>
                    </div>
                    <div class="reward-stat-meta mt-2" id="rewardStatTimestamps" style="font-size: 11px; color: var(--muted);"></div>
                </div>

                <!-- Form Fields -->
                <form id="rewardCampaignForm" class="mt-3" novalidate>
                    <input type="hidden" id="rewardCampaignId" value="">
                    <input type="hidden" id="rewardVideoId" value="">
                    <input type="hidden" id="rewardPublicationId" value="">

                    <!-- Campaign Name -->
                    <div class="idol-field">
                        <label for="rewardNameInput">Tên chiến dịch ưu đãi</label>
                        <input type="text" id="rewardNameInput" class="idol-input" placeholder="Ví dụ: Voucher xem video Syna 3D - Giảm 15%" maxlength="150" required>
                    </div>

                    <!-- Discount Type & Value Grid -->
                    <div class="idol-row">
                        <div class="idol-field" style="flex: 1;">
                            <label for="rewardDiscountTypeSelect">Loại giảm giá</label>
                            <select id="rewardDiscountTypeSelect" class="idol-select">
                                <option value="percentage">Phần trăm (%)</option>
                                <option value="fixed">Số tiền cố định (VNĐ)</option>
                            </select>
                        </div>
                        <div class="idol-field" style="flex: 1;">
                            <label for="rewardDiscountValueInput" id="rewardDiscountValueLabel">Mức giảm (%)</label>
                            <input type="number" id="rewardDiscountValueInput" class="idol-input" min="1" max="100" value="10" required>
                            <small class="text-muted" id="rewardDiscountValueHelp" style="font-size: 10.5px;">Nhập từ 1 đến 100%</small>
                        </div>
                    </div>

                    <!-- Max Discount (for percent) & Min Order Amount -->
                    <div class="idol-row">
                        <div class="idol-field" style="flex: 1;" id="rewardMaxDiscountWrap">
                            <label for="rewardMaxDiscountInput">Mức giảm tối đa (VNĐ) <span class="field-optional">Tùy chọn</span></label>
                            <input type="number" id="rewardMaxDiscountInput" class="idol-input" min="0" step="5000" placeholder="Để trống nếu không giới hạn">
                            <small class="text-muted" style="font-size: 10.5px;">Áp dụng cho giảm phần trăm</small>
                        </div>
                        <div class="idol-field" style="flex: 1;">
                            <label for="rewardMinOrderInput">Đơn hàng tối thiểu (VNĐ)</label>
                            <input type="number" id="rewardMinOrderInput" class="idol-input" min="0" step="10000" value="0">
                            <small class="text-muted" style="font-size: 10.5px;">0 = không yêu cầu đơn tối thiểu</small>
                        </div>
                    </div>

                    <!-- Dates Grid -->
                    <div class="idol-row">
                        <div class="idol-field" style="flex: 1;">
                            <label for="rewardStartDateInput">Ngày bắt đầu</label>
                            <input type="datetime-local" id="rewardStartDateInput" class="idol-input" required>
                        </div>
                        <div class="idol-field" style="flex: 1;">
                            <label for="rewardEndDateInput">Ngày kết thúc</label>
                            <input type="datetime-local" id="rewardEndDateInput" class="idol-input" required>
                        </div>
                    </div>

                    <!-- Quota & User Limit Grid -->
                    <div class="idol-row">
                        <div class="idol-field" style="flex: 1;">
                            <div style="display:flex;justify-content:space-between;align-items:baseline;">
                                <label for="rewardTotalQuotaInput" style="margin-bottom:0;">Tổng hạn ngạch phát hành</label>
                                <small class="text-muted" id="rewardQuotaHelp" style="font-size: 10.5px;">Tối thiểu 1</small>
                            </div>
                            <input type="number" id="rewardTotalQuotaInput" class="idol-input" min="1" max="100000" value="100" required style="margin-top:4px;">
                        </div>
                        <div class="idol-field" style="flex: 1;">
                            <label for="rewardUserLimitInput">Giới hạn nhận / khách hàng</label>
                            <input type="number" id="rewardUserLimitInput" class="idol-input" min="1" max="10" value="1" required>
                            <small class="text-muted" style="font-size: 10.5px;">Mặc định mỗi khách nhận 1 lần</small>
                        </div>
                    </div>

                    <!-- Stacking Policies -->
                    <div class="idol-field">
                        <label style="font-weight: 650; margin-bottom: 6px;">Chính sách cộng dồn ưu đãi</label>
                        <div class="reward-policy-box">
                            <label class="reward-checkbox-row">
                                <input type="checkbox" id="rewardStackFlashSale">
                                <span>
                                    <strong>Cho phép áp dụng chung với Flash Sale</strong>
                                    <small class="d-block text-muted">Mặc định tắt. Khi bật, khách hàng có thể dùng voucher này cho sản phẩm đang trong phiên Flash Sale.</small>
                                </span>
                            </label>
                            <label class="reward-checkbox-row mt-2">
                                <input type="checkbox" id="rewardStackOther">
                                <span>
                                    <strong>Cho phép cộng dồn với voucher khuyến mãi khác</strong>
                                    <small class="d-block text-muted">Mặc định tắt. Không khuyến khích để tránh chồng chéo chiết khấu.</small>
                                </span>
                            </label>
                        </div>
                    </div>

                    <!-- Active Switch -->
                    <div class="idol-field">
                        <label class="reward-checkbox-row active-toggle-row">
                            <input type="checkbox" id="rewardIsActive" checked>
                            <span>
                                <strong>Kích hoạt chiến dịch ngay</strong>
                                <small class="d-block text-muted">Mỗi video chỉ được có tối đa 1 chiến dịch voucher ở trạng thái kích hoạt.</small>
                            </span>
                        </label>
                    </div>

                    <!-- Applicable Products Section -->
                    <div class="pub-products-section mt-3">
                        <div class="field-heading">
                            <label style="font-weight: 650; margin-bottom: 0;">Sản phẩm áp dụng voucher</label>
                            <span class="selection-count" id="rewardSelectedProductCount">0 đã chọn</span>
                        </div>
                        <p class="idol-note" style="margin-top: 2px;">Voucher thưởng sẽ chỉ có hiệu lực cho các sản phẩm được chọn bên dưới khi thanh toán.</p>

                        <div class="pub-search-bar mt-2">
                            <div class="studio-search" style="flex: 1;">
                                <i class="bi bi-search" aria-hidden="true"></i>
                                <input id="rewardProductSearchInput" class="idol-input" type="search" placeholder="Tìm sản phẩm áp dụng..." autocomplete="off">
                            </div>
                            <button type="button" class="text-button" id="rewardSelectAllLinked">Chọn các sản phẩm của video</button>
                            <button type="button" class="text-button" id="rewardClearAllProducts">Bỏ chọn hết</button>
                        </div>

                        <!-- Selected Chips -->
                        <div id="rewardSelectedChips" class="selected-chips mt-2" hidden></div>

                        <!-- Product List -->
                        <div class="pub-product-list mt-2" id="rewardProductList" role="group" aria-label="Danh sách sản phẩm áp dụng"></div>
                    </div>
                </form>
            </div>
            <div class="pub-modal-footer">
                <button type="button" class="idol-btn danger-outline" id="rewardToggleActiveBtn" hidden>
                    <i class="bi bi-pause-circle me-1"></i> Tắt chiến dịch
                </button>
                <div style="flex: 1;"></div>
                <button type="button" class="idol-btn secondary" id="closeRewardModalFooterBtn">Đóng</button>
                <button type="button" class="idol-btn primary" id="rewardModalSubmitBtn">
                    <i class="bi bi-check2-circle me-1"></i> Lưu cấu hình voucher
                </button>
            </div>
        </div>
    </div>
</main>
<script>
    window.AI_IDOL_CSRF = <?= json_encode($csrfToken ?? '') ?>;
    window.AI_IDOL_PRODUCTS = <?= json_encode(array_values(array_map(static function($p) use ($studioEscape) {
        $rawImage = explode('|', (string)($p['link_hinh_anh'] ?? $p['hinh_anh'] ?? ''))[0];
        return [
            'id' => (string)($p['ma_san_pham'] ?? $p['id'] ?? ''),
            'name' => (string)($p['ten_san_pham'] ?? 'Sản phẩm chưa đặt tên'),
            'image' => resolve_image_url(trim($rawImage)),
            'price' => (float)($p['gia_ban'] ?? 0)
        ];
    }, $products)), JSON_UNESCAPED_UNICODE) ?>;
</script>
<script src="<?= BASE_URL ?>/assets/js/ai-idol-studio.js?v=<?= filemtime($studioAssets . '/js/ai-idol-studio.js') ?>" defer></script>
