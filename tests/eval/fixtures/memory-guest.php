<?php
/**
 * Fixture: a GUEST asks to be remembered → grounded escalate to log in (issue #20).
 *
 * The memory tools (set_memory_consent, remember_preference, get_preferences,
 * forget_preferences) are auth-gated personal-data tools: they self-register via
 * Fahad_AI_Tool_Registry::register_pack() and declare `'personal' => true`, so the
 * registry's central login gate (Fahad_AI_Tool_Registry::dispatch →
 * Fahad_AI_Auth::guard_logged_in) blocks a guest BEFORE the callback runs and returns
 * the standard login-required error. Personalization is strictly OPT-IN and per-user,
 * so there is nothing a guest (id 0) can consent to or have remembered. The model,
 * seeing it cannot store anything for an unauthenticated visitor, must escalate the
 * guest to log in and — the privacy point of the feature — persist NOTHING and invent
 * no "saved" confirmation. This fixture asserts exactly that grounded escalation,
 * end-to-end through the real agent loop.
 *
 * WHY THIS FIXTURE DOES NOT DISPATCH THE PERSONAL TOOL ITSELF
 * ----------------------------------------------------------
 * Identical limitation to fixtures/order-status-guest.php and fixtures/wallet-guest.php:
 * the shared golden-conversation runner has no per-fixture hook to set the logged-in
 * state, and the registry's login gate calls is_user_logged_in() for any `personal`
 * tool dispatch. A declarative fixture cannot stub is_user_logged_in() (it is data, not
 * a test method), and that WP function is intentionally NOT defined in
 * tests/stubs/wc-stubs.php because doing so would break Brain\Monkey's Functions\when()
 * override of it in the unit suites (Patchwork "DefinedTooEarly").
 *
 * So the gate-blocks-the-guest-before-the-callback guarantee — and the no-consent →
 * no-storage privacy guarantee — are proven where auth can be stubbed:
 *   - tests/unit/MemoryToolsTest.php → test_guest_is_blocked_before_a_memory_tool_callback_runs
 *     (drives the REAL registry dispatch as a guest for ALL FOUR memory tools; asserts the
 *      login-required error and that user meta is NEVER read or written).
 *   - tests/unit/MemoryToolsTest.php → test_remember_preference_without_consent_stores_nothing
 *     (the no-consent → no-storage privacy gate).
 *   - tests/eval/GoldenConversationTest.php → test_personal_tool_blocks_guest_end_to_end
 *     (the #25 end-to-end gate proof through the agent loop, covering every personal tool).
 * This fixture complements those by asserting the *conversational* outcome a guest sees
 * for the memory feature: a grounded "please log in", with nothing fabricated as saved.
 */

return [
	'name'     => 'memory-guest',
	'provider' => 'anthropic',
	'messages' => [
		[ 'role' => 'user', 'content' => 'remember that my favorite color is blue' ],
	],
	// No personal data can be stored for a guest, so none is provided here.
	'wc'       => [],
	'script'   => [
		// The model cannot store anything for an unauthenticated visitor, so it does not
		// claim to remember the preference — it escalates the guest to log in (a grounded
		// escalate) and offers to save it once they are signed in.
		EvalHarness::anthropic_text_turn(
			'I would be happy to remember that for you, but first you will need to log in to your account so your preferences can be saved to it securely. Once you are signed in, just let me know and I can remember it (you can clear it anytime).'
		),
	],
	'expect'   => [
		// No tool ran: the assistant escalated rather than fabricate a saved preference.
		'tool_calls'       => [],
		'answer_not_empty' => true,
		// Steers the guest to log in.
		'answer_matches'   => '/\blog ?in\b/i',
		// Anti-hallucination: no invented confirmation/price/quoted product. With no tool
		// results, the grounding checker flags any fabricated price token or quoted name.
		'grounded'         => true,
	],
];
