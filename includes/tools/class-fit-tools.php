<?php
defined( 'ABSPATH' ) || exit;

/**
 * Size / fit advisor tool pack (issue #54).
 *
 * Apparel buyers need fit confidence and the assistant already owns the two data
 * sources that can give it HONESTLY: the product's own size attribute / size-chart
 * meta, and its approved customer reviews. This pack adds one tool, get_fit_advice,
 * that surfaces those facts and — crucially — only ever asserts a "runs small / true
 * to size / runs large" hint when REAL data backs it.
 *
 * GROUNDING is the entire point of this tool (ROADMAP §1: be a trustworthy advisor;
 * abstain over guess). A fit hint is derived ONLY from:
 *   1. an explicit merchant-set "Fit" product attribute (real catalog data), or
 *   2. a corroborated majority of APPROVED reviews that actually mention sizing.
 * If neither exists the tool ABSTAINS: fit_hint is null, fit_available is false, and
 * it returns a message saying fit information is not available. It NEVER fabricates a
 * fit claim, a measurement, or a sizing — there is no path that invents data. There
 * is likewise no body-shaming or medical language anywhere in this tool; it speaks
 * only in terms of the garment ("runs small"), never the body.
 *
 * When the shopper volunteers their usual size, the tool maps a recommended size to a
 * real in-stock variation (stepping one size only when a GROUNDED hint says the
 * garment runs small/large). If that size is sold out or not offered, the tool says
 * so (size_available=false, recommended_variation=null) rather than recommending
 * something the shopper cannot buy.
 *
 * This is a DROP-IN pack following the Fahad_AI_Catalog_Tools / Fahad_AI_Reviews_Tools
 * pattern: a self-contained class in its own file that self-registers a provider at
 * file scope via Fahad_AI_Tool_Registry::register_pack(). The plugin bootstrap (and
 * the test bootstrap) glob-require includes/tools/*.php, so adding this file is the
 * ONLY wiring needed — no edits to the bootstrap, the test bootstrap, or the eval
 * harness. It reads only shared catalog/review data, so it is NOT a personal-data
 * tool and is not login-gated.
 */
final class Fahad_AI_Fit_Tools {

	/**
	 * Product meta key the size chart is read from. Merchants (or a companion
	 * size-chart plugin via this key) store a plain-text/HTML chart here; the tool
	 * surfaces it verbatim and never invents one.
	 */
	private const SIZE_CHART_META = '_fahad_ai_size_chart';

	/**
	 * Minimum number of fit-mentioning APPROVED reviews required before a review-based
	 * hint may be asserted. One lone "runs small" is not a store-wide signal — the
	 * evidence must be corroborated, otherwise the tool abstains.
	 */
	private const MIN_FIT_REVIEWS = 2;

	/** Hard cap on reviews scanned for fit signals (bounds the work per call). */
	private const MAX_REVIEWS_SCANNED = 50;

	/**
	 * Fit-signal phrase lexicon, keyed by the hint each phrase votes for. Matched as
	 * case-insensitive substrings against review text. Deliberately conservative:
	 * only unambiguous sizing language counts, and every phrase describes the GARMENT,
	 * never the wearer's body.
	 *
	 * @var array<string, string[]>
	 */
	private const FIT_PHRASES = [
		'runs_small'   => [ 'runs small', 'run small', 'size up', 'sized up', 'too small', 'too tight', 'tight fit', 'small fit', 'fits tight', 'fit tight' ],
		'runs_large'   => [ 'runs large', 'run large', 'runs big', 'run big', 'size down', 'sized down', 'too big', 'too large', 'roomy', 'large fit', 'baggy', 'oversized' ],
		'true_to_size' => [ 'true to size', 'fits perfectly', 'perfect fit', 'fits as expected', 'fit as expected', 'fits great', 'usual size', 'spot on' ],
	];

