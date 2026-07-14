<?php
/**
 * Unit tests for Fahad_AI_Coupon_Tools (issue #14: coupons & deals).
 *
 * Red → Green → Refactor. Conventions mirror CatalogToolsTest / ToolsTest:
 * WP/WC functions mocked via Brain\Monkey; WC objects via Mockery; the registry
 * singleton + its static pack list snapshotted and restored so a case here neither
 * inherits another suite's packs nor leaks the coupon pack we register.
 *
 * The two coupon tools (list_active_coupons, apply_coupon) are NOT built-ins , 
 * they ship as a drop-in feature pack that self-registers a provider via
 * Fahad_AI_Tool_Registry::register_pack() at file load. Every test registers the
 * coupon pack's REAL provider through register_pack(), then dispatches through
 * Fahad_AI_Tool_Registry::instance()->dispatch(), so the production registration
 * + merge + dispatch path is what is under test.
 *
 * Coupon objects are sourced through get_posts() (the production enumeration of
 * published `shop_coupon` posts). We stub get_posts() to hand back per-scenario
 * Mockery WC_Coupon mocks so each case can exercise a different validity state
 * (valid / expired / usage-exhausted / restricted) without the brittleness of a
 * process-global `overload:` constructor mock.
 */

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;

class CouponToolsTest extends TestCase {

    use MockeryPHPUnitIntegration;

    /**
     * Snapshot of the registry's static pack providers, restored in tearDown.
     *
     * @var array<int, callable>
     */
    private array $pack_snapshot = [];

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();

        $this->pack_snapshot = (array) ( new ReflectionProperty( Fahad_AI_Tool_Registry::class, 'pack_providers' ) )->getValue();

        Functions\stubs( [
            'absint'              => fn( $n ) => abs( (int) $n ),
            'sanitize_text_field' => fn( $s ) => is_string( $s ) ? trim( $s ) : $s,
            // Registry get_tools() reads the merchant tool-gating option (issue #56);
            // default (no disabled tools) so dispatch()/specs() are unaffected.
            'get_option'          => fn( $key, $default = '' ) => $default,
            'wc_format_decimal'   => fn( $n ) => (string) $n,
            'wp_strip_all_tags'   => fn( $s ) => strip_tags( (string) $s ),
            'get_woocommerce_currency_symbol' => fn() => '$',
        ] );

