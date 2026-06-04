<?php
defined( 'ABSPATH' ) || exit;

/**
 * Handles the REST endpoint and drives the agentic loop for both
 * Anthropic (Claude) and Moonshot AI (OpenAI-compatible) providers.
 */
final class Mayaai_API_Handler {

	private static $instance = null;

	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {}

	// =========================================================================
	// REST endpoint
	// =========================================================================

	public function handle_message( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$messages = $request->get_param( 'messages' );

		if ( empty( $messages ) || ! is_array( $messages ) ) {
			return new WP_Error( 'invalid_messages', __( 'A messages array is required.', 'maya-ai-shopping-assistant-for-woocommerce' ), [ 'status' => 400 ] );
		}

		$sanitized = $this->sanitize_messages( $messages );

		if ( empty( $sanitized ) ) {
			return new WP_Error( 'empty_messages', __( 'No valid messages provided.', 'maya-ai-shopping-assistant-for-woocommerce' ), [ 'status' => 400 ] );
		}

		if ( function_exists( 'wc_load_cart' ) ) {
			wc_load_cart();
		}

		$provider = get_option( 'mayaai_provider', 'anthropic' );

		$result = ( 'moonshot' === $provider )
			? $this->run_moonshot_agent( $sanitized )
			: $this->run_anthropic_agent( $sanitized );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return rest_ensure_response( $result );
	}

	// =========================================================================
	// Anthropic (Claude) — tool_use / end_turn
	// =========================================================================

	private function run_anthropic_agent( array $messages ): array|WP_Error {
		$tools = Mayaai_Tools::instance();
		$max   = 8;

		for ( $i = 0; $i < $max; $i++ ) {
			$response = $this->call_anthropic( $messages );

			if ( is_wp_error( $response ) ) {
				return $response;
			}

			$stop_reason = $response['stop_reason'] ?? 'end_turn';
			$content     = $response['content']     ?? [];

			$messages[] = [ 'role' => 'assistant', 'content' => $content ];

			if ( 'end_turn' === $stop_reason ) {
				$text = '';
				foreach ( $content as $block ) {
					if ( ( $block['type'] ?? '' ) === 'text' ) {
						$text .= $block['text'];
					}
				}
				return [ 'message' => trim( $text ), 'messages' => $messages ];
			}

			if ( 'tool_use' === $stop_reason ) {
				$tool_results = [];

				foreach ( $content as $block ) {
					if ( ( $block['type'] ?? '' ) !== 'tool_use' ) {
						continue;
					}
					$result         = $tools->execute( $block['name'], $block['input'] ?? [] );
					$tool_results[] = [
						'type'        => 'tool_result',
						'tool_use_id' => $block['id'],
						'content'     => wp_json_encode( $result ),
					];
				}

				$messages[] = [ 'role' => 'user', 'content' => $tool_results ];
				continue;
			}

			break;
		}

		return new WP_Error( 'agent_loop', __( 'Agent exceeded maximum iterations.', 'maya-ai-shopping-assistant-for-woocommerce' ), [ 'status' => 500 ] );
	}

	private function call_anthropic( array $messages ): array|WP_Error {
		$api_key = get_option( 'mayaai_anthropic_api_key', '' );

		if ( empty( $api_key ) ) {
			return new WP_Error( 'no_api_key', __( 'Anthropic API key is not configured.', 'maya-ai-shopping-assistant-for-woocommerce' ), [ 'status' => 500 ] );
		}

		$model = get_option( 'mayaai_anthropic_model', 'claude-haiku-4-5-20251001' );

		$payload = [
			'model'      => $model,
			'max_tokens' => 1024,
			'system'     => $this->get_system_prompt(),
			'tools'      => $this->get_anthropic_tools(),
			'messages'   => $messages,
		];

		$response = wp_remote_post( 'https://api.anthropic.com/v1/messages', [
			'timeout' => 30,
			'headers' => [
				'x-api-key'         => $api_key,
				'anthropic-version' => '2023-06-01',
				'content-type'      => 'application/json',
			],
			'body' => wp_json_encode( $payload ),
		] );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( 200 !== $code ) {
			$msg = $body['error']['message'] ?? sprintf(
				/* translators: %d: HTTP status code from the Anthropic API */
				__( 'Anthropic API error (HTTP %d).', 'maya-ai-shopping-assistant-for-woocommerce' ),
				$code
			);
			return new WP_Error( 'api_error', $msg, [ 'status' => 502 ] );
		}

		return $body;
	}

