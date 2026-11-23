const assert = require('node:assert/strict');
const { createHash } = require('node:crypto');
const { chromium } = require('playwright');

const base = process.env.DZ_DEMO_ORIGIN;
const cases = [
  { name: 'Гарднер', landing: '/children', free: '/children/result/demo', full: '/children/demo',
    report: '/examples/gardner-egor-v1/Egor-Report', child: 'Егор, 7 лет', test: '/children/test', price: '2 490',
    htmlSha: 'eed50c8988404da16fbf9fb3361e6171a3b69c1b440c86728b54485a06e658ce',
    pdfSha: 'ae89385a7bc6290f921c4b7995916f01cceac15589580fc61b25205b40a2bc70' },
  { name: 'Профориентация', landing: '/teen', free: '/teen/result/demo', full: '/teen/demo',
    report: '/examples/teen-anna-v1/Anna-Report', child: 'Анна, 13 лет', test: '/teen/test?start=1', price: '3 490',
    htmlSha: 'ec0f71da4f66efd44538e84362b9ac2886334fe76b5ee2185a5506700774cac2',
    pdfSha: 'c4581cc3bcb8c7895828cd864048c21b7499697dba087821e110b942925adc1f' },
];

async function run() {
  assert.ok(base && ['127.0.0.1','localhost'].includes(new URL(base).hostname), 'Use an isolated local site.');
  const options = process.env.DZ_CHROME ? { executablePath: process.env.DZ_CHROME } : {};
  const browser = await chromium.launch({ ...options, args: ['--no-sandbox','--no-zygote','--single-process','--disable-gpu'] });
  try {
    const page = await browser.newPage();
    const errors = [], mutations = [], failedAssets = [];
    page.on('pageerror', err => errors.push(err.message));
    page.on('response', r => { if (r.url().startsWith(base+'/') && r.status()>=400) failedAssets.push([new URL(r.url()).pathname,r.status()]); });
    await page.route('**/*', route => {
      const r = route.request();
      if (new URL(r.url()).origin !== base) return route.abort();
      if (!['GET', 'HEAD'].includes(r.method())) { mutations.push([r.method(),new URL(r.url()).pathname]); return route.abort(); }
      return route.continue();
    });
    for (const width of [390,1440]) {
      await page.setViewportSize({width,height:900});
      for (const c of cases) {
        await page.goto(base+c.landing);
        await page.getByRole('link',{name:'Пример результата',exact:true}).first().click();
        await page.waitForURL(base+c.free);
        const choices=page.locator('.report-choices');
        await choices.waitFor();
        assert.equal(await choices.getByRole('link').count(),2);
        assert.equal(await page.locator('.dz-free-cards article').count(),2);
        assert.equal(await page.locator('.dz-price').innerText(),c.price+' ₽');
        assert.equal(await page.getByRole('link',{name:'Пройти тест',exact:true}).getAttribute('href'),c.test);
        await choices.getByRole('link',{name:'Бесплатный отчет',exact:true}).click();
        await page.waitForURL(base+c.free+'#free-report');
        await page.waitForFunction(()=>Math.abs(document.getElementById('free-report').getBoundingClientRect().top)<150);
        assert.ok(await page.evaluate(()=>document.documentElement.scrollWidth <= innerWidth+1),'Free example overflows');
        if (process.env.DZ_QA_IMAGES) {
          await page.evaluate(()=>scrollTo({top:0,behavior:'instant'}));
          await page.screenshot({path:process.env.DZ_QA_IMAGES+'/'+(c.landing.slice(1))+'-'+width+'.png'});
        }
        await choices.getByRole('link',{name:'Платный отчет',exact:true}).click();
        await page.waitForURL(base+c.report+'.html');
        assert.equal(await page.locator('h1').innerText(),c.child);
        assert.ok(await page.evaluate(()=>document.documentElement.scrollWidth <= innerWidth+1),'Full report overflows');
        const html = await page.request.get(page.url());
        assert.equal(html.status(), 200);
        assert.match(html.headers()['content-type'], /text\/html/);
        assert.equal(createHash('sha256').update(await html.body()).digest('hex'), c.htmlSha);
        const pdf=page.getByRole('link',{name:'Скачать PDF'});
        const pdfUrl=new URL(await pdf.getAttribute('href'),page.url());
        assert.equal(pdfUrl.pathname,c.report+'.pdf');
        const response=await page.request.get(pdfUrl.href);
        assert.equal(response.status(),200);
        assert.match(response.headers()['content-type'],/application\/pdf/);
        const body=await response.body();
        assert.equal(body.subarray(0,5).toString(),'%PDF-');
        assert.equal(createHash('sha256').update(body).digest('hex'),c.pdfSha);
        await page.goBack();
        await choices.waitFor(); assert.equal(new URL(page.url()).pathname,c.free);
        await page.goto(base+c.landing);
        await page.getByRole('link',{name:'Пример полного отчета',exact:true}).click();
        await page.waitForURL(base+c.report+'.html');
        console.log(width,c.name,'landing → free → anchor → full → PDF; Back; landing → full: PASS');
      }
    }
    for (const path of ['/children/demo','/children/result/demo?view=report']) {
      await page.goto(base+path); await page.waitForURL(base+cases[0].report+'.html');
    }
    await page.goto(base+'/children/result/demo?preview=free');
    await page.locator('.report-choices').waitFor();
    await page.getByRole('link',{name:'Пройти тест',exact:true}).click();
    await page.waitForURL(base+'/children/test'); await page.getByRole('heading',{name:/Расскажите об интересах/}).waitFor();
    assert.deepEqual(errors,[]); assert.deepEqual(mutations,[]); assert.deepEqual(failedAssets,[]);
    console.log('Legacy addresses, child registration, assets, HTTP methods and JS errors: PASS');
  } finally { await browser.close(); }
}
run().catch(err=>{ console.error(err); process.exitCode=1; });
