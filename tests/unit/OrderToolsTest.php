<?php
/**
 * Unit tests for Fahad_AI_Order_Tools (issue #17: order status & tracking, auth-gated).
 *
 * Red → Green → Refactor. Conventions mirror CouponToolsTest / CatalogToolsTest:
 * WP/WC functions mocked via Brain\Monkey; WC objects via Mockery; the registry
 * singleton + its static pack list snapshotted and restored so a case here neither
 * inherits another suite's packs nor leaks the order pack we register.
 *
 * The two order tools (get_my_orders, get_order_status) are NOT built-ins, they
 * ship as a drop-in feature pack that self-registers a provider via
 * Fahad_AI_Tool_Registry::register_pack() at file load. Every test registers the
 * order pack's REAL provider through register_pack(), then dispatches through
 * Fahad_AI_Tool_Registry::instance()->dispatch(), so the production registration +
 * merge + dispatch path (INCLUDING the central login gate for `personal` tools) is
 * what is under test.
 *
 * SECURITY IS THE POINT. Order data is PII, so the two highest-severity tests are
 * first-class, not afterthoughts:
 *   - GUEST-BLOCK: a guest dispatching either personal tool is stopped centrally by
 *     the registry's login gate BEFORE the callback runs (the callback spy must
 *     never flip).
 *   - OWNERSHIP-BYPASS: a logged-in user (id 5) asking for an order owned by user 9
 *     gets a "not found" result, never the other user's order.
 */

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;

class OrderToolsTest extends TestCase {

    use MockeryPHPUnitIntegration;

    /**
     * Snapshot of the registry's static pack providers, restored in tearDown so a
     * test here neither inherits another suite's packs nor leaks the order pack we
     * register for our own cases. (Pack providers are static so they survive a
     * singleton instance reset, see Fahad_AI_Tool_Registry::register_pack.)
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
            // The order tools format the WC date through wc_format_datetime( $dt,
            // 'Y-m-d' ). Our date mock is a real DateTime, so format it directly , 
            // exactly the shape WooCommerce returns ('Y-m-d' here).
            'wc_format_datetime'  => fn( $dt, $format = 'Y-m-d' ) => $dt instanceof \DateTimeInterface ? $dt->format( $format ) : '',
        ] );

        // Default to a logged-in customer (id 5). Guest cases override this. The
        // per-record ownership tests set the order's owner relative to id 5.
        Functions\when( 'is_user_logged_in' )->justReturn( true );
        Functions\when( 'get_current_user_id' )->justReturn( 5 );
    }

    protected function tearDown(): void {
        ( new ReflectionProperty( Fahad_AI_Tool_Registry::class, 'pack_providers' ) )->setValue( null, $this->pack_snapshot );
        Monkey\tearDown();
        parent::tearDown();
    }

    /**
     * Fresh registry whose built tool list includes the order tools.
     *
     * Resets the Tools + registry singletons, then registers the order pack's REAL
     * provider via register_pack(), exactly what the pack's file-scope
     * self-registration does in production. Registering it explicitly (after
     * clearing the static list) keeps the test hermetic and order-independent.
     */
    private function registry(): Fahad_AI_Tool_Registry {
        ( new ReflectionProperty( Fahad_AI_Tools::class, 'instance' ) )->setValue( null, null );
        ( new ReflectionProperty( Fahad_AI_Tool_Registry::class, 'instance' ) )->setValue( null, null );

        Fahad_AI_Tool_Registry::reset_packs();
        Fahad_AI_Tool_Registry::register_pack( [ 'Fahad_AI_Order_Tools', 'register' ] );

        return Fahad_AI_Tool_Registry::instance();
    }

