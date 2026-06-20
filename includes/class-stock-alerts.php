<?php
defined( 'ABSPATH' ) || exit;

/**
 * Back-in-stock & price-drop alert subscriptions — store + notifier (issue #51).
 *
 * The persistence + email engine behind the `subscribe_stock_alert` agent tool
 * (Fahad_AI_Stock_Alert_Tools). The tool decides WHETHER an alert is legitimate
 * (no fake scarcity: a back-in-stock alert is refused for an in-stock item); THIS
 * class owns the consented subscription lifecycle and the WooCommerce hooks that
 * actually fire a notification on a real stock/price change.
 *
 * ─── ANTI-SPAM: DOUBLE OPT-IN ───────────────────────────────────────────────────
 *
 * subscribe() records a PENDING row only. NOTHING is ever emailed to a pending
 * subscriber except — by the tool/UI — a single confirm link. A notification can
 * fire only after confirm() flips the row to `confirmed` via a signed token the
 * shopper clicked. This is the anti-spam guarantee: a third party cannot weaponise
 * the store to mail an address that never opted in.
 *
 * ─── SIGNED TOKENS (confirm + one-click unsubscribe) ─────────────────────────────
 *
 * token($action,$id) returns an HMAC (hash_hmac sha256) over "$action|$id", keyed
 * by a site secret derived with wp_hash(). The signature is therefore bound to BOTH
 * the subscription id AND the action, so a confirm token cannot be replayed as an
 * unsubscribe token (or vice-versa), and a token for one subscription cannot act on
 * another. verify_token() uses hash_equals() (constant-time) so the check does not
 * leak via timing. The handlers (handle_confirm / handle_unsubscribe) read the id +
 * token from query vars on `init` and respond with a small confirmation page.
 *
 * ─── EMAIL HYGIENE + DEDUPE ──────────────────────────────────────────────────────
 *
 * Emails are validated with is_email() and sanitized with sanitize_email() before
 * storage; an invalid address is refused and stores nothing. The dedupe key is
 * email|product_id|variation_id|type, so the same shopper watching the same item
 * for the same reason subscribes ONCE — a second subscribe is idempotent.
 *
 * ─── NOTIFY ON A REAL CHANGE, MARK SENT ──────────────────────────────────────────
 *
 * WooCommerce stock hooks (woocommerce_product_set_stock /
 * woocommerce_variation_set_stock) call notify_back_in_stock() when an item becomes
 * available; a price-change check calls notify_price_drop() only when the NEW price
 * is genuinely lower than the old. Each emails only CONFIRMED, not-yet-sent matches,
 * then stamps `sent` so a subscriber is notified once per restock/drop, never
 * speculatively and never twice for the same event.
 *
 * ─── GDPR ────────────────────────────────────────────────────────────────────────
 *
 * erase_email() removes every row for an address (wired to the WP personal-data
 * eraser). uninstall.php drops the option entirely.
 *
 * ─── PRIVACY BOUNDARY ────────────────────────────────────────────────────────────
 *
 * Subscriber emails are PII: they live ONLY in this option and in the wp_mail
 * envelope. They are never returned to the model — the tool echoes back at most a
 * masked address (Fahad_AI_Auth::mask_email).
 *
 * Storage: a single autoload=no option holding an id => row map. This keeps the
 * feature self-contained and trivially testable (no custom table, no migration) at
 * the scale a per-product watch list realistically reaches.
 */
final class Fahad_AI_Stock_Alerts {

	/** Option name holding the id => subscription-row map (autoload off). */
	public const OPTION = 'fahad_ai_stock_alert_subs';

	/** Query var that routes a request to a confirm / unsubscribe handler. */
	public const QUERY_VAR = 'fahad_ai_stock_alert';

	/** Valid subscription types. */
	public const TYPE_BACK_IN_STOCK = 'back_in_stock';
	public const TYPE_PRICE_DROP     = 'price_drop';

	/** Hard cap on stored subscriptions (storage hygiene — bound the option). */
	public const MAX_SUBSCRIPTIONS = 5000;

	private static ?Fahad_AI_Stock_Alerts $instance = null;

	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {}

