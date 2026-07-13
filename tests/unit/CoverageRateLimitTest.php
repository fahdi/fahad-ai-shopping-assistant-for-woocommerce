<?php
/**
 * Coverage for the owner-settable per-visitor rate limit (issue #249): fahad_ai_rate_limit_value()
 * in admin-settings.php. The base is the saved fahad_ai_rate_limit option (default 20); the
 * fahad_ai_rate_limit filter still overrides it for developers; the result is floored at 1 so a
 * misconfigured 0 cannot disable abuse protection. is_rate_limited() consumes this getter.
 */

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

class CoverageRateLimitTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_defaults_to_twenty(): void {
		Functions\when( 'get_option' )->justReturn( 20 );
		Functions\when( 'apply_filters' )->alias( fn( $tag, $value ) => $value );
		$this->assertSame( 20, fahad_ai_rate_limit_value() );
	}

	public function test_reads_the_saved_setting(): void {
		Functions\when( 'get_option' )->justReturn( 5 );
		Functions\when( 'apply_filters' )->alias( fn( $tag, $value ) => $value );
		$this->assertSame( 5, fahad_ai_rate_limit_value() );
	}

	public function test_filter_overrides_the_saved_setting(): void {
		Functions\when( 'get_option' )->justReturn( 5 );
		Functions\when( 'apply_filters' )->justReturn( 99 );
		$this->assertSame( 99, fahad_ai_rate_limit_value() );
	}

	public function test_floored_at_one(): void {
		Functions\when( 'get_option' )->justReturn( 0 );
		Functions\when( 'apply_filters' )->justReturn( 0 );
		$this->assertSame( 1, fahad_ai_rate_limit_value() );
	}
}
