<?php
defined( 'ABSPATH' ) || exit;

// The subscription store + notifier this pack drives. Required here (not from the
// plugin bootstrap) so the WHOLE feature drops in as files under includes/ — the
// bootstrap glob-requires includes/tools/*.php, this file is one of them, and it
// pulls in the store. No edits to the plugin bootstrap or the test bootstrap.
require_once dirname( __DIR__ ) . '/class-stock-alerts.php';

/**
 * Back-in-stock & price-drop alert tool (issue #51) — the agent-facing surface.
 *
 * A drop-in feature pack (same pattern as Fahad_AI_Catalog_Tools): a self-contained
 * class in its own file under includes/tools/ that self-registers a provider at the
 * bottom via Fahad_AI_Tool_Registry::register_pack(). The bootstrap (and the test
 * bootstrap) glob-require everything here, so adding this pack is just new files —
 * NO edits to the plugin bootstrap, the test bootstrap, the registry, or the agent
 * loop. The persistence + notification engine lives in Fahad_AI_Stock_Alerts; this
 * class is only the tool the model calls.
 *
 * Tool provided:
 *   - subscribe_stock_alert(product_id, email, variation_id?, type?) — register a
 *     CONSENTED, double-opt-in alert for an out-of-stock product/variation
 *     (back_in_stock, default) or a price drop (price_drop), then tell the shopper
 *     to confirm via the email we send.
 *
 * ─── NO FAKE SCARCITY (issue #51, ROADMAP §6) ────────────────────────────────────
 *
 * A back_in_stock alert is REFUSED for an item that is currently IN stock — there is
 * nothing to wait for, and offering an alert would manufacture urgency. Only a
 * genuinely out-of-stock item is a valid back-in-stock subscription. A price_drop
 * alert is allowed regardless of stock state (watching for a lower price is not
 * scarcity). The product is loaded from WooCommerce so the in-stock check is grounded
 * in real data, never the model's assumption.
 *
 * ─── CONSENT / ANTI-SPAM ─────────────────────────────────────────────────────────
 *
 * The tool records a PENDING subscription only and asks the shopper to confirm via a
 * link emailed to them (double opt-in, handled by Fahad_AI_Stock_Alerts). It does
 * NOT activate the alert. This — not the model's word — is the anti-spam guarantee.
 *
 * ─── PRIVACY ─────────────────────────────────────────────────────────────────────
 *
 * The shopper's email is PII. The tool RESULT (which the model sees) never echoes the
 * raw address — at most a masked form via Fahad_AI_Auth::mask_email — so the address
 * stays out of model context. The real (unmasked) address lives only in the store and
 * the confirmation email envelope.
 *
 * This tool is PUBLIC (not `'personal' => true`): a guest can ask to be alerted by
 * giving an email, so it is not login-gated. Authorization for the later
 * confirm/unsubscribe actions is the signed token, not a session.
 */
final class Fahad_AI_Stock_Alert_Tools {

	/**
	 * Append the stock-alert tool to the registry's tool list.
	 *
	 * Registered as a pack provider (see register_pack() at file scope). Static —
	 * the pack holds no per-instance state; it delegates to the
	 * Fahad_AI_Stock_Alerts singleton.
	 *
	 * @param array $tools Existing tool definitions.
	 * @return array Tools with the stock-alert tool appended.
	 */
	public static function register( array $tools ): array {
		$tools[] = [
			'name'        => 'subscribe_stock_alert',
			'description' => 'Register the customer\'s interest in an item that is OUT OF STOCK (a back-in-stock alert) or watch an item for a PRICE DROP, emailing them when it happens. Use this ONLY when the customer asks to be notified, and ONLY offer a back-in-stock alert for an item that is genuinely out of stock — never for an in-stock item (that would be fake urgency). The customer must provide an email; the alert is double opt-in, so this records a pending subscription and a confirmation email is sent — tell the customer to click the confirm link to activate it, and that they can unsubscribe anytime in one click. Set type to "price_drop" to watch for a lower price (allowed even for in-stock items), or omit it for a back-in-stock alert. Pass variation_id to watch a specific variation.',
			'parameters'  => [
				'type'       => 'object',
				'properties' => [
					'product_id'   => [ 'type' => 'integer', 'description' => 'The ID of the product to watch.' ],
					'email'        => [ 'type' => 'string',  'description' => 'The customer\'s email address to notify (required; they will be asked to confirm it).' ],
					'variation_id' => [ 'type' => 'integer', 'description' => 'Optional ID of a specific variation to watch (e.g. the Medium/Navy variant).' ],
					'type'         => [ 'type' => 'string',  'description' => 'Alert type: "back_in_stock" (default, only valid for an out-of-stock item) or "price_drop" (watch for a lower price).', 'enum' => [ 'back_in_stock', 'price_drop' ] ],
				],
				'required'   => [ 'product_id', 'email' ],
			],
			'callback'    => fn( array $input ) => self::subscribe_stock_alert( $input ),
		];

		return $tools;
	}

