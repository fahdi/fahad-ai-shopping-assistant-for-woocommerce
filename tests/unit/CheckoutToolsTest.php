<?php
/**
 * Unit tests for Fahad_AI_Checkout_Tools (issue #55: conversational checkout assist).
 *
 * Red → Green → Refactor. Conventions mirror ShippingToolsTest / CouponToolsTest:
 * WP/WC functions mocked via Brain\Monkey; WC objects via Mockery; the registry
 * singleton + its static pack list snapshotted and restored so a case here neither
 * inherits another suite's packs nor leaks the checkout pack we register. The
 * production registration + merge + dispatch path is what is under test (every case
 * dispatches through Fahad_AI_Tool_Registry::instance()->dispatch()).
 *
 * Like the shipping pack, the checkout pack isolates EVERY WooCommerce cart /
 * shipping / coupon touch behind a small set of overridable `protected static`
 * seams (read_cart, resolve_shipping, select_shipping_method, candidate_coupons,
 * apply_coupon_code). Those WC surfaces, WC()->cart, WC()->session,
 * WC()->shipping(), WC_Shipping_Zones, WC_Discounts, are concrete classes /
 * singletons that Brain\Monkey (a FUNCTION mocker) cannot intercept. So these tests
 * drive a tiny subclass (Fahad_AI_Checkout_Tools_Stub) that overrides the seams with
 * canned data, exercising the summary-shaping, best-coupon selection, consent gate,
 * and PCI-boundary logic WITHOUT a live WooCommerce stack. Two seam-internal helpers
 * (best_candidate, money) are also hit directly through reflection on the REAL class
 * (private members are reflection-accessible since PHP 8.1, no setAccessible(),
 * which is a deprecated no-op on PHP 8.5).
 */

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;

class CheckoutToolsTest extends TestCase {

	use MockeryPHPUnitIntegration;

	/**
	 * Snapshot of the registry's static pack providers, restored in tearDown so a
	 * test here neither inherits another suite's packs nor leaks the pack we register.
	 *
	 * @var array<int, callable>
	 */
	private array $pack_snapshot = [];

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$this->pack_snapshot = (array) ( new ReflectionProperty( Fahad_AI_Tool_Registry::class, 'pack_providers' ) )->getValue();

		// Seam-test scratch state cleared between cases.
		Fahad_AI_Checkout_Tools_Stub::reset_stub();

