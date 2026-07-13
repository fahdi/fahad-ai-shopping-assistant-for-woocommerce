<?php
/**
 * Coverage for the WordPress dashboard widget (issue #245): fahad_ai_dashboard_widget() in
 * admin-settings.php. It puts the assistant's key numbers on the screen owners see on every
 * login. The render is exercised with the analytics store empty (zeros), which is the branch
 * a fresh install hits; the registration on wp_dashboard_setup is thin wiring in the main file.
 */

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__, 2 ) . '/includes/admin-settings.php';

class CoverageDashboardWidgetTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\stubs( [
			'esc_url'                         => fn( $s ) => (string) $s,
			'esc_html'                        => fn( $s ) => (string) $s,
			'get_woocommerce_currency_symbol' => fn() => '$',
		] );
		Functions\when( 'get_option' )->alias( fn( $k, $d = '' ) => $d );
		Functions\when( 'admin_url' )->alias( fn( $p = '' ) => 'http://example.com/wp-admin/' . $p );
		( new ReflectionProperty( Fahad_AI_Analytics::class, 'instance' ) )->setValue( null, null );
	}

	protected function tearDown(): void {
		( new ReflectionProperty( Fahad_AI_Analytics::class, 'instance' ) )->setValue( null, null );
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_widget_renders_the_key_stats_and_a_link(): void {
		ob_start();
		fahad_ai_dashboard_widget();
		$out = ob_get_clean();

		$this->assertStringContainsString( 'Conversations', $out );
		$this->assertStringContainsString( 'Chat-to-cart', $out );
		$this->assertStringContainsString( 'Resolution', $out );
		$this->assertStringContainsString( 'This month', $out );
		$this->assertStringContainsString( 'options-general.php?page=fahad-ai-shopping-assistant-for-woocommerce', $out );
		$this->assertStringNotContainsString( "\u{2014}", $out );
		$this->assertStringNotContainsString( "\u{2013}", $out );
	}
}
