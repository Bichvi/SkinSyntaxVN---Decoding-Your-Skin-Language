'use strict';
// Unit tests for the DOM-free helpers of studio-manual.js. Run: node --test "frontend/public/assets/js/tests/**/*.test.js"
const test = require('node:test');
const assert = require('node:assert/strict');
const M = require('../studio-manual.js');

// ------------------------------------------------------------------ buildQueryBody

test('buildQueryBody sends the filters, the 100-row cap and never q', () => {
  const body = M.buildQueryBody({
    cat1: 'face', cat2: '1002', cat3: '', expiry: 'expiry_17_19', stock: 'stock_high', sales: 'best_30',
    brand: '  CeraVe ', brandCap: true, enoughStock: true,
  });
  assert.deepEqual(body, {
    cat_l1: 'face', cat_l2: '1002', cat_l3: '', expiry: 'expiry_17_19', stock: 'stock_high', sales: 'best_30',
    brand: 'CeraVe', brand_cap: true, enough_stock: true, limit: 100,
  });
  assert.ok(!('q' in body));
});

test('buildQueryBody leaves warehouse_mode and scope_code out until the code is typed', () => {
  assert.ok(!('warehouse_mode' in M.buildQueryBody({ warehouseMode: 'warehouse', scopeCode: '' })));
  assert.ok(!('scope_code' in M.buildQueryBody({ warehouseMode: 'batch', scopeCode: '   ' })));
  assert.ok(!('warehouse_mode' in M.buildQueryBody({ warehouseMode: '', scopeCode: 'KHO-1' })), 'no mode, no scope');
  const body = M.buildQueryBody({ warehouseMode: 'batch', scopeCode: ' LOT-9 ' });
  assert.equal(body.warehouse_mode, 'batch');
  assert.equal(body.scope_code, 'LOT-9');
});

test('buildQueryBody never asks for enough stock when only out-of-stock products are wanted', () => {
  assert.equal(M.buildQueryBody({ stock: 'out_of_stock', enoughStock: true }).enough_stock, false);
  assert.equal(M.buildQueryBody({}).enough_stock, false);
  assert.equal(M.buildQueryBody(null).limit, 100);
});

// ----------------------------------------------------------------- createSelection

const rowsOf = (...ids) => ids.map((id) => ({ id: String(id), name: `SP ${id}`, image: '' }));
const idsOf = (selection) => selection.list().map((item) => item.id);

test('Top N ticks the first N rows while nothing was ticked by hand', () => {
  const selection = M.createSelection();
  selection.startFilter();
  assert.equal(selection.applyTop(rowsOf(1, 2, 3, 4, 5), 3), true);
  assert.deepEqual(idsOf(selection), ['1', '2', '3']);
});

test('Top N leaves the selection alone once the user ticked something for this filtering', () => {
  const selection = M.createSelection();
  selection.startFilter();
  selection.set({ id: '9', name: 'SP 9' }, true);
  assert.equal(selection.applyTop(rowsOf(1, 2, 3), 2), false);
  assert.deepEqual(idsOf(selection), ['9']);
});

test('unticking by hand counts as touching too', () => {
  const selection = M.createSelection();
  selection.startFilter();
  selection.applyTop(rowsOf(1, 2, 3), 3);
  selection.set({ id: '2' }, false);
  assert.equal(selection.applyTop(rowsOf(1, 2, 3), 3), false);
  assert.deepEqual(idsOf(selection), ['1', '3']);
});

test('the next filtering swaps untouched automatic ticks for its own', () => {
  const selection = M.createSelection();
  selection.startFilter();
  selection.applyTop(rowsOf(1, 2, 3), 2); // 1, 2 automatic
  selection.startFilter(); // a new filtering begins
  assert.equal(selection.applyTop(rowsOf(10, 11, 12), 2), true);
  assert.deepEqual(idsOf(selection).sort(), ['10', '11']);
});

test('a hand tick adopts the automatic picks that remain, so a later filtering keeps them', () => {
  const selection = M.createSelection();
  selection.startFilter();
  selection.applyTop(rowsOf(1, 2, 3), 3); // 1, 2, 3 automatic
  selection.set({ id: '7', name: 'SP 7' }, true); // the user accepts the list by ticking one more
  selection.startFilter();
  selection.applyTop(rowsOf(10, 11, 12), 2);
  assert.deepEqual(idsOf(selection).sort(), ['1', '10', '11', '2', '3', '7']);
});

