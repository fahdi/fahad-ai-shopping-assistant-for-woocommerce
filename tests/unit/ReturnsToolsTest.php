<?php
/**
 * Unit tests for Fahad_AI_Returns_Tools (issue #53: returns & exchange / RMA assistant,
 * auth-gated).
 *
 * Red → Green → Refactor. Conventions mirror OrderToolsTest / ReorderToolsTest:
 * WP/WC functions mocked via Brain\Monkey; WC objects via Mockery; the registry
 * singleton + its static pack list snapshotted and restored so a case here neither
 * inherits another suite's packs nor leaks the returns pack we register.
 *
 * The two returns tools (check_return_eligibility, request_return) are NOT built-ins , 
 * they ship as a drop-in feature pack that self-registers a provider via
 * Fahad_AI_Tool_Registry::register_pack() at file load. Every test registers the
 * returns pack's REAL provider through register_pack(), then dispatches through
 * Fahad_AI_Tool_Registry::instance()->dispatch(), so the production registration +
 * merge + dispatch path (INCLUDING the central login gate for `personal` tools) is
 * what is under test.
 *
 * SECURITY + MONEY-SAFETY ARE THE POINT (issue #53 hardening):
 *   - GUEST-BLOCK: a guest dispatching either personal tool is stopped centrally by the
 *     registry's login gate BEFORE the callback runs (the WC accessors must never be hit).
 *   - OWNERSHIP-BYPASS: a logged-in user (id 5) acting on an order owned by user 9 gets a
 *     "not found" result, never the other user's order, never an RMA against it.
 *   - NO MONEY MUTATION: request_return only RECORDS a request. It must NEVER issue a
 *     refund or change the order's status, asserted with ->never() on the money seams.
 *   - IDEMPOTENT: requesting a return for the same items twice records ONE RMA, not two.
 *   - HONEST ESCALATION: ineligible / edge cases return a plain reason AND a human-support
 *     path, and never block support.
 */

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;

class ReturnsToolsTest extends TestCase {

    use MockeryPHPUnitIntegration;

    /** A fixed "now" the eligibility window math is measured against (stubbed current_time). */
    private const NOW = 1750000000; // 2025-06-15-ish UTC.

    /**
     * Snapshot of the registry's static pack providers, restored in tearDown so a test
     * here neither inherits another suite's packs nor leaks the returns pack we register
     * for our own cases. (Pack providers are static so they survive a singleton instance
     * reset, see Fahad_AI_Tool_Registry::register_pack.)
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
            'sanitize_textarea_field' => fn( $s ) => is_string( $s ) ? trim( $s ) : $s,
            // Registry get_tools() reads the merchant tool-gating option (issue #56);
            // default (no disabled tools) so dispatch()/specs() are unaffected.
            'get_option'          => fn( $key, $default = '' ) => $default,
            'wp_strip_all_tags'   => fn( $s ) => strip_tags( (string) $s ),
            // The window math measures the order age against "now". current_time( 'timestamp', true )
            // returns a unix timestamp in WordPress; we pin it so age is deterministic.
            'current_time'        => fn( $type = 'timestamp', $gmt = 0 ) => self::NOW,
            // Filters are pass-through unless a test overrides, so the DEFAULT policy
            // (30-day window, the default eligible statuses) is what runs by default.
            'apply_filters'       => fn( $hook, $value = null ) => $value,
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
     * Fresh registry whose built tool list includes the returns tools.
     *
     * Resets the Tools + registry singletons, then registers the returns pack's REAL
     * provider via register_pack(), exactly what the pack's file-scope self-registration
     * does in production. Registering it explicitly (after clearing the static list) keeps
     * the test hermetic and order-independent.
     */
    private function registry(): Fahad_AI_Tool_Registry {
        ( new ReflectionProperty( Fahad_AI_Tools::class, 'instance' ) )->setValue( null, null );
        ( new ReflectionProperty( Fahad_AI_Tool_Registry::class, 'instance' ) )->setValue( null, null );

        Fahad_AI_Tool_Registry::reset_packs();
        Fahad_AI_Tool_Registry::register_pack( [ 'Fahad_AI_Returns_Tools', 'register' ] );

        return Fahad_AI_Tool_Registry::instance();
    }

