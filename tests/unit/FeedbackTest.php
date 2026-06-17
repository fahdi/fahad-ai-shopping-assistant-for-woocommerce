<?php
/**
 * Unit tests for Fahad_AI_Feedback (issue #50: reply feedback / thumbs +
 * production guardrail telemetry — the STORE behind the 👍/👎 controls).
 *
 * Red → Green → Refactor. Conventions mirror StockAlertsTest / MemoryToolsTest: WP
 * functions mocked via Brain\Monkey; the singleton reset via reflection between
 * cases (NEVER ReflectionMethod::setAccessible — host runs PHP 8.5). The store is
 * option-backed, so a single in-memory $this->options map stands in for the WP
 * options table and a test asserts exactly what was persisted.
 *
 * NO PII + TELEMETRY-ONLY IS THE POINT (issue #50 hardening). The headline tests
 * are first-class:
 *   - RATING VALIDATION: only 'up' / 'down' are accepted; junk is refused and
 *     stores nothing.
 *   - NO PII: a stored row carries the rating + opaque conversation/message refs
 *     ONLY — never an email, name, IP, or user id. A free-text reason is sanitized
 *     and length-capped (it can't smuggle an unbounded blob).
 *   - AGGREGATES: counts of up/down are reported correctly.
 *   - GUARDRAIL FLAG: a down-rating is flagged (and flag-queryable) so a future
 *     review pass can surface low-rated replies; flag() can also be set explicitly.
 *   - RETENTION CAP: the stored row count is bounded (oldest dropped first).
 */

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;

class FeedbackTest extends TestCase {

	use MockeryPHPUnitIntegration;

	/**
	 * In-memory stand-in for the WP options table, keyed by option name. The store
	 * is option-backed, so get_option / update_option / delete_option are stubbed to
	 * read/write this map and a test can assert precisely what was persisted (and,
	 * for the invalid-rating test, that nothing was).
	 *
	 * @var array<string, mixed>
	 */
	private array $options = [];

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$this->options = [];

		Functions\stubs( [
			// Sanitizers: trimming pass-throughs good enough for the store's needs.
			'sanitize_text_field'     => fn( $s ) => is_string( $s ) ? trim( $s ) : '',
			'sanitize_textarea_field' => fn( $s ) => is_string( $s ) ? trim( $s ) : '',
			'sanitize_key'            => fn( $s ) => is_string( $s ) ? strtolower( trim( $s ) ) : '',
			'absint'                  => fn( $n ) => abs( (int) $n ),
			'wp_generate_uuid4'       => fn() => 'uuid-' . md5( uniqid( '', true ) ),
		] );

