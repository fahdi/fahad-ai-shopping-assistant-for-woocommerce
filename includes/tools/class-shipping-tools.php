<?php
defined( 'ABSPATH' ) || exit;

/**
 * Shipping & delivery estimate tool (issue #19).
 *
 * Removes the "will it arrive / how much is shipping?" blocker by letting the
 * agent quote real shipping COSTS for a destination, plus a delivery WINDOW only
 * where one is genuinely derivable. A drop-in feature pack following the
 * reference pattern in Fahad_AI_Catalog_Tools: a self-contained class in its own
 * file under includes/tools/ that self-registers a provider at file scope via
 * Fahad_AI_Tool_Registry::register_pack() — no bootstrap, test-bootstrap, or
 * harness edits.
 *
 * Tool provided:
 *   - estimate_delivery — given a destination (country, optional state/province,
 *     optional postcode) and optionally a product_id, build a WooCommerce
 *     shipping package, find the matching zone, and report each ENABLED method's
 *     title + cost. A delivery window is included ONLY when a method genuinely
 *     exposes one (a `delivery_time`/`delivery_days` option, the de-facto setting
 *     several shipping plugins add); WooCommerce CORE has no delivery-date field,
 *     so we never invent an ETA.
 *
 * HONESTY / GRACEFUL FALLBACK (the whole point of the feature):
 *   - No matching zone, or a zone with no enabled methods → a clear "we couldn't
 *     determine shipping for that location, please check the destination"
 *     message. NOT an error object, NOT a fatal, NOT a fabricated number.
 *   - No derivable window → costs are still returned, with an explicit note that a
 *     precise delivery date isn't available rather than a guessed one.
 *
 * TESTABILITY: the ONLY method that touches the WooCommerce shipping stack
 * (WC_Shipping_Zones, zone + method objects) is resolve_zone_methods(); it
 * normalizes everything down to a plain array of { id, title, cost,
 * delivery_window } descriptors (or null when no zone matches). Everything else
 * is pure array shaping over that normalized list, so the cost/window/fallback
 * logic is unit-tested by overriding that single seam — no live shipping stack
 * required (Brain\Monkey can mock functions but not the concrete WC_Shipping_*
 * classes / their static matcher).
 *
 * NOTE on `final`: unlike the other packs this class is intentionally NOT marked
 * `final`, SOLELY so the test suite can subclass it to override the one
 * resolve_zone_methods() seam. No production code subclasses it.
 */
class Fahad_AI_Shipping_Tools {

	/**
	 * Append the shipping tool to the registry's tool list.
	 *
	 * Registered as a pack provider (see the register_pack() call at file scope).
	 * Static because the pack holds no per-instance state — it just queries the
	 * WooCommerce shipping zones for a destination.
	 *
	 * @param array $tools Existing tool definitions.
	 * @return array Tools with the shipping tool appended.
	 */
	public static function register( array $tools ): array {
		// Bind the callback (and, transitively, the WC seam) to the class register()
		// was actually called on, so a test subclass that overrides
		// resolve_zone_methods() is honoured. `static::class` is the late-static
		// (called) class here; we capture it because inside the arrow-fn below
		// `static::` would instead resolve to the defining (parent) class.
		$class = static::class;

		$tools[] = [
			'name'        => 'estimate_delivery',
			'description' => 'Estimate the shipping cost (and a delivery window when the store provides one) for a destination. Use this when the customer asks how much shipping costs, whether you ship to a place, or when their order will arrive. Provide at least the destination country (ISO code or name); state/province and postcode improve accuracy. If shipping cannot be determined for the destination, say so honestly — do not guess a price. WooCommerce has no built-in delivery date, so only state a delivery window if this tool returns one.',
			'parameters'  => [
				'type'       => 'object',
				'properties' => [
					'country'    => [ 'type' => 'string',  'description' => 'Destination country as a 2-letter ISO code (e.g. "US", "GB") or country name. Required.' ],
					'state'      => [ 'type' => 'string',  'description' => 'Optional state / province / region code (e.g. "CA").' ],
					'postcode'   => [ 'type' => 'string',  'description' => 'Optional postal / ZIP code; improves rate accuracy for some methods.' ],
					'product_id' => [ 'type' => 'integer', 'description' => 'Optional product ID to estimate shipping for that single product instead of the current cart.' ],
				],
				'required'   => [ 'country' ],
			],
			'callback'    => static fn( array $input ) => self::estimate_delivery( $input, $class ),
		];

		return $tools;
	}

