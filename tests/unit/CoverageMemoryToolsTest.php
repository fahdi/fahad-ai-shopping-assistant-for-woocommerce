<?php
/**
 * Coverage-completion tests for Fahad_AI_Memory_Tools.
 *
 * The behavioural suite (MemoryToolsTest) exercises the four model tools and the
 * context-injection filter. The GDPR privacy callbacks, the email-resolution
 * helper, and the defensive `$user_id <= 0` guards live in code paths that suite
 * never reaches. This suite closes those gaps, asserting REAL behaviour:
 *
 *   - register_exporter() / register_eraser(): keyed WP privacy registration that
 *     preserves any existing entries.
 *   - gdpr_export(): resolves the (verified) subject email to a user and returns
 *     ONLY that user's consent state + saved preferences; empty/unknown/post-erase
 *     users export nothing.
 *   - gdpr_erase(): purges BOTH meta rows for the resolved user only, reports the
 *     WP eraser shape, and is a safe no-op for an unknown / empty email.
 *   - user_id_for_email(): an email that sanitizes to '' short-circuits to 0
 *     (no get_user_by call) — the no-op guard the callbacks rely on.
 *   - has_consent() / read_prefs(): the defensive `$user_id <= 0` guards return a
 *     safe falsey/empty value WITHOUT reading user meta.
 *
 * Conventions mirror MemoryPrivacyTest / ApiHandlerTest: WP functions are stubbed
 * via Brain\Monkey; user meta is backed by an in-memory map so each assertion can
 * inspect exactly what was read/written; ReflectionMethod reaches the private
 * helpers directly.
 */

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;

class CoverageMemoryToolsTest extends TestCase {

	use MockeryPHPUnitIntegration;

	/** Consent flag user-meta key (mirrors Fahad_AI_Memory_Tools::OPTIN_META). */
	private const OPTIN_META = 'fahad_ai_memory_optin';

	/** Preferences map user-meta key (mirrors Fahad_AI_Memory_Tools::PREFS_META). */
	private const PREFS_META = 'fahad_ai_preferences';

	/** Stable privacy group / exporter / eraser key (mirrors PRIVACY_KEY). */
	private const PRIVACY_KEY = 'fahad-ai-memory';

	/**
	 * In-memory stand-in for WordPress user meta, keyed by "{$user_id}:{$key}".
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
			// Resolve to a real address; whitespace-only trims to '' (the empty guard).
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

		// Resolve a privacy request email to a WP_User-like object (->ID), or false.
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

	/** Invoke a private static method on the class under test. */
	private function callPrivate( string $method, array $args ) {
		// ReflectionMethod can invoke a private method directly in PHP 8.1+; no
		// setAccessible() is needed (and it is deprecated since 8.5).
		$m = new ReflectionMethod( Fahad_AI_Memory_Tools::class, $method );
		return $m->invokeArgs( null, $args );
	}

	// ── register_exporter / register_eraser ──────────────────────────────────────

	public function test_register_exporter_adds_a_keyed_exporter_with_a_callback(): void {
		$exporters = Fahad_AI_Memory_Tools::register_exporter( [] );

		$this->assertArrayHasKey( self::PRIVACY_KEY, $exporters );
		$this->assertArrayHasKey( 'exporter_friendly_name', $exporters[ self::PRIVACY_KEY ] );
		$this->assertIsCallable( $exporters[ self::PRIVACY_KEY ]['callback'] );
		// The callback resolves to gdpr_export on the class under test.
		$this->assertSame(
			[ 'Fahad_AI_Memory_Tools', 'gdpr_export' ],
			$exporters[ self::PRIVACY_KEY ]['callback']
		);
	}

	public function test_register_eraser_adds_a_keyed_eraser_with_a_callback(): void {
		$erasers = Fahad_AI_Memory_Tools::register_eraser( [] );

		$this->assertArrayHasKey( self::PRIVACY_KEY, $erasers );
		$this->assertArrayHasKey( 'eraser_friendly_name', $erasers[ self::PRIVACY_KEY ] );
		$this->assertIsCallable( $erasers[ self::PRIVACY_KEY ]['callback'] );
		$this->assertSame(
			[ 'Fahad_AI_Memory_Tools', 'gdpr_erase' ],
			$erasers[ self::PRIVACY_KEY ]['callback']
		);
	}

