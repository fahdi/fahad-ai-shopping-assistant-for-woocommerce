<?php
/**
 * Unit tests for the visual / image-search retriever seam (issue #63: "shop the look").
 *
 * Red → Green → Refactor. Conventions mirror SemanticSearchTest (#60) and WhatsAppTest
 * (#62): WP/WC functions mocked via Brain\Monkey; WC objects via Mockery; the singleton
 * reset via reflection between cases (NEVER ReflectionMethod::setAccessible — host runs
 * PHP 8.5); get_option defaulted; additive stubs only.
 *
 * ─── WHAT IS ACTUALLY TESTABLE HERE ─────────────────────────────────────────────────
 *
 * A real vision-embeddings provider is NOT available, so this is TESTED SCAFFOLDING
 * behind a provider seam — going live needs a vision/embeddings backend. There is NO real
 * outbound vision-API call anywhere; the actual ranking is a `fahad_ai_visual_retriever`
 * filter a provider implements (exactly the shape #60 uses for text). These tests pin the
 * security- and contract-critical PHP:
 *
 *   - Upload validation: an oversized image, or one with an invalid/unsafe MIME type, is
 *     rejected cleanly (WP_Error) BEFORE any retrieval runs — never a fatal, never a spew.
 *   - The seam: a registered retriever returns ranked product IDs for the image; those IDs
 *     are resolved LIVE via wc_get_product() so price/stock come from the live WC_Product
 *     at call time (never cached/embedded), and invisible/unpublished IDs are dropped.
 *   - Graceful degradation: NO provider registered → an honest "visual search isn't
 *     available" result (no error spew, no fatal). A retriever that returns nothing → a
 *     graceful "no match" result.
 *   - No retention: the validated image is never moved/copied/persisted by the search core
 *     (default: do not store) — pinned by asserting no move_uploaded_file/copy is invoked.
 *
 * A STUB retriever proves the seam, exactly as SemanticSearchTest stubs a semantic
 * provider and WhatsAppTest stubs a send provider.
 */

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;

class VisualSearchTest extends TestCase {

	use MockeryPHPUnitIntegration;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		// Tool-layer stubs (mirrors SemanticSearchTest) so the SHARED product formatter
		// runs against mocked products. get_option defaults so any future option-read is a
		// no-op; apply_filters backs the retriever seam (overridden per test). __ / esc_html__
		// are real pass-throughs from wc-stubs.php (loaded before Patchwork) — NOT re-stubbed.
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

	/**
	 * Register a stub visual retriever on the seam filter for one test. The retriever
	 * receives ( $passthrough, array $image, array $filters ) and returns ranked product
	 * IDs — exactly the contract a real vision-embeddings provider implements.
	 *
	 * @param callable $retriever fn( $value, array $image, array $filters ): mixed
	 */
	private function registerRetriever( callable $retriever ): void {
		Monkey\Filters\expectApplied( 'fahad_ai_visual_retriever' )
			->andReturnUsing( $retriever );
	}

	/**
	 * A valid uploaded-image descriptor in the $_FILES shape: a readable temp file, a
	 * declared size, and an allowed image MIME type. Tests tweak fields to drive rejection.
	 *
	 * @param array $overrides Field overrides (e.g. [ 'size' => ..., 'type' => ... ]).
	 */
	private function image( array $overrides = [] ): array {
		return array_merge( [
			'tmp_name' => '/tmp/fahad-ai-upload.jpg',
			'name'     => 'look.jpg',
			'type'     => 'image/jpeg',
			'size'     => 250 * 1024, // 250 KB — comfortably under the default ceiling.
		], $overrides );
	}

	/**
	 * Happy-path product mock (mirrors SemanticSearchTest::mockProduct). price/stock are
	 * supplied per-call so a test can assert they come from the LIVE product the id
	 * resolved to, NOT from anything the retriever returned.
	 */
	private function mockProduct( int $id, string $name, string $price, bool $inStock = true ): WC_Product {
		$p = Mockery::mock( WC_Product::class );
		$p->shouldReceive( 'get_id' )->andReturn( $id );
		$p->shouldReceive( 'get_name' )->andReturn( $name );
		$p->shouldReceive( 'get_price' )->andReturn( $price );
		$p->shouldReceive( 'get_regular_price' )->andReturn( $price );
		$p->shouldReceive( 'get_sale_price' )->andReturn( '' );
		$p->shouldReceive( 'is_on_sale' )->andReturn( false );
		$p->shouldReceive( 'is_visible' )->andReturn( true )->byDefault();
		$p->shouldReceive( 'is_in_stock' )->andReturn( $inStock )->byDefault();
		$p->shouldReceive( 'get_short_description' )->andReturn( '' );
		$p->shouldReceive( 'get_image_id' )->andReturn( 0 );
		$p->shouldReceive( 'get_average_rating' )->andReturn( '4.5' )->byDefault();
		$p->shouldReceive( 'get_review_count' )->andReturn( 8 )->byDefault();
		return $p;
	}

