=== Dukandar AI Shopping Assistant for WooCommerce ===
Contributors: fahdi
Tags: woocommerce, chatbot, ai, cart, assistant
Requires at least: 6.0
Tested up to: 7.0
Requires PHP: 8.0
Requires Plugins: woocommerce
Stable tag: 2.14.56
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

= 2.14.56 =
Know if it's working: the weekly email now shows whether your chat-to-cart rate is rising or falling.

* The weekly summary's chat-to-cart line now includes a week-over-week trend, for example "(26% chat-to-cart, up 5 points from last week)", so you can see at a glance whether the assistant is improving or slipping and act on it. Shown only once there is a prior week to compare against.

= 2.14.55 =
Consistency: search now respects your "hide out of stock items" setting.

* If your store hides out-of-stock products from the catalogue, the assistant now hides them from search results too, so it never recommends something a shopper cannot buy. When the setting is off (the default), results are unchanged.

= 2.14.54 =
Reliability fix: an awkwardly-phrased price range no longer returns nothing.

* Fixed an edge case where a reversed price range (for example "between $100 and $50") made product search return no results. The range is now normalized (bounds ordered, negatives floored to 0) so an inverted or out-of-range request still returns the products the shopper meant. No change for normal price filters.

= 2.14.53 =
Reliability fix: product search now safely handles an out-of-range result limit.

* Fixed an edge case where a zero or negative result limit could make product search return the entire catalogue (spiking token cost and slowing responses) or no results at all. The limit is now always clamped to a safe 1 to 10 range. No change for normal searches.

= 2.14.52 =
Lost demand, delivered to your inbox: the weekly email now includes searches that found nothing.

* The weekly summary email now carries the same "searches with no results" list added to the dashboard in 2.14.51, so the demand your catalogue is missing reaches you even if you never open the dashboard, with a pointer on what to do (stock it, rename for findability, add synonyms). Omitted when there is nothing to report.

= 2.14.51 =
See lost demand: the analytics dashboard now shows searches that returned no results.

* A new "Searches with no results" panel on the analytics dashboard lists what shoppers searched for but found nothing, real demand your catalogue did not meet. Use it to decide what to stock, rename products so they are findable, or add synonyms. Built from data already recorded (no new tracking), privacy-safe and range-filtered like the rest of the dashboard.

= 2.14.50 =
Sort it your way: shoppers can now ask for cheapest first, best-rated, or most popular.

* Product search gains an opt-in sort option (cheapest first, most expensive first, best-rated, most popular), mapped to WooCommerce's own ordering. So "show me your cheapest jackets" returns a correctly ordered, grounded result instead of a best-match guess. Omitted by default, so ordering is unchanged unless a sort is asked for.

= 2.14.49 =
Find the good stuff: shoppers can now ask for well-rated products and get a precise result.

* Product search gains an opt-in minimum-rating filter, so when a shopper asks for "top-rated" or "4-star-and-up" jackets the assistant returns only products whose real average rating clears that bar, composing with category, price, and on-sale. Unset by default, so nothing changes unless a rating is asked for.

= 2.14.48 =
Social proof at first glance: the assistant can now flag top-rated products while listing options.

* Product list results (search, recommendations, best-sellers, bundles, comparisons) now include the same grounded "highly_rated" flag as the product detail view, so the assistant can lead with "the top two are highly rated" the moment it presents options. Based on your real reviews (4.5+ stars with at least 5 reviews), never invented.

= 2.14.47 =
Recover sold-out moments: the assistant can now offer alternatives when a shopper tries to buy an out-of-stock item.

* When a shopper tries to add an out-of-stock product to the cart, the response now includes that product's real categories, so instead of a dead-end "out of stock" the assistant can say "that one's sold out, want to see other Jackets?" and steer them to in-stock alternatives at the most motivated moment. Only genuine categories are offered.

= 2.14.46 =
Keep free shipping in view: the assistant now updates free-shipping progress after an item is removed.

* Removing an item from the cart now returns the same grounded free-shipping progress already shown when adding or viewing the cart, so if the removal drops the order below your threshold the assistant can say "you're now $9 away from free shipping" and invite the shopper to re-add. Only when a threshold is configured.

= 2.14.45 =
Scarcity at the right moment: the assistant can now reinforce low stock right after an add to cart.

* Adding an item to the cart now returns a grounded low-stock signal (and the remaining quantity) for the exact item added, so when something is nearly sold out the assistant can honestly say "added, and only 2 left, best to check out soon" at the peak-intent moment. Shown only when stock is genuinely low, from real inventory.

