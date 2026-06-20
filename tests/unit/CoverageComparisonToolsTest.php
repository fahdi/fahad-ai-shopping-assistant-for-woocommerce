<?php
/**
 * Supplemental line-coverage tests for Fahad_AI_Comparison_Tools (issue #13).
 *
 * Companion to ComparisonToolsTest: that suite drives the happy path and the
 * common guard clauses, but two defensive branches remain unexecuted by it:
 *
 *   - sanitize_ids() returning an empty list when the model hands `ids` a
 *     NON-ARRAY value (e.g. a string or null) — the primary suite always passes
 *     an array or omits the key (which defaults to an empty array), so the
 *     `! is_array( $raw )` early return is never reached.
 *   - product_attributes() skipping an attribute whose display value resolves to
 *     an empty / whitespace-only string even though its name IS listed by
 *     get_attributes() — the primary suite's mock gives every listed attribute a
 *     non-empty value, so the `'' === $value` continue is never reached.
 *
 * Conventions mirror ComparisonToolsTest exactly: WP/WC functions are stubbed via
 * Brain\Monkey; WC objects via Mockery; the registry's static pack-provider list
 * is snapshotted in setUp and restored in tearDown so this suite neither inherits
 * another suite's packs nor leaks its own; every test registers the comparison
 * pack's REAL provider through register_pack() and dispatches through the live
 * Fahad_AI_Tool_Registry, so the production registration + merge + dispatch path
 * is what is exercised.
 */

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;

class CoverageComparisonToolsTest extends TestCase {

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

        // Tool-layer stubs (mirror ComparisonToolsTest::setUp) so the shared product
        // formatter the comparison tool reuses can run against mocked products.
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
     * REAL provider via register_pack() — exactly the pack's file-scope
     * self-registration — so the test is hermetic and order-independent.
     */
    private function registry(): Fahad_AI_Tool_Registry {
        ( new ReflectionProperty( Fahad_AI_Tools::class, 'instance' ) )->setValue( null, null );
        ( new ReflectionProperty( Fahad_AI_Tool_Registry::class, 'instance' ) )->setValue( null, null );

        Fahad_AI_Tool_Registry::reset_packs();
        Fahad_AI_Tool_Registry::register_pack( [ 'Fahad_AI_Comparison_Tools', 'register' ] );

        return Fahad_AI_Tool_Registry::instance();
    }

