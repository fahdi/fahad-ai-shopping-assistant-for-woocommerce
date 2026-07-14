<?php
/**
 * Unit tests for Fahad_AI_Comparison_Tools (issue #13: product comparison).
 *
 * Red → Green → Refactor cycle. Conventions mirror CatalogToolsTest: WP/WC
 * functions mocked via Brain\Monkey; WC objects via Mockery; singletons reset via
 * reflection; the registry's static pack-provider list snapshotted in setUp and
 * restored in tearDown.
 *
 * compare_products is NOT a built-in, it ships as a drop-in feature pack that
 * self-registers a provider via Fahad_AI_Tool_Registry::register_pack() at file
 * load. To exercise that registration genuinely (rather than inlining tool entries
 * by hand) every test registers the comparison pack's real provider through
 * register_pack(), then dispatches through
 * Fahad_AI_Tool_Registry::instance()->dispatch(), so the production registration
 * + merge + dispatch path is what is under test.
 */

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;

class ComparisonToolsTest extends TestCase {

    use MockeryPHPUnitIntegration;

    /**
     * Snapshot of the registry's static pack providers, restored in tearDown so a
     * test here neither inherits another suite's packs nor leaks the comparison
     * pack we register for our own cases.
     *
     * @var array<int, callable>
     */
    private array $pack_snapshot = [];

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();

        $this->pack_snapshot = (array) ( new ReflectionProperty( Fahad_AI_Tool_Registry::class, 'pack_providers' ) )->getValue();

