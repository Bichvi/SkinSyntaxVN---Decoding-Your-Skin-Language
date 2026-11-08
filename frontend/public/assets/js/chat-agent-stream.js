(function (window, document) {
  'use strict';

  var config = {
    endpoint: 'index.php?r=ai_chat_agent_stream',
    confirmEndpoint: 'index.php?r=chat_order_confirm',
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
        changeMessage: ''
      };
    }
    return draftStates[key];
  };

  var renderDraftCard = function (payload, container, options) {
    payload = stampDeadline(payload || {});
    options = options || {};
    var key = getDraftKey(payload, options);
    var state = getDraftState(key, payload);
    if (options.messageId || payload.message_id) state.messageId = String(options.messageId || payload.message_id);
    if (state.status === 'pending' && draftExpired(state.payload || payload)) state.status = 'expired';
    if (state.status === 'pending' || state.status === 'changed') state.payload = state.payload || payload;
    state.node = container;
    if (state.status === 'cancelled') {
      clearDraftTimer(state);
      container.hidden = true;
      return container;
    }
    container.hidden = false;
    container.className = 'ai-chat-agent-draft-card';
    container.textContent = '';

    if (state.status === 'created') {
      addText(container, 'strong', 'Đã tạo đơn #' + state.orderId);
      var createdConfirm = document.createElement('button');
      createdConfirm.type = 'button';
      createdConfirm.textContent = 'Xác nhận đặt hàng';
      createdConfirm.disabled = true;
      container.appendChild(createdConfirm);
      if (state.redirectMessage) addText(container, 'p', state.redirectMessage);
      return container;
    }

    var currentPayload = state.payload || payload;
    addText(container, 'h4', 'Đơn nháp', 'ai-chat-agent-draft-card__title');
    var list = document.createElement('ul');
    list.className = 'ai-chat-agent-draft-card__items';
    (Array.isArray(currentPayload.items) ? currentPayload.items : []).forEach(function (item) {
      var row = document.createElement('li');
      addText(row, 'span', (item.name || 'Sản phẩm') + ' × ' + (Number(item.qty) || 0));
      addText(row, 'strong', formatPrice(item.line_total));
      list.appendChild(row);
    });
    container.appendChild(list);
    var summary = document.createElement('div');
    summary.className = 'ai-chat-agent-draft-card__summary';
    addText(summary, 'div', 'Tạm tính: ' + formatPrice(currentPayload.subtotal));
    if (currentPayload.voucher && currentPayload.voucher.code) addText(summary, 'div', 'Voucher ' + currentPayload.voucher.code + ': -' + formatPrice(currentPayload.voucher.discount));
    addText(summary, 'div', 'Phí ship: ' + formatPrice(currentPayload.shipping));
    addText(summary, 'strong', 'Tổng: ' + formatPrice(currentPayload.total));
    container.appendChild(summary);
    var receiver = currentPayload.receiver || {};
    addText(container, 'p', 'Người nhận: ' + [receiver.name, receiver.phone_masked, receiver.address_short].filter(Boolean).join(' · '));
    addText(container, 'p', 'Thanh toán: ' + (currentPayload.payment_method || 'Chưa xác định'));
    (Array.isArray(currentPayload.warnings) ? currentPayload.warnings : []).forEach(function (warning) {
      addText(container, 'p', warning, 'ai-chat-agent-draft-card__warning');
    });

    var actions = document.createElement('div');
    actions.className = 'ai-chat-agent-draft-card__actions';
    var confirm = document.createElement('button');
    confirm.type = 'button';
    confirm.textContent = state.status === 'confirming' ? 'Đang xác nhận...' : 'Xác nhận đặt hàng';
    confirm.disabled = state.status !== 'pending';
    var cancel = document.createElement('button');
    cancel.type = 'button';
    cancel.textContent = 'Hủy';
    actions.appendChild(confirm);
    actions.appendChild(cancel);
    container.appendChild(actions);
    var countdown = addText(container, 'small', '', 'ai-chat-agent-draft-card__countdown');
    var expired = function () {
      state.status = 'expired';
      state.errorMessage = '';
      clearDraftTimer(state);
      confirm.disabled = true;
      countdown.textContent = 'Đơn nháp đã hết hạn';
    };
    var tick = function () {
      var remaining = Math.max(0, draftDeadline(currentPayload) - Date.now());
      if (!remaining) return expired();
      countdown.textContent = 'Còn ' + Math.ceil(remaining / 1000) + ' giây';
    };
    if (state.status === 'pending') {
      tick();
      if (state.status === 'pending') {
        clearDraftTimer(state);
        state.timerId = window.setInterval(tick, 1000);
      }
    } else if (state.status === 'expired') {
      clearDraftTimer(state);
      countdown.textContent = 'Đơn nháp đã hết hạn';
    } else {
      clearDraftTimer(state);
      if (state.status === 'changed') countdown.textContent = state.changeMessage || 'Giá đã thay đổi, bạn kiểm tra lại nhé';
    }
    if (state.errorMessage) addText(container, 'p', state.errorMessage, 'ai-chat-agent-draft-card__error');
    if (state.status === 'changed') addText(container, 'p', state.changeMessage || 'Giá đã thay đổi, bạn kiểm tra lại nhé', 'ai-chat-agent-draft-card__warning');

    cancel.addEventListener('click', function () {
      state.status = 'cancelled';
      state.errorMessage = '';
      clearDraftTimer(state);
      container.hidden = true;
    });
    confirm.addEventListener('click', function () {
      if (state.status !== 'pending' || confirm.disabled) return;
      if (draftExpired(currentPayload)) return expired();
      state.status = 'confirming';
      state.errorMessage = '';
      renderDraftCard(currentPayload, state.node || container, { stateKey: key });
      confirmDraft(currentPayload.draft_id).then(function (result) {
        if (result && result.ok && result.status === 'created') {
          state.status = 'created';
          state.orderId = result.order_id;
          state.next = result.next || null;
          state.errorMessage = '';
          clearDraftTimer(state);
          if (state.node) renderDraftCard(currentPayload, state.node, { stateKey: key });
          var next = result.next || {};
          if ((next.type === 'vnpay' || next.type === 'qr') && next.url) {
            if (isAllowedRedirect(next.url, window.location.origin || window.location.href, config.vnpayHost)) {
              window.location.href = next.url;
            } else {
              state.redirectMessage = 'Đã tạo đơn #' + result.order_id + '. Bạn mở mục Đơn hàng của tôi để thanh toán nhé.';
              if (state.node) addText(state.node, 'p', state.redirectMessage);
            }
          }
          return;
        }
        if (result && result.ok && result.status === 'changed') {
          state.status = 'changed';
          state.payload = stampDeadline(result.card || {});
          state.changeMessage = 'Giá đã thay đổi, bạn kiểm tra lại nhé';
          state.errorMessage = '';
          clearDraftTimer(state);
          if (state.node) renderDraftCard(state.payload, state.node, { stateKey: key });
          return;
        }
        if (draftExpired(currentPayload) || state.status === 'expired') {
          state.status = 'expired';
          state.errorMessage = '';
        } else {
          state.status = 'pending';
          state.errorMessage = (result && result.message) || 'Không thể xác nhận đơn hàng.';
        }
        if (state.node) renderDraftCard(currentPayload, state.node, { stateKey: key });
      }).catch(function (error) {
        if (draftExpired(currentPayload) || state.status === 'expired') {
          state.status = 'expired';
          state.errorMessage = '';
        } else {
          state.status = 'pending';
          state.errorMessage = error.message || 'Không thể xác nhận đơn hàng.';
        }
        if (state.node) renderDraftCard(currentPayload, state.node, { stateKey: key });
      });
    });
    return container;
  };

  var confirmDraft = function (draftId) {
    return fetch(config.confirmEndpoint, {
      method: 'POST', credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ draft_id: draftId, csrf: config.csrf })
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
    confirmDraft: confirmDraft
  };
}(window, document));
