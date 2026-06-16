<?php
/**
 * Fixture: add a specific variation to the cart (issue #12).
 *
 * The user asks for a specific variant ("add the Large blue one"). The model
 * first calls get_product_details to see the variable product's options (each
 * variation carries a human-readable label + its own price/stock), then calls
 * add_to_cart with the correct variation_id, and finally replies with the
 * cart/checkout links the system prompt mandates after a successful add.
 *
 * Asserts the two tools ran IN ORDER, that add_to_cart was given the Large/Blue
 * variation's id (201, not the small/red 202), and that the grounded final
 * answer carries the View Cart / Checkout link pattern.
 *
 * The variable product + its variation children are supplied declaratively via
 * the harness `variations` spec (see EvalHarness::mock_product / stub_woocommerce).
 */

return [
	'name'     => 'variation-add',
	'provider' => 'anthropic',
	'messages' => [
		[ 'role' => 'user', 'content' => 'I want the Cotton Tee — add the Large blue one to my cart' ],
	],
	'wc'       => [
		// The variable parent. Its variation children are declared inline and the
		// harness exposes each one as its own wc_get_product( variation_id ) result.
		'product_by_id' => [
			200 => [
				'name'       => 'Cotton Tee',
				'price'      => '20.00',
				'type'       => 'variable',
				'in_stock'   => true,
				'variations' => [
					[
						'variation_id' => 201,
						'attributes'   => [ 'attribute_size' => 'Large', 'attribute_color' => 'Blue' ],
						'price'        => '25.00',
						'in_stock'     => true,
					],
					[
						'variation_id' => 202,
						'attributes'   => [ 'attribute_size' => 'Small', 'attribute_color' => 'Red' ],
						'price'        => '22.00',
						'in_stock'     => true,
					],
				],
			],
		],
		'cart'          => [
			'add_returns' => 'cart_key_tee_large_blue',
			'total'       => '$25.00',
		],
	],
	'script'   => [
		// Turn 1: inspect the product's options. Explicit, unique tool_use ids keep
		// the harness's tool_result trace accurate across turns (the builders default
		// every turn's first call to "toolu_0", which would otherwise collide).
		EvalHarness::anthropic_tool_turn( [
			[ 'id' => 'toolu_details', 'name' => 'get_product_details', 'input' => [ 'product_id' => 200 ] ],
		] ),
		// Turn 2: add the chosen (Large / Blue) variation by its id.
		EvalHarness::anthropic_tool_turn( [
			[ 'id' => 'toolu_add', 'name' => 'add_to_cart', 'input' => [ 'product_id' => 200, 'variation_id' => 201, 'quantity' => 1 ] ],
		] ),
		// Turn 3: final answer with the mandated links (URLs from the tool result).
		// The variation's own price ($25.00) is grounded in the add_to_cart result.
		EvalHarness::anthropic_text_turn(
			'Done — I added the Large / Blue Cotton Tee ($25.00) to your cart. [View Cart](http://example.com/cart) · [Checkout](http://example.com/checkout)'
		),
	],
	'expect'   => [
		'tool_calls'       => [ 'get_product_details', 'add_to_cart' ], // exact order.
		'tool_inputs'      => [ 1 => [ 'variation_id' => 201 ] ],       // the Large/Blue variation, not 202.
		'min_cards'        => 1,                                        // the variable product's card is surfaced.
		'answer_not_empty' => true,
		'answer_matches'   => '/\[View Cart\]\(\S+\)\s*·\s*\[Checkout\]\(\S+\)/u',
		'grounded'         => true,
	],
];
