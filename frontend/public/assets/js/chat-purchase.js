(function () {
  'use strict';
  var escape = function (value) {
    return String(value == null ? '' : value).replace(/[&<>"']/g, function (char) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[char];
    });
  };
  var money = function (value) { return new Intl.NumberFormat('vi-VN').format(value) + ' đ'; };
  var safeUrl = function (value) {
    try { var url = new URL(value, window.location.href); return /^https?:$/.test(url.protocol) ? url.href : ''; }
    catch (error) { return ''; }
  };
  var product = function (item) {
    var image = safeUrl(item.image_url || '');
    return '<div class="ss-chat-buy__product">'
      + (image && item.image_url ? '<img src="' + escape(image) + '" alt="' + escape(item.name) + '" loading="lazy">' : '<span class="ss-chat-buy__no-image">Chưa có ảnh</span>')
      + '<div><a href="' + escape(safeUrl(item.detail_url)) + '">' + escape(item.name) + '</a>'
      + '<small>' + escape(item.brand) + '</small><strong>' + escape(money(item.price)) + '</strong></div></div>';
  };
  var render = function (state) {
    if (!state || !state.offer || !['choose', 'selected'].includes(state.stage)) return '';
    var html = '<section class="ss-chat-buy" data-buy-offer="' + escape(state.offer) + '" aria-label="Chọn sản phẩm để thanh toán">';
    if (state.stage === 'choose') {
      html += '<p class="ss-chat-buy__step">Chọn đúng sản phẩm bạn muốn mua</p>';
      (state.products || []).forEach(function (item) {
        html += '<div class="ss-chat-buy__option">' + product(item)
          + '<button type="button" data-buy-select="' + escape(item.id) + '" data-buy-quantity="' + escape(state.quantity) + '">Chọn sản phẩm này</button></div>';
      });
    } else {
      html += '<p class="ss-chat-buy__step">Bạn đã chọn</p>' + product(state.product)
        + '<form data-buy-form data-buy-id="' + escape(state.product.id) + '" data-buy-price="' + escape(state.product.price) + '">'
        + '<label>Số lượng <input aria-label="Số lượng mua" name="quantity" type="number" min="1" max="20" step="1" required value="' + escape(state.quantity) + '"></label>'
        + '<div class="ss-chat-buy__total"><span>Tiền sản phẩm</span><strong data-buy-subtotal>' + escape(money(state.subtotal)) + '</strong></div>'
        + '<small>Phí giao hàng và tổng tiền được hiển thị ở bước thanh toán.</small>'
        + '<div class="ss-chat-buy__actions"><button type="submit">Thanh toán <span aria-hidden="true">→</span></button>'
        + '<button type="button" class="ss-chat-buy__secondary" data-buy-refresh>Kiểm tra lại giá</button></div></form>'
        + '<button type="button" class="ss-chat-buy__change" data-buy-change>Chọn chai khác</button>';
    }
    return html + '<p class="ss-chat-buy__error" role="status" aria-live="polite" data-buy-error></p></section>';
  };

  var mount = function (widget, options) {
    var pending = new Set();
    // Catalog images can disappear from the upstream CDN. Keep the card usable
    // without substituting a photograph of a different product.
    widget.addEventListener('error', function (event) {
      var image = event.target;
      if (!(image instanceof HTMLImageElement) || !image.matches('.ss-chat-buy__product img')) return;
      var fallback = document.createElement('span');
      fallback.className = 'ss-chat-buy__no-image';
      fallback.textContent = 'Ảnh chưa tải được';
      image.replaceWith(fallback);
    }, true);
    var request = function (section, data) {
      var offer = section.dataset.buyOffer;
      if (pending.has(offer)) return;
      pending.add(offer);
      var buttons = section.querySelectorAll('button');
      buttons.forEach(function (button) { button.disabled = true; });
      section.querySelector('[data-buy-error]').textContent = 'Đang kiểm tra giá và số lượng…';
      fetch(options.endpoint, {
        method: 'POST', headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
        body: JSON.stringify(Object.assign({ offer: offer, csrf: options.csrf }, data))
      }).then(function (response) { return response.json(); }).then(function (result) {
        if (!result.ok) throw new Error(result.message || 'Chưa thực hiện được. Vui lòng thử lại.');
        if (result.redirect_url) {
          var target = new URL(result.redirect_url, window.location.href);
          if (target.origin !== window.location.origin || !['dangnhap', 'thanhtoan'].includes(target.searchParams.get('r'))) throw new Error('Đường dẫn thanh toán không hợp lệ.');
          window.location.assign(target.href);
        } else if (result.commerce) {
          options.onQuote(offer, result.commerce);
        }
      }).catch(function (error) {
        section.querySelector('[data-buy-error]').textContent = error.message || 'Mất kết nối. Chưa tạo đơn hàng, bạn thử lại nhé.';
      }).finally(function () {
        pending.delete(offer);
        buttons.forEach(function (button) { button.disabled = false; });
      });
    };
    widget.addEventListener('click', function (event) {
      var button = event.target.closest('[data-buy-select], [data-buy-refresh], [data-buy-change]');
      if (!button) return;
      var section = button.closest('[data-buy-offer]');
      if (button.hasAttribute('data-buy-change')) { options.onChange(section.dataset.buyOffer); return; }
      var form = section.querySelector('[data-buy-form]');
      var id = button.dataset.buySelect || (form && form.dataset.buyId);
      var quantity = form ? Number(form.elements.quantity.value) : Number(button.dataset.buyQuantity);
      if (form && !form.reportValidity()) return;
      request(section, { action: 'select', product_id: id, quantity: quantity });
    });
    widget.addEventListener('submit', function (event) {
      var form = event.target.closest('[data-buy-form]');
      if (!form) return;
      event.preventDefault();
      event.stopPropagation();
      if (!form.reportValidity()) return;
      request(form.closest('[data-buy-offer]'), {
        action: 'checkout', product_id: form.dataset.buyId, quantity: Number(form.elements.quantity.value), expected_price: Number(form.dataset.buyPrice)
      });
    });
    widget.addEventListener('input', function (event) {
      var form = event.target.closest('[data-buy-form]');
      if (!form) return;
      var quantity = Number(form.elements.quantity.value);
      form.querySelector('[data-buy-subtotal]').textContent = Number.isInteger(quantity) && quantity > 0 && quantity <= 20 ? money(quantity * Number(form.dataset.buyPrice)) : 'Kiểm tra số lượng';
      options.onQuantity(form.closest('[data-buy-offer]').dataset.buyOffer, quantity);
    });
  };
  window.SkinSyntaxPurchase = { render: render, mount: mount };
})();
