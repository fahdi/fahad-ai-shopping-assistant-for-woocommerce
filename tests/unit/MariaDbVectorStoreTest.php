<?php
/**
 * Unit tests for the MariaDB-native vector backend + store factory (RAG Phase 3, S3.1, #112).
 *
 * OPT-IN scale tier: when MariaDB >= 11.7 (native VECTOR + VEC_DISTANCE_COSINE) is
 * detected, the store factory uses this backend instead of post meta; on any other
 * DB (e.g. MySQL 8) it stays on the default. The native SQL itself can only be
 * verified against a real MariaDB 11.7+ instance (not available here), these tests
 * cover capability detection, query SQL construction, and factory selection.
 */

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;

class MariaDbVectorStoreTest extends TestCase {

	use MockeryPHPUnitIntegration;

	private $wpdb;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		$this->wpdb         = Mockery::mock();
		$this->wpdb->prefix = 'wp_';
		$GLOBALS['wpdb']    = $this->wpdb;
	}

	protected function tearDown(): void {
		unset( $GLOBALS['wpdb'] );
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_implements_the_vector_store_interface(): void {
		$this->assertInstanceOf( Dukandaar_Vector_Store::class, new Dukandaar_MariaDb_Vector_Store( 'm', 3 ) );
	}

	/** Fresh mock per case, stacking allows() on one mock makes the first return win. */
	private function available_on( string $server_info ): bool {
		$wpdb            = Mockery::mock();
		$wpdb->prefix    = 'wp_';
		$wpdb->allows( 'db_server_info' )->andReturn( $server_info );
		$GLOBALS['wpdb'] = $wpdb;
		return ( new Dukandaar_MariaDb_Vector_Store( 'm', 3 ) )->is_available();
	}

	public function test_is_available_only_on_mariadb_11_7_plus(): void {
		$this->assertTrue( $this->available_on( '11.7.2-MariaDB' ) );
		$this->assertFalse( $this->available_on( '8.0.35' ), 'MySQL has no native VECTOR' );
		$this->assertFalse( $this->available_on( '10.11.6-MariaDB' ), 'MariaDB < 11.7 is too old' );
		$this->assertTrue( $this->available_on( '12.0.1-MariaDB' ), 'newer major is fine' );
	}

	public function test_query_uses_native_distance_with_model_and_candidate_filters(): void {
		$captured = null;
		$this->wpdb->allows( 'prepare' )->andReturnUsing( static fn( $sql ) => $sql ); // passthrough; we assert on the template
		$this->wpdb->allows( 'get_col' )->andReturnUsing(
			static function ( $sql ) use ( &$captured ) { $captured = $sql; return [ '10', '12' ]; }
		);

		$ids = ( new Dukandaar_MariaDb_Vector_Store( 'text-embedding-3-small', 3 ) )->query( [ 1.0, 0.0, 0.0 ], 5, [ 10, 11, 12 ] );

		$this->assertSame( [ 10, 12 ], $ids, 'returns ints from get_col' );
		$this->assertStringContainsStringIgnoringCase( 'VEC_DISTANCE_COSINE', $captured );
		$this->assertStringContainsString( 'wp_dukandaar_vectors', $captured );
		$this->assertStringContainsString( 'IN (10,11,12)', $captured, 'candidate pre-filter' );
		$this->assertStringContainsString( 'model', $captured, 'model filter (never mix models)' );
	}

	public function test_query_short_circuits_with_no_candidates(): void {
		$this->wpdb->shouldNotReceive( 'get_col' );
		$this->assertSame( [], ( new Dukandaar_MariaDb_Vector_Store( 'm', 3 ) )->query( [ 1.0 ], 5, [] ) );
	}

	// ── Store factory: auto-detect, default-safe ────────────────────────────────

	public function test_factory_uses_mariadb_when_available(): void {
		$this->wpdb->allows( 'db_server_info' )->andReturn( '11.7.2-MariaDB' );
		Functions\when( 'apply_filters' )->alias( static fn( $hook, $value = null ) => $value );
		$this->assertInstanceOf(
			Dukandaar_MariaDb_Vector_Store::class,
			Dukandaar_Vector_Stores::resolve( 'text-embedding-3-small', 512 )
		);
	}

	public function test_factory_defaults_to_postmeta_on_plain_mysql(): void {
		$this->wpdb->allows( 'db_server_info' )->andReturn( '8.0.35' );
		Functions\when( 'apply_filters' )->alias( static fn( $hook, $value = null ) => $value );
		$this->assertInstanceOf(
			Dukandaar_Postmeta_Vector_Store::class,
			Dukandaar_Vector_Stores::resolve( 'text-embedding-3-small', 512 )
		);
	}
}
