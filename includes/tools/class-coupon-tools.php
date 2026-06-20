<?php
defined( 'ABSPATH' ) || exit;

/**
 * Coupons & deals tools (issue #14).
 *
 * A drop-in feature pack (same pattern as Fahad_AI_Catalog_Tools): a self-contained
 * class in its own file under includes/tools/ that self-registers a provider at the
 * bottom via Fahad_AI_Tool_Registry::register_pack(). The bootstrap (and the test
 * bootstrap) glob-require everything here, so adding this pack is a SINGLE new file
 * — no edits to the bootstrap, the test bootstrap, or the eval harness.
 *
 * Tools provided:
 *   - list_active_coupons — store coupons that are genuinely usable RIGHT NOW.
 *   - apply_coupon        — apply a real code to the session cart, honestly.
 *
 * HONESTY IS THE WHOLE POINT. The assistant must never invent a discount code or
 * claim a coupon works when it does not:
 *
 *   • list_active_coupons returns ONLY codes that exist in the store (the
 *     `shop_coupon` post type) AND pass every validity gate below. The model is
 *     handed real codes and told (via the tool description) to quote only those.
 *   • A coupon is included only if it is published, not expired (`date_expires`),
 *     under its total usage limit (`usage_limit` vs `usage_count`), under the
 *     current user's per-user limit where that is determinable (logged in, with a
 *     `usage_limit_per_user`), AND — when a non-empty session cart is present so
 *     applicability is determinable — accepted by WooCommerce's OWN validator
 *     (WC_Discounts::is_coupon_valid), which enforces product/category/min-spend
 *     restrictions against the actual cart. We never re-implement WC's restriction
 *     logic; we defer to it.
 *   • apply_coupon defers entirely to WC()->cart->apply_coupon(): success is
 *     reported only when WooCommerce itself accepts the code. A false/!valid result
 *     becomes a plain error — never a fabricated success.
 *
 * These tools are NOT personal-data tools: they operate on the SHARED session cart
 * and the store-wide coupon list, so they carry no `personal` flag and are not
 * login-gated. (The per-user usage-limit check below is a best-effort refinement
 * for a logged-in shopper, not an authorization boundary.)
 */
final class Fahad_AI_Coupon_Tools {

	/**
	 * Append the coupon tools to the registry's tool list.
	 *
	 * Registered as a pack provider (see the register_pack() call at file scope).
	 * Static because the pack holds no per-instance state — its tools call
	 * WooCommerce and the shared session cart directly.
	 *
	 * @param array $tools Existing tool definitions.
	 * @return array Tools with the coupon tools appended.
	 */
	public static function register( array $tools ): array {
		$tools[] = [
			'name'        => 'list_active_coupons',
			'description' => 'List the discount codes that are currently valid and usable in this store right now. Only returns REAL coupons that exist in the store and pass every check (published, not expired, within usage limits, and — when there is a cart — applicable to it). Use this when the customer asks about discounts, coupons, promo codes, or deals. NEVER make up a code: only ever mention codes returned by this tool.',
			'parameters'  => [
				'type'       => 'object',
				'properties' => new stdClass(),
			],
			'callback'    => fn( array $input ) => self::list_active_coupons( $input ),
		];

		$tools[] = [
			'name'        => 'apply_coupon',
			'description' => "Apply a discount code to the customer's cart. Pass the exact code (ideally one returned by list_active_coupons). Returns the updated cart total on success, or a clear error if WooCommerce rejects the code as invalid or inapplicable. Only report success if this tool returns success — never claim a code worked otherwise.",
			'parameters'  => [
				'type'       => 'object',
				'properties' => [
					'code' => [ 'type' => 'string', 'description' => 'The coupon / discount code to apply.' ],
				],
				'required'   => [ 'code' ],
			],
			'callback'    => fn( array $input ) => self::apply_coupon( $input ),
		];

		return $tools;
	}

	// -------------------------------------------------------------------------
	// Tool implementations
	// -------------------------------------------------------------------------

