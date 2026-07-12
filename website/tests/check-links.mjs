#!/usr/bin/env node
// Link-integrity test for the marketing site.
// Fails (exit 1) if any internal link points to a missing file or anchor.
// Treats `website/` as the web root: `/foo.html` -> website/foo.html,
// `/docs/` -> website/docs/index.html, relative links resolve from the file's dir.
import { readFileSync, readdirSync, statSync, existsSync } from 'node:fs';
import { join, dirname, resolve, relative } from 'node:path';
import { fileURLToPath } from 'node:url';

const ROOT = resolve(dirname(fileURLToPath(import.meta.url)), '..'); // website/
const SELF_HOST = 'getdukandar.com';

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

const idCache = new Map();
function idsOf(file) {
  if (idCache.has(file)) return idCache.get(file);
  const ids = new Set();
  if (existsSync(file)) {
    const html = readFileSync(file, 'utf8');
    for (const m of html.matchAll(/\bid\s*=\s*"([^"]+)"/g)) ids.add(m[1]);
    for (const m of html.matchAll(/\bname\s*=\s*"([^"]+)"/g)) ids.add(m[1]);
  }
  idCache.set(file, ids);
  return ids;
}

// Resolve an href + containing file to a local path, or {skip:true} for
// external/non-checkable links (other domains, mailto, tel, js, data).
function resolveHref(href, fromFile) {
  let h = href.trim();
  if (/^(https?:)?\/\//i.test(h)) {
    const m = h.match(new RegExp(`^https?://${SELF_HOST.replace(/\./g, '\\.')}(/[^"]*)?$`, 'i'));
    if (!m) return { skip: true };           // genuinely external
    h = m[1] || '/';                          // own-domain absolute -> treat as path
  } else if (/^(mailto:|tel:|javascript:|data:|#$)/i.test(h)) {
    return { skip: true };
  }
  let [pathPart, anchor] = h.split('#');
  pathPart = pathPart.split('?')[0];
  let targetFile;
  if (pathPart === '') {
    targetFile = fromFile;                    // pure in-page anchor
  } else if (pathPart.startsWith('/')) {
    let rel = pathPart.replace(/^\/+/, '');
    if (rel === '' || rel.endsWith('/')) rel += 'index.html';
    targetFile = join(ROOT, rel);
  } else {
    let rel = pathPart;
    if (rel.endsWith('/')) rel += 'index.html';
    targetFile = resolve(dirname(fromFile), rel);
  }
  return { targetFile, anchor };
}

const files = walk(ROOT);
const broken = [];
for (const file of files) {
  const html = readFileSync(file, 'utf8');
  for (const m of html.matchAll(/\bhref\s*=\s*"([^"]*)"/g)) {
    const href = m[1];
    if (href.trim() === '' || href.trim() === '#') {
      broken.push(`${relative(ROOT, file)} -> "${href}"  (dead placeholder link)`);
      continue;
    }
    const res = resolveHref(href, file);
    if (res.skip) continue;
    const rel = relative(ROOT, file);
    if (!existsSync(res.targetFile)) {
      broken.push(`${rel} -> ${href}  (missing file)`);
    } else if (res.anchor && !idsOf(res.targetFile).has(res.anchor)) {
      broken.push(`${rel} -> ${href}  (missing anchor #${res.anchor})`);
    }
  }
}

if (broken.length) {
  console.error(`✗ ${broken.length} broken internal link(s):`);
  for (const b of broken.sort()) console.error('  ' + b);
  process.exit(1);
}
console.log(`✓ All internal links resolve (${files.length} HTML files checked).`);
