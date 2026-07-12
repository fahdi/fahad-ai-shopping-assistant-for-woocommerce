<?php
/**
 * Unit tests for Fahad_AI_Analytics (issue #49: owner analytics &
 * "unanswered questions" dashboard, the privacy-safe event STORE behind it).
 *
 * Red → Green → Refactor. Conventions mirror FeedbackTest / StockAlertsTest: WP
 * functions mocked via Brain\Monkey; the singleton reset via reflection between
 * cases (NEVER ReflectionMethod::setAccessible, host runs PHP 8.5). The store is
 * option-backed, so a single in-memory $this->options map stands in for the WP
 * options table and a test asserts exactly what was persisted.
 *
 * PRIVACY-SAFE + TELEMETRY-ONLY IS THE POINT (issue #49 hardening). The headline
 * tests are first-class:
 *   - NO PII: a recorded row stores a coarse intent label OR a TRIMMED,
 *     EMAIL-MASKED question snippet, never a raw email, name, IP, or user id. An
 *     email in the question is masked; the snippet is length-capped.
 *   - AGGREGATES: top questions, the "couldn't answer" list (abstain / escalate /
 *     no-tool-match), the chat → add-to-cart → order funnel, and cost per
 *     conversation all compute correctly over a date range.
 *   - RETENTION: the stored row count is bounded (oldest dropped first) AND rows
 *     older than the age cap are purged.
 *   - EXPORT + DELETE: export() returns the data; purge() deletes it.
 *   - OPT-OUT: when disabled, record() stores nothing.
 */

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;

class AnalyticsTest extends TestCase {

	use MockeryPHPUnitIntegration;

	/**
	 * In-memory stand-in for the WP options table, keyed by option name. The store
	 * is option-backed, so get_option / update_option / delete_option are stubbed to
	 * read/write this map and a test can assert precisely what was persisted.
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

		// The store reuses Fahad_AI_Auth::mask_email() for the email-masking layer,
		// which only needs trim(); provide it as a pass-through so the helper works
		// without a full WP environment.
		Functions\when( 'apply_filters' )->alias( static fn( $tag, $value = null ) => $value );

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
		( new ReflectionProperty( Fahad_AI_Analytics::class, 'instance' ) )->setValue( null, null );
		Monkey\tearDown();
		parent::tearDown();
	}

	/** Fresh store singleton (reset between cases via reflection). */
	private function store(): Fahad_AI_Analytics {
		( new ReflectionProperty( Fahad_AI_Analytics::class, 'instance' ) )->setValue( null, null );
		return Fahad_AI_Analytics::instance();
	}

	/** All stored analytics rows (the raw option value). */
	private function rows(): array {
		return $this->options[ Fahad_AI_Analytics::OPTION ] ?? [];
	}

	/** The first stored row (via a local, so reset() never gets a function return). */
	private function first_row(): array {
		$rows = $this->rows();
		return (array) reset( $rows );
	}

	/** A minimal valid event with sensible defaults; overrides merged on top. */
	private function event( array $overrides = [] ): array {
		return array_merge(
			[
				'question'         => 'do you have running shoes',
				'tools'            => [ 'search_products' ],
				'outcome'          => Fahad_AI_Analytics::OUTCOME_ANSWERED,
				'product_surfaced' => true,
				'added_to_cart'    => false,
				'tokens'           => 0,
				'cost'             => 0.0,
				'conversation_ref' => 'conv-1',
			],
			$overrides
		);
	}

	// ── record(): stores the right fields ───────────────────────────────────────

