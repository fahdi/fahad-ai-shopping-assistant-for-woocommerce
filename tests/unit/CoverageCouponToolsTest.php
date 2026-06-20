<?php
/**
 * Supplemental line-coverage tests for Fahad_AI_Coupon_Tools.
 *
 * Companion to CouponToolsTest: it drives the remaining guard clauses, fallbacks
 * and branch paths the primary suite does not reach — non-coupon enumeration
 * items, a missing/unavailable cart, an id-0 coupon, the guest per-user path, the
 * WC_Discounts-absent fallback, free-text notes, product-scoped discount wording,
 * the get_posts()-absent and WP_Post enumeration branches, and the money/decimal
 * formatting tails. Conventions mirror CouponToolsTest (Brain\Monkey + Mockery,
 * the registry pack snapshot/restore, dispatch through the real provider path).
 */

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;

class CoverageCouponToolsTest extends TestCase {

    use MockeryPHPUnitIntegration;

    /** @var array<int, callable> */
    private array $pack_snapshot = [];

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();

        $this->pack_snapshot = (array) ( new ReflectionProperty( Fahad_AI_Tool_Registry::class, 'pack_providers' ) )->getValue();

        Functions\stubs( [
            'absint'                          => fn( $n ) => abs( (int) $n ),
            'sanitize_text_field'             => fn( $s ) => is_string( $s ) ? trim( $s ) : $s,
            'get_option'                      => fn( $key, $default = '' ) => $default,
            'wc_format_decimal'               => fn( $n ) => (string) $n,
            'wp_strip_all_tags'               => fn( $s ) => strip_tags( (string) $s ),
            'get_woocommerce_currency_symbol' => fn() => '$',
        ] );