	/**
	 * Register the WooCommerce + WordPress hooks that drive notifications and the
	 * confirm / unsubscribe handlers.
	 *
	 * Called once from the tool pack file at load (which is glob-required by the
	 * bootstrap), so the feature is fully self-wired with NO edits to the plugin
	 * bootstrap. Idempotent-friendly: only ever attaches each hook once per request
	 * because it is called exactly once.
	 */
	public static function init_hooks(): void {
		$self = self::instance();

		// Real stock changes → back-in-stock notifications. WooCommerce fires these
		// after a product's / variation's stock is set; we re-check availability and
		// only notify when the item is genuinely back in stock.
		add_action( 'woocommerce_product_set_stock',   [ $self, 'on_product_stock_change' ], 20, 1 );
		add_action( 'woocommerce_variation_set_stock', [ $self, 'on_product_stock_change' ], 20, 1 );

		// Real price changes → price-drop notifications. WooCommerce has no dedicated
		// "price dropped" hook, so we snapshot the price before a product is saved and
		// compare after; a notification fires only on a genuine decrease.
		add_action( 'woocommerce_before_product_object_save', [ $self, 'snapshot_price' ], 10, 1 );
		add_action( 'woocommerce_update_product',             [ $self, 'on_product_price_change' ], 20, 1 );
		add_action( 'woocommerce_update_product_variation',   [ $self, 'on_product_price_change' ], 20, 1 );

		// One-click confirm / unsubscribe links resolve here (front-end GET, no auth —
		// the signed token IS the authorization).
		add_action( 'init', [ $self, 'maybe_handle_request' ] );

		// GDPR: register the personal-data eraser so a store can fulfil an erasure
		// request for a subscriber's email.
		add_filter( 'wp_privacy_personal_data_erasers', [ $self, 'register_eraser' ] );
	}

	// -------------------------------------------------------------------------
	// Subscription lifecycle
	// -------------------------------------------------------------------------

	/**
	 * Create a PENDING (double-opt-in) subscription.
	 *
	 * Validates + sanitizes the email, normalises the type, dedupes on
	 * email|product|variation|type, and stores a pending row. Returns the row id and
	 * a confirm token so the caller can email a confirm link — it does NOT email
	 * anything itself (the tool/UI owns the confirm message), and it does NOT
	 * activate the alert (status stays `pending` until confirm()).
	 *
	 * @return array{ ok:bool, id?:string, confirm_token?:string, error?:string }
	 */
	public function subscribe( int $product_id, string $email, int $variation_id = 0, string $type = self::TYPE_BACK_IN_STOCK ): array {
		$email = sanitize_email( $email );
		if ( ! is_email( $email ) ) {
			return [
				'ok'    => false,
				'error' => __( 'That email address looks invalid.', 'fahad-ai-shopping-assistant-for-woocommerce' ),
			];
		}

		$type = $this->normalize_type( $type );
		if ( '' === $type ) {
			return [
				'ok'    => false,
				'error' => __( 'Unknown alert type.', 'fahad-ai-shopping-assistant-for-woocommerce' ),
			];
		}

		$product_id   = absint( $product_id );
		$variation_id = absint( $variation_id );

		$subs = $this->all();
		$id   = $this->dedupe_id( $email, $product_id, $variation_id, $type );

		// Dedupe: re-subscribing the same watch is idempotent. Keep the existing row's
		// status (do not silently re-arm a confirmed/sent row).
		if ( ! isset( $subs[ $id ] ) ) {
			if ( count( $subs ) >= self::MAX_SUBSCRIPTIONS ) {
				return [
					'ok'    => false,
					'error' => __( 'Alerts are temporarily unavailable. Please try again later.', 'fahad-ai-shopping-assistant-for-woocommerce' ),
				];
			}

			$subs[ $id ] = [
				'id'           => $id,
				'product_id'   => $product_id,
				'variation_id' => $variation_id,
				'email'        => $email,
				'type'         => $type,
				'status'       => 'pending',
				'created'      => time(),
				'confirmed'    => 0,
				'sent'         => 0,
			];
			$this->save( $subs );
		}

		return [
			'ok'            => true,
			'id'            => $id,
			'confirm_token' => $this->token( 'confirm', $id ),
		];
	}

