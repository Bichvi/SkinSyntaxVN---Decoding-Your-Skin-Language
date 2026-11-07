'use strict';
// Unit tests for the DOM-free helpers of studio-core.js. Run: node --test "frontend/public/assets/js/tests/**/*.test.js"
const test = require('node:test');
const assert = require('node:assert/strict');
const C = require('../studio-core.js');

// ---------------------------------------------------------------- decisionsMarkup

test('decisionsMarkup maps each level to its icon, hidden label and escaped message', () => {
  const html = C.decisionsMarkup([
    { code: 'A', level: 'done', message: 'Đã chọn 5 sản phẩm.' },
    { code: 'B', level: 'adjusted', message: 'Xin 10, tìm được 7 → làm 7.' },
    { code: 'C', level: 'waiting', message: 'YouTube chờ bạn cho phép.' },
    { code: 'D', level: 'stopped', message: 'Không tìm thấy sản phẩm nào.' },
  ]);
  assert.match(html, /^<ol class="studio-decisions" role="list">/);
  assert.match(html, /<\/ol>$/);
  const rows = html.match(/<li class="[^"]*">.*?<\/li>/g);
  assert.equal(rows.length, 4);
  assert.match(rows[0], /is-done/);
  assert.match(rows[0], /bi-check2-circle/);
  assert.match(rows[0], /<span class="visually-hidden">Đã làm: <\/span>/);
  assert.match(rows[0], /Đã chọn 5 sản phẩm\./);
  assert.match(rows[1], /is-adjusted/);
  assert.match(rows[1], /bi-exclamation-triangle/);
  assert.match(rows[1], /Đã điều chỉnh/);
  assert.match(rows[2], /is-waiting/);
  assert.match(rows[2], /bi-lock/);
  assert.match(rows[2], /Chờ bạn/);
  assert.match(rows[3], /is-stopped/);
  assert.match(rows[3], /bi-x-circle/);
  assert.match(rows[3], /Đã dừng/);
});

test('decisionsMarkup keeps the order of the list', () => {
  const html = C.decisionsMarkup([
    { level: 'waiting', message: 'thứ nhất' },
    { level: 'done', message: 'thứ hai' },
    { level: 'stopped', message: 'thứ ba' },
  ]);
  assert.ok(html.indexOf('thứ nhất') < html.indexOf('thứ hai'));
  assert.ok(html.indexOf('thứ hai') < html.indexOf('thứ ba'));
});

test('decisionsMarkup escapes the message', () => {
  const html = C.decisionsMarkup([{ level: 'done', message: '<img src=x onerror="alert(1)"> & "q" \'s\'' }]);
  assert.ok(!html.includes('<img'));
  assert.match(html, /&lt;img src=x onerror=&quot;alert\(1\)&quot;&gt; &amp; &quot;q&quot; &#39;s&#39;/);
});

test('decisionsMarkup gives an unknown level the info icon and never copies it into a class', () => {
  const html = C.decisionsMarkup([
    { level: 'mystery', message: 'cấp lạ' },
    { level: '"><script>', message: 'cấp độc' },
    { message: 'không có cấp' },
  ]);
  assert.equal(html.match(/bi-info-circle/g).length, 3);
  assert.equal(html.match(/is-info/g).length, 3);
  assert.ok(!html.includes('mystery'));
  assert.ok(!html.includes('<script>'));
  assert.match(html, /<span class="visually-hidden">[^<]+: <\/span>/);
});

test('decisionsMarkup tolerates level case', () => {
  assert.match(C.decisionsMarkup([{ level: 'DONE', message: 'x' }]), /is-done/);
});

test('decisionsMarkup returns an empty string for empty, absent or unusable input', () => {
  assert.equal(C.decisionsMarkup([]), '');
  assert.equal(C.decisionsMarkup(), '');
  assert.equal(C.decisionsMarkup(null), '');
  assert.equal(C.decisionsMarkup('done'), '');
  assert.equal(C.decisionsMarkup({ level: 'done', message: 'x' }), '');
  assert.equal(C.decisionsMarkup([null, undefined, 5, 'x', { level: 'done' }, { level: 'done', message: '   ' }]), '');
});

test('decisionsMarkup skips unusable entries but keeps the valid ones', () => {
  const html = C.decisionsMarkup([null, { level: 'done', message: 'giữ lại' }, { level: 'done', message: '' }]);
  assert.equal(html.match(/<li /g).length, 1);
  assert.match(html, /giữ lại/);
});

// ---------------------------------------------------------------- other helpers

test('escapeHtml escapes markup characters and tolerates null', () => {
  assert.equal(C.escapeHtml('<a href="x">\'&\'</a>'), '&lt;a href=&quot;x&quot;&gt;&#39;&amp;&#39;&lt;/a&gt;');
  assert.equal(C.escapeHtml(null), '');
  assert.equal(C.escapeHtml(undefined), '');
  assert.equal(C.escapeHtml(12), '12');
});

test('normalize strips Vietnamese diacritics and lowercases', () => {
  assert.equal(C.normalize('Kem Chống Nắng Đỏ'), 'kem chong nang do');
  assert.equal(C.normalize('đ'), 'd');
});

test('formatDate formats in Asia/Ho_Chi_Minh and falls back for missing or bad dates', () => {
  const text = C.formatDate('2026-10-15T13:00:00Z'); // 20:00 in Ho Chi Minh (UTC+7)
  assert.match(text, /20:00/);
  assert.match(text, /15\/10\/2026/);
  assert.equal(C.formatDate(''), 'Chưa có lịch');
  assert.equal(C.formatDate(null), 'Chưa có lịch');
  assert.equal(C.formatDate('not a date'), 'Chưa có lịch');
  assert.equal(C.formatDate(undefined, '—'), '—');
});

test('statusName and badge use Vietnamese names and ignore prototype keys', () => {
  assert.equal(C.statusName('AWAITING_APPROVAL'), 'Chờ duyệt');
  assert.equal(C.statusName('NEEDS_INPUT'), 'Cần bạn xác nhận');
  assert.equal(C.statusName('SKIPPED'), 'Đã bỏ qua');
  assert.equal(C.statusName('SOMETHING_NEW'), 'SOMETHING_NEW');
  assert.equal(C.statusName(''), 'Đang chờ');
  assert.equal(C.statusName(undefined), 'Đang chờ');
  assert.equal(C.statusName('constructor'), 'constructor');
  assert.equal(C.badge('SCHEDULED'), '<span class="status SCHEDULED">Đã lên lịch</span>');
  assert.equal(C.badge('LIVE', 'Đang phát nội bộ'), '<span class="status LIVE">Đang phát nội bộ</span>');
  assert.equal(C.badge('"><x>'), '<span class="status &quot;&gt;&lt;x&gt;">&quot;&gt;&lt;x&gt;</span>');
});

test('platformName labels known channels and passes others through', () => {
  assert.equal(C.platformName('internal'), 'Nội bộ');
  assert.equal(C.platformName('preview'), 'Xem thử');
  assert.equal(C.platformName('youtube'), 'YouTube');
  assert.equal(C.platformName('facebook'), 'Facebook');
  assert.equal(C.platformName('tiktok'), 'tiktok');
  assert.equal(C.platformName('constructor'), 'constructor');
  assert.equal(C.platformName(undefined), '');
});
