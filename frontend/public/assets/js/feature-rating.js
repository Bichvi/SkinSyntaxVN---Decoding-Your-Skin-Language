/*
 * Đánh giá nhanh (1-5 sao) sau khi khách hàng dùng gợi ý sản phẩm, chatbot AI, cảnh báo xung đột thành phần.
 * Chỉ chạy khi footer.php đặt window.SkinSyntaxFeatureRatingConfig (khách đã đăng nhập).
 * Không phụ thuộc thư viện; không sửa ai_chat_widget.php / cart-insights.js (quan sát DOM bằng MutationObserver).
 */
(function (root, factory) {
  'use strict';
  var api = factory(root);
  if (typeof module === 'object' && module.exports) {
    module.exports = api;
  }
  if (root && root.document) {
    root.SkinSyntaxFeatureRating = api;
    var cfg = root.SkinSyntaxFeatureRatingConfig;
    if (cfg && cfg.endpoint && cfg.csrf) {
      if (root.document.readyState === 'loading') {
        root.document.addEventListener('DOMContentLoaded', function () { api.init(cfg); });
      } else {
        api.init(cfg);
      }
    }
  }
})(typeof window !== 'undefined' ? window : this, function (win) {
  'use strict';

  var DAY_MS = 24 * 60 * 60 * 1000;
  var COOLDOWN_RATED_MS = 7 * DAY_MS;
  var COOLDOWN_DISMISSED_MS = 3 * DAY_MS;

  var QUESTIONS = {
    recommendation: 'Các sản phẩm được gợi ý có phù hợp với làn da của bạn không?',
    chatbot: 'Câu trả lời của trợ lý AI có hữu ích với bạn không?',
    cart_conflict: 'Cảnh báo thành phần xung đột có giúp bạn chọn sản phẩm an toàn hơn không?'
  };

  /* ---------- Hàm thuần (có test) ---------- */

  function storageKey(userHash, feature) {
    return 'ssfr:' + String(userHash || 'anon') + ':' + String(feature || '');
  }

  /** record: { t: ms, kind: 'rated' | 'dismissed' } hoặc null. */
  function shouldAsk(record, now) {
    if (!record || typeof record.t !== 'number' || !isFinite(record.t)) { return true; }
    var wait = record.kind === 'rated' ? COOLDOWN_RATED_MS : COOLDOWN_DISMISSED_MS;
    var elapsed = now - record.t;
    // Đồng hồ lệch (t ở tương lai) -> coi là còn trong thời gian chờ.
    return elapsed >= wait;
  }

  /**
   * Chatbot: bước trạng thái theo từng lần DOM đổi.
   * counts = { user, assistant, typing }. Phát tín hiệu khi có tin trợ lý MỚI, khách đã gửi >= 2 tin,
   * khách đã gửi tin trong một lần cập nhật DOM trước đó (loại trường hợp nạp lại lịch sử cả loạt).
   */
  function chatStep(state, counts) {
    var prev = state || { user: counts.user, assistant: counts.assistant, pendingUser: false, pendingAssistant: false };
    var next = {
      user: counts.user,
      assistant: counts.assistant,
      pendingUser: prev.pendingUser,
      pendingAssistant: prev.pendingAssistant
    };
    var assistantGrew = counts.assistant > prev.assistant;
    var userGrew = counts.user > prev.user;
    if (userGrew && !assistantGrew) { next.pendingUser = true; }
    if (assistantGrew && next.pendingUser) { next.pendingAssistant = true; }
    var fire = false;
    if (next.pendingAssistant && next.pendingUser && counts.user >= 2 && !counts.typing) {
      fire = true;
    }
    if (fire || (assistantGrew && !next.pendingUser)) {
      next.pendingUser = false;
      next.pendingAssistant = false;
    }
    return { state: next, fire: fire };
  }

  function countChatRows(streamEl) {
    if (!streamEl || !streamEl.querySelectorAll) { return { user: 0, assistant: 0, typing: 0 }; }
    return {
      user: streamEl.querySelectorAll('.ai-chat-widget__bubble-row--user').length,
      assistant: streamEl.querySelectorAll('.ai-chat-widget__bubble-row--assistant').length,
      typing: streamEl.querySelectorAll('.ai-chat-widget__typing-row').length
    };
  }

  function isVisible(el) {
    if (!el) { return false; }
    if (typeof el.getClientRects === 'function') {
      return el.getClientRects().length > 0;
    }
    return true;
  }

  function collectConflicts(container) {
    var out = { count: 0, ruleIds: [] };
    if (!container || !container.querySelectorAll) { return out; }
    var nodes = container.querySelectorAll('.cin-conflict');
    for (var i = 0; i < nodes.length; i++) {
      if (!isVisible(nodes[i])) { continue; }
      out.count++;
      var rule = String(nodes[i].getAttribute('data-rule') || '').slice(0, 60);
      if (rule && out.ruleIds.indexOf(rule) === -1 && out.ruleIds.length < 10) { out.ruleIds.push(rule); }
    }
    return out;
  }

  function pageRoute(search) {
    var m = /[?&]r=([^&#]*)/.exec(search || '');
    var r = '';
    try { r = m ? decodeURIComponent(m[1]) : ''; } catch (e) { r = m ? m[1] : ''; }
    return r.replace(/[^a-zA-Z0-9_\-]/g, '').slice(0, 60);
  }

  /* ---------- Lưu tần suất ---------- */

  function readRecord(userHash, feature) {
    try {
      var raw = win.localStorage.getItem(storageKey(userHash, feature));
      return raw ? JSON.parse(raw) : null;
    } catch (e) { return null; }
  }

  function writeRecord(userHash, feature, kind) {
    try {
      win.localStorage.setItem(storageKey(userHash, feature), JSON.stringify({ t: Date.now(), kind: kind }));
    } catch (e) { /* localStorage có thể bị chặn */ }
  }

  /* ---------- Giao diện ---------- */

  var state = { cfg: null, shown: false, visible: false, timers: {} };

  function el(tag, cls, text) {
    var node = win.document.createElement(tag);
    if (cls) { node.className = cls; }
    if (text != null) { node.textContent = text; }
    return node;
  }

  function closePopup(card) {
    if (card && card.parentNode) { card.parentNode.removeChild(card); }
    state.visible = false;
  }

  function showPopup(feature, context) {
    var cfg = state.cfg;
    if (!cfg || state.shown || state.visible) { return false; }
    if (!shouldAsk(readRecord(cfg.userHash, feature), Date.now())) { return false; }
    var doc = win.document;
    state.shown = true;
    state.visible = true;

    var selected = 0;
    var card = el('section', 'ssfr-card');
    card.setAttribute('role', 'dialog');
    card.setAttribute('aria-live', 'polite');
    card.setAttribute('aria-labelledby', 'ssfr-title');
    card.setAttribute('data-feature-rating', feature);

    var closeBtn = el('button', 'ssfr-close', '×');
    closeBtn.type = 'button';
    closeBtn.setAttribute('aria-label', 'Đóng');
    var title = el('h2', 'ssfr-title', 'Đánh giá nhanh');
    title.id = 'ssfr-title';
    var question = el('p', 'ssfr-question', QUESTIONS[feature] || '');
    var starsWrap = el('div', 'ssfr-stars');
    starsWrap.setAttribute('role', 'radiogroup');
    starsWrap.setAttribute('aria-label', QUESTIONS[feature] || 'Số sao');
    var labels = el('div', 'ssfr-labels');
    labels.appendChild(el('span', '', 'Không hài lòng'));
    labels.appendChild(el('span', '', 'Rất hài lòng'));
    var extra = el('div', 'ssfr-extra');
    extra.hidden = true;
    var commentLabel = el('label', 'ssfr-comment-label', 'Góp ý thêm (không bắt buộc)');
    var comment = el('textarea', 'ssfr-comment');
    comment.id = 'ssfr-comment';
    comment.maxLength = 500;
    comment.rows = 3;
    commentLabel.setAttribute('for', 'ssfr-comment');
    var message = el('p', 'ssfr-message');
    message.setAttribute('role', 'status');
    var actions = el('div', 'ssfr-actions');
    var skipBtn = el('button', 'ssfr-btn ssfr-btn--ghost', 'Bỏ qua');
    skipBtn.type = 'button';
    var sendBtn = el('button', 'ssfr-btn ssfr-btn--primary', 'Gửi');
    sendBtn.type = 'button';
    sendBtn.disabled = true;
    actions.appendChild(skipBtn);
    actions.appendChild(sendBtn);
    extra.appendChild(commentLabel);
    extra.appendChild(comment);
    card.appendChild(closeBtn);
    card.appendChild(title);
    card.appendChild(question);
    card.appendChild(starsWrap);
    card.appendChild(labels);
    card.appendChild(extra);
    card.appendChild(message);
    card.appendChild(actions);

    var starBtns = [];
    function paint(hover) {
      var upto = hover || selected;
      for (var i = 0; i < starBtns.length; i++) {
        starBtns[i].classList.toggle('is-on', i < upto);
        starBtns[i].setAttribute('aria-checked', String(i + 1 === selected));
        starBtns[i].tabIndex = (selected ? i + 1 === selected : i === 0) ? 0 : -1;
      }
    }
    function pick(n, focus) {
      selected = n;
      extra.hidden = false;
      sendBtn.disabled = false;
      paint(0);
      if (focus) { starBtns[n - 1].focus(); }
    }
    for (var s = 1; s <= 5; s++) {
      (function (n) {
        var b = el('button', 'ssfr-star', '★');
        b.type = 'button';
        b.setAttribute('role', 'radio');
        b.setAttribute('aria-label', n + ' sao');
        b.addEventListener('click', function () { pick(n, false); });
        b.addEventListener('mouseenter', function () { paint(n); });
        b.addEventListener('mouseleave', function () { paint(0); });
        b.addEventListener('keydown', function (ev) {
          var k = ev.key;
          if (k === 'ArrowRight' || k === 'ArrowUp') { ev.preventDefault(); pick(Math.min(5, (selected || n) + 1), true); }
          else if (k === 'ArrowLeft' || k === 'ArrowDown') { ev.preventDefault(); pick(Math.max(1, (selected || n) - 1), true); }
        });
        starBtns.push(b);
        starsWrap.appendChild(b);
      })(s);
    }
    paint(0);

    function dismiss() {
      writeRecord(cfg.userHash, feature, 'dismissed');
      closePopup(card);
    }
    closeBtn.addEventListener('click', dismiss);
    skipBtn.addEventListener('click', dismiss);
    card.addEventListener('keydown', function (ev) { if (ev.key === 'Escape') { dismiss(); } });

    var sending = false;
    sendBtn.addEventListener('click', function () {
      if (!selected || sending) { return; }
      sending = true;
      sendBtn.disabled = true;
      message.textContent = '';
      message.className = 'ssfr-message';
      var payload = {
        feature: feature,
        stars: selected,
        comment: comment.value.slice(0, 500),
        context: { page: pageRoute(win.location.search), rule_ids: (context && context.ruleIds) || [] },
        csrf: cfg.csrf
      };
      win.fetch(cfg.endpoint, {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-Token': cfg.csrf },
        body: JSON.stringify(payload)
      }).then(function (res) {
        return res.json().catch(function () { return {}; }).then(function (data) { return { ok: res.ok && data && data.ok, data: data }; });
      }).then(function (r) {
        if (r.ok) {
          writeRecord(cfg.userHash, feature, 'rated');
          card.classList.add('is-done');
          while (card.firstChild) { card.removeChild(card.firstChild); }
          var thanks = el('p', 'ssfr-thanks', 'Cảm ơn bạn đã đánh giá!');
          thanks.setAttribute('role', 'status');
          card.appendChild(thanks);
          win.setTimeout(function () { closePopup(card); }, 2000);
        } else {
          sending = false;
          sendBtn.disabled = false;
          message.className = 'ssfr-message is-error';
          message.textContent = (r.data && r.data.message) || 'Chưa gửi được đánh giá. Vui lòng thử lại.';
        }
      }).catch(function () {
        sending = false;
        sendBtn.disabled = false;
        message.className = 'ssfr-message is-error';
        message.textContent = 'Chưa gửi được đánh giá. Vui lòng thử lại.';
      });
    });

    doc.body.appendChild(card);
    return true;
  }

  function schedule(feature, delayMs, getContext) {
    if (state.shown || state.timers[feature]) { return; }
    if (!shouldAsk(readRecord(state.cfg.userHash, feature), Date.now())) { return; }
    state.timers[feature] = win.setTimeout(function () {
      state.timers[feature] = null;
      showPopup(feature, getContext ? getContext() : null);
    }, delayMs);
  }

  /* ---------- Các điểm kích hoạt ---------- */

  function watchRecommendation() {
    var rootEl = win.document.querySelector('[data-recommendation-root]');
    if (!rootEl) { return; }
    var done = false;
    function trigger() {
      if (done) { return; }
      done = true;
      schedule('recommendation', 2000);
    }
    rootEl.addEventListener('click', trigger, true);
    win.setTimeout(function () {
      if (win.document.visibilityState !== 'hidden' && isVisible(rootEl)) { trigger(); }
    }, 30000);
  }

  function watchChatbot() {
    var stream = win.document.querySelector('[data-ai-chat-stream]');
    if (!stream || typeof win.MutationObserver !== 'function') { return; }
    var st = null;
    function onChange() {
      var step = chatStep(st, countChatRows(stream));
      st = step.state;
      if (step.fire) { schedule('chatbot', 3000); }
    }
    st = chatStep(null, countChatRows(stream)).state;
    new win.MutationObserver(onChange).observe(stream, { childList: true, subtree: true });
  }

  function watchCart() {
    var box = win.document.querySelector('[data-cart-insights]');
    if (!box || typeof win.MutationObserver !== 'function') { return; }
    function onChange() {
      var found = collectConflicts(box);
      if (found.count > 0) { schedule('cart_conflict', 4000, function () { return collectConflicts(box); }); }
    }
    new win.MutationObserver(onChange).observe(box, { childList: true, subtree: true });
    onChange();
  }

  function init(cfg) {
    if (state.cfg || !win.document || !win.document.body) { return; }
    state.cfg = cfg;
    try { watchRecommendation(); } catch (e) { /* không được làm hỏng trang */ }
    try { watchChatbot(); } catch (e) { /* idem */ }
    try { watchCart(); } catch (e) { /* idem */ }
  }

  return {
    init: init,
    _internals: {
      storageKey: storageKey,
      shouldAsk: shouldAsk,
      chatStep: chatStep,
      countChatRows: countChatRows,
      collectConflicts: collectConflicts,
      pageRoute: pageRoute,
      COOLDOWN_RATED_MS: COOLDOWN_RATED_MS,
      COOLDOWN_DISMISSED_MS: COOLDOWN_DISMISSED_MS
    }
  };
});