	/**
	 * Confirm a pending subscription (double-opt-in completion). The signed `confirm`
	 * token must validate for this id. A non-existent id or a bad token is a no-op.
	 */
	public function confirm( string $id, string $token ): bool {
		if ( ! $this->verify_token( 'confirm', $id, $token ) ) {
			return false;
		}

		$subs = $this->all();
		if ( ! isset( $subs[ $id ] ) ) {
			return false;
		}

		$subs[ $id ]['status']    = 'confirmed';
		$subs[ $id ]['confirmed'] = time();
		// A fresh confirmation re-arms the alert (clear any prior sent stamp).
		$subs[ $id ]['sent']      = 0;
		$this->save( $subs );

		return true;
	}

	/**
	 * One-click unsubscribe. The signed `unsubscribe` token must validate for this
	 * id (a confirm token will NOT — tokens are bound to their action). Removes the
	 * row entirely. A bad token removes nothing.
	 */
	public function unsubscribe( string $id, string $token ): bool {
		if ( ! $this->verify_token( 'unsubscribe', $id, $token ) ) {
			return false;
		}

		$subs = $this->all();
		if ( ! isset( $subs[ $id ] ) ) {
			return false;
		}

		unset( $subs[ $id ] );
		$this->save( $subs );

		return true;
	}

	/**
	 * GDPR erase: remove EVERY subscription for an email address (case-insensitive).
	 * Returns the number of rows removed.
	 */
	public function erase_email( string $email ): int {
		$email = sanitize_email( $email );
		if ( '' === $email ) {
			return 0;
		}

		$subs    = $this->all();
		$removed = 0;
		foreach ( $subs as $id => $row ) {
			if ( isset( $row['email'] ) && strtolower( $row['email'] ) === strtolower( $email ) ) {
				unset( $subs[ $id ] );
				++$removed;
			}
		}

		if ( $removed > 0 ) {
			$this->save( $subs );
		}

		return $removed;
	}

	// -------------------------------------------------------------------------
	// Notification (only confirmed matches, only on a real change, mark sent)
	// -------------------------------------------------------------------------

	/**
	 * Notify every CONFIRMED, not-yet-sent back-in-stock subscriber for a product /
	 * variation that is now available, then stamp each `sent` so they are emailed
	 * once per restock — never speculatively, never twice for the same event.
	 *
	 * @return int Number of subscribers notified.
	 */
	public function notify_back_in_stock( int $product_id, int $variation_id = 0 ): int {
		return $this->dispatch_notifications(
			self::TYPE_BACK_IN_STOCK,
			$product_id,
			$variation_id,
			fn( array $row ) => $this->back_in_stock_email( $row )
		);
	}

	/**
	 * Notify CONFIRMED price-drop subscribers — but ONLY when the new price is
	 * genuinely lower than the old (no fabricated "drop"). Marks each sent.
	 *
	 * @return int Number of subscribers notified.
	 */
	public function notify_price_drop( int $product_id, int $variation_id, float $old_price, float $new_price ): int {
		// A real drop only. Equal or higher → notify nobody.
		if ( ! ( $new_price < $old_price ) || $new_price <= 0.0 ) {
			return 0;
		}

		return $this->dispatch_notifications(
			self::TYPE_PRICE_DROP,
			$product_id,
			$variation_id,
			fn( array $row ) => $this->price_drop_email( $row, $old_price, $new_price )
		);
	}

