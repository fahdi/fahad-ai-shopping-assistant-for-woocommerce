<?php
/**
 * COVERAGE-COMPLETION tests for Fahad_AI_Analytics (issue #49).
 *
 * Sibling AnalyticsTest.php already drives the headline behaviour (record, NO-PII
 * masking, the dashboard aggregates, retention, export/delete, opt-out). This file
 * targets the remaining uncovered guard/branch lines so the store hits ~100% line
 * coverage:
 *
 *   - top_questions(): an EMPTY question snippet is skipped (does not pollute the
 *     ranking) — the `continue` guard.
 *   - clean_question(): a whitespace-only question collapses to '' and is stored
 *     as an empty snippet (the early `return ''`).
 *   - clean_tools(): a non-array `tools` value yields []; a non-string / empty
 *     entry inside the array is skipped.
 *   - funnel(): the order_resolver branch — a supplied resolver is handed the
 *     cart conversation refs and its (clamped) result is reported as `orders`.
 *   - in_range(): the upper-bound `to` filter — a row newer than `to` is excluded.
 *   - new_id(): the md5(uniqid()) fallback when wp_generate_uuid4() is absent.
 *
 * Conventions mirror AnalyticsTest / ApiHandlerTest: Brain\Monkey for the WP
 * function seam, the singleton reset via reflection between cases, an in-memory
 * options map standing in for the WP options table.
 */

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;

class CoverageAnalyticsTest extends TestCase {

	use MockeryPHPUnitIntegration;

	/**
	 * In-memory stand-in for the WP options table, keyed by option name.
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

		// new_id() calls wp_generate_uuid4() ONLY when it EXISTS. In a shared process a
		// sibling test may already have defined it (via Patchwork), in which case calling
		// it unmocked would throw; so when it is already defined we (re)mock it. When it
		// is NOT defined — e.g. an @runInSeparateProcess case — we leave it absent so
		// new_id() exercises its md5( uniqid() ) FALLBACK branch (line 571).
		if ( function_exists( 'wp_generate_uuid4' ) ) {
			Functions\when( 'wp_generate_uuid4' )->alias( static fn() => 'uuid-' . md5( uniqid( '', true ) ) );
		}

		// mask_email() needs only trim(); apply_filters as a value pass-through.
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

		// NOTE: wp_generate_uuid4 is DELIBERATELY NOT stubbed here so new_id() falls
		// through to its md5( uniqid() ) branch (line 571). Per-case stubbing covers
		// the cases that just need a unique id without exercising that branch.
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

	/** The first stored row. */
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

	/**
	 * Invoke a private method on the store via reflection (no setAccessible —
	 * host runs PHP 8.x where private methods are reachable directly on PHP 8.1+
	 * once the method object is obtained; matches the sibling's reflection style).
	 */
	private function call_private( Fahad_AI_Analytics $store, string $method, array $args = [] ) {
		$ref = new ReflectionMethod( Fahad_AI_Analytics::class, $method );
		return $ref->invokeArgs( $store, $args );
	}

