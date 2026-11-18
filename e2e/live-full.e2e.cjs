/**
 * E2E (full livestream lifecycle, self-cleaning): admin creates a THROWAWAY session
 * titled "E2E LIVE <timestamp>", manages its deal queue and pinned product through the
 * admin UI, goes live with a fake camera/mic, viewers / a customer comment, react and buy,
 * the host ends it, post-end rules are checked, and the session is DELETED in `finally`
 * (even on failure). Other sessions are never touched.
 *
 * Sections (a failure in one does not abort the others; a per-section summary is printed):
 *   A admin page / create / validation / search / filter / edit / access control
 *   B deal queue + pin switching (UI + AJAX) + permissions (guest/customer/staff)
 *   C viewer before live
 *   D go live (LiveKit)            E pin switch while live (polling)
 *   F comments, rate limit, tabs, reactions
 *   G buying in live (pinned + queued deal)  -- includes an intentional bug-detecting assertion
 *   H end of session + post-end rules + admin list/report
 *   Z cleanup (delete session, verify it is gone)
 *
 * Run (Playwright resolved through NODE_PATH, same as the other e2e scripts):
 *   NODE_PATH="$USERPROFILE/.cache/codex-runtimes/codex-primary-runtime/dependencies/node/node_modules" \
 *     node e2e/live-full.e2e.cjs [out_dir]
 *
 * Env: E2E_BASE_URL (default http://localhost:8080), E2E_LIVE_HOLD_MS (on-air hold, default 20000),
 *      E2E_ADMIN_EMAIL/PASSWORD, E2E_STAFF_EMAIL/PASSWORD, E2E_CUSTOMER_EMAIL/PASSWORD
 *      (environment or the git-ignored .env; never printed). Exit: 0 all pass, 1 any FAIL,
 *      2 NOT RUN (credentials missing).
 *
 * WARNING: writes to the REAL database (MongoDB Atlas). The session itself is deleted at the
 * end, but these may remain: chat messages / reactions / viewer rows of the deleted session
 * (collections phien_live_chats, phien_live_reactions, phien_live_viewers), product
 * "add_to_cart" analytics events, and the recording file uploaded while on air.
 */
const fs = require('node:fs');
const path = require('node:path');
const { chromium, request: pwRequest } = require('playwright');

const ROOT = path.resolve(__dirname, '..');
function envFromDotenv(key) {
  if (process.env[key]) return process.env[key];
  try {
    const line = fs.readFileSync(path.join(ROOT, '.env'), 'utf8').split(/\r?\n/).find((l) => l.startsWith(key + '='));
    return line ? line.slice(key.length + 1).trim().replace(/^["']|["']$/g, '') : '';
  } catch (e) { return ''; }
}
const BASE = (process.env.E2E_BASE_URL || 'http://localhost:8080').replace(/\/$/, '');
const HOLD_MS = Number(process.env.E2E_LIVE_HOLD_MS || 20000);
const OUT = process.argv[2] || path.join(ROOT, 'report', 'live-full-e2e');
const ADMIN = { email: envFromDotenv('E2E_ADMIN_EMAIL'), pw: envFromDotenv('E2E_ADMIN_PASSWORD') };
const STAFF = { email: envFromDotenv('E2E_STAFF_EMAIL'), pw: envFromDotenv('E2E_STAFF_PASSWORD') };
const CUST = { email: envFromDotenv('E2E_CUSTOMER_EMAIL'), pw: envFromDotenv('E2E_CUSTOMER_PASSWORD') };
fs.mkdirSync(OUT, { recursive: true });

const STAMP = Date.now();
const TITLE = `E2E LIVE ${STAMP}`;
const S = { id: null, title: TITLE, P: {}, liveOk: false };

// ---------------------------------------------------------------- reporting
const sections = []; // {name, pass, fail, skip}
let cur = null;
const rec = (id, desc, ok, note = '') => {
  cur[ok ? 'pass' : 'fail']++;
  console.log(`${ok ? 'PASS' : 'FAIL'} ${id} ${desc}${note ? ' - ' + note : ''}`);
  return ok;
};
const skip = (id, desc, why) => { cur.skip++; console.log(`SKIP ${id} ${desc} - ${why}`); };
const note = (text) => console.log(`NOTE ${text}`);
async function section(name, fn) {
  cur = { name, pass: 0, fail: 0, skip: 0 };
  sections.push(cur);
  console.log(`\n=== Section ${name} ===`);
  try { await fn(); } catch (err) {
    rec(`${name.split(' ')[0]}.x`, 'section aborted by an unexpected error', false, (err.stack ? err.stack.split('\n').slice(0, 3).join(' ') : String(err)).slice(0, 400));
  }
}

// ---------------------------------------------------------------- helpers
const u = (route, qs = '') => `${BASE}/index.php?r=${route}${qs ? '&' + qs : ''}`;
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));
const norm = (s) => String(s || '').replace(/\s+/g, ' ').trim();
const pad = (n) => String(n).padStart(2, '0');
const dtLocal = (d) => `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}T${pad(d.getHours())}:${pad(d.getMinutes())}`;
// "Tried to add a track for a participant" is livekit-client's own console.error race warning, not app code.
const relevantErrors = (list) => list.filter((e) => !/fetching the script|sw\.js|service ?worker|favicon|Tried to add a track for a participant/i.test(e));

async function waitUntil(page, fn, arg, timeoutMs) {
  const deadline = Date.now() + timeoutMs;
  while (Date.now() < deadline) {
    try { if (await page.evaluate(fn, arg)) return true; } catch (e) { /* context destroyed by reload */ }
    await sleep(700);
  }
  throw new Error(`waitUntil timed out after ${timeoutMs}ms`);
}
async function poll(fn, timeoutMs, everyMs = 1000) {
  const deadline = Date.now() + timeoutMs;
  let last;
  while (Date.now() < deadline) {
    try { last = await fn(); if (last) return last; } catch (e) { /* retry */ }
    await sleep(everyMs);
  }
  return false;
}
async function call(ctx, method, route, opts = {}) {
  const r = await ctx.request[method](u(route, opts.qs || ''), { maxRedirects: 0, timeout: 60000, ...(opts.form ? { form: opts.form } : {}) });
  let text = '';
  try { text = await r.text(); } catch (e) { /* ignore */ }
  let json = null;
  try { json = JSON.parse(text); } catch (e) { /* not json */ }
  return { status: r.status(), json, text, location: r.headers()['location'] || '' };
}
const ping = (ctx) => call(ctx, 'get', 'api_live_ping', { qs: `room=${encodeURIComponent(S.id)}` }).then((r) => r.json || {});
const products = (ctx) => call(ctx, 'get', 'api_live_products', { qs: `id=${encodeURIComponent(S.id)}` }).then((r) => r.json || {});
const history = (ctx, mode = 'all') => call(ctx, 'get', 'api_live_chat_history', { qs: `room_id=${encodeURIComponent(S.id)}&mode=${mode}` }).then((r) => r.json || {});
const pinAjax = (ctx, productId, qty = 5, price = 45000) =>
  call(ctx, 'post', 'admin_live_pin_product', { form: { live_id: S.id, product_id: productId, so_luong_kho_deal: String(qty), gia_uu_dai_live: String(price), ajax: '1' } });
async function login(ctx, who) {
  const r = await ctx.request.post(u('xulydangnhap'), { form: { email: who.email, mat_khau: who.pw }, timeout: 60000 });
  return r.status();
}
const errorsOf = (page, bag) => {
  page.on('pageerror', (e) => bag.push('pageerror: ' + e.message));
  page.on('console', (m) => { if (m.type() === 'error') bag.push('console: ' + m.text()); });
};
async function shot(page, name) { try { await page.screenshot({ path: path.join(OUT, name + '.png') }); } catch (e) { /* ignore */ } }