	public function test_record_stores_a_turn_event_with_the_expected_fields(): void {
		$res = $this->store()->record( $this->event( [
			'tools'   => [ 'search_products', 'get_product_details' ],
			'outcome' => Fahad_AI_Analytics::OUTCOME_ANSWERED,
		] ) );

		$this->assertTrue( $res['ok'] ?? false );
		$this->assertArrayHasKey( 'id', $res );

		$rows = $this->rows();
		$this->assertCount( 1, $rows );

		$row = reset( $rows );
		$this->assertSame( Fahad_AI_Analytics::OUTCOME_ANSWERED, $row['outcome'] );
		$this->assertSame( [ 'search_products', 'get_product_details' ], $row['tools'] );
		$this->assertTrue( (bool) $row['product_surfaced'] );
		$this->assertArrayHasKey( 'created', $row );
		$this->assertArrayHasKey( 'question', $row );
	}

	public function test_record_rejects_an_unknown_outcome_and_stores_nothing(): void {
		$res = $this->store()->record( $this->event( [ 'outcome' => 'banana' ] ) );

		$this->assertFalse( $res['ok'] ?? true );
		$this->assertSame( [], $this->rows() );
	}

	public function test_tools_list_is_sanitized_and_bounded(): void {
		// Tool names come from model output → slugged + capped so a hostile turn can't
		// bloat the row with a giant tool array.
		$store = $this->store();
		$many  = array_map( static fn( $i ) => 'tool_' . $i, range( 1, 100 ) );

		$store->record( $this->event( [ 'tools' => $many ] ) );

		$row = $this->first_row();
		$this->assertLessThanOrEqual( Fahad_AI_Analytics::MAX_TOOLS, count( $row['tools'] ) );
		// Each retained tool name is a clean slug (sanitize_key lower-cases / trims).
		foreach ( $row['tools'] as $tool ) {
			$this->assertSame( $tool, strtolower( trim( $tool ) ) );
		}
	}

	// ── record(): NO PII ─────────────────────────────────────────────────────────

	public function test_stored_row_contains_no_pii_fields(): void {
		$store = $this->store();
		$store->record( $this->event( [ 'question' => 'where is my order' ] ) );

		$row = $this->first_row();

		// Telemetry only: intent/question snippet + tools + outcome + funnel flags +
		// cost + an opaque conversation ref + a timestamp. NEVER an email / name / ip /
		// user identifier as a structured field.
		foreach ( [ 'email', 'name', 'ip', 'user_id', 'user', 'customer' ] as $forbidden ) {
			$this->assertArrayNotHasKey( $forbidden, $row, "Row must not store a '$forbidden' field." );
		}
		$this->assertArrayHasKey( 'outcome', $row );
		$this->assertArrayHasKey( 'conversation_ref', $row );
	}

	public function test_email_in_a_question_is_masked(): void {
		// A shopper might type their email into the chat; the stored snippet must NOT
		// retain it verbatim. The local part is masked (jane@... → j***@example.com).
		$store = $this->store();
		$store->record( $this->event( [ 'question' => 'email me at jane.doe@example.com please' ] ) );

		$snippet = $this->first_row()['question'];
		$this->assertStringNotContainsString( 'jane.doe@example.com', $snippet, 'A raw email must never be stored.' );
		$this->assertStringNotContainsString( 'jane.doe', $snippet, 'The email local part must be masked.' );
		$this->assertStringContainsString( '@example.com', $snippet, 'The masked form keeps only the domain.' );
	}

	public function test_multiple_emails_in_a_question_are_all_masked(): void {
		$store = $this->store();
		$store->record( $this->event( [
			'question' => 'contact alice@foo.com or bob@bar.org',
		] ) );

		$snippet = $this->first_row()['question'];
		$this->assertStringNotContainsString( 'alice@foo.com', $snippet );
		$this->assertStringNotContainsString( 'bob@bar.org', $snippet );
	}

	public function test_question_snippet_is_length_capped(): void {
		// The question is attacker-controlled free text; it must be trimmed to a cap so
		// the option can't be bloated by a giant message.
		$store = $this->store();
		$store->record( $this->event( [ 'question' => str_repeat( 'x', 5000 ) ] ) );

		$row = $this->first_row();
		$this->assertLessThanOrEqual(
			Fahad_AI_Analytics::MAX_QUESTION_LENGTH,
			strlen( $row['question'] ),
			'The question snippet must be capped at MAX_QUESTION_LENGTH characters.'
		);
	}

