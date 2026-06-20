<?php
/**
 * Unit tests for Fahad_AI_Proactive (issue #65: proactive, consented, value-gated
 * assist — the SERVER-SIDE decision helper behind the widget's proactive nudge).
 *
 * Red → Green → Refactor. Conventions mirror FeedbackTest / StockAlertsTest: WP
 * functions mocked via Brain\Monkey; the singleton reset via reflection between cases
 * (NEVER ReflectionMethod::setAccessible — host runs PHP 8.5); get_option stubbed via
 * an in-memory $this->options map.
 *
 * ─── THE ANTI-DARK-PATTERN BAR IS THE WHOLE POINT (ROADMAP §6) ─────────────────────
 *
 * A proactive nudge is the easiest feature in the whole plugin to turn into spam / a
 * manipulation. These tests pin the guarantees that keep it honest:
 *
 *   - VALUE-GATE: a nudge is eligible ONLY when the merchant enabled it AND a REAL
 *     value signal is present (a genuinely-applicable coupon, or unused store credit).
 *     No signal → never eligible (the JS is handed nothing to show).
 *   - KILL-SWITCH: the merchant option defaults OFF, and OFF is never eligible even
 *     with a perfect value signal.
 *   - FREQUENCY CAP: a per-visitor cap is carried into the config; the gate refuses a
 *     repeat once the cap is reached.
 *   - DISMISSAL: a dismissed nudge is never eligible again.
 *   - NO FABRICATED URGENCY: the produced nudge text is built from the grounded value
 *     signal only; it carries no scarcity/urgency vocabulary, EVER.
 */

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;

class ProactiveTest extends TestCase {

	use MockeryPHPUnitIntegration;

	/** @var array<string, mixed> In-memory stand-in for the WP options table. */
	private array $options = [];

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$this->options = [];

		// NOTE: __ / esc_html__ are defined as real pass-throughs by tests/stubs/wc-stubs.php
		// (loaded before Patchwork), so they must NOT be re-stubbed here (DefinedTooEarly).
		Functions\stubs( [
			'sanitize_text_field' => fn( $s ) => is_string( $s ) ? trim( $s ) : '',
			'sanitize_key'        => fn( $s ) => is_string( $s ) ? strtolower( trim( $s ) ) : '',
			'absint'              => fn( $n ) => abs( (int) $n ),
			'wp_strip_all_tags'   => fn( $s ) => is_string( $s ) ? trim( strip_tags( $s ) ) : '',
		] );

		Functions\when( 'get_option' )->alias(
			fn( $name, $default = false ) => $this->options[ $name ] ?? $default
		);
		// apply_filters: identity on the value unless a test overrides it.
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

	/**
	 * A realistic eligibility-decision input: enabled, a real value signal, no prior
	 * shows this session, not dismissed. Individual tests override one key at a time so
	 * each assertion isolates exactly one gate.
	 *
	 * @param array<string, mixed> $overrides
	 * @return array<string, mixed>
	 */
	private function decision( array $overrides = [] ): array {
		return array_merge( [
			'enabled'        => true,
			'has_value'      => true,
			'frequency_cap'  => 1,
			'shown_count'    => 0,
			'dismissed'      => false,
		], $overrides );
	}

	// ── is_eligible(): the pure decision (the load-bearing gate) ────────────────────

	public function test_eligible_when_enabled_with_real_value_and_under_cap(): void {
		$this->assertTrue( $this->proactive()->is_eligible( $this->decision() ) );
	}

	public function test_disabled_merchant_option_is_never_eligible(): void {
		// KILL-SWITCH: OFF wins even with a perfect value signal under the cap.
		$this->assertFalse(
			$this->proactive()->is_eligible( $this->decision( [ 'enabled' => false ] ) )
		);
	}

	public function test_no_real_value_signal_is_never_eligible(): void {
		// VALUE-GATE: the trigger alone (idle/exit/return) is not enough — a nudge must
		// carry a grounded benefit or it never fires.
		$this->assertFalse(
			$this->proactive()->is_eligible( $this->decision( [ 'has_value' => false ] ) )
		);
	}

	public function test_frequency_cap_blocks_a_repeat(): void {
		// Already shown as many times as the cap allows → not eligible again.
		$this->assertFalse(
			$this->proactive()->is_eligible( $this->decision( [ 'frequency_cap' => 1, 'shown_count' => 1 ] ) )
		);
	}

	public function test_under_a_higher_cap_is_still_eligible(): void {
		$this->assertTrue(
			$this->proactive()->is_eligible( $this->decision( [ 'frequency_cap' => 3, 'shown_count' => 2 ] ) )
		);
	}

