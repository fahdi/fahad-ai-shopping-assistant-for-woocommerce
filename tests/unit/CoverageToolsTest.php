<?php
/**
 * Supplemental line-coverage tests for Fahad_AI_Tools.
 *
 * Targets the branches ToolsTest.php does not reach: the OR token_search
 * fallback (scoring/usort/slice), the build_variations skip when a variation
 * id no longer resolves, the variation_label "Any" skip, the
 * remove_from_cart hard-failure return, and plain_price's empty/null guard.
 *
 * Conventions mirror ToolsTest.php exactly: Brain\Monkey for WP/WC functions,
 * Mockery for WC objects, the singleton reset via ReflectionProperty.
 */

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;

class CoverageToolsTest extends TestCase {

	use MockeryPHPUnitIntegration;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		Functions\stubs( [
			'absint'                      => fn( $n ) => abs( (int) $n ),
			'sanitize_text_field'         => fn( $s ) => $s,
			'get_option'                  => fn( $key, $default = '' ) => $default,
			'wp_json_encode'              => fn( $d ) => json_encode( $d ),
			'wc_price'                    => fn( $p ) => '$' . $p,
			'wp_strip_all_tags'           => fn( $s ) => strip_tags( (string) $s ),
			'wp_get_attachment_image_url' => fn() => '',
			'wc_placeholder_img_src'      => fn() => 'http://example.com/placeholder.png',
			'get_permalink'               => fn( $id ) => 'http://example.com/?p=' . $id,
			'wc_get_cart_url'             => fn() => 'http://example.com/cart',
			'wc_get_checkout_url'         => fn() => 'http://example.com/checkout',
			'wp_list_pluck'               => fn( $list, $field ) => array_column( (array) $list, $field ),
			'get_the_terms'               => fn() => [],
		] );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	private function tools(): Fahad_AI_Tools {
		$ref = new ReflectionProperty( Fahad_AI_Tools::class, 'instance' );
		$ref->setValue( null, null );
		return Fahad_AI_Tools::instance();
	}

	/** Minimal product mock, only what format_product_summary reads. */
	private function mockProduct( int $id, string $name, string $price = '45' ): WC_Product {
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
		$p->shouldReceive( 'get_average_rating' )->andReturn( '0' )->byDefault();
		$p->shouldReceive( 'get_review_count' )->andReturn( 0 )->byDefault();
		return $p;
	}

	// ── semantic retrieval seam: short-circuits keyword search (lines 149-152) ──

	/**
	 * When a semantic retriever is registered and returns ranked IDs, search_products
	 * resolves them LIVE and returns them WITHOUT touching the keyword path. Passing
	 * category + price filters also drives semantic_filters() to project category
	 * (123/214) and the price bounds (217/220) into the retriever's constraints.
	 */
	public function test_search_uses_semantic_retriever_and_skips_keyword_path(): void {
		$captured = null;

		// The retriever filter returns ranked IDs; capture the filters it receives so
		// we can assert semantic_filters() projected category + price through.
		Functions\when( 'apply_filters' )->alias( function ( $hook, $value, ...$args ) use ( &$captured ) {
			if ( 'fahad_ai_semantic_retriever' === $hook ) {
				$captured = $args[1] ?? []; // [ $query, $filters ]
				return [ 88 ];              // ranked product IDs.
			}
			return $value;
		} );

		$product = $this->mockProduct( 88, 'Recommended Shoe', '70' );
		Functions\when( 'wc_get_product' )->justReturn( $product );

		// Keyword search must never run when the semantic leg yields results.
		Functions\expect( 'wc_get_products' )->never();

		$result = $this->tools()->execute( 'search_products', [
			'query'     => 'shoes for flat feet',
			'category'  => 'footwear',
			'min_price' => 20,
			'max_price' => 120,
		] );

		$this->assertSame( 1, $result['found'] );
		$this->assertSame( 88, $result['products'][0]['id'] );

		// semantic_filters projected category + price bounds (and limit) only.
		$this->assertSame( 'footwear', $captured['category'] );
		$this->assertSame( 20.0, $captured['min_price'] );
		$this->assertSame( 120.0, $captured['max_price'] );
		$this->assertArrayHasKey( 'limit', $captured );
		$this->assertArrayNotHasKey( 'status', $captured );
	}

