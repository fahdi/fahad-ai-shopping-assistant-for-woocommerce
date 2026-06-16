<?php
/**
 * Fixture: product comparison → comparison table (issue #13).
 *
 * The user asks for the difference between two specific products; the model calls
 * compare_products (a feature-pack tool, not a built-in) with their ids, then
 * replies with a short grounded recommendation — no inline spec/price dump, because
 * the comparison TABLE renders the aligned attributes.
 *
 * Asserts compare_products ran with the two ids, that the loop surfaced a comparison
 * payload with two product columns (best-sellers/search reuse cards; a comparison is
 * its OWN aligned shape, surfaced via the `comparison` field/SSE event mirroring the
 * cards path), and that the answer invents no product/price (grounded). A comparison
 * deliberately emits NO product cards, so this case asserts on the comparison payload
 * rather than min_cards.
 *
 * compare_products comes from the comparison feature pack (Fahad_AI_Comparison_Tools),
 * which self-registers via Fahad_AI_Tool_Registry::register_pack() when the test
 * bootstrap glob-loads includes/tools/*.php — exactly as the plugin bootstrap does in
 * production — so the real comparison tool executes in the loop with no per-fixture
 * wiring.
 */

return [
	'name'     => 'compare',
	'provider' => 'anthropic',
	'messages' => [
		[ 'role' => 'user', 'content' => "what's the difference between the Trail Runner and the Summit Pro?" ],
	],
	'wc'       => [
		// wc_get_product( id ) returns these for the comparison tool. Each carries
		// its own attributes so the tool can align them into a side-by-side table.
		'product_by_id' => [
			401 => [
				'name'       => 'Trail Runner',
				'price'      => '79.99',
				'in_stock'   => true,
				'attributes' => [ 'pa_weight' => '280g', 'pa_waterproof' => 'No', 'pa_terrain' => 'Trail' ],
			],
			402 => [
				'name'       => 'Summit Pro',
				'price'      => '129.99',
				'in_stock'   => true,
				'attributes' => [ 'pa_weight' => '340g', 'pa_waterproof' => 'Yes', 'pa_terrain' => 'Mountain' ],
			],
		],
	],
	'script'   => [
		// Turn 1: the model calls compare_products with the two ids.
		EvalHarness::anthropic_tool_turn( [
			[ 'name' => 'compare_products', 'input' => [ 'ids' => [ 401, 402 ] ] ],
		] ),
		// Turn 2: short grounded recommendation; the comparison table shows the specs.
		EvalHarness::anthropic_text_turn( 'The Summit Pro is the more rugged, waterproof option, while the Trail Runner is lighter for faster trail outings — see the side-by-side below.' ),
	],
	'expect'   => [
		'tool_calls'             => [ 'compare_products' ],
		'tool_inputs'            => [ 0 => [ 'ids' => [ 401, 402 ] ] ],
		'min_comparison_products' => 2,  // the comparison surfaced two aligned columns.
		'answer_not_empty'       => true,
		'grounded'               => true,
	],
];
