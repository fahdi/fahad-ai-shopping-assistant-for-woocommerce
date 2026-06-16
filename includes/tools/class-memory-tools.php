<?php
defined( 'ABSPATH' ) || exit;

/**
 * Personalization & cross-session memory tools (issue #20) — strictly OPT-IN.
 *
 * Remembers a LOGGED-IN customer's stated preferences across sessions, so the
 * assistant can be more relevant ("you usually shop for size large", "you prefer
 * blue"). Privacy is the whole point: NOTHING is stored, and NOTHING is injected
 * into the model context, until the customer has EXPLICITLY consented — and they can
 * view and clear everything at any time.
 *
 * A drop-in feature pack (same pattern as Fahad_AI_Order_Tools / Fahad_AI_Wallet_Tools):
 * a self-contained class in its own file under includes/tools/ that self-registers a
 * provider at the bottom via Fahad_AI_Tool_Registry::register_pack(), AND hooks the
 * `fahad_ai_system_prompt` filter for context injection. The bootstrap (and the test
 * bootstrap) glob-require everything here, so adding this pack is a SINGLE new file —
 * no edits to the bootstrap, the test bootstrap, the registry, or the agent loop.
 *
 * Tools provided (all `'personal' => true`):
 *   - set_memory_consent  — explicit opt-in / opt-out, stored in user meta.
 *   - remember_preference — store a stated preference. REFUSES without consent.
 *   - get_preferences     — view what is remembered + the consent state.
 *   - forget_preferences  — clear all stored preferences (and optionally consent).
 *
 * ─── CONSENT MODEL: opt-in GATES storage ───────────────────────────────────────────
 *
 * Consent is a boolean flag in the FAHAD_AI_OPTIN_META user-meta key, set ONLY by
 * set_memory_consent (which the model is instructed to call only when the customer
 * clearly agrees). remember_preference reads that flag FIRST and, when the user has not
 * opted in, returns a clear `needs_consent` message and writes NOTHING — there is no
 * code path that persists a preference without a recorded opt-in. Erasure
 * (forget_preferences) is always available, even after opting out.
 *
 * ─── CONTEXT INJECTION: compact, opt-in, loop-free ─────────────────────────────────
 *
 * inject_preferences() is hooked to the `fahad_ai_system_prompt` filter (issue #20), so
 * a compact, clearly-labelled preferences block is APPENDED to the model context for a
 * logged-in, opted-in customer with stored prefs — WITHOUT editing the agent-loop
 * methods (run_anthropic_agent / run_moonshot_agent / run_stream_agent). For a guest, an
 * opted-out user, or an empty map it appends nothing (returns the prompt unchanged). The
 * block is BOUNDED by the same caps as storage (MAX_PREFERENCES lines, each value
 * trimmed) so the prompt cannot balloon.
 *
 * ─── AUTH + PER-USER OWNERSHIP (issue #25) ─────────────────────────────────────────
 *
 *   1. CENTRAL LOGIN GATE. All four tools declare `'personal' => true`, so
 *      Fahad_AI_Tool_Registry::dispatch() runs Fahad_AI_Auth::guard_logged_in() BEFORE
 *      the callback — a guest is blocked centrally and the callback (and user meta) is
 *      never reached.
 *   2. CURRENT-USER-ONLY. Every read/write targets Fahad_AI_Auth::current_user_id();
 *      a user id supplied in the model INPUT is ignored, so the model can never read or
 *      mutate another customer's memory. This is the per-user ownership boundary for a
 *      feature whose "records" are simply this user's own meta rows.
 *
 * ─── STORAGE HYGIENE ───────────────────────────────────────────────────────────────
 *
 * The preferences map is BOUNDED: at most MAX_PREFERENCES keys, each key ≤ MAX_KEY_LENGTH
 * and each value ≤ MAX_VALUE_LENGTH characters, all sanitized. A new key beyond the cap
 * is refused (overwriting an existing key is always allowed, since it does not grow the
 * map). This keeps the stored map — and the injected context — small and predictable.
 */
final class Fahad_AI_Memory_Tools {

	/** User-meta key holding the boolean opt-in flag ('1' when consented). */
	private const OPTIN_META = 'fahad_ai_memory_optin';

	/** User-meta key holding the bounded key => value preferences map. */
	private const PREFS_META = 'fahad_ai_preferences';

	/** Max number of distinct preference keys stored per user (storage hygiene). */
	public const MAX_PREFERENCES = 20;

	/** Max length (characters) of a sanitized preference key. */
	public const MAX_KEY_LENGTH = 64;

	/** Max length (characters) of a sanitized preference value. */
	public const MAX_VALUE_LENGTH = 256;