        // Tool-layer stubs (mirror CatalogToolsTest::setUp) so the shared product
        // formatter the comparison tool reuses can run against mocked products.
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
            'wc_attribute_label'  => fn( $name ) => ucwords( str_replace( [ 'pa_', '_', '-' ], [ '', ' ', ' ' ], (string) $name ) ),
        ] );
    }

    protected function tearDown(): void {
        ( new ReflectionProperty( Fahad_AI_Tool_Registry::class, 'pack_providers' ) )->setValue( null, $this->pack_snapshot );
        Monkey\tearDown();
        parent::tearDown();
    }

    /**
     * Fresh registry whose built tool list includes the comparison tools.
     *
     * Resets the Tools + registry singletons, then registers the comparison pack's
     * REAL provider via register_pack(), exactly what the pack's file-scope
     * self-registration does in production.
     */
    private function registry(): Fahad_AI_Tool_Registry {
        ( new ReflectionProperty( Fahad_AI_Tools::class, 'instance' ) )->setValue( null, null );
        ( new ReflectionProperty( Fahad_AI_Tool_Registry::class, 'instance' ) )->setValue( null, null );

        Fahad_AI_Tool_Registry::reset_packs();
        Fahad_AI_Tool_Registry::register_pack( [ 'Fahad_AI_Comparison_Tools', 'register' ] );

        return Fahad_AI_Tool_Registry::instance();
    }

    /**
     * Product mock with attributes. $attributes is a name => display-value map; the
     * mock exposes the attribute NAMES via get_attributes() (the keys, mirroring the
     * WC_Product_Attribute[] map keyed by name) and each value via
     * get_attribute( $name ), the same two-call shape the comparison tool reads.
     *
     * @param array<string,string> $attributes
     */
    private function mockProduct( int $id, string $name, string $price, array $attributes = [], array $overrides = [] ): WC_Product {
        $p = Mockery::mock( WC_Product::class );
        $p->shouldReceive( 'get_id' )->andReturn( $id );
        $p->shouldReceive( 'get_name' )->andReturn( $name );
        $p->shouldReceive( 'get_price' )->andReturn( $price );
        $p->shouldReceive( 'get_regular_price' )->andReturn( $price );
        $p->shouldReceive( 'get_sale_price' )->andReturn( '' );
        $p->shouldReceive( 'is_on_sale' )->andReturn( false );
        $p->shouldReceive( 'is_visible' )->andReturn( $overrides['visible'] ?? true );
        $p->shouldReceive( 'is_in_stock' )->andReturn( $overrides['in_stock'] ?? true );
        $p->shouldReceive( 'get_short_description' )->andReturn( '' );
        $p->shouldReceive( 'get_image_id' )->andReturn( 0 );
        $p->shouldReceive( 'get_average_rating' )->andReturn( (string) ( $overrides['rating'] ?? '0' ) );
        $p->shouldReceive( 'get_review_count' )->andReturn( (int) ( $overrides['review_count'] ?? 0 ) );

        // Attributes: get_attributes() is keyed by attribute name in WooCommerce, so
        // the keys are all the tool needs to enumerate them; get_attribute( $name )
        // returns the product's display value for that attribute (or '' when absent).
        $attr_map = [];
        foreach ( $attributes as $attr_name => $value ) {
            $attr_map[ $attr_name ] = $attr_name; // value object is irrelevant; key drives enumeration.
        }
        $p->shouldReceive( 'get_attributes' )->andReturn( $attr_map );
        $p->shouldReceive( 'get_attribute' )->andReturnUsing(
            static fn( $name ) => (string) ( $attributes[ $name ] ?? '' )
        );

        return $p;
    }

    // ── registration ──────────────────────────────────────────────────────────

    public function test_comparison_tool_is_registered_via_register_pack(): void {
        $names = array_column( $this->registry()->specs(), 'name' );

        $this->assertContains( 'compare_products', $names );
        // Additive, the six built-ins remain.
        $this->assertContains( 'search_products', $names );
    }

    public function test_comparison_tool_spec_never_leaks_a_callback(): void {
        $specs = array_column( $this->registry()->specs(), null, 'name' );

        $this->assertArrayHasKey( 'compare_products', $specs );
        $this->assertArrayNotHasKey( 'callback', $specs['compare_products'] );
        $this->assertArrayHasKey( 'description', $specs['compare_products'] );
        $this->assertSame( 'object', $specs['compare_products']['parameters']['type'] );
        $this->assertArrayHasKey( 'properties', $specs['compare_products']['parameters'] );
    }

    // ── aligned fields for 2 products ───────────────────────────────────────────

    public function test_compare_two_products_returns_aligned_base_fields(): void {
        $a = $this->mockProduct( 1, 'Tee A', '19.99', [ 'pa_material' => 'Cotton', 'pa_color' => 'Blue' ], [ 'rating' => '4.5', 'review_count' => 10 ] );
        $b = $this->mockProduct( 2, 'Tee B', '24.99', [ 'pa_material' => 'Linen', 'pa_color' => 'Red' ], [ 'rating' => '4.0', 'review_count' => 5 ] );
        Functions\when( 'wc_get_product' )->alias( fn( $id ) => [ 1 => $a, 2 => $b ][ (int) $id ] ?? false );

        $result = $this->registry()->dispatch( 'compare_products', [ 'ids' => [ 1, 2 ] ] );

        $this->assertSame( 2, $result['found'] );
        $this->assertCount( 2, $result['products'] );

        // Each product carries the aligned base fields the issue requires.
        foreach ( [ 'id', 'name', 'price', 'in_stock', 'url', 'image', 'rating', 'review_count' ] as $key ) {
            $this->assertArrayHasKey( $key, $result['products'][0], "Product summary missing key: {$key}" );
        }
        $this->assertSame( 1, $result['products'][0]['id'] );
        $this->assertSame( 'Tee A', $result['products'][0]['name'] );
        $this->assertSame( 4.5, $result['products'][0]['rating'] );
        $this->assertSame( 10, $result['products'][0]['review_count'] );
        $this->assertSame( 2, $result['products'][1]['id'] );
    }

    public function test_compare_aligns_common_attributes_across_products(): void {
        // Both products share material + color, so those become aligned rows with a
        // value per product id.
        $a = $this->mockProduct( 1, 'Tee A', '19.99', [ 'pa_material' => 'Cotton', 'pa_color' => 'Blue' ] );
        $b = $this->mockProduct( 2, 'Tee B', '24.99', [ 'pa_material' => 'Linen', 'pa_color' => 'Red' ] );
        Functions\when( 'wc_get_product' )->alias( fn( $id ) => [ 1 => $a, 2 => $b ][ (int) $id ] ?? false );

        $result = $this->registry()->dispatch( 'compare_products', [ 'ids' => [ 1, 2 ] ] );

        $this->assertArrayHasKey( 'attributes', $result );
        $rows = array_column( $result['attributes'], null, 'name' );

        $this->assertArrayHasKey( 'Material', $rows );
        $this->assertArrayHasKey( 'Color', $rows );

        // Each attribute row carries each product's value keyed by product id.
        $this->assertSame( 'Cotton', $rows['Material']['values'][1] );
        $this->assertSame( 'Linen',  $rows['Material']['values'][2] );
        $this->assertSame( 'Blue',   $rows['Color']['values'][1] );
        $this->assertSame( 'Red',    $rows['Color']['values'][2] );
    }

    public function test_compare_attribute_present_on_only_one_product_is_blank_for_the_other(): void {
        // A union of attributes keeps the table aligned: an attribute one product
        // lacks still appears as a row, blank for the product that does not have it.
        $a = $this->mockProduct( 1, 'Tee A', '19.99', [ 'pa_material' => 'Cotton', 'pa_sleeve' => 'Short' ] );
        $b = $this->mockProduct( 2, 'Tee B', '24.99', [ 'pa_material' => 'Linen' ] );
        Functions\when( 'wc_get_product' )->alias( fn( $id ) => [ 1 => $a, 2 => $b ][ (int) $id ] ?? false );

        $result = $this->registry()->dispatch( 'compare_products', [ 'ids' => [ 1, 2 ] ] );
        $rows   = array_column( $result['attributes'], null, 'name' );

        $this->assertArrayHasKey( 'Sleeve', $rows );
        $this->assertSame( 'Short', $rows['Sleeve']['values'][1] );
        // Product 2 has no sleeve attribute → blank, but the id key is still present
        // so every row has a value for every compared product (aligned columns).
        $this->assertArrayHasKey( 2, $rows['Sleeve']['values'] );
        $this->assertSame( '', $rows['Sleeve']['values'][2] );
    }

    // ── aligned fields for 3 products ───────────────────────────────────────────

    public function test_compare_three_products_returns_three_aligned_columns(): void {
        $a = $this->mockProduct( 1, 'Tee A', '19.99', [ 'pa_color' => 'Blue' ] );
        $b = $this->mockProduct( 2, 'Tee B', '24.99', [ 'pa_color' => 'Red' ] );
        $c = $this->mockProduct( 3, 'Tee C', '29.99', [ 'pa_color' => 'Green' ] );
        Functions\when( 'wc_get_product' )->alias( fn( $id ) => [ 1 => $a, 2 => $b, 3 => $c ][ (int) $id ] ?? false );

        $result = $this->registry()->dispatch( 'compare_products', [ 'ids' => [ 1, 2, 3 ] ] );

        $this->assertSame( 3, $result['found'] );
        $this->assertCount( 3, $result['products'] );

        $rows = array_column( $result['attributes'], null, 'name' );
        $this->assertSame( 'Blue',  $rows['Color']['values'][1] );
        $this->assertSame( 'Red',   $rows['Color']['values'][2] );
        $this->assertSame( 'Green', $rows['Color']['values'][3] );
    }

    // ── graceful handling of bad input ──────────────────────────────────────────

    public function test_compare_skips_invalid_or_missing_id(): void {
        // Id 99 resolves to false (not found / not a product) and is skipped; the two
        // valid products still compare.
        $a = $this->mockProduct( 1, 'Tee A', '19.99', [ 'pa_color' => 'Blue' ] );
        $b = $this->mockProduct( 2, 'Tee B', '24.99', [ 'pa_color' => 'Red' ] );
        Functions\when( 'wc_get_product' )->alias( fn( $id ) => [ 1 => $a, 2 => $b ][ (int) $id ] ?? false );

        $result = $this->registry()->dispatch( 'compare_products', [ 'ids' => [ 1, 99, 2 ] ] );

        $this->assertSame( 2, $result['found'] );
        $this->assertCount( 2, $result['products'] );
        $ids = array_column( $result['products'], 'id' );
        $this->assertSame( [ 1, 2 ], $ids );
    }

    public function test_compare_skips_non_visible_product(): void {
        // A non-visible product cannot be shopped, so it is skipped (same buyable
        // gate the other product tools apply via is_visible()).
        $a = $this->mockProduct( 1, 'Tee A', '19.99', [ 'pa_color' => 'Blue' ] );
        $hidden = $this->mockProduct( 2, 'Hidden Tee', '24.99', [ 'pa_color' => 'Red' ], [ 'visible' => false ] );
        $c = $this->mockProduct( 3, 'Tee C', '29.99', [ 'pa_color' => 'Green' ] );
        Functions\when( 'wc_get_product' )->alias( fn( $id ) => [ 1 => $a, 2 => $hidden, 3 => $c ][ (int) $id ] ?? false );

        $result = $this->registry()->dispatch( 'compare_products', [ 'ids' => [ 1, 2, 3 ] ] );

        $this->assertSame( 2, $result['found'] );
        $ids = array_column( $result['products'], 'id' );
        $this->assertSame( [ 1, 3 ], $ids );
    }

    public function test_compare_returns_error_when_fewer_than_two_valid_products(): void {
        // Comparison needs at least two products; one valid id (plus a missing one)
        // yields a graceful error, not a one-product "comparison".
        $a = $this->mockProduct( 1, 'Tee A', '19.99', [ 'pa_color' => 'Blue' ] );
        Functions\when( 'wc_get_product' )->alias( fn( $id ) => [ 1 => $a ][ (int) $id ] ?? false );

        $result = $this->registry()->dispatch( 'compare_products', [ 'ids' => [ 1, 99 ] ] );

        $this->assertArrayHasKey( 'error', $result );
        $this->assertArrayNotHasKey( 'products', $result );
    }

    public function test_compare_returns_error_when_no_ids_supplied(): void {
        $result = $this->registry()->dispatch( 'compare_products', [] );

        $this->assertArrayHasKey( 'error', $result );
    }

    // ── sane max ────────────────────────────────────────────────────────────────

    public function test_compare_enforces_a_sane_max_of_four_products(): void {
        // More ids than the cap → only the first N are compared (a side-by-side table
        // with too many columns is unreadable, and it bounds the work done).
        $mocks = [];
        for ( $i = 1; $i <= 6; $i++ ) {
            $mocks[ $i ] = $this->mockProduct( $i, "Tee {$i}", '19.99', [ 'pa_color' => 'Blue' ] );
        }
        Functions\when( 'wc_get_product' )->alias( fn( $id ) => $mocks[ (int) $id ] ?? false );

        $result = $this->registry()->dispatch( 'compare_products', [ 'ids' => [ 1, 2, 3, 4, 5, 6 ] ] );

        $this->assertLessThanOrEqual( 4, $result['found'] );
        $this->assertLessThanOrEqual( 4, count( $result['products'] ) );
    }

    public function test_compare_deduplicates_repeated_ids(): void {
        // The same product passed twice must not produce two identical columns.
        $a = $this->mockProduct( 1, 'Tee A', '19.99', [ 'pa_color' => 'Blue' ] );
        $b = $this->mockProduct( 2, 'Tee B', '24.99', [ 'pa_color' => 'Red' ] );
        Functions\when( 'wc_get_product' )->alias( fn( $id ) => [ 1 => $a, 2 => $b ][ (int) $id ] ?? false );

        $result = $this->registry()->dispatch( 'compare_products', [ 'ids' => [ 1, 1, 2 ] ] );

        $this->assertSame( 2, $result['found'] );
        $ids = array_column( $result['products'], 'id' );
        $this->assertSame( [ 1, 2 ], $ids );
    }

    public function test_compare_with_no_shared_or_any_attributes_still_compares_base_fields(): void {
        // Products with no attributes at all still compare on name/price/etc.; the
        // attributes list is simply empty (no rows), never an error.
        $a = $this->mockProduct( 1, 'Mug A', '8.00' );
        $b = $this->mockProduct( 2, 'Mug B', '9.00' );
        Functions\when( 'wc_get_product' )->alias( fn( $id ) => [ 1 => $a, 2 => $b ][ (int) $id ] ?? false );

        $result = $this->registry()->dispatch( 'compare_products', [ 'ids' => [ 1, 2 ] ] );

        $this->assertSame( 2, $result['found'] );
        $this->assertArrayHasKey( 'attributes', $result );
        $this->assertSame( [], $result['attributes'] );
    }
}
