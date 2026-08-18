<?php
$pageTitle = '🔴 SkinSyntax Live - Sàn Thương Mại Điện Tử Livestream Tích Hợp AI Agent';
require_once __DIR__ . '/layouts/header.php';
?>

<div class="live-center-hero py-4" style="background: linear-gradient(135deg, #162F18 0%, #215427 100%); color: #FFF; border-bottom: 1px solid #C5DAC8;">
  <div class="container">
    <div class="row align-items-center g-3">
      <div class="col-lg-8">
        <div class="d-flex align-items-center gap-2 mb-2">
          <span class="badge bg-danger rounded-pill px-3 py-1.5 fw-bold" style="font-size: 0.8rem; animation: pulseGlowBtn 2s infinite;"><i class="fa-solid fa-circle me-1"></i>🔴 LIVE WEBRTC</span>
          <span class="badge bg-white text-success rounded-pill px-3 py-1.5 fw-bold" style="font-size: 0.8rem;"><i class="fa-solid fa-brain me-1"></i>LLM + RAG AI AGENT CO-HOST</span>
          <span class="badge bg-dark text-light rounded-pill px-3 py-1.5 fw-bold" style="font-size: 0.8rem;"><i class="fa-solid fa-cloud me-1"></i>LiveKit WebRTC Cloud</span>
        </div>
        <h1 class="fw-extrabold display-6 mb-2" style="font-weight: 800; letter-spacing: -0.02em;">SkinSyntax Live Commerce & AI Agent</h1>
        <p class="mb-0 text-light opacity-90" style="font-size: 1rem; line-height: 1.5; color: #EAF0EB !important;">
          Nền tảng Livestream Thương Mại Điện Tử thời gian thực dựa trên <strong>LiveKit Cloud / Self-Hosted WebRTC</strong> tích hợp <strong>AI Agent LLM & RAG</strong> tự động tư vấn hoạt chất sản phẩm và xử lý chốt đơn tự động 24/7 trong livestream.
        </p>
      </div>
      <div class="col-lg-4 text-lg-end">
        <button type="button" class="btn btn-light rounded-pill px-4 py-2.5 fw-bold shadow-sm" style="color: #215427;" data-bs-toggle="modal" data-bs-target="#createLiveModal">
          <i class="fa-solid fa-video me-2 text-danger"></i>Tạo Phiên Live Mới
        </button>
      </div>
    </div>
  </div>
</div>