= 2.14.44 =
No more dead ends: when a search finds nothing, the assistant can offer real browse paths.

* When a product search turns up no matches, the results now include a short list of your real store categories, so the assistant can say "I could not find that, but you could browse Shoes or Bags" instead of leaving the shopper at a dead end. Only genuine categories are ever suggested.

= 2.14.43 =
Deals visible at first glance: the assistant can now lead shoppers to the best deal while listing options.

* Product list results (search, recommendations, best-sellers, bundles, comparisons) now include the same grounded "discount_percent" as the product detail view, so the assistant can say "the blue one is 30% off" the moment it presents options, without a separate lookup. Computed from your real prices, and null when a product is not on sale.

= 2.14.42 =
Bestseller social proof: the assistant can now point shoppers to your proven best-sellers.

* Product details now include a "bestseller" signal computed from a product's real lifetime units sold against a new Bestseller Threshold setting. When you set a threshold, the assistant can honestly highlight proven best-sellers as social proof, grounded in real sales. It is opt-in: with no threshold set, no product is ever badged, so a bestseller claim is never invented.

= 2.14.41 =
Coupon confirmation: the assistant can now tell shoppers exactly how much a code saved them.

* Applying a discount code now returns a "discount_amount" computed from WooCommerce's real cart discount, so the assistant can confirm "that code saved you $8.50" right after it is applied. It is omitted when a code reduces nothing (for example a free-shipping-only code), so a shopper is never told about a $0 saving.

= 2.14.40 =
Savings reassurance at cart review: the assistant can now tell shoppers how much they are saving.

* Viewing the cart now includes a "cart_savings" total computed from your real regular and sale prices across all discounted items, so at the moment shoppers decide whether to check out, the assistant can reinforce "you're saving $42 across your cart." It is omitted whenever nothing is genuinely on sale, so a shopper is never shown a fabricated or zero saving.

= 2.14.39 =
Honest urgency: the assistant can now tell shoppers exactly how big a discount is.

* Product details now include a "discount_percent" value computed from your real regular and sale prices, so on a genuinely discounted product the assistant can say "20% off right now" and create real urgency. It stays null whenever there is no true reduction, so a shopper is never shown a fabricated or zero deal.

= 2.14.38 =
Honest social proof: the assistant can now highlight your genuinely top-rated products.

* Product details now include a "highly rated" signal computed from your real average rating and review count (4.5 stars with at least 5 reviews). The assistant can use it to point shoppers to well-reviewed products, a strong and honest conversion nudge, while never inventing popularity.

= 2.14.37 =
Send the assistant's emails to the right person, not just the site admin.

* Added a "Notifications Email" setting. The welcome email and weekly digest now go to the address you set here (falling back to the WordPress admin email if blank), so a shop manager or marketing lead, rather than a developer or hosting address, receives them.

= 2.14.36 =
The dashboard widget now nudges you to finish setup, on the screen you see every login.

* The Dukandar dashboard widget now shows your setup progress ("X of Y steps complete") with a link to finish, so the prompt to complete the high-value setup appears where you actually look, not buried in Settings.

= 2.14.35 =
Tune abuse protection without code: the per-visitor request limit is now a setting.

* Added a "Requests Per Minute" setting controlling how many messages a single visitor can send before being asked to slow down. Lower it if you see a client spamming the assistant and running up cost. Previously this was only tunable in code. The fahad_ai_rate_limit filter still overrides it for developers.

= 2.14.34 =
Get more from the assistant: a setup-progress checklist shows what is done and what to finish.

* Added a "Setup progress" checklist at the top of the settings page. It shows which high-value steps are done (provider connected, Store Information added, support contact set, free-shipping threshold set) and which are still to do, so you can quickly finish setting up and get the full benefit of the assistant.

= 2.14.33 =
See your assistant's results on every login: a WordPress dashboard widget.

* Added a "Dukandar Assistant" widget to the WordPress dashboard, the screen you land on at every login. It shows the last 7 days of conversations, chat-to-cart rate, and resolution rate, plus this month's AI spend, with a link to the full analytics. The plugin's value is now visible without opening anything.

= 2.14.32 =
No more invoice surprises: set a monthly AI budget and get warned when you reach it.

* Added a "Monthly Budget" setting. When this calendar month's AI spend reaches the amount you set, the admin shows a warning so you can react (raise the budget, lower the daily message limit, or pause) before the provider bill arrives. Resets automatically each month. 0 = no budget.

