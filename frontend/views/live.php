<?php
$pageTitle = 'SkinSyntax Live Commerce';
require_once __DIR__ . '/layouts/header.php';

$role = current_role();
$isStaff = in_array($role, ['admin', 'nhanvien'], true);
$hasLive = is_array($currentLive ?? null) && !empty($currentLive['id']);
$live = $hasLive ? $currentLive : [];
$permissions = $live['live_permissions'] ?? [];
$isStudio = $isStaff && (string)($_GET['studio'] ?? '') === '1';
$canPublish = $isStudio && !empty($permissions['publish']);
$canModerate = !empty($permissions['moderate']);
$canPin = !empty($permissions['pin']);
if ($hasLive || !empty($aiIdolLive)) $_SESSION['live_csrf'] ??= bin2hex(random_bytes(24));
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
  .ss-live-page [hidden]{display:none!important}
  .ss-message__text{white-space:pre-wrap;overflow-wrap:anywhere}
  .ss-live-mention{color:#075d44;font-weight:700}
  .ss-live-syna-avatar{width:1.45rem;height:1.45rem;border-radius:50%;display:inline-grid;place-items:center;background:#15704a;color:#fff;font-weight:700}
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
  .ss-player__top{top:0;background:linear-gradient(180deg,rgba(0,0,0,.7),transparent)}.ss-player__bottom{bottom:0;background:linear-gradient(0deg,rgba(0,0,0,.82),transparent);padding-top:42px;pointer-events:none}
  .ss-player--replay .ss-player__top{background:none;padding:10px;pointer-events:none}.ss-player--replay .ss-player__top>*{pointer-events:auto}.ss-player--replay .ss-player__top .ss-badge{background:rgba(15,23,42,.55);color:#fff}.ss-player--replay .ss-player__top{flex-wrap:nowrap}.ss-player--replay .ss-player__actions{width:auto;margin-left:auto}
  .ss-replay-meta{display:flex;align-items:center;gap:10px;margin-top:10px;color:#1f2a24}.ss-replay-meta__text{min-width:0}.ss-replay-meta strong{display:block;font-size:14px}.ss-replay-meta small{display:block;color:#5f6b64;font-size:12px;margin-top:2px;overflow-wrap:anywhere}
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

<main class="ss-live-page" id="ssLivePage" data-live-id="<?= h($live['id'] ?? '') ?>" data-live-status="<?= h($liveStatus) ?>" data-is-studio="<?= $canPublish ? '1' : '0' ?>" data-moderate="<?= $canModerate ? '1' : '0' ?>" data-ai-enabled="<?= !empty($live['bat_ai_cohost']) ? '1' : '0' ?>" data-csrf="<?= h($_SESSION['live_csrf'] ?? '') ?>" data-base-url="<?= h(BASE_URL) ?>">
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
  <?php if (!empty($aiIdolLive)): ?>
    <?php
      $aiAsset = static fn(string $rel): string => BASE_URL . '/assets/' . $rel . '?v=' . (@filemtime(dirname(__DIR__) . '/public/assets/' . $rel) ?: '1');
    ?>
    <link rel="stylesheet" href="<?= h($aiAsset('css/ai-idol-live.css')) ?>">
    <section id="aiIdolLive" class="ai-idol-live"
             data-campaign-id="<?= h($aiIdolLive['id']) ?>"
             data-csrf="<?= h($_SESSION['live_csrf']) ?>"
             data-started-at="<?= h((string)(int)$aiIdolLive['started_at_ts']) ?>"
             data-ends-at="<?= h((string)(int)$aiIdolLive['ends_at_ts']) ?>"
             data-duration="<?= h((string)(float)$aiIdolLive['duration_seconds']) ?>">
      <div class="ai-idol-live__stage">
        <video id="aiIdolLiveVideo" class="ai-idol-live__video" muted autoplay playsinline loop preload="auto"
               src="<?= h(BASE_URL . '/index.php?r=ai_idol_live_video&id=' . rawurlencode((string)$aiIdolLive['id'])) ?>"></video>
        <span class="ai-idol-live__badge">LIVE</span>
        <span class="ai-idol-live__name"><?= h($aiIdolLive['name']) ?></span>
        <div class="ai-idol-live__controls">
          <button type="button" id="aiIdolFullscreenBtn" class="ai-idol-live__mute" hidden>Phóng to</button>
          <button type="button" id="aiIdolMuteBtn" class="ai-idol-live__mute">Bật tiếng</button>
        </div>
        <button type="button" id="aiIdolPlayOverlay" class="ai-idol-live__overlay" hidden>Nhấn để xem</button>
        <div id="aiIdolEndedOverlay" class="ai-idol-live__overlay" hidden>Buổi live đã kết thúc</div>
      </div>
      <?php if (!empty($aiIdolProducts)): ?>
        <div class="ai-idol-live__products">
          <?php foreach ($aiIdolProducts as $aiP): ?>
            <?php
              $aiPid = (string)($aiP['ma_san_pham'] ?? '');
              $aiImg = !empty($aiP['link_hinh_anh']) ? resolve_image_url((string)$aiP['link_hinh_anh']) : '';
              $aiHref = BASE_URL . '/index.php?r=chitiet&id=' . rawurlencode($aiPid);
              $aiNum = (int)(($aiIdolLive['numbers'] ?? [])[$aiPid] ?? 0);
            ?>
            <div class="ai-idol-live__card">
              <a class="ai-idol-live__img" href="<?= h($aiHref) ?>">
                <?php if ($aiNum > 0): ?><span class="ai-idol-live__num">Số <?= h((string)$aiNum) ?></span><?php endif; ?>
                <?php if ($aiImg !== ''): ?><img src="<?= h($aiImg) ?>" alt="<?= h((string)($aiP['ten_san_pham'] ?? '')) ?>" loading="lazy"><?php endif; ?>
              </a>
              <a class="ai-idol-live__pname" href="<?= h($aiHref) ?>"><?= h((string)($aiP['ten_san_pham'] ?? '')) ?></a>
              <div class="ai-idol-live__price"><?= h(number_format((float)($aiP['gia_ban'] ?? 0), 0, ',', '.')) ?>đ</div>
              <button type="button" class="ai-idol-live__add" data-product-id="<?= h($aiPid) ?>">Thêm vào giỏ</button>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </section>
    <script src="<?= h($aiAsset('js/ai-idol-live.js')) ?>" defer></script>
  <?php endif; ?>

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
          <?php $isReplayView = ($liveStatus === 'replay_ready' && $recordingUrl !== ''); ?>
          <div class="ss-player<?= $isReplayView ? ' ss-player--replay' : '' ?>" id="ssPlayer">
            <video id="livekitRemoteVideo" autoplay playsinline muted hidden></video>
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
              <span class="ss-badge ss-badge--neutral" id="liveViewers" <?= $isLive ? '' : 'hidden' ?>><i class="fa-solid fa-eye"></i> <span id="liveViewerCount">0</span> đang xem</span>
              <span class="ss-badge ss-badge--neutral" id="liveSourceBadge" hidden></span>
              <?php if (!$isReplayView): ?><span class="ss-badge ss-badge--neutral" id="liveConnectionBadge"><i class="fa-solid fa-circle-notch fa-spin"></i> Đang chuẩn bị phát</span><?php endif; ?>
              <div class="ss-player__actions">
                <?php if ($isLive): ?><button type="button" id="btnReaction"><i class="fa-solid fa-heart"></i> <span id="reactionCount"><?= (int)($live['reaction_count'] ?? 0) ?></span></button><?php endif; ?>
                <button type="button" id="btnShareLive"><i class="fa-solid fa-share-nodes"></i> Chia sẻ</button>
                <button type="button" id="btnEnableLiveSound" hidden>Bật tiếng</button>
              </div>
            </div>
            <?php if (!$isReplayView): ?>
            <div class="ss-player__bottom">
              <div class="ss-streamer"><div class="ss-avatar"><?= h(mb_strtoupper(mb_substr($displayStreamer, 0, 2))) ?></div><div><strong><?= h($displayStreamer) ?></strong><small><?= h($live['title'] ?? '') ?></small></div></div>
            </div>
            <?php endif; ?>
          </div>
          <?php if ($isStudio && !$isReplayView): ?>
          <section class="ss-card ss-products mt-3" aria-label="Live Studio">
            <h2>Live Studio</h2>
            <nav class="d-flex gap-2 flex-wrap mb-2" aria-label="Điều khiển Live Studio">
              <?php if ($canPublish): ?><button type="button" class="btn btn-sm btn-light" data-studio-tab="liveSourcePanel">Nguồn phát</button><?php endif; ?>
              <button type="button" class="btn btn-sm btn-light" data-studio-tab="liveChatBox">Trò chuyện</button>
              <?php if ($canPin): ?><button type="button" class="btn btn-sm btn-light" data-studio-tab="liveProductPanel">Sản phẩm</button><?php endif; ?>
              <?php if ($role === 'admin'): ?><button type="button" class="btn btn-sm btn-light" data-studio-tab="liveSynaPanel">SYNA AI</button><?php endif; ?>
              <?php if ($canPublish): ?>
                <button type="button" class="btn btn-sm btn-light" data-studio-tab="liveLibraryPanel">Thư viện video</button>
                <button type="button" class="btn btn-sm btn-light" data-studio-tab="liveRecordingPanel">Bản ghi</button>
              <?php endif; ?>
            </nav>
            <?php if ($canPublish && in_array($liveStatus, ['scheduled', 'ready', 'live', 'interrupted'], true)): ?>
              <details id="liveSourcePanel" open><summary>Nguồn phát</summary>
                <div class="d-flex gap-2 flex-wrap my-2">
                  <label>Camera <select id="liveCameraDevice"><option value="">Mặc định</option></select></label>
                  <label>Micrô <select id="liveMicDevice"><option value="">Mặc định</option></select></label>
                </div>
                <div class="d-flex gap-2 flex-wrap my-2">
                  <button class="ss-btn ss-btn--ghost" type="button" id="livePreviewCamera">Xem trước camera</button>
                  <button class="ss-btn ss-btn--ghost" type="button" data-live-source="camera">Camera</button>
                  <button class="ss-btn ss-btn--ghost" type="button" data-live-source="screen">Chia sẻ màn hình</button>
                  <button class="ss-btn ss-btn--ghost" type="button" id="btnToggleMic" disabled>Bật micrô</button>
                  <button class="ss-btn ss-btn--ghost" type="button" id="btnMuteOutput" disabled>Tắt tiếng luồng</button>
                  <button class="ss-btn ss-btn--ghost" type="button" id="btnStopLive" disabled>Dừng nguồn</button>
                  <button class="ss-btn ss-btn--ghost text-danger" type="button" id="btnEndLive" disabled>Kết thúc và lưu bản ghi</button>
                </div>
                <p id="liveMediaStatus" role="status"></p>
              </details>
              <details id="liveLibraryPanel" open><summary>Thư viện video</summary>
                <label class="d-block my-2" for="liveVideoFiles">Chọn MP4 từ máy để phát</label>
                <input id="liveVideoFiles" type="file" accept="video/mp4,.mp4" multiple>
                <select id="liveVideoQueue" aria-label="Video trong phiên" class="form-select my-2"></select>
                <div class="d-flex gap-2 flex-wrap align-items-center">
                  <label><input type="checkbox" id="liveVideoLoop"> Lặp video</label>
                  <button class="ss-btn ss-btn--ghost" type="button" id="livePreviewVideo">Xem trước MP4</button>
                  <button class="ss-btn ss-btn--ghost" type="button" data-live-source="video">Phát video</button>
                  <button class="ss-btn ss-btn--ghost" type="button" data-live-source="ai_video">Phát video AI dựng sẵn</button>
                </div>
                <form id="liveLibraryUpload" class="my-2">
                  <label class="d-block">Lưu MP4 vào phiên (tối đa 18 MB)
                    <input name="source_video" type="file" accept="video/mp4,.mp4" required>
                  </label>
                  <label><input name="ai_video" type="checkbox" value="1"> Video SYNA đã dựng</label>
                  <button class="ss-btn ss-btn--ghost">Tải lên</button>
                </form>
                <label class="d-block" for="liveLibraryFiles">Video đã lưu và bản dựng SYNA</label>
                <select id="liveLibraryFiles" class="form-select my-2">
                  <?php foreach (($live['studio_videos'] ?? []) as $video): ?>
                    <option value="<?= h($video['url'] ?? '') ?>" data-source="<?= h($video['source'] ?? 'video') ?>"><?= h($video['name'] ?? '') ?></option>
                  <?php endforeach; ?>
                  <?php foreach (($aiReplaySessions ?? []) as $video): ?>
                    <option value="<?= h($video['url_ban_ghi']) ?>" data-source="ai_video"><?= h('SYNA · ' . $video['title']) ?></option>
                  <?php endforeach; ?>
                </select>
                <button class="ss-btn ss-btn--ghost" type="button" id="livePlayLibrary">Phát video đã chọn</button>
                <button class="ss-btn ss-btn--ghost" type="button" id="livePreviewLibrary">Xem trước video đã chọn</button>
              </details>
              <details id="liveRecordingPanel" class="mt-2"><summary>Bản ghi</summary>
                <p id="liveRecordingState" class="mb-2" role="status">Bản ghi bắt đầu khi phát thành công.</p>
                <a id="liveRecordingLink" class="ss-btn ss-btn--ghost" hidden>Xem bản ghi</a>
              </details>
            <?php endif; ?>
            <?php if ($canPin): ?>
              <details id="liveProductPanel" class="mt-2"><summary>Sản phẩm</summary>
                <form id="livePinForm" class="d-flex gap-2 flex-wrap my-2">
                  <input name="product_id" placeholder="Mã SP để ghim" aria-label="Mã sản phẩm" required>
                  <input name="so_luong_kho_deal" type="number" min="1" placeholder="Số lượng mở bán" aria-label="Số lượng mở bán" required>
                  <input name="gia_uu_dai_live" type="number" min="0" placeholder="Giá ưu đãi" aria-label="Giá ưu đãi">
                  <button class="ss-btn ss-btn--ghost" value="product_pin">Ghim sản phẩm</button>
                  <button class="ss-btn ss-btn--ghost" value="deal_add">Thêm vào phiên</button>
                  <button class="ss-btn ss-btn--ghost" type="button" id="liveUnpinProduct">Bỏ ghim</button>
                </form>
                <div id="liveStudioDeals"></div>
              </details>
            <?php endif; ?>
            <?php if ($role === 'admin'): ?>
              <details id="liveSynaPanel" class="mt-2"><summary>SYNA AI</summary>
                <label class="my-2"><input type="checkbox" id="liveToggleSyna" <?= !empty($live['bat_ai_cohost']) ? 'checked' : '' ?>> Bật trả lời khi được gọi bằng @syna</label>
                <p id="liveSynaQueue" class="mb-2" role="status"></p>
              </details>
              <details class="mt-2"><summary>Phân công nhân viên</summary>
                <form id="liveStaffForm" class="my-2">
                  <label>Mã streamer <input name="publisher" value="<?= h($live['publisher_staff_id'] ?? '') ?>"></label>
                  <label>Mã nhân viên <input name="staff"></label>
                  <label><input type="checkbox" name="pin" value="1"> Quản lý sản phẩm</label>
                  <label><input type="checkbox" name="moderate" value="1"> Quản lý chat</label>
                  <button class="ss-btn ss-btn--ghost">Lưu phân công</button>
                </form>
              </details>
            <?php endif; ?>
            <p id="liveStudioStatus" class="mb-0" role="status"></p>
          </section>
          <?php endif; ?>
          <?php if ($isReplayView): ?>
          <div class="ss-replay-meta"><div class="ss-avatar"><?= h(mb_strtoupper(mb_substr($displayStreamer, 0, 2))) ?></div><div class="ss-replay-meta__text"><strong><?= h($displayStreamer) ?></strong><small><?= h($live['title'] ?? '') ?></small></div></div>
          <?php endif; ?>

          <section class="ss-card ss-product" aria-label="Sản phẩm đang giới thiệu trong phiên phát">
            <div class="ss-product__head"><span class="ss-kicker"><i class="fa-solid fa-thumbtack"></i> Sản phẩm đang giới thiệu</span><span class="ss-badge ss-badge--neutral" id="flashDealTimer">Ưu đãi của phiên phát</span></div>
              <div class="ss-product__body" id="livePinnedBody" <?= $pinned ? '' : 'hidden' ?>>
                <img id="livePinnedImg" class="ss-product__image" <?= $pinnedImage !== '' ? 'src="' . h($pinnedImage) . '"' : 'hidden' ?> alt="<?= h($pinnedName) ?>">
                <div class="ss-product__info"><div id="livePinnedBrand" class="ss-product__brand"><?= h($pinnedBrand) ?></div><div id="livePinnedName" class="ss-product__name"><?= h($pinnedName) ?></div><div><span id="livePinnedPrice" class="ss-price"><?= vnd($pinnedPrice) ?></span><span id="livePinnedMarketPrice" class="ss-old-price" <?= $discount ? '' : 'hidden' ?>><?= vnd($marketPrice) ?></span><span id="livePinnedDiscountBadge" class="ss-badge ss-badge--danger ms-2" <?= $discount ? '' : 'hidden' ?>>-<?= $discount ?>%</span></div></div>
                <div class="ss-product__cta">
                  <form id="liveBuyNowForm" action="<?= BASE_URL ?>/index.php?r=api_live_add_to_cart" method="post"><input type="hidden" name="live_id" value="<?= h($live['id']) ?>"><input type="hidden" name="product_id" id="livePinnedProductId" value="<?= h($pinnedId) ?>"><button class="ss-btn ss-btn--primary" type="submit" <?= $isLive ? '' : 'disabled' ?>><i class="fa-solid fa-bag-shopping"></i> Thêm vào giỏ</button></form>
                  <a class="ss-btn ss-btn--ghost" id="livePinnedLink" href="<?= BASE_URL ?>/index.php?r=chitiet&amp;id=<?= urlencode($pinnedId) ?>">Chi tiết</a>
                </div>
              </div>
            <div class="ss-empty-product" id="livePinnedEmpty" <?= $pinned ? 'hidden' : '' ?>>Phiên này chưa có sản phẩm đang giới thiệu.</div>
          </section>

          <section class="ss-card ss-products" id="liveDeals" hidden>
            <h2>Danh sách ưu đãi trong phiên</h2><div class="ss-product-list" id="liveDealsList"></div>
          </section>
        </section>

        <aside class="ss-card ss-chat" aria-label="Trò chuyện trực tiếp">
          <div class="ss-chat__head"><div class="ss-chat__title"><h2><i class="fa-solid fa-comments text-success me-2"></i>Trò chuyện trực tiếp</h2><span class="ss-badge ss-badge--<?= $isLive ? 'ready' : 'neutral' ?>" id="chatConnectionState"><?= $isLive ? 'Đang mở' : 'Chưa mở' ?></span></div><div class="ss-chat__tabs" role="tablist"><button type="button" class="is-active" data-chat-mode="all">Tất cả</button><button type="button" data-chat-mode="featured">Nổi bật</button><button type="button" data-chat-mode="qa">Hỏi đáp</button></div></div>
          <div class="ss-chat__body" id="liveChatBox"><div class="ss-chat__notice">Tin nhắn trong phiên sẽ được lưu đúng theo buổi phát.</div></div>
          <div class="ss-chat__footer">
            <div id="liveChatCompose" <?= $canChat ? '' : 'hidden' ?>>
              <?php if (!$isStaff && !is_logged_in()): ?><input id="guestNickname" type="text" maxlength="40" placeholder="Tên hiển thị (không bắt buộc)" class="mb-2"><?php endif; ?>
              <div id="liveChatReply" hidden><span></span> <button type="button" class="btn btn-link btn-sm">Bỏ trả lời</button></div>
              <button type="button" id="liveSynaSuggestion" class="btn btn-link btn-sm" hidden>@syna — Hỏi trợ lý AI</button>
              <form id="liveChatForm" class="ss-chat__row"><input id="liveChatInput" type="text" maxlength="1000" placeholder="Nhắn cho mọi người…" required><button class="ss-btn ss-btn--primary" type="submit" aria-label="Gửi"><i class="fa-solid fa-paper-plane"></i></button></form>
              <button type="button" id="liveAskSyna" class="btn btn-link btn-sm">Hỏi SYNA</button>
              <p id="liveChatNotice" class="mb-1" role="status"></p>
              <p id="liveSynaNotice" class="mb-1" role="status"></p>
              <div class="ss-chat__helper">Tối đa 5 tin trong 20 giây. Không chia sẻ thông tin nhạy cảm.</div>
            </div>
            <div id="liveChatLocked" class="ss-chat__locked" <?= $canChat ? 'hidden' : '' ?>><i class="fa-solid fa-lock me-1"></i> Trò chuyện mở trong lúc phát trực tiếp.</div>
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
<?php foreach (['live-studio-media.js', 'live-commerce.js'] as $file): ?>
<script src="<?= h(BASE_URL . '/assets/js/' . $file . '?v=' . filemtime(dirname(__DIR__) . '/public/assets/js/' . $file)) ?>" defer></script>
<?php endforeach; ?>
<?php endif; ?>

<?php require_once __DIR__ . '/layouts/footer.php'; ?>
