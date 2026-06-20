<?php
/**
 * Supplemental line-coverage tests for Fahad_AI_Shipping_Tools (issue #19).
 *
 * The primary suite (ShippingToolsTest) drives the tool through its overridable
 * seam (a stub subclass that replaces resolve_zone_methods() with canned data),
 * so the REAL seam body — the only method that touches the WooCommerce shipping
 * stack — and a couple of private-helper branches it feeds never execute there.
 *
 * This file closes those gaps WITHOUT a live WooCommerce install by exercising
 * the REAL Fahad_AI_Shipping_Tools class against the WC_Shipping_* CLASS stubs
 * the test bootstrap already defines (tests/stubs/wc-stubs.php): the stub
 * WC_Shipping_Zones::get_zone_matching_package() returns a concrete zone with a
 * real flat_rate WC_Shipping_Method, so resolve_zone_methods() runs end to end
 * and normalizes it. The remaining private helpers (method_title / method_cost /
 * method_delivery_window) are reached by reflection against tiny method doubles
 * that expose exactly the surface each branch reads.
 *
 * Conventions mirror ShippingToolsTest / ApiHandlerTest: Brain\Monkey for WP fn
 * stubs, MockeryPHPUnitIntegration for mock verification, and ReflectionMethod to
 * reach the private/protected static seam + helpers.
 */

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;

class CoverageShippingToolsTest extends TestCase {

	use MockeryPHPUnitIntegration;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		Functions\stubs( [
			'sanitize_text_field' => fn( $s ) => is_string( $s ) ? trim( $s ) : $s,
			'absint'              => fn( $n ) => abs( (int) $n ),
			'get_option'          => fn( $key, $default = '' ) => $default,
		] );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/** Invoke a private/protected static method on the REAL pack class via reflection. */
	private function invokeStatic( string $method, ...$args ) {
		$ref = new ReflectionMethod( Fahad_AI_Shipping_Tools::class, $method );
		return $ref->invokeArgs( null, $args );
	}

	// ── the REAL resolve_zone_methods() seam (the WooCommerce-touching body) ──────
	// ShippingToolsTest overrides this method, so its real body never runs there.
	// Here we run it against the bootstrap's WC_Shipping_* class stubs: the stub
	// WC_Shipping_Zones returns a zone with one flat_rate method at 5.00, so the
	// loop builds and normalizes a descriptor from a genuine method object.

	public function test_resolve_zone_methods_normalizes_real_zone_methods(): void {
		// Guard: this exercise only makes sense if the bootstrap WC class stubs are
		// present (they are — see tests/stubs/wc-stubs.php).
		$this->assertTrue( class_exists( 'WC_Shipping_Zones' ) );

		$package = [
			'destination'   => [ 'country' => 'US', 'state' => 'CA', 'postcode' => '90210' ],
			'contents'      => [],
			'contents_cost' => 0,
			'product_id'    => 0,
		];

		$descriptors = $this->invokeStatic( 'resolve_zone_methods', $package );

		// The stub zone exposes exactly one enabled flat_rate method costing 5.00
		// with no delivery window — normalized to the tool's descriptor shape.
		$this->assertIsArray( $descriptors );
		$this->assertCount( 1, $descriptors );

		$first = $descriptors[0];
		$this->assertSame( 'flat_rate', $first['id'] );
		$this->assertSame( 'Flat rate', $first['title'] );   // from get_method_title()
		$this->assertSame( '5.00', $first['cost'] );          // from get_option('cost')
		$this->assertNull( $first['delivery_window'] );        // core method exposes none
		// Descriptor carries exactly the four normalized keys, nothing leaked.
		$this->assertSame(
			[ 'id', 'title', 'cost', 'delivery_window' ],
			array_keys( $first )
		);
	}

	public function test_resolve_zone_methods_returns_a_list_not_null_for_a_matched_zone(): void {
		// A matched zone with methods must yield a real array (the "[] / list" arm of
		// the seam), never null — null is reserved for "no zone matched".
		$descriptors = $this->invokeStatic( 'resolve_zone_methods', [
			'destination' => [ 'country' => 'GB', 'state' => '', 'postcode' => '' ],
		] );

		$this->assertNotNull( $descriptors );
		$this->assertIsArray( $descriptors );
		$this->assertArrayHasKey( 0, $descriptors );
	}

	// ── method_title(): instance title, with the documented fallbacks ─────────────

