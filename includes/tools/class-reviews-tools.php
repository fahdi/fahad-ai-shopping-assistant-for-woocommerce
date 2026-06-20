<?php
defined( 'ABSPATH' ) || exit;

/**
 * Reviews & ratings tool pack (issue #11).
 *
 * Ratings and reviews are the strongest trust lever a store already owns, built
 * entirely from LOCAL WooCommerce data — no external service. This pack adds one
 * tool, get_product_reviews, so the assistant can answer "is this any good?" with
 * the store's own average rating, review count, and a few recent APPROVED review
 * snippets. The model is expected to SUMMARISE those returned snippets (a one-line
 * sentiment read) and never to invent review content — the tool returns the real
 * text so any summary is grounded.
 *
 * WooCommerce reviews are WordPress comments: each approved review is a comment of
 * type `review` on the product post, and its star value is stored in the `rating`
 * comment meta. We therefore query approved review comments via get_comments() and
 * read each rating with get_comment_meta(). Only approved/moderated reviews are
 * surfaced (status => approve), so a pending or spam review never reaches a
 * customer through the assistant.
 *
 * This is a DROP-IN pack following the Fahad_AI_Catalog_Tools pattern: a
 * self-contained class in its own file that self-registers a provider at file
 * scope via Fahad_AI_Tool_Registry::register_pack(). The plugin bootstrap (and the
 * test bootstrap) glob-require includes/tools/*.php, so adding this file is the
 * ONLY wiring needed — no edits to the bootstrap, the test bootstrap, or the eval
 * harness.
 */
final class Fahad_AI_Reviews_Tools {

	/** Default number of recent review snippets returned. */
	private const DEFAULT_LIMIT = 3;

	/** Hard cap on returned snippets (keeps the tool payload small and focused). */
	private const MAX_LIMIT = 10;

	/** Words to keep per review excerpt before truncating. */
	private const EXCERPT_WORDS = 40;

	/**
	 * Append the reviews tool to the registry's tool list.
	 *
	 * Registered as a pack provider (see the register_pack() call at file scope):
	 * the registry calls this with the running tool list when it lazily builds.
	 * Static because the pack holds no per-instance state.
	 *
	 * @param array $tools Existing tool definitions.
	 * @return array Tools with the reviews tool appended.
	 */
	public static function register( array $tools ): array {
		$tools[] = [
			'name'        => 'get_product_reviews',
			'description' => 'Get the customer ratings and reviews for a product: the average star rating, the total number of reviews, and a few recent approved review snippets (author, star rating, a short excerpt, and date). Use this when the customer asks whether a product is good, what people think of it, or about its ratings/reviews. You may briefly summarise the overall sentiment from the returned snippets — but only ever reflect what the snippets actually say; never invent reviews, quotes, or ratings.',
			'parameters'  => [
				'type'       => 'object',
				'properties' => [
					'product_id' => [ 'type' => 'integer', 'description' => 'The WooCommerce product ID to fetch reviews for.' ],
					'limit'      => [ 'type' => 'integer', 'description' => 'How many recent review snippets to return (default 3, max 10).' ],
				],
				'required' => [ 'product_id' ],
			],
			'callback'    => fn( array $input ) => self::get_product_reviews( $input ),
		];

		return $tools;
	}

	// -------------------------------------------------------------------------
	// Tool implementation
	// -------------------------------------------------------------------------

	/**
	 * Average rating, review count, and recent approved review snippets.
	 *
	 * The aggregate rating/count come from the product object
	 * (get_average_rating / get_review_count) — WooCommerce's own cached
	 * aggregates over approved reviews — while the snippets are pulled fresh from
	 * the approved review comments so the model has real text to summarise.
	 *
	 * Graceful empty state: a product with no reviews returns review_count 0,
	 * rating 0.0, and an empty reviews array (NOT an error) so the assistant can
	 * say "no reviews yet". An invalid or non-visible product returns an error.
	 */
	private static function get_product_reviews( array $input ): array {
		$product_id = absint( $input['product_id'] ?? 0 );
		$product    = wc_get_product( $product_id );

		if ( ! $product || ! $product->is_visible() ) {
			return [ 'error' => __( 'Product not found.', 'fahad-ai-shopping-assistant-for-woocommerce' ) ];
		}

		$limit = min( max( 1, (int) ( $input['limit'] ?? self::DEFAULT_LIMIT ) ), self::MAX_LIMIT );

		$review_count = (int) $product->get_review_count();
		$rating       = round( (float) $product->get_average_rating(), 2 );

		return [
			'id'           => $product->get_id(),
			'name'         => $product->get_name(),
			'rating'       => $rating,
			'review_count' => $review_count,
			'reviews'      => self::recent_review_snippets( $product->get_id(), $limit ),
		];
	}

	/**
	 * Up to $limit recent APPROVED review snippets for a product.
	 *
	 * Reviews are comments: query the product's review-type comments with
	 * status `approve` only (moderation gate — pending/spam never surface),
	 * newest first. Each snippet is { author, rating, excerpt, date }; the rating
	 * is the per-review `rating` comment meta (0 when a review left no stars).
	 *
	 * @return array<int, array{author: string, rating: int, excerpt: string, date: string}>
	 */
	private static function recent_review_snippets( int $product_id, int $limit ): array {
		$comments = get_comments( [
			'post_id' => $product_id,
			'status'  => 'approve',
			'type'    => 'review',
			'number'  => $limit,
			'orderby' => 'comment_date_gmt',
			'order'   => 'DESC',
		] );

		if ( empty( $comments ) || ! is_array( $comments ) ) {
			return [];
		}

		$snippets = [];
		foreach ( $comments as $comment ) {
			$content = wp_strip_all_tags( (string) ( $comment->comment_content ?? '' ) );

			$snippets[] = [
				'author'  => (string) ( $comment->comment_author ?? '' ),
				'rating'  => (int) get_comment_meta( (int) ( $comment->comment_ID ?? 0 ), 'rating', true ),
				'excerpt' => wp_trim_words( $content, self::EXCERPT_WORDS, '…' ),
				'date'    => mysql2date( get_option( 'date_format', 'Y-m-d' ), (string) ( $comment->comment_date ?? '' ) ),
			];
		}

		return $snippets;
	}
}

// Self-register this feature pack the moment the file is loaded. The bootstrap
// (and the test bootstrap) glob-require includes/tools/*.php, so dropping this
// file in is the ONLY wiring needed — no bootstrap or harness edits.
// @codeCoverageIgnoreStart
// Reason: file-scope self-registration runs once at bootstrap require time, before PHPUnit's per-test pcov window opens, so it is unmeasurable; its target (the callable register provider) is asserted by CoverageReviewsToolsTest.
Fahad_AI_Tool_Registry::register_pack( [ 'Fahad_AI_Reviews_Tools', 'register' ] );
// @codeCoverageIgnoreEnd