	public function test_a_zero_or_negative_cap_blocks_everything(): void {
		// A non-positive cap means "never proactively nudge" (a conservative reading).
		$this->assertFalse(
			$this->proactive()->is_eligible( $this->decision( [ 'frequency_cap' => 0, 'shown_count' => 0 ] ) )
		);
	}

	public function test_dismissal_blocks_even_under_cap_with_value(): void {
		// DISMISSAL: a shopper who dismissed must never be re-nudged this scope.
		$this->assertFalse(
			$this->proactive()->is_eligible( $this->decision( [ 'dismissed' => true ] ) )
		);
	}

	// ── enabled(): the merchant kill-switch, default OFF ────────────────────────────

	public function test_enabled_defaults_off(): void {
		// Conservative default: the proactive nudge is OPT-IN by the merchant.
		$this->assertFalse( $this->proactive()->enabled() );
	}

	public function test_enabled_reflects_the_option_when_on(): void {
		$this->options['fahad_ai_proactive_enabled'] = 1;
		$this->assertTrue( $this->proactive()->enabled() );
	}

	// ── frequency_cap(): sane bounded default + override ────────────────────────────

	public function test_frequency_cap_default_is_one_per_session(): void {
		$this->assertSame( 1, $this->proactive()->frequency_cap() );
	}

	public function test_frequency_cap_reads_a_configured_value(): void {
		$this->options['fahad_ai_proactive_frequency'] = 2;
		$this->assertSame( 2, $this->proactive()->frequency_cap() );
	}

	public function test_frequency_cap_is_floored_at_zero_for_garbage(): void {
		$this->options['fahad_ai_proactive_frequency'] = -5;
		$this->assertSame( 0, $this->proactive()->frequency_cap() );
	}

	// ── value_signal(): grounded in REAL store data, never invented ─────────────────

	public function test_value_signal_is_null_when_no_coupon_and_no_credit(): void {
		// No applicable coupon, no wallet credit → there is genuinely nothing of value to
		// offer, so no nudge is produced.
		$signal = $this->proactive()->value_signal( [ 'found' => 0, 'coupons' => [] ], null );
		$this->assertNull( $signal );
	}

	public function test_value_signal_uses_a_real_applicable_coupon(): void {
		$coupons = [ 'found' => 1, 'coupons' => [ [ 'code' => 'SAVE10', 'description' => '10% off' ] ] ];
		$signal  = $this->proactive()->value_signal( $coupons, null );

		$this->assertIsArray( $signal );
		$this->assertSame( 'coupon', $signal['type'] );
		// The grounded code/description must appear so the message can reference a REAL deal.
		$this->assertStringContainsString( 'SAVE10', $signal['message'] );
	}

	public function test_value_signal_uses_unused_store_credit(): void {
		// No coupon, but the logged-in shopper has a positive wallet balance → that is a
		// genuine, grounded benefit ("you have X store credit").
		$balance = [ 'amount' => 500.0, 'currency' => 'PKR', 'formatted' => '₨500' ];
		$signal  = $this->proactive()->value_signal( [ 'found' => 0, 'coupons' => [] ], $balance );

		$this->assertIsArray( $signal );
		$this->assertSame( 'credit', $signal['type'] );
		$this->assertStringContainsString( '₨500', $signal['message'] );
	}

	public function test_store_credit_winback_message_has_no_fake_urgency(): void {
		// A5 win-back contract: the store-credit nudge (the grounded abandoned-cart
		// incentive) must stay calm and factual — never manufactured urgency/scarcity
		// (ROADMAP §6). This pins the trust property for the win-back message.
		$balance = [ 'amount' => 500.0, 'currency' => 'PKR', 'formatted' => '₨500' ];
		$signal  = $this->proactive()->value_signal( [ 'found' => 0, 'coupons' => [] ], $balance );

		$message = strtolower( $signal['message'] );
		foreach ( [ 'hurry', 'now ', 'today', 'limited', 'explast', 'last chance', 'expires', 'act fast', 'don\'t miss', 'only ' ] as $urgency ) {
			$this->assertStringNotContainsString( $urgency, $message, "Win-back message must not use urgency: {$urgency}" );
		}
	}

	public function test_zero_store_credit_is_not_a_value_signal(): void {
		// A zero/empty balance is NOT a benefit — never nudge "you have 0 credit".
		$balance = [ 'amount' => 0.0, 'currency' => 'PKR', 'formatted' => '₨0' ];
		$signal  = $this->proactive()->value_signal( [ 'found' => 0, 'coupons' => [] ], $balance );
		$this->assertNull( $signal );
	}

