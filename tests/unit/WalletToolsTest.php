<?php
/**
 * Unit tests for Fahad_AI_Wallet_Tools (issue #18: wallet-aware shopping, the
 * differentiator, MONEY-sensitive).
 *
 * Red → Green → Refactor. Conventions mirror OrderToolsTest (the other personal
 * pack): WP/WC functions mocked via Brain\Monkey; the wallet PROVIDER injected as
 * a Mockery mock through the `fahad_ai_wallet_provider` filter; the registry
 * singleton + its static pack list snapshotted/restored so a case here neither
 * inherits another suite's packs nor leaks the wallet pack we register.
 *
 * The three wallet tools (get_wallet_balance, top_up, pay_with_credit) are NOT
 * built-ins, they ship as a drop-in feature pack that self-registers via
 * Fahad_AI_Tool_Registry::register_pack() at file load and declare
 * `'personal' => true`. Every test registers the pack's REAL provider, then
 * dispatches through Fahad_AI_Tool_Registry::instance()->dispatch(), so the
 * production registration + merge + dispatch path (INCLUDING the central login
 * gate for `personal` tools) is what is under test.
 *
 * DECOUPLING. The assistant core has NO dependency on any wallet plugin. The
 * tools resolve a provider at runtime via apply_filters( 'fahad_ai_wallet_provider',
 * null ). With no provider registered the tools degrade gracefully (never fatal,
 * never invent a balance). The wallet plugin (WalletPro / Account Funds) is the
 * thing that registers the provider, these tests stand in for it with a mock.
 *
 * MONEY-SAFETY IS THE POINT. The highest-severity tests are first-class:
 *   - GUEST-BLOCK: a guest dispatching any wallet tool is stopped centrally by the
 *     registry's login gate BEFORE the callback runs, the provider is NEVER touched.
 *   - NO DOUBLE-SPEND: pay_with_credit with insufficient balance returns an error and
 *     the provider's pay_with_credit is NEVER called (no debit attempt).
 *   - NO SUCCESS WITHOUT CONFIRMATION: a provider failure surfaces an error; the tool
 *     never reports a success it cannot confirm (no partial state).
 *   - AMOUNT VALIDATION: non-positive / non-numeric amounts are rejected BEFORE the
 *     provider is touched (top_up / pay_with_credit money mutators never called).
 *   - CURRENT-USER-ONLY: balance/top-up/pay always act on the current user id; a user
 *     id supplied in the model input is ignored.
 */

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;

class WalletToolsTest extends TestCase {

    use MockeryPHPUnitIntegration;

    /**
     * Snapshot of the registry's static pack providers, restored in tearDown so a
     * test here neither inherits another suite's packs nor leaks the wallet pack we
     * register. (Pack providers are static so they survive a singleton instance
     * reset, see Fahad_AI_Tool_Registry::register_pack.)
     *
     * @var array<int, callable>
     */
    private array $pack_snapshot = [];

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();

        $this->pack_snapshot = (array) ( new ReflectionProperty( Fahad_AI_Tool_Registry::class, 'pack_providers' ) )->getValue();

        // Default to a logged-in customer (id 5). Guest cases override this.
        Functions\when( 'is_user_logged_in' )->justReturn( true );
        Functions\when( 'get_current_user_id' )->justReturn( 5 );

