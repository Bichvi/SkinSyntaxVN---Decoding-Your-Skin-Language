/* Real-browser acceptance test: AI Idol video on the product detail page.
 *
 * Run (Playwright resolved through NODE_PATH):
 *   NODE_PATH="$USERPROFILE/.cache/codex-runtimes/codex-primary-runtime/dependencies/node/node_modules" \
 *     node scripts/e2e/product-video.e2e.cjs
 *
 * Fixtures: scripts/e2e/seed-product-video-fixture.py (products 5689, 1000, 93, 1005 carry videos).
 * Optional env: E2E_BASE_URL, E2E_LIVE=1 (after seeding --live), E2E_ADMIN_EMAIL + E2E_ADMIN_PASSWORD.
 */
const assert = require('node:assert/strict');
const fs = require('node:fs/promises');
const path = require('node:path');
const { chromium } = require('playwright');

const BASE = (process.env.E2E_BASE_URL || 'http://localhost:8080').replace(/\/$/, '');
const OUT = path.resolve('report/product-video-e2e');
const VIDEO_IDS = new Set(['5689', '1000', '93', '1005']);
const detail = (id) => `${BASE}/index.php?r=chitiet&id=${id}`;

const results = [];
const pageErrors = [];
const consoleErrors = [];
const externalLoadFailures = []; // third-party CDN/font loads that fail when the machine is offline
let failed = 0;

async function check(name, fn) {
  try {
    await fn();
    results.push(`PASS: ${name}`);
  } catch (error) {
    failed += 1;
    results.push(`FAIL: ${name} -> ${String(error && error.message || error).split('\n')[0]}`);
  }
}

function skip(message) {
  results.push(`SKIP: ${message}`);
}

function watch(context, label) {
  context.on('page', (page) => {
    page.on('pageerror', (error) => pageErrors.push(`[${label}] ${page.url()} :: ${error.message}`));
    page.on('console', (message) => {
      if (message.type() !== 'error') return;
      const where = message.location() && message.location().url ? ` (${message.location().url})` : '';
      const failedUrl = /^Failed to load resource/.test(message.text()) ? (message.location() || {}).url : '';
      if (failedUrl && /^https?:/.test(failedUrl) && new URL(failedUrl).origin !== new URL(BASE).origin) {
        externalLoadFailures.push(`[${label}] ${message.text()} (${failedUrl})`);
        return;
      }
      consoleErrors.push(`[${label}] ${page.url()} :: ${message.text()}${where}`);
    });
  });
}

async function shot(page, name) {
  try { await page.screenshot({ path: path.join(OUT, name), fullPage: false }); } catch (e) { /* best effort */ }
}

async function open(page, id) {
  const response = await page.goto(detail(id), { waitUntil: 'load', timeout: 90000 });
  assert.ok(response && response.status() === 200, `chitiet ${id} HTTP ${response && response.status()}`);
}

const dialog = (page) => page.locator('.pv-modal[role=dialog][aria-modal=true]');

async function findProductWithoutVideo(page) {
  await page.goto(`${BASE}/index.php`, { waitUntil: 'load', timeout: 90000 });
  const ids = await page.evaluate(() => Array.from(document.querySelectorAll('a[href*="r=chitiet"]'))
    .map((a) => (new URL(a.href, location.href)).searchParams.get('id')).filter(Boolean));
  const id = ids.find((value) => !VIDEO_IDS.has(value));
  assert.ok(id, 'no product link without fixture video found on the home page');
  return id;
}

async function login(context) {
  // The login form lives in the header auth modal (POST r=xulydangnhap, fields email / mat_khau).
  const response = await context.request.post(`${BASE}/index.php?r=xulydangnhap`, {
    form: { email: process.env.E2E_ADMIN_EMAIL, mat_khau: process.env.E2E_ADMIN_PASSWORD },
  });
  assert.ok(response.ok(), `login request HTTP ${response.status()}`);
}

