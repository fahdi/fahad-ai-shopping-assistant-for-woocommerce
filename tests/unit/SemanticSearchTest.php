<?php
/**
 * Unit tests for the semantic-search retriever seam (issue #60).
 *
 * Red → Green → Refactor cycle. Conventions mirror ToolsTest / CatalogToolsTest:
 * WP/WC functions mocked via Brain\Monkey; WC objects via Mockery; the Tools
 * singleton reset via reflection. NO setAccessible on private methods, every
 * assertion drives the public search_products tool (via Fahad_AI_Tools::execute)
 * so the production seam → fall-back path is what is under test.
 *
 * The seam: search_products consults a pluggable retriever registered on the
 * `fahad_ai_semantic_retriever` filter BEFORE the keyword search. A retriever is
 * given the query (+ filters) and returns ranked product IDs; those IDs are
 * resolved LIVE via wc_get_product() so price/stock are never cached, they are
 * read from the live WC_Product at call time by format_product_summary(). With no
 * retriever registered (default), or one that returns nothing, search_products
 * falls back to the existing keyword search (+ relaxation) unchanged.
 *
 * Real semantic ranking requires an external embeddings provider to register a
 * retriever on the filter; none is wired here, so these tests use a STUB
 * retriever to prove the seam, exactly as WalletToolsTest stubs a wallet provider.
 */

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;

class SemanticSearchTest extends TestCase {

