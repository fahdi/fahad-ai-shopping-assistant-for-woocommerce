<?php
/**
 * Eval harness for the Fahad AI shopping assistant.
 *
 * This is the AI analogue of the unit tests: it drives the REAL agent loop
 * (Fahad_AI_API_Handler::run_anthropic_agent / run_moonshot_agent) against a
 * SCRIPTED LLM transport and REAL tool execution, then makes deterministic
 * assertions about which tools ran, what cards were produced, and whether the
 * model's final answer is grounded in the tool results.
 *
 * It NEVER makes a live API call. The model's HTTP responses are canned per
 * fixture and replayed in order via a stubbed wp_remote_post.
 *
 * Why reflection: run_anthropic_agent() and run_moonshot_agent() are private
 * (as is tool_result_cards()). The unit tests already reach private members the
 * same way (ReflectionMethod / ReflectionProperty), so we follow that pattern
 * rather than widening visibility in production code.
 */

use Brain\Monkey\Functions;

final class EvalHarness {

	/**
	 * Reset the API handler + tools singletons so each case starts clean.
	 * Mirrors the ApiHandlerTest / ToolsTest reflection reset of $instance.
	 */
	public static function reset_singletons(): void {
		( new ReflectionProperty( Fahad_AI_API_Handler::class, 'instance' ) )->setValue( null, null );
		( new ReflectionProperty( Fahad_AI_Tools::class, 'instance' ) )->setValue( null, null );
		// The registry caches its (filtered) tool list on the instance; resetting
		// it ensures a registry built in one eval case (e.g. one that registers a
		// custom tool via the filter) cannot leak into the next.
		( new ReflectionProperty( Fahad_AI_Tool_Registry::class, 'instance' ) )->setValue( null, null );
	}

	private static function handler(): Fahad_AI_API_Handler {
		return Fahad_AI_API_Handler::instance();
	}

	// =========================================================================
	// Common WordPress / WooCommerce stubs
	// =========================================================================

	/**
	 * Stub the WP/WC functions the agent loop and the tools depend on.
	 *
	 * - get_option supplies a non-empty API key (so call_* does not short-circuit
	 *   with "API key is not configured") plus model/region/system-prompt values.
	 * - The remaining stubs mirror ToolsTest::setUp so real tool execution works.
	 *
	 * @param array $options Extra/override get_option values, keyed by option name.
	 */
	public static function stub_environment( array $options = [] ): void {
		$defaults = [
			'fahad_ai_provider'           => 'anthropic',
			'fahad_ai_anthropic_api_key'  => 'test-anthropic-key',
			'fahad_ai_anthropic_model'    => 'claude-haiku-4-5-20251001',
			'fahad_ai_moonshot_api_key'   => 'test-moonshot-key',
			'fahad_ai_moonshot_model'     => 'kimi-k2.6',
			'fahad_ai_moonshot_region'    => 'global',
			'fahad_ai_system_prompt'      => '',
		];
		$opts = array_merge( $defaults, $options );

		Functions\when( 'get_option' )->alias(
			static fn( $key, $default = '' ) => $opts[ $key ] ?? $default
		);

		Functions\stubs( [
			'get_bloginfo'                    => fn() => 'Test Store',
			'get_woocommerce_currency_symbol' => fn() => '$',
			'get_site_url'                    => fn() => 'http://example.com',

			// Tool-layer stubs (mirror ToolsTest::setUp).
			'absint'                      => fn( $n ) => abs( (int) $n ),
			'sanitize_text_field'         => fn( $s ) => $s,
			'sanitize_textarea_field'     => fn( $s ) => $s,
			'wp_json_encode'              => fn( $d ) => json_encode( $d ),
			'wc_price'                    => fn( $p ) => '$' . $p,
			'wp_strip_all_tags'           => fn( $s ) => strip_tags( (string) $s ),
			'wp_get_attachment_image_url' => fn() => '',
			'wc_placeholder_img_src'      => fn() => 'http://example.com/placeholder.png',
			'get_permalink'               => fn( $id ) => 'http://example.com/?p=' . $id,
			'wc_get_cart_url'             => fn() => 'http://example.com/cart',
			'wc_get_checkout_url'         => fn() => 'http://example.com/checkout',
			'wp_list_pluck'               => fn( $list, $field ) => array_column( (array) $list, $field ),
			'get_the_terms'               => fn() => [],
			'rest_ensure_response'        => fn( $d ) => $d,
		] );
	}

