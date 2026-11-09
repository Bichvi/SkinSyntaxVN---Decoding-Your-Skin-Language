<?php
$product = is_array($swipeProduct ?? null) ? $swipeProduct : null;
$remaining = max(0, (int)($swipeRemaining ?? 0));
$productJson = json_encode($product, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?: 'null';
?>
<style>
  .beauty-swipe-page { max-width: 560px; margin: 0 auto; padding: 24px 12px calc(40px + env(safe-area-inset-bottom)); }
  .beauty-swipe-head { display:flex; justify-content:space-between; align-items:end; gap:12px; margin-bottom:16px; }
  .beauty-swipe-kicker { margin:0 0 5px; color:#39805b; font-size:.72rem; font-weight:800; letter-spacing:.12em; text-transform:uppercase; }
  .beauty-swipe-head h1 { margin:0; color:#173a2b; font-size:clamp(1.7rem,7vw,2.4rem); }
  .beauty-swipe-count { padding:7px 10px; border-radius:999px; color:#256044; background:#eaf6ed; font-size:.78rem; font-weight:750; white-space:nowrap; }
  .beauty-swipe-card { position:relative; min-height:560px; overflow:hidden; border:1px solid #dcebe1; border-radius:26px; background:#fff; box-shadow:0 18px 44px rgba(25,73,49,.14); touch-action:pan-y; }
  .beauty-swipe-card.is-moving { transition:none; }
  .beauty-swipe-card.is-out { opacity:0; transform:translateX(var(--swipe-x, 0)) rotate(var(--swipe-rotate, 0)); transition:opacity .18s, transform .18s; }
  .beauty-swipe-image-wrap { height:390px; padding:18px; background:linear-gradient(155deg,#eff8f1,#f8fbfa); }
  .beauty-swipe-image { width:100%; height:100%; object-fit:contain; border-radius:18px; }
  .beauty-swipe-body { padding:18px 20px 20px; }
  .beauty-swipe-brand { margin:0 0 5px; color:#6b8476; font-size:.78rem; font-weight:800; text-transform:uppercase; letter-spacing:.08em; }
  .beauty-swipe-name { margin:0 0 10px; color:#183427; font-size:1.15rem; line-height:1.35; }
  .beauty-swipe-price { display:flex; gap:10px; align-items:baseline; color:#12603c; font-size:1.15rem; font-weight:850; }
  .beauty-swipe-price del { color:#94a198; font-size:.82rem; font-weight:500; }
  .beauty-swipe-tags { display:flex; flex-wrap:wrap; gap:6px; margin-top:12px; }
  .beauty-swipe-tag { padding:5px 8px; border-radius:999px; color:#477160; background:#edf7ef; font-size:.72rem; }
  .beauty-swipe-empty { display:grid; place-items:center; min-height:420px; padding:32px; border:1px dashed #bdd8c5; border-radius:24px; color:#557263; background:#f7fbf8; text-align:center; }
  .beauty-swipe-actions { display:flex; justify-content:center; align-items:center; gap:12px; padding:18px 0 12px; }
  .beauty-swipe-actions button { width:54px; height:54px; border:1px solid #d4e3d9; border-radius:50%; color:#22523a; background:#fff; box-shadow:0 8px 18px rgba(27,75,48,.08); font-size:1.1rem; }
  .beauty-swipe-actions button[data-swipe-action="like"] { width:64px; height:64px; color:#fff; border-color:#137648; background:#137648; font-size:1.3rem; }
  .beauty-swipe-actions button:disabled { opacity:.45; }
  .beauty-swipe-links { display:flex; justify-content:center; gap:12px; flex-wrap:wrap; }
  .beauty-swipe-links a { color:#216345; font-size:.85rem; font-weight:750; text-decoration:none; }
  .beauty-swipe-feedback { min-height:22px; color:#557263; font-size:.82rem; text-align:center; }
  .beauty-swipe-feedback.is-error { color:#b42318; }
  .beauty-swipe-hint { margin:12px 0 0; color:#789084; font-size:.78rem; text-align:center; }
  @media (max-width:480px) { .beauty-swipe-page { padding-top:16px; } .beauty-swipe-card { min-height:500px; } .beauty-swipe-image-wrap { height:320px; } }
</style>

<main class="beauty-swipe-page" data-beauty-swipe-root data-action-url="<?= h(BASE_URL . '/index.php?r=beauty_swipe_action') ?>" data-cart-url="<?= h(BASE_URL . '/index.php?r=them_gio_hang_ajax') ?>">
  <header class="beauty-swipe-head">
    <div><p class="beauty-swipe-kicker">Khám phá cho bạn</p><h1>Chọn món hợp da</h1></div>
    <span class="beauty-swipe-count" data-swipe-count><?= h((string)$remaining) ?> sản phẩm</span>
  </header>
  <p class="beauty-swipe-feedback" data-swipe-feedback role="status" aria-live="polite"></p>
  <section class="beauty-swipe-card" data-swipe-card <?= $product ? '' : 'hidden' ?> aria-label="Thẻ sản phẩm">
    <div class="beauty-swipe-image-wrap"><img class="beauty-swipe-image" data-swipe-image data-fallback-image="<?= h(default_placeholder_image()) ?>" src="<?= h((string)($product['image'] ?? default_placeholder_image())) ?>" alt="<?= h((string)($product['name'] ?? '')) ?>"></div>
    <div class="beauty-swipe-body">
      <p class="beauty-swipe-brand" data-swipe-brand><?= h((string)($product['brand'] ?? '')) ?></p>
      <h2 class="beauty-swipe-name" data-swipe-name><?= h((string)($product['name'] ?? '')) ?></h2>
      <div class="beauty-swipe-price"><strong data-swipe-price><?= h(vnd((int)($product['price'] ?? 0))) ?></strong><del data-swipe-original <?= (int)($product['original_price'] ?? 0) > (int)($product['price'] ?? 0) ? '' : 'hidden' ?>><?= h(vnd((int)($product['original_price'] ?? 0))) ?></del></div>
      <div class="beauty-swipe-tags" data-swipe-tags><?php foreach (array_slice((array)($product['ingredients'] ?? []), 0, 4) as $tag): ?><span class="beauty-swipe-tag"><?= h((string)$tag) ?></span><?php endforeach; ?></div>
    </div>
  </section>
  <div class="beauty-swipe-empty" data-swipe-empty <?= $product ? 'hidden' : '' ?>>Hiện chưa có thêm sản phẩm đang còn hàng để khám phá. Bạn có thể quay lại <a href="<?= h(BASE_URL . '/index.php?r=goiy') ?>">routine cá nhân hóa</a>.</div>
  <div class="beauty-swipe-actions" data-swipe-actions <?= $product ? '' : 'hidden' ?>>
    <button type="button" data-swipe-action="skip" aria-label="Bỏ qua"><i class="fa-solid fa-xmark" aria-hidden="true"></i></button>
    <button type="button" data-swipe-action="undo" aria-label="Hoàn tác"><i class="fa-solid fa-rotate-left" aria-hidden="true"></i></button>
    <button type="button" data-swipe-action="like" aria-label="Lưu sản phẩm"><i class="fa-solid fa-heart" aria-hidden="true"></i></button>
  </div>
  <div class="beauty-swipe-links" data-swipe-links <?= $product ? '' : 'hidden' ?>><a href="#" data-swipe-detail>Xem chi tiết</a><a href="#" data-swipe-cart>Thêm vào giỏ</a></div>
  <p class="beauty-swipe-hint">Vuốt trái để bỏ qua · vuốt phải để lưu · có thể hoàn tác lựa chọn gần nhất.</p>
</main>

<script type="application/json" id="beauty-swipe-data"><?= $productJson ?></script>
<script>
(function () {
  var root = document.querySelector('[data-beauty-swipe-root]');
  var dataEl = document.getElementById('beauty-swipe-data');
  if (!root || !dataEl) return;
  var current = null;
  try { current = JSON.parse(dataEl.textContent || 'null'); } catch (e) { current = null; }
  var card = root.querySelector('[data-swipe-card]');
  var empty = root.querySelector('[data-swipe-empty]');
  var actions = root.querySelector('[data-swipe-actions]');
  var links = root.querySelector('[data-swipe-links]');
  var feedback = root.querySelector('[data-swipe-feedback]');
  var count = root.querySelector('[data-swipe-count]');
  var money = new Intl.NumberFormat('vi-VN');
  var busy = false;
  var imageFallbacks = [];
  var setFeedback = function (message, error) { feedback.textContent = message || ''; feedback.classList.toggle('is-error', !!error); };
  var setVisible = function (visible) { card.hidden = !visible; actions.hidden = !visible; links.hidden = !visible; empty.hidden = visible; };
  var render = function (product) {
    current = product || null;
    if (!current) { setVisible(false); return; }
    setVisible(true);
    card.classList.remove('is-out'); card.style.removeProperty('--swipe-x'); card.style.removeProperty('--swipe-rotate');
    var image = root.querySelector('[data-swipe-image]');
    imageFallbacks = Array.isArray(current.image_fallbacks) ? current.image_fallbacks.slice() : [];
    image.onerror = function () {
      var fallback = imageFallbacks.shift() || image.dataset.fallbackImage || '';
      if (fallback && image.src !== fallback) image.src = fallback;
      else image.onerror = null;
    };
    image.src = current.image || image.dataset.fallbackImage || ''; image.alt = current.name || '';
    root.querySelector('[data-swipe-brand]').textContent = current.brand || '';
    root.querySelector('[data-swipe-name]').textContent = current.name || 'Sản phẩm SkinSyntax';
    root.querySelector('[data-swipe-price]').textContent = (Number(current.price || 0) > 0 ? money.format(Number(current.price)) + ' đ' : '—');
    var original = root.querySelector('[data-swipe-original]'); original.textContent = Number(current.original_price || 0) > Number(current.price || 0) ? money.format(Number(current.original_price)) + ' đ' : ''; original.hidden = !(Number(current.original_price || 0) > Number(current.price || 0));
    var tags = root.querySelector('[data-swipe-tags]'); tags.replaceChildren(); (Array.isArray(current.ingredients) ? current.ingredients : []).slice(0, 4).forEach(function (tag) { var el = document.createElement('span'); el.className = 'beauty-swipe-tag'; el.textContent = tag; tags.appendChild(el); });
    root.querySelector('[data-swipe-detail]').href = current.detail_url || '#';
  };
  var send = function (action) {
    if (busy || !current && action !== 'undo') return;
    busy = true; setFeedback('Đang cập nhật lựa chọn…', false);
    var body = new FormData(); body.append('action', action); body.append('request_id', 'swipe-' + Date.now() + '-' + Math.random().toString(16).slice(2)); if (current) body.append('product_id', current.id || '');
    fetch(root.dataset.actionUrl, { method:'POST', body:body, headers:{'X-Requested-With':'XMLHttpRequest','Accept':'application/json'} }).then(function (r) { return r.json().then(function (j) { return { ok:r.ok, data:j }; }); }).then(function (result) { if (!result.ok || !result.data.ok) throw new Error(result.data.message || 'Không thể cập nhật.'); render(result.data.product || null); count.textContent = String(result.data.remaining || 0) + ' sản phẩm'; setFeedback(action === 'like' ? 'Đã lưu sản phẩm vào phiên khám phá.' : action === 'undo' ? 'Đã hoàn tác.' : '', false); }).catch(function (e) { setFeedback(e.message, true); }).finally(function () { busy = false; });
  };
  root.querySelectorAll('[data-swipe-action]').forEach(function (button) { button.addEventListener('click', function () { send(button.dataset.swipeAction); }); });
  root.querySelector('[data-swipe-cart]').addEventListener('click', function (event) {
    event.preventDefault(); if (!current || busy) return; var body = new FormData(); body.append('action','add_to_cart'); body.append('product_id',current.id || ''); body.append('ma_san_pham',current.id || ''); body.append('quantity','1'); body.append('qty','1'); fetch(root.dataset.cartUrl,{method:'POST',body:body,headers:{'X-Requested-With':'XMLHttpRequest','Accept':'application/json'}}).then(function(r){return r.json();}).then(function(j){setFeedback(j.message || (j.ok ? 'Đã thêm vào giỏ.' : 'Không thể thêm vào giỏ.'), !j.ok);}).catch(function(){setFeedback('Không thể thêm vào giỏ.',true);});
  });
  var initialImage = root.querySelector('[data-swipe-image]');
  if (initialImage) initialImage.onerror = function () {
    var fallback = imageFallbacks.shift() || initialImage.dataset.fallbackImage || '';
    if (fallback && initialImage.src !== fallback) initialImage.src = fallback;
    else initialImage.onerror = null;
  };
  var startX = 0, startY = 0;
  card.addEventListener('pointerdown', function (event) { if (busy || !current) return; startX = event.clientX; startY = event.clientY; card.setPointerCapture(event.pointerId); card.classList.add('is-moving'); });
  card.addEventListener('pointerup', function (event) { if (!card.classList.contains('is-moving')) return; card.classList.remove('is-moving'); var dx = event.clientX - startX, dy = event.clientY - startY; if (Math.abs(dx) < 60 || Math.abs(dx) < Math.abs(dy)) return; card.style.setProperty('--swipe-x', (dx > 0 ? '110%' : '-110%')); card.style.setProperty('--swipe-rotate', (dx > 0 ? '8deg' : '-8deg')); send(dx > 0 ? 'like' : 'skip'); });
  render(current);
})();
</script>
