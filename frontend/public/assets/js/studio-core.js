/*
 * AI Idol Studio - shared shell.
 *
 * Owns what every part of the page needs: api() (fetch + errors + CSRF), escaping and
 * formatting helpers, the Tự động / Thủ công tab switch, the service-health banner, the three
 * header numbers and the sync indicator. studio-manual.js, studio-auto.js and studio-activity.js
 * talk to each other and to this file through window.StudioCore and CustomEvents on #idolStudio.
 *
 * Events (handlers receive the CustomEvent; read event.detail):
 *   studio:data           {campaigns, runs[, error]}  emitted by studio-activity.js after each refresh.
 *                         `error` (truthy) = the refresh failed: the sync indicator goes offline and
 *                         the header numbers keep their last values.
 *   studio:health         {data[, error]}             emitted here. data = payload of
 *                         admin_ai_idol_health ({overall, checked_at, services}); null = unknown.
 *   studio:refresh        {}                          anyone may emit; studio-activity.js reloads.
 *   studio:open-item      {kind, id}                  open a campaign/run in the Activity column.
 *   studio:prefill-prompt {brief}                     put a brief in the Tự động prompt box.
 *   studio:show-flow      {flow}                      listened to here: 'auto' | 'manual'.
 *
 * The file loads in the browser through a <script> tag and in Node (module.exports) for the DOM-free
 * helpers, which is how studio-core.test.js reaches decisionsMarkup and the formatters.
 */
