<?php
/**
 * Supplementary line-coverage tests for Fahad_AI_API_Handler.
 *
 * These exercise the paths the primary ApiHandlerTest does not yet reach: the
 * agent-loop tool branches (Anthropic tool_use / OpenAI tool_calls and their
 * graceful-exhaustion fallbacks), the off-web text turn (run_text_turn, #62),
 * the direct cart-action happy path, the streaming endpoint (handle_stream /
 * run_stream_agent / stream_one_turn / sse_send), the merchant-config and
 * language-directive system-prompt slots, format_localized_amount, and the small
 * normalizer/guard helpers. Faithful TDD: each test asserts real behaviour.
 *
 * Conventions mirror ApiHandlerTest (the sibling): Brain\Monkey setUp/tearDown,
 * MockeryPHPUnitIntegration, Functions\stubs/when for WP/Woo seams, and Reflection
 * to reach private members + reset singletons.
 */

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;

class CoverageApiHandlerTest extends TestCase {

	use MockeryPHPUnitIntegration;

	/** @var array<int, callable> Registry pack snapshot, restored in tearDown. */
	private array $pack_snapshot = [];

	/** In-memory WP options stand-in for the seam helpers. */
	private array $options = [];

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$this->pack_snapshot = (array) ( new ReflectionProperty( Fahad_AI_Tool_Registry::class, 'pack_providers' ) )->getValue();
		Fahad_AI_Tool_Registry::reset_packs();
		( new ReflectionProperty( Fahad_AI_Tool_Registry::class, 'instance' ) )->setValue( null, null );
		( new ReflectionProperty( Fahad_AI_Tools::class, 'instance' ) )->setValue( null, null );

