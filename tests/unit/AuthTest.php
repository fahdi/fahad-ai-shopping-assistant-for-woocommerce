<?php
/**
 * Unit tests for Fahad_AI_Auth, the privacy / authorization BOUNDARY used by
 * personal-data tools (order status #17, wallet #18, cross-session memory #20).
 *
 * Red → Green → Refactor. The negative paths are first-class here: data leakage
 * is the highest-severity failure mode, so the guest-block and the
 * ownership-BYPASS attempt (user 5 must NOT reach user 9's record) are tested as
 * deliberately as the happy paths.
 *
 * WP functions (is_user_logged_in / get_current_user_id) are mocked via
 * Brain\Monkey, mirroring the other unit suites.
 */

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;

class AuthTest extends TestCase {

	use MockeryPHPUnitIntegration;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	// ── is_logged_in() / current_user_id() thin wrappers ─────────────────────

	public function test_is_logged_in_reflects_wordpress(): void {
		Functions\when( 'is_user_logged_in' )->justReturn( true );
		$this->assertTrue( Fahad_AI_Auth::is_logged_in() );

		Functions\when( 'is_user_logged_in' )->justReturn( false );
		$this->assertFalse( Fahad_AI_Auth::is_logged_in() );
	}

	public function test_current_user_id_reflects_wordpress(): void {
		Functions\when( 'get_current_user_id' )->justReturn( 42 );
		$this->assertSame( 42, Fahad_AI_Auth::current_user_id() );
	}

	// ── guard_logged_in(), the central guest gate ───────────────────────────

	public function test_guard_logged_in_returns_true_when_logged_in(): void {
		Functions\when( 'is_user_logged_in' )->justReturn( true );

		$this->assertTrue( Fahad_AI_Auth::guard_logged_in() );
	}

	public function test_guard_logged_in_returns_error_array_for_guest(): void {
		Functions\when( 'is_user_logged_in' )->justReturn( false );

		$result = Fahad_AI_Auth::guard_logged_in();

		// A tool returns arrays to the model, NOT a WP_Error, so the guard must
		// hand back a plain array the tool can return directly.
		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'error', $result );
		$this->assertArrayHasKey( 'requires_login', $result );
		$this->assertTrue( $result['requires_login'] );
		// Clear, human message, not a fatal, not silent.
		$this->assertIsString( $result['error'] );
		$this->assertNotSame( '', trim( $result['error'] ) );
	}

	// ── user_owns(), the per-record ownership primitive ──────────────────────

	public function test_user_owns_true_when_owner_matches_current_user(): void {
		Functions\when( 'get_current_user_id' )->justReturn( 7 );

		// Owner id 7 == current user 7 → owns it.
		$this->assertTrue( Fahad_AI_Auth::user_owns( 7 ) );
	}

	public function test_user_owns_false_for_a_different_owner(): void {
		Functions\when( 'get_current_user_id' )->justReturn( 7 );

		// Owner id 8 != current user 7 → does NOT own it.
		$this->assertFalse( Fahad_AI_Auth::user_owns( 8 ) );
	}

	/**
	 * OWNERSHIP-BYPASS attempt (headline acceptance criterion): the current user
	 * (5) must NOT be able to access a record owned by another user (9). This is
	 * the exact shape #17/#18/#20 will use: `user_owns( $order->get_customer_id() )`.
	 */
	public function test_user_owns_blocks_access_to_another_users_record(): void {
		Functions\when( 'get_current_user_id' )->justReturn( 5 );

		// Record belongs to user 9; the logged-in caller is user 5.
		$this->assertFalse(
			Fahad_AI_Auth::user_owns( 9 ),
			'user 5 must NOT be treated as owner of user 9\'s record'
		);
	}

	public function test_user_owns_false_for_guest_even_when_owner_id_is_zero(): void {
		Functions\when( 'get_current_user_id' )->justReturn( 0 );

		// A guest (id 0) must never "own" anything, even a record with owner 0.
		$this->assertFalse( Fahad_AI_Auth::user_owns( 0 ) );
	}

	public function test_user_owns_accepts_an_explicit_user_id(): void {
		// No need to consult get_current_user_id when caller passes the id.
		$this->assertTrue( Fahad_AI_Auth::user_owns( 12, 12 ) );
		$this->assertFalse( Fahad_AI_Auth::user_owns( 12, 13 ) );
		// Explicit guest id is still rejected.
		$this->assertFalse( Fahad_AI_Auth::user_owns( 0, 0 ) );
	}

	// ── mask_email(), PII minimization helper ────────────────────────────────

	public function test_mask_email_masks_local_part_keeps_domain(): void {
		$this->assertSame( 'j***@example.com', Fahad_AI_Auth::mask_email( 'jane@example.com' ) );
	}

	public function test_mask_email_handles_single_char_local_part(): void {
		// One-char local part: keep the first char, still mask.
		$this->assertSame( 'a***@example.com', Fahad_AI_Auth::mask_email( 'a@example.com' ) );
	}

	public function test_mask_email_handles_empty_input_safely(): void {
		$this->assertSame( '', Fahad_AI_Auth::mask_email( '' ) );
	}

	public function test_mask_email_handles_malformed_input_safely(): void {
		// No "@", not a valid email. Must not throw or leak a domain it cannot find.
		$masked = Fahad_AI_Auth::mask_email( 'not-an-email' );
		$this->assertIsString( $masked );
		// The original full string must NOT survive unmasked.
		$this->assertNotSame( 'not-an-email', $masked );
	}
}
