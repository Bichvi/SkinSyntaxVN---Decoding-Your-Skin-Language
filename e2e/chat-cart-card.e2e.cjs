// Run: NODE_PATH=<playwright node_modules> node e2e/chat-cart-card.e2e.cjs [out_dir]
// Guest-only E2E: chat cart card on the legacy path (agent stream forced to fallback). No login, no order.
const path = require('node:path');
const { chromium } = require('playwright');
const BASE = (process.env.E2E_BASE_URL || 'http://127.0.0.1:8080').replace(/\/$/, '');
const OUT = process.argv[2] || path.resolve(__dirname, '..', 'report', 'chat-cart-card-e2e');
require('node:fs').mkdirSync(OUT, { recursive: true });
const results = [];
const rec = (s, ok, n = '') => { results.push(ok); console.log(`${ok ? 'PASS' : 'FAIL'} ${s}${n ? ' — ' + n : ''}`); };

(async () => {
  const browser = await chromium.launch({ channel: 'chrome' });
  const ctx = await browser.newContext({ viewport: { width: 1280, height: 900 } });
  const page = await ctx.newPage();
  const errors = [];
  page.on('pageerror', (e) => errors.push(e.message));
  await page.route('**/index.php?r=ai_chat_agent_stream*', (r) => r.fulfill({ status: 404, contentType: 'application/json', body: '{"fallback":true}' }));

  await page.goto(`${BASE}/index.php`, { waitUntil: 'domcontentloaded' });
  // Add the first home-page product to the guest session cart through the real form endpoint.
  const added = await page.evaluate(async () => {
    const forms = [...document.querySelectorAll('form input[name="action"][value="add_to_cart"]')].map((i) => i.closest('form'));
    const notes = [];
    for (const form of forms.slice(0, 8)) {
      const u = new URL(form.getAttribute('action'), location.href); const r = await fetch(location.origin + u.pathname + u.search, { method: 'POST', body: new FormData(form), credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest', Accept: 'application/json' } });
      const body = await r.json().catch(() => ({}));
      const id = form.querySelector('[name=product_id]').value;
      if (body.ok) return { ok: true, id, note: `cart_count=${body.cart_count}` };
      notes.push(`${id}:${body.message || r.status}`);
    }
    return { ok: false, id: '', note: notes.join('; ') };
  });
  rec('Add product to guest cart', added.ok, added.id + ' ' + added.note);

  await page.reload({ waitUntil: 'domcontentloaded' });
  await page.waitForSelector('[data-ai-chat-widget]');
  if ((await page.getAttribute('[data-ai-chat-toggle]', 'aria-expanded')) !== 'true') await page.click('[data-ai-chat-toggle]');
  await page.waitForSelector('[data-ai-chat-input]', { state: 'visible' });

  async function ask(text) {
    const done = page.waitForResponse((r) => /r=ai_chat_(api|assistant)/.test(r.url()) && r.request().method() === 'POST', { timeout: 120000 });
    await page.fill('[data-ai-chat-input]', text);
    await page.$eval('[data-ai-chat-form]', (f) => f.requestSubmit());
    const resp = await done;
    const json = await resp.json().catch(() => ({}));
    await page.waitForTimeout(800);
    return json;
  }

  const cart = await ask('Giỏ hàng của mình có gì?');
  rec('Cart question answered by PHP cart read', cart.intent_mode === 'CART' && cart.pipeline_mode === 'php_cart_read', `${cart.intent_mode}/${cart.pipeline_mode}`);
  rec('Cart payload contains the added product', (cart.commerce?.items || []).some((i) => String(i.id) === String(added.id)), `items=${cart.commerce?.count}`);
  const card = page.locator('[data-ai-commerce-card] .ss-chat-buy').last();
  rec('Cart card rendered in chat', await card.isVisible().catch(() => false));
  rec('Card shows subtotal and checkout link', /Tạm tính/.test(await card.innerText().catch(() => '')) && (await card.locator('a.ss-chat-buy__checkout-link').getAttribute('href') || '').includes('r=giohang'));
  await page.screenshot({ path: path.join(OUT, 'cart-card.png') });

  const policy = await ask('Chính sách đổi trả thế nào?');
  rec('Policy question not answered by PHP preflight', policy.pipeline_mode !== 'verified_store_content' || policy.intent_mode === 'STORE_SUPPORT', `${policy.intent_mode}/${policy.pipeline_mode}`);

  const conflict = await ask('Sản phẩm trong giỏ có xung đột không?');
  rec('Conflict question not hijacked by cart read', conflict.pipeline_mode !== 'php_cart_read', `${conflict.intent_mode}/${conflict.pipeline_mode}`);
  await page.screenshot({ path: path.join(OUT, 'after-conflict.png') });

  rec('No page JS errors', errors.length === 0, errors.slice(0, 2).join(' | '));
  await browser.close();
  const failed = results.filter((x) => !x).length;
  console.log(failed ? `FAIL: ${failed}/${results.length}` : `PASS: ${results.length}/${results.length}`);
  process.exit(failed ? 1 : 0);
})().catch((e) => { console.error(e); process.exit(2); });
