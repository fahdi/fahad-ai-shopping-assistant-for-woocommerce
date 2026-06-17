<?php
defined( 'ABSPATH' ) || exit;

/**
 * Conversational checkout assist (issue #55).
 *
 * Removes the "what's in my order / which shipping / is there a better deal?"
 * blockers right before purchase by letting the agent ground its answers in the
 * REAL session cart, shipping options and coupon savings — then hand the shopper
 * off to the WooCommerce checkout. A drop-in feature pack following the reference
 * pattern in Fahad_AI_Catalog_Tools / Fahad_AI_Shipping_Tools: a self-contained
 * class in its own file under includes/tools/ that self-registers a provider at
 * file scope via Fahad_AI_Tool_Registry::register_pack() — no bootstrap,
 * test-bootstrap, or harness edits.
 *
 * Tools provided (all operate on the SHARED session cart, so NONE is personal /
 * login-gated — a guest mid-checkout must get grounded answers):
 *   - get_checkout_summary — echo the real cart (items, subtotal, discount,
 *     total, applied coupon), the destination's real shipping methods + which is
 *     chosen, and the checkout handoff URL. Empty cart → an honest empty state
 *     with no fabricated items/total. No invented numbers, ever.
 *   - set_shipping_method  — select a shipping method WooCommerce genuinely
 *     OFFERS for this cart/destination and recalculate the total. A method id the
 *     store doesn't offer is rejected (the model can't mis-state shipping).
 *   - apply_best_coupon    — find the genuinely best (largest real saving) valid +
 *     applicable coupon and, WITHOUT consent, RECOMMEND it; WITH `confirm` apply
 *     it. Surfaces a WooCommerce rejection honestly, never invents a code, and
 *     respects a stated budget by surfacing the projected total.
 *
 * PCI BOUNDARY (absolute): the conversational loop ENDS at the WooCommerce
 * checkout handoff — a URL from wc_get_checkout_url(), nothing more. No tool here
 * accepts, returns, stores, or even names card / payment data. Payment is handled
 * by WooCommerce's own (PCI-scoped) checkout, never by the assistant.
 *
 * HONESTY: every figure is read from the live cart / shipping stack / WooCommerce's
 * own coupon application; we never re-implement WC's restriction logic and never
 * fabricate a total, a method, or a code.
 *
 * TESTABILITY: like the shipping pack, EVERY WooCommerce cart / shipping / coupon
 * touch is isolated behind a small set of overridable `protected static` seams
 * (read_cart, resolve_shipping, select_shipping_method, candidate_coupons,
 * apply_coupon_code). Those WC surfaces — WC()->cart, WC()->session,
 * WC()->shipping(), WC_Shipping_Zones, WC_Discounts — are concrete classes /
 * singletons that Brain\Monkey (a FUNCTION mocker) cannot intercept, so the unit
 * suite drives a tiny subclass (Fahad_AI_Checkout_Tools_Stub) that overrides the
 * seams with canned data. Everything else is pure array shaping / decision logic
 * over those normalized snapshots.
 *
 * NOTE on `final`: unlike most packs this class is intentionally NOT marked
 * `final`, SOLELY so the test suite can subclass it to override the seams. No
 * production code subclasses it.
 */
class Fahad_AI_Checkout_Tools {

