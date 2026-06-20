<?php
/**
 * Supplemental line-coverage tests for the embedding indexer
 * (includes/class-indexer.php).
 *
 * Companion to IndexerTest. Drives the lifecycle wiring (init()), the static
 * Action Scheduler handlers (handle_embed_action / handle_delete_action), the
 * WooCommerce-resolving paths (reindex_product / product_fields / term_names),
 * and the index_fields guard/branch arms (empty document, empty vectors) that
 * the primary suite does not exercise. Every assertion checks real behaviour —
 * no bare smoke calls.
 */

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;

class CoverageIndexerTest extends TestCase {

	use MockeryPHPUnitIntegration;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( 'wp_strip_all_tags' )->alias( static fn( $s ) => trim( preg_replace( '/\s+/', ' ', (string) $s ) ) );
		Functions\when( 'get_option' )->alias( static fn( $k, $d = '' ) => $d ); // cap 0 (unlimited) by default
		Functions\when( 'get_transient' )->justReturn( 0 );
		Functions\when( 'set_transient' )->justReturn( true );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	private function provider( array $vectors = [ [ 0.1, 0.2, 0.3 ] ], string $model = 'text-embedding-3-small' ) {
		$p = Mockery::mock( Fahad_AI_Embedding_Provider::class );
		$p->allows( 'model' )->andReturn( $model );
		$p->allows( 'dimensions' )->andReturn( 512 );
		$p->allows( 'is_available' )->andReturn( true );
		$p->allows( 'embed' )->andReturn( $vectors );
		return $p;
	}

	// ── init(): product lifecycle + async handler wiring (lines 27-34) ───────────

	public function test_init_wires_every_product_lifecycle_and_handler_hook(): void {
		Monkey\Actions\expectAdded( 'woocommerce_update_product' )
			->once()->with( [ Fahad_AI_Indexer::class, 'enqueue_reembed' ] );
		Monkey\Actions\expectAdded( 'woocommerce_new_product' )
			->once()->with( [ Fahad_AI_Indexer::class, 'enqueue_reembed' ] );
		Monkey\Actions\expectAdded( 'wp_trash_post' )
			->once()->with( [ Fahad_AI_Indexer::class, 'enqueue_delete' ] );
		Monkey\Actions\expectAdded( 'before_delete_post' )
			->once()->with( [ Fahad_AI_Indexer::class, 'enqueue_delete' ] );
		Monkey\Actions\expectAdded( Fahad_AI_Indexer::ACTION_EMBED )
			->once()->with( [ Fahad_AI_Indexer::class, 'handle_embed_action' ] );
		Monkey\Actions\expectAdded( Fahad_AI_Indexer::ACTION_DELETE )
			->once()->with( [ Fahad_AI_Indexer::class, 'handle_delete_action' ] );

		Fahad_AI_Indexer::init();
	}

	// ── index_fields(): empty document deletes any stale vector (lines 42-44) ────

	public function test_empty_document_deletes_any_stale_embedding_and_skips(): void {
		$store = Mockery::mock( Fahad_AI_Vector_Store::class );
		$store->shouldReceive( 'delete' )->once()->with( 10 );
		$store->shouldNotReceive( 'content_hash' );
		$store->shouldNotReceive( 'upsert' );

		$provider = $this->provider();
		$provider->shouldNotReceive( 'embed' ); // nothing to embed → no API call

		$indexer = new Fahad_AI_Indexer( $provider, $store );
		// All-blank fields compose to '' → the empty-doc guard fires.
		$this->assertFalse( $indexer->index_fields( 10, [ 'title' => '   ', 'description' => '' ] ) );
	}

	// ── index_fields(): provider returns no vector → no upsert, returns false (62)

	public function test_returns_false_when_provider_yields_no_vector(): void {
		$store = Mockery::mock( Fahad_AI_Vector_Store::class );
		$store->allows( 'content_hash' )->andReturn( '' );
		$store->shouldNotReceive( 'upsert' ); // empty vector → never persisted

		$provider = $this->provider( [ [] ] ); // empty[0] → falls through to the false return
		$indexer  = new Fahad_AI_Indexer( $provider, $store );
		$this->assertFalse( $indexer->index_fields( 10, [ 'title' => 'Hoodie' ] ) );
	}

	// ── reindex_product(): resolves WC fields, composes, embeds (lines 72,164-181)