	/**
	 * Coupons that are valid and usable now.
	 *
	 * Enumerates published `shop_coupon` posts, loads each as a WC_Coupon, keeps
	 * only the ones that pass every validity gate (see is_coupon_currently_valid),
	 * and returns a coupon LIST — deliberately NOT a products[] array — so it does
	 * not render as product cards.
	 *
	 * @param array $input Unused (no parameters).
	 * @return array { found, coupons[], message? }
	 */
	private static function list_active_coupons( array $input ): array {
		$cart    = self::cart();
		$coupons = [];

		foreach ( self::get_coupon_objects() as $coupon ) {
			if ( ! $coupon instanceof WC_Coupon ) {
				// @codeCoverageIgnoreStart
				// Reason: defensive guard with no injection seam — get_coupon_objects()
				// only ever yields WC_Coupon instances (pass-through or `new WC_Coupon`),
				// so a non-coupon can never enter this loop, in tests or production.
				continue;
				// @codeCoverageIgnoreEnd
			}
			if ( ! self::is_coupon_currently_valid( $coupon, $cart ) ) {
				continue;
			}
			$coupons[] = self::format_coupon( $coupon );
		}

		if ( empty( $coupons ) ) {
			return [
				'found'   => 0,
				'coupons' => [],
				'message' => __( 'There are no active discount codes available right now.', 'fahad-ai-shopping-assistant-for-woocommerce' ),
			];
		}

		return [
			'found'   => count( $coupons ),
			'coupons' => $coupons,
		];
	}

	/**
	 * Apply a real coupon code to the session cart.
	 *
	 * Defers entirely to WC()->cart->apply_coupon(): WooCommerce runs its own full
	 * validation (existence, expiry, usage, restrictions) and returns true only when
	 * the code is genuinely applied. We report success ONLY in that case; otherwise
	 * we surface a plain error — never a fabricated success.
	 *
	 * @param array $input { code: string }
	 * @return array { success: bool, message?: string, cart_total?: string, error?: string }
	 */
	private static function apply_coupon( array $input ): array {
		$code = isset( $input['code'] ) ? sanitize_text_field( (string) $input['code'] ) : '';

		if ( '' === $code ) {
			return [
				'success' => false,
				'error'   => __( 'A coupon code is required.', 'fahad-ai-shopping-assistant-for-woocommerce' ),
			];
		}

		$cart = self::cart();
		if ( ! $cart ) {
			return [
				'success' => false,
				'error'   => __( 'The cart is not available right now.', 'fahad-ai-shopping-assistant-for-woocommerce' ),
			];
		}

		// WooCommerce validates the code itself and returns true only on success.
		$applied = $cart->apply_coupon( $code );

		if ( true !== $applied ) {
			return [
				'success' => false,
				'error'   => sprintf(
					/* translators: %s: the coupon code the customer tried to apply */
					__( 'The code "%s" could not be applied. It may be invalid, expired, or not applicable to your cart.', 'fahad-ai-shopping-assistant-for-woocommerce' ),
					$code
				),
			];
		}

		return [
			'success'    => true,
			'message'    => sprintf(
				/* translators: %s: the coupon code that was applied */
				__( 'Applied coupon %s to your cart.', 'fahad-ai-shopping-assistant-for-woocommerce' ),
				$code
			),
			'cart_total' => wp_strip_all_tags( (string) $cart->get_cart_total() ),
		];
	}

	// -------------------------------------------------------------------------
	// Validity
	// -------------------------------------------------------------------------

