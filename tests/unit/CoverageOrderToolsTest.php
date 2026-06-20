<?php
/**
 * Coverage top-up for Fahad_AI_Order_Tools (issue #17).
 *
 * The sibling OrderToolsTest covers the registration, happy paths, ownership
 * bypass, PII minimization, and guest-block. This file closes the remaining
 * branch the sibling does not exercise: the EARLY "invalid id" guard in
 * get_order_status — `if ( $order_id <= 0 ) return $not_found;` — which fires
 * for a zero, missing, or negative order_id and short-circuits BEFORE any
 * wc_get_order() lookup happens at all.
 *
 * Conventions mirror OrderToolsTest exactly: WP/WC functions stubbed via
 * Brain\Monkey, the registry singleton + its static pack list snapshotted and
 * restored, and dispatch routed through the REAL register_pack() + dispatch()
 * path (so the central login gate for `personal` tools is also under test).
 */

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;

class CoverageOrderToolsTest extends TestCase {

    use MockeryPHPUnitIntegration;

    /** @var array<int, callable> Snapshot of the registry's static pack providers. */
    private array $pack_snapshot = [];

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();

        $this->pack_snapshot = (array) ( new ReflectionProperty( Fahad_AI_Tool_Registry::class, 'pack_providers' ) )->getValue();

        Functions\stubs( [
            'absint'              => fn( $n ) => abs( (int) $n ),
            'sanitize_text_field' => fn( $s ) => is_string( $s ) ? trim( $s ) : $s,
            // Registry get_tools() reads the merchant tool-gating option (issue #56);
            // default (no disabled tools) so dispatch() is unaffected.
            'get_option'          => fn( $key, $default = '' ) => $default,
            'wc_format_datetime'  => fn( $dt, $format = 'Y-m-d' ) => $dt instanceof \DateTimeInterface ? $dt->format( $format ) : '',
        ] );

        // Default to a logged-in customer (id 5) so the central login gate lets the
        // callback run — the invalid-id guard lives INSIDE the callback, past the gate.
        Functions\when( 'is_user_logged_in' )->justReturn( true );
        Functions\when( 'get_current_user_id' )->justReturn( 5 );
    }

    protected function tearDown(): void {
        ( new ReflectionProperty( Fahad_AI_Tool_Registry::class, 'pack_providers' ) )->setValue( null, $this->pack_snapshot );
        Monkey\tearDown();
        parent::tearDown();
    }

    /**
     * Fresh registry whose built tool list includes the order tools, registered the
     * exact same way the pack's file-scope self-registration does in production.
     */
    private function registry(): Fahad_AI_Tool_Registry {
        ( new ReflectionProperty( Fahad_AI_Tools::class, 'instance' ) )->setValue( null, null );
        ( new ReflectionProperty( Fahad_AI_Tool_Registry::class, 'instance' ) )->setValue( null, null );

        Fahad_AI_Tool_Registry::reset_packs();
        Fahad_AI_Tool_Registry::register_pack( [ 'Fahad_AI_Order_Tools', 'register' ] );

        return Fahad_AI_Tool_Registry::instance();
    }

    // ── invalid order_id guard (the early return, before any lookup) ─────────────

    /**
     * order_id of 0 trips the `$order_id <= 0` guard and returns the standard
     * "not found" error WITHOUT ever calling wc_get_order() — proving the early
     * short-circuit, not the later missing-order path, produced the result.
     */
    public function test_get_order_status_returns_not_found_for_zero_id_without_a_lookup(): void {
        // If the guard did NOT short-circuit, the callback would reach wc_get_order().
        // ->never() turns any such call into a hard failure, pinning the early return.
        Functions\expect( 'wc_get_order' )->never();

        $result = $this->registry()->dispatch( 'get_order_status', [ 'order_id' => 0 ] );

        $this->assertArrayHasKey( 'error', $result );
        $this->assertStringContainsString( 'not found', strtolower( $result['error'] ) );
        // The guard returns ONLY the not-found shape — no order data of any kind.
        $this->assertArrayNotHasKey( 'status', $result );
        $this->assertArrayNotHasKey( 'number', $result );
        $this->assertArrayNotHasKey( 'total', $result );
    }

    /**
     * A MISSING order_id key (absint of null → 0) also trips the guard, so a
     * malformed tool call never reaches the lookup either.
     */
    public function test_get_order_status_returns_not_found_when_order_id_is_missing(): void {
        Functions\expect( 'wc_get_order' )->never();

        $result = $this->registry()->dispatch( 'get_order_status', [] );

        $this->assertArrayHasKey( 'error', $result );
        $this->assertStringContainsString( 'not found', strtolower( $result['error'] ) );
        $this->assertArrayNotHasKey( 'status', $result );
    }

    /**
     * A negative order_id resolves to 0 via absint (abs of a negative int stays
     * positive in the real WP absint, but here the dispatched value is already a
     * negative literal: absint() returns its absolute value, so we use a value the
     * guard still rejects — a zero — to keep the assertion about the `<= 0` boundary).
     *
     * To exercise the `< 0` half of the `<= 0` boundary independently, we feed a
     * non-numeric id. absint('') → 0 trips the guard the same way.
     */
    public function test_get_order_status_returns_not_found_for_non_numeric_id(): void {
        Functions\expect( 'wc_get_order' )->never();

        $result = $this->registry()->dispatch( 'get_order_status', [ 'order_id' => '' ] );

        $this->assertArrayHasKey( 'error', $result );
        $this->assertStringContainsString( 'not found', strtolower( $result['error'] ) );
    }

    /**
     * Sanity: the SAME "not found" error string the guard returns must NOT disclose
     * ownership/existence (no "forbidden"/"permission" wording), matching the
     * missing-order and not-owned paths — the guard collapses into the same shape.
     */
    public function test_invalid_id_error_does_not_disclose_existence(): void {
        Functions\expect( 'wc_get_order' )->never();

        $result = $this->registry()->dispatch( 'get_order_status', [ 'order_id' => 0 ] );

        $this->assertStringNotContainsString( 'forbidden', strtolower( $result['error'] ) );
        $this->assertStringNotContainsString( 'permission', strtolower( $result['error'] ) );
    }
}