    /** A unix timestamp `$days` days before the pinned NOW. */
    private function daysAgo( int $days ): int {
        return self::NOW - ( $days * DAY_IN_SECONDS );
    }

    /**
     * Build a Mockery line item exposing the getters the returns tools read: product id
     * and name.
     *
     * @param array $spec { product_id, name?, qty? }
     */
    private function mockLineItem( array $spec ) {
        $line = Mockery::mock( 'WC_Order_Item_Product' );
        $line->shouldReceive( 'get_product_id' )->andReturn( (int) ( $spec['product_id'] ?? 0 ) );
        $line->shouldReceive( 'get_name' )->andReturn( (string) ( $spec['name'] ?? '' ) );
        $line->shouldReceive( 'get_quantity' )->andReturn( (int) ( $spec['qty'] ?? 1 ) );
        return $line;
    }

    /**
     * Build a Mockery WC_Order from a plain spec. Only the getters/setters the tools read
     * or write are stubbed; the rest default to a recently-delivered, owned, completed
     * order that IS within the return window.
     *
     * The RMA meta read/write is modelled with a tiny in-memory store so idempotency can
     * be asserted across two calls on the SAME order object: get_meta reads it,
     * update_meta_data writes it, save() is a no-op. add_order_note is allowed (best
     * effort) but never required.
     *
     * @param array $spec { id?, customer_id?, status?, created_days_ago?, items?, meta? }
     */
    private function mockOrder( array $spec ): WC_Order {
        $o = Mockery::mock( WC_Order::class );
        $o->shouldReceive( 'get_id' )->andReturn( (int) ( $spec['id'] ?? 100 ) );
        $o->shouldReceive( 'get_order_number' )->andReturn( (string) ( $spec['number'] ?? (string) ( $spec['id'] ?? 100 ) ) );
        $o->shouldReceive( 'get_customer_id' )->andReturn( (int) ( $spec['customer_id'] ?? 5 ) );
        $o->shouldReceive( 'get_status' )->andReturn( (string) ( $spec['status'] ?? 'completed' ) );

        // get_date_created(): WooCommerce returns a WC_DateTime (a DateTime subclass). A
        // real DateTime stands in faithfully; the tool reads its getTimestamp(). null
        // models "no date on the order".
        $created_days = $spec['created_days_ago'] ?? 3; // default: 3 days ago → inside window.
        if ( null === $created_days ) {
            $o->shouldReceive( 'get_date_created' )->andReturn( null );
        } else {
            $dt = ( new \DateTime() )->setTimestamp( $this->daysAgo( (int) $created_days ) );
            $o->shouldReceive( 'get_date_created' )->andReturn( $dt );
        }

        $items = [];
        foreach ( $spec['items'] ?? [ [ 'product_id' => 10, 'name' => 'Blue Hoodie' ] ] as $i => $item ) {
            $items[ $i ] = $this->mockLineItem( $item );
        }
        $o->shouldReceive( 'get_items' )->andReturn( $items );

        // In-memory RMA meta store (shared across calls on this order) so idempotency is
        // observable. The production code reads/writes a single meta key; a single
        // get_meta stub that switches on the key avoids ambiguous overlapping expectations.
        $store = (object) [ 'rma' => $spec['meta']['rma'] ?? [] ];
        $o->shouldReceive( 'get_meta' )->andReturnUsing(
            fn( $key = '', $single = true ) => '_fahad_ai_rma_requests' === $key ? $store->rma : ''
        );
        $o->shouldReceive( 'update_meta_data' )->andReturnUsing(
            function ( $key, $value ) use ( $store ) {
                if ( '_fahad_ai_rma_requests' === $key ) {
                    $store->rma = $value;
                }
            }
        );
        $o->shouldReceive( 'save' )->andReturn( (int) ( $spec['id'] ?? 100 ) )->byDefault();
        $o->shouldReceive( 'add_order_note' )->andReturn( 1 )->byDefault();

        return $o;
    }

    // ── registration ──────────────────────────────────────────────────────────

    public function test_returns_tools_are_registered_via_register_pack(): void {
        $names = array_column( $this->registry()->specs(), 'name' );

        $this->assertContains( 'check_return_eligibility', $names );
        $this->assertContains( 'request_return', $names );
        // Additive: the six built-ins remain.
        $this->assertContains( 'search_products', $names );
    }