    /**
     * Product mock with attributes (mirrors ComparisonToolsTest::mockProduct).
     *
     * $attributes is a name => display-value map: get_attributes() exposes the
     * attribute NAMES (keys), and get_attribute( $name ) returns that product's
     * display value. A value of '' (or whitespace) models an attribute that is
     * listed but resolves to no usable display value.
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

    // ── sanitize_ids: non-array `ids` → empty list → friendly error (line 144) ──

    public function test_compare_returns_error_when_ids_is_a_string(): void {
        // The model hands `ids` a STRING instead of an array. sanitize_ids() cannot
        // iterate it, so its `! is_array( $raw )` guard returns an empty list, which
        // compare_products turns into the friendly "tell me which products" error —
        // never a fatal from foreach-ing a scalar.
        $result = $this->registry()->dispatch( 'compare_products', [ 'ids' => 'not-an-array' ] );

        $this->assertArrayHasKey( 'error', $result );
        $this->assertArrayNotHasKey( 'products', $result );
        $this->assertArrayNotHasKey( 'found', $result );
        $this->assertNotSame( '', $result['error'] );
    }

    public function test_compare_returns_error_when_ids_is_null(): void {
        // An explicit null `ids` is likewise non-array → empty list → friendly error.
        $result = $this->registry()->dispatch( 'compare_products', [ 'ids' => null ] );

        $this->assertArrayHasKey( 'error', $result );
        $this->assertArrayNotHasKey( 'products', $result );
    }

    public function test_compare_returns_error_when_ids_is_an_integer(): void {
        // A bare integer (e.g. a single id passed un-wrapped) is also non-array; the
        // tool must not try to compare it as a degenerate one-product list — it asks
        // for products instead.
        $result = $this->registry()->dispatch( 'compare_products', [ 'ids' => 42 ] );

        $this->assertArrayHasKey( 'error', $result );
        $this->assertArrayNotHasKey( 'products', $result );
    }

    // ── product_attributes: listed attribute with empty value is skipped (line 179) ─

    public function test_compare_skips_attribute_with_empty_display_value(): void {
        // Product 1 lists two attributes, but one of them (`pa_finish`) resolves to an
        // empty display value via get_attribute(). product_attributes() must skip that
        // empty value (the `'' === $value` continue) so it never becomes a noise row,
        // while the non-empty attribute (`pa_material`) is kept.
        $a = $this->mockProduct( 1, 'Tee A', '19.99', [ 'pa_material' => 'Cotton', 'pa_finish' => '' ] );
        $b = $this->mockProduct( 2, 'Tee B', '24.99', [ 'pa_material' => 'Linen' ] );
        Functions\when( 'wc_get_product' )->alias( fn( $id ) => [ 1 => $a, 2 => $b ][ (int) $id ] ?? false );

        $result = $this->registry()->dispatch( 'compare_products', [ 'ids' => [ 1, 2 ] ] );

        $this->assertSame( 2, $result['found'] );
        $rows = array_column( $result['attributes'], null, 'name' );

        // The non-empty attribute survived…
        $this->assertArrayHasKey( 'Material', $rows );
        $this->assertSame( 'Cotton', $rows['Material']['values'][1] );
        // …but the empty-valued attribute produced NO row (it was skipped, not blank).
        $this->assertArrayNotHasKey( 'Finish', $rows );
    }

    public function test_compare_skips_attribute_that_is_only_whitespace(): void {
        // get_attribute() returning whitespace ("  ") is trimmed to '' inside
        // product_attributes(), so it hits the same empty-value continue and is
        // dropped — a whitespace value must not create a row either.
        $a = $this->mockProduct( 1, 'Tee A', '19.99', [ 'pa_color' => 'Blue', 'pa_pattern' => '   ' ] );
        $b = $this->mockProduct( 2, 'Tee B', '24.99', [ 'pa_color' => 'Red' ] );
        Functions\when( 'wc_get_product' )->alias( fn( $id ) => [ 1 => $a, 2 => $b ][ (int) $id ] ?? false );

        $result = $this->registry()->dispatch( 'compare_products', [ 'ids' => [ 1, 2 ] ] );

        $rows = array_column( $result['attributes'], null, 'name' );
        $this->assertArrayHasKey( 'Color', $rows );
        // The whitespace-only attribute was trimmed to empty and skipped → no row.
        $this->assertArrayNotHasKey( 'Pattern', $rows );
        // And only the one real attribute row exists for this pair.
        $this->assertCount( 1, $result['attributes'] );
    }

    public function test_compare_product_with_only_empty_valued_attributes_yields_no_rows(): void {
        // Every listed attribute on both products resolves to '' → product_attributes()
        // skips all of them, so the aligned table is empty (no rows), yet the products
        // still compare on their base fields. This drives the empty-value continue for
        // every attribute on both products.
        $a = $this->mockProduct( 1, 'Mug A', '8.00', [ 'pa_glaze' => '', 'pa_size' => '' ] );
        $b = $this->mockProduct( 2, 'Mug B', '9.00', [ 'pa_glaze' => '' ] );
        Functions\when( 'wc_get_product' )->alias( fn( $id ) => [ 1 => $a, 2 => $b ][ (int) $id ] ?? false );

        $result = $this->registry()->dispatch( 'compare_products', [ 'ids' => [ 1, 2 ] ] );

        $this->assertSame( 2, $result['found'] );
        $this->assertArrayHasKey( 'attributes', $result );
        $this->assertSame( [], $result['attributes'] );
    }
}
