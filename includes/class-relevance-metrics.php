<?php
/**
 * Retrieval-quality metrics for the RAG eval gate (RAG Phase 0, S0.4).
 *
 * Binary-relevance recall@k / precision@k / nDCG@k over a retrieved id-list and
 * a relevant-id set. Used by the golden-set comparison (keyword vs vector vs
 * hybrid, RAG-DESIGN.md §6.1) and by the spike CLI report. Pure — no WordPress.
 */

defined( 'ABSPATH' ) || exit;

final class Fahad_AI_Relevance_Metrics {

	/**
	 * Fraction of relevant items appearing in the top-k retrieved.
	 * Empty relevant set is vacuously perfect (1.0).
	 *
	 * @param array<int,int> $retrieved Ranked ids, best first.
	 * @param array<int,int> $relevant  Relevant ids (set).
	 */
	public static function recall_at_k( array $retrieved, array $relevant, int $k ): float {
		if ( ! $relevant ) {
			return 1.0;
		}
		$hits = self::hits_in_top_k( $retrieved, $relevant, $k );
		return $hits / count( array_unique( $relevant ) );
	}

	/**
	 * Fraction of the top-k slots that are relevant (divided by k, the standard form).
	 */
	public static function precision_at_k( array $retrieved, array $relevant, int $k ): float {
		if ( $k <= 0 ) {
			return 0.0;
		}
		return self::hits_in_top_k( $retrieved, $relevant, $k ) / $k;
	}

	/**
	 * Normalised discounted cumulative gain at k (binary gain).
	 * DCG = Σ rel_i / log2(i+1); IDCG = the same with all relevant items ranked first.
	 */
	public static function ndcg_at_k( array $retrieved, array $relevant, int $k ): float {
		$relevant_set = array_fill_keys( array_map( 'intval', $relevant ), true );
		$top          = array_slice( array_values( $retrieved ), 0, $k );

		$dcg = 0.0;
		foreach ( $top as $i => $id ) {
			if ( isset( $relevant_set[ (int) $id ] ) ) {
				$dcg += 1.0 / log( $i + 2, 2 ); // position is $i+1, so log2((i+1)+1)
			}
		}

		// Ideal: min(#relevant, k) relevant items in the top positions.
		$ideal_hits = min( count( $relevant_set ), $k );
		$idcg       = 0.0;
		for ( $i = 0; $i < $ideal_hits; $i++ ) {
			$idcg += 1.0 / log( $i + 2, 2 );
		}

		return $idcg > 0.0 ? $dcg / $idcg : 0.0;
	}

	/** Count distinct relevant ids present in the top-k of the retrieved list. */
	private static function hits_in_top_k( array $retrieved, array $relevant, int $k ): int {
		$relevant_set = array_fill_keys( array_map( 'intval', $relevant ), true );
		$top          = array_slice( array_values( $retrieved ), 0, max( 0, $k ) );
		$seen         = [];
		foreach ( $top as $id ) {
			$id = (int) $id;
			if ( isset( $relevant_set[ $id ] ) ) {
				$seen[ $id ] = true;
			}
		}
		return count( $seen );
	}
}