	// ── token_search: OR fallback when exact + relaxed both miss ────────────────

	/**
	 * "sneakers jacket" matches no single product under AND search (no name holds
	 * both stems), and the relaxed AND query ("sneaker jacket") misses too, so the
	 * OR token_search runs: it queries each stem separately, scores by hit count,
	 * sorts, and returns the slice. This drives lines 277-294.
	 */
	public function test_token_search_or_fallback_surfaces_partial_matches(): void {
		// Catalog: one product per stem, neither containing both stems.
		$catalog = [ 14 => 'Running Sneakers', 22 => 'Denim Jacket' ];

		Functions\when( 'wc_get_products' )->alias( function ( array $args ) use ( $catalog ): array {
			$s = isset( $args['s'] ) ? trim( strtolower( (string) $args['s'] ) ) : '';
			if ( '' === $s ) {
				return [];
			}
			$words = preg_split( '/\s+/', $s, -1, PREG_SPLIT_NO_EMPTY );
			$out   = [];
			foreach ( $catalog as $id => $name ) {
				$match = true;
				foreach ( $words as $word ) {
					if ( false === strpos( strtolower( $name ), $word ) ) {
						$match = false;
						break;
					}
				}
				if ( $match ) {
					$out[] = $this->mockProduct( (int) $id, $name );
				}
			}
			return $out;
		} );

		$result = $this->tools()->execute( 'search_products', [ 'query' => 'sneakers jacket' ] );

		// Both stems each surface exactly one product; the OR fallback returns both.
		$this->assertSame( 2, $result['found'] );
		$ids = array_column( $result['products'], 'id' );
		sort( $ids );
		$this->assertSame( [ 14, 22 ], $ids );
	}

	/**
	 * When a single product matches multiple stems it must score higher and sort
	 * ahead of a product matching only one, exercising the usort comparator (289)
	 * and the scored-entry increment (281).
	 */
	public function test_token_search_ranks_products_by_term_hit_count(): void {
		// "Sneaker Jacket Combo" holds both stems; "Plain Jacket" holds only one.
		$catalog = [ 30 => 'Sneaker Jacket Combo', 31 => 'Plain Jacket' ];

		Functions\when( 'wc_get_products' )->alias( function ( array $args ) use ( $catalog ): array {
			$s = isset( $args['s'] ) ? trim( strtolower( (string) $args['s'] ) ) : '';
			// Force exact + relaxed (multi-word) to miss so token_search runs.
			if ( '' === $s || false !== strpos( $s, ' ' ) ) {
				return [];
			}
			$out = [];
			foreach ( $catalog as $id => $name ) {
				if ( false !== strpos( strtolower( $name ), $s ) ) {
					$out[] = $this->mockProduct( (int) $id, $name );
				}
			}
			return $out;
		} );

		$result = $this->tools()->execute( 'search_products', [ 'query' => 'sneakers jacket' ] );

		$this->assertSame( 2, $result['found'] );
		// Product 30 matched both stems (score 2) -> ranked first.
		$this->assertSame( 30, $result['products'][0]['id'] );
		$this->assertSame( 31, $result['products'][1]['id'] );
	}

	/**
	 * token_search honours the search limit when slicing the scored set (line 293).
	 */
	public function test_token_search_respects_limit_when_slicing(): void {
		$catalog = [ 40 => 'Sneaker A', 41 => 'Jacket B' ];

		Functions\when( 'wc_get_products' )->alias( function ( array $args ) use ( $catalog ): array {
			$s = isset( $args['s'] ) ? trim( strtolower( (string) $args['s'] ) ) : '';
			if ( '' === $s || false !== strpos( $s, ' ' ) ) {
				return [];
			}
			$out = [];
			foreach ( $catalog as $id => $name ) {
				if ( false !== strpos( strtolower( $name ), $s ) ) {
					$out[] = $this->mockProduct( (int) $id, $name );
				}
			}
			return $out;
		} );

		$result = $this->tools()->execute( 'search_products', [ 'query' => 'sneakers jacket', 'limit' => 1 ] );

		// Two stems each match one product, but limit caps the result at one.
		$this->assertSame( 1, $result['found'] );
		$this->assertCount( 1, $result['products'] );
	}

