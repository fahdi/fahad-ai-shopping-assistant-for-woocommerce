# Fahad AI Shopping Assistant for WooCommerce — Developer Knowledge Base

Plugin folder: `fahad-ai-shopping-assistant-for-woocommerce/`
Main file: `fahad-ai-shopping-assistant-for-woocommerce.php`
GitHub: https://github.com/fahdi/fahad-ai-shopping-assistant-for-woocommerce
Current version: 1.1.0
Requires: WordPress 6.0+ (tested up to 7.0), PHP 8.0+, WooCommerce active
Slug (WP.org): `fahad-ai-shopping-assistant-for-woocommerce` (pending approval)
Text domain: `fahad-ai-shopping-assistant-for-woocommerce` (must equal slug)

---

## Naming Conventions

| Concept | Pattern | Examples |
|---|---|---|
| PHP constants | `FAHAD_AI_*` | `FAHAD_AI_VERSION`, `FAHAD_AI_PATH`, `FAHAD_AI_URL` |
| PHP classes | `Fahad_AI_*` | `Fahad_AI_Chatbot`, `Fahad_AI_API_Handler`, `Fahad_AI_Tools` |
| PHP functions | `fahad_ai_*` | `fahad_ai_settings_page` |
| Option keys | `fahad_ai_*` | `fahad_ai_provider`, `fahad_ai_anthropic_api_key` |
| Nonce action | `fahad_ai_settings` |
| Submit button name | `fahad_ai_save` |
| JS handle (script/style) | `fahad-ai-chatbot`, `fahad-ai-admin` |
| JS localized var | `window.fahadAiChatbot` |
| REST namespace | `fahad-ai/v1` |
| HTML root id | `fahad-ai-chatbot-root` |
| Admin tbody ids | `fahad-ai-anthropic`, `fahad-ai-moonshot` |

The `FAHAD_AI` / `fahad_ai_` prefix derives from the plugin display name **Fahad AI Shopping Assistant for WooCommerce**. It's 7 characters, distinct, and uses no common words — passes WP.org's "at least 4 chars, distinct and unique" prefix requirement.

---

## File Structure

```
fahad-ai-shopping-assistant-for-woocommerce/
├── fahad-ai-shopping-assistant-for-woocommerce.php   # Bootstrap: header, Fahad_AI_Chatbot class, REST routes
├── includes/
│   ├── class-api-handler.php                         # Fahad_AI_API_Handler — agents, API calls, SSE streaming, tools specs
│   ├── class-tools.php                               # Fahad_AI_Tools — WooCommerce operations executed by the AI
│   └── admin-settings.php                            # fahad_ai_settings_page() — provider, keys, widget config
├── assets/
│   ├── js/chatbot.js                                 # Frontend widget — vanilla JS, no dependencies
│   ├── js/admin-settings.js                          # Admin provider toggle (extracted from inline <script>)
│   └── css/chatbot.css                               # Widget styles with CSS custom properties
├── tests/
│   ├── bootstrap.php                                 # PHPUnit bootstrap: loads stubs + plugin classes
│   ├── stubs/wc-stubs.php                            # WP_Error, WC_Product, WC_Cart stubs for tests
│   └── unit/
│       ├── ApiHandlerTest.php                        # 15 tests: sanitize_messages(), tool_specs()
│       └── ToolsTest.php                             # 21 tests: all 5 WooCommerce tools
├── languages/
│   └── index.php                                     # Placeholder — WP.org auto-loads translations
├── readme.txt                                        # WordPress.org format
├── README.md                                         # GitHub format
└── uninstall.php                                     # Deletes all options on plugin uninstall
```

---

## Architecture

### Request Flow

**Anthropic (non-streaming):**
```
JS sendRegular()
  → POST /wp-json/fahad-ai/v1/message
    → Fahad_AI_API_Handler::handle_message()
      → run_anthropic_agent()         # loop: tool_use → end_turn
        → call_anthropic()            # wp_remote_post to api.anthropic.com
        → Fahad_AI_Tools::execute()     # WooCommerce operations
      → returns { message, messages }
  ← JS appendMessage('bot', reply)
     renderMarkdown() applied
```

