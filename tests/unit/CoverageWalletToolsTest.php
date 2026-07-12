<?php
/**
 * Supplemental line-coverage tests for Fahad_AI_Wallet_Tools.
 *
 * The primary behavioural suite lives in WalletToolsTest (it drives every tool
 * through the production registry dispatch path with a Mockery provider). This
 * sibling file targets the remaining UNCOVERED branches of the tool layer, the
 * defensive / degradation paths that the headline tests do not reach:
 *
 *   - get_wallet_balance: provider present but the get_balance op is UNSUPPORTED
 *     (self::call() returns null) → graceful "not available".
 *   - top_up: the provider's top_up op returns null (unsupported) and returns a
 *     NON-ARRAY scalar → both degrade to "not available", never a fabricated balance.
 *   - pay_with_credit: get_balance returns a non-array / an array WITHOUT an 'amount'
 *     key → "not available" before any debit; the pay_with_credit op returns a
 *     non-array → "not available"; an order_id in the input is absint()'d into the
 *     provider context.
 *   - self::call() seam: the ARRAY-of-callables provider shape, the unsupported-op
 *     (null callable) branch, and the THROWING-provider isolation (a misbehaving
 *     adapter must be caught and turned into null, never fatal the request).
 *
 * Conventions mirror WalletToolsTest exactly: Brain\Monkey for WP/Woo functions,
 * the wallet PROVIDER injected through the `fahad_ai_wallet_provider` filter, and the
 * registry singleton + static pack list snapshotted/restored so a case here neither
 * inherits another suite's packs nor leaks the wallet pack we register. Everything
 * runs through Fahad_AI_Tool_Registry::instance()->dispatch(), the real path.
 *
 * Unlike the headline suite, several cases here inject a PLAIN provider (an array of
 * closures, or an object missing a method) rather than a Mockery mock, that is the
 * only way to exercise the "unsupported op" and "array shape" branches of
 * self::call(), which a fully-stubbed Mockery double can never reach.
 */

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;

class CoverageWalletToolsTest extends TestCase {

	use MockeryPHPUnitIntegration;

	/**
	 * Snapshot of the registry's static pack providers, restored in tearDown so a
	 * test here neither inherits another suite's packs nor leaks the wallet pack we
	 * register.
	 *
	 * @var array<int, callable>
	 */
	private array $pack_snapshot = [];

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$this->pack_snapshot = (array) ( new ReflectionProperty( Fahad_AI_Tool_Registry::class, 'pack_providers' ) )->getValue();

		// Default to a logged-in customer (id 5) so the central login gate passes and
		// the tool callbacks actually run (the branches under test live in them).
		Functions\when( 'is_user_logged_in' )->justReturn( true );
		Functions\when( 'get_current_user_id' )->justReturn( 5 );

		// Registry get_tools() reads the merchant tool-gating option (issue #56);
		// default (no disabled tools) so dispatch() is unaffected.
		Functions\when( 'get_option' )->alias( fn( $key, $default = '' ) => $default );