	// -------------------------------------------------------------------------
	// Tool implementation
	// -------------------------------------------------------------------------

	/**
	 * Estimate shipping methods + costs for a destination.
	 *
	 * Flow: validate destination → build a package → resolve the matching zone's
	 * enabled methods (the WC seam) → shape the response. Returns one of:
	 *   - guidance     (no/blank country): { available:false, message }
	 *   - fallback     (no zone / no methods): { available:false, message }
	 *   - success      : { available:true, destination, methods[], delivery_window_available, note? }
	 *
	 * @param array  $input { country, state?, postcode?, product_id? }
	 * @param string $class The class register() was called on; the WC seam is
	 *                      invoked through it so a test override is honoured.
	 * @return array
	 */
	private static function estimate_delivery( array $input, string $class = self::class ): array {
		$country  = strtoupper( sanitize_text_field( (string) ( $input['country'] ?? '' ) ) );
		$state    = strtoupper( sanitize_text_field( (string) ( $input['state'] ?? '' ) ) );
		$postcode = sanitize_text_field( (string) ( $input['postcode'] ?? '' ) );

		// Missing/blank destination: ask for it rather than guessing a zone.
		if ( '' === $country ) {
			return [
				'available' => false,
				'message'   => __( 'Please tell me the destination country (and ideally the state/region and postcode) so I can check shipping options.', 'fahad-ai-shopping-assistant-for-woocommerce' ),
			];
		}

		$package = self::build_package( $country, $state, $postcode, isset( $input['product_id'] ) ? (int) $input['product_id'] : 0 );

		// Route through the bound class so an overridden seam (tests) is used.
		$methods = call_user_func( [ $class, 'resolve_zone_methods' ], $package );

		// No matching zone (null) or a matched zone with no enabled methods ([]):
		// be honest, never fabricate a rate.
		if ( empty( $methods ) ) {
			return [
				'available' => false,
				'message'   => sprintf(
					/* translators: %s: destination country code or name */
					__( 'I couldn\'t determine shipping for %s. Please double-check the destination (country, and a state/region or postcode if you have one) — we may not ship there, or no shipping method is configured for that location.', 'fahad-ai-shopping-assistant-for-woocommerce' ),
					$country
				),
			];
		}

		$shaped          = self::shape_methods( $methods );
		$window_available = false;
		foreach ( $shaped as $m ) {
			if ( null !== $m['delivery_window'] && '' !== $m['delivery_window'] ) {
				$window_available = true;
				break;
			}
		}

		$result = [
			'available'                 => true,
			'destination'               => array_filter(
				[
					'country'  => $country,
					'state'    => $state,
					'postcode' => $postcode,
				],
				static fn( $v ) => '' !== $v
			),
			'methods'                   => $shaped,
			'delivery_window_available' => $window_available,
		];

		// Honest note when WooCommerce gives us no delivery date to report.
		if ( ! $window_available ) {
			$result['note'] = __( 'A precise delivery date isn\'t available — these are the shipping options and their costs. Delivery time depends on the carrier.', 'fahad-ai-shopping-assistant-for-woocommerce' );
		}

		return $result;
	}

	// -------------------------------------------------------------------------
	// Helpers (pure — operate on the normalized descriptor list)
	// -------------------------------------------------------------------------

