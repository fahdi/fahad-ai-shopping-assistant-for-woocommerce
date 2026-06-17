<?php
/**
 * Unit tests for the memory pack's GDPR privacy hooks (issue #59).
 *
 * The opt-in cross-session memory (issue #20) stores two per-user meta rows — the
 * consent flag (fahad_ai_memory_optin) and the bounded preferences map
 * (fahad_ai_preferences). This suite proves those rows participate in WordPress'
 * core privacy tools:
 *
 *   - EXPORT: wp_privacy_personal_data_exporters → Fahad_AI_Memory_Tools::gdpr_export()
 *     returns the user's stored preferences AND consent state, resolved from the
 *     subject's email (the shape WordPress' "Export personal data" tool consumes).
 *   - ERASE: wp_privacy_personal_data_erasers → Fahad_AI_Memory_Tools::gdpr_erase()
 *     DELETES every memory row (consent + preferences) for that user, leaving NO
 *     residue, and reports the WordPress eraser response shape.
 *   - CYCLE: a consent → store → erase round-trip leaves the meta map empty (the
 *     "no orphan rows/meta" acceptance criterion).
 *
 * Conventions mirror MemoryToolsTest: WP user-meta functions are mocked via
 * Brain\Monkey against an in-memory $this->meta map so a test asserts exactly what
 * remains after each operation. get_user_by('email', …) is stubbed to resolve the
 * subject email to a WP_User-like object carrying ->ID. NEVER ReflectionMethod::
 * setAccessible (host runs PHP 8.5); these callbacks are public static, called
 * directly (like inject_preferences).
 *
 * PRIVACY IS THE POINT. The highest-severity assertions are first-class:
 *   - COMPLETE PURGE: after erase, neither the consent flag nor the preferences row
 *     exists for the subject — verified against the raw meta map, not a return value.
 *   - NO CROSS-USER LEAK: export/erase only ever touch the meta of the user the email
 *     resolves to; an unknown email is a safe no-op that touches nothing.
 */

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;

class MemoryPrivacyTest extends TestCase {

    use MockeryPHPUnitIntegration;

    /** Consent flag user-meta key (mirrors Fahad_AI_Memory_Tools::OPTIN_META). */
    private const OPTIN_META = 'fahad_ai_memory_optin';

    /** Preferences map user-meta key (mirrors Fahad_AI_Memory_Tools::PREFS_META). */
    private const PREFS_META = 'fahad_ai_preferences';

    /**
     * In-memory stand-in for WordPress user meta, keyed by "{$user_id}:{$key}".
     * get_user_meta / update_user_meta / delete_user_meta read/write this map so a
     * test can assert exactly what the privacy callbacks left behind (or removed).
     *
     * @var array<string, mixed>
     */
    private array $meta = [];

    /**
     * email => user id, consulted by the get_user_by('email', …) stub. An email
     * absent from this map resolves to false (no such user) — the no-op path.
     *
     * @var array<string, int>
     */
    private array $usersByEmail = [];

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();

        $this->meta         = [];
        $this->usersByEmail = [];

