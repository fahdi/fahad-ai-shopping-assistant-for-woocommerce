<?php
/**
 * Supplemental line-coverage tests for Fahad_AI_Recommendation_Tools (issue #16).
 *
 * Companion to RecommendationToolsTest: this suite drives the remaining branches
 * of the free-text search fallback (Fahad_AI_Recommendation_Tools::from_search)
 * and the empty/non-numeric price gate in within_budget that the primary suite,
 * which uses numerically-priced products and a relation-based happy path, does not
 * reach:
 *
 *   - from_search hitting its $limit mid-iteration (the inner break),
 *   - from_search skipping a non-buyable (out-of-stock / not-visible) hit (continue),
 *   - from_search yielding zero buyable hits → the canonical empty_state(),
 *   - within_budget treating a product whose price is '' / non-numeric as in-budget
 *     even though a max_price WAS stated (the price gate cannot meaningfully exclude
 *     a product with no usable price).
 *
 * Conventions mirror RecommendationToolsTest exactly: WP/WC functions are stubbed
 * via Brain\Monkey; WC objects via Mockery; the registry's static pack-provider
 * list is snapshotted in setUp and restored in tearDown so this suite neither
 * inherits another suite's packs nor leaks its own; every test registers the
 * pack's REAL provider through register_pack() and dispatches through the live
 * Fahad_AI_Tool_Registry, so the production registration + merge + dispatch path
 * is what is exercised.
 */

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;

class CoverageRecommendationToolsTest extends TestCase {

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

