'use strict';
// Unit tests for the pure AI Idol Studio logic. Run: node --test "frontend/public/assets/js/tests/**/*.test.js"
const test = require('node:test');
const assert = require('node:assert/strict');
const L = require('../studio-logic.js');

const STYLE_KEYS = ['cheerful', 'gentle', 'expert', 'closing', 'concise', 'detailed', 'friendly', 'ingredients', 'pricing'];
const stateOf = (steps) => steps.map((s) => `${s.key}:${s.state}`).join(' ');
const keysOf = (steps) => steps.map((s) => s.key);
const camp = (status, extra) => ({ kind: 'campaign', id: 'c1', campaign: Object.assign({ _id: 'c1', status, purpose: 'live_and_product' }, extra), run: null });
const runItem = (status, extra) => ({ kind: 'run', id: 'r1', campaign: null, run: Object.assign({ _id: 'r1', status }, extra) });

// ---------------------------------------------------------------- constants

test('NARRATION_STYLES keeps the fixed order, labels and price flags', () => {
  assert.deepEqual(L.NARRATION_STYLES.map((s) => s.key), STYLE_KEYS);
  const byKey = Object.fromEntries(L.NARRATION_STYLES.map((s) => [s.key, s]));
  assert.equal(byKey.cheerful.label, 'Tươi vui, dễ thương');
  assert.equal(byKey.gentle.label, 'Tư vấn nhẹ nhàng');
  assert.equal(byKey.expert.label, 'Chuyên gia khoa học');
  assert.equal(byKey.closing.label, 'Tập trung chốt sale');
  assert.equal(byKey.concise.label, 'Ngắn gọn, nhanh');
  assert.equal(byKey.detailed.label, 'Giải thích chi tiết');
  assert.equal(byKey.friendly.label, 'Thân thiện như bạn bè');
  assert.equal(byKey.ingredients.label, 'Nhấn mạnh thành phần');
  assert.equal(byKey.pricing.label, 'Nhấn mạnh giá & ưu đãi');
  assert.deepEqual(L.NARRATION_STYLES.filter((s) => s.price).map((s) => s.key), ['closing', 'pricing']);
});

test('NARRATION_STYLES liveOnly flags', () => {
  assert.deepEqual(L.NARRATION_STYLES.filter((s) => s.liveOnly).map((s) => s.key), ['closing']);
});

test('PURPOSE_LABELS and CONTENT_MODE_LABELS are exact', () => {
  assert.deepEqual({ ...L.PURPOSE_LABELS }, {
    product_video: 'Video sản phẩm',
    live_and_product: 'Livestream + video sản phẩm',
    live_only: 'Chỉ livestream',
  });
  assert.deepEqual({ ...L.CONTENT_MODE_LABELS }, {
    'Product Intro': 'giới thiệu sản phẩm',
    'Problem-Solution': 'vấn đề và giải pháp',
    'Scientific Review': 'phân tích thành phần',
  });
});

// ------------------------------------------------------- allowed / toggle styles

test('allowedStyles hides only the live-only style for product_video', () => {
  assert.deepEqual(L.allowedStyles('product_video'), ['cheerful', 'gentle', 'expert', 'concise', 'detailed', 'friendly', 'ingredients', 'pricing']);
  assert.deepEqual(L.allowedStyles('live_only'), STYLE_KEYS);
  assert.deepEqual(L.allowedStyles('live_and_product'), STYLE_KEYS);
});

test('toggleStyle adds, removes and ignores a key the purpose forbids', () => {
  assert.deepEqual(L.toggleStyle([], 'gentle', 'product_video'), ['gentle']);
  assert.deepEqual(L.toggleStyle(['gentle'], 'ingredients', 'product_video'), ['gentle', 'ingredients']);
  assert.deepEqual(L.toggleStyle(['gentle', 'ingredients'], 'gentle', 'product_video'), ['ingredients']);
  // the price styles are locked for product_video: current comes back untouched
  const current = ['gentle'];
  assert.equal(L.toggleStyle(current, 'closing', 'product_video'), current);
  assert.deepEqual(L.toggleStyle(current, 'pricing', 'product_video'), ['gentle', 'pricing']);
  // ...but fine when the video is also live
  assert.deepEqual(L.toggleStyle(current, 'closing', 'live_and_product'), ['gentle', 'closing']);
  // unknown keys never get in
  assert.deepEqual(L.toggleStyle(current, 'nonsense', 'live_only'), ['gentle']);
});

