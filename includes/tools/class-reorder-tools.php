<?php
defined( 'ABSPATH' ) || exit;

/**
 * Reorder / "buy it again" tools (issue #52) — a fast repurchase path for
 * LOGGED-IN customers only.
 *
 * A drop-in feature pack (same pattern as Fahad_AI_Order_Tools / Fahad_AI_Catalog_Tools):
 * a self-contained class in its own file under includes/tools/ that self-registers a
 * provider at the bottom via Fahad_AI_Tool_Registry::register_pack(). The bootstrap
 * (and the test bootstrap) glob-require everything here, so adding this pack is a
 * SINGLE new file — no edits to the bootstrap, the test bootstrap, or the eval harness.
 *
 * Tools provided:
 *   - get_past_purchases — the distinct products the current customer has previously
 *                          bought, each REVALIDATED against the live catalogue
 *                          (exists, visible, in stock) with its CURRENT price, so the
 *                          assistant only ever offers a "buy again" the store can fulfil.
 *   - reorder            — re-add prior items to the cart, by an `order_id` the
 *                          customer OWNS or an explicit `product_ids` list. Every item
 *                          is revalidated at the moment of reorder; unavailable or
 *                          changed items are REPORTED, never silently dropped.
 *
 * SECURITY IS THE WHOLE POINT — order history is PII and re-adding ANOTHER customer's
 * order is the highest-severity failure. These tools use the issue-#25 authorization
 * boundary (Fahad_AI_Auth) in BOTH of its layers (defence in depth):
 *
 *   1. CENTRAL LOGIN GATE. Both tools declare `'personal' => true`, so
 *      Fahad_AI_Tool_Registry::dispatch() runs Fahad_AI_Auth::guard_logged_in()
 *      BEFORE the callback. A guest is blocked centrally with the standard
 *      login-required error and the callback is never reached — these tools never
 *      re-implement the guest check, so they cannot leak by forgetting it.
 *
 *   2. PER-RECORD OWNERSHIP. get_past_purchases scopes its wc_get_orders() query by
 *      `customer_id` to the current user, so the database can only ever hand back the
 *      caller's own orders (no per-record check is needed). reorder( order_id ) loads
 *      the order and then calls Fahad_AI_Auth::user_owns( $order->get_customer_id() );
 *      a mismatch returns a "not found"-style error (NOT "forbidden"), so we never even
 *      confirm an order exists for another user — and crucially we bail BEFORE touching
 *      the catalogue or the cart, so a foreign order's items can never be added.
 *
 * GUARDRAILS (ROADMAP §1 / #24, absolute). Reorder is a money action, so the data it
 * reports MUST be ground truth: the CURRENT price is read from the live product at
 * reorder time (never the stale order-line price), stock is re-checked, and a chosen
 * variation is verified to still belong to its parent. Nothing is fabricated; an item
 * that cannot be added is reported with a plain reason, not dropped.
 */
final class Fahad_AI_Reorder_Tools {

	/**
	 * How many recent orders to scan for purchase history / the default reorder source.
	 * Bounded so "buy it again" is never an unbounded history scan.
	 */
	private const HISTORY_LIMIT = 20;

	/** Hard ceiling on the history scan even when a larger limit is requested. */
	private const HISTORY_MAX = 50;

	/**
	 * Append the reorder tools to the registry's tool list.
	 *
	 * Registered as a pack provider (see the register_pack() call at file scope).
	 * Static because the pack holds no per-instance state — its tools call WooCommerce
	 * order/product/cart functions and the shared Fahad_AI_Auth boundary directly.
	 *
	 * Both tools carry `'personal' => true` so the registry login-gates them centrally
	 * (the first authorization layer).
	 *
	 * @param array $tools Existing tool definitions.
	 * @return array Tools with the reorder tools appended.
	 */
	public static function register( array $tools ): array {
		$tools[] = [
			'name'        => 'get_past_purchases',
			'description' => 'List the products the logged-in customer has previously purchased and can buy again — each with its product ID, name, and CURRENT price. Only items still available (in stock and purchasable) are returned. Use this when the customer asks to "buy it again", reorder, or see what they bought before. Only ever reflects the signed-in customer\'s OWN order history. Requires the customer to be logged in.',
			'parameters'  => [
				'type'       => 'object',
				'properties' => [
					'limit' => [ 'type' => 'integer', 'description' => 'How many recent orders to draw the purchase history from (default 20, max 50).' ],
				],
			],
			'personal'    => true,
			'callback'    => fn( array $input ) => self::get_past_purchases( $input ),
		];

		$tools[] = [
			'name'        => 'reorder',
			'description' => "Re-add previously purchased items to the logged-in customer's cart. Provide EITHER an order_id (re-adds the items from ONE of the customer's own past orders) OR an explicit product_ids list (e.g. a subset the customer chose from get_past_purchases). Each item is revalidated against the live catalogue at the current price and stock; any item that is out of stock, no longer sold, or otherwise unavailable is REPORTED back, never silently skipped. Only works for an order that belongs to the signed-in customer. Requires the customer to be logged in.",
			'parameters'  => [
				'type'       => 'object',
				'properties' => [
					'order_id'    => [ 'type' => 'integer', 'description' => 'ID of one of the customer\'s own past orders to re-add in full.' ],
					'product_ids' => [
						'type'        => 'array',
						'description' => 'Explicit list of product IDs to re-add (each at quantity 1). Use this for a subset chosen from get_past_purchases.',
						'items'       => [ 'type' => 'integer' ],
					],
				],
			],
			'personal'    => true,
			'callback'    => fn( array $input ) => self::reorder( $input ),
		];

		return $tools;
	}

