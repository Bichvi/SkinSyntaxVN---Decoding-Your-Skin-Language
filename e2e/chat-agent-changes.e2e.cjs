/* Real-browser acceptance test: the chat order card stays exact while the customer changes things.
 *
 * Scenario (real LLM, real PHP, Docker stack):
 *   advice → draft (2 items qty, new receiver, COD) → change phone → change payment to VNPay → change qty to 1
 *   → back to COD → confirm with email OTP → the stored order must equal the last card (items, qty, unit price,
 *   subtotal, discount, shipping, total, payment, receiver name/phone/address) → cancel the order.
 * After every card: line_total = unit × qty, subtotal = Σ lines, total = subtotal − discount + shipping, and the
 * card in the page shows the full phone/address the customer gave.
 *
 * Run:
 *   NODE_PATH="$USERPROFILE/.cache/codex-runtimes/codex-primary-runtime/dependencies/node/node_modules" \
 *     E2E_TURN_GAP_MS=30000 node e2e/chat-agent-changes.e2e.cjs
 * Needs MAIL_DEMO_MODE=1 on the php container (the OTP is read from `docker logs`), SYNA_AGENT_V2=1 and the
 * E2E customer in .env (E2E_CUSTOMER_EMAIL / E2E_CUSTOMER_PASSWORD, never printed). Creates ONE COD order, cancels it.
 */
const fs = require('node:fs');
const path = require('node:path');
const { spawnSync } = require('node:child_process');
const { chromium } = require('playwright');
const { readDemoOtp, dismissWelcome, OTP_INPUT, CARD } = require('./lib/chat-otp.cjs');

const ROOT = path.resolve(__dirname, '..');
const BASE = (process.env.E2E_BASE_URL || 'http://127.0.0.1:8080').replace(/\/$/, '');
const OUT = path.join(ROOT, 'report', 'chat-agent-changes');
const TURN_TIMEOUT = 150000;
const TURN_GAP_MS = Number(process.env.E2E_TURN_GAP_MS || 30000);
const PHONE_1 = '0912345678';
const PHONE_2 = '0987654321';
const STREET = '12 Nguyễn Văn A';