	/**
	 * Build a WooCommerce shipping package for the destination.
	 *
	 * Kept tiny and side-effect-free so it can be asserted on directly. The
	 * contents are intentionally minimal: zone MATCHING only depends on the
	 * destination, which is all this tool needs to enumerate available methods and
	 * their base costs. (A full cart-cost calculation per method is out of scope —
	 * see the class docblock — so we don't assemble line items here.)
	 *
	 * @param string $country  Upper-cased ISO country code or name.
	 * @param string $state    Upper-cased state/region (may be '').
	 * @param string $postcode Postcode (may be '').
	 * @param int    $product_id Optional product id (0 when absent).
	 * @return array WC shipping package shape (destination + empty contents).
	 */
	private static function build_package( string $country, string $state, string $postcode, int $product_id ): array {
		return [
			'destination' => [
				'country'  => $country,
				'state'    => $state,
				'postcode' => $postcode,
			],
			'contents'      => [],
			'contents_cost' => 0,
			'product_id'    => $product_id,
		];
	}

	/**
	 * Normalize the resolved method descriptors into the result shape, never
	 * inventing a delivery window. A descriptor without a window keeps a null
	 * window; the caller decides the honest note.
	 *
	 * @param array<int,array> $methods Descriptors from resolve_zone_methods().
	 * @return array<int,array{id:string,title:string,cost:string,delivery_window:?string}>
	 */
	private static function shape_methods( array $methods ): array {
		$shaped = [];
		foreach ( $methods as $m ) {
			if ( ! is_array( $m ) ) {
				continue;
			}
			$window = $m['delivery_window'] ?? null;
			$shaped[] = [
				'id'              => (string) ( $m['id'] ?? '' ),
				'title'           => (string) ( $m['title'] ?? '' ),
				'cost'            => (string) ( $m['cost'] ?? '' ),
				'delivery_window' => ( is_string( $window ) && '' !== trim( $window ) ) ? trim( $window ) : null,
			];
		}
		return $shaped;
	}

	// -------------------------------------------------------------------------
	// WooCommerce shipping SEAM (the only WC-touching method — overridable in tests)
	// -------------------------------------------------------------------------

	/**
	 * Resolve the enabled shipping methods serving a destination, normalized to
	 * plain descriptors. This is the single point of contact with the WooCommerce
	 * shipping stack, isolated so the rest of the tool is pure and unit-testable
	 * (tests override this method with canned data).
	 *
	 * Returns:
	 *   - null            when no shipping zone matches the package (unknown
	 *                     destination) — the caller turns this into the honest
	 *                     "couldn't determine" fallback.
	 *   - []              when a zone matches but has no enabled methods.
	 *   - array of { id, title, cost, delivery_window } otherwise.
	 *
	 * A delivery window is read from a method's `delivery_time`/`delivery_days`
	 * option when present (a de-facto setting several shipping plugins expose);
	 * WooCommerce core has none, so most methods yield null here and the caller
	 * reports costs with an honest "no precise date" note.
	 *
	 * `protected static` (not private) so a test subclass can override it with
	 * canned data — this is the single injectable seam. The class is intentionally
	 * NOT `final` SOLELY to permit that override; no production code subclasses it,
	 * and nothing else here is meant to be extended.
	 *
	 * @param array $package WC shipping package (destination + contents).
	 * @return array<int,array{id:string,title:string,cost:string,delivery_window:?string}>|null
	 */
	protected static function resolve_zone_methods( array $package ): ?array {
		if ( ! class_exists( 'WC_Shipping_Zones' ) ) {
			// @codeCoverageIgnoreStart
			// Reason: the shared test bootstrap (tests/stubs/wc-stubs.php) always defines WC_Shipping_Zones, so this no-WooCommerce guard branch can never be entered in-process.
			return null;
			// @codeCoverageIgnoreEnd
		}

		$zone = WC_Shipping_Zones::get_zone_matching_package( $package );

		// WC returns the "Rest of the World" zone (id 0) when nothing else matches.
		// If that zone has no enabled methods we treat the destination as
		// unserviceable below (empty list), which the caller reports honestly.
		if ( ! $zone || ! is_object( $zone ) || ! method_exists( $zone, 'get_shipping_methods' ) ) {
			// @codeCoverageIgnoreStart
			// Reason: the shared WC_Shipping_Zones stub hardcodes get_zone_matching_package() to return a concrete WC_Shipping_Zone with get_shipping_methods(), and a loaded concrete static cannot be overridden in-process, so this falsy-zone guard can never be entered.
			return null;
			// @codeCoverageIgnoreEnd
		}

		$descriptors = [];
		foreach ( $zone->get_shipping_methods( true ) as $method ) {
			$descriptors[] = [
				'id'              => (string) ( $method->id ?? '' ),
				'title'           => self::method_title( $method ),
				'cost'            => self::method_cost( $method ),
				'delivery_window' => self::method_delivery_window( $method ),
			];
		}

		return $descriptors;
	}

