<?php
defined( 'ABSPATH' ) || exit;

/**
 * Executes WooCommerce tool calls on behalf of the AI agent.
 */
final class Fahad_AI_Tools {

	private static $instance = null;

	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {}

	/**
	 * Built-in tool definitions for the registry.
	 *
	 * Each entry colocates the spec (name/description/parameters fed to the LLM)
	 * with its callback. The callbacks are closures bound to this instance so the
	 * implementation methods can stay private. The registry merges these with any
	 * tools added via the `fahad_ai_register_tools` filter.
	 *
	 * @return array<int, array{name: string, description: string, parameters: array, callback: callable}>
	 */
	public function builtin_definitions(): array {
		return [
			[
				'name'        => 'search_products',
				'description' => 'Search for products by name, category, or price range. Use this before recommending products.',
				'parameters'  => [
					'type'       => 'object',
					'properties' => [
						'query'     => [ 'type' => 'string',  'description' => 'Search term' ],
						'category'  => [ 'type' => 'string',  'description' => 'Category slug or name' ],
						'min_price' => [ 'type' => 'number',  'description' => 'Minimum price' ],
						'max_price' => [ 'type' => 'number',  'description' => 'Maximum price' ],
						'on_sale'   => [ 'type' => 'boolean', 'description' => 'When true, return ONLY products that are currently on sale (a reduced price). Use this whenever the customer asks what is on sale, about deals, discounts, or clearance, it composes with category and price.' ],
						'min_rating' => [ 'type' => 'number', 'description' => 'When set (e.g. 4), return ONLY products whose average customer rating is at least this many stars. Use whenever the customer asks for well-rated, top-rated, best-reviewed, or highly rated products; it composes with query, category, price, and on_sale.' ],
						'sort'      => [ 'type' => 'string', 'enum' => [ 'price_low', 'price_high', 'rating', 'popularity' ], 'description' => 'Order the results. Use price_low for cheapest first, price_high for most expensive first, rating for best-rated first, popularity for best-sellers first. Omit to keep the default best-match order.' ],
						'limit'     => [ 'type' => 'integer', 'description' => 'Max results (default 5, max 10)' ],
					],
				],
				'callback'    => fn( array $input ) => $this->search_products( $input ),
			],
			[
				'name'        => 'get_product_details',
				'description' => 'Get full details for a product, description, price, stock, variations.',
				'parameters'  => [
					'type'       => 'object',
					'properties' => [
						'product_id' => [ 'type' => 'integer', 'description' => 'The WooCommerce product ID' ],
					],
					'required' => [ 'product_id' ],
				],
				'callback'    => fn( array $input ) => $this->get_product_details( $input ),
			],
			[
				'name'        => 'add_to_cart',
				'description' => "Add a product to the customer's shopping cart.",
				'parameters'  => [
					'type'       => 'object',
					'properties' => [
						'product_id'   => [ 'type' => 'integer', 'description' => 'Product ID to add' ],
						'quantity'     => [ 'type' => 'integer', 'description' => 'Quantity (default 1)' ],
						'variation_id' => [ 'type' => 'integer', 'description' => 'Variation ID for variable products' ],
					],
					'required' => [ 'product_id' ],
				],
				'callback'    => fn( array $input ) => $this->add_to_cart( $input ),
			],
			[
				'name'        => 'update_cart_quantity',
				'description' => "Change the quantity of an item already in the customer's cart. Pass the cart_item_key (from view_cart) and the new quantity. Use when the customer wants more or fewer of something already in their cart, for example \"make it 3\" or \"just one of those\".",
				'parameters'  => [
					'type'       => 'object',
					'properties' => [
						'cart_item_key' => [ 'type' => 'string',  'description' => 'The cart item key from view_cart identifying the line to change.' ],
						'quantity'      => [ 'type' => 'integer', 'description' => 'The new quantity (at least 1).' ],
					],
					'required' => [ 'cart_item_key', 'quantity' ],
				],
				'callback'    => fn( array $input ) => $this->update_cart_quantity( $input ),
			],
			[
				'name'        => 'view_cart',
				'description' => "View the current contents of the customer's cart, totals, and checkout URL.",
				'parameters'  => [
					'type'       => 'object',
					'properties' => new stdClass(),
				],
				'callback'    => fn( array $input ) => $this->view_cart(),
			],
			[
				'name'        => 'remove_from_cart',
				'description' => "Remove an item from the cart using its cart_item_key (from view_cart).",
				'parameters'  => [
					'type'       => 'object',
					'properties' => [
						'cart_item_key' => [ 'type' => 'string', 'description' => 'Cart item key from view_cart or add_to_cart' ],
					],
					'required' => [ 'cart_item_key' ],
				],
				'callback'    => fn( array $input ) => $this->remove_from_cart( $input ),
			],
		];
	}

	/**
	 * Route a tool call by name to the appropriate handler.
	 *
	 * Public entry point used by the agent loop. Delegates to the tool registry
	 * so built-in and third-party (filter-registered) tools dispatch uniformly.
	 *
	 * @param string $name  Tool name.
	 * @param array  $input Tool input from the model.
	 * @return array Result to send back as tool_result content.
	 */
	public function execute( string $name, array $input ): array {
		return Fahad_AI_Tool_Registry::instance()->dispatch( $name, $input );
	}

	// -------------------------------------------------------------------------
	// Tool implementations
	// -------------------------------------------------------------------------

