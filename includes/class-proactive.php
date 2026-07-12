<?php
defined( 'ABSPATH' ) || exit;

/**
 * Proactive, consented, value-gated assist, the SERVER-SIDE decision helper (issue #65).
 *
 * The brains behind the widget's proactive nudge. A proactive nudge is the single
 * easiest feature in this plugin to turn into spam or a dark pattern, so the bar is
 * deliberately high and the gates live HERE, in unit-testable PHP, rather than being
 * trusted to client JS. The widget is handed at most a small, fully-grounded config and
 * enforces the same frequency/dismissal rules client-side; it can never invent a deal.
 *
 * ─── STRICTLY VALUE-GATED (ROADMAP §6, the whole point) ─────────────────────────────
 *
 * A nudge is eligible ONLY when BOTH hold:
 *   1. The merchant turned it on (kill-switch, default OFF, opt-in).
 *   2. A REAL value signal exists right now, a genuinely-applicable coupon (from
 *      list_active_coupons, which only ever returns codes WooCommerce itself accepts)
 *      OR unused store credit (a positive wallet balance for a logged-in shopper).
 *
 * The trigger (idle / exit-intent / returning with a cart) is owned by the widget, but
 * the trigger ALONE never fires a nudge, it must carry grounded value or there is
 * nothing to show. With no signal, config() returns [] and the widget gets nothing.
 *
 * ─── NO FABRICATED URGENCY / SCARCITY, EVER ─────────────────────────────────────────
 *
 * The nudge text is built ONLY from the grounded signal (the real coupon code/
 * description, or the real formatted balance). It states a fact and offers help. It
 * never says "hurry", "limited time", "only N left", "selling fast", etc. ProactiveTest
 * pins this with a banned-vocabulary check, the deterministic analogue of the eval
 * suite's scarcity_violations checker.
 *
 * ─── FREQUENCY CAP + DISMISSAL (consent / opt-out) ──────────────────────────────────
 *
 * is_eligible() refuses once the per-visitor show count reaches the cap, and refuses
 * outright if the shopper has dismissed. The cap is configurable (default 1 = once per
 * session); a non-positive cap means "never proactively nudge". The widget persists the
 * show count + dismissal under config()['storageKey'] (sessionStorage/localStorage), so
 * a dismissed nudge does not reappear, the shopper's own opt-out.
 *
 * ─── NO PII ──────────────────────────────────────────────────────────────────────────
 *
 * This helper stores nothing and feeds nothing to the model. The value signals come
 * from the store-wide coupon list and the shopper's OWN wallet balance; only a
 * formatted, non-identifying string ever reaches the widget config.
 *
 * Stateless singleton (mirrors Fahad_AI_Feedback / Fahad_AI_Auth): no per-instance
 * state, reset between tests via reflection on self::$instance.
 */
final class Fahad_AI_Proactive {

	/** Merchant kill-switch (default OFF, proactive nudges are opt-in). */
	public const OPTION_ENABLED = 'fahad_ai_proactive_enabled';

	/** Per-visitor frequency cap (default 1 = once per session). */
	public const OPTION_FREQUENCY = 'fahad_ai_proactive_frequency';

	/** Default frequency cap when the option is unset. */
	public const DEFAULT_FREQUENCY = 1;

	/** Stable storage key the widget uses to remember shows + dismissal per visitor. */
	public const STORAGE_KEY = 'fahad_ai_proactive_v1';

	private static ?Fahad_AI_Proactive $instance = null;

	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {}

	// -------------------------------------------------------------------------
	// Merchant config (kill-switch + frequency)
	// -------------------------------------------------------------------------

	/**
	 * Is the proactive nudge enabled by the merchant? Default OFF, the feature is
	 * opt-in, the conservative choice for anything that interrupts the shopper.
	 */
	public function enabled(): bool {
		return (bool) get_option( self::OPTION_ENABLED, 0 );
	}