    public function test_returns_tool_specs_never_leak_a_callback_or_personal_flag(): void {
        $specs = array_column( $this->registry()->specs(), null, 'name' );

        foreach ( [ 'check_return_eligibility', 'request_return' ] as $name ) {
            $this->assertArrayHasKey( $name, $specs );
            $this->assertArrayNotHasKey( 'callback', $specs[ $name ] );
            // The `personal` flag is an internal authorization detail; never advertised.
            $this->assertArrayNotHasKey( 'personal', $specs[ $name ] );
            $this->assertArrayHasKey( 'description', $specs[ $name ] );
            $this->assertSame( 'object', $specs[ $name ]['parameters']['type'] );
            $this->assertArrayHasKey( 'properties', $specs[ $name ]['parameters'] );
        }
    }

    // ── check_return_eligibility (happy path) ───────────────────────────────────

    public function test_eligibility_eligible_for_a_recent_completed_owned_order(): void {
        $order = $this->mockOrder( [
            'id'               => 100,
            'customer_id'      => 5,
            'status'           => 'completed',
            'created_days_ago' => 3, // well inside the 30-day window
            'items'            => [ [ 'product_id' => 10, 'name' => 'Blue Hoodie' ] ],
        ] );
        Functions\when( 'wc_get_order' )->justReturn( $order );

        $result = $this->registry()->dispatch( 'check_return_eligibility', [ 'order_id' => 100 ] );

        $this->assertArrayNotHasKey( 'error', $result );
        $this->assertTrue( $result['eligible'] );
        $this->assertSame( 30, $result['window_days'] );
    }

    public function test_eligibility_respects_the_return_window(): void {
        // 45 days old > the default 30-day window → ineligible by date.
        $order = $this->mockOrder( [
            'id'               => 100,
            'customer_id'      => 5,
            'status'           => 'completed',
            'created_days_ago' => 45,
        ] );
        Functions\when( 'wc_get_order' )->justReturn( $order );

        $result = $this->registry()->dispatch( 'check_return_eligibility', [ 'order_id' => 100 ] );

        $this->assertFalse( $result['eligible'] );
        // Honest reason + a human-support path; support is never withheld.
        $this->assertNotEmpty( $result['reason'] );
        $this->assertStringContainsString( 'window', strtolower( $result['reason'] ) );
        $this->assertTrue( $result['contact_support'] );
        $this->assertNotEmpty( $result['support'] );
    }

    public function test_eligibility_window_is_filterable(): void {
        // A merchant widens the window to 60 days via the documented filter; a 45-day-old
        // order then becomes eligible. Proves the policy is data-driven, not invented.
        $order = $this->mockOrder( [
            'id'               => 100,
            'customer_id'      => 5,
            'status'           => 'completed',
            'created_days_ago' => 45,
        ] );
        Functions\when( 'wc_get_order' )->justReturn( $order );
        Functions\when( 'apply_filters' )->alias( function ( $hook, $value = null ) {
            return 'fahad_ai_return_window_days' === $hook ? 60 : $value;
        } );

        $result = $this->registry()->dispatch( 'check_return_eligibility', [ 'order_id' => 100 ] );

        $this->assertTrue( $result['eligible'] );
        $this->assertSame( 60, $result['window_days'] );
    }

    public function test_eligibility_respects_order_status(): void {
        // A cancelled order is not returnable regardless of date.
        $order = $this->mockOrder( [
            'id'               => 100,
            'customer_id'      => 5,
            'status'           => 'cancelled',
            'created_days_ago' => 2,
        ] );
        Functions\when( 'wc_get_order' )->justReturn( $order );

        $result = $this->registry()->dispatch( 'check_return_eligibility', [ 'order_id' => 100 ] );

        $this->assertFalse( $result['eligible'] );
        $this->assertNotEmpty( $result['reason'] );
        $this->assertStringContainsString( 'status', strtolower( $result['reason'] ) );
        $this->assertTrue( $result['contact_support'] );
    }

