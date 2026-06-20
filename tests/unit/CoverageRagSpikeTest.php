<?php
/**
 * Coverage tests for Fahad_AI_Rag_Spike — the private percentile() helper.
 *
 * scan_latency() never feeds percentile() an empty list (it forces
 * $trials = max(1, $trials), so at least one timing always exists), so the
 * empty-list guard at the top of percentile() is unreachable through the public
 * API. We reach it directly with ReflectionMethod to prove the guard returns
 * 0.0 for an empty sample, and exercise the nearest-rank index math for the
 * non-empty path (including the clamp at both ends).
 */

use PHPUnit\Framework\TestCase;

class CoverageRagSpikeTest extends TestCase {

	private function percentile( array $sorted, int $p ): float {
		$method = new ReflectionMethod( Fahad_AI_Rag_Spike::class, 'percentile' );
		return $method->invoke( null, $sorted, $p );
	}

	public function test_percentile_of_empty_sample_is_zero(): void {
		// The uncovered guard: no samples => 0.0, never an undefined-index notice.
		$this->assertSame( 0.0, $this->percentile( [], 50 ) );
		$this->assertSame( 0.0, $this->percentile( [], 95 ) );
		$this->assertSame( 0.0, $this->percentile( [], 0 ) );
	}

	public function test_percentile_nearest_rank_picks_the_expected_element(): void {
		$sorted = [ 10.0, 20.0, 30.0, 40.0 ];

		// p50: ceil(0.5 * 4) - 1 = ceil(2) - 1 = 1 => index 1 => 20.0
		$this->assertSame( 20.0, $this->percentile( $sorted, 50 ) );
		// p95: ceil(0.95 * 4) - 1 = ceil(3.8) - 1 = 3 => index 3 => 40.0
		$this->assertSame( 40.0, $this->percentile( $sorted, 95 ) );
		// p100: ceil(4) - 1 = 3 => index 3 => 40.0 (top of range)
		$this->assertSame( 40.0, $this->percentile( $sorted, 100 ) );
	}

	public function test_percentile_clamps_low_index_to_first_element(): void {
		$sorted = [ 7.0, 8.0, 9.0 ];

		// p0: ceil(0) - 1 = -1, clamped up to 0 => first element.
		$this->assertSame( 7.0, $this->percentile( $sorted, 0 ) );
	}

	public function test_percentile_single_element_returns_that_element(): void {
		// ceil(anything in (0,1]) - 1 = 0, clamped into [0,0] => only element.
		$this->assertSame( 42.5, $this->percentile( [ 42.5 ], 50 ) );
		$this->assertSame( 42.5, $this->percentile( [ 42.5 ], 95 ) );
	}

	public function test_percentile_always_returns_float_type(): void {
		// Source casts to (float); an int-valued sample still comes back as float.
		$value = $this->percentile( [ 5 ], 50 );
		$this->assertIsFloat( $value );
		$this->assertSame( 5.0, $value );
	}
}