	public function test_conversation_ref_is_length_bounded(): void {
		$store = $this->store();
		$store->record( $this->event( [ 'conversation_ref' => str_repeat( 'c', 1000 ) ] ) );

		$this->assertLessThanOrEqual(
			Fahad_AI_Analytics::MAX_REF_LENGTH,
			strlen( $this->first_row()['conversation_ref'] )
		);
	}

	// ── opt-out: disabled store records nothing ──────────────────────────────────

	public function test_record_is_a_noop_when_analytics_is_disabled(): void {
		// The merchant opt-out: with the enabled flag explicitly off, record() stores
		// nothing (negligible-overhead short-circuit).
		$this->options[ Fahad_AI_Analytics::OPTION_ENABLED ] = 0;

		$res = $this->store()->record( $this->event() );

		$this->assertFalse( $res['ok'] ?? true );
		$this->assertSame( [], $this->rows() );
	}

	public function test_record_is_on_by_default(): void {
		// No enabled option set → analytics defaults ON (the feature is the point).
		$res = $this->store()->record( $this->event() );

		$this->assertTrue( $res['ok'] ?? false );
		$this->assertCount( 1, $this->rows() );
		$this->assertTrue( $this->store()->enabled() );
	}

	// ── aggregates: top questions ────────────────────────────────────────────────

	public function test_top_questions_ranks_by_frequency(): void {
		$store = $this->store();
		$store->record( $this->event( [ 'question' => 'do you ship to canada' ] ) );
		$store->record( $this->event( [ 'question' => 'do you ship to canada' ] ) );
		$store->record( $this->event( [ 'question' => 'do you ship to canada' ] ) );
		$store->record( $this->event( [ 'question' => 'is this waterproof' ] ) );

		$top = $store->top_questions( 10 );

		$this->assertSame( 'do you ship to canada', $top[0]['question'] );
		$this->assertSame( 3, $top[0]['count'] );
		$this->assertSame( 'is this waterproof', $top[1]['question'] );
		$this->assertSame( 1, $top[1]['count'] );
	}

	public function test_top_questions_respects_its_limit(): void {
		$store = $this->store();
		$store->record( $this->event( [ 'question' => 'a' ] ) );
		$store->record( $this->event( [ 'question' => 'b' ] ) );
		$store->record( $this->event( [ 'question' => 'c' ] ) );

		$this->assertCount( 2, $store->top_questions( 2 ) );
	}

	// ── aggregates: the "couldn't answer" list ───────────────────────────────────

	public function test_unanswered_surfaces_abstain_escalate_and_no_tool_match(): void {
		$store = $this->store();
		$store->record( $this->event( [ 'question' => 'gift wrap?',    'outcome' => Fahad_AI_Analytics::OUTCOME_ABSTAINED ] ) );
		$store->record( $this->event( [ 'question' => 'speak to human', 'outcome' => Fahad_AI_Analytics::OUTCOME_ESCALATED ] ) );
		$store->record( $this->event( [ 'question' => 'weather today',  'outcome' => Fahad_AI_Analytics::OUTCOME_NO_TOOL_MATCH ] ) );
		// An answered turn must NOT appear in the couldn't-answer list.
		$store->record( $this->event( [ 'question' => 'red shoes',      'outcome' => Fahad_AI_Analytics::OUTCOME_ANSWERED ] ) );

		$unanswered = $store->unanswered( 50 );

		$questions = array_column( $unanswered, 'question' );
		$this->assertContains( 'gift wrap?', $questions );
		$this->assertContains( 'speak to human', $questions );
		$this->assertContains( 'weather today', $questions );
		$this->assertNotContains( 'red shoes', $questions );
		$this->assertCount( 3, $unanswered );
	}

