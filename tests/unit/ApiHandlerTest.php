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
}
