<?php
/**
 * Unit tests for index health + the model-change rebuild flow (RAG Phase 2, S2.2, #110).
 *
 *  - Dukandaar_Index_Health records embedding failures for the admin readout.
 *  - index_fields_safe() records a terminal failure (no rethrow) but lets a
 *    retryable one propagate so Action Scheduler retries.
 *  - Model change ⇒ index stale ⇒ vector scan skips old-model vectors ⇒ search
 *    falls back to keyword until a rebuild re-embeds under the new model.
 */

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;

class IndexHealthTest extends TestCase {

	use MockeryPHPUnitIntegration;

	/** @var array<string,mixed> */
	private array $options = [];

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		$this->options = [];
		Functions\when( 'update_option' )->alias( function ( $k, $v ) { $this->options[ $k ] = $v; return true; } );
		Functions\when( 'get_option' )->alias( fn( $k, $d = '' ) => $this->options[ $k ] ?? $d );
		Functions\when( 'delete_option' )->alias( function ( $k ) { unset( $this->options[ $k ] ); return true; } );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_health_records_and_clears_failures(): void {
		$this->assertSame( 0, Dukandaar_Index_Health::failures() );

		Dukandaar_Index_Health::record_failure( 'boom' );
		Dukandaar_Index_Health::record_failure( 'again' );
		$this->assertSame( 2, Dukandaar_Index_Health::failures() );
		$this->assertSame( 'again', Dukandaar_Index_Health::last_error() );

		Dukandaar_Index_Health::clear();
		$this->assertSame( 0, Dukandaar_Index_Health::failures() );
		$this->assertSame( '', Dukandaar_Index_Health::last_error() );
	}

	private function provider_that_throws( bool $retryable ) {
		$p = Mockery::mock( Dukandaar_Embedding_Provider::class );
		$p->allows( 'model' )->andReturn( 'text-embedding-3-small' );
		$p->allows( 'dimensions' )->andReturn( 512 );
		$p->allows( 'is_available' )->andReturn( true );
		$p->allows( 'embed' )->andThrow( new Dukandaar_Embedding_Exception( 'fail', $retryable ) );
		return $p;
	}

	private function store_needing_embed() {
		$store = Mockery::mock( Dukandaar_Vector_Store::class );
		$store->allows( 'content_hash' )->andReturn( '' ); // forces an embed attempt
		return $store;
	}

	public function test_index_fields_safe_records_terminal_failure_without_rethrow(): void {
		Functions\when( 'wp_strip_all_tags' )->alias( static fn( $s ) => $s );
		$indexer = new Dukandaar_Indexer( $this->provider_that_throws( false ), $this->store_needing_embed() );

		$result = $indexer->index_fields_safe( 7, [ 'title' => 'Hoodie' ] );

		$this->assertFalse( $result );
		$this->assertSame( 1, Dukandaar_Index_Health::failures(), 'terminal failure is recorded' );
	}

	public function test_index_fields_safe_rethrows_retryable_for_action_scheduler(): void {
		Functions\when( 'wp_strip_all_tags' )->alias( static fn( $s ) => $s );
		$indexer = new Dukandaar_Indexer( $this->provider_that_throws( true ), $this->store_needing_embed() );

		$this->expectException( Dukandaar_Embedding_Exception::class );
		try {
			$indexer->index_fields_safe( 7, [ 'title' => 'Hoodie' ] );
		} finally {
			$this->assertSame( 1, Dukandaar_Index_Health::failures(), 'failure recorded even when rethrown' );
		}
	}

	public function test_model_change_makes_index_stale_and_search_falls_back(): void {
		// A vector stored under the OLD model is skipped by a store on the NEW model.
		Functions\when( 'update_post_meta' )->alias( function ( $id, $k, $v ) { $this->options[ "m{$id}_{$k}" ] = $v; return true; } );
		Functions\when( 'get_post_meta' )->alias( fn( $id, $k, $s = false ) => $this->options[ "m{$id}_{$k}" ] ?? '' );

		$old = new Dukandaar_Postmeta_Vector_Store( 'old-model', 3 );
		$old->upsert( 10, [ 1.0, 0.0, 0.0 ], 'old-model', 'h' );

		$new = new Dukandaar_Postmeta_Vector_Store( 'new-model', 3 );
		$this->assertSame( [], $new->query( [ 1.0, 0.0, 0.0 ], 5, [ 10 ] ), 'old-model vector is skipped under the new model' );

		// rebuild_required reflects the active vs index model.
		$this->options['dukandaar_index_model'] = 'old-model';
		$this->assertTrue( $new->rebuild_required() );
		$this->options['dukandaar_index_model'] = 'new-model';
		$this->assertFalse( $new->rebuild_required() );
	}
}