	// =========================================================================
	// WooCommerce data mocks
	// =========================================================================

	/**
	 * Build a Mockery WC_Product from a plain spec array. Mirrors
	 * ToolsTest::mockProduct but data-driven so fixtures stay declarative.
	 *
	 * @param array $spec { id, name, price, regular_price?, sale_price?, on_sale?,
	 *                      in_stock?, visible?, short_description?, type? }
	 */
	public static function mock_product( array $spec ): WC_Product {
		$id    = (int) ( $spec['id'] ?? 0 );
		$name  = (string) ( $spec['name'] ?? '' );
		$price = (string) ( $spec['price'] ?? '0' );

		$p = Mockery::mock( WC_Product::class );
		$p->shouldReceive( 'get_id' )->andReturn( $id );
		$p->shouldReceive( 'get_name' )->andReturn( $name );
		$p->shouldReceive( 'get_price' )->andReturn( $price );
		$p->shouldReceive( 'get_regular_price' )->andReturn( (string) ( $spec['regular_price'] ?? $price ) );
		$p->shouldReceive( 'get_sale_price' )->andReturn( (string) ( $spec['sale_price'] ?? '' ) );
		$p->shouldReceive( 'is_on_sale' )->andReturn( (bool) ( $spec['on_sale'] ?? false ) );
		$p->shouldReceive( 'is_visible' )->andReturn( (bool) ( $spec['visible'] ?? true ) );
		$p->shouldReceive( 'is_in_stock' )->andReturn( (bool) ( $spec['in_stock'] ?? true ) );
		$p->shouldReceive( 'get_type' )->andReturn( (string) ( $spec['type'] ?? 'simple' ) );
		$p->shouldReceive( 'is_type' )->with( 'variable' )->andReturn( ( $spec['type'] ?? 'simple' ) === 'variable' );
		$p->shouldReceive( 'get_description' )->andReturn( (string) ( $spec['description'] ?? '' ) );
		$p->shouldReceive( 'get_short_description' )->andReturn( (string) ( $spec['short_description'] ?? '' ) );
		$p->shouldReceive( 'get_sku' )->andReturn( (string) ( $spec['sku'] ?? '' ) );
		$p->shouldReceive( 'get_stock_quantity' )->andReturn( (int) ( $spec['stock_qty'] ?? 10 ) );
		$p->shouldReceive( 'get_image_id' )->andReturn( 0 );
		$p->shouldReceive( 'get_available_variations' )->andReturn( [] );
		return $p;
	}

	/**
	 * Apply a fixture's declarative WooCommerce data to the function stubs the
	 * tools call. Supported keys (all optional):
	 *   - products:        array of product specs returned by wc_get_products().
	 *   - product_by_id:   map of id => spec returned by wc_get_product().
	 *   - cart:            [ add_returns => string|false, total => string, items => [...] ]
	 *                      controls add_to_cart / view_cart behaviour.
	 *
	 * @param array $data Fixture's 'wc' block.
	 */
	public static function stub_woocommerce( array $data ): void {
		// wc_get_products → list of product mocks.
		if ( array_key_exists( 'products', $data ) ) {
			$mocks = array_map( [ self::class, 'mock_product' ], $data['products'] );
			Functions\when( 'wc_get_products' )->justReturn( $mocks );
		} else {
			Functions\when( 'wc_get_products' )->justReturn( [] );
		}

		// wc_get_product → mock by id, or false when absent.
		$by_id = [];
		foreach ( $data['product_by_id'] ?? [] as $id => $spec ) {
			$by_id[ (int) $id ] = self::mock_product( $spec + [ 'id' => (int) $id ] );
		}
		Functions\when( 'wc_get_product' )->alias(
			static fn( $id ) => $by_id[ (int) $id ] ?? false
		);

		// WC()->cart behaviour.
		$cart_cfg = $data['cart'] ?? [];
		$cart     = Mockery::mock( WC_Cart::class );
		$cart->shouldReceive( 'add_to_cart' )->andReturn( $cart_cfg['add_returns'] ?? 'cart_key_eval' );
		$cart->shouldReceive( 'get_cart_total' )->andReturn( $cart_cfg['total'] ?? '$0.00' );
		$cart->shouldReceive( 'get_cart_subtotal' )->andReturn( $cart_cfg['subtotal'] ?? ( $cart_cfg['total'] ?? '$0.00' ) );
		$cart->shouldReceive( 'is_empty' )->andReturn( empty( $cart_cfg['items'] ) );
		$cart->shouldReceive( 'get_cart' )->andReturn( $cart_cfg['items'] ?? [] );
		$cart->shouldReceive( 'get_cart_contents_count' )->andReturn( $cart_cfg['count'] ?? count( $cart_cfg['items'] ?? [] ) );
		$cart->shouldReceive( 'remove_cart_item' )->andReturn( $cart_cfg['remove_returns'] ?? true );
		Functions\when( 'WC' )->justReturn( (object) [ 'cart' => $cart ] );
	}

