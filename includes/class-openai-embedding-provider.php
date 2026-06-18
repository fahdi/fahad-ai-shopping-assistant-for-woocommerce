<?php
/**
 * OpenAI embeddings provider (RAG Phase 1, S1.1, #104).
 *
 * text-embedding-3-small with Matryoshka dimension shortening (512) — the
 * cheapest scan-friendly default (RAG-DESIGN.md §3). Uses the existing WP HTTP
 * layer. Failures are typed (Fahad_AI_Embedding_Exception) and tagged retryable
 * for transient errors (429/5xx/transport) so callers back off; they are never
 * surfaced to the shopper.
 */

defined( 'ABSPATH' ) || exit;

final class Fahad_AI_OpenAI_Embedding_Provider implements Fahad_AI_Embedding_Provider {

	private const ENDPOINT = 'https://api.openai.com/v1/embeddings';

	public function __construct(
		private string $key,
		private string $model = 'text-embedding-3-small',
		private int $dimensions = 512
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
			throw new Fahad_AI_Embedding_Exception( 'No embeddings API key configured.', false );
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
						'model'      => $this->model,
						'dimensions' => $this->dimensions,
						'input'      => $texts,
					]
				),
			]
		);

		if ( is_wp_error( $response ) ) {
			throw new Fahad_AI_Embedding_Exception( esc_html( 'Embeddings transport error: ' . $response->get_error_message() ), true );
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( 200 !== $code ) {
			// 429 + 5xx are transient (back off and retry); other 4xx are terminal.
			$retryable = ( 429 === $code || $code >= 500 );
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- message is escaped; 2nd arg is a bool flag, not output.
			throw new Fahad_AI_Embedding_Exception( esc_html( sprintf( 'Embeddings API returned HTTP %d.', $code ) ), $retryable );
		}

		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( empty( $data['data'] ) || ! is_array( $data['data'] ) ) {
			throw new Fahad_AI_Embedding_Exception( 'Malformed embeddings response.', false );
		}

		// OpenAI tags each row with its input index; sort to guarantee input order.
		usort( $data['data'], static fn( $a, $b ) => ( $a['index'] ?? 0 ) <=> ( $b['index'] ?? 0 ) );

		return array_map(
			static fn( $row ) => array_map( 'floatval', (array) ( $row['embedding'] ?? [] ) ),
			$data['data']
		);
	}
}