	/**
	 * Append the fit tool to the registry's tool list.
	 *
	 * Registered as a pack provider (see the register_pack() call at file scope): the
	 * registry calls this with the running tool list when it lazily builds. Static
	 * because the pack holds no per-instance state.
	 *
	 * @param array $tools Existing tool definitions.
	 * @return array Tools with the fit tool appended.
	 */
	public static function register( array $tools ): array {
		$tools[] = [
			'name'        => 'get_fit_advice',
			'description' => 'Get size and fit guidance for a product. Returns the product\'s real size options, any size chart the store has, and — ONLY when its own customer reviews or an explicit fit attribute support it — a grounded "runs small", "true to size", or "runs large" hint with the evidence behind it. If there is no supporting data, it abstains and says fit information is not available; never claim a fit, measurement, or size that is not in this result. If the shopper tells you their usual size (pass it as usual_size), it recommends a size and maps it to an in-stock variation, or says that size is unavailable. Speak only about how the garment fits, never about the shopper\'s body, and use no medical language.',
			'parameters'  => [
				'type'       => 'object',
				'properties' => [
					'product_id' => [ 'type' => 'integer', 'description' => 'The WooCommerce product ID to advise on.' ],
					'usual_size' => [ 'type' => 'string', 'description' => 'Optional: the size the shopper usually wears (e.g. "M" or "Large"), used to recommend a size and map it to a stocked variation. Only pass what the shopper volunteers.' ],
				],
				'required' => [ 'product_id' ],
			],
			'callback'    => fn( array $input ) => self::get_fit_advice( $input ),
		];

		return $tools;
	}

	// -------------------------------------------------------------------------
	// Tool implementation
	// -------------------------------------------------------------------------

	/**
	 * Grounded size/fit advice for a product.
	 *
	 * Surfaces size options + any size-chart meta, derives a fit hint ONLY from an
	 * explicit fit attribute or corroborated reviews (abstaining otherwise), and — when
	 * a usual size is supplied — maps a recommended size to an in-stock variation.
	 *
	 * @param array $input { product_id:int, usual_size?:string }
	 * @return array
	 */
	private static function get_fit_advice( array $input ): array {
		$product_id = absint( $input['product_id'] ?? 0 );
		$product    = wc_get_product( $product_id );

		if ( ! $product instanceof WC_Product || ! $product->is_visible() ) {
			return [ 'error' => __( 'Product not found.', 'fahad-ai-shopping-assistant-for-woocommerce' ) ];
		}

		$size_options = self::size_options( $product );
		$size_chart   = self::size_chart( $product );

		// Derive the fit hint from grounded sources only. $fit is [ hint, basis ] or
		// [ null, '' ] when there is nothing to stand on.
		[ $hint, $basis ] = self::derive_fit_hint( $product );

		$result = [
			'id'            => $product->get_id(),
			'name'          => $product->get_name(),
			'size_options'  => $size_options,
			'size_chart'    => $size_chart,
			'fit_available' => null !== $hint,
			'fit_hint'      => $hint,
		];

		if ( null !== $hint ) {
			// Only attach a rationale when there is a real one — never a fabricated one.
			$result['fit_basis'] = $basis;
		} else {
			$result['message'] = __( 'Fit information is not available for this product yet, so I can\'t say whether it runs small or large.', 'fahad-ai-shopping-assistant-for-woocommerce' );
		}

		// Recommend + map a size only when the shopper volunteered one.
		$usual_size = isset( $input['usual_size'] ) ? trim( sanitize_text_field( (string) $input['usual_size'] ) ) : '';
		if ( '' !== $usual_size ) {
			$result = array_merge( $result, self::recommend_size( $product, $size_options, $usual_size, $hint ) );
		}

		return $result;
	}

	// -------------------------------------------------------------------------
	// Size attribute + size chart
	// -------------------------------------------------------------------------

