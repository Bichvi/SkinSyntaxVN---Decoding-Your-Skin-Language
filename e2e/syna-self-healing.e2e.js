// Syna self-healing e2e (manual/auto approval_mode + approval/recovery cards).
// Run: Playwright MCP `browser_run_code_unsafe` with { filename: 'e2e/syna-self-healing.e2e.js' } while logged in as the
// local seeded admin (scripts/seed_admin_staff.php) at http://localhost:8080. All create/job endpoints are route-mocked.
// Result: returns { results, shots }. Note: run needs window.confirm stubbed (done inline).
async (page) => {
  const BASE = 'http://localhost:8080/index.php';
  const OUT = 'C:/xampp/htdocs/CNM/SkinSyntaxVN---Decoding-Your-Skin-Language/report/syna-self-healing-e2e/';
  const results = []; const errors = []; const shots = [];
  const check = (name, ok, info = '') => results.push(`${ok ? 'PASS' : 'FAIL'} ${name}${info ? ' :: ' + info : ''}`);
  page.on('console', (m) => { if (m.type() === 'error') errors.push(m.text().slice(0, 200)); });
  page.on('pageerror', (e) => errors.push('pageerror ' + String(e).slice(0, 200)));

  const reqs = []; // {route, body}
  let state = { campaigns: [], detail: null, runs: [] };
  const json = (route, body, status = 200) => route.fulfill({ status, contentType: 'application/json', body: JSON.stringify(body) });
  await page.route('**/index.php?r=admin_ai_idol_create_campaign*', async (route) => {
    reqs.push({ route: 'create', body: JSON.parse(route.request().postData() || '{}') });
    await new Promise((r) => setTimeout(r, 700));
    json(route, { ok: true, created: true, campaign_id: 'cmock1' });
  });
  await page.route('**/index.php?r=admin_ai_idol_campaigns*', (route) => json(route, { ok: true, campaigns: state.campaigns }));
  await page.route('**/index.php?r=admin_ai_idol_agent_runs*', (route) => json(route, { ok: true, runs: state.runs }));
  await page.route('**/index.php?r=admin_ai_idol_campaign&*', (route) => json(route, { ok: true, data: state.detail }));
  await page.route('**/index.php?r=admin_ai_idol_agent_create*', async (route) => {
    reqs.push({ route: 'auto', body: JSON.parse(route.request().postData() || '{}') });
    json(route, { ok: true, run: { _id: 'rmock1', status: 'INTERPRETING', brief: 'x', decisions: [] } });
  });
  for (const r of ['approve_script', 'job_retry_repair', 'job_rewrite', 'job_cancel']) {
    await page.route(`**/index.php?r=admin_ai_idol_${r}&*`, (route) => {
      reqs.push({ route: r, body: JSON.parse(route.request().postData() || '{}'), url: route.request().url() });
      json(route, { ok: true, status: 'QUEUED' });
    });
  }

  const shot = async (name) => { await page.screenshot({ path: OUT + name + '.png', fullPage: true }); shots.push(name + '.png'); };
  const overflow = (sel) => page.evaluate((s) => [...document.querySelectorAll(s)].filter((e) => e.offsetParent).map((e) => e.scrollWidth > e.clientWidth + 1 || e.scrollHeight > e.clientHeight + 1 ? e.id || e.className : null).filter(Boolean), sel);

  const toStep4 = async () => {
    await page.click('#manualFlowTab').catch(() => {});
    await page.waitForFunction(() => !document.getElementById('manualSelectAll').disabled, null, { timeout: 20000 }).catch(() => {});
    await page.click('#manualSelectAll');
    for (let i = 0; i < 3; i++) { await page.click('#manualNext'); await page.waitForTimeout(300); }
  };

  for (const [label, vp] of [['desktop', { width: 1366, height: 900 }], ['mobile', { width: 390, height: 844 }]]) {
    await page.setViewportSize(vp);
    state = { campaigns: [], detail: null, runs: [] };
    await page.goto(BASE + '?r=admin_ai_idol');
    await page.waitForSelector('#manualFlowTab'); await page.click('#autoFlowTab');

    // ---- 2. Auto tab
    const radios = await page.$$eval('input[name="autoApprovalMode"]', (r) => r.map((x) => ({ v: x.value, c: x.checked, vis: !!x.offsetParent || !!x.closest('label')?.offsetParent })));
    check(`${label} auto radios AUTO+MANUAL, default AUTO`, radios.length === 2 && radios.find((r) => r.v === 'AUTO')?.c === true, JSON.stringify(radios));
    await shot(`${label}-01-auto-tab`);
    await page.fill('#autoBrief', 'Tạo video giới thiệu kem chống nắng bán chạy nhất');
    await page.click('#autoSubmit');
    await page.waitForTimeout(600);
    let r = reqs.splice(0).find((x) => x.route === 'auto');
    check(`${label} auto submit approval_mode=AUTO`, r?.body?.approval_mode === 'AUTO', JSON.stringify(r?.body));
    await page.reload(); await page.click('#autoFlowTab'); await page.waitForSelector('#autoBrief');
    await page.fill('#autoBrief', 'Tạo video giới thiệu kem chống nắng bán chạy nhất');
    await page.locator('input[name="autoApprovalMode"][value="MANUAL"]').check({ force: true });
    await page.click('#autoSubmit'); await page.waitForTimeout(600);
    r = reqs.splice(0).find((x) => x.route === 'auto');
    check(`${label} auto submit approval_mode=MANUAL`, r?.body?.approval_mode === 'MANUAL', JSON.stringify(r?.body));

    // ---- 1. Manual tab wizard
    await page.reload(); await page.waitForSelector('#manualFlowTab');
    await page.click('#manualFlowTab');
    await toStep4();
    const atStep4 = await page.isVisible('#manualStep4');
    check(`${label} manual reaches step 4`, atStep4, await page.textContent('#manualStepCounter'));
    if (atStep4) {
      const vis = await page.evaluate(() => ['manualCreateAuto', 'manualCreateReview', 'manualPrev'].map((i) => { const e = document.getElementById(i); return !e.hidden && !!e.offsetParent; }));
      check(`${label} two create buttons + Quay lại visible`, vis.every(Boolean), vis.join());
      const subs = await page.$$eval('.wz-create small', (s) => s.map((x) => x.textContent.trim().length));
      check(`${label} buttons have sub-text`, subs.length === 2 && subs.every((n) => n > 10));
      const ov = await overflow('.wz-create, .wz-create span, .wz-create small');
      check(`${label} no clipping in create buttons`, ov.length === 0, ov.join());
      const docOv = await page.evaluate(() => document.documentElement.scrollWidth > window.innerWidth + 1);
      check(`${label} no horizontal page overflow`, !docOv);
      await shot(`${label}-02-manual-step4`);
      for (const [id, mode] of [['manualCreateAuto', 'AUTO'], ['manualCreateReview', 'MANUAL']]) {
        await page.click('#' + id);
        await page.waitForTimeout(150);
        const dis = await page.evaluate(() => ['manualCreateAuto', 'manualCreateReview'].map((i) => document.getElementById(i).disabled));
        check(`${label} ${mode} buttons disabled while pending`, dis.every(Boolean), dis.join());
        if (mode === 'AUTO') await shot(`${label}-03-manual-pending`);
        await page.waitForTimeout(1200);
        const c = reqs.splice(0).find((x) => x.route === 'create');
        check(`${label} ${mode} create body configuration.approval_mode`, c?.body?.configuration?.approval_mode === mode, JSON.stringify(c?.body?.configuration?.approval_mode));
        await page.waitForTimeout(500);
        if (mode === 'AUTO') await toStep4();
      }
    }

    // ---- 3. Activity cards
    const seg = (over) => ({ job_id: 'job1', product_id: '1', status: 'AWAITING_APPROVAL', script_status: 'PENDING_APPROVAL', script_version: 3, script_text: 'Xin chào, đây là kịch bản đủ dài để duyệt và tạo video cho sản phẩm.', repair_history: [{ code: 'PRICE' }, { code: 'CLAIM' }], ...over });
    const camp = (status, segments) => ({ _id: 'cmock1', name: 'Mock campaign', status, purpose: 'product_video', platforms: ['internal'], product_ids: ['1'], configuration: {}, segments, created_at: new Date().toISOString() });
    // approval
    let c = camp('AWAITING_APPROVAL', [seg({})]);
    state = { campaigns: [c], detail: c, runs: [] };
    await page.goto(BASE + '?r=admin_ai_idol&campaign_id=cmock1');
    await page.waitForSelector('.script-card', { timeout: 15000 }).catch(async () => {
      await page.click('#actAll, [data-act-filter="all"]').catch(() => {});
      await page.locator('.act-card').first().click().catch(() => {});
      await page.waitForSelector('.script-card', { timeout: 8000 }).catch(() => {});
    });
    const card = await page.locator('.script-card').first().innerText().catch(() => '');
    check(`${label} approval card title`, /Kịch bản V3 — Đã đạt kiểm tra ✓/.test(card), card.slice(0, 60));
    check(`${label} approval checklist`, /Thông tin sản phẩm/.test(card) && /Sẵn sàng đọc/.test(card));
    const btns = await page.$$eval('.script-card .script-actions > button, .script-card .script-actions > .script-rewrite > button', (b) => b.map((x) => x.textContent.trim()));
    check(`${label} approval has 4 action buttons`, btns.length === 4, btns.join(' | '));
    await shot(`${label}-04-approval-card`);
    await page.click('.script-card [data-script-toggle="rw"]');
    await page.waitForTimeout(200);
    await shot(`${label}-05-rewrite-menu`);
    await page.click('.script-card [data-script-rewrite="SHORTER"]');
    await page.waitForTimeout(800);
    let q = reqs.splice(0).find((x) => x.route === 'job_rewrite');
    check(`${label} rewrite -> job_rewrite instruction SHORTER`, q?.body?.instruction === 'SHORTER' && /job_id=job1/.test(q?.url || ''), JSON.stringify(q?.body));
    await page.click('.script-card [data-script-toggle="ed"]');
    await page.waitForTimeout(200);
    await page.fill('.script-card .script-editor', 'Kịch bản đã chỉnh sửa bởi quản trị viên, đủ dài để lưu lại.');
    await shot(`${label}-06-edit`);
    await page.click('.script-card .script-edit [data-approve-script]');
    await page.waitForTimeout(800);
    q = reqs.splice(0).find((x) => x.route === 'approve_script');
    check(`${label} edit save -> approve_script with script_text`, /chỉnh sửa bởi/.test(q?.body?.script_text || ''), JSON.stringify(q?.body).slice(0, 80));

    // recovery
    c = camp('NEEDS_ATTENTION', [seg({ status: 'AUTO_REPAIR_EXHAUSTED', script_status: 'AUTO_REPAIR_EXHAUSTED', repair_attempt: 2, validation_history: [{ issues: [{ code: 'PRICE_UNVERIFIED', reason: 'Giá chưa xác minh', original_text: 'chỉ 99k' }, { code: 'CLAIM_MEDICAL', reason: 'Claim y tế không hợp lệ' }] }] })]);
    state = { campaigns: [c], detail: c, runs: [] };
    await page.goto(BASE + '?r=admin_ai_idol&campaign_id=cmock1');
    await page.waitForSelector('.script-card.is-recovery', { timeout: 15000 }).catch(() => {});
    const rc = await page.locator('.script-card').first().innerText().catch(() => '');
    check(`${label} recovery title`, /⚠ Crew AI chưa thể hoàn tất kịch bản/.test(rc));
    check(`${label} recovery attempts 2/3`, /Đã tự sửa: 2\/3/.test(rc));
    check(`${label} recovery issues listed`, /PRICE_UNVERIFIED/.test(rc) && /Claim y tế/.test(rc));
    const body = await page.locator('body').innerText();
    check(`${label} old text 'Kịch bản bị từ chối' absent`, !/Kịch bản bị từ chối/.test(body));
    const rb = await page.$$eval('.script-card .script-actions button', (b) => b.map((x) => x.textContent.trim()));
    check(`${label} recovery buttons`, /Thử lại/.test(rb[0]) && rb.some((t) => /AI viết lại/.test(t)) && rb.some((t) => /Chỉnh sửa/.test(t)) && rb.some((t) => /Hủy job/.test(t)), rb.join(' | '));
    await shot(`${label}-07-recovery-card`);
    await page.click('[data-script-retry]'); await page.waitForTimeout(700);
    check(`${label} Thử lại -> job_retry_repair`, reqs.splice(0).some((x) => x.route === 'job_retry_repair'));
    await page.click('.script-card [data-script-toggle="rw"]');
    await page.click('.script-card [data-script-rewrite="STRONGER_SALES"]'); await page.waitForTimeout(700);
    q = reqs.splice(0).find((x) => x.route === 'job_rewrite');
    check(`${label} recovery rewrite STRONGER_SALES`, q?.body?.instruction === 'STRONGER_SALES');
    await page.click('.script-card [data-script-toggle="ed"]');
    await page.fill('.script-card .script-editor', 'Kịch bản sửa tay từ thẻ phục hồi, đủ dài để gửi duyệt.');
    await page.click('.script-card .script-edit [data-approve-script]'); await page.waitForTimeout(700);
    check(`${label} recovery edit -> approve_script`, reqs.splice(0).some((x) => x.route === 'approve_script'));
    await page.evaluate(() => { window.confirm = () => true; });
    await page.click('[data-script-cancel]'); await page.waitForTimeout(700);
    check(`${label} Hủy job -> job_cancel`, reqs.splice(0).some((x) => x.route === 'job_cancel'));
  }
  check('no console errors', errors.length === 0, errors.slice(0, 5).join(' || '));
  return { results, shots };
}
