<?php
/**
 * Unit tests for Fahad_AI_Shipping_Tools (issue #19: shipping & delivery estimate).
 *
 * Red → Green → Refactor. Conventions mirror CatalogToolsTest: WP/WC functions
 * mocked via Brain\Monkey, singletons reset via reflection, and the pack's REAL
 * provider registered through Fahad_AI_Tool_Registry::register_pack() so the
 * production registration + merge + dispatch path is what is under test.
 *
 * The WooCommerce shipping API is awkward to mock, WC_Shipping_Zones is a
 * concrete class with a STATIC matcher and zone/method OBJECTS, none of which
 * Brain\Monkey (a FUNCTION mocker) can intercept. So the pack isolates every WC
 * shipping touch behind one overridable seam, Fahad_AI_Shipping_Tools::resolve_zone_methods(),
 * which returns either a normalized list of { id, title, cost, delivery_window }
 * descriptors or null (no matching zone). These tests drive a tiny subclass that
 * overrides that seam with canned data, so the cost-shaping, window-derivation,
 * and fallback logic are exercised WITHOUT a live WooCommerce shipping stack.
 */

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;

class ShippingToolsTest extends TestCase {

	use MockeryPHPUnitIntegration;

	/**
	 * Snapshot of the registry's static pack providers, restored in tearDown so a
	 * test here neither inherits another suite's packs nor leaks the pack we
	 * register for our own cases.
	 *
	 * @var array<int, callable>
	 */
	private array $pack_snapshot = [];

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$this->pack_snapshot = (array) ( new ReflectionProperty( Fahad_AI_Tool_Registry::class, 'pack_providers' ) )->getValue();

		// Seam-test scratch state cleared between cases.
		Fahad_AI_Shipping_Tools_Stub::$zone_methods = null;
		Fahad_AI_Shipping_Tools_Stub::$captured_package = null;

