<?php
defined( 'ABSPATH' ) || exit;

/**
 * Owner analytics store + "unanswered questions" telemetry (issue #49).
 *
 * The merchant-facing signal behind the analytics dashboard: per-turn events the
 * agent loop records so a store owner can see ROI (chat → add-to-cart → order) and,
 * just as importantly, WHERE the assistant fails (the abstain / escalate /
 * no-tool-match "couldn't answer" list). record() logs one row per assistant turn;
 * the aggregates (top_questions / unanswered / funnel / cost_summary) read it back
 * over a date range for the dashboard. This is the #1 adoption/commercial gap the
 * roadmap calls out, made measurable without spying on shoppers.
 *
 * ─── PRIVACY-SAFE, NO PII (issue #49 hardening) ─────────────────────────────────
 *
 * A stored row carries ONLY: a TRIMMED, EMAIL-MASKED snippet of the shopper's
 * question (so a top-questions list is possible WITHOUT retaining raw message text),
 * the coarse outcome, the tool names called, three best-effort funnel booleans
 * (product_surfaced / added_to_cart, plus order attribution computed at aggregate
 * time), token/cost numbers, an OPAQUE conversation ref (a model-supplied token,
 * never an email/name), and a created timestamp. It NEVER stores an email, a name,
 * an IP, or a user id, there is deliberately no such field, so the option cannot
 * become a PII sink. The question snippet is run through Fahad_AI_Auth::mask_email()
 * for EVERY email-shaped token in it, so a shopper who types their address into the
 * chat does not leave it here verbatim. Nothing in here is EVER fed back to the
 * model (it is owner telemetry, read only by this store's own aggregates), that is
 * the hard rule from the issue.
 *
 * ─── BOUNDED INPUT ───────────────────────────────────────────────────────────────
 *
 * Every stored string is attacker-influenced (the question is shopper free-text; the
 * tool names come from model output). The question is capped at MAX_QUESTION_LENGTH,
 * the ref at MAX_REF_LENGTH, and the tool list at MAX_TOOLS slugged entries, so a
 * single turn can't bloat the option with an unbounded blob.
 *
 * ─── RETENTION ───────────────────────────────────────────────────────────────────
 *
 * Two bounds, enforced lazily on every record() (and exposed for a scheduled cron):
 *   - AGE: rows older than MAX_AGE_DAYS are purged (purge_expired()). This is the
 *     "no PII-bearing content beyond a configurable retention" promise, even the
 *     masked snippet ages out.
 *   - COUNT: the store is capped at MAX_ENTRIES rows, evicting the OLDEST first
 *     (FIFO), so the option stays a rolling, finite window.
 * purge() wipes everything (the dashboard's delete control).
 *
 * ─── OPT-OUT ─────────────────────────────────────────────────────────────────────
 *
 * Logging is opt-OUT via OPTION_ENABLED, default ON (analytics is the whole point of
 * the feature; a merchant who wants zero logging unticks one box and record() becomes
 * a no-op with negligible overhead). The agent loop calls record() unconditionally;
 * the enabled() short-circuit lives here so there is a single gate.
 *
 * Storage: a single autoload=no option holding an id => row map, the same
 * option-backed, no-migration approach as Fahad_AI_Feedback / Fahad_AI_Stock_Alerts,
 * at the scale a bounded, rolling analytics window realistically reaches.
 */
final class Fahad_AI_Analytics {

	/** Option name holding the id => event-row map (autoload off). */
	public const OPTION = 'fahad_ai_analytics';

	/** Option name for the merchant opt-out flag (default ON). */
	public const OPTION_ENABLED = 'fahad_ai_analytics_enabled';

	// Outcome enum, the coarse classification of an assistant turn. The three
	// "couldn't answer" outcomes (abstain / escalate / no-tool-match) are what the
	// dashboard's failure list surfaces.
	public const OUTCOME_ANSWERED      = 'answered';
	public const OUTCOME_ESCALATED     = 'escalated';
	public const OUTCOME_ABSTAINED     = 'abstained';
	public const OUTCOME_NO_TOOL_MATCH = 'no_tool_match';
	public const OUTCOME_ERROR         = 'error';

	/** Hard cap on stored rows (retention, bound the option, FIFO eviction). */
	public const MAX_ENTRIES = 2000;

