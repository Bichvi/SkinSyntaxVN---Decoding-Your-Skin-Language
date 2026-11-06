const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const test = require('node:test');

const source = fs.readFileSync('frontend/public/assets/js/ai-chat-label-scan.js', 'utf8');

function load() {
  const sandbox = {};
  sandbox.window = sandbox;
  vm.createContext(sandbox);
  vm.runInContext(source, sandbox);
  return sandbox.SkinSyntaxLabelScan;
}

const LS = load();
const T = LS._test;
const plain = (x) => JSON.parse(JSON.stringify(x));

function fullResult(over) {
  return Object.assign({
    status: 'ok',
    product: { name: 'Serum Retinol 0.5%', brand: 'Acme', product_type: 'serum', matched_product_id: 'SP1', match_score: 0.9, url: '/index.php?r=chitiet&id=SP1' },
    ingredients: {
      source: 'photo', total: 20, recognized: 18, raw_text: 'Aqua, Retinol, Glycerin, Niacinamide, Panthenol',
      items: [1, 2, 3, 4, 5, 6, 7, 8].map((n) => ({ key: 'k' + n, name: 'Ing' + n, name_vi: null, role: null, rank: n, highlight: true }))
    },
    conflicts: [{ cart_product_id: 'SP2', cart_product: { id: 'SP2', name: 'Toner AHA', url: '/index.php?r=chitiet&id=SP2' }, severity: 'high', timing: 'same_session', ingredients: ['retinol', 'aha'], advice_vi: 'Không dùng cùng buổi.', rule_id: 'r1', evidence: '' }],
    schedule: [{ cart_product_id: 'SP2', scan_slot: 'pm', cart_slot: 'am', kind: 'split_session', reason_vi: 'Tách buổi.' }],
    skin_fit: { verdict: 'caution', profile: { skin_type: 'dau', sensitive: true, concerns: [] }, good: [{ rule_id: 'g', reason_vi: 'Có niacinamide', ingredients: [] }], caution: [{ rule_id: 'c', reason_vi: 'Retinol mạnh', ingredients: [] }], avoid: [{ rule_id: 'a', reason_vi: 'Cồn khô', ingredients: [] }] },
    alternatives: [{ id: 'SP3', name: 'Serum dịu', price: 100000, image_url: '', url: '/index.php?r=chitiet&id=SP3', reason_code: 'gentler_fits_skin', reason_params: [] }],
    warnings: [], summary_vi: 'Tóm tắt', sunscreen_note: true, took_ms: 10
  }, over || {});
}

test('computeTargetSize: landscape, portrait, small not upscaled', () => {
  assert.deepEqual(plain(T.computeTargetSize(3200, 1600)), { width: 1600, height: 800 });
  assert.deepEqual(plain(T.computeTargetSize(1200, 4800)), { width: 400, height: 1600 });
  assert.deepEqual(plain(T.computeTargetSize(800, 600)), { width: 800, height: 600 });
  assert.deepEqual(plain(T.computeTargetSize(1600, 1600)), { width: 1600, height: 1600 });
});

test('mime whitelist', () => {
  assert.equal(T.isAllowedMime('image/jpeg'), true);
  assert.equal(T.isAllowedMime('image/png'), true);
  assert.equal(T.isAllowedMime('image/webp'), true);
  assert.equal(T.isAllowedMime('image/gif'), false);
  assert.equal(T.isAllowedMime('application/pdf'), false);
  assert.equal(T.isAllowedMime(''), false);
});

