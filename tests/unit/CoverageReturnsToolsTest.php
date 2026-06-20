<?php
/**
 * Supplemental line-coverage tests for Fahad_AI_Returns_Tools (issue #53).
 *
 * The primary behavioural suite lives in ReturnsToolsTest. This file closes the few
 * remaining UNCOVERED branches that the behavioural suite does not exercise, while still
 * asserting REAL, correct behaviour (not bare smoke calls):
 *
 *   - the window gate's "no usable order date" escalation (order_timestamp() returns null),
 *   - load_owned_order()'s non-positive order_id guard,
 *   - resolve_items()'s non-array input guard and its per-element non-scalar skip,
 *   - order_has_item()'s empty-needle short-circuit.
 *
 * Conventions mirror ReturnsToolsTest / ApiHandlerTest exactly: WP/WC functions stubbed via
 * Brain\Monkey, WC objects via Mockery, the registry's static pack list snapshotted and
 * restored so a case here neither inherits another suite's packs nor leaks the returns pack
 * we register, and ReflectionMethod to reach the private helpers a couple of branches can
 * only be hit through directly.
 */

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;

class CoverageReturnsToolsTest extends TestCase {

    use MockeryPHPUnitIntegration;

    /** A fixed "now" the eligibility window math is measured against (stubbed current_time). */
    private const NOW = 1750000000;

    /** @var array<int, callable> Snapshot of the registry's static pack providers. */
    private array $pack_snapshot = [];

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();

        $this->pack_snapshot = (array) ( new ReflectionProperty( Fahad_AI_Tool_Registry::class, 'pack_providers' ) )->getValue();

        Functions\stubs( [
            'absint'                  => fn( $n ) => abs( (int) $n ),
            'sanitize_text_field'     => fn( $s ) => is_string( $s ) ? trim( $s ) : $s,
            'sanitize_textarea_field' => fn( $s ) => is_string( $s ) ? trim( $s ) : $s,
            'get_option'              => fn( $key, $default = '' ) => $default,
            'wp_strip_all_tags'       => fn( $s ) => strip_tags( (string) $s ),
            'current_time'            => fn( $type = 'timestamp', $gmt = 0 ) => self::NOW,
            'apply_filters'           => fn( $hook, $value = null ) => $value,
        ] );

