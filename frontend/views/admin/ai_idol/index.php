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
$studioJsonFlags = JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP;
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
            <section class="studio-flow-panel" id="autoFlow" role="tabpanel" aria-labelledby="autoFlowTab"></section>
            <section class="studio-flow-panel" id="manualFlow" role="tabpanel" aria-labelledby="manualFlowTab" hidden></section>
        </div>
        <aside class="studio-activity" id="activityPanel" aria-label="Hoạt động"></aside>
    </div>
</main>
<script>
window.AI_IDOL_CSRF = <?= json_encode($studioCsrf) ?>;
window.__IDOL_CATALOG__ = <?= json_encode($catalogTree ?? [], $studioJsonFlags) ?>;
window.__IDOL_FILTER_COUNTS__ = <?= json_encode($filterCounts ?? [], $studioJsonFlags) ?>;
</script>
<?php foreach ($studioScripts as $studioScript): ?>
<script src="<?= $studioAssetUrl('js/' . $studioScript) ?>" defer></script>
<?php endforeach; ?>