// Parse the admin list HTML (fetched with a request: much cheaper than driving the 5 MB page).
async function adminSnapshot(util, ctx) {
  const html = (await call(ctx, 'get', 'admin_lives')).text;
  return util.evaluate(({ html, id, title }) => {
    const d = new DOMParser().parseFromString(html, 'text/html');
    const rows = [...d.querySelectorAll('tr.live-table-row')];
    const idOf = (r) => (r.querySelector('code')?.textContent || '').replace('#', '').trim();
    const row = rows.find((r) => (id ? idOf(r) === String(id) : (r.textContent || '').includes(title)));
    const e2eRows = rows.filter((r) => (r.textContent || '').includes('E2E LIVE')).map(idOf);
    if (!row) return { found: false, count: rows.length, e2eRows, titleInPage: html.includes(title) };
    const rid = idOf(row);
    const modal = d.getElementById('dealsModal_' + rid);
    const queue = modal ? [...modal.querySelectorAll('tbody tr')].map((tr) => ({
      product: tr.querySelector('input[name=product_id]')?.value || '',
      dealId: tr.querySelector('input[name=deal_id]')?.value || '',
    })) : [];
    const pinnedText = row.children[4]?.textContent || '';
    const m = pinnedText.match(/Mã SP:\s*(\S+)/);
    return {
      found: true, count: rows.length, e2eRows, id: rid, status: row.getAttribute('data-status'),
      title: (row.children[1]?.querySelector('strong')?.textContent || '').trim(), streamer: (row.children[2]?.textContent || '').trim(),
      pinned: m ? m[1] : '', badge: (row.querySelector('.badge')?.textContent || '').trim(),
      note: (d.querySelector(`#editLiveModal_${rid} textarea[name=tom_tat_phien_live]`)?.value || ''),
      queue,
    };
  }, { html, id: S.id, title: TITLE });
}

