<?php
/**
 * Unit tests for Fahad_AI_Tool_Registry.
 *
 * Red → Green → Refactor cycle.
 * WP/WC functions mocked via Brain\Monkey; WC objects via Mockery; singletons
 * reset via reflection (mirrors ToolsTest / ApiHandlerTest).
 *
 * The registry is the single source of truth for tool specs (fed to the LLM)
 * AND tool execution (dispatch). Built-ins are seeded in code and then exposed
 * to third parties through `apply_filters( 'fahad_ai_register_tools', $tools )`.
 */

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;

class ToolRegistryTest extends TestCase {

    use MockeryPHPUnitIntegration;

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();

        // Mirror the tool-layer stubs ToolsTest uses, so a real built-in tool
        // (search_products) can execute through dispatch().
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

    /**
     * Fresh registry instance with the cached tool list cleared.
     * Also resets Fahad_AI_Tools so the built-in callbacks bind to a clean
     * tools singleton.
     */
    private function registry(): Fahad_AI_Tool_Registry {
        ( new ReflectionProperty( Fahad_AI_Tools::class, 'instance' ) )->setValue( null, null );
        ( new ReflectionProperty( Fahad_AI_Tool_Registry::class, 'instance' ) )->setValue( null, null );
        return Fahad_AI_Tool_Registry::instance();
    }

    // ── built-in registration ───────────────────────────────────────────────

    public function test_builtin_registry_contains_exactly_the_five_tools(): void {
        $names = array_column( $this->registry()->specs(), 'name' );

        sort( $names );
        $expected = [ 'add_to_cart', 'get_product_details', 'remove_from_cart', 'search_products', 'view_cart' ];
        sort( $expected );

        $this->assertSame( $expected, $names );
    }

    public function test_specs_never_expose_the_callback(): void {
        foreach ( $this->registry()->specs() as $spec ) {
            $this->assertArrayNotHasKey( 'callback', $spec, "Tool '{$spec['name']}' spec leaks its callback" );
            // Spec must still carry the LLM-facing fields.
            $this->assertArrayHasKey( 'name', $spec );
            $this->assertArrayHasKey( 'description', $spec );
            $this->assertArrayHasKey( 'parameters', $spec );
        }
    }

    // ── dispatch() routing ──────────────────────────────────────────────────

    public function test_dispatch_routes_to_the_real_builtin_tool(): void {
        $product = Mockery::mock( WC_Product::class );
        $product->shouldReceive( 'get_id' )->andReturn( 1 );
        $product->shouldReceive( 'get_name' )->andReturn( 'Blue Jeans' );
        $product->shouldReceive( 'get_price' )->andReturn( '59.99' );
        $product->shouldReceive( 'get_regular_price' )->andReturn( '59.99' );
        $product->shouldReceive( 'get_sale_price' )->andReturn( '' );
        $product->shouldReceive( 'is_on_sale' )->andReturn( false );
        $product->shouldReceive( 'is_in_stock' )->andReturn( true );
        $product->shouldReceive( 'get_short_description' )->andReturn( '' );
        $product->shouldReceive( 'get_image_id' )->andReturn( 0 );
        Functions\when( 'wc_get_products' )->justReturn( [ $product ] );

        $result = $this->registry()->dispatch( 'search_products', [ 'query' => 'jeans' ] );

        $this->assertSame( 1, $result['found'] );
        $this->assertSame( 'Blue Jeans', $result['products'][0]['name'] );
    }

    public function test_dispatch_unknown_tool_returns_error_naming_the_tool(): void {
        $result = $this->registry()->dispatch( 'nonexistent_tool', [] );

        $this->assertArrayHasKey( 'error', $result );
        $this->assertStringContainsString( 'nonexistent_tool', $result['error'] );
    }

    // ── error isolation ─────────────────────────────────────────────────────

    public function test_dispatch_isolates_a_throwing_callback(): void {
        // A third-party tool whose callback throws must NOT bubble the exception
        // up and fatal the request — dispatch returns an error array instead.
        Functions\when( 'apply_filters' )->alias( function ( $hook, $tools ) {
            if ( 'fahad_ai_register_tools' === $hook ) {
                $tools[] = [
                    'name'        => 'boom',
                    'description' => 'Always explodes.',
                    'parameters'  => [ 'type' => 'object', 'properties' => new stdClass() ],
                    'callback'    => function ( array $input ): array {
                        throw new \RuntimeException( 'kaboom' );
                    },
                ];
            }
            return $tools;
        } );

        $result = $this->registry()->dispatch( 'boom', [] );

        $this->assertIsArray( $result );
        $this->assertArrayHasKey( 'error', $result );
        // The error must come from catching the throw (callback WAS reached),
        // not from the tool being unknown — otherwise the test would pass even
        // if the tool never registered.
        $this->assertStringNotContainsString( 'Unknown tool', $result['error'] );
    }

