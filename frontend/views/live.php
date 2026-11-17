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
  .ss-preview-tag{position:absolute;z-index:6;left:16px;top:62px;display:inline-flex;align-items:center;gap:6px;background:rgba(10,23,17,.78);color:#fff;border:1px solid rgba(255,255,255,.3);border-radius:999px;padding:5px 11px;font-size:12px;font-weight:800;letter-spacing:.04em}.ss-preview-tag[hidden]{display:none}.ss-preview-tag i{font-size:8px;color:#f5c542}.ss-preview-tag.is-live{background:#e23b4e;border-color:#e23b4e}.ss-preview-tag.is-live i{color:#fff}
  .ss-studio{margin-top:14px;padding:16px;border-color:#1c3c2a}.ss-studio__head{font-size:15px;font-weight:800;color:#183B2B;margin-bottom:12px}.ss-studio__head i{color:#2D6A4F;margin-right:6px}
  .ss-studio__devices{display:grid;grid-template-columns:1fr 1fr;gap:12px}
  .ss-device{display:flex;align-items:center;gap:12px;padding:12px;border:1px solid var(--ss-line);border-radius:14px;background:var(--ss-soft);min-width:0}
  .ss-device__icon{flex:0 0 auto;width:42px;height:42px;border-radius:50%;display:grid;place-items:center;background:#d9efe2;color:#183B2B;font-size:17px}
  .ss-device__info{flex:1 1 auto;min-width:0;display:flex;flex-direction:column;gap:3px}.ss-device__info strong{font-size:14px}
  .ss-device__state{font-size:12px;color:var(--ss-muted);line-height:1.4;overflow-wrap:anywhere}.ss-device__state.is-on{color:#2D6A4F;font-weight:700}.ss-device__state.is-error{color:#c3344d;font-weight:600}
  .ss-device__btn{flex:0 0 auto;min-height:40px;padding:8px 14px;border-radius:999px;border:1px solid #2D6A4F;background:#fff;color:#183B2B;font-size:13px;font-weight:700;cursor:pointer}.ss-device__btn:hover:not(:disabled){background:#e8f4ec}.ss-device__btn.is-on{background:#183B2B;color:#fff}.ss-device__btn.is-on:hover:not(:disabled){background:#2D6A4F;color:#fff}.ss-studio__autherr{margin:0 0 12px;padding:10px 12px;border-radius:10px;background:#fdecec;color:#9b1c1c;font-size:13px;font-weight:600}.ss-studio__autherr a{color:#9b1c1c;text-decoration:underline;margin-left:4px}.ss-device__btn:disabled{opacity:.6;cursor:not-allowed}
  .ss-meter{height:6px;border-radius:999px;background:#dfe8e1;overflow:hidden;margin-top:4px}.ss-meter__bar{height:100%;width:0;background:linear-gradient(90deg,#2D6A4F,#7bd39a);transition:width .08s linear}
  .ss-studio__go{margin-top:14px;padding-top:14px;border-top:1px solid var(--ss-line)}
  .ss-studio__pre,.ss-studio__live{display:flex;flex-direction:column;gap:8px}.ss-studio__pre[hidden],.ss-studio__live[hidden]{display:none}
  .ss-studio__golive{min-height:48px;border:0;border-radius:12px;background:#2D6A4F;color:#fff;font-size:16px;font-weight:800;padding:10px 20px;cursor:pointer}.ss-studio__golive:hover:not(:disabled){background:#183B2B}.ss-studio__golive:disabled{background:#b9c8bf;cursor:not-allowed}
  .ss-studio__help{margin:0;font-size:12px;color:var(--ss-muted);line-height:1.5}.ss-studio__error{margin:0;font-size:13px;color:#c3344d;font-weight:600}.ss-studio__error[hidden]{display:none}
  .ss-studio__live{flex-direction:row;align-items:center;justify-content:space-between;flex-wrap:wrap}.ss-studio__onair{display:inline-flex;align-items:center;gap:8px;font-weight:800;color:#c3344d;font-size:16px}.ss-studio__onair i{font-size:10px}.ss-studio__onair span{font-variant-numeric:tabular-nums;color:var(--ss-ink)}
  .ss-studio__end{min-height:44px;border:1px solid #e23b4e;background:#fff;color:#c3344d;border-radius:12px;font-weight:700;padding:8px 18px;cursor:pointer}.ss-studio__end:hover:not(:disabled){background:#fdecef}.ss-studio__end:disabled{opacity:.6}
  .ss-device__btn:focus-visible,.ss-studio__golive:focus-visible,.ss-studio__end:focus-visible{outline:3px solid #7bd39a;outline-offset:2px}
  @media (max-width:576px){.ss-studio__devices{grid-template-columns:1fr}.ss-studio__golive,.ss-studio__end{width:100%}.ss-studio__live{flex-direction:column;align-items:stretch}.ss-device{flex-wrap:wrap}.ss-device__btn{width:100%}.ss-preview-tag{left:12px;top:auto;bottom:70px}}
  .ss-camoff{position:absolute;inset:0;z-index:3;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:10px;background:#0a1711;color:#cfe6d8;font-weight:700;font-size:15px;text-align:center;padding:16px}.ss-camoff[hidden]{display:none}.ss-camoff i{font-size:28px;opacity:.8}
  .ss-player__chip{position:absolute;z-index:6;left:16px;bottom:72px;display:inline-flex;align-items:center;gap:7px;max-width:calc(100% - 32px);background:#fff4d6;color:#7a4a00;border:1px solid #b7791f;border-radius:999px;padding:6px 12px;font-size:13px;font-weight:800;line-height:1.35;box-shadow:0 4px 14px rgba(0,0,0,.35)}.ss-player__chip[hidden]{display:none}
  .ss-preview-tag.is-paused{background:#b7791f;border-color:#b7791f}.ss-preview-tag.is-paused i{color:#fff}
  .ss-studio__status{display:flex;flex-direction:column;align-items:flex-start;gap:6px}.ss-studio__onair.is-paused{color:var(--ss-muted);font-size:13px}
  .ss-studio__paused{display:inline-flex;align-items:center;gap:8px;font-weight:800;color:#b7791f;background:#fff4d6;border:1px solid #e8c880;border-radius:999px;padding:4px 12px;font-size:15px}.ss-studio__paused[hidden]{display:none}.ss-studio__paused span{font-variant-numeric:tabular-nums}
  .ss-studio__actions{display:flex;gap:10px;flex-wrap:wrap}
  .ss-studio__pause{min-height:44px;border:1px solid #b7791f;background:#fff4d6;color:#7a4a00;border-radius:12px;font-weight:700;padding:8px 18px;cursor:pointer}.ss-studio__pause:hover:not(:disabled){background:#ffe9b0}.ss-studio__pause:disabled{opacity:.6;cursor:not-allowed}.ss-studio__pause.is-resume{background:#2D6A4F;border-color:#2D6A4F;color:#fff}.ss-studio__pause.is-resume:hover:not(:disabled){background:#183B2B}.ss-studio__pause:focus-visible{outline:3px solid #7bd39a;outline-offset:2px}
  @media (max-width:576px){.ss-studio__actions{flex-direction:column}.ss-studio__pause,.ss-studio__end{width:100%}.ss-player__chip{left:12px;bottom:64px;font-size:12px}}
  @media (max-width:1050px){.ss-live-grid{grid-template-columns:minmax(0,1fr)}.ss-chat{position:static}.ss-chat__body{height:330px}.ss-session-grid{grid-template-columns:repeat(2,minmax(0,1fr))}}
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
          <?php if ($isStudio): ?><div class="mt-3 small text-success fw-semibold"><i class="fa-solid fa-circle-info me-1"></i>Bật camera và micro ở bảng điều khiển bên dưới video, kiểm tra khung xem trước, rồi bấm Bắt đầu phát.</div><?php endif; ?>
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
            <video id="livekitRemoteVideo" autoplay playsinline <?= $liveStatus === 'replay_ready' ? 'hidden' : '' ?>></video>
            <video id="livekitLocalVideo" autoplay playsinline muted hidden></video>
            <audio id="livekitRemoteAudio" autoplay></audio>
            <?php if ($liveStatus === 'replay_ready' && $recordingUrl !== ''): ?>
              <video id="replayVideoPlayer" controls playsinline preload="metadata" src="<?= h($recordingUrl) ?>"></video>
            <?php endif; ?>
            <div class="ss-player__waiting" id="liveWaitingOverlay" <?= ($isLive && $liveStatus !== 'replay_ready') ? '' : ($liveStatus === 'replay_ready' && $recordingUrl !== '' ? 'hidden' : '') ?>>
              <div class="ss-player__waiting-inner">
                <div class="ss-player__waiting-icon"><i class="fa-solid <?= $liveStatus === 'ready' ? 'fa-video' : ($liveStatus === 'scheduled' ? 'fa-calendar-days' : 'fa-video') ?>"></i></div>
                <h2 class="h5 fw-bold" id="liveWaitingTitle"><?= h($statusLabel) ?></h2>
                <p class="mb-0 opacity-75" id="liveWaitingMessage">
                  <?php if ($liveStatus === 'live'): ?>Đang chờ hình ảnh từ người phát.<?php elseif ($liveStatus === 'ready'): ?><?= $isStudio ? 'Bạn có thể bắt đầu sau khi cho phép sử dụng hình ảnh và âm thanh.' : 'Người dẫn sẽ bắt đầu trong ít phút. Trang sẽ tự cập nhật khi phiên bắt đầu.' ?><?php elseif ($liveStatus === 'interrupted' && !$isStudio): ?>Người dẫn đang kết nối lại. Trang sẽ tự cập nhật.<?php elseif ($liveStatus === 'scheduled'): ?>Phiên chưa đến thời gian phát sóng.<?php elseif ($liveStatus === 'replay_ready'): ?>Chưa có video xem lại.<?php else: ?>Phiên này chưa có video để xem.<?php endif; ?>
                </p>
              </div>
            </div>
            <?php if ($isStudio): ?><div class="ss-camoff" id="camOffOverlay" hidden><i class="fa-solid fa-video-slash" id="camOffIcon"></i><span id="camOffText">Camera đang tắt</span></div><span class="ss-player__chip" id="micOffChip" role="status" aria-live="polite" hidden><i class="fa-solid fa-microphone-slash"></i> Micro đang tắt — người xem không nghe thấy bạn</span><?php elseif (!$isReplayView): ?><span class="ss-player__chip" id="hostMicChip" role="status" aria-live="polite" hidden><i class="fa-solid fa-microphone-slash"></i> Người dẫn đang tắt micro</span><?php endif; ?>
            <?php if ($isStudio): ?><span class="ss-preview-tag" id="previewTag" hidden><i class="fa-solid fa-circle"></i> <span id="previewTagText">XEM TRƯỚC · Chưa phát</span></span><?php endif; ?>
            <div class="ss-player__top">
              <span class="ss-badge ss-badge--<?= h($statusTone) ?>" id="liveStatusBadge"><i class="fa-solid fa-circle"></i> <span id="liveStatusLabel"><?= h($statusLabel) ?></span></span>
              <?php if ($isLive): ?><span class="ss-badge ss-badge--neutral"><i class="fa-solid fa-eye"></i> <span id="liveViewerCount">0</span> đang xem</span><?php endif; ?>
              <?php if (!$isReplayView): ?><span class="ss-badge ss-badge--neutral" id="liveConnectionBadge"><i class="fa-solid fa-circle-notch fa-spin"></i> Đang chuẩn bị phát</span><?php endif; ?>
              <div class="ss-player__actions">
                <?php if ($isLive): ?><button type="button" id="btnReaction"><i class="fa-solid fa-heart"></i> <span id="reactionCount"><?= (int)($live['reaction_count'] ?? 0) ?></span></button><?php endif; ?>
                <button type="button" id="btnShareLive"><i class="fa-solid fa-share-nodes"></i> Chia sẻ</button>
                <?php if ($isStaff && !$isStudio && in_array($liveStatus, ['ready', 'live', 'interrupted'], true)): ?>
                  <a href="<?= BASE_URL ?>/index.php?r=live&amp;id=<?= h(urlencode((string)($live['id'] ?? ''))) ?>&amp;studio=1"><i class="fa-solid fa-video"></i> Vào phòng phát</a>
                <?php endif; ?>
              </div>
            </div>
            <?php if (!$isReplayView): ?>
            <div class="ss-player__bottom">
              <div class="ss-streamer"><div class="ss-avatar"><?= h(mb_strtoupper(mb_substr($displayStreamer, 0, 2))) ?></div><div><strong><?= h($displayStreamer) ?></strong><small><?= h($live['title'] ?? '') ?></small></div></div>
            </div>
            <?php endif; ?>
          </div>
          <?php if ($isReplayView): ?>
          <div class="ss-replay-meta"><div class="ss-avatar"><?= h(mb_strtoupper(mb_substr($displayStreamer, 0, 2))) ?></div><div class="ss-replay-meta__text"><strong><?= h($displayStreamer) ?></strong><small><?= h($live['title'] ?? '') ?></small></div></div>
          <?php endif; ?>

          <?php if ($isStudio && in_array($liveStatus, ['ready', 'live', 'interrupted'], true)): ?>
          <style>/* The host does not need the site-wide "top sale" toast; on phones it covers the preview. */#topSalesToast{display:none!important}</style>
          <section class="ss-card ss-studio" id="studioPanel" aria-label="Bảng điều khiển phát sóng">
            <div class="ss-studio__head"><i class="fa-solid fa-sliders"></i> Bảng điều khiển phát sóng</div>
            <p class="ss-studio__autherr" id="studioAuthError" role="alert" hidden></p>
            <div class="ss-studio__devices">
              <div class="ss-device" id="tileCam">
                <div class="ss-device__icon"><i class="fa-solid fa-video"></i></div>
                <div class="ss-device__info"><strong>Camera</strong><span class="ss-device__state" id="camState" aria-live="polite">Đang tắt</span></div>
                <button type="button" class="ss-device__btn" id="btnToggleCam">Bật camera</button>
              </div>
              <div class="ss-device" id="tileMic">
                <div class="ss-device__icon"><i class="fa-solid fa-microphone"></i></div>
                <div class="ss-device__info"><strong>Micro</strong><span class="ss-device__state" id="micState" aria-live="polite">Đang tắt</span>
                  <div class="ss-meter" aria-hidden="true"><div class="ss-meter__bar" id="micMeterBar"></div></div></div>
                <button type="button" class="ss-device__btn" id="btnToggleMic">Bật micro</button>
              </div>
            </div>
            <div class="ss-studio__go">
              <div class="ss-studio__pre" id="studioGoPre" <?= $isLive ? 'hidden' : '' ?>>
                <button type="button" class="ss-studio__golive" id="btnGoLive" disabled><i class="fa-solid fa-video"></i> Bắt đầu phát</button>
                <p class="ss-studio__help" id="studioHelp">Bật camera và micro, kiểm tra khung xem trước rồi bấm Bắt đầu phát.</p>
                <p class="ss-studio__error" id="studioGoError" role="alert" hidden></p>
              </div>
              <div class="ss-studio__live" id="studioGoLive" <?= $isLive ? '' : 'hidden' ?>>
                <div class="ss-studio__status">
                  <span class="ss-studio__onair" id="studioOnAir"><i class="fa-solid fa-circle"></i> <b id="studioStateLabel" aria-live="polite">Đang phát</b> <span id="studioTimer" aria-live="off">00:00</span></span>
                  <span class="ss-studio__paused" id="studioPausedInfo" aria-live="polite" hidden><i class="fa-solid fa-pause"></i> Tạm dừng <span id="studioPauseTimer" aria-live="off">00:00</span></span>
                </div>
                <div class="ss-studio__actions">
                  <button type="button" id="btnPauseLive" class="ss-studio__pause" aria-pressed="false"><i class="fa-solid fa-pause"></i> Tạm dừng</button>
                  <button type="button" id="btnEndLive" class="ss-studio__end"><i class="fa-solid fa-stop"></i> Kết thúc phiên phát</button>
                </div>
              </div>
            </div>
          </section>
          <?php endif; ?>

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
            <?php if ($canChat || $isStudio): ?>
              <?php /* The studio renders the composer before going live (hidden) so the host can chat without reloading, which would drop the broadcast. */ ?>
              <div id="liveChatComposer" <?= $canChat ? '' : 'hidden' ?>>
              <?php if (!$isStaff && !is_logged_in()): ?><input id="guestNickname" type="text" maxlength="40" placeholder="Tên hiển thị (không bắt buộc)" class="mb-2"><?php endif; ?>
              <form id="liveChatForm" class="ss-chat__row"><input id="liveChatInput" type="text" maxlength="1000" placeholder="Đặt câu hỏi về sản phẩm..." required><button class="ss-btn ss-btn--primary" type="submit" aria-label="Gửi"><i class="fa-solid fa-paper-plane"></i></button></form>
              <div class="ss-chat__helper">Tối đa 5 tin trong 20 giây. Không chia sẻ thông tin nhạy cảm.</div>
              </div>
            <?php endif; ?>
            <?php if (!$canChat): ?><div class="ss-chat__locked" id="liveChatLocked"><i class="fa-solid fa-lock me-1"></i> Trò chuyện sẽ mở khi người dẫn bắt đầu.</div><?php endif; ?>
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
  const isAiReplay=liveId.indexOf('ai-')===0;
  const $=(id)=>document.getElementById(id);
  const url=(route)=>base+'/index.php?r='+route;
  const pageJson=async (endpoint, options)=>{const response=await fetch(endpoint, options||{}); let data={}; try{data=await response.json();}catch(e){} if(!response.ok && !data.message) data.message='Yêu cầu không thành công'; return {response,data};};
  const esc=(value)=>String(value??'').replace(/[&<>"]/g, ch=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[ch]));
  let chatMode='all', rendered=new Set(), livekitRoom=null, localVideoTrack=null, localAudioTrack=null, recording=null, recordingChunks=[], recordingStream=null, streamState=status, publisherToken='';
  let lastChatTs=0, lastPinnedId=(($('livePinnedProductId')||{}).value||''), ending=false;
  let recUploadId='', recSeq=0, recServerNext=0, recFailed=false, recQueue=Promise.resolve(), recTimer=null;
  const chatBox=$('liveChatBox'), statusBadge=$('liveConnectionBadge'), wait=$('liveWaitingOverlay'), waitMsg=$('liveWaitingMessage'), remote=$('livekitRemoteVideo'), local=$('livekitLocalVideo'), audio=$('livekitRemoteAudio');
  function setConnection(text, tone){if(!statusBadge)return; statusBadge.className='ss-badge ss-badge--'+tone; statusBadge.innerHTML='<i class="fa-solid fa-circle"></i> '+esc(text);}
  function renderMessage(msg){const id=msg.client_message_id||msg.id||[msg.sender_name,msg.message,msg.created_at].join('|'); if(rendered.has(id)||!chatBox)return; rendered.add(id); const el=document.createElement('div'); el.className='ss-message'+(msg.is_ai?' ss-message--ai':''); const meta=document.createElement('div'); meta.className='ss-message__meta'; const name=document.createElement('strong'); name.textContent=msg.sender_name||'Khách hàng'; meta.appendChild(name); if(msg.is_ai){const badge=document.createElement('span');badge.className='ss-message__pill';badge.textContent='Tư vấn tự động';meta.appendChild(badge)} if(msg.is_order){const badge=document.createElement('span');badge.className='ss-message__pill';badge.textContent='Đơn hàng';meta.appendChild(badge)} const body=document.createElement('div');body.textContent=msg.message||'';el.append(meta,body);chatBox.appendChild(el);chatBox.scrollTop=chatBox.scrollHeight;}
  async function loadChat(){
    if(!liveId||!chatBox)return;
    const mode=chatMode;
    let endpoint=url('api_live_chat_history')+'&room_id='+encodeURIComponent(liveId)+'&mode='+encodeURIComponent(mode);
    if(lastChatTs>0)endpoint+='&after='+lastChatTs;
    const {data}=await pageJson(endpoint);
    if(mode!==chatMode)return;
    if(data.ok&&Array.isArray(data.data))data.data.forEach(msg=>{if((msg.ts||0)>lastChatTs)lastChatTs=msg.ts;renderMessage(msg);});
  }
  document.querySelectorAll('[data-chat-mode]').forEach(btn=>btn.addEventListener('click',()=>{document.querySelectorAll('[data-chat-mode]').forEach(b=>b.classList.remove('is-active'));btn.classList.add('is-active');chatMode=btn.dataset.chatMode||'all';rendered=new Set();lastChatTs=0;chatBox.innerHTML='';loadChat();}));
  const chatForm=$('liveChatForm'); if(chatForm){chatForm.addEventListener('submit',async e=>{e.preventDefault();const input=$('liveChatInput');const message=input.value.trim();if(!message)return;input.disabled=true;const clientId=(window.crypto&&crypto.randomUUID)?crypto.randomUUID():'msg-'+String(Date.now())+'-'+String(performance.now()).replace(/\D/g,'');const fd=new FormData();fd.append('room_id',liveId);fd.append('message',message);fd.append('client_message_id',clientId);const guest=$('guestNickname');if(guest)fd.append('guest_nickname',guest.value.trim());const {data}=await pageJson(url('api_live_chat'),{method:'POST',body:fd});input.disabled=false;if(data.ok){input.value='';const packet={type:'chat',message:{client_message_id:clientId,sender_name:guest&&guest.value.trim()?guest.value.trim():'Bạn',message,created_at:new Date().toISOString()}};renderMessage(packet.message);if(livekitRoom&&livekitRoom.state===LivekitClient.ConnectionState.Connected){try{await livekitRoom.localParticipant.publishData(new TextEncoder().encode(JSON.stringify(packet)),{reliable:true});}catch(err){console.warn('LiveKit chat publish failed',err);}}}else{alert(data.message||'Không thể gửi tin nhắn');}});}
  // The host's studio page may load before going live (no chat form yet) but must still see questions.
  if(chatForm||isStudio){loadChat();setInterval(loadChat,6000);}
  async function updateStream(state,extra){
    const fd=new FormData();
    fd.append('live_id',liveId);
    fd.append('state',state);
    if(state==='live'&&publisherToken)fd.append('publisher_token',publisherToken);
    if(extra)Object.keys(extra).forEach(key=>fd.append(key,extra[key]));
    const {response,data}=await pageJson(url('api_live_stream_state'),{method:'POST',body:fd});
    if(response.status===401)loginExpired(data.message);
    if(!data.ok)throw new Error(data.message||'Không thể cập nhật trạng thái');
    streamState=state;
    return data;
  }
  // The PHP login session can be lost while the studio is open (expiry or a server restart).
  function loginExpired(message){
    const text=message||'Phiên đăng nhập đã hết hạn. Hãy tải lại trang và đăng nhập lại để tiếp tục phát.';
    setConnection('Phiên đăng nhập đã hết hạn','danger');
    const box=$('studioAuthError');
    if(box){box.innerHTML=esc(text)+' <a href="'+esc(base)+'/index.php?r=dangnhap">Đăng nhập lại</a>';box.hidden=false;}
  }
  async function hostHeartbeat(){
    if(!isStudio||ending||streamState!=='live'||!publisherToken)return;
    const send=async()=>{
      const fd=new FormData();
      fd.append('live_id',liveId);
      fd.append('state','heartbeat');
      fd.append('publisher_token',publisherToken);
      return pageJson(url('api_live_stream_state'),{method:'POST',body:fd});
    };
    try{
      let {response,data}=await send();
      if(response.status===401){loginExpired(data.message);return;}
      if(!data.ok&&response.status===403){
        // Publisher token (1h TTL) may have expired: fetch a fresh one and retry once.
        const tk=await pageJson(url('api_live_token')+'&id='+encodeURIComponent(liveId));
        if(tk.data&&tk.data.ok&&tk.data.data&&tk.data.data.participant_token){
          publisherToken=tk.data.data.participant_token;
          ({response,data}=await send());
        }
      }
      if(!data.ok&&response.status===409){
        // Server says the session is no longer live (ended or interrupted): stop heartbeats.
        streamState=data.state||'interrupted';
        setConnection('Phiên phát đã bị gián đoạn, hãy bấm Bắt đầu phát để phát tiếp','warning');
        if(paused){paused=false;pausedAt=0;if(recording&&recording.state==='paused'){try{recording.resume();}catch(e){}}}
        renderStudio();
      }else if(!data.ok){
        // Transient error (5xx, network, expired token): keep streaming, retry on the next tick.
        console.warn('Host heartbeat rejected',response.status,data.message);
      }
    }catch(err){
      console.warn('Host heartbeat failed',err);
    }
  }
  setInterval(hostHeartbeat,10000);
  function addTrackToRecording(track){
    if(!track||!track.mediaStream)return;
    // Chrome stops a MediaRecorder when a track is added to the stream it records, so the
    // recorder starts only once video AND audio are present and its stream is never changed.
    if(recording)return;
    recordingStream=recordingStream||new MediaStream();
    track.mediaStream.getTracks().forEach(mediaTrack=>{if(!recordingStream.getTracks().some(existing=>existing.id===mediaTrack.id))recordingStream.addTrack(mediaTrack)});
    if(!recording&&window.MediaRecorder&&recordingStream.getVideoTracks().length&&recordingStream.getAudioTracks().length){
      let mime='video/webm;codecs=vp8,opus';
      if(!MediaRecorder.isTypeSupported(mime))mime='video/webm';
      try{
        recordingChunks=[];
        recUploadId=(window.crypto&&crypto.randomUUID)?crypto.randomUUID():'rec-'+Date.now().toString(36)+Math.random().toString(36).slice(2,10);
        recSeq=0;recServerNext=0;recFailed=false;recQueue=Promise.resolve();
        recording=new MediaRecorder(recordingStream,{mimeType:mime,videoBitsPerSecond:2500000});
        recording.ondataavailable=e=>{if(e.data&&e.data.size)recordingChunks.push(e.data)};
        recording.start(1000);
        // While paused the recorder emits nothing: skip the flush so no empty chunk is uploaded.
        recTimer=setInterval(()=>{if(recording&&recording.state==='recording')flushRecording(false);},10000);
      }catch(err){console.warn('Recording unavailable',err);recording=null;}
    }
  }
  async function sendRecordingChunk(blob,seq,final){
    let last={ok:false,message:'Không lưu được video'};
    for(let attempt=0;attempt<3;attempt++){
      if(attempt)await new Promise(resolve=>setTimeout(resolve,2000));
      const fd=new FormData();
      fd.append('live_id',liveId);
      fd.append('upload_id',recUploadId);
      fd.append('seq',String(seq));
      fd.append('final',final?'1':'0');
      if(blob)fd.append('video_blob',blob,'live-'+liveId+'-'+seq+'.webm');
      try{
        const {response,data}=await pageJson(url('api_upload_live_recording'),{method:'POST',body:fd});
        last=data;
        if(data.ok){if(data.next_seq!=null)recServerNext=data.next_seq;return data;}
        recFailed=true;
        if(response.status===409&&data.code==='out_of_order'){console.warn('Recording chunk out of order, server expected seq',data.expected);recServerNext=data.expected;return data;}
        // Permanent client errors will not be fixed by retrying.
        if(response.status>=400&&response.status<500&&response.status!==408&&response.status!==429)return data;
      }catch(err){
        last={ok:false,message:err.message||'Không lưu được video'};
        recFailed=true;
      }
    }
    return last;
  }
  function flushRecording(final){
    // Uploads are strictly sequential: each job waits for the previous one.
    const parts=recordingChunks;
    recordingChunks=[];
    const blob=parts.length?new Blob(parts,{type:(recording&&recording.mimeType)||'video/webm'}):null;
    if(!blob&&!final)return recQueue;
    // After a failed chunk later chunks cannot be appended; only finalise with what the server has.
    if(recFailed&&!final)return recQueue;
    let seq;
    if(recFailed){seq=recServerNext;}else if(blob){seq=recSeq++;}else{seq=recSeq;}
    const sendBlob=recFailed?null:blob;
    const job=recQueue.then(()=>sendRecordingChunk(sendBlob,seq,final));
    recQueue=job.catch(()=>null);
    return job;
  }
  async function uploadRecording(){
    if(!recording)return null;
    if(recTimer){clearInterval(recTimer);recTimer=null;}
    if(recording.state!=='inactive'){
      await new Promise(resolve=>{recording.onstop=()=>resolve();try{recording.stop();}catch(e){resolve();}});
    }
    return flushRecording(true);
  }
  function ensureAudioButton(){
    if(!livekitRoom)return;
    let btn=$('btnEnableAudio');
    if(livekitRoom.canPlaybackAudio){if(btn)btn.hidden=true;return;}
    if(!btn){
      btn=document.createElement('button');
      btn.type='button';
      btn.id='btnEnableAudio';
      btn.innerHTML='<i class="fa-solid fa-volume-high"></i> Bật tiếng';
      btn.addEventListener('click',async()=>{
        try{await livekitRoom.startAudio();}catch(err){console.warn('startAudio failed',err);}
        btn.hidden=true;
      });
      const actions=document.querySelector('.ss-player__actions');
      if(actions)actions.prepend(btn);
    }
    btn.hidden=false;
  }
  // ---- Viewer: host mute / pause state (derived from the host's muted tracks, plus the stream_paused data message) ----
  const waitTitle=$('liveWaitingTitle'), hostMicChip=$('hostMicChip'), waitIcon=wait?wait.querySelector('.ss-player__waiting-icon i'):null;
  const waitOrig={title:waitTitle?waitTitle.textContent:'',msg:waitMsg?waitMsg.textContent:'',icon:waitIcon?waitIcon.className:''};
  let remoteVideoPub=null, remoteAudioPub=null, hostPausedFlag=false, hostOverlay=false;
  function applyHostState(){
    if(isStudio||!wait)return;
    const vMuted=!!(remoteVideoPub&&remoteVideoPub.isMuted), aMuted=!!(remoteAudioPub&&remoteAudioPub.isMuted);
    const mode=(hostPausedFlag||(vMuted&&aMuted))?'paused':(vMuted?'cam':'');
    if(mode){
      if(waitTitle)waitTitle.textContent=mode==='paused'?'Phiên phát đang tạm dừng':'Người dẫn đang tắt camera';
      if(waitMsg)waitMsg.textContent=mode==='paused'?'Người dẫn sẽ quay lại ngay, bạn đừng rời đi nhé.':'Âm thanh vẫn đang phát bình thường.';
      if(waitIcon)waitIcon.className='fa-solid '+(mode==='paused'?'fa-pause':'fa-video-slash');
      wait.hidden=false;hostOverlay=true;
    }else if(hostOverlay){
      hostOverlay=false;
      if(waitTitle)waitTitle.textContent=waitOrig.title;
      if(waitMsg)waitMsg.textContent=waitOrig.msg;
      if(waitIcon)waitIcon.className=waitOrig.icon;
      wait.hidden=!!remoteVideoPub;
    }
    if(hostMicChip)hostMicChip.hidden=!(aMuted&&!mode);
  }
  async function connectLiveKit(){const canConnect=status==='live'||(isStudio&&['ready','interrupted'].includes(status));if(!canConnect){setConnection('Chưa bắt đầu phát','neutral');return;}if(!window.LivekitClient){setConnection('Chưa mở được phần phát video','warning');if(waitMsg)waitMsg.textContent='Không tải được phần phát video. Vui lòng thử lại.';return;}try{const {response,data}=await pageJson(url('api_live_token')+'&id='+encodeURIComponent(liveId));if(!response.ok||!data.ok||!data.data||!data.data.token){setConnection(data.message||'Chưa có kết nối phát','warning');return;}const info=data.data;publisherToken=info.participant_token||'';livekitRoom=new LivekitClient.Room({adaptiveStream:true,dynacast:true});livekitRoom.on(LivekitClient.RoomEvent.AudioPlaybackStatusChanged,ensureAudioButton);livekitRoom.on(LivekitClient.RoomEvent.ConnectionStateChanged,state=>{if(state===LivekitClient.ConnectionState.Connected)setConnection('Đã kết nối','ready');else if(state===LivekitClient.ConnectionState.Reconnecting)setConnection('Đang kết nối lại','warning');else if(state===LivekitClient.ConnectionState.Disconnected)setConnection('Đã ngắt kết nối','ended');});livekitRoom.on(LivekitClient.RoomEvent.TrackSubscribed,(track,publication)=>{if(track.kind===LivekitClient.Track.Kind.Video){track.attach(remote);remote.hidden=false;remoteVideoPub=publication||null;if(wait)wait.hidden=true;}else if(track.kind===LivekitClient.Track.Kind.Audio){track.attach(audio);remoteAudioPub=publication||null;}applyHostState();});livekitRoom.on(LivekitClient.RoomEvent.TrackUnsubscribed,(track)=>{track.detach();if(track.kind===LivekitClient.Track.Kind.Video){remoteVideoPub=null;remote.hidden=true;if(wait)wait.hidden=false;}else if(track.kind===LivekitClient.Track.Kind.Audio)remoteAudioPub=null;applyHostState();});livekitRoom.on(LivekitClient.RoomEvent.TrackMuted,()=>{applyHostState();});livekitRoom.on(LivekitClient.RoomEvent.TrackUnmuted,()=>{hostPausedFlag=false;applyHostState();});livekitRoom.on(LivekitClient.RoomEvent.DataReceived,(payload)=>{try{const packet=JSON.parse(new TextDecoder().decode(payload));if(packet.type==='chat'&&packet.message)renderMessage(packet.message);else if(packet.type==='stream_paused'){hostPausedFlag=true;applyHostState();}else if(packet.type==='stream_resumed'){hostPausedFlag=false;applyHostState();}}catch(e){}});await livekitRoom.connect(info.server_url,info.participant_token);ensureAudioButton();if(isStudio&&info.can_publish)setConnection('Có thể bắt đầu phát','ready');}catch(err){console.error(err);setConnection('Không kết nối được','danger');if(waitMsg)waitMsg.textContent='Không thể kết nối máy chủ phát. Vui lòng thử lại hoặc liên hệ quản trị viên.';}}
  // ---- Studio control panel ----
  const camState=$('camState'),micState=$('micState'),camBtn=$('btnToggleCam'),micBtn=$('btnToggleMic'),goBtn=$('btnGoLive'),goPre=$('studioGoPre'),goLiveBox=$('studioGoLive'),goErr=$('studioGoError'),goHelp=$('studioHelp'),liveTimer=$('studioTimer'),meterBar=$('micMeterBar'),previewTag=$('previewTag'),previewTagText=$('previewTagText');
  const camOffOverlay=$('camOffOverlay'),micOffChip=$('micOffChip'),pauseBtn=$('btnPauseLive'),onAirEl=$('studioOnAir'),stateLabel=$('studioStateLabel'),pausedInfo=$('studioPausedInfo'),pauseTimerEl=$('studioPauseTimer');
  let wentLive=(status==='live'), busyCam=false, busyMic=false, goBusy=false, camErr='', micErr='', liveStartedAt=0, meter=null;
  // Mute state of the published tracks (tracks stay published; mute never interrupts the session) and pause state.
  let camMuted=false, micMuted=false, paused=false, pausedAt=0, pauseBusy=false, prevMute={cam:false,mic:false};
  function deviceError(err,noun){
    const name=(err&&err.name)||'';
    if(name==='NotAllowedError'||name==='SecurityError')return 'Trình duyệt đang chặn '+noun+'. Bấm biểu tượng ổ khóa cạnh thanh địa chỉ, cho phép Camera và Micro rồi thử lại.';
    if(name==='NotFoundError'||name==='DevicesNotFoundError')return 'Không tìm thấy '+noun+' trên máy này.';
    if(name==='NotReadableError'||name==='TrackStartError'||name==='AbortError')return 'Thiết bị đang được ứng dụng khác sử dụng (Zoom, Meet, OBS…). Hãy đóng ứng dụng đó rồi thử lại.';
    if(name==='OverconstrainedError')return 'Thiết bị không hỗ trợ cấu hình yêu cầu.';
    if(name==='NotConnected')return 'Chưa kết nối máy chủ phát, hãy tải lại trang.';
    return (err&&err.message)||('Không thể bật '+noun+'.');
  }
  function fmtElapsed(ms){const t=Math.max(0,Math.floor(ms/1000)),h=Math.floor(t/3600),m=Math.floor((t%3600)/60),sec=t%60,p=n=>String(n).padStart(2,'0');return (h>0?p(h)+':':'')+p(m)+':'+p(sec);}
  function renderTile(stateEl,btn,busy,hasTrack,muted,locked,err,onLabel,offLabel,mutedText){
    const active=hasTrack&&!muted;
    if(stateEl){
      if(busy){stateEl.className='ss-device__state';stateEl.innerHTML='<i class="fa-solid fa-circle-notch fa-spin"></i> '+(active?'Đang tắt…':'Đang bật…');}
      else if(err){stateEl.className='ss-device__state is-error';stateEl.textContent=err;}
      else if(active){stateEl.className='ss-device__state is-on';stateEl.textContent='Đang hoạt động';}
      else if(hasTrack){stateEl.className='ss-device__state';stateEl.textContent=mutedText;}
      else{stateEl.className='ss-device__state';stateEl.textContent='Đang tắt';}
    }
    if(btn){btn.disabled=busy||locked;btn.classList.toggle('is-on',active&&!busy);btn.textContent=active?offLabel:onLabel;btn.setAttribute('aria-pressed',active?'true':'false');}
  }
  function renderStudio(){
    if(!isStudio)return;
    const isLiveNow=streamState==='live';
    if(!isLiveNow&&paused){paused=false;pausedAt=0;}
    renderTile(camState,camBtn,busyCam,!!localVideoTrack,camMuted,paused||pauseBusy,camErr,'Bật camera','Tắt camera','Đã tắt (người xem không thấy hình)');
    renderTile(micState,micBtn,busyMic,!!localAudioTrack,micMuted,paused||pauseBusy,micErr,'Bật micro','Tắt micro','Đã tắt (người xem không nghe thấy tiếng)');
    const bothOn=!!(localVideoTrack&&localAudioTrack&&!camMuted&&!micMuted);
    if(goBtn){goBtn.disabled=goBusy||busyCam||busyMic||!bothOn;goBtn.innerHTML=goBusy?'<i class="fa-solid fa-circle-notch fa-spin"></i> Đang bắt đầu…':'<i class="fa-solid fa-video"></i> Bắt đầu phát';}
    if(goPre)goPre.hidden=isLiveNow;
    if(goLiveBox)goLiveBox.hidden=!isLiveNow;
    if(goHelp)goHelp.textContent=wentLive&&!isLiveNow?'Phiên bị gián đoạn. Bật camera và micro (nếu đang tắt) rồi bấm Bắt đầu phát để phát tiếp.':'Bật camera và micro, kiểm tra khung xem trước rồi bấm Bắt đầu phát.';
    // Only before going live: once live, the status pill (ĐANG PHÁT / TẠM DỪNG) says it already.
    if(previewTag){previewTag.hidden=!localVideoTrack||isLiveNow;previewTag.classList.toggle('is-live',isLiveNow&&!paused);previewTag.classList.toggle('is-paused',paused);if(previewTagText)previewTagText.textContent=paused?'TẠM DỪNG':(isLiveNow?'TRỰC TIẾP':'XEM TRƯỚC · Chưa phát');}
    const hasTracks=!!(localVideoTrack||localAudioTrack);
    if(localVideoTrack){local.hidden=false;}else{local.hidden=true;}
    if(wait)wait.hidden=hasTracks;
    if(camOffOverlay)camOffOverlay.hidden=!((localVideoTrack&&camMuted)||(!localVideoTrack&&localAudioTrack));
    const camOffText=$('camOffText'),camOffIcon=$('camOffIcon');
    if(camOffText)camOffText.textContent=paused?'Đang tạm dừng · người xem thấy màn hình chờ':'Camera đang tắt';
    if(camOffIcon)camOffIcon.className='fa-solid '+(paused?'fa-pause':'fa-video-slash');
    // The status pill on the player was rendered server-side ("Sẵn sàng phát") and never followed the studio.
    const stBadge=$('liveStatusBadge'),stLabel=$('liveStatusLabel');
    if(stBadge&&stLabel&&hasTracks){
      const st=isLiveNow?(paused?['TẠM DỪNG','warning']:['ĐANG PHÁT','live']):['Sẵn sàng phát','ready'];
      stLabel.textContent=st[0];stBadge.className='ss-badge ss-badge--'+st[1];
    }
    if(micOffChip)micOffChip.hidden=!(isLiveNow&&localAudioTrack&&micMuted&&!paused);
    if(isLiveNow&&!liveStartedAt)liveStartedAt=Date.now();
    if(liveTimer&&isLiveNow)liveTimer.textContent=fmtElapsed(Date.now()-liveStartedAt);
    if(onAirEl)onAirEl.classList.toggle('is-paused',paused);
    if(stateLabel)stateLabel.textContent=paused?'Tổng thời gian phát':'Đang phát';
    if(pausedInfo)pausedInfo.hidden=!paused;
    if(pauseTimerEl&&paused)pauseTimerEl.textContent=fmtElapsed(Date.now()-pausedAt);
    if(pauseBtn){
      pauseBtn.disabled=pauseBusy||goBusy||!isLiveNow||!hasTracks;
      pauseBtn.classList.toggle('is-resume',paused);
      pauseBtn.setAttribute('aria-pressed',paused?'true':'false');
      pauseBtn.innerHTML=pauseBusy?'<i class="fa-solid fa-circle-notch fa-spin"></i> Đang xử lý…':(paused?'<i class="fa-solid fa-play"></i> Tiếp tục phát':'<i class="fa-solid fa-pause"></i> Tạm dừng');
    }
  }
  if(isStudio)setInterval(()=>{if(streamState==='live'&&liveTimer&&liveStartedAt)liveTimer.textContent=fmtElapsed(Date.now()-liveStartedAt);if(paused&&pauseTimerEl&&pausedAt)pauseTimerEl.textContent=fmtElapsed(Date.now()-pausedAt);},1000);
  function setGoError(text){if(!goErr)return;goErr.textContent=text||'';goErr.hidden=!text;}
  function stopMeter(){
    if(!meter)return;
    meter.stopped=true;cancelAnimationFrame(meter.raf);
    try{meter.src.disconnect();}catch(e){}
    try{meter.ctx.close();}catch(e){}
    meter=null;if(meterBar)meterBar.style.width='0%';
  }
  function startMeter(track){
    stopMeter();
    try{
      const AC=window.AudioContext||window.webkitAudioContext;
      if(!AC||!meterBar||!track||!track.mediaStreamTrack)return;
      const ctx=new AC();
      try{ctx.resume();}catch(e){}
      const src=ctx.createMediaStreamSource(new MediaStream([track.mediaStreamTrack]));
      const analyser=ctx.createAnalyser();analyser.fftSize=512;src.connect(analyser);
      const data=new Uint8Array(analyser.fftSize);
      const m={ctx,src,raf:0,stopped:false};
      const tick=()=>{
        if(m.stopped)return;
        analyser.getByteTimeDomainData(data);
        let peak=0;if(!micMuted)for(let i=0;i<data.length;i++){const v=Math.abs(data[i]-128)/128;if(v>peak)peak=v;}
        meterBar.style.width=Math.min(100,Math.round(peak*150))+'%';
        m.raf=requestAnimationFrame(tick);
      };
      meter=m;tick();
    }catch(e){console.warn('Mic level meter unavailable',e);stopMeter();}
  }
  async function goLive(){
    await updateStream('live');
    wentLive=true;
    {const composer=$('liveChatComposer'),locked=$('liveChatLocked'),chatState=$('chatConnectionState');if(composer)composer.hidden=false;if(locked)locked.hidden=true;if(chatState){chatState.textContent='Đang mở';chatState.className='ss-badge ss-badge--ready';}}
    hostHeartbeat();
    setConnection('Đang phát trực tiếp','ready');
    renderStudio();
  }
  function afterDeviceChange(){
    // Muting never interrupts a live session; this only refreshes the pre-live hints.
    if(streamState!=='live'){
      if(localVideoTrack&&localAudioTrack&&!camMuted&&!micMuted)setConnection('Sẵn sàng, hãy bấm Bắt đầu phát','ready');
      else if(localVideoTrack&&localAudioTrack)setConnection('Hãy bật cả camera và micro','ready');
      else if(wentLive)setConnection('Phiên phát đang gián đoạn, hãy bật camera và micro rồi bấm Bắt đầu phát','warning');
      else setConnection('Hãy bật cả camera và micro','ready');
    }
    renderStudio();
  }
  async function toggleCam(){
    if(!isStudio||busyCam||paused||pauseBusy||!camBtn)return;
    busyCam=true;camErr='';setGoError('');renderStudio();
    try{
      if(!localVideoTrack){
        if(!livekitRoom||livekitRoom.state!==LivekitClient.ConnectionState.Connected){const e=new Error('not connected');e.name='NotConnected';throw e;}
        // Acquire the camera ourselves and wrap it as a user-provided track: LiveKit then only
        // disables it on mute instead of stopping it and re-acquiring a NEW MediaStreamTrack on
        // unmute, which would leave the MediaRecorder holding a dead track (frozen replay).
        const camStream=await navigator.mediaDevices.getUserMedia({video:{width:{ideal:1280},height:{ideal:720},frameRate:{ideal:30}}});
        const track=new LivekitClient.LocalVideoTrack(camStream.getVideoTracks()[0],undefined,true);
        try{await livekitRoom.localParticipant.publishTrack(track,{source:LivekitClient.Track.Source.Camera});}catch(e){track.stop();throw e;}
        localVideoTrack=track;camMuted=false;track.attach(local);addTrackToRecording(track);
      }else if(camMuted){
        await localVideoTrack.unmute();camMuted=false;
      }else{
        await localVideoTrack.mute();camMuted=true;
      }
    }catch(err){camErr=deviceError(err,'camera');}
    finally{busyCam=false;}
    afterDeviceChange();
  }
  async function toggleMic(){
    if(!isStudio||busyMic||paused||pauseBusy||!micBtn)return;
    busyMic=true;micErr='';setGoError('');renderStudio();
    try{
      if(!localAudioTrack){
        if(!livekitRoom||livekitRoom.state!==LivekitClient.ConnectionState.Connected){const e=new Error('not connected');e.name='NotConnected';throw e;}
        const track=await LivekitClient.createLocalAudioTrack();
        try{await livekitRoom.localParticipant.publishTrack(track);}catch(e){track.stop();throw e;}
        localAudioTrack=track;micMuted=false;addTrackToRecording(track);startMeter(track);
      }else if(micMuted){
        await localAudioTrack.unmute();micMuted=false;
      }else{
        await localAudioTrack.mute();micMuted=true;
      }
    }catch(err){micErr=deviceError(err,'micro');}
    finally{busyMic=false;}
    afterDeviceChange();
  }
  async function sendHostSignal(type){
    // Tells viewers right away; viewers also derive the same state from the muted tracks (late joiners).
    try{
      if(livekitRoom&&livekitRoom.state===LivekitClient.ConnectionState.Connected)await livekitRoom.localParticipant.publishData(new TextEncoder().encode(JSON.stringify({type})),{reliable:true});
    }catch(err){console.warn('LiveKit signal failed',err);}
  }
  async function togglePause(){
    if(!isStudio||pauseBusy||streamState!=='live'||busyCam||busyMic)return;
    pauseBusy=true;setGoError('');renderStudio();
    try{
      if(!paused){
        prevMute={cam:camMuted,mic:micMuted};
        if(localVideoTrack&&!camMuted){await localVideoTrack.mute();camMuted=true;}
        if(localAudioTrack&&!micMuted){await localAudioTrack.mute();micMuted=true;}
        if(recording&&recording.state==='recording'){try{recording.pause();}catch(e){console.warn('Recorder pause failed',e);}flushRecording(false);}
        paused=true;pausedAt=Date.now();
        setConnection('Đã tạm dừng phát','warning');
        await sendHostSignal('stream_paused');
      }else{
        if(recording&&recording.state==='paused'){try{recording.resume();}catch(e){console.warn('Recorder resume failed',e);}}
        // Restore exactly what was on before the pause.
        if(localVideoTrack&&!prevMute.cam){await localVideoTrack.unmute();camMuted=false;}
        if(localAudioTrack&&!prevMute.mic){await localAudioTrack.unmute();micMuted=false;}
        paused=false;pausedAt=0;
        setConnection('Đang phát trực tiếp','ready');
        await sendHostSignal('stream_resumed');
      }
    }catch(err){setConnection(err.message||'Không thể đổi trạng thái tạm dừng, hãy thử lại.','warning');}
    finally{pauseBusy=false;renderStudio();}
  }
  async function onGoLive(){
    if(goBusy||!(localVideoTrack&&localAudioTrack&&!camMuted&&!micMuted)||streamState==='live')return;
    goBusy=true;setGoError('');renderStudio();
    try{await goLive();}catch(err){setGoError(err.message||'Không thể bắt đầu phát, hãy thử lại.');}
    finally{goBusy=false;renderStudio();}
  }
  if(camBtn)camBtn.addEventListener('click',toggleCam);if(micBtn)micBtn.addEventListener('click',toggleMic);if(goBtn)goBtn.addEventListener('click',onGoLive);
  if(pauseBtn)pauseBtn.addEventListener('click',togglePause);
  renderStudio();
  const end=$('btnEndLive');
  if(end)end.addEventListener('click',async()=>{
    if(!confirm('Kết thúc phiên phát? Video đã quay sẽ được lưu nếu quá trình ghi hình đang hoạt động.'))return;
    end.disabled=true;if(pauseBtn)pauseBtn.disabled=true;
    ending=true;
    try{
      const hadRecording=!!recording;
      // End the session first: a successful final upload moves it to replay_ready,
      // after which the server rejects state=ended with 409.
      await updateStream('ended',hadRecording?null:{no_recording:'1'});
      const upload=await uploadRecording();
      if(upload&&!upload.ok)alert(upload.message||'Không lưu được video');
      if(livekitRoom){try{await livekitRoom.disconnect();}catch(e){}}
      window.location.href=base+'/index.php?r=live&id='+encodeURIComponent(liveId);
    }catch(err){alert(err.message||'Không thể kết thúc phiên');end.disabled=false;ending=false;renderStudio();}
  });
  const reaction=$('btnReaction');if(reaction)reaction.addEventListener('click',async()=>{const fd=new FormData();fd.append('live_id',liveId);const {data}=await pageJson(url('api_live_reaction'),{method:'POST',body:fd});if(data.ok){$('reactionCount').textContent=data.reaction_count||0;reaction.classList.add('is-bumped');setTimeout(()=>reaction.classList.remove('is-bumped'),450);}});
  const share=$('btnShareLive');if(share)share.addEventListener('click',async()=>{try{await navigator.clipboard.writeText(window.location.href);share.innerHTML='<i class="fa-solid fa-check"></i> Đã sao chép';setTimeout(()=>share.innerHTML='<i class="fa-solid fa-share-nodes"></i> Chia sẻ',1600);}catch(e){window.prompt('Sao chép liên kết phiên phát:',window.location.href);}});
  async function reloadPage(){if(livekitRoom){try{await livekitRoom.disconnect();}catch(e){}}window.location.reload();}
  async function syncStatus(){
    if(!liveId)return;
    try{
      const {data}=await pageJson(url('api_live_ping')+'&room='+encodeURIComponent(liveId));
      if(!data.ok)return;
      const counter=$('liveViewerCount');
      if(counter)counter.textContent=data.active_viewers||0;
      const serverStatus=data.stream_status||'';
      if(!isStudio&&serverStatus&&serverStatus!==status&&(serverStatus==='live'||status==='live')){await reloadPage();return;}
      const pinnedId=String(data.pinned_product_id||'');
      if(pinnedId!==lastPinnedId){lastPinnedId=pinnedId;refreshPinned();}
    }catch(err){console.warn('Live status sync failed',err);}
  }
  if(status!=='replay_ready'&&!isAiReplay){
    syncStatus();
    setInterval(syncStatus,status==='live'?15000:10000);
  }
  connectLiveKit();
  document.querySelectorAll('[data-session-filter]').forEach(btn=>btn.addEventListener('click',()=>{document.querySelectorAll('[data-session-filter]').forEach(b=>b.classList.remove('is-active'));btn.classList.add('is-active');const filter=btn.dataset.sessionFilter;document.querySelectorAll('.ss-session').forEach(card=>{card.hidden=filter!=='all'&&card.dataset.status!==filter;});}));
  // The global add-to-cart handler in layouts/footer.php posts these forms (toast, badge, errors);
  // posting here too added every live item to the cart twice. Only show the button feedback.
  let lastCartForm=null;document.querySelectorAll('.live-add-to-cart-form,#liveBuyNowForm').forEach(form=>form.addEventListener('submit',()=>{lastCartForm=form;}));document.addEventListener('skinsyntax:cart-added',()=>{const button=lastCartForm&&lastCartForm.querySelector('button');lastCartForm=null;if(button){const old=button.innerHTML;button.innerHTML='<i class="fa-solid fa-check"></i> Đã thêm';setTimeout(()=>button.innerHTML=old,1400);}});
  let pinnedPresent=!!$('livePinnedProductId');
  async function refreshPinned(){if(!liveId||isAiReplay)return;const {data}=await pageJson(url('api_live_products')+'&id='+encodeURIComponent(liveId));if(!data.ok||!data.data)return;const p=data.data.pinned_product||null;if(Boolean(p)!==pinnedPresent){await reloadPage();return;}if(!p)return;const name=$('livePinnedName'),brand=$('livePinnedBrand'),price=$('livePinnedPrice'),id=$('livePinnedProductId'),img=$('livePinnedImg');if(name)name.textContent=p.ten_san_pham||'';if(brand)brand.textContent=p.thuong_hieu||'';if(price)price.textContent=new Intl.NumberFormat('vi-VN').format(data.data.gia_uu_dai_live||0)+' đ';if(id)id.value=p.ma_san_pham||'';if(img&&img.tagName==='IMG'&&p.link_hinh_anh)img.src=p.link_hinh_anh;}
  window.addEventListener('beforeunload',()=>{if(isStudio&&streamState==='live'){const fd=new FormData();fd.append('live_id',liveId);fd.append('state','interrupted');navigator.sendBeacon(url('api_live_stream_state'),fd);}});
})();
</script>
<?php endif; ?>

<?php require_once __DIR__ . '/layouts/footer.php'; ?>
