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
      var toggleProfileBtns = widget.querySelectorAll('[data-ai-chat-toggle-profile]');
      var profileRestrictedBtn = widget.querySelector('[data-ai-chat-profile-restricted]');
      var profileBanner = widget.querySelector('[data-ai-profile-banner]');
      var storageScope = widget.getAttribute('data-storage-scope') || 'guest';
      var storageKey = 'aiChatMessagesV4:' + storageScope;
      var streamUrl = stream ? (stream.getAttribute('data-ai-stream-url') || '') : '';
      var syncUrl = stream ? (stream.getAttribute('data-ai-sync-url') || '') : '';
      var commerceUrl = stream ? (stream.getAttribute('data-ai-commerce-url') || '') : '';
      var siteBaseUrl = stream ? (stream.getAttribute('data-ai-base-url') || '') : '';
      var aiSessionId = stream ? (stream.getAttribute('data-ai-session-id') || storageScope) : storageScope;
      var closeTimer = null;
      var messages = [];
      var cartState = { items: [], item_count: 0, total_qty: 0, subtotal: 0, shipping_fee: 30000, grand_total: 30000 };
      var cartDrawer = widget.querySelector('[data-ai-cart-drawer]');
      var cartBody = widget.querySelector('[data-ai-cart-body]');
      var cartFoot = widget.querySelector('[data-ai-cart-foot]');
      var cartTotalEl = widget.querySelector('[data-ai-cart-total]');
      var cartBadge = widget.querySelector('[data-ai-cart-badge]');
      var checkoutDrawer = widget.querySelector('[data-ai-checkout-drawer]');
      var checkoutBody = widget.querySelector('[data-ai-checkout-body]');
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
          var isExpanded = widget.classList.contains('is-expanded');
          var viewportPadding = isMobile ? 16 : 24;
          var availableHeight = Math.max(300, window.innerHeight - nextBottom - viewportPadding);
          var maxHeightLimit = isMobile ? (isExpanded ? 800 : 580) : (isExpanded ? 1200 : 620);
          panel.style.maxHeight = Math.min(maxHeightLimit, availableHeight) + 'px';
        }
      };

      var currencyFormatter = new Intl.NumberFormat('vi-VN');

      var callCommerce = function (action, payload) {
        if (!commerceUrl) {
          return Promise.reject(new Error('Commerce API unavailable'));
        }
        return fetch(commerceUrl, {
          method: 'POST',
          credentials: 'same-origin',
          headers: {
            'Content-Type': 'application/json',
            'Accept': 'application/json'
          },
          body: JSON.stringify({ action: action, payload: payload || {} })
        }).then(function (response) {
          return response.json().catch(function () {
            return { ok: false, message: 'Phản hồi không hợp lệ.' };
          });
        });
      };

      var updateCartBadge = function () {
        if (!cartBadge) {
          return;
        }
        var count = parseInt(cartState.total_qty || cartState.item_count || 0, 10);
        if (count > 0) {
          cartBadge.hidden = false;
          cartBadge.textContent = count > 99 ? '99+' : String(count);
        } else {
          cartBadge.hidden = true;
          cartBadge.textContent = '0';
        }
      };

      var setCartState = function (cart) {
        cartState = cart || { items: [], item_count: 0, total_qty: 0, subtotal: 0, shipping_fee: 30000, grand_total: 30000 };
        updateCartBadge();
        renderCartDrawer();
      };

      var loadCart = function () {
        return callCommerce('get_cart').then(function (result) {
          if (result && result.ok && result.cart) {
            setCartState(result.cart);
          }
          return result;
        }).catch(function () {
          return null;
        });
      };

      var renderCartDrawer = function () {
        if (!cartBody) {
          return;
        }
        var items = Array.isArray(cartState.items) ? cartState.items : [];
        if (!items.length) {
          cartBody.innerHTML = '<div class="ai-chat-widget__cart-empty">Giỏ hàng trống. Thêm sản phẩm từ gợi ý AI hoặc nhắn "cho tôi 2 serum..." nhé!</div>';
          if (cartFoot) {
            cartFoot.hidden = true;
          }
          return;
        }

        cartBody.innerHTML = items.map(function (item) {
          return '<div class="ai-chat-widget__cart-line" data-ai-cart-line="' + escapeHtml(item.id) + '">'
            + '<div class="ai-chat-widget__cart-line-info">'
            + '<div class="ai-chat-widget__cart-line-name">' + escapeHtml(item.name || 'Sản phẩm') + '</div>'
            + '<div class="ai-chat-widget__cart-line-meta">' + escapeHtml(currencyFormatter.format(item.price || 0) + ' đ') + '</div>'
            + '</div>'
            + '<div class="ai-chat-widget__cart-line-actions">'
            + '<input type="number" min="1" max="99" class="ai-chat-widget__qty-input ai-chat-widget__qty-input--sm" value="' + escapeHtml(String(item.qty || 1)) + '" data-ai-cart-qty="' + escapeHtml(item.id) + '">'
            + '<button type="button" class="ai-chat-widget__cart-remove" data-ai-cart-remove="' + escapeHtml(item.id) + '" title="Xóa"><i class="fa-solid fa-trash"></i></button>'
            + '</div>'
            + '</div>';
        }).join('');

        if (cartFoot && cartTotalEl) {
          cartFoot.hidden = false;
          cartTotalEl.innerHTML = 'Tạm tính: <strong>' + escapeHtml(currencyFormatter.format(cartState.subtotal || 0) + ' đ') + '</strong>'
            + ' · Ship: ' + escapeHtml(currencyFormatter.format(cartState.shipping_fee || 30000) + ' đ')
            + '<br>Tổng: <strong>' + escapeHtml(currencyFormatter.format(cartState.grand_total || 0) + ' đ') + '</strong>';
        }
      };

      var openCartDrawer = function () {
        if (!cartDrawer) {
          return;
        }
        openWidget();
        renderCartDrawer();
        cartDrawer.hidden = false;
        if (checkoutDrawer) {
          checkoutDrawer.hidden = true;
        }
        syncExpandedState();
        syncLayout();
        var items = Array.isArray(cartState.items) ? cartState.items : [];
        if (!items.length && cartBody) {
          cartBody.scrollTop = 0;
        }
        window.requestAnimationFrame(function () {
          if (cartDrawer && typeof cartDrawer.scrollIntoView === 'function') {
            cartDrawer.scrollIntoView({ block: 'end', behavior: 'smooth' });
          }
        });
      };

      var closeCartDrawer = function () {
        if (cartDrawer) {
          cartDrawer.hidden = true;
        }
      };

      var openCheckoutDrawer = function () {
        if (!checkoutDrawer || !checkoutBody) {
          return;
        }
        openWidget();
        checkoutBody.innerHTML = '<div class="ai-chat-widget__checkout-loading">Đang tải thông tin thanh toán...</div>';
        checkoutDrawer.hidden = false;
        if (cartDrawer) {
          cartDrawer.hidden = true;
        }

        callCommerce('checkout_preview').then(function (result) {
          if (!result || result.ok !== true) {
            checkoutBody.innerHTML = '<div class="ai-chat-widget__cart-empty">' + escapeHtml((result && result.message) || 'Không thể mở thanh toán.') + '</div>';
            return;
          }

          if (!result.is_logged_in) {
            checkoutBody.innerHTML = '<div class="ai-chat-widget__cart-empty">'
              + 'Vui lòng <a href="' + escapeHtml(result.login_url || (siteBaseUrl + '/index.php?r=dangnhap')) + '" class="ai-chat-widget__inline-link">đăng nhập</a> để đặt hàng trong chat.'
              + '</div>';
            return;
          }

          var preview = result.preview || {};
          var receiver = preview.default_receiver || {};
          var methods = Array.isArray(preview.payment_methods) ? preview.payment_methods : [{ id: 'cod', label: 'COD' }];
          var itemsHtml = (preview.items || []).map(function (item) {
            return '<div class="ai-chat-widget__checkout-line">'
              + escapeHtml(item.name || '') + ' × ' + escapeHtml(String(item.qty || 1))
              + ' — <strong>' + escapeHtml(currencyFormatter.format(item.line_total || 0) + ' đ') + '</strong>'
              + '</div>';
          }).join('');

          checkoutBody.innerHTML = '<div class="ai-chat-widget__checkout-summary">' + itemsHtml + '</div>'
            + '<div class="ai-chat-widget__checkout-total">Tổng thanh toán: <strong>' + escapeHtml(currencyFormatter.format(preview.grand_total || 0) + ' đ') + '</strong></div>'
            + '<form class="ai-chat-widget__checkout-form" data-ai-checkout-form>'
            + '<label class="ai-chat-widget__checkout-label">Họ tên người nhận<input class="ai-chat-widget__checkout-input" name="ten_nguoi_nhan" value="' + escapeHtml(receiver.ten_nguoi_nhan || '') + '" required></label>'
            + '<label class="ai-chat-widget__checkout-label">Số điện thoại<input class="ai-chat-widget__checkout-input" name="sdt_nguoi_nhan" value="' + escapeHtml(receiver.sdt_nguoi_nhan || '') + '" required></label>'
            + '<label class="ai-chat-widget__checkout-label">Địa chỉ chi tiết<input class="ai-chat-widget__checkout-input" name="dia_chi_chi_tiet" placeholder="Số nhà, tên đường" required></label>'
            + '<div class="ai-chat-widget__checkout-grid">'
            + '<label class="ai-chat-widget__checkout-label">Phường/Xã<input class="ai-chat-widget__checkout-input" name="phuong_xa"></label>'
            + '<label class="ai-chat-widget__checkout-label">Quận/Huyện<input class="ai-chat-widget__checkout-input" name="quan_huyen"></label>'
            + '</div>'
            + '<label class="ai-chat-widget__checkout-label">Tỉnh/Thành<input class="ai-chat-widget__checkout-input" name="tinh_thanh"></label>'
            + '<label class="ai-chat-widget__checkout-label">Ghi chú giao hàng<textarea class="ai-chat-widget__checkout-input" name="ghi_chu_giao_hang" rows="2"></textarea></label>'
            + '<fieldset class="ai-chat-widget__checkout-fieldset"><legend>Thanh toán</legend>'
            + methods.map(function (method, idx) {
              return '<label class="ai-chat-widget__checkout-radio"><input type="radio" name="payment_method" value="' + escapeHtml(method.id) + '"' + (idx === 0 ? ' checked' : '') + '> ' + escapeHtml(method.label) + '</label>';
            }).join('')
            + '</fieldset>'
            + '<label class="ai-chat-widget__checkout-check"><input type="checkbox" name="save_as_default" value="1"> Lưu làm địa chỉ mặc định</label>'
            + '<button type="submit" class="ai-chat-widget__drawer-btn ai-chat-widget__drawer-btn--primary">Xác nhận đặt hàng</button>'
            + '</form>';

          if (receiver.dia_chi_giao_hang) {
            var addrInput = checkoutBody.querySelector('[name="dia_chi_chi_tiet"]');
            if (addrInput) {
              addrInput.value = receiver.dia_chi_giao_hang;
            }
          }
        });
      };

      var closeCheckoutDrawer = function () {
        if (checkoutDrawer) {
          checkoutDrawer.hidden = true;
        }
      };

      var normalizeCartProductId = function (id) {
        var raw = String(id || '').trim();
        if (raw.indexOf('product_') === 0) {
          raw = raw.slice(8);
        }
        return raw;
      };

      var addItemsToCart = function (items) {
        var normalized = (items || []).map(function (item) {
          return {
            id: normalizeCartProductId(item.id),
            qty: Math.max(1, parseInt(item.qty || 1, 10))
          };
        }).filter(function (item) { return item.id !== '' && !/^doc_\d+$/i.test(item.id); });
        if (!normalized.length) {
          return Promise.resolve({ ok: false, message: 'Không thêm được — mã sản phẩm không hợp lệ. Thử bấm «Thêm giỏ» trên từng sản phẩm.' });
        }
        return callCommerce('add_items', { items: normalized }).then(function (result) {
          if (result && result.ok && result.cart) {
            setCartState(result.cart);
          }
          return result;
        });
      };

      var applyCartActions = function (actions) {
        if (!Array.isArray(actions) || !actions.length) {
          return Promise.resolve(null);
        }
        var items = actions.map(function (action) {
          return {
            id: normalizeCartProductId(action.product_id || action.id || ''),
            qty: Math.max(1, parseInt(action.qty || 1, 10))
          };
        }).filter(function (item) { return item.id !== '' && !/^doc_\d+$/i.test(item.id); });
        if (!items.length) {
          return Promise.resolve(null);
        }
        return addItemsToCart(items);
      };

      var handleCommercePayload = function (commerce) {
        if (!commerce || typeof commerce !== 'object') {
          return;
        }
        if (commerce.cart) {
          setCartState(commerce.cart);
        }
        if (commerce.action === 'show_cart') {
          openCartDrawer();
        }
      };

      var processAssistantExtras = function (payload, assistantIndex) {
        if (!payload || assistantIndex < 0 || !messages[assistantIndex]) {
          return Promise.resolve();
        }

        messages[assistantIndex].commerce = payload.commerce || null;

        if (payload.commerce && payload.commerce.action === 'cart_updated') {
          handleCommercePayload(payload.commerce);
          saveMessages();
          renderMessages();
          return Promise.resolve();
        }

        var tasks = [];
        if (Array.isArray(payload.cart_actions) && payload.cart_actions.length) {
          tasks.push(applyCartActions(payload.cart_actions));
        }
        if (payload.commerce) {
          handleCommercePayload(payload.commerce);
        }

        if (!tasks.length) {
          return Promise.resolve();
        }

        return Promise.all(tasks).then(function () {
          saveMessages();
          renderMessages();
        });
      };

      var fixProductUrls = function (items) {
        if (!Array.isArray(items)) {
          return [];
        }
        return items.map(function (product) {
          var next = Object.assign({}, product || {});
          var pid = normalizeCartProductId(next.id || '');
          if (/^doc_\d+$/i.test(pid)) {
            pid = '';
          }
          next.id = pid;
          var detailUrl = String(next.detail_url || '').trim();
          if (detailUrl !== '' && !/^https?:\/\//i.test(detailUrl) && siteBaseUrl !== '') {
            next.detail_url = siteBaseUrl.replace(/\/$/, '') + '/' + detailUrl.replace(/^\//, '');
          }
          var imageUrl = String(next.image_url || '').trim();
          if (imageUrl !== '' && !/^https?:\/\//i.test(imageUrl) && !/^data:/i.test(imageUrl) && siteBaseUrl !== '') {
            next.image_url = siteBaseUrl.replace(/\/$/, '') + '/' + imageUrl.replace(/^\//, '');
          }
          return next;
        });
      };

      var renderMetaBar = function (message) {
        if (!message || message.role === 'user' || message.typing) {
          return '';
        }

        var parts = [];
        if (message.mode) {
          var badgeClass = 'ai-chat-widget__mode-badge';
          if (message.mode === 'agent' || message.mode === 'pipeline_fallback') {
            badgeClass += ' ai-chat-widget__mode-badge--agent';
          } else if (message.mode === 'pipeline' && message.streamed) {
            badgeClass += ' ai-chat-widget__mode-badge--stream';
          }
          parts.push('<span class="' + badgeClass + '">' + escapeHtml(message.mode) + '</span>');
        }
        if (message.route_reason) {
          parts.push('<span class="ai-chat-widget__meta-text">' + escapeHtml(message.route_reason) + '</span>');
        }
        var meta = [];
        if (message.latency_ms) {
          meta.push(message.latency_ms + 'ms');
        }
        if (message.intent) {
          meta.push('intent: ' + message.intent);
        }
        if (meta.length) {
          parts.push('<span class="ai-chat-widget__meta-text">' + escapeHtml(meta.join(' · ')) + '</span>');
        }
        if (!parts.length) {
          return '';
        }
        return '<div class="ai-chat-widget__meta-bar">' + parts.join('') + '</div>';
      };

      var renderSuggestions = function (suggestions) {
        if (!Array.isArray(suggestions) || !suggestions.length) {
          return '';
        }
        return '<div class="ai-chat-widget__suggestions">' + suggestions.map(function (text) {
          return '<button type="button" class="ai-chat-widget__suggestion-chip" data-ai-suggestion="' + escapeHtml(text) + '">' + escapeHtml(text) + '</button>';
        }).join('') + '</div>';
      };

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
            
          var cartAction = (product.id)
            ? '<div class="ai-chat-widget__cart-inline">'
              + '<input type="number" min="1" max="99" value="1" class="ai-chat-widget__qty-input" data-ai-product-qty="' + escapeHtml(product.id) + '" aria-label="Số lượng">'
              + '<button type="button" class="ai-chat-widget__meta-card-btn ai-chat-widget__meta-card-btn--cart" data-ai-add-cart="' + escapeHtml(product.id) + '">'
              + '<i class="fa-solid fa-cart-plus"></i> Thêm giỏ'
              + '</button>'
              + '</div>'
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
            var conflictCards = renderConflictCards(message.conflicts || []);
            var productCards = renderProductCards(message.products || []);
            meta += renderMetaBlock('Conflict Detection', conflictCards);
            if (productCards.length) {
              var bulkIds = (message.products || []).map(function (p) { return String(p.id || ''); }).filter(Boolean).join(',');
              contentSuffix += '<div class="ai-chat-widget__product-toolbar">'
                + '<button type="button" class="ai-chat-widget__toolbar-btn" data-ai-add-all-cart="' + escapeHtml(bulkIds) + '"><i class="fa-solid fa-cart-plus"></i> Thêm tất cả vào giỏ</button>'
                + '<button type="button" class="ai-chat-widget__toolbar-btn" data-ai-open-cart><i class="fa-solid fa-cart-shopping"></i> Xem giỏ hàng</button>'
                + '</div>';
              contentSuffix += '<div class="ai-chat-widget__product-group">' + productCards.join('') + '</div>';
            }
            if (message.commerce && message.commerce.action === 'show_cart') {
              contentSuffix += '<div class="ai-chat-widget__inline-actions">'
                + '<button type="button" class="ai-chat-widget__toolbar-btn ai-chat-widget__toolbar-btn--primary" data-ai-open-cart><i class="fa-solid fa-cart-shopping"></i> Mở giỏ hàng</button>'
                + '<button type="button" class="ai-chat-widget__toolbar-btn" data-ai-checkout-open><i class="fa-solid fa-receipt"></i> Đặt hàng ngay</button>'
                + '</div>';
            }
          }

          var formattedContent = isUser
            ? escapeHtml(message.content)
            : (message.content
              ? formatMarkdown(message.content)
              : (message.streamStatus ? '' : '<span style="opacity:.6">...</span>'));
          var avatar = isUser
            ? ''
            : '<div class="ai-chat-widget__bubble-avatar" aria-hidden="true"><i class="fa-solid fa-robot"></i></div>';
          var streamStatus = (!isUser && message.streamStatus)
            ? '<div class="ai-chat-widget__stream-status">' + escapeHtml(message.streamStatus) + '</div>'
            : '';

          return '<div class="ai-chat-widget__bubble-row ' + (isUser ? 'ai-chat-widget__bubble-row--user' : 'ai-chat-widget__bubble-row--assistant') + '">' 
            + '<div class="ai-chat-widget__bubble-wrap">'
            + avatar
            + '<div class="ai-chat-widget__bubble ' + (isUser ? 'ai-chat-widget__bubble--user' : 'ai-chat-widget__bubble--assistant') + '">' 
            + '<div class="ai-chat-widget__bubble-author">' + (isUser ? 'Bạn' : 'SkinSyntax AI') + '</div>'
            + contentPrefix
            + streamStatus
            + '<div class="ai-chat-widget__bubble-text">' + formattedContent + '</div>'
            + contentSuffix
            + meta
            + renderMetaBar(message)
            + renderSuggestions(message.suggestions || [])
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
        if (widget.classList.contains('is-expanded') && !widget.classList.contains('is-open')) {
          widget.classList.remove('is-expanded');
        }

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

      var buildChatRequestBody = function (content) {
        var currentProductId = null;
        try {
          var urlParams = new URLSearchParams(window.location.search);
          if (urlParams.get('r') === 'chitiet') {
            currentProductId = urlParams.get('id');
          }
        } catch (e) {
        }

        return {
          message: content,
          session_id: aiSessionId,
          current_product_id: currentProductId,
          history: messages.filter(function (item) {
            return !item.typing;
          }).map(function (item) {
            return { role: item.role, content: item.content };
          })
        };
      };

      var applyAssistantPayload = function (payload, assistantIndex) {
        if (assistantIndex < 0 || !messages[assistantIndex]) {
          return;
        }

        messages[assistantIndex] = {
          role: 'assistant',
          content: String(payload.answer || '').trim(),
          conflicts: Array.isArray(payload.conflicts) ? payload.conflicts : [],
          products: fixProductUrls(Array.isArray(payload.products) ? payload.products : []),
          suggestions: Array.isArray(payload.suggestions) ? payload.suggestions : [],
          intent: String(payload.intent || ''),
          mode: String(payload.mode || payload._mode || ''),
          route_reason: String(payload.route_reason || payload._route_reason || ''),
          latency_ms: payload.latency_ms || 0,
          streamed: !!payload.streamed,
          commerce: payload.commerce || null,
          cart_actions: Array.isArray(payload.cart_actions) ? payload.cart_actions : []
        };
        saveMessages();
        renderMessages();
        return processAssistantExtras(payload, assistantIndex);
      };

      var sendSyncMessage = function (content, assistantIndex) {
        return fetch(syncUrl, {
          method: 'POST',
          headers: {
            'Content-Type': 'application/json',
            'Accept': 'application/json'
          },
          body: JSON.stringify(buildChatRequestBody(content))
        })
          .then(function (response) {
            return response.json().catch(function () {
              return { ok: false, message: 'Phản hồi AI không hợp lệ.' };
            });
          })
          .then(function (payload) {
            if (!payload || payload.ok !== true) {
              messages[assistantIndex] = {
                role: 'assistant',
                content: (payload && payload.message) ? payload.message : 'AI hiện chưa phản hồi được. Vui lòng thử lại sau.',
                conflicts: [],
                products: [],
                suggestions: []
              };
            } else {
              return applyAssistantPayload(payload, assistantIndex);
            }
            saveMessages();
            renderMessages();
          });
      };

      var CHAT_STREAM_TIMEOUT_MS = 90000;

      var parseSseBuffer = function (text, assistantIndex, answerRef) {
        var parts = text.split('\n\n');
        var remainder = parts.pop() || '';
        parts.forEach(function (chunk) {
          chunk.split('\n').forEach(function (line) {
            if (line.indexOf('data:') !== 0) {
              return;
            }
            var raw = line.slice(5).trim();
            if (!raw || raw === '[DONE]') {
              return;
            }
            var eventData;
            try {
              eventData = JSON.parse(raw);
            } catch (error) {
              return;
            }
            if (eventData.type === 'status') {
              messages[assistantIndex].streamStatus = String(eventData.message || 'Đang xử lý...');
              renderMessages();
            } else if (eventData.type === 'token') {
              answerRef.text += String(eventData.delta || '');
              messages[assistantIndex].content = answerRef.text;
              messages[assistantIndex].streamStatus = '';
              renderMessages();
            } else if (eventData.type === 'done') {
              messages[assistantIndex].conflicts = Array.isArray(eventData.conflicts) ? eventData.conflicts : [];
              messages[assistantIndex].products = fixProductUrls(Array.isArray(eventData.products) ? eventData.products : []);
              messages[assistantIndex].suggestions = Array.isArray(eventData.suggestions) ? eventData.suggestions : [];
              messages[assistantIndex].intent = String(eventData.intent || (eventData.analysis && eventData.analysis.intent) || '');
              messages[assistantIndex].mode = String(eventData.mode || 'pipeline');
              messages[assistantIndex].streamed = true;
              messages[assistantIndex].cart_actions = Array.isArray(eventData.cart_actions) ? eventData.cart_actions : [];
              messages[assistantIndex].commerce = eventData.commerce || null;
              answerRef.done = true;
            } else if (eventData.type === 'error') {
              throw new Error(String(eventData.message || 'Stream error'));
            }
          });
        });
        return remainder;
      };

      var finalizeStreamMessage = function (assistantIndex, answerRef, startedAt) {
        messages[assistantIndex].streamStatus = '';
        if (!answerRef.text.trim() && !(messages[assistantIndex].products || []).length) {
          throw new Error('Empty stream');
        }
        if (!answerRef.text.trim()) {
          messages[assistantIndex].content = 'Mình đã tìm thấy gợi ý sản phẩm bên dưới. Bạn xem và thêm giỏ nhé.';
        } else {
          messages[assistantIndex].content = answerRef.text.trim();
        }
        messages[assistantIndex].latency_ms = Date.now() - startedAt;
        messages[assistantIndex].mode = messages[assistantIndex].mode || 'pipeline';
        messages[assistantIndex].streamed = true;
        saveMessages();
        renderMessages();
        return processAssistantExtras({
          cart_actions: messages[assistantIndex].cart_actions,
          commerce: messages[assistantIndex].commerce
        }, assistantIndex);
      };

      var sendStreamMessage = function (content, assistantIndex) {
        var startedAt = Date.now();
        var abortCtrl = typeof AbortController !== 'undefined' ? new AbortController() : null;
        var timeoutId = window.setTimeout(function () {
          if (abortCtrl) {
            abortCtrl.abort();
          }
        }, CHAT_STREAM_TIMEOUT_MS);

        return fetch(streamUrl, {
          method: 'POST',
          headers: {
            'Content-Type': 'application/json',
            'Accept': 'text/event-stream'
          },
          body: JSON.stringify(buildChatRequestBody(content)),
          signal: abortCtrl ? abortCtrl.signal : undefined
        }).then(function (response) {
          if (!response.ok || !response.body) {
            throw new Error('Stream unavailable');
          }

          var reader = response.body.getReader();
          var decoder = new TextDecoder('utf-8');
          var buffer = '';
          var answerRef = { text: '', done: false };

          var pump = function () {
            return reader.read().then(function (result) {
              if (result.done) {
                buffer = parseSseBuffer(buffer, assistantIndex, answerRef);
                if (buffer.trim()) {
                  parseSseBuffer(buffer + '\n\n', assistantIndex, answerRef);
                }
                return finalizeStreamMessage(assistantIndex, answerRef, startedAt);
              }

              buffer += decoder.decode(result.value, { stream: true });
              buffer = parseSseBuffer(buffer, assistantIndex, answerRef);
              return pump();
            });
          };

          return pump();
        }).finally(function () {
          window.clearTimeout(timeoutId);
          if (messages[assistantIndex]) {
            messages[assistantIndex].streamStatus = '';
          }
        });
      };

      var sendMessage = function (text) {
        var content = String(text || '').trim();
        if (content === '') {
          return;
        }

        openWidget();
        addMessage({ role: 'user', content: content });
        setLoading(true);

        var assistantIndex = messages.length;
        messages.push({
          role: 'assistant',
          content: '',
          streamStatus: 'Đang kết nối AI...',
          conflicts: [],
          products: [],
          suggestions: []
        });
        renderMessages();

        var requestPromise = sendStreamMessage(content, assistantIndex).catch(function () {
          return sendSyncMessage(content, assistantIndex);
        });

        requestPromise
          .catch(function () {
            messages[assistantIndex] = {
              role: 'assistant',
              content: 'Không kết nối được tới AI service. Bạn có thể thử lại hoặc hỏi ngắn gọn hơn.',
              conflicts: [],
              products: [],
              suggestions: []
            };
            saveMessages();
            renderMessages();
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
          if (panel && panel.hidden) {
            openWidget();
          }
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

      var openCartQuickBtn = widget.querySelector('[data-ai-open-cart-quick]');
      if (openCartQuickBtn) {
        openCartQuickBtn.addEventListener('click', function () {
          loadCart().then(function () { openCartDrawer(); });
        });
      }

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

      var profileFromChatBtn = widget.querySelector('[data-ai-chat-profile-from-chat]');
      if (profileFromChatBtn) {
        profileFromChatBtn.addEventListener('click', function () {
          var lastUser = '';
          for (var i = messages.length - 1; i >= 0; i--) {
            if (messages[i] && messages[i].role === 'user' && String(messages[i].content || '').trim() !== '') {
              lastUser = String(messages[i].content).trim();
              break;
            }
          }
          if (lastUser !== '') {
            sendMessage('Dựa trên tình trạng da tôi vừa mô tả, gợi ý giúp tôi vài sản phẩm phù hợp trong ngân sách. Chi tiết: ' + lastUser);
          } else {
            sendMessage('Tôi cần gợi ý sản phẩm skincare. Tình trạng da: (mô tả của bạn). Ngân sách: (vd dưới 500k–1 triệu). Bạn hỏi lại nếu cần thêm chi tiết nhé.');
          }
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
        var addCartBtn = event.target.closest('[data-ai-add-cart]');
        if (addCartBtn) {
          event.preventDefault();
          var productId = addCartBtn.getAttribute('data-ai-add-cart') || '';
          var card = addCartBtn.closest('.ai-chat-widget__meta-card');
          var qtyInput = card ? card.querySelector('[data-ai-product-qty="' + productId + '"]') : null;
          var qty = qtyInput ? Math.max(1, parseInt(qtyInput.value || '1', 10)) : 1;
          addItemsToCart([{ id: productId, qty: qty }]).then(function (result) {
            if (result && result.ok) {
              addCartBtn.innerHTML = '<i class="fa-solid fa-check"></i> Đã thêm';
              window.setTimeout(function () {
                addCartBtn.innerHTML = '<i class="fa-solid fa-cart-plus"></i> Thêm vào giỏ hàng';
              }, 1500);
              openCartDrawer();
            } else if (result && result.message) {
              window.alert(result.message);
            }
          });
          return;
        }

        var addAllBtn = event.target.closest('[data-ai-add-all-cart]');
        if (addAllBtn) {
          event.preventDefault();
          var ids = (addAllBtn.getAttribute('data-ai-add-all-cart') || '').split(',').filter(Boolean);
          var items = ids.map(function (id) { return { id: id, qty: 1 }; });
          addItemsToCart(items).then(function (result) {
            if (result && result.ok) {
              openCartDrawer();
            }
          });
          return;
        }

        if (event.target.closest('[data-ai-open-cart]')) {
          event.preventDefault();
          loadCart().then(function (result) {
            openCartDrawer();
            var count = parseInt((cartState && cartState.total_qty) || 0, 10);
            if (count <= 0 && result && result.ok) {
              var hint = document.createElement('div');
              hint.className = 'ai-chat-widget__cart-hint';
              hint.textContent = 'Giỏ đang trống — bấm «Thêm giỏ» hoặc «Thêm tất cả vào giỏ» trên sản phẩm AI trước nhé.';
              if (cartBody && !cartBody.querySelector('.ai-chat-widget__cart-hint')) {
                cartBody.insertBefore(hint, cartBody.firstChild);
              }
            }
          });
          return;
        }

        if (event.target.closest('[data-ai-checkout-open]')) {
          event.preventDefault();
          openCheckoutDrawer();
          return;
        }

        if (event.target.closest('[data-ai-cart-close]')) {
          closeCartDrawer();
          return;
        }

        if (event.target.closest('[data-ai-checkout-close]')) {
          closeCheckoutDrawer();
          return;
        }

        var removeBtn = event.target.closest('[data-ai-cart-remove]');
        if (removeBtn) {
          var removeId = removeBtn.getAttribute('data-ai-cart-remove') || '';
          callCommerce('remove_item', { product_id: removeId }).then(function (result) {
            if (result && result.ok && result.cart) {
              setCartState(result.cart);
            }
          });
          return;
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

        var suggestion = event.target.closest('[data-ai-suggestion]');
        if (suggestion) {
          var suggestionText = suggestion.getAttribute('data-ai-suggestion') || '';
          if (suggestionText !== '') {
            sendMessage(suggestionText);
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

      widget.addEventListener('change', function (event) {
        var qtyInput = event.target.closest('[data-ai-cart-qty]');
        if (!qtyInput) {
          return;
        }
        var productId = qtyInput.getAttribute('data-ai-cart-qty') || '';
        var qty = Math.max(1, parseInt(qtyInput.value || '1', 10));
        callCommerce('update_qty', { product_id: productId, qty: qty }).then(function (result) {
          if (result && result.ok && result.cart) {
            setCartState(result.cart);
          }
        });
      });

      widget.addEventListener('submit', function (event) {
        var checkoutForm = event.target.closest('[data-ai-checkout-form]');
        if (!checkoutForm) {
          return;
        }
        event.preventDefault();
        var formData = new FormData(checkoutForm);
        var receiver = {
          ten_nguoi_nhan: String(formData.get('ten_nguoi_nhan') || '').trim(),
          sdt_nguoi_nhan: String(formData.get('sdt_nguoi_nhan') || '').trim(),
          dia_chi_chi_tiet: String(formData.get('dia_chi_chi_tiet') || '').trim(),
          phuong_xa: String(formData.get('phuong_xa') || '').trim(),
          quan_huyen: String(formData.get('quan_huyen') || '').trim(),
          tinh_thanh: String(formData.get('tinh_thanh') || '').trim(),
          ghi_chu_giao_hang: String(formData.get('ghi_chu_giao_hang') || '').trim(),
          save_as_default: formData.get('save_as_default') ? true : false
        };
        var paymentMethod = String(formData.get('payment_method') || 'cod');
        var submitBtn = checkoutForm.querySelector('[type="submit"]');
        if (submitBtn) {
          submitBtn.disabled = true;
          submitBtn.textContent = 'Đang đặt hàng...';
        }

        callCommerce('place_order', {
          receiver: receiver,
          payment_method: paymentMethod
        }).then(function (result) {
          if (result && result.ok) {
            closeCheckoutDrawer();
            closeCartDrawer();
            if (result.cart) {
              setCartState(result.cart);
            }
            addMessage({
              role: 'assistant',
              content: '✅ ' + (result.message || 'Đặt hàng thành công!')
                + (result.order_id ? '\n\nMã đơn: **#' + result.order_id + '**' : '')
                + (result.success_url ? '\n\n[Xem xác nhận đơn hàng](' + result.success_url + ')' : '')
            });
            if (result.success_url) {
              window.setTimeout(function () {
                window.location.href = result.success_url;
              }, 1800);
            }
            return;
          }

          if (submitBtn) {
            submitBtn.disabled = false;
            submitBtn.textContent = 'Xác nhận đặt hàng';
          }
          addMessage({
            role: 'assistant',
            content: '❌ ' + ((result && result.message) || 'Không thể đặt hàng lúc này.')
              + ((result && result.login_url) ? '\n\n[Vui lòng đăng nhập](' + result.login_url + ') để tiếp tục.' : '')
          });
        });
      });

      window.addEventListener('skinsyntax:support-chat-layout', function (event) {
        syncLayout(event.detail || null);
      });

      window.addEventListener('resize', function () {
        syncLayout();
      });

      // ── Auto-greeting (one-time per session) ──────────────────────────────
      var greetingKey = stream ? (stream.getAttribute('data-ai-greeting-key') || '') : '';

      var buildGreetingMessage = function () {
        var profileRaw = stream ? stream.getAttribute('data-ai-skin-profile') : '';
        var profile = null;
        try { profile = profileRaw ? JSON.parse(profileRaw) : null; } catch (e) {}

        if (!profile || !profile.loai_da) {
          return 'SkinSyntax AI chào bạn! 👋\n\n'
            + 'Mình là **Ngọc Vi** — tư vấn viên AI của SkinSyntaxVN. '
            + 'Bạn **không cần** hồ sơ da sẵn: hãy mô tả tình trạng da và ngân sách ngay trong chat.\n\n'
            + 'Ví dụ: *"da ngứa, nổi mụn, sản phẩm dưới 900k"*. '
            + 'Mình sẽ gợi ý sản phẩm và có thể **tự bổ sung hồ sơ** cho bạn sau khi tư vấn.\n\n'
            + 'Muốn lưu sẵn hồ sơ? [Cập nhật tại trang tài khoản](' + (siteBaseUrl || '') + '/index.php?r=hoso) hoặc [khảo sát da](' + (siteBaseUrl || '') + '/index.php?r=khaosat).';
        }

        var loaiDa = profile.loai_da || '';
        var vande  = profile.van_de_da || '';
        var tranh  = profile.thanh_phan_tranh || '';
        var nganSach = parseInt(profile.ngan_sach || 0, 10);

        var lines = [];
        lines.push('SkinSyntax AI chào bạn! 👋');
        lines.push('');
        lines.push('Mình đã ghi nhận tình trạng da của bạn là **' + loaiDa + '**' + (vande ? ' với vấn đề **' + vande + '**' : '') + '.');

        if (tranh) {
          lines.push('');
          lines.push('⚠️ **Ưu tiên tránh các thành phần:** ' + tranh + '.');
          lines.push('Mình sẽ luôn lọc sản phẩm an toàn với danh sách này cho bạn!');
        }

        if (nganSach > 0) {
          var fmt = new Intl.NumberFormat('vi-VN').format(nganSach);
          lines.push('');
          lines.push('💰 Ngân sách tham khảo của bạn: **' + fmt + ' đ**.');
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
      // ── End auto-greeting ──────────────────────────────────────────────────

      try {
        var stored = window.sessionStorage.getItem(storageKey);
        if (stored) {
          var parsed = JSON.parse(stored);
          if (Array.isArray(parsed)) {
            messages = parsed;
          }
        }
        if (window.sessionStorage.getItem(expandedStorageKey) === '1') {
          openWidget();
          widget.classList.add('is-expanded');
        }
      } catch (error) {
      }

      syncLayout();
      syncExpandedState();
      loadCart();
      renderMessages();

      // Inject greeting after first render if no prior history
      if (messages.length === 0) {
        injectGreeting();
      }
    });
