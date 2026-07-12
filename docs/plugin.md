# Plugin engineering, Dukandaar AI Shopping Assistant for WooCommerce

> "This particular plugin": WordPress/WooCommerce mechanics, structure, conventions, REST, admin, i18n, tests, distribution. For the assistant brain (providers, agentic loop, tools, prompt, guardrails, eval), see [ai-assistant.md](ai-assistant.md). For strategy/backlog, see [../ROADMAP.md](../ROADMAP.md).

## File structure

```
dukandaar-ai-shopping-assistant-for-woocommerce/
├── dukandaar-ai-shopping-assistant-for-woocommerce.php   # Bootstrap: header, Dukandaar_Chatbot, REST routes, asset enqueue; glob-loads includes/tools/*.php
├── includes/
│   ├── class-api-handler.php      # Dukandaar_API_Handler, agent loops, provider calls, SSE streaming, prompt, cards/comparison, cost controls
│   ├── class-tools.php            # Dukandaar_Tools, 5 built-in WooCommerce tools; execute() delegates to the registry
│   ├── class-tool-registry.php    # Dukandaar_Tool_Registry, singleton: specs(), dispatch(), register_pack(), get_tools(); layers built-ins → packs → dukandaar_register_tools filter
│   ├── class-auth.php             # Dukandaar_Auth, privacy/auth boundary for personal-data tools (login gate + per-record ownership + mask_email)
│   ├── admin-settings.php         # dukandaar_settings_page(), provider, keys, widget config
│   └── tools/                     # Drop-in feature packs (auto-loaded by glob); each self-registers via register_pack()
│       ├── class-catalog-tools.php        # best-sellers, categories
│       ├── class-reviews-tools.php        # product reviews/ratings
│       ├── class-comparison-tools.php     # side-by-side comparison
│       ├── class-coupon-tools.php         # list/apply coupons
│       ├── class-recommendation-tools.php # recommendations, cross-sells
│       ├── class-order-tools.php          # order status (personal)
│       ├── class-wallet-tools.php         # wallet balance/top-up (personal, decoupled provider)
│       ├── class-shipping-tools.php       # delivery estimate
│       └── class-memory-tools.php         # opt-in cross-session memory (personal)
├── assets/
│   ├── js/chatbot.js              # Frontend widget, vanilla JS, IIFE, no deps; SSE parsing, cards, comparison, variation selects, markdown
│   ├── js/admin-settings.js       # Admin provider toggle
│   └── css/chatbot.css            # Widget styles (CSS custom properties)
├── tests/
│   ├── bootstrap.php              # PHPUnit bootstrap: stubs + plugin classes + glob-loads tool packs
│   ├── stubs/wc-stubs.php         # WP_Error, WC_Product, WC_Cart, WC_Coupon, WC_Order, shipping-zone stubs
│   ├── unit/                      # Per-class unit tests (Brain\Monkey + Mockery)
│   └── eval/                      # Offline eval harness: golden conversations + grounding/guardrail checkers (see ai-assistant.md)
├── languages/                     # Placeholder; WP.org auto-loads translations
├── readme.txt                     # WordPress.org format (the canonical changelog)
├── uninstall.php                  # Deletes all dukandaar_* options
├── CLAUDE.md                      # Router/index (see repo root)
├── docs/                          # This folder
└── ROADMAP.md                     # Product strategy + backlog (excluded from the zip)
```

## Naming conventions

| Concept | Pattern | Examples |
|---|---|---|
| PHP constants | `DUKANDAAR_*` | `DUKANDAAR_VERSION`, `DUKANDAAR_PATH` |
| PHP classes | `Dukandaar_*` | `Dukandaar_API_Handler`, `Dukandaar_Tool_Registry`, `Dukandaar_Auth` |
| Functions / hooks / options | `dukandaar_*` | `dukandaar_register_tools`, `dukandaar_provider` |
| REST namespace | `dukandaar/v1` | |
| JS handle / localized var | `dukandaar-chatbot` / `window.dukandaarChatbot` | |
| Text domain | `dukandaar-ai-shopping-assistant-for-woocommerce` (== slug) | |

## REST API

**Namespace:** `dukandaar/v1`. **Gate:** `Dukandaar_Chatbot::authorize_request()` (the `permission_callback`).

| Endpoint | Method | Handler | Provider |
|---|---|---|---|
| `/message` | POST | `handle_message()` | Anthropic (non-streaming) |
| `/stream` | POST | `handle_stream()` | Moonshot (SSE streaming) |

**Security invariant, do NOT regress.** Both endpoints are intentionally public so guests can use the assistant, so the `wp_rest` nonce is CSRF protection, not authorization. WP.org review (2026-06-12) required more for endpoints that trigger billable AI + cart mutation. `authorize_request()` therefore enforces **both**: `wp_verify_nonce(…, 'wp_rest')` (403) and `is_rate_limited()` (429, transient fixed-window keyed on user id or `REMOTE_ADDR`, never `X-Forwarded-For`; defaults 20/60s, filterable via `dukandaar_rate_limit` / `dukandaar_rate_window`). Keep both. Personal-data tools add a *second* boundary on top, see [ai-assistant.md](ai-assistant.md) (Privacy & auth).

