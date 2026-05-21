<?php
$aiChatUser = $_SESSION['user'] ?? [];
$aiChatIdentitySource = trim((string)($aiChatUser['email'] ?? ''));
if ($aiChatIdentitySource === '') {
  $aiChatIdentitySource = trim((string)($aiChatUser['id'] ?? ''));
}
if ($aiChatIdentitySource === '') {
  $aiChatIdentitySource = 'guest';
}

$aiChatStorageScope = strtolower((string)preg_replace('/[^a-zA-Z0-9_-]+/', '-', $aiChatIdentitySource));
$aiChatStorageScope = trim($aiChatStorageScope, '-');
if ($aiChatStorageScope === '') {
  $aiChatStorageScope = 'guest';
}

// Lấy hồ sơ da từ khảo sát (nếu đã đăng nhập)
$aiChatSkinProfile = null;
$aiChatEmail = trim((string)($aiChatUser['email'] ?? ''));
if ($aiChatEmail !== '' && $pdo instanceof PDO) {
  try {
    if (class_exists('TaiKhoan')) {
        $taiKhoanModel = new TaiKhoan($pdo);
        $skinProfile = $taiKhoanModel->getSkinProfileByEmail($aiChatEmail);
        $khachHang = $taiKhoanModel->getKhachHangByEmail($aiChatEmail);
        
        if ($skinProfile && !empty($skinProfile['loai_da'])) {
            $aiChatSkinProfile = [
                'loai_da'   => trim((string)$skinProfile['loai_da']),
                'van_de_da' => trim((string)($skinProfile['van_de_da'] ?? '')),
                'ngan_sach' => (int)($skinProfile['ngan_sach'] ?? 0),
                'thanh_phan_tranh' => trim((string)($khachHang['thanh_phan_tranh'] ?? '')),
            ];
        }
    }
  } catch (Throwable $e) {
    $aiChatSkinProfile = null;
  }
}
?>
<?php if ($pdo instanceof PDO): ?>
  <div class="ai-chat-widget" data-ai-chat-widget>
    <button class="ai-chat-widget__trigger" type="button" data-ai-chat-toggle aria-expanded="false" aria-controls="aiChatPanel" title="Chat với AI">
      <span class="ai-chat-widget__trigger-icon" aria-hidden="true">
        <span class="ai-chat-widget__trigger-avatar"><i class="fa-solid fa-robot"></i></span>
      </span>
      <span class="ai-chat-widget__trigger-text">
        <strong>Chat với AI</strong>
        <small>Gợi ý skincare</small>
      </span>
    </button>

    <section class="ai-chat-widget__panel" id="aiChatPanel" data-ai-chat-panel hidden>
      <header class="ai-chat-widget__panel-head">
        <div class="ai-chat-widget__panel-head-main">
          <div class="ai-chat-widget__panel-avatar" aria-hidden="true"><i class="fa-solid fa-robot"></i></div>
          <div>
          <div class="ai-chat-widget__panel-title">SkinSyntax AI Agent</div>
          <div class="ai-chat-widget__panel-subtitle">Phân tích thành phần, cảnh báo conflict và gợi ý sản phẩm kèm hình ảnh.</div>
          <div class="ai-chat-widget__status" data-ai-chat-status>Đã kết nối hệ thống tư vấn.</div>
          </div>
        </div>
        <div class="ai-chat-widget__panel-actions">
          <button class="ai-chat-widget__reset" type="button" data-ai-chat-reset aria-label="Xóa lịch sử chat AI" title="Xóa lịch sử chat AI">
            <i class="fa-solid fa-trash-can"></i>
          </button>
          <button class="ai-chat-widget__expand" type="button" data-ai-chat-expand aria-pressed="false" aria-label="Phóng to chat AI">
            <i class="fa-solid fa-expand"></i>
          </button>
          <button class="ai-chat-widget__close" type="button" data-ai-chat-close aria-label="Đóng chat AI">
            <i class="fa-solid fa-xmark"></i>
          </button>
        </div>
      </header>

      <div class="ai-chat-widget__quick-actions">
        <button type="button" class="ai-chat-widget__quick-chip" data-ai-chat-prompt="Phân tích nhanh giỏ hàng hiện tại và cảnh báo các cặp chất có thể xung đột.">Phân tích giỏ hàng</button>
        <button type="button" class="ai-chat-widget__quick-chip" data-ai-chat-prompt="Tìm giúp tôi vài sản phẩm phù hợp với da dầu mụn và giải thích ngắn gọn.">Da dầu mụn</button>
        <button type="button" class="ai-chat-widget__quick-chip" data-ai-chat-prompt="Tóm tắt giúp tôi các nhóm hoạt chất treatment phổ biến và cách dùng an toàn trong routine.">Thành phần</button>
      </div>

<?php if ($aiChatSkinProfile): ?>
      <div class="ai-chat-profile-banner" data-ai-profile-banner
           data-profile="<?= htmlspecialchars(json_encode($aiChatSkinProfile, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), ENT_QUOTES) ?>">
        <div class="ai-chat-profile-banner__head">
          <span class="ai-chat-profile-banner__icon">✨</span>
          <div>
            <strong>Gợi ý theo hồ sơ da của bạn</strong>
            <span class="ai-chat-profile-banner__tag"><?= htmlspecialchars($aiChatSkinProfile['loai_da'], ENT_QUOTES) ?></span>
          </div>
        </div>
        <p class="ai-chat-profile-banner__desc">Chọn loại sản phẩm để AI tìm ngay theo da và ngân sách của bạn:</p>
        <div class="ai-chat-profile-banner__chips">
          <button type="button" class="ai-chat-profile-chip" data-category="Toner / Nước Cân Bằng Da">💧 Toner</button>
          <button type="button" class="ai-chat-profile-chip" data-category="Sữa Rửa Mặt">🧴 Sữa rửa mặt</button>
          <button type="button" class="ai-chat-profile-chip" data-category="Tẩy Trang Mặt">🌿 Tẩy trang</button>
          <button type="button" class="ai-chat-profile-chip" data-category="Serum / Tinh Chất">⚗️ Serum</button>
          <button type="button" class="ai-chat-profile-chip" data-category="Kem / Gel / Dầu Dưỡng">🫧 Kem dưỡng</button>
          <button type="button" class="ai-chat-profile-chip" data-category="Chống Nắng Da Mặt">☀️ Chống nắng</button>
          <button type="button" class="ai-chat-profile-chip" data-category="Mặt Nạ Giấy">🎭 Mặt nạ</button>
          <button type="button" class="ai-chat-profile-chip" data-category="Hỗ Trợ Trị Mụn">🔬 Trị mụn</button>
        </div>
      </div>
