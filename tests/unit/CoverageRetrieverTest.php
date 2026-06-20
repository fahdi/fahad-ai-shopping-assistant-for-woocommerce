<?php
/**
 * Supplemental coverage for the hybrid retriever (RAG Phase 1, S1.4, #107).
 *
 * RetrieverTest already exercises the pure search() fusion path. This file fills
 * the gaps the primary suite leaves uncovered:
 *  - register(): the opt-in add_filter wiring on the semantic-search seam,
 *  - resolve_seam() guards: provider null / not-available -> keyword fallback,
 *  - resolve_seam() happy path: enabled + available provider + resolved store ->
 *    runs search() and returns hybrid ids (vs. fallback on empty),
 *  - resolve_seam() catch: a retrieval Throwable degrades to keyword search,
 *  - filter_args() branches: category / min_price / max_price flow into the
 *    wc_get_products args for both retrieval legs.
 *
 * The provider and store are injected through the existing
 * `fahad_ai_embedding_provider` and `fahad_ai_vector_store` filters so the static
 * factories (Fahad_AI_Embeddings::provider / Fahad_AI_Vector_Stores::resolve) run
 * for real but hand back Mockery doubles — no HTTP, no DB.
 */

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;

class CoverageRetrieverTest extends TestCase {

	use MockeryPHPUnitIntegration;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		// Query-embedding cache (#109): default to a miss so the real embed path runs.
		Functions\when( 'get_transient' )->justReturn( false );
		Functions\when( 'set_transient' )->justReturn( true );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * Build a provider double with sane defaults. embed() returns a single 3-dim
	 * vector so the cache-write + vector legs run.
	 */
	private function provider( array $embed = [ [ 1.0, 0.0, 0.0 ] ], bool $available = true ) {
		$p = Mockery::mock( Fahad_AI_Embedding_Provider::class );
		$p->allows( 'embed' )->andReturn( $embed );
		$p->allows( 'model' )->andReturn( 'text-embedding-3-small' );
		$p->allows( 'dimensions' )->andReturn( 3 );
		$p->allows( 'is_available' )->andReturn( $available );
		return $p;
	}

	/**
	 * Make apply_filters inject $provider for the provider hook and $store for the
	 * store hook, and pass the incoming value through for everything else (incl. the
	 * fahad_ai_rerank passthrough inside search()).
	 */
	private function inject_filters( $provider, $store ): void {
		Functions\when( 'apply_filters' )->alias(
			static function ( $hook, $value = null ) use ( $provider, $store ) {
				if ( 'fahad_ai_embedding_provider' === $hook ) {
					return $provider;
				}
				if ( 'fahad_ai_vector_store' === $hook ) {
					return $store;
				}
				return $value; // rerank seam + any other filter: passthrough.
			}
		);
	}

	// ── register() opt-in seam ───────────────────────────────────────────────────

	public function test_register_wires_the_semantic_retriever_seam(): void {
		$captured = [];
		Functions\when( 'add_filter' )->alias(
			static function ( $hook, $cb, $priority = 10, $args = 1 ) use ( &$captured ) {
				$captured = compact( 'hook', 'cb', 'priority', 'args' );
				return true;
			}
		);

		Fahad_AI_Retriever::register();

		$this->assertSame( 'fahad_ai_semantic_retriever', $captured['hook'] );
		$this->assertSame( [ Fahad_AI_Retriever::class, 'resolve_seam' ], $captured['cb'] );
		$this->assertSame( 10, $captured['priority'] );
		$this->assertSame( 3, $captured['args'] );
	}

	// ── resolve_seam() guards ────────────────────────────────────────────────────

	public function test_resolve_seam_passes_through_when_provider_is_null(): void {
		// enabled() true but no API key configured -> provider() resolves to null.
		Functions\when( 'get_option' )->alias(
			static fn( $name, $default = false ) => 'fahad_ai_embeddings_enabled' === $name ? 1 : $default
		);
		// provider() ends with apply_filters( 'fahad_ai_embedding_provider', null ) -> null.
		Functions\when( 'apply_filters' )->alias( static fn( $hook, $value = null ) => $value );

		$sentinel = [ 99 ];
		$this->assertSame(
			$sentinel,
			Fahad_AI_Retriever::resolve_seam( $sentinel, 'warm', [] ),
			'null provider must hand the incoming value back unchanged'
		);
	}