	// -------------------------------------------------------------------------
	// Tool implementations
	// -------------------------------------------------------------------------

	/**
	 * Distinct, still-available products the CURRENT customer has bought before.
	 *
	 * The wc_get_orders() query is scoped by `customer_id` to
	 * Fahad_AI_Auth::current_user_id(), so it can only ever return that user's own
	 * orders — the data-leakage-proof way to derive purchase history (the database
	 * never hands back anyone else's row). The central login gate has already ensured
	 * the caller is logged in by the time this runs.
	 *
	 * Each candidate is REVALIDATED against the live catalogue (see validate_item):
	 * only products that still exist, are visible and are in stock are offered, each
	 * with its CURRENT price — so the assistant never suggests buying something the
	 * store cannot fulfil, and never quotes a stale price.
	 *
	 * @return array { found:int, products:array<int,array>, message?:string }
	 */
	private static function get_past_purchases( array $input ): array {
		$limit = min( max( 1, (int) ( $input['limit'] ?? self::HISTORY_LIMIT ) ), self::HISTORY_MAX );

		$orders = wc_get_orders( [
			// Scope to the signed-in customer ONLY. This is the boundary that keeps the
			// result strictly the caller's own history.
			'customer_id' => Fahad_AI_Auth::current_user_id(),
			'limit'       => $limit,
			'orderby'     => 'date',
			'order'       => 'DESC',
		] );

		$products = [];
		$seen     = [];

		foreach ( (array) $orders as $order ) {
			if ( ! $order instanceof WC_Order ) {
				continue;
			}

			foreach ( $order->get_items() as $line ) {
				$ref = self::line_to_ref( $line );
				if ( null === $ref ) {
					continue;
				}

				// Dedupe across orders by the product (or variation) actually purchased.
				$key = $ref['product_id'] . ':' . $ref['variation_id'];
				if ( isset( $seen[ $key ] ) ) {
					continue;
				}
				$seen[ $key ] = true;

				$valid = self::validate_item( $ref['product_id'], $ref['variation_id'] );
				if ( null === $valid ) {
					continue; // Gone / hidden / out of stock — not offered for buy-again.
				}

				$products[] = [
					'product_id'   => $valid['product_id'],
					'variation_id' => $valid['variation_id'],
					'name'         => $valid['name'],
					'price'        => $valid['price'],
					'in_stock'     => true,
				];
			}
		}

		if ( empty( $products ) ) {
			return [
				'found'    => 0,
				'products' => [],
				'message'  => __( 'You have no past purchases available to reorder.', 'fahad-ai-shopping-assistant-for-woocommerce' ),
			];
		}

		return [
			'found'    => count( $products ),
			'products' => $products,
		];
	}