		Functions\stubs( [
			'sanitize_text_field'             => fn( $s ) => $s,
			'get_bloginfo'                    => fn() => 'Test Store',
			'get_woocommerce_currency_symbol' => fn() => '$',
			'get_option'                      => fn( $key, $default = '' ) => $default,
			'get_site_url'                    => fn() => 'http://example.com',
		] );
	}

	protected function tearDown(): void {
		( new ReflectionProperty( Fahad_AI_Tool_Registry::class, 'pack_providers' ) )->setValue( null, $this->pack_snapshot );
		Monkey\tearDown();
		parent::tearDown();
	}

	// ── shared helpers ──────────────────────────────────────────────────────────

	private function handler(): Fahad_AI_API_Handler {
		$ref = new ReflectionProperty( Fahad_AI_API_Handler::class, 'instance' );
		$ref->setValue( null, null );
		return Fahad_AI_API_Handler::instance();
	}

	private function set_option_alias( array $map ): void {
		Functions\when( 'get_option' )->alias(
			static fn( $key, $default = '' ) => $map[ $key ] ?? $default
		);
	}

	private function invoke( string $method, array $args = [] ) {
		$m = new ReflectionMethod( Fahad_AI_API_Handler::class, $method );
		return $m->invokeArgs( $this->handler(), $args );
	}

	/**
	 * Capture everything a closure writes to the SSE stream. sse_send() echoes, then
	 * ob_flush()es its own buffer down a level and flush()es, so a single ob_start()
	 * would lose the bytes. We stack TWO buffers: the inner one is what sse_send sees
	 * and flushes; the outer one catches those flushed bytes, which we then read.
	 *
	 * @param callable $fn The body that drives sse_send (directly or via a loop).
	 * @return string Everything written to the stream.
	 */
	private function capture_sse( callable $fn ): string {
		ob_start();          // outer: catches what the inner buffer flushes down
		ob_start();          // inner: the buffer sse_send() sees and ob_flush()es
		$fn();
		ob_end_flush();      // push any inner remainder down to the outer buffer
		return (string) ob_get_clean();
	}

	/** Wire the encode/filter/analytics-off seams the agent loops rely on. */
	private function loop_seams( array $option_map = [] ): void {
		$map = array_merge(
			[ Fahad_AI_Analytics::OPTION_ENABLED => 0 ], // analytics off → record short-circuits.
			$option_map
		);
		$this->set_option_alias( $map );
		Functions\when( 'apply_filters' )->alias( static fn( $hook, $value = null ) => $value );
		Functions\when( 'wp_json_encode' )->alias( static fn( $d ) => json_encode( $d ) );
		Functions\when( 'sanitize_textarea_field' )->returnArg();
		// run_text_turn()/handle_message() call wc_load_cart() behind function_exists().
		// In isolation it is undefined (the guard skips it), but once another suite has
		// mocked it via Brain\Monkey it becomes "known" and the guard runs it, stub it
		// as a harmless no-op so these tests are order-independent (sibling does the same).
		Functions\when( 'wc_load_cart' )->justReturn( null );
		( new ReflectionProperty( Fahad_AI_Analytics::class, 'instance' ) )->setValue( null, null );
	}

	/**
	 * Register a deterministic stub tool that returns a canned result, then reset the
	 * registry instance so the agent loop rebuilds its tool map and sees it. Lets the
	 * streaming/loop tests drive a tool that surfaces product cards / a comparison
	 * WITHOUT mocking WooCommerce. The pack is restored by tearDown's snapshot.
	 *
	 * @param string $name   Tool name the model will "call".
	 * @param array  $result The canned tool result.
	 */
	private function register_stub_tool( string $name, array $result ): void {
		Fahad_AI_Tool_Registry::register_pack(
			static function ( array $tools ) use ( $name, $result ) {
				$tools[] = [
					'name'        => $name,
					'description' => 'Stub tool for coverage.',
					'parameters'  => [ 'type' => 'object', 'properties' => new stdClass() ],
					'callback'    => static fn( array $input ) => $result,
				];
				return $tools;
			}
		);
		( new ReflectionProperty( Fahad_AI_Tool_Registry::class, 'instance' ) )->setValue( null, null );
		( new ReflectionProperty( Fahad_AI_Tools::class, 'instance' ) )->setValue( null, null );
	}

	/** An Anthropic assistant turn that requests a single tool call. */
	private function anthropic_tool_turn( string $name, array $input = [] ): array {
		return [
			'stop_reason' => 'tool_use',
			'content'     => [
				[ 'type' => 'tool_use', 'id' => 'tu1', 'name' => $name, 'input' => $input ],
			],
		];
	}

	private function anthropic_end( string $text ): array {
		return [ 'stop_reason' => 'end_turn', 'content' => [ [ 'type' => 'text', 'text' => $text ] ] ];
	}

	private function openai_tool_turn( string $name, string $args = '{}' ): array {
		return [ 'choices' => [ [
			'finish_reason' => 'tool_calls',
			'message'       => [ 'role' => 'assistant', 'content' => null, 'tool_calls' => [
				[ 'id' => 'c1', 'function' => [ 'name' => $name, 'arguments' => $args ] ],
			] ],
		] ] ];
	}

	private function openai_stop( string $text ): array {
		return [ 'choices' => [ [ 'finish_reason' => 'stop', 'message' => [ 'role' => 'assistant', 'content' => $text ] ] ] ];
	}

	/**
	 * Script wp_remote_post to return each queued body in order (200), and wire the
	 * wp_remote_retrieve_* helpers. The queue lets a single agent-loop run see a
	 * tool turn followed by a final answer.
	 *
	 * @param array $bodies Wire-format response bodies (already provider-shaped).
	 */
	private function script_transport( array $bodies ): void {
		$queue = new ArrayObject( $bodies );
		Functions\when( 'wp_remote_post' )->alias(
			static function ( $url, $args = [] ) use ( $queue ) {
				$body = $queue->count() ? $queue->offsetGet( 0 ) : [];
				if ( $queue->count() ) {
					$queue->offsetUnset( 0 );
					$queue->exchangeArray( array_values( $queue->getArrayCopy() ) );
				}
				return [ '__eval' => true, 'code' => 200, 'body' => json_encode( $body ) ];
			}
		);
		Functions\when( 'wp_remote_retrieve_response_code' )->alias( static fn( $r ) => is_array( $r ) ? ( $r['code'] ?? 0 ) : 0 );
		Functions\when( 'wp_remote_retrieve_body' )->alias( static fn( $r ) => is_array( $r ) ? ( $r['body'] ?? '' ) : '' );
	}

	// =========================================================================
	// handle_message() input guards (lines 29, 35)
	// =========================================================================

	public function test_handle_message_rejects_non_array_messages(): void {
		$req = Mockery::mock( 'WP_REST_Request' );
		$req->shouldReceive( 'get_param' )->with( 'messages' )->andReturn( 'not-an-array' );

		$result = $this->handler()->handle_message( $req );

		$this->assertTrue( is_wp_error( $result ) );
		$this->assertSame( 'invalid_messages', $result->get_error_code() );
	}

	public function test_handle_message_rejects_when_sanitized_is_empty(): void {
		// A non-empty array whose only entries are invalid roles sanitizes to empty,
		// hitting the second guard (line 35).
		Functions\when( 'sanitize_textarea_field' )->returnArg();

		$req = Mockery::mock( 'WP_REST_Request' );
		$req->shouldReceive( 'get_param' )->with( 'messages' )->andReturn( [ [ 'role' => 'system', 'content' => 'nope' ] ] );

		$result = $this->handler()->handle_message( $req );

		$this->assertTrue( is_wp_error( $result ) );
		$this->assertSame( 'empty_messages', $result->get_error_code() );
	}

	// =========================================================================
	// run_text_turn(), off-web channel (issue #62), lines 103-138
	// =========================================================================

	public function test_run_text_turn_empty_sanitized_returns_degraded_message(): void {
		// All-invalid roles → sanitized empty → the friendly degraded line (line 106).
		Functions\when( 'sanitize_textarea_field' )->returnArg();

		$reply = $this->handler()->run_text_turn( [ [ 'role' => 'system', 'content' => 'x' ] ] );

		$this->assertIsString( $reply );
		$this->assertStringContainsString( 'could not reach', $reply );
	}

	public function test_run_text_turn_no_key_returns_degraded_message(): void {
		// Valid messages but no provider key → empty chain → degraded line (line 119),
		// never a leaked "configure a key" error on a customer channel.
		$this->loop_seams( [ 'fahad_ai_provider' => 'anthropic' ] );

		$reply = $this->handler()->run_text_turn( [ [ 'role' => 'user', 'content' => 'hi' ] ] );

		$this->assertStringContainsString( 'could not reach', $reply );
	}

	public function test_run_text_turn_returns_reply_text_on_success(): void {
		// A keyed provider that answers → run_text_turn returns ONLY the message text.
		$this->loop_seams( [
			'fahad_ai_provider'          => 'anthropic',
			'fahad_ai_anthropic_api_key' => 'sk-ant',
			'fahad_ai_anthropic_model'   => 'claude-haiku-4-5-20251001',
		] );
		$this->script_transport( [ $this->anthropic_end( 'Here is your answer.' ) ] );

		$reply = $this->handler()->run_text_turn( [ [ 'role' => 'user', 'content' => 'hi' ] ] );

		$this->assertSame( 'Here is your answer.', $reply );
	}

	public function test_run_text_turn_degrades_when_provider_errors(): void {
		// The keyed provider returns a transport WP_Error every attempt → run_text_turn
		// degrades gracefully (lines 135-138) rather than surfacing the error.
		$this->loop_seams( [
			'fahad_ai_provider'          => 'anthropic',
			'fahad_ai_anthropic_api_key' => 'sk-ant',
		] );
		Functions\when( 'wp_remote_post' )->justReturn( new WP_Error( 'http', 'timeout' ) );

		$reply = $this->handler()->run_text_turn( [ [ 'role' => 'user', 'content' => 'hi' ] ] );

		$this->assertStringContainsString( 'could not reach', $reply );
	}

	// =========================================================================
	// has_provider_key(), unknown provider (line 160)
	// =========================================================================

	public function test_has_provider_key_false_for_unknown_provider(): void {
		$this->assertFalse( (bool) $this->invoke( 'has_provider_key', [ 'no-such-provider' ] ) );
	}

	// =========================================================================
	// agent_fallback_message() (lines 348-350)
	// =========================================================================

	public function test_agent_fallback_message_with_products_points_to_them(): void {
		$msg = (string) $this->invoke( 'agent_fallback_message', [ true ] );
		$this->assertStringContainsString( 'options based on what I found', $msg );
	}

	public function test_agent_fallback_message_without_products_asks_to_rephrase(): void {
		$msg = (string) $this->invoke( 'agent_fallback_message', [ false ] );
		$this->assertStringContainsString( 'rephrase', $msg );
	}

	// =========================================================================
	// run_anthropic_agent() tool_use branch + graceful exhaustion (384-420)
	// =========================================================================

	public function test_anthropic_agent_executes_tool_then_returns_final_answer(): void {
		// First turn: the model requests an (unknown) tool, execute() returns an error
		// array (no WC mocks needed). Second turn: a final end_turn answer. This drives
		// the whole tool_use block (385-406) and the end_turn return.
		$this->loop_seams( [
			'fahad_ai_anthropic_api_key' => 'sk-ant',
			'fahad_ai_anthropic_model'   => 'claude-haiku-4-5-20251001',
		] );
		$this->script_transport( [
			$this->anthropic_tool_turn( 'search_widgets', [ 'q' => 'x' ] ),
			$this->anthropic_end( 'All set, here is the answer.' ),
		] );

		$result = $this->invoke( 'run_anthropic_agent', [ [ [ 'role' => 'user', 'content' => 'hi' ] ] ] );

		$this->assertSame( 'All set, here is the answer.', $result['message'] );
		// The transcript carries the user turn, the assistant tool_use, the user
		// tool_result, then the final assistant answer.
		$this->assertGreaterThanOrEqual( 4, count( $result['messages'] ) );
		$this->assertSame( 'tool_result', $result['messages'][2]['content'][0]['type'] );
	}

	public function test_anthropic_agent_falls_back_after_iteration_cap(): void {
		// Every turn is a tool_use (the model never produces a final answer) → after the
		// iteration budget the loop returns the friendly fallback (lines 415-420).
		$this->loop_seams( [ 'fahad_ai_anthropic_api_key' => 'sk-ant' ] );
		// Always hand back a tool turn so the loop exhausts its budget.
		Functions\when( 'wp_remote_post' )->alias(
			static fn( $url, $args = [] ) => [ '__eval' => true, 'code' => 200, 'body' => json_encode(
				[ 'stop_reason' => 'tool_use', 'content' => [ [ 'type' => 'tool_use', 'id' => 'tu', 'name' => 'noop_tool', 'input' => [] ] ] ]
			) ]
		);
		Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 200 );
		Functions\when( 'wp_remote_retrieve_body' )->alias( static fn( $r ) => is_array( $r ) ? ( $r['body'] ?? '' ) : '' );

		$result = $this->invoke( 'run_anthropic_agent', [ [ [ 'role' => 'user', 'content' => 'hi' ] ] ] );

		// No products gathered (unknown tool) → the "trouble completing that" fallback.
		$this->assertStringContainsString( 'trouble completing', $result['message'] );
		$this->assertSame( [], $result['products'] );
	}

	public function test_anthropic_agent_skips_non_tool_use_blocks_in_a_tool_turn(): void {
		// A tool_use turn whose content ALSO carries a text block: the loop skips the
		// non-tool_use block (the `continue`, line 389) and executes only the tool_use.
		$this->loop_seams( [ 'fahad_ai_anthropic_api_key' => 'sk-ant' ] );
		$this->script_transport( [
			[ 'stop_reason' => 'tool_use', 'content' => [
				[ 'type' => 'text', 'text' => 'Let me look that up.' ],
				[ 'type' => 'tool_use', 'id' => 'tu1', 'name' => 'search_widgets', 'input' => [] ],
			] ],
			$this->anthropic_end( 'Here is the result.' ),
		] );

		$result = $this->invoke( 'run_anthropic_agent', [ [ [ 'role' => 'user', 'content' => 'hi' ] ] ] );

		$this->assertSame( 'Here is the result.', $result['message'] );
		// Exactly one tool_result was appended (the text block produced none).
		$this->assertSame( 'tool_result', $result['messages'][2]['content'][0]['type'] );
		$this->assertCount( 1, $result['messages'][2]['content'] );
	}

	public function test_anthropic_agent_breaks_on_unknown_stop_reason(): void {
		// A stop_reason that is neither end_turn nor tool_use → the loop breaks (line
		// 409) and returns the graceful fallback (no products gathered).
		$this->loop_seams( [ 'fahad_ai_anthropic_api_key' => 'sk-ant' ] );
		$this->script_transport( [ [ 'stop_reason' => 'max_tokens', 'content' => [] ] ] );

		$result = $this->invoke( 'run_anthropic_agent', [ [ [ 'role' => 'user', 'content' => 'hi' ] ] ] );

		$this->assertStringContainsString( 'trouble completing', $result['message'] );
		$this->assertSame( [], $result['products'] );
	}

	public function test_anthropic_agent_returns_wp_error_on_transport_failure(): void {
		// call_anthropic returns a WP_Error (transport failure) → the loop returns it
		// directly (the is_wp_error early return).
		$this->loop_seams( [ 'fahad_ai_anthropic_api_key' => 'sk-ant' ] );
		Functions\when( 'wp_remote_post' )->justReturn( new WP_Error( 'http', 'boom' ) );

		$result = $this->invoke( 'run_anthropic_agent', [ [ [ 'role' => 'user', 'content' => 'hi' ] ] ] );

		$this->assertTrue( is_wp_error( $result ) );
	}

	// =========================================================================
	// call_anthropic / call_openai, wp_remote_post WP_Error (lines 459, 627)
	// =========================================================================

	public function test_call_anthropic_returns_transport_wp_error(): void {
		$this->loop_seams( [ 'fahad_ai_anthropic_api_key' => 'sk-ant' ] );
		Functions\when( 'wp_remote_post' )->justReturn( new WP_Error( 'http_request_failed', 'down' ) );

		$result = $this->invoke( 'call_anthropic', [ [ [ 'role' => 'user', 'content' => 'hi' ] ], 0 ] );

		$this->assertTrue( is_wp_error( $result ) );
		$this->assertSame( 'down', $result->get_error_message() );
	}

	public function test_call_openai_returns_transport_wp_error(): void {
		$this->loop_seams( [
			'fahad_ai_provider'         => 'moonshot',
			'fahad_ai_moonshot_api_key' => 'sk-moon',
		] );
		Functions\when( 'wp_remote_post' )->justReturn( new WP_Error( 'http_request_failed', 'down' ) );

		$result = $this->invoke( 'call_openai', [ [ [ 'role' => 'user', 'content' => 'hi' ] ], 'moonshot', 0 ] );

		$this->assertTrue( is_wp_error( $result ) );
		$this->assertSame( 'down', $result->get_error_message() );
	}

	// =========================================================================
	// run_openai_agent() tool_calls branch + graceful exhaustion (534-569)
	// =========================================================================

	public function test_openai_agent_executes_tool_then_returns_final_answer(): void {
		$this->loop_seams( [
			'fahad_ai_provider'         => 'moonshot',
			'fahad_ai_moonshot_api_key' => 'sk-moon',
			'fahad_ai_moonshot_model'   => 'kimi-k2.6',
		] );
		$this->script_transport( [
			$this->openai_tool_turn( 'search_widgets', '{"q":"x"}' ),
			$this->openai_stop( 'Done, here you go.' ),
		] );

		$result = $this->invoke( 'run_openai_agent', [ [ [ 'role' => 'user', 'content' => 'hi' ] ], 'moonshot' ] );

		$this->assertSame( 'Done, here you go.', $result['message'] );
		// A `tool` role message was appended to the returned (client) transcript.
		$roles = array_column( $result['messages'], 'role' );
		$this->assertContains( 'tool', $roles );
	}

	public function test_openai_agent_falls_back_after_iteration_cap(): void {
		$this->loop_seams( [
			'fahad_ai_provider'         => 'moonshot',
			'fahad_ai_moonshot_api_key' => 'sk-moon',
		] );
		Functions\when( 'wp_remote_post' )->alias(
			static fn( $url, $args = [] ) => [ '__eval' => true, 'code' => 200, 'body' => json_encode(
				[ 'choices' => [ [ 'finish_reason' => 'tool_calls', 'message' => [
					'role' => 'assistant', 'content' => null,
					'tool_calls' => [ [ 'id' => 'c', 'function' => [ 'name' => 'noop_tool', 'arguments' => '{}' ] ] ],
				] ] ] ]
			) ]
		);
		Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 200 );
		Functions\when( 'wp_remote_retrieve_body' )->alias( static fn( $r ) => is_array( $r ) ? ( $r['body'] ?? '' ) : '' );

		$result = $this->invoke( 'run_openai_agent', [ [ [ 'role' => 'user', 'content' => 'hi' ] ], 'moonshot' ] );

		$this->assertStringContainsString( 'trouble completing', $result['message'] );
		$this->assertSame( [], $result['products'] );
	}

	public function test_openai_agent_breaks_on_unknown_finish_reason(): void {
		// A finish_reason that is neither stop nor tool_calls → the loop breaks (line
		// 558) and returns the graceful fallback.
		$this->loop_seams( [
			'fahad_ai_provider'         => 'moonshot',
			'fahad_ai_moonshot_api_key' => 'sk-moon',
		] );
		$this->script_transport( [ [ 'choices' => [ [
			'finish_reason' => 'length',
			'message'       => [ 'role' => 'assistant', 'content' => 'cut off' ],
		] ] ] ] );

		$result = $this->invoke( 'run_openai_agent', [ [ [ 'role' => 'user', 'content' => 'hi' ] ], 'moonshot' ] );

		$this->assertStringContainsString( 'trouble completing', $result['message'] );
		$this->assertSame( [], $result['products'] );
	}

	public function test_openai_agent_returns_wp_error_on_transport_failure(): void {
		$this->loop_seams( [
			'fahad_ai_provider'         => 'moonshot',
			'fahad_ai_moonshot_api_key' => 'sk-moon',
		] );
		Functions\when( 'wp_remote_post' )->justReturn( new WP_Error( 'http', 'boom' ) );

		$result = $this->invoke( 'run_openai_agent', [ [ [ 'role' => 'user', 'content' => 'hi' ] ], 'moonshot' ] );

		$this->assertTrue( is_wp_error( $result ) );
	}

	// =========================================================================
	// handle_cart_action() happy path (lines 279-296)
	// =========================================================================

	public function test_handle_cart_action_dispatches_and_returns_result(): void {
		Functions\when( 'sanitize_key' )->returnArg();
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'wp_json_encode' )->alias( static fn( $d ) => json_encode( $d ) );
		Functions\when( 'apply_filters' )->alias( static fn( $hook, $value = null ) => $value );

		// prime_cart_session(): wc_load_cart present, but WC()->session check fails
		// cheaply when WC() returns an object with a null session. The view_cart tool
		// reads WC()->cart, so give it an empty cart so dispatch returns the empty-cart
		// result without touching real WooCommerce.
		Functions\when( 'wc_load_cart' )->justReturn( null );
		$cart = Mockery::mock();
		$cart->shouldReceive( 'is_empty' )->andReturn( true );
		Functions\when( 'WC' )->justReturn( (object) [ 'session' => null, 'cart' => $cart ] );

		Functions\when( 'rest_ensure_response' )->alias( static function ( $data ) {
			$resp = Mockery::mock( 'WP_REST_Response' );
			$resp->shouldReceive( 'get_data' )->andReturn( $data );
			return $resp;
		} );

		$req = Mockery::mock( 'WP_REST_Request' );
		$req->shouldReceive( 'get_param' )->with( 'action' )->andReturn( 'view' );
		$req->shouldReceive( 'get_param' )->with( 'product_id' )->andReturn( 0 );
		$req->shouldReceive( 'get_param' )->with( 'quantity' )->andReturn( 1 );
		$req->shouldReceive( 'get_param' )->with( 'variation_id' )->andReturn( 0 );
		$req->shouldReceive( 'get_param' )->with( 'cart_item_key' )->andReturn( '' );

		$response = $this->handler()->handle_cart_action( $req );

		// view_cart dispatch ran (the tool produced a result array, wrapped in a response).
		$this->assertIsArray( $response->get_data() );
	}

	public function test_handle_cart_action_saves_session_when_available(): void {
		// WC()->session exposes save_data() → the explicit persist branch (293) runs.
		Functions\when( 'sanitize_key' )->returnArg();
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'wp_json_encode' )->alias( static fn( $d ) => json_encode( $d ) );
		Functions\when( 'apply_filters' )->alias( static fn( $hook, $value = null ) => $value );
		Functions\when( 'wc_load_cart' )->justReturn( null );

		$cart = Mockery::mock();
		$cart->shouldReceive( 'is_empty' )->andReturn( true );

		// The save branch guards on method_exists(WC()->session,'save_data'), which is
		// FALSE for a generic Mockery mock (methods are routed via __call, not declared),
		// so use a real anonymous class that actually declares save_data() and records it.
		$session = new class {
			public bool $saved = false;
			public function set_customer_session_cookie( bool $set = true ): void {}
			public function save_data(): void { $this->saved = true; }
		};
		Functions\when( 'WC' )->justReturn( (object) [ 'session' => $session, 'cart' => $cart ] );

		Functions\when( 'rest_ensure_response' )->alias( static function ( $data ) {
			$resp = Mockery::mock( 'WP_REST_Response' );
			$resp->shouldReceive( 'get_data' )->andReturn( $data );
			return $resp;
		} );

		$req = Mockery::mock( 'WP_REST_Request' );
		$req->shouldReceive( 'get_param' )->with( 'action' )->andReturn( 'view' );
		$req->shouldReceive( 'get_param' )->with( 'product_id' )->andReturn( 0 );
		$req->shouldReceive( 'get_param' )->with( 'quantity' )->andReturn( 1 );
		$req->shouldReceive( 'get_param' )->with( 'variation_id' )->andReturn( 0 );
		$req->shouldReceive( 'get_param' )->with( 'cart_item_key' )->andReturn( '' );

		$response = $this->handler()->handle_cart_action( $req );

		$this->assertIsArray( $response->get_data() );
		$this->assertTrue( $session->saved, 'The REST cart action must persist the session immediately.' );
	}

	// =========================================================================
	// merchant_config_block() (lines 873, 878, 883, 890)
	// =========================================================================

	public function test_merchant_config_block_empty_when_nothing_configured(): void {
		$this->assertSame( '', (string) $this->invoke( 'merchant_config_block' ) );
	}

	public function test_merchant_config_block_includes_tone_off_limits_and_promo(): void {
		$this->set_option_alias( [
			'fahad_ai_tone'           => 'luxury',
			'fahad_ai_off_limits'     => 'politics, medical advice',
			'fahad_ai_promo_emphasis' => 'highlight the winter sale',
		] );

		$block = (string) $this->invoke( 'merchant_config_block' );

		$this->assertStringContainsString( Fahad_AI_API_Handler::TONES['luxury'], $block );
		$this->assertStringContainsString( 'politics, medical advice', $block );
		$this->assertStringContainsString( 'highlight the winter sale', $block );
		$this->assertStringContainsString( 'Store preferences', $block );
	}

	public function test_merchant_config_block_includes_free_shipping_threshold(): void {
		Functions\when( 'wc_get_price_decimal_separator' )->justReturn( '.' );
		Functions\when( 'wc_get_price_thousand_separator' )->justReturn( ',' );
		$this->set_option_alias( [ 'fahad_ai_free_shipping_threshold' => 50.0 ] );

		$block = (string) $this->invoke( 'merchant_config_block' );

		$this->assertStringContainsString( 'Free shipping', $block );
		$this->assertStringContainsString( '$50', $block );
		$this->assertStringContainsString( 'Store preferences', $block );
	}

	public function test_merchant_config_block_omits_free_shipping_when_zero(): void {
		$this->set_option_alias( [ 'fahad_ai_free_shipping_threshold' => 0 ] );
		$block = (string) $this->invoke( 'merchant_config_block' );
		$this->assertStringNotContainsString( 'Free shipping', $block );
	}

	public function test_merchant_config_block_includes_return_policy(): void {
		$this->set_option_alias( [ 'fahad_ai_return_policy' => '30-day returns on unworn items with receipt.' ] );

		$block = (string) $this->invoke( 'merchant_config_block' );

		$this->assertStringContainsString( 'Returns', $block );
		$this->assertStringContainsString( '30-day returns on unworn items with receipt.', $block );
		$this->assertStringContainsString( 'Store preferences', $block );
	}

	public function test_merchant_config_block_omits_return_policy_when_blank(): void {
		$this->set_option_alias( [ 'fahad_ai_return_policy' => '   ' ] );
		$block = (string) $this->invoke( 'merchant_config_block' );
		$this->assertStringNotContainsString( 'Returns', $block );
	}

	// =========================================================================
	// language_directive(), specific (non-auto) value (line 918)
	// =========================================================================

	public function test_language_directive_pins_a_specific_set(): void {
		$this->set_option_alias( [ 'fahad_ai_languages' => 'English, French' ] );

		$directive = (string) $this->invoke( 'language_directive' );

		$this->assertStringContainsString( 'English, French', $directive );
		$this->assertStringNotContainsString( Fahad_AI_API_Handler::SUPPORTED_LANGUAGES, $directive );
	}

	public function test_language_directive_uses_supported_set_for_auto(): void {
		// 'auto' (default) is a config token → falls back to the supported set.
		$directive = (string) $this->invoke( 'language_directive' );
		$this->assertStringContainsString( Fahad_AI_API_Handler::SUPPORTED_LANGUAGES, $directive );
	}

	// =========================================================================
	// format_localized_amount() (lines 944-955)
	// =========================================================================

	public function test_format_localized_amount_uses_wc_separators_when_available(): void {
		Functions\when( 'wc_get_price_decimals' )->justReturn( 2 );
		Functions\when( 'wc_get_price_decimal_separator' )->justReturn( '.' );
		Functions\when( 'wc_get_price_thousand_separator' )->justReturn( ',' );

		$out = (string) $this->invoke( 'format_localized_amount', [ 1299.5, null ] );

		$this->assertSame( '$1,299.50', $out );
	}

	public function test_format_localized_amount_honours_explicit_decimals(): void {
		// Explicit decimals (0) override the WC decimals default; the separator helpers
		// resolve to the standard '.'/',' (stubbed so a leaked prior definition in the
		// same process can't feed an unstubbed value).
		Functions\when( 'wc_get_price_decimal_separator' )->justReturn( '.' );
		Functions\when( 'wc_get_price_thousand_separator' )->justReturn( ',' );

		$out = (string) $this->invoke( 'format_localized_amount', [ 1000.0, 0 ] );

		$this->assertSame( '$1,000', $out );
	}

	// =========================================================================
	// analytics_conversation_ref(), empty conversation (line 1175)
	// =========================================================================

	public function test_analytics_conversation_ref_empty_for_no_user_turn(): void {
		$ref = (string) $this->invoke( 'analytics_conversation_ref', [ [ [ 'role' => 'assistant', 'content' => 'hi' ] ] ] );
		$this->assertSame( '', $ref );
	}

	public function test_analytics_conversation_ref_hashes_first_user_turn(): void {
		Functions\when( 'wp_json_encode' )->alias( static fn( $d ) => json_encode( $d ) );
		$messages = [ [ 'role' => 'user', 'content' => 'find me shoes' ] ];
		$ref      = (string) $this->invoke( 'analytics_conversation_ref', [ $messages ] );
		$this->assertSame( substr( md5( 'find me shoes' ), 0, 16 ), $ref );
	}

	// =========================================================================
	// analytics_tool_trace(), OpenAI tool_calls shape (lines 1204-1206)
	// =========================================================================

	public function test_analytics_tool_trace_reads_openai_tool_calls(): void {
		$messages = [
			[ 'role' => 'assistant', 'tool_calls' => [
				[ 'id' => 'c1', 'function' => [ 'name' => 'search_products' ] ],
				[ 'id' => 'c2', 'function' => [ 'name' => '' ] ], // empty name is skipped
				[ 'id' => 'c3', 'function' => [ 'name' => 'add_to_cart' ] ],
			] ],
		];

		$trace = (array) $this->invoke( 'analytics_tool_trace', [ $messages ] );

		$this->assertSame( [ 'search_products', 'add_to_cart' ], $trace );
	}

	// =========================================================================
	// transcript_requires_login(), Anthropic array-content tool_result (1255-1258)
	// =========================================================================

	public function test_transcript_requires_login_detects_anthropic_tool_result(): void {
		$messages = [
			[ 'role' => 'user', 'content' => [
				[ 'type' => 'tool_result', 'tool_use_id' => 't1', 'content' => '{"requires_login":true}' ],
			] ],
		];

		$this->assertTrue( (bool) $this->invoke( 'transcript_requires_login', [ $messages ] ) );
	}

	public function test_transcript_requires_login_false_for_plain_user_content(): void {
		$messages = [ [ 'role' => 'user', 'content' => 'where is my order' ] ];
		$this->assertFalse( (bool) $this->invoke( 'transcript_requires_login', [ $messages ] ) );
	}

	// =========================================================================
	// trim_product_summary(), non-array entry (line 1370)
	// =========================================================================

	public function test_trim_product_summary_passes_non_array_through(): void {
		$this->assertSame( 'not-a-product', $this->invoke( 'trim_product_summary', [ 'not-a-product' ] ) );
	}

	// =========================================================================
	// apply_token_budget(), no genuine user turn protects final message (1441)
	// =========================================================================

	public function test_apply_token_budget_no_user_turn_protects_final_message(): void {
		// Over budget, and NOT a single genuine (string-content) user turn, only
		// assistant/tool messages. tail_start falls back to the last message (line 1441).
		$this->set_option_alias( [ 'fahad_ai_token_budget' => 30 ] );
		Functions\when( 'apply_filters' )->alias( static fn( $hook, $value = null ) => $value );
		Functions\when( 'wp_json_encode' )->alias( static fn( $d ) => json_encode( $d ) );

		$messages = [
			[ 'role' => 'assistant', 'content' => str_repeat( 'old ', 200 ) ],
			[ 'role' => 'assistant', 'content' => str_repeat( 'mid ', 200 ) ],
			[ 'role' => 'assistant', 'content' => 'the final assistant message' ],
		];

		$out  = $this->invoke( 'apply_token_budget', [ $messages ] );
		$last = end( $out );

		$this->assertSame( 'the final assistant message', $last['content'] );
		$this->assertLessThan( count( $messages ), count( $out ) );
	}

	// =========================================================================
	// fast_model_route(), option-driven routing (lines 1525, 1527)
	// =========================================================================

	public function test_fast_model_route_returns_fast_model_for_simple_turn(): void {
		// Routing enabled + a fast model set + no tools in play → the fast model.
		$this->set_option_alias( [
			'fahad_ai_fast_model_routing' => true,
			'fahad_ai_fast_model'         => 'claude-haiku-fast',
		] );

		$model = (string) $this->invoke( 'fast_model_route', [ 'claude-default', [ 'has_tools' => false ] ] );

		$this->assertSame( 'claude-haiku-fast', $model );
	}

	public function test_fast_model_route_keeps_default_when_fast_model_empty(): void {
		// Routing enabled but no fast model configured → the configured default stands
		// (line 1527 returns $default).
		$this->set_option_alias( [ 'fahad_ai_fast_model_routing' => true ] );

		$model = (string) $this->invoke( 'fast_model_route', [ 'claude-default', [ 'has_tools' => false ] ] );

		$this->assertSame( 'claude-default', $model );
	}

	public function test_fast_model_route_keeps_default_when_tools_in_play(): void {
		$model = (string) $this->invoke( 'fast_model_route', [ 'claude-default', [ 'has_tools' => true ] ] );
		$this->assertSame( 'claude-default', $model );
	}

	public function test_fast_model_route_keeps_default_when_routing_disabled(): void {
		$model = (string) $this->invoke( 'fast_model_route', [ 'claude-default', [ 'has_tools' => false ] ] );
		$this->assertSame( 'claude-default', $model );
	}

	// =========================================================================
	// tool_result_comparison() degenerate guard (line 1649) +
	// normalize_comparison_attributes() drop paths (1685, 1691, 1695)
	// =========================================================================

	public function test_comparison_with_under_two_valid_columns_yields_empty(): void {
		// Two products but one is malformed (no id) so normalize_card drops it, leaving
		// a single column, a degenerate "table" is suppressed (line 1649).
		$result = (array) $this->invoke( 'tool_result_comparison', [ 'compare', [
			'products'   => [
				[ 'id' => 10, 'name' => 'Valid' ],
				[ 'name' => 'Missing id' ],
			],
			'attributes' => [ [ 'name' => 'Material', 'values' => [ 10 => 'Cotton' ] ] ],
		] ] );

		$this->assertSame( [], $result );
	}

	public function test_normalize_comparison_attributes_non_array_returns_empty(): void {
		$this->assertSame( [], (array) $this->invoke( 'normalize_comparison_attributes', [ 'nope' ] ) );
	}

	public function test_normalize_comparison_attributes_drops_unnamed_and_non_array_rows(): void {
		// A non-array row (1691) and an unnamed row (1695) are dropped; a named row with
		// values keyed by product id survives, with int keys + string values.
		$rows = (array) $this->invoke( 'normalize_comparison_attributes', [ [
			'not-an-array',
			[ 'name' => '   ', 'values' => [ 10 => 'x' ] ],           // blank name → dropped
			[ 'name' => 'Weight', 'values' => [ '10' => 280, '11' => 340 ] ],
		] ] );

		$this->assertCount( 1, $rows );
		$this->assertSame( 'Weight', $rows[0]['name'] );
		$this->assertSame( [ 10 => '280', 11 => '340' ], $rows[0]['values'] );
	}

	// =========================================================================
	// normalize_card_variations(), non-array input + malformed entry (1764, 1770)
	// =========================================================================

	public function test_normalize_card_variations_non_array_returns_empty(): void {
		$this->assertSame( [], (array) $this->invoke( 'normalize_card_variations', [ 'nope' ] ) );
	}

	public function test_normalize_card_variations_skips_non_array_entries(): void {
		// A non-array variation entry is skipped (line 1770); a valid one is kept.
		$out = (array) $this->invoke( 'normalize_card_variations', [ [
			'not-an-array',
			[ 'variation_id' => 55, 'label' => 'Large', 'price' => '$5.00', 'in_stock' => true ],
		] ] );

		$this->assertCount( 1, $out );
		$this->assertSame( 55, $out[0]['variation_id'] );
	}

	// =========================================================================
	// stream_provider() (lines 1834-1835)
	// =========================================================================

	public function test_stream_provider_returns_configured_openai_provider(): void {
		$this->set_option_alias( [ 'fahad_ai_provider' => 'openai' ] );
		$this->assertSame( 'openai', (string) $this->invoke( 'stream_provider' ) );
	}

	public function test_stream_provider_falls_back_to_moonshot_for_anthropic(): void {
		// The native anthropic provider is not openai-type → defensive moonshot default.
		$this->set_option_alias( [ 'fahad_ai_provider' => 'anthropic' ] );
		$this->assertSame( 'moonshot', (string) $this->invoke( 'stream_provider' ) );
	}

	// =========================================================================
	// sse_send() (lines 2091-2096)
	// =========================================================================

	public function test_sse_send_emits_a_data_frame(): void {
		Functions\when( 'wp_json_encode' )->alias( static fn( $d ) => json_encode( $d ) );

		$out = $this->capture_sse( fn() => $this->invoke( 'sse_send', [ 'chunk', [ 'content' => 'hello' ] ] ) );

		$this->assertStringContainsString( 'data: ', $out );
		$decoded = json_decode( trim( substr( $out, strlen( 'data: ' ) ) ), true );
		$this->assertSame( 'chunk', $decoded['type'] );
		$this->assertSame( 'hello', $decoded['content'] );
	}

	// =========================================================================
	// handle_stream() input guards (lines 1843-1855)
	// =========================================================================

	public function test_handle_stream_emits_error_and_exits_on_invalid_messages(): void {
		// handle_stream() calls exit; run it in a forked child so PHPUnit survives, and
		// assert the SSE error frame it emitted (lines 1845-1847).
		$out = $this->run_in_child( static function () {
			Functions\when( 'wp_json_encode' )->alias( static fn( $d ) => json_encode( $d ) );
			$req = Mockery::mock( 'WP_REST_Request' );
			$req->shouldReceive( 'get_param' )->with( 'messages' )->andReturn( null );
			( Fahad_AI_API_Handler::instance() )->handle_stream( $req );
		} );

		$this->assertStringContainsString( '"type":"error"', $out );
		$this->assertStringContainsString( 'messages array is required', $out );
	}

	public function test_handle_stream_emits_error_when_sanitized_empty(): void {
		$out = $this->run_in_child( static function () {
			Functions\when( 'wp_json_encode' )->alias( static fn( $d ) => json_encode( $d ) );
			Functions\when( 'sanitize_textarea_field' )->returnArg();
			$req = Mockery::mock( 'WP_REST_Request' );
			$req->shouldReceive( 'get_param' )->with( 'messages' )->andReturn( [ [ 'role' => 'system', 'content' => 'x' ] ] );
			( Fahad_AI_API_Handler::instance() )->handle_stream( $req );
		} );

		$this->assertStringContainsString( '"type":"error"', $out );
		$this->assertStringContainsString( 'No valid messages provided', $out );
	}

	public function test_handle_stream_runs_the_full_streaming_path(): void {
		// The happy path past the guards: handle_stream() clears buffers, primes the
		// cart, writes the SSE headers, closes the session lock, runs the stream agent,
		// then exits. Driven in a forked child (it ends with exit). Under output
		// buffering headers_sent() is false, so header() is silent, we assert the SSE
		// frames the agent streamed reached the client. (pcov does not attribute lines
		// executed in a forked child back to the parent's collector, so handle_stream's
		// body shows as uncovered even though this exercises it end to end, see the
		// uncoverableNotes; the exit()+full-buffer-teardown shape makes in-process pcov
		// attribution impossible without breaking the PHPUnit result protocol.)
		$out = $this->run_in_child( function () {
			$this->loop_seams( [
				'fahad_ai_provider'         => 'moonshot',
				'fahad_ai_moonshot_api_key' => 'sk-moon',
			] );
			Functions\when( 'wc_load_cart' )->justReturn( null );
			$session = Mockery::mock();
			$session->shouldReceive( 'set_customer_session_cookie' )->byDefault();
			Functions\when( 'WC' )->justReturn( (object) [ 'session' => $session ] );
			$this->script_transport( [ $this->openai_stop( 'Streamed answer.' ) ] );

			$req = Mockery::mock( 'WP_REST_Request' );
			$req->shouldReceive( 'get_param' )->with( 'messages' )->andReturn( [ [ 'role' => 'user', 'content' => 'hi' ] ] );

			$this->handler()->handle_stream( $req );
		} );

		$this->assertStringContainsString( 'Streamed answer.', $out );
		$this->assertStringContainsString( '"type":"done"', $out );
	}

	// =========================================================================
	// run_stream_agent(), the streaming agent loop (lines 1919-2036)
	// =========================================================================

	public function test_run_stream_agent_streams_text_then_done(): void {
		// A single stop turn → a `chunk` (the assistant text) and a `done` frame, with
		// the final-turn analytics terminal point (analytics off → no persistence).
		$this->loop_seams( [
			'fahad_ai_provider'         => 'moonshot',
			'fahad_ai_moonshot_api_key' => 'sk-moon',
		] );
		$this->script_transport( [ $this->openai_stop( 'Here are some options.' ) ] );

		$out = $this->capture_sse( fn() => $this->invoke( 'run_stream_agent', [ [ [ 'role' => 'user', 'content' => 'hi' ] ], 'moonshot' ] ) );

		$this->assertStringContainsString( '"type":"chunk"', $out );
		$this->assertStringContainsString( 'Here are some options.', $out );
		$this->assertStringContainsString( '"type":"done"', $out );
	}

	public function test_run_stream_agent_executes_tool_then_completes(): void {
		// Turn 1 streams a tool call (emits a `tool` frame, executes the unknown tool , 
		// an error result, so no `products` frame), turn 2 is the final stop. Exercises
		// the tool-execution block (1990-2022) and the final terminal point.
		$this->loop_seams( [
			'fahad_ai_provider'         => 'moonshot',
			'fahad_ai_moonshot_api_key' => 'sk-moon',
		] );
		$this->script_transport( [
			$this->openai_tool_turn( 'search_widgets', '{"q":"x"}' ),
			$this->openai_stop( 'All done.' ),
		] );

		$out = $this->capture_sse( fn() => $this->invoke( 'run_stream_agent', [ [ [ 'role' => 'user', 'content' => 'hi' ] ], 'moonshot' ] ) );

		$this->assertStringContainsString( '"type":"tool"', $out );
		$this->assertStringContainsString( 'search_widgets', $out );
		$this->assertStringContainsString( '"type":"done"', $out );
		$this->assertStringContainsString( 'All done.', $out );
	}

	public function test_run_stream_agent_emits_products_for_a_product_tool(): void {
		// A streamed tool call whose (stubbed) result is a product list → the loop emits
		// a `products` SSE event and marks sent_products (lines 2004-2005).
		$this->loop_seams( [
			'fahad_ai_provider'         => 'moonshot',
			'fahad_ai_moonshot_api_key' => 'sk-moon',
		] );
		$this->register_stub_tool( 'find_widgets', [
			'found'    => 1,
			'products' => [ [ 'id' => 7, 'name' => 'Widget', 'price' => '$5.00', 'in_stock' => true ] ],
		] );
		$this->script_transport( [
			$this->openai_tool_turn( 'find_widgets', '{}' ),
			$this->openai_stop( 'There it is.' ),
		] );

		$out = $this->capture_sse( fn() => $this->invoke( 'run_stream_agent', [ [ [ 'role' => 'user', 'content' => 'hi' ] ], 'moonshot' ] ) );

		$this->assertStringContainsString( '"type":"products"', $out );
		$this->assertStringContainsString( '"id":7', $out );
		$this->assertStringContainsString( '"type":"done"', $out );
	}

	public function test_run_stream_agent_emits_comparison_for_a_comparison_tool(): void {
		// A streamed tool call returning a comparison shape → a `comparison` SSE event
		// (line 2014) and NO product cards (comparison-shaped results emit none).
		$this->loop_seams( [
			'fahad_ai_provider'         => 'moonshot',
			'fahad_ai_moonshot_api_key' => 'sk-moon',
		] );
		$this->register_stub_tool( 'compare_widgets', [
			'found'      => 2,
			'products'   => [
				[ 'id' => 1, 'name' => 'A', 'price' => '$1', 'in_stock' => true ],
				[ 'id' => 2, 'name' => 'B', 'price' => '$2', 'in_stock' => true ],
			],
			'attributes' => [ [ 'name' => 'Color', 'values' => [ 1 => 'Red', 2 => 'Blue' ] ] ],
		] );
		$this->script_transport( [
			$this->openai_tool_turn( 'compare_widgets', '{}' ),
			$this->openai_stop( 'Compared.' ),
		] );

		$out = $this->capture_sse( fn() => $this->invoke( 'run_stream_agent', [ [ [ 'role' => 'user', 'content' => 'hi' ] ], 'moonshot' ] ) );

		$this->assertStringContainsString( '"type":"comparison"', $out );
		$this->assertStringNotContainsString( '"type":"products"', $out, 'A comparison emits no product cards.' );
	}

	public function test_run_stream_agent_flags_add_to_cart(): void {
		// A streamed add_to_cart tool call flips the added_to_cart funnel flag (line
		// 1994). The stub returns a non-product cart result, so no products event fires,
		// but the `tool` event names add_to_cart and the turn completes.
		$this->loop_seams( [
			'fahad_ai_provider'         => 'moonshot',
			'fahad_ai_moonshot_api_key' => 'sk-moon',
		] );
		$this->register_stub_tool( 'add_to_cart', [
			'success'  => true,
			'message'  => 'Added to your cart.',
			'cart_url' => 'https://example.com/cart',
		] );
		$this->script_transport( [
			$this->openai_tool_turn( 'add_to_cart', '{"product_id":7}' ),
			$this->openai_stop( 'Added.' ),
		] );

		$out = $this->capture_sse( fn() => $this->invoke( 'run_stream_agent', [ [ [ 'role' => 'user', 'content' => 'add it' ] ], 'moonshot' ] ) );

		$this->assertStringContainsString( '"name":"add_to_cart"', $out );
		$this->assertStringContainsString( '"type":"done"', $out );
	}

	public function test_run_stream_agent_degrades_on_stream_error(): void {
		// A transport error every turn → the graceful degraded chunk + done (1954-1962).
		$this->loop_seams( [
			'fahad_ai_provider'         => 'moonshot',
			'fahad_ai_moonshot_api_key' => 'sk-moon',
		] );
		Functions\when( 'wp_remote_post' )->justReturn( new WP_Error( 'http', 'boom' ) );

		$out = $this->capture_sse( fn() => $this->invoke( 'run_stream_agent', [ [ [ 'role' => 'user', 'content' => 'hi' ] ], 'moonshot' ] ) );

		$this->assertStringContainsString( 'could not reach', $out );
		$this->assertStringContainsString( '"type":"done"', $out );
		// The raw error text must never reach the client.
		$this->assertStringNotContainsString( 'boom', $out );
	}

	public function test_run_stream_agent_exhausts_budget_with_fallback(): void {
		// Every turn returns a tool call (never a stop) → after the iteration cap, the
		// graceful-exhaustion chunk + done (1029-2036).
		$this->loop_seams( [
			'fahad_ai_provider'         => 'moonshot',
			'fahad_ai_moonshot_api_key' => 'sk-moon',
		] );
		Functions\when( 'wp_remote_post' )->alias(
			static fn( $url, $args = [] ) => [ '__eval' => true, 'code' => 200, 'body' => json_encode(
				[ 'choices' => [ [ 'finish_reason' => 'tool_calls', 'message' => [
					'role' => 'assistant', 'content' => null,
					'tool_calls' => [ [ 'id' => 'c', 'function' => [ 'name' => 'noop_tool', 'arguments' => '{}' ] ] ],
				] ] ] ]
			) ]
		);
		Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 200 );
		Functions\when( 'wp_remote_retrieve_body' )->alias( static fn( $r ) => is_array( $r ) ? ( $r['body'] ?? '' ) : '' );

		$out = $this->capture_sse( fn() => $this->invoke( 'run_stream_agent', [ [ [ 'role' => 'user', 'content' => 'hi' ] ], 'moonshot' ] ) );

		// No products surfaced (unknown tool) → the rephrase fallback, then done.
		$this->assertStringContainsString( 'trouble completing', $out );
		$this->assertStringContainsString( '"type":"done"', $out );
	}

	// =========================================================================
	// stream_one_turn(), error path + no-text path
	// =========================================================================

	public function test_stream_one_turn_returns_error_on_wp_error(): void {
		$this->loop_seams( [
			'fahad_ai_provider'         => 'moonshot',
			'fahad_ai_moonshot_api_key' => 'sk-moon',
		] );
		Functions\when( 'wp_remote_post' )->justReturn( new WP_Error( 'http', 'upstream down' ) );

		ob_start();
		[ $text, $tool_calls, $error ] = $this->invoke( 'stream_one_turn', [ [ [ 'role' => 'user', 'content' => 'hi' ] ], 'moonshot', 0 ] );
		ob_end_clean();

		$this->assertSame( '', $text );
		$this->assertSame( [], $tool_calls );
		$this->assertSame( 'upstream down', $error );
	}

	public function test_stream_one_turn_no_text_emits_no_chunk(): void {
		// A turn with only tool_calls and empty content → no `chunk` frame is emitted
		// (the '' !== $text guard), but the tool calls are returned.
		$this->loop_seams( [
			'fahad_ai_provider'         => 'moonshot',
			'fahad_ai_moonshot_api_key' => 'sk-moon',
		] );
		$this->script_transport( [ [ 'choices' => [ [
			'finish_reason' => 'tool_calls',
			'message'       => [ 'role' => 'assistant', 'content' => '', 'tool_calls' => [
				[ 'id' => 'c1', 'function' => [ 'name' => 'search_products', 'arguments' => '{"q":"x"}' ] ],
			] ],
		] ] ] ] );

		ob_start();
		[ $text, $tool_calls, $error ] = $this->invoke( 'stream_one_turn', [ [ [ 'role' => 'user', 'content' => 'hi' ] ], 'moonshot', 0 ] );
		$out = ob_get_clean();

		$this->assertNull( $error );
		$this->assertSame( '', $text );
		$this->assertSame( 'search_products', $tool_calls[0]['name'] );
		$this->assertStringNotContainsString( '"type":"chunk"', $out );
	}

	// ── child-process runner for the exit()-terminating handle_stream path ──────

	/**
	 * Run a closure in a forked child and return everything it wrote to STDOUT.
	 *
	 * handle_stream() ends with exit AND tears down every output-buffer level
	 * (while(ob_get_level()) ob_end_clean()), so an ob_start() capture would be
	 * discarded. We instead redirect the child's real STDOUT (fd 1) to a temp file:
	 * closing STDOUT frees fd 1, and the next fopen() reclaims it, so all subsequent
	 * echo/print/flush output lands in the file regardless of buffering. Requires
	 * pcntl (present in the test PHP); skips otherwise.
	 *
	 * @param callable $fn The body to run in the child (typically ending at exit()).
	 */
	private function run_in_child( callable $fn ): string {
		if ( ! function_exists( 'pcntl_fork' ) ) {
			$this->markTestSkipped( 'pcntl not available for the exit()-terminating stream path.' );
		}

		$tmp = tempnam( sys_get_temp_dir(), 'sse' );
		$pid = pcntl_fork();

		if ( -1 === $pid ) {
			$this->fail( 'pcntl_fork failed' );
		}

		if ( 0 === $pid ) {
			// Child: drop any inherited output buffer so writes go straight to fd 1,
			// then point fd 1 (STDOUT) at the temp file so output survives the buffer
			// teardown inside handle_stream(); then run the body (which exits).
			while ( ob_get_level() ) {
				ob_end_clean();
			}
			fclose( STDOUT );
			$fd1 = fopen( $tmp, 'w' ); // reclaims fd 1
			try {
				$fn();
			} catch ( \Throwable $e ) {
				// fall through to the guaranteed exit below
			}
			// Flush any remaining buffered output down to fd 1, then exit.
			while ( ob_get_level() ) {
				ob_end_flush();
			}
			fflush( $fd1 );
			exit( 0 );
		}

		// Parent: wait for the child, read what it wrote.
		pcntl_waitpid( $pid, $status );
		$out = (string) file_get_contents( $tmp );
		@unlink( $tmp );
		return $out;
	}
}
