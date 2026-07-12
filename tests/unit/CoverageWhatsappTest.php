<?php
/**
 * Coverage tests for Fahad_AI_WhatsApp (issue #62), the lines the behavioural
 * WhatsAppTest does not already exercise: register_routes() (the REST route
 * declaration) and the three "no usable text message" branches inside the private
 * first_text_message() parser (non-array payload, non-text message type, and a
 * text message with empty/whitespace body or empty sender).
 *
 * Conventions mirror WhatsAppTest / the other Coverage* suites: WP functions mocked
 * via Brain\Monkey; the singleton reset via reflection between cases (NEVER
 * ReflectionMethod::setAccessible, the host runs PHP 8.5); get_option backed by an
 * in-memory options map; apply_filters is the identity stub unless a test overrides
 * it. The parser branches are driven through the PUBLIC handle_inbound() with a
 * correctly-signed body, so they execute exactly as they do in production (and we
 * additionally assert the send seam never fires for any of them).
 */

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;

class CoverageWhatsappTest extends TestCase {

	use MockeryPHPUnitIntegration;

	/** @var array<string, mixed> In-memory stand-in for the WP options table. */
	private array $options = [];

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$this->options = [];

		Functions\stubs( [
			'sanitize_text_field'     => fn( $s ) => is_string( $s ) ? trim( $s ) : '',
			'sanitize_textarea_field' => fn( $s ) => is_string( $s ) ? trim( $s ) : '',
		] );

		Functions\when( 'get_option' )->alias(
			fn( $name, $default = false ) => $this->options[ $name ] ?? $default
		);
		// Identity apply_filters by default → the send seam is a no-op (nothing sent
		// until a provider hooks it). Tests that need to observe the seam override it.
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

	/** A valid sha256 signature header for the raw body, keyed by the app secret. */
	private function sign( string $raw_body, string $secret ): string {
		return 'sha256=' . hash_hmac( 'sha256', $raw_body, $secret );
	}

	/**
	 * Capture any fahad_ai_whatsapp_send invocations into $sent (by reference) while
	 * keeping the identity behaviour for every other filter. Returns nothing; the seam
	 * stays a no-op (returns null), so the handler reports "not sent".
	 *
	 * @param array<int, array<int, mixed>> $sent Filled with the args of each send call.
	 */
	private function capture_send_seam( array &$sent ): void {
		Functions\when( 'apply_filters' )->alias(
			function ( $hook, $value = null ) use ( &$sent ) {
				if ( 'fahad_ai_whatsapp_send' === $hook ) {
					$sent[] = func_get_args();
				}
				return $value;
			}
		);
	}

	// ── register_routes(): the REST route declaration (lines 117-133) ───────────────

	public function test_register_routes_registers_the_whatsapp_endpoint(): void {
		// register_routes() declares ONE endpoint on fahad-ai/v1 carrying both a GET
		// (verify handshake) and a POST (inbound delivery) handler. We stub
		// register_rest_route to capture the exact arguments and assert the contract:
		// namespace, route, both methods, their callbacks/permission, and that the GET
		// args declare the hub_* params with a sanitize callback.
		$captured = null;
		Functions\when( 'register_rest_route' )->alias(
			function ( $namespace, $route, $config ) use ( &$captured ) {
				$captured = compact( 'namespace', 'route', 'config' );
				return true;
			}
		);

		$whatsapp = $this->whatsapp();
		$whatsapp->register_routes();

		$this->assertIsArray( $captured, 'register_rest_route must be called.' );
		$this->assertSame( 'fahad-ai/v1', $captured['namespace'] );
		$this->assertSame( '/whatsapp', $captured['route'] );

		$config = $captured['config'];
		$this->assertCount( 2, $config, 'One endpoint with two method handlers (GET + POST).' );

		// GET handler, the verify handshake.
		$get = $config[0];
		$this->assertSame( 'GET', $get['methods'] );
		$this->assertSame( [ $whatsapp, 'handle_verify' ], $get['callback'] );
		$this->assertSame( '__return_true', $get['permission_callback'] );
		$this->assertSame(
			[ 'hub_mode', 'hub_verify_token', 'hub_challenge' ],
			array_keys( $get['args'] ),
			'GET declares the hub.* query params so WP_REST_Request exposes them.'
		);
		$this->assertSame(
			'sanitize_text_field',
			$get['args']['hub_mode']['sanitize_callback'],
			'hub_mode is sanitised.'
		);
		$this->assertSame( 'sanitize_text_field', $get['args']['hub_verify_token']['sanitize_callback'] );
		$this->assertSame( 'sanitize_text_field', $get['args']['hub_challenge']['sanitize_callback'] );

		// POST handler, inbound delivery (no nonce; signature is checked inside).
		$post = $config[1];
		$this->assertSame( 'POST', $post['methods'] );
		$this->assertSame( [ $whatsapp, 'handle_inbound' ], $post['callback'] );
		$this->assertSame( '__return_true', $post['permission_callback'] );
		$this->assertArrayNotHasKey( 'args', $post, 'The POST handler reads the raw signed body, not declared args.' );
	}

	// ── first_text_message(): non-array payload → null (line 294) ───────────────────

