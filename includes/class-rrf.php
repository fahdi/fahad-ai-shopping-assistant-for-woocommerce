<?php
/**
 * Reciprocal Rank Fusion for hybrid retrieval (RAG Phase 0, S0.2).
 *
 * Fuses N ranked id-lists (e.g. the keyword leg and the vector leg of hybrid
 * search) into a single ranking by score(d) = Σ 1/(k + rank_i(d)). Because it
 * works on RANKS, not raw scores, it sidesteps the "cosine and BM25 scores are
 * not comparable" problem with no normalisation (RAG-DESIGN.md §4.3).
 *
 * Pure: no WordPress / WooCommerce dependencies.
 */

defined( 'ABSPATH' ) || exit;

final class Fahad_AI_Rrf {

	/** Standard RRF constant; dampens the weight of any single high rank. */
	public const DEFAULT_K = 60;

	/**
	 * Fuse ranked id-lists into one ranking, best first.
	 *
	 * @param array<int, array<int, int>> $ranked_lists Each inner array is ids in rank
	 *                                                   order (position 0 = rank 1).
	 * @param int                         $k            RRF constant (default 60).
	 * @return array<int, int> Fused ids, highest score first; ties broken by id ascending
	 *                         so the result is deterministic and reproducible.
	 */
	public static function fuse( array $ranked_lists, int $k = self::DEFAULT_K ): array {
		$scores = [];
		foreach ( $ranked_lists as $list ) {
			$rank = 1;
			foreach ( $list as $id ) {
				$id            = (int) $id;
				$scores[ $id ] = ( $scores[ $id ] ?? 0.0 ) + 1.0 / ( $k + $rank );
				++$rank;
			}
		}

		$ids = array_keys( $scores );
		usort(
			$ids,
			static function ( int $a, int $b ) use ( $scores ): int {
				// Deterministic tie-break by id when scores are effectively equal.
				if ( abs( $scores[ $a ] - $scores[ $b ] ) < 1e-12 ) {
					return $a <=> $b;
				}
				return $scores[ $b ] <=> $scores[ $a ];
			}
		);

		return $ids;
	}
}
