<?php
/**
 * Supplementary coverage tests for Fahad_AI_Tool_Registry.
 *
 * Targets the validation guard branches and reset() path that the primary
 * ToolRegistryTest does not exercise: a non-array entry in the tool list, an
 * entry whose `description` is missing / not a string, and reset() clearing the
 * per-instance cached tool list. Conventions mirror ToolRegistryTest /
 * ApiHandlerTest: Brain\Monkey for WP/WC functions, reflection to reset the
 * singletons and to snapshot/restore the static pack-provider list.
 */

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;

class CoverageToolRegistryTest extends TestCase {

    use MockeryPHPUnitIntegration;

    /** Snapshot of the registry's static first-party pack providers. */
    private array $pack_snapshot = [];

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();

        $this->pack_snapshot = $this->snapshot_packs();
        Fahad_AI_Tool_Registry::reset_packs();

        // Tool-layer stubs so the five real built-ins build cleanly and
        // get_tools() can read the merchant tool-gating option (default: none
        // disabled, so these guard-branch tests are unaffected by gating).
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

    /** Fresh registry + tools singletons with the cached tool list cleared. */
    private function registry(): Fahad_AI_Tool_Registry {
        ( new ReflectionProperty( Fahad_AI_Tools::class, 'instance' ) )->setValue( null, null );
        ( new ReflectionProperty( Fahad_AI_Tool_Registry::class, 'instance' ) )->setValue( null, null );
        return Fahad_AI_Tool_Registry::instance();
    }

    // ── validate(): non-array entries are skipped (line 280) ──────────────────

    /**
     * A non-array entry returned by the filter (e.g. a scalar or null injected by
     * a buggy add-on) must be skipped by validate() WITHOUT fataling, leaving the
     * six built-ins intact. This drives the `! is_array( $tool )` guard.
     */
    public function test_non_array_entry_is_skipped(): void {
        Functions\when( 'apply_filters' )->alias( function ( $hook, $tools ) {
            if ( 'fahad_ai_register_tools' === $hook ) {
                // Garbage non-array entries a misbehaving add-on might append.
                $tools[] = 'not-an-array';
                $tools[] = 42;
                $tools[] = null;
            }
            return $tools;
        } );

        $names = array_column( $this->registry()->specs(), 'name' );

        // None of the junk entries can become a tool; only the six built-ins remain.
        $this->assertCount( 6, $names );
        $this->assertContains( 'search_products', $names );
        $this->assertNotContains( 'not-an-array', $names );
    }

    /**
     * Even with the non-array junk present, a VALID sibling entry in the same
     * batch must still register, proving validate() `continue`s past the bad
     * entry rather than aborting the whole loop.
     */
    public function test_valid_entry_survives_alongside_non_array_junk(): void {
        Functions\when( 'apply_filters' )->alias( function ( $hook, $tools ) {
            if ( 'fahad_ai_register_tools' === $hook ) {
                $tools[] = 7; // non-array, must be skipped (line 280)
                $tools[] = [
                    'name'        => 'good_tool',
                    'description' => 'A well-formed tool sharing the batch with junk.',
                    'parameters'  => [ 'type' => 'object', 'properties' => new stdClass() ],
                    'callback'    => static fn( array $in ): array => [ 'ok' => true ],
                ];
            }
            return $tools;
        } );

        $names = array_column( $this->registry()->specs(), 'name' );

        $this->assertContains( 'good_tool', $names );
        $this->assertNotContains( 7, $names, true );
        $this->assertCount( 7, $names );
    }

    // ── validate(): bad/absent description is skipped (line 289) ──────────────

    /**
     * An entry with a non-string `description` (here an array) must be skipped , 
     * validate() requires a string description before the tool is advertised.
     */
    public function test_entry_with_non_string_description_is_skipped(): void {
        Functions\when( 'apply_filters' )->alias( function ( $hook, $tools ) {
            if ( 'fahad_ai_register_tools' === $hook ) {
                $tools[] = [
                    'name'        => 'array_desc',
                    'description' => [ 'not', 'a', 'string' ], // invalid type
                    'parameters'  => [ 'type' => 'object', 'properties' => new stdClass() ],
                    'callback'    => static fn( array $in ): array => [ 'ok' => true ],
                ];
            }
            return $tools;
        } );

        $this->assertNotContains( 'array_desc', array_column( $this->registry()->specs(), 'name' ) );
    }