    /**
     * Build a Mockery WC_Order from a plain spec. Only the getters the tools read
     * are stubbed; the rest default to a simple, owned, completed order.
     *
     * @param array $spec { id?, number?, status?, total?, date?, customer_id?,
     *                      items?: array<int, array{name:string, qty:int}>,
     *                      tracking?: string }
     */
    private function mockOrder( array $spec ): WC_Order {
        $o = Mockery::mock( WC_Order::class );
        $o->shouldReceive( 'get_id' )->andReturn( (int) ( $spec['id'] ?? 100 ) );
        $o->shouldReceive( 'get_order_number' )->andReturn( (string) ( $spec['number'] ?? (string) ( $spec['id'] ?? 100 ) ) );
        $o->shouldReceive( 'get_status' )->andReturn( (string) ( $spec['status'] ?? 'completed' ) );
        $o->shouldReceive( 'get_total' )->andReturn( (string) ( $spec['total'] ?? '49.99' ) );
        $o->shouldReceive( 'get_customer_id' )->andReturn( (int) ( $spec['customer_id'] ?? 5 ) );

        // get_date_created(): WooCommerce returns a WC_DateTime (a DateTime subclass).
        // A real DateTime stands in faithfully, wc_format_datetime() formats it, and
        // sidesteps mocking DateTime internals. null models "no date on the order".
        if ( array_key_exists( 'date', $spec ) && null !== $spec['date'] ) {
            $o->shouldReceive( 'get_date_created' )->andReturn( new \DateTime( (string) $spec['date'] ) );
        } else {
            $o->shouldReceive( 'get_date_created' )->andReturn( null );
        }

        // get_items(): each item is a mock exposing get_name() + get_quantity().
        $items = [];
        foreach ( $spec['items'] ?? [] as $i => $item ) {
            $line = Mockery::mock( 'WC_Order_Item_Product' );
            $line->shouldReceive( 'get_name' )->andReturn( (string) ( $item['name'] ?? '' ) );
            $line->shouldReceive( 'get_quantity' )->andReturn( (int) ( $item['qty'] ?? 1 ) );
            $items[ $i ] = $line;
        }
        $o->shouldReceive( 'get_items' )->andReturn( $items );

        // get_meta('_tracking_number'): best-effort tracking note (empty unless set).
        $o->shouldReceive( 'get_meta' )->with( '_tracking_number' )->andReturn( (string) ( $spec['tracking'] ?? '' ) );
        // Customer email exists on the order but must NOT leak into results.
        $o->shouldReceive( 'get_billing_email' )->andReturn( (string) ( $spec['email'] ?? 'jane@example.com' ) );

        return $o;
    }

    // ── registration ──────────────────────────────────────────────────────────

    public function test_order_tools_are_registered_via_register_pack(): void {
        $names = array_column( $this->registry()->specs(), 'name' );

        $this->assertContains( 'get_my_orders', $names );
        $this->assertContains( 'get_order_status', $names );
        // Additive: the six built-ins remain.
        $this->assertContains( 'search_products', $names );
    }

    public function test_order_tool_specs_never_leak_a_callback_or_personal_flag(): void {
        $specs = array_column( $this->registry()->specs(), null, 'name' );

        foreach ( [ 'get_my_orders', 'get_order_status' ] as $name ) {
            $this->assertArrayHasKey( $name, $specs );
            $this->assertArrayNotHasKey( 'callback', $specs[ $name ] );
            // The `personal` flag is an internal authorization detail; never advertised.
            $this->assertArrayNotHasKey( 'personal', $specs[ $name ] );
            $this->assertArrayHasKey( 'description', $specs[ $name ] );
            $this->assertSame( 'object', $specs[ $name ]['parameters']['type'] );
            $this->assertArrayHasKey( 'properties', $specs[ $name ]['parameters'] );
        }
    }

    // ── get_my_orders (happy path: own orders only) ─────────────────────────────

    public function test_get_my_orders_returns_the_logged_in_customers_orders(): void {
        $order = $this->mockOrder( [
            'id'     => 100,
            'number' => '100',
            'status' => 'processing',
            'total'  => '49.99',
            'date'   => '2026-06-01',
            'items'  => [ [ 'name' => 'Blue Hoodie', 'qty' => 2 ] ],
        ] );
        Functions\when( 'wc_get_orders' )->justReturn( [ $order ] );

        $result = $this->registry()->dispatch( 'get_my_orders', [] );

        $this->assertSame( 1, $result['found'] );
        $this->assertCount( 1, $result['orders'] );
        $this->assertSame( '100', $result['orders'][0]['number'] );
        $this->assertSame( 'processing', $result['orders'][0]['status'] );
        $this->assertSame( '2026-06-01', $result['orders'][0]['date'] );
        $this->assertSame( 'Blue Hoodie', $result['orders'][0]['items'][0]['name'] );
        $this->assertSame( 2, $result['orders'][0]['items'][0]['quantity'] );
    }

    public function test_get_my_orders_scopes_the_query_to_the_current_user(): void {
        // The query MUST be scoped to the current user id so it can only ever return
        // that user's own orders, the first line of defence against data leakage.
        Functions\expect( 'wc_get_orders' )
            ->once()
            ->andReturnUsing( function ( array $args ): array {
                $this->assertSame( 5, $args['customer_id'] );
                return [];
            } );

        $this->registry()->dispatch( 'get_my_orders', [] );
    }