	/**
	 * An all-stopword query yields no significant stems: relaxation produces an
	 * empty string (skipped), token_search runs but search_terms() returns [], so
	 * it short-circuits with an empty array (line 271). Result is the no-match
	 * message, never an over-broad dump.
	 */
	public function test_token_search_returns_empty_for_all_stopword_query(): void {
		// Exact query never matches; token_search bails before any product query.
		Functions\expect( 'wc_get_products' )->once()->andReturn( [] );

		$result = $this->tools()->execute( 'search_products', [ 'query' => 'show me some' ] );

		$this->assertSame( 0, $result['found'] );
		$this->assertEmpty( $result['products'] );
		$this->assertArrayHasKey( 'message', $result );
	}

	/**
	 * token_search can find no products for any stem (every per-term query returns
	 * []), hitting the `empty( $scored )` guard (line 286) and returning [].
	 */
	public function test_token_search_returns_empty_when_no_term_matches(): void {
		// Every query (exact, relaxed, per-stem) returns nothing.
		Functions\when( 'wc_get_products' )->justReturn( [] );

		$result = $this->tools()->execute( 'search_products', [ 'query' => 'sneakers jacket' ] );

		$this->assertSame( 0, $result['found'] );
		$this->assertEmpty( $result['products'] );
	}

	// ── build_variations: skip an unresolved variation (line 353) ───────────────

	/**
	 * A variation id in get_available_variations() that no longer resolves through
	 * wc_get_product() (deleted/hidden) must be skipped, not surfaced, exercising
	 * the `if ( ! $v ) continue;` guard. A second, resolvable variation confirms the
	 * loop continues past the skip.
	 */
	public function test_build_variations_skips_unresolvable_variation(): void {
		Functions\when( 'wc_attribute_label' )->alias( fn( $name ) => ucfirst( $name ) );
		Functions\when( 'taxonomy_exists' )->justReturn( false );

		$parent = Mockery::mock( WC_Product::class );
		$parent->shouldReceive( 'get_id' )->andReturn( 5 );
		$parent->shouldReceive( 'get_name' )->andReturn( 'Cotton Tee' );
		$parent->shouldReceive( 'get_price' )->andReturn( '20' );
		$parent->shouldReceive( 'get_regular_price' )->andReturn( '20' );
		$parent->shouldReceive( 'get_sale_price' )->andReturn( '' );
		$parent->shouldReceive( 'is_on_sale' )->andReturn( false );
		$parent->shouldReceive( 'is_visible' )->andReturn( true );
		$parent->shouldReceive( 'is_in_stock' )->andReturn( true );
		$parent->shouldReceive( 'get_description' )->andReturn( '' );
		$parent->shouldReceive( 'get_short_description' )->andReturn( '' );
		$parent->shouldReceive( 'get_sku' )->andReturn( '' );
		$parent->shouldReceive( 'get_stock_quantity' )->andReturn( 10 );
		$parent->shouldReceive( 'get_type' )->andReturn( 'variable' );
		$parent->shouldReceive( 'is_type' )->with( 'variable' )->andReturn( true );
		$parent->shouldReceive( 'get_image_id' )->andReturn( 0 );
		$parent->shouldReceive( 'get_average_rating' )->andReturn( '0' );
		$parent->shouldReceive( 'get_review_count' )->andReturn( 0 );
		$parent->shouldReceive( 'get_available_variations' )->andReturn( [
			[ 'variation_id' => 51, 'attributes' => [ 'attribute_size' => 'M' ] ],
			[ 'variation_id' => 99, 'attributes' => [ 'attribute_size' => 'L' ] ], // gone.
		] );

		$liveVar = Mockery::mock( WC_Product::class );
		$liveVar->shouldReceive( 'get_id' )->andReturn( 51 );
		$liveVar->shouldReceive( 'get_price' )->andReturn( '25' );
		$liveVar->shouldReceive( 'get_regular_price' )->andReturn( '25' );
		$liveVar->shouldReceive( 'get_sale_price' )->andReturn( '' );
		$liveVar->shouldReceive( 'is_on_sale' )->andReturn( false );
		$liveVar->shouldReceive( 'is_in_stock' )->andReturn( true );

		Functions\when( 'wc_get_product' )->alias(
			fn( $id ) => 5 === (int) $id ? $parent : ( 51 === (int) $id ? $liveVar : false )
		);

		$result = $this->tools()->execute( 'get_product_details', [ 'product_id' => 5 ] );

		// Only the resolvable variation survives; the deleted one (99) is dropped.
		$this->assertCount( 1, $result['variations'] );
		$this->assertSame( 51, $result['variations'][0]['variation_id'] );
	}

