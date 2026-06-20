<?php
/**
 * Unit tests for Fahad_AI_WhatsApp (issue #62: omnichannel — WhatsApp assistant).
 *
 * Red → Green → Refactor. Conventions mirror VoiceTest / ProactiveTest: WP functions
 * mocked via Brain\Monkey; the singleton reset via reflection between cases (NEVER
 * ReflectionMethod::setAccessible — host runs PHP 8.5); get_option stubbed via an
 * in-memory $this->options map; additive stubs only.
 *
 * ─── WHAT IS ACTUALLY TESTABLE HERE ─────────────────────────────────────────────────
 *
 * A live WhatsApp Business (Meta Cloud API) account is NOT available, so this is TESTED
 * SCAFFOLDING behind a provider seam — going live needs Meta credentials. There is NO
 * real outbound HTTP call to Meta anywhere; the actual send is a `fahad_ai_whatsapp_send`
 * filter a provider implements. These tests pin the security- and routing-critical PHP:
 *
 *   - GET webhook verify: Meta's hub.mode/hub.verify_token/hub.challenge handshake returns
 *     the challenge ONLY when the configured verify token matches (else it is rejected).
 *   - POST inbound: the X-Hub-Signature-256 HMAC (sha256, app secret, hash_equals) is
 *     verified BEFORE any processing — a bad/missing signature is rejected outright.
 *   - A valid signed inbound text routes into the SAME agentic core and the reply text is
 *     handed to the send SEAM (asserted via the filter — NOT a live HTTP call).
 *   - Disabled (default OFF) / unconfigured → no send.
 *
 * Identity: a WhatsApp user is treated as a GUEST. We never auto-trust a phone number as a
 * logged-in user, so the central login gate (Fahad_AI_Auth) still blocks personal-data
 * tools — pinned by test_inbound_does_not_authenticate_the_sender.
 */

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;

class WhatsAppTest extends TestCase {

	use MockeryPHPUnitIntegration;

	/** @var array<string, mixed> In-memory stand-in for the WP options table. */
	private array $options = [];

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$this->options = [];

		// __ / esc_html__ are real pass-throughs from tests/stubs/wc-stubs.php (loaded
		// before Patchwork), so they must NOT be re-stubbed here (DefinedTooEarly).
		Functions\stubs( [
			'sanitize_text_field'     => fn( $s ) => is_string( $s ) ? trim( $s ) : '',
			'sanitize_textarea_field' => fn( $s ) => is_string( $s ) ? trim( $s ) : '',
		] );

