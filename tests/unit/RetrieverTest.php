<?php
/**
 * Unit tests for the hybrid retriever (RAG Phase 1, S1.4, #107).
 *
 * Dukandaar_Retriever runs the keyword leg (WooCommerce search) and the vector leg
 * (embed query -> vector store) and fuses them with RRF. It plugs into the
 * EXISTING `dukandaar_semantic_retriever` seam (from #60), so search_products
 * becomes hybrid with no new tool and no api-handler change. With no provider or
 * an empty vector index it returns nothing, so search_products degrades to its
 * full keyword path (RAG-DESIGN.md §4.3, §6.2).
 */

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;

class RetrieverTest extends TestCase {

	use MockeryPHPUnitIntegration;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		// Query-embedding cache (#109): default to a miss so these tests exercise the real embed path.
		Functions\when( 'get_transient' )->justReturn( false );
		Functions\when( 'set_transient' )->justReturn( true );
		// Rerank seam (#113): no reranker registered -> passthrough.
		Functions\when( 'apply_filters' )->alias( static fn( $hook, $value = null ) => $value );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	private function provider( array $embed = [ [ 1.0, 0.0, 0.0 ] ] ) {
		$p = Mockery::mock( Dukandaar_Embedding_Provider::class );
		$p->allows( 'embed' )->andReturn( $embed );
		$p->allows( 'model' )->andReturn( 'text-embedding-3-small' );
		$p->allows( 'dimensions' )->andReturn( 3 );
		$p->allows( 'is_available' )->andReturn( true );
		return $p;
	}

	/** Stub wc_get_products: 's' present => keyword ids; else candidate ids. */
	private function stub_products( array $keyword, array $candidates ): void {
		Functions\when( 'wc_get_products' )->alias(
			static fn( $args ) => isset( $args['s'] ) && '' !== $args['s'] ? $keyword : $candidates
		);
	}

	public function test_search_fuses_keyword_and_vector_with_rrf(): void {
		$store = Mockery::mock( Dukandaar_Vector_Store::class );
		$store->allows( 'query' )->andReturn( [ 12, 10 ] );      // vector ranking
		$this->stub_products( [ 10, 11 ], [ 10, 11, 12 ] );      // keyword=[10,11], candidates=all

		$ids = ( new Dukandaar_Retriever( $this->provider(), $store ) )->search( 'warm', [], 10 );

		// RRF([10,11],[12,10]): 10 in both (top), then 12 (vec r1), then 11 (kw r2).
		$this->assertSame( [ 10, 12, 11 ], $ids );
	}

	public function test_search_respects_k(): void {
		$store = Mockery::mock( Dukandaar_Vector_Store::class );
		$store->allows( 'query' )->andReturn( [ 12, 10 ] );
		$this->stub_products( [ 10, 11 ], [ 10, 11, 12 ] );
		$this->assertCount( 2, ( new Dukandaar_Retriever( $this->provider(), $store ) )->search( 'warm', [], 2 ) );
	}

	public function test_search_returns_empty_when_query_cannot_be_embedded(): void {
		$store = Mockery::mock( Dukandaar_Vector_Store::class );
		$store->shouldNotReceive( 'query' );
		$retriever = new Dukandaar_Retriever( $this->provider( [] ), $store ); // embed returns nothing
		$this->assertSame( [], $retriever->search( 'warm', [], 10 ) );
	}

	public function test_search_returns_empty_when_index_has_no_match_so_keyword_takes_over(): void {
		$store = Mockery::mock( Dukandaar_Vector_Store::class );
		$store->allows( 'query' )->andReturn( [] ); // nothing embedded / no vector hit
		$this->stub_products( [ 10 ], [ 10 ] );
		// Empty vector leg => return [] so search_products runs its full keyword path.
		$this->assertSame( [], ( new Dukandaar_Retriever( $this->provider(), $store ) )->search( 'warm', [], 10 ) );
	}

	public function test_seam_callback_passes_through_when_no_provider(): void {
		// No key => factory returns null => the seam must return the incoming value
		// unchanged (null), so search_products keyword path runs. No HTTP, no vectors.
		Functions\when( 'get_option' )->alias( static fn( $k, $d = '' ) => $d );
		Functions\when( 'apply_filters' )->alias( static fn( $hook, $value = null ) => $value );

		$this->assertNull( Dukandaar_Retriever::resolve_seam( null, 'warm', [] ) );
	}
}