	public function test_reindex_product_embeds_resolved_woocommerce_fields(): void {
		// term_names() reads taxonomies: a real Term-ish object for categories,
		// a non-array (null) for tags → exercises both branches of term_names().
		$cat       = new stdClass();
		$cat->name = 'Outerwear';
		Functions\when( 'get_the_terms' )->alias(
			static fn( $id, $taxonomy ) => 'product_cat' === $taxonomy ? [ $cat, 'not-an-object' ] : false
		);

		$product = Mockery::mock( WC_Product::class );
		$product->allows( 'get_id' )->andReturn( 55 );
		$product->allows( 'get_name' )->andReturn( 'Winter Hoodie' );
		$product->allows( 'get_short_description' )->andReturn( 'Warm fleece' );
		$product->allows( 'get_description' )->andReturn( 'A cosy winter hoodie' );
		Functions\when( 'wc_get_product' )->justReturn( $product );

		// The composed text must include the title and the cleaned category name,
		// proving product_fields()/term_names() fed the document.
		$expected_doc  = Fahad_AI_Embedding_Document::compose( [
			'title'             => 'Winter Hoodie',
			'categories'        => [ 'Outerwear' ],
			'short_description' => 'Warm fleece',
			'description'       => 'A cosy winter hoodie',
			'tags'              => [],
		] );
		$expected_hash = Fahad_AI_Embedding_Document::content_hash( $expected_doc );

		$store = Mockery::mock( Fahad_AI_Vector_Store::class );
		$store->allows( 'content_hash' )->andReturn( '' );
		$store->shouldReceive( 'upsert' )->once()->with(
			55,
			[ 0.1, 0.2, 0.3 ],
			'text-embedding-3-small',
			$expected_hash
		);

		$indexer = new Fahad_AI_Indexer( $this->provider(), $store );
		$indexer->reindex_product( 55 );
	}

	public function test_term_names_returns_empty_when_taxonomy_helper_absent_or_nonarray(): void {
		// get_the_terms returns a WP_Error-ish non-array → term_names() returns [].
		Functions\when( 'get_the_terms' )->justReturn( null );

		$product = Mockery::mock( WC_Product::class );
		$product->allows( 'get_id' )->andReturn( 7 );
		$product->allows( 'get_name' )->andReturn( 'Plain Tee' );
		$product->allows( 'get_short_description' )->andReturn( '' );
		$product->allows( 'get_description' )->andReturn( '' );
		Functions\when( 'wc_get_product' )->justReturn( $product );

		// With no categories/tags the composed doc is just the title — confirming
		// term_names() dropped to [] for both taxonomies rather than erroring.
		$expected_hash = Fahad_AI_Embedding_Document::content_hash(
			Fahad_AI_Embedding_Document::compose( [ 'title' => 'Plain Tee' ] )
		);

		$store = Mockery::mock( Fahad_AI_Vector_Store::class );
		$store->allows( 'content_hash' )->andReturn( '' );
		$store->shouldReceive( 'upsert' )->once()->with(
			7,
			Mockery::type( 'array' ),
			'text-embedding-3-small',
			$expected_hash
		);

		$indexer = new Fahad_AI_Indexer( $this->provider(), $store );
		$indexer->reindex_product( 7 );
	}

	// ── backfill(null) with no wc_get_products() available → 0 jobs (lines 99-102)

	public function test_backfill_null_without_wc_query_helper_enqueues_nothing(): void {
		// backfill(null) resolves the product set itself: in a clean process
		// wc_get_products() is undefined, so the null branch falls to [] (no products).
		// When a sibling coverage test has stubbed wc_get_products via Patchwork, the
		// definition lingers after tearDown and function_exists() then reports true — so
		// we re-stub it to an empty set here. Either path yields the SAME behaviour under
		// test: no resolvable products -> nothing enqueued -> a zero count.
		if ( function_exists( 'wc_get_products' ) ) {
			Functions\when( 'wc_get_products' )->justReturn( [] );
		}

		$enqueued = 0;
		Functions\when( 'as_enqueue_async_action' )->alias( static function () use ( &$enqueued ) { ++$enqueued; return 1; } );

		$indexer = new Fahad_AI_Indexer( $this->provider(), Mockery::mock( Fahad_AI_Vector_Store::class ) );
		$this->assertSame( 0, $indexer->backfill( null ) );
		$this->assertSame( 0, $enqueued, 'no resolvable products -> no async jobs enqueued' );
	}