	// ── new_id(): md5( uniqid() ) fallback when wp_generate_uuid4 is absent (line 571) ──

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 *
	 * wp_generate_uuid4() is a real WordPress function the unit env never bootstraps,
	 * but Brain\Monkey DEFINES it the moment any other test stubs it — and Patchwork
	 * leaves it defined for the rest of the process, so function_exists() then reports
	 * true. To exercise the md5( uniqid() ) FALLBACK branch deterministically we run in
	 * a SEPARATE process where the function is genuinely undefined (the same isolation
	 * the coupon-tools coverage tests use for get_posts()/WC()).
	 */
	public function test_record_uses_md5_fallback_id_when_uuid_helper_absent(): void {
		$this->assertFalse(
			function_exists( 'wp_generate_uuid4' ),
			'precondition: wp_generate_uuid4 must be undefined so the md5 fallback fires.'
		);

		$res = $this->store()->record( $this->event() );

		$this->assertTrue( $res['ok'] ?? false );
		$id = $res['id'];
		// The fallback is md5( uniqid() ) → a 32-char lowercase hex string, NOT a UUID.
		$this->assertMatchesRegularExpression( '/^[0-9a-f]{32}$/', $id, 'Fallback id is a 32-char md5 hex.' );
		$this->assertArrayHasKey( $id, $this->rows(), 'The fallback id keys the stored row.' );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_fallback_ids_are_unique_across_turns(): void {
		// In the isolated process new_id() takes the md5( uniqid() ) fallback; two
		// records must still get distinct ids so rows do not collide in the map.
		$this->assertFalse( function_exists( 'wp_generate_uuid4' ) );

		$store = $this->store();
		$a     = $store->record( $this->event() )['id'];
		$b     = $store->record( $this->event() )['id'];

		$this->assertNotSame( $a, $b );
		$this->assertCount( 2, $this->rows() );
	}

	// ── clean_question(): whitespace-only question → '' (early return, line 426) ──

	public function test_whitespace_only_question_is_stored_as_empty_snippet(): void {
		// sanitize_textarea_field trims and \s+ collapse leaves nothing → the early
		// `return ''` branch; the row is still stored with an empty question snippet.
		$store = $this->store();
		$store->record( $this->event( [ 'question' => "   \n\t  " ] ) );

		$this->assertSame( '', $this->first_row()['question'], 'A whitespace-only question yields an empty snippet.' );
	}

	public function test_clean_question_returns_empty_string_directly(): void {
		// Drive clean_question() directly so the empty-input branch is unambiguous.
		$store = $this->store();
		$this->assertSame( '', $this->call_private( $store, 'clean_question', [ '     ' ] ) );
		$this->assertSame( '', $this->call_private( $store, 'clean_question', [ '' ] ) );
	}

	// ── top_questions(): empty-question rows are skipped (continue, line 219) ──

	public function test_top_questions_ignores_empty_question_rows(): void {
		// An intent-less turn (whitespace-only question → '' snippet) must NOT appear in
		// the ranking; the `continue` guard skips it while real questions still rank.
		$store = $this->store();
		$store->record( $this->event( [ 'question' => 'do you ship to canada' ] ) );
		$store->record( $this->event( [ 'question' => 'do you ship to canada' ] ) );
		$store->record( $this->event( [ 'question' => '    ' ] ) ); // empty snippet → skipped
		$store->record( $this->event( [ 'question' => "\t\n" ] ) ); // empty snippet → skipped

		$top = $store->top_questions( 10 );

		// Only the one real question appears; the blank rows are excluded entirely.
		$this->assertCount( 1, $top );
		$this->assertSame( 'do you ship to canada', $top[0]['question'] );
		$this->assertSame( 2, $top[0]['count'] );

		$questions = array_column( $top, 'question' );
		$this->assertNotContains( '', $questions, 'An empty question snippet must never be ranked.' );
	}

	// ── clean_tools(): non-array input → [] (line 460) ──

	public function test_clean_tools_returns_empty_for_non_array_input(): void {
		$store = $this->store();

		$this->assertSame( [], $this->call_private( $store, 'clean_tools', [ 'not-an-array' ] ) );
		$this->assertSame( [], $this->call_private( $store, 'clean_tools', [ 42 ] ) );
		$this->assertSame( [], $this->call_private( $store, 'clean_tools', [ null ] ) );
	}

	public function test_record_with_non_array_tools_stores_empty_tool_list(): void {
		// A turn that passes a scalar `tools` (defensive against malformed model output)
		// stores an empty, well-typed tool list rather than tripping.
		$store = $this->store();
		$store->record( $this->event( [ 'tools' => 'search_products' ] ) );

		$this->assertSame( [], $this->first_row()['tools'] );
	}

	// ── clean_tools(): non-string / empty entries are skipped (continue, line 466) ──

	public function test_clean_tools_skips_non_string_and_empty_entries(): void {
		// Model output can be ragged: ints, null, arrays, and empty strings are all
		// dropped; only the real slugs survive (and are de-duplicated).
		$store = $this->store();

		$clean = $this->call_private( $store, 'clean_tools', [ [
			'search_products',
			123,            // non-string → skipped
			null,           // non-string → skipped
			'',             // empty string → skipped
			[ 'nested' ],   // non-string → skipped
			'get_product_details',
			'search_products', // duplicate → de-duped
		] ] );

		$this->assertSame( [ 'search_products', 'get_product_details' ], $clean );
	}

	public function test_record_drops_ragged_tool_entries(): void {
		$store = $this->store();
		$store->record( $this->event( [ 'tools' => [ 'valid_tool', 0, false, '', 'other_tool' ] ] ) );

		$this->assertSame( [ 'valid_tool', 'other_tool' ], $this->first_row()['tools'] );
	}

	// ── funnel(): order_resolver branch (lines 320-321) ──

	public function test_funnel_invokes_order_resolver_with_cart_refs(): void {
		$store = $this->store();
		// Two conversations add to cart (A, B); one only surfaces (C). The resolver must
		// receive ONLY the real cart refs and its return becomes `orders`.
		$store->record( $this->event( [ 'conversation_ref' => 'A', 'product_surfaced' => true, 'added_to_cart' => true ] ) );
		$store->record( $this->event( [ 'conversation_ref' => 'B', 'product_surfaced' => true, 'added_to_cart' => true ] ) );
		$store->record( $this->event( [ 'conversation_ref' => 'C', 'product_surfaced' => true, 'added_to_cart' => false ] ) );

		$seen     = null;
		$resolver = function ( array $cart_refs ) use ( &$seen ) {
			$seen = $cart_refs;
			return count( $cart_refs ); // pretend every cart converted
		};

		$funnel = $store->funnel( [], $resolver );

		// The resolver saw exactly the two cart conversation refs.
		sort( $seen );
		$this->assertSame( [ 'A', 'B' ], $seen );
		// orders mirrors the resolver result (2), and is no longer null.
		$this->assertSame( 2, $funnel['orders'] );
		$this->assertSame( 2, $funnel['added_to_cart'] );
	}

	public function test_funnel_clamps_negative_resolver_result_to_zero(): void {
		// A resolver returning a negative (or non-int) value is clamped via max(0, (int))
		// so `orders` can never go below zero.
		$store = $this->store();
		$store->record( $this->event( [ 'conversation_ref' => 'A', 'added_to_cart' => true ] ) );

		$funnel = $store->funnel( [], static fn( array $refs ) => -7 );

		$this->assertSame( 0, $funnel['orders'], 'A negative resolver result clamps to zero.' );
	}

	public function test_funnel_casts_non_int_resolver_result(): void {
		// A stringy numeric resolver result is cast to int (best-effort attribution).
		$store = $this->store();
		$store->record( $this->event( [ 'conversation_ref' => 'A', 'added_to_cart' => true ] ) );

		$funnel = $store->funnel( [], static fn( array $refs ) => '3' );

		$this->assertSame( 3, $funnel['orders'] );
	}

	public function test_funnel_resolver_excludes_anonymous_cart_refs(): void {
		// A cart turn with NO conversation ref is an anonymous (#id) bucket and must NOT
		// be handed to the resolver — only real refs can be order-attributed.
		$store = $this->store();
		$store->record( $this->event( [ 'conversation_ref' => '', 'added_to_cart' => true ] ) );
		$store->record( $this->event( [ 'conversation_ref' => 'real', 'added_to_cart' => true ] ) );

		$seen     = [];
		$store->funnel( [], function ( array $refs ) use ( &$seen ) {
			$seen = $refs;
			return 0;
		} );

		$this->assertSame( [ 'real' ], $seen, 'Anonymous cart conversations are not order-attributable.' );
	}

	// ── in_range(): upper-bound `to` excludes newer rows (line 548) ──

	public function test_aggregates_exclude_rows_newer_than_the_to_bound(): void {
		// Seed three rows at controlled timestamps; a window with only an upper bound
		// (`to`) must drop the row whose `created` is AFTER it (the $ts > $to branch).
		$store = $this->store();

		$this->options[ Fahad_AI_Analytics::OPTION ] = [
			'early' => [ 'id' => 'early', 'question' => 'within',  'tools' => [], 'outcome' => Fahad_AI_Analytics::OUTCOME_ABSTAINED, 'product_surfaced' => false, 'added_to_cart' => false, 'tokens' => 0, 'cost' => 0.0, 'conversation_ref' => 'e', 'created' => 1000 ],
			'late'  => [ 'id' => 'late',  'question' => 'too new', 'tools' => [], 'outcome' => Fahad_AI_Analytics::OUTCOME_ABSTAINED, 'product_surfaced' => false, 'added_to_cart' => false, 'tokens' => 0, 'cost' => 0.0, 'conversation_ref' => 'l', 'created' => 9000 ],
		];

		// Upper bound only: keep rows created <= 5000, drop the `late` row (9000 > 5000).
		$unanswered = $store->unanswered( 50, [ 'to' => 5000 ] );

		$questions = array_column( $unanswered, 'question' );
		$this->assertContains( 'within', $questions );
		$this->assertNotContains( 'too new', $questions, 'A row newer than the `to` bound is excluded.' );
		$this->assertCount( 1, $unanswered );
	}

	public function test_to_bound_is_inclusive_at_the_boundary(): void {
		// The filter is `$ts > $to` → a row created EXACTLY at `to` is kept (inclusive).
		$store = $this->store();

		$this->options[ Fahad_AI_Analytics::OPTION ] = [
			'edge' => [ 'id' => 'edge', 'question' => 'boundary', 'tools' => [], 'outcome' => Fahad_AI_Analytics::OUTCOME_ABSTAINED, 'product_surfaced' => false, 'added_to_cart' => false, 'tokens' => 0, 'cost' => 0.0, 'conversation_ref' => 'b', 'created' => 5000 ],
		];

		$unanswered = $store->unanswered( 50, [ 'to' => 5000 ] );

		$this->assertCount( 1, $unanswered, 'A row created exactly at the `to` bound is retained.' );
		$this->assertSame( 'boundary', $unanswered[0]['question'] );
	}
}