	/**
	 * Append the checkout-assist tools to the registry's tool list.
	 *
	 * Registered as a pack provider (see the register_pack() call at file scope).
	 * Static because the pack holds no per-instance state — its tools query the
	 * shared session cart / shipping / coupons directly.
	 *
	 * The callbacks capture `static::class` (the late-static / called class) and
	 * route every seam call through it, so a test subclass that overrides the
	 * seams is honoured — inside an arrow-fn `static::` would instead resolve to
	 * the DEFINING (parent) class, hence the explicit capture.
	 *
	 * @param array $tools Existing tool definitions.
	 * @return array Tools with the checkout tools appended.
	 */
	public static function register( array $tools ): array {
		$class = static::class;

		$tools[] = [
			'name'        => 'get_checkout_summary',
			'description' => 'Summarise the order the customer is about to place: the real cart items, subtotal, any discount and the current total, the shipping methods available for their destination and which one is selected, and a link to the secure checkout. Use this when the customer asks "what\'s in my order", "what\'s my total", "how do I check out", or wants to review before paying. Every number comes from the live cart — never invent items, totals or shipping. Payment itself happens on the WooCommerce checkout page (this tool only returns its URL); never ask for card details.',
			'parameters'  => [
				'type'       => 'object',
				'properties' => new stdClass(),
			],
			'callback'    => static fn( array $input ) => self::get_checkout_summary( $input, $class ),
		];

		$tools[] = [
			'name'        => 'set_shipping_method',
			'description' => 'Choose a shipping method for the current cart and recalculate the total. Pass the method_id of a method WooCommerce actually offers for this destination (get_checkout_summary lists them). A method that is not offered is rejected — do not select one the store does not list. Returns the recalculated total so you can state a grounded figure. This never touches payment or card data.',
			'parameters'  => [
				'type'       => 'object',
				'properties' => [
					'method_id' => [ 'type' => 'string', 'description' => 'The id of a shipping method WooCommerce offers for this cart/destination, e.g. "flat_rate:1" or "free_shipping:2". Must be one of the methods returned by get_checkout_summary.' ],
				],
				'required'   => [ 'method_id' ],
			],
			'callback'    => static fn( array $input ) => self::set_shipping_method( $input, $class ),
		];

		$tools[] = [
			'name'        => 'apply_best_coupon',
			'description' => 'Find the single best discount code for the current cart (the one giving the largest REAL saving among codes that are valid and applicable right now) and either recommend it or apply it. WITHOUT confirm the tool only RECOMMENDS the code and asks the customer if they want it applied — it does NOT change the cart. WITH confirm:true it applies the code, reporting an honest error if WooCommerce rejects it. If no valid code applies, it says so and never invents one. Optionally pass the customer\'s budget to surface the projected total. Never claim a discount this tool did not actually apply.',
			'parameters'  => [
				'type'       => 'object',
				'properties' => [
					'confirm' => [ 'type' => 'boolean', 'description' => 'Set true ONLY after the customer has agreed to apply the recommended code. Omitted/false → recommend only, the cart is not changed.' ],
					'budget'  => [ 'type' => 'number',  'description' => 'Optional spend ceiling the customer stated; the projected total after the best saving is returned so you can reason about it.' ],
				],
			],
			'callback'    => static fn( array $input ) => self::apply_best_coupon( $input, $class ),
		];

		return $tools;
	}

	// -------------------------------------------------------------------------
	// Tool implementations (pure shaping / decisions over the seam snapshots)
	// -------------------------------------------------------------------------

	/**
	 * Grounded checkout summary: the real cart, shipping and total, plus the
	 * checkout handoff URL.
	 *
	 * Returns one of:
	 *   - empty cart : { empty:true, message, checkout_url, cart_url } — NO items,
	 *                  NO total (nothing to total, so nothing is fabricated).
	 *   - otherwise  : { empty:false, items[], subtotal, discount_total, total,
	 *                    applied_coupon|null, shipping{ needed, methods[],
	 *                    chosen_method }, checkout_url, cart_url }.
	 *
	 * @param array  $input Unused (no parameters).
	 * @param string $class The class register() was called on; seams route through
	 *                      it so a test override is honoured.
	 * @return array
	 */
	private static function get_checkout_summary( array $input, string $class = self::class ): array {
		$cart = (array) call_user_func( [ $class, 'read_cart' ] );

		// Nothing in the cart → an honest empty state. No items, no total: the
		// agent must not state a number for an empty order.
		if ( ! empty( $cart['empty'] ) ) {
			return [
				'empty'        => true,
				'message'      => __( 'Your cart is empty, so there is nothing to check out yet. Add something and I can summarise your order.', 'fahad-ai-shopping-assistant-for-woocommerce' ),
				'checkout_url' => self::checkout_url(),
				'cart_url'     => self::cart_url(),
			];
		}

		$result = [
			'empty'          => false,
			'items'          => self::shape_items( $cart['items'] ?? [] ),
			'subtotal'       => (string) ( $cart['subtotal'] ?? '' ),
			'discount_total' => (string) ( $cart['discount_total'] ?? '0' ),
			'total'          => (string) ( $cart['total'] ?? '' ),
			// No coupon applied → an explicit null, never a fabricated code.
			'applied_coupon' => isset( $cart['applied_coupon'] ) && '' !== (string) $cart['applied_coupon']
				? (string) $cart['applied_coupon']
				: null,
			'shipping'       => self::shape_shipping( call_user_func( [ $class, 'resolve_shipping' ] ) ),
			'checkout_url'   => self::checkout_url(),
			'cart_url'       => self::cart_url(),
		];

		return $result;
	}

