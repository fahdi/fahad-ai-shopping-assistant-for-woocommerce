<?php
/**
 * Coverage-focused unit tests for Fahad_AI_Bundle_Tools
 * (includes/tools/class-bundle-tools.php — issue #57 curated bundles).
 *
 * Companion to BundleToolsTest: this suite drives every branch of the bundle
 * tool independently — guard clauses, helper methods (numeric_price / budget /
 * display_price / sum / unique_ids / candidate_ids), the per-item stock and
 * visibility skips, the MAX_ITEMS cap, the genuine-vs-fabricated discount split,
 * the budget trim/decline paths, and the empty-state shape — so the file's line
 * coverage holds on its own.
 *
 * Conventions mirror BundleToolsTest / RecommendationToolsTest: WP/WC functions
 * mocked via Brain\Monkey; WC_Product instances via Mockery; singletons reset via
 * reflection (no setAccessible — a deprecated no-op on PHP 8.5); the registry's
 * static pack-provider list snapshotted in setUp and restored in tearDown so this
 * suite neither inherits another suite's packs nor leaks its own. Each test
 * dispatches through the LIVE Fahad_AI_Tool_Registry so the real
 * register_pack() + merge + dispatch path is exercised.
 *
 * NOTE on the file-scope self-registration line (the final
 * Fahad_AI_Tool_Registry::register_pack( ... ) statement): it runs exactly once,
 * during the test bootstrap's glob-require of includes/tools/*.php, BEFORE pcov
 * begins recording per-test coverage. It is therefore not attributable to any
 * test and is genuinely uncoverable from a unit test (re-requiring the file would
 * re-declare a `final class` and fatal). Every other statement is covered here.
 */

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;

class CoverageBundleToolsTest extends TestCase {

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

