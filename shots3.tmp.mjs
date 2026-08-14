import { chromium } from 'playwright';
const base = 'http://localhost:8801';
const browser = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium' });
const ctx = await browser.newContext({ viewport: { width: 390, height: 844 }, deviceScaleFactor: 2 });
const page = await ctx.newPage();
await page.goto(base + '/login');
await page.fill('input[type=email]', 'alex@example.com');
await page.fill('input[type=password]', 'demo-pass-1234');
await page.click('button[type=submit]');
await page.waitForURL('**/home');
for (const [name, path] of [['home', '/home'], ['eat', '/eat'], ['pantry-item', '/pantry/1']]) {
  await page.goto(base + path);
  await page.waitForLoadState('networkidle');
  await page.waitForTimeout(300);
  await page.screenshot({ path: `.impeccable/review/${name}.png`, fullPage: true });
}
// Drive the scan component into its done state to capture the reward moment.
await page.goto(base + '/scan');
await page.waitForLoadState('networkidle');
await page.evaluate(async () => {
  const c = window.Livewire.all()[0];
  await c.set('detectedBarcode', '5411188110835');
  await c.set('addedProductName', 'Alpro — Oat Drink (1L)');
  await c.set('matchedProductId', 1);
  await c.set('step', 'done');
});
await page.waitForTimeout(600);
await page.screenshot({ path: '.impeccable/review/scan-done.png', fullPage: true });
await browser.close();
console.log('done');
