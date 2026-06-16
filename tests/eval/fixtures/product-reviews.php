<?php
/**
 * Fixture: product reviews → grounded sentiment summary (issue #11).
 *
 * The user asks whether a product is any good. The model calls get_product_reviews
 * (the reviews feature pack tool, not a built-in), which returns the average
 * rating, review count, and a few recent APPROVED review snippets. The model then
 * replies with a short, grounded one-line sentiment read — summarising the real
 * returned reviews and inventing NOTHING.
 *
 * Asserts get_product_reviews ran with the product_id, and — crucially — that the
 * final answer is grounded: the grounding checker (anti-hallucination) must find
 * no fabricated price token or fabricated quoted review/product reference. The
 * quoted phrase in the scripted answer ("great quality and so comfortable") is a
 * verbatim fragment of a returned review, so it grounds; a made-up quote or a
 * never-returned price would fail the check.
 *
 * get_product_reviews comes from the reviews feature pack (Fahad_AI_Reviews_Tools),
 * which self-registers via Fahad_AI_Tool_Registry::register_pack() when the test
 * bootstrap glob-loads includes/tools/*.php — exactly as the plugin bootstrap does
 * in production — so the real reviews tool executes in the loop with no per-fixture
 * wiring. The declarative `wc.reviews` block drives the approved-review comments
 * the tool reads.
 */

return [
	'name'     => 'product-reviews',
	'provider' => 'anthropic',
	'messages' => [
		[ 'role' => 'user', 'content' => 'Is the Trail Runner any good? What do the reviews say?' ],
	],
	'wc'       => [
		// wc_get_product( id ) → the product the reviews tool validates and reads
		// its aggregate rating/count from.
		'product_by_id' => [
			101 => [ 'name' => 'Trail Runner', 'price' => '79.99', 'in_stock' => true, 'rating' => '4.5', 'review_count' => 24 ],
		],
		// Approved review comments returned by get_comments() (newest first); each
		// per-review rating is read via get_comment_meta.
		'reviews' => [
			[ 'id' => 901, 'author' => 'Dana',  'rating' => 5, 'content' => 'These have great quality and so comfortable on long trail runs.', 'date' => '2026-03-02 10:00:00' ],
			[ 'id' => 902, 'author' => 'Sam',   'rating' => 4, 'content' => 'Solid grip and true to size. Happy with them.',                  'date' => '2026-02-28 14:30:00' ],
			[ 'id' => 903, 'author' => 'Priya', 'rating' => 5, 'content' => 'Best running shoes I have owned. Worth every penny.',             'date' => '2026-02-20 08:15:00' ],
		],
	],
	'script'   => [
		// Turn 1: the model looks up the product's reviews.
		EvalHarness::anthropic_tool_turn( [
			[ 'name' => 'get_product_reviews', 'input' => [ 'product_id' => 101 ] ],
		] ),
		// Turn 2: a short, grounded sentiment summary. The quoted fragment is a
		// verbatim slice of Dana's returned review, so it grounds; no price is
		// stated (the card shows that) and no review content is invented.
		EvalHarness::anthropic_text_turn(
			'Reviewers really like it — it averages 4.5 stars across 24 reviews, with shoppers calling it "great quality and so comfortable" for long runs.'
		),
	],
	'expect'   => [
		'tool_calls'       => [ 'get_product_reviews' ],
		'tool_inputs'      => [ 0 => [ 'product_id' => 101 ] ],
		'answer_not_empty' => true,
		// The reviews tool returns a product-shaped result (id + name), so the
		// convention-based emitter surfaces a single product card too.
		'min_cards'        => 1,
		'max_cards'        => 1,
		// The anti-hallucination check must pass: the summary invents no review
		// text, no fake product, and no ungrounded price.
		'grounded'         => true,
	],
];