		// absint() is used by pay_with_credit to sanitise the order_id context.
		Functions\when( 'absint' )->alias( fn( $v ) => abs( (int) $v ) );
	}

	protected function tearDown(): void {
		( new ReflectionProperty( Fahad_AI_Tool_Registry::class, 'pack_providers' ) )->setValue( null, $this->pack_snapshot );
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * Fresh registry whose built tool list includes the wallet tools, resets the
	 * Tools + registry singletons, then registers the wallet pack's REAL provider,
	 * exactly as the pack's file-scope self-registration does in production.
	 */
	private function registry(): Fahad_AI_Tool_Registry {
		( new ReflectionProperty( Fahad_AI_Tools::class, 'instance' ) )->setValue( null, null );
		( new ReflectionProperty( Fahad_AI_Tool_Registry::class, 'instance' ) )->setValue( null, null );

		Fahad_AI_Tool_Registry::reset_packs();
		Fahad_AI_Tool_Registry::register_pack( [ 'Fahad_AI_Wallet_Tools', 'register' ] );

		return Fahad_AI_Tool_Registry::instance();
	}

	/**
	 * Register an arbitrary wallet provider value on the `fahad_ai_wallet_provider`
	 * filter for the duration of the test, passing every OTHER hook (notably the
	 * registry's own `fahad_ai_register_tools`) through to its default so the tool
	 * list still builds normally.
	 *
	 * @param object|array|null $provider The provider to resolve (object, array of
	 *                                    callables, or null for "no provider").
	 */
	private function provide( $provider ): void {
		Functions\when( 'apply_filters' )->alias(
			static function ( $hook, $value = null ) use ( $provider ) {
				return ( 'fahad_ai_wallet_provider' === $hook ) ? $provider : $value;
			}
		);
	}

	// ── self::call(): unsupported op (object provider missing the method) ───────

	/**
	 * Line 188 + the call() "null callable" branch (361): the provider is a real
	 * object, but it does NOT expose get_balance, so self::call() resolves no callable
	 * and returns null. get_wallet_balance must then degrade to "not available" rather
	 * than invent a balance.
	 */
	public function test_get_wallet_balance_degrades_when_provider_lacks_get_balance(): void {
		// An object provider with NO get_balance method, call() finds no callable.
		$this->provide( new stdClass() );

		$result = $this->registry()->dispatch( 'get_wallet_balance', [] );

		$this->assertArrayHasKey( 'error', $result );
		$this->assertStringContainsString( 'not available', strtolower( $result['error'] ) );
		$this->assertArrayNotHasKey( 'amount', $result );
		$this->assertArrayNotHasKey( 'formatted', $result );
	}

	// ── self::call(): ARRAY-of-callables provider shape (356, 357) ──────────────

	/**
	 * The provider may be an ARRAY of callables keyed by op name (the documented
	 * second shape). This exercises call()'s array branch (356, 357): get_balance is a
	 * closure in the array and its result flows straight back through the tool.
	 */
	public function test_array_of_callables_provider_is_supported_for_get_balance(): void {
		$seen_user = null;
		$this->provide( [
			'get_balance' => function ( $user_id ) use ( &$seen_user ) {
				$seen_user = $user_id;
				return [ 'amount' => 12.5, 'currency' => 'USD', 'formatted' => '$12.50' ];
			},
		] );

		$result = $this->registry()->dispatch( 'get_wallet_balance', [] );

		// Acts on the current user id (5), via the array-shaped provider.
		$this->assertSame( 5, $seen_user );
		$this->assertArrayNotHasKey( 'error', $result );
		$this->assertSame( 12.5, $result['amount'] );
		$this->assertSame( '$12.50', $result['formatted'] );
	}

	/**
	 * Array-of-callables provider whose 'get_balance' value is NOT callable (a bare
	 * scalar). call()'s array branch requires is_callable(), so it falls through to the
	 * null-callable return (361) and the tool degrades, proving a malformed array
	 * entry never fatals.
	 */
	public function test_array_provider_with_non_callable_entry_degrades(): void {
		$this->provide( [ 'get_balance' => 'not-a-callable' ] );

		$result = $this->registry()->dispatch( 'get_wallet_balance', [] );

		$this->assertArrayHasKey( 'error', $result );
		$this->assertStringContainsString( 'not available', strtolower( $result['error'] ) );
	}

	// ── self::call(): throwing provider is isolated to null (364-368) ───────────

	/**
	 * A misbehaving adapter that THROWS must never fatal the agent request: call()
	 * catches the Throwable and returns null (366, 367), so get_wallet_balance degrades
	 * to the graceful "not available" error, a clean no-op on our side.
	 */
	public function test_throwing_provider_is_caught_and_degrades_gracefully(): void {
		$this->provide( [
			'get_balance' => function () {
				throw new \RuntimeException( 'provider blew up' );
			},
		] );

		$result = $this->registry()->dispatch( 'get_wallet_balance', [] );

		$this->assertArrayHasKey( 'error', $result );
		$this->assertStringContainsString( 'not available', strtolower( $result['error'] ) );
		$this->assertArrayNotHasKey( 'amount', $result );
	}

	// ── top_up: provider top_up unsupported / non-array result ──────────────────

	/**
	 * Line 225: amount is valid and a provider exists, but its top_up op is UNSUPPORTED
	 * (the object has get_deposit_bonus but no top_up), so call('top_up') returns null.
	 * The tool must NOT fabricate a balance, it degrades to "not available".
	 */
	public function test_top_up_degrades_when_provider_lacks_top_up_op(): void {
		// Array provider: only get_deposit_bonus is supported; top_up is absent → null.
		$this->provide( [
			'get_deposit_bonus' => fn( $amount ) => null,
			// no 'top_up' key → call('top_up') returns null
		] );

		$result = $this->registry()->dispatch( 'top_up', [ 'amount' => 100 ] );

		$this->assertArrayHasKey( 'error', $result );
		$this->assertStringContainsString( 'not available', strtolower( $result['error'] ) );
		$this->assertArrayNotHasKey( 'balance', $result );
	}

	/**
	 * Line 228: the provider's top_up returns a NON-ARRAY scalar (e.g. a bare bool/int
	 *, a non-contract return). The tool must reject it as unconfirmed and degrade to
	 * "not available", never wrap a scalar as a balance.
	 */
	public function test_top_up_degrades_when_provider_returns_non_array(): void {
		$this->provide( [
			'get_deposit_bonus' => fn( $amount ) => null,
			'top_up'            => fn( $user_id, $amount ) => true, // non-array, non-contract
		] );

		$result = $this->registry()->dispatch( 'top_up', [ 'amount' => 100 ] );

		$this->assertArrayHasKey( 'error', $result );
		$this->assertStringContainsString( 'not available', strtolower( $result['error'] ) );
		$this->assertArrayNotHasKey( 'balance', $result );
	}

	// ── pay_with_credit: balance-read degradation + order_id context + non-array ─

	/**
	 * Line 275 (non-array balance): the sufficient-balance gate reads the balance
	 * first; if get_balance returns a NON-ARRAY the tool cannot verify funds, so it
	 * degrades to "not available" and NEVER attempts a debit.
	 */
	public function test_pay_with_credit_degrades_when_balance_read_returns_non_array(): void {
		$debit_attempted = false;
		$this->provide( [
			'get_balance'     => fn( $user_id ) => 'not-an-array',
			'pay_with_credit' => function () use ( &$debit_attempted ) {
				$debit_attempted = true;
				return [ 'amount' => 0.0 ];
			},
		] );

		$result = $this->registry()->dispatch( 'pay_with_credit', [ 'amount' => 30 ] );

		$this->assertArrayHasKey( 'error', $result );
		$this->assertStringContainsString( 'not available', strtolower( $result['error'] ) );
		$this->assertArrayNotHasKey( 'paid', $result );
		$this->assertArrayNotHasKey( 'balance', $result );
		// No debit may be attempted when the balance could not be verified.
		$this->assertFalse( $debit_attempted );
	}

	/**
	 * Line 275 (array WITHOUT an 'amount' key): a balance array that is missing the
	 * required 'amount' field is unusable for the gate, so the tool degrades the same
	 * way, no debit attempt.
	 */
	public function test_pay_with_credit_degrades_when_balance_array_has_no_amount(): void {
		$debit_attempted = false;
		$this->provide( [
			'get_balance'     => fn( $user_id ) => [ 'currency' => 'USD', 'formatted' => '$??' ],
			'pay_with_credit' => function () use ( &$debit_attempted ) {
				$debit_attempted = true;
				return [ 'amount' => 0.0 ];
			},
		] );

		$result = $this->registry()->dispatch( 'pay_with_credit', [ 'amount' => 30 ] );

		$this->assertArrayHasKey( 'error', $result );
		$this->assertStringContainsString( 'not available', strtolower( $result['error'] ) );
		$this->assertArrayNotHasKey( 'paid', $result );
		$this->assertFalse( $debit_attempted );
	}

	/**
	 * Line 290: when the model supplies an order_id, the tool absint()'s it into the
	 * provider context. We assert the provider receives a context carrying the
	 * sanitised order_id (e.g. a float/string id is coerced to a positive int).
	 */
	public function test_pay_with_credit_passes_sanitised_order_id_in_context(): void {
		$received_context = null;
		$this->provide( [
			'get_balance'     => fn( $user_id ) => [ 'amount' => 100.0, 'currency' => 'USD', 'formatted' => '$100.00' ],
			'pay_with_credit' => function ( $user_id, $amount, $context ) use ( &$received_context ) {
				$received_context = $context;
				return [ 'amount' => 70.0, 'currency' => 'USD', 'formatted' => '$70.00', 'paid' => true ];
			},
		] );

		$result = $this->registry()->dispatch( 'pay_with_credit', [ 'amount' => 30, 'order_id' => '4242' ] );

		$this->assertArrayNotHasKey( 'error', $result );
		$this->assertIsArray( $received_context );
		$this->assertArrayHasKey( 'order_id', $received_context );
		$this->assertSame( 4242, $received_context['order_id'] );
		$this->assertTrue( $result['paid'] );
	}

	/**
	 * The order_id context is sanitised through absint(): a negative / float-ish value
	 * is normalised to a non-negative int before it reaches the provider.
	 */
	public function test_pay_with_credit_absints_negative_order_id(): void {
		$received_context = null;
		$this->provide( [
			'get_balance'     => fn( $user_id ) => [ 'amount' => 100.0, 'currency' => 'USD', 'formatted' => '$100.00' ],
			'pay_with_credit' => function ( $user_id, $amount, $context ) use ( &$received_context ) {
				$received_context = $context;
				return [ 'amount' => 70.0, 'paid' => true ];
			},
		] );

		$this->registry()->dispatch( 'pay_with_credit', [ 'amount' => 30, 'order_id' => -7 ] );

		$this->assertSame( 7, $received_context['order_id'] );
	}

	/**
	 * Line 295: the provider's pay_with_credit returns a NON-ARRAY (a non-contract
	 * value). Balance was sufficient, but the debit result is unconfirmable, so the
	 * tool degrades to "not available" and reports no success.
	 */
	public function test_pay_with_credit_degrades_when_debit_returns_non_array(): void {
		$this->provide( [
			'get_balance'     => fn( $user_id ) => [ 'amount' => 100.0, 'currency' => 'USD', 'formatted' => '$100.00' ],
			'pay_with_credit' => fn( $user_id, $amount, $context ) => false, // non-array result
		] );

		$result = $this->registry()->dispatch( 'pay_with_credit', [ 'amount' => 30 ] );

		$this->assertArrayHasKey( 'error', $result );
		$this->assertStringContainsString( 'not available', strtolower( $result['error'] ) );
		$this->assertArrayNotHasKey( 'paid', $result );
		$this->assertArrayNotHasKey( 'balance', $result );
	}

	/**
	 * Happy-path through the ARRAY-of-callables provider for pay_with_credit WITHOUT an
	 * order_id (the context stays empty) and where the provider's result omits 'paid'
	 *, the tool then defaults paid to true in its success envelope. Complements the
	 * order_id case above by covering the no-order_id branch of the same method.
	 */
	public function test_pay_with_credit_defaults_paid_true_when_provider_omits_it(): void {
		$received_context = 'unset';
		$this->provide( [
			'get_balance'     => fn( $user_id ) => [ 'amount' => 50.0, 'currency' => 'USD', 'formatted' => '$50.00' ],
			'pay_with_credit' => function ( $user_id, $amount, $context ) use ( &$received_context ) {
				$received_context = $context;
				// No 'paid' key in the provider's confirmed result.
				return [ 'amount' => 20.0, 'currency' => 'USD', 'formatted' => '$20.00' ];
			},
		] );

		$result = $this->registry()->dispatch( 'pay_with_credit', [ 'amount' => 30 ] );

		$this->assertArrayNotHasKey( 'error', $result );
		// No order_id supplied → empty context handed to the provider.
		$this->assertSame( [], $received_context );
		$this->assertSame( 20.0, $result['balance']['amount'] );
		// Tool defaults paid to true when the provider confirms a result without 'paid'.
		$this->assertTrue( $result['paid'] );
	}

	// ── file-scope self-registration (413) ──────────────────────────────────────

	/**
	 * The pack file self-registers via Fahad_AI_Tool_Registry::register_pack() at file
	 * scope (line 413) the moment it is require'd, the only wiring needed. The bootstrap
	 * glob-requires includes/tools/*.php, so the class is loaded and its register()
	 * method is a valid, callable pack provider. Asserting that proves the file-scope
	 * registration call references a real, invokable provider.
	 */
	public function test_pack_self_registration_references_a_callable_register(): void {
		$this->assertTrue( class_exists( 'Fahad_AI_Wallet_Tools' ) );
		$this->assertTrue( is_callable( [ 'Fahad_AI_Wallet_Tools', 'register' ] ) );

		// And register() really appends the three wallet tools onto a tool list, the
		// exact contract the file-scope register_pack() wires up.
		$tools = Fahad_AI_Wallet_Tools::register( [] );
		$names = array_column( $tools, 'name' );
		$this->assertSame( [ 'get_wallet_balance', 'top_up', 'pay_with_credit', 'get_referral_link' ], $names );
	}
}