	public function test_backfill_null_uses_wc_get_products_when_available(): void {
		// wc_get_products IS defined here → the null branch resolves published ids.
		Functions\when( 'wc_get_products' )->justReturn( [ 21, 22 ] );
		$count = 0;
		Functions\when( 'as_enqueue_async_action' )->alias( static function () use ( &$count ) { ++$count; return 1; } );

		$indexer = new Fahad_AI_Indexer( $this->provider(), Mockery::mock( Fahad_AI_Vector_Store::class ) );
		$this->assertSame( 2, $indexer->backfill( null ), 'one job per published product id' );
		$this->assertSame( 2, $count );
	}

	// ── index_fields_safe(): terminal embedding error is recorded + swallowed ────

	public function test_index_fields_safe_records_and_swallows_a_terminal_error(): void {
		$recorded = null;
		Functions\when( 'update_option' )->alias(
			static function ( $name, $value ) use ( &$recorded ) {
				if ( 'fahad_ai_index_last_error' === $name ) {
					$recorded = $value;
				}
				return true;
			}
		);

		$store = Mockery::mock( Fahad_AI_Vector_Store::class );
		$store->allows( 'content_hash' )->andReturn( '' );

		$provider = Mockery::mock( Fahad_AI_Embedding_Provider::class );
		$provider->allows( 'model' )->andReturn( 'text-embedding-3-small' );
		$provider->allows( 'dimensions' )->andReturn( 512 );
		$provider->allows( 'is_available' )->andReturn( true );
		$provider->allows( 'embed' )->andThrow( new Fahad_AI_Embedding_Exception( 'bad api key', false ) );

		$indexer = new Fahad_AI_Indexer( $provider, $store );
		// Terminal (non-retryable) → recorded, swallowed, returns false (no rethrow).
		$this->assertFalse( $indexer->index_fields_safe( 10, [ 'title' => 'Hoodie' ] ) );
		$this->assertSame( 'bad api key', $recorded, 'the failure message is recorded for the health readout' );
	}

	// ── index_fields_safe(): retryable embedding error is recorded + rethrown ────

	public function test_index_fields_safe_rethrows_a_retryable_error_for_rescheduling(): void {
		Functions\when( 'update_option' )->justReturn( true );

		$store = Mockery::mock( Fahad_AI_Vector_Store::class );
		$store->allows( 'content_hash' )->andReturn( '' );

		$provider = Mockery::mock( Fahad_AI_Embedding_Provider::class );
		$provider->allows( 'model' )->andReturn( 'text-embedding-3-small' );
		$provider->allows( 'dimensions' )->andReturn( 512 );
		$provider->allows( 'is_available' )->andReturn( true );
		$provider->allows( 'embed' )->andThrow( new Fahad_AI_Embedding_Exception( 'rate limited', true ) );

		$indexer = new Fahad_AI_Indexer( $provider, $store );

		// Retryable → rethrown so Action Scheduler reschedules the job.
		$this->expectException( Fahad_AI_Embedding_Exception::class );
		$this->expectExceptionMessage( 'rate limited' );
		$indexer->index_fields_safe( 10, [ 'title' => 'Hoodie' ] );
	}

	// ── handle_embed_action(): semantic search OFF → early return (lines 144-145)

	public function test_handle_embed_action_noop_when_semantic_search_disabled(): void {
		Functions\when( 'get_option' )->alias(
			static fn( $k, $d = '' ) => 'fahad_ai_embeddings_enabled' === $k ? 0 : $d
		);
		// No provider/store work should happen — apply_filters must never be reached.
		Functions\expect( 'apply_filters' )->never();

		Fahad_AI_Indexer::handle_embed_action( 5 ); // returns void; absence of error == pass
		$this->assertTrue( true );
	}

	// ── handle_embed_action(): no usable provider → early return (lines 147-149) ─

	public function test_handle_embed_action_noop_when_provider_unavailable(): void {
		Functions\when( 'get_option' )->alias(
			static fn( $k, $d = '' ) => 'fahad_ai_embeddings_enabled' === $k ? 1 : $d
		);
		// enabled → true; provider() runs apply_filters('fahad_ai_embedding_provider').
		// Return a provider that reports itself unavailable → the guard returns early
		// before any vector-store resolution.
		$provider = Mockery::mock( Fahad_AI_Embedding_Provider::class );
		$provider->allows( 'model' )->andReturn( 'text-embedding-3-small' );
		$provider->allows( 'dimensions' )->andReturn( 512 );
		$provider->allows( 'is_available' )->andReturn( false );
		Functions\when( 'apply_filters' )->alias(
			static function ( $tag, $value = null ) use ( $provider ) {
				return 'fahad_ai_embedding_provider' === $tag ? $provider : $value;
			}
		);

		Fahad_AI_Indexer::handle_embed_action( 5 );
		// Provider unavailable → it must never be asked to embed.
		$provider->shouldNotHaveReceived( 'embed' );
	}

