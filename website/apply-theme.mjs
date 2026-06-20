// Add a warm-daylight light mode + theme toggle across every marketing page.
// Idempotent — safe to re-run. Run: node website/apply-theme.mjs
import { readFileSync, writeFileSync, readdirSync, statSync } from 'node:fs';
import { join, relative, sep, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';

const ROOT = dirname(fileURLToPath(import.meta.url));

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

// Light-theme variable remap + overrides for the hard-coded dark colours, plus
// toggle-button styling. Appended to every page's <style> once.
const THEME_CSS = `
  /* theme: light mode (warm daylight) */
  [data-theme="light"]{
    color-scheme:light;
    --ink:#f4f1e8;        /* page background, warm paper */
    --ink-2:#fcfaf4;      /* raised surface / cards */
    --ink-3:#e9e3d5;      /* inset chip */
    --paper:#1b1a17;      /* primary text, warm near-black */
    --paper-soft:#494640; /* secondary text */
    --paper-dim:#6c685f;  /* tertiary / mono dim */
    --lime-deep:#4f6a10;  /* accent text + thin bars */
    --lime-text:#4f6a10;  /* lime used as text */
    --line:rgba(22,20,14,.14);
    --line-2:rgba(22,20,14,.05);
    --shadow:0 24px 60px -30px rgba(70,58,28,.30);
  }
  /* hard-coded dark colours that don't flow through variables */
  [data-theme="light"] header{background:rgba(244,241,232,.82)}
  [data-theme="light"] .btn:hover{border-color:rgba(22,20,14,.28)}
  [data-theme="light"] .mock{background:linear-gradient(180deg,#fcfaf4,#f1ece0)}
  [data-theme="light"] .mock-top{background:rgba(22,20,14,.02)}
  [data-theme="light"] .bub.bot{background:#eef6d6;border-color:rgba(120,150,30,.40)}
  [data-theme="light"] .card{background:rgba(22,20,14,.03)}
  [data-theme="light"] .card .ph{background:linear-gradient(135deg,#e7e1d3,#d6d0c0)}
  [data-theme="light"] code{background:rgba(22,20,14,.05)}
  [data-theme="light"] pre{background:#f7f3ea}
  /* theme toggle */
  .theme-toggle{display:inline-flex;align-items:center;justify-content:center;width:38px;height:38px;margin-left:8px;border-radius:10px;border:1px solid var(--line);background:transparent;color:var(--paper-soft);cursor:pointer;flex-shrink:0;transition:color .18s,border-color .18s}
  .theme-toggle:hover{color:var(--paper);border-color:var(--paper-dim)}
  .theme-toggle svg{width:17px;height:17px;display:block}
  .theme-toggle .i-moon{display:none}
  [data-theme="light"] .theme-toggle .i-sun{display:none}
  [data-theme="light"] .theme-toggle .i-moon{display:block}`;

const NOFLASH = `<script>(function(){try{var t=localStorage.getItem('fa-theme')||(matchMedia('(prefers-color-scheme: light)').matches?'light':'dark');document.documentElement.setAttribute('data-theme',t);}catch(e){}})();</script>`;

const TOGGLE_BTN = `<button class="theme-toggle" type="button" aria-label="Toggle light and dark theme" title="Toggle theme"><svg class="i-sun" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="4.2"/><path d="M12 2.5v2.2M12 19.3v2.2M2.5 12h2.2M19.3 12h2.2M4.9 4.9l1.6 1.6M17.5 17.5l1.6 1.6M19.1 4.9l-1.6 1.6M6.5 17.5l-1.6 1.6"/></svg><svg class="i-moon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 14.4A8 8 0 0 1 9.6 4 7 7 0 1 0 20 14.4z"/></svg></button>`;

const TOGGLE_JS = `<script>(function(){function s(t){document.documentElement.setAttribute('data-theme',t);try{localStorage.setItem('fa-theme',t);}catch(e){}document.querySelectorAll('.theme-toggle').forEach(function(b){b.setAttribute('aria-pressed',String(t==='light'));});}document.addEventListener('click',function(e){var b=e.target.closest&&e.target.closest('.theme-toggle');if(!b)return;var c=document.documentElement.getAttribute('data-theme')==='light'?'light':'dark';s(c==='light'?'dark':'light');});var c0=document.documentElement.getAttribute('data-theme')==='light'?'light':'dark';document.querySelectorAll('.theme-toggle').forEach(function(b){b.setAttribute('aria-pressed',String(c0==='light'));});})();</script>`;

let changed = 0;
for (const file of walk(ROOT)) {
  const rel = relative(ROOT, file).split(sep).join('/');
  let h = readFileSync(file, 'utf8');
  const before = h;

  // 1. lime-as-text -> themeable var (dark unchanged via fallback). Safe & idempotent.
  h = h.replace(/color:var\(--lime\)/g, 'color:var(--lime-text,var(--lime))');

  // 2. light-theme CSS block (once)
  if (!h.includes('theme: light mode')) h = h.replace('</style>', THEME_CSS + '\n</style>');

  // 3. no-flash head script right after charset (once)
  if (!h.includes("localStorage.getItem('fa-theme')")) {
    h = h.replace('<meta charset="utf-8">', '<meta charset="utf-8">\n' + NOFLASH);
  }

  // 4. advertise both schemes to the UA
  h = h.replace('<meta name="color-scheme" content="dark">', '<meta name="color-scheme" content="dark light">');

  // 5. toggle button as last child of header .nav (once)
  if (!h.includes('class="theme-toggle"')) {
    h = h.replace(/<\/div>(\s*)<\/header>/, TOGGLE_BTN + '</div>$1</header>');
  }

  // 6. toggle behaviour before </body> (once)
  if (!h.includes("e.target.closest&&e.target.closest('.theme-toggle')")) {
    h = h.replace('</body>', TOGGLE_JS + '\n</body>');
  }

  if (h !== before) { writeFileSync(file, h); changed++; console.log('themed: ' + rel); }
}
console.log(`\n${changed} files updated.`);
