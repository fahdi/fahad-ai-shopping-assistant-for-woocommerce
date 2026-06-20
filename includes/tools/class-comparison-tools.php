<?php
defined( 'ABSPATH' ) || exit;

/**
 * Product comparison tool (issue #13).
 *
 * A drop-in feature pack (same pattern as Fahad_AI_Catalog_Tools /
 * Fahad_AI_Recommendation_Tools): a self-contained class in its own file under
 * includes/tools/ that self-registers a provider at the bottom via
 * Fahad_AI_Tool_Registry::register_pack(). The bootstrap (and the test bootstrap)
 * glob-require everything here, so adding this pack is a SINGLE new file — no edits
 * to the bootstrap, the test bootstrap, or the eval harness.
 *
 * Tool provided:
 *   - compare_products — "what's the difference between A and B?". Given 2–4 product
 *     ids, returns each product's NORMALIZED base summary (reusing the shared
 *     Fahad_AI_Tools::format_product_summary so the fields match every other product
 *     surface) PLUS an ALIGNED attribute table: one row per attribute, each row
 *     carrying every compared product's value for that attribute (blank where a
 *     product lacks it), so the widget can render a side-by-side comparison.
 *
 * Everything comes from real WooCommerce data — names, prices, ratings, stock, and
 * the products' OWN attributes (get_attributes() / get_attribute()) — nothing is
 * invented. Non-visible / not-found ids are skipped; fewer than two comparable
 * products yields a graceful error rather than a degenerate one-column "comparison".
 * The id list is de-duplicated and capped (MAX_PRODUCTS) so the table stays readable
 * and the work is bounded.
 *
 * This is NOT a personal-data tool — it reads the shared catalog only — so it carries
 * no `personal` flag and is not login-gated.
 *
 * The comparison result is a DIFFERENT shape from the product-card path (aligned
 * columns, not a flat card list). It is surfaced to the widget as a separate
 * `comparison` payload by Fahad_AI_API_Handler::tool_result_comparison(), mirroring
 * how product cards are surfaced — the agent loop is untouched.
 */
final class Fahad_AI_Comparison_Tools {

	/**
	 * Maximum number of products compared at once. A side-by-side table with more
	 * columns than this is unreadable (especially on mobile), and the cap bounds the
	 * per-request work. Extra ids beyond the cap are dropped (first N kept).
	 */
	private const MAX_PRODUCTS = 4;

	/**
	 * Append the comparison tool to the registry's tool list.
	 *
	 * Registered as a pack provider (see the register_pack() call at file scope).
	 * Static because the pack holds no per-instance state — its tool calls
	 * WooCommerce directly and reuses the shared formatter singleton.
	 *
	 * @param array $tools Existing tool definitions.
	 * @return array Tools with the comparison tool appended.
	 */
	public static function register( array $tools ): array {
		$tools[] = [
			'name'        => 'compare_products',
			'description' => 'Compare two to four products side by side. Use this when the customer asks for the difference between specific products, or which of a few products to pick. Pass the product ids (find them first with search_products if needed). Returns each product\'s real name, price, rating, stock and image, plus an aligned table of their attributes (each attribute lined up across the products). The storefront renders this as a side-by-side comparison table, so do NOT repeat the full spec list in your text — give a short, grounded recommendation instead. Only ever compare products this store actually has; never invent specs or prices.',
			'parameters'  => [
				'type'       => 'object',
				'properties' => [
					'ids' => [
						'type'        => 'array',
						'items'       => [ 'type' => 'integer' ],
						'description' => 'The product ids to compare (2 to 4).',
					],
				],
				'required'   => [ 'ids' ],
			],
			'callback'    => fn( array $input ) => self::compare_products( $input ),
		];

		return $tools;
	}

	// -------------------------------------------------------------------------
	// Tool implementation
	// -------------------------------------------------------------------------

