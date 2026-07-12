<?php
defined( 'ABSPATH' ) || exit;

/**
 * WhatsApp omnichannel assistant, webhook, verification, routing and a pluggable
 * outbound SEND seam (issue #62).
 *
 * ─── WHAT THIS IS (AND IS NOT) ───────────────────────────────────────────────────────
 *
 * The PK market is WhatsApp-first; the assistant was web-widget-only. This drives the
 * SAME agentic core + tools over WhatsApp Business (Meta Cloud API). It is delivered as
 * TESTED SCAFFOLDING behind a provider seam: this class owns the INBOUND half, Meta's
 * webhook verify handshake, the X-Hub-Signature-256 HMAC check, parsing a text message,
 * routing it into the existing non-streaming agent loop, and hands the reply text to a
 * pluggable SEND seam. It deliberately does NOT make the live Meta HTTP call: GOING LIVE
 * NEEDS A META WHATSAPP BUSINESS ACCOUNT + TOKENS, supplied by a provider that implements
 * the `fahad_ai_whatsapp_send` filter (see send()). With no provider hooked, the seam is
 * a no-op, so nothing is ever sent until a merchant wires one up.
 *
 * ─── SECURITY (all enforced here, in unit-testable PHP) ──────────────────────────────
 *
 *   1. GET verify: the challenge is echoed ONLY when hub.mode is 'subscribe' AND the
 *      supplied verify token equals the configured one (an empty configured token can
 *      never match, fail closed). Otherwise 403.
 *   2. POST inbound: the X-Hub-Signature-256 HMAC (sha256, keyed by the app secret,
 *      compared with hash_equals, constant time) is verified BEFORE any parsing or
 *      agent work. A missing/bad signature, or an unconfigured app secret, is rejected
 *      (403) and NOTHING is processed or sent (fail closed).
 *   3. Opt-in: the channel is OFF by default. A signed inbound on a disabled channel is
 *      acknowledged (200, so Meta does not retry) but NOT processed.
 *   4. Identity: a WhatsApp sender is treated as a GUEST. We NEVER auto-trust a phone
 *      number as a logged-in WC customer, so the central login gate (Fahad_AI_Auth) keeps
 *      personal-data tools blocked for an unverified identity. Building a verified
 *      phone→customer mapping is explicitly OUT OF SCOPE for #62.
 *   5. Secrets (verify token, app secret) live in options, are never localized to the
 *      client or fed to the model, and no PII (phone numbers, message text) is logged.
 *
 * ─── COST / RATE AWARENESS ───────────────────────────────────────────────────────────
 *
 * Each inbound text drives a billable agent turn, so a per-channel rate/cost ceiling is a
 * real concern. The existing turn-level cost controls (token budget, model routing,
 * bounded provider failover) apply automatically because routing reuses
 * Fahad_AI_API_Handler. A WhatsApp-specific inbound throttle (e.g. per-sender window) is
 * a follow-up; the SEND seam is also the natural place for a provider to enforce Meta's
 * own messaging limits. Noted, not fully built, for #62.
 *
 * Stateless singleton (mirrors Fahad_AI_Voice / Fahad_AI_Proactive): no per-instance
 * state, reset between tests via reflection on self::$instance.
 */
final class Fahad_AI_WhatsApp {

	/** Merchant kill-switch (default OFF, the channel is opt-in). */
	public const OPTION_ENABLED = 'fahad_ai_whatsapp_enabled';

	/** The verify token used in Meta's GET webhook subscription handshake. */
	public const OPTION_VERIFY_TOKEN = 'fahad_ai_whatsapp_verify_token';

	/** The Meta App Secret, the HMAC key for the X-Hub-Signature-256 header. */
	public const OPTION_APP_SECRET = 'fahad_ai_whatsapp_app_secret';

	private static ?Fahad_AI_WhatsApp $instance = null;

	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {}

	// -------------------------------------------------------------------------
	// Merchant config
	// -------------------------------------------------------------------------

	/**
	 * Is the WhatsApp channel enabled by the merchant? Default OFF, opt-in, the
	 * conservative choice for a channel that processes inbound messages and drives
	 * billable agent turns.
	 */
	public function enabled(): bool {
		return (bool) get_option( self::OPTION_ENABLED, 0 );
	}

