<?php
/**
 * Fixture: multi-turn, multi-tool conversation — tool-use id uniqueness (issue #66).
 *
 * The user wants to buy the Trail Runner. The model calls search_products in turn 1,
 * then add_to_cart in turn 2, then gives a one-line confirmation with the required
 * cart/checkout links. This is the case that exposes the harness's tool-use id
 * collision: two SEPARATE tool turns each used to be assigned id 0 by the canned
 * builders, so reconstructing the trace (which keys tool results by id) mapped both
 * calls to the LAST result. With per-turn-unique ids each call resolves to its own
 * distinct result.
 *
 * The two tools return unmistakably different shapes (search → `products`,
 * add_to_cart → `cart_url`/`checkout_url`), so test_multi_turn_tool_calls_do_not_
 * collide_ids() can assert the first call resolves to the SEARCH result and the
 * second to the ADD result — a collision would surface the add result on call #0.
 *
 * Distinct from add-to-cart.php (same flow, asserts tool order + the link pattern):
 * this fixture exists specifically to prove the per-call tool RESULT mapping survives
 * a 2+ tool-call multi-turn run, which is the id-collision regression guard.
 */

return [
	'name'     => 'multi-turn-tools',
	'provider' => 'anthropic',
	'messages' => [
		[ 'role' => 'user', 'content' => 'find the Trail Runner and add it to my cart' ],
	],
	'wc'       => [
		'products'      => [
			[ 'id' => 101, 'name' => 'Trail Runner', 'price' => '79.99', 'in_stock' => true ],
		],
		'product_by_id' => [
			101 => [ 'name' => 'Trail Runner', 'price' => '79.99', 'in_stock' => true ],
		],
		'cart'          => [
			'add_returns' => 'cart_key_trailrunner',
			'total'       => '$79.99',
		],
	],
	'script'   => [
		// Turn 1: locate the product (one tool call → id A).
		EvalHarness::anthropic_tool_turn( [
			[ 'name' => 'search_products', 'input' => [ 'query' => 'Trail Runner' ] ],
		] ),
		// Turn 2: add it (one tool call → id B, which MUST differ from id A).
		EvalHarness::anthropic_tool_turn( [
			[ 'name' => 'add_to_cart', 'input' => [ 'product_id' => 101, 'quantity' => 1 ] ],
		] ),
		// Turn 3: one-line confirmation with the mandated cart/checkout links.
		EvalHarness::anthropic_text_turn(
			'Done — the Trail Runner is in your cart. [View Cart](http://example.com/cart) · [Checkout](http://example.com/checkout)'
		),
	],
	'expect'   => [
		'tool_calls'       => [ 'search_products', 'add_to_cart' ], // exact order.
		'answer_not_empty' => true,
		'grounded'         => true,
	],
];