	// ── seam present: retriever drives the results ──────────────────────────────────

	public function test_valid_image_with_retriever_returns_live_resolved_cards(): void {
		// A valid image + a registered retriever → the retriever's ranked ids are resolved
		// LIVE via wc_get_product and returned as cards, in rank order. This is the core
		// "shop the look" happy path.
		$byId = [
			31 => $this->mockProduct( 31, 'Floral Summer Dress', '49.00' ),
			12 => $this->mockProduct( 12, 'Linen Wrap Dress', '79.00' ),
		];
		Functions\when( 'wc_get_product' )->alias( fn( $id ) => $byId[ (int) $id ] ?? false );

		$this->registerRetriever( fn( $value, $image, $filters ) => [ 31, 12 ] );

		$result = $this->visual()->search( $this->image() );

		$this->assertTrue( $result['available'] );
		$this->assertSame( 2, $result['found'] );
		// Order is the retriever's ranking, preserved.
		$this->assertSame( [ 31, 12 ], array_column( $result['products'], 'id' ) );
		$this->assertSame( 'Floral Summer Dress', $result['products'][0]['name'] );
	}

	public function test_retriever_receives_the_image_and_filters(): void {
		// The seam must hand the retriever the validated image descriptor and the structured
		// filters (category/price/limit) so a provider can pre-filter its vector scan, just
		// like the #60 text seam hands the query + filters.
		$seen = [];
		$byId = [ 7 => $this->mockProduct( 7, 'Denim Jacket', '60.00' ) ];
		Functions\when( 'wc_get_product' )->alias( fn( $id ) => $byId[ (int) $id ] ?? false );

		$this->registerRetriever( function ( $value, $image, $filters ) use ( &$seen ) {
			$seen = [ 'image' => $image, 'filters' => $filters ];
			return [ 7 ];
		} );

		$this->visual()->search( $this->image(), [
			'category'  => 'jackets',
			'min_price' => 20,
			'max_price' => 100,
			'limit'     => 3,
		] );

		$this->assertSame( 'image/jpeg', $seen['image']['type'] );
		$this->assertSame( 'jackets', $seen['filters']['category'] );
		$this->assertSame( 20.0, $seen['filters']['min_price'] );
		$this->assertSame( 100.0, $seen['filters']['max_price'] );
		$this->assertSame( 3, $seen['filters']['limit'] );
	}

	public function test_results_carry_live_price_and_stock_not_cached(): void {
		// The retriever returns only an id. Price and stock MUST come from the live
		// WC_Product resolved via wc_get_product at call time — never embedded/cached. We
		// prove it by making the live product report a specific price + OUT of stock; the
		// card must reflect the live values, not anything the seam stored.
		$live = $this->mockProduct( 50, 'Wool Coat', '199.99', /* inStock */ false );
		Functions\when( 'wc_get_product' )->justReturn( $live );

		$this->registerRetriever( fn( $value, $image, $filters ) => [ 50 ] );

		$result = $this->visual()->search( $this->image() );

		$card = $result['products'][0];
		$this->assertSame( 50, $card['id'] );
		$this->assertSame( '$199.99', $card['price'] ); // live wc_price of the live product
		$this->assertFalse( $card['in_stock'] );         // live stock, read now
		// The summary carries the canonical card keys so it renders as a card.
		foreach ( [ 'id', 'name', 'price', 'regular_price', 'sale_price', 'on_sale', 'in_stock', 'short_description', 'image', 'url' ] as $key ) {
			$this->assertArrayHasKey( $key, $card, "Summary missing key: {$key}" );
		}
	}