	/** The configured webhook verify token (empty string when unset). */
	public function verify_token(): string {
		return (string) get_option( self::OPTION_VERIFY_TOKEN, '' );
	}

	/** The configured Meta App Secret used to key the inbound HMAC (empty when unset). */
	public function app_secret(): string {
		return (string) get_option( self::OPTION_APP_SECRET, '' );
	}

	// -------------------------------------------------------------------------
	// REST / webhook routes
	// -------------------------------------------------------------------------

	/**
	 * Register the WhatsApp webhook routes on the fahad-ai/v1 namespace.
	 *
	 * Two methods on ONE endpoint, matching Meta's webhook contract:
	 *   GET  /whatsapp, the subscription verify handshake (hub.* query params).
	 *   POST /whatsapp, inbound message deliveries (signed JSON body).
	 *
	 * permission_callback is __return_true on purpose: Meta cannot send a WordPress
	 * nonce, so the security boundary is NOT a nonce here, it is the verify-token check
	 * (GET) and the X-Hub-Signature-256 HMAC (POST), both enforced INSIDE the handlers
	 * before anything happens. This mirrors the plugin's "public endpoint, real auth
	 * inside" philosophy (the chat endpoints are public + nonce/rate-limit; this channel
	 * is public + signature/verify-token).
	 *
	 * The hub.* and header params are declared so WP_REST_Request exposes them with
	 * underscore keys (hub_mode, hub_verify_token, hub_challenge), which the handlers read.
	 */
	public function register_routes(): void {
		register_rest_route( 'fahad-ai/v1', '/whatsapp', [
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'handle_verify' ],
				'permission_callback' => '__return_true',
				'args'                => [
					'hub_mode'         => [ 'sanitize_callback' => 'sanitize_text_field' ],
					'hub_verify_token' => [ 'sanitize_callback' => 'sanitize_text_field' ],
					'hub_challenge'    => [ 'sanitize_callback' => 'sanitize_text_field' ],
				],
			],
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'handle_inbound' ],
				'permission_callback' => '__return_true',
			],
		] );
	}

	// -------------------------------------------------------------------------
	// GET, webhook verification handshake
	// -------------------------------------------------------------------------

	/**
	 * Handle Meta's webhook subscription verification (GET).
	 *
	 * Meta calls the webhook with hub.mode=subscribe, hub.verify_token=<the token you
	 * configured in the App dashboard> and hub.challenge=<a nonce>. We echo the challenge
	 * back ONLY when the mode is 'subscribe' AND the supplied token matches the configured
	 * verify token (compared constant-time). Meta sends the challenge as a number and
	 * expects it echoed; we cast to int so the body is the bare number it looks for.
	 *
	 * Fail closed: an empty configured verify token never matches (so an unconfigured
	 * site can't be hijacked into confirming a webhook), a wrong token is a 403, and any
	 * non-subscribe mode is a 403.
	 *
	 * @return WP_REST_Response|WP_Error The challenge (int) on success, else a 403 error.
	 */
	public function handle_verify( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$mode      = (string) $request->get_param( 'hub_mode' );
		$token     = (string) $request->get_param( 'hub_verify_token' );
		$challenge = (string) $request->get_param( 'hub_challenge' );

		$configured = $this->verify_token();

		// Fail closed: require subscribe mode, a non-empty configured token, and a
		// constant-time token match. hash_equals avoids leaking the token via timing.
		if ( 'subscribe' !== $mode || '' === $configured || ! hash_equals( $configured, $token ) ) {
			return new WP_Error(
				'fahad_ai_whatsapp_verify_failed',
				__( 'WhatsApp webhook verification failed.', 'fahad-ai-shopping-assistant-for-woocommerce' ),
				[ 'status' => 403 ]
			);
		}

		// Echo the challenge back as the bare value Meta expects (it sends an integer).
		return new WP_REST_Response( (int) $challenge, 200 );
	}

	// -------------------------------------------------------------------------
	// POST, inbound message delivery
	// -------------------------------------------------------------------------

	/**
	 * Handle an inbound WhatsApp delivery (POST).
	 *
	 * Order is load-bearing (security first, fail closed):
	 *   1. Verify the X-Hub-Signature-256 HMAC over the RAW body BEFORE anything else.
	 *      A missing/bad signature, or an unconfigured app secret, → 403, nothing
	 *      processed, nothing sent.
	 *   2. If the channel is disabled (default), ACK 200 (so Meta does not retry) but do
	 *      NOT process. The channel must be explicitly opted into.
	 *   3. Parse the first text message. A delivery with no text (status webhook,
	 *      unsupported type, …) is ACKed without running the agent or sending.
	 *   4. Route the text into the SAME non-streaming agent loop AS A GUEST (no identity
	 *      is trusted, personal-data tools stay login-gated), then hand the reply text to
	 *      the SEND seam, addressed to the sender.
	 *
	 * Always returns 200 once the signature passes (even when nothing is sent), because a
	 * non-200 makes Meta retry the delivery, we never want a retry storm for a benign
	 * "no text to answer" or "no provider configured" case.
	 *
	 * @return WP_REST_Response|WP_Error 403 on a failed signature; otherwise a 200 ack.
	 */
	public function handle_inbound( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$raw       = (string) $request->get_body();
		$signature = $request->get_header( 'x_hub_signature_256' );

		// 1. Signature FIRST, before parsing or any agent work (constant-time, fail closed).
		if ( ! $this->verify_signature( $raw, is_string( $signature ) ? $signature : null ) ) {
			return new WP_Error(
				'fahad_ai_whatsapp_bad_signature',
				__( 'Invalid webhook signature.', 'fahad-ai-shopping-assistant-for-woocommerce' ),
				[ 'status' => 403 ]
			);
		}

		// 2. Opt-in gate: a disabled channel acks but does not process or send.
		if ( ! $this->enabled() ) {
			return $this->ack( false );
		}

		// 3. Parse the first inbound text message (if any).
		$message = $this->first_text_message( $raw );

		if ( null === $message ) {
			// A non-text delivery (status update, unsupported type, malformed), ack
			// without running the agent or sending. No PII is logged.
			return $this->ack( false );
		}

		// 4. Route into the SAME agent core as a GUEST, then hand the reply to the seam.
		// NOTE: we do NOT call wp_set_current_user, the sender is unverified, so personal
		// tools stay blocked by Fahad_AI_Auth (identity hardening, #62).
		$reply = Fahad_AI_API_Handler::instance()->run_text_turn( [
			[ 'role' => 'user', 'content' => $message['text'] ],
		] );

		$result = $this->send( $message['from'], $reply );

		return $this->ack( $this->was_sent( $result ) );
	}

	// -------------------------------------------------------------------------
	// Signature verification (X-Hub-Signature-256)
	// -------------------------------------------------------------------------

	/**
	 * Constant-time verification of Meta's X-Hub-Signature-256 header.
	 *
	 * Meta signs the RAW request body with HMAC-SHA256 keyed by the App Secret and sends
	 * it as `sha256=<hexdigest>`. We recompute it over the raw body and compare with
	 * hash_equals (constant time, never leaks via timing).
	 *
	 * Fail closed: an unconfigured app secret, a missing/blank header, or any mismatch all
	 * return false, so an inbound is processed ONLY when its signature is provably valid.
	 *
	 * @param string      $raw_body  The exact raw request body the HMAC is computed over.
	 * @param string|null $signature The X-Hub-Signature-256 header value, or null if absent.
	 */
	public function verify_signature( string $raw_body, ?string $signature ): bool {
		$secret = $this->app_secret();

		// Fail closed: no secret configured → cannot verify → never trust.
		if ( '' === $secret ) {
			return false;
		}

		if ( null === $signature || '' === $signature ) {
			return false;
		}

		$expected = 'sha256=' . hash_hmac( 'sha256', $raw_body, $secret );

		return hash_equals( $expected, $signature );
	}

	// -------------------------------------------------------------------------
	// Payload parsing
	// -------------------------------------------------------------------------

	/**
	 * Extract the FIRST inbound text message from a Meta webhook payload, or null.
	 *
	 * Meta's shape is entry[].changes[].value.messages[]; a text message carries
	 * type=text with text.body, plus the sender's phone number in `from`. We return only
	 * the minimum the agent + reply need, { from, text }, and ignore everything else
	 * (status webhooks, reactions, media, etc.) by returning null, so the caller acks
	 * without processing. Empty/whitespace-only text is treated as no message.
	 *
	 * @param string $raw The raw JSON request body.
	 * @return array{from:string, text:string}|null The first text message, or null.
	 */
	private function first_text_message( string $raw ): ?array {
		$payload = json_decode( $raw, true );

		if ( ! is_array( $payload ) ) {
			return null;
		}

		foreach ( $payload['entry'] ?? [] as $entry ) {
			foreach ( $entry['changes'] ?? [] as $change ) {
				$messages = $change['value']['messages'] ?? null;

				if ( ! is_array( $messages ) ) {
					continue;
				}

				foreach ( $messages as $msg ) {
					if ( ( $msg['type'] ?? '' ) !== 'text' ) {
						continue;
					}

					$text = trim( (string) ( $msg['text']['body'] ?? '' ) );
					$from = (string) ( $msg['from'] ?? '' );

					if ( '' === $text || '' === $from ) {
						continue;
					}

					return [
						'from' => sanitize_text_field( $from ),
						'text' => sanitize_textarea_field( $text ),
					];
				}
			}
		}

		return null;
	}

	// -------------------------------------------------------------------------
	// Outbound SEND seam (the deliverable, NOT a live Meta call)
	// -------------------------------------------------------------------------

	/**
	 * Hand a reply to the pluggable outbound SEND seam, the documented extension point a
	 * provider implements to actually call the Meta Cloud API (issue #62).
	 *
	 * THIS PLUGIN MAKES NO LIVE META CALL. By default (no provider hooked) the filter
	 * returns null and nothing is sent, the "nothing happens until a provider + tokens
	 * are configured" guarantee. A provider (a companion plugin, or a future paid add-on
	 * that holds the merchant's WhatsApp phone-number id + access token) registers:
	 *
	 *   add_filter( 'fahad_ai_whatsapp_send', function ( $result, $to, $text, $ctx ) {
	 *       // POST to https://graph.facebook.com/<ver>/<phone_number_id>/messages
	 *       //   Authorization: Bearer <access token>
	 *       //   body: { messaging_product: 'whatsapp', to: $to,
	 *       //           type: 'text', text: { body: $text } }
	 *       // return [ 'sent' => true, 'id' => <wamid> ] on success.
	 *       return $result;
	 *   }, 10, 4 );
	 *
	 * Keeping the live HTTP call OUT of core keeps secrets (the access token) in the
	 * provider, mirrors the wallet-provider decoupling (#18), and means this scaffolding
	 * can ship and be fully unit-tested without any Meta credentials.
	 *
	 * @param string $to   The recipient's WhatsApp phone number (from the inbound message).
	 * @param string $text The reply text to deliver.
	 * @return mixed Whatever the provider returns (null when none is configured = no-op).
	 */
	public function send( string $to, string $text ) {
		/**
		 * Filter that performs the actual WhatsApp send (issue #62).
		 *
		 * Default null = no-op (nothing is sent until a provider implements this). A
		 * provider returns a result array (e.g. [ 'sent' => true, 'id' => <wamid> ]).
		 *
		 * @param mixed  $result Provider result; null by default (no provider = no send).
		 * @param string $to     Recipient WhatsApp phone number.
		 * @param string $text   Reply text.
		 * @param array  $context { channel: 'whatsapp' }, room for future routing hints.
		 */
		return apply_filters( 'fahad_ai_whatsapp_send', null, $to, $text, [ 'channel' => 'whatsapp' ] );
	}

	/**
	 * Whether a send seam result indicates the message was actually sent. A provider
	 * signals success with a truthy `sent` flag; null (no provider) or a falsey/odd
	 * return is treated as "not sent".
	 *
	 * @param mixed $result The value returned by the send seam.
	 */
	private function was_sent( $result ): bool {
		return is_array( $result ) && ! empty( $result['sent'] );
	}

	/**
	 * A 200 acknowledgement body for an inbound delivery. We always ACK once the
	 * signature passes (so Meta does not retry); `sent` tells the caller/tests whether a
	 * reply was actually dispatched through the seam.
	 *
	 * @param bool $sent Whether a reply was sent through the provider seam.
	 */
	private function ack( bool $sent ): WP_REST_Response {
		return new WP_REST_Response( [ 'received' => true, 'sent' => $sent ], 200 );
	}
}
