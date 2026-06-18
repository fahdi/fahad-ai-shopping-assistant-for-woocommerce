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

	public static function provider(): ?Fahad_AI_Embedding_Provider {
		$key     = (string) get_option( 'fahad_ai_openai_api_key', '' );
		$default = '' !== $key
			? new Fahad_AI_OpenAI_Embedding_Provider(
				$key,
				(string) get_option( 'fahad_ai_embedding_model', 'text-embedding-3-small' ),
				(int) get_option( 'fahad_ai_embedding_dims', 512 )
			)
			: null;

		/**
		 * Filter the active embedding provider.
		 *
		 * @param Fahad_AI_Embedding_Provider|null $default The key-derived default (or null).
		 */
		return apply_filters( 'fahad_ai_embedding_provider', $default );
	}
}
