<?php
/**
 * Fixture: greeting — no tool use.
 *
 * The user says "hi"; the model replies with plain text and calls no tools.
 * Asserts the loop runs zero tools and returns a non-empty reply.
 *
 * Provided twice (anthropic + moonshot) to prove the harness drives both loops.
 * See README.md for the fixture format.
 */

return [
	'name'     => 'greeting',
	'provider' => 'anthropic',
	'messages' => [
		[ 'role' => 'user', 'content' => 'hi' ],
	],
	'wc'       => [], // no products / cart needed.
	'script'   => [
		// Turn 1: final text, no tool_use.
		EvalHarness::anthropic_text_turn( 'Hi there! How can I help you find something today?' ),
	],
	'expect'   => [
		'tool_calls'      => [],      // exact ordered list of expected tool names.
		'answer_not_empty' => true,
		'grounded'        => true,
	],
];
