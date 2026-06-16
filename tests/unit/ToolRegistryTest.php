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

    /**
     * Snapshot of the registry's static first-party pack providers, captured in
     * setUp and restored in tearDown.
     *
     * Pack providers live in a STATIC list (so they survive a singleton instance
     * reset — that is the whole point of register_pack). Feature packs such as the
     * catalog pack self-register into that list at file load. These isolation
     * tests assert on the exact built-in tool set + the third-party filter path, so
     * they must run against a registry with NO first-party packs. We clear the list
     * for each test and restore the original afterwards, so we neither see the
     * globally-registered packs nor permanently drop them for other suites.
     *
     * @var array<int, callable>
     */
    private array $pack_snapshot = [];

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();

        $this->pack_snapshot = $this->snapshot_packs();
        Fahad_AI_Tool_Registry::reset_packs();

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
        $this->restore_packs( $this->pack_snapshot );
        Monkey\tearDown();
        parent::tearDown();
    }

    /** Read the registry's static pack-provider list via reflection. */
    private function snapshot_packs(): array {
        $prop = new ReflectionProperty( Fahad_AI_Tool_Registry::class, 'pack_providers' );
        return (array) $prop->getValue();
    }

    /** Restore the registry's static pack-provider list via reflection. */
    private function restore_packs( array $providers ): void {
        ( new ReflectionProperty( Fahad_AI_Tool_Registry::class, 'pack_providers' ) )->setValue( null, $providers );
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

    // ── first-party packs: register_pack() (drop-in feature packs) ────────────

    /**
     * A feature pack registers its tools by handing the registry a PROVIDER — a
     * callable `fn( array $tools ): array` that appends its definitions. This is
     * the deterministic, WordPress-filter-free path feature packs (catalog,
     * shipping, …) use so they are picked up identically in production and tests.
     *
     * A tool registered via register_pack() must be advertised to the model via
     * specs() (callback hidden) AND be dispatchable, exactly like a built-in.
     */
    public function test_register_pack_tool_is_advertised_and_dispatchable(): void {
        $invoked = false;

        Fahad_AI_Tool_Registry::register_pack(
            static function ( array $tools ) use ( &$invoked ): array {
                $tools[] = [
                    'name'        => 'pack_tool',
                    'description' => 'A tool contributed by a first-party feature pack.',
                    'parameters'  => [
                        'type'       => 'object',
                        'properties' => [
                            'q' => [ 'type' => 'string', 'description' => 'A query' ],
                        ],
                    ],
                    'callback'    => static function ( array $input ) use ( &$invoked ): array {
                        $invoked = true;
                        return [ 'ok' => true, 'q' => $input['q'] ?? '' ];
                    },
                ];
                return $tools;
            }
        );

        $registry = $this->registry();

        // 1. Advertised to the model (no callback leaked), alongside the built-ins.
        $names = array_column( $registry->specs(), 'name' );
        $this->assertContains( 'pack_tool', $names );
        $this->assertContains( 'search_products', $names );
        $this->assertCount( 6, $names );
        $spec = array_column( $registry->specs(), null, 'name' )['pack_tool'];
        $this->assertArrayNotHasKey( 'callback', $spec );

        // 2. Dispatchable: the pack's callback runs and its result is returned.
        $result = $registry->dispatch( 'pack_tool', [ 'q' => 'hi' ] );
        $this->assertTrue( $invoked, 'pack tool callback was not invoked' );
        $this->assertSame( 'hi', $result['q'] );
    }

    /**
     * Pack providers are registered statically and must SURVIVE a reset of the
     * registry singleton instance. The eval harness and the unit suites reset the
     * instance between cases (to clear the per-instance cached tool list); if that
     * reset dropped the registered packs, every feature pack would vanish after the
     * first reset. This guards the exact invariant from the refactor: providers are
     * static (survive instance reset); only the built tool LIST is per-instance.
     */
    public function test_registered_packs_survive_a_singleton_instance_reset(): void {
        Fahad_AI_Tool_Registry::register_pack(
            static function ( array $tools ): array {
                $tools[] = [
                    'name'        => 'durable_pack_tool',
                    'description' => 'Should still be present after an instance reset.',
                    'parameters'  => [ 'type' => 'object', 'properties' => new stdClass() ],
                    'callback'    => static fn( array $input ): array => [ 'ok' => true ],
                ];
                return $tools;
            }
        );

        // First build, then blow away the instance (NOT the static provider list).
        $this->assertContains( 'durable_pack_tool', array_column( $this->registry()->specs(), 'name' ) );
        ( new ReflectionProperty( Fahad_AI_Tool_Registry::class, 'instance' ) )->setValue( null, null );

        // A brand-new instance must rebuild its list and STILL include the pack.
        $names = array_column( Fahad_AI_Tool_Registry::instance()->specs(), 'name' );
        $this->assertContains( 'durable_pack_tool', $names, 'pack provider was lost on singleton reset' );
    }

    /**
     * The first-party pack path and the third-party `fahad_ai_register_tools`
     * filter must COEXIST: a tool from each is present and dispatchable in the same
     * registry. This proves the refactor layered packs in front of the filter
     * without breaking the established add-on extension point.
     */
    public function test_first_party_pack_and_third_party_filter_coexist(): void {
        $pack_ran   = false;
        $filter_ran = false;

        // First-party pack contributes pack_tool.
        Fahad_AI_Tool_Registry::register_pack(
            static function ( array $tools ) use ( &$pack_ran ): array {
                $tools[] = [
                    'name'        => 'pack_tool',
                    'description' => 'First-party pack tool.',
                    'parameters'  => [ 'type' => 'object', 'properties' => new stdClass() ],
                    'callback'    => static function ( array $input ) use ( &$pack_ran ): array {
                        $pack_ran = true;
                        return [ 'source' => 'pack' ];
                    },
                ];
                return $tools;
            }
        );

        // Third-party add-on contributes wallet_balance via the public filter.
        Functions\when( 'apply_filters' )->alias( function ( $hook, $tools ) use ( &$filter_ran ) {
            if ( 'fahad_ai_register_tools' === $hook ) {
                $tools[] = [
                    'name'        => 'wallet_balance',
                    'description' => 'Third-party wallet balance.',
                    'parameters'  => [ 'type' => 'object', 'properties' => new stdClass() ],
                    'callback'    => function ( array $input ) use ( &$filter_ran ): array {
                        $filter_ran = true;
                        return [ 'source' => 'filter' ];
                    },
                ];
            }
            return $tools;
        } );

        $registry = $this->registry();

        // Both tools advertised next to the five built-ins (5 + 2 = 7).
        $names = array_column( $registry->specs(), 'name' );
        $this->assertContains( 'pack_tool', $names );
        $this->assertContains( 'wallet_balance', $names );
        $this->assertContains( 'search_products', $names );
        $this->assertCount( 7, $names );

        // Both are independently dispatchable.
        $this->assertSame( 'pack', $registry->dispatch( 'pack_tool', [] )['source'] );
        $this->assertSame( 'filter', $registry->dispatch( 'wallet_balance', [] )['source'] );
        $this->assertTrue( $pack_ran, 'first-party pack callback did not run' );
        $this->assertTrue( $filter_ran, 'third-party filter callback did not run' );
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

    // ── personal-data tools: central login gate (defense in depth) ────────────

    /**
     * Register a tool flagged `'personal' => true` via the extension filter. The
     * callback flips $invoked so a test can assert whether dispatch() reached it.
     *
     * @param bool &$invoked Set true by the callback when (and only when) it runs.
     */
    private function register_personal_tool( bool &$invoked ): void {
        Functions\when( 'apply_filters' )->alias( function ( $hook, $tools ) use ( &$invoked ) {
            if ( 'fahad_ai_register_tools' === $hook ) {
                $tools[] = [
                    'name'        => 'order_status',
                    'description' => 'Look up the caller\'s most recent order status.',
                    'parameters'  => [ 'type' => 'object', 'properties' => new stdClass() ],
                    'personal'    => true,
                    'callback'    => function ( array $input ) use ( &$invoked ): array {
                        $invoked = true;
                        return [ 'status' => 'Shipped' ];
                    },
                ];
            }
            return $tools;
        } );
    }

    /**
     * GUEST-BLOCK (headline acceptance criterion): a personal-flagged tool must
     * be gated centrally. When the caller is a guest, dispatch() returns the
     * login-required error and the callback is NEVER invoked — a personal tool
     * cannot leak by forgetting to check login itself.
     */
    public function test_personal_tool_blocks_guest_and_never_invokes_callback(): void {
        Functions\when( 'is_user_logged_in' )->justReturn( false );

        $invoked = false;
        $this->register_personal_tool( $invoked );

        $result = $this->registry()->dispatch( 'order_status', [] );

        $this->assertFalse( $invoked, 'guest reached a personal tool callback — login gate failed' );
        $this->assertArrayHasKey( 'requires_login', $result );
        $this->assertTrue( $result['requires_login'] );
        $this->assertArrayHasKey( 'error', $result );
        // The block is the login gate, not an "unknown tool" miss.
        $this->assertStringNotContainsString( 'Unknown tool', $result['error'] );
    }

    /**
     * For a logged-in caller the central gate is satisfied, so dispatch() proceeds
     * to the personal tool's callback exactly like a normal tool. (Per-RECORD
     * ownership is still the callback's job — see Fahad_AI_Auth::user_owns.)
     */
    public function test_personal_tool_runs_for_logged_in_user(): void {
        Functions\when( 'is_user_logged_in' )->justReturn( true );

        $invoked = false;
        $this->register_personal_tool( $invoked );

        $result = $this->registry()->dispatch( 'order_status', [] );

        $this->assertTrue( $invoked, 'logged-in user did not reach the personal tool callback' );
        $this->assertSame( 'Shipped', $result['status'] );
        $this->assertArrayNotHasKey( 'requires_login', $result );
    }

    /**
     * A NON-personal (normal) tool must be unaffected by the gate: a guest can
     * still call it. We register a normal tool and dispatch it as a guest — the
     * callback runs and no login error is returned.
     */
    public function test_non_personal_tool_is_not_login_gated_for_guests(): void {
        Functions\when( 'is_user_logged_in' )->justReturn( false );

        $invoked = false;
        Functions\when( 'apply_filters' )->alias( function ( $hook, $tools ) use ( &$invoked ) {
            if ( 'fahad_ai_register_tools' === $hook ) {
                $tools[] = [
                    'name'        => 'store_hours',
                    'description' => 'Public store hours — no login needed.',
                    'parameters'  => [ 'type' => 'object', 'properties' => new stdClass() ],
                    'callback'    => function ( array $input ) use ( &$invoked ): array {
                        $invoked = true;
                        return [ 'hours' => '9-5' ];
                    },
                ];
            }
            return $tools;
        } );

        $result = $this->registry()->dispatch( 'store_hours', [] );

        $this->assertTrue( $invoked, 'a non-personal tool was wrongly gated' );
        $this->assertSame( '9-5', $result['hours'] );
        $this->assertArrayNotHasKey( 'requires_login', $result );
    }

    /**
     * The declarative `personal` flag is an internal authorization detail and must
     * NOT be advertised to the model: specs() exposes only name/description/
     * parameters (the existing contract), never the flag or the callback.
     */
    public function test_personal_flag_is_not_leaked_by_specs(): void {
        $invoked = false;
        $this->register_personal_tool( $invoked );

        $spec = array_column( $this->registry()->specs(), null, 'name' )['order_status'];

        $this->assertArrayHasKey( 'name', $spec );
        $this->assertArrayHasKey( 'description', $spec );
        $this->assertArrayHasKey( 'parameters', $spec );
        $this->assertArrayNotHasKey( 'personal', $spec );
        $this->assertArrayNotHasKey( 'callback', $spec );
    }
}