function envValue(name) {
  if (process.env[name]) return process.env[name];
  const file = path.join(ROOT, '.env');
  if (!fs.existsSync(file)) return '';
  const line = fs.readFileSync(file, 'utf8').split(/\r?\n/).find((l) => l.startsWith(name + '='));
  return line ? line.slice(name.length + 1).trim().replace(/^['"]|['"]$/g, '') : '';
}

const results = [];
function record(step, ok, note = '') {
  results.push({ step, ok, note });
  console.log(`${ok ? 'PASS' : 'FAIL'} ${step}${note ? ' — ' + note : ''}`);
}
async function shot(page, name) {
  fs.mkdirSync(OUT, { recursive: true });
  await page.screenshot({ path: path.join(OUT, name + '.png'), fullPage: false }).catch(() => {});
}
const digits = (s) => String(s || '').replace(/\D/g, '');

// Every draft_card payload seen in the agent stream, in order (as relayed by PHP to the browser).
const cards = [];
let turnCount = 0;

async function sendTurn(page, text) {
  if (turnCount++ > 0 && TURN_GAP_MS > 0) await page.waitForTimeout(TURN_GAP_MS);
  const before = cards.length;
  const finished = page.waitForEvent('requestfinished', { predicate: (r) => r.url().includes('r=ai_chat_agent_stream'), timeout: TURN_TIMEOUT });
  await page.fill('[data-ai-chat-input]', text);
  await page.$eval('[data-ai-chat-form]', (f) => f.requestSubmit());
  await finished;
  await page.waitForTimeout(1200);
  const bot = await page.$$eval('[data-ai-chat-widget] .ai-chat-widget__bubble-text', (els) => {
    const t = els.map((e) => (e.innerText || '').trim()).filter(Boolean);
    return t.length ? t[t.length - 1] : '';
  }).catch(() => '');
  console.log(`  > ${text}\n  < ${bot.replace(/\s+/g, ' ').slice(0, 220)}`);
  return { newCard: cards.length > before ? cards[cards.length - 1] : null, bot };
}

// Ask once, retry with a nudge if the agent answered without a new card.
async function turnForCard(page, text, nudge) {
  let r = await sendTurn(page, text);
  if (!r.newCard && nudge) r = await sendTurn(page, nudge);
  return r.newCard;
}

function checkArithmetic(label, card) {
  const items = Array.isArray(card.items) ? card.items : [];
  const linesOk = items.every((i) => Number(i.line_total) === Number(i.unit_price) * Number(i.qty));
  const sub = items.reduce((s, i) => s + Number(i.line_total), 0);
  const discount = card.voucher ? Number(card.voucher.discount || 0) : 0;
  const totalOk = Number(card.total) === Math.max(0, Number(card.subtotal) - discount) + Number(card.shipping);
  record(`${label}: line = unit × qty, subtotal = Σ lines, total = subtotal − discount + ship`,
    items.length > 0 && linesOk && sub === Number(card.subtotal) && totalOk,
    `items=${items.map((i) => `${i.qty}×${i.unit_price}`).join('+')} sub=${card.subtotal} disc=${discount} ship=${card.shipping} total=${card.total}`);
}

async function cardText(page) {
  return page.locator(CARD).last().innerText().catch(() => '');
}

function orderFromDb(orderId) {
  const php = `<?php
require '/var/www/html/vendor/autoload.php';
require '/var/www/html/app/config/db.php';
$o = $pdo->hoa_don->findOne(['ma_hoa_don' => ${Number(orderId)}]);
$lines = [];
foreach ($pdo->chi_tiet_hoa_don->find(['ma_hoa_don' => ${Number(orderId)}]) as $l) $lines[] = ['id' => (string)$l['ma_san_pham'], 'qty' => (int)$l['so_luong'], 'unit' => (int)$l['don_gia']];
echo json_encode($o ? ['tam_tinh' => (int)$o['tam_tinh'], 'so_tien_giam' => (int)$o['so_tien_giam'], 'phi_van_chuyen' => (int)$o['phi_van_chuyen'],
  'tong_tien' => (int)$o['tong_tien'], 'pttt' => (string)$o['hinh_thuc_thanh_toan'], 'ten' => (string)$o['ten_nguoi_nhan'],
  'sdt' => (string)$o['sdt_nguoi_nhan'], 'dia_chi' => (string)$o['dia_chi_giao_hang'], 'lines' => $lines] : null, JSON_UNESCAPED_UNICODE);`;
  const tmp = path.join(require('node:os').tmpdir(), `e2e-order-${process.pid}.php`);
  fs.writeFileSync(tmp, php);
  const env = { ...process.env, MSYS_NO_PATHCONV: '1' };
  spawnSync('docker', ['cp', tmp, 'skinsyntax-php:/tmp/e2e_order.php'], { env });
  const r = spawnSync('docker', ['exec', 'skinsyntax-php', 'php', '/tmp/e2e_order.php'], { encoding: 'utf8', env });
  spawnSync('docker', ['exec', 'skinsyntax-php', 'rm', '-f', '/tmp/e2e_order.php'], { env });
  fs.unlinkSync(tmp);
  try { return JSON.parse((r.stdout || '').trim().split(/\r?\n/).pop()); } catch { return null; }
}

(async () => {
  const email = envValue('E2E_CUSTOMER_EMAIL');
  const password = envValue('E2E_CUSTOMER_PASSWORD');
  if (!email || !password) { console.log('NOT RUN: E2E_CUSTOMER_EMAIL / E2E_CUSTOMER_PASSWORD missing'); process.exit(2); }
  let browser;
  for (const opts of [{}, { channel: 'msedge' }, { channel: 'chrome' }]) {
    try { browser = await chromium.launch({ headless: true, ...opts }); break; } catch (e) { browser = null; }
  }
  if (!browser) { console.log('NOT RUN: no browser available'); process.exit(2); }

  let context = null;
  let createdOrderId = null;
  try {
    context = await browser.newContext({ viewport: { width: 1280, height: 900 } });
    const page = await context.newPage();
    page.on('response', async (res) => {
      if (!res.url().includes('r=ai_chat_agent_stream')) return;
      try {
        for (const block of (await res.text()).split(/\n\n/)) {
          if (!/^event: ui$/m.test(block)) continue;
          const data = block.split('\n').filter((l) => l.startsWith('data:')).map((l) => l.slice(5).replace(/^ /, '')).join('\n');
          const ev = JSON.parse(data);
          if (ev && ev.kind === 'draft_card' && ev.payload) cards.push(ev.payload);
        }
      } catch { /* body unavailable */ }
    });

    await page.goto(`${BASE}/index.php?r=dangnhap`, { waitUntil: 'domcontentloaded' });
    const form = page.locator('form[action*="xulydangnhap"]').first();
    await form.locator('input[name="email"]').fill(email);
    await form.locator('input[name="mat_khau"]').fill(password);
    await Promise.all([page.waitForLoadState('domcontentloaded'), form.evaluate((f) => f.requestSubmit())]);
    // Let the post-login redirect finish; a goto racing it is aborted (net::ERR_ABORTED).
    await page.waitForLoadState('load').catch(() => {});
    await page.waitForTimeout(1500);
    for (let attempt = 1; ; attempt++) {
      try { await page.goto(`${BASE}/index.php`, { waitUntil: 'domcontentloaded' }); break; } catch (e) {
        if (attempt >= 3) throw e;
        await page.waitForTimeout(2000);
      }
    }
    await page.waitForSelector('[data-ai-chat-widget]');
    await dismissWelcome(page);
    if ((await page.getAttribute('[data-ai-chat-toggle]', 'aria-expanded')) !== 'true') await page.click('[data-ai-chat-toggle]');
    await page.waitForSelector('[data-ai-chat-input]', { state: 'visible' });

    // 1. First draft: qty 2, new receiver, COD.
    await sendTurn(page, 'tìm sữa rửa mặt cho da dầu');
    let card = await turnForCard(page,
      `lấy cái đầu tiên 2 chai, giao tới địa chỉ mới: người nhận E2E Test, số điện thoại ${PHONE_1}, ${STREET}, Phường Bến Nghé, Quận 1, TP.HCM. Thanh toán COD, lập đơn nháp giúp mình`,
      'đúng rồi, lập đơn nháp với thông tin đó nhé');
    record('draft card created', !!card);
    if (!card) throw new Error('no draft card');
    const productId = card.items[0] && card.items[0].id;
    record('qty 2 as asked', card.items.length === 1 && Number(card.items[0].qty) === 2, `qty=${card.items.map((i) => i.qty)}`);
    record('payment COD', card.payment_method === 'cod', card.payment_method);
    record('full phone + address reach the browser card', digits(card.receiver && card.receiver.phone) === PHONE_1
      && String(card.receiver && card.receiver.address).includes(STREET), `phone=${card.receiver && card.receiver.phone}`);
    let text = await cardText(page);
    record('card shows full phone and street', digits(text).includes(PHONE_1) && text.includes(STREET));
    checkArithmetic('draft 1', card);
    await shot(page, '01-draft');

    // 2. Change only the phone.
    card = await turnForCard(page, `đổi số điện thoại người nhận thành ${PHONE_2} giúp mình`, `cập nhật đơn nháp với số ${PHONE_2} nhé`);
    record('phone change → new card', !!card);
    if (card) {
      record('phone changed, address/name/qty/payment kept', digits(card.receiver.phone) === PHONE_2
        && String(card.receiver.address).includes(STREET) && card.receiver.name === 'E2E Test'
        && Number(card.items[0].qty) === 2 && card.payment_method === 'cod',
        `phone=${card.receiver.phone} name=${card.receiver.name} qty=${card.items[0].qty} pay=${card.payment_method}`);
      checkArithmetic('after phone change', card);
      text = await cardText(page);
      record('card shows the new phone', digits(text).includes(PHONE_2));
      await shot(page, '02-phone-changed');
    }

    // 3. Change only the payment method.
    card = await turnForCard(page, 'đổi sang thanh toán VNPay nhé', 'cập nhật đơn nháp sang VNPay giúp mình');
    record('payment change → new card', !!card);
    if (card) {
      record('payment VNPay, qty/receiver kept', card.payment_method === 'vnpay' && Number(card.items[0].qty) === 2
        && digits(card.receiver.phone) === PHONE_2, `pay=${card.payment_method} qty=${card.items[0].qty}`);
      checkArithmetic('after payment change', card);
      text = await cardText(page);
      record('card shows VNPay', /VNPay/i.test(text));
      await shot(page, '03-vnpay');
    }

    // 4. Change only the quantity.
    card = await turnForCard(page, 'giảm còn 1 chai thôi', 'cập nhật đơn nháp còn 1 chai nhé');
    record('qty change → new card', !!card);
    if (card) {
      record('qty 1, payment/receiver kept, same product', Number(card.items[0].qty) === 1 && card.items[0].id === productId
        && card.payment_method === 'vnpay' && digits(card.receiver.phone) === PHONE_2,
        `qty=${card.items[0].qty} pay=${card.payment_method}`);
      checkArithmetic('after qty change', card);
      await shot(page, '04-qty-1');
    }

    // 5. Back to COD (a VNPay order would redirect to the payment gateway).
    card = await turnForCard(page, 'thôi thanh toán COD nhé', 'cập nhật đơn nháp sang COD giúp mình');
    record('back to COD → new card', !!card && card.payment_method === 'cod' && Number(card.items[0].qty) === 1,
      card ? `pay=${card.payment_method} qty=${card.items[0].qty}` : '');
    if (!card) throw new Error('no final COD card');
    checkArithmetic('final card', card);
    const finalCard = card;

    // 6. Confirm with the OTP and compare the stored order with the final card.
    const confirmResponses = [];
    page.on('response', async (res) => {
      if (res.url().includes('r=chat_order_confirm')) { try { confirmResponses.push(await res.json()); } catch { confirmResponses.push({}); } }
    });
    const t0 = Date.now();
    await page.locator(`${CARD} button:has-text("Xác nhận đặt hàng"):not([disabled])`).last().click();
    await page.locator(OTP_INPUT).last().waitFor({ state: 'visible', timeout: 30000 });
    const code = await readDemoOtp(t0);
    record('OTP readable (MAIL_DEMO_MODE=1)', !!code);
    if (!code) throw new Error('no OTP in logs');
    await page.locator(OTP_INPUT).last().fill(code);
    await page.locator(`${CARD} .ai-chat-agent-draft-card__otp button:has-text("Xác nhận"):not([disabled])`).last().click();
    await page.waitForSelector('text=/Đã tạo đơn #\\d+/', { timeout: 60000 });
    const created = confirmResponses.find((r) => r && r.ok && r.status === 'created');
    createdOrderId = created ? created.order_id : null;
    record('order created', !!createdOrderId, `order_id=${createdOrderId}`);
    await shot(page, '05-created');

    const order = createdOrderId ? orderFromDb(createdOrderId) : null;
    record('order readable from DB', !!order);
    if (order) {
      const it = finalCard.items[0];
      const line = order.lines.find((l) => l.id === String(it.id));
      record('stored line = card line (product, qty, unit price)', order.lines.length === finalCard.items.length && !!line
        && line.qty === Number(it.qty) && line.unit === Number(it.unit_price), JSON.stringify(order.lines));
      record('stored amounts = card (subtotal, discount, shipping, total)',
        order.tam_tinh === Number(finalCard.subtotal)
        && order.so_tien_giam === (finalCard.voucher ? Number(finalCard.voucher.discount) : 0)
        && order.phi_van_chuyen === Number(finalCard.shipping) && order.tong_tien === Number(finalCard.total),
        `db=${order.tam_tinh}/${order.so_tien_giam}/${order.phi_van_chuyen}/${order.tong_tien} card=${finalCard.subtotal}/${finalCard.voucher ? finalCard.voucher.discount : 0}/${finalCard.shipping}/${finalCard.total}`);
      record('stored payment = COD', order.pttt === 'cod', order.pttt);
      record('stored receiver = last change (name, phone, address)', order.ten === 'E2E Test' && digits(order.sdt) === PHONE_2
        && order.dia_chi.includes(STREET), `sdt_ok=${digits(order.sdt) === PHONE_2}`);
    }
  } catch (err) {
    record('scenario aborted', false, err && err.message ? err.message.slice(0, 200) : String(err));
  } finally {
    if (createdOrderId && context) {
      try {
        const res = await context.request.post(`${BASE}/index.php?r=huydonhang`, {
          form: { ma_hoa_don: String(createdOrderId), ly_do_huy: 'Khac', ly_do_huy_bo_sung: 'Đơn kiểm thử E2E tự động' },
          maxRedirects: 0,
        });
        record('test order cancelled', /don=da_huy/.test(res.headers()['location'] || ''), `order_id=${createdOrderId}`);
      } catch (e) {
        record('test order cancelled', false, `order_id=${createdOrderId} — cancel manually`);
      }
    }
    fs.mkdirSync(OUT, { recursive: true });
    fs.writeFileSync(path.join(OUT, 'result.json'), JSON.stringify({ base: BASE, at: new Date().toISOString(), createdOrderId, results }, null, 2));
    await browser.close().catch(() => {});
    const failed = results.filter((r) => !r.ok).length;
    console.log(`RESULT: ${failed ? 'FAIL' : 'PASS'} (${results.length - failed}/${results.length})`);
    process.exit(failed ? 1 : 0);
  }
})();
