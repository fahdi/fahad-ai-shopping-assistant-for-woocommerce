<?php
/**
 * Unit tests for Fahad_AI_Rag_Spike (RAG Phase 0, S0.5).
 *
 * The reusable spike engine behind the `wp fahad-ai rag-spike` command:
 *  - compare(): scores keyword/vector/hybrid recall@k over a catalog + queries
 *  - scan_latency(): synthetic brute-force cosine scan benchmark to project the
 *    MySQL-default latency at catalog sizes the demo store can't reach yet.
 */

use PHPUnit\Framework\TestCase;

class RagSpikeTest extends TestCase {

	public function test_compare_scores_all_three_modes_over_the_golden_set(): void {
		$result = Fahad_AI_Rag_Spike::compare(
			RagGoldenSet::texts(),
			RagGoldenSet::vectors(),
			RagGoldenSet::queries(),
			3
		);

		$this->assertCount( 3, $result['queries'], 'one row per golden query' );
		foreach ( $result['queries'] as $row ) {
			$this->assertArrayHasKey( 'name', $row );
			$this->assertArrayHasKey( 'keyword', $row );
			$this->assertArrayHasKey( 'vector', $row );
			$this->assertArrayHasKey( 'hybrid', $row );
		}

		// The gate, restated through the engine: hybrid wins overall and is perfect here.
		$this->assertSame( 1.0, $result['mean']['hybrid'] );
		$this->assertGreaterThan( $result['mean']['keyword'], $result['mean']['hybrid'] );
		$this->assertGreaterThan( $result['mean']['vector'], $result['mean']['hybrid'] );
	}

	public function test_scan_latency_returns_well_formed_timing(): void {
		$stats = Fahad_AI_Rag_Spike::scan_latency( 200, 512, 5 );

		$this->assertSame( 200, $stats['size'] );
		$this->assertSame( 512, $stats['dim'] );
		$this->assertSame( 5, $stats['trials'] );
		$this->assertGreaterThanOrEqual( 0.0, $stats['p50_ms'] );
		$this->assertGreaterThanOrEqual( $stats['p50_ms'], $stats['p95_ms'], 'p95 >= p50' );
	}
}