    /**
     * An entry MISSING `description` entirely must also be skipped (the
     * `! isset( $tool['description'] )` half of the same guard), while a valid
     * sibling in the batch still registers.
     */
    public function test_entry_missing_description_is_skipped_but_valid_sibling_registers(): void {
        Functions\when( 'apply_filters' )->alias( function ( $hook, $tools ) {
            if ( 'fahad_ai_register_tools' === $hook ) {
                $tools[] = [
                    'name'       => 'no_description',
                    // 'description' intentionally omitted.
                    'parameters' => [ 'type' => 'object', 'properties' => new stdClass() ],
                    'callback'   => static fn( array $in ): array => [ 'ok' => true ],
                ];
                $tools[] = [
                    'name'        => 'has_description',
                    'description' => 'Properly described sibling.',
                    'parameters'  => [ 'type' => 'object', 'properties' => new stdClass() ],
                    'callback'    => static fn( array $in ): array => [ 'ok' => true ],
                ];
            }
            return $tools;
        } );

        $names = array_column( $this->registry()->specs(), 'name' );

        $this->assertNotContains( 'no_description', $names );
        $this->assertContains( 'has_description', $names );
        // The skipped entry must also be undispatchable (treated as unknown).
        $this->assertArrayHasKey( 'error', $this->registry()->dispatch( 'no_description', [] ) );
    }

    // ── reset(): clears the per-instance cached tool list (line 313) ──────────

    /**
     * reset() must null the cached tool list so the NEXT call rebuilds it. We
     * build once with a filter that contributes a tool, assert it is present,
     * then swap the filter so it contributes nothing, call reset(), and assert
     * the rebuilt list no longer contains the tool, proving reset() forced a
     * fresh build rather than returning the stale cache.
     */
    public function test_reset_forces_a_rebuild_of_the_tool_list(): void {
        $contribute = true;

        Functions\when( 'apply_filters' )->alias( function ( $hook, $tools ) use ( &$contribute ) {
            if ( 'fahad_ai_register_tools' === $hook && $contribute ) {
                $tools[] = [
                    'name'        => 'ephemeral',
                    'description' => 'Present only while $contribute is true.',
                    'parameters'  => [ 'type' => 'object', 'properties' => new stdClass() ],
                    'callback'    => static fn( array $in ): array => [ 'ok' => true ],
                ];
            }
            return $tools;
        } );

        $registry = $this->registry();

        // First build caches the list WITH the ephemeral tool.
        $this->assertContains( 'ephemeral', array_column( $registry->specs(), 'name' ) );

        // Stop contributing, but WITHOUT reset() the cache would still serve it.
        $contribute = false;
        $this->assertContains(
            'ephemeral',
            array_column( $registry->specs(), 'name' ),
            'cached list should still hold the tool before reset()'
        );

        // reset() nulls the cache (line 313) → next specs() rebuilds from scratch.
        $registry->reset();

        $this->assertNotContains(
            'ephemeral',
            array_column( $registry->specs(), 'name' ),
            'reset() did not clear the cached tool list'
        );
        // Built-ins are rebuilt and still present.
        $this->assertContains( 'search_products', array_column( $registry->specs(), 'name' ) );
    }

    /** reset() is a no-op-safe void: callable before any build, returns null. */
    public function test_reset_before_first_build_is_safe(): void {
        $registry = $this->registry();

        $this->assertNull( $registry->reset() );

        // The list still builds correctly afterwards.
        $this->assertContains( 'search_products', array_column( $registry->specs(), 'name' ) );
    }

    // ── apply_tool_gating(): merchant-disabled tools (issue #56) ──────────────

