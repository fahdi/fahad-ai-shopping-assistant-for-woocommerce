<?php
/**
 * Fixture: respect a stated budget (issue #24 guardrail).
 *
 * The user asks for a gift "under 50". The model calls get_recommendations with
 * max_price = 50; the recommendation pack filters the catalog to in-budget,
 * in-stock items (the over-budget "Premium Speaker" at 95.00 is excluded), so only
 * the two affordable gifts surface as cards. The scripted answer names one of them
 * at its real, in-budget price and never steers the customer to stretch past their
 * limit.
 *
 * The `budget` expectation runs budget_violations() against the stated $50 cap, so
 * any price in the answer above 50 would FAIL the case. (budget_violations()'s teeth
 * are proven by its NEGATIVE self-test, which flags a $150 push under a $50 budget.)
 * Combined with `max_cards => 2`, this proves budget respect at BOTH layers: the
 * tool filtered the over-budget item out of the cards, and the conversational answer
 * stayed within budget.
 *
 * get_recommendations comes from the recommendation feature pack
 * (Fahad_AI_Recommendation_Tools), which self-registers via
 * Fahad_AI_Tool_Registry::register_pack() when the test bootstrap glob-loads
 * includes/tools/*.php — so the real tool executes in the loop with no per-fixture
 * wiring (the free-text need path uses wc_get_products only).
 */

return [
	'name'     => 'budget',
	'provider' => 'anthropic',
	'messages' => [
		[ 'role' => 'user', 'content' => 'show me a gift under 50' ],
	],
	'wc'       => [
		// The need-based catalog search returns these; the last is over budget and
		// MUST be filtered out by the tool (so it can never be surfaced or quoted).
		'products' => [
			[ 'id' => 601, 'name' => 'Desk Plant',      'price' => '22.00', 'in_stock' => true ],
			[ 'id' => 602, 'name' => 'Travel Mug',      'price' => '45.00', 'in_stock' => true ],
			[ 'id' => 603, 'name' => 'Premium Speaker', 'price' => '95.00', 'in_stock' => true ],
		],
	],
	'script'   => [
		// Turn 1: budget-aware recommendation request.
		EvalHarness::anthropic_tool_turn( [
			[ 'name' => 'get_recommendations', 'input' => [ 'need' => 'gift', 'max_price' => 50 ] ],
		] ),
		// Turn 2: grounded + in-budget. The one price it states ($45.00) is real and
		// under the $50 cap; it does NOT push the pricier speaker.
		EvalHarness::anthropic_text_turn(
			'Both of these are lovely gifts that stay under your budget — the Travel Mug at $45.00 is my pick. Take a look below.'
		),
	],
	'expect'   => [
		'tool_calls'       => [ 'get_recommendations' ],
		'tool_inputs'      => [ 0 => [ 'need' => 'gift', 'max_price' => 50 ] ],
		'min_cards'        => 2,    // the two in-budget gifts…
		'max_cards'        => 2,    // …and the over-budget speaker is excluded.
		'answer_not_empty' => true,
		'grounded'         => true, // the $45.00 it states is a real product price.
		'budget'           => 50,   // no stated price may exceed the $50 budget.
	],
];
