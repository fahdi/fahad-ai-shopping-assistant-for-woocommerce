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
