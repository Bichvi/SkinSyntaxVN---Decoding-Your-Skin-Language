'use strict';
// Unit tests for the DOM-free helpers of studio-activity.js. Run: node --test "frontend/public/assets/js/tests/**/*.test.js"
const test = require('node:test');
const assert = require('node:assert/strict');
const A = require('../studio-activity.js');
const L = require('../studio-logic.js');

const campaign = (over = {}) => ({ _id: 'c1', name: 'Kem <b>chống nắng</b>', status: 'SCHEDULED', purpose: 'live_only', platforms: ['internal'], product_ids: ['1', '2'], configuration: {}, ...over });
const merged = (c, runs = []) => L.mergeActivity([c], runs)[0];

test('pollDelay is 5 s while a step is active and 30 s otherwise', () => {
  assert.equal(A.pollDelay([]), 30000);
  assert.equal(A.pollDelay(L.mergeActivity([campaign({ status: 'SCHEDULED' })], [])), 30000);
  assert.equal(A.pollDelay(L.mergeActivity([campaign({ status: 'GENERATING' })], [])), 5000);
  assert.equal(A.pollDelay(L.mergeActivity([], [{ _id: 'r1', status: 'INTERPRETING', brief: 'x' }])), 5000);
  assert.equal(A.pollDelay(L.mergeActivity([campaign({ status: 'AWAITING_APPROVAL' })], [])), 30000); // waiting for staff, nothing is moving
});

test('pendingPlatformsOf reads the campaign, the list row or the run view', () => {
  assert.deepEqual(A.pendingPlatformsOf(campaign({ pending_external_platforms: ['youtube'] })), ['youtube']);
  assert.deepEqual(A.pendingPlatformsOf(merged(campaign({ pending_external_platforms: ['facebook'] }))), ['facebook']);
  assert.deepEqual(A.pendingPlatformsOf({ run: { campaign_pending_external_platforms: ['youtube'] } }), ['youtube']);
  assert.deepEqual(A.pendingPlatformsOf(campaign()), []);
  assert.deepEqual(A.pendingPlatformsOf(null), []);
});

test('canApprovePlatform allows only the statuses before the show starts', () => {
  ['VALIDATING', 'GENERATING', 'AWAITING_APPROVAL', 'NEEDS_ATTENTION', 'SCHEDULED'].forEach((s) => assert.equal(A.canApprovePlatform(s), true, s));
  ['LIVE', 'COMPLETED', 'CANCELLED', 'FAILED', 'VIDEOS_READY', ''].forEach((s) => assert.equal(A.canApprovePlatform(s), false, s));
});

test('segmentPageState labels the product page from the ai_product_videos record, or says nothing', () => {
  assert.equal(A.segmentPageState({}), null);
  assert.match(A.segmentPageState({ product_page: { eligible: false }, publication: null }).text, /chỉ livestream/);
  assert.deepEqual(A.segmentPageState({ publication: { is_published: true, source: 'auto' } }), { text: 'Đã đăng trang SP', live: true, auto: true });
  assert.deepEqual(A.segmentPageState({ publication: { is_published: true, source: null } }), { text: 'Đã đăng trang SP', live: true, auto: false });
  assert.deepEqual(A.segmentPageState({ publication: { is_published: false, source: 'auto' } }), { text: 'Chưa đăng', live: false, auto: true });
  assert.deepEqual(A.segmentPageState({ publication: null }), { text: 'Chưa có bản xuất bản', live: false, auto: false });
});

test('publishedCount counts only published publications', () => {
  assert.equal(A.publishedCount([{ publication: { is_published: true } }, { publication: { is_published: false } }, { publication: null }, {}]), 1);
  assert.equal(A.publishedCount(null), 0);
});

test('publicationUrl links READY, eligible videos to the publish page', () => {
  const seg = { status: 'READY', video_id: 'abc123', product_page: { eligible: true } };
  assert.equal(A.publicationUrl(seg), 'index.php?r=admin_ai_product_videos&video_id=abc123');
  assert.equal(A.publicationUrl({ ...seg, status: 'FAILED' }), '');
  assert.equal(A.publicationUrl({ status: 'READY' }), '');
  assert.equal(A.publicationUrl({ ...seg, product_page: { eligible: false } }), '');
});

test('datetime-local helpers use Asia/Ho_Chi_Minh', () => {
  assert.equal(A.toDatetimeLocal('2026-10-15T13:00:00Z'), '2026-10-15T20:00');
  assert.equal(A.toDatetimeLocal(''), '');
  assert.equal(A.vnTime('2026-10-15T20:00'), Date.parse('2026-10-15T13:00:00Z'));
  assert.ok(Number.isNaN(A.vnTime('')));
});

test('cardMarkup escapes the title and exposes steps with accessible names', () => {
  const html = A.cardMarkup(merged(campaign({ status: 'AWAITING_APPROVAL', pending_external_platforms: ['youtube'] })), { active: true });
  assert.ok(!html.includes('<b>'));
  assert.match(html, /Kem &lt;b&gt;chống nắng&lt;\/b&gt;/);
  assert.match(html, /aria-label="Kiểm duyệt: cần bạn"/);
  assert.match(html, /Chờ cho phép YouTube/);
  assert.match(html, /aria-pressed="true"/);
  assert.match(html, /Thủ công/);
});

test('cardMarkup hides the pending chip once the permission window is over', () => {
  const html = A.cardMarkup(merged(campaign({ status: 'LIVE', pending_external_platforms: ['youtube'] })));
  assert.ok(!/Chờ cho phép/.test(html));
});
