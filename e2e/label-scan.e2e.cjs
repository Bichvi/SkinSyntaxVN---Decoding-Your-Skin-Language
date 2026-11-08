// Run: NODE_PATH=<playwright node_modules> node e2e/label-scan.e2e.cjs [out_dir]
// Guest-only E2E for the ingredient-label scan in the Syna chat widget (LABEL_SCAN_ENABLED=1).
// Live part sends real photos (report/label-scan-e2e/inputs, not committed) through PHP -> Flask -> vision provider.
// Live part stays under the PHP limit of 6 scans / 10 min per session; mock part fulfils routes locally.
// No login, no account, no order, no secrets.
const path = require('node:path');
const fs = require('node:fs');
const { chromium } = require('playwright');
const BASE = (process.env.E2E_BASE_URL || 'http://127.0.0.1:8080').replace(/\/$/, '');
const OUT = process.argv[2] || path.resolve(__dirname, '..', 'report', 'label-scan-e2e');
const INPUTS = path.join(OUT, 'inputs');
const RETINOL_ID = process.env.E2E_RETINOL_PRODUCT_ID || '98';
fs.mkdirSync(OUT, { recursive: true });
const results = [];
const skip = (s, n = '') => console.log(`SKIP ${s} - ${n}`);
const unavailable = (j) => !!(j && j.result && j.result.status === 'vision_unavailable');
const rec = (s, ok, n = '') => { results.push(ok); console.log(`${ok ? 'PASS' : 'FAIL'} ${s}${n ? ' - ' + n : ''}`); };
const CARD = '[data-ls-card]';
const TONER = 'Water, Glycolic Acid, Butylene Glycol, Salicylic Acid, Niacinamide, Glycerin, Sodium Hydroxide, Panthenol, Allantoin, Fragrance, Phenoxyethanol';

async function openWidget(page) {
  await page.waitForSelector('[data-ai-chat-widget]');
  if ((await page.getAttribute('[data-ai-chat-toggle]', 'aria-expanded')) !== 'true') await page.click('[data-ai-chat-toggle]');
  await page.waitForSelector('[data-ai-chat-input]', { state: 'visible' });
}
// Groq free tier allows ~1 photo per minute (8k tokens/min); set E2E_LIVE_GAP_MS=65000 to space live uploads.
const LIVE_GAP_MS = Number(process.env.E2E_LIVE_GAP_MS || 0);
let lastLiveUpload = 0;
async function upload(page, file, live = false) {
  if (live && LIVE_GAP_MS > 0 && lastLiveUpload) {
    const left = lastLiveUpload + LIVE_GAP_MS - Date.now();
    if (left > 0) await page.waitForTimeout(left);
  }
  if (live) lastLiveUpload = Date.now();
  const wait = page.waitForResponse((r) => r.url().includes('r=chat_label_scan') && r.request().method() === 'POST', { timeout: 90000 });
  await page.setInputFiles('[data-ai-label-input]', path.join(INPUTS, file));
  const resp = await wait;
  const json = await resp.json().catch(() => ({}));
  await page.waitForTimeout(700);
  return json;
}
const bodyText = (page) => page.locator('[data-ai-chat-widget]').innerText();