	/**
	 * Is this coupon genuinely usable right now?
	 *
	 * Layered, honest checks (cheapest/most-deterministic first):
	 *   1. Exists & published — guards drafts/pending/trash (a code in those states
	 *      is not offered to customers).
	 *   2. Not expired — `date_expires` is null (no expiry) or strictly in the future.
	 *   3. Total usage limit — `usage_limit` of 0 means unlimited; otherwise the
	 *      coupon is exhausted once `usage_count` reaches it.
	 *   4. Per-user usage limit — only when we can determine the user (logged in) and
	 *      the coupon sets `usage_limit_per_user`: exclude if this user already used
	 *      it that many times. Guests, or coupons with no per-user cap, skip this.
	 *   5. Cart applicability — ONLY when a non-empty session cart exists (so it is
	 *      determinable): defer to WooCommerce's own validator, which enforces
	 *      product/category/min-spend/exclude-sale rules against the actual cart.
	 *
	 * @param WC_Coupon     $coupon Coupon to test.
	 * @param WC_Cart|null  $cart   Current session cart, if available.
	 */
	private static function is_coupon_currently_valid( WC_Coupon $coupon, $cart ): bool {
		// 1. Published only. (A coupon constructed from an unknown code has id 0.)
		if ( $coupon->get_id() <= 0 ) {
			return false;
		}
		$status = $coupon->get_status();
		if ( '' !== $status && 'publish' !== $status ) {
			return false;
		}

		// 2. Expiry. WC_Coupon::get_date_expires() returns a WC_DateTime (extends
		//    DateTime, so getTimestamp() is available) or null. is_callable covers
		//    both the real object and any DateTime-like value.
		$expires = $coupon->get_date_expires();
		if ( $expires && is_callable( [ $expires, 'getTimestamp' ] ) && (int) $expires->getTimestamp() <= time() ) {
			return false;
		}

		// 3. Total usage limit.
		$usage_limit = (int) $coupon->get_usage_limit();
		if ( $usage_limit > 0 && (int) $coupon->get_usage_count() >= $usage_limit ) {
			return false;
		}

		// 4. Per-user usage limit (best effort; only when determinable).
		if ( ! self::passes_per_user_limit( $coupon ) ) {
			return false;
		}

		// 5. Cart applicability — defer to WooCommerce's own validator when a
		//    non-empty cart makes restrictions (product/category/min-spend) checkable.
		if ( $cart && ! $cart->is_empty() && ! self::is_valid_for_cart( $coupon, $cart ) ) {
			return false;
		}

		return true;
	}

	/**
	 * Per-user usage-limit check. Returns true (does not exclude) unless we can
	 * positively determine the logged-in user has hit the coupon's per-user cap.
	 *
	 * `get_used_by()` holds a mix of user IDs (as strings) and billing emails from
	 * past uses; counting this user's ID occurrences is the same signal WooCommerce
	 * uses for the per-user limit. For a guest (user id 0) the limit is not
	 * determinable here, so we do not exclude — WC enforces it again at apply time.
	 */
	private static function passes_per_user_limit( WC_Coupon $coupon ): bool {
		$per_user = (int) $coupon->get_usage_limit_per_user();
		if ( $per_user <= 0 ) {
			return true; // No per-user cap.
		}

		$user_id = (int) get_current_user_id();
		if ( $user_id <= 0 ) {
			return true; // Guest: not determinable here; WC re-checks at apply time.
		}

		$used_by = (array) $coupon->get_used_by();
		$times   = 0;
		foreach ( $used_by as $who ) {
			if ( (string) $who === (string) $user_id ) {
				$times++;
			}
		}

		return $times < $per_user;
	}

	/**
	 * Defer to WooCommerce's authoritative validator for cart applicability.
	 *
	 * WC_Discounts::is_coupon_valid() enforces product/category restrictions,
	 * minimum/maximum spend, exclude-sale-items, etc. against the supplied cart and
	 * returns true or a WP_Error. We never re-implement that logic. If the validator
	 * is unavailable (e.g. a stripped environment), we do not exclude — the coupon
	 * still passed the deterministic checks above, and apply_coupon validates again.
	 */
	private static function is_valid_for_cart( WC_Coupon $coupon, WC_Cart $cart ): bool {
		if ( ! class_exists( 'WC_Discounts' ) ) {
			return true;
		}

		$discounts = new WC_Discounts( $cart );
		$valid     = $discounts->is_coupon_valid( $coupon );

		return ! is_wp_error( $valid );
	}

	// -------------------------------------------------------------------------
	// Formatting
	// -------------------------------------------------------------------------

	/**
	 * Shape a coupon into the honest summary the model sees: the real code, a
	 * human description of the discount (type + amount), and a minimum-spend note
	 * when one applies. No invented fields.
	 */
	private static function format_coupon( WC_Coupon $coupon ): array {
		$summary = [
			'code'        => $coupon->get_code(),
			'description' => self::describe_discount( $coupon ),
		];

		$min = $coupon->get_minimum_amount();
		if ( '' !== (string) $min && (float) $min > 0 ) {
			$summary['minimum_spend'] = sprintf(
				/* translators: %s: formatted minimum spend amount, e.g. $50 */
				__( 'Minimum spend %s', 'fahad-ai-shopping-assistant-for-woocommerce' ),
				self::money( $min )
			);
		}

		$free_text = $coupon->get_description();
		if ( '' !== (string) $free_text ) {
			$summary['note'] = wp_strip_all_tags( (string) $free_text );
		}

		return $summary;
	}