	/**
	 * Append the memory tools to the registry's tool list.
	 *
	 * Registered as a pack provider (see the register_pack() call at file scope).
	 * Static because the pack holds no per-instance state — its tools read/write the
	 * current user's meta directly and use the shared Fahad_AI_Auth boundary.
	 *
	 * All four tools carry `'personal' => true` so the registry login-gates them
	 * centrally (the first authorization layer). The descriptions instruct the model to
	 * ask BEFORE remembering anything and to remind the customer their preferences can be
	 * viewed and cleared at any time.
	 *
	 * @param array $tools Existing tool definitions.
	 * @return array Tools with the memory tools appended.
	 */
	public static function register( array $tools ): array {
		$tools[] = [
			'name'        => 'set_memory_consent',
			'description' => 'Record whether the logged-in customer agrees to have their shopping preferences remembered across sessions. Personalization is strictly opt-in: ONLY call this with enabled=true after the customer has clearly agreed to be remembered (e.g. "yes, remember my preferences"), and call it with enabled=false if they ask to stop being remembered. Always let the customer know they can view or clear what is remembered at any time. Requires the customer to be logged in.',
			'parameters'  => [
				'type'       => 'object',
				'properties' => [
					'enabled' => [ 'type' => 'boolean', 'description' => 'True to opt in to being remembered, false to opt out.' ],
				],
				'required'   => [ 'enabled' ],
			],
			'personal'    => true,
			'callback'    => fn( array $input ) => self::set_memory_consent( $input ),
		];

		$tools[] = [
			'name'        => 'remember_preference',
			'description' => 'Store ONE stated shopping preference for the logged-in customer (e.g. key "favorite_color" value "blue", or key "size" value "large") so it can improve future recommendations. Only call this for a preference the customer has actually stated, and only AFTER they have opted in via set_memory_consent — if they have not opted in this returns a needs-consent message and stores nothing, so ask for their consent first. Mention that they can see or clear their saved preferences anytime. Requires the customer to be logged in.',
			'parameters'  => [
				'type'       => 'object',
				'properties' => [
					'key'   => [ 'type' => 'string', 'description' => 'A short label for the preference, e.g. "favorite_color", "size", "preferred_brand".' ],
					'value' => [ 'type' => 'string', 'description' => 'The preference value the customer stated, e.g. "blue", "large".' ],
				],
				'required'   => [ 'key', 'value' ],
			],
			'personal'    => true,
			'callback'    => fn( array $input ) => self::remember_preference( $input ),
		];

		$tools[] = [
			'name'        => 'get_preferences',
			'description' => 'Return the shopping preferences currently remembered for the logged-in customer, plus whether they have opted in to being remembered. Use this when the customer asks "what do you remember about me?" or "what preferences have you saved?". Only ever returns the signed-in customer\'s OWN saved preferences. Requires the customer to be logged in.',
			'parameters'  => [
				'type'       => 'object',
				'properties' => new stdClass(),
			],
			'personal'    => true,
			'callback'    => fn( array $input ) => self::get_preferences( $input ),
		];

		$tools[] = [
			'name'        => 'forget_preferences',
			'description' => 'Clear ALL saved shopping preferences for the logged-in customer (easy erasure). Use this when the customer asks to "forget my preferences", "clear what you remember", or similar. Set clear_consent=true if they also want to withdraw consent to being remembered. Only ever clears the signed-in customer\'s OWN data. Requires the customer to be logged in.',
			'parameters'  => [
				'type'       => 'object',
				'properties' => [
					'clear_consent' => [ 'type' => 'boolean', 'description' => 'Also withdraw consent to being remembered (default false: keep the opt-in, just clear the stored preferences).' ],
				],
			],
			'personal'    => true,
			'callback'    => fn( array $input ) => self::forget_preferences( $input ),
		];

		return $tools;
	}

	// -------------------------------------------------------------------------
	// Tool implementations — all act on the CURRENT user only.
	// -------------------------------------------------------------------------

	/**
	 * Record the current customer's opt-in / opt-out for cross-session memory.
	 *
	 * Writes the boolean flag for Fahad_AI_Auth::current_user_id() ONLY — a model-supplied
	 * user id is ignored. Opting out stores a falsey flag, which both blocks future
	 * storage AND stops injection (inject_preferences re-reads it); it does NOT erase
	 * already-stored prefs (use forget_preferences for that). The central login gate has
	 * already ensured the caller is logged in.
	 *
	 * @return array{ consent: bool, message: string }
	 */
	private static function set_memory_consent( array $input ): array {
		$user_id = Fahad_AI_Auth::current_user_id();
		$enabled = ! empty( $input['enabled'] ) && false !== $input['enabled'];

		if ( $enabled ) {
			update_user_meta( $user_id, self::OPTIN_META, '1' );

			return [
				'consent' => true,
				'message' => __(
					'Got it — I will remember your preferences. You can ask to see or clear them anytime.',
					'fahad-ai-shopping-assistant-for-woocommerce'
				),
			];
		}

		// Opt out: flip the flag off (we store '0' rather than delete, so the choice is
		// explicit). Stored prefs are kept until the customer clears them.
		update_user_meta( $user_id, self::OPTIN_META, '0' );

		return [
			'consent' => false,
			'message' => __(
				'No problem — I will not remember your preferences. You can turn this back on anytime.',
				'fahad-ai-shopping-assistant-for-woocommerce'
			),
		];
	}