	public function test_method_title_prefers_get_method_title(): void {
		$method = Mockery::mock( WC_Shipping_Method::class );
		$method->shouldReceive( 'get_method_title' )->andReturn( 'Express Courier' );

		$this->assertSame( 'Express Courier', $this->invokeStatic( 'method_title', $method ) );
	}

	public function test_method_title_falls_back_to_title_property_when_method_title_blank(): void {
		// get_method_title() exists but returns '' → fall through to the ->title prop.
		$method        = Mockery::mock( WC_Shipping_Method::class );
		$method->id    = 'flat_rate:9';
		$method->title = 'Standard';
		$method->shouldReceive( 'get_method_title' )->andReturn( '' );

		$this->assertSame( 'Standard', $this->invokeStatic( 'method_title', $method ) );
	}

	public function test_method_title_falls_back_to_id_when_no_method_title_method(): void {
		// A plain object with NO get_method_title() and NO title prop → uses the id.
		$method     = new stdClass();
		$method->id = 'service_abc';

		$this->assertSame( 'service_abc', $this->invokeStatic( 'method_title', $method ) );
	}

	// ── method_cost(): the ->cost public-property arm (line 315) ──────────────────

	public function test_method_cost_reads_public_cost_property_when_no_get_option(): void {
		// A method object that exposes NO get_option() but DOES carry a scalar ->cost
		// public property: cost is read straight off the property.
		$method       = new stdClass();
		$method->id   = 'custom_rate';
		$method->cost = '9.99';

		$this->assertSame( '9.99', $this->invokeStatic( 'method_cost', $method ) );
	}

	public function test_method_cost_is_empty_when_no_option_and_no_cost_property(): void {
		// No get_option(), no ->cost property → '' (unknown), never an invented number.
		$method     = new stdClass();
		$method->id = 'dynamic_rate';

		$this->assertSame( '', $this->invokeStatic( 'method_cost', $method ) );
	}

	public function test_method_cost_get_option_blank_falls_through_to_cost_property(): void {
		// get_option('cost') returns '' (not usable) → fall through to scalar ->cost.
		$method       = Mockery::mock( WC_Shipping_Method::class );
		$method->id   = 'flat_rate';
		$method->cost = '3.25';
		$method->shouldReceive( 'get_option' )->with( 'cost' )->andReturn( '' );

		$this->assertSame( '3.25', $this->invokeStatic( 'method_cost', $method ) );
	}

	// ── method_delivery_window(): the no-get_option() guard (line 334) ────────────

	public function test_method_delivery_window_is_null_without_get_option(): void {
		// A method object that does not expose get_option() cannot carry a window →
		// null, never guessed.
		$method     = new stdClass();
		$method->id = 'no_options_method';

		$this->assertNull( $this->invokeStatic( 'method_delivery_window', $method ) );
	}

	public function test_method_delivery_window_reads_delivery_days_option(): void {
		// Coverage for the loop walking past delivery_time to a later option key.
		$method = Mockery::mock( WC_Shipping_Method::class );
		$method->shouldReceive( 'get_option' )->with( 'delivery_time' )->andReturn( '' );
		$method->shouldReceive( 'get_option' )->with( 'delivery_days' )->andReturn( '  3-5 days  ' );
		$method->shouldReceive( 'get_option' )->andReturn( '' );

		// Value is trimmed.
		$this->assertSame( '3-5 days', $this->invokeStatic( 'method_delivery_window', $method ) );
	}

	// ── shape_methods(): the non-array element guard (line 207 `continue`) ────────

	public function test_shape_methods_skips_non_array_entries(): void {
		// A malformed descriptor list (scalars mixed in) must be skipped, not fatal:
		// only the well-formed array entry survives, normalized.
		$shaped = $this->invokeStatic( 'shape_methods', [
			'not-an-array',
			42,
			null,
			[ 'id' => 'flat_rate:1', 'title' => 'Flat rate', 'cost' => '5.00', 'delivery_window' => '' ],
		] );

		$this->assertCount( 1, $shaped );
		$this->assertSame( 'flat_rate:1', $shaped[0]['id'] );
		$this->assertSame( 'Flat rate', $shaped[0]['title'] );
		$this->assertSame( '5.00', $shaped[0]['cost'] );
		// Blank window string is normalized to null (never an empty string).
		$this->assertNull( $shaped[0]['delivery_window'] );
	}
}
