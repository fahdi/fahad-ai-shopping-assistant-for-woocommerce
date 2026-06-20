<?php
defined( 'ABSPATH' ) || exit;

/**
 * Curated bundles — "complete the look" (issue #57).
 *
 * A drop-in feature pack (same pattern as Fahad_AI_Recommendation_Tools /
 * Fahad_AI_Comparison_Tools): a self-contained class in its own file under
 * includes/tools/ that self-registers a provider at the bottom via
 * Fahad_AI_Tool_Registry::register_pack(). The bootstrap (and the test bootstrap)
 * glob-require everything here, so adding this pack is a SINGLE new file — no edits
 * to the bootstrap, the test bootstrap, or the eval harness. Do NOT edit the
 * existing packs.
 *
 * Tool provided:
 *   - get_bundle — given a product, propose a complementary multi-item bundle with
 *     a single, HONEST combined price ("complete the look" / "frequently bought
 *     together as a set"). A clean AOV lever done honestly: it is a DISCLOSED,
 *     OPTIONAL suggestion, never required, never auto-added.
 *
 * EVERYTHING IS GROUNDED IN REAL WOOCOMMERCE DATA — nothing is invented:
 *
 *   • Bundle membership comes from the store's OWN relations:
 *       - If the anchor product is a GROUPED product, its children
 *         ($product->get_children()) ARE the bundle — a grouped product is a
 *         merchant-defined container, so the children are exactly the items the
 *         merchant grouped. The grouped container itself is not a sellable line and
 *         is never listed as an item.
 *       - Otherwise the anchor is the base item ("the thing being completed") and
 *         its cross-sell relations ($product->get_cross_sell_ids(), the merchant's
 *         "goes well with this" picks) are layered in as the complementary items.
 *     The anchor is de-duplicated out of its own cross-sells, and the item count is
 *     capped (MAX_ITEMS) so the offer stays a tidy set rather than a dump.
 *
 *   • PRICING IS GROUNDED, NEVER FABRICATED. The combined price is the EXACT sum of
 *     the in-stock items' real active prices ($product->get_price(), which already
 *     reflects each item's own sale price when it is on sale). A bundle "saving" is
 *     surfaced ONLY when it genuinely exists — i.e. the items' regular-price sum is
 *     higher than the combined active-price sum because items are individually on
 *     sale. There is no synthetic "bundle discount": if nothing is on sale the
 *     combined price equals the regular total and `has_discount` is false with zero
 *     savings. (This deliberately avoids inventing a discount — see the absolute
 *     guardrails in docs/ai-assistant.md §"System prompt & trust guardrails".)
 *
 *   • PER-ITEM STOCK is respected: an out-of-stock (or non-visible) complementary
 *     item is excluded from the bundle AND from the combined price, and reported in
 *     an `unavailable` list so the offer is honest about what is actually in it. If
 *     the anchor itself cannot be bought there is no honest bundle to offer.
 *
 *   • A STATED BUDGET (max_price) is respected: rather than proposing a bundle that
 *     exceeds it, the lowest-priority complementary items are trimmed (dropped from
 *     the end of the priority order) until the combined price fits, and the result
 *     discloses that it trimmed. If even the base item alone exceeds the budget
 *     there is no bundle that fits — the tool refuses to propose one over budget
 *     (`fits_budget => false`) rather than push past the stated limit.
 *
 * Each item is rendered through the shared Fahad_AI_Tools::format_product_summary()
 * so bundle item cards match search/recommendation cards exactly, augmented with a
 * numeric `price_raw` / `regular_price_raw` so the combined total is verifiably the
 * sum of the parts. The items are returned under the canonical `products` key so the
 * convention-based card emission in Fahad_AI_API_Handler::tool_result_cards() renders
 * them as ordinary product cards automatically (no shared-file edits) — a bundle is a
 * priced SET of normal products, not a new card type, and it carries no `attributes`
 * key so it is never mistaken for a comparison table. The bundle-level pricing
 * (combined_price / regular_price / savings) and the `optional => true` disclosure
 * ride alongside as top-level scalars, which the cost-control trim preserves verbatim
 * so the model always sees the grounded combined price and never invents one.
 *
 * This is NOT a personal-data tool — it reads the shared catalog only — so it
 * carries no `personal` flag and is not login-gated.
 */
