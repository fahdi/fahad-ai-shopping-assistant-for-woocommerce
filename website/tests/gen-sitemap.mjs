// Regenerate sitemap.xml with accurate <lastmod> (from each page's last git
// commit date) and no deprecated <priority>/<changefreq> tags. Idempotent.
// Run from the website/ dir: node tests/gen-sitemap.mjs
import { readdirSync, statSync, writeFileSync } from 'node:fs';
import { join, relative, sep, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';
import { execSync } from 'node:child_process';

const ROOT = dirname(dirname(fileURLToPath(import.meta.url))); // website/
const ORIGIN = 'https://fahadai.isupercoder.com';

function walk(d) {
  let o = [];
  for (const n of readdirSync(d)) {
    if (n === 'tests' || n === 'node_modules') continue;
    const p = join(d, n);
    if (statSync(p).isDirectory()) o = o.concat(walk(p));
    else if (n.endsWith('.html')) o.push(p);
  }
  return o;
}

function urlFor(rel) {
  if (rel === 'index.html') return ORIGIN + '/';
  if (rel === 'docs/index.html') return ORIGIN + '/docs/';
  return ORIGIN + '/' + rel;
}

function lastmod(file) {
  // Last commit date that touched this file; fall back to file mtime.
  try {
    const d = execSync(`git log -1 --format=%cs -- "${file}"`, { cwd: ROOT, encoding: 'utf8' }).trim();
    if (d) return d;
  } catch { /* not committed yet */ }
  return statSync(file).mtime.toISOString().slice(0, 10);
}

const urls = walk(ROOT)
  .map((f) => ({ url: urlFor(relative(ROOT, f).split(sep).join('/')), mod: lastmod(f) }))
  .sort((a, b) => (a.url === ORIGIN + '/' ? -1 : b.url === ORIGIN + '/' ? 1 : a.url.localeCompare(b.url)));

const body = urls
  .map((u) => `  <url>\n    <loc>${u.url}</loc>\n    <lastmod>${u.mod}</lastmod>\n  </url>`)
  .join('\n');

const xml = `<?xml version="1.0" encoding="UTF-8"?>
<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">
${body}
</urlset>
`;

writeFileSync(join(ROOT, 'sitemap.xml'), xml);
console.log(`sitemap.xml regenerated: ${urls.length} URLs with <lastmod>, no <priority>.`);
