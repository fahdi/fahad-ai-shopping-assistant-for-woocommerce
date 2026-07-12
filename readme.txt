=== Dukandar AI Shopping Assistant for WooCommerce ===
Contributors: fahdi
Tags: woocommerce, chatbot, ai, cart, assistant
Requires at least: 6.0
Tested up to: 7.0
Requires PHP: 8.0
Requires Plugins: woocommerce
Stable tag: 2.14.5
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/old-licenses/gpl-2.0.html

AI-powered shopping assistant for WooCommerce. Answers customer questions and manages the cart using Claude or Kimi K2.

== Description ==

<a href="https://woo.isupercoder.com/?fahad_demo=What%20wireless%20headphones%20do%20you%20have%20and%20how%20much%3F"><img src="https://ps.w.org/fahad-ai-shopping-assistant-for-woocommerce/assets/screenshot-1.gif" alt="Dukandar answers a product question with grounded store data and a product card"></a>

A shopper asks a product question and the assistant answers with your real catalogue data: an in-stock product card with title, price, and stock status, never an invented fact. [Try it live on a real store](https://woo.isupercoder.com/?fahad_demo=What%20wireless%20headphones%20do%20you%20have%20and%20how%20much%3F).

Dukandar AI Shopping Assistant adds an intelligent shopping assistant widget to your WooCommerce store. Customers can ask questions about products, get personalised recommendations, and add items to their cart, all through a natural conversational interface.

**Supported AI providers:**

* **Anthropic Claude**: claude-haiku, claude-sonnet, claude-opus
* **Moonshot AI (Kimi K2)**: kimi-k2-thinking-turbo, kimi-k2-thinking, kimi-k2.5, and Moonshot V1 models

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

**For developers:** other plugins can register their own assistant tools via the `fahad_ai_register_tools` filter, no core changes required.

**Requirements:**

* WooCommerce 7.0 or later
* An API key from [Anthropic](https://platform.claude.com) or [Moonshot AI](https://platform.kimi.ai)

**External services:**

This plugin sends data only to the AI provider you configure with an API key. Every provider is opt-in; nothing is sent until you choose and configure one. See the [External services](#section-external-services) section below for full details, including links to each provider's terms of service and privacy policy.

== Installation ==

1. Upload the `fahad-ai-shopping-assistant-for-woocommerce` folder to `/wp-content/plugins/`
2. Activate the plugin through the **Plugins** menu in WordPress
3. Navigate to **Settings → Dukandar Assistant**
4. Choose your AI provider (Anthropic or Moonshot AI)
5. Enter your API key
6. Configure the bot name, greeting message, and accent color
7. Save, the chat widget will appear on all frontend pages

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

The Moonshot AI (Kimi) provider uses a streaming endpoint; the Anthropic provider uses standard request/response. Either way the reply is delivered to the chat widget as soon as it is ready.

= What data is sent to the AI provider? =

The conversation history (user messages and assistant replies) and the results of tool calls (product data, cart contents) are transmitted to the selected provider's API per session. Review the provider's privacy policy for how they handle this data.

== Screenshots ==

1. The assistant answers a product question with grounded store data and a product card, then declines to invent a discount when there is no sale.
2. The Dukandar chat widget live on a WooCommerce storefront, grounded in the store's real catalogue.

== External services ==

This plugin connects to external AI APIs to generate chat responses and (optionally) product embeddings. Every provider is opt-in, no data leaves your server until you choose a provider and enter an API key in Settings.

**What data is sent:** the shopper's messages, the assistant's prior replies (conversation history), and the results of tool calls (product titles, descriptions, cart contents, order status for the logged-in customer). No raw customer account data is sent unless the shopper types it into the chat.

**When data is sent:** only when a shopper submits a message to the chat widget, or (for semantic search) when the store owner triggers an embedding build or a product is saved.

---

= Anthropic (Claude) =

Used to generate chat responses when Anthropic is selected as the provider.

* **Endpoint:** `api.anthropic.com`
* **Data sent:** conversation history and tool-call results per session.
* [Terms of Service](https://www.anthropic.com/legal/consumer-terms) | [Privacy Policy](https://www.anthropic.com/legal/privacy)

= Moonshot AI (Kimi) =

Used to generate chat responses (including streaming) when Moonshot AI is selected.

* **Endpoint:** `api.moonshot.ai` / `api.moonshot.cn`
* **Data sent:** conversation history and tool-call results per session.
* [Terms of Service](https://platform.kimi.ai/docs/agreement/modeluse) | [Privacy Policy](https://platform.kimi.ai/docs/agreement/userprivacy)

= OpenAI =

Used to generate chat responses when OpenAI is selected, and as the default provider for semantic-search embeddings when that feature is enabled.

* **Endpoint:** `api.openai.com`
* **Data sent (chat):** conversation history and tool-call results per session. **Data sent (embeddings, optional):** product titles, descriptions, and attributes, never prices, stock levels, or customer data.
* [Terms of Service](https://openai.com/policies/terms-of-use/) | [Privacy Policy](https://openai.com/policies/privacy-policy/)

= Google Gemini =

Used to generate chat responses when Google Gemini is selected as the provider.

* **Endpoint:** `generativelanguage.googleapis.com`
* **Data sent:** conversation history and tool-call results per session.
* [Terms of Service](https://ai.google.dev/gemini-api/terms) | [Privacy Policy](https://policies.google.com/privacy)

= Groq =

Used to generate chat responses when Groq is selected as the provider.

* **Endpoint:** `api.groq.com`
* **Data sent:** conversation history and tool-call results per session.
* [Terms of Service](https://groq.com/terms-of-use) | [Privacy Policy](https://groq.com/privacy-policy)

= Mistral AI =

Used to generate chat responses when Mistral is selected as the provider.

* **Endpoint:** `api.mistral.ai`
* **Data sent:** conversation history and tool-call results per session.
* [Terms of Service](https://legal.mistral.ai/terms) | [Privacy Policy](https://legal.mistral.ai/terms/privacy-policy)

= DeepSeek =

Used to generate chat responses when DeepSeek is selected as the provider.

* **Endpoint:** `api.deepseek.com`
* **Data sent:** conversation history and tool-call results per session.
* [Terms of Service](https://cdn.deepseek.com/policies/en-US/deepseek-terms-of-use.html) | [Privacy Policy](https://cdn.deepseek.com/policies/en-US/deepseek-privacy-policy.html)

= xAI (Grok) =

Used to generate chat responses when xAI is selected as the provider.

* **Endpoint:** `api.x.ai`
* **Data sent:** conversation history and tool-call results per session.
* [Terms of Service](https://x.ai/legal/terms-of-service) | [Privacy Policy](https://x.ai/legal/privacy-policy)

= Together AI =

Used to generate chat responses when Together AI is selected as the provider.

* **Endpoint:** `api.together.xyz`
* **Data sent:** conversation history and tool-call results per session.
* [Terms of Service](https://www.together.ai/terms-of-service) | [Privacy Policy](https://www.together.ai/privacy)

= OpenRouter =

Used to route chat requests to various underlying models when OpenRouter is selected as the provider.

* **Endpoint:** `openrouter.ai`
* **Data sent:** conversation history and tool-call results per session.
* [Terms of Service](https://openrouter.ai/terms) | [Privacy Policy](https://openrouter.ai/privacy)

= Perplexity =

Used to generate chat responses when Perplexity is selected as the provider.

* **Endpoint:** `api.perplexity.ai`
* **Data sent:** conversation history and tool-call results per session.
* [Terms of Service](https://www.perplexity.ai/hub/legal/terms-of-service) | [Privacy Policy](https://www.perplexity.ai/hub/legal/privacy-policy)

= Cohere =

Used for semantic-search embeddings (optional feature, off by default) when Cohere is selected as the embeddings provider. Only product text is sent, never prices, stock levels, or customer data.

* **Endpoint:** `api.cohere.com`
* **Data sent:** product titles, descriptions, and attributes when the store owner builds or rebuilds the search index.
* [Terms of Service](https://cohere.com/terms-of-use) | [Privacy Policy](https://cohere.com/privacy)

= Qdrant (optional external vector database) =

Used only when the store owner explicitly configures an external Qdrant server (self-hosted or Qdrant Cloud) as the semantic-search index for very large catalogs. Off by default; typical stores use the built-in database index and never contact Qdrant.

* **Endpoint:** the Qdrant URL you configure (your own server or Qdrant Cloud cluster).
* **Data sent:** product embedding vectors with product IDs, the embedding model name, and a content hash, never prices, stock levels, or customer data.
* [Terms and Conditions](https://qdrant.tech/legal/terms_and_conditions/) | [Privacy Policy](https://qdrant.tech/legal/privacy-policy/) (apply when using Qdrant Cloud; self-hosted servers are governed by you)

= Meta (WhatsApp Business Cloud API) =

Used when the optional WhatsApp channel is enabled (off by default). The plugin receives inbound messages from Meta's webhook and sends replies back through the Cloud API. Enable this only if you have a Meta Business account and have configured the WhatsApp integration in Settings.

* **Endpoint:** `graph.facebook.com`
* **Data sent:** the assistant's reply text and the shopper's WhatsApp sender ID. Inbound messages arrive via Meta's webhook, no shopper data is proactively sent from your server to Meta beyond what is required to deliver a reply.
* [WhatsApp Business Terms of Service](https://www.whatsapp.com/legal/business-terms/) | [Meta Privacy Policy](https://www.facebook.com/privacy/policy/)

== Changelog ==

= 2.14.5 =
Rebrand: the plugin is now Dukandar. Same plugin, same settings, same slug, only the name changed.

* Renamed the display title to Dukandar AI Shopping Assistant for WooCommerce. No functional changes; your API keys, settings, and existing configuration are preserved (the internal identifiers and update slug are unchanged).

= 2.14.4 =
Editorial pass: plain-text copy with no em-dashes anywhere in the plugin.

* Removed em-dashes from all user-facing text and code copy (readme, admin settings labels, provider and tool descriptions, inline help, and developer docs) for one consistent plain-text voice across the plugin, the WordPress.org listing, the GitHub project, and the marketing site.
* No functional changes for shoppers or merchants; the full test suite passes.

= 2.14.3 =
Packaging fix: development files are no longer bundled in the distribution zip.

* The release zip now packages only the runtime plugin files (main file, `uninstall.php`, `readme.txt`, `includes/`, `assets/`, `languages/`). Development tooling (`phpcs.xml.dist`, Playwright end-to-end tests, `package.json`) and the marketing website folder are excluded.
* No plugin code changes.

= 2.14.2 =
WordPress.org review fixes: working legal links, hardened WP-CLI report path, statically-verifiable REST permissions.

* Replaced dead or relocated Terms of Service / Privacy Policy links (DeepSeek, Together AI, Moonshot/Kimi, Mistral, Groq, Google Gemini API) with their current canonical URLs; every link in the readme was verified live.
* `wp fahad-ai rag-spike --report` now accepts a filename only: the report is always written inside `wp-content/uploads/fahad-ai-shopping-assistant-for-woocommerce/`, and absolute paths or `../` traversal are stripped.
* Inlined literal `permission_callback` values (`__return_true` for the read-only public agent endpoints, capability checks for admin endpoints) so intent is statically verifiable.
* Documented the optional external Qdrant vector database in the External services section.

= 2.14.1 =
WordPress.org compliance: external services documentation and plugin-folder write fix.

* Added standalone `== External services ==` readme section listing every AI provider (Anthropic, Moonshot, OpenAI, Gemini, Groq, Mistral, DeepSeek, xAI, Together AI, OpenRouter, Perplexity, Cohere, WhatsApp/Meta) with Terms of Service and Privacy Policy links for each.
* Fixed `wp fahad-ai rag-spike` WP-CLI command: the report is now written to the WordPress uploads directory (`wp-content/uploads/fahad-ai-shopping-assistant-for-woocommerce/RAG-SPIKE-REPORT.md`) instead of the plugin folder.

= 2.14.0 =
Live-demo deep link.

* A store can share a link like `?fahad_demo=your%20question` that opens the assistant, types the question with a typewriter effect, and sends it automatically, for a hands-free live demo. Use `?fahad_demo=1` for a built-in default question.

= 2.13.1 =
Back-in-stock alerts from chat.

* The assistant now offers to notify a customer when an out-of-stock item returns (double opt-in), using the existing stock-alert system.

= 2.13.0 =
Store-as-an-agent, let AI agents discover and shop your store.

* New read-only endpoints under /agent for external AI agents (ChatGPT, Claude, Perplexity): an llms.txt usage guide, a structured catalogue feed, search and product lookups that reuse the same grounded data as the chat widget, and a human checkout handoff (the shopper completes payment, never the agent).

= 2.12.0 =
Merchant copilot, admin insight endpoints.

* New admin-only, read-only endpoints (gated by the Manage WooCommerce capability) that surface real store data for an admin assistant: a sales/refunds summary, products worth putting on sale, a product's real attributes for content generation, and reviews awaiting a reply. Nothing is written automatically.

= 2.11.12 =
Refer a friend, from chat.

* Ask the assistant how to refer a friend and it now shares your real referral link, code and reward amounts (when the store runs a referral programme).

= 2.11.11 =
Shop within your wallet balance.

* Ask the assistant to find something within your store credit and it now checks your real balance and only suggests products that fit it.

= 2.11.10 =
Grounded wallet and store-credit answers.

* When a customer asks about their balance or store credit, the assistant now checks their real wallet instead of answering from memory, and asks them to sign in if they are logged out.

= 2.11.9 =
Grounded sale and discount answers.

* The assistant can now filter a product search to items that are currently on sale, so "what is on sale?" returns the real sale products with their sale prices instead of a generic list.
* It no longer claims an item is or is not on sale from memory. It checks the catalog first, and if nothing is discounted it says so plainly.
* Reached 100% automated test coverage across the plugin (PHP and the chat widget) with enforced coverage gates.

= 2.11.8 =
Mobile UI fixes, humanized replies, and a new frontend test suite.

* Fixed the chat panel overflowing the screen on landscape phones and short windows (the header and close button could be pushed off-screen).
* Reply feedback (the thumbs) now sits below the message instead of beside it.
* The message input is now 16px so iOS Safari no longer zooms the page when you tap it.
* The full-screen mobile panel now respects device safe areas (notch and home indicator) and uses the dynamic viewport height.
* Assistant replies read more naturally and no longer use em-dashes.
* Added a re-runnable Playwright end-to-end test suite for the widget across mobile, tablet, and desktop sizes.

= 2.11.1 =
WordPress.org review fixes + standards compliance.

* Replaced the direct cURL call in the streaming path with the WordPress HTTP API (per the plugin guidelines).
* Hardened all admin form input handling (unslash + sanitize) and resolved Plugin Check / PHPCS findings.
* Confirmed WordPress 7.0 and PHP 8.0-8.4 compatibility.

= 2.11.0 =
Semantic search scale tiers (opt-in).

* **Larger catalogs, faster search.** On MariaDB 11.7+ the index automatically uses the database's native vector search; for very large catalogs you can point the index at an external **Qdrant** server. Both are optional and auto-detected, typical stores keep using the built-in store with no change.
* Optional reranking hook for fine-tuning result order. No action needed; semantic search stays off until you enable it.

= 2.10.0 =
Semantic search hardening + provider flexibility.

* **Choose your embeddings provider.** Semantic search is no longer tied to OpenAI: pick OpenAI, **Cohere** (better for Urdu and other non-Latin scripts), or any **OpenAI-compatible endpoint** (e.g. Moonshot, Together, or a self-hosted server) by setting a base URL and key, so you can reuse a key you already have.
* **More resilient & cost-aware.** Embedding requests now retry rate-limit/temporary errors with backoff, repeated identical searches reuse a cached embedding, and the admin shows index health (indexed count, last build, failures) with a clear "rebuild needed" prompt when you change the model.

= 2.9.0 =
Semantic search (beta): find products by meaning, not just keywords.

* New **Semantic Search** setting: when enabled with an OpenAI key, product search becomes hybrid, it combines the existing keyword search with AI vector search and fuses the results, so "something warm for winter" finds the fleece even without the exact words.
* Off by default and fully backward compatible: search stays keyword-only until you switch it on, and always falls back to keyword search if the index is unavailable.
* Build the index from Settings with one click; it updates automatically as products change, skips unchanged products, and respects a per-day cap to control cost. Only product text is sent to OpenAI, never prices, stock, or customer data.

= 2.8.1 =
* Fixed a bug where a product could be shown as a duplicate card within a single reply when the assistant referenced it from more than one action in the same turn (e.g. a search followed by a details lookup). Each product now appears at most once per reply.

= 2.8.0 =
Support for all major AI providers.

* Choose your AI provider in Settings: **OpenAI** (GPT), **Google Gemini**, **Groq**, **Mistral**, **DeepSeek**, **xAI (Grok)**, **Together**, **OpenRouter**, **Perplexity**, a **local model via Ollama**, or any **custom OpenAI-compatible endpoint**: alongside the existing **Anthropic (Claude)** and **Moonshot (Kimi)**.
* Each provider has its own API key and model setting; the assistant streams replies for the OpenAI-compatible providers and uses the native API for Claude.
* Automatic failover already extends across whichever providers you've configured: if your chosen one is briefly unavailable, the assistant falls back to another keyed provider, then to search/support, never a dead end.
* Fully backward compatible: existing Anthropic and Moonshot setups keep working with no changes.

= 2.7.0 =
Multilingual replies, a PHP 8.1 fix, and developer seams for advanced search & channels.

* Multilingual: the assistant detects the shopper's language (English, Urdu, Roman Urdu) and replies in it, while keeping all product facts grounded; new admin "Languages" setting, and prices/numbers format for the locale.
* Fix: PHP 8.1 compatibility, a return type used PHP 8.2+ syntax that broke on 8.1; restored the stated minimum.
* Developer seams (need a provider to activate, ship inert): semantic/vector product search (`fahad_ai_semantic_retriever`, falls back to keyword search), a signed WhatsApp webhook + agent routing behind a send provider, and an image-search upload endpoint behind a vision-retriever seam.

= 2.6.0 =
Insight, voice, and helpful nudges.

* Owner analytics: a new admin dashboard showing top questions, the "couldn't answer" list, a chat-to-cart funnel, and cost per conversation, privacy-safe (no personal data stored), with export and delete controls.
* Voice: optional speech-to-text input and spoken replies in the chat widget (browser Web Speech API; off by default, text always works).
* Proactive help: an optional, value-gated nudge (off by default) that only appears when there's a genuinely applicable coupon or unused store credit, never fake urgency, frequency-capped, dismissible.

= 2.5.0 =
Checkout help, merchant controls, and quality hardening.

* Conversational checkout: the assistant can summarise the order (items, shipping options, totals), set a shipping method, and recommend or apply the best valid coupon, only with the shopper's consent, then hand off to the secure WooCommerce checkout for payment (it never handles card details).
* Merchant controls: a new admin section to set the assistant's tone, restrict which tools it may use, define off-limits topics and promo emphasis, and tune cost/model options. The trust guardrails can never be disabled.
* Hardening: the assistant always gives a one-line summary alongside product cards, currency symbols always render correctly, and internal quality-eval coverage was extended.

= 2.4.0 =
Privacy, reliability, and feedback.

* Privacy (GDPR): the assistant's opt-in saved preferences are now included in WordPress' personal-data Export and removed by its personal-data Erase tools.
* Reliability: if the configured AI provider is briefly unavailable, the assistant automatically falls back to the other configured provider; if neither responds it shows a friendly message and points to search/support instead of an error.
* Reply feedback: shoppers can give a thumbs up/down on replies so you can see where the assistant does well or needs work (no personal data stored).

= 2.3.0 =
New shopper features.

* Returns & exchanges: logged-in customers can check whether an order item is returnable (against the store's return window and the order's status) and submit a return request. The assistant only records the request for your team, it never issues a refund, and points to human support for anything ineligible.
* Back-in-stock & price-drop alerts: shoppers can ask to be notified when an out-of-stock item returns or a price drops. Opt-in with double confirmation and one-click unsubscribe; alerts only ever fire on a real stock or price change (no fake urgency).

= 2.2.0 =
New shopper features.

* Reorder / buy-it-again: logged-in customers can see what they've bought before and re-add available items in one step (each revalidated for current price and stock; sold-out items are reported, not silently dropped).
* Size & fit advice: the assistant surfaces a product's size options and any size chart, and offers a "runs small / true to size / runs large" hint, but only when the store's own reviews or a fit attribute support it, never invented.
* "Complete the look" bundles: an optional suggested bundle of complementary items with an honest combined price (a saving is shown only when items are genuinely on sale), respecting stock and any stated budget.

= 2.1.0 =
New: direct, verified Add-to-cart from chat product cards.

* The "Add to cart" button on chat product cards now adds the item instantly through a dedicated cart endpoint, with no AI round-trip, it's faster, and the confirmation always reflects the real cart result (it can no longer say "added" without actually adding). Variable products still require choosing an option first.

= 2.0.2 =
Maintenance & planning release, no functional plugin changes.

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
* Coupons & deals, only valid, applicable codes are shown and applied to the cart
* Best-sellers and category browsing
* Recommendations & optional cross-sells that respect a stated budget
* Order status & tracking for the logged-in customer (their own orders only)
* Wallet-aware shopping, balance and top-up via a decoupled provider, when a compatible wallet plugin is active
* Shipping & delivery estimates by destination
* Opt-in cross-session memory for stated preferences (viewable and clearable)

Trust & safety:
* Anti-dark-pattern guardrails encoded in the assistant: no fake scarcity, respect stated budgets, disclose upsells, abstain instead of guessing, and never block human support
* Privacy/authorization boundary for personal-data tools, login-gated, with strict per-record ownership checks
* WCAG 2.2 AA accessibility pass on the chat widget and product cards

Under the hood:
* Tool extensibility hook (`fahad_ai_register_tools`) so add-ons can register assistant tools without forking the core; built-in tools migrated to a drop-in registry
* Cost & latency controls, tool results trimmed before being fed back to the model, per-conversation token budget, and a model-routing seam
* An offline eval harness (golden conversations + grounding checks) runs in CI to guard answer quality

= 1.1.0 =
* New: product results now render as rich visual cards in the chat, product photo, price (with sale price), stock status, a short description, and View / Add to cart buttons
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
* Renamed plugin to "Dukandar AI Shopping Assistant for WooCommerce" with the `fahad-ai-shopping-assistant-for-woocommerce` slug (final name approved by the WordPress.org Plugin Directory)
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

= 2.14.5 =
Rebrand to Dukandar. Name-only change; settings and functionality are preserved.

= 2.14.4 =
Editorial pass: plain-text copy with no em-dashes across the readme, admin UI, and docs. No functional changes for shoppers or merchants.

= 2.14.3 =
Packaging-only release: development files removed from the distribution zip. No functional changes for shoppers or merchants.

= 2.14.2 =
Maintenance release: refreshed all external-service ToS/privacy links to current URLs, confined the WP-CLI report path to the uploads directory, and made REST permission callbacks statically verifiable. No functional changes for shoppers or merchants.

= 2.14.1 =
Maintenance release: WordPress.org guideline compliance, adds full external-service disclosure with ToS/privacy links for all providers, and moves the RAG spike report out of the plugin folder. No functional changes for shoppers or merchants.

= 2.11.1 =
Maintenance release: WordPress.org guideline compliance (HTTP API, input sanitization) and WP 7.0 confirmation. No functional changes.

= 2.11.0 =
Adds optional scale tiers for semantic search (MariaDB native vectors, external Qdrant) and a reranking hook. All opt-in; no change for typical stores.

= 2.10.0 =
Semantic search now works with Cohere or any OpenAI-compatible endpoint (not just OpenAI), plus retry/caching and index-health reporting. No action needed; semantic search stays off until you enable it.

= 2.9.0 =
Adds optional semantic (AI vector) product search, off by default. Enable it in Settings with an OpenAI key to let shoppers find products by meaning; search is unchanged until you do.

= 2.8.1 =
Fixes a bug where the same product card could appear twice in one reply when the assistant looked a product up more than once in a single turn.

= 2.8.0 =
Adds support for all major AI providers, OpenAI, Gemini, Groq, Mistral, DeepSeek, xAI, Together, OpenRouter, Perplexity, local Ollama, and custom OpenAI-compatible endpoints, alongside Claude and Moonshot. Backward compatible; existing setups are unchanged.

= 2.7.0 =
Adds multilingual replies (incl. Urdu / Roman Urdu), fixes PHP 8.1 compatibility, and adds developer seams for semantic search, WhatsApp, and image search (each needs a provider to activate). Backward compatible.

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
Maintenance release: documentation and internal planning only, no functional changes. Safe to skip if you are on 2.0.1.

= 2.0.1 =
Fixes from live QA: smarter product search (plurals and descriptive queries), a friendly fallback instead of a raw loop error, correct currency rendering in streamed replies, and add to cart now persists for guests on the streaming provider.

= 2.0.0 =
Major update: reviews, variations, comparison, coupons, recommendations, order status, wallet, shipping, and opt-in memory, plus trust guardrails, a privacy/auth boundary, accessibility, and cost controls. Backward compatible; no settings changes needed.

= 1.1.0 =
Product results now appear as rich cards with photo, price, stock, and Add-to-cart buttons.

= 1.0.7 =
Fixes Moonshot streaming returning a blank reply and adds a Global/China region selector for Moonshot API keys.

= 1.0.6 =
Security and compatibility update: adds rate limiting to the chat endpoints and raises the minimum PHP version to 8.0.

= 1.0.4 =
Existing v1.0.2 users: option keys have been renamed from `wc_ai_chatbot_*` to `fahad_ai_*`. Settings will need to be re-entered after upgrade.
