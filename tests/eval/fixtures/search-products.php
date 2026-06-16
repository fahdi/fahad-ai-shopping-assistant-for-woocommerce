<?php
/**
 * Fixture: search → cards.
 *
 * The user asks to find running shoes; the model calls search_products, then
 * replies with a short intro (no inline prices — the cards render those).
 * Asserts search_products ran, that the loop surfaced product cards, and that
 * the answer is grounded (it invents no price/product).
 */

return [
	'name'     => 'search-products',
	'provider' => 'anthropic',
	'messages' => [
		[ 'role' => 'user', 'content' => 'find me some running shoes' ],
	],
	'wc'       => [
		'products' => [
			[ 'id' => 101, 'name' => 'Trail Runner', 'price' => '79.99', 'in_stock' => true ],
			[ 'id' => 102, 'name' => 'Road Glider',  'price' => '64.50', 'in_stock' => true ],
		],
	],
	'script'   => [
		// Turn 1: call search_products.
		EvalHarness::anthropic_tool_turn( [
			[ 'name' => 'search_products', 'input' => [ 'query' => 'running shoes', 'limit' => 5 ] ],
		] ),
		// Turn 2: short grounded intro, no prices repeated in text.
		EvalHarness::anthropic_text_turn( 'Here are a couple of great running shoes I found for you — take a look below.' ),
	],
	'expect'   => [
		'tool_calls'       => [ 'search_products' ],
		'min_cards'        => 2,   // product cards the loop must surface.
		'answer_not_empty' => true,
		'grounded'         => true,
	],
];
