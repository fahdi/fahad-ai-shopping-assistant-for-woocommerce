<?php
/**
 * Unit tests for Fahad_AI_Bundle_Tools (issue #57: curated bundles / "complete the look").
 *
 * Red → Green → Refactor. Conventions mirror RecommendationToolsTest /
 * ComparisonToolsTest: WP/WC functions mocked via Brain\Monkey; WC objects via
 * Mockery; singletons reset via reflection (never setAccessible(), a deprecated
 * no-op on PHP 8.5); the registry's static pack-provider list snapshotted in setUp
 * and restored in tearDown so this suite neither inherits another suite's packs nor
 * leaks its own.
 *
 * get_bundle is NOT a built-in, it ships as a drop-in feature pack that
 * self-registers a provider via Fahad_AI_Tool_Registry::register_pack() at file
 * load. Every test registers the bundle pack's REAL provider through
 * register_pack(), then dispatches through Fahad_AI_Tool_Registry::instance()->dispatch()
 *, so the production registration + merge + dispatch path is what is under test.
 *
 * The honesty invariants the issue demands are asserted directly: the combined
 * price equals the REAL sum of item prices; a discount is surfaced ONLY when the
 * items are genuinely on sale (regular total > combined), never fabricated;
 * out-of-stock items are flagged/skipped; a stated budget is respected (trim to fit
 * or refuse); the bundle is always presented as an OPTIONAL suggestion.
 */

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;

