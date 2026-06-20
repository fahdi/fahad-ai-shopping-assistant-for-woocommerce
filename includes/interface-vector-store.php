<?php
/**
 * Vector store contract (RAG Phase 1, S1.2, #105).
 *
 * One swappable boundary for storing product embeddings and running similarity
 * search. The default is post-meta-backed (Fahad_AI_Postmeta_Vector_Store);
 * Phase 3 adds MariaDB-native and external (Qdrant) implementations behind the
 * same interface so retrieval logic never changes when the backend does
 * (RAG-DESIGN.md §7.1, §7.4).
 */

// @codeCoverageIgnoreStart
// Reason: file-scope guard runs once at bootstrap require time, before PHPUnit's per-test pcov window; re-requiring fatally redeclares the interface (see CoverageVectorStoreTest).
defined( 'ABSPATH' ) || exit;
// @codeCoverageIgnoreEnd

interface Fahad_AI_Vector_Store {

	/**
	 * Store (or replace) a product's embedding plus the model + content hash it
	 * was built under.
	 *
	 * @param array<int, float> $vector
	 */
	public function upsert( int $product_id, array $vector, string $model, string $content_hash ): void;

	/** Remove a product's embedding. */
	public function delete( int $product_id ): void;

	/** The stored content hash for a product, or '' if none (indexer skip check). */
	public function content_hash( int $product_id ): string;

	/**
	 * Rank candidate products by similarity to the query vector.
	 *
	 * @param array<int, float> $query_vector
	 * @param array<int, int>   $candidate_ids Pre-filtered ids to scan (category/stock/
	 *                                         price filtering happens upstream in the retriever).
	 * @return array<int, int> Up to $k product ids, most similar first.
	 */
	public function query( array $query_vector, int $k, array $candidate_ids ): array;

	/** Whether the backend is usable right now. */
	public function is_available(): bool;

	/** Whether the stored index needs (re)building for the active model/dims. */
	public function rebuild_required(): bool;
}