= 2.14.31 =
Distraction-free checkout: optionally hide the assistant on the cart and checkout pages.

* Added a "Hide at Checkout" option. Turn it on to keep the assistant available across your storefront but remove it from the cart and checkout pages, so nothing competes for attention while a shopper is completing their purchase. Off by default, so nothing changes unless you choose it.

= 2.14.30 =
Your weekly email now includes what shoppers disliked, so the whole fix-list is in one place.

* The weekly digest now lists the reasons shoppers rated a reply unhelpful, alongside the questions the assistant could not answer. Both halves of the action list, content gaps and quality gaps, land in your inbox each week so you know exactly what to improve without opening the dashboard.

= 2.14.29 =
See exactly what to fix: the analytics now list the replies shoppers rated unhelpful, with their reasons.

* The analytics now show recent thumbs-down replies alongside the shopper's own reason, so you can see precisely which answers disappointed customers and fix them at the source (for example in Store Information). The actionable companion to the helpfulness rate.

= 2.14.28 =
Set your limits with eyes open: the settings page now shows this month's AI spend.

* The Cost &amp; Performance settings now show your month-to-date AI spend and conversation count, right above the token budget and daily cap. No more setting cost limits blind, you can see what you are actually spending as you set them.

= 2.14.27 =
Your shoppers' verdict: the analytics now show the helpfulness rate from thumbs up/down.

* The plugin already collected shopper thumbs up/down on replies; now the analytics surface it as a "Shopper helpfulness" rate with the up/down counts. It is your customers' own judgement of answer quality, next to the system's resolution rate, so you can see how well the assistant is really landing.

= 2.14.26 =
An off switch: pause the assistant in one click without deactivating the plugin.

* Added an "Enable Assistant" toggle. Untick it to pause the assistant everywhere: the widget disappears and no AI calls are made, while every setting is kept. Handy for maintenance, testing a change, or instantly stopping costs, without the heavier step of deactivating the plugin.

= 2.14.25 =
A friendly welcome: confirm the assistant is live and get set up for success from day one.

* The first time you connect an AI provider, Dukandar emails the store admin a one-time welcome: confirmation that the assistant is live, how to test it, and the few settings that make it most effective (Store Information, free-shipping threshold, support contact, analytics). Sent once, so it never nags.

= 2.14.24 =
Your weekly email now includes the resolution rate, the "is it working?" number, at a glance.

* The weekly digest now shows the assistant's resolution rate alongside conversations, chat-to-cart, and cost, so you see how much of the workload it is handling on its own without opening the dashboard.

= 2.14.23 =
Know if it is working: the analytics now show your assistant's resolution rate.

* Added a "Resolution rate" to the analytics: the share of questions the assistant actually answered, rather than escalating, abstaining, or erroring. A low rate is your cue to enrich Store Information so the assistant handles more on its own, the same gaps the weekly digest already lists.

= 2.14.22 =
Honest urgency: the assistant can now say "only a few left" when a product really is low in stock.

* Product details now include a low-stock signal computed from your real WooCommerce stock levels and low-stock threshold. The assistant can use it to nudge genuine urgency ("only 2 left"), a strong and legitimate conversion lever, while staying true to its promise of never inventing or exaggerating scarcity.

= 2.14.21 =
Free-shipping nudge at the perfect moment: right after a shopper adds to cart.

* The assistant now knows the exact amount left to reach free shipping the instant an item is added, the classic "add a little more to unlock free shipping" moment. Combined with the cart view added in 2.14.20, it can prompt at the two highest-impact points in the buying flow. Only when a free-shipping threshold is set, and always from real cart numbers.

= 2.14.20 =
Exact free-shipping nudge in the cart: "you are $X away from free shipping", from real cart data.

* When a free-shipping threshold is set, the assistant now knows the exact amount left to qualify from the real cart total, so it can say precisely how much more to add instead of estimating. One of the most reliable ways to lift order value, now grounded in actual numbers rather than a guess.

= 2.14.19 =
Your weekly email now tells you what to fix: the questions the assistant could not answer.

* The weekly digest now includes the top questions shoppers asked that the assistant could not answer, with a pointer to add the answers under Store Information. Turns the summary into an action list: fill the gaps once and the assistant handles them from then on. Shown only when there are gaps.

= 2.14.18 =
Teach the assistant your store: answer shipping, sizing, and FAQ questions, not just product data.