	// ── variation_label: skip an "Any" (empty) attribute value (line 391) ───────

	/**
	 * A variation that leaves one attribute as "Any" (empty value) must omit that
	 * attribute from the label while still listing the specified one, driving the
	 * `if ( '' === $value ) continue;` skip.
	 */
	public function test_variation_label_skips_empty_any_attribute(): void {
		Functions\when( 'wc_attribute_label' )->alias( fn( $name ) => ucfirst( $name ) );
		Functions\when( 'taxonomy_exists' )->justReturn( false );

		$parent = Mockery::mock( WC_Product::class );
		$parent->shouldReceive( 'get_id' )->andReturn( 6 );
		$parent->shouldReceive( 'get_name' )->andReturn( 'Mug' );
		$parent->shouldReceive( 'get_price' )->andReturn( '8' );
		$parent->shouldReceive( 'get_regular_price' )->andReturn( '8' );
		$parent->shouldReceive( 'get_sale_price' )->andReturn( '' );
		$parent->shouldReceive( 'is_on_sale' )->andReturn( false );
		$parent->shouldReceive( 'is_visible' )->andReturn( true );
		$parent->shouldReceive( 'is_in_stock' )->andReturn( true );
		$parent->shouldReceive( 'get_description' )->andReturn( '' );
		$parent->shouldReceive( 'get_short_description' )->andReturn( '' );
		$parent->shouldReceive( 'get_sku' )->andReturn( '' );
		$parent->shouldReceive( 'get_stock_quantity' )->andReturn( 10 );
		$parent->shouldReceive( 'get_type' )->andReturn( 'variable' );
		$parent->shouldReceive( 'is_type' )->with( 'variable' )->andReturn( true );
		$parent->shouldReceive( 'get_image_id' )->andReturn( 0 );
		$parent->shouldReceive( 'get_average_rating' )->andReturn( '0' );
		$parent->shouldReceive( 'get_review_count' )->andReturn( 0 );
		$parent->shouldReceive( 'get_available_variations' )->andReturn( [
			[
				'variation_id' => 61,
				// 'color' is "Any" (empty); only 'finish' should appear in the label.
				'attributes'   => [ 'attribute_color' => '', 'attribute_finish' => 'Matte' ],
			],
		] );

		$variation = Mockery::mock( WC_Product::class );
		$variation->shouldReceive( 'get_id' )->andReturn( 61 );
		$variation->shouldReceive( 'get_price' )->andReturn( '8' );
		$variation->shouldReceive( 'get_regular_price' )->andReturn( '8' );
		$variation->shouldReceive( 'get_sale_price' )->andReturn( '' );
		$variation->shouldReceive( 'is_on_sale' )->andReturn( false );
		$variation->shouldReceive( 'is_in_stock' )->andReturn( true );

		Functions\when( 'wc_get_product' )->alias(
			fn( $id ) => 6 === (int) $id ? $parent : ( 61 === (int) $id ? $variation : false )
		);

		$result = $this->tools()->execute( 'get_product_details', [ 'product_id' => 6 ] );

		// The "Any" color is dropped; only the specified finish is labelled.
		$this->assertSame( 'Finish: Matte', $result['variations'][0]['label'] );
	}

	// ── get_product_details: on-sale discount_percent wiring (issue #257) ───────

