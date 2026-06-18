<?php
/**
 * Unit tests for Fahad_AI_Rag_Spike_Retriever (RAG Phase 0, S0.4).
 *
 * The three retrieval legs used by the spike comparison and the CLI:
 *  - keyword_rank: token-overlap baseline (stands in for the existing search leg)
 *  - vector_rank:  cosine over embeddings, candidates above a threshold
 *  - hybrid_rank:  RRF fusion of the two (reuses Fahad_AI_Rrf)
 * Each returns ids best-first; non-candidates (no overlap / cos ≤ threshold) are
 * excluded, mirroring how real legs return only their candidates.
 */

use PHPUnit\Framework\TestCase;

class RagSpikeRetrieverTest extends TestCase {

	public function test_keyword_rank_scores_by_token_overlap_and_excludes_zero(): void {
		$docs = [
			1 => 'wool winter coat warm',
			2 => 'summer swim shorts',
			3 => 'warm gloves',
		];
		// query tokens {warm, winter}: doc1=2, doc3=1, doc2=0 (excluded)
		$this->assertSame( [ 1, 3 ], Fahad_AI_Rag_Spike_Retriever::keyword_rank( 'warm winter', $docs ) );
	}

	public function test_keyword_rank_is_case_insensitive_and_tokenised(): void {
		$docs = [ 5 => 'Warm, WINTER coat!' ];
		$this->assertSame( [ 5 ], Fahad_AI_Rag_Spike_Retriever::keyword_rank( 'warm winter', $docs ) );
	}

	public function test_vector_rank_orders_by_cosine_and_excludes_non_candidates(): void {
		$vecs = [
			1 => [ 1.0, 0.0, 0.0 ],
			2 => [ 0.0, 1.0, 0.0 ],  // orthogonal -> cos 0 -> excluded
			3 => [ 0.9, 0.0, 0.0 ],  // same direction -> cos 1
		];
		// query [1,0,0]: doc1 cos1, doc3 cos1 (tie -> id asc), doc2 cos0 excluded
		$this->assertSame( [ 1, 3 ], Fahad_AI_Rag_Spike_Retriever::vector_rank( [ 1.0, 0.0, 0.0 ], $vecs ) );
	}

	public function test_hybrid_rank_recovers_items_each_leg_alone_misses(): void {
		// keyword finds only 7; vector finds only 8 -> hybrid surfaces both at the top.
		$hybrid = Fahad_AI_Rag_Spike_Retriever::hybrid_rank( [ 7 ], [ 8 ] );
		$this->assertSame( [ 7, 8 ], $hybrid, 'RRF of disjoint single-hit legs returns both (tie -> id asc)' );
	}

	public function test_hybrid_rank_matches_rrf_of_the_legs(): void {
		$kw  = [ 1, 3 ];
		$vec = [ 3, 2 ];
		$this->assertSame(
			Fahad_AI_Rrf::fuse( [ $kw, $vec ] ),
			Fahad_AI_Rag_Spike_Retriever::hybrid_rank( $kw, $vec )
		);
	}
}