	/**
	 * The per-visitor frequency cap. Default DEFAULT_FREQUENCY (once per session);
	 * floored at 0 so garbage / negative values collapse to "never nudge" rather than
	 * an unbounded loop.
	 */
	public function frequency_cap(): int {
		return max( 0, (int) get_option( self::OPTION_FREQUENCY, self::DEFAULT_FREQUENCY ) );
	}

	// -------------------------------------------------------------------------
	// The pure eligibility decision (the load-bearing gate)
	// -------------------------------------------------------------------------

	/**
	 * Decide whether a proactive nudge may be shown, given a fully-resolved decision
	 * input. Pure and deterministic, no WordPress, no I/O, so every gate is unit
	 * testable in isolation. ALL of the following must hold:
	 *
	 *   - enabled       , the merchant kill-switch is on.
	 *   - has_value     , a REAL value signal is present (coupon or store credit).
	 *   - frequency_cap , strictly positive (a non-positive cap means never nudge).
	 *   - shown_count   , strictly below the cap (the cap bounds repeats).
	 *   - !dismissed    , the shopper has not dismissed (consent / opt-out honored).
	 *
	 * @param array{enabled?:bool,has_value?:bool,frequency_cap?:int,shown_count?:int,dismissed?:bool} $d
	 */
	public function is_eligible( array $d ): bool {
		if ( empty( $d['enabled'] ) ) {
			return false; // Merchant kill-switch.
		}
		if ( empty( $d['has_value'] ) ) {
			return false; // Value-gate: a trigger alone is never enough.
		}
		if ( ! empty( $d['dismissed'] ) ) {
			return false; // Shopper opt-out.
		}

		$cap   = (int) ( $d['frequency_cap'] ?? 0 );
		$shown = (int) ( $d['shown_count'] ?? 0 );
		if ( $cap <= 0 ) {
			return false; // Non-positive cap → never proactively nudge.
		}

		return $shown < $cap; // Frequency cap bounds repeats.
	}

	// -------------------------------------------------------------------------
	// Value signal, grounded in REAL store data, never invented
	// -------------------------------------------------------------------------

	/**
	 * Resolve the single grounded value signal to offer, or null when there is
	 * genuinely nothing of value.
	 *
	 * A coupon is preferred over store credit when both exist: a store-wide,
	 * currently-applicable coupon benefits any shopper, whereas credit is per-account.
	 * This is a deterministic choice of ONE signal, never two stacked nudges.
	 *
	 *   - $coupons: the list_active_coupons() result shape { found:int, coupons:array }.
	 *     Those codes already passed every WooCommerce validity gate (published, not
	 *     expired, within usage limits, applicable to the cart), so a code present here
	 *     is a REAL, usable deal.
	 *   - $balance: the wallet provider's get_balance() shape { amount, formatted, … },
	 *     or null when the shopper is a guest / there is no wallet. A positive amount is
	 *     unused store credit; zero/empty is NOT a benefit and yields no signal.
	 *
	 * @param array      $coupons list_active_coupons() result.
	 * @param array|null $balance Wallet balance, or null.
	 * @return array{type:string,message:string}|null
	 */
	public function value_signal( array $coupons, ?array $balance ): ?array {
		$coupon = $this->first_valid_coupon( $coupons );
		if ( null !== $coupon ) {
			return [
				'type'    => 'coupon',
				'message' => $this->coupon_message( $coupon ),
			];
		}

		if ( $this->balance_has_credit( $balance ) ) {
			return [
				'type'    => 'credit',
				'message' => $this->credit_message( $balance ),
			];
		}

		return null;
	}

	/**
	 * The first genuinely-usable coupon summary from a list_active_coupons() result, or
	 * null. Defends against a malformed result and requires a non-empty code.
	 *
	 * @return array{code:string,description?:string}|null
	 */
	private function first_valid_coupon( array $coupons ): ?array {
		$list = $coupons['coupons'] ?? [];
		if ( ! is_array( $list ) ) {
			return null;
		}
		foreach ( $list as $coupon ) {
			if ( is_array( $coupon ) && '' !== (string) ( $coupon['code'] ?? '' ) ) {
				return $coupon;
			}
		}
		return null;
	}