	// =========================================================================
	// Scripted LLM transport
	// =========================================================================

	/**
	 * Queue a list of canned API response BODIES (associative arrays in the
	 * provider's wire format) so successive wp_remote_post calls — i.e. each turn
	 * of the agent loop — return the next scripted response.
	 *
	 * Each entry is the raw response body (use the anthropic_ / moonshot_ builders,
	 * which return exactly that). For non-200 transports (error-handling cases),
	 * wrap a turn with EvalHarness::http_error( $code, $body ).
	 *
	 * wp_remote_post returns an opaque transport value here: a wrapper array
	 * { __eval: true, code, body }. We stub wp_remote_retrieve_response_code /
	 * wp_remote_retrieve_body to read from that wrapper, exactly as the loop does
	 * with a real WP HTTP response.
	 *
	 * @param array $responses Ordered list of response bodies (or http_error() wrappers).
	 */
	public static function script_transport( array $responses ): void {
		$queue = array_map(
			static function ( $turn ) {
				// http_error() wrapper carries an explicit code; everything else is a 200 body.
				if ( is_array( $turn ) && isset( $turn['__eval_http'] ) ) {
					$code = $turn['code'];
					$body = $turn['body'];
				} else {
					$code = 200;
					$body = $turn;
				}
				return [
					'__eval' => true,
					'code'   => $code,
					'body'   => is_string( $body ) ? $body : json_encode( $body ),
				];
			},
			$responses
		);

		$cursor = 0;
		Functions\when( 'wp_remote_post' )->alias(
			static function () use ( &$cursor, $queue ) {
				if ( $cursor >= count( $queue ) ) {
					// The agent asked for more turns than the fixture scripted.
					// Returning a WP_Error surfaces the over-run as a clear failure
					// instead of an opaque "Undefined array key" notice.
					return new WP_Error( 'eval_transport_exhausted', 'Scripted transport ran out of responses.' );
				}
				return $queue[ $cursor++ ];
			}
		);

		Functions\when( 'wp_remote_retrieve_response_code' )->alias(
			static fn( $response ) => is_array( $response ) ? ( $response['code'] ?? 0 ) : 0
		);

		Functions\when( 'wp_remote_retrieve_body' )->alias(
			static fn( $response ) => is_array( $response ) ? ( $response['body'] ?? '' ) : ''
		);
	}

	// =========================================================================
	// Canned-response builders (keep fixtures readable & provider-accurate)
	// =========================================================================

	/** Anthropic: a turn that asks to call one or more tools (stop_reason tool_use). */
	public static function anthropic_tool_turn( array $calls ): array {
		$content = [];
		foreach ( $calls as $i => $call ) {
			$content[] = [
				'type'  => 'tool_use',
				'id'    => $call['id'] ?? ( 'toolu_' . $i ),
				'name'  => $call['name'],
				'input' => $call['input'] ?? [],
			];
		}
		return [ 'stop_reason' => 'tool_use', 'content' => $content ];
	}

	/** Anthropic: the final text turn (stop_reason end_turn). */
	public static function anthropic_text_turn( string $text ): array {
		return [
			'stop_reason' => 'end_turn',
			'content'     => [ [ 'type' => 'text', 'text' => $text ] ],
		];
	}

