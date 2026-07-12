<?php
/**
 * Fixture: add to cart (two tool calls, ordered).
 *
 * The user wants to buy the Trail Runner. The model first calls search_products
 * to locate it, then add_to_cart, then replies with the cart/checkout links the
 * system prompt mandates after a successful add.
 *
 * Asserts both tools ran IN ORDER and the final answer contains the
 * View Cart / Checkout link pattern built from the (real) tool result URLs.
 */

return [
	'name'     => 'add-to-cart',
	'provider' => 'anthropic',
	'messages' => [
		[ 'role' => 'user', 'content' => 'add the Trail Runner to my cart' ],
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
		// Turn 1: find the product.
		EvalHarness::anthropic_tool_turn( [
			[ 'name' => 'search_products', 'input' => [ 'query' => 'Trail Runner' ] ],
		] ),
		// Turn 2: add it to the cart.
		EvalHarness::anthropic_tool_turn( [
			[ 'name' => 'add_to_cart', 'input' => [ 'product_id' => 101, 'quantity' => 1 ] ],
		] ),
		// Turn 3: final answer with the required links (URLs come from the tool result).
		EvalHarness::anthropic_text_turn(
			'Done, I added the Trail Runner to your cart. [View Cart](http://example.com/cart) · [Checkout](http://example.com/checkout)'
		),
	],
	'expect'   => [
		'tool_calls'       => [ 'search_products', 'add_to_cart' ], // exact order.
		'answer_not_empty' => true,
		'answer_matches'   => '/\[View Cart\]\(\S+\)\s*·\s*\[Checkout\]\(\S+\)/u', // cart/checkout link pattern.
		'grounded'         => true,
	],
];