	/**
	 * A simple product that is on sale must surface a grounded discount_percent computed
	 * from its real regular vs sale price, exercising the on-sale true-branch of the
	 * details wiring that every other details test (all is_on_sale=false) leaves uncovered.
	 */
	public function test_product_details_surfaces_discount_percent_when_on_sale(): void {
		$product = Mockery::mock( WC_Product::class );
		$product->shouldReceive( 'get_id' )->andReturn( 8 );
		$product->shouldReceive( 'get_name' )->andReturn( 'Winter Jacket' );
		$product->shouldReceive( 'get_price' )->andReturn( '70' );
		$product->shouldReceive( 'get_regular_price' )->andReturn( '100' );
		$product->shouldReceive( 'get_sale_price' )->andReturn( '70' );
		$product->shouldReceive( 'is_on_sale' )->andReturn( true );
		$product->shouldReceive( 'is_visible' )->andReturn( true );
		$product->shouldReceive( 'is_in_stock' )->andReturn( true );
		$product->shouldReceive( 'get_description' )->andReturn( '' );
		$product->shouldReceive( 'get_short_description' )->andReturn( '' );
		$product->shouldReceive( 'get_sku' )->andReturn( '' );
		$product->shouldReceive( 'get_stock_quantity' )->andReturn( 5 );
		$product->shouldReceive( 'get_type' )->andReturn( 'simple' );
		$product->shouldReceive( 'is_type' )->with( 'variable' )->andReturn( false );
		$product->shouldReceive( 'get_image_id' )->andReturn( 0 );
		$product->shouldReceive( 'get_average_rating' )->andReturn( '0' );
		$product->shouldReceive( 'get_review_count' )->andReturn( 0 );

		Functions\when( 'wc_get_product' )->justReturn( $product );

		$result = $this->tools()->execute( 'get_product_details', [ 'product_id' => 8 ] );

		$this->assertTrue( $result['on_sale'] );
		$this->assertSame( 30, $result['discount_percent'] );
	}

	// ── remove_from_cart: hard failure path (lines 548-551) ─────────────────────

	/**
	 * The item exists in the cart but WC()->cart->remove_cart_item() returns false
	 * (removal failed). The tool must report success=false with an error, hitting
	 * the final failure return, the path ToolsTest never exercises (its remove
	 * tests cover the success and unknown-key branches only).
	 */
	public function test_remove_from_cart_reports_failure_when_removal_rejected(): void {
		$product = $this->mockProduct( 5, 'Jeans', '59' );

		$mockCart = Mockery::mock( WC_Cart::class );
		$mockCart->shouldReceive( 'get_cart' )->andReturn( [ 'key_xyz' => [ 'data' => $product ] ] );
		$mockCart->shouldReceive( 'remove_cart_item' )->with( 'key_xyz' )->andReturn( false );
		// get_cart_total must NOT be read on the failure path.
		$mockCart->shouldNotReceive( 'get_cart_total' );
		Functions\when( 'WC' )->justReturn( (object) [ 'cart' => $mockCart ] );

		$result = $this->tools()->execute( 'remove_from_cart', [ 'cart_item_key' => 'key_xyz' ] );

		$this->assertFalse( $result['success'] );
		$this->assertArrayHasKey( 'error', $result );
		$this->assertStringContainsString( 'Could not remove', $result['error'] );
		$this->assertArrayNotHasKey( 'new_total', $result );
	}

	// ── plain_price: empty / null guard (line 603) ──────────────────────────────

	/**
	 * A product with no price ('' from get_price) must surface an empty price
	 * string rather than a formatted "$", exercising plain_price's
	 * `if ( '' === $price || null === $price ) return '';` guard via the public
	 * format_product_summary entry point.
	 */
	public function test_format_product_summary_returns_empty_price_for_blank_price(): void {
		$product = Mockery::mock( WC_Product::class );
		$product->shouldReceive( 'get_id' )->andReturn( 77 );
		$product->shouldReceive( 'get_name' )->andReturn( 'Price On Request' );
		$product->shouldReceive( 'get_price' )->andReturn( '' );          // empty -> guard.
		$product->shouldReceive( 'get_regular_price' )->andReturn( '' );  // empty -> guard.
		$product->shouldReceive( 'get_sale_price' )->andReturn( '' );
		$product->shouldReceive( 'is_on_sale' )->andReturn( false );
		$product->shouldReceive( 'is_in_stock' )->andReturn( true );
		$product->shouldReceive( 'get_short_description' )->andReturn( '' );
		$product->shouldReceive( 'get_image_id' )->andReturn( 0 );
		$product->shouldReceive( 'get_average_rating' )->andReturn( '0' );
		$product->shouldReceive( 'get_review_count' )->andReturn( 0 );

		$summary = $this->tools()->format_product_summary( $product );

		$this->assertSame( '', $summary['price'] );
		$this->assertSame( '', $summary['regular_price'] );
	}
}
