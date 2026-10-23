/* Real-browser check: Studio "Tự động" no longer stops on fixable ambiguity.
 *
 * Run (Playwright through NODE_PATH, admin test account from .env):
 *   NODE_PATH=... node scripts/e2e/syna-autopilot-no-stop.e2e.cjs
 *
 * For each prompt: types it in the Tự động tab with "Duyệt kịch bản trước" (nothing is published), waits for the
 * run to finish planning, asserts it was not stopped and created a programme, then CANCELS that programme at once.
 * Exit: 0 pass, 1 a check failed, 2 could not run.
 */
const assert = require('node:assert/strict');
const { chromium } = require('playwright');

const BASE = (process.env.E2E_BASE_URL || 'http://localhost:8080').replace(/\/$/, '');
const STUDIO = `${BASE}/index.php?r=admin_ai_idol`;
const PROMPTS = (process.env.E2E_PROMPTS || [
  "Làm video sản phẩm cho 5 sữa rửa mặt cho da dầu bán chạy nhất trong 30 ngày qua, giọng tư vấn nhẹ nhàng, nhấn mạnh thành phần. bữa nay đang qua mùa đông, nên tui muốn đẩy mạnh các sản phẩm dịu nhẹ như l'oreal, eucerin",
  'Làm video 5 sản phẩm trị mụn, trừ sản phẩm đã hết hàng',
  'Xả hàng tồn kho thấp, làm video 3 sản phẩm',
].join('\n')).split('\n').filter(Boolean);

(async () => {
  if (!process.env.E2E_ADMIN_EMAIL || !process.env.E2E_ADMIN_PASSWORD) {
    console.log('E2E NOT RUN: thiếu E2E_ADMIN_EMAIL/E2E_ADMIN_PASSWORD');
    process.exit(2);
  }
  // E2E_CHANNEL=chrome uses the installed Chrome when Playwright's own browser is not downloaded.
  const browser = await chromium.launch(process.env.E2E_CHANNEL ? { channel: process.env.E2E_CHANNEL } : {});
  const ctx = await browser.newContext({ viewport: { width: 1440, height: 1000 } });
  const login = await ctx.request.post(`${BASE}/index.php?r=xulydangnhap`, {
    form: { email: process.env.E2E_ADMIN_EMAIL, mat_khau: process.env.E2E_ADMIN_PASSWORD },
  });
  assert.ok(login.ok(), `login HTTP ${login.status()}`);
  const page = await ctx.newPage();
  const errors = [];
  page.on('pageerror', (e) => errors.push(e.message));
  let failed = 0;

  for (const [index, prompt] of PROMPTS.entries()) {
    const label = `${index + 1}. ${prompt.slice(0, 60)}`;
    let campaignId = '';
    try {
      await page.goto(STUDIO, { waitUntil: 'load', timeout: 90000 });
      assert.match(page.url(), /r=admin_ai_idol/, 'not on Studio (login failed?)');
      await page.locator('#autoFlowTab').click();
      await page.locator('input[name=autoApprovalMode][value=MANUAL]').check({ force: true });
      await page.fill('#autoBrief', prompt);
      const [created] = await Promise.all([
        page.waitForResponse((r) => r.url().includes('admin_ai_idol_agent_create'), { timeout: 60000 }),
        page.locator('#autoSubmit').click(),
      ]);
      const runId = String((await created.json()).run_id || '');
      assert.ok(runId, 'no run_id');
      let run = {};
      for (let i = 0; i < 90; i += 1) { // up to ~3 minutes of planning
        await page.waitForTimeout(2000);
        const res = await page.request.get(`${BASE}/index.php?r=admin_ai_idol_agent_run&run_id=${runId}`);
        const body = await res.json();
        run = body.run || body.data || body;
        if (run.campaign_id || ['NEEDS_INPUT', 'FAILED', 'CANCELLED', 'CAMPAIGN_CREATED'].includes(run.status)) break;
      }
      campaignId = String(run.campaign_id || '');
      const stops = (run.decisions || []).filter((d) => d.level === 'stopped').map((d) => d.message);
      const picked = ((run.plan || {}).resolved_products || []).map((p) => p.name).filter(Boolean);
      assert.equal(stops.length, 0, `Syna dừng: ${stops.join(' | ')}`);
      assert.ok(campaignId, `chưa tạo chương trình (status ${run.status}, stage ${run.current_stage})`);
      const card = await page.locator('#autoCard').innerText({ timeout: 10000 });
      assert.ok(!/Đã dừng/.test(card), 'thẻ kết quả vẫn hiện "Đã dừng"');
      console.log(`PASS: ${label}\n      ${picked.length} SP: ${picked.slice(0, 5).join(' / ')}`);
    } catch (error) {
      failed += 1;
      console.log(`FAIL: ${label} -> ${String(error.message || error).split('\n')[0]}`);
    } finally {
      if (campaignId) {
        const csrf = await page.evaluate(() => String(window.AI_IDOL_CSRF || ''));
        const res = await page.request.post(`${BASE}/index.php?r=admin_ai_idol_cancel_campaign&campaign_id=${encodeURIComponent(campaignId)}`, {
          headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf }, data: { csrf },
        });
        if (!res.ok()) { failed += 1; console.log(`FAIL: không hủy được chương trình ${campaignId} — hãy hủy tay`); }
        console.log(`      đã hủy chương trình ${campaignId}: HTTP ${res.status()}`);
      }
    }
  }
  if (errors.length) { failed += 1; console.log(`FAIL: lỗi JS trên trang: ${errors[0]}`); }
  await browser.close();
  process.exit(failed ? 1 : 0);
})().catch((error) => { console.log(`E2E CRASH: ${error.message}`); process.exit(2); });