	use MockeryPHPUnitIntegration;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		// Same tool-layer stubs ToolsTest uses so the shared formatter runs against
		// mocked products. get_option defaults so the registry's tool-gating (issue
		// #56) is a no-op; apply_filters/remove_all_filters back the retriever seam.
		Functions\stubs( [
			'absint'              => fn( $n ) => abs( (int) $n ),
			'sanitize_text_field' => fn( $s ) => is_string( $s ) ? trim( $s ) : $s,
			'get_option'          => fn( $key, $default = '' ) => $default,
			'wp_json_encode'      => fn( $d ) => json_encode( $d ),
			'wc_price'            => fn( $p ) => '$' . $p,
			'wp_strip_all_tags'   => fn( $s ) => strip_tags( (string) $s ),
			'wp_get_attachment_image_url' => fn() => '',
			'wc_placeholder_img_src'      => fn() => 'http://example.com/placeholder.png',
			'get_permalink'       => fn( $id ) => 'http://example.com/?p=' . $id,
			'wc_get_cart_url'     => fn() => 'http://example.com/cart',
			'wc_get_checkout_url' => fn() => 'http://example.com/checkout',
			'wp_list_pluck'       => fn( $list, $field ) => array_column( (array) $list, $field ),
			'get_the_terms'       => fn() => [],
			'get_terms'           => fn() => [],
		] );
	}

	protected function tearDown(): void {
		// Drop any retriever a test registered so it never leaks into the next case.
		Monkey\tearDown();
		parent::tearDown();
	}

	private function tools(): Fahad_AI_Tools {
		( new ReflectionProperty( Fahad_AI_Tools::class, 'instance' ) )->setValue( null, null );
		return Fahad_AI_Tools::instance();
	}

	/**
	 * Register a stub semantic retriever on the seam filter for one test. The
	 * retriever receives ( $passthrough, $query, $filters ) and returns ranked
	 * product IDs, exactly the contract a real embeddings provider implements.
	 *
	 * @param callable $retriever fn( $value, string $query, array $filters ): mixed
	 */
	private function registerRetriever( callable $retriever ): void {
		Monkey\Filters\expectApplied( 'fahad_ai_semantic_retriever' )
			->andReturnUsing( $retriever );
	}

	/**
	 * Happy-path product mock (mirrors ToolsTest::mockProduct). price/stock are
	 * supplied per-call so a test can assert they come from the LIVE product the
	 * id resolved to, not from anything the retriever returned.
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

	/**
	 * Alias wc_get_products() to a WooCommerce-like AND-substring search over a
	 * small { id => name } catalog (mirrors ToolsTest::aliasCatalogSearch) so the
	 * keyword fall-back can be tested deterministically.
	 */
	private function aliasCatalogSearch( array $catalog ): void {
		$self = $this;
		Functions\when( 'wc_get_products' )->alias( function ( array $args ) use ( $catalog, $self ): array {
			$s   = isset( $args['s'] ) ? trim( (string) $args['s'] ) : '';
			$out = [];
			foreach ( $catalog as $id => $name ) {
				$match = true;
				if ( '' !== $s ) {
					foreach ( preg_split( '/\s+/', strtolower( $s ) ) as $word ) {
						if ( '' !== $word && false === strpos( strtolower( $name ), $word ) ) {
							$match = false;
							break;
						}
					}
				}
				if ( $match ) {
					$out[] = $self->mockProduct( (int) $id, $name, '45' );
				}
			}
			return $out;
		} );
	}

	// ── seam present: retriever drives the results ──────────────────────────────

	public function test_registered_retriever_ranked_ids_drive_the_results(): void {
		// "shoes for flat feet", the keyword search would miss it; the retriever
		// returns the semantically-relevant ids, in rank order. search_products must
		// resolve those ids (live) and return them in that order, NOT run keyword.
		$byId = [
			31 => $this->mockProduct( 31, 'Arch Support Trainer', '89.00' ),
			12 => $this->mockProduct( 12, 'Orthopedic Walking Shoe', '120.00' ),
		];
		Functions\when( 'wc_get_product' )->alias( fn( $id ) => $byId[ (int) $id ] ?? false );

		// wc_get_products must NOT be hit when the retriever satisfies the query.
		Functions\expect( 'wc_get_products' )->never();

		$this->registerRetriever( fn( $value, $query, $filters ) => [ 31, 12 ] );

		$result = $this->tools()->execute( 'search_products', [ 'query' => 'shoes for flat feet' ] );

		$this->assertSame( 2, $result['found'] );
		// Order is the retriever's ranking, preserved.
		$this->assertSame( [ 31, 12 ], array_column( $result['products'], 'id' ) );
		$this->assertSame( 'Arch Support Trainer', $result['products'][0]['name'] );
	}

	public function test_retriever_receives_the_query_and_filters(): void {
		// The seam must hand the retriever the sanitized query and the structured
		// filters (category/price/limit) so a provider can pre-filter its vector scan.
		$seen   = [];
		$byId   = [ 7 => $this->mockProduct( 7, 'Rain Jacket', '60.00' ) ];
		Functions\when( 'wc_get_product' )->alias( fn( $id ) => $byId[ (int) $id ] ?? false );
		Functions\when( 'wc_get_products' )->justReturn( [] );

		$this->registerRetriever( function ( $value, $query, $filters ) use ( &$seen ) {
			$seen = [ 'query' => $query, 'filters' => $filters ];
			return [ 7 ];
		} );

		$this->tools()->execute( 'search_products', [
			'query'     => 'something for a rainy hike',
			'category'  => 'jackets',
			'min_price' => 20,
			'max_price' => 100,
			'limit'     => 3,
		] );

		$this->assertSame( 'something for a rainy hike', $seen['query'] );
		$this->assertSame( 'jackets', $seen['filters']['category'] );
		$this->assertSame( 20.0, $seen['filters']['min_price'] );
		$this->assertSame( 100.0, $seen['filters']['max_price'] );
		$this->assertSame( 3, $seen['filters']['limit'] );
	}

	public function test_retriever_results_carry_live_price_and_stock_not_cached(): void {
		// The retriever returns only an id. Price and stock MUST come from the live
		// WC_Product resolved via wc_get_product at call time, never embedded/cached.
		// We prove this by making the live product report a specific price + OUT of
		// stock; the card must reflect the live values, not anything the seam stored.
		$live = $this->mockProduct( 50, 'Wool Coat', '199.99', /* inStock */ false );
		Functions\when( 'wc_get_product' )->justReturn( $live );
		Functions\when( 'wc_get_products' )->justReturn( [] );

		$this->registerRetriever( fn( $value, $query, $filters ) => [ 50 ] );

		$result = $this->tools()->execute( 'search_products', [ 'query' => 'warm for winter' ] );

		$card = $result['products'][0];
		$this->assertSame( 50, $card['id'] );
		$this->assertSame( '$199.99', $card['price'] ); // live wc_price of the live product
		$this->assertFalse( $card['in_stock'] );        // live stock, read now
		// The summary carries the canonical card keys so it renders as a card.
		foreach ( [ 'id', 'name', 'price', 'regular_price', 'sale_price', 'on_sale', 'in_stock', 'short_description', 'image', 'url' ] as $key ) {
			$this->assertArrayHasKey( $key, $card, "Summary missing key: {$key}" );
		}
	}

	public function test_retriever_ids_resolving_to_invisible_products_are_dropped(): void {
		// An id the retriever ranks may be unpublished/hidden by the time we resolve
		// it (the index can lag live truth). Such products are filtered out, never
		// surfaced, and a missing id (wc_get_product false) is skipped, not fatal.
		$visible = $this->mockProduct( 1, 'Visible Boot', '70.00' );
		$hidden  = $this->mockProduct( 2, 'Hidden Boot', '70.00' );
		$hidden->shouldReceive( 'is_visible' )->andReturn( false );

		Functions\when( 'wc_get_product' )->alias( function ( $id ) use ( $visible, $hidden ) {
			return [ 1 => $visible, 2 => $hidden ][ (int) $id ] ?? false; // id 9 → false
		} );
		Functions\when( 'wc_get_products' )->justReturn( [] );

		$this->registerRetriever( fn( $value, $query, $filters ) => [ 2, 1, 9 ] );

		$result = $this->tools()->execute( 'search_products', [ 'query' => 'boots' ] );

		$this->assertSame( 1, $result['found'] );
		$this->assertSame( [ 1 ], array_column( $result['products'], 'id' ) );
	}

	// ── seam absent / empty: graceful fall-back to keyword ──────────────────────

	public function test_no_retriever_falls_back_to_keyword_search_unchanged(): void {
		// Default state: no provider registered. search_products must behave exactly
		// as today, the keyword search drives the results.
		$product = $this->mockProduct( 1, 'Blue Jeans', '59.99' );
		Functions\when( 'wc_get_products' )->justReturn( [ $product ] );

		$result = $this->tools()->execute( 'search_products', [ 'query' => 'jeans' ] );

		$this->assertSame( 1, $result['found'] );
		$this->assertSame( 'Blue Jeans', $result['products'][0]['name'] );
	}

	public function test_no_retriever_still_relaxes_plural_query(): void {
		// The existing relaxation (plurals/adjectives) must remain intact when no
		// retriever is registered, the seam adds a layer, it does not replace this.
		$this->aliasCatalogSearch( [ 38 => 'Premium Pullover Hoodie', 14 => 'Running Sneakers' ] );

		$result = $this->tools()->execute( 'search_products', [ 'query' => 'hoodies' ] );

		$this->assertSame( 1, $result['found'] );
		$this->assertSame( 38, $result['products'][0]['id'] );
	}

	public function test_retriever_returning_nothing_falls_back_to_keyword(): void {
		// A retriever is registered but finds no semantic match (empty index / below
		// threshold). search_products must fall back to keyword, not return empty.
		$product = $this->mockProduct( 5, 'Canvas Sneaker', '40.00' );
		Functions\when( 'wc_get_products' )->justReturn( [ $product ] );
		// No id resolves (the seam returns nothing, so resolution never runs).
		Functions\when( 'wc_get_product' )->justReturn( false );

		$this->registerRetriever( fn( $value, $query, $filters ) => [] );

		$result = $this->tools()->execute( 'search_products', [ 'query' => 'sneaker' ] );

		$this->assertSame( 1, $result['found'] );
		$this->assertSame( 5, $result['products'][0]['id'] );
	}

	public function test_retriever_returning_non_array_falls_back_to_keyword(): void {
		// A misbehaving provider (returns the passthrough value untouched, or a
		// scalar) must degrade gracefully to keyword, never fatal or empty.
		$product = $this->mockProduct( 8, 'Leather Belt', '25.00' );
		Functions\when( 'wc_get_products' )->justReturn( [ $product ] );

		$this->registerRetriever( fn( $value, $query, $filters ) => $value ); // returns null

		$result = $this->tools()->execute( 'search_products', [ 'query' => 'belt' ] );

		$this->assertSame( 1, $result['found'] );
		$this->assertSame( 8, $result['products'][0]['id'] );
	}

	public function test_retriever_all_invisible_then_empty_keyword_returns_empty_state(): void {
		// Retriever ranked an id, but it resolves invisible AND keyword finds nothing
		// → the honest empty state (found 0), so the model abstains rather than guess.
		$hidden = $this->mockProduct( 3, 'Discontinued Clog', '30.00' );
		$hidden->shouldReceive( 'is_visible' )->andReturn( false );
		Functions\when( 'wc_get_product' )->justReturn( $hidden );
		Functions\when( 'wc_get_products' )->justReturn( [] );

		$this->registerRetriever( fn( $value, $query, $filters ) => [ 3 ] );

		$result = $this->tools()->execute( 'search_products', [ 'query' => 'clogs' ] );

		$this->assertSame( 0, $result['found'] );
		$this->assertSame( [], $result['products'] );
		$this->assertArrayHasKey( 'message', $result );
	}

	public function test_empty_query_with_filters_does_not_invoke_retriever(): void {
		// A pure category/price browse (no free-text query) has no semantic intent to
		// embed, the seam is skipped and the keyword/filter path runs as before.
		Monkey\Filters\expectApplied( 'fahad_ai_semantic_retriever' )->never(); // seam not consulted
		$product = $this->mockProduct( 21, 'Filtered Item', '15.00' );
		Functions\when( 'wc_get_products' )->justReturn( [ $product ] );

		$result = $this->tools()->execute( 'search_products', [ 'category' => 'sale', 'max_price' => 50 ] );

		$this->assertSame( 1, $result['found'] );
		$this->assertSame( 21, $result['products'][0]['id'] );
	}
}
