<?php
/**
 * Unit tests for Fahad_AI_Rrf (RAG Phase 0, S0.2).
 *
 * Reciprocal Rank Fusion merges the keyword and vector ranked id-lists into one
 * ranking by Σ 1/(k + rank). It operates on ranks, not scores, so cosine and
 * BM25 scores never have to be normalised (RAG-DESIGN.md §4.3).
 */

use PHPUnit\Framework\TestCase;

class RrfTest extends TestCase {

	public function test_fuses_two_lists_by_reciprocal_rank(): void {
		// listA ranks: 10=1, 20=2, 30=3 ; listB ranks: 20=1, 40=2, 10=3 (k=60)
		// 20 = 1/62 + 1/61 (best combined), 10 = 1/61 + 1/63, 40 = 1/62, 30 = 1/63
		$fused = Fahad_AI_Rrf::fuse( [ [ 10, 20, 30 ], [ 20, 40, 10 ] ] );
		$this->assertSame( [ 20, 10, 40, 30 ], $fused );
	}

	public function test_an_id_in_multiple_lists_outranks_a_single_list_top_hit(): void {
		// 5 is only rank 3 in BOTH lists; 1 and 3 are rank 1 in one list each.
		// 5: 1/63 + 1/63 = 0.03175 beats 1: 1/61 = 0.01639 — the fusion reward.
		$fused = Fahad_AI_Rrf::fuse( [ [ 1, 2, 5 ], [ 3, 4, 5 ] ] );
		$this->assertSame( 5, $fused[0], 'shared id outranks any single-list rank-1 hit' );
		// Ties resolve deterministically by id ascending: 1 before 3, then 2 before 4.
		$this->assertSame( [ 5, 1, 3, 2, 4 ], $fused );
	}

	public function test_single_list_preserves_its_order(): void {
		$this->assertSame( [ 7, 3, 9 ], Fahad_AI_Rrf::fuse( [ [ 7, 3, 9 ] ] ) );
	}

	public function test_empty_input_yields_empty(): void {
		$this->assertSame( [], Fahad_AI_Rrf::fuse( [] ) );
		$this->assertSame( [], Fahad_AI_Rrf::fuse( [ [], [] ] ) );
	}

	public function test_k_parameter_changes_weighting_but_not_this_ordering(): void {
		// Smaller k sharpens the rank-1 advantage; ordering here is stable.
		$fused = Fahad_AI_Rrf::fuse( [ [ 10, 20 ], [ 10, 30 ] ], 1 );
		$this->assertSame( 10, $fused[0], 'id 10 is rank 1 in both lists' );
	}
}