	// ── aggregates: chat → add-to-cart → order funnel ────────────────────────────

	public function test_funnel_counts_conversations_surfaced_and_added_to_cart(): void {
		$store = $this->store();
		// Conversation A: two turns, one surfaced a product, one added to cart.
		$store->record( $this->event( [ 'conversation_ref' => 'A', 'product_surfaced' => true,  'added_to_cart' => false ] ) );
		$store->record( $this->event( [ 'conversation_ref' => 'A', 'product_surfaced' => true,  'added_to_cart' => true  ] ) );
		// Conversation B: surfaced a product, never added.
		$store->record( $this->event( [ 'conversation_ref' => 'B', 'product_surfaced' => true,  'added_to_cart' => false ] ) );
		// Conversation C: a pure Q&A turn, nothing surfaced.
		$store->record( $this->event( [ 'conversation_ref' => 'C', 'product_surfaced' => false, 'added_to_cart' => false ] ) );

		$funnel = $store->funnel();

		// Funnel is per-conversation (best-effort attribution), not per-turn.
		$this->assertSame( 3, $funnel['conversations'], 'Three distinct conversations.' );
		$this->assertSame( 2, $funnel['product_surfaced'], 'Two conversations saw a product (A, B).' );
		$this->assertSame( 1, $funnel['added_to_cart'], 'One conversation added to cart (A).' );
		// Headline ROI number: share of conversations that reached the cart (1 of 3).
		$this->assertEqualsWithDelta( 1 / 3, $funnel['cart_rate'], 0.0001, 'One cart across three conversations.' );
	}

	public function test_funnel_cart_rate_is_zero_when_there_are_no_conversations(): void {
		$funnel = $this->store()->funnel();

		$this->assertSame( 0, $funnel['conversations'] );
		$this->assertSame( 0.0, $funnel['cart_rate'], 'No conversations must not divide by zero.' );
	}

	// ── health signal: recent error-outcome count ───────────────────────────────

	public function test_error_count_since_counts_only_recent_errors(): void {
		$store = $this->store();

		$this->options[ Fahad_AI_Analytics::OPTION ] = [
			// Old error (before the cutoff) → excluded.
			'e0' => [ 'id' => 'e0', 'question' => 'q', 'tools' => [], 'outcome' => Fahad_AI_Analytics::OUTCOME_ERROR,    'product_surfaced' => false, 'added_to_cart' => false, 'tokens' => 0, 'cost' => 0.0, 'conversation_ref' => 'a', 'created' => 1000 ],
			// Two recent errors → counted.
			'e1' => [ 'id' => 'e1', 'question' => 'q', 'tools' => [], 'outcome' => Fahad_AI_Analytics::OUTCOME_ERROR,    'product_surfaced' => false, 'added_to_cart' => false, 'tokens' => 0, 'cost' => 0.0, 'conversation_ref' => 'b', 'created' => 100000 ],
			'e2' => [ 'id' => 'e2', 'question' => 'q', 'tools' => [], 'outcome' => Fahad_AI_Analytics::OUTCOME_ERROR,    'product_surfaced' => false, 'added_to_cart' => false, 'tokens' => 0, 'cost' => 0.0, 'conversation_ref' => 'c', 'created' => 100001 ],
			// Recent but not an error → excluded.
			'a1' => [ 'id' => 'a1', 'question' => 'q', 'tools' => [], 'outcome' => Fahad_AI_Analytics::OUTCOME_ANSWERED, 'product_surfaced' => false, 'added_to_cart' => false, 'tokens' => 0, 'cost' => 0.0, 'conversation_ref' => 'd', 'created' => 100002 ],
		];

		$this->assertSame( 2, $store->error_count_since( 50000 ) );
	}

	public function test_error_count_since_is_zero_with_no_rows(): void {
		$this->assertSame( 0, $this->store()->error_count_since( 0 ) );
	}

