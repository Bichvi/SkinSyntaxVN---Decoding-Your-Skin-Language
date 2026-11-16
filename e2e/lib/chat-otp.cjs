/* Shared E2E helper for the chat order OTP step (draft card → "Xác nhận đặt hàng" → email OTP → "Xác nhận").
 *
 * The E2E stack runs PHP with MAIL_DEMO_MODE=1: no mail is sent and ChatAgentController writes
 * "[chat_order_otp demo] code=NNNNNN" to the php container's stderr. readDemoOtp() reads it back with `docker logs`.
 * The code is a one-time, 5-minute test value; it is never printed by these helpers.
 * Env: E2E_PHP_CONTAINER (default skinsyntax-php).
 */
const { spawnSync } = require('node:child_process');

const PHP_CONTAINER = process.env.E2E_PHP_CONTAINER || 'skinsyntax-php';
const OTP_LINE_RE = /\[chat_order_otp demo\] code=(\d{6})/g;
const CARD = '.ai-chat-agent-draft-card';
const OTP_INPUT = `${CARD} .ai-chat-agent-draft-card__otp-input`;

const sleep = (ms) => new Promise((r) => setTimeout(r, ms));

// php-fpm writes error_log() to the container's stderr, which `docker logs` replays on its own stderr: read both.
function dockerLogs(since) {
  const r = spawnSync('docker', ['logs', '--since', since, PHP_CONTAINER], { encoding: 'utf8', maxBuffer: 32 * 1024 * 1024 });
  return (r.stdout || '') + (r.stderr || '');
}

/** Latest demo OTP logged by PHP since `sinceMs` (epoch ms), or null when none shows up within `timeoutMs`. */
async function readDemoOtp(sinceMs, timeoutMs = 15000) {
  const since = new Date(sinceMs - 2000).toISOString();
  const end = Date.now() + timeoutMs;
  while (Date.now() < end) {
    const codes = [...dockerLogs(since).matchAll(OTP_LINE_RE)].map((m) => m[1]);
    if (codes.length) return codes[codes.length - 1];
    await sleep(500);
  }
  return null;
}

/**
 * Click the enabled "Xác nhận đặt hàng" button of the last draft card, wait for the OTP box,
 * read the demo code and submit it. Returns { otpSent, codeFound, submitted, otpSendResponse }.
 * `dbl: true` double-clicks the confirm button (one OTP must still be sent) and double-clicks the OTP submit.
 * `code` overrides the code read from the logs (e.g. a wrong code for a negative test).
 */
async function confirmWithOtp(page, { dbl = false, code = null, timeoutMs = 30000 } = {}) {
  const startedAt = Date.now();
  const otpResponses = [];
  const onResponse = async (res) => {
    if (!res.url().includes('r=chat_order_otp')) return;
    let body = null;
    try { body = await res.json(); } catch (e) { body = { unreadable: true }; }
    otpResponses.push({ status: res.status(), body });
  };
  page.on('response', onResponse);
  try {
    const btn = page.locator(`${CARD} button:has-text("Xác nhận đặt hàng"):not([disabled])`).last();
    if (dbl) await btn.dblclick({ timeout: 10000 }); else await btn.click({ timeout: 10000 });
    const input = page.locator(OTP_INPUT).last();
    await input.waitFor({ state: 'visible', timeout: timeoutMs });
    const otpSent = otpResponses.some((r) => r.body && r.body.ok);
    const value = code || (await readDemoOtp(startedAt));
    if (!value) return { otpSent, codeFound: false, submitted: false, otpResponses };
    await input.fill(value);
    const submit = page.locator(`${CARD} .ai-chat-agent-draft-card__otp button:has-text("Xác nhận"):not([disabled])`).last();
    if (dbl) await submit.dblclick({ timeout: 10000 }); else await submit.click({ timeout: 10000 });
    return { otpSent, codeFound: true, submitted: true, otpResponses };
  } finally {
    // Keep listening briefly so a late /chat_order_otp response is still counted by callers that inspect it.
    setTimeout(() => page.off('response', onResponse), 5000);
  }
}

/** Close the home-page welcome popup (shown on every load outside production mode) so it cannot cover the chat toggle. */
async function dismissWelcome(page) {
  const close = page.locator('[data-welcome-popup] [data-welcome-close]');
  try {
    await close.waitFor({ state: 'visible', timeout: 4000 });
  } catch (e) {
    return false; // popup not shown on this page load
  }
  await close.click({ timeout: 5000 }).catch(() => {});
  await page.locator('[data-welcome-backdrop]').waitFor({ state: 'hidden', timeout: 5000 }).catch(() => {});
  return true;
}

module.exports = { readDemoOtp, confirmWithOtp, dismissWelcome, OTP_INPUT, CARD };
