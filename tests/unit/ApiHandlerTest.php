<?php
/**
 * Unit tests for Fahad_AI_API_Handler.
 *
 * Covers:
 *  - sanitize_messages()  — input validation & sanitization
 *  - tool_specs()         — contract/structure of tool definitions
 */

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;

class ApiHandlerTest extends TestCase {

    use MockeryPHPUnitIntegration;

    /**
     * Snapshot of the registry's static first-party pack providers, restored in
     * tearDown. The tool_specs() contract tests below assert on the BARE built-in
     * set (exactly five tools). Feature packs (the catalog pack, …) self-register
     * into that static list at file load, so we clear it for the duration of each
     * test — proving the built-in contract independent of however many packs ship —
     * and restore it afterwards so other suites keep their packs.
     *
     * @var array<int, callable>
     */
    private array $pack_snapshot = [];

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();

        $this->pack_snapshot = (array) ( new ReflectionProperty( Fahad_AI_Tool_Registry::class, 'pack_providers' ) )->getValue();
        Fahad_AI_Tool_Registry::reset_packs();
        // Clear any registry list cached by a previous test so the next build sees
        // the now-empty pack list (the built-in contract, not packs).
        ( new ReflectionProperty( Fahad_AI_Tool_Registry::class, 'instance' ) )->setValue( null, null );