final class Fahad_AI_Bundle_Tools {

	/**
	 * Maximum number of items in a proposed bundle (anchor + complementary). A
	 * "complete the look" set with more lines than this stops being a tidy
	 * suggestion, and the cap bounds the per-request work. Extra candidates beyond
	 * the cap are dropped (highest-priority first kept).
	 */
	private const MAX_ITEMS = 8;

	/**
	 * Append the bundle tool to the registry's tool list.
	 *
	 * Registered as a pack provider (see the register_pack() call at file scope).
	 * Static because the pack holds no per-instance state — its tool calls
	 * WooCommerce directly and reuses the shared formatter singleton.
	 *
	 * @param array $tools Existing tool definitions.
	 * @return array Tools with the bundle tool appended.
	 */
	public static function register( array $tools ): array {
		$tools[] = [
			'name'        => 'get_bundle',
			'description' => 'Suggest an OPTIONAL "complete the look" / "frequently bought together" bundle for a product: the product plus complementary items, with a single combined price. Pass product_id (find it with search_products first if needed); optionally pass max_price to respect the customer\'s budget. The bundle is built only from the store\'s real grouped-product children and cross-sell relations — never invent items. The combined price is the exact sum of the items\' real prices, and any saving is shown ONLY when the items are genuinely on sale (never fabricate a discount). Out-of-stock items are left out and reported. If a budget is given the bundle is trimmed to fit or declined — never proposed over budget. Always present the bundle as an optional suggestion the customer can take or leave, never as required.',
			'parameters'  => [
				'type'       => 'object',
				'properties' => [
					'product_id' => [ 'type' => 'integer', 'description' => 'The anchor product to build the bundle around.' ],
					'max_price'  => [ 'type' => 'number',  'description' => 'Optional budget cap for the whole bundle. The combined price will not exceed this; the bundle is trimmed to fit or declined.' ],
				],
				'required'   => [ 'product_id' ],
			],
			'callback'    => fn( array $input ) => self::get_bundle( $input ),
		];

		return $tools;
	}

	// -------------------------------------------------------------------------
	// Tool implementation
	// -------------------------------------------------------------------------