(function (global) {
  'use strict';

  // ------------------------------------------------------------ pure helpers (no DOM)

  const TIME_ZONE = 'Asia/Ho_Chi_Minh';

  const escapeHtml = (value) => String(value ?? '').replace(/[&<>'"]/g, (char) => (
    { '&': '&amp;', '<': '&lt;', '>': '&gt;', "'": '&#39;', '"': '&quot;' }[char]
  ));

  function formatDate(value, fallback = 'Chưa có lịch') {
    if (!value) return fallback;
    const date = new Date(value);
    if (Number.isNaN(date.getTime())) return fallback;
    return date.toLocaleString('vi-VN', {
      day: '2-digit', month: '2-digit', year: 'numeric', hour: '2-digit', minute: '2-digit', timeZone: TIME_ZONE,
    });
  }

  const normalize = (value) => String(value).normalize('NFD').replace(/[̀-ͯ]/g, '').replace(/đ/gi, 'd').toLocaleLowerCase('vi');

  // Campaign, Agent run and segment statuses share one table so every panel names them the same way.
  const STATUS_NAMES = new Map(Object.entries({
    DRAFT: 'Bản nháp', QUEUED: 'Đang chờ', PENDING: 'Đang chờ', GENERATING: 'Đang tạo', PROCESSING: 'Đang xử lý',
    AWAITING_APPROVAL: 'Chờ duyệt', READY: 'Sẵn sàng', SCHEDULED: 'Đã lên lịch', STARTING: 'Sắp lên sóng',
    LIVE: 'Đang phát', COMPLETED: 'Hoàn tất', VIDEOS_READY: 'Video đã tạo xong', NEEDS_ATTENTION: 'Cần kiểm tra',
    PARTIAL_FAILURE: 'Lỗi một phần', FAILED: 'Bị lỗi', CANCELLED: 'Đã hủy', SKIPPED: 'Đã bỏ qua',
    WAITING_RENDERER: 'Chờ Syna', ASSEMBLING: 'Đang ghép video', VALIDATING: 'Đang kiểm tra',
    REJECTED_BY_VALIDATOR: 'Bị từ chối kiểm duyệt',
    RESULTS_READY: 'Đã tìm xong', DATA_UNAVAILABLE: 'Thiếu dữ liệu', EMPTY_RESULTS: 'Không có kết quả',
    // Agent run stages
    RECEIVED: 'Đã tiếp nhận', INTERPRETING: 'Đang hiểu yêu cầu', CLASSIFYING_INTENT: 'Đang phân loại ý định',
    INTENT_REVIEW: 'Cần xác nhận ý định', RESOLVING_PRODUCTS: 'Đang tìm sản phẩm', NEEDS_INPUT: 'Cần bạn xác nhận',
    PLAN_READY: 'Đã lập kế hoạch', CAMPAIGN_CREATED: 'Đã tạo chương trình', QUERYING_PRODUCTS: 'Đang lọc và kiểm tra số liệu',
    QUERY_RESULTS: 'Kết quả truy vấn', SCHEDULE_REVIEW: 'Cần sửa lịch phát', POLICY_GATE: 'Cần chọn nơi phát',
    CAMPAIGN_CANCELLED: 'Chương trình đã hủy',
  }));
  const statusName = (value) => STATUS_NAMES.get(value) || String(value || 'Đang chờ');
  const badge = (value, label) => `<span class="status ${escapeHtml(value)}">${escapeHtml(label || statusName(value))}</span>`;

  const PLATFORM_NAMES = new Map(Object.entries({ internal: 'Nội bộ', preview: 'Xem thử', youtube: 'YouTube', facebook: 'Facebook' }));
  const platformName = (value) => PLATFORM_NAMES.get(value) || String(value ?? '');

  // A decision is {code, level, message, at}. The icon says the level at a glance; a visually-hidden
  // label says it to screen readers. The level is never copied into markup: only known levels map to a class.
  const DECISION_LEVELS = new Map(Object.entries({
    done: { icon: 'bi-check2-circle', label: 'Đã làm' },
    adjusted: { icon: 'bi-exclamation-triangle', label: 'Đã điều chỉnh' },
    waiting: { icon: 'bi-lock', label: 'Chờ bạn' },
    stopped: { icon: 'bi-x-circle', label: 'Đã dừng' },
  }));
  const DECISION_UNKNOWN = { icon: 'bi-info-circle', label: 'Ghi chú' };

  function decisionsMarkup(decisions) {
    if (!Array.isArray(decisions)) return '';
    const rows = decisions.map((decision) => {
      if (!decision || typeof decision !== 'object') return '';
      const message = String(decision.message ?? '').trim();
      if (!message) return '';
      const key = String(decision.level ?? '').toLowerCase();
      const level = DECISION_LEVELS.get(key) || DECISION_UNKNOWN;
      const modifier = DECISION_LEVELS.has(key) ? key : 'info';
      return `<li class="studio-decision is-${modifier}"><i class="bi ${level.icon}" aria-hidden="true"></i><span class="visually-hidden">${level.label}: </span><span class="studio-decision-text">${escapeHtml(message)}</span></li>`;
    }).filter(Boolean);
    return rows.length ? `<ol class="studio-decisions" role="list">${rows.join('')}</ol>` : '';
  }

  const helpers = { escapeHtml, formatDate, normalize, statusName, badge, platformName, decisionsMarkup };
  if (typeof module === 'object' && module.exports) module.exports = helpers;

  // ------------------------------------------------------------------ browser only

  const doc = global.document;
  const root = doc ? doc.getElementById('idolStudio') : null;
  if (!root || global.StudioCore) return;

  const $ = (id) => doc.getElementById(id);

  // fetch + JSON + errors. Resolves with the parsed body; throws Error(message) on a network failure,
  // a non-JSON body, a non-2xx status or `ok: false`. Non-GET requests carry the CSRF token.
  async function api(url, options = {}) {
    const init = { ...options };
    const method = String(init.method || 'GET').toUpperCase();
    const token = typeof global.AI_IDOL_CSRF === 'string' ? global.AI_IDOL_CSRF : '';
    if (method !== 'GET' && token) {
      const headers = new Headers(init.headers || {});
      if (!headers.has('X-CSRF-Token')) headers.set('X-CSRF-Token', token);
      init.headers = headers;
    }
    let response;
    try {
      response = await fetch(url, init);
    } catch (error) {
      if (error && error.name === 'AbortError') throw error; // callers cancel their own requests
      const failure = new Error('Không kết nối được máy chủ. Hãy kiểm tra mạng rồi thử lại.');
      failure.status = 0;
      throw failure;
    }
    const raw = await response.text();
    let data;
    try {
      data = raw ? JSON.parse(raw) : {};
    } catch {
      const failure = new Error(response.status === 413
        ? 'Tệp vượt giới hạn máy chủ. Video nhân vật tối đa 35 MB.'
        : `Máy chủ trả về phản hồi không hợp lệ (HTTP ${response.status}). Hãy kiểm tra phiên đăng nhập.`);
      failure.status = response.status;
      throw failure;
    }
    if (!response.ok || (data && data.ok === false)) {
      const failure = new Error((data && data.message) || `Máy chủ báo lỗi (HTTP ${response.status}).`);
      failure.status = response.status;
      failure.data = data;
      throw failure;
    }
    return data;
  }

  function notify(el, message, success = false) {
    if (!el) return;
    el.textContent = message || '';
    el.classList.add('studio-message');
    el.classList.toggle('success', Boolean(success));
    el.hidden = !message;
  }

  const emit = (name, detail) => root.dispatchEvent(new CustomEvent(name, { detail }));
  function on(name, handler) {
    root.addEventListener(name, handler);
    return () => root.removeEventListener(name, handler);
  }

  // ------------------------------------------------------------------ tab switch

  const FLOW_KEY = 'aiIdolStudio.flow';
  const FLOWS = ['auto', 'manual'];
  const DEFAULT_FLOW = 'auto';
  const tablist = root.querySelector('[role="tablist"]');
  const tabs = [...root.querySelectorAll('[data-studio-flow]')];

  function storedFlow() {
    try {
      const value = global.localStorage.getItem(FLOW_KEY);
      return FLOWS.includes(value) ? value : DEFAULT_FLOW;
    } catch {
      return DEFAULT_FLOW;
    }
  }

  function rememberFlow(flow) {
    try {
      global.localStorage.setItem(FLOW_KEY, flow);
    } catch {
      // Private window or blocked site data: the choice just is not remembered.
    }
  }

  function showFlow(flow, { focus = false, remember = true, reveal = false } = {}) {
    if (!FLOWS.includes(flow)) return;
    tabs.forEach((tab) => {
      const active = tab.dataset.studioFlow === flow;
      tab.classList.toggle('is-active', active);
      tab.setAttribute('aria-selected', String(active));
      tab.tabIndex = active ? 0 : -1;
      const panel = $(tab.getAttribute('aria-controls'));
      if (panel) panel.hidden = !active;
      if (active && focus) tab.focus();
    });
    if (remember) rememberFlow(flow);
    // Asked from another column (for example "Dùng lại prompt" far below on a phone): bring the tabs into view.
    if (reveal && tablist) {
      const top = tablist.getBoundingClientRect().top;
      if (top < 0 || top > global.innerHeight) tablist.scrollIntoView({ block: 'start' });
    }
  }

  tabs.forEach((tab) => tab.addEventListener('click', () => showFlow(tab.dataset.studioFlow)));
  if (tablist) {
    tablist.addEventListener('keydown', (event) => {
      if (event.altKey || event.ctrlKey || event.metaKey) return;
      const current = tabs.indexOf(event.target.closest('[data-studio-flow]'));
      if (current < 0) return;
      let next;
      if (event.key === 'ArrowRight') next = (current + 1) % tabs.length;
      else if (event.key === 'ArrowLeft') next = (current - 1 + tabs.length) % tabs.length;
      else if (event.key === 'Home') next = 0;
      else if (event.key === 'End') next = tabs.length - 1;
      else return;
      event.preventDefault();
      showFlow(tabs[next].dataset.studioFlow, { focus: true });
    });
  }
  on('studio:show-flow', (event) => showFlow(event.detail && event.detail.flow, { reveal: true }));
  showFlow(storedFlow(), { remember: false });

  // ------------------------------------------------------------------ service health

  // Informational only: it never disables a button. `data` is the payload of admin_ai_idol_health.
  const HEALTH_INTERVAL = 30000;
  const HEALTH_LABELS = { mongo: 'Dữ liệu', redis: 'Hàng đợi', workers: 'Worker', script_council: 'Kịch bản', syna: 'Syna', avatar: 'Avatar', rtmp: 'Phát ngoài' };
  const healthEl = $('idolHealth');
  let latestHealth = null;
  let healthBusy = false;
  let healthSignature = '';
  let healthCheckedAt = 0;

  function healthMarkup(data) {
    const services = (data && data.services) || {};
    const chips = Object.entries(HEALTH_LABELS).filter(([key]) => services[key]).map(([key, label]) => {
      const item = services[key];
      const state = item.status === 'not_required' || item.status === 'not_configured' ? 'idle' : item.ok ? 'ok' : 'down';
      const extra = key === 'rtmp' ? (item.platforms || []).map(platformName).join(', ') || 'chỉ nội bộ' : '';
      const icon = state === 'down' ? 'x-circle-fill' : state === 'ok' ? 'check-circle-fill' : 'dash-circle';
      return `<span class="health-chip is-${state}" title="${escapeHtml(item.detail || '')}"><i class="bi bi-${icon}" aria-hidden="true"></i>${escapeHtml(label)}${extra ? `: ${escapeHtml(extra)}` : ''}</span>`;
    }).join('');
    const problems = Object.keys(HEALTH_LABELS)
      .filter((key) => services[key] && !services[key].ok && services[key].detail)
      .map((key) => escapeHtml(services[key].detail));
    return `<span class="health-title">Dịch vụ</span>${chips}${problems.length ? `<span class="health-note is-bad">${problems.join(' ')}</span>` : ''}`;
  }

  // The banner is a polite live region: only touch it when what it says has changed.
  function paintHealth(markup) {
    if (!healthEl || markup === healthSignature) return;
    healthSignature = markup;
    healthEl.innerHTML = markup;
  }

  async function refreshHealth() {
    if (healthBusy) return;
    healthBusy = true;
    try {
      const data = (await api('index.php?r=admin_ai_idol_health')).data || null;
      latestHealth = data;
      paintHealth(healthMarkup(data));
      emit('studio:health', { data });
    } catch (error) {
      latestHealth = null; // unknown: listeners must not warn about a channel they cannot verify
      paintHealth(`<span class="health-title">Dịch vụ</span><span class="health-note is-bad">Không kiểm tra được tình trạng dịch vụ: ${escapeHtml(error.message)}</span>`);
      emit('studio:health', { data: null, error: error.message });
    } finally {
      healthBusy = false;
      healthCheckedAt = Date.now();
    }
  }

  refreshHealth();
  global.setInterval(() => { if (!doc.hidden) refreshHealth(); }, HEALTH_INTERVAL);
  doc.addEventListener('visibilitychange', () => {
    if (!doc.hidden && Date.now() - healthCheckedAt >= HEALTH_INTERVAL) refreshHealth();
  });

  // ------------------------------------------------------------------ header numbers + sync

  const syncEl = $('syncStatus');
  let syncState = '';

  function setSync(online) {
    if (!syncEl) return;
    if (online) syncEl.title = `Cập nhật lúc ${new Date().toLocaleTimeString('vi-VN', { hour: '2-digit', minute: '2-digit', timeZone: TIME_ZONE })}`;
    const state = online ? 'online' : 'offline';
    if (state === syncState) return; // role="status": announce a change of state, not every refresh
    syncState = state;
    syncEl.classList.toggle('is-offline', !online);
    syncEl.innerHTML = `<span class="sync-dot"></span> ${online ? 'Tự động cập nhật' : 'Chưa thể cập nhật'}`;
  }

  function renderStats(campaigns) {
    const put = (id, value) => { const el = $(id); if (el) el.textContent = String(value); };
    const count = (status) => campaigns.filter((item) => item && item.status === status).length;
    put('totalCampaigns', campaigns.length);
    put('approvalCount', count('AWAITING_APPROVAL'));
    put('scheduledCount', count('SCHEDULED'));
    const warn = global.AiIdolApproval && global.AiIdolApproval.approvalWarning;
    const dueSoon = typeof warn === 'function' ? campaigns.filter((item) => item && warn(item)).length : 0;
    const stat = $('approvalCount') && $('approvalCount').closest('.studio-stat');
    if (stat) {
      stat.classList.toggle('is-alert', dueSoon > 0);
      stat.title = dueSoon ? `${dueSoon} chương trình sắp tới giờ phát nhưng chưa duyệt kịch bản` : '';
    }
  }

  on('studio:data', (event) => {
    const detail = event.detail || {};
    if (detail.error) return setSync(false);
    if (!Array.isArray(detail.campaigns)) return;
    renderStats(detail.campaigns);
    setSync(true);
  });

  // ------------------------------------------------------------------ public surface

  global.StudioCore = Object.freeze({
    root, $, api, escapeHtml, formatDate, normalize, statusName, badge, platformName,
    notify, emit, on, decisionsMarkup,
    health: () => latestHealth,
  });
}(typeof globalThis !== 'undefined' ? globalThis : this));
