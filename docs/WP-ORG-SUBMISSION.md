# WordPress.org submission — prep & runbook (issue #67)

> **Status: BLOCKED on an owner action.** The directory slug
> `fahad-ai-shopping-assistant-for-woocommerce` is still in WordPress.org review, and
> committing to the plugin SVN requires the owner's wordpress.org account + the approved
> slug. This document is the ready-to-run package + reviewer reply so the submission is a
> mechanical step the moment the slug clears. Nothing here can be executed from this repo/CI.

## What's already compliant (verified)
- **Text domain == slug:** `fahad-ai-shopping-assistant-for-woocommerce` (header + every `__()`).
- **Prefixes:** `FAHAD_AI_` / `fahad_ai_` (≥4 chars, distinct) — passes the unique-prefix rule.
- **`readme.txt`:** valid header (Contributors, Tags, Requires at least, Tested up to 7.0, Requires PHP, Stable tag matches the release), Description / Installation / FAQ / Changelog / Upgrade Notice sections, and the **External services** disclosure (Anthropic + Moonshot endpoints + privacy links).
- **Direct cURL** (the Moonshot SSE handle in `class-api-handler.php`) is wrapped in `phpcs:disable/enable` with the justification comment the reviewer asked for.
- **No inline `<script>`** (admin JS is enqueued); `Requires Plugins: woocommerce` header present.
- **Distribution zip excludes** dev/test/CI: `.git`, `.github`, `tests`, `vendor`, `docs`, `CLAUDE.md`, `ROADMAP.md`, `README.md`, `composer.*`, `phpunit.xml`, `.gitignore` (see the build command in [plugin.md](plugin.md)).

## Prerequisite (owner)
1. The slug must be **approved** by the Plugin Directory (the pending step).
2. Owner has SVN access to `https://plugins.svn.wordpress.org/fahad-ai-shopping-assistant-for-woocommerce/`.

## SVN runbook (once the slug is approved)
```bash
SLUG=fahad-ai-shopping-assistant-for-woocommerce
VER=<latest release, e.g. 2.7.0>

# 1. Build the clean zip (see plugin.md) and unzip it to a staging dir, OR use the
#    git-archive export directly as trunk contents.
svn checkout https://plugins.svn.wordpress.org/$SLUG/ svn-$SLUG
cd svn-$SLUG

# 2. Replace trunk with the release files (the zip's top-level folder contents).
rsync -a --delete --exclude='.svn' /path/to/unzipped/$SLUG/ trunk/

# 3. Tag the release.
svn cp trunk tags/$VER

# 4. Store Wance/banner/icon assets (if any) under assets/ (NOT shipped in the plugin).
#    Screenshots referenced in readme.txt go here as screenshot-1.png, etc.

# 5. Set the stable tag in trunk/readme.txt to $VER (already set in the repo).

svn add --force trunk tags assets 2>/dev/null
svn status
svn commit -m "Release $VER"
```

## Reviewer reply template (brief — no AI fluff; reviewers flag that)
> Thanks for the review. Addressed:
> - Slug/prefix: final slug `fahad-ai-shopping-assistant-for-woocommerce`; all code uses the `fahad_ai_`/`FAHAD_AI_` prefix and the text domain matches the slug.
> - cURL: the only direct cURL is the Moonshot SSE streaming handle; it's documented inline (phpcs justification) because `wp_remote_post()` buffers the full body and the `http_api_curl` override proved unreliable for SSE.
> - Auth: the public `/message` and `/stream` endpoints pair the `wp_rest` nonce with per-client rate limiting (billable AI + cart mutation).
> - External services: Anthropic + Moonshot are disclosed in readme.txt with privacy-policy links; only conversation + relevant product data are sent.
>
> Please let me know if anything else is needed.

## After it's live
- Tick #67, note the directory URL, and (optional) add the WordPress.org badge/links to README.md.
