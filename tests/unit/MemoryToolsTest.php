<?php
/**
 * Unit tests for Fahad_AI_Memory_Tools (issue #20: personalization & cross-session
 * memory, strictly OPT-IN).
 *
 * Red → Green → Refactor. Conventions mirror OrderToolsTest / WalletToolsTest (the
 * other personal packs): WP functions mocked via Brain\Monkey; the registry
 * singleton + its static pack list snapshotted/restored so a case here neither
 * inherits another suite's packs nor leaks the memory pack we register.
 *
 * The memory tools (set_memory_consent, remember_preference, get_preferences,
 * forget_preferences) are NOT built-ins, they ship as a drop-in feature pack that
 * self-registers via Fahad_AI_Tool_Registry::register_pack() at file load and declare
 * `'personal' => true`. Every test registers the pack's REAL provider, then dispatches
 * through Fahad_AI_Tool_Registry::instance()->dispatch(), so the production
 * registration + merge + dispatch path (INCLUDING the central login gate for
 * `personal` tools) is what is under test.
 *
 * PRIVACY/CONSENT IS THE POINT. The highest-severity tests are first-class:
 *   - NO CONSENT → NO STORAGE: remember_preference WITHOUT opt-in stores nothing and
 *     returns a needs-consent message, update_user_meta for the preferences key is
 *     NEVER called.
 *   - GUEST-BLOCK: a guest dispatching any memory tool is stopped centrally by the
 *     registry's login gate BEFORE the callback runs, user meta is NEVER touched.
 *   - CURRENT-USER-ONLY: every read/write targets the current user id; a user id
 *     supplied in the model input is ignored.
 *   - VIEW + ERASURE: with consent a preference is stored, get_preferences returns it,
 *     forget_preferences clears it.
 */

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;

class MemoryToolsTest extends TestCase {

    use MockeryPHPUnitIntegration;

    /**
     * Snapshot of the registry's static pack providers, restored in tearDown so a
     * test here neither inherits another suite's packs nor leaks the memory pack we
     * register. (Pack providers are static so they survive a singleton instance
     * reset, see Fahad_AI_Tool_Registry::register_pack.)
     *
     * @var array<int, callable>
     */
    private array $pack_snapshot = [];

    /**
     * In-memory stand-in for WordPress user meta, keyed by "{$user_id}:{$key}".
     * get_user_meta / update_user_meta / delete_user_meta are stubbed to read/write
     * this array so a test can assert exactly what the tools persisted, and, for the
     * no-consent test, that nothing was persisted at all.
     *
     * @var array<string, mixed>
     */
    private array $meta = [];

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();

        $this->pack_snapshot = (array) ( new ReflectionProperty( Fahad_AI_Tool_Registry::class, 'pack_providers' ) )->getValue();

        $this->meta = [];

        Functions\stubs( [
            'sanitize_text_field' => fn( $s ) => is_string( $s ) ? trim( $s ) : $s,
            'absint'              => fn( $n ) => abs( (int) $n ),
            // Registry get_tools() reads the merchant tool-gating option (issue #56);
            // default (no disabled tools) so dispatch()/specs() are unaffected.
            'get_option'          => fn( $key, $default = '' ) => $default,
        ] );

        // User-meta seam backed by the in-memory $this->meta map.
        Functions\when( 'get_user_meta' )->alias(
            fn( $user_id, $key, $single = false ) => $this->meta[ "{$user_id}:{$key}" ] ?? ( $single ? '' : [] )
        );
        Functions\when( 'update_user_meta' )->alias(
            function ( $user_id, $key, $value ) {
                $this->meta[ "{$user_id}:{$key}" ] = $value;
                return true;
            }
        );
        Functions\when( 'delete_user_meta' )->alias(
            function ( $user_id, $key ) {
                unset( $this->meta[ "{$user_id}:{$key}" ] );
                return true;
            }
        );

