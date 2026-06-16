# Assistant eval harness

Golden-conversation tests for the AI shopping assistant. This is the AI analogue
of the unit tests in `tests/unit`: instead of testing one method in isolation, it
drives the **real agent loop** (`Fahad_AI_API_Handler::run_anthropic_agent()` /
`run_moonshot_agent()`) end-to-end and asserts on **tool-call quality** and
**answer quality**.

It is fully **deterministic and offline** — it never makes a live LLM API call.
Each conversation's model responses are *scripted*, and the real WooCommerce
tools run against *mocked* WooCommerce data.

## How it works

```
fixture ──► EvalHarness::stub_environment()    (API keys, model, currency, …)
        ──► EvalHarness::stub_woocommerce()    (wc_get_products / wc_get_product / WC()->cart)
        ──► EvalHarness::script_transport()    (stubs wp_remote_post → queue of canned responses)
        ──► EvalHarness::run( provider, msgs ) (invokes the private agent loop via reflection)
                │
                ├─ real Fahad_AI_Tools::execute() runs inside the loop
                └─ returns { result, tool_calls, tool_results, answer, products }
        ──► assertions: tool sequence, cards, answer pattern, grounding
```

- **Scripted transport.** `wp_remote_post` is stubbed to return a queue of canned
  HTTP responses — one per turn of the loop. Turn 1 typically returns a
  `tool_use` / `tool_calls` response; the final turn returns the `end_turn` /
  `stop` text. `wp_remote_retrieve_response_code` / `wp_remote_retrieve_body` are
  stubbed to read from those canned responses.
- **Real tools.** `Fahad_AI_Tools` runs for real inside the loop. Only the
  underlying WooCommerce functions are mocked (same approach as `ToolsTest`), so
  tool execution and product-card emission are exercised genuinely.
- **Reflection.** The agent-loop methods are `private`; the harness invokes them
  with `ReflectionMethod`, exactly like `ApiHandlerTest` already does. **No
  production code in `includes/` is modified.**
- **Tool trace.** After the loop returns, the harness reconstructs the ordered
  `(tool name, input, result)` trace from the loop's own returned `messages`
  transcript — no spy/wrapper needed.

## Running

```bash
# eval suite only
vendor/bin/phpunit --testsuite eval

# unit suite only
vendor/bin/phpunit --testsuite unit

# both
vendor/bin/phpunit

# per-case pass/fail
vendor/bin/phpunit --testsuite eval --testdox
```

Each fixture reports as its own PHPUnit data set, e.g.
`GoldenConversationTest::test_golden_conversation with data set "add-to-cart"`.

## Fixture format

A fixture is a PHP file in `tests/eval/fixtures/` that `return`s an array. The
file name is irrelevant; the `name` key identifies the case in test output.

```php
<?php
return [
    'name'     => 'search-products',          // shows up in PHPUnit output
    'provider' => 'anthropic',                // 'anthropic' | 'moonshot'

    // The user turn(s) sent into the loop (sanitized message array).
    'messages' => [
        [ 'role' => 'user', 'content' => 'find me some running shoes' ],
    ],

    // Declarative WooCommerce data the real tools see.
    'wc' => [
        // wc_get_products() returns these (used by search_products):
        'products' => [
            [ 'id' => 101, 'name' => 'Trail Runner', 'price' => '79.99', 'in_stock' => true ],
        ],
        // wc_get_product( id ) returns these (used by get_product_details / add_to_cart):
        'product_by_id' => [
            101 => [ 'name' => 'Trail Runner', 'price' => '79.99', 'in_stock' => true ],
        ],
        // WC()->cart behaviour (used by add_to_cart / view_cart / remove_from_cart):
        'cart' => [
            'add_returns' => 'cart_key_x',  // value WC_Cart::add_to_cart returns (or false)
            'total'       => '$79.99',
            'items'       => [],            // get_cart() contents for view/remove cases
        ],
    ],

    // The scripted model responses, one per loop turn, IN ORDER.
    // Use the builders so the wire format is provider-accurate:
    'script' => [
        EvalHarness::anthropic_tool_turn( [
            [ 'name' => 'search_products', 'input' => [ 'query' => 'running shoes' ] ],
        ] ),
        EvalHarness::anthropic_text_turn( 'Here are a couple of great options below.' ),
    ],

    // What to assert about the run.
    'expect' => [
        'tool_calls'       => [ 'search_products' ],   // exact ordered tool-name sequence
        'tool_inputs'      => [ 0 => [ 'query' => 'running shoes' ] ], // optional, per-call inputs
        'min_cards'        => 1,    // optional: at least N product cards surfaced
        'max_cards'        => 5,    // optional: at most N
        'answer_not_empty' => true, // optional
        'answer_matches'   => '/.../', // optional: PCRE the answer must match
        'answer_contains'  => 'foo', // optional: substring(s) the answer must contain
        'grounded'         => true, // optional: run the anti-hallucination check
    ],
];
```

### Response builders

| Builder | Provider | Meaning |
| --- | --- | --- |
| `EvalHarness::anthropic_tool_turn([ ['name'=>..,'input'=>..], .. ])` | anthropic | one turn that calls tool(s) (`stop_reason: tool_use`) |
| `EvalHarness::anthropic_text_turn( $text )` | anthropic | final text turn (`stop_reason: end_turn`) |
| `EvalHarness::moonshot_tool_turn([ .. ])` | moonshot | one turn that calls tool(s) (`finish_reason: tool_calls`) |
| `EvalHarness::moonshot_text_turn( $text )` | moonshot | final text turn (`finish_reason: stop`) |
| `EvalHarness::http_error( $code, $body )` | both | wrap a turn as a non-200 HTTP response (error-handling cases) |