    public function test_get_my_orders_defaults_limit_to_5(): void {
        $this->assertSame( 5, $this->captureLimit( [] ) );
    }

    public function test_get_my_orders_caps_limit_at_10(): void {
        $this->assertSame( 10, $this->captureLimit( [ 'limit' => 999 ] ) );
    }

    /** Helper: dispatch get_my_orders and capture the `limit` passed to wc_get_orders. */
    private function captureLimit( array $input ): int {
        $captured = 0;
        Functions\when( 'wc_get_orders' )->alias( function ( array $args ) use ( &$captured ): array {
            $captured = (int) $args['limit'];
            return [];
        } );
        $this->registry()->dispatch( 'get_my_orders', $input );
        return $captured;
    }

    public function test_get_my_orders_returns_empty_state_when_none(): void {
        Functions\when( 'wc_get_orders' )->justReturn( [] );

        $result = $this->registry()->dispatch( 'get_my_orders', [] );

        $this->assertSame( 0, $result['found'] );
        $this->assertSame( [], $result['orders'] );
        $this->assertArrayHasKey( 'message', $result );
    }

    public function test_get_my_orders_does_not_leak_pii(): void {
        $order = $this->mockOrder( [
            'id'    => 100,
            'email' => 'jane@example.com',
            'items' => [ [ 'name' => 'Blue Hoodie', 'qty' => 1 ] ],
        ] );
        Functions\when( 'wc_get_orders' )->justReturn( [ $order ] );

        $result = $this->registry()->dispatch( 'get_my_orders', [] );

        // No raw email/address fields anywhere in the encoded payload.
        $encoded = json_encode( $result );
        $this->assertStringNotContainsString( 'jane@example.com', $encoded );
        $this->assertArrayNotHasKey( 'email', $result['orders'][0] );
        $this->assertArrayNotHasKey( 'billing_email', $result['orders'][0] );
        $this->assertArrayNotHasKey( 'address', $result['orders'][0] );
    }

    // ── get_order_status (happy path) ───────────────────────────────────────────

    public function test_get_order_status_returns_status_for_an_owned_order(): void {
        // Current user is 5; order is owned by 5.
        $order = $this->mockOrder( [
            'id'          => 100,
            'number'      => '100',
            'status'      => 'shipped',
            'total'       => '49.99',
            'date'        => '2026-06-01',
            'customer_id' => 5,
            'items'       => [ [ 'name' => 'Blue Hoodie', 'qty' => 1 ] ],
        ] );
        Functions\when( 'wc_get_order' )->justReturn( $order );

        $result = $this->registry()->dispatch( 'get_order_status', [ 'order_id' => 100 ] );

        $this->assertArrayNotHasKey( 'error', $result );
        $this->assertSame( '100', $result['number'] );
        $this->assertSame( 'shipped', $result['status'] );
        $this->assertSame( '49.99', $result['total'] );
    }

    public function test_get_order_status_includes_tracking_note_when_present(): void {
        $order = $this->mockOrder( [
            'id'          => 100,
            'customer_id' => 5,
            'tracking'    => '1Z999AA10123456784',
        ] );
        Functions\when( 'wc_get_order' )->justReturn( $order );

        $result = $this->registry()->dispatch( 'get_order_status', [ 'order_id' => 100 ] );

        $this->assertArrayHasKey( 'tracking_number', $result );
        $this->assertSame( '1Z999AA10123456784', $result['tracking_number'] );
    }

    public function test_get_order_status_omits_tracking_when_absent(): void {
        $order = $this->mockOrder( [ 'id' => 100, 'customer_id' => 5, 'tracking' => '' ] );
        Functions\when( 'wc_get_order' )->justReturn( $order );

        $result = $this->registry()->dispatch( 'get_order_status', [ 'order_id' => 100 ] );

        $this->assertArrayNotHasKey( 'tracking_number', $result );
    }

    public function test_get_order_status_returns_not_found_for_missing_order(): void {
        // wc_get_order returns false when the id does not resolve to an order.
        Functions\when( 'wc_get_order' )->justReturn( false );

        $result = $this->registry()->dispatch( 'get_order_status', [ 'order_id' => 999 ] );

        $this->assertArrayHasKey( 'error', $result );
        $this->assertStringContainsString( 'not found', strtolower( $result['error'] ) );
    }