    public function test_eligibility_for_a_specific_item_on_the_order(): void {
        $order = $this->mockOrder( [
            'id'               => 100,
            'customer_id'      => 5,
            'status'           => 'completed',
            'created_days_ago' => 5,
            'items'            => [
                [ 'product_id' => 10, 'name' => 'Blue Hoodie' ],
                [ 'product_id' => 20, 'name' => 'Red Cap' ],
            ],
        ] );
        Functions\when( 'wc_get_order' )->justReturn( $order );

        $result = $this->registry()->dispatch( 'check_return_eligibility', [ 'order_id' => 100, 'item' => 'Red Cap' ] );

        $this->assertTrue( $result['eligible'] );
        $this->assertSame( 'Red Cap', $result['item'] );
    }

    public function test_eligibility_for_an_item_not_on_the_order_is_ineligible(): void {
        $order = $this->mockOrder( [
            'id'               => 100,
            'customer_id'      => 5,
            'status'           => 'completed',
            'created_days_ago' => 5,
            'items'            => [ [ 'product_id' => 10, 'name' => 'Blue Hoodie' ] ],
        ] );
        Functions\when( 'wc_get_order' )->justReturn( $order );

        $result = $this->registry()->dispatch( 'check_return_eligibility', [ 'order_id' => 100, 'item' => 'Garden Gnome' ] );

        $this->assertFalse( $result['eligible'] );
        $this->assertNotEmpty( $result['reason'] );
        $this->assertTrue( $result['contact_support'] );
    }

    public function test_eligibility_missing_order_is_not_found(): void {
        Functions\when( 'wc_get_order' )->justReturn( false );

        $result = $this->registry()->dispatch( 'check_return_eligibility', [ 'order_id' => 999 ] );

        $this->assertArrayHasKey( 'error', $result );
        $this->assertStringContainsString( 'not found', strtolower( $result['error'] ) );
        // Even a "can't find it" answer must still point to a human (never block support).
        $this->assertTrue( $result['contact_support'] );
    }

    // ── OWNERSHIP-BYPASS (the headline security test) ───────────────────────────

    /**
     * Current user is 5. They check eligibility for order #100, owned by user 9. The tool
     * must return a "not found"-style result, NEVER the other user's order, and must not
     * even confirm the order exists (no "forbidden"/"permission" disclosure).
     */
    public function test_eligibility_blocks_access_to_another_users_order(): void {
        $someone_elses = $this->mockOrder( [
            'id'               => 100,
            'customer_id'      => 9, // belongs to a DIFFERENT user
            'status'           => 'completed',
            'created_days_ago' => 1,
            'items'            => [ [ 'product_id' => 77, 'name' => 'Secret Gift' ] ],
        ] );
        Functions\when( 'wc_get_order' )->justReturn( $someone_elses );

        $result = $this->registry()->dispatch( 'check_return_eligibility', [ 'order_id' => 100 ] );

        $this->assertArrayHasKey( 'error', $result );
        $this->assertStringContainsString( 'not found', strtolower( $result['error'] ) );
        $this->assertArrayNotHasKey( 'eligible', $result );
        $encoded = json_encode( $result );
        $this->assertStringNotContainsString( 'Secret Gift', $encoded );
        $this->assertStringNotContainsString( 'forbidden', strtolower( $result['error'] ) );
        $this->assertStringNotContainsString( 'permission', strtolower( $result['error'] ) );
    }

    /**
     * The same ownership boundary must protect the WRITE path: a non-owner cannot create
     * an RMA against someone else's order, and the RMA meta is never written.
     */
    public function test_request_return_blocks_a_non_owner(): void {
        $store_written = false;
        $someone_elses = $this->mockOrder( [
            'id'               => 100,
            'customer_id'      => 9,
            'status'           => 'completed',
            'created_days_ago' => 1,
            'items'            => [ [ 'product_id' => 77, 'name' => 'Secret Gift' ] ],
        ] );
        // If the write path were reached it would call update_meta_data, make that a
        // hard failure for a non-owner.
        $someone_elses->shouldReceive( 'update_meta_data' )->never();
        Functions\when( 'wc_get_order' )->justReturn( $someone_elses );

        $result = $this->registry()->dispatch( 'request_return', [
            'order_id' => 100,
            'items'    => [ 'Secret Gift' ],
            'reason'   => 'changed my mind',
        ] );

        $this->assertArrayHasKey( 'error', $result );
        $this->assertStringContainsString( 'not found', strtolower( $result['error'] ) );
        $this->assertArrayNotHasKey( 'rma_id', $result );
    }