	/**
	 * Build a complementary bundle with an honest combined price.
	 *
	 * @param array $input { product_id: int, max_price?: number }
	 * @return array {
	 *     On success: {
	 *         found:int, optional:true, products: array<int,array>,
	 *         combined_price:float, combined_price_display:string,
	 *         regular_price:float, savings:float, has_discount:bool,
	 *         trimmed:bool, fits_budget:bool, unavailable: array<int,array>,
	 *         message?:string
	 *     }
	 *     On no/empty bundle: { found:0, products:[], optional:true, ...flags, message:string }
	 *     On bad input:       { error:string }
	 * }
	 */
	private static function get_bundle( array $input ): array {
		$product_id = absint( $input['product_id'] ?? 0 );
		if ( $product_id <= 0 ) {
			return [
				'error' => __( 'Tell me which product to build a bundle around and I will put a set together.', 'fahad-ai-shopping-assistant-for-woocommerce' ),
			];
		}

		$max_price = self::budget( $input );
		$anchor    = wc_get_product( $product_id );

		if ( ! $anchor instanceof WC_Product || ! $anchor->is_visible() ) {
			return self::empty_state( __( 'That product is not available, so there is no bundle to suggest.', 'fahad-ai-shopping-assistant-for-woocommerce' ), $max_price );
		}

		[ $candidate_ids, $anchor_is_item ] = self::candidate_ids( $anchor );

		// Resolve candidates to buyable items, in priority order. Out-of-stock /
		// non-visible items are excluded from the bundle; out-of-stock ones are
		// reported so the offer is honest about what it contains.
		$formatter   = Fahad_AI_Tools::instance();
		$products    = [];
		$unavailable = [];

		foreach ( $candidate_ids as $id ) {
			if ( count( $products ) >= self::MAX_ITEMS ) {
				break;
			}

			$product = wc_get_product( $id );
			if ( ! $product instanceof WC_Product || ! $product->is_visible() ) {
				continue;
			}

			if ( ! $product->is_in_stock() ) {
				// The anchor of a non-grouped bundle is the base item — if it cannot
				// be bought there is no honest bundle to build at all.
				if ( $anchor_is_item && $id === $product_id ) {
					return self::empty_state( __( 'That product is out of stock, so there is no bundle to suggest right now.', 'fahad-ai-shopping-assistant-for-woocommerce' ), $max_price );
				}
				$unavailable[] = [
					'id'   => (int) $product->get_id(),
					'name' => $product->get_name(),
				];
				continue;
			}

			$summary                      = $formatter->format_product_summary( $product );
			$summary['price_raw']         = self::numeric_price( $product->get_price() );
			$summary['regular_price_raw'] = self::numeric_price( $product->get_regular_price() );
			$products[]                   = $summary;
		}

		// A one-item "bundle" is not a bundle — need at least two real items to make
		// a "complete the look" set.
		if ( count( $products ) < 2 ) {
			return self::empty_state( __( 'There are no complementary items to bundle with this product right now.', 'fahad-ai-shopping-assistant-for-woocommerce' ), $max_price, $unavailable );
		}

		// Respect a stated budget: trim the lowest-priority complementary items
		// (from the end of the priority order) until the combined price fits. Never
		// trim below the base item; if even that exceeds the budget, decline.
		$trimmed = false;
		if ( null !== $max_price ) {
			while ( count( $products ) > 1 && self::sum( $products, 'price_raw' ) > $max_price ) {
				array_pop( $products );
				$trimmed = true;
			}

			// Still over budget with a single item, or trimming left fewer than two
			// items: there is no bundle that fits the budget.
			if ( self::sum( $products, 'price_raw' ) > $max_price || count( $products ) < 2 ) {
				$state                = self::empty_state( __( 'I could not put together a bundle within that budget.', 'fahad-ai-shopping-assistant-for-woocommerce' ), $max_price, $unavailable );
				$state['fits_budget'] = false;
				return $state;
			}
		}

		$combined = self::sum( $products, 'price_raw' );
		$regular  = self::sum( $products, 'regular_price_raw' );

		// A genuine saving exists ONLY when the regular-price total is higher than
		// the combined active-price total (items are individually on sale). Never
		// fabricate a discount: clamp at zero so floating-point noise or a missing
		// regular price can never invent a negative/positive saving.
		$savings      = max( 0.0, round( $regular - $combined, 2 ) );
		$has_discount = $savings > 0;

		$result = [
			'found'                  => count( $products ),
			'optional'               => true, // disclosed upsell — never required.
			// Canonical `products` key so the items render as ordinary product cards
			// via the convention-based emitter (no shared-file edits); no `attributes`
			// key, so this is never mistaken for a comparison table.
			'products'               => $products,
			'combined_price'         => round( $combined, 2 ),
			'combined_price_display' => self::display_price( $combined ),
			'regular_price'          => round( $regular, 2 ),
			'savings'                => $savings,
			'has_discount'           => $has_discount,
			'trimmed'                => $trimmed,
			'fits_budget'            => true,
			'unavailable'            => $unavailable,
		];

		if ( $has_discount ) {
			$result['savings_display'] = self::display_price( $savings );
		}

		if ( $trimmed ) {
			$result['message'] = __( 'I trimmed the bundle to stay within your budget.', 'fahad-ai-shopping-assistant-for-woocommerce' );
		}

		if ( ! empty( $unavailable ) ) {
			$result['message'] = __( 'Some items are out of stock and were left out of the bundle.', 'fahad-ai-shopping-assistant-for-woocommerce' );
		}

		return $result;
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	/**
	 * The candidate item ids for the bundle, in priority order, plus whether the
	 * anchor itself is one of the items.
	 *
	 * A grouped product is a merchant-defined container: its children ARE the
	 * bundle and the container is not a sellable line, so the anchor is NOT an item.
	 * For any other product the anchor is the base item ("the thing being
	 * completed") followed by its cross-sell relations. The list is de-duplicated
	 * (first occurrence wins, preserving priority) and the anchor is never echoed
	 * back as its own cross-sell.
	 *
	 * @param WC_Product $anchor The product to build around.
	 * @return array{0: int[], 1: bool} [ ordered candidate ids, anchor_is_item ]
	 */
	private static function candidate_ids( WC_Product $anchor ): array {
		$anchor_id = (int) $anchor->get_id();

		if ( $anchor->is_type( 'grouped' ) ) {
			return [ self::unique_ids( $anchor->get_children(), $anchor_id ), false ];
		}

		$ids = array_merge( [ $anchor_id ], (array) $anchor->get_cross_sell_ids() );
		return [ self::unique_ids( $ids, 0 ), true ];
	}

	/**
	 * Cast to positive ints, drop zeros, a given excluded id, and duplicates (first
	 * occurrence wins, order preserved).
	 *
	 * @param array $raw     Raw ids.
	 * @param int   $exclude An id to drop entirely (0 to exclude nothing).
	 * @return int[]
	 */
	private static function unique_ids( array $raw, int $exclude ): array {
		$ids = [];
		foreach ( $raw as $value ) {
			$id = absint( $value );
			if ( $id > 0 && $id !== $exclude && ! in_array( $id, $ids, true ) ) {
				$ids[] = $id;
			}
		}
		return $ids;
	}

	/** Sum a numeric key across the item summaries. */
	private static function sum( array $items, string $key ): float {
		$total = 0.0;
		foreach ( $items as $item ) {
			$total += (float) ( $item[ $key ] ?? 0 );
		}
		return $total;
	}

	/**
	 * A raw WooCommerce price as a float. An empty / non-numeric price (e.g. a
	 * grouped container with no price of its own) is treated as 0 so it never
	 * corrupts the combined total.
	 */
	private static function numeric_price( $price ): float {
		return ( '' !== $price && null !== $price && is_numeric( $price ) ) ? (float) $price : 0.0;
	}

	/**
	 * A plain display price string (currency symbol, no HTML) for a numeric amount,
	 * matching how the shared formatter renders per-item prices so the combined
	 * total reads consistently with the item cards.
	 */
	private static function display_price( float $amount ): string {
		return trim( html_entity_decode( wp_strip_all_tags( wc_price( $amount ) ), ENT_QUOTES, 'UTF-8' ) );
	}

	/** Parse the optional max_price budget into a positive float, or null when unset. */
	private static function budget( array $input ): ?float {
		if ( ! isset( $input['max_price'] ) || '' === $input['max_price'] || ! is_numeric( $input['max_price'] ) ) {
			return null;
		}
		$max = (float) $input['max_price'];
		return $max > 0 ? $max : null;
	}

	/**
	 * Standard empty/declined bundle result. Keeps the same flags a successful
	 * result carries (optional, trimmed, fits_budget, unavailable) so the shape is
	 * stable for the model and the widget regardless of outcome.
	 *
	 * @param string                 $message     Friendly explanation.
	 * @param float|null             $max_price   The stated budget, if any.
	 * @param array<int,array>       $unavailable Any items reported out of stock.
	 */
	private static function empty_state( string $message, ?float $max_price, array $unavailable = [] ): array {
		return [
			'found'       => 0,
			'optional'    => true,
			'products'    => [],
			'trimmed'     => false,
			// No budget given → trivially "fits"; a budget given but unmet is
			// overridden to false by the caller.
			'fits_budget' => true,
			'unavailable' => $unavailable,
			'message'     => $message,
		];
	}
}

// Self-register this feature pack the moment the file is loaded. The bootstrap
// (and the test bootstrap) glob-require includes/tools/*.php, so dropping this
// file in is the ONLY wiring needed — no bootstrap or harness edits.
// @codeCoverageIgnoreStart
// Reason: file-scope self-registration runs once at bootstrap require time, before PHPUnit's per-test pcov window opens, so it can never be measured.
Fahad_AI_Tool_Registry::register_pack( [ 'Fahad_AI_Bundle_Tools', 'register' ] );
// @codeCoverageIgnoreEnd
