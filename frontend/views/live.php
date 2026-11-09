<?php
$pageTitle = 'SkinSyntax Live Commerce';
require_once __DIR__ . '/layouts/header.php';

$role = current_role();
$isStaff = in_array($role, ['admin', 'nhanvien'], true);
$isStudio = $isStaff && (string)($_GET['studio'] ?? '') === '1';
$hasLive = is_array($currentLive ?? null) && !empty($currentLive['id']);
$live = $hasLive ? $currentLive : [];
$displayStreamer = trim((string)preg_replace('/\s*&?\s*AI\s*Co[-\s]?Host\b/iu', '', (string)($live['streamer'] ?? '')));
$displayStreamer = $displayStreamer !== '' ? $displayStreamer : 'SkinSyntax';
$liveStatus = (string)($live['status'] ?? 'none');
$isLive = $liveStatus === 'live';
$canChat = $isLive;
$pinned = $live['pinned_product'] ?? null;
$pinnedId = (string)($pinned['ma_san_pham'] ?? $pinned['id'] ?? $live['ma_san_pham_ghim'] ?? '');
$pinnedName = (string)($pinned['ten_san_pham'] ?? 'Chưa có sản phẩm ghim');
$pinnedBrand = (string)($pinned['thuong_hieu'] ?? '');
$pinnedPrice = (float)($live['gia_uu_dai_live'] ?? ($pinned['gia_ban'] ?? 0));
$marketPrice = (float)($pinned['gia_thi_truong'] ?? 0);
$pinnedImage = $pinned ? resolve_image_url(product_image_source($pinned)) : '';
$discount = ($marketPrice > $pinnedPrice && $marketPrice > 0) ? (int)round((($marketPrice - $pinnedPrice) / $marketPrice) * 100) : 0;
$statusLabels = [
    'none' => ['Chưa có phiên phát sóng', 'neutral'],
    'scheduled' => ['Đã lên lịch', 'scheduled'],
    'ready' => ['Sẵn sàng phát', 'ready'],
    'live' => ['ĐANG PHÁT', 'live'],
    'interrupted' => ['Đang kết nối lại', 'warning'],
    'ended' => ['Đã kết thúc', 'ended'],
    'processing' => ['Đang xử lý bản ghi', 'processing'],
    'replay_ready' => ['Xem lại', 'replay'],
    'recording_failed' => ['Lưu video lỗi', 'danger'],
];
[$statusLabel, $statusTone] = $statusLabels[$liveStatus] ?? $statusLabels['none'];
$recordingUrl = (string)($live['url_ban_ghi'] ?? '');
?>