(async () => {
  const browser = await chromium.launch({ channel: 'chrome' });
  const pageErrors = [];
  const dialogs = [];
  function track(page) {
    page.on('pageerror', (e) => pageErrors.push(e.message));
    page.on('dialog', (d) => { dialogs.push(d.message()); d.dismiss().catch(() => {}); });
  }

  // ---------------- LIVE (guest) ----------------
  const ctx = await browser.newContext({ viewport: { width: 1280, height: 900 } });
  const page = await ctx.newPage();
  track(page);
  await page.route('**/index.php?r=ai_chat_agent_stream*', (r) => r.fulfill({ status: 404, contentType: 'application/json', body: '{"fallback":true}' }));
  // Add the retinol product to the guest cart through the real add_to_cart form (found on the search page).
  await page.goto(`${BASE}/index.php?r=tatca&q=retinol`, { waitUntil: 'domcontentloaded' });
  const added = await page.evaluate(async (id) => {
    const form = [...document.querySelectorAll('form input[name="action"][value="add_to_cart"]')].map((i) => i.closest('form'))
      .find((f) => f.querySelector('[name=product_id]').value === id);
    if (!form) return { ok: false, note: 'form not found' };
    const u = new URL(form.getAttribute('action'), location.href);
    const r = await fetch(location.origin + u.pathname + u.search, { method: 'POST', body: new FormData(form), credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest', Accept: 'application/json' } });
    const body = await r.json().catch(() => ({}));
    return { ok: !!body.ok, note: body.message || `cart_count=${body.cart_count}` };
  }, RETINOL_ID);
  rec('Live: retinol product added to guest cart', added.ok, `${RETINOL_ID} ${added.note}`);
  await page.goto(`${BASE}/index.php`, { waitUntil: 'domcontentloaded' });
  await openWidget(page);
  rec('Live: attach button visible (flag on)', await page.locator('[data-ai-label-btn]').isVisible());

  // photo 1: card
  const j1 = await upload(page, '1-pharmaform-brightener.png', true);
  rec('Live photo 1: route ok', j1.ok === true, `ok=${j1.ok} code=${j1.code || ''} took=${j1.result && j1.result.took_ms}`);
  const card1 = page.locator(CARD).last();
  rec('Live photo 1: card header + ingredient chips', (await card1.locator('.ai-label-card__name').count()) === 1 && (await card1.locator('.ai-label-card__chip').count()) >= 3);
  rec('Live photo 1: skin quick-pick shown for guest', (await card1.locator('[data-ls-action="skin"]').count()) >= 3);
  await page.screenshot({ path: path.join(OUT, 'live-01-card-desktop.png') });
  rec('Live photo 1: cart conflict block or cart note present', (await card1.locator('.ai-label-card__conflicts, .ai-label-card__muted').count()) >= 1);
  const qp = page.waitForResponse((r) => r.url().includes('r=chat_label_scan') && r.request().method() === 'POST', { timeout: 60000 });
  await card1.locator('[data-ls-action="skin"][data-ls-skin="kho"]').click();
  await qp;
  await page.waitForTimeout(800);
  const card1b = page.locator(CARD).last();
  rec('Live quick-pick Da kho: card updated (pressed + fit title)', (await card1b.locator('[data-ls-action="skin"][data-ls-skin="kho"][aria-pressed="true"]').count()) === 1, (await card1b.locator('.ai-label-card__fit .ai-label-card__title').first().innerText().catch(() => '')).slice(0, 80));
  await page.screenshot({ path: path.join(OUT, 'live-02-quickpick-kho.png') });

  // photo 4: no ingredient list
  const j4 = await upload(page, '4-rivaj-no-inci.png', true);
  const t4 = await bodyText(page);
  if (unavailable(j4)) skip('Live photo 4: tips note', 'INFRA: vision provider rate-limited (vision_unavailable)'); else rec('Live photo 4: tips note, no ingredient card', (await page.locator('.ai-label-card--note').count()) >= 1 && /Mẹo chụp/.test(t4));
  await page.screenshot({ path: path.join(OUT, 'live-03-not-label.png') });

  // photo 7: low recognition
  const j7 = await upload(page, '7-ha-cream-partial.png', true);
  const card7 = page.locator(CARD).last();
  if (unavailable(j7)) skip('Live photo 7: low_recognition warning strip', 'INFRA: vision provider rate-limited (vision_unavailable)'); else rec('Live photo 7: low_recognition warning strip', (await card7.locator('.ai-label-card__warn').count()) === 1);
  rec('Live photo 7: edit box available', (await card7.locator('[data-ls-text]').count()) === 1);
  await page.screenshot({ path: path.join(OUT, 'live-04-low-recognition.png') });

  // edit with AHA/BHA toner text -> conflict with retinol in cart + schedule
  if (!(await card7.locator('[data-ls-edit]').isVisible())) await card7.locator('[data-ls-action="edit-toggle"]').click();
  await card7.locator('[data-ls-text]').fill(TONER);
  const wait = page.waitForResponse((r) => r.url().includes('r=chat_label_scan') && r.request().method() === 'POST', { timeout: 60000 });
  await card7.locator('[data-ls-action="reanalyze"]').click();
  const jr = await (await wait).json().catch(() => ({}));
  await page.waitForTimeout(800);
  const cardR = page.locator(CARD).last();
  rec('Live reanalyze: route ok', jr.ok === true, `code=${jr.code || ''}`);
  const conflictTxt = await cardR.locator('.ai-label-card__conflicts').innerText().catch(() => '');
  rec('Live reanalyze: conflict block with retinol cart product', /retinol/i.test(conflictTxt), conflictTxt.slice(0, 120).replace(/\n/g, ' | '));
  rec('Live reanalyze: schedule line shown', (await cardR.locator('.ai-label-card__schedule').count()) >= 1, (await cardR.locator('.ai-label-card__schedule').first().innerText().catch(() => '')).slice(0, 100));
  await page.screenshot({ path: path.join(OUT, 'live-05-conflict-schedule.png') });

  // mobile screenshot of a card
  await page.setViewportSize({ width: 375, height: 812 });
  await page.waitForTimeout(500);
  await cardR.scrollIntoViewIfNeeded().catch(() => {});
  await page.screenshot({ path: path.join(OUT, 'live-06-card-mobile-375.png') });
  const overflow = await page.evaluate(() => document.documentElement.scrollWidth > window.innerWidth + 2);
  rec('Mobile 375px: no horizontal page overflow', !overflow);
  await page.setViewportSize({ width: 1280, height: 900 });

  // follow-up chip -> normal chat reply
  const chip = page.locator('[data-ls-action="followup"]').last();
  const chipText = (await chip.innerText()).trim();
  const done = page.waitForResponse((r) => /r=ai_chat_(api|assistant)/.test(r.url()) && r.request().method() === 'POST', { timeout: 120000 });
  await chip.click();
  const cr = await done;
  await page.waitForTimeout(1500);
  rec('Follow-up chip sends a normal chat message and gets a reply', cr.status() === 200, `"${chipText.slice(0, 60)}" -> ${cr.status()}`);
  await page.screenshot({ path: path.join(OUT, 'live-07-followup.png') });
  await ctx.close();

  // ---------------- MOCK (fresh context, routes fulfilled locally) ----------------
  const mctx = await browser.newContext({ viewport: { width: 1280, height: 900 } });
  const mp = await mctx.newPage();
  track(mp);
  await mp.route('**/index.php?r=ai_chat_agent_stream*', (r) => r.fulfill({ status: 404, contentType: 'application/json', body: '{"fallback":true}' }));
  await mp.goto(`${BASE}/index.php`, { waitUntil: 'domcontentloaded' });
  await openWidget(mp);
  let mock = null;
  await mp.route('**/index.php?r=chat_label_scan*', (r) => r.fulfill({ status: mock.status || 200, contentType: 'application/json', body: JSON.stringify(mock.body) }));
  const send = async (m) => { mock = m; await upload(mp, '1-pharmaform-brightener.png'); };

  await send({ body: { ok: false, code: 'unavailable' } });
  rec('Mock vision_unavailable: paste box shown', (await mp.locator(`${CARD} [data-ls-text]`).count()) >= 1);
  for (const [code, re, status] of [['too_large', /quá lớn/, 413], ['bad_type', /JPG, PNG hoặc WEBP/, 415], ['rate_limited', /gửi hơi nhanh/, 429], ['csrf', /hết hạn/, 403]]) {
    await send({ status, body: { ok: false, code } });
    rec(`Mock ${code}: Vietnamese bubble`, re.test(await bodyText(mp)));
  }
  const xss = '<img src=x onerror=alert(1)>';
  await send({ body: { ok: true, result: {
    status: 'ok', product: { name: xss, brand: xss, product_type: 'serum', matched_product_id: null, match_score: null },
    ingredients: { source: 'photo', total: 2, recognized: 2, items: [{ key: 'niacinamide', name: xss, name_vi: null, role: 'brightening', rank: 1, highlight: true }, { key: 'water', name: 'Water', name_vi: null, role: null, rank: 2, highlight: false }], raw_text: 'Water, Niacinamide' },
    conflicts: [{ cart_product_id: '1', cart_product_name: xss, severity: 'high', timing: 'same_session', ingredients: ['niacinamide'], advice_vi: xss, rule_id: 'x', evidence: 'x' }],
    schedule: [], skin_fit: { verdict: 'unknown', findings: [], profile_used: {} }, alternatives: [], warnings: [], summary_vi: xss, sunscreen_note: false, took_ms: 1 } } });
  await mp.waitForTimeout(600);
  rec('Mock XSS: rendered as text, no injected img, no dialog', (await mp.locator(`${CARD} img[src="x"]`).count()) === 0 && dialogs.length === 0 && (await mp.locator(CARD).last().innerText()).includes('<img src=x'), `dialogs=${dialogs.length}`);
  await mp.screenshot({ path: path.join(OUT, 'mock-xss.png') });
  await mctx.close();

  rec('No page JS errors', pageErrors.length === 0, pageErrors.slice(0, 2).join(' | '));
  await browser.close();
  const failed = results.filter((x) => !x).length;
  console.log(failed ? `FAIL: ${failed}/${results.length}` : `PASS: ${results.length}/${results.length}`);
  process.exit(failed ? 1 : 0);
})().catch((e) => { console.error(e); process.exit(2); });