	public function test_a_coupon_entry_with_no_code_is_ignored(): void {
		// Defensive grounding: a malformed coupon row (no usable code) is NOT a deal, so
		// it must never become a nudge — fall through to credit (here, none → null).
		$coupons = [ 'found' => 1, 'coupons' => [ [ 'description' => '10% off' ] ] ];
		$this->assertNull( $this->proactive()->value_signal( $coupons, null ) );
	}

	public function test_coupon_is_preferred_but_credit_is_the_fallback(): void {
		// When BOTH exist, a coupon (store-wide, applies to anyone) is surfaced; this is a
		// deterministic choice, not two stacked nudges.
		$coupons = [ 'found' => 1, 'coupons' => [ [ 'code' => 'SAVE10', 'description' => '10% off' ] ] ];
		$balance = [ 'amount' => 500.0, 'formatted' => '₨500' ];
		$signal  = $this->proactive()->value_signal( $coupons, $balance );

		$this->assertSame( 'coupon', $signal['type'] );
	}

	// ── NO FABRICATED URGENCY (the hardening that cannot regress) ───────────────────

	public function test_a_produced_nudge_message_carries_no_urgency_or_scarcity(): void {
		// Build messages from BOTH grounded signal types and assert none of them smuggle
		// scarcity/urgency vocabulary. This is the deterministic anti-dark-pattern check —
		// the analogue of the eval suite's scarcity_violations checker.
		$messages = [];

		$coupon = $this->proactive()->value_signal(
			[ 'found' => 1, 'coupons' => [ [ 'code' => 'SAVE10', 'description' => '10% off' ] ] ],
			null
		);
		$messages[] = $coupon['message'];

		$credit = $this->proactive()->value_signal(
			[ 'found' => 0, 'coupons' => [] ],
			[ 'amount' => 500.0, 'formatted' => '₨500' ]
		);
		$messages[] = $credit['message'];

		$banned = [
			'hurry', 'hurries', 'act now', 'now only', 'last chance', 'limited time',
			'limited stock', 'only ', 'almost gone', 'selling fast', 'don\'t miss',
			'expires soon', 'ending soon', 'while stocks last', 'before it\'s gone',
			'few left', 'running out', 'urgent', 'urgency',
		];

		foreach ( $messages as $message ) {
			$lower = strtolower( $message );
			foreach ( $banned as $needle ) {
				$this->assertStringNotContainsString(
					$needle,
					$lower,
					"Proactive nudge text must never use scarcity/urgency vocabulary: found '$needle' in \"$message\"."
				);
			}
		}
	}

	// ── config(): the localized surface handed to the widget ────────────────────────

	public function test_config_is_empty_when_disabled(): void {
		// Merchant kill-switch OFF (default) → the widget is handed NO proactive config,
		// so the JS literally cannot show a nudge.
		$this->assertSame( [], $this->proactive()->config( $this->signal_coupon() ) );
	}

	public function test_config_is_empty_when_there_is_no_value_signal(): void {
		$this->options['fahad_ai_proactive_enabled'] = 1;
		$this->assertSame( [], $this->proactive()->config( null ) );
	}

	public function test_config_carries_the_grounded_message_cap_and_type_when_eligible(): void {
		$this->options['fahad_ai_proactive_enabled']   = 1;
		$this->options['fahad_ai_proactive_frequency'] = 2;

		$config = $this->proactive()->config( $this->signal_coupon() );

		$this->assertTrue( (bool) $config['enabled'] );
		$this->assertSame( 2, $config['frequencyCap'] );
		$this->assertSame( 'coupon', $config['type'] );
		$this->assertStringContainsString( 'SAVE10', $config['message'] );
		// A stable storage key so the widget can remember dismissal/shows per visitor.
		$this->assertArrayHasKey( 'storageKey', $config );
		$this->assertNotSame( '', $config['storageKey'] );
	}

	public function test_config_is_empty_when_enabled_but_cap_is_zero(): void {
		// Enabled + real value, but the merchant set the cap to 0 → effectively off; do
		// not hand the widget a nudge it must immediately suppress.
		$this->options['fahad_ai_proactive_enabled']   = 1;
		$this->options['fahad_ai_proactive_frequency'] = 0;

		$this->assertSame( [], $this->proactive()->config( $this->signal_coupon() ) );
	}

	/** A canned grounded coupon value-signal for the config() tests. */
	private function signal_coupon(): array {
		return [
			'type'    => 'coupon',
			'message' => 'You can use code SAVE10 (10% off) on this order.',
		];
	}
}