	public function test_register_filters_preserve_existing_entries(): void {
		$exporters = Fahad_AI_Memory_Tools::register_exporter( [ 'other' => [ 'x' => 1 ] ] );
		$erasers   = Fahad_AI_Memory_Tools::register_eraser( [ 'other' => [ 'y' => 2 ] ] );

		// Existing third-party registrations are kept, ours is appended.
		$this->assertArrayHasKey( 'other', $exporters );
		$this->assertArrayHasKey( self::PRIVACY_KEY, $exporters );
		$this->assertSame( [ 'x' => 1 ], $exporters['other'] );
		$this->assertArrayHasKey( 'other', $erasers );
		$this->assertArrayHasKey( self::PRIVACY_KEY, $erasers );
		$this->assertSame( [ 'y' => 2 ], $erasers['other'] );
	}

	// ── gdpr_export ──────────────────────────────────────────────────────────────

	public function test_gdpr_export_returns_consent_and_every_stored_preference(): void {
		$this->seedUser( 'jane@example.com', 7 );
		$this->meta['7:' . self::OPTIN_META] = '1';
		$this->meta['7:' . self::PREFS_META] = [ 'favorite_color' => 'blue', 'size' => 'large' ];

		$result = Fahad_AI_Memory_Tools::gdpr_export( 'jane@example.com' );

		$this->assertTrue( $result['done'] );
		$this->assertCount( 1, $result['data'] );

		$group = $result['data'][0];
		$this->assertSame( self::PRIVACY_KEY, $group['group_id'] );
		$this->assertSame( self::PRIVACY_KEY . '-7', $group['item_id'] );

		$pairs = [];
		foreach ( $group['data'] as $row ) {
			$pairs[ $row['name'] ] = $row['value'];
		}
		$this->assertSame( 'Yes', $pairs['Memory consent'] );
		$this->assertSame( 'blue', $pairs['favorite_color'] );
		$this->assertSame( 'large', $pairs['size'] );
	}

	public function test_gdpr_export_reports_consent_no_for_an_explicit_opt_out(): void {
		$this->seedUser( 'jane@example.com', 7 );
		// Flag present but falsey ('0') → a recorded opt-out, still exportable.
		$this->meta['7:' . self::OPTIN_META] = '0';

		$result = Fahad_AI_Memory_Tools::gdpr_export( 'jane@example.com' );

		$this->assertTrue( $result['done'] );
		$pairs = [];
		foreach ( $result['data'][0]['data'] as $row ) {
			$pairs[ $row['name'] ] = $row['value'];
		}
		$this->assertSame( 'No', $pairs['Memory consent'] );
	}

	public function test_gdpr_export_for_a_user_with_no_memory_returns_empty_done(): void {
		// User resolves but has neither a consent flag nor stored prefs → nothing to export.
		$this->seedUser( 'jane@example.com', 7 );

		$result = Fahad_AI_Memory_Tools::gdpr_export( 'jane@example.com' );

		$this->assertSame( [], $result['data'] );
		$this->assertTrue( $result['done'] );
	}

	public function test_gdpr_export_for_an_unknown_email_returns_empty_done(): void {
		// No user seeded → user_id_for_email resolves to 0 → empty export.
		$result = Fahad_AI_Memory_Tools::gdpr_export( 'nobody@example.com' );

		$this->assertSame( [], $result['data'] );
		$this->assertTrue( $result['done'] );
	}

	public function test_gdpr_export_only_reads_the_resolved_users_meta(): void {
		$this->seedUser( 'jane@example.com', 7 );
		$this->meta['7:' . self::OPTIN_META] = '1';
		$this->meta['7:' . self::PREFS_META] = [ 'mine' => 'jane-value' ];
		// A different user's data must never surface.
		$this->meta['8:' . self::PREFS_META] = [ 'theirs' => 'bob-secret' ];

		$result = Fahad_AI_Memory_Tools::gdpr_export( 'jane@example.com' );

		$pairs = [];
		foreach ( $result['data'][0]['data'] as $row ) {
			$pairs[ $row['name'] ] = $row['value'];
		}
		$this->assertSame( 'jane-value', $pairs['mine'] );
		$this->assertArrayNotHasKey( 'theirs', $pairs );
		$this->assertStringNotContainsString( 'bob-secret', json_encode( $result ) );
	}

