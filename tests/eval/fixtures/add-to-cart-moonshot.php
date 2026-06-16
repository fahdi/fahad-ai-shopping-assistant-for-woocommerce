<?php
/**
 * Fixture: add to cart (Moonshot provider variant).
 *
 * Same flow as add-to-cart.php but on the OpenAI-compatible loop: the model
 * emits tool_calls (search_products then add_to_cart) and a final stop turn with
 * the required cart/checkout links.
 */

return [
	'name'     => 'add-to-cart-moonshot',
	'provider' => 'moonshot',
	'messages' => [
		[ 'role' => 'user', 'content' => 'buy the Road Glider for me' ],
	],
	'wc'       => [
		'products'      => [
			[ 'id' => 102, 'name' => 'Road Glider', 'price' => '64.50', 'in_stock' => true ],
		],
		'product_by_id' => [
			102 => [ 'name' => 'Road Glider', 'price' => '64.50', 'in_stock' => true ],
		],
		'cart'          => [
			'add_returns' => 'cart_key_roadglider',
			'total'       => '$64.50',
		],
	],
	'script'   => [
		EvalHarness::moonshot_tool_turn( [
			[ 'name' => 'search_products', 'input' => [ 'query' => 'Road Glider' ] ],
		] ),
		EvalHarness::moonshot_tool_turn( [
			[ 'name' => 'add_to_cart', 'input' => [ 'product_id' => 102, 'quantity' => 1 ] ],
		] ),
		EvalHarness::moonshot_text_turn(
			'Added the Road Glider to your cart. [View Cart](http://example.com/cart) · [Checkout](http://example.com/checkout)'
		),
	],
	'expect'   => [
		'tool_calls'       => [ 'search_products', 'add_to_cart' ],
		'answer_not_empty' => true,
		'answer_matches'   => '/\[View Cart\]\(\S+\)\s*·\s*\[Checkout\]\(\S+\)/u',
		'grounded'         => true,
	],
];