	private function search_products( array $input ): array {
		// On-sale browse (issue #137): a grounded filter so the assistant can answer
		// "what is on sale" from real data instead of guessing. Fetch wider when filtering
		// by sale so the post-filter has enough candidates (sale items may sit beyond the
		// first few by relevance), then cap to the display limit below.
		$only_sale  = ! empty( $input['on_sale'] );
		$min_rating = isset( $input['min_rating'] ) ? max( 0.0, (float) $input['min_rating'] ) : 0.0;
		$limit      = self::clamp_search_limit( (int) ( $input['limit'] ?? 5 ) );

		// Widen the fetch when a post-query filter (on-sale or min-rating) will thin the results,
		// so enough candidates survive to fill the display limit; capped back down after filtering.
		$widen = $only_sale || $min_rating > 0.0;

		$base = [
			'status'  => 'publish',
			'limit'   => $widen ? 50 : $limit,
			'orderby' => 'relevance',
		];

		// Optional result sorting (issue #279): when the shopper asks for a specific order
		// (cheapest first, best-rated, etc.), override relevance with WooCommerce's own ordering.
		$sort_args = self::sort_args( isset( $input['sort'] ) ? sanitize_text_field( $input['sort'] ) : '' );
		if ( ! empty( $sort_args ) ) {
			$base = array_merge( $base, $sort_args );
		}

		if ( ! empty( $input['category'] ) ) {
			$base['category'] = [ sanitize_text_field( $input['category'] ) ];
		}

		// Normalize a model-supplied price range (issue #287): floor negatives and swap an
		// inverted min/max so an impossible range never silently returns zero results.
		list( $min_price, $max_price ) = self::normalize_price_range(
			isset( $input['min_price'] ) ? (float) $input['min_price'] : null,
			isset( $input['max_price'] ) ? (float) $input['max_price'] : null
		);

		if ( null !== $min_price ) {
			$base['min_price'] = $min_price;
		}

		if ( null !== $max_price ) {
			$base['max_price'] = $max_price;
		}

		$query = ! empty( $input['query'] ) ? sanitize_text_field( $input['query'] ) : '';

		// Semantic retrieval seam (issue #60). When the shopper supplied free text,
		// consult any registered semantic (embeddings/vector) retriever FIRST, it
		// understands intent/synonyms ("shoes for flat feet") that the literal
		// keyword search below misses. The retriever returns ranked product IDs;
		// Fahad_AI_Semantic_Search resolves them LIVE (so price/stock are never
		// cached) into the same card-shaped summaries. With no provider registered
		// it returns [] and we fall straight through to keyword search unchanged , 
		// the keyword leg is always the safety net (graceful degradation). A pure
		// category/price browse (empty query) has no intent to embed, so it skips
		// the seam entirely. See docs/RAG-DESIGN.md §4.3 / §5.4.
		// Skip the semantic seam for an on-sale browse: "on sale" is a structured catalog
		// filter, not an intent to embed, and the semantic summaries are ranked by meaning,
		// not sale status, so we always resolve sale queries through the deterministic
		// keyword/catalog path below and filter on the live WC_Product::is_on_sale().
		if ( '' !== $query && ! $only_sale ) {
			$semantic = Fahad_AI_Semantic_Search::retrieve( $query, $this->semantic_filters( $base ) );
			if ( ! empty( $semantic ) ) {
				return [
					'found'    => count( $semantic ),
					'products' => $semantic,
				];
			}
		}

		$products = $this->query_products( $base, $query );

		// Shoppers type plurals ("hoodies") and adjective-laden phrases ("medium black
		// hoodie") that WooCommerce's literal AND substring search misses. When an exact
		// query finds nothing, relax it before giving up. Relaxation only ever runs after
		// the exact search already failed, so a genuine match is never diluted.
		if ( empty( $products ) && '' !== $query ) {
			$relaxed = $this->relax_query( $query );
			if ( '' !== $relaxed && $relaxed !== strtolower( $query ) ) {
				$products = $this->query_products( $base, $relaxed );
			}
			if ( empty( $products ) ) {
				$products = $this->token_search( $base, $query );
			}
		}

		// Respect the store's catalog policy (issue #289): when the merchant hides out-of-stock
		// items from the shop, hide them from assistant search too, so it never recommends a
		// product the shopper cannot buy. Unconditional filter; a no-hide store keeps everything.
		$hide_out_of_stock = ( 'yes' === get_option( 'woocommerce_hide_out_of_stock_items' ) );
		$products          = array_values(
			array_filter(
				$products,
				static fn( $p ) => $p instanceof WC_Product && self::stock_visible( $p->is_in_stock(), $hide_out_of_stock )
			)
		);

		// Min-rating filter (issue #277): keep only products whose real average rating clears the
		// requested bar. Applied after query resolution so the count is honest; opt-in via the
		// pure rating_passes() guard, so an unset/zero minimum never removes anything.
		if ( $min_rating > 0.0 ) {
			$products = array_values(
				array_filter(
					$products,
					static fn( $p ) => $p instanceof WC_Product && self::rating_passes( (float) $p->get_average_rating(), $min_rating )
				)
			);
		}

		// On-sale filter (issue #137): keep only products WooCommerce reports as on sale,
		// then cap to the display limit. Applied after all query resolution so the cards
		// always match an "on sale" claim and the count is honest.
		if ( $only_sale ) {
			$products = array_slice(
				array_values(
					array_filter(
						$products,
						static fn( $p ) => $p instanceof WC_Product && $p->is_on_sale()
					)
				),
				0,
				$limit
			);
		} elseif ( $widen ) {
			// Rating-only filter widened the fetch; cap back down to the display limit.
			$products = array_slice( $products, 0, $limit );
		}

		if ( empty( $products ) ) {
			$response = [
				'found'    => 0,
				'products' => [],
				'message'  => $only_sale
					? __( 'No products are currently on sale.', 'fahad-ai-shopping-assistant-for-woocommerce' )
					: __( 'No products found matching your search.', 'fahad-ai-shopping-assistant-for-woocommerce' ),
			];

			// Dead-end recovery (issue #267): on a general no-match, offer real store categories so
			// the assistant can redirect the shopper ("browse Shoes or Bags") instead of losing them.
			// Skipped for an on-sale browse, where "nothing is on sale" needs no category detour.
			if ( ! $only_sale ) {
				$suggested = self::suggested_categories(
					get_terms( [ 'taxonomy' => 'product_cat', 'hide_empty' => true ] ),
					5
				);
				if ( ! empty( $suggested ) ) {
					$response['suggested_categories'] = $suggested;
				}
			}

			return $response;
		}

		return [
			'found'    => count( $products ),
			'products' => array_map( [ $this, 'format_product_summary' ], $products ),
		];
	}