    public function test_get_order_status_does_not_leak_pii(): void {
        $order = $this->mockOrder( [ 'id' => 100, 'customer_id' => 5, 'email' => 'jane@example.com' ] );
        Functions\when( 'wc_get_order' )->justReturn( $order );

        $result = $this->registry()->dispatch( 'get_order_status', [ 'order_id' => 100 ] );

        $encoded = json_encode( $result );
        $this->assertStringNotContainsString( 'jane@example.com', $encoded );
        $this->assertArrayNotHasKey( 'email', $result );
        $this->assertArrayNotHasKey( 'billing_email', $result );
    }

    // ── OWNERSHIP-BYPASS (the key security test) ────────────────────────────────

    /**
     * THE headline security test. Current user is 5. They request order #100, which
     * is owned by user 9. get_order_status must return a "not found"-style result , 
     * NEVER the other user's order, and must not even confirm the order exists.
     *
     * Without the in-callback Fahad_AI_Auth::user_owns() guard this fails: the order
     * loads fine (wc_get_order returns it) and its real status would leak.
     */
    public function test_get_order_status_blocks_access_to_another_users_order(): void {
        $someone_elses_order = $this->mockOrder( [
            'id'          => 100,
            'number'      => '100',
            'status'      => 'shipped',
            'total'       => '999.00',
            'customer_id' => 9, // belongs to a DIFFERENT user
            'items'       => [ [ 'name' => 'Secret Gift', 'qty' => 1 ] ],
        ] );
        Functions\when( 'wc_get_order' )->justReturn( $someone_elses_order );

        // Current user is 5 (from setUp); the order belongs to 9.
        $result = $this->registry()->dispatch( 'get_order_status', [ 'order_id' => 100 ] );

        // Must be a not-found error, never the order.
        $this->assertArrayHasKey( 'error', $result );
        $this->assertStringContainsString( 'not found', strtolower( $result['error'] ) );
        // None of the other user's order data leaked, anywhere.
        $encoded = json_encode( $result );
        $this->assertArrayNotHasKey( 'status', $result );
        $this->assertArrayNotHasKey( 'total', $result );
        $this->assertStringNotContainsString( 'shipped', $encoded );
        $this->assertStringNotContainsString( '999.00', $encoded );
        $this->assertStringNotContainsString( 'Secret Gift', $encoded );
        // "Not found" must not confirm the record exists for another user, so the
        // message must not be a "forbidden / not yours" disclosure.
        $this->assertStringNotContainsString( 'forbidden', strtolower( $result['error'] ) );
        $this->assertStringNotContainsString( 'permission', strtolower( $result['error'] ) );
    }

    // ── GUEST-BLOCK (central login gate, callback never reached) ────────────────

    /**
     * A guest dispatching either personal tool must be stopped CENTRALLY by the
     * registry's login gate, before the tool callback runs. We assert the standard
     * login-required error AND, critically, that the underlying WC data accessors
     * are NEVER invoked, proving the callback was never reached (the gate is central,
     * not something each tool re-implements). is_user_logged_in() is stubbed false.
     *
     * @dataProvider personalToolProvider
     */
    public function test_guest_is_blocked_before_a_personal_tool_callback_runs( string $tool, array $input ): void {
        Functions\when( 'is_user_logged_in' )->justReturn( false );
        Functions\when( 'get_current_user_id' )->justReturn( 0 );

        // If the callback were reached, it would call one of these, they must NOT
        // be hit. expect(...)->never() turns any call into a hard failure.
        Functions\expect( 'wc_get_orders' )->never();
        Functions\expect( 'wc_get_order' )->never();

        $result = $this->registry()->dispatch( $tool, $input );

        $this->assertArrayHasKey( 'requires_login', $result );
        $this->assertTrue( $result['requires_login'] );
        $this->assertArrayHasKey( 'error', $result );
        $this->assertStringNotContainsString( 'Unknown tool', $result['error'] );
        // No order data of any kind in a guest result.
        $this->assertArrayNotHasKey( 'orders', $result );
        $this->assertArrayNotHasKey( 'status', $result );
    }

    /** Both personal tools must be guest-gated. */
    public static function personalToolProvider(): array {
        return [
            'get_my_orders'    => [ 'get_my_orders', [] ],
            'get_order_status' => [ 'get_order_status', [ 'order_id' => 100 ] ],
        ];
    }
}
