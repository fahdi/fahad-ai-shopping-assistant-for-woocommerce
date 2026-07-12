<?php
/**
 * Coverage for the activation nudge (issue #190): fahad_ai_is_provider_configured()
 * and fahad_ai_setup_notice() in includes/admin-settings.php.
 *
 * The notice converts "installed but not set up" into "working", so it must appear
 * only when there is genuinely no key for the selected provider, only to users who
 * can manage the assistant, and never on the settings page itself.
 */

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__, 2 ) . '/includes/admin-settings.php';

class CoverageSetupNoticeTest extends TestCase {

	/** @var array<string,mixed> */
	private array $options = [];

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		$_GET          = [];
		$this->options = [];

		Functions\stubs( [
			'sanitize_key' => fn( $s ) => preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $s ) ),
			'wp_unslash'   => fn( $s ) => $s,
			'admin_url'    => fn( $p = '' ) => 'http://example.com/wp-admin/' . $p,
			'esc_url'      => fn( $s ) => (string) $s,
		] );
		Functions\when( 'get_option' )->alias( fn( $k, $d = '' ) => $this->options[ $k ] ?? $d );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	// ── fahad_ai_is_provider_configured ──────────────────────────────────────────

	public function test_not_configured_when_selected_provider_has_no_key(): void {
		$this->options = [ 'fahad_ai_provider' => 'anthropic' ];
		$this->assertFalse( fahad_ai_is_provider_configured() );
	}

	public function test_configured_when_selected_provider_key_is_set(): void {
		$this->options = [ 'fahad_ai_provider' => 'anthropic', 'fahad_ai_anthropic_api_key' => 'sk-abc' ];
		$this->assertTrue( fahad_ai_is_provider_configured() );
	}

	public function test_configuration_is_scoped_to_the_selected_provider(): void {
		// Provider is moonshot but only the anthropic key is present: not configured.
		$this->options = [
			'fahad_ai_provider'          => 'moonshot',
			'fahad_ai_anthropic_api_key' => 'sk-abc',
			'fahad_ai_moonshot_api_key'  => '   ',
		];
		$this->assertFalse( fahad_ai_is_provider_configured() );

		$this->options['fahad_ai_moonshot_api_key'] = 'ms-xyz';
		$this->assertTrue( fahad_ai_is_provider_configured() );
	}

	// ── fahad_ai_setup_notice ────────────────────────────────────────────────────

	public function test_notice_hidden_when_user_cannot_manage(): void {
		Functions\when( 'current_user_can' )->justReturn( false );
		$this->options = [ 'fahad_ai_provider' => 'anthropic' ];

		ob_start();
		fahad_ai_setup_notice();
		$this->assertSame( '', ob_get_clean() );
	}

	public function test_notice_hidden_when_a_provider_is_configured(): void {
		Functions\when( 'current_user_can' )->justReturn( true );
		$this->options = [ 'fahad_ai_provider' => 'anthropic', 'fahad_ai_anthropic_api_key' => 'sk-abc' ];

		ob_start();
		fahad_ai_setup_notice();
		$this->assertSame( '', ob_get_clean() );
	}

	public function test_notice_hidden_on_the_settings_page(): void {
		Functions\when( 'current_user_can' )->justReturn( true );
		$this->options       = [ 'fahad_ai_provider' => 'anthropic' ];
		$_GET['page']        = 'fahad-ai-shopping-assistant-for-woocommerce';

		ob_start();
		fahad_ai_setup_notice();
		$this->assertSame( '', ob_get_clean() );
	}

	public function test_notice_shown_when_active_but_unconfigured(): void {
		Functions\when( 'current_user_can' )->justReturn( true );
		$this->options = [ 'fahad_ai_provider' => 'anthropic' ];
		$_GET['page']  = 'some-other-page'; // page set, but not ours: still show

		ob_start();
		fahad_ai_setup_notice();
		$out = ob_get_clean();

		$this->assertStringContainsString( 'notice-warning', $out );
		$this->assertStringContainsString( 'is-dismissible', $out );
		$this->assertStringContainsString( 'options-general.php?page=fahad-ai-shopping-assistant-for-woocommerce', $out );
		$this->assertStringContainsString( 'Set up Dukandar', $out );
		$this->assertStringNotContainsString( "\u{2014}", $out ); // no em-dash
		$this->assertStringNotContainsString( "\u{2013}", $out ); // no en-dash
	}
}