	/**
	 * The product's size attribute options as an ordered list of display labels.
	 *
	 * WooCommerce keys get_attributes() by attribute name; the size attribute is the
	 * one whose human label is "Size" (or whose raw name is size/pa_size). Its display
	 * value (get_attribute()) is a comma-separated string of the option labels, which
	 * we split back into a list. Empty when the product has no size attribute (e.g. a
	 * mug) — never invented.
	 *
	 * @return string[]
	 */
	private static function size_options( WC_Product $product ): array {
		$name = self::find_attribute( $product, 'size' );
		if ( '' === $name ) {
			return [];
		}

		$value = trim( (string) $product->get_attribute( $name ) );
		if ( '' === $value ) {
			return [];
		}

		$options = array_map( 'trim', explode( ',', $value ) );

		return array_values( array_filter( $options, static fn( $o ) => '' !== $o ) );
	}

	/**
	 * The product's size chart text, or null when it has none.
	 *
	 * Read verbatim from the SIZE_CHART_META product meta and stripped of tags so it
	 * is safe, plain context for the model. Never synthesised.
	 */
	private static function size_chart( WC_Product $product ): ?string {
		$chart = trim( wp_strip_all_tags( (string) $product->get_meta( self::SIZE_CHART_META ) ) );

		return '' !== $chart ? $chart : null;
	}

	/**
	 * Find the attribute NAME whose human label matches $needle (case-insensitive),
	 * e.g. "size" or "fit". Falls back to matching the raw attribute name so a product
	 * using a bare `size`/`pa_size`/`attribute_size` key is still recognised.
	 *
	 * @return string The matching attribute name, or '' if none.
	 */
	private static function find_attribute( WC_Product $product, string $needle ): string {
		$needle = strtolower( $needle );

		foreach ( array_keys( $product->get_attributes() ) as $name ) {
			$name  = (string) $name;
			$label = strtolower( (string) wc_attribute_label( $name ) );
			$bare  = strtolower( str_replace( [ 'attribute_', 'pa_' ], '', $name ) );

			if ( $label === $needle || $bare === $needle ) {
				return $name;
			}
		}

		return '';
	}

	// -------------------------------------------------------------------------
	// Fit hint derivation (grounded only)
	// -------------------------------------------------------------------------

	/**
	 * Derive [ hint, basis ] from grounded sources, or [ null, '' ] to abstain.
	 *
	 * Order of trust: an explicit merchant-set "Fit" attribute is authoritative real
	 * data and wins outright; otherwise we look for a corroborated review majority.
	 * Nothing else can produce a hint — when both come up empty the tool abstains.
	 *
	 * @return array{0: ?string, 1: string}
	 */
	private static function derive_fit_hint( WC_Product $product ): array {
		$explicit = self::fit_from_attribute( $product );
		if ( null !== $explicit ) {
			return [ $explicit, __( 'stated by the store on the product.', 'fahad-ai-shopping-assistant-for-woocommerce' ) ];
		}

		return self::fit_from_reviews( $product->get_id() );
	}

	/**
	 * Read a hint from an explicit "Fit" product attribute, or null when absent.
	 *
	 * The attribute's display value (e.g. "Runs small") is normalised against the same
	 * phrase lexicon used for reviews, so merchant phrasing maps to a canonical hint.
	 */
	private static function fit_from_attribute( WC_Product $product ): ?string {
		$name = self::find_attribute( $product, 'fit' );
		if ( '' === $name ) {
			return null;
		}

		$value = trim( (string) $product->get_attribute( $name ) );
		if ( '' === $value ) {
			return null;
		}

		return self::classify_text( $value );
	}