(async () => {
  if (!ADMIN.email || !ADMIN.pw || !CUST.email || !CUST.pw) {
    console.log('E2E NOT RUN: thieu tai khoan (E2E_ADMIN_* va E2E_CUSTOMER_* la bat buoc; E2E_STAFF_* tuy chon)');
    process.exit(2);
  }
  const browser = await chromium.launch({
    channel: process.env.E2E_CHANNEL || 'chrome',
    headless: true,
    args: ['--use-fake-device-for-media-stream', '--use-fake-ui-for-media-stream', '--autoplay-policy=no-user-gesture-required'],
  });
  const errs = { admin: [], host: [], viewer: [], customer: [] };
  const dialogs = [];
  const acceptDialogs = (page, tag) => page.on('dialog', async (d) => {
    dialogs.push(`${tag} ${d.type()}: ${d.message()}`);
    if (d.type() === 'confirm') await d.accept(); else await d.dismiss();
  });

  let adminCtx, util, adminPage, guestCtx, probeCtx, custCtx, hostPage, viewer, custPage, staffPage;
  try {
    adminCtx = await browser.newContext({ permissions: ['camera', 'microphone'], viewport: { width: 1366, height: 900 } });
    const loginStatus = await login(adminCtx, ADMIN);
    util = await (await browser.newContext()).newPage();
    await util.goto('about:blank');
    adminPage = await adminCtx.newPage();
    adminPage.setDefaultTimeout(60000);
    errorsOf(adminPage, errs.admin);
    acceptDialogs(adminPage, 'admin');
    probeCtx = await browser.newContext(); // anonymous guest used for read-only probes
    guestCtx = await browser.newContext({ viewport: { width: 1280, height: 860 } }); // the viewer
    custCtx = await browser.newContext({ viewport: { width: 1280, height: 860 } });
    const custLogin = await login(custCtx, CUST);

    // ================================================================= A
    await section('A admin page', async () => {
      rec('A0', 'admin and customer login requests accepted', loginStatus < 400 && custLogin < 400, `admin HTTP ${loginStatus}, customer HTTP ${custLogin}`);
      const resp = await adminPage.goto(u('admin_lives'), { waitUntil: 'load', timeout: 120000 });
      const title = await adminPage.title();
      const h1 = norm(await adminPage.locator('h1:has-text("Quản lý phiên phát")').first().textContent().catch(() => ''));
      rec('A1', 'admin opens r=admin_lives (200, "Quản lý phiên phát")', resp.status() === 200 && /Quản lý phiên phát/.test(title + ' ' + h1), `HTTP ${resp.status()} title="${title}"`);

      // pick 4 distinct, real products from the create-form select
      const opts = await adminPage.evaluate(() => [...document.querySelectorAll('#adminLiveProductSelect option')]
        .filter((o) => o.value).map((o) => ({ id: o.value, name: (o.getAttribute('data-name') || '').trim(), price: parseFloat(o.getAttribute('data-price') || '0'), stock: o.getAttribute('data-stock') })));
      const good = opts.filter((o) => o.name && o.price >= 10000 && /^[\w.-]+$/.test(o.id) && (o.stock === '' || Number(o.stock) >= 5));
      const uniq = []; const seenName = new Set();
      for (const o of good) { if (!seenName.has(o.name)) { seenName.add(o.name); uniq.push(o); } if (uniq.length === 4) break; }
      rec('A2', 'found 4 distinct real products in the create form', uniq.length === 4, `${opts.length} options, ${good.length} usable`);
      if (uniq.length < 4) throw new Error('not enough products');
      [S.P.A, S.P.B, S.P.C, S.P.D] = uniq;

      const before = await adminSnapshot(util, adminCtx);
      note(`sessions before: ${before.count}; leftover "E2E LIVE" rows from earlier runs: ${before.e2eRows.length} (never touched)`);

      await adminPage.click('[data-bs-target="#adminCreateLiveModal"]');
      await adminPage.waitForSelector('#adminCreateLiveModal.show', { state: 'visible' });
      rec('A3', '"Tạo phiên phát mới" modal opens', true);

      // validation: required title empty -> browser blocks the submit
      await adminPage.fill('#adminCreateLiveModal input[name=streamer]', '');
      const invalid = await adminPage.evaluate(() => !document.querySelector('#adminCreateLiveModal form').checkValidity());
      await adminPage.click('#adminCreateLiveModal button[type=submit]');
      await sleep(1500);
      const stillThere = (await adminPage.locator('#adminCreateLiveModal.show').count()) === 1 && adminPage.url().includes('r=admin_lives');
      rec('A4', 'submitting with required fields empty is blocked (form invalid, modal stays)', invalid && stillThere);
      const srvBlank = await call(adminCtx, 'post', 'admin_live_create', { form: { tieu_de: '', streamer: '' } });
      const afterBlank = await adminSnapshot(util, adminCtx);
      rec('A5', 'server-side: POST with empty title/streamer creates nothing', afterBlank.count === before.count, `${before.count} -> ${afterBlank.count} (HTTP ${srvBlank.status})`);

      // fill via the UI
      const now = new Date();
      await adminPage.fill('#adminCreateLiveModal input[name=tieu_de]', TITLE);
      await adminPage.fill('#adminCreateLiveModal input[name=streamer]', 'E2E Host');
      await adminPage.fill('#adminCreateLiveModal input[name=khung_gio_bat_dau]', dtLocal(now));
      await adminPage.fill('#adminCreateLiveModal input[name=khung_gio_ket_thuc]', dtLocal(new Date(now.getTime() + 3 * 3600 * 1000)));
      await adminPage.fill('#adminProductFilterInput', S.P.A.id);
      const visible = await adminPage.evaluate((pid) => [...document.querySelectorAll('#adminLiveProductSelect option')].filter((o) => o.value && o.style.display !== 'none').map((o) => o.value).includes(pid), S.P.A.id);
      rec('A6', 'product search filter keeps product A visible', visible);
      await adminPage.selectOption('#adminLiveProductSelect', S.P.A.id);
      const infoName = norm(await adminPage.locator('#adminSelectedProductNameText').textContent());
      rec('A7', 'selecting product A shows its info badge', infoName.includes(S.P.A.name.slice(0, 20)), infoName.slice(0, 40));
      await adminPage.fill('#adminLivePriceInput', '49000');
      await adminPage.fill('#adminLiveStockDealInput', '3');
      await adminPage.fill('#adminCreateLiveModal textarea[name=tom_tat_phien_live]', 'E2E throwaway session');
      await shot(adminPage, 'A-create-modal');
      await Promise.all([adminPage.waitForNavigation({ waitUntil: 'load', timeout: 120000 }), adminPage.click('#adminCreateLiveModal button[type=submit]')]);
      const flash = norm(await adminPage.locator('.alert-success').first().textContent().catch(() => ''));
      rec('A8', 'create submitted; success flash shown', /tạo phiên LiveStream mới thành công/i.test(flash), flash.slice(0, 60));

      const created = await adminSnapshot(util, adminCtx);
      rec('A9', 'new row appears in the list', created.found && created.title === TITLE, `rows ${before.count} -> ${created.count}`);
      if (!created.found) throw new Error('created session not found in list');
      S.id = created.id;
      note(`throwaway session id=${S.id} title="${TITLE}"`);
      rec('A10', 'new row: status scheduled, pinned product = A', created.status === 'scheduled' && created.pinned === S.P.A.id, `status=${created.status} pinned=${created.pinned}`);

      // search + filter in the real page
      await adminPage.goto(u('admin_lives'), { waitUntil: 'load', timeout: 120000 });
      const visibleIds = () => adminPage.evaluate(() => [...document.querySelectorAll('tr.live-table-row')].filter((r) => r.style.display !== 'none').map((r) => (r.querySelector('code')?.textContent || '').replace('#', '').trim()));
      await adminPage.fill('#liveSearchInput', TITLE);
      await adminPage.dispatchEvent('#liveSearchInput', 'keyup');
      const bySearch = await visibleIds();
      rec('A11', 'search box filters the list to the new row', bySearch.length === 1 && bySearch[0] === S.id, JSON.stringify(bySearch));
      await adminPage.selectOption('#liveStatusFilter', 'scheduled');
      const bySched = await visibleIds();
      rec('A12', 'status filter "scheduled" keeps the new row', bySched.length === 1 && bySched[0] === S.id, JSON.stringify(bySched));
      await adminPage.selectOption('#liveStatusFilter', 'live');
      const byLive = await visibleIds();
      rec('A13', 'status filter "live" hides it', byLive.length === 0, JSON.stringify(byLive));
      await adminPage.selectOption('#liveStatusFilter', 'all');
      await adminPage.fill('#liveSearchInput', '');
      await adminPage.dispatchEvent('#liveSearchInput', 'keyup');
      rec('A14', 'clearing the filters shows all rows again', (await visibleIds()).length === created.count);

      // edit via editLiveModal
      const newTitle = `${TITLE} (edited)`;
      await adminPage.click(`[data-bs-target="#editLiveModal_${S.id}"]`);
      await adminPage.waitForSelector(`#editLiveModal_${S.id}.show`, { state: 'visible' });
      await adminPage.fill(`#editLiveModal_${S.id} input[name=tieu_de]`, newTitle);
      await adminPage.fill(`#editLiveModal_${S.id} textarea[name=tom_tat_phien_live]`, 'E2E edited note');
      await Promise.all([adminPage.waitForNavigation({ waitUntil: 'load', timeout: 120000 }), adminPage.click(`#editLiveModal_${S.id} button[type=submit]`)]);
      const edited = await adminSnapshot(util, adminCtx);
      rec('A15', 'edit via editLiveModal: new title + note persisted', edited.found && edited.title === newTitle && edited.note === 'E2E edited note', `title="${edited.title}" note="${edited.note}"`);
      rec('A16', 'edit did not change pinned product / status', edited.pinned === S.P.A.id && edited.status === 'scheduled', `pinned=${edited.pinned} status=${edited.status}`);
      S.title = newTitle;

      // access control
      const guestList = await call(probeCtx, 'get', 'admin_lives');
      rec('A17', 'guest GET r=admin_lives is refused (redirect to login)', guestList.status !== 200 && !guestList.text.includes('live-table-row'), `HTTP ${guestList.status} -> ${guestList.location.replace(BASE, '')}`);
      const custList = await call(custCtx, 'get', 'admin_lives');
      rec('A18', 'customer GET r=admin_lives is refused', custList.status !== 200 && !custList.text.includes('live-table-row'), `HTTP ${custList.status}`);
      const gDel = await call(probeCtx, 'get', 'admin_live_delete', { qs: `id=${S.id}` });
      const cDel = await call(custCtx, 'get', 'admin_live_delete', { qs: `id=${S.id}` });
      const stillExists = await adminSnapshot(util, adminCtx);
      rec('A19', 'guest/customer cannot delete the session (still exists)', stillExists.found, `guest HTTP ${gDel.status}, customer HTTP ${cDel.status}`);
    });

    if (!S.id) {
      console.log('\nFATAL: session was not created; cannot continue with sections B-H');
    } else {
      // ================================================================= B
      await section('B queue + pin', async () => {
        const openDeals = async () => {
          await adminPage.goto(u('admin_lives'), { waitUntil: 'load', timeout: 120000 });
          await adminPage.click(`[data-bs-target="#dealsModal_${S.id}"]`);
          await adminPage.waitForSelector(`#dealsModal_${S.id}.show`, { state: 'visible' });
        };
        const addDealUi = async (P, qty) => {
          await openDeals();
          const f = `#dealsModal_${S.id} form[action*="admin_live_add_deal"]`;
          await adminPage.selectOption(`${f} select[name=product_id]`, P.id);
          await adminPage.fill(`${f} input[name=gia_uu_dai_live]`, '30000');
          await adminPage.fill(`${f} input[name=so_luong_kho_deal]`, qty);
          return { f, submit: () => Promise.all([adminPage.waitForNavigation({ waitUntil: 'load', timeout: 120000 }), adminPage.click(`${f} button[type=submit]`)]) };
        };
        for (const k of ['B', 'C', 'D']) {
          const { submit } = await addDealUi(S.P[k], '2');
          await submit();
        }
        let snap = await adminSnapshot(util, adminCtx);
        rec('B1', 'add deals B, C, D through the modal -> queue has 3 items in order', snap.queue.map((q) => q.product).join(',') === [S.P.B.id, S.P.C.id, S.P.D.id].join(','), snap.queue.map((q) => q.product).join(','));
        rec('B2', 'deal ids are unique', new Set(snap.queue.map((q) => q.dealId)).size === 3 && snap.queue.every((q) => q.dealId));
        const q0 = snap.queue.length; // relative baseline: isolates the refusal checks below from the queue-display check B1
        rec('B3', 'adding deals did not change the pinned product (still A)', snap.pinned === S.P.A.id, snap.pinned);

        // qty 0 (min=1 blocks the browser submit), then empty qty on the same page load (server refuses)
        const zeroQty = await addDealUi(S.P.B, '0');
        await adminPage.click(`${zeroQty.f} button[type=submit]`);
        await sleep(1200);
        const blocked = await adminPage.evaluate((f) => !document.querySelector(f).checkValidity(), zeroQty.f);
        rec('B5a', 'quantity 0 is blocked by the form (invalid)', blocked);
        await adminPage.fill(`${zeroQty.f} input[name=so_luong_kho_deal]`, '');
        await zeroQty.submit();
        snap = await adminSnapshot(util, adminCtx);
        rec('B4', 'empty quantity is refused (no new item)', snap.queue.length === q0, `queue=${snap.queue.length} (baseline ${q0})`);
        const srvZero = await call(adminCtx, 'post', 'admin_live_add_deal', { form: { live_id: S.id, product_id: S.P.B.id, gia_uu_dai_live: '30000', so_luong_kho_deal: '0' } });
        const srvNeg = await call(adminCtx, 'post', 'admin_live_add_deal', { form: { live_id: S.id, product_id: S.P.B.id, gia_uu_dai_live: '30000', so_luong_kho_deal: '-4' } });
        snap = await adminSnapshot(util, adminCtx);
        rec('B5b', 'server-side: quantity 0 / -4 are refused (queue unchanged)', snap.queue.length === q0, `HTTP ${srvZero.status}/${srvNeg.status}, queue=${snap.queue.length} (baseline ${q0})`);

        // pin C from the queue (UI)
        const pinFromQueue = async (P) => {
          await openDeals();
          const row = adminPage.locator(`#dealsModal_${S.id} tbody tr`).filter({ has: adminPage.locator(`input[name=product_id][value="${P.id}"]`) });
          if ((await row.count()) === 0) {
            // queue is not rendered (see B1): fall back to the same POST the pin form sends so the later steps still run
            const fb = await call(adminCtx, 'post', 'admin_live_pin_product', { form: { live_id: S.id, product_id: P.id, so_luong_kho_deal: '2', gia_uu_dai_live: '30000', redirect: '1' } });
            note(`pin form for ${P.id} not found in the deals modal; used direct POST fallback (HTTP ${fb.status})`);
            return false;
          }
          await Promise.all([adminPage.waitForNavigation({ waitUntil: 'load', timeout: 120000 }), row.locator('button:has-text("Giới thiệu ngay")').click({ timeout: 15000 })]);
          return true;
        };
        const viaUiC = await pinFromQueue(S.P.C);
        let pj = await products(probeCtx);
        rec('B6', 'pin C from the queue (pin form in the deals modal) -> api_live_products pinned_product_id == C', viaUiC && pj.ok && pj.data.pinned_product_id === S.P.C.id && pj.data.pinned_product?.ma_san_pham === S.P.C.id, JSON.stringify({ id: pj.data?.pinned_product_id }));
        snap = await adminSnapshot(util, adminCtx);
        rec('B7', 'exactly one pinned product in the admin list (C), queue still 3', snap.pinned === S.P.C.id && snap.queue.length === 3, `pinned=${snap.pinned} queue=${snap.queue.length}`);
        const viaUiD = await pinFromQueue(S.P.D);
        pj = await products(probeCtx);
        snap = await adminSnapshot(util, adminCtx);
        rec('B8', 'pin D -> pinned becomes D, C is no longer pinned', viaUiD && pj.data?.pinned_product_id === S.P.D.id && pj.data?.pinned_product_id !== S.P.C.id && snap.pinned === S.P.D.id, `pinned=${pj.data?.pinned_product_id}`);
        rec('B9', 'queue still has 3 deals after switching the pin', snap.queue.length === 3, `queue=${snap.queue.length}`);

        // delete deal B
        await openDeals();
        const rowB = adminPage.locator(`#dealsModal_${S.id} tbody tr`).filter({ has: adminPage.locator(`input[name=product_id][value="${S.P.B.id}"]`) });
        if ((await rowB.count()) > 0) await Promise.all([adminPage.waitForNavigation({ waitUntil: 'load', timeout: 120000 }), rowB.locator('button[title="Xóa deal"]').click({ timeout: 15000 })]);
        snap = await adminSnapshot(util, adminCtx);
        rec('B10', 'delete deal B -> queue has 2 (C, D)', snap.queue.map((q) => q.product).join(',') === [S.P.C.id, S.P.D.id].join(','), snap.queue.map((q) => q.product).join(','));
        rec('B11', 'deleting a deal did not change the pin (D)', snap.pinned === S.P.D.id, snap.pinned);
        await shot(adminPage, 'B-after-queue');

        // AJAX pin
        const ajaxOk = await pinAjax(adminCtx, S.P.C.id, 4, 41000);
        pj = await products(probeCtx);
        rec('B12', 'AJAX pin (ajax=1) returns ok:true and applies', ajaxOk.json?.ok === true && pj.data?.pinned_product_id === S.P.C.id, JSON.stringify(ajaxOk.json || ajaxOk.text.slice(0, 80)));
        const ajaxBad = await pinAjax(adminCtx, S.P.D.id, 0);
        pj = await products(probeCtx);
        rec('B13', 'AJAX pin with so_luong_kho_deal=0 -> ok:false and pin unchanged (C)', ajaxBad.json?.ok === false && pj.data?.pinned_product_id === S.P.C.id, JSON.stringify(ajaxBad.json));
        const ajaxEmpty = await pinAjax(adminCtx, '', 3);
        rec('B14', 'AJAX pin with empty product -> ok:false', ajaxEmpty.json?.ok === false);
        const ajaxMissing = await call(adminCtx, 'post', 'admin_live_pin_product', { form: { live_id: '99999999', product_id: S.P.D.id, so_luong_kho_deal: '3', ajax: '1' } });
        rec('B15', 'AJAX pin on a non-existent session does not report success', ajaxMissing.json?.ok !== true, JSON.stringify(ajaxMissing.json));

        // permissions
        const gPin = await pinAjax(probeCtx, S.P.D.id, 3);
        const cPin = await pinAjax(custCtx, S.P.D.id, 3);
        const gDeal = await call(probeCtx, 'post', 'admin_live_add_deal', { form: { live_id: S.id, product_id: S.P.B.id, gia_uu_dai_live: '30000', so_luong_kho_deal: '2' } });
        const cDeal = await call(custCtx, 'post', 'admin_live_add_deal', { form: { live_id: S.id, product_id: S.P.B.id, gia_uu_dai_live: '30000', so_luong_kho_deal: '2' } });
        const gDelDeal = await call(probeCtx, 'post', 'admin_live_delete_deal', { form: { live_id: S.id, deal_id: (snap.queue[0] || { dealId: 'deal_none' }).dealId } });
        const cDelDeal = await call(custCtx, 'post', 'admin_live_delete_deal', { form: { live_id: S.id, deal_id: (snap.queue[0] || { dealId: 'deal_none' }).dealId } });
        pj = await products(probeCtx);
        snap = await adminSnapshot(util, adminCtx);
        rec('B16', 'guest/customer pin attempts are refused (ok:false)', gPin.json?.ok === false && cPin.json?.ok === false, `${JSON.stringify(gPin.json)} | ${JSON.stringify(cPin.json)}`);
        rec('B17', 'guest/customer add/delete deal attempts do not report success', gDeal.json?.ok !== true && cDeal.json?.ok !== true && gDelDeal.json?.ok !== true && cDelDeal.json?.ok !== true, `HTTP ${gDeal.status}/${cDeal.status}/${gDelDeal.status}/${cDelDeal.status}`);
        rec('B18', 'state unchanged after unauthorized attempts (pinned C, queue C+D)', pj.data?.pinned_product_id === S.P.C.id && snap.queue.map((q) => q.product).join(',') === [S.P.C.id, S.P.D.id].join(','), `pinned=${pj.data?.pinned_product_id} queue=${snap.queue.map((q) => q.product)}`);

        // staff
        if (STAFF.email && STAFF.pw) {
          const staffCtx = await browser.newContext();
          const st = await login(staffCtx, STAFF);
          const sl = await call(staffCtx, 'get', 'admin_lives');
          const sp = await pinAjax(staffCtx, S.P.C.id, 4, 41000);
          pj = await products(probeCtx);
          rec('B19', 'staff (nhanvien) can open the list and pin', st < 400 && sl.status === 200 && sp.json?.ok === true && pj.data?.pinned_product_id === S.P.C.id, `login HTTP ${st}, list HTTP ${sl.status}, pin ${JSON.stringify(sp.json?.ok)}`);
          await staffCtx.close();
        } else {
          note('B19 skipped: E2E_STAFF_* credentials missing');
        }
        // leave D pinned for the viewer sections
        const back = await pinAjax(adminCtx, S.P.D.id, 4, 41000);
        pj = await products(probeCtx);
        rec('B20', 'AJAX pin D again -> pinned D (state for the viewer sections)', back.json?.ok === true && pj.data?.pinned_product_id === S.P.D.id);
      });

      // ================================================================= C
      await section('C viewer before live', async () => {
        viewer = await guestCtx.newPage();
        viewer.setDefaultTimeout(60000);
        errorsOf(viewer, errs.viewer);
        acceptDialogs(viewer, 'viewer');
        await viewer.goto(u('live', `id=${encodeURIComponent(S.id)}`), { waitUntil: 'load', timeout: 90000 });
        const st = await viewer.evaluate(() => document.getElementById('ssLivePage')?.dataset.liveStatus);
        const liveId = await viewer.evaluate(() => document.getElementById('ssLivePage')?.dataset.liveId);
        rec('C1', 'guest opens the session page; it is not live yet', liveId === S.id && st !== 'live', `id=${liveId} status=${st}`);
        const pinnedName = norm(await viewer.locator('#livePinnedName').textContent().catch(() => ''));
        rec('C2', 'pinned card shows product D', pinnedName.includes(S.P.D.name.slice(0, 25)), pinnedName.slice(0, 50));
        const deals = await viewer.evaluate(() => [...document.querySelectorAll('.ss-deal')].map((d) => ({ name: (d.querySelector('strong')?.textContent || '').trim(), disabled: !!d.querySelector('button')?.disabled })));
        const dn = deals.map((d) => d.name).join('|');
        rec('C3', 'deal list shows the 2 queued items (C, D)', deals.length === 2 && dn.includes(S.P.C.name.slice(0, 20)) && dn.includes(S.P.D.name.slice(0, 20)), dn.slice(0, 80));
        const buyDisabled = await viewer.evaluate(() => !!document.querySelector('#liveBuyNowForm button')?.disabled);
        rec('C4', 'pinned "Thêm vào giỏ" button is disabled before live', buyDisabled);
        rec('C4b', 'every queued-deal "+ Giỏ" button is disabled before live (and there are some)', deals.length > 0 && deals.every((d) => d.disabled), `${deals.length} deal buttons`);
        const chatLocked = (await viewer.locator('#liveChatInput').count()) === 0;
        rec('C5', 'chat input is locked before live', chatLocked);
        const probe = await browser.newContext();
        const cart = await call(probe, 'post', 'api_live_add_to_cart', { form: { live_id: S.id, product_id: S.P.D.id } });
        rec('C6', 'POST api_live_add_to_cart while not live -> ok:false', cart.json?.ok === false, JSON.stringify(cart.json));
        const chat = await call(probe, 'post', 'api_live_chat', { form: { room_id: S.id, message: 'E2E before live' } });
        rec('C7', 'POST api_live_chat while not live is refused', chat.json?.ok === false, JSON.stringify(chat.json));
        const react = await call(probe, 'post', 'api_live_reaction', { form: { live_id: S.id } });
        rec('C8', 'reaction while not live is refused', react.json?.ok === false || react.status === 409, `HTTP ${react.status}`);
        await probe.close();
        await shot(viewer, 'C-viewer-before-live');
      });

      // ================================================================= D
      await section('D go live', async () => {
        const hostCtx = adminCtx;
        hostPage = await hostCtx.newPage();
        hostPage.setDefaultTimeout(60000);
        errorsOf(hostPage, errs.host);
        acceptDialogs(hostPage, 'host');
        const uploads = [];
        hostPage.on('response', (r) => { if (r.url().includes('r=api_upload_live_recording')) uploads.push(r.status()); });
        await hostPage.goto(u('admin_live_status', `id=${encodeURIComponent(S.id)}&status=danglive&next=live`), { waitUntil: 'load', timeout: 90000 });
        rec('D1', 'admin_live_status danglive&next=live lands on the studio', hostPage.url().includes('studio=1') && hostPage.url().includes('r=live'), hostPage.url().replace(BASE, ''));
        rec('D2', 'studio shows camera/mic buttons', (await hostPage.locator('#btnToggleCam').count()) === 1 && (await hostPage.locator('#btnToggleMic').count()) === 1);
        const connected = await hostPage.waitForFunction(() => /Có thể bắt đầu phát|Đã kết nối/.test(document.getElementById('liveConnectionBadge')?.textContent || ''), null, { timeout: 30000 }).then(() => true, () => false);
        rec('D3', 'host connected to LiveKit (INFRA)', connected, connected ? '' : 'LiveKit unreachable -> D-H live-dependent steps are infra failures, not app failures');
        if (!connected) { S.liveOk = false; await shot(hostPage, 'D-no-livekit'); return; }
        const liveResp = hostPage.waitForResponse((r) => r.url().includes('r=api_live_stream_state') && r.request().postData()?.includes('live'), { timeout: 45000 });
        await hostPage.click('#btnToggleCam');
        await hostPage.waitForFunction(() => { const v = document.getElementById('livekitLocalVideo'); return v && !v.hidden && v.videoWidth > 0; }, null, { timeout: 20000 }).catch(() => null);
        await hostPage.click('#btnToggleMic');
        await hostPage.waitForFunction(() => { const b = document.getElementById('btnGoLive'); return b && !b.disabled; }, null, { timeout: 20000 }).catch(() => null);
        await hostPage.click('#btnGoLive');
        const lr = await liveResp.catch(() => null);
        const lj = lr ? await lr.json().catch(() => ({})) : {};
        S.liveOk = !!lj.ok && lj.state === 'live';
        rec('D4', 'host clicks Bắt đầu phát; server accepts state=live', S.liveOk, JSON.stringify(lj));
        if (!S.liveOk) { await shot(hostPage, 'D-golive-failed'); return; }
        const startedAt = Date.now();
        const pg = await ping(probeCtx);
        rec('D5', 'api_live_ping reports the session as live', pg.stream_status === 'live', `stream_status=${pg.stream_status}`);
        await waitUntil(viewer, () => document.getElementById('ssLivePage')?.dataset.liveStatus === 'live', null, 40000)
          .then(() => rec('D6', 'guest viewer page auto-switches to LIVE', true), () => rec('D6', 'guest viewer page auto-switches to LIVE', false));
        await waitUntil(viewer, () => { const v = document.getElementById('livekitRemoteVideo'); return v && !v.hidden && v.videoWidth > 0; }, null, 45000)
          .then(() => rec('D7', 'viewer receives remote video (videoWidth>0)', true), () => rec('D7', 'viewer receives remote video (videoWidth>0)', false, 'INFRA? LiveKit media not delivered'));
        const cnt = await poll(async () => Number(await viewer.locator('#liveViewerCount').textContent().catch(() => '0')) >= 1, 30000, 1500);
        rec('D8', 'viewer count >= 1', !!cnt, `count=${await viewer.locator('#liveViewerCount').textContent().catch(() => '?')}`);
        const studioChatForm = (await hostPage.locator('#liveChatInput').isVisible().catch(() => false)) ? 1 : 0;
        if (studioChatForm) {
          const hostMsg = `E2E studio reply ${Date.now()}`;
          const sent = hostPage.waitForResponse((r) => r.url().includes('r=api_live_chat') && !r.url().includes('history') && r.request().method() === 'POST', { timeout: 20000 }).catch(() => null);
          await hostPage.fill('#liveChatInput', hostMsg);
          await hostPage.press('#liveChatInput', 'Enter');
          const sr = await sent; const sj = sr ? await sr.json().catch(() => ({})) : {};
          rec('D9b', 'host sends a chat from the studio without reloading -> ok', sj.ok === true, sr ? `HTTP ${sr.status()} ${sj.message || ''}` : 'no request');
          const seen = await poll(async () => (await viewer.locator('#liveChatBox').textContent().catch(() => '')).includes(hostMsg), 25000, 1000);
          rec('D9c', 'studio message appears on the viewer page', !!seen);
        }
        rec('D9', 'studio chat input is available right after going live (host can reply without reloading)', studioChatForm === 1, studioChatForm ? '' : 'BUG-DETECT: canChat is computed at render time, the studio loaded pre-live has no chat form until a reload (which would drop the broadcast)');
        await shot(viewer, 'D-viewer-live');
        await shot(hostPage, 'D-host-live');
        S.uploads = uploads; S.startedAt = startedAt;
      });

      // ================================================================= E
      await section('E live pin switch', async () => {
        if (!S.liveOk) { skip('E1', 'pin switch while live', 'session is not live (see section D)'); return; }
        const t0 = Date.now();
        const r = await pinAjax(adminCtx, S.P.C.id, 4, 41000);
        rec('E1', 'admin pins C via AJAX while live', r.json?.ok === true, JSON.stringify(r.json));
        const ok = await waitUntil(viewer, (n) => (document.getElementById('livePinnedName')?.textContent || '').replace(/\s+/g, ' ').trim().includes(n), S.P.C.name.slice(0, 25), 40000).then(() => true, () => false);
        rec('E2', 'viewer #livePinnedName switches to C without a manual reload', ok, `${Math.round((Date.now() - t0) / 1000)}s`);
        const pid = await viewer.evaluate(() => document.getElementById('livePinnedProductId')?.value);
        rec('E3', 'viewer buy form product id follows the pin (C)', pid === S.P.C.id, `value=${pid}`);
        const pg = await ping(probeCtx);
        rec('E4', 'api_live_ping pinned_product_id == C and the session is still live', pg.pinned_product_id === S.P.C.id && pg.stream_status === 'live', JSON.stringify({ p: pg.pinned_product_id, s: pg.stream_status }));
        await shot(viewer, 'E-pin-switched');
      });

      // ================================================================= F
      await section('F comments', async () => {
        if (!S.liveOk) { skip('F1', 'comments', 'session is not live (see section D)'); return; }
        const chatPost = (page) => page.waitForResponse((r) => r.url().includes('r=api_live_chat') && !r.url().includes('history') && r.request().method() === 'POST', { timeout: 30000 }).catch(() => null);
        // guest sends a question
        await viewer.reload({ waitUntil: 'load' });
        const guestMsg = `E2E guest question ${STAMP}?`;
        let p = chatPost(viewer);
        await viewer.fill('#guestNickname', 'E2EGuest');
        await viewer.fill('#liveChatInput', guestMsg);
        await viewer.press('#liveChatInput', 'Enter');
        let resp = await p; let j = resp ? await resp.json().catch(() => ({})) : {};
        rec('F1', 'guest (with nickname) sends a message -> api_live_chat ok', !!j.ok, resp ? `HTTP ${resp.status()}` : 'no request');
        await waitUntil(hostPage, (t) => (document.getElementById('liveChatBox')?.textContent || '').includes(t), guestMsg, 25000)
          .then(() => rec('F2', 'guest message appears on the host chat box', true), () => rec('F2', 'guest message appears on the host chat box', false));
        let h = await history(probeCtx);
        const gItem = (h.data || []).find((m) => m.message === guestMsg);
        rec('F3', 'guest message is in api_live_chat_history with the nickname', !!gItem && gItem.sender_name === 'E2EGuest' && gItem.sender_type === 'guest', gItem ? `${gItem.sender_name}/${gItem.sender_type}` : 'missing');

        // empty message
        let sent = 0;
        const counter = (r) => { if (r.url().includes('r=api_live_chat') && !r.url().includes('history') && r.method() === 'POST') sent++; };
        viewer.on('request', counter);
        await viewer.fill('#liveChatInput', '');
        await viewer.click('#liveChatForm button[type=submit]');
        await sleep(1500);
        viewer.off('request', counter);
        const emptyApi = await call(probeCtx, 'post', 'api_live_chat', { form: { room_id: S.id, message: '   ' } });
        rec('F4', 'empty message is not sent (UI sends no request; API says ok:false)', sent === 0 && emptyApi.json?.ok === false, `requests=${sent} api=${JSON.stringify(emptyApi.json)}`);

        // customer (separate context) -> appears on guest viewer
        custPage = await custCtx.newPage();
        custPage.setDefaultTimeout(60000);
        errorsOf(custPage, errs.customer);
        acceptDialogs(custPage, 'customer');
        await custPage.goto(u('live', `id=${encodeURIComponent(S.id)}`), { waitUntil: 'load', timeout: 90000 });
        const custMsg = `E2E customer hello ${STAMP}`;
        p = chatPost(custPage);
        await custPage.fill('#liveChatInput', custMsg);
        await custPage.press('#liveChatInput', 'Enter');
        resp = await p; j = resp ? await resp.json().catch(() => ({})) : {};
        rec('F5', 'logged-in customer sends a message -> ok', !!j.ok, resp ? `HTTP ${resp.status()}` : 'no request');
        await waitUntil(viewer, (t) => (document.getElementById('liveChatBox')?.textContent || '').includes(t), custMsg, 25000)
          .then(() => rec('F6', 'customer message appears on the guest viewer page', true), () => rec('F6', 'customer message appears on the guest viewer page', false));
        h = await history(probeCtx);
        const cItem = (h.data || []).find((m) => m.message === custMsg);
        rec('F7', 'customer message has sender_type customer (login is effective)', !!cItem && cItem.sender_type === 'customer', cItem ? cItem.sender_type : 'missing');

        // host sends a message (from a second admin tab: the studio tab has no chat form)
        const hostChat = await adminCtx.newPage();
        hostChat.setDefaultTimeout(60000);
        errorsOf(hostChat, errs.host);
        acceptDialogs(hostChat, 'hostchat');
        await hostChat.goto(u('live', `id=${encodeURIComponent(S.id)}`), { waitUntil: 'load', timeout: 90000 });
        const hostMsg = `E2E host says hi ${STAMP}`;
        if (await hostChat.locator('#liveChatInput').count()) {
          p = chatPost(hostChat);
          await hostChat.fill('#liveChatInput', hostMsg);
          await hostChat.press('#liveChatInput', 'Enter');
          resp = await p; j = resp ? await resp.json().catch(() => ({})) : {};
          rec('F8', 'host sends a message (staff chat tab) -> ok', !!j.ok, resp ? `HTTP ${resp.status()}` : 'no request');
          await waitUntil(viewer, (t) => (document.getElementById('liveChatBox')?.textContent || '').includes(t), hostMsg, 25000)
            .then(() => rec('F9', 'host message appears on the viewer page', true), () => rec('F9', 'host message appears on the viewer page', false));
          h = await history(probeCtx);
          const hItem = (h.data || []).find((m) => m.message === hostMsg);
          rec('F10', 'host message sender_type is streamer', !!hItem && hItem.sender_type === 'streamer', hItem ? hItem.sender_type : 'missing');
        } else {
          rec('F8', 'host chat input exists on the staff live page', false);
        }
        await hostChat.close();

        // tabs + history modes
        await viewer.reload({ waitUntil: 'load' });
        const tabResults = [];
        for (const [mode, label] of [['all', 'Tất cả'], ['featured', 'Nổi bật'], ['qa', 'Hỏi đáp']]) {
          const hp = viewer.waitForResponse((r) => r.url().includes('api_live_chat_history') && r.url().includes(`mode=${mode}`), { timeout: 15000 }).catch(() => null);
          await viewer.click(`[data-chat-mode="${mode}"]`);
          const hr = await hp; const hj = hr ? await hr.json().catch(() => ({})) : {};
          const active = await viewer.evaluate((m) => document.querySelector(`[data-chat-mode="${m}"]`)?.classList.contains('is-active'), mode);
          tabResults.push(`${label}:${active && hj.ok ? 'ok' : 'bad'}`);
        }
        rec('F11', 'chat tabs Tất cả / Nổi bật / Hỏi đáp are clickable and load history', tabResults.every((t) => t.endsWith('ok')), tabResults.join(' '));
        const qaTab = await viewer.evaluate(() => document.getElementById('liveChatBox')?.textContent || '');
        rec('F12', 'Hỏi đáp tab shows the guest question and hides non-questions', qaTab.includes(guestMsg) && !qaTab.includes(custMsg), '');
        h = await history(probeCtx, 'qa');
        const qaMsgs = (h.data || []).map((m) => m.message);
        rec('F13', 'history mode=qa returns ok and only questions (contains guest "?" message)', h.ok === true && qaMsgs.includes(guestMsg) && !qaMsgs.includes(custMsg), `${qaMsgs.length} items`);
        const feat0 = await history(probeCtx, 'featured');
        rec('F14', 'history mode=featured returns ok', feat0.ok === true && Array.isArray(feat0.data), `${(feat0.data || []).length} items`);

        // rate limit: fresh guest session, 6 quick messages
        const rateCtx = await browser.newContext();
        const codes = [];
        for (let i = 1; i <= 6; i++) {
          const r = await call(rateCtx, 'post', 'api_live_chat', { form: { room_id: S.id, message: i === 1 ? `chốt đơn E2E ${STAMP}` : `E2E rate ${i} ${STAMP}` } });
          codes.push(`${r.status}${r.json?.ok ? '+' : '-'}`);
        }
        rec('F15', 'rate limit: messages 1-5 accepted, the 6th within 20s gets HTTP 429', codes.slice(0, 5).every((c) => c === '200+') && codes[5].startsWith('429'), codes.join(' '));
        await rateCtx.close();
        const feat = await history(probeCtx, 'featured');
        rec('F16', 'the "chốt đơn" message is listed under mode=featured', (feat.data || []).some((m) => (m.message || '').startsWith('chốt đơn E2E') && m.is_order), `${(feat.data || []).length} featured items`);

        // reactions
        const before = Number(await viewer.locator('#reactionCount').textContent().catch(() => '0'));
        await viewer.click('#btnReaction');
        const bumped = await poll(async () => Number(await viewer.locator('#reactionCount').textContent()) > before, 15000, 500);
        rec('F17', 'reaction button increments #reactionCount', !!bumped, `${before} -> ${await viewer.locator('#reactionCount').textContent()}`);
        const rApi = await call(probeCtx, 'post', 'api_live_reaction', { form: { live_id: S.id } });
        rec('F18', 'POST api_live_reaction -> ok with a reaction_count', rApi.json?.ok === true && rApi.json.reaction_count >= 2, JSON.stringify(rApi.json));
        await shot(viewer, 'F-chat');
      });

      // ================================================================= G
      await section('G buying in live', async () => {
        if (!S.liveOk) { skip('G1', 'buying in live', 'session is not live (see section D)'); return; }
        if (!custPage) { custPage = await custCtx.newPage(); custPage.setDefaultTimeout(60000); errorsOf(custPage, errs.customer); acceptDialogs(custPage, 'customer'); }
        await custPage.goto(u('live', `id=${encodeURIComponent(S.id)}`), { waitUntil: 'load', timeout: 90000 });
        const pinnedNow = await custPage.evaluate(() => document.getElementById('livePinnedProductId')?.value);
        rec('G0', 'customer page shows pinned product C', pinnedNow === S.P.C.id, `value=${pinnedNow}`);
        const cartResp = (page) => page.waitForResponse((r) => r.url().includes('r=api_live_add_to_cart'), { timeout: 20000 }).catch(() => null);
        let cartPosts = 0;
        custPage.on('request', (q) => { if (q.url().includes('r=api_live_add_to_cart') && q.method() === 'POST') cartPosts++; });
        let p = cartResp(custPage);
        await custPage.click('#liveBuyNowForm button');
        let r = await p; let j = r ? await r.json().catch(() => ({})) : {};
        await custPage.waitForTimeout(2500);
        rec('G1b', 'one click on "Thêm vào giỏ" sends exactly ONE add-to-cart request (no double add)', cartPosts === 1, `requests=${cartPosts}`);
        cartPosts = 0;
        rec('G1', 'click pinned "Thêm vào giỏ" -> ok:true with product_id == pinned C', j.ok === true && j.product_id === S.P.C.id, JSON.stringify({ ok: j.ok, product_id: j.product_id, cart: j.cart_count, http: r ? r.status() : 'no response', dialogs: dialogs.slice(-2) }));
        // queued deal D (non-pinned)
        const dealForm = custPage.locator('.ss-deal').filter({ hasText: S.P.D.name.slice(0, 20) }).first();
        const hasD = (await dealForm.count()) === 1;
        rec('G2', 'queued deal for D is listed on the customer page', hasD);
        if (hasD) {
          p = cartResp(custPage);
          await dealForm.locator('button').click();
          r = await p; j = r ? await r.json().catch(() => ({})) : {};
          rec('G3', 'BUG-DETECT: click "+ Giỏ" on queued (non-pinned) deal D -> response product_id == D', j.ok === true && j.product_id === S.P.D.id,
            `got product_id=${j.product_id} (expected D)`);
          // the queued deal for the pinned product itself must still resolve to C
          const dealC = custPage.locator('.ss-deal').filter({ hasText: S.P.C.name.slice(0, 20) }).first();
          if (await dealC.count()) {
            p = cartResp(custPage);
            await dealC.locator('button').click();
            r = await p; j = r ? await r.json().catch(() => ({})) : {};
            rec('G4', 'click "+ Giỏ" on queued deal C (the pinned one) -> product_id == C', j.ok === true && j.product_id === S.P.C.id, JSON.stringify({ product_id: j.product_id }));
          }
        }
        const apiD = await call(custCtx, 'post', 'api_live_add_to_cart', { form: { live_id: S.id, product_id: S.P.D.id } });
        rec('G5', 'BUG-DETECT (API): add-to-cart for queued product D while C is pinned -> product_id == D', apiD.json?.ok === true && apiD.json.product_id === S.P.D.id,
          `got product_id=${apiD.json?.product_id} (pinned=${S.P.C.id})`);
        await shot(custPage, 'G-buy');
      });

      // ================================================================= H
      await section('H end of session', async () => {
        const ended = ['ended', 'replay_ready', 'recording_failed', 'processing'];
        if (S.liveOk) {
          const remaining = HOLD_MS - (Date.now() - S.startedAt);
          if (remaining > 0) { note(`holding on air for ${Math.round(remaining / 1000)}s more (E2E_LIVE_HOLD_MS=${HOLD_MS})`); await sleep(remaining); }
          const held = await ping(probeCtx);
          rec('H0', `session still live after ${Math.round((Date.now() - S.startedAt) / 1000)}s on air`, held.stream_status === 'live', `stream_status=${held.stream_status}`);
          await Promise.all([
            hostPage.waitForURL((x) => !x.toString().includes('studio=1'), { timeout: 120000 }).catch(() => null),
            hostPage.click('#btnEndLive'),
          ]);
          await hostPage.waitForLoadState('load').catch(() => null);
          const fin = await poll(async () => { const pg = await ping(probeCtx); return ended.includes(pg.stream_status) ? pg.stream_status : false; }, 90000, 2000);
          rec('H1', 'host ends the session; it becomes replay_ready (or ended)', ['replay_ready', 'ended'].includes(fin), `stream_status=${fin}`);
          if (S.uploads) note(`recording uploads: ${S.uploads.length} (${S.uploads.join(',')})`);
          await waitUntil(viewer, () => document.getElementById('ssLivePage')?.dataset.liveStatus !== 'live', null, 45000)
            .then(() => rec('H2', 'viewer page leaves LIVE after the end', true), () => rec('H2', 'viewer page leaves LIVE after the end', false));
        } else {
          // infra fallback: end the session server-side so the post-end rules can still be checked
          const forced = await call(adminCtx, 'get', 'admin_live_status', { qs: `id=${encodeURIComponent(S.id)}&status=ketthuc` });
          const fin = await ping(probeCtx);
          note(`LiveKit/live unavailable: ended server-side (HTTP ${forced.status}) -> stream_status=${fin.stream_status}`);
          rec('H1', 'session ended server-side (fallback)', ended.includes(fin.stream_status), `stream_status=${fin.stream_status}`);
        }
        const pAfter = await pinAjax(adminCtx, S.P.A.id, 3);
        rec('H3', 'pin after the end -> ok:false mentioning KẾT THÚC', pAfter.json?.ok === false && /KẾT THÚC/.test(pAfter.json?.message || ''), JSON.stringify(pAfter.json));
        const pj = await products(probeCtx);
        rec('H4', 'pinned product unchanged by the refused pin', pj.data?.pinned_product_id === S.P.C.id, pj.data?.pinned_product_id);
        const restart = await call(adminCtx, 'get', 'admin_live_status', { qs: `id=${encodeURIComponent(S.id)}&status=danglive` });
        const pgR = await ping(probeCtx);
        rec('H5', 'admin_live_status restart is refused (status stays ended/replay)', ended.includes(pgR.stream_status), `HTTP ${restart.status}, stream_status=${pgR.stream_status}`);
        const restart2 = await call(adminCtx, 'post', 'api_live_stream_state', { form: { live_id: S.id, state: 'live' } });
        rec('H5b', 'api_live_stream_state live on an ended session is refused (409/403, not ok)', restart2.json?.ok !== true && restart2.status >= 400, `HTTP ${restart2.status}`);
        const post = await browser.newContext();
        const cart = await call(post, 'post', 'api_live_add_to_cart', { form: { live_id: S.id, product_id: S.P.C.id } });
        rec('H6', 'add-to-cart after the end is refused', cart.json?.ok === false, JSON.stringify(cart.json));
        const chat = await call(post, 'post', 'api_live_chat', { form: { room_id: S.id, message: 'E2E after end' } });
        rec('H7', 'chat after the end is refused', chat.json?.ok === false, JSON.stringify(chat.json));
        const react = await call(post, 'post', 'api_live_reaction', { form: { live_id: S.id } });
        rec('H8', 'reaction after the end is refused', react.json?.ok === false || react.status === 409, `HTTP ${react.status}`);
        await post.close();

        const snap = await adminSnapshot(util, adminCtx);
        rec('H9', 'admin list shows the session as ended/replay with the "Kết thúc" badge', snap.found && ['ended', 'replay_ready', 'recording_failed', 'processing'].includes(snap.status) && /Kết thúc/.test(snap.badge), `status=${snap.status} badge="${snap.badge}"`);
        await adminPage.goto(u('admin_lives'), { waitUntil: 'load', timeout: 120000 });
        await adminPage.click(`[data-bs-target="#reportModal_${S.id}"]`);
        await adminPage.waitForSelector(`#reportModal_${S.id}.show`, { state: 'visible' });
        const reportTxt = norm(await adminPage.locator(`#reportModal_${S.id} .modal-title`).textContent());
        rec('H10', 'report modal opens for the session', reportTxt.includes(`#${S.id}`), reportTxt.slice(0, 60));
        await shot(adminPage, 'H-report');
      });
    }
  } catch (fatal) {
    cur = cur || { name: 'setup', pass: 0, fail: 0, skip: 0 };
    if (!sections.includes(cur)) sections.push(cur);
    rec('fatal', 'unexpected error outside a section', false, String(fatal && fatal.stack ? fatal.stack.split('\n').slice(0, 3).join(' ') : fatal).slice(0, 400));
  } finally {
    // ================================================================= Z cleanup (always)
    await section('Z cleanup', async () => {
      const api = await pwRequest.newContext({ baseURL: BASE });
      const ctx = { request: api };
      await login(ctx, ADMIN);
      let html = (await call(ctx, 'get', 'admin_lives')).text;
      // ids of every row carrying OUR unique title (normally exactly the one session)
      const mine = [];
      const rowRe = /<tr class="live-table-row"[\s\S]*?<\/tr>/g;
      for (const m of html.matchAll(rowRe)) {
        if (m[0].includes(TITLE)) { const idm = m[0].match(/<code class="fw-bold">#([^<]+)<\/code>/); if (idm) mine.push(idm[1]); }
      }
      if (S.id && !mine.includes(S.id)) mine.push(S.id);
      for (const id of mine) await call(ctx, 'get', 'admin_live_delete', { qs: `id=${encodeURIComponent(id)}` });
      html = (await call(ctx, 'get', 'admin_lives')).text;
      rec('Z1', `session(s) ${mine.join(',') || '(none created)'} deleted; title no longer in the admin list`, !html.includes(TITLE) && !mine.some((id) => html.includes(`<code class="fw-bold">#${id}</code>`)), `${mine.length} deleted`);
      if (S.id) {
        const pg = await call(ctx, 'get', 'api_live_ping', { qs: `room=${encodeURIComponent(S.id)}` });
        rec('Z2', 'api_live_ping reports the deleted session as missing', pg.json?.stream_status === 'missing', JSON.stringify({ s: pg.json?.stream_status }));
        const pr = await call(ctx, 'get', 'api_live_products', { qs: `id=${encodeURIComponent(S.id)}` });
        rec('Z3', 'api_live_products reports the deleted session as not found', pr.json?.ok === false, JSON.stringify(pr.json));
        const page = await call(ctx, 'get', 'live', { qs: `id=${encodeURIComponent(S.id)}` });
        rec('Z4', 'the live page no longer renders the deleted session id', !page.text.includes(`data-live-id="${S.id}"`), '');
      }
      const left = [...html.matchAll(/E2E LIVE (\d+)/g)].map((m) => m[1]).filter((x, i, a) => a.indexOf(x) === i);
      note(`"E2E LIVE" sessions still in the list after cleanup (other runs, untouched): ${left.length ? left.join(',') : 'none'}`);
      await api.dispose();
    });

    // page errors
    cur = { name: 'Y page errors', pass: 0, fail: 0, skip: 0 };
    sections.push(cur);
    console.log(`\n=== Section ${cur.name} ===`);
    for (const k of ['admin', 'host', 'viewer', 'customer']) {
      const bad = relevantErrors(errs[k]);
      rec(`Y.${k}`, `no page errors (${k})`, bad.length === 0, bad.slice(0, 3).join(' | ').slice(0, 300));
    }
    const alerts = dialogs.filter((d) => / alert:/.test(d));
    if (alerts.length) note(`alert dialogs seen: ${alerts.slice(0, 5).join(' || ').slice(0, 400)}`);
    try { await browser.close(); } catch (e) { /* ignore */ }
  }

  console.log('\n---- per-section summary ----');
  let totalFail = 0; let totalPass = 0; let totalSkip = 0;
  for (const s of sections) {
    totalFail += s.fail; totalPass += s.pass; totalSkip += s.skip;
    console.log(`${s.fail ? 'FAIL' : 'ok  '} ${s.name.padEnd(26)} ${s.pass} pass, ${s.fail} fail, ${s.skip} skip`);
  }
  console.log(`\n${totalPass}/${totalPass + totalFail} PASS${totalFail ? `, ${totalFail} FAIL` : ''}${totalSkip ? `, ${totalSkip} SKIP` : ''} (screenshots in ${OUT})`);
  process.exit(totalFail ? 1 : 0);
})();
