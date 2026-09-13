// Screenshot a locally rendered page at three widths and report h-overflow.
//   node shoot.mjs [page.html] [outPrefix]
import { chromium } from '/opt/node22/lib/node_modules/playwright/index.mjs';

const page = process.argv[2] ?? 'preview.html';
const prefix = process.argv[3] ?? page.replace(/\.html$/, '');

const b = await chromium.launch();
for (const [name, width] of [['desktop', 1440], ['tablet', 900], ['mobile', 390]]) {
  const p = await b.newPage({ viewport: { width, height: 900 }, deviceScaleFactor: 0.6 });
  await p.goto('file://' + process.cwd() + '/' + page);
  // fullPage capture does not trigger lazy loading, so force it before shooting
  await p.evaluate(async () => {
    document.querySelectorAll('img[loading="lazy"]').forEach(i => { i.loading = 'eager'; });
    for (let y = 0; y < document.body.scrollHeight; y += 600) {
      window.scrollTo(0, y);
      await new Promise(r => setTimeout(r, 60));
    }
    window.scrollTo(0, 0);
    await Promise.all([...document.images].filter(i => !i.complete)
      .map(i => new Promise(r => { i.onload = i.onerror = r; })));
  });
  await p.waitForTimeout(500);
  const overflow = await p.evaluate(
    () => document.documentElement.scrollWidth > window.innerWidth + 1);
  const broken = await p.evaluate(
    () => [...document.images].filter(i => !i.naturalWidth).map(i => i.src));
  console.log(`${name.padEnd(8)} h-overflow: ${overflow}  broken images: ${broken.length}`);
  broken.forEach(s => console.log('   ' + s));
  await p.screenshot({ path: `${prefix}-${name}.png`, fullPage: true });
}
await b.close();