	/**
	 * Run one product query, attaching the search term only when it is non-empty.
	 *
	 * @param array  $base  Base wc_get_products args (status/limit/filters).
	 * @param string $query Search term, or '' for no text constraint.
	 */
	private function query_products( array $base, string $query ): array {
		if ( '' !== $query ) {
			$base['s'] = $query;
		}
		return wc_get_products( $base );
	}

	/**
	 * Project the parsed wc_get_products args into the structured constraint set the
	 * semantic retriever receives (issue #60). The retriever is handed only the
	 * filters that make sense for a vector pre-filter, category, price range and
	 * limit, NOT the WooCommerce-internal keys (status/orderby), so a provider can
	 * narrow its scan and bound its result count without depending on WC query shape.
	 *
	 * @param array $base Base wc_get_products args built in search_products.
	 * @return array{category?: string, min_price?: float, max_price?: float, limit: int}
	 */
	private function semantic_filters( array $base ): array {
		$filters = [ 'limit' => (int) ( $base['limit'] ?? 5 ) ];

		// $base['category'] is a single-element slug array (see search_products).
		if ( ! empty( $base['category'] ) && is_array( $base['category'] ) ) {
			$filters['category'] = (string) reset( $base['category'] );
		}
		if ( isset( $base['min_price'] ) ) {
			$filters['min_price'] = (float) $base['min_price'];
		}
		if ( isset( $base['max_price'] ) ) {
			$filters['max_price'] = (float) $base['max_price'];
		}

		return $filters;
	}

	/**
	 * Significant search words: lowercased, de-pluralised, with size/colour/filler
	 * words dropped. Used only to relax a search that already returned nothing.
	 *
	 * @return string[] Unique stems in first-seen order.
	 */
	private function search_terms( string $query ): array {
		static $stop = [
			// Sizes.
			'xs', 's', 'm', 'l', 'xl', 'xxl', 'small', 'medium', 'large', 'extra',
			// Colours.
			'black', 'white', 'navy', 'blue', 'red', 'green', 'grey', 'gray', 'pink', 'yellow', 'brown', 'purple', 'orange',
			// Filler.
			'the', 'a', 'an', 'of', 'for', 'with', 'in', 'to', 'me', 'my', 'show', 'do', 'you', 'have', 'any', 'please', 'want', 'looking', 'need', 'some', 'one', 'find', 'get',
		];

		$words = preg_split( '/[^a-z0-9]+/', strtolower( $query ), -1, PREG_SPLIT_NO_EMPTY );
		$terms = [];
		foreach ( (array) $words as $word ) {
			if ( in_array( $word, $stop, true ) ) {
				continue;
			}
			if ( strlen( $word ) > 3 && 's' === $word[ strlen( $word ) - 1 ] ) {
				$word = substr( $word, 0, -1 ); // Drop a trailing plural "s"; substring search forgives the stem.
			}
			$terms[] = $word;
		}
		return array_values( array_unique( $terms ) );
	}

	/**
	 * Relaxed AND query: the significant stems joined back into one search string.
	 */
	private function relax_query( string $query ): string {
		return implode( ' ', $this->search_terms( $query ) );
	}

	/**
	 * Last-ditch OR search: match ANY significant term, ranked by how many terms each
	 * product hits, so "sneakers jacket" still surfaces the closest products instead of
	 * nothing. Bounded by the same limit as the primary search.
	 */
	private function token_search( array $base, string $query ): array {
		$terms = $this->search_terms( $query );
		if ( empty( $terms ) ) {
			return [];
		}

		$scored = [];
		foreach ( $terms as $term ) {
			foreach ( $this->query_products( $base, $term ) as $product ) {
				$id = $product->get_id();
				if ( ! isset( $scored[ $id ] ) ) {
					$scored[ $id ] = [ 'score' => 0, 'product' => $product ];
				}
				++$scored[ $id ]['score'];
			}
		}

		if ( empty( $scored ) ) {
			return [];
		}

		usort( $scored, fn( $a, $b ) => $b['score'] <=> $a['score'] );

		return array_map(
			fn( $entry ) => $entry['product'],
			array_slice( $scored, 0, (int) ( $base['limit'] ?? 5 ) )
		);
	}

	/**
	 * Whether a product is genuinely low on stock (issue #222), so the assistant can surface an
	 * HONEST "only N left" scarcity signal from real WooCommerce data, never a fabricated one.
	 * True only when the product is in stock and tracking a positive quantity at or below the
	 * store's low-stock threshold. Unmanaged stock (null quantity), zero, or out-of-stock is
	 * never "low". The threshold is floored at 1 so a misconfigured 0 still flags the last unit.
	 */
	public static function low_stock_flag( bool $in_stock, ?int $stock_qty, int $threshold ): bool {
		if ( ! $in_stock || null === $stock_qty || $stock_qty <= 0 ) {
			return false;
		}
		return $stock_qty <= max( 1, $threshold );
	}

