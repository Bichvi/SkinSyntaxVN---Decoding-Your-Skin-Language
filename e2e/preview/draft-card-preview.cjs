/* Visual preview of the chat order draft / invoice card, with NO LLM and NO real orders.
 *
 * Renders window.SkinSyntaxAgent.renderDraftCard with a fake payload inside the real chat widget and forces each
 * state by mocking window.fetch for r=chat_order_otp / r=chat_order_confirm (those endpoints are never called).
 * Screenshots go to report/draft-card-preview/*.png.
 *
 * Run (Docker stack up on :8080):
 *   NODE_PATH="$USERPROFILE/.cache/codex-runtimes/codex-primary-runtime/dependencies/node/node_modules" \
 *     node e2e/preview/draft-card-preview.cjs
 * Env: E2E_BASE_URL (default http://127.0.0.1:8080)
 */
const fs = require('node:fs');
const path = require('node:path');
const { chromium } = require('playwright');
const { dismissWelcome } = require('../lib/chat-otp.cjs');

const BASE = (process.env.E2E_BASE_URL || 'http://127.0.0.1:8080').replace(/\/$/, '');
const OUT = path.join(__dirname, '..', '..', 'report', 'draft-card-preview');

const payload = (id, expiresInSeconds) => {
  const now = Math.floor(Date.now() / 1000);
  return {
    draft_id: id,
    server_now: now,
    expires_at: now + expiresInSeconds,
    items: [
      { id: 'p1', name: 'Kem chống nắng Anessa Perfect UV Sunscreen Skincare Milk SPF50+ PA++++ dành cho da nhạy cảm 60ml', image: '', image_url: '', qty: 1, unit_price: 379000, line_total: 379000 },
      { id: 'p2', name: 'Sữa rửa mặt CeraVe dịu nhẹ', image: '', image_url: 'http://127.0.0.1:8080/assets/images/does-not-exist.jpg', qty: 2, unit_price: 215000, line_total: 430000 }
    ],
    subtotal: 809000,
    voucher: { code: 'SYNA10', discount: 80900 },
    shipping: 30000,
    total: 758100,
    receiver: { name: 'Nguyễn Thị Bích Vi', phone_masked: '090****321', address_short: '12 Nguyễn Huệ, Phường Bến Nghé, Quận 1, TP.HCM' },
    payment_method: 'cod',
    warnings: ['Retinol và AHA trong giỏ có thể gây kích ứng nếu dùng cùng buổi tối.']
  };
};

