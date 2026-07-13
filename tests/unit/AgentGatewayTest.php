<?php
/**
 * Unit tests for Fahad_AI_Agent_Gateway (Epic C, store-as-an-agent).
 *
 * Covers route registration, the llms.txt + catalog feed, the tool-reusing search /
 * product endpoints, and the human checkout handoff, every branch, so an agent can
 * never be served fabricated or unsafe data.
 */

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;

final class AgentGatewayTest extends TestCase {

	use MockeryPHPUnitIntegration;

	private array $pack_snapshot = [];

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		$this->pack_snapshot = (array) ( new ReflectionProperty( Fahad_AI_Tool_Registry::class, 'pack_providers' ) )->getValue();

		Functions\when( 'rest_ensure_response' )->alias( static fn ( $x ) => $x );
		Functions\when( 'wp_strip_all_tags' )->alias( static fn ( $s ) => trim( (string) strip_tags( (string) $s ) ) );
		Functions\when( 'sanitize_text_field' )->alias( static fn ( $s ) => trim( (string) $s ) );
		Functions\when( 'get_option' )->alias( static fn ( $key, $default = '' ) => $default );
		Functions\when( 'get_terms' )->justReturn( [] );
		Functions\when( 'is_user_logged_in' )->justReturn( true );
		Functions\when( 'get_current_user_id' )->justReturn( 0 );
	}

	protected function tearDown(): void {
		( new ReflectionProperty( Fahad_AI_Tool_Registry::class, 'pack_providers' ) )->setValue( null, $this->pack_snapshot );
		( new ReflectionProperty( Fahad_AI_Agent_Gateway::class, 'instance' ) )->setValue( null, null );
		Monkey\tearDown();
		parent::tearDown();
	}

	private function gateway(): Fahad_AI_Agent_Gateway {
		( new ReflectionProperty( Fahad_AI_Agent_Gateway::class, 'instance' ) )->setValue( null, null );
		return Fahad_AI_Agent_Gateway::instance();
	}

	/** Reset the registry/Tools singletons so the gateway dispatches the REAL built-in tools. */
	private function resetRegistry(): void {
		( new ReflectionProperty( Fahad_AI_Tools::class, 'instance' ) )->setValue( null, null );
		( new ReflectionProperty( Fahad_AI_Tool_Registry::class, 'instance' ) )->setValue( null, null );
		Fahad_AI_Tool_Registry::reset_packs();
	}

	public function test_register_routes_registers_the_agent_endpoints(): void {
		$paths = [];
		Functions\when( 'register_rest_route' )->alias( static function ( $ns, $path ) use ( &$paths ) {
			$paths[] = $path;
		} );

		$this->gateway()->register_routes();

		foreach ( [ '/agent/llms', '/agent/catalog', '/agent/search', '/agent/product', '/agent/checkout-handoff' ] as $expected ) {
			$this->assertContains( $expected, $paths );
		}
	}

	public function test_llms_is_grounded_text_pointing_at_the_feed(): void {
		Functions\when( 'get_bloginfo' )->justReturn( 'Test Store' );
		Functions\when( 'rest_url' )->alias( static fn ( $p ) => 'https://shop.test/wp-json/' . $p );

		$response = $this->gateway()->rest_llms( new WP_REST_Request() );
		$body     = $response->get_data();

		$this->assertStringContainsString( 'Test Store', $body );
		$this->assertStringContainsString( 'https://shop.test/wp-json/fahad-ai/v1/agent/catalog', $body );
		$this->assertStringContainsString( 'Do not fabricate', $body );
	}

	public function test_catalog_returns_a_grounded_feed_and_a_cache_header(): void {
		$product = Mockery::mock( 'WC_Product' );
		$product->shouldReceive( 'get_id' )->andReturn( 5 );
		$product->shouldReceive( 'get_name' )->andReturn( 'Mug' );
		$product->shouldReceive( 'get_price' )->andReturn( '12' );
		$product->shouldReceive( 'is_on_sale' )->andReturn( false );
		$product->shouldReceive( 'is_in_stock' )->andReturn( true );
		$product->shouldReceive( 'get_short_description' )->andReturn( '<b>Nice</b>' );

		// A non-product entry must be skipped (defensive grounding).
		Functions\when( 'wc_get_products' )->justReturn( [ $product, 'not-a-product' ] );
		Functions\when( 'get_permalink' )->justReturn( 'https://shop.test/product/mug' );

		$response = $this->gateway()->rest_catalog( new WP_REST_Request() );
		$data     = $response->get_data();

		$this->assertSame( 1, $data['count'] );
		$this->assertSame( 5, $data['products'][0]['id'] );
		$this->assertSame( 'Nice', $data['products'][0]['short_desc'] );
		$this->assertSame( 'public, max-age=300', $response->headers['Cache-Control'] );
	}

	public function test_search_reuses_the_real_search_products_tool(): void {
		$this->resetRegistry();
		// No matches -> the real search_products tool returns a grounded "0 found" result,
		// proving the gateway dispatches through the same tool the chat widget uses.
		Functions\when( 'apply_filters' )->alias( static fn ( $tag, $value = null ) => $value );
		Functions\when( 'wc_get_products' )->justReturn( [] );

		$out = $this->gateway()->rest_search( new WP_REST_Request( [ 'q' => 'nonexistent-xyz' ] ) );

		$this->assertIsArray( $out );
		$this->assertSame( 0, $out['found'] );
	}

	public function test_product_reuses_the_real_get_product_details_tool(): void {
		$this->resetRegistry();
		Functions\when( 'apply_filters' )->alias( static fn ( $tag, $value = null ) => $value );
		Functions\when( 'wc_get_product' )->justReturn( false ); // not found

		$out = $this->gateway()->rest_product( new WP_REST_Request( [ 'id' => 999999 ] ) );

		$this->assertIsArray( $out );
		$this->assertArrayHasKey( 'error', $out );
	}

	public function test_checkout_handoff_builds_human_add_to_cart_urls(): void {
		Functions\when( 'wc_get_cart_url' )->justReturn( 'https://shop.test/cart/' );
		Functions\when( 'add_query_arg' )->alias( static fn ( $k, $v, $url ) => $url . '?' . $k . '=' . $v );

		// Dedups and drops non-positive ids.
		$out = $this->gateway()->rest_checkout_handoff( new WP_REST_Request( [ 'ids' => '1, 2, 2, 0, x' ] ) );

		$this->assertCount( 2, $out['items'] );
		$this->assertSame( 1, $out['items'][0]['product_id'] );
		$this->assertSame( 'https://shop.test/cart/?add-to-cart=1', $out['items'][0]['add_to_cart'] );
		$this->assertStringContainsString( 'Payment is completed by the shopper', $out['note'] );
	}

	public function test_checkout_handoff_400_when_no_products(): void {
		$result = $this->gateway()->rest_checkout_handoff( new WP_REST_Request( [ 'ids' => '' ] ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'fahad_ai_no_products', $result->get_error_code() );
	}
}