	/**
	 * Select a shipping method WooCommerce genuinely offers, then recalculate.
	 *
	 * Guards: a method_id is required; it must be one of the methods the resolved
	 * shipping snapshot OFFERS (the model can't pick a method the store doesn't
	 * list for this destination, which would mis-state shipping). Only then is the
	 * select seam invoked; the recalculated total is read back from a fresh cart
	 * snapshot so the agent can state a grounded number.
	 *
	 * @param array  $input { method_id: string }
	 * @param string $class The class register() was called on.
	 * @return array { success:bool, chosen_method?, total?, methods?, error? }
	 */
	private static function set_shipping_method( array $input, string $class = self::class ): array {
		$method_id = isset( $input['method_id'] ) ? sanitize_text_field( (string) $input['method_id'] ) : '';

		if ( '' === $method_id ) {
			return [
				'success' => false,
				'error'   => __( 'Please tell me which shipping method to use. I can list the available options from your order summary.', 'fahad-ai-shopping-assistant-for-woocommerce' ),
			];
		}

		$shipping = self::shape_shipping( call_user_func( [ $class, 'resolve_shipping' ] ) );

		if ( ! self::method_is_offered( $method_id, $shipping['methods'] ) ) {
			return [
				'success' => false,
				'error'   => sprintf(
					/* translators: %s: the shipping method id the model tried to select */
					__( '"%s" is not a shipping method available for this order. Please choose one of the methods offered for your destination.', 'fahad-ai-shopping-assistant-for-woocommerce' ),
					$method_id
				),
				'methods' => $shipping['methods'],
			];
		}

		$ok = (bool) call_user_func( [ $class, 'select_shipping_method' ], $method_id );

		if ( ! $ok ) {
			return [
				'success' => false,
				'error'   => __( 'I could not set that shipping method just now. Please try again.', 'fahad-ai-shopping-assistant-for-woocommerce' ),
			];
		}

		// Read the cart back AFTER selection so the total reflects the new method.
		$cart = (array) call_user_func( [ $class, 'read_cart' ] );

		return [
			'success'       => true,
			'chosen_method' => $method_id,
			'total'         => (string) ( $cart['total'] ?? '' ),
			'checkout_url'  => self::checkout_url(),
		];
	}

