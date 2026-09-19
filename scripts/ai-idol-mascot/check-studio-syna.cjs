// Browser contract test: actual PHP view + live assets, intercepted API writes.
// No login bypass, no campaigns created, no external broadcast.
const { chromium } = require('playwright');
const { execFileSync } = require('node:child_process');
const assert = require('node:assert/strict');
const fs = require('node:fs/promises');
const path = require('node:path');

(async () => {
  const root = path.resolve(__dirname, '../..'), output = path.join(root, 'report/ai-idol-syna-native');
  await fs.mkdir(output, { recursive: true });
  const fixture = `define('BASE_URL',''); function resolve_image_url($v){return $v;} $products=[['ma_san_pham'=>'995','ten_san_pham'=>'Vichy QA','link_hinh_anh'=>'']]; include 'frontend/views/admin/ai_idol/index.php';`;
  const html = execFileSync('php', ['-r', fixture], { cwd: root, encoding: 'utf8' });
  const browser = await chromium.launch({ channel: 'chrome', headless: true });
  const results = [], errors = [], writes = [];
  try {
    const page = await browser.newPage({ viewport: { width: 1500, height: 1000 } });
    page.on('pageerror', error => errors.push(error.message));
    const fakeCampaign = { _id: 'qa-only', name: 'Syna QA', status: 'AWAITING_APPROVAL', product_ids: ['995'], configuration: { avatar_mode: 'syna_3d' }, segments: [] };
    await page.route('**/index.php?*', async route => {
      const request = route.request(), params = new URL(request.url()).searchParams;
      if (params.get('r') === 'qa_studio') return route.fulfill({ contentType: 'text/html', body: html });
      let body;
      if (request.method() === 'POST') {
        writes.push({ route: params.get('r'), data: request.postDataJSON() });
        body = { ok: true, campaign_id: 'qa-only' };
      } else body = params.get('r') === 'admin_ai_idol_campaigns' ? { ok: true, data: [] } : { ok: true, data: fakeCampaign };
      return route.fulfill({ contentType: 'application/json', body: JSON.stringify(body) });
    });
    await page.goto('http://localhost:8080/index.php?r=qa_studio');
    await page.locator('.product-check').check(); await page.locator('#nextStep').click();
    assert.equal(await page.locator('#characterMode').inputValue(), 'syna_3d');
    assert.equal(await page.locator('#customAvatarPicker').isVisible(), false);
    assert.equal(await page.locator('#videoFormat option[value="9:16"]').isDisabled(), true);
    await page.locator('#characterMode').selectOption('custom');
    assert.equal(await page.locator('#customAvatarPicker').isVisible(), true);
    assert.equal(await page.locator('#videoFormat option[value="9:16"]').isDisabled(), false);
    await page.locator('#nextStep').click();
    const validationMessage = await page.locator('#formMessage').innerText();
    assert.equal(validationMessage.length > 10, true);
    assert.equal(await page.locator('#formMessage').isVisible(), true);
    results.push('PASS: legacy media validation remains required; vertical format remains available to custom avatars');
    await page.locator('#characterMode').selectOption('syna_3d');
    await page.locator('#nextStep').click();
    assert.equal(await page.locator('#stepPanel3').isVisible(), true);
    await page.locator('#createCampaign').click();
    await page.waitForTimeout(1500);
    const createMessage = await page.locator('#formMessage').innerText();
    if (!await page.locator('#formMessage.success').isVisible()) throw new Error(`Create contract did not complete: ${createMessage}`);
    assert.equal(writes.length, 1);
    assert.equal(writes[0].route, 'admin_ai_idol_create_campaign');
    assert.equal(writes[0].data.configuration.avatar_mode, 'syna_3d');
    assert.equal(writes[0].data.configuration.avatar_asset, '');
    assert.equal(writes[0].data.configuration.format, '16:9');
    assert.deepEqual(writes[0].data.product_ids, ['995']);
    assert.match(await page.locator('#campaignDetail').innerText(), /Syna 3D/);
    results.push('PASS: Syna goes through creation contract without upload and preserves review state');
    await page.locator('#stepTab2').click();
    await page.screenshot({ path: path.join(output, 'studio-contract-desktop.png'), fullPage: true });
    await page.setViewportSize({ width: 390, height: 844 });
    assert.equal(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), true);
    await page.screenshot({ path: path.join(output, 'studio-contract-mobile.png'), fullPage: true });
    assert.deepEqual(errors, []);
    results.push('PASS: mobile overflow and browser script error checks');
    await fs.writeFile(path.join(output, 'studio-contract-results.json'), JSON.stringify({ results, errors, scope: 'PHP view/live JS/CSS, API writes intercepted; not authenticated full E2E' }, null, 2));
    results.forEach(value => console.log(value));
  } finally { await browser.close(); }
})().catch(error => { console.error(error); process.exitCode = 1; });