	/** Moonshot/OpenAI: a turn that asks to call one or more tools (finish_reason tool_calls). */
	public static function moonshot_tool_turn( array $calls ): array {
		$tool_calls = [];
		foreach ( $calls as $i => $call ) {
			$tool_calls[] = [
				'id'       => $call['id'] ?? ( 'call_' . $i ),
				'type'     => 'function',
				'function' => [
					'name'      => $call['name'],
					'arguments' => json_encode( $call['input'] ?? [] ),
				],
			];
		}
		return [
			'choices' => [
				[
					'finish_reason' => 'tool_calls',
					'message'       => [ 'role' => 'assistant', 'content' => null, 'tool_calls' => $tool_calls ],
				],
			],
		];
	}

	/** Moonshot/OpenAI: the final text turn (finish_reason stop). */
	public static function moonshot_text_turn( string $text ): array {
		return [
			'choices' => [
				[
					'finish_reason' => 'stop',
					'message'       => [ 'role' => 'assistant', 'content' => $text ],
				],
			],
		];
	}

	/**
	 * Wrap a turn as a non-200 HTTP transport response, for fixtures that test the
	 * loop's error handling (e.g. a 502 from the provider). Body is the provider's
	 * error JSON, e.g. [ 'error' => [ 'message' => '...' ] ].
	 */
	public static function http_error( int $code, array $body ): array {
		return [ '__eval_http' => true, 'code' => $code, 'body' => $body ];
	}

	// =========================================================================
	// Running the real agent loop
	// =========================================================================

	/**
	 * Drive the real agent loop for a provider, then reconstruct the tool trace
	 * (names, inputs, results) from the loop's own returned transcript.
	 *
	 * We deliberately do NOT wrap or subclass Fahad_AI_Tools (it is `final`, and
	 * the goal is to touch no production code). The real Fahad_AI_Tools runs
	 * inside the loop against the WooCommerce stubs, and the loop records each
	 * tool_use/tool_calls block plus its tool_result content into the returned
	 * `messages` array — so we parse that transcript to recover the trace.
	 *
	 * @param string $provider 'anthropic' | 'moonshot'.
	 * @param array  $messages Sanitized message array (user turns).
	 * @return array {
	 *     @type mixed   $result       The loop's return value (message/messages/products) or WP_Error.
	 *     @type array   $tool_calls   Ordered [ name, input ] of tools the model invoked.
	 *     @type array   $tool_results Ordered tool result arrays (parallel to $tool_calls).
	 *     @type string  $answer       The final answer text ('' on WP_Error).
	 *     @type array   $products     The product cards the loop surfaced.
	 * }
	 */
	public static function run( string $provider, array $messages ): array {
		// Use the real singleton tools instance (freshly reset by the caller).
		self::reset_singletons();

		$method = ( 'moonshot' === $provider ) ? 'run_moonshot_agent' : 'run_anthropic_agent';
		$ref    = new ReflectionMethod( Fahad_AI_API_Handler::class, $method );
		$result = $ref->invoke( self::handler(), $messages );

		$tool_calls   = [];
		$tool_results = [];
		$answer       = '';
		$products     = [];

		if ( is_array( $result ) ) {
			$answer   = (string) ( $result['message'] ?? '' );
			$products = $result['products'] ?? [];
			[ $tool_calls, $tool_results ] = ( 'moonshot' === $provider )
				? self::trace_moonshot( $result['messages'] ?? [] )
				: self::trace_anthropic( $result['messages'] ?? [] );
		}

		return [
			'result'       => $result,
			'tool_calls'   => $tool_calls,
			'tool_results' => $tool_results,
			'answer'       => $answer,
			'products'     => $products,
		];
	}

	/**
	 * Recover (calls, results) from an Anthropic transcript.
	 * Assistant `tool_use` blocks give name/input/id; the user message that
	 * follows carries `tool_result` blocks keyed by tool_use_id with JSON content.
	 */
	private static function trace_anthropic( array $messages ): array {
		$by_id = [];   // tool_use_id => result array
		foreach ( $messages as $msg ) {
			$content = $msg['content'] ?? [];
			if ( ! is_array( $content ) ) {
				continue;
			}
			foreach ( $content as $block ) {
				if ( ( $block['type'] ?? '' ) === 'tool_result' ) {
					$by_id[ $block['tool_use_id'] ?? '' ] = json_decode( $block['content'] ?? '{}', true ) ?? [];
				}
			}
		}

		$calls   = [];
		$results = [];
		foreach ( $messages as $msg ) {
			$content = $msg['content'] ?? [];
			if ( ! is_array( $content ) ) {
				continue;
			}
			foreach ( $content as $block ) {
				if ( ( $block['type'] ?? '' ) === 'tool_use' ) {
					$id        = $block['id'] ?? '';
					$calls[]   = [ 'name' => $block['name'] ?? '', 'input' => $block['input'] ?? [] ];
					$results[] = $by_id[ $id ] ?? [];
				}
			}
		}
		return [ $calls, $results ];
	}

