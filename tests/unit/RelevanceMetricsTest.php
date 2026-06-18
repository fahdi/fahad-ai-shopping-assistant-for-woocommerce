<?php
/**
 * Unit tests for Fahad_AI_Relevance_Metrics (RAG Phase 0, S0.4).
 *
 * recall@k / precision@k / nDCG@k over a retrieved id-list vs a relevant set,
 * with binary relevance. Used by the golden-set comparison (keyword vs vector
 * vs hybrid) and by the spike CLI report.
 */

use PHPUnit\Framework\TestCase;

class RelevanceMetricsTest extends TestCase {

	public function test_recall_at_k(): void {
		$retrieved = [ 1, 2, 3, 4 ];
		$relevant  = [ 2, 4 ];
		$this->assertSame( 1.0, Fahad_AI_Relevance_Metrics::recall_at_k( $retrieved, $relevant, 4 ) );
		// top-2 = [1,2] ∩ {2,4} = {2} → 1/2
		$this->assertSame( 0.5, Fahad_AI_Relevance_Metrics::recall_at_k( $retrieved, $relevant, 2 ) );
	}

	public function test_precision_at_k(): void {
		$retrieved = [ 1, 2, 3, 4 ];
		$relevant  = [ 2, 4 ];
		// top-4 has 2 relevant of 4 slots → 0.5
		$this->assertSame( 0.5, Fahad_AI_Relevance_Metrics::precision_at_k( $retrieved, $relevant, 4 ) );
		// top-2 = [1,2] → 1 relevant of 2 → 0.5
		$this->assertSame( 0.5, Fahad_AI_Relevance_Metrics::precision_at_k( $retrieved, $relevant, 2 ) );
	}

	public function test_recall_with_empty_relevant_is_one_vacuously(): void {
		$this->assertSame( 1.0, Fahad_AI_Relevance_Metrics::recall_at_k( [ 1, 2 ], [], 2 ) );
	}

	public function test_ndcg_perfect_ranking_is_one(): void {
		// Relevant item at position 1 → DCG == IDCG.
		$this->assertEqualsWithDelta( 1.0, Fahad_AI_Relevance_Metrics::ndcg_at_k( [ 2, 1, 3 ], [ 2 ], 3 ), 1e-9 );
	}

	public function test_ndcg_discounts_lower_positions(): void {
		// Single relevant item at position 2 → 1/log2(3) ≈ 0.63093.
		$this->assertEqualsWithDelta( 0.63093, Fahad_AI_Relevance_Metrics::ndcg_at_k( [ 1, 2, 3 ], [ 2 ], 3 ), 1e-5 );
	}

	public function test_ndcg_two_relevant_below_ideal(): void {
		// retrieved [1,2,3], relevant {2,3}: DCG = 1/log2(3) + 1/log2(4) = 1.13093
		// IDCG (ideal [2,3,..]) = 1/log2(2) + 1/log2(3) = 1.63093 → nDCG ≈ 0.69343
		$this->assertEqualsWithDelta( 0.69343, Fahad_AI_Relevance_Metrics::ndcg_at_k( [ 1, 2, 3 ], [ 2, 3 ], 3 ), 1e-5 );
	}
}
