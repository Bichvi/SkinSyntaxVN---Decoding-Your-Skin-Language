/* Chụp bảng thành phần trong widget chat Syna: nén ảnh, gửi lên chat_label_scan, vẽ thẻ kết quả.
 * Module độc lập (IIFE). Mọi chuỗi từ server đều được escape trước khi chèn vào HTML. */
(function (global) {
  'use strict';

  var ALLOWED_MIMES = ['image/jpeg', 'image/png', 'image/webp'];
  var PRODUCT_TYPES = ['cleanser', 'toner', 'serum', 'moisturizer', 'sunscreen', 'mask', 'other'];
  var SKIN_LABELS = { dau: 'Da dầu', kho: 'Da khô', hon_hop: 'Da hỗn hợp', thuong: 'Da thường' };
  var VERDICT_EMOJI = { good: '🟢', caution: '🟡', avoid: '🔴', neutral: '⚪', unknown: '⚪' };
  var VERDICT_LABEL = { good: 'Phù hợp', caution: 'Cần lưu ý', avoid: 'Nên tránh', neutral: 'Trung tính', unknown: 'Chưa đủ thông tin' };
  var SEVERITY_EMOJI = { high: '🔴', medium: '🟡', low: '⚪' };
  var ALT_REASON = { gentler_fits_skin: 'Dịu nhẹ hơn và hợp da bạn', no_cart_conflict: 'Không xung đột với giỏ hàng' };
  var ERROR_TEXT = {
    disabled: 'Tính năng chụp bảng thành phần hiện chưa được bật.',
    csrf: 'Phiên làm việc đã hết hạn. Bạn tải lại trang rồi thử lại nhé.',
    rate_limited: 'Bạn gửi hơi nhanh. Vui lòng đợi vài phút rồi thử lại nhé.',
    too_large: 'Ảnh quá lớn (tối đa 4 MB). Bạn chụp lại hoặc chọn ảnh nhỏ hơn nhé.',
    bad_type: 'Chỉ nhận ảnh JPG, PNG hoặc WEBP.',
    bad_text: 'Danh sách thành phần cần từ 20 đến 6000 ký tự.',
    unavailable: 'Syna tạm thời chưa đọc được ảnh. Bạn có thể dán danh sách thành phần vào đây.'
  };
  var TIPS = [
    'Chụp mặt sau sản phẩm, nơi in bảng thành phần (Ingredients).',
    'Chụp đủ sáng, tránh chói và tránh bị mờ.',
    'Giữ máy thẳng, chụp sát phần chữ để đọc rõ từng dòng.'
  ];
  var DISCLAIMER = 'Kết quả tham khảo, không thay thế tư vấn da liễu. Ảnh chỉ dùng để đọc chữ, không lưu lại.';
  var TEXT_MIN = 20;
  var TEXT_MAX = 6000;

  var deps = { escapeHtml: null, renderProductCards: null, baseUrl: '' };
  var currentId = '';
  var states = {};  // msgId -> {skin_type, sensitive} (đã chọn tay)
  var drafts = {};  // msgId -> chữ đang sửa dở
  var editOpen = {}; // msgId -> hộp sửa đang mở/đóng
  var results = {}; // msgId -> result (để nút sửa / chọn loại da biết dữ liệu gốc)
  var thumbs = {};  // msgId -> object URL (chỉ trong bộ nhớ, không lưu sessionStorage)
  var thumbOrder = [];

  // ------------------------------------------------------------------ pure helpers

  function fallbackEscape(value) {
    return String(value == null ? '' : value)
      .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
  }

  function esc(value) {
    var fn = typeof deps.escapeHtml === 'function' ? deps.escapeHtml : fallbackEscape;
    return fn(value == null ? '' : String(value));
  }

  function computeTargetSize(width, height, maxEdge) {
    var limit = maxEdge || 1600;
    var w = Math.max(1, Math.round(Number(width) || 0));
    var h = Math.max(1, Math.round(Number(height) || 0));
    var longEdge = Math.max(w, h);
    if (longEdge <= limit) {
      return { width: w, height: h };
    }
    var scale = limit / longEdge;
    return { width: Math.max(1, Math.round(w * scale)), height: Math.max(1, Math.round(h * scale)) };
  }

  function isAllowedMime(mime) {
    return ALLOWED_MIMES.indexOf(String(mime || '').toLowerCase()) !== -1;
  }

  function safeUrl(url, baseUrl) {
    var u = String(url == null ? '' : url).trim();
    if (u === '' || /[\u0000-\u001f\s\\]/.test(u)) {
      return '';
    }
    if (u.charAt(0) === '/') {
      return u.charAt(1) === '/' ? '' : u;
    }
    if (u.indexOf('index.php') === 0 || u.indexOf('./index.php') === 0) {
      return u;
    }
    var base = baseUrl == null ? deps.baseUrl : baseUrl;
    if (base && /^https?:\/\//i.test(base) && u.indexOf(base) === 0) {
      return u;
    }
    return '';
  }

  function verdictEmoji(verdict) {
    return VERDICT_EMOJI[verdict] || VERDICT_EMOJI.unknown;
  }

  function slotLabel(slot) {
    return slot === 'am' ? 'buổi sáng' : (slot === 'pm' ? 'buổi tối' : '');
  }

  function scheduleText(item, scanName, cartName) {
    var kind = item && item.kind;
    var scan = scanName || 'Sản phẩm này';
    var cart = cartName || 'sản phẩm trong giỏ';
    if (kind === 'split_session') {
      var a = slotLabel(item.scan_slot);
      var b = slotLabel(item.cart_slot);
      if (a && b) {
        return scan + ' ' + a + ' · ' + cart + ' ' + b;
      }
      return scan + ' và ' + cart + ' nên dùng tách buổi';
    }
    if (kind === 'alternate_nights') {
      return scan + ' và ' + cart + ': xen kẽ các tối';
    }
    if (kind === 'alternate_days') {
      return scan + ' và ' + cart + ': dùng xen kẽ ngày';
    }
    return '';
  }

  function validProductType(value) {
    return PRODUCT_TYPES.indexOf(value) !== -1 ? value : '';
  }

  function baseFields(result, csrf, conversationId) {
    var product = (result && result.product) || {};
    var out = {};
    if (product.name) { out.product_name = String(product.name); }
    if (product.brand) { out.brand = String(product.brand); }
    var type = validProductType(product.product_type);
    if (type) { out.product_type = type; }
    out.csrf = csrf || '';
    if (conversationId) { out.conversation_id = String(conversationId); }
    return out;
  }

  function ingredientsTextFor(result) {
    var ing = (result && result.ingredients) || {};
    var raw = String(ing.raw_text || '').trim();
    if (raw.length >= TEXT_MIN) { return raw; }
    var items = Array.isArray(ing.items) ? ing.items : [];
    return items.map(function (it) { return String((it && it.name) || '').trim(); }).filter(Boolean).join(', ');
  }

  function truncateName(name) {
    var n = String(name || '').replace(/\s+/g, ' ').trim();
    if (n.length > 60) { n = n.slice(0, 60).replace(/\s+\S*$/, '').trim() || n.slice(0, 60); n += '…'; }
    return n;
  }

  function followUpTexts(result) {
    var name = truncateName(result && result.product && result.product.name) || 'sản phẩm vừa quét';
    var conflicts = Array.isArray(result && result.conflicts) ? result.conflicts : [];
    var first = conflicts[0] ? String((conflicts[0].cart_product && conflicts[0].cart_product.name) || conflicts[0].cart_product_name || '') : '';
    var out = [];
    out.push(first ? 'Dùng ' + name + ' chung với ' + truncateName(first) + ' được không?' : name + ' có hợp với da mình không?');
    out.push('Xếp routine sáng/tối có ' + name + ' cho tôi');
    return out;
  }

  function buildEditPayload(result, text, csrf, conversationId) {
    var out = baseFields(result, csrf, conversationId);
    out.ingredients_text = String(text || '').trim();
    return out;
  }

  function buildQuickPickPayload(result, skinKey, sensitive, csrf, conversationId) {
    var out = baseFields(result, csrf, conversationId);
    out.ingredients_text = ingredientsTextFor(result);
    out.skin_type_override = JSON.stringify({ skin_type: skinKey || null, sensitive: !!sensitive });
    return out;
  }

  function buildImagePayload(blob, csrf, conversationId) {
    var out = { csrf: csrf || '' };
    if (conversationId) { out.conversation_id = String(conversationId); }
    return out;
  }

  function errorText(code, messageVi) {
    if (messageVi && typeof messageVi === 'string') { return messageVi; }
    return ERROR_TEXT[code] || ERROR_TEXT.unavailable;
  }

  // ------------------------------------------------------------------ rendering

  function link(url, label, cls) {
    var safe = safeUrl(url);
    if (!safe) {
      return '<span' + (cls ? ' class="' + cls + '"' : '') + '>' + esc(label) + '</span>';
    }
    return '<a' + (cls ? ' class="' + cls + '"' : '') + ' href="' + esc(safe) + '">' + esc(label) + '</a>';
  }

  function renderPasteBox(result, hidden, withTitle) {
    var id = currentId;
    if (id && Object.prototype.hasOwnProperty.call(editOpen, id)) { hidden = !editOpen[id]; }
    var draft = id && typeof drafts[id] === 'string' ? drafts[id] : ((result && result.ingredients && result.ingredients.raw_text) || '');
    return '<div class="ai-label-card__edit" data-ls-edit' + (hidden ? ' hidden' : '') + '>'
      + (withTitle ? '<div class="ai-label-card__edit-title">Dán danh sách thành phần của sản phẩm</div>' : '')
      + '<textarea class="ai-label-card__textarea" rows="5" data-ls-text maxlength="' + TEXT_MAX + '" placeholder="Water, Glycerin, Niacinamide, ...">'
      + esc(draft) + '</textarea>'
      + '<button type="button" class="ai-label-card__btn ai-label-card__btn--primary" data-ls-action="reanalyze">Phân tích lại</button>'
      + '</div>';
  }

  function renderTips() {
    return '<ul class="ai-label-card__tips">' + TIPS.map(function (t) { return '<li>' + esc(t) + '</li>'; }).join('') + '</ul>';
  }

  function renderHeader(result) {
    var product = result.product || {};
    var name = String(product.name || '').trim();
    var brand = String(product.brand || '').trim();
    var shop = safeUrl(product.url);
    if (!name && !brand && !shop) { return ''; }
    return '<div class="ai-label-card__head">'
      + '<div class="ai-label-card__name">' + esc(name || 'Sản phẩm trong ảnh') + '</div>'
      + (brand ? '<div class="ai-label-card__brand">' + esc(brand) + '</div>' : '')
      + (shop ? '<a class="ai-label-card__shop" href="' + esc(shop) + '">✓ Có bán tại shop</a>' : '')
      + '</div>';
  }

  function renderIngredients(result, editOpen) {
    var ing = result.ingredients || {};
    var items = Array.isArray(ing.items) ? ing.items : [];
    var total = Number(ing.total) || items.length;
    if (!total && !items.length) { return ''; }
    var label = function (it) { return String((it && (it.name_vi || it.name)) || '').trim(); };
    var chips = items.filter(function (it) { return it && it.highlight && label(it); }).slice(0, 6);
    var html = '<div class="ai-label-card__block ai-label-card__ing">'
      + '<div class="ai-label-card__title">Đã đọc ' + esc(total) + ' thành phần'
      + (ing.recognized != null && Number(ing.recognized) !== total ? ' (nhận diện được ' + esc(ing.recognized) + ')' : '') + '</div>';
    if (chips.length) {
      html += '<div class="ai-label-card__chips">' + chips.map(function (it) {
        return '<span class="ai-label-card__chip">' + esc(label(it)) + '</span>';
      }).join('') + '</div>';
    }
    var names = items.map(label).filter(Boolean);
    if (names.length) {
      html += '<details class="ai-label-card__all"><summary>Xem đầy đủ thành phần</summary><div>' + esc(names.join(', ')) + '</div></details>';
    }
    if (ing.source !== 'catalog') {
      html += '<button type="button" class="ai-label-card__btn" data-ls-action="edit-toggle">✏️ Sửa nếu đọc sai</button>'
        + renderPasteBox(result, !editOpen, false);
    }
    return html + '</div>';
  }

  function cartName(result, id) {
    var list = Array.isArray(result.conflicts) ? result.conflicts : [];
    for (var i = 0; i < list.length; i++) {
      if (list[i] && list[i].cart_product_id === id) {
        return String((list[i].cart_product && list[i].cart_product.name) || list[i].cart_product_name || '');
      }
    }
    return '';
  }

  function renderConflicts(result) {
    var conflicts = Array.isArray(result.conflicts) ? result.conflicts : [];
    var schedule = Array.isArray(result.schedule) ? result.schedule : [];
    var scanName = String((result.product && result.product.name) || '').trim();
    var lines = schedule.map(function (s) {
      var text = scheduleText(s, scanName, cartName(result, s && s.cart_product_id));
      if (!text) { return ''; }
      return '<div class="ai-label-card__schedule">🗓 ' + esc(text)
        + (s.reason_vi ? ' <span class="ai-label-card__muted">' + esc(s.reason_vi) + '</span>' : '') + '</div>';
    }).join('');
    var sun = result.sunscreen_note === true
      ? '<div class="ai-label-card__sun">☀️ Dùng hoạt chất này thì ban ngày nhớ thoa kem chống nắng nhé.</div>' : '';
    if (!conflicts.length && !lines && !sun) { return ''; }
    var html = '<div class="ai-label-card__block ai-label-card__conflicts">';
    if (conflicts.length) {
      html += '<div class="ai-label-card__title">Đối chiếu với giỏ hàng</div>' + conflicts.map(function (c) {
        var sev = SEVERITY_EMOJI[c && c.severity] ? c.severity : 'low';
        var name = String((c.cart_product && c.cart_product.name) || c.cart_product_name || 'Sản phẩm trong giỏ');
        return '<div class="ai-label-card__conflict ai-label-card__conflict--' + sev + '">'
          + SEVERITY_EMOJI[sev] + ' ' + link(c.cart_product && c.cart_product.url, name, 'ai-label-card__cartlink')
          + '<div class="ai-label-card__advice">' + esc(c.advice_vi || '') + '</div></div>';
      }).join('');
    }
    return html + lines + sun + '</div>';
  }

  function renderSkinFit(result) {
    var fit = result.skin_fit || {};
    var warnings = Array.isArray(result.warnings) ? result.warnings : [];
    var missing = warnings.indexOf('profile_missing') !== -1;
    var groups = [['good', '✅'], ['caution', '⚠️'], ['avoid', '⛔']];
    var count = 0;
    groups.forEach(function (g) { count += Array.isArray(fit[g[0]]) ? fit[g[0]].length : 0; });
    var verdict = fit.verdict || 'unknown';
    if (verdict === 'unknown' && !count && !missing) { return ''; }
    var profile = fit.profile || {};
    var parts = [];
    if (SKIN_LABELS[profile.skin_type]) { parts.push(SKIN_LABELS[profile.skin_type]); }
    if (profile.sensitive) { parts.push('nhạy cảm'); }
    var html = '<div class="ai-label-card__block ai-label-card__fit">'
      + '<div class="ai-label-card__title">' + verdictEmoji(verdict) + ' ' + esc(VERDICT_LABEL[verdict] || VERDICT_LABEL.unknown)
      + (parts.length ? ' <span class="ai-label-card__muted">· ' + esc(parts.join(', ')) + '</span>' : '') + '</div>';
    groups.forEach(function (g) {
      (Array.isArray(fit[g[0]]) ? fit[g[0]] : []).forEach(function (f) {
        html += '<div class="ai-label-card__finding">' + g[1] + ' ' + esc((f && f.reason_vi) || '') + '</div>';
      });
    });
    var st = states[currentId] || null;
    if (missing || st) {
      var pressed = st ? !!st.sensitive : !!profile.sensitive;
      html += '<div class="ai-label-card__muted">Bạn chọn loại da để Syna đánh giá chính xác hơn:</div>'
        + '<div class="ai-label-card__quick">'
        + [['dau', 'Da dầu'], ['kho', 'Da khô'], ['hon_hop', 'Hỗn hợp'], ['thuong', 'Thường']].map(function (p) {
          return '<button type="button" class="ai-label-card__btn" data-ls-action="skin" data-ls-skin="' + p[0] + '" aria-pressed="' + (st && st.skin_type === p[0] ? 'true' : 'false') + '">' + esc(p[1]) + '</button>';
        }).join('')
        + '<button type="button" class="ai-label-card__btn ai-label-card__btn--toggle" data-ls-sensitive aria-pressed="'
        + (pressed ? 'true' : 'false') + '">Nhạy cảm</button></div>';
    }
    return html + '</div>';
  }

  function renderAlternatives(result) {
    var alts = Array.isArray(result.alternatives) ? result.alternatives : [];
    var warnings = Array.isArray(result.warnings) ? result.warnings : [];
    if (!alts.length || warnings.indexOf('graph_degraded') !== -1) { return ''; }
    var mapped = alts.map(function (a) {
      return {
        id: a.id, name: a.name, brand: a.brand || '', price: a.price, image_url: a.image_url, detail_url: safeUrl(a.url),
        summary: ALT_REASON[a.reason_code] || ''
      };
    });
    var cards = '';
    if (typeof deps.renderProductCards === 'function') {
      cards = '<div class="ai-chat-widget__product-group">' + (deps.renderProductCards(mapped) || []).join('') + '</div>';
    } else {
      cards = mapped.map(function (p) {
        return '<div class="ai-label-card__alt">' + link(p.detail_url, p.name || 'Sản phẩm', 'ai-label-card__cartlink')
          + (p.summary ? '<div class="ai-label-card__muted">' + esc(p.summary) + '</div>' : '') + '</div>';
      }).join('');
    }
    return '<div class="ai-label-card__block ai-label-card__alts"><div class="ai-label-card__title">Gợi ý thay thế dịu nhẹ hơn</div>' + cards + '</div>';
  }

  function renderFollowUps(result) {
    return '<div class="ai-label-card__followups">' + followUpTexts(result).map(function (t) {
      return '<button type="button" class="ai-label-card__chipbtn" data-ls-action="followup" data-ls-text-value="' + esc(t) + '">' + esc(t) + '</button>';
    }).join('') + '</div>';
  }

  function renderCard(result, msgId, state) {
    if (!result || typeof result !== 'object') { return ''; }
    var id = msgId == null ? '' : String(msgId);
    currentId = id;
    if (id) { results[id] = result; if (state && typeof state === 'object') { states[id] = state; } }
    var status = result.status || 'ok';
    var root = function (inner, extra) {
      return '<div class="ai-label-card' + (extra ? ' ' + extra : '') + '" data-ls-card data-ls-msg="' + esc(id) + '">' + inner + '</div>';
    };
    if (status === 'not_label') {
      return root('<div class="ai-label-card__title">Mẹo chụp để Syna đọc được</div>' + renderTips(), 'ai-label-card--note');
    }
    if (status === 'vision_unavailable' || status === 'unreadable') {
      return root(renderPasteBox(result, false, true), 'ai-label-card--note');
    }
    if (status !== 'ok') { return ''; }

    var warnings = Array.isArray(result.warnings) ? result.warnings : [];
    var low = warnings.indexOf('low_recognition') !== -1;
    var html = '';
    if (low) {
      html += '<div class="ai-label-card__warn">⚠️ Kết quả có thể thiếu. Bạn kiểm tra và sửa danh sách bên dưới nếu cần.</div>';
    }
    html += renderHeader(result) + renderIngredients(result, low) + renderConflicts(result);
    if (warnings.indexOf('cart_empty') !== -1) {
      html += '<div class="ai-label-card__muted">Giỏ hàng đang trống nên Syna chưa kiểm tra xung đột.</div>';
    }
    html += renderSkinFit(result) + renderAlternatives(result) + renderFollowUps(result)
      + '<div class="ai-label-card__disclaimer">' + esc(DISCLAIMER) + '</div>';
    return root(html);
  }

  function thumbHtml(msgId) {
    var url = thumbs[String(msgId)];
    if (!url) { return ''; }
    return '<img class="ai-label-card__thumb" src="' + esc(url) + '" alt="Ảnh nhãn sản phẩm">';
  }

  // ------------------------------------------------------------------ browser wiring

  function compressImage(file, maxBytes) {
    return new Promise(function (resolve, reject) {
      var objectUrl = URL.createObjectURL(file);
      var img = new Image();
      img.onload = function () {
        try {
          var size = computeTargetSize(img.naturalWidth || img.width, img.naturalHeight || img.height, 1600);
          var canvas = document.createElement('canvas');
          canvas.width = size.width;
          canvas.height = size.height;
          var ctx = canvas.getContext('2d');
          ctx.fillStyle = '#fff';
          ctx.fillRect(0, 0, size.width, size.height);
          ctx.drawImage(img, 0, 0, size.width, size.height);
          var qualities = [0.85, 0.7, 0.55];
          var attempt = function (i) {
            if (i >= qualities.length) { reject({ code: 'too_large' }); return; }
            canvas.toBlob(function (blob) {
              if (!blob) { reject({ code: 'unavailable' }); return; }
              if (blob.size <= maxBytes) { resolve(blob); return; }
              attempt(i + 1);
            }, 'image/jpeg', qualities[i]);
          };
          attempt(0);
        } catch (e) {
          reject({ code: 'unavailable' });
        } finally {
          URL.revokeObjectURL(objectUrl);
        }
      };
      img.onerror = function () { URL.revokeObjectURL(objectUrl); reject({ code: 'unavailable' }); };
      img.src = objectUrl;
    });
  }

  function toFormData(fields, blob) {
    var fd = new FormData();
    Object.keys(fields).forEach(function (k) { fd.append(k, fields[k]); });
    if (blob) { fd.append('image', blob, 'label.jpg'); }
    return fd;
  }

  function init(opts) {
    opts = opts || {};
    var widget = opts.widget;
    if (!widget || !opts.endpoint || typeof opts.addMessage !== 'function') { return; }
    deps.escapeHtml = opts.escapeHtml || null;
    deps.renderProductCards = opts.renderProductCards || null;
    var m = String(opts.endpoint).match(/^(https?:\/\/.*?\/)index\.php/i);
    deps.baseUrl = m ? m[1] : '';

    var form = opts.form || widget;
    var btn = form.querySelector('[data-ai-label-btn]');
    var input = form.querySelector('[data-ai-label-input]');
    var busy = false;

    var setBusy = function (value) {
      busy = value;
      if (btn) { btn.disabled = value; }
    };
    var convId = function () {
      return opts.isLoggedIn && typeof opts.getConversationId === 'function' ? (opts.getConversationId() || '') : '';
    };
    var say = function (text) { opts.addMessage({ role: 'assistant', content: text }); };
    var uid = function (p) { return p + Date.now() + '-' + Math.floor(Math.random() * 1e6); };

    var post = function (fields, blob) {
      var ctl = typeof AbortController !== 'undefined' ? new AbortController() : null;
      var timer = setTimeout(function () { if (ctl) { ctl.abort(); } }, 30000);
      return fetch(opts.endpoint, {
        method: 'POST', body: toFormData(fields, blob), credentials: 'same-origin', signal: ctl ? ctl.signal : undefined
      }).then(function (res) { return res.json(); })
        .catch(function () { return null; })
        .then(function (json) { clearTimeout(timer); return json; });
    };

    var toMessage = function (json, id, state) {
      if (json && json.ok === true && json.result && typeof json.result === 'object') {
        var msg = { role: 'assistant', content: String(json.result.summary_vi || 'Syna đã đọc xong bảng thành phần.'), labelScan: json.result, id: id };
        if (state) { msg.labelScanState = state; }
        return msg;
      }
      var failed = { role: 'assistant', content: errorText(json && json.code, json && json.message_vi), id: id };
      if (!json || json.code === 'unavailable') {
        // lời nhắn nhắc dán thành phần -> hiện luôn ô dán
        failed.labelScan = { status: 'vision_unavailable', product: {}, ingredients: {} };
      }
      return failed;
    };

    var rerunOverride = function (card, msgId, result, skin, sensitive) {
      var text = ingredientsTextFor(result);
      if (text.length < TEXT_MIN) {
        say('Để đổi loại da, bạn gửi lại ảnh bảng thành phần giúp Syna nhé.');
        return;
      }
      var payload = buildQuickPickPayload(result, skin, sensitive, opts.csrf, convId());
      setBusy(true);
      card.classList.add('is-busy');
      post(payload).then(function (json) {
        if (json && json.ok === true && json.result) {
          states[msgId] = { skin_type: skin || null, sensitive: !!sensitive };
          opts.replaceMessage(msgId, toMessage(json, msgId, states[msgId]));
        } else {
          say(errorText(json && json.code, json && json.message_vi)); // giữ nguyên thẻ cũ
        }
      }).catch(function () { say(ERROR_TEXT.unavailable); })
        .then(function () { card.classList.remove('is-busy'); setBusy(false); });
    };

    var withTyping = function (run) {
      var typingId = uid('ls-t-');
      opts.addMessage({ role: 'assistant', content: '...', typing: true, typingText: 'Syna đang đọc nhãn…', id: typingId });
      return run().then(function (json) {
        opts.replaceMessage(typingId, toMessage(json, uid('ls-a-')));
      });
    };

    var rememberThumb = function (id, blob) {
      thumbs[id] = URL.createObjectURL(blob);
      thumbOrder.push(id);
      while (thumbOrder.length > 3) {
        var old = thumbOrder.shift();
        try { URL.revokeObjectURL(thumbs[old]); } catch (e) { /* ignore */ }
        delete thumbs[old];
      }
    };

    var scanFile = function (file) {
      if (busy || !file) { return; }
      if (!isAllowedMime(file.type)) { say(ERROR_TEXT.bad_type); return; }
      setBusy(true);
      compressImage(file, 4 * 1024 * 1024).then(function (blob) {
        var userId = uid('ls-u-');
        rememberThumb(userId, blob);
        opts.addMessage({ role: 'user', content: '📷 Ảnh nhãn sản phẩm', id: userId, labelThumb: true });
        return withTyping(function () { return post(buildImagePayload(blob, opts.csrf, convId()), blob); });
      }).catch(function (err) {
        say(err && err.code === 'too_large' ? 'Ảnh quá lớn. Bạn chụp lại hoặc chọn ảnh nhỏ hơn nhé.' : ERROR_TEXT.unavailable);
      }).then(function () { setBusy(false); });
    };

    if (btn && input) {
      btn.addEventListener('click', function () { if (!busy) { input.click(); } });
      input.addEventListener('change', function () {
        var file = input.files && input.files[0];
        input.value = '';
        scanFile(file);
      });
    }

    widget.addEventListener('click', function (event) {
      var target = event.target && event.target.closest ? event.target.closest('[data-ls-action],[data-ls-sensitive]') : null;
      if (!target) { return; }
      var card = target.closest('[data-ls-card]');
      if (!card) { return; }
      var msgId = card.getAttribute('data-ls-msg') || '';
      var result = results[msgId];
      var action = target.getAttribute('data-ls-action');

      if (action === 'followup') {
        if (typeof opts.sendMessage === 'function') { opts.sendMessage(target.getAttribute('data-ls-text-value') || ''); }
        return;
      }
      if (action === 'edit-toggle') {
        var box = card.querySelector('[data-ls-edit]');
        if (box) { box.hidden = !box.hidden; editOpen[msgId] = !box.hidden; }
        return;
      }
      if (!result || busy) { return; }
      var profile = (result.skin_fit && result.skin_fit.profile) || {};
      var cur = states[msgId] || { skin_type: null, sensitive: !!profile.sensitive };

      if (target.hasAttribute('data-ls-sensitive')) {
        rerunOverride(card, msgId, result, cur.skin_type, !cur.sensitive);
        return;
      }
      if (action === 'reanalyze') {
        var ta = card.querySelector('[data-ls-text]');
        var text = ta ? String(ta.value || '').trim() : '';
        if (text.length < TEXT_MIN || text.length > TEXT_MAX) { say(ERROR_TEXT.bad_text); return; }
        setBusy(true);
        withTyping(function () { return post(buildEditPayload(result, text, opts.csrf, convId())); })
          .then(function () { delete drafts[msgId]; delete editOpen[msgId]; })
          .catch(function () { say(ERROR_TEXT.unavailable); })
          .then(function () { setBusy(false); });
        return;
      }
      if (action === 'skin') {
        rerunOverride(card, msgId, result, target.getAttribute('data-ls-skin'), cur.sensitive);
      }
    });

    widget.addEventListener('input', function (event) {
      var ta = event.target && event.target.closest ? event.target.closest('[data-ls-text]') : null;
      var card = ta && ta.closest('[data-ls-card]');
      if (card) { drafts[card.getAttribute('data-ls-msg') || ''] = String(ta.value || ''); }
    });

    global.addEventListener && global.addEventListener('pagehide', function () {
      Object.keys(thumbs).forEach(function (k) { try { URL.revokeObjectURL(thumbs[k]); } catch (e) { /* ignore */ } });
    });
  }

  global.SkinSyntaxLabelScan = {
    init: init,
    renderCard: renderCard,
    thumbHtml: thumbHtml,
    _test: {
      computeTargetSize: computeTargetSize,
      isAllowedMime: isAllowedMime,
      safeUrl: safeUrl,
      verdictEmoji: verdictEmoji,
      scheduleText: scheduleText,
      buildEditPayload: buildEditPayload,
      buildQuickPickPayload: buildQuickPickPayload,
      followUpTexts: followUpTexts,
      ingredientsTextFor: ingredientsTextFor,
      errorText: errorText,
      renderCard: renderCard
    }
  };
})(typeof window !== 'undefined' ? window : this);
