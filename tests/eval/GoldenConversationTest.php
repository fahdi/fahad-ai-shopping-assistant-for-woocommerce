<?php
/**
 * Golden-conversation eval tests.
 *
 * Each fixture in tests/eval/fixtures/*.php is one golden conversation. A data
 * provider feeds every fixture into a single test so PHPUnit reports per-case
 * pass/fail (the case name is the data-set key). The test drives the REAL agent
 * loop through EvalHarness against a scripted LLM transport + real tool
 * execution, then checks the recorded tool calls, the surfaced cards, the final
 * answer, and grounding.
 *
 * This file also contains the grounding-checker SELF-TESTS (clearly labelled):
 * a positive case (grounded answer passes) and a NEGATIVE case (an answer that
 * fabricates a price must FAIL the checker), proving the checker works.
 *
 * Conventions match the unit suite: Brain\Monkey setUp/tearDown, Mockery,
 * reflection to reset singletons between cases.
 */

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;

final class GoldenConversationTest extends TestCase {

	use MockeryPHPUnitIntegration;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		EvalHarness::reset_singletons();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * Discover every fixture file and key it by fixture name so failures read as
	 * "GoldenConversationTest::test_golden_conversation with data set 'add-to-cart'".
	 *
	 * @return array<string, array{0: array}>
	 */
	public static function fixtureProvider(): array {
		$cases = [];
		foreach ( glob( __DIR__ . '/fixtures/*.php' ) as $file ) {
			$fixture = require $file;
			$name    = $fixture['name'] ?? basename( $file, '.php' );
			$cases[ $name ] = [ $fixture ];
		}
		return $cases;
	}

	/**
	 * Run one golden conversation end-to-end and assert on the result.
	 *
	 * @dataProvider fixtureProvider
	 */
	public function test_golden_conversation( array $fixture ): void {
		$provider = $fixture['provider'] ?? 'anthropic';
		$expect   = $fixture['expect'] ?? [];

		// 1. Environment + scripted transport + WC data.
		EvalHarness::stub_environment( [ 'fahad_ai_provider' => $provider ] );
		EvalHarness::stub_woocommerce( $fixture['wc'] ?? [] );
		EvalHarness::script_transport( $fixture['script'] ?? [] );

		// Feature tool packs need NO per-test wiring here: each pack
		// (e.g. the catalog pack providing get_top_products) self-registers via
		// Fahad_AI_Tool_Registry::register_pack() when the test bootstrap
		// glob-loads includes/tools/*.php, and those static providers survive the
		// per-case singleton reset in reset_singletons(). So every golden
		// conversation can exercise pack tools exactly as in production, with
		// built-ins and the third-party filter still flowing through unchanged.

		// 2. Drive the real agent loop.
		$run = EvalHarness::run( $provider, $fixture['messages'] ?? [] );

		// The loop must complete (not a WP_Error) for any fixture we assert on.
		$this->assertFalse(
			is_wp_error( $run['result'] ),
			sprintf(
				'[%s] agent loop returned WP_Error: %s',
				$fixture['name'],
				is_wp_error( $run['result'] ) ? $run['result']->get_error_message() : ''
			)
		);

		// 3. Tool-call trace (names, exact order).
		if ( array_key_exists( 'tool_calls', $expect ) ) {
			$actual_names = array_map( static fn( $c ) => $c['name'], $run['tool_calls'] );
			$this->assertSame(
				$expect['tool_calls'],
				$actual_names,
				sprintf( '[%s] tool-call sequence mismatch', $fixture['name'] )
			);
		}

		// 3b. Optional per-call input assertions: [ index => [ key => value ] ].
		foreach ( $expect['tool_inputs'] ?? [] as $idx => $expected_input ) {
			$this->assertArrayHasKey( $idx, $run['tool_calls'], "[{$fixture['name']}] expected a tool call at index {$idx}" );
			foreach ( $expected_input as $key => $value ) {
				$this->assertSame(
					$value,
					$run['tool_calls'][ $idx ]['input'][ $key ] ?? null,
					sprintf( '[%s] tool call #%d input "%s" mismatch', $fixture['name'], $idx, $key )
				);
			}
		}

		// 4. Card assertions.
		if ( array_key_exists( 'min_cards', $expect ) ) {
			$this->assertGreaterThanOrEqual(
				$expect['min_cards'],
				count( $run['products'] ),
				sprintf( '[%s] expected at least %d cards', $fixture['name'], $expect['min_cards'] )
			);
		}
		if ( array_key_exists( 'max_cards', $expect ) ) {
			$this->assertLessThanOrEqual(
				$expect['max_cards'],
				count( $run['products'] ),
				sprintf( '[%s] expected at most %d cards', $fixture['name'], $expect['max_cards'] )
			);
		}

		// 5. Final answer assertions.
		if ( ! empty( $expect['answer_not_empty'] ) ) {
			$this->assertNotSame( '', trim( $run['answer'] ), sprintf( '[%s] answer is empty', $fixture['name'] ) );
		}
		if ( isset( $expect['answer_matches'] ) ) {
			$this->assertMatchesRegularExpression(
				$expect['answer_matches'],
				$run['answer'],
				sprintf( '[%s] answer did not match required pattern', $fixture['name'] )
			);
		}
		if ( isset( $expect['answer_contains'] ) ) {
			foreach ( (array) $expect['answer_contains'] as $needle ) {
				$this->assertStringContainsString(
					$needle,
					$run['answer'],
					sprintf( '[%s] answer missing expected substring', $fixture['name'] )
				);
			}
		}

		// 6. Grounding (anti-hallucination).
		if ( ! empty( $expect['grounded'] ) ) {
			$violations = EvalHarness::grounding_violations( $run['answer'], $run['tool_results'] );
			$this->assertSame(
				[],
				$violations,
				sprintf( "[%s] answer is not grounded:\n - %s", $fixture['name'], implode( "\n - ", $violations ) )
			);
		}
	}

