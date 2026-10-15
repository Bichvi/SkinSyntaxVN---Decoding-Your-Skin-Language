/*
 * AI Idol Studio - pure logic (no DOM, no network, no clock).
 *
 * Loaded by the browser through a <script> tag (exposes window.StudioLogic) and
 * required by the Node unit tests (module.exports). Every function tolerates
 * missing fields on old records and never throws.
 */
(function (root, factory) {
  const api = factory();
  if (typeof module === 'object' && module.exports) module.exports = api;
  else root.StudioLogic = api;
})(typeof self !== 'undefined' ? self : this, function () {
  'use strict';

  // ------------------------------------------------------------------ constants

  // Fixed display order. `price: true` = the style makes Syna speak prices.
  const NARRATION_STYLES = Object.freeze([
    Object.freeze({ key: 'cheerful', label: 'Tươi vui, dễ thương', price: false }),
    Object.freeze({ key: 'gentle', label: 'Tư vấn nhẹ nhàng', price: false }),
    Object.freeze({ key: 'expert', label: 'Chuyên gia khoa học', price: false }),
    Object.freeze({ key: 'closing', label: 'Tập trung chốt sale', price: true }),
    Object.freeze({ key: 'concise', label: 'Ngắn gọn, nhanh', price: false }),
    Object.freeze({ key: 'detailed', label: 'Giải thích chi tiết', price: false }),
    Object.freeze({ key: 'friendly', label: 'Thân thiện như bạn bè', price: false }),
    Object.freeze({ key: 'ingredients', label: 'Nhấn mạnh thành phần', price: false }),
    Object.freeze({ key: 'pricing', label: 'Nhấn mạnh giá & ưu đãi', price: true }),
  ]);

  const PURPOSE_LABELS = Object.freeze({
    product_video: 'Video sản phẩm',
    live_and_product: 'Livestream + video sản phẩm',
    live_only: 'Chỉ livestream',
  });

  const CONTENT_MODE_LABELS = Object.freeze({
    'Product Intro': 'giới thiệu sản phẩm',
    'Problem-Solution': 'vấn đề và giải pháp',
    'Scientific Review': 'phân tích thành phần',
  });

  const DEFAULT_PURPOSE = 'product_video'; // what the wizard starts with
  const LEGACY_PURPOSE = 'live_only'; // campaigns saved before `purpose` existed were live shows
  const DEFAULT_CONTENT_MODE = 'Product Intro';
  const DEFAULT_FORMAT = '16:9';

  const PLATFORM_LABELS = Object.freeze({
    internal: 'Nội bộ',
    preview: 'Xem thử',
    youtube: 'YouTube',
    facebook: 'Facebook',
  });

  // concise and detailed exclude each other.
  const STYLE_OPPOSITE = Object.freeze({ concise: 'detailed', detailed: 'concise' });

  const STEP_ORDER = Object.freeze(['select', 'script', 'review', 'render', 'schedule', 'broadcast', 'page']);
  const STEP_LABELS = Object.freeze({
    select: 'Chọn SP',
    script: 'Kịch bản',
    review: 'Kiểm duyệt',
    render: 'Render',
    schedule: 'Lên lịch',
    broadcast: 'Phát',
    page: 'Trang SP',
  });
  const PURPOSE_STEPS = Object.freeze({
    product_video: Object.freeze(['select', 'script', 'review', 'render', 'page']),
    live_only: Object.freeze(['select', 'script', 'review', 'render', 'schedule', 'broadcast']),
    live_and_product: STEP_ORDER,
  });

  // A run that ended without a campaign: nothing is moving any more.
  const RUN_FINISHED = Object.freeze(['COMPLETED', 'RESULTS_READY', 'EMPTY_RESULTS', 'DATA_UNAVAILABLE', 'CANCELLED', 'CAMPAIGN_CANCELLED']);
  const RUN_NEEDS_YOU = Object.freeze(['NEEDS_INPUT', 'FAILED', 'NEEDS_ATTENTION']);
  const CAMPAIGN_NEEDS_YOU = Object.freeze(['AWAITING_APPROVAL', 'NEEDS_ATTENTION', 'FAILED']);
  // A pending external channel can still be approved while the show has not started.
  const PENDING_PLATFORM_STATUSES = Object.freeze(['VALIDATING', 'GENERATING', 'AWAITING_APPROVAL', 'NEEDS_ATTENTION', 'SCHEDULED']);

  // -------------------------------------------------------------------- helpers

  const isObject = (value) => value !== null && typeof value === 'object';
  const asArray = (value) => (Array.isArray(value) ? value : []);
  const upper = (value) => (typeof value === 'string' ? value.trim().toUpperCase() : '');

  function normalizePurpose(purpose, fallback) {
    return typeof purpose === 'string' && Object.prototype.hasOwnProperty.call(PURPOSE_LABELS, purpose)
      ? purpose
      : fallback;
  }

  function idOf(value) {
    if (typeof value === 'string') return value;
    if (typeof value === 'number') return String(value);
    if (isObject(value) && typeof value.$oid === 'string') return value.$oid; // Mongo extended JSON
    return '';
  }

  function timeOf(value) {
    const ms = typeof value === 'string' || typeof value === 'number' ? Date.parse(value) : NaN;
    return Number.isNaN(ms) ? 0 : ms;
  }

  function styleByKey(key) {
    return NARRATION_STYLES.find((style) => style.key === key) || null;
  }

  // ---------------------------------------------------------------------- styles

  function allowedStyles(purpose) {
    const productOnly = normalizePurpose(purpose, DEFAULT_PURPOSE) === 'product_video';
    return NARRATION_STYLES.filter((style) => !(productOnly && style.price)).map((style) => style.key);
  }

  function toggleStyle(current, key, purpose) {
    const list = Array.isArray(current) ? current : [];
    if (!allowedStyles(purpose).includes(key)) return list; // locked or unknown: hand back the same array
    if (list.includes(key)) return list.filter((item) => item !== key);
    const opposite = STYLE_OPPOSITE[key];
    return list.filter((item) => item !== opposite).concat(key);
  }

  // The styles that will really apply: known, allowed for the purpose, no duplicates.
  function effectiveStyles(styles, purpose) {
    const allowed = allowedStyles(purpose);
    const seen = [];
    asArray(styles).forEach((key) => {
      if (allowed.includes(key) && !seen.includes(key)) seen.push(key);
    });
    return seen;
  }

  // ------------------------------------------------------------ intent / summary

  function productNames(products) {
    return asArray(products)
      .map((product) => {
        if (typeof product === 'string') return product.trim();
        if (!isObject(product)) return '';
        const name = typeof product.name === 'string' ? product.name.trim() : '';
        return name || idOf(product.id) || 'Sản phẩm chưa đặt tên';
      })
      .filter(Boolean);
  }

  function describeProducts(names) {
    const n = names.length;
    if (n <= 3) return `${n} sản phẩm: ${names.join(', ')}`;
    return `${n} sản phẩm: ${names.slice(0, 3).join(', ')} và ${n - 3} sản phẩm khác`;
  }

  function describeLead(purpose, schedule) {
    if (purpose === 'product_video') return 'Syna làm video sản phẩm (không nói giá)';
    const sched = isObject(schedule) ? schedule : {};
    let lead = 'Syna livestream';
    const duration = Number(sched.duration);
    if (Number.isFinite(duration) && duration > 0) lead += ` ${duration} phút`;
    // `at` is a datetime-local value: cut it from the string, never through Date (no timezone shift).
    const match = /^(\d{4})-(\d{2})-(\d{2})T(\d{2}):(\d{2})/.exec(typeof sched.at === 'string' ? sched.at : '');
    if (match) lead += ` lúc ${match[4]}:${match[5]} ${match[3]}/${match[2]}/${match[1]}`;
    const channels = [];
    asArray(sched.platforms).forEach((platform) => {
      if (typeof platform !== 'string') return;
      const name = PLATFORM_LABELS[platform] || platform;
      if (!channels.includes(name)) channels.push(name);
    });
    if (channels.length) lead += ` trên ${channels.join(', ')}`;
    return lead;
  }

  const INTENT_TAILS = Object.freeze({
    product_video: 'video tự đăng lên trang sản phẩm khi sẵn sàng.',
    live_and_product: 'sau buổi phát, phần giới thiệu không giá tự đăng lên trang sản phẩm.',
    live_only: 'không đăng lên trang sản phẩm.',
  });

  // Mid-sentence in describeIntent, capitalised in the summary bar.
  function characterName(character, capitalised) {
    if (character !== 'custom') return 'Syna 3D';
    return capitalised ? 'Nhân vật riêng' : 'nhân vật riêng';
  }

  function formatOf(sel) {
    return typeof sel.format === 'string' && sel.format ? sel.format : DEFAULT_FORMAT;
  }

  function describeIntent(sel) {
    const s = isObject(sel) ? sel : {};
    const names = productNames(s.products);
    if (!names.length) return 'Chưa chọn sản phẩm.';
    const purpose = normalizePurpose(s.purpose, DEFAULT_PURPOSE);
    const mode = CONTENT_MODE_LABELS[s.contentMode] || CONTENT_MODE_LABELS[DEFAULT_CONTENT_MODE];
    const labels = effectiveStyles(s.styles, purpose).map((key) => styleByKey(key).label.toLowerCase().replace(/&/g, 'và'));
    const styles = labels.length ? `phong cách ${labels.join(', ')}, ` : '';
    const character = `${characterName(s.character)} khung ${formatOf(s)}`;
    return `${describeLead(purpose, s.schedule)} cho ${describeProducts(names)}, cách kể ${mode}, ${styles}${character}; ${INTENT_TAILS[purpose]}`;
  }

  function summaryBar(sel) {
    const s = isObject(sel) ? sel : {};
    const purpose = normalizePurpose(s.purpose, DEFAULT_PURPOSE);
    const count = effectiveStyles(s.styles, purpose).length;
    let bar = `${productNames(s.products).length} SP · ${PURPOSE_LABELS[purpose]}`;
    if (count > 0) bar += ` · ${count} phong cách`;
    return `${bar} · ${characterName(s.character, true)} ${formatOf(s)}`;
  }

  // ------------------------------------------------------- activity item parts

  // An activity item is {kind, campaign, run, ...}; be forgiving about bare shapes.
  function partsOf(item) {
    const it = isObject(item) ? item : {};
    const campaign = isObject(it.campaign) ? it.campaign : it.kind === 'campaign' ? it : null;
    const run = isObject(it.run) ? it.run : !campaign && it.kind === 'run' ? it : null;
    const source = campaign || run || it;
    return { campaign, run, status: upper(source.status) };
  }

  // ---------------------------------------------------------------- progressSteps

  // Where the item stands, as an index into STEP_ORDER plus the state of that step.
  // Steps before the index are done, steps after it are todo.
  function stepTarget(campaign, status) {
    const at = (key, state) => ({ index: STEP_ORDER.indexOf(key), state });
    if (status === 'CANCELLED') return at('select', 'done');
    if (!campaign) {
      if (RUN_FINISHED.includes(status)) return at('select', 'done');
      // A run that has not produced a campaign yet is still at the first step.
      return at('select', RUN_NEEDS_YOU.includes(status) ? 'blocked' : 'active');
    }
    switch (status) {
      case 'VALIDATING': return at('script', 'active');
      case 'AWAITING_APPROVAL': return at('review', 'blocked');
      case 'GENERATING':
      case 'ASSEMBLING': return at('render', 'active');
      case 'NEEDS_ATTENTION':
      case 'FAILED': return campaign.master_video_path ? at('schedule', 'blocked') : at('render', 'blocked');
      case 'SCHEDULED': return at('schedule', 'done');
      case 'STARTING':
      case 'LIVE': return at('broadcast', 'active');
      case 'COMPLETED':
      case 'VIDEOS_READY': return { index: STEP_ORDER.length, state: 'done' };
      default: return at('script', 'active'); // selection is certainly over, the rest is unknown
    }
  }

  function progressSteps(item) {
    const { campaign, run, status } = partsOf(item);
    let purpose;
    if (campaign) purpose = normalizePurpose(campaign.purpose, LEGACY_PURPOSE);
    else purpose = normalizePurpose(isObject(run && run.plan) ? run.plan.purpose : undefined, 'live_and_product'); // purpose unknown yet: show them all
    const visible = PURPOSE_STEPS[purpose];

    const target = stepTarget(campaign, status);
    let index = target.index;
    if (index < STEP_ORDER.length && target.state !== 'done' && !visible.includes(STEP_ORDER[index])) {
      // The marker belongs to a step this purpose does not show: move it to the nearest step that is shown.
      const after = STEP_ORDER.findIndex((key, i) => i > index && visible.includes(key));
      let before = -1;
      STEP_ORDER.forEach((key, i) => { if (i < index && visible.includes(key)) before = i; });
      index = after !== -1 ? after : before;
    }

    return STEP_ORDER
      .map((key, i) => ({ key, label: STEP_LABELS[key], state: i < index ? 'done' : i === index ? target.state : 'todo' }))
      .filter((step) => visible.includes(step.key));
  }

  // -------------------------------------------------------------------- needsYou

  function needsYou(item) {
    const { campaign, run, status } = partsOf(item);
    if (campaign) {
      if (CAMPAIGN_NEEDS_YOU.includes(status)) return true;
      return asArray(campaign.pending_external_platforms).length > 0 && PENDING_PLATFORM_STATUSES.includes(status);
    }
    return Boolean(run) && RUN_NEEDS_YOU.includes(status);
  }

  // ------------------------------------------------------- mergeActivity / filter

  function mergeActivity(campaigns, runs) {
    const runList = asArray(runs).filter(isObject);
    const runById = new Map();
    const runByCampaign = new Map();
    runList.forEach((run) => {
      const id = idOf(run._id);
      if (id && !runById.has(id)) runById.set(id, run);
      const campaignId = idOf(run.campaign_id);
      if (campaignId && !runByCampaign.has(campaignId)) runByCampaign.set(campaignId, run);
    });

    const items = [];
    asArray(campaigns).filter(isObject).forEach((campaign) => {
      const id = idOf(campaign._id);
      const configuration = isObject(campaign.configuration) ? campaign.configuration : {};
      const auto = Boolean(configuration.agent_managed);
      let run = null;
      if (auto) run = runById.get(idOf(configuration.agent_run_id)) || runByCampaign.get(id) || null;
      items.push({
        kind: 'campaign',
        id,
        source: auto ? 'auto' : 'manual',
        status: upper(campaign.status),
        title: typeof campaign.name === 'string' && campaign.name.trim() ? campaign.name.trim() : 'Chương trình chưa đặt tên',
        createdAt: campaign.created_at || '',
        campaign,
        run,
      });
    });

    runList.forEach((run) => {
      if (idOf(run.campaign_id)) return; // shown through its campaign card
      items.push({
        kind: 'run',
        id: idOf(run._id),
        source: 'auto',
        status: upper(run.status),
        title: typeof run.brief === 'string' && run.brief.trim() ? run.brief.trim() : 'Yêu cầu tự động',
        createdAt: run.created_at || '',
        campaign: null,
        run,
      });
    });

    // Array.prototype.sort is stable: records with no usable date keep their input order at the end.
    return items.sort((a, b) => timeOf(b.createdAt) - timeOf(a.createdAt));
  }

  function filterActivity(items, filter) {
    const list = asArray(items);
    if (filter === 'needs_you') return list.filter(needsYou);
    if (filter === 'scheduled') {
      return list.filter((item) => {
        const { campaign, status } = partsOf(item);
        return Boolean(campaign) && status === 'SCHEDULED';
      });
    }
    return list.slice();
  }

  // ----------------------------------------------------------------- latest gate

  // Drop stale async results: take a token before the request, apply the result only if still current.
  function createLatestGate() {
    let latest = 0;
    return {
      next() {
        latest += 1;
        return latest;
      },
      isCurrent(token) {
        return token === latest;
      },
    };
  }

  return {
    NARRATION_STYLES,
    PURPOSE_LABELS,
    CONTENT_MODE_LABELS,
    allowedStyles,
    toggleStyle,
    describeIntent,
    summaryBar,
    progressSteps,
    needsYou,
    mergeActivity,
    filterActivity,
    createLatestGate,
  };
});
