<?php
/**
 * Coverage top-up for Fahad_AI_Feedback (issue #50 store).
 *
 * The sibling FeedbackTest.php already exercises record() / aggregates() /
 * flag() / recent_down() / flagged() / retention end-to-end. It stubs
 * wp_generate_uuid4 in setUp, so new_id() always takes the UUID branch and the
 * md5 fallback (the `function_exists( 'wp_generate_uuid4' )` else arm) never
 * runs. This file deliberately does NOT stub wp_generate_uuid4: in the unit-test
 * runtime that WordPress function is undefined, so function_exists() returns
 * false and new_id() falls through to `md5( uniqid( '', true ) )`, executing the
 * otherwise-uncovered fallback line.
 *
 * Conventions mirror FeedbackTest / the Coverage* siblings: Brain\Monkey for the
 * WP option seam, the MockeryPHPUnitIntegration trait, and the singleton reset by
 * reflection between cases (never ReflectionMethod::setAccessible — the host runs
 * PHP 8.5).
 */

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;

class CoverageFeedbackTest extends TestCase {

	use MockeryPHPUnitIntegration;

	/**
	 * In-memory stand-in for the WP options table (option name => value), so the
	 * store's get_option / update_option calls round-trip and a test can assert
	 * exactly what was persisted.
	 *
	 * @var array<string, mixed>
	 */
	private array $options = [];

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$this->options = [];

		Functions\stubs( [
			'sanitize_text_field'     => fn( $s ) => is_string( $s ) ? trim( $s ) : '',
			'sanitize_textarea_field' => fn( $s ) => is_string( $s ) ? trim( $s ) : '',
			'sanitize_key'            => fn( $s ) => is_string( $s ) ? strtolower( trim( $s ) ) : '',
		] );

		// wp_generate_uuid4 is intentionally NOT stubbed when it is undefined —
		// leaving it absent forces new_id()'s md5 fallback branch (the coverage
		// target). But Brain\Monkey leaves a once-stubbed function defined in the
		// global namespace for the rest of the process, so if a prior test (e.g.
		// the sibling FeedbackTest) already defined it, calls would throw "not
		// defined nor mocked". Re-provide an expectation in that case so record()
		// works; the md5 branch is still covered by the separate-process test below.
		if ( function_exists( 'wp_generate_uuid4' ) ) {
			Functions\when( 'wp_generate_uuid4' )->alias(
				fn() => 'uuid-' . md5( uniqid( '', true ) )
			);
		}

		Functions\when( 'get_option' )->alias(
			fn( $name, $default = false ) => $this->options[ $name ] ?? $default
		);
		Functions\when( 'update_option' )->alias(
			function ( $name, $value ) {
				$this->options[ $name ] = $value;
				return true;
			}
		);
	}

	protected function tearDown(): void {
		( new ReflectionProperty( Fahad_AI_Feedback::class, 'instance' ) )->setValue( null, null );
		Monkey\tearDown();
		parent::tearDown();
	}

	/** Fresh store singleton (reset between cases via reflection). */
	private function store(): Fahad_AI_Feedback {
		( new ReflectionProperty( Fahad_AI_Feedback::class, 'instance' ) )->setValue( null, null );
		return Fahad_AI_Feedback::instance();
	}

	/** All stored feedback rows (the raw option value). */
	private function rows(): array {
		return $this->options[ Fahad_AI_Feedback::OPTION ] ?? [];
	}

	// ── new_id(): md5 fallback when wp_generate_uuid4 is unavailable ────────────

	/**
	 * new_id() falls back to `md5( uniqid( '', true ) )` when wp_generate_uuid4 is
	 * not available. The sibling FeedbackTest stubs wp_generate_uuid4, and a stubbed
	 * function stays defined in the global namespace for the rest of the process —
	 * so once that test has run, function_exists() is true and the fallback can no
	 * longer be reached in the same process. This case runs in its OWN process
	 * (preserveGlobalState disabled) so wp_generate_uuid4 is guaranteed undefined,
	 * forcing the md5 fallback regardless of which file ran first.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_record_uses_the_md5_id_fallback_when_uuid_helper_is_absent(): void {
		// Premise: in a fresh process wp_generate_uuid4 (a WP function) is undefined,
		// so new_id() must take the md5( uniqid() ) path.
		$this->assertFalse(
			function_exists( 'wp_generate_uuid4' ),
			'Premise: the md5 fallback only runs when wp_generate_uuid4 is undefined.'
		);

		$res = $this->store()->record( 'up', '', 'conv-1', 'msg-1' );

		$this->assertTrue( $res['ok'] ?? false );
		$id = $res['id'] ?? '';

		// md5() output: exactly 32 lowercase hex characters (the UUID branch would
		// contain dashes and be 36 chars), proving the fallback line executed.
		$this->assertMatchesRegularExpression( '/^[0-9a-f]{32}$/', $id, 'Fallback id must be a 32-char md5 hash.' );

		// And the row is keyed/persisted under that fallback id.
		$rows = $this->rows();
		$this->assertArrayHasKey( $id, $rows );
		$this->assertSame( $id, $rows[ $id ]['id'] );

		// uniqid( '', true ) carries extra entropy, so a second record gets a
		// distinct fallback id (no collision overwriting the first row).
		$second = $this->store()->record( 'down', '', 'conv-2', 'msg-2' )['id'];
		$this->assertNotSame( $id, $second, 'Each fallback id must be unique.' );
		$this->assertCount( 2, $this->rows(), 'Distinct ids must produce two stored rows.' );
	}

	public function test_cap_via_real_mb_substr_truncates_a_multibyte_reason(): void {
		// cap() runs through the mb_substr branch (mb_substr is a loaded builtin in
		// this runtime, so the function_exists guard is true). Feed a multibyte
		// reason longer than the cap and assert it is truncated to MAX_REASON_LENGTH
		// *characters* — and not chopped mid-character into invalid UTF-8.
		$reason = str_repeat( 'é', Fahad_AI_Feedback::MAX_REASON_LENGTH + 50 );

		$res = $this->store()->record( 'down', $reason, 'conv-1', 'msg-1' );
		$this->assertTrue( $res['ok'] ?? false );

		$stored = $this->rows()[ $res['id'] ]['reason'];

		$this->assertSame(
			Fahad_AI_Feedback::MAX_REASON_LENGTH,
			mb_strlen( $stored ),
			'A multibyte reason must be capped to MAX_REASON_LENGTH characters.'
		);
		$this->assertSame(
			$stored,
			mb_convert_encoding( $stored, 'UTF-8', 'UTF-8' ),
			'mb_substr must not chop a multibyte reason mid-character.'
		);
	}
}
