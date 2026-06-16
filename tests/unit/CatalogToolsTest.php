<?php
/**
 * Unit tests for Fahad_AI_Catalog_Tools (issue #15: best-sellers & category browse).
 *
 * Red → Green → Refactor cycle. Conventions mirror ToolsTest / ToolRegistryTest:
 * WP/WC functions mocked via Brain\Monkey; WC objects via Mockery; singletons
 * reset via reflection.
 *
 * The two catalog tools (get_top_products, list_categories) are NOT built-ins —
 * they ship as a drop-in feature pack that self-registers a provider via
 * Fahad_AI_Tool_Registry::register_pack() at file load. To exercise that
 * registration genuinely (rather than inlining tool entries by hand) every test
 * registers the catalog pack's real provider through register_pack(), then
 * dispatches through Fahad_AI_Tool_Registry::instance()->dispatch() — so the
 * production registration + merge + dispatch path is what is under test.
 */

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;

class CatalogToolsTest extends TestCase {

    use MockeryPHPUnitIntegration;

    /**
     * Snapshot of the registry's static pack providers, restored in tearDown so a
     * test here neither inherits another suite's packs nor leaks the catalog pack
     * we register for our own cases. (Pack providers are static so they survive a
     * singleton instance reset — see Fahad_AI_Tool_Registry::register_pack.)
     *
     * @var array<int, callable>
     */
    private array $pack_snapshot = [];

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();

        $this->pack_snapshot = (array) ( new ReflectionProperty( Fahad_AI_Tool_Registry::class, 'pack_providers' ) )->getValue();