test('toggleStyle makes concise and detailed mutually exclusive', () => {
  assert.deepEqual(L.toggleStyle(['detailed', 'gentle'], 'concise', 'live_only'), ['gentle', 'concise']);
  assert.deepEqual(L.toggleStyle(['concise'], 'detailed', 'product_video'), ['detailed']);
  assert.deepEqual(L.toggleStyle(['concise'], 'concise', 'product_video'), []);
});

test('toggleStyle never mutates its input and survives garbage', () => {
  const current = Object.freeze(['concise']);
  assert.deepEqual(L.toggleStyle(current, 'detailed', 'live_only'), ['detailed']);
  assert.deepEqual(L.toggleStyle(undefined, 'gentle', 'live_only'), ['gentle']);
  assert.deepEqual(L.toggleStyle(null, 'gentle'), ['gentle']);
});

// ---------------------------------------------------------------- describeIntent

const selBase = () => ({
  products: [{ id: 'p1', name: 'Serum B5' }],
  purpose: 'product_video',
  schedule: { at: '', duration: 60, platforms: ['internal'] },
  styles: [],
  contentMode: 'Product Intro',
  character: 'syna_3d',
  format: '16:9',
});

test('describeIntent: one product, product_video, one style', () => {
  const sel = selBase();
  sel.styles = ['gentle'];
  assert.equal(
    L.describeIntent(sel),
    'Syna làm video sản phẩm (không nói giá) cho 1 sản phẩm: Serum B5, cách kể giới thiệu sản phẩm, phong cách tư vấn nhẹ nhàng, Syna 3D khung 16:9; video tự đăng lên trang sản phẩm khi sẵn sàng.'
  );
});

test('describeIntent: five products, live_and_product on YouTube + internal', () => {
  const sel = {
    products: [{ id: '1', name: 'A' }, { id: '2', name: 'B' }, { id: '3', name: 'C' }, { id: '4', name: 'D' }, { id: '5', name: 'E' }],
    purpose: 'live_and_product',
    schedule: { at: '2026-10-16T20:05', duration: 90, platforms: ['internal', 'youtube'] },
    styles: ['ingredients', 'pricing'],
    contentMode: 'Scientific Review',
    character: 'custom',
    format: '9:16',
  };
  assert.equal(
    L.describeIntent(sel),
    'Syna livestream 90 phút lúc 20:05 16/10/2026 trên Nội bộ, YouTube cho 5 sản phẩm: A, B, C và 2 sản phẩm khác, cách kể phân tích thành phần, phong cách nhấn mạnh thành phần, nhấn mạnh giá và ưu đãi, nhân vật riêng khung 9:16; sau buổi phát, phần giới thiệu không giá tự đăng lên trang sản phẩm.'
  );
});

test('describeIntent: live_only without styles, Facebook, three products listed in full', () => {
  const sel = {
    products: [{ id: '1', name: 'A' }, { id: '2', name: 'B' }, { id: '3', name: 'C' }],
    purpose: 'live_only',
    schedule: { at: '2026-01-02T03:04', duration: 30, platforms: ['facebook'] },
    styles: [],
    contentMode: 'Problem-Solution',
    character: 'syna_3d',
    format: '16:9',
  };
  assert.equal(
    L.describeIntent(sel),
    'Syna livestream 30 phút lúc 03:04 02/01/2026 trên Facebook cho 3 sản phẩm: A, B, C, cách kể vấn đề và giải pháp, Syna 3D khung 16:9; không đăng lên trang sản phẩm.'
  );
});

test('describeIntent: exactly four products collapses the tail; two styles keep their order', () => {
  const sel = selBase();
  sel.products = [{ name: 'A' }, { name: 'B' }, { name: 'C' }, { name: 'D' }];
  sel.styles = ['friendly', 'cheerful'];
  assert.match(L.describeIntent(sel), /cho 4 sản phẩm: A, B, C và 1 sản phẩm khác, cách kể giới thiệu sản phẩm, phong cách thân thiện như bạn bè, tươi vui, dễ thương, Syna 3D khung 16:9; /);
});

test('describeIntent: no products', () => {
  const sel = selBase();
  sel.products = [];
  assert.equal(L.describeIntent(sel), 'Chưa chọn sản phẩm.');
});

