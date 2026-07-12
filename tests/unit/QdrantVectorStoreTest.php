<?php
/**
 * Unit tests for the Qdrant external vector store + rerank seam (RAG Phase 3, S3.2, #113).
 *
 * OPT-IN scale tier behind the dukandaar_vector_store filter. The HTTP shapes are
 * unit-tested here; a live Qdrant instance is needed for an end-to-end check.
 * Also covers the optional cross-encoder rerank seam (off by default).
 */

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;

class QdrantVectorStoreTest extends TestCase {

	use MockeryPHPUnitIntegration;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( 'wp_json_encode' )->alias( static fn( $d ) => json_encode( $d ) );
		Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 200 );
		Functions\when( 'wp_remote_retrieve_body' )->justReturn( '{}' );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	private function store(): Dukandaar_Qdrant_Vector_Store {
		return new Dukandaar_Qdrant_Vector_Store( 'https://q.example.com', 'qk', 'products', 'text-embedding-3-small' );
	}

	public function test_implements_interface_and_availability_reflects_url(): void {
		$this->assertInstanceOf( Dukandaar_Vector_Store::class, $this->store() );
		$this->assertTrue( $this->store()->is_available() );
		$this->assertFalse( ( new Dukandaar_Qdrant_Vector_Store( '', 'k', 'c', 'm' ) )->is_available() );
	}

	public function test_query_searches_qdrant_and_returns_ranked_ids(): void {
		$captured = null;
		Functions\when( 'wp_remote_post' )->alias(
			static function ( $url, $args ) use ( &$captured ) {
				$captured = [ 'url' => $url, 'body' => json_decode( $args['body'], true ), 'key' => $args['headers']['api-key'] ?? '' ];
				return [ 'ok' => true ];
			}
		);
		Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 200 );
		Functions\when( 'wp_remote_retrieve_body' )->justReturn(
			json_encode( [ 'result' => [ [ 'id' => 12, 'score' => 0.9 ], [ 'id' => 10, 'score' => 0.7 ] ] ] )
		);

		$ids = $this->store()->query( [ 0.5, 0.25 ], 5, [ 10, 11, 12 ] );

		$this->assertSame( [ 12, 10 ], $ids, 'ranked ids from Qdrant result' );
		$this->assertStringContainsString( '/collections/products/points/search', $captured['url'] );
		$this->assertSame( 5, $captured['body']['limit'] );
		$this->assertSame( [ 0.5, 0.25 ], $captured['body']['vector'] );
		$this->assertSame( 'qk', $captured['key'] );
	}

	public function test_query_with_no_candidates_short_circuits(): void {
		$called = false;
		Functions\when( 'wp_remote_post' )->alias( static function () use ( &$called ) { $called = true; return []; } );
		$this->assertSame( [], $this->store()->query( [ 1.0 ], 5, [] ) );
		$this->assertFalse( $called );
	}

	public function test_upsert_puts_point_with_vector_and_payload(): void {
		$captured = null;
		Functions\when( 'wp_remote_request' )->alias(
			static function ( $url, $args ) use ( &$captured ) {
				$captured = [ 'url' => $url, 'method' => $args['method'], 'body' => json_decode( $args['body'], true ) ];
				return [ 'ok' => true ];
			}
		);
		Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 200 );

		$this->store()->upsert( 10, [ 0.1, 0.2 ], 'text-embedding-3-small', 'hash10' );

		$this->assertStringContainsString( '/collections/products/points', $captured['url'] );
		$point = $captured['body']['points'][0];
		$this->assertSame( 10, $point['id'] );
		$this->assertSame( [ 0.1, 0.2 ], $point['vector'] );
		$this->assertSame( 'text-embedding-3-small', $point['payload']['model'] );
		$this->assertSame( 'hash10', $point['payload']['content_hash'] );
	}

	public function test_query_throws_when_qdrant_unreachable(): void {
		Functions\when( 'wp_remote_post' )->justReturn( new WP_Error( 'http', 'down' ) );
		Functions\when( 'esc_html' )->alias( static fn( $s ) => $s );
		$this->expectException( Dukandaar_Embedding_Exception::class ); // caught upstream -> keyword fallback
		$this->store()->query( [ 1.0 ], 5, [ 10 ] );
	}

	// ── Rerank seam (optional, off by default) ──────────────────────────────────

	public function test_retriever_applies_a_registered_reranker(): void {
		Functions\when( 'get_transient' )->justReturn( false );
		Functions\when( 'set_transient' )->justReturn( true );
		Functions\when( 'wc_get_products' )->alias( static fn( $args ) => isset( $args['s'] ) ? [ 10 ] : [ 10, 12 ] );
		// A reranker that reverses the fused order.
		Functions\when( 'apply_filters' )->alias(
			static fn( $hook, $value, ...$rest ) => 'dukandaar_rerank' === $hook ? array_reverse( $value ) : $value
		);

		$provider = Mockery::mock( Dukandaar_Embedding_Provider::class );
		$provider->allows( 'embed' )->andReturn( [ [ 1.0, 0.0, 0.0 ] ] );
		$provider->allows( 'model' )->andReturn( 'm' );
		$provider->allows( 'dimensions' )->andReturn( 3 );
		$provider->allows( 'is_available' )->andReturn( true );
		$store = Mockery::mock( Dukandaar_Vector_Store::class );
		$store->allows( 'query' )->andReturn( [ 12, 10 ] );

		$ids = ( new Dukandaar_Retriever( $provider, $store ) )->search( 'warm', [], 10 );
		// Without rerank the fused order would start with 10; the reranker reverses it.
		$this->assertSame( array_reverse( Dukandaar_Rrf::fuse( [ [ 10 ], [ 12, 10 ] ] ) ), $ids );
	}
}
