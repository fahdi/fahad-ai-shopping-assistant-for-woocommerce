<?php
defined( 'ABSPATH' ) || exit;

/**
 * Catalog discovery tools: best-sellers and category browsing (issue #15).
 *
 * This is the FIRST feature tool pack and the reference pattern every later
 * feature follows: a self-contained class, in its own file under
 * includes/tools/, that registers its tools through the public
 * `fahad_ai_register_tools` filter rather than being baked into
 * Fahad_AI_Tools::builtin_definitions(). The plugin bootstrap require_once's
 * this file and instantiates the class; the constructor hooks register().
 * Dogfooding the extensibility hook this way keeps features modular and lets
 * separate features be built in parallel without touching the core tool list.
 *
 * Tools provided:
 *   - get_top_products  — best sellers, returned in the SAME card-shaped summary
 *                         search_products uses, so they render as product cards.
 *   - list_categories   — product_cat terms (name/slug/count); a category list,
 *                         NOT product cards.
 *
 * "Best seller" is defined as the products with the highest WooCommerce
 * `total_sales` (lifetime units sold), ordered descending. total_sales is a
 * first-class WooCommerce product meta updated on each completed order, which
 * makes it the most defensible, store-agnostic signal — independent of ratings
 * (subjective, sparse) or recency (a new arrival is not a "best seller"). The
 * query therefore sorts by the `total_sales` meta value numerically, DESC.
 */
final class Fahad_AI_Catalog_Tools {

	/**
	 * Hook tool registration onto the extensibility filter.
	 *
	 * The constructor only wires the filter (no work at construction time); the
	 * registry fires `fahad_ai_register_tools` lazily on first use and register()
	 * appends this pack's tools then.
	 */
	public function __construct() {
		add_filter( 'fahad_ai_register_tools', [ $this, 'register' ] );
	}

	/**
	 * Append the catalog tools to the registry's tool list.
	 *
	 * @param array $tools Existing tool definitions.
	 * @return array Tools with the catalog tools appended.
	 */
	public function register( array $tools ): array {
		$tools[] = [
			'name'        => 'get_top_products',
			'description' => 'List the store best-sellers — the products with the most total sales. Use this when the customer asks what is popular, trending, or your best sellers. Returns products that render as visual cards. Optionally narrow to a category.',
			'parameters'  => [
				'type'       => 'object',
				'properties' => [
					'limit'    => [ 'type' => 'integer', 'description' => 'How many best-sellers to return (default 5, max 10).' ],
					'category' => [ 'type' => 'string',  'description' => 'Optional category slug or name to limit best-sellers to.' ],
				],
			],
			'callback'    => fn( array $input ) => $this->get_top_products( $input ),
		];

		$tools[] = [
			'name'        => 'list_categories',
			'description' => 'List the product categories available in the store, each with its name, slug, and product count. Use this when the customer asks what categories or departments exist, or to browse the catalog. To then show the products in a category, call get_top_products or search_products with that category.',
			'parameters'  => [
				'type'       => 'object',
				'properties' => [
					'include_empty' => [ 'type' => 'boolean', 'description' => 'Include categories with no products (default false).' ],
				],
			],
			'callback'    => fn( array $input ) => $this->list_categories( $input ),
		];

		return $tools;
	}

	// -------------------------------------------------------------------------
	// Tool implementations
	// -------------------------------------------------------------------------

	/**
	 * Best-selling products by lifetime total_sales (highest first).
	 *
	 * Returns the same shape as search_products — { found, products[] } where each
	 * product is the canonical card summary — so the convention-based card
	 * emission in the API handler surfaces them as cards automatically.
	 */
	private function get_top_products( array $input ): array {
		$args = [
			'status'   => 'publish',
			'limit'    => min( max( 1, (int) ( $input['limit'] ?? 5 ) ), 10 ),
			// "Best seller" === highest total_sales. total_sales is product meta,
			// so order by its numeric value descending.
			'orderby'  => 'meta_value_num',
			'meta_key' => 'total_sales', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
			'order'    => 'DESC',
		];

		if ( ! empty( $input['category'] ) ) {
			$args['category'] = [ sanitize_text_field( $input['category'] ) ];
		}

		$products = wc_get_products( $args );

		if ( empty( $products ) ) {
			return [
				'found'    => 0,
				'products' => [],
				'message'  => __( 'No best-selling products are available yet.', 'fahad-ai-shopping-assistant-for-woocommerce' ),
			];
		}

		// Reuse the shared formatter so best-seller cards match search cards exactly.
		$formatter = Fahad_AI_Tools::instance();

		return [
			'found'    => count( $products ),
			'products' => array_map( [ $formatter, 'format_product_summary' ], $products ),
		];
	}

	/**
	 * Product categories (product_cat terms) with name, slug, and product count.
	 *
	 * Empty categories are skipped by default (hide_empty); pass include_empty to
	 * list them too. Returns a category list — deliberately NOT a products[] array
	 * — so it does not render as product cards.
	 */
	private function list_categories( array $input ): array {
		$terms = get_terms( [
			'taxonomy'   => 'product_cat',
			'hide_empty' => empty( $input['include_empty'] ),
		] );

		if ( is_wp_error( $terms ) || empty( $terms ) ) {
			return [
				'found'      => 0,
				'categories' => [],
				'message'    => __( 'No product categories were found.', 'fahad-ai-shopping-assistant-for-woocommerce' ),
			];
		}

		$categories = [];
		foreach ( $terms as $term ) {
			$categories[] = [
				'name'  => $term->name,
				'slug'  => $term->slug,
				'count' => (int) $term->count,
			];
		}

		return [
			'found'      => count( $categories ),
			'categories' => $categories,
		];
	}
}
