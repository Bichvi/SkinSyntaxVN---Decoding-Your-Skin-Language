(function () {
  'use strict';

  var config = window.SkinSyntaxFavoriteConfig || {};
  var toastTimer = null;

  function showToast(message, isError) {
    var toast = document.querySelector('[data-favorite-toast]');
    if (!toast) {
      toast = document.createElement('div');
      toast.className = 'favorite-toast';
      toast.setAttribute('data-favorite-toast', '');
      document.body.appendChild(toast);
    }
    toast.textContent = message || '';
    toast.style.background = isError ? '#B42318' : '#183B2B';
    toast.classList.add('is-visible');
    window.clearTimeout(toastTimer);
    toastTimer = window.setTimeout(function () {
      toast.classList.remove('is-visible');
    }, 2600);
  }

  function openLoginModal() {
    var trigger = document.querySelector('[data-bs-toggle="modal"][data-bs-target="#authModal"][data-auth-tab="login"]');
    if (trigger) {
      trigger.click();
      showToast('Đăng nhập để lưu sản phẩm yêu thích nhé.');
      return;
    }
    var redirect = window.location.href;
    var target = String(config.loginUrl || 'index.php?r=dangnhap');
    target += (target.indexOf('?') >= 0 ? '&' : '?') + 'redirect=' + encodeURIComponent(redirect);
    window.location.href = target;
  }

  function updateButtons(productId, isFavorite) {
    document.querySelectorAll('[data-favorite-product-id]').forEach(function (button) {
      if (String(button.getAttribute('data-favorite-product-id') || '') !== String(productId)) return;
      button.classList.toggle('is-favorite', !!isFavorite);
      button.setAttribute('aria-pressed', isFavorite ? 'true' : 'false');
      button.setAttribute('title', isFavorite ? 'Bỏ khỏi yêu thích' : 'Lưu sản phẩm yêu thích');
      var icon = button.querySelector('i');
      if (icon) {
        icon.classList.toggle('fas', !!isFavorite);
        icon.classList.toggle('far', !isFavorite);
      }
      var hidden = button.querySelector('.visually-hidden');
      if (hidden) hidden.textContent = isFavorite ? 'Bỏ khỏi yêu thích' : 'Lưu sản phẩm yêu thích';
    });
  }

  document.addEventListener('click', function (event) {
    var button = event.target.closest('[data-favorite-product-id]');
    if (!button) return;
    event.preventDefault();
    event.stopPropagation();

    var productId = String(button.getAttribute('data-favorite-product-id') || '').trim();
    if (!productId) return;
    if (config.loggedIn !== true) {
      openLoginModal();
      return;
    }
    if (button.dataset.favoriteBusy === '1') return;
    button.dataset.favoriteBusy = '1';
    button.disabled = true;

    fetch(String(config.endpoint || 'index.php?r=favorite_toggle'), {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'Accept': 'application/json',
        'X-Requested-With': 'XMLHttpRequest'
      },
      body: JSON.stringify({ product_id: productId })
    })
      .then(function (response) {
        return response.json().then(function (payload) {
          return { response: response, payload: payload || {} };
        });
      })
      .then(function (result) {
        if (result.response.status === 401 || result.payload.code === 'login_required') {
          config.loggedIn = false;
          openLoginModal();
          return;
        }
        if (!result.response.ok || !result.payload.ok) {
          throw new Error(result.payload.message || 'Chưa thể lưu sản phẩm.');
        }
        updateButtons(productId, !!result.payload.favorite);
        showToast(result.payload.message || 'Đã cập nhật yêu thích.');
        document.dispatchEvent(new CustomEvent('skinsyntax:favorite-changed', {
          detail: { productId: productId, favorite: !!result.payload.favorite }
        }));
      })
      .catch(function (error) {
        showToast(error.message || 'Chưa thể cập nhật yêu thích.', true);
      })
      .finally(function () {
        button.disabled = false;
        delete button.dataset.favoriteBusy;
      });
  });
}());
