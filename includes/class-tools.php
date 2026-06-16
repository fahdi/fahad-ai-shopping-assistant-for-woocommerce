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
						'limit'     => [ 'type' => 'integer', 'description' => 'Max results (default 5, max 10)' ],
					],
				],
				'callback'    => fn( array $input ) => $this->search_products( $input ),
			],
			[
				'name'        => 'get_product_details',
				'description' => 'Get full details for a product — description, price, stock, variations.',
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
		$args = [
			'status'  => 'publish',
			'limit'   => min( (int) ( $input['limit'] ?? 5 ), 10 ),
			'orderby' => 'relevance',
		];

		if ( ! empty( $input['query'] ) ) {
			$args['s'] = sanitize_text_field( $input['query'] );
		}

		if ( ! empty( $input['category'] ) ) {
			$args['category'] = [ sanitize_text_field( $input['category'] ) ];
		}

		if ( isset( $input['min_price'] ) ) {
			$args['min_price'] = (float) $input['min_price'];
		}

		if ( isset( $input['max_price'] ) ) {
			$args['max_price'] = (float) $input['max_price'];
		}

		$products = wc_get_products( $args );

		if ( empty( $products ) ) {
			return [
				'found'    => 0,
				'products' => [],
				'message'  => __( 'No products found matching your search.', 'fahad-ai-shopping-assistant-for-woocommerce' ),
			];
		}

		return [
			'found'    => count( $products ),
			'products' => array_map( [ $this, 'format_product_summary' ], $products ),
		];
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
			$variations = [];
			foreach ( $product->get_available_variations() as $var ) {
				$v = wc_get_product( $var['variation_id'] );
				if ( $v ) {
					$variations[] = [
						'variation_id' => $v->get_id(),
						'attributes'   => $var['attributes'],
						'price'        => $this->plain_price( $v->get_price() ),
						'in_stock'     => $v->is_in_stock(),
					];
				}
			}
			$data['variations'] = $variations;
		}

		return $data;
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

		if ( ! $product->is_in_stock() ) {
			return [
				'success' => false,
				'error'   => sprintf(
					/* translators: %s: product name */
					__( '%s is currently out of stock.', 'fahad-ai-shopping-assistant-for-woocommerce' ),
					$product->get_name()
				),
			];
		}

		$cart_item_key = WC()->cart->add_to_cart( $product_id, $quantity, $variation_id );

		if ( $cart_item_key ) {
			return [
				'success'       => true,
				'message'       => sprintf(
					/* translators: 1: quantity, 2: product name */
					__( 'Added %1$dx %2$s to your cart.', 'fahad-ai-shopping-assistant-for-woocommerce' ),
					$quantity,
					$product->get_name()
				),
				'cart_item_key' => $cart_item_key,
				'cart_total'    => wp_strip_all_tags( WC()->cart->get_cart_total() ),
				'cart_url'      => wc_get_cart_url(),
				'checkout_url'  => wc_get_checkout_url(),
			];
		}

		return [
			'success' => false,
			'error'   => __( 'Could not add to cart. The product may require a variation to be selected.', 'fahad-ai-shopping-assistant-for-woocommerce' ),
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

		return [
			'empty'        => false,
			'items'        => $items,
			'item_count'   => $cart->get_cart_contents_count(),
			'subtotal'     => wp_strip_all_tags( $cart->get_cart_subtotal() ),
			'total'        => wp_strip_all_tags( $cart->get_cart_total() ),
			'cart_url'     => wc_get_cart_url(),
			'checkout_url' => wc_get_checkout_url(),
		];
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
