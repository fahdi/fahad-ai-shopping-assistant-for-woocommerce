# The AI assistant — providers, loop, tools, prompt, guardrails, eval

> "Our AI assistant": how the assistant thinks and acts — the agentic core, tool system, system prompt, trust guardrails, privacy boundary, cost controls, and the eval harness. For WordPress/WooCommerce plumbing (REST, admin, widget, i18n, distribution) see [plugin.md](plugin.md). For strategy/backlog see [../ROADMAP.md](../ROADMAP.md).

**North star (ROADMAP §1):** *be a trustworthy advisor; conversion is the byproduct.* Understand the need behind the query, ground every fact, close the loop with real actions, and abstain/escalate rather than guess.

## Providers

| | Anthropic (Claude) | Moonshot AI (Kimi K2) |
|---|---|---|
| Endpoint | `api.anthropic.com/v1/messages` | `{base}/v1/chat/completions` (`base` from `moonshot_base_url()`: `api.moonshot.ai` global / `api.moonshot.cn` china, per `fahad_ai_moonshot_region`) |
| Auth | `x-api-key` | `Authorization: Bearer` |
| Tool format | `input_schema` (JSON Schema) | OpenAI-compatible (`parameters`, `type:"function"`) |
| System prompt | top-level `system` field | first `role:system` message |
| Loop signal | `stop_reason === 'tool_use'` → execute → append `tool_result` → repeat; `end_turn` ends | `finish_reason === 'tool_calls'` → execute → append `role:tool` → repeat; `stop` ends |
| Streaming | non-streaming (`/message`) | SSE (`/stream`), `stream:true`, delta chunks, `[DONE]` |
| Models | `claude-haiku-4-5-20251001`, `claude-sonnet-4-6`, `claude-opus-4-6` | `kimi-k2.6` (default) + others; **global and china catalogues differ; a key for one is 401 on the other** |

Neutral tool specs live once and are mapped per provider (`get_anthropic_tools()` / `get_openai_tools()`).

## Agentic loop

Three loops in `Fahad_AI_API_Handler`, all capped at `$max = 8` iterations:
- `run_anthropic_agent()` — non-streaming.
- `run_moonshot_agent()` — non-streaming, OpenAI-shaped.
- `run_stream_agent()` + `stream_one_turn()` — streaming; emits SSE `chunk`/`tool`/`products`/`comparison`/`done`/`error`.

