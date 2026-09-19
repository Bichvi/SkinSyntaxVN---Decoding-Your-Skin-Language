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
    <div class="studio-flow-switch" role="tablist" aria-label="Chọn cách tạo chương trình">
        <button type="button" class="flow-tab is-active" id="manualFlowTab" role="tab" aria-selected="true" aria-controls="manualFlow" data-studio-flow="manual"><i class="bi bi-sliders" aria-hidden="true"></i><span><strong>Wizard thủ công</strong><small>Chủ động chọn từng thiết lập</small></span></button>
        <button type="button" class="flow-tab" id="agentFlowTab" role="tab" aria-selected="false" aria-controls="agentFlow" data-studio-flow="agent"><i class="bi bi-stars" aria-hidden="true"></i><span><strong>Agent tự động</strong><small>Mô tả một lần, Agent tự lập kế hoạch</small></span></button>
    </div>
    <section class="agent-flow" id="agentFlow" role="tabpanel" aria-labelledby="agentFlowTab" hidden>
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
    <div class="studio-workspace" id="manualFlow" role="tabpanel" aria-labelledby="manualFlowTab">
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
                        <div class="idol-row"><div class="idol-field"><label for="scheduledAt">Ngày & giờ phát</label><input id="scheduledAt" type="datetime-local" class="idol-input" required></div><div class="idol-field"><label for="durationMinutes">Thời lượng <span class="field-optional">phút</span></label><input id="durationMinutes" type="number" min="1" max="720" value="60" class="idol-input" required></div></div>
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
</main>
<script src="<?= BASE_URL ?>/assets/js/ai-idol-studio.js?v=<?= filemtime($studioAssets . '/js/ai-idol-studio.js') ?>" defer></script>
