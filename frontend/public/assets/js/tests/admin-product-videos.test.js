'use strict';
// Unit tests for the pure helpers of the product-video page. Run: node --test "frontend/public/assets/js/tests/**/*.test.js"
const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const P = require('../admin-product-videos.js');

const video = (extra) => Object.assign({ video_id: 'v1', title: 'Serum Vitamin C', campaign_name: 'Tuần lễ Đẹp', associated_product_ids: ['101', '202'], is_published: false }, extra);

test('escapeHtml escapes the five HTML specials and tolerates null', () => {
  assert.equal(P.escapeHtml(`<img src=x onerror="a('b')">&`), '&lt;img src=x onerror=&quot;a(&#39;b&#39;)&quot;&gt;&amp;');
  assert.equal(P.escapeHtml(null), '');
  assert.equal(P.escapeHtml(undefined), '');
  assert.equal(P.escapeHtml(0), '0');
});

test('normalizeText strips Vietnamese accents and đ', () => {
  assert.equal(P.normalizeText('Tuần lễ Đẹp'), 'tuan le dep');
  assert.equal(P.normalizeText(null), '');
});

test('formatDuration pads minutes and seconds and clamps bad input', () => {
  assert.equal(P.formatDuration(65.9), '01:05');
  assert.equal(P.formatDuration(0), '00:00');
  assert.equal(P.formatDuration(-5), '00:00');
  assert.equal(P.formatDuration('abc'), '00:00');
  assert.equal(P.formatDuration(3725), '62:05');
});

test('isAutoSource is true only for source "auto" (any case)', () => {
  assert.equal(P.isAutoSource({ source: 'auto' }), true);
  assert.equal(P.isAutoSource({ source: 'AUTO' }), true);
  assert.equal(P.isAutoSource({ source: '' }), false);
  assert.equal(P.isAutoSource({ source: 'manual' }), false);
  assert.equal(P.isAutoSource({}), false);
  assert.equal(P.isAutoSource(null), false);
});

test('countVideos splits published and unpublished', () => {
  const list = [video({ is_published: true }), video({ video_id: 'v2' }), video({ video_id: 'v3', is_published: true })];
  assert.deepEqual(P.countVideos(list), { all: 3, published: 2, unpublished: 1 });
  assert.deepEqual(P.countVideos(null), { all: 0, published: 0, unpublished: 0 });
});

test('filterVideos applies the published filter and an accent-insensitive search on title, campaign and product ids', () => {
  const a = video({ video_id: 'a', is_published: true });
  const b = video({ video_id: 'b', title: 'Kem chống nắng', campaign_name: 'Mùa hè', associated_product_ids: ['303'] });
  const all = [a, b];
  assert.deepEqual(P.filterVideos(all, 'all', '').map((v) => v.video_id), ['a', 'b']);
  assert.deepEqual(P.filterVideos(all, 'published', '').map((v) => v.video_id), ['a']);
  assert.deepEqual(P.filterVideos(all, 'unpublished', '').map((v) => v.video_id), ['b']);
  assert.deepEqual(P.filterVideos(all, 'all', 'chong nang').map((v) => v.video_id), ['b']);
  assert.deepEqual(P.filterVideos(all, 'all', 'tuan le dep').map((v) => v.video_id), ['a']);
  assert.deepEqual(P.filterVideos(all, 'all', '303').map((v) => v.video_id), ['b']);
  assert.deepEqual(P.filterVideos(all, 'published', 'chong nang'), []);
  assert.deepEqual(P.filterVideos(null, 'all', ''), []);
});

test('discountText formats percentage and fixed discounts', () => {
  assert.equal(P.discountText({ discount_type: 'percentage', discount_value: 15 }), '15%');
  assert.match(P.discountText({ discount_type: 'fixed', discount_value: 50000 }), /^50[.,\s ]?000 đ$/u);
  assert.equal(P.discountText(null), '');
});

test('toLocalInput keeps server wall-clock strings and formats Dates for datetime-local', () => {
  assert.equal(P.toLocalInput('2026-10-15 09:30:00'), '2026-10-15T09:30');
  assert.equal(P.toLocalInput('2026-10-15T23:05'), '2026-10-15T23:05');
  assert.equal(P.toLocalInput(new Date(2026, 9, 5, 7, 4)), '2026-10-05T07:04');
  assert.equal(P.toLocalInput(''), '');
  assert.equal(P.toLocalInput('not a date'), '');
  assert.equal(P.toLocalInput(new Date('x')), '');
});

test('reconcilePrimary keeps a still-selected primary, else falls back to the first selected', () => {
  assert.equal(P.reconcilePrimary(new Set(['1', '2']), '2'), '2');
  assert.equal(P.reconcilePrimary(new Set(['1', '2']), '9'), '1');
  assert.equal(P.reconcilePrimary(new Set(), '9'), '');
  assert.equal(P.reconcilePrimary(['5'], undefined), '5');
});

test('validateReward returns the first problem, in form order', () => {
  const ok = { name: 'Voucher', type: 'percentage', value: '10', start: '2026-10-15T08:00', end: '2026-10-22T08:00', quota: '100', selectedCount: 2 };
  assert.equal(P.validateReward(ok, null), '');
  assert.equal(P.validateReward(Object.assign({}, ok, { name: '  ' }), null), 'Vui lòng nhập tên chiến dịch ưu đãi.');
  assert.equal(P.validateReward(Object.assign({}, ok, { value: '0' }), null), 'Mức giảm phần trăm phải từ 1 đến 100%.');
  assert.equal(P.validateReward(Object.assign({}, ok, { value: '101' }), null), 'Mức giảm phần trăm phải từ 1 đến 100%.');
  assert.equal(P.validateReward(Object.assign({}, ok, { type: 'fixed', value: '0' }), null), 'Mức giảm tiền cố định phải lớn hơn 0 VNĐ.');
  assert.equal(P.validateReward(Object.assign({}, ok, { type: 'fixed', value: '20000' }), null), '');
  assert.equal(P.validateReward(Object.assign({}, ok, { start: '' }), null), 'Vui lòng chọn ngày bắt đầu và kết thúc.');
  assert.equal(P.validateReward(Object.assign({}, ok, { end: '2026-10-15T08:00' }), null), 'Ngày bắt đầu phải trước ngày kết thúc.');
  assert.equal(P.validateReward(Object.assign({}, ok, { quota: '0' }), null), 'Tổng hạn ngạch phát hành phải lớn hơn 0.');
  assert.equal(P.validateReward(Object.assign({}, ok, { selectedCount: 0 }), null), 'Vui lòng chọn ít nhất 1 sản phẩm áp dụng voucher.');
});

test('validateReward refuses a quota below the vouchers already issued', () => {
  const values = { name: 'V', type: 'percentage', value: '10', start: '2026-10-15T08:00', end: '2026-10-22T08:00', quota: '20', selectedCount: 1 };
  assert.match(P.validateReward(values, { issued_count: 35 }), /không thể nhỏ hơn số voucher đã phát hành thực tế \(35\)/);
  assert.equal(P.validateReward(values, { issued_count: 20 }), '');
});

test('the page script is self-contained: it renders the auto badge and escapes data', () => {
  const source = fs.readFileSync(path.join(__dirname, '..', 'admin-product-videos.js'), 'utf8');
  assert.match(source, /isAutoSource\(v\)/);
  assert.match(source, /Tự động/);
  assert.doesNotMatch(source, /ai-idol-studio|StudioLogic|StudioCore/);
  assert.match(source, /new URLSearchParams\(window\.location\.search\)\.get\('video_id'\)/);
});