	/**
	 * Whether a product is genuinely well-reviewed (issue #255), so the assistant can offer honest
	 * social proof ("one of our top-rated") from real review data, never a fabricated claim. True
	 * only when the average rating meets the bar AND there are enough reviews for it to be
	 * meaningful (min reviews floored at 1, so a single 5-star review never reads as "top-rated").
	 */
	public static function highly_rated_flag( float $rating, int $reviews, float $min_rating, int $min_reviews ): bool {
		return $reviews >= max( 1, $min_reviews ) && $rating >= $min_rating;
	}

	/**
	 * Grounded per-product "bestseller" signal from real lifetime units sold, so the assistant
	 * can honestly point to a proven best-seller. Opt-in and owner-gated: true only when a
	 * positive units-sold threshold is configured AND the product's sales meet or exceed it, so
	 * with no threshold set (default 0) no product is ever falsely badged and the owner decides
	 * what "bestseller" means for their catalogue.
	 */
	public static function bestseller_flag( int $total_sales, int $threshold ): bool {
		return $threshold > 0 && $total_sales >= $threshold;
	}

	/**
	 * Whether a product's average rating clears a requested minimum, for the search min_rating
	 * filter. A non-positive minimum (unset) passes everything, so the filter is strictly opt-in
	 * and never hides products when the shopper has not asked to narrow by rating.
	 */
	public static function rating_passes( float $rating, float $min_rating ): bool {
		return $min_rating <= 0.0 || $rating >= $min_rating;
	}

	/**
	 * Clamp a model-supplied search limit into a safe 1..10 range. The high cap bounds the result
	 * set; the low floor of 1 is the important guard, without it a supplied 0 returns an empty
	 * result and a negative value makes WooCommerce fetch the ENTIRE catalogue (limit -1 is
	 * "unbounded"), dumping every product into the LLM context.
	 */
	public static function clamp_search_limit( int $requested ): int {
		return max( 1, min( $requested, 10 ) );
	}

	/**
	 * Keep a model-supplied search price range sane: floor negative bounds to 0 (no negative
	 * prices) and swap an inverted range (min above max) so WooCommerce is never handed an
	 * impossible range that silently returns zero results, "between $100 and $50" becomes
	 * $50..$100. A null bound is left as null (no constraint on that side).
	 *
	 * @return array{0: ?float, 1: ?float} The normalized [min, max].
	 */
	/**
	 * Whether a product should appear in search given the store's "hide out of stock items"
	 * catalog setting. In-stock products are always visible; an out-of-stock product is hidden
	 * only when the merchant has opted to hide out-of-stock items, so the assistant matches what
	 * the shopper sees everywhere else on the site and never recommends something they cannot buy.
	 */
	public static function stock_visible( bool $in_stock, bool $hide_out_of_stock ): bool {
		return $in_stock || ! $hide_out_of_stock;
	}

	public static function normalize_price_range( ?float $min, ?float $max ): array {
		$min = ( null === $min ) ? null : max( 0.0, $min );
		$max = ( null === $max ) ? null : max( 0.0, $max );

		if ( null !== $min && null !== $max && $min > $max ) {
			return [ $max, $min ];
		}

		return [ $min, $max ];
	}

	/**
	 * Map a shopper-friendly sort value to WooCommerce's own orderby/order for search, so a
	 * shopper can ask for "cheapest first" or "best-rated" and get a correctly ordered, grounded
	 * result. Returns an empty array for anything unknown or empty, so the sort is strictly
	 * opt-in and search keeps its relevance order unless a real sort is requested.
	 *
	 * @return array{orderby: string, order: string}|array{}
	 */
	public static function sort_args( string $sort ): array {
		switch ( $sort ) {
			case 'price_low':
				return [ 'orderby' => 'price', 'order' => 'ASC' ];
			case 'price_high':
				return [ 'orderby' => 'price', 'order' => 'DESC' ];
			case 'rating':
				return [ 'orderby' => 'rating', 'order' => 'DESC' ];
			case 'popularity':
				return [ 'orderby' => 'popularity', 'order' => 'DESC' ];
			default:
				return [];
		}
	}

	/**
	 * Up to $limit real product-category names for redirecting a shopper after a search finds
	 * nothing, so a dead-end "no results" becomes "we don't have that, but browse X or Y." Pure:
	 * takes the get_terms() result and returns only non-empty string names, skipping a WP_Error
	 * (non-array input) or a malformed term, so the assistant only ever offers categories that
	 * genuinely exist.
	 *
	 * @param mixed $terms The get_terms() result: an array of term objects, or a WP_Error.
	 * @return array<int, string>
	 */
	public static function suggested_categories( $terms, int $limit ): array {
		if ( ! is_array( $terms ) || $limit < 1 ) {
			return [];
		}

		$names = [];
		foreach ( $terms as $term ) {
			$name = ( is_object( $term ) && isset( $term->name ) ) ? trim( (string) $term->name ) : '';
			if ( '' !== $name ) {
				$names[] = $name;
			}
			if ( count( $names ) >= $limit ) {
				break;
			}
		}

		return $names;
	}

