<?php
/**
 * Unit tests for embedding cost / rate-limit controls (RAG Phase 2, S2.1, #109).
 *
 *  - The OpenAI provider retries transient failures (429/5xx/transport) with
 *    exponential backoff + jitter, then gives up; terminal errors fail fast.
 *  - The retriever caches a query's embedding so repeated identical shopper
 *    phrases are not re-embedded.
 * (The per-day embedding cap already shipped in the indexer, #106.)
 */

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;

class CostControlsTest extends TestCase {

	use MockeryPHPUnitIntegration;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( 'wp_json_encode' )->alias( static fn( $d ) => json_encode( $d ) );
		Functions\when( 'esc_html' )->alias( static fn( $s ) => $s );
		Functions\when( 'wp_rand' )->alias( static fn( $a = 0, $b = 0 ) => $a ); // deterministic jitter
		Functions\when( 'apply_filters' )->alias( static fn( $hook, $value = null ) => $value ); // rerank seam passthrough
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/** retry_base_ms = 0 keeps the test fast (no real sleep). */
	private function provider(): Dukandaar_OpenAI_Embedding_Provider {
		return new Dukandaar_OpenAI_Embedding_Provider( 'sk-test', 'text-embedding-3-small', 3, 0 );
	}

	private function codes( array $sequence ): void {
		$i = 0;
		Functions\when( 'wp_remote_post' )->justReturn( [ 'ok' => true ] );
		Functions\when( 'wp_remote_retrieve_response_code' )->alias(
			static function () use ( &$i, $sequence ) {
				$code = $sequence[ min( $i, count( $sequence ) - 1 ) ];
				++$i;
				return $code;
			}
		);
		Functions\when( 'wp_remote_retrieve_body' )->justReturn( json_encode( [ 'data' => [ [ 'index' => 0, 'embedding' => [ 0.1, 0.2, 0.3 ] ] ] ] ) );
	}

	public function test_retries_transient_failure_then_succeeds(): void {
		$calls = 0;
		Functions\when( 'wp_remote_post' )->alias( static function () use ( &$calls ) { ++$calls; return [ 'ok' => true ]; } );
		$seq = [ 429, 503, 200 ];
		$i   = 0;
		Functions\when( 'wp_remote_retrieve_response_code' )->alias( static function () use ( &$i, $seq ) { return $seq[ min( $i++, 2 ) ]; } );
		Functions\when( 'wp_remote_retrieve_body' )->justReturn( json_encode( [ 'data' => [ [ 'index' => 0, 'embedding' => [ 1.0 ] ] ] ] ) );

		$out = $this->provider()->embed( [ 'x' ] );
		$this->assertSame( [ [ 1.0 ] ], $out );
		$this->assertSame( 3, $calls, 'two retries then success' );
	}

	public function test_gives_up_after_max_retries_on_persistent_rate_limit(): void {
		$calls = 0;
		Functions\when( 'wp_remote_post' )->alias( static function () use ( &$calls ) { ++$calls; return [ 'ok' => true ]; } );
		Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 429 );
		Functions\when( 'wp_remote_retrieve_body' )->justReturn( '{}' );

		try {
			$this->provider()->embed( [ 'x' ] );
			$this->fail( 'expected exception' );
		} catch ( Dukandaar_Embedding_Exception $e ) {
			$this->assertTrue( $e->is_retryable() );
			$this->assertSame( 3, $calls, 'initial attempt + 2 retries, then gives up' );
		}
	}

	public function test_does_not_retry_a_terminal_error(): void {
		$calls = 0;
		Functions\when( 'wp_remote_post' )->alias( static function () use ( &$calls ) { ++$calls; return [ 'ok' => true ]; } );
		Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 400 );
		Functions\when( 'wp_remote_retrieve_body' )->justReturn( '{}' );

		try {
			$this->provider()->embed( [ 'x' ] );
			$this->fail( 'expected exception' );
		} catch ( Dukandaar_Embedding_Exception $e ) {
			$this->assertFalse( $e->is_retryable() );
			$this->assertSame( 1, $calls, '4xx fails fast, no retry' );
		}
	}

	public function test_retriever_caches_the_query_embedding(): void {
		$transients = [];
		Functions\when( 'get_transient' )->alias( function ( $k ) use ( &$transients ) { return $transients[ $k ] ?? false; } );
		Functions\when( 'set_transient' )->alias( function ( $k, $v ) use ( &$transients ) { $transients[ $k ] = $v; return true; } );
		Functions\when( 'wc_get_products' )->alias( static fn( $args ) => isset( $args['s'] ) ? [ 10 ] : [ 10, 12 ] );

		$provider = Mockery::mock( Dukandaar_Embedding_Provider::class );
		$provider->allows( 'model' )->andReturn( 'text-embedding-3-small' );
		$provider->allows( 'dimensions' )->andReturn( 3 );
		$provider->allows( 'is_available' )->andReturn( true );
		$provider->shouldReceive( 'embed' )->once()->andReturn( [ [ 1.0, 0.0, 0.0 ] ] ); // exactly ONE embed across two searches

		$store = Mockery::mock( Dukandaar_Vector_Store::class );
		$store->allows( 'query' )->andReturn( [ 12, 10 ] );

		$retriever = new Dukandaar_Retriever( $provider, $store );
		$first  = $retriever->search( 'warm jacket', [], 10 );
		$second = $retriever->search( 'warm jacket', [], 10 ); // identical -> served from cache
		$this->assertSame( $first, $second );
	}
}