	// -------------------------------------------------------------------------
	// Tool implementation
	// -------------------------------------------------------------------------

	/**
	 * Subscribe to a back-in-stock or price-drop alert.
	 *
	 * Grounds the in-stock check in the real product, REFUSES a back-in-stock alert
	 * for an in-stock item (no fake scarcity), then records a pending double-opt-in
	 * subscription via Fahad_AI_Stock_Alerts and tells the shopper to confirm by
	 * email. Never echoes the raw email back (PII): the model-facing result carries a
	 * masked address only, and the unmasked address lives only in the store + the
	 * confirmation email.
	 *
	 * @return array A subscribed/pending result, a refusal, or an error.
	 */
	private static function subscribe_stock_alert( array $input ): array {
		$product_id   = absint( $input['product_id'] ?? 0 );
		$variation_id = absint( $input['variation_id'] ?? 0 );
		$email        = sanitize_email( (string) ( $input['email'] ?? '' ) );
		$type         = sanitize_text_field( (string) ( $input['type'] ?? Fahad_AI_Stock_Alerts::TYPE_BACK_IN_STOCK ) );

		// Validate email early so a clearly-bad address is rejected before any lookup.
		if ( ! is_email( $email ) ) {
			return [
				'error' => __( 'I need a valid email address to set up the alert.', 'fahad-ai-shopping-assistant-for-woocommerce' ),
			];
		}

		// Ground the request in the real product — a missing product is reported, not faked.
		$lookup_id = $variation_id > 0 ? $variation_id : $product_id;
		$product   = function_exists( 'wc_get_product' ) ? wc_get_product( $lookup_id ) : null;

		if ( ! $product instanceof WC_Product ) {
			return [
				'error' => __( 'I could not find that product, so I cannot set up an alert for it.', 'fahad-ai-shopping-assistant-for-woocommerce' ),
			];
		}

		$type = ( Fahad_AI_Stock_Alerts::TYPE_PRICE_DROP === $type )
			? Fahad_AI_Stock_Alerts::TYPE_PRICE_DROP
			: Fahad_AI_Stock_Alerts::TYPE_BACK_IN_STOCK;

		// NO FAKE SCARCITY: a back-in-stock alert for an IN-STOCK item is refused.
		if ( Fahad_AI_Stock_Alerts::TYPE_BACK_IN_STOCK === $type && $product->is_in_stock() ) {
			return [
				'refused' => true,
				'reason'  => 'in_stock',
				'message' => __( 'That item is in stock right now, so there is no need for a back-in-stock alert — you can buy it now. I can watch it for a price drop instead if you like.', 'fahad-ai-shopping-assistant-for-woocommerce' ),
			];
		}

		// Resolve the parent product id when a variation was given, so the stored
		// subscription's product_id matches the WooCommerce stock/price events (which
		// carry the parent + variation).
		$parent       = $product->get_parent_id();
		$store_product = ( $variation_id > 0 && $parent > 0 ) ? $parent : $product_id;

		$result = Fahad_AI_Stock_Alerts::instance()->subscribe( $store_product, $email, $variation_id, $type );

		if ( empty( $result['ok'] ) ) {
			return [
				'error' => $result['error'] ?? __( 'I could not set up that alert. Please try again.', 'fahad-ai-shopping-assistant-for-woocommerce' ),
			];
		}

		return [
			'subscribed' => true,
			'pending'    => true,
			'type'       => $type,
			// Masked, not raw — keep the address out of model context.
			'email'      => Fahad_AI_Auth::mask_email( $email ),
			'message'    => __( 'Almost done! I have set up the alert and sent a confirmation link to your email — click it to activate the alert. You can unsubscribe anytime in one click.', 'fahad-ai-shopping-assistant-for-woocommerce' ),
		];
	}
}

// Self-register this feature pack the moment the file is loaded. The bootstrap (and
// the test bootstrap) glob-require includes/tools/*.php, so dropping this file in is
// the ONLY wiring needed — no bootstrap or harness edits.
Fahad_AI_Tool_Registry::register_pack( [ 'Fahad_AI_Stock_Alert_Tools', 'register' ] );

// Wire the store's WooCommerce + WordPress hooks (stock/price notifications and the
// confirm/unsubscribe + GDPR handlers). Guarded with function_exists so this file can
// be glob-loaded by the unit-test bootstrap (which loads tool packs before
// Brain\Monkey patches WordPress functions per-test) without fataling on a missing
// add_action — the unit suites exercise Fahad_AI_Stock_Alerts directly and stub WP
// functions themselves; in WordPress add_action is always defined so the hooks are
// registered for real. Mirrors how Fahad_AI_Memory_Tools wires its filter.
if ( function_exists( 'add_action' ) && function_exists( 'add_filter' ) ) {
	Fahad_AI_Stock_Alerts::init_hooks();
}
