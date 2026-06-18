<?php
/**
 * Default vector store: embeddings as product post meta (RAG Phase 1, S1.2, #105).
 *
 * RAG-DESIGN.md §5.1 sketched a custom `{prefix}fahad_ai_embeddings` table. This
 * implementation deliberately uses POST META instead, because the plugin keeps a
 * zero-custom-table convention (even analytics stores to an option) and has no
 * activation/migration machinery. Post meta is still MySQL-backed, needs no
 * schema or uninstall drop, and is auto-deleted with the product — so the
 * "remove embedding on product delete" requirement (§5.3) comes for free.
 *
 * The brute-force cosine scan (§2.1) runs over a caller-supplied candidate set
 * (the retriever pre-filters by category/stock/price upstream, §4.4). Vectors
 * built under a different model are skipped so models are never mixed (§5.5).
 */

defined( 'ABSPATH' ) || exit;

final class Fahad_AI_Postmeta_Vector_Store implements Fahad_AI_Vector_Store {

	private const META_VECTOR = '_fahad_ai_embedding';
	private const META_MODEL  = '_fahad_ai_embedding_model';
	private const META_DIM    = '_fahad_ai_embedding_dim';
	private const META_HASH   = '_fahad_ai_embedding_hash';

	/** Option recording the model the index was last built under (§5.5). */
	public const OPTION_INDEX_MODEL = 'fahad_ai_index_model';

	public function __construct(
		private string $model,
		private int $dimensions
	) {}

	public function upsert( int $product_id, array $vector, string $model, string $content_hash ): void {
		update_post_meta( $product_id, self::META_VECTOR, Fahad_AI_Vector_Math::pack_vector( $vector ) );
		update_post_meta( $product_id, self::META_MODEL, $model );
		update_post_meta( $product_id, self::META_DIM, count( $vector ) );
		update_post_meta( $product_id, self::META_HASH, $content_hash );
	}

	public function delete( int $product_id ): void {
		delete_post_meta( $product_id, self::META_VECTOR );
		delete_post_meta( $product_id, self::META_MODEL );
		delete_post_meta( $product_id, self::META_DIM );
		delete_post_meta( $product_id, self::META_HASH );
	}

	public function content_hash( int $product_id ): string {
		return (string) get_post_meta( $product_id, self::META_HASH, true );
	}

	public function query( array $query_vector, int $k, array $candidate_ids ): array {
		$dim    = count( $query_vector );
		$scored = [];

		foreach ( $candidate_ids as $id ) {
			$id = (int) $id;
			// Never compare across models — a vector from another model is meaningless here.
			if ( (string) get_post_meta( $id, self::META_MODEL, true ) !== $this->model ) {
				continue;
			}
			$blob = get_post_meta( $id, self::META_VECTOR, true );
			if ( ! is_string( $blob ) || '' === $blob ) {
				continue;
			}
			$vector = Fahad_AI_Vector_Math::unpack_vector( $blob );
			if ( count( $vector ) !== $dim ) {
				continue;
			}
			$scored[ $id ] = Fahad_AI_Vector_Math::cosine( $query_vector, $vector );
		}

		// Highest cosine first; deterministic tie-break by id ascending.
		uksort(
			$scored,
			static function ( $a, $b ) use ( $scored ) {
				if ( abs( $scored[ $a ] - $scored[ $b ] ) < 1e-12 ) {
					return $a <=> $b;
				}
				return $scored[ $b ] <=> $scored[ $a ];
			}
		);

		return array_slice( array_keys( $scored ), 0, max( 0, $k ) );
	}

	public function is_available(): bool {
		// Post meta is always available (core WP); no external dependency.
		return true;
	}

	public function rebuild_required(): bool {
		return (string) get_option( self::OPTION_INDEX_MODEL, '' ) !== $this->model;
	}
}
