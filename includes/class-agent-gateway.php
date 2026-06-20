<?php
/**
 * Store-as-an-agent gateway (Epic C).
 *
 * Lets external AI agents (ChatGPT, Claude, Perplexity, …) discover and shop the store
 * through grounded, read-only endpoints that reuse the SAME tool layer as the chat
 * widget, so an agent sees exactly the data a shopper would:
 *   - C1 GET /agent/llms     — a text usage policy (llms.txt-style) pointing agents at
 *                              the feed and stating what is allowed.
 *        GET /agent/catalog  — a structured, cacheable product feed (trusted WC fields).
 *   - C2 GET /agent/search   — reuse search_products (grounded card data).
 *        GET /agent/product  — reuse get_product_details.
 *   - C3 GET /agent/checkout-handoff — build a HUMAN add-to-cart + checkout URL for the
 *                              chosen products. No payment runs agent-side, and no PII
 *                              ever leaves: the agent gets a link a person opens and
 *                              completes themselves.
 *
 * All endpoints are read-only and expose only published catalog data; the handoff only
 * returns URLs. Nothing here mutates the store or charges anyone.
 *
 * @package Fahad_AI
 */

defined( 'ABSPATH' ) || exit;

final class Fahad_AI_Agent_Gateway {

	private static ?Fahad_AI_Agent_Gateway $instance = null;

	public static function instance(): Fahad_AI_Agent_Gateway {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {}

	public function register_routes(): void {
		$public = '__return_true'; // read-only catalog data; safe to expose to agents.

		foreach ( [ 'llms', 'catalog', 'search', 'product', 'checkout-handoff' ] as $path ) {
			$method = 'rest_' . str_replace( '-', '_', $path );
			register_rest_route( 'fahad-ai/v1', '/agent/' . $path, [
				'methods'             => 'GET',
				'callback'            => [ $this, $method ],
				'permission_callback' => $public,
			] );
		}
	}

	// ── C1: llms.txt + catalog feed ─────────────────────────────────────────────

	public function rest_llms( WP_REST_Request $request ) {
		$store   = (string) get_bloginfo( 'name' );
		$catalog = rest_url( 'fahad-ai/v1/agent/catalog' );
		$search  = rest_url( 'fahad-ai/v1/agent/search' );

		$body = "# {$store} — AI agent guide\n"
			. "\n"
			. "This store welcomes AI shopping agents. Use the read-only endpoints below; all\n"
			. "data is grounded in the live catalogue. Do not fabricate prices, stock, or\n"
			. "availability — read them here.\n"
			. "\n"
			. "Catalogue feed: {$catalog}\n"
			. "Search:         {$search}?q=QUERY\n"
			. "Checkout:       a human completes payment; request /agent/checkout-handoff for a\n"
			. "                link the shopper opens themselves.\n";

		$response = new WP_REST_Response( $body );
		$response->header( 'Content-Type', 'text/plain; charset=utf-8' );
		return $response;
	}

	public function rest_catalog( WP_REST_Request $request ) {
		$products = (array) wc_get_products( [ 'status' => 'publish', 'limit' => 200 ] );

		$items = [];
		foreach ( $products as $product ) {
			if ( ! $product instanceof WC_Product ) {
				continue;
			}
			$items[] = [
				'id'         => (int) $product->get_id(),
				'name'       => (string) $product->get_name(),
				'price'      => (float) $product->get_price(),
				'on_sale'    => (bool) $product->is_on_sale(),
				'in_stock'   => (bool) $product->is_in_stock(),
				'url'        => (string) get_permalink( $product->get_id() ),
				'short_desc' => wp_strip_all_tags( (string) $product->get_short_description() ),
			];
		}

		$response = new WP_REST_Response( [ 'count' => count( $items ), 'products' => $items ] );
		$response->header( 'Cache-Control', 'public, max-age=300' );
		return $response;
	}

	// ── C2: read-only agent endpoints (reuse the chat tools) ────────────────────

	public function rest_search( WP_REST_Request $request ) {
		$query  = sanitize_text_field( (string) $request->get_param( 'q' ) );
		$result = Fahad_AI_Tool_Registry::instance()->dispatch( 'search_products', [ 'query' => $query ] );
		return rest_ensure_response( $result );
	}

	public function rest_product( WP_REST_Request $request ) {
		$id     = (int) $request->get_param( 'id' );
		$result = Fahad_AI_Tool_Registry::instance()->dispatch( 'get_product_details', [ 'product_id' => $id ] );
		return rest_ensure_response( $result );
	}

	// ── C3: human checkout handoff (no agent-side payment) ──────────────────────

	public function rest_checkout_handoff( WP_REST_Request $request ) {
		$ids = $this->parse_ids( $request->get_param( 'ids' ) );
		if ( empty( $ids ) ) {
			return new WP_Error( 'fahad_ai_no_products', __( 'No products specified.', 'fahad-ai-shopping-assistant-for-woocommerce' ), [ 'status' => 400 ] );
		}

		// A WooCommerce add-to-cart URL a HUMAN opens to populate their own cart, then
		// checks out in their own session. The agent never holds a cart or pays.
		$cart_url = (string) wc_get_cart_url();

		$handoff = [];
		foreach ( $ids as $id ) {
			$handoff[] = [
				'product_id'  => $id,
				'add_to_cart' => (string) add_query_arg( 'add-to-cart', $id, $cart_url ),
			];
		}

		return rest_ensure_response( [
			'note'  => __( 'Open these links in a browser to add the items and check out. Payment is completed by the shopper, not the agent.', 'fahad-ai-shopping-assistant-for-woocommerce' ),
			'items' => $handoff,
		] );
	}

	// ── helpers ─────────────────────────────────────────────────────────────────

	/** Parse a comma-separated id list into a clean array of unique positive ints. */
	private function parse_ids( $raw ): array {
		$ids = [];
		foreach ( explode( ',', (string) $raw ) as $part ) {
			$id = (int) trim( $part );
			if ( $id > 0 ) {
				$ids[] = $id;
			}
		}
		return array_values( array_unique( $ids ) );
	}
}