	// ── aggregates: cost per conversation ────────────────────────────────────────

	public function test_cost_summary_totals_and_averages_per_conversation(): void {
		$store = $this->store();
		$store->record( $this->event( [ 'conversation_ref' => 'A', 'tokens' => 100, 'cost' => 0.01 ] ) );
		$store->record( $this->event( [ 'conversation_ref' => 'A', 'tokens' => 300, 'cost' => 0.03 ] ) );
		$store->record( $this->event( [ 'conversation_ref' => 'B', 'tokens' => 200, 'cost' => 0.02 ] ) );

		$cost = $store->cost_summary();

		$this->assertSame( 2, $cost['conversations'] );
		$this->assertEqualsWithDelta( 0.06, $cost['total_cost'], 0.0001 );
		$this->assertSame( 600, $cost['total_tokens'] );
		// Cost per conversation = total / distinct conversations.
		$this->assertEqualsWithDelta( 0.03, $cost['cost_per_conversation'], 0.0001 );
	}

	// ── aggregates: date-range filtering ─────────────────────────────────────────

	public function test_aggregates_respect_a_date_range(): void {
		$store = $this->store();

		// Seed rows directly with controlled timestamps so the range filter is testable
		// independent of wall-clock time. Two "old" rows and one "recent" row.
		$old    = 1000;
		$recent = 100000;
		$this->options[ Fahad_AI_Analytics::OPTION ] = [
			'r1' => [ 'id' => 'r1', 'question' => 'old one',   'tools' => [], 'outcome' => Fahad_AI_Analytics::OUTCOME_ABSTAINED, 'product_surfaced' => false, 'added_to_cart' => false, 'tokens' => 0, 'cost' => 0.0, 'conversation_ref' => 'x', 'created' => $old ],
			'r2' => [ 'id' => 'r2', 'question' => 'old two',   'tools' => [], 'outcome' => Fahad_AI_Analytics::OUTCOME_ABSTAINED, 'product_surfaced' => false, 'added_to_cart' => false, 'tokens' => 0, 'cost' => 0.0, 'conversation_ref' => 'y', 'created' => $old ],
			'r3' => [ 'id' => 'r3', 'question' => 'recent one', 'tools' => [], 'outcome' => Fahad_AI_Analytics::OUTCOME_ABSTAINED, 'product_surfaced' => false, 'added_to_cart' => false, 'tokens' => 0, 'cost' => 0.0, 'conversation_ref' => 'z', 'created' => $recent ],
		];

		// Window that includes only the recent row.
		$range      = [ 'from' => 50000, 'to' => 200000 ];
		$unanswered = $store->unanswered( 50, $range );

		$this->assertCount( 1, $unanswered );
		$this->assertSame( 'recent one', $unanswered[0]['question'] );
	}

	// ── retention: count cap, oldest dropped ─────────────────────────────────────

	public function test_retention_cap_bounds_the_stored_row_count(): void {
		$store = $this->store();

		$over = Fahad_AI_Analytics::MAX_ENTRIES + 25;
		for ( $i = 0; $i < $over; $i++ ) {
			$store->record( $this->event( [ 'conversation_ref' => 'c' . $i ] ) );
		}

		$this->assertLessThanOrEqual( Fahad_AI_Analytics::MAX_ENTRIES, count( $this->rows() ) );
	}

	public function test_retention_cap_drops_oldest_first(): void {
		$store = $this->store();

		$total = Fahad_AI_Analytics::MAX_ENTRIES + 5;
		for ( $i = 0; $i < $total; $i++ ) {
			$store->record( $this->event( [ 'conversation_ref' => 'conv-' . $i ] ) );
		}

		$refs = array_column( $this->rows(), 'conversation_ref' );
		$this->assertNotContains( 'conv-0', $refs, 'The oldest entry must be evicted first.' );
		$this->assertContains( 'conv-' . ( $total - 1 ), $refs, 'The newest entry must be retained.' );
	}

