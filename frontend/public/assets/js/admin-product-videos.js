/*
 * Video trang sản phẩm & voucher (index.php?r=admin_ai_product_videos).
 *
 * Self-contained: the AI Idol Studio never loads this file. The pure helpers at the top have no DOM,
 * network or clock access and are exported for the Node unit tests (module.exports); the page code
 * below them only runs in a browser that rendered #adminProductVideos.
 *
 * Talks to the existing JSON routes, CSRF in the body and in X-CSRF-Token:
 *   GET  admin_ai_idol_product_videos, admin_ai_idol_search_products, admin_ai_idol_reward_campaign
 *   POST admin_ai_idol_publish_video, admin_ai_idol_toggle_publish,
 *        admin_ai_idol_save_reward_campaign, admin_ai_idol_toggle_reward_campaign
 */
(function (root, factory) {
  const api = factory();
  if (typeof module === 'object' && module.exports) module.exports = api;
  else root.AdminProductVideos = api;
})(typeof self !== 'undefined' ? self : this, function () {
  'use strict';

  // ------------------------------------------------------------------ pure helpers

  const ESCAPES = { '&': '&amp;', '<': '&lt;', '>': '&gt;', "'": '&#39;', '"': '&quot;' };
  const escapeHtml = (value) => String(value == null ? '' : value).replace(/[&<>'"]/g, (ch) => ESCAPES[ch]);

  const normalizeText = (value) => String(value == null ? '' : value)
    .normalize('NFD').replace(/[̀-ͯ]/g, '').replace(/đ/gi, 'd').toLocaleLowerCase('vi');

  const formatDuration = (totalSeconds) => {
    const sec = Math.max(0, Math.floor(Number(totalSeconds) || 0));
    return `${String(Math.floor(sec / 60)).padStart(2, '0')}:${String(sec % 60).padStart(2, '0')}`;
  };

  const formatMoney = (value) => `${(Number(value) || 0).toLocaleString('vi-VN')} đ`;

  // A publication the pipeline created (ai-idol-service, spec D10) keeps source "auto" even after an admin edits it.
  const isAutoSource = (video) => String((video && video.source) || '').toLowerCase() === 'auto';

  const countVideos = (videos) => {
    const list = Array.isArray(videos) ? videos : [];
    const published = list.filter((v) => v && v.is_published).length;
    return { all: list.length, published, unpublished: list.length - published };
  };

  const filterVideos = (videos, filter, query) => {
    const needle = normalizeText(String(query || '').trim());
    return (Array.isArray(videos) ? videos : []).filter((v) => {
      if (!v) return false;
      if (filter === 'published' && !v.is_published) return false;
      if (filter === 'unpublished' && v.is_published) return false;
      if (!needle) return true;
      const haystack = normalizeText(`${v.title || ''} ${v.campaign_name || ''} ${(v.associated_product_ids || []).join(' ')}`);
      return haystack.includes(needle);
    });
  };

  const discountText = (campaign) => {
    if (!campaign) return '';
    return campaign.discount_type === 'percentage'
      ? `${Number(campaign.discount_value) || 0}%`
      : formatMoney(campaign.discount_value);
  };

  const pad2 = (n) => String(n).padStart(2, '0');
  const toLocalInput = (value) => {
    if (value instanceof Date) {
      if (Number.isNaN(value.getTime())) return '';
      return `${value.getFullYear()}-${pad2(value.getMonth() + 1)}-${pad2(value.getDate())}T${pad2(value.getHours())}:${pad2(value.getMinutes())}`;
    }
    const text = String(value == null ? '' : value).trim();
    if (!text) return '';
    const plain = /^(\d{4})-(\d{2})-(\d{2})[ T](\d{2}):(\d{2})/.exec(text);
    if (plain && !/(Z|[+-]\d{2}:?\d{2})$/.test(text)) return `${plain[1]}-${plain[2]}-${plain[3]}T${plain[4]}:${plain[5]}`;
    return toLocalInput(new Date(text));
  };

  // The selected primary product survives a selection change as long as it is still selected.
  const reconcilePrimary = (selectedIds, current) => {
    const ids = Array.from(selectedIds || []).map(String);
    if (current != null && ids.includes(String(current))) return String(current);
    return ids[0] || '';
  };

  // First problem in the voucher form, or '' when it can be sent. values: strings as typed in the inputs.
  const validateReward = (values, editingCampaign) => {
    const v = values || {};
    if (!String(v.name || '').trim()) return 'Vui lòng nhập tên chiến dịch ưu đãi.';
    const value = parseFloat(v.value) || 0;
    if (v.type === 'percentage' && (value <= 0 || value > 100)) return 'Mức giảm phần trăm phải từ 1 đến 100%.';
    if (v.type === 'fixed' && value <= 0) return 'Mức giảm tiền cố định phải lớn hơn 0 VNĐ.';
    if (!v.start || !v.end) return 'Vui lòng chọn ngày bắt đầu và kết thúc.';
    if (new Date(v.start) >= new Date(v.end)) return 'Ngày bắt đầu phải trước ngày kết thúc.';
    const quota = parseInt(v.quota, 10) || 0;
    if (quota <= 0) return 'Tổng hạn ngạch phát hành phải lớn hơn 0.';
    if (editingCampaign && quota < Number(editingCampaign.issued_count || 0)) {
      return `Hạn ngạch (${quota}) không thể nhỏ hơn số voucher đã phát hành thực tế (${Number(editingCampaign.issued_count || 0)}).`;
    }
    if (!(Number(v.selectedCount) > 0)) return 'Vui lòng chọn ít nhất 1 sản phẩm áp dụng voucher.';
    return '';
  };

  const pure = {
    escapeHtml, normalizeText, formatDuration, formatMoney, isAutoSource, countVideos, filterVideos,
    discountText, toLocalInput, reconcilePrimary, validateReward,
  };

  // ------------------------------------------------------------------ page

  if (typeof document === 'undefined') return pure;
  const page = document.getElementById('adminProductVideos');
  if (!page) return pure;

  const $ = (id) => document.getElementById(id);
  let config = {};
  try { config = JSON.parse(($('apvConfig') || {}).textContent || '{}') || {}; } catch (_) { config = {}; }
  const CSRF = String(config.csrf || '');
  const PLACEHOLDER = String(config.placeholder || '');

  const state = { videos: [], filter: 'all', query: '', loaded: false };
  const catalog = [];
  const catalogById = new Map();

  function addProducts(list) {
    let added = false;
    (Array.isArray(list) ? list : []).forEach((p) => {
      const id = String(p && p.id != null ? p.id : '');
      if (!id || catalogById.has(id)) return;
      const item = { id, name: String(p.name || `Sản phẩm ${id}`), image: String(p.image || ''), price: Number(p.price) || 0 };
      catalogById.set(id, item);
      catalog.push(item);
      added = true;
    });
    return added;
  }
  addProducts(config.products);

  // ---- network

  async function request(url, options) {
    const opts = options || {};
    const controller = new AbortController();
    const timer = setTimeout(() => controller.abort(), opts.timeoutMs || 30000);
    let response;
    try {
      response = await fetch(url, Object.assign({}, opts, { signal: controller.signal }));
    } catch (err) {
      throw new Error(err && err.name === 'AbortError'
        ? 'Yêu cầu quá thời gian chờ. Vui lòng thử lại.'
        : `Không thể kết nối đến máy chủ: ${(err && err.message) || 'lỗi mạng'}`);
    } finally {
      clearTimeout(timer);
    }
    const raw = await response.text();
    let data;
    try { data = raw ? JSON.parse(raw) : {}; } catch (_) {
      throw new Error(`Máy chủ trả về phản hồi không hợp lệ (HTTP ${response.status}).`);
    }
    if (!response.ok || !data.ok) throw new Error(data.message || `HTTP ${response.status}`);
    return data;
  }

  const post = (route, payload) => request(`index.php?r=${route}`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': CSRF },
    body: JSON.stringify(Object.assign({}, payload, { csrf: CSRF })),
  });

  // ---- small UI helpers

  function showBox(box, text, kind) {
    if (!box) return;
    box.textContent = text;
    box.className = `apv-message${kind === 'success' ? ' success' : ''}`;
    box.hidden = !text;
  }
  const notice = (text, kind) => showBox($('apvNotice'), text, kind);

  function setBusy(button, busy, busyHtml) {
    if (!button) return;
    if (busy) {
      if (button.dataset.idleHtml === undefined) button.dataset.idleHtml = button.innerHTML;
      button.disabled = true;
      button.innerHTML = busyHtml;
    } else {
      button.disabled = false;
      if (button.dataset.idleHtml !== undefined) { button.innerHTML = button.dataset.idleHtml; delete button.dataset.idleHtml; }
    }
  }
  const SPIN = '<i class="bi bi-arrow-repeat apv-spin" aria-hidden="true"></i> ';

  // ---- modals: focus on open, Tab trap, Esc to close, focus back to the opener

  const FOCUSABLE = 'a[href], button:not([disabled]), input:not([disabled]):not([type="hidden"]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])';
  const modals = {
    pub: { backdrop: $('apvPubBackdrop'), dialog: $('apvPubModal'), opener: null, onClose: null },
    reward: { backdrop: $('apvRewardBackdrop'), dialog: $('apvRewardModal'), opener: null, onClose: null },
  };
  let openKey = null;

  const focusables = (dialog) => Array.from(dialog.querySelectorAll(FOCUSABLE)).filter((el) => el.offsetParent !== null);

  function openerOf(element) {
    if (!element || !element.dataset) return null;
    return { videoId: element.dataset.videoId || '', action: element.dataset.apvAction || '', id: element.id || '' };
  }

  function openModal(key, openerElement) {
    const modal = modals[key];
    if (!modal || !modal.backdrop) return;
    modal.opener = openerOf(openerElement);
    modal.backdrop.hidden = false;
    openKey = key;
    document.body.classList.add('apv-modal-open');
    const items = focusables(modal.dialog);
    const first = items.find((el) => el.matches('input[type="text"], input[type="number"]')) || items[0] || modal.dialog;
    first.focus();
  }

  function restoreFocus(opener) {
    let target = null;
    if (opener && opener.videoId && opener.action) {
      const sel = `[data-video-id="${window.CSS && CSS.escape ? CSS.escape(opener.videoId) : opener.videoId}"][data-apv-action="${opener.action}"]`;
      target = page.querySelector(sel);
    }
    if (!target && opener && opener.id) target = $(opener.id);
    if (!target || target.disabled) target = $('apvRefresh');
    if (target) target.focus();
  }

  function closeModal(key) {
    const modal = modals[key];
    if (!modal || !modal.backdrop || modal.backdrop.hidden) return;
    modal.backdrop.hidden = true;
    if (openKey === key) openKey = null;
    document.body.classList.remove('apv-modal-open');
    if (typeof modal.onClose === 'function') modal.onClose();
    restoreFocus(modal.opener);
    modal.opener = null;
  }

  document.addEventListener('keydown', (event) => {
    if (!openKey) return;
    const modal = modals[openKey];
    if (event.key === 'Escape') {
      event.preventDefault();
      closeModal(openKey);
      return;
    }
    if (event.key !== 'Tab') return;
    const items = focusables(modal.dialog);
    if (!items.length) { event.preventDefault(); modal.dialog.focus(); return; }
    const first = items[0];
    const last = items[items.length - 1];
    const active = document.activeElement;
    if (event.shiftKey && (active === first || !modal.dialog.contains(active))) { event.preventDefault(); last.focus(); }
    else if (!event.shiftKey && (active === last || !modal.dialog.contains(active))) { event.preventDefault(); first.focus(); }
  });

  Object.keys(modals).forEach((key) => {
    const modal = modals[key];
    if (!modal.backdrop) return;
    modal.backdrop.addEventListener('mousedown', (event) => { if (event.target === modal.backdrop) closeModal(key); });
  });
  page.querySelectorAll('[data-apv-close]').forEach((btn) => btn.addEventListener('click', () => closeModal(btn.dataset.apvClose)));

  // Broken images (relative or expired product links) fall back to the placeholder once.
  page.addEventListener('error', (event) => {
    const img = event.target;
    if (img && img.tagName === 'IMG' && PLACEHOLDER && img.dataset.fallback !== '1') {
      img.dataset.fallback = '1';
      img.src = PLACEHOLDER;
    }
  }, true);

  // ---- product picker (used by both modals)

  function createPicker(opts) {
    const selected = new Set();
    let term = '';
    let debounce = null;

    const visible = () => {
      const needle = normalizeText(term.trim());
      return catalog.filter((p) => !needle || normalizeText(`${p.id} ${p.name}`).includes(needle));
    };

    function renderList() {
      const items = visible();
      if (!items.length) {
        opts.listEl.innerHTML = '<div class="apv-empty compact"><p>Không tìm thấy sản phẩm phù hợp.</p></div>';
        return;
      }
      opts.listEl.innerHTML = items.map((p) => {
        const checked = selected.has(p.id);
        return `<label class="apv-product${checked ? ' is-selected' : ''}">
          <input type="checkbox" value="${escapeHtml(p.id)}"${checked ? ' checked' : ''}>
          <span class="apv-product-thumb"><img src="${escapeHtml(p.image || PLACEHOLDER)}" alt="" loading="lazy"></span>
          <span class="apv-product-info"><span class="apv-product-name" title="${escapeHtml(p.name)}">${escapeHtml(p.name)}</span><span class="apv-product-code">Mã SP: <strong>${escapeHtml(p.id)}</strong></span></span>
          <span class="apv-product-price">${escapeHtml(formatMoney(p.price))}</span>
        </label>`;
      }).join('');
    }

    function renderChips() {
      opts.countEl.textContent = `${selected.size} đã chọn`;
      if (!selected.size) { opts.chipsEl.hidden = true; opts.chipsEl.innerHTML = ''; return; }
      opts.chipsEl.hidden = false;
      opts.chipsEl.innerHTML = Array.from(selected).map((id) => {
        const p = catalogById.get(id);
        const name = p ? p.name : `SP #${id}`;
        return `<span class="apv-chip"><span>[${escapeHtml(id)}] ${escapeHtml(name)}</span><button type="button" class="apv-chip-remove" data-remove-id="${escapeHtml(id)}" aria-label="Bỏ chọn ${escapeHtml(name)}">&times;</button></span>`;
      }).join('');
    }

    function changed() { renderChips(); if (opts.onChange) opts.onChange(); }

    function syncRows() {
      opts.listEl.querySelectorAll('input[type="checkbox"]').forEach((cb) => {
        cb.checked = selected.has(cb.value);
        cb.closest('.apv-product').classList.toggle('is-selected', cb.checked);
      });
    }

    opts.listEl.addEventListener('change', (event) => {
      const cb = event.target;
      if (!cb || cb.type !== 'checkbox') return;
      if (cb.checked) selected.add(cb.value); else selected.delete(cb.value);
      cb.closest('.apv-product').classList.toggle('is-selected', cb.checked);
      changed();
    });

    opts.chipsEl.addEventListener('click', (event) => {
      const btn = event.target.closest('[data-remove-id]');
      if (!btn) return;
      selected.delete(btn.dataset.removeId);
      syncRows();
      changed();
      (opts.searchEl || opts.listEl).focus();
    });

    opts.searchEl.addEventListener('input', () => {
      term = opts.searchEl.value;
      renderList();
      const query = term.trim();
      clearTimeout(debounce);
      if (query.length < 2) return;
      debounce = setTimeout(async () => {
        try {
          const res = await request(`index.php?r=admin_ai_idol_search_products&q=${encodeURIComponent(query)}`);
          if (addProducts(res.products) && opts.searchEl.value.trim() === query) { renderList(); renderChips(); }
        } catch (_) { /* the local list stays usable */ }
      }, 300);
    });

    return {
      selected,
      renderList,
      refresh() { renderList(); renderChips(); },
      reset(ids) {
        selected.clear();
        (ids || []).forEach((id) => { if (id != null && String(id) !== '') selected.add(String(id)); });
        term = '';
        opts.searchEl.value = '';
        renderList();
        renderChips();
      },
      selectVisible() { visible().forEach((p) => selected.add(p.id)); syncRows(); changed(); },
      add(ids) { (ids || []).forEach((id) => { if (id != null && String(id) !== '') selected.add(String(id)); }); syncRows(); changed(); },
      clear() { selected.clear(); syncRows(); changed(); },
    };
  }

  // ---- video list

  const grid = $('apvGrid');
  let inflight = null;
  let again = false;

  function refreshVideos() {
    if (inflight) { again = true; return inflight; }
    const button = $('apvRefresh');
    button.disabled = true;
    grid.setAttribute('aria-busy', 'true');
    inflight = (async () => {
      try {
        do {
          again = false;
          const res = await request('index.php?r=admin_ai_idol_product_videos');
          state.videos = Array.isArray(res.videos) ? res.videos : [];
          state.loaded = true;
          if (!$('apvNotice').classList.contains('success')) notice('');
          renderGrid();
        } while (again);
      } catch (err) {
        notice(`Không thể tải danh sách video: ${err.message}`);
        if (!state.loaded) renderGrid();
      } finally {
        inflight = null;
        button.disabled = false;
        grid.setAttribute('aria-busy', 'false');
      }
    })();
    return inflight;
  }

  const INELIGIBLE_MSG = 'Video này là bản livestream có nói giá nên không thể đăng lên trang sản phẩm.';
  const isEligible = (v) => !v || v.product_page_eligible !== false;

  function cardHtml(v) {
    const isReady = v.render_status === 'READY';
    const isPublished = Boolean(v.is_published);
    const linked = Array.isArray(v.associated_product_ids) ? v.associated_product_ids : [];
    const primary = v.primary_product_id || linked[0] || '';
    const rc = v.reward_campaign;
    const vid = escapeHtml(v.video_id);

    const publishedBadge = isPublished
      ? '<span class="apv-badge published"><i class="bi bi-check-circle-fill" aria-hidden="true"></i> Đã xuất bản</span>'
      : '<span class="apv-badge unpublished"><i class="bi bi-dash-circle" aria-hidden="true"></i> Chưa xuất bản</span>';
    const renderBadge = isReady
      ? '<span class="apv-badge ready"><i class="bi bi-check2" aria-hidden="true"></i> Sẵn sàng</span>'
      : '<span class="apv-badge pending"><i class="bi bi-arrow-repeat apv-spin" aria-hidden="true"></i> Đang render</span>';
    const ineligibleBadge = isEligible(v)
      ? ''
      : `<span class="apv-badge pending" title="${escapeHtml(INELIGIBLE_MSG)}"><i class="bi bi-slash-circle" aria-hidden="true"></i> Có nói giá</span>`;
    const autoBadge = isAutoSource(v)
      ? '<span class="apv-badge auto" title="Hệ thống tự đăng video này khi render xong"><i class="bi bi-robot" aria-hidden="true"></i> Tự động</span>'
      : '';
    let rewardBadge = '';
    let rewardBox = '';
    if (rc) {
      rewardBadge = rc.is_active
        ? `<span class="apv-badge reward-active"><i class="bi bi-gift-fill" aria-hidden="true"></i> Voucher: ${escapeHtml(discountText(rc))}</span>`
        : '<span class="apv-badge reward-inactive"><i class="bi bi-gift" aria-hidden="true"></i> Voucher (tạm dừng)</span>';
      if (Number(rc.issued_count || 0) > 0) {
        rewardBox = `<div class="apv-reward-box"><span><i class="bi bi-ticket-perforated" aria-hidden="true"></i> Đã phát thực tế:</span><strong>${escapeHtml(Number(rc.issued_count) || 0)} / ${escapeHtml(Number(rc.total_quota) || 0)}</strong></div>`;
      }
    }
    const title = v.title || 'Syna 3D - Giới thiệu sản phẩm';

    return `<article class="apv-card" data-video-id="${vid}">
      <div class="apv-card-preview">
        <img src="${escapeHtml(v.thumbnail_url || PLACEHOLDER)}" alt="" loading="lazy">
        <span class="apv-card-format">${escapeHtml(v.format || '16:9')}</span>
        <span class="apv-card-duration">${escapeHtml(formatDuration(v.duration))}</span>
      </div>
      <div class="apv-card-body">
        <div class="apv-card-tags">${publishedBadge}${renderBadge}${ineligibleBadge}${autoBadge}${rewardBadge}</div>
        <h3 class="apv-card-title" title="${escapeHtml(title)}">${escapeHtml(title)}</h3>
        <div class="apv-card-campaign"><i class="bi bi-collection-play" aria-hidden="true"></i> <span>${escapeHtml(v.campaign_name || 'Chương trình AI Idol')}</span></div>
        <div class="apv-card-meta">
          <span class="apv-pill"><i class="bi bi-link-45deg" aria-hidden="true"></i> <strong>${linked.length}</strong> sản phẩm liên kết</span>
          <span class="apv-help">Ưu tiên: <strong>${escapeHtml(Number(v.display_priority) || 10)}</strong></span>
        </div>
        ${rewardBox}
      </div>
      <div class="apv-card-footer">
        <button type="button" class="apv-btn secondary" data-apv-action="manage" data-video-id="${vid}"${isReady ? '' : ' disabled title="Video chưa render xong nên chưa thể xuất bản."'}><i class="bi bi-sliders" aria-hidden="true"></i> Quản lý xuất bản</button>
        ${isPublished && isReady ? `<button type="button" class="apv-btn primary" data-apv-action="reward" data-video-id="${vid}" title="Cấu hình voucher xem video"><i class="bi bi-gift" aria-hidden="true"></i> Voucher xem video</button>` : ''}
        ${isPublished && primary ? `<a class="apv-btn secondary apv-btn-icon" href="index.php?r=chitiet&amp;id=${escapeHtml(encodeURIComponent(primary))}" target="_blank" rel="noopener" aria-label="Xem video trên trang chi tiết sản phẩm" title="Xem trên trang chi tiết sản phẩm"><i class="bi bi-box-arrow-up-right" aria-hidden="true"></i></a>` : ''}
      </div>
    </article>`;
  }

  function renderGrid() {
    const counts = countVideos(state.videos);
    $('apvCountAll').textContent = `(${counts.all})`;
    $('apvCountPublished').textContent = `(${counts.published})`;
    $('apvCountUnpublished').textContent = `(${counts.unpublished})`;

    const items = filterVideos(state.videos, state.filter, state.query);
    if (!items.length) {
      const text = !state.loaded
        ? 'Chưa tải được danh sách video. Hãy thử làm mới.'
        : (state.query.trim() ? 'Không tìm thấy video phù hợp từ khóa.'
          : (state.filter === 'published' ? 'Chưa có video nào được xuất bản lên sản phẩm.'
            : (state.filter === 'unpublished' ? 'Mọi video đều đã được xuất bản.' : 'Chưa có video nào được tạo trong AI Idol Studio.')));
      grid.innerHTML = `<div class="apv-empty"><i class="bi bi-film" aria-hidden="true"></i><p>${escapeHtml(text)}</p></div>`;
      return;
    }
    grid.innerHTML = items.map(cardHtml).join('');
  }

  const findVideo = (id) => state.videos.find((v) => v.video_id === id);

  page.querySelectorAll('[data-apv-filter]').forEach((btn) => btn.addEventListener('click', () => {
    page.querySelectorAll('[data-apv-filter]').forEach((b) => { b.classList.remove('is-active'); b.setAttribute('aria-pressed', 'false'); });
    btn.classList.add('is-active');
    btn.setAttribute('aria-pressed', 'true');
    state.filter = btn.dataset.apvFilter || 'all';
    renderGrid();
  }));
  $('apvSearch').addEventListener('input', (event) => { state.query = event.target.value; renderGrid(); });
  $('apvRefresh').addEventListener('click', () => { notice(''); refreshVideos(); });

  grid.addEventListener('click', (event) => {
    if (event.target.closest('a')) return;
    const rewardBtn = event.target.closest('[data-apv-action="reward"]');
    if (rewardBtn) { openRewardModal(rewardBtn.dataset.videoId, rewardBtn); return; }
    const manageBtn = event.target.closest('[data-apv-action="manage"]');
    if (manageBtn) { openPublishModal(manageBtn.dataset.videoId, manageBtn); return; }
    const card = event.target.closest('.apv-card');
    const cardBtn = card && card.querySelector('[data-apv-action="manage"]');
    if (cardBtn && !cardBtn.disabled) openPublishModal(card.dataset.videoId, cardBtn);
  });

  // ---- publish modal

  let pubVideo = null;
  let pubPrimary = '';
  const pubPicker = createPicker({
    listEl: $('apvPubList'), chipsEl: $('apvPubChips'), countEl: $('apvPubCount'), searchEl: $('apvPubSearch'),
    onChange: renderPrimary,
  });

  function renderPrimary() {
    const select = $('apvPubPrimary');
    pubPrimary = reconcilePrimary(pubPicker.selected, select.value || pubPrimary);
    if (!pubPicker.selected.size) {
      select.innerHTML = '<option value="">-- Chưa chọn sản phẩm liên kết --</option>';
      return;
    }
    select.innerHTML = Array.from(pubPicker.selected).map((id) => {
      const p = catalogById.get(id);
      return `<option value="${escapeHtml(id)}"${id === pubPrimary ? ' selected' : ''}>[${escapeHtml(id)}] ${escapeHtml(p ? p.name : `SP #${id}`)}</option>`;
    }).join('');
  }

  function openPublishModal(videoId, opener) {
    const video = findVideo(videoId);
    if (!video) return;
    pubVideo = video;
    showBox($('apvPubAlert'), '');
    $('apvPubTitle').textContent = `Quản lý xuất bản: ${video.title || 'Video AI Idol'}`;
    $('apvPubVideoTitle').value = video.title || 'Syna 3D - Giới thiệu sản phẩm';
    $('apvPubCampaign').textContent = video.campaign_name || 'Chương trình AI Idol';
    $('apvPubDuration').textContent = formatDuration(video.duration);
    $('apvPubPriority').value = Number(video.display_priority) || 10;
    const poster = $('apvPubPoster');
    poster.dataset.fallback = '';
    poster.src = video.thumbnail_url || PLACEHOLDER;
    const status = $('apvPubStatus');
    status.className = `apv-badge ${video.is_published ? 'published' : 'unpublished'}`;
    status.innerHTML = video.is_published
      ? '<i class="bi bi-check-circle-fill" aria-hidden="true"></i> Đang xuất bản trên trang sản phẩm'
      : '<i class="bi bi-dash-circle" aria-hidden="true"></i> Chưa xuất bản (nháp)';
    $('apvPubAuto').hidden = !isAutoSource(video);
    $('apvPubUnpublish').hidden = !video.is_published;
    const eligible = isEligible(video);
    $('apvPubSubmit').disabled = !eligible;
    $('apvPubSubmit').title = eligible ? '' : INELIGIBLE_MSG;
    if (!eligible) showBox($('apvPubAlert'), INELIGIBLE_MSG);

    const ids = (video.associated_product_ids || []).map(String).filter(Boolean);
    if (!ids.length && video.default_product_id) ids.push(String(video.default_product_id));
    pubPrimary = String(video.primary_product_id || '');
    pubPicker.reset(ids);
    $('apvPubPrimary').value = '';
    renderPrimary();
    openModal('pub', opener);
  }
  modals.pub.onClose = () => { pubVideo = null; };

  $('apvPubSelectAll').addEventListener('click', () => pubPicker.selectVisible());
  $('apvPubClearAll').addEventListener('click', () => pubPicker.clear());
  $('apvPubPrimary').addEventListener('change', (event) => { pubPrimary = event.target.value; });

  $('apvPubSubmit').addEventListener('click', async () => {
    const video = pubVideo;
    if (!video) return;
    if (!isEligible(video)) { showBox($('apvPubAlert'), INELIGIBLE_MSG); return; }
    const alertBox = $('apvPubAlert');
    const ids = Array.from(pubPicker.selected);
    if (!ids.length) { showBox(alertBox, 'Vui lòng chọn ít nhất một sản phẩm liên kết để xuất bản video.'); return; }
    const priority = parseInt($('apvPubPriority').value || '10', 10);
    const button = $('apvPubSubmit');
    setBusy(button, true, `${SPIN}Đang xuất bản…`);
    showBox(alertBox, '');
    try {
      const res = await post('admin_ai_idol_publish_video', {
        video_id: video.video_id,
        associated_product_ids: ids,
        primary_product_id: $('apvPubPrimary').value || ids[0],
        title: $('apvPubVideoTitle').value.trim() || video.title || 'Syna 3D - Giới thiệu sản phẩm',
        display_priority: Number.isNaN(priority) ? 10 : priority,
        is_published: true,
      });
      notice(res.message || 'Đã xuất bản video lên trang sản phẩm thành công.', 'success');
      await refreshVideos();
      if (pubVideo === video) closeModal('pub');
    } catch (err) {
      showBox(alertBox, `Lỗi xuất bản: ${err.message}`);
    } finally {
      setBusy(button, false);
    }
  });

  $('apvPubUnpublish').addEventListener('click', async () => {
    const video = pubVideo;
    if (!video) return;
    const alertBox = $('apvPubAlert');
    const button = $('apvPubUnpublish');
    setBusy(button, true, `${SPIN}Đang gỡ…`);
    showBox(alertBox, '');
    try {
      const res = await post('admin_ai_idol_toggle_publish', {
        publication_id: video.publication_id || '',
        video_id: video.video_id,
        is_published: false,
      });
      notice(res.message || 'Đã gỡ video khỏi trang sản phẩm.', 'success');
      await refreshVideos();
      if (pubVideo === video) closeModal('pub');
    } catch (err) {
      showBox(alertBox, `Lỗi hủy xuất bản: ${err.message}`);
    } finally {
      setBusy(button, false);
    }
  });

  // ---- voucher modal

  let rewardVideo = null;
  let rewardCampaign = null;
  let rewardToken = 0;
  const rewardPicker = createPicker({
    listEl: $('apvRewardList'), chipsEl: $('apvRewardChips'), countEl: $('apvRewardCount'), searchEl: $('apvRewardSearch'),
  });

  function updateDiscountType() {
    const percent = $('apvRewardType').value === 'percentage';
    const input = $('apvRewardValue');
    $('apvRewardValueLabel').textContent = percent ? 'Mức giảm (%)' : 'Mức giảm (VNĐ)';
    $('apvRewardValueHelp').textContent = percent ? 'Nhập từ 1 đến 100%' : 'Nhập số tiền giảm cố định (VNĐ)';
    input.min = percent ? '1' : '1000';
    input.step = percent ? '1' : '5000';
    if (percent) input.max = '100'; else input.removeAttribute('max');
    $('apvRewardMaxWrap').hidden = !percent;
  }
  $('apvRewardType').addEventListener('change', updateDiscountType);

  function paintToggle(active) {
    const btn = $('apvRewardToggle');
    btn.hidden = false;
    btn.className = `apv-btn ${active ? 'danger-outline' : 'secondary'}`;
    btn.innerHTML = active
      ? '<i class="bi bi-pause-circle" aria-hidden="true"></i> Tắt chiến dịch'
      : '<i class="bi bi-play-circle" aria-hidden="true"></i> Kích hoạt chiến dịch';
  }

  function paintStatusBadge(active) {
    const badge = $('apvRewardStatsBadge');
    badge.className = `apv-badge ${active ? 'published' : 'unpublished'}`;
    badge.innerHTML = active
      ? '<i class="bi bi-check-circle-fill" aria-hidden="true"></i> Đang hoạt động'
      : '<i class="bi bi-pause-circle" aria-hidden="true"></i> Đang tạm dừng';
  }

  function fillRewardForm(c) {
    $('apvRewardCampaignId').value = c.campaign_id || '';
    $('apvRewardName').value = c.campaign_name || '';
    $('apvRewardType').value = c.discount_type || 'percentage';
    $('apvRewardValue').value = c.discount_value || 10;
    $('apvRewardMax').value = c.max_discount_amount || '';
    $('apvRewardMin').value = c.min_order_amount || 0;
    if (c.start_date) $('apvRewardStart').value = toLocalInput(c.start_date);
    if (c.end_date) $('apvRewardEnd').value = toLocalInput(c.end_date);
    $('apvRewardQuota').value = c.total_quota || 100;
    $('apvRewardUserLimit').value = c.user_limit || 1;
    $('apvRewardStackFlash').checked = Boolean(c.stack_with_flash_sale);
    $('apvRewardStackOther').checked = Boolean(c.stack_with_other_vouchers);
    $('apvRewardActive').checked = Boolean(c.is_active);
    updateDiscountType();
    if (Array.isArray(c.applicable_product_ids) && c.applicable_product_ids.length) rewardPicker.reset(c.applicable_product_ids);

    const issued = Number(c.issued_count || 0);
    const quota = Number(c.total_quota || 0);
    $('apvRewardStats').hidden = false;
    $('apvStatIssued').textContent = issued;
    $('apvStatQuota').textContent = quota;
    $('apvStatRemaining').textContent = Math.max(0, quota - issued);
    $('apvStatUserLimit').textContent = c.user_limit || 1;
    $('apvStatMeta').innerHTML = `Mã: <code>${escapeHtml(c.campaign_id)}</code> · Cập nhật: ${escapeHtml(c.updated_at || c.created_at || '—')}`;
    paintStatusBadge(Boolean(c.is_active));
    paintToggle(Boolean(c.is_active));
  }

  async function openRewardModal(videoId, opener) {
    const video = findVideo(videoId);
    if (!video) return;
    rewardVideo = video;
    rewardCampaign = null;
    const token = ++rewardToken;
    showBox($('apvRewardAlert'), '');

    const poster = $('apvRewardPoster');
    poster.dataset.fallback = '';
    poster.src = video.thumbnail_url || PLACEHOLDER;
    $('apvRewardDuration').textContent = formatDuration(video.duration);
    $('apvRewardCampaign').textContent = video.campaign_name || 'Chương trình AI Idol';
    $('apvRewardVideoTitle').textContent = video.title || 'Syna 3D - Giới thiệu sản phẩm';
    $('apvRewardVideoId').textContent = video.video_id || '—';
    $('apvRewardVideoIdField').value = video.video_id || '';
    $('apvRewardPublicationId').value = video.publication_id || '';

    $('apvRewardCampaignId').value = '';
    $('apvRewardName').value = `Voucher xem video: ${video.title || 'Syna 3D'}`;
    $('apvRewardType').value = 'percentage';
    $('apvRewardValue').value = 10;
    $('apvRewardMax').value = '';
    $('apvRewardMin').value = 0;
    $('apvRewardQuota').value = 100;
    $('apvRewardUserLimit').value = 1;
    $('apvRewardStackFlash').checked = false;
    $('apvRewardStackOther').checked = false;
    $('apvRewardActive').checked = true;
    const now = new Date();
    $('apvRewardStart').value = toLocalInput(now);
    $('apvRewardEnd').value = toLocalInput(new Date(now.getTime() + 7 * 24 * 3600 * 1000));
    updateDiscountType();

    const ids = (video.associated_product_ids || []).map(String).filter(Boolean);
    if (!ids.length && video.default_product_id) ids.push(String(video.default_product_id));
    rewardPicker.reset(ids);

    $('apvRewardStats').hidden = true;
    $('apvRewardToggle').hidden = true;
    // Saving before the current campaign is known could create a second campaign for the video.
    $('apvRewardSubmit').disabled = true;
    openModal('reward', opener);

    try {
      const res = await request(`index.php?r=admin_ai_idol_reward_campaign&video_id=${encodeURIComponent(videoId)}`);
      if (token !== rewardToken || rewardVideo !== video) return;
      if (res.campaign) { rewardCampaign = res.campaign; fillRewardForm(res.campaign); }
      $('apvRewardSubmit').disabled = false;
    } catch (err) {
      if (token !== rewardToken || rewardVideo !== video) return;
      showBox($('apvRewardAlert'), `Không tải được cấu hình voucher hiện tại (${err.message}). Hãy đóng và mở lại để tránh ghi đè nhầm.`);
    }
  }
  modals.reward.onClose = () => { rewardVideo = null; rewardCampaign = null; rewardToken += 1; };

  $('apvRewardSelectLinked').addEventListener('click', () => {
    if (rewardVideo) rewardPicker.add(rewardVideo.associated_product_ids || []);
  });
  $('apvRewardClearAll').addEventListener('click', () => rewardPicker.clear());

  $('apvRewardSubmit').addEventListener('click', async () => {
    const video = rewardVideo;
    if (!video) return;
    const alertBox = $('apvRewardAlert');
    showBox(alertBox, '');
    const problem = validateReward({
      name: $('apvRewardName').value,
      type: $('apvRewardType').value,
      value: $('apvRewardValue').value,
      start: $('apvRewardStart').value,
      end: $('apvRewardEnd').value,
      quota: $('apvRewardQuota').value,
      selectedCount: rewardPicker.selected.size,
    }, rewardCampaign);
    if (problem) { showBox(alertBox, problem); return; }

    const maxValue = $('apvRewardMax').value;
    const button = $('apvRewardSubmit');
    setBusy(button, true, `${SPIN}Đang lưu…`);
    try {
      const res = await post('admin_ai_idol_save_reward_campaign', {
        campaign_id: $('apvRewardCampaignId').value.trim(),
        video_id: $('apvRewardVideoIdField').value.trim(),
        publication_id: $('apvRewardPublicationId').value.trim(),
        campaign_name: $('apvRewardName').value.trim(),
        discount_type: $('apvRewardType').value,
        discount_value: parseFloat($('apvRewardValue').value) || 0,
        max_discount_amount: maxValue !== '' ? parseInt(maxValue, 10) : null,
        min_order_amount: parseInt($('apvRewardMin').value, 10) || 0,
        start_date: $('apvRewardStart').value,
        end_date: $('apvRewardEnd').value,
        total_quota: parseInt($('apvRewardQuota').value, 10) || 0,
        user_limit: parseInt($('apvRewardUserLimit').value, 10) || 1,
        applicable_product_ids: Array.from(rewardPicker.selected),
        stack_with_flash_sale: $('apvRewardStackFlash').checked,
        stack_with_other_vouchers: $('apvRewardStackOther').checked,
        is_active: $('apvRewardActive').checked,
      });
      notice(res.message || 'Đã lưu cấu hình voucher thành công.', 'success');
      await refreshVideos();
      if (rewardVideo === video) closeModal('reward');
    } catch (err) {
      showBox(alertBox, `Lỗi lưu cấu hình: ${err.message}`);
    } finally {
      setBusy(button, false);
    }
  });

  $('apvRewardToggle').addEventListener('click', async () => {
    const campaign = rewardCampaign;
    if (!campaign) return;
    const alertBox = $('apvRewardAlert');
    const button = $('apvRewardToggle');
    const next = !campaign.is_active;
    setBusy(button, true, `${SPIN}Đang xử lý…`);
    showBox(alertBox, '');
    try {
      const res = await post('admin_ai_idol_toggle_reward_campaign', { campaign_id: campaign.campaign_id, is_active: next });
      campaign.is_active = next;
      $('apvRewardActive').checked = next;
      setBusy(button, false);
      paintToggle(next);
      paintStatusBadge(next);
      showBox(alertBox, res.message || 'Đã thay đổi trạng thái chiến dịch.', 'success');
      await refreshVideos();
    } catch (err) {
      setBusy(button, false);
      showBox(alertBox, `Lỗi cập nhật: ${err.message}`);
    }
  });

  // ---- start

  updateDiscountType();
  refreshVideos().then(() => {
    const wanted = (new URLSearchParams(window.location.search).get('video_id') || '').trim();
    if (!wanted) return;
    const video = findVideo(wanted);
    if (video) openPublishModal(wanted, null);
    else if (state.loaded) notice(`Không tìm thấy video có mã ${wanted}.`);
  });

  return pure;
});
