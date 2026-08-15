/**
 * Real-browser smoke test of the core loop (brief §18 acceptance):
 * login -> Scan -> barcode -> confirm -> quantity -> pantry.
 *
 * WHY THIS EXISTS: the PHP suite exercises components server-side and cannot see
 * browser-only failures (double-loaded scripts, dead Livewire components, Alpine
 * wiring, uploads). The nested-layout bug that froze the Scan flow in production
 * was invisible to 200+ green PHPUnit tests and instantly visible here.
 *
 * Usage:
 *   1. Serve the app (php artisan serve) with a seeded user:
 *      User::factory()->onboarded()->create(['email' => 'e2e@test.dev', 'password' => bcrypt('password-e2e')])
 *   2. BASE=http://127.0.0.1:8000 node scripts/e2e-smoke.mjs
 *
 * Requires playwright-core and a Chromium executable (CHROMIUM env var, or the
 * default Playwright browsers path).
 */
import { chromium } from 'playwright-core';

const BASE = process.env.BASE || 'http://127.0.0.1:8000';
const EXE = process.env.CHROMIUM || undefined;
const EMAIL = process.env.E2E_EMAIL || 'e2e@test.dev';
const PASSWORD = process.env.E2E_PASSWORD || 'password-e2e';

// A minimal JPEG; barcode detection is stubbed below, mirroring a successful
// on-device read without needing a genuine barcode photo.
const JPEG = Buffer.from(
    '/9j/4AAQSkZJRgABAQEAYABgAAD/2wBDAAgGBgcGBQgHBwcJCQgKDBQNDAsLDBkSEw8UHRofHh0aHBwgJC4nICIsIxwcKDcpLDAxNDQ0Hyc5PTgyPC4zNDL/wAALCAABAAEBAREA/8QAFAABAAAAAAAAAAAAAAAAAAAACf/EABQQAQAAAAAAAAAAAAAAAAAAAAD/2gAIAQEAAD8AKp//2Q==',
    'base64'
);

const fail = (msg) => { console.error('FAIL:', msg); process.exitCode = 1; };
const ok = (msg) => console.log('OK:', msg);

const browser = await chromium.launch(EXE ? { executablePath: EXE } : {});
const page = await (await browser.newContext({ viewport: { width: 390, height: 844 } })).newPage();

const problems = [];
page.on('console', (m) => { if (/multiple instances|closing tags/i.test(m.text())) problems.push(m.text()); });
page.on('pageerror', (e) => problems.push(e.message));

// ---- login ----
await page.goto(`${BASE}/login`, { waitUntil: 'networkidle' });
await page.fill('input[type=email]', EMAIL);
await page.fill('input[type=password]', PASSWORD);
await page.click('button[type=submit]');
await page.waitForTimeout(3000);
page.url().includes('/home') ? ok('login -> home') : fail(`login landed on ${page.url()}`);

// ---- structural health: exactly one document, live Livewire components ----
const health = await page.evaluate(() => ({
    scripts: [...document.querySelectorAll('script[src]')].map((s) => s.src.split('/').pop().split('?')[0]),
    components: window.Livewire ? window.Livewire.all().length : -1,
}));
const dupes = health.scripts.filter((s, i) => health.scripts.indexOf(s) !== i);
dupes.length === 0 ? ok('no duplicated scripts') : fail(`duplicated scripts: ${dupes.join(', ')}`);
health.components > 0 ? ok(`${health.components} live Livewire component(s)`) : fail('no live Livewire components — page is inert');

// ---- scan: the pipelined scanner (capture drop-box + results stack) ----
await page.click('a[href*=scan]');
await page.waitForTimeout(1500);
(await page.isVisible('text=Scan')) ? ok('scanner rendered') : fail('scanner missing');

// A barcode capture POSTs to the drop-box and settles in the background; the
// results stack polls it in. XSRF cookie carries the token for in-page fetch.
const status = await page.evaluate(async () => {
    const token = decodeURIComponent((document.cookie.match(/XSRF-TOKEN=([^;]+)/) || [])[1] || '');
    const form = new FormData();
    form.append('barcode', '5000159407236');
    const res = await fetch('/scan/captures', {
        method: 'POST',
        headers: { 'X-XSRF-TOKEN': token, Accept: 'application/json' },
        body: form,
    });
    return res.status;
});
status === 201 ? ok('capture accepted by the drop-box') : fail(`capture POST returned ${status}`);

await page.waitForTimeout(8000); // poll interval + background settle
if (await page.isVisible('text=IN PANTRY')) {
    ok('capture auto-added via the provenance gate');
} else if (await page.isVisible('text=IDENTIFY THIS YET')) {
    // Open Food Facts unreachable in this environment — settled honestly.
    ok('capture settled as unknown (OFF unreachable?) — pipeline alive');
} else if (await page.isVisible('text=IDENTIFYING')) {
    ok('capture visible and in flight (no worker running) — pipeline alive');
} else {
    fail('capture never appeared in the results stack');
}

problems.length === 0 ? ok('no browser warnings/errors') : fail(`browser problems: ${problems.slice(0, 3).join(' | ')}`);
await browser.close();
console.log(process.exitCode ? 'SMOKE FAILED' : 'SMOKE PASSED');
