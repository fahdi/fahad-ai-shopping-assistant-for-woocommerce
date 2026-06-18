<?php
/**
 * Cohere embeddings provider (RAG Phase 2, S2.3, #111).
 *
 * embed-multilingual-v3.0 — materially stronger on non-Latin scripts (Urdu,
 * Arabic, Hindi) than the OpenAI default, which matters for this store's
 * Urdu/English audience (RAG-DESIGN.md §3). Implements the same provider
 * interface, so the indexer/retriever are unchanged.
 *
 * NOTE: the interface embeds query and document text the same way, so we use
 * Cohere's `search_document` input type for both — slightly suboptimal vs.
 * `search_query` for queries, but acceptable for the MVP and avoids widening
 * the interface.
 */

defined( 'ABSPATH' ) || exit;

final class Fahad_AI_Cohere_Embedding_Provider implements Fahad_AI_Embedding_Provider {

	private const ENDPOINT = 'https://api.cohere.com/v2/embed';

	public function __construct(
		private string $key,
		private string $model = 'embed-multilingual-v3.0',
		private int $dimensions = 1024
	) {}

	public function model(): string {
		return $this->model;
	}

	public function dimensions(): int {
		return $this->dimensions;
	}

	public function is_available(): bool {
		return '' !== $this->key;
	}

	public function embed( array $texts ): array {
		$texts = array_values( $texts );
		if ( ! $texts ) {
			return [];
		}
		if ( ! $this->is_available() ) {
			throw new Fahad_AI_Embedding_Exception( 'No Cohere API key configured.', false );
		}

		$response = wp_remote_post(
			self::ENDPOINT,
			[
				'timeout' => 60,
				'headers' => [
					'Authorization' => 'Bearer ' . $this->key,
					'Content-Type'  => 'application/json',
				],
				'body'    => wp_json_encode(
					[
						'model'           => $this->model,
						'texts'           => $texts,
						'input_type'      => 'search_document',
						'embedding_types' => [ 'float' ],
					]
				),
			]
		);

		if ( is_wp_error( $response ) ) {
			throw new Fahad_AI_Embedding_Exception( esc_html( 'Cohere transport error: ' . $response->get_error_message() ), true );
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( 200 !== $code ) {
			$retryable = ( 429 === $code || $code >= 500 );
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- message is escaped; 2nd arg is a bool flag, not output.
			throw new Fahad_AI_Embedding_Exception( esc_html( sprintf( 'Cohere API returned HTTP %d.', $code ) ), $retryable );
		}

		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( empty( $data['embeddings']['float'] ) || ! is_array( $data['embeddings']['float'] ) ) {
			throw new Fahad_AI_Embedding_Exception( 'Malformed Cohere response.', false );
		}

		return array_map(
			static fn( $vector ) => array_map( 'floatval', (array) $vector ),
			$data['embeddings']['float']
		);
	}
}
