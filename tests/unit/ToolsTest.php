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
}
