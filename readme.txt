=== Fahad AI Shopping Assistant for WooCommerce ===
Contributors: fahdi
Tags: woocommerce, chatbot, ai, cart, assistant
Requires at least: 6.0
Tested up to: 7.0
Requires PHP: 8.0
Stable tag: 1.0.7
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
* Add products to the customer's cart
* View current cart contents with totals
* Remove items from the cart
* Stream responses in real-time (Moonshot provider)

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

= 1.0.7 =
Fixes Moonshot streaming returning a blank reply and adds a Global/China region selector for Moonshot API keys.

= 1.0.6 =
Security and compatibility update: adds rate limiting to the chat endpoints and raises the minimum PHP version to 8.0.

= 1.0.4 =
Existing v1.0.2 users: option keys have been renamed from `wc_ai_chatbot_*` to `fahad_ai_*`. Settings will need to be re-entered after upgrade.