        Functions\when( 'is_user_logged_in' )->justReturn( true );
        Functions\when( 'get_current_user_id' )->justReturn( 5 );
    }

    protected function tearDown(): void {
        ( new ReflectionProperty( Fahad_AI_Tool_Registry::class, 'pack_providers' ) )->setValue( null, $this->pack_snapshot );
        Monkey\tearDown();
        parent::tearDown();
    }

    /** Fresh registry whose built tool list includes the returns tools (mirrors ReturnsToolsTest). */
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

    /** A line item exposing the get_name() the resolver/eligibility helpers read. */
    private function mockLineItem( string $name ) {
        $line = Mockery::mock( 'WC_Order_Item_Product' );
        $line->shouldReceive( 'get_name' )->andReturn( $name );
        return $line;
    }

    /**
     * Build a Mockery WC_Order. `created_days_ago => null` models an order whose
     * get_date_created() returns null (no usable date), which the others never do.
     *
     * @param array $spec { id?, customer_id?, status?, created_days_ago?, items? }
     */
    private function mockOrder( array $spec ): WC_Order {
        $o = Mockery::mock( WC_Order::class );
        $o->shouldReceive( 'get_id' )->andReturn( (int) ( $spec['id'] ?? 100 ) );
        $o->shouldReceive( 'get_customer_id' )->andReturn( (int) ( $spec['customer_id'] ?? 5 ) );
        $o->shouldReceive( 'get_status' )->andReturn( (string) ( $spec['status'] ?? 'completed' ) );

        // array_key_exists (not ??) so an EXPLICIT null in the spec models a dateless order
        // rather than collapsing to the default via null-coalescing.
        $created_days = array_key_exists( 'created_days_ago', $spec ) ? $spec['created_days_ago'] : 3;
        if ( null === $created_days ) {
            $o->shouldReceive( 'get_date_created' )->andReturn( null );
        } else {
            $dt = ( new \DateTime() )->setTimestamp( $this->daysAgo( (int) $created_days ) );
            $o->shouldReceive( 'get_date_created' )->andReturn( $dt );
        }

        $items = [];
        foreach ( $spec['items'] ?? [ 'Blue Hoodie' ] as $i => $name ) {
            $items[ $i ] = $this->mockLineItem( (string) $name );
        }
        $o->shouldReceive( 'get_items' )->andReturn( $items );

        $store = (object) [ 'rma' => [] ];
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

    /** Invoke a private static method on Fahad_AI_Returns_Tools directly. */
    private function callPrivate( string $method, array $args ) {
        // No setAccessible() needed: private methods are already invokable via reflection on
        // PHP 8.1+, and setAccessible() is a deprecated no-op there.
        $m = new ReflectionMethod( Fahad_AI_Returns_Tools::class, $method );
        return $m->invokeArgs( null, $args );
    }

    // ── window gate: "no usable order date" escalation (lines 277-280, 444) ─────

    /**
     * An order with an ELIGIBLE status but NO usable creation date passes the status gate,
     * then hits the window gate with a null timestamp. The tool must NOT guess eligibility:
     * it escalates with an honest "can't confirm the date" reason and the human-support path,
     * and never reports the order as eligible.
     */
    public function test_eligibility_escalates_when_the_order_has_no_usable_date(): void {
        $order = $this->mockOrder( [
            'id'               => 100,
            'customer_id'      => 5,
            'status'           => 'completed', // passes the status gate so the window gate runs
            'created_days_ago' => null,        // get_date_created() === null → order_timestamp() null
        ] );
        Functions\when( 'wc_get_order' )->justReturn( $order );

        $result = $this->registry()->dispatch( 'check_return_eligibility', [ 'order_id' => 100 ] );

        $this->assertFalse( $result['eligible'] );
        $this->assertNotEmpty( $result['reason'] );
        // The honest reason is specifically about not being able to confirm the date.
        $this->assertStringContainsString( 'date', strtolower( $result['reason'] ) );
        $this->assertTrue( $result['contact_support'] );
        $this->assertNotEmpty( $result['support'] );
        // window_days is still reported (the base payload), but eligibility is not granted.
        $this->assertSame( 30, $result['window_days'] );
    }

    /** order_timestamp() returns null for a dateless order (the helper directly, line 444). */
    public function test_order_timestamp_is_null_for_a_dateless_order(): void {
        $order = $this->mockOrder( [ 'created_days_ago' => null ] );

        $this->assertNull( $this->callPrivate( 'order_timestamp', [ $order ] ) );
    }

    /** And returns the creation unix timestamp when a date IS present (the non-null branch). */
    public function test_order_timestamp_returns_the_creation_unix_time(): void {
        $order = $this->mockOrder( [ 'created_days_ago' => 7 ] );

        $this->assertSame( $this->daysAgo( 7 ), $this->callPrivate( 'order_timestamp', [ $order ] ) );
    }

    // ── load_owned_order(): non-positive order id guard (line 332) ──────────────

    /**
     * A dispatch with order_id 0 (e.g. the model omitted/zeroed it) must collapse to the
     * standard "not found" result WITHOUT ever loading an order — wc_get_order must not be
     * called, because load_owned_order() bails on the non-positive id first.
     */
    public function test_eligibility_with_a_zero_order_id_is_not_found_without_a_lookup(): void {
        Functions\expect( 'wc_get_order' )->never();

        $result = $this->registry()->dispatch( 'check_return_eligibility', [ 'order_id' => 0 ] );

        $this->assertArrayHasKey( 'error', $result );
        $this->assertStringContainsString( 'not found', strtolower( $result['error'] ) );
        $this->assertArrayNotHasKey( 'eligible', $result );
        $this->assertTrue( $result['contact_support'] );
    }

    /** load_owned_order() returns null for any non-positive id (the guard directly, line 332). */
    public function test_load_owned_order_returns_null_for_non_positive_id(): void {
        Functions\expect( 'wc_get_order' )->never();

        $this->assertNull( $this->callPrivate( 'load_owned_order', [ 0 ] ) );
        $this->assertNull( $this->callPrivate( 'load_owned_order', [ -1 ] ) );
    }

    // ── resolve_items(): non-array input guard (line 369) ───────────────────────

    /**
     * The write path tolerates a malformed `items` (the model sent a string, not an array):
     * resolve_items() returns [] for a non-array, so request_return reports "tell me which
     * item(s)" and records NOTHING — no RMA may be written.
     */
    public function test_request_return_with_non_array_items_records_nothing(): void {
        $order = $this->mockOrder( [ 'id' => 100, 'customer_id' => 5, 'status' => 'completed', 'created_days_ago' => 4 ] );
        $order->shouldReceive( 'update_meta_data' )->with( '_fahad_ai_rma_requests', Mockery::any() )->never();
        Functions\when( 'wc_get_order' )->justReturn( $order );

        $result = $this->registry()->dispatch( 'request_return', [
            'order_id' => 100,
            'items'    => 'Blue Hoodie', // a STRING, not an array
            'reason'   => 'x',
        ] );

        $this->assertArrayHasKey( 'error', $result );
        $this->assertArrayNotHasKey( 'rma_id', $result );
        $this->assertFalse( $result['recorded'] );
        $this->assertTrue( $result['contact_support'] );
    }

    /** resolve_items() returns [] directly for a non-array argument (the guard, line 369). */
    public function test_resolve_items_returns_empty_for_non_array_input(): void {
        $order = $this->mockOrder( [ 'items' => [ 'Blue Hoodie' ] ] );

        $this->assertSame( [], $this->callPrivate( 'resolve_items', [ $order, 'not-an-array' ] ) );
        $this->assertSame( [], $this->callPrivate( 'resolve_items', [ $order, null ] ) );
    }

    // ── resolve_items(): per-element non-scalar skip (line 385) ─────────────────

    /**
     * Non-scalar elements inside the items array (e.g. the model nested an object/array) are
     * skipped, while a valid scalar name alongside them still resolves. Proves the resolver
     * sanitises the model's input element-by-element.
     */
    public function test_resolve_items_skips_non_scalar_elements_but_keeps_valid_names(): void {
        $order = $this->mockOrder( [ 'items' => [ 'Blue Hoodie', 'Red Cap' ] ] );

        $resolved = $this->callPrivate( 'resolve_items', [
            $order,
            [ [ 'nested' => 'array' ], (object) [ 'x' => 1 ], 'Blue Hoodie' ],
        ] );

        // The two non-scalars are dropped; the on-order scalar survives.
        $this->assertSame( [ 'Blue Hoodie' ], $resolved );
    }

    /** When EVERY element is non-scalar, resolve_items() yields nothing. */
    public function test_resolve_items_with_only_non_scalar_elements_is_empty(): void {
        $order = $this->mockOrder( [ 'items' => [ 'Blue Hoodie' ] ] );

        $resolved = $this->callPrivate( 'resolve_items', [ $order, [ [ 'a' ], (object) [] ] ] );

        $this->assertSame( [], $resolved );
    }

    // ── order_has_item(): empty-needle short-circuit (line 400) ─────────────────

    /**
     * A whitespace-only / empty item name can never match a line item: order_has_item()
     * short-circuits to false on an empty needle BEFORE scanning the order's items. Driven
     * directly because the eligibility entry point trims the item first, so this guard is
     * only reachable through the helper itself.
     */
    public function test_order_has_item_is_false_for_an_empty_or_whitespace_needle(): void {
        $order = $this->mockOrder( [ 'items' => [ 'Blue Hoodie' ] ] );

        $this->assertFalse( $this->callPrivate( 'order_has_item', [ $order, '   ' ] ) );
        $this->assertFalse( $this->callPrivate( 'order_has_item', [ $order, '' ] ) );
    }

    /** Sanity: a real, present item name still matches case-insensitively (the true branch). */
    public function test_order_has_item_matches_a_present_name_case_insensitively(): void {
        $order = $this->mockOrder( [ 'items' => [ 'Blue Hoodie' ] ] );

        $this->assertTrue( $this->callPrivate( 'order_has_item', [ $order, 'blue hoodie' ] ) );
        $this->assertFalse( $this->callPrivate( 'order_has_item', [ $order, 'Garden Gnome' ] ) );
    }
}
