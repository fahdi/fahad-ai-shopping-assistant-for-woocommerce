<?php
/**
 * Coverage for the weekly owner digest (issue #206): fahad_ai_weekly_digest_enabled(),
 * fahad_ai_build_weekly_digest() and fahad_ai_should_send_weekly_digest() in
 * admin-settings.php. These are the pure, testable pieces; the wp-cron schedule and the
 * wp_mail send are thin wiring in the main plugin file.
 *
 * The digest turns the analytics we already record into a plain-language weekly summary in
 * the owner's inbox (the strongest retention lever), and must never email an empty report.
 */

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__, 2 ) . '/includes/admin-settings.php';

class CoverageWeeklyDigestTest extends TestCase {

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

	// ── weekly_digest_enabled (default ON) ───────────────────────────────────────

	public function test_digest_enabled_by_default(): void {
		$this->assertTrue( fahad_ai_weekly_digest_enabled() );
	}

	public function test_digest_can_be_disabled(): void {
		$this->options['fahad_ai_weekly_digest'] = '0';
		$this->assertFalse( fahad_ai_weekly_digest_enabled() );
	}

	// ── should_send (enabled AND had activity) ───────────────────────────────────

	public function test_should_send_true_when_enabled_and_active(): void {
		$this->assertTrue( fahad_ai_should_send_weekly_digest( true, 5 ) );
	}

	public function test_should_not_send_when_disabled(): void {
		$this->assertFalse( fahad_ai_should_send_weekly_digest( false, 5 ) );
	}

	public function test_should_not_send_when_no_activity(): void {
		$this->assertFalse( fahad_ai_should_send_weekly_digest( true, 0 ) );
	}

	// ── build_weekly_digest (pure formatter) ─────────────────────────────────────

	private function stats(): array {
		return [
			'conversations' => 42,
			'added_to_cart' => 11,
			'cart_rate'     => 11 / 42,
			'orders'        => 3,
			'total_cost'    => 1.2,
			'currency'      => '$',
			'top_questions' => [
				[ 'question' => 'Do you ship to Canada?', 'count' => 7 ],
				[ 'question' => 'Where is my order?', 'count' => 5 ],
			],
			'settings_url'  => 'http://example.com/wp-admin/options-general.php?page=fahad-ai',
		];
	}

	public function test_build_includes_the_headline_numbers(): void {
		$body = fahad_ai_build_weekly_digest( $this->stats() );

		$this->assertStringContainsString( 'last 7 days', $body );
		$this->assertStringContainsString( 'Conversations: 42', $body );
		$this->assertStringContainsString( '26% chat-to-cart', $body ); // 11 of 42
		$this->assertStringContainsString( '$1.20', $body );
		$this->assertStringContainsString( 'Do you ship to Canada?', $body );
		$this->assertStringContainsString( 'http://example.com/wp-admin/options-general.php?page=fahad-ai', $body );
		$this->assertStringContainsString( 'turn', $body ); // how to turn the email off
		$this->assertStringNotContainsString( "\u{2014}", $body );
		$this->assertStringNotContainsString( "\u{2013}", $body );
	}

	public function test_build_shows_na_when_orders_unresolved(): void {
		$stats           = $this->stats();
		$stats['orders'] = null;
		$this->assertStringContainsString( 'n/a', fahad_ai_build_weekly_digest( $stats ) );
	}

	public function test_build_omits_top_questions_when_none(): void {
		$stats                  = $this->stats();
		$stats['top_questions'] = [];
		$this->assertStringNotContainsString( 'Top questions', fahad_ai_build_weekly_digest( $stats ) );
	}

	// ── unanswered questions section (issue #216) ────────────────────────────────

	public function test_build_lists_unanswered_questions_with_a_pointer(): void {
		$stats                = $this->stats();
		$stats['unanswered']  = [
			[ 'question' => 'Do you offer gift wrapping?' ],
			[ 'question' => 'When will the black hoodie be back?' ],
		];

		$body = fahad_ai_build_weekly_digest( $stats );

		$this->assertStringContainsString( 'could not answer', $body );
		$this->assertStringContainsString( 'Do you offer gift wrapping?', $body );
		$this->assertStringContainsString( 'When will the black hoodie be back?', $body );
		$this->assertStringContainsString( 'Store Information', $body ); // pointer to the fix
		$this->assertStringNotContainsString( "\u{2014}", $body );
		$this->assertStringNotContainsString( "\u{2013}", $body );
	}

	public function test_build_dedupes_and_skips_blank_unanswered_questions(): void {
		$stats               = $this->stats();
		$stats['unanswered'] = [
			[ 'question' => 'Do you restock sold-out items?' ],
			[ 'question' => '' ],                              // blank, skipped
			[ 'question' => 'Do you restock sold-out items?' ], // duplicate, collapsed
		];

		$body  = fahad_ai_build_weekly_digest( $stats );
		$count = substr_count( $body, 'Do you restock sold-out items?' );
		$this->assertSame( 1, $count, 'Duplicate unanswered questions collapse to one line.' );
	}

	public function test_build_omits_unanswered_section_when_none(): void {
		$stats               = $this->stats();
		$stats['unanswered'] = [];
		$this->assertStringNotContainsString( 'could not answer', fahad_ai_build_weekly_digest( $stats ) );
	}
}
