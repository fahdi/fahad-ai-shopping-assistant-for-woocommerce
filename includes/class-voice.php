<?php
defined( 'ABSPATH' ) || exit;

/**
 * Voice input/output, the SERVER-SIDE config gate behind the widget's voice controls
 * (issue #64).
 *
 * Voice is overwhelmingly a CLIENT concern: the browser's Web Speech API does the work , 
 * SpeechRecognition (speech → text) for input and speechSynthesis (text → speech) for
 * output. Both run entirely IN THE BROWSER; this plugin adds NO external service and
 * never receives, processes, or stores any audio. (Some browsers, e.g. Chrome, may relay
 * recognition audio to the vendor's own speech service, that is the browser's behaviour
 * under the user's own mic permission, not something this plugin arranges.)
 *
 * The only load-bearing PHP is this gate. Mirroring Dukandaar_Proactive (#65), the widget
 * is handed a voice config ONLY when the merchant turned voice on, so a store that has
 * not opted in literally cannot render the mic/speaker controls (and never prompts for a
 * mic permission). Keeping the gate here, in unit-testable PHP, is what VoiceTest pins.
 *
 * ─── TWO INDEPENDENT, OPT-IN TOGGLES ─────────────────────────────────────────────────
 *
 *   1. enabled()     , the master kill-switch (default OFF). When off, config() is [].
 *   2. tts_enabled() , voice OUTPUT, i.e. speak the assistant's replies (default OFF).
 *      A merchant may want hands-free INPUT without the assistant talking back, so this
 *      is its own opt-in and only meaningful when the master switch is on.
 *
 * Even when enabled, the widget still hides/disables the controls when the browser does
 * not support the relevant API, text always works fully (graceful degradation), and the
 * shopper's mic permission is always the browser's to grant (this plugin never bypasses
 * it).
 *
 * ─── NO PII ──────────────────────────────────────────────────────────────────────────
 *
 * This helper stores nothing and feeds nothing to the model. It only reads two options
 * and the site language, and hands the widget a tiny, non-identifying config.
 *
 * Stateless singleton (mirrors Dukandaar_Proactive / Dukandaar_Feedback): no per-instance
 * state, reset between tests via reflection on self::$instance.
 */
final class Dukandaar_Voice {

	/** Merchant kill-switch (default OFF, voice is opt-in). */
	public const OPTION_ENABLED = 'dukandaar_voice_enabled';

	/** Voice-output sub-toggle: speak the assistant's replies (default OFF). */
	public const OPTION_TTS = 'dukandaar_voice_tts';

	private static ?Dukandaar_Voice $instance = null;

	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {}

	// -------------------------------------------------------------------------
	// Merchant config (master switch + TTS sub-toggle)
	// -------------------------------------------------------------------------

	/**
	 * Is voice enabled by the merchant? Default OFF, the feature is opt-in, the
	 * conservative choice for anything that requests a device permission (the mic).
	 */
	public function enabled(): bool {
		return (bool) get_option( self::OPTION_ENABLED, 0 );
	}

	/**
	 * Should the assistant speak its replies aloud (voice output)? Default OFF. Only
	 * meaningful when enabled() is true; config() never surfaces it otherwise.
	 */
	public function tts_enabled(): bool {
		return (bool) get_option( self::OPTION_TTS, 0 );
	}

	// -------------------------------------------------------------------------
	// The localized surface handed to the widget
	// -------------------------------------------------------------------------

	/**
	 * Build the voice config the widget receives, or [] when voice is off.
	 *
	 * Returns [] (the widget gets nothing, so it CANNOT render the mic/speaker controls
	 * or prompt for a mic permission) unless the merchant kill-switch is on. When on, the
	 * config carries:
	 *
	 *   - enabled : true (the widget should build the controls, subject to browser support).
	 *   - tts     : whether to speak replies (the voice-output sub-toggle).
	 *   - lang    : the site language tag (e.g. en-US) so recognition/synthesis match the
	 *               store's language; '' lets the browser pick its own default.
	 *
	 * A `dukandaar_voice_config` filter lets a site adjust the final config (e.g. force a
	 * specific recognition locale, or return [] to force-disable) without code edits, and
	 * a non-array return is treated as "no voice" rather than corrupting the localized data.
	 *
	 * @return array Empty array, or the widget's voice config.
	 */
	public function config(): array {
		if ( ! $this->enabled() ) {
			return [];
		}

		$config = [
			'enabled' => true,
			'tts'     => $this->tts_enabled(),
			'lang'    => $this->lang(),
		];

		/**
		 * Filter the voice config before it is localized to the widget. Return [] to
		 * suppress voice entirely on a given request, or adjust e.g. the recognition
		 * locale.
		 *
		 * @param array $config The resolved config (or the same shape).
		 */
		$config = apply_filters( 'dukandaar_voice_config', $config );

		return is_array( $config ) ? $config : [];
	}

	/**
	 * The BCP-47 language tag to use for recognition/synthesis, from the site language
	 * (e.g. "en-US"). Returns '' when the site language is blank, in which case the widget
	 * lets the browser choose its own default locale.
	 */
	private function lang(): string {
		$lang = function_exists( 'get_bloginfo' ) ? (string) get_bloginfo( 'language' ) : '';
		return sanitize_text_field( $lang );
	}
}
