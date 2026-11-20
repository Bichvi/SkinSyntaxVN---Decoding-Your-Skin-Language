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
$showAiIdolLive = $showAiIdolLive ?? !empty($aiIdolLive);
$liveDirectoryOnly = !empty($liveDirectoryOnly);
$canChat = $isLive && is_logged_in();
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
    'interrupted' => ['Tạm dừng phát', 'warning'],
    'ended' => ['Đã kết thúc', 'ended'],
    'processing' => ['Đang xử lý bản ghi', 'processing'],
    'replay_ready' => ['Xem lại', 'replay'],
    'recording_failed' => ['Lưu video lỗi', 'danger'],
];
[$statusLabel, $statusTone] = $statusLabels[$liveStatus] ?? $statusLabels['none'];
$recordingAvailable = !empty($live['recording_available']);
$recordingUrl = $recordingAvailable ? (string)($live['url_ban_ghi'] ?? '') : '';
?>

<style>
  .ss-live-page{--ss-ink:#17231d;--ss-muted:#66736b;--ss-line:#dfe8e1;--ss-green:#176b45;--ss-green-dark:#0d3f2c;--ss-soft:#f5f8f5;background:#f7f9f7;color:var(--ss-ink);min-height:100vh;padding-bottom:48px}
  .ss-live-page [hidden]{display:none!important}
  .ss-live-page .ss-message:not(.ss-message--new){animation:none}
  .ss-player:fullscreen{width:100%;height:100%;aspect-ratio:auto;border:0;border-radius:0}
  .ss-player:fullscreen video{object-fit:contain}
  .ss-live-page .ss-player--fit video{object-fit:contain}
  .ss-chat-avatar{width:24px;height:24px;object-fit:cover;border-radius:50%;vertical-align:middle;margin-right:6px}
  .ss-avatar{position:relative;overflow:hidden}
  .ss-avatar img{position:absolute;inset:0;width:100%;height:100%;object-fit:cover}
  .ss-live-settings label{display:block;font-size:.82rem}
  .ss-live-settings input[type=range]{width:100%;accent-color:#9be5b7}
  .ss-live-page .live-voucher-popup{position:static;margin-top:10px;background:#123d2c;min-width:0;box-shadow:none;backdrop-filter:none}
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
  .ss-player__top{top:0;background:linear-gradient(180deg,rgba(0,0,0,.7),transparent)}.ss-player__bottom{bottom:0;background:linear-gradient(0deg,rgba(0,0,0,.82),transparent);padding-top:42px;pointer-events:none;justify-content:space-between}
  .ss-player--replay .ss-player__top{background:none;padding:10px;pointer-events:none}.ss-player--replay .ss-player__top>*{pointer-events:auto}.ss-player--replay .ss-player__top .ss-badge{background:rgba(15,23,42,.55);color:#fff}.ss-player--replay .ss-player__top{flex-wrap:nowrap}.ss-player--replay .ss-player__actions{width:auto;margin-left:auto}
  .ss-player--replay .ss-player__bottom,
  .ss-player--replay .ss-live-settings,
  .ss-player--replay .ss-live-deals-drawer,
  .ss-player--replay #liveConnectionBadge,
  .ss-player--replay #btnEnableLiveSound,
  .ss-player--replay #hostMicChip,
  .ss-player--replay #liveHeartsContainer { display: none !important; }
  .ss-player--replay video { object-fit: contain; }
  .ss-replay-meta{display:flex;align-items:center;gap:10px;margin-top:10px;color:#1f2a24}.ss-replay-meta__text{min-width:0}.ss-replay-meta strong{display:block;font-size:14px}.ss-replay-meta small{display:block;color:#5f6b64;font-size:12px;margin-top:2px;overflow-wrap:anywhere}
  .ss-badge{display:inline-flex;align-items:center;gap:6px;border-radius:999px;padding:6px 10px;font-size:12px;font-weight:750;line-height:1}.ss-badge--live{background:#e23b4e;color:#fff}.ss-badge--ready{background:#dff1e6;color:#0f6740}.ss-badge--scheduled{background:#fff0c2;color:#7b5a00}.ss-badge--warning{background:#fff0c2;color:#795700}.ss-badge--neutral,.ss-badge--ended,.ss-badge--processing{background:#e9eeea;color:#526057}.ss-badge--replay{background:#dff1e6;color:#0f6740}.ss-badge--danger{background:#ffe2e5;color:#ad293c}
  .ss-player__actions{margin-left:auto;display:flex;gap:7px;flex-wrap:wrap}.ss-player__actions button,.ss-player__actions a{border:1px solid rgba(255,255,255,.35);background:rgba(0,0,0,.42);color:#fff;border-radius:999px;padding:6px 10px;font-size:12px;text-decoration:none}.ss-player__actions button:hover,.ss-player__actions a:hover{background:rgba(255,255,255,.15)}
  .ss-streamer{display:flex;align-items:center;gap:10px}.ss-avatar{width:38px;height:38px;border-radius:50%;display:grid;place-items:center;background:#d9efe2;color:#176b45;font-weight:800}.ss-streamer strong{display:block;font-size:14px}.ss-streamer small{display:block;color:#d2e1d7;font-size:12px;margin-top:2px}
  .ss-live-controls{display:flex;align-items:center;gap:7px;pointer-events:auto;margin-left:auto}.ss-live-control{width:38px;height:38px;border:1px solid rgba(255,255,255,.28);border-radius:50%;display:grid;place-items:center;background:rgba(7,18,12,.72);color:#fff;cursor:pointer;transition:background .18s ease,transform .18s ease}.ss-live-control:hover{background:rgba(23,107,69,.92);transform:translateY(-1px)}.ss-live-control:focus-visible{outline:3px solid #9be5b7;outline-offset:2px}.ss-live-control--deals{width:auto;border-radius:999px;padding:0 12px;grid-auto-flow:column;gap:7px;font-weight:800}.ss-live-control__count{min-width:19px;height:19px;border-radius:999px;display:grid;place-items:center;background:#f5c542;color:#17231d;font-size:11px}.ss-live-settings{position:absolute;right:16px;bottom:72px;z-index:20;width:min(260px,calc(100% - 32px));padding:12px;border-radius:12px;background:rgba(7,18,12,.96);border:1px solid rgba(255,255,255,.18);color:#fff;box-shadow:0 14px 36px rgba(0,0,0,.38)}.ss-live-settings strong{display:block;font-size:13px;margin-bottom:5px}.ss-live-settings small{display:block;color:#cfe0d5;line-height:1.45}.ss-live-deals-drawer{position:absolute;z-index:19;right:12px;top:70px;bottom:70px;width:min(380px,calc(100% - 24px));display:flex;flex-direction:column;background:#fff;color:var(--ss-ink);border-radius:16px;box-shadow:0 18px 48px rgba(0,0,0,.4);overflow:hidden}.ss-live-deals-drawer__head{display:flex;align-items:center;justify-content:space-between;gap:12px;padding:14px 16px;border-bottom:1px solid var(--ss-line)}.ss-live-deals-drawer__head h2{font-size:15px;margin:0}.ss-live-deals-drawer__close{width:34px;height:34px;border:0;border-radius:50%;background:#edf3ef;color:var(--ss-ink);cursor:pointer}.ss-live-deals-drawer .ss-product-list{display:flex;flex-direction:column;overflow:auto;margin:0;padding:12px}.ss-cart-toast{position:fixed;z-index:1080;right:22px;bottom:22px;max-width:min(360px,calc(100vw - 24px));display:flex;align-items:center;gap:10px;padding:12px 14px;border-radius:13px;background:#123d2c;color:#fff;box-shadow:0 14px 38px rgba(13,63,44,.28);font-size:13px;font-weight:700}.ss-cart-toast i{color:#9be5b7}.header-cart-badge.is-updated{animation:ss-cart-pop .45s ease-out}.ss-message{animation:ss-chat-in .22s cubic-bezier(.2,.8,.2,1) both}@keyframes ss-chat-in{from{opacity:0;transform:translateY(8px)}to{opacity:1;transform:translateY(0)}}@keyframes ss-cart-pop{50%{transform:scale(1.35)}}
  .ss-product{padding:18px;margin-top:14px}.ss-product__head{display:flex;justify-content:space-between;align-items:center;gap:10px;margin-bottom:14px}.ss-kicker{color:#c3344d;font-size:11px;font-weight:800;letter-spacing:.06em;text-transform:uppercase}.ss-product__body{display:flex;gap:14px;align-items:center}.ss-product__image{width:78px;height:78px;object-fit:cover;border:1px solid var(--ss-line);border-radius:12px;background:#f5f7f5}.ss-product__image--empty{display:grid;place-items:center;color:#8a988e;font-size:24px}.ss-product__info{min-width:0;flex:1}.ss-product__brand{color:var(--ss-green);font-size:12px;font-weight:750}.ss-product__name{font-weight:750;margin:3px 0 6px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}.ss-price{color:var(--ss-green);font-size:20px;font-weight:850}.ss-old-price{color:#8c968e;text-decoration:line-through;font-size:12px;margin-left:8px}.ss-product__cta{display:flex;gap:8px;align-items:center}.ss-btn{border:0;border-radius:999px;padding:10px 16px;font-size:13px;font-weight:800;cursor:pointer;text-decoration:none;display:inline-flex;align-items:center;justify-content:center;gap:7px}.ss-btn--primary{background:var(--ss-green-dark);color:#fff}.ss-btn--primary:hover{background:#176b45;color:#fff}.ss-btn--ghost{background:#edf5ef;color:var(--ss-green)}.ss-btn[disabled]{opacity:.55;cursor:not-allowed}.ss-empty-product{padding:20px;text-align:center;color:var(--ss-muted);border:1px dashed var(--ss-line);border-radius:12px}
  .ss-live-product-modal{position:fixed;inset:0;z-index:1100;display:grid;place-items:center;padding:18px;background:rgba(9,28,18,.58)}.ss-live-product-modal[hidden]{display:none}.ss-live-product-modal__card{width:min(520px,100%);background:#fff;border:1px solid var(--ss-line);border-radius:18px;box-shadow:0 24px 70px rgba(9,28,18,.28);padding:20px}.ss-live-product-modal__head{display:flex;align-items:flex-start;justify-content:space-between;gap:12px}.ss-live-product-modal__close{width:34px;height:34px;border:0;border-radius:50%;background:#edf5ef;color:var(--ss-ink);cursor:pointer}.ss-live-product-modal__body{display:flex;gap:16px;align-items:center;margin-top:16px}.ss-live-product-modal__image{width:120px;height:120px;object-fit:cover;border-radius:14px;background:#f5f7f5}.ss-live-product-modal__name{font-size:18px;font-weight:800;line-height:1.35}.ss-live-product-modal__meta{color:var(--ss-muted);font-size:12px;margin-top:5px}
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
  @media (max-width:620px){.ss-live-wrap{padding:18px 12px 0}.ss-live-hero{padding:22px 0}.ss-live-hero .container{padding-left:16px;padding-right:16px}.ss-player{border-radius:12px}.ss-product__body{align-items:flex-start}.ss-product__cta{width:100%;margin-top:10px}.ss-product__body{flex-wrap:wrap}.ss-product__info{flex-basis:calc(100% - 94px)}.ss-product__cta{margin-left:92px}.ss-product__cta .ss-btn{flex:1}.ss-session-grid{grid-template-columns:1fr}.ss-session__thumb{height:170px}.ss-player__actions{width:100%;margin-left:0}.ss-player__top{align-items:flex-start}.ss-player__bottom{padding:12px;padding-top:38px}.ss-streamer{max-width:calc(100% - 176px)}.ss-streamer small,.ss-streamer strong{white-space:nowrap;overflow:hidden;text-overflow:ellipsis}.ss-live-control{width:36px;height:36px}.ss-live-control--deals{width:36px;padding:0}.ss-live-control--deals>span:not(.ss-live-control__count){display:none}.ss-live-control__count{position:absolute;transform:translate(12px,-12px)}.ss-live-deals-drawer{left:8px;right:8px;top:auto;bottom:8px;width:auto;max-height:72%;border-radius:16px}.ss-chat__body{height:310px}.ss-cart-toast{right:12px;bottom:12px}}
  @media (prefers-reduced-motion:reduce){.ss-message,.header-cart-badge.is-updated,.ss-live-control{animation:none!important;transition:none!important}}
  .ss-live-hero--discovery + .ss-live-wrap { padding-top: 14px; }
  .ss-deal__meta del { font-size: .72rem; color: var(--ss-muted); }
  .ss-deal__meta strong { white-space: normal; }
  .ss-live-rewards { margin-top: 10px; }
  .ss-live-hero.ss-live-hero--discovery {
    padding: 10px 0;
    background: #133b2d;
    border-bottom: 1px solid #245542;
    color: #fff;
  }
  .ss-discovery { display: flex; align-items: center; gap: 16px; min-width: 0; }
  .ss-discovery__syna { flex: 0 0 auto; width: 78px; height: 90px; object-fit: contain; }
  .ss-discovery h1 { margin: 0 0 4px; font-size: 1.38rem; line-height: 1.35; }
  .ss-discovery p { color: #d5e9dd; font-size: .85rem; }
  .ss-discovery__nav { display: flex; align-items: center; flex-wrap: wrap; gap: 8px; }
  .ss-discovery__nav .ss-btn { background: #e3f1e7; color: #174832; }
  .ss-discovery__nav .ss-btn:hover { background: #fff; }
  .ss-session__match {
    display: block;
    margin: 8px 0;
    color: #145639;
    font-size: .75rem;
    font-weight: 700;
  }
  .ss-session__body a:focus-visible, .ss-discovery__nav :focus-visible, .ss-filters button:focus-visible {
    outline: 3px solid #e3ab58;
    outline-offset: 2px;
  }
  @media (max-width: 620px) {
    .ss-discovery { gap: 8px; }
    .ss-discovery h1 { font-size: 1.15rem; }
    .ss-discovery__syna { width: 56px; height: 67px; }
    .ss-discovery__nav { width: 100%; padding-left: 64px; }
    .ss-discovery__nav .ss-btn { padding: 8px 10px; }
  }
  @media (max-width: 620px) {
    .ss-player:not(:fullscreen) .ss-live-deals-drawer {
      position: fixed;
      inset: auto 8px max(8px, env(safe-area-inset-bottom));
      width: auto;
      max-height: min(55dvh, 420px);
    }
    .ss-player:not(:fullscreen) .ss-live-deals-drawer .ss-product-list { min-height: 92px; }
    .ss-player__top { gap: 5px; padding: 9px; }
    .ss-player__top .ss-badge { font-size: 10px; }
    .ss-player__waiting { padding: 66px 18px 54px; }
    .ss-player__waiting-icon { display: none; }
    .ss-player__waiting h2 { font-size: 1rem; margin-bottom: 5px; }
    .ss-player__waiting p { font-size: .79rem; }
    .ss-player__bottom { flex-wrap: nowrap; }
    .ss-streamer { min-width: 0; }
    .ss-streamer > div:last-child { min-width: 0; }
    .ss-streamer .ss-avatar { flex: 0 0 30px; width: 30px; height: 30px; }
  }

  /* Floating Reaction Hearts */
  @keyframes floatUpAndFade {
    0% { transform: translateY(0) scale(0.8); opacity: 1; }
    50% { transform: translateY(-100px) scale(1.2) translateX(12px); opacity: 0.9; }
    100% { transform: translateY(-240px) scale(1.5) translateX(-15px); opacity: 0; }
  }
  .floating-heart {
    position: absolute;
    bottom: 20px;
    right: 25px;
    font-size: 28px;
    pointer-events: none;
    animation: floatUpAndFade 2s ease-out forwards;
    z-index: 20;
    user-select: none;
  }

  /* Live Voucher Banner Overlay */
  .live-voucher-popup {
    position: absolute;
    top: 58px;
    right: 16px;
    background: rgba(13, 22, 18, 0.94);
    border: 1px solid #52b788;
    border-radius: 12px;
    padding: 12px 16px;
    color: #fff;
    z-index: 15;
    box-shadow: 0 8px 32px rgba(0,0,0,0.5);
    backdrop-filter: blur(8px);
    min-width: 280px;
    animation: slideInDown 0.3s ease;
  }
  @keyframes slideInDown {
    from { transform: translateY(-15px); opacity: 0; }
    to { transform: translateY(0); opacity: 1; }
  }

  /* Pinned Comment Banner */
  .ss-chat-pinned-comment {
    background: #183b2b;
    border-left: 3px solid #52b788;
    padding: 7px 12px;
    font-size: 0.8rem;
    margin: 8px 12px 4px;
    border-radius: 6px;
    display: flex;
    align-items: center;
    color: #e8f5ee;
  }
</style>

<main class="ss-live-page" id="ssLivePage" data-live-id="<?= h($live['id'] ?? '') ?>" data-live-status="<?= h($liveStatus) ?>" data-signed-in="<?= is_logged_in() ? '1' : '0' ?>" data-is-studio="<?= $canPublish ? '1' : '0' ?>" data-moderate="<?= $canModerate ? '1' : '0' ?>" data-ai-enabled="<?= !empty($live['bat_ai_cohost']) ? '1' : '0' ?>" data-csrf="<?= h($_SESSION['live_csrf'] ?? '') ?>" data-base-url="<?= h(BASE_URL) ?>">
  <?php if (!$isStudio): ?>
  <section class="ss-live-hero ss-live-hero--discovery">
    <div class="container d-flex flex-wrap align-items-center justify-content-between gap-2">
      <div class="ss-discovery">
        <img class="ss-discovery__syna" src="<?= h(BASE_URL . '/assets/images/syna_greeting.png') ?>" alt="" width="78" height="90">
        <div>
          <h1><?= h($hasLive ? (($live['title'] ?? '') ?: 'SkinSyntax Live') : 'Phòng Live cùng Syna') ?></h1>
          <?php if (!empty($live['discovery_topic'])): ?>
            <p>Chủ đề khớp hồ sơ da của bạn · <?= h($live['discovery_topic']) ?></p>
          <?php elseif (!$hasLive): ?>
            <p>Chọn buổi tư vấn, xem lịch phát hoặc mở lại phiên bạn đã bỏ lỡ.</p>
          <?php elseif ($isLive): ?>
            <p>Đang phát cùng <?= h($displayStreamer) ?></p>
          <?php endif; ?>
        </div>
      </div>
      <nav class="ss-discovery__nav" aria-label="Khám phá Live">
        <?php if ($liveDirectoryOnly): ?>
          <a class="ss-btn" href="<?= h(BASE_URL . '/index.php?r=live') ?>">Phiên nổi bật</a>
        <?php else: ?>
          <a class="ss-btn" href="<?= h(BASE_URL . '/index.php?r=live&view=all') ?>"><i class="fa-solid fa-list" aria-hidden="true"></i> Tất cả phiên</a>
        <?php endif; ?>
        <?php if ($isLive && $canChat && !empty($live['bat_ai_cohost'])): ?>
          <button type="button" class="ss-btn" id="liveDiscoveryAsk"><i class="fa-solid fa-comments" aria-hidden="true"></i> Hỏi Syna</button>
        <?php elseif (!empty($aiIdolLive) && !$showAiIdolLive): ?>
          <a class="ss-btn" href="<?= h(BASE_URL . '/index.php?r=live&view=syna#aiIdolLive') ?>"><i class="fa-solid fa-video" aria-hidden="true"></i> Xem Syna đang phát</a>
        <?php endif; ?>
      </nav>
    </div>
  </section>
  <?php else: ?>
  <section class="ss-live-hero">
    <div class="container">
      <div class="d-flex flex-wrap align-items-center justify-content-between gap-3">
        <div>
          <h1>Phòng phát SkinSyntax</h1>
          <p>Chuẩn bị nguồn phát, giới thiệu sản phẩm và trò chuyện cùng người xem.</p>
          <?php if ($canPublish): ?><div class="mt-3 small text-success fw-semibold"><i class="fa-solid fa-circle-info me-1"></i>Xem trước camera, bật micrô nếu cần, rồi chọn Bắt đầu phát camera. Bạn cũng có thể phát video hoặc chia sẻ màn hình.</div><?php endif; ?>
        </div>
        <?php if ($isStaff): ?>
          <a class="ss-btn ss-btn--primary" href="<?= BASE_URL ?>/index.php?r=admin_lives"><i class="fa-solid fa-sliders"></i> Quản lý phiên phát</a>
        <?php endif; ?>
      </div>
    </div>
  </section>
  <?php endif; ?>

  <div class="ss-live-wrap">
  <?php if ($showAiIdolLive && !empty($aiIdolLive)): ?>
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

    <?php if (!$hasLive && !$showAiIdolLive && !$liveDirectoryOnly): ?>
      <section class="ss-card ss-empty">
        <i class="fa-solid fa-video-slash"></i>
        <h2>Chưa có phiên phát khả dụng</h2>
        <p>Không có dữ liệu phiên live thật để hiển thị. Quản trị viên có thể tạo phiên mới trong khu vực quản lý.</p>
        <?php if ($isStaff): ?><a class="ss-btn ss-btn--primary mt-3" href="<?= BASE_URL ?>/index.php?r=admin_lives">Mở quản lý phiên phát</a><?php endif; ?>
      </section>
    <?php elseif ($hasLive): ?>
      <div class="ss-live-grid">
        <section>
          <?php $isReplayView = ($liveStatus === 'replay_ready' && $recordingAvailable && $recordingUrl !== ''); ?>
          <div class="ss-player<?= $isReplayView ? ' ss-player--replay' : '' ?>" id="ssPlayer">
            <video id="livekitRemoteVideo" autoplay playsinline muted hidden></video>
            <video id="livekitLocalVideo" autoplay playsinline muted hidden></video>
            <audio id="livekitRemoteAudio" autoplay muted></audio>
            <?php if ($liveStatus === 'replay_ready' && $recordingUrl !== ''): ?>
              <video id="replayVideoPlayer" controls playsinline preload="metadata" src="<?= h($recordingUrl) ?>"></video>
            <?php endif; ?>
            <div class="ss-player__waiting" id="liveWaitingOverlay" <?= ($isLive && $liveStatus !== 'replay_ready') ? '' : ($liveStatus === 'replay_ready' && $recordingUrl !== '' ? 'hidden' : '') ?>>
              <div class="ss-player__waiting-inner">
                <div class="ss-player__waiting-icon"><i class="fa-solid <?= $liveStatus === 'ready' ? 'fa-video' : ($liveStatus === 'scheduled' ? 'fa-calendar-days' : 'fa-video') ?>"></i></div>
                <h2 class="h5 fw-bold" id="liveWaitingTitle"><?= h($statusLabel) ?></h2>
                <p class="mb-0 opacity-75" id="liveWaitingMessage">
                  <?php if ($liveStatus === 'live'): ?>Đang tải hình ảnh từ buổi live…<?php elseif ($liveStatus === 'ready'): ?><?= $isStudio ? 'Xem trước nguồn phát rồi chọn Bắt đầu phát.' : 'Buổi live sắp bắt đầu. Bạn có thể giữ trang này để theo dõi.' ?><?php elseif ($liveStatus === 'interrupted'): ?>Buổi live đang tạm dừng. Trang sẽ tự cập nhật khi tiếp tục.<?php elseif ($liveStatus === 'scheduled'): ?>Buổi live sẽ bắt đầu theo lịch hẹn.<?php elseif ($liveStatus === 'replay_ready'): ?>Bản ghi không khả dụng.<?php else: ?>Buổi live đã kết thúc. Hiện chưa có video xem lại.<?php endif; ?>
                </p>
              </div>
            </div>
            <?php if ($isStudio): ?><div class="ss-camoff" id="camOffOverlay" hidden><i class="fa-solid fa-video-slash" id="camOffIcon"></i><span id="camOffText">Camera đang tắt</span></div><span class="ss-player__chip" id="micOffChip" role="status" aria-live="polite" hidden><i class="fa-solid fa-microphone-slash"></i> Micro đang tắt — người xem không nghe thấy bạn</span><?php elseif (!$isReplayView): ?><span class="ss-player__chip" id="hostMicChip" role="status" aria-live="polite" hidden><i class="fa-solid fa-microphone-slash"></i> Người dẫn đang tắt micro</span><?php endif; ?>
            <?php if ($isStudio): ?><span class="ss-preview-tag" id="previewTag" hidden><i class="fa-solid fa-circle"></i> <span id="previewTagText">XEM TRƯỚC · Chưa phát</span></span><?php endif; ?>
            <div class="ss-player__top">
              <span class="ss-badge ss-badge--<?= h($statusTone) ?>" id="liveStatusBadge"><i class="fa-solid fa-circle"></i> <span id="liveStatusLabel"><?= h($statusLabel) ?></span></span>
              <span class="ss-badge ss-badge--neutral" id="liveViewers" <?= $isLive ? '' : 'hidden' ?>><i class="fa-solid fa-eye"></i> <span id="liveViewerCount">0</span> đang xem</span>
              <span class="ss-badge ss-badge--neutral" id="liveSourceBadge" hidden></span>
              <?php if (!$isReplayView): ?><span class="ss-badge ss-badge--neutral" id="liveConnectionBadge"><i class="fa-solid fa-circle-notch fa-spin"></i> Đang chuẩn bị phát</span><?php endif; ?>
              <div class="ss-player__actions">
                <?php if ($isLive): ?><button type="button" id="btnReaction"><i class="fa-solid fa-heart"></i> <span id="reactionCount"><?= (int)($live['reaction_count'] ?? 0) ?></span></button><?php endif; ?>
                <button type="button" id="btnShareLive"><i class="fa-solid fa-share-nodes"></i> Chia sẻ</button>
                <button type="button" id="btnEnableLiveSound" hidden>Bật tiếng</button>
                <?php if (!empty($permissions['publish']) && !$isStudio && in_array($liveStatus, ['ready', 'live', 'interrupted'], true)): ?>
                  <a href="<?= BASE_URL ?>/index.php?r=live&amp;id=<?= h(urlencode((string)($live['id'] ?? ''))) ?>&amp;studio=1"><i class="fa-solid fa-video"></i> Vào phòng phát</a>
                <?php endif; ?>
              </div>
            </div>
            <?php if (!$isReplayView): ?>
            <div class="ss-player__bottom">
              <div class="ss-streamer">
                <div class="ss-avatar" aria-hidden="true">
                  <?= h(mb_strtoupper(mb_substr($displayStreamer, 0, 2))) ?>
                  <img id="liveStreamerAvatar" alt="" <?= !empty($live['streamer_avatar']) ? 'src="' . h($live['streamer_avatar']) . '"' : 'hidden' ?>>
                </div>
                <div><strong><?= h($displayStreamer) ?></strong><small><?= h($live['title'] ?? '') ?></small></div>
              </div>
              <div class="ss-live-controls" aria-label="Điều khiển trình phát">
                <button type="button" class="ss-live-control" id="btnLiveMute" aria-label="Bật âm thanh" aria-pressed="false"><i class="fa-solid fa-volume-xmark" aria-hidden="true"></i></button>
                <button type="button" class="ss-live-control ss-live-control--deals" id="btnLiveDeals" aria-label="Mở sản phẩm ưu đãi" aria-controls="liveDeals" aria-expanded="false" hidden><i class="fa-solid fa-bag-shopping" aria-hidden="true"></i><span>Sản phẩm</span><span class="ss-live-control__count" id="liveDealCount">0</span></button>
                <button type="button" class="ss-live-control" id="btnLiveSettings" aria-label="Cài đặt trình phát" aria-controls="liveSettingsPanel" aria-expanded="false"><i class="fa-solid fa-gear" aria-hidden="true"></i></button>
                <button type="button" class="ss-live-control" id="btnLiveFullscreen" aria-label="Phóng to màn hình"><i class="fa-solid fa-expand" aria-hidden="true"></i></button>
              </div>
            </div>
            <?php endif; ?>

            <div class="ss-live-settings" id="liveSettingsPanel" hidden>
              <label for="liveVolume">Âm lượng</label>
              <input type="range" id="liveVolume" min="0" max="1" step="0.05" value="1">
              <label><input type="checkbox" id="liveFitVideo"> Hiện trọn khung hình</label>
            </div>

            <aside class="ss-live-deals-drawer" id="liveDeals" aria-labelledby="liveDealsTitle" hidden>
              <div class="ss-live-deals-drawer__head"><div><h2 id="liveDealsTitle">Ưu đãi đang mở bán</h2><small>Giá và thời gian do quản trị viên thiết lập</small></div><button type="button" class="ss-live-deals-drawer__close" id="btnCloseLiveDeals" aria-label="Đóng danh sách sản phẩm"><i class="fa-solid fa-xmark" aria-hidden="true"></i></button></div>
              <div class="ss-product-list" id="liveDealsList"></div>
            </aside>

            <!-- FLOATING HEARTS CONTAINER -->
            <div id="liveHeartsContainer" style="position: absolute; right: 0; bottom: 0; width: 100px; height: 320px; pointer-events: none; overflow: hidden; z-index: 18;"></div>
          </div>

            <div id="liveVoucherPopup" class="live-voucher-popup" hidden>
              <div class="d-flex align-items-center justify-content-between gap-2 mb-1">
                <span class="badge bg-warning text-dark fw-bold extra-small"><i class="fa-solid fa-bolt me-1"></i>VOUCHER ĐỘC QUYỀN LIVE</span>
                <span class="badge bg-dark text-warning border border-warning extra-small"><i class="fa-solid fa-clock me-1"></i><span id="liveVoucherTimer">10:00</span></span>
              </div>
              <div class="d-flex align-items-center justify-content-between gap-3 mt-1">
                <div>
                  <strong class="d-block text-white" id="liveVoucherTitle" style="font-size: 0.88rem;">Flash Deal Live</strong>
                  <span class="text-success fw-bold" id="liveVoucherValue" style="font-size: 1.05rem;">50.000 đ</span>
                  <small class="text-white-50 d-block extra-small">Mã: <code id="liveVoucherCode" class="text-warning">LIVE50K</code></small>
                </div>
                <button type="button" id="btnClaimVoucher" class="btn btn-sm btn-success fw-bold rounded-pill px-3 py-1.5 shadow">
                  Nhận voucher
                </button>
              </div>
            </div>
          <?php if ($role === 'khach_hang' && !$isStudio): ?>
            <a class="ss-btn ss-btn--ghost ss-live-rewards" href="<?= h(BASE_URL . '/index.php?r=phan_thuong') ?>">
              <i class="fa-solid fa-gift" aria-hidden="true"></i> Phần thưởng của tôi
            </a>
          <?php endif; ?>
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
                  <button class="ss-btn ss-btn--primary" type="button" data-live-source="camera">Bắt đầu phát camera</button>
                  <button class="ss-btn ss-btn--ghost" type="button" data-live-source="screen">Phát màn hình</button>
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
              <div class="ss-product__body" id="livePinnedBody" <?= $pinned ? '' : 'hidden' ?>>
                <img id="livePinnedImg" class="ss-product__image" <?= $pinnedImage !== '' ? 'src="' . h($pinnedImage) . '"' : 'hidden' ?> alt="<?= h($pinnedName) ?>">
                <div class="ss-product__info"><div id="livePinnedBrand" class="ss-product__brand"><?= h($pinnedBrand) ?></div><div id="livePinnedName" class="ss-product__name"><?= h($pinnedName) ?></div><div><span id="livePinnedPrice" class="ss-price"><?= vnd($pinnedPrice) ?></span><span id="livePinnedMarketPrice" class="ss-old-price" <?= $discount ? '' : 'hidden' ?>><?= vnd($marketPrice) ?></span><span id="livePinnedDiscountBadge" class="ss-badge ss-badge--danger ms-2" <?= $discount ? '' : 'hidden' ?>>-<?= $discount ?>%</span></div><small id="livePinCountdown" class="d-block mt-1 text-muted" hidden></small></div>
                <div class="ss-product__cta">
                  <form id="liveBuyNowForm" action="<?= BASE_URL ?>/index.php?r=api_live_add_to_cart" method="post"><input type="hidden" name="live_id" value="<?= h($live['id']) ?>"><input type="hidden" name="csrf" value="<?= h($_SESSION['live_csrf'] ?? '') ?>"><input type="hidden" name="deal_id" value="pinned"><input type="hidden" name="product_id" id="livePinnedProductId" value="<?= h($pinnedId) ?>"><button class="ss-btn ss-btn--primary" type="submit" <?= $isLive ? '' : 'disabled' ?>><i class="fa-solid fa-bag-shopping"></i> Thêm vào giỏ</button></form>
                  <button class="ss-btn ss-btn--ghost" id="livePinnedLink" type="button">Xem nhanh</button>
                </div>
              </div>
            <div class="ss-empty-product" id="livePinnedEmpty" <?= $pinned ? 'hidden' : '' ?>>Phiên này chưa có sản phẩm đang giới thiệu.</div>
          </section>

          <div class="ss-live-product-modal" id="liveProductModal" role="dialog" aria-modal="true" aria-labelledby="liveProductModalName" hidden>
            <div class="ss-live-product-modal__card">
              <div class="ss-live-product-modal__head"><div><span class="ss-kicker">Thông tin sản phẩm</span><h2 class="h5 mb-0 mt-1" id="liveProductModalName">Sản phẩm đang giới thiệu</h2></div><button type="button" class="ss-live-product-modal__close" id="btnCloseLiveProductModal" aria-label="Đóng thông tin sản phẩm"><i class="fa-solid fa-xmark"></i></button></div>
              <div class="ss-live-product-modal__body"><img class="ss-live-product-modal__image" id="liveProductModalImage" alt=""><div><div class="ss-product__brand" id="liveProductModalBrand"></div><div class="ss-price mt-2" id="liveProductModalPrice"></div><div class="ss-live-product-modal__meta" id="liveProductModalMeta"></div></div></div>
              <div class="d-flex gap-2 justify-content-end mt-4"><button type="button" class="ss-btn ss-btn--ghost" id="btnCloseLiveProductModal2">Tiếp tục xem live</button><button type="button" class="ss-btn ss-btn--primary" id="btnModalAddToCart"><i class="fa-solid fa-bag-shopping"></i> Thêm vào giỏ</button></div>
            </div>
          </div>

        </section>

        <aside class="ss-card ss-chat" aria-label="Trò chuyện trực tiếp">
          <div class="ss-chat__head"><div class="ss-chat__title"><h2><i class="fa-solid fa-comments text-success me-2"></i>Trò chuyện trực tiếp</h2><span class="ss-badge ss-badge--<?= $isLive ? 'ready' : 'neutral' ?>" id="chatConnectionState"><?= $isLive ? 'Đang mở' : 'Chưa mở' ?></span></div><div class="ss-chat__tabs" role="tablist"><button type="button" class="is-active" data-chat-mode="all">Tất cả</button><button type="button" data-chat-mode="featured">Nổi bật</button><button type="button" data-chat-mode="qa">Hỏi đáp</button></div></div>
          <!-- PINNED COMMENT BANNER -->
          <div id="livePinnedCommentBanner" class="ss-chat-pinned-comment" hidden>
            <i class="fa-solid fa-thumbtack text-warning me-1.5"></i>
            <span id="livePinnedCommentText"></span>
          </div>
          <div class="ss-chat__body" id="liveChatBox"><div class="ss-chat__notice">Tin nhắn trong phiên sẽ được lưu đúng theo buổi phát.</div></div>
          <div class="ss-chat__footer">
            <div id="liveChatCompose" <?= $canChat ? '' : 'hidden' ?>>
              <div id="liveChatReply" hidden><span></span> <button type="button" class="btn btn-link btn-sm">Bỏ trả lời</button></div>
              <button type="button" id="liveSynaSuggestion" class="btn btn-link btn-sm" hidden>@syna — Hỏi trợ lý AI</button>
              <form id="liveChatForm" class="ss-chat__row"><input id="liveChatInput" type="text" maxlength="1000" placeholder="Nhắn cho mọi người…" required><button class="ss-btn ss-btn--primary" type="submit" aria-label="Gửi"><i class="fa-solid fa-paper-plane"></i></button></form>
              <button type="button" id="liveAskSyna" class="btn btn-link btn-sm">Hỏi SYNA</button>
              <p id="liveChatNotice" class="mb-1" role="status"></p>
              <p id="liveSynaNotice" class="mb-1" role="status"></p>
              <div class="ss-chat__helper">Tối đa 5 tin trong 20 giây. Không chia sẻ thông tin nhạy cảm.</div>
            </div>
            <div id="liveChatLocked" class="ss-chat__locked" <?= $canChat ? 'hidden' : '' ?>>
              <i class="fa-solid fa-lock me-1"></i>
              <span id="liveChatOfflineHint" <?= $isLive ? 'hidden' : '' ?>>Trò chuyện mở khi phiên phát trực tiếp bắt đầu.</span>
              <span id="liveChatGuestHint" <?= $isLive && !is_logged_in() ? '' : 'hidden' ?>><button type="button" class="btn btn-link btn-sm p-0 align-baseline" data-bs-toggle="modal" data-bs-target="#authModal" data-auth-tab="login">Đăng nhập để bình luận</button></span>
            </div>
          </div>
        </aside>
      </div>
    <?php endif; ?>

    <?php if (!empty($liveSessions) || $liveDirectoryOnly || !empty($aiIdolLive)): ?>
      <section class="ss-sessions" id="live-sessions" aria-labelledby="live-sessions-title">
        <div class="ss-session-toolbar">
          <h2 class="ss-session-title" id="live-sessions-title">Tất cả phiên Live</h2>
          <div class="ss-filters" role="group" aria-label="Lọc phiên Live" hidden>
            <button type="button" class="is-active" data-session-filter="all" aria-pressed="true">Tất cả</button>
            <button type="button" data-session-filter="live" aria-pressed="false">Đang phát</button>
            <button type="button" data-session-filter="scheduled,ready" aria-pressed="false">Sắp phát</button>
            <button type="button" data-session-filter="replay_ready" aria-pressed="false">Xem lại</button>
            <?php if (array_filter($liveSessions, static fn($session) => !empty($session['discovery_topic']))): ?>
              <button type="button" data-session-filter="profile" aria-pressed="false">Theo hồ sơ da</button>
            <?php endif; ?>
          </div>
        </div>
        <div class="ss-session-grid" id="liveSessionGrid">
          <?php if (!empty($aiIdolLive)): ?>
            <article class="ss-card ss-session" data-status="live">
              <div class="ss-session__thumb ss-session__thumb--empty"><i class="fa-solid fa-video" aria-hidden="true"></i><span class="ss-badge ss-badge--live">ĐANG PHÁT</span></div>
              <div class="ss-session__body">
                <strong><?= h($aiIdolLive['name'] ?? 'Live cùng Syna') ?></strong>
                <small>Syna · AI Idol</small>
                <a class="ss-btn ss-btn--primary" href="<?= h(BASE_URL . '/index.php?r=live&view=syna#aiIdolLive') ?>">Xem Syna</a>
              </div>
            </article>
          <?php endif; ?>
          <?php foreach ($liveSessions as $session):
            $sessionStatus = (string)($session['status'] ?? 'scheduled');
            $sessionImage = (string)($session['thumbnail'] ?? '');
            $sessionStreamer = trim((string)preg_replace('/\s*&?\s*AI\s*Co[-\s]?Host\b/iu', '', (string)($session['streamer'] ?? '')));
            [$sessionLabel, $sessionTone] = $statusLabels[$sessionStatus] ?? $statusLabels['none'];
            $topic = (string)($session['discovery_topic'] ?? '');
            $action = match ($sessionStatus) {
                'live' => 'Xem ngay', 'replay_ready' => 'Mở bản ghi', default => 'Xem chi tiết',
            };
          ?>
            <article class="ss-card ss-session" data-status="<?= h($sessionStatus) ?>" data-skin-match="<?= $topic !== '' ? '1' : '0' ?>">
              <div class="ss-session__thumb <?= $sessionImage === '' ? 'ss-session__thumb--empty' : '' ?>">
                <?php if ($sessionImage !== ''): ?><img src="<?= h($sessionImage) ?>" alt="" loading="lazy"><?php else: ?><i class="fa-solid fa-video" aria-hidden="true"></i><?php endif; ?>
                <span class="ss-badge ss-badge--<?= h($sessionTone) ?>"><?= h($sessionLabel) ?></span>
              </div>
              <div class="ss-session__body">
                <strong><?= h($session['title'] ?? '') ?></strong>
                <small><?= h($sessionStreamer ?: 'SkinSyntax') ?><?php if ($sessionStatus === 'live'): ?> · <?= (int)($session['viewers'] ?? 0) ?> đang xem<?php endif; ?></small>
                <?php if ($topic !== ''): ?><span class="ss-session__match">Theo hồ sơ · <?= h($topic) ?></span><?php endif; ?>
                <a class="ss-btn <?= $sessionStatus === 'live' ? 'ss-btn--primary' : 'ss-btn--ghost' ?>" href="<?= h(BASE_URL . '/index.php?r=live&id=' . rawurlencode((string)$session['id'])) ?>"><?= h($action) ?></a>
              </div>
            </article>
          <?php endforeach; ?>
        </div>
        <p id="liveSessionEmpty" class="mt-3" role="status" <?= empty($liveSessions) && empty($aiIdolLive) ? '' : 'hidden' ?>>Chưa có phiên nào trong mục này.</p>
      </section>
    <?php endif; ?>
  </div>
</main>

<script>
(() => {
  const directory = document.getElementById('live-sessions');
  if (directory) {
    const controls = directory.querySelectorAll('[data-session-filter]');
    const sessions = directory.querySelectorAll('.ss-session');
    const emptyState = document.getElementById('liveSessionEmpty');
    if (sessions.length) directory.querySelector('.ss-filters').hidden = false;
    controls.forEach(control => control.addEventListener('click', () => {
      const filter = control.dataset.sessionFilter;
      let shown = 0;
      sessions.forEach(session => {
        const visible = filter === 'all' || (filter === 'profile'
          ? session.dataset.skinMatch === '1'
          : filter.split(',').includes(session.dataset.status));
        session.hidden = !visible;
        if (visible) shown++;
      });
      controls.forEach(item => {
        item.classList.toggle('is-active', item === control);
        item.setAttribute('aria-pressed', String(item === control));
      });
      emptyState.hidden = shown > 0;
    }));
  }
  document.getElementById('liveDiscoveryAsk')?.addEventListener('click', () => {
    const ask = document.getElementById('liveAskSyna');
    if (ask && !ask.hidden && !ask.closest('[hidden]')) ask.click();
  });
})();
</script>

<?php if ($hasLive): ?>
<script src="https://cdn.jsdelivr.net/npm/livekit-client@2.1.2/dist/livekit-client.umd.min.js" crossorigin="anonymous"></script>
<?php foreach (['live-studio-media.js', 'live-commerce.js'] as $file): ?>
<script src="<?= h(BASE_URL . '/assets/js/' . $file . '?v=' . filemtime(dirname(__DIR__) . '/public/assets/js/' . $file)) ?>" defer></script>
<?php endforeach; ?>
<?php endif; ?>

<?php require_once __DIR__ . '/layouts/footer.php'; ?>