	/**
	 * Whole-number percent off, computed from a product's real regular vs sale price, so the
	 * assistant can create honest urgency ("30% off right now"). Returns null whenever there is
	 * no genuine discount, so a shopper is never shown a fabricated, zero, or negative deal:
	 * regular price must be positive, sale price non-negative, and the sale a real reduction
	 * below regular. Rounds to the nearest whole percent.
	 */
	public static function discount_percent( float $regular_price, float $sale_price ): ?int {
		if ( $regular_price <= 0 || $sale_price < 0 || $sale_price >= $regular_price ) {
			return null;
		}

		return (int) round( ( ( $regular_price - $sale_price ) / $regular_price ) * 100 );
	}

	private function get_product_details( array $input ): array {
		$product_id = absint( $input['product_id'] ?? 0 );
		$product    = wc_get_product( $product_id );

		if ( ! $product || ! $product->is_visible() ) {
			return [ 'error' => __( 'Product not found.', 'fahad-ai-shopping-assistant-for-woocommerce' ) ];
		}

		$data = [
			'id'                => $product->get_id(),
			'name'              => $product->get_name(),
			'price'             => $this->plain_price( $product->get_price() ),
			'regular_price'     => $this->plain_price( $product->get_regular_price() ),
			'sale_price'        => $product->is_on_sale() ? $this->plain_price( $product->get_sale_price() ) : null,
			'on_sale'           => $product->is_on_sale(),
			'discount_percent'  => $product->is_on_sale()
				? self::discount_percent( (float) $product->get_regular_price(), (float) $product->get_sale_price() )
				: null,
			'description'       => wp_strip_all_tags( $product->get_description() ),
			'short_description' => wp_strip_all_tags( $product->get_short_description() ),
			'sku'               => $product->get_sku(),
			'in_stock'          => $product->is_in_stock(),
			'stock_qty'         => $product->get_stock_quantity(),
			'low_stock'         => self::low_stock_flag( $product->is_in_stock(), $product->get_stock_quantity(), (int) get_option( 'woocommerce_notify_low_stock_amount', 2 ) ),
			'type'              => $product->get_type(),
			'image'             => $this->product_image_url( $product ),
			'url'               => get_permalink( $product->get_id() ),
			'rating'            => round( (float) $product->get_average_rating(), 2 ),
			'review_count'      => (int) $product->get_review_count(),
			'highly_rated'      => self::highly_rated_flag( (float) $product->get_average_rating(), (int) $product->get_review_count(), 4.5, 5 ),
			'bestseller'        => self::bestseller_flag( (int) $product->get_total_sales(), (int) get_option( 'fahad_ai_bestseller_threshold', 0 ) ),
			'categories'        => wp_list_pluck(
				get_the_terms( $product_id, 'product_cat' ) ?: [],
				'name'
			),
		];

		if ( $product->is_type( 'variable' ) ) {
			$data['variations'] = $this->build_variations( $product );
		}

		return $data;
	}

	/**
	 * Build the surfaced variation list for a variable product.
	 *
	 * Each entry carries the variation id, a human-readable attribute `label`
	 * (e.g. "Size: Large, Color: Blue") derived from the raw attribute map, the
	 * variation's OWN price/sale/stock (not the parent's), and the raw `attributes`
	 * map (kept so the model can reason about exact attribute values).
	 *
	 * @return array<int, array{variation_id:int, label:string, attributes:array, price:string, regular_price:string, sale_price:?string, on_sale:bool, in_stock:bool}>
	 */
	private function build_variations( WC_Product $product ): array {
		$variations = [];

		foreach ( $product->get_available_variations() as $var ) {
			$attributes = isset( $var['attributes'] ) && is_array( $var['attributes'] ) ? $var['attributes'] : [];
			$v          = wc_get_product( $var['variation_id'] ?? 0 );

			if ( ! $v ) {
				continue;
			}

			$variations[] = [
				'variation_id'  => $v->get_id(),
				'label'         => $this->variation_label( $attributes ),
				'attributes'    => $attributes,
				'price'         => $this->plain_price( $v->get_price() ),
				'regular_price' => $this->plain_price( $v->get_regular_price() ),
				'sale_price'    => $v->is_on_sale() ? $this->plain_price( $v->get_sale_price() ) : null,
				'on_sale'       => $v->is_on_sale(),
				'in_stock'      => $v->is_in_stock(),
			];
		}

		return $variations;
	}

	/**
	 * Turn the raw `get_available_variations()` attribute map into a single
	 * human-readable label like "Size: Large, Color: Blue".
	 *
	 * The map keys are "attribute_" + the attribute name/taxonomy (e.g.
	 * attribute_pa_size, attribute_color); the values are term SLUGS for global
	 * (taxonomy) attributes and the literal display value for custom product
	 * attributes. We resolve the attribute name with wc_attribute_label() and, for
	 * taxonomy attributes, look the value's term name up so a slug like "large"
	 * reads as "Large". An empty value (a variation that leaves an attribute "Any")
	 * is skipped so the label stays meaningful.
	 *
	 * @param array<string,string> $attributes Raw attribute map for one variation.
	 */
	private function variation_label( array $attributes ): string {
		$parts = [];

		foreach ( $attributes as $key => $value ) {
			$value = (string) $value;
			if ( '' === $value ) {
				continue; // "Any <attribute>", nothing specific to show.
			}

			$taxonomy = preg_replace( '/^attribute_/', '', (string) $key );
			$name     = wc_attribute_label( $taxonomy );
			$display  = $value;

			// Global (taxonomy) attributes store a term slug, resolve to its name.
			if ( taxonomy_exists( $taxonomy ) ) {
				$term = get_term_by( 'slug', $value, $taxonomy );
				if ( $term && ! empty( $term->name ) ) {
					$display = $term->name;
				}
			}

			$parts[] = $name . ': ' . $display;
		}

		return implode( ', ', $parts );
	}