	/** Rows older than this many days are purged (retention, even the masked snippet ages out). */
	public const MAX_AGE_DAYS = 90;

	/** Max characters kept of the (email-masked) question snippet. */
	public const MAX_QUESTION_LENGTH = 200;

	/** Max characters kept of the opaque conversation ref. */
	public const MAX_REF_LENGTH = 128;

	/** Max number of tool names kept per row (bounds a model-driven blob). */
	public const MAX_TOOLS = 12;

	/** The valid outcome values record() accepts. */
	private const OUTCOMES = [
		self::OUTCOME_ANSWERED,
		self::OUTCOME_ESCALATED,
		self::OUTCOME_ABSTAINED,
		self::OUTCOME_NO_TOOL_MATCH,
		self::OUTCOME_ERROR,
	];

	/** The "couldn't answer" outcomes surfaced by unanswered(). */
	private const FAILED_OUTCOMES = [
		self::OUTCOME_ESCALATED,
		self::OUTCOME_ABSTAINED,
		self::OUTCOME_NO_TOOL_MATCH,
	];

	private static ?Fahad_AI_Analytics $instance = null;

	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {}

	// -------------------------------------------------------------------------
	// Opt-out
	// -------------------------------------------------------------------------

	/**
	 * Whether analytics logging is enabled. Default ON; a merchant opts OUT by
	 * setting OPTION_ENABLED to a falsey value. This is the single gate record()
	 * checks, so disabling it makes recording a no-op everywhere.
	 */
	public function enabled(): bool {
		// Absent option ⇒ ON by default; an explicit falsey value ⇒ off.
		return (bool) get_option( self::OPTION_ENABLED, 1 );
	}

	// -------------------------------------------------------------------------
	// Record
	// -------------------------------------------------------------------------

	/**
	 * Record one assistant-turn analytics event.
	 *
	 * Short-circuits to a no-op when analytics is disabled (the opt-out). Otherwise
	 * validates the outcome (an unknown outcome is refused and stores nothing),
	 * email-masks + length-caps the question snippet, slugs + bounds the tool list,
	 * bounds the conversation ref, normalises the funnel booleans + numbers, stores
	 * NO PII, purges expired rows, and enforces the count cap (oldest first).
	 *
	 * @param array $event {
	 *     @type string   $question         The shopper's question (masked + capped here). Optional.
	 *     @type string[] $tools            Tool names the turn called. Optional.
	 *     @type string   $outcome          One of the OUTCOME_* constants. Required.
	 *     @type bool     $product_surfaced Whether a product card was surfaced this turn.
	 *     @type bool     $added_to_cart    Whether this turn added to cart.
	 *     @type int      $tokens           Token count for the turn (0 if unknown).
	 *     @type float    $cost             Cost for the turn (0 if unknown).
	 *     @type string   $conversation_ref Opaque client conversation token (capped).
	 * }
	 * @return array{ ok:bool, id?:string, error?:string }
	 */
	public function record( array $event ): array {
		if ( ! $this->enabled() ) {
			return [
				'ok'    => false,
				'error' => 'analytics_disabled',
			];
		}

		$outcome = sanitize_key( (string) ( $event['outcome'] ?? '' ) );
		if ( ! in_array( $outcome, self::OUTCOMES, true ) ) {
			return [
				'ok'    => false,
				'error' => 'invalid_outcome',
			];
		}

		$rows = $this->all();

		$id          = $this->new_id();
		$rows[ $id ] = [
			'id'               => $id,
			'question'         => $this->clean_question( (string) ( $event['question'] ?? '' ) ),
			'tools'            => $this->clean_tools( $event['tools'] ?? [] ),
			'outcome'          => $outcome,
			'product_surfaced' => ! empty( $event['product_surfaced'] ),
			'added_to_cart'    => ! empty( $event['added_to_cart'] ),
			'tokens'           => max( 0, (int) ( $event['tokens'] ?? 0 ) ),
			'cost'             => max( 0.0, (float) ( $event['cost'] ?? 0.0 ) ),
			'conversation_ref' => $this->cap( sanitize_text_field( (string) ( $event['conversation_ref'] ?? '' ) ), self::MAX_REF_LENGTH ),
			'created'          => time(),
		];

		// Retention: age out stale rows first (so the count cap then operates on the
		// live window), then bound the count.
		$rows = $this->drop_expired( $rows );
		$rows = $this->enforce_count( $rows );

		$this->save( $rows );

		return [
			'ok' => true,
			'id' => $id,
		];
	}

