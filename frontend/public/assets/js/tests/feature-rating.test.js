'use strict';
// Unit tests cho các hàm thuần của feature-rating.js. Run: node --test "frontend/public/assets/js/tests/**/*.test.js"
const test = require('node:test');
const assert = require('node:assert/strict');
const FR = require('../feature-rating.js')._internals;

const DAY = 24 * 60 * 60 * 1000;
const NOW = 1_800_000_000_000;

test('shouldAsk: chưa có bản ghi thì được hỏi', () => {
  assert.equal(FR.shouldAsk(null, NOW), true);
  assert.equal(FR.shouldAsk({ t: 'x' }, NOW), true);
});

test('shouldAsk: đã đánh giá thì chờ 7 ngày', () => {
  assert.equal(FR.shouldAsk({ t: NOW - 6 * DAY, kind: 'rated' }, NOW), false);
  assert.equal(FR.shouldAsk({ t: NOW - 7 * DAY, kind: 'rated' }, NOW), true);
});

test('shouldAsk: bỏ qua thì chờ 3 ngày', () => {
  assert.equal(FR.shouldAsk({ t: NOW - 2 * DAY, kind: 'dismissed' }, NOW), false);
  assert.equal(FR.shouldAsk({ t: NOW - 3 * DAY, kind: 'dismissed' }, NOW), true);
});

test('shouldAsk: đồng hồ lệch (t ở tương lai) vẫn đang chờ', () => {
  assert.equal(FR.shouldAsk({ t: NOW + DAY, kind: 'dismissed' }, NOW), false);
});

test('storageKey gắn theo người dùng và tính năng', () => {
  assert.equal(FR.storageKey('abc123', 'chatbot'), 'ssfr:abc123:chatbot');
  assert.notEqual(FR.storageKey('a', 'chatbot'), FR.storageKey('b', 'chatbot'));
});

test('chatStep: hội thoại thật (khách gửi, rồi trợ lý trả lời) với >= 2 tin khách', () => {
  let r = FR.chatStep(null, { user: 1, assistant: 1, typing: 0 });
  assert.equal(r.fire, false);
  r = FR.chatStep(r.state, { user: 2, assistant: 1, typing: 0 });
  assert.equal(r.fire, false);
  r = FR.chatStep(r.state, { user: 2, assistant: 1, typing: 1 });
  assert.equal(r.fire, false);
  r = FR.chatStep(r.state, { user: 2, assistant: 2, typing: 0 });
  assert.equal(r.fire, true);
  // Không bắn lại khi DOM đổi mà không có tin trợ lý mới.
  r = FR.chatStep(r.state, { user: 2, assistant: 2, typing: 0 });
  assert.equal(r.fire, false);
});

test('chatStep: chỉ 1 tin khách thì chưa bắn', () => {
  let r = FR.chatStep(null, { user: 0, assistant: 0, typing: 0 });
  r = FR.chatStep(r.state, { user: 1, assistant: 0, typing: 0 });
  r = FR.chatStep(r.state, { user: 1, assistant: 1, typing: 0 });
  assert.equal(r.fire, false);
});

test('chatStep: nạp lại lịch sử cả loạt không bắn', () => {
  let r = FR.chatStep(null, { user: 0, assistant: 0, typing: 0 });
  r = FR.chatStep(r.state, { user: 3, assistant: 3, typing: 0 });
  assert.equal(r.fire, false);
});

test('chatStep: tin trợ lý xuất hiện khi còn typing thì chờ typing biến mất', () => {
  let r = FR.chatStep(null, { user: 1, assistant: 1, typing: 0 });
  r = FR.chatStep(r.state, { user: 2, assistant: 1, typing: 1 });
  r = FR.chatStep(r.state, { user: 2, assistant: 2, typing: 1 });
  assert.equal(r.fire, false);
  r = FR.chatStep(r.state, { user: 2, assistant: 2, typing: 0 });
  assert.equal(r.fire, true);
});

function fakeNodes(map) {
  return {
    querySelectorAll(sel) {
      const n = map[sel] || [];
      return n;
    }
  };
}

test('countChatRows đếm đúng từng loại hàng', () => {
  const stream = fakeNodes({
    '.ai-chat-widget__bubble-row--user': [1, 2],
    '.ai-chat-widget__bubble-row--assistant': [1],
    '.ai-chat-widget__typing-row': []
  });
  assert.deepEqual(FR.countChatRows(stream), { user: 2, assistant: 1, typing: 0 });
  assert.deepEqual(FR.countChatRows(null), { user: 0, assistant: 0, typing: 0 });
});

test('collectConflicts gom rule_id không trùng và bỏ nút ẩn', () => {
  const node = (rule, visible) => ({ getAttribute: () => rule, getClientRects: () => (visible ? [1] : []) });
  const box = fakeNodes({
    '.cin-conflict': [node('retinol_aha', true), node('retinol_aha', true), node('vitc_niacinamide', true), node('hidden', false)]
  });
  assert.deepEqual(FR.collectConflicts(box), { count: 3, ruleIds: ['retinol_aha', 'vitc_niacinamide'] });
  assert.deepEqual(FR.collectConflicts(null), { count: 0, ruleIds: [] });
});

test('pageRoute lấy tham số r và làm sạch', () => {
  assert.equal(FR.pageRoute('?r=goiy&x=1'), 'goiy');
  assert.equal(FR.pageRoute('?r=gio%20hang<script>'), 'giohangscript');
  assert.equal(FR.pageRoute(''), '');
});
