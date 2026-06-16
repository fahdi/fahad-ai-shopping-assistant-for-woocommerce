<?php
defined( 'ABSPATH' ) || exit;

/**
 * Wallet / store-credit tools (issue #18) — the DIFFERENTIATOR, and MONEY-sensitive.
 *
 * "What's my balance?", "top up $50 (and what bonus do I get?)", "pay with my store
 * credit" — for LOGGED-IN customers only. This is the highest-differentiation feature:
 * it ties the assistant to the org's own wallet plugins (WalletPro / Account Funds).
 *
 * ─── DECOUPLING: the assistant core does NOT depend on any wallet plugin ───────────
 *
 * The store's wallet plugin owns the money LEDGER. This pack must NOT hard-depend on
 * that plugin's internals, so it talks to it through a PROVIDER ADAPTER resolved at
 * runtime via a WordPress filter:
 *
 *     $provider = apply_filters( 'fahad_ai_wallet_provider', null );
 *
 * The wallet plugin registers the adapter:
 *
 *     add_filter( 'fahad_ai_wallet_provider', fn() => new My_WalletPro_Adapter() );
 *
 * The assistant ships these TOOLS; the wallet plugin ships the PROVIDER. Either side
 * can change independently. (This pack itself self-registers via the first-party
 * register_pack() drop-in mechanism — issue #22 — so adding it is one new file with
 * no bootstrap edits; the provider seam is what keeps it decoupled from the wallet
 * plugin specifically.)
 *
 * ─── PROVIDER ADAPTER CONTRACT (what the wallet plugin must register) ──────────────
 *
 * A provider is an OBJECT exposing the methods below, OR an ARRAY of callables keyed
 * by the same operation names (both are accepted — see self::call()). All money
 * amounts are floats in the store currency. Every operation that the wallet plugin
 * cannot service should return an `[ 'error' => string ]` array — NEVER throw, and
 * NEVER report a success it did not perform. Atomicity/idempotency of the LEDGER are
 * the PROVIDER's responsibility (this tool layer only guarantees it never creates an
 * unsafe condition — see the money-safety section below).
 *
 *   get_balance( int $user_id ): array
 *       → [ 'amount' => float, 'currency' => string, 'formatted' => string ]
 *
 *   get_deposit_bonus( float $amount ): array|null
 *       → the bonus a top-up of $amount would earn, surfaced HONESTLY so the model can
 *         mention it (never to pressure). Shape: [ 'amount' => float, 'currency' =>
 *         string, 'formatted' => string ], or null when no bonus applies.
 *
 *   top_up( int $user_id, float $amount ): array
 *       → credit the wallet; MUST be ATOMIC in the provider. Returns the NEW balance
 *         [ 'amount', 'currency', 'formatted' ], or [ 'error' => string ] on failure.
 *
 *   pay_with_credit( int $user_id, float $amount, array $context = [] ): array
 *       → debit the wallet for an order/cart; MUST be ATOMIC + IDEMPOTENT in the
 *         provider. Returns the new balance (and may include 'paid' => true), or
 *         [ 'error' => string ] on failure. The actual order/cart application is the
 *         provider's job; $context carries any hints (e.g. order id) the model passed.
 *
 * ─── MONEY-SAFETY invariants enforced HERE, at the TOOL layer ──────────────────────
 *
 * The provider owns ledger atomicity; the tool's job is to never CREATE an unsafe
 * condition and never REPORT something it cannot confirm:
 *
 *   1. AMOUNT VALIDATION. Non-numeric / non-positive amounts are rejected BEFORE the
 *      provider is touched (no bogus debit/credit ever reaches the ledger).
 *   2. SUFFICIENT-BALANCE-BEFORE-PAY (no double-spend). pay_with_credit reads the
 *      balance and refuses if it cannot cover the amount — the provider's debit is
 *      never even attempted. (The provider is still the final authority; this is the
 *      tool refusing to ask for an impossible debit.)
 *   3. NO SUCCESS WITHOUT CONFIRMATION. The tool reports success ONLY by passing
 *      through what the provider returned. A provider error is surfaced verbatim-ish;
 *      the tool never fabricates a balance or a "paid". The assistant holds no balance,
 *      so a provider failure is a clean no-op on our side (no partial/rolled-back state
 *      to reconcile — the debit is ONE atomic provider call, never split here).
 *   4. CURRENT-USER-ONLY. Every operation acts on Fahad_AI_Auth::current_user_id();
 *      a user id in the model INPUT is ignored, so the model cannot touch another
 *      customer's wallet. (The `personal` flag below blocks guests centrally; using the
 *      current user id enforces per-user ownership.)
 *
 * ─── AUTH: personal-data tools, login-gated centrally (issue #25) ──────────────────
 *
 * All three tools declare `'personal' => true`, so Fahad_AI_Tool_Registry::dispatch()
 * runs Fahad_AI_Auth::guard_logged_in() BEFORE the callback — a guest is blocked
 * centrally with the standard login-required error and the provider is never touched.
 *
 * ─── GRACEFUL DEGRADATION ──────────────────────────────────────────────────────────
 *
 * The tools are ALWAYS registered, even with no wallet plugin present. With no provider
 * the tools return a clear `[ 'error' => 'Wallet is not available on this store.' ]` —
 * never fatal, never an invented balance. Registering-always (rather than only when a
 * provider exists) is the simplest path AND lets the model honestly explain that the
 * store has no wallet, instead of the capability silently vanishing.
 */