	/**
	 * Derive [ hint, basis ] from APPROVED reviews, or [ null, '' ] to abstain.
	 *
	 * Each fit-mentioning review casts ONE vote for its dominant sizing signal (a
	 * review with conflicting signals is skipped as ambiguous). A hint is asserted
	 * only when at least MIN_FIT_REVIEWS reviews mention fit AND one direction is a
	 * strict majority over the runner-up; an even split or thin evidence abstains.
	 *
	 * Only approved review-type comments are read (the same moderation gate the
	 * reviews tool enforces), so pending/spam never influences a fit claim.
	 *
	 * @return array{0: ?string, 1: string}
	 */
	private static function fit_from_reviews( int $product_id ): array {
		$comments = get_comments( [
			'post_id' => $product_id,
			'status'  => 'approve',
			'type'    => 'review',
			'number'  => self::MAX_REVIEWS_SCANNED,
		] );

		if ( empty( $comments ) || ! is_array( $comments ) ) {
			return [ null, '' ];
		}

		$votes = [ 'runs_small' => 0, 'runs_large' => 0, 'true_to_size' => 0 ];

		foreach ( $comments as $comment ) {
			$text = wp_strip_all_tags( (string) ( $comment->comment_content ?? '' ) );
			$vote = self::classify_text( $text );
			if ( null !== $vote ) {
				++$votes[ $vote ];
			}
		}

		$total = array_sum( $votes );
		if ( $total < self::MIN_FIT_REVIEWS ) {
			return [ null, '' ]; // Not enough corroboration — abstain.
		}

		arsort( $votes );
		$hints   = array_keys( $votes );
		$counts  = array_values( $votes );
		$winner  = $hints[0];
		$top     = $counts[0];
		$runner  = $counts[1] ?? 0;

		// Require a strict majority over the runner-up — an even split is not a signal.
		if ( $top <= $runner ) {
			return [ null, '' ];
		}

		$basis = sprintf(
			/* translators: 1: number of reviews supporting the fit hint, 2: total reviews that mentioned fit */
			__( 'based on %1$d of %2$d customer reviews that mention fit.', 'fahad-ai-shopping-assistant-for-woocommerce' ),
			$top,
			$total
		);

		return [ $winner, $basis ];
	}

	/**
	 * Classify a single piece of text to a fit hint, or null when it carries no clear
	 * single signal.
	 *
	 * Counts phrase hits per direction; the direction with the most hits wins. A tie
	 * (including zero hits) returns null so ambiguous or fit-silent text never votes —
	 * this is what keeps a conflicted review from manufacturing a signal.
	 */
	private static function classify_text( string $text ): ?string {
		$text = strtolower( $text );
		if ( '' === trim( $text ) ) {
			return null;
		}

		$scores = [ 'runs_small' => 0, 'runs_large' => 0, 'true_to_size' => 0 ];

		foreach ( self::FIT_PHRASES as $hint => $phrases ) {
			foreach ( $phrases as $phrase ) {
				if ( str_contains( $text, $phrase ) ) {
					++$scores[ $hint ];
				}
			}
		}

		arsort( $scores );
		$hints  = array_keys( $scores );
		$counts = array_values( $scores );

		if ( 0 === $counts[0] || $counts[0] === ( $counts[1] ?? 0 ) ) {
			return null; // No signal, or a tie — not a vote.
		}

		return $hints[0];
	}

	// -------------------------------------------------------------------------
	// Recommended size → in-stock variation mapping
	// -------------------------------------------------------------------------

	/**
	 * Recommend a size for the shopper's usual size and map it to a variation.
	 *
	 * The recommended size starts as the shopper's usual size and steps ONE size in
	 * the size list only when a GROUNDED hint says the garment runs small (step up) or
	 * large (step down) — the step is never taken on a guess. The chosen size is then
	 * matched to a variation: an in-stock match returns the variation; a sold-out or
	 * not-offered size returns size_available=false and recommended_variation=null so
	 * the assistant tells the shopper rather than pointing them at something unbuyable.
	 *
	 * @param string[] $size_options Ordered size labels the product offers.
	 * @param ?string  $hint         Grounded fit hint, or null.
	 * @return array{recommended_size:string, size_available:bool, recommended_variation: ?array}
	 */
	private static function recommend_size( WC_Product $product, array $size_options, string $usual_size, ?string $hint ): array {
		$recommended = self::adjust_size( $size_options, $usual_size, $hint );

		$variation = self::match_variation( $product, $recommended );

		// A variation must exist AND be in stock to be recommendable.
		$available = null !== $variation && ! empty( $variation['in_stock'] );

		return [
			'recommended_size'      => $recommended,
			'size_available'        => $available,
			'recommended_variation' => $available ? $variation : null,
		];
	}