**Moonshot (SSE streaming):**
```
JS sendStreaming()
  → POST /wp-json/fahad-ai/v1/stream
    → Fahad_AI_API_Handler::handle_stream()      # bypasses WP REST buffering
      → run_stream_agent()                      # loop: stream → execute tools → stream again
        → stream_one_turn()                     # raw cURL — only way to do SSE
          → emits SSE: chunk / tool / error
        → Fahad_AI_Tools::execute()               # between turns
      → emits SSE: done
  ← JS reads ReadableStream, parses SSE events
     'chunk' → append text (plain)
     'tool'  → show TOOL_LABELS status
     'done'  → re-render fullText with renderMarkdown()
     'error' → show message
```

### Why a dedicated cURL handle for streaming

`wp_remote_post()` buffers the entire response before returning. SSE requires
writing chunks to the browser as they arrive. The two non-streaming paths
(`call_anthropic`, `call_moonshot`) both use `wp_remote_post()` correctly.

v1.0.4–1.0.6 tried to keep streaming inside `wp_remote_post()` by overriding the
cURL write callback through the `http_api_curl` hook. That proved unreliable:
on some PHP/cURL builds the WordPress transport's own write handler wins, so the
upstream body bypasses our callback and prints straight to output — prepended to
our `data:` line, it broke SSE framing and the client rendered an empty bubble.
v1.0.7 drives a dedicated `curl_init()` handle in `stream_one_turn()` so the
write callback is deterministic. The cURL block has `phpcs:disable/enable` with
this explanation so WP.org reviewers understand the necessity.

---

## AI Providers

### Anthropic Claude
- **Endpoint:** `https://api.anthropic.com/v1/messages`
- **Auth header:** `x-api-key: {key}`
- **Tool format:** `input_schema` (JSON Schema inside `tools` array)
- **Tool loop:** response `stop_reason === 'tool_use'` → execute → append `tool_result` → repeat
- **Option key:** `fahad_ai_anthropic_api_key`
- **Models:** `claude-haiku-4-5-20251001`, `claude-sonnet-4-6`, `claude-opus-4-6`
- **Content filtering:** Haiku is aggressive — if "Output blocked by content filtering policy" appears, switch to Sonnet

### Moonshot AI (Kimi K2)
- **Endpoint:** `{base}/v1/chat/completions`, where `{base}` comes from `moonshot_base_url()` — `https://api.moonshot.ai` (Global) or `https://api.moonshot.cn` (China), selected by the `fahad_ai_moonshot_region` option
- **Two platforms:** Global and China are independent — a key issued on one is rejected by the other (401), and their model catalogues differ
- **Auth header:** `Authorization: Bearer {key}`
- **API keys from:** https://platform.moonshot.ai (Global) or https://platform.moonshot.cn (China)
- **Tool format:** OpenAI-compatible (`parameters`, `type: "function"`)
- **System message:** prepended as first message (`role: system`) — not a top-level field
- **Tool loop:** `finish_reason === 'tool_calls'` → execute → append `tool` role message → repeat
- **Streaming:** SSE with `stream: true`, delta chunks, `[DONE]` sentinel
- **Option key:** `fahad_ai_moonshot_api_key`

---

## WooCommerce Tools

Five tools are defined in `Fahad_AI_API_Handler::tool_specs()` (canonical) and mapped to two formats:
- `get_anthropic_tools()` → uses `input_schema`
- `get_openai_tools()` → uses `parameters` + `type: "function"`

| Tool | Input | Key output fields |
|---|---|---|
| `search_products` | query, category, min_price, max_price, limit (≤10) | found, products[]{id, name, price, in_stock, url} |
| `get_product_details` | product_id (required) | full product data, variations[] for variable products, url |
| `add_to_cart` | product_id (required), quantity, variation_id | success, cart_item_key, cart_url, checkout_url |
| `view_cart` | — | items[], subtotal, total, cart_url, checkout_url |
| `remove_from_cart` | cart_item_key (required) | success, new_total |