	public function test_gdpr_export_for_empty_email_is_a_safe_noop(): void {
		// Whitespace email sanitizes to '' → user_id_for_email returns 0 WITHOUT a lookup.
		Functions\expect( 'get_user_by' )->never();

		$result = Fahad_AI_Memory_Tools::gdpr_export( '   ' );

		$this->assertSame( [], $result['data'] );
		$this->assertTrue( $result['done'] );
	}

	// ── gdpr_erase ───────────────────────────────────────────────────────────────

	public function test_gdpr_erase_removes_both_rows_and_reports_the_wp_shape(): void {
		$this->seedUser( 'jane@example.com', 7 );
		$this->meta['7:' . self::OPTIN_META] = '1';
		$this->meta['7:' . self::PREFS_META] = [ 'favorite_color' => 'blue' ];

		$result = Fahad_AI_Memory_Tools::gdpr_erase( 'jane@example.com' );

		$this->assertTrue( $result['items_removed'] );
		$this->assertFalse( $result['items_retained'] );
		$this->assertSame( [], $result['messages'] );
		$this->assertTrue( $result['done'] );

		// No residue for the subject.
		$this->assertArrayNotHasKey( '7:' . self::PREFS_META, $this->meta );
		$this->assertArrayNotHasKey( '7:' . self::OPTIN_META, $this->meta );
	}

	public function test_gdpr_erase_reports_nothing_removed_when_user_had_no_memory(): void {
		// User resolves but never used the feature → purge runs but nothing was there.
		$this->seedUser( 'jane@example.com', 7 );

		$result = Fahad_AI_Memory_Tools::gdpr_erase( 'jane@example.com' );

		$this->assertFalse( $result['items_removed'] );
		$this->assertFalse( $result['items_retained'] );
		$this->assertTrue( $result['done'] );
	}

	public function test_gdpr_erase_removes_when_only_consent_flag_was_present(): void {
		// Only the consent flag exists (no prefs) — still counts as removed.
		$this->seedUser( 'jane@example.com', 7 );
		$this->meta['7:' . self::OPTIN_META] = '0';

		$result = Fahad_AI_Memory_Tools::gdpr_erase( 'jane@example.com' );

		$this->assertTrue( $result['items_removed'] );
		$this->assertArrayNotHasKey( '7:' . self::OPTIN_META, $this->meta );
	}

	public function test_gdpr_erase_for_an_unknown_email_is_a_safe_noop(): void {
		// No user seeded; an unrelated user's data must survive untouched.
		$this->meta['8:' . self::PREFS_META] = [ 'theirs' => 'bob-secret' ];

		$result = Fahad_AI_Memory_Tools::gdpr_erase( 'nobody@example.com' );

		$this->assertFalse( $result['items_removed'] );
		$this->assertTrue( $result['done'] );
		$this->assertArrayHasKey( '8:' . self::PREFS_META, $this->meta );
	}

	public function test_gdpr_erase_for_empty_email_does_not_touch_meta(): void {
		// Whitespace email → user_id_for_email returns 0 → no meta is ever touched.
		$this->meta['8:' . self::PREFS_META] = [ 'theirs' => 'bob-secret' ];
		Functions\expect( 'get_user_by' )->never();
		Functions\expect( 'delete_user_meta' )->never();

		$result = Fahad_AI_Memory_Tools::gdpr_erase( '   ' );

		$this->assertFalse( $result['items_removed'] );
		$this->assertTrue( $result['done'] );
		$this->assertArrayHasKey( '8:' . self::PREFS_META, $this->meta );
	}