        // Note: sanitize_textarea_field is intentionally NOT stubbed here.
        // Tests that need pass-through behaviour add it individually;
        // test_string_content_is_sanitized uses Functions\expect() to assert the call.
        Functions\stubs( [
            'sanitize_text_field'            => fn( $s ) => $s,
            'get_bloginfo'                   => fn() => 'Test Store',
            'get_woocommerce_currency_symbol' => fn() => '$',
            'get_option'                     => fn( $key, $default = '' ) => $default,
            'get_site_url'                   => fn() => 'http://example.com',
        ] );
    }

    protected function tearDown(): void {
        ( new ReflectionProperty( Fahad_AI_Tool_Registry::class, 'pack_providers' ) )->setValue( null, $this->pack_snapshot );
        Monkey\tearDown();
        parent::tearDown();
    }

    private function handler(): Fahad_AI_API_Handler {
        $ref = new ReflectionProperty( Fahad_AI_API_Handler::class, 'instance' );
        $ref->setValue( null, null );
        return Fahad_AI_API_Handler::instance();
    }

    // ── stream_one_turn uses the WordPress HTTP API, not raw cURL (WP.org guideline) ──
    // The streaming endpoint buffers the upstream model call via call_openai()
    // (wp_remote_post) and emits the assistant text to the client as an SSE chunk.
    // Reaching the stubbed wp_remote_post (rather than a live cURL handle) proves the
    // raw-cURL transport was removed.

    public function test_stream_one_turn_uses_http_api_and_parses_message(): void {
        $this->set_option_alias( [ 'fahad_ai_moonshot_api_key' => 'k', 'fahad_ai_moonshot_model' => 'kimi-k2.6' ] );
        Functions\when( 'apply_filters' )->alias( static fn( $tag, $value = null ) => $value );
        Functions\when( 'wp_json_encode' )->alias( static fn( $d ) => json_encode( $d ) );
        Functions\when( 'wp_remote_post' )->justReturn( [ 'is_eval' => true ] );
        Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 200 );
        Functions\when( 'wp_remote_retrieve_body' )->justReturn(
            json_encode( [
                'choices' => [ [
                    'finish_reason' => 'tool_calls',
                    'message'       => [
                        'content'    => 'Here are some hoodies',
                        'tool_calls' => [ [ 'id' => 't1', 'function' => [ 'name' => 'search_products', 'arguments' => '{"query":"hoodie"}' ] ] ],
                    ],
                ] ],
            ] )
        );

        $m = new ReflectionMethod( Fahad_AI_API_Handler::class, 'stream_one_turn' );
        ob_start();
        [ $text, $tool_calls, $error ] = $m->invoke( $this->handler(), [ [ 'role' => 'user', 'content' => 'hi' ] ], 'moonshot', 0 );
        ob_end_clean();

        $this->assertNull( $error );
        $this->assertSame( 'Here are some hoodies', $text, 'reached wp_remote_post (HTTP API), not a live cURL call' );
        $this->assertSame( 'search_products', $tool_calls[0]['name'] );
        $this->assertSame( [ 'query' => 'hoodie' ], $tool_calls[0]['input'] );
    }

    // ── per-turn product-card de-duplication (bug #97) ──────────────────────────
    // The agent loop can surface the same product from more than one tool call in a
    // single turn (e.g. search_products then get_product_details on the same item),
    // which previously emitted a duplicate `products` SSE event and rendered the card
    // twice. dedupe_cards() drops cards whose product id was already streamed this
    // turn and records the newly-sent ids, so each product appears at most once.

    public function test_dedupe_cards_drops_a_product_already_streamed_this_turn(): void {
        $m = new ReflectionMethod( Fahad_AI_API_Handler::class, 'dedupe_cards' );
        $h = $this->handler();

        $sent = [];

        // First tool call this turn: two distinct products — both are fresh.
        $args1  = [ [ [ 'id' => 14, 'name' => 'Running Sneakers' ], [ 'id' => 10, 'name' => 'White T-Shirt' ] ], &$sent ];
        $fresh1 = $m->invokeArgs( $h, $args1 );
        $this->assertSame( [ 14, 10 ], array_column( $fresh1, 'id' ) );

        // Second tool call in the SAME turn re-surfaces product 14 — it must be dropped.
        $args2  = [ [ [ 'id' => 14, 'name' => 'Running Sneakers' ] ], &$sent ];
        $fresh2 = $m->invokeArgs( $h, $args2 );
        $this->assertSame( [], $fresh2, 'A product already streamed this turn must not be re-emitted.' );
    }

    public function test_dedupe_cards_keeps_unseen_products_and_records_them(): void {
        $m = new ReflectionMethod( Fahad_AI_API_Handler::class, 'dedupe_cards' );
        $h = $this->handler();

        $sent  = [ 14 => true ];
        $args  = [ [ [ 'id' => 14, 'name' => 'Dup' ], [ 'id' => 13, 'name' => 'Water Bottle' ] ], &$sent ];
        $fresh = $m->invokeArgs( $h, $args );

        $this->assertSame( [ 13 ], array_column( $fresh, 'id' ), 'Only the not-yet-seen product survives.' );
        $this->assertArrayHasKey( 13, $sent, 'The newly-streamed id must be recorded for later dedup.' );
    }

    public function test_dedupe_cards_keeps_cards_without_a_usable_id(): void {
        // Defence in depth: a card with no/zero id cannot be deduped reliably, so it
        // passes through rather than being silently dropped.
        $m = new ReflectionMethod( Fahad_AI_API_Handler::class, 'dedupe_cards' );
        $h = $this->handler();

        $sent  = [];
        $args  = [ [ [ 'name' => 'No id card' ], [ 'id' => 0, 'name' => 'Zero id' ] ], &$sent ];
        $fresh = $m->invokeArgs( $h, $args );

        $this->assertCount( 2, $fresh, 'Cards without a usable id are kept.' );
    }

    // ── system prompt nudges plain currency symbols (live-QA finding #29) ───────
    // The model sometimes emitted a numeric HTML entity for ₨ (and occasionally a
    // malformed one); the prompt now tells it to write the plain symbol instead.

    public function test_system_prompt_forbids_currency_entities(): void {
        Functions\when( 'apply_filters' )->alias( fn( $tag, $value ) => $value );

        $prompt = ( new ReflectionMethod( Fahad_AI_API_Handler::class, 'get_system_prompt' ) )
            ->invoke( $this->handler() );

        $this->assertStringContainsString( 'Never use HTML entities', $prompt );
    }

    // ── always close with a one-line summary, even alongside cards (issue #66) ───
    // Live QA found turns that ended on product cards with NO prose. The prompt
    // previously asked for a "short intro" but turns still came back card-only, so
    // the rule is strengthened to make a one-line intro/summary MANDATORY whenever
    // cards render. This pins that wording so a future prompt edit can't drop it
    // (the client fallback in chatbot.js is the deterministic safety net underneath).

    public function test_system_prompt_requires_a_summary_line_with_cards(): void {
        Functions\when( 'apply_filters' )->alias( static fn( $tag, $value = null ) => $value );

        $prompt = ( new ReflectionMethod( Fahad_AI_API_Handler::class, 'get_system_prompt' ) )
            ->invoke( $this->handler() );

        // The rule must be phrased as an unconditional requirement, not a soft "you
        // may". "Always" + "one line" of text accompanying the cards is the contract.
        $this->assertStringContainsString( 'Always write at least one line', $prompt );
        $this->assertStringContainsString( 'never reply with only cards', $prompt );
    }

    // ── deterministic currency entity normalizer (issue #66) ────────────────────
    // Beyond the #29 prompt nudge, normalize_currency_entities() is a server-side
    // guard on the assistant TEXT (applied on the non-stream return paths) so a
    // numeric currency entity the model still occasionally emits can never reach the
    // browser as a stray glyph. A well-formed entity is decoded to its symbol; a
    // MALFORMED one (e.g. a dropped digit: &#836;, which would decode to the U+0344
    // combining mark) is repaired to the configured currency symbol — never a
    // combining/control character. A raw symbol passes through untouched.

    private function normalize_currency_entities( string $text ): string {
        $method = new ReflectionMethod( Fahad_AI_API_Handler::class, 'normalize_currency_entities' );
        return $method->invoke( $this->handler(), $text );
    }

    public function test_currency_normalizer_passes_through_a_raw_symbol(): void {
        // A plain symbol (the desired output) must be left exactly as-is.
        $this->assertSame(
            'That is ₨1,299 in total.',
            $this->normalize_currency_entities( 'That is ₨1,299 in total.' )
        );
    }

    public function test_currency_normalizer_decodes_a_valid_numeric_entity(): void {
        // &#8360; is the rupee sign (U+20A8) — a well-formed currency entity must be
        // decoded to the plain symbol so the customer never sees the raw entity text.
        $rupee = "\xE2\x82\xA8"; // U+20A8
        $this->assertSame(
            "It costs {$rupee}1,299.",
            $this->normalize_currency_entities( 'It costs &#8360;1,299.' )
        );
    }

    public function test_currency_normalizer_repairs_a_malformed_entity_to_the_symbol(): void {
        // &#836; is a dropped-digit corruption of &#8360;. Decoded naively it becomes
        // U+0344 (COMBINING GREEK DIALYTIKA TONOS) — a combining mark that renders as a
        // stray glyph on the digits after it. The normalizer must repair it to the
        // configured currency symbol ($ in these stubs), NEVER emit U+0344.
        $out = $this->normalize_currency_entities( 'It costs &#836;1,299.' );

        $this->assertSame( 'It costs $1,299.', $out );
        // Hard guarantee: the combining mark must not survive anywhere in the output.
        $this->assertStringNotContainsString( "\xCD\x84", $out, 'U+0344 combining mark leaked into output' );
    }

    // ── humanized replies, no em-dashes (#130) ──────────────────────────────────
    // Replies must read like a person, not a machine, and must never contain an
    // em-dash (—, U+2014) or en-dash (–, U+2013). humanize_text() is a deterministic
    // server-side guard applied to the assistant TEXT on BOTH the non-stream return
    // and the buffered streaming chunk, so a dash the model still emits despite the
    // prompt can never reach the shopper. The em-dash glyph below is U+2014; en-dash
    // is U+2013.

    private function humanize_text( string $text ): string {
        $method = new ReflectionMethod( Fahad_AI_API_Handler::class, 'humanize_text' );
        return $method->invoke( $this->handler(), $text );
    }

    public function test_humanize_replaces_a_spaced_em_dash_with_a_comma(): void {
        $in  = "We have Clothing \xE2\x80\x94 take a look.";          // U+2014
        $this->assertSame( 'We have Clothing, take a look.', $this->humanize_text( $in ) );
    }

    public function test_humanize_replaces_an_en_dash_with_a_comma(): void {
        $in  = "Fast \xE2\x80\x93 reliable shipping.";                 // U+2013
        $this->assertSame( 'Fast, reliable shipping.', $this->humanize_text( $in ) );
    }

    public function test_humanize_keeps_numeric_ranges_as_a_hyphen(): void {
        $in  = "Sizes 30\xE2\x80\x9340 are in stock.";                 // 30–40
        $this->assertSame( 'Sizes 30-40 are in stock.', $this->humanize_text( $in ) );
    }

    public function test_humanize_passes_clean_text_through_unchanged(): void {
        $clean = 'Sure, I can help you find a great pair of shoes today.';
        $this->assertSame( $clean, $this->humanize_text( $clean ) );
    }

    public function test_humanize_leaves_no_em_or_en_dash_in_the_output(): void {
        $in  = "A \xE2\x80\x94 B \xE2\x80\x93 C \xE2\x80\x94 D";
        $out = $this->humanize_text( $in );
        $this->assertStringNotContainsString( "\xE2\x80\x94", $out, 'em-dash leaked' );
        $this->assertStringNotContainsString( "\xE2\x80\x93", $out, 'en-dash leaked' );
    }

    public function test_humanize_is_idempotent(): void {
        $in   = "We have Clothing \xE2\x80\x94 take a look.";
        $once = $this->humanize_text( $in );
        $this->assertSame( $once, $this->humanize_text( $once ) );
    }

    public function test_system_prompt_forbids_em_dashes_and_asks_for_humanized_concise(): void {
        Functions\when( 'apply_filters' )->alias( static fn( $tag, $value = null ) => $value );

        $prompt = ( new ReflectionMethod( Fahad_AI_API_Handler::class, 'get_system_prompt' ) )
            ->invoke( $this->handler() );

        $this->assertStringContainsString( 'Never use em-dashes or en-dashes', $prompt );
        $this->assertStringContainsString( 'concise but complete', $prompt );
    }

    // ── grounded sale/deal answers (#137) ───────────────────────────────────────
    // The model must NEVER claim sale status from memory; sale questions are answered
    // by querying search_products with on_sale:true, so the cards match the claim.

    public function test_system_prompt_grounds_sale_questions_in_a_tool_call(): void {
        Functions\when( 'apply_filters' )->alias( static fn( $tag, $value = null ) => $value );

        $prompt = ( new ReflectionMethod( Fahad_AI_API_Handler::class, 'get_system_prompt' ) )
            ->invoke( $this->handler() );

        $this->assertStringContainsString( 'on_sale', $prompt );
        $this->assertStringContainsStringIgnoringCase( 'never', $prompt );
    }

    // ── wallet-aware shopping context (Epic A / #140) ────────────────────────────
    // Balance/credit questions must be grounded via get_wallet_balance, never stated
    // from memory; logged-out shoppers are asked to sign in.

    public function test_system_prompt_grounds_wallet_balance_in_a_tool_call(): void {
        Functions\when( 'apply_filters' )->alias( static fn( $tag, $value = null ) => $value );

        $prompt = ( new ReflectionMethod( Fahad_AI_API_Handler::class, 'get_system_prompt' ) )
            ->invoke( $this->handler() );

        $this->assertStringContainsString( 'get_wallet_balance', $prompt );
        $this->assertStringContainsStringIgnoringCase( 'signed in', $prompt );
    }

    public function test_currency_normalizer_repairs_hex_malformed_entity(): void {
        // The hex spelling of the same malformed value (&#x344;) must be repaired too —
        // the guard keys off the resulting codepoint, not the decimal/hex notation.
        $out = $this->normalize_currency_entities( 'Total: &#x344;500.' );

        $this->assertSame( 'Total: $500.', $out );
        $this->assertStringNotContainsString( "\xCD\x84", $out );
    }

    public function test_currency_normalizer_leaves_unrelated_text_untouched(): void {
        // No currency entity present → the text is returned verbatim (the guard is
        // narrow: it only touches numeric character references, not ordinary prose).
        $text = 'We have 3 left in stock — a great deal at $49.99.';
        $this->assertSame( $text, $this->normalize_currency_entities( $text ) );
    }

    // ── direct, verified cart REST actions (#48) ────────────────────────────────
    // The card "Add to cart" button calls a dedicated cart endpoint directly (no
    // agent round-trip). handle_cart_action() maps a sanitized action to a built-in
    // cart tool and dispatches it; an unknown action is rejected before any work.

    public function test_cart_action_tool_maps_actions(): void {
        $m = new ReflectionMethod( Fahad_AI_API_Handler::class, 'cart_action_tool' );
        $h = $this->handler();

        $this->assertSame( 'add_to_cart',      $m->invoke( $h, 'add' ) );
        $this->assertSame( 'remove_from_cart', $m->invoke( $h, 'remove' ) );
        $this->assertSame( 'view_cart',        $m->invoke( $h, 'view' ) );
        $this->assertNull( $m->invoke( $h, 'frobnicate' ) );
    }

    public function test_handle_cart_action_rejects_unknown_action(): void {
        Functions\when( 'sanitize_key' )->returnArg();

        $req = Mockery::mock( 'WP_REST_Request' );
        $req->shouldReceive( 'get_param' )->with( 'action' )->andReturn( 'frobnicate' );

        $result = $this->handler()->handle_cart_action( $req );

        $this->assertTrue( is_wp_error( $result ), 'Unknown cart action must return a WP_Error.' );
    }

    // ── reply feedback endpoint (#50) ────────────────────────────────────────────
    // POST fahad-ai/v1/feedback → handle_feedback(): validates the rating, sanitizes
    // the optional reason + opaque conversation/message refs, and delegates to the
    // Fahad_AI_Feedback store (option-backed, telemetry-only, NO PII). A junk rating
    // is rejected with a 400 before anything is stored; a valid rating is recorded
    // and the store's id is echoed back so the client can reflect the chosen state.

    /**
     * Back handle_feedback's Fahad_AI_Feedback store with the in-memory option map so
     * these endpoint tests assert what was persisted end-to-end (the handler is on a
     * `final` class, so we drive the real store rather than mocking it).
     */
    private function feedback_option_seam(): void {
        Functions\when( 'get_option' )->alias( fn( $name, $default = false ) => $this->options[ $name ] ?? $default );
        Functions\when( 'update_option' )->alias( function ( $name, $value ) {
            $this->options[ $name ] = $value;
            return true;
        } );
    }

    /** In-memory WP options stand-in for the feedback endpoint tests. */
    private array $options = [];

    private function feedback_request( $rating, string $reason = '', string $conv = '', string $msg = '' ) {
        $req = Mockery::mock( 'WP_REST_Request' );
        $req->shouldReceive( 'get_param' )->with( 'rating' )->andReturn( $rating );
        $req->shouldReceive( 'get_param' )->with( 'reason' )->andReturn( $reason );
        $req->shouldReceive( 'get_param' )->with( 'conversation_ref' )->andReturn( $conv );
        $req->shouldReceive( 'get_param' )->with( 'message_ref' )->andReturn( $msg );
        return $req;
    }

    public function test_handle_feedback_rejects_an_invalid_rating(): void {
        Functions\when( 'sanitize_key' )->returnArg();
        Functions\when( 'sanitize_text_field' )->returnArg();
        Functions\when( 'sanitize_textarea_field' )->returnArg();
        $this->options = [];
        $this->feedback_option_seam();

        $result = $this->handler()->handle_feedback( $this->feedback_request( 'sideways', '', 'c1', 'm1' ) );

        $this->assertTrue( is_wp_error( $result ), 'A junk rating must return a WP_Error.' );
        // Nothing persisted for a bad rating.
        $this->assertSame( [], $this->options[ Fahad_AI_Feedback::OPTION ] ?? [] );
    }

    public function test_handle_feedback_stores_a_valid_rating_and_returns_ok(): void {
        Functions\when( 'sanitize_key' )->returnArg();
        Functions\when( 'sanitize_text_field' )->returnArg();
        Functions\when( 'sanitize_textarea_field' )->returnArg();
        Functions\when( 'wp_generate_uuid4' )->justReturn( 'uuid-fixed' );
        $this->options = [];
        $this->feedback_option_seam();
        $this->stub_rest_ensure_response();
        // Reset the store singleton so it reads our seam, not a prior test's.
        ( new ReflectionProperty( Fahad_AI_Feedback::class, 'instance' ) )->setValue( null, null );

        $result = $this->handler()->handle_feedback( $this->feedback_request( 'down', 'wrong answer', 'conv-1', 'msg-1' ) );
        $data   = $this->response_data( $result );

        $this->assertTrue( $data['ok'] ?? false, 'A valid rating must be accepted.' );
        $rows = $this->options[ Fahad_AI_Feedback::OPTION ] ?? [];
        $this->assertCount( 1, $rows, 'Exactly one feedback row must be persisted.' );
        $this->assertSame( 'down', reset( $rows )['rating'] );
    }

    // ── sanitize_messages ─────────────────────────────────────────────────────

    public function test_user_role_is_allowed(): void {
        Functions\when( 'sanitize_textarea_field' )->returnArg();

        $result = $this->handler()->sanitize_messages( [
            [ 'role' => 'user', 'content' => 'Hello' ],
        ] );

        $this->assertCount( 1, $result );
        $this->assertSame( 'user', $result[0]['role'] );
    }

    public function test_assistant_role_is_allowed(): void {
        Functions\when( 'sanitize_textarea_field' )->returnArg();

        $result = $this->handler()->sanitize_messages( [
            [ 'role' => 'assistant', 'content' => 'Hi there' ],
        ] );

        $this->assertCount( 1, $result );
        $this->assertSame( 'assistant', $result[0]['role'] );
    }

    public function test_tool_role_is_allowed_for_moonshot(): void {
        Functions\when( 'sanitize_textarea_field' )->returnArg();

        $result = $this->handler()->sanitize_messages( [
            [ 'role' => 'tool', 'tool_call_id' => 'abc', 'content' => '{"found":1}' ],
        ] );

        $this->assertCount( 1, $result );
        $this->assertSame( 'tool', $result[0]['role'] );
    }

    public function test_invalid_role_is_stripped(): void {
        Functions\when( 'sanitize_textarea_field' )->returnArg();

        $result = $this->handler()->sanitize_messages( [
            [ 'role' => 'system',    'content' => 'Injected system prompt' ],
            [ 'role' => 'malicious', 'content' => 'Bad actor' ],
            [ 'role' => 'user',      'content' => 'Legit message' ],
        ] );

        $this->assertCount( 1, $result );
        $this->assertSame( 'user', $result[0]['role'] );
    }

    public function test_message_without_role_key_is_skipped(): void {
        Functions\when( 'sanitize_textarea_field' )->returnArg();

        $result = $this->handler()->sanitize_messages( [
            [ 'content' => 'No role here' ],
            [ 'role' => 'user', 'content' => 'Has role' ],
        ] );

        $this->assertCount( 1, $result );
    }

    public function test_string_content_is_sanitized(): void {
        // Verify that sanitize_textarea_field IS called on string content.
        // Functions\expect() registers a Mockery expectation; PHPUnit/Mockery will
        // fail the test if the function is not called exactly once with the given argument.
        Functions\expect( 'sanitize_textarea_field' )
            ->once()
            ->with( 'Hello <script>alert(1)</script>' )
            ->andReturn( 'Hello ' );

        $result = $this->handler()->sanitize_messages( [
            [ 'role' => 'user', 'content' => 'Hello <script>alert(1)</script>' ],
        ] );

        $this->assertSame( 'Hello ', $result[0]['content'] );
    }

    public function test_array_content_passes_through_unchanged(): void {
        // Tool-call content blocks come from our own server — must not be mangled.
        // Array content never goes through sanitize_textarea_field, so no stub needed.
        $blocks = [
            [ 'type' => 'tool_use', 'id' => 'abc', 'name' => 'search_products', 'input' => [] ],
        ];

        $result = $this->handler()->sanitize_messages( [
            [ 'role' => 'assistant', 'content' => $blocks ],
        ] );

        $this->assertSame( $blocks, $result[0]['content'] );
    }

    public function test_empty_array_returns_empty(): void {
        $result = $this->handler()->sanitize_messages( [] );
        $this->assertSame( [], $result );
    }

    public function test_mixed_valid_and_invalid_messages(): void {
        Functions\when( 'sanitize_textarea_field' )->returnArg();

        $input = [
            [ 'role' => 'user',      'content' => 'msg 1' ],
            [ 'role' => 'admin',     'content' => 'bad'   ],
            [ 'role' => 'assistant', 'content' => 'msg 2' ],
            [ 'no_role_key'          => 'also bad'        ],
            [ 'role' => 'tool',      'content' => '{}'    ],
        ];

        $result = $this->handler()->sanitize_messages( $input );

        $this->assertCount( 3, $result );
        $this->assertSame( 'user',      $result[0]['role'] );
        $this->assertSame( 'assistant', $result[1]['role'] );
        $this->assertSame( 'tool',      $result[2]['role'] );
    }

    // ── tool_specs() contract ─────────────────────────────────────────────────

    public function test_exactly_five_tools_are_defined(): void {
        $specs = $this->handler()->tool_specs();
        $this->assertCount( 5, $specs );
    }

    public function test_every_tool_has_name_description_and_parameters(): void {
        foreach ( $this->handler()->tool_specs() as $spec ) {
            $this->assertArrayHasKey( 'name',        $spec, "Tool missing 'name'" );
            $this->assertArrayHasKey( 'description', $spec, "Tool missing 'description'" );
            $this->assertArrayHasKey( 'parameters',  $spec, "Tool missing 'parameters'" );
        }
    }

    public function test_expected_tool_names_are_present(): void {
        $names = array_column( $this->handler()->tool_specs(), 'name' );

        foreach ( [ 'search_products', 'get_product_details', 'add_to_cart', 'view_cart', 'remove_from_cart' ] as $expected ) {
            $this->assertContains( $expected, $names, "Tool '{$expected}' missing from spec" );
        }
    }

    public function test_tool_parameters_are_valid_json_schema(): void {
        foreach ( $this->handler()->tool_specs() as $spec ) {
            $params = $spec['parameters'];
            $this->assertSame( 'object', $params['type'],
                "Tool '{$spec['name']}' parameters must be type:object" );
            $this->assertArrayHasKey( 'properties', $params,
                "Tool '{$spec['name']}' parameters missing 'properties'" );
        }
    }

    public function test_required_tools_declare_required_fields(): void {
        $specs = array_column( $this->handler()->tool_specs(), null, 'name' );

        // get_product_details requires product_id.
        $this->assertArrayHasKey( 'required', $specs['get_product_details']['parameters'] );
        $this->assertContains( 'product_id', $specs['get_product_details']['parameters']['required'] );

        // add_to_cart requires product_id.
        $this->assertArrayHasKey( 'required', $specs['add_to_cart']['parameters'] );
        $this->assertContains( 'product_id', $specs['add_to_cart']['parameters']['required'] );

        // remove_from_cart requires cart_item_key.
        $this->assertArrayHasKey( 'required', $specs['remove_from_cart']['parameters'] );
        $this->assertContains( 'cart_item_key', $specs['remove_from_cart']['parameters']['required'] );
    }

    public function test_tool_descriptions_are_non_empty_strings(): void {
        foreach ( $this->handler()->tool_specs() as $spec ) {
            $this->assertIsString( $spec['description'] );
            $this->assertNotEmpty( $spec['description'],
                "Tool '{$spec['name']}' has empty description" );
        }
    }

    // ── get_system_prompt() — passes through the fahad_ai_system_prompt filter ──
    // (issue #20: cross-session memory injects a compact preferences block here,
    // WITHOUT editing the agent-loop methods — the only change to the handler is the
    // apply_filters() wrap. These tests prove the filter is applied and that the
    // default prompt is unchanged when no filter modifies it.)

    private function get_system_prompt(): string {
        $method = new ReflectionMethod( Fahad_AI_API_Handler::class, 'get_system_prompt' );
        return $method->invoke( $this->handler() );
    }

    public function test_system_prompt_passes_through_the_filter(): void {
        // A hooked modification on `fahad_ai_system_prompt` must be reflected in the
        // returned prompt — this is the seam the memory pack uses to append prefs.
        Functions\when( 'apply_filters' )->alias(
            static fn( $hook, $value = null ) =>
                'fahad_ai_system_prompt' === $hook ? $value . "\n\n[INJECTED BLOCK]" : $value
        );

        $prompt = $this->get_system_prompt();

        $this->assertStringContainsString( '[INJECTED BLOCK]', $prompt );
        // The base prompt is still present — the filter APPENDS, it does not replace.
        $this->assertStringContainsString( 'shopping assistant', $prompt );
    }

    public function test_system_prompt_is_applied_with_the_documented_hook_name(): void {
        $applied_hooks = [];
        Functions\when( 'apply_filters' )->alias(
            static function ( $hook, $value = null ) use ( &$applied_hooks ) {
                $applied_hooks[] = $hook;
                return $value;
            }
        );

        $this->get_system_prompt();

        $this->assertContains( 'fahad_ai_system_prompt', $applied_hooks );
    }

    public function test_system_prompt_is_unchanged_when_no_filter_modifies_it(): void {
        // With apply_filters passing the value straight through (the WordPress default
        // when nothing is hooked), the default prompt text is emitted verbatim.
        Functions\when( 'apply_filters' )->alias( static fn( $hook, $value = null ) => $value );

        $prompt = $this->get_system_prompt();

        $this->assertStringContainsString( 'You are a helpful shopping assistant for Test Store', $prompt );
        $this->assertStringContainsString( 'Currency: $', $prompt );
        // No injected block when nothing hooks the filter.
        $this->assertStringNotContainsString( '[INJECTED BLOCK]', $prompt );
    }

    public function test_default_prompt_states_the_trust_guardrail_policy(): void {
        // issue #24: the trust / anti-dark-pattern policy lives INLINE in the default
        // prompt (its source of truth). This pins the consolidated guardrail section so
        // a future prompt edit cannot silently drop the policy — the deterministic eval
        // checkers (scarcity_violations / budget_violations / escalation_present /
        // abstains) enforce the BEHAVIOUR; this asserts the POLICY text is present.
        Functions\when( 'apply_filters' )->alias( static fn( $hook, $value = null ) => $value );

        $prompt = $this->get_system_prompt();

        $this->assertStringContainsString( 'No fake urgency or scarcity', $prompt );
        $this->assertStringContainsString( 'Respect the customer\'s stated budget', $prompt );
        $this->assertStringContainsString( 'Abstain over guessing', $prompt );
        $this->assertStringContainsString( 'Never block human support', $prompt );
        // The earlier ad-hoc honesty lines are CONSOLIDATED here, not duplicated: the
        // grounding clause now reads as one rule covering product facts AND reviews.
        $this->assertStringContainsString( 'Never invent product details, prices, stock, reviews', $prompt );
    }

    public function test_custom_system_prompt_option_still_passes_through_the_filter(): void {
        // An admin-set custom prompt short-circuits the default text but must STILL go
        // through the filter, so memory injection works regardless of a custom prompt.
        Functions\when( 'get_option' )->alias(
            fn( $key, $default = '' ) => 'fahad_ai_system_prompt' === $key ? 'Custom store prompt.' : $default
        );
        Functions\when( 'apply_filters' )->alias(
            static fn( $hook, $value = null ) =>
                'fahad_ai_system_prompt' === $hook ? $value . ' [PREFS]' : $value
        );

        $prompt = $this->get_system_prompt();

        $this->assertStringContainsString( 'Custom store prompt.', $prompt );
        $this->assertStringContainsString( '[PREFS]', $prompt );
    }

    public function test_custom_system_prompt_still_carries_the_trust_guardrails(): void {
        // issue #56: a merchant's custom prompt USED to return verbatim, with the
        // guardrails entirely absent. They are now appended after the merchant text AND
        // after the filter, so the anti-feature policy can never be dropped by config.
        Functions\when( 'get_option' )->alias(
            fn( $key, $default = '' ) => 'fahad_ai_system_prompt' === $key ? 'Sell hard, no rules.' : $default
        );
        Functions\when( 'apply_filters' )->alias( static fn( $hook, $value = null ) => $value );

        $prompt = $this->get_system_prompt();

        $this->assertStringContainsString( 'Sell hard, no rules.', $prompt );
        $this->assertStringContainsString( 'No fake urgency or scarcity', $prompt );
        $this->assertStringContainsString( 'Never block human support', $prompt );
    }

    // ── moonshot_base_url() region selection ──────────────────────────────────

    private function moonshot_base_url(): string {
        $method = new ReflectionMethod( Fahad_AI_API_Handler::class, 'moonshot_base_url' );
        return $method->invoke( $this->handler() );
    }

    public function test_base_url_defaults_to_global(): void {
        // setUp stubs get_option() to return the supplied default, so the
        // region resolves to 'global' when the option is unset.
        $this->assertSame( 'https://api.moonshot.ai', $this->moonshot_base_url() );
    }

    public function test_base_url_uses_china_endpoint_when_region_is_china(): void {
        Functions\when( 'get_option' )->alias(
            fn( $key, $default = '' ) => 'fahad_ai_moonshot_region' === $key ? 'china' : $default
        );

        $this->assertSame( 'https://api.moonshot.cn', $this->moonshot_base_url() );
    }

    public function test_base_url_uses_global_endpoint_for_any_non_china_value(): void {
        Functions\when( 'get_option' )->alias(
            fn( $key, $default = '' ) => 'fahad_ai_moonshot_region' === $key ? 'global' : $default
        );

        $this->assertSame( 'https://api.moonshot.ai', $this->moonshot_base_url() );
    }

    // ── tool_result_cards() — product card payload for the widget ─────────────

    private function tool_result_cards( string $tool, array $result ): array {
        $method = new ReflectionMethod( Fahad_AI_API_Handler::class, 'tool_result_cards' );
        return $method->invoke( $this->handler(), $tool, $result );
    }

    public function test_search_products_result_maps_to_cards(): void {
        $cards = $this->tool_result_cards( 'search_products', [
            'found'    => 2,
            'products' => [
                [ 'id' => 10, 'name' => 'Sneakers', 'price' => 'Rs90', 'in_stock' => true,  'image' => 'http://x/a.jpg', 'url' => 'http://x/p/10' ],
                [ 'id' => 11, 'name' => 'Bottle',   'price' => 'Rs35', 'in_stock' => false, 'image' => 'http://x/b.jpg', 'url' => 'http://x/p/11' ],
            ],
        ] );

        $this->assertCount( 2, $cards );
        $this->assertSame( 10, $cards[0]['id'] );
        $this->assertSame( 'Sneakers', $cards[0]['name'] );
        $this->assertTrue( $cards[0]['in_stock'] );
        $this->assertFalse( $cards[1]['in_stock'] );
        $this->assertSame( 'http://x/a.jpg', $cards[0]['image'] );
    }

    public function test_get_product_details_result_maps_to_single_card(): void {
        $cards = $this->tool_result_cards( 'get_product_details', [
            'id'                => 42,
            'name'              => 'Headphones',
            'price'             => 'Rs150',
            'in_stock'          => true,
            'short_description' => 'Noise-cancelling',
            'image'             => 'http://x/h.jpg',
            'url'               => 'http://x/p/42',
        ] );

        $this->assertCount( 1, $cards );
        $this->assertSame( 42, $cards[0]['id'] );
        $this->assertSame( 'Noise-cancelling', $cards[0]['short_description'] );
    }

    // ── rating fields pass through to the card (issue #11) ─────────────────────

    public function test_card_carries_rating_and_review_count(): void {
        $cards = $this->tool_result_cards( 'search_products', [
            'products' => [
                [ 'id' => 10, 'name' => 'Sneakers', 'price' => 'Rs90', 'in_stock' => true, 'rating' => 4.5, 'review_count' => 12 ],
            ],
        ] );

        $this->assertCount( 1, $cards );
        $this->assertSame( 4.5, $cards[0]['rating'] );
        $this->assertSame( 12, $cards[0]['review_count'] );
    }

    public function test_card_defaults_rating_fields_to_zero_when_absent(): void {
        // Tools that never set rating (or an add-on returning a bare product shape)
        // still produce a well-formed card: numeric zero, not missing/null.
        $cards = $this->tool_result_cards( 'some_future_product_tool', [
            'id'    => 99,
            'name'  => 'No Rating Product',
            'price' => 'Rs10',
        ] );

        $this->assertCount( 1, $cards );
        $this->assertSame( 0.0, $cards[0]['rating'] );
        $this->assertSame( 0, $cards[0]['review_count'] );
    }

    public function test_card_casts_rating_fields_to_numeric(): void {
        // Defence in depth: even if a string sneaks in, the card emits numbers so
        // the widget can compare review_count > 0 reliably.
        $cards = $this->tool_result_cards( 'search_products', [
            'products' => [
                [ 'id' => 10, 'name' => 'Sneakers', 'rating' => '3.7', 'review_count' => '4' ],
            ],
        ] );

        $this->assertSame( 3.7, $cards[0]['rating'] );
        $this->assertSame( 4, $cards[0]['review_count'] );
    }

    public function test_non_product_tools_produce_no_cards(): void {
        $this->assertSame( [], $this->tool_result_cards( 'add_to_cart', [ 'success' => true ] ) );
        $this->assertSame( [], $this->tool_result_cards( 'view_cart', [ 'empty' => true ] ) );
        $this->assertSame( [], $this->tool_result_cards( 'search_products', [ 'found' => 0, 'products' => [] ] ) );
    }

    public function test_card_without_id_or_name_is_dropped(): void {
        $cards = $this->tool_result_cards( 'search_products', [
            'products' => [
                [ 'name' => 'No ID' ],
                [ 'id' => 5 ],
                [ 'id' => 7, 'name' => 'Valid' ],
            ],
        ] );

        $this->assertCount( 1, $cards );
        $this->assertSame( 7, $cards[0]['id'] );
    }

    // ── variations on the card payload (issue #12) ────────────────────────────
    // A variable product's card carries an is_variable flag and a COMPACT
    // variations list (variation_id, label, price, in_stock) so the widget can
    // render a selector and add the chosen variation. Non-variable products are
    // unchanged: no variations key, is_variable false.

    public function test_card_carries_variations_for_variable_product(): void {
        $cards = $this->tool_result_cards( 'get_product_details', [
            'id'         => 42,
            'name'       => 'Cotton Tee',
            'price'      => '$20.00',
            'in_stock'   => true,
            'type'       => 'variable',
            'variations' => [
                [
                    'variation_id' => 51,
                    'label'        => 'Size: Large, Color: Blue',
                    'attributes'   => [ 'attribute_pa_size' => 'large', 'attribute_pa_color' => 'blue' ],
                    'price'        => '$25.00',
                    'in_stock'     => true,
                ],
                [
                    'variation_id' => 52,
                    'label'        => 'Size: Small, Color: Red',
                    'attributes'   => [ 'attribute_pa_size' => 'small', 'attribute_pa_color' => 'red' ],
                    'price'        => '$22.00',
                    'in_stock'     => false,
                ],
            ],
        ] );

        $this->assertCount( 1, $cards );
        $this->assertTrue( $cards[0]['is_variable'] );
        $this->assertArrayHasKey( 'variations', $cards[0] );
        $this->assertCount( 2, $cards[0]['variations'] );

        // Compact shape: only the fields the widget needs.
        $v = $cards[0]['variations'][0];
        $this->assertSame( 51, $v['variation_id'] );
        $this->assertSame( 'Size: Large, Color: Blue', $v['label'] );
        $this->assertSame( '$25.00', $v['price'] );
        $this->assertTrue( $v['in_stock'] );
        $this->assertSame( [ 'variation_id', 'label', 'price', 'in_stock' ], array_keys( $v ) );

        // Out-of-stock variation is carried (the widget decides how to present it),
        // with its own stock flag.
        $this->assertFalse( $cards[0]['variations'][1]['in_stock'] );
    }

    public function test_card_for_non_variable_product_has_no_variations(): void {
        $cards = $this->tool_result_cards( 'get_product_details', [
            'id'       => 10,
            'name'     => 'Simple Mug',
            'price'    => '$8.00',
            'in_stock' => true,
            'type'     => 'simple',
        ] );

        $this->assertCount( 1, $cards );
        $this->assertFalse( $cards[0]['is_variable'] );
        $this->assertArrayNotHasKey( 'variations', $cards[0] );
    }

    public function test_search_card_without_type_is_not_variable(): void {
        // search_products summaries carry no `type`/`variations`; their cards must
        // default to non-variable so the existing card payload is unchanged.
        $cards = $this->tool_result_cards( 'search_products', [
            'products' => [
                [ 'id' => 10, 'name' => 'Sneakers', 'price' => 'Rs90', 'in_stock' => true ],
            ],
        ] );

        $this->assertFalse( $cards[0]['is_variable'] );
        $this->assertArrayNotHasKey( 'variations', $cards[0] );
    }

    public function test_card_variations_drop_entries_missing_id_or_label(): void {
        // Defence in depth: a malformed variation entry (no id, or no label) is
        // dropped so the widget never renders an option it cannot add.
        $cards = $this->tool_result_cards( 'get_product_details', [
            'id'         => 42,
            'name'       => 'Cotton Tee',
            'type'       => 'variable',
            'variations' => [
                [ 'label' => 'No id', 'price' => '$1.00', 'in_stock' => true ],
                [ 'variation_id' => 0, 'label' => 'Zero id', 'price' => '$1.00', 'in_stock' => true ],
                [ 'variation_id' => 55, 'label' => 'Valid', 'price' => '$5.00', 'in_stock' => true ],
            ],
        ] );

        $this->assertCount( 1, $cards[0]['variations'] );
        $this->assertSame( 55, $cards[0]['variations'][0]['variation_id'] );
    }

    public function test_variable_product_with_no_variations_is_not_marked_variable(): void {
        // A "variable" type that yields an empty variations list (all options gone)
        // should not advertise a selector — is_variable false, no variations key.
        $cards = $this->tool_result_cards( 'get_product_details', [
            'id'         => 42,
            'name'       => 'Cotton Tee',
            'type'       => 'variable',
            'variations' => [],
        ] );

        $this->assertFalse( $cards[0]['is_variable'] );
        $this->assertArrayNotHasKey( 'variations', $cards[0] );
    }

    // ── convention-based card emission (issue #15) ────────────────────────────
    // Card emission keys off the RESULT SHAPE, not the tool name, so any current
    // or future product tool (get_top_products, recommendations, …) renders cards
    // without tool_result_cards() being taught its name.

    public function test_new_tool_with_products_array_maps_to_cards(): void {
        // A tool name tool_result_cards() has never heard of, returning the same
        // products[] shape search_products does → cards, by convention.
        $cards = $this->tool_result_cards( 'get_top_products', [
            'found'    => 2,
            'products' => [
                [ 'id' => 21, 'name' => 'Best Seller A', 'price' => 'Rs90', 'in_stock' => true,  'image' => 'http://x/a.jpg', 'url' => 'http://x/p/21' ],
                [ 'id' => 22, 'name' => 'Best Seller B', 'price' => 'Rs35', 'in_stock' => false, 'image' => 'http://x/b.jpg', 'url' => 'http://x/p/22' ],
            ],
        ] );

        $this->assertCount( 2, $cards );
        $this->assertSame( 21, $cards[0]['id'] );
        $this->assertSame( 'Best Seller A', $cards[0]['name'] );
        $this->assertTrue( $cards[0]['in_stock'] );
        $this->assertFalse( $cards[1]['in_stock'] );
    }

    public function test_new_tool_with_single_product_shape_maps_to_one_card(): void {
        // A single product-shaped result (has id AND name) from any tool → one card.
        $cards = $this->tool_result_cards( 'some_future_product_tool', [
            'id'                => 99,
            'name'              => 'Single Product',
            'price'             => 'Rs10',
            'in_stock'          => true,
            'short_description' => 'Just one',
            'image'             => 'http://x/s.jpg',
            'url'               => 'http://x/p/99',
        ] );

        $this->assertCount( 1, $cards );
        $this->assertSame( 99, $cards[0]['id'] );
        $this->assertSame( 'Single Product', $cards[0]['name'] );
        $this->assertSame( 'Just one', $cards[0]['short_description'] );
    }

    public function test_new_tool_with_non_product_result_yields_no_cards(): void {
        // A non-product result (no products[], not product-shaped) from a tool the
        // method does not know → no cards. This is what keeps list_categories
        // (a category list, not products) from rendering as product cards.
        $this->assertSame( [], $this->tool_result_cards( 'list_categories', [
            'categories' => [
                [ 'name' => 'Shoes', 'slug' => 'shoes', 'count' => 12 ],
            ],
        ] ) );
    }

    // ── tool_result_comparison() — comparison-table payload (issue #13) ────────
    // A comparison is a DIFFERENT shape from the flat card list (aligned columns +
    // attribute rows). It is surfaced as its OWN `comparison` payload, mirroring how
    // cards are surfaced, and a comparison-shaped result deliberately emits NO cards
    // (so the widget shows one comparison table, not a table plus redundant cards).

    private function tool_result_comparison( string $tool, array $result ): array {
        $method = new ReflectionMethod( Fahad_AI_API_Handler::class, 'tool_result_comparison' );
        return $method->invoke( $this->handler(), $tool, $result );
    }

    /** A comparison-shaped result (products[] + aligned attributes[]) → a comparison payload. */
    public function test_comparison_result_maps_to_comparison_payload(): void {
        $comparison = $this->tool_result_comparison( 'compare_products', [
            'found'    => 2,
            'products' => [
                [ 'id' => 10, 'name' => 'Tee A', 'price' => '$19.99', 'in_stock' => true,  'url' => 'http://x/p/10', 'image' => 'http://x/a.jpg', 'rating' => 4.5, 'review_count' => 10 ],
                [ 'id' => 11, 'name' => 'Tee B', 'price' => '$24.99', 'in_stock' => false, 'url' => 'http://x/p/11', 'image' => 'http://x/b.jpg', 'rating' => 4.0, 'review_count' => 5 ],
            ],
            'attributes' => [
                [ 'name' => 'Material', 'values' => [ 10 => 'Cotton', 11 => 'Linen' ] ],
                [ 'name' => 'Color',    'values' => [ 10 => 'Blue',   11 => 'Red' ] ],
            ],
        ] );

        // Columns: one normalized card per compared product (trusted server fields).
        $this->assertArrayHasKey( 'products', $comparison );
        $this->assertCount( 2, $comparison['products'] );
        $this->assertSame( 10, $comparison['products'][0]['id'] );
        $this->assertSame( 'Tee A', $comparison['products'][0]['name'] );
        $this->assertTrue( $comparison['products'][0]['in_stock'] );
        $this->assertFalse( $comparison['products'][1]['in_stock'] );
        $this->assertSame( 4.5, $comparison['products'][0]['rating'] );

        // Rows: the aligned attribute table, value per product id.
        $this->assertArrayHasKey( 'attributes', $comparison );
        $this->assertCount( 2, $comparison['attributes'] );
        $this->assertSame( 'Material', $comparison['attributes'][0]['name'] );
        $this->assertSame( 'Cotton', $comparison['attributes'][0]['values'][10] );
        $this->assertSame( 'Linen',  $comparison['attributes'][0]['values'][11] );
    }

    /** A comparison with no shared attributes still surfaces (empty attribute rows). */
    public function test_comparison_result_with_no_attributes_still_maps(): void {
        $comparison = $this->tool_result_comparison( 'compare_products', [
            'found'      => 2,
            'products'   => [
                [ 'id' => 10, 'name' => 'Mug A', 'price' => '$8.00', 'in_stock' => true ],
                [ 'id' => 11, 'name' => 'Mug B', 'price' => '$9.00', 'in_stock' => true ],
            ],
            'attributes' => [],
        ] );

        $this->assertCount( 2, $comparison['products'] );
        $this->assertSame( [], $comparison['attributes'] );
    }

    /** Non-comparison tool results → no comparison payload. */
    public function test_non_comparison_tools_produce_no_comparison(): void {
        // A plain search result (products[] but NO aligned attributes[]) is cards,
        // not a comparison.
        $this->assertSame( [], $this->tool_result_comparison( 'search_products', [
            'found'    => 1,
            'products' => [ [ 'id' => 10, 'name' => 'Sneakers', 'price' => 'Rs90', 'in_stock' => true ] ],
        ] ) );
        // Cart / category / error results are never comparisons either.
        $this->assertSame( [], $this->tool_result_comparison( 'add_to_cart', [ 'success' => true ] ) );
        $this->assertSame( [], $this->tool_result_comparison( 'list_categories', [
            'categories' => [ [ 'name' => 'Shoes', 'slug' => 'shoes', 'count' => 12 ] ],
        ] ) );
        // A graceful comparison error (no products[]) is not a comparison payload.
        $this->assertSame( [], $this->tool_result_comparison( 'compare_products', [ 'error' => 'need two' ] ) );
    }

    /** A single-product result is not a comparison (needs at least two columns). */
    public function test_single_product_result_is_not_a_comparison(): void {
        $this->assertSame( [], $this->tool_result_comparison( 'compare_products', [
            'found'      => 1,
            'products'   => [ [ 'id' => 10, 'name' => 'Solo', 'price' => '$5.00', 'in_stock' => true ] ],
            'attributes' => [],
        ] ) );
    }

    /**
     * A comparison-shaped result emits NO product cards: the widget renders ONE
     * comparison table from the comparison payload, not a table plus a redundant run
     * of cards for the same products.
     */
    public function test_comparison_shaped_result_emits_no_cards(): void {
        $cards = $this->tool_result_cards( 'compare_products', [
            'found'      => 2,
            'products'   => [
                [ 'id' => 10, 'name' => 'Tee A', 'price' => '$19.99', 'in_stock' => true ],
                [ 'id' => 11, 'name' => 'Tee B', 'price' => '$24.99', 'in_stock' => true ],
            ],
            'attributes' => [
                [ 'name' => 'Material', 'values' => [ 10 => 'Cotton', 11 => 'Linen' ] ],
            ],
        ] );

        $this->assertSame( [], $cards );
    }

    /**
     * Existing card emission is unchanged: a plain products[] result (no aligned
     * attributes[]) still maps to cards — the comparison detection must not steal
     * the search/best-seller card path.
     */
    public function test_plain_products_result_still_maps_to_cards_after_comparison_added(): void {
        $cards = $this->tool_result_cards( 'search_products', [
            'found'    => 2,
            'products' => [
                [ 'id' => 10, 'name' => 'Sneakers', 'price' => 'Rs90', 'in_stock' => true ],
                [ 'id' => 11, 'name' => 'Bottle',   'price' => 'Rs35', 'in_stock' => false ],
            ],
        ] );

        $this->assertCount( 2, $cards );
        $this->assertSame( 10, $cards[0]['id'] );
    }

    // ── trim_tool_result() — shrink the model copy, never the card copy (issue #23)
    // The FULL tool result is JSON-appended to the model's message history every
    // turn (expensive). trim_tool_result() returns a COPY reduced to the fields the
    // model needs to reason/answer. Cards are built from the FULL result BEFORE the
    // trim (proven below), so the widget payload is unaffected — only the model copy
    // shrinks. Trimming is tool-aware and conservative (keep name + price so the
    // grounding eval, which reconstructs results from the trimmed transcript, still
    // passes).

    private function trim_tool_result( string $tool, array $result ): array {
        $method = new ReflectionMethod( Fahad_AI_API_Handler::class, 'trim_tool_result' );
        return $method->invoke( $this->handler(), $tool, $result );
    }

    /** Build a full search result with N products carrying every heavy field. */
    private function full_search_result( int $n ): array {
        $products = [];
        for ( $i = 1; $i <= $n; $i++ ) {
            $products[] = [
                'id'                => $i,
                'name'              => "Product {$i}",
                'price'             => '$' . ( 10 + $i ) . '.00',
                'regular_price'     => '$' . ( 20 + $i ) . '.00',
                'sale_price'        => '$' . ( 10 + $i ) . '.00',
                'on_sale'           => true,
                'in_stock'          => true,
                'short_description' => str_repeat( 'A long product blurb that costs tokens. ', 8 ),
                'image'             => "https://example.com/wp-content/uploads/2026/01/product-{$i}-1024x1024.jpg",
                'url'               => "https://example.com/product/product-{$i}/",
                'rating'            => 4.5,
                'review_count'      => 12,
            ];
        }
        return [ 'found' => $n, 'products' => $products ];
    }

    public function test_trim_reduces_search_result_size_substantially(): void {
        // The headline "measurable token reduction" evidence: a 10-product search
        // result, once trimmed for the MODEL, is much smaller than the full result.
        $full    = $this->full_search_result( 10 );
        $trimmed = $this->trim_tool_result( 'search_products', $full );

        $full_len    = strlen( json_encode( $full ) );
        $trimmed_len = strlen( json_encode( $trimmed ) );

        // Meaningful margin: trimmed encodes to less than HALF the full size.
        $this->assertLessThan(
            $full_len * 0.5,
            $trimmed_len,
            sprintf( 'expected a substantial reduction; full=%d trimmed=%d', $full_len, $trimmed_len )
        );
    }

    public function test_trim_preserves_name_and_price_for_each_product(): void {
        // Grounding depends on name + price surviving the trim (the eval reconstructs
        // the results the model saw from the trimmed transcript).
        $trimmed = $this->trim_tool_result( 'search_products', $this->full_search_result( 3 ) );

        $this->assertCount( 3, $trimmed['products'] );
        foreach ( $trimmed['products'] as $i => $p ) {
            $n = $i + 1;
            $this->assertSame( $n, $p['id'] );
            $this->assertSame( "Product {$n}", $p['name'] );
            $this->assertSame( '$' . ( 10 + $n ) . '.00', $p['price'] );
            $this->assertArrayHasKey( 'in_stock', $p );
        }
    }

    public function test_trim_drops_heavy_product_fields(): void {
        $trimmed = $this->trim_tool_result( 'search_products', $this->full_search_result( 2 ) );

        foreach ( $trimmed['products'] as $p ) {
            $this->assertArrayNotHasKey( 'image', $p );
            $this->assertArrayNotHasKey( 'short_description', $p );
            $this->assertArrayNotHasKey( 'regular_price', $p );
            $this->assertArrayNotHasKey( 'sale_price', $p );
        }
    }

    public function test_trim_does_not_mutate_the_full_result_used_for_cards(): void {
        // The cards are built from the FULL result; trim must operate on a COPY and
        // never strip fields off the array the caller still holds for card emission.
        $full = $this->full_search_result( 2 );
        $this->trim_tool_result( 'search_products', $full );

        // The original still has every heavy field — trimming did not mutate it.
        $this->assertArrayHasKey( 'image', $full['products'][0] );
        $this->assertArrayHasKey( 'short_description', $full['products'][0] );
        $this->assertSame( 'https://example.com/wp-content/uploads/2026/01/product-1-1024x1024.jpg', $full['products'][0]['image'] );

        // And a card built from the (untouched) full result still carries the image.
        $cards = $this->tool_result_cards( 'search_products', $full );
        $this->assertSame( $full['products'][0]['image'], $cards[0]['image'] );
    }

    public function test_trim_preserves_cart_link_fields_for_add_to_cart(): void {
        // The system prompt's link rules depend on cart_url/checkout_url/message and
        // totals from add_to_cart — they MUST survive the trim.
        $full = [
            'success'       => true,
            'message'       => 'Added 1x Trail Runner to your cart.',
            'cart_item_key' => 'abc123',
            'price'         => '$79.99',
            'cart_total'    => '$79.99',
            'cart_url'      => 'https://example.com/cart',
            'checkout_url'  => 'https://example.com/checkout',
        ];
        $trimmed = $this->trim_tool_result( 'add_to_cart', $full );

        $this->assertSame( 'https://example.com/cart', $trimmed['cart_url'] );
        $this->assertSame( 'https://example.com/checkout', $trimmed['checkout_url'] );
        $this->assertSame( 'Added 1x Trail Runner to your cart.', $trimmed['message'] );
        $this->assertSame( '$79.99', $trimmed['cart_total'] );
        // The grounded price the variation fixture references survives too.
        $this->assertSame( '$79.99', $trimmed['price'] );
    }

    public function test_trim_preserves_view_cart_link_fields_and_totals(): void {
        $full = [
            'empty'        => false,
            'items'        => [ [ 'cart_item_key' => 'k1', 'product_id' => 10, 'name' => 'Trail Runner', 'quantity' => 1, 'price' => '$79.99', 'line_total' => '$79.99' ] ],
            'item_count'   => 1,
            'subtotal'     => '$79.99',
            'total'        => '$79.99',
            'cart_url'     => 'https://example.com/cart',
            'checkout_url' => 'https://example.com/checkout',
        ];
        $trimmed = $this->trim_tool_result( 'view_cart', $full );

        $this->assertSame( 'https://example.com/cart', $trimmed['cart_url'] );
        $this->assertSame( 'https://example.com/checkout', $trimmed['checkout_url'] );
        $this->assertSame( '$79.99', $trimmed['total'] );
    }

    public function test_trim_preserves_error_and_login_messages(): void {
        $this->assertSame(
            [ 'error' => 'Product not found.' ],
            $this->trim_tool_result( 'get_product_details', [ 'error' => 'Product not found.' ] )
        );

        $login = $this->trim_tool_result( 'order_status', [ 'requires_login' => true, 'error' => 'Please log in.' ] );
        $this->assertTrue( $login['requires_login'] );
        $this->assertSame( 'Please log in.', $login['error'] );
    }

    public function test_trim_keeps_single_product_extra_fields_but_drops_heavy_ones(): void {
        // A single product-shaped result (e.g. get_product_reviews returns id+name
        // PLUS review snippets the model must summarise) is trimmed SUBTRACTIVELY:
        // only the heavy product fields are dropped, every other field is kept so the
        // grounded review/quote survives.
        $full = [
            'id'                => 101,
            'name'              => 'Trail Runner',
            'price'             => '$79.99',
            'regular_price'     => '$99.99',
            'sale_price'        => '$79.99',
            'on_sale'           => true,
            'in_stock'          => true,
            'image'             => 'https://example.com/img/trail.jpg',
            'short_description' => 'A very long short description that the model does not need.',
            'rating'            => 4.5,
            'review_count'      => 24,
            'reviews'           => [
                [ 'author' => 'Dana', 'rating' => 5, 'excerpt' => 'great quality and so comfortable', 'date' => '2026-03-02' ],
            ],
        ];
        $trimmed = $this->trim_tool_result( 'get_product_reviews', $full );

        // Heavy fields gone.
        $this->assertArrayNotHasKey( 'image', $trimmed );
        $this->assertArrayNotHasKey( 'short_description', $trimmed );
        $this->assertArrayNotHasKey( 'regular_price', $trimmed );
        $this->assertArrayNotHasKey( 'sale_price', $trimmed );

        // Essentials + the grounded review text kept.
        $this->assertSame( 'Trail Runner', $trimmed['name'] );
        $this->assertSame( '$79.99', $trimmed['price'] );
        $this->assertSame( 24, $trimmed['review_count'] );
        $this->assertSame( 'great quality and so comfortable', $trimmed['reviews'][0]['excerpt'] );
    }

    public function test_trim_keeps_comparison_attributes_and_trims_columns(): void {
        // A comparison result feeds the model BOTH product columns and the aligned
        // attribute rows it reasons over. Trim the heavy per-product fields but keep
        // the attribute table intact.
        $full = [
            'found'      => 2,
            'products'   => [
                [ 'id' => 401, 'name' => 'Trail Runner', 'price' => '$79.99', 'in_stock' => true, 'image' => 'https://x/a.jpg', 'short_description' => 'blurb', 'regular_price' => '$99.99' ],
                [ 'id' => 402, 'name' => 'Summit Pro',   'price' => '$129.99', 'in_stock' => true, 'image' => 'https://x/b.jpg', 'short_description' => 'blurb', 'regular_price' => '$149.99' ],
            ],
            'attributes' => [
                [ 'name' => 'Waterproof', 'values' => [ 401 => 'No', 402 => 'Yes' ] ],
                [ 'name' => 'Weight',     'values' => [ 401 => '280g', 402 => '340g' ] ],
            ],
        ];
        $trimmed = $this->trim_tool_result( 'compare_products', $full );

        // Attribute rows are preserved verbatim (the model reasons over these).
        $this->assertSame( $full['attributes'], $trimmed['attributes'] );

        // Columns keep name + price, drop the heavy fields.
        $this->assertSame( 'Trail Runner', $trimmed['products'][0]['name'] );
        $this->assertSame( '$79.99', $trimmed['products'][0]['price'] );
        $this->assertArrayNotHasKey( 'image', $trimmed['products'][0] );
        $this->assertArrayNotHasKey( 'short_description', $trimmed['products'][0] );
    }

    public function test_trim_is_filterable(): void {
        // The trim must be tunable/disable-able via the documented filter.
        Functions\when( 'apply_filters' )->alias(
            static function ( $hook, $value = null, $tool = null, $full = null ) {
                // A hook that disables trimming returns the full result untouched.
                return 'fahad_ai_trim_tool_result' === $hook ? $full : $value;
            }
        );

        $full    = $this->full_search_result( 2 );
        $trimmed = $this->trim_tool_result( 'search_products', $full );

        // With the disabling filter, the "trimmed" copy equals the full result.
        $this->assertArrayHasKey( 'image', $trimmed['products'][0] );
        $this->assertSame( $full, $trimmed );
    }

    public function test_trim_passes_tool_and_full_result_to_the_filter(): void {
        $seen = [];
        Functions\when( 'apply_filters' )->alias(
            static function ( $hook, $value = null, $tool = null, $full = null ) use ( &$seen ) {
                if ( 'fahad_ai_trim_tool_result' === $hook ) {
                    $seen = [ 'tool' => $tool, 'full' => $full ];
                }
                return $value;
            }
        );

        $full = $this->full_search_result( 1 );
        $this->trim_tool_result( 'search_products', $full );

        $this->assertSame( 'search_products', $seen['tool'] );
        $this->assertSame( $full, $seen['full'] );
    }

    // ── apply_token_budget() — bound the outgoing context (issue #23) ───────────
    // A configurable per-conversation budget (option + filter fahad_ai_token_budget,
    // default 0 = unlimited) caps the context. Token size is estimated with a
    // char/÷4 proxy. When over budget, the OLDEST non-essential history is dropped
    // while the system prompt (if present), the latest user turn, and the most recent
    // tool results survive. An in-progress tool loop is never broken.

    private function apply_token_budget( array $messages ): array {
        $method = new ReflectionMethod( Fahad_AI_API_Handler::class, 'apply_token_budget' );
        return $method->invoke( $this->handler(), $messages );
    }

    private function set_option_alias( array $map ): void {
        Functions\when( 'get_option' )->alias(
            static fn( $key, $default = '' ) => $map[ $key ] ?? $default
        );
    }

    public function test_budget_unlimited_by_default_leaves_messages_unchanged(): void {
        // Default option 0 / absent → unlimited → identity.
        $messages = [
            [ 'role' => 'user', 'content' => str_repeat( 'hello ', 500 ) ],
            [ 'role' => 'assistant', 'content' => str_repeat( 'world ', 500 ) ],
            [ 'role' => 'user', 'content' => 'and now this' ],
        ];

        $this->assertSame( $messages, $this->apply_token_budget( $messages ) );
    }

    public function test_budget_under_limit_leaves_messages_unchanged(): void {
        $this->set_option_alias( [ 'fahad_ai_token_budget' => 100000 ] );

        $messages = [
            [ 'role' => 'user', 'content' => 'short' ],
            [ 'role' => 'assistant', 'content' => 'also short' ],
        ];

        $this->assertSame( $messages, $this->apply_token_budget( $messages ) );
    }

    public function test_budget_drops_oldest_history_when_over_limit(): void {
        // A tiny budget forces the oldest history out. The latest user turn must
        // survive; the very first (oldest) message must be dropped.
        $this->set_option_alias( [ 'fahad_ai_token_budget' => 50 ] );

        $messages = [
            [ 'role' => 'user', 'content' => str_repeat( 'OLDEST ', 200 ) ],      // ~1400 chars
            [ 'role' => 'assistant', 'content' => str_repeat( 'middle ', 200 ) ], // ~1400 chars
            [ 'role' => 'user', 'content' => 'the newest question' ],
        ];

        $out = $this->apply_token_budget( $messages );

        // The newest user turn is preserved.
        $last = end( $out );
        $this->assertSame( 'the newest question', $last['content'] );
        // The oldest message was dropped (the budget is far smaller than its size).
        $this->assertLessThan( count( $messages ), count( $out ) );
        $contents = array_map( static fn( $m ) => $m['content'] ?? '', $out );
        $this->assertNotContains( str_repeat( 'OLDEST ', 200 ), $contents );
    }

    public function test_budget_keeps_system_message_and_latest_turn(): void {
        // Moonshot passes a leading system message in the array. Even over budget,
        // the system prompt and the latest user turn (plus its tool loop) survive.
        $this->set_option_alias( [ 'fahad_ai_token_budget' => 60 ] );

        $messages = [
            [ 'role' => 'system', 'content' => 'SYSTEM PROMPT keep me' ],
            [ 'role' => 'user', 'content' => str_repeat( 'old turn ', 200 ) ],
            [ 'role' => 'assistant', 'content' => str_repeat( 'old answer ', 200 ) ],
            [ 'role' => 'user', 'content' => 'latest question' ],
        ];

        $out = $this->apply_token_budget( $messages );

        $this->assertSame( 'system', $out[0]['role'] );
        $this->assertSame( 'SYSTEM PROMPT keep me', $out[0]['content'] );
        $last = end( $out );
        $this->assertSame( 'latest question', $last['content'] );
    }

    public function test_budget_preserves_in_progress_tool_loop(): void {
        // The latest user turn is followed by an assistant tool_use + a user
        // tool_result (an in-progress Anthropic loop). The budget must NOT split that
        // pair off — everything from the latest user turn to the end is protected.
        $this->set_option_alias( [ 'fahad_ai_token_budget' => 80 ] );

        $messages = [
            [ 'role' => 'user', 'content' => str_repeat( 'ancient ', 300 ) ],
            [ 'role' => 'assistant', 'content' => str_repeat( 'history ', 300 ) ],
            [ 'role' => 'user', 'content' => 'find me a tee' ],
            [ 'role' => 'assistant', 'content' => [ [ 'type' => 'tool_use', 'id' => 'tu1', 'name' => 'search_products', 'input' => [] ] ] ],
            [ 'role' => 'user', 'content' => [ [ 'type' => 'tool_result', 'tool_use_id' => 'tu1', 'content' => '{"found":1}' ] ] ],
        ];

        $out = $this->apply_token_budget( $messages );

        // The whole tail (latest user turn + tool_use + tool_result) is intact, in order.
        $tail = array_slice( $out, -3 );
        $this->assertSame( 'find me a tee', $tail[0]['content'] );
        $this->assertSame( 'tool_use', $tail[1]['content'][0]['type'] );
        $this->assertSame( 'tool_result', $tail[2]['content'][0]['type'] );
    }

    public function test_budget_is_filterable(): void {
        // The option default can be overridden by the fahad_ai_token_budget filter.
        // Here the option is unset (would be unlimited) but the filter sets a small
        // budget, so older history is dropped.
        Functions\when( 'apply_filters' )->alias(
            static fn( $hook, $value = null ) => 'fahad_ai_token_budget' === $hook ? 50 : $value
        );

        $messages = [
            [ 'role' => 'user', 'content' => str_repeat( 'OLDEST ', 200 ) ],
            [ 'role' => 'user', 'content' => 'newest' ],
        ];

        $out = $this->apply_token_budget( $messages );

        $this->assertLessThan( count( $messages ), count( $out ) );
        $last = end( $out );
        $this->assertSame( 'newest', $last['content'] );
    }

    // ── resolve_model() — configurable model routing (issue #23) ────────────────
    // Routing lets a hook pick a cheaper/faster model for simple turns and a more
    // capable one for reasoning. The DEFAULT must preserve today's behaviour: the
    // configured model is returned unchanged unless a fahad_ai_model filter overrides
    // it. The chosen model flows into the request payload.

    private function resolve_model( string $default, string $provider, array $context = [] ): string {
        $method = new ReflectionMethod( Fahad_AI_API_Handler::class, 'resolve_model' );
        return $method->invoke( $this->handler(), $default, $provider, $context );
    }

    public function test_resolve_model_returns_configured_model_by_default(): void {
        // No filter hooked → the default (configured) model is returned verbatim.
        Functions\when( 'apply_filters' )->alias( static fn( $hook, $value = null ) => $value );

        $this->assertSame(
            'claude-haiku-4-5-20251001',
            $this->resolve_model( 'claude-haiku-4-5-20251001', 'anthropic', [ 'has_tools' => true, 'iteration' => 0 ] )
        );
    }

    public function test_resolve_model_can_be_overridden_by_filter(): void {
        Functions\when( 'apply_filters' )->alias(
            static fn( $hook, $value = null, $provider = null, $context = null ) =>
                'fahad_ai_model' === $hook ? 'claude-opus-4-8' : $value
        );

        $this->assertSame(
            'claude-opus-4-8',
            $this->resolve_model( 'claude-haiku-4-5-20251001', 'anthropic', [] )
        );
    }

    public function test_resolve_model_passes_provider_and_context_to_filter(): void {
        $seen = [];
        Functions\when( 'apply_filters' )->alias(
            static function ( $hook, $value = null, $provider = null, $context = null ) use ( &$seen ) {
                if ( 'fahad_ai_model' === $hook ) {
                    $seen = [ 'default' => $value, 'provider' => $provider, 'context' => $context ];
                }
                return $value;
            }
        );

        $this->resolve_model( 'kimi-k2.6', 'moonshot', [ 'has_tools' => false, 'iteration' => 2 ] );

        $this->assertSame( 'kimi-k2.6', $seen['default'] );
        $this->assertSame( 'moonshot', $seen['provider'] );
        $this->assertSame( [ 'has_tools' => false, 'iteration' => 2 ], $seen['context'] );
    }

    public function test_resolve_model_falls_back_when_filter_returns_non_string(): void {
        // Defence in depth: a misbehaving filter returning a non-string must not
        // poison the payload — the configured default stands.
        Functions\when( 'apply_filters' )->alias(
            static fn( $hook, $value = null ) => 'fahad_ai_model' === $hook ? null : $value
        );

        $this->assertSame(
            'kimi-k2.6',
            $this->resolve_model( 'kimi-k2.6', 'moonshot', [] )
        );
    }

    public function test_anthropic_payload_uses_the_routed_model(): void {
        // End-to-end seam check: a fahad_ai_model override flows into the Anthropic
        // request payload (asserted via the captured wp_remote_post body).
        $this->set_option_alias( [ 'fahad_ai_anthropic_api_key' => 'k', 'fahad_ai_anthropic_model' => 'claude-haiku-4-5-20251001' ] );
        Functions\when( 'apply_filters' )->alias(
            static fn( $hook, $value = null ) => 'fahad_ai_model' === $hook ? 'claude-opus-4-8' : $value
        );
        Functions\when( 'wp_json_encode' )->alias( static fn( $d ) => json_encode( $d ) );

        $captured = null;
        Functions\when( 'wp_remote_post' )->alias(
            static function ( $url, $args ) use ( &$captured ) {
                $captured = json_decode( $args['body'], true );
                return [ 'is_eval' => true ];
            }
        );
        Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 200 );
        Functions\when( 'wp_remote_retrieve_body' )->justReturn( json_encode( [ 'stop_reason' => 'end_turn', 'content' => [] ] ) );
        // is_wp_error() is a real stub (instanceof WP_Error); the array returned by
        // wp_remote_post above is not a WP_Error, so it correctly reports false —
        // do NOT redefine it via Brain\Monkey (Patchwork "DefinedTooEarly").

        $method = new ReflectionMethod( Fahad_AI_API_Handler::class, 'call_anthropic' );
        $method->invoke( $this->handler(), [ [ 'role' => 'user', 'content' => 'hi' ] ] );

        $this->assertSame( 'claude-opus-4-8', $captured['model'] );
    }

    // ── prime_cart_session() — guest cart persistence over SSE (live-QA finding #31)
    // The streaming endpoint flushes the event-stream headers and then holds the
    // connection open, so WooCommerce never gets its shutdown chance to send the
    // guest session Set-Cookie before output starts. handle_stream() therefore
    // primes the cart and forces the cookie out via this helper BEFORE the headers.
    // Header ordering itself isn't unit-testable, but we can pin the contract: the
    // helper must load the cart and emit the session cookie when none has been sent.

    private function prime_cart_session(): void {
        $method = new ReflectionMethod( Fahad_AI_API_Handler::class, 'prime_cart_session' );
        $method->invoke( $this->handler() );
    }

    public function test_prime_cart_session_emits_guest_session_cookie(): void {
        // wc_load_cart() must be called so the session/cart are available, and the
        // session cookie must be forced out (true) so the guest's browser is handed
        // a WC session id that survives to the next request. headers_sent() is a PHP
        // internal Patchwork can't redefine without extra config; under PHPUnit CLI it
        // genuinely returns false (nothing has been emitted), which is the path that
        // matters here — so we exercise it for real rather than stubbing it.
        Functions\expect( 'wc_load_cart' )->once();

        $session = Mockery::mock();
        $session->shouldReceive( 'set_customer_session_cookie' )->once()->with( true );
        Functions\when( 'WC' )->justReturn( (object) [ 'session' => $session ] );

        $this->prime_cart_session();
    }

    // ── provider failover & graceful degradation (issue #58) ────────────────────
    // A provider error/timeout/429 should fall back to the OTHER configured provider
    // (reusing the configured order); with no provider available the shopper still
    // gets a graceful, non-error response (product search + support), never a dead
    // end. These pin the three small seams the dispatch wires together:
    //   has_provider_key() — is a provider's key option non-empty?
    //   provider_chain()   — ordered, key-filtered list to try (configured first).
    //   degraded_response() — the friendly, NON-error fallback when all providers fail.

    private function has_provider_key( string $provider ): bool {
        $method = new ReflectionMethod( Fahad_AI_API_Handler::class, 'has_provider_key' );
        return $method->invoke( $this->handler(), $provider );
    }

    private function provider_chain(): array {
        $method = new ReflectionMethod( Fahad_AI_API_Handler::class, 'provider_chain' );
        return $method->invoke( $this->handler() );
    }

    private function degraded_response( array $messages = [] ): array {
        $method = new ReflectionMethod( Fahad_AI_API_Handler::class, 'degraded_response' );
        return $method->invoke( $this->handler(), $messages );
    }

    // ── has_provider_key() ──────────────────────────────────────────────────────

    public function test_has_provider_key_true_when_key_set(): void {
        $this->set_option_alias( [
            'fahad_ai_anthropic_api_key' => 'sk-ant-123',
            'fahad_ai_moonshot_api_key'  => 'sk-moon-456',
        ] );

        $this->assertTrue( $this->has_provider_key( 'anthropic' ) );
        $this->assertTrue( $this->has_provider_key( 'moonshot' ) );
    }

    public function test_has_provider_key_false_when_key_missing_or_empty(): void {
        // setUp's get_option stub returns the default ('') for unset options, so both
        // keys read as empty here.
        $this->assertFalse( $this->has_provider_key( 'anthropic' ) );
        $this->assertFalse( $this->has_provider_key( 'moonshot' ) );

        // An explicitly empty string is also "no key".
        $this->set_option_alias( [
            'fahad_ai_anthropic_api_key' => '',
            'fahad_ai_moonshot_api_key'  => '',
        ] );
        $this->assertFalse( $this->has_provider_key( 'anthropic' ) );
        $this->assertFalse( $this->has_provider_key( 'moonshot' ) );
    }

    public function test_has_provider_key_reads_the_matching_option_per_provider(): void {
        // Only the moonshot key is set → only moonshot reports true.
        $this->set_option_alias( [ 'fahad_ai_moonshot_api_key' => 'sk-moon' ] );

        $this->assertTrue( $this->has_provider_key( 'moonshot' ) );
        $this->assertFalse( $this->has_provider_key( 'anthropic' ) );
    }

    // ── provider_chain() — configured provider first, key-filtered ──────────────

    public function test_provider_chain_configured_moonshot_with_both_keys(): void {
        // configured = moonshot, both keys present → moonshot first, anthropic fallback.
        $this->set_option_alias( [
            'fahad_ai_provider'          => 'moonshot',
            'fahad_ai_anthropic_api_key' => 'sk-ant',
            'fahad_ai_moonshot_api_key'  => 'sk-moon',
        ] );

        $this->assertSame( [ 'moonshot', 'anthropic' ], $this->provider_chain() );
    }

    public function test_provider_chain_configured_anthropic_with_both_keys(): void {
        // configured = anthropic, both keys present → anthropic first, moonshot fallback.
        $this->set_option_alias( [
            'fahad_ai_provider'          => 'anthropic',
            'fahad_ai_anthropic_api_key' => 'sk-ant',
            'fahad_ai_moonshot_api_key'  => 'sk-moon',
        ] );

        $this->assertSame( [ 'anthropic', 'moonshot' ], $this->provider_chain() );
    }

    public function test_provider_chain_defaults_to_anthropic_first_when_provider_unset(): void {
        // The provider option defaults to 'anthropic' (matching handle_message's
        // existing default), so with both keys the chain leads with anthropic.
        $this->set_option_alias( [
            'fahad_ai_anthropic_api_key' => 'sk-ant',
            'fahad_ai_moonshot_api_key'  => 'sk-moon',
        ] );

        $this->assertSame( [ 'anthropic', 'moonshot' ], $this->provider_chain() );
    }

    public function test_provider_chain_filters_out_provider_without_key(): void {
        // configured = anthropic but ONLY the anthropic key exists → chain is just
        // [anthropic]; the keyless moonshot is filtered out (no pointless fallback).
        $this->set_option_alias( [
            'fahad_ai_provider'          => 'anthropic',
            'fahad_ai_anthropic_api_key' => 'sk-ant',
        ] );

        $this->assertSame( [ 'anthropic' ], $this->provider_chain() );
    }

    public function test_provider_chain_fallback_only_when_configured_provider_keyless(): void {
        // configured = anthropic but only the MOONSHOT key exists. The configured
        // provider has no key, so the chain is just the keyed fallback [moonshot].
        $this->set_option_alias( [
            'fahad_ai_provider'         => 'anthropic',
            'fahad_ai_moonshot_api_key' => 'sk-moon',
        ] );

        $this->assertSame( [ 'moonshot' ], $this->provider_chain() );
    }

    public function test_provider_chain_empty_when_no_keys(): void {
        // No keys configured at all → empty chain (handle_message keeps its existing
        // no-key WP_Error in this case).
        $this->set_option_alias( [ 'fahad_ai_provider' => 'moonshot' ] );

        $this->assertSame( [], $this->provider_chain() );
    }

    public function test_provider_chain_has_no_duplicates(): void {
        // Each provider appears at most once regardless of configuration — the
        // dispatch tries each provider a single time (bounded, no loop).
        $this->set_option_alias( [
            'fahad_ai_provider'          => 'moonshot',
            'fahad_ai_anthropic_api_key' => 'sk-ant',
            'fahad_ai_moonshot_api_key'  => 'sk-moon',
        ] );

        $chain = $this->provider_chain();

        $this->assertSame( $chain, array_values( array_unique( $chain ) ) );
        $this->assertLessThanOrEqual( 2, count( $chain ) );
    }

    // ── degraded_response() — friendly, NON-error fallback ──────────────────────

    public function test_degraded_response_shape_is_a_friendly_non_error(): void {
        $result = $this->degraded_response();

        // A non-empty friendly message (never a raw error / blank).
        $this->assertArrayHasKey( 'message', $result );
        $this->assertIsString( $result['message'] );
        $this->assertNotEmpty( $result['message'] );

        // Explicitly flagged degraded, and carries the empty card/comparison shape so
        // the widget renders consistently.
        $this->assertTrue( $result['degraded'] );
        $this->assertSame( [], $result['products'] );
        $this->assertSame( [], $result['comparison'] );

        // It is NOT an error payload — no `error` key, never a dead end.
        $this->assertArrayNotHasKey( 'error', $result );
    }

    public function test_degraded_response_message_points_to_search_and_support(): void {
        // The friendly copy must keep the shopper moving: it should mention they can
        // still browse/search the store AND reach support (the human handoff). This
        // is the "never a dead end" guarantee in prose.
        $message = strtolower( $this->degraded_response()['message'] );

        $this->assertStringContainsString( 'search', $message );
        $this->assertStringContainsString( 'support', $message );
    }

    public function test_degraded_response_message_leaks_no_secret_or_exception(): void {
        // Hardening: the friendly message must never surface a key or raw exception
        // text. Even with keys configured, the degraded copy is generic.
        $this->set_option_alias( [
            'fahad_ai_anthropic_api_key' => 'sk-ant-SECRET',
            'fahad_ai_moonshot_api_key'  => 'sk-moon-SECRET',
        ] );

        $message = $this->degraded_response()['message'];

        $this->assertStringNotContainsString( 'sk-ant-SECRET', $message );
        $this->assertStringNotContainsString( 'sk-moon-SECRET', $message );
        $this->assertStringNotContainsString( 'Exception', $message );
    }

    public function test_degraded_response_carries_the_messages_passed_in(): void {
        // The conversation transcript is echoed back so the client keeps its history
        // intact after a degraded turn.
        $messages = [
            [ 'role' => 'user', 'content' => 'find me running shoes' ],
            [ 'role' => 'assistant', 'content' => 'sure, one sec' ],
        ];

        $result = $this->degraded_response( $messages );

        $this->assertSame( $messages, $result['messages'] );
    }

    public function test_degraded_response_defaults_messages_to_empty_array(): void {
        $result = $this->degraded_response();

        $this->assertArrayHasKey( 'messages', $result );
        $this->assertSame( [], $result['messages'] );
    }

    // ── handle_message() dispatch — failover + graceful degradation ─────────────
    // The non-streaming endpoint builds provider_chain() and tries each provider in
    // order: it returns the first non-WP_Error result, falls THROUGH to the next
    // provider on a WP_Error, and returns degraded_response() (NOT an error) only
    // after every provider has failed. With no key at all it keeps the existing
    // no-key WP_Error.
    //
    // Fahad_AI_API_Handler is `final`, so we do NOT partial-mock it (Mockery cannot
    // replace methods on a final class). Instead we drive the REAL dispatch end to
    // end — handle_message → run_*_agent → call_* → wp_remote_post — against a
    // SCRIPTED transport (the eval-harness pattern). wp_remote_post is routed BY URL
    // so we can make one provider's endpoint fail and the other succeed, exercising
    // the actual failover wiring rather than a mocked seam. Each canned turn is a
    // single end_turn/stop (no tool calls), so no WC tool mocks are needed.

    private function message_request( array $messages ) {
        $req = Mockery::mock( 'WP_REST_Request' );
        $req->shouldReceive( 'get_param' )->with( 'messages' )->andReturn( $messages );
        return $req;
    }

    /**
     * Stub rest_ensure_response to wrap a payload in a WP_REST_Response-shaped mock
     * exposing get_data(). handle_message() is type-hinted to return
     * WP_REST_Response|WP_Error, so (unlike the eval harness, which calls the private
     * agent loops directly) the identity stub would violate the return type. Mockery
     * auto-defines the WP_REST_Response class, mirroring how the suite already mocks
     * WP_REST_Request. A WP_Error passed in is returned as-is (matches real WP).
     */
    private function stub_rest_ensure_response(): void {
        // handle_message() calls wc_load_cart() (guarded by function_exists). In
        // isolation the function is undefined so the guard skips it; but once another
        // suite (e.g. the eval cases) has mocked wc_load_cart via Brain\Monkey it
        // becomes "known", so the guard runs it and Brain\Monkey demands a per-test
        // expectation. Stub it as a harmless no-op so this test is order-independent.
        Functions\when( 'wc_load_cart' )->justReturn( null );

        Functions\when( 'rest_ensure_response' )->alias( static function ( $data ) {
            if ( $data instanceof WP_Error ) {
                return $data;
            }
            $resp = Mockery::mock( 'WP_REST_Response' );
            $resp->shouldReceive( 'get_data' )->andReturn( $data );
            return $resp;
        } );
    }

    /** Read the payload out of a handle_message() return (WP_REST_Response mock). */
    private function response_data( $response ): array {
        return $response->get_data();
    }

    /** Anthropic non-streaming final-answer body (stop_reason end_turn). */
    private function anthropic_answer( string $text ): array {
        return [ 'stop_reason' => 'end_turn', 'content' => [ [ 'type' => 'text', 'text' => $text ] ] ];
    }

    /** Moonshot non-streaming final-answer body (finish_reason stop). */
    private function moonshot_answer( string $text ): array {
        return [ 'choices' => [ [ 'finish_reason' => 'stop', 'message' => [ 'role' => 'assistant', 'content' => $text ] ] ] ];
    }

    /**
     * Stub the agent-loop transport, routing by request URL so each provider can be
     * made to succeed or fail independently. A handler returns either a body array
     * (→ HTTP 200) or an [ 'code' => int, 'body' => array ] wrapper for a non-200
     * (which call_* turns into a WP_Error). Also stubs the wp_remote_retrieve_* and
     * encode helpers the loop relies on, and records how many times each provider
     * endpoint was hit so the tests can assert bounded, at-most-once tries.
     *
     * @param callable $anthropic fn(): array  Response for the Anthropic endpoint.
     * @param callable $moonshot  fn(): array  Response for the Moonshot endpoint.
     * @return ArrayObject Live call counter: $counts['anthropic'] / $counts['moonshot'].
     *                     An ArrayObject (not a plain array) so the closure and the
     *                     test share ONE instance by reference — a returned array would
     *                     be a value copy taken before any request was made.
     */
    private function route_transport( callable $anthropic, callable $moonshot ): ArrayObject {
        $counts = new ArrayObject( [ 'anthropic' => 0, 'moonshot' => 0 ] );

        Functions\when( 'wp_json_encode' )->alias( static fn( $d ) => json_encode( $d ) );

        // Owner analytics (#49): handle_message now records a privacy-safe turn event
        // via Fahad_AI_Analytics on every resolved (or degraded) turn. Analytics is ON
        // by default, so these end-to-end dispatch tests exercise that path — give the
        // store a harmless option seam (it persists with update_option) and a stable id
        // so the recording neither fatals on an unstubbed function nor pollutes state.
        // The recording is fire-and-forget here; the store has its own unit tests.
        Functions\when( 'update_option' )->justReturn( true );
        Functions\when( 'wp_generate_uuid4' )->justReturn( 'uuid-analytics' );
        ( new ReflectionProperty( Fahad_AI_Analytics::class, 'instance' ) )->setValue( null, null );

        Functions\when( 'wp_remote_post' )->alias(
            static function ( $url, $args = [] ) use ( $anthropic, $moonshot, $counts ) {
                $is_anthropic = str_contains( (string) $url, 'anthropic' );
                $key          = $is_anthropic ? 'anthropic' : 'moonshot';
                $counts[ $key ]++;
                $resp = $is_anthropic ? $anthropic() : $moonshot();

                if ( isset( $resp['code'] ) && isset( $resp['body'] ) ) {
                    return [ '__eval' => true, 'code' => $resp['code'], 'body' => json_encode( $resp['body'] ) ];
                }
                return [ '__eval' => true, 'code' => 200, 'body' => json_encode( $resp ) ];
            }
        );

        Functions\when( 'wp_remote_retrieve_response_code' )->alias(
            static fn( $r ) => is_array( $r ) ? ( $r['code'] ?? 0 ) : 0
        );
        Functions\when( 'wp_remote_retrieve_body' )->alias(
            static fn( $r ) => is_array( $r ) ? ( $r['body'] ?? '' ) : ''
        );

        return $counts;
    }

    public function test_handle_message_no_keys_returns_existing_no_key_error(): void {
        // No provider key configured → empty chain → the existing no-key WP_Error is
        // preserved (admin-facing signal that configuration is incomplete).
        Functions\when( 'sanitize_textarea_field' )->returnArg();
        $this->stub_rest_ensure_response();

        $result = $this->handler()->handle_message(
            $this->message_request( [ [ 'role' => 'user', 'content' => 'hi' ] ] )
        );

        $this->assertTrue( is_wp_error( $result ), 'With no key, the no-key WP_Error must be preserved.' );
    }

    public function test_handle_message_returns_primary_result_on_success(): void {
        // configured = moonshot with a key → moonshot is tried first; on success its
        // result is returned and the anthropic endpoint is never called.
        $this->set_option_alias( [
            'fahad_ai_provider'         => 'moonshot',
            'fahad_ai_moonshot_api_key' => 'sk-moon',
        ] );
        Functions\when( 'sanitize_textarea_field' )->returnArg();
        Functions\when( 'apply_filters' )->alias( static fn( $hook, $value = null ) => $value );
        $this->stub_rest_ensure_response();

        $counter = $this->route_transport(
            fn() => $this->fail( 'anthropic must not be called when moonshot succeeds' ),
            fn() => $this->moonshot_answer( 'moonshot says hi' )
        );

        $result = $this->response_data( $this->handler()->handle_message(
            $this->message_request( [ [ 'role' => 'user', 'content' => 'hi' ] ] )
        ) );

        $this->assertSame( 'moonshot says hi', $result['message'] );
        $this->assertArrayNotHasKey( 'degraded', $result, 'A successful turn is not degraded.' );
        $this->assertSame( 0, $counter['anthropic'] );
        $this->assertSame( 1, $counter['moonshot'] );
    }

    public function test_handle_message_falls_back_to_secondary_on_primary_error(): void {
        // configured = moonshot, both keys → primary (moonshot) returns a 429. The
        // dispatch transparently falls back to anthropic and returns ITS result.
        // Bounded: each provider endpoint is hit exactly once.
        $this->set_option_alias( [
            'fahad_ai_provider'          => 'moonshot',
            'fahad_ai_anthropic_api_key' => 'sk-ant',
            'fahad_ai_moonshot_api_key'  => 'sk-moon',
        ] );
        Functions\when( 'sanitize_textarea_field' )->returnArg();
        Functions\when( 'apply_filters' )->alias( static fn( $hook, $value = null ) => $value );
        $this->stub_rest_ensure_response();

        $counter = $this->route_transport(
            fn() => $this->anthropic_answer( 'anthropic to the rescue' ),
            fn() => [ 'code' => 429, 'body' => [ 'error' => [ 'message' => 'rate limited' ] ] ]
        );

        $result = $this->response_data( $this->handler()->handle_message(
            $this->message_request( [ [ 'role' => 'user', 'content' => 'hi' ] ] )
        ) );

        $this->assertSame( 'anthropic to the rescue', $result['message'] );
        $this->assertArrayNotHasKey( 'degraded', $result, 'A successful fallback is not degraded.' );
        $this->assertSame( 1, $counter['moonshot'], 'primary tried once' );
        $this->assertSame( 1, $counter['anthropic'], 'fallback tried once' );
    }

    public function test_handle_message_degrades_gracefully_when_all_providers_fail(): void {
        // Both providers keyed but BOTH error (502) → no dead end: a degraded_response
        // is returned (NOT a WP_Error), each provider endpoint hit exactly once
        // (bounded retries, cost does not balloon), and no key leaks into the copy.
        $this->set_option_alias( [
            'fahad_ai_provider'          => 'moonshot',
            'fahad_ai_anthropic_api_key' => 'sk-ant-SECRET',
            'fahad_ai_moonshot_api_key'  => 'sk-moon-SECRET',
        ] );
        Functions\when( 'sanitize_textarea_field' )->returnArg();
        Functions\when( 'apply_filters' )->alias( static fn( $hook, $value = null ) => $value );
        $this->stub_rest_ensure_response();

        $counter = $this->route_transport(
            fn() => [ 'code' => 502, 'body' => [ 'error' => [ 'message' => 'anthropic down' ] ] ],
            fn() => [ 'code' => 502, 'body' => [ 'error' => [ 'message' => 'moonshot down' ] ] ]
        );

        $response = $this->handler()->handle_message(
            $this->message_request( [ [ 'role' => 'user', 'content' => 'hi' ] ] )
        );

        $this->assertFalse( is_wp_error( $response ), 'Total failure must NOT surface as an error.' );
        $result = $this->response_data( $response );
        $this->assertTrue( $result['degraded'] );
        $this->assertNotEmpty( $result['message'] );
        $this->assertStringNotContainsString( 'SECRET', $result['message'] );
        $this->assertArrayNotHasKey( 'error', $result );

        // Bounded: at most one attempt per provider — no loop.
        $this->assertSame( 1, $counter['moonshot'] );
        $this->assertSame( 1, $counter['anthropic'] );
    }

    public function test_handle_message_single_provider_path_is_unchanged_on_success(): void {
        // The existing single-provider happy path: configured = anthropic, only the
        // anthropic key → chain is [anthropic], its result returns verbatim and the
        // moonshot endpoint is never touched.
        $this->set_option_alias( [
            'fahad_ai_provider'          => 'anthropic',
            'fahad_ai_anthropic_api_key' => 'sk-ant',
        ] );
        Functions\when( 'sanitize_textarea_field' )->returnArg();
        Functions\when( 'apply_filters' )->alias( static fn( $hook, $value = null ) => $value );
        $this->stub_rest_ensure_response();

        $counter = $this->route_transport(
            fn() => $this->anthropic_answer( 'anthropic single-provider' ),
            fn() => $this->fail( 'moonshot must not be called on the single-provider anthropic path' )
        );

        $result = $this->response_data( $this->handler()->handle_message(
            $this->message_request( [ [ 'role' => 'user', 'content' => 'hi' ] ] )
        ) );

        $this->assertSame( 'anthropic single-provider', $result['message'] );
        $this->assertSame( 1, $counter['anthropic'] );
        $this->assertSame( 0, $counter['moonshot'] );
    }

    // ── owner-analytics recording wired into the agent loop (issue #49) ──────────
    // handle_message / the stream loop call the private record_turn_analytics() at
    // each terminal point. These cover the WIRING (outcome derivation, tool trace,
    // funnel flags, PII passthrough to the store, opt-out) by invoking the private
    // method against the in-memory option seam — the eval-harness reflection pattern,
    // with NO setAccessible (host runs PHP 8.5). The store's own bounds/masking are
    // unit-tested in AnalyticsTest; here we prove the loop feeds it the right event.

    /** Back Fahad_AI_Analytics with the in-memory option map + reset its singleton. */
    private function analytics_option_seam(): void {
        Functions\when( 'get_option' )->alias( fn( $name, $default = false ) => $this->options[ $name ] ?? $default );
        Functions\when( 'update_option' )->alias( function ( $name, $value ) {
            $this->options[ $name ] = $value;
            return true;
        } );
        Functions\when( 'sanitize_text_field' )->alias( static fn( $s ) => is_string( $s ) ? trim( $s ) : '' );
        Functions\when( 'sanitize_textarea_field' )->alias( static fn( $s ) => is_string( $s ) ? trim( $s ) : '' );
        Functions\when( 'sanitize_key' )->alias( static fn( $s ) => is_string( $s ) ? strtolower( trim( $s ) ) : '' );
        Functions\when( 'wp_generate_uuid4' )->justReturn( 'uuid-fixed' );
        ( new ReflectionProperty( Fahad_AI_Analytics::class, 'instance' ) )->setValue( null, null );
    }

    /** Invoke the private record_turn_analytics() (no setAccessible — PHP 8.5 safe). */
    private function record_turn_analytics( array $input, array $result, string $hint = '', array $overrides = [] ): void {
        ( new ReflectionMethod( Fahad_AI_API_Handler::class, 'record_turn_analytics' ) )
            ->invoke( $this->handler(), $input, $result, $hint, $overrides );
    }

    /** The single persisted analytics row. */
    private function analytics_row(): array {
        $rows = $this->options[ Fahad_AI_Analytics::OPTION ] ?? [];
        return (array) reset( $rows );
    }

    public function test_loop_records_an_answered_turn_with_tool_trace_and_funnel_flag(): void {
        $this->options = [];
        $this->analytics_option_seam();

        // An Anthropic-shaped transcript: the model searched, then added to cart.
        $input  = [ [ 'role' => 'user', 'content' => 'add the red shoes to my cart' ] ];
        $result = [
            'message'  => 'Added to your cart.',
            'products' => [ [ 'id' => 1, 'name' => 'Red Shoes' ] ],
            'messages' => [
                [ 'role' => 'user', 'content' => 'add the red shoes to my cart' ],
                [ 'role' => 'assistant', 'content' => [
                    [ 'type' => 'tool_use', 'name' => 'search_products', 'id' => 't1', 'input' => [] ],
                ] ],
                [ 'role' => 'assistant', 'content' => [
                    [ 'type' => 'tool_use', 'name' => 'add_to_cart', 'id' => 't2', 'input' => [] ],
                ] ],
            ],
        ];

        $this->record_turn_analytics( $input, $result );

        $row = $this->analytics_row();
        $this->assertSame( Fahad_AI_Analytics::OUTCOME_ANSWERED, $row['outcome'] );
        $this->assertSame( [ 'search_products', 'add_to_cart' ], $row['tools'] );
        $this->assertTrue( $row['product_surfaced'] );
        $this->assertTrue( $row['added_to_cart'], 'add_to_cart in the trace flags the funnel.' );
    }

    public function test_loop_masks_an_email_typed_into_the_question(): void {
        $this->options = [];
        $this->analytics_option_seam();

        $input  = [ [ 'role' => 'user', 'content' => 'ship it to jane.doe@example.com' ] ];
        $result = [ 'message' => 'ok', 'products' => [], 'messages' => $input ];

        $this->record_turn_analytics( $input, $result );

        $q = $this->analytics_row()['question'];
        $this->assertStringNotContainsString( 'jane.doe@example.com', $q, 'A raw email must never be persisted from the loop.' );
        $this->assertStringContainsString( '@example.com', $q );
    }

    public function test_loop_records_escalated_when_a_tool_requires_login(): void {
        $this->options = [];
        $this->analytics_option_seam();

        // A personal tool returned requires_login (the grounded sign-in handoff).
        $input  = [ [ 'role' => 'user', 'content' => 'where is my order' ] ];
        $result = [
            'message'  => 'Please log in to see your orders.',
            'products' => [],
            'messages' => [
                [ 'role' => 'user', 'content' => 'where is my order' ],
                [ 'role' => 'assistant', 'content' => [ [ 'type' => 'tool_use', 'name' => 'get_my_orders', 'id' => 't1', 'input' => [] ] ] ],
                [ 'role' => 'tool', 'content' => '{"error":"Please log in","requires_login":true}' ],
            ],
        ];

        $this->record_turn_analytics( $input, $result );

        $this->assertSame( Fahad_AI_Analytics::OUTCOME_ESCALATED, $this->analytics_row()['outcome'] );
    }

    public function test_loop_records_no_tool_match_when_nothing_was_used(): void {
        $this->options = [];
        $this->analytics_option_seam();

        // The model answered with no tool call and surfaced no product.
        $input  = [ [ 'role' => 'user', 'content' => 'what is the meaning of life' ] ];
        $result = [
            'message'  => 'I can help you shop — was there a product you had in mind?',
            'products' => [],
            'messages' => [ [ 'role' => 'user', 'content' => 'what is the meaning of life' ], [ 'role' => 'assistant', 'content' => [ [ 'type' => 'text', 'text' => '...' ] ] ] ],
        ];

        $this->record_turn_analytics( $input, $result );

        $this->assertSame( Fahad_AI_Analytics::OUTCOME_NO_TOOL_MATCH, $this->analytics_row()['outcome'] );
    }

    public function test_loop_records_nothing_when_analytics_is_disabled(): void {
        $this->options = [ Fahad_AI_Analytics::OPTION_ENABLED => 0 ];
        $this->analytics_option_seam();

        $input  = [ [ 'role' => 'user', 'content' => 'hi' ] ];
        $this->record_turn_analytics( $input, [ 'message' => 'hi', 'products' => [], 'messages' => $input ] );

        $this->assertSame( [], $this->options[ Fahad_AI_Analytics::OPTION ] ?? [], 'Opt-out must persist nothing.' );
    }

    public function test_loop_honors_an_explicit_outcome_hint(): void {
        // The dispatch path passes OUTCOME_ERROR when every provider failed; the hint
        // wins over derivation.
        $this->options = [];
        $this->analytics_option_seam();

        $input  = [ [ 'role' => 'user', 'content' => 'hi' ] ];
        $this->record_turn_analytics( $input, [ 'message' => '', 'products' => [], 'messages' => $input ], Fahad_AI_Analytics::OUTCOME_ERROR );

        $this->assertSame( Fahad_AI_Analytics::OUTCOME_ERROR, $this->analytics_row()['outcome'] );
    }

    public function test_loop_uses_streaming_overrides_for_tools_and_cart(): void {
        // The streaming path can't read the trace from a returned transcript, so it
        // passes the tools it accumulated + the cart flag as overrides.
        $this->options = [];
        $this->analytics_option_seam();

        $input = [ [ 'role' => 'user', 'content' => 'add it' ] ];
        $this->record_turn_analytics(
            $input,
            [ 'message' => 'done', 'products' => [ [ 'id' => 1 ] ], 'messages' => $input ],
            '',
            [ 'tools' => [ 'search_products', 'add_to_cart' ], 'added_to_cart' => true ]
        );

        $row = $this->analytics_row();
        $this->assertSame( [ 'search_products', 'add_to_cart' ], $row['tools'] );
        $this->assertTrue( $row['added_to_cart'] );
    }

    // ── multi-provider dispatch (OpenAI-compatible presets) ──────────────────────
    // The OpenAI path is generalised: run_openai_agent / call_openai are parameterised
    // by a provider id resolved from Fahad_AI_Providers. Moonshot is now just one preset
    // of this path; OpenAI, Gemini, Groq, … ride the SAME code, differing only in the
    // base URL / key / model the catalog resolves. These pin:
    //   - an 'openai'-type preset resolves the right base_url/key/model and hits the
    //     OpenAI /chat/completions endpoint with a Bearer header (asserted via the
    //     captured wp_remote_post URL/headers/body — the harness pattern);
    //   - the native 'anthropic' path is unchanged (api.anthropic.com + x-api-key);
    //   - BACKWARD COMPAT: fahad_ai_provider=moonshot still hits api.moonshot.ai;
    //   - failover generalises across the whole catalog (configured first, then any
    //     OTHER keyed provider, each at most once);
    //   - the custom base URL is validated.

    /**
     * Capture the single wp_remote_post( url, args ) an agent turn makes, returning a
     * scripted 200 body. Used to assert the URL/headers/model the generalised OpenAI
     * path sends for a given provider preset.
     *
     * @param array $body The provider wire-format response body (use *_answer()).
     * @return ArrayObject Live capture: ['url'], ['args'].
     */
    private function capture_openai_post( array $body ): ArrayObject {
        $cap = new ArrayObject( [ 'url' => null, 'args' => null ] );

        Functions\when( 'wp_json_encode' )->alias( static fn( $d ) => json_encode( $d ) );
        Functions\when( 'apply_filters' )->alias( static fn( $hook, $value = null ) => $value );

        Functions\when( 'wp_remote_post' )->alias(
            static function ( $url, $args = [] ) use ( &$cap, $body ) {
                $cap['url']  = $url;
                $cap['args'] = $args;
                return [ '__eval' => true, 'code' => 200, 'body' => json_encode( $body ) ];
            }
        );
        Functions\when( 'wp_remote_retrieve_response_code' )->alias(
            static fn( $r ) => is_array( $r ) ? ( $r['code'] ?? 0 ) : 0
        );
        Functions\when( 'wp_remote_retrieve_body' )->alias(
            static fn( $r ) => is_array( $r ) ? ( $r['body'] ?? '' ) : ''
        );

        return $cap;
    }

    /** Invoke the generalised private call_openai( messages, provider_id ). */
    private function call_openai( array $messages, string $provider_id ): array {
        $method = new ReflectionMethod( Fahad_AI_API_Handler::class, 'call_openai' );
        return (array) $method->invoke( $this->handler(), $messages, $provider_id );
    }

    public function test_call_openai_targets_openai_endpoint_with_bearer_and_model(): void {
        // The 'openai' preset → https://api.openai.com/v1/chat/completions, a Bearer
        // auth header carrying the openai key, and the configured openai model.
        $this->set_option_alias( [
            'fahad_ai_provider'       => 'openai',
            'fahad_ai_openai_api_key' => 'sk-openai-XYZ',
            'fahad_ai_openai_model'   => 'gpt-4o',
        ] );

        $cap = $this->capture_openai_post( $this->moonshot_answer( 'hi from openai' ) );
        $this->call_openai( [ [ 'role' => 'system', 'content' => 'sys' ], [ 'role' => 'user', 'content' => 'hi' ] ], 'openai' );

        $this->assertSame( 'https://api.openai.com/v1/chat/completions', $cap['url'] );
        $this->assertSame( 'Bearer sk-openai-XYZ', $cap['args']['headers']['Authorization'] );

        $payload = json_decode( $cap['args']['body'], true );
        $this->assertSame( 'gpt-4o', $payload['model'] );
        // The OpenAI tool format (type:function), not the Anthropic input_schema form.
        $this->assertArrayHasKey( 'tools', $payload );
    }

    public function test_call_openai_uses_preset_default_model_when_unset(): void {
        // No model option set for openai → the catalog default (gpt-4o-mini) is sent.
        $this->set_option_alias( [
            'fahad_ai_provider'       => 'openai',
            'fahad_ai_openai_api_key' => 'sk-openai',
        ] );

        $cap = $this->capture_openai_post( $this->moonshot_answer( 'hi' ) );
        $this->call_openai( [ [ 'role' => 'user', 'content' => 'hi' ] ], 'openai' );

        $payload = json_decode( $cap['args']['body'], true );
        $this->assertSame( 'gpt-4o-mini', $payload['model'] );
    }

    public function test_call_openai_targets_gemini_base_url(): void {
        // A different OpenAI-compatible preset rides the SAME path with its own base URL.
        $this->set_option_alias( [
            'fahad_ai_provider'       => 'gemini',
            'fahad_ai_gemini_api_key' => 'sk-gemini',
            'fahad_ai_gemini_model'   => 'gemini-2.0-flash',
        ] );

        $cap = $this->capture_openai_post( $this->moonshot_answer( 'hi' ) );
        $this->call_openai( [ [ 'role' => 'user', 'content' => 'hi' ] ], 'gemini' );

        $this->assertSame(
            'https://generativelanguage.googleapis.com/v1beta/openai/chat/completions',
            $cap['url']
        );
        $this->assertSame( 'Bearer sk-gemini', $cap['args']['headers']['Authorization'] );
    }

    public function test_call_openai_moonshot_preset_still_targets_moonshot(): void {
        // BACKWARD COMPAT: the moonshot preset resolves to the region base URL and its
        // existing key/model — the exact request the pre-multi-provider code sent.
        $this->set_option_alias( [
            'fahad_ai_provider'         => 'moonshot',
            'fahad_ai_moonshot_api_key' => 'sk-moon',
            'fahad_ai_moonshot_model'   => 'kimi-k2.6',
            'fahad_ai_moonshot_region'  => 'global',
        ] );

        $cap = $this->capture_openai_post( $this->moonshot_answer( 'hi' ) );
        $this->call_openai( [ [ 'role' => 'user', 'content' => 'hi' ] ], 'moonshot' );

        $this->assertSame( 'https://api.moonshot.ai/v1/chat/completions', $cap['url'] );
        $this->assertSame( 'Bearer sk-moon', $cap['args']['headers']['Authorization'] );
        $payload = json_decode( $cap['args']['body'], true );
        $this->assertSame( 'kimi-k2.6', $payload['model'] );
    }

    public function test_call_openai_moonshot_china_region_targets_cn_endpoint(): void {
        // BACKWARD COMPAT: the region selection survives the generalisation.
        $this->set_option_alias( [
            'fahad_ai_moonshot_api_key' => 'sk-moon',
            'fahad_ai_moonshot_region'  => 'china',
        ] );

        $cap = $this->capture_openai_post( $this->moonshot_answer( 'hi' ) );
        $this->call_openai( [ [ 'role' => 'user', 'content' => 'hi' ] ], 'moonshot' );

        $this->assertSame( 'https://api.moonshot.cn/v1/chat/completions', $cap['url'] );
    }

    public function test_call_openai_custom_uses_merchant_base_url(): void {
        // The custom preset sends to the merchant-configured base URL.
        $this->set_option_alias( [
            'fahad_ai_provider'        => 'custom',
            'fahad_ai_custom_api_key'  => 'sk-custom',
            'fahad_ai_custom_model'    => 'my-model',
            'fahad_ai_custom_base_url' => 'https://llm.mystore.example/v1',
        ] );

        $cap = $this->capture_openai_post( $this->moonshot_answer( 'hi' ) );
        $this->call_openai( [ [ 'role' => 'user', 'content' => 'hi' ] ], 'custom' );

        $this->assertSame( 'https://llm.mystore.example/v1/chat/completions', $cap['url'] );
        $payload = json_decode( $cap['args']['body'], true );
        $this->assertSame( 'my-model', $payload['model'] );
    }

    public function test_call_openai_no_key_returns_wp_error(): void {
        // No key for the selected provider → the existing no-key WP_Error contract
        // (so an admin still gets the "configure a key" signal). No request is made.
        $this->set_option_alias( [ 'fahad_ai_provider' => 'openai' ] );
        Functions\when( 'apply_filters' )->alias( static fn( $hook, $value = null ) => $value );
        Functions\expect( 'wp_remote_post' )->never();

        $method = new ReflectionMethod( Fahad_AI_API_Handler::class, 'call_openai' );
        $result = $method->invoke( $this->handler(), [ [ 'role' => 'user', 'content' => 'hi' ] ], 'openai' );

        $this->assertTrue( is_wp_error( $result ) );
    }

    // ── has_provider_key()/provider_chain() generalised across the catalog ───────

    public function test_has_provider_key_works_for_a_new_provider(): void {
        $this->set_option_alias( [ 'fahad_ai_openai_api_key' => 'sk-openai' ] );

        $this->assertTrue( $this->has_provider_key( 'openai' ) );
        $this->assertFalse( $this->has_provider_key( 'gemini' ) );
    }

    public function test_provider_chain_configured_first_then_other_keyed_providers(): void {
        // configured = openai (keyed); anthropic + groq also keyed; gemini NOT keyed.
        // The chain is the configured provider FIRST, then the other keyed providers
        // (in catalog order), each at most once. gemini is excluded (no key).
        $this->set_option_alias( [
            'fahad_ai_provider'          => 'openai',
            'fahad_ai_openai_api_key'    => 'sk-openai',
            'fahad_ai_anthropic_api_key' => 'sk-ant',
            'fahad_ai_groq_api_key'      => 'sk-groq',
        ] );

        $chain = $this->provider_chain();

        $this->assertSame( 'openai', $chain[0], 'Configured provider is tried first.' );
        $this->assertContains( 'anthropic', $chain );
        $this->assertContains( 'groq', $chain );
        $this->assertNotContains( 'gemini', $chain, 'A keyless provider is never in the chain.' );
        // Bounded + de-duplicated: each provider appears at most once.
        $this->assertSame( $chain, array_values( array_unique( $chain ) ) );
    }

    public function test_provider_chain_backward_compat_anthropic_then_moonshot(): void {
        // BACKWARD COMPAT: the original two-provider behaviour is preserved exactly.
        // configured = anthropic, both legacy keys set → ['anthropic','moonshot', …]
        // with anthropic first and moonshot present (the historical fallback).
        $this->set_option_alias( [
            'fahad_ai_provider'          => 'anthropic',
            'fahad_ai_anthropic_api_key' => 'sk-ant',
            'fahad_ai_moonshot_api_key'  => 'sk-moon',
        ] );

        $chain = $this->provider_chain();

        $this->assertSame( 'anthropic', $chain[0] );
        $this->assertContains( 'moonshot', $chain );
    }

    public function test_provider_chain_empty_when_no_keys_anywhere(): void {
        // No key for ANY catalog provider → empty chain (handle_message preserves the
        // existing no-key WP_Error in that case).
        $this->set_option_alias( [ 'fahad_ai_provider' => 'openai' ] );

        $this->assertSame( [], $this->provider_chain() );
    }

    // ── handle_message routes by provider type ───────────────────────────────────

    public function test_handle_message_routes_openai_provider_to_openai_endpoint(): void {
        // configured = openai with a key → the turn is dispatched through the OpenAI
        // path to api.openai.com, and the anthropic endpoint is never touched.
        $this->set_option_alias( [
            'fahad_ai_provider'       => 'openai',
            'fahad_ai_openai_api_key' => 'sk-openai',
        ] );
        Functions\when( 'sanitize_textarea_field' )->returnArg();
        Functions\when( 'apply_filters' )->alias( static fn( $hook, $value = null ) => $value );
        $this->stub_rest_ensure_response();

        $urls    = new ArrayObject( [] );
        Functions\when( 'wp_json_encode' )->alias( static fn( $d ) => json_encode( $d ) );
        Functions\when( 'update_option' )->justReturn( true );
        Functions\when( 'wp_generate_uuid4' )->justReturn( 'uuid' );
        ( new ReflectionProperty( Fahad_AI_Analytics::class, 'instance' ) )->setValue( null, null );
        Functions\when( 'wp_remote_post' )->alias(
            static function ( $url, $args = [] ) use ( $urls ) {
                $urls[] = $url;
                return [ '__eval' => true, 'code' => 200, 'body' => json_encode(
                    [ 'choices' => [ [ 'finish_reason' => 'stop', 'message' => [ 'role' => 'assistant', 'content' => 'openai reply' ] ] ] ]
                ) ];
            }
        );
        Functions\when( 'wp_remote_retrieve_response_code' )->alias( static fn( $r ) => is_array( $r ) ? ( $r['code'] ?? 0 ) : 0 );
        Functions\when( 'wp_remote_retrieve_body' )->alias( static fn( $r ) => is_array( $r ) ? ( $r['body'] ?? '' ) : '' );

        $result = $this->response_data( $this->handler()->handle_message(
            $this->message_request( [ [ 'role' => 'user', 'content' => 'hi' ] ] )
        ) );

        $this->assertSame( 'openai reply', $result['message'] );
        $this->assertStringContainsString( 'api.openai.com', (string) $urls[0] );
        foreach ( $urls as $u ) {
            $this->assertStringNotContainsString( 'anthropic', (string) $u, 'Anthropic must not be called for an openai provider.' );
        }
    }

    public function test_handle_message_falls_back_across_new_providers(): void {
        // configured = openai (502), fallback to a keyed anthropic. The dispatch tries
        // openai first, falls through on its error, and returns anthropic's result.
        $this->set_option_alias( [
            'fahad_ai_provider'          => 'openai',
            'fahad_ai_openai_api_key'    => 'sk-openai',
            'fahad_ai_anthropic_api_key' => 'sk-ant',
        ] );
        Functions\when( 'sanitize_textarea_field' )->returnArg();
        Functions\when( 'apply_filters' )->alias( static fn( $hook, $value = null ) => $value );
        $this->stub_rest_ensure_response();

        $counts = new ArrayObject( [ 'openai' => 0, 'anthropic' => 0 ] );
        Functions\when( 'wp_json_encode' )->alias( static fn( $d ) => json_encode( $d ) );
        Functions\when( 'update_option' )->justReturn( true );
        Functions\when( 'wp_generate_uuid4' )->justReturn( 'uuid' );
        ( new ReflectionProperty( Fahad_AI_Analytics::class, 'instance' ) )->setValue( null, null );
        Functions\when( 'wp_remote_post' )->alias(
            static function ( $url, $args = [] ) use ( $counts ) {
                if ( str_contains( (string) $url, 'anthropic' ) ) {
                    $counts['anthropic']++;
                    return [ '__eval' => true, 'code' => 200, 'body' => json_encode(
                        [ 'stop_reason' => 'end_turn', 'content' => [ [ 'type' => 'text', 'text' => 'anthropic fallback' ] ] ]
                    ) ];
                }
                $counts['openai']++;
                return [ '__eval' => true, 'code' => 502, 'body' => json_encode( [ 'error' => [ 'message' => 'down' ] ] ) ];
            }
        );
        Functions\when( 'wp_remote_retrieve_response_code' )->alias( static fn( $r ) => is_array( $r ) ? ( $r['code'] ?? 0 ) : 0 );
        Functions\when( 'wp_remote_retrieve_body' )->alias( static fn( $r ) => is_array( $r ) ? ( $r['body'] ?? '' ) : '' );

        $result = $this->response_data( $this->handler()->handle_message(
            $this->message_request( [ [ 'role' => 'user', 'content' => 'hi' ] ] )
        ) );

        $this->assertSame( 'anthropic fallback', $result['message'] );
        $this->assertSame( 1, $counts['openai'], 'primary (openai) tried once' );
        $this->assertSame( 1, $counts['anthropic'], 'fallback (anthropic) tried once' );
    }

    // ── custom base URL validation ───────────────────────────────────────────────

    private function valid_custom_base_url( string $url ): string {
        // wp_parse_url is WordPress's wrapper around parse_url; alias to the PHP core
        // function for the test. esc_url_raw is stubbed pass-through by each caller.
        Functions\when( 'wp_parse_url' )->alias( static fn( $u, $c = -1 ) => parse_url( $u ) );
        $method = new ReflectionMethod( Fahad_AI_API_Handler::class, 'sanitize_custom_base_url' );
        return (string) $method->invoke( null, $url );
    }

    public function test_custom_base_url_accepts_https(): void {
        Functions\when( 'esc_url_raw' )->returnArg();
        $this->assertSame( 'https://llm.example/v1', $this->valid_custom_base_url( 'https://llm.example/v1' ) );
    }

    public function test_custom_base_url_rejects_non_https_and_junk(): void {
        Functions\when( 'esc_url_raw' )->returnArg();
        // http (non-TLS), a bare word, and a javascript: scheme are all rejected to ''.
        $this->assertSame( '', $this->valid_custom_base_url( 'http://llm.example/v1' ), 'plain http rejected' );
        $this->assertSame( '', $this->valid_custom_base_url( 'not a url' ), 'junk rejected' );
        $this->assertSame( '', $this->valid_custom_base_url( 'javascript:alert(1)' ), 'dangerous scheme rejected' );
    }

    public function test_custom_base_url_allows_localhost_http_for_self_hosted(): void {
        // A self-hosted/local endpoint (e.g. a proxy on the same box) is the one
        // permitted non-TLS case — localhost never leaves the machine.
        Functions\when( 'esc_url_raw' )->returnArg();
        $this->assertSame( 'http://localhost:8080/v1', $this->valid_custom_base_url( 'http://localhost:8080/v1' ) );
    }

    // ── backward compatibility: the original moonshot dispatch is unchanged ───────

    public function test_handle_message_moonshot_backward_compat_hits_moonshot_endpoint(): void {
        // An install configured BEFORE multi-provider: fahad_ai_provider=moonshot with
        // the legacy key/model/region options. The turn must still dispatch to
        // api.moonshot.ai/v1/chat/completions with a Bearer header and the legacy model,
        // returning the moonshot reply — exactly as the pre-change code did. Anthropic
        // is never called (only the moonshot key is set).
        $this->set_option_alias( [
            'fahad_ai_provider'         => 'moonshot',
            'fahad_ai_moonshot_api_key' => 'sk-moon-legacy',
            'fahad_ai_moonshot_model'   => 'kimi-k2.6',
            'fahad_ai_moonshot_region'  => 'global',
        ] );
        Functions\when( 'sanitize_textarea_field' )->returnArg();
        Functions\when( 'apply_filters' )->alias( static fn( $hook, $value = null ) => $value );
        $this->stub_rest_ensure_response();

        $captured = new ArrayObject( [ 'url' => null, 'auth' => null, 'model' => null ] );
        Functions\when( 'wp_json_encode' )->alias( static fn( $d ) => json_encode( $d ) );
        Functions\when( 'update_option' )->justReturn( true );
        Functions\when( 'wp_generate_uuid4' )->justReturn( 'uuid' );
        ( new ReflectionProperty( Fahad_AI_Analytics::class, 'instance' ) )->setValue( null, null );
        Functions\when( 'wp_remote_post' )->alias(
            static function ( $url, $args = [] ) use ( $captured ) {
                $captured['url']   = $url;
                $captured['auth']  = $args['headers']['Authorization'] ?? null;
                $captured['model'] = ( json_decode( $args['body'] ?? '{}', true )['model'] ?? null );
                return [ '__eval' => true, 'code' => 200, 'body' => json_encode(
                    [ 'choices' => [ [ 'finish_reason' => 'stop', 'message' => [ 'role' => 'assistant', 'content' => 'kimi reply' ] ] ] ]
                ) ];
            }
        );
        Functions\when( 'wp_remote_retrieve_response_code' )->alias( static fn( $r ) => is_array( $r ) ? ( $r['code'] ?? 0 ) : 0 );
        Functions\when( 'wp_remote_retrieve_body' )->alias( static fn( $r ) => is_array( $r ) ? ( $r['body'] ?? '' ) : '' );

        $result = $this->response_data( $this->handler()->handle_message(
            $this->message_request( [ [ 'role' => 'user', 'content' => 'hi' ] ] )
        ) );

        $this->assertSame( 'kimi reply', $result['message'] );
        $this->assertSame( 'https://api.moonshot.ai/v1/chat/completions', $captured['url'] );
        $this->assertSame( 'Bearer sk-moon-legacy', $captured['auth'] );
        $this->assertSame( 'kimi-k2.6', $captured['model'] );
    }

    public function test_provider_chain_is_bounded_with_many_keys(): void {
        // Even with a key for EVERY catalog provider, the chain lists each provider at
        // most once (bounded failover — no loop, no duplicate attempts). Its length
        // never exceeds the catalog size.
        $map = [ 'fahad_ai_provider' => 'openai' ];
        foreach ( Fahad_AI_Providers::catalog() as $preset ) {
            $map[ $preset['key_option'] ] = 'sk-key';
        }
        $this->set_option_alias( $map );

        $chain = $this->provider_chain();

        $this->assertSame( $chain, array_values( array_unique( $chain ) ), 'No duplicate providers.' );
        $this->assertLessThanOrEqual( count( Fahad_AI_Providers::ids() ), count( $chain ) );
        $this->assertSame( 'openai', $chain[0], 'Configured provider is still first.' );
    }
}