	/**
	 * Store ONE stated preference for the current customer — ONLY with consent.
	 *
	 * THE consent gate: if the user has not opted in, return a clear `needs_consent`
	 * response and write NOTHING (no user-meta write happens on this path). With consent,
	 * the key/value are sanitized + length-capped, merged into the bounded map for
	 * Fahad_AI_Auth::current_user_id() (a model-supplied user id is ignored), and a new
	 * key beyond MAX_PREFERENCES is refused. The central login gate has already ensured
	 * the caller is logged in.
	 *
	 * @return array { stored: true } on success, { needs_consent: true, message } when
	 *               not opted in, or { error } for invalid input / a full map.
	 */
	private static function remember_preference( array $input ): array {
		$user_id = Fahad_AI_Auth::current_user_id();

		// Consent gate FIRST — never store without a recorded opt-in.
		if ( ! self::has_consent( $user_id ) ) {
			return [
				'needs_consent' => true,
				'message'       => __(
					'I can remember that, but only with your permission. Would you like me to remember your preferences for next time? You can clear them whenever you want.',
					'fahad-ai-shopping-assistant-for-woocommerce'
				),
			];
		}

		$key   = self::sanitize_key( $input['key'] ?? '' );
		$value = self::sanitize_value( $input['value'] ?? '' );

		if ( '' === $key || '' === $value ) {
			return [
				'error' => __(
					'A preference needs both a key and a value.',
					'fahad-ai-shopping-assistant-for-woocommerce'
				),
			];
		}

		$prefs = self::read_prefs( $user_id );

		// Storage hygiene: a NEW key beyond the cap is refused. Overwriting an existing
		// key is always fine — it does not grow the map.
		if ( ! array_key_exists( $key, $prefs ) && count( $prefs ) >= self::MAX_PREFERENCES ) {
			return [
				'error' => sprintf(
					/* translators: %d: the maximum number of preferences that can be saved. */
					__( 'I can only remember up to %d preferences. Please clear some before adding more.', 'fahad-ai-shopping-assistant-for-woocommerce' ),
					self::MAX_PREFERENCES
				),
			];
		}

		$prefs[ $key ] = $value;
		update_user_meta( $user_id, self::PREFS_META, $prefs );

		return [
			'stored'  => true,
			'key'     => $key,
			'value'   => $value,
			'message' => __(
				'Saved. You can ask me to show or clear your preferences anytime.',
				'fahad-ai-shopping-assistant-for-woocommerce'
			),
		];
	}

	/**
	 * Return the current customer's stored preferences + consent state, so they can see
	 * exactly what is remembered. Reads ONLY Fahad_AI_Auth::current_user_id() — a
	 * model-supplied user id is ignored. The central login gate has already ensured the
	 * caller is logged in.
	 *
	 * @return array{ consent: bool, preferences: array<string,string>, count: int }
	 */
	private static function get_preferences( array $input ): array {
		$user_id = Fahad_AI_Auth::current_user_id();
		$prefs   = self::read_prefs( $user_id );

		return [
			'consent'     => self::has_consent( $user_id ),
			'preferences' => $prefs,
			'count'       => count( $prefs ),
		];
	}

	/**
	 * Clear ALL stored preferences for the current customer (easy erasure), and
	 * optionally withdraw consent too. Acts on Fahad_AI_Auth::current_user_id() ONLY — a
	 * model-supplied user id is ignored — so it can never erase another customer's data.
	 * The central login gate has already ensured the caller is logged in.
	 *
	 * @return array{ cleared: true, consent_cleared: bool, message: string }
	 */
	private static function forget_preferences( array $input ): array {
		$user_id = Fahad_AI_Auth::current_user_id();

		delete_user_meta( $user_id, self::PREFS_META );

		$clear_consent = ! empty( $input['clear_consent'] ) && false !== $input['clear_consent'];
		if ( $clear_consent ) {
			delete_user_meta( $user_id, self::OPTIN_META );
		}

		return [
			'cleared'         => true,
			'consent_cleared' => $clear_consent,
			'message'         => __(
				'Done — I have cleared your saved preferences.',
				'fahad-ai-shopping-assistant-for-woocommerce'
			),
		];
	}