	/**
	 * Recover (calls, results) from a Moonshot/OpenAI transcript.
	 * Assistant messages carry `tool_calls` (function.name + JSON arguments); the
	 * `tool` role messages that follow carry the JSON result keyed by tool_call_id.
	 */
	private static function trace_moonshot( array $messages ): array {
		$by_id = [];
		foreach ( $messages as $msg ) {
			if ( ( $msg['role'] ?? '' ) === 'tool' ) {
				$by_id[ $msg['tool_call_id'] ?? '' ] = json_decode( $msg['content'] ?? '{}', true ) ?? [];
			}
		}

		$calls   = [];
		$results = [];
		foreach ( $messages as $msg ) {
			foreach ( $msg['tool_calls'] ?? [] as $tc ) {
				$id        = $tc['id'] ?? '';
				$name      = $tc['function']['name'] ?? '';
				$input     = json_decode( $tc['function']['arguments'] ?? '{}', true ) ?? [];
				$calls[]   = [ 'name' => $name, 'input' => $input ];
				$results[] = $by_id[ $id ] ?? [];
			}
		}
		return [ $calls, $results ];
	}

	// =========================================================================
	// Grounding checker (anti-hallucination)
	// =========================================================================

	/**
	 * Heuristic grounding check: every concrete product FACT that appears in the
	 * model's final answer must also appear in at least one tool RESULT.
	 *
	 * What we extract from the answer and verify:
	 *   1. Price-like tokens — e.g. "$59.99", "59.99", "Rs90". A model that invents
	 *      a price it was never given is the classic hallucination we must catch.
	 *   2. Product names — every name present in any tool result. If the answer
	 *      mentions a known product name, fine; we only FAIL on invented prices,
	 *      because free-form prose legitimately contains many non-product words.
	 *
	 * The tool results are flattened to a single searchable haystack (names +
	 * JSON-encoded values), so a price/name is "grounded" if it literally occurs
	 * in any result the tools returned this conversation.
	 *
	 * Deterministic + offline. Returns a list of violation strings (empty == grounded).
	 *
	 * LIMITS (by design — documented, not bugs):
	 *   - It is a string/number containment check, not semantic. It cannot detect
	 *     a swapped-but-plausible claim (e.g. attributing product A's real price to
	 *     product B) because both numbers exist in the results.
	 *   - It only inspects price tokens and known product names; it will not catch a
	 *     fabricated non-numeric, non-name claim ("ships free worldwide").
	 *   - Currency-symbol/format variance is normalised loosely (digits + decimal),
	 *     so "$59.99" grounds "59.99". A fixture should use the same price format the
	 *     tools emit (the harness stubs wc_price as "$<value>").
	 * These limits are acceptable: the eval's job is to catch the high-value failure
	 * mode (invented prices / invented products), and the negative self-test proves
	 * the checker actually fails such cases.
	 *
	 * @param string $answer       The model's final answer text.
	 * @param array  $tool_results Ordered tool result arrays from the conversation.
	 * @return string[] Violations; empty array means the answer is grounded.
	 */
	public static function grounding_violations( string $answer, array $tool_results ): array {
		$violations = [];

		$haystack       = self::flatten_results( $tool_results );
		$haystack_lower = strtolower( $haystack );
		$ground_numbers = self::extract_numbers( $haystack );

		// 1. Price tokens in the answer must be grounded.
		foreach ( self::extract_prices( $answer ) as $price ) {
			$norm = self::normalize_number( $price );
			if ( '' === $norm ) {
				continue;
			}
			if ( ! in_array( $norm, $ground_numbers, true ) ) {
				$violations[] = "ungrounded price '{$price}' (not present in any tool result)";
			}
		}

		// 2. Product names from the results may appear; an answer naming a product
		//    NOT in any result is a fabricated product reference.
		$known_names = self::extract_product_names( $tool_results );
		foreach ( self::candidate_quoted_names( $answer ) as $name ) {
			$name_lower = strtolower( trim( $name ) );
			if ( '' === $name_lower ) {
				continue;
			}
			$grounded = false;
			foreach ( $known_names as $known ) {
				if ( $name_lower === strtolower( $known ) ) {
					$grounded = true;
					break;
				}
			}
			// Fall back to substring containment against the flattened results,
			// so a name mentioned unquoted-but-real still grounds.
			if ( ! $grounded && str_contains( $haystack_lower, $name_lower ) ) {
				$grounded = true;
			}
			if ( ! $grounded ) {
				$violations[] = "ungrounded product name '{$name}' (not present in any tool result)";
			}
		}

		return $violations;
	}