        Functions\when( 'get_current_user_id' )->justReturn( 0 );
        Functions\when( 'is_user_logged_in' )->justReturn( false );
    }

    protected function tearDown(): void {
        ( new ReflectionProperty( Fahad_AI_Tool_Registry::class, 'pack_providers' ) )->setValue( null, $this->pack_snapshot );
        Monkey\tearDown();
        parent::tearDown();
    }

    /** Fresh registry with the coupon pack's REAL provider registered. */
    private function registry(): Fahad_AI_Tool_Registry {
        ( new ReflectionProperty( Fahad_AI_Tools::class, 'instance' ) )->setValue( null, null );
        ( new ReflectionProperty( Fahad_AI_Tool_Registry::class, 'instance' ) )->setValue( null, null );

        Fahad_AI_Tool_Registry::reset_packs();
        Fahad_AI_Tool_Registry::register_pack( [ 'Fahad_AI_Coupon_Tools', 'register' ] );

        return Fahad_AI_Tool_Registry::instance();
    }

    /** Build a Mockery WC_Coupon from a plain spec (defaults: unrestricted & usable). */
    private function mockCoupon( array $spec ): WC_Coupon {
        $c = Mockery::mock( WC_Coupon::class );
        $c->shouldReceive( 'get_code' )->andReturn( (string) ( $spec['code'] ?? '' ) );
        $c->shouldReceive( 'get_id' )->andReturn( (int) ( $spec['id'] ?? 123 ) );
        $c->shouldReceive( 'get_status' )->andReturn( (string) ( $spec['status'] ?? 'publish' ) );
        $c->shouldReceive( 'get_discount_type' )->andReturn( (string) ( $spec['type'] ?? 'percent' ) );
        $c->shouldReceive( 'get_amount' )->andReturn( (string) ( $spec['amount'] ?? '10' ) );
        $c->shouldReceive( 'get_description' )->andReturn( (string) ( $spec['description'] ?? '' ) );
        $c->shouldReceive( 'get_date_expires' )->andReturn( null );
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

    /** Stub get_posts() (the coupon enumeration source) to return these items. */
    private function stubCoupons( array $coupons ): void {
        Functions\when( 'get_posts' )->justReturn( $coupons );
    }

    // ── list_active_coupons: mixed enumeration source ───────────────────────────

    public function test_list_active_coupons_handles_mixed_enumeration_source(): void {
        // get_posts() may hand back a mix of WP_Post-like objects and bare strings.
        // get_coupon_objects() wraps each non-WC_Coupon into `new WC_Coupon( $code )`
        // (stub id 0 → excluded as unknown), while a real, valid coupon is returned.
        // This drives the get_coupon_objects WP_Post/string-wrapping branches and the
        // id-0 exclusion together with a genuinely valid coupon.
        $this->stubCoupons( [
            (object) [ 'post_title' => 'WRAPPED' ],
            'bare-code',
            $this->mockCoupon( [ 'code' => 'REAL' ] ),
        ] );
        Functions\when( 'WC' )->justReturn( (object) [ 'cart' => null ] );

        $result = $this->registry()->dispatch( 'list_active_coupons', [] );

        $this->assertSame( 1, $result['found'] );
        $this->assertSame( 'REAL', $result['coupons'][0]['code'] );
    }

    // ── is_coupon_currently_valid: id-0 guard (line 202) ────────────────────────

    public function test_list_active_coupons_excludes_unknown_code_with_id_zero(): void {
        // A coupon constructed from an unknown code has id 0 → must be excluded.
        $this->stubCoupons( [
            $this->mockCoupon( [ 'code' => 'GHOST', 'id' => 0 ] ),
            $this->mockCoupon( [ 'code' => 'KNOWN', 'id' => 5 ] ),
        ] );
        Functions\when( 'WC' )->justReturn( (object) [ 'cart' => null ] );

        $result = $this->registry()->dispatch( 'list_active_coupons', [] );

        $codes = array_column( $result['coupons'], 'code' );
        $this->assertContains( 'KNOWN', $codes );
        $this->assertNotContains( 'GHOST', $codes );
    }

    // ── passes_per_user_limit: guest path (line 254) ────────────────────────────

    public function test_per_user_limited_coupon_passes_for_guest(): void {
        // The coupon sets a per-user cap, but a guest (user id 0) is not determinable
        // here — the tool must NOT exclude it (WC re-checks at apply time).
        Functions\when( 'get_current_user_id' )->justReturn( 0 );
        Functions\when( 'is_user_logged_in' )->justReturn( false );

        $this->stubCoupons( [
            $this->mockCoupon( [
                'code'                 => 'PERUSER',
                'usage_limit_per_user' => 1,
                'used_by'              => [ '7', '7' ],
            ] ),
        ] );
        Functions\when( 'WC' )->justReturn( (object) [ 'cart' => null ] );

        $result = $this->registry()->dispatch( 'list_active_coupons', [] );

        $this->assertSame( 1, $result['found'] );
        $this->assertSame( 'PERUSER', $result['coupons'][0]['code'] );
    }

    // ── is_valid_for_cart: WC_Discounts absent fallback (line 279) ──────────────

    public function test_list_active_coupons_with_cart_but_no_discounts_class_does_not_exclude(): void {
        // A non-empty cart makes applicability checkable, but if WC_Discounts is not
        // loaded the tool defers (returns the coupon) rather than excluding it. In this
        // unit environment WC_Discounts is intentionally NOT stubbed, so it is absent.
        $this->assertFalse( class_exists( 'WC_Discounts', false ), 'precondition: WC_Discounts must be absent here' );

        $this->stubCoupons( [
            $this->mockCoupon( [ 'code' => 'KEEPME' ] ),
        ] );

        $cart = Mockery::mock( WC_Cart::class );
        $cart->shouldReceive( 'is_empty' )->andReturn( false );
        Functions\when( 'WC' )->justReturn( (object) [ 'cart' => $cart ] );

        $result = $this->registry()->dispatch( 'list_active_coupons', [] );

        $this->assertSame( 1, $result['found'] );
        $this->assertSame( 'KEEPME', $result['coupons'][0]['code'] );
    }

    // ── format_coupon: free-text note (line 314) ────────────────────────────────

    public function test_list_active_coupons_includes_free_text_note(): void {
        // A coupon with a free-text description surfaces it as a stripped 'note'.
        $this->stubCoupons( [
            $this->mockCoupon( [
                'code'        => 'NOTED',
                'description' => '<b>Members only</b> deal',
            ] ),
        ] );
        Functions\when( 'WC' )->justReturn( (object) [ 'cart' => null ] );

        $result = $this->registry()->dispatch( 'list_active_coupons', [] );

        $this->assertArrayHasKey( 'note', $result['coupons'][0] );
        $this->assertSame( 'Members only deal', $result['coupons'][0]['note'] );
    }

    // ── describe_discount: product-scoped percent (line 337) ────────────────────

    public function test_describe_discount_percent_product_is_select_products(): void {
        $this->stubCoupons( [
            $this->mockCoupon( [ 'code' => 'PCTPROD', 'type' => 'percent_product', 'amount' => '15' ] ),
        ] );
        Functions\when( 'WC' )->justReturn( (object) [ 'cart' => null ] );

        $result = $this->registry()->dispatch( 'list_active_coupons', [] );

        $desc = $result['coupons'][0]['description'];
        $this->assertStringContainsString( '15%', $desc );
        $this->assertStringContainsString( 'select products', $desc );
    }

    // ── describe_discount: product-scoped fixed (line 346) ──────────────────────

    public function test_describe_discount_fixed_product_is_select_products(): void {
        $this->stubCoupons( [
            $this->mockCoupon( [ 'code' => 'FIXPROD', 'type' => 'fixed_product', 'amount' => '20' ] ),
        ] );
        Functions\when( 'WC' )->justReturn( (object) [ 'cart' => null ] );

        $result = $this->registry()->dispatch( 'list_active_coupons', [] );

        $desc = $result['coupons'][0]['description'];
        $this->assertStringContainsString( '$20', $desc );
        $this->assertStringContainsString( 'select products', $desc );
        $this->assertStringNotContainsString( '%', $desc );
    }

    // ── money / trim_decimal fractional tails (lines 427, 442) ──────────────────

    public function test_minimum_spend_with_fractional_amount_formats_two_decimals(): void {
        // A non-integer minimum_amount drives money()'s number_format( , 2 ) branch.
        $this->stubCoupons( [
            $this->mockCoupon( [
                'code'           => 'FRACMIN',
                'type'           => 'fixed_cart',
                'amount'         => '5',
                'minimum_amount' => '49.99',
            ] ),
        ] );
        Functions\when( 'WC' )->justReturn( (object) [ 'cart' => null ] );

        $result = $this->registry()->dispatch( 'list_active_coupons', [] );

        $this->assertArrayHasKey( 'minimum_spend', $result['coupons'][0] );
        $this->assertStringContainsString( '$49.99', (string) $result['coupons'][0]['minimum_spend'] );
    }

    public function test_describe_percent_trims_insignificant_decimal_zeros(): void {
        // A percent amount of "10.00" should read as "10%" — exercises trim_decimal's
        // rtrim path on a value containing a decimal point.
        $this->stubCoupons( [
            $this->mockCoupon( [ 'code' => 'TENPCT', 'type' => 'percent', 'amount' => '10.00' ] ),
        ] );
        Functions\when( 'WC' )->justReturn( (object) [ 'cart' => null ] );

        $result = $this->registry()->dispatch( 'list_active_coupons', [] );

        $this->assertStringContainsString( '10%', $result['coupons'][0]['description'] );
        $this->assertStringNotContainsString( '10.00', $result['coupons'][0]['description'] );
    }

    // ── get_coupon_objects: get_posts() absent (line 372) ───────────────────────

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     *
     * When get_posts() is unavailable the enumeration degrades to an empty list and
     * the tool reports "no codes" rather than fataling. get_posts() is a real
     * WordPress function, so we exercise the function_exists() guard in a SEPARATE
     * process where it is never defined; the empty-state response confirms the
     * branch. (Brain\Monkey would otherwise define it the moment any test stubs it.)
     */
    public function test_list_active_coupons_empty_when_get_posts_unavailable(): void {
        $this->assertFalse( function_exists( 'get_posts' ), 'precondition: get_posts must be undefined in this isolated process' );
        Functions\when( 'WC' )->justReturn( (object) [ 'cart' => null ] );

        $result = $this->registry()->dispatch( 'list_active_coupons', [] );

        $this->assertSame( 0, $result['found'] );
        $this->assertSame( [], $result['coupons'] );
        $this->assertArrayHasKey( 'message', $result );
    }

    // ── get_coupon_objects: WP_Post enumeration branch (lines 394-395) ──────────

    public function test_get_coupon_objects_constructs_coupon_from_wp_post_title(): void {
        // get_posts() returns WP_Post-like objects: each post_title is treated as a
        // coupon code and loaded via `new WC_Coupon( $code )`. The stub WC_Coupon's
        // get_id() returns 0, so the resulting coupon is then excluded as "unknown" —
        // proving the post→coupon construction path ran without error.
        $this->stubCoupons( [
            (object) [ 'post_title' => 'FROMPOST' ],
        ] );
        Functions\when( 'WC' )->justReturn( (object) [ 'cart' => null ] );

        $result = $this->registry()->dispatch( 'list_active_coupons', [] );

        // The constructed (stub) coupon has id 0, so nothing passes validity.
        $this->assertSame( 0, $result['found'] );
        $this->assertSame( [], $result['coupons'] );
    }

    // ── cart(): WC() unavailable (line 412) and apply_coupon null-cart (145-148) ─

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     *
     * When WC() itself is undefined, cart() returns null; apply_coupon then surfaces
     * "cart not available" without ever touching a cart. WC() is a real WooCommerce
     * function, so the function_exists() guard only fires when it is absent — done in
     * a SEPARATE process so the missing definition cannot leak.
     */
    public function test_apply_coupon_errors_when_wc_function_unavailable(): void {
        $this->assertFalse( function_exists( 'WC' ), 'precondition: WC() must be undefined in this isolated process' );

        $result = $this->registry()->dispatch( 'apply_coupon', [ 'code' => 'ANY' ] );

        $this->assertFalse( $result['success'] );
        $this->assertArrayHasKey( 'error', $result );
        $this->assertArrayNotHasKey( 'cart_total', $result );
    }

    public function test_apply_coupon_errors_when_cart_is_null(): void {
        // WC() resolves but exposes no cart → cart() returns null → "cart not available".
        Functions\when( 'WC' )->justReturn( (object) [ 'cart' => null ] );

        $result = $this->registry()->dispatch( 'apply_coupon', [ 'code' => 'SAVE10' ] );

        $this->assertFalse( $result['success'] );
        $this->assertArrayHasKey( 'error', $result );
        $this->assertArrayNotHasKey( 'cart_total', $result );
    }
}