    // ── third-party extension (headline acceptance criterion) ────────────────

    public function test_third_party_tool_is_advertised_and_dispatchable(): void {
        $invoked = false;

        Functions\when( 'apply_filters' )->alias( function ( $hook, $tools ) use ( &$invoked ) {
            if ( 'fahad_ai_register_tools' === $hook ) {
                $tools[] = [
                    'name'        => 'wallet_balance',
                    'description' => 'Return the customer wallet balance.',
                    'parameters'  => [
                        'type'       => 'object',
                        'properties' => [
                            'currency' => [ 'type' => 'string', 'description' => 'ISO currency code' ],
                        ],
                    ],
                    'callback'    => function ( array $input ) use ( &$invoked ): array {
                        $invoked = true;
                        return [ 'balance' => '42.00', 'currency' => $input['currency'] ?? 'USD' ];
                    },
                ];
            }
            return $tools;
        } );

        $registry = $this->registry();

        // 1. It is advertised to the model via specs() (with no callback leaked).
        $names = array_column( $registry->specs(), 'name' );
        $this->assertContains( 'wallet_balance', $names );
        $wallet_spec = array_column( $registry->specs(), null, 'name' )['wallet_balance'];
        $this->assertArrayNotHasKey( 'callback', $wallet_spec );
        $this->assertSame( 'Return the customer wallet balance.', $wallet_spec['description'] );

        // 2. The agent can call it: dispatch invokes the callback and returns it.
        $result = $registry->dispatch( 'wallet_balance', [ 'currency' => 'EUR' ] );
        $this->assertTrue( $invoked, 'third-party callback was not invoked' );
        $this->assertSame( '42.00', $result['balance'] );
        $this->assertSame( 'EUR', $result['currency'] );

        // Built-ins still present alongside the new tool.
        $this->assertContains( 'search_products', $names );
        $this->assertCount( 6, $names );
    }

    // ── validation: malformed third-party entries are skipped ────────────────

    public function test_entry_missing_callback_is_skipped(): void {
        Functions\when( 'apply_filters' )->alias( function ( $hook, $tools ) {
            if ( 'fahad_ai_register_tools' === $hook ) {
                $tools[] = [
                    'name'        => 'no_callback',
                    'description' => 'Has a spec but no callback.',
                    'parameters'  => [ 'type' => 'object', 'properties' => new stdClass() ],
                    // 'callback' intentionally omitted.
                ];
            }
            return $tools;
        } );

        $registry = $this->registry();

        $this->assertNotContains( 'no_callback', array_column( $registry->specs(), 'name' ) );
        $this->assertArrayHasKey( 'error', $registry->dispatch( 'no_callback', [] ) );
    }

    public function test_entry_with_non_callable_callback_is_skipped(): void {
        Functions\when( 'apply_filters' )->alias( function ( $hook, $tools ) {
            if ( 'fahad_ai_register_tools' === $hook ) {
                $tools[] = [
                    'name'        => 'bad_callback',
                    'description' => 'Callback is a string, not callable.',
                    'parameters'  => [ 'type' => 'object', 'properties' => new stdClass() ],
                    'callback'    => 'definitely_not_a_function_name',
                ];
            }
            return $tools;
        } );

        $this->assertNotContains( 'bad_callback', array_column( $this->registry()->specs(), 'name' ) );
    }

    public function test_entry_missing_name_is_skipped(): void {
        Functions\when( 'apply_filters' )->alias( function ( $hook, $tools ) {
            if ( 'fahad_ai_register_tools' === $hook ) {
                $tools[] = [
                    'description' => 'No name at all.',
                    'parameters'  => [ 'type' => 'object', 'properties' => new stdClass() ],
                    'callback'    => fn( array $in ) => [ 'ok' => true ],
                ];
            }
            return $tools;
        } );

        // Only the 5 built-ins survive; the anonymous entry is dropped.
        $this->assertCount( 5, $this->registry()->specs() );
    }

    public function test_entry_with_invalid_parameters_schema_is_skipped(): void {
        Functions\when( 'apply_filters' )->alias( function ( $hook, $tools ) {
            if ( 'fahad_ai_register_tools' === $hook ) {
                // parameters missing the required 'properties' key.
                $tools[] = [
                    'name'        => 'bad_schema',
                    'description' => 'Parameters lack properties.',
                    'parameters'  => [ 'type' => 'object' ],
                    'callback'    => fn( array $in ) => [ 'ok' => true ],
                ];
            }
            return $tools;
        } );

        $this->assertNotContains( 'bad_schema', array_column( $this->registry()->specs(), 'name' ) );
    }
}
