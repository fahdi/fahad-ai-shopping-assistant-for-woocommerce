<?php
/**
 * Unit tests for Fahad_AI_Recommendation_Tools (issue #16: recommendations & cross-sell).
 *
 * Red → Green → Refactor. Conventions mirror CatalogToolsTest: WP/WC functions
 * mocked via Brain\Monkey; WC objects via Mockery; singletons reset via reflection;
 * the registry's static pack-provider list snapshotted in setUp and restored in
 * tearDown so this suite neither inherits another suite's packs nor leaks its own.
 *
 * The two recommendation tools (get_recommendations, get_cross_sells) are NOT
 * built-ins, they ship as a drop-in feature pack that self-registers a provider
 * via Fahad_AI_Tool_Registry::register_pack() at file load. Every test registers
 * the pack's REAL provider through register_pack(), then dispatches through
 * Fahad_AI_Tool_Registry::instance()->dispatch(), so the production registration +
 * merge + dispatch path is what is under test.
 */

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;

class RecommendationToolsTest extends TestCase {

    use MockeryPHPUnitIntegration;

    /**
     * Snapshot of the registry's static pack providers, restored in tearDown.
     *
     * @var array<int, callable>
     */
    private array $pack_snapshot = [];

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();

        $this->pack_snapshot = (array) ( new ReflectionProperty( Fahad_AI_Tool_Registry::class, 'pack_providers' ) )->getValue();

