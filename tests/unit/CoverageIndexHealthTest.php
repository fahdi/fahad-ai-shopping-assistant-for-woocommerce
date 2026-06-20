<?php
/**
 * Coverage top-up for Fahad_AI_Index_Health::last_error_at() (#110).
 *
 * The sibling IndexHealthTest exercises failures()/last_error()/record_failure()/
 * clear() but never reads the last-error timestamp. These tests drive
 * last_error_at() through its default (no option) and recorded paths.
 */

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;

class CoverageIndexHealthTest extends TestCase {

	use MockeryPHPUnitIntegration;

	/** @var array<string,mixed> */
	private array $options = [];

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		$this->options = [];
		Functions\when( 'update_option' )->alias( function ( $k, $v ) { $this->options[ $k ] = $v; return true; } );
		Functions\when( 'get_option' )->alias( fn( $k, $d = '' ) => $this->options[ $k ] ?? $d );
		Functions\when( 'delete_option' )->alias( function ( $k ) { unset( $this->options[ $k ] ); return true; } );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_last_error_at_defaults_to_zero_when_never_recorded(): void {
		$this->assertSame( 0, Fahad_AI_Index_Health::last_error_at() );
	}

	public function test_last_error_at_returns_recorded_timestamp(): void {
		$before = time();
		Fahad_AI_Index_Health::record_failure( 'boom' );
		$after = time();

		$at = Fahad_AI_Index_Health::last_error_at();
		$this->assertGreaterThanOrEqual( $before, $at );
		$this->assertLessThanOrEqual( $after, $at );
	}

	public function test_last_error_at_casts_stored_value_to_int(): void {
		// A stored string timestamp must come back as a real int.
		$this->options['fahad_ai_index_last_error_at'] = '1700000000';
		$this->assertSame( 1700000000, Fahad_AI_Index_Health::last_error_at() );
	}

	public function test_clear_resets_last_error_at_to_default(): void {
		Fahad_AI_Index_Health::record_failure( 'boom' );
		$this->assertGreaterThan( 0, Fahad_AI_Index_Health::last_error_at() );

		Fahad_AI_Index_Health::clear();
		$this->assertSame( 0, Fahad_AI_Index_Health::last_error_at() );
	}
}
