<?php
defined( 'ABSPATH' ) || exit;

/**
 * Recommendations & cross-sell tools (issue #16).
 *
 * A drop-in feature pack (same pattern as Fahad_AI_Catalog_Tools / Fahad_AI_Coupon_Tools):
 * a self-contained class in its own file under includes/tools/ that self-registers
 * a provider at the bottom via Fahad_AI_Tool_Registry::register_pack(). The bootstrap
 * (and the test bootstrap) glob-require everything here, so adding this pack is a
 * SINGLE new file — no edits to the bootstrap, the test bootstrap, or the eval harness.
 *
 * Tools provided:
 *   - get_recommendations — need-based "what goes well with this?" suggestions.
 *   - get_cross_sells     — the clearly-OPTIONAL post-add-to-cart cross-sell offer.
 *
 * RELEVANCE IS DERIVED FROM REAL WOOCOMMERCE RELATIONS — nothing is invented:
 *
 *   • get_recommendations, given a product_id, layers the store's OWN relation data,
 *     most-relevant first:
 *       1. Up-sells   ($product->get_upsell_ids()) — merchant-curated "better/premium
 *          alternative" picks, the strongest signal because a human chose them.
 *       2. Related    (wc_get_related_products()) — WooCommerce's own algorithm over
 *          shared category + tag, the standard "you might also like" set.
 *     The two are merged in that order, de-duplicated, and the source product itself
 *     is never echoed back. Each surfaced product carries a short `reason` string the
 *     model can show ("Recommended upgrade", "Frequently viewed together").
 *   • With no product_id but a free-text `need`/`context` (e.g. "something for
 *     hiking", "a gift"), it falls back to a catalog search (wc_get_products with the
 *     need as the search term) so a need-based ask still returns real products.
 *   • BUDGET RESPECT: when the customer states a `max_price`, any candidate whose
 *     price exceeds it is filtered out — the assistant honours a stated budget rather
 *     than upselling past it.
 *
 *   • get_cross_sells returns the cross-sell products WooCommerce associates with the
 *     CURRENT cart (WC()->cart->get_cross_sells(), the IDs aggregated from the cart
 *     items' cross_sell_ids). This is the "offered post-add-to-cart, clearly optional"
 *     flow: the result is flagged `optional => true` and the tool description tells the
 *     model these are OPTIONAL add-ons to offer without pressure — so the upsell stays
 *     transparent (the disclosure/anti-dark-pattern policy itself is issue #24; here we
 *     simply never present cross-sells as required).
 *
 * Out-of-stock and non-visible products are skipped (a shopper cannot act on them),
 * and every product is rendered through the shared Fahad_AI_Tools::format_product_summary()
 * so recommendation/cross-sell cards match search and best-seller cards exactly and the
 * convention-based card emission in the API handler surfaces them automatically.
 *
 * These tools are NOT personal-data tools: they read the shared catalog and the shared
 * session cart, so they carry no `personal` flag and are not login-gated.
 */
final class Fahad_AI_Recommendation_Tools {

	/**
	 * Append the recommendation tools to the registry's tool list.
	 *
	 * Registered as a pack provider (see the register_pack() call at file scope).
	 * Static because the pack holds no per-instance state — its tools call
	 * WooCommerce and the shared session cart directly and reuse the shared
	 * formatter singleton.
	 *
	 * @param array $tools Existing tool definitions.
	 * @return array Tools with the recommendation tools appended.
	 */
	public static function register( array $tools ): array {
		$tools[] = [
			'name'        => 'get_recommendations',
			'description' => 'Suggest products that go well with a given product, or that fit a stated need or budget. Pass product_id for "what goes well with this / what should I get with it" (uses the store\'s real up-sell and related-product relations), and/or a free-text need/context like "something for hiking" or "a birthday gift". Pass max_price to respect a budget — items above it are excluded. Returns real, in-stock products that render as visual cards, each with a short reason. Only ever recommend products this tool returns.',
			'parameters'  => [
				'type'       => 'object',
				'properties' => [
					'product_id' => [ 'type' => 'integer', 'description' => 'A product to base suggestions on (uses its up-sells and related products).' ],
					'need'       => [ 'type' => 'string',  'description' => 'Free-text need or context, e.g. "something for hiking" or "a gift". Used to search the catalog when no product_id is given.' ],
					'max_price'  => [ 'type' => 'number',  'description' => 'Optional budget cap — exclude any suggestion priced above this.' ],
					'limit'      => [ 'type' => 'integer', 'description' => 'How many suggestions to return (default 4, max 10).' ],
				],
			],
			'callback'    => fn( array $input ) => self::get_recommendations( $input ),
		];

		$tools[] = [
			'name'        => 'get_cross_sells',
			'description' => 'List OPTIONAL add-on products that pair with what is already in the customer\'s cart (the store\'s cross-sell relations). Use this AFTER something has been added to the cart, to offer extras the customer may also want. These are entirely optional — present them as suggestions, never as required, and never pressure the customer. Returns real, in-stock products that render as visual cards. Returns an empty list when the cart is empty or has no cross-sells.',
			'parameters'  => [
				'type'       => 'object',
				'properties' => [
					'max_price' => [ 'type' => 'number',  'description' => 'Optional budget cap — exclude any add-on priced above this.' ],
					'limit'     => [ 'type' => 'integer', 'description' => 'How many add-ons to return (default 4, max 10).' ],
				],
			],
			'callback'    => fn( array $input ) => self::get_cross_sells( $input ),
		];

		return $tools;
	}

