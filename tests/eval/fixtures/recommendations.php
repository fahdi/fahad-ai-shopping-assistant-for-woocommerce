<?php
/**
 * Fixture: need-based recommendation within a stated budget (issue #16).
 *
 * The user asks for "a gift under 1000". The model calls get_recommendations with
 * the free-text need and a max_price budget; the tool searches the catalog (the
 * harness stubs wc_get_products from the `products` block) and returns only the
 * in-stock items within budget as product cards — so this case exercises BOTH
 * relevance (real products surface as cards) and BUDGET RESPECT (the over-budget
 * item is filtered out).
 *
 * The wc data deliberately includes one over-budget product ("Luxury Watch" at
 * 1500.00). A correct tool excludes it, so it must NOT appear among the surfaced
 * cards; only the two within-budget gifts do. The scripted answer states no price
 * or product name of its own (the cards render those), so the grounding check
 * passes — and crucially the model cannot have surfaced or quoted the filtered
 * over-budget item.
 *
 * get_recommendations comes from the recommendation feature pack
 * (Fahad_AI_Recommendation_Tools), which self-registers via
 * Fahad_AI_Tool_Registry::register_pack() when the test bootstrap glob-loads
 * includes/tools/*.php — exactly as the plugin bootstrap does in production — so
 * the real tool executes in the loop with no per-fixture wiring. (The free-text
 * need path uses wc_get_products only, which the harness provides; it touches no
 * relation lookups, so the case is fully driven by the declarative fixture.)
 */

return [
	'name'     => 'recommendations',
	'provider' => 'anthropic',
	'messages' => [
		[ 'role' => 'user', 'content' => 'can you suggest a gift under 1000?' ],
	],
	'wc'       => [
		// wc_get_products() (the need-based catalog search) returns these. The
		// last one is over budget and MUST be filtered out by the tool.
		'products' => [
			[ 'id' => 301, 'name' => 'Cozy Blanket',  'price' => '450.00',  'in_stock' => true ],
			[ 'id' => 302, 'name' => 'Scented Candle', 'price' => '300.00',  'in_stock' => true ],
			[ 'id' => 303, 'name' => 'Luxury Watch',   'price' => '1500.00', 'in_stock' => true ],
		],
	],
	'script'   => [
		// Turn 1: the model asks for budget-aware suggestions.
		EvalHarness::anthropic_tool_turn( [
			[ 'name' => 'get_recommendations', 'input' => [ 'need' => 'gift', 'max_price' => 1000 ] ],
		] ),
		// Turn 2: short grounded intro; the cards below show prices/names.
		EvalHarness::anthropic_text_turn( 'Here are a couple of great gift ideas within your budget — take a look below!' ),
	],
	'expect'   => [
		'tool_calls'       => [ 'get_recommendations' ],
		'tool_inputs'      => [ 0 => [ 'need' => 'gift', 'max_price' => 1000 ] ],
		'min_cards'        => 2,   // the two within-budget gifts surface as cards…
		'max_cards'        => 2,   // …and the over-budget Luxury Watch is excluded.
		'answer_not_empty' => true,
		'grounded'         => true, // no fabricated product/price in the answer.
	],
];