		Functions\stubs( [
			'sanitize_text_field' => fn( $s ) => is_string( $s ) ? trim( $s ) : $s,
			'absint'              => fn( $n ) => abs( (int) $n ),
			'wp_strip_all_tags'   => fn( $s ) => strip_tags( (string) $s ),
			'wc_format_decimal'   => fn( $n ) => (string) $n,
			'get_woocommerce_currency_symbol' => fn() => '$',
			// The handoff URL, the PCI boundary stops here; we never go past checkout.
			'wc_get_checkout_url' => fn() => 'https://shop.test/checkout/',
			'wc_get_cart_url'     => fn() => 'https://shop.test/cart/',
			// Registry get_tools() reads the merchant tool-gating option (issue #56);
			// default (no disabled tools) so dispatch()/specs() are unaffected.
			'get_option'          => fn( $key, $default = '' ) => $default,
		] );
	}

	protected function tearDown(): void {
		( new ReflectionProperty( Fahad_AI_Tool_Registry::class, 'pack_providers' ) )->setValue( null, $this->pack_snapshot );
		Monkey\tearDown();
		parent::tearDown();
	}

	// ── fixtures / helpers ──────────────────────────────────────────────────────

	/** A non-empty cart snapshot with sensible defaults; overridable per field. */
	private function cartSnapshot( array $over = [] ): array {
		return array_merge( [
			'empty'           => false,
			'items'           => [
				[ 'name' => 'Blue Hoodie', 'quantity' => 1, 'line_total' => '40.00' ],
			],
			'item_count'      => 1,
			'subtotal'        => '40.00',
			'discount_total'  => '0.00',
			'tax_total'       => '0.00',
			'total'           => '40.00',
			'applied_coupon'  => null,
			'currency_symbol' => '$',
		], $over );
	}

	/** A resolved-shipping snapshot: a list of methods + the chosen id. */
	private function shippingSnapshot( array $methods, ?string $chosen = null, bool $needed = true ): array {
		return [
			'needed'        => $needed,
			'chosen_method' => $chosen,
			'methods'       => $methods,
		];
	}

	/**
	 * Register the OVERRIDDEN pack (the stub subclass) and dispatch a checkout tool
	 * through the REAL registry, so the production registration + dispatch path runs
	 * while the WC seams return canned data.
	 */
	private function dispatch( string $tool, array $input = [] ): array {
		( new ReflectionProperty( Fahad_AI_Tools::class, 'instance' ) )->setValue( null, null );
		( new ReflectionProperty( Fahad_AI_Tool_Registry::class, 'instance' ) )->setValue( null, null );

		Fahad_AI_Tool_Registry::reset_packs();
		Fahad_AI_Tool_Registry::register_pack( [ 'Fahad_AI_Checkout_Tools_Stub', 'register' ] );

		return Fahad_AI_Tool_Registry::instance()->dispatch( $tool, $input );
	}

	/**
	 * Fresh registry whose built tool list includes the checkout tools, registered
	 * via the pack's REAL provider, exactly what the file-scope self-registration
	 * does in production (used for the spec/registration assertions).
	 */
	private function registry(): Fahad_AI_Tool_Registry {
		( new ReflectionProperty( Fahad_AI_Tools::class, 'instance' ) )->setValue( null, null );
		( new ReflectionProperty( Fahad_AI_Tool_Registry::class, 'instance' ) )->setValue( null, null );

		Fahad_AI_Tool_Registry::reset_packs();
		Fahad_AI_Tool_Registry::register_pack( [ 'Fahad_AI_Checkout_Tools', 'register' ] );

		return Fahad_AI_Tool_Registry::instance();
	}

	/** Invoke a private static helper on the REAL pack class via reflection. */
	private function invokePrivate( string $method, ...$args ) {
		return ( new ReflectionMethod( Fahad_AI_Checkout_Tools::class, $method ) )->invokeArgs( null, $args );
	}

	// ── registration ────────────────────────────────────────────────────────────

	public function test_checkout_tools_are_registered_via_register_pack(): void {
		$names = array_column( $this->registry()->specs(), 'name' );

		$this->assertContains( 'get_checkout_summary', $names );
		$this->assertContains( 'set_shipping_method', $names );
		$this->assertContains( 'apply_best_coupon', $names );
		// Additive, the six built-ins remain.
		$this->assertContains( 'search_products', $names );
		$this->assertContains( 'add_to_cart', $names );
		$this->assertCount( 9, $names );
	}

	public function test_checkout_tool_specs_never_leak_a_callback(): void {
		$specs = array_column( $this->registry()->specs(), null, 'name' );

		foreach ( [ 'get_checkout_summary', 'set_shipping_method', 'apply_best_coupon' ] as $name ) {
			$this->assertArrayHasKey( $name, $specs );
			$this->assertArrayNotHasKey( 'callback', $specs[ $name ] );
			$this->assertArrayHasKey( 'description', $specs[ $name ] );
			$this->assertSame( 'object', $specs[ $name ]['parameters']['type'] );
			$this->assertArrayHasKey( 'properties', $specs[ $name ]['parameters'] );
		}
		// set_shipping_method takes a method_id; apply_best_coupon takes a confirm flag.
		$this->assertArrayHasKey( 'method_id', $specs['set_shipping_method']['parameters']['properties'] );
		$this->assertArrayHasKey( 'confirm', $specs['apply_best_coupon']['parameters']['properties'] );
	}

	public function test_checkout_tools_are_not_personal(): void {
		// They operate on the SHARED session cart (not customer-specific records), so
		// they must NOT be login-gated, a guest checking out must get answers.
		$map = ( new ReflectionMethod( Fahad_AI_Tool_Registry::class, 'get_tools' ) )->invoke( $this->registry() );

		foreach ( [ 'get_checkout_summary', 'set_shipping_method', 'apply_best_coupon' ] as $name ) {
			$this->assertArrayHasKey( $name, $map );
			$this->assertEmpty( $map[ $name ]['personal'] ?? null );
		}
	}

	// ── get_checkout_summary: grounded in the real cart/shipping/total ───────────

	public function test_summary_reflects_real_cart_shipping_and_total(): void {
		Fahad_AI_Checkout_Tools_Stub::$cart = $this->cartSnapshot( [
			'items'          => [
				[ 'name' => 'Blue Hoodie', 'quantity' => 2, 'line_total' => '80.00' ],
			],
			'subtotal'       => '80.00',
			'discount_total' => '10.00',
			'total'          => '75.00',
			'applied_coupon' => 'SAVE10',
		] );
		Fahad_AI_Checkout_Tools_Stub::$shipping = $this->shippingSnapshot(
			[
				[ 'id' => 'flat_rate:1',     'label' => 'Flat rate',     'cost' => '5.00' ],
				[ 'id' => 'free_shipping:2', 'label' => 'Free shipping', 'cost' => '0.00' ],
			],
			'flat_rate:1'
		);

		$result = $this->dispatch( 'get_checkout_summary' );

		// Cart contents are echoed from the real snapshot, not invented.
		$this->assertFalse( $result['empty'] );
		$this->assertCount( 1, $result['items'] );
		$this->assertSame( 'Blue Hoodie', $result['items'][0]['name'] );
		$this->assertSame( 2, $result['items'][0]['quantity'] );

		// Subtotal / discount / total come straight from the cart's real figures.
		$this->assertStringContainsString( '80', (string) $result['subtotal'] );
		$this->assertStringContainsString( '75', (string) $result['total'] );
		$this->assertSame( 'SAVE10', $result['applied_coupon'] );

		// Shipping reflects the zone's real methods + which one is chosen.
		$this->assertCount( 2, $result['shipping']['methods'] );
		$this->assertSame( 'flat_rate:1', $result['shipping']['chosen_method'] );
		$this->assertSame( 'Flat rate', $result['shipping']['methods'][0]['label'] );

		// Handoff URL present; PCI boundary asserted in its own test.
		$this->assertSame( 'https://shop.test/checkout/', $result['checkout_url'] );
	}

	public function test_summary_surfaces_tax_from_the_real_cart(): void {
		Fahad_AI_Checkout_Tools_Stub::$cart     = $this->cartSnapshot( [ 'tax_total' => '6.40' ] );
		Fahad_AI_Checkout_Tools_Stub::$shipping = $this->shippingSnapshot( [], null, false );

		$result = $this->dispatch( 'get_checkout_summary' );

		$this->assertSame( '6.40', $result['tax_total'] );
	}

	public function test_summary_lists_all_applied_coupons(): void {
		Fahad_AI_Checkout_Tools_Stub::$cart     = $this->cartSnapshot( [ 'applied_coupons' => [ 'SAVE10', 'EXTRA' ] ] );
		Fahad_AI_Checkout_Tools_Stub::$shipping = $this->shippingSnapshot( [], null, false );

		$result = $this->dispatch( 'get_checkout_summary' );

		$this->assertSame( [ 'SAVE10', 'EXTRA' ], $result['applied_coupons'] );
	}

	public function test_summary_includes_item_count(): void {
		Fahad_AI_Checkout_Tools_Stub::$cart     = $this->cartSnapshot( [ 'item_count' => 4 ] );
		Fahad_AI_Checkout_Tools_Stub::$shipping = $this->shippingSnapshot( [], null, false );

		$result = $this->dispatch( 'get_checkout_summary' );

		$this->assertSame( 4, $result['item_count'] );
	}

	public function test_summary_empty_cart_returns_empty_state_with_no_totals(): void {
		Fahad_AI_Checkout_Tools_Stub::$cart = [ 'empty' => true ];

		$result = $this->dispatch( 'get_checkout_summary' );

		$this->assertTrue( $result['empty'] );
		$this->assertArrayHasKey( 'message', $result );
		// Nothing to total, must not fabricate items or a price.
		$this->assertArrayNotHasKey( 'items', $result );
		$this->assertArrayNotHasKey( 'total', $result );
	}

	public function test_summary_handles_no_shipping_required_without_inventing_methods(): void {
		// e.g. an all-virtual cart: shipping not needed → no methods, no invented cost.
		Fahad_AI_Checkout_Tools_Stub::$cart     = $this->cartSnapshot();
		Fahad_AI_Checkout_Tools_Stub::$shipping = $this->shippingSnapshot( [], null, false );

		$result = $this->dispatch( 'get_checkout_summary' );

		$this->assertFalse( $result['shipping']['needed'] );
		$this->assertSame( [], $result['shipping']['methods'] );
		$this->assertNull( $result['shipping']['chosen_method'] );
		// Still a valid summary with the real total + handoff.
		$this->assertArrayHasKey( 'total', $result );
		$this->assertSame( 'https://shop.test/checkout/', $result['checkout_url'] );
	}

	public function test_summary_omits_applied_coupon_key_when_none_applied(): void {
		Fahad_AI_Checkout_Tools_Stub::$cart     = $this->cartSnapshot( [ 'applied_coupon' => null ] );
		Fahad_AI_Checkout_Tools_Stub::$shipping = $this->shippingSnapshot( [], null, false );

		$result = $this->dispatch( 'get_checkout_summary' );

		// No coupon applied → the field is null/absent, never a fabricated code.
		$this->assertArrayHasKey( 'applied_coupon', $result );
		$this->assertNull( $result['applied_coupon'] );
	}

	// ── set_shipping_method: choose a REAL available method + recalc ─────────────

	public function test_set_shipping_method_selects_an_available_method_and_recalcs(): void {
		Fahad_AI_Checkout_Tools_Stub::$cart     = $this->cartSnapshot( [ 'total' => '45.00' ] );
		Fahad_AI_Checkout_Tools_Stub::$shipping = $this->shippingSnapshot(
			[
				[ 'id' => 'flat_rate:1',     'label' => 'Flat rate',     'cost' => '5.00' ],
				[ 'id' => 'free_shipping:2', 'label' => 'Free shipping', 'cost' => '0.00' ],
			],
			'flat_rate:1'
		);

		$result = $this->dispatch( 'set_shipping_method', [ 'method_id' => 'free_shipping:2' ] );

		$this->assertTrue( $result['success'] );
		// The seam recorded the chosen id (proves we set it on the session, not faked).
		$this->assertSame( 'free_shipping:2', Fahad_AI_Checkout_Tools_Stub::$selected_method );
		$this->assertSame( 'free_shipping:2', $result['chosen_method'] );
		// Recalculated total comes back so the agent can state a grounded number.
		$this->assertArrayHasKey( 'total', $result );
	}

	public function test_set_shipping_method_rejects_a_method_not_offered(): void {
		// The model must NOT be able to select a method WooCommerce doesn't offer for
		// this destination, that would mis-state shipping.
		Fahad_AI_Checkout_Tools_Stub::$cart     = $this->cartSnapshot();
		Fahad_AI_Checkout_Tools_Stub::$shipping = $this->shippingSnapshot(
			[ [ 'id' => 'flat_rate:1', 'label' => 'Flat rate', 'cost' => '5.00' ] ],
			'flat_rate:1'
		);

		$result = $this->dispatch( 'set_shipping_method', [ 'method_id' => 'local_pickup:9' ] );

		$this->assertNotTrue( $result['success'] ?? false );
		$this->assertArrayHasKey( 'error', $result );
		// The seam must NOT have been told to select the bogus method.
		$this->assertNull( Fahad_AI_Checkout_Tools_Stub::$selected_method );
	}

	public function test_set_shipping_method_requires_a_method_id(): void {
		Fahad_AI_Checkout_Tools_Stub::$cart     = $this->cartSnapshot();
		Fahad_AI_Checkout_Tools_Stub::$shipping = $this->shippingSnapshot(
			[ [ 'id' => 'flat_rate:1', 'label' => 'Flat rate', 'cost' => '5.00' ] ],
			null
		);

		$result = $this->dispatch( 'set_shipping_method', [] );

		$this->assertNotTrue( $result['success'] ?? false );
		$this->assertArrayHasKey( 'error', $result );
		$this->assertNull( Fahad_AI_Checkout_Tools_Stub::$selected_method );
	}

	// ── apply_best_coupon: best pick, consent gate, never invents ────────────────

	public function test_apply_best_coupon_picks_the_genuinely_best_valid_code(): void {
		// Three valid+applicable coupons with different savings; the BEST (largest
		// real saving) must be the one recommended.
		Fahad_AI_Checkout_Tools_Stub::$cart       = $this->cartSnapshot( [ 'subtotal' => '100.00', 'total' => '100.00' ] );
		Fahad_AI_Checkout_Tools_Stub::$candidates = [
			[ 'code' => 'FIVE',   'saving' => 5.0,  'description' => '$5 off' ],
			[ 'code' => 'TWENTY', 'saving' => 20.0, 'description' => '20% off' ],
			[ 'code' => 'TEN',    'saving' => 10.0, 'description' => '$10 off' ],
		];

		$result = $this->dispatch( 'apply_best_coupon', [] ); // no consent → recommend only

		$this->assertSame( 'TWENTY', $result['code'] );
		$this->assertEqualsWithDelta( 20.0, (float) $result['saving'], 0.001 );
	}

	public function test_apply_best_coupon_without_consent_recommends_but_does_not_apply(): void {
		Fahad_AI_Checkout_Tools_Stub::$cart       = $this->cartSnapshot();
		Fahad_AI_Checkout_Tools_Stub::$candidates = [
			[ 'code' => 'SAVE15', 'saving' => 15.0, 'description' => '15% off' ],
		];

		$result = $this->dispatch( 'apply_best_coupon', [] );

		// Recommendation, NOT an application.
		$this->assertFalse( $result['applied'] );
		$this->assertTrue( $result['recommended'] ?? false );
		$this->assertSame( 'SAVE15', $result['code'] );
		// The cart was NOT touched: the apply seam must not have run.
		$this->assertSame( [], Fahad_AI_Checkout_Tools_Stub::$applied_codes );
		// A message that asks for confirmation rather than claiming a discount.
		$this->assertArrayHasKey( 'message', $result );
		$this->assertMatchesRegularExpression( '/(confirm|want|shall|apply it|would you|let me know)/i', $result['message'] );
	}

	public function test_apply_best_coupon_with_consent_applies_the_best_code(): void {
		Fahad_AI_Checkout_Tools_Stub::$cart        = $this->cartSnapshot( [ 'subtotal' => '100.00', 'total' => '100.00' ] );
		Fahad_AI_Checkout_Tools_Stub::$candidates  = [
			[ 'code' => 'FIVE',   'saving' => 5.0,  'description' => '$5 off' ],
			[ 'code' => 'TWENTY', 'saving' => 20.0, 'description' => '20% off' ],
		];
		Fahad_AI_Checkout_Tools_Stub::$apply_result = true; // WC accepts it.

		$result = $this->dispatch( 'apply_best_coupon', [ 'confirm' => true ] );

		$this->assertTrue( $result['applied'] );
		$this->assertSame( 'TWENTY', $result['code'] );
		// Consent given → the apply seam ran with EXACTLY the best code.
		$this->assertSame( [ 'TWENTY' ], Fahad_AI_Checkout_Tools_Stub::$applied_codes );
	}

	public function test_apply_best_coupon_reports_error_if_woocommerce_rejects_on_apply(): void {
		// Consent given, but WC's own apply_coupon rejects at apply time (race / edge):
		// we must surface an honest failure, NOT claim a discount we didn't get.
		Fahad_AI_Checkout_Tools_Stub::$cart         = $this->cartSnapshot();
		Fahad_AI_Checkout_Tools_Stub::$candidates   = [
			[ 'code' => 'SAVE20', 'saving' => 20.0, 'description' => '20% off' ],
		];
		Fahad_AI_Checkout_Tools_Stub::$apply_result = false; // WC says no.

		$result = $this->dispatch( 'apply_best_coupon', [ 'confirm' => true ] );

		$this->assertNotTrue( $result['applied'] ?? false );
		$this->assertArrayHasKey( 'error', $result );
		$this->assertSame( [ 'SAVE20' ], Fahad_AI_Checkout_Tools_Stub::$applied_codes ); // it tried
	}

	public function test_apply_best_coupon_never_invents_a_code_when_none_apply(): void {
		// No genuinely valid+applicable coupon → the tool must say so, never make one up,
		// and never touch the cart (even WITH consent).
		Fahad_AI_Checkout_Tools_Stub::$cart       = $this->cartSnapshot();
		Fahad_AI_Checkout_Tools_Stub::$candidates = [];

		$result = $this->dispatch( 'apply_best_coupon', [ 'confirm' => true ] );

		$this->assertFalse( $result['applied'] );
		$this->assertArrayNotHasKey( 'code', $result );
		$this->assertArrayHasKey( 'message', $result );
		$this->assertMatchesRegularExpression( '/(no|none|couldn\'t find|no applicable|no valid)/i', $result['message'] );
		$this->assertSame( [], Fahad_AI_Checkout_Tools_Stub::$applied_codes );
	}

	public function test_apply_best_coupon_respects_a_stated_budget(): void {
		// If the shopper stated a budget and applying the best coupon would still keep
		// the total within it, we proceed; but a coupon must never PUSH a recommendation
		// that contradicts a budget cap. Here the best coupon's saving brings the total
		// under budget, it should still be the recommended code (the budget is a ceiling
		// on spend, not a reason to hide a real saving). This guards the budget plumbing.
		Fahad_AI_Checkout_Tools_Stub::$cart       = $this->cartSnapshot( [ 'subtotal' => '60.00', 'total' => '60.00' ] );
		Fahad_AI_Checkout_Tools_Stub::$candidates = [
			[ 'code' => 'TEN', 'saving' => 10.0, 'description' => '$10 off' ],
		];

		$result = $this->dispatch( 'apply_best_coupon', [ 'budget' => 55 ] );

		$this->assertSame( 'TEN', $result['code'] );
		// Projected total after the saving is surfaced so the agent can reason about budget.
		$this->assertArrayHasKey( 'projected_total', $result );
		$this->assertEqualsWithDelta( 50.0, (float) $result['projected_total'], 0.001 );
	}

	// ── PCI boundary: handoff returns the URL, touches NO card data ──────────────

	public function test_summary_handoff_returns_checkout_url_and_touches_no_card_data(): void {
		Fahad_AI_Checkout_Tools_Stub::$cart     = $this->cartSnapshot();
		Fahad_AI_Checkout_Tools_Stub::$shipping = $this->shippingSnapshot(
			[ [ 'id' => 'flat_rate:1', 'label' => 'Flat rate', 'cost' => '5.00' ] ],
			'flat_rate:1'
		);

		$result = $this->dispatch( 'get_checkout_summary' );

		// The loop ENDS at the WooCommerce checkout handoff: a URL, nothing more.
		$this->assertSame( 'https://shop.test/checkout/', $result['checkout_url'] );

		// Absolutely no payment / card fields anywhere in the result, the PCI boundary.
		$this->assertResultHasNoCardData( $result );
	}

	public function test_no_checkout_tool_accepts_payment_or_card_parameters(): void {
		// The tool SPECS must not invite the model to collect card data: no parameter
		// resembling a card number / cvc / expiry / payment field exists on any tool.
		$specs = array_column( $this->registry()->specs(), null, 'name' );

		foreach ( [ 'get_checkout_summary', 'set_shipping_method', 'apply_best_coupon' ] as $name ) {
			$props = array_keys( (array) ( $specs[ $name ]['parameters']['properties'] ?? [] ) );
			foreach ( $props as $param ) {
				$this->assertDoesNotMatchRegularExpression(
					'/(card|cvc|cvv|pan|expir|payment|billing|cardholder|account_number)/i',
					(string) $param,
					"Checkout tool {$name} must not expose a payment/card parameter ({$param})."
				);
			}
		}
	}

	public function test_set_shipping_method_result_touches_no_card_data(): void {
		Fahad_AI_Checkout_Tools_Stub::$cart     = $this->cartSnapshot();
		Fahad_AI_Checkout_Tools_Stub::$shipping = $this->shippingSnapshot(
			[ [ 'id' => 'flat_rate:1', 'label' => 'Flat rate', 'cost' => '5.00' ] ],
			'flat_rate:1'
		);

		$result = $this->dispatch( 'set_shipping_method', [ 'method_id' => 'flat_rate:1' ] );

		$this->assertResultHasNoCardData( $result );
	}

	/** Recursively assert a tool result contains no payment/card-shaped keys. */
	private function assertResultHasNoCardData( array $result ): void {
		array_walk_recursive(
			$result,
			function ( $value, $key ): void {
				$this->assertDoesNotMatchRegularExpression(
					'/(card_number|cardholder|\bcvc\b|\bcvv\b|\bpan\b|expir|payment_method_token|account_number)/i',
					(string) $key,
					"Result key '{$key}' looks like card/payment data, the PCI boundary forbids it."
				);
			}
		);
	}

	// ── seam-internal helpers (hit the REAL class directly) ──────────────────────

	public function test_best_candidate_picks_max_saving(): void {
		$best = $this->invokePrivate( 'best_candidate', [
			[ 'code' => 'A', 'saving' => 3.0 ],
			[ 'code' => 'B', 'saving' => 9.0 ],
			[ 'code' => 'C', 'saving' => 7.0 ],
		] );

		$this->assertSame( 'B', $best['code'] );
	}

	public function test_best_candidate_returns_null_for_empty(): void {
		$this->assertNull( $this->invokePrivate( 'best_candidate', [] ) );
	}

	public function test_money_formats_plain_symbol_no_html(): void {
		$this->assertSame( '$10', $this->invokePrivate( 'money', '10.00' ) );
		$this->assertSame( '$12.50', $this->invokePrivate( 'money', '12.5' ) );
	}

	// ── error isolation: a throwing seam never fatals the request ────────────────

	public function test_dispatch_isolates_a_throwing_seam(): void {
		Fahad_AI_Checkout_Tools_Stub::$throw = true;

		$result = $this->dispatch( 'get_checkout_summary' );

		$this->assertArrayHasKey( 'error', $result );
	}
}