final class Fahad_AI_Wallet_Tools {

	/**
	 * The graceful "no wallet plugin" error. Centralized so every tool degrades with
	 * the SAME message and tests can assert one string.
	 *
	 * @return array{error: string}
	 */
	private static function unavailable(): array {
		return [
			'error' => __(
				'Wallet is not available on this store.',
				'fahad-ai-shopping-assistant-for-woocommerce'
			),
		];
	}

	/**
	 * Append the wallet tools to the registry's tool list.
	 *
	 * Registered as a pack provider (see the register_pack() call at file scope).
	 * Static because the pack holds no per-instance state — its tools resolve the
	 * wallet provider via the filter and call the shared Fahad_AI_Auth boundary.
	 *
	 * All three tools carry `'personal' => true` so the registry login-gates them
	 * centrally (the first authorization layer).
	 *
	 * @param array $tools Existing tool definitions.
	 * @return array Tools with the wallet tools appended.
	 */
	public static function register( array $tools ): array {
		$tools[] = [
			'name'        => 'get_wallet_balance',
			'description' => 'Get the logged-in customer\'s wallet / store-credit balance. Use this when the customer asks "what\'s my balance?", "how much store credit do I have?", or similar. Returns the amount, currency, and a display-formatted balance. Only ever reports the signed-in customer\'s OWN balance. Requires the customer to be logged in.',
			'parameters'  => [
				'type'       => 'object',
				'properties' => new stdClass(),
			],
			'personal'    => true,
			'callback'    => fn( array $input ) => self::get_wallet_balance( $input ),
		];

		$tools[] = [
			'name'        => 'top_up',
			'description' => 'Add funds to the logged-in customer\'s wallet / store credit by a given amount. Use this when the customer asks to "top up", "add credit", or "load my wallet". If the store offers a deposit bonus for this amount, the result includes it under "deposit_bonus" — mention any bonus HONESTLY and factually (e.g. "adding $50 also earns a $5 bonus"); never pressure the customer to deposit. Returns the new balance. Requires the customer to be logged in.',
			'parameters'  => [
				'type'       => 'object',
				'properties' => [
					'amount' => [ 'type' => 'number', 'description' => 'The amount to add to the wallet. Must be greater than zero.' ],
				],
				'required'   => [ 'amount' ],
			],
			'personal'    => true,
			'callback'    => fn( array $input ) => self::top_up( $input ),
		];

		$tools[] = [
			'name'        => 'pay_with_credit',
			'description' => 'Pay for the current order or cart using the logged-in customer\'s wallet / store credit, for a given amount. Use this when the customer asks to "pay with my store credit / wallet / balance". The balance is checked first; if it cannot cover the amount the payment is refused and nothing is charged. Returns the new balance on success. Only ever charges the signed-in customer\'s OWN wallet. Requires the customer to be logged in.',
			'parameters'  => [
				'type'       => 'object',
				'properties' => [
					'amount'   => [ 'type' => 'number',  'description' => 'The amount to pay from the wallet. Must be greater than zero.' ],
					'order_id' => [ 'type' => 'integer', 'description' => 'Optional ID of the order this payment is for, passed to the wallet provider as context.' ],
				],
				'required'   => [ 'amount' ],
			],
			'personal'    => true,
			'callback'    => fn( array $input ) => self::pay_with_credit( $input ),
		];

		return $tools;
	}