<?php endif; ?>

      <div class="ai-chat-widget__stream" data-ai-chat-stream>
        <div class="ai-chat-widget__welcome" data-ai-chat-welcome>
          <div class="ai-chat-widget__welcome-badge">AI Agent</div>
          <h4>Tư vấn skincare dựa trên dữ liệu thật</h4>
          <p>Bạn có thể hỏi về thành phần, sản phẩm phù hợp, routine treatment hoặc yêu cầu quét giỏ hàng để phát hiện các cặp hoạt chất cần tránh. Khi có dữ liệu, phần gợi ý sẽ lấy kèm hình ảnh sản phẩm.</p>
        </div>
      </div>

      <form class="ai-chat-widget__form" data-ai-chat-form>
        <div class="ai-chat-widget__composer">
          <textarea class="form-control ai-chat-widget__textarea" rows="2" data-ai-chat-input placeholder="Hỏi AI về thành phần, sản phẩm, routine hoặc phân tích giỏ hàng..." required></textarea>
          <button class="btn ai-chat-widget__submit" type="submit" data-ai-chat-submit>Gửi</button>
        </div>
        <div class="ai-chat-widget__helper">AI ưu tiên dữ liệu thật từ cửa hàng, gồm tên, giá, mô tả và hình ảnh sản phẩm khi hệ thống có sẵn.</div>
      </form>
    </section>
  </div>

  <style>
    .ai-chat-widget {
      position: fixed;
      right: 16px;
      bottom: 88px;
      z-index: 1084;
      display: flex;
      flex-direction: column;
      align-items: flex-end;
      gap: 12px;
      transition: bottom 0.28s cubic-bezier(0.22, 1, 0.36, 1);
    }

    .ai-chat-widget__trigger {
      display: inline-flex;
      align-items: center;
      gap: 10px;
      border: 0;
      border-radius: 999px;
      min-width: 228px;
      max-width: min(70vw, 286px);
      padding: 8px 14px;
      background: linear-gradient(135deg, #102542 0%, #1c4f82 100%);
      color: #f8fbff;
      box-shadow: 0 18px 36px rgba(16, 37, 66, 0.26);
      font-weight: 700;
      transition: transform 0.22s ease, box-shadow 0.22s ease;
    }

    .ai-chat-widget__trigger:hover {
      transform: translateY(-2px);
      box-shadow: 0 24px 42px rgba(16, 37, 66, 0.28);
    }

    .ai-chat-widget.is-open .ai-chat-widget__trigger {
      display: none;
    }

    .ai-chat-widget__trigger-icon {
      width: 38px;
      height: 38px;
      border-radius: 50%;
      display: grid;
      place-items: center;
      background: rgba(255, 255, 255, 0.08);
      flex: 0 0 auto;
    }

    .ai-chat-widget__trigger-avatar {
      width: 30px;
      height: 30px;
      border-radius: 50%;
      display: grid;
      place-items: center;
      background: radial-gradient(circle at top, #8cc2ff 0%, #4d78a9 60%, #27435f 100%);
      color: #eff8ff;
      font-size: 14px;
      box-shadow: inset 0 1px 0 rgba(255, 255, 255, 0.16);
    }

    .ai-chat-widget__trigger-text {
      display: grid;
      text-align: left;
      line-height: 1.1;
      min-width: 0;
    }

    .ai-chat-widget__trigger-text strong {
      font-size: 14px;
    }

    .ai-chat-widget__trigger-text small {
      color: rgba(248, 251, 255, 0.76);
      font-size: 10px;
      font-weight: 600;
      white-space: nowrap;
      overflow: hidden;
      text-overflow: ellipsis;
    }

    .ai-chat-widget__panel {
      width: min(360px, calc(100vw - 24px));
      max-height: min(68vh, 520px);
      border-radius: 22px;
      overflow: hidden;
      background: #f9fcff;
      border: 1px solid #dce7f3;
      box-shadow: 0 30px 70px rgba(15, 23, 42, 0.24);
      display: flex;
      flex-direction: column;
      opacity: 0;
      transform: translateY(18px) scale(0.96);
      transform-origin: bottom right;
      pointer-events: none;
      transition: opacity 0.22s ease, transform 0.28s cubic-bezier(0.22, 1, 0.36, 1);
    }

    .ai-chat-widget.is-expanded .ai-chat-widget__panel {
      width: min(760px, calc(100vw - 28px));
      max-height: min(84vh, 760px) !important;
    }

    .ai-chat-widget__panel[hidden] {
      display: none !important;
    }

    .ai-chat-widget.is-open .ai-chat-widget__panel {
      opacity: 1;
      transform: translateY(0) scale(1);
      pointer-events: auto;
    }

    .ai-chat-widget__panel-head {
      display: flex;
      justify-content: space-between;
      align-items: flex-start;
      gap: 12px;
      padding: 16px 18px;
      background: linear-gradient(135deg, #f4f7f2 0%, #fffdf8 100%);
      border-bottom: 1px solid #dfe6db;
    }

    .ai-chat-widget__panel-head-main {
      display: grid;
      grid-template-columns: 42px minmax(0, 1fr);
      gap: 12px;
      align-items: start;
    }

    .ai-chat-widget__panel-avatar {
      width: 42px;
      height: 42px;
      border-radius: 16px;
      display: grid;
      place-items: center;
      background: linear-gradient(135deg, #173a5c 0%, #3e7fc4 100%);
      color: #f7fbff;
      font-size: 18px;
      box-shadow: 0 12px 20px rgba(28, 79, 130, 0.18);
    }

    .ai-chat-widget__panel-title {
      font-size: 17px;
      font-weight: 800;
      color: #173528;
    }

    .ai-chat-widget__panel-subtitle {
      margin-top: 4px;
      color: #647468;
      font-size: 13px;
      line-height: 1.55;
    }

    .ai-chat-widget__status {
      margin-top: 10px;
      display: inline-flex;
      align-items: center;
      gap: 8px;
      padding: 6px 10px;
      border-radius: 999px;
      background: #edf6ef;
      color: #25633d;
      font-size: 11px;
      font-weight: 800;
      letter-spacing: 0.03em;
    }

    .ai-chat-widget__status::before {
      content: '';
      width: 8px;
      height: 8px;
      border-radius: 50%;
      background: currentColor;
      opacity: 0.85;
    }

    .ai-chat-widget__status.is-fallback {
      background: #fff3df;
      color: #a15c00;
    }

    .ai-chat-widget__panel-actions {
      display: flex;
      gap: 8px;
      flex: 0 0 auto;
    }

    .ai-chat-widget__reset,
    .ai-chat-widget__expand,
    .ai-chat-widget__close {
      width: 38px;
      height: 38px;
      border-radius: 50%;
      border: 1px solid #d7e0d3;
      background: #fff;
      color: #3c5b47;
    }

    .ai-chat-widget__expand[aria-pressed='true'] {
      background: #eaf5ee;
      color: #1d5a3c;
      border-color: #b7d2bf;
    }

    .ai-chat-widget__reset:hover {
      background: #fff5f0;
      color: #a24a18;
      border-color: #edc7b3;
    }

    .ai-chat-widget__quick-actions {
      display: flex;
      gap: 8px;
      flex-wrap: wrap;
      padding: 12px 14px 0;
    }

    .ai-chat-widget__quick-chip {
      border: 1px solid #dce7db;
      background: #fff;
      color: #234234;
      border-radius: 999px;
      padding: 8px 12px;
      font-size: 12px;
      font-weight: 700;
    }

    .ai-chat-widget__stream {
      flex: 1;
      min-height: 180px;
      max-height: 280px;
      overflow-y: auto;
      padding: 14px;
      display: flex;
      flex-direction: column;
      gap: 12px;
      background: linear-gradient(180deg, #fbfcf8 0%, #ffffff 100%);
    }

    .ai-chat-widget.is-expanded .ai-chat-widget__stream {
      max-height: 500px;
    }

    .ai-chat-widget__welcome {
      border-radius: 20px;
      background: linear-gradient(135deg, #173528 0%, #2f6a4f 100%);
      color: #f8fbff;
      padding: 16px;
      box-shadow: 0 16px 30px rgba(23, 53, 40, 0.22);
    }

    .ai-chat-widget__welcome-badge {
      display: inline-flex;
      border-radius: 999px;
      background: rgba(255, 255, 255, 0.14);
      padding: 6px 10px;
      font-size: 11px;
      font-weight: 800;
      letter-spacing: 0.08em;
      margin-bottom: 12px;
    }

    .ai-chat-widget__welcome h4 {
      font-size: 16px;
      margin-bottom: 8px;
      font-weight: 800;
    }

    .ai-chat-widget__welcome p {
      margin: 0;
      font-size: 13px;
      line-height: 1.55;
      color: rgba(248, 251, 255, 0.84);
    }

    .ai-chat-widget__bubble-row {
      display: flex;
    }

    .ai-chat-widget__bubble-row--user {
      justify-content: flex-end;
    }

    .ai-chat-widget__bubble-row--assistant {
      justify-content: flex-start;
    }

    .ai-chat-widget__bubble-wrap {
      display: flex;
      align-items: flex-end;
      gap: 10px;
      max-width: 92%;
    }

    .ai-chat-widget__bubble-avatar {
      width: 34px;
      height: 34px;
      flex: 0 0 34px;
      border-radius: 12px;
      display: grid;
      place-items: center;
      background: linear-gradient(135deg, #173a5c 0%, #4d8fd0 100%);
      color: #f4fbff;
      box-shadow: 0 10px 18px rgba(23, 58, 92, 0.14);
    }

    .ai-chat-widget__bubble {
      max-width: 100%;
      border-radius: 18px;
      padding: 12px 14px;
      box-shadow: 0 12px 24px rgba(15, 23, 42, 0.08);
    }

    .ai-chat-widget__bubble--user {
      background: #e5f5ea;
      color: #183126;
      border: 1px solid #c7e5d1;
    }

    .ai-chat-widget__bubble--assistant {
      background: #ffffff;
      color: #172033;
      border: 1px solid #e2e8df;
    }

    .ai-chat-widget__bubble-author {
      font-size: 12px;
      font-weight: 800;
      margin-bottom: 6px;
    }

    .ai-chat-widget__bubble-text {
      line-height: 1.6;
      word-break: break-word;
      font-size: 13.5px;
    }

    .ai-chat-widget__bubble-text p { margin: 0 0 8px; }
    .ai-chat-widget__bubble-text p:last-child { margin-bottom: 0; }
    .ai-chat-widget__bubble-text strong { font-weight: 700; color: #1a3a2a; }
    .ai-chat-widget__bubble-text ul,
    .ai-chat-widget__bubble-text ol { margin: 4px 0 8px 18px; padding: 0; }
    .ai-chat-widget__bubble-text li { margin-bottom: 3px; }
    .ai-chat-widget__bubble-text h1,
    .ai-chat-widget__bubble-text h2,
    .ai-chat-widget__bubble-text h3,
    .ai-chat-widget__bubble-text h4 { font-size: 13.5px; font-weight: 700; margin: 10px 0 4px; color: #1a3a2a; }
    .ai-chat-widget__bubble-text code { background: #eef3ed; padding: 1px 5px; border-radius: 4px; font-size: 12px; }
    .ai-chat-widget__bubble-text hr { border: none; border-top: 1px dashed #d9e0d5; margin: 8px 0; }

    .ai-chat-widget__fallback-note {
      margin-bottom: 10px;
      display: inline-flex;
      align-items: center;
      gap: 8px;
      padding: 7px 10px;
      border-radius: 12px;
      background: #fff7e7;
      color: #8a5800;
      font-size: 12px;
      font-weight: 700;
    }

    .ai-chat-widget__fallback-note::before {
      content: '';
      width: 8px;
      height: 8px;
      border-radius: 50%;
      background: currentColor;
      opacity: 0.8;
    }

    .ai-chat-widget__meta-block {
      margin-top: 10px;
      padding-top: 10px;
      border-top: 1px dashed #d9e0d5;
      display: grid;
      gap: 8px;
    }

    .ai-chat-widget__meta-title {
      font-size: 12px;
      font-weight: 800;
      color: #44624e;
      text-transform: uppercase;
      letter-spacing: 0.06em;
    }

    .ai-chat-widget__meta-list {
      display: grid;
      gap: 8px;
      font-size: 13px;
      color: #475569;
    }

    .ai-chat-widget__meta-card {
      border: 1px solid #e2e8df;
      border-radius: 16px;
      padding: 12px 13px;
      background: #fbfdfb;
      box-shadow: inset 0 1px 0 rgba(255, 255, 255, 0.75);
    }

    .ai-chat-widget__meta-card-product {
      display: grid;
      grid-template-columns: 52px minmax(0, 1fr);
      gap: 10px;
      align-items: start;
    }

    .ai-chat-widget__meta-card-image {
      width: 52px;
      height: 52px;
      border-radius: 12px;
      overflow: hidden;
      background: linear-gradient(180deg, #f0f6f8 0%, #e6f0eb 100%);
      border: 1px solid #dde7e2;
      display: grid;
      place-items: center;
      color: #7a8e84;
      font-size: 18px;
    }

    .ai-chat-widget__meta-card-image img {
      width: 100%;
      height: 100%;
      object-fit: cover;
      display: block;
    }

    .ai-chat-widget__meta-card-body {
      min-width: 0;
    }

    .ai-chat-widget__meta-card-thumb-link,
    .ai-chat-widget__meta-card-title-link {
      text-decoration: none;
      color: inherit;
    }

    .ai-chat-widget__meta-card-open {
      padding: 0;
      border: 0;
      background: transparent;
      text-align: inherit;
      color: inherit;
      cursor: pointer;
      width: 100%;
    }

    .ai-chat-widget__meta-card-title-link:hover .ai-chat-widget__meta-card-title {
      color: #1d6b46;
    }

    .ai-chat-widget__product-group {
      display: grid;
      gap: 10px;
      margin-bottom: 12px;
    }

    .ai-chat-widget__meta-card--warning {
      background: #fffaf1;
      border-color: #f3dec0;
    }

    .ai-chat-widget__inline-link {
      color: #2a6a4c;
      text-decoration: underline;
      font-weight: 600;
      transition: color 0.15s ease;
    }
    
    .ai-chat-widget__inline-link:hover {
      color: #17422f;
    }

    .ai-chat-widget__meta-card-title-link {
      text-decoration: none !important;
      color: inherit !important;
      display: block;
      text-align: left;
    }

    .ai-chat-widget__meta-card-title-link:hover .ai-chat-widget__meta-card-title {
      color: #2a6a4c;
      text-decoration: underline;
    }

    .ai-chat-widget__meta-card-thumb-link {
      display: block;
      flex-shrink: 0;
      transition: transform 0.2s ease;
      cursor: pointer;
    }

    .ai-chat-widget__meta-card-thumb-link:hover {
      transform: scale(1.05);
    }

    .ai-chat-widget__meta-card-title {
      color: #1d3526;
      font-weight: 800;
      line-height: 1.45;
    }

    .ai-chat-widget__meta-card-subtitle {
      margin-top: 4px;
      color: #607064;
      line-height: 1.55;
    }

    .ai-chat-widget__meta-card-price {
      margin-top: 6px;
      color: #2c6a4a;
      font-weight: 800;
    }

    .ai-chat-widget__meta-card-actions {
      margin-top: 10px;
      display: flex;
      flex-wrap: wrap;
      gap: 8px;
      align-items: center;
    }

    .ai-chat-widget__meta-card-link,
    .ai-chat-widget__meta-card-toggle {
      display: inline-flex;
      align-items: center;
      justify-content: center;
      min-height: 34px;
      padding: 0 12px;
      border-radius: 999px;
      border: 1px solid #d5e3d9;
      background: #ffffff;
      color: #1d4f37;
      font-size: 12px;
      font-weight: 700;
      text-decoration: none;
      transition: transform 0.18s ease, box-shadow 0.18s ease, background 0.18s ease;
    }

    .ai-chat-widget__meta-card-link:hover,
    .ai-chat-widget__meta-card-toggle:hover {
      transform: translateY(-1px);
      box-shadow: 0 10px 18px rgba(15, 23, 42, 0.08);
      background: #f5fbf7;
      color: #143b2a;
    }

    .ai-chat-widget__meta-card-pricechip {
      display: inline-flex;
      align-items: center;
      min-height: 34px;
      padding: 0 12px;
      border-radius: 999px;
      background: #eaf7ef;
      color: #1d6b46;
      font-size: 12px;
      font-weight: 800;
      border: 1px solid #cae7d5;
    }

    .ai-chat-widget__meta-card-url {
      margin-top: 8px;
      padding: 10px 12px;
      border-radius: 12px;
      background: #f4f8f5;
      border: 1px dashed #cfe0d4;
      font-size: 12px;
      color: #476254;
      word-break: break-all;
    }

    .ai-chat-widget__meta-card-url[hidden] {
      display: none !important;
    }

    .ai-chat-widget__typing-row {
      display: flex;
      justify-content: flex-start;
    }

    .ai-chat-widget__typing-bubble {
      display: inline-flex;
      align-items: center;
      gap: 5px;
      padding: 12px 18px;
      border-radius: 20px;
      background: #fff;
      border: 1px solid #e2e8df;
      box-shadow: 0 4px 12px rgba(15,23,42,0.06);
    }

    .ai-chat-widget__typing-bubble span {
      width: 7px;
      height: 7px;
      border-radius: 50%;
      background: #6b8f7b;
      animation: ai-chat-blink 1.2s infinite ease-in-out;
    }

    .ai-chat-widget__typing-bubble span:nth-child(2) { animation-delay: 0.2s; }
    .ai-chat-widget__typing-bubble span:nth-child(3) { animation-delay: 0.4s; }

    @keyframes ai-chat-blink {
      0%, 80%, 100% { transform: scale(0.7); opacity: 0.35; }
      40% { transform: scale(1); opacity: 1; }
    }

    .ai-chat-widget__form {
      border-top: 1px solid #e2ebf5;
      padding: 14px;
      background: #fff;
    }

    .ai-chat-widget__composer {
      display: grid;
      grid-template-columns: minmax(0, 1fr) auto;
      gap: 10px;
      align-items: end;
    }

    .ai-chat-widget__textarea {
      resize: none;
      min-height: 46px;
      max-height: 120px;
      border-radius: 18px;
      border-color: #cfdeec;
      background: #fbfdff;
      padding: 11px 13px;
      line-height: 1.55;
    }

    .ai-chat-widget__textarea:focus {
      border-color: #84b89a;
      box-shadow: 0 0 0 0.2rem rgba(74, 140, 97, 0.12);
    }

    .ai-chat-widget__submit {
      border: 0;
      height: 46px;
      min-width: 78px;
      border-radius: 16px;
      background: #2f6a4f;
      color: #fff;
      font-weight: 800;
      padding-inline: 16px;
    }

    .ai-chat-widget__submit:hover {
      background: #24543f;
      color: #fff;
    }

    .ai-chat-widget__submit:disabled {
      opacity: 0.7;
      cursor: wait;
    }

    .ai-chat-widget__helper {
      margin-top: 10px;
      color: #64748b;
      font-size: 12px;
      line-height: 1.55;
    }

    @media (max-width: 767.98px) {
      .ai-chat-widget {
        right: 12px;
        left: 12px;
        bottom: 76px;
        align-items: stretch;
      }

      .ai-chat-widget__trigger {
        justify-content: flex-start;
        min-width: 0;
        max-width: none;
        width: 100%;
      }

      .ai-chat-widget.is-open .ai-chat-widget__trigger {
        display: none;
      }

      .ai-chat-widget__panel {
        width: 100%;
        max-height: min(66vh, 480px);
      }

      .ai-chat-widget.is-expanded .ai-chat-widget__panel {
        width: 100%;
        max-height: 88vh !important;
      }

      .ai-chat-widget__stream {
        max-height: 232px;
      }

      .ai-chat-widget.is-expanded .ai-chat-widget__stream {
        max-height: 56vh;
      }

      .ai-chat-widget__composer {
        grid-template-columns: 1fr;
      }

      .ai-chat-widget__submit {
        width: 100%;
      }

      .ai-chat-widget__bubble-wrap {
        max-width: 100%;
      }
    }
  </style>

  <script>
    document.addEventListener('DOMContentLoaded', function () {
      var widget = document.querySelector('[data-ai-chat-widget]');
      if (!widget) {
        return;
      }

      var panel = widget.querySelector('[data-ai-chat-panel]');
      var trigger = widget.querySelector('[data-ai-chat-toggle]');
      var closeButton = widget.querySelector('[data-ai-chat-close]');
      var expandButton = widget.querySelector('[data-ai-chat-expand]');
      var resetButton = widget.querySelector('[data-ai-chat-reset]');
      var stream = widget.querySelector('[data-ai-chat-stream]');
      var welcome = widget.querySelector('[data-ai-chat-welcome]');
      var form = widget.querySelector('[data-ai-chat-form]');
      var input = widget.querySelector('[data-ai-chat-input]');
      var submit = widget.querySelector('[data-ai-chat-submit]');
      var status = widget.querySelector('[data-ai-chat-status]');
      var quickPrompts = widget.querySelectorAll('[data-ai-chat-prompt]');
      var storageScope = <?= json_encode($aiChatStorageScope, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
      var storageKey = 'aiChatMessagesV4:' + storageScope;
      var closeTimer = null;
      var messages = [];
      var defaultBottom = parseFloat(window.getComputedStyle(widget).bottom || '88') || 88;
      var expandedStorageKey = 'aiChatExpandedV4:' + storageScope;

      var escapeHtml = function (value) {
        return String(value || '')
          .replace(/&/g, '&amp;')
          .replace(/</g, '&lt;')
          .replace(/>/g, '&gt;')
          .replace(/"/g, '&quot;')
          .replace(/'/g, '&#39;');
      };

      var formatMarkdown = function (text) {
        var safe = escapeHtml(text);
        // headers
        safe = safe.replace(/^####\s+(.+)$/gm, '<h4>$1</h4>');
        safe = safe.replace(/^###\s+(.+)$/gm, '<h3>$1</h3>');
        safe = safe.replace(/^##\s+(.+)$/gm, '<h2>$1</h2>');
        safe = safe.replace(/^#\s+(.+)$/gm, '<h1>$1</h1>');
        // bold + italic
        safe = safe.replace(/\*\*\*(.+?)\*\*\*/g, '<strong><em>$1</em></strong>');
        safe = safe.replace(/\*\*(.+?)\*\*/g, '<strong>$1</strong>');
        safe = safe.replace(/\*(.+?)\*/g, '<em>$1</em>');
        // inline code
        safe = safe.replace(/`([^`]+)`/g, '<code>$1</code>');
        // links
        safe = safe.replace(/\[([^\]]+)\]\(([^)]+)\)/g, '<a href="$2" class="ai-chat-widget__inline-link">$1</a>');
        // hr
        safe = safe.replace(/^---$/gm, '<hr>');
        // unordered list items
        safe = safe.replace(/^[\*\-]\s+(.+)$/gm, '<li>$1</li>');
        // numbered list items
        safe = safe.replace(/^\d+\.\s+(.+)$/gm, '<li>$1</li>');
        // wrap consecutive <li> in <ul>
        safe = safe.replace(/((?:<li>.*?<\/li>\n?)+)/g, '<ul>$1</ul>');
        // paragraphs: split by double newlines
        safe = safe.split(/\n{2,}/).map(function (block) {
          block = block.trim();
          if (!block) return '';
          if (/^<(h[1-4]|ul|ol|hr|li)/.test(block)) return block;
          return '<p>' + block.replace(/\n/g, '<br>') + '</p>';
        }).join('');
        return safe;
      };

      var scrollToBottom = function () {
        if (stream) {
          stream.scrollTop = stream.scrollHeight;
        }
      };

      var syncLayout = function (supportState) {
        var isMobile = window.matchMedia('(max-width: 767.98px)').matches;
        var fallbackBottom = isMobile ? 84 : defaultBottom;
        var nextBottom = fallbackBottom;

        if (!isMobile) {
          var detail = supportState || null;
          if (!detail) {
            var supportWidget = document.querySelector('[data-support-chat-widget]');
            if (supportWidget) {
              detail = {
                height: supportWidget.offsetHeight || 0,
                bottom: parseFloat(window.getComputedStyle(supportWidget).bottom || '22') || 22,
              };
            }
          }

          if (detail && detail.height > 0) {
            nextBottom = Math.max(defaultBottom, Math.round(detail.bottom + detail.height + 12));
          }
        }

        widget.style.bottom = nextBottom + 'px';

        if (panel) {
          var viewportPadding = isMobile ? 16 : 24;
          var availableHeight = Math.max(300, window.innerHeight - nextBottom - viewportPadding);
          panel.style.maxHeight = Math.min(isMobile ? 580 : 620, availableHeight) + 'px';
        }
      };

      var currencyFormatter = new Intl.NumberFormat('vi-VN');

      var renderMetaBlock = function (title, items) {
        if (!Array.isArray(items) || !items.length) {
          return '';
        }
        return '<div class="ai-chat-widget__meta-block">'
          + '<div class="ai-chat-widget__meta-title">' + escapeHtml(title) + '</div>'
          + '<div class="ai-chat-widget__meta-list">' + items.map(function (item) {
            return '<div>' + item + '</div>';
          }).join('') + '</div>'
          + '</div>';
      };

      var renderConflictCards = function (items) {
        if (!Array.isArray(items) || !items.length) {
          return [];
        }

        return items.map(function (conflict) {
          var title = escapeHtml((conflict.product_a || '') + ' + ' + (conflict.product_b || ''));
          var warning = escapeHtml(conflict.warning || 'Có xung đột cần lưu ý.');
          var recommendation = escapeHtml(conflict.recommendation || 'Nên tách buổi dùng hoặc giảm tần suất khi phối hợp.');
          return '<div class="ai-chat-widget__meta-card ai-chat-widget__meta-card--warning">'
            + '<div class="ai-chat-widget__meta-card-title">' + title + '</div>'
            + '<div class="ai-chat-widget__meta-card-subtitle">' + warning + '</div>'
            + '<div class="ai-chat-widget__meta-card-subtitle">Gợi ý: ' + recommendation + '</div>'
            + '</div>';
        });
      };

      var renderProductCards = function (items) {
        if (!Array.isArray(items) || !items.length) {
          return [];
        }

        var summarizeText = function (value, maxLength) {
          var text = String(value || '').replace(/\s+/g, ' ').trim();
          if (text === '') {
            return 'Có dữ liệu truy xuất từ cửa hàng.';
          }

          if (text.length <= maxLength) {
            return text;
          }

          return text.slice(0, maxLength).replace(/[\s,;:.!?-]+$/g, '') + '...';
        };

        return items.map(function (product) {
          var title = escapeHtml(product.name || 'Sản phẩm liên quan');
          var brand = escapeHtml(product.brand || 'Chưa rõ thương hiệu');
          var rawSummary = product.summary || product.short_description || product.description || product.ingredients || '';
          var description = escapeHtml(summarizeText(rawSummary, 120));
          var price = product.price ? '<div class="ai-chat-widget__meta-card-pricechip">' + escapeHtml(currencyFormatter.format(product.price) + ' đ') + '</div>' : '';
          var imageUrl = String(product.image_url || '').trim();
          var detailUrl = String(product.detail_url || '').trim();
          var linkId = 'ai-product-link-' + String(product.id || title).replace(/[^a-zA-Z0-9_-]/g, '-');
          var fallbackImage = 'data:image/svg+xml,%3Csvg xmlns=%22http://www.w3.org/2000/svg%22 width=%2292%22 height=%2292%22 viewBox=%220 0 92 92%22%3E%3Crect width=%2292%22 height=%2292%22 rx=%2214%22 fill=%22%23edf4ef%22/%3E%3Cpath d=%22M27 60l13-15 9 10 8-9 8 14H27z%22 fill=%22%2396aca0%22/%3E%3Ccircle cx=%2236%22 cy=%2233%22 r=%226%22 fill=%22%23bfd0c5%22/%3E%3C/svg%3E';
          var imageCore = imageUrl !== ''
            ? '<div class="ai-chat-widget__meta-card-image"><img src="' + escapeHtml(imageUrl) + '" alt="' + title + '" loading="lazy" onerror="this.onerror=null;this.src=\'' + fallbackImage + '\';"></div>'
            : '<div class="ai-chat-widget__meta-card-image"><img src="' + fallbackImage + '" alt="' + title + '" loading="lazy"></div>';
          var image = detailUrl !== ''
            ? '<a href="' + escapeHtml(detailUrl) + '" class="ai-chat-widget__meta-card-thumb-link">' + imageCore + '</a>'
            : imageCore;
          var detailAction = detailUrl !== ''
            ? '<a href="' + escapeHtml(detailUrl) + '" class="ai-chat-widget__meta-card-link">Xem chi tiết</a>'
            : '';
          var toggleAction = detailUrl !== ''
            ? '<button type="button" class="ai-chat-widget__meta-card-toggle" data-ai-product-link-toggle data-target="' + escapeHtml(linkId) + '">Hiện link</button>'
            : '';
          var linkBlock = detailUrl !== ''
            ? '<div class="ai-chat-widget__meta-card-url" id="' + escapeHtml(linkId) + '" hidden>' + escapeHtml(detailUrl) + '</div>'
            : '';
          var titleBlock = detailUrl !== ''
            ? '<a href="' + escapeHtml(detailUrl) + '" class="ai-chat-widget__meta-card-title-link"><div class="ai-chat-widget__meta-card-title">' + title + '</div></a>'
            : '<div class="ai-chat-widget__meta-card-title">' + title + '</div>';

          return '<div class="ai-chat-widget__meta-card">'
            + '<div class="ai-chat-widget__meta-card-product">'
            + image
            + '<div class="ai-chat-widget__meta-card-body">'
            + titleBlock
            + '<div class="ai-chat-widget__meta-card-subtitle">' + brand + '</div>'
            + '<div class="ai-chat-widget__meta-card-subtitle">' + description + '</div>'
            + '<div class="ai-chat-widget__meta-card-actions">' + price + detailAction + toggleAction + '</div>'
            + linkBlock
            + '</div>'
            + '</div>'
            + '</div>';
        });
      };

      var updateStatus = function () {
        if (!status) {
          return;
        }

        var latestAssistant = null;
        for (var i = messages.length - 1; i >= 0; i -= 1) {
          if (messages[i] && messages[i].role === 'assistant' && !messages[i].typing) {
            latestAssistant = messages[i];
            break;
          }
        }

        if (latestAssistant && latestAssistant.fallback) {
          status.textContent = String(latestAssistant.statusMessage || 'Đang dùng dữ liệu dự phòng do AI service chưa phản hồi.');
          status.classList.add('is-fallback');
          return;
        }

        status.textContent = 'Đã kết nối hệ thống tư vấn.';
        status.classList.remove('is-fallback');
      };

      var renderMessages = function () {
        if (!stream) {
          return;
        }

        var html = '';
        if (messages.length === 0) {
          if (welcome) {
            welcome.hidden = false;
          }
          scrollToBottom();
          return;
        }

        if (welcome) {
          welcome.hidden = true;
        }

        html = messages.map(function (message) {
          if (message.typing) {
            return '<div class="ai-chat-widget__typing-row">'
              + '<div class="ai-chat-widget__typing-bubble">'
              + '<span></span><span></span><span></span>'
              + '</div></div>';
          }

          var isUser = message.role === 'user';
          var meta = '';
          var contentPrefix = '';
          var contentSuffix = '';
          if (!isUser) {
            if (message.fallback) {
              contentPrefix = '<div class="ai-chat-widget__fallback-note">' + escapeHtml(message.fallbackNote || 'Phản hồi dự phòng từ dữ liệu hệ thống') + '</div>';
            }

            var conflictCards = renderConflictCards(message.conflicts || []);
            var productCards = renderProductCards(message.products || []);
            meta += renderMetaBlock('Conflict Detection', conflictCards);
            if (productCards.length) {
              contentSuffix += '<div class="ai-chat-widget__product-group">' + productCards.join('') + '</div>';
            }
          }

          var formattedContent = isUser ? escapeHtml(message.content) : formatMarkdown(message.content);
          var avatar = isUser
            ? ''
            : '<div class="ai-chat-widget__bubble-avatar" aria-hidden="true"><i class="fa-solid fa-robot"></i></div>';

          return '<div class="ai-chat-widget__bubble-row ' + (isUser ? 'ai-chat-widget__bubble-row--user' : 'ai-chat-widget__bubble-row--assistant') + '">' 
            + '<div class="ai-chat-widget__bubble-wrap">'
            + avatar
            + '<div class="ai-chat-widget__bubble ' + (isUser ? 'ai-chat-widget__bubble--user' : 'ai-chat-widget__bubble--assistant') + '">' 
            + '<div class="ai-chat-widget__bubble-author">' + (isUser ? 'Bạn' : 'SkinSyntax AI') + '</div>'
            + contentPrefix
            + '<div class="ai-chat-widget__bubble-text">' + formattedContent + '</div>'
            + contentSuffix
            + meta
            + '</div>'
            + '</div>'
            + '</div>';
        }).join('');

        stream.innerHTML = (welcome ? welcome.outerHTML : '') + html;
        welcome = stream.querySelector('[data-ai-chat-welcome]');
        if (welcome) {
          welcome.hidden = messages.length > 0;
        }
        updateStatus();
        scrollToBottom();
      };

      var saveMessages = function () {
        try {
          window.sessionStorage.setItem(storageKey, JSON.stringify(messages.slice(-12)));
        } catch (error) {
        }
      };

      var clearMessages = function () {
        messages = [];
        try {
          window.sessionStorage.removeItem(storageKey);
        } catch (error) {
        }
        renderMessages();
        if (input) {
          input.value = '';
          input.style.height = 'auto';
        }
      };

      var addMessage = function (message) {
        messages.push(message);
        saveMessages();
        renderMessages();
      };

      var setLoading = function (loading) {
        if (submit) {
          submit.disabled = loading;
          submit.textContent = loading ? 'Đang trả lời...' : 'Gửi';
        }
      };

      var syncExpandedState = function () {
        var expanded = widget.classList.contains('is-expanded');
        if (expandButton) {
          expandButton.setAttribute('aria-pressed', expanded ? 'true' : 'false');
          expandButton.innerHTML = expanded
            ? '<i class="fa-solid fa-compress"></i>'
            : '<i class="fa-solid fa-expand"></i>';
        }
        try {
          window.sessionStorage.setItem(expandedStorageKey, expanded ? '1' : '0');
        } catch (error) {
        }
      };

      var openWidget = function () {
        if (!panel || !trigger) {
          return;
        }
        if (closeTimer) {
          window.clearTimeout(closeTimer);
          closeTimer = null;
        }
        panel.hidden = false;
        window.requestAnimationFrame(function () {
          syncLayout();
          trigger.setAttribute('aria-expanded', 'true');
          widget.classList.add('is-open');
          if (input) {
            input.focus();
          }
          scrollToBottom();
        });
      };

      var closeWidget = function () {
        if (!panel || !trigger) {
          return;
        }
        trigger.setAttribute('aria-expanded', 'false');
        widget.classList.remove('is-open');
        if (closeTimer) {
          window.clearTimeout(closeTimer);
        }
        closeTimer = window.setTimeout(function () {
          panel.hidden = true;
          closeTimer = null;
        }, 280);
      };

      var sendMessage = function (text) {
        var content = String(text || '').trim();
        if (content === '') {
          return;
        }

        openWidget();
        addMessage({ role: 'user', content: content });
        setLoading(true);

        var typingId = 'typing-' + Date.now();
        messages.push({ role: 'assistant', content: '...', typing: true, id: typingId });
        renderMessages();

        fetch('<?= BASE_URL ?>/index.php?r=ai_chat_assistant', {
          method: 'POST',
          headers: {
            'Content-Type': 'application/json',
            'Accept': 'application/json'
          },
          body: JSON.stringify({
            message: content,
            history: messages.filter(function (item) {
              return !item.typing;
            }).map(function (item) {
              return { role: item.role, content: item.content };
            })
          })
        })
          .then(function (response) {
            return response.json().catch(function () {
              return { ok: false, message: 'Phản hồi AI không hợp lệ.' };
            });
          })
          .then(function (payload) {
            messages = messages.filter(function (item) {
              return item.id !== typingId;
            });

            if (!payload || payload.ok !== true) {
              addMessage({
                role: 'assistant',
                content: (payload && payload.message) ? payload.message : 'AI hiện chưa phản hồi được. Vui lòng thử lại sau.',
                conflicts: [],
                products: []
              });
              return;
            }

            addMessage({
              role: 'assistant',
              content: String(payload.answer || '').trim(),
              conflicts: Array.isArray(payload.conflicts) ? payload.conflicts : [],
              products: Array.isArray(payload.products) ? payload.products : [],
              fallback: payload.fallback === true,
              fallbackReason: String(payload.fallback_reason || ''),
              statusMessage: String(payload.status_message || ''),
              fallbackNote: String(payload.fallback_note || '')
            });
          })
          .catch(function () {
            messages = messages.filter(function (item) {
              return item.id !== typingId;
            });
            addMessage({
              role: 'assistant',
              content: 'Không kết nối được tới AI service. Bạn có thể thử lại hoặc hỏi ngắn gọn hơn.',
              conflicts: [],
              products: [],
              fallback: true
            });
          })
          .finally(function () {
            setLoading(false);
          });
      };

      if (trigger) {
        trigger.addEventListener('click', function () {
          if (panel.hidden) {
            openWidget();
          } else {
            closeWidget();
          }
        });
      }

      if (closeButton) {
        closeButton.addEventListener('click', function () {
          closeWidget();
        });
      }

      if (expandButton) {
        expandButton.addEventListener('click', function () {
          widget.classList.toggle('is-expanded');
          syncExpandedState();
          syncLayout();
          scrollToBottom();
        });
      }

      if (resetButton) {
        resetButton.addEventListener('click', function () {
          clearMessages();
        });
      }

      if (form) {
        form.addEventListener('submit', function (event) {
          event.preventDefault();
          if (!input) {
            return;
          }
          var content = input.value;
          input.value = '';
          sendMessage(content);
        });
      }

      if (input) {
        input.addEventListener('input', function () {
          input.style.height = 'auto';
          input.style.height = Math.min(input.scrollHeight, 130) + 'px';
        });
      }

      quickPrompts.forEach(function (button) {
        button.addEventListener('click', function () {
          sendMessage(button.getAttribute('data-ai-chat-prompt') || '');
        });
      });

      document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape' && widget.classList.contains('is-open')) {
          closeWidget();
        }
      });

      document.addEventListener('click', function (event) {
        var openProduct = event.target.closest('[data-ai-open-product]');
        if (openProduct) {
          var openUrl = openProduct.getAttribute('data-url') || '';
          if (openUrl !== '') {
            window.location.href = openUrl;
          }
          return;
        }

        var toggle = event.target.closest('[data-ai-product-link-toggle]');
        if (!toggle) {
          return;
        }

        var targetId = toggle.getAttribute('data-target') || '';
        var target = targetId ? document.getElementById(targetId) : null;
        if (!target) {
          return;
        }

        var shouldHide = !target.hidden;
        target.hidden = shouldHide;
        toggle.textContent = shouldHide ? 'Hiện link' : 'Ẩn link';
      });

      window.addEventListener('skinsyntax:support-chat-layout', function (event) {
        syncLayout(event.detail || null);
      });

      window.addEventListener('resize', function () {
        syncLayout();
      });

      try {
        var stored = window.sessionStorage.getItem(storageKey);
        if (stored) {
          var parsed = JSON.parse(stored);
          if (Array.isArray(parsed)) {
            messages = parsed;
          }
        }
        if (window.sessionStorage.getItem(expandedStorageKey) === '1') {
          widget.classList.add('is-expanded');
        }
      } catch (error) {
      }

      syncLayout();
      syncExpandedState();
      renderMessages();
    });
  </script>
<?php endif; ?>