### Cart session in REST context
WooCommerce doesn't initialise the cart for REST requests by default.
`handle_message()` and `handle_stream()` both call `wc_load_cart()` before
any tool execution.

---

## Linking in Chat Responses

The system prompt instructs the AI to:
1. Format every product name as `[Product Name](url)` using the `url` field from tool results
2. After `add_to_cart` success: append `[View Cart](cart_url) · [Checkout](checkout_url)`
3. When checkout is requested: include `[Proceed to Checkout](checkout_url)`

The JS `renderMarkdown(text)` function handles rendering:
1. Escapes all HTML first (XSS-safe)
2. Converts `[text](url)` → `<a>` — same-origin URLs only
3. Converts `**text**` → `<strong>`
4. Converts `\n` → `<br>`

Streaming path: text arrives as plain during `chunk` events, `renderMarkdown()`
is applied on `done` to the final assembled `fullText`.

---

## WordPress Options

All stored under the `fahad_ai_` prefix:

| Option | Default | Description |
|---|---|---|
| `fahad_ai_provider` | `anthropic` | `anthropic` or `moonshot` |
| `fahad_ai_anthropic_api_key` | `''` | Anthropic key (sk-ant-…) |
| `fahad_ai_anthropic_model` | `claude-haiku-4-5-20251001` | Claude model ID |
| `fahad_ai_moonshot_api_key` | `''` | Moonshot key (sk-…) |
| `fahad_ai_moonshot_model` | `kimi-k2.6` | Kimi model ID |
| `fahad_ai_moonshot_region` | `global` | `global` (api.moonshot.ai) or `china` (api.moonshot.cn) |
| `fahad_ai_bot_name` | `Store Assistant` | Widget header name |
| `fahad_ai_greeting` | `Hi! How can I help you today?` | First bot message |
| `fahad_ai_system_prompt` | `''` | Custom prompt appended to default |
| `fahad_ai_accent_color` | `#2563eb` | Widget header/button color |

All options are deleted by `uninstall.php` when the plugin is removed.

---

## Frontend Widget

`assets/js/chatbot.js` — vanilla JS, no dependencies, IIFE-wrapped.

**Config injected via `wp_localize_script` as `window.fahadAiChatbot`:**
- `apiUrl`, `streamUrl`, `provider`, `nonce`
- `botName`, `greeting`, `accentColor`
- `i18n` — translatable strings for all UI labels, error messages, and tool status labels

**Key functions:**
- `sendMessage()` — routes to `sendStreaming()` or `sendRegular()` based on `cfg.provider`
- `sendStreaming()` — fetch + ReadableStream, parses SSE `data:` lines
- `sendRegular()` — fetch + JSON, uses `e.message` from WP REST error response
- `appendMessage(role, text)` — `textContent` for user, `innerHTML = renderMarkdown()` for bot
- `appendEmptyBotBubble()` — returns the bubble element for direct streaming updates
- `renderMarkdown(text)` — safe HTML escape → link conversion → bold → newlines
- `esc(str)` — HTML escape helper (used in widget HTML construction only)

**CSS custom properties (set from JS on `documentElement`):**
- `--chatbot-accent` — from `cfg.accentColor`
- `--chatbot-accent-dark` — auto-darkened 20 points via `darkenHex()`

---

## Admin Settings

Located in `includes/admin-settings.php` — `fahad_ai_settings_page()`.

Page hook: `settings_page_fahad-ai-shopping-assistant-for-woocommerce`
The main plugin's `enqueue_admin_assets()` checks for this hook string before enqueueing `fahad-ai-admin` (the provider toggle script).

Form fields use `fahad_ai_settings` nonce and `fahad_ai_save` submit button name. All `$_POST` reads pass through `wp_unslash()` and the appropriate sanitize callback.

The provider toggle JS (extracted from a previously-inline `<script>` block to satisfy WP.org review) lives in `assets/js/admin-settings.js`. It binds a `change` listener on the `#provider` select and toggles `#fahad-ai-anthropic` / `#fahad-ai-moonshot` visibility.

---

## REST API

