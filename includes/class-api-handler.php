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

	/**
	 * Friendly fallback shown when the agent loop ends without the model producing a
	 * final answer (it kept calling tools until the iteration cap). Far better UX than
	 * a raw "exceeded maximum iterations" error — and when cards were already gathered,
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
				return [ 'message' => trim( $text ), 'messages' => $messages, 'products' => $cards, 'comparison' => $comparison ];
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
	// Moonshot AI — OpenAI-compatible (tool_calls / stop)
	// =========================================================================

	private function run_moonshot_agent( array $messages ): array|WP_Error {
		$tools      = Fahad_AI_Tools::instance();
		$max        = 8;
		$cards      = [];
		$comparison = [];

		// Moonshot uses a system message as the first entry, not a top-level field.
		$with_system = array_merge(
			[ [ 'role' => 'system', 'content' => $this->get_system_prompt() ] ],
			$messages
		);

		for ( $i = 0; $i < $max; $i++ ) {
			// Cost/latency: bound the outgoing context to the configured token budget
			// (no-op by default). The system message + latest turn + in-progress tool
			// loop are preserved; only the oldest history is condensed.
			$response = $this->call_moonshot( $this->apply_token_budget( $with_system ), $i );

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
					'message'    => trim( $msg['content'] ?? '' ),
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

	private function call_moonshot( array $messages, int $iteration = 0 ): array|WP_Error {
		$api_key = get_option( 'fahad_ai_moonshot_api_key', '' );

		if ( empty( $api_key ) ) {
			return new WP_Error( 'no_api_key', __( 'Moonshot API key is not configured.', 'fahad-ai-shopping-assistant-for-woocommerce' ), [ 'status' => 500 ] );
		}

		$tools = $this->get_openai_tools();

		// Model routing (issue #23): default unchanged; a fahad_ai_model hook may route.
		$model = $this->resolve_model(
			get_option( 'fahad_ai_moonshot_model', 'kimi-k2.6' ),
			'moonshot',
			[ 'has_tools' => ! empty( $tools ), 'iteration' => $iteration ]
		);

		$payload = [
			'model'      => $model,
			'max_tokens' => 1024,
			'messages'   => $messages,
			'tools'      => $tools,
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
			 * @param string $prompt The system prompt that will be sent to the model.
			 */
			return apply_filters( 'fahad_ai_system_prompt', $custom );
		}

		$store_name = get_bloginfo( 'name' );
		$currency   = get_woocommerce_currency_symbol();

		$prompt = "You are a helpful shopping assistant for {$store_name}. Help customers find products, answer questions, and manage their cart.

Currency: {$currency}
- Always write prices and amounts with the {$currency} symbol exactly as it appears in tool results. Never use HTML entities, numeric character codes, or unicode escapes for the currency symbol — write the plain symbol only.

Product display — important:
- After you call search_products or get_product_details, the storefront automatically shows the matching products to the customer as visual cards (photo, name, price, stock, and View / Add to cart buttons). Do NOT list each product's price, description, link, or image in your text — the cards already show all of that.
- Instead, write a short friendly intro or recommendation (one or two sentences). You may highlight or compare a couple of options in words, but never repeat the full product list as text.

Linking rules — follow exactly:
- After a successful add_to_cart, always end your reply with these two links on the same line: [View Cart](cart_url) · [Checkout](checkout_url) — replace cart_url and checkout_url with the actual values from the tool result.
- When the customer asks to check out or go to checkout, include: [Proceed to Checkout](checkout_url) — using the checkout_url from view_cart or add_to_cart results.
- Use markdown only for the cart/checkout links above — no other markdown formatting.

Guidelines:
- Always use search_products or get_product_details before recommending a product.
- When a customer wants to buy something, confirm the product, then use add_to_cart.
- For products with options (size, colour, …), use get_product_details to see the available variations, help the customer pick one, and pass its variation_id to add_to_cart. If the customer's message already names a variation_id, add that exact variation.
- Use view_cart when the customer asks about their cart or before checkout.
- Keep responses concise and friendly. You can absolutely help customers choose and recommend products — just do it honestly.