	private function add_to_cart( array $input ): array {
		$product_id   = absint( $input['product_id'] ?? 0 );
		$quantity     = max( 1, absint( $input['quantity'] ?? 1 ) );
		$variation_id = absint( $input['variation_id'] ?? 0 );

		$product = wc_get_product( $product_id );

		if ( ! $product || ! $product->is_visible() ) {
			return [
				'success' => false,
				'error'   => __( 'Product not found.', 'fahad-ai-shopping-assistant-for-woocommerce' ),
			];
		}

		// When a variation is chosen, the variation, not the parent, is the source
		// of truth for stock and price. Load and validate it, then gate on ITS stock
		// so a sold-out size/colour is rejected even if the parent reports in-stock.
		$item = $product;

		if ( $variation_id ) {
			$variation = wc_get_product( $variation_id );

			if ( ! $variation || (int) $variation->get_parent_id() !== $product_id ) {
				return [
					'success' => false,
					'error'   => __( 'That product option is not available.', 'fahad-ai-shopping-assistant-for-woocommerce' ),
				];
			}

			$item = $variation;
		}

		if ( ! $item->is_in_stock() ) {
			$response = [
				'success' => false,
				'error'   => sprintf(
					/* translators: %s: product (or selected variation) name */
					__( '%s is currently out of stock.', 'fahad-ai-shopping-assistant-for-woocommerce' ),
					$item->get_name()
				),
			];

			// Recover a high-intent dead end (issue #273): a sold-out add is an active buy attempt,
			// so offer the product's own categories and let the assistant point the shopper to
			// in-stock alternatives instead of ending at "out of stock". Omitted when it has none.
			$categories = self::suggested_categories( get_the_terms( $product_id, 'product_cat' ), 3 );
			if ( ! empty( $categories ) ) {
				$response['suggested_categories'] = $categories;
			}

			return $response;
		}

		// Honest stock-limit message (issue #295): the item is in stock but the shopper asked for
		// more than is available. Use WooCommerce's own has_enough_stock (which respects managed
		// stock and backorders) so we give a clear "only N left" answer instead of falling through
		// to the generic "may require a variation" error, and never touch the cart.
		if ( ! $item->has_enough_stock( $quantity ) ) {
			return [
				'success' => false,
				'error'   => sprintf(
					/* translators: 1: available quantity, 2: product (or selected variation) name */
					__( 'Only %1$d left of %2$s, so I could not add that many. Please choose %1$d or fewer.', 'fahad-ai-shopping-assistant-for-woocommerce' ),
					(int) $item->get_stock_quantity(),
					$item->get_name()
				),
			];
		}

		$cart_item_key = WC()->cart->add_to_cart( $product_id, $quantity, $variation_id );

		if ( $cart_item_key ) {
			$response = [
				'success'       => true,
				'message'       => sprintf(
					/* translators: 1: quantity, 2: product (or selected variation) name */
					__( 'Added %1$dx %2$s to your cart.', 'fahad-ai-shopping-assistant-for-woocommerce' ),
					$quantity,
					$item->get_name()
				),
				'cart_item_key' => $cart_item_key,
				'price'         => $this->plain_price( $item->get_price() ),
				'cart_total'    => wp_strip_all_tags( WC()->cart->get_cart_total() ),
				'cart_url'      => wc_get_cart_url(),
				'checkout_url'  => wc_get_checkout_url(),
				// Scarcity at the peak-commitment moment (issue #269): reuse the grounded low-stock
				// signal for the exact item added so the assistant can honestly say "only N left,
				// best to check out soon" and reduce abandonment on nearly sold-out items.
				'low_stock'     => self::low_stock_flag( $item->is_in_stock(), $item->get_stock_quantity(), (int) get_option( 'woocommerce_notify_low_stock_amount', 2 ) ),
				'stock_qty'     => $item->get_stock_quantity(),
			];

			// Precise free-shipping nudge at the highest-leverage moment (issue #220): right
			// after an add, tell the shopper the exact amount left to unlock free shipping so
			// they can top up now. Only when a threshold is configured; grounded, not guessed.
			$threshold = (float) get_option( 'fahad_ai_free_shipping_threshold', 0 );
			if ( $threshold > 0 ) {
				$response['free_shipping'] = self::free_shipping_progress( (float) WC()->cart->get_cart_contents_total(), $threshold );
			}

			return $response;
		}

		return [
			'success' => false,
			'error'   => __( 'Could not add to cart. The product may require a variation to be selected.', 'fahad-ai-shopping-assistant-for-woocommerce' ),
		];
	}

	/**
	 * Free-shipping progress for a cart total (issue #218). Returns the configured threshold,
	 * the exact remaining amount to qualify (floored at 0), and whether the cart already
	 * qualifies, or null when no threshold is set. Pure: the caller supplies the numbers, so
	 * the assistant can state a grounded "you are $X away from free shipping" from real cart
	 * data instead of estimating.
	 *
	 * @return array{threshold: float, remaining: float, qualified: bool}|null
	 */
	public static function free_shipping_progress( float $cart_total, float $threshold ): ?array {
		if ( $threshold <= 0 ) {
			return null;
		}
		$remaining = max( 0.0, $threshold - $cart_total );
		return [
			'threshold' => $threshold,
			'remaining' => $remaining,
			'qualified' => $remaining <= 0.0,
		];
	}

