/* Real-browser acceptance test: order through the chat agent (Task 19, plan 2026-10-27-chatbot-dathang-gd0-gd1).
 *
 * Run (Playwright resolved through NODE_PATH, same as scripts/e2e/product-video.e2e.cjs):
 *   NODE_PATH="$USERPROFILE/.cache/codex-runtimes/codex-primary-runtime/dependencies/node/node_modules" \
 *     node e2e/chat-agent-order.e2e.cjs
 *
 * Needs: the Docker stack with SYNA_AGENT_V2=1, a seeded test customer whose credentials are in .env as
 * E2E_CUSTOMER_EMAIL / E2E_CUSTOMER_PASSWORD (never printed). Creates ONE real COD order and cancels it at the end.
 * Optional env: E2E_BASE_URL (default http://127.0.0.1:8080), E2E_KILL_SWITCH=1 (also checks the purchase kill switch
 * through `docker exec skinsyntax-php php /var/www/html/scripts/chat_agent_switch.php`).
 */
const fs = require('node:fs');
const path = require('node:path');
const { execFileSync } = require('node:child_process');
const { chromium } = require('playwright');

const ROOT = path.resolve(__dirname, '..');
const BASE = (process.env.E2E_BASE_URL || 'http://127.0.0.1:8080').replace(/\/$/, '');
const OUT = path.join(ROOT, 'report', 'chat-agent-e2e');
const TURN_TIMEOUT = 120000;

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

async function openWidget(page) {
  await page.goto(`${BASE}/index.php`, { waitUntil: 'domcontentloaded' });
  await page.waitForSelector('[data-ai-chat-widget]');
  const v2 = await page.getAttribute('[data-ai-chat-widget]', 'data-agent-v2');
  const expanded = await page.getAttribute('[data-ai-chat-toggle]', 'aria-expanded');
  if (expanded !== 'true') await page.click('[data-ai-chat-toggle]');
  await page.waitForSelector('[data-ai-chat-input]', { state: 'visible' });
  return v2;
}

const TURN_GAP_MS = Number(process.env.E2E_TURN_GAP_MS || 0); // spacing for free-tier LLM token-per-minute limits
let turnCount = 0;

async function sendTurn(page, text) {
  if (turnCount++ > 0 && TURN_GAP_MS > 0) await page.waitForTimeout(TURN_GAP_MS);
  const finished = page.waitForEvent('requestfinished', {
    predicate: (r) => r.url().includes('r=ai_chat_agent_stream'),
    timeout: TURN_TIMEOUT,
  });
  await page.fill('[data-ai-chat-input]', text);
  await page.$eval('[data-ai-chat-form]', (f) => f.requestSubmit());
  await finished;
  await page.waitForTimeout(800);
}

async function lastBotText(page) {
  return page.$$eval('[data-ai-chat-widget] .ai-chat-widget__bubble-text', (els) => {
    const texts = els.map((e) => (e.innerText || '').trim()).filter(Boolean);
    return texts.length ? texts[texts.length - 1].slice(0, 300) : '';
  }).catch(() => '');
}

async function login(context, email, password) {
  const page = await context.newPage();
  await page.goto(`${BASE}/index.php?r=dangnhap`, { waitUntil: 'domcontentloaded' });
  const form = page.locator('form[action*="xulydangnhap"]').first();
  await form.locator('input[name="email"]').fill(email);
  await form.locator('input[name="mat_khau"]').fill(password);
  await Promise.all([page.waitForLoadState('domcontentloaded'), form.evaluate((f) => f.requestSubmit())]);
  await page.waitForTimeout(1000);
  const loggedIn = !/r=dangnhap/.test(page.url()) || (await page.locator('a[href*="r=dangxuat"]').count()) > 0;
  return { page, loggedIn };
}

function killSwitch(state) {
  return execFileSync('docker', ['exec', 'skinsyntax-php', 'php', '/var/www/html/scripts/chat_agent_switch.php', state], {
    encoding: 'utf8',
  }).trim();
}

