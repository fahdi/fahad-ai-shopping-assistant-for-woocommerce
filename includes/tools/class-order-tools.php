<?php
defined( 'ABSPATH' ) || exit;

/**
 * Order status & tracking tools (issue #17) — self-service "where's my order?"
 * for LOGGED-IN customers only.
 *
 * A drop-in feature pack (same pattern as Fahad_AI_Catalog_Tools / Fahad_AI_Coupon_Tools):
 * a self-contained class in its own file under includes/tools/ that self-registers a
 * provider at the bottom via Fahad_AI_Tool_Registry::register_pack(). The bootstrap
 * (and the test bootstrap) glob-require everything here, so adding this pack is a
 * SINGLE new file — no edits to the bootstrap, the test bootstrap, or the eval harness.
 *
 * Tools provided:
 *   - get_my_orders     — the logged-in customer's recent orders (number, status,
 *                         date, total, item summary).
 *   - get_order_status  — status (+ a tracking note if the store records one) for ONE
 *                         order, but ONLY if it belongs to the current user.
 *
 * SECURITY IS THE WHOLE POINT — order data is PII and leaking ANOTHER customer's
 * order is the highest-severity failure. These tools use the issue-#25 authorization
 * boundary (Fahad_AI_Auth) in BOTH of its layers (defence in depth):
 *
 *   1. CENTRAL LOGIN GATE. Both tools declare `'personal' => true`, so
 *      Fahad_AI_Tool_Registry::dispatch() runs Fahad_AI_Auth::guard_logged_in()
 *      BEFORE the callback. A guest is blocked centrally with the standard
 *      login-required error and the callback is never reached — these tools never
 *      re-implement the guest check, so they cannot leak by forgetting it.
 *
 *   2. PER-RECORD OWNERSHIP. The registry cannot know which customer a given order
 *      belongs to, so get_order_status loads the order and then calls
 *      Fahad_AI_Auth::user_owns( $order->get_customer_id() ); a mismatch returns a
 *      "not found"-style error (NOT "forbidden"), so we never even confirm that an
 *      order exists for another user. get_my_orders needs no per-record check
 *      because its query is scoped by `customer_id` to the current user, so it can
 *      only ever return that user's own orders.
 *
 * PII MINIMIZATION. Results carry only what a status answer needs — order number,
 * status, date, total, and an item summary (name + quantity). Raw email / billing /
 * shipping address are deliberately never included. (If a tool ever needs to echo an
 * email, Fahad_AI_Auth::mask_email() exists for that; nothing here does.)
 */
final class Fahad_AI_Order_Tools {

	/**
	 * Append the order tools to the registry's tool list.
	 *
	 * Registered as a pack provider (see the register_pack() call at file scope).
	 * Static because the pack holds no per-instance state — its tools call
	 * WooCommerce order functions and the shared Fahad_AI_Auth boundary directly.
	 *
	 * Both tools carry `'personal' => true` so the registry login-gates them
	 * centrally (the first authorization layer).
	 *
	 * @param array $tools Existing tool definitions.
	 * @return array Tools with the order tools appended.
	 */
	public static function register( array $tools ): array {
		$tools[] = [
			'name'        => 'get_my_orders',
			'description' => 'List the logged-in customer\'s recent orders — each with its order number, status, date, total, and the items on it. Use this when the customer asks about their orders, order history, or "where are my orders?". Only ever returns the signed-in customer\'s OWN orders. Requires the customer to be logged in.',
			'parameters'  => [
				'type'       => 'object',
				'properties' => [
					'limit' => [ 'type' => 'integer', 'description' => 'How many recent orders to return (default 5, max 10).' ],
				],
			],
			'personal'    => true,
			'callback'    => fn( array $input ) => self::get_my_orders( $input ),
		];

		$tools[] = [
			'name'        => 'get_order_status',
			'description' => 'Get the current status (and a tracking number, if the store records one) for ONE of the logged-in customer\'s orders, by its order ID. Use this when the customer asks "where is my order #123?" or about the status of a specific order. Only works for an order that belongs to the signed-in customer; otherwise it reports the order was not found. Requires the customer to be logged in.',
			'parameters'  => [
				'type'       => 'object',
				'properties' => [
					'order_id' => [ 'type' => 'integer', 'description' => 'The ID of the order to look up.' ],
				],
				'required'   => [ 'order_id' ],
			],
			'personal'    => true,
			'callback'    => fn( array $input ) => self::get_order_status( $input ),
		];

		return $tools;
	}

	// -------------------------------------------------------------------------
	// Tool implementations
	// -------------------------------------------------------------------------