	/**
	 * Recommend (or, with consent, apply) the genuinely best coupon for the cart.
	 *
	 * Flow: gather valid+applicable candidates (the seam) → pick the largest real
	 * saving → if none, say so honestly (never invent a code, never touch the
	 * cart) → WITHOUT consent return a recommendation that ASKS to apply → WITH
	 * `confirm` apply it, surfacing a WooCommerce rejection as an honest error. A
	 * stated `budget` adds a projected-total figure so the agent can reason about
	 * the spend ceiling — it never hides a real saving.
	 *
	 * @param array  $input { confirm?: bool, budget?: number }
	 * @param string $class The class register() was called on.
	 * @return array
	 */
	private static function apply_best_coupon( array $input, string $class = self::class ): array {
		$confirm    = ! empty( $input['confirm'] );
		$has_budget = isset( $input['budget'] ) && is_numeric( $input['budget'] );
		$budget     = $has_budget ? (float) $input['budget'] : null;

		$candidates = (array) call_user_func( [ $class, 'candidate_coupons' ] );
		$best       = self::best_candidate( $candidates );

		// No genuinely valid + applicable code → honest "none", no invented code,
		// cart untouched even WITH consent.
		if ( null === $best ) {
			return [
				'applied' => false,
				'message' => __( 'I couldn\'t find a valid discount code that applies to your cart right now, so there\'s no coupon to add.', 'fahad-ai-shopping-assistant-for-woocommerce' ),
			];
		}

		$cart           = (array) call_user_func( [ $class, 'read_cart' ] );
		$saving         = (float) ( $best['saving'] ?? 0 );
		$current_total  = (float) ( $cart['total'] ?? 0 );
		$projected      = max( 0.0, $current_total - $saving );

		$base = [
			'code'        => (string) $best['code'],
			'saving'      => $saving,
			'description' => isset( $best['description'] ) ? (string) $best['description'] : '',
		];

		// Budget is a ceiling on spend, not a reason to hide a real saving: always
		// surface the projected total so the agent can reason about it.
		if ( null !== $budget ) {
			$base['budget']          = $budget;
			$base['projected_total'] = $projected;
			$base['within_budget']   = $projected <= $budget;
		}

		// No consent → RECOMMEND only. The cart is NOT touched (apply seam never
		// runs); the message asks for confirmation rather than claiming a discount.
		if ( ! $confirm ) {
			return array_merge( $base, [
				'applied'     => false,
				'recommended' => true,
				'message'     => sprintf(
					/* translators: 1: coupon code, 2: formatted saving amount */
					__( 'The best code I found is %1$s, which would save about %2$s. Want me to apply it?', 'fahad-ai-shopping-assistant-for-woocommerce' ),
					$base['code'],
					self::money( (string) $saving )
				),
			] );
		}

		// Consent given → apply via WooCommerce, which validates again and returns
		// true only on success. A false result is an honest error, never a faked win.
		$ok = (bool) call_user_func( [ $class, 'apply_coupon_code' ], $base['code'] );

		if ( ! $ok ) {
			return array_merge( $base, [
				'applied' => false,
				'error'   => sprintf(
					/* translators: %s: the coupon code that WooCommerce rejected at apply time */
					__( 'I tried to apply %s but the store rejected it — it may have just expired or stopped being applicable. No discount was added.', 'fahad-ai-shopping-assistant-for-woocommerce' ),
					$base['code']
				),
			] );
		}

		// Re-read the cart so the reported total reflects the applied discount.
		$after = (array) call_user_func( [ $class, 'read_cart' ] );

		return array_merge( $base, [
			'applied' => true,
			'message' => sprintf(
				/* translators: 1: coupon code, 2: formatted saving amount */
				__( 'Applied %1$s — you saved about %2$s.', 'fahad-ai-shopping-assistant-for-woocommerce' ),
				$base['code'],
				self::money( (string) $saving )
			),
			'total'   => (string) ( $after['total'] ?? $cart['total'] ?? '' ),
		] );
	}

	// -------------------------------------------------------------------------
	// Pure helpers (shaping + decisions; no WooCommerce contact)
	// -------------------------------------------------------------------------