        // Tool-layer stubs (mirror CatalogToolsTest::setUp) so the shared product
        // formatter the recommendation tools reuse can run against mocked products.
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
        ] );
    }

    protected function tearDown(): void {
        ( new ReflectionProperty( Fahad_AI_Tool_Registry::class, 'pack_providers' ) )->setValue( null, $this->pack_snapshot );
        Monkey\tearDown();
        parent::tearDown();
    }

    /**
     * Fresh registry whose built tool list includes the recommendation tools.
     *
     * Resets the Tools + registry singletons, then registers the recommendation
     * pack's REAL provider via register_pack(), exactly what the pack's file-scope
     * self-registration does in production. Registered explicitly (after clearing
     * the static list) so the test is hermetic and order-independent.
     */
    private function registry(): Fahad_AI_Tool_Registry {
        ( new ReflectionProperty( Fahad_AI_Tools::class, 'instance' ) )->setValue( null, null );
        ( new ReflectionProperty( Fahad_AI_Tool_Registry::class, 'instance' ) )->setValue( null, null );

        Fahad_AI_Tool_Registry::reset_packs();
        Fahad_AI_Tool_Registry::register_pack( [ 'Fahad_AI_Recommendation_Tools', 'register' ] );

        return Fahad_AI_Tool_Registry::instance();
    }

    /** Default "happy path" product mock (mirrors CatalogToolsTest::mockProduct). */
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
        $p->shouldReceive( 'get_average_rating' )->andReturn( '0' )->byDefault();
        $p->shouldReceive( 'get_review_count' )->andReturn( 0 )->byDefault();
        $p->shouldReceive( 'get_upsell_ids' )->andReturn( [] )->byDefault();
        $p->shouldReceive( 'get_cross_sell_ids' )->andReturn( [] )->byDefault();
        return $p;
    }

    /** Mock WC()->cart returning the given cross-sell product IDs. */
    private function stubCart( array $cross_sell_ids, bool $empty = false ): void {
        $cart = Mockery::mock( WC_Cart::class );
        $cart->shouldReceive( 'is_empty' )->andReturn( $empty );
        $cart->shouldReceive( 'get_cross_sells' )->andReturn( $cross_sell_ids );
        Functions\when( 'WC' )->justReturn( (object) [ 'cart' => $cart ] );
    }

    // ── registration ──────────────────────────────────────────────────────────

    public function test_recommendation_tools_are_registered_via_register_pack(): void {
        $names = array_column( $this->registry()->specs(), 'name' );

        $this->assertContains( 'get_recommendations', $names );
        $this->assertContains( 'get_cross_sells', $names );
        // They are additive, the six built-ins remain.
        $this->assertContains( 'search_products', $names );
        $this->assertCount( 8, $names );
    }

    public function test_recommendation_tool_specs_never_leak_a_callback(): void {
        $specs = array_column( $this->registry()->specs(), null, 'name' );

        foreach ( [ 'get_recommendations', 'get_cross_sells' ] as $name ) {
            $this->assertArrayHasKey( $name, $specs );
            $this->assertArrayNotHasKey( 'callback', $specs[ $name ] );
            $this->assertArrayHasKey( 'description', $specs[ $name ] );
            $this->assertSame( 'object', $specs[ $name ]['parameters']['type'] );
            $this->assertArrayHasKey( 'properties', $specs[ $name ]['parameters'] );
        }
    }

    public function test_recommendation_tools_are_not_personal(): void {
        // They operate on the shared catalog/session cart, so they must NOT be
        // login-gated, a guest can ask for suggestions. (Private members are
        // reflection-accessible by default since PHP 8.1, so no setAccessible() , 
        // which is a deprecated no-op on 8.5; mirrors CouponToolsTest.)
        $map = ( new ReflectionMethod( Fahad_AI_Tool_Registry::class, 'get_tools' ) )->invoke( $this->registry() );

        $this->assertArrayHasKey( 'get_recommendations', $map );
        $this->assertArrayHasKey( 'get_cross_sells', $map );
        $this->assertEmpty( $map['get_recommendations']['personal'] ?? null );
        $this->assertEmpty( $map['get_cross_sells']['personal'] ?? null );
    }

    // ── get_recommendations ─────────────────────────────────────────────────────

    public function test_get_recommendations_returns_upsells_first_in_card_shape(): void {
        $source = $this->mockProduct( 1, 'Source Camera', '500.00' );
        $source->shouldReceive( 'get_upsell_ids' )->andReturn( [ 2 ] );
        $upsell = $this->mockProduct( 2, 'Pro Camera', '900.00' );

        Functions\when( 'wc_get_product' )->alias(
            fn( $id ) => [ 1 => $source, 2 => $upsell ][ (int) $id ] ?? false
        );
        // No related products needed; upsell alone satisfies the request.
        Functions\when( 'wc_get_related_products' )->justReturn( [] );

        $result = $this->registry()->dispatch( 'get_recommendations', [ 'product_id' => 1 ] );

        $this->assertSame( 1, $result['found'] );
        $this->assertCount( 1, $result['products'] );
        $this->assertSame( 2, $result['products'][0]['id'] );
        $this->assertSame( 'Pro Camera', $result['products'][0]['name'] );
        // Card-shaped summary keys (so they render as product cards).
        foreach ( [ 'id', 'name', 'price', 'regular_price', 'sale_price', 'on_sale', 'in_stock', 'short_description', 'image', 'url' ] as $key ) {
            $this->assertArrayHasKey( $key, $result['products'][0], "Summary missing key: {$key}" );
        }
        // Each recommendation carries a short rationale the model can surface.
        $this->assertArrayHasKey( 'reason', $result['products'][0] );
        $this->assertNotSame( '', $result['products'][0]['reason'] );
    }

    public function test_get_recommendations_falls_back_to_related_products(): void {
        $source = $this->mockProduct( 1, 'Source Shoe', '80.00' );
        $source->shouldReceive( 'get_upsell_ids' )->andReturn( [] ); // no curated upsells
        $related = $this->mockProduct( 3, 'Related Sock', '12.00' );

        Functions\when( 'wc_get_product' )->alias(
            fn( $id ) => [ 1 => $source, 3 => $related ][ (int) $id ] ?? false
        );
        // wc_get_related_products returns IDs.
        Functions\expect( 'wc_get_related_products' )
            ->once()
            ->andReturnUsing( function ( $product_id ) {
                $this->assertSame( 1, $product_id );
                return [ 3 ];
            } );

        $result = $this->registry()->dispatch( 'get_recommendations', [ 'product_id' => 1 ] );

        $this->assertSame( 1, $result['found'] );
        $this->assertSame( 3, $result['products'][0]['id'] );
        $this->assertArrayHasKey( 'reason', $result['products'][0] );
    }

    public function test_get_recommendations_excludes_the_source_product(): void {
        // A relation list that (defensively) includes the source id must not echo
        // the source product back as its own recommendation.
        $source = $this->mockProduct( 1, 'Source', '50.00' );
        $source->shouldReceive( 'get_upsell_ids' )->andReturn( [ 1, 4 ] );
        $other = $this->mockProduct( 4, 'Other', '60.00' );

        Functions\when( 'wc_get_product' )->alias(
            fn( $id ) => [ 1 => $source, 4 => $other ][ (int) $id ] ?? false
        );
        Functions\when( 'wc_get_related_products' )->justReturn( [] );

        $result = $this->registry()->dispatch( 'get_recommendations', [ 'product_id' => 1 ] );

        $ids = array_column( $result['products'], 'id' );
        $this->assertNotContains( 1, $ids );
        $this->assertContains( 4, $ids );
    }

    public function test_get_recommendations_dedupes_overlapping_relations(): void {
        // The same product appearing as both an upsell and a related product must
        // surface only once.
        $source = $this->mockProduct( 1, 'Source', '50.00' );
        $source->shouldReceive( 'get_upsell_ids' )->andReturn( [ 5 ] );
        $dup = $this->mockProduct( 5, 'Dup', '70.00' );

        Functions\when( 'wc_get_product' )->alias(
            fn( $id ) => [ 1 => $source, 5 => $dup ][ (int) $id ] ?? false
        );
        Functions\when( 'wc_get_related_products' )->justReturn( [ 5 ] );

        $result = $this->registry()->dispatch( 'get_recommendations', [ 'product_id' => 1 ] );

        $this->assertSame( 1, $result['found'] );
        $this->assertCount( 1, $result['products'] );
        $this->assertSame( 5, $result['products'][0]['id'] );
    }

    public function test_get_recommendations_respects_max_price_budget(): void {
        // BUDGET RESPECT: items priced above max_price must be excluded.
        $source = $this->mockProduct( 1, 'Source', '50.00' );
        $source->shouldReceive( 'get_upsell_ids' )->andReturn( [ 2, 3 ] );
        $cheap = $this->mockProduct( 2, 'Within Budget', '40.00' );
        $pricey = $this->mockProduct( 3, 'Over Budget', '120.00' );

        Functions\when( 'wc_get_product' )->alias(
            fn( $id ) => [ 1 => $source, 2 => $cheap, 3 => $pricey ][ (int) $id ] ?? false
        );
        Functions\when( 'wc_get_related_products' )->justReturn( [] );

        $result = $this->registry()->dispatch( 'get_recommendations', [ 'product_id' => 1, 'max_price' => 100 ] );

        $ids = array_column( $result['products'], 'id' );
        $this->assertContains( 2, $ids, 'item within budget must be kept' );
        $this->assertNotContains( 3, $ids, 'item above max_price must be excluded' );
        $this->assertSame( 1, $result['found'] );
    }

    public function test_get_recommendations_searches_catalog_for_free_text_need(): void {
        // With no product_id but a free-text need, fall back to a catalog search so
        // the model can answer "I want something for hiking".
        $hit = $this->mockProduct( 7, 'Hiking Boot', '95.00' );
        Functions\expect( 'wc_get_products' )
            ->once()
            ->andReturnUsing( function ( array $args ) use ( $hit ) {
                $this->assertSame( 'hiking', $args['s'] );
                return [ $hit ];
            } );

        $result = $this->registry()->dispatch( 'get_recommendations', [ 'need' => 'hiking' ] );

        $this->assertSame( 1, $result['found'] );
        $this->assertSame( 7, $result['products'][0]['id'] );
        $this->assertArrayHasKey( 'reason', $result['products'][0] );
    }

    public function test_get_recommendations_applies_budget_to_free_text_search(): void {
        // A budget query ("a gift under 1000") must filter the catalog search too.
        $within = $this->mockProduct( 8, 'Nice Gift', '800.00' );
        $over   = $this->mockProduct( 9, 'Luxury Gift', '1500.00' );
        Functions\when( 'wc_get_products' )->justReturn( [ $within, $over ] );

        $result = $this->registry()->dispatch( 'get_recommendations', [ 'need' => 'gift', 'max_price' => 1000 ] );

        $ids = array_column( $result['products'], 'id' );
        $this->assertContains( 8, $ids );
        $this->assertNotContains( 9, $ids );
    }

    public function test_get_recommendations_caps_limit_at_10(): void {
        $source = $this->mockProduct( 1, 'Source', '10.00' );
        // 12 upsell ids; tool must cap the returned set at 10.
        $ids = range( 100, 111 );
        $source->shouldReceive( 'get_upsell_ids' )->andReturn( $ids );

        Functions\when( 'wc_get_product' )->alias(
            function ( $id ) use ( $source ) {
                $id = (int) $id;
                if ( 1 === $id ) {
                    return $source;
                }
                return $this->mockProduct( $id, "Item {$id}", '15.00' );
            }
        );
        Functions\when( 'wc_get_related_products' )->justReturn( [] );

        $result = $this->registry()->dispatch( 'get_recommendations', [ 'product_id' => 1, 'limit' => 999 ] );

        $this->assertLessThanOrEqual( 10, count( $result['products'] ) );
    }

    public function test_get_recommendations_empty_state_when_no_relations(): void {
        $source = $this->mockProduct( 1, 'Lonely Product', '50.00' );
        $source->shouldReceive( 'get_upsell_ids' )->andReturn( [] );

        Functions\when( 'wc_get_product' )->alias( fn( $id ) => (int) $id === 1 ? $source : false );
        Functions\when( 'wc_get_related_products' )->justReturn( [] );

        $result = $this->registry()->dispatch( 'get_recommendations', [ 'product_id' => 1 ] );

        $this->assertSame( 0, $result['found'] );
        $this->assertSame( [], $result['products'] );
        $this->assertArrayHasKey( 'message', $result );
    }

    public function test_get_recommendations_handles_unknown_product(): void {
        // Unknown product_id, no free-text need → graceful empty, not a fatal.
        Functions\when( 'wc_get_product' )->justReturn( false );
        Functions\when( 'wc_get_related_products' )->justReturn( [] );

        $result = $this->registry()->dispatch( 'get_recommendations', [ 'product_id' => 999 ] );

        $this->assertSame( 0, $result['found'] );
        $this->assertSame( [], $result['products'] );
        $this->assertArrayHasKey( 'message', $result );
    }

    public function test_get_recommendations_skips_out_of_stock_relations(): void {
        // An out-of-stock relation should not be recommended (cannot be bought).
        $source = $this->mockProduct( 1, 'Source', '50.00' );
        $source->shouldReceive( 'get_upsell_ids' )->andReturn( [ 2 ] );
        $oos = $this->mockProduct( 2, 'Sold Out', '40.00' );
        $oos->shouldReceive( 'is_in_stock' )->andReturn( false );

        Functions\when( 'wc_get_product' )->alias(
            fn( $id ) => [ 1 => $source, 2 => $oos ][ (int) $id ] ?? false
        );
        Functions\when( 'wc_get_related_products' )->justReturn( [] );

        $result = $this->registry()->dispatch( 'get_recommendations', [ 'product_id' => 1 ] );

        $this->assertSame( 0, $result['found'] );
        $this->assertSame( [], $result['products'] );
    }

    // ── get_cross_sells ───────────────────────────────────────────────────────

    public function test_get_cross_sells_returns_cart_cross_sells_in_card_shape(): void {
        $this->stubCart( [ 2, 3 ] );
        $a = $this->mockProduct( 2, 'Cross A', '15.00' );
        $b = $this->mockProduct( 3, 'Cross B', '25.00' );
        Functions\when( 'wc_get_product' )->alias(
            fn( $id ) => [ 2 => $a, 3 => $b ][ (int) $id ] ?? false
        );

        $result = $this->registry()->dispatch( 'get_cross_sells', [] );

        $this->assertSame( 2, $result['found'] );
        $this->assertCount( 2, $result['products'] );
        $this->assertSame( 2, $result['products'][0]['id'] );
        // Card-shaped (so they render as cards).
        foreach ( [ 'id', 'name', 'price', 'in_stock', 'image', 'url' ] as $key ) {
            $this->assertArrayHasKey( $key, $result['products'][0], "Summary missing key: {$key}" );
        }
    }

    public function test_get_cross_sells_signals_optional_offer(): void {
        // The whole point of cross-sell is a CLEARLY OPTIONAL post-add-to-cart offer.
        // The tool result must carry an explicit "optional" signal the model can use.
        $this->stubCart( [ 2 ] );
        Functions\when( 'wc_get_product' )->alias(
            fn( $id ) => (int) $id === 2 ? $this->mockProduct( 2, 'Add-on', '9.99' ) : false
        );

        $result = $this->registry()->dispatch( 'get_cross_sells', [] );

        $this->assertArrayHasKey( 'optional', $result );
        $this->assertTrue( $result['optional'] );
    }

    public function test_get_cross_sells_empty_when_cart_empty(): void {
        $this->stubCart( [], true );

        $result = $this->registry()->dispatch( 'get_cross_sells', [] );

        $this->assertSame( 0, $result['found'] );
        $this->assertSame( [], $result['products'] );
        $this->assertArrayHasKey( 'message', $result );
    }

    public function test_get_cross_sells_empty_when_no_cross_sells_defined(): void {
        // Non-empty cart but no cross-sells configured on its products.
        $this->stubCart( [], false );

        $result = $this->registry()->dispatch( 'get_cross_sells', [] );

        $this->assertSame( 0, $result['found'] );
        $this->assertSame( [], $result['products'] );
        $this->assertArrayHasKey( 'message', $result );
    }

    public function test_get_cross_sells_respects_max_price_budget(): void {
        $this->stubCart( [ 2, 3 ] );
        $cheap  = $this->mockProduct( 2, 'Cheap Add-on', '9.99' );
        $pricey = $this->mockProduct( 3, 'Pricey Add-on', '99.99' );
        Functions\when( 'wc_get_product' )->alias(
            fn( $id ) => [ 2 => $cheap, 3 => $pricey ][ (int) $id ] ?? false
        );

        $result = $this->registry()->dispatch( 'get_cross_sells', [ 'max_price' => 50 ] );

        $ids = array_column( $result['products'], 'id' );
        $this->assertContains( 2, $ids );
        $this->assertNotContains( 3, $ids );
    }
}