	// -------------------------------------------------------------------------
	// Tool implementations
	// -------------------------------------------------------------------------

	/**
	 * Current customer's wallet balance, straight from the provider.
	 *
	 * Always asks the provider about Fahad_AI_Auth::current_user_id() — never a user
	 * id from the model input — so the model cannot read another customer's balance.
	 * With no provider, degrades to the graceful "not available" error (no invented
	 * balance). The central login gate has already ensured the caller is logged in.
	 *
	 * @return array The provider's balance, or { error } when unavailable.
	 */
	private static function get_wallet_balance( array $input ): array {
		$provider = self::provider();
		if ( null === $provider ) {
			return self::unavailable();
		}

		$balance = self::call( $provider, 'get_balance', [ Fahad_AI_Auth::current_user_id() ] );
		if ( null === $balance ) {
			return self::unavailable();
		}

		return is_array( $balance ) ? $balance : self::unavailable();
	}

	/**
	 * Add funds to the current customer's wallet, surfacing any deposit bonus.
	 *
	 * Money-safety: the amount is validated (> 0, numeric) BEFORE the provider is
	 * touched. The deposit bonus is surfaced honestly (so the model can mention it),
	 * then the ATOMIC top_up is delegated to the provider in ONE call. Success is
	 * reported ONLY from what the provider returns — a provider error is surfaced and
	 * no balance is fabricated. Always acts on the current user id.
	 *
	 * @return array { balance:array, deposit_bonus?:array } on success, else { error }.
	 */
	private static function top_up( array $input ): array {
		$amount = self::validate_amount( $input['amount'] ?? null );
		if ( null === $amount ) {
			return self::invalid_amount();
		}

		$provider = self::provider();
		if ( null === $provider ) {
			return self::unavailable();
		}

		$user_id = Fahad_AI_Auth::current_user_id();

		// Surface the deposit bonus honestly so the model can mention it (read-only;
		// a missing/unsupported bonus op is simply "no bonus", never an error).
		$bonus = self::call( $provider, 'get_deposit_bonus', [ $amount ] );

		// Delegate the ATOMIC credit to the provider in a single call.
		$result = self::call( $provider, 'top_up', [ $user_id, $amount ] );
		if ( null === $result ) {
			return self::unavailable();
		}
		if ( ! is_array( $result ) ) {
			return self::unavailable();
		}
		// NO SUCCESS WITHOUT CONFIRMATION: a provider error is surfaced as-is; we never
		// report a balance the provider did not confirm.
		if ( isset( $result['error'] ) ) {
			return [ 'error' => (string) $result['error'] ];
		}

		$response = [ 'balance' => $result ];
		if ( is_array( $bonus ) && ! empty( $bonus ) ) {
			$response['deposit_bonus'] = $bonus;
		}

		return $response;
	}

	/**
	 * Pay for an order/cart from the current customer's wallet.
	 *
	 * Money-safety, in order:
	 *   1. Validate the amount (> 0, numeric) BEFORE touching the provider.
	 *   2. SUFFICIENT-BALANCE GATE (no double-spend): read the balance and refuse if it
	 *      cannot cover the amount — the provider's debit is NEVER attempted.
	 *   3. Delegate the ATOMIC + idempotent debit to the provider in ONE call; report
	 *      success ONLY from what the provider returns (a provider error → error, no
	 *      fabricated "paid"). The debit is never split into non-atomic steps here, so
	 *      there is no partial state to roll back on the assistant side.
	 * Always acts on the current user id (a model-supplied user id is ignored).
	 *
	 * @return array The provider's post-payment result, or { error }.
	 */
	private static function pay_with_credit( array $input ): array {
		$amount = self::validate_amount( $input['amount'] ?? null );
		if ( null === $amount ) {
			return self::invalid_amount();
		}

		$provider = self::provider();
		if ( null === $provider ) {
			return self::unavailable();
		}

		$user_id = Fahad_AI_Auth::current_user_id();

		// (2) Sufficient-balance gate — read first, refuse before any debit attempt.
		$balance = self::call( $provider, 'get_balance', [ $user_id ] );
		if ( ! is_array( $balance ) || ! isset( $balance['amount'] ) ) {
			return self::unavailable();
		}
		if ( (float) $balance['amount'] < $amount ) {
			return [
				'error' => __(
					'Insufficient wallet balance to cover this amount.',
					'fahad-ai-shopping-assistant-for-woocommerce'
				),
			];
		}

		// (3) Atomic debit, delegated to the provider in ONE call. Pass any context the
		// model supplied (e.g. order id) for the provider to apply.
		$context = [];
		if ( isset( $input['order_id'] ) ) {
			$context['order_id'] = absint( $input['order_id'] );
		}

		$result = self::call( $provider, 'pay_with_credit', [ $user_id, $amount, $context ] );
		if ( ! is_array( $result ) ) {
			return self::unavailable();
		}
		// NO SUCCESS WITHOUT CONFIRMATION.
		if ( isset( $result['error'] ) ) {
			return [ 'error' => (string) $result['error'] ];
		}

		// Normalize the confirmed result into a { balance, paid } envelope so the model
		// sees a consistent success shape regardless of the provider's exact return.
		$response = [ 'balance' => $result, 'paid' => true ];
		if ( array_key_exists( 'paid', $result ) ) {
			$response['paid'] = (bool) $result['paid'];
		}

		return $response;
	}

