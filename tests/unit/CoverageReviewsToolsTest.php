<?php
/**
 * Coverage companion for Fahad_AI_Reviews_Tools (issue #11: reviews & ratings).
 *
 * Sibling ReviewsToolsTest already exercises dispatch, the empty/error states and
 * the snippet-building path; this file pins down the remaining contract edges so
 * the pack's behaviour is fully asserted:
 *
 *   - the file-scope self-registration target (the pack class exists and its
 *     register() callback is a real, invokable provider that appends exactly the
 *     get_product_reviews tool — the exact contract the file-scope
 *     Fahad_AI_Tool_Registry::register_pack() call wires up at load),
 *   - register() is purely ADDITIVE: it appends to whatever tool list it is handed
 *     without dropping or reordering existing entries,
 *   - the get_product_reviews spec shape (integer params, the documented limit
 *     ceiling text) that the model relies on,
 *   - the snippet helper's defensive read paths: a non-array get_comments result,
 *     comment objects missing fields, the EXCERPT_WORDS truncation, MAX_LIMIT
 *     capping and the limit floor of 1.
 *
 * Conventions mirror the sibling: register the pack's REAL provider through
 * Fahad_AI_Tool_Registry::register_pack() (after snapshotting + clearing the
 * static pack list), then dispatch through the singleton, so the production
 * registration + merge + dispatch path is what is under test. WordPress/Woo
 * functions are stubbed with Brain\Monkey.
 */

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;

class CoverageReviewsToolsTest extends TestCase {

	use MockeryPHPUnitIntegration;

	/**
	 * Snapshot of the registry's static pack providers, restored in tearDown so a
	 * test here neither inherits another suite's packs nor leaks the reviews pack
	 * we register for our own cases.
	 *
	 * @var array<int, callable>
	 */
	private array $pack_snapshot = [];

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$this->pack_snapshot = (array) ( new ReflectionProperty( Fahad_AI_Tool_Registry::class, 'pack_providers' ) )->getValue();