	/** True when the answer introduces no fabricated product facts. */
	public static function is_grounded( string $answer, array $tool_results ): bool {
		return empty( self::grounding_violations( $answer, $tool_results ) );
	}

	// ── Grounding helpers ─────────────────────────────────────────────────────

	/** Flatten tool results into one searchable string (JSON + product names). */
	private static function flatten_results( array $tool_results ): string {
		$parts = [];
		foreach ( $tool_results as $r ) {
			$parts[] = json_encode( $r );
		}
		return implode( ' ', $parts );
	}

	/** Pull every product "name" field out of search/detail tool results. */
	private static function extract_product_names( array $tool_results ): array {
		$names = [];
		foreach ( $tool_results as $r ) {
			if ( ! is_array( $r ) ) {
				continue;
			}
			if ( isset( $r['name'] ) && is_string( $r['name'] ) ) {
				$names[] = $r['name'];
			}
			if ( ! empty( $r['products'] ) && is_array( $r['products'] ) ) {
				foreach ( $r['products'] as $p ) {
					if ( isset( $p['name'] ) && is_string( $p['name'] ) ) {
						$names[] = $p['name'];
					}
				}
			}
			if ( ! empty( $r['items'] ) && is_array( $r['items'] ) ) {
				foreach ( $r['items'] as $p ) {
					if ( isset( $p['name'] ) && is_string( $p['name'] ) ) {
						$names[] = $p['name'];
					}
				}
			}
		}
		return array_values( array_unique( $names ) );
	}

	/**
	 * Extract price-like tokens from text. Matches an optional currency symbol or
	 * code immediately followed by a number with a decimal part (e.g. "$59.99",
	 * "Rs90.00", "59.99"). Requiring a decimal part avoids treating bare integers
	 * like "2 items" or "1x" as prices.
	 */
	private static function extract_prices( string $text ): array {
		$out = [];
		if ( preg_match_all( '/(?:[$£€]|Rs\.?|USD|EUR|GBP)?\s?\d{1,3}(?:,\d{3})*\.\d{2}\b/u', $text, $m ) ) {
			$out = array_map( 'trim', $m[0] );
		}
		return $out;
	}

	/** All decimal numbers found anywhere in a string, normalised. */
	private static function extract_numbers( string $text ): array {
		$out = [];
		if ( preg_match_all( '/\d{1,3}(?:,\d{3})*\.\d{2}\b/u', $text, $m ) ) {
			foreach ( $m[0] as $n ) {
				$norm = self::normalize_number( $n );
				if ( '' !== $norm ) {
					$out[] = $norm;
				}
			}
		}
		return array_values( array_unique( $out ) );
	}

	/** Strip currency symbols/commas; keep digits + one decimal point. */
	private static function normalize_number( string $token ): string {
		$clean = preg_replace( '/[^0-9.]/', '', $token );
		return is_string( $clean ) ? $clean : '';
	}

	/**
	 * Candidate product-name mentions: we only treat *quoted* substrings as
	 * explicit product references to keep the heuristic conservative (free prose
	 * contains too many capitalised words to flag every one). A fixture that wants
	 * to test fabricated-name detection should quote the invented name in the
	 * scripted answer (e.g. the "Quantum Widget 9000").
	 */
	private static function candidate_quoted_names( string $text ): array {
		$out = [];
		if ( preg_match_all( '/[\"“”\'‘’]([^\"“”\'‘’]{2,60})[\"“”\'‘’]/u', $text, $m ) ) {
			$out = array_map( 'trim', $m[1] );
		}
		return array_values( array_filter( $out ) );
	}
}