        // Tool-layer stubs (mirror RecommendationToolsTest::setUp) so the shared
        // product formatter the recommendation tools reuse can run against mocks.
        Functions\stubs( [
            'absint'              => fn( $n ) => abs( (int) $n ),
            'sanitize_text_field' => fn( $s ) => $s,
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
     * pack's REAL provider via register_pack() — exactly the pack's file-scope
     * self-registration — so the test is hermetic and order-independent.
     */
    private function registry(): Fahad_AI_Tool_Registry {
        ( new ReflectionProperty( Fahad_AI_Tools::class, 'instance' ) )->setValue( null, null );
        ( new ReflectionProperty( Fahad_AI_Tool_Registry::class, 'instance' ) )->setValue( null, null );

        Fahad_AI_Tool_Registry::reset_packs();
        Fahad_AI_Tool_Registry::register_pack( [ 'Fahad_AI_Recommendation_Tools', 'register' ] );

        return Fahad_AI_Tool_Registry::instance();
    }

    /** Default "happy path" product mock (mirrors RecommendationToolsTest::mockProduct). */
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

    // ── from_search: inner $limit break (source line ~269) ──────────────────────

    public function test_free_text_search_caps_results_at_limit_breaking_mid_loop(): void {
        // The catalog search returns MORE buyable products than the requested limit.
        // from_search must stop appending once it has $limit products — exercising the
        // inner `break` rather than running the whole result set.
        $hits = [
            $this->mockProduct( 21, 'Boot A', '50.00' ),
            $this->mockProduct( 22, 'Boot B', '55.00' ),
            $this->mockProduct( 23, 'Boot C', '60.00' ),
            $this->mockProduct( 24, 'Boot D', '65.00' ), // should never be reached
        ];

        Functions\expect( 'wc_get_products' )
            ->once()
            ->andReturnUsing( function ( array $args ) use ( $hits ) {
                $this->assertSame( 'hiking', $args['s'] );
                return $hits;
            } );

        $result = $this->registry()->dispatch( 'get_recommendations', [
            'need'  => 'hiking',
            'limit' => 3,
        ] );

        $this->assertSame( 3, $result['found'] );
        $this->assertCount( 3, $result['products'] );
        $ids = array_column( $result['products'], 'id' );
        $this->assertSame( [ 21, 22, 23 ], $ids );
        // The fourth hit was past the cap — the break stopped iteration before it.
        $this->assertNotContains( 24, $ids );
    }

    // ── from_search: skip non-buyable hit (source line ~272) ────────────────────

    public function test_free_text_search_skips_out_of_stock_hit(): void {
        // A search hit that is out of stock cannot be bought, so from_search must
        // `continue` past it and surface only the buyable hit.
        $oos = $this->mockProduct( 31, 'Sold Out Boot', '70.00' );
        $oos->shouldReceive( 'is_in_stock' )->andReturn( false );
        $ok = $this->mockProduct( 32, 'In Stock Boot', '75.00' );

        Functions\when( 'wc_get_products' )->justReturn( [ $oos, $ok ] );

        $result = $this->registry()->dispatch( 'get_recommendations', [ 'need' => 'hiking' ] );

        $this->assertSame( 1, $result['found'] );
        $ids = array_column( $result['products'], 'id' );
        $this->assertNotContains( 31, $ids, 'out-of-stock hit must be skipped' );
        $this->assertContains( 32, $ids );
        $this->assertArrayHasKey( 'reason', $result['products'][0] );
    }

    public function test_free_text_search_skips_not_visible_hit(): void {
        // A non-visible hit (e.g. hidden from catalog) is likewise skipped.
        $hidden = $this->mockProduct( 33, 'Hidden Boot', '80.00' );
        $hidden->shouldReceive( 'is_visible' )->andReturn( false );
        $visible = $this->mockProduct( 34, 'Visible Boot', '85.00' );

        Functions\when( 'wc_get_products' )->justReturn( [ $hidden, $visible ] );

        $result = $this->registry()->dispatch( 'get_recommendations', [ 'need' => 'hiking' ] );

        $this->assertSame( 1, $result['found'] );
        $ids = array_column( $result['products'], 'id' );
        $this->assertNotContains( 33, $ids );
        $this->assertContains( 34, $ids );
    }

    public function test_free_text_search_skips_non_product_hit(): void {
        // wc_get_products can return non-WC_Product entries defensively; from_search
        // must `continue` past anything that is not a WC_Product instance.
        $ok = $this->mockProduct( 35, 'Real Boot', '90.00' );

        Functions\when( 'wc_get_products' )->justReturn( [ false, $ok ] );

        $result = $this->registry()->dispatch( 'get_recommendations', [ 'need' => 'hiking' ] );

        $this->assertSame( 1, $result['found'] );
        $this->assertSame( 35, $result['products'][0]['id'] );
    }

    // ── from_search: empty_state when no buyable hits (source line ~284) ────────

    public function test_free_text_search_returns_empty_state_when_no_buyable_hits(): void {
        // Every search hit is out of stock → no buyable product survives the filter →
        // from_search returns the canonical empty_state() (found 0, empty products,
        // a message), not a fatal or a malformed shape.
        $oos = $this->mockProduct( 41, 'Out 1', '20.00' );
        $oos->shouldReceive( 'is_in_stock' )->andReturn( false );

        Functions\when( 'wc_get_products' )->justReturn( [ $oos ] );

        $result = $this->registry()->dispatch( 'get_recommendations', [ 'need' => 'hiking' ] );

        $this->assertSame( 0, $result['found'] );
        $this->assertSame( [], $result['products'] );
        $this->assertArrayHasKey( 'message', $result );
        $this->assertNotSame( '', $result['message'] );
    }

    public function test_free_text_search_returns_empty_state_when_search_finds_nothing(): void {
        // The catalog search itself returns nothing → still the canonical empty_state.
        Functions\when( 'wc_get_products' )->justReturn( [] );

        $result = $this->registry()->dispatch( 'get_recommendations', [ 'need' => 'nonexistent' ] );

        $this->assertSame( 0, $result['found'] );
        $this->assertSame( [], $result['products'] );
        $this->assertArrayHasKey( 'message', $result );
    }

    // ── within_budget: empty / non-numeric price with a stated budget (line ~304) ─

    public function test_free_text_search_keeps_product_with_empty_price_under_budget(): void {
        // A product whose price is '' cannot be meaningfully gated by a budget, so
        // within_budget must return true (keep it) EVEN THOUGH a max_price was stated.
        // This drives the non-null max_price path into the empty-price early return —
        // the branch the relation/numeric-price happy paths never reach.
        $priceless = $this->mockProduct( 51, 'No Price Boot', '' );

        Functions\when( 'wc_get_products' )->justReturn( [ $priceless ] );

        $result = $this->registry()->dispatch( 'get_recommendations', [
            'need'      => 'hiking',
            'max_price' => 100,
        ] );

        // Kept despite the budget, because its price could not exclude it.
        $this->assertSame( 1, $result['found'] );
        $this->assertSame( 51, $result['products'][0]['id'] );
    }

    public function test_free_text_search_keeps_product_with_non_numeric_price_under_budget(): void {
        // A non-numeric price string ("N/A") is likewise un-gateable, so the product
        // is kept under a stated budget.
        $weird = $this->mockProduct( 52, 'Odd Price Boot', 'N/A' );

        Functions\when( 'wc_get_products' )->justReturn( [ $weird ] );

        $result = $this->registry()->dispatch( 'get_recommendations', [
            'need'      => 'hiking',
            'max_price' => 100,
        ] );

        $this->assertSame( 1, $result['found'] );
        $this->assertSame( 52, $result['products'][0]['id'] );
    }
}