	// ── retention: age-based purge ───────────────────────────────────────────────

	public function test_purge_expired_drops_rows_older_than_the_age_cap(): void {
		$store = $this->store();

		$now    = time();
		$oldTs  = $now - ( ( Fahad_AI_Analytics::MAX_AGE_DAYS + 5 ) * DAY_IN_SECONDS );
		$freshTs = $now - DAY_IN_SECONDS;

		$this->options[ Fahad_AI_Analytics::OPTION ] = [
			'old'   => [ 'id' => 'old',   'question' => 'stale',  'tools' => [], 'outcome' => Fahad_AI_Analytics::OUTCOME_ANSWERED, 'product_surfaced' => false, 'added_to_cart' => false, 'tokens' => 0, 'cost' => 0.0, 'conversation_ref' => 'o', 'created' => $oldTs ],
			'fresh' => [ 'id' => 'fresh', 'question' => 'recent', 'tools' => [], 'outcome' => Fahad_AI_Analytics::OUTCOME_ANSWERED, 'product_surfaced' => false, 'added_to_cart' => false, 'tokens' => 0, 'cost' => 0.0, 'conversation_ref' => 'f', 'created' => $freshTs ],
		];

		$store->purge_expired();

		$rows = $this->rows();
		$this->assertArrayNotHasKey( 'old', $rows, 'A row older than the age cap must be purged.' );
		$this->assertArrayHasKey( 'fresh', $rows, 'A fresh row must survive the purge.' );
	}

	public function test_record_purges_expired_rows_lazily(): void {
		$store = $this->store();

		$oldTs = time() - ( ( Fahad_AI_Analytics::MAX_AGE_DAYS + 5 ) * DAY_IN_SECONDS );
		$this->options[ Fahad_AI_Analytics::OPTION ] = [
			'old' => [ 'id' => 'old', 'question' => 'stale', 'tools' => [], 'outcome' => Fahad_AI_Analytics::OUTCOME_ANSWERED, 'product_surfaced' => false, 'added_to_cart' => false, 'tokens' => 0, 'cost' => 0.0, 'conversation_ref' => 'o', 'created' => $oldTs ],
		];

		// Recording a new turn opportunistically evicts the stale row.
		$store->record( $this->event( [ 'conversation_ref' => 'new' ] ) );

		$rows = $this->rows();
		$this->assertArrayNotHasKey( 'old', $rows );
		$refs = array_column( $rows, 'conversation_ref' );
		$this->assertContains( 'new', $refs );
	}

	// ── export + delete (retention control on the dashboard) ─────────────────────

	public function test_export_returns_all_stored_rows(): void {
		$store = $this->store();
		$store->record( $this->event( [ 'conversation_ref' => 'a' ] ) );
		$store->record( $this->event( [ 'conversation_ref' => 'b' ] ) );

		$export = $store->export();

		$this->assertCount( 2, $export );
		$refs = array_column( $export, 'conversation_ref' );
		$this->assertContains( 'a', $refs );
		$this->assertContains( 'b', $refs );
	}

	public function test_purge_deletes_all_stored_rows(): void {
		$store = $this->store();
		$store->record( $this->event( [ 'conversation_ref' => 'a' ] ) );
		$store->record( $this->event( [ 'conversation_ref' => 'b' ] ) );

		$store->purge();

		$this->assertSame( [], $this->rows() );
		$this->assertSame( [], $store->export() );
	}

	// ── corrupted option resilience ─────────────────────────────────────────────

	public function test_a_corrupted_option_is_treated_as_empty(): void {
		$this->options[ Fahad_AI_Analytics::OPTION ] = 'corrupted-not-an-array';

		$res = $this->store()->record( $this->event() );

		$this->assertTrue( $res['ok'] ?? false );
		$this->assertCount( 1, $this->rows() );
	}
}
