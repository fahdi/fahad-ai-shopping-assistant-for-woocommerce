<?php
defined( 'ABSPATH' ) || exit;

/**
 * Reply feedback store + production guardrail telemetry (issue #50).
 *
 * The persistence behind the 👍/👎 controls the widget renders on each bot reply.
 * The offline eval harness can't see production; this is the in-the-wild signal on
 * answer quality and guardrail drift. record() logs a thumbs rating against an
 * opaque conversation/message reference; aggregates()/recent_down()/flagged()
 * expose it so a future review pass can surface low-rated replies and convert them
 * into eval golden cases.
 *
 * ─── TELEMETRY ONLY — NO PII (issue #50 hardening) ───────────────────────────────
 *
 * A stored row carries ONLY: the rating ('up'|'down'), a sanitized + length-capped
 * free-text reason, two OPAQUE client-supplied refs (conversation + message — these
 * are widget-generated tokens, never an email/name), a created timestamp, and a
 * guardrail `flagged` boolean. It NEVER stores an email, a name, an IP, or a user
 * id — there is deliberately no such field, so the option cannot become a PII sink.
 * Nothing in here is ever fed back to the model (it is review/telemetry data, read
 * only by the store's own aggregates).
 *
 * ─── BOUNDED INPUT ───────────────────────────────────────────────────────────────
 *
 * Every stored string is attacker-controlled (a public storefront endpoint feeds
 * this). The reason is capped at MAX_REASON_LENGTH and each ref at MAX_REF_LENGTH,
 * so a single submission can't bloat the option with an unbounded blob.
 *
 * ─── RETENTION ───────────────────────────────────────────────────────────────────
 *
 * The store is bounded at MAX_ENTRIES rows. record() evicts the OLDEST entries
 * first (FIFO) once the cap is reached, so the option stays a rolling, finite window
 * of recent feedback rather than growing without limit.
 *
 * ─── GUARDRAIL FLAG ──────────────────────────────────────────────────────────────
 *
 * Each row has a `flagged` boolean. A 👎 is flagged automatically (it is the
 * low-rated signal). flag($id) lets a future guardrail flagger also mark an
 * otherwise 👍 reply that still tripped a scarcity/budget/grounding heuristic.
 * flagged() returns those rows (newest first) for a review/export pass.
 *
 * Storage: a single autoload=no option holding an id => row map. This keeps the
 * feature self-contained and trivially testable (no custom table, no migration) at
 * the scale a bounded, rolling feedback window realistically reaches — the same
 * approach as Fahad_AI_Stock_Alerts.
 */
final class Fahad_AI_Feedback {

	/** Option name holding the id => feedback-row map (autoload off). */
	public const OPTION = 'fahad_ai_feedback';

	/** Valid ratings. */
	public const RATING_UP   = 'up';
	public const RATING_DOWN = 'down';

	/** Hard cap on stored rows (retention — bound the option, FIFO eviction). */
	public const MAX_ENTRIES = 1000;

	/** Max characters kept of a free-text reason (bounds an attacker-controlled blob). */
	public const MAX_REASON_LENGTH = 500;

	/** Max characters kept of an opaque conversation/message ref. */
	public const MAX_REF_LENGTH = 128;

	private static ?Fahad_AI_Feedback $instance = null;

	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {}

	// -------------------------------------------------------------------------
	// Record
	// -------------------------------------------------------------------------

	/**
	 * Record a thumbs rating for a reply.
	 *
	 * Validates the rating ('up'|'down'; anything else is refused and stores
	 * nothing), sanitizes + length-caps the optional reason and the opaque refs,
	 * stores NO PII, auto-flags a 👎, and enforces the retention cap (oldest first).
	 *
	 * @param string $rating           'up' | 'down'.
	 * @param string $reason           Optional short free-text reason (capped).
	 * @param string $conversation_ref Opaque client conversation token (capped).
	 * @param string $message_ref      Opaque client message token (capped).
	 * @return array{ ok:bool, id?:string, error?:string }
	 */
	public function record( string $rating, string $reason = '', string $conversation_ref = '', string $message_ref = '' ): array {
		$rating = $this->normalize_rating( $rating );
		if ( '' === $rating ) {
			return [
				'ok'    => false,
				'error' => __( 'Please choose a rating.', 'fahad-ai-shopping-assistant-for-woocommerce' ),
			];
		}

		$reason           = $this->cap( sanitize_textarea_field( $reason ), self::MAX_REASON_LENGTH );
		$conversation_ref = $this->cap( sanitize_text_field( $conversation_ref ), self::MAX_REF_LENGTH );
		$message_ref      = $this->cap( sanitize_text_field( $message_ref ), self::MAX_REF_LENGTH );

		$rows = $this->all();

		$id          = $this->new_id();
		$rows[ $id ] = [
			'id'               => $id,
			'rating'           => $rating,
			'reason'           => $reason,
			'conversation_ref' => $conversation_ref,
			'message_ref'      => $message_ref,
			'created'          => time(),
			// A 👎 is the low-rated signal: flag it for the review pass automatically.
			'flagged'          => ( self::RATING_DOWN === $rating ),
		];

		$rows = $this->enforce_retention( $rows );
		$this->save( $rows );

		return [
			'ok' => true,
			'id' => $id,
		];
	}