<style>
  .ss-live-page{--ss-ink:#17231d;--ss-muted:#66736b;--ss-line:#dfe8e1;--ss-green:#176b45;--ss-green-dark:#0d3f2c;--ss-soft:#f5f8f5;background:#f7f9f7;color:var(--ss-ink);min-height:100vh;padding-bottom:48px}
  .ss-live-hero{background:linear-gradient(135deg,#fbfdfb 0%,#edf5ef 100%);border-bottom:1px solid var(--ss-line);padding:28px 0}
  .ss-live-hero h1{font-size:clamp(1.65rem,3vw,2.6rem);letter-spacing:-.04em;margin:0 0 8px;font-weight:800}
  .ss-live-hero p{max-width:760px;color:var(--ss-muted);margin:0;line-height:1.6}
  .ss-live-wrap{max-width:1440px;margin:0 auto;padding:28px 20px 0}
  .ss-live-grid{display:grid;grid-template-columns:minmax(0,2.05fr) minmax(320px,.95fr);gap:20px;align-items:start}
  .ss-card{background:#fff;border:1px solid var(--ss-line);border-radius:18px;box-shadow:0 8px 26px rgba(28,63,43,.07)}
  .ss-player{position:relative;aspect-ratio:16/9;overflow:hidden;background:#0a1711;border-radius:18px;border:1px solid #1c3c2a;isolation:isolate}
  .ss-player video{position:absolute;inset:0;width:100%;height:100%;object-fit:cover;background:#0a1711}
  .ss-player__waiting{position:absolute;inset:0;z-index:2;display:flex;align-items:center;justify-content:center;text-align:center;padding:24px;background:radial-gradient(circle at 50% 35%,#214d36 0%,#0b1911 64%);color:#fff}
  .ss-player__waiting[hidden]{display:none}
  .ss-player__waiting-inner{max-width:520px}.ss-player__waiting-icon{width:72px;height:72px;display:grid;place-items:center;border-radius:50%;background:#2e9561;color:#fff;font-size:28px;margin:0 auto 15px}
  .ss-player__top,.ss-player__bottom{position:absolute;z-index:5;left:0;right:0;padding:16px;display:flex;align-items:center;gap:8px;flex-wrap:wrap;color:#fff}
  .ss-player__top{top:0;background:linear-gradient(180deg,rgba(0,0,0,.7),transparent)}.ss-player__bottom{bottom:0;background:linear-gradient(0deg,rgba(0,0,0,.82),transparent);padding-top:42px}
  .ss-badge{display:inline-flex;align-items:center;gap:6px;border-radius:999px;padding:6px 10px;font-size:12px;font-weight:750;line-height:1}.ss-badge--live{background:#e23b4e;color:#fff}.ss-badge--ready{background:#dff1e6;color:#0f6740}.ss-badge--scheduled{background:#fff0c2;color:#7b5a00}.ss-badge--warning{background:#fff0c2;color:#795700}.ss-badge--neutral,.ss-badge--ended,.ss-badge--processing{background:#e9eeea;color:#526057}.ss-badge--replay{background:#dff1e6;color:#0f6740}.ss-badge--danger{background:#ffe2e5;color:#ad293c}
  .ss-player__actions{margin-left:auto;display:flex;gap:7px;flex-wrap:wrap}.ss-player__actions button,.ss-player__actions a{border:1px solid rgba(255,255,255,.35);background:rgba(0,0,0,.42);color:#fff;border-radius:999px;padding:6px 10px;font-size:12px;text-decoration:none}.ss-player__actions button:hover,.ss-player__actions a:hover{background:rgba(255,255,255,.15)}
  .ss-streamer{display:flex;align-items:center;gap:10px}.ss-avatar{width:38px;height:38px;border-radius:50%;display:grid;place-items:center;background:#d9efe2;color:#176b45;font-weight:800}.ss-streamer strong{display:block;font-size:14px}.ss-streamer small{display:block;color:#d2e1d7;font-size:12px;margin-top:2px}
  .ss-product{padding:18px;margin-top:14px}.ss-product__head{display:flex;justify-content:space-between;align-items:center;gap:10px;margin-bottom:14px}.ss-kicker{color:#c3344d;font-size:11px;font-weight:800;letter-spacing:.06em;text-transform:uppercase}.ss-product__body{display:flex;gap:14px;align-items:center}.ss-product__image{width:78px;height:78px;object-fit:cover;border:1px solid var(--ss-line);border-radius:12px;background:#f5f7f5}.ss-product__image--empty{display:grid;place-items:center;color:#8a988e;font-size:24px}.ss-product__info{min-width:0;flex:1}.ss-product__brand{color:var(--ss-green);font-size:12px;font-weight:750}.ss-product__name{font-weight:750;margin:3px 0 6px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}.ss-price{color:var(--ss-green);font-size:20px;font-weight:850}.ss-old-price{color:#8c968e;text-decoration:line-through;font-size:12px;margin-left:8px}.ss-product__cta{display:flex;gap:8px;align-items:center}.ss-btn{border:0;border-radius:999px;padding:10px 16px;font-size:13px;font-weight:800;cursor:pointer;text-decoration:none;display:inline-flex;align-items:center;justify-content:center;gap:7px}.ss-btn--primary{background:var(--ss-green-dark);color:#fff}.ss-btn--primary:hover{background:#176b45;color:#fff}.ss-btn--ghost{background:#edf5ef;color:var(--ss-green)}.ss-btn[disabled]{opacity:.55;cursor:not-allowed}.ss-empty-product{padding:20px;text-align:center;color:var(--ss-muted);border:1px dashed var(--ss-line);border-radius:12px}
  .ss-products{padding:18px;margin-top:14px}.ss-products h2,.ss-session-title{font-size:16px;margin:0;font-weight:800}.ss-product-list{display:grid;grid-template-columns:repeat(auto-fill,minmax(220px,1fr));gap:10px;margin-top:14px}.ss-deal{display:flex;gap:10px;align-items:center;padding:10px;border:1px solid var(--ss-line);border-radius:13px;background:#fbfdfb}.ss-deal img{width:54px;height:54px;object-fit:cover;border-radius:9px}.ss-deal__meta{min-width:0;flex:1}.ss-deal__meta strong{display:block;font-size:13px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}.ss-deal__meta small{display:block;color:var(--ss-muted);font-size:11px;margin-top:3px}.ss-deal .ss-btn{padding:7px 10px;font-size:11px}
  .ss-chat{position:sticky;top:86px;overflow:hidden}.ss-chat__head{padding:16px;border-bottom:1px solid var(--ss-line)}.ss-chat__title{display:flex;align-items:center;justify-content:space-between;gap:8px}.ss-chat__title h2{font-size:16px;margin:0;font-weight:800}.ss-chat__tabs{display:flex;gap:6px;margin-top:13px}.ss-chat__tabs button{border:1px solid var(--ss-line);background:#fff;border-radius:999px;padding:6px 10px;font-size:11px;color:var(--ss-muted);cursor:pointer}.ss-chat__tabs button.is-active{background:#e8f3eb;border-color:#b9dbc4;color:var(--ss-green);font-weight:800}.ss-chat__body{height:430px;overflow:auto;padding:14px;background:#f9fbf9}.ss-chat__notice{font-size:12px;line-height:1.45;color:var(--ss-muted);background:#eef6ef;border-radius:10px;padding:10px;margin-bottom:10px}.ss-message{padding:9px 10px;border-radius:11px;background:#fff;border:1px solid var(--ss-line);margin-bottom:8px;font-size:13px;line-height:1.45}.ss-message--ai{background:#eaf5ed;border-color:#c8e2cf}.ss-message__meta{font-size:11px;color:var(--ss-muted);margin-bottom:3px}.ss-message__meta strong{color:var(--ss-green)}.ss-message__pill{font-size:10px;background:#fbe5a7;color:#6f5100;border-radius:999px;padding:2px 5px;margin-left:5px}.ss-chat__footer{padding:14px;border-top:1px solid var(--ss-line)}.ss-chat__footer input{border:1px solid var(--ss-line);border-radius:999px;padding:10px 13px;width:100%;font-size:13px;outline:none}.ss-chat__footer input:focus{border-color:#8cc09d;box-shadow:0 0 0 3px #e8f3eb}.ss-chat__row{display:flex;gap:7px}.ss-chat__row .ss-btn{width:42px;padding:0}.ss-chat__helper{font-size:11px;color:var(--ss-muted);margin:8px 2px 0}.ss-chat__locked{padding:12px;border-radius:10px;background:#f0f3f0;color:#6e7b72;font-size:12px;text-align:center}
  .ss-sessions{margin-top:36px}.ss-session-toolbar{display:flex;justify-content:space-between;align-items:end;gap:14px;flex-wrap:wrap;margin-bottom:14px}.ss-session-toolbar p{color:var(--ss-muted);font-size:12px;margin:5px 0 0}.ss-filters{display:flex;gap:6px;flex-wrap:wrap}.ss-filters button{border:1px solid var(--ss-line);background:#fff;color:var(--ss-muted);border-radius:999px;padding:7px 11px;font-size:11px;cursor:pointer}.ss-filters button.is-active{background:var(--ss-green);color:#fff;border-color:var(--ss-green)}.ss-session-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:14px}.ss-session{overflow:hidden;min-width:0}.ss-session__thumb{height:150px;background:linear-gradient(135deg,#e8f1eb,#b8d6c1);position:relative}.ss-session__thumb img{width:100%;height:100%;object-fit:cover}.ss-session__thumb--empty{display:grid;place-items:center;color:#6f8c78;font-size:25px}.ss-session__thumb .ss-badge{position:absolute;top:10px;left:10px}.ss-session__body{padding:13px}.ss-session__body strong{display:block;font-size:13px;line-height:1.4;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden;min-height:36px}.ss-session__body small{display:block;color:var(--ss-muted);margin:7px 0 12px;font-size:11px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}.ss-session__body .ss-btn{width:100%;padding:8px 10px;font-size:11px}
  .ss-empty{padding:68px 24px;text-align:center}.ss-empty i{font-size:42px;color:#7b9984}.ss-empty h2{font-size:22px;margin:15px 0 8px}.ss-empty p{max-width:520px;margin:0 auto;color:var(--ss-muted);line-height:1.6}
  @media (max-width:1050px){.ss-live-grid{grid-template-columns:1fr}.ss-chat{position:static}.ss-chat__body{height:330px}.ss-session-grid{grid-template-columns:repeat(2,minmax(0,1fr))}}
  @media (max-width:620px){.ss-live-wrap{padding:18px 12px 0}.ss-live-hero{padding:22px 0}.ss-live-hero .container{padding-left:16px;padding-right:16px}.ss-player{border-radius:12px}.ss-product__body{align-items:flex-start}.ss-product__cta{width:100%;margin-top:10px}.ss-product__body{flex-wrap:wrap}.ss-product__info{flex-basis:calc(100% - 94px)}.ss-product__cta{margin-left:92px}.ss-product__cta .ss-btn{flex:1}.ss-session-grid{grid-template-columns:1fr}.ss-session__thumb{height:170px}.ss-player__actions{width:100%;margin-left:0}.ss-player__top{align-items:flex-start}.ss-player__bottom{padding:12px;padding-top:38px}.ss-chat__body{height:310px}}
</style>

<main class="ss-live-page" id="ssLivePage" data-live-id="<?= h($live['id'] ?? '') ?>" data-live-status="<?= h($liveStatus) ?>" data-is-staff="<?= $isStaff ? '1' : '0' ?>" data-is-studio="<?= $isStudio ? '1' : '0' ?>" data-base-url="<?= h(BASE_URL) ?>">
  <section class="ss-live-hero">
    <div class="container">
      <div class="d-flex flex-wrap align-items-center justify-content-between gap-3">
        <div>
          <div class="d-flex gap-2 flex-wrap mb-2">
            <span class="ss-badge ss-badge--live"><i class="fa-solid fa-circle"></i> SKINSYNTAX LIVE</span>
            <span class="ss-badge ss-badge--ready"><i class="fa-solid fa-shield-heart"></i> Hàng chính hãng</span>
          </div>
          <h1>Bán hàng trực tiếp, tư vấn đúng sản phẩm</h1>
          <p>Hình ảnh thật, trò chuyện thật và ưu đãi lấy từ sản phẩm trong cửa hàng. Trạng thái ĐANG PHÁT chỉ hiện khi hình ảnh và âm thanh đã hoạt động.</p>
          <?php if ($isStudio): ?><div class="mt-3 small text-success fw-semibold"><i class="fa-solid fa-circle-info me-1"></i>Bật hình ảnh và âm thanh ở góc trên video. Khi cả hai hoạt động, người xem mới nhìn thấy phiên phát.</div><?php endif; ?>
        </div>
        <?php if ($isStaff): ?>
          <a class="ss-btn ss-btn--primary" href="<?= BASE_URL ?>/index.php?r=admin_lives"><i class="fa-solid fa-sliders"></i> Quản lý phiên phát</a>
        <?php endif; ?>
      </div>
    </div>
  </section>

  <div class="ss-live-wrap">
    <?php if (!$hasLive): ?>
      <section class="ss-card ss-empty">
        <i class="fa-solid fa-video-slash"></i>
        <h2>Chưa có phiên phát khả dụng</h2>
        <p>Không có dữ liệu phiên live thật để hiển thị. Quản trị viên có thể tạo phiên mới trong khu vực quản lý.</p>
        <?php if ($isStaff): ?><a class="ss-btn ss-btn--primary mt-3" href="<?= BASE_URL ?>/index.php?r=admin_lives">Mở quản lý phiên phát</a><?php endif; ?>
      </section>
    <?php else: ?>
      <div class="ss-live-grid">
        <section>
          <div class="ss-player" id="ssPlayer">
            <video id="livekitRemoteVideo" autoplay playsinline <?= $liveStatus === 'replay_ready' ? 'hidden' : '' ?>></video>
            <video id="livekitLocalVideo" autoplay playsinline muted hidden></video>
            <audio id="livekitRemoteAudio" autoplay></audio>
            <?php if ($liveStatus === 'replay_ready' && $recordingUrl !== ''): ?>
              <video id="replayVideoPlayer" controls playsinline preload="metadata" src="<?= h($recordingUrl) ?>"></video>
            <?php endif; ?>
            <div class="ss-player__waiting" id="liveWaitingOverlay" <?= ($isLive && $liveStatus !== 'replay_ready') ? '' : ($liveStatus === 'replay_ready' && $recordingUrl !== '' ? 'hidden' : '') ?>>
              <div class="ss-player__waiting-inner">
                <div class="ss-player__waiting-icon"><i class="fa-solid <?= $liveStatus === 'ready' ? 'fa-video' : ($liveStatus === 'scheduled' ? 'fa-calendar-days' : 'fa-tower-broadcast') ?>"></i></div>
                <h2 class="h5 fw-bold" id="liveWaitingTitle"><?= h($statusLabel) ?></h2>
                <p class="mb-0 opacity-75" id="liveWaitingMessage">
                  <?php if ($liveStatus === 'live'): ?>Đang chờ hình ảnh từ người phát.<?php elseif ($liveStatus === 'ready'): ?>Bạn có thể bắt đầu sau khi cho phép sử dụng hình ảnh và âm thanh.<?php elseif ($liveStatus === 'scheduled'): ?>Phiên chưa đến thời gian phát sóng.<?php elseif ($liveStatus === 'replay_ready'): ?>Chưa có video xem lại.<?php else: ?>Phiên này chưa có video để xem.<?php endif; ?>
                </p>
              </div>
            </div>
            <div class="ss-player__top">
              <span class="ss-badge ss-badge--<?= h($statusTone) ?>" id="liveStatusBadge"><i class="fa-solid fa-circle"></i> <span id="liveStatusLabel"><?= h($statusLabel) ?></span></span>
              <?php if ($isLive): ?><span class="ss-badge ss-badge--neutral"><i class="fa-solid fa-eye"></i> <span id="liveViewerCount">0</span> đang xem</span><?php endif; ?>
              <span class="ss-badge ss-badge--neutral" id="liveConnectionBadge"><i class="fa-solid fa-circle-notch fa-spin"></i> Đang chuẩn bị phát</span>
              <div class="ss-player__actions">
                <?php if ($isLive): ?><button type="button" id="btnReaction"><i class="fa-solid fa-heart"></i> <span id="reactionCount"><?= (int)($live['reaction_count'] ?? 0) ?></span></button><?php endif; ?>
                <button type="button" id="btnShareLive"><i class="fa-solid fa-share-nodes"></i> Chia sẻ</button>
                <?php if ($isStudio && in_array($liveStatus, ['ready', 'live', 'interrupted'], true)): ?>
                  <button type="button" id="btnToggleCam"><i class="fa-solid fa-video"></i> Bật hình ảnh</button>
                  <button type="button" id="btnToggleMic"><i class="fa-solid fa-microphone"></i> Bật âm thanh</button>
                  <?php if ($isLive): ?><button type="button" id="btnEndLive" class="text-danger"><i class="fa-solid fa-stop"></i> Kết thúc</button><?php endif; ?>
                <?php endif; ?>
              </div>
            </div>
            <div class="ss-player__bottom">
              <div class="ss-streamer"><div class="ss-avatar"><?= h(mb_strtoupper(mb_substr($displayStreamer, 0, 2))) ?></div><div><strong><?= h($displayStreamer) ?></strong><small><?= h($live['title'] ?? '') ?></small></div></div>
            </div>
          </div>

          <section class="ss-card ss-product" aria-label="Sản phẩm đang giới thiệu trong phiên phát">
            <div class="ss-product__head"><span class="ss-kicker"><i class="fa-solid fa-thumbtack"></i> Sản phẩm đang giới thiệu</span><span class="ss-badge ss-badge--neutral" id="flashDealTimer">Ưu đãi của phiên phát</span></div>
            <?php if ($pinned): ?>
              <div class="ss-product__body">
                <?php if ($pinnedImage !== ''): ?><img id="livePinnedImg" class="ss-product__image" src="<?= h($pinnedImage) ?>" alt="<?= h($pinnedName) ?>"><?php else: ?><div id="livePinnedImg" class="ss-product__image ss-product__image--empty"><i class="fa-regular fa-image"></i></div><?php endif; ?>
                <div class="ss-product__info"><div id="livePinnedBrand" class="ss-product__brand"><?= h($pinnedBrand) ?></div><div id="livePinnedName" class="ss-product__name"><?= h($pinnedName) ?></div><div><span id="livePinnedPrice" class="ss-price"><?= vnd($pinnedPrice) ?></span><?php if ($marketPrice > $pinnedPrice): ?><span id="livePinnedMarketPrice" class="ss-old-price"><?= vnd($marketPrice) ?></span><span id="livePinnedDiscountBadge" class="ss-badge ss-badge--danger ms-2">-<?= $discount ?>%</span><?php endif; ?></div></div>
                <div class="ss-product__cta">
                  <form id="liveBuyNowForm" action="<?= BASE_URL ?>/index.php?r=api_live_add_to_cart" method="post"><input type="hidden" name="live_id" value="<?= h($live['id']) ?>"><input type="hidden" name="product_id" id="livePinnedProductId" value="<?= h($pinnedId) ?>"><button class="ss-btn ss-btn--primary" type="submit" <?= $isLive ? '' : 'disabled' ?>><i class="fa-solid fa-bag-shopping"></i> Thêm vào giỏ</button></form>
                  <a class="ss-btn ss-btn--ghost" href="<?= BASE_URL ?>/index.php?r=chitiet&amp;id=<?= urlencode($pinnedId) ?>">Chi tiết</a>
                </div>
              </div>
            <?php else: ?><div class="ss-empty-product">Phiên này chưa có sản phẩm đang giới thiệu. Khi người quản trị chọn sản phẩm, nội dung sẽ tự cập nhật.</div><?php endif; ?>
          </section>

          <?php
            $dealProducts = [];
            foreach ((array)($live['danh_sach_deal'] ?? []) as $deal) {
                $dealId = (string)($deal['ma_san_pham'] ?? '');
                foreach ((array)($allProducts ?? []) as $product) {
                    if ($dealId !== '' && (string)($product['ma_san_pham'] ?? $product['id'] ?? '') === $dealId) { $dealProducts[] = ['product' => $product, 'deal' => $deal]; break; }
                }
            }
          ?>
          <?php if ($dealProducts): ?><section class="ss-card ss-products"><h2>Danh sách ưu đãi trong phiên</h2><div class="ss-product-list"><?php foreach ($dealProducts as $deal): $product = $deal['product']; $dealInfo = $deal['deal']; $productId = (string)($product['ma_san_pham'] ?? $product['id'] ?? ''); $image = resolve_image_url(product_image_source($product)); ?><article class="ss-deal"><img src="<?= h($image) ?>" alt="<?= h($product['ten_san_pham'] ?? '') ?>"><div class="ss-deal__meta"><strong><?= h($product['ten_san_pham'] ?? '') ?></strong><small><?= vnd((float)($dealInfo['gia_uu_dai_live'] ?? $product['gia_ban'] ?? 0)) ?> · Còn lại <?= (int)($dealInfo['so_luong_kho_deal'] ?? 0) ?></small></div><form class="live-add-to-cart-form" action="<?= BASE_URL ?>/index.php?r=api_live_add_to_cart" method="post"><input type="hidden" name="live_id" value="<?= h($live['id']) ?>"><input type="hidden" name="product_id" value="<?= h($productId) ?>"><button class="ss-btn ss-btn--ghost" type="submit" <?= $isLive ? '' : 'disabled' ?>>+ Giỏ</button></form></article><?php endforeach; ?></div></section><?php endif; ?>
        </section>

        <aside class="ss-card ss-chat" aria-label="Trò chuyện trực tiếp">
          <div class="ss-chat__head"><div class="ss-chat__title"><h2><i class="fa-solid fa-comments text-success me-2"></i>Trò chuyện trực tiếp</h2><span class="ss-badge ss-badge--<?= $isLive ? 'ready' : 'neutral' ?>" id="chatConnectionState"><?= $isLive ? 'Đang mở' : 'Chưa mở' ?></span></div><div class="ss-chat__tabs" role="tablist"><button type="button" class="is-active" data-chat-mode="all">Tất cả</button><button type="button" data-chat-mode="featured">Nổi bật</button><button type="button" data-chat-mode="qa">Hỏi đáp</button></div></div>
          <div class="ss-chat__body" id="liveChatBox"><div class="ss-chat__notice">Tin nhắn trong phiên sẽ được lưu đúng theo buổi phát.</div></div>
          <div class="ss-chat__footer">
            <?php if ($canChat): ?>
              <?php if (!$isStaff && !is_logged_in()): ?><input id="guestNickname" type="text" maxlength="40" placeholder="Tên hiển thị (không bắt buộc)" class="mb-2"><?php endif; ?>
              <form id="liveChatForm" class="ss-chat__row"><input id="liveChatInput" type="text" maxlength="1000" placeholder="Đặt câu hỏi về sản phẩm..." required><button class="ss-btn ss-btn--primary" type="submit" aria-label="Gửi"><i class="fa-solid fa-paper-plane"></i></button></form>
              <div class="ss-chat__helper">Tối đa 5 tin trong 20 giây. Không chia sẻ thông tin nhạy cảm.</div>
            <?php else: ?><div class="ss-chat__locked"><i class="fa-solid fa-lock me-1"></i> Trò chuyện sẽ mở khi người dẫn bắt đầu.</div><?php endif; ?>
          </div>
        </aside>
      </div>
    <?php endif; ?>

    <?php if (!empty($liveSessions)): ?>
      <section class="ss-sessions">
        <div class="ss-session-toolbar"><div><h2 class="ss-session-title">Các phiên phát khác</h2><p>Chỉ hiển thị phiên và video đã có trong hệ thống.</p></div><div class="ss-filters"><button type="button" class="is-active" data-session-filter="all">Tất cả (<?= count($liveSessions) ?>)</button><button type="button" data-session-filter="live">Đang phát</button><button type="button" data-session-filter="scheduled">Sắp phát</button><button type="button" data-session-filter="replay_ready">Xem lại</button></div></div>
        <div class="ss-session-grid" id="liveSessionGrid">
          <?php foreach ($liveSessions as $session): $sessionStatus = (string)($session['status'] ?? 'scheduled'); $sessionImage = (string)($session['thumbnail'] ?? ''); $sessionStreamer = trim((string)preg_replace('/\s*&?\s*AI\s*Co[-\s]?Host\b/iu', '', (string)($session['streamer'] ?? ''))); $sessionStreamer = $sessionStreamer !== '' ? $sessionStreamer : 'SkinSyntax'; [$sessionLabel, $sessionTone] = $statusLabels[$sessionStatus] ?? $statusLabels['none']; ?>
            <article class="ss-card ss-session" data-status="<?= h($sessionStatus) ?>"><div class="ss-session__thumb <?= $sessionImage === '' ? 'ss-session__thumb--empty' : '' ?>"><?php if ($sessionImage !== ''): ?><img src="<?= h($sessionImage) ?>" alt="<?= h($session['title'] ?? '') ?>" loading="lazy"><?php else: ?><i class="fa-solid fa-video"></i><?php endif; ?><span class="ss-badge ss-badge--<?= h($sessionTone) ?>"><?= h($sessionLabel) ?></span></div><div class="ss-session__body"><strong><?= h($session['title'] ?? '') ?></strong><small><i class="fa-solid fa-user-headset me-1"></i><?= h($sessionStreamer) ?><?php if ($sessionStatus === 'live'): ?> · <span class="card-viewer-count"><?= (int)($session['viewers'] ?? 0) ?></span> đang xem<?php endif; ?></small><a class="ss-btn <?= $sessionStatus === 'live' ? 'ss-btn--primary' : 'ss-btn--ghost' ?>" href="<?= BASE_URL ?>/index.php?r=live&amp;id=<?= urlencode((string)$session['id']) ?>"><?= $sessionStatus === 'replay_ready' ? 'Xem lại bản ghi' : ($sessionStatus === 'live' ? 'Xem ngay' : 'Xem chi tiết') ?></a></div></article>
          <?php endforeach; ?>
        </div>
      </section>
    <?php endif; ?>
  </div>
</main>

<?php if ($hasLive): ?>
<script src="https://cdn.jsdelivr.net/npm/livekit-client@2.1.2/dist/livekit-client.umd.min.js" crossorigin="anonymous"></script>
<script>
(function(){
  'use strict';
  const page=document.getElementById('ssLivePage'); if(!page) return;
  const base=page.dataset.baseUrl||'.', liveId=page.dataset.liveId||'', status=page.dataset.liveStatus||'none', isStaff=page.dataset.isStaff==='1', isStudio=page.dataset.isStudio==='1';
  const $=(id)=>document.getElementById(id);
  const url=(route)=>base+'/index.php?r='+route;
  const pageJson=async (endpoint, options)=>{const response=await fetch(endpoint, options||{}); let data={}; try{data=await response.json();}catch(e){} if(!response.ok && !data.message) data.message='Yêu cầu không thành công'; return {response,data};};
  const esc=(value)=>String(value??'').replace(/[&<>"]/g, ch=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[ch]));
  let chatMode='all', rendered=new Set(), livekitRoom=null, localVideoTrack=null, localAudioTrack=null, recording=null, recordingChunks=[], recordingStream=null, streamState=status, publisherToken='';
  const chatBox=$('liveChatBox'), statusBadge=$('liveConnectionBadge'), wait=$('liveWaitingOverlay'), waitMsg=$('liveWaitingMessage'), remote=$('livekitRemoteVideo'), local=$('livekitLocalVideo'), audio=$('livekitRemoteAudio');
  function setConnection(text, tone){if(!statusBadge)return; statusBadge.className='ss-badge ss-badge--'+tone; statusBadge.innerHTML='<i class="fa-solid fa-circle"></i> '+esc(text);}
  function renderMessage(msg){const id=msg.client_message_id||msg.id||[msg.sender_name,msg.message,msg.created_at].join('|'); if(rendered.has(id)||!chatBox)return; rendered.add(id); const el=document.createElement('div'); el.className='ss-message'+(msg.is_ai?' ss-message--ai':''); const meta=document.createElement('div'); meta.className='ss-message__meta'; const name=document.createElement('strong'); name.textContent=msg.sender_name||'Khách hàng'; meta.appendChild(name); if(msg.is_ai){const badge=document.createElement('span');badge.className='ss-message__pill';badge.textContent='Tư vấn tự động';meta.appendChild(badge)} if(msg.is_order){const badge=document.createElement('span');badge.className='ss-message__pill';badge.textContent='Đơn hàng';meta.appendChild(badge)} const body=document.createElement('div');body.textContent=msg.message||'';el.append(meta,body);chatBox.appendChild(el);chatBox.scrollTop=chatBox.scrollHeight;}
  async function loadChat(){if(!liveId||!chatBox)return; const {data}=await pageJson(url('api_live_chat_history')+'&room_id='+encodeURIComponent(liveId)+'&mode='+encodeURIComponent(chatMode)); if(data.ok&&Array.isArray(data.data))data.data.forEach(renderMessage);}
  document.querySelectorAll('[data-chat-mode]').forEach(btn=>btn.addEventListener('click',()=>{document.querySelectorAll('[data-chat-mode]').forEach(b=>b.classList.remove('is-active'));btn.classList.add('is-active');chatMode=btn.dataset.chatMode||'all';rendered=new Set();chatBox.innerHTML='';loadChat();}));
  const chatForm=$('liveChatForm'); if(chatForm){chatForm.addEventListener('submit',async e=>{e.preventDefault();const input=$('liveChatInput');const message=input.value.trim();if(!message)return;input.disabled=true;const clientId=(window.crypto&&crypto.randomUUID)?crypto.randomUUID():'msg-'+String(Date.now())+'-'+String(performance.now()).replace(/\D/g,'');const fd=new FormData();fd.append('room_id',liveId);fd.append('message',message);fd.append('client_message_id',clientId);const guest=$('guestNickname');if(guest)fd.append('guest_nickname',guest.value.trim());const {data}=await pageJson(url('api_live_chat'),{method:'POST',body:fd});input.disabled=false;if(data.ok){input.value='';const packet={type:'chat',message:{client_message_id:clientId,sender_name:guest&&guest.value.trim()?guest.value.trim():'Bạn',message,created_at:new Date().toISOString()}};renderMessage(packet.message);if(livekitRoom&&livekitRoom.state===LivekitClient.ConnectionState.Connected){try{await livekitRoom.localParticipant.publishData(new TextEncoder().encode(JSON.stringify(packet)),{reliable:true});}catch(err){console.warn('LiveKit chat publish failed',err);}}}else{alert(data.message||'Không thể gửi tin nhắn');}});loadChat();setInterval(loadChat,3000);}
  async function updateStream(state){const fd=new FormData();fd.append('live_id',liveId);fd.append('state',state);if(state==='live'&&publisherToken)fd.append('publisher_token',publisherToken);const {data}=await pageJson(url('api_live_stream_state'),{method:'POST',body:fd});if(!data.ok)throw new Error(data.message||'Không thể cập nhật trạng thái');streamState=state;return data;}
  function addTrackToRecording(track){if(!track||!track.mediaStream)return;recordingStream=recordingStream||new MediaStream();track.mediaStream.getTracks().forEach(mediaTrack=>{if(!recordingStream.getTracks().some(existing=>existing.id===mediaTrack.id))recordingStream.addTrack(mediaTrack)});if(!recording&&window.MediaRecorder&&recordingStream.getVideoTracks().length){let mime='video/webm;codecs=vp8,opus';if(!MediaRecorder.isTypeSupported(mime))mime='video/webm';try{recordingChunks=[];recording=new MediaRecorder(recordingStream,{mimeType:mime});recording.ondataavailable=e=>{if(e.data&&e.data.size)recordingChunks.push(e.data)};recording.start(1000);}catch(err){console.warn('Recording unavailable',err);}}
  }
  async function uploadRecording(){if(!recording||recording.state==='inactive'||!recordingChunks.length)return null;return new Promise(resolve=>{recording.onstop=async()=>{const blob=new Blob(recordingChunks,{type:recording.mimeType||'video/webm'});const fd=new FormData();fd.append('live_id',liveId);fd.append('video_blob',blob,'live-'+liveId+'.webm');const {data}=await pageJson(url('api_upload_live_recording'),{method:'POST',body:fd});resolve(data)};recording.stop();});}
  async function connectLiveKit(){const canConnect=status==='live'||(isStudio&&['ready','interrupted'].includes(status));if(!canConnect){setConnection('Chưa bắt đầu phát','neutral');return;}if(!window.LivekitClient){setConnection('Chưa mở được phần phát video','warning');if(waitMsg)waitMsg.textContent='Không tải được phần phát video. Vui lòng thử lại.';return;}try{const {response,data}=await pageJson(url('api_live_token')+'&id='+encodeURIComponent(liveId));if(!response.ok||!data.ok||!data.data||!data.data.token){setConnection(data.message||'Chưa có kết nối phát','warning');return;}const info=data.data;publisherToken=info.participant_token||'';livekitRoom=new LivekitClient.Room({adaptiveStream:true,dynacast:true});livekitRoom.on(LivekitClient.RoomEvent.ConnectionStateChanged,state=>{if(state===LivekitClient.ConnectionState.Connected)setConnection('Đã kết nối','ready');else if(state===LivekitClient.ConnectionState.Reconnecting)setConnection('Đang kết nối lại','warning');else if(state===LivekitClient.ConnectionState.Disconnected)setConnection('Đã ngắt kết nối','ended');});livekitRoom.on(LivekitClient.RoomEvent.TrackSubscribed,(track)=>{if(track.kind===LivekitClient.Track.Kind.Video){track.attach(remote);remote.hidden=false;if(wait)wait.hidden=true;}else if(track.kind===LivekitClient.Track.Kind.Audio)track.attach(audio);});livekitRoom.on(LivekitClient.RoomEvent.TrackUnsubscribed,(track)=>{track.detach();if(track.kind===LivekitClient.Track.Kind.Video){remote.hidden=true;if(wait)wait.hidden=false;}});livekitRoom.on(LivekitClient.RoomEvent.DataReceived,(payload)=>{try{const packet=JSON.parse(new TextDecoder().decode(payload));if(packet.type==='chat'&&packet.message)renderMessage(packet.message);}catch(e){}});await livekitRoom.connect(info.server_url,info.participant_token);if(isStudio&&info.can_publish)setConnection('Có thể bắt đầu phát','ready');}catch(err){console.error(err);setConnection('Không kết nối được','danger');if(waitMsg)waitMsg.textContent='Không thể kết nối máy chủ phát. Vui lòng thử lại hoặc liên hệ quản trị viên.';}}
  async function markLiveIfReady(){if(localVideoTrack&&localAudioTrack&&streamState!=='live'){await updateStream('live');if(wait)wait.hidden=true;setConnection('Đang phát trực tiếp','ready');}else if(!localVideoTrack||!localAudioTrack){setConnection('Đã bật một phần, hãy bật cả hình ảnh và âm thanh','ready');}}
  async function toggleCam(){const button=$('btnToggleCam');if(!button)return;try{if(!localVideoTrack){if(!livekitRoom)throw new Error('Chưa kết nối');localVideoTrack=await LivekitClient.createLocalVideoTrack();await livekitRoom.localParticipant.publishTrack(localVideoTrack);localVideoTrack.attach(local);local.hidden=false;addTrackToRecording(localVideoTrack);button.innerHTML='<i class="fa-solid fa-video-slash"></i> Tắt hình ảnh';await markLiveIfReady();}else{await livekitRoom.localParticipant.unpublishTrack(localVideoTrack);localVideoTrack.stop();localVideoTrack=null;local.hidden=true;button.innerHTML='<i class="fa-solid fa-video"></i> Bật hình ảnh';if(streamState==='live')await updateStream('interrupted');}}catch(err){alert(err.message||'Không thể bật hình ảnh. Hãy cho phép trình duyệt sử dụng thiết bị hình ảnh.');}}
  async function toggleMic(){const button=$('btnToggleMic');if(!button)return;try{if(!localAudioTrack){if(!livekitRoom)throw new Error('Chưa kết nối');localAudioTrack=await LivekitClient.createLocalAudioTrack();await livekitRoom.localParticipant.publishTrack(localAudioTrack);addTrackToRecording(localAudioTrack);button.innerHTML='<i class="fa-solid fa-microphone-slash"></i> Tắt âm thanh';await markLiveIfReady();}else{await livekitRoom.localParticipant.unpublishTrack(localAudioTrack);localAudioTrack.stop();localAudioTrack=null;button.innerHTML='<i class="fa-solid fa-microphone"></i> Bật âm thanh';if(streamState==='live')await updateStream('interrupted');}}catch(err){alert(err.message||'Không thể bật âm thanh. Hãy cho phép trình duyệt sử dụng thiết bị âm thanh.');}}
  const cam=$('btnToggleCam'),mic=$('btnToggleMic');if(cam)cam.addEventListener('click',toggleCam);if(mic)mic.addEventListener('click',toggleMic);
  const end=$('btnEndLive');if(end)end.addEventListener('click',async()=>{if(!confirm('Kết thúc phiên phát? Video đã quay sẽ được lưu nếu quá trình ghi hình đang hoạt động.'))return;end.disabled=true;try{const upload=await uploadRecording();if(upload&&!upload.ok)alert(upload.message||'Không lưu được video');await updateStream('ended');window.location.href=base+'/index.php?r=live&id='+encodeURIComponent(liveId);}catch(err){alert(err.message||'Không thể kết thúc phiên');end.disabled=false;}});
  const reaction=$('btnReaction');if(reaction)reaction.addEventListener('click',async()=>{const fd=new FormData();fd.append('live_id',liveId);const {data}=await pageJson(url('api_live_reaction'),{method:'POST',body:fd});if(data.ok){$('reactionCount').textContent=data.reaction_count||0;reaction.classList.add('is-bumped');setTimeout(()=>reaction.classList.remove('is-bumped'),450);}});
  const share=$('btnShareLive');if(share)share.addEventListener('click',async()=>{try{await navigator.clipboard.writeText(window.location.href);share.innerHTML='<i class="fa-solid fa-check"></i> Đã sao chép';setTimeout(()=>share.innerHTML='<i class="fa-solid fa-share-nodes"></i> Chia sẻ',1600);}catch(e){window.prompt('Sao chép liên kết phiên phát:',window.location.href);}});
  async function heartbeat(){if(!liveId||streamState!=='live')return;const {data}=await pageJson(url('api_live_ping')+'&room='+encodeURIComponent(liveId));if(data.ok&&$('liveViewerCount'))$('liveViewerCount').textContent=data.active_viewers||0;}
  if(status==='live'){heartbeat();setInterval(heartbeat,5000);}connectLiveKit();
  document.querySelectorAll('[data-session-filter]').forEach(btn=>btn.addEventListener('click',()=>{document.querySelectorAll('[data-session-filter]').forEach(b=>b.classList.remove('is-active'));btn.classList.add('is-active');const filter=btn.dataset.sessionFilter;document.querySelectorAll('.ss-session').forEach(card=>{card.hidden=filter!=='all'&&card.dataset.status!==filter;});}));
  document.querySelectorAll('.live-add-to-cart-form,#liveBuyNowForm').forEach(form=>form.addEventListener('submit',async e=>{e.preventDefault();const {data}=await pageJson(form.action,{method:'POST',body:new FormData(form)});if(!data.ok){alert(data.message||'Không thể thêm sản phẩm');return;}const button=form.querySelector('button');if(button){const old=button.innerHTML;button.innerHTML='<i class="fa-solid fa-check"></i> Đã thêm';setTimeout(()=>button.innerHTML=old,1400);}}));
  let pinnedPresent=!!$('livePinnedProductId');
  async function refreshPinned(){if(!liveId)return;const {data}=await pageJson(url('api_live_products')+'&id='+encodeURIComponent(liveId));if(!data.ok||!data.data)return;const p=data.data.pinned_product||null;if(Boolean(p)!==pinnedPresent){window.location.reload();return;}if(!p)return;const name=$('livePinnedName'),brand=$('livePinnedBrand'),price=$('livePinnedPrice'),id=$('livePinnedProductId'),img=$('livePinnedImg');if(name)name.textContent=p.ten_san_pham||'';if(brand)brand.textContent=p.thuong_hieu||'';if(price)price.textContent=new Intl.NumberFormat('vi-VN').format(data.data.gia_uu_dai_live||0)+' đ';if(id)id.value=p.ma_san_pham||'';if(img&&img.tagName==='IMG'&&p.link_hinh_anh)img.src=p.link_hinh_anh;}
  setInterval(refreshPinned,5000);
  window.addEventListener('beforeunload',()=>{if(isStudio&&streamState==='live'){const fd=new FormData();fd.append('live_id',liveId);fd.append('state','interrupted');navigator.sendBeacon(url('api_live_stream_state'),fd);}});
})();
</script>
<?php endif; ?>

<?php require_once __DIR__ . '/layouts/footer.php'; ?>
