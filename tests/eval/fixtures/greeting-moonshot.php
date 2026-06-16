<?php
/**
 * Fixture: greeting (Moonshot provider variant).
 *
 * Same intent as greeting.php but exercises the OpenAI-compatible loop
 * (run_moonshot_agent) so both providers are covered by the eval suite.
 */

return [
	'name'     => 'greeting-moonshot',
	'provider' => 'moonshot',
	'messages' => [
		[ 'role' => 'user', 'content' => 'hi' ],
	],
	'wc'       => [],
	'script'   => [
		EvalHarness::moonshot_text_turn( 'Hey! Looking for anything in particular today?' ),
	],
	'expect'   => [
		'tool_calls'       => [],
		'answer_not_empty' => true,
		'grounded'         => true,
	],
];