<div class="container py-4">
  <!-- MAIN INTERACTIVE LIVESTREAM STUDIO -->
  <div class="row g-4 mb-5">
    <!-- LEFT: WEBRTC LIVE VIDEO PLAYER CONTAINER -->
    <div class="col-lg-8">
      <div class="live-player-card card border-0 rounded-4 overflow-hidden shadow-lg bg-dark position-relative" style="aspect-ratio: 16/9; border: 1.5px solid #215427 !important;">
        <!-- Simulated LiveKit WebRTC Video Screen -->
        <div class="live-video-stream w-100 h-100 position-relative d-flex align-items-center justify-content-center" style="background: radial-gradient(circle at center, #1E3A21 0%, #0B190D 100%);">
          <img src="<?= BASE_URL ?>/assets/images/hero_campaign_ai_skin.png" alt="Live Stream" class="w-100 h-100" style="object-fit: cover; opacity: 0.85;">

          <!-- TOP VIDEO OVERLAY INFO -->
          <div class="position-absolute top-0 start-0 w-100 p-3 d-flex align-items-center justify-content-between text-white" style="background: linear-gradient(180deg, rgba(0,0,0,0.7) 0%, rgba(0,0,0,0) 100%); z-index: 10;">
            <div class="d-flex align-items-center gap-2">
              <span class="badge bg-danger rounded-pill px-2.5 py-1 fw-bold" style="font-size: 0.75rem;"><i class="fa-solid fa-circle text-white me-1"></i>TRỰC TIẾP</span>
              <span class="badge bg-dark bg-opacity-75 rounded-pill px-2.5 py-1" style="font-size: 0.75rem;"><i class="fa-solid fa-eye text-warning me-1"></i><span id="liveViewerCount">1,420</span> người xem</span>
              <span class="badge bg-dark bg-opacity-75 rounded-pill px-2.5 py-1 text-info" style="font-size: 0.75rem;"><i class="fa-solid fa-bolt me-1"></i>Latency: 120ms (WebRTC)</span>
            </div>
            <div class="d-flex align-items-center gap-2">
              <span class="badge bg-success bg-opacity-90 rounded-pill px-2.5 py-1" style="font-size: 0.75rem;" title="Hệ thống đang tự động ghi hình phiên Live"><i class="fa-solid fa-record-vinyl text-danger me-1"></i>Recording Active</span>
              <span class="badge bg-info bg-opacity-90 rounded-pill px-2.5 py-1 text-dark fw-bold" style="font-size: 0.75rem;"><i class="fa-solid fa-microphone me-1"></i>AI Transcription</span>
            </div>
          </div>

          <!-- STREAMER & AI CO-HOST BADGE -->
          <div class="position-absolute bottom-0 start-0 p-3 text-white" style="background: linear-gradient(0deg, rgba(0,0,0,0.85) 0%, rgba(0,0,0,0) 100%); width: 100%; z-index: 10;">
            <div class="d-flex align-items-center gap-2 mb-1">
              <div class="rounded-circle bg-success text-white d-flex align-items-center justify-content-center fw-bold" style="width: 36px; height: 36px; border: 2px solid #FFF;">DS</div>
              <div>
                <strong class="d-block text-white" style="font-size: 0.95rem;">DS. Minh Trang & AI Skin Co-Host</strong>
                <small class="text-light opacity-75" style="font-size: 0.75rem;">Đang trình bày: Routine Phục Hồi Da Dầu Mụn Với B5 & Salicylic Acid</small>
              </div>
            </div>
          </div>

          <!-- WEBRTC LIVEKIT STATUS INDICATOR -->
          <div class="position-absolute center text-center p-3 rounded-4" style="background: rgba(15, 23, 42, 0.85); backdrop-filter: blur(8px); border: 1px solid rgba(255,255,255,0.2); max-width: 320px; display: none;" id="livekitConnectingBox">
            <div class="spinner-border text-success mb-2" role="status"></div>
            <div class="fw-bold text-white small">Đang kết nối LiveKit WebRTC Cloud...</div>
            <div class="extra-small text-muted" style="font-size: 0.7rem;">ws_url: wss://skinsyntax-live.livekit.cloud</div>
          </div>
        </div>
      </div>

      <!-- PINNED LIVE SALE PRODUCT BAR -->
      <div class="card border-0 rounded-4 shadow-sm p-3 mt-3" style="background: #F4F8F4; border: 1.5px solid #C5DAC8 !important;">
        <div class="d-flex align-items-center justify-content-between mb-2">
          <span class="badge bg-danger rounded-pill px-3 py-1 fw-bold" style="font-size: 0.75rem;"><i class="fa-solid fa-thumbtack me-1"></i>SẢN PHẨM ĐANG GHIM TRONG LIVE</span>
          <span class="text-success fw-bold small"><i class="fa-solid fa-tags me-1"></i>Đồng Giá Ưu Đãi Trực Tiếp</span>
        </div>
        <div class="d-flex align-items-center gap-3 flex-wrap">
          <img src="<?= h(resolve_image_url($topSaleProducts[0]['link_hinh_anh'] ?? '')) ?>" alt="Pinned Product" class="rounded-3" style="width: 70px; height: 70px; object-fit: cover; border: 1px solid #C5DAC8;">
          <div class="flex-grow-1" style="min-width: 200px;">
            <span class="badge bg-success text-white rounded-pill extra-small mb-1" style="font-size: 0.68rem;"><?= h($topSaleProducts[0]['thuong_hieu'] ?? 'SkinSyntax') ?></span>
            <h6 class="fw-bold text-dark mb-1 text-truncate" style="max-width: 380px; font-size: 0.95rem;"><?= h($topSaleProducts[0]['ten_san_pham'] ?? 'Serum Phục Hồi Da SkinSyntax B5') ?></h6>
            <div class="d-flex align-items-baseline gap-2">
              <span class="fw-extrabold text-success fs-5" style="color: #215427 !important;"><?= vnd($topSaleProducts[0]['gia_ban'] ?? 78000) ?></span>
              <?php if (!empty($topSaleProducts[0]['gia_thi_truong'])): ?>
                <span class="text-muted text-decoration-line-through small"><?= vnd($topSaleProducts[0]['gia_thi_truong']) ?></span>
              <?php endif; ?>
              <span class="badge bg-danger rounded-pill" style="font-size: 0.7rem;">-84% OFF</span>
            </div>
          </div>
          <form method="post" action="<?= BASE_URL ?>/index.php?r=them_gio_hang_ajax" class="m-0 ms-auto">
            <input type="hidden" name="action" value="add_to_cart">
            <input type="hidden" name="buy_now" value="1">
            <input type="hidden" name="product_id" value="<?= h((string)($topSaleProducts[0]['ma_san_pham'] ?? $topSaleProducts[0]['id'] ?? '5876')) ?>">
            <input type="hidden" name="quantity" value="1">
            <button type="submit" class="btn text-white fw-bold px-4 py-2.5 btn-buy-now-pulse rounded-pill" style="background: linear-gradient(135deg, #215427 0%, #162F18 100%); border: none; font-size: 0.9rem;">
              ⚡ MUA NGAY TRONG LIVE
            </button>
          </form>
        </div>
      </div>
    </div>

    <!-- RIGHT: LIVE CHAT SIDEBAR WITH AI AGENT INTEGRATION -->
    <div class="col-lg-4">
      <div class="card border-0 rounded-4 shadow-lg h-100 d-flex flex-column" style="border: 1.5px solid #C5DAC8 !important; background: #FFF;">
        <div class="card-header bg-white p-3 border-bottom d-flex align-items-center justify-content-between" style="border-color: #E2EADF !important;">
          <div class="d-flex align-items-center gap-2">
            <i class="fa-solid fa-comments text-success fs-5"></i>
            <strong class="text-dark" style="font-size: 0.95rem;">Live Chat & AI Agent</strong>
          </div>
          <span class="badge bg-success bg-opacity-10 text-success rounded-pill px-2.5 py-1 extra-small fw-bold"><i class="fa-solid fa-robot me-1"></i>AI Co-Host Active</span>
        </div>

        <!-- LIVE CHAT MESSAGES BODY -->
        <div class="card-body p-3 overflow-auto flex-grow-1" id="liveChatBox" style="max-height: 380px; min-height: 320px; font-size: 0.85rem; background: #F8FAF8;">
          <div class="chat-msg mb-2 p-2 rounded-3 bg-white border" style="border-color: #E2EADF !important;">
            <strong class="text-success" style="color: #215427;"><i class="fa-solid fa-shield-halved me-1"></i>Hệ thống SkinSyntax:</strong> Chúc mừng phiên Live Stream đã kết nối máy chủ LiveKit WebRTC Cloud thành công! AI Agent sẵn sàng hỗ trợ tự động chốt đơn và trả lời tư vấn hoạt chất.
          </div>
          <div class="chat-msg mb-2 p-2 rounded-3 bg-white border" style="border-color: #E2EADF !important;">
            <strong class="text-primary">Thu Trang (Hà Nội):</strong> Sản phẩm kem nền này có kiềm dầu tốt không ạ?
          </div>
          <div class="chat-msg mb-2 p-2.5 rounded-3 text-white" style="background: linear-gradient(135deg, #162F18 0%, #215427 100%);">
            <strong class="text-warning"><i class="fa-solid fa-wand-magic-sparkles me-1"></i>AI Skin Co-Host (RAG):</strong> Qua phân tích dữ liệu bảng thành phần, sản phẩm chứa Silica & BHA giúp hỗ trợ kiềm dầu lên đến 8-12 tiếng liên tục ạ!
          </div>
          <div class="chat-msg mb-2 p-2 rounded-3 bg-white border" style="border-color: #E2EADF !important;">
            <strong class="text-danger">Quốc Bảo (TP.HCM):</strong> chốt đơn 1
          </div>
          <div class="chat-msg mb-2 p-2.5 rounded-3 text-white" style="background: linear-gradient(135deg, #162F18 0%, #215427 100%);">
            <strong class="text-warning"><i class="fa-solid fa-robot me-1"></i>AI Agent Auto-Checkout:</strong> ⚡ Đã tự động chốt đơn 1x [Mini] Kem Nền Lancôme cho anh Quốc Bảo với giá 78.000đ! Vui lòng bấm vào giỏ hàng để xác nhận đơn.
          </div>
        </div>

        <!-- LIVE CHAT INPUT FORM -->
        <div class="card-footer p-3 bg-white border-top" style="border-color: #E2EADF !important;">
          <div class="mb-2 extra-small text-muted" style="font-size: 0.72rem;">
            💡 Mẹo: Gõ <strong>"chốt đơn"</strong> hoặc câu hỏi về hoạt chất da để thử phản hồi AI Agent!
          </div>
          <form id="liveChatForm" class="d-flex gap-2">
            <input type="text" id="liveChatInput" class="form-control form-control-sm rounded-pill px-3" placeholder="Nhập tin nhắn hoặc 'chốt đơn'..." required style="border-color: #C5DAC8;">
            <button type="submit" class="btn btn-sm text-white rounded-pill px-3 fw-bold" style="background: #215427;">
              <i class="fa-solid fa-paper-plane"></i>
            </button>
          </form>
        </div>
      </div>
    </div>
  </div>

  <!-- LIVESTREAM ROOM LIST & ARCHIVES -->
  <div class="mb-5">
    <div class="d-flex align-items-center justify-content-between mb-4">
      <div>
        <h3 class="fw-bold text-dark mb-1" style="color: #1A2F1A;">Các Phiên Live Stream Khác</h3>
        <p class="text-muted small mb-0">Khám phá các phòng LiveStream đang diễn ra và lịch phát sóng sắp tới</p>
      </div>
      <span class="badge bg-success bg-opacity-10 text-success rounded-pill px-3 py-2 fw-bold" style="font-size: 0.82rem;"><i class="fa-solid fa-signal me-1"></i>WebRTC Engine Active</span>
    </div>

    <div class="row g-4">
      <?php foreach ($liveSessions as $session): ?>
        <div class="col-md-6 col-lg-4">
          <div class="card h-100 border-0 rounded-4 shadow-sm overflow-hidden card-elevated" style="border: 1px solid #E2EADF !important; background: #FFF;">
            <div class="position-relative" style="aspect-ratio: 16/9; overflow: hidden; background: #000;">
              <img src="<?= h($session['thumbnail']) ?>" alt="<?= h($session['title']) ?>" style="width: 100%; height: 100%; object-fit: cover; opacity: 0.9;">
              <?php if ($session['status'] === 'live'): ?>
                <span class="badge bg-danger position-absolute top-0 start-0 m-3 px-3 py-1.5 rounded-pill fw-bold" style="font-size: 0.75rem;"><i class="fa-solid fa-circle me-1"></i>🔴 LIVE NOW</span>
                <span class="badge bg-dark bg-opacity-75 position-absolute top-0 end-0 m-3 px-2.5 py-1.5 rounded-pill" style="font-size: 0.72rem;"><i class="fa-solid fa-eye text-warning me-1"></i><?= number_format($session['viewers']) ?></span>
              <?php else: ?>
                <span class="badge bg-warning text-dark position-absolute top-0 start-0 m-3 px-3 py-1.5 rounded-pill fw-bold" style="font-size: 0.75rem;"><i class="fa-solid fa-clock me-1"></i>SẮP DIỄN RA</span>
              <?php endif; ?>
            </div>
            <div class="card-body p-3.5 p-3 d-flex flex-column">
              <div class="small fw-bold text-success mb-1" style="color: #215427 !important;"><i class="fa-solid fa-headset me-1"></i><?= h($session['streamer']) ?></div>
              <h5 class="card-title fw-bold text-dark mb-2" style="font-size: 0.95rem; line-height: 1.4; display: -webkit-box; -webkit-line-clamp: 2; line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden; min-height: 2.7em;">
                <?= h($session['title']) ?>
              </h5>
              <p class="text-muted small mb-3 flex-grow-1" style="font-size: 0.8rem; display: -webkit-box; -webkit-line-clamp: 2; line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden;">
                <?= h($session['description']) ?>
              </p>
              <a href="<?= BASE_URL ?>/index.php?r=live" class="btn btn-sm w-100 rounded-pill fw-bold mt-auto" style="background: <?= $session['status'] === 'live' ? 'linear-gradient(135deg, #215427 0%, #162F18 100%)' : '#EAF0EB' ?>; color: <?= $session['status'] === 'live' ? '#FFF' : '#215427' ?>; border: <?= $session['status'] === 'live' ? 'none' : '1px solid #C5DAC8' ?>;">
                <?= $session['status'] === 'live' ? 'Tham Gia Xem Live & Chat AI' : 'Đăng Ký Nhận Thông Báo' ?>
              </a>
            </div>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  </div>
