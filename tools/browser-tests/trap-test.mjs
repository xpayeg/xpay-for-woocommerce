// playwright-core is not a plugin dependency (the shipped plugin has no
// node_modules). Install it wherever you run this from: npm i playwright-core
let chromium;
try {
  ( { chromium } = await import( 'playwright-core' ) );
} catch ( e ) {
  console.error( 'playwright-core not found. Run: npm install playwright-core' );
  process.exit( 2 );
}
const browser = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium-1194/chrome-linux/chrome', args: ['--no-sandbox'] });
const results = [];
function check(name, pass, detail) { results.push({ name, pass, detail }); }

async function fresh() {
  const page = await browser.newPage();
  await page.goto('http://127.0.0.1:8099/tools/browser-tests/trap-harness.html', { waitUntil: 'networkidle' });
  await page.waitForTimeout(150);
  return page;
}
const state = (page) => page.evaluate(() => ({
  status: document.getElementById('xpay-payment-status').textContent,
  paused: document.getElementById('xpay-payment').classList.contains('xpay-paused'),
  windowOpen: document.documentElement.classList.contains('xpay-window-open'),
  payVisible: document.getElementById('xpay-pay-button').style.display !== 'none',
  t: window.__test,
  hash: location.hash,
}));

// SCENARIO A — the reported trap: pay, get declined, click the window's X.
{
  const page = await fresh();
  const opened = await state(page);
  check('A0 window opens on load', opened.t.opens === 1 && opened.windowOpen, JSON.stringify(opened));

  await page.evaluate(() => window.postMessage({ type: 'XPAY_EMBED_CONFIRMED', payload: {} }, location.origin));
  await page.waitForTimeout(80);
  // Card is declined: the platform sends NOTHING. Shopper clicks the X inside the window.
  await page.evaluate(() => window.postMessage({ type: 'XPAY_EMBED_CLOSE', payload: {} }, location.origin));
  await page.waitForTimeout(150);
  const after = await state(page);
  check('A1 SDK itself refused the close (trap reproduced)', after.t.sdkCloses === 0, `sdkCloses=${after.t.sdkCloses}`);
  check('A2 plugin freed the shopper', after.t.destroys === 1 && after.paused && !after.windowOpen, JSON.stringify(after));
  check('A3 pay-again button offered', after.payVisible, after.status);
  check('A3b message names the failed attempt, not an untouched close',
    after.status === 'Payment was not completed. Your order is saved, and you can try again.', after.status);

  // Re-open must build a NEW instance: destroy() is terminal in the SDK.
  await page.click('#xpay-pay-button');
  await page.waitForTimeout(150);
  const re = await state(page);
  check('A4 Pay now reopens with a fresh window', re.t.instances === 2 && re.t.opens === 2 && re.windowOpen, JSON.stringify(re.t));
  await page.close();
}

// SCENARIO B — happy path must be untouched.
{
  const page = await fresh();
  await page.evaluate(() => window.postMessage({ type: 'XPAY_EMBED_CONFIRMED', payload: {} }, location.origin));
  await page.waitForTimeout(60);
  await page.evaluate(() => window.postMessage({ type: 'XPAY_EMBED_SUCCESS', payload: { redirectUrl: '#paid-ok' } }, location.origin));
  await page.waitForTimeout(200);
  const s = await state(page);
  check('B1 success still navigates, no teardown', s.hash === '#paid-ok' && s.t.destroys === 0, `hash=${s.hash} destroys=${s.t.destroys}`);
  await page.close();
}

// SCENARIO C — a close BEFORE paying is the SDK's own job; we must not double-handle.
{
  const page = await fresh();
  await page.evaluate(() => window.postMessage({ type: 'XPAY_EMBED_CLOSE', payload: {} }, location.origin));
  await page.waitForTimeout(150);
  const s = await state(page);
  check('C1 SDK handled it, plugin stayed out', s.t.sdkCloses === 1 && s.t.destroys === 0, JSON.stringify(s.t));
  check('C2 page still shows the paused state', s.paused && s.payVisible, JSON.stringify(s));
  check('C3 untouched close keeps the calm wording',
    s.status === 'Your order is saved. Pay when you are ready.', s.status);
  await page.close();
}

// SCENARIO D — a close message from another origin must be ignored.
{
  const page = await fresh();
  await page.evaluate(() => window.postMessage({ type: 'XPAY_EMBED_CONFIRMED', payload: {} }, location.origin));
  await page.waitForTimeout(60);
  // localhost:8099 is a genuinely different origin from 127.0.0.1:8099.
  await page.evaluate(() => new Promise((resolve) => {
    const f = document.createElement('iframe');
    f.src = 'http://localhost:8099/';
    f.onload = () => { f.contentWindow.postMessage({ type: 'XPAY_EMBED_CLOSE', payload: {} }, '*'); setTimeout(resolve, 250); };
    document.body.appendChild(f);
  }));
  const s = await state(page);
  check('D1 foreign-origin close ignored', s.t.destroys === 0 && !s.paused, JSON.stringify(s.t));
  await page.close();
}

await browser.close();
let fail = 0;
for (const r of results) { if (!r.pass) fail++; console.log(`${r.pass ? 'PASS' : 'FAIL'}  ${r.name}${r.pass ? '' : '  <- ' + r.detail}`); }
console.log(fail === 0 ? `\nAll ${results.length} checks passed.` : `\n${fail}/${results.length} FAILED`);
process.exit(fail ? 1 : 0);