	// =========================================================================
	// Moonshot AI — OpenAI-compatible (tool_calls / stop)
	// =========================================================================

	private function run_moonshot_agent( array $messages ): array|WP_Error {
		$tools = Mayaai_Tools::instance();
		$max   = 8;

		// Moonshot uses a system message as the first entry, not a top-level field.
		$with_system = array_merge(
			[ [ 'role' => 'system', 'content' => $this->get_system_prompt() ] ],
			$messages
		);

		for ( $i = 0; $i < $max; $i++ ) {
			$response = $this->call_moonshot( $with_system );

			if ( is_wp_error( $response ) ) {
				return $response;
			}

			$choice        = $response['choices'][0]         ?? [];
			$msg           = $choice['message']              ?? [];
			$finish_reason = $choice['finish_reason']        ?? 'stop';

			// Append the raw assistant message into both arrays.
			$with_system[] = $msg;
			$messages[]    = $msg;

			if ( 'stop' === $finish_reason ) {
				return [
					'message'  => trim( $msg['content'] ?? '' ),
					'messages' => $messages,   // returned to client (no system msg)
				];
			}

			if ( 'tool_calls' === $finish_reason ) {
				foreach ( $msg['tool_calls'] ?? [] as $call ) {
					$name  = $call['function']['name']      ?? '';
					$input = json_decode( $call['function']['arguments'] ?? '{}', true ) ?? [];

					$result = $tools->execute( $name, $input );

					$tool_msg = [
						'role'         => 'tool',
						'tool_call_id' => $call['id'],
						'content'      => wp_json_encode( $result ),
					];

					$with_system[] = $tool_msg;
					$messages[]    = $tool_msg;
				}
				continue;
			}

			break;
		}

		return new WP_Error( 'agent_loop', __( 'Agent exceeded maximum iterations.', 'maya-ai-shopping-assistant-for-woocommerce' ), [ 'status' => 500 ] );
	}

	private function call_moonshot( array $messages ): array|WP_Error {
		$api_key = get_option( 'mayaai_moonshot_api_key', '' );

		if ( empty( $api_key ) ) {
			return new WP_Error( 'no_api_key', __( 'Moonshot API key is not configured.', 'maya-ai-shopping-assistant-for-woocommerce' ), [ 'status' => 500 ] );
		}

		$model = get_option( 'mayaai_moonshot_model', 'moonshot-v1-32k' );

		$payload = [
			'model'      => $model,
			'max_tokens' => 1024,
			'messages'   => $messages,
			'tools'      => $this->get_openai_tools(),
		];

		$response = wp_remote_post( 'https://api.moonshot.ai/v1/chat/completions', [
			'timeout' => 30,
			'headers' => [
				'Authorization' => 'Bearer ' . $api_key,
				'Content-Type'  => 'application/json',
			],
			'body' => wp_json_encode( $payload ),
		] );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( 200 !== $code ) {
			$msg = $body['error']['message'] ?? sprintf(
				/* translators: %d: HTTP status code from the Moonshot API */
				__( 'Moonshot API error (HTTP %d).', 'maya-ai-shopping-assistant-for-woocommerce' ),
				$code
			);
			return new WP_Error( 'api_error', $msg, [ 'status' => 502 ] );
		}

		return $body;
	}

	// =========================================================================
	// Tool definitions — Anthropic format & OpenAI format
	// =========================================================================

