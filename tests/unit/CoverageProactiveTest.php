<?php
/**
 * Supplemental line-coverage tests for Fahad_AI_Proactive (issue #65).
 *
 * Companion to ProactiveTest: this file pins the remaining defensive / branch paths
 * that ProactiveTest does not exercise — a malformed coupons result, the no-description
 * coupon nudge, and the credit nudge falling back to the raw amount when no formatted
 * string is supplied. Conventions mirror ProactiveTest exactly (Brain\Monkey for WP
 * functions, singleton reset by reflection, get_option stubbed via an in-memory map).
 */

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;

class CoverageProactiveTest extends TestCase {

	use MockeryPHPUnitIntegration;

	/** @var array<string, mixed> In-memory stand-in for the WP options table. */
	private array $options = [];

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$this->options = [];

		// __ / esc_html__ are real pass-throughs from tests/stubs/wc-stubs.php — do NOT
		// re-stub them here (DefinedTooEarly).
		Functions\stubs( [
			'sanitize_text_field' => fn( $s ) => is_string( $s ) ? trim( $s ) : '',
			'sanitize_key'        => fn( $s ) => is_string( $s ) ? strtolower( trim( $s ) ) : '',
			'absint'              => fn( $n ) => abs( (int) $n ),
			'wp_strip_all_tags'   => fn( $s ) => is_string( $s ) ? trim( strip_tags( $s ) ) : '',
		] );

		Functions\when( 'get_option' )->alias(
			fn( $name, $default = false ) => $this->options[ $name ] ?? $default
		);
		Functions\when( 'apply_filters' )->alias(
			fn( $hook, $value = null ) => $value
		);
	}

	protected function tearDown(): void {
		( new ReflectionProperty( Fahad_AI_Proactive::class, 'instance' ) )->setValue( null, null );
		Monkey\tearDown();
		parent::tearDown();
	}

	/** Fresh singleton (reset between cases via reflection). */
	private function proactive(): Fahad_AI_Proactive {
		( new ReflectionProperty( Fahad_AI_Proactive::class, 'instance' ) )->setValue( null, null );
		return Fahad_AI_Proactive::instance();
	}

	// ── first_valid_coupon(): malformed 'coupons' that is not an array ───────────────

	public function test_value_signal_is_null_when_coupons_key_is_not_an_array(): void {
		// Defensive grounding (line 185): a list_active_coupons() result whose 'coupons'
		// is not an array is malformed — it is NOT a real deal and must yield no signal.
		// With no wallet credit either, value_signal() must resolve to null.
		$signal = $this->proactive()->value_signal( [ 'found' => 1, 'coupons' => 'oops' ], null );
		$this->assertNull( $signal );
	}

	public function test_value_signal_is_null_when_coupons_key_is_missing(): void {
		// The '?? []' default keeps a totally-empty result safe (it iterates an empty
		// list and returns null) — no coupon, no credit → nothing of value.
		$this->assertNull( $this->proactive()->value_signal( [ 'found' => 0 ], null ) );
	}

	// ── coupon_message(): the no-description branch ──────────────────────────────────

	public function test_coupon_message_omits_parens_when_there_is_no_description(): void {
		// Lines 222-226: a real coupon with NO description still produces an honest,
		// urgency-free nudge that references the grounded code — and, crucially, the
		// short form has no parenthetical description segment.
		$coupons = [ 'found' => 1, 'coupons' => [ [ 'code' => 'SAVE10' ] ] ];
		$signal  = $this->proactive()->value_signal( $coupons, null );

		$this->assertIsArray( $signal );
		$this->assertSame( 'coupon', $signal['type'] );
		$this->assertStringContainsString( 'SAVE10', $signal['message'] );
		// The no-description message must not carry a "(...)" description clause.
		$this->assertStringNotContainsString( '(', $signal['message'] );
		$this->assertStringContainsString( 'You can use code SAVE10 on this order.', $signal['message'] );
	}

	public function test_coupon_message_treats_empty_description_as_no_description(): void {
		// An explicit empty-string description must collapse to the same short form (the
		// "'' !== $description" guard is false), still no parenthetical segment.
		$coupons = [ 'found' => 1, 'coupons' => [ [ 'code' => 'WELCOME', 'description' => '' ] ] ];
		$signal  = $this->proactive()->value_signal( $coupons, null );

		$this->assertStringContainsString( 'WELCOME', $signal['message'] );
		$this->assertStringNotContainsString( '(', $signal['message'] );
	}

	public function test_coupon_message_with_description_keeps_the_parenthetical(): void {
		// Sanity counter-case: WITH a description the long form is used and the grounded
		// description appears inside parentheses (the other branch of the same method).
		$coupons = [ 'found' => 1, 'coupons' => [ [ 'code' => 'SAVE10', 'description' => '10% off' ] ] ];
		$signal  = $this->proactive()->value_signal( $coupons, null );

		$this->assertStringContainsString( '(10% off)', $signal['message'] );
	}

	// ── credit_message(): the formatted-empty → raw-amount fallback ──────────────────

	public function test_credit_message_falls_back_to_raw_amount_when_not_formatted(): void {
		// Line 238: when the wallet provides a positive amount but NO formatted string,
		// the nudge falls back to the raw amount so the shopper still sees the real value.
		$balance = [ 'amount' => 500.0 ];
		$signal  = $this->proactive()->value_signal( [ 'found' => 0, 'coupons' => [] ], $balance );

		$this->assertIsArray( $signal );
		$this->assertSame( 'credit', $signal['type'] );
		$this->assertStringContainsString( '500', $signal['message'] );
	}

	public function test_credit_message_falls_back_when_formatted_is_empty_string(): void {
		// An explicit empty formatted string also triggers the raw-amount fallback (the
		// "'' === $formatted" guard is true) — the grounded amount must still surface.
		$balance = [ 'amount' => 12.5, 'formatted' => '' ];
		$signal  = $this->proactive()->value_signal( [ 'found' => 0, 'coupons' => [] ], $balance );

		$this->assertSame( 'credit', $signal['type'] );
		$this->assertStringContainsString( '12.5', $signal['message'] );
	}
}
