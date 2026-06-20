<?php
defined( 'ABSPATH' ) || exit;

/**
 * Returns & exchange / RMA tools (issue #53) — guide ELIGIBLE returns and RECORD a
 * return request, for LOGGED-IN customers only. Returns/refunds are money-sensitive, so
 * this pack is deliberately conservative: it checks a data-driven policy and records a
 * request; it NEVER issues a refund, credit, or any money/status mutation. Refunds stay a
 * human action — edge and ineligible cases escalate to support, never block it.
 *
 * A drop-in feature pack (same pattern as Fahad_AI_Order_Tools / Fahad_AI_Reorder_Tools):
 * a self-contained class in its own file under includes/tools/ that self-registers a
 * provider at the bottom via Fahad_AI_Tool_Registry::register_pack(). The bootstrap (and
 * the test bootstrap) glob-require everything here, so adding this pack is a SINGLE new
 * file — no edits to the bootstrap, the test bootstrap, or the eval harness.
 *
 * Tools provided:
 *   - check_return_eligibility — is an order (or one item on it) returnable, judged
 *                                against a CONFIGURABLE return window (default 30 days;
 *                                filter `fahad_ai_return_window_days`), the order's status
 *                                and date, AND ownership. Returns a plain, honest reason
 *                                when ineligible plus the human-support path.
 *   - request_return           — RECORD a return/exchange request (an RMA) as order meta
 *                                and a best-effort order note. Idempotent (the same items
 *                                requested again do NOT create a duplicate). NEVER refunds
 *                                or changes the order — only records the request.
 *
 * SECURITY + MONEY-SAFETY ARE THE WHOLE POINT (issue #53 hardening). These tools use the
 * issue-#25 authorization boundary (Fahad_AI_Auth) in BOTH of its layers (defence in
 * depth):
 *
 *   1. CENTRAL LOGIN GATE. Both tools declare `'personal' => true`, so
 *      Fahad_AI_Tool_Registry::dispatch() runs Fahad_AI_Auth::guard_logged_in() BEFORE the
 *      callback. A guest is blocked centrally with the standard login-required error and
 *      the callback is never reached — these tools never re-implement the guest check, so
 *      they cannot leak by forgetting it.
 *
 *   2. PER-RECORD OWNERSHIP. The registry cannot know which customer a given order belongs
 *      to, so each tool loads the order and then calls
 *      Fahad_AI_Auth::user_owns( $order->get_customer_id() ); a mismatch returns a "not
 *      found"-style error (NOT "forbidden"), so we never even confirm an order exists for
 *      another user — and we bail BEFORE reading items or recording anything.
 *
 * POLICY IS DATA-DRIVEN, NOT INVENTED. Eligibility is judged only against real signals:
 * the order's status (`fahad_ai_return_eligible_statuses`, default completed/processing),
 * its date vs. the window (`fahad_ai_return_window_days`, default 30), and the items
 * actually on the order. The assistant never fabricates a policy.
 */
final class Fahad_AI_Returns_Tools {

	/** Order meta key the RMA request list is recorded under. */
	private const RMA_META_KEY = '_fahad_ai_rma_requests';

	/** Default return window in days (overridable via `fahad_ai_return_window_days`). */
	private const DEFAULT_WINDOW_DAYS = 30;

