/**
 * E2E: host goes live with a fake camera/mic, a guest viewer watches, the stream
 * survives past the freshness window, then the host ends it and the recording is saved.
 *
 * Run (Playwright resolved through NODE_PATH, same as the other e2e scripts):
 *   NODE_PATH="$USERPROFILE/.cache/codex-runtimes/codex-primary-runtime/dependencies/node/node_modules" \
 *     node e2e/live-stream.e2e.cjs [out_dir]
 *
 * Env: E2E_BASE_URL (default http://localhost:8080), E2E_LIVE_ID (default 8),
 *      E2E_LIVE_HOLD_MS (default 90000), E2E_ADMIN_EMAIL / E2E_ADMIN_PASSWORD
 *      (read from the environment or the git-ignored .env; never printed),
 *      E2E_LIVE_ROLE=staff to host as the E2E_STAFF_EMAIL / E2E_STAFF_PASSWORD staff account.
 *
 * WARNING: this mutates the chosen live session for real: it goes LIVE on LiveKit,
 * writes one chat message, then ENDS the session (it becomes a replay and cannot go
 * live again). Only run it against a throwaway test session.
 */
const fs = require('node:fs');
const path = require('node:path');
const { chromium } = require('playwright');

const ROOT = path.resolve(__dirname, '..');
function envFromDotenv(key) {
  if (process.env[key]) return process.env[key];
  try {
    const line = fs.readFileSync(path.join(ROOT, '.env'), 'utf8').split(/\r?\n/).find((l) => l.startsWith(key + '='));
    return line ? line.slice(key.length + 1).trim().replace(/^["']|["']$/g, '') : '';
  } catch (e) { return ''; }
}
const BASE = (process.env.E2E_BASE_URL || 'http://localhost:8080').replace(/\/$/, '');
const LIVE_ID = process.env.E2E_LIVE_ID || '8';
const HOLD_MS = Number(process.env.E2E_LIVE_HOLD_MS || 90000);
const OUT = process.argv[2] || path.join(ROOT, 'report', 'live-stream-e2e');
const ROLE = (process.env.E2E_LIVE_ROLE || 'admin').toLowerCase() === 'staff' ? 'staff' : 'admin';
const ADMIN_EMAIL = envFromDotenv(ROLE === 'staff' ? 'E2E_STAFF_EMAIL' : 'E2E_ADMIN_EMAIL');
const ADMIN_PASSWORD = envFromDotenv(ROLE === 'staff' ? 'E2E_STAFF_PASSWORD' : 'E2E_ADMIN_PASSWORD');
fs.mkdirSync(OUT, { recursive: true });

const results = [];
const rec = (step, ok, note = '') => { results.push(ok); console.log(`${ok ? 'PASS' : 'FAIL'} ${step}${note ? ' - ' + note : ''}`); };
const livePage = (studio) => `${BASE}/index.php?r=live&id=${encodeURIComponent(LIVE_ID)}${studio ? '&studio=1' : ''}`;
const errorsOf = (page, bag) => {
  page.on('pageerror', (e) => bag.push('pageerror: ' + e.message));
  page.on('console', (m) => { if (m.type() === 'error') bag.push('console: ' + m.text()); });
};
async function ping(request) {
  const r = await request.get(`${BASE}/index.php?r=api_live_ping&room=${encodeURIComponent(LIVE_ID)}`);
  return r.json().catch(() => ({}));
}
async function videoState(page, selector) {
  return page.evaluate((sel) => {
    const v = document.querySelector(sel);
    return v ? { hidden: v.hidden, w: v.videoWidth, h: v.videoHeight, t: v.currentTime, paused: v.paused } : null;
  }, selector);
}
// Like page.waitForFunction, but survives the page reloading itself mid-wait
// (the viewer page reloads when the session goes live or ends).
async function waitUntil(page, fn, arg, timeoutMs) {
  const deadline = Date.now() + timeoutMs;
  while (Date.now() < deadline) {
    try { if (await page.evaluate(fn, arg)) return true; } catch (e) { /* context destroyed by reload */ }
    await new Promise((r) => setTimeout(r, 1000));
  }
  throw new Error(`waitUntil timed out after ${timeoutMs}ms`);
}

(async () => {
  if (!ADMIN_EMAIL || !ADMIN_PASSWORD) {
    console.log(`E2E NOT RUN: thiếu tài khoản ${ROLE} (E2E_${ROLE.toUpperCase()}_EMAIL/PASSWORD)`);
    process.exit(2);
  }
  const browser = await chromium.launch({
    channel: process.env.E2E_CHANNEL || 'chrome',
    headless: true,
    args: ['--use-fake-device-for-media-stream', '--use-fake-ui-for-media-stream', '--autoplay-policy=no-user-gesture-required'],
  });
  const hostErrors = [];
  const viewerErrors = [];
  const dialogs = [];
  try {
    // ---- Host (admin) ----
    const hostCtx = await browser.newContext({ permissions: ['camera', 'microphone'], viewport: { width: 1366, height: 900 } });
    const login = await hostCtx.request.post(`${BASE}/index.php?r=xulydangnhap`, { form: { email: ADMIN_EMAIL, mat_khau: ADMIN_PASSWORD } });
    rec(`0. ${ROLE} login request`, login.ok(), `HTTP ${login.status()}`);
    const roleCheck = await hostCtx.request.get(`${BASE}/index.php?r=admin_lives`, { maxRedirects: 0 });
    rec(`0b. ${ROLE} can open live management`, roleCheck.status() === 200, `HTTP ${roleCheck.status()}`);
    const host = await hostCtx.newPage();
    errorsOf(host, hostErrors);
    host.on('dialog', async (d) => {
      dialogs.push(`${d.type()}: ${d.message()}`);
      if (d.type() === 'confirm') await d.accept(); else await d.dismiss();
    });
    // Every recording chunk upload (10 s chunks + one final call), from the start.
    const uploads = [];
    host.on('response', (r) => {
      if (r.url().includes('r=api_upload_live_recording')) uploads.push({ status: r.status() });
    });

    let initial = await ping(hostCtx.request);
    if (process.env.E2E_LIVE_RESET === '1' && ['ended', 'replay_ready', 'recording_failed', 'interrupted'].includes(initial.stream_status)) {
      // Opt-in: put a finished test session back to "ready" (state=offline) so the flow can run again.
      const reset = await hostCtx.request.post(`${BASE}/index.php?r=api_live_stream_state`, { form: { live_id: LIVE_ID, state: 'offline' } });
      console.log(`   reset ${initial.stream_status} -> offline: HTTP ${reset.status()}`);
      initial = await ping(hostCtx.request);
    }
    console.log(`   initial stream_status=${initial.stream_status}`);
    rec('1. session is not ended before the test', !['ended', 'replay_ready', 'recording_failed'].includes(initial.stream_status), String(initial.stream_status));

    // ---- Viewer (guest) opens BEFORE the stream starts ----
    const viewerCtx = await browser.newContext({ viewport: { width: 1280, height: 860 } });
    const viewer = await viewerCtx.newPage();
    errorsOf(viewer, viewerErrors);
    await viewer.goto(livePage(false), { waitUntil: 'load', timeout: 60000 });
    const viewerBefore = await viewer.evaluate(() => document.getElementById('ssLivePage')?.dataset.liveStatus);
    rec('2. viewer page loads before the stream (not live yet)', viewerBefore !== 'live', `status=${viewerBefore}`);

    // ---- Host goes live ----
    await host.goto(livePage(true), { waitUntil: 'load', timeout: 60000 });
    rec('3. studio shows camera/mic buttons', (await host.locator('#btnToggleCam').count()) === 1 && (await host.locator('#btnToggleMic').count()) === 1);
    await host.waitForFunction(() => /Có thể bắt đầu phát|Đã kết nối/.test(document.getElementById('liveConnectionBadge')?.textContent || ''), null, { timeout: 30000 })
      .then(() => rec('4. host connected to LiveKit', true), (e) => rec('4. host connected to LiveKit', false, e.message.split('\n')[0]));
    const liveResp = host.waitForResponse((r) => r.url().includes('r=api_live_stream_state') && r.request().postData()?.includes('live'), { timeout: 45000 });
    await host.click('#btnToggleCam');
    await host.waitForFunction(() => { const v = document.getElementById('livekitLocalVideo'); return v && !v.hidden && v.videoWidth > 0; }, null, { timeout: 20000 }).catch(() => null);
    await host.waitForTimeout(500);
    const preview = await host.evaluate(() => {
      const v = document.getElementById('livekitLocalVideo');
      if (!v) return { ok: false, why: 'no local video' };
      const r = v.getBoundingClientRect();
      const top = document.elementFromPoint(r.left + r.width / 2, r.top + r.height / 2);
      return { ok: !v.hidden && v.videoWidth > 0 && top === v, hidden: v.hidden, width: v.videoWidth, top: top ? (top.id || top.className || top.tagName) : null };
    });
    rec('5a. camera preview visible before going live', preview.ok, JSON.stringify(preview));
    const goDisabledAfterCam = await host.evaluate(() => !!document.getElementById('btnGoLive')?.disabled);
    rec('5b. go-live disabled until both devices on', goDisabledAfterCam);
    await host.click('#btnToggleMic');
    await host.waitForFunction(() => { const b = document.getElementById('btnGoLive'); return b && !b.disabled; }, null, { timeout: 20000 }).catch(() => null);
    await host.click('#btnGoLive');
    const lr = await liveResp.catch(() => null);
    const liveJson = lr ? await lr.json().catch(() => ({})) : {};
    rec('5. host state=live accepted by server', !!liveJson.ok && liveJson.state === 'live', JSON.stringify(liveJson));
    const startedAt = Date.now();

    // ---- Viewer auto-reloads into the live stream ----
    await waitUntil(viewer, () => document.getElementById('ssLivePage')?.dataset.liveStatus === 'live', null, 30000)
      .then(() => rec('6. viewer page auto-updated to LIVE', true), () => rec('6. viewer page auto-updated to LIVE', false));
    await waitUntil(viewer, () => { const v = document.getElementById('livekitRemoteVideo'); return v && !v.hidden && v.videoWidth > 0; }, null, 45000)
      .then(() => rec('7. viewer receives host video', true), () => rec('7. viewer receives host video', false));
    await viewer.screenshot({ path: path.join(OUT, 'viewer-live.png') });
    await host.screenshot({ path: path.join(OUT, 'host-live.png') });

    // ---- Viewer chat reaches the host ----
    const chatText = `E2E live chat ${Date.now()}`;
    if (await viewer.locator('#liveChatInput').count()) {
      viewer.on('dialog', async (d) => { dialogs.push(`viewer ${d.type()}: ${d.message()}`); await d.dismiss(); });
      const chatResp = viewer.waitForResponse((r) => r.url().includes('r=api_live_chat') && !r.url().includes('history') && r.request().method() === 'POST', { timeout: 30000 }).catch(() => null);
      await viewer.fill('#liveChatInput', chatText);
      await viewer.press('#liveChatInput', 'Enter');
      const cr = await chatResp;
      const chatJson = cr ? await cr.json().catch(() => ({})) : {};
      rec('8a. viewer chat accepted by server', !!chatJson.ok, cr ? `HTTP ${cr.status()} ${chatJson.message || ''}` : 'no request');
      await waitUntil(host, (t) => (document.getElementById('liveChatBox')?.textContent || '').includes(t), chatText, 20000)
        .then(() => rec('8. viewer chat appears on host page', true), () => rec('8. viewer chat appears on host page', false));
    } else {
      rec('8. viewer chat appears on host page', false, 'chat input missing on viewer page');
    }

    // ---- Hold past the freshness window (the original bug dropped the stream after 30s) ----
    const v1 = await videoState(viewer, '#livekitRemoteVideo');
    const remaining = HOLD_MS - (Date.now() - startedAt);
    console.log(`   holding the stream for ${Math.round(Math.max(0, remaining) / 1000)}s more ...`);
    if (remaining > 0) await viewer.waitForTimeout(remaining);
    const held = await ping(viewerCtx.request);
    rec(`9. still LIVE after ${Math.round((Date.now() - startedAt) / 1000)}s`, held.stream_status === 'live', `stream_status=${held.stream_status}, viewers=${held.active_viewers}`);
    const v2 = await videoState(viewer, '#livekitRemoteVideo');
    rec('10. viewer video kept playing', !!(v1 && v2 && !v2.hidden && v2.w > 0 && v2.t > v1.t + 5), `t ${v1 && v1.t.toFixed(1)} -> ${v2 && v2.t.toFixed(1)}`);
    const viewerCount = Number(await viewer.locator('#liveViewerCount').textContent().catch(() => '0'));
    rec('11. viewer count shown', viewerCount >= 1, `count=${viewerCount}`);
    const tokenRes = await viewerCtx.request.get(`${BASE}/index.php?r=api_live_token&id=${encodeURIComponent(LIVE_ID)}`);
    rec('12. guest can still get a viewer token', tokenRes.status() === 200, `HTTP ${tokenRes.status()}`);

    // ---- Host ends the stream; recording is saved ----
    const heldSeconds = (Date.now() - startedAt) / 1000;
    await Promise.all([
      host.waitForURL((u) => !u.toString().includes('studio=1'), { timeout: 120000 }).catch(() => null),
      host.click('#btnEndLive'),
    ]);
    await host.waitForLoadState('load');
    rec('13. no error dialog while ending', !dialogs.some((d) => d.startsWith('alert')), dialogs.join(' | '));
    rec('14. every recording upload accepted', uploads.length >= Math.floor(heldSeconds / 10) && uploads.every((u) => u.status === 200),
      `${uploads.length} uploads (${uploads.map((u) => u.status).join(',')}) for ${Math.round(heldSeconds)}s on air`);
    const after = await ping(hostCtx.request);
    rec('15. session is replay_ready after ending', after.stream_status === 'replay_ready', `stream_status=${after.stream_status}`);
    await host.goto(livePage(false), { waitUntil: 'load' });
    rec('17. replay player shown', (await host.locator('#replayVideoPlayer').count()) === 1);
    const recSrc = await host.locator('#replayVideoPlayer').getAttribute('src').catch(() => null);
    if (recSrc) {
      const head = await hostCtx.request.get(new URL(recSrc, BASE).toString(), { headers: { Range: 'bytes=0-1023' } });
      rec('16. recording file is served', [200, 206].includes(head.status()), `HTTP ${head.status()} ${recSrc.split('/').pop()}`);
      // MediaRecorder WebM has no duration header: seek far to make the browser compute it.
      const duration = await host.evaluate(() => new Promise((resolve) => {
        const v = document.getElementById('replayVideoPlayer');
        const done = () => resolve(Number.isFinite(v.duration) ? v.duration : -1);
        v.muted = true;
        v.addEventListener('loadedmetadata', () => {
          if (Number.isFinite(v.duration)) return done();
          v.addEventListener('durationchange', () => { if (Number.isFinite(v.duration)) done(); });
          v.currentTime = 1e9;
        }, { once: true });
        v.load();
        setTimeout(done, 20000);
      }));
      rec('16b. recording covers the stream', duration >= heldSeconds * 0.8, `duration ${duration.toFixed(1)}s vs on air ${Math.round(heldSeconds)}s`);
    } else {
      rec('16. recording file is served', false, 'no replay src');
    }
    await host.screenshot({ path: path.join(OUT, 'replay.png') });

    // Viewer should have reloaded away from LIVE.
    await waitUntil(viewer, () => document.getElementById('ssLivePage')?.dataset.liveStatus !== 'live', null, 30000)
      .then(() => rec('18. viewer page left LIVE after end', true), () => rec('18. viewer page left LIVE after end', false));

    const relevant = (list) => list.filter((e) => !/fetching the script|sw\.js|service ?worker|favicon/i.test(e));
    rec('19. no page errors (host)', relevant(hostErrors).length === 0, relevant(hostErrors).slice(0, 3).join(' | '));
    rec('20. no page errors (viewer)', relevant(viewerErrors).length === 0, relevant(viewerErrors).slice(0, 3).join(' | '));
  } catch (err) {
    rec('unexpected error', false, err.stack ? err.stack.split('\n').slice(0, 3).join(' ') : String(err));
  } finally {
    await browser.close();
  }
  const failed = results.filter((ok) => !ok).length;
  console.log(`\n${results.length - failed}/${results.length} PASS${failed ? `, ${failed} FAIL` : ''} (screenshots in ${OUT})`);
  process.exit(failed ? 1 : 0);
})();