	/**
	 * Compare 2–4 products: per-product base summary + an aligned attribute table.
	 *
	 * @param array $input { ids: int[] }
	 * @return array {
	 *     On success: { found:int, products: array<int,array>, attributes: array<int,array{name:string, values: array<int,string>}> }
	 *     On bad input: { error: string }
	 * }
	 */
	private static function compare_products( array $input ): array {
		$ids = self::sanitize_ids( $input['ids'] ?? [] );

		if ( empty( $ids ) ) {
			return [
				'error' => __( 'Tell me which products to compare and I will line them up for you.', 'fahad-ai-shopping-assistant-for-woocommerce' ),
			];
		}

		$formatter = Fahad_AI_Tools::instance();
		$products  = [];
		$attr_sets = []; // product_id => [ attribute label => value ]

		foreach ( $ids as $id ) {
			$product = wc_get_product( $id );

			// Skip not-found / non-product / non-visible ids — a shopper cannot act on
			// them, and a missing id must not abort the whole comparison.
			if ( ! $product instanceof WC_Product || ! $product->is_visible() ) {
				continue;
			}

			$summary               = $formatter->format_product_summary( $product );
			$products[]            = $summary;
			$attr_sets[ (int) $id ] = self::product_attributes( $product );
		}

		if ( count( $products ) < 2 ) {
			return [
				'error' => __( 'I need at least two available products to compare. Please pick two or three.', 'fahad-ai-shopping-assistant-for-woocommerce' ),
			];
		}

		return [
			'found'      => count( $products ),
			'products'   => $products,
			'attributes' => self::align_attributes( $attr_sets ),
		];
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	/**
	 * Normalize the incoming ids: cast to positive ints, drop zeros/dupes (first
	 * occurrence wins, preserving order) and cap at MAX_PRODUCTS. A non-array input
	 * yields an empty list (the caller turns that into a friendly error).
	 *
	 * @param mixed $raw The raw `ids` value from the model.
	 * @return int[]
	 */
	private static function sanitize_ids( $raw ): array {
		if ( ! is_array( $raw ) ) {
			return [];
		}

		$ids = [];
		foreach ( $raw as $value ) {
			$id = absint( $value );
			if ( $id > 0 && ! in_array( $id, $ids, true ) ) {
				$ids[] = $id;
			}
			if ( count( $ids ) >= self::MAX_PRODUCTS ) {
				break;
			}
		}

		return $ids;
	}

	/**
	 * One product's attributes as a readable label => display-value map.
	 *
	 * WooCommerce keys get_attributes() by the attribute name/taxonomy; we resolve
	 * each to its human label with wc_attribute_label() and read the product's
	 * display value (already term-name resolved for taxonomy attributes, literal for
	 * custom ones) with get_attribute(). Empty values are dropped so a blank
	 * attribute does not create a noise row.
	 *
	 * @return array<string,string> label => value
	 */
	private static function product_attributes( WC_Product $product ): array {
		$out = [];

		foreach ( array_keys( $product->get_attributes() ) as $name ) {
			$name  = (string) $name;
			$value = trim( (string) $product->get_attribute( $name ) );
			if ( '' === $value ) {
				continue;
			}
			$label = wc_attribute_label( $name );
			// First label wins if two raw attribute names map to the same display
			// label (defensive — keeps the per-product map deterministic).
			if ( ! isset( $out[ $label ] ) ) {
				$out[ $label ] = $value;
			}
		}

		return $out;
	}

	/**
	 * Build the aligned attribute table from the per-product attribute maps.
	 *
	 * The rows are the UNION of every product's attribute labels, in first-seen
	 * order, so the table stays aligned: an attribute only one product has still
	 * appears as a row, blank for the products that lack it. Each row is
	 * { name, values: { product_id => value } } with a value key for EVERY compared
	 * product (blank string when absent) so the widget can render columns uniformly.
	 *
	 * @param array<int,array<string,string>> $attr_sets product_id => (label => value)
	 * @return array<int,array{name:string, values: array<int,string>}>
	 */
	private static function align_attributes( array $attr_sets ): array {
		$product_ids = array_keys( $attr_sets );

		// Union of attribute labels, first-seen order preserved.
		$labels = [];
		foreach ( $attr_sets as $attributes ) {
			foreach ( array_keys( $attributes ) as $label ) {
				if ( ! in_array( $label, $labels, true ) ) {
					$labels[] = $label;
				}
			}
		}

		$rows = [];
		foreach ( $labels as $label ) {
			$values = [];
			foreach ( $product_ids as $pid ) {
				$values[ $pid ] = $attr_sets[ $pid ][ $label ] ?? '';
			}
			$rows[] = [
				'name'   => $label,
				'values' => $values,
			];
		}

		return $rows;
	}
}

// Self-register this feature pack the moment the file is loaded. The bootstrap
// (and the test bootstrap) glob-require includes/tools/*.php, so dropping this
// file in is the ONLY wiring needed — no bootstrap or harness edits.
// @codeCoverageIgnoreStart
// Reason: file-scope self-registration runs once at bootstrap require_once time, before PHPUnit's per-test pcov window opens; it can never be re-executed in-process.
Fahad_AI_Tool_Registry::register_pack( [ 'Fahad_AI_Comparison_Tools', 'register' ] );
// @codeCoverageIgnoreEnd