	// -------------------------------------------------------------------------
	// Aggregates (read back for the dashboard)
	// -------------------------------------------------------------------------

	/**
	 * Top questions by frequency over an optional date range, newest-frequency first.
	 * Empty question snippets are ignored (an intent-less turn does not pollute the
	 * list). Ties keep insertion order, which is good enough for a dashboard.
	 *
	 * @param int   $limit Max questions to return.
	 * @param array $range { from?: int, to?: int } inclusive unix-timestamp window; omit for all time.
	 * @return array<int, array{question:string, count:int}>
	 */
	/**
	 * The most frequent product searches that surfaced NO product, distinct demand the catalogue
	 * did not meet. A row counts only when it ran the product-search tool AND surfaced nothing, so
	 * this is the shopper-searched-and-found-nothing signal, not the same as an unanswered question
	 * (a no-result search can be answered perfectly well with "we don't have that"). Reuses the same
	 * privacy-safe, range-filtered store as every other aggregate.
	 *
	 * @return array<int, array{question: string, count: int}> Sorted by count, highest first.
	 */
	public function unmet_searches( int $limit = 10, array $range = [] ): array {
		$counts = [];
		foreach ( $this->in_range( $range ) as $row ) {
			if ( ! empty( $row['product_surfaced'] ) ) {
				continue;
			}
			if ( ! in_array( 'search_products', (array) ( $row['tools'] ?? [] ), true ) ) {
				continue;
			}
			$q = trim( (string) ( $row['question'] ?? '' ) );
			if ( '' === $q ) {
				continue;
			}
			$counts[ $q ] = ( $counts[ $q ] ?? 0 ) + 1;
		}

		arsort( $counts );

		$out = [];
		foreach ( $counts as $question => $count ) {
			$out[] = [
				'question' => $question,
				'count'    => $count,
			];
		}

		return array_slice( $out, 0, max( 0, $limit ) );
	}

	public function top_questions( int $limit = 10, array $range = [] ): array {
		$counts = [];
		foreach ( $this->in_range( $range ) as $row ) {
			$q = (string) ( $row['question'] ?? '' );
			if ( '' === $q ) {
				continue;
			}
			$counts[ $q ] = ( $counts[ $q ] ?? 0 ) + 1;
		}

		arsort( $counts );

		$out = [];
		foreach ( $counts as $question => $count ) {
			$out[] = [
				'question' => $question,
				'count'    => $count,
			];
		}

		return array_slice( $out, 0, max( 0, $limit ) );
	}

	/**
	 * The "questions we couldn't answer" list, turns whose outcome was abstain,
	 * escalate or no-tool-match, newest first, capped at $limit, over an optional
	 * date range. This is the merchant's content/coverage backlog.
	 *
	 * @param int   $limit Max rows to return.
	 * @param array $range { from?: int, to?: int } inclusive window; omit for all time.
	 * @return array<int, array{question:string, outcome:string, created:int}>
	 */
	public function unanswered( int $limit = 50, array $range = [] ): array {
		$rows = array_values( array_filter(
			$this->in_range( $range ),
			static fn( array $row ) => in_array( $row['outcome'] ?? '', self::FAILED_OUTCOMES, true )
		) );

		usort( $rows, static fn( $a, $b ) => ( $b['created'] ?? 0 ) <=> ( $a['created'] ?? 0 ) );

		$rows = array_slice( $rows, 0, max( 0, $limit ) );

		// Return only the fields the dashboard list needs (no funnel/cost noise).
		return array_map(
			static fn( array $row ) => [
				'question' => (string) ( $row['question'] ?? '' ),
				'outcome'  => (string) ( $row['outcome'] ?? '' ),
				'created'  => (int) ( $row['created'] ?? 0 ),
			],
			$rows
		);
	}

