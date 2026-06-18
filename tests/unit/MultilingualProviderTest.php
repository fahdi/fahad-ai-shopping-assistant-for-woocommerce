<?php
/**
 * Unit tests for embedding provider flexibility (RAG Phase 2, S2.3, #111).
 *
 *  - The OpenAI-compatible provider takes a configurable base URL, so embeddings
 *    can point at ANY OpenAI-compatible endpoint (OpenAI, Moonshot, Together, a
 *    self-hosted server) with that endpoint's key — not just OpenAI.
 *  - A Cohere provider (embed-multilingual-v3.0) for stronger non-Latin scripts.
 *  - The factory selects the provider from settings.
 */

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;

class MultilingualProviderTest extends TestCase {

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

	// ── OpenAI-compatible provider: configurable base URL ───────────────────────

	public function test_openai_provider_posts_to_a_configurable_base_url(): void {
		$captured = null;
		Functions\when( 'wp_remote_post' )->alias( static function ( $url ) use ( &$captured ) { $captured = $url; return [ 'ok' => true ]; } );
		Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 200 );
		Functions\when( 'wp_remote_retrieve_body' )->justReturn( json_encode( [ 'data' => [ [ 'index' => 0, 'embedding' => [ 1.0 ] ] ] ] ) );

		// Point at a Moonshot-style OpenAI-compatible endpoint.
		$p = new Fahad_AI_OpenAI_Embedding_Provider( 'sk-moon', 'text-embedding-3-small', 512, 0, 'https://api.moonshot.ai/v1' );
		$p->embed( [ 'hi' ] );

		$this->assertSame( 'https://api.moonshot.ai/v1/embeddings', $captured );
	}

	public function test_openai_provider_defaults_to_openai_base_url(): void {
		$captured = null;
		Functions\when( 'wp_remote_post' )->alias( static function ( $url ) use ( &$captured ) { $captured = $url; return [ 'ok' => true ]; } );
		Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 200 );
		Functions\when( 'wp_remote_retrieve_body' )->justReturn( json_encode( [ 'data' => [ [ 'index' => 0, 'embedding' => [ 1.0 ] ] ] ] ) );

		( new Fahad_AI_OpenAI_Embedding_Provider( 'sk', 'text-embedding-3-small', 512, 0 ) )->embed( [ 'hi' ] );
		$this->assertSame( 'https://api.openai.com/v1/embeddings', $captured );
	}

	// ── Cohere provider ─────────────────────────────────────────────────────────

	public function test_cohere_builds_request_and_parses_embeddings(): void {
		$captured = null;
		Functions\when( 'wp_remote_post' )->alias(
			static function ( $url, $args ) use ( &$captured ) {
				$captured = [ 'url' => $url, 'body' => json_decode( $args['body'], true ), 'auth' => $args['headers']['Authorization'] ];
				return [ 'ok' => true ];
			}
		);
		Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 200 );
		Functions\when( 'wp_remote_retrieve_body' )->justReturn( json_encode( [ 'embeddings' => [ 'float' => [ [ 0.1, 0.2 ], [ 0.3, 0.4 ] ] ] ] ) );

		$out = ( new Fahad_AI_Cohere_Embedding_Provider( 'co-key', 'embed-multilingual-v3.0' ) )->embed( [ 'a', 'b' ] );

		$this->assertStringContainsString( 'cohere.com', $captured['url'] );
		$this->assertSame( 'embed-multilingual-v3.0', $captured['body']['model'] );
		$this->assertSame( [ 'a', 'b' ], $captured['body']['texts'] );
		$this->assertSame( 'Bearer co-key', $captured['auth'] );
		$this->assertSame( [ [ 0.1, 0.2 ], [ 0.3, 0.4 ] ], $out );
	}

	public function test_cohere_throws_retryable_on_rate_limit(): void {
		Functions\when( 'wp_remote_post' )->justReturn( [ 'ok' => true ] );
		Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 429 );
		Functions\when( 'wp_remote_retrieve_body' )->justReturn( '{}' );

		try {
			( new Fahad_AI_Cohere_Embedding_Provider( 'co-key' ) )->embed( [ 'x' ] );
			$this->fail( 'expected exception' );
		} catch ( Fahad_AI_Embedding_Exception $e ) {
			$this->assertTrue( $e->is_retryable() );
		}
	}

	public function test_cohere_is_available_and_reports_model(): void {
		$p = new Fahad_AI_Cohere_Embedding_Provider( 'co-key', 'embed-multilingual-v3.0' );
		$this->assertTrue( $p->is_available() );
		$this->assertSame( 'embed-multilingual-v3.0', $p->model() );
		$this->assertFalse( ( new Fahad_AI_Cohere_Embedding_Provider( '' ) )->is_available() );
	}

	// ── Factory selection ───────────────────────────────────────────────────────

	private function with_options( array $opts ): void {
		Functions\when( 'get_option' )->alias( static fn( $k, $d = '' ) => $opts[ $k ] ?? $d );
		Functions\when( 'apply_filters' )->alias( static fn( $hook, $value = null ) => $value );
	}

	public function test_factory_selects_cohere_when_configured(): void {
		$this->with_options( [ 'fahad_ai_embedding_provider_type' => 'cohere', 'fahad_ai_cohere_api_key' => 'co-key' ] );
		$this->assertInstanceOf( Fahad_AI_Cohere_Embedding_Provider::class, Fahad_AI_Embeddings::provider() );
	}

	public function test_factory_defaults_to_openai_compatible(): void {
		$this->with_options( [ 'fahad_ai_embedding_api_key' => 'sk-x' ] );
		$this->assertInstanceOf( Fahad_AI_OpenAI_Embedding_Provider::class, Fahad_AI_Embeddings::provider() );
	}

	public function test_factory_falls_back_to_the_chat_openai_key(): void {
		// Backward compat: no dedicated embeddings key, but the existing OpenAI chat key is set.
		$this->with_options( [ 'fahad_ai_openai_api_key' => 'sk-legacy' ] );
		$this->assertInstanceOf( Fahad_AI_OpenAI_Embedding_Provider::class, Fahad_AI_Embeddings::provider() );
	}

	public function test_factory_null_without_any_key(): void {
		$this->with_options( [] );
		$this->assertNull( Fahad_AI_Embeddings::provider() );
	}
}