    // ── request_return (happy path, money-safety, idempotency) ──────────────────

    public function test_request_return_records_an_rma_without_issuing_a_refund(): void {
        $order = $this->mockOrder( [
            'id'               => 100,
            'customer_id'      => 5,
            'status'           => 'completed',
            'created_days_ago' => 4,
            'items'            => [ [ 'product_id' => 10, 'name' => 'Blue Hoodie' ] ],
        ] );
        // MONEY-SAFETY: the assistant only records a request. It must NEVER refund or
        // mutate order status, turn any such call into a hard failure.
        Functions\expect( 'wc_create_refund' )->never();
        $order->shouldReceive( 'set_status' )->never();
        $order->shouldReceive( 'update_status' )->never();
        $order->shouldReceive( 'payment_complete' )->never();
        Functions\when( 'wc_get_order' )->justReturn( $order );

        $result = $this->registry()->dispatch( 'request_return', [
            'order_id' => 100,
            'items'    => [ 'Blue Hoodie' ],
            'reason'   => 'Too small',
        ] );

        $this->assertArrayNotHasKey( 'error', $result );
        $this->assertTrue( $result['recorded'] );
        $this->assertArrayHasKey( 'rma_id', $result );
        $this->assertNotEmpty( $result['rma_id'] );
        // Explicitly NOT a refund/credit, only a request.
        $this->assertArrayNotHasKey( 'refund', $result );
        $this->assertArrayNotHasKey( 'refunded', $result );
        // The requested item is echoed back.
        $this->assertContains( 'Blue Hoodie', $result['items'] );
    }

    public function test_request_return_persists_the_rma_to_order_meta(): void {
        $order = $this->mockOrder( [
            'id'               => 100,
            'customer_id'      => 5,
            'status'           => 'completed',
            'created_days_ago' => 4,
            'items'            => [ [ 'product_id' => 10, 'name' => 'Blue Hoodie' ] ],
        ] );
        // The RMA is persisted: save() must be called (the meta write is verified via the
        // in-memory store below). save() has a byDefault in mockOrder; overriding it with a
        // count expectation is unambiguous.
        $order->shouldReceive( 'save' )->atLeast()->once()->andReturn( 100 );
        Functions\when( 'wc_get_order' )->justReturn( $order );

        $result = $this->registry()->dispatch( 'request_return', [
            'order_id' => 100,
            'items'    => [ 'Blue Hoodie' ],
            'reason'   => 'Too small',
        ] );

        $this->assertTrue( $result['recorded'] );
        // The RMA landed in the order meta (one record carrying the reason).
        $stored = $order->get_meta( '_fahad_ai_rma_requests' );
        $this->assertIsArray( $stored );
        $this->assertCount( 1, $stored );
        $this->assertSame( 'Too small', $stored[0]['reason'] );
        $this->assertSame( [ 'Blue Hoodie' ], $stored[0]['items'] );
    }

    public function test_request_return_is_idempotent_for_the_same_items(): void {
        // The same order object is reused across both dispatches so the in-memory RMA meta
        // store persists between them (mirroring real order meta).
        $order = $this->mockOrder( [
            'id'               => 100,
            'customer_id'      => 5,
            'status'           => 'completed',
            'created_days_ago' => 4,
            'items'            => [ [ 'product_id' => 10, 'name' => 'Blue Hoodie' ] ],
        ] );
        Functions\when( 'wc_get_order' )->justReturn( $order );

        $registry = $this->registry();

        $first  = $registry->dispatch( 'request_return', [ 'order_id' => 100, 'items' => [ 'Blue Hoodie' ], 'reason' => 'Too small' ] );
        $second = $registry->dispatch( 'request_return', [ 'order_id' => 100, 'items' => [ 'Blue Hoodie' ], 'reason' => 'Too small' ] );

        $this->assertTrue( $first['recorded'] );
        // Idempotent: the SAME items requested again must NOT create a second RMA.
        $this->assertSame( $first['rma_id'], $second['rma_id'] );
        $this->assertArrayHasKey( 'already_requested', $second );
        $this->assertTrue( $second['already_requested'] );

        // And the stored meta holds exactly ONE request, not two.
        $stored = $order->get_meta( '_fahad_ai_rma_requests' );
        $this->assertCount( 1, $stored );
    }