	public function test_ids_resolving_to_invisible_products_are_dropped(): void {
		// An id the retriever ranks may be unpublished/hidden by the time we resolve it (the
		// index can lag live truth). Such products are filtered out — never surfaced — and a
		// missing id (wc_get_product false) is skipped, not fatal. Mirrors the #60 invariant.
		$visible = $this->mockProduct( 1, 'Visible Boot', '70.00' );
		$hidden  = $this->mockProduct( 2, 'Hidden Boot', '70.00' );
		$hidden->shouldReceive( 'is_visible' )->andReturn( false );

		Functions\when( 'wc_get_product' )->alias( function ( $id ) use ( $visible, $hidden ) {
			return [ 1 => $visible, 2 => $hidden ][ (int) $id ] ?? false; // id 9 → false
		} );

		$this->registerRetriever( fn( $value, $image, $filters ) => [ 2, 1, 9 ] );

		$result = $this->visual()->search( $this->image() );

		$this->assertSame( 1, $result['found'] );
		$this->assertSame( [ 1 ], array_column( $result['products'], 'id' ) );
	}

	public function test_limit_bounds_the_resolved_results(): void {
		// A provider may over-return; the structured `limit` caps how many cards are
		// resolved/returned (a cost + payload ceiling), preserving rank order.
		$byId = [
			1 => $this->mockProduct( 1, 'Tee One', '20.00' ),
			2 => $this->mockProduct( 2, 'Tee Two', '20.00' ),
			3 => $this->mockProduct( 3, 'Tee Three', '20.00' ),
		];
		Functions\when( 'wc_get_product' )->alias( fn( $id ) => $byId[ (int) $id ] ?? false );

		$this->registerRetriever( fn( $value, $image, $filters ) => [ 1, 2, 3 ] );

		$result = $this->visual()->search( $this->image(), [ 'limit' => 2 ] );

		$this->assertSame( 2, $result['found'] );
		$this->assertSame( [ 1, 2 ], array_column( $result['products'], 'id' ) );
	}

	// ── upload validation: reject oversized / invalid / unsafe cleanly ──────────────