	/**
	 * Human-readable discount description from the coupon's type + amount.
	 *
	 * percent / percent_product  → "N% off [select products]"
	 * fixed_cart / fixed_product → "<money> off [select products]"
	 */
	private static function describe_discount( WC_Coupon $coupon ): string {
		$type   = $coupon->get_discount_type();
		$amount = $coupon->get_amount();

		$is_percent     = in_array( $type, [ 'percent', 'percent_product' ], true );
		$product_scoped = in_array( $type, [ 'percent_product', 'fixed_product' ], true );

		if ( $is_percent ) {
			$value = self::trim_decimal( (string) wc_format_decimal( $amount ) );
			$base  = $product_scoped
				/* translators: %s: percentage amount, e.g. 15 */
				? sprintf( __( '%s%% off select products', 'fahad-ai-shopping-assistant-for-woocommerce' ), $value )
				/* translators: %s: percentage amount, e.g. 15 */
				: sprintf( __( '%s%% off', 'fahad-ai-shopping-assistant-for-woocommerce' ), $value );
			return $base;
		}

		$money = self::money( $amount );
		return $product_scoped
			/* translators: %s: formatted discount amount, e.g. $10 */
			? sprintf( __( '%s off select products', 'fahad-ai-shopping-assistant-for-woocommerce' ), $money )
			/* translators: %s: formatted discount amount, e.g. $10 */
			: sprintf( __( '%s off', 'fahad-ai-shopping-assistant-for-woocommerce' ), $money );
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	/**
	 * Published `shop_coupon` posts as WC_Coupon objects.
	 *
	 * Enumerates the coupon post type and loads each code via `new WC_Coupon`.
	 * Items that are already WC_Coupon instances are passed through unchanged — a
	 * small seam that keeps the enumeration source mockable in unit tests (the test
	 * stubs get_posts() to return coupon mocks directly) without a process-global
	 * constructor overload.
	 *
	 * Degrades to an empty list (rather than fataling) if the WP query function is
	 * unavailable — so the tool stays honest: it reports "no codes" instead of
	 * erroring, and never invents one.
	 *
	 * @return array<int, WC_Coupon>
	 */
	private static function get_coupon_objects(): array {
		if ( ! function_exists( 'get_posts' ) ) {
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

		$coupons = [];
		foreach ( $posts as $post ) {
			if ( $post instanceof WC_Coupon ) {
				$coupons[] = $post;
				continue;
			}
			// A WP_Post: its title is the coupon code.
			$code = is_object( $post ) && isset( $post->post_title ) ? $post->post_title : $post;
			$coupons[] = new WC_Coupon( $code );
		}

		return $coupons;
	}

	/**
	 * The current session cart, or null when unavailable.
	 *
	 * The API handler calls wc_load_cart() before tools run, so WC()->cart is
	 * normally present; we still guard for a missing cart so the tools degrade
	 * cleanly rather than fatal.
	 *
	 * @return WC_Cart|null
	 */
	private static function cart() {
		if ( ! function_exists( 'WC' ) ) {
			return null;
		}
		$wc = WC();
		return ( $wc && isset( $wc->cart ) && $wc->cart instanceof WC_Cart ) ? $wc->cart : null;
	}

	/**
	 * Trim an insignificant trailing fractional part: "10.00" → "10", "12.50" →
	 * "12.5", "15" → "15". Only strips zeros AFTER a decimal point, so a whole
	 * number's own trailing digits are preserved.
	 */
	private static function trim_decimal( string $value ): string {
		if ( ! str_contains( $value, '.' ) ) {
			return $value;
		}
		return rtrim( rtrim( $value, '0' ), '.' );
	}

	/**
	 * Format a raw amount as a plain money string (currency symbol, no HTML),
	 * trimming a trailing ".00" so "$10.00" reads as "$10". Mirrors the plain,
	 * card-friendly price style the product tools use.
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
}

// Self-register this feature pack the moment the file is loaded. The bootstrap
// (and the test bootstrap) glob-require includes/tools/*.php, so dropping this file
// in is the ONLY wiring needed — no bootstrap or harness edits.
// @codeCoverageIgnoreStart
// Reason: file-scope self-registration runs once at require time (test bootstrap
// glob-requires this file) — before PHPUnit opens its per-test pcov window.
Fahad_AI_Tool_Registry::register_pack( [ 'Fahad_AI_Coupon_Tools', 'register' ] );
// @codeCoverageIgnoreEnd
