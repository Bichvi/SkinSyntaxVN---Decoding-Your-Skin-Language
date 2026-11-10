/*
 * AI Idol live (trang /live): phát lại video master như một buổi live lặp vòng.
 * livePosition là hàm thuần (không DOM/đồng hồ) để test bằng Node.
 */
(function (root, factory) {
  const api = factory();
  if (typeof module === 'object' && module.exports) module.exports = api;
  else {
    root.AiIdolLive = api;
    if (typeof document !== 'undefined') {
      if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', api.init);
      else api.init();
    }
  }
})(typeof self !== 'undefined' ? self : this, function () {
  'use strict';

  function livePosition(nowSec, startedSec, durationSec) {
    const dur = Number(durationSec);
    if (!(dur > 0) || !isFinite(dur)) return 0;
    const elapsed = Number(nowSec) - Number(startedSec);
    if (!isFinite(elapsed) || elapsed <= 0) return 0;
    const pos = elapsed % dur;
    return pos < 0 ? 0 : pos;
  }

  function init() {
    const wrap = document.getElementById('aiIdolLive');
    const video = document.getElementById('aiIdolLiveVideo');
    if (!wrap || !video) return;

    const startedAt = Number(wrap.dataset.startedAt) || 0;
    const endsAt = Number(wrap.dataset.endsAt) || 0;
    const duration = Number(wrap.dataset.duration) || 0;
    const nowSec = () => Date.now() / 1000;
    const playOverlay = document.getElementById('aiIdolPlayOverlay');
    const endedOverlay = document.getElementById('aiIdolEndedOverlay');
    const muteBtn = document.getElementById('aiIdolMuteBtn');
    let ended = false;

    function target() { return livePosition(nowSec(), startedAt, duration || video.duration); }

    function seekToLive() {
      if (ended || !(video.duration > 0)) return;
      try { video.currentTime = Math.min(target(), Math.max(0, video.duration - 0.05)); } catch (e) { /* ignore */ }
    }

    function tryPlay() {
      const p = video.play();
      if (p && typeof p.then === 'function') {
        p.then(() => { if (playOverlay) playOverlay.hidden = true; })
          .catch(() => { if (playOverlay && !ended) playOverlay.hidden = false; });
      }
    }

    function checkEnded() {
      if (ended || !endsAt || nowSec() < endsAt) return;
      ended = true;
      video.pause();
      if (playOverlay) playOverlay.hidden = true;
      if (endedOverlay) endedOverlay.hidden = false;
    }

    video.addEventListener('loadedmetadata', () => { seekToLive(); tryPlay(); });
    video.addEventListener('seeking', () => {
      if (ended || !(video.duration > 0)) return;
      if (Math.abs(video.currentTime - target()) > 2) seekToLive();
    });
    document.addEventListener('visibilitychange', () => {
      if (document.visibilityState !== 'visible') return;
      checkEnded();
      if (ended) return;
      seekToLive();
      if (video.paused) tryPlay();
    });

    if (playOverlay) {
      playOverlay.addEventListener('click', () => { seekToLive(); tryPlay(); });
    }
    if (muteBtn) {
      muteBtn.addEventListener('click', () => {
        video.muted = !video.muted;
        muteBtn.textContent = video.muted ? 'Bật tiếng' : 'Tắt tiếng';
        if (video.paused && !ended) tryPlay();
      });
    }

    // Thêm vào giỏ: dùng api_live_add_to_cart có sẵn.
    wrap.querySelectorAll('.ai-idol-live__add').forEach((btn) => {
      btn.addEventListener('click', () => {
        const pid = btn.getAttribute('data-product-id');
        if (!pid || btn.disabled) return;
        const original = btn.textContent;
        btn.disabled = true;
        const body = new URLSearchParams({ product_id: pid, quantity: '1' });
        fetch('index.php?r=api_live_add_to_cart', { method: 'POST', body, credentials: 'same-origin' })
          .then((r) => r.json())
          .then((data) => {
            if (data && data.ok) {
              btn.textContent = 'Đã thêm';
              document.querySelectorAll('.cart-count, #cartCount, .cart-badge').forEach((el) => { el.textContent = data.cart_count; });
            } else {
              alert((data && data.message) || 'Không thể thêm sản phẩm');
            }
          })
          .catch(() => { /* giữ nguyên nút */ })
          .finally(() => { setTimeout(() => { btn.disabled = false; btn.textContent = original; }, 1500); });
      });
    });

    setInterval(checkEnded, 1000);
    checkEnded();
    if (video.readyState >= 1) { seekToLive(); tryPlay(); }
  }

  return { livePosition, init };
});