test('describeIntent never throws on missing fields and ignores styles the purpose forbids', () => {
  assert.equal(L.describeIntent(undefined), 'Chưa chọn sản phẩm.');
  assert.equal(L.describeIntent({}), 'Chưa chọn sản phẩm.');
  assert.equal(typeof L.describeIntent({ products: [{ id: 'x' }] }), 'string');
  const sel = selBase();
  sel.styles = ['closing', 'gentle'];
  const text = L.describeIntent(sel);
  assert.ok(text.includes('phong cách tư vấn nhẹ nhàng, Syna 3D'));
  assert.ok(!text.includes('chốt sale'));
  // a live purpose with a broken date drops the clock but stays readable
  const live = selBase();
  live.purpose = 'live_only';
  live.schedule = { at: 'not-a-date', duration: 45, platforms: ['youtube'] };
  assert.match(L.describeIntent(live), /^Syna livestream 45 phút trên YouTube cho 1 sản phẩm: Serum B5, /);
});

// ------------------------------------------------------------------- summaryBar

test('summaryBar: with and without styles, both characters', () => {
  const sel = selBase();
  sel.products = [1, 2, 3, 4, 5].map((n) => ({ id: String(n), name: 'P' + n }));
  sel.styles = ['gentle', 'ingredients'];
  assert.equal(L.summaryBar(sel), '5 SP · Video sản phẩm · 2 phong cách · Syna 3D 16:9');
  sel.styles = [];
  sel.purpose = 'live_and_product';
  sel.character = 'custom';
  sel.format = '9:16';
  assert.equal(L.summaryBar(sel), '5 SP · Livestream + video sản phẩm · Nhân vật riêng 9:16');
  sel.purpose = 'live_only';
  assert.equal(L.summaryBar(sel), '5 SP · Chỉ livestream · Nhân vật riêng 9:16');
  assert.equal(typeof L.summaryBar(undefined), 'string');
});

// ----------------------------------------------------------------- progressSteps

test('progressSteps: the steps shown depend on the purpose, with the fixed labels', () => {
  const pv = L.progressSteps({ kind: 'campaign', campaign: { status: 'GENERATING', purpose: 'product_video' } });
  assert.deepEqual(keysOf(pv), ['select', 'script', 'review', 'render', 'page']);
  const lo = L.progressSteps({ kind: 'campaign', campaign: { status: 'GENERATING', purpose: 'live_only' } });
  assert.deepEqual(keysOf(lo), ['select', 'script', 'review', 'render', 'schedule', 'broadcast']);
  const both = L.progressSteps(camp('GENERATING'));
  assert.deepEqual(keysOf(both), ['select', 'script', 'review', 'render', 'schedule', 'broadcast', 'page']);
  assert.deepEqual(both.map((s) => s.label), ['Chọn SP', 'Kịch bản', 'Kiểm duyệt', 'Render', 'Lên lịch', 'Phát', 'Trang SP']);
  // legacy campaign with no purpose behaves like live_only
  const legacy = L.progressSteps({ kind: 'campaign', campaign: { status: 'GENERATING' } });
  assert.deepEqual(keysOf(legacy), keysOf(lo));
});

test('progressSteps: campaign VALIDATING / AWAITING_APPROVAL / GENERATING / ASSEMBLING', () => {
  assert.equal(stateOf(L.progressSteps(camp('VALIDATING'))), 'select:done script:active review:todo render:todo schedule:todo broadcast:todo page:todo');
  assert.equal(stateOf(L.progressSteps(camp('AWAITING_APPROVAL'))), 'select:done script:done review:blocked render:todo schedule:todo broadcast:todo page:todo');
  assert.equal(stateOf(L.progressSteps(camp('GENERATING'))), 'select:done script:done review:done render:active schedule:todo broadcast:todo page:todo');
  assert.equal(stateOf(L.progressSteps(camp('ASSEMBLING'))), 'select:done script:done review:done render:active schedule:todo broadcast:todo page:todo');
});

test('progressSteps: NEEDS_ATTENTION / FAILED block on render, or on schedule once a master video exists', () => {
  for (const status of ['NEEDS_ATTENTION', 'FAILED']) {
    assert.equal(stateOf(L.progressSteps(camp(status))), 'select:done script:done review:done render:blocked schedule:todo broadcast:todo page:todo');
    assert.equal(
      stateOf(L.progressSteps(camp(status, { master_video_path: '/media/master.mp4' }))),
      'select:done script:done review:done render:done schedule:blocked broadcast:todo page:todo'
    );
  }
});