async function main() {
  fs.mkdirSync(OUT, { recursive: true });
  // Bundled browser first, then the system Edge/Chrome (no download needed).
  let browser = null;
  for (const opts of [{}, { channel: 'msedge' }, { channel: 'chrome' }]) {
    try { browser = await chromium.launch({ headless: true, ...opts }); break; } catch (e) { browser = null; }
  }
  if (!browser) throw new Error('no browser available (bundled, msedge, chrome)');
  const ctx = await browser.newContext({ viewport: { width: 1280, height: 1400 } });
  const page = await ctx.newPage();
  await page.goto(`${BASE}/index.php`, { waitUntil: 'domcontentloaded' });
  await page.waitForSelector('[data-ai-chat-widget]');
  await dismissWelcome(page);
  if ((await page.getAttribute('[data-ai-chat-toggle]', 'aria-expanded')) !== 'true') await page.click('[data-ai-chat-toggle]');
  await page.waitForSelector('[data-ai-chat-input]', { state: 'visible' });

  // The real panel is short and scrolls; make it tall for the preview so the whole card is visible in one shot.
  await page.addStyleTag({ content: '.ai-chat-widget:not(.is-expanded) .ai-chat-widget__panel { height: 1200px !important; max-height: 1300px !important; } .ai-chat-widget.is-open .ai-chat-widget__panel { height: 1200px !important; max-height: 1300px !important; }' });

  // Mock the two order endpoints; everything else goes to the network untouched.
  await page.evaluate(() => {
    window.__mock = { confirm: 'invalid', calls: [] };
    const realFetch = window.fetch.bind(window);
    const json = (body) => Promise.resolve({ ok: true, status: 200, json: () => Promise.resolve(body) });
    window.fetch = (url, init) => {
      const u = String(url);
      if (u.includes('r=chat_order_otp')) {
        window.__mock.calls.push('otp');
        return json({ ok: true, email_masked: 'b***@gmail.com', expires_in: 300, resend_in: 60 });
      }
      if (u.includes('r=chat_order_confirm')) {
        window.__mock.calls.push('confirm');
        return window.__mock.confirm === 'created'
          ? json({ ok: true, status: 'created', order_id: 502999 })
          : json({ ok: false, code: 'otp_invalid', message: 'Mã xác nhận không đúng. Bạn còn 4 lần thử.' });
      }
      return realFetch(url, init);
    };
  });

  const mount = async (id, expiresIn) => {
    await page.evaluate(({ data }) => {
      const stream = document.querySelector('[data-ai-chat-stream]');
      stream.querySelectorAll('[data-preview-card]').forEach((n) => n.remove());
      const host = document.createElement('div');
      host.setAttribute('data-ai-agent-ui', '');
      host.setAttribute('data-preview-card', '');
      stream.appendChild(host);
      window.SkinSyntaxAgent.renderDraftCard(data, host, { messageId: 'preview-' + data.draft_id });
      host.scrollIntoView({ block: 'start' });
    }, { data: payload(id, expiresIn) });
  };
  const shot = async (name) => {
    await page.waitForTimeout(250);
    await page.evaluate(() => document.querySelector('[data-preview-card]').scrollIntoView({ block: 'start' }));
    await page.locator('.ai-chat-widget__panel').screenshot({ path: path.join(OUT, name + '.png') });
    console.log('saved', name);
  };

  // pending
  await mount('pv-pending', 581);
  await shot('pending');

  // otp (empty), then wrong code
  await mount('pv-otp', 581);
  await page.locator('[data-preview-card] button:has-text("Xác nhận đặt hàng")').click();
  await page.locator('[data-preview-card] .ai-chat-agent-draft-card__otp-input').waitFor({ state: 'visible' });
  await page.locator('[data-preview-card] .ai-chat-agent-draft-card__otp-input').fill('12');
  await shot('otp');
  await page.locator('[data-preview-card] .ai-chat-agent-draft-card__otp-input').fill('123456');
  await page.locator('[data-preview-card] .ai-chat-agent-draft-card__otp button:text-is("Xác nhận")').click();
  await page.locator('[data-preview-card] [role="alert"]').waitFor({ state: 'visible' });
  await shot('otp-wrong');

  // created
  await page.evaluate(() => { window.__mock.confirm = 'created'; });
  await page.locator('[data-preview-card] .ai-chat-agent-draft-card__otp-input').fill('123456');
  await page.locator('[data-preview-card] .ai-chat-agent-draft-card__otp button:text-is("Xác nhận")').click();
  await page.locator('[data-preview-card]').getByText(/Đã tạo đơn #502999/).waitFor();
  await shot('created');

  // expired
  await mount('pv-expired', -30);
  await shot('expired');

  // urgent countdown (amber/red pill)
  await mount('pv-urgent', 14);
  await shot('pending-urgent');

  // narrow phone width
  await page.setViewportSize({ width: 320, height: 1400 });
  await page.waitForTimeout(400);
  // The phone layout pins the panel to the viewport height; let it grow so the whole card is captured.
  await page.addStyleTag({ content: '.ai-chat-widget .ai-chat-widget__panel { height: auto !important; max-height: none !important; } .ai-chat-widget .ai-chat-widget__stream { max-height: none !important; height: auto !important; overflow: visible !important; flex: none !important; }' });
  await mount('pv-320', 581);
  await shot('pending-320');
  await page.locator('[data-preview-card] button:has-text("Xác nhận đặt hàng")').click();
  await page.locator('[data-preview-card] .ai-chat-agent-draft-card__otp-input').waitFor({ state: 'visible' });
  await shot('otp-320');

  const calls = await page.evaluate(() => window.__mock.calls);
  console.log('mocked calls:', calls.join(','));
  await browser.close();
}

main().catch((e) => { console.error(e); process.exit(1); });