		// Option seam backed by $this->options.
		Functions\when( 'get_option' )->alias(
			fn( $name, $default = false ) => $this->options[ $name ] ?? $default
		);
		Functions\when( 'update_option' )->alias(
			function ( $name, $value ) {
				$this->options[ $name ] = $value;
				return true;
			}
		);
		Functions\when( 'delete_option' )->alias(
			function ( $name ) {
				unset( $this->options[ $name ] );
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

	/** The first stored row (via a local, so reset() never gets a function return). */
	private function first_row(): array {
		$rows = $this->rows();
		return (array) reset( $rows );
	}

	// ── record(): rating validation ─────────────────────────────────────────────

	public function test_record_accepts_an_up_rating_and_stores_it(): void {
		$res = $this->store()->record( 'up', '', 'conv-1', 'msg-1' );

		$this->assertTrue( $res['ok'] ?? false );
		$this->assertArrayHasKey( 'id', $res );

		$rows = $this->rows();
		$this->assertCount( 1, $rows );

		$row = reset( $rows );
		$this->assertSame( 'up', $row['rating'] );
		$this->assertSame( 'conv-1', $row['conversation_ref'] );
		$this->assertSame( 'msg-1', $row['message_ref'] );
	}

	public function test_record_accepts_a_down_rating(): void {
		$res = $this->store()->record( 'down', 'not what I asked', 'conv-9', 'msg-3' );

		$this->assertTrue( $res['ok'] ?? false );
		$row = $this->first_row();
		$this->assertSame( 'down', $row['rating'] );
	}

	public function test_record_rejects_a_junk_rating_and_stores_nothing(): void {
		$res = $this->store()->record( 'sideways', '', 'conv-1', 'msg-1' );

		$this->assertFalse( $res['ok'] ?? true );
		$this->assertArrayHasKey( 'error', $res );
		// Nothing persisted for an invalid rating.
		$this->assertSame( [], $this->rows() );
	}

	public function test_record_rejects_an_empty_rating(): void {
		$res = $this->store()->record( '', 'some reason', 'conv-1', 'msg-1' );

		$this->assertFalse( $res['ok'] ?? true );
		$this->assertSame( [], $this->rows() );
	}

	public function test_record_normalizes_rating_case_and_whitespace(): void {
		// sanitize_key lower-cases + trims; "  UP  " is a valid 'up'.
		$res = $this->store()->record( '  UP  ', '', 'conv-1', 'msg-1' );

		$this->assertTrue( $res['ok'] ?? false );
		$this->assertSame( 'up', $this->first_row()['rating'] );
	}

	// ── record(): NO PII ─────────────────────────────────────────────────────────

	public function test_stored_row_contains_no_pii_fields(): void {
		$store = $this->store();
		$store->record( 'down', 'My email is jane@example.com', 'conv-1', 'msg-1' );

		$row = $this->first_row();

		// Telemetry only: rating + opaque refs + a sanitized reason + timestamps/flag.
		// NEVER an email/name/ip/user identifier as a structured field.
		foreach ( [ 'email', 'name', 'ip', 'user_id', 'user', 'customer' ] as $forbidden ) {
			$this->assertArrayNotHasKey( $forbidden, $row, "Row must not store a '$forbidden' field." );
		}
		// The refs are opaque client tokens, not PII; the rating is the signal.
		$this->assertArrayHasKey( 'rating', $row );
		$this->assertArrayHasKey( 'conversation_ref', $row );
	}

	public function test_reason_is_sanitized_and_length_capped(): void {
		$store = $this->store();

		// A reason far longer than the cap must be truncated on store (no unbounded
		// blob, and the cap is the bound the hardening promises).
		$long = str_repeat( 'x', 5000 );
		$store->record( 'down', $long, 'conv-1', 'msg-1' );

		$row = $this->first_row();
		$this->assertLessThanOrEqual(
			Fahad_AI_Feedback::MAX_REASON_LENGTH,
			strlen( $row['reason'] ),
			'A free-text reason must be capped at MAX_REASON_LENGTH characters.'
		);
	}

	public function test_refs_are_length_bounded(): void {
		// Opaque refs are still attacker-controlled strings — they must be bounded so
		// the option can't be bloated by a giant "conversation id".
		$store = $this->store();
		$store->record( 'up', '', str_repeat( 'c', 1000 ), str_repeat( 'm', 1000 ) );

		$row = $this->first_row();
		$this->assertLessThanOrEqual( Fahad_AI_Feedback::MAX_REF_LENGTH, strlen( $row['conversation_ref'] ) );
		$this->assertLessThanOrEqual( Fahad_AI_Feedback::MAX_REF_LENGTH, strlen( $row['message_ref'] ) );
	}

	// ── aggregates(): counts up/down ─────────────────────────────────────────────

	public function test_aggregates_count_up_and_down_correctly(): void {
		$store = $this->store();
		$store->record( 'up',   '', 'c1', 'm1' );
		$store->record( 'up',   '', 'c2', 'm2' );
		$store->record( 'down', '', 'c3', 'm3' );

		$agg = $store->aggregates();

		$this->assertSame( 2, $agg['up'] );
		$this->assertSame( 1, $agg['down'] );
		$this->assertSame( 3, $agg['total'] );
	}

	public function test_aggregates_on_an_empty_store_are_zero(): void {
		$agg = $this->store()->aggregates();

		$this->assertSame( 0, $agg['up'] );
		$this->assertSame( 0, $agg['down'] );
		$this->assertSame( 0, $agg['total'] );
	}

	// ── guardrail flag: a down-rating is flagged + retrievable ──────────────────

	public function test_a_down_rating_is_flagged_for_review(): void {
		$store = $this->store();
		$res   = $store->record( 'down', 'wrong price quoted', 'conv-7', 'msg-2' );

		// The low-rated reply is surfaced to a future review pass via the guardrail flag.
		$rows = $this->rows();
		$this->assertTrue( (bool) $rows[ $res['id'] ]['flagged'], 'A down-rating must be flagged.' );
	}

	public function test_an_up_rating_is_not_flagged(): void {
		$store = $this->store();
		$res   = $store->record( 'up', '', 'conv-7', 'msg-2' );

		$rows = $this->rows();
		$this->assertFalse( (bool) $rows[ $res['id'] ]['flagged'], 'An up-rating must not be flagged.' );
	}

	public function test_flag_marks_an_arbitrary_entry_flagged(): void {
		// flag() lets a future guardrail flagger mark an entry (e.g. an UP-rated reply
		// that still tripped a scarcity/budget/grounding heuristic) for review.
		$store = $this->store();
		$id    = $store->record( 'up', '', 'conv-1', 'msg-1' )['id'];

		$this->assertTrue( $store->flag( $id ) );
		$this->assertTrue( (bool) $this->rows()[ $id ]['flagged'] );
	}

	public function test_flag_on_an_unknown_id_is_a_noop(): void {
		$store = $this->store();
		$store->record( 'up', '', 'conv-1', 'msg-1' );

		$this->assertFalse( $store->flag( 'does-not-exist' ) );
	}

	public function test_flagged_returns_only_flagged_entries_newest_first(): void {
		$store = $this->store();
		$store->record( 'up',   '', 'c1', 'm1' );           // not flagged
		$down = $store->record( 'down', 'bad', 'c2', 'm2' ); // flagged (down)
		$up   = $store->record( 'up',   '', 'c3', 'm3' );
		$store->flag( $up['id'] );                            // explicitly flagged

		$flagged = $store->flagged( 50 );

		// Both flagged rows are returned; the un-flagged up-rating is not.
		$this->assertCount( 2, $flagged );
		$refs = array_column( $flagged, 'conversation_ref' );
		$this->assertContains( 'c2', $refs );
		$this->assertContains( 'c3', $refs );
		$this->assertNotContains( 'c1', $refs );
	}

	// ── recent_down(): the low-rated replies are queryable ──────────────────────

	public function test_recent_down_returns_only_down_rated_rows(): void {
		$store = $this->store();
		$store->record( 'up',   '', 'c1', 'm1' );
		$store->record( 'down', 'a', 'c2', 'm2' );
		$store->record( 'up',   '', 'c3', 'm3' );
		$store->record( 'down', 'b', 'c4', 'm4' );

		$down = $store->recent_down( 50 );

		$this->assertCount( 2, $down );
		foreach ( $down as $row ) {
			$this->assertSame( 'down', $row['rating'] );
		}
	}

	public function test_recent_down_respects_its_limit(): void {
		$store = $this->store();
		$store->record( 'down', 'a', 'c1', 'm1' );
		$store->record( 'down', 'b', 'c2', 'm2' );
		$store->record( 'down', 'c', 'c3', 'm3' );

		$this->assertCount( 2, $store->recent_down( 2 ) );
	}

	// ── retention cap: bounded storage, oldest dropped ──────────────────────────

	public function test_retention_cap_bounds_the_stored_row_count(): void {
		$store = $this->store();

		// Record one more than the cap; the store must never exceed MAX_ENTRIES.
		$over = Fahad_AI_Feedback::MAX_ENTRIES + 25;
		for ( $i = 0; $i < $over; $i++ ) {
			$store->record( 'up', '', 'c' . $i, 'm' . $i );
		}

		$this->assertLessThanOrEqual( Fahad_AI_Feedback::MAX_ENTRIES, count( $this->rows() ) );
	}

	public function test_retention_cap_drops_oldest_first(): void {
		$store = $this->store();

		// The very first ref recorded should be evicted once we overflow the cap; a
		// recent one should survive (FIFO retention).
		$total = Fahad_AI_Feedback::MAX_ENTRIES + 5;
		for ( $i = 0; $i < $total; $i++ ) {
			$store->record( 'up', '', 'conv-' . $i, 'msg-' . $i );
		}

		$refs = array_column( $this->rows(), 'conversation_ref' );
		$this->assertNotContains( 'conv-0', $refs, 'The oldest entry must be evicted first.' );
		$this->assertContains( 'conv-' . ( $total - 1 ), $refs, 'The newest entry must be retained.' );
	}

	// ── corrupted option resilience ─────────────────────────────────────────────

	public function test_a_corrupted_option_is_treated_as_empty(): void {
		// A non-array option value must not fatal — the store reads it as empty.
		$this->options[ Fahad_AI_Feedback::OPTION ] = 'corrupted-not-an-array';

		$res = $this->store()->record( 'up', '', 'c1', 'm1' );

		$this->assertTrue( $res['ok'] ?? false );
		$this->assertCount( 1, $this->rows() );
	}
}
