<?php
/**
 * The three retrieval legs for the RAG spike comparison (RAG Phase 0, S0.4).
 *
 * Deliberately small and pure so the same code drives the offline golden-set
 * eval (canned embeddings) and the spike CLI (real embeddings):
 *  - keyword_rank: token-overlap baseline — a stand-in for the existing keyword
 *    search leg, good at literal tokens (SKUs, exact words).
 *  - vector_rank:  cosine over embeddings (Fahad_AI_Vector_Math), candidates above
 *    a similarity threshold — good at intent/synonyms.
 *  - hybrid_rank:  RRF fusion of the two legs (Fahad_AI_Rrf).
 *
 * Each leg returns only its CANDIDATES (no overlap / cos ≤ threshold are dropped),
 * matching how the real legs behave (keyword returns matches; vector returns its
 * top-N). This is what lets hybrid recover items either leg alone misses.
 */

defined( 'ABSPATH' ) || exit;

final class Fahad_AI_Rag_Spike_Retriever {

	/**
	 * Rank docs by query-token overlap, best first, excluding zero-overlap docs.
	 *
	 * @param array<int,string> $docs id => searchable text.
	 * @return array<int,int> ids, highest overlap first (ties by id ascending).
	 */
	public static function keyword_rank( string $query, array $docs ): array {
		$query_tokens = array_unique( self::tokenize( $query ) );
		if ( ! $query_tokens ) {
			return [];
		}

		$scored = [];
		foreach ( $docs as $id => $text ) {
			$doc_tokens = array_fill_keys( self::tokenize( (string) $text ), true );
			$overlap    = 0;
			foreach ( $query_tokens as $t ) {
				if ( isset( $doc_tokens[ $t ] ) ) {
					++$overlap;
				}
			}
			if ( $overlap > 0 ) {
				$scored[ (int) $id ] = (float) $overlap;
			}
		}

		return self::rank_by_score_desc( $scored );
	}

	/**
	 * Rank docs by cosine similarity to the query vector, excluding those at or
	 * below the threshold (non-candidates).
	 *
	 * @param array<int,float>       $query_vec
	 * @param array<int,array<int,float>> $doc_vecs id => vector.
	 * @return array<int,int> ids, highest cosine first (ties by id ascending).
	 */
	public static function vector_rank( array $query_vec, array $doc_vecs, float $threshold = 0.0 ): array {
		$scored = [];
		foreach ( $doc_vecs as $id => $vec ) {
			$cos = Fahad_AI_Vector_Math::cosine( $query_vec, $vec );
			if ( $cos > $threshold ) {
				$scored[ (int) $id ] = $cos;
			}
		}

		return self::rank_by_score_desc( $scored );
	}

	/**
	 * Fuse the keyword and vector legs with Reciprocal Rank Fusion.
	 *
	 * @param array<int,int> $keyword
	 * @param array<int,int> $vector
	 * @return array<int,int> fused ids, best first.
	 */
	public static function hybrid_rank( array $keyword, array $vector, int $k = Fahad_AI_Rrf::DEFAULT_K ): array {
		return Fahad_AI_Rrf::fuse( [ $keyword, $vector ], $k );
	}

	/** Lowercase, split on non-word characters (Unicode-aware), drop empties. */
	private static function tokenize( string $text ): array {
		$parts = preg_split( '/\W+/u', mb_strtolower( $text ), -1, PREG_SPLIT_NO_EMPTY );
		return is_array( $parts ) ? $parts : [];
	}

	/**
	 * @param array<int,float> $scored id => score
	 * @return array<int,int> ids ordered by score desc, ties by id asc (deterministic).
	 */
	private static function rank_by_score_desc( array $scored ): array {
		$ids = array_keys( $scored );
		usort(
			$ids,
			static function ( int $a, int $b ) use ( $scored ): int {
				if ( abs( $scored[ $a ] - $scored[ $b ] ) < 1e-12 ) {
					return $a <=> $b;
				}
				return $scored[ $b ] <=> $scored[ $a ];
			}
		);
		return $ids;
	}
}