class BundleToolsTest extends TestCase {

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
        // product formatter the bundle tool reuses can run against mocked products.
        Functions\stubs( [
            'absint'              => fn( $n ) => abs( (int) $n ),
            'sanitize_text_field' => fn( $s ) => $s,
            // Registry get_tools() reads the merchant tool-gating option (issue #56);
            // default (no disabled tools) so dispatch()/specs() are unaffected.
            'get_option'          => fn( $key, $default = '' ) => $default,
            'wp_json_encode'      => fn( $d ) => json_encode( $d ),
            'wc_price'            => fn( $p ) => '$' . number_format( (float) $p, 2 ),
            'wp_strip_all_tags'   => fn( $s ) => strip_tags( (string) $s ),
            'wp_get_attachment_image_url' => fn() => '',
            'wc_placeholder_img_src'      => fn() => 'http://example.com/placeholder.png',
            'get_permalink'       => fn( $id ) => 'http://example.com/?p=' . $id,
        ] );
    }

    protected function tearDown(): void {
        ( new ReflectionProperty( Fahad_AI_Tool_Registry::class, 'pack_providers' ) )->setValue( null, $this->pack_snapshot );
        Monkey\tearDown();
        parent::tearDown();
    }

    /**
     * Fresh registry whose built tool list includes the bundle tool.
     *
     * Resets the Tools + registry singletons, then registers the bundle pack's REAL
     * provider via register_pack(), exactly what the pack's file-scope
     * self-registration does in production. Registered explicitly (after clearing
     * the static list) so the test is hermetic and order-independent.
     */
    private function registry(): Fahad_AI_Tool_Registry {
        ( new ReflectionProperty( Fahad_AI_Tools::class, 'instance' ) )->setValue( null, null );
        ( new ReflectionProperty( Fahad_AI_Tool_Registry::class, 'instance' ) )->setValue( null, null );

        Fahad_AI_Tool_Registry::reset_packs();
        Fahad_AI_Tool_Registry::register_pack( [ 'Fahad_AI_Bundle_Tools', 'register' ] );

        return Fahad_AI_Tool_Registry::instance();
    }

    /**
     * Product mock the bundle tool reads.
     *
     * Defaults to a buyable, visible, in-stock SIMPLE product with no relations.
     * $overrides tweaks behaviour:
     *   - type           : product type string (default 'simple'; 'grouped' for a container)
     *   - children       : grouped-product child ids (get_children())
     *   - cross_sell_ids : cross-sell relation ids (get_cross_sell_ids())
     *   - regular_price  : regular price (defaults to $price; set higher than the
     *                      active price to model a genuine sale)
     *   - on_sale        : whether the product is on sale (drives sale_price surfacing)
     *   - in_stock       : stock state (default true)
     *   - visible        : visibility (default true)
     */
    private function mockProduct( int $id, string $name, string $price, array $overrides = [] ): WC_Product {
        $type     = $overrides['type'] ?? 'simple';
        $regular  = (string) ( $overrides['regular_price'] ?? $price );
        $on_sale  = (bool) ( $overrides['on_sale'] ?? false );

        $p = Mockery::mock( WC_Product::class );
        $p->shouldReceive( 'get_id' )->andReturn( $id );
        $p->shouldReceive( 'get_name' )->andReturn( $name );
        $p->shouldReceive( 'get_price' )->andReturn( $price );
        $p->shouldReceive( 'get_regular_price' )->andReturn( $regular );
        $p->shouldReceive( 'get_sale_price' )->andReturn( $on_sale ? $price : '' );
        $p->shouldReceive( 'is_on_sale' )->andReturn( $on_sale );
        $p->shouldReceive( 'is_visible' )->andReturn( $overrides['visible'] ?? true );
        $p->shouldReceive( 'is_in_stock' )->andReturn( $overrides['in_stock'] ?? true );
        $p->shouldReceive( 'get_short_description' )->andReturn( '' );
        $p->shouldReceive( 'get_image_id' )->andReturn( 0 );
        $p->shouldReceive( 'get_average_rating' )->andReturn( '0' );
        $p->shouldReceive( 'get_review_count' )->andReturn( 0 );
        $p->shouldReceive( 'get_type' )->andReturn( $type );
        $p->shouldReceive( 'is_type' )->andReturnUsing( fn( $t ) => $t === $type );
        $p->shouldReceive( 'get_children' )->andReturn( $overrides['children'] ?? [] );
        $p->shouldReceive( 'get_cross_sell_ids' )->andReturn( $overrides['cross_sell_ids'] ?? [] );
        $p->shouldReceive( 'get_upsell_ids' )->andReturn( [] );
        return $p;
    }

    /** wc_get_product lookup over an id => mock map (false for unknown ids). */
    private function stubLookup( array $map ): void {
        Functions\when( 'wc_get_product' )->alias( fn( $id ) => $map[ (int) $id ] ?? false );
    }

    // ── registration ────────────────────────────────────────────────────────────

    public function test_bundle_tool_is_registered_via_register_pack(): void {
        $names = array_column( $this->registry()->specs(), 'name' );

        $this->assertContains( 'get_bundle', $names );
        // Additive, the six built-ins remain.
        $this->assertContains( 'search_products', $names );
    }

    public function test_bundle_tool_spec_never_leaks_a_callback(): void {
        $specs = array_column( $this->registry()->specs(), null, 'name' );

        $this->assertArrayHasKey( 'get_bundle', $specs );
        $this->assertArrayNotHasKey( 'callback', $specs['get_bundle'] );
        $this->assertArrayHasKey( 'description', $specs['get_bundle'] );
        $this->assertSame( 'object', $specs['get_bundle']['parameters']['type'] );
        $this->assertArrayHasKey( 'properties', $specs['get_bundle']['parameters'] );
        // product_id is the one required parameter.
        $this->assertContains( 'product_id', $specs['get_bundle']['parameters']['required'] ?? [] );
    }

    public function test_bundle_tool_is_not_personal(): void {
        // Operates on the shared catalog only, so it must NOT be login-gated, a
        // guest can ask "what goes with this?". (Private members are
        // reflection-accessible by default since PHP 8.1, so no setAccessible().)
        $map = ( new ReflectionMethod( Fahad_AI_Tool_Registry::class, 'get_tools' ) )->invoke( $this->registry() );

        $this->assertArrayHasKey( 'get_bundle', $map );
        $this->assertEmpty( $map['get_bundle']['personal'] ?? null );
    }

    // ── combined price == real sum ────────────────────────────────────────────────

    public function test_combined_price_equals_the_real_sum_of_item_prices(): void {
        // Anchor + two cross-sell items; combined price must be the exact sum of the
        // three real item prices, nothing rounded away or invented.
        $anchor = $this->mockProduct( 1, 'Jacket', '100.00', [ 'cross_sell_ids' => [ 2, 3 ] ] );
        $shirt  = $this->mockProduct( 2, 'Shirt', '40.00' );
        $belt   = $this->mockProduct( 3, 'Belt', '25.50' );
        $this->stubLookup( [ 1 => $anchor, 2 => $shirt, 3 => $belt ] );

        $result = $this->registry()->dispatch( 'get_bundle', [ 'product_id' => 1 ] );

        $this->assertCount( 3, $result['products'] );
        // Sum of the real prices: 100 + 40 + 25.50.
        $this->assertEqualsWithDelta( 165.50, $result['combined_price'], 0.001 );
        // The reported combined price must equal the literal sum of the surfaced item prices.
        $sum = array_sum( array_map( static fn( $i ) => (float) $i['price_raw'], $result['products'] ) );
        $this->assertEqualsWithDelta( $sum, $result['combined_price'], 0.001 );
    }

    public function test_grouped_product_children_form_the_bundle(): void {
        // A grouped product is a CONTAINER: its children are the bundle items, and
        // the grouped product itself is not a sellable line.
        $group = $this->mockProduct( 1, 'Camera Kit', '0', [ 'type' => 'grouped', 'children' => [ 2, 3 ] ] );
        $body  = $this->mockProduct( 2, 'Body', '500.00' );
        $lens  = $this->mockProduct( 3, 'Lens', '300.00' );
        $this->stubLookup( [ 1 => $group, 2 => $body, 3 => $lens ] );

        $result = $this->registry()->dispatch( 'get_bundle', [ 'product_id' => 1 ] );

        $ids = array_column( $result['products'], 'id' );
        $this->assertSame( [ 2, 3 ], $ids );
        $this->assertNotContains( 1, $ids, 'the grouped container is not itself a bundle item' );
        $this->assertEqualsWithDelta( 800.00, $result['combined_price'], 0.001 );
    }

    public function test_anchor_is_included_and_not_duplicated_by_its_own_cross_sells(): void {
        // A (defensive) self-referential cross-sell must not list the anchor twice.
        $anchor = $this->mockProduct( 1, 'Jacket', '100.00', [ 'cross_sell_ids' => [ 1, 2 ] ] );
        $shirt  = $this->mockProduct( 2, 'Shirt', '40.00' );
        $this->stubLookup( [ 1 => $anchor, 2 => $shirt ] );

        $result = $this->registry()->dispatch( 'get_bundle', [ 'product_id' => 1 ] );

        $ids = array_column( $result['products'], 'id' );
        $this->assertSame( [ 1, 2 ], $ids );
        $this->assertEqualsWithDelta( 140.00, $result['combined_price'], 0.001 );
    }

    // ── genuine discount only; never fabricated ───────────────────────────────────

    public function test_no_savings_reported_when_nothing_is_on_sale(): void {
        // Honesty invariant: when every item is at its regular price there is NO
        // bundle discount. The tool must not invent one, savings is zero/absent and
        // the combined price equals the regular total.
        $anchor = $this->mockProduct( 1, 'Jacket', '100.00', [ 'cross_sell_ids' => [ 2 ] ] );
        $shirt  = $this->mockProduct( 2, 'Shirt', '40.00' );
        $this->stubLookup( [ 1 => $anchor, 2 => $shirt ] );

        $result = $this->registry()->dispatch( 'get_bundle', [ 'product_id' => 1 ] );

        $this->assertEqualsWithDelta( 140.00, $result['combined_price'], 0.001 );
        $this->assertEqualsWithDelta( 140.00, $result['regular_price'], 0.001 );
        $this->assertSame( 0.0, (float) ( $result['savings'] ?? 0 ) );
        $this->assertFalse( $result['has_discount'] );
    }

    public function test_genuine_discount_is_reported_when_items_are_on_sale(): void {
        // A REAL discount exists only because items are individually on sale: the
        // combined (active) price is below the regular total. Savings = the genuine
        // difference, grounded in the products' own sale prices.
        $anchor = $this->mockProduct( 1, 'Jacket', '80.00', [ 'cross_sell_ids' => [ 2 ], 'regular_price' => '100.00', 'on_sale' => true ] );
        $shirt  = $this->mockProduct( 2, 'Shirt', '30.00', [ 'regular_price' => '40.00', 'on_sale' => true ] );
        $this->stubLookup( [ 1 => $anchor, 2 => $shirt ] );

        $result = $this->registry()->dispatch( 'get_bundle', [ 'product_id' => 1 ] );

        // Active sum 80 + 30 = 110; regular sum 100 + 40 = 140; genuine saving 30.
        $this->assertEqualsWithDelta( 110.00, $result['combined_price'], 0.001 );
        $this->assertEqualsWithDelta( 140.00, $result['regular_price'], 0.001 );
        $this->assertEqualsWithDelta( 30.00, $result['savings'], 0.001 );
        $this->assertTrue( $result['has_discount'] );
    }

    // ── per-item stock respected ──────────────────────────────────────────────────

    public function test_out_of_stock_item_is_skipped_and_flagged(): void {
        // An out-of-stock complementary item cannot be bought: it must be excluded
        // from the bundle (and its price excluded from the combined total) and
        // reported as unavailable so the offer is honest about what is in it.
        $anchor = $this->mockProduct( 1, 'Jacket', '100.00', [ 'cross_sell_ids' => [ 2, 3 ] ] );
        $shirt  = $this->mockProduct( 2, 'Shirt', '40.00' );
        $oos    = $this->mockProduct( 3, 'Sold Out Belt', '25.00', [ 'in_stock' => false ] );
        $this->stubLookup( [ 1 => $anchor, 2 => $shirt, 3 => $oos ] );

        $result = $this->registry()->dispatch( 'get_bundle', [ 'product_id' => 1 ] );

        $ids = array_column( $result['products'], 'id' );
        $this->assertSame( [ 1, 2 ], $ids );
        // Combined price excludes the out-of-stock item.
        $this->assertEqualsWithDelta( 140.00, $result['combined_price'], 0.001 );
        // The unavailable item is reported, not silently dropped.
        $this->assertNotEmpty( $result['unavailable'] );
        $this->assertContains( 3, array_column( $result['unavailable'], 'id' ) );
    }

    public function test_non_visible_item_is_skipped(): void {
        $anchor = $this->mockProduct( 1, 'Jacket', '100.00', [ 'cross_sell_ids' => [ 2, 3 ] ] );
        $shirt  = $this->mockProduct( 2, 'Shirt', '40.00' );
        $hidden = $this->mockProduct( 3, 'Hidden', '25.00', [ 'visible' => false ] );
        $this->stubLookup( [ 1 => $anchor, 2 => $shirt, 3 => $hidden ] );

        $result = $this->registry()->dispatch( 'get_bundle', [ 'product_id' => 1 ] );

        $ids = array_column( $result['products'], 'id' );
        $this->assertSame( [ 1, 2 ], $ids );
        $this->assertEqualsWithDelta( 140.00, $result['combined_price'], 0.001 );
    }

    public function test_out_of_stock_anchor_yields_graceful_empty(): void {
        // If the thing being "completed" cannot itself be bought, there is no honest
        // bundle to offer.
        $anchor = $this->mockProduct( 1, 'Jacket', '100.00', [ 'in_stock' => false, 'cross_sell_ids' => [ 2 ] ] );
        $shirt  = $this->mockProduct( 2, 'Shirt', '40.00' );
        $this->stubLookup( [ 1 => $anchor, 2 => $shirt ] );

        $result = $this->registry()->dispatch( 'get_bundle', [ 'product_id' => 1 ] );

        $this->assertSame( 0, $result['found'] );
        $this->assertSame( [], $result['products'] );
        $this->assertArrayHasKey( 'message', $result );
    }

    // ── budget respected ──────────────────────────────────────────────────────────

    public function test_bundle_within_budget_is_returned_whole(): void {
        $anchor = $this->mockProduct( 1, 'Jacket', '100.00', [ 'cross_sell_ids' => [ 2 ] ] );
        $shirt  = $this->mockProduct( 2, 'Shirt', '40.00' );
        $this->stubLookup( [ 1 => $anchor, 2 => $shirt ] );

        $result = $this->registry()->dispatch( 'get_bundle', [ 'product_id' => 1, 'max_price' => 200 ] );

        $this->assertCount( 2, $result['products'] );
        $this->assertEqualsWithDelta( 140.00, $result['combined_price'], 0.001 );
        $this->assertLessThanOrEqual( 200, $result['combined_price'] );
        $this->assertFalse( $result['trimmed'] );
    }

    public function test_over_budget_bundle_is_trimmed_to_fit_and_says_so(): void {
        // A stated budget must be respected: rather than proposing a bundle that
        // exceeds it, the tool trims the lowest-priority complementary items until
        // the combined price fits, and discloses that it did.
        $anchor = $this->mockProduct( 1, 'Jacket', '100.00', [ 'cross_sell_ids' => [ 2, 3 ] ] );
        $shirt  = $this->mockProduct( 2, 'Shirt', '40.00' );
        $belt   = $this->mockProduct( 3, 'Belt', '30.00' );
        $this->stubLookup( [ 1 => $anchor, 2 => $shirt, 3 => $belt ] );

        // Full bundle is 170; budget 150 forces dropping the last item (belt, 30) → 140.
        $result = $this->registry()->dispatch( 'get_bundle', [ 'product_id' => 1, 'max_price' => 150 ] );

        $this->assertLessThanOrEqual( 150, $result['combined_price'] );
        $this->assertTrue( $result['trimmed'] );
        $ids = array_column( $result['products'], 'id' );
        $this->assertContains( 1, $ids, 'the anchor is kept' );
        $this->assertNotContains( 3, $ids, 'the priciest trimmed item is dropped to fit budget' );
        $this->assertArrayHasKey( 'message', $result );
    }

    public function test_budget_too_small_for_even_the_anchor_refuses_the_bundle(): void {
        // If the base item alone already exceeds the budget, there is no bundle that
        // fits, the tool must NOT propose one over budget.
        $anchor = $this->mockProduct( 1, 'Jacket', '100.00', [ 'cross_sell_ids' => [ 2 ] ] );
        $shirt  = $this->mockProduct( 2, 'Shirt', '40.00' );
        $this->stubLookup( [ 1 => $anchor, 2 => $shirt ] );

        $result = $this->registry()->dispatch( 'get_bundle', [ 'product_id' => 1, 'max_price' => 50 ] );

        $this->assertSame( 0, $result['found'] );
        $this->assertSame( [], $result['products'] );
        $this->assertFalse( $result['fits_budget'] );
        $this->assertArrayHasKey( 'message', $result );
    }

    // ── presented as an optional suggestion ───────────────────────────────────────

    public function test_bundle_is_flagged_optional(): void {
        // The whole feature is a DISCLOSED, optional upsell. The result must carry an
        // explicit optional signal the model surfaces (never presented as required).
        $anchor = $this->mockProduct( 1, 'Jacket', '100.00', [ 'cross_sell_ids' => [ 2 ] ] );
        $shirt  = $this->mockProduct( 2, 'Shirt', '40.00' );
        $this->stubLookup( [ 1 => $anchor, 2 => $shirt ] );

        $result = $this->registry()->dispatch( 'get_bundle', [ 'product_id' => 1 ] );

        $this->assertArrayHasKey( 'optional', $result );
        $this->assertTrue( $result['optional'] );
    }

    public function test_bundle_items_render_as_cards_not_a_comparison(): void {
        // The items ride under the canonical `products` key so the API handler's
        // convention-based emitter renders them as ordinary product cards with no
        // shared-file edits, and the result carries NO `attributes` key, so it is
        // never mistaken for a comparison table (which is products[] + attributes[]).
        $anchor = $this->mockProduct( 1, 'Jacket', '100.00', [ 'cross_sell_ids' => [ 2 ] ] );
        $shirt  = $this->mockProduct( 2, 'Shirt', '40.00' );
        $this->stubLookup( [ 1 => $anchor, 2 => $shirt ] );

        $result = $this->registry()->dispatch( 'get_bundle', [ 'product_id' => 1 ] );

        $this->assertArrayHasKey( 'products', $result );
        $this->assertArrayNotHasKey( 'attributes', $result );
        // Each item carries the card-shaped summary keys (so it renders as a card).
        foreach ( [ 'id', 'name', 'price', 'in_stock', 'image', 'url' ] as $key ) {
            $this->assertArrayHasKey( $key, $result['products'][0], "Item summary missing key: {$key}" );
        }
    }

    // ── empty / degenerate states ─────────────────────────────────────────────────

    public function test_no_complementary_items_yields_empty_bundle(): void {
        // A product with no grouped children and no cross-sells has nothing to bundle
        // with, a one-item "bundle" is not a bundle, so return a graceful empty state.
        $anchor = $this->mockProduct( 1, 'Lonely Jacket', '100.00' );
        $this->stubLookup( [ 1 => $anchor ] );

        $result = $this->registry()->dispatch( 'get_bundle', [ 'product_id' => 1 ] );

        $this->assertSame( 0, $result['found'] );
        $this->assertSame( [], $result['products'] );
        $this->assertArrayHasKey( 'message', $result );
    }

    public function test_unknown_product_yields_graceful_empty(): void {
        Functions\when( 'wc_get_product' )->justReturn( false );

        $result = $this->registry()->dispatch( 'get_bundle', [ 'product_id' => 999 ] );

        $this->assertSame( 0, $result['found'] );
        $this->assertSame( [], $result['products'] );
        $this->assertArrayHasKey( 'message', $result );
    }

    public function test_missing_product_id_returns_error(): void {
        $result = $this->registry()->dispatch( 'get_bundle', [] );

        $this->assertArrayHasKey( 'error', $result );
        $this->assertArrayNotHasKey( 'products', $result );
    }

    public function test_bundle_caps_number_of_items(): void {
        // A long cross-sell list must not produce an unwieldy bundle; the item count
        // is capped (default max). Anchor + many cross-sells → bounded set.
        $cross_ids = range( 100, 120 );
        $anchor    = $this->mockProduct( 1, 'Anchor', '10.00', [ 'cross_sell_ids' => $cross_ids ] );
        $map       = [ 1 => $anchor ];
        foreach ( $cross_ids as $cid ) {
            $map[ $cid ] = $this->mockProduct( $cid, "Item {$cid}", '5.00' );
        }
        $this->stubLookup( $map );

        $result = $this->registry()->dispatch( 'get_bundle', [ 'product_id' => 1 ] );

        $this->assertLessThanOrEqual( 8, count( $result['products'] ) );
    }
}