	public function test_gdpr_erase_only_purges_the_resolved_users_meta(): void {
		$this->seedUser( 'jane@example.com', 7 );
		$this->meta['7:' . self::OPTIN_META] = '1';
		$this->meta['7:' . self::PREFS_META] = [ 'mine' => 'yes' ];
		$this->meta['8:' . self::OPTIN_META] = '1';
		$this->meta['8:' . self::PREFS_META] = [ 'theirs' => 'secret' ];

		Fahad_AI_Memory_Tools::gdpr_erase( 'jane@example.com' );

		$this->assertArrayNotHasKey( '7:' . self::OPTIN_META, $this->meta );
		$this->assertArrayNotHasKey( '7:' . self::PREFS_META, $this->meta );
		$this->assertArrayHasKey( '8:' . self::OPTIN_META, $this->meta );
		$this->assertArrayHasKey( '8:' . self::PREFS_META, $this->meta );
	}

	// ── user_id_for_email (private helper guards) ────────────────────────────────

	public function test_user_id_for_email_resolves_a_known_email(): void {
		$this->seedUser( 'jane@example.com', 42 );

		$this->assertSame( 42, $this->callPrivate( 'user_id_for_email', [ 'jane@example.com' ] ) );
	}

	public function test_user_id_for_email_returns_zero_for_an_unknown_email(): void {
		$this->assertSame( 0, $this->callPrivate( 'user_id_for_email', [ 'ghost@example.com' ] ) );
	}

	public function test_user_id_for_email_short_circuits_to_zero_for_an_empty_email(): void {
		// sanitize_email('   ') → '' so the helper returns 0 BEFORE any get_user_by lookup.
		Functions\expect( 'get_user_by' )->never();

		$this->assertSame( 0, $this->callPrivate( 'user_id_for_email', [ '   ' ] ) );
	}

	// ── has_consent (defensive $user_id <= 0 guard) ──────────────────────────────

	public function test_has_consent_returns_false_for_a_non_positive_user_id(): void {
		// The guard short-circuits without reading user meta for an invalid id.
		Functions\expect( 'get_user_meta' )->never();

		$this->assertFalse( $this->callPrivate( 'has_consent', [ 0 ] ) );
		$this->assertFalse( $this->callPrivate( 'has_consent', [ -3 ] ) );
	}

	public function test_has_consent_reads_the_optin_flag_for_a_valid_user(): void {
		$this->meta['5:' . self::OPTIN_META] = '1';

		$this->assertTrue( $this->callPrivate( 'has_consent', [ 5 ] ) );
		// No flag → no consent.
		$this->assertFalse( $this->callPrivate( 'has_consent', [ 6 ] ) );
	}

	// ── read_prefs (defensive $user_id <= 0 guard + corrupt-meta defence) ─────────

	public function test_read_prefs_returns_empty_for_a_non_positive_user_id(): void {
		Functions\expect( 'get_user_meta' )->never();

		$this->assertSame( [], $this->callPrivate( 'read_prefs', [ 0 ] ) );
		$this->assertSame( [], $this->callPrivate( 'read_prefs', [ -1 ] ) );
	}

	public function test_read_prefs_returns_the_stored_map_for_a_valid_user(): void {
		$this->meta['5:' . self::PREFS_META] = [ 'size' => 'large' ];

		$this->assertSame( [ 'size' => 'large' ], $this->callPrivate( 'read_prefs', [ 5 ] ) );
	}

	public function test_read_prefs_defends_against_corrupt_non_array_meta(): void {
		// A corrupted scalar meta value yields a clean empty map, not a fatal.
		$this->meta['5:' . self::PREFS_META] = 'not-an-array';

		$this->assertSame( [], $this->callPrivate( 'read_prefs', [ 5 ] ) );
	}

	// ── truncate (mb_substr branch) ──────────────────────────────────────────────

	public function test_truncate_caps_length_using_mb_substr_when_available(): void {
		// mb_substr exists in this runtime, so the multibyte branch is taken.
		$long = str_repeat( 'a', 100 );

		$this->assertSame( str_repeat( 'a', 10 ), $this->callPrivate( 'truncate', [ $long, 10 ] ) );
		// A shorter string is returned unchanged.
		$this->assertSame( 'short', $this->callPrivate( 'truncate', [ 'short', 64 ] ) );
	}
}