	// -------------------------------------------------------------------------
	// Tool implementations
	// -------------------------------------------------------------------------

	/**
	 * Need-based / "goes well with" recommendations.
	 *
	 * Relevance is layered from the store's OWN relation data (up-sells first, then
	 * related products), falling back to a catalog search for a free-text need when no
	 * product_id is given. A stated max_price filters out anything above budget. Returns
	 * the canonical { found, products[] } card shape — each product augmented with a
	 * short `reason` — so it renders as product cards.
	 */
	private static function get_recommendations( array $input ): array {
		$limit     = min( max( 1, (int) ( $input['limit'] ?? 4 ) ), 10 );
		$max_price = self::budget( $input );

		$product_id = absint( $input['product_id'] ?? 0 );
		$need       = isset( $input['need'] ) ? sanitize_text_field( (string) $input['need'] ) : '';

		// Map of candidate id => reason, most-relevant first. Using the id as the key
		// de-duplicates a product that is both an up-sell and a related product (the
		// first/strongest reason wins).
		$candidates = [];

		if ( $product_id > 0 ) {
			$source = wc_get_product( $product_id );

			if ( $source instanceof WC_Product ) {
				// 1. Merchant-curated up-sells (strongest signal).
				foreach ( $source->get_upsell_ids() as $id ) {
					$id = (int) $id;
					if ( $id > 0 && $id !== $product_id && ! isset( $candidates[ $id ] ) ) {
						$candidates[ $id ] = __( 'Recommended upgrade', 'fahad-ai-shopping-assistant-for-woocommerce' );
					}
				}

				// 2. WooCommerce's related-products algorithm (shared category + tag).
				$related = wc_get_related_products( $product_id, $limit + count( $candidates ) );
				foreach ( (array) $related as $id ) {
					$id = (int) $id;
					if ( $id > 0 && $id !== $product_id && ! isset( $candidates[ $id ] ) ) {
						$candidates[ $id ] = __( 'Frequently bought together', 'fahad-ai-shopping-assistant-for-woocommerce' );
					}
				}
			}
		}

		// 3. Free-text need with no (or too few) relation-based candidates: search the
		//    catalog so a need-based ask still returns real products.
		if ( '' !== $need && empty( $candidates ) ) {
			return self::from_search( $need, $limit, $max_price );
		}

		$products = self::collect( array_keys( $candidates ), $candidates, $limit, $max_price );

		if ( empty( $products ) ) {
			return self::empty_state();
		}

		return [
			'found'    => count( $products ),
			'products' => $products,
		];
	}