/**
 * Test seam: overrides the WC-touching methods so the summary / shipping-selection /
 * best-coupon / consent logic runs without a live WooCommerce stack. This is the
 * "injectable seam" the shipping pack established, production code stays decoupled
 * from WC internals behind these protected-static methods; no production code
 * subclasses Fahad_AI_Checkout_Tools.
 */
class Fahad_AI_Checkout_Tools_Stub extends Fahad_AI_Checkout_Tools {

	/** @var array Canned cart snapshot. */
	public static array $cart = [];

	/** @var array|null Canned resolved-shipping snapshot (null = unavailable). */
	public static ?array $shipping = null;

	/** @var array<int,array> Canned valid+applicable coupon candidates (code/saving/description). */
	public static array $candidates = [];

	/** @var bool Whether the WC apply_coupon seam should report success. */
	public static bool $apply_result = true;

	/** @var bool When true the first seam throws, to prove dispatch() isolation. */
	public static bool $throw = false;

	/** @var string|null The method id the production code asked the session to select. */
	public static ?string $selected_method = null;

	/** @var array<int,string> Codes the production code asked WC to apply, in order. */
	public static array $applied_codes = [];

	public static function reset_stub(): void {
		self::$cart            = [];
		self::$shipping        = null;
		self::$candidates      = [];
		self::$apply_result    = true;
		self::$throw           = false;
		self::$selected_method = null;
		self::$applied_codes   = [];
	}

	protected static function read_cart(): array {
		if ( self::$throw ) {
			throw new RuntimeException( 'simulated WC failure' );
		}
		return self::$cart;
	}

	protected static function resolve_shipping(): ?array {
		return self::$shipping;
	}

	protected static function select_shipping_method( string $method_id ): bool {
		self::$selected_method = $method_id;
		return true;
	}

	protected static function candidate_coupons(): array {
		return self::$candidates;
	}

	protected static function apply_coupon_code( string $code ): bool {
		self::$applied_codes[] = $code;
		return self::$apply_result;
	}
}