	/**
	 * Canonical tool spec (used to derive both formats).
	 */
	public function tool_specs(): array {
		return [
			[
				'name'        => 'search_products',
				'description' => 'Search for products by name, category, or price range. Use this before recommending products.',
				'parameters'  => [
					'type'       => 'object',
					'properties' => [
						'query'     => [ 'type' => 'string',  'description' => 'Search term' ],
						'category'  => [ 'type' => 'string',  'description' => 'Category slug or name' ],
						'min_price' => [ 'type' => 'number',  'description' => 'Minimum price' ],
						'max_price' => [ 'type' => 'number',  'description' => 'Maximum price' ],
						'limit'     => [ 'type' => 'integer', 'description' => 'Max results (default 5, max 10)' ],
					],
				],
			],
			[
				'name'        => 'get_product_details',
				'description' => 'Get full details for a product — description, price, stock, variations.',
				'parameters'  => [
					'type'       => 'object',
					'properties' => [
						'product_id' => [ 'type' => 'integer', 'description' => 'The WooCommerce product ID' ],
					],
					'required' => [ 'product_id' ],
				],
			],
			[
				'name'        => 'add_to_cart',
				'description' => "Add a product to the customer's shopping cart.",
				'parameters'  => [
					'type'       => 'object',
					'properties' => [
						'product_id'   => [ 'type' => 'integer', 'description' => 'Product ID to add' ],
						'quantity'     => [ 'type' => 'integer', 'description' => 'Quantity (default 1)' ],
						'variation_id' => [ 'type' => 'integer', 'description' => 'Variation ID for variable products' ],
					],
					'required' => [ 'product_id' ],
				],
			],
			[
				'name'        => 'view_cart',
				'description' => "View the current contents of the customer's cart, totals, and checkout URL.",
				'parameters'  => [
					'type'       => 'object',
					'properties' => new stdClass(),
				],
			],
			[
				'name'        => 'remove_from_cart',
				'description' => "Remove an item from the cart using its cart_item_key (from view_cart).",
				'parameters'  => [
					'type'       => 'object',
					'properties' => [
						'cart_item_key' => [ 'type' => 'string', 'description' => 'Cart item key from view_cart or add_to_cart' ],
					],
					'required' => [ 'cart_item_key' ],
				],
			],
		];
	}

	private function get_anthropic_tools(): array {
		return array_map( function ( $spec ) {
			return [
				'name'         => $spec['name'],
				'description'  => $spec['description'],
				'input_schema' => $spec['parameters'],
			];
		}, $this->tool_specs() );
	}

	private function get_openai_tools(): array {
		return array_map( function ( $spec ) {
			return [
				'type'     => 'function',
				'function' => [
					'name'        => $spec['name'],
					'description' => $spec['description'],
					'parameters'  => $spec['parameters'],
				],
			];
		}, $this->tool_specs() );
	}

	// =========================================================================
	// System prompt
	// =========================================================================

	private function get_system_prompt(): string {
		$custom = get_option( 'mayaai_system_prompt', '' );
		if ( ! empty( $custom ) ) {
			return $custom;
		}

		$store_name = get_bloginfo( 'name' );
		$currency   = get_woocommerce_currency_symbol();

		return "You are a helpful shopping assistant for {$store_name}. Help customers find products, answer questions, and manage their cart.

Currency: {$currency}

Linking rules — follow exactly:
- Always format product names as markdown links using the url field from tool results: [Product Name](url)
- After a successful add_to_cart, always end your reply with these two links on the same line: [View Cart](cart_url) · [Checkout](checkout_url) — replace cart_url and checkout_url with the actual values from the tool result.
- When the customer asks to check out or go to checkout, include: [Proceed to Checkout](checkout_url) — using the checkout_url from view_cart or add_to_cart results.
- Only use markdown for product names and cart/checkout links — no other markdown formatting.

Guidelines:
- Always use search_products or get_product_details before recommending a product — never invent product details.
- When a customer wants to buy something, confirm the product, then use add_to_cart.
- Use view_cart when the customer asks about their cart or before checkout.
- Keep responses concise and friendly.
- For order status, account issues, or returns, direct the customer to the store's support team.";
	}

	// =========================================================================
	// Input sanitization
	// =========================================================================

	public function sanitize_messages( array $messages ): array {
		$out             = [];
		$allowed_roles   = [ 'user', 'assistant', 'tool' ];

		foreach ( $messages as $msg ) {
			if ( ! isset( $msg['role'] ) ) {
				continue;
			}

			if ( ! in_array( $msg['role'], $allowed_roles, true ) ) {
				continue;
			}

			// Simple string content — sanitize it.
			if ( isset( $msg['content'] ) && is_string( $msg['content'] ) ) {
				$out[] = [
					'role'    => $msg['role'],
					'content' => sanitize_textarea_field( $msg['content'] ),
				];
				continue;
			}

			// Structured content (tool_use blocks, tool_result blocks, tool messages)
			// comes from our own server responses — pass through as-is.
			$out[] = $msg;
		}

		return $out;
	}

	// =========================================================================
	// Moonshot SSE streaming
	// =========================================================================