test('unticking one automatic pick adopts the others too', () => {
  const selection = M.createSelection();
  selection.startFilter();
  selection.applyTop(rowsOf(1, 2, 3), 3);
  selection.set({ id: '2' }, false);
  selection.startFilter();
  selection.applyTop(rowsOf(10), 1);
  assert.deepEqual(idsOf(selection).sort(), ['1', '10', '3']);
});

test('setMany and clear count as hand ticks as well', () => {
  const selection = M.createSelection();
  selection.startFilter();
  selection.applyTop(rowsOf(1, 2), 2);
  selection.setMany(rowsOf(5, 6), true); // "Chọn tất cả"
  selection.startFilter();
  selection.applyTop(rowsOf(10), 1);
  assert.deepEqual(idsOf(selection).sort(), ['1', '10', '2', '5', '6']);
  selection.clear();
  assert.equal(selection.size, 0);
});

test('a row ticked by hand earlier stays manual when Top N would pick it', () => {
  const selection = M.createSelection();
  selection.set({ id: '1', name: 'SP 1' }, true);
  selection.startFilter();
  selection.applyTop(rowsOf(1, 2), 2); // 1 is already there, 2 is automatic
  selection.startFilter();
  selection.applyTop(rowsOf(5, 6), 1);
  assert.deepEqual(idsOf(selection).sort(), ['1', '5']);
});

test('changing Top N to "tự chọn" drops the untouched automatic ticks', () => {
  const selection = M.createSelection();
  selection.startFilter();
  selection.applyTop(rowsOf(1, 2, 3), 3);
  selection.applyTop(rowsOf(1, 2, 3), 0);
  assert.equal(selection.size, 0);
});

test('setMany, has, clear and reset', () => {
  const selection = M.createSelection();
  selection.setMany(rowsOf(1, 2, 3), true);
  assert.equal(selection.size, 3);
  assert.equal(selection.has('2'), true);
  assert.equal(selection.has(2), true);
  selection.setMany(rowsOf(2), false);
  assert.deepEqual(idsOf(selection), ['1', '3']);
  selection.clear();
  assert.equal(selection.size, 0);
  selection.set({ id: '4' }, true);
  selection.reset();
  assert.equal(selection.size, 0);
  assert.equal(selection.applyTop(rowsOf(1), 1), true, 'reset forgets that the user touched');
});

test('an item without an id is ignored and a missing name falls back to the id', () => {
  const selection = M.createSelection();
  selection.set({ name: 'x' }, true);
  selection.set(null, true);
  assert.equal(selection.size, 0);
  selection.set({ id: 42 }, true);
  assert.equal(selection.list()[0].name, '42');
});

// ------------------------------------------------------------- category cascade

// The shape AdminAiIdolController::buildCatalogTree() produces: one root per group, numeric ids, own children.
const tree = {
  face: {
    id: 1001, label: 'Chăm Sóc Da Mặt', count: 2473, children: {
      2002: { id: 2002, label: 'Làm Sạch Da', count: 613, children: { 1: { id: 1, label: 'Sữa Rửa Mặt', count: 285 }, 2: { id: 2, label: 'Tẩy Trang Mặt', count: 195 } } },
      6: { id: 6, label: 'Chống Nắng Da Mặt', count: 231, children: {} },
    },
  },
  makeup: {
    id: 22, label: 'Trang Điểm', count: 40, children: {
      23: { id: 23, label: 'Trang Điểm Môi', count: 40, children: { 29: { id: 29, label: 'Son Kem / Tint', count: 40 } } },
    },
  },
};

test('rootCategory finds a group by its numeric id or by its key', () => {
  assert.equal(M.rootCategory(tree, '1001').label, 'Chăm Sóc Da Mặt');
  assert.equal(M.rootCategory(tree, 'face').id, 1001);
  assert.equal(M.rootCategory(tree, '22').label, 'Trang Điểm');
  assert.equal(M.rootCategory(tree, 'makeup').id, 22);
  assert.equal(M.rootCategory(tree, 22).id, 22);
  assert.equal(M.rootCategory(tree, 'body'), null);
  assert.equal(M.rootCategory(tree, ''), null);
  assert.equal(M.rootCategory([], '1001'), null, 'an empty PHP array arrives as []');
  assert.equal(M.rootCategory(null, '1001'), null);
});

