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

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();

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
}