* Added a "Store Information / FAQ" setting. Enter facts about your store, delivery times, sizing and fit, materials and care, brand or warranty details, common questions, and the assistant answers from them when relevant instead of deflecting. It treats your information as authoritative for your store and never invents details beyond it. Turns the assistant from a product-catalogue helper into a store expert.

= 2.14.17 =
No more dead ends: give the assistant your support contact so "talk to a human" leads somewhere.

* Added a "Support Contact" setting (email, phone, or contact page). When set, the assistant shares it exactly when a shopper needs a person or it cannot help, instead of vaguely saying "contact support". It never invents a contact. A small change that stops losing the shoppers who reach the edge of what the assistant can do.

= 2.14.16 =
Don't lose sales to your own cost cap: get warned before the daily limit turns shoppers away.

* If you set a daily usage cap, the admin now warns you once the store nears it (at 80% by default), so you can raise the limit before the assistant starts pointing shoppers to human support at a busy time. A stronger notice appears once the limit is reached. The warning clears itself each day. This makes the cost cap safe to use without risking a silent afternoon of lost assisted sales.

= 2.14.15 =
Clean install on modern WooCommerce: declared High-Performance Order Storage (HPOS) compatibility.

* The plugin now declares compatibility with WooCommerce High-Performance Order Storage (HPOS / custom order tables), the default for new stores. This removes the "incompatible" warning some stores saw in the Plugins screen and on the HPOS settings page. No behaviour change: the assistant already read orders only through WooCommerce's standard order APIs.

= 2.14.14 =
See your results without logging in: a weekly email summary of what the assistant did.

* Added an optional weekly email digest to the store admin: conversations, chat-to-cart rate, chat-attributed orders, AI cost, and the top questions shoppers asked, for the last 7 days. It is sent only when there was activity (never an empty email) and can be turned off with one tick in Settings. A regular reminder of the value the assistant is adding, right in your inbox.

= 2.14.13 =
Fewer abandoned carts: the assistant can now answer return-policy questions accurately from your own words.

* Added a "Return &amp; Refund Policy" setting. When you enter your policy, the assistant answers return, refund, and exchange questions using only what you wrote, one of the biggest sources of pre-purchase hesitation, so a shopper gets a clear answer instead of a shrug. It never invents terms and refers anything your policy does not cover to human support.

= 2.14.12 =
Sell more per order: the assistant can now nudge shoppers toward your free-shipping threshold.

* Added a "Free Shipping Threshold" setting. When you enter the order amount that unlocks free shipping, the assistant can helpfully tell a shopper how much more they need to add to qualify, one of the most reliable ways to lift average order value. It is a grounded fact from your setting, stated plainly and never as pressure, and the assistant will never invent a threshold you have not set.

= 2.14.11 =
Health guard: get warned if your AI provider stops working, instead of losing sales silently.

* Added an admin warning that appears when the assistant has failed a cluster of responses in the last 24 hours, the usual sign of an API key that is wrong, expired, or out of credit. It points you straight to your provider settings so a broken key gets fixed fast, instead of the widget quietly going dead and costing you sales. The alert threshold is filterable (fahad_ai_error_alert_threshold, default 3) and it clears itself once things recover.

= 2.14.10 =
ROI at a glance: the analytics now show your chat-to-cart conversion rate.

* Added a "Chat-to-cart rate" row to the Conversion funnel (Settings analytics), showing the share of assistant conversations that reached the cart as a single percentage. Owners no longer have to do the math on raw counts to see the assistant's impact.

= 2.14.9 =
Cost safety, now owner-friendly: set the daily usage cap from the settings screen, no code needed.

* Added a "Daily Message Limit" field to Settings > Cost &amp; Performance. Store owners can now cap total AI answers per day right from the admin, so the cost safeguard is usable without touching PHP. The fahad_ai_daily_message_cap filter still overrides the saved value for developers, and enforcement is unchanged (graceful hand-off to human support at the limit, resets daily).

= 2.14.8 =
Cost safety: a store-wide daily cap keeps your AI spend predictable.

* Added a filterable daily limit on total AI answers (fahad_ai_daily_message_cap, default 0 = unlimited). When reached, the assistant politely points shoppers to human support instead of making more billable calls, and the counter resets each day. This protects against bill-shock from a traffic spike or abuse across many IPs.

= 2.14.7 =
A gentle, one-time review request appears after two weeks of configured use, so happy stores can help others find Dukandar.

