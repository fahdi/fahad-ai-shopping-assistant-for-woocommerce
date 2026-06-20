<?php
/**
 * Supplemental line-coverage tests for Fahad_AI_Checkout_Tools.
 *
 * The sibling CheckoutToolsTest drives the pure shaping / decision logic through a
 * stub subclass that OVERRIDES the five WooCommerce seams, so the REAL seam bodies
 * (read_cart, resolve_shipping, select_shipping_method, candidate_coupons,
 * apply_coupon_code) plus the shared session plumbing (cart(), persist_session())
 * never execute. This suite exercises those seams DIRECTLY on the real class via
 * reflection, mocking the concrete WC surfaces (WC(), WC_Cart, WC()->session,
 * WC()->shipping(), WC_Discounts, WC_Coupon, get_posts) through Brain\Monkey +
 * Mockery — exactly the convention CouponToolsTest established. It also fills the
 * remaining pure-helper branch gaps (non-array guards, the select-failed path of
 * set_shipping_method) so the file reaches full statement coverage.
 *
 * Every assertion checks real behaviour: the normalised snapshot shapes the seams
 * return, the honest empty/degraded states when WC is unavailable, the session
 * mutation side effects, and the candidate filtering (already-applied / invalid /
 * zero-saving) the real candidate_coupons performs.
 */

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;

class CoverageCheckoutToolsTest extends TestCase {

	use MockeryPHPUnitIntegration;

	/** @var array<int, callable> Snapshot of the registry pack list, restored in tearDown. */
	private array $pack_snapshot = [];

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$this->pack_snapshot = (array) ( new ReflectionProperty( Fahad_AI_Tool_Registry::class, 'pack_providers' ) )->getValue();

		Fahad_AI_Checkout_Tools_SelectFails_Stub::reset_stub();

