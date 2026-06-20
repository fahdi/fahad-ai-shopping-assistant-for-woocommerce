<?php
/**
 * Embedding provider contract (RAG Phase 1, S1.1, #104).
 *
 * Decouples the vector pipeline from any one vendor. The default implementation
 * is OpenAI (Fahad_AI_OpenAI_Embedding_Provider); an add-on can swap in
 * Cohere/Voyage/self-hosted via the `fahad_ai_embedding_provider` filter without
 * touching core. Embeddings are OPTIONAL — until a provider is available the
 * assistant runs keyword-only, exactly as today (RAG-DESIGN.md §3, §4.3).
 */

// @codeCoverageIgnoreStart
// Reason: file-scope guard runs once at bootstrap require time, before PHPUnit's per-test pcov window; re-requiring fatally redeclares the interface (see CoverageEmbeddingProviderTest).
defined( 'ABSPATH' ) || exit;
// @codeCoverageIgnoreEnd

interface Fahad_AI_Embedding_Provider {

	/**
	 * Embed a batch of texts.
	 *
	 * @param string[] $texts
	 * @return array<int, array<int, float>> One vector per input, in input order.
	 * @throws Fahad_AI_Embedding_Exception On any failure.
	 */
	public function embed( array $texts ): array;

	/** The embedding model id (stored beside every vector; §5.5). */
	public function model(): string;

	/** The vector dimensionality the provider is configured to return. */
	public function dimensions(): int;

	/** Whether the provider is configured and usable right now. */
	public function is_available(): bool;
}
