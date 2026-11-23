const assert = require('node:assert/strict');
const { chromium } = require('playwright');
const base = process.env.DZ_DEMO_ORIGIN;
const session = '00000000-0000-4000-8000-000000000001';
const pending = { paid: false, has_payment_operation: false, report_ready: false, notification_channel: 'email' };
const failures = [];
const pause = ms => new Promise(resolve => setTimeout(resolve, ms));

(async () => {
  assert.ok(base && ['127.0.0.1', 'localhost'].includes(new URL(base).hostname));
  const browser = await chromium.launch({ executablePath: process.env.DZ_CHROME,
    args: ['--no-sandbox', '--no-zygote', '--single-process', '--disable-gpu'] });
  const context = await browser.newContext();
  await context.route('**/*', route => {
    const request = route.request();
    if (new URL(request.url()).origin !== base || !['GET', 'HEAD'].includes(request.method())) return route.abort();
    return route.continue();
  });
  async function check(name, fn) {
    const page = await context.newPage();
    const pageErrors = [];
    page.on('pageerror', error => pageErrors.push(error.message));
    try { await fn(page); console.log('PASS', name); }
    catch (error) { failures.push(name); console.log('FAIL', name, error.message, pageErrors); }
    finally { await page.close(); }
  }
  try {
    await check('admin: production bundle renders report sections and rejects executable HTML', async page => {
      await page.route('**/api/admin/**', route => {
        const path = new URL(route.request().url()).pathname;
        if (path === '/api/admin/me') return route.fulfill({ json: { id: 1, name: 'QA', login: 'qa' } });
        if (path.endsWith('/gigachat/status')) return route.fulfill({ json: {
          gigachat_status: 'done', report_data: { 'intro.text': '<p>Проверяемый раздел отчёта</p><img src="/missing-audit.png" onerror="window.__auditXss=1"><a href="javascript:window.__auditXss=1">unsafe link</a>' },
        } });
        return route.fulfill({ json: { public_id: session, test_type: 'teen', status: 'completed',
          child_name: 'QA', child_age: 15, parent_name: 'QA', email: 'qa@example.test',
          payment_status: 'paid', has_result: true, answers: [], current_profile_state: {} } });
      });
      await page.goto(base + '/admin/sessions/' + session + '/show');
      await page.getByText('Проверяемый раздел отчёта', { exact: true }).waitFor({ timeout: 3000 });
      assert.equal(await page.locator('[onerror], a[href^="javascript:"]').count(), 0);
      assert.equal(await page.evaluate(() => window.__auditXss || 0), 0);
    });
    await check('gardner: bank response after unmount cannot reschedule polling', async page => {
      let reads = 0, bankChecks = 0, release;
      const gate = new Promise(resolve => release = resolve);
      await page.route('**/api/gardner-commerce/**', async route => {
        if (route.request().method() === 'POST') { bankChecks++; await gate; return route.fulfill({ json: { status: 'pending' } }); }
        reads++;
        return route.fulfill({ json: { paid: false, order_status: 'pending', timezone: 'Asia/Irkutsk', slots: [], report: {} } });
      });
      await page.goto(base + '/children/payment/' + session);
      for (let i = 0; i < 50 && bankChecks === 0; i++) await pause(20);
      assert.equal(bankChecks, 1);
      await page.locator('.account-brand').click();
      await page.waitForURL(base + '/');
      release(); await pause(350);
      assert.equal(reads, 1, 'A disposed page fetched its order again after the bank response');
    });
    await check('unknown route: accessible 404 at mobile and desktop widths', async page => {
      for (const width of [390, 1440]) {
        await page.setViewportSize({ width, height: 900 });
        const response = await page.goto(base + '/not-a-real-page');
        assert.equal(response.status(), 404);
        await page.getByRole('heading', { name: 'Страница не найдена' }).waitFor({ timeout: 3000 });
        assert.ok(await page.getByRole('link', { name: 'На главную', exact: true }).isVisible());
        assert.ok(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1));
      }
    });
    await check('teen: no polling/listener installed after unmount during initial request', async page => {
      await page.addInitScript(({ session }) => {
        sessionStorage.setItem(`tmig_verified_phone_${session}`, '79990000001');
        window.__auditIntervals = 0;
        const original = window.setInterval;
        window.setInterval = function (callback, delay, ...args) {
          if (delay === 25000) window.__auditIntervals++;
          return original.call(this, callback, delay, ...args);
        };
      }, { session });
      let release, count = 0;
      const gate = new Promise(resolve => release = resolve);
      await page.route('**/api/teen-payment/*/status', async route => {
        count++; await gate; await route.fulfill({ json: pending });
      });
      await page.goto(base + '/teen/payment/' + session);
      for (let i = 0; i < 50 && count === 0; i++) await pause(20);
      assert.equal(count, 1);
      await page.locator('.dzps-brand').click();
      await page.waitForURL(base + '/');
      release();
      await pause(350);
      await page.evaluate(() => window.dispatchEvent(new Event('focus')));
      await pause(250);
      assert.equal(count, 1, 'A disposed page sent a new status request');
      assert.equal(await page.evaluate(() => window.__auditIntervals), 0, 'Timer installed after unmount');
    });
    await check('teen: focus events cannot overlap an in-flight status request', async page => {
      await page.addInitScript(({ session }) => sessionStorage.setItem(`tmig_verified_phone_${session}`, '79990000001'), { session });
      let count = 0, release;
      const gate = new Promise(resolve => release = resolve);
      await page.route('**/api/teen-payment/*/status', async route => {
        count++;
        if (count > 1) await gate;
        await route.fulfill({ json: pending });
      });
      await page.goto(base + '/teen/payment/' + session);
      await page.getByRole('heading', { name: 'Пока нет подтверждения от банка' }).waitFor();
      await page.evaluate(() => { for (let i = 0; i < 4; i++) window.dispatchEvent(new Event('focus')); });
      await pause(250);
      const observed = count;
      release();
      assert.equal(observed, 2, 'Multiple concurrent status requests can overwrite newer payment state');
    });
  } finally { await browser.close(); }
  assert.deepEqual(failures, []);
})().catch(error => { console.error(error); process.exitCode = 1; });