	// =========================================================================
	// Tool-extensibility ACCEPTANCE (end-to-end through the REAL loop)
	// =========================================================================

	/**
	 * A third-party tool registered via the `fahad_ai_register_tools` filter is
	 * advertised to the model AND invoked by the REAL agent loop when the model
	 * calls it — proving an add-on can extend the agent without forking core.
	 *
	 * This is the eval-level analogue of ToolRegistryTest's unit extension test:
	 * the unit test exercises the registry in isolation; here the custom tool runs
	 * through Fahad_AI_API_Handler::run_anthropic_agent() against the scripted
	 * transport, exactly like a built-in tool.
	 *
	 * A declarative fixture file cannot install the apply_filters stub itself, so
	 * this lives as a dedicated test (the shared fixture runner is left untouched).
	 */
	public function test_filter_registered_tool_runs_end_to_end(): void {
		$invoked_with = null;

		// Register a custom "track_order" tool via the public extension filter.
		Functions\when( 'apply_filters' )->alias(
			function ( $hook, $value = null ) use ( &$invoked_with ) {
				if ( 'fahad_ai_register_tools' === $hook && is_array( $value ) ) {
					$value[] = [
						'name'        => 'track_order',
						'description' => 'Look up the delivery status of an order by its ID.',
						'parameters'  => [
							'type'       => 'object',
							'properties' => [
								'order_id' => [ 'type' => 'integer', 'description' => 'The order ID' ],
							],
							'required'   => [ 'order_id' ],
						],
						'callback'    => function ( array $input ) use ( &$invoked_with ): array {
							$invoked_with = $input;
							return [ 'order_id' => $input['order_id'] ?? 0, 'status' => 'Shipped' ];
						},
					];
				}
				return $value;
			}
		);

		EvalHarness::stub_environment( [ 'fahad_ai_provider' => 'anthropic' ] );
		EvalHarness::stub_woocommerce( [] );
		EvalHarness::script_transport( [
			// Turn 1: the model calls the third-party tool.
			EvalHarness::anthropic_tool_turn( [
				[ 'name' => 'track_order', 'input' => [ 'order_id' => 7 ] ],
			] ),
			// Turn 2: final answer grounded in the tool result.
			EvalHarness::anthropic_text_turn( 'Your order #7 has Shipped.' ),
		] );

		$run = EvalHarness::run( 'anthropic', [
			[ 'role' => 'user', 'content' => 'where is my order 7?' ],
		] );

		// The loop completed and actually invoked the custom callback.
		$this->assertFalse( is_wp_error( $run['result'] ) );
		$this->assertSame( [ 'order_id' => 7 ], $invoked_with, 'custom tool callback was not invoked by the real loop' );

		// The loop recorded the custom tool call + its result in the transcript.
		$names = array_map( static fn( $c ) => $c['name'], $run['tool_calls'] );
		$this->assertSame( [ 'track_order' ], $names );
		$this->assertSame( 'Shipped', $run['tool_results'][0]['status'] );

		// And the model's answer reflects the tool result.
		$this->assertStringContainsString( 'Shipped', $run['answer'] );
	}

