<?php
/**
 * Fixture: error / abstain.
 *
 * The user asks for a product the store does not carry. search_products returns
 * "found: 0" (empty). The model must ABSTAIN — acknowledge nothing matched and
 * NOT fabricate a product or price. The grounding check passes precisely because
 * the answer introduces no invented facts.
 *
 * Contrast this with the grounding self-test in GoldenConversationTest, which
 * scripts an answer that DOES fabricate a price and asserts the checker fails it.
 */

return [
	'name'     => 'error-abstain',
	'provider' => 'anthropic',
	'messages' => [
		[ 'role' => 'user', 'content' => 'do you sell a left-handed quantum spanner?' ],
	],
	'wc'       => [
		'products' => [], // wc_get_products returns nothing → tool reports found: 0.
	],
	'script'   => [
		// Turn 1: the model searches.
		EvalHarness::anthropic_tool_turn( [
			[ 'name' => 'search_products', 'input' => [ 'query' => 'left-handed quantum spanner' ] ],
		] ),
		// Turn 2: honest abstention — no invented product, no invented price.
		EvalHarness::anthropic_text_turn(
			"I couldn't find anything matching that in our store right now. Is there something else I can help you look for?"
		),
	],
	'expect'   => [
		'tool_calls'       => [ 'search_products' ],
		'min_cards'        => 0,    // nothing found → no cards.
		'max_cards'        => 0,
		'answer_not_empty' => true,
		'grounded'         => true, // abstaining answer must be grounded.
	],
];
