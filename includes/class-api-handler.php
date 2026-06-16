<?php
defined( 'ABSPATH' ) || exit;

/**
 * Handles the REST endpoint and drives the agentic loop for both
 * Anthropic (Claude) and Moonshot AI (OpenAI-compatible) providers.
 */
final class Fahad_AI_API_Handler {

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
			return new WP_Error( 'invalid_messages', __( 'A messages array is required.', 'fahad-ai-shopping-assistant-for-woocommerce' ), [ 'status' => 400 ] );
		}

		$sanitized = $this->sanitize_messages( $messages );

		if ( empty( $sanitized ) ) {
			return new WP_Error( 'empty_messages', __( 'No valid messages provided.', 'fahad-ai-shopping-assistant-for-woocommerce' ), [ 'status' => 400 ] );
		}

		if ( function_exists( 'wc_load_cart' ) ) {
			wc_load_cart();
		}

		$provider = get_option( 'fahad_ai_provider', 'anthropic' );

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
		$tools = Fahad_AI_Tools::instance();
		$max   = 8;
		$cards = [];

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
				return [ 'message' => trim( $text ), 'messages' => $messages, 'products' => $cards ];
			}

			if ( 'tool_use' === $stop_reason ) {
				$tool_results = [];

				foreach ( $content as $block ) {
					if ( ( $block['type'] ?? '' ) !== 'tool_use' ) {
						continue;
					}
					$result         = $tools->execute( $block['name'], $block['input'] ?? [] );
					$cards          = array_merge( $cards, $this->tool_result_cards( $block['name'], $result ) );
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

		return new WP_Error( 'agent_loop', __( 'Agent exceeded maximum iterations.', 'fahad-ai-shopping-assistant-for-woocommerce' ), [ 'status' => 500 ] );
	}

	private function call_anthropic( array $messages ): array|WP_Error {
		$api_key = get_option( 'fahad_ai_anthropic_api_key', '' );

		if ( empty( $api_key ) ) {
			return new WP_Error( 'no_api_key', __( 'Anthropic API key is not configured.', 'fahad-ai-shopping-assistant-for-woocommerce' ), [ 'status' => 500 ] );
		}

		$model = get_option( 'fahad_ai_anthropic_model', 'claude-haiku-4-5-20251001' );

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
				__( 'Anthropic API error (HTTP %d).', 'fahad-ai-shopping-assistant-for-woocommerce' ),
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
		$tools = Fahad_AI_Tools::instance();
		$max   = 8;
		$cards = [];

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
					'products' => $cards,
				];
			}

			if ( 'tool_calls' === $finish_reason ) {
				foreach ( $msg['tool_calls'] ?? [] as $call ) {
					$name  = $call['function']['name']      ?? '';
					$input = json_decode( $call['function']['arguments'] ?? '{}', true ) ?? [];

					$result = $tools->execute( $name, $input );
					$cards  = array_merge( $cards, $this->tool_result_cards( $name, $result ) );

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

		return new WP_Error( 'agent_loop', __( 'Agent exceeded maximum iterations.', 'fahad-ai-shopping-assistant-for-woocommerce' ), [ 'status' => 500 ] );
	}

	private function call_moonshot( array $messages ): array|WP_Error {
		$api_key = get_option( 'fahad_ai_moonshot_api_key', '' );

		if ( empty( $api_key ) ) {
			return new WP_Error( 'no_api_key', __( 'Moonshot API key is not configured.', 'fahad-ai-shopping-assistant-for-woocommerce' ), [ 'status' => 500 ] );
		}

		$model = get_option( 'fahad_ai_moonshot_model', 'kimi-k2.6' );

		$payload = [
			'model'      => $model,
			'max_tokens' => 1024,
			'messages'   => $messages,
			'tools'      => $this->get_openai_tools(),
		];

		$response = wp_remote_post( $this->moonshot_base_url() . '/v1/chat/completions', [
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
				__( 'Moonshot API error (HTTP %d).', 'fahad-ai-shopping-assistant-for-woocommerce' ),
				$code
			);
			return new WP_Error( 'api_error', $msg, [ 'status' => 502 ] );
		}

		return $body;
	}

	/**
	 * Base URL for the Moonshot API, selected by the configured region.
	 * Moonshot runs two independent platforms with separate keys and model
	 * catalogues: the global endpoint (api.moonshot.ai) and the China
	 * endpoint (api.moonshot.cn). A key issued on one is rejected by the other.
	 */
	private function moonshot_base_url(): string {
		$region = get_option( 'fahad_ai_moonshot_region', 'global' );
		return 'china' === $region
			? 'https://api.moonshot.cn'
			: 'https://api.moonshot.ai';
	}

	// =========================================================================
	// Tool definitions — Anthropic format & OpenAI format
	// =========================================================================

	/**
	 * Canonical tool spec (used to derive both provider formats).
	 *
	 * Sourced from the tool registry so built-in AND third-party
	 * (filter-registered) tools are advertised to the model uniformly.
	 */
	public function tool_specs(): array {
		return Fahad_AI_Tool_Registry::instance()->specs();
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
		$custom = get_option( 'fahad_ai_system_prompt', '' );
		if ( ! empty( $custom ) ) {
			return $custom;
		}

		$store_name = get_bloginfo( 'name' );
		$currency   = get_woocommerce_currency_symbol();

		return "You are a helpful shopping assistant for {$store_name}. Help customers find products, answer questions, and manage their cart.

Currency: {$currency}

Product display — important:
- After you call search_products or get_product_details, the storefront automatically shows the matching products to the customer as visual cards (photo, name, price, stock, and View / Add to cart buttons). Do NOT list each product's price, description, link, or image in your text — the cards already show all of that.
- Instead, write a short friendly intro or recommendation (one or two sentences). You may highlight or compare a couple of options in words, but never repeat the full product list as text.

Linking rules — follow exactly:
- After a successful add_to_cart, always end your reply with these two links on the same line: [View Cart](cart_url) · [Checkout](checkout_url) — replace cart_url and checkout_url with the actual values from the tool result.
- When the customer asks to check out or go to checkout, include: [Proceed to Checkout](checkout_url) — using the checkout_url from view_cart or add_to_cart results.
- Use markdown only for the cart/checkout links above — no other markdown formatting.

Guidelines:
- Always use search_products or get_product_details before recommending a product — never invent product details.
- When asked about ratings or reviews, use get_product_reviews and summarise only the returned reviews — never invent reviews, quotes, ratings, or sentiment.
- When a customer wants to buy something, confirm the product, then use add_to_cart.
- Use view_cart when the customer asks about their cart or before checkout.
- Keep responses concise and friendly.
- For order status, account issues, or returns, direct the customer to the store's support team.";
	}

	// =========================================================================
	// Product cards (surfaced to the widget for rich rendering)
	// =========================================================================

	/**
	 * Build the product-card payload the widget renders, from a tool result.
	 *
	 * CONVENTION over configuration: card emission keys off the SHAPE of the tool
	 * result, not the tool's name, so every product tool — the built-in
	 * search_products / get_product_details AND any filter-registered tool that
	 * returns the same shape (get_top_products, recommendations, comparisons, …) —
	 * surfaces cards automatically without this method being taught its name.
	 *
	 *   1. A result with a non-empty `products` array  → one card per entry.
	 *   2. Otherwise a single product-shaped result (has both `id` and `name`)
	 *      → one card.
	 *   3. Otherwise no cards (cart actions, category lists, errors, empty
	 *      searches, …).
	 *
	 * The $tool name is retained for the call signature (callers pass the tool
	 * that produced $result) but is intentionally NOT used to decide cards.
	 *
	 * Card data comes straight from WooCommerce (via the tools), never from
	 * model-generated text, so the widget can trust these fields.
	 *
	 * @param string $tool   Name of the tool that produced the result (unused;
	 *                        emission is by result shape, see above).
	 * @param array  $result The tool result array.
	 */
	private function tool_result_cards( string $tool, array $result ): array {
		if ( ! empty( $result['products'] ) && is_array( $result['products'] ) ) {
			return array_values( array_filter( array_map( [ $this, 'normalize_card' ], $result['products'] ) ) );
		}

		if ( ! empty( $result['id'] ) && ! empty( $result['name'] ) ) {
			$card = $this->normalize_card( $result );
			return $card ? [ $card ] : [];
		}

		return [];
	}

	/**
	 * Reduce a product summary/detail array to the card fields the widget uses.
	 */
	private function normalize_card( array $p ): array {
		if ( empty( $p['id'] ) || empty( $p['name'] ) ) {
			return [];
		}

		return [
			'id'                => (int) $p['id'],
			'name'              => (string) $p['name'],
			'price'             => (string) ( $p['price'] ?? '' ),
			'regular_price'     => (string) ( $p['regular_price'] ?? '' ),
			'sale_price'        => isset( $p['sale_price'] ) ? (string) $p['sale_price'] : null,
			'on_sale'           => ! empty( $p['on_sale'] ),
			'in_stock'          => ! isset( $p['in_stock'] ) || (bool) $p['in_stock'],
			'short_description' => (string) ( $p['short_description'] ?? $p['description'] ?? '' ),
			'image'             => (string) ( $p['image'] ?? '' ),
			'url'               => (string) ( $p['url'] ?? '' ),
			// Trust signals (issue #11). Cast to numbers so the widget can compare
			// review_count > 0 reliably and render ★avg (count). Default to 0 for
			// tools/add-ons that return a product shape without rating data.
			'rating'            => round( (float) ( $p['rating'] ?? 0 ), 2 ),
			'review_count'      => (int) ( $p['review_count'] ?? 0 ),
		];
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
	 * REST callback for POST /wp-json/fahad-ai/v1/stream
	 * Bypasses WordPress response buffering and pipes SSE directly to the browser.
	 */
	public function handle_stream( WP_REST_Request $request ): void {
		$messages = $request->get_param( 'messages' );

		if ( empty( $messages ) || ! is_array( $messages ) ) {
			$this->sse_send( 'error', [ 'message' => __( 'A messages array is required.', 'fahad-ai-shopping-assistant-for-woocommerce' ) ] );
			exit;
		}

		$sanitized = $this->sanitize_messages( $messages );

		if ( empty( $sanitized ) ) {
			$this->sse_send( 'error', [ 'message' => __( 'No valid messages provided.', 'fahad-ai-shopping-assistant-for-woocommerce' ) ] );
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
		$tools = Fahad_AI_Tools::instance();
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
				$result = $tools->execute( $tc['name'], $tc['input'] );

				$cards = $this->tool_result_cards( $tc['name'], $result );
				if ( ! empty( $cards ) ) {
					$this->sse_send( 'products', [ 'products' => $cards ] );
				}

				$api_msgs[] = [
					'role'         => 'tool',
					'tool_call_id' => $tc['id'],
					'content'      => wp_json_encode( $result ),
				];
			}
		}

		$this->sse_send( 'error', [ 'message' => __( 'Agent exceeded maximum iterations.', 'fahad-ai-shopping-assistant-for-woocommerce' ) ] );
	}

	/**
	 * Opens a single streaming curl request to Moonshot.
	 * Forwards text delta chunks to the browser immediately via SSE.
	 * Accumulates tool_calls for the caller to execute.
	 *
	 * @return array{0: string, 1: array, 2: string|null} [text, tool_calls, error]
	 */
	private function stream_one_turn( array $messages ): array {
		$api_key = get_option( 'fahad_ai_moonshot_api_key', '' );
		$model   = get_option( 'fahad_ai_moonshot_model', 'kimi-k2.6' );

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
					$error = $chunk['error']['message'] ?? __( 'Moonshot API error.', 'fahad-ai-shopping-assistant-for-woocommerce' );
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
		 * SSE streaming needs the response body delivered to us incrementally.
		 * wp_remote_post() buffers the whole body, and overriding the cURL write
		 * callback through the http_api_curl hook is not honoured reliably across
		 * PHP/cURL builds (the WordPress transport sets its own write handler, so
		 * the upstream bytes can leak straight to output and corrupt the SSE
		 * framing). A dedicated cURL handle gives us a deterministic write
		 * callback, which is required for real streaming.
		 */
		if ( ! function_exists( 'curl_init' ) ) {
			return [ '', [], __( 'Live streaming requires the PHP cURL extension.', 'fahad-ai-shopping-assistant-for-woocommerce' ) ];
		}

		// phpcs:disable WordPress.WP.AlternativeFunctions.curl_curl_init, WordPress.WP.AlternativeFunctions.curl_curl_setopt, WordPress.WP.AlternativeFunctions.curl_curl_exec, WordPress.WP.AlternativeFunctions.curl_curl_errno, WordPress.WP.AlternativeFunctions.curl_curl_error, WordPress.WP.AlternativeFunctions.curl_curl_getinfo, WordPress.WP.AlternativeFunctions.curl_curl_close
		$ch = curl_init();
		curl_setopt( $ch, CURLOPT_URL, $this->moonshot_base_url() . '/v1/chat/completions' );
		curl_setopt( $ch, CURLOPT_POST, true );
		curl_setopt( $ch, CURLOPT_POSTFIELDS, wp_json_encode( $payload ) );
		curl_setopt( $ch, CURLOPT_HTTPHEADER, [
			'Authorization: Bearer ' . $api_key,
			'Content-Type: application/json',
			'Accept: text/event-stream',
		] );
		curl_setopt( $ch, CURLOPT_TIMEOUT, 60 );
		curl_setopt( $ch, CURLOPT_RETURNTRANSFER, false );
		curl_setopt( $ch, CURLOPT_WRITEFUNCTION, $write_callback );
		curl_exec( $ch );
		$curl_errno = curl_errno( $ch );
		$curl_error = curl_error( $ch );
		$http_code  = (int) curl_getinfo( $ch, CURLINFO_HTTP_CODE );
		curl_close( $ch );
		// phpcs:enable

		// Transport-level failure (DNS, TLS, timeout) with no SSE error parsed.
		if ( ! $error && $curl_errno ) {
			$error = $curl_error !== '' ? $curl_error : __( 'Live streaming connection failed.', 'fahad-ai-shopping-assistant-for-woocommerce' );
		}

		// Non-200 with no SSE error parsed → plain JSON error body (e.g. 401 auth failures).
		if ( ! $error && 200 !== $http_code ) {
			$body  = json_decode( $raw_body, true );
			$error = $body['error']['message'] ?? sprintf(
				/* translators: %d: HTTP status code from the Moonshot API */
				__( 'Moonshot API error (HTTP %d).', 'fahad-ai-shopping-assistant-for-woocommerce' ),
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
