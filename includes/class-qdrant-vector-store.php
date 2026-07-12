<?php
/**
 * Qdrant external vector store, opt-in scale tier (RAG Phase 3, S3.2, #113).
 *
 * For very large catalogs that outgrow the MySQL/MariaDB backends, point the
 * index at a managed/self-hosted Qdrant. Registered via the dukandaar_vector_store
 * filter only when configured (URL + key); otherwise the default backend is used,
 * so a typical install is unaffected. A transport failure throws, the retriever
 * catches it and falls back to keyword search (RAG-DESIGN.md §2.4, §6.2).
 *
 * INTEGRATION NOTE: the HTTP request/response shapes are unit-tested; an
 * end-to-end check needs a live Qdrant instance (not available in this env).
 */

defined( 'ABSPATH' ) || exit;

final class Dukandaar_Qdrant_Vector_Store implements Dukandaar_Vector_Store {

	public function __construct(
		private string $url,
		private string $key,
		private string $collection,
		private string $model
	) {}

	public function is_available(): bool {
		return '' !== $this->url && '' !== $this->collection;
	}

	public function upsert( int $product_id, array $vector, string $model, string $content_hash ): void {
		$this->send(
			'PUT',
			"/collections/{$this->collection}/points",
			[
				'points' => [
					[
						'id'      => $product_id,
						'vector'  => array_map( static fn( $v ) => (float) $v, $vector ),
						'payload' => [ 'model' => $model, 'content_hash' => $content_hash ],
					],
				],
			]
		);
	}

	public function delete( int $product_id ): void {
		$this->send( 'POST', "/collections/{$this->collection}/points/delete", [ 'points' => [ $product_id ] ] );
	}

	public function content_hash( int $product_id ): string {
		$res = $this->send( 'POST', "/collections/{$this->collection}/points", [ 'ids' => [ $product_id ], 'with_payload' => true ] );
		return (string) ( $res['result'][0]['payload']['content_hash'] ?? '' );
	}

	public function query( array $query_vector, int $k, array $candidate_ids ): array {
		if ( ! $candidate_ids ) {
			return [];
		}
		$res = $this->send(
			'POST',
			"/collections/{$this->collection}/points/search",
			[
				'vector'       => array_map( static fn( $v ) => (float) $v, $query_vector ),
				'limit'        => max( 1, $k ),
				'with_payload' => false,
				'filter'       => [
					'must' => [
						[ 'key' => 'model', 'match' => [ 'value' => $this->model ] ],
						[ 'has_id' => array_map( 'intval', $candidate_ids ) ],
					],
				],
			]
		);

		$ids = [];
		foreach ( (array) ( $res['result'] ?? [] ) as $point ) {
			if ( isset( $point['id'] ) ) {
				$ids[] = (int) $point['id'];
			}
		}
		return $ids;
	}

	public function rebuild_required(): bool {
		return (string) get_option( Dukandaar_Postmeta_Vector_Store::OPTION_INDEX_MODEL, '' ) !== $this->model;
	}

	/** Issue a Qdrant request; returns the decoded body or throws (transport/HTTP error). */
	private function send( string $method, string $path, array $body ): array {
		$args = [
			'timeout' => 30,
			'method'  => $method,
			'headers' => [ 'Content-Type' => 'application/json', 'api-key' => $this->key ],
			'body'    => wp_json_encode( $body ),
		];
		$endpoint = rtrim( $this->url, '/' ) . $path;

		// POST goes through wp_remote_post; other verbs (PUT) through wp_remote_request.
		$response = 'POST' === $method ? wp_remote_post( $endpoint, $args ) : wp_remote_request( $endpoint, $args );

		if ( is_wp_error( $response ) ) {
			throw new Dukandaar_Embedding_Exception( esc_html( 'Qdrant transport error: ' . $response->get_error_message() ), true );
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( $code < 200 || $code >= 300 ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- message escaped; 2nd arg is a bool flag.
			throw new Dukandaar_Embedding_Exception( esc_html( sprintf( 'Qdrant returned HTTP %d.', $code ) ), 429 === $code || $code >= 500 );
		}
		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		return is_array( $data ) ? $data : [];
	}

	/**
	 * Register Qdrant as the vector backend when configured (opt-in). Reads the
	 * URL/key/collection options; a blank URL leaves the default backend in place.
	 */
	public static function register(): void {
		$url = (string) get_option( 'dukandaar_qdrant_url', '' );
		if ( '' === $url ) {
			return;
		}
		add_filter(
			'dukandaar_vector_store',
			static function ( $fallback, $model ) use ( $url ) {
				$store = new self(
					$url,
					(string) get_option( 'dukandaar_qdrant_key', '' ),
					(string) get_option( 'dukandaar_qdrant_collection', 'dukandaar_products' ),
					$model
				);
				return $store->is_available() ? $store : $fallback;
			},
			10,
			2
		);
	}
}