Per tool call the sequence is: **execute → surface cards/comparison from the FULL result → trim the result → feed only the trimmed copy back to the model** (cost control #23; cards/SSE keep full data so grounding is preserved).

**Graceful exhaustion (v2.0.1, finding #28).** If the loop hits `$max` without a final answer, it does NOT surface a raw "Agent exceeded maximum iterations" error. `agent_fallback_message()` returns a friendly message (and keeps any product cards already gathered); the streaming path emits that as a `chunk` + `done`, not an `error`.

**SSE robustness (v2.0.1, finding #29).** `stream_one_turn()`'s cURL write callback uses `split_sse_lines()` to buffer partial frames — cURL delivers arbitrary byte chunks, so a `data:` line (or a multibyte char) split across writes must not be parsed half-formed. A dedicated `curl_init()` handle is required because `wp_remote_post()` buffers the whole body and the `http_api_curl` override corrupted SSE framing on some builds.

## Tools, registry & extensibility

`Fahad_AI_Tool_Registry` (singleton) is the dispatch layer:
- `get_tools()` layers **built-ins → feature packs → `apply_filters('fahad_ai_register_tools', $tools)`**.
- `specs()` returns provider-facing specs with **no `callback`/`personal` leakage** (verified by tests).
- `dispatch($name,$input)`: unknown tool → error; try/catch isolation; **central login gate** — a tool declaring `'personal' => true` is blocked for guests via `Fahad_AI_Auth::guard_logged_in()` before its callback runs.
- `register_pack(callable)` — static provider list that survives per-test singleton resets.

**Drop-in feature packs.** The bootstrap glob-loads `includes/tools/*.php`; each pack self-registers via `register_pack()`. Adding a pack needs **zero** shared-wiring edits — this is what made the v2.0 features conflict-free to build in parallel.

**Adding a tool from another plugin** (the supported extension path):
```php
add_filter( 'fahad_ai_register_tools', function ( array $tools ) {
    $tools[] = [
        'name' => 'my_tool',
        'description' => '…',
        'parameters' => [ 'type' => 'object', 'properties' => [ /* … */ ] ],
        'callback' => fn( array $in ) => [ /* result array */ ],
        // 'personal' => true,  // → login-gated centrally
    ];
    return $tools;
} );
```

**Tool inventory (23 as of v2.0.x):** built-in (5) `search_products`, `get_product_details`, `add_to_cart`, `view_cart`, `remove_from_cart`; packs (18) `get_top_products`, `list_categories`, `compare_products`, `list_active_coupons`, `apply_coupon`, `get_recommendations`, `get_cross_sells`, `get_product_reviews`, `estimate_delivery`, `get_my_orders`*, `get_order_status`*, `get_wallet_balance`*, `top_up`*, `pay_with_credit`*, `set_memory_consent`*, `remember_preference`*, `get_preferences`*, `forget_preferences`* (* = `personal`, login-gated).

**Search relaxation (v2.0.1, finding #27).** `search_products` first runs an exact query; if that returns nothing it relaxes (de-pluralise, drop size/colour/filler stop-words, then an any-term match) — broadening **only** after an exact miss, so real matches are never diluted. Semantic search (#60) is the planned root upgrade.

## System prompt & trust guardrails (#24)

`get_system_prompt()` builds the default prompt and runs `apply_filters('fahad_ai_system_prompt', …)` in **both** the custom and default branches (so the memory pack can append a preferences block, and merchant config can extend it). The policy is consolidated inline and is **absolute**:
- No fake urgency/scarcity; only real, tool-reported stock numbers.
- Respect a stated budget; never push above it.
- Disclose upsells/cross-sells as optional; only real, applicable coupons/wallet bonuses.
- Ground every fact in `search_products`/`get_product_details`/`get_product_reviews`; never invent specs, price, stock, reviews, ratings, order or wallet data.
- Abstain over guessing; route to human support; never block the support path.
- **Currency (#29):** write prices with the plain symbol from tool results; never HTML entities / numeric codes.
- Linking: product links + `[View Cart](cart_url) · [Checkout](checkout_url)` after a successful add (markdown only for those links).

These are enforced by deterministic offline checkers (below) so they cannot silently regress. The anti-features list is ROADMAP §6.

## Privacy & auth boundary (#25)

`Fahad_AI_Auth` (stateless static): `is_logged_in()`, `current_user_id()`, `guard_logged_in()` (returns `true` or a `requires_login` error array), `user_owns($owner_id, ?$user_id)` (a guest, id 0, owns nothing), `mask_email()`. Two layers, by design:
1. **Login gate (central)** — `'personal' => true` tools are gated in `dispatch()` before the callback.
2. **Per-record ownership (in the callback)** — order/wallet/memory tools compute the record owner and call `user_owns()`; a logged-in user cannot read another user's record (returns "not found", not "forbidden" — doesn't leak existence).

Keep PII out of model context and logs (`mask_email`, "not found" phrasing).

## Cost & latency controls (#23)

- `trim_tool_result()` — shrinks ONLY the JSON fed back to the model; cards/SSE keep full data (grounding intact).
- `apply_token_budget()` — bounds outgoing context (no-op at the default budget 0); preserves the system message, latest turn, and in-progress tool loop.
- `resolve_model()` + `apply_filters('fahad_ai_model', …)` — routing seam (e.g. a cheap model for greetings, a capable one for reasoning); default is the configured model unchanged.

## Eval harness (#21)

`tests/eval/` drives the **real** private agent loops (via reflection) against a **scripted transport** (`wp_remote_post` stubbed to a queue of canned provider responses) plus real tool execution against WC stubs. Golden conversations (`tests/eval/fixtures/*.php`) assert which tools were called, the surfaced cards/comparison, and grounding. Deterministic checkers — `grounding_violations`, `scarcity_violations`, `budget_violations`, `escalation_present`, `abstains` — encode the guardrails, with positive + negative self-tests proving each checker works. This is the AI analogue of the unit tests: **no answer-changing feature ships without an eval case.**

## Wallet decoupling (#18)

Wallet tools are **decoupled**: they expose a `fahad_ai_wallet_provider` filter and stay dormant ("Wallet is not available on this store") until a provider adapter (e.g. WalletPro/Account Funds) registers via that filter. Money-safety must mirror the Account Funds invariants (no double-spend, compensating rollback). This keeps "AI + wallet" a clean cross-plugin bundle, not core coupling (ROADMAP §5).
