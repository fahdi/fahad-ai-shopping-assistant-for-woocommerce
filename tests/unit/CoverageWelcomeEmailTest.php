<?php
/**
 * Coverage for the one-time welcome email (issue #229): fahad_ai_should_send_welcome() and
 * fahad_ai_build_welcome_email() in admin-settings.php. These are the pure, testable pieces;
 * the wp_mail send + the "sent once" option write are thin wiring in the main plugin file.
 *
 * The welcome confirms the assistant is live the first time a provider is configured (the
 * moment activation succeeds) and points the owner at the highest-value next steps.
 */

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__, 2 ) . '/includes/admin-settings.php';

class CoverageWelcomeEmailTest extends TestCase {

	/** @var array<string,mixed> */
	private array $options = [];

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		$this->options = [];
		Functions\when( 'get_option' )->alias( fn( $k, $d = '' ) => $this->options[ $k ] ?? $d );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	private function configure_provider(): void {
		$this->options['fahad_ai_provider']          = 'anthropic';
		$this->options['fahad_ai_anthropic_api_key'] = 'sk-abc';
	}

	// ── should_send_welcome ──────────────────────────────────────────────────────

	public function test_no_welcome_when_not_configured(): void {
		$this->assertFalse( fahad_ai_should_send_welcome() );
	}

	public function test_welcome_due_once_configured(): void {
		$this->configure_provider();
		$this->assertTrue( fahad_ai_should_send_welcome() );
	}

	public function test_no_welcome_after_it_was_sent(): void {
		$this->configure_provider();
		$this->options['fahad_ai_welcome_sent'] = '1';
		$this->assertFalse( fahad_ai_should_send_welcome() );
	}

	// ── build_welcome_email ──────────────────────────────────────────────────────

	public function test_build_confirms_live_and_guides_next_steps(): void {
		$body = fahad_ai_build_welcome_email( 'http://example.com/wp-admin/options-general.php?page=fahad-ai' );

		$this->assertStringContainsString( 'live', $body );
		$this->assertStringContainsString( 'Store Information', $body );          // high-value next step
		$this->assertStringContainsString( 'http://example.com/wp-admin/options-general.php?page=fahad-ai', $body );
		$this->assertStringNotContainsString( "\u{2014}", $body );
		$this->assertStringNotContainsString( "\u{2013}", $body );
	}
}
