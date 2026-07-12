<?php
/**
 * Hybrid retriever (RAG Phase 1, S1.4, #107).
 *
 * Runs both retrieval legs and fuses them with Reciprocal Rank Fusion:
 *  - keyword leg: WooCommerce search (literal tokens, SKUs, exact words),
 *  - vector leg:  embed the query, scan the vector store over the filtered
 *    candidate set (intent / synonyms),
 * then RRF-fuses the two ranked lists (RAG-DESIGN.md §4.3). Pure vector misses
 * exact tokens and pure keyword misses intent; hybrid recovers both.
 *
 * It plugs into the EXISTING `fahad_ai_semantic_retriever` seam (#60), returning
 * ranked product IDs only, Fahad_AI_Semantic_Search resolves them to LIVE
 * price/stock summaries (§5.4). So search_products becomes hybrid with no new
 * tool and no api-handler change. With no provider or an empty vector index the
 * retriever returns [], and search_products runs its full keyword path
 * (graceful degradation, §6.2).
 */

defined( 'ABSPATH' ) || exit;

final class Fahad_AI_Retriever {

	public function __construct(
		private Fahad_AI_Embedding_Provider $provider,
		private Fahad_AI_Vector_Store $store
	) {}

	/** Register the hybrid retriever on the semantic-search seam. */
	public static function register(): void {
		add_filter( 'fahad_ai_semantic_retriever', [ self::class, 'resolve_seam' ], 10, 3 );
	}

	/**
	 * Seam callback. Returns hybrid-ranked product IDs when an embedding provider
	 * is configured, else the incoming value unchanged (so keyword search runs).
	 *
	 * @param mixed  $ids     Incoming value from the filter (null by default).
	 * @param string $query   Sanitized free-text query.
	 * @param array  $filters Structured constraints (category/price/limit).
	 * @return mixed Ranked product IDs, or $ids to fall back to keyword search.
	 */
	public static function resolve_seam( $ids, string $query, array $filters ) {
		if ( ! Fahad_AI_Embeddings::enabled() ) {
			return $ids; // semantic search is opt-in, keyword search runs until switched on
		}
		$provider = Fahad_AI_Embeddings::provider();
		if ( ! $provider || ! $provider->is_available() ) {
			return $ids;
		}

		$store = Fahad_AI_Vector_Stores::resolve( $provider->model(), $provider->dimensions() );
		try {
			$limit  = isset( $filters['limit'] ) ? max( 1, (int) $filters['limit'] ) : 10;
			$hybrid = ( new self( $provider, $store ) )->search( $query, $filters, $limit );
			return empty( $hybrid ) ? $ids : $hybrid; // empty hybrid -> fall back to keyword
		} catch ( \Throwable $e ) {
			return $ids; // never surface a retrieval error; degrade to keyword
		}
	}

	/**
	 * Hybrid-ranked product IDs for a query, best first.
	 *
	 * @return array<int, int> Up to $k product IDs, or [] to fall back to keyword.
	 */
	public function search( string $query, array $filters = [], int $k = 10 ): array {
		$k = max( 1, $k );

		$query_vector = $this->embed_query( $query );
		if ( ! $query_vector ) {
			return [];
		}

		$candidates = $this->candidate_ids( $filters );
		$vector_ids = $candidates ? $this->store->query( $query_vector, $k * 3, $candidates ) : [];
		if ( ! $vector_ids ) {
			return []; // nothing embedded matched -> let keyword search take over
		}

		$keyword_ids = $this->keyword_ids( $query, $filters, $k * 3 );
		$fused       = Fahad_AI_Rrf::fuse( [ $keyword_ids, $vector_ids ] );

		/**
		 * Optional cross-encoder rerank seam (#113). Off by default, a Cohere/Voyage
		 * reranker can register here to reorder the fused candidates for extra
		 * precision; with nothing registered the fused order passes through unchanged.
		 *
		 * @param array<int,int> $fused   Fused candidate ids, best first.
		 * @param string         $query   The shopper's query.
		 * @param array          $filters Structured constraints.
		 */
		$fused = (array) apply_filters( 'fahad_ai_rerank', $fused, $query, $filters );

		return array_slice( $fused, 0, $k );
	}

	/**
	 * Embed the query, caching the vector briefly so repeated identical shopper
	 * phrases are not re-embedded (cost control, #109). Cache key is namespaced by
	 * model + dimensions so a model change never returns a stale-shape vector.
	 *
	 * @return array<int, float>
	 */
	private function embed_query( string $query ): array {
		$key    = 'fahad_ai_qe_' . md5( $query . '|' . $this->provider->model() . '|' . $this->provider->dimensions() );
		$cached = get_transient( $key );
		if ( is_array( $cached ) && $cached ) {
			return $cached;
		}

		$vectors = $this->provider->embed( [ $query ] );
		$vector  = $vectors[0] ?? [];
		if ( $vector ) {
			set_transient( $key, $vector, 300 ); // 5 min, long enough to coalesce a burst of identical queries
		}
		return $vector;
	}

	/** Product IDs matching the live filters (category/price/stock), the vector scan set. */
	private function candidate_ids( array $filters ): array {
		return $this->product_ids( $this->filter_args( $filters, [ 'limit' => -1 ] ) );
	}

	/** Keyword-leg product IDs (WooCommerce relevance search) under the same filters. */
	private function keyword_ids( string $query, array $filters, int $limit ): array {
		return $this->product_ids( $this->filter_args( $filters, [ 'limit' => $limit, 's' => $query, 'orderby' => 'relevance' ] ) );
	}

	/** Merge the structured filters into a wc_get_products args array. */
	private function filter_args( array $filters, array $extra ): array {
		$args = array_merge( [ 'status' => 'publish', 'return' => 'ids' ], $extra );
		if ( ! empty( $filters['category'] ) ) {
			$args['category'] = [ (string) $filters['category'] ];
		}
		if ( isset( $filters['min_price'] ) ) {
			$args['min_price'] = (float) $filters['min_price'];
		}
		if ( isset( $filters['max_price'] ) ) {
			$args['max_price'] = (float) $filters['max_price'];
		}
		return $args;
	}

	/** @return array<int,int> */
	private function product_ids( array $args ): array {
		return array_map( 'intval', (array) wc_get_products( $args ) );
	}
}
