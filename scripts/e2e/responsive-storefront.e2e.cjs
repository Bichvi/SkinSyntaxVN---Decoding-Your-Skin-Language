// Uses an already running local storefront and the project's Playwright runtime.
const assert = require('node:assert/strict');
const { chromium } = require('playwright');

const base = new URL(process.env.E2E_BASE_URL || 'http://127.0.0.1:8080/');
assert.ok(['127.0.0.1', 'localhost'].includes(base.hostname), 'Use a local test storefront');

const sizes = [
  [375, 667], [390, 844], [393, 852], [440, 956], [360, 800], [393, 873],
  [768, 1024], [1366, 768], [1920, 1080], [667, 375],
];

async function assertInside(page, locator) {
  const box = await locator.boundingBox();
  const viewport = await page.evaluate(() => ({
    width: window.innerWidth,
    height: window.innerHeight,
  }));
  assert.ok(box, 'Expected a rendered element');
  assert.ok(box.x >= -1 && box.y >= -1, JSON.stringify(box));
  assert.ok(box.x + box.width <= viewport.width + 1, JSON.stringify(box));
  assert.ok(box.y + box.height <= viewport.height + 1, JSON.stringify(box));
  return box;
}

async function checkViewport(browser, width, height) {
  const phone = width < 768;
  const context = await browser.newContext({
    viewport: { width, height },
    isMobile: phone,
    hasTouch: phone,
    reducedMotion: 'reduce',
  });
  try {
    await context.route('**/*', route => {
      const url = new URL(route.request().url());
      return url.origin === base.origin ? route.continue() : route.abort();
    });
    const page = await context.newPage();
    page.setDefaultTimeout(8000);
    const response = await page.goto(new URL('index.php?r=tatca', base).href, {
      waitUntil: 'domcontentloaded',
    });
    assert.equal(response.status(), 200);
    assert.ok(await page.evaluate(() =>
      document.documentElement.scrollWidth <= document.documentElement.clientWidth + 1
    ), 'Unexpected page overflow');

    if (phone) {
      const form = page.locator('#liveSearchForm');
      const searchBox = await assertInside(page, form);
      const input = await assertInside(page, form.locator('#search-input'));
      const submit = await assertInside(page, form.locator('button[type=submit]'));
      assert.ok(input.y + input.height <= searchBox.y + searchBox.height);
      assert.ok(submit.x >= input.x + input.width - 1);
    }

    const launcher = page.locator('[data-ai-chat-toggle]');
    const panel = page.locator('#aiChatPanel');
    const composer = page.locator('[data-ai-chat-form]');
    const launcherRect = await assertInside(page, launcher);
    assert.ok(launcherRect.x + launcherRect.width / 2 > width / 2);
    await launcher.click();
    await panel.waitFor({ state: 'visible' });
    await page.locator('[data-ai-chat-widget].is-open').waitFor();
    await panel.evaluate(el => Promise.allSettled(el.getAnimations().map(animation => animation.finished)));
    const expand = page.locator('[data-ai-chat-expand]');
    await expand.click();
    assert.equal(await expand.getAttribute('aria-pressed'), 'true');
    await assertInside(page, panel);
    await assertInside(page, composer);
    await expand.click();
    assert.equal(await expand.getAttribute('aria-pressed'), 'false');
    await assertInside(page, composer);
    if (phone) {
      await page.locator('[data-ai-chat-input]').fill('Serum');
      const keyboard = await page.evaluate(() => {
        const viewport = window.visualViewport;
        const height = Math.max(230, viewport.height - 270);
        Object.defineProperty(viewport, 'height', { configurable: true, value: height });
        viewport.dispatchEvent(new Event('resize'));
        return height;
      });
      await page.waitForFunction(limit =>
        document.querySelector('[data-ai-chat-form]').getBoundingClientRect().bottom <= limit,
      keyboard);
      await page.evaluate(() => {
        delete window.visualViewport.height;
        window.visualViewport.dispatchEvent(new Event('resize'));
      });
    }
    await page.locator('[data-ai-chat-close]').click();
    await panel.waitFor({ state: 'hidden' });

    if (width < 992) {
      const filter = page.locator('.catf-fab');
      const sheet = page.locator('#catfSheet');
      await filter.click();
      await sheet.waitFor({ state: 'visible' });
      await page.locator('.catf-apply').click();
      await sheet.waitFor({ state: 'hidden' });
      const menu = page.locator('#mobileNavOffcanvas');
      await page.locator('.mobile-menu-toggle').click();
      await menu.waitFor({ state: 'visible' });
      await menu.getByRole('button', { name: 'Đóng', exact: true }).click();
      await menu.waitFor({ state: 'hidden' });
    }

    if (width === 360 && process.env.E2E_PRODUCT_ID) {
      const detail = new URL('index.php?r=chitiet', base);
      detail.searchParams.set('id', process.env.E2E_PRODUCT_ID);
      await page.goto(detail.href, { waitUntil: 'domcontentloaded' });
      const tabs = page.locator('#detailTabNav');
      await tabs.scrollIntoViewIfNeeded();
      assert.ok(await tabs.evaluate(el => el.scrollWidth <= el.clientWidth + 1));
      await tabs.locator('#tab-hoi-dap').click();
      await page.locator('#pane-hoi-dap').waitFor({ state: 'visible' });
    }
  } finally {
    await context.close();
  }
}

async function main() {
  const browser = await chromium.launch({ channel: 'chrome' });
  let failures = 0;
  try {
    for (const [width, height] of sizes) {
      try {
        await checkViewport(browser, width, height);
        console.log(`PASS ${width}x${height}`);
      } catch (error) {
        failures += 1;
        console.error(`FAIL ${width}x${height}: ${error.message}`);
      }
    }
  } finally {
    await browser.close();
  }
  process.exitCode = failures ? 1 : 0;
}

main().catch(error => {
  console.error(error);
  process.exitCode = 1;
});
