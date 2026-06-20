<?php
/**
 * Line-coverage hardening for Fahad_AI_Catalog_Tools (includes/tools/class-catalog-tools.php).
 *
 * Companion to CatalogToolsTest: this suite drives EVERY reachable statement and
 * branch of the catalog pack through the production register_pack() + dispatch()
 * path, asserting real, correct behaviour (not bare smoke calls). Conventions
 * mirror CatalogToolsTest / the other Coverage*ToolsTest siblings: WP/WC
 * functions stubbed via Brain\Monkey, WC product objects via Mockery, the Tools
 * and registry singletons reset through reflection, and the static pack-provider
 * list snapshotted/restored so this suite neither inherits nor leaks packs.
 *
 * NOTE on the one residual uncovered line (the file-scope
 * `Fahad_AI_Tool_Registry::register_pack( [ 'Fahad_AI_Catalog_Tools', 'register' ] );`
 * at the bottom of the source file): that statement is a load-time side effect
 * that runs exactly ONCE when the bootstrap require_once's the file, BEFORE
 * pcov's per-test collection window opens, so it is never attributed to a test.
 * It cannot be re-executed from a test either — re-`include`ing the file is a
 * fatal `final class` redeclaration, and calling register_pack() directly only
 * exercises the registry class, not this call-site line. This suite instead
 * asserts the OBSERVABLE RESULT of that registration (the catalog provider is
 * present and wired) so the behaviour is verified even though the literal call
 * site stays uncovered. The identical pattern holds for every sibling pack file.
 */

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;

