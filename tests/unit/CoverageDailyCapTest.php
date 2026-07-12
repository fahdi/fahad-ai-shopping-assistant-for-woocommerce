<?php
/**
 * Coverage for the store-wide daily usage cap (issue #194): the counter + threshold
 * helpers on Fahad_AI_Auth that cap total billable AI answers per day and reset daily
 * without cron. The cap is a filterable cost ceiling (default 0 = unlimited).
 */

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

class CoverageDailyCapTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	// ── daily_cap (option-backed base, filter override, clamped to >= 0) ─────────

	public function test_cap_is_unlimited_by_default(): void {
		Functions\when( 'get_option' )->justReturn( 0 );
		Functions\when( 'apply_filters' )->alias( fn( $tag, $value ) => $value );
		$this->assertSame( 0, Fahad_AI_Auth::daily_cap() );
	}

	public function test_cap_reads_the_saved_setting(): void {
		Functions\when( 'get_option' )->justReturn( 500 );
		Functions\when( 'apply_filters' )->alias( fn( $tag, $value ) => $value );
		$this->assertSame( 500, Fahad_AI_Auth::daily_cap() );
	}

	public function test_cap_filter_overrides_the_saved_setting(): void {
		Functions\when( 'get_option' )->justReturn( 500 );
		Functions\when( 'apply_filters' )->justReturn( 999 );
		$this->assertSame( 999, Fahad_AI_Auth::daily_cap() );
	}

	public function test_cap_clamps_negative_values_to_zero(): void {
		Functions\when( 'get_option' )->justReturn( 0 );
		Functions\when( 'apply_filters' )->justReturn( -5 );
		$this->assertSame( 0, Fahad_AI_Auth::daily_cap() );
	}

	// ── daily_count (auto-resets when the stored day is not today) ───────────────

	public function test_count_is_zero_with_no_record(): void {
		Functions\when( 'get_option' )->justReturn( [] );
		$this->assertSame( 0, Fahad_AI_Auth::daily_count() );
	}

	public function test_count_is_zero_when_the_record_is_from_a_previous_day(): void {
		Functions\when( 'get_option' )->justReturn( [ 'date' => '20200101', 'count' => 99 ] );
		$this->assertSame( 0, Fahad_AI_Auth::daily_count() );
	}

	public function test_count_returns_todays_value(): void {
		Functions\when( 'get_option' )->justReturn( [ 'date' => gmdate( 'Ymd' ), 'count' => 7 ] );
		$this->assertSame( 7, Fahad_AI_Auth::daily_count() );
	}

	// ── daily_cap_reached ───────────────────────────────────────────────────────

	public function test_cap_never_reached_when_unlimited(): void {
		Functions\when( 'apply_filters' )->justReturn( 0 );
		Functions\when( 'get_option' )->justReturn( [ 'date' => gmdate( 'Ymd' ), 'count' => 9999 ] );
		$this->assertFalse( Fahad_AI_Auth::daily_cap_reached() );
	}

	public function test_cap_not_reached_below_the_limit(): void {
		Functions\when( 'apply_filters' )->justReturn( 100 );
		Functions\when( 'get_option' )->justReturn( [ 'date' => gmdate( 'Ymd' ), 'count' => 99 ] );
		$this->assertFalse( Fahad_AI_Auth::daily_cap_reached() );
	}

	public function test_cap_reached_at_the_limit(): void {
		Functions\when( 'apply_filters' )->justReturn( 100 );
		Functions\when( 'get_option' )->justReturn( [ 'date' => gmdate( 'Ymd' ), 'count' => 100 ] );
		$this->assertTrue( Fahad_AI_Auth::daily_cap_reached() );
	}

	// ── record_daily_message ────────────────────────────────────────────────────

	public function test_record_starts_a_fresh_day(): void {
		Functions\when( 'get_option' )->justReturn( [] );
		Functions\expect( 'update_option' )->once()->with( 'fahad_ai_daily_count', [ 'date' => gmdate( 'Ymd' ), 'count' => 1 ], false );
		Fahad_AI_Auth::record_daily_message();
		$this->assertTrue( true );
	}

	public function test_record_increments_within_the_same_day(): void {
		Functions\when( 'get_option' )->justReturn( [ 'date' => gmdate( 'Ymd' ), 'count' => 5 ] );
		Functions\expect( 'update_option' )->once()->with( 'fahad_ai_daily_count', [ 'date' => gmdate( 'Ymd' ), 'count' => 6 ], false );
		Fahad_AI_Auth::record_daily_message();
		$this->assertTrue( true );
	}

	public function test_record_resets_the_counter_on_a_new_day(): void {
		Functions\when( 'get_option' )->justReturn( [ 'date' => '20200101', 'count' => 99 ] );
		Functions\expect( 'update_option' )->once()->with( 'fahad_ai_daily_count', [ 'date' => gmdate( 'Ymd' ), 'count' => 1 ], false );
		Fahad_AI_Auth::record_daily_message();
		$this->assertTrue( true );
	}
}