	public function test_resolve_seam_passes_through_when_provider_unavailable(): void {
		Functions\when( 'get_option' )->alias(
			static fn( $name, $default = false ) => 'fahad_ai_embeddings_enabled' === $name ? 1 : $default
		);
		$provider = $this->provider( [ [ 1.0, 0.0, 0.0 ] ], false ); // is_available() -> false
		// Inject the unavailable provider; store hook unused but kept consistent.
		$this->inject_filters( $provider, Mockery::mock( Fahad_AI_Vector_Store::class ) );

		$sentinel = [ 7 ];
		$this->assertSame(
			$sentinel,
			Fahad_AI_Retriever::resolve_seam( $sentinel, 'warm', [] ),
			'an unavailable provider must degrade to the incoming keyword value'
		);
	}

	// ── resolve_seam() happy path + catch ────────────────────────────────────────

	public function test_resolve_seam_returns_hybrid_ids_when_search_yields_results(): void {
		Functions\when( 'get_option' )->alias(
			static fn( $name, $default = false ) => 'fahad_ai_embeddings_enabled' === $name ? 1 : $default
		);
		Functions\when( 'wc_get_products' )->alias(
			static fn( $args ) => isset( $args['s'] ) && '' !== $args['s'] ? [ 10, 11 ] : [ 10, 11, 12 ]
		);

		$store = Mockery::mock( Fahad_AI_Vector_Store::class );
		$store->allows( 'query' )->andReturn( [ 12, 10 ] );
		$this->inject_filters( $this->provider(), $store );

		$ids = Fahad_AI_Retriever::resolve_seam( null, 'warm', [] );

		// RRF([10,11],[12,10]) -> 10 (both) first, then 12, then 11.
		$this->assertSame( [ 10, 12, 11 ], $ids );
	}

	public function test_resolve_seam_falls_back_when_search_is_empty(): void {
		Functions\when( 'get_option' )->alias(
			static fn( $name, $default = false ) => 'fahad_ai_embeddings_enabled' === $name ? 1 : $default
		);
		// Vector leg returns nothing -> search() returns [] -> seam hands back $ids.
		Functions\when( 'wc_get_products' )->justReturn( [ 10 ] );

		$store = Mockery::mock( Fahad_AI_Vector_Store::class );
		$store->allows( 'query' )->andReturn( [] );
		$this->inject_filters( $this->provider(), $store );

		$sentinel = [ 5 ];
		$this->assertSame(
			$sentinel,
			Fahad_AI_Retriever::resolve_seam( $sentinel, 'warm', [] ),
			'an empty hybrid result must fall back to the incoming keyword value'
		);
	}

	public function test_resolve_seam_swallows_throwable_and_degrades_to_keyword(): void {
		Functions\when( 'get_option' )->alias(
			static fn( $name, $default = false ) => 'fahad_ai_embeddings_enabled' === $name ? 1 : $default
		);
		Functions\when( 'wc_get_products' )->justReturn( [ 10, 11, 12 ] );

		$store = Mockery::mock( Fahad_AI_Vector_Store::class );
		$store->allows( 'query' )->andThrow( new \RuntimeException( 'vector backend exploded' ) );
		$this->inject_filters( $this->provider(), $store );

		$sentinel = [ 3 ];
		$this->assertSame(
			$sentinel,
			Fahad_AI_Retriever::resolve_seam( $sentinel, 'warm', [] ),
			'a retrieval Throwable must never surface; degrade to keyword'
		);
	}

