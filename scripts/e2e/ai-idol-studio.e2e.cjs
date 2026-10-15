/* Real-browser acceptance test: AI Idol Studio redesign (Tự động / Thủ công tabs, Hoạt động, publication page).
 *
 * Run (Playwright resolved through NODE_PATH):
 *   NODE_PATH="$USERPROFILE/.cache/codex-runtimes/codex-primary-runtime/dependencies/node/node_modules" \
 *     node scripts/e2e/ai-idol-studio.e2e.cjs
 *
 * Env: E2E_BASE_URL (default http://localhost:8080), E2E_ADMIN_EMAIL, E2E_ADMIN_PASSWORD (never hardcoded).
 *
 * Without the admin variables only the anonymous checks run (Studio / publication page deny a visitor, the public
 * product page has no console error) and the admin part prints "E2E NOT RUN: thiếu E2E_ADMIN_EMAIL/E2E_ADMIN_PASSWORD".
 * Exit codes: 0 = everything that could run passed, 1 = a check failed, 2 = nothing could run / crash.
 *
 * With admin credentials the script CREATES a manual programme and an auto-run request (names are printed at the end
 * so you can cancel or hide them). It never deletes data.
 */
const assert = require('node:assert/strict');
const fs = require('node:fs/promises');
const path = require('node:path');
const { chromium } = require('playwright');

const BASE = (process.env.E2E_BASE_URL || 'http://localhost:8080').replace(/\/$/, '');
const OUT = path.resolve('report/ai-idol-studio-e2e');
const HAS_ADMIN = Boolean(process.env.E2E_ADMIN_EMAIL && process.env.E2E_ADMIN_PASSWORD);
const STUDIO = `${BASE}/index.php?r=admin_ai_idol`;
const PUBLICATION = `${BASE}/index.php?r=admin_ai_product_videos`;
const VIEWPORTS = [
  { name: 'desktop', width: 1440, height: 1000 },
  { name: 'mobile', width: 390, height: 844 },
];

const results = [];
const pageErrors = [];
const consoleErrors = [];
const externalLoadFailures = []; // third-party CDN/font loads that fail when the machine is offline
const created = [];
let failed = 0;
let ran = 0;

async function check(name, fn) {
  ran += 1;
  try {
    await fn();
    results.push(`PASS: ${name}`);
  } catch (error) {
    failed += 1;
    results.push(`FAIL: ${name} -> ${String((error && error.message) || error).split('\n')[0]}`);
  }
}

function notRun(message) {
  results.push(`NOT RUN: ${message}`);
}

function watch(context, label) {
  context.on('page', (page) => {
    page.on('pageerror', (error) => pageErrors.push(`[${label}] ${page.url()} :: ${error.message}`));
    page.on('console', (message) => {
      if (message.type() !== 'error') return;
      const location = message.location() || {};
      const failedUrl = /^Failed to load resource/.test(message.text()) ? location.url : '';
      if (failedUrl && /^https?:/.test(failedUrl) && new URL(failedUrl).origin !== new URL(BASE).origin) {
        externalLoadFailures.push(`[${label}] ${message.text()} (${failedUrl})`);
        return;
      }
      consoleErrors.push(`[${label}] ${page.url()} :: ${message.text()}${location.url ? ` (${location.url})` : ''}`);
    });
  });
}

async function shot(page, name) {
  try { await page.screenshot({ path: path.join(OUT, name), fullPage: false }); } catch (e) { /* best effort */ }
}

const noHorizontalScroll = (page) => page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth);

async function login(context) {
  // The login form lives in the header auth modal (POST r=xulydangnhap, fields email / mat_khau).
  const response = await context.request.post(`${BASE}/index.php?r=xulydangnhap`, {
    form: { email: process.env.E2E_ADMIN_EMAIL, mat_khau: process.env.E2E_ADMIN_PASSWORD },
  });
  assert.ok(response.ok(), `login request HTTP ${response.status()}`);
}