	/**
	 * Total money saved across a cart from genuinely discounted lines, so at the highest-
	 * abandonment moment (cart review) the assistant can reinforce "you're saving $X across your
	 * cart" from real cart data instead of estimating. Pure: the caller supplies each line's
	 * regular and sale unit price and quantity. A line only counts when its sale price is a real
	 * reduction below a positive regular price, so non-sale, zero/negative-regular, and negative-
	 * sale lines contribute nothing. Returns null when there is no genuine saving, so a shopper is
	 * never shown a fabricated or zero total. Rounds to 2 decimals.
	 *
	 * @param array<int, array{regular?: float, sale?: float, quantity?: int}> $lines
	 */
	public static function cart_savings( array $lines ): ?float {
		$total = 0.0;

		foreach ( $lines as $line ) {
			$regular  = (float) ( $line['regular'] ?? 0 );
			$sale     = (float) ( $line['sale'] ?? 0 );
			$quantity = max( 0, (int) ( $line['quantity'] ?? 0 ) );

			if ( $regular > 0 && $sale >= 0 && $sale < $regular ) {
				$total += ( $regular - $sale ) * $quantity;
			}
		}

		return $total > 0 ? round( $total, 2 ) : null;
	}

	private function view_cart(): array {
		$cart = WC()->cart;

		if ( $cart->is_empty() ) {
			return [
				'empty'   => true,
				'message' => __( 'Your cart is empty.', 'fahad-ai-shopping-assistant-for-woocommerce' ),
			];
		}

		$items         = [];
		$savings_lines = [];
		foreach ( $cart->get_cart() as $key => $item ) {
			/** @var WC_Product $product */
			$product = $item['data'];
			$items[] = [
				'cart_item_key' => $key,
				'product_id'    => $item['product_id'],
				'name'          => $product->get_name(),
				'quantity'      => $item['quantity'],
				'price'         => wc_price( $product->get_price() ),
				'line_total'    => wc_price( $item['line_total'] ),
			];

			$regular         = (float) $product->get_regular_price();
			$savings_lines[] = [
				'regular'  => $regular,
				'sale'     => $product->is_on_sale() ? (float) $product->get_sale_price() : $regular,
				'quantity' => (int) $item['quantity'],
			];
		}

		$response = [
			'empty'        => false,
			'items'        => $items,
			'item_count'   => $cart->get_cart_contents_count(),
			'subtotal'     => wp_strip_all_tags( $cart->get_cart_subtotal() ),
			'total'        => wp_strip_all_tags( $cart->get_cart_total() ),
			'cart_url'     => wc_get_cart_url(),
			'checkout_url' => wc_get_checkout_url(),
		];

		// Precise free-shipping nudge (issue #218): when a threshold is configured, add the
		// exact remaining amount from the real cart total so the assistant can say "you are
		// $X away from free shipping" without guessing.
		$threshold = (float) get_option( 'fahad_ai_free_shipping_threshold', 0 );
		if ( $threshold > 0 ) {
			$response['free_shipping'] = self::free_shipping_progress( (float) $cart->get_cart_contents_total(), $threshold );
		}

		// Grounded savings reassurance (issue #259): at the highest-abandonment moment, surface
		// the real total saved across on-sale lines so the assistant can reinforce "you're saving
		// $X across your cart." Omitted entirely when there is nothing genuinely on sale.
		$cart_savings = self::cart_savings( $savings_lines );
		if ( null !== $cart_savings ) {
			$response['cart_savings'] = $cart_savings;
		}

		// Reflect applied coupons at cart review (issue #297): show the active codes and the money
		// they took off (via the same guard as apply_coupon), so a shopper can confirm their code
		// is still working before checkout. Omitted when no coupon is applied or it reduced nothing.
		$applied_coupons = array_values( array_filter( array_map( 'strval', (array) $cart->get_applied_coupons() ) ) );
		if ( ! empty( $applied_coupons ) ) {
			$response['applied_coupons'] = $applied_coupons;

			$discount_total = Fahad_AI_Coupon_Tools::coupon_discount_amount( (float) $cart->get_discount_total() );
			if ( null !== $discount_total ) {
				$response['discount_total'] = $discount_total;
			}
		}

		return $response;
	}

	/**
	 * Change the quantity of an item already in the cart (issue #303), the "make it 3" companion
	 * to add/remove. Validates the line exists and that WooCommerce reports enough stock for the
	 * new amount (has_enough_stock, so backorders are honoured) before setting it, then returns
	 * the updated total and re-surfaces free-shipping progress like remove_from_cart.
	 */
	private function update_cart_quantity( array $input ): array {
		$key      = sanitize_text_field( $input['cart_item_key'] ?? '' );
		$quantity = max( 1, absint( $input['quantity'] ?? 0 ) );

		if ( '' === $key ) {
			return [
				'success' => false,
				'error'   => __( 'Cart item key is required.', 'fahad-ai-shopping-assistant-for-woocommerce' ),
			];
		}

		$cart_contents = WC()->cart->get_cart();

		if ( ! isset( $cart_contents[ $key ] ) ) {
			return [
				'success' => false,
				'error'   => __( 'Item not found in cart.', 'fahad-ai-shopping-assistant-for-woocommerce' ),
			];
		}

		/** @var WC_Product $item */
		$item = $cart_contents[ $key ]['data'];

		// Honest stock-limit message (mirrors add_to_cart, issue #295): never set a quantity the
		// store cannot fulfil; tell the shopper how many are actually available instead.
		if ( ! $item->has_enough_stock( $quantity ) ) {
			return [
				'success' => false,
				'error'   => sprintf(
					/* translators: 1: available quantity, 2: product name */
					__( 'Only %1$d left of %2$s, so I could not set that many. Please choose %1$d or fewer.', 'fahad-ai-shopping-assistant-for-woocommerce' ),
					(int) $item->get_stock_quantity(),
					$item->get_name()
				),
			];
		}

		WC()->cart->set_quantity( $key, $quantity );

		$response = [
			'success'   => true,
			'message'   => sprintf(
				/* translators: 1: product name, 2: new quantity */
				__( 'Updated %1$s to %2$d in your cart.', 'fahad-ai-shopping-assistant-for-woocommerce' ),
				$item->get_name(),
				$quantity
			),
			'new_total' => wp_strip_all_tags( WC()->cart->get_cart_total() ),
		];

		// Keep the free-shipping goal in view (issue #271): a quantity change moves the cart total,
		// so re-surface how far the shopper is from free shipping when a threshold is configured.
		$threshold = (float) get_option( 'fahad_ai_free_shipping_threshold', 0 );
		if ( $threshold > 0 ) {
			$response['free_shipping'] = self::free_shipping_progress( (float) WC()->cart->get_cart_contents_total(), $threshold );
		}

		return $response;
	}

