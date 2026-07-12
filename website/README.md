# Dukandaar marketing site

Source for **fahadai.isupercoder.com** (landing page, changelog, contact).
Static HTML/CSS, no build step. `website/` maps to the web root on the server.

## Structure

```
website/
  index.html        # landing page (/)
  changelog.html    # release notes (/changelog.html), mirrors ../readme.txt
  contact.html      # support & contact (/contact.html)
  robots.txt
  sitemap.xml
  og-image-1200x630.png   # social preview (generate; referenced by OG/Twitter tags)
  tests/            # zero-dependency Node checks (link integrity + on-page SEO)
```

Design: dark "engineered trust" editorial, Fraunces display + JetBrains Mono receipts
+ a highlighter-lime accent. The positioning leads with the product's thesis: a grounded,
open-source, 100%-test-covered assistant that "shows its work."

## Tests

```
npm run test:website          # links + SEO
npm run test:website:links    # check-links.mjs: every internal link resolves to a file/anchor
npm run test:website:seo      # check-seo.mjs: title/description/canonical/OG/Twitter/JSON-LD/h1, no em/en dash in title+description
```

Run from the repo root. The checkers treat `website/` as the web root and gate CI
(non-zero exit on any broken link or missing SEO element).

## Deploy

Served by nginx from `/var/www/fahadai/` on the production host (207.244.253.120).

Mirror the served content with rsync, excluding tooling (tests, build scripts, README):

```
rsync -az --delete -o StrictHostKeyChecking=no \
  --exclude tests/ --exclude '*.mjs' --exclude README.md --exclude node_modules/ \
  website/ root@207.244.253.120:/var/www/fahadai/
```

The build script `apply-fixes.mjs` (idempotent audit fixes) and `tests/og-card.html`
(OG image source, rendered to `og-image-1200x630.png` with headless Chrome at 1200x630)
are generators, not served assets, so they are excluded above.

First-time setup (one-off): create the nginx server block for `fahadai.isupercoder.com`
pointing at `/var/www/fahadai`, add the DNS A record (`fahadai` -> 207.244.253.120) on
Namecheap, then issue SSL:

```
certbot certonly --webroot -w /var/www/certbot -d fahadai.isupercoder.com --non-interactive --agree-tos -m info@fahdmurtaza.com
```