        // Registry get_tools() reads the merchant tool-gating option (issue #56);
        // default (no disabled tools) so dispatch()/specs() are unaffected.
        Functions\when( 'get_option' )->alias( fn( $key, $default = '' ) => $default );
    }

    protected function tearDown(): void {
        ( new ReflectionProperty( Fahad_AI_Tool_Registry::class, 'pack_providers' ) )->setValue( null, $this->pack_snapshot );
        Monkey\tearDown();
        parent::tearDown();
    }

    /**
     * Fresh registry whose built tool list includes the wallet tools.
     *
     * Resets the Tools + registry singletons, then registers the wallet pack's REAL
     * provider via register_pack(), exactly what the pack's file-scope
     * self-registration does in production. Registering it explicitly (after
     * clearing the static list) keeps the test hermetic and order-independent.
     */
    private function registry(): Fahad_AI_Tool_Registry {
        ( new ReflectionProperty( Fahad_AI_Tools::class, 'instance' ) )->setValue( null, null );
        ( new ReflectionProperty( Fahad_AI_Tool_Registry::class, 'instance' ) )->setValue( null, null );

        Fahad_AI_Tool_Registry::reset_packs();
        Fahad_AI_Tool_Registry::register_pack( [ 'Fahad_AI_Wallet_Tools', 'register' ] );

        return Fahad_AI_Tool_Registry::instance();
    }

    /**
     * Register a mock wallet provider on the `fahad_ai_wallet_provider` filter for
     * the duration of the test. The provider is the seam that keeps the assistant
     * core decoupled from the wallet plugin: the wallet plugin would return its own
     * adapter here; we return a Mockery mock so each test controls the money ops.
     *
     * @return \Mockery\MockInterface The provider mock (set expectations on it).
     */
    private function withProvider(): \Mockery\MockInterface {
        $provider = Mockery::mock( 'Fahad_AI_Wallet_Provider_Stub' );
        Functions\when( 'apply_filters' )->alias(
            static function ( $hook, $value = null ) use ( $provider ) {
                return ( 'fahad_ai_wallet_provider' === $hook ) ? $provider : $value;
            }
        );
        return $provider;
    }

    /**
     * No provider registered: the filter returns its passed-through default (null),
     * exactly as WordPress would with no `fahad_ai_wallet_provider` callback added.
     */
    private function withNoProvider(): void {
        Functions\when( 'apply_filters' )->alias(
            static fn( $hook, $value = null ) => $value
        );
    }

    // ── registration ──────────────────────────────────────────────────────────

    public function test_wallet_tools_are_registered_via_register_pack(): void {
        $names = array_column( $this->registry()->specs(), 'name' );

        $this->assertContains( 'get_wallet_balance', $names );
        $this->assertContains( 'top_up', $names );
        $this->assertContains( 'pay_with_credit', $names );
        // Additive: the six built-ins remain.
        $this->assertContains( 'search_products', $names );
    }

    public function test_wallet_tool_specs_never_leak_a_callback_or_personal_flag(): void {
        $specs = array_column( $this->registry()->specs(), null, 'name' );

        foreach ( [ 'get_wallet_balance', 'top_up', 'pay_with_credit' ] as $name ) {
            $this->assertArrayHasKey( $name, $specs );
            $this->assertArrayNotHasKey( 'callback', $specs[ $name ] );
            // The `personal` flag is an internal authorization detail; never advertised.
            $this->assertArrayNotHasKey( 'personal', $specs[ $name ] );
            $this->assertArrayHasKey( 'description', $specs[ $name ] );
            $this->assertSame( 'object', $specs[ $name ]['parameters']['type'] );
            $this->assertArrayHasKey( 'properties', $specs[ $name ]['parameters'] );
        }
    }

    // ── get_wallet_balance (happy path) ─────────────────────────────────────────

    public function test_get_wallet_balance_returns_the_providers_balance_for_current_user(): void {
        $provider = $this->withProvider();
        // The tool MUST ask the provider for the CURRENT user (id 5), never a model id.
        $provider->shouldReceive( 'get_balance' )
            ->once()
            ->with( 5 )
            ->andReturn( [ 'amount' => 42.5, 'currency' => 'USD', 'formatted' => '$42.50' ] );

        $result = $this->registry()->dispatch( 'get_wallet_balance', [] );

        $this->assertArrayNotHasKey( 'error', $result );
        $this->assertSame( 42.5, $result['amount'] );
        $this->assertSame( 'USD', $result['currency'] );
        $this->assertSame( '$42.50', $result['formatted'] );
    }

    public function test_get_wallet_balance_uses_current_user_not_model_supplied_id(): void {
        // A malicious/confused model passes someone else's user_id. It MUST be ignored
        //, the provider is always asked about the authenticated current user (5).
        $provider = $this->withProvider();
        $provider->shouldReceive( 'get_balance' )
            ->once()
            ->with( 5 )
            ->andReturn( [ 'amount' => 0.0, 'currency' => 'USD', 'formatted' => '$0.00' ] );

        $this->registry()->dispatch( 'get_wallet_balance', [ 'user_id' => 9999 ] );
    }

    public function test_get_wallet_balance_without_provider_degrades_gracefully(): void {
        $this->withNoProvider();

        $result = $this->registry()->dispatch( 'get_wallet_balance', [] );

        $this->assertArrayHasKey( 'error', $result );
        $this->assertStringContainsString( 'not available', strtolower( $result['error'] ) );
        // Never invent a balance.
        $this->assertArrayNotHasKey( 'amount', $result );
        $this->assertArrayNotHasKey( 'formatted', $result );
    }

    // ── get_referral_link (Epic A / #143) ───────────────────────────────────────

    public function test_get_referral_link_returns_provider_referral_info_for_current_user(): void {
        $provider = $this->withProvider();
        $provider->shouldReceive( 'referral_info' )
            ->once()
            ->with( 5 )
            ->andReturn( [ 'enabled' => true, 'code' => 'ABC123', 'url' => 'https://shop.test/?wpref=ABC123', 'referrer_reward' => '$10.00', 'referee_reward' => '$5.00' ] );

        $result = $this->registry()->dispatch( 'get_referral_link', [] );

        $this->assertArrayNotHasKey( 'error', $result );
        $this->assertTrue( $result['enabled'] );
        $this->assertSame( 'ABC123', $result['code'] );
        $this->assertSame( 'https://shop.test/?wpref=ABC123', $result['url'] );
    }

    public function test_get_referral_link_uses_current_user_not_model_supplied_id(): void {
        $provider = $this->withProvider();
        $provider->shouldReceive( 'referral_info' )
            ->once()
            ->with( 5 )
            ->andReturn( [ 'enabled' => false ] );

        $this->registry()->dispatch( 'get_referral_link', [ 'user_id' => 9999 ] );
    }

    public function test_get_referral_link_without_provider_degrades_gracefully(): void {
        $this->withNoProvider();

        $result = $this->registry()->dispatch( 'get_referral_link', [] );

        $this->assertArrayHasKey( 'error', $result );
        $this->assertStringContainsString( 'not available', strtolower( $result['error'] ) );
        $this->assertArrayNotHasKey( 'code', $result );
    }

    public function test_get_referral_link_degrades_when_op_unsupported_returns_null(): void {
        // A provider that does not implement referral_info: self::call() isolates the
        // failure to null, and the tool degrades gracefully (no invented referral).
        $provider = $this->withProvider();
        $provider->shouldReceive( 'referral_info' )->andThrow( new \BadMethodCallException() );

        $result = $this->registry()->dispatch( 'get_referral_link', [] );

        $this->assertArrayHasKey( 'error', $result );
    }

    public function test_get_referral_link_degrades_when_provider_returns_non_array(): void {
        $provider = $this->withProvider();
        $provider->shouldReceive( 'referral_info' )->once()->with( 5 )->andReturn( 'nope' );

        $result = $this->registry()->dispatch( 'get_referral_link', [] );

        $this->assertArrayHasKey( 'error', $result );
    }

    // ── top_up (surfaces deposit bonus; validates amount) ───────────────────────

    public function test_top_up_surfaces_deposit_bonus_and_reports_new_balance(): void {
        $provider = $this->withProvider();
        // Bonus is surfaced honestly so the model can mention it.
        $provider->shouldReceive( 'get_deposit_bonus' )
            ->once()
            ->with( 100.0 )
            ->andReturn( [ 'amount' => 10.0, 'currency' => 'USD', 'formatted' => '$10.00' ] );
        $provider->shouldReceive( 'top_up' )
            ->once()
            ->with( 5, 100.0 )
            ->andReturn( [ 'amount' => 152.5, 'currency' => 'USD', 'formatted' => '$152.50' ] );

        $result = $this->registry()->dispatch( 'top_up', [ 'amount' => 100 ] );

        $this->assertArrayNotHasKey( 'error', $result );
        // New balance from the provider.
        $this->assertSame( 152.5, $result['balance']['amount'] );
        // Bonus surfaced for honest mention.
        $this->assertArrayHasKey( 'deposit_bonus', $result );
        $this->assertSame( 10.0, $result['deposit_bonus']['amount'] );
    }

    public function test_top_up_handles_no_bonus_gracefully(): void {
        $provider = $this->withProvider();
        $provider->shouldReceive( 'get_deposit_bonus' )->once()->with( 50.0 )->andReturn( null );
        $provider->shouldReceive( 'top_up' )->once()->with( 5, 50.0 )
            ->andReturn( [ 'amount' => 50.0, 'currency' => 'USD', 'formatted' => '$50.00' ] );

        $result = $this->registry()->dispatch( 'top_up', [ 'amount' => 50 ] );

        $this->assertArrayNotHasKey( 'error', $result );
        $this->assertSame( 50.0, $result['balance']['amount'] );
        // No bonus key (or null) when the provider offers none, never fabricated.
        $this->assertTrue( ! array_key_exists( 'deposit_bonus', $result ) || null === $result['deposit_bonus'] );
    }

    /**
     * AMOUNT VALIDATION (money-safety). A non-positive / non-numeric amount must be
     * rejected BEFORE the provider is touched: neither get_deposit_bonus NOR the
     * money-mutating top_up may be called. never() turns any such call into a hard
     * failure, proving validation guards the provider.
     *
     * @dataProvider invalidAmountProvider
     */
    public function test_top_up_rejects_invalid_amount_before_calling_provider( $amount ): void {
        $provider = $this->withProvider();
        $provider->shouldReceive( 'get_deposit_bonus' )->never();
        $provider->shouldReceive( 'top_up' )->never();

        $result = $this->registry()->dispatch( 'top_up', [ 'amount' => $amount ] );

        $this->assertArrayHasKey( 'error', $result );
        $this->assertArrayNotHasKey( 'balance', $result );
    }

    public function test_top_up_without_provider_degrades_gracefully(): void {
        $this->withNoProvider();

        $result = $this->registry()->dispatch( 'top_up', [ 'amount' => 100 ] );

        $this->assertArrayHasKey( 'error', $result );
        $this->assertStringContainsString( 'not available', strtolower( $result['error'] ) );
        $this->assertArrayNotHasKey( 'balance', $result );
    }

    public function test_top_up_surfaces_provider_failure_without_reporting_success(): void {
        // The provider's atomic top_up failed. The tool must surface that, never a success.
        $provider = $this->withProvider();
        $provider->shouldReceive( 'get_deposit_bonus' )->andReturn( null );
        $provider->shouldReceive( 'top_up' )->once()->with( 5, 100.0 )
            ->andReturn( [ 'error' => 'Gateway declined the deposit.' ] );

        $result = $this->registry()->dispatch( 'top_up', [ 'amount' => 100 ] );

        $this->assertArrayHasKey( 'error', $result );
        $this->assertArrayNotHasKey( 'balance', $result );
    }

    // ── pay_with_credit (sufficient-balance gate; no double-spend) ──────────────

    public function test_pay_with_credit_debits_via_provider_on_sufficient_balance(): void {
        $provider = $this->withProvider();
        $provider->shouldReceive( 'get_balance' )->once()->with( 5 )
            ->andReturn( [ 'amount' => 100.0, 'currency' => 'USD', 'formatted' => '$100.00' ] );
        // Single atomic debit delegated to the provider.
        $provider->shouldReceive( 'pay_with_credit' )->once()->with( 5, 30.0, Mockery::type( 'array' ) )
            ->andReturn( [ 'amount' => 70.0, 'currency' => 'USD', 'formatted' => '$70.00', 'paid' => true ] );

        $result = $this->registry()->dispatch( 'pay_with_credit', [ 'amount' => 30 ] );

        $this->assertArrayNotHasKey( 'error', $result );
        $this->assertSame( 70.0, $result['balance']['amount'] );
        $this->assertTrue( $result['paid'] );
    }

    /**
     * THE headline money-safety test: NO DOUBLE-SPEND.
     *
     * Balance is 10.00; the customer tries to pay 30.00. The tool must check the
     * balance FIRST, return an "insufficient" error, and NEVER call the provider's
     * pay_with_credit, there must be NO debit attempt at all. never() turns a debit
     * call into a hard failure. Without the sufficient-balance gate this fails: the
     * tool would call pay_with_credit and risk a debit the customer cannot cover.
     */
    public function test_pay_with_credit_blocks_insufficient_balance_without_debiting(): void {
        $provider = $this->withProvider();
        $provider->shouldReceive( 'get_balance' )->once()->with( 5 )
            ->andReturn( [ 'amount' => 10.0, 'currency' => 'USD', 'formatted' => '$10.00' ] );
        // CRITICAL: the debit must never even be attempted.
        $provider->shouldReceive( 'pay_with_credit' )->never();

        $result = $this->registry()->dispatch( 'pay_with_credit', [ 'amount' => 30 ] );

        $this->assertArrayHasKey( 'error', $result );
        $this->assertStringContainsString( 'insufficient', strtolower( $result['error'] ) );
        // No success of any kind reported.
        $this->assertArrayNotHasKey( 'paid', $result );
        $this->assertArrayNotHasKey( 'balance', $result );
    }

    /**
     * AMOUNT VALIDATION for pay: a non-positive / non-numeric amount is rejected
     * BEFORE touching the provider, neither the balance read NOR the debit run.
     *
     * @dataProvider invalidAmountProvider
     */
    public function test_pay_with_credit_rejects_invalid_amount_before_calling_provider( $amount ): void {
        $provider = $this->withProvider();
        $provider->shouldReceive( 'get_balance' )->never();
        $provider->shouldReceive( 'pay_with_credit' )->never();

        $result = $this->registry()->dispatch( 'pay_with_credit', [ 'amount' => $amount ] );

        $this->assertArrayHasKey( 'error', $result );
        $this->assertArrayNotHasKey( 'paid', $result );
    }

    /**
     * NO SUCCESS WITHOUT CONFIRMATION. Balance is sufficient, but the provider's
     * atomic debit fails (returns an error). The tool must surface that error and
     * NOT report a paid/success, there is no partial state on the assistant side.
     */
    public function test_pay_with_credit_surfaces_provider_failure_without_reporting_success(): void {
        $provider = $this->withProvider();
        $provider->shouldReceive( 'get_balance' )->once()->with( 5 )
            ->andReturn( [ 'amount' => 100.0, 'currency' => 'USD', 'formatted' => '$100.00' ] );
        $provider->shouldReceive( 'pay_with_credit' )->once()
            ->andReturn( [ 'error' => 'Ledger write failed.' ] );

        $result = $this->registry()->dispatch( 'pay_with_credit', [ 'amount' => 30 ] );

        $this->assertArrayHasKey( 'error', $result );
        $this->assertArrayNotHasKey( 'paid', $result );
        $this->assertArrayNotHasKey( 'balance', $result );
    }

    public function test_pay_with_credit_uses_current_user_not_model_supplied_id(): void {
        // Model supplies someone else's user_id; it MUST be ignored, balance read and
        // debit both target the authenticated current user (5).
        $provider = $this->withProvider();
        $provider->shouldReceive( 'get_balance' )->once()->with( 5 )
            ->andReturn( [ 'amount' => 100.0, 'currency' => 'USD', 'formatted' => '$100.00' ] );
        $provider->shouldReceive( 'pay_with_credit' )->once()->with( 5, 30.0, Mockery::type( 'array' ) )
            ->andReturn( [ 'amount' => 70.0, 'currency' => 'USD', 'formatted' => '$70.00', 'paid' => true ] );

        $this->registry()->dispatch( 'pay_with_credit', [ 'amount' => 30, 'user_id' => 9999 ] );
    }

    public function test_pay_with_credit_without_provider_degrades_gracefully(): void {
        $this->withNoProvider();

        $result = $this->registry()->dispatch( 'pay_with_credit', [ 'amount' => 30 ] );

        $this->assertArrayHasKey( 'error', $result );
        $this->assertStringContainsString( 'not available', strtolower( $result['error'] ) );
        $this->assertArrayNotHasKey( 'paid', $result );
    }

    /** Non-positive and non-numeric amounts that must all be rejected pre-provider. */
    public static function invalidAmountProvider(): array {
        return [
            'zero'        => [ 0 ],
            'negative'    => [ -5 ],
            'negative_fl' => [ -0.01 ],
            'non_numeric' => [ 'abc' ],
            'empty'       => [ '' ],
            'null'        => [ null ],
        ];
    }

    // ── GUEST-BLOCK (central login gate, provider never touched) ────────────────

    /**
     * A guest dispatching ANY wallet tool must be stopped CENTRALLY by the registry's
     * login gate, before the tool callback runs. We assert the standard login-required
     * error AND, critically, that the wallet PROVIDER is NEVER resolved or touched,
     * proving the callback was never reached. is_user_logged_in() is stubbed false.
     * Mirrors OrderToolsTest's guest-block test.
     *
     * NB: apply_filters fires legitimately for the registry's OWN
     * `fahad_ai_register_tools` filter while it builds the tool list, so we cannot
     * assert apply_filters is never called at all. Instead we record every hook it is
     * applied with and assert the wallet PROVIDER hook was never among them, the seam
     * the callback would use to reach the provider was never touched. A provider mock
     * with all money ops set to never() backs this up: even if the seam were reached,
     * touching the provider would fail the test.
     *
     * @dataProvider walletToolProvider
     */
    public function test_guest_is_blocked_before_a_wallet_tool_callback_runs( string $tool, array $input ): void {
        Functions\when( 'is_user_logged_in' )->justReturn( false );
        Functions\when( 'get_current_user_id' )->justReturn( 0 );

        // A real provider whose every money op is forbidden, proves the callback never
        // reached the provider even if the seam somehow resolved one.
        $provider = Mockery::mock( 'Fahad_AI_Wallet_Provider_Stub' );
        $provider->shouldReceive( 'get_balance' )->never();
        $provider->shouldReceive( 'get_deposit_bonus' )->never();
        $provider->shouldReceive( 'top_up' )->never();
        $provider->shouldReceive( 'pay_with_credit' )->never();

        // Record the hooks apply_filters is called with. The wallet-provider hook is the
        // seam the tool callback uses; for a blocked guest it must never appear.
        $applied_hooks = [];
        Functions\when( 'apply_filters' )->alias(
            static function ( $hook, $value = null ) use ( &$applied_hooks, $provider ) {
                $applied_hooks[] = $hook;
                return ( 'fahad_ai_wallet_provider' === $hook ) ? $provider : $value;
            }
        );

        $result = $this->registry()->dispatch( $tool, $input );

        // The provider seam was NEVER reached, the gate stopped the guest first.
        $this->assertNotContains( 'fahad_ai_wallet_provider', $applied_hooks );

        $this->assertArrayHasKey( 'requires_login', $result );
        $this->assertTrue( $result['requires_login'] );
        $this->assertArrayHasKey( 'error', $result );
        $this->assertStringNotContainsString( 'Unknown tool', $result['error'] );
        // No wallet data of any kind in a guest result.
        $this->assertArrayNotHasKey( 'amount', $result );
        $this->assertArrayNotHasKey( 'balance', $result );
        $this->assertArrayNotHasKey( 'paid', $result );
    }

    /** Every wallet tool must be guest-gated. */
    public static function walletToolProvider(): array {
        return [
            'get_wallet_balance' => [ 'get_wallet_balance', [] ],
            'top_up'             => [ 'top_up', [ 'amount' => 100 ] ],
            'pay_with_credit'    => [ 'pay_with_credit', [ 'amount' => 30 ] ],
        ];
    }
}
