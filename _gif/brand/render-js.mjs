import { chromium } from 'playwright';
import { fileURLToPath } from 'url';
import path from 'path';
import fs from 'fs';

// args: htmlFile width height frames outDir
const [html, W, H, FRAMES, outDir] = process.argv.slice(2);
const width = +W, height = +H, frames = +FRAMES;
const dir = path.dirname(fileURLToPath(import.meta.url));
const htmlPath = path.resolve(dir, html);
fs.mkdirSync(outDir, { recursive: true });

const browser = await chromium.launch();
const page = await browser.newPage({ viewport: { width, height }, deviceScaleFactor: 2 });
await page.goto('file://' + htmlPath);
await page.evaluate(async () => { await document.fonts.ready; });
await page.waitForFunction(() => window.__ready === true, null, { timeout: 5000 });
// Kill any live requestAnimationFrame preview loop so it cannot overwrite our
// explicit __draw(t) calls between the evaluate and the screenshot. Frames must
// be driven ONLY by the stepped t below, or the GIF captures wall-clock states.
await page.evaluate(() => { window.requestAnimationFrame = () => 0; });
await page.waitForTimeout(300); // ensure webfonts painted

for (let i = 0; i < frames; i++) {
  const t = i / frames; // 0..1 exclusive -> seamless
  await page.evaluate((tt) => window.__draw(tt), t);
  const n = String(i).padStart(3, '0');
  await page.screenshot({ path: path.join(outDir, `f_${n}.png`) });
}
await browser.close();
console.log(`rendered ${frames} frames -> ${outDir}`);