	// -------------------------------------------------------------------------
	// Guardrail flag
	// -------------------------------------------------------------------------

	/**
	 * Flag a stored entry for review. Used by a future guardrail flagger to surface
	 * a reply that tripped a scarcity/budget/grounding heuristic even if it was not
	 * thumbed down. A non-existent id is a no-op.
	 *
	 * @return bool True when an entry was flagged.
	 */
	public function flag( string $id ): bool {
		$rows = $this->all();
		if ( ! isset( $rows[ $id ] ) ) {
			return false;
		}

		$rows[ $id ]['flagged'] = true;
		$this->save( $rows );

		return true;
	}

	// -------------------------------------------------------------------------
	// Aggregates / queries (review + future eval export)
	// -------------------------------------------------------------------------

	/**
	 * Simple counts over the stored window.
	 *
	 * @return array{ up:int, down:int, total:int }
	 */
	public function aggregates(): array {
		$up   = 0;
		$down = 0;

		foreach ( $this->all() as $row ) {
			if ( self::RATING_UP === ( $row['rating'] ?? '' ) ) {
				++$up;
			} elseif ( self::RATING_DOWN === ( $row['rating'] ?? '' ) ) {
				++$down;
			}
		}

		return [
			'up'    => $up,
			'down'  => $down,
			'total' => $up + $down,
		];
	}

	/**
	 * The most recent down-rated rows (newest first), capped at $limit. These are the
	 * low-rated replies a reviewer / eval-export pass wants to inspect.
	 *
	 * @return array<int, array>
	 */
	public function recent_down( int $limit = 50 ): array {
		return $this->recent( $limit, fn( array $row ) => self::RATING_DOWN === ( $row['rating'] ?? '' ) );
	}

	/**
	 * The most recent flagged rows (newest first), capped at $limit. Surfaces every
	 * entry a review pass should look at — both auto-flagged 👎s and entries an
	 * explicit guardrail flagger marked.
	 *
	 * @return array<int, array>
	 */
	public function flagged( int $limit = 50 ): array {
		return $this->recent( $limit, fn( array $row ) => ! empty( $row['flagged'] ) );
	}

	// -------------------------------------------------------------------------
	// Internals
	// -------------------------------------------------------------------------

	/**
	 * Rows matching $match, newest first, capped at $limit. Sorted by the `created`
	 * timestamp descending so the freshest feedback comes first.
	 *
	 * @param callable $match fn( array $row ): bool
	 * @return array<int, array>
	 */
	private function recent( int $limit, callable $match ): array {
		$rows = array_values( array_filter( $this->all(), $match ) );

		usort( $rows, static fn( $a, $b ) => ( $b['created'] ?? 0 ) <=> ( $a['created'] ?? 0 ) );

		$limit = max( 0, $limit );

		return array_slice( $rows, 0, $limit );
	}

	/**
	 * Bound the row map to MAX_ENTRIES, evicting the OLDEST rows first (FIFO) so the
	 * store is a rolling window. Rows are insertion-ordered (record() appends), and a
	 * `created` sort guards against any out-of-order map, so dropping from the front
	 * removes the oldest.
	 *
	 * @param array<string, array> $rows
	 * @return array<string, array>
	 */
	private function enforce_retention( array $rows ): array {
		if ( count( $rows ) <= self::MAX_ENTRIES ) {
			return $rows;
		}

		// Sort oldest → newest, then keep only the newest MAX_ENTRIES.
		uasort( $rows, static fn( $a, $b ) => ( $a['created'] ?? 0 ) <=> ( $b['created'] ?? 0 ) );

		return array_slice( $rows, -self::MAX_ENTRIES, null, true );
	}

	/** Normalise a rating to 'up'|'down', or '' when invalid. */
	private function normalize_rating( string $rating ): string {
		$rating = sanitize_key( $rating );
		if ( self::RATING_UP === $rating || self::RATING_DOWN === $rating ) {
			return $rating;
		}
		return '';
	}

	/**
	 * Truncate a string to at most $max characters. Uses mb_substr when available so a
	 * multibyte reason is not chopped mid-character (mirrors the memory pack's cap).
	 */
	private function cap( string $text, int $max ): string {
		if ( function_exists( 'mb_substr' ) ) {
			return mb_substr( $text, 0, $max );
		}
		return substr( $text, 0, $max );
	}

	/** A unique row id (UUID when available, else a random hash). */
	private function new_id(): string {
		if ( function_exists( 'wp_generate_uuid4' ) ) {
			return (string) wp_generate_uuid4();
		}
		return md5( uniqid( '', true ) );
	}

	/**
	 * All feedback rows as an id => row map. Defends against a corrupted / non-array
	 * option by returning an empty map.
	 *
	 * @return array<string, array>
	 */
	private function all(): array {
		$rows = get_option( self::OPTION, [] );
		return is_array( $rows ) ? $rows : [];
	}

	/** Persist the id => row map (autoload off — this is a rolling window). */
	private function save( array $rows ): void {
		update_option( self::OPTION, $rows, false );
	}
}