        // Default to a logged-in customer (id 5). Guest cases override this.
        Functions\when( 'is_user_logged_in' )->justReturn( true );
        Functions\when( 'get_current_user_id' )->justReturn( 5 );
    }

    protected function tearDown(): void {
        ( new ReflectionProperty( Fahad_AI_Tool_Registry::class, 'pack_providers' ) )->setValue( null, $this->pack_snapshot );
        Monkey\tearDown();
        parent::tearDown();
    }

    /**
     * Fresh registry whose built tool list includes the memory tools.
     *
     * Resets the Tools + registry singletons, then registers the memory pack's REAL
     * provider via register_pack(), exactly what the pack's file-scope
     * self-registration does in production.
     */
    private function registry(): Fahad_AI_Tool_Registry {
        ( new ReflectionProperty( Fahad_AI_Tools::class, 'instance' ) )->setValue( null, null );
        ( new ReflectionProperty( Fahad_AI_Tool_Registry::class, 'instance' ) )->setValue( null, null );

        Fahad_AI_Tool_Registry::reset_packs();
        Fahad_AI_Tool_Registry::register_pack( [ 'Fahad_AI_Memory_Tools', 'register' ] );

        return Fahad_AI_Tool_Registry::instance();
    }

    /** Convenience: opt the current user (id 5) in directly via the meta map. */
    private function optInUser5(): void {
        $this->meta['5:fahad_ai_memory_optin'] = '1';
    }

    // ── registration ──────────────────────────────────────────────────────────

    public function test_memory_tools_are_registered_via_register_pack(): void {
        $names = array_column( $this->registry()->specs(), 'name' );

        $this->assertContains( 'set_memory_consent', $names );
        $this->assertContains( 'remember_preference', $names );
        $this->assertContains( 'get_preferences', $names );
        $this->assertContains( 'forget_preferences', $names );
        // Additive: the six built-ins remain.
        $this->assertContains( 'search_products', $names );
    }

    public function test_memory_tool_specs_never_leak_a_callback_or_personal_flag(): void {
        $specs = array_column( $this->registry()->specs(), null, 'name' );

        foreach ( [ 'set_memory_consent', 'remember_preference', 'get_preferences', 'forget_preferences' ] as $name ) {
            $this->assertArrayHasKey( $name, $specs );
            $this->assertArrayNotHasKey( 'callback', $specs[ $name ] );
            // The `personal` flag is an internal authorization detail; never advertised.
            $this->assertArrayNotHasKey( 'personal', $specs[ $name ] );
            $this->assertArrayHasKey( 'description', $specs[ $name ] );
            $this->assertSame( 'object', $specs[ $name ]['parameters']['type'] );
            $this->assertArrayHasKey( 'properties', $specs[ $name ]['parameters'] );
        }
    }

    // ── set_memory_consent (explicit opt-in / opt-out) ──────────────────────────

    public function test_set_memory_consent_opts_the_current_user_in(): void {
        $result = $this->registry()->dispatch( 'set_memory_consent', [ 'enabled' => true ] );

        $this->assertArrayNotHasKey( 'error', $result );
        $this->assertTrue( $result['consent'] );
        // Persisted under the CURRENT user id (5), not anywhere else.
        $this->assertSame( '1', $this->meta['5:fahad_ai_memory_optin'] ?? null );
    }

    public function test_set_memory_consent_opts_the_current_user_out(): void {
        $this->optInUser5();

        $result = $this->registry()->dispatch( 'set_memory_consent', [ 'enabled' => false ] );

        $this->assertArrayNotHasKey( 'error', $result );
        $this->assertFalse( $result['consent'] );
        // Opting out flips the stored flag off (falsey), so nothing is injected later.
        $this->assertEmpty( $this->meta['5:fahad_ai_memory_optin'] ?? '' );
    }

    public function test_set_memory_consent_uses_current_user_not_model_supplied_id(): void {
        // A confused/malicious model passes someone else's user_id. It MUST be ignored , 
        // consent is written only for the authenticated current user (5).
        $this->registry()->dispatch( 'set_memory_consent', [ 'enabled' => true, 'user_id' => 9999 ] );

        $this->assertArrayHasKey( '5:fahad_ai_memory_optin', $this->meta );
        $this->assertArrayNotHasKey( '9999:fahad_ai_memory_optin', $this->meta );
    }

    // ── remember_preference, THE consent gate (no consent → no storage) ────────

    /**
     * THE headline privacy test: NO CONSENT → NO STORAGE.
     *
     * The current user has NOT opted in. remember_preference must refuse, return a
     * clear needs-consent message, and write NOTHING, update_user_meta for the
     * preferences key is never called. Without the consent gate this fails: the tool
     * would persist a preference the user never agreed to store.
     */
    public function test_remember_preference_without_consent_stores_nothing(): void {
        // No opt-in seeded → not consented.
        Functions\expect( 'update_user_meta' )
            ->with( Mockery::any(), 'fahad_ai_preferences', Mockery::any() )
            ->never();

        $result = $this->registry()->dispatch( 'remember_preference', [ 'key' => 'favorite_color', 'value' => 'blue' ] );

        // A clear "needs consent" response, not a stored confirmation.
        $this->assertArrayHasKey( 'needs_consent', $result );
        $this->assertTrue( $result['needs_consent'] );
        $this->assertArrayHasKey( 'message', $result );
        $this->assertArrayNotHasKey( 'stored', $result );
        // Nothing about the preferences map was persisted.
        $this->assertArrayNotHasKey( '5:fahad_ai_preferences', $this->meta );
    }

    public function test_remember_preference_with_consent_stores_the_preference(): void {
        $this->optInUser5();

        $result = $this->registry()->dispatch( 'remember_preference', [ 'key' => 'favorite_color', 'value' => 'blue' ] );

        $this->assertArrayNotHasKey( 'error', $result );
        $this->assertArrayNotHasKey( 'needs_consent', $result );
        $this->assertTrue( $result['stored'] );
        // Persisted under the current user's preferences map.
        $this->assertSame( [ 'favorite_color' => 'blue' ], $this->meta['5:fahad_ai_preferences'] ?? null );
    }

    public function test_remember_preference_with_consent_merges_into_existing_prefs(): void {
        $this->optInUser5();
        $this->meta['5:fahad_ai_preferences'] = [ 'size' => 'large' ];

        $this->registry()->dispatch( 'remember_preference', [ 'key' => 'favorite_color', 'value' => 'blue' ] );

        $this->assertSame(
            [ 'size' => 'large', 'favorite_color' => 'blue' ],
            $this->meta['5:fahad_ai_preferences']
        );
    }

    public function test_remember_preference_requires_a_key_and_value(): void {
        $this->optInUser5();

        $missing_value = $this->registry()->dispatch( 'remember_preference', [ 'key' => 'favorite_color' ] );
        $this->assertArrayHasKey( 'error', $missing_value );
        $this->assertArrayNotHasKey( '5:fahad_ai_preferences', $this->meta );

        $missing_key = $this->registry()->dispatch( 'remember_preference', [ 'value' => 'blue' ] );
        $this->assertArrayHasKey( 'error', $missing_key );
        $this->assertArrayNotHasKey( '5:fahad_ai_preferences', $this->meta );
    }

    public function test_remember_preference_uses_current_user_not_model_supplied_id(): void {
        $this->optInUser5();

        $this->registry()->dispatch( 'remember_preference', [
            'key'     => 'favorite_color',
            'value'   => 'blue',
            'user_id' => 9999, // must be ignored
        ] );

        $this->assertArrayHasKey( '5:fahad_ai_preferences', $this->meta );
        $this->assertArrayNotHasKey( '9999:fahad_ai_preferences', $this->meta );
    }

    // ── storage hygiene (bound the map; cap key/value length) ───────────────────

    public function test_remember_preference_caps_the_number_of_stored_keys(): void {
        $this->optInUser5();

        // Fill the map to the cap with distinct keys.
        $full = [];
        for ( $i = 0; $i < Fahad_AI_Memory_Tools::MAX_PREFERENCES; $i++ ) {
            $full[ "k{$i}" ] = "v{$i}";
        }
        $this->meta['5:fahad_ai_preferences'] = $full;

        // One more NEW key must be refused (the map is full), never grow unbounded.
        $result = $this->registry()->dispatch( 'remember_preference', [ 'key' => 'overflow', 'value' => 'x' ] );

        $this->assertArrayHasKey( 'error', $result );
        $this->assertArrayNotHasKey( 'overflow', $this->meta['5:fahad_ai_preferences'] );
        $this->assertCount( Fahad_AI_Memory_Tools::MAX_PREFERENCES, $this->meta['5:fahad_ai_preferences'] );
    }

    public function test_remember_preference_updating_existing_key_at_cap_is_allowed(): void {
        $this->optInUser5();

        $full = [];
        for ( $i = 0; $i < Fahad_AI_Memory_Tools::MAX_PREFERENCES; $i++ ) {
            $full[ "k{$i}" ] = "v{$i}";
        }
        $this->meta['5:fahad_ai_preferences'] = $full;

        // Overwriting an EXISTING key does not grow the map, so it is allowed at cap.
        $result = $this->registry()->dispatch( 'remember_preference', [ 'key' => 'k0', 'value' => 'updated' ] );

        $this->assertTrue( $result['stored'] ?? false );
        $this->assertSame( 'updated', $this->meta['5:fahad_ai_preferences']['k0'] );
        $this->assertCount( Fahad_AI_Memory_Tools::MAX_PREFERENCES, $this->meta['5:fahad_ai_preferences'] );
    }

    public function test_remember_preference_truncates_overlong_value(): void {
        $this->optInUser5();

        $long = str_repeat( 'a', Fahad_AI_Memory_Tools::MAX_VALUE_LENGTH + 50 );
        $this->registry()->dispatch( 'remember_preference', [ 'key' => 'note', 'value' => $long ] );

        $stored = $this->meta['5:fahad_ai_preferences']['note'];
        $this->assertSame( Fahad_AI_Memory_Tools::MAX_VALUE_LENGTH, strlen( $stored ) );
    }

    public function test_remember_preference_truncates_overlong_key(): void {
        $this->optInUser5();

        $long_key = str_repeat( 'k', Fahad_AI_Memory_Tools::MAX_KEY_LENGTH + 50 );
        $this->registry()->dispatch( 'remember_preference', [ 'key' => $long_key, 'value' => 'v' ] );

        $keys = array_keys( $this->meta['5:fahad_ai_preferences'] );
        $this->assertCount( 1, $keys );
        $this->assertSame( Fahad_AI_Memory_Tools::MAX_KEY_LENGTH, strlen( $keys[0] ) );
    }

    // ── get_preferences (view what is remembered + consent state) ───────────────

    public function test_get_preferences_returns_stored_prefs_and_consent_state(): void {
        $this->optInUser5();
        $this->meta['5:fahad_ai_preferences'] = [ 'favorite_color' => 'blue', 'size' => 'large' ];

        $result = $this->registry()->dispatch( 'get_preferences', [] );

        $this->assertTrue( $result['consent'] );
        $this->assertSame( [ 'favorite_color' => 'blue', 'size' => 'large' ], $result['preferences'] );
    }

    public function test_get_preferences_reports_no_consent_and_empty_when_not_opted_in(): void {
        // Not opted in, nothing stored.
        $result = $this->registry()->dispatch( 'get_preferences', [] );

        $this->assertFalse( $result['consent'] );
        $this->assertSame( [], $result['preferences'] );
    }

    public function test_get_preferences_uses_current_user_not_model_supplied_id(): void {
        $this->optInUser5();
        $this->meta['5:fahad_ai_preferences']    = [ 'mine' => 'yes' ];
        $this->meta['9999:fahad_ai_preferences'] = [ 'theirs' => 'secret' ];

        $result = $this->registry()->dispatch( 'get_preferences', [ 'user_id' => 9999 ] );

        // Only the current user's (5) prefs come back, never user 9999's.
        $this->assertSame( [ 'mine' => 'yes' ], $result['preferences'] );
        $this->assertStringNotContainsString( 'secret', json_encode( $result ) );
    }

    // ── forget_preferences (easy erasure) ───────────────────────────────────────

    public function test_forget_preferences_clears_the_stored_prefs(): void {
        $this->optInUser5();
        $this->meta['5:fahad_ai_preferences'] = [ 'favorite_color' => 'blue' ];

        $result = $this->registry()->dispatch( 'forget_preferences', [] );

        $this->assertArrayNotHasKey( 'error', $result );
        $this->assertTrue( $result['cleared'] );
        // The preferences row is gone.
        $this->assertArrayNotHasKey( '5:fahad_ai_preferences', $this->meta );
    }

    public function test_forget_preferences_can_also_clear_consent(): void {
        $this->optInUser5();
        $this->meta['5:fahad_ai_preferences'] = [ 'favorite_color' => 'blue' ];

        $result = $this->registry()->dispatch( 'forget_preferences', [ 'clear_consent' => true ] );

        $this->assertTrue( $result['cleared'] );
        // Both the prefs AND the consent flag are gone.
        $this->assertArrayNotHasKey( '5:fahad_ai_preferences', $this->meta );
        $this->assertArrayNotHasKey( '5:fahad_ai_memory_optin', $this->meta );
    }

    public function test_forget_preferences_keeps_consent_by_default(): void {
        $this->optInUser5();
        $this->meta['5:fahad_ai_preferences'] = [ 'favorite_color' => 'blue' ];

        $this->registry()->dispatch( 'forget_preferences', [] );

        // Default erasure clears prefs but LEAVES the opt-in in place (the user is still
        // willing to be remembered; they just cleared what was stored).
        $this->assertArrayNotHasKey( '5:fahad_ai_preferences', $this->meta );
        $this->assertSame( '1', $this->meta['5:fahad_ai_memory_optin'] ?? null );
    }

    public function test_forget_preferences_uses_current_user_not_model_supplied_id(): void {
        $this->optInUser5();
        $this->meta['5:fahad_ai_preferences']    = [ 'mine' => 'yes' ];
        $this->meta['9999:fahad_ai_preferences'] = [ 'theirs' => 'secret' ];

        $this->registry()->dispatch( 'forget_preferences', [ 'user_id' => 9999 ] );

        // Only the current user's (5) prefs were cleared; user 9999's are untouched.
        $this->assertArrayNotHasKey( '5:fahad_ai_preferences', $this->meta );
        $this->assertArrayHasKey( '9999:fahad_ai_preferences', $this->meta );
    }

    // ── GUEST-BLOCK (central login gate, user meta never touched) ───────────────

    /**
     * A guest dispatching ANY memory tool must be stopped CENTRALLY by the registry's
     * login gate, before the tool callback runs. We assert the standard login-required
     * error AND, critically, that user meta is NEVER read or written, proving the
     * callback was never reached. Mirrors OrderToolsTest / WalletToolsTest guest-block.
     *
     * @dataProvider memoryToolProvider
     */
    public function test_guest_is_blocked_before_a_memory_tool_callback_runs( string $tool, array $input ): void {
        Functions\when( 'is_user_logged_in' )->justReturn( false );
        Functions\when( 'get_current_user_id' )->justReturn( 0 );

        // If the callback were reached it would touch user meta, these must NOT be hit.
        Functions\expect( 'get_user_meta' )->never();
        Functions\expect( 'update_user_meta' )->never();
        Functions\expect( 'delete_user_meta' )->never();

        $result = $this->registry()->dispatch( $tool, $input );

        $this->assertArrayHasKey( 'requires_login', $result );
        $this->assertTrue( $result['requires_login'] );
        $this->assertArrayHasKey( 'error', $result );
        $this->assertStringNotContainsString( 'Unknown tool', $result['error'] );
        // No memory/preference data of any kind in a guest result.
        $this->assertArrayNotHasKey( 'preferences', $result );
        $this->assertArrayNotHasKey( 'stored', $result );
    }

    /** Every memory tool must be guest-gated. */
    public static function memoryToolProvider(): array {
        return [
            'set_memory_consent' => [ 'set_memory_consent', [ 'enabled' => true ] ],
            'remember_preference' => [ 'remember_preference', [ 'key' => 'k', 'value' => 'v' ] ],
            'get_preferences'    => [ 'get_preferences', [] ],
            'forget_preferences' => [ 'forget_preferences', [] ],
        ];
    }

    // ── CONTEXT INJECTION via the fahad_ai_system_prompt filter ─────────────────
    // The pack hooks the `fahad_ai_system_prompt` filter (issue #20) to APPEND a
    // compact preferences block, but ONLY for a logged-in, opted-in user with stored
    // prefs. For a guest, an opted-out user, or empty prefs it must return the prompt
    // UNCHANGED. We exercise the pack's filter callback directly (the same callable it
    // registers via add_filter at file scope) so the injection contract is proven
    // without touching the agent-loop bodies.

    private const BASE_PROMPT = 'You are a helpful shopping assistant.';

    /** Invoke the pack's registered filter callback on a base prompt. */
    private function inject( string $prompt ): string {
        return Fahad_AI_Memory_Tools::inject_preferences( $prompt );
    }

    public function test_injection_appends_compact_prefs_for_logged_in_opted_in_user(): void {
        $this->optInUser5();
        $this->meta['5:fahad_ai_preferences'] = [ 'favorite_color' => 'blue', 'size' => 'large' ];

        $out = $this->inject( self::BASE_PROMPT );

        // The base prompt is preserved and the block is APPENDED (not a replacement).
        $this->assertStringStartsWith( self::BASE_PROMPT, $out );
        $this->assertNotSame( self::BASE_PROMPT, $out );
        // The actual stored preferences appear in the injected block.
        $this->assertStringContainsString( 'favorite_color', $out );
        $this->assertStringContainsString( 'blue', $out );
        $this->assertStringContainsString( 'size', $out );
        $this->assertStringContainsString( 'large', $out );
    }

    public function test_injection_appends_nothing_for_a_guest(): void {
        Functions\when( 'is_user_logged_in' )->justReturn( false );
        Functions\when( 'get_current_user_id' )->justReturn( 0 );
        // Even if some other user has prefs, a guest's prompt is never decorated.
        $this->meta['5:fahad_ai_memory_optin']  = '1';
        $this->meta['5:fahad_ai_preferences']   = [ 'favorite_color' => 'blue' ];

        $this->assertSame( self::BASE_PROMPT, $this->inject( self::BASE_PROMPT ) );
    }

    public function test_injection_appends_nothing_for_an_opted_out_user(): void {
        // Logged in, has prefs, but NOT opted in → nothing injected (opt-in only).
        $this->meta['5:fahad_ai_preferences'] = [ 'favorite_color' => 'blue' ];

        $this->assertSame( self::BASE_PROMPT, $this->inject( self::BASE_PROMPT ) );
    }

    public function test_injection_appends_nothing_when_prefs_are_empty(): void {
        // Logged in and opted in, but nothing stored yet → nothing to inject.
        $this->optInUser5();

        $this->assertSame( self::BASE_PROMPT, $this->inject( self::BASE_PROMPT ) );
    }

    public function test_injection_block_is_bounded_by_the_preference_cap(): void {
        // Storage hygiene also bounds the INJECTED context: at most MAX_PREFERENCES
        // lines, each value capped, so a bloated map cannot blow up the prompt.
        $this->optInUser5();
        $prefs = [];
        for ( $i = 0; $i < Fahad_AI_Memory_Tools::MAX_PREFERENCES + 25; $i++ ) {
            $prefs[ "k{$i}" ] = "v{$i}";
        }
        // (write directly; remember_preference would itself cap, this models a raw map)
        $this->meta['5:fahad_ai_preferences'] = $prefs;

        $out  = $this->inject( self::BASE_PROMPT );
        $body = substr( $out, strlen( self::BASE_PROMPT ) );

        // No more than MAX_PREFERENCES "key: value" pref lines are rendered.
        $pref_lines = preg_match_all( '/^- /m', $body );
        $this->assertLessThanOrEqual( Fahad_AI_Memory_Tools::MAX_PREFERENCES, $pref_lines );
    }
}
