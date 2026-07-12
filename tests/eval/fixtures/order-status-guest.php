<?php
/**
 * Fixture: a GUEST asks "where's my order?" → grounded escalate to log in (issue #17).
 *
 * The order tools (get_my_orders, get_order_status) are auth-gated personal-data
 * tools: they self-register via Fahad_AI_Tool_Registry::register_pack() and declare
 * `'personal' => true`, so the registry's central login gate
 * (Fahad_AI_Tool_Registry::dispatch → Fahad_AI_Auth::guard_logged_in) blocks a guest
 * BEFORE the callback runs and returns the standard login-required error. The model,
 * seeing it has no authenticated order data, must escalate the guest to log in and , 
 * the anti-hallucination point of the feature, invent NO order number, status, or
 * date. This fixture asserts exactly that grounded escalation, end-to-end through the
 * real agent loop.
 *
 * WHY THIS FIXTURE DOES NOT DISPATCH THE PERSONAL TOOL ITSELF
 * ----------------------------------------------------------
 * The shared golden-conversation runner (GoldenConversationTest::test_golden_conversation)
 * has no per-fixture hook to set the logged-in state, and the registry's login gate
 * calls is_user_logged_in() for any `personal` tool dispatch. A declarative fixture
 * cannot stub is_user_logged_in() (it is data, not a test method), and that WP
 * function is intentionally NOT defined in tests/stubs/wc-stubs.php because doing so
 * would break Brain\Monkey's Functions\when() override of it in the unit suites
 * (Patchwork "DefinedTooEarly"). This is the same limitation the #25 guest-block eval
 * documents, which is precisely why THAT end-to-end test lives as a dedicated method
 * (GoldenConversationTest::test_personal_tool_blocks_guest_end_to_end) that stubs
 * is_user_logged_in() itself, rather than as a fixture.
 *
 * So the gate-blocks-the-guest-before-the-callback guarantee is proven where it can
 * stub auth:
 *   - tests/unit/OrderToolsTest.php → test_guest_is_blocked_before_a_personal_tool_callback_runs
 *     (drives the REAL registry dispatch as a guest for BOTH order tools; asserts the
 *      login-required error and that the WC accessors are NEVER called).
 *   - tests/eval/GoldenConversationTest.php → test_personal_tool_blocks_guest_end_to_end
 *     (the #25 end-to-end gate proof through the agent loop).
 * This fixture complements those by asserting the *conversational* outcome a guest
 * sees: a grounded "please log in", with no fabricated order facts.
 */

return [
	'name'     => 'order-status-guest',
	'provider' => 'anthropic',
	'messages' => [
		[ 'role' => 'user', 'content' => 'where is my order?' ],
	],
	// No order data is exposed to a guest, so none is provided here.
	'wc'       => [],
	'script'   => [
		// The model has no authenticated order data to act on, so it does not invent
		// an order, it escalates the guest to log in (a grounded "abstain"/escalate).
		EvalHarness::anthropic_text_turn(
			'I can help with that, but first you will need to log in to your account so I can look up your order securely. Once you are signed in, just ask again.'
		),
	],
	'expect'   => [
		// No tool ran: the assistant escalated rather than fabricate order data.
		'tool_calls'       => [],
		'answer_not_empty' => true,
		// Steers the guest to log in.
		'answer_matches'   => '/\blog ?in\b/i',
		// Anti-hallucination: no invented order number / price / status. With no tool
		// results, the grounding checker flags any fabricated price or quoted name.
		'grounded'         => true,
	],
];
