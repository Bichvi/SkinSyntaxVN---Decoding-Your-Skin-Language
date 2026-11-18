(function (window, document) {
  'use strict';

  var config = {
    endpoint: 'index.php?r=ai_chat_agent_stream',
    confirmEndpoint: 'index.php?r=chat_order_confirm',
    otpEndpoint: 'index.php?r=chat_order_otp',
    csrf: '',
    vnpayHost: ''
  };
  var activeRequest = null;
  var draftStates = Object.create(null);

  var formatPrice = function (value) {
    var amount = Number(value);
    if (!Number.isFinite(amount)) amount = 0;
    return new Intl.NumberFormat('vi-VN').format(amount) + 'đ';
  };

  var parseSseBlock = function (block) {
    var eventName = 'message';
    var data = [];
    String(block || '').split(/\r\n|\n|\r/).forEach(function (line) {
      if (line.indexOf('event:') === 0) eventName = line.slice(6).trim();
      if (line.indexOf('data:') === 0) data.push(line.slice(5).replace(/^ /, ''));
    });
    if (!data.length) return null;
    var value;
    try { value = JSON.parse(data.join('\n')); } catch (e) { value = { message: data.join('\n') }; }
    return { event: eventName, data: value };
  };

  var isAbortError = function (error) {
    return !!error && (error.name === 'AbortError' || error.code === 20);
  };

  var abortActive = function () {
    var request = activeRequest;
    if (!request) return;
    request.aborted = true;
    activeRequest = null;
    request.controller.abort();
    if (request.reader && typeof request.reader.cancel === 'function') {
      try { request.reader.cancel(); } catch (e) {}
    }
  };

  var dispatch = function (parsed, handlers, request) {
    if (!parsed || request.aborted || activeRequest !== request) return;
    var handler = handlers[parsed.event];
    if (typeof handler === 'function') handler(parsed.data || {});
  };

  var send = function (options) {
    options = options || {};
    abortActive();
    var request = {
      controller: new AbortController(),
      reader: null,
      aborted: false
    };
    activeRequest = request;
    var handlers = {
      meta: options.onMeta,
      token: options.onToken,
      ui: options.onUi,
      cart_updated: options.onCartUpdated,
      done: options.onDone,
      error: options.onError
    };

    var readResponse = function (response) {
      if (response.status === 404) {
        return response.json().catch(function () { return {}; }).then(function (payload) {
          if (payload && payload.fallback === true) return { fallback: true };
          throw new Error('Trợ lý đang bận, bạn thử lại sau nhé.');
        });
      }
      if (!response.ok || !response.body) throw new Error('Agent response unavailable');
      request.reader = response.body.getReader();
      var decoder = new TextDecoder();
      var buffer = '';
      var read = function () {
        return request.reader.read().then(function (part) {
          buffer += decoder.decode(part.value || new Uint8Array(), { stream: !part.done });
          var chunks = buffer.split(/\r\n\r\n|\n\n|\r\r/);
          buffer = chunks.pop() || '';
          chunks.forEach(function (chunk) { dispatch(parseSseBlock(chunk), handlers, request); });
          return part.done ? null : read();
        });
      };
      return read().then(function () {
        if (buffer.trim()) dispatch(parseSseBlock(buffer), handlers, request);
        return { fallback: false };
      });
    };

    return fetch(config.endpoint, {
      method: 'POST',
      credentials: 'same-origin',
      signal: request.controller.signal,
      headers: { 'Content-Type': 'application/json', Accept: 'text/event-stream' },
      body: JSON.stringify({ message: String(options.message || ''), conversation_id: options.conversationId || undefined })
    }).then(readResponse).catch(function (error) {
      if (request.aborted || isAbortError(error)) return { aborted: true };
      if (activeRequest === request) activeRequest = null;
      if (typeof options.onError === 'function') options.onError({ message: error.message || 'Không thể kết nối đến máy chủ.' });
      throw error;
    }).then(function (result) {
      if (activeRequest === request) activeRequest = null;
      return result;
    });
  };

  var isAllowedRedirect = function (url, origin, vnpayHost) {
    if (typeof url !== 'string' || url.trim() === '') return false;
    var base;
    var parsed;
    try {
      base = new URL(String(origin || window.location.href));
      parsed = new URL(url, base.href);
    } catch (e) {
      return false;
    }
    if (parsed.protocol !== 'http:' && parsed.protocol !== 'https:') return false;
    if (parsed.origin === base.origin) return true;
    var configuredHost = String(vnpayHost || '').trim().toLowerCase();
    if (parsed.protocol !== 'https:' || configuredHost === '') return false;
    try {
      var configuredUrl = new URL('https://' + configuredHost);
      return parsed.host.toLowerCase() === configuredUrl.host.toLowerCase();
    } catch (e) {
      return false;
    }
  };

  var addText = function (parent, tag, value, className) {
    var node = document.createElement(tag);
    if (className) node.className = className;
    node.textContent = String(value == null ? '' : value);
    parent.appendChild(node);
    return node;
  };

  var getDraftKey = function (payload, options) {
    options = options || {};
    // An explicit stateKey is used by our own re-renders; otherwise a draft is identified by its draft_id.
    return String(options.stateKey || payload.draft_id || options.messageId || payload.message_id || 'draft');
  };

  var clearDraftTimer = function (state) {
    if (state && state.timerId != null) {
      window.clearInterval(state.timerId);
      state.timerId = null;
    }
  };

  // Server and browser clocks may differ: fix the local deadline once, when the card first arrives, and reuse it.
  var stampDeadline = function (payload) {
    if (!payload || typeof payload !== 'object' || Number.isFinite(payload.deadline_ms)) return payload;
    var expiresAt = Number(payload.expires_at);
    var serverNow = Number(payload.server_now);
    if (Number.isFinite(expiresAt) && Number.isFinite(serverNow) && serverNow > 0) {
      payload.deadline_ms = Date.now() + (expiresAt - serverNow) * 1000;
    }
    return payload;
  };

  var draftDeadline = function (payload) {
    return Number.isFinite(payload.deadline_ms) ? payload.deadline_ms : Number(payload.expires_at || 0) * 1000;
  };

  var draftExpired = function (payload) {
    return draftDeadline(payload) <= Date.now();
  };

  var getDraftState = function (key, payload) {
    if (!draftStates[key]) {
      draftStates[key] = {
        key: key,
        messageId: '',
        status: 'pending',
        timerId: null,
        node: null,
        payload: payload,
        orderId: null,
        next: null,
        redirectMessage: '',
        errorMessage: '',
        changeMessage: '',
        otpEmail: '',
        otpDraftId: '',
        otpExpiresAt: 0,
        resendAt: 0,
        otpValue: ''
      };
    }
    return draftStates[key];
  };

  var FALLBACK_IMAGE = 'data:image/svg+xml,%3Csvg xmlns=%22http://www.w3.org/2000/svg%22 width=%2292%22 height=%2292%22 viewBox=%220 0 92 92%22%3E%3Crect width=%2292%22 height=%2292%22 rx=%2214%22 fill=%22%23edf4ef%22/%3E%3Cpath d=%22M27 60l13-15 9 10 8-9 8 14H27z%22 fill=%22%2396aca0%22/%3E%3Ccircle cx=%2236%22 cy=%2233%22 r=%226%22 fill=%22%23bfd0c5%22/%3E%3C/svg%3E';

  var PAYMENT_LABELS = {
    cod: 'Thanh toán khi nhận hàng (COD)',
    vnpay: 'VNPay (thẻ/ ví)',
    bank_transfer_qr: 'Chuyển khoản QR'
  };

  var ICON_PATHS = {
    user: ['M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2', 'M12 3a4 4 0 1 0 0 8 4 4 0 0 0 0-8z'],
    pin: ['M21 10c0 7-9 13-9 13S3 17 3 10a9 9 0 0 1 18 0z', 'M12 7a3 3 0 1 0 0 6 3 3 0 0 0 0-6z'],
    card: ['M4 5h16a2 2 0 0 1 2 2v10a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V7a2 2 0 0 1 2-2z', 'M2 10h20'],
    check: ['M5 13l4 4L19 7']
  };

  // Decorative inline SVG (aria-hidden). Falls back to a plain element where createElementNS is unavailable.
  var makeIcon = function (name, className) {
    var ns = 'http://www.w3.org/2000/svg';
    var make = function (tag) { return document.createElementNS ? document.createElementNS(ns, tag) : document.createElement(tag); };
    var svg = make('svg');
    svg.setAttribute('viewBox', '0 0 24 24');
    svg.setAttribute('fill', 'none');
    svg.setAttribute('stroke', 'currentColor');
    svg.setAttribute('stroke-width', '2');
    svg.setAttribute('stroke-linecap', 'round');
    svg.setAttribute('stroke-linejoin', 'round');
    svg.setAttribute('aria-hidden', 'true');
    svg.setAttribute('focusable', 'false');
    if (className) svg.setAttribute('class', className);
    (ICON_PATHS[name] || []).forEach(function (d) {
      var path = make('path');
      path.setAttribute('d', d);
      svg.appendChild(path);
    });
    return svg;
  };

  var formatClock = function (totalSeconds) {
    var secs = Math.max(0, Math.floor(totalSeconds));
    var s = secs % 60;
    return Math.floor(secs / 60) + ':' + (s < 10 ? '0' : '') + s;
  };

  var renderDraftCard = function (payload, container, options) {
    payload = stampDeadline(payload || {});
    options = options || {};
    var key = getDraftKey(payload, options);
    var state = getDraftState(key, payload);
    if (options.messageId || payload.message_id) state.messageId = String(options.messageId || payload.message_id);
    if ((state.status === 'pending' || state.status === 'otp') && draftExpired(state.payload || payload)) state.status = 'expired';
    if (state.status === 'pending' || state.status === 'changed') state.payload = state.payload || payload;
    state.node = container;
    if (state.status === 'cancelled') {
      clearDraftTimer(state);
      container.hidden = true;
      return container;
    }
    container.hidden = false;
    var cardClass = 'ai-chat-agent-draft-card';
    container.className = cardClass;
    container.textContent = '';
    if (container.setAttribute) {
      container.setAttribute('role', 'region');
      container.setAttribute('aria-label', 'Đơn nháp');
    }

    if (state.status === 'created') {
      container.className = cardClass + ' is-created';
      var doneBox = document.createElement('div');
      doneBox.className = 'ai-chat-agent-draft-card__success';
      var doneIcon = document.createElement('span');
      doneIcon.className = 'ai-chat-agent-draft-card__success-icon';
      doneIcon.appendChild(makeIcon('check'));
      doneBox.appendChild(doneIcon);
      addText(doneBox, 'strong', 'Đặt hàng thành công', 'ai-chat-agent-draft-card__success-title');
      addText(doneBox, 'p', 'Đã tạo đơn #' + state.orderId, 'ai-chat-agent-draft-card__order-id');
      var doneTotal = (state.payload || payload || {}).total;
      if (doneTotal != null) {
        var doneRow = document.createElement('div');
        doneRow.className = 'ai-chat-agent-draft-card__row ai-chat-agent-draft-card__row--total';
        addText(doneRow, 'span', 'Tổng thanh toán');
        addText(doneRow, 'strong', formatPrice(doneTotal));
        doneBox.appendChild(doneRow);
      }
      var orderLink = addText(doneBox, 'a', 'Xem đơn hàng của tôi', 'ai-chat-agent-draft-card__link');
      orderLink.href = 'index.php?r=hoso';
      container.appendChild(doneBox);
      if (state.redirectMessage) addText(container, 'p', state.redirectMessage, 'ai-chat-agent-draft-card__note');
      return container;
    }

    var currentPayload = state.payload || payload;

    // Header: title + countdown pill.
    var head = document.createElement('div');
    head.className = 'ai-chat-agent-draft-card__head';
    var headText = document.createElement('div');
    headText.className = 'ai-chat-agent-draft-card__head-text';
    addText(headText, 'h4', 'Xác nhận đơn hàng', 'ai-chat-agent-draft-card__title');
    addText(headText, 'p', 'Đơn nháp · kiểm tra lại trước khi đặt', 'ai-chat-agent-draft-card__sub');
    head.appendChild(headText);
    var countdown = addText(head, 'span', '', 'ai-chat-agent-draft-card__countdown');
    container.appendChild(head);
    var notice = addText(container, 'p', '', 'ai-chat-agent-draft-card__notice');
    notice.hidden = true;

    // Items.
    var list = document.createElement('ul');
    list.className = 'ai-chat-agent-draft-card__items';
    (Array.isArray(currentPayload.items) ? currentPayload.items : []).forEach(function (item) {
      var row = document.createElement('li');
      row.className = 'ai-chat-agent-draft-card__item';
      var thumb = document.createElement('img');
      thumb.className = 'ai-chat-agent-draft-card__thumb';
      thumb.alt = '';
      thumb.loading = 'lazy';
      thumb.src = String(item.image_url || item.image || '') || FALLBACK_IMAGE;
      thumb.addEventListener('error', function () {
        if (thumb.src !== FALLBACK_IMAGE) thumb.src = FALLBACK_IMAGE;
      });
      row.appendChild(thumb);
      var info = document.createElement('div');
      info.className = 'ai-chat-agent-draft-card__item-info';
      addText(info, 'span', item.name || 'Sản phẩm', 'ai-chat-agent-draft-card__item-name');
      addText(info, 'span', 'SL: ' + (Number(item.qty) || 0) + ' × ' + formatPrice(item.unit_price != null ? item.unit_price : item.line_total), 'ai-chat-agent-draft-card__item-meta');
      row.appendChild(info);
      addText(row, 'strong', formatPrice(item.line_total), 'ai-chat-agent-draft-card__item-total');
      list.appendChild(row);
    });
    container.appendChild(list);

    // Price summary.
    var summary = document.createElement('div');
    summary.className = 'ai-chat-agent-draft-card__summary';
    var summaryRow = function (label, value, modifier) {
      var row = document.createElement('div');
      row.className = 'ai-chat-agent-draft-card__row' + (modifier ? ' ai-chat-agent-draft-card__row--' + modifier : '');
      addText(row, 'span', label);
      addText(row, modifier === 'total' ? 'strong' : 'span', value);
      summary.appendChild(row);
    };
    summaryRow('Tạm tính', formatPrice(currentPayload.subtotal));
    if (currentPayload.voucher && currentPayload.voucher.code) {
      summaryRow('Giảm giá (' + currentPayload.voucher.code + ')', '−' + formatPrice(currentPayload.voucher.discount), 'discount');
    }
    summaryRow('Phí vận chuyển', formatPrice(currentPayload.shipping));
    var divider = document.createElement('div');
    divider.className = 'ai-chat-agent-draft-card__divider';
    summary.appendChild(divider);
    summaryRow('Tổng thanh toán', formatPrice(currentPayload.total), 'total');
    container.appendChild(summary);

    // Delivery details.
    var receiver = currentPayload.receiver || {};
    var delivery = document.createElement('div');
    delivery.className = 'ai-chat-agent-draft-card__delivery';
    var deliveryRow = function (icon, label, value, muted) {
      var row = document.createElement('div');
      row.className = 'ai-chat-agent-draft-card__info';
      row.appendChild(makeIcon(icon, 'ai-chat-agent-draft-card__icon'));
      var body = document.createElement('div');
      body.className = 'ai-chat-agent-draft-card__info-body';
      addText(body, 'span', label, 'ai-chat-agent-draft-card__info-label');
      addText(body, 'span', value, 'ai-chat-agent-draft-card__info-value' + (muted ? ' is-muted' : ''));
      row.appendChild(body);
      delivery.appendChild(row);
    };
    // The server adds the full phone/address for the owner's browser; the masked/short form is the fallback.
    var receiverPhone = receiver.phone || receiver.phone_masked;
    var receiverAddress = receiver.address || receiver.address_short;
    deliveryRow('user', 'Người nhận', [receiver.name, receiverPhone].filter(Boolean).join(' · ') || 'Chưa có thông tin', !(receiver.name || receiverPhone));
    deliveryRow('pin', 'Giao tới', receiverAddress || 'Chưa có địa chỉ', !receiverAddress);
    deliveryRow('card', 'Thanh toán', PAYMENT_LABELS[currentPayload.payment_method] || String(currentPayload.payment_method || 'Chưa xác định'));
    container.appendChild(delivery);

    (Array.isArray(currentPayload.warnings) ? currentPayload.warnings : []).forEach(function (warning) {
      addText(container, 'p', warning, 'ai-chat-agent-draft-card__warning');
    });

    var actions = document.createElement('div');
    actions.className = 'ai-chat-agent-draft-card__actions';
    var confirm = document.createElement('button');
    confirm.type = 'button';
    confirm.className = 'ai-chat-agent-draft-card__btn ai-chat-agent-draft-card__btn--primary ai-chat-agent-draft-card__confirm';
    confirm.textContent = state.status === 'otp_sending' ? 'Đang gửi mã...'
      : (state.status === 'confirming' ? 'Đang xác nhận...' : 'Xác nhận đặt hàng');
    confirm.disabled = state.status !== 'pending' && state.status !== 'changed';
    actions.appendChild(confirm);
    addText(actions, 'p', 'Mã xác nhận sẽ được gửi tới email của bạn', 'ai-chat-agent-draft-card__hint');
    var cancel = document.createElement('button');
    cancel.type = 'button';
    cancel.className = 'ai-chat-agent-draft-card__btn ai-chat-agent-draft-card__btn--text ai-chat-agent-draft-card__cancel';
    cancel.textContent = 'Hủy';
    actions.appendChild(cancel);
    container.appendChild(actions);

    var otpInput = null;
    var otpSubmit = null;
    var otpResend = null;
    if (state.status === 'otp' || (state.status === 'confirming' && state.otpEmail)) {
      container.className = cardClass + ' is-otp';
      var otpBox = document.createElement('div');
      otpBox.className = 'ai-chat-agent-draft-card__otp';
      addText(otpBox, 'h5', 'Nhập mã xác nhận', 'ai-chat-agent-draft-card__otp-title');
      addText(otpBox, 'p', 'Mã xác nhận đã gửi tới ' + state.otpEmail, 'ai-chat-agent-draft-card__otp-hint');
      otpInput = document.createElement('input');
      otpInput.type = 'text';
      otpInput.inputMode = 'numeric';
      otpInput.autocomplete = 'one-time-code';
      otpInput.maxLength = 6;
      otpInput.pattern = '\\d{6}';
      otpInput.placeholder = 'Nhập mã 6 số';
      otpInput.className = 'ai-chat-agent-draft-card__otp-input';
      if (otpInput.setAttribute) otpInput.setAttribute('aria-label', 'Mã xác nhận 6 số');
      otpInput.value = state.otpValue || '';
      otpInput.disabled = state.status !== 'otp';
      otpBox.appendChild(otpInput);
      if (state.errorMessage) {
        // Right under the code field, where the customer is looking (the card bottom may be scrolled out of view).
        var otpError = addText(otpBox, 'p', state.errorMessage, 'ai-chat-agent-draft-card__error');
        if (otpError.setAttribute) otpError.setAttribute('role', 'alert');
      }
      if (state.status === 'confirming') addText(otpBox, 'p', 'Đang xác nhận...', 'ai-chat-agent-draft-card__otp-status');
      var otpActions = document.createElement('div');
      otpActions.className = 'ai-chat-agent-draft-card__actions';
      otpSubmit = document.createElement('button');
      otpSubmit.type = 'button';
      otpSubmit.className = 'ai-chat-agent-draft-card__btn ai-chat-agent-draft-card__btn--primary ai-chat-agent-draft-card__otp-submit';
      otpSubmit.textContent = 'Xác nhận';
      otpSubmit.disabled = state.status !== 'otp' || !/^\d{6}$/.test(state.otpValue || '');
      otpResend = document.createElement('button');
      otpResend.type = 'button';
      otpResend.className = 'ai-chat-agent-draft-card__btn ai-chat-agent-draft-card__btn--text ai-chat-agent-draft-card__resend';
      otpResend.textContent = 'Gửi lại mã';
      otpResend.disabled = state.status !== 'otp';
      otpActions.appendChild(otpSubmit);
      otpActions.appendChild(otpResend);
      otpBox.appendChild(otpActions);
      container.appendChild(otpBox);
      if (state.focusOtp && state.status === 'otp') {
        // Only right after the OTP box opens or a code is rejected, so a background re-render never steals focus.
        state.focusOtp = false;
        if (otpInput.focus) { try { otpInput.focus(); } catch (e) { /* detached node */ } }
      }
    }
    var updateResend = function () {
      if (!otpResend || state.status !== 'otp') return;
      var left = Math.ceil((state.resendAt - Date.now()) / 1000);
      otpResend.disabled = left > 0;
      otpResend.textContent = left > 0 ? 'Gửi lại mã (' + left + 's)' : 'Gửi lại mã';
    };
    updateResend();

    var showExpired = function () {
      container.className = cardClass + ' is-expired';
      countdown.className = 'ai-chat-agent-draft-card__countdown is-expired';
      countdown.textContent = 'Đã hết hạn';
      notice.textContent = 'Đơn nháp đã hết hạn';
      notice.hidden = false;
    };
    var expired = function () {
      state.status = 'expired';
      state.errorMessage = '';
      clearDraftTimer(state);
      confirm.disabled = true;
      if (otpInput) otpInput.disabled = true;
      if (otpSubmit) otpSubmit.disabled = true;
      if (otpResend) otpResend.disabled = true;
      showExpired();
    };
    var tick = function () {
      var remaining = Math.max(0, draftDeadline(currentPayload) - Date.now());
      if (!remaining) return expired();
      var secs = Math.ceil(remaining / 1000);
      countdown.className = 'ai-chat-agent-draft-card__countdown' + (secs < 20 ? ' is-urgent' : (secs < 60 ? ' is-warn' : ''));
      countdown.textContent = 'Còn ' + formatClock(secs);
      updateResend();
    };
    var ticking = state.status === 'pending' || state.status === 'otp' || state.status === 'otp_sending';
    if (ticking) {
      tick();
      if (state.status !== 'expired') {
        clearDraftTimer(state);
        state.timerId = window.setInterval(tick, 1000);
      }
    } else if (state.status === 'expired') {
      clearDraftTimer(state);
      showExpired();
    } else {
      clearDraftTimer(state);
      if (state.status === 'changed') {
        countdown.className = 'ai-chat-agent-draft-card__countdown is-warn';
        countdown.textContent = 'Giá đã thay đổi';
      }
    }
    if (state.errorMessage && !otpInput) addText(container, 'p', state.errorMessage, 'ai-chat-agent-draft-card__error');
    if (state.status === 'changed') addText(container, 'p', state.changeMessage || 'Giá đã thay đổi, bạn kiểm tra lại nhé', 'ai-chat-agent-draft-card__warning');

    var rerender = function () {
      if (state.node) renderDraftCard(state.payload || currentPayload, state.node, { stateKey: key });
    };

    // Resolves a confirm response (sent together with the OTP).
    var handleConfirmResult = function (result) {
      if (result && result.ok && result.status === 'created') {
        state.status = 'created';
        state.orderId = result.order_id;
        state.next = result.next || null;
        state.errorMessage = '';
        state.otpValue = '';
        state.otpDraftId = '';
        clearDraftTimer(state);
        if (state.node) renderDraftCard(currentPayload, state.node, { stateKey: key });
        var next = result.next || {};
        if ((next.type === 'vnpay' || next.type === 'qr') && next.url) {
          if (isAllowedRedirect(next.url, window.location.origin || window.location.href, config.vnpayHost)) {
            window.location.href = next.url;
          } else {
            state.redirectMessage = 'Đã tạo đơn #' + result.order_id + '. Bạn mở mục Đơn hàng của tôi để thanh toán nhé.';
            if (state.node) addText(state.node, 'p', state.redirectMessage, 'ai-chat-agent-draft-card__note');
          }
        }
        return;
      }
      if (result && result.ok && result.status === 'changed') {
        state.status = 'changed';
        state.payload = stampDeadline(result.card || {});
        state.changeMessage = 'Giá đã thay đổi, bạn kiểm tra lại nhé';
        state.errorMessage = '';
        state.otpValue = '';
        // The server rebinds a still-valid code to the replacement draft.
        if (state.otpDraftId && state.payload.draft_id) state.otpDraftId = String(state.payload.draft_id);
        clearDraftTimer(state);
        if (state.node) renderDraftCard(state.payload, state.node, { stateKey: key });
        return;
      }
      var code = result && result.code ? String(result.code) : '';
      var message = (result && result.message) || 'Không thể xác nhận đơn hàng.';
      if (draftExpired(currentPayload) || state.status === 'expired') {
        state.status = 'expired';
        state.errorMessage = '';
      } else if (code === 'otp_invalid') {
        state.status = 'otp';
        state.errorMessage = message;
        state.otpValue = '';
      } else if (code === 'otp_expired' || code === 'otp_locked' || code === 'otp_required') {
        state.status = 'otp';
        state.errorMessage = message;
        state.otpValue = '';
        state.resendAt = 0;
      } else {
        state.status = state.otpDraftId ? 'otp' : 'pending';
        state.errorMessage = message;
      }
      state.focusOtp = state.status === 'otp';
      rerender();
    };

    var handleConfirmError = function (error) {
      if (draftExpired(currentPayload) || state.status === 'expired') {
        state.status = 'expired';
        state.errorMessage = '';
      } else {
        state.status = state.otpDraftId ? 'otp' : 'pending';
        state.errorMessage = (error && error.message) || 'Không thể xác nhận đơn hàng.';
      }
      rerender();
    };

    var requestCode = function (fallbackStatus) {
      state.status = 'otp_sending';
      state.errorMessage = '';
      rerender();
      requestOtp(currentPayload.draft_id).then(function (result) {
        if (result && result.ok) {
          var now = Date.now();
          state.status = 'otp';
          state.otpEmail = String(result.email_masked || '');
          state.otpDraftId = String(currentPayload.draft_id);
          state.otpExpiresAt = now + (Number(result.expires_in) || 300) * 1000;
          state.resendAt = now + (Number(result.resend_in) || 60) * 1000;
          state.otpValue = '';
          state.errorMessage = '';
          state.focusOtp = true;
        } else if (draftExpired(currentPayload)) {
          state.status = 'expired';
          state.errorMessage = '';
        } else if (result && result.code === 'otp_cooldown' && state.otpDraftId) {
          state.status = 'otp';
          state.errorMessage = result.message || '';
        } else {
          state.status = fallbackStatus;
          state.errorMessage = (result && result.message) || 'Không thể gửi mã xác nhận.';
        }
        rerender();
      }).catch(function (error) {
        state.status = state.status === 'expired' ? 'expired' : fallbackStatus;
        state.errorMessage = (error && error.message) || 'Không thể gửi mã xác nhận.';
        rerender();
      });
    };

    cancel.addEventListener('click', function () {
      state.status = 'cancelled';
      state.errorMessage = '';
      clearDraftTimer(state);
      container.hidden = true;
    });
    confirm.addEventListener('click', function () {
      if ((state.status !== 'pending' && state.status !== 'changed') || confirm.disabled) return;
      if (draftExpired(currentPayload)) return expired();
      var fallbackStatus = state.status;
      // A code that is still valid (e.g. after a price change) is reused instead of mailing a new one.
      if (state.otpDraftId && state.otpDraftId === String(currentPayload.draft_id) && state.otpExpiresAt > Date.now()) {
        state.status = 'otp';
        state.errorMessage = '';
        state.changeMessage = '';
        return rerender();
      }
      requestCode(fallbackStatus);
    });
    if (otpInput) {
      otpInput.addEventListener('input', function () {
        state.otpValue = String(otpInput.value || '').replace(/\D/g, '').slice(0, 6);
        otpInput.value = state.otpValue;
        otpSubmit.disabled = state.status !== 'otp' || !/^\d{6}$/.test(state.otpValue);
      });
      otpSubmit.addEventListener('click', function () {
        if (state.status !== 'otp' || !/^\d{6}$/.test(state.otpValue || '')) return;
        if (draftExpired(currentPayload)) return expired();
        var code = state.otpValue;
        state.status = 'confirming';
        state.errorMessage = '';
        rerender();
        confirmDraft(currentPayload.draft_id, code).then(handleConfirmResult).catch(handleConfirmError);
      });
      otpResend.addEventListener('click', function () {
        if (state.status !== 'otp' || otpResend.disabled) return;
        if (draftExpired(currentPayload)) return expired();
        requestCode('otp');
      });
    }
    return container;
  };

  var requestOtp = function (draftId) {
    return fetch(config.otpEndpoint, {
      method: 'POST', credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ draft_id: draftId, csrf: config.csrf })
    }).then(function (response) { return response.json(); });
  };

  var confirmDraft = function (draftId, otp) {
    var body = { draft_id: draftId, csrf: config.csrf };
    if (otp != null) body.otp = String(otp);
    return fetch(config.confirmEndpoint, {
      method: 'POST', credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(body)
    }).then(function (response) { return response.json(); });
  };

  var cleanupDraftCards = function (root) {
    Object.keys(draftStates).forEach(function (key) {
      var state = draftStates[key];
      if (state.node && (!root || !root.contains(state.node))) {
        clearDraftTimer(state);
        state.node = null;
      }
    });
  };

  var renderUi = function (payload, container, actions) {
    payload = payload || {};
    if (payload.kind === 'draft_card') return renderDraftCard(payload.payload || {}, container, { messageId: payload.messageId || payload.message_id });
    var data = payload.payload || {};
    container.textContent = '';
    if (payload.kind === 'clarify') {
      addText(container, 'p', data.question || 'Bạn muốn chọn phương án nào?');
      (Array.isArray(data.options) ? data.options : []).forEach(function (option) {
        var button = document.createElement('button'); button.type = 'button'; button.textContent = String(option);
        button.addEventListener('click', function () { if (actions && actions.onSend) actions.onSend(String(option)); });
        container.appendChild(button);
      });
    } else if (payload.kind === 'login_required') {
      var link = document.createElement('a'); link.href = 'index.php?r=dangnhap'; link.textContent = 'Đăng nhập để tiếp tục'; container.appendChild(link);
    } else if (payload.kind === 'order_list') {
      (Array.isArray(data.orders) ? data.orders : []).forEach(function (order) {
        var row = document.createElement('p');
        addText(row, 'strong', '#' + (order.order_id || '') + ' · ' + formatPrice(order.total));
        addText(row, 'span', ' · ' + (order.status || ''));
        container.appendChild(row);
      });
    } else if (payload.kind === 'product_list' && data && Array.isArray(data.products)) {
      addText(container, 'p', 'Sản phẩm phù hợp:');
      data.products.forEach(function (product) { addText(container, 'p', product.name || 'Sản phẩm'); });
    }
    return container;
  };

  window.SkinSyntaxAgent = {
    configure: function (values) { Object.assign(config, values || {}); },
    send: send,
    abort: abortActive,
    parseSseBlock: parseSseBlock,
    formatPrice: formatPrice,
    isAllowedRedirect: isAllowedRedirect,
    renderDraftCard: renderDraftCard,
    cleanupDraftCards: cleanupDraftCards,
    getDraftCardState: function (id) {
      var key = String(id);
      if (draftStates[key]) return draftStates[key];
      var match = Object.keys(draftStates).filter(function (candidate) { return draftStates[candidate].messageId === key; });
      return match.length ? draftStates[match[match.length - 1]] : null;
    },
    renderUi: renderUi,
    confirmDraft: confirmDraft,
    requestOtp: requestOtp
  };
}(window, document));
