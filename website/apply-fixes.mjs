// One-off: apply the SEO + UI/UX audit fixes uniformly across the marketing site.
// Idempotent, safe to re-run. Run: node website/apply-fixes.mjs
import { readFileSync, writeFileSync, readdirSync, statSync } from 'node:fs';
import { join, dirname, relative, sep } from 'node:path';
import { fileURLToPath } from 'node:url';

const ROOT = dirname(fileURLToPath(import.meta.url));
const ORIGIN = 'https://fahadai.isupercoder.com';

function walk(d) {
  let o = [];
  for (const n of readdirSync(d)) {
    if (n === 'tests') continue;
    const p = join(d, n);
    if (statSync(p).isDirectory()) o = o.concat(walk(p));
    else if (n.endsWith('.html')) o.push(p);
  }
  return o;
}

// Accessibility + motion CSS appended to every page's <style>.
const A11Y_CSS = `
  /* a11y + motion (audit) */
  :root{color-scheme:dark}
  :focus-visible{outline:2px solid var(--lime);outline-offset:3px;border-radius:6px}
  .btn-lime:focus-visible{outline-color:var(--ink);outline-offset:2px}
  .skip{position:absolute;left:-9999px;top:0;z-index:200;background:var(--lime);color:#10130a;padding:10px 16px;border-radius:0 0 10px 0;font-weight:700;text-decoration:none}
  .skip:focus{left:0}
  section[id]{scroll-margin-top:84px}
  .nav .links a,.foot .links a,.side a{min-height:24px}
  @media (prefers-reduced-motion:reduce){
    *{scroll-behavior:auto !important}
    .reveal{animation:none;opacity:1;transform:none}
    .btn-lime:hover,.feat:hover,.c:hover,.stat:hover,#chatbot-toggle:hover{transform:none}
  }`;

const DOC_NAMES = {
  'installation.html': 'Installation', 'configuration.html': 'Configuration', 'ai-providers.html': 'AI providers',
  'how-it-works.html': 'How it works', 'product-search.html': 'Product search', 'cart-and-checkout.html': 'Cart and checkout',
  'wallet-integration.html': 'Wallet integration', 'stock-alerts.html': 'Stock alerts', 'semantic-search.html': 'Semantic search',
  'voice.html': 'Voice', 'whatsapp.html': 'WhatsApp', 'multilingual.html': 'Multilingual', 'proactive-nudges.html': 'Proactive nudges',
  'merchant-copilot.html': 'Merchant copilot', 'agent-endpoints.html': 'Agent endpoints', 'rest-api.html': 'REST API',
  'hooks-filters.html': 'Hooks and filters', 'trust-and-privacy.html': 'Trust and privacy', 'troubleshooting.html': 'Troubleshooting',
};

let changed = 0;
for (const file of walk(ROOT)) {
  const rel = relative(ROOT, file).split(sep).join('/');
  let h = readFileSync(file, 'utf8');
  const before = h;

  // 1. a11y CSS (once)
  if (!h.includes('a11y + motion (audit)')) h = h.replace('</style>', A11Y_CSS + '\n</style>');

  // 2. favicon + color-scheme meta (once), after viewport meta
  if (!h.includes('rel="icon"')) {
    h = h.replace(/(<meta name="viewport"[^>]*>)/, `$1\n<meta name="color-scheme" content="dark">\n<link rel="icon" href="/favicon.svg" type="image/svg+xml">`);
  }

  // 3. skip link as first body child (once)
  if (!h.includes('class="skip"')) h = h.replace(/<body>/, `<body>\n<a class="skip" href="#main">Skip to content</a>`);

  // 4. external links: rel="noopener noreferrer"
  h = h.replace(/<a([^>]*?)href="(https?:\/\/(?!fahadai\.isupercoder\.com)[^"]+)"((?:(?!rel=)[^>])*?)>/g,
    (m, a, url, rest) => /rel=/.test(m) ? m : `<a${a}href="${url}"${rest} rel="noopener noreferrer">`);

  const isDocPage = rel.startsWith('docs/') && rel !== 'docs/index.html';

  if (isDocPage) {
    // 5. docs main landmark: <div class="layout"> -> <main id="main" class="layout">, close </div><footer> -> </main><footer>
    h = h.replace('<div class="layout">', '<main id="main" class="layout">');
    h = h.replace(/<\/div>\s*<footer>/, '</main>\n<footer>');
    // 6. mobile sidebar: keep it visible (override display:none) + horizontal chips
    if (!h.includes('docs mobile sidebar (audit)')) {
      h = h.replace('</style>', `  /* docs mobile sidebar (audit) */\n  @media(max-width:820px){.side{display:block;position:static;padding-top:20px;border-bottom:1px solid var(--line);margin-bottom:6px}.side a{display:inline-block;margin-right:14px}.side .lbl{margin-top:14px}}\n</style>`);
    }
    // 7. BreadcrumbList JSON-LD (once)
    const fname = rel.split('/').pop();
    const name = DOC_NAMES[fname] || 'Page';
    if (!h.includes('"BreadcrumbList"')) {
      const bc = `<script type="application/ld+json">\n{"@context":"https://schema.org","@type":"BreadcrumbList","itemListElement":[{"@type":"ListItem","position":1,"name":"Home","item":"${ORIGIN}/"},{"@type":"ListItem","position":2,"name":"Docs","item":"${ORIGIN}/docs/"},{"@type":"ListItem","position":3,"name":"${name}","item":"${ORIGIN}/docs/${fname}"}]}\n</script>`;
      h = h.replace('</head>', bc + '\n</head>');
    }
  } else {
    // 8. marketing pages: id="main" on the first <main>
    h = h.replace(/<main(?![^>]*id=)/, '<main id="main"');
  }

  if (h !== before) { writeFileSync(file, h); changed++; console.log('fixed: ' + rel); }
}
console.log(`\n${changed} files updated.`);
