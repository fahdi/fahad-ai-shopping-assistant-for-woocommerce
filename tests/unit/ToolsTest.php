<?php
/**
 * Unit tests for Fahad_AI_Tools.
 *
 * Red → Green → Refactor cycle.
 * WP/WC functions mocked via Brain\Monkey; WC objects via Mockery.
 */

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;

class ToolsTest extends TestCase {

    use MockeryPHPUnitIntegration;

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();

        Functions\stubs( [
            'absint'              => fn( $n ) => abs( (int) $n ),
            'sanitize_text_field' => fn( $s ) => $s,
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

    // ── execute() routing ─────────────────────────────────────────────────────

    public function test_execute_returns_error_for_unknown_tool(): void {
        $result = $this->tools()->execute( 'nonexistent_tool', [] );

        $this->assertArrayHasKey( 'error', $result );
        $this->assertStringContainsString( 'nonexistent_tool', $result['error'] );
    }

    public function test_execute_routes_to_search_products(): void {
        Functions\when( 'wc_get_products' )->justReturn( [] );

        $result = $this->tools()->execute( 'search_products', [ 'query' => 'shirt' ] );

        $this->assertArrayHasKey( 'found', $result );
        $this->assertArrayHasKey( 'products', $result );
    }

    public function test_execute_routes_to_view_cart(): void {
        $mockCart = Mockery::mock( WC_Cart::class );
        $mockCart->shouldReceive( 'is_empty' )->andReturn( true );
        Functions\when( 'WC' )->justReturn( (object) [ 'cart' => $mockCart ] );

        $result = $this->tools()->execute( 'view_cart', [] );

        $this->assertTrue( $result['empty'] );
    }

    // ── search_products ───────────────────────────────────────────────────────

    public function test_search_returns_found_count_and_product_data(): void {
        $product = $this->mockProduct( 1, 'Blue Jeans', '59.99' );
        Functions\when( 'wc_get_products' )->justReturn( [ $product ] );

        $result = $this->tools()->execute( 'search_products', [ 'query' => 'jeans' ] );

        $this->assertSame( 1, $result['found'] );
        $this->assertCount( 1, $result['products'] );
        $this->assertSame( 1,          $result['products'][0]['id'] );
        $this->assertSame( 'Blue Jeans', $result['products'][0]['name'] );
    }

    public function test_search_returns_empty_when_no_products_found(): void {
        Functions\when( 'wc_get_products' )->justReturn( [] );

        $result = $this->tools()->execute( 'search_products', [ 'query' => 'xyz_nonexistent' ] );

        $this->assertSame( 0, $result['found'] );
        $this->assertEmpty( $result['products'] );
        $this->assertArrayHasKey( 'message', $result );
    }

    public function test_search_caps_limit_at_10(): void {
        Functions\expect( 'wc_get_products' )
            ->once()
            ->andReturnUsing( function ( array $args ): array {
                $this->assertSame( 10, $args['limit'] );
                return [];
            } );

        $this->tools()->execute( 'search_products', [ 'limit' => 999 ] );
    }

    public function test_search_applies_price_range_filters(): void {
        Functions\expect( 'wc_get_products' )
            ->once()
            ->andReturnUsing( function ( array $args ): array {
                $this->assertSame( 10.0, $args['min_price'] );
                $this->assertSame( 50.0, $args['max_price'] );
                return [];
            } );

        $this->tools()->execute( 'search_products', [ 'min_price' => 10, 'max_price' => 50 ] );
    }

    // ── search relaxation: plurals & adjective-laden queries (live-QA finding) ──

    public function test_search_relaxes_plural_query_to_find_product(): void {
        // "hoodies" must surface the "Premium Pullover Hoodie" even though WooCommerce's
        // literal substring search misses the plural ("hoodies" is not in the title).
        $this->aliasCatalogSearch( [ 38 => 'Premium Pullover Hoodie', 14 => 'Running Sneakers' ] );

        $result = $this->tools()->execute( 'search_products', [ 'query' => 'hoodies' ] );

        $this->assertSame( 1, $result['found'] );
        $this->assertSame( 38, $result['products'][0]['id'] );
    }

    public function test_search_relaxes_adjective_laden_query(): void {
        // A shopper phrase ("Medium Black Premium Pullover Hoodie") should drop the
        // size/colour words and still resolve the product.
        $this->aliasCatalogSearch( [ 38 => 'Premium Pullover Hoodie', 14 => 'Running Sneakers' ] );

        $result = $this->tools()->execute( 'search_products', [ 'query' => 'Medium Black Premium Pullover Hoodie' ] );

        $this->assertSame( 38, $result['products'][0]['id'] );
    }

    public function test_search_does_not_overbroaden_for_nonsense(): void {
        // Relaxation must not turn a genuine no-match into "return everything".
        $this->aliasCatalogSearch( [ 38 => 'Premium Pullover Hoodie', 14 => 'Running Sneakers' ] );

        $result = $this->tools()->execute( 'search_products', [ 'query' => 'zzzznope' ] );

        $this->assertSame( 0, $result['found'] );
    }

    public function test_search_product_summary_contains_required_fields(): void {
        $product = $this->mockProduct( 7, 'T-Shirt', '29.99' );
        Functions\when( 'wc_get_products' )->justReturn( [ $product ] );

        $result  = $this->tools()->execute( 'search_products', [ 'query' => 'shirt' ] );
        $summary = $result['products'][0];

        foreach ( [ 'id', 'name', 'price', 'in_stock', 'url' ] as $key ) {
            $this->assertArrayHasKey( $key, $summary, "Summary missing key: {$key}" );
        }
    }

    // ── get_product_details ───────────────────────────────────────────────────

    public function test_get_product_details_returns_full_data(): void {
        $product = $this->mockProduct( 5, 'Sneakers', '89.99' );
        Functions\when( 'wc_get_product' )->justReturn( $product );

        $result = $this->tools()->execute( 'get_product_details', [ 'product_id' => 5 ] );

        $this->assertSame( 5, $result['id'] );
        $this->assertSame( 'Sneakers', $result['name'] );
        foreach ( [ 'sku', 'in_stock', 'categories', 'url' ] as $key ) {
            $this->assertArrayHasKey( $key, $result );
        }
    }

    public function test_get_product_details_returns_error_for_false_product(): void {
        Functions\when( 'wc_get_product' )->justReturn( false );

        $result = $this->tools()->execute( 'get_product_details', [ 'product_id' => 9999 ] );

        $this->assertArrayHasKey( 'error', $result );
    }

    public function test_get_product_details_returns_error_for_invisible_product(): void {
        // Build a minimal mock — do NOT use mockProduct() to avoid is_visible conflict.
        $product = Mockery::mock( WC_Product::class );
        $product->shouldReceive( 'is_visible' )->andReturn( false );
        Functions\when( 'wc_get_product' )->justReturn( $product );

        $result = $this->tools()->execute( 'get_product_details', [ 'product_id' => 3 ] );

        $this->assertArrayHasKey( 'error', $result );
    }

    // ── rating fields on the card payload (issue #11) ───────────────────────────

    public function test_search_product_summary_includes_rating_and_review_count(): void {
        $product = $this->mockProduct( 7, 'T-Shirt', '29.99' );
        Functions\when( 'wc_get_products' )->justReturn( [ $product ] );

        $summary = $this->tools()->execute( 'search_products', [ 'query' => 'shirt' ] )['products'][0];

        $this->assertArrayHasKey( 'rating', $summary );
        $this->assertArrayHasKey( 'review_count', $summary );
        $this->assertSame( 4.5, $summary['rating'] );
        $this->assertSame( 8, $summary['review_count'] );
    }

    public function test_get_product_details_includes_rating_and_review_count(): void {
        $product = $this->mockProduct( 5, 'Sneakers', '89.99' );
        Functions\when( 'wc_get_product' )->justReturn( $product );

        $result = $this->tools()->execute( 'get_product_details', [ 'product_id' => 5 ] );

        $this->assertArrayHasKey( 'rating', $result );
        $this->assertArrayHasKey( 'review_count', $result );
        $this->assertSame( 4.5, $result['rating'] );
        $this->assertSame( 8, $result['review_count'] );
    }

    public function test_product_with_no_reviews_reports_zero_rating_and_count(): void {
        $product = Mockery::mock( WC_Product::class );
        $product->shouldReceive( 'get_id' )->andReturn( 9 );
        $product->shouldReceive( 'get_name' )->andReturn( 'Brand New Item' );
        $product->shouldReceive( 'get_price' )->andReturn( '10' );
        $product->shouldReceive( 'get_regular_price' )->andReturn( '10' );
        $product->shouldReceive( 'get_sale_price' )->andReturn( '' );
        $product->shouldReceive( 'is_on_sale' )->andReturn( false );
        $product->shouldReceive( 'is_visible' )->andReturn( true );
        $product->shouldReceive( 'is_in_stock' )->andReturn( true );
        $product->shouldReceive( 'get_short_description' )->andReturn( '' );
        $product->shouldReceive( 'get_image_id' )->andReturn( 0 );
        $product->shouldReceive( 'get_average_rating' )->andReturn( '0' );
        $product->shouldReceive( 'get_review_count' )->andReturn( 0 );
        Functions\when( 'wc_get_products' )->justReturn( [ $product ] );

        $summary = $this->tools()->execute( 'search_products', [ 'query' => 'new' ] )['products'][0];

        $this->assertSame( 0.0, $summary['rating'] );
        $this->assertSame( 0, $summary['review_count'] );
    }

    // ── get_product_details: variations (issue #12) ────────────────────────────

    public function test_get_product_details_surfaces_readable_variations(): void {
        // Global taxonomy attributes (pa_size / pa_color): get_available_variations
        // returns term *slugs*; the tool must present them as human-readable labels.
        Functions\when( 'wc_attribute_label' )->alias(
            fn( $name ) => [ 'pa_size' => 'Size', 'pa_color' => 'Color' ][ $name ] ?? ucfirst( $name )
        );
        Functions\when( 'taxonomy_exists' )->justReturn( true );
        Functions\when( 'get_term_by' )->alias(
            fn( $by, $value, $tax ) => (object) [ 'name' => ucfirst( (string) $value ) ]
        );

        $parent    = $this->mockVariableProduct( 5, 'Cotton Tee', '20.00' );
        $variation = $this->mockVariation( 51, '25.00', true );

        $parent->shouldReceive( 'get_available_variations' )->andReturn( [
            [
                'variation_id' => 51,
                'attributes'   => [ 'attribute_pa_size' => 'large', 'attribute_pa_color' => 'blue' ],
            ],
        ] );

        Functions\when( 'wc_get_product' )->alias(
            fn( $id ) => 5 === (int) $id ? $parent : ( 51 === (int) $id ? $variation : false )
        );

        $result = $this->tools()->execute( 'get_product_details', [ 'product_id' => 5 ] );

        $this->assertArrayHasKey( 'variations', $result );
        $this->assertCount( 1, $result['variations'] );

        $v = $result['variations'][0];
        $this->assertSame( 51, $v['variation_id'] );
        // Human-readable attribute summary built from slugs.
        $this->assertSame( 'Size: Large, Color: Blue', $v['label'] );
        // Variation-level price + stock are surfaced.
        $this->assertSame( '$25.00', $v['price'] );
        $this->assertTrue( $v['in_stock'] );
        // Raw attributes map is retained for the model.
        $this->assertArrayHasKey( 'attributes', $v );
    }

    public function test_get_product_details_variation_label_falls_back_to_custom_attribute_values(): void {
        // Custom (non-taxonomy) product attributes: the value is already a display
        // value, not a slug — taxonomy_exists is false, so no term lookup happens.
        Functions\when( 'wc_attribute_label' )->alias( fn( $name ) => ucfirst( str_replace( '-', ' ', $name ) ) );
        Functions\when( 'taxonomy_exists' )->justReturn( false );

        $parent    = $this->mockVariableProduct( 6, 'Mug', '8.00' );
        $variation = $this->mockVariation( 61, '8.00', true );

        $parent->shouldReceive( 'get_available_variations' )->andReturn( [
            [
                'variation_id' => 61,
                'attributes'   => [ 'attribute_finish' => 'Matte' ],
            ],
        ] );

        Functions\when( 'wc_get_product' )->alias(
            fn( $id ) => 6 === (int) $id ? $parent : ( 61 === (int) $id ? $variation : false )
        );

        $result = $this->tools()->execute( 'get_product_details', [ 'product_id' => 6 ] );

        $this->assertSame( 'Finish: Matte', $result['variations'][0]['label'] );
    }

    public function test_get_product_details_variation_reports_out_of_stock(): void {
        Functions\when( 'wc_attribute_label' )->alias( fn( $name ) => ucfirst( $name ) );
        Functions\when( 'taxonomy_exists' )->justReturn( false );

        $parent       = $this->mockVariableProduct( 7, 'Hoodie', '40.00' );
        $inStockVar   = $this->mockVariation( 71, '40.00', true );
        $outOfStockVar = $this->mockVariation( 72, '40.00', false );

        $parent->shouldReceive( 'get_available_variations' )->andReturn( [
            [ 'variation_id' => 71, 'attributes' => [ 'attribute_size' => 'M' ] ],
            [ 'variation_id' => 72, 'attributes' => [ 'attribute_size' => 'L' ] ],
        ] );

        Functions\when( 'wc_get_product' )->alias( function ( $id ) use ( $parent, $inStockVar, $outOfStockVar ) {
            return [ 7 => $parent, 71 => $inStockVar, 72 => $outOfStockVar ][ (int) $id ] ?? false;
        } );

        $result = $this->tools()->execute( 'get_product_details', [ 'product_id' => 7 ] );

        $this->assertTrue( $result['variations'][0]['in_stock'] );
        $this->assertFalse( $result['variations'][1]['in_stock'] );
    }

    // ── add_to_cart ───────────────────────────────────────────────────────────

    public function test_add_to_cart_success_returns_cart_urls(): void {
        $product = $this->mockProduct( 10, 'Headphones', '149.99' );
        Functions\when( 'wc_get_product' )->justReturn( $product );

        $mockCart = Mockery::mock( WC_Cart::class );
        $mockCart->shouldReceive( 'add_to_cart' )->andReturn( 'cart_key_abc' );
        $mockCart->shouldReceive( 'get_cart_total' )->andReturn( '$149.99' );
        Functions\when( 'WC' )->justReturn( (object) [ 'cart' => $mockCart ] );

        $result = $this->tools()->execute( 'add_to_cart', [ 'product_id' => 10, 'quantity' => 1 ] );

        $this->assertTrue( $result['success'] );
        $this->assertSame( 'cart_key_abc', $result['cart_item_key'] );
        $this->assertArrayHasKey( 'cart_url',     $result );
        $this->assertArrayHasKey( 'checkout_url', $result );
    }

    public function test_add_to_cart_fails_for_out_of_stock_product(): void {
        // Build mock directly — avoid double-stub conflict from mockProduct().
        $product = Mockery::mock( WC_Product::class );
        $product->shouldReceive( 'is_visible' )->andReturn( true );
        $product->shouldReceive( 'is_in_stock' )->andReturn( false );
        $product->shouldReceive( 'get_name' )->andReturn( 'Sold Out Item' );
        Functions\when( 'wc_get_product' )->justReturn( $product );

        $result = $this->tools()->execute( 'add_to_cart', [ 'product_id' => 10 ] );

        $this->assertFalse( $result['success'] );
        $this->assertStringContainsString( 'out of stock', strtolower( $result['error'] ) );
    }

    public function test_add_to_cart_fails_for_nonexistent_product(): void {
        Functions\when( 'wc_get_product' )->justReturn( false );

        $result = $this->tools()->execute( 'add_to_cart', [ 'product_id' => 0 ] );

        $this->assertFalse( $result['success'] );
        $this->assertArrayHasKey( 'error', $result );
    }

    public function test_add_to_cart_returns_failure_when_wc_cart_rejects(): void {
        $product = $this->mockProduct( 10, 'Widget', '10' );
        Functions\when( 'wc_get_product' )->justReturn( $product );

        $mockCart = Mockery::mock( WC_Cart::class );
        $mockCart->shouldReceive( 'add_to_cart' )->andReturn( false );
        Functions\when( 'WC' )->justReturn( (object) [ 'cart' => $mockCart ] );

        $result = $this->tools()->execute( 'add_to_cart', [ 'product_id' => 10 ] );

        $this->assertFalse( $result['success'] );
    }

    public function test_add_to_cart_defaults_quantity_to_1(): void {
        $product = $this->mockProduct( 10, 'Widget', '10' );
        Functions\when( 'wc_get_product' )->justReturn( $product );

        $mockCart = Mockery::mock( WC_Cart::class );
        $mockCart->shouldReceive( 'add_to_cart' )
            ->with( 10, 1, 0 )
            ->andReturn( 'key_123' );
        $mockCart->shouldReceive( 'get_cart_total' )->andReturn( '$10.00' );
        Functions\when( 'WC' )->justReturn( (object) [ 'cart' => $mockCart ] );

        $result = $this->tools()->execute( 'add_to_cart', [ 'product_id' => 10 ] );

        $this->assertTrue( $result['success'] );
    }

    // ── add_to_cart: variations (issue #12) ────────────────────────────────────

    public function test_add_to_cart_adds_the_chosen_variation(): void {
        // Parent is a variable product; the customer chose variation 51. add_to_cart
        // must pass the variation_id through to WC and honor the VARIATION's stock,
        // not the parent's.
        $parent    = $this->mockVariableProduct( 5, 'Cotton Tee', '20.00' );
        $variation = $this->mockVariation( 51, '25.00', true );

        Functions\when( 'wc_get_product' )->alias(
            fn( $id ) => 5 === (int) $id ? $parent : ( 51 === (int) $id ? $variation : false )
        );

        $mockCart = Mockery::mock( WC_Cart::class );
        // The variation id is forwarded as the 3rd arg.
        $mockCart->shouldReceive( 'add_to_cart' )
            ->once()
            ->with( 5, 1, 51 )
            ->andReturn( 'cart_key_var' );
        $mockCart->shouldReceive( 'get_cart_total' )->andReturn( '$25.00' );
        Functions\when( 'WC' )->justReturn( (object) [ 'cart' => $mockCart ] );

        $result = $this->tools()->execute( 'add_to_cart', [ 'product_id' => 5, 'variation_id' => 51 ] );

        $this->assertTrue( $result['success'] );
        $this->assertSame( 'cart_key_var', $result['cart_item_key'] );
    }

    public function test_add_to_cart_rejects_out_of_stock_variation(): void {
        // The parent product is in stock, but the *chosen variation* is not.
        // The add must be rejected on the variation's stock, not the parent's.
        $parent    = $this->mockVariableProduct( 5, 'Cotton Tee', '20.00' );
        $variation = $this->mockVariation( 52, '25.00', false );

        Functions\when( 'wc_get_product' )->alias(
            fn( $id ) => 5 === (int) $id ? $parent : ( 52 === (int) $id ? $variation : false )
        );

        // The cart must never be touched when the variation is out of stock.
        $mockCart = Mockery::mock( WC_Cart::class );
        $mockCart->shouldNotReceive( 'add_to_cart' );
        Functions\when( 'WC' )->justReturn( (object) [ 'cart' => $mockCart ] );

        $result = $this->tools()->execute( 'add_to_cart', [ 'product_id' => 5, 'variation_id' => 52 ] );

        $this->assertFalse( $result['success'] );
        $this->assertStringContainsString( 'out of stock', strtolower( $result['error'] ) );
    }

    public function test_add_to_cart_rejects_variation_not_belonging_to_product(): void {
        // A variation_id that does not resolve (or is unrelated) must be rejected
        // rather than silently added against the wrong parent.
        $parent = $this->mockVariableProduct( 5, 'Cotton Tee', '20.00' );

        Functions\when( 'wc_get_product' )->alias(
            fn( $id ) => 5 === (int) $id ? $parent : false
        );

        $mockCart = Mockery::mock( WC_Cart::class );
        $mockCart->shouldNotReceive( 'add_to_cart' );
        Functions\when( 'WC' )->justReturn( (object) [ 'cart' => $mockCart ] );

        $result = $this->tools()->execute( 'add_to_cart', [ 'product_id' => 5, 'variation_id' => 999 ] );

        $this->assertFalse( $result['success'] );
        $this->assertArrayHasKey( 'error', $result );
    }

    // ── view_cart ─────────────────────────────────────────────────────────────

    public function test_view_cart_returns_empty_state(): void {
        $mockCart = Mockery::mock( WC_Cart::class );
        $mockCart->shouldReceive( 'is_empty' )->andReturn( true );
        Functions\when( 'WC' )->justReturn( (object) [ 'cart' => $mockCart ] );

        $result = $this->tools()->execute( 'view_cart', [] );

        $this->assertTrue( $result['empty'] );
        $this->assertArrayHasKey( 'message', $result );
    }

    public function test_view_cart_returns_items_totals_and_urls(): void {
        $product  = $this->mockProduct( 3, 'Bottle', '34.99' );
        $cartItem = [ 'product_id' => 3, 'quantity' => 2, 'line_total' => 69.98, 'data' => $product ];

        $mockCart = Mockery::mock( WC_Cart::class );
        $mockCart->shouldReceive( 'is_empty' )->andReturn( false );
        $mockCart->shouldReceive( 'get_cart' )->andReturn( [ 'key_abc' => $cartItem ] );
        $mockCart->shouldReceive( 'get_cart_contents_count' )->andReturn( 2 );
        $mockCart->shouldReceive( 'get_cart_subtotal' )->andReturn( '$69.98' );
        $mockCart->shouldReceive( 'get_cart_total' )->andReturn( '$69.98' );
        Functions\when( 'WC' )->justReturn( (object) [ 'cart' => $mockCart ] );

        $result = $this->tools()->execute( 'view_cart', [] );

        $this->assertFalse( $result['empty'] );
        $this->assertCount( 1, $result['items'] );
        $this->assertSame( 'key_abc', $result['items'][0]['cart_item_key'] );
        $this->assertSame( 2,         $result['items'][0]['quantity'] );
        $this->assertArrayHasKey( 'checkout_url', $result );
    }

    // ── remove_from_cart ──────────────────────────────────────────────────────

    public function test_remove_from_cart_success(): void {
        $product  = $this->mockProduct( 5, 'Jeans', '59' );
        $mockCart = Mockery::mock( WC_Cart::class );
        $mockCart->shouldReceive( 'get_cart' )
            ->andReturn( [ 'key_xyz' => [ 'data' => $product ] ] );
        $mockCart->shouldReceive( 'remove_cart_item' )->with( 'key_xyz' )->andReturn( true );
        $mockCart->shouldReceive( 'get_cart_total' )->andReturn( '$0.00' );
        Functions\when( 'WC' )->justReturn( (object) [ 'cart' => $mockCart ] );

        $result = $this->tools()->execute( 'remove_from_cart', [ 'cart_item_key' => 'key_xyz' ] );

        $this->assertTrue( $result['success'] );
        $this->assertStringContainsString( 'Jeans', $result['message'] );
    }

    public function test_remove_from_cart_fails_for_unknown_key(): void {
        $mockCart = Mockery::mock( WC_Cart::class );
        $mockCart->shouldReceive( 'get_cart' )->andReturn( [] );
        Functions\when( 'WC' )->justReturn( (object) [ 'cart' => $mockCart ] );

        $result = $this->tools()->execute( 'remove_from_cart', [ 'cart_item_key' => 'bad_key' ] );

        $this->assertFalse( $result['success'] );
        $this->assertArrayHasKey( 'error', $result );
    }

    public function test_remove_from_cart_requires_cart_item_key(): void {
        $result = $this->tools()->execute( 'remove_from_cart', [] );

        $this->assertFalse( $result['success'] );
        $this->assertArrayHasKey( 'error', $result );
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    /**
     * Default "happy path" product mock.
     * is_visible and is_in_stock use ->byDefault() so tests can override them.
     */
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
        $p->shouldReceive( 'get_type' )->andReturn( 'simple' );
        $p->shouldReceive( 'is_type' )->with( 'variable' )->andReturn( false );
        $p->shouldReceive( 'get_description' )->andReturn( '' );
        $p->shouldReceive( 'get_short_description' )->andReturn( '' );
        $p->shouldReceive( 'get_sku' )->andReturn( '' );
        $p->shouldReceive( 'get_stock_quantity' )->andReturn( 10 );
        $p->shouldReceive( 'get_image_id' )->andReturn( 0 );
        $p->shouldReceive( 'get_average_rating' )->andReturn( '4.5' )->byDefault();
        $p->shouldReceive( 'get_review_count' )->andReturn( 8 )->byDefault();
        return $p;
    }

    /**
     * Alias wc_get_products() to a WooCommerce-like AND-substring search over a small
     * { id => name } catalog, so search relaxation can be tested deterministically:
     * every word in 's' must be a substring of the product name (mirrors WP search).
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

    /**
     * A variable parent product mock. get_available_variations() is intentionally
     * NOT stubbed here so each test declares the exact variation set it needs.
     */
    private function mockVariableProduct( int $id, string $name, string $price ): WC_Product {
        $p = Mockery::mock( WC_Product::class );
        $p->shouldReceive( 'get_id' )->andReturn( $id );
        $p->shouldReceive( 'get_name' )->andReturn( $name );
        $p->shouldReceive( 'get_price' )->andReturn( $price );
        $p->shouldReceive( 'get_regular_price' )->andReturn( $price );
        $p->shouldReceive( 'get_sale_price' )->andReturn( '' );
        $p->shouldReceive( 'is_on_sale' )->andReturn( false );
        $p->shouldReceive( 'is_visible' )->andReturn( true )->byDefault();
        $p->shouldReceive( 'is_in_stock' )->andReturn( true )->byDefault();
        $p->shouldReceive( 'get_type' )->andReturn( 'variable' );
        $p->shouldReceive( 'is_type' )->with( 'variable' )->andReturn( true );
        $p->shouldReceive( 'get_description' )->andReturn( '' );
        $p->shouldReceive( 'get_short_description' )->andReturn( '' );
        $p->shouldReceive( 'get_sku' )->andReturn( '' );
        $p->shouldReceive( 'get_stock_quantity' )->andReturn( 10 );
        $p->shouldReceive( 'get_image_id' )->andReturn( 0 );
        $p->shouldReceive( 'get_average_rating' )->andReturn( '0' )->byDefault();
        $p->shouldReceive( 'get_review_count' )->andReturn( 0 )->byDefault();
        return $p;
    }

    /**
     * A single variation (child) product mock with its own price + stock state and
     * a parent id so add_to_cart can verify the variation belongs to the product.
     */
    private function mockVariation( int $id, string $price, bool $inStock, int $parentId = 5 ): WC_Product {
        $v = Mockery::mock( WC_Product::class );
        $v->shouldReceive( 'get_id' )->andReturn( $id );
        $v->shouldReceive( 'get_name' )->andReturn( 'Variation ' . $id );
        $v->shouldReceive( 'get_price' )->andReturn( $price );
        $v->shouldReceive( 'get_regular_price' )->andReturn( $price );
        $v->shouldReceive( 'get_sale_price' )->andReturn( '' );
        $v->shouldReceive( 'is_on_sale' )->andReturn( false );
        $v->shouldReceive( 'is_in_stock' )->andReturn( $inStock );
        $v->shouldReceive( 'is_visible' )->andReturn( true )->byDefault();
        $v->shouldReceive( 'get_type' )->andReturn( 'variation' );
        $v->shouldReceive( 'is_type' )->with( 'variable' )->andReturn( false );
        $v->shouldReceive( 'get_parent_id' )->andReturn( $parentId )->byDefault();
        return $v;
    }
}
