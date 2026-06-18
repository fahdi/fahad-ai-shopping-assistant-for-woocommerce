<?php
/**
 * Embedding provider factory (RAG Phase 1, S1.1, #104).
 *
 * Resolves the active provider: OpenAI when a key is stored, else null
 * (keyword-only). The `fahad_ai_embedding_provider` filter lets an add-on swap
 * in another backend without touching core.
 */

defined( 'ABSPATH' ) || exit;

final class Fahad_AI_Embeddings {

	/**
	 * Whether semantic search is switched on. Embeddings cost money, so a chat-only
	 * OpenAI key must not silently incur them — semantic search is opt-in (S1.5).
	 */
	public static function enabled(): bool {
		return (bool) get_option( 'fahad_ai_embeddings_enabled', 0 );
	}

	public static function provider(): ?Fahad_AI_Embedding_Provider {
		$type = (string) get_option( 'fahad_ai_embedding_provider_type', 'openai' );

		if ( 'cohere' === $type ) {
			$key     = (string) get_option( 'fahad_ai_cohere_api_key', '' );
			$default = '' !== $key
				? new Fahad_AI_Cohere_Embedding_Provider(
					$key,
					(string) get_option( 'fahad_ai_embedding_model', 'embed-multilingual-v3.0' )
				)
				: null;
		} else {
			// OpenAI-compatible (OpenAI default, or Moonshot/Together/self-hosted via base URL).
			// Prefer a dedicated embeddings key; fall back to the existing chat OpenAI key.
			$key     = (string) get_option( 'fahad_ai_embedding_api_key', '' );
			if ( '' === $key ) {
				$key = (string) get_option( 'fahad_ai_openai_api_key', '' );
			}
			$default = '' !== $key
				? new Fahad_AI_OpenAI_Embedding_Provider(
					$key,
					(string) get_option( 'fahad_ai_embedding_model', 'text-embedding-3-small' ),
					(int) get_option( 'fahad_ai_embedding_dims', 512 ),
					200, // retry backoff base (ms); transient failures retry with jitter
					(string) get_option( 'fahad_ai_embedding_base_url', 'https://api.openai.com/v1' )
				)
				: null;
		}

		/**
		 * Filter the active embedding provider.
		 *
		 * @param Fahad_AI_Embedding_Provider|null $default The settings-derived default (or null).
		 */
		return apply_filters( 'fahad_ai_embedding_provider', $default );
	}
}