	public function test_signed_non_array_json_payload_is_acked_without_sending(): void {
		// A correctly-signed body whose JSON decodes to a NON-array (here a bare JSON
		// string) must yield no message: first_text_message returns null at the
		// is_array guard, so the delivery is acked (200) with nothing sent.
		$this->options['fahad_ai_whatsapp_enabled']    = 1;
		$this->options['fahad_ai_whatsapp_app_secret'] = 'app-secret';

		$raw = json_encode( 'just-a-string' ); // decodes to the scalar string, not an array.
		$sig = $this->sign( $raw, 'app-secret' );

		$sent = [];
		$this->capture_send_seam( $sent );

		$response = $this->whatsapp()->handle_inbound( $this->inbound_request( $raw, $sig ) );

		$this->assertFalse( is_wp_error( $response ), 'A non-array payload is acked, not errored.' );
		$this->assertSame( 200, $response->get_status() );
		$this->assertFalse( (bool) ( $response->get_data()['sent'] ?? false ) );
		$this->assertSame( [], $sent, 'No send for a non-array payload.' );
	}

	public function test_signed_invalid_json_payload_is_acked_without_sending(): void {
		// Outright malformed JSON also decodes to null (not an array): same null path,
		// same benign ack, no parse error escapes, nothing is sent.
		$this->options['fahad_ai_whatsapp_enabled']    = 1;
		$this->options['fahad_ai_whatsapp_app_secret'] = 'app-secret';

		$raw = '{not valid json';
		$sig = $this->sign( $raw, 'app-secret' );

		$sent = [];
		$this->capture_send_seam( $sent );

		$response = $this->whatsapp()->handle_inbound( $this->inbound_request( $raw, $sig ) );

		$this->assertFalse( is_wp_error( $response ) );
		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( [], $sent );
	}

	// ── first_text_message(): a non-text message type is skipped (line 307) ─────────

	public function test_signed_non_text_message_type_is_acked_without_sending(): void {
		// A delivery whose message exists but is NOT type=text (here an image) hits the
		// `continue` at the type check, so no text is extracted: null → ack, no send.
		$this->options['fahad_ai_whatsapp_enabled']    = 1;
		$this->options['fahad_ai_whatsapp_app_secret'] = 'app-secret';

		$raw = json_encode( [
			'object' => 'whatsapp_business_account',
			'entry'  => [ [ 'changes' => [ [ 'value' => [
				'messaging_product' => 'whatsapp',
				'messages'          => [ [
					'from'  => '15551234567',
					'id'    => 'wamid.IMG',
					'type'  => 'image',
					'image' => [ 'id' => 'media-123' ],
				] ],
			] ] ] ] ],
		] );
		$sig = $this->sign( $raw, 'app-secret' );

		$sent = [];
		$this->capture_send_seam( $sent );

		$response = $this->whatsapp()->handle_inbound( $this->inbound_request( $raw, $sig ) );

		$this->assertFalse( is_wp_error( $response ), 'A non-text message type is acked, not errored.' );
		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( [], $sent, 'No reply is sent for a non-text message.' );
	}

	// ── first_text_message(): empty body / empty sender is skipped (line 314) ───────

	public function test_signed_whitespace_only_text_is_acked_without_sending(): void {
		// A type=text message whose body is whitespace-only trims to '' → the empty-text
		// guard `continue`s, no message is returned, and the delivery is acked with no
		// send. (Empty/whitespace-only text is deliberately treated as no message.)
		$this->options['fahad_ai_whatsapp_enabled']    = 1;
		$this->options['fahad_ai_whatsapp_app_secret'] = 'app-secret';

		$raw = json_encode( [
			'object' => 'whatsapp_business_account',
			'entry'  => [ [ 'changes' => [ [ 'value' => [
				'messaging_product' => 'whatsapp',
				'messages'          => [ [
					'from' => '15551234567',
					'id'   => 'wamid.BLANK',
					'type' => 'text',
					'text' => [ 'body' => "   \n\t  " ],
				] ],
			] ] ] ] ],
		] );
		$sig = $this->sign( $raw, 'app-secret' );

		$sent = [];
		$this->capture_send_seam( $sent );

		$response = $this->whatsapp()->handle_inbound( $this->inbound_request( $raw, $sig ) );

		$this->assertFalse( is_wp_error( $response ), 'A blank-text message is acked, not errored.' );
		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( [], $sent, 'No reply is sent for an empty-after-trim text body.' );
	}

	public function test_signed_text_with_empty_sender_is_acked_without_sending(): void {
		// A type=text message with real text but an EMPTY `from` also hits the same
		// guard (the OR on `'' === $from`): no recipient to reply to, so it is skipped
		// and the delivery is acked with nothing sent.
		$this->options['fahad_ai_whatsapp_enabled']    = 1;
		$this->options['fahad_ai_whatsapp_app_secret'] = 'app-secret';

		$raw = json_encode( [
			'object' => 'whatsapp_business_account',
			'entry'  => [ [ 'changes' => [ [ 'value' => [
				'messaging_product' => 'whatsapp',
				'messages'          => [ [
					'from' => '',
					'id'   => 'wamid.NOFROM',
					'type' => 'text',
					'text' => [ 'body' => 'hello there' ],
				] ],
			] ] ] ] ],
		] );
		$sig = $this->sign( $raw, 'app-secret' );

		$sent = [];
		$this->capture_send_seam( $sent );

		$response = $this->whatsapp()->handle_inbound( $this->inbound_request( $raw, $sig ) );

		$this->assertFalse( is_wp_error( $response ), 'A message with no sender is acked, not errored.' );
		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( [], $sent, 'No reply is sent when there is no sender to address.' );
	}
}
