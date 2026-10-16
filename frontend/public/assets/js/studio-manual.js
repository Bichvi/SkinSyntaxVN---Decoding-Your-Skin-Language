/*
 * AI Idol Studio - tab Thủ công (wizard 4 bước).
 *
 * 1 Sản phẩm -> 2 Mục đích & lịch -> 3 Nội dung & nhân vật -> 4 Xem lại & tạo.
 * The markup lives in frontend/views/admin/ai_idol/index.php (#manualFlow). This file filters products
 * through admin_ai_idol_products_query, keeps the selection across filterings, validates each step, uploads
 * the character/background files and creates the campaign through admin_ai_idol_create_campaign.
 *
 * Talks to the rest of the page through StudioCore:
 *   listens  studio:health {data}   which channels have RTMP configured (warning only, never blocks)
 *   emits    studio:refresh {}      after a campaign was created
 *            studio:open-item {kind: 'campaign', id}
 *
 * The DOM-free helpers are exported for Node (module.exports) so studio-manual.test.js can reach them.
 */
(function (global) {
  'use strict';

  const hasModule = typeof module === 'object' && module !== null && Boolean(module.exports);
  const nodeRequire = hasModule && typeof require === 'function' ? require : null;
  const core = global.StudioCore || (nodeRequire ? nodeRequire('./studio-core.js') : null);
  const logic = global.StudioLogic || (nodeRequire ? nodeRequire('./studio-logic.js') : null);
  if (!core || !logic) return; // loaded before studio-logic.js / studio-core.js: nothing to wire

  const { escapeHtml, normalize, platformName } = core;

  // ------------------------------------------------------------------ constants

  const TIME_ZONE = 'Asia/Ho_Chi_Minh'; // a datetime-local value has no zone: the backend reads it in this one
  const QUERY_URL = 'index.php?r=admin_ai_idol_products_query';
  const CREATE_URL = 'index.php?r=admin_ai_idol_create_campaign';
  const UPLOAD_URL = 'index.php?r=admin_ai_idol_upload_asset';
  const QUERY_LIMIT = 100; // the API cap; Top N only ticks the first rows, it never shortens the list
  const DEBOUNCE_MS = 300;
  const QUERY_TIMEOUT_MS = 10000;
  const MIN_DURATION = 1;
  const MAX_DURATION = 720;
  const PURPOSES = ['product_video', 'live_and_product', 'live_only'];
  const EXTERNAL_PLATFORMS = ['youtube', 'facebook'];
  const STEP_COUNT = 4;
  const STEP_NAMES = ['Sản phẩm', 'Mục đích & lịch phát', 'Nội dung & nhân vật', 'Xem lại & tạo'];
  const MB = 1024 * 1024;

  // What execute_query counts in `missing_data` (keys are internal identifiers), in the words of the page.
  const MISSING_LABELS = Object.freeze({
    expiry_or_batch_quantity: 'dữ liệu lô và hạn dùng',
    stock: 'số liệu tồn kho',
    lifetime_sales: 'doanh số toàn kỳ',
    sales_snapshot: 'số liệu bán hàng theo kỳ',
    invalid_conversion_cohort: 'dữ liệu chuyển đổi hợp lệ',
    growth_baseline: 'mốc so sánh tăng trưởng',
    price: 'giá bán',
    comparison_sample: 'mẫu so sánh',
    brand: 'thương hiệu',
  });

  // ------------------------------------------------------------ pure helpers (no DOM)

  const text = (value) => (typeof value === 'string' ? value.trim() : '');
  const numberText = (value, digits = 2) => value.toLocaleString('vi-VN', { maximumFractionDigits: digits });

  // The body of admin_ai_idol_products_query. Flask answers 400 to a warehouse mode without a code, so both
  // keys stay out until the code is typed. `q` is never sent: the quick search only narrows the rows we hold.
  function buildQueryBody(filters) {
    const f = filters && typeof filters === 'object' ? filters : {};
    const stock = text(f.stock);
    const body = {
      cat_l1: text(f.cat1),
      cat_l2: text(f.cat2),
      cat_l3: text(f.cat3),
      expiry: text(f.expiry),
      stock,
      sales: text(f.sales),
      brand: text(f.brand),
      brand_cap: Boolean(f.brandCap),
      enough_stock: stock === 'out_of_stock' ? false : Boolean(f.enoughStock), // nothing in stock cannot be "enough"
      limit: QUERY_LIMIT,
    };
    const mode = text(f.warehouseMode);
    const code = text(f.scopeCode);
    if (code && (mode === 'warehouse' || mode === 'batch')) {
      body.warehouse_mode = mode;
      body.scope_code = code;
    }
    return body;
  }

  // The picker tree (window.__IDOL_CATALOG__) is {key: root}; a root is {id, label, count, children: {id: node}}.
  // categoryOptions turns one `children` map into select options; the lookups below go by the group the user
  // picked, which is the root's numeric id (or its key: 'face' still works).
  function categoryOptions(children) {
    const options = [];
    Object.entries(children && typeof children === 'object' ? children : {}).forEach(([key, node]) => {
      if (!node || typeof node !== 'object') return;
      options.push({
        value: String(node.id ?? key),
        label: String(node.label ?? node.name ?? key),
        count: Number(node.count),
        children: node.children,
      });
    });
    return options;
  }

  function rootCategory(catalog, value) {
    const wanted = text(String(value ?? ''));
    if (!wanted || !catalog || typeof catalog !== 'object') return null;
    const hit = Object.entries(catalog).find(([key, node]) => node && typeof node === 'object' && (key === wanted || String(node.id ?? '') === wanted));
    return hit ? hit[1] : null;
  }

  function level2Options(catalog, rootValue) {
    const root = rootCategory(catalog, rootValue);
    return root ? categoryOptions(root.children) : [];
  }

  function level3Options(catalog, rootValue, level2Value) {
    const parent = level2Options(catalog, rootValue).find((option) => option.value === text(String(level2Value ?? '')));
    return parent ? categoryOptions(parent.children) : [];
  }

  // One API product -> the row we keep. null = unusable (no id).
  function normalizeProduct(raw) {
    const r = raw && typeof raw === 'object' ? raw : {};
    const id = String(r.product_id ?? r.id ?? '').trim();
    if (!id) return null;
    const stockValue = r.stock === null || r.stock === undefined || r.stock === '' ? NaN : Number(r.stock);
    return {
      id,
      name: text(r.name) || `Sản phẩm ${id}`,
      image: text(r.image),
      stock: Number.isFinite(stockValue) ? stockValue : null,
      metric: formatMetric(r.metric_label, r.metric_value),
    };
  }

  // "Số lượng bán giảm dần: 42" - the ranking figure of a row. Dates arrive as YYYY-MM-DD.
  function formatMetric(label, value) {
    const name = text(label);
    let shown = '';
    if (typeof value === 'number' && Number.isFinite(value)) {
      shown = numberText(value);
    } else if (typeof value === 'string' && value.trim()) {
      const date = /^(\d{4})-(\d{2})-(\d{2})/.exec(value.trim());
      shown = date ? `${date[3]}/${date[2]}/${date[1]}` : value.trim();
    }
    if (name && shown) return `${name}: ${shown}`;
    return name || shown;
  }

  // missing_data = {internal_key: number of products}. Never shows the key itself.
  function missingDataNotes(missing) {
    if (!missing || typeof missing !== 'object' || Array.isArray(missing)) return [];
    return Object.entries(missing)
      .filter(([, count]) => Number(count) > 0)
      .map(([key, count]) => `Chưa có ${MISSING_LABELS[key] || 'một số dữ liệu'} cho ${numberText(Number(count), 0)} sản phẩm.`);
  }

  function stockTone(stock) {
    if (stock === null) return 'unknown';
    if (stock <= 0) return 'out';
    return stock <= 10 ? 'low' : 'ok';
  }

  function stockText(stock) {
    if (stock === null) return 'Tồn kho: chưa rõ';
    return stock <= 0 ? 'Hết hàng' : `Tồn kho: ${numberText(stock, 0)}`;
  }

  // Every string that came from the API goes through escapeHtml.
  function productRowMarkup(product, checked) {
    const id = escapeHtml(product.id);
    const image = product.image
      ? `<img src="${escapeHtml(product.image)}" alt="" loading="lazy" width="42" height="48">`
      : '';
    const metric = product.metric ? `<span class="wz-metric">${escapeHtml(product.metric)}</span>` : '';
    return `<li class="wz-product${checked ? ' is-selected' : ''}" data-id="${id}"><label>`
      + `<input type="checkbox" value="${id}" data-wz-product${checked ? ' checked' : ''}>`
      + `<span class="wz-thumb">${image}</span>`
      + `<span class="wz-copy"><span class="wz-name">${escapeHtml(product.name)}</span>`
      + `<small class="wz-meta"><span>Mã #${id}</span>`
      + `<span class="wz-stock is-${stockTone(product.stock)}">${escapeHtml(stockText(product.stock))}</span>${metric}</small></span>`
      + '</label></li>';
  }

  function chipsMarkup(items) {
    return (Array.isArray(items) ? items : []).map((item) => {
      const name = escapeHtml(item.name || item.id);
      return `<button type="button" class="wz-chip" data-wz-remove="${escapeHtml(item.id)}" aria-label="Bỏ chọn ${name}"><span>${name}</span><i class="bi bi-x" aria-hidden="true"></i></button>`;
    }).join('');
  }

  // The products ticked so far. They live across filterings (a Map keyed by product id). Top N ticks the first
  // N rows only while the user has not ticked anything by hand for the current filtering, and the next filtering
  // swaps the automatic ticks that are still untouched for its own. The first tick or untick by hand adopts the
  // automatic picks that remain: the user has seen the list and kept them, so a later filtering never drops them.
  function createSelection() {
    const items = new Map();
    const automatic = new Set(); // ids ticked by Top N before the user touched the list
    let touched = false; // the user ticked or unticked something since the current filtering began
    const keyOf = (value) => String(value ?? '').trim();
    const itemOf = (value) => ({ id: keyOf(value.id), name: text(value.name) || keyOf(value.id), image: text(value.image) });
    const store = {
      get size() { return items.size; },
      has: (id) => items.has(keyOf(id)),
      list: () => [...items.values()],
      startFilter() { touched = false; },
      set(value, on) { // a tick or untick by hand
        const id = keyOf(value && value.id);
        if (!id) return;
        touched = true;
        automatic.clear(); // adopt the remaining automatic picks
        if (on) items.set(id, itemOf(value));
        else items.delete(id);
      },
      setMany(list, on) { (Array.isArray(list) ? list : []).forEach((value) => store.set(value, on)); },
      clear() { touched = true; automatic.clear(); items.clear(); },
      applyTop(rows, count) { // false = left alone because the user already ticked by hand
        if (touched) return false;
        automatic.forEach((id) => items.delete(id));
        automatic.clear();
        const n = Math.floor(Number(count));
        if (!(n > 0)) return true;
        (Array.isArray(rows) ? rows : []).slice(0, n).forEach((row) => {
          const id = keyOf(row && row.id);
          if (!id || items.has(id)) return; // already there (ticked by hand earlier): it stays manual
          items.set(id, itemOf(row));
          automatic.add(id);
        });
        return true;
      },
      reset() { items.clear(); automatic.clear(); touched = false; },
    };
    return store;
  }

  // "2026-10-15T20:00" for the given moment as seen in Asia/Ho_Chi_Minh.
  function formatInZone(date, timeZone = TIME_ZONE) {
    const moment = date instanceof Date ? date : new Date(date);
    if (Number.isNaN(moment.getTime())) return '';
    const parts = {};
    new Intl.DateTimeFormat('en-GB', {
      timeZone, year: 'numeric', month: '2-digit', day: '2-digit', hour: '2-digit', minute: '2-digit', hourCycle: 'h23',
    }).formatToParts(moment).forEach((part) => { parts[part.type] = part.value; });
    return `${parts.year}-${parts.month}-${parts.day}T${parts.hour}:${parts.minute}`;
  }

  // Both values are zone-less wall-clock strings of the same shape, so they compare as text.
  function isFutureSlot(value, nowValue) {
    const slot = text(value);
    return /^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}/.test(slot) && slot.slice(0, 16) > String(nowValue || '').slice(0, 16);
  }

  // "2026-10-15T20:00" -> "20:00 15/10/2026" (string surgery on purpose: no Date, no zone shift).
  function formatSlot(value) {
    const match = /^(\d{4})-(\d{2})-(\d{2})T(\d{2}):(\d{2})/.exec(text(value));
    return match ? `${match[4]}:${match[5]} ${match[3]}/${match[2]}/${match[1]}` : '';
  }

  // Step 2. {ok:true} or {ok:false, field, message}; `field` tells the caller what to focus.
  function validateSchedule(input, nowValue) {
    const s = input && typeof input === 'object' ? input : {};
    if (!PURPOSES.includes(s.purpose) || s.purpose === 'product_video') return { ok: true };
    if (!isFutureSlot(s.at, nowValue)) return { ok: false, field: 'at', message: 'Chọn giờ phát nằm trong tương lai.' };
    const duration = Number(s.duration);
    if (!Number.isInteger(duration) || duration < MIN_DURATION || duration > MAX_DURATION) {
      return { ok: false, field: 'duration', message: `Thời lượng phát từ ${MIN_DURATION} đến ${MAX_DURATION} phút.` };
    }
    if (!Array.isArray(s.platforms) || !s.platforms.length) {
      return { ok: false, field: 'platforms', message: 'Chọn ít nhất một nơi phát.' };
    }
    return { ok: true };
  }

  // Channels with RTMP configured, from the health payload; null = unknown (then nobody is warned).
  function rtmpPlatformsOf(data) {
    const d = data && typeof data === 'object' ? data : null;
    const rtmp = d && ((d.services && d.services.rtmp) || d.rtmp);
    return rtmp && Array.isArray(rtmp.platforms) ? rtmp.platforms.filter((item) => typeof item === 'string') : null;
  }

  function rtmpWarning(chosen, configured) {
    if (!Array.isArray(configured)) return '';
    const missing = (Array.isArray(chosen) ? chosen : []).filter((item) => EXTERNAL_PLATFORMS.includes(item) && !configured.includes(item));
    return missing.length ? `Chưa cấu hình RTMP cho ${missing.map(platformName).join(', ')}.` : '';
  }

  // Styles the purpose allows, in the order they were ticked, no duplicates.
  function pruneStyles(styles, purpose) {
    const allowed = logic.allowedStyles(purpose);
    const kept = [];
    (Array.isArray(styles) ? styles : []).forEach((key) => {
      if (allowed.includes(key) && !kept.includes(key)) kept.push(key);
    });
    return kept;
  }

  // The create-campaign body: the payload of the old wizard plus configuration.narration_styles.
  const APPROVAL_MODES = ['AUTO', 'MANUAL'];

  function buildCampaignPayload(input) {
    const i = input && typeof input === 'object' ? input : {};
    const purpose = PURPOSES.includes(i.purpose) ? i.purpose : 'product_video';
    const live = purpose !== 'product_video';
    const syna = i.character !== 'custom';
    return {
      name: text(i.name),
      product_ids: Array.isArray(i.productIds) ? i.productIds.slice() : [],
      purpose,
      scheduled_at: live ? i.scheduledAt : null,
      platforms: live ? (Array.isArray(i.platforms) ? i.platforms.slice() : []) : [],
      duration_minutes: live ? Number(i.duration) : 60,
      content_mode: text(i.contentMode) || 'Product Intro',
      configuration: {
        format: syna || i.format !== '9:16' ? '16:9' : '9:16', // Syna 3D is landscape only
        length_mode: live ? 'Short (30-60s)' : 'Long (90-150s)',
        avatar_mode: syna ? 'syna_3d' : i.avatarIsVideo ? 'motion_video' : 'photo',
        avatar_asset: syna ? '' : String(i.avatarAsset ?? ''),
        background_asset: String(i.backgroundAsset ?? ''),
        narration_styles: pruneStyles(i.styles, purpose),
        ...(APPROVAL_MODES.includes(i.approvalMode) ? { approval_mode: i.approvalMode } : {}),
      },
    };
  }

  // The backend answers in English for a few cases an employee can reach from this form.
  function friendlyError(message) {
    const raw = String(message ?? '').trim();
    let match = /^Schedule needs at least ([\d.]+) minutes to prepare (\d+) product/.exec(raw);
    if (match) return `Giờ phát cần cách hiện tại ít nhất ${Number(match[1])} phút để chuẩn bị ${match[2]} sản phẩm.`;
    match = /^A campaign supports at most (\d+) products/.exec(raw);
    if (match) return `Một chương trình có tối đa ${match[1]} sản phẩm. Hãy bỏ bớt sản phẩm.`;
    if (/^Select at least one product/.test(raw)) return 'Chọn ít nhất một sản phẩm.';
    if (/^Select at least one supported platform/.test(raw)) return 'Chọn ít nhất một nơi phát.';
    return raw;
  }

  // Step 4 summary blocks, one per earlier step, each with a "Sửa" button that jumps back to it.
  function reviewMarkup(view) {
    const v = view && typeof view === 'object' ? view : {};
    const products = Array.isArray(v.products) ? v.products : [];
    const row = (label, valueHtml) => `<div><dt>${escapeHtml(label)}</dt><dd>${valueHtml}</dd></div>`;
    const block = (step, title, body) => `<section class="wz-review-block" aria-label="${escapeHtml(title)}">`
      + `<div class="wz-review-head"><h4>${escapeHtml(title)}</h4>`
      + `<button type="button" class="text-button" data-wz-edit="${step}">Sửa<span class="visually-hidden"> ${escapeHtml(title.toLowerCase())}</span></button></div>${body}</section>`;

    const names = products.map((p) => `<li>${escapeHtml(p.name || p.id)}</li>`).join('');
    const productBody = `<p class="wz-review-count">${numberText(products.length, 0)} sản phẩm</p><ul class="wz-review-list">${names}</ul>`;

    const live = Boolean(v.live);
    const planRows = [row('Mục đích', escapeHtml(v.purposeLabel))];
    if (live) {
      planRows.push(row('Giờ phát', escapeHtml(formatSlot(v.slot) || 'Chưa chọn')));
      planRows.push(row('Thời lượng', escapeHtml(`${v.duration} phút`)));
      planRows.push(row('Kênh phát', escapeHtml((v.channels || []).join(', ') || 'Chưa chọn')));
    }
    planRows.push(row('Duyệt kịch bản', escapeHtml(live
      ? 'Bạn duyệt kịch bản trước khi render.'
      : 'Tự duyệt sau kiểm duyệt, rồi tự đăng lên trang sản phẩm.')));

    const styleLabels = Array.isArray(v.styleLabels) ? v.styleLabels : [];
    const contentRows = [
      row('Tên chương trình', escapeHtml(text(v.name) || 'Tự đặt theo ngày')),
      row('Cách kể', escapeHtml(v.contentModeLabel)),
      row('Phong cách', escapeHtml(styleLabels.join(', ') || 'Không chọn')),
      row('Nhân vật', escapeHtml(v.custom ? `Nhân vật riêng${v.avatarName ? ` (${v.avatarName})` : ''}` : 'Syna 3D')),
      row('Khung hình', escapeHtml(v.format)),
      row('Phông nền', escapeHtml(v.backgroundName || 'Không dùng')),
    ];

    return block(1, 'Sản phẩm', productBody)
      + block(2, 'Mục đích & lịch phát', `<dl>${planRows.join('')}</dl>`)
      + block(3, 'Nội dung & nhân vật', `<dl>${contentRows.join('')}</dl>`);
  }

  const helpers = {
    buildQueryBody, normalizeProduct, formatMetric, missingDataNotes, productRowMarkup, chipsMarkup,
    createSelection, formatInZone, isFutureSlot, formatSlot, validateSchedule, rtmpPlatformsOf, rtmpWarning,
    pruneStyles, buildCampaignPayload, friendlyError, reviewMarkup, categoryOptions, rootCategory, level2Options, level3Options,
  };
  if (hasModule) module.exports = helpers;

  // ------------------------------------------------------------------ browser only

  const { $, api, notify, emit, on } = core;
  const panel = typeof $ === 'function' ? $('manualFlow') : null;
  const form = typeof $ === 'function' ? $('manualForm') : null;
  if (!panel || !form) return;

  // ---------------------------------------------------------------- element refs

  const stepButtons = [...panel.querySelectorAll('[data-wz-step]')];
  const stepPanels = [1, 2, 3, 4].map((n) => $(`manualStep${n}`));
  const stepTitles = [1, 2, 3, 4].map((n) => $(`manualStepTitle${n}`));
  const messageEl = $('manualMessage');
  const counterEl = $('manualStepCounter');
  const summaryEl = $('manualSummary');
  const prevButton = $('manualPrev');
  const nextButton = $('manualNext');
  const createButtons = [$('manualCreateAuto'), $('manualCreateReview')];

  const cat1 = $('manualCategoryLevel1');
  const cat2 = $('manualCategoryLevel2');
  const cat3 = $('manualCategoryLevel3');
  const warehouseMode = $('manualWarehouseMode');
  const warehouseField = $('manualWarehouseValue');
  const warehouseLabel = $('manualWarehouseLabel');
  const warehouseInput = $('manualWarehouse');
  const quantitySelect = $('manualQuantity');
  const brandInput = $('manualBrand');
  const enoughStock = $('manualEnoughStock');

  const searchInput = $('manualSearch');
  const selectAllButton = $('manualSelectAll');
  const deselectButton = $('manualDeselect');
  const selectedCountEl = $('manualSelectedCount');
  const resultsEl = $('manualResults');
  const statusEl = $('manualResultStatus');
  const notesEl = $('manualResultNotes');
  const errorEl = $('manualResultError');
  const errorTextEl = $('manualResultErrorText');
  const retryButton = $('manualRetry');
  const listEl = $('manualProductList');
  const chipsEl = $('manualChips');

  const liveFields = $('manualLiveFields');
  const scheduledAt = $('manualScheduledAt');
  const durationInput = $('manualDuration');
  const prepNote = $('manualPrepNote');
  const rtmpWarnEl = $('manualRtmpWarn');

  const nameInput = $('manualName');
  const contentModeSelect = $('manualContentMode');
  const styleGrid = $('manualStyleGrid');
  const styleNote = $('manualStyleNote');
  const characterSelect = $('manualCharacter');
  const formatSelect = $('manualFormat');
  const avatarInput = $('manualAvatarFile');
  const avatarName = $('manualAvatarFilename');
  const avatarImage = $('manualAvatarImage');
  const avatarVideo = $('manualAvatarVideo');
  const avatarNote = $('manualAvatarNote');
  const backgroundInput = $('manualBackgroundFile');
  const backgroundName = $('manualBackgroundFilename');
  const backgroundImage = $('manualBackgroundImage');

  const intentEl = $('manualIntent');
  const reviewEl = $('manualReview');

  // ----------------------------------------------------------------------- state

  const selection = createSelection();
  const gate = logic.createLatestGate();
  let rows = []; // the products of the latest filtering
  let matched = 0;
  let notes = []; // warnings + missing-data notes of the latest filtering
  let phase = 'idle'; // idle | loading | ready | error
  let errorMessage = '';
  let inflight = null; // AbortController of the running request
  let debounceTimer = 0;
  let lastKey = '';
  let started = false;
  let currentStep = 1;
  let visited = 1; // furthest step the user has reached
  let styles = []; // ticked narration styles (the single source of truth for the checkboxes)
  let configuredRtmp = null; // null = health unknown
  let avatarValid = false;
  let avatarObjectUrl = '';
  let backgroundObjectUrl = '';
  let submitting = false;

  const checkedValue = (name) => {
    const el = form.querySelector(`input[name="${name}"]:checked`);
    return el ? el.value : '';
  };
  const purposeValue = () => {
    const value = checkedValue('manualPurpose');
    return PURPOSES.includes(value) ? value : 'product_video';
  };
  const isLive = () => purposeValue() !== 'product_video';
  const isSyna = () => characterSelect.value !== 'custom';
  const checkedPlatforms = () => [...form.querySelectorAll('input[name="manualPlatform"]:checked')].map((el) => el.value);
  const isVideoFile = (file) => Boolean(file && (String(file.type).startsWith('video/') || /\.(mp4|webm)$/i.test(file.name)));

  // ------------------------------------------------------------- category cascade

  const catalog = global.__IDOL_CATALOG__ && typeof global.__IDOL_CATALOG__ === 'object' ? global.__IDOL_CATALOG__ : {};

  function fillSelect(select, options, placeholder) {
    select.replaceChildren(new Option(placeholder, ''));
    options.forEach((option) => {
      const suffix = Number.isFinite(option.count) ? ` ( có ${numberText(option.count, 0)} sản phẩm )` : '';
      select.add(new Option(`${option.label}${suffix}`, option.value));
    });
    select.value = '';
    select.disabled = options.length === 0;
  }

  function updateLevel2() {
    fillSelect(cat2, level2Options(catalog, cat1.value), cat1.value ? 'Chọn danh mục' : 'Chọn nhóm trước');
    fillSelect(cat3, [], 'Chọn danh mục trước');
  }

  function updateLevel3() {
    const options = level3Options(catalog, cat1.value, cat2.value);
    fillSelect(cat3, options, cat2.value ? 'Chọn phân loại (không bắt buộc)' : 'Chọn danh mục trước');
  }

  function updateStockDependents() {
    const none = checkedValue('manualStock') === 'out_of_stock';
    enoughStock.disabled = none;
    if (none) enoughStock.checked = false;
  }

  function updateWarehouseField() {
    const mode = warehouseMode.value;
    warehouseField.hidden = !mode;
    warehouseLabel.textContent = mode === 'batch' ? 'Mã lô hàng' : 'Tên hoặc mã kho';
    warehouseInput.placeholder = mode === 'batch' ? 'Ví dụ: LOT-2026-09-A' : 'Ví dụ: KHO-HN-01';
  }

  // ------------------------------------------------------------ product filtering

  const collectFilters = () => ({
    cat1: cat1.value,
    cat2: cat2.value,
    cat3: cat3.value,
    expiry: checkedValue('manualExpiry'),
    stock: checkedValue('manualStock'),
    sales: checkedValue('manualSales'),
    warehouseMode: warehouseMode.value,
    scopeCode: warehouseInput.value,
    brand: brandInput.value,
    brandCap: $('manualBrandCap').checked,
    enoughStock: enoughStock.checked,
  });

  function visibleRows() {
    const term = normalize(searchInput.value.trim());
    return term ? rows.filter((row) => normalize(`${row.name} ${row.id}`).includes(term)) : rows;
  }

  function renderStatus() {
    notesEl.innerHTML = notes.map((note) => `<li>${escapeHtml(note)}</li>`).join('');
    notesEl.hidden = !notes.length || phase !== 'ready';
    errorEl.hidden = phase !== 'error';
    errorTextEl.textContent = phase === 'error' ? errorMessage : '';
    resultsEl.setAttribute('aria-busy', String(phase === 'loading'));
    resultsEl.classList.toggle('is-loading', phase === 'loading');
    if (phase === 'loading') {
      statusEl.textContent = 'Đang tìm sản phẩm phù hợp…';
    } else if (phase === 'error') {
      statusEl.textContent = '';
    } else if (phase === 'ready') {
      const term = searchInput.value.trim();
      let line = rows.length
        ? `Có ${numberText(matched, 0)} sản phẩm phù hợp${matched > rows.length ? `, hiển thị ${numberText(rows.length, 0)} dòng đầu` : ''}.`
        : 'Không có sản phẩm nào phù hợp với bộ lọc hiện tại. Thử nới điều kiện kho, hạn dùng hoặc chọn danh mục khác.';
      if (rows.length && term) line += ` ${numberText(visibleRows().length, 0)} dòng khớp “${term}”.`;
      statusEl.textContent = line;
    } else {
      statusEl.textContent = '';
    }
  }

  function renderRows() {
    const list = visibleRows();
    listEl.innerHTML = list.map((row) => productRowMarkup(row, selection.has(row.id))).join('');
    listEl.hidden = !list.length;
    selectAllButton.disabled = !list.length;
    renderStatus();
  }

  // Selection changed: counter, chips, row ticks, the buttons, the summary bar.
  function refreshSelection() {
    const count = selection.size;
    selectedCountEl.textContent = `${count} đã chọn`;
    deselectButton.disabled = count === 0;
    chipsEl.innerHTML = chipsMarkup(selection.list());
    chipsEl.hidden = count === 0;
    listEl.querySelectorAll('li[data-id]').forEach((item) => {
      const on = selection.has(item.dataset.id);
      item.classList.toggle('is-selected', on);
      const box = item.querySelector('input[type="checkbox"]');
      if (box) box.checked = on;
    });
    updatePrepNote();
    renderSummary();
  }

  function setAbort(controller) {
    if (inflight && inflight !== controller) inflight.abort();
    inflight = controller;
  }

  async function runQuery({ force = false } = {}) {
    clearTimeout(debounceTimer);
    const body = buildQueryBody(collectFilters());
    const key = JSON.stringify(body);
    if (!force && key === lastKey && phase !== 'error') return; // same filters as the last request: nothing to ask
    lastKey = key;
    selection.startFilter();

    const controller = new AbortController();
    setAbort(controller);
    const token = gate.next();
    let timedOut = false;
    const timer = setTimeout(() => { timedOut = true; controller.abort(); }, QUERY_TIMEOUT_MS);
    phase = 'loading';
    renderStatus();
    try {
      const data = await api(QUERY_URL, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(body),
        signal: controller.signal,
      });
      if (!gate.isCurrent(token)) return; // a newer filtering owns the list now
      rows = (Array.isArray(data.products) ? data.products : []).map(normalizeProduct).filter(Boolean);
      const total = Number(data.matched_count);
      matched = Number.isFinite(total) ? total : rows.length;
      const warnings = (Array.isArray(data.warnings) ? data.warnings : []).filter((item) => typeof item === 'string' && item.trim());
      notes = warnings.concat(missingDataNotes(data.missing_data));
      phase = 'ready';
      selection.applyTop(rows, quantitySelect.value);
      renderRows();
      refreshSelection();
    } catch (error) {
      if (!gate.isCurrent(token)) return;
      if (error && error.name === 'AbortError' && !timedOut) return;
      rows = [];
      matched = 0;
      notes = [];
      phase = 'error';
      const reason = timedOut ? 'Tìm sản phẩm mất hơn 10 giây' : friendlyError(error && error.message) || 'Không tìm được sản phẩm';
      errorMessage = `${/[.!?…]$/.test(reason) ? reason : `${reason}.`} Các sản phẩm bạn đã chọn vẫn được giữ.`;
      renderRows();
    } finally {
      clearTimeout(timer);
      if (inflight === controller) inflight = null;
    }
  }

  const scheduleQuery = (delay = DEBOUNCE_MS) => {
    clearTimeout(debounceTimer);
    debounceTimer = setTimeout(() => { runQuery(); }, delay);
  };

  function startFiltering() {
    if (started) return;
    started = true;
    runQuery({ force: true });
  }

  // ------------------------------------------------------------- summary + intent

  function currentSel() {
    const purpose = purposeValue();
    return {
      products: selection.list().map(({ id, name }) => ({ id, name })),
      purpose,
      schedule: purpose === 'product_video' ? {} : { at: scheduledAt.value, duration: Number(durationInput.value), platforms: checkedPlatforms() },
      contentMode: contentModeSelect.value,
      styles: pruneStyles(styles, purpose),
      character: isSyna() ? 'syna_3d' : 'custom',
      format: formatSelect.value,
    };
  }

  function renderSummary() {
    summaryEl.textContent = logic.summaryBar(currentSel());
  }

  function optionLabel(select) {
    const option = select.selectedOptions && select.selectedOptions[0];
    return option ? option.textContent.trim() : '';
  }

  function renderReview() {
    const sel = currentSel();
    const live = sel.purpose !== 'product_video';
    const avatarFile = avatarInput.files && avatarInput.files[0];
    const backgroundFile = backgroundInput.files && backgroundInput.files[0];
    intentEl.textContent = logic.describeIntent(sel);
    reviewEl.innerHTML = reviewMarkup({
      products: sel.products,
      purposeLabel: logic.PURPOSE_LABELS[sel.purpose],
      live,
      slot: live ? sel.schedule.at : '',
      duration: live ? sel.schedule.duration : 0,
      channels: live ? sel.schedule.platforms.map(platformName) : [],
      name: nameInput.value,
      contentModeLabel: optionLabel(contentModeSelect),
      styleLabels: sel.styles.map((key) => logic.NARRATION_STYLES.find((style) => style.key === key).label),
      custom: sel.character === 'custom',
      avatarName: avatarFile ? avatarFile.name : '',
      format: sel.format,
      backgroundName: backgroundFile ? backgroundFile.name : '',
    });
  }

  // ------------------------------------------------------- step 2: purpose + schedule

  const preparationMinutes = () => Math.max(5, selection.size * 8); // the same estimate the old wizard showed

  function updatePrepNote() {
    if (!prepNote) return;
    const count = selection.size;
    prepNote.textContent = count
      ? `${count} sản phẩm: nên chừa ít nhất ${preparationMinutes()} phút để tạo video, chưa tính thời gian bạn duyệt kịch bản.`
      : 'Chưa chọn sản phẩm. Quay lại bước 1 để chọn.';
  }

  function updateRtmpWarning() {
    const warning = isLive() ? rtmpWarning(checkedPlatforms(), configuredRtmp) : '';
    rtmpWarnEl.textContent = warning;
    rtmpWarnEl.hidden = !warning;
  }

  function lockedStylesSentence() {
    const labels = logic.NARRATION_STYLES.filter((style) => style.liveOnly).map((style) => `“${style.label}”`);
    return `${labels.join(' và ')} tạm tắt vì video sản phẩm nằm lâu dài trên trang sản phẩm.`;
  }

  function buildStyleGrid() {
    logic.NARRATION_STYLES.forEach((style) => {
      const label = document.createElement('label');
      label.className = 'wz-check';
      const input = document.createElement('input');
      input.type = 'checkbox';
      input.name = 'manualStyle';
      input.value = style.key;
      const span = document.createElement('span');
      span.textContent = style.label;
      label.append(input, span);
      styleGrid.append(label);
    });
    styleNote.textContent = lockedStylesSentence();
  }

  function syncStyles() {
    const allowed = logic.allowedStyles(purposeValue());
    styleGrid.querySelectorAll('input[name="manualStyle"]').forEach((input) => {
      const locked = !allowed.includes(input.value);
      input.checked = styles.includes(input.value);
      input.disabled = locked;
      if (locked) input.setAttribute('aria-describedby', 'manualStyleNote');
      else input.removeAttribute('aria-describedby');
      input.closest('label').classList.toggle('is-disabled', locked);
    });
    styleNote.hidden = purposeValue() !== 'product_video';
  }

  function applyPurpose() {
    const purpose = purposeValue();
    const live = purpose !== 'product_video';
    liveFields.hidden = !live;
    $('manualProductVideoNote').hidden = live;
    $('manualApprovalNote').hidden = !live;
    styles = pruneStyles(styles, purpose); // toggleStyle cannot remove a key the new purpose forbids
    syncStyles();
    if (live) {
      scheduledAt.min = formatInZone(new Date());
      if (!scheduledAt.value) {
        const lead = Math.max(60, preparationMinutes() + 15);
        scheduledAt.value = formatInZone(new Date(Date.now() + lead * 60 * 1000));
      }
      updatePrepNote();
    }
    updateRtmpWarning();
  }

  // ----------------------------------------------------- step 3: character + files

  function applyCharacter() {
    const syna = isSyna();
    $('manualCustomAvatar').hidden = syna;
    avatarNote.hidden = syna;
    $('manualSynaHelp').hidden = !syna;
    $('manualSynaPreview').hidden = !syna;
    formatSelect.querySelector('option[value="9:16"]').disabled = syna;
    if (syna) {
      formatSelect.value = '16:9';
      avatarVideo.pause();
    }
  }

  function setAvatarNote(mode, message) {
    avatarNote.className = `avatar-mode-note${mode ? ` ${mode}` : ''}`;
    avatarNote.textContent = message;
  }

  function clearAvatarPreview() {
    avatarValid = false;
    avatarImage.onload = avatarImage.onerror = avatarVideo.onloadedmetadata = avatarVideo.onerror = null;
    avatarVideo.pause();
    avatarImage.removeAttribute('src');
    avatarVideo.removeAttribute('src');
    avatarImage.classList.remove('has-image');
    avatarVideo.classList.remove('has-image');
    avatarVideo.load();
    if (avatarObjectUrl) URL.revokeObjectURL(avatarObjectUrl);
    avatarObjectUrl = '';
  }

  function clearBackgroundPreview() {
    backgroundImage.onload = backgroundImage.onerror = null;
    backgroundImage.removeAttribute('src');
    backgroundImage.classList.remove('has-image');
    if (backgroundObjectUrl) URL.revokeObjectURL(backgroundObjectUrl);
    backgroundObjectUrl = '';
  }

  avatarInput.addEventListener('change', () => {
    clearAvatarPreview();
    const file = avatarInput.files && avatarInput.files[0];
    avatarName.textContent = file ? file.name : 'Chọn video hoặc ảnh';
    if (!file) {
      setAvatarNote('', 'Chọn nhân vật để xem trước tại đây.');
      renderSummary();
      return;
    }
    const video = isVideoFile(file);
    const fail = (message) => {
      avatarInput.value = '';
      avatarName.textContent = 'Chọn video hoặc ảnh';
      avatarValid = false;
      setAvatarNote('photo', message);
      notify(messageEl, message);
    };
    if (!/\.(png|jpe?g|webp|mp4|webm)$/i.test(file.name)) return fail('Nhân vật cần là ảnh PNG, JPG, WebP hoặc video MP4, WebM.');
    if (file.size > (video ? 35 : 10) * MB) return fail(`Tệp nhân vật tối đa ${video ? 35 : 10} MB.`);
    notify(messageEl, '');
    avatarObjectUrl = URL.createObjectURL(file);
    const url = avatarObjectUrl;
    if (!video) {
      setAvatarNote('', 'Đang đọc ảnh nhân vật…');
      avatarImage.onload = () => {
        if (avatarObjectUrl !== url) return;
        avatarValid = true;
        avatarImage.classList.add('has-image');
        setAvatarNote('photo', 'Ảnh tĩnh: miệng và đầu chuyển động nhẹ; vai, tay và cơ thể giữ nguyên.');
      };
      avatarImage.onerror = () => fail('Không đọc được ảnh này. Bạn hãy chọn ảnh khác.');
      avatarImage.src = url;
    } else {
      setAvatarNote('', 'Đang kiểm tra video nhân vật…');
      avatarVideo.onloadedmetadata = () => {
        if (avatarObjectUrl !== url) return;
        if (!Number.isFinite(avatarVideo.duration) || avatarVideo.duration < 5 || avatarVideo.duration > 30) {
          return fail('Video nhân vật cần dài từ 5 đến 30 giây.');
        }
        avatarValid = true;
        avatarVideo.classList.add('has-image');
        setAvatarNote('motion', 'Video: giữ chuyển động mắt, đầu, vai và tay có sẵn trong clip; tạo lại khẩu hình theo lời nói.');
        if (currentStep === 3) avatarVideo.play().catch(() => {});
      };
      avatarVideo.onerror = () => fail('Không đọc được video này. Thử video MP4 định dạng H.264.');
      avatarVideo.src = url;
    }
  });

  backgroundInput.addEventListener('change', () => {
    clearBackgroundPreview();
    const file = backgroundInput.files && backgroundInput.files[0];
    backgroundName.textContent = file ? file.name : 'Chọn ảnh nền · tùy chọn';
    if (!file) return;
    if (file.size > 10 * MB || !/\.(png|jpe?g|webp)$/i.test(file.name)) {
      backgroundInput.value = '';
      backgroundName.textContent = 'Chọn ảnh nền · tùy chọn';
      notify(messageEl, 'Phông nền cần là ảnh PNG, JPG hoặc WebP, tối đa 10 MB.');
      return;
    }
    backgroundObjectUrl = URL.createObjectURL(file);
    backgroundImage.onload = () => backgroundImage.classList.add('has-image');
    backgroundImage.onerror = () => {
      backgroundInput.value = '';
      backgroundName.textContent = 'Chọn ảnh nền · tùy chọn';
      notify(messageEl, 'Không đọc được phông nền. Bạn hãy chọn ảnh khác.');
    };
    backgroundImage.src = backgroundObjectUrl;
  });

  // ------------------------------------------------------------- step navigation

  function validateStep(step) {
    if (step === 1) {
      return selection.size
        ? { ok: true }
        : { ok: false, message: 'Chọn ít nhất một sản phẩm để tiếp tục.', focus: searchInput };
    }
    if (step === 2) {
      const live = isLive();
      const result = validateSchedule({
        purpose: purposeValue(),
        at: scheduledAt.value,
        duration: durationInput.value,
        platforms: live ? checkedPlatforms() : [],
      }, formatInZone(new Date()));
      if (result.ok) return result;
      const target = { at: scheduledAt, duration: durationInput, platforms: form.querySelector('input[name="manualPlatform"]') }[result.field];
      return { ...result, focus: target };
    }
    if (step === 3 && !isSyna() && !(avatarValid && avatarInput.files && avatarInput.files[0])) {
      return { ok: false, message: 'Chọn ảnh hoặc video nhân vật hợp lệ để tiếp tục.', focus: avatarInput };
    }
    return { ok: true };
  }

  function renderStepNav() {
    stepButtons.forEach((button) => {
      const step = Number(button.dataset.wzStep);
      const active = step === currentStep;
      button.classList.toggle('is-active', active);
      if (active) button.setAttribute('aria-current', 'step');
      else button.removeAttribute('aria-current');
      const done = !active && step < STEP_COUNT && step <= visited && validateStep(step).ok;
      button.classList.toggle('is-done', done);
      const hint = button.querySelector('.wz-step-hint');
      if (hint) hint.textContent = done ? ' (đã xong)' : active ? ' (bước hiện tại)' : '';
    });
  }

  function showStep(step, { focus = true } = {}) {
    currentStep = Math.min(STEP_COUNT, Math.max(1, step));
    visited = Math.max(visited, currentStep);
    stepPanels.forEach((section, index) => { section.hidden = index + 1 !== currentStep; });
    counterEl.textContent = `Bước ${currentStep} / ${STEP_COUNT}`;
    prevButton.hidden = currentStep === 1;
    nextButton.hidden = currentStep === STEP_COUNT;
    createButtons.forEach((button) => { button.hidden = currentStep !== STEP_COUNT; });
    if (currentStep !== 3) avatarVideo.pause();
    else if (avatarValid && isVideoFile(avatarInput.files && avatarInput.files[0])) avatarVideo.play().catch(() => {});
    if (currentStep === 2) {
      scheduledAt.min = formatInZone(new Date());
      updatePrepNote();
      updateRtmpWarning();
    }
    if (currentStep === STEP_COUNT) renderReview();
    notify(messageEl, '');
    renderStepNav();
    renderSummary();
    if (focus) stepTitles[currentStep - 1].focus();
  }

  // Going back is always allowed; going forward needs every step in between to be valid.
  function goTo(target, options = {}) {
    if (submitting) return false;
    const wanted = Math.min(STEP_COUNT, Math.max(1, Number(target) || 1));
    for (let step = currentStep; step < wanted; step += 1) {
      const result = validateStep(step);
      if (result.ok) continue;
      showStep(step, { focus: false });
      notify(messageEl, result.message);
      if (result.focus) result.focus.focus();
      return false;
    }
    showStep(wanted, options);
    return true;
  }

  stepButtons.forEach((button) => button.addEventListener('click', () => goTo(Number(button.dataset.wzStep))));
  prevButton.addEventListener('click', () => goTo(currentStep - 1));
  nextButton.addEventListener('click', () => goTo(currentStep + 1));
  reviewEl.addEventListener('click', (event) => {
    const edit = event.target.closest('[data-wz-edit]');
    if (edit) goTo(Number(edit.dataset.wzEdit));
  });

  // ----------------------------------------------------------- events: step 1

  function markFilterChange() { scheduleQuery(); }

  cat1.addEventListener('change', () => { updateLevel2(); markFilterChange(); });
  cat2.addEventListener('change', () => { updateLevel3(); markFilterChange(); });
  cat3.addEventListener('change', markFilterChange);
  form.querySelectorAll('input[name="manualExpiry"], input[name="manualSales"]').forEach((el) => el.addEventListener('change', markFilterChange));
  form.querySelectorAll('input[name="manualStock"]').forEach((el) => el.addEventListener('change', () => { updateStockDependents(); markFilterChange(); }));
  warehouseMode.addEventListener('change', () => { updateWarehouseField(); markFilterChange(); });
  warehouseInput.addEventListener('input', markFilterChange);
  brandInput.addEventListener('input', markFilterChange);
  $('manualBrandCap').addEventListener('change', markFilterChange);
  enoughStock.addEventListener('change', markFilterChange);

  // Choosing Top N is itself an instruction: it ticks the first N rows of the list on screen even if the user
  // ticked by hand before (hand ticks stay; only the earlier automatic ones are replaced).
  quantitySelect.addEventListener('change', () => {
    selection.startFilter();
    selection.applyTop(rows, quantitySelect.value);
    refreshSelection();
  });

  searchInput.addEventListener('input', renderRows);
  // Enter in a field never submits the wizard; in the brand and warehouse boxes it asks for the list at once.
  form.addEventListener('keydown', (event) => {
    const target = event.target;
    if (event.key !== 'Enter' || !target || target.tagName !== 'INPUT') return;
    if (!['text', 'search', 'number', 'datetime-local'].includes(target.type)) return;
    event.preventDefault();
    if (target === brandInput || target === warehouseInput) runQuery();
  });

  listEl.addEventListener('change', (event) => {
    const box = event.target.closest('input[data-wz-product]');
    if (!box) return;
    const row = rows.find((item) => item.id === box.value);
    if (!row) return;
    selection.set(row, box.checked);
    refreshSelection();
  });
  // An <img> error does not bubble, so listen while the event travels down.
  listEl.addEventListener('error', (event) => {
    if (event.target && event.target.tagName === 'IMG') event.target.hidden = true;
  }, true);

  selectAllButton.addEventListener('click', () => {
    selection.setMany(visibleRows(), true);
    refreshSelection();
  });
  deselectButton.addEventListener('click', () => {
    selection.clear();
    refreshSelection();
    searchInput.focus();
  });
  chipsEl.addEventListener('click', (event) => {
    const chip = event.target.closest('[data-wz-remove]');
    if (!chip) return;
    const buttons = [...chipsEl.querySelectorAll('[data-wz-remove]')];
    const index = buttons.indexOf(chip);
    selection.set({ id: chip.dataset.wzRemove }, false);
    refreshSelection();
    const left = chipsEl.querySelectorAll('[data-wz-remove]');
    (left[Math.min(index, left.length - 1)] || searchInput).focus();
  });
  retryButton.addEventListener('click', () => runQuery({ force: true }));

  // ----------------------------------------------------------- events: steps 2-3

  form.querySelectorAll('input[name="manualPurpose"]').forEach((el) => el.addEventListener('change', () => {
    applyPurpose();
    renderSummary();
  }));
  form.querySelectorAll('input[name="manualPlatform"]').forEach((el) => el.addEventListener('change', () => {
    updateRtmpWarning();
    renderSummary();
  }));
  styleGrid.addEventListener('change', (event) => {
    const input = event.target.closest('input[name="manualStyle"]');
    if (!input) return;
    styles = logic.toggleStyle(pruneStyles(styles, purposeValue()), input.value, purposeValue());
    syncStyles();
    renderSummary();
  });
  characterSelect.addEventListener('change', () => {
    applyCharacter();
    renderSummary();
  });
  [scheduledAt, durationInput, contentModeSelect, formatSelect, nameInput].forEach((el) => {
    el.addEventListener('input', renderSummary);
    el.addEventListener('change', renderSummary);
  });

  // ----------------------------------------------------------------------- submit

  async function uploadAsset(kind, file) {
    if (!file) return '';
    const body = new FormData();
    body.append('kind', kind);
    body.append('file', file);
    const result = await api(UPLOAD_URL, { method: 'POST', body });
    if (!result.filename) throw new Error(kind === 'avatar' ? 'Không tải được tệp nhân vật lên. Hãy thử lại.' : 'Không tải được phông nền lên. Hãy thử lại.');
    return result.filename;
  }

  function setBusy(busy, activeButton) {
    submitting = busy;
    createButtons.forEach((button) => {
      button.disabled = busy;
      if (!button.dataset.label) button.dataset.label = button.innerHTML;
      button.innerHTML = busy && button === activeButton ? 'Đang chuẩn bị…' : button.dataset.label;
    });
    prevButton.disabled = busy;
    stepButtons.forEach((button) => { button.disabled = busy; });
    form.setAttribute('aria-busy', String(busy));
  }

  function resetWizard() {
    form.reset(); // values only: no change events fire
    selection.reset();
    rows = [];
    matched = 0;
    notes = [];
    phase = 'idle';
    styles = [];
    clearAvatarPreview();
    clearBackgroundPreview();
    setAvatarNote('', 'Chọn nhân vật để xem trước tại đây.');
    avatarName.textContent = 'Chọn video hoặc ảnh';
    backgroundName.textContent = 'Chọn ảnh nền · tùy chọn';
    updateLevel2();
    updateStockDependents();
    updateWarehouseField();
    applyPurpose();
    applyCharacter();
    visited = 1;
    renderRows();
    refreshSelection();
    showStep(1);
    runQuery({ force: true });
  }

  async function createCampaign(approvalMode, button) {
    if (submitting) return;
    for (let step = 1; step <= 3; step += 1) { // a step can have gone stale since it was visited
      const result = validateStep(step);
      if (result.ok) continue;
      showStep(step, { focus: false });
      notify(messageEl, result.message);
      if (result.focus) result.focus.focus();
      return;
    }
    setBusy(true, button);
    notify(messageEl, '');
    let created = null;
    try {
      // Capture the whole form before the first await: the form stays editable while files upload.
      const sel = currentSel();
      const avatarFile = isSyna() ? null : avatarInput.files[0];
      const backgroundFile = backgroundInput.files && backgroundInput.files[0];
      const base = {
        name: nameInput.value,
        productIds: sel.products.map((product) => product.id),
        purpose: sel.purpose,
        scheduledAt: scheduledAt.value,
        platforms: checkedPlatforms(),
        duration: durationInput.value,
        contentMode: contentModeSelect.value,
        format: formatSelect.value,
        character: sel.character,
        avatarIsVideo: isVideoFile(avatarFile),
        styles: sel.styles,
        approvalMode,
      };
      // The required avatar goes first so a failure does not leave an orphan background behind.
      const avatarAsset = await uploadAsset('avatar', avatarFile);
      const backgroundAsset = await uploadAsset('background', backgroundFile);
      created = await api(CREATE_URL, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(buildCampaignPayload({ ...base, avatarAsset, backgroundAsset })),
      });
    } catch (error) {
      notify(messageEl, `Không thể tạo chương trình: ${friendlyError(error && error.message) || 'lỗi không rõ.'}`);
    } finally {
      setBusy(false);
    }
    if (created) afterCreated(created);
  }

  // The campaign exists from here on. Nothing below may report "Không thể tạo chương trình": a second click
  // would create a duplicate.
  function afterCreated(result) {
    try {
      resetWizard();
      notify(messageEl, result.created === false
        ? 'Chương trình giống hệt đã được tạo trước đó. Theo dõi tiến độ ở bảng Hoạt động.'
        : 'Đã tạo chương trình. Theo dõi tiến độ ở bảng Hoạt động.', true);
    } catch (error) {
      notify(messageEl, 'Đã tạo chương trình nhưng biểu mẫu chưa làm mới được. Hãy tải lại trang trước khi tạo tiếp.');
    }
    emit('studio:refresh', {});
    if (result.campaign_id) emit('studio:open-item', { kind: 'campaign', id: String(result.campaign_id) });
  }

  form.addEventListener('submit', (event) => {
    event.preventDefault();
    if (currentStep === STEP_COUNT) {
      const button = event.submitter && createButtons.includes(event.submitter) ? event.submitter : null;
      createCampaign(button && button.dataset.approval === 'AUTO' ? 'AUTO' : 'MANUAL', button);
    } // Enter in a text field on an earlier step does nothing
  });

  // ----------------------------------------------------------------------- health

  on('studio:health', (event) => {
    configuredRtmp = rtmpPlatformsOf(event.detail && event.detail.data);
    updateRtmpWarning();
  });
  configuredRtmp = rtmpPlatformsOf(typeof core.health === 'function' ? core.health() : null);

  // ------------------------------------------------------------------------- init

  buildStyleGrid();
  updateLevel2();
  updateStockDependents();
  updateWarehouseField();
  applyPurpose();
  applyCharacter();
  updatePrepNote();
  renderRows();
  refreshSelection();
  showStep(1, { focus: false });

  // The first request waits until the tab is really shown: the wizard is the hidden tab by default.
  if (!panel.hidden) {
    startFiltering();
  } else {
    const observer = new MutationObserver(() => {
      if (panel.hidden) return;
      observer.disconnect();
      startFiltering();
    });
    observer.observe(panel, { attributes: true, attributeFilter: ['hidden'] });
  }
}(typeof globalThis !== 'undefined' ? globalThis : this));