test('progressSteps: SCHEDULED, STARTING, LIVE, COMPLETED, VIDEOS_READY, CANCELLED', () => {
  assert.equal(stateOf(L.progressSteps(camp('SCHEDULED'))), 'select:done script:done review:done render:done schedule:done broadcast:todo page:todo');
  for (const status of ['STARTING', 'LIVE']) {
    assert.equal(stateOf(L.progressSteps(camp(status))), 'select:done script:done review:done render:done schedule:done broadcast:active page:todo');
  }
  for (const status of ['COMPLETED', 'VIDEOS_READY']) {
    assert.equal(stateOf(L.progressSteps(camp(status))), 'select:done script:done review:done render:done schedule:done broadcast:done page:done');
  }
  assert.equal(stateOf(L.progressSteps(camp('CANCELLED'))), 'select:done script:todo review:todo render:todo schedule:todo broadcast:todo page:todo');
});

test('progressSteps: a hidden step never takes the active/blocked marker', () => {
  const pv = (status, extra) => ({ kind: 'campaign', campaign: Object.assign({ status, purpose: 'product_video' }, extra) });
  assert.equal(stateOf(L.progressSteps(pv('GENERATING'))), 'select:done script:done review:done render:active page:todo');
  assert.equal(stateOf(L.progressSteps(pv('VIDEOS_READY'))), 'select:done script:done review:done render:done page:done');
  // schedule is not part of product_video: the marker moves to the next step that is shown
  assert.equal(stateOf(L.progressSteps(pv('FAILED', { master_video_path: '/m.mp4' }))), 'select:done script:done review:done render:done page:blocked');
});

test('progressSteps: run without a campaign', () => {
  for (const status of ['RECEIVED', 'INTERPRETING', 'SOMETHING_NEW']) {
    assert.equal(stateOf(L.progressSteps(runItem(status))), 'select:active script:todo review:todo render:todo schedule:todo broadcast:todo page:todo');
  }
  for (const status of ['NEEDS_INPUT', 'FAILED', 'NEEDS_ATTENTION']) {
    assert.equal(stateOf(L.progressSteps(runItem(status))), 'select:blocked script:todo review:todo render:todo schedule:todo broadcast:todo page:todo');
  }
  // a cancelled run is not "in progress"
  assert.equal(stateOf(L.progressSteps(runItem('CANCELLED'))), 'select:done script:todo review:todo render:todo schedule:todo broadcast:todo page:todo');
  // a run that already knows its purpose only shows the steps that apply
  assert.deepEqual(keysOf(L.progressSteps(runItem('RECEIVED', { plan: { purpose: 'product_video' } }))), ['select', 'script', 'review', 'render', 'page']);
});

test('progressSteps never throws on junk and always returns well-formed steps', () => {
  for (const junk of [undefined, null, {}, { kind: 'campaign' }, { kind: 'run' }, 'x', 42]) {
    const steps = L.progressSteps(junk);
    assert.ok(Array.isArray(steps) && steps.length > 0);
    for (const s of steps) {
      assert.ok(['done', 'active', 'todo', 'blocked'].includes(s.state));
      assert.equal(typeof s.label, 'string');
    }
  }
  // unknown status on a real campaign: the selection is certainly over, nothing is blocked
  const odd = L.progressSteps(camp('SOMETHING_NEW'));
  assert.equal(odd[0].state, 'done');
  assert.ok(!odd.some((s) => s.state === 'blocked'));
});

// --------------------------------------------------------------------- needsYou

test('needsYou: campaign states that wait for the user', () => {
  assert.equal(L.needsYou(camp('AWAITING_APPROVAL')), true);
  assert.equal(L.needsYou(camp('NEEDS_ATTENTION')), true);
  assert.equal(L.needsYou(camp('FAILED')), true);
  assert.equal(L.needsYou(camp('GENERATING')), false);
  assert.equal(L.needsYou(camp('SCHEDULED')), false);
  assert.equal(L.needsYou(camp('COMPLETED')), false);
  assert.equal(L.needsYou(camp('CANCELLED')), false);
});

