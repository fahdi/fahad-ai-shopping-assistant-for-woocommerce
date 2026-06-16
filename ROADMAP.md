# Fahad AI Shopping Assistant — Product Roadmap

> Working document. The thesis: **an assistant earns conversion by being a trustworthy advisor, not by nudging harder.** Everything below is judged against that.

This is a strategy/roadmap doc for maintainers. It is excluded from the distributed plugin zip.

---

## 1. What a shopping assistant *should* be

An AI shopping assistant serves two parties whose interests mostly — but not entirely — align:

- **The shopper** wants the right product, fast, with confidence, at a fair price, without being manipulated.
- **The store owner** wants conversion, higher average order value, retention, and lower support cost.

These reconcile through one mechanism: **trust**. A shopper who believes the assistant is honest will take its recommendation, buy more, and come back. An assistant that optimizes the next click with fake urgency and hidden upsells wins one order and loses the relationship. So the design north star is *be a great advisor; conversion is the byproduct.*

From that, the principles this product is built and measured against:

1. **Advisor, not a search box with a chat skin.** Understand the need behind the query ("running shoes for flat feet") and explain *why* a recommendation fits — consultative, not keyword matching.
2. **Grounded and honest.** Never invent specs, price, or stock. Cite the source (product data, reviews). Say "I'm not sure" and route to a human rather than guess. *(Already enforced: the system prompt forbids inventing product details.)*
3. **Action-capable.** Close the loop — add to cart, apply a coupon, check out, check an order — not just describe.
4. **Context-aware.** Know the cart, the customer (when logged in), what they're viewing, and their currency/locale/language.
5. **Respectful of autonomy.** Surface trade-offs, support comparison, disclose upsells. No dark patterns.
6. **Fast and low-friction.** Stream responses, minimize round-trips, work on mobile, be accessible.
7. **Private and safe.** Gate personal data behind auth, rate-limit, resist prompt injection, never leak PII to the model or logs.
8. **Measurable and improvable.** The owner must see what customers ask, where the assistant fails, and its conversion impact.
9. **Gracefully degrading.** When the AI is unsure or down, fall back to search/support — never a dead end.
10. **Extensible.** Other plugins (incl. the owner's own) should be able to add capabilities without forking.

### Shopper jobs-to-be-done
Every feature should map to one of these:

| Job | "Help me…" |
|---|---|
| Discover | …figure out what to buy |
| Decide | …be confident this is the right choice (specs, reviews, fit, comparison) |
| Qualify | …know if I can actually have it (stock, delivery, ETA, cost) |
| Save | …get the best honest deal (price, coupons, bundles, store credit) |
| Transact | …buy with minimal friction |
| Follow up | …after purchase (order status, returns, how-to) |
| Recover | …when something is wrong (escalate to a human) |

---

## 2. Where we are today

**Capabilities (grounded in the code):**
- Agentic loop over **5 WooCommerce tools** (`includes/class-tools.php`): `search_products`, `get_product_details`, `add_to_cart`, `view_cart`, `remove_from_cart`.
- Two providers (Anthropic, Moonshot/Kimi), Global/China region select, SSE streaming on the Moonshot path.
- **Rich product cards** (v1.1.0): photo, price, sale, stock, View / Add-to-cart, sourced from trusted WC data via a `products` SSE event / response field.
- Security baseline: `wp_rest` nonce + per-client rate limit on the public endpoints.

**Honest gap analysis (principle → what's missing):**

| Principle | Gap today |
|---|---|
| Decide | No reviews/ratings, no comparison, no variation handling in chat |
| Discover | Keyword search only (no semantic/related/best-seller surfacing) |
| Qualify | No delivery/shipping estimate; stock is boolean, not "X left / restock" |
| Save | No coupons, bundles, or store-credit awareness |
| Follow up | No order status / tracking (needs an auth boundary) |
| Context | No cross-session memory/personalization; no locale/language adaptation |
| Measurable | No owner analytics or "questions we couldn't answer" report |
| Improvable | No eval harness for answer/tool-call quality (a real risk for an AI product) |
| Extensible | Tools are hardcoded; no registration hook for add-ons |
| Fast/consistent | Anthropic path has no streaming; Add-to-cart costs a full agent round-trip |

---

## 3. Roadmap

Effort: **S** ≈ <1 day, **M** ≈ 2–4 days, **L** ≈ a week+. Every item ships with tests (project rule: no feature without tests) and, where it changes answers, an eval case.

### Now — high impact, mostly local WooCommerce data
| Feature | Job | Owner value | Implementation sketch | Effort | Key risk |
|---|---|---|---|---|---|
| **Reviews & ratings** | Decide | Trust → conversion | New `get_product_reviews` tool (WC reviews are comments); show ★avg + count on the card + a one-line AI sentiment summary | M | Summary must not fabricate — quote/derive only from real reviews |
| **Variations in chat** | Decide/Transact | Sell variable products | Surface attributes in `get_product_details`; let `add_to_cart` take a chosen `variation_id`; render selectors in the card | M | Attribute/stock correctness per variation |
| **Product comparison** | Decide | Reduces bounce | `compare_products(ids[])` → comparison-table card (price, key attrs, rating, stock) | M | Choosing which attributes matter per category |
| **Coupons & deals** | Save | AOV, clearance | `list_active_coupons` / `apply_coupon`; only ever show valid, applicable codes | M | Never invent codes; respect usage limits |
| **Best-sellers & category browse** | Discover | Merchandising | `get_top_products`, `list_categories` | S | — |

### Next — differentiation; some need auth or integration
| Feature | Job | Owner value | Implementation sketch | Effort | Key risk |
|---|---|---|---|---|---|
| **Recommendations & cross-sell** | Discover/Decide | AOV | Use WC related/upsell/cross-sell + AI need-matching; "frequently bought together"; gift/use-case mode | M | Stay relevant, disclose upsell, respect budget |
| **Order status & tracking** | Follow up | Deflects support tickets | Auth-gated `get_my_orders`/`get_order_status`; requires a real authorization boundary, not just the nonce | M | **Privacy** — strict ownership checks; never expose others' orders |
| **Wallet / store credit** *(see §5)* | Save/Transact | Ties to your wallet plugins | `get_wallet_balance`, `top_up` (+deposit bonus), pay-with-credit | M | Money-safety; mirror Account Funds invariants |
| **Shipping & delivery estimate** | Qualify | Removes "will it arrive?" doubt | `estimate_delivery` from WC zones + customer location | M–L | Accuracy across zones/carriers |
| **Personalization & memory** | Context | Retention | Remember stated preferences across sessions (opt-in, per-user) | M–L | Privacy/consent; storage hygiene |

### Later — platform bets
| Feature | Job | Owner value | Implementation sketch | Effort | Key risk |
|---|---|---|---|---|---|
| **Semantic / vector search** | Discover | Better matches than keyword | Embed catalog; vector lookup feeding `search_products` | L | Index freshness, hosting of embeddings |
| **Multilingual (Urdu/English)** | All | Reach for this store specifically | Detect/select language; localized prompts + UI strings | M–L | Quality of non-English answers |
| **Owner analytics & "unanswered questions"** | — (owner) | Product stickiness + merchandising | Log intents/outcomes; dashboard of top questions, failures, chat→conversion | M–L | Privacy-safe logging; storage |
| **Visual search** | Discover | Novelty, apparel/decor fit | Image upload → similarity over catalog | L | Cost, accuracy |
| **Proactive assist** | Discover/Recover | Recover abandons | Exit-intent / PDP / cart nudges | M | Easily becomes spam — strict frequency + value gate |

---

## 4. Cross-cutting foundations (do alongside, not after)

These are not glamorous but they decide whether the AI features are *shippable*.

- **Assistant eval harness.** A golden set of conversations with assertions on (a) which tools get called and (b) answer quality (grounded, no hallucinated specs). This is the AI analogue of the existing unit tests and the only way to add features without silently regressing answer quality. **Build this early.**
- **Cost & latency controls.** Per-conversation token budget, tool-result trimming (don't feed full product blobs back to the model), catalog/review caching, and **model routing** (cheap model for greetings/simple lookups, capable model for reasoning). Directly affects the owner's API bill.
- **Trust guardrails (mostly prompt + policy + tests).** No fabricated scarcity, respect stated budget, disclose upsells, prefer "I don't know + escalate" over guessing.
- **Privacy & auth boundary.** Any personal-data tool (orders, wallet, memory) needs capability/ownership checks beyond the nonce. Keep PII out of model context and logs where possible.
- **Accessibility.** WCAG 2.2 AA pass on the widget (focus management, ARIA, contrast, keyboard, reduced-motion). Cards added new interactive elements — audit them.
- **Extensibility hook.** A `fahad_ai_register_tools` filter so add-ons register tools (name, schema, callback). This turns wallet/shipping/loyalty integrations into clean add-ons instead of core coupling — and is the right architecture for everything above.
- **Streaming parity / cheaper actions.** Consider a streaming path for Anthropic, and a direct `add_to_cart` action (skip the full agent round-trip the card button currently triggers) to cut latency and tokens.
- **WP.org hygiene.** Disclose every external service; justify any direct cURL; keep new tools using local WC data where possible to avoid review friction.

---

## 5. Standout opportunity — Wallet-aware shopping (your ecosystem)

This store runs **WalletPro** (and Account Funds is in the same portfolio). No generic competitor can do this — it's a moat built from products you already own:

- "**What's my balance?**" → `get_wallet_balance`
- "**Top up Rs 2000**" → `top_up`, and surface the **deposit bonus** automatically ("add Rs 2000, get Rs X bonus")
- "**Pay with my store credit**" → apply wallet at checkout
- Proactive, honest nudge: "You have Rs 500 credit — want to use it on this order?"

Implemented via the extensibility hook (§4), the wallet plugin registers these tools into the assistant — keeping the assistant core clean and making "AI + wallet" a bundled selling point across your plugins. Money-safety must mirror the Account Funds invariants (no double-spend, compensating rollback). **This is the highest-differentiation, lowest-competition item on the list.**

Related: `ai-provider-for-anthropic` is active on this site. Consider letting the assistant optionally source its key/model from a site-wide AI provider plugin so the owner configures credentials once.

---

## 6. Anti-features — what we should deliberately NOT build

Critical thinking includes refusing the tempting-but-corrosive:

- **No fake urgency/scarcity** ("3 people viewing!") unless literally true.
- **No hidden or auto-added items**, no pre-checked upsells.
- **No hallucinated specs, reviews, or availability** — ever; ground or abstain.
- **No over-collection of PII**; don't ask for data the task doesn't need.
- **Never block the human-support path** to force self-service.
- **No pushing out-of-budget products** when the shopper stated a budget.

Encode these as guardrail tests so they can't regress.

---

## 7. How to tell it's working (success metrics)

- **Resolution/containment rate** — share of chats that end without needing human support.
- **Chat-attributed conversion & AOV** — orders and basket size influenced by the assistant.
- **Card add-to-cart rate** — clicks on Add from cards.
- **Escalation rate** — how often it correctly hands off to a human.
- **Hallucination rate** — measured via the eval harness (target ~0 on grounded claims).
- **CSAT / thumbs** — a lightweight rating on responses.

Instrumenting these is itself a feature (§3 "Owner analytics").

---

## 8. Suggested sequencing & rationale

1. **Eval harness + reviews/ratings.** The harness de-risks every later AI change; reviews are the single biggest trust lever and use local data. Do them together.
2. **Comparison + variations + best-sellers/coupons.** Completes the "decide & save" jobs with mostly-local data and low review risk.
3. **Wallet integration (via the extensibility hook).** Build the hook, then wallet — highest differentiation, leverages your portfolio.
4. **Order status + recommendations.** Adds the post-purchase and AOV jobs; introduces the auth boundary you'll reuse.
5. **Owner analytics, multilingual, semantic search.** Platform bets once the core advisor is strong.

Rationale: front-load trust (grounding, reviews, eval) and local-data wins; defer anything needing new infra (vectors), heavy privacy surface (orders/memory), or external accuracy (shipping) until the foundation and auth boundary exist.

---

## 9. Open decisions for the owner

- **Add-to-cart UX:** keep the conversational agent round-trip, or a direct action (faster/cheaper, less "assistant did it")?
- **Personalization:** opt-in only? What's the data-retention policy?
- **Upsell stance:** how aggressive, and how is it disclosed?
- **Multilingual priority:** is Urdu/English worth pulling earlier for this store?
- **Wallet bundling:** ship wallet tools inside this plugin, or as a WalletPro add-on that registers via the hook? (Recommend the latter.)