	/**
	 * Recent orders for the CURRENT logged-in customer.
	 *
	 * The query is scoped by `customer_id` to Fahad_AI_Auth::current_user_id(), so it
	 * can only ever return that user's own orders — the data-leakage-proof way to
	 * "list my orders" (no per-record ownership check is needed because the database
	 * never hands back anyone else's row). The central login gate has already ensured
	 * the caller is logged in by the time this runs.
	 *
	 * @return array { found:int, orders:array<int,array>, message?:string }
	 */
	private static function get_my_orders( array $input ): array {
		$limit = min( max( 1, (int) ( $input['limit'] ?? 5 ) ), 10 );

		$orders = wc_get_orders( [
			// Scope to the signed-in customer ONLY. This is the boundary that keeps
			// the result strictly the caller's own orders.
			'customer_id' => Fahad_AI_Auth::current_user_id(),
			'limit'       => $limit,
			'orderby'     => 'date',
			'order'       => 'DESC',
		] );

		if ( empty( $orders ) ) {
			return [
				'found'   => 0,
				'orders'  => [],
				'message' => __( 'You have no recent orders.', 'fahad-ai-shopping-assistant-for-woocommerce' ),
			];
		}

		$summaries = array_map( [ self::class, 'summarize_order' ], $orders );

		return [
			'found'  => count( $summaries ),
			'orders' => $summaries,
		];
	}

	/**
	 * Status (+ optional tracking note) for ONE order the caller owns.
	 *
	 * Loads the order, then enforces the SECOND authorization layer:
	 * Fahad_AI_Auth::user_owns( $order->get_customer_id() ). On a missing order OR an
	 * order owned by someone else we return the SAME "not found" error — deliberately
	 * not "forbidden" — so the assistant cannot be used to probe whether an order id
	 * exists for another customer. The central login gate has already ensured the
	 * caller is logged in.
	 *
	 * @return array A status summary, or { error:string } for missing/not-owned.
	 */
	private static function get_order_status( array $input ): array {
		$order_id = absint( $input['order_id'] ?? 0 );

		$not_found = [
			'error' => __( 'Order not found.', 'fahad-ai-shopping-assistant-for-woocommerce' ),
		];

		if ( $order_id <= 0 ) {
			return $not_found;
		}

		$order = wc_get_order( $order_id );

		// Missing order, or — crucially — an order owned by a DIFFERENT user. Both
		// collapse to the same "not found" so ownership is never disclosed.
		if ( ! $order instanceof WC_Order || ! Fahad_AI_Auth::user_owns( $order->get_customer_id() ) ) {
			return $not_found;
		}

		$summary = self::summarize_order( $order );

		// Best-effort tracking note. WooCommerce CORE has no tracking field, so we
		// read a conventional `_tracking_number` meta (set by common shipment-tracking
		// plugins). Only included when genuinely present — never fabricated.
		$tracking = trim( (string) $order->get_meta( '_tracking_number' ) );
		if ( '' !== $tracking ) {
			$summary['tracking_number'] = $tracking;
		}

		return $summary;
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	/**
	 * PII-minimized summary of a single order: number, status, date, total, and an
	 * item summary (name + quantity). Deliberately excludes email and any billing /
	 * shipping address — a status answer never needs them.
	 *
	 * @param WC_Order $order
	 * @return array{ number:string, status:string, date:string, total:string, items:array<int,array{name:string,quantity:int}> }
	 */
	private static function summarize_order( WC_Order $order ): array {
		$date = $order->get_date_created();

		$items = [];
		foreach ( $order->get_items() as $item ) {
			$items[] = [
				'name'     => $item->get_name(),
				'quantity' => (int) $item->get_quantity(),
			];
		}

		return [
			'number' => (string) $order->get_order_number(),
			'status' => $order->get_status(),
			'date'   => $date ? wc_format_datetime( $date, 'Y-m-d' ) : '',
			'total'  => (string) $order->get_total(),
			'items'  => $items,
		];
	}
}

// Self-register this feature pack the moment the file is loaded. The bootstrap
// (and the test bootstrap) glob-require includes/tools/*.php, so dropping this file
// in is the ONLY wiring needed — no bootstrap or harness edits.
// @codeCoverageIgnoreStart
// Reason: file-scope self-registration runs once at bootstrap require time, before PHPUnit's per-test pcov window opens.
Fahad_AI_Tool_Registry::register_pack( [ 'Fahad_AI_Order_Tools', 'register' ] );
// @codeCoverageIgnoreEnd