test('needsYou: pending external platforms only count before the broadcast starts', () => {
  const pending = { pending_external_platforms: ['youtube'] };
  for (const status of ['VALIDATING', 'GENERATING', 'AWAITING_APPROVAL', 'NEEDS_ATTENTION', 'SCHEDULED']) {
    assert.equal(L.needsYou(camp(status, pending)), true, status);
  }
  for (const status of ['STARTING', 'LIVE', 'COMPLETED', 'VIDEOS_READY', 'CANCELLED']) {
    assert.equal(L.needsYou(camp(status, pending)), false, status);
  }
  assert.equal(L.needsYou(camp('SCHEDULED', { pending_external_platforms: [] })), false);
  assert.equal(L.needsYou(camp('SCHEDULED', { pending_external_platforms: null })), false);
});

test('needsYou: runs without a campaign', () => {
  assert.equal(L.needsYou(runItem('NEEDS_INPUT')), true);
  assert.equal(L.needsYou(runItem('FAILED')), true);
  assert.equal(L.needsYou(runItem('NEEDS_ATTENTION')), true);
  assert.equal(L.needsYou(runItem('INTERPRETING')), false);
  assert.equal(L.needsYou(runItem('RECEIVED')), false);
  assert.equal(L.needsYou(undefined), false);
  assert.equal(L.needsYou({}), false);
});

// ------------------------------------------------------- mergeActivity / filter

test('mergeActivity: merges runs into campaigns, labels the source and sorts newest first', () => {
  const campaigns = [
    { _id: 'c-old', name: 'Chương trình cũ', status: 'COMPLETED', created_at: '2026-10-10T08:00:00+00:00', configuration: {} },
    { _id: 'c-auto', name: 'Tự động tối', status: 'SCHEDULED', created_at: '2026-10-14T12:00:00+00:00', configuration: { agent_managed: true, agent_run_id: 'r-auto' } },
    { _id: 'c-new', name: 'Mới nhất', status: 'AWAITING_APPROVAL', created_at: '2026-10-15T01:00:00+00:00' },
  ];
  const runs = [
    { _id: 'r-auto', brief: 'Live tối nay', status: 'COMPLETED', campaign_id: 'c-auto', created_at: '2026-10-14T11:59:00+00:00', decisions: [{ code: 'DATA_GAPS', level: 'adjusted', message: 'x', at: '2026-10-14T11:59:30Z' }] },
    { _id: 'r-old', brief: 'Prompt cũ chưa có lịch', status: 'NEEDS_INPUT', created_at: '2026-10-12T09:00:00+00:00' }, // legacy: no decisions, no mode
    { _id: 'r-new', brief: 'Đang hiểu prompt', status: 'INTERPRETING', created_at: '2026-10-15T02:00:00+00:00', mode: 'autopilot' },
  ];
  const items = L.mergeActivity(campaigns, runs);
  assert.deepEqual(items.map((i) => i.id), ['r-new', 'c-new', 'c-auto', 'r-old', 'c-old']);
  // the run that has a campaign does not appear on its own
  assert.equal(items.filter((i) => i.kind === 'run').length, 2);
  assert.ok(!items.some((i) => i.id === 'r-auto'));

  const auto = items.find((i) => i.id === 'c-auto');
  assert.equal(auto.kind, 'campaign');
  assert.equal(auto.source, 'auto');
  assert.equal(auto.status, 'SCHEDULED');
  assert.equal(auto.title, 'Tự động tối');
  assert.equal(auto.createdAt, '2026-10-14T12:00:00+00:00');
  assert.equal(auto.campaign._id, 'c-auto');
  assert.equal(auto.run._id, 'r-auto');
  assert.equal(auto.run.decisions[0].code, 'DATA_GAPS');

  const manual = items.find((i) => i.id === 'c-old');
  assert.equal(manual.source, 'manual');
  assert.equal(manual.run, null);
  assert.equal(items.find((i) => i.id === 'c-new').source, 'manual'); // no configuration at all

  const legacyRun = items.find((i) => i.id === 'r-old');
  assert.equal(legacyRun.kind, 'run');
  assert.equal(legacyRun.source, 'auto');
  assert.equal(legacyRun.status, 'NEEDS_INPUT');
  assert.equal(legacyRun.title, 'Prompt cũ chưa có lịch');
  assert.equal(legacyRun.campaign, null);
  assert.equal(legacyRun.run._id, 'r-old');
  assert.equal(L.needsYou(legacyRun), true);
});

