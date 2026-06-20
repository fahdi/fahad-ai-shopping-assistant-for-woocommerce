<?php
/**
 * Coverage gap tests for Fahad_AI_Relevance_Metrics (RAG Phase 0, S0.4).
 *
 * Targets the precision@k non-positive-k guard clause (early return 0.0) and
 * the related edge paths not exercised by RelevanceMetricsTest. The class is a
 * pure static utility with no WordPress dependencies, so no Brain\Monkey setup
 * is required.
 */

use PHPUnit\Framework\TestCase;

class CoverageRelevanceMetricsTest extends TestCase {

	/**
	 * precision_at_k must short-circuit to 0.0 when k is zero, exercising the
	 * `if ( $k <= 0 ) { return 0.0; }` guard (avoids division by zero).
	 */
	public function test_precision_at_k_with_zero_k_returns_zero(): void {
		$result = Fahad_AI_Relevance_Metrics::precision_at_k( [ 1, 2, 3 ], [ 2 ], 0 );
		$this->assertSame( 0.0, $result );
	}

	/**
	 * The same guard fires for any negative k.
	 */
	public function test_precision_at_k_with_negative_k_returns_zero(): void {
		$result = Fahad_AI_Relevance_Metrics::precision_at_k( [ 1, 2, 3 ], [ 2 ], -5 );
		$this->assertSame( 0.0, $result );
	}

	/**
	 * The guard fires before division even when there are no relevant items,
	 * confirming it is the k-check (not the data) driving the 0.0 result.
	 */
	public function test_precision_at_k_zero_k_with_empty_relevant_returns_zero(): void {
		$result = Fahad_AI_Relevance_Metrics::precision_at_k( [ 1, 2, 3 ], [], 0 );
		$this->assertSame( 0.0, $result );
	}
}
