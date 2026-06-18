<?php
/**
 * Unit tests for the default vector store (RAG Phase 1, S1.2, #105).
 *
 * The default backend stores each product's embedding as POST META (packed
 * float32 + model + dim + content_hash), not a custom table — consistent with
 * the plugin's zero-custom-table convention (even analytics uses options) and
 * auto-cleaned when a product is deleted. query() runs the brute-force cosine
 * scan over a caller-supplied candidate set, skipping vectors built under a
 * different model (stale) so models are never mixed (RAG-DESIGN.md §5.5).
 */

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;

class VectorStoreTest extends TestCase {

	use MockeryPHPUnitIntegration;

	/** @var array<int, array<string, mixed>> simulated post meta */
	private array $meta = [];

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		$this->meta = [];

		Functions\when( 'update_post_meta' )->alias(
			function ( $id, $key, $value ) {
				$this->meta[ $id ][ $key ] = $value;
				return true;
			}
		);
		Functions\when( 'get_post_meta' )->alias(
			fn( $id, $key, $single = false ) => $this->meta[ $id ][ $key ] ?? ''
		);
		Functions\when( 'delete_post_meta' )->alias(
			function ( $id, $key ) {
				unset( $this->meta[ $id ][ $key ] );
				return true;
			}
		);
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	private function store( string $model = 'text-embedding-3-small', int $dim = 3 ): Fahad_AI_Postmeta_Vector_Store {
		return new Fahad_AI_Postmeta_Vector_Store( $model, $dim );
	}

	public function test_implements_the_vector_store_interface(): void {
		$this->assertInstanceOf( Fahad_AI_Vector_Store::class, $this->store() );
		$this->assertTrue( $this->store()->is_available() );
	}

	public function test_upsert_writes_vector_model_and_hash_then_query_ranks_by_cosine(): void {
		$store = $this->store();
		$store->upsert( 10, [ 1.0, 0.0, 0.0 ], 'text-embedding-3-small', 'h10' );
		$store->upsert( 11, [ 0.0, 1.0, 0.0 ], 'text-embedding-3-small', 'h11' );
		$store->upsert( 12, [ 0.9, 0.1, 0.0 ], 'text-embedding-3-small', 'h12' );

		// Query close to [1,0,0]: expect 10 (cos 1) then 12 (cos ~0.994) then 11 (cos 0 -> excluded? kept but last).
		$ranked = $store->query( [ 1.0, 0.0, 0.0 ], 3, [ 10, 11, 12 ] );
		$this->assertSame( [ 10, 12 ], array_slice( $ranked, 0, 2 ), 'ranked by descending cosine' );
		$this->assertContains( 10, $ranked );
	}

	public function test_query_respects_k(): void {
		$store = $this->store();
		$store->upsert( 10, [ 1.0, 0.0, 0.0 ], 'text-embedding-3-small', 'h' );
		$store->upsert( 12, [ 0.9, 0.0, 0.0 ], 'text-embedding-3-small', 'h' );
		$this->assertCount( 1, $store->query( [ 1.0, 0.0, 0.0 ], 1, [ 10, 12 ] ) );
	}

	public function test_query_skips_vectors_built_under_a_different_model(): void {
		$store = $this->store( 'text-embedding-3-small', 3 );
		$store->upsert( 10, [ 1.0, 0.0, 0.0 ], 'text-embedding-3-small', 'h' );
		$store->upsert( 11, [ 1.0, 0.0, 0.0 ], 'some-old-model', 'h' ); // stale model

		$ranked = $store->query( [ 1.0, 0.0, 0.0 ], 5, [ 10, 11 ] );
		$this->assertSame( [ 10 ], $ranked, 'a vector from another model must never be compared' );
	}

	public function test_query_skips_missing_or_dim_mismatched_vectors(): void {
		$store = $this->store( 'text-embedding-3-small', 3 );
		$store->upsert( 10, [ 1.0, 0.0, 0.0 ], 'text-embedding-3-small', 'h' );
		$store->upsert( 11, [ 1.0, 0.0 ], 'text-embedding-3-small', 'h' ); // wrong dim
		// 12 has no embedding at all.

		$this->assertSame( [ 10 ], $store->query( [ 1.0, 0.0, 0.0 ], 5, [ 10, 11, 12 ] ) );
	}

	public function test_delete_removes_the_embedding(): void {
		$store = $this->store();
		$store->upsert( 10, [ 1.0, 0.0, 0.0 ], 'text-embedding-3-small', 'h' );
		$store->delete( 10 );
		$this->assertSame( [], $store->query( [ 1.0, 0.0, 0.0 ], 5, [ 10 ] ) );
		$this->assertSame( '', $store->content_hash( 10 ) );
	}

	public function test_content_hash_round_trips(): void {
		$store = $this->store();
		$store->upsert( 10, [ 1.0, 0.0, 0.0 ], 'text-embedding-3-small', 'abc123' );
		$this->assertSame( 'abc123', $store->content_hash( 10 ) );
		$this->assertSame( '', $store->content_hash( 999 ), 'no embedding -> empty hash' );
	}

	public function test_rebuild_required_when_index_model_differs_from_active(): void {
		Functions\when( 'get_option' )->alias(
			fn( $k, $d = '' ) => 'fahad_ai_index_model' === $k ? 'old-model' : $d
		);
		$this->assertTrue( $this->store( 'text-embedding-3-small' )->rebuild_required() );

		Functions\when( 'get_option' )->alias(
			fn( $k, $d = '' ) => 'fahad_ai_index_model' === $k ? 'text-embedding-3-small' : $d
		);
		$this->assertFalse( $this->store( 'text-embedding-3-small' )->rebuild_required() );
	}
}