		Functions\stubs( [
			'sanitize_text_field' => fn( $s ) => is_string( $s ) ? trim( $s ) : $s,
			'absint'              => fn( $n ) => abs( (int) $n ),
			'wc_format_localized_price' => fn( $p ) => (string) $p,
			// Registry get_tools() reads the merchant tool-gating option (issue #56);
			// default (no disabled tools) so dispatch()/specs() are unaffected. (This is
			// the GLOBAL get_option; the WC_Shipping_Method->get_option() the tests mock
			// is a method on a Mockery object, unaffected by this function stub.)
			'get_option'          => fn( $key, $default = '' ) => $default,
		] );
	}

	protected function tearDown(): void {
		( new ReflectionProperty( Fahad_AI_Tool_Registry::class, 'pack_providers' ) )->setValue( null, $this->pack_snapshot );
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * Fresh registry whose built tool list includes the shipping tools, registered
	 * via the pack's REAL provider, exactly what the file-scope self-registration
	 * does in production.
	 */
	private function registry(): Fahad_AI_Tool_Registry {
		( new ReflectionProperty( Fahad_AI_Tools::class, 'instance' ) )->setValue( null, null );
		( new ReflectionProperty( Fahad_AI_Tool_Registry::class, 'instance' ) )->setValue( null, null );

		Fahad_AI_Tool_Registry::reset_packs();
		Fahad_AI_Tool_Registry::register_pack( [ 'Fahad_AI_Shipping_Tools', 'register' ] );

		return Fahad_AI_Tool_Registry::instance();
	}

	// ── registration ──────────────────────────────────────────────────────────

	public function test_shipping_tool_is_registered_via_register_pack(): void {
		$names = array_column( $this->registry()->specs(), 'name' );

		$this->assertContains( 'estimate_delivery', $names );
		// Additive, the six built-ins remain.
		$this->assertContains( 'search_products', $names );
		$this->assertCount( 7, $names );
	}

	public function test_shipping_tool_spec_never_leaks_a_callback(): void {
		$specs = array_column( $this->registry()->specs(), null, 'name' );

		$this->assertArrayHasKey( 'estimate_delivery', $specs );
		$this->assertArrayNotHasKey( 'callback', $specs['estimate_delivery'] );
		$this->assertArrayHasKey( 'description', $specs['estimate_delivery'] );
		$this->assertSame( 'object', $specs['estimate_delivery']['parameters']['type'] );
		$this->assertArrayHasKey( 'country', $specs['estimate_delivery']['parameters']['properties'] );
	}

	public function test_shipping_tool_is_not_personal(): void {
		// Shipping rates are not customer-specific data, so the tool must NOT be
		// login-gated (no `personal` flag), guests asking "how much is shipping?"
		// must get an answer.
		$get_tools = new ReflectionMethod( Fahad_AI_Tool_Registry::class, 'get_tools' );
		$built     = $get_tools->invoke( $this->registry() );

		$this->assertArrayHasKey( 'estimate_delivery', $built );
		$this->assertEmpty( $built['estimate_delivery']['personal'] ?? null );
	}

	// ── known destination → methods + costs ─────────────────────────────────────

	public function test_returns_methods_and_costs_for_known_destination(): void {
		$this->stubZoneMethods( [
			[ 'id' => 'flat_rate:1',    'title' => 'Flat rate',     'cost' => '5.00', 'delivery_window' => null ],
			[ 'id' => 'free_shipping:2', 'title' => 'Free shipping', 'cost' => '0.00', 'delivery_window' => null ],
		] );

		$result = $this->dispatch( [ 'country' => 'US', 'state' => 'CA', 'postcode' => '90210' ] );

		$this->assertTrue( $result['available'] );
		$this->assertCount( 2, $result['methods'] );
		$this->assertSame( 'Flat rate', $result['methods'][0]['title'] );
		$this->assertSame( '5.00', $result['methods'][0]['cost'] );
		$this->assertSame( 'Free shipping', $result['methods'][1]['title'] );
		$this->assertSame( '0.00', $result['methods'][1]['cost'] );
	}

	public function test_destination_is_passed_into_the_package(): void {
		$this->stubZoneMethods( [
			[ 'id' => 'flat_rate:1', 'title' => 'Flat rate', 'cost' => '5.00', 'delivery_window' => null ],
		] );

		$this->dispatch( [ 'country' => 'gb', 'state' => 'ENG', 'postcode' => 'SW1A 1AA' ] );

		$package = Fahad_AI_Shipping_Tools_Stub::$captured_package;
		$this->assertIsArray( $package );
		$this->assertArrayHasKey( 'destination', $package );
		$this->assertSame( 'GB', $package['destination']['country'] ); // upper-cased
		$this->assertSame( 'ENG', $package['destination']['state'] );
		$this->assertSame( 'SW1A 1AA', $package['destination']['postcode'] );
	}

	// ── delivery window: only when genuinely derivable ──────────────────────────

	public function test_includes_delivery_window_only_when_method_provides_one(): void {
		$this->stubZoneMethods( [
			[ 'id' => 'flat_rate:1', 'title' => 'Express',  'cost' => '12.00', 'delivery_window' => '1-2 business days' ],
			[ 'id' => 'flat_rate:2', 'title' => 'Standard', 'cost' => '5.00',  'delivery_window' => null ],
		] );

		$result = $this->dispatch( [ 'country' => 'US' ] );

		// Express has a derivable window; surface it.
		$this->assertSame( '1-2 business days', $result['methods'][0]['delivery_window'] );
		// Standard does not; the window must be null, NOT invented.
		$this->assertNull( $result['methods'][1]['delivery_window'] );
	}

	public function test_carries_honest_note_when_no_window_is_derivable(): void {
		$this->stubZoneMethods( [
			[ 'id' => 'flat_rate:1', 'title' => 'Flat rate', 'cost' => '5.00', 'delivery_window' => null ],
		] );

		$result = $this->dispatch( [ 'country' => 'US' ] );

		// No method carries a window, so the result must say so plainly rather than
		// fabricating an ETA. WooCommerce core has no delivery-date field.
		$this->assertFalse( $result['delivery_window_available'] );
		$this->assertArrayHasKey( 'note', $result );
		$this->assertMatchesRegularExpression( '/delivery date/i', $result['note'] );
	}

	public function test_marks_window_available_when_at_least_one_method_has_one(): void {
		$this->stubZoneMethods( [
			[ 'id' => 'flat_rate:1', 'title' => 'Express', 'cost' => '12.00', 'delivery_window' => '1-2 business days' ],
		] );

		$result = $this->dispatch( [ 'country' => 'US' ] );

		$this->assertTrue( $result['delivery_window_available'] );
	}

	// ── graceful fallback: no matching zone ─────────────────────────────────────

	public function test_graceful_fallback_when_no_zone_matches(): void {
		// resolve_zone_methods returns null → no zone serves this destination.
		$this->stubZoneMethods( null );

		$result = $this->dispatch( [ 'country' => 'ZZ' ] );

		// NOT an error, NOT a fatal, NOT a fabricated cost: a clear "can't determine".
		$this->assertArrayNotHasKey( 'error', $result );
		$this->assertFalse( $result['available'] );
		$this->assertArrayNotHasKey( 'methods', $result );
		$this->assertArrayHasKey( 'message', $result );
		$this->assertMatchesRegularExpression( '/(could not|couldn\'t|unable|can\'t|cannot).*(determine|calculate)/i', $result['message'] );
	}

	public function test_graceful_fallback_when_zone_has_no_enabled_methods(): void {
		// A zone matched but has no enabled shipping methods → still can't quote.
		$this->stubZoneMethods( [] );

		$result = $this->dispatch( [ 'country' => 'US' ] );

		$this->assertArrayNotHasKey( 'error', $result );
		$this->assertFalse( $result['available'] );
		$this->assertArrayHasKey( 'message', $result );
	}

	// ── missing / invalid destination → guidance, not a guess ───────────────────

	public function test_missing_country_returns_guidance_not_error(): void {
		// Should never reach the WC seam, no country, nothing to match.
		$this->stubZoneMethods( [
			[ 'id' => 'flat_rate:1', 'title' => 'Flat rate', 'cost' => '5.00', 'delivery_window' => null ],
		] );

		$result = $this->dispatch( [] );

		$this->assertArrayNotHasKey( 'error', $result );
		$this->assertFalse( $result['available'] );
		$this->assertArrayHasKey( 'message', $result );
		$this->assertMatchesRegularExpression( '/(country|destination|where)/i', $result['message'] );
		// The seam must NOT have been consulted without a destination.
		$this->assertNull( Fahad_AI_Shipping_Tools_Stub::$captured_package );
	}

	public function test_blank_country_string_returns_guidance(): void {
		$result = $this->dispatch( [ 'country' => '   ' ] );

		$this->assertFalse( $result['available'] );
		$this->assertArrayHasKey( 'message', $result );
		$this->assertNull( Fahad_AI_Shipping_Tools_Stub::$captured_package );
	}

	// ── never fabricates: dispatch through the registry isolates throws ─────────

	public function test_dispatch_isolates_a_throwing_seam(): void {
		// If the WC seam throws, the registry's error isolation must catch it, the
		// tool must never fatal the request.
		Fahad_AI_Shipping_Tools_Stub::$zone_methods = 'throw';

		$result = $this->dispatch( [ 'country' => 'US' ] );

		$this->assertArrayHasKey( 'error', $result );
	}

	// ── WC method-object PARSING (the real seam internals) ──────────────────────
	// These hit the private helpers that read WooCommerce method objects directly
	// (the part the seam-override tests above deliberately skip), proving cost and
	// window extraction against mocked WC_Shipping_Method instances.

	public function test_method_cost_reads_flat_rate_cost_option(): void {
		$method = Mockery::mock( WC_Shipping_Method::class );
		$method->id = 'flat_rate';
		$method->shouldReceive( 'get_option' )->with( 'cost' )->andReturn( '7.50' );

		$this->assertSame( '7.50', $this->invokePrivate( 'method_cost', $method ) );
	}

	public function test_method_cost_is_zero_for_free_shipping(): void {
		// free_shipping carries no `cost` option; it must report "0", not "".
		$method = Mockery::mock( WC_Shipping_Method::class );
		$method->id = 'free_shipping';
		$method->shouldReceive( 'get_option' )->andReturn( '' );

		$this->assertSame( '0', $this->invokePrivate( 'method_cost', $method ) );
	}

	public function test_method_cost_is_empty_when_not_a_simple_option(): void {
		// A dynamic-cost method (no usable `cost` option) yields '' so the agent
		// reports the method WITHOUT inventing a number.
		$method = Mockery::mock( WC_Shipping_Method::class );
		$method->id = 'service_xyz';
		$method->shouldReceive( 'get_option' )->with( 'cost' )->andReturn( '' );

		$this->assertSame( '', $this->invokePrivate( 'method_cost', $method ) );
	}

	public function test_method_delivery_window_reads_an_exposed_option(): void {
		// A method that DOES expose a delivery-time option surfaces it verbatim.
		$method = Mockery::mock( WC_Shipping_Method::class );
		$method->shouldReceive( 'get_option' )->with( 'delivery_time' )->andReturn( '2-3 business days' );
		$method->shouldReceive( 'get_option' )->andReturn( '' );

		$this->assertSame( '2-3 business days', $this->invokePrivate( 'method_delivery_window', $method ) );
	}

	public function test_method_delivery_window_is_null_when_none_exposed(): void {
		// Stock flat_rate/free_shipping expose NO delivery field → null, never guessed.
		$method = Mockery::mock( WC_Shipping_Method::class );
		$method->shouldReceive( 'get_option' )->andReturn( '' );

		$this->assertNull( $this->invokePrivate( 'method_delivery_window', $method ) );
	}

	// ── helpers ─────────────────────────────────────────────────────────────────

	/** Invoke a private static helper on the real pack class via reflection. */
	private function invokePrivate( string $method, ...$args ) {
		$ref = new ReflectionMethod( Fahad_AI_Shipping_Tools::class, $method );
		return $ref->invokeArgs( null, $args );
	}

	/**
	 * Register the OVERRIDDEN pack (the stub subclass) so the seam returns canned
	 * data, then dispatch estimate_delivery through the real registry.
	 */
	private function dispatch( array $input ): array {
		( new ReflectionProperty( Fahad_AI_Tools::class, 'instance' ) )->setValue( null, null );
		( new ReflectionProperty( Fahad_AI_Tool_Registry::class, 'instance' ) )->setValue( null, null );

		Fahad_AI_Tool_Registry::reset_packs();
		Fahad_AI_Tool_Registry::register_pack( [ 'Fahad_AI_Shipping_Tools_Stub', 'register' ] );

		return Fahad_AI_Tool_Registry::instance()->dispatch( 'estimate_delivery', $input );
	}

	private function stubZoneMethods( ?array $methods ): void {
		Fahad_AI_Shipping_Tools_Stub::$zone_methods = $methods;
	}
}

/**
 * Test seam: overrides the single WC-touching method so the cost/window/fallback
 * logic runs without a live WooCommerce shipping stack. This is the "injectable
 * seam" the recipe calls for, production code stays decoupled from WC internals
 * behind resolve_zone_methods().
 */
class Fahad_AI_Shipping_Tools_Stub extends Fahad_AI_Shipping_Tools {

	/** @var array<int,array>|string|null Canned descriptors, 'throw', or null (no zone). */
	public static $zone_methods = null;

	/** @var array|null The package the production code built (assert destination shaping). */
	public static $captured_package = null;

	protected static function resolve_zone_methods( array $package ): ?array {
		self::$captured_package = $package;

		if ( 'throw' === self::$zone_methods ) {
			throw new RuntimeException( 'simulated WC failure' );
		}

		return self::$zone_methods;
	}
}