(async () => {
  const email = envValue('E2E_CUSTOMER_EMAIL');
  const password = envValue('E2E_CUSTOMER_PASSWORD');
  if (!email || !password) {
    console.log('NOT RUN: E2E_CUSTOMER_EMAIL / E2E_CUSTOMER_PASSWORD missing');
    process.exit(2);
  }
  // Prefer Playwright's bundled browser; fall back to the system Edge/Chrome so no browser download is needed.
  let browser;
  for (const opts of [{}, { channel: 'msedge' }, { channel: 'chrome' }]) {
    try { browser = await chromium.launch({ headless: true, ...opts }); break; } catch (e) { browser = null; }
  }
  if (!browser) {
    console.log('NOT RUN: no browser available (bundled, msedge, chrome)');
    process.exit(2);
  }
  let createdOrderId = null;
  let customerContext = null;
  try {
    // 1. Guest: buying asks to log in, no draft.
    const guest = await browser.newContext();
    const gp = await guest.newPage();
    const v2 = await openWidget(gp);
    record('widget flag data-agent-v2=1', v2 === '1', `value=${v2}`);
    await sendTurn(gp, 'đặt cho mình 1 chai sữa rửa mặt some by mi');
    const loginLink = await gp.locator('[data-ai-chat-widget] a[href*="r=dangnhap"]').count();
    const guestDraft = await gp.locator('.ai-chat-agent-draft-card').count();
    record('guest buy → login prompt, no draft', loginLink > 0 && guestDraft === 0, `loginLinks=${loginLink} drafts=${guestDraft}`);
    await shot(gp, '01-guest-login-required');
    await guest.close();

    // 2. Customer login.
    customerContext = await browser.newContext();
    const { page, loggedIn } = await login(customerContext, email, password);
    record('customer login', loggedIn);
    if (!loggedIn) throw new Error('login failed — is the E2E customer seeded?');

    const confirmResponses = [];
    page.on('response', async (res) => {
      if (res.url().includes('r=chat_order_confirm')) {
        try { confirmResponses.push(await res.json()); } catch { confirmResponses.push({ ok: false, unreadable: true }); }
      }
    });
    let cartUpdated = 0;
    page.on('requestfinished', () => {});

    await openWidget(page);

    // 3. Optional kill switch: bot still answers but must not produce a draft.
    if (process.env.E2E_KILL_SWITCH === '1') {
      try {
        killSwitch('off');
        await sendTurn(page, 'đặt luôn 1 sữa rửa mặt cho da dầu, thanh toán COD giao về địa chỉ mặc định');
        const drafts = await page.locator('.ai-chat-agent-draft-card').count();
        record('kill switch off → no draft', drafts === 0, `drafts=${drafts}`);
      } finally {
        record('kill switch restored', /on/.test(killSwitch('on')));
      }
    }

    // 4. Acceptance scenario.
    // The E2E account has no saved receiver, so the bot must ask; the last turn supplies a fake test receiver.
    const turns = [
      'mình da dầu mụn, tư vấn và đặt giúp 1 sữa rửa mặt giao về nhà, COD',
      'lấy sản phẩm đầu tiên giúp mình, 1 chai, thanh toán COD',
      'giao tới địa chỉ mới: người nhận E2E Test, số điện thoại 0912345678, địa chỉ 12 Nguyễn Văn A, Phường Bến Nghé, Quận 1, TP.HCM. Thanh toán COD, lập đơn nháp giúp mình',
      'đúng rồi, dùng thông tin người nhận vừa gửi và COD, lập đơn nháp nhé',
    ];
    const confirmBtn = page.locator('.ai-chat-agent-draft-card button:has-text("Xác nhận đặt hàng"):not([disabled])');
    let sawProducts = false;
    for (const t of turns) {
      await sendTurn(page, t);
      sawProducts = sawProducts || (await page.locator('[data-ai-chat-widget] [data-ai-add-cart], [data-ai-chat-widget] .ai-chat-widget__meta-card-title').count()) > 0;
      if (await confirmBtn.count()) break;
      console.log('  bot:', (await lastBotText(page)).replace(/\s+/g, ' ').slice(0, 160));
    }
    record('products shown during advice', sawProducts);
    const hasDraft = (await confirmBtn.count()) > 0;
    record('draft card with Confirm button', hasDraft);
    await shot(page, '02-draft-card');
    if (!hasDraft) throw new Error('no draft card after the scripted turns');

    // 5. No order before the click.
    record('no order created before clicking Confirm', confirmResponses.length === 0);

    // 6. Double click → exactly one created order.
    await confirmBtn.first().dblclick();
    await page.waitForSelector('text=/Đã tạo đơn #\\d+/', { timeout: 60000 });
    await page.waitForTimeout(1500);
    const created = confirmResponses.filter((r) => r && r.ok && r.status === 'created');
    createdOrderId = created.length ? created[0].order_id : null;
    record('double click → exactly one created order', created.length === 1 && confirmResponses.length <= 2,
      `confirm calls=${confirmResponses.length} created=${created.length}`);
    await shot(page, '03-order-created');

    // 7. Order is visible in "Đơn hàng của tôi".
    await page.goto(`${BASE}/index.php?r=hoso`, { waitUntil: 'domcontentloaded' });
    const listed = createdOrderId !== null && (await page.locator(`text=${createdOrderId}`).count()) > 0;
    record('order listed in customer orders', listed, `order_id=${createdOrderId}`);
    await shot(page, '04-orders');
  } catch (err) {
    record('scenario aborted', false, err && err.message ? err.message.slice(0, 200) : String(err));
  } finally {
    // 8. Cleanup: cancel the test order (restores stock).
    if (createdOrderId && customerContext) {
      try {
        const res = await customerContext.request.post(`${BASE}/index.php?r=huydonhang`, {
          // customerOrderCancel requires a reason (ly_do_huy; "Khac" needs ly_do_huy_bo_sung).
          form: { ma_hoa_don: String(createdOrderId), ly_do_huy: 'Khac', ly_do_huy_bo_sung: 'Đơn kiểm thử E2E tự động' },
          maxRedirects: 0,
        });
        const location = res.headers()['location'] || '';
        record('test order cancelled', /don=da_huy/.test(location), `order_id=${createdOrderId} http=${res.status()}`);
      } catch (e) {
        record('test order cancelled', false, `order_id=${createdOrderId} — cancel manually`);
      }
    }
    await browser.close();
    fs.mkdirSync(OUT, { recursive: true });
    fs.writeFileSync(path.join(OUT, 'result.json'), JSON.stringify({ base: BASE, at: new Date().toISOString(), createdOrderId, results }, null, 2));
    const failed = results.filter((r) => !r.ok).length;
    console.log(failed ? `RESULT: FAIL (${failed} failed)` : 'RESULT: PASS');
    process.exit(failed ? 1 : 0);
  }
})();
