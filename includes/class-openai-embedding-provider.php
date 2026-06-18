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

	/** Extra attempts after the first on a transient failure (429/5xx/transport). */
	private const MAX_RETRIES = 2;

	/**
	 * @param int    $retry_base_ms Base backoff in ms (exponential + jitter). 0 disables
	 *                              the sleep (used in tests so retries don't block).
	 * @param string $base_url      OpenAI-compatible API base (no trailing slash). Lets a
	 *                              merchant point embeddings at Moonshot/Together/a self-
	 *                              hosted endpoint with that endpoint's key (#111).
	 */
	public function __construct(
		private string $key,
		private string $model = 'text-embedding-3-small',
		private int $dimensions = 512,
		private int $retry_base_ms = 0,
		private string $base_url = 'https://api.openai.com/v1'
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

		// Retry transient failures (429/5xx/transport) with exponential backoff + jitter;
		// terminal errors (4xx/malformed) fail fast (RAG-DESIGN.md §5.6).
		$attempt = 0;
		while ( true ) {
			try {
				return $this->request( $texts );
			} catch ( Fahad_AI_Embedding_Exception $e ) {
				if ( ! $e->is_retryable() || $attempt >= self::MAX_RETRIES ) {
					throw $e;
				}
				$this->backoff( ++$attempt );
			}
		}
	}

	/** A single embeddings request; throws Fahad_AI_Embedding_Exception on any failure. */
	private function request( array $texts ): array {
		$response = wp_remote_post(
			rtrim( $this->base_url, '/' ) . '/embeddings',
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

	/** Exponential backoff with jitter before retry $attempt (no-op when base is 0). */
	private function backoff( int $attempt ): void {
		if ( $this->retry_base_ms <= 0 ) {
			return;
		}
		$delay  = $this->retry_base_ms * ( 2 ** ( $attempt - 1 ) );
		$jitter = function_exists( 'wp_rand' ) ? wp_rand( 0, $this->retry_base_ms ) : 0;
		usleep( ( $delay + $jitter ) * 1000 );
	}
}
