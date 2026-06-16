<?php
/**
 * Fixture: best sellers → cards (issue #15).
 *
 * The user asks for the store's best sellers; the model calls get_top_products
 * (a filter-registered catalog tool, not a built-in), then replies with a short
 * grounded intro — no inline prices, because the product cards render those.
 *
 * Asserts get_top_products ran, that the loop surfaced product cards (best-sellers
 * reuse the existing product-card rendering, via the convention-based emitter),
 * and that the answer invents no product/price (grounded).
 *
 * get_top_products comes from the catalog feature pack (Fahad_AI_Catalog_Tools),
 * which self-registers via Fahad_AI_Tool_Registry::register_pack() when the test
 * bootstrap glob-loads includes/tools/*.php — exactly as the plugin bootstrap does
 * in production — so the real catalog tool executes in the loop with no per-fixture
 * wiring.
 */

return [
	'name'     => 'best-sellers',
	'provider' => 'anthropic',
	'messages' => [
		[ 'role' => 'user', 'content' => 'what are your best sellers?' ],
	],
	'wc'       => [
		// wc_get_products() (ordered by total_sales) returns these for the tool.
		'products' => [
			[ 'id' => 201, 'name' => 'Flagship Hoodie', 'price' => '89.99', 'in_stock' => true ],
			[ 'id' => 202, 'name' => 'Classic Cap',     'price' => '24.99', 'in_stock' => true ],
			[ 'id' => 203, 'name' => 'Everyday Tote',    'price' => '39.99', 'in_stock' => true ],
		],
	],
	'script'   => [
		// Turn 1: the model calls the catalog best-sellers tool.
		EvalHarness::anthropic_tool_turn( [
			[ 'name' => 'get_top_products', 'input' => [ 'limit' => 3 ] ],
		] ),
		// Turn 2: short grounded intro; cards render the details below it.
		EvalHarness::anthropic_text_turn( 'These are our most popular picks right now — take a look below!' ),
	],
	'expect'   => [
		'tool_calls'       => [ 'get_top_products' ],
		'tool_inputs'      => [ 0 => [ 'limit' => 3 ] ],
		'min_cards'        => 3,   // best-sellers must surface as product cards.
		'answer_not_empty' => true,
		'grounded'         => true,
	],
];