	/**
	 * Shared matcher + sender: email each confirmed, unsent row of $type for the
	 * given product/variation, then stamp `sent`. The $compose callback returns
	 * [ subject, body ] for one row.
	 *
	 * @param callable $compose fn( array $row ): array{0:string,1:string}
	 */
	private function dispatch_notifications( string $type, int $product_id, int $variation_id, callable $compose ): int {
		$subs    = $this->all();
		$changed = false;
		$sent    = 0;
		$now     = time();

		foreach ( $subs as $id => $row ) {
			if ( ( $row['type'] ?? '' ) !== $type ) {
				continue;
			}
			if ( 'confirmed' !== ( $row['status'] ?? '' ) ) {
				continue; // never email a pending (unconfirmed) subscriber
			}
			if ( ! empty( $row['sent'] ) ) {
				continue; // already notified for this restock/drop
			}
			if ( (int) ( $row['product_id'] ?? 0 ) !== $product_id ) {
				continue;
			}
			// A subscription to a specific variation matches only that variation; a
			// product-level (variation_id 0) subscription matches the product event.
			if ( (int) ( $row['variation_id'] ?? 0 ) !== $variation_id ) {
				continue;
			}

			[ $subject, $body ] = $compose( $row );
			wp_mail( $row['email'], $subject, $body, [ 'Content-Type: text/html; charset=UTF-8' ] );

			$subs[ $id ]['sent'] = $now;
			$changed             = true;
			++$sent;
		}

		if ( $changed ) {
			$this->save( $subs );
		}

		return $sent;
	}

	// -------------------------------------------------------------------------
	// WooCommerce hook callbacks
	// -------------------------------------------------------------------------

	/**
	 * woocommerce_product_set_stock / woocommerce_variation_set_stock callback. The
	 * product object is passed; if it is now in stock, notify its back-in-stock
	 * watchers. A variation notifies its variation watchers; a simple product
	 * notifies product-level watchers.
	 *
	 * @param mixed $product A WC_Product (or variation) object.
	 */
	public function on_product_stock_change( $product ): void {
		if ( ! $product instanceof WC_Product ) {
			return;
		}
		if ( ! $product->is_in_stock() ) {
			return; // only a genuine "back in stock" transition matters
		}

		$id     = $product->get_id();
		$parent = $product->get_parent_id();

		if ( $product->is_type( 'variation' ) && $parent > 0 ) {
			$this->notify_back_in_stock( $parent, $id );
		} else {
			$this->notify_back_in_stock( $id, 0 );
		}
	}

	/**
	 * woocommerce_before_product_object_save callback: snapshot the product's current
	 * price BEFORE the save so on_product_price_change can detect a decrease. Stored
	 * in a per-request map keyed by product id.
	 *
	 * @param mixed $product A WC_Product about to be saved.
	 */
	public function snapshot_price( $product ): void {
		if ( ! $product instanceof WC_Product ) {
			return;
		}
		$pid = $product->get_id();
		if ( $pid > 0 ) {
			$this->price_snapshots[ $pid ] = (float) $product->get_price();
		}
	}

	/**
	 * woocommerce_update_product / woocommerce_update_product_variation callback:
	 * compare the post-save price against the pre-save snapshot and, on a genuine
	 * drop, notify price-drop watchers.
	 *
	 * @param int $product_id The saved product / variation id.
	 */
	public function on_product_price_change( $product_id ): void {
		$product_id = absint( $product_id );
		if ( $product_id <= 0 || ! array_key_exists( $product_id, $this->price_snapshots ) ) {
			return;
		}

		$old = $this->price_snapshots[ $product_id ];
		unset( $this->price_snapshots[ $product_id ] );

		$product = function_exists( 'wc_get_product' ) ? wc_get_product( $product_id ) : null;
		if ( ! $product instanceof WC_Product ) {
			return;
		}

		$new    = (float) $product->get_price();
		$parent = $product->get_parent_id();

		if ( $product->is_type( 'variation' ) && $parent > 0 ) {
			$this->notify_price_drop( $parent, $product_id, $old, $new );
		} else {
			$this->notify_price_drop( $product_id, 0, $old, $new );
		}
	}

	/** Per-request pre-save price snapshots, keyed by product id. @var array<int,float> */
	private array $price_snapshots = [];

	// -------------------------------------------------------------------------
	// Confirm / unsubscribe request handling (init)
	// -------------------------------------------------------------------------

