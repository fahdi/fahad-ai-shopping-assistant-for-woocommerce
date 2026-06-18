<?php
/**
 * RAG retrieval-quality gate (RAG Phase 0, S0.4 / AC4).
 *
 * Runs the golden set (RagGoldenSet) through the three retrieval legs and
 * asserts the regression gate from RAG-DESIGN.md §6.1: hybrid (RRF) must never
 * do worse than either leg alone, and must beat keyword-only overall — otherwise
 * the added complexity is not earning its place. Deterministic and offline
 * (canned embeddings); proves the measurement is meaningful before a live
 * provider is wired in Phase 1.
 */

use PHPUnit\Framework\TestCase;

class RagRelevanceGateTest extends TestCase {

	private const K = 3;

	/** @return array{keyword: float, vector: float, hybrid: float} recall@K for one query. */
	private function recall_for_query( array $q ): array {
		$texts = RagGoldenSet::texts();
		$vecs  = RagGoldenSet::vectors();

		$keyword = Fahad_AI_Rag_Spike_Retriever::keyword_rank( $q['query'], $texts );
		$vector  = Fahad_AI_Rag_Spike_Retriever::vector_rank( $q['vec'], $vecs );
		$hybrid  = Fahad_AI_Rag_Spike_Retriever::hybrid_rank( $keyword, $vector );

		return [
			'keyword' => Fahad_AI_Relevance_Metrics::recall_at_k( $keyword, $q['relevant'], self::K ),
			'vector'  => Fahad_AI_Relevance_Metrics::recall_at_k( $vector, $q['relevant'], self::K ),
			'hybrid'  => Fahad_AI_Relevance_Metrics::recall_at_k( $hybrid, $q['relevant'], self::K ),
		];
	}

	public function test_hybrid_never_does_worse_than_either_leg_per_query(): void {
		foreach ( RagGoldenSet::queries() as $q ) {
			$r = $this->recall_for_query( $q );
			$this->assertGreaterThanOrEqual(
				$r['keyword'],
				$r['hybrid'],
				"hybrid must be >= keyword for query '{$q['name']}'"
			);
			$this->assertGreaterThanOrEqual(
				$r['vector'],
				$r['hybrid'],
				"hybrid must be >= vector for query '{$q['name']}'"
			);
		}
	}

	public function test_hybrid_beats_each_leg_on_aggregate_recall(): void {
		$queries = RagGoldenSet::queries();
		$sum     = [ 'keyword' => 0.0, 'vector' => 0.0, 'hybrid' => 0.0 ];
		foreach ( $queries as $q ) {
			foreach ( $this->recall_for_query( $q ) as $mode => $recall ) {
				$sum[ $mode ] += $recall;
			}
		}
		$n    = count( $queries );
		$mean = array_map( static fn( $s ) => $s / $n, $sum );

		$this->assertGreaterThan( $mean['keyword'], $mean['hybrid'], 'hybrid must beat keyword-only overall' );
		$this->assertGreaterThan( $mean['vector'], $mean['hybrid'], 'hybrid must beat vector-only overall' );
		$this->assertSame( 1.0, $mean['hybrid'], 'hybrid recovers every relevant item on this golden set' );
	}

	public function test_at_least_one_query_is_a_strict_hybrid_win(): void {
		$strict = 0;
		foreach ( RagGoldenSet::queries() as $q ) {
			$r = $this->recall_for_query( $q );
			if ( $r['hybrid'] > $r['keyword'] && $r['hybrid'] > $r['vector'] ) {
				++$strict;
			}
		}
		$this->assertGreaterThanOrEqual(
			1,
			$strict,
			'at least one query where hybrid strictly beats BOTH legs (the split case neither leg covers alone)'
		);
	}
}
