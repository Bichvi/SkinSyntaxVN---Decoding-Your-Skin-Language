/*
 * AI Idol Studio - Bảng Hoạt động (the column shared by the Tự động and Thủ công tabs).
 *
 * The only place that polls: admin_ai_idol_campaigns + admin_ai_idol_agent_runs are merged into one
 * list of cards (StudioLogic.mergeActivity), filtered (Tất cả / Cần bạn / Đã lên lịch) and opened in a
 * detail block under the list. The markup shell lives in frontend/views/admin/ai_idol/index.php (#activityPanel).
 *
 *   emits    studio:data {campaigns, runs}      after every poll; {error} when a poll failed
 *            studio:prefill-prompt {brief}      "Dùng lại prompt" on a run
 *   listens  studio:refresh {}                  reload now
 *            studio:open-item {kind, id}        kind 'campaign' | 'run': open it in the detail block
 *   reads    ?campaign_id=<id> on load          the notification bell links here
 *
 * Poll: 5 s while any card has an active progress step, else 30 s; stopped while the tab is hidden.
 *
 * The file loads in the browser through a <script> tag and in Node (module.exports) for the DOM-free
 * helpers, which is how studio-activity.test.js reaches them.
 */
(function (global) {
  'use strict';

  const inNode = typeof module === 'object' && module.exports;
  const core = global.StudioCore || (inNode ? require('./studio-core.js') : null);
  const logic = global.StudioLogic || (inNode ? require('./studio-logic.js') : null);
  if (!core || !logic) return;
  const { escapeHtml, formatDate, statusName, badge, platformName, decisionsMarkup } = core;

  const POLL_ACTIVE_MS = 5000;
  const POLL_IDLE_MS = 30000;
  const LOCAL_PLATFORMS = ['internal', 'preview'];
  // A pending external channel can still be allowed while the show has not started (mirrors StudioLogic.needsYou).
  const PENDING_STATUSES = ['VALIDATING', 'GENERATING', 'AWAITING_APPROVAL', 'NEEDS_ATTENTION', 'SCHEDULED'];
  const CANCELLABLE = ['VALIDATING', 'GENERATING', 'AWAITING_APPROVAL', 'NEEDS_ATTENTION', 'SCHEDULED'];
  const PREVIEW_STATUSES = ['READY', 'SCHEDULED', 'STARTING', 'LIVE', 'COMPLETED', 'PARTIAL_FAILURE'];
  const RUN_FINISHED = ['COMPLETED', 'RESULTS_READY', 'EMPTY_RESULTS', 'DATA_UNAVAILABLE', 'CANCELLED', 'CAMPAIGN_CANCELLED'];
  const RUN_RETRYABLE = ['FAILED', 'NEEDS_ATTENTION'];
  const STEP_STATE_TEXT = { done: 'đã xong', active: 'đang làm', blocked: 'cần bạn', todo: 'chưa tới' };
  const PAGE_BLOCK_REASONS = {
    missing_price_marker: 'Kịch bản thiếu mốc [CTA] nên không tách được phần giới thiệu cho trang sản phẩm.',
    body_cut_failed: 'Không cắt được phần giới thiệu cho trang sản phẩm.',
  };
  const MIN_SCRIPT_LENGTH = 40;
  const MIN_RESCHEDULE_MS = 2 * 60 * 1000;
  const VN_OFFSET = '+07:00'; // Asia/Ho_Chi_Minh has no daylight saving time

  // ------------------------------------------------------------ pure helpers (no DOM)

  const asArray = (value) => (Array.isArray(value) ? value : []);
  const isObject = (value) => value !== null && typeof value === 'object';
  const idText = (value) => (isObject(value) && typeof value.$oid === 'string' ? value.$oid : String(value ?? ''));

  // 5 s while something is moving, 30 s while everything is waiting or finished.
  function pollDelay(items) {
    const busy = asArray(items).some((item) => logic.progressSteps(item).some((step) => step.state === 'active'));
    return busy ? POLL_ACTIVE_MS : POLL_IDLE_MS;
  }

  // The external channels still waiting for the staff's permission (campaign document, list row or run view).
  function pendingPlatformsOf(item) {
    const source = isObject(item) ? item : {};
    const campaign = isObject(source.campaign) ? source.campaign : source;
    const run = isObject(source.run) ? source.run : source;
    const list = asArray(campaign.pending_external_platforms).length
      ? campaign.pending_external_platforms
      : run.campaign_pending_external_platforms;
    return asArray(list).filter((value) => typeof value === 'string' && value);
  }

  const canApprovePlatform = (status) => PENDING_STATUSES.includes(String(status || '').toUpperCase());

  const isInternalOnly = (platforms) => Array.isArray(platforms) && platforms.length > 0 && platforms.every((value) => LOCAL_PLATFORMS.includes(value));
  // A LIVE internal-only programme is watched inside the Studio, not on an outside platform.
  const liveBadge = (status, platforms) => (status === 'LIVE' && isInternalOnly(platforms) ? badge(status, 'Đang phát nội bộ') : badge(status));

  // "Trang SP" state of one segment, from its ai_product_videos record (`publication`, spec D10);
  // null = the segment carries no such field, show nothing.
  function segmentPageState(segment) {
    if (!isObject(segment)) return null;
    const page = isObject(segment.product_page) ? segment.product_page : null;
    if (page && page.eligible === false) return { text: 'Không đăng lên trang sản phẩm (chỉ livestream).', live: false, auto: false };
    if (page && page.price_state) {
      const auto = isObject(segment.publication) && segment.publication.source === 'auto';
      return { text: 'Giá trong video không còn đúng, video đã tự ẩn khỏi trang sản phẩm. Hãy tạo video mới.', live: false, auto };
    }
    if (!('publication' in segment)) return null;
    const publication = segment.publication;
    if (!isObject(publication)) return { text: 'Chưa có bản xuất bản', live: false, auto: false };
    const auto = publication.source === 'auto';
    if (publication.is_published) return { text: 'Đã đăng trang SP', live: true, auto };
    return { text: 'Chưa đăng', live: false, auto };
  }

  const publishedCount = (segments) => asArray(segments).filter((segment) => isObject(segment) && isObject(segment.publication) && segment.publication.is_published).length;

  function publicationUrl(segment) {
    const page = isObject(segment) && isObject(segment.product_page) ? segment.product_page : {};
    const videoId = idText(page.video_id || (isObject(segment) ? segment.video_id : ''));
    if (!isObject(segment) || segment.status !== 'READY' || !videoId || page.eligible === false) return '';
    return `index.php?r=admin_ai_product_videos&video_id=${encodeURIComponent(videoId)}`;
  }

  // datetime-local value in Asia/Ho_Chi_Minh, whatever the browser time zone is.
  function toDatetimeLocal(value) {
    const date = new Date(value);
    if (!value || Number.isNaN(date.getTime())) return '';
    const parts = {};
    new Intl.DateTimeFormat('en-GB', {
      timeZone: 'Asia/Ho_Chi_Minh', year: 'numeric', month: '2-digit', day: '2-digit', hour: '2-digit', minute: '2-digit', hourCycle: 'h23',
    }).formatToParts(date).forEach((part) => { parts[part.type] = part.value; });
    return `${parts.year}-${parts.month}-${parts.day}T${parts.hour}:${parts.minute}`;
  }

  // Milliseconds of a datetime-local value read as Asia/Ho_Chi_Minh; NaN when it is empty or invalid.
  const vnTime = (value) => (/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}/.test(String(value || '')) ? Date.parse(`${String(value).slice(0, 16)}:00${VN_OFFSET}`) : NaN);

  function stepsMarkup(item) {
    const steps = logic.progressSteps(item);
    if (!steps.length) return '';
    return `<ol class="act-steps" aria-label="Tiến độ">${steps.map((step) => (
      `<li class="is-${escapeHtml(step.state)}" aria-label="${escapeHtml(step.label)}: ${STEP_STATE_TEXT[step.state] || ''}"><span class="act-step-dot" aria-hidden="true"></span><span class="act-step-label">${escapeHtml(step.label)}</span></li>`
    )).join('')}</ol>`;
  }

  function approvalAlertMarkup(warning, compact) {
    if (!warning) return '';
    const level = escapeHtml(warning.level);
    if (compact) return `<span class="campaign-alert is-${level}"><i class="bi bi-alarm" aria-hidden="true"></i>${escapeHtml(warning.short)}</span>`;
    return `<div class="approval-alert is-${level}"><i class="bi bi-alarm" aria-hidden="true"></i><p><strong>${escapeHtml(warning.short)}</strong>${escapeHtml(warning.text)}</p></div>`;
  }

  function cardMarkup(item, { active = false, warning = null } = {}) {
    const campaign = item.campaign;
    const run = item.run;
    const source = campaign || run || {};
    const platforms = campaign ? campaign.platforms : run && run.campaign_platforms;
    const status = item.status;
    const meta = [];
    if (campaign) {
      const purpose = logic.PURPOSE_LABELS[campaign.purpose] || logic.PURPOSE_LABELS.live_only;
      const total = Number(campaign.progress && campaign.progress.total) || asArray(campaign.product_ids).length;
      meta.push(purpose);
      if (total) meta.push(`${total} SP`);
      if (campaign.scheduled_at) meta.push(formatDate(campaign.scheduled_at));
    } else {
      meta.push('Yêu cầu chưa thành chương trình');
      if (source.updated_at) meta.push(formatDate(source.updated_at, ''));
    }
    const pending = pendingPlatformsOf(item).filter(() => canApprovePlatform(status));
    const pendingChip = pending.length ? `<span class="act-chip is-waiting"><i class="bi bi-lock" aria-hidden="true"></i>Chờ cho phép ${escapeHtml(pending.map(platformName).join(', '))}</span>` : '';
    const needs = logic.needsYou(item) ? '<span class="act-chip is-needs"><i class="bi bi-person-raised-hand" aria-hidden="true"></i>Cần bạn</span>' : '';
    return `<li><button type="button" class="act-card${active ? ' is-active' : ''}" data-act-kind="${escapeHtml(item.kind)}" data-act-id="${escapeHtml(item.id)}" aria-pressed="${active}">`
      + `<span class="act-card-top"><span class="act-card-title">${escapeHtml(item.title)}</span>${liveBadge(status, platforms)}</span>`
      + `<span class="act-card-meta"><span class="act-tag is-${item.source}">${item.source === 'auto' ? 'Tự động' : 'Thủ công'}</span>${meta.map((part) => `<span>${escapeHtml(part)}</span>`).join('')}</span>`
      + `${stepsMarkup(item)}${approvalAlertMarkup(warning, true)}${needs}${pendingChip}</button></li>`;
  }

  const helpers = {
    publishedCount, pollDelay, pendingPlatformsOf, canApprovePlatform, segmentPageState, publicationUrl, toDatetimeLocal, vnTime, stepsMarkup, cardMarkup, isInternalOnly,
  };
  if (inNode) module.exports = helpers;

  // ------------------------------------------------------------------ browser only

  const doc = global.document;
  if (!core.root || !doc || !core.$('activityPanel') || !core.$('activityList')) return;
  const { $, api, notify, emit, on } = core;
  const approvalWarning = (global.AiIdolApproval && global.AiIdolApproval.approvalWarning) || (() => null);

  const CAMPAIGNS_URL = 'index.php?r=admin_ai_idol_campaigns&limit=30';
  const RUNS_URL = 'index.php?r=admin_ai_idol_agent_runs&limit=30';
  const campaignUrl = (id) => `index.php?r=admin_ai_idol_campaign&campaign_id=${encodeURIComponent(id)}`;
  const routeUrl = (route, key, id) => `index.php?r=${route}&${key}=${encodeURIComponent(id)}`;
  const JSON_HEADERS = { 'Content-Type': 'application/json' };

  const listEl = $('activityList');
  const detailEl = $('activityDetail');
  const bodyEl = $('activityDetailBody');
  const messageEl = $('activityDetailMessage');
  const liveEl = $('activityLive');
  const refreshEl = $('activityRefresh');
  const filterButtons = [...doc.querySelectorAll('[data-act-filter]')];
  if (!detailEl || !bodyEl) return;

  let campaigns = [];
  let runs = [];
  let items = [];
  let loaded = false;
  let filter = 'all';
  let selected = null; // {kind: 'campaign'|'run', id}
  let detailDoc = null; // admin_ai_idol_campaign document of the selected campaign
  let pendingOpen = null; // asked to open something the list has not shown yet
  let busy = false;
  let again = false;
  let pollTimer = 0;
  let lastListHtml = '';
  let lastDetailHtml = '';
  let lastDetailKey = '';
  let lastLive = '';
  const knownStatus = new Map();
  let knownNeeds = -1;
  const detailGate = logic.createLatestGate();
  const scriptDrafts = new Map();
  const approvingJobs = new Set();
  const busyActions = new Set(); // '<campaign id>:platform:youtube', '<campaign id>:campaign:cancel', 'run:<id>:retry' ... while in flight
  const busyKey = (id, name) => `${id}:${name}`;
  const toggled = new Map(); // <details> the staff opened or closed by hand, by data-key
  const reduceMotion = () => Boolean(global.matchMedia && global.matchMedia('(prefers-reduced-motion: reduce)').matches);

  // ------------------------------------------------------------------ list

  const itemKey = (item) => `${item.kind}:${item.id}`;
  const selectedKey = () => (selected ? `${selected.kind}:${selected.id}` : '');
  function findItem(sel) {
    if (!sel) return null;
    return items.find((item) => item.kind === sel.kind && item.id === sel.id) || null;
  }

  const EMPTY_TEXT = {
    all: ['collection-play', 'Chưa có hoạt động nào.<br>Giao việc cho Syna hoặc tạo chương trình thủ công.'],
    needs_you: ['check2-circle', 'Không có việc nào cần bạn lúc này.'],
    scheduled: ['calendar2-check', 'Chưa có chương trình nào được lên lịch.'],
  };

  function renderFilters() {
    const counts = {
      all: items.length,
      needs_you: logic.filterActivity(items, 'needs_you').length,
      scheduled: logic.filterActivity(items, 'scheduled').length,
    };
    filterButtons.forEach((button) => {
      const key = button.dataset.actFilter;
      const active = key === filter;
      button.classList.toggle('is-active', active);
      button.setAttribute('aria-pressed', String(active));
      const count = button.querySelector('.act-count');
      if (count) {
        count.textContent = String(counts[key] ?? 0);
        count.classList.toggle('is-alert', key === 'needs_you' && counts.needs_you > 0);
      }
    });
    return counts;
  }

  function renderList() {
    renderFilters();
    let html;
    if (!loaded) {
      html = `<p class="act-empty" role="status">${lastError ? escapeHtml(lastError) : 'Đang tải hoạt động…'}</p>`;
    } else {
      const visible = logic.filterActivity(items, filter);
      if (!visible.length) {
        const [icon, text] = EMPTY_TEXT[filter] || EMPTY_TEXT.all;
        html = `<div class="empty compact"><i class="bi bi-${icon}" aria-hidden="true"></i><p>${text}</p></div>`;
      } else {
        const key = selectedKey();
        html = `<ul class="act-cards" role="list">${visible.map((item) => cardMarkup(item, {
          active: key === itemKey(item),
          warning: item.campaign ? approvalWarning(item.campaign) : null,
        })).join('')}</ul>`;
      }
    }
    if (html === lastListHtml) return; // keep keyboard focus and scroll on unchanged polls
    lastListHtml = html;
    const focused = doc.activeElement && doc.activeElement.closest ? doc.activeElement.closest('.act-card') : null;
    const focusKey = focused ? `${focused.dataset.actKind}:${focused.dataset.actId}` : '';
    listEl.innerHTML = html;
    listEl.setAttribute('aria-busy', String(!loaded));
    if (focusKey) {
      const again2 = [...listEl.querySelectorAll('.act-card')].find((button) => `${button.dataset.actKind}:${button.dataset.actId}` === focusKey);
      if (again2) again2.focus({ preventScroll: true });
    }
  }

  // The live region is polite: say something only when the Cần bạn count or a card's status changed.
  function announce(text) {
    if (!liveEl || !text || text === lastLive) return;
    lastLive = text;
    liveEl.textContent = text;
  }

  function trackChanges(counts) {
    const changes = [];
    items.forEach((item) => {
      const key = itemKey(item);
      if (loaded && knownStatus.has(key) && knownStatus.get(key) !== item.status) changes.push(`${item.title}: ${statusName(item.status)}`);
      knownStatus.set(key, item.status);
    });
    const needsChanged = knownNeeds !== counts.needs_you && !(knownNeeds === -1 && counts.needs_you === 0);
    if (needsChanged) changes.unshift(counts.needs_you ? `Có ${counts.needs_you} việc cần bạn.` : 'Không còn việc nào cần bạn.');
    knownNeeds = counts.needs_you;
    if (changes.length) announce(changes.slice(0, 4).join(' '));
  }

  // ------------------------------------------------------------------ detail markup

  const detailMessage = (text, success = false) => notify(messageEl, text, success);

  function segmentMarkup(segment, names) {
    const productId = String(segment.product_id ?? '');
    const title = names.get(productId) || `Sản phẩm ${productId}`;
    const jobId = String(segment.job_id || '');
    if (segment.status === 'SKIPPED') {
      const reason = String(segment.error_message || '').trim();
      return `<li class="segment is-skipped"><div class="segment-heading"><span>${escapeHtml(title)}</span>${badge('SKIPPED', 'Bỏ qua')}</div>${reason ? `<p class="act-skip-reason">Lý do: ${escapeHtml(reason)}</p>` : ''}</li>`;
    }
    const errorText = String(segment.error_message || '');
    const errorSummary = errorText.includes('CUDA out of memory') ? 'GPU hết bộ nhớ khi tạo video.' : (errorText.split('\n')[0] || '').slice(0, 180);
    const errorMarkup = errorText ? `<details class="segment-error"><summary>${escapeHtml(errorSummary)}</summary><pre>${escapeHtml(errorText)}</pre></details>` : '';
    // A draft the validator rejected is kept so staff can fix the flagged claims and approve it.
    const rejected = segment.script_status === 'REJECTED_BY_VALIDATOR' && Boolean(segment.rejected_script_text);
    const awaiting = segment.status === 'AWAITING_APPROVAL' || segment.script_status === 'PENDING_APPROVAL' || rejected;
    const scriptText = String(awaiting && scriptDrafts.has(jobId) ? scriptDrafts.get(jobId) : (segment.script_text || (rejected ? segment.rejected_script_text : '')));
    const feedback = rejected
      ? `<div class="agent-warning script-feedback"><strong>Kịch bản bị từ chối:</strong> ${escapeHtml(segment.validation_feedback || 'Có nội dung vi phạm quy chế quảng cáo.')}<br>Sửa lại các câu bị nêu rồi bấm chấp nhận; hệ thống sẽ kiểm tra lại trước khi tạo video.</div>`
      : '';
    const detailKey = `script:${jobId}`;
    const open = toggled.has(detailKey) ? toggled.get(detailKey) : awaiting;
    const sending = approvingJobs.has(jobId);
    const scriptMarkup = scriptText
      ? `<details class="script-review" data-key="${escapeHtml(detailKey)}"${open ? ' open' : ''}><summary>${rejected ? 'Sửa kịch bản bị từ chối' : awaiting ? 'Chỉnh sửa & duyệt kịch bản' : 'Xem kịch bản đã duyệt'}</summary>${feedback}`
        + `<label for="script-${escapeHtml(jobId)}">${escapeHtml(title)}</label><textarea class="script-editor" data-job="${escapeHtml(jobId)}" id="script-${escapeHtml(jobId)}"${awaiting ? '' : ' readonly'}>${escapeHtml(scriptText)}</textarea>`
        + `<p class="script-review-note">${awaiting ? 'Sửa trực tiếp lời giới thiệu. Giọng nói và video chỉ được tạo sau khi bạn chấp nhận.' : 'Kịch bản đã duyệt cho video này.'}</p>`
        + `${awaiting ? `<button type="button" class="idol-btn primary" data-approve-script="${escapeHtml(jobId)}"${sending ? ' disabled' : ''}>${sending ? 'Đang gửi duyệt…' : 'Chấp nhận & tạo video'}</button>` : ''}</details>`
      : '';
    const page = segmentPageState(segment);
    const link = publicationUrl(segment);
    const pageMarkup = page || link
      ? `<div class="segment-page${page && page.live ? ' is-live' : ''}">${page ? `<span><i class="bi bi-shop" aria-hidden="true"></i> ${escapeHtml(page.text)}${page.auto ? ' <span class="act-tag is-auto">Tự động</span>' : ''}</span>` : ''}${link ? `<a class="idol-btn secondary" href="${escapeHtml(link)}">Quản lý xuất bản</a>` : ''}</div>`
      : '';
    return `<li class="segment"><div class="segment-heading"><span>${escapeHtml(title)}</span>${badge(segment.status)}</div>${errorMarkup}${pageMarkup}${scriptMarkup}</li>`;
  }

  function broadcastMarkup(campaign) {
    const broadcasts = asArray(campaign.broadcasts);
    const preflight = isObject(campaign.preflight) ? campaign.preflight : null;
    if (!broadcasts.length && !preflight) return '';
    const rows = broadcasts.map((row) => `<tr><td>${escapeHtml(platformName(row.platform))}</td><td>${badge(row.status)}</td><td>${escapeHtml(row.error_message || (row.ends_at ? `Đến ${formatDate(row.ends_at)}` : ''))}</td></tr>`).join('');
    const table = rows ? `<table class="broadcast-table"><thead><tr><th scope="col">Nơi phát</th><th scope="col">Trạng thái</th><th scope="col">Chi tiết</th></tr></thead><tbody>${rows}</tbody></table>` : '';
    const errors = asArray(preflight && preflight.errors);
    const check = preflight
      ? `<p class="broadcast-preflight ${preflight.ok ? 'is-ok' : 'is-bad'}"><strong>Kiểm tra trước phát:</strong> ${preflight.ok ? 'đạt' : 'chưa đạt'}${errors.length ? ` · ${errors.map(escapeHtml).join('; ')}` : ''}${preflight.checked_at ? `<small> (${escapeHtml(formatDate(preflight.checked_at))})</small>` : ''}</p>`
      : '';
    return `<section class="broadcast-panel">${table}${check}</section>`;
  }

  function pendingMarkup(campaign) {
    const pending = pendingPlatformsOf(campaign);
    if (!pending.length) return '';
    const allowed = canApprovePlatform(campaign.status);
    const rows = pending.map((platform) => {
      const name = platformName(platform);
      const sending = busyActions.has(busyKey(campaign._id, `platform:${platform}`));
      const control = allowed
        ? `<button type="button" class="idol-btn primary" data-approve-platform="${escapeHtml(platform)}"${sending ? ' disabled' : ''}>${sending ? 'Đang gửi…' : `Cho phép phát ${escapeHtml(name)}`}</button>`
        : '<span class="act-expired">Đã hết hạn cho phép</span>';
      return `<li><span class="act-pending-name"><i class="bi bi-broadcast" aria-hidden="true"></i>${escapeHtml(name)}</span>${control}</li>`;
    }).join('');
    return `<section class="act-pending" aria-label="Kênh chờ cho phép"><h4>Kênh chờ bạn cho phép</h4><ul role="list">${rows}</ul></section>`;
  }

  function controlsMarkup(campaign) {
    const status = campaign.status;
    const id = campaign._id;
    const sending = (name) => busyActions.has(busyKey(id, `campaign:${name}`));
    const label = (name, text) => (sending(name) ? 'Đang gửi…' : text);
    const off = (name) => (sending(name) ? ' disabled' : '');
    const retry = status === 'NEEDS_ATTENTION'
      ? `<div class="detail-actions"><button type="button" class="idol-btn warn" id="actRetryCampaign"${off('retry')}>${label('retry', 'Tạo lại phần bị lỗi')}</button></div>`
      : '';
    if (!CANCELLABLE.includes(status)) return retry;
    const canAirNow = isInternalOnly(campaign.platforms) && campaign.master_video_path && ['SCHEDULED', 'NEEDS_ATTENTION'].includes(status);
    const reschedule = `<div class="agent-input"><label for="actRescheduleAt">Đổi lịch</label><input id="actRescheduleAt" class="idol-input" type="datetime-local" value="${escapeHtml(toDatetimeLocal(campaign.scheduled_at))}"><button type="button" class="idol-btn secondary" id="actRescheduleCampaign"${off('reschedule')}>${label('reschedule', 'Đổi lịch')}</button></div>`;
    return `${retry}<div class="campaign-controls">${reschedule}<div class="detail-actions">${canAirNow ? `<button type="button" class="idol-btn primary" id="actAirNow"${off('air')}>${label('air', 'Phát nội bộ ngay')}</button>` : ''}<button type="button" class="idol-btn secondary danger" id="actCancelCampaign"${off('cancel')}>${label('cancel', 'Hủy chương trình')}</button></div></div>`;
  }

  function productNames(run) {
    const plan = isObject(run && run.plan) ? run.plan : {};
    const names = new Map();
    [...asArray(plan.resolved_products), ...asArray(plan.candidate_products)].forEach((product) => {
      if (isObject(product) && product.product_id != null && product.name) names.set(String(product.product_id), String(product.name));
    });
    return names;
  }

  function headingMarkup(eyebrow, statusBadge) {
    return `<div class="detail-heading"><span class="studio-eyebrow">${escapeHtml(eyebrow)}</span><span class="act-heading-end">${statusBadge}<button type="button" class="icon-button" data-act-close aria-label="Đóng chi tiết" title="Đóng"><i class="bi bi-x-lg" aria-hidden="true"></i></button></span></div>`;
  }

  function campaignDetailMarkup(campaign, item) {
    const run = item ? item.run : null;
    const view = { kind: 'campaign', campaign, run, status: String(campaign.status || '').toUpperCase() };
    const status = view.status;
    const configuration = isObject(campaign.configuration) ? campaign.configuration : {};
    const auto = Boolean(configuration.agent_managed);
    const warning = approvalWarning(campaign);
    const canPreview = Boolean(campaign.master_video_path) && PREVIEW_STATUSES.includes(status);
    const internalLive = status === 'LIVE' && isInternalOnly(campaign.platforms) && Boolean(campaign.started_at);
    const liveNote = internalLive ? ` · đang phát nội bộ${campaign.ends_at ? `, kết thúc ${formatDate(campaign.ends_at)}` : ''}` : '';
    const waiting = status === 'VIDEOS_READY' ? 'Video sản phẩm đã sẵn sàng. Trạng thái trên trang sản phẩm nằm ở từng sản phẩm bên dưới.'
      : status === 'AWAITING_APPROVAL' ? 'Kịch bản đã sẵn sàng. Duyệt bên dưới để bắt đầu tạo video.'
        : ['NEEDS_ATTENTION', 'FAILED'].includes(status) ? 'Video chưa hoàn tất. Xem phần cần kiểm tra bên dưới.'
          : status === 'CANCELLED' ? 'Chương trình này đã được hủy.' : 'Video đang được chuẩn bị. Tiến độ sẽ tự cập nhật.';
    const avatar = String(configuration.avatar_asset || '');
    const motion = /\.(mp4|webm)$/i.test(avatar);
    const character = configuration.avatar_mode === 'syna_3d' ? 'Syna 3D · giọng đọc đã duyệt, sản phẩm thật và bảng thành phần.' : motion ? 'Nhân vật từ video · giữ chuyển động có sẵn trong clip.' : 'Nhân vật từ ảnh tĩnh · miệng và đầu chuyển động nhẹ.';
    const names = productNames(run);
    const livePages = publishedCount(campaign.segments);
    const purpose = logic.PURPOSE_LABELS[campaign.purpose] || logic.PURPOSE_LABELS.live_only;
    const meta = campaign.scheduled_at ? `Lịch phát ${formatDate(campaign.scheduled_at)}${liveNote}` : `${purpose} · không phát sóng`;
    const decisions = run ? decisionsMarkup(run.decisions) : '';
    const segments = asArray(campaign.segments);
    return `${headingMarkup(auto ? 'TỰ ĐỘNG · CHI TIẾT CHƯƠNG TRÌNH' : 'THỦ CÔNG · CHI TIẾT CHƯƠNG TRÌNH', liveBadge(status, campaign.platforms))}`
      + `<h3 id="actDetailTitle">${escapeHtml(campaign.name || 'Chương trình chưa đặt tên')}</h3>`
      + `<p class="campaign-meta">${escapeHtml(meta)}${livePages ? ` · ${livePages} video đang hiện trên trang sản phẩm` : ''}</p>`
      + `${stepsMarkup(view)}${approvalAlertMarkup(warning, false)}`
      + `${campaign.error_message ? `<div class="studio-message" role="alert">${escapeHtml(campaign.error_message)}</div>` : ''}`
      + `${pendingMarkup(campaign)}`
      + (canPreview
        ? `<video id="actPreview" controls playsinline preload="metadata" aria-label="Video chương trình" src="index.php?r=admin_ai_idol_stream_campaign&campaign_id=${encodeURIComponent(campaign._id)}"></video>`
        : `<div class="empty compact"><i class="bi bi-${status === 'AWAITING_APPROVAL' ? 'pencil-square' : 'camera-reels'}" aria-hidden="true"></i><p>${waiting}</p></div>`)
      + `<div class="avatar-mode-note ${motion || configuration.avatar_mode === 'syna_3d' ? 'motion' : 'photo'}">${escapeHtml(character)}</div>`
      + `${segments.length ? `<ul class="act-segments" role="list" aria-label="Các sản phẩm trong chương trình">${segments.map((segment) => segmentMarkup(segment, names)).join('')}</ul>` : ''}`
      + `${broadcastMarkup(campaign)}`
      + `${decisions ? `<section class="act-decisions"><h4>Syna đã quyết định</h4>${decisions}</section>` : ''}`
      + `${controlsMarkup(campaign)}`;
  }

  function runDetailMarkup(item) {
    const run = item.run;
    const status = item.status;
    const plan = isObject(run.plan) ? run.plan : {};
    const warnings = asArray(plan.warnings).map(String).filter(Boolean);
    const decisions = decisionsMarkup(run.decisions);
    const note = run.error_message
      ? `<div class="studio-message" role="alert">${escapeHtml(run.error_message)}</div>`
      : warnings.length ? warnings.map((text) => `<div class="agent-warning">${escapeHtml(text)}</div>`).join('') : '';
    const legacy = !run.mode && status === 'NEEDS_INPUT'
      ? '<p class="agent-warning">Yêu cầu kiểu cũ cần xác nhận thêm. Hãy dùng lại prompt ở tab Tự động, hoặc hủy yêu cầu này.</p>' : '';
    const id = String(run._id || item.id);
    const retry = RUN_RETRYABLE.includes(status)
      ? `<button type="button" class="idol-btn warn" data-run-retry="${escapeHtml(id)}"${busyActions.has(`run:${id}:retry`) ? ' disabled' : ''}>Thử lại</button>` : '';
    const cancel = !RUN_FINISHED.includes(status)
      ? `<button type="button" class="idol-btn secondary danger" data-run-cancel="${escapeHtml(id)}"${busyActions.has(`run:${id}:cancel`) ? ' disabled' : ''}>Hủy</button>` : '';
    const reuse = run.brief ? '<button type="button" class="idol-btn secondary" data-run-prefill><i class="bi bi-arrow-repeat" aria-hidden="true"></i> Dùng lại prompt</button>' : '';
    return `${headingMarkup(run.mode === 'autopilot' ? 'TỰ ĐỘNG · YÊU CẦU' : 'YÊU CẦU', badge(status))}`
      + `<h3 id="actDetailTitle">${escapeHtml(statusName(status))}</h3>`
      + `<p class="campaign-meta">${escapeHtml(run.brief || '')}</p>`
      + `${stepsMarkup(item)}${note}${legacy}`
      + `${decisions ? `<section class="act-decisions"><h4>Syna đã quyết định</h4>${decisions}</section>` : ''}`
      + `<div class="detail-actions">${retry}${cancel}${reuse}</div>`;
  }

  // ------------------------------------------------------------------ detail rendering

  // Typing a script or a new schedule must survive a poll: do not rebuild the block under the cursor.
  function typingInside() {
    const active = doc.activeElement;
    return Boolean(active && bodyEl.contains(active) && active.matches('textarea:not([readonly]), #actRescheduleAt'));
  }

  function renderDetail() {
    if (!selected) {
      detailEl.hidden = true;
      lastDetailHtml = '';
      lastDetailKey = '';
      return;
    }
    detailEl.hidden = false;
    const key = selectedKey();
    const sameBlock = key === lastDetailKey;
    let html;
    if (selected.kind === 'campaign') {
      if (!detailDoc || String(detailDoc._id) !== selected.id) {
        if (sameBlock) return;
        html = '<p class="empty compact" role="status">Đang mở chương trình…</p>';
      } else {
        html = campaignDetailMarkup(detailDoc, findItem(selected) || items.find((item) => item.kind === 'campaign' && item.id === selected.id) || null);
      }
    } else {
      const item = findItem(selected);
      html = item && item.run ? runDetailMarkup(item) : '<p class="empty compact" role="status">Không tìm thấy yêu cầu này nữa.</p>';
    }
    if (html === lastDetailHtml && sameBlock) return;
    if (sameBlock && typingInside()) return; // try again on the next poll
    rememberDrafts();
    const oldVideo = sameBlock ? bodyEl.querySelector('video') : null;
    const oldSrc = oldVideo ? oldVideo.getAttribute('src') : '';
    const focusId = sameBlock && doc.activeElement && bodyEl.contains(doc.activeElement) ? doc.activeElement.id : '';
    bodyEl.innerHTML = html;
    lastDetailHtml = html;
    lastDetailKey = key;
    const video = bodyEl.querySelector('video');
    if (video) {
      // Reuse the playing node instead of resetting playback on every poll.
      if (oldVideo && oldSrc === video.getAttribute('src')) video.replaceWith(oldVideo);
      else if (detailDoc && detailDoc.status === 'LIVE' && isInternalOnly(detailDoc.platforms)) seekToLivePosition(video, detailDoc.started_at);
    }
    if (focusId) {
      const target = doc.getElementById(focusId);
      if (target) target.focus({ preventScroll: true });
    }
  }

  // Internal programmes loop the master video from the real start time, so every viewer sees the same moment.
  function seekToLivePosition(video, startedAt) {
    const started = new Date(startedAt).getTime();
    if (!Number.isFinite(started)) return;
    video.loop = true;
    video.addEventListener('loadedmetadata', () => {
      if (Number.isFinite(video.duration) && video.duration > 0) video.currentTime = ((Date.now() - started) / 1000) % video.duration;
      const played = video.play();
      if (played && played.catch) played.catch(() => {});
    }, { once: true });
  }

  function rememberDrafts() {
    bodyEl.querySelectorAll('.script-editor:not([readonly])').forEach((editor) => scriptDrafts.set(editor.dataset.job, editor.value));
  }

  async function loadDetail(id, { background = false } = {}) {
    const token = detailGate.next();
    if (!background) {
      detailDoc = null;
      renderDetail();
    }
    try {
      const result = await api(campaignUrl(id));
      if (!detailGate.isCurrent(token) || !selected || selected.kind !== 'campaign' || selected.id !== id) return;
      detailDoc = result.data || null;
      renderDetail();
    } catch (error) {
      if (!detailGate.isCurrent(token) || !selected || selected.id !== id) return;
      if (!detailDoc) {
        bodyEl.innerHTML = `<p class="empty compact" role="alert">${escapeHtml(error.message)}</p>`;
        lastDetailHtml = '';
        lastDetailKey = selectedKey();
      } else if (!background) detailMessage(error.message);
    }
  }

  // ------------------------------------------------------------------ selecting / opening

  function select(next, { scroll = false, focus = false } = {}) {
    const same = selected && next && selected.kind === next.kind && selected.id === next.id;
    selected = next;
    if (!same) {
      detailDoc = null;
      lastDetailHtml = '';
      lastDetailKey = '';
      toggled.clear();
      detailMessage('');
    }
    renderList();
    renderDetail();
    if (selected && selected.kind === 'campaign') loadDetail(selected.id, { background: same });
    if (selected && scroll) detailEl.scrollIntoView({ block: 'start', behavior: reduceMotion() ? 'auto' : 'smooth' });
    if (selected && focus) detailEl.focus({ preventScroll: true });
  }

  function closeDetail() {
    const key = selectedKey();
    detailGate.next();
    select(null);
    const card = [...listEl.querySelectorAll('.act-card')].find((button) => `${button.dataset.actKind}:${button.dataset.actId}` === key);
    if (card) card.focus({ preventScroll: true });
  }

  // kind 'run' may belong to a campaign (the card is then the campaign's); null = not known yet.
  function resolveTarget(kind, id) {
    if (kind === 'campaign') return { kind: 'campaign', id };
    const direct = items.find((item) => item.kind === 'run' && item.id === id);
    if (direct) return { kind: 'run', id };
    const owner = items.find((item) => item.kind === 'campaign' && item.run && idText(item.run._id) === id);
    if (owner) return { kind: 'campaign', id: owner.id };
    const run = runs.find((candidate) => idText(candidate._id) === id);
    return run && run.campaign_id ? { kind: 'campaign', id: idText(run.campaign_id) } : null;
  }

  function openItem(kind, id, options = {}) {
    if (!id || (kind !== 'campaign' && kind !== 'run')) return;
    const target = resolveTarget(kind, String(id));
    if (target) {
      pendingOpen = null;
      select(target, options);
      return;
    }
    pendingOpen = { kind, id: String(id), options, tries: 0 };
    refresh();
  }

  // ------------------------------------------------------------------ polling

  let lastError = '';

  function schedule() {
    global.clearTimeout(pollTimer);
    if (doc.hidden) return; // the visibilitychange handler reloads
    pollTimer = global.setTimeout(refresh, pollDelay(items));
  }

  async function refresh() {
    if (busy) { again = true; return; }
    busy = true;
    again = false;
    global.clearTimeout(pollTimer);
    if (refreshEl) { refreshEl.disabled = true; refreshEl.setAttribute('aria-busy', 'true'); }
    try {
      const [campaignResult, runResult] = await Promise.all([api(CAMPAIGNS_URL), api(RUNS_URL)]);
      campaigns = asArray(campaignResult.campaigns);
      runs = asArray(runResult.runs);
      items = logic.mergeActivity(campaigns, runs);
      lastError = '';
      loaded = true;
      const counts = renderFilters();
      trackChanges(counts);
      let reloadDetail = true;
      if (pendingOpen) {
        const target = resolveTarget(pendingOpen.kind, pendingOpen.id);
        pendingOpen.tries += 1;
        if (target) { const opts = pendingOpen.options; pendingOpen = null; select(target, opts); reloadDetail = false; } // select() loads it
        else if (pendingOpen.tries >= 2) pendingOpen = null;
      }
      renderList();
      if (selected && selected.kind === 'campaign' && reloadDetail) loadDetail(selected.id, { background: true });
      else renderDetail();
      emit('studio:data', { campaigns, runs });
    } catch (error) {
      lastError = error && error.message ? error.message : 'Không tải được hoạt động.';
      if (!loaded) renderList();
      emit('studio:data', { error: lastError });
    } finally {
      busy = false;
      if (refreshEl) { refreshEl.disabled = false; refreshEl.removeAttribute('aria-busy'); }
      if (again) refresh();
      else schedule();
    }
  }

  doc.addEventListener('visibilitychange', () => {
    if (doc.hidden) global.clearTimeout(pollTimer);
    else refresh();
  });

  // ------------------------------------------------------------------ actions

  async function send(route, key, id, body, lock) {
    busyActions.add(lock);
    lastDetailHtml = ''; // show the disabled button
    renderDetail();
    try {
      return await api(routeUrl(route, key, id), { method: 'POST', headers: JSON_HEADERS, body: JSON.stringify(body || {}) });
    } finally {
      busyActions.delete(lock);
      lastDetailHtml = '';
    }
  }

  async function afterChange() {
    lastDetailHtml = '';
    await refresh();
    if (selected && selected.kind === 'campaign') await loadDetail(selected.id, { background: true });
  }

  async function approvePlatform(platform) {
    const campaignId = selected && selected.kind === 'campaign' ? selected.id : '';
    if (!campaignId || busyActions.has(busyKey(campaignId, `platform:${platform}`))) return;
    detailMessage('');
    try {
      await send('admin_ai_idol_approve_platform', 'campaign_id', campaignId, { platform }, busyKey(campaignId, `platform:${platform}`));
      detailMessage(`Đã cho phép phát ${platformName(platform)}.`, true);
      await afterChange();
    } catch (error) {
      renderDetail();
      detailMessage(error.message); // 400 / 409 carry the server's explanation (expired, wrong state ...)
    }
  }

  async function approveScript(button) {
    const jobId = button.dataset.approveScript;
    const editor = doc.getElementById(`script-${jobId}`);
    const text = editor ? editor.value.trim() : '';
    if (text.length < MIN_SCRIPT_LENGTH) return detailMessage(`Kịch bản cần ít nhất ${MIN_SCRIPT_LENGTH} ký tự.`);
    if (approvingJobs.has(jobId)) return undefined;
    approvingJobs.add(jobId);
    scriptDrafts.set(jobId, editor.value);
    lastDetailHtml = '';
    renderDetail(); // the button shows "Đang gửi duyệt…" and stays disabled across polls
    detailMessage('');
    try {
      await api(routeUrl('admin_ai_idol_approve_script', 'job_id', jobId), { method: 'POST', headers: JSON_HEADERS, body: JSON.stringify({ script_text: text }) });
      scriptDrafts.delete(jobId);
      detailMessage('Đã chấp nhận kịch bản. Video sẽ được tạo.', true);
      await afterChange(); // the lock is released only after the refreshed detail has replaced the button
    } catch (error) {
      detailMessage(`Không thể duyệt kịch bản: ${error.message}`);
    } finally {
      approvingJobs.delete(jobId);
      lastDetailHtml = '';
      renderDetail();
    }
    return undefined;
  }

  async function campaignControl(id) {
    const campaignId = selected && selected.kind === 'campaign' ? selected.id : '';
    if (!campaignId) return;
    let route = 'admin_ai_idol_reschedule_campaign';
    let body = {};
    let busyName = 'reschedule';
    let ok = 'Đã đổi lịch.';
    if (id === 'actCancelCampaign') {
      if (!global.confirm('Hủy chương trình này? Các phần chưa tạo xong sẽ dừng lại và không thể khôi phục.')) return;
      route = 'admin_ai_idol_cancel_campaign';
      busyName = 'cancel';
      ok = 'Đã hủy chương trình.';
    } else if (id === 'actAirNow') {
      body = { scheduled_at: 'now' };
      busyName = 'air';
      ok = 'Đã yêu cầu phát nội bộ ngay.';
    } else if (id === 'actRetryCampaign') {
      route = 'admin_ai_idol_retry_campaign';
      busyName = 'retry';
      ok = 'Đã gửi yêu cầu tạo lại phần bị lỗi.';
    } else {
      const input = doc.getElementById('actRescheduleAt');
      const value = input ? input.value : '';
      const time = vnTime(value);
      if (!Number.isFinite(time) || time < Date.now() + MIN_RESCHEDULE_MS) return detailMessage('Chọn lịch mới cách hiện tại ít nhất 2 phút.');
      body = { scheduled_at: value };
    }
    detailMessage('');
    try {
      await send(route, 'campaign_id', campaignId, body, busyKey(campaignId, `campaign:${busyName}`));
      detailMessage(ok, true);
      await afterChange();
    } catch (error) {
      renderDetail();
      detailMessage(error.status === 409 ? error.message : `Không thể cập nhật chương trình: ${error.message}`);
    }
    return undefined;
  }

  async function runControl(kind, runId) {
    const route = kind === 'retry' ? 'admin_ai_idol_agent_retry' : 'admin_ai_idol_agent_cancel';
    detailMessage('');
    try {
      await send(route, 'run_id', runId, {}, `run:${runId}:${kind}`);
      detailMessage(kind === 'retry' ? 'Syna đang thử lại.' : 'Đã hủy yêu cầu.', true);
      await afterChange();
    } catch (error) {
      renderDetail();
      detailMessage(error.status === 409 ? 'Syna đang xử lý, chờ vài giây rồi thử lại.' : `Không thể cập nhật yêu cầu: ${error.message}`);
    }
  }

  // ------------------------------------------------------------------ events

  listEl.addEventListener('click', (event) => {
    const card = event.target.closest('.act-card');
    if (!card) return;
    select({ kind: card.dataset.actKind, id: card.dataset.actId }, { scroll: true, focus: true });
  });

  filterButtons.forEach((button) => button.addEventListener('click', () => {
    filter = button.dataset.actFilter;
    lastListHtml = '';
    renderList();
  }));

  if (refreshEl) refreshEl.addEventListener('click', () => { refresh(); });

  bodyEl.addEventListener('input', (event) => {
    if (event.target.matches('.script-editor:not([readonly])')) scriptDrafts.set(event.target.dataset.job, event.target.value);
  });
  bodyEl.addEventListener('toggle', (event) => {
    const key = event.target.dataset && event.target.dataset.key;
    if (key) toggled.set(key, event.target.open);
  }, true);

  bodyEl.addEventListener('click', (event) => {
    const target = event.target.closest('button, a');
    if (!target || target.disabled) return;
    if (target.hasAttribute('data-act-close')) return closeDetail();
    if (target.dataset.approveScript) return approveScript(target);
    if (target.dataset.approvePlatform) return approvePlatform(target.dataset.approvePlatform);
    if (['actCancelCampaign', 'actAirNow', 'actRescheduleCampaign', 'actRetryCampaign'].includes(target.id)) return campaignControl(target.id);
    if (target.dataset.runRetry) return runControl('retry', target.dataset.runRetry);
    if (target.dataset.runCancel) {
      if (!global.confirm('Hủy yêu cầu này?')) return undefined;
      return runControl('cancel', target.dataset.runCancel);
    }
    if (target.hasAttribute('data-run-prefill')) {
      const item = findItem(selected);
      if (item && item.run && item.run.brief) emit('studio:prefill-prompt', { brief: String(item.run.brief) });
    }
    return undefined;
  });
  detailEl.addEventListener('keydown', (event) => {
    if (event.key === 'Escape' && !event.defaultPrevented && !event.target.matches('textarea, input')) closeDetail();
  });

  on('studio:refresh', () => { refresh(); });
  on('studio:open-item', (event) => {
    const detail = event.detail || {};
    openItem(detail.kind, detail.id, { scroll: true });
  });

  // ------------------------------------------------------------------ start

  renderList();
  const linked = new URLSearchParams(global.location ? global.location.search : '').get('campaign_id');
  if (linked) openItem('campaign', linked, { scroll: true });
  refresh();
}(typeof globalThis !== 'undefined' ? globalThis : this));