	// ── handle_embed_action(): full path resolves store + reindexes (151-152) ────

	public function test_handle_embed_action_resolves_store_and_reindexes_product(): void {
		Functions\when( 'get_option' )->alias(
			static fn( $k, $d = '' ) => 'fahad_ai_embeddings_enabled' === $k ? 1 : $d
		);

		$provider = $this->provider();

		// The product to be re-embedded.
		$product = Mockery::mock( WC_Product::class );
		$product->allows( 'get_id' )->andReturn( 88 );
		$product->allows( 'get_name' )->andReturn( 'Beanie' );
		$product->allows( 'get_short_description' )->andReturn( '' );
		$product->allows( 'get_description' )->andReturn( '' );
		Functions\when( 'wc_get_product' )->justReturn( $product );
		Functions\when( 'get_the_terms' )->justReturn( false );

		$store = Mockery::mock( Fahad_AI_Vector_Store::class );
		$store->allows( 'content_hash' )->andReturn( '' );
		$store->shouldReceive( 'upsert' )->once()->with(
			88,
			[ 0.1, 0.2, 0.3 ],
			'text-embedding-3-small',
			Mockery::type( 'string' )
		);

		// Both filters: provider resolution AND vector-store resolution. The store
		// filter lets us inject our mock instead of the auto-detected postmeta store.
		Functions\when( 'apply_filters' )->alias(
			static function ( $tag, $value = null ) use ( $provider, $store ) {
				if ( 'fahad_ai_embedding_provider' === $tag ) {
					return $provider;
				}
				if ( 'fahad_ai_vector_store' === $tag ) {
					return $store;
				}
				return $value;
			}
		);

		Fahad_AI_Indexer::handle_embed_action( '88' ); // string id → cast to int internally
	}

	// ── handle_delete_action(): with a provider → resolve(model,dims)->delete ────

	public function test_handle_delete_action_deletes_with_provider_model_and_dims(): void {
		$provider = Mockery::mock( Fahad_AI_Embedding_Provider::class );
		$provider->allows( 'model' )->andReturn( 'text-embedding-3-large' );
		$provider->allows( 'dimensions' )->andReturn( 1024 );
		$provider->allows( 'is_available' )->andReturn( true );

		$store = Mockery::mock( Fahad_AI_Vector_Store::class );
		$store->shouldReceive( 'delete' )->once()->with( 33 );

		$captured = [];
		Functions\when( 'apply_filters' )->alias(
			static function ( $tag, $value = null, $model = null, $dims = null ) use ( $provider, $store, &$captured ) {
				if ( 'fahad_ai_embedding_provider' === $tag ) {
					return $provider;
				}
				if ( 'fahad_ai_vector_store' === $tag ) {
					$captured = [ 'model' => $model, 'dims' => $dims ];
					return $store;
				}
				return $value;
			}
		);

		Fahad_AI_Indexer::handle_delete_action( '33' );

		$this->assertSame( 'text-embedding-3-large', $captured['model'] );
		$this->assertSame( 1024, $captured['dims'] );
	}

	// ── handle_delete_action(): no provider → empty model/0 dims still deletes ───

	public function test_handle_delete_action_deletes_with_empty_model_when_no_provider(): void {
		$store = Mockery::mock( Fahad_AI_Vector_Store::class );
		$store->shouldReceive( 'delete' )->once()->with( 44 );

		$captured = [];
		Functions\when( 'apply_filters' )->alias(
			static function ( $tag, $value = null, $model = null, $dims = null ) use ( $store, &$captured ) {
				if ( 'fahad_ai_embedding_provider' === $tag ) {
					return null; // no provider configured
				}
				if ( 'fahad_ai_vector_store' === $tag ) {
					$captured = [ 'model' => $model, 'dims' => $dims ];
					return $store;
				}
				return $value;
			}
		);

		Fahad_AI_Indexer::handle_delete_action( 44 );

		$this->assertSame( '', $captured['model'], 'no provider → empty model id' );
		$this->assertSame( 0, $captured['dims'], 'no provider → zero dimensions' );
	}
}
