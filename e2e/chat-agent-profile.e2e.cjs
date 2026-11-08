/* E2E: a logged-in customer with a skin profile asks Syna about their skin.
 * The agent v2 answer must use the saved profile instead of asking for the skin type again.
 *
 * Run:
 *   NODE_PATH="$USERPROFILE/.cache/codex-runtimes/codex-primary-runtime/dependencies/node/node_modules" \
 *     node e2e/chat-agent-profile.e2e.cjs
 * Needs the seeded test account (php scripts/e2e/seed_test_accounts.php): oily skin, concerns "Mụn, Thâm mụn".
 * Credentials come from E2E_CUSTOMER_EMAIL / E2E_CUSTOMER_PASSWORD (env or .env) and are never printed.
 */
const fs = require('node:fs');
const path = require('node:path');
const { chromium } = require('playwright');

const ROOT = path.resolve(__dirname, '..');
const BASE = (process.env.E2E_BASE_URL || 'http://127.0.0.1:8080').replace(/\/$/, '');
const OUT = path.join(ROOT, 'report', 'chat-agent-profile-e2e');
const TURN_TIMEOUT = 120000;
const TURN_GAP_MS = Number(process.env.E2E_TURN_GAP_MS || 20000);

function envValue(name) {
  if (process.env[name]) return process.env[name];
  const file = path.join(ROOT, '.env');
  if (!fs.existsSync(file)) return '';
  const line = fs.readFileSync(file, 'utf8').split(/\r?\n/).find((l) => l.startsWith(name + '='));
  return line ? line.slice(name.length + 1).trim().replace(/^['"]|['"]$/g, '') : '';
}

// Phrases that mean the bot ignored the saved profile and asked for the skin type again.
const ASKS_AGAIN = /(chưa có thông tin về (loại )?da|chưa (thể )?(biết|nhìn thấy)[^.!?\n]{0,30}(da|làn da)|cho mình biết (thêm )?[^.!?\n]{0,20}(da bạn|loại da)|thuộc loại (da )?nào)/i;

const ERROR_BUBBLE = /(đang bận|gặp sự cố|thử lại (sau|giúp)|đã xảy ra lỗi|không thể kết nối|chưa sẵn sàng)/i;

const CASES = [
  // Must name the saved skin type AND a saved concern, not just mention oily skin in passing.
  { id: 'P1', text: 'bạn có biết làn da của tui như thế nào không', mustMention: /dầu[\s\S]*mụn|mụn[\s\S]*dầu/i },
  { id: 'P2', text: 'tư vấn cho tôi 3 sản phẩm cho da tôi sáng khỏe hơn', mustMention: null },
];

async function botTexts(page) {
  return page.$$eval('[data-ai-chat-widget] .ai-chat-widget__bubble--assistant .ai-chat-widget__bubble-text',
    (els) => els.map((e) => (e.innerText || '').trim()));
}

(async () => {
  fs.mkdirSync(OUT, { recursive: true });
  const email = envValue('E2E_CUSTOMER_EMAIL');
  const password = envValue('E2E_CUSTOMER_PASSWORD');
  if (!email || !password) {
    console.log('NOT RUN: E2E_CUSTOMER_EMAIL / E2E_CUSTOMER_PASSWORD missing');
    process.exit(2);
  }
  let browser = null;
  for (const opts of [{}, { channel: 'msedge' }, { channel: 'chrome' }]) {
    try { browser = await chromium.launch({ headless: true, ...opts }); break; } catch (e) { /* next */ }
  }
  if (!browser) {
    console.log('NOT RUN: no Chromium, Edge or Chrome available');
    process.exit(2);
  }
  const ctx = await browser.newContext({ viewport: { width: 1280, height: 900 } });
  const page = await ctx.newPage();
  const results = [];
  let failed = 0;
  try {
    await page.goto(`${BASE}/index.php?r=dangnhap`, { waitUntil: 'domcontentloaded' });
    const form = page.locator('form[action*="xulydangnhap"]').first();
    await form.locator('input[name="email"]').fill(email);
    await form.locator('input[name="mat_khau"]').fill(password);
    await Promise.all([page.waitForLoadState('domcontentloaded'), form.evaluate((f) => f.requestSubmit())]);
    await page.waitForTimeout(1000);

    await page.goto(`${BASE}/index.php`, { waitUntil: 'domcontentloaded' });
    await page.waitForSelector('[data-ai-chat-widget]');
    const v2 = await page.getAttribute('[data-ai-chat-widget]', 'data-agent-v2');
    if (v2 !== '1') throw new Error('agent v2 is not enabled on the widget');
    if ((await page.getAttribute('[data-ai-chat-toggle]', 'aria-expanded')) !== 'true') {
      await page.click('[data-ai-chat-toggle]');
    }
    await page.waitForSelector('[data-ai-chat-input]', { state: 'visible' });
    const newChat = page.locator('[data-ai-new-chat]').first();
    if (await newChat.isVisible().catch(() => false)) await newChat.click();

    const ask = async (text) => {
      const before = (await botTexts(page)).length;
      const finished = page.waitForEvent('requestfinished', {
        predicate: (r) => r.url().includes('r=ai_chat_agent_stream'), timeout: TURN_TIMEOUT,
      });
      await page.fill('[data-ai-chat-input]', text);
      await page.$eval('[data-ai-chat-form]', (f) => f.requestSubmit());
      await finished;
      await page.waitForTimeout(1500);
      return (await botTexts(page)).slice(before).join('\n').trim();
    };
    for (const [i, c] of CASES.entries()) {
      if (i > 0) await page.waitForTimeout(TURN_GAP_MS);
      let answer = await ask(c.text);
      if (!answer || ERROR_BUBBLE.test(answer.slice(0, 200))) {
        // LLM providers may be rate limited: wait and retry once.
        console.log(`[${c.id}] error answer, retrying in 60s: ${answer.slice(0, 80)}`);
        await page.waitForTimeout(60000);
        answer = await ask(c.text);
      }
      const reasons = [];
      if (!answer) reasons.push('empty answer');
      if (ERROR_BUBBLE.test(answer.slice(0, 200))) reasons.push('error answer');
      if (ASKS_AGAIN.test(answer)) reasons.push('asked for the skin type again');
      if (c.mustMention && !c.mustMention.test(answer)) reasons.push(`does not mention ${c.mustMention}`);
      const pass = reasons.length === 0;
      if (!pass) failed++;
      results.push({ id: c.id, user: c.text, answer, pass, reasons });
      console.log(`[${c.id}] ${pass ? 'PASS' : 'FAIL ' + reasons.join('; ')}: ${answer.replace(/\s+/g, ' ').slice(0, 160)}`);
      await page.screenshot({ path: path.join(OUT, `${c.id}.png`) });
    }
  } catch (e) {
    failed++;
    results.push({ error: String(e && e.message || e) });
    console.log('ERROR', e && e.message);
  } finally {
    fs.writeFileSync(path.join(OUT, 'result.json'), JSON.stringify({ at: new Date().toISOString(), results }, null, 2));
    await browser.close();
  }
  console.log(failed ? `RESULT: FAIL (${failed})` : 'RESULT: PASS');
  process.exit(failed ? 1 : 0);
})();