/* ----------------------------------------------------------------- anonymous checks (always run) */
async function anonymousChecks(browser) {
  const ctx = await browser.newContext({ viewport: VIEWPORTS[0] });
  watch(ctx, 'anon');
  const page = await ctx.newPage();

  for (const [label, url, marker] of [['Studio', STUDIO, '#idolStudio'], ['publication page', PUBLICATION, '#adminProductVideos']]) {
    await check(`anonymous visitor cannot open the ${label} (no 500, no admin markup)`, async () => {
      const response = await page.goto(url, { waitUntil: 'load', timeout: 90000 });
      assert.ok(response, 'no response');
      assert.ok(response.status() < 500, `HTTP ${response.status()}`);
      assert.equal(await page.locator(marker).count(), 0, `${marker} rendered for an anonymous visitor (url ${page.url()})`);
      assert.doesNotMatch(await page.locator('body').innerText(), /Fatal error|Stack trace|Warning:/, 'PHP error text on the page');
    });
  }

  await check('public product page loads with no console error', async () => {
    await page.goto(`${BASE}/index.php`, { waitUntil: 'load', timeout: 90000 });
    const id = await page.evaluate(() => {
      const link = document.querySelector('a[href*="r=chitiet"]');
      return link ? new URL(link.href, location.href).searchParams.get('id') : '';
    });
    assert.ok(id, 'no product link found on the home page');
    const before = consoleErrors.length + pageErrors.length;
    const response = await page.goto(`${BASE}/index.php?r=chitiet&id=${id}`, { waitUntil: 'load', timeout: 90000 });
    assert.ok(response && response.status() === 200, `chitiet ${id} HTTP ${response && response.status()}`);
    assert.ok(await page.locator('h2.detail-title').count() > 0, 'product title (h2.detail-title) missing');
    await page.waitForTimeout(1000);
    const fresh = [...consoleErrors, ...pageErrors].slice(before);
    assert.equal(fresh.length, 0, `${fresh.length} error(s): ${fresh.slice(0, 3).join(' | ')}`);
  });
  await ctx.close();
}