	// -------------------------------------------------------------------------
	// Context injection (fahad_ai_system_prompt filter) — loop-free.
	// -------------------------------------------------------------------------

	/**
	 * Append a compact preferences block to the system prompt for a logged-in, opted-in
	 * customer with stored preferences. Hooked to the `fahad_ai_system_prompt` filter
	 * (issue #20) so injection happens WITHOUT editing the agent-loop methods.
	 *
	 * Returns the prompt UNCHANGED for a guest, an opted-out user, or an empty map —
	 * nothing is injected until the customer has explicitly consented AND has prefs. The
	 * block is BOUNDED by the storage caps (at most MAX_PREFERENCES lines, each value
	 * trimmed to MAX_VALUE_LENGTH) so the prompt stays small. Public so the unit test can
	 * exercise the injection contract directly.
	 *
	 * @param string $prompt The current system prompt.
	 * @return string The prompt, with a preferences block appended when applicable.
	 */
	public static function inject_preferences( string $prompt ): string {
		if ( ! Fahad_AI_Auth::is_logged_in() ) {
			return $prompt;
		}

		$user_id = Fahad_AI_Auth::current_user_id();

		if ( ! self::has_consent( $user_id ) ) {
			return $prompt;
		}

		$prefs = self::read_prefs( $user_id );
		if ( empty( $prefs ) ) {
			return $prompt;
		}

		// Bound the rendered block to the cap, even if the stored map somehow exceeds it.
		$prefs = array_slice( $prefs, 0, self::MAX_PREFERENCES, true );

		$lines = [];
		foreach ( $prefs as $key => $value ) {
			$lines[] = '- ' . $key . ': ' . self::sanitize_value( (string) $value );
		}

		$block = __(
			"The signed-in customer has asked you to remember these preferences (use them to make your help more relevant; they can clear them anytime):",
			'fahad-ai-shopping-assistant-for-woocommerce'
		);

		return $prompt . "\n\n" . $block . "\n" . implode( "\n", $lines );
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	/** Whether the given user has explicitly opted in to cross-session memory. */
	private static function has_consent( int $user_id ): bool {
		if ( $user_id <= 0 ) {
			return false;
		}

		return (bool) get_user_meta( $user_id, self::OPTIN_META, true );
	}

	/**
	 * Read the user's stored preferences map as a clean key => value array. Defends
	 * against a corrupted/non-array meta value by returning an empty map.
	 *
	 * @return array<string,string>
	 */
	private static function read_prefs( int $user_id ): array {
		if ( $user_id <= 0 ) {
			return [];
		}

		$prefs = get_user_meta( $user_id, self::PREFS_META, true );

		return is_array( $prefs ) ? $prefs : [];
	}

	/** Sanitize + length-cap a preference key. */
	private static function sanitize_key( $raw ): string {
		$key = sanitize_text_field( (string) $raw );

		return self::truncate( $key, self::MAX_KEY_LENGTH );
	}

	/** Sanitize + length-cap a preference value. */
	private static function sanitize_value( $raw ): string {
		$value = sanitize_text_field( (string) $raw );

		return self::truncate( $value, self::MAX_VALUE_LENGTH );
	}

	/**
	 * Truncate a string to at most $max characters. Uses mb_substr when available so a
	 * multibyte value is not cut mid-character; falls back to substr otherwise.
	 */
	private static function truncate( string $text, int $max ): string {
		if ( function_exists( 'mb_substr' ) ) {
			return mb_substr( $text, 0, $max );
		}

		return substr( $text, 0, $max );
	}
}

// Self-register this feature pack the moment the file is loaded. The bootstrap (and the
// test bootstrap) glob-require includes/tools/*.php, so dropping this file in is the ONLY
// wiring needed — no bootstrap, registry, or agent-loop edits.
Fahad_AI_Tool_Registry::register_pack( [ 'Fahad_AI_Memory_Tools', 'register' ] );

// Hook the compact preferences block into the model context via the documented
// `fahad_ai_system_prompt` filter (issue #20). This is how memory is injected WITHOUT
// touching the agent-loop methods. Guarded with function_exists so this file can be
// glob-loaded by the unit-test bootstrap (which loads tool packs before Brain\Monkey
// patches WordPress functions per-test) without fataling on a missing add_filter — the
// unit suites exercise inject_preferences() directly and stub apply_filters themselves,
// and in WordPress add_filter is always defined so the hook is registered for real.
if ( function_exists( 'add_filter' ) ) {
	add_filter( 'fahad_ai_system_prompt', [ 'Fahad_AI_Memory_Tools', 'inject_preferences' ] );
}
