=== Fahad AI Shopping Assistant for WooCommerce ===
Contributors: fahdi
Tags: woocommerce, chatbot, ai, cart, assistant
Requires at least: 6.0
Tested up to: 7.0
Requires PHP: 8.0
Stable tag: 2.6.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

AI-powered shopping assistant for WooCommerce. Answers customer questions and manages the cart using Claude or Kimi K2.

== Description ==

Fahad AI Shopping Assistant adds an intelligent shopping assistant widget to your WooCommerce store. Customers can ask questions about products, get personalised recommendations, and add items to their cart — all through a natural conversational interface.

**Supported AI providers:**

* **Anthropic Claude** — claude-haiku, claude-sonnet, claude-opus
* **Moonshot AI (Kimi K2)** — kimi-k2-thinking-turbo, kimi-k2-thinking, kimi-k2.5, and Moonshot V1 models

**What the chatbot can do:**

* Search products by name, category, and price range
* Show full product details including stock status, SKU, and available variations
* Help customers pick and add a specific variation (size/colour) of a variable product
* Show ratings and review highlights on product cards (grounded in real reviews)
* Compare products side by side in a mobile-friendly table
* Recommend related products and offer optional cross-sells, respecting a stated budget
* Surface best-sellers and browse categories
* Show valid, applicable coupons and apply them to the cart (never invented codes)
* Estimate shipping cost and delivery options for a destination
* Look up order status for the logged-in customer (their own orders only)
* Check wallet/store-credit balance and top up, when a compatible wallet plugin is active
* Remember a logged-in customer's stated preferences across sessions (opt-in, viewable and clearable)
* Add products to the customer's cart, view cart contents with totals, and remove items
* Stream responses in real-time (Moonshot provider)

**Built for trust:** the assistant grounds every product fact in your store's data (no invented prices, stock, reviews, or codes), respects stated budgets, never uses fake scarcity or pressure, discloses upsells as optional, and always points customers to human support when needed. Personal data (orders, wallet, saved preferences) is restricted to the logged-in owner.

**For developers:** other plugins can register their own assistant tools via the `fahad_ai_register_tools` filter — no core changes required.

**Requirements:**