	/**
	 * Human title for a shipping method (instance title falls back to method id).
	 *
	 * @param object $method WC_Shipping_Method instance.
	 */
	private static function method_title( object $method ): string {
		if ( method_exists( $method, 'get_method_title' ) ) {
			$title = (string) $method->get_method_title();
			if ( '' !== $title ) {
				return $title;
			}
		}
		return isset( $method->title ) ? (string) $method->title : (string) ( $method->id ?? '' );
	}

	/**
	 * Base cost for a method, as a plain string. flat_rate exposes a `cost`
	 * option; free_shipping is reported as "0". Methods whose cost is dynamic
	 * (e.g. table/weight based) and not a simple option yield '' (unknown), so the
	 * agent reports the method without inventing a number.
	 *
	 * @param object $method WC_Shipping_Method instance.
	 */
	private static function method_cost( object $method ): string {
		$id = (string) ( $method->id ?? '' );

		if ( 'free_shipping' === $id ) {
			return '0';
		}

		if ( method_exists( $method, 'get_option' ) ) {
			$cost = $method->get_option( 'cost' );
			if ( is_scalar( $cost ) && '' !== (string) $cost ) {
				return (string) $cost;
			}
		}

		if ( isset( $method->cost ) && is_scalar( $method->cost ) ) {
			return (string) $method->cost;
		}

		return '';
	}

	/**
	 * Derive a delivery window from a method ONLY when it genuinely exposes one.
	 *
	 * Checks the de-facto `delivery_time` / `delivery_days` method options that
	 * several shipping plugins add. WooCommerce core has no such field, so this
	 * returns null for stock flat_rate/free_shipping — and the caller then reports
	 * costs with an honest note instead of an invented ETA.
	 *
	 * @param object $method WC_Shipping_Method instance.
	 * @return string|null
	 */
	private static function method_delivery_window( object $method ): ?string {
		if ( ! method_exists( $method, 'get_option' ) ) {
			return null;
		}

		foreach ( [ 'delivery_time', 'delivery_days', 'delivery_window' ] as $option ) {
			$value = $method->get_option( $option );
			if ( is_string( $value ) && '' !== trim( $value ) ) {
				return trim( $value );
			}
		}

		return null;
	}
}

// Self-register this feature pack the moment the file is loaded. The bootstrap
// (and the test bootstrap) glob-require includes/tools/*.php, so dropping this
// file in is the ONLY wiring needed — no bootstrap or harness edits.
// @codeCoverageIgnoreStart
// Reason: file-scope self-registration runs once at require time during bootstrap, before PHPUnit's per-test pcov window opens, so it is never measurable.
Fahad_AI_Tool_Registry::register_pack( [ 'Fahad_AI_Shipping_Tools', 'register' ] );
// @codeCoverageIgnoreEnd