	/**
	 * The chat → add-to-cart → order funnel over an optional date range, attributed
	 * PER CONVERSATION (best-effort): a conversation counts toward a stage if ANY of
	 * its turns reached it. Order attribution is best-effort and computed by a
	 * supplied resolver so the store stays decoupled from WooCommerce order lookups
	 * (and unit-testable): pass a callable that, given the set of conversation refs
	 * that added to cart, returns how many converted to an order. With no resolver,
	 * `orders` is reported as null (unknown) rather than a fabricated 0.
	 *
	 * @param array         $range          { from?: int, to?: int } inclusive window; omit for all time.
	 * @param callable|null $order_resolver fn( string[] $cart_conversation_refs ): int
	 * @return array{ conversations:int, product_surfaced:int, added_to_cart:int, orders:int|null }
	 */
	public function funnel( array $range = [], ?callable $order_resolver = null ): array {
		$conversations = [];
		foreach ( $this->in_range( $range ) as $row ) {
			$ref = (string) ( $row['conversation_ref'] ?? '' );
			// Group turns by conversation ref; a blank ref is its own anonymous bucket
			// keyed by row id so a turn without a ref still counts as a conversation.
			$key = '' !== $ref ? $ref : ( '#' . ( $row['id'] ?? uniqid() ) );

			if ( ! isset( $conversations[ $key ] ) ) {
				$conversations[ $key ] = [
					'surfaced' => false,
					'cart'     => false,
				];
			}
			if ( ! empty( $row['product_surfaced'] ) ) {
				$conversations[ $key ]['surfaced'] = true;
			}
			if ( ! empty( $row['added_to_cart'] ) ) {
				$conversations[ $key ]['cart'] = true;
			}
		}

		$surfaced  = 0;
		$cart      = 0;
		$cart_refs = [];
		foreach ( $conversations as $key => $stage ) {
			if ( $stage['surfaced'] ) {
				++$surfaced;
			}
			if ( $stage['cart'] ) {
				++$cart;
				// Only real (non-anonymous) refs can be attributed to an order.
				if ( ! str_starts_with( (string) $key, '#' ) ) {
					$cart_refs[] = $key;
				}
			}
		}

		$orders = null;
		if ( null !== $order_resolver ) {
			$resolved = $order_resolver( $cart_refs );
			$orders   = max( 0, (int) $resolved );
		}

		$conversation_count = count( $conversations );

		return [
			'conversations'    => $conversation_count,
			'product_surfaced' => $surfaced,
			'added_to_cart'    => $cart,
			// Headline ROI number: share of conversations that reached the cart. Kept as a
			// 0..1 fraction so the caller can format it; 0 when there are no conversations.
			'cart_rate'        => $conversation_count > 0 ? $cart / $conversation_count : 0.0,
			'orders'           => $orders,
		];
	}

	/**
	 * Best-effort provider-health signal: how many turns ended in an ERROR outcome since
	 * $since (a unix time). Used to warn the owner when the AI provider is failing (a wrong,
	 * expired, or credit-exhausted key), which otherwise only surfaces as a silently dead
	 * widget. Returns 0 when logging is off or nothing is stored.
	 */
	public function error_count_since( int $since ): int {
		$errors = 0;
		foreach ( $this->in_range( [ 'from' => $since ] ) as $row ) {
			if ( self::OUTCOME_ERROR === ( $row['outcome'] ?? '' ) ) {
				++$errors;
			}
		}
		return $errors;
	}

	/**
	 * Answer-quality signal (issue #224): the share of recorded turns that ended in an ANSWERED
	 * outcome over the window, as a 0..1 float. The complement is turns the assistant escalated,
	 * abstained on, had no tool for, or errored on. 0 when there are no turns (no divide-by-zero).
	 */
	public function resolution_rate( array $range = [] ): float {
		$rows  = $this->in_range( $range );
		$total = count( $rows );
		if ( 0 === $total ) {
			return 0.0;
		}
		$answered = 0;
		foreach ( $rows as $row ) {
			if ( self::OUTCOME_ANSWERED === ( $row['outcome'] ?? '' ) ) {
				++$answered;
			}
		}
		return $answered / $total;
	}