	// =========================================================================
	// Privacy / auth BOUNDARY — guest-block END-TO-END (issue #25)
	// =========================================================================

	/**
	 * A personal-data tool (declaring `'personal' => true`) registered via the
	 * extension filter must block a GUEST through the REAL agent loop: the central
	 * login gate in Fahad_AI_Tool_Registry::dispatch() returns the login-required
	 * error WITHOUT invoking the tool's callback, that error flows back to the
	 * model as the tool_result, and the model's scripted answer escalates the user
	 * to log in (a grounded "abstain"/escalate — no personal data is fabricated).
	 *
	 * This is the eval-level analogue of the ToolRegistryTest guest-block unit
	 * test: there the registry is exercised in isolation; here the gate runs inside
	 * Fahad_AI_API_Handler::run_anthropic_agent() against the scripted transport,
	 * exactly as it would for a real guest request. A declarative fixture cannot
	 * install the apply_filters / is_user_logged_in stubs itself, so — like the
	 * #22 filter test — this lives as a dedicated test.
	 */
	public function test_personal_tool_blocks_guest_end_to_end(): void {
		$callback_invoked = false;

		// Guest: not logged in. The boundary must stop the personal tool here.
		Functions\when( 'is_user_logged_in' )->justReturn( false );
		Functions\when( 'get_current_user_id' )->justReturn( 0 );

		// Register a personal "order_status" tool via the public extension filter.
		Functions\when( 'apply_filters' )->alias(
			function ( $hook, $value = null ) use ( &$callback_invoked ) {
				if ( 'fahad_ai_register_tools' === $hook && is_array( $value ) ) {
					$value[] = [
						'name'        => 'order_status',
						'description' => 'Look up the status of the logged-in customer\'s order.',
						'parameters'  => [
							'type'       => 'object',
							'properties' => [
								'order_id' => [ 'type' => 'integer', 'description' => 'The order ID' ],
							],
						],
						'personal'    => true,
						'callback'    => function ( array $input ) use ( &$callback_invoked ): array {
							// Must NEVER run for a guest — proves the gate is central,
							// not something this tool checks for itself.
							$callback_invoked = true;
							return [ 'order_id' => $input['order_id'] ?? 0, 'status' => 'Shipped' ];
						},
					];
				}
				return $value;
			}
		);

		EvalHarness::stub_environment( [ 'fahad_ai_provider' => 'anthropic' ] );
		EvalHarness::stub_woocommerce( [] );
		EvalHarness::script_transport( [
			// Turn 1: the model tries the personal tool.
			EvalHarness::anthropic_tool_turn( [
				[ 'name' => 'order_status', 'input' => [ 'order_id' => 7 ] ],
			] ),
			// Turn 2: seeing the login-required tool_result, the model escalates.
			EvalHarness::anthropic_text_turn(
				'You will need to log in to your account so I can check that order for you.'
			),
		] );

		$run = EvalHarness::run( 'anthropic', [
			[ 'role' => 'user', 'content' => 'where is my order 7?' ],
		] );

		// The loop completed and the guest NEVER reached the personal callback.
		$this->assertFalse( is_wp_error( $run['result'] ) );
		$this->assertFalse( $callback_invoked, 'guest reached a personal tool callback through the real loop' );

		// The tool "ran" (was dispatched) but returned the login-required error.
		$names = array_map( static fn( $c ) => $c['name'], $run['tool_calls'] );
		$this->assertSame( [ 'order_status' ], $names );
		$this->assertTrue( $run['tool_results'][0]['requires_login'] ?? false );

		// No personal data leaked into the tool result (no "Shipped" status).
		$this->assertArrayNotHasKey( 'status', $run['tool_results'][0] );

		// The model's answer steers the user to log in (grounded escalate).
		$this->assertMatchesRegularExpression( '/\blog ?in\b/i', $run['answer'] );
	}

