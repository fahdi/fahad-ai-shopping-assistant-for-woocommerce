<?php
/**
 * RAG spike engine (RAG Phase 0, S0.5).
 *
 * Two reusable measurements behind the `wp fahad-ai rag-spike` command:
 *  - compare():      keyword/vector/hybrid recall@k over a catalog + golden queries
 *                    (the relevance gate, reusing the tested primitives).
 *  - scan_latency():  a synthetic brute-force cosine scan over packed float32 BLOBs
 *                    — the exact per-row work the MySQL default backend does — to
 *                    project latency at catalog sizes the demo store can't reach.
 *
 * Spike-only: no shipped tool or UI (RAG-DESIGN.md §7.5 Phase 0 is a gated decision).
 */

defined( 'ABSPATH' ) || exit;

final class Fahad_AI_Rag_Spike {

	/**
	 * Score keyword / vector / hybrid recall@k over a catalog and golden queries.
	 *
	 * @param array<int,string>            $texts   id => searchable text.
	 * @param array<int,array<int,float>>  $vecs    id => embedding.
	 * @param array<int,array{name?:string,query:string,vec:array<int,float>,relevant:int[]}> $queries
	 * @return array{queries: array<int,array{name:string,keyword:float,vector:float,hybrid:float}>, mean: array{keyword:float,vector:float,hybrid:float}, k:int}
	 */
	public static function compare( array $texts, array $vecs, array $queries, int $k = 3 ): array {
		$rows = [];
		$sum  = [ 'keyword' => 0.0, 'vector' => 0.0, 'hybrid' => 0.0 ];

		foreach ( $queries as $q ) {
			$keyword = Fahad_AI_Rag_Spike_Retriever::keyword_rank( $q['query'], $texts );
			$vector  = Fahad_AI_Rag_Spike_Retriever::vector_rank( $q['vec'], $vecs );
			$hybrid  = Fahad_AI_Rag_Spike_Retriever::hybrid_rank( $keyword, $vector );

			$row = [
				'name'    => (string) ( $q['name'] ?? $q['query'] ),
				'keyword' => Fahad_AI_Relevance_Metrics::recall_at_k( $keyword, $q['relevant'], $k ),
				'vector'  => Fahad_AI_Relevance_Metrics::recall_at_k( $vector, $q['relevant'], $k ),
				'hybrid'  => Fahad_AI_Relevance_Metrics::recall_at_k( $hybrid, $q['relevant'], $k ),
			];
			foreach ( [ 'keyword', 'vector', 'hybrid' ] as $mode ) {
				$sum[ $mode ] += $row[ $mode ];
			}
			$rows[] = $row;
		}

		$n = max( 1, count( $queries ) );
		return [
			'queries' => $rows,
			'mean'    => array_map( static fn( $s ) => $s / $n, $sum ),
			'k'       => $k,
		];
	}

	/**
	 * Project brute-force scan latency at a given catalog size.
	 *
	 * Builds $size deterministic float32 BLOBs (the real storage form, ~2 KB each
	 * at 512 dims) and times unpack + cosine across the whole set — the exact work
	 * a query does in the MySQL default backend — for $trials query vectors.
	 *
	 * @return array{size:int,dim:int,trials:int,p50_ms:float,p95_ms:float}
	 */
	public static function scan_latency( int $size, int $dim, int $trials ): array {
		$blobs = [];
		for ( $i = 0; $i < $size; $i++ ) {
			$v = [];
			for ( $j = 0; $j < $dim; $j++ ) {
				$v[ $j ] = sin( $i * 0.7 + $j * 0.013 );
			}
			$blobs[] = Fahad_AI_Vector_Math::pack_vector( $v );
		}

		$trials  = max( 1, $trials );
		$timings = [];
		for ( $t = 0; $t < $trials; $t++ ) {
			$query = [];
			for ( $j = 0; $j < $dim; $j++ ) {
				$query[ $j ] = cos( $t * 0.5 + $j * 0.011 );
			}
			$start = microtime( true );
			foreach ( $blobs as $blob ) {
				Fahad_AI_Vector_Math::cosine( $query, Fahad_AI_Vector_Math::unpack_vector( $blob ) );
			}
			$timings[] = ( microtime( true ) - $start ) * 1000.0;
		}

		sort( $timings );
		return [
			'size'   => $size,
			'dim'    => $dim,
			'trials' => $trials,
			'p50_ms' => self::percentile( $timings, 50 ),
			'p95_ms' => self::percentile( $timings, 95 ),
		];
	}

	/** Nearest-rank percentile of an ascending-sorted list. */
	private static function percentile( array $sorted, int $p ): float {
		if ( ! $sorted ) {
			return 0.0;
		}
		$idx = (int) ceil( $p / 100 * count( $sorted ) ) - 1;
		$idx = max( 0, min( count( $sorted ) - 1, $idx ) );
		return (float) $sorted[ $idx ];
	}
}