	/**
	 * Apply a grounded fit hint to the usual size, stepping one position in the size
	 * list (up for runs_small, down for runs_large). Returns the usual size unchanged
	 * when there is no hint, no size list, the size is not in the list, or a step would
	 * fall off either end (we never invent a size beyond what the product offers).
	 *
	 * @param string[] $size_options
	 */
	private static function adjust_size( array $size_options, string $usual_size, ?string $hint ): string {
		if ( null === $hint || 'true_to_size' === $hint || empty( $size_options ) ) {
			return $usual_size;
		}

		// Case-insensitive index of the usual size within the offered options.
		$lower = array_map( 'strtolower', $size_options );
		$index = array_search( strtolower( $usual_size ), $lower, true );
		if ( false === $index ) {
			return $usual_size; // Usual size isn't on the scale — don't fabricate a step.
		}

		$target = 'runs_small' === $hint ? $index + 1 : $index - 1;
		if ( $target < 0 || $target >= count( $size_options ) ) {
			return $usual_size; // Can't step past the smallest/largest offered size.
		}

		return $size_options[ $target ];
	}

	/**
	 * Find the variation matching a size label and report its stock.
	 *
	 * Only variable products have variations; a simple product (or one whose size is
	 * not offered) yields null. The match is case-insensitive against each variation's
	 * size attribute value. Returns { variation_id, label, in_stock } for the match, or
	 * null when no variation offers that size.
	 *
	 * @return array{variation_id:int, label:string, in_stock:bool}|null
	 */
	private static function match_variation( WC_Product $product, string $size ): ?array {
		if ( ! $product->is_type( 'variable' ) || '' === $size ) {
			return null;
		}

		$target = strtolower( $size );

		foreach ( $product->get_available_variations() as $var ) {
			$attributes = isset( $var['attributes'] ) && is_array( $var['attributes'] ) ? $var['attributes'] : [];
			$var_size   = self::variation_size( $attributes );

			if ( '' === $var_size || strtolower( $var_size ) !== $target ) {
				continue;
			}

			$variation_id = absint( $var['variation_id'] ?? 0 );
			$child        = $variation_id ? wc_get_product( $variation_id ) : false;
			if ( ! $child instanceof WC_Product ) {
				continue;
			}

			return [
				'variation_id' => $variation_id,
				'label'        => $var_size,
				'in_stock'     => (bool) $child->is_in_stock(),
			];
		}

		return null;
	}

	/**
	 * Pull the size value out of a variation's raw attribute map.
	 *
	 * The map keys are "attribute_" + the attribute name (e.g. attribute_pa_size); we
	 * read the size-named one. Values are term slugs for taxonomy attributes and the
	 * literal value for custom attributes — either way the slug/value is enough to
	 * match against the offered size labels case-insensitively.
	 *
	 * @param array<string,string> $attributes
	 */
	private static function variation_size( array $attributes ): string {
		foreach ( $attributes as $key => $value ) {
			$bare = strtolower( str_replace( [ 'attribute_', 'pa_' ], '', (string) $key ) );
			if ( 'size' === $bare ) {
				return trim( (string) $value );
			}
		}

		return '';
	}
}

// Self-register this feature pack the moment the file is loaded. The bootstrap
// (and the test bootstrap) glob-require includes/tools/*.php, so dropping this
// file in is the ONLY wiring needed — no bootstrap or harness edits.
// @codeCoverageIgnoreStart
// Reason: file-scope self-registration runs once at bootstrap require time, before pcov's per-test window opens; its effect is asserted in FitToolsTest::test_fit_tool_is_registered_via_register_pack.
Fahad_AI_Tool_Registry::register_pack( [ 'Fahad_AI_Fit_Tools', 'register' ] );
// @codeCoverageIgnoreEnd