		Functions\stubs( [
			'absint'              => fn( $n ) => abs( (int) $n ),
			'sanitize_text_field' => fn( $s ) => $s,
			'wp_json_encode'      => fn( $d ) => json_encode( $d ),
			'wp_strip_all_tags'   => fn( $s ) => strip_tags( (string) $s ),
			'get_option'          => fn( $key, $default = '' ) => $default,
			'wp_trim_words'       => function ( $text, $num = 55, $more = null ) {
				$words = preg_split( '/\s+/', trim( (string) $text ), -1, PREG_SPLIT_NO_EMPTY );
				if ( count( $words ) <= $num ) {
					return implode( ' ', $words );
				}
				return implode( ' ', array_slice( $words, 0, $num ) ) . ( $more ?? '…' );
			},
			'mysql2date'          => fn( $format, $date ) => $date,
		] );
	}

	protected function tearDown(): void {
		( new ReflectionProperty( Fahad_AI_Tool_Registry::class, 'pack_providers' ) )->setValue( null, $this->pack_snapshot );
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * Fresh registry whose built tool list includes the reviews tools — resets the
	 * Tools + registry singletons, clears the static pack list, then registers the
	 * reviews pack's REAL provider, exactly as the pack's file-scope
	 * self-registration does in production.
	 */
	private function registry(): Fahad_AI_Tool_Registry {
		( new ReflectionProperty( Fahad_AI_Tools::class, 'instance' ) )->setValue( null, null );
		( new ReflectionProperty( Fahad_AI_Tool_Registry::class, 'instance' ) )->setValue( null, null );

		Fahad_AI_Tool_Registry::reset_packs();
		Fahad_AI_Tool_Registry::register_pack( [ 'Fahad_AI_Reviews_Tools', 'register' ] );

		return Fahad_AI_Tool_Registry::instance();
	}

	/** A product mock that reports an average rating and review count. */
	private function mockProduct( int $id, float $avg, int $count, bool $visible = true ): WC_Product {
		$p = Mockery::mock( WC_Product::class );
		$p->shouldReceive( 'get_id' )->andReturn( $id );
		$p->shouldReceive( 'get_name' )->andReturn( 'Reviewed Product' );
		$p->shouldReceive( 'is_visible' )->andReturn( $visible );
		$p->shouldReceive( 'get_average_rating' )->andReturn( (string) $avg );
		$p->shouldReceive( 'get_review_count' )->andReturn( $count );
		return $p;
	}

	/** Build a WP_Comment-like review row (stdClass is enough for the tool). */
	private function review( int $id, string $author, string $content, string $date ): object {
		return (object) [
			'comment_ID'      => $id,
			'comment_author'  => $author,
			'comment_content' => $content,
			'comment_date'    => $date,
		];
	}

	// ── file-scope self-registration target ─────────────────────────────────────

	/**
	 * The pack file self-registers via Fahad_AI_Tool_Registry::register_pack() at
	 * file scope the moment it is require'd — the only wiring needed. The bootstrap
	 * glob-requires includes/tools/*.php, so the class is loaded and its register()
	 * method is a valid, callable pack provider. Asserting that proves the
	 * file-scope registration call references a real, invokable provider.
	 */
	public function test_pack_self_registration_references_a_callable_register(): void {
		$this->assertTrue( class_exists( 'Fahad_AI_Reviews_Tools' ) );
		$this->assertTrue( is_callable( [ 'Fahad_AI_Reviews_Tools', 'register' ] ) );

		// register() really appends the reviews tool onto a tool list — the exact
		// contract the file-scope register_pack() wires up.
		$tools = Fahad_AI_Reviews_Tools::register( [] );
		$names = array_column( $tools, 'name' );
		$this->assertSame( [ 'get_product_reviews' ], $names );
	}

	// ── register() provider contract ────────────────────────────────────────────

	public function test_register_is_additive_and_preserves_existing_tools(): void {
		// The provider must only ever APPEND; an existing list comes back intact
		// with the reviews tool tacked on the end (the registry layers providers,
		// so a pack that dropped entries would silently delete other tools).
		$existing = [
			[ 'name' => 'search_products' ],
			[ 'name' => 'add_to_cart' ],
		];

		$tools = Fahad_AI_Reviews_Tools::register( $existing );
		$names = array_column( $tools, 'name' );

		$this->assertSame( [ 'search_products', 'add_to_cart', 'get_product_reviews' ], $names );
	}

	public function test_register_appended_tool_carries_a_callable_callback(): void {
		// The appended entry must expose an invokable callback — that is what
		// dispatch() calls. Prove the callback is the get_product_reviews closure by
		// routing an invalid product through it and reading the error contract back.
		Functions\when( 'wc_get_product' )->justReturn( false );

		$tools    = Fahad_AI_Reviews_Tools::register( [] );
		$reviews  = $tools[0];
		$callback = $reviews['callback'];

		$this->assertIsCallable( $callback );

		$result = $callback( [ 'product_id' => 0 ] );
		$this->assertArrayHasKey( 'error', $result );
		$this->assertArrayNotHasKey( 'reviews', $result );
	}

	public function test_get_product_reviews_spec_declares_integer_params_and_limit_ceiling(): void {
		$specs = array_column( $this->registry()->specs(), null, 'name' );
		$spec  = $specs['get_product_reviews'];

		$props = $spec['parameters']['properties'];
		$this->assertSame( 'integer', $props['product_id']['type'] );
		$this->assertSame( 'integer', $props['limit']['type'] );
		// The documented ceiling (max 10) is what stops the model asking for an
		// unbounded snippet count.
		$this->assertStringContainsString( 'max 10', $props['limit']['description'] );
	}

	// ── recent_review_snippets defensive paths ──────────────────────────────────

	public function test_non_array_get_comments_result_yields_empty_reviews(): void {
		// get_comments() can return a non-array (e.g. a count when miscalled); the
		// helper must guard and return [] rather than iterate a scalar.
		$product = $this->mockProduct( 5, 4.0, 1 );
		Functions\when( 'wc_get_product' )->justReturn( $product );
		Functions\when( 'get_comments' )->justReturn( 7 );

		$result = $this->registry()->dispatch( 'get_product_reviews', [ 'product_id' => 5 ] );

		$this->assertArrayNotHasKey( 'error', $result );
		$this->assertSame( [], $result['reviews'] );
	}

	public function test_comment_missing_fields_degrades_to_empty_strings_and_zero_rating(): void {
		// A malformed comment row (no author/content/date, comment_ID absent) must
		// not fatal: the null-coalescing reads fall back to '' / 0 so the snippet
		// still surfaces with safe defaults.
		$product = $this->mockProduct( 5, 4.0, 1 );
		Functions\when( 'wc_get_product' )->justReturn( $product );
		Functions\when( 'get_comments' )->justReturn( [ (object) [] ] );

		// comment_ID falls back to 0; assert get_comment_meta is asked for 0 and
		// returns nothing, so the rating is the integer 0.
		Functions\when( 'get_comment_meta' )->alias( function ( $id, $key, $single = false ) {
			return 0 === $id ? '' : 'unexpected';
		} );

		$result = $this->registry()->dispatch( 'get_product_reviews', [ 'product_id' => 5 ] );

		$this->assertCount( 1, $result['reviews'] );
		$snippet = $result['reviews'][0];
		$this->assertSame( '', $snippet['author'] );
		$this->assertSame( 0, $snippet['rating'] );
		$this->assertSame( '', $snippet['excerpt'] );
		$this->assertSame( '', $snippet['date'] );
	}

	public function test_long_review_is_truncated_to_excerpt_word_budget(): void {
		// EXCERPT_WORDS keeps the payload small: a 60-word review comes back capped
		// to 40 words with the ellipsis appended, so the model never sees a wall of
		// text.
		$product = $this->mockProduct( 5, 5.0, 1 );
		Functions\when( 'wc_get_product' )->justReturn( $product );

		$long = implode( ' ', array_map( static fn( $i ) => "word{$i}", range( 1, 60 ) ) );
		Functions\when( 'get_comments' )->justReturn( [
			$this->review( 301, 'Dana', $long, '2026-03-01 00:00:00' ),
		] );
		Functions\when( 'get_comment_meta' )->justReturn( '4' );

		$result  = $this->registry()->dispatch( 'get_product_reviews', [ 'product_id' => 5 ] );
		$excerpt = $result['reviews'][0]['excerpt'];

		$this->assertStringEndsWith( '…', $excerpt );
		// 40 kept words + the ellipsis token; the 41st original word is gone.
		$kept = preg_split( '/\s+/', rtrim( $excerpt, '…' ), -1, PREG_SPLIT_NO_EMPTY );
		$this->assertCount( 40, $kept );
		$this->assertContains( 'word40', $kept );
		$this->assertNotContains( 'word41', $kept );
	}

	public function test_limit_is_capped_at_max_limit_of_ten(): void {
		// A caller asking for 25 snippets is clamped to MAX_LIMIT (10): the bounded
		// number is what is passed to get_comments so the DB query stays small.
		$product = $this->mockProduct( 5, 4.2, 99 );
		Functions\when( 'wc_get_product' )->justReturn( $product );

		Functions\expect( 'get_comments' )
			->once()
			->andReturnUsing( function ( array $args ): array {
				$this->assertSame( 10, $args['number'] );
				return [];
			} );

		$this->registry()->dispatch( 'get_product_reviews', [ 'product_id' => 5, 'limit' => 25 ] );
	}

	public function test_limit_floor_is_one_for_zero_or_negative_request(): void {
		// max( 1, ... ) floors the limit: a 0 or negative request still pulls at
		// least one snippet rather than an empty/invalid number.
		$product = $this->mockProduct( 5, 4.2, 5 );
		Functions\when( 'wc_get_product' )->justReturn( $product );

		Functions\expect( 'get_comments' )
			->once()
			->andReturnUsing( function ( array $args ): array {
				$this->assertSame( 1, $args['number'] );
				return [];
			} );

		$this->registry()->dispatch( 'get_product_reviews', [ 'product_id' => 5, 'limit' => -4 ] );
	}

	public function test_orders_query_newest_first(): void {
		// Recent snippets means newest-first: the query must sort by GMT date DESC
		// so the customer sees the most current reviews.
		$product = $this->mockProduct( 5, 4.0, 3 );
		Functions\when( 'wc_get_product' )->justReturn( $product );

		Functions\expect( 'get_comments' )
			->once()
			->andReturnUsing( function ( array $args ): array {
				$this->assertSame( 'comment_date_gmt', $args['orderby'] );
				$this->assertSame( 'DESC', $args['order'] );
				return [];
			} );

		$this->registry()->dispatch( 'get_product_reviews', [ 'product_id' => 5 ] );
	}
}
