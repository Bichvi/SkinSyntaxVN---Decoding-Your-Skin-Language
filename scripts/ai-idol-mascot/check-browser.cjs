/* Focused real-browser acceptance test. Uses existing Playwright via NODE_PATH. */
const assert = require('node:assert/strict');
const fs = require('node:fs/promises');
const path = require('node:path');
const { chromium } = require('playwright');

async function main() {
  const output = path.resolve('report/ai-idol-syna-v6');
  await fs.mkdir(output, { recursive: true });
  const browser = await chromium.launch({ channel: 'chrome', headless: true });
  const results = [];
  const errors = [];
  const base = process.env.MASCOT_DEMO_URL || 'http://localhost:8080/ai-idol-mascot-demo/index.html';
  try {
    const context = await browser.newContext({ viewport: { width: 1440, height: 1000 }, acceptDownloads: true });
    const page = await context.newPage();
    page.on('pageerror', (error) => errors.push(error.message));
    const requests = [];
    page.on('request', (request) => requests.push({ method: request.method(), url: request.url() }));
    const response = await page.goto(base);
    assert.equal(response.status(), 200);
    await page.waitForSelector('#stage[data-ready="true"]');
    assert.equal(await page.locator('#script button').count(), 8);
    assert.equal(await page.locator('#expression option').count(), 9);
    assert.equal(await page.locator('#gestureChoice option').count(), 12);
    assert.match(await page.locator('#script').innerText(), /Syna/);
    assert.doesNotMatch(await page.locator('#script').innerText(), /Luna/);
    assert.match(await page.locator('.sample-price').innerText(), /199.000 đ.*minh họa/);
    results.push('PASS: static page, atlas, WAV and 8 sentence cues load');
    await page.screenshot({ path: path.join(output, 'desktop.png'), fullPage: true });

    await page.locator('#play').click();
    await page.waitForFunction(() => Number(document.querySelector('#stage').dataset.mouth) > 0.1);
    const firstPixels = await page.locator('#stage').screenshot();
    await page.waitForFunction(() => Number(document.querySelector('#stage').dataset.time) > 1.5);
    const secondPixels = await page.locator('#stage').screenshot();
    assert.notDeepEqual(firstPixels, secondPixels);
    await page.locator('#play').click();
    await page.waitForFunction(() => document.querySelector('#stage').dataset.mouth === '0.000');
    results.push('PASS: audible playback clock drives visible animation; pause closes mouth');

    await page.locator('#script button').nth(2).click();
    await page.waitForFunction(() => document.querySelector('#stage').dataset.boardActive === 'centella');
    const narration = await page.evaluate(async () => (await fetch('./assets/syna-presentation-v5.json')).json());
    assert.ok(Number(await page.locator('#stage').getAttribute('data-time')) >= narration.cues[2].start - 0.1);
    await page.locator('#play').click();
    results.push('PASS: clicking ingredient sentence seeks narration and board highlight');

    // Exercise the real seek input; assert the board, glance and accessible notes
    // on the same media clock, including returning to a previous product point.
    async function seek(time) {
      await page.locator('#seek').evaluate((input, value) => {
        input.value = String(value); input.dispatchEvent(new Event('input', { bubbles: true }));
      }, time);
      await page.waitForFunction((value) => Math.abs(Number(document.querySelector('#stage').dataset.time) - value) < 0.04, time);
    }
    for (const cue of narration.cues.filter((item) => item.lookAtBoard)) {
      await seek(cue.start + 1.3);
      assert.equal(await page.locator('#stage').getAttribute('data-board-active'), cue.board);
      assert.ok(Number(await page.locator('#stage').getAttribute('data-board-look')) > 0.95);
      assert.equal(await page.locator('#boardNotes [aria-current="true"]').count(), 1);
      await page.locator('#stage').screenshot({ path: path.join(output, `board-${cue.board}.png`) });
      await seek(cue.end - 0.1);
      assert.equal(await page.locator('#stage').getAttribute('data-board-look'), '0.000');
    }
    await seek(narration.cues[3].start + 1);
    assert.equal(await page.locator('#stage').getAttribute('data-board-visible'), 'centella');
    assert.equal(await page.locator('#stage').getAttribute('data-board-active'), '');
    assert.equal(await page.locator('#stage').getAttribute('data-board-look'), '0.000');
    await seek(narration.cues[2].end + 0.15);
    assert.equal(await page.locator('#stage').getAttribute('data-board-visible'), 'centella');
    assert.equal(await page.locator('#stage').getAttribute('data-board-active'), '');
    await seek(narration.cues[2].start + 1.3);
    results.push('PASS: all three pointing targets, eased return, camera-only cue, gap retention and backward seek');

    await page.locator('#reducedMotion').check();
    await page.waitForFunction(() => document.querySelector('#stage').dataset.head === '0.0000');
    assert.equal(await page.locator('#stage').getAttribute('data-board-look'), '0.000');
    assert.equal(await page.locator('#stage').getAttribute('data-board-active'), 'centella');
    await page.locator('#reducedMotion').uncheck();
    await page.locator('[data-gesture="greeting"]').click();
    await page.waitForFunction(() => document.querySelector('#stage').dataset.gesture === 'greeting');
    results.push('PASS: reduced motion and manual gesture controls');
    await page.locator('#expression').selectOption('winkLeft');
    await page.waitForFunction(() => document.querySelector('#stage').dataset.expression === 'winkLeft');
    await page.locator('#expression').selectOption('surprised');
    await page.waitForFunction(() => document.querySelector('#stage').dataset.expression === 'surprised');
    await page.locator('#expression').selectOption('auto');
    await page.locator('#gestureChoice').selectOption('processing');
    await page.waitForFunction(() => document.querySelector('#stage').dataset.gesture === 'processing');
    assert.match(await page.locator('#gestureStatus').innerText(), /Đang thử/);
    assert.match(await page.locator('#status').innerText(), /tạm dừng/);
    await page.locator('#returnGesture').click();
    await page.waitForFunction(() => document.querySelector('#gestureStatus').textContent.startsWith('Theo kịch bản'));
    const jumps = await page.evaluate(async () => {
      const stage = document.querySelector('#stage');
      let previous = null, maxJump = 0;
      for (let frame = 0; frame < 85; frame += 1) {
        if (frame % 18 === 0) {
          const select = document.querySelector('#gestureChoice');
          select.value = ['greeting', 'happy', 'thinking', 'processing', 'listening'][Math.floor(frame / 18)];
          select.dispatchEvent(new Event('change', { bubbles: true }));
        }
        await new Promise(requestAnimationFrame);
        const current = [Number(stage.dataset.leftArm), Number(stage.dataset.rightArm)];
        if (previous) maxJump = Math.max(maxJump, ...current.map((value, i) => Math.abs(value - previous[i])));
        previous = current;
      }
      return maxJump;
    });
    assert.ok(jumps < 0.25, `arm snapped ${jumps} radians between frames`);
    await page.locator('#returnGesture').click();
    await page.waitForFunction(() => document.querySelector('#gestureStatus').textContent.startsWith('Theo kịch bản'));
    await page.locator('#stickers').uncheck();
    await page.locator('#stickers').check();
    results.push('PASS: expressions, select-to-preview, pause, smooth rapid switches, return-to-script and stickers');

    await page.locator('[data-theme="rose"]').click();
    assert.equal(await page.locator('[data-theme="rose"]').getAttribute('aria-pressed'), 'true');
    await page.locator('#backgroundFile').setInputFiles({ name: 'bad.txt', mimeType: 'text/plain', buffer: Buffer.from('invalid') });
    assert.equal(await page.locator('#error').isVisible(), true);
    await page.locator('#backgroundFile').setInputFiles({ name: 'invalid.png', mimeType: 'image/png', buffer: Buffer.from('not an image') });
    await page.waitForFunction(() => document.querySelector('#error').textContent.includes('Không đọc được'));
    await page.locator('#backgroundFile').setInputFiles(path.join(output, 'desktop.png'));
    await page.waitForFunction(() => document.querySelector('#backgroundNote').textContent.includes('desktop.png'));
    await page.locator('[data-theme="garden"]').click();
    results.push('PASS: themes, local image upload and malformed-image errors');

    // A recording really ends and downloads; no mocked MediaRecorder or audio.
    const downloaded = page.waitForEvent('download', { timeout: 60000 });
    await page.locator('#export').click();
    assert.equal(await page.locator('#seek').isDisabled(), true);
    const download = await downloaded;
    await download.saveAs(path.join(output, 'syna-thuyet-trinh-v6.webm'));
    await page.waitForFunction(() => document.querySelector('#exportNote').textContent.includes('Đã xuất'));
    await page.waitForFunction(() => document.querySelector('#stage').dataset.mouth === '0.000');
    results.push('PASS: full WebM export and mouth closes at end');

    await page.locator('#export').click();
    await page.locator('#export').click();
    await page.waitForFunction(() => document.querySelector('#exportNote').textContent.includes('Đã hủy'));
    await page.waitForFunction(() => !document.querySelector('#play').disabled);
    results.push('PASS: cancel export restores controls without saving partial video');

    await page.setViewportSize({ width: 390, height: 844 });
    await seek(narration.cues[5].start + 1.3);
    assert.match(await page.locator('#boardNotes').innerText(), /Panthenol/);
    assert.equal(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), true);
    await page.screenshot({ path: path.join(output, 'mobile.png'), fullPage: true });
    await page.locator('#play').focus();
    await page.keyboard.press('Enter');
    await page.waitForFunction(() => Number(document.querySelector('#stage').dataset.mouth) > 0);
    results.push('PASS: mobile layout has no horizontal overflow; keyboard starts playback');

    const failedPage = await context.newPage();
    await failedPage.route('**/assets/syna-presentation-v5.wav', (route) => route.fulfill({ status: 404, body: '' }));
    await failedPage.goto(base);
    await failedPage.waitForSelector('#error:not([hidden])');
    assert.equal(await failedPage.locator('#play').isDisabled(), true);
    results.push('PASS: missing audio is explained; playback stays disabled');
    await failedPage.close();

    assert.deepEqual(errors, []);
    assert.ok(requests.every((request) => request.method === 'GET'));
    assert.ok(requests.every((request) => request.url.startsWith(new URL(base).origin) || request.url.startsWith('blob:')));
    results.push('PASS: no JavaScript errors, external calls, POST or campaign writes');
    await fs.writeFile(path.join(output, 'browser-results.json'), JSON.stringify({ url: base, results, errors }, null, 2));
    results.forEach((result) => console.log(result));
  } finally {
    await browser.close();
  }
}

main().catch((error) => { console.error(error); process.exitCode = 1; });