/* ----------------------------------------------------------------- admin checks */
async function studioFlow(browser) {
  const ctx = await browser.newContext({ viewport: VIEWPORTS[0] });
  watch(ctx, 'admin');
  const page = await ctx.newPage();
  const activityCards = () => page.locator('#activityList .act-card');
  let productId = '';
  let loggedIn = false;

  await check('1. login, Studio shows 2 tabs with Tự động active by default', async () => {
    await login(ctx);
    await page.goto(STUDIO, { waitUntil: 'load', timeout: 90000 });
    assert.match(page.url(), /r=admin_ai_idol/, `not on the Studio (login failed? url ${page.url()})`);
    loggedIn = true;
    assert.equal(await page.locator('.flow-tab[role=tab]').count(), 2, 'expected 2 tabs');
    assert.equal(await page.locator('#autoFlowTab').getAttribute('aria-selected'), 'true', 'Tự động not default');
    assert.equal(await page.locator('#manualFlowTab').getAttribute('aria-selected'), 'false');
    assert.equal(await page.locator('#autoFlow').isVisible(), true);
    assert.equal(await page.locator('#manualFlow').isHidden(), true);
    await shot(page, '01-desktop-studio.png');
  });
  if (!loggedIn) {
    notRun('các kịch bản Studio còn lại: đăng nhập admin không thành công');
    await ctx.close();
    return;
  }

  for (const vp of VIEWPORTS) {
    await check(`1b. no horizontal scroll at ${vp.width}x${vp.height}`, async () => {
      await page.setViewportSize({ width: vp.width, height: vp.height });
      await page.goto(STUDIO, { waitUntil: 'load', timeout: 90000 });
      await page.locator('#autoFlowTab').waitFor({ state: 'visible', timeout: 10000 });
      assert.equal(await noHorizontalScroll(page), true, 'Tự động tab scrolls horizontally');
      await page.locator('#manualFlowTab').click();
      await page.locator('#manualFlow').waitFor({ state: 'visible', timeout: 5000 });
      assert.equal(await noHorizontalScroll(page), true, 'Thủ công tab scrolls horizontally');
      await shot(page, `01b-${vp.name}-studio.png`);
    });
  }
  await page.setViewportSize({ width: VIEWPORTS[0].width, height: VIEWPORTS[0].height });

  await check('2. Thủ công: Bán chạy toàn kỳ + Top 3 selects exactly 3 products', async () => {
    await page.goto(STUDIO, { waitUntil: 'load', timeout: 90000 });
    await page.locator('#manualFlowTab').click();
    await page.locator('#manualFlow').waitFor({ state: 'visible', timeout: 5000 });
    await page.locator('input[name=manualSales][value=best_all]').check({ force: true });
    await page.selectOption('#manualQuantity', '3');
    await page.waitForFunction(() => document.querySelectorAll('#manualProductList .wz-product input[data-wz-product]:checked').length === 3, null, { timeout: 30000 });
    const ids = await page.locator('#manualProductList .wz-product input[data-wz-product]:checked').evaluateAll((nodes) => nodes.map((n) => n.value));
    assert.equal(ids.length, 3, `ticked ${ids.length}`);
    productId = ids[0];
    await shot(page, '02-desktop-manual-step1.png');
  });

  await check('2b. Thủ công: video sản phẩm locks "Tập trung chốt sale"; step 4 intent sentence', async () => {
    assert.ok(productId, 'no products selected in the previous check');
    await page.locator('#manualNext').click();
    await page.locator('#manualStep2').waitFor({ state: 'visible', timeout: 5000 });
    assert.equal(await page.locator('input[name=manualPurpose][value=product_video]').isChecked(), true, 'product_video not default');
    await page.locator('#manualNext').click();
    await page.locator('#manualStep3').waitFor({ state: 'visible', timeout: 5000 });
    const gentle = page.locator('#manualStyleGrid label.wz-check', { hasText: 'Tư vấn nhẹ nhàng' }).locator('input');
    await gentle.check({ force: true });
    assert.equal(await gentle.isChecked(), true);
    const sale = page.locator('#manualStyleGrid label.wz-check', { hasText: 'Tập trung chốt sale' }).locator('input');
    assert.equal(await sale.isDisabled(), true, '"Tập trung chốt sale" must be locked for product video');
    await page.locator('#manualNext').click();
    await page.locator('#manualStep4').waitFor({ state: 'visible', timeout: 5000 });
    const intent = (await page.locator('#manualIntent').innerText()).trim();
    assert.ok(intent.startsWith('Syna làm video sản phẩm (không nói giá) cho 3 sản phẩm:'), `intent: ${intent.slice(0, 90)}`);
    await shot(page, '02b-desktop-manual-step4.png');
  });

  await check('2c. Thủ công: Tạo adds one "Thủ công" card; double click creates only one programme', async () => {
    const before = await activityCards().count();
    const create = page.locator('#manualCreate');
    await create.waitFor({ state: 'visible', timeout: 5000 });
    await create.dblclick();
    await page.waitForFunction((n) => document.querySelectorAll('#activityList .act-card').length > n, before, { timeout: 60000 });
    await page.waitForTimeout(3000); // let a (wrong) second request land before counting
    const after = await activityCards().count();
    assert.equal(after - before, 1, `cards before ${before}, after ${after}`);
    const manualTags = await page.locator('#activityList .act-card .act-tag', { hasText: 'Thủ công' }).count();
    assert.ok(manualTags >= 1, 'no card labelled "Thủ công"');
    const name = await activityCards().first().locator('.act-card-title').innerText();
    created.push(`Thủ công: ${name.trim()}`);
    await shot(page, '02c-desktop-activity-after-create.png');
  });

  await check('3. Tự động: decision card has rows, the injected <img> stays inert, new card labelled "Tự động"', async () => {
    assert.ok(productId, 'no real product id from step 2');
    await page.locator('#autoFlowTab').click();
    await page.locator('#autoFlow').waitFor({ state: 'visible', timeout: 5000 });
    const before = await activityCards().count();
    let dialogSeen = false;
    page.on('dialog', async (dialog) => { dialogSeen = true; await dialog.dismiss(); });
    const prompt = `Làm video giới thiệu sản phẩm mã ${productId} <img src=x onerror=alert(1)>`;
    await page.fill('#autoBrief', prompt);
    await page.locator('#autoSubmit').click();
    await page.waitForFunction(() => document.querySelectorAll('#autoCard .au-facts > div').length >= 1, null, { timeout: 60000 });
    assert.equal(await page.locator('#autoCard img[src="x"]').count(), 0, 'injected <img src=x> became an element');
    assert.equal(dialogSeen, false, 'alert() fired from injected markup');
    await page.waitForFunction((n) => document.querySelectorAll('#activityList .act-card').length > n, before, { timeout: 60000 });
    const autoTags = await page.locator('#activityList .act-card .act-tag', { hasText: 'Tự động' }).count();
    assert.ok(autoTags >= 1, 'no card labelled "Tự động"');
    const name = await activityCards().first().locator('.act-card-title').innerText();
    created.push(`Tự động: ${name.trim()} (yêu cầu: ${prompt})`);
    await shot(page, '03-desktop-auto-card.png');
  });

  await check('4. "Cần bạn" filter count equals the number of visible cards', async () => {
    const filter = page.locator('[data-act-filter=needs_you]');
    const shown = Number((await filter.locator('.act-count').innerText()).replace(/\D/g, '') || '0');
    await filter.click();
    await page.waitForTimeout(500);
    const cards = await activityCards().count();
    assert.equal(cards, shown, `button says ${shown}, ${cards} card(s) visible`);
    await page.locator('[data-act-filter=all]').click();
  });

  /* ---------------- publication page (brief [rcm] scenarios) ---------------- */
  await check('5. publication page: video list loads, publish modal opens without console error', async () => {
    const before = consoleErrors.length + pageErrors.length;
    const response = await page.goto(PUBLICATION, { waitUntil: 'load', timeout: 90000 });
    assert.ok(response && response.status() === 200, `HTTP ${response && response.status()}`);
    await page.waitForFunction(() => document.getElementById('apvGrid').getAttribute('aria-busy') === 'false', null, { timeout: 30000 });
    assert.equal(await page.locator('#apvNotice:not([hidden])').count(), 0, 'list shows an error notice');
    const total = await page.locator('.apv-card').count();
    const manage = page.locator('.apv-card [data-apv-action=manage]:not([disabled])').first();
    if (await manage.count() === 0) {
      results.push(`NOT RUN: mở modal xuất bản - không có video READY để mở (danh sách có ${total} thẻ)`);
    } else {
      await manage.click();
      await page.locator('#apvPubModal').waitFor({ state: 'visible', timeout: 5000 });
      await shot(page, '05-desktop-publish-modal.png');
      await page.keyboard.press('Escape');
    }
    const fresh = [...consoleErrors, ...pageErrors].slice(before);
    assert.equal(fresh.length, 0, `${fresh.length} error(s): ${fresh.slice(0, 3).join(' | ')}`);
  });

  await check('6. auto-published video (source auto) plays on its product page (only with a real READY auto video)', async () => {
    await page.goto(PUBLICATION, { waitUntil: 'load', timeout: 90000 });
    await page.waitForFunction(() => document.getElementById('apvGrid').getAttribute('aria-busy') === 'false', null, { timeout: 30000 });
    const card = page.locator('.apv-card', { has: page.locator('.apv-badge.auto') })
      .filter({ has: page.locator('.apv-badge.ready') }).filter({ has: page.locator('.apv-badge.published') }).first();
    if (await card.count() === 0) {
      results.push('NOT RUN: video tự động trên trang sản phẩm - chưa có video READY nào có source auto đã xuất bản');
      ran -= 1;
      return;
    }
    const videoId = await card.getAttribute('data-video-id');
    const href = await card.locator('a[href*="r=chitiet"]').first().getAttribute('href');
    assert.ok(href, 'published auto video has no product link');
    await page.goto(new URL(href.replace(/&amp;/g, '&'), `${BASE}/`).toString(), { waitUntil: 'load', timeout: 90000 });
    const island = await page.locator('script#productVideoData').textContent();
    assert.ok(island && island.includes(videoId), `video ${videoId} missing from #productVideoData`);
    const playable = await page.evaluate(() => {
      const video = document.querySelector('video');
      return Boolean(video && (video.currentSrc || video.src || video.querySelector('source')));
    });
    assert.ok(playable || await page.locator('.pv-card, .pv-mini').count() > 0, 'no playable video element on the product page');
  });

  await ctx.close();
}

