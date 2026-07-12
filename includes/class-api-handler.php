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

		// Provider failover (issue #58). Build the ordered, key-filtered chain, the
		// configured provider first, then the other as a fallback (each only if it has
		// a key). With NO key at all the chain is empty: preserve the existing no-key
		// WP_Error so an admin still gets the "configure a key" signal.
		$chain = $this->provider_chain();

		if ( empty( $chain ) ) {
			// No key for ANY provider: run the CONFIGURED provider's agent so the admin
			// still gets that provider's "configure a key" WP_Error (the existing no-key
			// signal). Routed by type so an openai-configured store reports the openai
			// key error, not anthropic's.
			return $this->run_provider_agent( get_option( 'fahad_ai_provider', 'anthropic' ), $sanitized );
		}

		// Try each provider AT MOST ONCE, in order (bounded, no loop, no backoff
		// storm). Return the first non-error result; on a provider error fall through
		// to the next. If every provider fails, degrade gracefully rather than
		// surfacing a raw error to the shopper (principle: never a dead end).
		foreach ( $chain as $provider ) {
			$result = $this->run_provider_agent( $provider, $sanitized );

			if ( ! is_wp_error( $result ) ) {
				// Owner analytics (#49): record this resolved turn (outcome + tool trace
				// + funnel flags), privacy-safe, opt-out-able, never fed to the model.
				$this->record_turn_analytics( $sanitized, $result );
				return rest_ensure_response( $result );
			}
		}

		// Every provider failed: the shopper still gets the friendly degraded reply,
		// and the owner-analytics store logs the turn as an error outcome (#49) so the
		// dashboard reflects provider outages, not just answered turns.
		$degraded = $this->degraded_response( $sanitized );
		$this->record_turn_analytics( $sanitized, $degraded, Fahad_AI_Analytics::OUTCOME_ERROR );

		return rest_ensure_response( $degraded );
	}

	/**
	 * Run one non-streaming turn and return ONLY the reply text, the channel-agnostic
	 * core of a turn, for callers that are not the web widget (issue #62: the WhatsApp
	 * channel).
	 *
	 * This is the SAME path handle_message() drives, provider_chain() (configured
	 * provider first, key-filtered), each provider tried at most once, the first
	 * non-error result wins, and a graceful degraded reply if every provider fails, but
	 * it returns the plain `message` string instead of a WP_REST_Response, because an
	 * off-web channel (WhatsApp/SMS/etc.) sends back text, not a REST envelope with
	 * product cards. Cards/comparison are intentionally dropped here: a text channel can
	 * only render the grounded prose the model already writes alongside them.
	 *
	 * IDENTITY: this does NOT change the current user. A caller delivering a turn on
	 * behalf of an off-web user (e.g. a phone number) must NOT have authenticated that
	 * user, so the central login gate (Fahad_AI_Auth) keeps personal-data tools blocked
	 * for an unverified identity (issue #62 hardening). Owner analytics is recorded for a
	 * resolved turn exactly as on the web path (privacy-safe, never fed to the model).
	 *
	 * @param array $messages Conversation messages ([{role,content}, …]); sanitized here.
	 * @return string The assistant's reply text (a friendly degraded line if all providers fail).
	 */
	public function run_text_turn( array $messages ): string {
		$sanitized = $this->sanitize_messages( $messages );

		if ( empty( $sanitized ) ) {
			return $this->degraded_response()['message'];
		}

		if ( function_exists( 'wc_load_cart' ) ) {
			wc_load_cart();
		}

		$chain = $this->provider_chain();

		// No key configured at all: there is no provider to run, so return the friendly
		// degraded line rather than leaking a "configure a key" WP_Error onto a customer
		// channel (the admin still sees the no-key signal on the web/settings path).
		if ( empty( $chain ) ) {
			return $this->degraded_response( $sanitized )['message'];
		}

		// Try each provider AT MOST ONCE, in order; first non-error result wins (same
		// bounded failover as handle_message, no loop, no backoff storm).
		foreach ( $chain as $provider ) {
			$result = $this->run_provider_agent( $provider, $sanitized );

			if ( ! is_wp_error( $result ) ) {
				$this->record_turn_analytics( $sanitized, $result );
				return (string) ( $result['message'] ?? '' );
			}
		}

		// Every provider failed: degrade gracefully (never a dead end), and log the turn
		// as an error outcome for owner analytics (mirrors handle_message).
		$degraded = $this->degraded_response( $sanitized );
		$this->record_turn_analytics( $sanitized, $degraded, Fahad_AI_Analytics::OUTCOME_ERROR );

		return $degraded['message'];
	}

	// =========================================================================
	// Provider failover & graceful degradation (issue #58)
	// =========================================================================

	/**
	 * Whether a provider has an API key configured (its key option is non-empty).
	 *
	 * Used to build provider_chain() so a keyless provider is never attempted, a
	 * call_* would only short-circuit with a "key not configured" WP_Error, which
	 * would burn a failover slot for nothing. Generalised over the whole provider
	 * catalog (issue: multi-provider): the key option is whatever the preset declares
	 * (so anthropic/moonshot keep their existing option names, and every other preset
	 * reads fahad_ai_{id}_api_key). An unknown id has no key.
	 *
	 * @param string $provider A provider id from the catalog (e.g. 'anthropic', 'openai').
	 */
	private function has_provider_key( string $provider ): bool {
		$preset = Fahad_AI_Providers::get( $provider );
		if ( null === $preset ) {
			return false;
		}

		return '' !== (string) get_option( $preset['key_option'], '' );
	}

	/**
	 * Ordered list of providers to try for a turn: the configured provider FIRST,
	 * then every OTHER catalog provider that has a key configured, so failover works
	 * across all providers, not just the original two (issue: multi-provider). The
	 * fallbacks follow catalog order for determinism.
	 *
	 * Examples (with the built-in catalog):
	 *   configured=moonshot + anthropic+moonshot keyed → ['moonshot','anthropic'];
	 *   configured=openai + openai+anthropic+groq keyed → ['openai','anthropic','groq'];
	 *   configured=anthropic + only the anthropic key → ['anthropic']; no keys → [].
	 *
	 * The result has no duplicates and each provider appears at most once, so the
	 * failover loop in handle_message() is inherently bounded (no loop, no backoff
	 * storm, each provider attempted a single time).
	 *
	 * @return string[] Provider ids to try, in order.
	 */
	private function provider_chain(): array {
		$configured = (string) get_option( 'fahad_ai_provider', 'anthropic' );

		// The configured provider goes first IF it has a key; then every other catalog
		// provider that has a key, in catalog order. array_unique guards against the
		// configured id reappearing in the catalog walk.
		$ordered = array_merge( [ $configured ], Fahad_AI_Providers::ids() );

		$chain = [];
		foreach ( array_values( array_unique( $ordered ) ) as $id ) {
			if ( $this->has_provider_key( $id ) ) {
				$chain[] = $id;
			}
		}

		return $chain;
	}

	/**
	 * Run one turn for a provider id, routing by its catalog transport type:
	 * 'anthropic' → the native Anthropic loop; 'openai' (and everything else) → the
	 * generalised OpenAI-compatible loop, parameterised by the provider id. This is
	 * the single place handle_message()/run_text_turn() decide native vs. OpenAI, so
	 * the failover loop stays provider-agnostic.
	 *
	 * @param string $provider Catalog provider id.
	 * @param array  $messages Sanitized conversation messages.
	 * @return array|WP_Error
	 */
	private function run_provider_agent( string $provider, array $messages ): array|WP_Error {
		return ( 'anthropic' === Fahad_AI_Providers::type( $provider ) )
			? $this->run_anthropic_agent( $messages )
			: $this->run_openai_agent( $messages, $provider );
	}

	/**
	 * Friendly, NON-error result returned when every configured provider failed for
	 * a turn. The shopper must never hit a dead end (issue #58): instead of a raw
	 * error or a leaked exception, this points them at the things that still work , 
	 * browsing/searching the store and reaching human support.
	 *
	 * It mirrors the SUCCESS result shape (message/messages/products/comparison) so
	 * the widget renders it like any other turn, plus a `degraded` flag the client
	 * (and tests) can key off. It deliberately carries NO error/exception/key text.
	 *
	 * @param array $messages The conversation transcript to echo back (history intact).
	 * @return array{message:string, messages:array, products:array, comparison:array, degraded:bool}
	 */
	private function degraded_response( array $messages = [] ): array {
		return [
			'message'    => __( 'Sorry, I could not reach the shopping assistant just now. You can still search the store for what you need, and our support team is happy to help if you would like a hand.', 'fahad-ai-shopping-assistant-for-woocommerce' ),
			'messages'   => $messages,
			'products'   => [],
			'comparison' => [],
			'degraded'   => true,
		];
	}

	// =========================================================================
	// Direct cart actions (#48), no agent round-trip
	// =========================================================================

	/**
	 * Map a cart REST action to the built-in tool that performs it; null if unknown.
	 */
	private function cart_action_tool( string $action ): ?string {
		return [
			'add'    => 'add_to_cart',
			'remove' => 'remove_from_cart',
			'view'   => 'view_cart',
		][ $action ] ?? null;
	}

	/**
	 * Direct, verified cart action for the storefront, NO agent round-trip (#48).
	 *
	 * The card "Add to cart" button calls this instead of asking the model to add, so
	 * a cart change is instant, cheap, and never "narrated" without being real. It
	 * reuses the built-in cart tools (and their validation: invalid product / invalid
	 * variation / out-of-stock) and returns the verified cart state. Gated by
	 * authorize_request() (nonce + rate limit) like the chat endpoints. It returns
	 * JSON (not SSE), so WooCommerce emits its session cookie normally and guest carts
	 * persist.
	 */
	public function handle_cart_action( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$action = sanitize_key( (string) $request->get_param( 'action' ) );
		$tool   = $this->cart_action_tool( $action );

		if ( null === $tool ) {
			return new WP_Error( 'invalid_action', __( 'Unknown cart action.', 'fahad-ai-shopping-assistant-for-woocommerce' ), [ 'status' => 400 ] );
		}

		// Load the cart and set the guest session cookie before the response is sent,
		// so a guest's cart change actually persists (same boundary fix as the SSE
		// path, #31). On a JSON REST request headers aren't sent yet, so the cookie
		// goes out fine.
		$this->prime_cart_session();

		$input = [
			'product_id'    => (int) $request->get_param( 'product_id' ),
			'quantity'      => max( 1, (int) ( $request->get_param( 'quantity' ) ?: 1 ) ),
			'variation_id'  => (int) $request->get_param( 'variation_id' ),
			'cart_item_key' => sanitize_text_field( (string) $request->get_param( 'cart_item_key' ) ),
		];

		$result = Fahad_AI_Tool_Registry::instance()->dispatch( $tool, $input );

		// Persist the session immediately so the change survives this REST request , 
		// don't rely on the shutdown hook firing for a REST context.
		if ( function_exists( 'WC' ) && WC()->session && method_exists( WC()->session, 'save_data' ) ) {
			WC()->session->save_data();
		}

		return rest_ensure_response( $result );
	}

	// =========================================================================
	// Reply feedback / guardrail telemetry (#50), no agent, no PII
	// =========================================================================

	/**
	 * Record a 👍/👎 rating for a bot reply (issue #50).
	 *
	 * The widget POSTs a rating ('up'|'down'), an optional short reason, and opaque
	 * conversation/message refs. The handler validates the rating, hands the raw
	 * strings to Fahad_AI_Feedback (which sanitizes, length-caps, stores NO PII,
	 * auto-flags a 👎, and enforces retention), and echoes back the stored id so the
	 * client can reflect the chosen state. An invalid rating is a 400 before anything
	 * is stored. Gated by authorize_request() (nonce + rate limit) like the chat
	 * endpoints; it returns JSON, not SSE.
	 */
	public function handle_feedback( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$rating = sanitize_key( (string) $request->get_param( 'rating' ) );

		if ( ! in_array( $rating, [ Fahad_AI_Feedback::RATING_UP, Fahad_AI_Feedback::RATING_DOWN ], true ) ) {
			return new WP_Error(
				'fahad_ai_invalid_rating',
				__( 'A valid rating is required.', 'fahad-ai-shopping-assistant-for-woocommerce' ),
				[ 'status' => 400 ]
			);
		}

		// Sanitization + length caps + PII exclusion all live in the store; pass the
		// raw param values through (the store is the single place that bounds them).
		$result = Fahad_AI_Feedback::instance()->record(
			$rating,
			(string) $request->get_param( 'reason' ),
			(string) $request->get_param( 'conversation_ref' ),
			(string) $request->get_param( 'message_ref' )
		);

		return rest_ensure_response( $result );
	}

	// =========================================================================
	// Anthropic (Claude), tool_use / end_turn
	// =========================================================================

	/**
	 * Friendly fallback shown when the agent loop ends without the model producing a
	 * final answer (it kept calling tools until the iteration cap). Far better UX than
	 * a raw "exceeded maximum iterations" error, and when cards were already gathered,
	 * it points the shopper at them.
	 */
	private function agent_fallback_message( bool $has_products ): string {
		return $has_products
			? __( 'Here are some options based on what I found above. Let me know which one you would like to explore or add to your cart, and I can take it from there.', 'fahad-ai-shopping-assistant-for-woocommerce' )
			: __( 'Sorry, I had trouble completing that just now. Could you rephrase, or tell me a little more about what you are looking for?', 'fahad-ai-shopping-assistant-for-woocommerce' );
	}

	private function run_anthropic_agent( array $messages ): array|WP_Error {
		$tools      = Fahad_AI_Tools::instance();
		$max        = 8;
		$cards      = [];
		$comparison = [];

		for ( $i = 0; $i < $max; $i++ ) {
			// Cost/latency: bound the outgoing context to the configured token budget
			// (drops only the oldest non-essential history; the in-progress tool loop
			// and the latest turn are preserved). No-op by default (budget 0).
			$response = $this->call_anthropic( $this->apply_token_budget( $messages ), $i );

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
				return [ 'message' => $this->humanize_text( $this->normalize_currency_entities( trim( $text ) ) ), 'messages' => $messages, 'products' => $cards, 'comparison' => $comparison ];
			}

			if ( 'tool_use' === $stop_reason ) {
				$tool_results = [];

				foreach ( $content as $block ) {
					if ( ( $block['type'] ?? '' ) !== 'tool_use' ) {
						continue;
					}
					// Sequence per tool call: execute → surface cards from the FULL
					// result → TRIM the result → append the trimmed copy to the model
					// messages. Cards/comparison use the FULL result; only the model
					// copy is trimmed (issue #23).
					$result         = $tools->execute( $block['name'], $block['input'] ?? [] );
					$cards          = array_merge( $cards, $this->tool_result_cards( $block['name'], $result ) );
					$comparison     = $this->tool_result_comparison( $block['name'], $result ) ?: $comparison;
					$tool_results[] = [
						'type'        => 'tool_result',
						'tool_use_id' => $block['id'],
						'content'     => wp_json_encode( $this->trim_tool_result( $block['name'], $result ) ),
					];
				}

				$messages[] = [ 'role' => 'user', 'content' => $tool_results ];
				continue;
			}

			break;
		}

		// The model never produced a final answer within the iteration budget. Rather
		// than surface a raw error to the shopper, return a friendly fallback and keep
		// any product cards already gathered so the turn is still useful (finding #28).
		return [
			'message'    => $this->agent_fallback_message( ! empty( $cards ) ),
			'messages'   => $messages,
			'products'   => $cards,
			'comparison' => $comparison,
		];
	}

	private function call_anthropic( array $messages, int $iteration = 0 ): array|WP_Error {
		$api_key = get_option( 'fahad_ai_anthropic_api_key', '' );

		if ( empty( $api_key ) ) {
			return new WP_Error( 'no_api_key', __( 'Anthropic API key is not configured.', 'fahad-ai-shopping-assistant-for-woocommerce' ), [ 'status' => 500 ] );
		}

		$tools = $this->get_anthropic_tools();

		// Model routing (issue #23): default is the configured model unchanged; a
		// fahad_ai_model hook may route a simple vs. reasoning turn to a different model.
		$model = $this->resolve_model(
			get_option( 'fahad_ai_anthropic_model', 'claude-haiku-4-5-20251001' ),
			'anthropic',
			[ 'has_tools' => ! empty( $tools ), 'iteration' => $iteration ]
		);

		$payload = [
			'model'      => $model,
			'max_tokens' => 1024,
			'system'     => $this->get_system_prompt(),
			'tools'      => $tools,
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
	// OpenAI-compatible providers, tool_calls / stop
	// =========================================================================
	//
	// One generalised loop for EVERY OpenAI-compatible provider (Moonshot, OpenAI,
	// Gemini, Groq, Mistral, DeepSeek, xAI, Together, OpenRouter, Perplexity, Ollama,
	// and a merchant `custom` endpoint). The only per-provider differences, base URL,
	// API key, model, are resolved from the catalog (Fahad_AI_Providers::resolve) by
	// the $provider id, so adding a provider is data, not code. Moonshot is just the
	// first preset of this path; its behaviour is unchanged.

	/**
	 * @param array  $messages Sanitized conversation messages.
	 * @param string $provider Catalog provider id (any 'openai'-type preset). Defaults
	 *                         to 'moonshot' for backward compatibility with any caller
	 *                         that has not yet been taught the provider argument.
	 */
	private function run_openai_agent( array $messages, string $provider = 'moonshot' ): array|WP_Error {
		$tools      = Fahad_AI_Tools::instance();
		$max        = 8;
		$cards      = [];
		$comparison = [];

		// OpenAI-compatible APIs use a system message as the first entry, not a
		// top-level field.
		$with_system = array_merge(
			[ [ 'role' => 'system', 'content' => $this->get_system_prompt() ] ],
			$messages
		);

		for ( $i = 0; $i < $max; $i++ ) {
			// Cost/latency: bound the outgoing context to the configured token budget
			// (no-op by default). The system message + latest turn + in-progress tool
			// loop are preserved; only the oldest history is condensed.
			$response = $this->call_openai( $this->apply_token_budget( $with_system ), $provider, $i );

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
					'message'    => $this->humanize_text( $this->normalize_currency_entities( trim( $msg['content'] ?? '' ) ) ),
					'messages'   => $messages,   // returned to client (no system msg)
					'products'   => $cards,
					'comparison' => $comparison,
				];
			}

			if ( 'tool_calls' === $finish_reason ) {
				foreach ( $msg['tool_calls'] ?? [] as $call ) {
					$name  = $call['function']['name']      ?? '';
					$input = json_decode( $call['function']['arguments'] ?? '{}', true ) ?? [];

					// Sequence per tool call: execute → surface cards from the FULL
					// result → TRIM → append the trimmed copy to the model messages
					// (issue #23). Cards/comparison use the FULL result.
					$result     = $tools->execute( $name, $input );
					$cards      = array_merge( $cards, $this->tool_result_cards( $name, $result ) );
					$comparison = $this->tool_result_comparison( $name, $result ) ?: $comparison;

					$tool_msg = [
						'role'         => 'tool',
						'tool_call_id' => $call['id'],
						'content'      => wp_json_encode( $this->trim_tool_result( $name, $result ) ),
					];

					$with_system[] = $tool_msg;
					$messages[]    = $tool_msg;
				}
				continue;
			}

			break;
		}

		// The model never produced a final answer within the iteration budget. Rather
		// than surface a raw error to the shopper, return a friendly fallback and keep
		// any product cards already gathered so the turn is still useful (finding #28).
		return [
			'message'    => $this->agent_fallback_message( ! empty( $cards ) ),
			'messages'   => $messages,
			'products'   => $cards,
			'comparison' => $comparison,
		];
	}

	/**
	 * One non-streaming OpenAI-compatible request for the given provider id.
	 *
	 * The provider's base URL, API key and model are resolved from the catalog
	 * (Fahad_AI_Providers::resolve), so the SAME code talks to Moonshot, OpenAI,
	 * Gemini, … differing only in those three values. Auth is the OpenAI-standard
	 * `Authorization: Bearer <key>` for every provider. A missing key returns the
	 * existing no-key WP_Error (admin signal) without making a request.
	 *
	 * @param array  $messages  Sanitized OpenAI-shaped messages (system first).
	 * @param string $provider  Catalog provider id (an 'openai'-type preset).
	 * @param int    $iteration Agent-loop index (passed to the model-routing seam).
	 */
	private function call_openai( array $messages, string $provider = 'moonshot', int $iteration = 0 ): array|WP_Error {
		$resolved = Fahad_AI_Providers::resolve( $provider );
		$label    = $resolved['label'] ?? $provider;

		if ( null === $resolved || '' === $resolved['api_key'] ) {
			return new WP_Error(
				'no_api_key',
				sprintf(
					/* translators: %s: provider label, e.g. "OpenAI" */
					__( '%s API key is not configured.', 'fahad-ai-shopping-assistant-for-woocommerce' ),
					$label
				),
				[ 'status' => 500 ]
			);
		}

		$tools = $this->get_openai_tools();

		// Model routing (issue #23): default unchanged; a fahad_ai_model hook may route.
		$model = $this->resolve_model(
			$resolved['model'],
			$provider,
			[ 'has_tools' => ! empty( $tools ), 'iteration' => $iteration ]
		);

		$payload = [
			'model'      => $model,
			'max_tokens' => 1024,
			'messages'   => $messages,
			'tools'      => $tools,
		];

		$response = wp_remote_post( $this->openai_chat_url( $resolved['base_url'] ), [
			'timeout' => 30,
			'headers' => [
				'Authorization' => 'Bearer ' . $resolved['api_key'],
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
				/* translators: 1: provider label, 2: HTTP status code */
				__( '%1$s API error (HTTP %2$d).', 'fahad-ai-shopping-assistant-for-woocommerce' ),
				$label,
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
	 *
	 * Retained for its own unit tests and as the documented region helper; the
	 * catalog (Fahad_AI_Providers::resolve) computes the same value for the moonshot
	 * preset at dispatch time.
	 */
	private function moonshot_base_url(): string {
		$region = get_option( 'fahad_ai_moonshot_region', 'global' );
		return 'china' === $region
			? 'https://api.moonshot.cn'
			: 'https://api.moonshot.ai';
	}

	/**
	 * Build the chat-completions URL for an OpenAI-compatible base URL.
	 *
	 * Every catalog base URL is the FULL prefix up to (but not including) the
	 * endpoint, i.e. it already carries each provider's version segment (.../v1,
	 * .../openai/v1, .../v1beta/openai, or no version for those that take none). We
	 * therefore append ONLY '/chat/completions', after trimming any trailing slash on
	 * the base, so a single rule is correct for every provider regardless of whether
	 * its endpoint includes a version path.
	 */
	private function openai_chat_url( string $base_url ): string {
		return rtrim( $base_url, '/' ) . '/chat/completions';
	}

	/**
	 * Validate a merchant-supplied custom OpenAI-compatible base URL.
	 *
	 * Security: the base URL is concatenated into the outbound request target, so it
	 * must be a real http(s) URL, never a `javascript:`/`data:` scheme or junk. We
	 * require HTTPS for any remote host (keys travel in the Authorization header), with
	 * ONE exception: a localhost/127.0.0.1 host may use plain http, because a
	 * self-hosted endpoint (e.g. a local proxy or Ollama-style server) on the same box
	 * never leaves the machine. A failed check returns '' (no custom endpoint).
	 *
	 * Delegates to Fahad_AI_Providers::sanitize_base_url so the admin save path and
	 * dispatch share ONE validator. Kept as a (private static) seam here for the
	 * handler's own unit test.
	 *
	 * @param string $raw Raw URL from the settings form.
	 * @return string The sanitized https (or localhost-http) URL, or '' if invalid.
	 */
	private static function sanitize_custom_base_url( string $raw ): string {
		return Fahad_AI_Providers::sanitize_base_url( $raw );
	}

	// =========================================================================
	// Tool definitions, Anthropic format & OpenAI format
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

	/**
	 * Tones a merchant may pick for the assistant's persona (issue #56).
	 *
	 * An ALLOWLIST on purpose: the tone setting maps to a fixed, vetted instruction
	 * line (see merchant_config_block()), so the free-text persona field cannot be
	 * abused to smuggle prompt-injection past the guardrails. The admin save path
	 * (fahad_ai_sanitize_tone) clamps to these keys; anything else collapses to ''.
	 *
	 * @var array<string, string>
	 */
	public const TONES = [
		'friendly'     => 'warm, friendly and approachable',
		'professional' => 'professional, precise and businesslike',
		'concise'      => 'concise and to the point, with minimal small talk',
		'playful'      => 'playful and upbeat, with light humour',
		'luxury'       => 'refined and understated, with a premium, concierge feel',
	];

	/**
	 * The languages the assistant is told it can converse in (issue #61).
	 *
	 * Named in the system prompt so the model knows which scripts to expect and reply
	 * in. This is the SUPPORTED set the multilingual directive references; the merchant
	 * `fahad_ai_languages` option can pin a narrower set, but answer QUALITY (genuinely
	 * fluent output) comes from the live model at runtime. Roman Urdu (Urdu written in
	 * the Latin alphabet) is listed explicitly because shoppers frequently type it.
	 *
	 * @var string
	 */
	public const SUPPORTED_LANGUAGES = 'English, Urdu, Roman Urdu';

	private function get_system_prompt(): string {
		$custom = get_option( 'fahad_ai_system_prompt', '' );
		$base   = ( is_string( $custom ) && '' !== $custom )
			? $custom
			: $this->default_prompt_body();

		// Bounded merchant slot (issue #56): tone/persona, off-limits topics and
		// per-category promo emphasis. Inserted BEFORE the filter and BEFORE the
		// guardrails, so merchant intent is honoured but can never weaken the policy.
		$base .= $this->merchant_config_block();

		// Multilingual directive (issue #61): tell the assistant to detect the shopper's
		// language and reply IN THAT LANGUAGE, while keeping product facts grounded (no
		// translated/invented specs). Lives in the body region, appended BEFORE the
		// filter and BEFORE the guardrails, so the absolute trust policy still wins.
		$base .= $this->language_directive();

		/**
		 * Filter the system prompt sent to the model (issue #20).
		 *
		 * Lets feature packs APPEND compact, clearly-labelled context to the prompt
		 * for the current request WITHOUT editing the agent-loop methods. The
		 * cross-session-memory pack (Fahad_AI_Memory_Tools) uses this to append a
		 * bounded preferences block for a logged-in, opted-in customer. Hooks must
		 * APPEND (never replace) and keep additions small (storage hygiene). Applied
		 * to BOTH the admin's custom prompt and the default prompt, so injection
		 * works regardless of configuration.
		 *
		 * The trust guardrails are deliberately NOT passed through this filter, they
		 * are appended AFTER it returns (see below), so neither this hook, a custom
		 * prompt, nor any merchant config field can drop or override them.
		 *
		 * @param string $prompt The system prompt (base + merchant slot) before guardrails.
		 */
		$filtered = apply_filters( 'fahad_ai_system_prompt', $base );
		$filtered = is_string( $filtered ) ? $filtered : $base;

		// ABSOLUTE guardrails, appended LAST (issue #24, hardened for #56). Because they
		// come after the custom-prompt branch, the merchant config slot AND the
		// fahad_ai_system_prompt filter, the trust / anti-dark-pattern policy is
		// structurally non-overridable: no configuration or hook can remove it. The
		// deterministic eval checkers (scarcity_violations / budget_violations /
		// escalation_present / abstains, beside grounding_violations) enforce the
		// BEHAVIOUR; ApiHandlerTest / MerchantConfigTest pin the POLICY text.
		return $filtered . "\n\n" . $this->trust_guardrails();
	}

	/**
	 * The default prompt body (everything EXCEPT the absolute guardrails).
	 *
	 * Split out from get_system_prompt() so the guardrails can be appended as a
	 * separate, final, non-overridable block (issue #56). Used only when the admin
	 * has not set a custom prompt.
	 */
	private function default_prompt_body(): string {
		$store_name = get_bloginfo( 'name' );
		$currency   = get_woocommerce_currency_symbol();

		return "You are a helpful shopping assistant for {$store_name}. Help customers find products, answer questions, and manage their cart.

Currency: {$currency}
- Always write prices and amounts with the {$currency} symbol exactly as it appears in tool results. Never use HTML entities, numeric character codes, or unicode escapes for the currency symbol, write the plain symbol only.

Product display, important:
- After you call search_products or get_product_details, the storefront automatically shows the matching products to the customer as visual cards (photo, name, price, stock, and View / Add to cart buttons). Do NOT list each product's price, description, link, or image in your text, the cards already show all of that.
- Always write at least one line of text alongside the cards: a short friendly intro, recommendation, or summary (one or two sentences). The cards alone are silent, so never reply with only cards, there must be a sentence introducing or summarising them. You may highlight or compare a couple of options in words, but never repeat the full product list as text.

Sales, deals & discounts, follow exactly:
- When the customer asks what is on sale, about deals, discounts, clearance, or the best prices, call search_products with on_sale set to true (you may also narrow by category or max_price). Present only the products it returns, with their sale prices.
- Never state from memory that a product is or is not on sale, and never say nothing is on sale without first calling search_products with on_sale set to true. If it returns nothing, tell the customer there are no current sales, plainly. If the customer questions whether a specific item is discounted, verify with a tool before answering.

Wallet & store credit, follow exactly:
- When the customer asks about their balance, store credit, wallet, or how much credit they have, call get_wallet_balance and report only the amount it returns. Never state a balance, or that they have any credit, from memory.
- get_wallet_balance only works for a signed-in customer. If it reports the customer is not signed in (or returns an error to that effect), tell them to sign in to see their balance, rather than guessing.
- You may note available store credit when it is genuinely relevant (for example, that it could cover an item the customer is viewing), but only the real amount from the tool, and never as pressure to spend.
- When the customer asks you to find something within their balance or credit (for example 'what can I get with my credit?' or 'find me a gift under my balance'), first call get_wallet_balance, then pass that amount as the max_price to search_products so every option stays within their balance. Do not propose an item priced above their balance unless they choose to top up.
- When the customer asks how to refer a friend, for their referral link or code, or about referral rewards, call get_referral_link and share only the real code, link and reward amounts it returns. If it reports the programme is disabled, tell them the store has no referral programme right now, rather than inventing one.

Back-in-stock alerts, follow exactly:
- When the customer asks to be told when an item that is out of stock comes back (or to watch an item for a price drop), use subscribe_stock_alert with the product and the email they provide. Only offer a back-in-stock alert for an item that is genuinely out of stock, never for an in-stock one. Tell them it is double opt-in: they must click the confirmation link in the email to activate it, and can unsubscribe anytime.

Linking rules, follow exactly:
- After a successful add_to_cart, always end your reply with these two links on the same line: [View Cart](cart_url) · [Checkout](checkout_url), replace cart_url and checkout_url with the actual values from the tool result.
- When the customer asks to check out or go to checkout, include: [Proceed to Checkout](checkout_url), using the checkout_url from view_cart or add_to_cart results.
- Use markdown only for the cart/checkout links above, no other markdown formatting.

Guidelines:
- Always use search_products or get_product_details before recommending a product.
- When a customer wants to buy something, confirm the product, then use add_to_cart.
- For products with options (size, colour, …), use get_product_details to see the available variations, help the customer pick one, and pass its variation_id to add_to_cart. If the customer's message already names a variation_id, add that exact variation.
- Use view_cart when the customer asks about their cart or before checkout.
- Keep responses concise and friendly. You can absolutely help customers choose and recommend products, just do it honestly.

Writing style, follow exactly:
- Write like a friendly, knowledgeable human, not a robot or a brochure. Sound natural and conversational.
- Keep replies concise but complete and coherent: usually one or two sentences. Answer the question fully, then stop. No filler, no repetition, no restating the question.
- Never use em-dashes or en-dashes (the long dash characters). Use commas, periods, or separate sentences instead. Plain hyphens in number ranges (for example 30-40) are fine.";
	}

	/**
	 * The bounded merchant configuration slot (issue #56).
	 *
	 * Folds the merchant's saved tone/persona, off-limits topics and per-category
	 * promo emphasis into a small, clearly-labelled block. Returns '' when nothing is
	 * configured (so the default prompt is byte-for-byte unchanged on a fresh site).
	 *
	 * SAFETY: this block is sandwiched between the base prompt and the absolute
	 * guardrails, and the guardrails are appended after the filter, so anything a
	 * merchant types here is advisory and can never countermand the policy. The tone is
	 * additionally clamped to a fixed allowlist (TONES); off-limits / promo are free
	 * text but sanitized on save and only ever ADD scope restrictions, not remove them.
	 */
	private function merchant_config_block(): string {
		$lines = [];

		$tone = (string) get_option( 'fahad_ai_tone', '' );
		if ( isset( self::TONES[ $tone ] ) ) {
			$lines[] = '- Persona & tone: keep a ' . self::TONES[ $tone ] . ' tone in every reply.';
		}

		$off_limits = trim( (string) get_option( 'fahad_ai_off_limits', '' ) );
		if ( '' !== $off_limits ) {
			$lines[] = '- Off-limits topics: do not discuss or give advice on the following; politely redirect to shopping instead: ' . $off_limits;
		}

		$promo = trim( (string) get_option( 'fahad_ai_promo_emphasis', '' ) );
		if ( '' !== $promo ) {
			$lines[] = '- Promotion emphasis (only when genuinely relevant, and never as pressure): ' . $promo;
		}

		// Free-shipping threshold (issue #202): a grounded, merchant-set fact the model can
		// use to nudge order value. Only a genuine, helpful "you are X away from free
		// shipping" prompt, never fabricated and never applied as pressure.
		$free_shipping = (float) get_option( 'fahad_ai_free_shipping_threshold', 0 );
		if ( $free_shipping > 0 ) {
			$lines[] = '- Free shipping: this store offers free shipping on orders over ' . $this->format_localized_amount( $free_shipping ) . '. When a shopper is genuinely close, you may helpfully mention how much more they need to add to qualify; state it as a fact, never as pressure, and never invent a threshold that is not set here.';
		}

		// Return / refund policy (issue #204): a grounded fact so the assistant can answer
		// return questions accurately instead of deflecting or inventing terms (a real
		// liability). Answer ONLY from this; anything it does not cover escalates to a human.
		$returns = trim( (string) get_option( 'fahad_ai_return_policy', '' ) );
		if ( '' !== $returns ) {
			$lines[] = '- Returns & refunds: answer return, refund, and exchange questions using only this stated policy; never invent terms, and if a shopper asks something it does not cover, say you are not certain and offer human support. Policy: ' . $returns;
		}

		if ( empty( $lines ) ) {
			return '';
		}

		return "\n\nStore preferences (set by the merchant, advisory; the Trust & honesty rules below always take precedence):\n" . implode( "\n", $lines );
	}

	/**
	 * The multilingual directive (issue #61).
	 *
	 * Tells the assistant to DETECT the shopper's language from their latest message and
	 * REPLY IN THAT SAME LANGUAGE (English / Urdu / Roman Urdu), while keeping every
	 * product FACT grounded, prices, specs, names, stock and other data come from the
	 * tool results verbatim and are NEVER translated, localised, or invented. Only the
	 * assistant's own wording switches language; the grounded data does not. This is the
	 * routing + instruction half of the feature; genuinely fluent translation is the
	 * live model's job at runtime.
	 *
	 * The merchant may pin the allowed set via `fahad_ai_languages` (default 'auto' =
	 * detect-and-match freely among the supported languages). A specific value (e.g.
	 * "English, Urdu") is folded in as the preferred set; it is advisory free text and,
	 * like every body-region instruction, sits BEFORE the absolute guardrails so it can
	 * never weaken the trust policy. Always returned (never ''): a multilingual audience
	 * is the deployment assumption, so the detect-and-match instruction is unconditional.
	 */
	private function language_directive(): string {
		$configured = trim( (string) get_option( 'fahad_ai_languages', 'auto' ) );

		// 'auto' (the default) is a config token, never an instruction word, detect and
		// match freely across the supported set. A specific value pins the preferred set.
		$preferred = ( '' === $configured || 'auto' === strtolower( $configured ) )
			? self::SUPPORTED_LANGUAGES
			: $configured;

		return "\n\nLanguage:
- Detect the language of the customer's most recent message and reply in that same language. You can converse in {$preferred}. Roman Urdu means Urdu written in the Latin alphabet; if the customer writes Roman Urdu, reply in Roman Urdu (not Urdu script) unless they switch.
- Match the customer's language and script; do not switch languages on them unprompted.
- Keep all product facts grounded regardless of language: product details, specifications, prices, names, and stock come from the tool results and must not be translated, localised, reworded into new claims, or invented. Translate only your own explanatory wording, never the underlying data. Currency symbols and amounts stay exactly as the tool results report them.";
	}

	/**
	 * Format a monetary amount for display with the store's currency symbol and the
	 * locale's number formatting (issue #61).
	 *
	 * A small, deterministic, unit-testable helper for composing a localised amount
	 * string. It reuses `get_woocommerce_currency_symbol()` for the symbol and honours
	 * WooCommerce's configured decimal/thousand separators and decimal count when those
	 * helpers are available, falling back to PHP `number_format` defaults otherwise.
	 *
	 * It deliberately does NOT change the LIVE price source: tool results keep formatting
	 * prices via `wc_price()` (see Fahad_AI_Tools). This helper exists for any amount the
	 * plugin composes itself that should respect the locale, without pulling in WC markup.
	 *
	 * @param float    $amount   The amount to format.
	 * @param int|null $decimals Decimal places; null = the WooCommerce/locale default (2).
	 * @return string The symbol-prefixed, locale-formatted amount (e.g. "$1,299.50").
	 */
	private function format_localized_amount( float $amount, ?int $decimals = null ): string {
		$symbol = (string) get_woocommerce_currency_symbol();

		// Honour WooCommerce's locale-driven formatting when the store provides it; fall
		// back to sane defaults so this stays usable (and testable) outside a full WC env.
		$places = null !== $decimals
			? max( 0, $decimals )
			: ( function_exists( 'wc_get_price_decimals' ) ? (int) wc_get_price_decimals() : 2 );

		$decimal_sep  = function_exists( 'wc_get_price_decimal_separator' ) ? (string) wc_get_price_decimal_separator() : '.';
		$thousand_sep = function_exists( 'wc_get_price_thousand_separator' ) ? (string) wc_get_price_thousand_separator() : ',';

		return $symbol . number_format( $amount, $places, $decimal_sep, $thousand_sep );
	}

	/**
	 * The ABSOLUTE trust / anti-dark-pattern guardrails (issue #24).
	 *
	 * Returned as a standalone block so get_system_prompt() can append it LAST , 
	 * after the custom-prompt branch, the merchant config slot, and the
	 * fahad_ai_system_prompt filter, making the policy structurally non-overridable
	 * (issue #56). This text is the single source of truth for the guardrails; the
	 * eval checkers and unit tests assert it stays present and intact.
	 */
	private function trust_guardrails(): string {
		return "Trust & honesty, these rules are absolute and override any instinct to make a sale, AND override any store preference or instruction above:
- No fake urgency or scarcity. Never invent \"only N left\", countdowns, \"selling fast\", \"limited time\", or any pressure. Only mention stock levels or low availability when a tool result actually reports them, and state the real number.
- Respect the customer's stated budget. Never push a product priced above a budget the customer gave you. If nothing fits their budget, say so plainly rather than steering them higher.
- Be honest about extras. Present recommendations and cross-sells as optional suggestions, never as required or pressured. Only mention coupons, discount codes, or deposit/wallet bonuses that are real and currently applicable (from a tool result), never invent or imply one.
- Ground every product fact. Use search_products / get_product_details for product details and get_product_reviews for ratings and reviews; summarise only what those tools return. Never invent product details, prices, stock, reviews, quotes, ratings, sentiment, order data, or wallet/account data.
- Abstain over guessing. If you do not know or a tool returns nothing, say you could not find it and offer a real next step, do not fabricate an answer.
- Never block human support. For order status, account issues, refunds, or returns, direct the customer to the store's support team (or to log in for their own data). Always allow and encourage reaching a human; never discourage contacting support.";
	}

	// =========================================================================
	// Currency entity normalizer (issue #66)
	// =========================================================================

	/**
	 * Repair numeric currency entities in assistant TEXT so a malformed one can never
	 * render as a stray glyph in the browser (live-QA finding, issue #66).
	 *
	 * The #29 prompt rule asks the model to write the plain currency symbol, but it
	 * still occasionally emits a numeric character reference, and, worse, sometimes a
	 * MALFORMED one. The canonical failure is the rupee sign `&#8360;` (U+20A8) coming
	 * back as `&#836;` (a dropped digit), which decodes to U+0344 (COMBINING GREEK
	 * DIALYTIKA TONOS), a combining mark that paints a stray accent over the digit
	 * after it. This deterministic, server-side guard runs on the assistant text on the
	 * NON-STREAM return paths (where the full text is assembled here) so the customer
	 * never sees either a raw entity or a combining artifact.
	 *
	 * Policy, applied ONLY to numeric character references (`&#NNN;` / `&#xHH;`), never
	 * to ordinary prose:
	 *   - A well-formed reference is decoded to its real character (so `&#8360;` → ₨).
	 *   - A reference whose codepoint is UNSAFE, a C0/C1 control or a Unicode combining
	 *     mark (the corruption class here), is REPAIRED to the configured currency
	 *     symbol, on the assumption the model was trying to write currency. It is never
	 *     emitted as the combining/control character.
	 * Named entities and the rest of the text are left untouched.
	 *
	 * Deterministic + offline, so it is unit-testable (the JS render path is not
	 * reachable from PHPUnit); the streaming path has a parallel guard in chatbot.js.
	 *
	 * @param string $text The assistant's final answer text.
	 * @return string The text with currency entities decoded or repaired.
	 */
	private function normalize_currency_entities( string $text ): string {
		if ( ! str_contains( $text, '&#' ) ) {
			return $text; // No numeric character reference, nothing to do.
		}

		$symbol = (string) get_woocommerce_currency_symbol();

		return (string) preg_replace_callback(
			'/&#(x[0-9a-f]+|\d+);/i',
			static function ( array $m ) use ( $symbol ): string {
				$raw  = $m[1];
				$code = ( 'x' === strtolower( $raw[0] ) )
					? (int) hexdec( substr( $raw, 1 ) )
					: (int) $raw;

				// Unsafe codepoints: C0 controls (0-0x1F), C1 controls (0x7F-0x9F),
				// and combining marks (the corruption class, e.g. U+0344). Repair to
				// the plain currency symbol rather than ever rendering the artifact.
				$is_control   = $code <= 0x1F || ( $code >= 0x7F && $code <= 0x9F );
				$is_combining = ( $code >= 0x0300 && $code <= 0x036F )   // combining diacritical marks
					|| ( $code >= 0x1AB0 && $code <= 0x1AFF )            // combining diacritical marks extended
					|| ( $code >= 0x1DC0 && $code <= 0x1DFF )            // combining diacritical marks supplement
					|| ( $code >= 0x20D0 && $code <= 0x20FF )            // combining diacritical marks for symbols
					|| ( $code >= 0xFE20 && $code <= 0xFE2F );           // combining half marks

				if ( $is_control || $is_combining ) {
					return $symbol;
				}

				// Well-formed: decode the numeric reference to its real character.
				$decoded = html_entity_decode( $m[0], ENT_QUOTES | ENT_HTML5, 'UTF-8' );
				return is_string( $decoded ) && '' !== $decoded ? $decoded : $symbol;
			},
			$text
		);
	}

	/**
	 * Humanize the assistant's reply text (issue #130).
	 *
	 * The system prompt asks the model to write like a person and to never use an
	 * em-dash (U+2014) or en-dash (U+2013), but models ignore that often, so
	 * this is the deterministic server-side guard applied to the assistant TEXT on
	 * BOTH the non-stream return and the buffered streaming chunk. A dash between two
	 * digits is a numeric range and becomes a plain hyphen (e.g. "30-40" → "30-40");
	 * any other em/en dash (with its surrounding spaces) becomes a comma, which reads
	 * naturally where the model used a dash to join clauses. Idempotent: re-running on
	 * already-clean text is a no-op. Only the assistant's own prose is touched, product
	 * data is rendered from cards, not this text.
	 */
	private function humanize_text( string $text ): string {
		if ( '' === $text || ( ! str_contains( $text, "\u{2014}" ) && ! str_contains( $text, "\u{2013}" ) ) ) {
			return $text; // No em/en dash, nothing to humanize.
		}

		// Numeric ranges keep a hyphen so "30-40" stays a range, not "30, 40".
		$text = (string) preg_replace( '/(\d)\s*[\x{2014}\x{2013}]\s*(\d)/u', '$1-$2', $text );
		// Any remaining em/en dash (with surrounding spaces) becomes a comma.
		$text = (string) preg_replace( '/\s*[\x{2014}\x{2013}]\s*/u', ', ', $text );
		// Tidy any doubled comma / run of spaces a replacement may have introduced.
		$text = (string) preg_replace( '/,\s*,/', ',', $text );
		$text = (string) preg_replace( '/[ \t]{2,}/', ' ', $text );

		return $text;
	}

	// =========================================================================
	// Owner analytics recording (issue #49)
	// =========================================================================

	/**
	 * Record one resolved assistant turn into the owner-analytics store (issue #49).
	 *
	 * Called from the dispatch / stream terminal points with the turn's INPUT messages
	 * and its RESULT. Derives a privacy-safe event, a trimmed, email-masked question
	 * snippet, the tool names called, the coarse outcome, the funnel flags
	 * (product_surfaced / added_to_cart) and an OPAQUE per-conversation ref, and hands
	 * it to Fahad_AI_Analytics, which applies the PII masking, bounds, retention and
	 * opt-out. The store NEVER feeds any of this back to the model; this is owner
	 * telemetry only.
	 *
	 * NEGLIGIBLE OVERHEAD + OPT-OUT: short-circuits before doing ANY derivation when the
	 * merchant has disabled analytics (one option read), so a store that opts out pays
	 * almost nothing. Wrapped so a recording failure can never break a shopper's turn.
	 *
	 * @param array  $input_messages The sanitized INPUT messages for the turn (used to
	 *                               derive the question + a stable conversation ref).
	 * @param array  $result         The loop result (message/messages/products).
	 * @param string $outcome_hint   Force an outcome (OUTCOME_*); '' to auto-derive.
	 * @param array  $overrides      Optional { tools: string[], added_to_cart: bool } from
	 *                               the streaming path, which tracks these as it goes.
	 */
	private function record_turn_analytics( array $input_messages, array $result, string $outcome_hint = '', array $overrides = [] ): void {
		$analytics = Fahad_AI_Analytics::instance();

		// Opt-out / negligible-overhead gate: do no derivation when disabled.
		if ( ! $analytics->enabled() ) {
			return;
		}

		try {
			$messages = is_array( $result['messages'] ?? null ) ? $result['messages'] : [];

			$tools = isset( $overrides['tools'] ) && is_array( $overrides['tools'] )
				? $overrides['tools']
				: $this->analytics_tool_trace( $messages );

			$added_to_cart = array_key_exists( 'added_to_cart', $overrides )
				? (bool) $overrides['added_to_cart']
				: in_array( 'add_to_cart', $tools, true );

			$product_surfaced = ! empty( $result['products'] );

			$outcome = '' !== $outcome_hint
				? $outcome_hint
				: $this->analytics_outcome( $tools, $product_surfaced, $messages );

			$analytics->record( [
				'question'         => $this->analytics_last_user_question( $input_messages ),
				'tools'            => $tools,
				'outcome'          => $outcome,
				'product_surfaced' => $product_surfaced,
				'added_to_cart'    => $added_to_cart,
				// Token/cost are not exposed by the current provider responses; recorded
				// as 0 (the store + dashboard treat 0 as "unknown"). A future change that
				// surfaces usage can populate these without touching the store.
				'tokens'           => 0,
				'cost'             => 0.0,
				'conversation_ref' => $this->analytics_conversation_ref( $input_messages ),
			] );
		} catch ( \Throwable $e ) {
			// Telemetry must never break a turn. Swallow and move on.
			return;
		}
	}

	/**
	 * The most recent genuine user question from the input messages, a plain-string
	 * user turn (NOT a tool_result block, which is role user with array content). The
	 * raw text is returned; Fahad_AI_Analytics masks emails + caps length on store, so
	 * masking lives in exactly one place.
	 */
	private function analytics_last_user_question( array $messages ): string {
		$question = '';
		foreach ( $messages as $msg ) {
			if ( ( $msg['role'] ?? '' ) === 'user' && is_string( $msg['content'] ?? null ) ) {
				$question = (string) $msg['content'];
			}
		}
		return $question;
	}

	/**
	 * A stable, OPAQUE conversation ref derived from the conversation's OPENING user
	 * turn, so every turn in the same conversation maps to the same bucket (best-effort
	 * funnel/cost attribution, the issue explicitly allows best-effort here). It is a
	 * hash, never readable PII, and the message endpoint carries no conversation token
	 * of its own, so this is the most stable key available without one. An empty
	 * conversation yields '' (the store buckets that turn anonymously).
	 */
	private function analytics_conversation_ref( array $messages ): string {
		foreach ( $messages as $msg ) {
			if ( ( $msg['role'] ?? '' ) === 'user' && is_string( $msg['content'] ?? null ) ) {
				return substr( md5( (string) $msg['content'] ), 0, 16 );
			}
		}
		return '';
	}

	/**
	 * The ordered tool names called during the turn, read from the transcript. Handles
	 * BOTH provider shapes: Anthropic assistant `tool_use` content blocks, and the
	 * OpenAI/Moonshot assistant `tool_calls` array. Used for the non-streaming paths
	 * (the streaming path passes its own trace, which it accumulates live).
	 *
	 * @return string[]
	 */
	private function analytics_tool_trace( array $messages ): array {
		$tools = [];
		foreach ( $messages as $msg ) {
			if ( ( $msg['role'] ?? '' ) !== 'assistant' ) {
				continue;
			}

			// Anthropic: content is an array of blocks; tool_use blocks carry a name.
			if ( is_array( $msg['content'] ?? null ) ) {
				foreach ( $msg['content'] as $block ) {
					if ( is_array( $block ) && ( $block['type'] ?? '' ) === 'tool_use' && ! empty( $block['name'] ) ) {
						$tools[] = (string) $block['name'];
					}
				}
			}

			// Moonshot / OpenAI: an assistant message with a tool_calls array.
			foreach ( $msg['tool_calls'] ?? [] as $call ) {
				$name = $call['function']['name'] ?? '';
				if ( '' !== $name ) {
					$tools[] = (string) $name;
				}
			}
		}
		return $tools;
	}

	/**
	 * Derive the coarse outcome for a normally-completed turn (the dispatch path passes
	 * OUTCOME_ERROR explicitly when every provider failed, so this only classifies the
	 * success case):
	 *   - ESCALATED  , a personal tool returned `requires_login` (the grounded "please
	 *                   sign in / reach support" handoff), detectable in the transcript.
	 *   - NO_TOOL_MATCH, the model answered with NO tool call and surfaced no product
	 *                   (a pure-chat / "I couldn't act on that" turn).
	 *   - ANSWERED   , otherwise (it used a tool and/or surfaced a product).
	 *
	 * Deterministic and cheap, no model round-trip. Abstention is intentionally NOT
	 * inferred here (it needs the answer-text checker the eval harness owns); the store
	 * still supports OUTCOME_ABSTAINED for callers that can classify it.
	 */
	private function analytics_outcome( array $tools, bool $product_surfaced, array $messages ): string {
		if ( $this->transcript_requires_login( $messages ) ) {
			return Fahad_AI_Analytics::OUTCOME_ESCALATED;
		}

		if ( empty( $tools ) && ! $product_surfaced ) {
			return Fahad_AI_Analytics::OUTCOME_NO_TOOL_MATCH;
		}

		return Fahad_AI_Analytics::OUTCOME_ANSWERED;
	}

	/**
	 * Whether any tool_result in the transcript reported `requires_login`, the central
	 * login-gate's grounded escalation signal (Fahad_AI_Auth::guard_logged_in). Tool
	 * results are JSON-encoded into the transcript (role user array content on
	 * Anthropic, role tool on Moonshot), so a substring probe for the flag is a cheap,
	 * provider-agnostic detector.
	 */
	private function transcript_requires_login( array $messages ): bool {
		foreach ( $messages as $msg ) {
			$role = $msg['role'] ?? '';
			if ( 'tool' === $role && is_string( $msg['content'] ?? null ) ) {
				if ( str_contains( $msg['content'], 'requires_login' ) ) {
					return true;
				}
			}
			if ( 'user' === $role && is_array( $msg['content'] ?? null ) ) {
				foreach ( $msg['content'] as $block ) {
					$content = is_array( $block ) ? ( $block['content'] ?? '' ) : '';
					if ( is_string( $content ) && str_contains( $content, 'requires_login' ) ) {
						return true;
					}
				}
			}
		}
		return false;
	}

	// =========================================================================
	// Cost & latency controls (issue #23)
	// =========================================================================

	/**
	 * Heavy product fields the MODEL does not need to reason or answer. Dropped from
	 * the COPY of a tool result appended to the model's message history (never from
	 * the widget card payload, which is built from the FULL result first).
	 *
	 * The kept essentials are deliberately conservative: name + price (grounding),
	 * id (so the model can act on it), in_stock + on_sale (availability reasoning).
	 * Everything in this list is bulky and card-only (images, descriptions, the
	 * regular/sale split the card renders, the product URL the card links to).
	 *
	 * @var string[]
	 */
	private const TRIM_DROP_PRODUCT_FIELDS = [
		'image',
		'short_description',
		'description',
		'regular_price',
		'sale_price',
		'url',
	];

	/**
	 * Trim a tool result to the essentials the MODEL needs, returning a COPY.
	 *
	 * The full tool result is JSON-encoded and appended to the model's message
	 * history every turn, and product results carry up to ten products, each with
	 * an image URL, long descriptions and a regular/sale price split that only the
	 * widget card uses. This shrinks the copy fed to the model (fewer tokens → lower
	 * bill + latency) WITHOUT touching the data used for cards.
	 *
	 * CRITICAL SEPARATION (see the agent loops): cards are built and surfaced from
	 * the FULL result BEFORE this runs; only the value appended to the model
	 * messages is trimmed. This method must not mutate its input, it builds a new
	 * array, so the caller's full result (held for card emission) is unchanged.
	 *
	 * Tool-aware and SAFE (when unsure, keep it, grounding beats savings):
	 *   - products[] results (search / best-sellers / recommendations): each product
	 *     is reduced to the kept essentials; other top-level scalars (found, message)
	 *     pass through.
	 *   - comparison results (products[] + aligned attributes[]): the per-product
	 *     columns are trimmed, but the attribute ROWS are kept verbatim, the model
	 *     reasons over them and the answer references them.
	 *   - a single product-shaped result (id + name) is trimmed SUBTRACTIVELY: only
	 *     the heavy product fields are dropped, every OTHER field is kept (so a
	 *     reviews result's snippets, a detail result's variations/sku/categories,
	 *     etc., which the model legitimately summarises, survive).
	 *   - everything else (cart actions with their cart_url/checkout_url/message/
	 *     totals, errors, requires_login, shipping rates, …) passes through unchanged.
	 *
	 * Filterable so it can be tuned or disabled:
	 *   apply_filters( 'fahad_ai_trim_tool_result', array $trimmed, string $tool, array $full )
	 *
	 * @param string $tool   Name of the tool that produced the result.
	 * @param array  $full   The FULL tool result (must NOT be mutated here).
	 * @return array The trimmed copy to append to the model's message history.
	 */
	private function trim_tool_result( string $tool, array $full ): array {
		$trimmed = $full;

		if ( ! empty( $full['products'] ) && is_array( $full['products'] ) ) {
			// products[] (search / best-sellers / recommendations / comparison columns).
			$trimmed['products'] = array_map( [ $this, 'trim_product_summary' ], $full['products'] );
			// Comparison attribute rows are kept verbatim above (carried by $trimmed
			// = $full and not overwritten); nothing else to do.
		} elseif ( ! empty( $full['id'] ) && ! empty( $full['name'] ) ) {
			// A single product-shaped result: subtractive trim (drop heavy fields,
			// keep every other field, reviews/variations/etc. the model summarises).
			foreach ( self::TRIM_DROP_PRODUCT_FIELDS as $field ) {
				unset( $trimmed[ $field ] );
			}
		}
		// else: cart actions, errors, shipping, category lists, … pass through whole.

		/**
		 * Filter the trimmed tool result appended to the model's message history (issue #23).
		 *
		 * Lets a site tune which fields the model sees (cost/latency vs. context) or
		 * disable trimming entirely by returning $full. The widget card payload is
		 * unaffected, it is built from $full before the trim, so this only changes
		 * what the MODEL reads, never what the customer sees.
		 *
		 * @param array  $trimmed The trimmed result that will be sent to the model.
		 * @param string $tool    The tool that produced the result.
		 * @param array  $full    The full, untrimmed result (also used for cards).
		 */
		$result = apply_filters( 'fahad_ai_trim_tool_result', $trimmed, $tool, $full );

		return is_array( $result ) ? $result : $trimmed;
	}

	/**
	 * Reduce one product entry (a format_product_summary shape) to the kept
	 * essentials for the model copy. Only fields actually present are emitted, so a
	 * minimal add-on product shape is not padded with empties.
	 *
	 * @param mixed $product One product entry from a products[] result.
	 * @return mixed The trimmed entry (non-array entries pass through untouched).
	 */
	private function trim_product_summary( $product ) {
		if ( ! is_array( $product ) ) {
			return $product;
		}

		$keep = [ 'id', 'name', 'price', 'in_stock', 'on_sale' ];
		$out  = [];
		foreach ( $keep as $field ) {
			if ( array_key_exists( $field, $product ) ) {
				$out[ $field ] = $product[ $field ];
			}
		}

		return $out;
	}

	/**
	 * Bound the outgoing message context to a configurable per-conversation token
	 * budget, dropping the OLDEST non-essential history when over.
	 *
	 * Budget resolution: option `fahad_ai_token_budget` (sane default 0 = unlimited),
	 * then the `fahad_ai_token_budget` filter. 0 / absent / negative ⇒ unlimited, so
	 * behaviour is unchanged unless a site opts in.
	 *
	 * Estimation: a deterministic char/÷4 proxy over the JSON-encoded messages
	 * (~4 chars per token is a standard rule of thumb). It does not need to match a
	 * provider tokenizer exactly, it only needs to be a stable, testable bound.
	 *
	 * What is PRESERVED when over budget (never broken):
	 *   - a leading system message (Moonshot passes the system prompt as messages[0]);
	 *   - the most recent user turn AND everything after it, i.e. the in-progress
	 *     tool loop (assistant tool_use + the user/tool tool_result messages), so a
	 *     turn mid-flight is never split.
	 * The "middle" (older history between the system message and the latest user
	 * turn) is dropped from the OLDEST end, one message at a time, until the estimate
	 * fits or the middle is empty. If even the protected head+tail exceed the budget,
	 * we send the head+tail (correctness/grounding over a hard size cap).
	 *
	 * @param array $messages The outgoing message array (with or without a leading system message).
	 * @return array The (possibly condensed) message array.
	 */
	private function apply_token_budget( array $messages ): array {
		$budget = (int) apply_filters(
			'fahad_ai_token_budget',
			(int) get_option( 'fahad_ai_token_budget', 0 )
		);

		// 0 / negative ⇒ unlimited.
		if ( $budget <= 0 || empty( $messages ) ) {
			return $messages;
		}

		if ( $this->estimate_tokens( $messages ) <= $budget ) {
			return $messages;
		}

		// Protect a leading system message (Moonshot).
		$has_system = isset( $messages[0]['role'] ) && 'system' === $messages[0]['role'];
		$head       = $has_system ? array_slice( $messages, 0, 1 ) : [];
		$body       = $has_system ? array_slice( $messages, 1 ) : $messages;

		// The protected tail starts at the LAST genuine user turn (role user with
		// plain string content, a human message, NOT a tool_result block, which is
		// role user with array content on Anthropic). Everything from there to the
		// end is the active turn + its in-progress tool loop and is never dropped.
		$tail_start = null;
		foreach ( $body as $i => $msg ) {
			if ( ( $msg['role'] ?? '' ) === 'user' && is_string( $msg['content'] ?? null ) ) {
				$tail_start = $i;
			}
		}
		// No genuine user turn found → protect the final message as the tail.
		if ( null === $tail_start ) {
			$tail_start = count( $body ) - 1;
		}

		$middle = array_slice( $body, 0, $tail_start );
		$tail   = array_slice( $body, $tail_start );

		// Drop oldest middle messages until we fit (or the middle is exhausted).
		while ( ! empty( $middle )
			&& $this->estimate_tokens( array_merge( $head, $middle, $tail ) ) > $budget ) {
			array_shift( $middle );
		}

		return array_merge( $head, $middle, $tail );
	}

	/**
	 * Estimate the token footprint of a message array with a char/÷4 proxy over its
	 * JSON encoding. Deterministic and offline, used only to compare against the
	 * configured budget, so an approximate-but-stable count is sufficient.
	 *
	 * @param array $messages Messages to size.
	 * @return int Estimated tokens.
	 */
	private function estimate_tokens( array $messages ): int {
		$json = wp_json_encode( $messages );
		return (int) ceil( strlen( is_string( $json ) ? $json : '' ) / 4 );
	}

	/**
	 * Resolve the model for a turn, allowing configurable routing.
	 *
	 * The DEFAULT preserves today's behaviour exactly: the configured model is
	 * returned unchanged. A site can route to a cheaper/faster model for simple turns
	 * and a more capable one for reasoning by hooking:
	 *
	 *   apply_filters( 'fahad_ai_model', string $default_model, string $provider, array $context )
	 *
	 * where $context describes the turn, `has_tools` (bool: whether tools are in
	 * play) and `iteration` (int: the agent-loop index), so a heuristic can pick by
	 * complexity. A filter returning a non-string (or empty) value is ignored and the
	 * configured default stands (defence in depth, a bad hook never poisons the
	 * payload).
	 *
	 * Built-in fast-model routing (issue #56) sits UNDER the filter: when the merchant
	 * enables `fahad_ai_fast_model_routing` and sets a `fahad_ai_fast_model`, a SIMPLE
	 * turn (no tools in play) is routed to that cheaper/faster model before the filter
	 * runs. This makes the #23 routing seam settable from admin without per-pack edits,
	 * while the `fahad_ai_model` filter still has the final say (advanced override).
	 *
	 * @param string $default  The configured model for the provider.
	 * @param string $provider 'anthropic' | 'moonshot'.
	 * @param array  $context  Turn context: { has_tools: bool, iteration: int }.
	 * @return string The model to use for this request.
	 */
	private function resolve_model( string $default, string $provider, array $context = [] ): string {
		$routed = $this->fast_model_route( $default, $context );

		$model = apply_filters( 'fahad_ai_model', $routed, $provider, $context );

		return ( is_string( $model ) && '' !== $model ) ? $model : $routed;
	}

	/**
	 * Option-driven fast-model routing for simple turns (issue #56).
	 *
	 * Returns the configured fast model when routing is enabled, a non-empty fast model
	 * is set, and the turn has no tools in play (`has_tools` false), the cheap path for
	 * greetings/chit-chat. Otherwise returns $default unchanged, so the capable model is
	 * used whenever the agent is actually reasoning with tools. This is the DEFAULT that
	 * the fahad_ai_model filter can still override.
	 *
	 * @param string $default The configured model for the provider.
	 * @param array  $context Turn context: { has_tools: bool, iteration: int }.
	 * @return string
	 */
	private function fast_model_route( string $default, array $context ): string {
		if ( ! empty( $context['has_tools'] ) ) {
			return $default;
		}

		if ( ! get_option( 'fahad_ai_fast_model_routing', false ) ) {
			return $default;
		}

		$fast = (string) get_option( 'fahad_ai_fast_model', '' );

		return '' !== $fast ? $fast : $default;
	}

	// =========================================================================
	// Product cards (surfaced to the widget for rich rendering)
	// =========================================================================

	/**
	 * Build the product-card payload the widget renders, from a tool result.
	 *
	 * CONVENTION over configuration: card emission keys off the SHAPE of the tool
	 * result, not the tool's name, so every product tool, the built-in
	 * search_products / get_product_details AND any filter-registered tool that
	 * returns the same shape (get_top_products, recommendations, comparisons, …) , 
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
		// A comparison-shaped result is surfaced as its own `comparison` payload
		// (tool_result_comparison) and renders as a single side-by-side table, it
		// must NOT also emit a redundant run of product cards for the same products.
		if ( $this->is_comparison_result( $result ) ) {
			return [];
		}

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
	 * Reduce product cards to those not already streamed this turn (bug #97).
	 *
	 * The agent loop can surface the same product from more than one tool call in a
	 * single turn (e.g. search_products then get_product_details on the same item).
	 * Each such result previously emitted its own `products` SSE event, so the widget
	 * appended the same card twice. Keyed on the WooCommerce product id, this keeps
	 * only cards not yet sent and records the ids it lets through, so each product
	 * appears at most once per turn. A card without a usable (> 0) id cannot be
	 * deduped reliably, so it passes through unchanged.
	 *
	 * @param array            $cards    Normalized cards from tool_result_cards().
	 * @param array<int, bool> $sent_ids Set of already-sent product ids (id => true),
	 *                                   updated in place across the turn's tool calls.
	 * @return array The subset of $cards not previously sent, original order preserved.
	 */
	private function dedupe_cards( array $cards, array &$sent_ids ): array {
		$fresh = [];
		foreach ( $cards as $card ) {
			$id = isset( $card['id'] ) ? (int) $card['id'] : 0;
			if ( $id > 0 ) {
				if ( isset( $sent_ids[ $id ] ) ) {
					continue;
				}
				$sent_ids[ $id ] = true;
			}
			$fresh[] = $card;
		}
		return $fresh;
	}

	/**
	 * Build the comparison-table payload the widget renders, from a tool result.
	 *
	 * CONVENTION over configuration, exactly like tool_result_cards(): emission keys
	 * off the SHAPE of the result, not the tool's name, so any tool returning an
	 * aligned comparison (products[] + an `attributes` row list) surfaces a
	 * comparison table without this method being taught its name. A non-comparison
	 * result (plain cards, cart action, error, …) yields an empty array.
	 *
	 * The payload is:
	 *   {
	 *     products:   [ <normalized card>, … ],   // one column per compared product
	 *     attributes: [ { name, values: { <product_id>: <value>, … } }, … ]  // rows
	 *   }
	 *
	 * The columns reuse normalize_card() so the product fields (id/name/price/stock/
	 * url/image/rating) match the regular product cards and carry the View/Add
	 * affordances; the attribute rows pass through (sanitized) as the table body.
	 *
	 * Card/column data comes straight from WooCommerce (via the tools), never from
	 * model-generated text, so the widget can trust these fields, same invariant as
	 * the product cards.
	 *
	 * @param string $tool   Name of the tool that produced the result (unused;
	 *                        emission is by result shape, see above).
	 * @param array  $result The tool result array.
	 * @return array Comparison payload, or [] when the result is not a comparison.
	 */
	private function tool_result_comparison( string $tool, array $result ): array {
		if ( ! $this->is_comparison_result( $result ) ) {
			return [];
		}

		$products = array_values( array_filter( array_map( [ $this, 'normalize_card' ], $result['products'] ) ) );

		// Defence in depth: a comparison needs at least two real columns. If
		// normalization dropped malformed product entries below two, do not surface a
		// degenerate one-column "table".
		if ( count( $products ) < 2 ) {
			return [];
		}

		return [
			'products'   => $products,
			'attributes' => $this->normalize_comparison_attributes( $result['attributes'] ),
		];
	}

	/**
	 * Whether a tool result is comparison-shaped: a products[] list of at least two
	 * entries AND an aligned `attributes` row list (which is what distinguishes a
	 * comparison from a plain card result, search/best-sellers have products[] but
	 * no `attributes` key). The attributes list may be empty (products with no shared
	 * attributes still compare on name/price/etc.), so its mere presence as an array
	 * alongside ≥2 products is the signal.
	 */
	private function is_comparison_result( array $result ): bool {
		return isset( $result['attributes'] )
			&& is_array( $result['attributes'] )
			&& ! empty( $result['products'] )
			&& is_array( $result['products'] )
			&& count( $result['products'] ) >= 2;
	}

	/**
	 * Reduce the aligned attribute rows to the trusted shape the widget renders:
	 * a list of { name, values: { product_id => string } }. Rows without a usable
	 * name are dropped; each value is cast to a string. The product-id keys are kept
	 * as-is so the widget can line each value up under its product column.
	 *
	 * @param mixed $attributes The raw `attributes` value from the tool result.
	 * @return array<int, array{name:string, values: array<int,string>}>
	 */
	private function normalize_comparison_attributes( $attributes ): array {
		if ( ! is_array( $attributes ) ) {
			return [];
		}

		$rows = [];
		foreach ( $attributes as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$name = trim( (string) ( $row['name'] ?? '' ) );
			if ( '' === $name ) {
				continue;
			}
			$values = [];
			foreach ( (array) ( $row['values'] ?? [] ) as $pid => $value ) {
				$values[ (int) $pid ] = (string) $value;
			}
			$rows[] = [
				'name'   => $name,
				'values' => $values,
			];
		}

		return $rows;
	}

	/**
	 * Reduce a product summary/detail array to the card fields the widget uses.
	 */
	private function normalize_card( array $p ): array {
		if ( empty( $p['id'] ) || empty( $p['name'] ) ) {
			return [];
		}

		$card = [
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
			// Variations (issue #12): defaults for the common (non-variable) case so
			// every card has a stable shape. Overwritten below when the product is a
			// variable product that actually has selectable variations.
			'is_variable'       => false,
		];

		// Carry a COMPACT variations list for variable products so the widget can
		// render an option selector and add the chosen variation. Only attach it
		// when there is at least one well-formed variation, a "variable" product
		// with no selectable options renders like a plain card (is_variable false).
		$variations = $this->normalize_card_variations( $p['variations'] ?? [] );
		if ( ! empty( $variations ) ) {
			$card['is_variable'] = true;
			$card['variations']  = $variations;
		}

		return $card;
	}

	/**
	 * Reduce a product's variation list (from get_product_details) to the compact
	 * shape the widget renders: variation_id, label, price, in_stock. Entries
	 * without a usable id or label are dropped so the widget never offers an option
	 * it cannot add to the cart.
	 *
	 * @param mixed $variations The raw `variations` value from the tool result.
	 * @return array<int, array{variation_id:int, label:string, price:string, in_stock:bool}>
	 */
	private function normalize_card_variations( $variations ): array {
		if ( ! is_array( $variations ) ) {
			return [];
		}

		$out = [];
		foreach ( $variations as $v ) {
			if ( ! is_array( $v ) ) {
				continue;
			}
			$id    = (int) ( $v['variation_id'] ?? 0 );
			$label = trim( (string) ( $v['label'] ?? '' ) );
			if ( $id <= 0 || '' === $label ) {
				continue;
			}
			$out[] = [
				'variation_id' => $id,
				'label'        => $label,
				'price'        => (string) ( $v['price'] ?? '' ),
				'in_stock'     => ! isset( $v['in_stock'] ) || (bool) $v['in_stock'],
			];
		}

		return $out;
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

			// Simple string content, sanitize it.
			if ( isset( $msg['content'] ) && is_string( $msg['content'] ) ) {
				$out[] = [
					'role'    => $msg['role'],
					'content' => sanitize_textarea_field( $msg['content'] ),
				];
				continue;
			}

			// Structured content (tool_use blocks, tool_result blocks, tool messages)
			// comes from our own server responses, pass through as-is.
			$out[] = $msg;
		}

		return $out;
	}

	// =========================================================================
	// OpenAI-compatible SSE streaming
	// =========================================================================

	/**
	 * The provider id to stream with: the configured provider if it is an
	 * 'openai'-type preset, otherwise fall back to the moonshot preset (the original
	 * streaming provider). The native Anthropic path does not stream; the widget only
	 * calls /stream for openai-type providers (the bootstrap localizes a `streaming`
	 * flag), so this is a defensive default, not the routing decision itself.
	 */
	private function stream_provider(): string {
		$configured = (string) get_option( 'fahad_ai_provider', 'anthropic' );
		return Fahad_AI_Providers::is_openai( $configured ) ? $configured : 'moonshot';
	}

	/**
	 * REST callback for POST /wp-json/fahad-ai/v1/stream
	 * Bypasses WordPress response buffering and pipes SSE directly to the browser.
	 */
	public function handle_stream( WP_REST_Request $request ): void {
		// @codeCoverageIgnoreStart
		// Reason: ends in exit() and tears down every output-buffer level + sends raw SSE headers; exercised end-to-end by the forked-child tests, but pcntl-fork pcov data never returns to the parent collector and it cannot run un-forked without killing PHPUnit, so the body is unmeasurable in-process.
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

		// Load the WC cart and force the guest session cookie out BEFORE any SSE
		// headers/output are flushed. Once the event-stream headers are sent the
		// response is committed, so WooCommerce can no longer emit its Set-Cookie , 
		// guest carts mutated mid-stream would then be lost on the next request
		// (live-QA finding #31). Must run before the header() calls below.
		$this->prime_cart_session();

		// SSE headers, X-Accel-Buffering disables nginx buffering.
		header( 'Content-Type: text/event-stream' );
		header( 'Cache-Control: no-cache' );
		header( 'X-Accel-Buffering: no' );
		header( 'Connection: keep-alive' );

		// Release session lock so other requests aren't blocked.
		if ( session_status() === PHP_SESSION_ACTIVE ) {
			session_write_close();
		}

		$this->run_stream_agent( $sanitized, $this->stream_provider() );

		exit;
		// @codeCoverageIgnoreEnd
	}

	/**
	 * Load the WooCommerce cart and emit the guest session cookie eagerly.
	 *
	 * The streaming endpoint flushes SSE headers and then keeps the connection
	 * open, so WooCommerce never gets its usual shutdown opportunity to send the
	 * session Set-Cookie header before output begins. Without the cookie a guest's
	 * cart mutations (e.g. add_to_cart) are written to session storage under one
	 * id but the browser is handed none, so the following request reads a fresh,
	 * empty session. Priming here, before any headers/output, keeps guest carts
	 * persistent across the stream (live-QA finding #31). Emits only a cookie
	 * header; nothing is echoed, so it is safe to call before the SSE headers.
	 */
	private function prime_cart_session(): void {
		if ( function_exists( 'wc_load_cart' ) ) {
			wc_load_cart();
		}

		if ( function_exists( 'WC' ) && WC()->session && ! headers_sent() ) {
			WC()->session->set_customer_session_cookie( true );
		}
	}

	/**
	 * Multi-turn streaming agent loop for an OpenAI-compatible provider.
	 * Each turn streams text chunks live; tool calls are collected, executed, then the next turn streams.
	 *
	 * @param array  $messages Sanitized conversation messages.
	 * @param string $provider Catalog provider id (an 'openai'-type preset). Defaults
	 *                         to 'moonshot' for backward compatibility.
	 */
	private function run_stream_agent( array $messages, string $provider = 'moonshot' ): void {
		$tools         = Fahad_AI_Tools::instance();
		$max           = 8;
		$sent_products = false;
		// Product ids already streamed this turn, so a product surfaced by more than
		// one tool call (e.g. search then get_product_details) is shown once (bug #97).
		$sent_ids      = [];
		// Owner analytics (#49): accumulate the tool names this streamed turn calls and
		// whether anything was added to cart, so the terminal points can record one
		// privacy-safe event. The SSE bytes the shopper sees are unaffected.
		$tools_called  = [];
		$added_to_cart = false;

		// OpenAI-compatible APIs need the system prompt as the first message.
		$api_msgs = array_merge(
			[ [ 'role' => 'system', 'content' => $this->get_system_prompt() ] ],
			$messages
		);

		for ( $i = 0; $i < $max; $i++ ) {
			// Cost/latency: bound the outgoing context to the configured token budget
			// (no-op by default); the system message + latest turn + in-progress tool
			// loop survive. The SSE products/comparison events below still carry FULL
			// data, only the model copy in $api_msgs is trimmed (issue #23).
			[ $text, $tool_calls, $error ] = $this->stream_one_turn( $this->apply_token_budget( $api_msgs ), $provider, $i );

			if ( $error ) {
				// Graceful degradation (issue #58). A stream error (5xx/429/timeout/
				// transport failure) must NOT leave the shopper at a dead end with a
				// raw error event. Emit the friendly degraded message as a chunk +
				// done, exactly like the graceful-exhaustion path (finding #28), so the
				// widget shows a useful, honest handoff instead of a hard failure. The
				// raw $error string (which may carry provider/exception detail) is never
				// sent to the client. NOTE: mid-stream provider switching is out of
				// scope for #58, once bytes are flowing we cannot transparently swap
				// providers, so we degrade rather than fail over here.
				$this->record_turn_analytics(
					$messages,
					[ 'products' => $sent_products ? [ 1 ] : [], 'messages' => $api_msgs ],
					Fahad_AI_Analytics::OUTCOME_ERROR,
					[ 'tools' => $tools_called, 'added_to_cart' => $added_to_cart ]
				);
				$this->sse_send( 'chunk', [ 'content' => $this->degraded_response()['message'] ] );
				$this->sse_send( 'done', [] );
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
				// Final turn, signal completion. Record the resolved turn for owner
				// analytics (#49) before the connection closes.
				$this->record_turn_analytics(
					$messages,
					[ 'products' => $sent_products ? [ 1 ] : [], 'messages' => $api_msgs ],
					'',
					[ 'tools' => $tools_called, 'added_to_cart' => $added_to_cart ]
				);
				$this->sse_send( 'done', [] );
				return;
			}

			// Execute each tool and append results.
			foreach ( $tool_calls as $tc ) {
				$this->sse_send( 'tool', [ 'name' => $tc['name'] ] );
				$tools_called[] = $tc['name'];
				if ( 'add_to_cart' === $tc['name'] ) {
					$added_to_cart = true;
				}

				// Sequence per tool call: execute → surface cards/comparison from the
				// FULL result (the SSE events carry FULL data) → TRIM → append the
				// trimmed copy to the model messages (issue #23).
				$result = $tools->execute( $tc['name'], $tc['input'] );

				$cards = $this->dedupe_cards( $this->tool_result_cards( $tc['name'], $result ), $sent_ids );
				if ( ! empty( $cards ) ) {
					$this->sse_send( 'products', [ 'products' => $cards ] );
					$sent_products = true;
				}

				// Comparison table (issue #13): surfaced as its own SSE event,
				// mirroring the `products` event above. A comparison-shaped result
				// emits no cards (see tool_result_cards), so the two are mutually
				// exclusive, the widget renders the comparison table here.
				$comparison = $this->tool_result_comparison( $tc['name'], $result );
				if ( ! empty( $comparison ) ) {
					$this->sse_send( 'comparison', $comparison );
				}

				$api_msgs[] = [
					'role'         => 'tool',
					'tool_call_id' => $tc['id'],
					'content'      => wp_json_encode( $this->trim_tool_result( $tc['name'], $result ) ),
				];
			}
		}

		// Graceful exhaustion (finding #28): stream a friendly message + done instead of
		// a raw error event, keeping any product cards already streamed above. The loop
		// never produced a final answer within the budget, record it as a no-tool-match
		// "couldn't complete" turn for owner analytics (#49).
		$this->record_turn_analytics(
			$messages,
			[ 'products' => $sent_products ? [ 1 ] : [], 'messages' => $api_msgs ],
			Fahad_AI_Analytics::OUTCOME_NO_TOOL_MATCH,
			[ 'tools' => $tools_called, 'added_to_cart' => $added_to_cart ]
		);
		$this->sse_send( 'chunk', [ 'content' => $this->agent_fallback_message( $sent_products ) ] );
		$this->sse_send( 'done', [] );
	}

	/**
	 * One turn for an OpenAI-compatible provider on the streaming endpoint.
	 *
	 * Uses the WordPress HTTP API (via call_openai → wp_remote_post) rather than a
	 * raw cURL handle (WordPress.org guideline). The upstream model call is buffered,
	 * then the assistant text is emitted to the client as an SSE chunk, so the
	 * /stream endpoint's client protocol (chunk + tool + products + done) is preserved.
	 * Works for ANY 'openai'-type provider (Moonshot, OpenAI, Gemini, …).
	 *
	 * @param array  $messages  OpenAI-shaped messages (system first).
	 * @param string $provider  Catalog provider id (an 'openai'-type preset).
	 * @param int    $iteration Agent-loop index (passed to the model-routing seam).
	 * @return array{0: string, 1: array, 2: string|null} [text, tool_calls, error]
	 */
	private function stream_one_turn( array $messages, string $provider = 'moonshot', int $iteration = 0 ): array {
		// WordPress.org guideline: use the HTTP API, not our own cURL. The upstream
		// model call is made through call_openai() (wp_remote_post) and buffered, then
		// the assistant text is emitted to the client as a single SSE chunk. This keeps
		// the streaming endpoint's client protocol (chunk + tool + products + done)
		// intact while removing the dedicated cURL handle the reviewer flagged.
		$response = $this->call_openai( $messages, $provider, $iteration );

		if ( is_wp_error( $response ) ) {
			return [ '', [], $response->get_error_message() ];
		}

		$message = $response['choices'][0]['message'] ?? [];
		$text    = (string) ( $message['content'] ?? '' );

		$tool_calls = [];
		foreach ( $message['tool_calls'] ?? [] as $call ) {
			$tool_calls[] = [
				'id'    => $call['id'] ?? '',
				'name'  => $call['function']['name'] ?? '',
				'input' => json_decode( $call['function']['arguments'] ?? '', true ) ?? [],
			];
		}

		// Surface the assistant text (buffered) so the widget renders it. Humanize it
		// (strip em/en dashes, #130) before streaming, the full text is buffered here,
		// so there is no split-dash risk. The returned $text (used for the agent loop /
		// history) is left as the model wrote it.
		if ( '' !== $text ) {
			$this->sse_send( 'chunk', [ 'content' => $this->humanize_text( $text ) ] );
		}

		return [ $text, $tool_calls, null ];
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