        Functions\stubs( [
            'sanitize_text_field' => fn( $s ) => is_string( $s ) ? trim( $s ) : $s,
            'sanitize_email'      => fn( $s ) => is_string( $s ) ? trim( $s ) : '',
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

        // Resolve a privacy request's email to a WP_User-like object (->ID), or false.
        Functions\when( 'get_user_by' )->alias(
            function ( $field, $value ) {
                if ( 'email' !== $field ) {
                    return false;
                }
                $email = strtolower( trim( (string) $value ) );
                if ( ! isset( $this->usersByEmail[ $email ] ) ) {
                    return false;
                }
                return (object) [ 'ID' => $this->usersByEmail[ $email ] ];
            }
        );
    }

    protected function tearDown(): void {
        Monkey\tearDown();
        parent::tearDown();
    }

    /** Seed a known user (id) for an email so get_user_by resolves it. */
    private function seedUser( string $email, int $id ): void {
        $this->usersByEmail[ strtolower( $email ) ] = $id;
    }

    // ── filter registration ──────────────────────────────────────────────────────

    public function test_register_exporter_adds_a_keyed_exporter_with_a_callback(): void {
        $exporters = Fahad_AI_Memory_Tools::register_exporter( [] );

        $this->assertArrayHasKey( 'fahad-ai-memory', $exporters );
        $this->assertArrayHasKey( 'exporter_friendly_name', $exporters['fahad-ai-memory'] );
        $this->assertIsCallable( $exporters['fahad-ai-memory']['callback'] );
    }

    public function test_register_eraser_adds_a_keyed_eraser_with_a_callback(): void {
        $erasers = Fahad_AI_Memory_Tools::register_eraser( [] );

        $this->assertArrayHasKey( 'fahad-ai-memory', $erasers );
        $this->assertArrayHasKey( 'eraser_friendly_name', $erasers['fahad-ai-memory'] );
        $this->assertIsCallable( $erasers['fahad-ai-memory']['callback'] );
    }

    public function test_register_filters_preserve_existing_entries(): void {
        $exporters = Fahad_AI_Memory_Tools::register_exporter( [ 'other' => [ 'x' => 1 ] ] );
        $erasers   = Fahad_AI_Memory_Tools::register_eraser( [ 'other' => [ 'y' => 2 ] ] );

        $this->assertArrayHasKey( 'other', $exporters );
        $this->assertArrayHasKey( 'fahad-ai-memory', $exporters );
        $this->assertArrayHasKey( 'other', $erasers );
        $this->assertArrayHasKey( 'fahad-ai-memory', $erasers );
    }

    // ── EXPORT ────────────────────────────────────────────────────────────────────

    public function test_exporter_returns_the_users_stored_prefs_and_consent(): void {
        $this->seedUser( 'jane@example.com', 7 );
        $this->meta['7:' . self::OPTIN_META] = '1';
        $this->meta['7:' . self::PREFS_META] = [ 'favorite_color' => 'blue', 'size' => 'large' ];

        $result = Fahad_AI_Memory_Tools::gdpr_export( 'jane@example.com' );

        $this->assertTrue( $result['done'] );
        $this->assertArrayHasKey( 'data', $result );
        $this->assertCount( 1, $result['data'] );

        $group = $result['data'][0];
        $this->assertSame( 'fahad-ai-memory', $group['group_id'] );
        $this->assertNotEmpty( $group['item_id'] ); // a stable per-user item id is present

        // Flatten the exported name => value pairs for assertion.
        $pairs = [];
        foreach ( $group['data'] as $row ) {
            $pairs[ $row['name'] ] = $row['value'];
        }

        // Every stored preference is present, plus the consent state.
        $this->assertSame( 'blue', $pairs['favorite_color'] );
        $this->assertSame( 'large', $pairs['size'] );
        $this->assertArrayHasKey( 'Memory consent', $pairs );
        $this->assertSame( 'Yes', $pairs['Memory consent'] );
    }

    public function test_exporter_reports_consent_no_for_an_explicit_opt_out(): void {
        $this->seedUser( 'jane@example.com', 7 );
        // An explicit opt-out is still a recorded choice (flag present, falsey) → exportable.
        $this->meta['7:' . self::OPTIN_META] = '0';

        $result = Fahad_AI_Memory_Tools::gdpr_export( 'jane@example.com' );

        $this->assertTrue( $result['done'] );
        // The consent state is reported as a record that this user opted out.
        $group = $result['data'][0];
        $pairs = [];
        foreach ( $group['data'] as $row ) {
            $pairs[ $row['name'] ] = $row['value'];
        }
        $this->assertSame( 'No', $pairs['Memory consent'] );
    }

    public function test_exporter_for_a_user_with_no_memory_returns_empty_done(): void {
        $this->seedUser( 'jane@example.com', 7 );
        // User exists but never touched the feature (no consent flag, no prefs) → nothing
        // to export. This is also the post-erase state (the cycle test relies on it).
        $result = Fahad_AI_Memory_Tools::gdpr_export( 'jane@example.com' );

        $this->assertSame( [], $result['data'] );
        $this->assertTrue( $result['done'] );
    }

    public function test_exporter_for_unknown_email_returns_empty_done(): void {
        // No user seeded → get_user_by returns false.
        $result = Fahad_AI_Memory_Tools::gdpr_export( 'nobody@example.com' );

        $this->assertSame( [], $result['data'] );
        $this->assertTrue( $result['done'] );
    }

    public function test_exporter_only_reads_the_resolved_users_meta(): void {
        // Two users with stored prefs; exporting Jane must not surface Bob's data.
        $this->seedUser( 'jane@example.com', 7 );
        $this->meta['7:' . self::OPTIN_META] = '1';
        $this->meta['7:' . self::PREFS_META] = [ 'mine' => 'jane-value' ];
        $this->meta['8:' . self::PREFS_META] = [ 'theirs' => 'bob-secret' ];

        $result = Fahad_AI_Memory_Tools::gdpr_export( 'jane@example.com' );

        // Only Jane's (id 7) data is surfaced; Bob's (id 8) row is never read.
        $this->assertNotEmpty( $result['data'] );
        $pairs = [];
        foreach ( $result['data'][0]['data'] as $row ) {
            $pairs[ $row['name'] ] = $row['value'];
        }
        $this->assertSame( 'jane-value', $pairs['mine'] );
        $this->assertArrayNotHasKey( 'theirs', $pairs );
    }

    // ── ERASE (complete purge — the headline #59 requirement) ──────────────────────

    public function test_eraser_removes_both_prefs_and_consent_leaving_no_residue(): void {
        $this->seedUser( 'jane@example.com', 7 );
        $this->meta['7:' . self::OPTIN_META] = '1';
        $this->meta['7:' . self::PREFS_META] = [ 'favorite_color' => 'blue' ];

        $result = Fahad_AI_Memory_Tools::gdpr_erase( 'jane@example.com' );

        // WordPress eraser response shape.
        $this->assertTrue( $result['items_removed'] );
        $this->assertFalse( $result['items_retained'] );
        $this->assertTrue( $result['done'] );
        $this->assertSame( [], $result['messages'] );

        // CRITICAL: nothing remains for this user — no orphan meta of any kind.
        $this->assertArrayNotHasKey( '7:' . self::PREFS_META, $this->meta );
        $this->assertArrayNotHasKey( '7:' . self::OPTIN_META, $this->meta );
    }

    public function test_eraser_reports_nothing_removed_when_user_had_no_memory(): void {
        $this->seedUser( 'jane@example.com', 7 );
        // User exists but never used the memory feature.
        $result = Fahad_AI_Memory_Tools::gdpr_erase( 'jane@example.com' );

        $this->assertFalse( $result['items_removed'] );
        $this->assertFalse( $result['items_retained'] );
        $this->assertTrue( $result['done'] );
    }

    public function test_eraser_for_unknown_email_is_a_safe_noop(): void {
        // No user seeded; also seed an unrelated user's data that MUST survive.
        $this->meta['8:' . self::PREFS_META] = [ 'theirs' => 'bob-secret' ];

        $result = Fahad_AI_Memory_Tools::gdpr_erase( 'nobody@example.com' );

        $this->assertFalse( $result['items_removed'] );
        $this->assertTrue( $result['done'] );
        // Unrelated user untouched.
        $this->assertArrayHasKey( '8:' . self::PREFS_META, $this->meta );
    }

    public function test_eraser_only_purges_the_resolved_users_meta(): void {
        $this->seedUser( 'jane@example.com', 7 );
        $this->meta['7:' . self::OPTIN_META] = '1';
        $this->meta['7:' . self::PREFS_META] = [ 'mine' => 'yes' ];
        $this->meta['8:' . self::OPTIN_META] = '1';
        $this->meta['8:' . self::PREFS_META] = [ 'theirs' => 'secret' ];

        Fahad_AI_Memory_Tools::gdpr_erase( 'jane@example.com' );

        // Jane (7) is purged; Bob (8) is fully intact.
        $this->assertArrayNotHasKey( '7:' . self::OPTIN_META, $this->meta );
        $this->assertArrayNotHasKey( '7:' . self::PREFS_META, $this->meta );
        $this->assertArrayHasKey( '8:' . self::OPTIN_META, $this->meta );
        $this->assertArrayHasKey( '8:' . self::PREFS_META, $this->meta );
    }

    // ── CYCLE: consent → store → erase leaves no residue ──────────────────────────

    /**
     * The eval-style acceptance check from #59, expressed deterministically: drive a
     * full lifecycle through the SAME seams production uses (consent + a stored pref),
     * then run the GDPR eraser and assert the user's meta map is completely empty —
     * no orphan rows, nothing for the privacy tool to leave behind.
     */
    public function test_consent_store_erase_cycle_leaves_no_residue(): void {
        $this->seedUser( 'jane@example.com', 7 );

        // 1. Consent (opt-in flag) + 2. store a couple of preferences.
        $this->meta['7:' . self::OPTIN_META] = '1';
        $this->meta['7:' . self::PREFS_META] = [ 'favorite_color' => 'blue', 'preferred_brand' => 'acme' ];

        // Sanity: the rows exist before erasure.
        $this->assertNotEmpty( $this->meta );

        // 3. GDPR erase.
        $result = Fahad_AI_Memory_Tools::gdpr_erase( 'jane@example.com' );
        $this->assertTrue( $result['items_removed'] );

        // No residue whatsoever for the subject.
        $remaining = array_filter(
            array_keys( $this->meta ),
            fn( $k ) => str_starts_with( $k, '7:' )
        );
        $this->assertSame( [], array_values( $remaining ), 'GDPR erase must leave no memory meta for the subject.' );

        // And a subsequent export confirms nothing is left to export.
        $export = Fahad_AI_Memory_Tools::gdpr_export( 'jane@example.com' );
        $this->assertSame( [], $export['data'] );
    }
}