async function main() {
  await fs.mkdir(OUT, { recursive: true });
  const browser = await chromium.launch({ channel: 'chrome', headless: true });
  try {
    await anonymousChecks(browser);
    if (HAS_ADMIN) {
      await studioFlow(browser);
    } else {
      notRun('E2E NOT RUN: thiếu E2E_ADMIN_EMAIL/E2E_ADMIN_PASSWORD (Studio, Thủ công, Tự động, Hoạt động, trang xuất bản)');
      console.log('E2E NOT RUN: thiếu E2E_ADMIN_EMAIL/E2E_ADMIN_PASSWORD');
    }
  } finally {
    await browser.close();
  }

  await check('no page errors and no first-party console errors on the visited pages', async () => {
    const problems = [...pageErrors.map((e) => `pageerror ${e}`), ...consoleErrors.map((e) => `console.error ${e}`)];
    assert.equal(problems.length, 0, `${problems.length} problem(s): ${problems.slice(0, 5).join(' | ')}`);
  });

  console.log(results.join('\n'));
  if (externalLoadFailures.length) {
    console.log(`NOTE: ${externalLoadFailures.length} third-party resource load failure(s) ignored (off-origin, usually offline DNS):`);
    externalLoadFailures.slice(0, 10).forEach((line) => console.log(`  ${line}`));
  }
  if (pageErrors.length || consoleErrors.length) {
    console.log('--- details ---');
    [...pageErrors, ...consoleErrors].forEach((line) => console.log(line));
  }
  if (created.length) {
    console.log('Dữ liệu đã tạo (hủy hoặc ẩn trong Studio nếu không cần):');
    created.forEach((line) => console.log(`  - ${line}`));
  }
  console.log(`Screenshots: ${OUT}`);
  if (failed) process.exitCode = 1;
  else if (!HAS_ADMIN) process.exitCode = 2; // admin flows could not run; anonymous checks above may still have passed
  else process.exitCode = ran ? 0 : 2;
}

main().catch((error) => {
  console.error('E2E crashed:', error);
  process.exit(2);
});