	/**
	 * Re-add prior items to the cart, by an owned order_id OR an explicit product_ids
	 * list.
	 *
	 * order_id path: loads the order and enforces the SECOND authorization layer —
	 * Fahad_AI_Auth::user_owns( $order->get_customer_id() ). A missing order OR one
	 * owned by someone else collapses to the SAME "not found" error (never
	 * "forbidden"), and we return BEFORE touching the catalogue or the cart, so a
	 * foreign order's items can never be added. The line items' own quantities and
	 * chosen variations are preserved.
	 *
	 * product_ids path: re-adds the given products (each at quantity 1). These are
	 * public catalogue ids the customer selected, so no per-record ownership applies —
	 * but each is still revalidated like any other reorder item.
	 *
	 * Every item is revalidated at reorder time (validate_item) and added via
	 * WC()->cart->add_to_cart with the variation forwarded. The result reports both
	 * what was `added` (with its CURRENT price) and what was `unavailable` (with a
	 * plain reason) — nothing is silently dropped.
	 *
	 * @return array { added:array, unavailable:array, cart_total?:string, cart_url?:string,
	 *                 checkout_url?:string } | { error:string }
	 */
	private static function reorder( array $input ): array {
		$order_id = absint( $input['order_id'] ?? 0 );

		if ( $order_id > 0 ) {
			$refs = self::refs_from_order( $order_id );
			// refs_from_order returns an error array (not a list) for a missing /
			// not-owned order; surface it unchanged so ownership is never disclosed.
			if ( isset( $refs['error'] ) ) {
				return $refs;
			}
		} else {
			$refs = self::refs_from_product_ids( $input['product_ids'] ?? [] );
		}

		if ( empty( $refs ) ) {
			return [
				'error' => __( 'Tell me which order to reorder, or which items to add.', 'fahad-ai-shopping-assistant-for-woocommerce' ),
			];
		}

		$added       = [];
		$unavailable = [];

		foreach ( $refs as $ref ) {
			self::add_one( $ref, $added, $unavailable );
		}

		$result = [
			'added'       => $added,
			'unavailable' => $unavailable,
		];

		// Only surface cart links once something actually landed in the cart (grounded:
		// no "view your cart" when nothing was added).
		if ( ! empty( $added ) ) {
			$result['cart_total']   = wp_strip_all_tags( WC()->cart->get_cart_total() );
			$result['cart_url']     = wc_get_cart_url();
			$result['checkout_url'] = wc_get_checkout_url();
		}

		return $result;
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	/**
	 * Resolve the reorder item refs from an order the caller OWNS.
	 *
	 * Loads the order, then enforces per-record ownership. A missing order or one owned
	 * by a DIFFERENT user returns the SAME "not found" error (deliberately not
	 * "forbidden") — and we never touch the catalogue/cart for it. On success returns a
	 * list of { product_id, variation_id, quantity } from the order's line items.
	 *
	 * @return array<int,array{product_id:int,variation_id:int,quantity:int}>|array{error:string}
	 */
	private static function refs_from_order( int $order_id ): array {
		$not_found = [
			'error' => __( 'Order not found.', 'fahad-ai-shopping-assistant-for-woocommerce' ),
		];

		$order = wc_get_order( $order_id );

		// Missing order, or — crucially — an order owned by a DIFFERENT user. Both
		// collapse to the same "not found" so ownership is never disclosed, and we bail
		// before touching the catalogue or the cart.
		if ( ! $order instanceof WC_Order || ! Fahad_AI_Auth::user_owns( $order->get_customer_id() ) ) {
			return $not_found;
		}

		$refs = [];
		foreach ( $order->get_items() as $line ) {
			$ref = self::line_to_ref( $line );
			if ( null !== $ref ) {
				$refs[] = $ref;
			}
		}

		return $refs;
	}

	/**
	 * Normalise an explicit product_ids input into reorder item refs (quantity 1 each).
	 * Non-positive / non-scalar ids are dropped.
	 *
	 * @param mixed $product_ids Raw `product_ids` input from the model.
	 * @return array<int,array{product_id:int,variation_id:int,quantity:int}>
	 */
	private static function refs_from_product_ids( $product_ids ): array {
		if ( ! is_array( $product_ids ) ) {
			return [];
		}

		$refs = [];
		foreach ( $product_ids as $id ) {
			$pid = absint( $id );
			if ( $pid > 0 ) {
				$refs[] = [ 'product_id' => $pid, 'variation_id' => 0, 'quantity' => 1 ];
			}
		}

		return $refs;
	}

	/**
	 * Turn an order line item into a reorder ref { product_id, variation_id, quantity },
	 * or null when it carries no usable product id.
	 *
	 * get_items() can return non-product line types (fee/shipping items) that have no
	 * get_product_id(), so we guard with is_callable() — true for a real
	 * WC_Order_Item_Product (and for a test double exposing the getter), false for a
	 * line item that does not carry a product. (is_callable rather than method_exists so
	 * a __call-backed product item is still recognised.)
	 *
	 * @param object $line A WC_Order_Item_Product (its product/variation getters are read).
	 * @return array{product_id:int,variation_id:int,quantity:int}|null
	 */
	private static function line_to_ref( $line ): ?array {
		if ( ! is_object( $line ) || ! is_callable( [ $line, 'get_product_id' ] ) ) {
			return null;
		}

		$product_id = absint( $line->get_product_id() );
		if ( $product_id <= 0 ) {
			return null;
		}

		$variation_id = is_callable( [ $line, 'get_variation_id' ] ) ? absint( $line->get_variation_id() ) : 0;
		$quantity     = is_callable( [ $line, 'get_quantity' ] ) ? max( 1, absint( $line->get_quantity() ) ) : 1;

		return [
			'product_id'   => $product_id,
			'variation_id' => $variation_id,
			'quantity'     => $quantity,
		];
	}

	/**
	 * Revalidate ONE reorder item against the live catalogue.
	 *
	 * Mirrors the built-in add_to_cart guardrails (issue #12): the product must exist
	 * and be visible; when a variation was chosen it must still resolve AND belong to
	 * the same parent; the purchasable unit (variation if any, else the product) must
	 * be in stock. Returns the validated, GROUNDED snapshot (current name + price) or
	 * null with a plain reason in $reason for an unavailable item.
	 *
	 * @param int         $product_id
	 * @param int         $variation_id 0 for a simple product.
	 * @param string|null $reason       Out-param: human reason when the item is unavailable.
	 * @return array{product_id:int,variation_id:int,name:string,price:string}|null
	 */
	private static function validate_item( int $product_id, int $variation_id, ?string &$reason = null ): ?array {
		$product = wc_get_product( $product_id );

		if ( ! $product instanceof WC_Product || ! $product->is_visible() ) {
			$reason = __( 'This product is no longer available.', 'fahad-ai-shopping-assistant-for-woocommerce' );
			return null;
		}

		// The purchasable unit is the variation when one was chosen, otherwise the
		// product itself. Stock and price are read from THAT unit (a sold-out size must
		// be rejected even if the parent reports in stock).
		$item = $product;

		if ( $variation_id > 0 ) {
			$variation = wc_get_product( $variation_id );

			if ( ! $variation instanceof WC_Product || (int) $variation->get_parent_id() !== $product_id ) {
				$reason = __( 'That product option is no longer available.', 'fahad-ai-shopping-assistant-for-woocommerce' );
				return null;
			}

			$item = $variation;
		}

		if ( ! $item->is_in_stock() ) {
			$reason = __( 'This item is currently out of stock.', 'fahad-ai-shopping-assistant-for-woocommerce' );
			return null;
		}

		return [
			'product_id'   => $product_id,
			'variation_id' => $variation_id,
			'name'         => $item->get_name(),
			// CURRENT price from the live product — never the stale order-line price.
			'price'        => self::plain_price( $item->get_price() ),
		];
	}

	/**
	 * Validate one ref and either add it to the cart (recording it in $added with its
	 * CURRENT price) or record it in $unavailable with a plain reason. Never throws and
	 * never silently drops — every ref lands in exactly one of the two buckets.
	 *
	 * @param array{product_id:int,variation_id:int,quantity:int} $ref
	 * @param array $added       Accumulator for successfully added items (by reference).
	 * @param array $unavailable Accumulator for unavailable items (by reference).
	 */
	private static function add_one( array $ref, array &$added, array &$unavailable ): void {
		$reason = null;
		$valid  = self::validate_item( $ref['product_id'], $ref['variation_id'], $reason );

		if ( null === $valid ) {
			$unavailable[] = [
				'product_id'   => $ref['product_id'],
				'variation_id' => $ref['variation_id'],
				'reason'       => $reason ?? __( 'This item is no longer available.', 'fahad-ai-shopping-assistant-for-woocommerce' ),
			];
			return;
		}

		$quantity      = max( 1, (int) ( $ref['quantity'] ?? 1 ) );
		$cart_item_key = WC()->cart->add_to_cart( $ref['product_id'], $quantity, $ref['variation_id'] );

		if ( ! $cart_item_key ) {
			// Revalidation passed but the cart still refused it (a cart-level rule, a
			// product needing a fuller variation selection, etc.). Report it — do not
			// claim it was added.
			$unavailable[] = [
				'product_id'   => $ref['product_id'],
				'variation_id' => $ref['variation_id'],
				'reason'       => __( 'This item could not be added to your cart.', 'fahad-ai-shopping-assistant-for-woocommerce' ),
			];
			return;
		}

		$added[] = [
			'product_id'    => $valid['product_id'],
			'variation_id'  => $valid['variation_id'],
			'name'          => $valid['name'],
			'quantity'      => $quantity,
			'price'         => $valid['price'],
			'cart_item_key' => $cart_item_key,
		];
	}

	/**
	 * Format a raw price into a plain display string (currency symbol, no HTML), the
	 * same shape the built-in product tools surface — wc_price() returns markup with
	 * HTML entities; the AI and the widget cards both want a clean string.
	 *
	 * @param mixed $price
	 */
	private static function plain_price( $price ): string {
		if ( '' === $price || null === $price ) {
			return '';
		}
		return trim( html_entity_decode( wp_strip_all_tags( wc_price( $price ) ), ENT_QUOTES, 'UTF-8' ) );
	}
}

// Self-register this feature pack the moment the file is loaded. The bootstrap (and
// the test bootstrap) glob-require includes/tools/*.php, so dropping this file in is
// the ONLY wiring needed — no bootstrap or harness edits.
Fahad_AI_Tool_Registry::register_pack( [ 'Fahad_AI_Reorder_Tools', 'register' ] );