	private function remove_from_cart( array $input ): array {
		$key = sanitize_text_field( $input['cart_item_key'] ?? '' );

		if ( empty( $key ) ) {
			return [
				'success' => false,
				'error'   => __( 'Cart item key is required.', 'fahad-ai-shopping-assistant-for-woocommerce' ),
			];
		}

		$cart_contents = WC()->cart->get_cart();

		if ( ! isset( $cart_contents[ $key ] ) ) {
			return [
				'success' => false,
				'error'   => __( 'Item not found in cart.', 'fahad-ai-shopping-assistant-for-woocommerce' ),
			];
		}

		$product_name = $cart_contents[ $key ]['data']->get_name();

		if ( WC()->cart->remove_cart_item( $key ) ) {
			$response = [
				'success'   => true,
				'message'   => sprintf(
					/* translators: %s: product name */
					__( 'Removed %s from your cart.', 'fahad-ai-shopping-assistant-for-woocommerce' ),
					$product_name
				),
				'new_total' => wp_strip_all_tags( WC()->cart->get_cart_total() ),
			];

			// Re-surface free-shipping progress (issue #271): removing an item can drop the cart
			// below the threshold, so tell the shopper how much they are now away, from the real
			// updated total, and give them a reason to re-add. Only when a threshold is configured.
			$threshold = (float) get_option( 'fahad_ai_free_shipping_threshold', 0 );
			if ( $threshold > 0 ) {
				$response['free_shipping'] = self::free_shipping_progress( (float) WC()->cart->get_cart_contents_total(), $threshold );
			}

			return $response;
		}

		return [
			'success' => false,
			'error'   => __( 'Could not remove the item.', 'fahad-ai-shopping-assistant-for-woocommerce' ),
		];
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	/**
	 * Reduce a WC_Product to the canonical card-shaped summary the widget
	 * renders (id, name, price, regular_price, sale_price, on_sale, in_stock,
	 * short_description, image, url, rating, review_count).
	 *
	 * PUBLIC and shared on purpose: this is the single source of truth for how a
	 * product becomes card data. The built-in search_products uses it, and
	 * filter-registered product tools (best-sellers, recommendations, …) MUST
	 * reuse it instead of re-deriving these fields, so every product card stays
	 * consistent and the convention-based card emission in
	 * Fahad_AI_API_Handler::tool_result_cards() keeps working uniformly.
	 */
	public function format_product_summary( WC_Product $product ): array {
		return [
			'id'                => $product->get_id(),
			'name'              => $product->get_name(),
			'price'             => $this->plain_price( $product->get_price() ),
			'regular_price'     => $this->plain_price( $product->get_regular_price() ),
			'sale_price'        => $product->is_on_sale() ? $this->plain_price( $product->get_sale_price() ) : null,
			'on_sale'           => $product->is_on_sale(),
			'discount_percent'  => $product->is_on_sale()
				? self::discount_percent( (float) $product->get_regular_price(), (float) $product->get_sale_price() )
				: null,
			'in_stock'          => $product->is_in_stock(),
			'short_description' => wp_strip_all_tags( $product->get_short_description() ),
			'image'             => $this->product_image_url( $product ),
			'url'               => get_permalink( $product->get_id() ),
			'rating'            => round( (float) $product->get_average_rating(), 2 ),
			'review_count'      => (int) $product->get_review_count(),
			'highly_rated'      => self::highly_rated_flag( (float) $product->get_average_rating(), (int) $product->get_review_count(), 4.5, 5 ),
		];
	}

	/**
	 * Thumbnail URL for the product, falling back to the WooCommerce placeholder.
	 */
	private function product_image_url( WC_Product $product ): string {
		$image_id = $product->get_image_id();
		$url      = $image_id ? wp_get_attachment_image_url( $image_id, 'woocommerce_thumbnail' ) : '';
		return $url ? $url : wc_placeholder_img_src( 'woocommerce_thumbnail' );
	}

	/**
	 * Format a raw price into a plain display string (currency symbol, no HTML).
	 * `wc_price()` returns markup with HTML entities; the AI and the widget cards
	 * both want a clean string like "₨90.00".
	 */
	private function plain_price( $price ): string {
		if ( '' === $price || null === $price ) {
			return '';
		}
		return trim( html_entity_decode( wp_strip_all_tags( wc_price( $price ) ), ENT_QUOTES, 'UTF-8' ) );
	}
}