test('renderCard: full result has every block', () => {
  const html = LS.renderCard(fullResult(), 'm1');
  assert.match(html, /data-ls-msg="m1"/);
  assert.match(html, /Serum Retinol 0\.5%/);
  assert.match(html, /Có bán tại shop/);
  assert.match(html, /Đã đọc 20 thành phần/);
  assert.equal((html.match(/ai-label-card__chip"/g) || []).length, 6);
  assert.match(html, /<details class="ai-label-card__all">/);
  assert.match(html, /Sửa nếu đọc sai/);
  assert.match(html, /data-ls-edit hidden/);
  assert.match(html, /🔴/);
  assert.match(html, /Toner AHA/);
  assert.match(html, /Không dùng cùng buổi\./);
  assert.match(html, /Serum Retinol 0\.5% buổi tối · Toner AHA buổi sáng/);
  assert.match(html, /kem chống nắng/);
  assert.match(html, /🟡 Cần lưu ý/);
  assert.match(html, /Da dầu, nhạy cảm/);
  assert.match(html, /✅ Có niacinamide/);
  assert.match(html, /⚠️ Retinol mạnh/);
  assert.match(html, /⛔ Cồn khô/);
  assert.match(html, /Serum dịu/);
  assert.match(html, /Dùng Serum Retinol 0\.5% chung với Toner AHA được không\?/);
  assert.match(html, /Xếp routine sáng\/tối có Serum Retinol 0\.5% cho tôi/);
  assert.match(html, /Kết quả tham khảo, không thay thế tư vấn da liễu/);
  assert.doesNotMatch(html, /data-ls-action="skin"/);
});

test('renderCard: minimal result hides empty blocks', () => {
  const html = LS.renderCard({
    status: 'ok', product: { name: '', brand: '', product_type: 'other', matched_product_id: null, match_score: null },
    ingredients: { source: 'photo', total: 0, recognized: 0, items: [], raw_text: '' },
    conflicts: [], schedule: [], skin_fit: { verdict: 'unknown', profile: { skin_type: null, sensitive: false, concerns: [] }, good: [], caution: [], avoid: [] },
    alternatives: [], warnings: [], summary_vi: 'x', sunscreen_note: false, took_ms: 1
  }, 'm2');
  assert.doesNotMatch(html, /ai-label-card__head/);
  assert.doesNotMatch(html, /ai-label-card__ing/);
  assert.doesNotMatch(html, /ai-label-card__conflicts/);
  assert.doesNotMatch(html, /ai-label-card__fit/);
  assert.doesNotMatch(html, /ai-label-card__alts/);
  assert.match(html, /Kết quả tham khảo/);
});

test('renderCard escapes <script> in name, advice and reason', () => {
  const evil = '<script>alert(1)</script>';
  const r = fullResult();
  r.product.name = evil;
  r.conflicts[0].advice_vi = evil;
  r.conflicts[0].cart_product.name = evil;
  r.skin_fit.caution[0].reason_vi = evil;
  r.ingredients.items[0].name = evil;
  r.ingredients.raw_text = evil;
  const html = LS.renderCard(r, 'm3');
  assert.equal(html.includes('<script>'), false);
  assert.match(html, /&lt;script&gt;/);
});

test('javascript: and protocol-relative urls are not linked', () => {
  const r = fullResult();
  r.product.url = 'javascript:alert(1)';
  r.conflicts[0].cart_product.url = '//evil.example/x';
  const html = LS.renderCard(r, 'm4');
  assert.equal(html.includes('javascript:'), false);
  assert.equal(html.includes('//evil.example'), false);
  assert.doesNotMatch(html, /Có bán tại shop/);
  assert.equal(T.safeUrl('/index.php?r=chitiet&id=1'), '/index.php?r=chitiet&id=1');
  assert.equal(T.safeUrl('index.php?r=chitiet'), 'index.php?r=chitiet');
  assert.equal(T.safeUrl('data:text/html,x'), '');
  assert.equal(T.safeUrl('http://shop.test/p/index.php?r=a', 'http://shop.test/p/'), 'http://shop.test/p/index.php?r=a');
  assert.equal(T.safeUrl('http://evil.test/', 'http://shop.test/p/'), '');
});

test('verdict emoji map', () => {
  assert.equal(T.verdictEmoji('good'), '🟢');
  assert.equal(T.verdictEmoji('caution'), '🟡');
  assert.equal(T.verdictEmoji('avoid'), '🔴');
  assert.equal(T.verdictEmoji('neutral'), '⚪');
  assert.equal(T.verdictEmoji('unknown'), '⚪');
  assert.equal(T.verdictEmoji('bogus'), '⚪');
});

test('schedule kinds wording', () => {
  assert.equal(T.scheduleText({ kind: 'split_session', scan_slot: 'am', cart_slot: 'pm' }, 'A', 'B'), 'A buổi sáng · B buổi tối');
  assert.match(T.scheduleText({ kind: 'alternate_nights' }, 'A', 'B'), /xen kẽ các tối/);
  assert.match(T.scheduleText({ kind: 'alternate_days' }, 'A', 'B'), /dùng xen kẽ ngày/);
  assert.equal(T.scheduleText({ kind: 'nope' }, 'A', 'B'), '');
});

test('not_label shows tips only, no result blocks', () => {
  const html = LS.renderCard({ status: 'not_label', summary_vi: 's', warnings: [] }, 'm5');
  assert.match(html, /ai-label-card__tips/);
  assert.match(html, /<li>/);
  assert.doesNotMatch(html, /ai-label-card__disclaimer/);
  assert.doesNotMatch(html, /ai-label-card__ing/);
  assert.match(LS.renderCard({ status: 'unreadable' }, 'm6'), /<textarea/);
});

test('vision_unavailable shows a paste textarea', () => {
  const html = LS.renderCard({ status: 'vision_unavailable', product: {}, ingredients: {} }, 'm7');
  assert.match(html, /<textarea/);
  assert.match(html, /data-ls-action="reanalyze"/);
  assert.doesNotMatch(html, /data-ls-edit hidden/);
});

test('low_recognition: warning strip and edit box open', () => {
  const html = LS.renderCard(fullResult({ warnings: ['low_recognition'] }), 'm8');
  assert.match(html, /Kết quả có thể thiếu/);
  assert.match(html, /<div class="ai-label-card__edit" data-ls-edit>/);
  assert.match(html, /<textarea[^>]*data-ls-text/);
});

test('catalog source hides the edit button and box', () => {
  const r = fullResult();
  r.ingredients.source = 'catalog';
  const html = LS.renderCard(r, 'm9');
  assert.doesNotMatch(html, /Sửa nếu đọc sai/);
  assert.doesNotMatch(html, /data-ls-edit/);
});

test('profile_missing shows quick-pick and sensitive toggle; cart_empty line', () => {
  const html = LS.renderCard(fullResult({ warnings: ['profile_missing', 'cart_empty'], conflicts: [], schedule: [] }), 'm10');
  ['dau', 'kho', 'hon_hop', 'thuong'].forEach((k) => assert.match(html, new RegExp('data-ls-skin="' + k + '"')));
  assert.match(html, /data-ls-sensitive/);
  assert.match(html, /Giỏ hàng đang trống/);
  assert.match(html, /Xếp routine sáng\/tối có/);
});

test('graph_degraded hides alternatives', () => {
  const html = LS.renderCard(fullResult({ warnings: ['graph_degraded'] }), 'm11');
  assert.doesNotMatch(html, /ai-label-card__alts/);
});

test('request builders: edit and quick-pick payloads', () => {
  const r = fullResult();
  const edit = T.buildEditPayload(r, '  Aqua, Retinol, Glycerin, Panthenol  ', 'tok', 'c1');
  assert.equal(edit.ingredients_text, 'Aqua, Retinol, Glycerin, Panthenol');
  assert.equal(edit.product_type, 'serum');
  assert.equal(edit.csrf, 'tok');
  assert.equal(edit.conversation_id, 'c1');
  assert.equal(edit.product_name, 'Serum Retinol 0.5%');
  assert.equal(T.buildEditPayload(r, 'x', 'tok', '').conversation_id, undefined);

  const quick = T.buildQuickPickPayload(r, 'kho', true, 'tok', '');
  assert.equal(quick.ingredients_text, r.ingredients.raw_text);
  assert.deepEqual(JSON.parse(quick.skin_type_override), { skin_type: 'kho', sensitive: true });
  assert.equal(quick.csrf, 'tok');
  assert.deepEqual(JSON.parse(T.buildQuickPickPayload(r, null, false, 't', '').skin_type_override), { skin_type: null, sensitive: false });
});

test('errorText prefers server message, falls back per code', () => {
  assert.equal(T.errorText('csrf', 'Hết phiên'), 'Hết phiên');
  assert.match(T.errorText('too_large', ''), /quá lớn/);
  assert.match(T.errorText('weird', ''), /tạm thời chưa đọc được ảnh/);
});

test('I2: catalog result re-run sends item names + product fields', () => {
  const r = fullResult();
  r.ingredients.source = 'catalog';
  r.ingredients.raw_text = '';
  r.ingredients.items = [{ name: 'Aqua' }, { name: 'Glycerin' }, { name: 'Niacinamide' }, { name: 'Panthenol' }];
  const p = T.buildQuickPickPayload(r, 'dau', false, 'tok', '');
  assert.equal(p.ingredients_text, 'Aqua, Glycerin, Niacinamide, Panthenol');
  assert.equal(p.product_name, 'Serum Retinol 0.5%');
  assert.equal(p.brand, 'Acme');
  assert.equal(p.product_type, 'serum');
  assert.deepEqual(JSON.parse(p.skin_type_override), { skin_type: 'dau', sensitive: false });
});

test('I3: sensitive-only payload and persisted selection render', () => {
  const r = fullResult({ warnings: ['profile_missing'] });
  const p = T.buildQuickPickPayload(r, null, true, 'tok', '');
  assert.deepEqual(JSON.parse(p.skin_type_override), { skin_type: null, sensitive: true });
  // đã chọn tay: hàng chọn vẫn hiện dù không còn profile_missing, nút đã chọn aria-pressed=true
  const html = LS.renderCard(fullResult(), 'm20', { skin_type: 'kho', sensitive: true });
  assert.match(html, /data-ls-skin="kho" aria-pressed="true"/);
  assert.match(html, /data-ls-skin="dau" aria-pressed="false"/);
  assert.match(html, /data-ls-sensitive aria-pressed="true"/);
});

test('I4: follow-up chip text names the scanned product', () => {
  const [a, b] = T.followUpTexts(fullResult());
  assert.equal(a, 'Dùng Serum Retinol 0.5% chung với Toner AHA được không?');
  assert.equal(b, 'Xếp routine sáng/tối có Serum Retinol 0.5% cho tôi');
  const none = T.followUpTexts(fullResult({ conflicts: [] }));
  assert.equal(none[0], 'Serum Retinol 0.5% có hợp với da mình không?');
  const long = fullResult();
  long.product.name = 'A'.repeat(100);
  assert.ok(T.followUpTexts(long)[1].length < 110);
});

test('M1: zero is rendered with a widget-style escaper', () => {
  const sandbox = {};
  sandbox.window = sandbox;
  vm.createContext(sandbox);
  vm.runInContext(source, sandbox);
  const ls = sandbox.SkinSyntaxLabelScan;
  // escaper kiểu widget: String(value || '')
  ls.init({ widget: { querySelector() { return null; }, addEventListener() {} }, endpoint: 'x', addMessage() {}, escapeHtml: (v) => String(v || '') });
  const r = fullResult();
  r.ingredients.recognized = 0;
  assert.match(ls.renderCard(r, 'm30'), /nhận diện được 0\)/);
});

test('M2: edit draft and open state survive re-render (state maps)', () => {
  const html = LS.renderCard(fullResult({ warnings: ['low_recognition'] }), 'm31');
  assert.match(html, /<textarea[^>]*data-ls-text/);
});

test('M6: alternative cards pass an empty brand', () => {
  const sandbox = {};
  sandbox.window = sandbox;
  vm.createContext(sandbox);
  vm.runInContext(source, sandbox);
  let seen = null;
  sandbox.SkinSyntaxLabelScan.init({ widget: { querySelector() { return null; }, addEventListener() {} }, endpoint: 'x', addMessage() {}, renderProductCards: (items) => { seen = items; return []; } });
  sandbox.SkinSyntaxLabelScan.renderCard(fullResult(), 'm32');
  assert.equal(seen[0].brand, '');
});

test('M4: widget renders labelScan content as escaped plain text', () => {
  const widget = fs.readFileSync('frontend/views/components/ai_chat_widget.php', 'utf8');
  assert.ok(widget.includes("message.labelScan ? '<p>' + escapeHtml(message.content) + '</p>' : formatMarkdown(supportContent)"));
  // và tên có markdown link chỉ là chữ trong thẻ (thẻ kết quả đã escape)
  const r = fullResult();
  r.product.name = '[x](javascript:alert(1))';
  const html = LS.renderCard(r, 'm33');
  assert.equal(/href="javascript:/.test(html), false);
  assert.equal(/<a [^>]*\[x\]/.test(html), false);
});