	/**
	 * Append the returns tools to the registry's tool list.
	 *
	 * Registered as a pack provider (see the register_pack() call at file scope). Static
	 * because the pack holds no per-instance state — its tools call WooCommerce order
	 * functions and the shared Fahad_AI_Auth boundary directly.
	 *
	 * Both tools carry `'personal' => true` so the registry login-gates them centrally
	 * (the first authorization layer).
	 *
	 * @param array $tools Existing tool definitions.
	 * @return array Tools with the returns tools appended.
	 */
	public static function register( array $tools ): array {
		$tools[] = [
			'name'        => 'check_return_eligibility',
			'description' => 'Check whether one of the logged-in customer\'s orders — or a specific item on it — can be returned, judged against the store\'s return window and the order\'s status and date. Use this when the customer asks "can I return this?", about a refund, an exchange, or sending an item back. Returns whether it is eligible and, when it is NOT, a plain honest reason plus how to reach human support. Only works for an order that belongs to the signed-in customer; otherwise it reports the order was not found. This does NOT issue any refund. Requires the customer to be logged in.',
			'parameters'  => [
				'type'       => 'object',
				'properties' => [
					'order_id' => [ 'type' => 'integer', 'description' => 'ID of the customer\'s own order to check.' ],
					'item'     => [ 'type' => 'string', 'description' => 'Optional name of a single item on the order to check (omit to judge the whole order).' ],
				],
				'required'   => [ 'order_id' ],
			],
			'personal'    => true,
			'callback'    => fn( array $input ) => self::check_return_eligibility( $input ),
		];

		$tools[] = [
			'name'        => 'request_return',
			'description' => 'Record a return or exchange request (an RMA) for eligible items on one of the logged-in customer\'s own orders. Use this AFTER check_return_eligibility confirms the items are returnable and the customer has chosen to proceed. This ONLY records the request for the store team to process — it does NOT issue a refund, credit, or change the order; refunds remain a human decision. Recording is idempotent: requesting the same items again will not create a duplicate. Only works for an order that belongs to the signed-in customer. Requires the customer to be logged in.',
			'parameters'  => [
				'type'       => 'object',
				'properties' => [
					'order_id' => [ 'type' => 'integer', 'description' => 'ID of the customer\'s own order the items belong to.' ],
					'items'    => [
						'type'        => 'array',
						'description' => 'Names of the items on the order to request a return/exchange for.',
						'items'       => [ 'type' => 'string' ],
					],
					'reason'   => [ 'type' => 'string', 'description' => 'The customer\'s reason for the return/exchange (e.g. "too small", "faulty").' ],
				],
				'required'   => [ 'order_id', 'items' ],
			],
			'personal'    => true,
			'callback'    => fn( array $input ) => self::request_return( $input ),
		];

		return $tools;
	}

	// -------------------------------------------------------------------------
	// Tool implementations
	// -------------------------------------------------------------------------

	/**
	 * Is an order (or one item on it) returnable?
	 *
	 * Loads the order, enforces per-record ownership, then judges it against the
	 * data-driven policy (status + window) and — when an `item` is named — that the item is
	 * actually on the order. Eligible → { eligible:true, … }. Ineligible → an honest
	 * { eligible:false, reason, contact_support:true, support } so the assistant can give a
	 * truthful "no" and still route to a human (never blocking support).
	 *
	 * A missing or not-owned order collapses to the SAME "not found" error (never
	 * "forbidden"), so ownership is never disclosed — but even that answer carries the
	 * support path, because we must never block the route to a human.
	 *
	 * @return array
	 */
	private static function check_return_eligibility( array $input ): array {
		$order = self::load_owned_order( absint( $input['order_id'] ?? 0 ) );
		if ( ! $order instanceof WC_Order ) {
			return self::not_found();
		}

		$item = isset( $input['item'] ) ? sanitize_text_field( (string) $input['item'] ) : '';

		return self::evaluate( $order, $item );
	}