	/**
	 * Cross-sell add-ons for the current cart — the clearly-OPTIONAL post-add offer.
	 *
	 * Reads WC()->cart->get_cross_sells() (the cross-sell IDs WooCommerce aggregates
	 * from the cart items) and returns them as cards, flagged `optional => true` so the
	 * model presents them as suggestions, never as required. Empty when the cart is
	 * empty or no cross-sells are configured.
	 */
	private static function get_cross_sells( array $input ): array {
		$limit     = min( max( 1, (int) ( $input['limit'] ?? 4 ) ), 10 );
		$max_price = self::budget( $input );

		$cart = WC()->cart ?? null;

		if ( ! $cart || $cart->is_empty() ) {
			return [
				'found'    => 0,
				'products' => [],
				'optional' => true,
				'message'  => __( 'There are no items in the cart to suggest add-ons for.', 'fahad-ai-shopping-assistant-for-woocommerce' ),
			];
		}

		$ids      = array_map( 'intval', (array) $cart->get_cross_sells() );
		$products = self::collect( $ids, [], $limit, $max_price );

		if ( empty( $products ) ) {
			return [
				'found'    => 0,
				'products' => [],
				'optional' => true,
				'message'  => __( 'There are no add-on suggestions for the current cart.', 'fahad-ai-shopping-assistant-for-woocommerce' ),
			];
		}

		return [
			'found'    => count( $products ),
			'products' => $products,
			// These are optional extras, never required — see the tool description.
			'optional' => true,
		];
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	/**
	 * Load products by id, keep only buyable ones (visible + in stock), honour the
	 * budget cap, optionally attach a per-id `reason`, and stop at $limit. Returns the
	 * canonical card summaries (reused from Fahad_AI_Tools) so cards stay consistent.
	 *
	 * @param int[]                $ids       Candidate product IDs, in priority order.
	 * @param array<int,string>    $reasons   Optional id => reason map.
	 * @param int                  $limit     Max products to return.
	 * @param float|null           $max_price Budget cap, or null for no cap.
	 * @return array<int,array> Card-shaped product summaries (each may carry `reason`).
	 */
	private static function collect( array $ids, array $reasons, int $limit, ?float $max_price ): array {
		$formatter = Fahad_AI_Tools::instance();
		$products  = [];

		foreach ( $ids as $id ) {
			if ( count( $products ) >= $limit ) {
				break;
			}

			$id      = (int) $id;
			$product = wc_get_product( $id );

			if ( ! $product instanceof WC_Product || ! $product->is_visible() || ! $product->is_in_stock() ) {
				continue;
			}

			if ( ! self::within_budget( $product, $max_price ) ) {
				continue;
			}

			$summary = $formatter->format_product_summary( $product );
			if ( isset( $reasons[ $id ] ) && '' !== $reasons[ $id ] ) {
				$summary['reason'] = $reasons[ $id ];
			}
			$products[] = $summary;
		}

		return $products;
	}

	/**
	 * Free-text need fallback: search the catalog (real products), keep buyable ones
	 * within budget, attach a generic reason. Returns the { found, products[] } card
	 * shape. Mirrors the built-in search so a need-based ask grounds in real data.
	 */
	private static function from_search( string $need, int $limit, ?float $max_price ): array {
		$args = [
			'status'  => 'publish',
			'limit'   => $limit,
			'orderby' => 'relevance',
			's'       => $need,
		];

		$found     = wc_get_products( $args );
		$formatter = Fahad_AI_Tools::instance();
		$products  = [];

		foreach ( (array) $found as $product ) {
			if ( count( $products ) >= $limit ) {
				break;
			}
			if ( ! $product instanceof WC_Product || ! $product->is_visible() || ! $product->is_in_stock() ) {
				continue;
			}
			if ( ! self::within_budget( $product, $max_price ) ) {
				continue;
			}

			$summary           = $formatter->format_product_summary( $product );
			$summary['reason'] = __( 'Matches what you are looking for', 'fahad-ai-shopping-assistant-for-woocommerce' );
			$products[]        = $summary;
		}

		if ( empty( $products ) ) {
			return self::empty_state();
		}

		return [
			'found'    => count( $products ),
			'products' => $products,
		];
	}

	/**
	 * Whether a product is within the stated budget. A null cap (no budget given) is
	 * always within budget. An empty/non-numeric product price is treated as within
	 * budget (the price gate cannot meaningfully exclude it).
	 */
	private static function within_budget( WC_Product $product, ?float $max_price ): bool {
		if ( null === $max_price ) {
			return true;
		}
		$price = $product->get_price();
		if ( '' === $price || null === $price || ! is_numeric( $price ) ) {
			return true;
		}
		return (float) $price <= $max_price;
	}

	/** Parse the optional max_price budget into a positive float, or null when unset. */
	private static function budget( array $input ): ?float {
		if ( ! isset( $input['max_price'] ) || '' === $input['max_price'] || ! is_numeric( $input['max_price'] ) ) {
			return null;
		}
		$max = (float) $input['max_price'];
		return $max > 0 ? $max : null;
	}

	/** Standard empty recommendation result. */
	private static function empty_state(): array {
		return [
			'found'    => 0,
			'products' => [],
			'message'  => __( 'No suitable recommendations were found.', 'fahad-ai-shopping-assistant-for-woocommerce' ),
		];
	}
}

// Self-register this feature pack the moment the file is loaded. The bootstrap
// (and the test bootstrap) glob-require includes/tools/*.php, so dropping this
// file in is the ONLY wiring needed — no bootstrap or harness edits.
// @codeCoverageIgnoreStart
// Reason: file-scope self-registration runs once at bootstrap require time, before pcov's per-test window opens; its effect is asserted in CoverageRecommendationToolsTest via the registry() helper's register_pack() + live dispatch.
Fahad_AI_Tool_Registry::register_pack( [ 'Fahad_AI_Recommendation_Tools', 'register' ] );
// @codeCoverageIgnoreEnd