        // No logged-in user unless a case overrides (per-user usage-limit path).
        Functions\when( 'get_current_user_id' )->justReturn( 0 );
        Functions\when( 'is_user_logged_in' )->justReturn( false );
    }

    protected function tearDown(): void {
        ( new ReflectionProperty( Fahad_AI_Tool_Registry::class, 'pack_providers' ) )->setValue( null, $this->pack_snapshot );
        Monkey\tearDown();
        parent::tearDown();
    }

    /**
     * Fresh registry whose built tool list includes the coupon tools.
     *
     * Resets the Tools + registry singletons, then registers the coupon pack's
     * REAL provider via register_pack(), exactly what the pack's file-scope
     * self-registration does in production.
     */
    private function registry(): Fahad_AI_Tool_Registry {
        ( new ReflectionProperty( Fahad_AI_Tools::class, 'instance' ) )->setValue( null, null );
        ( new ReflectionProperty( Fahad_AI_Tool_Registry::class, 'instance' ) )->setValue( null, null );

        Fahad_AI_Tool_Registry::reset_packs();
        Fahad_AI_Tool_Registry::register_pack( [ 'Fahad_AI_Coupon_Tools', 'register' ] );

        return Fahad_AI_Tool_Registry::instance();
    }

    /**
     * Build a Mockery WC_Coupon from a plain spec. Only the getters the tool reads
     * are stubbed; everything defaults to "unrestricted & usable".
     *
     * @param array $spec { code, type?, amount?, description?, expires_ts?|null,
     *                      usage_limit?, usage_count?, usage_limit_per_user?,
     *                      used_by?, minimum_amount?, maximum_amount?,
     *                      product_ids?, product_categories?, status? }
     */
    private function mockCoupon( array $spec ): WC_Coupon {
        $c = Mockery::mock( WC_Coupon::class );
        $c->shouldReceive( 'get_code' )->andReturn( (string) ( $spec['code'] ?? '' ) );
        $c->shouldReceive( 'get_id' )->andReturn( (int) ( $spec['id'] ?? 123 ) );
        $c->shouldReceive( 'get_status' )->andReturn( (string) ( $spec['status'] ?? 'publish' ) );
        $c->shouldReceive( 'get_discount_type' )->andReturn( (string) ( $spec['type'] ?? 'percent' ) );
        $c->shouldReceive( 'get_amount' )->andReturn( (string) ( $spec['amount'] ?? '10' ) );
        $c->shouldReceive( 'get_description' )->andReturn( (string) ( $spec['description'] ?? '' ) );

        // date_expires: WC_DateTime-like mock with getTimestamp(), or null.
        if ( array_key_exists( 'expires_ts', $spec ) && null !== $spec['expires_ts'] ) {
            $dt = Mockery::mock( 'WC_DateTime' );
            $dt->shouldReceive( 'getTimestamp' )->andReturn( (int) $spec['expires_ts'] );
            $c->shouldReceive( 'get_date_expires' )->andReturn( $dt );
        } else {
            $c->shouldReceive( 'get_date_expires' )->andReturn( null );
        }

        $c->shouldReceive( 'get_usage_limit' )->andReturn( (int) ( $spec['usage_limit'] ?? 0 ) );
        $c->shouldReceive( 'get_usage_count' )->andReturn( (int) ( $spec['usage_count'] ?? 0 ) );
        $c->shouldReceive( 'get_usage_limit_per_user' )->andReturn( (int) ( $spec['usage_limit_per_user'] ?? 0 ) );
        $c->shouldReceive( 'get_used_by' )->andReturn( (array) ( $spec['used_by'] ?? [] ) );
        $c->shouldReceive( 'get_minimum_amount' )->andReturn( (string) ( $spec['minimum_amount'] ?? '' ) );
        $c->shouldReceive( 'get_maximum_amount' )->andReturn( (string) ( $spec['maximum_amount'] ?? '' ) );
        $c->shouldReceive( 'get_product_ids' )->andReturn( (array) ( $spec['product_ids'] ?? [] ) );
        $c->shouldReceive( 'get_product_categories' )->andReturn( (array) ( $spec['product_categories'] ?? [] ) );
        return $c;
    }

    /** Stub get_posts() (the coupon enumeration source) to return these coupons. */
    private function stubCoupons( array $coupons ): void {
        Functions\when( 'get_posts' )->justReturn( $coupons );
    }

    // ── registration ──────────────────────────────────────────────────────────

    public function test_coupon_tools_are_registered_via_register_pack(): void {
        $names = array_column( $this->registry()->specs(), 'name' );

        $this->assertContains( 'list_active_coupons', $names );
        $this->assertContains( 'apply_coupon', $names );
        // Additive, the six built-ins remain.
        $this->assertContains( 'search_products', $names );
        $this->assertContains( 'add_to_cart', $names );
    }

    public function test_coupon_tool_specs_never_leak_a_callback(): void {
        $specs = array_column( $this->registry()->specs(), null, 'name' );

        foreach ( [ 'list_active_coupons', 'apply_coupon' ] as $name ) {
            $this->assertArrayHasKey( $name, $specs );
            $this->assertArrayNotHasKey( 'callback', $specs[ $name ] );
            $this->assertArrayHasKey( 'description', $specs[ $name ] );
            $this->assertSame( 'object', $specs[ $name ]['parameters']['type'] );
            $this->assertArrayHasKey( 'properties', $specs[ $name ]['parameters'] );
        }
    }

    public function test_coupon_tools_are_not_personal(): void {
        // They operate on the shared session cart, so they must NOT be login-gated.
        // (Private members are reflection-accessible by default since PHP 8.1, so
        // no setAccessible(), which is a deprecated no-op on 8.5.)
        $map = ( new ReflectionMethod( Fahad_AI_Tool_Registry::class, 'get_tools' ) )->invoke( $this->registry() );

        $this->assertArrayHasKey( 'list_active_coupons', $map );
        $this->assertArrayHasKey( 'apply_coupon', $map );
        $this->assertEmpty( $map['list_active_coupons']['personal'] ?? null );
        $this->assertEmpty( $map['apply_coupon']['personal'] ?? null );
    }

    // ── list_active_coupons ───────────────────────────────────────────────────

    public function test_list_active_coupons_returns_valid_coupon_with_description_and_min_note(): void {
        $this->stubCoupons( [
            $this->mockCoupon( [
                'code'           => 'SAVE10',
                'type'           => 'percent',
                'amount'         => '10',
                'minimum_amount' => '50',
            ] ),
        ] );
        // No cart context for this case.
        Functions\when( 'WC' )->justReturn( (object) [ 'cart' => null ] );

        $result = $this->registry()->dispatch( 'list_active_coupons', [] );

        $this->assertSame( 1, $result['found'] );
        $this->assertCount( 1, $result['coupons'] );
        $this->assertSame( 'SAVE10', $result['coupons'][0]['code'] );
        // Human description carries discount type + amount.
        $this->assertStringContainsString( '10', $result['coupons'][0]['description'] );
        // Minimum-spend note present and references the amount.
        $this->assertArrayHasKey( 'minimum_spend', $result['coupons'][0] );
        $this->assertStringContainsString( '50', (string) $result['coupons'][0]['minimum_spend'] );
        // A coupon list is NOT a product list, no cards.
        $this->assertArrayNotHasKey( 'products', $result );
    }

    public function test_list_active_coupons_excludes_expired_coupon(): void {
        $this->stubCoupons( [
            $this->mockCoupon( [ 'code' => 'EXPIRED', 'expires_ts' => 1000 ] ), // far in the past
            $this->mockCoupon( [ 'code' => 'LIVE' ] ),
        ] );
        Functions\when( 'WC' )->justReturn( (object) [ 'cart' => null ] );

        $result = $this->registry()->dispatch( 'list_active_coupons', [] );

        $codes = array_column( $result['coupons'], 'code' );
        $this->assertContains( 'LIVE', $codes );
        $this->assertNotContains( 'EXPIRED', $codes );
    }

    public function test_list_active_coupons_excludes_usage_exhausted_coupon(): void {
        $this->stubCoupons( [
            $this->mockCoupon( [ 'code' => 'MAXED', 'usage_limit' => 5, 'usage_count' => 5 ] ),
            $this->mockCoupon( [ 'code' => 'OPEN',  'usage_limit' => 5, 'usage_count' => 2 ] ),
        ] );
        Functions\when( 'WC' )->justReturn( (object) [ 'cart' => null ] );

        $result = $this->registry()->dispatch( 'list_active_coupons', [] );

        $codes = array_column( $result['coupons'], 'code' );
        $this->assertContains( 'OPEN', $codes );
        $this->assertNotContains( 'MAXED', $codes );
    }

    public function test_list_active_coupons_excludes_unpublished_coupon(): void {
        $this->stubCoupons( [
            $this->mockCoupon( [ 'code' => 'DRAFT', 'status' => 'draft' ] ),
            $this->mockCoupon( [ 'code' => 'PUB',   'status' => 'publish' ] ),
        ] );
        Functions\when( 'WC' )->justReturn( (object) [ 'cart' => null ] );

        $result = $this->registry()->dispatch( 'list_active_coupons', [] );

        $codes = array_column( $result['coupons'], 'code' );
        $this->assertContains( 'PUB', $codes );
        $this->assertNotContains( 'DRAFT', $codes );
    }

    public function test_list_active_coupons_excludes_per_user_exhausted_when_logged_in(): void {
        // Logged-in user 7 already used SOLO (limit 1 per user) → not usable for them.
        Functions\when( 'get_current_user_id' )->justReturn( 7 );
        Functions\when( 'is_user_logged_in' )->justReturn( true );

        $this->stubCoupons( [
            $this->mockCoupon( [
                'code'                 => 'SOLO',
                'usage_limit_per_user' => 1,
                'used_by'              => [ '7' ],
            ] ),
            $this->mockCoupon( [
                'code'                 => 'AGAIN',
                'usage_limit_per_user' => 2,
                'used_by'              => [ '7' ],
            ] ),
        ] );
        Functions\when( 'WC' )->justReturn( (object) [ 'cart' => null ] );

        $result = $this->registry()->dispatch( 'list_active_coupons', [] );

        $codes = array_column( $result['coupons'], 'code' );
        $this->assertContains( 'AGAIN', $codes );
        $this->assertNotContains( 'SOLO', $codes );
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     *
     * A non-empty cart is present, so applicability against the cart is
     * determinable: the production code consults WC's own validator
     * (WC_Discounts::is_coupon_valid) which returns a WP_Error for the restricted
     * coupon → it must be excluded; the OK one stays. The validator is overloaded
     * (argument-driven) in a SEPARATE PROCESS so the process-global overload class
     * cannot leak into other cases.
     */
    public function test_list_active_coupons_excludes_when_not_applicable_to_current_cart(): void {
        $this->stubCoupons( [
            $this->mockCoupon( [ 'code' => 'CARTOK' ] ),
            $this->mockCoupon( [ 'code' => 'WRONGCART' ] ),
        ] );

        $cart = Mockery::mock( WC_Cart::class );
        $cart->shouldReceive( 'is_empty' )->andReturn( false );
        Functions\when( 'WC' )->justReturn( (object) [ 'cart' => $cart ] );

        // WC's authoritative cart validator: valid for CARTOK, WP_Error for WRONGCART.
        $discounts = Mockery::mock( 'overload:WC_Discounts' );
        $discounts->shouldReceive( 'is_coupon_valid' )->andReturnUsing(
            static fn( $coupon ) => 'WRONGCART' === $coupon->get_code()
                ? new WP_Error( 'invalid_coupon', 'Not applicable to cart.' )
                : true
        );

        $result = $this->registry()->dispatch( 'list_active_coupons', [] );

        $codes = array_column( $result['coupons'], 'code' );
        $this->assertContains( 'CARTOK', $codes );
        $this->assertNotContains( 'WRONGCART', $codes );
    }

    public function test_list_active_coupons_empty_state_when_none(): void {
        $this->stubCoupons( [] );
        Functions\when( 'WC' )->justReturn( (object) [ 'cart' => null ] );

        $result = $this->registry()->dispatch( 'list_active_coupons', [] );

        $this->assertSame( 0, $result['found'] );
        $this->assertSame( [], $result['coupons'] );
        $this->assertArrayHasKey( 'message', $result );
    }

    public function test_list_active_coupons_describes_fixed_cart_discount(): void {
        $this->stubCoupons( [
            $this->mockCoupon( [ 'code' => 'TENOFF', 'type' => 'fixed_cart', 'amount' => '10' ] ),
        ] );
        Functions\when( 'WC' )->justReturn( (object) [ 'cart' => null ] );

        $result = $this->registry()->dispatch( 'list_active_coupons', [] );

        // Fixed-cart shows a currency amount, not a percent.
        $this->assertSame( 'TENOFF', $result['coupons'][0]['code'] );
        $this->assertStringContainsString( '$10', $result['coupons'][0]['description'] );
        $this->assertStringNotContainsString( '%', $result['coupons'][0]['description'] );
    }

    // ── apply_coupon ──────────────────────────────────────────────────────────

    public function test_apply_coupon_success_updates_total(): void {
        $cart = Mockery::mock( WC_Cart::class );
        $cart->shouldReceive( 'apply_coupon' )->with( 'SAVE10' )->once()->andReturn( true );
        $cart->shouldReceive( 'get_cart_total' )->andReturn( '$45.00' );
        $cart->shouldReceive( 'get_discount_total' )->andReturn( 5.0 );
        Functions\when( 'WC' )->justReturn( (object) [ 'cart' => $cart ] );

        $result = $this->registry()->dispatch( 'apply_coupon', [ 'code' => 'SAVE10' ] );

        $this->assertTrue( $result['success'] );
        $this->assertStringContainsString( 'SAVE10', $result['message'] );
        $this->assertSame( '$45.00', $result['cart_total'] );
    }

    public function test_apply_coupon_confirms_the_real_saving(): void {
        // A code that takes $5.50 off must surface a grounded discount_amount the assistant
        // can confirm ("that saved you $5.50"), sourced from WC's real cart discount.
        $cart = Mockery::mock( WC_Cart::class );
        $cart->shouldReceive( 'apply_coupon' )->with( 'SAVE10' )->once()->andReturn( true );
        $cart->shouldReceive( 'get_cart_total' )->andReturn( '$44.50' );
        $cart->shouldReceive( 'get_discount_total' )->andReturn( 5.5 );
        Functions\when( 'WC' )->justReturn( (object) [ 'cart' => $cart ] );

        $result = $this->registry()->dispatch( 'apply_coupon', [ 'code' => 'SAVE10' ] );

        $this->assertSame( 5.5, $result['discount_amount'] );
    }

    public function test_apply_coupon_omits_discount_when_code_reduces_nothing(): void {
        // A free-shipping-only code applies successfully but reduces no line: no "$0 off".
        $cart = Mockery::mock( WC_Cart::class );
        $cart->shouldReceive( 'apply_coupon' )->with( 'FREESHIP' )->once()->andReturn( true );
        $cart->shouldReceive( 'get_cart_total' )->andReturn( '$50.00' );
        $cart->shouldReceive( 'get_discount_total' )->andReturn( 0.0 );
        Functions\when( 'WC' )->justReturn( (object) [ 'cart' => $cart ] );

        $result = $this->registry()->dispatch( 'apply_coupon', [ 'code' => 'FREESHIP' ] );

        $this->assertTrue( $result['success'] );
        $this->assertArrayNotHasKey( 'discount_amount', $result );
    }

    public function test_apply_coupon_invalid_code_returns_error_not_fabricated_success(): void {
        // WC's own apply_coupon returns false (invalid/inapplicable). The tool must
        // surface an error and NOT claim success.
        $cart = Mockery::mock( WC_Cart::class );
        $cart->shouldReceive( 'apply_coupon' )->with( 'BOGUS' )->once()->andReturn( false );
        Functions\when( 'WC' )->justReturn( (object) [ 'cart' => $cart ] );

        $result = $this->registry()->dispatch( 'apply_coupon', [ 'code' => 'BOGUS' ] );

        $this->assertArrayHasKey( 'error', $result );
        $this->assertNotTrue( $result['success'] ?? false );
        $this->assertArrayNotHasKey( 'cart_total', $result );
    }

    public function test_apply_coupon_missing_code_returns_error(): void {
        // Must not even touch the cart when no code is provided.
        Functions\when( 'WC' )->justReturn( (object) [ 'cart' => Mockery::mock( WC_Cart::class ) ] );

        $result = $this->registry()->dispatch( 'apply_coupon', [] );

        $this->assertArrayHasKey( 'error', $result );
        $this->assertNotTrue( $result['success'] ?? false );
    }

    public function test_apply_coupon_normalizes_code_before_applying(): void {
        // Codes are matched case-insensitively by WC (stored lowercased); the tool
        // should hand WC a trimmed code. We assert the trimmed value reaches the cart.
        $cart = Mockery::mock( WC_Cart::class );
        $cart->shouldReceive( 'apply_coupon' )->with( 'SAVE10' )->once()->andReturn( true );
        $cart->shouldReceive( 'get_cart_total' )->andReturn( '$45.00' );
        $cart->shouldReceive( 'get_discount_total' )->andReturn( 5.0 );
        Functions\when( 'WC' )->justReturn( (object) [ 'cart' => $cart ] );

        $result = $this->registry()->dispatch( 'apply_coupon', [ 'code' => '  SAVE10  ' ] );

        $this->assertTrue( $result['success'] );
    }

    // ── remove_coupon ─────────────────────────────────────────────────────────

    public function test_remove_coupon_is_registered_and_not_personal(): void {
        $names = array_column( $this->registry()->specs(), 'name' );
        $this->assertContains( 'remove_coupon', $names );

        $map = ( new ReflectionMethod( Fahad_AI_Tool_Registry::class, 'get_tools' ) )->invoke( $this->registry() );
        $this->assertArrayHasKey( 'remove_coupon', $map );
        $this->assertEmpty( $map['remove_coupon']['personal'] ?? null );
    }

    public function test_remove_coupon_removes_an_applied_code(): void {
        // WooCommerce stores applied codes lowercased; a case-different request still matches.
        $cart = Mockery::mock( WC_Cart::class );
        $cart->shouldReceive( 'get_applied_coupons' )->andReturn( [ 'save10' ] );
        $cart->shouldReceive( 'remove_coupon' )->with( 'SAVE10' )->once()->andReturn( true );
        $cart->shouldReceive( 'get_cart_total' )->andReturn( '$50.00' );
        Functions\when( 'WC' )->justReturn( (object) [ 'cart' => $cart ] );

        $result = $this->registry()->dispatch( 'remove_coupon', [ 'code' => 'SAVE10' ] );

        $this->assertTrue( $result['success'] );
        $this->assertStringContainsString( 'SAVE10', $result['message'] );
        $this->assertSame( '$50.00', $result['cart_total'] );
    }

    public function test_remove_coupon_errors_when_code_not_applied(): void {
        // Must not claim removal of a code the cart never had.
        $cart = Mockery::mock( WC_Cart::class );
        $cart->shouldReceive( 'get_applied_coupons' )->andReturn( [] );
        $cart->shouldNotReceive( 'remove_coupon' );
        Functions\when( 'WC' )->justReturn( (object) [ 'cart' => $cart ] );

        $result = $this->registry()->dispatch( 'remove_coupon', [ 'code' => 'GHOST' ] );

        $this->assertFalse( $result['success'] );
        $this->assertArrayHasKey( 'error', $result );
        $this->assertArrayNotHasKey( 'cart_total', $result );
    }

    public function test_remove_coupon_requires_a_code(): void {
        Functions\when( 'WC' )->justReturn( (object) [ 'cart' => Mockery::mock( WC_Cart::class ) ] );

        $result = $this->registry()->dispatch( 'remove_coupon', [] );

        $this->assertFalse( $result['success'] );
        $this->assertArrayHasKey( 'error', $result );
    }

    public function test_remove_coupon_errors_when_cart_is_null(): void {
        Functions\when( 'WC' )->justReturn( (object) [ 'cart' => null ] );

        $result = $this->registry()->dispatch( 'remove_coupon', [ 'code' => 'SAVE10' ] );

        $this->assertFalse( $result['success'] );
        $this->assertArrayNotHasKey( 'cart_total', $result );
    }
}
