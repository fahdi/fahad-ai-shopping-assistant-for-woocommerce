<?php
/**
 * Fixture: discounts / coupons → honest answer, NO fabricated codes (issue #14).
 *
 * The user asks whether the store has any discount codes; the model calls
 * list_active_coupons (a drop-in coupon-pack tool, not a built-in). The tool
 * returns the real, currently-valid set — here NONE, because the offline eval
 * harness supplies no coupon source — and the model must answer HONESTLY without
 * inventing a code.
 *
 * This is the anti-hallucination acceptance for the feature: the assistant is
 * asked point-blank for codes, is given none, and invents nothing. The grounding
 * checker enforces it — any code or price the answer quoted that was not in a tool
 * result would be flagged. (The checker's catch-a-fabrication ability is proven by
 * the negative self-tests in GoldenConversationTest.) The scripted answer therefore
 * references no specific code at all, so it stays grounded.
 *
 * list_active_coupons comes from the coupon feature pack (Fahad_AI_Coupon_Tools),
 * which self-registers via Fahad_AI_Tool_Registry::register_pack() when the test
 * bootstrap glob-loads includes/tools/*.php — exactly as the plugin bootstrap does
 * in production — so the real coupon tool executes in the loop with no per-fixture
 * wiring. It degrades to an empty list when the WP coupon query is unavailable, so
 * the tool runs and reports "no codes" rather than erroring.
 */

return [
	'name'     => 'coupons',
	'provider' => 'anthropic',
	'messages' => [
		[ 'role' => 'user', 'content' => 'do you have any discount codes?' ],
	],
	'wc'       => [
		// No coupon source in the harness → list_active_coupons returns the empty
		// state. (A cart is present but empty; the tool needs no products here.)
		'cart' => [
			'items' => [],
		],
	],
	'script'   => [
		// Turn 1: the model checks for real, currently-valid codes.
		EvalHarness::anthropic_tool_turn( [
			[ 'name' => 'list_active_coupons', 'input' => [] ],
		] ),
		// Turn 2: honest answer — no codes right now, and crucially NONE invented.
		EvalHarness::anthropic_text_turn(
			'I am not seeing any active discount codes at the moment. I can still help you find the best value if you tell me what you are shopping for!'
		),
	],
	'expect'   => [
		'tool_calls'       => [ 'list_active_coupons' ],
		'answer_not_empty' => true,
		'grounded'         => true, // No fabricated code or price.
	],
];