	/**
	 * `init` callback. If the request carries our query var, resolve a confirm or
	 * unsubscribe action from the (sanitized) id + token query args and render a
	 * minimal confirmation page, then exit. No nonce/login: the signed token IS the
	 * authorization, and the action is idempotent + non-destructive beyond the
	 * subscriber's own row.
	 */
	public function maybe_handle_request(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- the signed token is the auth.
		if ( empty( $_GET[ self::QUERY_VAR ] ) ) {
			return;
		}

		$action = sanitize_text_field( wp_unslash( $_GET[ self::QUERY_VAR ] ) );
		$id     = isset( $_GET['sub'] ) ? sanitize_text_field( wp_unslash( $_GET['sub'] ) ) : '';
		$token  = isset( $_GET['token'] ) ? sanitize_text_field( wp_unslash( $_GET['token'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		if ( 'confirm' === $action ) {
			$ok      = $this->confirm( $id, $token );
			$message = $ok
				? __( 'Thanks! Your alert is confirmed. We will email you when it triggers.', 'fahad-ai-shopping-assistant-for-woocommerce' )
				: __( 'This confirmation link is invalid or has expired.', 'fahad-ai-shopping-assistant-for-woocommerce' );
			$this->render_page( $message );
			return;
		}

		if ( 'unsubscribe' === $action ) {
			$ok      = $this->unsubscribe( $id, $token );
			$message = $ok
				? __( 'You have been unsubscribed from this alert.', 'fahad-ai-shopping-assistant-for-woocommerce' )
				: __( 'This unsubscribe link is invalid or has expired.', 'fahad-ai-shopping-assistant-for-woocommerce' );
			$this->render_page( $message );
			return;
		}
	}

	/** A confirm/unsubscribe URL carrying the signed token for one subscription. */
	public function action_url( string $action, string $id ): string {
		return esc_url_raw( add_query_arg(
			[
				self::QUERY_VAR => $action,
				'sub'           => $id,
				'token'         => $this->token( $action, $id ),
			],
			home_url( '/' )
		) );
	}

	/**
	 * Render a tiny standalone confirmation page and stop. Kept minimal and escaped;
	 * only reached for a real confirm/unsubscribe click.
	 */
	private function render_page( string $message ): void {
		if ( ! function_exists( 'wp_die' ) ) {
			// @codeCoverageIgnoreStart
			// Reason: wp_die is a real WP function the shared test setUp stubs via Brain\Monkey, which defines it permanently in the global namespace; a defined global function cannot be undefined mid-process, so function_exists() never returns false here and this defensive return is structurally unreachable in-process.
			return; // defensive: never reached outside WP
			// @codeCoverageIgnoreEnd
		}
		wp_die(
			esc_html( $message ),
			esc_html__( 'Stock alert', 'fahad-ai-shopping-assistant-for-woocommerce' ),
			[ 'response' => 200 ]
		);
	}

	// -------------------------------------------------------------------------
	// GDPR eraser
	// -------------------------------------------------------------------------

	/**
	 * Register the personal-data eraser so WordPress' privacy tools can remove a
	 * subscriber's alerts on an erasure request.
	 *
	 * @param array $erasers Existing erasers.
	 * @return array
	 */
	public function register_eraser( array $erasers ): array {
		$erasers['fahad-ai-stock-alerts'] = [
			'eraser_friendly_name' => __( 'Fahad AI stock alerts', 'fahad-ai-shopping-assistant-for-woocommerce' ),
			'callback'             => [ $this, 'gdpr_erase' ],
		];
		return $erasers;
	}

	/**
	 * Personal-data eraser callback: delete every alert for the email.
	 *
	 * @param string $email The address being erased.
	 * @param int    $page  Pagination (single page — we erase all at once).
	 * @return array WordPress eraser response shape.
	 */
	public function gdpr_erase( string $email, int $page = 1 ): array {
		$removed = $this->erase_email( $email );

		return [
			'items_removed'  => $removed > 0,
			'items_retained' => false,
			'messages'       => [],
			'done'           => true,
		];
	}

	// -------------------------------------------------------------------------
	// Tokens (HMAC, bound to action + id)
	// -------------------------------------------------------------------------

	/**
	 * Signed token for an action ('confirm' | 'unsubscribe') on a subscription id.
	 * HMAC-SHA256 over "$action|$id" keyed by a site secret. Bound to the action AND
	 * the id, so it cannot be replayed for a different action or subscription.
	 */
	public function token( string $action, string $id ): string {
		return hash_hmac( 'sha256', $action . '|' . $id, $this->secret() );
	}

	/** Constant-time token verification (hash_equals) for $action on $id. */
	private function verify_token( string $action, string $id, string $token ): bool {
		if ( '' === $id || '' === $token ) {
			return false;
		}
		return hash_equals( $this->token( $action, $id ), $token );
	}

	/** Site secret for the token HMAC, derived via wp_hash so it is salted per-site. */
	private function secret(): string {
		return wp_hash( 'fahad_ai_stock_alert_secret' );
	}

	// -------------------------------------------------------------------------
	// Email composition (no PII to the model — these are real emails to the user)
	// -------------------------------------------------------------------------

	/** @return array{0:string,1:string} [ subject, body ] for a back-in-stock email. */
	private function back_in_stock_email( array $row ): array {
		$site    = get_bloginfo( 'name' );
		$subject = sprintf(
			/* translators: %s: store name. */
			__( 'Back in stock at %s', 'fahad-ai-shopping-assistant-for-woocommerce' ),
			$site
		);
		$intro = __( 'Good news — an item you wanted is back in stock.', 'fahad-ai-shopping-assistant-for-woocommerce' );

		return [ $subject, $this->wrap_email( $intro, $row ) ];
	}

	/** @return array{0:string,1:string} [ subject, body ] for a price-drop email. */
	private function price_drop_email( array $row, float $old_price, float $new_price ): array {
		$site    = get_bloginfo( 'name' );
		$subject = sprintf(
			/* translators: %s: store name. */
			__( 'Price drop at %s', 'fahad-ai-shopping-assistant-for-woocommerce' ),
			$site
		);
		$intro = __( 'Good news — the price dropped on an item you were watching.', 'fahad-ai-shopping-assistant-for-woocommerce' );

		return [ $subject, $this->wrap_email( $intro, $row ) ];
	}

	/**
	 * Wrap an email body with the intro line and a one-click unsubscribe link.
	 * Escaped for HTML email.
	 */
	private function wrap_email( string $intro, array $row ): string {
		$unsub = $this->action_url( 'unsubscribe', $row['id'] );

		return wp_kses_post(
			'<p>' . esc_html( $intro ) . '</p>' .
			'<p style="font-size:12px;color:#666">' .
			esc_html( __( 'Don\'t want these alerts? Unsubscribe in one click:', 'fahad-ai-shopping-assistant-for-woocommerce' ) ) .
			' <a href="' . esc_url( $unsub ) . '">' . esc_html( $unsub ) . '</a></p>'
		);
	}

	// -------------------------------------------------------------------------
	// Storage helpers
	// -------------------------------------------------------------------------

	/**
	 * All subscription rows as an id => row map. Defends against a corrupted /
	 * non-array option by returning an empty map.
	 *
	 * @return array<string, array>
	 */
	private function all(): array {
		$subs = get_option( self::OPTION, [] );
		return is_array( $subs ) ? $subs : [];
	}

	/** Persist the id => row map (autoload off — this can grow). */
	private function save( array $subs ): void {
		update_option( self::OPTION, $subs, false );
	}

	/**
	 * Deterministic dedupe id for a watch: a hash of
	 * email|product_id|variation_id|type. Deterministic so re-subscribing the same
	 * watch maps to the same row (idempotent) without scanning the whole map.
	 */
	private function dedupe_id( string $email, int $product_id, int $variation_id, string $type ): string {
		return md5( strtolower( $email ) . '|' . $product_id . '|' . $variation_id . '|' . $type );
	}

	/** Normalise a type to one of the two valid values, or '' when unknown. */
	private function normalize_type( string $type ): string {
		$type = strtolower( trim( $type ) );
		if ( self::TYPE_BACK_IN_STOCK === $type || self::TYPE_PRICE_DROP === $type ) {
			return $type;
		}
		return '';
	}
}