Trust & honesty — these rules are absolute and override any instinct to make a sale:
- No fake urgency or scarcity. Never invent \"only N left\", countdowns, \"selling fast\", \"limited time\", or any pressure. Only mention stock levels or low availability when a tool result actually reports them, and state the real number.
- Respect the customer's stated budget. Never push a product priced above a budget the customer gave you. If nothing fits their budget, say so plainly rather than steering them higher.
- Be honest about extras. Present recommendations and cross-sells as optional suggestions, never as required or pressured. Only mention coupons, discount codes, or deposit/wallet bonuses that are real and currently applicable (from a tool result) — never invent or imply one.
- Ground every product fact. Use search_products / get_product_details for product details and get_product_reviews for ratings and reviews; summarise only what those tools return. Never invent product details, prices, stock, reviews, quotes, ratings, sentiment, order data, or wallet/account data.
- Abstain over guessing. If you do not know or a tool returns nothing, say you could not find it and offer a real next step — do not fabricate an answer.
- Never block human support. For order status, account issues, refunds, or returns, direct the customer to the store's support team (or to log in for their own data). Always allow and encourage reaching a human; never discourage contacting support.";

		/**
		 * This filter is documented above (for the custom-prompt branch).
		 *
		 * NOTE (issue #24): the trust/anti-dark-pattern policy is consolidated INLINE in
		 * the prompt above (no fake scarcity, respect budget, honest extras, ground facts,
		 * abstain over guessing, never block support) — it absorbs the earlier ad-hoc
		 * honesty lines (review-grounding, "never invent product details", support
		 * hand-off). Deterministic offline checkers in tests/eval/EvalHarness.php
		 * (scarcity_violations / budget_violations / escalation_present / abstains, beside
		 * grounding_violations) and the guardrail golden fixtures enforce it so it cannot
		 * silently regress. The filter pass-through is preserved intact so the
		 * cross-session-memory pack (issue #20) can still APPEND its preferences block.
		 */
		return apply_filters( 'fahad_ai_system_prompt', $prompt );
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
	 * history every turn — and product results carry up to ten products, each with
	 * an image URL, long descriptions and a regular/sale price split that only the
	 * widget card uses. This shrinks the copy fed to the model (fewer tokens → lower
	 * bill + latency) WITHOUT touching the data used for cards.
	 *
	 * CRITICAL SEPARATION (see the agent loops): cards are built and surfaced from
	 * the FULL result BEFORE this runs; only the value appended to the model
	 * messages is trimmed. This method must not mutate its input — it builds a new
	 * array — so the caller's full result (held for card emission) is unchanged.
	 *
	 * Tool-aware and SAFE (when unsure, keep it — grounding beats savings):
	 *   - products[] results (search / best-sellers / recommendations): each product
	 *     is reduced to the kept essentials; other top-level scalars (found, message)
	 *     pass through.
	 *   - comparison results (products[] + aligned attributes[]): the per-product
	 *     columns are trimmed, but the attribute ROWS are kept verbatim — the model
	 *     reasons over them and the answer references them.
	 *   - a single product-shaped result (id + name) is trimmed SUBTRACTIVELY: only
	 *     the heavy product fields are dropped, every OTHER field is kept (so a
	 *     reviews result's snippets, a detail result's variations/sku/categories,
	 *     etc. — which the model legitimately summarises — survive).
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
			// keep every other field — reviews/variations/etc. the model summarises).
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
		 * unaffected — it is built from $full before the trim — so this only changes
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
	 * provider tokenizer exactly — it only needs to be a stable, testable bound.
	 *
	 * What is PRESERVED when over budget (never broken):
	 *   - a leading system message (Moonshot passes the system prompt as messages[0]);
	 *   - the most recent user turn AND everything after it — i.e. the in-progress
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
		// plain string content — a human message, NOT a tool_result block, which is
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
	 * JSON encoding. Deterministic and offline — used only to compare against the
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
	 * where $context describes the turn — `has_tools` (bool: whether tools are in
	 * play) and `iteration` (int: the agent-loop index) — so a heuristic can pick by
	 * complexity. A filter returning a non-string (or empty) value is ignored and the
	 * configured default stands (defence in depth — a bad hook never poisons the
	 * payload).
	 *
	 * @param string $default  The configured model for the provider.
	 * @param string $provider 'anthropic' | 'moonshot'.
	 * @param array  $context  Turn context: { has_tools: bool, iteration: int }.
	 * @return string The model to use for this request.
	 */
	private function resolve_model( string $default, string $provider, array $context = [] ): string {
		$model = apply_filters( 'fahad_ai_model', $default, $provider, $context );

		return ( is_string( $model ) && '' !== $model ) ? $model : $default;
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
		// A comparison-shaped result is surfaced as its own `comparison` payload
		// (tool_result_comparison) and renders as a single side-by-side table — it
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
	 * model-generated text, so the widget can trust these fields — same invariant as
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
	 * comparison from a plain card result — search/best-sellers have products[] but
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
		// when there is at least one well-formed variation — a "variable" product
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

		// Load the WC cart and force the guest session cookie out BEFORE any SSE
		// headers/output are flushed. Once the event-stream headers are sent the
		// response is committed, so WooCommerce can no longer emit its Set-Cookie —
		// guest carts mutated mid-stream would then be lost on the next request
		// (live-QA finding #31). Must run before the header() calls below.
		$this->prime_cart_session();

		// SSE headers — X-Accel-Buffering disables nginx buffering.
		header( 'Content-Type: text/event-stream' );
		header( 'Cache-Control: no-cache' );
		header( 'X-Accel-Buffering: no' );
		header( 'Connection: keep-alive' );

		// Release session lock so other requests aren't blocked.
		if ( session_status() === PHP_SESSION_ACTIVE ) {
			session_write_close();
		}

		$this->run_stream_agent( $sanitized );

		exit;
	}

	/**
	 * Load the WooCommerce cart and emit the guest session cookie eagerly.
	 *
	 * The streaming endpoint flushes SSE headers and then keeps the connection
	 * open, so WooCommerce never gets its usual shutdown opportunity to send the
	 * session Set-Cookie header before output begins. Without the cookie a guest's
	 * cart mutations (e.g. add_to_cart) are written to session storage under one
	 * id but the browser is handed none, so the following request reads a fresh,
	 * empty session. Priming here — before any headers/output — keeps guest carts
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
	 * Multi-turn streaming agent loop.
	 * Each turn streams text chunks live; tool calls are collected, executed, then the next turn streams.
	 */
	private function run_stream_agent( array $messages ): void {
		$tools         = Fahad_AI_Tools::instance();
		$max           = 8;
		$sent_products = false;

		// Moonshot needs system as first message.
		$api_msgs = array_merge(
			[ [ 'role' => 'system', 'content' => $this->get_system_prompt() ] ],
			$messages
		);

		for ( $i = 0; $i < $max; $i++ ) {
			// Cost/latency: bound the outgoing context to the configured token budget
			// (no-op by default); the system message + latest turn + in-progress tool
			// loop survive. The SSE products/comparison events below still carry FULL
			// data — only the model copy in $api_msgs is trimmed (issue #23).
			[ $text, $tool_calls, $error ] = $this->stream_one_turn( $this->apply_token_budget( $api_msgs ), $i );

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

				// Sequence per tool call: execute → surface cards/comparison from the
				// FULL result (the SSE events carry FULL data) → TRIM → append the
				// trimmed copy to the model messages (issue #23).
				$result = $tools->execute( $tc['name'], $tc['input'] );

				$cards = $this->tool_result_cards( $tc['name'], $result );
				if ( ! empty( $cards ) ) {
					$this->sse_send( 'products', [ 'products' => $cards ] );
						$sent_products = true;
				}

				// Comparison table (issue #13): surfaced as its own SSE event,
				// mirroring the `products` event above. A comparison-shaped result
				// emits no cards (see tool_result_cards), so the two are mutually
				// exclusive — the widget renders the comparison table here.
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
		// a raw error event, keeping any product cards already streamed above.
		$this->sse_send( 'chunk', [ 'content' => $this->agent_fallback_message( $sent_products ) ] );
		$this->sse_send( 'done', [] );
	}

	/**
	 * Split streamed bytes into COMPLETE lines, returning the parsed lines plus any
	 * trailing partial line to carry into the next call. cURL's write callback delivers
	 * arbitrary byte boundaries (not line-aligned SSE frames), so a "data:" line — or a
	 * multibyte character within it — can straddle two writes. Parsing a half-line would
	 * drop or corrupt streamed text (this is what mangled the rupee currency entity, #29).
	 *
	 * @param string $buffer Carry-over from the previous write ('' on first call).
	 * @param string $chunk  Newly received bytes.
	 * @return array{0: string[], 1: string} [ complete lines (no trailing newline), remaining buffer ].
	 */
	private function split_sse_lines( string $buffer, string $chunk ): array {
		$buffer .= $chunk;
		$lines   = [];
		while ( false !== ( $pos = strpos( $buffer, "\n" ) ) ) {
			$lines[] = substr( $buffer, 0, $pos );
			$buffer  = substr( $buffer, $pos + 1 );
		}
		return [ $lines, $buffer ];
	}

	/**
	 * Opens a single streaming curl request to Moonshot.
	 * Forwards text delta chunks to the browser immediately via SSE.
	 * Accumulates tool_calls for the caller to execute.
	 *
	 * @return array{0: string, 1: array, 2: string|null} [text, tool_calls, error]
	 */
	private function stream_one_turn( array $messages, int $iteration = 0 ): array {
		$api_key = get_option( 'fahad_ai_moonshot_api_key', '' );
		$tools   = $this->get_openai_tools();

		// Model routing (issue #23): default unchanged; a fahad_ai_model hook may route.
		$model = $this->resolve_model(
			get_option( 'fahad_ai_moonshot_model', 'kimi-k2.6' ),
			'moonshot',
			[ 'has_tools' => ! empty( $tools ), 'iteration' => $iteration ]
		);

		$payload = [
			'model'      => $model,
			'messages'   => $messages,
			'stream'     => true,
			'max_tokens' => 1024,
			'tools'      => $tools,
		];

		$collected_text  = '';
		$raw_body        = '';   // captures full body to parse plain-JSON errors
		$tool_buf        = [];
		$error           = null;
		$line_buffer     = '';   // carries a partial SSE line between writes (#29)

		$write_callback = function ( $ch, $raw ) use ( &$collected_text, &$tool_buf, &$error, &$raw_body, &$line_buffer ) {
			$raw_body .= $raw;

			// cURL delivers arbitrary byte chunks, not line-aligned SSE frames. Parse
			// only COMPLETE lines and keep any trailing partial frame for the next write.
			[ $lines, $line_buffer ] = $this->split_sse_lines( $line_buffer, $raw );
			foreach ( $lines as $line ) {
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