        // Tool-layer stubs (mirror ToolsTest::setUp) so the shared product
        // formatter the catalog tools reuse can run against mocked products.
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
        ( new ReflectionProperty( Fahad_AI_Tool_Registry::class, 'pack_providers' ) )->setValue( null, $this->pack_snapshot );
        Monkey\tearDown();
        parent::tearDown();
    }

    /**
     * Fresh registry whose built tool list includes the catalog tools.
     *
     * Resets the Tools + registry singletons, then registers the catalog pack's
     * REAL provider via register_pack() — exactly what the pack's file-scope
     * self-registration does in production. We register it explicitly (after
     * clearing the static list) so the test is hermetic and order-independent
     * regardless of what other suites do to the shared provider list.
     */
    private function registry(): Fahad_AI_Tool_Registry {
        ( new ReflectionProperty( Fahad_AI_Tools::class, 'instance' ) )->setValue( null, null );
        ( new ReflectionProperty( Fahad_AI_Tool_Registry::class, 'instance' ) )->setValue( null, null );

        Fahad_AI_Tool_Registry::reset_packs();
        Fahad_AI_Tool_Registry::register_pack( [ 'Fahad_AI_Catalog_Tools', 'register' ] );

        return Fahad_AI_Tool_Registry::instance();
    }

    /** Default "happy path" product mock (mirrors ToolsTest::mockProduct). */
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
        return $p;
    }

    // ── registration ──────────────────────────────────────────────────────────

    public function test_catalog_tools_are_registered_via_register_pack(): void {
        $names = array_column( $this->registry()->specs(), 'name' );

        $this->assertContains( 'get_top_products', $names );
        $this->assertContains( 'list_categories', $names );
        // They are additive — the five built-ins remain.
        $this->assertContains( 'search_products', $names );
        $this->assertCount( 7, $names );
    }

    public function test_catalog_tool_specs_never_leak_a_callback(): void {
        $specs = array_column( $this->registry()->specs(), null, 'name' );

        foreach ( [ 'get_top_products', 'list_categories' ] as $name ) {
            $this->assertArrayHasKey( $name, $specs );
            $this->assertArrayNotHasKey( 'callback', $specs[ $name ] );
            $this->assertArrayHasKey( 'description', $specs[ $name ] );
            $this->assertSame( 'object', $specs[ $name ]['parameters']['type'] );
            $this->assertArrayHasKey( 'properties', $specs[ $name ]['parameters'] );
        }
    }

    // ── get_top_products ────────────────────────────────────────────────────────

    public function test_get_top_products_returns_formatted_products(): void {
        $product = $this->mockProduct( 1, 'Bestseller Tee', '29.99' );
        Functions\when( 'wc_get_products' )->justReturn( [ $product ] );

        $result = $this->registry()->dispatch( 'get_top_products', [] );

        $this->assertSame( 1, $result['found'] );
        $this->assertCount( 1, $result['products'] );
        $this->assertSame( 1, $result['products'][0]['id'] );
        $this->assertSame( 'Bestseller Tee', $result['products'][0]['name'] );
        // Same card-shaped summary search_products emits (so it renders as a card).
        foreach ( [ 'id', 'name', 'price', 'regular_price', 'sale_price', 'on_sale', 'in_stock', 'short_description', 'image', 'url' ] as $key ) {
            $this->assertArrayHasKey( $key, $result['products'][0], "Summary missing key: {$key}" );
        }
    }

    public function test_get_top_products_orders_by_total_sales_descending(): void {
        // "Best seller" is defined as highest total_sales — assert the query asks
        // for that ordering so the definition is enforced, not just documented.
        Functions\expect( 'wc_get_products' )
            ->once()
            ->andReturnUsing( function ( array $args ): array {
                $this->assertSame( 'meta_value_num', $args['orderby'] );
                $this->assertSame( 'total_sales', $args['meta_key'] );
                $this->assertSame( 'DESC', $args['order'] );
                return [];
            } );

        $this->registry()->dispatch( 'get_top_products', [] );
    }

    public function test_get_top_products_defaults_limit_to_5(): void {
        Functions\expect( 'wc_get_products' )
            ->once()
            ->andReturnUsing( function ( array $args ): array {
                $this->assertSame( 5, $args['limit'] );
                return [];
            } );

        $this->registry()->dispatch( 'get_top_products', [] );
    }

    public function test_get_top_products_caps_limit_at_10(): void {
        Functions\expect( 'wc_get_products' )
            ->once()
            ->andReturnUsing( function ( array $args ): array {
                $this->assertSame( 10, $args['limit'] );
                return [];
            } );

        $this->registry()->dispatch( 'get_top_products', [ 'limit' => 999 ] );
    }

    public function test_get_top_products_passes_category_when_supplied(): void {
        Functions\expect( 'wc_get_products' )
            ->once()
            ->andReturnUsing( function ( array $args ): array {
                $this->assertSame( [ 'hoodies' ], $args['category'] );
                return [];
            } );

        $this->registry()->dispatch( 'get_top_products', [ 'category' => 'hoodies' ] );
    }

    public function test_get_top_products_omits_category_when_absent(): void {
        Functions\expect( 'wc_get_products' )
            ->once()
            ->andReturnUsing( function ( array $args ): array {
                $this->assertArrayNotHasKey( 'category', $args );
                return [];
            } );

        $this->registry()->dispatch( 'get_top_products', [] );
    }

    public function test_get_top_products_returns_empty_state_when_no_sales(): void {
        Functions\when( 'wc_get_products' )->justReturn( [] );

        $result = $this->registry()->dispatch( 'get_top_products', [] );

        $this->assertSame( 0, $result['found'] );
        $this->assertSame( [], $result['products'] );
        $this->assertArrayHasKey( 'message', $result );
    }

    // ── list_categories ──────────────────────────────────────────────────────────

    public function test_list_categories_returns_name_slug_and_count(): void {
        $terms = [
            (object) [ 'name' => 'Shoes',   'slug' => 'shoes',   'count' => 12 ],
            (object) [ 'name' => 'Hoodies', 'slug' => 'hoodies', 'count' => 4 ],
        ];
        Functions\expect( 'get_terms' )->once()->andReturn( $terms );

        $result = $this->registry()->dispatch( 'list_categories', [] );

        $this->assertSame( 2, $result['found'] );
        $this->assertCount( 2, $result['categories'] );
        $this->assertSame(
            [ 'name' => 'Shoes', 'slug' => 'shoes', 'count' => 12 ],
            $result['categories'][0]
        );
        $this->assertSame( 4, $result['categories'][1]['count'] );
        // A category list is NOT a product list — no products[] key to render cards.
        $this->assertArrayNotHasKey( 'products', $result );
    }

    public function test_list_categories_hides_empty_categories_by_default(): void {
        Functions\expect( 'get_terms' )
            ->once()
            ->andReturnUsing( function ( array $args ): array {
                $this->assertSame( 'product_cat', $args['taxonomy'] );
                $this->assertTrue( $args['hide_empty'] );
                return [];
            } );

        $this->registry()->dispatch( 'list_categories', [] );
    }

    public function test_list_categories_can_include_empty_categories(): void {
        Functions\expect( 'get_terms' )
            ->once()
            ->andReturnUsing( function ( array $args ): array {
                $this->assertFalse( $args['hide_empty'] );
                return [];
            } );

        $this->registry()->dispatch( 'list_categories', [ 'include_empty' => true ] );
    }

    public function test_list_categories_returns_empty_state_when_none(): void {
        Functions\when( 'get_terms' )->justReturn( [] );

        $result = $this->registry()->dispatch( 'list_categories', [] );

        $this->assertSame( 0, $result['found'] );
        $this->assertSame( [], $result['categories'] );
        $this->assertArrayHasKey( 'message', $result );
    }

    public function test_list_categories_tolerates_wp_error_from_get_terms(): void {
        // get_terms can return a WP_Error (e.g. invalid taxonomy). The tool must
        // degrade to an empty list, not fatal or leak the error object.
        Functions\when( 'get_terms' )->justReturn( new WP_Error( 'invalid_taxonomy', 'bad' ) );

        $result = $this->registry()->dispatch( 'list_categories', [] );

        $this->assertSame( 0, $result['found'] );
        $this->assertSame( [], $result['categories'] );
    }
}
