# Fahad AI Shopping Assistant for WooCommerce — index

Concise router for this repo. To keep context lean, the detail lives in two focused docs — **read only the one you need.**

- **[docs/plugin.md](docs/plugin.md)** — *this particular plugin*: WordPress/WooCommerce mechanics (file structure, REST endpoints + the public-endpoint security invariant, admin, widget, i18n, tests, WP.org distribution, the zip build).
- **[docs/ai-assistant.md](docs/ai-assistant.md)** — *our AI assistant*: providers, the agentic loop, the tool registry + extensibility, the system prompt + trust guardrails, the privacy/auth boundary, cost controls, and the eval harness.
- **[ROADMAP.md](ROADMAP.md)** — product strategy, the v2.1+ backlog (epic #47), and the release workflow rationale.

## Identity
- Main file: `fahad-ai-shopping-assistant-for-woocommerce.php` · GitHub: https://github.com/fahdi/fahad-ai-shopping-assistant-for-woocommerce
- **Current version: 2.0.2**
- Requires: WordPress 6.0+ (tested up to 7.0), PHP 8.0+ (host dev runs 8.5), WooCommerce active
- Slug / text domain (must match): `fahad-ai-shopping-assistant-for-woocommerce` (WP.org pending — see #67)
- Prefixes: constants `FAHAD_AI_*`, classes `Fahad_AI_*`, functions/hooks/options `fahad_ai_*`, REST `fahad-ai/v1`. Full conventions table in [docs/plugin.md](docs/plugin.md).

## Golden rules (always apply)
1. **TDD — no feature without tests.** Tests first (red → green): unit tests always; an eval/golden case when behavior/answers change. Full suite green before merge; no new PHPCS or PHP-deprecation noise (host is 8.5 — don't call `ReflectionMethod::setAccessible()`).
2. **Trust guardrails are absolute.** No fake scarcity, respect stated budget, disclose upsells, ground every fact or abstain, never block human support. Enforced by the eval checkers; anti-features are ROADMAP §6.
3. **Security invariant — don't regress.** The public `/message` + `/stream` endpoints keep the `wp_rest` nonce **and** the per-client rate limit. Personal-data tools add the `Fahad_AI_Auth` boundary (login gate + per-record ownership) on top.
4. **Commits:** never add a "Generated with Claude" line or a `Co-Authored-By` trailer.

## Release workflow — EVERY plugin PR
No exceptions for code/feature PRs:
1. **TDD** per rule 1 above.
2. **Bump the version (semver)** — patch for fixes, minor for features — in all four places: the main-file header `Version:`, `FAHAD_AI_VERSION`, `readme.txt` `Stable tag`, and this file's "Current version".
3. **Document the change** — add a `readme.txt` changelog entry **and** an upgrade-notice entry; update `docs/*` / `ROADMAP.md` / the issue checkbox as relevant.
4. **Branch → PR → merge to `main`** (merge commit), suite green.
5. **Build the zip** (`git archive` of HEAD, prune dev/test/docs — see [docs/plugin.md](docs/plugin.md)) and **publish a GitHub release** whose notes describe exactly what changed.
6. Tick the item in epic **#47** (or its child issue).

> Docs/planning-only changes (ROADMAP, CLAUDE.md, `docs/`, issue grooming) don't change the shipped plugin; a release for them is optional and, if cut, its notes must say "no functional plugin change."

## Quick commands
```bash
# Tests (from the repo root)
vendor/bin/phpunit

# Build a release zip (set <version>)
SLUG=fahad-ai-shopping-assistant-for-woocommerce
rm -rf /tmp/fahad-build && mkdir -p /tmp/fahad-build/$SLUG
git archive HEAD | tar -x -C /tmp/fahad-build/$SLUG
( cd /tmp/fahad-build/$SLUG && rm -rf tests .github .gitignore phpunit.xml composer.json composer.lock CLAUDE.md ROADMAP.md README.md docs )
( cd /tmp/fahad-build && zip -rq /Users/isupercoder/Code/github/$SLUG-<version>.zip $SLUG )
```

For WP-CLI against the local demo store, use the Local-bundled binary (host Homebrew WP-CLI on PHP 8.4+ leaks deprecation noise to stdout) — see the project memory note.