	/**
	 * REST callback for POST /wp-json/mayaai/v1/stream
	 * Bypasses WordPress response buffering and pipes SSE directly to the browser.
	 */
	public function handle_stream( WP_REST_Request $request ): void {
		$messages = $request->get_param( 'messages' );

		if ( empty( $messages ) || ! is_array( $messages ) ) {
			$this->sse_send( 'error', [ 'message' => __( 'A messages array is required.', 'maya-ai-shopping-assistant-for-woocommerce' ) ] );
			exit;
		}

		$sanitized = $this->sanitize_messages( $messages );

		if ( empty( $sanitized ) ) {
			$this->sse_send( 'error', [ 'message' => __( 'No valid messages provided.', 'maya-ai-shopping-assistant-for-woocommerce' ) ] );
			exit;
		}

		// Tell WordPress we're handling the response ourselves.
		add_filter( 'rest_pre_serve_request', '__return_true' );

		// Kill all output buffering layers.
		while ( ob_get_level() ) {
			ob_end_clean();
		}

		// SSE headers — X-Accel-Buffering disables nginx buffering.
		header( 'Content-Type: text/event-stream' );
		header( 'Cache-Control: no-cache' );
		header( 'X-Accel-Buffering: no' );
		header( 'Connection: keep-alive' );

		// Release session lock so other requests aren't blocked.
		if ( session_status() === PHP_SESSION_ACTIVE ) {
			session_write_close();
		}

		if ( function_exists( 'wc_load_cart' ) ) {
			wc_load_cart();
		}

		$this->run_stream_agent( $sanitized );

		exit;
	}

	/**
	 * Multi-turn streaming agent loop.
	 * Each turn streams text chunks live; tool calls are collected, executed, then the next turn streams.
	 */
	private function run_stream_agent( array $messages ): void {
		$tools = Mayaai_Tools::instance();
		$max   = 8;

		// Moonshot needs system as first message.
		$api_msgs = array_merge(
			[ [ 'role' => 'system', 'content' => $this->get_system_prompt() ] ],
			$messages
		);

		for ( $i = 0; $i < $max; $i++ ) {
			[ $text, $tool_calls, $error ] = $this->stream_one_turn( $api_msgs );

			if ( $error ) {
				$this->sse_send( 'error', [ 'message' => $error ] );
				return;
			}

			// Build the assistant history entry for this turn.
			$assistant_msg = [ 'role' => 'assistant', 'content' => $text ?: null ];
			if ( ! empty( $tool_calls ) ) {
				$assistant_msg['tool_calls'] = array_map( fn( $tc ) => [
					'id'       => $tc['id'],
					'type'     => 'function',
					'function' => [ 'name' => $tc['name'], 'arguments' => wp_json_encode( $tc['input'] ) ],
				], $tool_calls );
			}
			$api_msgs[] = $assistant_msg;

			if ( empty( $tool_calls ) ) {
				// Final turn — signal completion.
				$this->sse_send( 'done', [] );
				return;
			}

			// Execute each tool and append results.
			foreach ( $tool_calls as $tc ) {
				$this->sse_send( 'tool', [ 'name' => $tc['name'] ] );
				$result     = $tools->execute( $tc['name'], $tc['input'] );
				$api_msgs[] = [
					'role'         => 'tool',
					'tool_call_id' => $tc['id'],
					'content'      => wp_json_encode( $result ),
				];
			}
		}

		$this->sse_send( 'error', [ 'message' => __( 'Agent exceeded maximum iterations.', 'maya-ai-shopping-assistant-for-woocommerce' ) ] );
	}