test('level2Options lists the children of the group that was picked, not always the face group', () => {
  // JS lists integer-like keys in ascending order, which is how the page has always listed them.
  assert.deepEqual(M.level2Options(tree, '1001').map((o) => [o.value, o.count]), [['6', 231], ['2002', 613]]);
  assert.deepEqual(M.level2Options(tree, '22').map((o) => [o.value, o.label]), [['23', 'Trang Điểm Môi']]);
  assert.deepEqual(M.level2Options(tree, 'face').map((o) => o.value), ['6', '2002']);
  assert.deepEqual(M.level2Options(tree, 'hair'), [], 'a group with no root has nothing to offer');
  assert.deepEqual(M.level2Options(tree, ''), []);
});

test('level3Options lists the leaves of the chosen category under the chosen group', () => {
  assert.deepEqual(M.level3Options(tree, '1001', '2002').map((o) => o.value), ['1', '2']);
  assert.deepEqual(M.level3Options(tree, '22', '23').map((o) => o.label), ['Son Kem / Tint']);
  assert.deepEqual(M.level3Options(tree, '22', '2002'), [], 'a category of another group is not found');
  assert.deepEqual(M.level3Options(tree, '1001', '6'), []);
  assert.deepEqual(M.level3Options(tree, '1001', ''), []);
});

test('categoryOptions reads counts, keeps a missing count out and ignores junk nodes', () => {
  const options = M.categoryOptions({ 5: { id: 5, label: 'A', count: 3 }, x: { label: 'B' }, y: null, z: 'text' });
  assert.deepEqual(options.map((o) => [o.value, o.label, Number.isFinite(o.count)]), [['5', 'A', true], ['x', 'B', false]]);
  assert.deepEqual(M.categoryOptions(undefined), []);
});

// --------------------------------------------------------- products from the API

test('normalizeProduct keeps what the list needs and drops rows without an id', () => {
  assert.equal(M.normalizeProduct({ name: 'x' }), null);
  assert.equal(M.normalizeProduct(null), null);
  const product = M.normalizeProduct({ product_id: 995, name: ' Kem ', image: 'a.jpg', stock: 12, metric_label: 'Số lượng bán giảm dần', metric_value: 42 });
  assert.deepEqual(product, { id: '995', name: 'Kem', image: 'a.jpg', stock: 12, metric: 'Số lượng bán giảm dần: 42' });
  assert.equal(M.normalizeProduct({ product_id: 'A1' }).name, 'Sản phẩm A1');
  assert.equal(M.normalizeProduct({ product_id: 'A1' }).stock, null);
});

test('formatMetric prints numbers, dates and label-only metrics', () => {
  assert.equal(M.formatMetric('Tăng trưởng', 12), 'Tăng trưởng: 12');
  assert.equal(M.formatMetric('Hạn dùng gần nhất trước', '2027-03-09'), 'Hạn dùng gần nhất trước: 09/03/2027');
  assert.equal(M.formatMetric('Mã sản phẩm tăng dần', null), 'Mã sản phẩm tăng dần');
  assert.equal(M.formatMetric('', 5), '5');
  assert.equal(M.formatMetric(undefined, undefined), '');
});

test('missingDataNotes names the gap in words and never prints the internal key', () => {
  const notes = M.missingDataNotes({ sales_snapshot: 12, stock: 0, made_up_key: 3 });
  assert.equal(notes.length, 2);
  assert.equal(notes[0], 'Chưa có số liệu bán hàng theo kỳ cho 12 sản phẩm.');
  assert.match(notes[1], /^Chưa có một số dữ liệu cho 3 sản phẩm\.$/);
  assert.ok(notes.every((note) => !note.includes('made_up_key') && !note.includes('sales_snapshot')));
  assert.deepEqual(M.missingDataNotes(null), []);
  assert.deepEqual(M.missingDataNotes([]), []);
  assert.deepEqual(M.missingDataNotes({}), []);
});

