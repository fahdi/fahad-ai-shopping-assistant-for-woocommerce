<?php
/**
 * Fixture: a GUEST asks "what's my wallet balance?" → grounded escalate to log in
 * (issue #18, the wallet differentiator).
 *
 * The wallet tools (get_wallet_balance, top_up, pay_with_credit) are auth-gated
 * personal-data tools: they self-register via Fahad_AI_Tool_Registry::register_pack()
 * and declare `'personal' => true`, so the registry's central login gate
 * (Fahad_AI_Tool_Registry::dispatch → Fahad_AI_Auth::guard_logged_in) blocks a guest
 * BEFORE the callback runs and returns the standard login-required error. The model,
 * seeing it has no authenticated wallet data — and, crucially, no wallet PROVIDER
 * result of any kind — must escalate the guest to log in and invent NO balance,
 * currency, or bonus. Fabricating a balance for a guest is the worst-case failure for
 * a MONEY feature, so this fixture asserts the grounded escalation end-to-end through
 * the real agent loop.
 *
 * WHY THIS FIXTURE DOES NOT DISPATCH THE PERSONAL TOOL ITSELF
 * ----------------------------------------------------------
 * Identical limitation to fixtures/order-status-guest.php: the shared golden-conversation
 * runner has no per-fixture hook to set the logged-in state, and the registry's login
 * gate calls is_user_logged_in() for any `personal` tool dispatch. A declarative fixture
 * cannot stub is_user_logged_in() (it is data, not a test method), and that WP function
 * is intentionally NOT defined in tests/stubs/wc-stubs.php because doing so would break
 * Brain\Monkey's Functions\when() override of it in the unit suites (Patchwork
 * "DefinedTooEarly").
 *
 * So the gate-blocks-the-guest-before-the-callback guarantee — and the no-double-spend /
 * provider-never-touched money-safety guarantees — are proven where auth can be stubbed:
 *   - tests/unit/WalletToolsTest.php → test_guest_is_blocked_before_a_wallet_tool_callback_runs
 *     (drives the REAL registry dispatch as a guest for ALL THREE wallet tools; asserts the
 *      login-required error and that the wallet PROVIDER seam is NEVER reached).
 *   - tests/unit/WalletToolsTest.php → the pay_with_credit insufficient-balance and
 *     amount-validation tests (provider money ops asserted never() called).
 *   - tests/eval/GoldenConversationTest.php → test_personal_tool_blocks_guest_end_to_end
 *     (the #25 end-to-end gate proof through the agent loop, covering every personal tool).
 * This fixture complements those by asserting the *conversational* outcome a guest sees
 * for the wallet feature: a grounded "please log in", with no fabricated wallet facts.
 */

return [
	'name'     => 'wallet-guest',
	'provider' => 'anthropic',
	'messages' => [
		[ 'role' => 'user', 'content' => 'what is my wallet balance?' ],
	],
	// No wallet data is exposed to a guest, so none is provided here.
	'wc'       => [],
	'script'   => [
		// The model has no authenticated wallet data (and no provider result), so it does
		// not invent a balance — it escalates the guest to log in (a grounded escalate).
		EvalHarness::anthropic_text_turn(
			'I can help with your wallet, but first you will need to log in to your account so I can check your balance securely. Once you are signed in, just ask again.'
		),
	],
	'expect'   => [
		// No tool ran: the assistant escalated rather than fabricate a wallet balance.
		'tool_calls'       => [],
		'answer_not_empty' => true,
		// Steers the guest to log in.
		'answer_matches'   => '/\blog ?in\b/i',
		// Anti-hallucination: no invented balance / amount / bonus. With no tool results,
		// the grounding checker flags any fabricated price token or quoted name.
		'grounded'         => true,
	],
];
