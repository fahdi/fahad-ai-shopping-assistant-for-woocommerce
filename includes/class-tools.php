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
		$only_sale = ! empty( $input['on_sale'] );
		$limit     = min( (int) ( $input['limit'] ?? 5 ), 10 );

		$base = [
			'status'  => 'publish',
			'limit'   => $only_sale ? 50 : $limit,
			'orderby' => 'relevance',
		];

		if ( ! empty( $input['category'] ) ) {
			$base['category'] = [ sanitize_text_field( $input['category'] ) ];
		}

		if ( isset( $input['min_price'] ) ) {
			$base['min_price'] = (float) $input['min_price'];
		}

		if ( isset( $input['max_price'] ) ) {
			$base['max_price'] = (float) $input['max_price'];
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
		}

		if ( empty( $products ) ) {
			return [
				'found'    => 0,
				'products' => [],
				'message'  => $only_sale
					? __( 'No products are currently on sale.', 'fahad-ai-shopping-assistant-for-woocommerce' )
					: __( 'No products found matching your search.', 'fahad-ai-shopping-assistant-for-woocommerce' ),
			];
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
			'description'       => wp_strip_all_tags( $product->get_description() ),
			'short_description' => wp_strip_all_tags( $product->get_short_description() ),
			'sku'               => $product->get_sku(),
			'in_stock'          => $product->is_in_stock(),
			'stock_qty'         => $product->get_stock_quantity(),
			'type'              => $product->get_type(),
			'image'             => $this->product_image_url( $product ),
			'url'               => get_permalink( $product->get_id() ),
			'rating'            => round( (float) $product->get_average_rating(), 2 ),
			'review_count'      => (int) $product->get_review_count(),
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
			return [
				'success' => false,
				'error'   => sprintf(
					/* translators: %s: product (or selected variation) name */
					__( '%s is currently out of stock.', 'fahad-ai-shopping-assistant-for-woocommerce' ),
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

	private function view_cart(): array {
		$cart = WC()->cart;

		if ( $cart->is_empty() ) {
			return [
				'empty'   => true,
				'message' => __( 'Your cart is empty.', 'fahad-ai-shopping-assistant-for-woocommerce' ),
			];
		}

		$items = [];
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
			return [
				'success'   => true,
				'message'   => sprintf(
					/* translators: %s: product name */
					__( 'Removed %s from your cart.', 'fahad-ai-shopping-assistant-for-woocommerce' ),
					$product_name
				),
				'new_total' => wp_strip_all_tags( WC()->cart->get_cart_total() ),
			];
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
			'in_stock'          => $product->is_in_stock(),
			'short_description' => wp_strip_all_tags( $product->get_short_description() ),
			'image'             => $this->product_image_url( $product ),
			'url'               => get_permalink( $product->get_id() ),
			'rating'            => round( (float) $product->get_average_rating(), 2 ),
			'review_count'      => (int) $product->get_review_count(),
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