	// =========================================================================
	// Grounding-checker SELF-TESTS (prove the checker actually works)
	// =========================================================================

	/** SELF-TEST (positive): an answer using only real tool-result data is grounded. */
	public function test_grounding_self_test_passes_for_grounded_answer(): void {
		$tool_results = [
			[
				'found'    => 1,
				'products' => [
					[ 'id' => 1, 'name' => 'Trail Runner', 'price' => '$79.99', 'in_stock' => true ],
				],
			],
		];

		// References the real product name and its real price → grounded.
		$answer = 'The "Trail Runner" is a solid pick at $79.99.';

		$this->assertSame( [], EvalHarness::grounding_violations( $answer, $tool_results ) );
		$this->assertTrue( EvalHarness::is_grounded( $answer, $tool_results ) );
	}

	/**
	 * SELF-TEST (NEGATIVE): an answer that FABRICATES a price the tools never
	 * returned MUST be flagged. This proves the grounding checker can fail —
	 * a checker that always passes would be worthless.
	 */
	public function test_grounding_self_test_fails_for_fabricated_price(): void {
		$tool_results = [
			[
				'found'    => 1,
				'products' => [
					[ 'id' => 1, 'name' => 'Trail Runner', 'price' => '$79.99', 'in_stock' => true ],
				],
			],
		];

		// $129.99 was NEVER in any tool result → hallucinated price.
		$answer = 'Great news — the Trail Runner is on sale for just $129.99 today!';

		$violations = EvalHarness::grounding_violations( $answer, $tool_results );
		$this->assertNotEmpty( $violations, 'Grounding checker failed to flag a fabricated price.' );
		$this->assertFalse( EvalHarness::is_grounded( $answer, $tool_results ) );
		// And the violation should name the offending token.
		$this->assertStringContainsString( '129.99', implode( ' ', $violations ) );
	}

	/**
	 * SELF-TEST (NEGATIVE): an answer that names a product NOT present in any
	 * tool result is flagged as a fabricated product reference.
	 */
	public function test_grounding_self_test_fails_for_fabricated_product_name(): void {
		$tool_results = [
			[ 'found' => 1, 'products' => [ [ 'id' => 1, 'name' => 'Trail Runner', 'price' => '$79.99' ] ] ],
		];

		// The store never returned a "Quantum Widget 9000".
		$answer = 'You should check out the "Quantum Widget 9000" — it is perfect for you.';

		$violations = EvalHarness::grounding_violations( $answer, $tool_results );
		$this->assertNotEmpty( $violations, 'Grounding checker failed to flag a fabricated product name.' );
		$this->assertStringContainsString( 'Quantum Widget 9000', implode( ' ', $violations ) );
	}
}