* WooCommerce 7.0 or later
* An API key from [Anthropic](https://console.anthropic.com) or [Moonshot AI](https://platform.moonshot.ai)

**External services:**

This plugin sends conversation data to third-party AI APIs:

* Anthropic Messages API (`api.anthropic.com`) — when the Anthropic provider is selected. [Privacy policy](https://www.anthropic.com/legal/privacy).
* Moonshot AI API (`api.moonshot.ai`) — when the Moonshot provider is selected. [Privacy policy](https://www.moonshot.ai/privacy).

Only conversation history and product data relevant to the current session are transmitted. No personal customer data is sent unless the customer types it into the chat.

== Installation ==

1. Upload the `fahad-ai-shopping-assistant-for-woocommerce` folder to `/wp-content/plugins/`
2. Activate the plugin through the **Plugins** menu in WordPress
3. Navigate to **Settings → Fahad AI Assistant**
4. Choose your AI provider (Anthropic or Moonshot AI)
5. Enter your API key
6. Configure the bot name, greeting message, and accent color
7. Save — the chat widget will appear on all frontend pages

== Frequently Asked Questions ==

= Which AI provider should I use? =

Both providers work well. Anthropic Claude has strong instruction-following for English-language stores. Moonshot AI (Kimi K2) supports real-time streaming responses. Choose based on your API access and cost preferences.

= Does the chatbot have access to my product catalogue? =

Yes. It searches products, retrieves full product details, and checks stock levels using WooCommerce's native data functions. No separate sync or index is required.

= Can it actually add products to the cart? =

Yes. When a customer asks to add an item, the chatbot calls WooCommerce's cart API directly within the customer's session. The cart page will reflect the changes immediately.

= Is the API key stored securely? =

API keys are stored in the WordPress options table using standard WordPress security practices. They are never exposed to the frontend or included in page source.

= Does it support streaming responses? =

Yes — streaming is available with the Moonshot AI (Kimi) provider. Responses appear word-by-word as they are generated. The Anthropic provider uses standard request/response.

= What data is sent to the AI provider? =

The conversation history (user messages and assistant replies) and the results of tool calls (product data, cart contents) are transmitted to the selected provider's API per session. Review the provider's privacy policy for how they handle this data.

== Screenshots ==

1. Chat widget on the storefront
2. Admin settings — provider and API key configuration

== Changelog ==

= 2.6.0 =
Insight, voice, and helpful nudges.

* Owner analytics: a new admin dashboard showing top questions, the "couldn't answer" list, a chat-to-cart funnel, and cost per conversation — privacy-safe (no personal data stored), with export and delete controls.
* Voice: optional speech-to-text input and spoken replies in the chat widget (browser Web Speech API; off by default, text always works).
* Proactive help: an optional, value-gated nudge (off by default) that only appears when there's a genuinely applicable coupon or unused store credit — never fake urgency, frequency-capped, dismissible.

= 2.5.0 =
Checkout help, merchant controls, and quality hardening.

* Conversational checkout: the assistant can summarise the order (items, shipping options, totals), set a shipping method, and recommend or apply the best valid coupon — only with the shopper's consent — then hand off to the secure WooCommerce checkout for payment (it never handles card details).
* Merchant controls: a new admin section to set the assistant's tone, restrict which tools it may use, define off-limits topics and promo emphasis, and tune cost/model options. The trust guardrails can never be disabled.
* Hardening: the assistant always gives a one-line summary alongside product cards, currency symbols always render correctly, and internal quality-eval coverage was extended.

= 2.4.0 =
Privacy, reliability, and feedback.

* Privacy (GDPR): the assistant's opt-in saved preferences are now included in WordPress' personal-data Export and removed by its personal-data Erase tools.
* Reliability: if the configured AI provider is briefly unavailable, the assistant automatically falls back to the other configured provider; if neither responds it shows a friendly message and points to search/support instead of an error.
* Reply feedback: shoppers can give a thumbs up/down on replies so you can see where the assistant does well or needs work (no personal data stored).

= 2.3.0 =
New shopper features.

* Returns & exchanges: logged-in customers can check whether an order item is returnable (against the store's return window and the order's status) and submit a return request. The assistant only records the request for your team — it never issues a refund — and points to human support for anything ineligible.
* Back-in-stock & price-drop alerts: shoppers can ask to be notified when an out-of-stock item returns or a price drops. Opt-in with double confirmation and one-click unsubscribe; alerts only ever fire on a real stock or price change (no fake urgency).

= 2.2.0 =
New shopper features.

* Reorder / buy-it-again: logged-in customers can see what they've bought before and re-add available items in one step (each revalidated for current price and stock; sold-out items are reported, not silently dropped).
* Size & fit advice: the assistant surfaces a product's size options and any size chart, and offers a "runs small / true to size / runs large" hint — but only when the store's own reviews or a fit attribute support it, never invented.
* "Complete the look" bundles: an optional suggested bundle of complementary items with an honest combined price (a saving is shown only when items are genuinely on sale), respecting stock and any stated budget.

= 2.1.0 =
New: direct, verified Add-to-cart from chat product cards.

* The "Add to cart" button on chat product cards now adds the item instantly through a dedicated cart endpoint, with no AI round-trip — it's faster, and the confirmation always reflects the real cart result (it can no longer say "added" without actually adding). Variable products still require choosing an option first.

= 2.0.2 =
Maintenance & planning release — no functional plugin changes.

* Documentation: split the developer guide (CLAUDE.md) into focused context docs (docs/plugin.md, docs/ai-assistant.md) and refreshed it for the 2.0.x architecture.
* Planning: published the v2.1+ opportunity backlog and a standing per-PR release workflow.

= 2.0.1 =
Bug-fix release from live-store QA of 2.0.0.

* Product search now understands plural and descriptive phrasing (for example "hoodies" or "medium black hoodie") instead of returning nothing and falling back to unrelated products.
* The assistant no longer shows a raw "agent exceeded maximum iterations" error. It ends with a friendly message and keeps any product cards it already found.
* Fixed the currency symbol rendering as garbled text in streamed replies. Streamed responses now buffer partial data frames, so the symbol renders correctly.
* Fixed add to cart not persisting for logged-out shoppers on the streaming (Moonshot) provider. The session cookie is now set before streaming begins, so items stay in the cart.

= 2.0.0 =
A major release delivering the full shopping-assistant roadmap.

New shopper features:
* Reviews & ratings shown on product cards (grounded in real reviews, never invented)
* Pick and add a specific product variation (size/colour) conversationally
* Side-by-side product comparison table (mobile-friendly)
* Coupons & deals — only valid, applicable codes are shown and applied to the cart
* Best-sellers and category browsing
* Recommendations & optional cross-sells that respect a stated budget
* Order status & tracking for the logged-in customer (their own orders only)
* Wallet-aware shopping — balance and top-up via a decoupled provider, when a compatible wallet plugin is active
* Shipping & delivery estimates by destination
* Opt-in cross-session memory for stated preferences (viewable and clearable)

Trust & safety:
* Anti-dark-pattern guardrails encoded in the assistant: no fake scarcity, respect stated budgets, disclose upsells, abstain instead of guessing, and never block human support
* Privacy/authorization boundary for personal-data tools — login-gated, with strict per-record ownership checks
* WCAG 2.2 AA accessibility pass on the chat widget and product cards

Under the hood:
* Tool extensibility hook (`fahad_ai_register_tools`) so add-ons can register assistant tools without forking the core; built-in tools migrated to a drop-in registry
* Cost & latency controls — tool results trimmed before being fed back to the model, per-conversation token budget, and a model-routing seam
* An offline eval harness (golden conversations + grounding checks) runs in CI to guard answer quality

= 1.1.0 =
* New: product results now render as rich visual cards in the chat — product photo, price (with sale price), stock status, a short description, and View / Add to cart buttons
* Product search and details now return the product image and a clean price; card data is sourced from WooCommerce (not AI text) so it is always accurate
* The assistant gives a short intro instead of repeating every product's details in text, since the cards show them

= 1.0.7 =
* Added a Moonshot Region setting (Global api.moonshot.ai / China api.moonshot.cn) so keys issued on either platform work without editing code
* Fixed Moonshot streaming: replies and errors now render reliably instead of an empty bubble. The streaming request uses a dedicated cURL handle because the `http_api_curl` write-callback override could let the upstream response bypass the handler and corrupt the SSE stream on some PHP/cURL builds
* Updated the default Kimi model to `kimi-k2.6` and refreshed the model list (the previous default was not available on the global platform)

= 1.0.6 =
* Security: the `/message` and `/stream` REST endpoints now enforce per-client rate limiting alongside the existing nonce check, capping how many billable AI calls and cart changes a single visitor can trigger
* Raised the minimum PHP requirement to 8.0 to match the typed code already in use

= 1.0.5 =
* Bumped "Tested up to" to WordPress 7.0

= 1.0.4 =
* Renamed plugin to "Fahad AI Shopping Assistant for WooCommerce" with the `fahad-ai-shopping-assistant-for-woocommerce` slug (final name approved by the WordPress.org Plugin Directory)
* All option keys, constants, classes, REST namespace, JS handles, and the text domain migrated to the `fahad_ai_` / `fahad-ai-` prefix
* Replaced raw cURL in the Moonshot streaming path with `wp_remote_post()` plus the documented `http_api_curl` hook for the SSE write callback
* Added `Requires Plugins: woocommerce` header for WordPress 6.5+ dependency check
* Replaced inline `<script>` block in admin settings with a properly enqueued JS file

= 1.0.2 =
* Renamed display name to "AI Chatbot for WooCommerce"
* Removed deprecated `load_plugin_textdomain()` call (auto-loaded since WP 4.6)
* Added `wp_unslash()` on all `$_POST` reads in the admin settings page
* Suppressed cURL Plugin Check warnings with documented justification (SSE streaming)

= 1.0.1 =
* Markdown link rendering for product names and cart/checkout links
* Safe HTML escaping in the streaming render path
* WordPress Playground demo link

= 1.0.0 =
* Initial release
* Anthropic Claude support (haiku, sonnet, opus)
* Moonshot AI / Kimi K2 support with real-time streaming
* WooCommerce cart integration: search, view details, add, view cart, remove
* Customisable bot name, greeting message, and accent color
* Optional custom system prompt

== Upgrade Notice ==

= 2.6.0 =
Adds an owner analytics dashboard (privacy-safe), optional voice input/output, and an optional value-gated proactive nudge (off by default, no fake urgency). Backward compatible.

= 2.5.0 =
Adds conversational checkout help (consent-gated coupons, no payment handling), merchant tone/scope/cost controls (guardrails stay enforced), and quality hardening. Backward compatible.

= 2.4.0 =
Adds GDPR export/erase for saved preferences, automatic AI-provider failover with graceful degradation, and thumbs up/down reply feedback. Backward compatible; no settings changes.

= 2.3.0 =
Adds a returns/exchange assistant (records requests, never refunds) and consented back-in-stock / price-drop alerts. Backward compatible; no settings changes.

= 2.2.0 =
Adds reorder/buy-it-again, grounded size & fit advice, and optional "complete the look" bundles. Backward compatible; no settings changes.

= 2.1.0 =
Adds instant, reliable Add-to-cart from chat product cards (no AI round-trip). Backward compatible; no settings changes.

= 2.0.2 =
Maintenance release: documentation and internal planning only — no functional changes. Safe to skip if you are on 2.0.1.

= 2.0.1 =
Fixes from live QA: smarter product search (plurals and descriptive queries), a friendly fallback instead of a raw loop error, correct currency rendering in streamed replies, and add to cart now persists for guests on the streaming provider.

= 2.0.0 =
Major update: reviews, variations, comparison, coupons, recommendations, best-sellers, order status, wallet, shipping estimates, and opt-in memory — plus trust guardrails, a privacy/auth boundary, accessibility, an extensibility hook, and cost controls. Backward compatible; no settings changes required.

= 1.1.0 =
Product results now appear as rich cards with photo, price, stock, and Add-to-cart buttons.

= 1.0.7 =
Fixes Moonshot streaming returning a blank reply and adds a Global/China region selector for Moonshot API keys.

= 1.0.6 =
Security and compatibility update: adds rate limiting to the chat endpoints and raises the minimum PHP version to 8.0.

= 1.0.4 =
Existing v1.0.2 users: option keys have been renamed from `wc_ai_chatbot_*` to `fahad_ai_*`. Settings will need to be re-entered after upgrade.
