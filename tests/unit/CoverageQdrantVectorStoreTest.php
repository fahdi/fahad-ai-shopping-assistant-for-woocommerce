<?php
/**
 * Supplemental coverage for the Qdrant external vector store (RAG Phase 3, S3.2, #113).
 *
 * Exercises the paths the primary QdrantVectorStoreTest leaves uncovered:
 * delete(), content_hash(), rebuild_required(), the non-2xx HTTP-error branch in
 * send(), and the opt-in register() seam (blank-URL early return + the filter
 * callback that builds the store and returns it / the fallback).
 */

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;

class CoverageQdrantVectorStoreTest extends TestCase {

	use MockeryPHPUnitIntegration;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( 'wp_json_encode' )->alias( static fn( $d ) => json_encode( $d ) );
		Functions\when( 'esc_html' )->alias( static fn( $s ) => $s );
		Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 200 );
		Functions\when( 'wp_remote_retrieve_body' )->justReturn( '{}' );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	private function store(): Fahad_AI_Qdrant_Vector_Store {
		return new Fahad_AI_Qdrant_Vector_Store( 'https://q.example.com/', 'qk', 'products', 'text-embedding-3-small' );
	}

	// ── delete() ─────────────────────────────────────────────────────────────────

	public function test_delete_posts_points_delete_payload(): void {
		$captured = null;
		Functions\when( 'wp_remote_post' )->alias(
			static function ( $url, $args ) use ( &$captured ) {
				$captured = [
					'url'    => $url,
					'method' => $args['method'],
					'body'   => json_decode( $args['body'], true ),
				];
				return [ 'ok' => true ];
			}
		);

		$this->store()->delete( 42 );

		$this->assertNotNull( $captured, 'delete() must issue an HTTP request' );
		$this->assertSame( 'POST', $captured['method'] );
		$this->assertStringContainsString( '/collections/products/points/delete', $captured['url'] );
		// The trailing slash on the base URL is trimmed before the path is appended.
		$this->assertStringNotContainsString( '.com//collections', $captured['url'] );
		$this->assertSame( [ 'points' => [ 42 ] ], $captured['body'] );
	}

	// ── content_hash() ─────────────────────────────────────────────────────────────

	public function test_content_hash_returns_payload_hash_from_result(): void {
		$captured = null;
		Functions\when( 'wp_remote_post' )->alias(
			static function ( $url, $args ) use ( &$captured ) {
				$captured = [ 'url' => $url, 'body' => json_decode( $args['body'], true ) ];
				return [ 'ok' => true ];
			}
		);
		Functions\when( 'wp_remote_retrieve_body' )->justReturn(
			json_encode( [ 'result' => [ [ 'payload' => [ 'content_hash' => 'abc123' ] ] ] ] )
		);

		$hash = $this->store()->content_hash( 7 );

		$this->assertSame( 'abc123', $hash );
		$this->assertStringContainsString( '/collections/products/points', $captured['url'] );
		$this->assertSame( [ 7 ], $captured['body']['ids'] );
		$this->assertTrue( $captured['body']['with_payload'] );
	}

	public function test_content_hash_returns_empty_string_when_payload_absent(): void {
		Functions\when( 'wp_remote_post' )->justReturn( [ 'ok' => true ] );
		Functions\when( 'wp_remote_retrieve_body' )->justReturn( json_encode( [ 'result' => [] ] ) );

		$this->assertSame( '', $this->store()->content_hash( 7 ) );
	}

	// ── rebuild_required() ─────────────────────────────────────────────────────────

	public function test_rebuild_required_true_when_indexed_model_differs(): void {
		Functions\when( 'get_option' )->justReturn( 'some-other-model' );
		$this->assertTrue( $this->store()->rebuild_required() );
	}

	public function test_rebuild_required_false_when_indexed_model_matches(): void {
		Functions\when( 'get_option' )->justReturn( 'text-embedding-3-small' );
		$this->assertFalse( $this->store()->rebuild_required() );
	}

	// ── send() HTTP-error branch ───────────────────────────────────────────────────

	public function test_non_2xx_response_throws_retryable_for_5xx(): void {
		Functions\when( 'wp_remote_post' )->justReturn( [ 'ok' => true ] );
		Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 503 );

		try {
			$this->store()->delete( 1 );
			$this->fail( 'expected Fahad_AI_Embedding_Exception for a 5xx response' );
		} catch ( Fahad_AI_Embedding_Exception $e ) {
			$this->assertStringContainsString( 'HTTP 503', $e->getMessage() );
			$this->assertTrue( $e->is_retryable(), '5xx must be retryable' );
		}
	}

	public function test_non_2xx_response_throws_retryable_for_429(): void {
		Functions\when( 'wp_remote_post' )->justReturn( [ 'ok' => true ] );
		Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 429 );

		try {
			$this->store()->delete( 1 );
			$this->fail( 'expected Fahad_AI_Embedding_Exception for 429' );
		} catch ( Fahad_AI_Embedding_Exception $e ) {
			$this->assertStringContainsString( 'HTTP 429', $e->getMessage() );
			$this->assertTrue( $e->is_retryable(), '429 must be retryable' );
		}
	}

	public function test_non_2xx_response_throws_non_retryable_for_4xx(): void {
		Functions\when( 'wp_remote_post' )->justReturn( [ 'ok' => true ] );
		Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 404 );

		try {
			$this->store()->delete( 1 );
			$this->fail( 'expected Fahad_AI_Embedding_Exception for a 4xx response' );
		} catch ( Fahad_AI_Embedding_Exception $e ) {
			$this->assertStringContainsString( 'HTTP 404', $e->getMessage() );
			$this->assertFalse( $e->is_retryable(), '4xx (not 429) must be terminal' );
		}
	}

	// ── register() opt-in seam ─────────────────────────────────────────────────────

	public function test_register_no_ops_when_url_option_blank(): void {
		Functions\when( 'get_option' )->justReturn( '' );
		// add_filter must NOT be invoked when no URL is configured.
		Functions\expect( 'add_filter' )->never();

		Fahad_AI_Qdrant_Vector_Store::register();

		$this->assertTrue( true ); // reaching here proves the early return ran without registering.
	}

	public function test_register_adds_filter_and_callback_returns_store_when_available(): void {
		Functions\when( 'get_option' )->alias(
			static function ( $name, $default = false ) {
				return match ( $name ) {
					'fahad_ai_qdrant_url'        => 'https://q.example.com',
					'fahad_ai_qdrant_key'        => 'secret-key',
					'fahad_ai_qdrant_collection' => 'my_products',
					default                      => $default,
				};
			}
		);

		$captured = [];
		Functions\when( 'add_filter' )->alias(
			static function ( $hook, $cb, $priority = 10, $args = 1 ) use ( &$captured ) {
				$captured = compact( 'hook', 'cb', 'priority', 'args' );
				return true;
			}
		);

		Fahad_AI_Qdrant_Vector_Store::register();

		$this->assertSame( 'fahad_ai_vector_store', $captured['hook'] );
		$this->assertSame( 10, $captured['priority'] );
		$this->assertSame( 2, $captured['args'] );
		$this->assertIsCallable( $captured['cb'] );

		// Invoke the registered callback: a configured URL yields an available store,
		// so the callback returns the Qdrant store rather than the fallback.
		$fallback = Mockery::mock( Fahad_AI_Vector_Store::class );
		$result   = ( $captured['cb'] )( $fallback, 'text-embedding-3-small' );

		$this->assertInstanceOf( Fahad_AI_Qdrant_Vector_Store::class, $result );
		$this->assertNotSame( $fallback, $result );
		$this->assertTrue( $result->is_available() );
	}

	public function test_register_callback_returns_fallback_when_store_unavailable(): void {
		// URL non-blank so register() proceeds, but collection blank so the built
		// store is NOT available — the callback must hand back the fallback.
		Functions\when( 'get_option' )->alias(
			static function ( $name, $default = false ) {
				return match ( $name ) {
					'fahad_ai_qdrant_url'        => 'https://q.example.com',
					'fahad_ai_qdrant_key'        => 'k',
					'fahad_ai_qdrant_collection' => '',
					default                      => $default,
				};
			}
		);

		$cb = null;
		Functions\when( 'add_filter' )->alias(
			static function ( $hook, $callback ) use ( &$cb ) {
				$cb = $callback;
				return true;
			}
		);

		Fahad_AI_Qdrant_Vector_Store::register();

		$this->assertIsCallable( $cb );
		$fallback = Mockery::mock( Fahad_AI_Vector_Store::class );
		$result   = $cb( $fallback, 'm' );

		$this->assertSame( $fallback, $result, 'an unavailable store falls back' );
	}
}
