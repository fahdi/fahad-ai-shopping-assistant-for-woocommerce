<?php
/**
 * Unit tests for Fahad_AI_Voice (issue #64: voice input/output).
 *
 * Red → Green → Refactor. Conventions mirror ProactiveTest / FeedbackTest: WP functions
 * mocked via Brain\Monkey; the singleton reset via reflection between cases (NEVER
 * ReflectionMethod::setAccessible — host runs PHP 8.5); get_option stubbed via an
 * in-memory $this->options map.
 *
 * ─── WHAT IS ACTUALLY TESTABLE HERE ─────────────────────────────────────────────────
 *
 * Voice input/output is overwhelmingly client-side (the browser Web Speech API:
 * SpeechRecognition for input, speechSynthesis for output). The voice UX itself can only
 * be exercised in a real browser. The one piece of load-bearing PHP is the CONFIG GATE:
 * the widget gets a voice config ONLY when the merchant turned voice on, so a store that
 * has not opted in can never render the mic/speaker controls. These tests pin that gate,
 * plus the TTS sub-toggle and the conservative OFF-by-default behaviour — the same
 * pattern proven for the proactive nudge (#65).
 */

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;

class VoiceTest extends TestCase {

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
		] );

		Functions\when( 'get_option' )->alias(
			fn( $name, $default = false ) => $this->options[ $name ] ?? $default
		);
		// get_bloginfo: the language tag drives the recognition/synthesis locale. Default
		// to en-US unless a test overrides it.
		Functions\when( 'get_bloginfo' )->alias(
			fn( $show = '' ) => 'language' === $show ? 'en-US' : ''
		);
		// apply_filters: identity on the value unless a test overrides it.
		Functions\when( 'apply_filters' )->alias(
			fn( $hook, $value = null ) => $value
		);
	}

	protected function tearDown(): void {
		( new ReflectionProperty( Fahad_AI_Voice::class, 'instance' ) )->setValue( null, null );
		Monkey\tearDown();
		parent::tearDown();
	}

	/** Fresh singleton (reset between cases via reflection). */
	private function voice(): Fahad_AI_Voice {
		( new ReflectionProperty( Fahad_AI_Voice::class, 'instance' ) )->setValue( null, null );
		return Fahad_AI_Voice::instance();
	}

	// ── enabled(): the merchant kill-switch, default OFF ────────────────────────────

	public function test_enabled_defaults_off(): void {
		// Conservative default: voice is OPT-IN by the merchant. A mic button that asks for
		// a permission a shopper did not expect is the kind of surprise we avoid by default.
		$this->assertFalse( $this->voice()->enabled() );
	}

	public function test_enabled_reflects_the_option_when_on(): void {
		$this->options['fahad_ai_voice_enabled'] = 1;
		$this->assertTrue( $this->voice()->enabled() );
	}

	// ── tts_enabled(): the voice-output (speak replies) sub-toggle, default OFF ──────

	public function test_tts_defaults_off(): void {
		// Speaking replies aloud is its own opt-in: a merchant may want voice INPUT (hands
		// free typing) without the assistant talking back.
		$this->assertFalse( $this->voice()->tts_enabled() );
	}

	public function test_tts_reflects_the_option_when_on(): void {
		$this->options['fahad_ai_voice_tts'] = 1;
		$this->assertTrue( $this->voice()->tts_enabled() );
	}

	// ── config(): the localized surface handed to the widget ────────────────────────

	public function test_config_is_empty_when_voice_disabled(): void {
		// Master kill-switch OFF (default) → the widget is handed NO voice config, so the JS
		// literally cannot render the mic/speaker controls. TTS being on is irrelevant when
		// the master switch is off.
		$this->options['fahad_ai_voice_tts'] = 1;
		$this->assertSame( [], $this->voice()->config() );
	}

	public function test_config_carries_enabled_and_lang_when_voice_on(): void {
		$this->options['fahad_ai_voice_enabled'] = 1;

		$config = $this->voice()->config();

		$this->assertTrue( (bool) $config['enabled'] );
		// The recognition/synthesis locale is grounded in the site language so speech
		// matches the store's language rather than the browser's default.
		$this->assertSame( 'en-US', $config['lang'] );
	}

	public function test_config_tts_is_false_when_voice_on_but_tts_off(): void {
		// Voice INPUT only: the mic works, but the assistant does not speak replies.
		$this->options['fahad_ai_voice_enabled'] = 1;

		$config = $this->voice()->config();

		$this->assertFalse( $config['tts'] );
	}

	public function test_config_tts_is_true_when_both_on(): void {
		$this->options['fahad_ai_voice_enabled'] = 1;
		$this->options['fahad_ai_voice_tts']     = 1;

		$config = $this->voice()->config();

		$this->assertTrue( $config['tts'] );
	}

	public function test_config_lang_falls_back_to_empty_when_site_language_blank(): void {
		// A blank site language yields '' (the widget then lets the browser pick its
		// default locale) — never a crash, never a bogus tag.
		$this->options['fahad_ai_voice_enabled'] = 1;
		Functions\when( 'get_bloginfo' )->alias( fn( $show = '' ) => '' );

		$config = $this->voice()->config();

		$this->assertSame( '', $config['lang'] );
	}

	public function test_config_is_filterable(): void {
		// A site can force-disable voice (return []) or adjust the config via the filter,
		// without code edits — mirrors fahad_ai_proactive_config.
		$this->options['fahad_ai_voice_enabled'] = 1;
		Functions\when( 'apply_filters' )->alias(
			fn( $hook, $value = null ) => 'fahad_ai_voice_config' === $hook ? [] : $value
		);

		$this->assertSame( [], $this->voice()->config() );
	}

	public function test_config_survives_a_non_array_filter_return(): void {
		// Defensive: a misbehaving filter that returns a non-array must not corrupt the
		// localized config — it collapses to [] (no voice) rather than a fatal later.
		$this->options['fahad_ai_voice_enabled'] = 1;
		Functions\when( 'apply_filters' )->alias(
			fn( $hook, $value = null ) => 'fahad_ai_voice_config' === $hook ? 'nonsense' : $value
		);

		$this->assertSame( [], $this->voice()->config() );
	}
}
