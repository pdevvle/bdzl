// Screenshot the REAL site, not a mock.
//
//   node live.mjs [path] [outPrefix]
//   node live.mjs /                         -> live-home-desktop.png, -mobile.png
//   node live.mjs /product/some-kit product
//
// Chromium cannot reach the network on its own in this sandbox; it has to be
// pointed at the agent proxy, which is what the `proxy` launch option below
// does. With that, the live site loads and can be inspected directly — which
// catches things no local mock can, because the mock has no Astra, no Astra
// Pro and no WooCommerce. The missing-underline bug (Astra's
// `underline-content-links` outspecifying the homepage block's own `.bk a`)
// was invisible locally and obvious here.
//
// Every request carries a cache-buster: Breeze serves a cached homepage, and
// a change to the customizer CSS does NOT purge it, so without ?cb= you can
// screenshot a stale page and believe a fix failed.
import { chromium } from '/opt/node22/lib/node_modules/playwright/index.mjs';

const path = process.argv[2] ?? '/';
const prefix = process.argv[3] ?? 'live' + (path === '/' ? '-home' : path.replace(/\W+/g, '-'));
const proxy = process.env.HTTPS_PROXY || process.env.https_proxy;
if (!proxy) throw new Error('HTTPS_PROXY is not set; Chromium cannot reach the site without it');

const b = await chromium.launch({ proxy: { server: proxy }, args: ['--ignore-certificate-errors'] });
for (const [label, width] of [['desktop', 1440], ['mobile', 390]]) {
  const p = await b.newPage({ viewport: { width, height: 900 }, ignoreHTTPSErrors: true });
  const url = 'https://bedazzlekits.com' + path + (path.includes('?') ? '&' : '?') + 'cb=' + Date.now();
  const r = await p.goto(url, { waitUntil: 'load', timeout: 60000 });
  await p.evaluate(() => document.querySelectorAll('img[loading="lazy"]').forEach(i => { i.loading = 'eager'; }));
  await p.evaluate(async () => {
    for (let y = 0; y < document.body.scrollHeight; y += 700) {
      window.scrollTo(0, y); await new Promise(r => setTimeout(r, 140));
    }
    window.scrollTo(0, 0);
  });
  await p.waitForTimeout(2500);
  const overflow = await p.evaluate(() => document.documentElement.scrollWidth > window.innerWidth + 1);
  const broken = await p.evaluate(() => [...document.images].filter(i => !i.naturalWidth).map(i => i.src));
  console.log(`${label.padEnd(8)} ${r.status()}  h-overflow: ${overflow}  broken images: ${broken.length}`);
  broken.forEach(s => console.log('   ' + s));
  await p.screenshot({ path: `${prefix}-${label}.png`, fullPage: true });
  await p.close();
}
await b.close();