test('mergeActivity: tolerates missing lists, missing fields and unparseable dates', () => {
  assert.deepEqual(L.mergeActivity(undefined, undefined), []);
  assert.deepEqual(L.mergeActivity(null, 'oops'), []);
  const items = L.mergeActivity(
    [null, { _id: 'a' }, { _id: 'b', created_at: 'garbage' }, { _id: 'c', created_at: '2026-10-15T00:00:00Z', name: 'C' }],
    [undefined, { _id: 'r1' }, { _id: 'r2', brief: 'hi', created_at: '2026-10-14T00:00:00Z' }]
  );
  assert.equal(items.length, 5);
  assert.equal(items[0].id, 'c');
  assert.equal(items[1].id, 'r2');
  for (const item of items) {
    assert.ok(['campaign', 'run'].includes(item.kind));
    assert.ok(['auto', 'manual'].includes(item.source));
    assert.equal(typeof item.title, 'string');
  }
  // agent_run_id that points at nothing: no run attached, no crash
  const orphan = L.mergeActivity([{ _id: 'x', configuration: { agent_managed: true, agent_run_id: 'gone' } }], []);
  assert.equal(orphan[0].source, 'auto');
  assert.equal(orphan[0].run, null);
});

test('mergeActivity does not mutate its inputs', () => {
  const campaigns = Object.freeze([Object.freeze({ _id: 'a', created_at: '2026-10-15T00:00:00Z' }), Object.freeze({ _id: 'b', created_at: '2026-10-16T00:00:00Z' })]);
  const runs = Object.freeze([]);
  const items = L.mergeActivity(campaigns, runs);
  assert.deepEqual(items.map((i) => i.id), ['b', 'a']);
  assert.deepEqual(campaigns.map((c) => c._id), ['a', 'b']);
});

test('filterActivity: all / needs_you / scheduled', () => {
  const items = L.mergeActivity(
    [
      { _id: 'sched', status: 'SCHEDULED', created_at: '2026-10-15T05:00:00Z' },
      { _id: 'appr', status: 'AWAITING_APPROVAL', created_at: '2026-10-15T04:00:00Z' },
      { _id: 'live', status: 'LIVE', pending_external_platforms: ['facebook'], created_at: '2026-10-15T03:00:00Z' },
      { _id: 'pend', status: 'SCHEDULED', pending_external_platforms: ['youtube'], created_at: '2026-10-15T02:00:00Z' },
    ],
    [{ _id: 'stuck', status: 'NEEDS_INPUT', brief: 'b', created_at: '2026-10-15T06:00:00Z' }]
  );
  assert.deepEqual(L.filterActivity(items, 'all').map((i) => i.id), ['stuck', 'sched', 'appr', 'live', 'pend']);
  assert.deepEqual(L.filterActivity(items, 'needs_you').map((i) => i.id), ['stuck', 'appr', 'pend']);
  assert.deepEqual(L.filterActivity(items, 'scheduled').map((i) => i.id), ['sched', 'pend']);
  assert.deepEqual(L.filterActivity(items, 'whatever').map((i) => i.id), ['stuck', 'sched', 'appr', 'live', 'pend']);
  assert.deepEqual(L.filterActivity(undefined, 'all'), []);
  assert.notEqual(L.filterActivity(items, 'all'), items); // a copy, safe to sort/mutate
});

// ------------------------------------------------------------ createLatestGate

test('createLatestGate: only the newest token is current', () => {
  const gate = L.createLatestGate();
  const first = gate.next();
  assert.equal(gate.isCurrent(first), true);
  const second = gate.next();
  assert.equal(gate.isCurrent(first), false);
  assert.equal(gate.isCurrent(second), true);
  assert.notEqual(first, second);
  assert.equal(gate.isCurrent(undefined), false);
  // gates are independent
  const other = L.createLatestGate();
  const t = other.next();
  gate.next();
  assert.equal(other.isCurrent(t), true);
});

test('progressSteps: a finished run without a campaign has nothing active', () => {
  ['COMPLETED', 'RESULTS_READY', 'EMPTY_RESULTS', 'DATA_UNAVAILABLE', 'CANCELLED', 'CAMPAIGN_CANCELLED'].forEach((status) => {
    const steps = L.progressSteps(runItem(status));
    assert.ok(!steps.some((step) => step.state === 'active'), status);
    assert.equal(steps[0].state, 'done', status);
    assert.ok(steps.slice(1).every((step) => step.state === 'todo'), status);
  });
  assert.equal(L.progressSteps(runItem('INTERPRETING'))[0].state, 'active'); // still working
});
