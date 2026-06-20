<?php
/**
 * Coverage tests for Fahad_AI_MariaDb_Vector_Store — exercises the write paths
 * (upsert / delete / content_hash / rebuild_required / maybe_create_table) and the
 * version-mismatch guard in is_available() that the sibling MariaDbVectorStoreTest
 * does not reach. The native VECTOR SQL is still only verified for shape (the live
 * query needs a real MariaDB 11.7+ server), so these assert on the SQL template and
 * the bound arguments handed to $wpdb.
 */

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;

class CoverageMariadbVectorStoreTest extends TestCase {

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

	// ── is_available(): the regex-miss guard (line 48) ──────────────────────────

	public function test_is_available_false_when_version_unparsable(): void {
		// "mariadb" present (passes the vendor sniff) but no "<num>.<num>" to parse,
		// so preg_match fails and is_available() must bail false.
		$this->wpdb->allows( 'db_server_info' )->andReturn( 'MariaDB-dev-build' );
		$store = new Fahad_AI_MariaDb_Vector_Store( 'm', 3 );
		$this->assertFalse( $store->is_available() );
	}

	public function test_is_available_false_when_wpdb_missing_db_server_info(): void {
		// Bare object without db_server_info — the is_callable guard returns false.
		$GLOBALS['wpdb'] = new stdClass();
		$store           = new Fahad_AI_MariaDb_Vector_Store( 'm', 3 );
		$this->assertFalse( $store->is_available() );
	}

	// ── upsert(): creates the table, then prepares the VECTOR insert ────────────

	public function test_upsert_creates_table_and_inserts_vector_literal(): void {
		$queries  = array();
		$prepared = null;

		// First query() call is the CREATE TABLE (raw string), second is the prepared INSERT.
		$this->wpdb->allows( 'query' )->andReturnUsing(
			static function ( $sql ) use ( &$queries ) {
				$queries[] = $sql;
				return 1;
			}
		);
		$this->wpdb->allows( 'prepare' )->andReturnUsing(
			static function ( $sql, ...$args ) use ( &$prepared ) {
				$prepared = array(
					'sql'  => $sql,
					'args' => $args,
				);
				return $sql; // passthrough; the INSERT template is what we assert
			}
		);

		( new Fahad_AI_MariaDb_Vector_Store( 'text-embedding-3-small', 3 ) )
			->upsert( 42, array( 0.5, -0.25, 1.0 ), 'text-embedding-3-small', 'abc123' );

		// Two raw queries hit $wpdb: CREATE TABLE first, then the INSERT.
		$this->assertCount( 2, $queries );
		$this->assertStringContainsString( 'CREATE TABLE IF NOT EXISTS wp_fahad_ai_vectors', $queries[0] );
		$this->assertStringContainsString( 'VECTOR(3)', $queries[0], 'dimensions interpolated into column type' );
		$this->assertStringContainsString( 'VECTOR INDEX (embedding)', $queries[0] );

		$this->assertNotNull( $prepared, 'INSERT goes through prepare()' );
		$this->assertStringContainsString( 'INSERT INTO wp_fahad_ai_vectors', $prepared['sql'] );
		$this->assertStringContainsString( 'VEC_FromText(%s)', $prepared['sql'] );
		$this->assertStringContainsString( 'ON DUPLICATE KEY UPDATE', $prepared['sql'] );

		// Bound args: product_id, model, vector literal, content_hash (in order).
		$this->assertSame(
			array( 42, 'text-embedding-3-small', '[0.5,-0.25,1]', 'abc123' ),
			$prepared['args']
		);
	}

	// ── delete(): prepared DELETE by product id ─────────────────────────────────

	public function test_delete_prepares_delete_by_product_id(): void {
		$prepared = null;
		$ran      = null;
		$this->wpdb->allows( 'prepare' )->andReturnUsing(
			static function ( $sql, ...$args ) use ( &$prepared ) {
				$prepared = array(
					'sql'  => $sql,
					'args' => $args,
				);
				return 'PREPARED_DELETE';
			}
		);
		$this->wpdb->allows( 'query' )->andReturnUsing(
			static function ( $sql ) use ( &$ran ) {
				$ran = $sql;
				return 1;
			}
		);

		( new Fahad_AI_MariaDb_Vector_Store( 'm', 3 ) )->delete( 99 );

		$this->assertSame( 'PREPARED_DELETE', $ran, 'the prepared statement is the one executed' );
		$this->assertStringContainsString( 'DELETE FROM wp_fahad_ai_vectors', $prepared['sql'] );
		$this->assertStringContainsString( 'WHERE product_id = %d', $prepared['sql'] );
		$this->assertSame( array( 99 ), $prepared['args'] );
	}