	/** Does the balance represent a positive amount of unused store credit? */
	private function balance_has_credit( ?array $balance ): bool {
		if ( null === $balance ) {
			return false;
		}
		return isset( $balance['amount'] ) && (float) $balance['amount'] > 0;
	}

	/**
	 * Honest, urgency-free nudge text for a real coupon. References the grounded code
	 * (and its plain description when present) and offers help, no scarcity language.
	 *
	 * @param array{code:string,description?:string} $coupon
	 */
	private function coupon_message( array $coupon ): string {
		$code        = wp_strip_all_tags( (string) $coupon['code'] );
		$description = wp_strip_all_tags( (string) ( $coupon['description'] ?? '' ) );

		if ( '' !== $description ) {
			return sprintf(
				/* translators: 1: coupon code, e.g. SAVE10; 2: plain discount description, e.g. "10% off". A calm, factual nudge, no urgency. */
				__( 'You can use code %1$s (%2$s) on this order. Want help applying it?', 'fahad-ai-shopping-assistant-for-woocommerce' ),
				$code,
				$description
			);
		}

		return sprintf(
			/* translators: %s: coupon code, e.g. SAVE10. A calm, factual nudge, no urgency. */
			__( 'You can use code %s on this order. Want help applying it?', 'fahad-ai-shopping-assistant-for-woocommerce' ),
			$code
		);
	}

	/**
	 * Honest, urgency-free nudge text for unused store credit. References the real
	 * formatted balance and offers help, no scarcity language.
	 *
	 * @param array{formatted?:string,amount?:float} $balance
	 */
	private function credit_message( array $balance ): string {
		$formatted = wp_strip_all_tags( (string) ( $balance['formatted'] ?? '' ) );
		if ( '' === $formatted ) {
			$formatted = (string) ( $balance['amount'] ?? '' );
		}

		return sprintf(
			/* translators: %s: the shopper's formatted store-credit balance, e.g. ₨500. A calm, factual nudge, no urgency. */
			__( 'You have %s in store credit. Want to use it on this order?', 'fahad-ai-shopping-assistant-for-woocommerce' ),
			$formatted
		);
	}

	// -------------------------------------------------------------------------
	// The localized surface handed to the widget
	// -------------------------------------------------------------------------

	/**
	 * Build the proactive config the widget receives, or [] when no nudge may be shown.
	 *
	 * Returns [] (the widget gets nothing, so it CANNOT show a nudge) unless ALL of:
	 *   - the merchant kill-switch is on,
	 *   - the frequency cap is strictly positive,
	 *   - a grounded value signal was resolved (passed in, computed from real data).
	 *
	 * When eligible, the config carries the grounded message + type, the cap, and a
	 * stable storage key the widget uses to remember per-visitor shows + dismissal. The
	 * widget enforces the same cap/dismissal client-side via that key. A `fahad_ai_proactive_config`
	 * filter lets a site adjust the final config (e.g. force-disable) without code edits.
	 *
	 * @param array{type:string,message:string}|null $signal The grounded value signal.
	 * @return array Empty array, or the widget config.
	 */
	public function config( ?array $signal ): array {
		if ( ! $this->enabled() || null === $signal ) {
			return [];
		}

		$cap = $this->frequency_cap();
		if ( $cap <= 0 ) {
			return [];
		}

		$config = [
			'enabled'      => true,
			'frequencyCap' => $cap,
			'type'         => (string) $signal['type'],
			'message'      => (string) $signal['message'],
			'storageKey'   => self::STORAGE_KEY,
		];

		/**
		 * Filter the proactive nudge config before it is localized to the widget.
		 * Return [] to suppress the nudge entirely on a given request.
		 *
		 * @param array $config The resolved config (or the same shape).
		 */
		$config = apply_filters( 'fahad_ai_proactive_config', $config );

		return is_array( $config ) ? $config : [];
	}
}
