<?php
declare(strict_types=1);

$root = dirname(__DIR__, 3);
$controller = file_get_contents($root . '/backend/app/controllers/LiveController.php');
$model = file_get_contents($root . '/backend/app/models/PhienLive.php');
$studio = file_get_contents($root . '/frontend/views/live_studio.php');
$viewer = file_get_contents($root . '/frontend/public/assets/js/live-commerce.js');
$viewerView = file_get_contents($root . '/frontend/views/live.php');
$admin = file_get_contents($root . '/backend/app/controllers/QuanTriController.php');
$adminView = file_get_contents($root . '/frontend/views/admin/lives.php');
$studioView = file_get_contents($root . '/frontend/views/live_studio.php');
$routers = [
    file_get_contents($root . '/index.php'),
    file_get_contents($root . '/frontend/public/index.php'),
    file_get_contents($root . '/backend/public/index.php'),
];

$failed = 0;
function checkContract(bool $condition, string $label): void
{
    global $failed;
    if ($condition) {
        echo "PASS  {$label}\n";
        return;
    }
    $failed++;
    echo "FAIL  {$label}\n";
}

checkContract(str_contains($controller, "error_log('[SkinSyntax Live] token_issued live_id=' . (string)\$liveDoc['id']"), 'token logging uses the loaded live document and cannot emit an undefined-variable warning');
checkContract(str_contains($controller, "\$roomName = 'skinsyntax_room_' . (string)(\$liveDoc['id'] ?? \$liveId)"), 'Studio token uses the canonical document id room');
checkContract(str_contains($controller, "\$room = 'skinsyntax_room_' . \$liveId"), 'publisher token verification uses the same canonical room');
checkContract(str_contains($controller, "\$room = 'skinsyntax_room_' . \$live['id']"), 'track probe uses the same canonical room');
checkContract(str_contains($controller, "public function apiSearchCatalogProducts()"), 'catalog search endpoint exists');
checkContract(str_contains($controller, "'chats' => \$history") && str_contains($controller, "'data' => \$history"), 'chat history keeps both current and compatibility response keys');
checkContract(str_contains($controller, "'chat' => \$this->publicLiveChat"), 'chat send returns the persisted message for realtime broadcast');

foreach ($routers as $index => $router) {
    checkContract(str_contains($router, "case 'api_search_catalog_products':"), 'catalog search route is registered in router ' . ($index + 1));
}

checkContract(str_contains($studio, "client_message_id: clientMessageId"), 'Studio sends the required chat idempotency key');
checkContract(
    str_contains($studio, 'Math.max(0, lastChatTimestamp - 1)') &&
    str_contains($studio, "&after=' + encodeURIComponent(after)"),
    'Studio chat polling overlaps its API cursor to keep messages with the same timestamp'
);
checkContract(str_contains($studio, "Array.isArray(data.chats) ? data.chats : (Array.isArray(data.data) ? data.data : [])"), 'Studio reads both chat history response keys');
checkContract(!str_contains($studio, 'window.location.reload()'), 'Studio product actions do not reload and reactivate media');
checkContract(str_contains($studio, 'id="catalogSearchInput"') && str_contains($studio, 'role="combobox"'), 'product picker is a searchable combobox');
checkContract(!str_contains($studio, 'livekit-ingress.skinsyntax.vn') && !str_contains($studio, "md5('live_"), 'unconfigured OBS credentials are not presented as usable');
checkContract(str_contains($studio, '<option value="5">5 phút</option>') && str_contains($studio, '<option value="0">Không giới hạn</option>'), 'manual pin duration offers timed and unlimited choices');
checkContract(str_contains($studio, "sourceHasRealAudio") && str_contains($studio, "studio_audio_not_published"), 'Studio does not publish a synthetic silent audio track as real audio');
checkContract(str_contains($studio, "videoPublication?.trackSid") && str_contains($studio, "audioPublication?.trackSid"), 'Studio waits for LiveKit publication confirmation');
checkContract(str_contains($controller, "for (\$attempt = 1; \$attempt <= 3; \$attempt++)"), 'server-side video confirmation retries while LiveKit settles');

