<?php
/**
 * Coverage for the timed review request (issue #192): fahad_ai_should_request_review(),
 * fahad_ai_review_notice() and fahad_ai_maybe_dismiss_review() in admin-settings.php.
 *
 * The nudge must only appear after real, sustained use (provider configured + 14 days
 * since activation) and must be permanently dismissible via a nonce-protected link.
 */

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

if ( ! defined( 'DAY_IN_SECONDS' ) ) {
	define( 'DAY_IN_SECONDS', 86400 );
}

require_once dirname( __DIR__, 2 ) . '/includes/admin-settings.php';

class CoverageReviewNoticeTest extends TestCase {

	/** @var array<string,mixed> */
	private array $options = [];

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		$_GET          = [];
		$this->options = [];

		Functions\stubs( [
			'sanitize_text_field' => fn( $s ) => is_string( $s ) ? trim( $s ) : '',
			'wp_unslash'          => fn( $s ) => $s,
			'esc_url'             => fn( $s ) => (string) $s,
			'add_query_arg'       => fn( ...$a ) => 'http://example.com/wp-admin/?dismiss=1',
			'wp_nonce_url'        => fn( $url, ...$a ) => $url . '&_wpnonce=abc',
		] );
		Functions\when( 'get_option' )->alias( fn( $k, $d = '' ) => $this->options[ $k ] ?? $d );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	private function configured_used_store(): void {
		$this->options = [
			'fahad_ai_provider'          => 'anthropic',
			'fahad_ai_anthropic_api_key' => 'sk-abc',
			'fahad_ai_activated_at'      => time() - 20 * DAY_IN_SECONDS,
		];
	}

	// ── should_request_review ────────────────────────────────────────────────────

	public function test_no_review_request_when_not_configured(): void {
		$this->options = [ 'fahad_ai_provider' => 'anthropic', 'fahad_ai_activated_at' => time() - 20 * DAY_IN_SECONDS ];
		$this->assertFalse( fahad_ai_should_request_review() );
	}

	public function test_no_review_request_when_already_dismissed(): void {
		$this->configured_used_store();
		$this->options['fahad_ai_review_dismissed'] = '1';
		$this->assertFalse( fahad_ai_should_request_review() );
	}

	public function test_no_review_request_before_activation_recorded(): void {
		$this->options = [ 'fahad_ai_provider' => 'anthropic', 'fahad_ai_anthropic_api_key' => 'sk-abc', 'fahad_ai_activated_at' => 0 ];
		$this->assertFalse( fahad_ai_should_request_review() );
	}

	public function test_no_review_request_within_the_first_two_weeks(): void {
		$this->configured_used_store();
		$this->options['fahad_ai_activated_at'] = time() - 3 * DAY_IN_SECONDS;
		$this->assertFalse( fahad_ai_should_request_review() );
	}

	public function test_review_requested_after_two_weeks_of_configured_use(): void {
		$this->configured_used_store();
		$this->assertTrue( fahad_ai_should_request_review() );
	}

	// ── review_notice ────────────────────────────────────────────────────────────

	public function test_notice_hidden_when_user_cannot_manage(): void {
		Functions\when( 'current_user_can' )->justReturn( false );
		$this->configured_used_store();
		ob_start();
		fahad_ai_review_notice();
		$this->assertSame( '', ob_get_clean() );
	}

	public function test_notice_hidden_when_not_yet_due(): void {
		Functions\when( 'current_user_can' )->justReturn( true );
		// unconfigured, so should_request_review() is false
		$this->options = [ 'fahad_ai_provider' => 'anthropic' ];
		ob_start();
		fahad_ai_review_notice();
		$this->assertSame( '', ob_get_clean() );
	}

	public function test_notice_shown_after_sustained_use(): void {
		Functions\when( 'current_user_can' )->justReturn( true );
		$this->configured_used_store();
		ob_start();
		fahad_ai_review_notice();
		$out = ob_get_clean();

		$this->assertStringContainsString( 'notice-info', $out );
		$this->assertStringContainsString( 'wordpress.org/support/plugin/fahad-ai-shopping-assistant-for-woocommerce/reviews', $out );
		$this->assertStringContainsString( '_wpnonce=abc', $out );
		$this->assertStringContainsString( 'Leave a review', $out );
		$this->assertStringNotContainsString( "\u{2014}", $out );
		$this->assertStringNotContainsString( "\u{2013}", $out );
	}

	// ── maybe_dismiss_review ─────────────────────────────────────────────────────

	public function test_dismiss_ignored_without_the_query_arg(): void {
		Functions\expect( 'update_option' )->never();
		fahad_ai_maybe_dismiss_review();
		$this->assertTrue( true );
	}

	public function test_dismiss_ignored_when_user_cannot_manage(): void {
		$_GET['fahad_ai_dismiss_review'] = '1';
		Functions\when( 'current_user_can' )->justReturn( false );
		Functions\expect( 'update_option' )->never();
		fahad_ai_maybe_dismiss_review();
		$this->assertTrue( true );
	}

	public function test_dismiss_ignored_with_a_bad_nonce(): void {
		$_GET['fahad_ai_dismiss_review'] = '1';
		$_GET['_wpnonce']                = 'nope';
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'wp_verify_nonce' )->justReturn( false );
		Functions\expect( 'update_option' )->never();
		fahad_ai_maybe_dismiss_review();
		$this->assertTrue( true );
	}

	public function test_dismiss_persists_with_a_valid_nonce(): void {
		$_GET['fahad_ai_dismiss_review'] = '1';
		$_GET['_wpnonce']                = 'good';
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'wp_verify_nonce' )->justReturn( true );
		Functions\expect( 'update_option' )->once()->with( 'fahad_ai_review_dismissed', '1' );
		fahad_ai_maybe_dismiss_review();
		$this->assertTrue( true );
	}
}
