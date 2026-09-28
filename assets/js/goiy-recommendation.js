(function () {
  'use strict';

  var root = document.querySelector('[data-recommendation-root]');
  var dataElement = document.getElementById('recommendation-page-data');
  if (!root || !dataElement) return;

  var data = {};
  try {
    data = JSON.parse(dataElement.textContent || '{}');
  } catch (error) {
    console.error('Recommendation response data is invalid.', error);
    return;
  }

  var state = {
    session: 'am',
    view: 'routine',
    concern: '',
    selected: {},
    comboDirty: false
  };

  var money = new Intl.NumberFormat('vi-VN');
  var normalize = function (value) {
    return String(value || '').toLocaleLowerCase('vi-VN').normalize('NFD').replace(/[\u0300-\u036f]/g, '');
  };
  var formatMoney = function (value) {
    var amount = Number(value || 0);
    return amount > 0 ? money.format(Math.round(amount)) + 'đ' : '—';
  };
  var stepsFor = function (session) {
    return Array.isArray(data[session]) ? data[session] : [];
  };
  var productForStep = function (session, order) {
    var step = stepsFor(session).find(function (item) {
      return Number(item.step_order) === Number(order);
    });
    return step && step.recommended_product ? step.recommended_product : null;
  };
  var allCards = function () {
    return Array.prototype.slice.call(root.querySelectorAll('[data-step-card]'));
  };
  var currentCards = function () {
    return allCards().filter(function (card) {
      return card.closest('[data-session-panel]') && card.closest('[data-session-panel]').getAttribute('data-session-panel') === state.session;
    });
  };
  var showFeedback = function (message, isError) {
    var feedback = root.querySelector('[data-combo-feedback]');
    if (!feedback) return;
    feedback.textContent = message || '';
    feedback.classList.toggle('is-error', Boolean(isError));
  };

  var safeImageFallback = function (image) {
    image.addEventListener('error', function () {
      var fallback = image.getAttribute('data-fallback-image');
      if (!fallback || image.getAttribute('data-fallback-used') === 'true') return;
      image.setAttribute('data-fallback-used', 'true');
      image.src = fallback;
    });
  };
  root.querySelectorAll('img[data-fallback-image]').forEach(safeImageFallback);

  var setScore = function (card, score) {
    var fill = card.querySelector('[data-product-score]');
    var label = card.querySelector('[data-product-score-label]');
    var hasNumericScore = score !== null && score !== undefined && score !== '' && Number.isFinite(Number(score));
    var numeric = hasNumericScore ? Math.max(0, Math.min(100, Number(score))) : 0;
    var display = numeric ? (numeric % 1 ? numeric.toFixed(1) : numeric) + '%' : 'Chưa tính';
    if (fill) fill.style.width = numeric + '%';
    if (label && hasNumericScore) label.textContent = display;
    return display;
  };
  var setIngredients = function (card, ingredients) {
    var list = card.querySelector('[data-product-ingredients]');
    var empty = card.querySelector('[data-product-ingredients-empty]');
    var values = Array.isArray(ingredients) ? ingredients.filter(Boolean).slice(0, 8) : [];
    if (list) {
      list.replaceChildren();
      values.forEach(function (ingredient) {
        var chip = document.createElement('span');
        chip.className = 'ingredient-chip';
        chip.textContent = ingredient;
        list.appendChild(chip);
      });
      list.hidden = values.length === 0;
    }
    if (empty) empty.hidden = values.length > 0;
  };
  var applyProductToCard = function (card, product) {
    if (!product) return;
    var name = String(product.name || product.ten_san_pham || 'Sản phẩm SkinSyntax');
    var price = Number(product.price || product.gia_ban || 0);
    var original = Number(product.original_price || product.gia_thi_truong || 0);
    var image = String(product.image || product.image_url || product.link_hinh_anh || '');
    var productId = String(product.id || product.product_id || product.ma_san_pham || '');
    var nameElement = card.querySelector('[data-product-name]');
    var brandElement = card.querySelector('[data-product-brand]');
    var imageElement = card.querySelector('[data-product-image]');
    var priceElement = card.querySelector('[data-product-price]');
    var originalElement = card.querySelector('[data-product-original-price]');
    if (nameElement) nameElement.textContent = name;
    if (brandElement) {
      brandElement.textContent = String(product.brand || product.thuong_hieu || '');
      brandElement.hidden = !brandElement.textContent;
    }
    if (imageElement && image) {
      imageElement.removeAttribute('data-fallback-used');
      imageElement.src = image;
      imageElement.alt = name;
    }
    if (priceElement) priceElement.textContent = formatMoney(price);
    if (originalElement) {
      originalElement.textContent = original > price ? formatMoney(original) : '';
      originalElement.hidden = original <= price;
    }
    var rawScore = product.match_score ?? product.match_percent ?? product.score;
    var scoreDisplay = setScore(card, rawScore);
    var scoreLabelElement = card.querySelector('[data-product-score-label]');
    var fitLabel = String(product.match_label || product.fit_status || scoreDisplay || 'Chưa tính');
    if (scoreLabelElement) scoreLabelElement.textContent = rawScore !== null && rawScore !== undefined && rawScore !== '' ? scoreDisplay : fitLabel;
    var scoreContainer = card.querySelector('.step-card__score');
    if (scoreContainer) scoreContainer.setAttribute('aria-label', 'Độ phù hợp ' + (rawScore !== null && rawScore !== undefined && rawScore !== '' ? scoreDisplay : fitLabel));
    setIngredients(card, product.key_ingredients || product.matched_ingredients || []);
    var input = card.querySelector('[data-product-id-input]');
    if (input) input.value = productId;
    var legacyInput = card.querySelector('[data-product-ma-id-input]');
    if (legacyInput) legacyInput.value = productId;
    var detailUrl = String(product.detail_url || '');
    if (!detailUrl && productId) detailUrl = (root.dataset.baseUrl || '') + '/index.php?r=chitiet&id=' + encodeURIComponent(productId);
    card.querySelectorAll('[data-product-link]').forEach(function (link) {
      link.href = detailUrl || '#';
      link.setAttribute('aria-label', 'Xem ' + name);
    });
    card.dataset.selectedProduct = productId;
    card.dataset.search = normalize([name, product.brand, product.category, (product.key_ingredients || []).join(' ')].join(' '));
    state.selected[card.dataset.stepKey] = product;
    state.comboDirty = true;
  };

  allCards().forEach(function (card) {
    var stepKey = card.dataset.stepKey || '';
    var parts = stepKey.split('-');
    var initial = productForStep(parts[0] || 'am', parts[1] || 0);
    if (initial) state.selected[stepKey] = initial;
    card.querySelectorAll('[data-score]').forEach(function (fill) {
      setScore(card, fill.getAttribute('data-score'));
    });
  });

  var activateStep = function (stepKey, shouldScroll) {
    allCards().forEach(function (card) {
      card.classList.toggle('is-active', card.dataset.stepKey === stepKey);
    });
    root.querySelectorAll('[data-layer-step]').forEach(function (layer) {
      layer.classList.toggle('is-active', layer.dataset.layerStep === stepKey);
    });
    if (shouldScroll) {
      var card = allCards().find(function (item) { return item.dataset.stepKey === stepKey; });
      if (card) card.scrollIntoView({ behavior: 'smooth', block: 'center' });
    }
  };

  var syncLayers = function () {
    var cards = currentCards();
    var layers = Array.prototype.slice.call(root.querySelectorAll('[data-layer-index]'));
    layers.forEach(function (layer, index) {
      var card = cards[index];
      layer.hidden = !card;
      if (!card) return;
      layer.dataset.layerStep = card.dataset.stepKey;
      var label = layer.querySelector('text');
      var stepName = card.querySelector('.step-card__step-name');
      if (label && stepName) label.textContent = stepName.textContent.split('/')[0].trim();
      layer.setAttribute('aria-label', stepName ? stepName.textContent : 'Bước routine');
    });
    if (cards[0]) activateStep(cards[0].dataset.stepKey, false);
  };

  var applyConcernFilter = function () {
    var visibleCount = 0;
    currentCards().forEach(function (card) {
      var matches = !state.concern || normalize(card.dataset.search).indexOf(normalize(state.concern)) !== -1;
      card.classList.toggle('is-filtered-out', !matches);
      if (matches) visibleCount += 1;
    });
    var empty = root.querySelector('[data-filter-empty]');
    if (empty) empty.hidden = !state.concern || visibleCount > 0;
  };

  var setSession = function (session) {
    state.session = session === 'pm' ? 'pm' : 'am';
    root.classList.toggle('is-evening', state.session === 'pm');
    root.querySelectorAll('[data-session-tab]').forEach(function (button) {
      button.setAttribute('aria-selected', button.dataset.sessionTab === state.session ? 'true' : 'false');
    });
    root.querySelectorAll('[data-session-panel]').forEach(function (panel) {
      panel.hidden = panel.dataset.sessionPanel !== state.session;
    });
    root.querySelectorAll('[data-session-label]').forEach(function (labelElement) {
      var panel = labelElement.closest('[data-session-panel]');
      var panelSession = panel ? panel.dataset.sessionPanel : 'am';
      labelElement.textContent = panelSession === 'pm' ? 'Buổi tối' : 'Buổi sáng';
    });
    var heading = root.querySelector('[data-session-heading]');
    var layerHeading = root.querySelector('[data-layer-heading]');
    var label = state.session === 'pm' ? 'Routine buổi tối' : 'Routine buổi sáng';
    if (heading) heading.textContent = label;
    if (layerHeading) layerHeading.textContent = state.session === 'pm' ? 'Thứ tự buổi tối' : 'Thứ tự buổi sáng';
    syncLayers();
    applyConcernFilter();
  };

  root.querySelectorAll('[data-session-tab]').forEach(function (button) {
    button.addEventListener('click', function () { setSession(button.dataset.sessionTab); });
  });
  root.querySelectorAll('[data-view-tab]').forEach(function (button) {
    button.addEventListener('click', function () {
      state.view = button.dataset.viewTab;
      root.querySelectorAll('[data-view-tab]').forEach(function (tab) {
        tab.setAttribute('aria-selected', tab === button ? 'true' : 'false');
      });
      root.querySelectorAll('[data-recommendation-view]').forEach(function (view) {
        view.hidden = view.dataset.recommendationView !== state.view;
      });
    });
  });

  root.querySelectorAll('[data-concern-filter]').forEach(function (button) {
    button.setAttribute('aria-pressed', 'false');
    button.addEventListener('click', function () {
      state.concern = state.concern === button.dataset.concernFilter ? '' : button.dataset.concernFilter;
      root.querySelectorAll('[data-concern-filter]').forEach(function (tag) {
        var active = state.concern !== '' && tag.dataset.concernFilter === state.concern;
        tag.classList.toggle('profile-chip--active', active);
        tag.setAttribute('aria-pressed', active ? 'true' : 'false');
      });
      applyConcernFilter();
    });
  });

  root.querySelectorAll('[data-alternatives-toggle]').forEach(function (button) {
    button.addEventListener('click', function () {
      var card = button.closest('[data-step-card]');
      var panel = card && card.querySelector('[data-alternatives-panel]');
      if (!panel) return;
      var open = panel.hidden;
      panel.hidden = !open;
      button.setAttribute('aria-expanded', open ? 'true' : 'false');
    });
  });
  root.querySelectorAll('[data-alternative]').forEach(function (button) {
    button.addEventListener('click', function () {
      var card = button.closest('[data-step-card]');
      if (!card) return;
      try {
        applyProductToCard(card, JSON.parse(button.dataset.product || '{}'));
        var panel = card.querySelector('[data-alternatives-panel]');
        var toggle = card.querySelector('[data-alternatives-toggle]');
        if (panel) panel.hidden = true;
        if (toggle) toggle.setAttribute('aria-expanded', 'false');
        updateComboTotal();
        activateStep(card.dataset.stepKey, false);
      } catch (error) {
        console.error('Alternative product data is invalid.', error);
      }
    });
  });
  root.querySelectorAll('[data-step-card]').forEach(function (card) {
    card.addEventListener('mouseenter', function () { activateStep(card.dataset.stepKey, false); });
    card.addEventListener('focusin', function () { activateStep(card.dataset.stepKey, false); });
  });
  root.querySelectorAll('[data-layer-index]').forEach(function (layer) {
    var activateLayer = function () {
      if (layer.hidden || !layer.dataset.layerStep) return;
      activateStep(layer.dataset.layerStep, true);
    };
    layer.addEventListener('mouseenter', activateLayer);
    layer.addEventListener('focus', activateLayer);
    layer.addEventListener('click', activateLayer);
    layer.addEventListener('keydown', function (event) {
      if (event.key === 'Enter' || event.key === ' ') {
        event.preventDefault();
        activateLayer();
      }
    });
  });

  var currentSelectedProducts = function () {
    var products = {};
    Object.keys(state.selected).forEach(function (key) {
      var product = state.selected[key];
      var id = String(product && (product.id || product.product_id) || '');
      if (id) products[id] = product;
    });
    return Object.keys(products).map(function (id) { return products[id]; });
  };
  var updateComboTotal = function () {
    if (data.combo_from_api && !state.comboDirty) return;
    var products = currentSelectedProducts();
    var total = products.reduce(function (sum, product) { return sum + Number(product.price || product.gia_ban || 0); }, 0);
    var original = products.reduce(function (sum, product) { return sum + Number(product.original_price || product.gia_thi_truong || 0); }, 0);
    var totalElement = root.querySelector('[data-combo-total]');
    var originalElement = root.querySelector('[data-combo-original]');
    var discountElement = root.querySelector('[data-combo-discount]');
    if (totalElement) totalElement.textContent = formatMoney(total);
    if (originalElement) {
      originalElement.textContent = original > total ? formatMoney(original) : '';
      originalElement.hidden = original <= total;
    }
    if (discountElement) {
      var discount = original > total ? Math.round((1 - total / original) * 100) : 0;
      discountElement.textContent = discount > 0 ? '-' + discount + '%' : '';
      discountElement.hidden = discount <= 0;
    }
  };

  var addCombo = function () {
    var button = root.querySelector('[data-add-combo]');
    if (!button) return;
    var products = currentSelectedProducts().filter(function (product) { return String(product.id || product.product_id || '') !== ''; });
    if (!products.length) {
      showFeedback('Chưa có sản phẩm hợp lệ để thêm vào giỏ.', true);
      return;
    }
    button.disabled = true;
    showFeedback('Đang thêm ' + products.length + ' sản phẩm vào giỏ...', false);
    var cartUrl = data.cart_url || (root.dataset.baseUrl + '/index.php?r=them_gio_hang_ajax');
    var added = 0;
    products.reduce(function (chain, product) {
      return chain.then(function () {
        var productId = String(product.id || product.product_id || '');
        var body = new FormData();
        body.append('action', 'add_to_cart');
        body.append('product_id', productId);
        body.append('ma_san_pham', productId);
        body.append('quantity', '1');
        body.append('qty', '1');
        return fetch(cartUrl, { method: 'POST', body: body, headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' } })
          .then(function (response) { return response.json().then(function (json) { return { response: response, json: json }; }); })
          .then(function (result) {
            if (!result.response.ok || !result.json || result.json.ok === false) throw new Error(result.json && result.json.message ? result.json.message : 'Không thể thêm sản phẩm.');
            added += 1;
          });
      });
    }, Promise.resolve()).then(function () {
      showFeedback('Đã thêm ' + added + ' sản phẩm vào giỏ hàng.', false);
    }).catch(function (error) {
      showFeedback('Đã thêm ' + added + '/' + products.length + ' sản phẩm. ' + error.message, true);
    }).finally(function () {
      button.disabled = false;
    });
  };
  var comboButton = root.querySelector('[data-add-combo]');
  if (comboButton) comboButton.addEventListener('click', addCombo);

  var buildIngredientMap = function () {
    var map = root.querySelector('[data-ingredient-map]');
    if (!map) return;
    var products = Array.isArray(data.map_products) ? data.map_products.filter(function (product) { return product && product.id; }) : [];
    var lineLayer = map.querySelector('[data-ingredient-lines]');
    var nodeLayer = map.querySelector('[data-ingredient-nodes]');
    var mobileList = map.querySelector('[data-ingredient-mobile-list]');
    if (!lineLayer || !nodeLayer) return;
    var positions = {};
    var width = Math.max(map.clientWidth, 480);
    var height = 330;
    lineLayer.setAttribute('viewBox', '0 0 ' + width + ' ' + height);
    nodeLayer.replaceChildren();
    products.forEach(function (product, index) {
      var column = index % 2;
      var row = Math.floor(index / 2);
      var rows = Math.max(1, Math.ceil(products.length / 2));
      var x = column ? 77 : 23;
      var y = 18 + ((row + 0.5) / rows) * 64;
      positions[String(product.id)] = { x: width * x / 100, y: height * y / 100 };
      var node = document.createElement('button');
      node.type = 'button';
      node.className = 'ingredient-map__node';
      node.dataset.productId = String(product.id);
      node.style.left = x + '%';
      node.style.top = y + '%';
      var image = document.createElement('img');
      image.src = product.image || '';
      image.alt = '';
      var label = document.createElement('span');
      label.textContent = product.name || 'Sản phẩm';
      node.appendChild(image);
      node.appendChild(label);
      node.addEventListener('click', function () {
        var card = currentCards().find(function (item) { return item.dataset.selectedProduct === String(product.id); });
        if (card) activateStep(card.dataset.stepKey, true);
      });
      nodeLayer.appendChild(node);
    });

    var edges = [];
    var ingredientOwners = {};
    products.forEach(function (product) {
      (product.key_ingredients || []).forEach(function (ingredient) {
        var key = normalize(ingredient);
        if (key) (ingredientOwners[key] = ingredientOwners[key] || []).push(String(product.id));
      });
    });
    Object.keys(ingredientOwners).forEach(function (ingredient) {
      var owners = Array.from(new Set(ingredientOwners[ingredient]));
      for (var i = 0; i < owners.length - 1; i += 1) {
        for (var j = i + 1; j < owners.length; j += 1) edges.push({ from: owners[i], to: owners[j], label: ingredient, conflict: false });
      }
    });
    var findProduct = function (label) {
      var needle = normalize(label);
      return products.find(function (product) {
        return normalize(product.id) === needle || normalize(product.name).indexOf(needle) !== -1;
      });
    };
    (data.conflict_warnings || []).forEach(function (warning) {
      var pair = Array.isArray(warning.pair) ? warning.pair : [];
      var first = findProduct(pair[0]);
      var second = findProduct(pair[1]);
      if (first && second) edges.push({ from: String(first.id), to: String(second.id), label: warning.resolution || 'Cần lưu ý', conflict: true });
    });
    lineLayer.replaceChildren();
    edges.forEach(function (edge) {
      var from = positions[edge.from];
      var to = positions[edge.to];
      if (!from || !to) return;
      var line = document.createElementNS('http://www.w3.org/2000/svg', 'line');
      line.setAttribute('x1', from.x);
      line.setAttribute('y1', from.y);
      line.setAttribute('x2', to.x);
      line.setAttribute('y2', to.y);
      line.setAttribute('class', edge.conflict ? 'ingredient-map__edge ingredient-map__edge--conflict' : 'ingredient-map__edge');
      var title = document.createElementNS('http://www.w3.org/2000/svg', 'title');
      title.textContent = edge.label;
      line.appendChild(title);
      lineLayer.appendChild(line);
    });
    if (mobileList) {
      mobileList.replaceChildren();
      edges.filter(function (edge) { return edge.conflict; }).forEach(function (edge) {
        var item = document.createElement('div');
        item.className = 'ingredient-map__mobile-item';
        var firstName = products.find(function (product) { return String(product.id) === edge.from; });
        var secondName = products.find(function (product) { return String(product.id) === edge.to; });
        var left = document.createElement('strong');
        var right = document.createElement('strong');
        var warning = document.createElement('small');
        left.textContent = firstName ? firstName.name : edge.from;
        right.textContent = secondName ? secondName.name : edge.to;
        warning.textContent = 'Cần lưu ý';
        item.appendChild(left);
        item.appendChild(warning);
        item.appendChild(right);
        mobileList.appendChild(item);
      });
      if (!mobileList.children.length && edges.length) {
        var info = document.createElement('div');
        info.className = 'ingredient-map__mobile-item';
        info.textContent = 'Có hoạt chất chung trong routine; hãy xem đường nối trên màn hình lớn.';
        mobileList.appendChild(info);
      }
    }
  };

  var consultForm = document.getElementById('aiConsultForm');
  if (consultForm) {
    consultForm.addEventListener('submit', function (event) {
      event.preventDefault();
      var input = document.getElementById('aiConsultInput');
      var button = document.getElementById('aiConsultBtn');
      var question = input ? input.value.trim() : '';
      if (!question) return;
      var feedback = consultForm.parentNode.querySelector('.recommendation-consult__feedback');
      if (!feedback) {
        feedback = document.createElement('p');
        feedback.className = 'recommendation-consult__feedback';
        consultForm.parentNode.appendChild(feedback);
      }
      if (button) { button.disabled = true; button.textContent = 'Đang hỏi...'; }
      var baseUrl = root.dataset.baseUrl || '';
      fetch(baseUrl + '/index.php?r=ai_chat_api', { method: 'POST', headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' }, body: JSON.stringify({ message: question }) })
        .then(function (response) { return response.json().then(function (json) { return { response: response, json: json }; }); })
        .then(function (result) { feedback.textContent = result.json.answer || result.json.message || (result.response.ok ? 'Syna đã nhận câu hỏi.' : 'Chưa thể trả lời lúc này.'); })
        .catch(function () { feedback.textContent = 'Chưa thể kết nối Syna lúc này.'; })
        .finally(function () { if (button) { button.disabled = false; button.textContent = 'Hỏi Syna'; } });
    });
  }

  setSession('am');
  updateComboTotal();
  buildIngredientMap();
  window.addEventListener('resize', buildIngredientMap, { passive: true });
}());
