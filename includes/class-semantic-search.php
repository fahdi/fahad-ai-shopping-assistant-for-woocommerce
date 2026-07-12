<?php
defined( 'ABSPATH' ) || exit;

/**
 * Semantic / vector product retrieval, the pluggable RETRIEVER SEAM (issue #60).
 *
 * WHAT THIS IS (and is not). This class is the boundary that lets a semantic
 * (embeddings/vector) backend plug into product discovery WITHOUT coupling it to
 * core. It does NOT itself embed text or talk to any embeddings API, real
 * semantic ranking needs an external embeddings provider (see docs/RAG-DESIGN.md),
 * which is a separate dependency and key and is NOT bundled. Until a provider
 * registers a retriever on the filter below, this seam returns nothing and product
 * search degrades to the existing WooCommerce keyword search (+ relaxation) in
 * Dukandaar_Tools::search_products, i.e. ZERO behaviour change out of the box.
 *
 * This mirrors the wallet-decoupling pattern (`dukandaar_wallet_provider`): the
 * capability stays dormant and is activated cleanly by an add-on, so "AI + vector
 * search" is a swappable bundle rather than hard core coupling.
 *
 * THE CONTRACT. A provider registers a retriever on:
 *
 *     apply_filters( 'dukandaar_semantic_retriever', null, string $query, array $filters )
 *
 * Two registration shapes are accepted (use whichever fits the provider):
 *
 *   1. Return the ranked product IDs directly. The filter is applied per query, so
 *      the provider can embed `$query`, run its vector lookup (pre-filtered by
 *      `$filters`), and return the top-k product IDs, best first:
 *
 *          add_filter( 'dukandaar_semantic_retriever', function ( $ids, $query, $filters ) {
 *              return My_Embeddings_Backend::rank( $query, $filters ); // int[] best-first
 *          }, 10, 3 );
 *
 *   2. Return a callable retriever `fn( string $query, array $filters ): int[]`
 *      (handy when the provider wants to register once, not re-resolve per call):
 *
 *          add_filter( 'dukandaar_semantic_retriever', fn() => [ $backend, 'rank' ] );
 *
 * `$filters` carries the structured constraints the tool already parsed , 
 * `category` (string), `min_price`/`max_price` (float), `limit` (int), so a
 * provider can pre-filter its scan (the design doc's live filters, §4.4) and bound
 * its result count. The retriever returns IDs ONLY; it must never return
 * price/stock.
 *
 * LIVE TRUTH IS NEVER CACHED. The returned IDs are resolved here through
 * wc_get_product() at call time and shaped by Dukandaar_Tools::format_product_summary(),
 * which reads price / sale / stock / rating straight from the live WC_Product. So
 * even though retrieval used a (potentially stale) vector index, the price and
 * stock the model and the cards see are always current, never embedded or cached
 * (design doc §5.4, an explicit invariant). Products that no longer resolve, or are
 * not visible/published, are dropped so the index can lag live truth safely.
 *
 * GRACEFUL DEGRADATION. No retriever, a retriever that returns nothing, a
 * malformed return, or a throwing retriever all yield an empty result here, and
 * Dukandaar_Tools::search_products then runs its existing keyword path. The shopper
 * never sees a semantic-search error (design doc §6.2). This is the hybrid safety
 * net: the keyword leg always backstops the vector leg.
 */
final class Dukandaar_Semantic_Search {

	/**
	 * Resolve a query through the registered semantic retriever, if any.
	 *
	 * Returns the card-shaped product summaries (the same shape search_products
	 * emits) for the retriever's ranked, still-resolvable, visible products, in
	 * rank order. Returns an EMPTY array when there is no retriever, the retriever
	 * returns nothing usable, or it throws: the caller treats an empty array as
	 * "fall back to keyword search", so this method is always safe to consult first.
	 *
	 * @param string $query   Sanitized free-text search query (non-empty).
	 * @param array  $filters Structured constraints: category (string),
	 *                        min_price/max_price (float), limit (int).
	 * @return array<int, array> Card summaries in rank order, or [] to fall back.
	 */
	public static function retrieve( string $query, array $filters ): array {
		$ids = self::ranked_ids( $query, $filters );
		if ( empty( $ids ) ) {
			return [];
		}

		$limit   = isset( $filters['limit'] ) ? max( 1, (int) $filters['limit'] ) : 0;
		$tools   = Dukandaar_Tools::instance();
		$summaries = [];

		foreach ( $ids as $id ) {
			$product = wc_get_product( $id );

			// The index can lag live truth: an id may no longer resolve, or may be
			// unpublished/hidden now. Skip those, never surface an unbuyable product.
			if ( ! $product instanceof WC_Product || ! $product->is_visible() ) {
				continue;
			}

			// format_product_summary reads price/stock LIVE from this product, the
			// retriever supplied only the id, never any cached price/stock.
			$summaries[] = $tools->format_product_summary( $product );

			if ( $limit && count( $summaries ) >= $limit ) {
				break;
			}
		}

		return $summaries;
	}

	/**
	 * Ask the registered retriever for ranked product IDs, normalised to a clean
	 * list of unique positive ints in rank order.
	 *
	 * Accepts either registration shape (direct IDs, or a callable retriever, see
	 * the class docblock) and is defensive about everything a third-party provider
	 * might return: a non-array, a callable that throws, duplicate or non-numeric
	 * entries. Anything unusable collapses to [] so the caller falls back to
	 * keyword search instead of erroring.
	 *
	 * @param string $query   Free-text query handed to the provider.
	 * @param array  $filters Structured constraints handed to the provider.
	 * @return int[] Unique positive product IDs, best first (possibly empty).
	 */
	private static function ranked_ids( string $query, array $filters ): array {
		/**
		 * Filter: register a semantic product retriever (issue #60).
		 *
		 * Default null ⇒ no semantic backend ⇒ keyword search is used. A provider
		 * returns either ranked product IDs (int[]) or a callable
		 * `fn( string $query, array $filters ): int[]`. See the class docblock for
		 * the full contract. Real ranking requires an external embeddings provider;
		 * none ships with the plugin.
		 *
		 * @param mixed  $retriever Null by default; IDs or a callable when registered.
		 * @param string $query     The sanitized free-text query.
		 * @param array  $filters   Structured constraints (category/price/limit).
		 */
		$retriever = apply_filters( 'dukandaar_semantic_retriever', null, $query, $filters );

		if ( null === $retriever || false === $retriever ) {
			return [];
		}

		// Shape 2: a callable retriever resolved per query. Isolated like the tool
		// registry isolates tool callbacks, a throwing provider degrades to keyword.
		if ( is_callable( $retriever ) ) {
			try {
				$retriever = $retriever( $query, $filters );
			} catch ( \Throwable $e ) {
				return [];
			}
		}

		if ( ! is_array( $retriever ) ) {
			return [];
		}

		$ids = [];
		foreach ( $retriever as $candidate ) {
			if ( ! is_numeric( $candidate ) ) {
				continue;
			}
			$id = (int) $candidate;
			if ( $id > 0 ) {
				$ids[ $id ] = $id; // de-dupe while preserving first-seen rank order.
			}
		}

		return array_values( $ids );
	}
}