	/**
	 * Normalise cart items to the result shape, echoing only real fields.
	 *
	 * @param mixed $items List of { name, quantity, line_total } from the snapshot.
	 * @return array<int, array{name:string, quantity:int, line_total:string}>
	 */
	private static function shape_items( $items ): array {
		if ( ! is_array( $items ) ) {
			return [];
		}

		$shaped = [];
		foreach ( $items as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}
			$shaped[] = [
				'name'       => (string) ( $item['name'] ?? '' ),
				'quantity'   => (int) ( $item['quantity'] ?? 0 ),
				'line_total' => (string) ( $item['line_total'] ?? '' ),
			];
		}
		return $shaped;
	}

	/**
	 * Normalise the resolved-shipping snapshot to the result shape, never
	 * inventing methods. A null snapshot (no shipping context) and a
	 * shipping-not-needed snapshot both collapse to the honest no-methods shape.
	 *
	 * @param mixed $shipping { needed, chosen_method, methods[] } or null.
	 * @return array{needed:bool, methods:array<int,array>, chosen_method:?string}
	 */
	private static function shape_shipping( $shipping ): array {
		if ( ! is_array( $shipping ) || empty( $shipping['needed'] ) ) {
			return [
				'needed'        => false,
				'methods'       => [],
				'chosen_method' => null,
			];
		}

		$methods = [];
		foreach ( (array) ( $shipping['methods'] ?? [] ) as $m ) {
			if ( ! is_array( $m ) ) {
				continue;
			}
			$methods[] = [
				'id'    => (string) ( $m['id'] ?? '' ),
				'label' => (string) ( $m['label'] ?? '' ),
				'cost'  => (string) ( $m['cost'] ?? '' ),
			];
		}

		$chosen = isset( $shipping['chosen_method'] ) && '' !== (string) $shipping['chosen_method']
			? (string) $shipping['chosen_method']
			: null;

		return [
			'needed'        => true,
			'methods'       => $methods,
			'chosen_method' => $chosen,
		];
	}

	/**
	 * Is $method_id among the offered shipping methods? Guards the model from
	 * selecting a method WooCommerce doesn't list for this destination.
	 *
	 * @param string             $method_id The requested method id.
	 * @param array<int, array>  $methods   Offered method descriptors.
	 */
	private static function method_is_offered( string $method_id, array $methods ): bool {
		foreach ( $methods as $m ) {
			if ( is_array( $m ) && (string) ( $m['id'] ?? '' ) === $method_id ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Pick the candidate coupon with the largest real saving; null for an empty
	 * list. The single source of "best" so the recommend + apply paths agree.
	 *
	 * @param array<int, array> $candidates Each { code, saving, description? }.
	 * @return array|null The best candidate, or null when there are none.
	 */
	private static function best_candidate( array $candidates ): ?array {
		$best = null;
		foreach ( $candidates as $candidate ) {
			if ( ! is_array( $candidate ) || ! isset( $candidate['code'] ) ) {
				continue;
			}
			if ( null === $best || (float) ( $candidate['saving'] ?? 0 ) > (float) ( $best['saving'] ?? 0 ) ) {
				$best = $candidate;
			}
		}
		return $best;
	}

	/**
	 * Format a raw amount as a plain money string (currency symbol, no HTML),
	 * trimming a trailing ".00" so "$10.00" reads as "$10". Mirrors the plain,
	 * card-friendly price style the product / coupon tools use.
	 *
	 * @param mixed $amount Raw numeric amount.
	 */
	private static function money( $amount ): string {
		$value  = (float) wc_format_decimal( $amount );
		$symbol = function_exists( 'get_woocommerce_currency_symbol' ) ? get_woocommerce_currency_symbol() : '';
		$symbol = html_entity_decode( (string) $symbol, ENT_QUOTES, 'UTF-8' );

		$number = ( floor( $value ) === $value )
			? number_format( $value, 0 )
			: number_format( $value, 2 );

		return $symbol . $number;
	}

	/** The secure checkout handoff URL — the PCI boundary stops here. */
	private static function checkout_url(): string {
		return function_exists( 'wc_get_checkout_url' ) ? (string) wc_get_checkout_url() : '';
	}

	/** The cart URL, for a "review your cart" fallback link. */
	private static function cart_url(): string {
		return function_exists( 'wc_get_cart_url' ) ? (string) wc_get_cart_url() : '';
	}

	// -------------------------------------------------------------------------
	// WooCommerce SEAMS (the only WC-touching methods — overridable in tests)
	// -------------------------------------------------------------------------
	//
	// These five `protected static` methods are the SINGLE point of contact with
	// the WooCommerce cart / shipping / coupon stack. Everything above is pure, so
	// the unit suite overrides these with canned data (no live WC required). The
	// class is intentionally NOT `final` SOLELY to permit that override; no
	// production code subclasses it.

	/**
	 * Snapshot the live session cart, normalised to a plain array the pure code
	 * shapes. An empty / unavailable cart yields [ 'empty' => true ] so the
	 * summary returns an honest empty state.
	 *
	 * @return array { empty, items[], subtotal, discount_total, total, applied_coupon, currency_symbol }
	 */
	protected static function read_cart(): array {
		$cart = self::cart();
		if ( ! $cart || $cart->is_empty() ) {
			return [ 'empty' => true ];
		}

		$items = [];
		foreach ( $cart->get_cart() as $cart_item ) {
			$product = is_array( $cart_item ) && isset( $cart_item['data'] ) ? $cart_item['data'] : null;
			$name    = ( $product && method_exists( $product, 'get_name' ) ) ? (string) $product->get_name() : '';
			$qty     = is_array( $cart_item ) ? (int) ( $cart_item['quantity'] ?? 0 ) : 0;
			$line    = is_array( $cart_item ) ? (string) ( $cart_item['line_total'] ?? '' ) : '';
			$items[] = [
				'name'       => $name,
				'quantity'   => $qty,
				'line_total' => $line,
			];
		}

		$applied = method_exists( $cart, 'get_applied_coupons' ) ? (array) $cart->get_applied_coupons() : [];

		return [
			'empty'           => false,
			'items'           => $items,
			'subtotal'        => wp_strip_all_tags( (string) $cart->get_cart_subtotal() ),
			'discount_total'  => method_exists( $cart, 'get_discount_total' ) ? (string) $cart->get_discount_total() : '0',
			'total'           => wp_strip_all_tags( (string) $cart->get_cart_total() ),
			'applied_coupon'  => $applied[0] ?? null,
			'currency_symbol' => function_exists( 'get_woocommerce_currency_symbol' ) ? get_woocommerce_currency_symbol() : '',
		];
	}

	/**
	 * Resolve the shipping context for the current cart: whether shipping is
	 * needed, the methods WooCommerce offers for the destination, and which is
	 * chosen. Returns null when no cart/shipping context exists; a not-needed
	 * cart (e.g. all-virtual) yields needed=false with no methods — the caller
	 * never invents a method or cost.
	 *
	 * @return array{needed:bool, chosen_method:?string, methods:array<int,array>}|null
	 */
	protected static function resolve_shipping(): ?array {
		$cart = self::cart();
		if ( ! $cart ) {
			return null;
		}

		// All-virtual / no-shipping carts: nothing to offer, nothing to invent.
		if ( method_exists( $cart, 'needs_shipping' ) && ! $cart->needs_shipping() ) {
			return [ 'needed' => false, 'chosen_method' => null, 'methods' => [] ];
		}

		if ( ! function_exists( 'WC' ) || ! WC()->shipping() ) {
			return [ 'needed' => true, 'chosen_method' => null, 'methods' => [] ];
		}

		if ( method_exists( $cart, 'calculate_shipping' ) ) {
			$cart->calculate_shipping();
		}

		$chosen_methods = (array) ( WC()->session ? WC()->session->get( 'chosen_shipping_methods', [] ) : [] );
		$chosen         = $chosen_methods[0] ?? null;

		$methods  = [];
		$packages = WC()->shipping()->get_packages();
		foreach ( $packages as $package ) {
			$rates = isset( $package['rates'] ) && is_array( $package['rates'] ) ? $package['rates'] : [];
			foreach ( $rates as $rate_id => $rate ) {
				$methods[] = [
					'id'    => (string) $rate_id,
					'label' => is_object( $rate ) && method_exists( $rate, 'get_label' ) ? (string) $rate->get_label() : '',
					'cost'  => is_object( $rate ) && method_exists( $rate, 'get_cost' ) ? (string) $rate->get_cost() : '',
				];
			}
		}

		return [
			'needed'        => true,
			'chosen_method' => $chosen,
			'methods'       => $methods,
		];
	}

	/**
	 * Set the chosen shipping method on the session and recalculate totals.
	 *
	 * Mirrors WooCommerce's own chosen-method storage (the
	 * `chosen_shipping_methods` session array) and persists the session so the
	 * change survives the REST request — the same cart-session handling the cart
	 * paths use (live-QA finding #31), since this endpoint can't rely on the
	 * shutdown hook firing.
	 *
	 * @param string $method_id The (already validated as offered) method id.
	 */
	protected static function select_shipping_method( string $method_id ): bool {
		if ( ! function_exists( 'WC' ) || ! WC()->session ) {
			return false;
		}

		$chosen    = (array) WC()->session->get( 'chosen_shipping_methods', [] );
		$chosen[0] = $method_id;
		WC()->session->set( 'chosen_shipping_methods', $chosen );

		$cart = self::cart();
		if ( $cart ) {
			if ( method_exists( $cart, 'calculate_shipping' ) ) {
				$cart->calculate_shipping();
			}
			if ( method_exists( $cart, 'calculate_totals' ) ) {
				$cart->calculate_totals();
			}
		}

		self::persist_session();

		return true;
	}

	/**
	 * The coupons that are genuinely valid + applicable to the current cart, each
	 * normalised to { code, saving, description }. The `saving` is WooCommerce's
	 * OWN computed discount for the cart (via WC_Discounts), so "best" reflects a
	 * real number — we never re-implement restriction/saving logic.
	 *
	 * @return array<int, array{code:string, saving:float, description:string}>
	 */
	protected static function candidate_coupons(): array {
		$cart = self::cart();
		if ( ! $cart || $cart->is_empty() || ! class_exists( 'WC_Discounts' ) || ! function_exists( 'get_posts' ) ) {
			return [];
		}

		$posts = get_posts( [
			'post_type'      => 'shop_coupon',
			'post_status'    => 'publish',
			'posts_per_page' => 100,
			'orderby'        => 'date',
			'order'          => 'DESC',
		] );

		if ( empty( $posts ) || ! is_array( $posts ) ) {
			return [];
		}

		$already     = method_exists( $cart, 'get_applied_coupons' ) ? array_map( 'strtolower', (array) $cart->get_applied_coupons() ) : [];
		$candidates  = [];

		foreach ( $posts as $post ) {
			$code   = is_object( $post ) && isset( $post->post_title ) ? (string) $post->post_title : (string) $post;
			$coupon = new WC_Coupon( $code );

			if ( $coupon->get_id() <= 0 || in_array( strtolower( $coupon->get_code() ), $already, true ) ) {
				continue;
			}

			// Defer to WooCommerce's authoritative validator for applicability.
			$discounts = new WC_Discounts( $cart );
			$valid     = $discounts->is_coupon_valid( $coupon );
			if ( is_wp_error( $valid ) ) {
				continue;
			}

			// WooCommerce's OWN computed saving for this coupon against the cart.
			$discounts->apply_coupon( $coupon );
			$saving = (float) $discounts->get_discount( $coupon->get_code(), true );
			if ( $saving <= 0 ) {
				continue;
			}

			$candidates[] = [
				'code'        => $coupon->get_code(),
				'saving'      => $saving,
				'description' => wp_strip_all_tags( (string) $coupon->get_description() ),
			];
		}

		return $candidates;
	}

	/**
	 * Apply a coupon to the live session cart, deferring entirely to WooCommerce
	 * (it validates again and returns true only on success), then persist the
	 * session so the discount survives the REST request (#31).
	 *
	 * @param string $code The coupon code to apply.
	 */
	protected static function apply_coupon_code( string $code ): bool {
		$cart = self::cart();
		if ( ! $cart ) {
			return false;
		}

		$applied = (bool) $cart->apply_coupon( $code );
		if ( $applied ) {
			self::persist_session();
		}

		return $applied;
	}

	// -------------------------------------------------------------------------
	// Session plumbing (shared by the mutating seams)
	// -------------------------------------------------------------------------

	/**
	 * The current session cart, or null when unavailable. The API handler calls
	 * wc_load_cart() before tools run, so WC()->cart is normally present; we still
	 * load it defensively and guard a missing cart so the tools degrade cleanly
	 * rather than fatal.
	 *
	 * @return WC_Cart|null
	 */
	private static function cart() {
		if ( function_exists( 'wc_load_cart' ) && ( ! function_exists( 'WC' ) || ! WC()->cart ) ) {
			wc_load_cart();
		}
		if ( ! function_exists( 'WC' ) ) {
			return null;
		}
		$wc = WC();
		return ( $wc && isset( $wc->cart ) && $wc->cart instanceof WC_Cart ) ? $wc->cart : null;
	}

	/**
	 * Persist the session immediately so a cart mutation survives this REST
	 * request — mirrors the cart-session handling in the cart paths (#31); we
	 * don't rely on the shutdown hook firing for a REST context.
	 */
	private static function persist_session(): void {
		if ( function_exists( 'WC' ) && WC()->session && method_exists( WC()->session, 'save_data' ) ) {
			WC()->session->save_data();
		}
	}
}

// Self-register this feature pack the moment the file is loaded. The bootstrap
// (and the test bootstrap) glob-require includes/tools/*.php, so dropping this
// file in is the ONLY wiring needed — no bootstrap or harness edits.
Fahad_AI_Tool_Registry::register_pack( [ 'Fahad_AI_Checkout_Tools', 'register' ] );