	// ── content_hash(): prepared SELECT, cast to string ─────────────────────────

	public function test_content_hash_returns_stored_hash(): void {
		$prepared = null;
		$this->wpdb->allows( 'prepare' )->andReturnUsing(
			static function ( $sql, ...$args ) use ( &$prepared ) {
				$prepared = array(
					'sql'  => $sql,
					'args' => $args,
				);
				return 'PREPARED_SELECT';
			}
		);
		$this->wpdb->allows( 'get_var' )->with( 'PREPARED_SELECT' )->andReturn( 'deadbeef' );

		$hash = ( new Fahad_AI_MariaDb_Vector_Store( 'm', 3 ) )->content_hash( 7 );

		$this->assertSame( 'deadbeef', $hash );
		$this->assertStringContainsString( 'SELECT content_hash FROM wp_fahad_ai_vectors', $prepared['sql'] );
		$this->assertStringContainsString( 'WHERE product_id = %d', $prepared['sql'] );
		$this->assertSame( array( 7 ), $prepared['args'] );
	}

	public function test_content_hash_casts_null_to_empty_string(): void {
		// No row → get_var returns null → (string) cast yields ''.
		$this->wpdb->allows( 'prepare' )->andReturn( 'PREPARED_SELECT' );
		$this->wpdb->allows( 'get_var' )->andReturn( null );

		$hash = ( new Fahad_AI_MariaDb_Vector_Store( 'm', 3 ) )->content_hash( 7 );

		$this->assertSame( '', $hash );
	}

	// ── rebuild_required(): compares the stored index model to ours ─────────────

	public function test_rebuild_required_true_when_index_model_differs(): void {
		Functions\when( 'get_option' )->justReturn( 'old-model' );
		$store = new Fahad_AI_MariaDb_Vector_Store( 'new-model', 3 );
		$this->assertTrue( $store->rebuild_required() );
	}

	public function test_rebuild_required_false_when_index_model_matches(): void {
		Functions\when( 'get_option' )->justReturn( 'same-model' );
		$store = new Fahad_AI_MariaDb_Vector_Store( 'same-model', 3 );
		$this->assertFalse( $store->rebuild_required() );
	}

	public function test_rebuild_required_reads_the_index_model_option(): void {
		$seen = null;
		Functions\when( 'get_option' )->alias(
			static function ( $name, $default = false ) use ( &$seen ) {
				$seen = $name;
				return '';
			}
		);
		$store = new Fahad_AI_MariaDb_Vector_Store( 'm', 3 );
		// '' !== 'm' → a rebuild is required, and the option key is the postmeta store's.
		$this->assertTrue( $store->rebuild_required() );
		$this->assertSame( Fahad_AI_Postmeta_Vector_Store::OPTION_INDEX_MODEL, $seen );
	}

	// ── maybe_create_table(): dimension is interpolated into the DDL ────────────

	public function test_upsert_interpolates_configured_dimensions_into_ddl(): void {
		$create = null;
		$this->wpdb->allows( 'query' )->andReturnUsing(
			static function ( $sql ) use ( &$create ) {
				if ( null === $create && false !== stripos( $sql, 'CREATE TABLE' ) ) {
					$create = $sql;
				}
				return 1;
			}
		);
		$this->wpdb->allows( 'prepare' )->andReturn( 'PREPARED_INSERT' );

		( new Fahad_AI_MariaDb_Vector_Store( 'm', 1536 ) )
			->upsert( 1, array( 0.0 ), 'm', 'h' );

		$this->assertNotNull( $create );
		$this->assertStringContainsString( 'VECTOR(1536)', $create, 'configured dimension drives the column width' );
		$this->assertStringContainsString( 'PRIMARY KEY', $create );
		$this->assertStringContainsString( 'content_hash CHAR(40)', $create );
	}
}
