<?php
/**
 * Coverage gap test for Fahad_AI_Postmeta_Vector_Store::query() (#105).
 *
 * VectorStoreTest already exercises the happy path and most guards. The one
 * uncovered branch is the `continue;` at line 64 — the guard that skips a
 * candidate whose MODEL meta matches the active model (so it passes the line-59
 * cross-model check) but whose stored VECTOR blob is missing/empty or not a
 * string. The sibling suite's "missing vector" case never reaches that branch:
 * its un-embedded product has no model meta either, so it bails at the line-59
 * model mismatch first. Here we set the model meta WITHOUT a usable vector blob,
 * forcing execution into and past line 64.
 */

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;

class CoveragePostmetaVectorStoreTest extends TestCase {

	use MockeryPHPUnitIntegration;

	private const MODEL = 'text-embedding-3-small';

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

	private function store(): Fahad_AI_Postmeta_Vector_Store {
		return new Fahad_AI_Postmeta_Vector_Store( self::MODEL, 3 );
	}

	/**
	 * Directly seed the meta map so we can decouple model meta from the vector
	 * blob (upsert() always writes both together, which can't reproduce the
	 * "model present, blob absent" state line 64 guards against).
	 */
	private function seed( int $id, array $kv ): void {
		foreach ( $kv as $key => $value ) {
			$this->meta[ $id ][ $key ] = $value;
		}
	}

	/**
	 * Candidate whose model meta matches the active model (passes the line-59
	 * cross-model guard) but whose vector blob is an empty string. Execution must
	 * reach the line-63/64 guard and `continue`, excluding the id from results.
	 */
	public function test_query_skips_candidate_with_matching_model_but_empty_vector_blob(): void {
		$store = $this->store();

		// Good neighbour, fully embedded.
		$store->upsert( 10, [ 1.0, 0.0, 0.0 ], self::MODEL, 'h10' );

		// Matching model, but the stored vector is an empty string -> line 64.
		$this->seed( 11, [
			'_fahad_ai_embedding_model' => self::MODEL,
			'_fahad_ai_embedding'       => '',
		] );

		$ranked = $store->query( [ 1.0, 0.0, 0.0 ], 5, [ 10, 11 ] );

		$this->assertSame( [ 10 ], $ranked, 'empty blob must be skipped even when the model matches' );
		$this->assertNotContains( 11, $ranked );
	}

	/**
	 * Same branch, reached via a NON-STRING blob (e.g. a numeric value left in
	 * meta). `! is_string( $blob )` is the other half of the line-63 guard.
	 */
	public function test_query_skips_candidate_with_matching_model_but_non_string_vector_blob(): void {
		$store = $this->store();

		$store->upsert( 10, [ 1.0, 0.0, 0.0 ], self::MODEL, 'h10' );

		// Matching model, but the vector blob is an int, not a packed string.
		$this->seed( 12, [
			'_fahad_ai_embedding_model' => self::MODEL,
			'_fahad_ai_embedding'       => 0,
		] );

		$ranked = $store->query( [ 1.0, 0.0, 0.0 ], 5, [ 10, 12 ] );

		$this->assertSame( [ 10 ], $ranked, 'non-string blob must be skipped even when the model matches' );
		$this->assertNotContains( 12, $ranked );
	}

	/**
	 * Belt-and-braces: when the ONLY candidate has a matching model but an empty
	 * blob, query() returns no results (the scan produces an empty scored map).
	 */
	public function test_query_returns_empty_when_sole_candidate_has_empty_blob(): void {
		$store = $this->store();

		$this->seed( 11, [
			'_fahad_ai_embedding_model' => self::MODEL,
			'_fahad_ai_embedding'       => '',
		] );

		$this->assertSame( [], $store->query( [ 1.0, 0.0, 0.0 ], 5, [ 11 ] ) );
	}
}
