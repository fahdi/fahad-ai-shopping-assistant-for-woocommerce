<?php
/**
 * Unit tests for the embedding indexer (RAG Phase 1, S1.3, #106).
 *
 * Embeds products into the vector store, skipping re-embeds when the composed
 * text is unchanged (so a price-only edit is a no-op), honouring a per-day token
 * cap, and coalescing rapid saves via Action Scheduler. Embedding is always
 * async — never inline on a product save.
 */

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;

class IndexerTest extends TestCase {

	use MockeryPHPUnitIntegration;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( 'wp_strip_all_tags' )->alias( static fn( $s ) => trim( preg_replace( '/\s+/', ' ', (string) $s ) ) );
		Functions\when( 'get_option' )->alias( static fn( $k, $d = '' ) => $d );      // cap 0 (unlimited) by default
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

	public function test_indexes_a_product_when_its_text_changed(): void {
		$store = Mockery::mock( Fahad_AI_Vector_Store::class );
		$store->allows( 'content_hash' )->andReturn( '' ); // nothing stored yet
		$store->shouldReceive( 'upsert' )->once()->with(
			10,
			[ 0.1, 0.2, 0.3 ],
			'text-embedding-3-small',
			Mockery::type( 'string' )
		);

		$indexer = new Fahad_AI_Indexer( $this->provider(), $store );
		$this->assertTrue( $indexer->index_fields( 10, [ 'title' => 'Hoodie', 'description' => 'warm fleece' ] ) );
	}

	public function test_skips_re_embed_when_text_is_unchanged(): void {
		$fields = [ 'title' => 'Hoodie', 'description' => 'warm fleece' ];
		$hash   = Fahad_AI_Embedding_Document::content_hash( Fahad_AI_Embedding_Document::compose( $fields ) );

		$provider = $this->provider();
		$provider->shouldNotReceive( 'embed' ); // the whole point: no API call on a no-op edit

		$store = Mockery::mock( Fahad_AI_Vector_Store::class );
		$store->allows( 'content_hash' )->with( 10 )->andReturn( $hash ); // already indexed with same text
		$store->shouldNotReceive( 'upsert' );

		$indexer = new Fahad_AI_Indexer( $provider, $store );
		$this->assertFalse( $indexer->index_fields( 10, $fields ), 'unchanged text -> skipped (a price-only edit never re-embeds)' );
	}

	public function test_respects_the_daily_token_cap(): void {
		Functions\when( 'get_option' )->alias( static fn( $k, $d = '' ) => Fahad_AI_Indexer::OPTION_DAILY_CAP === $k ? 5 : $d );
		Functions\when( 'get_transient' )->justReturn( 5 ); // cap already reached today

		$provider = $this->provider();
		$provider->shouldNotReceive( 'embed' );
		$store = Mockery::mock( Fahad_AI_Vector_Store::class );
		$store->allows( 'content_hash' )->andReturn( '' );

		$indexer = new Fahad_AI_Indexer( $provider, $store );
		$this->assertFalse( $indexer->index_fields( 10, [ 'title' => 'X' ] ), 'over the daily cap -> deferred, no embed' );
	}

	public function test_zero_cap_means_unlimited(): void {
		Functions\when( 'get_transient' )->justReturn( 999999 );
		$indexer = new Fahad_AI_Indexer( $this->provider(), Mockery::mock( Fahad_AI_Vector_Store::class ) );
		$this->assertTrue( $indexer->within_daily_cap() );
	}

	public function test_enqueue_reembed_schedules_a_unique_async_action(): void {
		$captured = null;
		Functions\when( 'as_enqueue_async_action' )->alias(
			static function ( $hook, $args, $group, $unique ) use ( &$captured ) {
				$captured = compact( 'hook', 'args', 'group', 'unique' );
				return 1;
			}
		);

		Fahad_AI_Indexer::enqueue_reembed( 42 );

		$this->assertSame( Fahad_AI_Indexer::ACTION_EMBED, $captured['hook'] );
		$this->assertSame( [ 'product_id' => 42 ], $captured['args'] );
		$this->assertSame( Fahad_AI_Indexer::GROUP, $captured['group'] );
		$this->assertTrue( $captured['unique'], 'unique=true coalesces rapid repeated saves of the same product' );
	}

	public function test_enqueue_delete_schedules_a_delete_action(): void {
		$captured = null;
		Functions\when( 'as_enqueue_async_action' )->alias(
			static function ( $hook, $args ) use ( &$captured ) { $captured = compact( 'hook', 'args' ); return 1; }
		);
		Fahad_AI_Indexer::enqueue_delete( 7 );
		$this->assertSame( Fahad_AI_Indexer::ACTION_DELETE, $captured['hook'] );
		$this->assertSame( [ 'product_id' => 7 ], $captured['args'] );
	}

	public function test_reindex_deletes_when_the_product_is_gone(): void {
		Functions\when( 'wc_get_product' )->justReturn( false );
		$store = Mockery::mock( Fahad_AI_Vector_Store::class );
		$store->shouldReceive( 'delete' )->once()->with( 99 );

		$indexer = new Fahad_AI_Indexer( $this->provider(), $store );
		$indexer->reindex_product( 99 );
	}

	public function test_backfill_enqueues_one_action_per_product(): void {
		$count = 0;
		Functions\when( 'as_enqueue_async_action' )->alias( static function () use ( &$count ) { ++$count; return 1; } );
		$indexer = new Fahad_AI_Indexer( $this->provider(), Mockery::mock( Fahad_AI_Vector_Store::class ) );
		$this->assertSame( 3, $indexer->backfill( [ 10, 11, 12 ] ) );
		$this->assertSame( 3, $count );
	}
}