		Functions\when( 'get_option' )->alias(
			fn( $name, $default = false ) => $this->options[ $name ] ?? $default
		);
		// apply_filters: identity on the value unless a test overrides it (so the send
		// seam is a no-op by default — nothing is sent until a provider hooks it).
		Functions\when( 'apply_filters' )->alias(
			fn( $hook, $value = null ) => $value
		);
	}

	protected function tearDown(): void {
		( new ReflectionProperty( Fahad_AI_WhatsApp::class, 'instance' ) )->setValue( null, null );
		( new ReflectionProperty( Fahad_AI_API_Handler::class, 'instance' ) )->setValue( null, null );
		Monkey\tearDown();
		parent::tearDown();
	}

	/** Fresh singleton (reset between cases via reflection). */
	private function whatsapp(): Fahad_AI_WhatsApp {
		( new ReflectionProperty( Fahad_AI_WhatsApp::class, 'instance' ) )->setValue( null, null );
		return Fahad_AI_WhatsApp::instance();
	}

	/** A GET verify request: hub.mode / hub.verify_token / hub.challenge params. */
	private function verify_request( string $mode, string $token, string $challenge ) {
		$req = Mockery::mock( 'WP_REST_Request' );
		$req->shouldReceive( 'get_param' )->with( 'hub_mode' )->andReturn( $mode );
		$req->shouldReceive( 'get_param' )->with( 'hub_verify_token' )->andReturn( $token );
		$req->shouldReceive( 'get_param' )->with( 'hub_challenge' )->andReturn( $challenge );
		return $req;
	}

	/**
	 * A POST inbound request. get_body() returns the raw JSON (the bytes the HMAC is
	 * computed over); the signature header is read via get_header('x_hub_signature_256').
	 */
	private function inbound_request( string $raw_body, ?string $signature ) {
		$req = Mockery::mock( 'WP_REST_Request' );
		$req->shouldReceive( 'get_body' )->andReturn( $raw_body );
		$req->shouldReceive( 'get_header' )->with( 'x_hub_signature_256' )->andReturn( $signature );
		return $req;
	}

	/** A well-formed Meta inbound text payload carrying a single message. */
	private function text_payload( string $from, string $text ): array {
		return [
			'object' => 'whatsapp_business_account',
			'entry'  => [ [
				'changes' => [ [
					'value' => [
						'messaging_product' => 'whatsapp',
						'metadata'          => [ 'phone_number_id' => '1234567890' ],
						'messages'          => [ [
							'from' => $from,
							'id'   => 'wamid.TEST',
							'type' => 'text',
							'text' => [ 'body' => $text ],
						] ],
					],
				] ],
			] ],
		];
	}

	/** A valid sha256 signature header for the raw body, keyed by the app secret. */
	private function sign( string $raw_body, string $secret ): string {
		return 'sha256=' . hash_hmac( 'sha256', $raw_body, $secret );
	}

	/**
	 * Stub the API-handler transport so a routed inbound text reaches the REAL agent
	 * loop and produces a deterministic reply — the SAME scripted-transport pattern the
	 * ApiHandlerTest dispatch tests use. Anthropic is the default provider; one end_turn
	 * answer (no tool calls) means no WC tool mocks are needed. NO live Meta call.
	 */
	private function stub_agent_reply( string $reply ): void {
		Functions\when( 'wp_json_encode' )->alias( static fn( $d ) => json_encode( $d ) );
		Functions\when( 'wc_load_cart' )->justReturn( null );
		Functions\when( 'get_bloginfo' )->justReturn( 'Test Store' );
		Functions\when( 'get_woocommerce_currency_symbol' )->justReturn( '$' );

		// Owner analytics (#49) records every resolved turn; keep it from fataling on
		// unstubbed functions and reset its singleton (mirrors the dispatch tests).
		Functions\when( 'update_option' )->justReturn( true );
		Functions\when( 'wp_generate_uuid4' )->justReturn( 'uuid-analytics' );
		( new ReflectionProperty( Fahad_AI_Analytics::class, 'instance' ) )->setValue( null, null );

		Functions\when( 'wp_remote_post' )->alias(
			static fn( $url, $args = [] ) => [ '__eval' => true, 'code' => 200, 'body' => json_encode( [
				'stop_reason' => 'end_turn',
				'content'     => [ [ 'type' => 'text', 'text' => $reply ] ],
			] ) ]
		);
		Functions\when( 'wp_remote_retrieve_response_code' )->alias(
			static fn( $r ) => is_array( $r ) ? ( $r['code'] ?? 0 ) : 0
		);
		Functions\when( 'wp_remote_retrieve_body' )->alias(
			static fn( $r ) => is_array( $r ) ? ( $r['body'] ?? '' ) : ''
		);
	}

	// ── enabled(): the merchant kill-switch, default OFF ────────────────────────────

	public function test_enabled_defaults_off(): void {
		// Conservative default: the WhatsApp channel is OPT-IN. Inbound messages are not
		// processed until the merchant turns it on AND configures Meta credentials.
		$this->assertFalse( $this->whatsapp()->enabled() );
	}

	public function test_enabled_reflects_the_option_when_on(): void {
		$this->options['fahad_ai_whatsapp_enabled'] = 1;
		$this->assertTrue( $this->whatsapp()->enabled() );
	}

	// ── GET webhook verify (Meta hub.* handshake) ───────────────────────────────────

	public function test_verify_returns_challenge_when_token_matches(): void {
		// Meta's subscription handshake: when hub.mode is 'subscribe' AND the supplied
		// verify token matches the configured one, echo the challenge back verbatim. Meta
		// echoes it as an integer; we return it so the subscription is confirmed.
		$this->options['fahad_ai_whatsapp_verify_token'] = 'sekret-verify';

		$response = $this->whatsapp()->handle_verify(
			$this->verify_request( 'subscribe', 'sekret-verify', '1158201444' )
		);

		$this->assertFalse( is_wp_error( $response ), 'A matching token must succeed.' );
		$this->assertSame( 1158201444, $response->get_data() );
	}

	public function test_verify_rejects_when_token_does_not_match(): void {
		// A wrong verify token is rejected (403) and the challenge is NOT echoed — an
		// attacker who guesses the endpoint but not the token cannot confirm a webhook.
		$this->options['fahad_ai_whatsapp_verify_token'] = 'sekret-verify';

		$response = $this->whatsapp()->handle_verify(
			$this->verify_request( 'subscribe', 'WRONG-token', '1158201444' )
		);

		$this->assertTrue( is_wp_error( $response ) );
		$this->assertSame( 403, $response->data['status'] ?? 0 );
	}

	public function test_verify_rejects_when_verify_token_is_unconfigured(): void {
		// With no verify token configured, the handshake can never succeed — an empty
		// configured token must not match an empty supplied token (no accidental open).
		$response = $this->whatsapp()->handle_verify(
			$this->verify_request( 'subscribe', '', '1158201444' )
		);

		$this->assertTrue( is_wp_error( $response ) );
		$this->assertSame( 403, $response->data['status'] ?? 0 );
	}

	public function test_verify_rejects_when_mode_is_not_subscribe(): void {
		// hub.mode must be 'subscribe'; any other mode is rejected even with a good token.
		$this->options['fahad_ai_whatsapp_verify_token'] = 'sekret-verify';

		$response = $this->whatsapp()->handle_verify(
			$this->verify_request( 'unsubscribe', 'sekret-verify', '1158201444' )
		);

		$this->assertTrue( is_wp_error( $response ) );
	}

	// ── POST inbound: signature verification BEFORE any processing ──────────────────

	public function test_inbound_rejects_a_missing_signature_before_processing(): void {
		// HARDENING: no X-Hub-Signature-256 header → reject (403) BEFORE parsing or
		// running the agent. The send seam must never fire on an unsigned request.
		$this->options['fahad_ai_whatsapp_enabled']    = 1;
		$this->options['fahad_ai_whatsapp_app_secret'] = 'app-secret';

		$payload = $this->text_payload( '15551234567', 'hello' );
		$raw     = json_encode( $payload );

		$sent = [];
		Functions\when( 'apply_filters' )->alias(
			function ( $hook, $value = null ) use ( &$sent ) {
				if ( 'fahad_ai_whatsapp_send' === $hook ) {
					$sent[] = func_get_args();
				}
				return $value;
			}
		);

		$response = $this->whatsapp()->handle_inbound( $this->inbound_request( $raw, null ) );

		$this->assertTrue( is_wp_error( $response ) );
		$this->assertSame( 403, $response->data['status'] ?? 0 );
		$this->assertSame( [], $sent, 'No send may occur for an unsigned request.' );
	}

	public function test_inbound_rejects_a_bad_signature_before_processing(): void {
		// A signature computed with the WRONG secret must fail the constant-time compare,
		// so the request is rejected (403) before the agent runs or any send fires.
		$this->options['fahad_ai_whatsapp_enabled']    = 1;
		$this->options['fahad_ai_whatsapp_app_secret'] = 'app-secret';

		$payload = $this->text_payload( '15551234567', 'hello' );
		$raw     = json_encode( $payload );
		$bad_sig = $this->sign( $raw, 'WRONG-secret' );

		$sent = [];
		Functions\when( 'apply_filters' )->alias(
			function ( $hook, $value = null ) use ( &$sent ) {
				if ( 'fahad_ai_whatsapp_send' === $hook ) {
					$sent[] = func_get_args();
				}
				return $value;
			}
		);

		$response = $this->whatsapp()->handle_inbound( $this->inbound_request( $raw, $bad_sig ) );

		$this->assertTrue( is_wp_error( $response ) );
		$this->assertSame( 403, $response->data['status'] ?? 0 );
		$this->assertSame( [], $sent, 'No send may occur for a bad-signature request.' );
	}

	public function test_inbound_rejects_when_app_secret_is_unconfigured(): void {
		// Without a configured app secret the signature cannot be verified, so EVERY
		// inbound POST is rejected (fail closed) — never processed on trust.
		$this->options['fahad_ai_whatsapp_enabled'] = 1;

		$payload = $this->text_payload( '15551234567', 'hello' );
		$raw     = json_encode( $payload );

		$response = $this->whatsapp()->handle_inbound(
			$this->inbound_request( $raw, $this->sign( $raw, 'any-secret' ) )
		);

		$this->assertTrue( is_wp_error( $response ) );
		$this->assertSame( 403, $response->data['status'] ?? 0 );
	}

	// ── POST inbound: a valid signed text routes into the agent → send seam ─────────

	public function test_valid_signed_text_routes_into_agent_and_replies_to_send_seam(): void {
		// The end-to-end happy path WITHOUT any live HTTP to Meta: a correctly-signed
		// inbound text reaches the REAL agent loop (scripted transport) and the resulting
		// reply text is handed to the fahad_ai_whatsapp_send SEAM — addressed to the
		// sender. We assert the seam is invoked with the reply; we do NOT make a network
		// call. A provider implementing the filter is what actually talks to Meta.
		$this->options['fahad_ai_whatsapp_enabled']    = 1;
		$this->options['fahad_ai_whatsapp_app_secret'] = 'app-secret';
		$this->options['fahad_ai_anthropic_api_key']   = 'sk-ant-key';

		$payload = $this->text_payload( '15551234567', 'do you have running shoes?' );
		$raw     = json_encode( $payload );
		$sig     = $this->sign( $raw, 'app-secret' );

		$this->stub_agent_reply( 'Yes — we have several running shoes in stock.' );

		// Capture the send seam (mock the provider). It must be called once with the
		// recipient and the reply text; return a truthy "sent" so the handler can report.
		$captured = [];
		Functions\when( 'apply_filters' )->alias(
			function ( $hook, $value = null, $to = null, $text = null ) use ( &$captured ) {
				if ( 'fahad_ai_whatsapp_send' === $hook ) {
					$captured = [ 'to' => $to, 'text' => $text ];
					return [ 'sent' => true ];
				}
				return $value;
			}
		);

		$response = $this->whatsapp()->handle_inbound( $this->inbound_request( $raw, $sig ) );

		$this->assertFalse( is_wp_error( $response ), 'A valid signed text must be accepted.' );
		$this->assertSame( '15551234567', $captured['to'] ?? null, 'Reply goes back to the sender.' );
		// The reply is humanized by the shared dispatch (#130): WhatsApp replies, like web
		// replies, must contain no em-dash. The stubbed "Yes — we have…" reaches the send
		// seam as "Yes, we have…".
		$this->assertSame(
			'Yes, we have several running shoes in stock.',
			$captured['text'] ?? null,
			'The agent reply text (humanized, no em-dash) must be handed to the send seam.'
		);
	}

	public function test_inbound_does_not_send_when_channel_disabled(): void {
		// Even a perfectly-signed inbound is NOT processed (and nothing is sent) when the
		// merchant kill-switch is OFF (the default). The channel must be explicitly opted
		// into. The signature still validates; the disabled gate short-circuits after.
		$this->options['fahad_ai_whatsapp_app_secret'] = 'app-secret';
		// enabled NOT set → default OFF.

		$payload = $this->text_payload( '15551234567', 'hello' );
		$raw     = json_encode( $payload );
		$sig     = $this->sign( $raw, 'app-secret' );

		$sent = [];
		Functions\when( 'apply_filters' )->alias(
			function ( $hook, $value = null ) use ( &$sent ) {
				if ( 'fahad_ai_whatsapp_send' === $hook ) {
					$sent[] = func_get_args();
				}
				return $value;
			}
		);

		$response = $this->whatsapp()->handle_inbound( $this->inbound_request( $raw, $sig ) );

		$this->assertFalse( is_wp_error( $response ), 'A disabled channel still acks (200), it just does not process.' );
		$this->assertSame( [], $sent, 'A disabled channel must not send anything.' );
	}

	public function test_default_send_seam_is_a_noop_when_no_provider_configured(): void {
		// Unconfigured: with NO provider hooked onto fahad_ai_whatsapp_send (the default
		// identity apply_filters from setUp), a valid signed text routes into the agent
		// but NOTHING is sent (the seam returns null). This is the "default no-op / nothing
		// is sent until a provider + tokens are set" guarantee — the live Meta call is the
		// provider's job, not this plugin's.
		$this->options['fahad_ai_whatsapp_enabled']    = 1;
		$this->options['fahad_ai_whatsapp_app_secret'] = 'app-secret';
		$this->options['fahad_ai_anthropic_api_key']   = 'sk-ant-key';

		$payload = $this->text_payload( '15551234567', 'hello' );
		$raw     = json_encode( $payload );
		$sig     = $this->sign( $raw, 'app-secret' );

		$this->stub_agent_reply( 'Hi there!' );

		// apply_filters stays the identity stub from setUp → the send seam returns null
		// (no provider). The handler must not fatal and must report "not sent".
		$response = $this->whatsapp()->handle_inbound( $this->inbound_request( $raw, $sig ) );

		$this->assertFalse( is_wp_error( $response ) );
		$data = $response->get_data();
		$this->assertFalse( (bool) ( $data['sent'] ?? false ), 'With no provider, nothing is sent.' );
	}

	// ── Identity gating: a WhatsApp sender is a GUEST, never auto-trusted ───────────

	public function test_inbound_does_not_authenticate_the_sender(): void {
		// HARDENING: a phone number must NOT be auto-trusted as a logged-in WC customer.
		// We assert the handler never calls wp_set_current_user — so the central login
		// gate (Fahad_AI_Auth::guard_logged_in) keeps personal-data tools blocked. Building
		// the verified phone→customer mapping is explicitly out of scope for #62.
		$this->options['fahad_ai_whatsapp_enabled']    = 1;
		$this->options['fahad_ai_whatsapp_app_secret'] = 'app-secret';
		$this->options['fahad_ai_anthropic_api_key']   = 'sk-ant-key';

		$payload = $this->text_payload( '15551234567', 'what is my order status?' );
		$raw     = json_encode( $payload );
		$sig     = $this->sign( $raw, 'app-secret' );

		$this->stub_agent_reply( 'Please log in to view your orders.' );

		// If the handler ever tried to authenticate the sender it would call this; expect
		// it NEVER to (Brain\Monkey fails the test if an unexpected call is made).
		Functions\expect( 'wp_set_current_user' )->never();

		$this->whatsapp()->handle_inbound( $this->inbound_request( $raw, $sig ) );
	}

	// ── Robustness: a signed-but-non-text/empty payload is acked without a send ─────

	public function test_signed_non_text_payload_is_acked_without_sending(): void {
		// A correctly-signed delivery that carries no text message (e.g. a status webhook,
		// or an unsupported message type) must be acknowledged (200) without running the
		// agent or sending anything — Meta retries on a non-200, so we must not error.
		$this->options['fahad_ai_whatsapp_enabled']    = 1;
		$this->options['fahad_ai_whatsapp_app_secret'] = 'app-secret';

		$raw = json_encode( [
			'object' => 'whatsapp_business_account',
			'entry'  => [ [ 'changes' => [ [ 'value' => [
				'messaging_product' => 'whatsapp',
				'statuses'          => [ [ 'status' => 'delivered' ] ],
			] ] ] ] ],
		] );
		$sig = $this->sign( $raw, 'app-secret' );

		$sent = [];
		Functions\when( 'apply_filters' )->alias(
			function ( $hook, $value = null ) use ( &$sent ) {
				if ( 'fahad_ai_whatsapp_send' === $hook ) {
					$sent[] = func_get_args();
				}
				return $value;
			}
		);

		$response = $this->whatsapp()->handle_inbound( $this->inbound_request( $raw, $sig ) );

		$this->assertFalse( is_wp_error( $response ), 'A status/non-text webhook is acked, not errored.' );
		$this->assertSame( [], $sent, 'No reply is sent for a non-text payload.' );
	}
}
