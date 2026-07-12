#!/usr/bin/env node
// On-page SEO test for the marketing site.
// Fails (exit 1) if any page is missing required on-page SEO elements.
// Treats `website/` as the web root and derives the canonical URL from each
// file's path, then asserts title/description/canonical/OG/Twitter/JSON-LD/h1.
import { readFileSync, readdirSync, statSync } from 'node:fs';
import { join, dirname, resolve, relative, sep } from 'node:path';
import { fileURLToPath } from 'node:url';

const ROOT = resolve(dirname(fileURLToPath(import.meta.url)), '..'); // website/
const ORIGIN = 'https://fahadai.isupercoder.com';
const DASH = /[, -]/; // em dash / en dash, banned in title + description

function walk(dir) {
  const out = [];
  for (const name of readdirSync(dir)) {
    if (name === 'tests' || name === 'node_modules') continue;
    const p = join(dir, name);
    if (statSync(p).isDirectory()) out.push(...walk(p));
    else if (name.endsWith('.html')) out.push(p);
  }
  return out;
}

// file path -> expected canonical URL
function canonicalFor(file) {
  let rel = relative(ROOT, file).split(sep).join('/');
  if (rel === 'index.html') return `${ORIGIN}/`;
  if (rel === 'docs/index.html') return `${ORIGIN}/docs/`;
  return `${ORIGIN}/${rel}`;
}

const decode = s => (s || '')
  .replace(/&amp;/g, '&').replace(/&lt;/g, '<').replace(/&gt;/g, '>')
  .replace(/&quot;/g, '"').replace(/&#0?39;|&apos;/g, "'").replace(/&middot;/g, '·');

const countMatches = (html, re) => (html.match(re) || []).length;
const attr = (tag, name) => {
  const m = tag.match(new RegExp(`${name}\\s*=\\s*"([^"]*)"`, 'i'));
  return m ? m[1] : null;
};
const metaContent = (html, key, kind = 'name') => {
  const re = new RegExp(`<meta[^>]*\\b${kind}\\s*=\\s*"${key}"[^>]*>`, 'i');
  const m = html.match(re);
  return m ? attr(m[0], 'content') : null;
};

const files = walk(ROOT);
const errors = [];
for (const file of files) {
  const rel = relative(ROOT, file);
  const html = readFileSync(file, 'utf8');
  const fail = msg => errors.push(`${rel}: ${msg}`);

  // 1. lang + viewport
  if (!/<html[^>]*\blang\s*=\s*"[a-z-]+"/i.test(html)) fail('missing <html lang>');
  if (!/<meta[^>]*name\s*=\s*"viewport"/i.test(html)) fail('missing viewport meta');

  // 2. title
  const titleN = countMatches(html, /<title>/gi);
  const titleM = html.match(/<title>([\s\S]*?)<\/title>/i);
  if (titleN !== 1 || !titleM) fail(`expected exactly one <title> (found ${titleN})`);
  else {
    const t = decode(titleM[1].trim());
    if (t.length < 15 || t.length > 65) fail(`title length ${t.length} (want 15-65): "${t}"`);
    if (DASH.test(t)) fail(`title contains em/en dash: "${t}"`);
  }

  // 3. meta description
  const desc = metaContent(html, 'description');
  if (!desc) fail('missing meta description');
  else {
    const d = decode(desc.trim());
    if (d.length < 110 || d.length > 165) fail(`description length ${d.length} (want 110-165)`);
    if (DASH.test(d)) fail('description contains em/en dash');
  }

  // 4. canonical (absolute, correct path)
  const canonM = html.match(/<link[^>]*rel\s*=\s*"canonical"[^>]*>/i);
  const canon = canonM ? attr(canonM[0], 'href') : null;
  const want = canonicalFor(file);
  if (!canon) fail('missing canonical');
  else if (canon !== want) fail(`canonical "${canon}" !== expected "${want}"`);

  // 5. Open Graph
  for (const k of ['og:title', 'og:description', 'og:type', 'og:url', 'og:image']) {
    const v = metaContent(html, k, 'property');
    if (!v || !v.trim()) fail(`missing/empty ${k}`);
    if (k === 'og:url' && v && v !== want) fail(`og:url "${v}" !== canonical "${want}"`);
  }

  // 6. Twitter card
  if (!metaContent(html, 'twitter:card')) fail('missing twitter:card');

  // 7. JSON-LD (>=1, all valid JSON)
  const ld = [...html.matchAll(/<script[^>]*type\s*=\s*"application\/ld\+json"[^>]*>([\s\S]*?)<\/script>/gi)];
  if (ld.length === 0) fail('missing JSON-LD structured data');
  ld.forEach((m, i) => { try { JSON.parse(m[1].trim()); } catch { fail(`JSON-LD block #${i + 1} is invalid JSON`); } });

  // 8. exactly one h1
  const h1 = countMatches(html, /<h1[\s>]/gi);
  if (h1 !== 1) fail(`expected exactly one <h1> (found ${h1})`);
}

// Site-level: robots.txt + sitemap.xml
import { existsSync } from 'node:fs';
const robotsPath = join(ROOT, 'robots.txt');
const sitemapPath = join(ROOT, 'sitemap.xml');
if (!existsSync(robotsPath)) errors.push('robots.txt: missing');
else {
  const robots = readFileSync(robotsPath, 'utf8');
  if (!/Sitemap:\s*https:\/\/fahadai\.isupercoder\.com\/sitemap\.xml/i.test(robots))
    errors.push('robots.txt: missing Sitemap directive');
}
if (!existsSync(sitemapPath)) errors.push('sitemap.xml: missing');
else {
  const sm = readFileSync(sitemapPath, 'utf8');
  if (!/<urlset[^>]*xmlns=/i.test(sm)) errors.push('sitemap.xml: missing <urlset xmlns>');
  const locs = new Set([...sm.matchAll(/<loc>([^<]+)<\/loc>/g)].map(m => m[1].trim()));
  for (const file of files) {
    const want = canonicalFor(file);
    if (!locs.has(want)) errors.push(`sitemap.xml: missing <loc> for ${want}`);
  }
}

if (errors.length) {
  console.error(`✗ ${errors.length} SEO problem(s):`);
  for (const e of errors.sort()) console.error('  ' + e);
  process.exit(1);
}
console.log(`✓ On-page SEO valid (${files.length} HTML files checked).`);