	public function test_resolve_seam_clamps_limit_filter_to_at_least_one(): void {
		// A zero/negative limit must clamp to 1 (max( 1, (int) ... )); assert the
		// returned set honours the clamped k of 1.
		Functions\when( 'get_option' )->alias(
			static fn( $name, $default = false ) => 'fahad_ai_embeddings_enabled' === $name ? 1 : $default
		);
		Functions\when( 'wc_get_products' )->alias(
			static fn( $args ) => isset( $args['s'] ) && '' !== $args['s'] ? [ 10, 11 ] : [ 10, 11, 12 ]
		);

		$store = Mockery::mock( Fahad_AI_Vector_Store::class );
		$store->allows( 'query' )->andReturn( [ 12, 10 ] );
		$this->inject_filters( $this->provider(), $store );

		$ids = Fahad_AI_Retriever::resolve_seam( null, 'warm', [ 'limit' => 0 ] );

		$this->assertCount( 1, $ids, 'limit 0 must clamp to k=1' );
		$this->assertSame( [ 10 ], $ids );
	}

	// ── filter_args() branches (via search()) ────────────────────────────────────

	public function test_filter_args_applies_category_and_price_bounds_to_both_legs(): void {
		$captured = [];
		Functions\when( 'apply_filters' )->alias( static fn( $hook, $value = null ) => $value );
		Functions\when( 'wc_get_products' )->alias(
			static function ( $args ) use ( &$captured ) {
				$captured[] = $args;
				return isset( $args['s'] ) && '' !== $args['s'] ? [ 10 ] : [ 10, 12 ];
			}
		);

		$store = Mockery::mock( Fahad_AI_Vector_Store::class );
		$store->allows( 'query' )->andReturn( [ 12, 10 ] );

		$filters = [
			'category'  => 'hats',
			'min_price' => '15',
			'max_price' => 80,
		];
		$ids = ( new Fahad_AI_Retriever( $this->provider(), $store ) )->search( 'warm', $filters, 10 );

		$this->assertNotEmpty( $ids );

		// Two wc_get_products calls fired: candidate leg (limit -1, no 's') and keyword leg.
		$this->assertCount( 2, $captured );

		foreach ( $captured as $args ) {
			$this->assertSame( 'publish', $args['status'] );
			$this->assertSame( 'ids', $args['return'] );
			// category is normalised to a string-array (line 134).
			$this->assertSame( [ 'hats' ], $args['category'] );
			// min/max price cast to float (lines 137, 140).
			$this->assertSame( 15.0, $args['min_price'] );
			$this->assertSame( 80.0, $args['max_price'] );
		}

		// The candidate leg uses limit -1; the keyword leg carries the search term.
		$candidate = array_values( array_filter( $captured, static fn( $a ) => ! isset( $a['s'] ) ) )[0];
		$keyword   = array_values( array_filter( $captured, static fn( $a ) => isset( $a['s'] ) ) )[0];
		$this->assertSame( -1, $candidate['limit'] );
		$this->assertSame( 'warm', $keyword['s'] );
		$this->assertSame( 'relevance', $keyword['orderby'] );
	}

	public function test_search_uses_cached_query_vector_when_present(): void {
		// get_transient hit -> embed_query short-circuits at line 109, embed() never runs.
		Functions\when( 'apply_filters' )->alias( static fn( $hook, $value = null ) => $value );
		Functions\when( 'get_transient' )->justReturn( [ 1.0, 0.0, 0.0 ] );
		Functions\when( 'wc_get_products' )->alias(
			static fn( $args ) => isset( $args['s'] ) && '' !== $args['s'] ? [ 10 ] : [ 10, 12 ]
		);

		$provider = Mockery::mock( Fahad_AI_Embedding_Provider::class );
		$provider->allows( 'model' )->andReturn( 'text-embedding-3-small' );
		$provider->allows( 'dimensions' )->andReturn( 3 );
		// embed() must NOT be called on a cache hit.
		$provider->shouldNotReceive( 'embed' );

		$store = Mockery::mock( Fahad_AI_Vector_Store::class );
		$store->allows( 'query' )->andReturn( [ 12, 10 ] );

		$ids = ( new Fahad_AI_Retriever( $provider, $store ) )->search( 'warm', [], 10 );

		$this->assertSame( [ 10, 12 ], $ids );
	}
}