`handle_stream()` bypasses WP REST buffering: `add_filter('rest_pre_serve_request','__return_true')`, then `header('Content-Type: text/event-stream')` + `X-Accel-Buffering: no`.

**Cart session in REST (two gotchas, both load-bearing):**
1. WooCommerce doesn't init the cart for REST requests, `handle_message()` and `handle_stream()` call `wc_load_cart()` before any tool runs.
2. **Guest cart persistence (v2.0.1, finding #31):** the streaming endpoint must `prime_cart_session()` (load cart + `WC()->session->set_customer_session_cookie(true)`) **before** flushing the SSE headers, once `text/event-stream` headers are sent, WooCommerce can no longer emit its `Set-Cookie`, orphaning a guest's cart. Never move the cart priming after the `header()` calls.

## Frontend widget

`assets/js/chatbot.js`, vanilla JS, IIFE, no deps. Config injected via `wp_localize_script` as `window.dukandaarChatbot` (`apiUrl`, `streamUrl`, `provider`, `nonce`, `botName`, `greeting`, `accentColor`, `i18n.*`). `sendMessage()` routes to `sendStreaming()` (SSE ReadableStream) or `sendRegular()` (JSON) by provider.

**Markdown rendering & currency (v2.0.1, finding #29):** bot text is rendered by `renderMarkdown()`, which first calls `decodeEntities()` (a textarea, decodes as text only, XSS-safe) so HTML entities like `&#8360;` (₨) render as the symbol, then HTML-escapes, then converts same-origin `[text](url)` links, `**bold**`, and newlines. The streaming reader buffers partial SSE frames (split frames previously dropped/corrupted multibyte text).

## WordPress options

All under `dukandaar_`: `provider`, `anthropic_api_key`, `anthropic_model`, `moonshot_api_key`, `moonshot_model`, `moonshot_region` (`global`/`china`), `bot_name`, `greeting`, `system_prompt` (custom; appended), `accent_color`, plus the personal/memory pack's per-user meta. `uninstall.php` removes all `dukandaar_*` options.

## Admin settings

`includes/admin-settings.php` → `dukandaar_settings_page()`. Page hook `settings_page_dukandaar-ai-shopping-assistant-for-woocommerce`. `dukandaar_settings` nonce + `dukandaar_save` submit. All `$_POST` reads use `wp_unslash()` + sanitize. Provider toggle JS is externally enqueued (`assets/js/admin-settings.js`), no inline `<script>` (WP.org).

## Internationalization

Every user-facing string uses the `dukandaar-ai-shopping-assistant-for-woocommerce` text domain. PHP: `__()`/`esc_html__()`/`esc_html_e()` + `/* translators: */` on sprintf. JS: all labels/ARIA/errors/tool-status via `dukandaarChatbot.i18n.*` with `i18n.foo || 'fallback'`. Text domain must equal the slug (WP.org).

## Test suite

**Runner:** `vendor/bin/phpunit` (host PHP works; Local-bundled PHP also fine). **Stack:** PHPUnit 10.5 + Brain\Monkey 2.x + Mockery 1.x. **Coverage:** 338 tests as of 2.0.1, unit (`tests/unit/`) per class + an offline eval suite (`tests/eval/`). Singletons reset between tests via reflection on `Dukandaar_*::$instance`. Host runs PHP 8.5, avoid `ReflectionMethod::setAccessible()` (deprecated no-op). The eval harness is documented in [ai-assistant.md](ai-assistant.md).

## WordPress.org distribution

**Status:** slug `dukandaar-ai-shopping-assistant-for-woocommerce` accepted; **pending final review** (see #67). History (condensed): submitted Apr 2026 as `wc-ai-chatbot` → naming/prefix/cURL/i18n flags → renamed via "Maya" (rejected, brand-like) → final rename to "Dukandaar…"; cURL justified, text-domain fixed, nonce replaced with `authorize_request()` (nonce + rate-limit), Moonshot streaming reverted to a dedicated `curl_init()` handle (the `http_api_curl` override corrupted SSE framing).

**Building the release zip** (allow-list, only runtime files ship):
```bash
cd /Users/isupercoder/Code/github/dukandaar-ai-shopping-assistant-for-woocommerce
SLUG=dukandaar-ai-shopping-assistant-for-woocommerce
rm -rf /tmp/dukandaar-build && mkdir -p /tmp/dukandaar-build/$SLUG
git archive HEAD -- $SLUG.php uninstall.php readme.txt includes assets languages | tar -x -C /tmp/dukandaar-build/$SLUG
cd /tmp/dukandaar-build && zip -rq /Users/isupercoder/Code/github/_dukandaar-builds/$SLUG-<version>.zip $SLUG
```
The zip contains ONLY the runtime allow-list: the main plugin file, `uninstall.php`, `readme.txt`, `includes/`, `assets/`, `languages/`. Everything else in the repo is dev tooling and must not ship, WP.org's automated scanner rejects zips with application/dev files (it bounced 2.14.2 over `phpcs.xml.dist`, and the zip also carried `website/`, `e2e/`, `playwright.config.ts`, `package.json`). If a new runtime directory is ever added, extend the `git archive` pathspec here and in CLAUDE.md.

**Reviewer-reply pattern:** brief and direct (no AI fluff, reviewers flag it), bullet the categories addressed, request any slug change explicitly in both the email and the upload comment.
