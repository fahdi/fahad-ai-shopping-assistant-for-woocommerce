<?php
/**
 * Unit tests for the embedding provider abstraction (RAG Phase 1, S1.1, #104).
 *
 * Fahad_AI_Embedding_Provider (interface) + Fahad_AI_OpenAI_Embedding_Provider
 * (text-embedding-3-small @ 512 dims) + Fahad_AI_Embeddings (factory + filter).
 * Off / keyword-only until a key is configured; failures are typed and tagged
 * retryable so the indexer can back off and the retriever can degrade — they
 * never reach the shopper.
 */

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;

class EmbeddingsTest extends TestCase {

	use MockeryPHPUnitIntegration;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( 'wp_json_encode' )->alias( static fn( $d ) => json_encode( $d ) );
		Functions\when( 'esc_html' )->alias( static fn( $s ) => $s );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	private function provider( string $key = 'sk-test', string $model = 'text-embedding-3-small', int $dims = 512 ): Fahad_AI_OpenAI_Embedding_Provider {
		return new Fahad_AI_OpenAI_Embedding_Provider( $key, $model, $dims );
	}

	public function test_embed_builds_openai_request_with_model_dims_and_input(): void {
		$captured = null;
		Functions\when( 'wp_remote_post' )->alias(
			static function ( $url, $args ) use ( &$captured ) {
				$captured = [ 'url' => $url, 'body' => json_decode( $args['body'], true ), 'headers' => $args['headers'] ];
				return [ 'ok' => true ];
			}
		);
		Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 200 );
		Functions\when( 'wp_remote_retrieve_body' )->justReturn(
			json_encode( [ 'data' => [ [ 'index' => 0, 'embedding' => [ 0.1, 0.2 ] ], [ 'index' => 1, 'embedding' => [ 0.3, 0.4 ] ] ] ] )
		);

		$this->provider()->embed( [ 'hello', 'world' ] );

		$this->assertSame( 'https://api.openai.com/v1/embeddings', $captured['url'] );
		$this->assertSame( 'text-embedding-3-small', $captured['body']['model'] );
		$this->assertSame( 512, $captured['body']['dimensions'] );
		$this->assertSame( [ 'hello', 'world' ], $captured['body']['input'] );
		$this->assertSame( 'Bearer sk-test', $captured['headers']['Authorization'] );
	}

	public function test_embed_returns_vectors_in_input_order(): void {
		Functions\when( 'wp_remote_post' )->justReturn( [ 'ok' => true ] );
		Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 200 );
		// Deliberately out of order — must be re-sorted by index.
		Functions\when( 'wp_remote_retrieve_body' )->justReturn(
			json_encode( [ 'data' => [ [ 'index' => 1, 'embedding' => [ 9.0 ] ], [ 'index' => 0, 'embedding' => [ 1.0 ] ] ] ] )
		);

		$out = $this->provider()->embed( [ 'a', 'b' ] );
		$this->assertSame( [ [ 1.0 ], [ 9.0 ] ], $out );
	}

	public function test_embed_returns_empty_without_calling_http_for_empty_input(): void {
		$called = false;
		Functions\when( 'wp_remote_post' )->alias( static function () use ( &$called ) { $called = true; return []; } );

		$this->assertSame( [], $this->provider()->embed( [] ) );
		$this->assertFalse( $called, 'empty input must not hit the API' );
	}

	public function test_embed_throws_non_retryable_without_key(): void {
		$this->expectException( Fahad_AI_Embedding_Exception::class );
		try {
			$this->provider( '' )->embed( [ 'x' ] );
		} catch ( Fahad_AI_Embedding_Exception $e ) {
			$this->assertFalse( $e->is_retryable(), 'a missing key is a config error, not retryable' );
			throw $e;
		}
	}

	public function test_embed_throws_retryable_on_rate_limit(): void {
		Functions\when( 'wp_remote_post' )->justReturn( [ 'ok' => true ] );
		Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 429 );
		Functions\when( 'wp_remote_retrieve_body' )->justReturn( '{}' );

		try {
			$this->provider()->embed( [ 'x' ] );
			$this->fail( 'expected exception' );
		} catch ( Fahad_AI_Embedding_Exception $e ) {
			$this->assertTrue( $e->is_retryable() );
		}
	}

	public function test_embed_throws_retryable_on_server_error(): void {
		Functions\when( 'wp_remote_post' )->justReturn( [ 'ok' => true ] );
		Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 503 );
		Functions\when( 'wp_remote_retrieve_body' )->justReturn( '{}' );

		try {
			$this->provider()->embed( [ 'x' ] );
			$this->fail( 'expected exception' );
		} catch ( Fahad_AI_Embedding_Exception $e ) {
			$this->assertTrue( $e->is_retryable() );
		}
	}

	public function test_embed_throws_non_retryable_on_bad_request(): void {
		Functions\when( 'wp_remote_post' )->justReturn( [ 'ok' => true ] );
		Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 400 );
		Functions\when( 'wp_remote_retrieve_body' )->justReturn( '{}' );

		try {
			$this->provider()->embed( [ 'x' ] );
			$this->fail( 'expected exception' );
		} catch ( Fahad_AI_Embedding_Exception $e ) {
			$this->assertFalse( $e->is_retryable(), '4xx (except 429) is a client error, not retryable' );
		}
	}

	public function test_embed_throws_retryable_on_transport_error(): void {
		Functions\when( 'wp_remote_post' )->justReturn( new WP_Error( 'http_request_failed', 'boom' ) );

		try {
			$this->provider()->embed( [ 'x' ] );
			$this->fail( 'expected exception' );
		} catch ( Fahad_AI_Embedding_Exception $e ) {
			$this->assertTrue( $e->is_retryable() );
		}
	}

	public function test_is_available_reflects_key_presence(): void {
		$this->assertTrue( $this->provider( 'sk-x' )->is_available() );
		$this->assertFalse( $this->provider( '' )->is_available() );
		$this->assertSame( 'text-embedding-3-small', $this->provider()->model() );
		$this->assertSame( 512, $this->provider()->dimensions() );
	}

	public function test_factory_returns_openai_provider_when_keyed(): void {
		Functions\when( 'get_option' )->alias(
			static fn( $k, $d = '' ) => [
				'fahad_ai_openai_api_key'   => 'sk-live',
				'fahad_ai_embedding_model'  => 'text-embedding-3-small',
				'fahad_ai_embedding_dims'   => 512,
			][ $k ] ?? $d
		);
		Functions\when( 'apply_filters' )->alias( static fn( $hook, $value = null ) => $value );

		$p = Fahad_AI_Embeddings::provider();
		$this->assertInstanceOf( Fahad_AI_OpenAI_Embedding_Provider::class, $p );
		$this->assertTrue( $p->is_available() );
	}

	public function test_factory_returns_null_without_key(): void {
		Functions\when( 'get_option' )->alias( static fn( $k, $d = '' ) => $d );
		Functions\when( 'apply_filters' )->alias( static fn( $hook, $value = null ) => $value );

		$this->assertNull( Fahad_AI_Embeddings::provider() );
	}

	public function test_factory_respects_filter_override(): void {
		Functions\when( 'get_option' )->alias( static fn( $k, $d = '' ) => $d );
		$custom = $this->createMock( Fahad_AI_Embedding_Provider::class );
		Functions\when( 'apply_filters' )->alias(
			static fn( $hook, $value = null ) => 'fahad_ai_embedding_provider' === $hook ? $custom : $value
		);

		$this->assertSame( $custom, Fahad_AI_Embeddings::provider() );
	}
}