	/**
	 * Cost / token totals and a per-conversation average over an optional date range.
	 * Cost per conversation = total cost / number of distinct conversations (the
	 * metric the issue asks for). An empty window yields zeros (no divide-by-zero).
	 *
	 * @param array $range { from?: int, to?: int } inclusive window; omit for all time.
	 * @return array{ conversations:int, total_cost:float, total_tokens:int, cost_per_conversation:float, turns:int }
	 */
	public function cost_summary( array $range = [] ): array {
		$total_cost   = 0.0;
		$total_tokens = 0;
		$turns        = 0;
		$conv_keys    = [];

		foreach ( $this->in_range( $range ) as $row ) {
			$total_cost   += (float) ( $row['cost'] ?? 0.0 );
			$total_tokens += (int) ( $row['tokens'] ?? 0 );
			++$turns;

			$ref = (string) ( $row['conversation_ref'] ?? '' );
			$key = '' !== $ref ? $ref : ( '#' . ( $row['id'] ?? uniqid() ) );
			$conv_keys[ $key ] = true;
		}

		$conversations = count( $conv_keys );

		return [
			'conversations'         => $conversations,
			'total_cost'            => $total_cost,
			'total_tokens'          => $total_tokens,
			'cost_per_conversation' => $conversations > 0 ? $total_cost / $conversations : 0.0,
			'turns'                 => $turns,
		];
	}

	// -------------------------------------------------------------------------
	// Export + delete (dashboard retention controls)
	// -------------------------------------------------------------------------

	/**
	 * All stored rows as a flat list (newest first), for the export control. Already
	 * privacy-safe, every row is the masked/bounded shape record() persisted, so it
	 * is safe to hand to the merchant as a download.
	 *
	 * @param array $range { from?: int, to?: int } inclusive window; omit for all time.
	 * @return array<int, array>
	 */
	public function export( array $range = [] ): array {
		$rows = $this->in_range( $range );
		usort( $rows, static fn( $a, $b ) => ( $b['created'] ?? 0 ) <=> ( $a['created'] ?? 0 ) );
		return $rows;
	}

	/**
	 * Delete ALL stored analytics rows (the dashboard's delete / retention control).
	 * Removes the option entirely so nothing lingers.
	 */
	public function purge(): void {
		delete_option( self::OPTION );
	}

	/**
	 * Purge rows older than the age cap. Exposed so a scheduled cron (or the bootstrap)
	 * can age out stale telemetry even on a quiet store where record() rarely runs.
	 * Returns the number of rows removed.
	 */
	public function purge_expired(): int {
		$rows  = $this->all();
		$kept  = $this->drop_expired( $rows );
		$dropped = count( $rows ) - count( $kept );

		if ( $dropped > 0 ) {
			$this->save( $kept );
		}

		return $dropped;
	}

	// -------------------------------------------------------------------------
	// Internals, PII minimization
	// -------------------------------------------------------------------------

	/**
	 * Turn a raw shopper question into a stored snippet: collapse whitespace,
	 * EMAIL-MASK every email-shaped token (PII minimization, a shopper who types
	 * their address into the chat must not leave it here), then length-cap. Returns
	 * '' for an empty/whitespace-only question.
	 */
	private function clean_question( string $question ): string {
		$question = sanitize_textarea_field( $question );
		$question = (string) preg_replace( '/\s+/u', ' ', $question );
		$question = trim( $question );

		if ( '' === $question ) {
			return '';
		}

		$question = $this->mask_emails( $question );

		return $this->cap( $question, self::MAX_QUESTION_LENGTH );
	}

	/**
	 * Replace every email-shaped token in a string with its masked form
	 * (jane@example.com → j***@example.com), reusing the plugin's existing
	 * Fahad_AI_Auth::mask_email() helper so masking is consistent with the rest of the
	 * privacy boundary (#25). A conservative address regex is used; anything it matches
	 * is masked, so the local part never survives.
	 */
	private function mask_emails( string $text ): string {
		return (string) preg_replace_callback(
			'/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i',
			static fn( array $m ) => Fahad_AI_Auth::mask_email( $m[0] ),
			$text
		);
	}

	/**
	 * Slug + bound a tool-name list. Tool names originate from model output, so each is
	 * run through sanitize_key (a safe slug) and the list is de-duplicated and capped
	 * at MAX_TOOLS so a runaway turn can't store a giant array. Non-string entries are
	 * dropped.
	 *
	 * @param mixed $tools The raw tool list.
	 * @return array<int, string>
	 */
	private function clean_tools( $tools ): array {
		if ( ! is_array( $tools ) ) {
			return [];
		}

		$clean = [];
		foreach ( $tools as $tool ) {
			if ( ! is_string( $tool ) || '' === $tool ) {
				continue;
			}
			$slug = sanitize_key( $tool );
			if ( '' !== $slug ) {
				$clean[ $slug ] = $slug;
			}
			if ( count( $clean ) >= self::MAX_TOOLS ) {
				break;
			}
		}

		return array_values( $clean );
	}

