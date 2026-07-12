<?php
/**
 * Fixture: "how much is shipping to …?" → estimate_delivery → grounded answer (issue #19).
 *
 * The user asks for a shipping cost to a destination; the model calls
 * estimate_delivery (the shipping feature pack tool, not a built-in), which runs
 * for REAL against the WooCommerce shipping stubs (a WC_Shipping_Zones stub that
 * returns one flat_rate method at 5.00, see tests/stubs/wc-stubs.php). The model
 * then states that cost back.
 *
 * Asserts estimate_delivery ran with the destination the user gave, and, the
 * whole point of the feature, that the answer is GROUNDED: the only price it
 * states ($5.00) is the real method cost the tool returned, never an invented
 * number or a made-up delivery date.
 *
 * estimate_delivery comes from the shipping feature pack (Dukandaar_Shipping_Tools),
 * which self-registers via Dukandaar_Tool_Registry::register_pack() when the test
 * bootstrap glob-loads includes/tools/*.php, exactly as the plugin bootstrap does
 * in production, so the real tool executes in the loop with no per-fixture wiring.
 */

return [
	'name'     => 'shipping-estimate',
	'provider' => 'anthropic',
	'messages' => [
		[ 'role' => 'user', 'content' => 'how much is shipping to the US?' ],
	],
	// No 'wc' product/cart data is needed: the shipping tool reads zones/methods
	// from the WC_Shipping_Zones stub, not from wc_get_products / the cart.
	'wc'       => [],
	'script'   => [
		// Turn 1: the model calls the shipping tool with the destination.
		EvalHarness::anthropic_tool_turn( [
			[ 'name' => 'estimate_delivery', 'input' => [ 'country' => 'US' ] ],
		] ),
		// Turn 2: a grounded answer. The cost ($5.00) is exactly what the tool
		// returned; no delivery date is promised (the tool derived none).
		EvalHarness::anthropic_text_turn(
			'Standard shipping to the US is $5.00. I don\'t have a guaranteed delivery date, that depends on the carrier.'
		),
	],
	'expect'   => [
		'tool_calls'      => [ 'estimate_delivery' ],
		'tool_inputs'     => [ 0 => [ 'country' => 'US' ] ],
		'answer_not_empty' => true,
		'grounded'        => true,
	],
];
