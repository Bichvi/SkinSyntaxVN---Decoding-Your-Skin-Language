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
if ($aiChatEmail !== '' && $pdo !== null) {
  try {
    if (class_exists('TaiKhoan')) {
        $taiKhoanModel = new TaiKhoan($pdo);
        $skinProfile = $taiKhoanModel->getSkinProfileByEmail($aiChatEmail);
        $khachHang = $taiKhoanModel->getKhachHangByEmail($aiChatEmail);
        
        // Kiểm tra loại da hợp lệ (không rỗng và không phải "Chưa xác định")
        $loaiDaRaw = trim((string)($skinProfile['loai_da'] ?? ''));
        if ($loaiDaRaw !== '' && mb_strtolower($loaiDaRaw, 'UTF-8') !== 'chưa xác định') {
            $aiChatSkinProfile = [
                'loai_da'   => $loaiDaRaw,
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
<?php if ($pdo !== null): ?>
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
      <?php if (!empty($_SESSION['user'])): ?>
        <aside class="ai-chat-widget__sidebar" data-ai-chat-sidebar>
          <div class="ai-chat-widget__sidebar-head">
            <h5>Cuộc hội thoại</h5>
            <button type="button" class="ai-chat-widget__new-chat-btn" data-ai-new-chat>
              <i class="fa-solid fa-plus"></i> Hội thoại mới
            </button>
          </div>
          <div class="ai-chat-widget__history-list" data-ai-history-list>
            <div class="ai-chat-widget__history-loading">Đang tải lịch sử...</div>
          </div>
        </aside>
      <?php endif; ?>

      <div class="ai-chat-widget__main-content">
        <header class="ai-chat-widget__panel-head">
        <div class="ai-chat-widget__panel-head-main">
          <div class="ai-chat-widget__panel-avatar" aria-hidden="true"><i class="fa-solid fa-robot"></i></div>
          <div class="ai-chat-widget__panel-info">
            <div class="ai-chat-widget__panel-title">Trợ Lý Tư Vấn SkinSyntax</div>
            <div class="ai-chat-widget__status-compact" data-ai-chat-status>
               <span class="ai-chat-widget__status-dot"></span> Đã kết nối
            </div>
          </div>
        </div>
        <div class="ai-chat-widget__panel-actions">
          <button class="ai-chat-widget__action-btn" type="button" data-ai-chat-reset title="Xóa lịch sử">
            <i class="fa-solid fa-trash-can"></i>
          </button>
          <button class="ai-chat-widget__action-btn ai-chat-widget__action-btn--expand" type="button" data-ai-chat-expand title="Phóng to">
            <i class="fa-solid fa-expand"></i> <span>Phóng to</span>
          </button>
          <button class="ai-chat-widget__action-btn ai-chat-widget__action-btn--close" type="button" data-ai-chat-close title="Đóng">
            <i class="fa-solid fa-xmark"></i>
          </button>
        </div>
      </header>

      <div class="ai-chat-widget__quick-actions">
        <button type="button" class="ai-chat-widget__quick-chip" data-ai-chat-prompt="Phân tích nhanh giỏ hàng hiện tại và cảnh báo các cặp chất có thể xung đột.">Phân tích giỏ hàng</button>
        <button type="button" class="ai-chat-widget__quick-chip" data-ai-chat-prompt="Tìm giúp tôi vài sản phẩm phù hợp với da dầu mụn và giải thích ngắn gọn.">Da dầu mụn</button>
        <button type="button" class="ai-chat-widget__quick-chip" data-ai-chat-prompt="Tóm tắt giúp tôi các nhóm hoạt chất treatment phổ biến và cách dùng an toàn trong routine.">Thành phần</button>
        
        <?php if (!empty($_SESSION['user'])): ?>
          <button type="button" class="ai-chat-widget__quick-chip ai-chat-widget__quick-chip--highlight" 
                  <?= $aiChatSkinProfile ? 'data-ai-chat-toggle-profile' : 'data-ai-chat-profile-restricted' ?>>
            Gợi ý theo hồ sơ da
          </button>
        <?php endif; ?>
      </div>

<?php if ($aiChatSkinProfile): ?>
      <div class="ai-chat-profile-banner" data-ai-profile-banner hidden
           data-profile="<?= htmlspecialchars(json_encode($aiChatSkinProfile, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), ENT_QUOTES, 'UTF-8') ?>">
        <div class="ai-chat-profile-banner__head">
          <span class="ai-chat-profile-banner__icon">
            <i class="fa-solid fa-wand-magic-sparkles"></i>
          </span>
          <div>
            <strong>Gợi ý theo hồ sơ da của bạn</strong>
            <span class="ai-chat-profile-banner__tag"><?= htmlspecialchars($aiChatSkinProfile['loai_da'], ENT_QUOTES, 'UTF-8') ?></span>
          </div>
          <button type="button" class="ai-chat-profile-banner__close" data-ai-chat-toggle-profile aria-label="Đóng gợi ý">
            <i class="fa-solid fa-xmark"></i>
          </button>
        </div>
        <p class="ai-chat-profile-banner__desc">Bạn muốn tìm sản phẩm nào phù hợp với <strong><?= htmlspecialchars($aiChatSkinProfile['loai_da'], ENT_QUOTES, 'UTF-8') ?></strong> và ngân sách của mình?</p>
        <div class="ai-chat-profile-banner__chips">
          <button type="button" class="ai-chat-profile-chip" data-category="Toner / Nước Cân Bằng Da">
            <i class="fa-solid fa-droplet"></i> Toner
          </button>

          <button type="button" class="ai-chat-profile-chip" data-category="Sữa Rửa Mặt">
            <i class="fa-solid fa-pump-soap"></i> Sữa rửa mặt
          </button>

          <button type="button" class="ai-chat-profile-chip" data-category="Tẩy Trang Mặt">
            <i class="fa-solid fa-leaf"></i> Tẩy trang
          </button>

          <button type="button" class="ai-chat-profile-chip" data-category="Serum / Tinh Chất">
            <i class="fa-solid fa-flask"></i> Serum
          </button>

          <button type="button" class="ai-chat-profile-chip" data-category="Kem / Gel / Dầu Dưỡng">
            <i class="fa-solid fa-jar"></i> Kem dưỡng
          </button>

          <button type="button" class="ai-chat-profile-chip" data-category="Chống Nắng Da Mặt">
            <i class="fa-solid fa-sun"></i> Chống nắng
          </button>

          <button type="button" class="ai-chat-profile-chip" data-category="Mặt Nạ Giấy">
            <i class="fa-solid fa-face-smile"></i> Mặt nạ
          </button>

          <button type="button" class="ai-chat-profile-chip" data-category="Hỗ Trợ Trị Mụn">
            <i class="fa-solid fa-prescription-bottle-medical"></i> Trị mụn
          </button>
        </div>
      </div>
<?php endif; ?>

      <div class="ai-chat-widget__stream" data-ai-chat-stream
           data-ai-skin-profile="<?= $aiChatSkinProfile ? htmlspecialchars(json_encode($aiChatSkinProfile, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), ENT_QUOTES, 'UTF-8') : '' ?>"
           data-ai-greeting-key="aiChatGreeting:<?= $aiChatStorageScope ?>">
        <div class="ai-chat-widget__welcome" data-ai-chat-welcome>
          <div class="ai-chat-widget__welcome-badge">Trợ Lý AI</div>
<?php if ($aiChatSkinProfile): ?>
          <h4>Xin chào! Mình đã có hồ sơ da của bạn</h4>
          <p>Loại da: <strong><?= htmlspecialchars($aiChatSkinProfile['loai_da'], ENT_QUOTES, 'UTF-8') ?></strong><?php if (!empty($aiChatSkinProfile['thanh_phan_tranh'])): ?> · Tránh: <strong><?= htmlspecialchars($aiChatSkinProfile['thanh_phan_tranh'], ENT_QUOTES, 'UTF-8') ?></strong><?php endif; ?></p>
          <p style="margin-top:4px">Chọn loại sản phẩm bên dưới để mình gợi ý ngay, hoặc hỏi bất kỳ điều gì về skincare nhé!</p>
<?php else: ?>
          <h4>Tư vấn skincare dựa trên các sản phẩm của SkinSyntax</h4>
          <p>Bạn có thể hỏi về thành phần, sản phẩm phù hợp, routine treatment hoặc yêu cầu quét giỏ hàng để phát hiện các cặp hoạt chất cần tránh. Khi có dữ liệu, phần gợi ý sẽ lấy kèm hình ảnh sản phẩm.</p>
<?php endif; ?>
        </div>
      </div>

      <form class="ai-chat-widget__form" data-ai-chat-form>
        <div class="ai-chat-widget__composer">
          <textarea class="form-control ai-chat-widget__textarea" rows="2" data-ai-chat-input placeholder="Hỏi AI về thành phần, sản phẩm, routine hoặc phân tích giỏ hàng..." required></textarea>
          <button class="btn ai-chat-widget__submit" type="submit" data-ai-chat-submit>Gửi</button>
        </div>
        
      </form>
      </div>
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
      gap: 12px;
      padding: 10px 18px;
      border-radius: 8px;
      border: 1px solid #C8DACF;
      background: #183B2B;
      color: #ffffff;
      box-shadow: 0 4px 14px rgba(24, 59, 43, 0.2);
      cursor: pointer;
      transition: all 0.22s ease;
      position: relative;
    }

    .ai-chat-widget__trigger:hover {
      background: #122B1F;
      transform: translateY(-2px);
    }

    .ai-chat-widget.is-open .ai-chat-widget__trigger {
      display: none;
    }

    .ai-chat-widget__trigger-icon {
      width: 34px;
      height: 34px;
      border-radius: 6px;
      display: grid;
      place-items: center;
      background: rgba(255, 255, 255, 0.14);
      flex: 0 0 auto;
    }

    .ai-chat-widget__trigger-avatar {
      width: 28px;
      height: 28px;
      border-radius: 4px;
      display: grid;
      place-items: center;
      background: #2D6A4F;
      color: #ffffff;
      font-size: 13px;
    }

    .ai-chat-widget__trigger-text {
      display: grid;
      text-align: left;
      line-height: 1.2;
      min-width: 0;
    }

    .ai-chat-widget__trigger-text strong {
      font-size: 13px;
      font-weight: 600;
    }

    .ai-chat-widget__trigger-text small {
      color: rgba(248, 251, 255, 0.78);
      font-size: 10px;
      font-weight: 500;
      white-space: nowrap;
      overflow: hidden;
      text-overflow: ellipsis;
    }

    .ai-chat-widget__panel {
      width: min(360px, calc(100vw - 24px));
      max-height: min(68vh, 520px);
      border-radius: 12px;
      overflow: hidden;
      background: #FAFAFA;
      border: 1px solid #dce7f3;
      box-shadow: 0 10px 30px rgba(15, 23, 42, 0.12);
      display: flex;
      flex-direction: column;
      opacity: 0;
      transform: translateY(18px) scale(0.96);
      transform-origin: bottom right;
      pointer-events: none;
      transition: opacity 0.22s ease, transform 0.28s cubic-bezier(0.22, 1, 0.36, 1), width 0.3s ease, max-height 0.3s ease;
    }

    /* Sidebar and main-content wrap */
    .ai-chat-widget__sidebar {
      display: none;
      width: 280px;
      flex-shrink: 0;
      background: #0f172a;
      color: #f1f5f9;
      border-right: 1px solid #1e293b;
      flex-direction: column;
    }

    .ai-chat-widget__sidebar-head {
      padding: 24px 20px 16px;
      border-bottom: 1px solid #1e293b;
      display: flex;
      flex-direction: column;
      gap: 16px;
    }

    .ai-chat-widget__sidebar-head h5 {
      margin: 0;
      font-size: 15px;
      font-weight: 600;
      color: #38bdf8;
      letter-spacing: 0.05em;
      text-transform: uppercase;
    }

    .ai-chat-widget__new-chat-btn {
      width: 100%;
      padding: 10px 16px;
      border-radius: 8px;
      border: 1px dashed #334155;
      background: rgba(51, 65, 85, 0.3);
      color: #cbd5e1;
      font-size: 13px;
      font-weight: 500;
      display: inline-flex;
      align-items: center;
      justify-content: center;
      gap: 8px;
      cursor: pointer;
      transition: all 0.2s ease;
    }

    .ai-chat-widget__new-chat-btn:hover {
      background: rgba(51, 65, 85, 0.6);
      color: #fff;
      border-color: #475569;
    }

    .ai-chat-widget__history-list {
      flex: 1;
      overflow-y: auto;
      padding: 12px 8px;
      display: flex;
      flex-direction: column;
      gap: 6px;
    }

    .ai-chat-widget__history-item {
      display: flex;
      align-items: center;
      justify-content: space-between;
      padding: 10px 12px;
      border-radius: 8px;
      cursor: pointer;
      transition: all 0.2s ease;
      color: #cbd5e1;
      min-width: 0;
      position: relative;
    }

    .ai-chat-widget__history-item:hover {
      background: #1e293b;
      color: #fff;
    }

    .ai-chat-widget__history-item.is-active {
      background: #1e293b;
      color: #38bdf8;
      font-weight: 500;
      border-left: 3px solid #38bdf8;
      border-top-left-radius: 0;
      border-bottom-left-radius: 0;
    }

    .ai-chat-widget__history-info {
      display: flex;
      flex-direction: column;
      min-width: 0;
      flex: 1;
    }

    .ai-chat-widget__history-title {
      font-size: 13px;
      font-weight: 500;
      white-space: nowrap;
      overflow: hidden;
      text-overflow: ellipsis;
    }

    .ai-chat-widget__history-preview {
      font-size: 11px;
      color: #64748b;
      white-space: nowrap;
      overflow: hidden;
      text-overflow: ellipsis;
      margin-top: 2px;
    }

    .ai-chat-widget__history-delete-btn {
      background: transparent;
      border: 0;
      color: #64748b;
      padding: 4px;
      border-radius: 4px;
      cursor: pointer;
      display: none;
      transition: all 0.2s ease;
    }

    .ai-chat-widget__history-item:hover .ai-chat-widget__history-delete-btn {
      display: block;
    }

    .ai-chat-widget__history-delete-btn:hover {
      color: #f43f5e;
      background: rgba(244, 63, 94, 0.1);
    }

    .ai-chat-widget__history-loading,
    .ai-chat-widget__history-empty {
      text-align: center;
      padding: 24px 12px;
      font-size: 13px;
      color: #64748b;
    }

    .ai-chat-widget__main-content {
      display: flex;
      flex-direction: column;
      flex: 1;
      width: 100%;
      height: 100%;
      overflow: hidden;
    }

    .ai-chat-widget__panel[hidden] {
      display: none !important;
    }

    .ai-chat-widget.is-open .ai-chat-widget__panel {
      opacity: 1;
      transform: translateY(0) scale(1);
      pointer-events: auto;
    }

    /* Phóng to toàn màn hình */
    .ai-chat-widget.is-expanded {
      position: fixed !important;
      top: 0 !important;
      left: 0 !important;
      right: 0 !important;
      bottom: 0 !important;
      width: 100vw !important;
      height: 100vh !important;
      z-index: 2147483647 !important;
      background: rgba(15, 23, 42, 0.4);
      backdrop-filter: blur(12px);
      padding: 0;
      margin: 0;
      display: block !important;
    }

    .ai-chat-widget.is-expanded .ai-chat-widget__panel {
      width: 100% !important;
      height: 100% !important;
      max-height: 100vh !important;
      border-radius: 0;
      transform: none !important;
      box-shadow: none;
      border: 0;
      display: flex;
      flex-direction: row !important;
      opacity: 1 !important;
      pointer-events: auto !important;
    }

    .ai-chat-widget.is-expanded .ai-chat-widget__sidebar {
      display: flex;
    }

    .ai-chat-widget.is-expanded .ai-chat-widget__panel-head {
      padding: 24px 32px;
      background: #fff;
    }

    .ai-chat-widget.is-expanded .ai-chat-widget__panel-title {
      font-size: 22px;
    }

    .ai-chat-widget.is-expanded .ai-chat-widget__stream {
      padding: 30px 40px;
      max-height: none !important;
      flex: 1;
    }

    .ai-chat-widget.is-expanded .ai-chat-widget__form {
      padding: 24px 40px;
    }

    .ai-chat-widget.is-expanded .ai-chat-profile-banner {
      margin: 20px 40px;
      padding: 30px;
      border-radius: 24px;
    }

    /* Header Styles */
    .ai-chat-widget__panel-head {
      display: flex !important;
      justify-content: space-between !important;
      align-items: center !important;
      gap: 12px;
      padding: 12px 16px;
      background: #ffffff;
      border-bottom: 1px solid #edf2f7;
      flex-shrink: 0;
    }

    .ai-chat-widget__panel-head-main {
      display: flex !important;
      gap: 10px;
      align-items: center;
      flex: 1;
      min-width: 0;
    }

    .ai-chat-widget__panel-avatar {
      flex-shrink: 0;
      width: 40px;
      height: 40px;
      border-radius: 12px;
      display: grid;
      place-items: center;
      background: linear-gradient(135deg, #1a3a3a 0%, #2f6a4f 100%);
      color: #fff;
      font-size: 18px;
    }

    .ai-chat-widget__panel-info {
      flex: 1;
      min-width: 0;
    }

    .ai-chat-widget__panel-title {
      font-size: 15px;
      font-weight: 700;
      color: #1a202c;
      margin: 0;
      line-height: 1.2;
    }

    .ai-chat-widget__status-compact {
      font-size: 11px;
      color: #718096;
      margin-top: 2px;
      display: flex;
      align-items: center;
      gap: 4px;
    }

    .ai-chat-widget__status-dot {
      width: 6px;
      height: 6px;
      border-radius: 50%;
      background: #48bb78;
    }

    .ai-chat-widget__status-compact.is-fallback .ai-chat-widget__status-dot {
      background: #f6ad55;
    }

    .ai-chat-widget__panel-actions {
      display: flex !important;
      gap: 6px;
      flex-shrink: 0;
    }

    .ai-chat-widget__action-btn {
      height: 32px;
      padding: 0 8px;
      border-radius: 8px;
      border: 1px solid #e2e8f0;
      background: #fff;
      color: #4a5568;
      display: flex;
      align-items: center;
      justify-content: center;
      gap: 6px;
      font-size: 12px;
      font-weight: 600;
      cursor: pointer;
      transition: all 0.2s ease;
    }

    .ai-chat-widget__action-btn:hover {
      background: #f7fafc;
      border-color: #cbd5e0;
      color: #2d3748;
    }

    .ai-chat-widget__action-btn--close {
      width: 32px;
      padding: 0;
    }

    .ai-chat-widget__action-btn i {
      font-size: 14px;
    }

    .ai-chat-widget.is-expanded .ai-chat-widget__panel-head {
      padding: 16px 24px;
    }

    .ai-chat-widget.is-expanded .ai-chat-widget__panel-title {
      font-size: 18px;
    }

    .ai-chat-widget__expand:hover {
      background: #f0f7f2;
      border-color: #b8d0bf;
      color: #1a4d32;
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
      cursor: pointer;
      transition: all 0.2s ease;
    }

    .ai-chat-widget__quick-chip:hover {
      background: #f0f7f2;
      border-color: #b8d0bf;
    }

    .ai-chat-widget__quick-chip--highlight {
      background: linear-gradient(135deg, #2f6a4f 0%, #173528 100%);
      color: #fff;
      border: 0;
      box-shadow: 0 4px 12px rgba(47, 106, 79, 0.2);
    }

    .ai-chat-widget__quick-chip--highlight:hover {
      background: linear-gradient(135deg, #3a8161 0%, #1f4635 100%);
      color: #fff;
      transform: translateY(-1px);
      box-shadow: 0 6px 16px rgba(47, 106, 79, 0.3);
    }

    .ai-chat-profile-banner {
      margin: 10px 14px;
      padding: 12px 14px;
      background: linear-gradient(135deg, #f0f7f4 0%, #ffffff 100%);
      border: 1px solid #d5e5db;
      border-radius: 16px;
      box-shadow: 0 4px 12px rgba(47, 106, 79, 0.05);
      position: relative;
      animation: ai-slide-down 0.3s ease-out;
      flex-shrink: 0;
    }

    /* ... existing animation ... */

    .ai-chat-profile-banner__close {
      position: absolute;
      top: 10px;
      right: 10px;
      width: 24px;
      height: 24px;
      border-radius: 50%;
      border: 0;
      background: #e2e8df;
      color: #1a3a2a;
      display: grid;
      place-items: center;
      font-size: 11px;
      cursor: pointer;
    }

    .ai-chat-profile-banner__close:hover {
      background: #d5e1d3;
    }

    .ai-chat-profile-banner__head {
      display: flex;
      align-items: center;
      gap: 8px;
      margin-bottom: 6px;
    }

    .ai-chat-profile-banner__head strong {
      font-size: 13px;
      color: #1a202c;
    }

    .ai-chat-profile-banner__tag {
      background: #2f6a4f;
      color: #fff;
      padding: 2px 8px;
      border-radius: 6px;
      font-size: 10px;
      font-weight: 800;
      text-transform: uppercase;
    }

    .ai-chat-profile-banner__desc {
      font-size: 12px;
      color: #4a5568;
      margin: 0 0 10px;
      line-height: 1.4;
    }

    .ai-chat-profile-banner__chips {
      display: flex;
      flex-wrap: wrap;
      gap: 6px;
    }

    .ai-chat-profile-chip {
      background: #fff;
      border: 1px solid #e2e8f0;
      padding: 5px 10px;
      border-radius: 999px;
      font-size: 11.5px;
      font-weight: 600;
      color: #2d3748;
      cursor: pointer;
      transition: all 0.2s ease;
      display: flex;
      align-items: center;
      gap: 4px;
    }

    .ai-chat-profile-chip:hover {
      background: #f7fafc;
      border-color: #cbd5e0;
      transform: translateY(-1px);
    }

    .ai-chat-widget.is-expanded .ai-chat-profile-banner {
      margin: 16px 24px;
      padding: 20px;
    }

    .ai-chat-widget__stream {
      flex: 1;
      min-height: 120px;
      overflow-y: auto;
      padding: 14px;
      display: flex;
      flex-direction: column;
      gap: 12px;
      background: linear-gradient(180deg, #fbfcf8 0%, #ffffff 100%);
    }

    .ai-chat-widget.is-expanded .ai-chat-widget__stream {
      min-height: 200px;
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

    /* Premium Product Action Buttons */
    .ai-chat-widget__meta-card-btn {
      display: inline-flex;
      align-items: center;
      justify-content: center;
      min-height: 34px;
      padding: 0 14px;
      border-radius: 999px;
      font-size: 11px;
      font-weight: 700;
      text-decoration: none;
      transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1);
      cursor: pointer;
      border: 1px solid #d5e3d9;
      background: #ffffff;
      color: #1d4f37;
      gap: 5px;
      outline: none;
    }

    .ai-chat-widget__meta-card-btn:hover {
      transform: translateY(-1.5px);
      box-shadow: 0 4px 12px rgba(29, 107, 70, 0.12);
    }

    .ai-chat-widget__meta-card-btn--cart {
      background: linear-gradient(135deg, #208753, #155e37);
      color: #ffffff;
      border: none;
      box-shadow: 0 2px 6px rgba(29, 107, 70, 0.2);
    }

    .ai-chat-widget__meta-card-btn--cart:hover {
      background: linear-gradient(135deg, #24995e, #1a7042);
      color: #ffffff;
      box-shadow: 0 4px 14px rgba(29, 107, 70, 0.3);
    }

    .ai-chat-widget__meta-card-btn--detail {
      background: #ffffff;
      color: #1d6b46;
      border: 1px solid #cae7d5;
    }

    .ai-chat-widget__meta-card-btn--detail:hover {
      background: #f0faf4;
      border-color: #a4dbba;
    }

    .ai-chat-widget__meta-card-btn--ask {
      background: #f0f6ff;
      color: #1d4ed8;
      border: 1px solid #bfdbfe;
    }

    .ai-chat-widget__meta-card-btn--ask:hover {
      background: #dbeafe;
      border-color: #93c5fd;
      color: #1e40af;
      box-shadow: 0 4px 12px rgba(29, 78, 216, 0.15);
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
      gap: 8px;
      padding: 10px 16px;
      border-radius: 16px;
      background: #FFFFFF;
      border: 1px solid #E2EADF;
      box-shadow: 0 4px 12px rgba(45, 90, 39, 0.04);
      max-width: 90%;
    }

    .ai-chat-widget__typing-text {
      font-size: 13px;
      color: #5C705E;
      font-weight: 500;
      transition: opacity 0.2s ease-in-out;
      opacity: 1;
    }

    .ai-chat-widget__typing-dots {
      display: inline-flex;
      align-items: center;
      gap: 3px;
    }

    .ai-chat-widget__typing-dots span {
      width: 5px;
      height: 5px;
      border-radius: 50%;
      background: #2D5A27;
      animation: ai-chat-blink 1.4s infinite ease-in-out;
      display: inline-block;
    }

    .ai-chat-widget__typing-dots span:nth-child(2) { animation-delay: 0.2s; }
    .ai-chat-widget__typing-dots span:nth-child(3) { animation-delay: 0.4s; }

    @keyframes ai-chat-blink {
      0%, 80%, 100% { transform: scale(0.6); opacity: 0.3; }
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
        width: 100vw;
        height: 100vh;
        max-height: 100vh !important;
      }

      .ai-chat-widget.is-expanded .ai-chat-widget__stream {
        max-height: none;
        flex: 1;
      }

      .ai-chat-widget.is-expanded .ai-chat-widget__panel-head {
        padding: 16px;
      }

      .ai-chat-widget.is-expanded .ai-chat-widget__form {
        padding: 16px;
      }

      .ai-chat-widget.is-expanded .ai-chat-profile-banner {
        margin: 12px;
        padding: 16px;
      }

      .ai-chat-widget__stream {
        max-height: 232px;
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

      var thinkingMessages = [
        "Đang xem xét thông tin bạn cung cấp",
        "Đang tìm thông tin liên quan",
        "Đang đối chiếu thông tin sản phẩm",
        "Đang kiểm tra thành phần",
        "Đang xem xét công dụng sản phẩm",
        "Đang chọn lọc thông tin phù hợp",
        "Đang tổng hợp thông tin",
        "Đang hoàn thiện tư vấn cho bạn"
      ];
      var loadingInterval = null;
      var currentLoadingTextIndex = 0;
      var toggleProfileBtns = widget.querySelectorAll('[data-ai-chat-toggle-profile]');
      var profileRestrictedBtn = widget.querySelector('[data-ai-chat-profile-restricted]');
      var profileBanner = widget.querySelector('[data-ai-profile-banner]');
      var storageScope = <?= json_encode($aiChatStorageScope, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
      var storageKey = 'aiChatMessagesV4:' + storageScope;
      var closeTimer = null;
      var messages = [];
      var defaultBottom = parseFloat(window.getComputedStyle(widget).bottom || '88') || 88;
      var expandedStorageKey = 'aiChatExpandedV4:' + storageScope;

      var activeConversationId = null;
      var sidebar = widget.querySelector('[data-ai-chat-sidebar]');
      var historyList = widget.querySelector('[data-ai-history-list]');
      var newChatBtn = widget.querySelector('[data-ai-new-chat]');
      var userLoggedIn = <?= json_encode(!empty($_SESSION['user'])) ?>;

      var loadConversations = function () {
        if (!userLoggedIn || !historyList) return;
        historyList.innerHTML = '<div class="ai-chat-widget__history-loading">Đang tải lịch sử...</div>';
        
        fetch('index.php?r=ai_chat_get_conversations')
          .then(function(res) { return res.json(); })
          .then(function(data) {
            if (data.ok && data.conversations) {
              renderConversationList(data.conversations);
            } else {
              historyList.innerHTML = '<div class="ai-chat-widget__history-empty">Không thể tải lịch sử</div>';
            }
          })
          .catch(function() {
            historyList.innerHTML = '<div class="ai-chat-widget__history-empty">Không thể tải lịch sử</div>';
          });
      };

      var renderConversationList = function (conversations) {
        if (!historyList) return;
        if (conversations.length === 0) {
          historyList.innerHTML = '<div class="ai-chat-widget__history-empty">Chưa có cuộc trò chuyện nào</div>';
          return;
        }
        
        var html = conversations.map(function (c) {
          var activeClass = (activeConversationId === c.id) ? ' is-active' : '';
          var title = escapeHtml(c.title);
          var preview = escapeHtml(c.last_message_preview || 'Chưa có tin nhắn');
          
          return '<div class="ai-chat-widget__history-item' + activeClass + '" data-conv-id="' + c.id + '">'
            + '<div class="ai-chat-widget__history-info">'
            + '<span class="ai-chat-widget__history-title">' + title + '</span>'
            + '<span class="ai-chat-widget__history-preview">' + preview + '</span>'
            + '</div>'
            + '<button type="button" class="ai-chat-widget__history-delete-btn" data-delete-conv-id="' + c.id + '" title="Xóa">'
            + '<i class="fa-solid fa-trash-can"></i>'
            + '</button>'
            + '</div>';
        }).join('');
        
        historyList.innerHTML = html;
        
        var items = historyList.querySelectorAll('.ai-chat-widget__history-item');
        items.forEach(function (item) {
          item.addEventListener('click', function (e) {
            if (e.target.closest('.ai-chat-widget__history-delete-btn')) return;
            var convId = this.getAttribute('data-conv-id');
            selectConversation(convId);
          });
        });
        
        var deleteBtns = historyList.querySelectorAll('.ai-chat-widget__history-delete-btn');
        deleteBtns.forEach(function (btn) {
          btn.addEventListener('click', function (e) {
            e.stopPropagation();
            var convId = this.getAttribute('data-delete-conv-id');
            if (confirm('Bạn có chắc chắn muốn xóa cuộc trò chuyện này?')) {
              deleteConversation(convId);
            }
          });
        });
      };

      var selectConversation = function (convId) {
        activeConversationId = convId;
        try {
          window.sessionStorage.setItem('aiChatActiveConversationIdV4:' + storageScope, convId);
        } catch (e) {}
        
        var items = historyList.querySelectorAll('.ai-chat-widget__history-item');
        items.forEach(function (item) {
          if (item.getAttribute('data-conv-id') === convId) {
            item.classList.add('is-active');
          } else {
            item.classList.remove('is-active');
          }
        });
        
        stream.innerHTML = '<div style="text-align:center; padding:20px; color:#757575;">Đang tải cuộc trò chuyện...</div>';
        if (welcome) welcome.hidden = true;
        
        fetch('index.php?r=ai_chat_get_messages&conversation_id=' + encodeURIComponent(convId))
          .then(function(res) { return res.json(); })
          .then(function(data) {
            if (data.ok && data.conversation) {
              messages = data.conversation.messages.map(function(m) {
                return {
                  role: m.role,
                  content: m.content,
                  products: m.products,
                  conflicts: m.conflicts
                };
              });
              renderMessages();
              scrollToBottom();
            } else {
              stream.innerHTML = '<div style="text-align:center; padding:20px; color:#f43f5e;">Lỗi tải cuộc trò chuyện</div>';
            }
          })
          .catch(function() {
            stream.innerHTML = '<div style="text-align:center; padding:20px; color:#f43f5e;">Lỗi kết nối tải cuộc trò chuyện</div>';
          });
      };

      var deleteConversation = function (convId) {
        fetch('index.php?r=ai_chat_delete_conversation&conversation_id=' + encodeURIComponent(convId), {
          method: 'POST'
        })
          .then(function(res) { return res.json(); })
          .then(function(data) {
            if (data.ok) {
              if (activeConversationId === convId) {
                activeConversationId = null;
                messages = [];
                renderMessages();
              }
              loadConversations();
            } else {
              alert('Không thể xóa cuộc trò chuyện: ' + (data.message || 'Lỗi không xác định'));
            }
          })
          .catch(function() {
            alert('Lỗi kết nối khi xóa cuộc trò chuyện');
          });
      };

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
        // quicksend links
        safe = safe.replace(/\[([^\]]+)\]\((quicksend:[^)]+)\)/g, '<a href="$2" class="ai-chat-quick-btn" style="display:inline-block; border: 1px solid #183B2B; color: #183B2B; background: #EBF2EE; border-radius: 16px; padding: 4px 12px; margin: 4px 2px; text-decoration: none; font-size: 12px; font-weight: 600; transition: all 0.2s;">$1</a>');
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

      var syncLayout = function () {
        var isMobile = window.matchMedia('(max-width: 767.98px)').matches;
        var nextBottom = isMobile ? 74 : 84;
        widget.style.bottom = nextBottom + 'px';
        widget.style.right = isMobile ? '16px' : '20px';

        if (panel) {
          var isExpanded = widget.classList.contains('is-expanded');
          var viewportPadding = isMobile ? 16 : 24;
          var availableHeight = Math.max(300, window.innerHeight - nextBottom - viewportPadding);
          var maxHeightLimit = isMobile ? (isExpanded ? 800 : 580) : (isExpanded ? 1200 : 620);
          panel.style.maxHeight = Math.min(maxHeightLimit, availableHeight) + 'px';
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
            return 'Phù hợp với yêu cầu của bạn.';
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
          
          var fullDescription = escapeHtml(rawSummary);
          var shortDescription = escapeHtml(summarizeText(rawSummary, 120));
          
          var descriptionBlock = '';
          if (rawSummary.length > 120) {
              var uniqueId = 'ai-product-desc-' + Math.random().toString(36).substring(2, 9);
              descriptionBlock = '<div class="ai-chat-widget__meta-card-subtitle" title="Nhấp để xem thêm/thu gọn" style="cursor: pointer;" onclick="var s=document.getElementById(\'' + uniqueId + '-short\'), f=document.getElementById(\'' + uniqueId + '-full\'); if(s.hidden){ s.hidden=false; f.hidden=true; } else { s.hidden=true; f.hidden=false; }">'
                + '<span id="' + uniqueId + '-short">' + shortDescription + ' <span style="color:#2c6a4a; font-weight:800; font-size:11px; white-space:nowrap;">[Xem thêm]</span></span>'
                + '<span id="' + uniqueId + '-full" hidden>' + fullDescription + ' <span style="color:#2c6a4a; font-weight:800; font-size:11px; white-space:nowrap;">[Thu gọn]</span></span>'
                + '</div>';
          } else {
              descriptionBlock = '<div class="ai-chat-widget__meta-card-subtitle">' + shortDescription + '</div>';
          }

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
            
          var cartAction = (detailUrl !== '' && product.id)
            ? '<button type="button" class="ai-chat-widget__meta-card-btn ai-chat-widget__meta-card-btn--cart" data-ai-add-cart="' + escapeHtml(product.id) + '">'
              + '<i class="fa-solid fa-cart-plus"></i> Thêm vào giỏ hàng'
              + '</button>'
            : '';
            
          var detailAction = detailUrl !== ''
            ? '<a href="' + escapeHtml(detailUrl) + '" class="ai-chat-widget__meta-card-btn ai-chat-widget__meta-card-btn--detail">'
              + '<i class="fa-solid fa-circle-info"></i> Xem chi tiết'
              + '</a>'
            : '';
            
          var askMoreAction = product.name
            ? '<button type="button" class="ai-chat-widget__meta-card-btn ai-chat-widget__meta-card-btn--ask" data-ai-ask-more="' + escapeHtml(product.name) + '">'
              + '<i class="fa-solid fa-comment-dots"></i> Hỏi kỹ hơn'
              + '</button>'
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
            + descriptionBlock
            + '<div class="ai-chat-widget__meta-card-actions">' + price + cartAction + detailAction + askMoreAction + toggleAction + '</div>'
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

        status.innerHTML = '<span class="ai-chat-widget__status-dot"></span> Đã kết nối';
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
            var initialText = thinkingMessages[currentLoadingTextIndex] || thinkingMessages[0];
            return '<div class="ai-chat-widget__typing-row">'
              + '<div class="ai-chat-widget__typing-bubble">'
              + '<span class="ai-chat-widget__typing-text" data-ai-typing-text>' + initialText + '</span>'
              + '<span class="ai-chat-widget__typing-dots">'
              + '<span></span><span></span><span></span>'
              + '</span>'
              + '</div></div>';
          }

          var isUser = message.role === 'user';
          var meta = '';
          var contentPrefix = '';
          var contentSuffix = '';
          if (!isUser) {
            var conflictCards = renderConflictCards(message.conflicts || []);
            var productCards = renderProductCards(message.products || []);
            meta += renderMetaBlock('Conflict Detection', conflictCards);
            if (productCards.length) {
              contentSuffix += '<div class="ai-chat-widget__product-group">' + productCards.join('') + '</div>';
            }

            // Render evaluation metrics badge
            var evalScores = message.eval_scores || { ar: 1.0, gr: 1.0, cr: 1.0 };
            var pipelineMode = message.pipeline_mode || (message.fallback ? 'Agent -> Fallback' : 'Pipeline');
            var queryType = message.query_type || 'simple single-intent query';
            var intentMode = message.intent_mode || 'PRODUCT_INQUIRY';
            var fallbackReason = message.fallbackReason || '';
            
            var dotColor = (pipelineMode === 'Pipeline') ? '#4caf50' : '#ff9800';
            var subText = message.fallback ? ('-> ' + (fallbackReason || 'agent failed: APIStatusError')) : ('-> ' + queryType);
            var intentPart = message.fallback ? '' : (' | intent: ' + intentMode);
            var latencyPart = message.latency ? (' | ' + Number(message.latency).toFixed(2) + 's') : '';
            
            meta += '<div class="ai-chat-widget__eval-badge" style="display: inline-flex; align-items: center; gap: 6px; font-size: 11px; color: #757575; background: #f5f5f5; padding: 4px 8px; border-radius: 12px; margin-top: 8px; font-family: monospace; border: 1px solid #e0e0e0;">'
                 + '<span style="width: 8px; height: 8px; border-radius: 50%; background-color: ' + dotColor + '; display: inline-block;"></span>'
                 + '<strong>' + pipelineMode + '</strong> | ' + subText + ' | '
                 + 'AR: ' + Number(evalScores.ar).toFixed(2) + ' | '
                 + 'GR: ' + Number(evalScores.gr).toFixed(2) + ' | '
                 + 'CR: ' + Number(evalScores.cr).toFixed(2)
                 + latencyPart
                 + intentPart
                 + '</div>';
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
          var textSpan = expandButton.querySelector('span');
          var icon = expandButton.querySelector('i');
          if (expanded) {
             if(icon) icon.className = 'fa-solid fa-compress';
             if(textSpan) textSpan.textContent = 'Thu nhỏ';
          } else {
             if(icon) icon.className = 'fa-solid fa-expand';
             if(textSpan) textSpan.textContent = 'Phóng to';
          }
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
          if (window.sessionStorage.getItem(expandedStorageKey) === '1') {
            widget.classList.add('is-expanded');
          }
          if (userLoggedIn) {
            loadConversations();
          }
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

        // Đảm bảo gỡ bỏ trạng thái phóng to khi đóng widget toàn màn hình
        if (widget.classList.contains('is-expanded')) {
          widget.classList.remove('is-expanded');
          syncExpandedState();
        }

        if (closeTimer) {
          window.clearTimeout(closeTimer);
        }
        closeTimer = window.setTimeout(function () {
          panel.hidden = true;
          closeTimer = null;
        }, 280);
      };

      var sendMessage = function (text) {
        var startTime = Date.now();
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

        if (loadingInterval) {
          clearInterval(loadingInterval);
        }
        currentLoadingTextIndex = 0;
        loadingInterval = setInterval(function() {
          currentLoadingTextIndex = (currentLoadingTextIndex + 1) % thinkingMessages.length;
          var textEl = widget.querySelector('[data-ai-typing-text]');
          if (textEl) {
            textEl.style.opacity = '0';
            setTimeout(function() {
              textEl.textContent = thinkingMessages[currentLoadingTextIndex];
              textEl.style.opacity = '1';
            }, 200);
          }
        }, 4000);

        var currentProductId = null;
        try {
          var urlParams = new URLSearchParams(window.location.search);
          if (urlParams.get('r') === 'chitiet') {
            currentProductId = urlParams.get('id');
          }
        } catch (e) {
        }

        fetch('<?= BASE_URL ?>/index.php?r=ai_chat_assistant', {
          method: 'POST',
          headers: {
            'Content-Type': 'application/json',
            'Accept': 'application/json'
          },
          body: JSON.stringify({
            message: content,
            current_product_id: currentProductId,
            conversation_id: activeConversationId,
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

            if (payload.conversation_id && activeConversationId !== payload.conversation_id) {
              activeConversationId = payload.conversation_id;
            }

            addMessage({
              role: 'assistant',
              content: String(payload.answer || '').trim(),
              conflicts: Array.isArray(payload.conflicts) ? payload.conflicts : [],
              products: Array.isArray(payload.products) ? payload.products : [],
              fallback: payload.fallback === true,
              fallbackReason: String(payload.fallback_reason || ''),
              statusMessage: String(payload.status_message || ''),
              fallbackNote: String(payload.fallback_note || ''),
              eval_scores: payload.eval_scores,
              pipeline_mode: payload.pipeline_mode,
              query_type: payload.query_type,
              intent_mode: payload.intent_mode,
              latency: payload.latency
            });

            if (userLoggedIn) {
              loadConversations();
            }
          })
          .catch(function () {
            messages = messages.filter(function (item) {
              return item.id !== typingId;
            });
            var elapsed = ((Date.now() - startTime) / 1000).toFixed(2);
            addMessage({
              role: 'assistant',
              content: 'Không kết nối được tới AI service. Bạn có thể thử lại hoặc hỏi ngắn gọn hơn.',
              conflicts: [],
              products: [],
              fallback: true,
              fallbackReason: 'agent failed: APIStatusError',
              pipeline_mode: 'Agent -> Fallback',
              query_type: 'simple single-intent query',
              intent_mode: 'PRODUCT_INQUIRY',
              eval_scores: { ar: 0.0, gr: 0.0, cr: 0.0 },
              latency: parseFloat(elapsed)
            });
          })
          .finally(function () {
            if (loadingInterval) {
              clearInterval(loadingInterval);
              loadingInterval = null;
            }
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

      var startNewChat = function () {
        activeConversationId = null;
        try {
          window.sessionStorage.removeItem('aiChatActiveConversationIdV4:' + storageScope);
        } catch (e) {}
        messages = [];
        renderMessages();
        injectGreeting();
        
        if (historyList) {
          var items = historyList.querySelectorAll('.ai-chat-widget__history-item');
          items.forEach(function (item) {
            item.classList.remove('is-active');
          });
        }
      };

      if (newChatBtn) {
        newChatBtn.addEventListener('click', startNewChat);
      }

      if (resetButton) {
        resetButton.addEventListener('click', startNewChat);
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

      toggleProfileBtns.forEach(function (btn) {
        btn.addEventListener('click', function () {
          if (profileBanner) {
            profileBanner.hidden = !profileBanner.hidden;
            if (!profileBanner.hidden) {
              profileBanner.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
            }
          }
        });
      });

      if (profileRestrictedBtn) {
        profileRestrictedBtn.addEventListener('click', function () {
          addMessage({
            role: 'assistant',
            content: "Chào bạn! Bạn đã đăng nhập rồi nhưng để mình có thể đưa ra gợi ý sản phẩm phù hợp nhất, bạn hãy dành 1 phút để hoàn thành [khảo sát da tại đây](<?= BASE_URL ?>/index.php?r=khaosat) nhé. Sau khi có hồ sơ da, mình sẽ tư vấn sát nhất nhe!"
          });
        });
      }

      var profileChips = widget.querySelectorAll('.ai-chat-profile-chip');
      profileChips.forEach(function (button) {
        button.addEventListener('click', function () {
          var category = button.getAttribute('data-category') || '';
          if (category !== '') {
            if (profileBanner) {
              profileBanner.hidden = true;
            }
            sendMessage('Gợi ý cho tôi một vài sản phẩm phù hợp với da tôi thuộc nhóm: ' + category);
          }
        });
      });

      document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape' && widget.classList.contains('is-open')) {
          closeWidget();
        }
      });

      document.addEventListener('click', function (event) {
        var quickSendLink = event.target.closest('a');
        if (quickSendLink) {
          var href = quickSendLink.getAttribute('href') || '';
          if (href.indexOf('quicksend:') === 0) {
            event.preventDefault();
            var text = href.substring(10);
            sendMessage(decodeURIComponent(text));
            return;
          }
        }

        var openProduct = event.target.closest('[data-ai-open-product]');
        if (openProduct) {
          var openUrl = openProduct.getAttribute('data-url') || '';
          if (openUrl !== '') {
            window.location.href = openUrl;
          }
          return;
        }

        var askMore = event.target.closest('[data-ai-ask-more]');
        if (askMore) {
          var productName = askMore.getAttribute('data-ai-ask-more') || '';
          if (productName !== '') {
            sendMessage('Hãy tư vấn chi tiết hơn về thành phần và cách dùng của sản phẩm: ' + productName);
          }
          return;
        }

        var addCartBtn = event.target.closest('[data-ai-add-cart]');
        if (addCartBtn) {
          event.preventDefault();
          var productId = addCartBtn.getAttribute('data-ai-add-cart') || '';
          
          addCartBtn.disabled = true;
          var oldHTML = addCartBtn.innerHTML;
          addCartBtn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Đang thêm...';
          
          var formData = new FormData();
          formData.append('action', 'add_to_cart');
          formData.append('product_id', productId);
          formData.append('quantity', '1');
          
          fetch('<?= BASE_URL ?>/index.php?r=them_gio_hang_ajax', {
            method: 'POST',
            body: formData,
            headers: {
              'X-Requested-With': 'XMLHttpRequest'
            }
          })
          .then(function(res) { return res.json(); })
          .then(function(result) {
            if (result && result.ok) {
              addCartBtn.innerHTML = '<i class="fa-solid fa-check"></i> Đã thêm';
              
              if (typeof updateCartBadge === 'function' && result.cart_count !== undefined) {
                updateCartBadge(parseInt(result.cart_count, 10) || 0);
              }
              if (typeof bounceCartHeader === 'function') {
                bounceCartHeader();
              }
              if (typeof showCartToast === 'function') {
                showCartToast(result.message || 'Đã thêm sản phẩm vào giỏ hàng', true);
              }
              
              window.setTimeout(function () {
                addCartBtn.disabled = false;
                addCartBtn.innerHTML = oldHTML;
              }, 1500);
            } else {
              if (typeof showCartToast === 'function') {
                showCartToast(result.message || 'Không thể thêm sản phẩm', false);
              } else {
                alert(result.message || 'Có lỗi xảy ra.');
              }
              addCartBtn.disabled = false;
              addCartBtn.innerHTML = oldHTML;
            }
          })
          .catch(function(err) {
            if (typeof showCartToast === 'function') {
              showCartToast('Không thể kết nối đến máy chủ.', false);
            } else {
              alert('Không thể kết nối đến máy chủ.');
            }
            addCartBtn.disabled = false;
            addCartBtn.innerHTML = oldHTML;
          });
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

      // â”€â”€ Auto-greeting (one-time per session) â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
      var greetingKey = stream ? (stream.getAttribute('data-ai-greeting-key') || '') : '';

      var buildGreetingMessage = function () {
        var profileRaw = stream ? stream.getAttribute('data-ai-skin-profile') : '';
        var profile = null;
        try { profile = profileRaw ? JSON.parse(profileRaw) : null; } catch (e) {}

        if (!profile || !profile.loai_da) {
          // Khách chưa có hồ sơ da
          return 'SkinSyntax AI chào bạn! ðŸ‘‹\n\n'
            + 'Mình là **Ngọc Vi** — tư vấn viên AI của SkinSyntaxVN. '
            + 'Bạn có thể hỏi mình về:\n'
            + '- Thành phần mỹ phẩm & cách phối hợp an toàn\n'
            + '- Gợi ý sản phẩm phù hợp từng loại da\n'
            + '- Phân tích giỏ hàng & phát hiện xung đột hoạt chất\n\n'
            + 'Để mình tư vấn sát hơn, bạn có thể [hoàn thành khảo sát da](<?= BASE_URL ?>/index.php?r=khaosat) nhé!';
        }

        var loaiDa = profile.loai_da || '';
        var vande  = profile.van_de_da || '';
        var tranh  = profile.thanh_phan_tranh || '';
        var nganSach = parseInt(profile.ngan_sach || 0, 10);

        var lines = [];
        lines.push('SkinSyntax AI chào bạn! ðŸ‘‹');
        lines.push('');
        lines.push('Mình đã ghi nhận tình trạng da của bạn là **' + loaiDa + '**' + (vande ? ' với vấn đề **' + vande + '**' : '') + '.');

        if (tranh) {
          lines.push('');
          lines.push('âš ï¸ **Ưu tiên tránh các thành phần:** ' + tranh + '.');
          lines.push('Mình sẽ luôn lọc sản phẩm an toàn với danh sách này cho bạn!');
        }

        if (nganSach > 0) {
          var fmt = new Intl.NumberFormat('vi-VN').format(nganSach);
          lines.push('');
          lines.push('ðŸ’° Ngân sách tham khảo của bạn: **' + fmt + ' đ**.');
        }

        lines.push('');
        lines.push('Dưới đây là một số gợi ý nhanh — bạn muốn mình tìm sản phẩm nào hôm nay?');
        lines.push('*(Chọn loại sản phẩm ở nút bên dưới hoặc hỏi tự do nhé!)*');

        return lines.join('\n');
      };

      var injectGreeting = function () {
        if (!greetingKey) return;
        try {
          if (window.sessionStorage.getItem(greetingKey) === '1') return; // already shown
          window.sessionStorage.setItem(greetingKey, '1');
        } catch (e) {}

        var greetText = buildGreetingMessage();
        messages.push({
          role: 'assistant',
          content: greetText,
          conflicts: [],
          products: []
        });
        // Don't save to message storage so it regenerates fresh each session
        renderMessages();
      };
      // â”€â”€ End auto-greeting â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€

      try {
        if (userLoggedIn) {
          var savedConvId = window.sessionStorage.getItem('aiChatActiveConversationIdV4:' + storageScope);
          if (savedConvId && savedConvId !== 'null' && savedConvId !== 'undefined') {
            activeConversationId = savedConvId;
            selectConversation(savedConvId);
          }
        } else {
          var stored = window.sessionStorage.getItem(storageKey);
          if (stored) {
            var parsed = JSON.parse(stored);
            if (Array.isArray(parsed)) {
              messages = parsed;
            }
          }
        }
      } catch (error) {
      }

      syncLayout();
      syncExpandedState();
      renderMessages();

      // Inject greeting after first render if no prior history
      if (messages.length === 0) {
        injectGreeting();
      }
    });
  </script>
<?php endif; ?>