	// -------------------------------------------------------------------------
	// Internals, retention
	// -------------------------------------------------------------------------

	/**
	 * Drop rows whose `created` timestamp is older than MAX_AGE_DAYS. A row with no
	 * usable timestamp is treated as fresh (kept), we never silently delete on a
	 * missing field; the count cap is the backstop for those.
	 *
	 * @param array<string, array> $rows
	 * @return array<string, array>
	 */
	private function drop_expired( array $rows ): array {
		$cutoff = time() - ( self::MAX_AGE_DAYS * DAY_IN_SECONDS );

		return array_filter(
			$rows,
			static fn( array $row ) => (int) ( $row['created'] ?? PHP_INT_MAX ) >= $cutoff
		);
	}

	/**
	 * Bound the row map to MAX_ENTRIES, evicting the OLDEST rows first (FIFO) so the
	 * store is a rolling window. A `created` sort guards against any out-of-order map.
	 *
	 * @param array<string, array> $rows
	 * @return array<string, array>
	 */
	private function enforce_count( array $rows ): array {
		if ( count( $rows ) <= self::MAX_ENTRIES ) {
			return $rows;
		}

		uasort( $rows, static fn( $a, $b ) => ( $a['created'] ?? 0 ) <=> ( $b['created'] ?? 0 ) );

		return array_slice( $rows, -self::MAX_ENTRIES, null, true );
	}

	// -------------------------------------------------------------------------
	// Internals, storage
	// -------------------------------------------------------------------------

	/**
	 * Rows within an inclusive { from, to } timestamp window, as a flat list. An
	 * empty/absent range returns everything. Used by every aggregate so date-range
	 * filtering is implemented once.
	 *
	 * @param array $range { from?: int, to?: int }
	 * @return array<int, array>
	 */
	private function in_range( array $range ): array {
		$from = isset( $range['from'] ) ? (int) $range['from'] : null;
		$to   = isset( $range['to'] ) ? (int) $range['to'] : null;

		$rows = array_values( $this->all() );

		if ( null === $from && null === $to ) {
			return $rows;
		}

		return array_values( array_filter(
			$rows,
			static function ( array $row ) use ( $from, $to ) {
				$ts = (int) ( $row['created'] ?? 0 );
				if ( null !== $from && $ts < $from ) {
					return false;
				}
				if ( null !== $to && $ts > $to ) {
					return false;
				}
				return true;
			}
		) );
	}

	/**
	 * Truncate a string to at most $max characters. Uses mb_substr when available so a
	 * multibyte snippet is not chopped mid-character (mirrors Fahad_AI_Feedback::cap).
	 */
	private function cap( string $text, int $max ): string {
		if ( function_exists( 'mb_substr' ) ) {
			return mb_substr( $text, 0, $max );
		}
		// @codeCoverageIgnoreStart
		// Reason: mbstring is a loaded extension in the test/runtime env, so
		// function_exists('mb_substr') is hardcoded true and this substr fallback
		// (taken only when mbstring is absent) is unreachable in-process.
		return substr( $text, 0, $max );
		// @codeCoverageIgnoreEnd
	}

	/** A unique row id (UUID when available, else a random hash). */
	private function new_id(): string {
		if ( function_exists( 'wp_generate_uuid4' ) ) {
			return (string) wp_generate_uuid4();
		}
		return md5( uniqid( '', true ) );
	}

	/**
	 * All analytics rows as an id => row map. Defends against a corrupted / non-array
	 * option by returning an empty map.
	 *
	 * @return array<string, array>
	 */
	private function all(): array {
		$rows = get_option( self::OPTION, [] );
		return is_array( $rows ) ? $rows : [];
	}

	/** Persist the id => row map (autoload off, this is a rolling window). */
	private function save( array $rows ): void {
		update_option( self::OPTION, $rows, false );
	}
}