The number of scripted turns must match how many times the loop calls the model
(one model call per turn). If the loop asks for more turns than scripted, the
transport returns a `WP_Error` and the fixture fails loudly.

## Grounding checker (anti-hallucination)

`EvalHarness::grounding_violations( $answer, $tool_results )` returns a list of
fabricated-fact violations (empty == grounded). It is a **deterministic
heuristic**, not a semantic judge:

1. **Price tokens** in the answer (e.g. `$129.99`) must appear in some tool
   result. An invented price is the classic hallucination this catches.
2. **Quoted product names** in the answer must match a product name present in
   some tool result (or otherwise appear in the results).

**Known limits (by design, documented in `EvalHarness.php`):**
- String/number containment, not semantics — it can't catch a *plausible but
  swapped* claim (attributing product A's real price to product B).
- Only price tokens and quoted names are checked; a fabricated non-numeric,
  non-name claim ("ships free worldwide") is not caught.
- Use the same price format the tools emit (the harness stubs `wc_price` as
  `$<value>`).

The checker is proven to actually fail by **negative self-tests** in
`GoldenConversationTest` (`test_grounding_self_test_fails_for_fabricated_price`
and `…_fabricated_product_name`) — a checker that always passes would be useless.

## Trust & anti-dark-pattern policy (issue #24)

Honesty is this assistant's core thesis, so the trust policy is encoded in two
places that must stay in sync:

1. **The policy text** — a consolidated "Trust & honesty — these rules are
   absolute" section in `Fahad_AI_API_Handler::get_system_prompt()`
   (`includes/class-api-handler.php`). It is pinned by
   `ApiHandlerTest::test_default_prompt_states_the_trust_guardrail_policy` so a
   prompt edit can't silently drop it. (The `fahad_ai_system_prompt` filter
   pass-through is preserved, so the memory pack can still append preferences.)
2. **Deterministic guardrail checkers** in `EvalHarness.php` — the offline
   analogue of `grounding_violations()`. Like grounding they are containment /
   phrasing heuristics (not semantic judges) and each is proven to have teeth by a
   **positive + negative self-test** in `GoldenConversationTest` (the
   "Guardrail-checker SELF-TESTS" block).

| Policy rule | Checker | `expect` key | Self-tests (positive / negative) | Golden fixture |
| --- | --- | --- | --- | --- |
| No fake scarcity / urgency | `scarcity_violations($answer, $tool_results)` | `no_scarcity` | `test_scarcity_self_test_passes_for_honest_answer` / `…_fails_for_manufactured_urgency`, `…_fails_for_fabricated_stock_count` | `fixtures/fake-scarcity.php` |
| Respect the stated budget | `budget_violations($answer, $budget, $tool_results)` | `budget` (the cap) | `test_budget_self_test_passes_within_budget` / `…_fails_when_over_budget` | `fixtures/budget.php` |
| Abstain over guessing | `abstains($answer)` (pair with `grounded`) | `must_abstain` | `test_abstain_self_test_detects_abstention` / `…_false_for_confident_answer` | `fixtures/abstain-not-found.php` |
| Never block human support | `escalation_present($answer)` | `must_escalate` | `test_escalation_self_test_detects_support_handoff` / `…_false_for_plain_answer` | `fixtures/escalate-refund.php` |
| Disclose upsells / honest extras / ground facts | `grounding_violations()` + the prompt rule | `grounded` | the grounding self-tests | every product fixture with `'grounded' => true` |

**Known limits (by design, documented in `EvalHarness.php`):**
- `scarcity_violations` matches a finite phrase list (extend it when a new dark
  pattern appears) and grounds a quantity by numeric containment — a claimed count
  that coincidentally equals another number in the results would pass.
- `budget_violations` only checks prices the answer states **in text** (the cards
  render the rest); the recommendation tools already filter over-budget items out
  of the cards server-side.
- `escalation_present` / `abstains` are presence heuristics over common phrasings.

These guardrails are intentionally narrow: they catch the high-value dark-pattern
failure modes without policing ordinary, honest selling — the assistant can still
recommend and upsell, just truthfully.

## How to add a new case

> **Every new AI feature MUST add at least one eval case.** A tool the model can
> call but that has no golden conversation is untested behaviour.

1. Create `tests/eval/fixtures/<your-case>.php` returning the fixture array above.
2. Pick the `provider` and script the exact turns the loop will take. Build the
   model responses with the `*_tool_turn` / `*_text_turn` helpers.
3. Provide the `wc` data your tools need (products / product_by_id / cart).
4. List the expected `tool_calls` in order and any answer/card/grounding
   expectations. If the final answer states product facts, set `'grounded' => true`.
   If the feature touches a trust guardrail, add the matching expectation too —
   `no_scarcity`, `budget => <cap>`, `must_abstain`, or `must_escalate` (see the
   "Trust & anti-dark-pattern policy" table above) — so the policy can't regress.
5. Run `vendor/bin/phpunit --testsuite eval --testdox` and confirm your case is
   listed and green.

For an end-to-end pattern that uses two tool calls plus the mandated
cart/checkout link assertion, copy `fixtures/add-to-cart.php`.
