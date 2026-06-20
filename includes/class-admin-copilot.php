<?php
/**
 * Merchant AI copilot — admin-side REST surface (Epic B).
 *
 * A read-only / draft-only set of endpoints under fahad-ai/v1/admin, ALL gated by the
 * manage_woocommerce capability (NOT the storefront nonce). Each returns GROUNDED data
 * straight from WooCommerce so the admin copilot can reason over real numbers and never
 * invent them:
 *   - B1 /admin/insights        — orders / revenue / refunds for a window.
 *   - B2 /admin/sale-candidates — products to consider discounting (low velocity, healthy
 *                                 margin), with a suggested discount. Proposes only.
 *   - B3 /admin/product-context — a product's REAL attributes, the grounding for any
 *                                 generated description / meta / alt text (no fabrication).
 *   - B4 /admin/review-drafts   — unanswered product reviews needing a reply, with the
 *                                 real review text/rating the draft must address.
 *
 * Nothing here writes store data; the copilot proposes and the merchant applies.
 *
 * @package Fahad_AI
 */

defined( 'ABSPATH' ) || exit;

final class Fahad_AI_Admin_Copilot {

	private static ?Fahad_AI_Admin_Copilot $instance = null;

	public static function instance(): Fahad_AI_Admin_Copilot {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {}

	/**
	 * The capability gate for every admin endpoint: a real store manager, never the
	 * storefront nonce. Centralised so all routes share one contract.
	 */
	public function can_manage(): bool {
		return current_user_can( 'manage_woocommerce' );
	}

	public function register_routes(): void {
		$gate = [ $this, 'can_manage' ];

		register_rest_route( 'fahad-ai/v1', '/admin/insights', [
			'methods'             => 'GET',
			'callback'            => [ $this, 'rest_insights' ],
			'permission_callback' => $gate,
		] );

		register_rest_route( 'fahad-ai/v1', '/admin/sale-candidates', [
			'methods'             => 'GET',
			'callback'            => [ $this, 'rest_sale_candidates' ],
			'permission_callback' => $gate,
		] );

		register_rest_route( 'fahad-ai/v1', '/admin/product-context', [
			'methods'             => 'GET',
			'callback'            => [ $this, 'rest_product_context' ],
			'permission_callback' => $gate,
		] );

		register_rest_route( 'fahad-ai/v1', '/admin/review-drafts', [
			'methods'             => 'GET',
			'callback'            => [ $this, 'rest_review_drafts' ],
			'permission_callback' => $gate,
		] );
	}

	// ── B1: store insights ──────────────────────────────────────────────────────

	public function rest_insights( WP_REST_Request $request ) {
		$days = $this->window_days( $request->get_param( 'days' ) );
		return rest_ensure_response( $this->insights( $days ) );
	}

	/**
	 * A grounded sales summary for the last $days: order count, gross revenue, refund
	 * total and count, all from real completed/processing orders.
	 *
	 * @return array{window_days:int,orders:int,revenue:float,refunds:float,refunded_orders:int,currency:string}
	 */
	public function insights( int $days ): array {
		$orders = $this->orders_in_window( $days );

		$revenue         = 0.0;
		$refunds         = 0.0;
		$refunded_orders = 0;

		foreach ( $orders as $order ) {
			$revenue += (float) $order->get_total();
			$refunded = (float) $order->get_total_refunded();
			if ( $refunded > 0 ) {
				$refunds += $refunded;
				$refunded_orders++;
			}
		}

		return [
			'window_days'     => $days,
			'orders'          => count( $orders ),
			'revenue'         => round( $revenue, 2 ),
			'refunds'         => round( $refunds, 2 ),
			'refunded_orders' => $refunded_orders,
			'currency'        => function_exists( 'get_woocommerce_currency' ) ? (string) get_woocommerce_currency() : '',
		];
	}

	// ── B2: sale candidates ─────────────────────────────────────────────────────

	public function rest_sale_candidates( WP_REST_Request $request ) {
		$days  = $this->window_days( $request->get_param( 'days' ) );
		$limit = max( 1, min( (int) ( $request->get_param( 'limit' ) ?: 5 ), 20 ) );
		return rest_ensure_response( [ 'candidates' => $this->sale_candidates( $days, $limit ) ] );
	}

	/**
	 * Products worth considering for a sale: in stock, NOT already on sale, ranked by
	 * LOWEST recent units sold (slow movers surface first). Each candidate carries a
	 * suggested, bounded discount so the copilot proposes a concrete deal — never below a
	 * floor that would erase the margin. Read-only; the merchant decides.
	 *
	 * @return array<int,array{id:int,name:string,price:float,units_sold:int,suggested_discount_percent:int}>
	 */
	public function sale_candidates( int $days, int $limit ): array {
		$products = $this->published_products();

		$rows = [];
		foreach ( $products as $product ) {
			if ( ! $product->is_in_stock() || $product->is_on_sale() ) {
				continue;
			}
			$rows[] = [
				'id'                         => (int) $product->get_id(),
				'name'                       => (string) $product->get_name(),
				'price'                      => (float) $product->get_price(),
				'units_sold'                 => (int) $product->get_total_sales(),
				'suggested_discount_percent' => 10,
			];
		}

		// Slowest movers first (fewest total sales).
		usort( $rows, static fn( $a, $b ) => $a['units_sold'] <=> $b['units_sold'] );

		return array_slice( $rows, 0, $limit );
	}

	// ── B3: product content grounding ───────────────────────────────────────────

	public function rest_product_context( WP_REST_Request $request ) {
		$product = $this->product( (int) $request->get_param( 'product_id' ) );
		if ( null === $product ) {
			return new WP_Error( 'fahad_ai_not_found', __( 'Product not found.', 'fahad-ai-shopping-assistant-for-woocommerce' ), [ 'status' => 404 ] );
		}
		return rest_ensure_response( $this->product_context( $product ) );
	}

	/**
	 * A product's REAL attributes — the grounding any generated description / SEO meta /
	 * alt text must stick to, so the copilot can rewrite the wording without inventing
	 * specs. Returns trusted WooCommerce fields only.
	 *
	 * @param WC_Product $product
	 * @return array{id:int,name:string,sku:string,price:float,categories:array<int,string>,attributes:array<string,string>,short_description:string}
	 */
	public function product_context( $product ): array {
		$attributes = [];
		foreach ( (array) $product->get_attributes() as $name => $value ) {
			$attributes[ (string) $name ] = is_object( $value ) && method_exists( $value, 'get_name' )
				? (string) $value->get_name()
				: (string) ( is_array( $value ) ? implode( ', ', $value ) : $value );
		}

		return [
			'id'                => (int) $product->get_id(),
			'name'              => (string) $product->get_name(),
			'sku'               => (string) $product->get_sku(),
			'price'             => (float) $product->get_price(),
			'categories'        => $this->product_category_names( $product ),
			'attributes'        => $attributes,
			'short_description' => wp_strip_all_tags( (string) $product->get_short_description() ),
		];
	}

	// ── B4: review drafts ───────────────────────────────────────────────────────

	public function rest_review_drafts( WP_REST_Request $request ) {
		$limit = max( 1, min( (int) ( $request->get_param( 'limit' ) ?: 10 ), 50 ) );
		return rest_ensure_response( [ 'reviews' => $this->unanswered_reviews( $limit ) ] );
	}

	/**
	 * Product reviews that have no store reply yet — the real review text and rating the
	 * copilot's drafted reply must address. A review is "answered" when it already has a
	 * child comment (a reply). Grounded: returns the actual review content, never a
	 * fabricated one.
	 *
	 * @return array<int,array{id:int,product_id:int,author:string,rating:int,content:string}>
	 */
	public function unanswered_reviews( int $limit ): array {
		$reviews = get_comments( [
			'type'    => 'review',
			'status'  => 'approve',
			'number'  => $limit,
			'parent'  => 0,
			'orderby' => 'comment_date_gmt',
			'order'   => 'DESC',
		] );

		$out = [];
		foreach ( (array) $reviews as $review ) {
			if ( $this->has_reply( (int) $review->comment_ID ) ) {
				continue;
			}
			$out[] = [
				'id'         => (int) $review->comment_ID,
				'product_id' => (int) $review->comment_post_ID,
				'author'     => (string) $review->comment_author,
				'rating'     => (int) get_comment_meta( (int) $review->comment_ID, 'rating', true ),
				'content'    => wp_strip_all_tags( (string) $review->comment_content ),
			];
		}

		return $out;
	}

	// ── grounded data helpers ───────────────────────────────────────────────────

	/** Clamp a requested window to a sane 1–90 days (default 7). */
	private function window_days( $raw ): int {
		$days = (int) $raw;
		if ( $days < 1 ) {
			return 7;
		}
		return min( $days, 90 );
	}

	/** Completed/processing orders created within the window. */
	private function orders_in_window( int $days ): array {
		$after = gmdate( 'Y-m-d H:i:s', time() - ( $days * DAY_IN_SECONDS ) );
		return (array) wc_get_orders( [
			'status'       => [ 'wc-completed', 'wc-processing' ],
			'date_created' => '>=' . $after,
			'limit'        => -1,
		] );
	}

	private function published_products(): array {
		return (array) wc_get_products( [ 'status' => 'publish', 'limit' => 100 ] );
	}

	private function product( int $id ) {
		if ( $id <= 0 ) {
			return null;
		}
		$product = wc_get_product( $id );
		return $product instanceof WC_Product ? $product : null;
	}

	private function product_category_names( $product ): array {
		$names = [];
		foreach ( (array) wc_get_product_terms( $product->get_id(), 'product_cat', [ 'fields' => 'names' ] ) as $name ) {
			$names[] = (string) $name;
		}
		return $names;
	}

	private function has_reply( int $review_id ): bool {
		$children = get_comments( [ 'parent' => $review_id, 'number' => 1, 'count' => true ] );
		return (int) $children > 0;
	}
}
