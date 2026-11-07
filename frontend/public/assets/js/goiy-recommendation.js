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
    return amount > 0 ? money.format(Math.round(amount)) + ' đ' : '—';
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
    // Ảnh có thể đã lỗi trước khi script (defer) gắn listener.
    if (image.complete && image.naturalWidth === 0 && image.getAttribute('src')) {
      image.dispatchEvent(new Event('error'));
    }
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
  // Hộp "Vì sao Syna chọn cho bạn" (Tình trạng -> Vấn đề -> Giải pháp). Markup phải giống hệt
  // phần server render trong partials/recommendation_step_card.php; chỉ dùng textContent, không innerHTML.
  var cleanText = function (value) {
    return typeof value === 'string' || typeof value === 'number' ? String(value).trim() : '';
  };
  var createEl = function (tag, className, text) {
    var node = document.createElement(tag);
    if (className) node.className = className;
    if (text !== undefined && text !== null && text !== '') node.textContent = text;
    return node;
  };
  var createIcon = function (iconClass) {
    var icon = createEl('i', 'fa-solid ' + iconClass);
    icon.setAttribute('aria-hidden', 'true');
    return icon;
  };
  var createFitRow = function (label) {
    var row = createEl('div', 'fit-explain__row');
    row.appendChild(createEl('span', 'fit-explain__label', label));
    return row;
  };
  var fitParts = function (fit) {
    if (!fit || typeof fit !== 'object') return null;
    var skin = fit.skin && typeof fit.skin === 'object' ? fit.skin : {};
    var parts = {
      skinLabel: cleanText(skin.label),
      sensitivity: cleanText(skin.sensitivity),
      note: cleanText(skin.note),
      concerns: (Array.isArray(fit.concerns) ? fit.concerns : []).filter(function (concern) {
        return concern && typeof concern === 'object' && cleanText(concern.label) !== '';
      }),
      goal: fit.goal && typeof fit.goal === 'object' && fit.goal.matched === true && cleanText(fit.goal.label) !== '' ? cleanText(fit.goal.label) : '',
      solution: cleanText(fit.solution),
      actives: (Array.isArray(fit.actives) ? fit.actives : []).filter(function (active) {
        return active && typeof active === 'object' && cleanText(active.name) !== '';
      }).slice(0, 2),
      caution: cleanText(fit.caution)
    };
    var hasContent = parts.skinLabel !== '' || parts.concerns.length > 0 || parts.solution !== '' || parts.actives.length > 0;
    return hasContent ? parts : null;
  };
  var buildFit = function (box, parts) {
    var row;
    if (parts.skinLabel) {
      row = createFitRow('Tình trạng');
      var skinText = createEl('p', 'fit-explain__text');
      skinText.appendChild(createEl('b', '', parts.skinLabel));
      if (parts.sensitivity) skinText.appendChild(document.createTextNode(' · ' + parts.sensitivity));
      if (parts.note) {
        skinText.appendChild(document.createTextNode(' '));
        skinText.appendChild(createEl('span', 'fit-explain__note', parts.note));
      }
      row.appendChild(skinText);
      box.appendChild(row);
    }
    if (parts.concerns.length) {
      row = createFitRow('Vấn đề');
      var concernList = createEl('ul', 'fit-explain__concerns');
      parts.concerns.forEach(function (concern) {
        var label = cleanText(concern.label);
        var item;
        if (concern.matched === true) {
          item = createEl('li', 'is-matched');
          item.appendChild(createIcon('fa-check'));
          item.appendChild(document.createTextNode(' ' + label));
        } else {
          item = createEl('li', 'is-pending', label);
          item.title = 'Sản phẩm này chưa nhắm trực tiếp vấn đề này';
          var handledBy = cleanText(concern.handled_by);
          if (handledBy) item.appendChild(createEl('small', '', ' · bước ' + handledBy + ' đảm nhận'));
        }
        concernList.appendChild(item);
      });
      row.appendChild(concernList);
      box.appendChild(row);
    }
    if (parts.solution || parts.actives.length) {
      row = createFitRow('Giải pháp');
      var solutionBox = createEl('div', 'fit-explain__text');
      if (parts.solution) solutionBox.appendChild(createEl('p', '', parts.solution));
      if (parts.actives.length) {
        var activeList = createEl('ul', 'fit-explain__actives');
        parts.actives.forEach(function (active) {
          var item = createEl('li');
          item.appendChild(createEl('b', '', cleanText(active.name)));
          var benefit = cleanText(active.benefit);
          if (benefit) item.appendChild(document.createTextNode(' — ' + benefit));
          activeList.appendChild(item);
        });
        solutionBox.appendChild(activeList);
      }
      row.appendChild(solutionBox);
      box.appendChild(row);
    }
    if (parts.goal) {
      var goal = createEl('p', 'fit-explain__goal');
      goal.appendChild(createIcon('fa-bullseye'));
      goal.appendChild(document.createTextNode(' Đúng mục tiêu: ' + parts.goal));
      box.appendChild(goal);
    }
    if (parts.caution) {
      var caution = createEl('p', 'fit-explain__caution');
      caution.appendChild(createIcon('fa-circle-info'));
      caution.appendChild(document.createTextNode(' ' + parts.caution));
      box.appendChild(caution);
    }
  };
  var renderFit = function (card, fit, why) {
    var fitBox = card.querySelector('[data-product-fit]');
    var whyList = card.querySelector('[data-product-why]');
    var parts = fitParts(fit);
    if (fitBox) {
      fitBox.replaceChildren();
      if (parts) buildFit(fitBox, parts);
      fitBox.hidden = !parts;
    }
    if (whyList) {
      // Không có fit_explanation (cache cũ / thiếu dữ liệu): rơi về danh sách lý do cũ.
      if (!parts) {
        whyList.replaceChildren();
        (Array.isArray(why) ? why : []).slice(0, 3).forEach(function (reason) {
          var item = document.createElement('li');
          item.textContent = reason;
          whyList.appendChild(item);
        });
      }
      whyList.hidden = Boolean(parts) || !whyList.children.length;
    }
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
    renderFit(card, product.fit, product.why);
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
      var label = layer.querySelector('[data-layer-label]') || layer.querySelector('text');
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
  // Đổi chỗ: sản phẩm đang hiển thị chuyển xuống ô lựa chọn vừa bấm, nên người dùng
  // luôn có thể đổi lại mà không mất sản phẩm gợi ý ban đầu.
  var renderAlternative = function (button, product) {
    button.dataset.product = JSON.stringify(product);
    button.setAttribute('aria-label', 'Đổi sang ' + (product.name || 'sản phẩm khác'));
    var image = button.querySelector('[data-alternative-image]');
    var name = button.querySelector('[data-alternative-name]');
    var price = button.querySelector('[data-alternative-price]');
    var score = button.querySelector('[data-alternative-score]');
    if (image && product.image) image.src = product.image;
    if (name) name.textContent = product.name || '';
    if (price) price.textContent = formatMoney(product.price);
    if (score) {
      var hasScore = product.match_score !== null && product.match_score !== undefined && Number.isFinite(Number(product.match_score));
      score.textContent = hasScore ? Math.round(Number(product.match_score)) + '%' : '';
      score.hidden = !hasScore;
    }
  };
  // Thời điểm mỗi sản phẩm được đưa lên làm sản phẩm chính (theo id) để luân phiên chọn sản phẩm lâu chưa hiện.
  var lastShownAt = new Map();
  var swapWithAlternative = function (card, button) {
    if (!card || !button) return false;
    try {
      var next = JSON.parse(button.dataset.product || '{}');
      var currentElement = card.querySelector('[data-current-product]');
      var current = currentElement ? JSON.parse(currentElement.textContent || '{}') : null;
      applyProductToCard(card, next);
      if (currentElement) currentElement.textContent = JSON.stringify(next);
      if (current && current.id) renderAlternative(button, current);
      if (next && next.id) lastShownAt.set(String(next.id), Date.now());
      updateComboTotal();
      activateStep(card.dataset.stepKey, false);
      return true;
    } catch (error) {
      console.error('Alternative product data is invalid.', error);
      return false;
    }
  };
  root.querySelectorAll('[data-alternative]').forEach(function (button) {
    button.addEventListener('click', function () {
      var card = button.closest('[data-step-card]');
      if (!card) return;
      // Người dùng đã tự chọn: luân phiên tự động không đổi thẻ này nữa.
      card.dataset.userPicked = 'true';
      swapWithAlternative(card, button);
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

  // ---- Luân phiên gợi ý ----
  // Sau mỗi chu kỳ (mặc định 3 phút), sản phẩm chính của từng bước tự đổi sang một lựa chọn có độ phù hợp
  // tương đương. Không đổi thẻ đang được trỏ chuột / focus, thẻ người dùng đã tự chọn, thẻ đang bị lọc ẩn,
  // và đếm ngược dừng khi tab bị ẩn để không dồn lượt đổi.
  var ROTATE_STORAGE_KEY = 'skinsyntax.goiy.rotate';
  var ROTATE_MIN_SCORE = 60;
  var ROTATE_SCORE_GAP = 15;
  var rotateMs = Number(root.dataset.rotateInterval);
  if (!(rotateMs >= 1000)) rotateMs = 180000;
  var rotationControl = root.querySelector('[data-rotation-control]');
  var rotationStatus = root.querySelector('[data-rotation-status]');
  var rotationToggle = root.querySelector('[data-rotation-toggle]');
  var rotationToast = root.querySelector('[data-rotation-toast]');
  var rotationToastText = root.querySelector('[data-rotation-toast-text]');
  var rotationUndo = root.querySelector('[data-rotation-undo]');
  // Đưa toast ra body để z-index không bị giới hạn trong stacking context của workspace
  // (nếu không, popup "Top sale" ở footer che mất nút Hoàn tác).
  if (rotationToast && rotationToast.parentNode !== document.body) document.body.appendChild(rotationToast);
  var rotation = { enabled: true, remainingMs: rotateMs, timer: null, toastTimer: null, lastRun: null };

  var readRotatePref = function () {
    try {
      var value = window.localStorage.getItem(ROTATE_STORAGE_KEY);
      return value === 'on' || value === 'off' ? value : null;
    } catch (error) {
      return null;
    }
  };
  var writeRotatePref = function (value) {
    try {
      window.localStorage.setItem(ROTATE_STORAGE_KEY, value);
    } catch (error) {
      // localStorage có thể bị chặn (chế độ riêng tư); bỏ qua, trạng thái chỉ giữ trong phiên.
    }
  };
  var prefersReducedMotion = function () {
    try {
      return Boolean(window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches);
    } catch (error) {
      return false;
    }
  };

  var parseButtonProduct = function (button) {
    try {
      var product = JSON.parse(button.dataset.product || '{}');
      return product && typeof product === 'object' ? product : null;
    } catch (error) {
      return null;
    }
  };
  var scoreOf = function (product) {
    if (!product || product.match_score === null || product.match_score === undefined || product.match_score === '') return null;
    var value = Number(product.match_score);
    return Number.isFinite(value) ? value : null;
  };

  // Ngưỡng "tương đương" của từng thẻ: điểm cao nhất trong sản phẩm chính + các lựa chọn trừ 15 (tối thiểu 60).
  var initRotationPool = function (card) {
    var scores = [];
    var currentElement = card.querySelector('[data-current-product]');
    var current = null;
    try {
      current = currentElement ? JSON.parse(currentElement.textContent || '{}') : null;
    } catch (error) {
      current = null;
    }
    var currentScore = scoreOf(current);
    if (currentScore !== null) scores.push(currentScore);
    if (current && current.id) lastShownAt.set(String(current.id), Date.now());
    card.querySelectorAll('[data-alternative]').forEach(function (button) {
      var score = scoreOf(parseButtonProduct(button));
      if (score !== null) scores.push(score);
    });
    var topScore = scores.length ? Math.max.apply(null, scores) : 0;
    card.dataset.rotateThreshold = String(Math.max(ROTATE_MIN_SCORE, topScore - ROTATE_SCORE_GAP));
  };

  // Nút thay thế đủ điều kiện, ưu tiên sản phẩm lâu chưa hiện nhất, hoà thì chọn điểm cao hơn.
  var nextRotationButton = function (card) {
    var threshold = Number(card.dataset.rotateThreshold || ROTATE_MIN_SCORE);
    var best = null;
    var bestShown = 0;
    var bestScore = 0;
    card.querySelectorAll('[data-alternative]').forEach(function (button) {
      var product = parseButtonProduct(button);
      var score = scoreOf(product);
      if (!product || !product.id || score === null || score < threshold) return;
      var shown = lastShownAt.get(String(product.id)) || 0;
      if (!best || shown < bestShown || (shown === bestShown && score > bestScore)) {
        best = button;
        bestShown = shown;
        bestScore = score;
      }
    });
    return best;
  };
  var isCardBusy = function (card) {
    return card.matches(':hover')
      || card.contains(document.activeElement)
      || card.dataset.userPicked === 'true'
      || card.classList.contains('is-filtered-out');
  };

  var formatCountdown = function (ms) {
    var seconds = Math.max(0, Math.ceil(ms / 1000));
    var rest = seconds % 60;
    return Math.floor(seconds / 60) + ':' + (rest < 10 ? '0' : '') + rest;
  };
  var updateRotationUi = function () {
    if (rotationStatus) {
      rotationStatus.textContent = rotation.enabled
        ? 'Gợi ý luân phiên sau ' + formatCountdown(rotation.remainingMs)
        : 'Đã tạm dừng tự đổi gợi ý';
    }
    if (rotationToggle) {
      rotationToggle.textContent = rotation.enabled ? 'Tạm dừng' : 'Bật tự đổi';
      rotationToggle.setAttribute('aria-pressed', rotation.enabled ? 'false' : 'true');
    }
  };

  var hideRotationToast = function () {
    window.clearTimeout(rotation.toastTimer);
    rotation.toastTimer = null;
    rotation.lastRun = null;
    if (rotationToast) rotationToast.hidden = true;
  };
  var showRotationToast = function (count) {
    if (!rotationToast || !rotationToastText) return;
    rotationToastText.textContent = 'Syna vừa làm mới ' + count + ' gợi ý có độ phù hợp tương đương.';
    rotationToast.hidden = false;
    window.clearTimeout(rotation.toastTimer);
    rotation.toastTimer = window.setTimeout(hideRotationToast, 8000);
  };
  var undoRotation = function () {
    var run = rotation.lastRun;
    if (run) {
      run.records.slice().reverse().forEach(function (record) {
        // Thẻ người dùng đã tự chọn sau lượt luân phiên thì giữ nguyên lựa chọn của họ.
        if (record.card.dataset.userPicked !== 'true') swapWithAlternative(record.card, record.button);
      });
    }
    hideRotationToast();
  };

  var rotateTick = function () {
    var targets = [];
    currentCards().forEach(function (card) {
      if (isCardBusy(card)) return;
      var button = nextRotationButton(card);
      if (button) targets.push({ card: card, button: button });
    });
    if (!targets.length) return;
    // Lượt mới thay thế lượt cũ: bản ghi hoàn tác của lượt trước bị xoá cùng toast.
    hideRotationToast();
    var run = { records: [], pending: targets.length };
    rotation.lastRun = run;
    var finishOne = function () {
      run.pending -= 1;
      if (run.pending === 0 && rotation.lastRun === run) {
        if (run.records.length) showRotationToast(run.records.length);
        else rotation.lastRun = null;
      }
    };
    targets.forEach(function (target, index) {
      window.setTimeout(function () {
        var card = target.card;
        card.classList.add('is-rotating');
        window.setTimeout(function () {
          card.classList.remove('is-rotating');
          // Người dùng có thể vừa trỏ chuột/chọn thẻ trong lúc hiệu ứng chạy: kiểm tra lại trước khi đổi.
          if (!isCardBusy(card) && swapWithAlternative(card, target.button)) {
            run.records.push({ card: card, button: target.button });
            card.classList.add('is-rotated');
            window.setTimeout(function () { card.classList.remove('is-rotated'); }, 1600);
          }
          finishOne();
        }, 250);
      }, index * 120);
    });
  };

  var setRotationEnabled = function (enabled, shouldPersist) {
    rotation.enabled = enabled;
    if (shouldPersist) writeRotatePref(enabled ? 'on' : 'off');
    updateRotationUi();
  };

  allCards().forEach(initRotationPool);
  var hasRotatableCard = allCards().some(function (card) { return nextRotationButton(card) !== null; });
  if (hasRotatableCard && rotationControl) {
    var storedPref = readRotatePref();
    rotation.enabled = storedPref ? storedPref === 'on' : !prefersReducedMotion();
    rotationControl.hidden = false;
    updateRotationUi();
    if (rotationToggle) {
      rotationToggle.addEventListener('click', function () { setRotationEnabled(!rotation.enabled, true); });
    }
    if (rotationUndo) rotationUndo.addEventListener('click', undoRotation);
    // Một timer 1 giây: chỉ trừ thời gian khi đang bật và tab đang hiển thị (không dồn lượt đổi khi quay lại).
    rotation.timer = window.setInterval(function () {
      if (!rotation.enabled || document.hidden) return;
      rotation.remainingMs -= 1000;
      if (rotation.remainingMs <= 0) {
        rotateTick();
        rotation.remainingMs = rotateMs;
      }
      updateRotationUi();
    }, 1000);
  }
  // Hook cho QA: chạy ngay một lượt luân phiên và đặt lại đồng hồ đếm ngược.
  root.addEventListener('goiy:rotate-now', function () {
    rotateTick();
    rotation.remainingMs = rotateMs;
    if (rotationControl && !rotationControl.hidden) updateRotationUi();
  });
}());