</div>

<!-- MODAL TẠO PHIÊN LIVE MỚI (DÀNH CHO ADMIN / STREAMER) -->
<div class="modal fade" id="createLiveModal" tabindex="-1" aria-labelledby="createLiveModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content rounded-4 border-0 shadow-lg">
      <div class="modal-header border-bottom p-3" style="border-color: #E2EADF !important;">
        <h5 class="modal-header-title fw-bold text-dark m-0" id="createLiveModalLabel"><i class="fa-solid fa-video text-danger me-2"></i>Tạo Phiên Livestream AI Mới</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body p-4">
        <form id="createLiveForm">
          <div class="mb-3">
            <label class="form-label fw-bold small">Tên Tiêu Đề Phiên Livestream</label>
            <input type="text" class="form-control rounded-3" placeholder="VD: Tư Vấn Routine Đẹp Da Đón Tết Với AI Co-Host..." required>
          </div>
          <div class="mb-3">
            <label class="form-label fw-bold small">Cấu Hình Kết Nối LiveKit Cloud</label>
            <input type="text" class="form-control rounded-3" value="wss://skinsyntax-live.livekit.cloud" readonly style="background: #F8FAF8;">
          </div>
          <div class="mb-3">
            <label class="form-label fw-bold small">Sản Phẩm Ghim Độc Quyền Trực Tiếp</label>
            <select class="form-select rounded-3">
              <option value="5876">[Mini] Kem Nền Lancôme Che Phủ Màu 245C (Giảm 84%)</option>
              <option value="5689">[Mini] Nước Thần Lancôme Clarifique Dưỡng Sáng (Giảm 82%)</option>
              <option value="5933">Dầu Dưỡng Tóc Weilaiya Cánh Hoa Hồng (Giảm 80%)</option>
            </select>
          </div>
          <div class="form-check form-switch mb-3">
            <input class="form-check-input" type="checkbox" id="aiCoHostSwitch" checked>
            <label class="form-check-input-label fw-bold small" for="aiCoHostSwitch">Bật AI Agent Co-Host (Trả lời RAG & Tự động chốt đơn)</label>
          </div>
          <button type="button" class="btn text-white w-100 py-2.5 rounded-pill fw-bold" style="background: linear-gradient(135deg, #215427 0%, #162F18 100%); border: none;" data-bs-dismiss="modal" onclick="alert('Đã khởi tạo thành công phòng LiveStream mới trên LiveKit Cloud với AI Agent Co-Host!');">
            🚀 Khởi Tạo & Bắt Đầu Stream ngay
          </button>
        </form>
      </div>
    </div>
  </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
  const chatForm = document.getElementById('liveChatForm');
  const chatInput = document.getElementById('liveChatInput');
  const chatBox = document.getElementById('liveChatBox');

  if (!chatForm || !chatInput || !chatBox) return;

  chatForm.addEventListener('submit', function(e) {
    e.preventDefault();
    const text = chatInput.value.trim();
    if (!text) return;

    // Append User Message
    const userMsgEl = document.createElement('div');
    userMsgEl.className = 'chat-msg mb-2 p-2 rounded-3 bg-white border';
    userMsgEl.style.borderColor = '#E2EADF';
    userMsgEl.innerHTML = '<strong class="text-dark">Bạn:</strong> ' + escapeHtml(text);
    chatBox.appendChild(userMsgEl);

    chatInput.value = '';
    chatBox.scrollTop = chatBox.scrollHeight;

    // Call Backend AI Agent API
    const formData = new FormData();
    formData.append('message', text);

    fetch('<?= BASE_URL ?>/index.php?r=api_live_chat', {
      method: 'POST',
      body: formData
    })
    .then(res => res.json())
    .then(data => {
      if (data.ok && data.ai_response) {
        setTimeout(() => {
          const aiMsgEl = document.createElement('div');
          aiMsgEl.className = 'chat-msg mb-2 p-2.5 rounded-3 text-white';
          aiMsgEl.style.background = 'linear-gradient(135deg, #162F18 0%, #215427 100%)';
          aiMsgEl.innerHTML = escapeHtml(data.ai_response);
          chatBox.appendChild(aiMsgEl);
          chatBox.scrollTop = chatBox.scrollHeight;
        }, 600);
      }
    })
    .catch(err => console.log('Live chat AI error', err));
  });

  function escapeHtml(str) {
    return String(str).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
  }
});
</script>

<?php require_once __DIR__ . '/layouts/footer.php'; ?>