    public function test_request_return_for_an_ineligible_order_does_not_record_and_escalates(): void {
        // 90 days old → outside the window. A return must NOT be recorded; the customer is
        // given an honest reason and the human-support path.
        $order = $this->mockOrder( [
            'id'               => 100,
            'customer_id'      => 5,
            'status'           => 'completed',
            'created_days_ago' => 90,
            'items'            => [ [ 'product_id' => 10, 'name' => 'Blue Hoodie' ] ],
        ] );
        // No RMA may be written for an ineligible order.
        $order->shouldReceive( 'update_meta_data' )->with( '_fahad_ai_rma_requests', Mockery::any() )->never();
        Functions\when( 'wc_get_order' )->justReturn( $order );

        $result = $this->registry()->dispatch( 'request_return', [
            'order_id' => 100,
            'items'    => [ 'Blue Hoodie' ],
            'reason'   => 'Too small',
        ] );

        $this->assertArrayNotHasKey( 'rma_id', $result );
        $this->assertFalse( $result['recorded'] ?? false );
        $this->assertNotEmpty( $result['reason'] );
        $this->assertTrue( $result['contact_support'] );
    }

    public function test_request_return_requires_at_least_one_item(): void {
        $order = $this->mockOrder( [ 'id' => 100, 'customer_id' => 5, 'status' => 'completed', 'created_days_ago' => 4 ] );
        $order->shouldReceive( 'update_meta_data' )->with( '_fahad_ai_rma_requests', Mockery::any() )->never();
        Functions\when( 'wc_get_order' )->justReturn( $order );

        $result = $this->registry()->dispatch( 'request_return', [ 'order_id' => 100, 'items' => [], 'reason' => 'n/a' ] );

        $this->assertArrayHasKey( 'error', $result );
        $this->assertArrayNotHasKey( 'rma_id', $result );
    }

    public function test_request_return_missing_order_is_not_found(): void {
        Functions\when( 'wc_get_order' )->justReturn( false );

        $result = $this->registry()->dispatch( 'request_return', [ 'order_id' => 999, 'items' => [ 'Blue Hoodie' ], 'reason' => 'x' ] );

        $this->assertArrayHasKey( 'error', $result );
        $this->assertStringContainsString( 'not found', strtolower( $result['error'] ) );
        $this->assertArrayNotHasKey( 'rma_id', $result );
    }

    // ── GUEST-BLOCK (central login gate, callback never reached) ────────────────

    /**
     * A guest dispatching either personal tool must be stopped CENTRALLY by the registry's
     * login gate, before the tool callback runs. We assert the standard login-required
     * error AND, critically, that wc_get_order is NEVER invoked, proving the callback was
     * never reached. is_user_logged_in() is stubbed false.
     *
     * @dataProvider personalToolProvider
     */
    public function test_guest_is_blocked_before_a_personal_tool_callback_runs( string $tool, array $input ): void {
        Functions\when( 'is_user_logged_in' )->justReturn( false );
        Functions\when( 'get_current_user_id' )->justReturn( 0 );

        // If the callback were reached, it would load the order, it must NOT be hit.
        Functions\expect( 'wc_get_order' )->never();

        $result = $this->registry()->dispatch( $tool, $input );

        $this->assertArrayHasKey( 'requires_login', $result );
        $this->assertTrue( $result['requires_login'] );
        $this->assertArrayHasKey( 'error', $result );
        $this->assertStringNotContainsString( 'Unknown tool', $result['error'] );
        // No eligibility / RMA data in a guest result.
        $this->assertArrayNotHasKey( 'eligible', $result );
        $this->assertArrayNotHasKey( 'rma_id', $result );
    }

    /** Both personal tools must be guest-gated. */
    public static function personalToolProvider(): array {
        return [
            'check_return_eligibility' => [ 'check_return_eligibility', [ 'order_id' => 100 ] ],
            'request_return'           => [ 'request_return', [ 'order_id' => 100, 'items' => [ 'Blue Hoodie' ], 'reason' => 'x' ] ],
        ];
    }
}