	/**
	 * Record a return/exchange request (an RMA) for eligible items — and NOTHING that
	 * touches money or the order's lifecycle.
	 *
	 * Flow: load + ownership → require at least one item → RE-CHECK eligibility (never
	 * record against an ineligible order) → idempotently append an RMA record to the order
	 * meta + a best-effort order note. The result reports the request was `recorded` with
	 * its `rma_id`; on a repeat of the same items it returns the EXISTING record with
	 * `already_requested:true`. It deliberately exposes no refund/credit field — there is
	 * none.
	 *
	 * @return array
	 */
	private static function request_return( array $input ): array {
		$order = self::load_owned_order( absint( $input['order_id'] ?? 0 ) );
		if ( ! $order instanceof WC_Order ) {
			return self::not_found();
		}

		$items = self::resolve_items( $order, $input['items'] ?? [] );
		if ( empty( $items ) ) {
			return [
				'error'           => __( 'Tell me which item(s) from this order you want to return.', 'fahad-ai-shopping-assistant-for-woocommerce' ),
				'recorded'        => false,
				'contact_support' => true,
				'support'         => self::support_message(),
			];
		}

		// Never record a return against an order that is not actually returnable. Re-judge
		// the whole order (status + window); an ineligible order escalates instead.
		$eligibility = self::evaluate( $order );
		if ( empty( $eligibility['eligible'] ) ) {
			// Surface the honest reason + support path; record NOTHING.
			$eligibility['recorded'] = false;
			return $eligibility;
		}

		$reason    = isset( $input['reason'] ) ? sanitize_textarea_field( (string) $input['reason'] ) : '';
		$signature = self::signature( $items );

		// Idempotency: if an identical request (same set of items) already exists, return
		// it unchanged instead of recording a duplicate.
		$existing = self::read_requests( $order );
		foreach ( $existing as $record ) {
			if ( isset( $record['signature'] ) && $record['signature'] === $signature ) {
				return [
					'recorded'          => true,
					'already_requested' => true,
					'rma_id'            => (string) ( $record['rma_id'] ?? '' ),
					'order_id'          => $order->get_id(),
					'items'             => $items,
					'message'           => __( 'A return request for these items has already been recorded; our team will be in touch.', 'fahad-ai-shopping-assistant-for-woocommerce' ),
					'contact_support'   => true,
					'support'           => self::support_message(),
				];
			}
		}

		$rma_id = self::new_rma_id( $order );
		$record = [
			'rma_id'    => $rma_id,
			'signature' => $signature,
			'items'     => $items,
			'reason'    => $reason,
			'status'    => 'requested', // a REQUEST only — never an approval/refund.
			'requested_by' => Fahad_AI_Auth::current_user_id(),
			'requested_at' => self::now(),
		];

		$existing[] = $record;
		self::write_requests( $order, $existing );

		// Best-effort customer-facing note for the store team. Failure here must not break
		// the recorded request (the meta is the source of truth), so it is fire-and-forget.
		if ( is_callable( [ $order, 'add_order_note' ] ) ) {
			$order->add_order_note(
				sprintf(
					/* translators: 1: RMA reference id, 2: comma-separated item list */
					__( 'Return request %1$s recorded via the AI assistant for: %2$s. No refund has been issued; awaiting store review.', 'fahad-ai-shopping-assistant-for-woocommerce' ),
					$rma_id,
					implode( ', ', $items )
				),
				false
			);
		}

		return [
			'recorded'        => true,
			'rma_id'          => $rma_id,
			'order_id'        => $order->get_id(),
			'items'           => $items,
			'message'         => __( 'Your return request has been recorded. Our team will review it and follow up — no refund is issued automatically.', 'fahad-ai-shopping-assistant-for-woocommerce' ),
			// Always keep the human path open for anything beyond recording the request.
			'contact_support' => true,
			'support'         => self::support_message(),
		];
	}

	// -------------------------------------------------------------------------
	// Eligibility (data-driven policy)
	// -------------------------------------------------------------------------

	/**
	 * Judge an owned order (optionally a single named item) against the policy.
	 *
	 * Order of checks, each producing an honest reason on failure:
	 *   1. status — must be one of `fahad_ai_return_eligible_statuses` (default
	 *      completed/processing). Cancelled/refunded/failed/pending are not returnable.
	 *   2. window — order date + `fahad_ai_return_window_days` (default 30) must not be in
	 *      the past relative to now.
	 *   3. item   — when an item name is given it must be on the order.
	 *
	 * @param WC_Order $order
	 * @param string   $item Optional item name to narrow to.
	 * @return array { eligible:bool, window_days:int, order_id:int, item?:string, reason?, contact_support?, support? }
	 */
	private static function evaluate( WC_Order $order, string $item = '' ): array {
		$window_days = self::window_days();

		$base = [
			'order_id'    => $order->get_id(),
			'window_days' => $window_days,
		];
		if ( '' !== $item ) {
			$base['item'] = $item;
		}

		// 1. Status gate.
		if ( ! in_array( $order->get_status(), self::eligible_statuses(), true ) ) {
			return $base + self::ineligible(
				__( 'This order\'s status means it is not eligible for a self-service return. Our support team can still help.', 'fahad-ai-shopping-assistant-for-woocommerce' )
			);
		}

		// 2. Window gate.
		$ordered_at = self::order_timestamp( $order );
		if ( null === $ordered_at ) {
			// No usable order date — don't guess eligibility; route to a human.
			return $base + self::ineligible(
				__( 'I can\'t confirm this order\'s date to check the return window, so our support team should take a look.', 'fahad-ai-shopping-assistant-for-woocommerce' )
			);
		}
		$deadline = $ordered_at + ( $window_days * DAY_IN_SECONDS );
		if ( self::now() > $deadline ) {
			return $base + self::ineligible(
				sprintf(
					/* translators: %d: the store's return window in days */
					__( 'This order is outside the %d-day return window, so it is not eligible for a self-service return. Our support team can still help.', 'fahad-ai-shopping-assistant-for-woocommerce' ),
					$window_days
				)
			);
		}

		// 3. Specific-item gate.
		if ( '' !== $item && ! self::order_has_item( $order, $item ) ) {
			return $base + self::ineligible(
				__( 'I can\'t find that item on this order, so I can\'t start a return for it. Our support team can help if something looks off.', 'fahad-ai-shopping-assistant-for-woocommerce' )
			);
		}

		return $base + [ 'eligible' => true ];
	}