**Namespace:** `fahad-ai/v1`
**Gate:** `Fahad_AI_Chatbot::authorize_request()` (the `permission_callback` for both routes)

| Endpoint | Method | Handler | Used by |
|---|---|---|---|
| `/message` | POST | `Fahad_AI_API_Handler::handle_message()` | Anthropic provider |
| `/stream` | POST | `Fahad_AI_API_Handler::handle_stream()` | Moonshot provider |

**Why these endpoints are public + rate limited (security invariant — do not regress):**
Both endpoints are intentionally public so guests can use the assistant. The `wp_rest`
nonce is exposed to every visitor, so it is CSRF protection, not authorization. WP.org
review (2026-06-12) flagged that a nonce alone does not protect endpoints that trigger
billable AI calls and modify the cart. `authorize_request()` therefore does two things:
1. `wp_verify_nonce( …, 'wp_rest' )` → `WP_Error` 403 on failure (CSRF).
2. `is_rate_limited()` → `WP_Error` 429 when the caller exceeds the window.

`is_rate_limited()` is a transient-backed fixed window keyed on user id (logged in) or
`REMOTE_ADDR` (guests; never `X-Forwarded-For`). Defaults 20 req / 60s, filterable via
`fahad_ai_rate_limit` and `fahad_ai_rate_window`. Keep both the nonce and the rate limit.

`handle_stream()` bypasses WordPress REST buffering via:
```php
add_filter( 'rest_pre_serve_request', '__return_true' );
header( 'Content-Type: text/event-stream' );
header( 'X-Accel-Buffering: no' );  // disables nginx buffering
```

**SSE event types emitted:**
- `chunk` → `{ type, content }` — text delta
- `tool` → `{ type, name }` — tool executing (shows status label in UI)
- `products` → `{ type, products }` — product cards from a search/details tool (widget renders cards)
- `error` → `{ type, message }` — error, streaming aborted
- `done` → `{ type }` — all turns complete

---

## Internationalization

Every user-facing string is wrapped in a gettext function with the `fahad-ai-shopping-assistant-for-woocommerce` text domain.

**PHP:**
- `__()` / `esc_html__()` / `esc_html_e()` / `esc_attr_e()` — labels, headings, settings page
- `sprintf( __( '... %s ...', '...' ), $var )` — error messages with dynamic content (with `/* translators: */` comments)
- `Fahad_AI_API_Handler` — all `WP_Error` messages, including HTTP error formatters
- `Fahad_AI_Tools` — all tool result messages (success, error, hint)

**JS:**
- All UI labels, ARIA labels, placeholders, error messages, and tool status labels are passed via `wp_localize_script` as `fahadAiChatbot.i18n.*`
- The JS file uses `i18n.foo || 'fallback English'` to be defensive against missing keys

**Text domain matching:** WP.org review requires the text domain to exactly match the plugin slug. Both are `fahad-ai-shopping-assistant-for-woocommerce`.

---

## Test Suite

**Runner:** `vendor/bin/phpunit --testdox`
**Stack:** PHPUnit 10.5 + Brain\Monkey 2.x + Mockery 1.x
**Coverage:** 43 tests, 134 assertions

**Class refs in tests:**
- `Fahad_AI_API_Handler::class` (was `WC_AI_Chatbot_API_Handler`)
- `Fahad_AI_Tools::class` (was `WC_AI_Chatbot_Tools`)
- Reflection on `Fahad_AI_*::$instance` to reset singletons between tests

**Running tests (Local by Flywheel PHP):**
```bash
cd /Users/isupercoder/websites/woocommerce-demo/app/public/wp-content/plugins/fahad-ai-shopping-assistant-for-woocommerce
vendor/bin/phpunit --testdox
```

---

## WordPress.org Distribution