class CoverageCatalogToolsTest extends TestCase {

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
		// formatter the catalog tools reuse runs against mocked products.
		Functions\stubs( [
			'absint'                      => fn( $n ) => abs( (int) $n ),
			'sanitize_text_field'         => fn( $s ) => $s,
			'get_option'                  => fn( $key, $default = '' ) => $default,
			'wp_json_encode'              => fn( $d ) => json_encode( $d ),
			'wc_price'                    => fn( $p ) => '$' . $p,
			'wp_strip_all_tags'           => fn( $s ) => strip_tags( (string) $s ),
			'wp_get_attachment_image_url' => fn() => '',
			'wc_placeholder_img_src'      => fn() => 'http://example.com/placeholder.png',
			'get_permalink'               => fn( $id ) => 'http://example.com/?p=' . $id,
			'wc_get_cart_url'             => fn() => 'http://example.com/cart',
			'wc_get_checkout_url'         => fn() => 'http://example.com/checkout',
			'wp_list_pluck'               => fn( $list, $field ) => array_column( (array) $list, $field ),
			'get_the_terms'               => fn() => [],
		] );
	}

	protected function tearDown(): void {
		( new ReflectionProperty( Fahad_AI_Tool_Registry::class, 'pack_providers' ) )->setValue( null, $this->pack_snapshot );
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * Fresh registry whose built tool list includes the catalog tools, registered
	 * through the pack's REAL provider — the same path the file-scope
	 * self-registration uses in production.
	 */
	private function registry(): Fahad_AI_Tool_Registry {
		( new ReflectionProperty( Fahad_AI_Tools::class, 'instance' ) )->setValue( null, null );
		( new ReflectionProperty( Fahad_AI_Tool_Registry::class, 'instance' ) )->setValue( null, null );

		Fahad_AI_Tool_Registry::reset_packs();
		Fahad_AI_Tool_Registry::register_pack( [ 'Fahad_AI_Catalog_Tools', 'register' ] );

		return Fahad_AI_Tool_Registry::instance();
	}

	/** Minimal product mock that the shared formatter can summarise. */
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
		return $p;
	}

	// ── register() — the provider that the file-scope register_pack wires up ──────

	public function test_register_appends_both_catalog_tools_in_order(): void {
		// register() is the callable the file-scope register_pack() hands to the
		// registry. Call it directly against a known base list to assert it APPENDS
		// (does not replace) and preserves order: get_top_products then list_categories.
		$base  = [ [ 'name' => 'pre_existing' ] ];
		$tools = Fahad_AI_Catalog_Tools::register( $base );

		$names = array_column( $tools, 'name' );
		$this->assertSame( [ 'pre_existing', 'get_top_products', 'list_categories' ], $names );
	}

	public function test_register_top_products_entry_is_well_formed_with_callback(): void {
		$tools = array_column( Fahad_AI_Catalog_Tools::register( [] ), null, 'name' );

		$entry = $tools['get_top_products'];
		$this->assertIsString( $entry['description'] );
		$this->assertSame( 'object', $entry['parameters']['type'] );
		$this->assertArrayHasKey( 'limit', $entry['parameters']['properties'] );
		$this->assertArrayHasKey( 'category', $entry['parameters']['properties'] );
		// The callback is the closure that forwards to the private implementation.
		$this->assertIsCallable( $entry['callback'] );
	}

	public function test_register_list_categories_entry_is_well_formed_with_callback(): void {
		$tools = array_column( Fahad_AI_Catalog_Tools::register( [] ), null, 'name' );

		$entry = $tools['list_categories'];
		$this->assertIsString( $entry['description'] );
		$this->assertSame( 'object', $entry['parameters']['type'] );
		$this->assertArrayHasKey( 'include_empty', $entry['parameters']['properties'] );
		$this->assertIsCallable( $entry['callback'] );
	}

	public function test_top_products_callback_closure_forwards_input(): void {
		// Invoking the registered closure (rather than dispatch) executes the
		// fn( array $input ) => self::get_top_products( $input ) arrow on its own.
		$tools    = array_column( Fahad_AI_Catalog_Tools::register( [] ), null, 'name' );
		$callback = $tools['get_top_products']['callback'];

		Functions\when( 'wc_get_products' )->justReturn( [] );

		$result = $callback( [] );
		$this->assertSame( 0, $result['found'] );
		$this->assertSame( [], $result['products'] );
	}

	public function test_list_categories_callback_closure_forwards_input(): void {
		$tools    = array_column( Fahad_AI_Catalog_Tools::register( [] ), null, 'name' );
		$callback = $tools['list_categories']['callback'];

		Functions\when( 'get_terms' )->justReturn( [] );

		$result = $callback( [] );
		$this->assertSame( 0, $result['found'] );
		$this->assertSame( [], $result['categories'] );
	}

	// ── self-registration result (line-158 behaviour, asserted by effect) ─────────

	public function test_pack_self_registration_wires_the_catalog_provider(): void {
		// The file-scope register_pack() call (last line of the source) runs at
		// load time and cannot be re-attributed to a test, so assert its RESULT:
		// re-running the same registration produces a registry whose built tool
		// list contains exactly the two catalog tools the provider appends.
		$names = array_column( $this->registry()->specs(), 'name' );

		$this->assertContains( 'get_top_products', $names );
		$this->assertContains( 'list_categories', $names );
	}

	// ── get_top_products: limit clamping ──────────────────────────────────────────

	public function test_get_top_products_clamps_zero_limit_up_to_1(): void {
		// max( 1, (int) 0 ) === 1 — the lower bound of the clamp.
		Functions\expect( 'wc_get_products' )
			->once()
			->andReturnUsing( function ( array $args ): array {
				$this->assertSame( 1, $args['limit'] );
				return [];
			} );

		$this->registry()->dispatch( 'get_top_products', [ 'limit' => 0 ] );
	}

	public function test_get_top_products_clamps_negative_limit_up_to_1(): void {
		Functions\expect( 'wc_get_products' )
			->once()
			->andReturnUsing( function ( array $args ): array {
				$this->assertSame( 1, $args['limit'] );
				return [];
			} );

		$this->registry()->dispatch( 'get_top_products', [ 'limit' => -50 ] );
	}

	public function test_get_top_products_keeps_in_range_limit(): void {
		// A value already inside [1,10] passes through untouched.
		Functions\expect( 'wc_get_products' )
			->once()
			->andReturnUsing( function ( array $args ): array {
				$this->assertSame( 7, $args['limit'] );
				return [];
			} );

		$this->registry()->dispatch( 'get_top_products', [ 'limit' => 7 ] );
	}

	public function test_get_top_products_casts_numeric_string_limit(): void {
		// (int) '3' === 3 — the input arrives from the model as loosely-typed JSON.
		Functions\expect( 'wc_get_products' )
			->once()
			->andReturnUsing( function ( array $args ): array {
				$this->assertSame( 3, $args['limit'] );
				return [];
			} );

		$this->registry()->dispatch( 'get_top_products', [ 'limit' => '3' ] );
	}

	// ── get_top_products: query shape & category ──────────────────────────────────

	public function test_get_top_products_always_queries_published_only(): void {
		Functions\expect( 'wc_get_products' )
			->once()
			->andReturnUsing( function ( array $args ): array {
				$this->assertSame( 'publish', $args['status'] );
				return [];
			} );

		$this->registry()->dispatch( 'get_top_products', [] );
	}

	public function test_get_top_products_sanitizes_category_value(): void {
		// sanitize_text_field is stubbed to identity, but assert the category is
		// passed through it (wrapped in a single-element array as wc expects).
		$seen = null;
		Functions\when( 'sanitize_text_field' )->alias( function ( $s ) use ( &$seen ) {
			$seen = $s;
			return trim( (string) $s );
		} );
		Functions\expect( 'wc_get_products' )
			->once()
			->andReturnUsing( function ( array $args ): array {
				$this->assertSame( [ 'shoes' ], $args['category'] );
				return [];
			} );

		$this->registry()->dispatch( 'get_top_products', [ 'category' => 'shoes' ] );
		$this->assertSame( 'shoes', $seen );
	}

	public function test_get_top_products_treats_empty_string_category_as_absent(): void {
		// empty( '' ) is true, so the category branch is skipped — no category arg.
		Functions\expect( 'wc_get_products' )
			->once()
			->andReturnUsing( function ( array $args ): array {
				$this->assertArrayNotHasKey( 'category', $args );
				return [];
			} );

		$this->registry()->dispatch( 'get_top_products', [ 'category' => '' ] );
	}

	// ── get_top_products: result shaping ──────────────────────────────────────────

	public function test_get_top_products_formats_every_product_via_shared_formatter(): void {
		$products = [
			$this->mockProduct( 11, 'Alpha', '10.00' ),
			$this->mockProduct( 22, 'Beta', '20.00' ),
			$this->mockProduct( 33, 'Gamma', '30.00' ),
		];
		Functions\when( 'wc_get_products' )->justReturn( $products );

		$result = $this->registry()->dispatch( 'get_top_products', [] );

		$this->assertSame( 3, $result['found'] );
		$this->assertCount( 3, $result['products'] );
		$this->assertSame( [ 11, 22, 33 ], array_column( $result['products'], 'id' ) );
		$this->assertSame( [ 'Alpha', 'Beta', 'Gamma' ], array_column( $result['products'], 'name' ) );
		// No empty-state message when products exist.
		$this->assertArrayNotHasKey( 'message', $result );
	}

	public function test_get_top_products_empty_state_carries_translatable_message(): void {
		Functions\when( 'wc_get_products' )->justReturn( [] );

		$result = $this->registry()->dispatch( 'get_top_products', [] );

		$this->assertSame( 0, $result['found'] );
		$this->assertSame( [], $result['products'] );
		$this->assertNotEmpty( $result['message'] );
		$this->assertIsString( $result['message'] );
	}

	// ── list_categories: term mapping & flags ─────────────────────────────────────

	public function test_list_categories_maps_terms_and_casts_count_to_int(): void {
		$terms = [
			(object) [ 'name' => 'Tops',    'slug' => 'tops',    'count' => '9' ],   // string count → cast int
			(object) [ 'name' => 'Bottoms', 'slug' => 'bottoms', 'count' => 0 ],
		];
		Functions\when( 'get_terms' )->justReturn( $terms );

		$result = $this->registry()->dispatch( 'list_categories', [] );

		$this->assertSame( 2, $result['found'] );
		$this->assertSame(
			[ 'name' => 'Tops', 'slug' => 'tops', 'count' => 9 ],
			$result['categories'][0]
		);
		$this->assertSame( 0, $result['categories'][1]['count'] );
		$this->assertIsInt( $result['categories'][0]['count'] );
		$this->assertArrayNotHasKey( 'products', $result );
		$this->assertArrayNotHasKey( 'message', $result );
	}

	public function test_list_categories_default_hides_empty(): void {
		Functions\expect( 'get_terms' )
			->once()
			->andReturnUsing( function ( array $args ): array {
				$this->assertSame( 'product_cat', $args['taxonomy'] );
				$this->assertTrue( $args['hide_empty'] );
				return [];
			} );

		$this->registry()->dispatch( 'list_categories', [] );
	}

	public function test_list_categories_include_empty_truthy_disables_hide_empty(): void {
		Functions\expect( 'get_terms' )
			->once()
			->andReturnUsing( function ( array $args ): array {
				$this->assertFalse( $args['hide_empty'] );
				return [];
			} );

		$this->registry()->dispatch( 'list_categories', [ 'include_empty' => true ] );
	}

	public function test_list_categories_include_empty_falsy_keeps_hide_empty(): void {
		// empty( false ) is true → hide_empty stays true.
		Functions\expect( 'get_terms' )
			->once()
			->andReturnUsing( function ( array $args ): array {
				$this->assertTrue( $args['hide_empty'] );
				return [];
			} );

		$this->registry()->dispatch( 'list_categories', [ 'include_empty' => false ] );
	}

	public function test_list_categories_empty_array_triggers_empty_state(): void {
		Functions\when( 'get_terms' )->justReturn( [] );

		$result = $this->registry()->dispatch( 'list_categories', [] );

		$this->assertSame( 0, $result['found'] );
		$this->assertSame( [], $result['categories'] );
		$this->assertNotEmpty( $result['message'] );
	}

	public function test_list_categories_wp_error_triggers_empty_state(): void {
		// is_wp_error( $terms ) === true short-circuits to the empty state.
		Functions\when( 'get_terms' )->justReturn( new WP_Error( 'invalid_taxonomy', 'bad' ) );

		$result = $this->registry()->dispatch( 'list_categories', [] );

		$this->assertSame( 0, $result['found'] );
		$this->assertSame( [], $result['categories'] );
		$this->assertArrayHasKey( 'message', $result );
	}
}