		Functions\stubs( [
			'sanitize_text_field' => fn( $s ) => is_string( $s ) ? trim( $s ) : $s,
			'wp_strip_all_tags'   => fn( $s ) => strip_tags( (string) $s ),
			'wc_format_decimal'   => fn( $n ) => (string) $n,
			'get_woocommerce_currency_symbol' => fn() => '$',
			'wc_get_checkout_url' => fn() => 'https://shop.test/checkout/',
			'wc_get_cart_url'     => fn() => 'https://shop.test/cart/',
			'get_option'          => fn( $key, $default = '' ) => $default,
			// cart() defensively loads the session cart when WC()->cart is absent. Stub it
			// as a no-op so the guard is exercised without a live WC; Patchwork keeps the
			// function defined process-wide once seen, so a global stub avoids the
			// "not mocked in this test" error in later cases where the cart is null.
			'wc_load_cart'        => fn() => null,
		] );
	}

	protected function tearDown(): void {
		( new ReflectionProperty( Fahad_AI_Tool_Registry::class, 'pack_providers' ) )->setValue( null, $this->pack_snapshot );
		Monkey\tearDown();
		parent::tearDown();
	}

	// ── reflection helpers ───────────────────────────────────────────────────────

	/** Invoke a private/protected static method on the REAL pack class. */
	private function invoke( string $method, ...$args ) {
		return ( new ReflectionMethod( Fahad_AI_Checkout_Tools::class, $method ) )->invokeArgs( null, $args );
	}

	/**
	 * Dispatch a checkout tool through the REAL registry against the named pack
	 * provider class (defaults to the seam-failing stub used for the select path).
	 */
	private function dispatch( string $providerClass, string $tool, array $input = [] ): array {
		( new ReflectionProperty( Fahad_AI_Tools::class, 'instance' ) )->setValue( null, null );
		( new ReflectionProperty( Fahad_AI_Tool_Registry::class, 'instance' ) )->setValue( null, null );

		Fahad_AI_Tool_Registry::reset_packs();
		Fahad_AI_Tool_Registry::register_pack( [ $providerClass, 'register' ] );

		return Fahad_AI_Tool_Registry::instance()->dispatch( $tool, $input );
	}

	/**
	 * A fake WC() container exposing the cart property, session property and
	 * shipping() method the seams touch. Any of them may be null to drive a guard.
	 */
	private function fakeWc( $cart = null, $session = null, $shipping = '__none__' ) {
		$wc = Mockery::mock();
		$wc->cart = $cart;
		$wc->session = $session;
		if ( '__none__' !== $shipping ) {
			$wc->shouldReceive( 'shipping' )->andReturn( $shipping );
		}
		return $wc;
	}

	/** A concrete fake session so the seams' method_exists(save_data/get/set) guards pass. */
	private function fakeSession( array $initialChosen = [] ): Fahad_AI_Checkout_Coverage_Fake_Session {
		return new Fahad_AI_Checkout_Coverage_Fake_Session( $initialChosen );
	}

	// ── pure helper branch gaps ──────────────────────────────────────────────────

	public function test_shape_items_non_array_input_returns_empty(): void {
		$this->assertSame( [], $this->invoke( 'shape_items', 'not-an-array' ) );
		$this->assertSame( [], $this->invoke( 'shape_items', null ) );
	}

	public function test_shape_items_skips_non_array_entries(): void {
		$shaped = $this->invoke( 'shape_items', [
			'garbage-scalar',
			[ 'name' => 'Hat', 'quantity' => 2, 'line_total' => '20.00' ],
		] );

		// The scalar item is dropped; only the real array item is echoed.
		$this->assertCount( 1, $shaped );
		$this->assertSame( 'Hat', $shaped[0]['name'] );
		$this->assertSame( 2, $shaped[0]['quantity'] );
		$this->assertSame( '20.00', $shaped[0]['line_total'] );
	}

	public function test_shape_shipping_skips_non_array_methods_and_nulls_blank_chosen(): void {
		$out = $this->invoke( 'shape_shipping', [
			'needed'        => true,
			'chosen_method' => '', // blank → must collapse to null, never an empty string.
			'methods'       => [
				'bogus-scalar',
				[ 'id' => 'flat_rate:1', 'label' => 'Flat rate', 'cost' => '5.00' ],
			],
		] );

		$this->assertTrue( $out['needed'] );
		$this->assertNull( $out['chosen_method'] );
		// The scalar method is dropped; only the real one survives, fully shaped.
		$this->assertCount( 1, $out['methods'] );
		$this->assertSame( 'flat_rate:1', $out['methods'][0]['id'] );
		$this->assertSame( 'Flat rate', $out['methods'][0]['label'] );
		$this->assertSame( '5.00', $out['methods'][0]['cost'] );
	}

	public function test_best_candidate_skips_entries_without_a_code(): void {
		$best = $this->invoke( 'best_candidate', [
			'scalar-not-array',
			[ 'saving' => 99.0 ],                  // no 'code' → skipped despite huge saving
			[ 'code' => 'REAL', 'saving' => 4.0 ],
		] );

		$this->assertSame( 'REAL', $best['code'] );
	}

	// ── set_shipping_method: the select seam reports failure (lines 204-207) ──────

	public function test_set_shipping_method_surfaces_select_failure(): void {
		// A method that IS offered, but the (real) select seam returns false — the tool
		// must report an honest failure, not a faked success.
		Fahad_AI_Checkout_Tools_SelectFails_Stub::$shipping = [
			'needed'        => true,
			'chosen_method' => null,
			'methods'       => [ [ 'id' => 'flat_rate:1', 'label' => 'Flat rate', 'cost' => '5.00' ] ],
		];

		$result = $this->dispatch( 'Fahad_AI_Checkout_Tools_SelectFails_Stub', 'set_shipping_method', [ 'method_id' => 'flat_rate:1' ] );

		$this->assertFalse( $result['success'] );
		$this->assertArrayHasKey( 'error', $result );
		$this->assertArrayNotHasKey( 'chosen_method', $result );
	}

	// ── read_cart seam (lines 467-497, cart() 679-688) ───────────────────────────

	public function test_read_cart_returns_empty_when_no_cart(): void {
		// WC()->cart is null and not a WC_Cart → cart() yields null → honest empty state.
		Functions\when( 'WC' )->justReturn( $this->fakeWc( null ) );

		$snap = $this->invoke( 'read_cart' );

		$this->assertTrue( $snap['empty'] );
		$this->assertArrayNotHasKey( 'items', $snap );
	}

	public function test_cart_loads_the_session_cart_when_absent(): void {
		// wc_load_cart() exists (stubbed) and WC()->cart is empty → cart() defensively
		// invokes the loader (line 681). Our load is a no-op so the cart stays null →
		// honest empty state, proving the load attempt never fabricates a cart.
		Functions\when( 'WC' )->justReturn( $this->fakeWc( null ) );

		$snap = $this->invoke( 'read_cart' );

		$this->assertTrue( $snap['empty'] );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 *
	 * In an isolated process where WooCommerce is entirely absent (the WC() function
	 * is never defined), cart() must degrade to null rather than fatal — so the seam
	 * returns an honest empty state. Run in a separate process so no other case's
	 * Brain\Monkey stub of WC() leaks in and defines the function.
	 */
	public function test_cart_returns_null_when_woocommerce_absent(): void {
		$this->assertFalse( function_exists( 'WC' ), 'precondition: WC() must be undefined here' );

		$snap = $this->invoke( 'read_cart' );

		$this->assertTrue( $snap['empty'] );
	}

	public function test_read_cart_returns_empty_when_cart_is_empty(): void {
		$cart = Mockery::mock( WC_Cart::class );
		$cart->shouldReceive( 'is_empty' )->andReturn( true );
		Functions\when( 'WC' )->justReturn( $this->fakeWc( $cart ) );

		$snap = $this->invoke( 'read_cart' );

		$this->assertTrue( $snap['empty'] );
	}

	public function test_read_cart_normalises_a_populated_cart(): void {
		$product = Mockery::mock( WC_Product::class );
		$product->shouldReceive( 'get_name' )->andReturn( 'Blue Hoodie' );

		// Concrete fake cart declaring get_applied_coupons / get_discount_total so the
		// production method_exists guards run their real value branches.
		$cart = new Fahad_AI_Checkout_Coverage_Fake_Cart( [
			'empty'          => false,
			'cart'           => [
				'key1' => [ 'data' => $product, 'quantity' => 3, 'line_total' => '120.00' ],
				'bad'  => 'not-an-array-item', // exercises the is_array guards on the item
			],
			'applied'        => [ 'SAVE10', 'EXTRA' ],
			'subtotal'       => '<span>$120.00</span>',
			'discount_total' => '10.00',
			'total'          => '<span>$110.00</span>',
		] );
		Functions\when( 'WC' )->justReturn( $this->fakeWc( $cart ) );

		$snap = $this->invoke( 'read_cart' );

		$this->assertFalse( $snap['empty'] );
		$this->assertCount( 2, $snap['items'] );
		// Real product item.
		$this->assertSame( 'Blue Hoodie', $snap['items'][0]['name'] );
		$this->assertSame( 3, $snap['items'][0]['quantity'] );
		$this->assertSame( '120.00', $snap['items'][0]['line_total'] );
		// Non-array cart row → empty/zeroed fields (no fabrication, no fatal).
		$this->assertSame( '', $snap['items'][1]['name'] );
		$this->assertSame( 0, $snap['items'][1]['quantity'] );
		// Tags are stripped from the subtotal/total figures.
		$this->assertSame( '$120.00', $snap['subtotal'] );
		$this->assertSame( '$110.00', $snap['total'] );
		// Real discount + first applied coupon are surfaced (never fabricated).
		$this->assertSame( '10.00', $snap['discount_total'] );
		$this->assertSame( 'SAVE10', $snap['applied_coupon'] );
		// Currency symbol read from WC.
		$this->assertSame( '$', $snap['currency_symbol'] );
	}

	// ── resolve_shipping seam (lines 508-548) ────────────────────────────────────

	public function test_resolve_shipping_returns_null_when_no_cart(): void {
		Functions\when( 'WC' )->justReturn( $this->fakeWc( null ) );

		$this->assertNull( $this->invoke( 'resolve_shipping' ) );
	}

	public function test_resolve_shipping_not_needed_for_virtual_cart(): void {
		$cart = new Fahad_AI_Checkout_Coverage_Fake_Cart( [ 'needs_shipping' => false ] );
		Functions\when( 'WC' )->justReturn( $this->fakeWc( $cart ) );

		$snap = $this->invoke( 'resolve_shipping' );

		$this->assertFalse( $snap['needed'] );
		$this->assertSame( [], $snap['methods'] );
		$this->assertNull( $snap['chosen_method'] );
	}

	public function test_resolve_shipping_when_shipping_engine_unavailable(): void {
		// needs_shipping true, but WC()->shipping() is falsy → needed with no methods,
		// never an invented one.
		$cart = new Fahad_AI_Checkout_Coverage_Fake_Cart( [ 'needs_shipping' => true ] );
		Functions\when( 'WC' )->justReturn( $this->fakeWc( $cart, null, null ) );

		$snap = $this->invoke( 'resolve_shipping' );

		$this->assertTrue( $snap['needed'] );
		$this->assertSame( [], $snap['methods'] );
		$this->assertNull( $snap['chosen_method'] );
	}

	public function test_resolve_shipping_lists_real_rates_and_chosen_method(): void {
		// Concrete fake cart: needs_shipping true + calculate_shipping present, so the
		// production method_exists guard runs the recalculation line (not just the check).
		$cart = new Fahad_AI_Checkout_Coverage_Fake_Cart( [ 'needs_shipping' => true ] );

		// A rate object exposing get_label()/get_cost(), and a scalar rate to exercise
		// the is_object guards (label/cost fall back to '').
		$rate = new Fahad_AI_Checkout_Coverage_Fake_Rate( 'Flat rate', '5.00' );

		$session = $this->fakeSession( [ 'flat_rate:1' ] );

		$shipping = Mockery::mock();
		$shipping->shouldReceive( 'get_packages' )->andReturn( [
			[ 'rates' => [ 'flat_rate:1' => $rate, 'odd' => 'scalar-rate' ] ],
			[ 'no_rates_key' => true ], // exercises the rates-missing branch
		] );

		Functions\when( 'WC' )->justReturn( $this->fakeWc( $cart, $session, $shipping ) );

		$snap = $this->invoke( 'resolve_shipping' );

		$this->assertTrue( $snap['needed'] );
		$this->assertSame( 'flat_rate:1', $snap['chosen_method'] );
		$this->assertCount( 2, $snap['methods'] );
		$this->assertSame( 'flat_rate:1', $snap['methods'][0]['id'] );
		$this->assertSame( 'Flat rate', $snap['methods'][0]['label'] );
		$this->assertSame( '5.00', $snap['methods'][0]['cost'] );
		// Scalar rate → id echoed, label/cost blank (no fabrication).
		$this->assertSame( 'odd', $snap['methods'][1]['id'] );
		$this->assertSame( '', $snap['methods'][1]['label'] );
		$this->assertSame( '', $snap['methods'][1]['cost'] );
		// calculate_shipping was invoked on the cart before reading packages.
		$this->assertSame( 1, $cart->calcShippingCount );
	}

	public function test_resolve_shipping_without_session_has_null_chosen(): void {
		// No session object → chosen stays null; still lists methods.
		$cart = new Fahad_AI_Checkout_Coverage_Fake_Cart( [ 'needs_shipping' => true ] );

		$shipping = Mockery::mock();
		$shipping->shouldReceive( 'get_packages' )->andReturn( [] );

		Functions\when( 'WC' )->justReturn( $this->fakeWc( $cart, null, $shipping ) );

		$snap = $this->invoke( 'resolve_shipping' );

		$this->assertTrue( $snap['needed'] );
		$this->assertNull( $snap['chosen_method'] );
		$this->assertSame( [], $snap['methods'] );
	}

	// ── select_shipping_method seam (lines 561-583, persist_session 695-698) ──────

	public function test_select_shipping_method_false_without_session(): void {
		Functions\when( 'WC' )->justReturn( $this->fakeWc( null, null ) );

		$this->assertFalse( $this->invoke( 'select_shipping_method', 'flat_rate:1' ) );
	}

	public function test_select_shipping_method_sets_session_and_recalculates(): void {
		// Concrete fake session: method_exists(save_data) must pass so persist runs.
		$session = $this->fakeSession( [ 'flat_rate:1' ] );

		// Concrete fake cart declaring calculate_shipping/calculate_totals so the
		// production method_exists guards run the recalculation lines.
		$cart = new Fahad_AI_Checkout_Coverage_Fake_Cart();

		Functions\when( 'WC' )->justReturn( $this->fakeWc( $cart, $session, '__none__' ) );

		$ok = $this->invoke( 'select_shipping_method', 'free_shipping:2' );

		$this->assertTrue( $ok );
		// The chosen method is stored at index 0 of the session array WooCommerce uses.
		$this->assertSame( 'free_shipping:2', $session->get( 'chosen_shipping_methods', [] )[0] );
		// Totals were recalculated and the session persisted (survives the REST request).
		$this->assertSame( 1, $cart->calcShippingCount );
		$this->assertSame( 1, $cart->calcTotalsCount );
		$this->assertSame( 1, $session->saveCount );
	}

	// ── candidate_coupons seam (lines 593-644) ───────────────────────────────────

	public function test_candidate_coupons_empty_when_cart_unavailable(): void {
		Functions\when( 'WC' )->justReturn( $this->fakeWc( null ) );

		$this->assertSame( [], $this->invoke( 'candidate_coupons' ) );
	}

	public function test_candidate_coupons_empty_when_discounts_class_unavailable(): void {
		// wc-stubs deliberately leaves WC_Discounts undefined in the default process, so
		// the class_exists guard short-circuits to an honest empty list — we never
		// re-implement WC's validation.
		if ( class_exists( 'WC_Discounts', false ) ) {
			$this->markTestSkipped( 'WC_Discounts already loaded in this process.' );
		}

		$cart = Mockery::mock( WC_Cart::class );
		$cart->shouldReceive( 'is_empty' )->andReturn( false );
		Functions\when( 'WC' )->justReturn( $this->fakeWc( $cart ) );

		$this->assertSame( [], $this->invoke( 'candidate_coupons' ) );
	}

	public function test_candidate_coupons_empty_when_cart_empty(): void {
		// is_empty true short-circuits to [] (the cart guard's second clause).
		$cart = Mockery::mock( WC_Cart::class );
		$cart->shouldReceive( 'is_empty' )->andReturn( true );
		Functions\when( 'WC' )->justReturn( $this->fakeWc( $cart ) );

		$this->assertSame( [], $this->invoke( 'candidate_coupons' ) );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 *
	 * Drives the enumeration loop with WC_Discounts PRESENT (overloaded in a separate
	 * process, since wc-stubs deliberately leaves it undefined so it CAN be overloaded
	 * argument-free here). The post list mixes object posts (post_title = code) and a
	 * bare string code, exercising both code-extraction branches. Each new WC_Coupon()
	 * resolves to the bootstrap stub (get_id() === 0), so every candidate is skipped at
	 * the invalid-id guard → an honest empty list, with the loop, already-applied map,
	 * code extraction and skip path all executed.
	 */
	public function test_candidate_coupons_enumerates_and_skips_invalid_ids(): void {
		Mockery::mock( 'overload:WC_Discounts' ); // make class_exists('WC_Discounts') true

		$cart = Mockery::mock( WC_Cart::class );
		$cart->shouldReceive( 'is_empty' )->andReturn( false );
		$cart->shouldReceive( 'get_applied_coupons' )->andReturn( [ 'OLD' ] );
		Functions\when( 'WC' )->justReturn( (object) [ 'cart' => $cart, 'session' => null ] );

		Functions\when( 'get_posts' )->justReturn( [
			(object) [ 'post_title' => 'ALPHA' ], // object post → code from post_title
			'BETA',                                // bare string → code from the string itself
		] );

		// Every stub WC_Coupon has get_id() === 0 → both are skipped at the invalid-id
		// guard, yielding an honest empty candidate list (no fabrication).
		$this->assertSame( [], $this->invoke( 'candidate_coupons' ) );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 *
	 * get_posts returns a non-list (the production guard treats empty/non-array as
	 * "no coupons"). WC_Discounts present so we reach the get_posts call.
	 */
	public function test_candidate_coupons_empty_when_get_posts_returns_empty(): void {
		Mockery::mock( 'overload:WC_Discounts' );

		$cart = Mockery::mock( WC_Cart::class );
		$cart->shouldReceive( 'is_empty' )->andReturn( false );
		Functions\when( 'WC' )->justReturn( (object) [ 'cart' => $cart, 'session' => null ] );
		Functions\when( 'get_posts' )->justReturn( [] );

		$this->assertSame( [], $this->invoke( 'candidate_coupons' ) );
	}

	// ── apply_coupon_code seam (lines 653-665) ───────────────────────────────────

	public function test_apply_coupon_code_false_when_no_cart(): void {
		Functions\when( 'WC' )->justReturn( $this->fakeWc( null ) );

		$this->assertFalse( $this->invoke( 'apply_coupon_code', 'SAVE10' ) );
	}

	public function test_apply_coupon_code_applies_and_persists_on_success(): void {
		// Concrete fake session: method_exists(save_data) must pass for persist to run.
		$session = $this->fakeSession();

		$cart = Mockery::mock( WC_Cart::class );
		$cart->shouldReceive( 'apply_coupon' )->with( 'SAVE10' )->once()->andReturn( true );

		Functions\when( 'WC' )->justReturn( $this->fakeWc( $cart, $session ) );

		$this->assertTrue( $this->invoke( 'apply_coupon_code', 'SAVE10' ) );
		// Applied → the session was persisted so the discount survives the REST request.
		$this->assertSame( 1, $session->saveCount );
	}

	public function test_apply_coupon_code_does_not_persist_on_rejection(): void {
		$session = $this->fakeSession();

		$cart = Mockery::mock( WC_Cart::class );
		$cart->shouldReceive( 'apply_coupon' )->with( 'BOGUS' )->once()->andReturn( false );

		Functions\when( 'WC' )->justReturn( $this->fakeWc( $cart, $session ) );

		$this->assertFalse( $this->invoke( 'apply_coupon_code', 'BOGUS' ) );
		// Rejected → nothing persisted (no faked win).
		$this->assertSame( 0, $session->saveCount );
	}
}

/**
 * Seam stub whose select_shipping_method reports FAILURE, to exercise the
 * set_shipping_method select-failed branch. All other seams return canned data via
 * the static fields, mirroring the sibling stub.
 */
class Fahad_AI_Checkout_Tools_SelectFails_Stub extends Fahad_AI_Checkout_Tools {

	public static array $cart = [];
	public static ?array $shipping = null;

	public static function reset_stub(): void {
		self::$cart     = [];
		self::$shipping = null;
	}

	protected static function read_cart(): array {
		return self::$cart;
	}

	protected static function resolve_shipping(): ?array {
		return self::$shipping;
	}

	protected static function select_shipping_method( string $method_id ): bool {
		return false; // the store could not set it just now.
	}

	protected static function candidate_coupons(): array {
		return [];
	}

	protected static function apply_coupon_code( string $code ): bool {
		return false;
	}
}

/**
 * A concrete WC_Cart subclass declaring the optional methods the read_cart /
 * resolve_shipping / select_shipping_method seams probe with method_exists(). Using
 * a real subclass (rather than a Mockery mock of the bare WC_Cart stub, which does
 * not declare them) makes those guards' true branches execute, so the recalculation
 * and discount/coupon lines are covered with meaningful, asserted behaviour.
 */
class Fahad_AI_Checkout_Coverage_Fake_Cart extends WC_Cart {

	private array $cfg;
	public int $calcShippingCount = 0;
	public int $calcTotalsCount   = 0;

	public function __construct( array $cfg = [] ) {
		$this->cfg = $cfg;
	}

	public function is_empty(): bool { return (bool) ( $this->cfg['empty'] ?? false ); }
	public function get_cart(): array { return (array) ( $this->cfg['cart'] ?? [] ); }
	public function get_cart_subtotal(): string { return (string) ( $this->cfg['subtotal'] ?? '' ); }
	public function get_cart_total(): string { return (string) ( $this->cfg['total'] ?? '' ); }
	public function get_applied_coupons(): array { return (array) ( $this->cfg['applied'] ?? [] ); }
	public function get_discount_total(): string { return (string) ( $this->cfg['discount_total'] ?? '0' ); }
	public function needs_shipping(): bool { return (bool) ( $this->cfg['needs_shipping'] ?? true ); }
	public function calculate_shipping(): void { $this->calcShippingCount++; }
	public function calculate_totals(): void { $this->calcTotalsCount++; }
}

/**
 * A concrete fake WC session exposing get/set/save_data so persist_session()'s
 * method_exists(save_data) guard passes and the chosen-method storage round-trips.
 */
class Fahad_AI_Checkout_Coverage_Fake_Session {

	private array $store;
	public int $saveCount = 0;

	public function __construct( array $initialChosen = [] ) {
		$this->store = [ 'chosen_shipping_methods' => $initialChosen ];
	}

	public function get( string $key, $default = null ) {
		return $this->store[ $key ] ?? $default;
	}

	public function set( string $key, $value ): void {
		$this->store[ $key ] = $value;
	}

	public function save_data(): void {
		$this->saveCount++;
	}
}

/** A concrete shipping-rate object exposing get_label()/get_cost() for resolve_shipping. */
class Fahad_AI_Checkout_Coverage_Fake_Rate {

	private string $label;
	private string $cost;

	public function __construct( string $label, string $cost ) {
		$this->label = $label;
		$this->cost  = $cost;
	}

	public function get_label(): string { return $this->label; }
	public function get_cost(): string { return $this->cost; }
}
