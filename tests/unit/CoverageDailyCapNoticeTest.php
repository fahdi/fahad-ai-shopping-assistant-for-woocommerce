<?php
/**
 * Coverage for the approaching-daily-cap admin warning (issue #210): fahad_ai_daily_cap_notice()
 * in admin-settings.php. When the store nears its daily AI cap, the assistant is about to
 * start turning shoppers away (a peak-time lost-sales risk), so warn the owner in time to
 * raise the limit. A stronger message shows once the cap is actually reached.
 */

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__, 2 ) . '/includes/admin-settings.php';

class CoverageDailyCapNoticeTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\stubs( [
			'esc_url'  => fn( $s ) => (string) $s,
			'esc_html' => fn( $s ) => (string) $s,
		] );
		Functions\when( 'admin_url' )->alias( fn( $p = '' ) => 'http://example.com/wp-admin/' . $p );
		Functions\when( 'apply_filters' )->alias( fn( $tag, $val ) => $val );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/** Seed the cap option + a today-dated counter so the auth helpers read them. */
	private function seed_cap_and_count( int $cap, int $count ): void {
		Functions\when( 'get_option' )->alias(
			fn( $k, $d = '' ) => 'fahad_ai_daily_message_cap' === $k
				? $cap
				: [ 'date' => gmdate( 'Ymd' ), 'count' => $count ]
		);
	}

	public function test_notice_hidden_when_user_cannot_manage(): void {
		Functions\when( 'current_user_can' )->justReturn( false );
		$this->seed_cap_and_count( 100, 100 );
		ob_start();
		fahad_ai_daily_cap_notice();
		$this->assertSame( '', ob_get_clean() );
	}

	public function test_notice_hidden_when_not_approaching(): void {
		Functions\when( 'current_user_can' )->justReturn( true );
		$this->seed_cap_and_count( 100, 10 );
		ob_start();
		fahad_ai_daily_cap_notice();
		$this->assertSame( '', ob_get_clean() );
	}

	public function test_notice_warns_when_approaching_but_not_reached(): void {
		Functions\when( 'current_user_can' )->justReturn( true );
		$this->seed_cap_and_count( 100, 85 );
		ob_start();
		fahad_ai_daily_cap_notice();
		$out = ob_get_clean();

		$this->assertStringContainsString( 'notice-warning', $out );
		$this->assertStringContainsString( '85', $out );
		$this->assertStringContainsString( '100', $out );
		$this->assertStringContainsString( 'options-general.php?page=fahad-ai-shopping-assistant-for-woocommerce', $out );
		$this->assertStringNotContainsString( "\u{2014}", $out );
		$this->assertStringNotContainsString( "\u{2013}", $out );
	}

	public function test_notice_is_stronger_once_reached(): void {
		Functions\when( 'current_user_can' )->justReturn( true );
		$this->seed_cap_and_count( 100, 100 );
		ob_start();
		fahad_ai_daily_cap_notice();
		$out = ob_get_clean();

		$this->assertStringContainsString( 'notice-error', $out );
		$this->assertStringContainsString( '100', $out );
		$this->assertStringNotContainsString( "\u{2014}", $out );
		$this->assertStringNotContainsString( "\u{2013}", $out );
	}
}