* Added a dismissible admin notice inviting a WordPress.org review, shown only once a provider is configured and the plugin has been active for two weeks, and only to users who can manage the assistant. Dismissing it is permanent.

= 2.14.6 =
Setup nudge: if Dukandar is active but no AI provider is configured, an admin notice now prompts you to add your API key, so an unconfigured store no longer looks broken.

* Added a dismissible admin notice, shown only to users who can manage the assistant and only when no provider key is set, with a one-click link to the settings page. It disappears once a key is saved.

= Older versions =

Only recent releases are listed here to stay within the changelog length WordPress.org supports. For the complete history of earlier versions, see the GitHub releases: https://github.com/fahdi/dukandar-shopping-assistant-for-woocommerce/releases


== Upgrade Notice ==

= 2.14.56 =
Adds a week-over-week trend to the weekly email's chat-to-cart rate so you can see if the assistant is improving. No breaking changes.

= 2.14.55 =
Search now respects your "hide out of stock items" catalog setting, so the assistant does not recommend products shoppers cannot buy. No breaking changes.

= 2.14.54 =
Reliability fix: normalizes a reversed or negative price range so an awkward request still returns the right products. No breaking changes.

= 2.14.53 =
Reliability fix: clamps an out-of-range search limit so a bad value can never dump the whole catalogue or return nothing. No breaking changes.

= 2.14.52 =
Adds the "searches with no results" demand list to the weekly summary email so it reaches you without opening the dashboard. No breaking changes.

= 2.14.51 =
Adds a "Searches with no results" panel to the analytics dashboard so you can see and act on demand your catalogue is missing. No breaking changes.

= 2.14.50 =
Adds opt-in result sorting (cheapest, most expensive, best-rated, most popular) to product search. No breaking changes.

= 2.14.49 =
Adds an opt-in minimum-rating filter to product search so shoppers can narrow directly to well-rated products. No breaking changes.

= 2.14.48 =
Surfaces the grounded highly-rated flag in product list results so the assistant can lead with social proof while listing options. No breaking changes.

= 2.14.47 =
Offers the product's categories when an out-of-stock item can't be added, so the assistant can steer shoppers to in-stock alternatives. No breaking changes.

= 2.14.46 =
Re-surfaces free-shipping progress after an item is removed so the assistant can nudge shoppers who drop below the threshold. No breaking changes.

= 2.14.45 =
Adds a grounded low-stock signal to the add-to-cart response so the assistant can reinforce scarcity at the peak-intent moment. No breaking changes.

= 2.14.44 =
Suggests real store categories when a search finds nothing, so the assistant can redirect shoppers instead of hitting a dead end. No breaking changes.

= 2.14.43 =
Surfaces the grounded discount percentage in product list results so the assistant can lead shoppers to the best deal while listing options. No breaking changes.

= 2.14.42 =
Adds an opt-in Bestseller Threshold and a grounded per-product bestseller signal so the assistant can highlight proven best-sellers as honest social proof. No breaking changes.

= 2.14.41 =
Adds a grounded confirmation of how much an applied coupon saved so the assistant can reassure shoppers right after they redeem a code. No breaking changes.

= 2.14.40 =
Adds a grounded total-savings figure at cart review so the assistant can reassure shoppers how much they are saving. No breaking changes.

= 2.14.39 =
Adds a grounded "X% off" discount signal from your real prices so the assistant can create honest urgency on genuine sale items. No breaking changes.

= 2.14.38 =
Adds an honest "highly rated" signal from your real reviews so the assistant can highlight top-rated products. No breaking changes.

= 2.14.37 =
Adds a Notifications Email setting so the assistant's emails reach whoever manages the store, not just the site admin. No breaking changes.

= 2.14.36 =
The dashboard widget now shows setup progress with a link to finish. No breaking changes.

= 2.14.35 =
Adds a Requests Per Minute setting so you can tune per-visitor abuse protection without code. No breaking changes.

= 2.14.34 =
Adds a Setup progress checklist to the settings page so you can finish setup and get the full benefit. No breaking changes.

= 2.14.33 =
Adds a WordPress dashboard widget with the assistant's key stats, visible on every login. No breaking changes.

= 2.14.32 =
Adds a Monthly Budget setting that warns you when this month's AI spend reaches it, before the provider invoice. No breaking changes.

= 2.14.31 =
Adds a Hide at Checkout option to keep the cart and checkout pages distraction-free. Off by default. No breaking changes.

