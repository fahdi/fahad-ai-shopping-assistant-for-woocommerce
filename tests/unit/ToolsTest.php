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
            // Registry get_tools() reads the merchant tool-gating option (issue #56);
            // default (no disabled tools) so dispatch()/specs() are unaffected.
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

    public function test_search_no_match_suggests_real_store_categories(): void {
        // A genuine dead-end search must offer real browse paths so the shopper is not lost.
        $this->aliasCatalogSearch( [ 38 => 'Premium Pullover Hoodie', 14 => 'Running Sneakers' ] );
        Functions\when( 'get_terms' )->justReturn( [
            (object) [ 'name' => 'Shoes' ],
            (object) [ 'name' => 'Bags' ],
        ] );

        $result = $this->tools()->execute( 'search_products', [ 'query' => 'zzzznope' ] );

        $this->assertSame( 0, $result['found'] );
        $this->assertSame( [ 'Shoes', 'Bags' ], $result['suggested_categories'] );
    }

    public function test_search_on_sale_no_match_does_not_suggest_categories(): void {
        // The "nothing on sale" case needs no category detour.
        Functions\when( 'wc_get_products' )->justReturn( [] );
        Functions\when( 'get_terms' )->justReturn( [ (object) [ 'name' => 'Shoes' ] ] );

        $result = $this->tools()->execute( 'search_products', [ 'on_sale' => true ] );

        $this->assertSame( 0, $result['found'] );
        $this->assertArrayNotHasKey( 'suggested_categories', $result );
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

    public function test_search_min_rating_returns_only_well_rated_products(): void {
        // A quality-conscious "4+ stars" search must drop the lower-rated product.
        $high = $this->mockProduct( 1, 'Great Jacket', '80' );
        $high->shouldReceive( 'get_average_rating' )->andReturn( '4.8' );
        $low = $this->mockProduct( 2, 'Meh Jacket', '80' );
        $low->shouldReceive( 'get_average_rating' )->andReturn( '3.0' );
        Functions\when( 'wc_get_products' )->justReturn( [ $high, $low ] );

        $result = $this->tools()->execute( 'search_products', [ 'query' => 'jacket', 'min_rating' => 4.0 ] );

        $this->assertSame( 1, $result['found'], 'Only the 4+ star product must survive.' );
        $this->assertSame( 1, $result['products'][0]['id'] );
    }

    public function test_search_hides_out_of_stock_when_store_hides_them(): void {
        // With the store's "hide out of stock" catalog setting on, a sold-out product must be
        // dropped from results so the assistant never recommends something the shopper can't buy.
        $inStock = $this->mockProduct( 1, 'Available Jacket', '80' );
        $soldOut = $this->mockProduct( 2, 'Sold Out Jacket', '80' );
        $soldOut->shouldReceive( 'is_in_stock' )->andReturn( false );
        Functions\when( 'wc_get_products' )->justReturn( [ $inStock, $soldOut ] );
        Functions\when( 'get_option' )->alias(
            fn( $k, $d = '' ) => 'woocommerce_hide_out_of_stock_items' === $k ? 'yes' : $d
        );

        $result = $this->tools()->execute( 'search_products', [ 'query' => 'jacket' ] );

        $this->assertSame( 1, $result['found'] );
        $this->assertSame( 1, $result['products'][0]['id'] );
    }

    public function test_search_keeps_out_of_stock_when_store_does_not_hide_them(): void {
        // Default (setting off): out-of-stock products remain in results, behaviour unchanged.
        $inStock = $this->mockProduct( 1, 'Available Jacket', '80' );
        $soldOut = $this->mockProduct( 2, 'Sold Out Jacket', '80' );
        $soldOut->shouldReceive( 'is_in_stock' )->andReturn( false );
        Functions\when( 'wc_get_products' )->justReturn( [ $inStock, $soldOut ] );

        $result = $this->tools()->execute( 'search_products', [ 'query' => 'jacket' ] );

        $this->assertSame( 2, $result['found'] );
    }

    public function test_search_inverted_price_range_is_normalized_for_the_query(): void {
        // "between $100 and $50" must reach WooCommerce as $50..$100, not an impossible range.
        $captured = null;
        Functions\when( 'wc_get_products' )->alias( function ( array $args ) use ( &$captured ) {
            $captured = $args;
            return [];
        } );

        $this->tools()->execute( 'search_products', [ 'query' => 'jacket', 'min_price' => 100, 'max_price' => 50 ] );

        $this->assertSame( 50.0, $captured['min_price'] );
        $this->assertSame( 100.0, $captured['max_price'] );
    }

    public function test_search_negative_limit_does_not_fetch_unbounded_catalogue(): void {
        // A model-supplied limit of -1 must not reach the query as an unbounded fetch (-1 = all).
        $captured = null;
        Functions\when( 'wc_get_products' )->alias( function ( array $args ) use ( &$captured ) {
            $captured = $args;
            return [];
        } );

        $this->tools()->execute( 'search_products', [ 'query' => 'jacket', 'limit' => -1 ] );

        $this->assertSame( 1, $captured['limit'], 'A negative limit must clamp to 1, never -1 (unbounded).' );
    }

    public function test_search_sort_applies_woocommerce_ordering_to_the_query(): void {
        // "Cheapest first" must reach the product query as WooCommerce price-ascending ordering.
        $captured = null;
        Functions\when( 'wc_get_products' )->alias( function ( array $args ) use ( &$captured ) {
            $captured = $args;
            return [];
        } );

        $this->tools()->execute( 'search_products', [ 'query' => 'jacket', 'sort' => 'price_low' ] );

        $this->assertSame( 'price', $captured['orderby'] );
        $this->assertSame( 'ASC', $captured['order'] );
    }

    public function test_search_without_sort_keeps_relevance_order(): void {
        $captured = null;
        Functions\when( 'wc_get_products' )->alias( function ( array $args ) use ( &$captured ) {
            $captured = $args;
            return [];
        } );

        $this->tools()->execute( 'search_products', [ 'query' => 'jacket' ] );

        $this->assertSame( 'relevance', $captured['orderby'] );
        $this->assertArrayNotHasKey( 'order', $captured );
    }

    public function test_search_sort_param_exposed_in_schema(): void {
        $defs   = $this->tools()->builtin_definitions();
        $search = null;
        foreach ( $defs as $d ) {
            if ( 'search_products' === $d['name'] ) {
                $search = $d;
                break;
            }
        }
        $props = $search['parameters']['properties'] ?? [];
        $this->assertArrayHasKey( 'sort', $props );
        $this->assertContains( 'price_low', $props['sort']['enum'] ?? [] );
    }

    public function test_search_min_rating_param_exposed_in_schema(): void {
        $defs  = $this->tools()->builtin_definitions();
        $search = null;
        foreach ( $defs as $d ) {
            if ( 'search_products' === $d['name'] ) {
                $search = $d;
                break;
            }
        }
        $props = $search['parameters']['properties'] ?? [];
        $this->assertArrayHasKey( 'min_rating', $props );
        $this->assertSame( 'number', $props['min_rating']['type'] ?? null );
    }

    public function test_search_summary_flags_bestseller_from_real_data(): void {
        // With an owner-set bestseller threshold, a high-selling product must carry the badge in
        // list results so the assistant can steer shoppers to proven best-sellers at discovery.
        $product = $this->mockProduct( 7, 'Alpine Jacket', '129.99' );
        $product->shouldReceive( 'get_total_sales' )->andReturn( 500 );
        Functions\when( 'wc_get_products' )->justReturn( [ $product ] );
        Functions\when( 'get_option' )->alias(
            fn( $k, $d = '' ) => 'fahad_ai_bestseller_threshold' === $k ? 100 : $d
        );

        $result = $this->tools()->execute( 'search_products', [ 'query' => 'jacket' ] );

        $this->assertTrue( $result['products'][0]['bestseller'] );
    }

    public function test_search_summary_bestseller_false_without_threshold(): void {
        // Default (no threshold): nothing is badged, behaviour unchanged.
        $product = $this->mockProduct( 8, 'Regular Jacket', '99.99' );
        $product->shouldReceive( 'get_total_sales' )->andReturn( 5000 );
        Functions\when( 'wc_get_products' )->justReturn( [ $product ] );

        $result = $this->tools()->execute( 'search_products', [ 'query' => 'jacket' ] );

        $this->assertFalse( $result['products'][0]['bestseller'] );
    }

    public function test_search_summary_flags_highly_rated_from_real_data(): void {
        // A well-reviewed product (4.5 stars, 8 reviews via mockProduct defaults) must carry the
        // decision-ready social-proof flag in list results, so the assistant can lead with it.
        $product = $this->mockProduct( 7, 'Beloved Tee', '29.99' );
        Functions\when( 'wc_get_products' )->justReturn( [ $product ] );

        $result = $this->tools()->execute( 'search_products', [ 'query' => 'tee' ] );

        $this->assertTrue( $result['products'][0]['highly_rated'] );
    }

    public function test_search_summary_highly_rated_false_without_enough_reviews(): void {
        // A perfect score with too few reviews must not read as top-rated (same bar as details).
        $product = $this->mockProduct( 8, 'New Tee', '19.99' );
        $product->shouldReceive( 'get_average_rating' )->andReturn( '5.0' );
        $product->shouldReceive( 'get_review_count' )->andReturn( 2 );
        Functions\when( 'wc_get_products' )->justReturn( [ $product ] );

        $result = $this->tools()->execute( 'search_products', [ 'query' => 'tee' ] );

        $this->assertFalse( $result['products'][0]['highly_rated'] );
    }

    // ── on-sale filter (issue #137) ─────────────────────────────────────────────
    // A grounded "what is on sale" needs a real filter, so the assistant can never
    // claim sale status from memory and the cards always match the claim.

    private function mockSaleProduct( int $id, string $name, string $regular, string $sale ): WC_Product {
        $p = Mockery::mock( WC_Product::class );
        $p->shouldReceive( 'get_id' )->andReturn( $id );
        $p->shouldReceive( 'get_name' )->andReturn( $name );
        $p->shouldReceive( 'get_price' )->andReturn( $sale );
        $p->shouldReceive( 'get_regular_price' )->andReturn( $regular );
        $p->shouldReceive( 'get_sale_price' )->andReturn( $sale );
        $p->shouldReceive( 'is_on_sale' )->andReturn( true );
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
        $p->shouldReceive( 'get_total_sales' )->andReturn( 0 )->byDefault();
        $p->shouldReceive( 'has_enough_stock' )->andReturn( true )->byDefault();
        return $p;
    }

    public function test_search_products_schema_exposes_on_sale_param(): void {
        $defs = $this->tools()->builtin_definitions();
        $search = null;
        foreach ( $defs as $d ) {
            if ( 'search_products' === ( $d['name'] ?? '' ) ) { $search = $d; break; }
        }
        $this->assertNotNull( $search, 'search_products tool must exist' );
        $props = $search['parameters']['properties'] ?? [];
        $this->assertArrayHasKey( 'on_sale', $props, 'search_products must expose an on_sale param' );
        $this->assertSame( 'boolean', $props['on_sale']['type'] ?? null );
    }

    public function test_search_on_sale_returns_only_discounted_products(): void {
        $sale   = $this->mockSaleProduct( 1, 'Discounted Mug', '49.99', '34.99' );
        $normal = $this->mockProduct( 2, 'Full Price Tee', '34.99' );
        Functions\when( 'wc_get_products' )->justReturn( [ $sale, $normal ] );

        $result = $this->tools()->execute( 'search_products', [ 'on_sale' => true ] );

        $this->assertSame( 1, $result['found'], 'Only the on-sale product must be returned.' );
        $this->assertSame( 1, $result['products'][0]['id'] );
        $this->assertTrue( $result['products'][0]['on_sale'] );
        // The grounded deal magnitude must be present in list results (49.99 -> 34.99 = 30% off),
        // so the assistant can lead with "30% off" without a separate product-details call.
        $this->assertSame( 30, $result['products'][0]['discount_percent'] );
    }

    public function test_search_summary_discount_percent_is_null_when_not_on_sale(): void {
        $normal = $this->mockProduct( 2, 'Full Price Tee', '34.99' );
        Functions\when( 'wc_get_products' )->justReturn( [ $normal ] );

        $result = $this->tools()->execute( 'search_products', [ 'query' => 'tee' ] );

        $this->assertNull( $result['products'][0]['discount_percent'] );
    }

    public function test_search_on_sale_with_none_returns_zero_and_a_sale_message(): void {
        Functions\when( 'wc_get_products' )->justReturn( [ $this->mockProduct( 9, 'Full Price', '20.00' ) ] );

        $result = $this->tools()->execute( 'search_products', [ 'on_sale' => true ] );

        $this->assertSame( 0, $result['found'] );
        $this->assertStringContainsStringIgnoringCase( 'on sale', (string) ( $result['message'] ?? '' ) );
    }

    public function test_search_without_on_sale_does_not_filter(): void {
        $sale   = $this->mockSaleProduct( 1, 'Discounted Mug', '49.99', '34.99' );
        $normal = $this->mockProduct( 2, 'Full Price Tee', '34.99' );
        Functions\when( 'wc_get_products' )->justReturn( [ $sale, $normal ] );

        $result = $this->tools()->execute( 'search_products', [ 'query' => 'mug' ] );

        $this->assertSame( 2, $result['found'], 'Without on_sale the filter must not apply.' );
    }

    // ── get_product_details ───────────────────────────────────────────────────

    public function test_get_product_details_returns_full_data(): void {
        $product = $this->mockProduct( 5, 'Sneakers', '89.99' );
        Functions\when( 'wc_get_product' )->justReturn( $product );

        $result = $this->tools()->execute( 'get_product_details', [ 'product_id' => 5 ] );

        $this->assertSame( 5, $result['id'] );
        $this->assertSame( 'Sneakers', $result['name'] );
        foreach ( [ 'sku', 'in_stock', 'categories', 'url', 'low_stock', 'highly_rated' ] as $key ) {
            $this->assertArrayHasKey( $key, $result );
        }
        // qty 10 vs default threshold 2 => not low.
        $this->assertFalse( $result['low_stock'] );
        // rating 4.5 with 8 reviews meets the 4.5 / 5 bar => highly rated (#255).
        $this->assertTrue( $result['highly_rated'] );
    }

    public function test_get_product_details_flags_low_stock_from_real_data(): void {
        $product = $this->mockProduct( 5, 'Sneakers', '89.99' ); // stubbed stock qty is 10
        Functions\when( 'wc_get_product' )->justReturn( $product );
        // Raise the store low-stock threshold so the real quantity (10) counts as low.
        Functions\when( 'get_option' )->alias(
            fn( $k, $d = '' ) => 'woocommerce_notify_low_stock_amount' === $k ? 20 : $d
        );

        $result = $this->tools()->execute( 'get_product_details', [ 'product_id' => 5 ] );

        $this->assertTrue( $result['low_stock'] );
    }

    public function test_get_product_details_returns_error_for_false_product(): void {
        Functions\when( 'wc_get_product' )->justReturn( false );

        $result = $this->tools()->execute( 'get_product_details', [ 'product_id' => 9999 ] );

        $this->assertArrayHasKey( 'error', $result );
    }

    public function test_get_product_details_returns_error_for_invisible_product(): void {
        // Build a minimal mock, do NOT use mockProduct() to avoid is_visible conflict.
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
        // value, not a slug, taxonomy_exists is false, so no term lookup happens.
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
        // No free-shipping threshold configured (get_option default 0) => no nudge field.
        $this->assertArrayNotHasKey( 'free_shipping', $result );
        // A well-stocked item (qty 10) must not read as low stock.
        $this->assertFalse( $result['low_stock'] );
        $this->assertSame( 10, $result['stock_qty'] );
        // A successful add is not a dead end, so no back-in-stock offer.
        $this->assertArrayNotHasKey( 'back_in_stock_available', $result );
    }

    public function test_add_to_cart_flags_low_stock_at_commitment(): void {
        // The exact item added is nearly sold out (2 left, default threshold): the response must
        // carry a grounded scarcity signal so the assistant can say "only 2 left, check out soon".
        $product = Mockery::mock( WC_Product::class );
        $product->shouldReceive( 'get_id' )->andReturn( 10 );
        $product->shouldReceive( 'get_name' )->andReturn( 'Headphones' );
        $product->shouldReceive( 'get_price' )->andReturn( '149.99' );
        $product->shouldReceive( 'is_visible' )->andReturn( true );
        $product->shouldReceive( 'is_in_stock' )->andReturn( true );
        $product->shouldReceive( 'has_enough_stock' )->andReturn( true );
        $product->shouldReceive( 'get_stock_quantity' )->andReturn( 2 );
        Functions\when( 'wc_get_product' )->justReturn( $product );

        $mockCart = Mockery::mock( WC_Cart::class );
        $mockCart->shouldReceive( 'add_to_cart' )->andReturn( 'cart_key_abc' );
        $mockCart->shouldReceive( 'get_cart_total' )->andReturn( '$149.99' );
        Functions\when( 'WC' )->justReturn( (object) [ 'cart' => $mockCart ] );

        $result = $this->tools()->execute( 'add_to_cart', [ 'product_id' => 10, 'quantity' => 1 ] );

        $this->assertTrue( $result['low_stock'] );
        $this->assertSame( 2, $result['stock_qty'] );
    }

    public function test_add_to_cart_includes_free_shipping_progress_when_threshold_set(): void {
        $product = $this->mockProduct( 10, 'Headphones', '35.00' );
        Functions\when( 'wc_get_product' )->justReturn( $product );

        $mockCart = Mockery::mock( WC_Cart::class );
        $mockCart->shouldReceive( 'add_to_cart' )->andReturn( 'cart_key_abc' );
        $mockCart->shouldReceive( 'get_cart_total' )->andReturn( '$35.00' );
        $mockCart->shouldReceive( 'get_cart_contents_total' )->andReturn( 35.0 );
        Functions\when( 'WC' )->justReturn( (object) [ 'cart' => $mockCart ] );
        Functions\when( 'get_option' )->alias(
            fn( $k, $d = '' ) => 'fahad_ai_free_shipping_threshold' === $k ? 50.0 : $d
        );

        $result = $this->tools()->execute( 'add_to_cart', [ 'product_id' => 10, 'quantity' => 1 ] );

        $this->assertArrayHasKey( 'free_shipping', $result );
        $this->assertSame( 50.0, $result['free_shipping']['threshold'] );
        $this->assertEqualsWithDelta( 15.0, $result['free_shipping']['remaining'], 0.001 );
        $this->assertFalse( $result['free_shipping']['qualified'] );
    }

    public function test_add_to_cart_gives_honest_message_when_not_enough_stock(): void {
        // In stock, but the shopper asked for more than is available (WC has_enough_stock=false).
        // The response must name the real available count, not the misleading "may require a
        // variation" fallback, and must not touch the cart.
        $product = Mockery::mock( WC_Product::class );
        $product->shouldReceive( 'is_visible' )->andReturn( true );
        $product->shouldReceive( 'is_in_stock' )->andReturn( true );
        $product->shouldReceive( 'has_enough_stock' )->with( 5 )->andReturn( false );
        $product->shouldReceive( 'get_stock_quantity' )->andReturn( 2 );
        $product->shouldReceive( 'get_name' )->andReturn( 'Headphones' );
        Functions\when( 'wc_get_product' )->justReturn( $product );

        $mockCart = Mockery::mock( WC_Cart::class );
        $mockCart->shouldNotReceive( 'add_to_cart' );
        Functions\when( 'WC' )->justReturn( (object) [ 'cart' => $mockCart ] );

        $result = $this->tools()->execute( 'add_to_cart', [ 'product_id' => 10, 'quantity' => 5 ] );

        $this->assertFalse( $result['success'] );
        $this->assertStringContainsString( '2', $result['error'] );
        $this->assertStringContainsString( 'Headphones', $result['error'] );
        $this->assertStringNotContainsString( 'variation', strtolower( $result['error'] ) );
    }

    public function test_add_to_cart_fails_for_out_of_stock_product(): void {
        // Build mock directly, avoid double-stub conflict from mockProduct().
        $product = Mockery::mock( WC_Product::class );
        $product->shouldReceive( 'is_visible' )->andReturn( true );
        $product->shouldReceive( 'is_in_stock' )->andReturn( false );
        $product->shouldReceive( 'get_name' )->andReturn( 'Sold Out Item' );
        Functions\when( 'wc_get_product' )->justReturn( $product );

        $result = $this->tools()->execute( 'add_to_cart', [ 'product_id' => 10 ] );

        $this->assertFalse( $result['success'] );
        $this->assertStringContainsString( 'out of stock', strtolower( $result['error'] ) );
        // No categories resolvable (get_the_terms default []) => no suggestions field.
        $this->assertArrayNotHasKey( 'suggested_categories', $result );
        // The dead end must surface the back-in-stock recovery so the assistant offers it.
        $this->assertTrue( $result['back_in_stock_available'] );
    }

    public function test_add_to_cart_out_of_stock_offers_category_alternatives(): void {
        // A sold-out add is a high-intent dead end; the failure must carry the product's real
        // categories so the assistant can redirect ("want to see other Jackets?").
        $product = Mockery::mock( WC_Product::class );
        $product->shouldReceive( 'is_visible' )->andReturn( true );
        $product->shouldReceive( 'is_in_stock' )->andReturn( false );
        $product->shouldReceive( 'get_name' )->andReturn( 'Sold Out Jacket' );
        Functions\when( 'wc_get_product' )->justReturn( $product );
        Functions\when( 'get_the_terms' )->justReturn( [
            (object) [ 'name' => 'Jackets' ],
            (object) [ 'name' => 'Outerwear' ],
        ] );

        $result = $this->tools()->execute( 'add_to_cart', [ 'product_id' => 10 ] );

        $this->assertFalse( $result['success'] );
        $this->assertSame( [ 'Jackets', 'Outerwear' ], $result['suggested_categories'] );
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
        $mockCart->shouldReceive( 'get_applied_coupons' )->andReturn( [] )->byDefault();
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
        // No free-shipping threshold configured (get_option default 0) => no nudge field.
        $this->assertArrayNotHasKey( 'free_shipping', $result );
    }

    public function test_view_cart_includes_free_shipping_progress_when_threshold_set(): void {
        $product  = $this->mockProduct( 3, 'Bottle', '34.99' );
        $cartItem = [ 'product_id' => 3, 'quantity' => 1, 'line_total' => 34.99, 'data' => $product ];

        $mockCart = Mockery::mock( WC_Cart::class );
        $mockCart->shouldReceive( 'is_empty' )->andReturn( false );
        $mockCart->shouldReceive( 'get_applied_coupons' )->andReturn( [] )->byDefault();
        $mockCart->shouldReceive( 'get_cart' )->andReturn( [ 'key_abc' => $cartItem ] );
        $mockCart->shouldReceive( 'get_cart_contents_count' )->andReturn( 1 );
        $mockCart->shouldReceive( 'get_cart_subtotal' )->andReturn( '$34.99' );
        $mockCart->shouldReceive( 'get_cart_total' )->andReturn( '$34.99' );
        $mockCart->shouldReceive( 'get_cart_contents_total' )->andReturn( 34.99 );
        Functions\when( 'WC' )->justReturn( (object) [ 'cart' => $mockCart ] );
        Functions\when( 'get_option' )->alias(
            fn( $k, $d = '' ) => 'fahad_ai_free_shipping_threshold' === $k ? 50.0 : $d
        );

        $result = $this->tools()->execute( 'view_cart', [] );

        $this->assertArrayHasKey( 'free_shipping', $result );
        $this->assertSame( 50.0, $result['free_shipping']['threshold'] );
        $this->assertEqualsWithDelta( 15.01, $result['free_shipping']['remaining'], 0.001 );
        $this->assertFalse( $result['free_shipping']['qualified'] );
    }

    public function test_view_cart_reflects_applied_coupons_and_discount(): void {
        // A coupon is applied; reviewing the cart must show the code and the money it took off,
        // so the assistant can reassure "SAVE10 is applied, taking $5 off" before checkout.
        $product  = $this->mockProduct( 3, 'Bottle', '34.99' );
        $cartItem = [ 'product_id' => 3, 'quantity' => 1, 'line_total' => 29.99, 'data' => $product ];

        $mockCart = Mockery::mock( WC_Cart::class );
        $mockCart->shouldReceive( 'is_empty' )->andReturn( false );
        $mockCart->shouldReceive( 'get_cart' )->andReturn( [ 'key_abc' => $cartItem ] );
        $mockCart->shouldReceive( 'get_cart_contents_count' )->andReturn( 1 );
        $mockCart->shouldReceive( 'get_cart_subtotal' )->andReturn( '$34.99' );
        $mockCart->shouldReceive( 'get_cart_total' )->andReturn( '$29.99' );
        $mockCart->shouldReceive( 'get_applied_coupons' )->andReturn( [ 'SAVE10' ] );
        $mockCart->shouldReceive( 'get_discount_total' )->andReturn( 5.0 );
        Functions\when( 'WC' )->justReturn( (object) [ 'cart' => $mockCart ] );

        $result = $this->tools()->execute( 'view_cart', [] );

        $this->assertSame( [ 'SAVE10' ], $result['applied_coupons'] );
        $this->assertSame( 5.0, $result['discount_total'] );
    }

    public function test_view_cart_includes_cart_savings_when_items_on_sale(): void {
        // A cart with one genuinely discounted line (regular 50, sale 40, x2 => 20 saved).
        $product = Mockery::mock( WC_Product::class );
        $product->shouldReceive( 'get_id' )->andReturn( 3 );
        $product->shouldReceive( 'get_name' )->andReturn( 'Bottle' );
        $product->shouldReceive( 'get_price' )->andReturn( '40' );
        $product->shouldReceive( 'get_regular_price' )->andReturn( '50' );
        $product->shouldReceive( 'get_sale_price' )->andReturn( '40' );
        $product->shouldReceive( 'is_on_sale' )->andReturn( true );

        $cartItem = [ 'product_id' => 3, 'quantity' => 2, 'line_total' => 80.0, 'data' => $product ];

        $mockCart = Mockery::mock( WC_Cart::class );
        $mockCart->shouldReceive( 'is_empty' )->andReturn( false );
        $mockCart->shouldReceive( 'get_applied_coupons' )->andReturn( [] )->byDefault();
        $mockCart->shouldReceive( 'get_cart' )->andReturn( [ 'key_abc' => $cartItem ] );
        $mockCart->shouldReceive( 'get_cart_contents_count' )->andReturn( 2 );
        $mockCart->shouldReceive( 'get_cart_subtotal' )->andReturn( '$80.00' );
        $mockCart->shouldReceive( 'get_cart_total' )->andReturn( '$80.00' );
        Functions\when( 'WC' )->justReturn( (object) [ 'cart' => $mockCart ] );

        $result = $this->tools()->execute( 'view_cart', [] );

        $this->assertSame( 20.0, $result['cart_savings'] );
    }

    public function test_view_cart_omits_cart_savings_when_nothing_on_sale(): void {
        // mockProduct is not on sale, so there is no genuine saving and the field is omitted.
        $product  = $this->mockProduct( 3, 'Bottle', '34.99' );
        $cartItem = [ 'product_id' => 3, 'quantity' => 1, 'line_total' => 34.99, 'data' => $product ];

        $mockCart = Mockery::mock( WC_Cart::class );
        $mockCart->shouldReceive( 'is_empty' )->andReturn( false );
        $mockCart->shouldReceive( 'get_applied_coupons' )->andReturn( [] )->byDefault();
        $mockCart->shouldReceive( 'get_cart' )->andReturn( [ 'key_abc' => $cartItem ] );
        $mockCart->shouldReceive( 'get_cart_contents_count' )->andReturn( 1 );
        $mockCart->shouldReceive( 'get_cart_subtotal' )->andReturn( '$34.99' );
        $mockCart->shouldReceive( 'get_cart_total' )->andReturn( '$34.99' );
        Functions\when( 'WC' )->justReturn( (object) [ 'cart' => $mockCart ] );

        $result = $this->tools()->execute( 'view_cart', [] );

        $this->assertArrayNotHasKey( 'cart_savings', $result );
    }

    // ── remove_from_cart ──────────────────────────────────────────────────────

    // ── update_cart_quantity (issue #303) ───────────────────────────────────────

    public function test_update_cart_quantity_sets_new_quantity(): void {
        $product = $this->mockProduct( 5, 'Jeans', '59' );
        $mockCart = Mockery::mock( WC_Cart::class );
        $mockCart->shouldReceive( 'get_cart' )->andReturn( [ 'key_xyz' => [ 'data' => $product ] ] );
        $mockCart->shouldReceive( 'set_quantity' )->with( 'key_xyz', 3 )->once()->andReturn( true );
        $mockCart->shouldReceive( 'get_cart_total' )->andReturn( '$177.00' );
        Functions\when( 'WC' )->justReturn( (object) [ 'cart' => $mockCart ] );

        $result = $this->tools()->execute( 'update_cart_quantity', [ 'cart_item_key' => 'key_xyz', 'quantity' => 3 ] );

        $this->assertTrue( $result['success'] );
        $this->assertStringContainsString( 'Jeans', $result['message'] );
        $this->assertStringContainsString( '3', $result['message'] );
        $this->assertSame( '$177.00', $result['new_total'] );
        // No threshold configured => no free-shipping nudge.
        $this->assertArrayNotHasKey( 'free_shipping', $result );
    }

    public function test_update_cart_quantity_requires_a_key(): void {
        Functions\when( 'WC' )->justReturn( (object) [ 'cart' => Mockery::mock( WC_Cart::class ) ] );

        $result = $this->tools()->execute( 'update_cart_quantity', [ 'quantity' => 2 ] );

        $this->assertFalse( $result['success'] );
        $this->assertArrayHasKey( 'error', $result );
    }

    public function test_update_cart_quantity_errors_for_unknown_key(): void {
        $mockCart = Mockery::mock( WC_Cart::class );
        $mockCart->shouldReceive( 'get_cart' )->andReturn( [] );
        Functions\when( 'WC' )->justReturn( (object) [ 'cart' => $mockCart ] );

        $result = $this->tools()->execute( 'update_cart_quantity', [ 'cart_item_key' => 'nope', 'quantity' => 2 ] );

        $this->assertFalse( $result['success'] );
        $this->assertArrayNotHasKey( 'new_total', $result );
    }

    public function test_update_cart_quantity_honest_message_when_not_enough_stock(): void {
        $product = Mockery::mock( WC_Product::class );
        $product->shouldReceive( 'get_name' )->andReturn( 'Jeans' );
        $product->shouldReceive( 'has_enough_stock' )->with( 9 )->andReturn( false );
        $product->shouldReceive( 'get_stock_quantity' )->andReturn( 4 );

        $mockCart = Mockery::mock( WC_Cart::class );
        $mockCart->shouldReceive( 'get_cart' )->andReturn( [ 'key_xyz' => [ 'data' => $product ] ] );
        $mockCart->shouldNotReceive( 'set_quantity' );
        Functions\when( 'WC' )->justReturn( (object) [ 'cart' => $mockCart ] );

        $result = $this->tools()->execute( 'update_cart_quantity', [ 'cart_item_key' => 'key_xyz', 'quantity' => 9 ] );

        $this->assertFalse( $result['success'] );
        $this->assertStringContainsString( '4', $result['error'] );
    }

    public function test_update_cart_quantity_surfaces_free_shipping_progress(): void {
        $product = $this->mockProduct( 5, 'Jeans', '59' );
        $mockCart = Mockery::mock( WC_Cart::class );
        $mockCart->shouldReceive( 'get_cart' )->andReturn( [ 'key_xyz' => [ 'data' => $product ] ] );
        $mockCart->shouldReceive( 'set_quantity' )->with( 'key_xyz', 1 )->once()->andReturn( true );
        $mockCart->shouldReceive( 'get_cart_total' )->andReturn( '$59.00' );
        $mockCart->shouldReceive( 'get_cart_contents_total' )->andReturn( 59.0 );
        Functions\when( 'WC' )->justReturn( (object) [ 'cart' => $mockCart ] );
        Functions\when( 'get_option' )->alias(
            fn( $k, $d = '' ) => 'fahad_ai_free_shipping_threshold' === $k ? 75.0 : $d
        );

        $result = $this->tools()->execute( 'update_cart_quantity', [ 'cart_item_key' => 'key_xyz', 'quantity' => 1 ] );

        $this->assertArrayHasKey( 'free_shipping', $result );
        $this->assertEqualsWithDelta( 16.0, $result['free_shipping']['remaining'], 0.001 );
    }

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
        // No threshold configured (get_option default 0) => no free-shipping nudge.
        $this->assertArrayNotHasKey( 'free_shipping', $result );
    }

    public function test_remove_from_cart_resurfaces_free_shipping_progress(): void {
        // Removing an item can drop the cart below the free-shipping bar; the assistant must be
        // able to say "you're now $X away from free shipping" so the shopper can re-add.
        $product  = $this->mockProduct( 5, 'Jeans', '59' );
        $mockCart = Mockery::mock( WC_Cart::class );
        $mockCart->shouldReceive( 'get_cart' )->andReturn( [ 'key_xyz' => [ 'data' => $product ] ] );
        $mockCart->shouldReceive( 'remove_cart_item' )->with( 'key_xyz' )->andReturn( true );
        $mockCart->shouldReceive( 'get_cart_total' )->andReturn( '$41.00' );
        $mockCart->shouldReceive( 'get_cart_contents_total' )->andReturn( 41.0 );
        Functions\when( 'WC' )->justReturn( (object) [ 'cart' => $mockCart ] );
        Functions\when( 'get_option' )->alias(
            fn( $k, $d = '' ) => 'fahad_ai_free_shipping_threshold' === $k ? 50.0 : $d
        );

        $result = $this->tools()->execute( 'remove_from_cart', [ 'cart_item_key' => 'key_xyz' ] );

        $this->assertArrayHasKey( 'free_shipping', $result );
        $this->assertSame( 50.0, $result['free_shipping']['threshold'] );
        $this->assertEqualsWithDelta( 9.0, $result['free_shipping']['remaining'], 0.001 );
        $this->assertFalse( $result['free_shipping']['qualified'] );
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
        $p->shouldReceive( 'get_total_sales' )->andReturn( 0 )->byDefault();
        $p->shouldReceive( 'has_enough_stock' )->andReturn( true )->byDefault();
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
        $p->shouldReceive( 'get_total_sales' )->andReturn( 0 )->byDefault();
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
        $v->shouldReceive( 'has_enough_stock' )->andReturn( true )->byDefault();
        $v->shouldReceive( 'get_stock_quantity' )->andReturn( 10 )->byDefault();
        $v->shouldReceive( 'is_visible' )->andReturn( true )->byDefault();
        $v->shouldReceive( 'get_type' )->andReturn( 'variation' );
        $v->shouldReceive( 'is_type' )->with( 'variable' )->andReturn( false );
        $v->shouldReceive( 'get_parent_id' )->andReturn( $parentId )->byDefault();
        return $v;
    }
}