**Status:** Pending review. Submission history:
- Apr 13, 2026: submitted as `wc-ai-chatbot` ("AI Chatbot for WooCommerce") — pended Apr 23 with name/prefix/cURL/i18n issues
- Apr 27, 2026: v1.0.3 uploaded as "Maya AI Shopping Assistant for WooCommerce" — naming rejected Apr 30 and again May 3 ("Maya looks like a brand, not clearly tied to the owner account")
- May 17, 2026: author proposed `fahad-ai-shopping-assistant-for-woocommerce`
- May 20, 2026: reviewer accepted the new name; flagged remaining issues: raw cURL in `class-api-handler.php` and text-domain mismatch (still `maya-ai-…`)
- v1.0.4: final rename to `Fahad AI Shopping Assistant for WooCommerce` + cURL replaced with `wp_remote_post()` + `http_api_curl` hook
- v1.0.5: "Tested up to" raised to WordPress 7.0
- Jun 12, 2026: reviewer flagged the last open issue — `check_nonce` is not meaningful authorization for the public `/message` and `/stream` endpoints (billable AI + cart mutation)
- v1.0.6: replaced `check_nonce` with `authorize_request()` (nonce + per-client rate limiting), raised `Requires PHP` to 8.0 to match the typed code
- v1.0.7: added the `fahad_ai_moonshot_region` setting (Global/China endpoint select); reverted the Moonshot streaming path to a dedicated `curl_init()` handle after the `http_api_curl` hook proved unreliable (leaked the upstream body and corrupted SSE framing → blank replies); fixed the default Kimi model (`kimi-k2-thinking-turbo` was unavailable on the global platform) to `kimi-k2.6`
- v1.1.0: product results render as rich cards in the widget. Tools (`format_product_summary`, `get_product_details`) now return a product `image` and a plain-text `price`. The handler emits card data to the client via `tool_result_cards()` — a `products` SSE event on the stream path and a `products` array on the non-stream response. The client (`renderProductCards`/`buildCard`) builds cards via DOM APIs from this trusted server data (not model text). System prompt updated so the model gives a short intro instead of restating product details.

**v1.0.4 Plugin Check fixes:**
- Renamed to the WP.org-reserved slug `fahad-ai-shopping-assistant-for-woocommerce` end-to-end (display name, file name, constants, classes, options, REST namespace, JS handles, localized object, text domain)
- Replaced raw cURL in Moonshot SSE streaming with `wp_remote_post()` + `http_api_curl` hook
- `Requires Plugins: woocommerce` header (from v1.0.3, retained)
- Inline `<script>` in admin settings replaced with externally-enqueued `assets/js/admin-settings.js` (from v1.0.3, retained)
- Text domain matches the new slug exactly

**Building a release zip:**
```bash
cd /Users/isupercoder/Code/github
zip -r fahad-ai-shopping-assistant-for-woocommerce-1.1.0.zip fahad-ai-shopping-assistant-for-woocommerce \
  --exclude "fahad-ai-shopping-assistant-for-woocommerce/.git/*" \
  --exclude "fahad-ai-shopping-assistant-for-woocommerce/vendor/*" \
  --exclude "fahad-ai-shopping-assistant-for-woocommerce/tests/*" \
  --exclude "fahad-ai-shopping-assistant-for-woocommerce/composer.json" \
  --exclude "fahad-ai-shopping-assistant-for-woocommerce/composer.lock" \
  --exclude "fahad-ai-shopping-assistant-for-woocommerce/phpunit.xml" \
  --exclude "fahad-ai-shopping-assistant-for-woocommerce/phpunit.xml.bak" \
  --exclude "fahad-ai-shopping-assistant-for-woocommerce/.phpunit.result.cache" \
  --exclude "fahad-ai-shopping-assistant-for-woocommerce/.gitignore" \
  --exclude "fahad-ai-shopping-assistant-for-woocommerce/README.md" \
  --exclude "fahad-ai-shopping-assistant-for-woocommerce/CLAUDE.md" \
  --exclude "fahad-ai-shopping-assistant-for-woocommerce/ROADMAP.md"
```

**Reply to WP.org reviewer pattern (from past EventCrafter/LeadCrafter approvals):**
- Brief and direct — no copy-pasted AI fluff (reviewer flags this explicitly)
- Bullet list of categories addressed (don't enumerate every change)
- Slug change must be requested *explicitly* in the email body AND in the upload comment