	/**
	 * Opens a single streaming curl request to Moonshot.
	 * Forwards text delta chunks to the browser immediately via SSE.
	 * Accumulates tool_calls for the caller to execute.
	 *
	 * @return array{0: string, 1: array, 2: string|null} [text, tool_calls, error]
	 */
	private function stream_one_turn( array $messages ): array {
		$api_key = get_option( 'mayaai_moonshot_api_key', '' );
		$model   = get_option( 'mayaai_moonshot_model', 'moonshot-v1-32k' );

		$payload = [
			'model'      => $model,
			'messages'   => $messages,
			'stream'     => true,
			'max_tokens' => 1024,
			'tools'      => $this->get_openai_tools(),
		];

		$collected_text  = '';
		$raw_body        = '';   // captures full body to parse plain-JSON errors
		$tool_buf        = [];
		$error           = null;

		$write_callback = function ( $ch, $raw ) use ( &$collected_text, &$tool_buf, &$error, &$raw_body ) {
			$raw_body .= $raw;

			// A single write may contain multiple SSE lines.
			foreach ( explode( "\n", $raw ) as $line ) {
				$line = trim( $line );
				if ( ! str_starts_with( $line, 'data: ' ) ) {
					continue;
				}

				$json = substr( $line, 6 );
				if ( $json === '[DONE]' ) {
					continue;
				}

				$chunk = json_decode( $json, true );
				if ( ! is_array( $chunk ) ) {
					continue;
				}

				// Error embedded inside the SSE stream.
				if ( isset( $chunk['error'] ) ) {
					$error = $chunk['error']['message'] ?? __( 'Moonshot API error.', 'maya-ai-shopping-assistant-for-woocommerce' );
					return strlen( $raw );
				}

				$delta = $chunk['choices'][0]['delta'] ?? [];

				// ── Text chunk ──
				if ( ! empty( $delta['content'] ) ) {
					$collected_text .= $delta['content'];
					$this->sse_send( 'chunk', [ 'content' => $delta['content'] ] );
				}

				// ── Tool call fragments (may arrive across multiple chunks) ──
				foreach ( $delta['tool_calls'] ?? [] as $tc ) {
					$idx = $tc['index'] ?? 0;
					if ( ! isset( $tool_buf[ $idx ] ) ) {
						$tool_buf[ $idx ] = [ 'id' => '', 'name' => '', 'arguments' => '' ];
					}
					if ( ! empty( $tc['id'] ) )                    $tool_buf[ $idx ]['id']        = $tc['id'];
					if ( ! empty( $tc['function']['name'] ) )      $tool_buf[ $idx ]['name']      = $tc['function']['name'];
					if ( ! empty( $tc['function']['arguments'] ) ) $tool_buf[ $idx ]['arguments'] .= $tc['function']['arguments'];
				}
			}

			return strlen( $raw );
		};

		/*
		 * wp_remote_post() buffers the full response by default, which would defeat
		 * streaming. The documented http_api_curl hook lets us inject a write
		 * callback on the underlying cURL handle so SSE chunks reach the browser
		 * as they arrive, while the request still goes through the WordPress HTTP
		 * API for SSL/proxy/header handling.
		 *
		 * See https://developer.wordpress.org/reference/hooks/http_api_curl/.
		 */
		$stream_marker = '_mayaai_moonshot_stream';

		$curl_hook = function ( $handle, $r ) use ( $stream_marker, $write_callback ) {
			if ( empty( $r[ $stream_marker ] ) ) {
				return;
			}
			// phpcs:disable WordPress.WP.AlternativeFunctions.curl_curl_setopt
			curl_setopt( $handle, CURLOPT_WRITEFUNCTION, $write_callback );
			curl_setopt( $handle, CURLOPT_RETURNTRANSFER, false );
			// phpcs:enable
		};

		add_action( 'http_api_curl', $curl_hook, 10, 2 );

		$response = wp_remote_post(
			'https://api.moonshot.ai/v1/chat/completions',
			[
				'timeout'       => 60,
				'headers'       => [
					'Authorization' => 'Bearer ' . $api_key,
					'Content-Type'  => 'application/json',
				],
				'body'          => wp_json_encode( $payload ),
				$stream_marker  => true,
			]
		);

		remove_action( 'http_api_curl', $curl_hook, 10 );

		if ( is_wp_error( $response ) ) {
			$error     = $error ?? $response->get_error_message();
			$http_code = 0;
		} else {
			$http_code = wp_remote_retrieve_response_code( $response );
		}

		// Non-200 with no SSE error parsed → plain JSON error body (e.g. 401 auth failures).
		if ( ! $error && 200 !== $http_code ) {
			$body  = json_decode( $raw_body, true );
			$error = $body['error']['message'] ?? sprintf(
				/* translators: %d: HTTP status code from the Moonshot API */
				__( 'Moonshot API error (HTTP %d).', 'maya-ai-shopping-assistant-for-woocommerce' ),
				$http_code
			);
		}

		// Parse accumulated tool call argument JSON strings.
		$tool_calls = [];
		foreach ( $tool_buf as $tc ) {
			$tool_calls[] = [
				'id'    => $tc['id'],
				'name'  => $tc['name'],
				'input' => json_decode( $tc['arguments'], true ) ?? [],
			];
		}

		return [ $collected_text, $tool_calls, $error ];
	}

	/**
	 * Emit a single SSE event and flush immediately.
	 */
	private function sse_send( string $type, array $data ): void {
		echo 'data: ' . wp_json_encode( array_merge( [ 'type' => $type ], $data ) ) . "\n\n";
		if ( ob_get_level() ) {
			ob_flush();
		}
		flush();
	}
}
