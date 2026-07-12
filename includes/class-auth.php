<?php
defined( 'ABSPATH' ) || exit;

/**
 * Privacy / authorization BOUNDARY for personal-data tools.
 *
 * The public chat endpoints are intentionally open to guests (see
 * Fahad_AI_Chatbot::authorize_request), the nonce + rate limit there are
 * CSRF / abuse protection, NOT an authorization boundary. This class is the
 * reusable mechanism that tools exposing PERSONAL data (order status #17,
 * wallet #18, cross-session memory #20) use to decide WHO may see WHAT.
 *
 * Two layers, by design (defence in depth):
 *
 *   1. Login gate (central). A tool definition may declare `'personal' => true`.
 *      Fahad_AI_Tool_Registry::dispatch() then calls guard_logged_in() BEFORE the
 *      callback, so a guest is blocked centrally and a personal tool cannot forget
 *      to check login.
 *
 *   2. Per-record ownership (in the callback). The registry cannot know, in
 *      general, which user a given order / wallet / memory row belongs to, so each
 *      personal tool computes the record's owner (e.g. $order->get_customer_id())
 *      and calls user_owns() before returning anything. This is what stops a
 *      logged-in user reading ANOTHER user's record.
 *
 * Stateless utility (all static): it only wraps WordPress' current-user
 * functions and holds no cached state, unlike the singletons elsewhere in the
 * plugin. That also keeps it trivial to unit-test by stubbing is_user_logged_in()
 * / get_current_user_id().
 */
final class Fahad_AI_Auth {

	/**
	 * Whether the current request is from a logged-in user.
	 */
	public static function is_logged_in(): bool {
		return (bool) is_user_logged_in();
	}

	/**
	 * Current user id (0 for guests), wrapping get_current_user_id().
	 */
	public static function current_user_id(): int {
		return (int) get_current_user_id();
	}

	/**
	 * Central login gate for personal-data tools.
	 *
	 * Returns boolean true when the caller is logged in, otherwise a STANDARD
	 * error array a tool (or the registry) can return to the model directly. We
	 * return an array, not a WP_Error, because tool results are arrays; the
	 * `requires_login` flag lets the widget/model distinguish "please sign in"
	 * from a generic failure and steer the user to log in (a grounded escalate).
	 *
	 * @return true|array{error: string, requires_login: true}
	 */
	public static function guard_logged_in(): bool|array {
		if ( self::is_logged_in() ) {
			return true;
		}

		return [
			'error'          => __(
				'Please log in to access your account information.',
				'fahad-ai-shopping-assistant-for-woocommerce'
			),
			'requires_login' => true,
		];
	}

	/**
	 * Generic per-record ownership primitive.
	 *
	 * True iff `$owner_id` (e.g. an order's customer id) equals the current, or
	 * explicitly supplied, user id AND that user id is a real, logged-in user
	 * (> 0). A guest (id 0) never owns anything, even a record whose owner id is
	 * also 0, which prevents an unauthenticated request from matching orphaned /
	 * guest-owned rows.
	 *
	 * Callers use this for the second authorization layer, e.g.:
	 *
	 *     if ( ! Fahad_AI_Auth::user_owns( $order->get_customer_id() ) ) {
	 *         return [ 'error' => __( 'Order not found.', '…' ) ];
	 *     }
	 *
	 * @param int      $owner_id The record owner's user id.
	 * @param int|null $user_id  User id to check; defaults to the current user.
	 */
	public static function user_owns( int $owner_id, ?int $user_id = null ): bool {
		$uid = $user_id ?? self::current_user_id();

		return $uid > 0 && $owner_id === $uid;
	}

	/**
	 * Mask an email for safe inclusion in model context / logs (PII minimization).
	 *
	 * Keeps the first character of the local part and the full domain, masking the
	 * rest: `jane@example.com` → `j***@example.com`. Empty input returns an empty
	 * string; input without a single clear `local@domain` shape is fully masked to
	 * `***` rather than echoed back, so a malformed value can never leak verbatim.
	 *
	 * This is deliberately minimal, a helper, not a redaction framework.
	 */
	public static function mask_email( string $email ): string {
		$email = trim( $email );

		if ( '' === $email ) {
			return '';
		}

		$at = strpos( $email, '@' );

		// No usable local@domain split → don't echo the raw value back.
		if ( false === $at || 0 === $at || $at === strlen( $email ) - 1 ) {
			return '***';
		}

		$local  = substr( $email, 0, $at );
		$domain = substr( $email, $at + 1 );

		return $local[0] . '***@' . $domain;
	}

	/**
	 * The store-wide daily cap on billable AI answers (issues #194, #196). The base is the
	 * owner-set 'Daily message limit' setting (0 = unlimited, the default); the
	 * fahad_ai_daily_message_cap filter can still override it for code-level users. Negative
	 * values are clamped to 0.
	 */
	public static function daily_cap(): int {
		$configured = (int) get_option( 'fahad_ai_daily_message_cap', 0 );
		return max( 0, (int) apply_filters( 'fahad_ai_daily_message_cap', $configured ) );
	}

	/**
	 * How many billable AI answers have been served today. Backed by a single option
	 * that carries the day it belongs to, so the count resets automatically at the day
	 * boundary with no cron: a record from any day but today reads as 0.
	 */
	public static function daily_count(): int {
		$data = get_option( 'fahad_ai_daily_count', [] );
		if ( is_array( $data ) && ( $data['date'] ?? '' ) === gmdate( 'Ymd' ) ) {
			return (int) ( $data['count'] ?? 0 );
		}
		return 0;
	}

	/** Whether the store has hit its daily cap (never true when unlimited). */
	public static function daily_cap_reached(): bool {
		$cap = self::daily_cap();
		return $cap > 0 && self::daily_count() >= $cap;
	}

	/**
	 * Whether today's usage is nearing the cap (issue #210), so the owner can raise it
	 * before the assistant starts turning shoppers away at peak time. True only when a cap
	 * is set and the count is at/above the warn ratio (default 0.8, filter
	 * fahad_ai_cap_warn_ratio, clamped to 0..1). Stays true once the cap is fully reached.
	 */
	public static function daily_cap_approaching(): bool {
		$cap = self::daily_cap();
		if ( $cap <= 0 ) {
			return false;
		}
		$ratio = (float) apply_filters( 'fahad_ai_cap_warn_ratio', 0.8 );
		$ratio = min( 1.0, max( 0.0, $ratio ) );
		return self::daily_count() >= (int) ceil( $cap * $ratio );
	}

	/**
	 * Count one billable AI answer against today's total, resetting the counter when the
	 * stored record belongs to an earlier day. Autoload is off; this option changes often.
	 */
	public static function record_daily_message(): void {
		$today = gmdate( 'Ymd' );
		$data  = get_option( 'fahad_ai_daily_count', [] );
		$count = ( is_array( $data ) && ( $data['date'] ?? '' ) === $today ) ? (int) ( $data['count'] ?? 0 ) : 0;
		update_option( 'fahad_ai_daily_count', [ 'date' => $today, 'count' => $count + 1 ], false );
	}
}