        Functions\stubs( [
            'absint'                      => fn( $n ) => abs( (int) $n ),
            'sanitize_text_field'         => fn( $s ) => $s,
            'get_option'                  => fn( $key, $default = '' ) => $default,
            'wp_json_encode'              => fn( $d ) => json_encode( $d ),
            'wc_price'                    => fn( $p ) => '$' . number_format( (float) $p, 2 ),
            'wp_strip_all_tags'           => fn( $s ) => strip_tags( (string) $s ),
            'wp_get_attachment_image_url' => fn() => '',
            'wc_placeholder_img_src'      => fn() => 'http://example.com/placeholder.png',
            'get_permalink'               => fn( $id ) => 'http://example.com/?p=' . $id,
        ] );
    }

    protected function tearDown(): void {
        ( new ReflectionProperty( Fahad_AI_Tool_Registry::class, 'pack_providers' ) )->setValue( null, $this->pack_snapshot );
        Monkey\tearDown();
        parent::tearDown();
    }

    /**
     * Fresh registry whose built tool list includes the bundle tool. Resets the
     * Tools + registry singletons, then registers ONLY the bundle pack's real
     * provider so the suite is hermetic and order-independent.
     */
    private function registry(): Fahad_AI_Tool_Registry {
        ( new ReflectionProperty( Fahad_AI_Tools::class, 'instance' ) )->setValue( null, null );
        ( new ReflectionProperty( Fahad_AI_Tool_Registry::class, 'instance' ) )->setValue( null, null );

        Fahad_AI_Tool_Registry::reset_packs();
        Fahad_AI_Tool_Registry::register_pack( [ 'Fahad_AI_Bundle_Tools', 'register' ] );

        return Fahad_AI_Tool_Registry::instance();
    }

    /** Dispatch the bundle tool through the live registry. */
    private function getBundle( array $input ): array {
        return $this->registry()->dispatch( 'get_bundle', $input );
    }

    /**
     * Buyable, visible, in-stock SIMPLE product mock with no relations by default.
     * Overrides: type, children, cross_sell_ids, regular_price, on_sale, in_stock,
     * visible, price (the active price is the $price arg).
     */
    private function mockProduct( int $id, string $name, string $price, array $overrides = [] ): WC_Product {
        $type    = $overrides['type'] ?? 'simple';
        $regular = array_key_exists( 'regular_price', $overrides ) ? (string) $overrides['regular_price'] : (string) $price;
        $on_sale = (bool) ( $overrides['on_sale'] ?? false );

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

    // ── input guards ──────────────────────────────────────────────────────────────

    public function test_zero_product_id_returns_error_without_touching_woocommerce(): void {
        // absint( 0 ) <= 0 → the early error guard fires before any wc_get_product call.
        $result = $this->getBundle( [ 'product_id' => 0 ] );

        $this->assertArrayHasKey( 'error', $result );
        $this->assertArrayNotHasKey( 'products', $result );
    }

    public function test_negative_product_id_returns_error(): void {
        // absint() folds a negative to a positive, but a string that absints to 0
        // (or genuinely 0) trips the guard; assert the missing-id branch directly.
        $result = $this->getBundle( [ 'product_id' => 'not-a-number' ] );

        $this->assertArrayHasKey( 'error', $result );
    }

    public function test_anchor_not_a_product_yields_empty_state(): void {
        // wc_get_product returns false (unknown id) → not a WC_Product → empty state.
        $this->stubLookup( [] );

        $result = $this->getBundle( [ 'product_id' => 42 ] );

        $this->assertSame( 0, $result['found'] );
        $this->assertSame( [], $result['products'] );
        $this->assertTrue( $result['optional'] );
        $this->assertFalse( $result['trimmed'] );
        $this->assertTrue( $result['fits_budget'] );
        $this->assertSame( [], $result['unavailable'] );
        $this->assertArrayHasKey( 'message', $result );
    }

    public function test_non_visible_anchor_yields_empty_state(): void {
        // A real product that is not visible cannot be a bundle anchor.
        $anchor = $this->mockProduct( 1, 'Hidden Jacket', '100.00', [ 'visible' => false, 'cross_sell_ids' => [ 2 ] ] );
        $shirt  = $this->mockProduct( 2, 'Shirt', '40.00' );
        $this->stubLookup( [ 1 => $anchor, 2 => $shirt ] );

        $result = $this->getBundle( [ 'product_id' => 1 ] );

        $this->assertSame( 0, $result['found'] );
        $this->assertArrayHasKey( 'message', $result );
    }

    // ── candidate resolution ──────────────────────────────────────────────────────

    public function test_simple_anchor_is_base_item_plus_cross_sells(): void {
        $anchor = $this->mockProduct( 1, 'Jacket', '100.00', [ 'cross_sell_ids' => [ 2, 3 ] ] );
        $shirt  = $this->mockProduct( 2, 'Shirt', '40.00' );
        $belt   = $this->mockProduct( 3, 'Belt', '25.50' );
        $this->stubLookup( [ 1 => $anchor, 2 => $shirt, 3 => $belt ] );

        $result = $this->getBundle( [ 'product_id' => 1 ] );

        $this->assertSame( [ 1, 2, 3 ], array_column( $result['products'], 'id' ) );
        $this->assertEqualsWithDelta( 165.50, $result['combined_price'], 0.001 );
        // The combined price is the literal sum of the surfaced item prices.
        $sum = array_sum( array_map( static fn( $i ) => (float) $i['price_raw'], $result['products'] ) );
        $this->assertEqualsWithDelta( $sum, $result['combined_price'], 0.001 );
    }

    public function test_grouped_anchor_children_are_the_bundle_and_container_excluded(): void {
        // Grouped container itself is never an item; its children form the set.
        $group = $this->mockProduct( 1, 'Camera Kit', '', [ 'type' => 'grouped', 'children' => [ 2, 3 ] ] );
        $body  = $this->mockProduct( 2, 'Body', '500.00' );
        $lens  = $this->mockProduct( 3, 'Lens', '300.00' );
        $this->stubLookup( [ 1 => $group, 2 => $body, 3 => $lens ] );

        $result = $this->getBundle( [ 'product_id' => 1 ] );

        $ids = array_column( $result['products'], 'id' );
        $this->assertSame( [ 2, 3 ], $ids );
        $this->assertNotContains( 1, $ids );
        $this->assertEqualsWithDelta( 800.00, $result['combined_price'], 0.001 );
    }

    public function test_anchor_self_reference_in_cross_sells_is_deduped(): void {
        // unique_ids drops a duplicate/self id; the anchor appears exactly once.
        $anchor = $this->mockProduct( 1, 'Jacket', '100.00', [ 'cross_sell_ids' => [ 1, 2, 2 ] ] );
        $shirt  = $this->mockProduct( 2, 'Shirt', '40.00' );
        $this->stubLookup( [ 1 => $anchor, 2 => $shirt ] );

        $result = $this->getBundle( [ 'product_id' => 1 ] );

        $this->assertSame( [ 1, 2 ], array_column( $result['products'], 'id' ) );
    }

    public function test_zero_and_negative_cross_sell_ids_are_dropped(): void {
        // unique_ids casts via absint and drops zeros (id > 0 guard).
        $anchor = $this->mockProduct( 1, 'Jacket', '100.00', [ 'cross_sell_ids' => [ 0, 2 ] ] );
        $shirt  = $this->mockProduct( 2, 'Shirt', '40.00' );
        $this->stubLookup( [ 1 => $anchor, 2 => $shirt ] );

        $result = $this->getBundle( [ 'product_id' => 1 ] );

        $this->assertSame( [ 1, 2 ], array_column( $result['products'], 'id' ) );
    }

    // ── stock / visibility per item ───────────────────────────────────────────────

    public function test_out_of_stock_complementary_item_is_skipped_and_reported(): void {
        $anchor = $this->mockProduct( 1, 'Jacket', '100.00', [ 'cross_sell_ids' => [ 2, 3 ] ] );
        $shirt  = $this->mockProduct( 2, 'Shirt', '40.00' );
        $oos    = $this->mockProduct( 3, 'Sold Out Belt', '25.00', [ 'in_stock' => false ] );
        $this->stubLookup( [ 1 => $anchor, 2 => $shirt, 3 => $oos ] );

        $result = $this->getBundle( [ 'product_id' => 1 ] );

        $this->assertSame( [ 1, 2 ], array_column( $result['products'], 'id' ) );
        $this->assertEqualsWithDelta( 140.00, $result['combined_price'], 0.001 );
        $this->assertContains( 3, array_column( $result['unavailable'], 'id' ) );
        $this->assertSame( 'Sold Out Belt', $result['unavailable'][0]['name'] );
        // The unavailable-items disclosure message is set.
        $this->assertSame( 'Some items are out of stock and were left out of the bundle.', $result['message'] );
    }

    public function test_non_visible_complementary_item_is_skipped_silently(): void {
        // A non-visible item is `continue`d before the stock check — not even reported.
        $anchor = $this->mockProduct( 1, 'Jacket', '100.00', [ 'cross_sell_ids' => [ 2, 3 ] ] );
        $shirt  = $this->mockProduct( 2, 'Shirt', '40.00' );
        $hidden = $this->mockProduct( 3, 'Hidden', '25.00', [ 'visible' => false ] );
        $this->stubLookup( [ 1 => $anchor, 2 => $shirt, 3 => $hidden ] );

        $result = $this->getBundle( [ 'product_id' => 1 ] );

        $this->assertSame( [ 1, 2 ], array_column( $result['products'], 'id' ) );
        $this->assertEqualsWithDelta( 140.00, $result['combined_price'], 0.001 );
        $this->assertSame( [], $result['unavailable'], 'a hidden item is skipped, not reported as unavailable' );
    }

    public function test_false_cross_sell_lookup_is_skipped(): void {
        // wc_get_product returns false for an id that is not in the map → `continue`.
        $anchor = $this->mockProduct( 1, 'Jacket', '100.00', [ 'cross_sell_ids' => [ 2, 999 ] ] );
        $shirt  = $this->mockProduct( 2, 'Shirt', '40.00' );
        $this->stubLookup( [ 1 => $anchor, 2 => $shirt ] ); // id 999 → false

        $result = $this->getBundle( [ 'product_id' => 1 ] );

        $this->assertSame( [ 1, 2 ], array_column( $result['products'], 'id' ) );
    }

    public function test_out_of_stock_anchor_of_non_grouped_bundle_declines(): void {
        // The base item of a non-grouped bundle that is out of stock kills the bundle.
        $anchor = $this->mockProduct( 1, 'Jacket', '100.00', [ 'in_stock' => false, 'cross_sell_ids' => [ 2 ] ] );
        $shirt  = $this->mockProduct( 2, 'Shirt', '40.00' );
        $this->stubLookup( [ 1 => $anchor, 2 => $shirt ] );

        $result = $this->getBundle( [ 'product_id' => 1 ] );

        $this->assertSame( 0, $result['found'] );
        $this->assertSame( [], $result['products'] );
        $this->assertArrayHasKey( 'message', $result );
    }

    public function test_grouped_child_out_of_stock_is_reported_not_fatal(): void {
        // In a grouped bundle no child is "the anchor", so an out-of-stock child is
        // reported as unavailable rather than killing the whole bundle.
        $group = $this->mockProduct( 1, 'Kit', '', [ 'type' => 'grouped', 'children' => [ 2, 3, 4 ] ] );
        $a     = $this->mockProduct( 2, 'A', '50.00' );
        $b     = $this->mockProduct( 3, 'B', '60.00' );
        $oos   = $this->mockProduct( 4, 'C out', '70.00', [ 'in_stock' => false ] );
        $this->stubLookup( [ 1 => $group, 2 => $a, 3 => $b, 4 => $oos ] );

        $result = $this->getBundle( [ 'product_id' => 1 ] );

        $this->assertSame( [ 2, 3 ], array_column( $result['products'], 'id' ) );
        $this->assertEqualsWithDelta( 110.00, $result['combined_price'], 0.001 );
        $this->assertContains( 4, array_column( $result['unavailable'], 'id' ) );
    }

    // ── degenerate "not a bundle" ─────────────────────────────────────────────────

    public function test_single_buyable_item_is_not_a_bundle(): void {
        // Anchor with no usable complementary items → < 2 items → empty state,
        // carrying along any unavailable report.
        $anchor = $this->mockProduct( 1, 'Lonely', '100.00', [ 'cross_sell_ids' => [ 2 ] ] );
        $oos    = $this->mockProduct( 2, 'Out', '40.00', [ 'in_stock' => false ] );
        $this->stubLookup( [ 1 => $anchor, 2 => $oos ] );

        $result = $this->getBundle( [ 'product_id' => 1 ] );

        $this->assertSame( 0, $result['found'] );
        $this->assertSame( [], $result['products'] );
        $this->assertContains( 2, array_column( $result['unavailable'], 'id' ) );
        $this->assertArrayHasKey( 'message', $result );
    }

    // ── MAX_ITEMS cap ─────────────────────────────────────────────────────────────

    public function test_item_count_is_capped_at_max_items(): void {
        $cross_ids = range( 100, 130 ); // far beyond the cap of 8
        $anchor    = $this->mockProduct( 1, 'Anchor', '10.00', [ 'cross_sell_ids' => $cross_ids ] );
        $map       = [ 1 => $anchor ];
        foreach ( $cross_ids as $cid ) {
            $map[ $cid ] = $this->mockProduct( $cid, "Item {$cid}", '5.00' );
        }
        $this->stubLookup( $map );

        $result = $this->getBundle( [ 'product_id' => 1 ] );

        // Cap is 8 (anchor + 7 complementary), highest priority kept.
        $this->assertCount( 8, $result['products'] );
        $this->assertSame( [ 1, 100, 101, 102, 103, 104, 105, 106 ], array_column( $result['products'], 'id' ) );
    }

    // ── discount honesty ──────────────────────────────────────────────────────────

    public function test_no_discount_when_nothing_is_on_sale(): void {
        $anchor = $this->mockProduct( 1, 'Jacket', '100.00', [ 'cross_sell_ids' => [ 2 ] ] );
        $shirt  = $this->mockProduct( 2, 'Shirt', '40.00' );
        $this->stubLookup( [ 1 => $anchor, 2 => $shirt ] );

        $result = $this->getBundle( [ 'product_id' => 1 ] );

        $this->assertEqualsWithDelta( 140.00, $result['combined_price'], 0.001 );
        $this->assertEqualsWithDelta( 140.00, $result['regular_price'], 0.001 );
        $this->assertSame( 0.0, $result['savings'] );
        $this->assertFalse( $result['has_discount'] );
        // No savings_display key when there is no genuine discount.
        $this->assertArrayNotHasKey( 'savings_display', $result );
    }

    public function test_genuine_discount_surfaces_savings_and_display(): void {
        $anchor = $this->mockProduct( 1, 'Jacket', '80.00', [ 'cross_sell_ids' => [ 2 ], 'regular_price' => '100.00', 'on_sale' => true ] );
        $shirt  = $this->mockProduct( 2, 'Shirt', '30.00', [ 'regular_price' => '40.00', 'on_sale' => true ] );
        $this->stubLookup( [ 1 => $anchor, 2 => $shirt ] );

        $result = $this->getBundle( [ 'product_id' => 1 ] );

        $this->assertEqualsWithDelta( 110.00, $result['combined_price'], 0.001 );
        $this->assertEqualsWithDelta( 140.00, $result['regular_price'], 0.001 );
        $this->assertEqualsWithDelta( 30.00, $result['savings'], 0.001 );
        $this->assertTrue( $result['has_discount'] );
        // savings_display branch executes when has_discount is true.
        $this->assertSame( '$30.00', $result['savings_display'] );
    }

    public function test_missing_regular_price_never_invents_a_negative_saving(): void {
        // An item with an empty regular price contributes 0 to the regular sum via
        // numeric_price; savings is clamped at >= 0 so no fabricated discount arises.
        $anchor = $this->mockProduct( 1, 'Jacket', '100.00', [ 'cross_sell_ids' => [ 2 ], 'regular_price' => '' ] );
        $shirt  = $this->mockProduct( 2, 'Shirt', '40.00', [ 'regular_price' => '' ] );
        $this->stubLookup( [ 1 => $anchor, 2 => $shirt ] );

        $result = $this->getBundle( [ 'product_id' => 1 ] );

        $this->assertEqualsWithDelta( 140.00, $result['combined_price'], 0.001 );
        // Regular sum is 0 (empty regular prices), but savings clamps at 0 — never negative.
        $this->assertSame( 0.0, $result['savings'] );
        $this->assertFalse( $result['has_discount'] );
    }

    public function test_combined_price_display_is_a_plain_currency_string(): void {
        $anchor = $this->mockProduct( 1, 'Jacket', '100.00', [ 'cross_sell_ids' => [ 2 ] ] );
        $shirt  = $this->mockProduct( 2, 'Shirt', '40.00' );
        $this->stubLookup( [ 1 => $anchor, 2 => $shirt ] );

        $result = $this->getBundle( [ 'product_id' => 1 ] );

        // display_price runs wc_price → strip tags → decode entities → trim.
        $this->assertSame( '$140.00', $result['combined_price_display'] );
    }

    // ── budget handling ───────────────────────────────────────────────────────────

    public function test_no_budget_returns_whole_bundle_and_trivially_fits(): void {
        $anchor = $this->mockProduct( 1, 'Jacket', '100.00', [ 'cross_sell_ids' => [ 2 ] ] );
        $shirt  = $this->mockProduct( 2, 'Shirt', '40.00' );
        $this->stubLookup( [ 1 => $anchor, 2 => $shirt ] );

        // No max_price → budget() returns null → no trim loop.
        $result = $this->getBundle( [ 'product_id' => 1 ] );

        $this->assertCount( 2, $result['products'] );
        $this->assertFalse( $result['trimmed'] );
        $this->assertTrue( $result['fits_budget'] );
    }

    public function test_empty_string_budget_is_treated_as_no_budget(): void {
        // budget(): '' max_price short-circuits to null.
        $anchor = $this->mockProduct( 1, 'Jacket', '100.00', [ 'cross_sell_ids' => [ 2 ] ] );
        $shirt  = $this->mockProduct( 2, 'Shirt', '40.00' );
        $this->stubLookup( [ 1 => $anchor, 2 => $shirt ] );

        $result = $this->getBundle( [ 'product_id' => 1, 'max_price' => '' ] );

        $this->assertFalse( $result['trimmed'] );
        $this->assertTrue( $result['fits_budget'] );
    }

    public function test_non_numeric_budget_is_treated_as_no_budget(): void {
        // budget(): non-numeric max_price → null.
        $anchor = $this->mockProduct( 1, 'Jacket', '100.00', [ 'cross_sell_ids' => [ 2 ] ] );
        $shirt  = $this->mockProduct( 2, 'Shirt', '40.00' );
        $this->stubLookup( [ 1 => $anchor, 2 => $shirt ] );

        $result = $this->getBundle( [ 'product_id' => 1, 'max_price' => 'lots' ] );

        $this->assertFalse( $result['trimmed'] );
        $this->assertTrue( $result['fits_budget'] );
    }

    public function test_zero_budget_is_treated_as_no_budget(): void {
        // budget(): a numeric but non-positive max_price (0) → null, not a hard cap.
        $anchor = $this->mockProduct( 1, 'Jacket', '100.00', [ 'cross_sell_ids' => [ 2 ] ] );
        $shirt  = $this->mockProduct( 2, 'Shirt', '40.00' );
        $this->stubLookup( [ 1 => $anchor, 2 => $shirt ] );

        $result = $this->getBundle( [ 'product_id' => 1, 'max_price' => 0 ] );

        // Zero budget is ignored (treated as "no stated budget"), so the bundle stands.
        $this->assertFalse( $result['trimmed'] );
        $this->assertTrue( $result['fits_budget'] );
        $this->assertCount( 2, $result['products'] );
    }

    public function test_over_budget_bundle_is_trimmed_to_fit(): void {
        $anchor = $this->mockProduct( 1, 'Jacket', '100.00', [ 'cross_sell_ids' => [ 2, 3 ] ] );
        $shirt  = $this->mockProduct( 2, 'Shirt', '40.00' );
        $belt   = $this->mockProduct( 3, 'Belt', '30.00' );
        $this->stubLookup( [ 1 => $anchor, 2 => $shirt, 3 => $belt ] );

        // Full bundle 170; budget 150 trims the last item (belt) down to 140.
        $result = $this->getBundle( [ 'product_id' => 1, 'max_price' => 150 ] );

        $this->assertLessThanOrEqual( 150, $result['combined_price'] );
        $this->assertTrue( $result['trimmed'] );
        $this->assertTrue( $result['fits_budget'] );
        $this->assertSame( [ 1, 2 ], array_column( $result['products'], 'id' ) );
        $this->assertSame( 'I trimmed the bundle to stay within your budget.', $result['message'] );
    }

    public function test_budget_below_anchor_declines_with_fits_budget_false(): void {
        // Trim cannot drop below the base item; if even that exceeds the budget the
        // bundle is declined and fits_budget is overridden to false.
        $anchor = $this->mockProduct( 1, 'Jacket', '100.00', [ 'cross_sell_ids' => [ 2 ] ] );
        $shirt  = $this->mockProduct( 2, 'Shirt', '40.00' );
        $this->stubLookup( [ 1 => $anchor, 2 => $shirt ] );

        $result = $this->getBundle( [ 'product_id' => 1, 'max_price' => 50 ] );

        $this->assertSame( 0, $result['found'] );
        $this->assertSame( [], $result['products'] );
        $this->assertFalse( $result['fits_budget'] );
        $this->assertArrayHasKey( 'message', $result );
    }

    public function test_budget_trim_leaving_one_item_declines(): void {
        // Trimming everything but the base still over budget → < 2 items → decline.
        $anchor = $this->mockProduct( 1, 'Jacket', '60.00', [ 'cross_sell_ids' => [ 2, 3 ] ] );
        $shirt  = $this->mockProduct( 2, 'Shirt', '40.00' );
        $belt   = $this->mockProduct( 3, 'Belt', '40.00' );
        $this->stubLookup( [ 1 => $anchor, 2 => $shirt, 3 => $belt ] );

        // Budget 70: drop belt (→100), drop shirt (→60, single item) → fewer than two.
        $result = $this->getBundle( [ 'product_id' => 1, 'max_price' => 70 ] );

        $this->assertSame( 0, $result['found'] );
        $this->assertFalse( $result['fits_budget'] );
    }

    public function test_budget_exactly_at_combined_price_keeps_whole_bundle(): void {
        // Boundary: combined == max_price is NOT over budget (the loop uses `>`).
        $anchor = $this->mockProduct( 1, 'Jacket', '100.00', [ 'cross_sell_ids' => [ 2 ] ] );
        $shirt  = $this->mockProduct( 2, 'Shirt', '40.00' );
        $this->stubLookup( [ 1 => $anchor, 2 => $shirt ] );

        $result = $this->getBundle( [ 'product_id' => 1, 'max_price' => 140 ] );

        $this->assertFalse( $result['trimmed'] );
        $this->assertTrue( $result['fits_budget'] );
        $this->assertCount( 2, $result['products'] );
    }

    // ── optional / card-shape disclosure ──────────────────────────────────────────

    public function test_result_is_flagged_optional_and_carries_no_attributes_key(): void {
        $anchor = $this->mockProduct( 1, 'Jacket', '100.00', [ 'cross_sell_ids' => [ 2 ] ] );
        $shirt  = $this->mockProduct( 2, 'Shirt', '40.00' );
        $this->stubLookup( [ 1 => $anchor, 2 => $shirt ] );

        $result = $this->getBundle( [ 'product_id' => 1 ] );

        $this->assertTrue( $result['optional'] );
        $this->assertArrayHasKey( 'products', $result );
        $this->assertArrayNotHasKey( 'attributes', $result );
        // Each item carries the card-shaped summary keys plus the verifiable raw prices.
        foreach ( [ 'id', 'name', 'price', 'in_stock', 'image', 'url', 'price_raw', 'regular_price_raw' ] as $key ) {
            $this->assertArrayHasKey( $key, $result['products'][0], "Item summary missing key: {$key}" );
        }
    }

    public function test_found_count_matches_number_of_products(): void {
        $anchor = $this->mockProduct( 1, 'Jacket', '100.00', [ 'cross_sell_ids' => [ 2, 3 ] ] );
        $shirt  = $this->mockProduct( 2, 'Shirt', '40.00' );
        $belt   = $this->mockProduct( 3, 'Belt', '25.00' );
        $this->stubLookup( [ 1 => $anchor, 2 => $shirt, 3 => $belt ] );

        $result = $this->getBundle( [ 'product_id' => 1 ] );

        $this->assertSame( 3, $result['found'] );
        $this->assertCount( 3, $result['products'] );
    }
}