checkContract(str_contains($model, "'pin_duration_minutes' => \$durationMinutes"), 'pin duration is persisted in the live document');
checkContract(str_contains($model, "'deal_bat_dau', 'deal_ket_thuc', 'pin_duration_minutes'") && str_contains($studio, 'async function unpinProduct()'), 'Studio can clear a pin without deleting the product');
checkContract(str_contains($controller, "'pin_ends_at' => \$pinEndsAt") && str_contains($controller, "'server_time' => \$now"), 'product API exposes server-based pin timing');
checkContract(str_contains($viewerView, 'id="livePinCountdown"'), 'viewer contains a pin countdown target');
checkContract(str_contains($viewer, "function updatePinnedCountdown()") && str_contains($viewer, "products_changed"), 'viewer refreshes products and countdown after realtime changes');
checkContract(str_contains($admin, 'UPLOAD_ERR_INI_SIZE') && str_contains($admin, 'UPLOAD_ERR_CANT_WRITE'), 'thumbnail upload reports server size and write failures specifically');
checkContract(str_contains($admin, '$failureMessage = $e->getMessage();') && str_contains($admin, "set_flash('error', \$failureMessage)"), 'live create preserves the actionable upload error instead of replacing it with a generic message');
checkContract(str_contains($adminView, 'liveThumbnailPreviewImage') && str_contains($adminView, 'URL.createObjectURL'), 'admin can preview a local cover image before creating the session');
checkContract(str_contains($viewerView, 'ss-live-hero__backdrop') && str_contains($studioView, 'studio-topbar__cover'), 'the selected cover is reused as a contained background in viewer and studio banners');
checkContract(str_contains($studio, '.catalog-result { display: block; min-width: 0;') && str_contains($studio, 'catalog-result__name { display: block; min-width: 0; overflow-wrap: anywhere;'), 'long catalog names wrap fully within the product result row');
checkContract(str_contains($controller, "\$job['live_offers'] = LiveOffer::openDeals(") && str_contains($controller, "\$job['live_offer_prices']"), 'SYNA receives every active offer and its matching live price');
checkContract(str_contains($controller, 'bao nhieu san pham') && str_contains($controller, 'dang gioi thieu') && str_contains($controller, 'findManyByIds'), 'SYNA can list products currently introduced in the live session');
checkContract(str_contains($viewer, "deal.id !== 'pinned'") && str_contains($viewer, "view.textContent = 'Xem'") && str_contains($viewer, 'openProductModal(deal, deal.id)'), 'live product drawer avoids a duplicate pin and opens a quick-view modal for each offer');
checkContract(str_contains($viewerView, 'id="livePinnedOverlay"') && str_contains($viewer, 'livePinnedOverlayName') && str_contains($viewer, 'const featuredOffer = pinned ?') && str_contains($viewer, ': offers[0] ?'), 'a pinned or currently open deal appears as a compact overlay on the live video');
checkContract(!str_contains($viewerView, 'Giá và thời gian do quản trị viên thiết lập'), 'customer-facing deal drawer avoids admin-only wording');
checkContract(str_contains($viewer, "message.reply_to_sender_name || original?.sender_name") && str_contains($controller, "'reply_to_sender_name'"), 'SYNA replies visibly identify the viewer being addressed');
checkContract(str_contains($viewer, "document.addEventListener('visibilitychange'") && str_contains($viewer, 'refreshPublisherHeartbeat'), 'publisher sends a heartbeat immediately after returning to the tab');

if ($failed > 0) {
    echo "\n{$failed} CONTRACT CHECK(S) FAILED\n";
    exit(1);
}

echo "\nALL PASSED\n";
