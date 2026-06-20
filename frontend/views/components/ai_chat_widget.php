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

$aiChatSkinProfile = null;
$aiChatEmail = trim((string)($aiChatUser['email'] ?? ''));
if ($aiChatEmail !== '' && $pdo !== null) {
  try {
    if (class_exists('TaiKhoan')) {
        $taiKhoanModel = new TaiKhoan($pdo);
        $skinProfile = $taiKhoanModel->getSkinProfileByEmail($aiChatEmail);
        $khachHang = $taiKhoanModel->getKhachHangByEmail($aiChatEmail);
        
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

$aiChatSessionId = 'guest-' . $aiChatStorageScope;
if ($aiChatEmail !== '') {
  $aiChatSessionId = 'user-' . hash('sha256', strtolower($aiChatEmail));
}
?>
<?php if ($pdo !== null): ?>
  <div class="ai-chat-widget" data-ai-chat-widget data-storage-scope="<?= htmlspecialchars($aiChatStorageScope, ENT_QUOTES) ?>">
    <button class="ai-chat-widget__trigger" type="button" data-ai-chat-toggle aria-expanded="false" aria-controls="aiChatPanel" title="Chat với AI">
      <span class="ai-chat-widget__trigger-icon" aria-hidden="true">
        <span class="ai-chat-widget__trigger-avatar"><i class="fa-solid fa-robot"></i></span>
      </span>
      <span class="ai-chat-widget__trigger-text">
        <strong>Chat với AI</strong>
        <small>Gợi ý & đặt hàng</small>
      </span>
      <span class="ai-chat-widget__cart-badge" data-ai-cart-badge hidden>0</span>
    </button>

    <section class="ai-chat-widget__panel" id="aiChatPanel" data-ai-chat-panel hidden>
      <header class="ai-chat-widget__panel-head">
        <div class="ai-chat-widget__panel-head-main">
          <div class="ai-chat-widget__panel-avatar" aria-hidden="true"><i class="fa-solid fa-robot"></i></div>
          <div class="ai-chat-widget__panel-info">
            <div class="ai-chat-widget__panel-title">SkinSyntax AI Agent</div>
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
        <button type="button" class="ai-chat-widget__quick-chip" data-ai-open-cart-quick><i class="fa-solid fa-cart-shopping"></i> Giỏ hàng</button>
        <button type="button" class="ai-chat-widget__quick-chip" data-ai-chat-prompt="Tìm giúp tôi vài sản phẩm phù hợp với da dầu mụn và giải thích ngắn gọn.">Da dầu mụn</button>
        <button type="button" class="ai-chat-widget__quick-chip" data-ai-chat-prompt="Tóm tắt giúp tôi các nhóm hoạt chất treatment phổ biến và cách dùng an toàn trong routine.">Thành phần</button>
        
        <?php if (!empty($_SESSION['user'])): ?>
          <button type="button" class="ai-chat-widget__quick-chip ai-chat-widget__quick-chip--highlight"
                  <?= $aiChatSkinProfile ? 'data-ai-chat-toggle-profile' : 'data-ai-chat-profile-from-chat' ?>>
            ✨ Gợi ý theo hồ sơ da
          </button>
        <?php endif; ?>
      </div>

<?php if ($aiChatSkinProfile): ?>
      <div class="ai-chat-profile-banner" data-ai-profile-banner hidden
           data-profile="<?= htmlspecialchars(json_encode($aiChatSkinProfile, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), ENT_QUOTES) ?>">
        <div class="ai-chat-profile-banner__head">
          <span class="ai-chat-profile-banner__icon">✨</span>
          <div>
            <strong>Gợi ý theo hồ sơ da của bạn</strong>
            <span class="ai-chat-profile-banner__tag"><?= htmlspecialchars($aiChatSkinProfile['loai_da'], ENT_QUOTES) ?></span>
          </div>
          <button type="button" class="ai-chat-profile-banner__close" data-ai-chat-toggle-profile aria-label="Đóng gợi ý">
            <i class="fa-solid fa-xmark"></i>
          </button>
        </div>
        <p class="ai-chat-profile-banner__desc">Bạn muốn tìm sản phẩm nào phù hợp với <strong><?= htmlspecialchars($aiChatSkinProfile['loai_da'], ENT_QUOTES) ?></strong> và ngân sách của mình?</p>
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

      <div class="ai-chat-widget__stream" data-ai-chat-stream
           data-ai-session-id="<?= htmlspecialchars($aiChatSessionId, ENT_QUOTES) ?>"
           data-ai-stream-url="<?= BASE_URL ?>/index.php?r=ai_chat_stream"
           data-ai-sync-url="<?= BASE_URL ?>/index.php?r=ai_chat_assistant"
           data-ai-commerce-url="<?= BASE_URL ?>/index.php?r=ai_chat_commerce"
           data-ai-base-url="<?= BASE_URL ?>"
           data-ai-skin-profile="<?= $aiChatSkinProfile ? htmlspecialchars(json_encode($aiChatSkinProfile, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), ENT_QUOTES) : '' ?>"
           data-ai-greeting-key="aiChatGreeting:<?= $aiChatStorageScope ?>">
        <div class="ai-chat-widget__welcome" data-ai-chat-welcome>
          <div class="ai-chat-widget__welcome-badge">AI Agent</div>
<?php if ($aiChatSkinProfile): ?>
          <h4>Xin chào! Mình đã có hồ sơ da của bạn 👋</h4>
          <p>Loại da: <strong><?= htmlspecialchars($aiChatSkinProfile['loai_da'], ENT_QUOTES) ?></strong><?php if (!empty($aiChatSkinProfile['thanh_phan_tranh'])): ?> · Tránh: <strong><?= htmlspecialchars($aiChatSkinProfile['thanh_phan_tranh'], ENT_QUOTES) ?></strong><?php endif; ?></p>
          <p style="margin-top:4px">Chọn loại sản phẩm bên dưới để mình gợi ý ngay, hoặc hỏi bất kỳ điều gì về skincare nhé!</p>
<?php else: ?>
          <h4>Tư vấn skincare — không cần hồ sơ sẵn</h4>
          <p>Mô tả tình trạng da và ngân sách ngay trong chat (vd: da ngứa, mụn, dưới 900k). Mình sẽ gợi ý sản phẩm và có thể <strong>tự bổ sung hồ sơ</strong> cho bạn sau mỗi lượt tư vấn.</p>
<?php endif; ?>
        </div>
      </div>

      <form class="ai-chat-widget__form" data-ai-chat-form>
        <div class="ai-chat-widget__composer">
          <textarea class="form-control ai-chat-widget__textarea" rows="2" data-ai-chat-input placeholder="Hỏi AI, thêm giỏ hàng, đặt hàng ngay trong chat..." required></textarea>
          <button class="btn ai-chat-widget__submit" type="submit" data-ai-chat-submit>Gửi</button>
        </div>
      </form>

      <div class="ai-chat-widget__drawer" data-ai-cart-drawer hidden>
        <div class="ai-chat-widget__drawer-head">
          <strong><i class="fa-solid fa-cart-shopping"></i> Giỏ hàng</strong>
          <button type="button" class="ai-chat-widget__drawer-close" data-ai-cart-close aria-label="Đóng"><i class="fa-solid fa-xmark"></i></button>
        </div>
        <div class="ai-chat-widget__drawer-body" data-ai-cart-body></div>
        <div class="ai-chat-widget__drawer-foot" data-ai-cart-foot hidden>
          <div class="ai-chat-widget__cart-total" data-ai-cart-total></div>
          <button type="button" class="ai-chat-widget__drawer-btn ai-chat-widget__drawer-btn--primary" data-ai-checkout-open>Đặt hàng</button>
        </div>
      </div>

      <div class="ai-chat-widget__drawer ai-chat-widget__drawer--checkout" data-ai-checkout-drawer hidden>
        <div class="ai-chat-widget__drawer-head">
          <strong><i class="fa-solid fa-receipt"></i> Thanh toán</strong>
          <button type="button" class="ai-chat-widget__drawer-close" data-ai-checkout-close aria-label="Đóng"><i class="fa-solid fa-xmark"></i></button>
        </div>
        <div class="ai-chat-widget__drawer-body" data-ai-checkout-body></div>
      </div>
    </section>
  </div>

  <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/ai-chat-widget.css">

  <script src="<?= BASE_URL ?>/assets/js/ai-chat-widget.js" defer></script>
<?php endif; ?>