	// -------------------------------------------------------------------------
	// Provider seam + helpers
	// -------------------------------------------------------------------------

	/**
	 * Resolve the wallet provider via the decoupling filter. Returns the registered
	 * provider (object or array of callables), or null when no wallet plugin supplied
	 * one — the signal for graceful degradation. This is the ONLY coupling point to the
	 * wallet plugin, and it is a runtime filter, so the assistant core depends on no
	 * wallet-plugin class.
	 *
	 * @return object|array|null
	 */
	private static function provider() {
		$provider = apply_filters( 'fahad_ai_wallet_provider', null );

		if ( is_object( $provider ) || is_array( $provider ) ) {
			return $provider;
		}

		return null;
	}

	/**
	 * Invoke one provider operation, supporting BOTH provider shapes:
	 *   - an OBJECT with a method named $op, or
	 *   - an ARRAY of callables keyed by $op.
	 *
	 * Returns the operation's result, or null when the provider does not support the
	 * operation (so the caller can degrade gracefully rather than fatal). A throwing
	 * provider is isolated to null too — a misbehaving wallet adapter must never fatal
	 * the agent request (mirrors the registry's callback isolation), and for a money op
	 * "null" means "unconfirmed", which the callers treat as a no-op / unavailable.
	 *
	 * @param object|array $provider Resolved provider.
	 * @param string       $op       Operation name.
	 * @param array        $args     Positional args for the operation.
	 * @return mixed|null The op result, or null when unsupported/failed.
	 */
	private static function call( $provider, string $op, array $args ) {
		$callable = null;

		if ( is_object( $provider ) && is_callable( [ $provider, $op ] ) ) {
			$callable = [ $provider, $op ];
		} elseif ( is_array( $provider ) && isset( $provider[ $op ] ) && is_callable( $provider[ $op ] ) ) {
			$callable = $provider[ $op ];
		}

		if ( null === $callable ) {
			return null;
		}

		try {
			return call_user_func_array( $callable, $args );
		} catch ( \Throwable $e ) {
			return null;
		}
	}

	/**
	 * Validate a money amount from model input. Returns a positive float, or null when
	 * the value is non-numeric or not strictly greater than zero. This is the gate that
	 * keeps a bogus/zero/negative amount from ever reaching the provider (money-safety
	 * invariant #1). Booleans are rejected (is_numeric(false) is false) so a stray flag
	 * can never be read as an amount.
	 *
	 * @param mixed $raw
	 * @return float|null
	 */
	private static function validate_amount( $raw ): ?float {
		if ( ! is_numeric( $raw ) ) {
			return null;
		}

		$amount = (float) $raw;
		if ( $amount <= 0 ) {
			return null;
		}

		return $amount;
	}

	/**
	 * The standard invalid-amount error. Centralized so top_up / pay_with_credit reject
	 * with the same message.
	 *
	 * @return array{error: string}
	 */
	private static function invalid_amount(): array {
		return [
			'error' => __(
				'Please provide an amount greater than zero.',
				'fahad-ai-shopping-assistant-for-woocommerce'
			),
		];
	}
}

// Self-register this feature pack the moment the file is loaded. The bootstrap
// (and the test bootstrap) glob-require includes/tools/*.php, so dropping this file
// in is the ONLY wiring needed — no bootstrap or harness edits.
Fahad_AI_Tool_Registry::register_pack( [ 'Fahad_AI_Wallet_Tools', 'register' ] );