	/**
	 * The ineligible-result tail: a plain reason plus the always-open human-support path.
	 * Centralised so every "no" answer is shaped identically and never omits support.
	 *
	 * @return array{ eligible:false, reason:string, contact_support:true, support:string }
	 */
	private static function ineligible( string $reason ): array {
		return [
			'eligible'        => false,
			'reason'          => $reason,
			'contact_support' => true,
			'support'         => self::support_message(),
		];
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	/**
	 * Load an order ONLY if it belongs to the current user.
	 *
	 * Returns the WC_Order on success, or null for a missing order OR one owned by a
	 * different user — the caller maps null to the SAME "not found" error so ownership is
	 * never disclosed and nothing is read/written for a foreign order. The central login
	 * gate has already ensured the caller is logged in by the time this runs.
	 */
	private static function load_owned_order( int $order_id ): ?WC_Order {
		if ( $order_id <= 0 ) {
			return null;
		}

		$order = wc_get_order( $order_id );

		if ( ! $order instanceof WC_Order || ! Fahad_AI_Auth::user_owns( $order->get_customer_id() ) ) {
			return null;
		}

		return $order;
	}

	/**
	 * Standard "not found" result. Deliberately not "forbidden" so it never confirms an
	 * order exists for another user; still carries the support path because we must never
	 * block the route to a human.
	 *
	 * @return array{ error:string, contact_support:true, support:string }
	 */
	private static function not_found(): array {
		return [
			'error'           => __( 'Order not found.', 'fahad-ai-shopping-assistant-for-woocommerce' ),
			'contact_support' => true,
			'support'         => self::support_message(),
		];
	}

	/**
	 * Resolve the requested item NAMES against the items actually on the order, preserving
	 * only those that match (case-insensitively). This both sanitises the model's input and
	 * guarantees an RMA can only ever name items that are genuinely on the order.
	 *
	 * @param mixed $items Raw `items` input from the model (expected: string[]).
	 * @return array<int,string> Distinct, on-order item names.
	 */
	private static function resolve_items( WC_Order $order, $items ): array {
		if ( ! is_array( $items ) ) {
			return [];
		}

		$on_order = [];
		foreach ( $order->get_items() as $line ) {
			if ( is_object( $line ) && is_callable( [ $line, 'get_name' ] ) ) {
				$name = trim( (string) $line->get_name() );
				if ( '' !== $name ) {
					$on_order[ strtolower( $name ) ] = $name;
				}
			}
		}

		$resolved = [];
		foreach ( $items as $raw ) {
			if ( ! is_scalar( $raw ) ) {
				continue;
			}
			$key = strtolower( trim( sanitize_text_field( (string) $raw ) ) );
			if ( '' !== $key && isset( $on_order[ $key ] ) && ! in_array( $on_order[ $key ], $resolved, true ) ) {
				$resolved[] = $on_order[ $key ];
			}
		}

		return $resolved;
	}

	/** Whether the order has a line item whose name matches `$item` (case-insensitively). */
	private static function order_has_item( WC_Order $order, string $item ): bool {
		$needle = strtolower( trim( $item ) );
		if ( '' === $needle ) {
			return false;
		}

		foreach ( $order->get_items() as $line ) {
			if ( is_object( $line ) && is_callable( [ $line, 'get_name' ] ) && strtolower( trim( (string) $line->get_name() ) ) === $needle ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * The configured return window in days. Default self::DEFAULT_WINDOW_DAYS, overridable
	 * via the documented `fahad_ai_return_window_days` filter. Coerced to a sane positive
	 * integer so a filter returning junk can't open an infinite (or negative) window.
	 */
	private static function window_days(): int {
		$days = (int) apply_filters( 'fahad_ai_return_window_days', self::DEFAULT_WINDOW_DAYS );
		return $days > 0 ? $days : self::DEFAULT_WINDOW_DAYS;
	}

	/**
	 * Order statuses that are eligible for a self-service return. Default completed +
	 * processing (a paid/fulfilled order), overridable via
	 * `fahad_ai_return_eligible_statuses`. Cancelled/refunded/failed/pending/on-hold are
	 * intentionally NOT default-eligible.
	 *
	 * @return array<int,string>
	 */
	private static function eligible_statuses(): array {
		$statuses = apply_filters( 'fahad_ai_return_eligible_statuses', [ 'completed', 'processing' ] );
		return is_array( $statuses ) && ! empty( $statuses ) ? array_values( array_map( 'strval', $statuses ) ) : [ 'completed', 'processing' ];
	}

	/**
	 * The order's creation timestamp (unix), or null when the order carries no usable date.
	 * WooCommerce returns a WC_DateTime (a DateTime subclass) exposing getTimestamp().
	 */
	private static function order_timestamp( WC_Order $order ): ?int {
		$date = $order->get_date_created();
		if ( $date instanceof \DateTimeInterface ) {
			return $date->getTimestamp();
		}
		return null;
	}

	/** Current unix time (UTC), via WordPress so it is stubbable/filterable in tests. */
	private static function now(): int {
		return (int) current_time( 'timestamp', true );
	}

	/**
	 * Read the recorded RMA request list off the order (always an array).
	 *
	 * @return array<int,array>
	 */
	private static function read_requests( WC_Order $order ): array {
		$stored = $order->get_meta( self::RMA_META_KEY );
		return is_array( $stored ) ? $stored : [];
	}

	/** Persist the RMA request list to the order meta and save. */
	private static function write_requests( WC_Order $order, array $requests ): void {
		$order->update_meta_data( self::RMA_META_KEY, $requests );
		if ( is_callable( [ $order, 'save' ] ) ) {
			$order->save();
		}
	}

	/**
	 * A stable signature for a set of item names so repeat requests are detected regardless
	 * of order/case. This is what makes recording idempotent.
	 */
	private static function signature( array $items ): string {
		$norm = array_map( fn( $n ) => strtolower( trim( (string) $n ) ), $items );
		sort( $norm );
		return md5( implode( '|', $norm ) );
	}

	/** A human-readable RMA reference, unique per order + sequence. */
	private static function new_rma_id( WC_Order $order ): string {
		$seq = count( self::read_requests( $order ) ) + 1;
		return sprintf( 'RMA-%d-%d', $order->get_id(), $seq );
	}

	/** The standard "reach a human" message — support is always available, never blocked. */
	private static function support_message(): string {
		return __( 'If you need more help, our support team can assist with your return or refund.', 'fahad-ai-shopping-assistant-for-woocommerce' );
	}
}

// Self-register this feature pack the moment the file is loaded. The bootstrap (and the
// test bootstrap) glob-require includes/tools/*.php, so dropping this file in is the ONLY
// wiring needed — no bootstrap or harness edits.
// @codeCoverageIgnoreStart
// Reason: file-scope self-registration runs once at bootstrap require time, before pcov's per-test window opens; its effect is asserted in ReturnsToolsTest::test_returns_tools_are_registered_via_register_pack.
Fahad_AI_Tool_Registry::register_pack( [ 'Fahad_AI_Returns_Tools', 'register' ] );
// @codeCoverageIgnoreEnd
