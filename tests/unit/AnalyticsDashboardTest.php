<?php
/**
 * Unit tests for the analytics dashboard's pure helper logic (issue #49).
 *
 * The dashboard rendering and the export/delete admin-post handlers need a browser
 * (output + redirects/headers), but the DATE-RANGE parsing and the order-attribution
 * callback are pure functions the dashboard depends on, and the "selectable date
 * range" is an acceptance criterion — so they are unit-tested here directly.
 *
 * Conventions mirror MerchantConfigTest: the admin settings file is a plain function
 * file (guarded by ABSPATH, which the WC stubs define) loaded once; Brain\Monkey stubs
 * the few WP helpers the functions call.
 */

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__, 2 ) . '/includes/admin-settings.php';

class AnalyticsDashboardTest extends TestCase {

	use MockeryPHPUnitIntegration;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		Functions\stubs( [
			'sanitize_text_field' => fn( $s ) => is_string( $s ) ? trim( $s ) : '',
			'wp_unslash'          => fn( $s ) => $s,
		] );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	// ── date-range parsing (the dashboard's selectable window) ──────────────────

	public function test_parse_date_returns_null_for_blank(): void {
		$this->assertNull( fahad_ai_analytics_parse_date( '', false ) );
		$this->assertNull( fahad_ai_analytics_parse_date( '   ', true ) );
	}

	public function test_parse_date_rejects_a_malformed_date(): void {
		// Only a strict Y-m-d shape is accepted; anything else is null (and never
		// trusted as something else).
		$this->assertNull( fahad_ai_analytics_parse_date( 'not-a-date', false ) );
		$this->assertNull( fahad_ai_analytics_parse_date( '2026/06/18', false ) );
		$this->assertNull( fahad_ai_analytics_parse_date( "2026-06-18'; DROP", false ) );
	}

	public function test_parse_date_start_is_midnight_and_end_is_end_of_day(): void {
		$start = fahad_ai_analytics_parse_date( '2026-06-18', false );
		$end   = fahad_ai_analytics_parse_date( '2026-06-18', true );

		$this->assertIsInt( $start );
		$this->assertIsInt( $end );
		// The end-of-day bound is the last second of the same day → exactly 86399s later.
		$this->assertSame( 86399, $end - $start, 'The inclusive upper bound must cover the whole final day.' );
	}

	// ── outcome labels (display mapping) ────────────────────────────────────────

	public function test_outcome_label_maps_known_outcomes(): void {
		$this->assertSame( 'Escalated', fahad_ai_analytics_outcome_label( Fahad_AI_Analytics::OUTCOME_ESCALATED ) );
		$this->assertSame( 'No matching action', fahad_ai_analytics_outcome_label( Fahad_AI_Analytics::OUTCOME_NO_TOOL_MATCH ) );
	}

	public function test_outcome_label_passes_through_an_unknown_key(): void {
		$this->assertSame( 'mystery', fahad_ai_analytics_outcome_label( 'mystery' ) );
	}

	// ── order attribution callback (best-effort funnel) ─────────────────────────

	public function test_attribute_orders_defaults_to_zero_without_a_filter(): void {
		// No built-in conversation↔order link exists yet, so the honest default is 0
		// attributable orders (never a fabricated number).
		Functions\when( 'apply_filters' )->alias( static fn( $tag, $value, $refs = null ) => $value );

		$this->assertSame( 0, fahad_ai_attribute_orders( [ 'conv-a', 'conv-b' ] ) );
	}

	public function test_attribute_orders_is_filterable(): void {
		// An integration can supply real attribution via the filter; the refs that
		// reached add-to-cart are passed through so the hook can resolve orders.
		Functions\when( 'apply_filters' )->alias(
			static fn( $tag, $value, $refs = [] ) => 'fahad_ai_attributed_orders' === $tag ? count( $refs ) : $value
		);

		$this->assertSame( 2, fahad_ai_attribute_orders( [ 'conv-a', 'conv-b' ] ) );
	}
}