test('productRowMarkup escapes every string from the API', () => {
  const html = M.productRowMarkup({
    id: '1"><b>', name: '<img src=x onerror=alert(1)>', image: 'x" onerror="alert(1)', stock: 0, metric: '<script>',
  }, true);
  assert.ok(!/<img src=x/.test(html), 'a product name never becomes a tag');
  assert.ok(!html.includes('<script>'));
  assert.ok(!html.includes('<b>'));
  assert.match(html, /&lt;img src=x onerror=alert\(1\)&gt;/);
  assert.match(html, /checked/);
  assert.match(html, /is-selected/);
  assert.match(html, /is-out/);
  assert.match(html, /Hết hàng/);
  assert.ok(!/ onerror="alert/.test(html), 'a quote in the image address stays inside the attribute');
});

test('productRowMarkup tones the stock and leaves an unticked row plain', () => {
  const low = M.productRowMarkup({ id: '1', name: 'A', image: '', stock: 5, metric: '' }, false);
  assert.match(low, /is-low/);
  assert.ok(!/checked/.test(low));
  assert.ok(!/is-selected/.test(low));
  assert.ok(!/wz-metric/.test(low));
  assert.match(M.productRowMarkup({ id: '1', name: 'A', image: '', stock: 500, metric: 'm' }, false), /Tồn kho: 500/);
  assert.match(M.productRowMarkup({ id: '1', name: 'A', image: '', stock: null, metric: '' }, false), /chưa rõ/);
});

test('chipsMarkup labels each remove button with the escaped name', () => {
  const html = M.chipsMarkup([{ id: '7', name: 'Kem <b>chống</b> nắng' }, { id: '8', name: '' }]);
  assert.match(html, /data-wz-remove="7"/);
  assert.match(html, /aria-label="Bỏ chọn Kem &lt;b&gt;chống&lt;\/b&gt; nắng"/);
  assert.ok(!html.includes('<b>'));
  assert.match(html, /aria-label="Bỏ chọn 8"/);
  assert.equal(M.chipsMarkup([]), '');
});

// -------------------------------------------------------------- time + schedule

test('formatInZone reads the wall clock of Asia/Ho_Chi_Minh', () => {
  assert.equal(M.formatInZone(new Date('2026-10-15T13:00:00Z')), '2026-10-15T20:00');
  assert.equal(M.formatInZone(new Date('2026-10-15T17:30:00Z')), '2026-10-16T00:30', 'midnight is 00, not 24');
  assert.equal(M.formatInZone('not a date'), '');
});

test('isFutureSlot compares wall-clock strings and rejects junk', () => {
  assert.equal(M.isFutureSlot('2026-10-15T20:00', '2026-10-15T19:59'), true);
  assert.equal(M.isFutureSlot('2026-10-15T20:00', '2026-10-15T20:00'), false);
  assert.equal(M.isFutureSlot('2026-10-15T19:00', '2026-10-15T20:00'), false);
  assert.equal(M.isFutureSlot('2026-10-15T20:00:30', '2026-10-15T19:00'), true, 'seconds are ignored');
  assert.equal(M.isFutureSlot('', '2026-10-15T20:00'), false);
  assert.equal(M.isFutureSlot('tomorrow', '2026-10-15T20:00'), false);
});

test('formatSlot turns a datetime-local value into hour then date without a Date', () => {
  assert.equal(M.formatSlot('2026-10-15T20:05'), '20:05 15/10/2026');
  assert.equal(M.formatSlot(''), '');
  assert.equal(M.formatSlot('nope'), '');
});

test('validateSchedule: a product video needs nothing', () => {
  assert.deepEqual(M.validateSchedule({ purpose: 'product_video', at: '', duration: 0, platforms: [] }, '2026-10-15T10:00'), { ok: true });
});

test('validateSchedule: a livestream needs a future time, 1-720 minutes and a channel', () => {
  const now = '2026-10-15T10:00';
  const ok = { purpose: 'live_only', at: '2026-10-15T20:00', duration: 60, platforms: ['internal'] };
  assert.deepEqual(M.validateSchedule(ok, now), { ok: true });
  assert.deepEqual(M.validateSchedule({ ...ok, purpose: 'live_and_product' }, now), { ok: true });
  assert.equal(M.validateSchedule({ ...ok, at: '' }, now).field, 'at');
  assert.equal(M.validateSchedule({ ...ok, at: '2026-10-15T09:00' }, now).message, 'Chọn giờ phát nằm trong tương lai.');
  for (const duration of [0, 721, '', '1.5', 'abc']) {
    const result = M.validateSchedule({ ...ok, duration }, now);
    assert.equal(result.ok, false, `duration ${duration}`);
    assert.equal(result.field, 'duration');
    assert.equal(result.message, 'Thời lượng phát từ 1 đến 720 phút.');
  }
  assert.equal(M.validateSchedule({ ...ok, duration: '720' }, now).ok, true);
  assert.equal(M.validateSchedule({ ...ok, duration: 1 }, now).ok, true);
  const noChannel = M.validateSchedule({ ...ok, platforms: [] }, now);
  assert.equal(noChannel.field, 'platforms');
  assert.equal(noChannel.message, 'Chọn ít nhất một nơi phát.');
});

// ------------------------------------------------------------------------- rtmp

test('rtmpPlatformsOf reads the health payload and says null when it does not know', () => {
  assert.deepEqual(M.rtmpPlatformsOf({ services: { rtmp: { platforms: ['youtube'] } } }), ['youtube']);
  assert.deepEqual(M.rtmpPlatformsOf({ services: { rtmp: { platforms: [] } } }), []);
  assert.deepEqual(M.rtmpPlatformsOf({ rtmp: { platforms: ['facebook'] } }), ['facebook']);
  assert.equal(M.rtmpPlatformsOf(null), null);
  assert.equal(M.rtmpPlatformsOf({ services: {} }), null);
  assert.equal(M.rtmpPlatformsOf({ services: { rtmp: { status: 'ok' } } }), null);
});

test('rtmpWarning names the chosen channels that have no RTMP and stays quiet when unknown', () => {
  assert.equal(M.rtmpWarning(['internal', 'youtube'], ['facebook']), 'Chưa cấu hình RTMP cho YouTube.');
  assert.equal(M.rtmpWarning(['youtube', 'facebook'], []), 'Chưa cấu hình RTMP cho YouTube, Facebook.');
  assert.equal(M.rtmpWarning(['internal'], []), '', 'the internal channel needs no RTMP');
  assert.equal(M.rtmpWarning(['youtube'], ['youtube']), '');
  assert.equal(M.rtmpWarning(['youtube'], null), '', 'health unknown: no warning');
});

// ------------------------------------------------------------------------ styles

test('pruneStyles drops what the purpose forbids, unknown keys and duplicates', () => {
  assert.deepEqual(M.pruneStyles(['gentle', 'closing', 'pricing', 'ingredients'], 'product_video'), ['gentle', 'ingredients']);
  assert.deepEqual(M.pruneStyles(['gentle', 'closing', 'gentle', 'nope'], 'live_only'), ['gentle', 'closing']);
  assert.deepEqual(M.pruneStyles(['closing', 'pricing'], 'live_and_product'), ['closing', 'pricing']);
  assert.deepEqual(M.pruneStyles(null, 'product_video'), []);
});

// ---------------------------------------------------------------------- payload

test('a product video payload has no schedule, no channels and no price styles', () => {
  const payload = M.buildCampaignPayload({
    name: '  Video kem  ', productIds: ['1', '2'], purpose: 'product_video', scheduledAt: '2026-10-15T20:00',
    platforms: ['youtube'], duration: 90, contentMode: 'Scientific Review', format: '9:16', character: 'syna_3d',
    styles: ['gentle', 'closing', 'pricing'], avatarAsset: 'ignored.mp4', backgroundAsset: 'bg.png',
  });
  assert.deepEqual(payload, {
    name: 'Video kem',
    product_ids: ['1', '2'],
    purpose: 'product_video',
    scheduled_at: null,
    platforms: [],
    duration_minutes: 60,
    content_mode: 'Scientific Review',
    configuration: {
      format: '16:9',
      length_mode: 'Long (90-150s)',
      avatar_mode: 'syna_3d',
      avatar_asset: '',
      background_asset: 'bg.png',
      narration_styles: ['gentle'],
    },
  });
});

test('a livestream payload keeps the schedule, channels, duration and price styles', () => {
  const payload = M.buildCampaignPayload({
    name: '', productIds: ['5'], purpose: 'live_and_product', scheduledAt: '2026-10-15T20:00', platforms: ['internal', 'youtube'],
    duration: '45', contentMode: 'Product Intro', format: '16:9', character: 'syna_3d', styles: ['closing', 'pricing'],
  });
  assert.equal(payload.purpose, 'live_and_product');
  assert.equal(payload.scheduled_at, '2026-10-15T20:00');
  assert.deepEqual(payload.platforms, ['internal', 'youtube']);
  assert.equal(payload.duration_minutes, 45);
  assert.equal(payload.configuration.length_mode, 'Short (30-60s)');
  assert.deepEqual(payload.configuration.narration_styles, ['closing', 'pricing']);
});

test('a custom character payload carries the uploaded file names and the chosen frame', () => {
  const video = M.buildCampaignPayload({
    productIds: ['1'], purpose: 'product_video', character: 'custom', avatarIsVideo: true, avatarAsset: 'a.mp4', backgroundAsset: '', format: '9:16', styles: [],
  });
  assert.equal(video.configuration.avatar_mode, 'motion_video');
  assert.equal(video.configuration.avatar_asset, 'a.mp4');
  assert.equal(video.configuration.format, '9:16');
  const photo = M.buildCampaignPayload({ productIds: ['1'], character: 'custom', avatarIsVideo: false, avatarAsset: 'a.png', format: '16:9' });
  assert.equal(photo.configuration.avatar_mode, 'photo');
  assert.deepEqual(photo.configuration.narration_styles, []);
});

test('buildCampaignPayload tolerates a missing input', () => {
  const payload = M.buildCampaignPayload(null);
  assert.deepEqual(payload.product_ids, []);
  assert.equal(payload.purpose, 'product_video');
  assert.equal(payload.content_mode, 'Product Intro');
});

// ------------------------------------------------------------------------ errors

test('friendlyError puts the backend English messages into Vietnamese and passes the rest through', () => {
  assert.equal(M.friendlyError('Schedule needs at least 24 minutes to prepare 3 product(s)'), 'Giờ phát cần cách hiện tại ít nhất 24 phút để chuẩn bị 3 sản phẩm.');
  assert.equal(M.friendlyError('A campaign supports at most 100 products'), 'Một chương trình có tối đa 100 sản phẩm. Hãy bỏ bớt sản phẩm.');
  assert.equal(M.friendlyError('Select at least one product'), 'Chọn ít nhất một sản phẩm.');
  assert.equal(M.friendlyError('Select at least one supported platform'), 'Chọn ít nhất một nơi phát.');
  assert.equal(M.friendlyError('Bộ lọc không hợp lệ: sales'), 'Bộ lọc không hợp lệ: sales');
  assert.equal(M.friendlyError(undefined), '');
});

// ------------------------------------------------------------------------ review

const reviewView = (extra) => ({
  products: [{ id: '1', name: 'Kem <b>chống nắng</b>' }, { id: '2', name: 'Serum' }],
  purposeLabel: 'Video sản phẩm',
  live: false,
  name: '',
  contentModeLabel: 'Giới thiệu sản phẩm',
  styleLabels: ['Tư vấn nhẹ nhàng'],
  custom: false,
  format: '16:9',
  ...extra,
});

test('reviewMarkup has one block per step with an edit button that names its step', () => {
  const html = M.reviewMarkup(reviewView());
  assert.deepEqual([...html.matchAll(/data-wz-edit="(\d)"/g)].map((m) => m[1]), ['1', '2', '3']);
  assert.match(html, /2 sản phẩm/);
  assert.match(html, /Tư vấn nhẹ nhàng/);
  assert.match(html, /Tự đặt theo ngày/);
  assert.match(html, /Syna 3D/);
  assert.match(html, /Tự duyệt sau kiểm duyệt/);
  assert.ok(!html.includes('<b>'), 'product names are escaped');
  assert.ok(!/Giờ phát/.test(html), 'a product video has no broadcast time');
});

test('reviewMarkup shows the broadcast time, channels and the approval step for a livestream', () => {
  const html = M.reviewMarkup(reviewView({
    live: true, purposeLabel: 'Chỉ livestream', slot: '2026-10-15T20:00', duration: 60, channels: ['Nội bộ', 'YouTube'],
    custom: true, avatarName: 'a<b>.mp4', backgroundName: 'bg.png', styleLabels: [], name: 'Live <i>tối</i>',
  }));
  assert.match(html, /20:00 15\/10\/2026/);
  assert.match(html, /60 phút/);
  assert.match(html, /Nội bộ, YouTube/);
  assert.match(html, /Bạn duyệt kịch bản trước khi render/);
  assert.match(html, /Nhân vật riêng \(a&lt;b&gt;\.mp4\)/);
  assert.match(html, /bg\.png/);
  assert.match(html, /Không chọn/);
  assert.ok(!html.includes('<i>') && !html.includes('<b>'));
});
