<?php
/**
 * Coverage for the provider-health warning (issue #200): fahad_ai_provider_health_notice()
 * in admin-settings.php. It warns the store owner when the AI provider has been failing
 * (a cluster of error-outcome turns in the last 24h), which is the usual silent-churn
 * cause: a wrong/expired/credit-exhausted API key that only shows up as a dead widget.
 *
 * The warning only appears for a capable, configured store once errors reach a filterable
 * threshold, and it self-clears when recent errors fall back below it (no dismiss needed).
 */

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

if ( ! defined( 'DAY_IN_SECONDS' ) ) {
	define( 'DAY_IN_SECONDS', 86400 );
}

require_once dirname( __DIR__, 2 ) . '/includes/admin-settings.php';

class CoverageProviderHealthNoticeTest extends TestCase {

	/** @var array<string,mixed> */
	private array $options = [];

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		$this->options = [];

		Functions\stubs( [
			'esc_url'  => fn( $s ) => (string) $s,
			'esc_html' => fn( $s ) => (string) $s,
			'_n'       => fn( $single, $plural, $number ) => 1 === (int) $number ? $single : $plural,
		] );
		Functions\when( 'get_option' )->alias( fn( $k, $d = '' ) => $this->options[ $k ] ?? $d );
		Functions\when( 'admin_url' )->alias( fn( $p = '' ) => 'http://example.com/wp-admin/' . $p );
		Functions\when( 'apply_filters' )->alias( fn( $tag, $value ) => $value );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	private function configured_store(): void {
		$this->options['fahad_ai_provider']          = 'anthropic';
		$this->options['fahad_ai_anthropic_api_key'] = 'sk-abc';
	}

	/** Seed the analytics option with $n recent error-outcome rows. */
	private function seed_recent_errors( int $n ): void {
		$rows = [];
		for ( $i = 0; $i < $n; $i++ ) {
			$rows[ 'e' . $i ] = [
				'id' => 'e' . $i, 'question' => 'q', 'tools' => [], 'outcome' => Fahad_AI_Analytics::OUTCOME_ERROR,
				'product_surfaced' => false, 'added_to_cart' => false, 'tokens' => 0, 'cost' => 0.0,
				'conversation_ref' => 'c' . $i, 'created' => time(),
			];
		}
		$this->options[ Fahad_AI_Analytics::OPTION ] = $rows;
	}

	public function test_notice_hidden_when_user_cannot_manage(): void {
		Functions\when( 'current_user_can' )->justReturn( false );
		$this->configured_store();
		$this->seed_recent_errors( 10 );
		ob_start();
		fahad_ai_provider_health_notice();
		$this->assertSame( '', ob_get_clean() );
	}

	public function test_notice_hidden_when_provider_not_configured(): void {
		Functions\when( 'current_user_can' )->justReturn( true );
		$this->seed_recent_errors( 10 ); // errors, but no key configured
		ob_start();
		fahad_ai_provider_health_notice();
		$this->assertSame( '', ob_get_clean() );
	}

	public function test_notice_hidden_when_errors_below_threshold(): void {
		Functions\when( 'current_user_can' )->justReturn( true );
		$this->configured_store();
		$this->seed_recent_errors( 2 ); // default threshold is 3
		ob_start();
		fahad_ai_provider_health_notice();
		$this->assertSame( '', ob_get_clean() );
	}

	public function test_notice_shown_when_errors_reach_threshold(): void {
		Functions\when( 'current_user_can' )->justReturn( true );
		$this->configured_store();
		$this->seed_recent_errors( 3 );
		ob_start();
		fahad_ai_provider_health_notice();
		$out = ob_get_clean();

		$this->assertStringContainsString( 'notice-error', $out );
		$this->assertStringContainsString( 'options-general.php?page=fahad-ai-shopping-assistant-for-woocommerce', $out );
		$this->assertStringContainsString( '3', $out );
		$this->assertStringNotContainsString( "\u{2014}", $out );
		$this->assertStringNotContainsString( "\u{2013}", $out );
	}

	public function test_threshold_is_filterable(): void {
		Functions\when( 'current_user_can' )->justReturn( true );
		$this->configured_store();
		$this->seed_recent_errors( 3 );
		// Raise the threshold above the error count → hidden.
		Functions\when( 'apply_filters' )->justReturn( 99 );
		ob_start();
		fahad_ai_provider_health_notice();
		$this->assertSame( '', ob_get_clean() );
	}
}