async function main() {
  await fs.mkdir(OUT, { recursive: true });
  const browser = await chromium.launch({ channel: 'chrome', headless: true });
  try {
    /* ---------------- Desktop ---------------- */
    const desktop = await browser.newContext({ viewport: { width: 1440, height: 1000 } });
    watch(desktop, 'desktop');
    const page = await desktop.newPage();

    await check('1. product 5689: data island, 3 cards, "Mới nhất" badge, mini player visible', async () => {
      await open(page, 5689);
      assert.equal(await page.locator('script#productVideoData').count(), 1, '#productVideoData missing');
      const cards = page.locator('section[data-pv-row] button.pv-card');
      assert.equal(await cards.count(), 3, 'expected 3 cards');
      assert.match(await page.locator('section[data-pv-row] h2').innerText(), /Video về sản phẩm/);
      assert.match(await cards.nth(0).locator('.pv-new').innerText(), /Mới nhất/);
      await page.locator('.pv-mini').waitFor({ state: 'visible', timeout: 5000 });
      await shot(page, '01-desktop-5689-row-and-mini.png');
    });

    await check('2. card #2 opens viewer 2/3, ArrowDown -> 3/3 landscape, next disabled, Escape returns focus', async () => {
      await open(page, 5689);
      const card = page.locator('button.pv-card[data-pv-index="1"]');
      await card.click();
      await dialog(page).waitFor({ state: 'visible', timeout: 5000 });
      assert.equal((await page.locator('.pv-count').innerText()).trim(), '2/3');
      assert.equal(await page.locator('.pv-stage').evaluate((n) => n.classList.contains('is-landscape')), false, 'stage 2 must be portrait');
      await page.keyboard.press('ArrowDown');
      await page.waitForFunction(() => document.querySelector('.pv-count').textContent.trim() === '3/3');
      assert.equal(await page.locator('.pv-stage').evaluate((n) => n.classList.contains('is-landscape')), true, 'stage 3 must be landscape');
      assert.equal(await page.locator('.pv-nav button[aria-label="Video tiếp theo"]').isDisabled(), true, 'next must be disabled at 3/3');
      assert.equal(await page.locator('.pv-nav button[aria-label="Video trước"]').isDisabled(), false);
      await shot(page, '02-desktop-viewer-3of3-landscape.png');
      await page.keyboard.press('Escape');
      await dialog(page).waitFor({ state: 'hidden', timeout: 5000 });
      const focused = await page.evaluate(() => document.activeElement && document.activeElement.getAttribute('data-pv-index'));
      assert.equal(focused, '1', 'focus must return to card index 1, got ' + focused);
    });

    await check('2b. clicking ▼ to the last video keeps focus in the dialog (Escape still closes)', async () => {
      await open(page, 5689);
      await page.locator('.pv-card[data-pv-index="0"]').click();
      await dialog(page).waitFor({ state: 'visible', timeout: 5000 });
      const next = page.locator('.pv-nav .pv-round').last();
      await next.click();
      await page.waitForFunction(() => document.querySelector('.pv-count').textContent.trim() === '2/3');
      await next.click();
      await page.waitForFunction(() => document.querySelector('.pv-count').textContent.trim() === '3/3');
      const inside = await page.evaluate(() => Boolean(document.activeElement && document.activeElement.closest('.pv-modal')));
      assert.equal(inside, true, 'focus left the dialog after the ▼ button was disabled');
      await page.keyboard.press('Escape');
      await dialog(page).waitFor({ state: 'hidden', timeout: 5000 });
    });

    await check('3. mini opens viewer at 1/3, tray has "Đang xem" which closes the viewer', async () => {
      await open(page, 5689);
      if (await page.locator('.pv-mini.is-live').count()) {
        // During a live show the mini player goes to the livestream instead (check 9); open from the row.
        await page.locator('.pv-card[data-pv-index="0"]').click();
      } else {
        await page.locator('.pv-mini').click();
      }
      await dialog(page).waitFor({ state: 'visible', timeout: 5000 });
      assert.equal((await page.locator('.pv-count').innerText()).trim(), '1/3');
      const current = page.locator('.pv-tray .pv-product', { has: page.locator('.pv-product-tag', { hasText: 'Đang xem' }) });
      assert.ok(await current.count() >= 1, 'no "Đang xem" tray item');
      assert.equal(await current.first().evaluate((n) => n.tagName), 'BUTTON');
      await shot(page, '03-desktop-viewer-tray.png');
      await current.first().click();
      await dialog(page).waitFor({ state: 'hidden', timeout: 5000 });
    });

    await check('4. product 1000: single video (no counter), tray links to other products, click navigates', async () => {
      await open(page, 1000);
      await page.locator('.pv-mini').click();
      await dialog(page).waitFor({ state: 'visible', timeout: 5000 });
      assert.equal(await page.locator('.pv-count').isHidden(), true, '.pv-count must be hidden for a single video');
      assert.equal(await page.locator('.pv-nav').isHidden(), true, 'prev/next arrows must be hidden for a single video');
      const links = page.locator('.pv-tray a.pv-product[href*="r=chitiet&id="]');
      const n = await links.count();
      assert.ok(n >= 2, `expected >=2 tray links, got ${n}`);
      for (let i = 0; i < n; i += 1) {
        assert.match(await links.nth(i).locator('.pv-product-tag').innerText(), /Cùng chương trình/);
        assert.match(await links.nth(i).innerText(), /Xem chi tiết/);
      }
      await shot(page, '04-desktop-1000-tray.png');
      await links.first().click();
      await page.waitForURL(/r=chitiet&id=\d+/, { timeout: 10000 });
      assert.doesNotMatch(page.url(), /id=1000(&|$)/, 'should have left product 1000');
    });

    await check('5. product without videos: no data island, no product-video.css, no mini', async () => {
      const id = await findProductWithoutVideo(page);
      await open(page, id);
      assert.equal(await page.locator('script#productVideoData').count(), 0, `#productVideoData present on ${id}`);
      assert.equal(await page.locator('link[href*="product-video.css"]').count(), 0, 'product-video.css linked');
      assert.equal(await page.locator('.pv-mini').count(), 0, '.pv-mini present');
      await shot(page, `05-desktop-no-video-${id}.png`);
    });

    await check('6. mini close -> reopen chip; stays hidden after reload; reopen restores mini', async () => {
      const fresh = await desktop.newPage(); // new tab = fresh sessionStorage
      await open(fresh, 5689);
      await fresh.locator('.pv-mini').waitFor({ state: 'visible', timeout: 5000 });
      await fresh.locator('.pv-mini-close').click();
      await fresh.locator('.pv-mini').waitFor({ state: 'hidden', timeout: 5000 });
      await fresh.locator('.pv-reopen').waitFor({ state: 'visible', timeout: 5000 });
      assert.match(await fresh.locator('.pv-reopen').innerText(), /Xem video/);
      await fresh.reload({ waitUntil: 'load', timeout: 90000 });
      await fresh.locator('.pv-reopen').waitFor({ state: 'visible', timeout: 5000 });
      assert.equal(await fresh.locator('.pv-mini').isHidden(), true, 'mini must stay hidden after reload');
      await fresh.locator('.pv-reopen').click();
      await fresh.locator('.pv-mini').waitFor({ state: 'visible', timeout: 5000 });
      assert.equal(await fresh.locator('.pv-reopen').isHidden(), true);
      await fresh.close();
    });
    await desktop.close();

    /* ---------------- Mobile ---------------- */
    const mobile = await browser.newContext({ viewport: { width: 390, height: 844 }, isMobile: true, hasTouch: true });
    watch(mobile, 'mobile');
    const mpage = await mobile.newPage();
    await check('7. mobile 390x844: viewer stage covers the viewport, nav arrows hidden', async () => {
      await open(mpage, 5689);
      await mpage.locator('button.pv-card[data-pv-index="0"]').scrollIntoViewIfNeeded();
      await mpage.locator('button.pv-card[data-pv-index="0"]').click();
      await dialog(mpage).waitFor({ state: 'visible', timeout: 5000 });
      const box = await mpage.locator('.pv-stage').boundingBox();
      const view = mpage.viewportSize();
      assert.ok(Math.abs(box.width - 390) <= 2, `stage width ${box.width}`);
      assert.ok(Math.abs(box.height - view.height) <= 2, `stage height ${box.height} vs ${view.height}`);
      assert.equal(await mpage.locator('.pv-nav').evaluate((n) => getComputedStyle(n).display), 'none');
      await shot(mpage, '07-mobile-viewer.png');
    });
    await mobile.close();

    /* ---------------- Live (gated) ---------------- */
    if (process.env.E2E_LIVE === '1') {
      const liveCtx = await browser.newContext({ viewport: { width: 1440, height: 1000 } });
      watch(liveCtx, 'live');
      const lp = await liveCtx.newPage();
      await check('9. LIVE: mini is .is-live with "LIVE" badge and navigates to r=live', async () => {
        await open(lp, 5689);
        const mini = lp.locator('.pv-mini.is-live');
        await mini.waitFor({ state: 'visible', timeout: 5000 });
        assert.match(await lp.locator('.pv-mini-badge').innerText(), /LIVE/);
        await shot(lp, '09-desktop-live-mini.png');
        await Promise.all([lp.waitForURL(/r=live/, { timeout: 10000 }), mini.click()]);
      });
      await liveCtx.close();
    } else {
      skip('live (set E2E_LIVE=1 after seeding --live)');
    }

    /* ---------------- Studio (gated) ---------------- */
    if (process.env.E2E_ADMIN_EMAIL && process.env.E2E_ADMIN_PASSWORD) {
      const adminCtx = await browser.newContext({ viewport: { width: 1440, height: 1000 } });
      watch(adminCtx, 'studio');
      const ap = await adminCtx.newPage();
      await check('10. Studio: default product_video purpose locks sale styles; live_and_product unlocks them', async () => {
        await login(adminCtx);
        await ap.goto(`${BASE}/index.php?r=admin_ai_idol`, { waitUntil: 'load', timeout: 90000 });
        assert.match(ap.url(), /r=admin_ai_idol/, 'not on the studio page (login failed?)');
        const sale = ap.locator('input[name=agentStyle][value="tập trung chốt sale"]');
        const price = ap.locator('input[name=agentStyle][value="nhấn mạnh giá và ưu đãi"]');
        await sale.waitFor({ state: 'attached', timeout: 10000 });
        assert.equal(await ap.locator('input[name=agentPurpose][value=product_video]').isChecked(), true, 'product_video not default');
        assert.equal(await ap.locator('#agentLiveFields').isHidden(), true, '#agentLiveFields should be hidden');
        assert.equal(await sale.isDisabled(), true, 'sale style should be disabled');
        assert.equal(await price.isDisabled(), true, 'price style should be disabled');
        await shot(ap, '10-studio-product-video.png');
        await ap.locator('input[name=agentPurpose][value=live_and_product]').check({ force: true });
        await ap.locator('#agentLiveFields').waitFor({ state: 'visible', timeout: 5000 });
        assert.equal(await sale.isDisabled(), false, 'sale style should be enabled');
        assert.equal(await price.isDisabled(), false, 'price style should be enabled');
        await shot(ap, '10-studio-live-and-product.png');
      });
      await adminCtx.close();
    } else {
      skip('studio (set E2E_ADMIN_EMAIL/E2E_ADMIN_PASSWORD)');
    }
  } finally {
    await browser.close();
  }

  await check('8. no page errors and no first-party console errors on the visited pages', async () => {
    const problems = [...pageErrors.map((e) => `pageerror ${e}`), ...consoleErrors.map((e) => `console.error ${e}`)];
    assert.equal(problems.length, 0, `${problems.length} problem(s): ${problems.slice(0, 5).join(' | ')}`);
  });
  console.log(results.join('\n'));
  if (externalLoadFailures.length) {
    console.log(`NOTE: ${externalLoadFailures.length} third-party resource load failure(s) ignored by check 8 (off-origin, usually offline DNS):`);
    externalLoadFailures.slice(0, 10).forEach((line) => console.log(`  ${line}`));
  }
  if (pageErrors.length || consoleErrors.length) {
    console.log('--- details ---');
    [...pageErrors, ...consoleErrors].forEach((line) => console.log(line));
  }
  console.log(`Screenshots: ${OUT}`);
  process.exitCode = failed ? 1 : 0;
}

main().catch((error) => {
  console.error('E2E crashed:', error);
  process.exit(2);
});