	public function test_oversized_image_is_rejected_before_retrieval(): void {
		// An image over the size ceiling is rejected with a clean WP_Error and the seam is
		// NEVER consulted — no retrieval, no resolution, no fatal. (Default ceiling is 5 MB.)
		Monkey\Filters\expectApplied( 'fahad_ai_visual_retriever' )->never();
		Functions\expect( 'wc_get_product' )->never();

		$result = $this->visual()->search( $this->image( [ 'size' => 6 * 1024 * 1024 ] ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 413, $result->data['status'] ?? 0 );
	}

	public function test_invalid_mime_type_is_rejected_before_retrieval(): void {
		// A non-image MIME (e.g. a disguised PDF/script) is rejected cleanly and the seam is
		// never consulted — the content-safety / unsafe-upload guard.
		Monkey\Filters\expectApplied( 'fahad_ai_visual_retriever' )->never();
		Functions\expect( 'wc_get_product' )->never();

		$result = $this->visual()->search( $this->image( [ 'type' => 'application/pdf', 'name' => 'invoice.pdf' ] ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 415, $result->data['status'] ?? 0 );
	}

	public function test_empty_or_missing_image_is_rejected(): void {
		// A request with no usable image reference (no tmp_name, no url, no data) is a clean
		// 400 — never a fatal on a missing key.
		Monkey\Filters\expectApplied( 'fahad_ai_visual_retriever' )->never();

		$result = $this->visual()->search( [] );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 400, $result->data['status'] ?? 0 );
	}

	public function test_zero_byte_image_is_rejected(): void {
		// A zero-byte upload (truncated / failed transfer) is rejected as a bad request,
		// never passed to a provider.
		Monkey\Filters\expectApplied( 'fahad_ai_visual_retriever' )->never();

		$result = $this->visual()->search( $this->image( [ 'size' => 0 ] ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 400, $result->data['status'] ?? 0 );
	}

	// ── no retention of the user image (default: do not store) ──────────────────────

	public function test_image_is_not_persisted_by_the_search_core(): void {
		// Privacy invariant: the search core never persists the uploaded image (default = do
		// not store). We pin this by asserting the WordPress upload-persistence functions are
		// never invoked while a full happy-path search runs — core hands the image to the
		// seam for the request only and stores nothing.
		Functions\expect( 'wp_handle_upload' )->never();
		Functions\expect( 'wp_insert_attachment' )->never();
		Functions\expect( 'media_handle_upload' )->never();
		Functions\expect( 'wp_upload_bits' )->never();

		$byId = [ 5 => $this->mockProduct( 5, 'Canvas Sneaker', '40.00' ) ];
		Functions\when( 'wc_get_product' )->alias( fn( $id ) => $byId[ (int) $id ] ?? false );
		$this->registerRetriever( fn( $value, $image, $filters ) => [ 5 ] );

		$result = $this->visual()->search( $this->image() );

		$this->assertTrue( $result['available'] );
		$this->assertSame( 5, $result['products'][0]['id'] );
	}

	// ── graceful degradation: no provider / no match ────────────────────────────────

	public function test_no_provider_returns_graceful_not_available(): void {
		// Default state: NO vision provider registered. A valid image must yield an honest
		// "visual search isn't available" result — available=false, found=0, a message — NOT
		// an error spew and NOT a fatal. wc_get_product is never reached.
		Functions\when( 'apply_filters' )->alias( fn( $hook, $value = null ) => $value ); // identity ⇒ null retriever
		Functions\expect( 'wc_get_product' )->never();

		$result = $this->visual()->search( $this->image() );

		$this->assertIsArray( $result );
		$this->assertFalse( $result['available'] );
		$this->assertSame( 0, $result['found'] );
		$this->assertSame( [], $result['products'] );
		$this->assertArrayHasKey( 'message', $result );
	}

	public function test_retriever_returning_nothing_is_graceful_no_match(): void {
		// A vision provider IS registered but finds no visually-similar product (empty index
		// / below threshold). The result is a graceful "no match": available=true, found=0,
		// a message — never an error.
		Functions\expect( 'wc_get_product' )->never();
		$this->registerRetriever( fn( $value, $image, $filters ) => [] );

		$result = $this->visual()->search( $this->image() );

		$this->assertTrue( $result['available'] );
		$this->assertSame( 0, $result['found'] );
		$this->assertSame( [], $result['products'] );
		$this->assertArrayHasKey( 'message', $result );
	}

	public function test_retriever_returning_the_null_passthrough_is_not_available(): void {
		// A provider that returns the passthrough untouched (null) is INDISTINGUISHABLE from
		// no provider at all — apply_filters returns null either way — so it correctly maps to
		// the graceful "not available" state, never a fatal. (A registered provider proves its
		// presence by returning an array, even an empty one.)
		Functions\expect( 'wc_get_product' )->never();
		$this->registerRetriever( fn( $value, $image, $filters ) => $value ); // returns null

		$result = $this->visual()->search( $this->image() );

		$this->assertFalse( $result['available'] );
		$this->assertSame( 0, $result['found'] );
		$this->assertSame( [], $result['products'] );
		$this->assertArrayHasKey( 'message', $result );
	}

	public function test_retriever_returning_a_scalar_is_treated_as_no_match(): void {
		// A misbehaving provider that returns a non-null scalar (it IS present, but produced
		// garbage) degrades to a graceful no-match — available stays true, found 0, no fatal.
		Functions\expect( 'wc_get_product' )->never();
		$this->registerRetriever( fn( $value, $image, $filters ) => 'not-an-array' );

		$result = $this->visual()->search( $this->image() );

		$this->assertTrue( $result['available'] );
		$this->assertSame( 0, $result['found'] );
		$this->assertSame( [], $result['products'] );
	}

	public function test_throwing_retriever_degrades_to_no_match(): void {
		// A callable retriever that throws is isolated — it degrades to a graceful no-match,
		// never propagating a fatal to the shopper (mirrors the #60 throwing-retriever case).
		Functions\expect( 'wc_get_product' )->never();
		$this->registerRetriever( fn( $value, $image, $filters ) => static function () {
			throw new \RuntimeException( 'vision backend down' );
		} );

		$result = $this->visual()->search( $this->image() );

		$this->assertTrue( $result['available'] );
		$this->assertSame( 0, $result['found'] );
		$this->assertSame( [], $result['products'] );
	}

	public function test_callable_retriever_shape_is_supported(): void {
		// Shape 2 (like #60): the provider may return a callable retriever resolved per call
		// — fn( array $image, array $filters ): int[]. The seam invokes it and resolves live.
		$byId = [ 9 => $this->mockProduct( 9, 'Striped Scarf', '25.00' ) ];
		Functions\when( 'wc_get_product' )->alias( fn( $id ) => $byId[ (int) $id ] ?? false );

		$this->registerRetriever( fn( $value, $image, $filters ) => fn( $img, $f ) => [ 9 ] );

		$result = $this->visual()->search( $this->image() );

		$this->assertTrue( $result['available'] );
		$this->assertSame( 1, $result['found'] );
		$this->assertSame( 9, $result['products'][0]['id'] );
	}

	public function test_all_invisible_results_collapse_to_no_match(): void {
		// Retriever ranked an id, but it resolves invisible → after dropping it there are no
		// cards, so the honest no-match state is returned (available stays true).
		$hidden = $this->mockProduct( 3, 'Discontinued Clog', '30.00' );
		$hidden->shouldReceive( 'is_visible' )->andReturn( false );
		Functions\when( 'wc_get_product' )->justReturn( $hidden );

		$this->registerRetriever( fn( $value, $image, $filters ) => [ 3 ] );

		$result = $this->visual()->search( $this->image() );

		$this->assertTrue( $result['available'] );
		$this->assertSame( 0, $result['found'] );
		$this->assertSame( [], $result['products'] );
		$this->assertArrayHasKey( 'message', $result );
	}

	// ── REST handler: gated, validates, shapes the response ─────────────────────────

	public function test_rest_handler_returns_cards_for_a_valid_upload(): void {
		// The REST handler reads the uploaded file (the $_FILES 'image' part), runs the same
		// search core, and returns a 200 response carrying the cards. No live vision call.
		$byId = [ 4 => $this->mockProduct( 4, 'Knit Beanie', '15.00' ) ];
		Functions\when( 'wc_get_product' )->alias( fn( $id ) => $byId[ (int) $id ] ?? false );
		Functions\when( 'rest_ensure_response' )->alias( fn( $d ) => new WP_REST_Response( $d, 200 ) );

		$this->registerRetriever( fn( $value, $image, $filters ) => [ 4 ] );

		$request = Mockery::mock( 'WP_REST_Request' );
		$request->shouldReceive( 'get_file_params' )->andReturn( [ 'image' => $this->image() ] );
		$request->shouldReceive( 'get_param' )->andReturn( null );

		$response = $this->visual()->handle_search( $request );

		$this->assertFalse( is_wp_error( $response ) );
		$data = $response->get_data();
		$this->assertTrue( $data['available'] );
		$this->assertSame( 4, $data['products'][0]['id'] );
	}

	public function test_rest_handler_rejects_oversized_upload_with_wp_error(): void {
		// An oversized upload through the REST surface returns the WP_Error (413) straight to
		// the client — the seam is never consulted.
		Monkey\Filters\expectApplied( 'fahad_ai_visual_retriever' )->never();

		$request = Mockery::mock( 'WP_REST_Request' );
		$request->shouldReceive( 'get_file_params' )->andReturn( [ 'image' => $this->image( [ 'size' => 6 * 1024 * 1024 ] ) ] );
		$request->shouldReceive( 'get_param' )->andReturn( null );

		$response = $this->visual()->handle_search( $request );

		$this->assertInstanceOf( WP_Error::class, $response );
		$this->assertSame( 413, $response->data['status'] ?? 0 );
	}

	public function test_rest_handler_passes_structured_filters_to_the_seam(): void {
		// The REST handler forwards optional category/price/limit params to the search core
		// so a provider can pre-filter its vector scan.
		$seen = [];
		$byId = [ 8 => $this->mockProduct( 8, 'Leather Belt', '25.00' ) ];
		Functions\when( 'wc_get_product' )->alias( fn( $id ) => $byId[ (int) $id ] ?? false );
		Functions\when( 'rest_ensure_response' )->alias( fn( $d ) => new WP_REST_Response( $d, 200 ) );

		$this->registerRetriever( function ( $value, $image, $filters ) use ( &$seen ) {
			$seen = $filters;
			return [ 8 ];
		} );

		$params  = [ 'category' => 'accessories', 'min_price' => '10', 'max_price' => '50', 'limit' => '4' ];
		$request = Mockery::mock( 'WP_REST_Request' );
		$request->shouldReceive( 'get_file_params' )->andReturn( [ 'image' => $this->image() ] );
		$request->shouldReceive( 'get_param' )->andReturnUsing( fn( $key ) => $params[ $key ] ?? null );

		$this->visual()->handle_search( $request );

		$this->assertSame( 'accessories', $seen['category'] );
		$this->assertSame( 10.0, $seen['min_price'] );
		$this->assertSame( 50.0, $seen['max_price'] );
		$this->assertSame( 4, $seen['limit'] );
	}
}
