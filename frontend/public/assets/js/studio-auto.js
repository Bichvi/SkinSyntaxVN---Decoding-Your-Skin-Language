/*
 * AI Idol Studio - tab Tự động.
 *
 * One prompt box + "Giao cho Syna", six sample chips, and the "Syna đã quyết định" card for the
 * current Agent run. The form markup lives in frontend/views/admin/ai_idol/index.php (#autoFlow).
 *
 *   listens  studio:data {campaigns, runs}      keeps the card current
 *            studio:open-item {kind:'run', id}  show that run in the card
 *            studio:prefill-prompt {brief}      fill the box and switch to this tab
 *   emits    studio:refresh {}                  after a run was created
 *            studio:show-flow {flow:'auto'}     on prefill
 */
(function (global) {
  'use strict';

  const core = global.StudioCore;
  if (!core || !global.document || !core.$('autoFlow')) return;
  const { $, api, escapeHtml, formatDate, notify, emit, on, decisionsMarkup, platformName } = core;
  const logic = global.StudioLogic || {};

  const CREATE_URL = 'index.php?r=admin_ai_idol_agent_create';
  const RUN_URL = 'index.php?r=admin_ai_idol_agent_run&run_id=';
  const MIN_LENGTH = 12;
  const MAX_LENGTH = 4000;
  const POLL_MS = 5000;
  const DONE_STATUSES = new Set(['COMPLETED', 'FAILED', 'CANCELLED', 'CAMPAIGN_CREATED', 'CAMPAIGN_CANCELLED', 'NEEDS_INPUT', 'NEEDS_ATTENTION']);
  const MAX_FAILURES = 5;
  const STALE_NOTE = 'Chưa cập nhật được trạng thái; bảng Hoạt động sẽ tiếp tục theo dõi.';

  const CHIPS = [
    ['Top bán chạy 30 ngày', 'Làm video sản phẩm cho 5 kem chống nắng bán chạy nhất trong 30 ngày qua, giọng tư vấn nhẹ nhàng, nhấn mạnh thành phần.'],
    ['Xả lô còn hạn', 'Livestream 30 phút lúc 20h tối mai bán 5 sản phẩm còn hạn sử dụng từ 17 đến 19 tháng, tồn kho trên 100 sản phẩm, giọng tập trung chốt sale.'],
    ['Xem nhiều, chưa mua', 'Làm video sản phẩm cho 5 sản phẩm được xem nhiều nhưng chưa chốt đơn trong 30 ngày qua, giải thích chi tiết.'],
    ['Giảm giá, bán chậm', 'Livestream 60 phút kèm video sản phẩm cho 5 sản phẩm đang giảm giá nhưng bán chậm trong 30 ngày qua, giọng tươi vui.'],
    ['Theo mã sản phẩm', 'Làm video giới thiệu sản phẩm mã 995 theo phong cách phân tích thành phần.'],
    ['Live có YouTube', 'Livestream 60 phút lúc 20h thứ Sáu giới thiệu 5 serum bán tốt trong 7 ngày qua, phát nội bộ và YouTube.'],
  ];

  const briefEl = $('autoBrief');
  const countEl = $('autoBriefCount');
  const msgEl = $('autoMessage');
  const submitEl = $('autoSubmit');
  const undoEl = $('autoUndo');
  const cardEl = $('autoCard');
  const chipsEl = $('autoChips');
  const form = $('autoForm');
  if (!briefEl || !form || !cardEl) return;

  // Only AUTO|MANUAL ever leave the browser; anything else falls back to AUTO.
  const approvalMode = () => {
    const picked = form.querySelector('input[name="autoApprovalMode"]:checked');
    return picked && picked.value === 'MANUAL' ? 'MANUAL' : 'AUTO';
  };

  let previousPrompt = '';
  let currentRun = null;
  let pinnedId = '';   // run chosen by creation / open-item; studio:data must not replace it with another
  let pollTimer = 0;
  let lastCard = '';
  let failures = 0;
  let staleNote = false;

  const asObject = (value) => (value && typeof value === 'object' ? value : null);
  const runId = (run) => String((run && (run._id || run.id)) || '');
  const isStopped = (run) => Boolean(run) && (
    (run.status === 'NEEDS_INPUT' && run.current_stage === 'AUTOPILOT_STOPPED')
    || (Array.isArray(run.decisions) && run.decisions.some((d) => d && d.level === 'stopped')));
  const isFinished = (run) => Boolean(run) && (DONE_STATUSES.has(run.status) || isStopped(run));

  // ------------------------------------------------------------------ prompt box

  function updateCount() { countEl.textContent = `${briefEl.value.length} / ${MAX_LENGTH}`; }
  briefEl.addEventListener('input', () => { updateCount(); notify(msgEl, ''); });

  function setPrompt(text) {
    if (briefEl.value.trim() && briefEl.value !== text) {
      previousPrompt = briefEl.value;
      undoEl.hidden = false;
    }
    briefEl.value = text;
    updateCount();
    notify(msgEl, '');
  }

  chipsEl.innerHTML = CHIPS.map(([label], index) => `<button type="button" class="au-chip" data-chip="${index}">${escapeHtml(label)}</button>`).join('');
  chipsEl.addEventListener('click', (event) => {
    const chip = event.target.closest('[data-chip]');
    if (!chip) return;
    setPrompt(CHIPS[Number(chip.dataset.chip)][1]);
    briefEl.focus();
  });
  undoEl.addEventListener('click', () => {
    briefEl.value = previousPrompt;
    undoEl.hidden = true;
    updateCount();
    briefEl.focus();
  });

  on('studio:prefill-prompt', (event) => {
    const brief = String((event.detail && event.detail.brief) || '');
    if (!brief) return;
    setPrompt(brief);
    emit('studio:show-flow', { flow: 'auto' });
    briefEl.focus();
  });

  // ------------------------------------------------------------------ submit

  form.addEventListener('submit', async (event) => {
    event.preventDefault();
    if (submitEl.disabled) return;
    const brief = briefEl.value.trim();
    if (brief.length < MIN_LENGTH) {
      notify(msgEl, `Mô tả cần ít nhất ${MIN_LENGTH} ký tự để Syna hiểu yêu cầu.`);
      briefEl.focus();
      return;
    }
    submitEl.disabled = true;
    notify(msgEl, '');
    try {
      const result = await api(CREATE_URL, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ brief, mode: 'autopilot', approval_mode: approvalMode(), idempotency_key: `studio:${Date.now()}` }),
      });
      const id = String(result.run_id || '');
      if (!id) throw new Error('Máy chủ không trả về mã lần chạy.');
      notify(msgEl, result.created === false ? 'Yêu cầu này đã được giao trước đó.' : 'Đã giao cho Syna. Kết quả hiện ở thẻ bên dưới.', true);
      pinnedId = id;
      failures = 0;
      staleNote = false;
      currentRun = { _id: id, brief, status: 'RECEIVED', decisions: [] };
      renderCard();
      emit('studio:refresh', {});
      pollRun();
    } catch (error) {
      notify(msgEl, error.message || 'Không giao được việc cho Syna.');
    } finally {
      submitEl.disabled = false;
    }
  });

  // ------------------------------------------------------------------ decision card

  function productsMarkup(run) {
    const products = run.plan && Array.isArray(run.plan.resolved_products) ? run.plan.resolved_products : [];
    const items = products.filter(Boolean).map((p) => {
      const id = p.product_id ?? p.id ?? '';
      const img = p.image ? `<img src="${escapeHtml(p.image)}" alt="" loading="lazy" width="36" height="42">` : '';
      const name = p.name || `Sản phẩm ${id}`;
      return `<li class="au-product"><span class="au-thumb">${img}</span><span class="au-product-name">${escapeHtml(name)}</span>${id !== '' ? `<span class="au-product-id">Mã ${escapeHtml(id)}</span>` : ''}</li>`;
    });
    if (!items.length) return '';
    return `<div class="au-section"><h3>Sản phẩm đã chọn (${items.length})</h3><ul class="au-products" role="list">${items.join('')}</ul></div>`;
  }

  function styleLabel(key) {
    const list = Array.isArray(logic.NARRATION_STYLES) ? logic.NARRATION_STYLES : [];
    const found = list.find((style) => style.key === key);
    return found ? found.label : String(key);
  }

  function factsMarkup(run) {
    const plan = asObject(run.plan) || {};
    const purposeLabels = logic.PURPOSE_LABELS || {};
    const purpose = purposeLabels[plan.purpose];
    const scheduled = run.campaign_scheduled_at || plan.scheduled_at;
    const platforms = run.campaign_platforms || plan.platforms;
    const pending = (Array.isArray(run.campaign_pending_external_platforms) && run.campaign_pending_external_platforms.length)
      ? run.campaign_pending_external_platforms
      : (plan.pending_external_platforms || run.pending_external_platforms);
    const styles = Array.isArray(plan.narration_styles) ? plan.narration_styles.map(styleLabel) : [];
    const rows = [];
    if (purpose) rows.push(['Mục đích', purpose]);
    if (scheduled) rows.push(['Giờ phát', formatDate(scheduled)]);
    if (Array.isArray(platforms) && platforms.length) rows.push(['Kênh', platforms.map(platformName).join(', ')]);
    if (Array.isArray(pending) && pending.length) rows.push(['Kênh chờ cho phép', pending.map(platformName).join(', ')]);
    if (styles.length) rows.push(['Phong cách', styles.join(', ')]);
    if (!rows.length) return '';
    return `<dl class="au-facts">${rows.map(([k, v]) => `<div><dt>${escapeHtml(k)}</dt><dd>${escapeHtml(v)}</dd></div>`).join('')}</dl>`;
  }

  function renderCard() {
    const run = currentRun;
    if (!run) { cardEl.hidden = true; return; }
    let body = decisionsMarkup(run.decisions);
    if (!body) {
      if (run.status === 'FAILED') body = `<p class="au-wait">Yêu cầu bị lỗi${run.error_message ? `: ${escapeHtml(run.error_message)}` : '.'}</p>`;
      else if (run.status === 'CANCELLED' || run.status === 'CAMPAIGN_CANCELLED') body = '<p class="au-wait">Đã hủy.</p>';
      else body = '<p class="au-wait"><i class="bi bi-hourglass-split" aria-hidden="true"></i> Syna đang đọc yêu cầu…</p>';
    }
    if (staleNote) body += `<p class="au-wait">${escapeHtml(STALE_NOTE)}</p>`;
    const edit = isStopped(run)
      ? '<div class="au-actions"><button type="button" class="idol-btn secondary" data-au-edit><i class="bi bi-pencil" aria-hidden="true"></i> Sửa prompt</button></div>'
      : '';
    const html = '<div class="panel-heading"><div><span class="studio-eyebrow">SYNA ĐÃ QUYẾT ĐỊNH</span><h2>Kết quả lần giao việc gần nhất</h2></div></div>'
      + `<div class="au-card-body"><p class="au-brief"><strong>Yêu cầu:</strong> ${escapeHtml(run.brief || '')}</p>${body}${productsMarkup(run)}${factsMarkup(run)}${edit}</div>`;
    if (html === lastCard) return; // keep the live region quiet while nothing changed
    lastCard = html;
    cardEl.innerHTML = html;
    cardEl.hidden = false;
  }

  cardEl.addEventListener('click', (event) => {
    if (!event.target.closest('[data-au-edit]') || !currentRun) return;
    setPrompt(String(currentRun.brief || ''));
    briefEl.focus();
  });

  // ------------------------------------------------------------------ keeping the card current

  function show(run) { currentRun = run; renderCard(); }

  async function fetchRun(id) {
    const result = await api(RUN_URL + encodeURIComponent(id));
    return asObject(result.data) || asObject(result.run);
  }

  // Poll the one run until it finishes. Paused while the tab or the panel is hidden; studio:data supersedes it.
  const panelVisible = () => !global.document.hidden && !$('autoFlow').hidden;
  function pollRun() {
    global.clearTimeout(pollTimer);
    const id = pinnedId;
    if (!id || !panelVisible()) return; // resumed by visibilitychange / studio:show-flow
    pollTimer = global.setTimeout(async () => {
      let run = null;
      try { run = await fetchRun(id); failures = 0; } catch { failures += 1; }
      if (id !== pinnedId) return;
      if (run) show(run);
      if (failures >= MAX_FAILURES) { staleNote = true; renderCard(); return; }
      if (!isFinished(run)) pollRun();
    }, POLL_MS);
  }
  function resumePolling() {
    if (pinnedId && !isFinished(currentRun) && failures < MAX_FAILURES) pollRun();
  }
  global.document.addEventListener('visibilitychange', resumePolling);
  on('studio:show-flow', () => global.setTimeout(resumePolling, 0));

  on('studio:open-item', async (event) => {
    const detail = event.detail || {};
    if (detail.kind !== 'run' || !detail.id) return;
    const id = String(detail.id);
    pinnedId = id;
    failures = 0;
    staleNote = false;
    global.clearTimeout(pollTimer);
    try {
      const run = await fetchRun(id);
      if (id !== pinnedId || !run) return;
      show(run);
      if (!isFinished(run)) pollRun();
    } catch { /* the Activity column reports its own errors */ }
  });

  on('studio:data', (event) => {
    const detail = event.detail || {};
    if (detail.error || !Array.isArray(detail.runs)) return;
    if (pinnedId) {
      const fresh = detail.runs.find((run) => runId(run) === pinnedId);
      if (fresh) { global.clearTimeout(pollTimer); staleNote = false; show(fresh); }
      return;
    }
    const latest = detail.runs.find((run) => run && run.mode === 'autopilot');
    if (latest) show(latest);
  });

  updateCount();
}(typeof globalThis !== 'undefined' ? globalThis : this));