    /**
     * Register a third-party tool, then mark it disabled via the
     * `fahad_ai_disabled_tools` option. apply_tool_gating() must drop it from the
     * built list (drives array_flip + the foreach/unset of a non-protected tool),
     * while every other tool survives.
     */
    public function test_disabled_third_party_tool_is_gated_out(): void {
        Functions\when( 'apply_filters' )->alias( function ( $hook, $tools ) {
            if ( 'fahad_ai_register_tools' === $hook ) {
                $tools[] = [
                    'name'        => 'wallet_balance',
                    'description' => 'Disabled by the merchant from admin.',
                    'parameters'  => [ 'type' => 'object', 'properties' => new stdClass() ],
                    'callback'    => static fn( array $in ): array => [ 'ok' => true ],
                ];
            }
            return $tools;
        } );

        // Merchant disabled wallet_balance (overrides the empty default stub).
        Functions\when( 'get_option' )->alias(
            fn( $key, $default = '' ) => 'fahad_ai_disabled_tools' === $key ? [ 'wallet_balance' ] : $default
        );

        $names = array_column( $this->registry()->specs(), 'name' );

        $this->assertNotContains( 'wallet_balance', $names );
        // The six built-ins are untouched by gating.
        $this->assertContains( 'search_products', $names );
        $this->assertCount( 6, $names );
        // And the gated tool is no longer dispatchable.
        $this->assertArrayHasKey( 'error', $this->registry()->dispatch( 'wallet_balance', [] ) );
    }

    /**
     * Built-in tools are a protected floor: even if a merchant (or a tampered
     * option) lists a built-in name in the disabled set, it must NOT be removed.
     * This drives the `! isset( $protected[ $name ] )` FALSE branch, the unset is
     * skipped for a protected name.
     */
    public function test_builtin_tool_cannot_be_disabled_even_if_listed(): void {
        Functions\when( 'get_option' )->alias(
            fn( $key, $default = '' ) => 'fahad_ai_disabled_tools' === $key ? [ 'search_products' ] : $default
        );

        $names = array_column( $this->registry()->specs(), 'name' );

        // search_products is a protected built-in; it survives the gating attempt.
        $this->assertContains( 'search_products', $names );
        $this->assertCount( 6, $names );
    }

    /**
     * Non-string entries in the disabled option (e.g. an int slipped in by a
     * tampered option) are ignored by the `is_string( $name )` guard and must not
     * disturb the tool list. A genuine disabled tool in the same list is still
     * removed, proving the loop continues past the junk entry.
     */
    public function test_non_string_disabled_entry_is_ignored(): void {
        Functions\when( 'apply_filters' )->alias( function ( $hook, $tools ) {
            if ( 'fahad_ai_register_tools' === $hook ) {
                $tools[] = [
                    'name'        => 'loyalty_points',
                    'description' => 'Add-on tool the merchant disabled.',
                    'parameters'  => [ 'type' => 'object', 'properties' => new stdClass() ],
                    'callback'    => static fn( array $in ): array => [ 'ok' => true ],
                ];
            }
            return $tools;
        } );

        Functions\when( 'get_option' )->alias(
            fn( $key, $default = '' ) => 'fahad_ai_disabled_tools' === $key
                ? [ 42, 'loyalty_points', 'never_registered' ]
                : $default
        );

        $names = array_column( $this->registry()->specs(), 'name' );

        // The int 42 and the unknown name are no-ops; the real disabled tool is gone.
        $this->assertNotContains( 'loyalty_points', $names );
        $this->assertContains( 'search_products', $names );
        $this->assertCount( 6, $names );
    }

    /**
     * A non-array option value (e.g. a corrupt `false`/string stored under the
     * key) must short-circuit gating to identity, the tool list is returned
     * unchanged. This drives the `! is_array( $disabled )` guard.
     */
    public function test_non_array_disabled_option_is_treated_as_no_gating(): void {
        Functions\when( 'get_option' )->alias(
            fn( $key, $default = '' ) => 'fahad_ai_disabled_tools' === $key ? 'corrupt' : $default
        );

        $names = array_column( $this->registry()->specs(), 'name' );

        $this->assertContains( 'search_products', $names );
        $this->assertCount( 6, $names );
    }
}
