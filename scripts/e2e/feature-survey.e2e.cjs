const assert = require('node:assert/strict');
const fs = require('node:fs');
const { chromium } = require('playwright');

const base = new URL('http://127.0.0.1:8713/frontend/public/index.php');
const url = (r, query = {}) => base + '?' + new URLSearchParams({ r, ...query });
const output = 'output/playwright/survey-after';
const ajax = { 'X-Requested-With': 'XMLHttpRequest', Accept: 'application/json' };

async function run() {
  const browser = await chromium.launch({
    executablePath: process.env.SURVEY_CHROMIUM || undefined,
  });
  fs.mkdirSync(output, { recursive: true });
  try {
    const guest = await browser.newContext();
    assert.equal((await guest.request.get(url('admin_feature_feedback', { format: 'json' }))).status(), 403);
    await guest.close();
    for (const [role, route, width] of [['customer', 'goiy', 390], ['staff', 'staff_dashboard', 820], ['admin', 'admin_dashboard', 1440]]) {
      const context = await browser.newContext({ viewport: { width, height: 850 }, reducedMotion: 'reduce' });
      await context.route('**/*', request => new URL(request.request().url()).origin === base.origin
        ? request.continue() : request.abort());
      const login = await context.request.post(url('xulydangnhap'), {
        form: { email: role + '@skinsyntax.test', mat_khau: 'Survey-fixture-only-42' }, maxRedirects: 0,
      });
      assert.equal(login.status(), 302);
      assert.match(login.headers().location, /r=(home|staff_dashboard|admin_dashboard)$/);
      const page = await context.newPage();
      page.setDefaultTimeout(8000);
      await page.goto(url(route), { waitUntil: 'domcontentloaded' });
      const invitation = page.locator('[data-survey-invitation]:not([data-syna-survey])');
      const trigger = invitation.locator('[data-survey-open]');
      const dialog = invitation.locator('[data-survey-dialog]');
      if (role === 'customer') {
        await page.getByText('Sữa rửa mặt dịu nhẹ (fixture)', { exact: false }).first().waitFor();
        await trigger.scrollIntoViewIfNeeded();
        await dialog.waitFor();
      } else {
        await trigger.click();
      }
      assert.equal(await dialog.isVisible(), true);
      if (role === 'customer') assert.equal(await page.locator('[data-syna-survey]').isVisible(), false);
      assert.equal(await dialog.locator('input:checked').count(), 0);
      await page.keyboard.press('Escape');
      await dialog.waitFor({ state: 'hidden' });
      await trigger.click();
      await page.screenshot({ path: output + '/' + role + '.png' });
      await page.keyboard.press('Tab');
      assert.ok(await dialog.evaluate(element => element.contains(document.activeElement)));
      await dialog.locator('[data-survey-skip]').click();
      assert.equal(await dialog.isVisible(), false);
      await trigger.click();
      await dialog.locator('[data-survey-submit]').click();
      assert.equal(await dialog.locator('[data-survey-error]').isVisible(), true);
      const payload = {
        csrf: await dialog.locator('[name=csrf]').inputValue(),
        interaction_id: await dialog.locator('[name=interaction_id]').inputValue(),
      };
      const post = data => context.request.post(url('feature_feedback'), { form: data, headers: ajax });
      assert.equal((await post(payload)).status(), 422);
      const inputs = dialog.locator('[data-survey-question] input[value="4"]');
      assert.equal(await inputs.count(), 5);
      for (const input of await inputs.all()) {
        const name = await input.getAttribute('name');
        payload[name] = '4';
        await dialog.locator('label[for="' + await input.getAttribute('id') + '"]').click();
      }
      const first = Object.keys(payload).find(key => key.startsWith('answers'));
      assert.equal((await post({ ...payload, [first]: '6' })).status(), 422);
      if (role === 'customer' && process.env.SURVEY_PARALLEL_SERVER === '1') {
        const secondWorker = url('feature_feedback').replace(':8713/', ':8714/');
        const responses = await Promise.all([post(payload), context.request.post(secondWorker, { form: payload, headers: ajax })]);
        const writes = await Promise.all(responses.map(async result => {
          assert.equal(result.status(), 200);
          return (await result.json()).duplicate;
        }));
        assert.deepEqual(writes.sort(), [false, true]);
      }
      const response = page.waitForResponse(r => r.url() === url('feature_feedback') && r.request().method() === 'POST');
      await dialog.locator('[data-survey-submit]').click();
      assert.equal((await response).status(), 200);
      await dialog.locator('[data-survey-success]').waitFor();
      await dialog.locator('[data-survey-success] [data-survey-close]').click();
      assert.deepEqual(await (await post({ ...payload, role: 'admin', user_id: 'spoofed' })).json(), { ok: true, duplicate: true });
      for (const retry of await Promise.all([post(payload), post(payload), post(payload)])) {
        assert.deepEqual(await retry.json(), { ok: true, duplicate: true });
      }
      if (role !== 'admin') {
        assert.equal((await context.request.get(url('admin_feature_feedback', { format: 'json' }))).status(), 403);
        if (role === 'customer') {
          const widget = page.locator('[data-ai-chat-widget]');
          await widget.evaluate(element => element.dataset.agentV2 = '0');
          let completed = false;
          await page.route(url('ai_chat_assistant'), request => request.fulfill({
            json: completed ? { ok: true, answer: 'Phản hồi tư vấn dùng để kiểm tra giao diện.' } : { ok: false, message: 'Dịch vụ tạm gián đoạn.' },
          }));
          await widget.locator('[data-ai-chat-toggle]').click();
          for (const reply of ['Dịch vụ tạm gián đoạn.', 'Phản hồi tư vấn dùng để kiểm tra giao diện.']) {
            await widget.locator('[data-ai-chat-input]').fill('Câu hỏi kiểm tra khảo sát.');
            await widget.locator('[data-ai-chat-submit]').click();
            await widget.getByText(reply, { exact: true }).waitFor();
            assert.equal(await widget.locator('[data-syna-survey]').isVisible(), completed);
            completed = true;
          }
          await widget.locator('[data-syna-survey] [data-survey-open]').click();
          await widget.locator('[data-syna-survey] [data-survey-dialog]').screenshot({ path: output + '/syna.png' });
          await page.keyboard.press('Escape');
          const key = await invitation.getAttribute('data-survey-key');
          await context.request.post(url('xulydangnhap'), {
            form: { email: 'customer2@skinsyntax.test', mat_khau: 'Survey-fixture-only-42' }, maxRedirects: 0,
          });
          const stale = await post(payload);
          assert.ok([403, 422].includes(stale.status()));
          await page.goto(url('goiy'), { waitUntil: 'domcontentloaded' });
          assert.notEqual(await invitation.getAttribute('data-survey-key'), key);
        }
      } else {
        await page.goto(url('admin_feature_feedback'), { waitUntil: 'domcontentloaded' });
        await page.getByRole('heading', { name: 'Khảo sát & đánh giá' }).waitFor();
        await page.screenshot({ path: output + '/dashboard.png', fullPage: true });
        await page.locator('#themeToggleBtn').click();
        await page.waitForFunction(() => document.documentElement.dataset.theme === 'dark');
        await page.screenshot({ path: output + '/dashboard-dark.png', fullPage: true });
        await page.locator('#themeToggleBtn').click();
        await page.setViewportSize({ width: 390, height: 850 });
        await page.waitForFunction(() => document.getElementById('adminSidebar').getBoundingClientRect().right <= 1);
        await page.screenshot({ path: output + '/dashboard-mobile.png' });
        const dimensions = await page.locator('.survey-dashboard').evaluate(element => [element.scrollWidth, element.clientWidth]);
        assert.ok(dimensions[0] <= dimensions[1] + 1, 'Survey dashboard overflows: ' + dimensions.join('/'));
        const json = await (await context.request.get(url('admin_feature_feedback', { format: 'json' }))).json();
        assert.equal(json.filters.version, '2');
        assert.equal(json.summary.surveys, 3);
        assert.equal(json.summary.answers, 15);
        assert.equal(json.summary.participants, 3);
        assert.equal(json.summary.distribution[4], 15);
        assert.equal(json.summary.average, 4);
        assert.equal(json.summary.satisfaction_rate, 100);
        assert.ok(json.summary.criteria.every(row => row.median === 4));
        const all = await (await context.request.get(url('admin_feature_feedback', { format: 'json', version: 'all' }))).json();
        assert.equal(all.summary.surveys, 4);
        assert.equal(all.summary.answers, 16);
        assert.equal(all.summary.legacy_surveys, 1);
        assert.equal(all.summary.distribution[2], 1);
        assert.equal(all.summary.distribution[4], 15);
        assert.equal(all.summary.groups.version.v1.surveys, 1);
        assert.equal(all.summary.groups.version.v2.surveys, 3);
        const filtered = await (await context.request.get(url('admin_feature_feedback', { format: 'json', role: 'nhanvien', version: '2', from: '2020-01-01' }))).json();
        assert.equal(filtered.summary.surveys, 1);
        await page.goto(url('admin_feature_feedback', { to: '2000-01-01' }), { waitUntil: 'domcontentloaded' });
        await page.getByRole('heading', { name: 'Chưa có phản hồi trong phạm vi đang lọc' }).waitFor();
        for (const scope of ['summary', 'responses']) {
          const csv = await context.request.get(url('admin_feature_feedback', { format: 'csv', scope, role: 'nhanvien' }));
          assert.equal(csv.status(), 200);
          assert.match(csv.headers()['content-type'], /text\/csv/);
          assert.equal((await csv.text()).trim().split(/\r?\n/).length, 6);
        }
      }
      await context.close();
      console.log(role + ' survey passed');
    }
  } finally {
    await browser.close();
  }
}
run().catch(error => { console.error(error); process.exitCode = 1; });
