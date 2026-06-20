<?php
/**
 * Supplemental line-coverage tests for Fahad_AI_Visual_Search (issue #63 seam).
 *
 * The primary behaviour is pinned by VisualSearchTest. This file targets the residual
 * uncovered lines that the primary suite does not exercise:
 *
 *   - register_routes(): the REST route registration (lines 100-104) — never invoked by
 *     the primary suite, which drives handle_search()/search() directly.
 *   - handle_search() with NO 'image' file part → the `: []` fallback (line 122), proving a
 *     fileless request degrades to the 400 "no image" validation error, never a fatal.
 *   - normalize_ids() skipping a NON-NUMERIC candidate (line 384 `continue`) — a provider
 *     that returns mixed garbage among real ids must have the garbage dropped, ids kept.
 *   - format_bytes() KB and B branches (lines 445/446/448) — driven through validate_image's
 *     "too large" message by filtering the max-byte ceiling into the KB and B ranges.
 *
 * Conventions mirror VisualSearchTest exactly: WP/WC functions via Brain\Monkey; WC objects
 * via Mockery; the singletons reset via ReflectionProperty between cases (NEVER
 * setAccessible — host runs PHP 8.5); additive stubs only.
 */

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;

class CoverageVisualSearchTest extends TestCase {

	use MockeryPHPUnitIntegration;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		// Same tool-layer + WP stubs the primary suite uses so the shared formatter and the
		// filter seam resolve. get_option defaults to a no-op; __ is a real pass-through from
		// wc-stubs.php (loaded before Patchwork) — NOT re-stubbed here.
		Functions\stubs( [
			'absint'                      => fn( $n ) => abs( (int) $n ),
			'sanitize_text_field'         => fn( $s ) => is_string( $s ) ? trim( $s ) : $s,
			'get_option'                  => fn( $key, $default = '' ) => $default,
			'wp_json_encode'              => fn( $d ) => json_encode( $d ),
			'wc_price'                    => fn( $p ) => '$' . $p,
			'wp_strip_all_tags'           => fn( $s ) => strip_tags( (string) $s ),
			'wp_get_attachment_image_url' => fn() => '',
			'wc_placeholder_img_src'      => fn() => 'http://example.com/placeholder.png',
			'get_permalink'               => fn( $id ) => 'http://example.com/?p=' . $id,
			'get_the_terms'               => fn() => [],
		] );
	}

	protected function tearDown(): void {
		( new ReflectionProperty( Fahad_AI_Visual_Search::class, 'instance' ) )->setValue( null, null );
		( new ReflectionProperty( Fahad_AI_Tools::class, 'instance' ) )->setValue( null, null );
		Monkey\tearDown();
		parent::tearDown();
	}

	/** Fresh singleton (reset between cases via reflection). */
	private function visual(): Fahad_AI_Visual_Search {
		( new ReflectionProperty( Fahad_AI_Visual_Search::class, 'instance' ) )->setValue( null, null );
		return Fahad_AI_Visual_Search::instance();
	}

	/** Register a stub visual retriever on the seam filter for one test. */
	private function registerRetriever( callable $retriever ): void {
		Monkey\Filters\expectApplied( 'fahad_ai_visual_retriever' )
			->andReturnUsing( $retriever );
	}

	/** A valid uploaded-image descriptor in the $_FILES shape (comfortably under the ceiling). */
	private function image( array $overrides = [] ): array {
		return array_merge( [
			'tmp_name' => '/tmp/fahad-ai-upload.jpg',
			'name'     => 'look.jpg',
			'type'     => 'image/jpeg',
			'size'     => 250 * 1024,
		], $overrides );
	}

	/** Happy-path product mock (mirrors VisualSearchTest::mockProduct). */
	private function mockProduct( int $id, string $name, string $price ): WC_Product {
		$p = Mockery::mock( WC_Product::class );
		$p->shouldReceive( 'get_id' )->andReturn( $id );
		$p->shouldReceive( 'get_name' )->andReturn( $name );
		$p->shouldReceive( 'get_price' )->andReturn( $price );
		$p->shouldReceive( 'get_regular_price' )->andReturn( $price );
		$p->shouldReceive( 'get_sale_price' )->andReturn( '' );
		$p->shouldReceive( 'is_on_sale' )->andReturn( false );
		$p->shouldReceive( 'is_visible' )->andReturn( true )->byDefault();
		$p->shouldReceive( 'is_in_stock' )->andReturn( true )->byDefault();
		$p->shouldReceive( 'get_short_description' )->andReturn( '' );
		$p->shouldReceive( 'get_image_id' )->andReturn( 0 );
		$p->shouldReceive( 'get_average_rating' )->andReturn( '4.5' )->byDefault();
		$p->shouldReceive( 'get_review_count' )->andReturn( 8 )->byDefault();
		return $p;
	}

	// ── register_routes(): the endpoint self-registration (lines 100-104) ────────────

	public function test_register_routes_wires_the_visual_search_post_endpoint(): void {
		// The class owns its own REST endpoint (mirroring the WhatsApp channel). register_routes
		// must register POST fahad-ai/v1/visual-search with handle_search as the callback and the
		// shared authorize_request gate as the permission callback — proving the billable lookup
		// is CSRF-protected and rate-capped exactly like a chat turn.
		$captured = null;
		Functions\expect( 'register_rest_route' )
			->once()
			->andReturnUsing( function ( $namespace, $route, $args ) use ( &$captured ) {
				$captured = compact( 'namespace', 'route', 'args' );
				return true;
			} );

		$gate    = static function () { return true; };
		$visual  = $this->visual();
		$visual->register_routes( $gate );

		$this->assertSame( 'fahad-ai/v1', $captured['namespace'] );
		$this->assertSame( '/visual-search', $captured['route'] );
		$this->assertSame( 'POST', $captured['args']['methods'] );
		// Callback is bound to handle_search on this very instance.
		$this->assertSame( [ $visual, 'handle_search' ], $captured['args']['callback'] );
		// The supplied authorize_request gate is wired verbatim as the permission callback.
		$this->assertSame( $gate, $captured['args']['permission_callback'] );
	}

	// ── handle_search() with NO file part: the `: []` fallback (line 122) ────────────

	public function test_handle_search_with_no_image_part_falls_back_to_empty_and_400s(): void {
		// A multipart request that carries NO 'image' file part hits the `: []` fallback, so
		// search() receives an empty descriptor and returns the 400 "no image" validation
		// WP_Error straight to the client — never a fatal, never a stray retrieval.
		Monkey\Filters\expectApplied( 'fahad_ai_visual_retriever' )->never();
		Functions\expect( 'wc_get_product' )->never();

		$request = Mockery::mock( 'WP_REST_Request' );
		$request->shouldReceive( 'get_file_params' )->andReturn( [] ); // no 'image' key
		$request->shouldReceive( 'get_param' )->andReturn( null );

		$response = $this->visual()->handle_search( $request );

		$this->assertInstanceOf( WP_Error::class, $response );
		$this->assertSame( 'fahad_ai_visual_no_image', $response->get_error_code() );
		$this->assertSame( 400, $response->data['status'] ?? 0 );
	}

	public function test_handle_search_with_non_array_image_part_falls_back_to_empty(): void {
		// Defensive: even if the 'image' part is present but is NOT an array (malformed
		// multipart), the guard still collapses to [] (line 122) and the request 400s rather
		// than passing a scalar on to validation.
		Monkey\Filters\expectApplied( 'fahad_ai_visual_retriever' )->never();
		Functions\expect( 'wc_get_product' )->never();

		$request = Mockery::mock( 'WP_REST_Request' );
		$request->shouldReceive( 'get_file_params' )->andReturn( [ 'image' => 'not-an-array' ] );
		$request->shouldReceive( 'get_param' )->andReturn( null );

		$response = $this->visual()->handle_search( $request );

		$this->assertInstanceOf( WP_Error::class, $response );
		$this->assertSame( 400, $response->data['status'] ?? 0 );
	}

	// ── normalize_ids(): non-numeric candidates are skipped (line 384 `continue`) ────

	public function test_non_numeric_candidates_are_skipped_numeric_ids_kept(): void {
		// A provider may return mixed garbage (strings, nulls, nested arrays) alongside real
		// ids. Each non-numeric entry is skipped (line 384), each numeric one coerced to a
		// positive int — so the live resolution sees only the clean ids, in first-seen order.
		$byId = [
			11 => $this->mockProduct( 11, 'Cable Knit Sweater', '55.00' ),
			22 => $this->mockProduct( 22, 'Ribbed Beanie', '18.00' ),
		];
		Functions\when( 'wc_get_product' )->alias( fn( $id ) => $byId[ (int) $id ] ?? false );

		// 'abc' (string), null, and the nested array are all non-numeric → skipped. The
		// numeric string '22' survives and coerces to int 22, proving is_numeric (not
		// is_int) gates the skip.
		$this->registerRetriever( fn( $value, $image, $filters ) => [ 'abc', 11, null, [ 99 ], '22' ] );

		$result = $this->visual()->search( $this->image() );

		$this->assertTrue( $result['available'] );
		$this->assertSame( 2, $result['found'] );
		$this->assertSame( [ 11, 22 ], array_column( $result['products'], 'id' ) );
	}

	// ── format_bytes(): KB and B branches via the "too large" message ────────────────

	public function test_too_large_message_renders_ceiling_in_KB(): void {
		// Filter the size ceiling into the KB range (>= 1 KB, < 1 MB). An oversized upload then
		// renders the "too large" message via format_bytes' KB branch (lines 445-446). 2048
		// bytes formats as "2 KB" (trailing .0 trimmed).
		Functions\when( 'apply_filters' )->alias( function ( $hook, $value = null ) {
			if ( 'fahad_ai_visual_max_bytes' === $hook ) {
				return 2048; // 2 KB ceiling
			}
			return $value; // identity for the retriever seam (never reached here)
		} );
		Functions\expect( 'wc_get_product' )->never();

		// 3 KB upload > the 2 KB ceiling → 413, message carries the KB-formatted ceiling.
		$result = $this->visual()->search( $this->image( [ 'size' => 3 * 1024 ] ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 413, $result->data['status'] ?? 0 );
		$this->assertStringContainsString( '2 KB', $result->get_error_message() );
	}

	public function test_too_large_message_renders_fractional_KB(): void {
		// A non-integer-KB ceiling exercises the number_format + trailing-zero-trim path for the
		// KB branch (e.g. 1536 bytes → "1.5 KB"), confirming the rtrim logic keeps a real
		// fractional digit rather than stripping it.
		Functions\when( 'apply_filters' )->alias( function ( $hook, $value = null ) {
			if ( 'fahad_ai_visual_max_bytes' === $hook ) {
				return 1536; // 1.5 KB
			}
			return $value;
		} );
		Functions\expect( 'wc_get_product' )->never();

		$result = $this->visual()->search( $this->image( [ 'size' => 4096 ] ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertStringContainsString( '1.5 KB', $result->get_error_message() );
	}

	public function test_too_large_message_renders_ceiling_in_bytes(): void {
		// Filter the ceiling BELOW 1 KB so format_bytes falls all the way through to the bare
		// bytes branch (line 448 `return $bytes . ' B'`). A 1000-byte ceiling renders "1000 B".
		Functions\when( 'apply_filters' )->alias( function ( $hook, $value = null ) {
			if ( 'fahad_ai_visual_max_bytes' === $hook ) {
				return 1000; // < 1 KB → bytes branch
			}
			return $value;
		} );
		Functions\expect( 'wc_get_product' )->never();

		// 2000 bytes > 1000-byte ceiling → 413, message carries "1000 B".
		$result = $this->visual()->search( $this->image( [ 'size' => 2000 ] ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 413, $result->data['status'] ?? 0 );
		$this->assertStringContainsString( '1000 B', $result->get_error_message() );
	}
}
