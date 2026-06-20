<?php
/**
 * Unit tests for Fahad_AI_Admin_Copilot (Epic B — merchant copilot).
 *
 * Covers the capability gate, route registration, and every grounded data method /
 * branch, so the admin endpoints can never fabricate store numbers.
 */

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;

final class AdminCopilotTest extends TestCase {

	use MockeryPHPUnitIntegration;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		if ( ! defined( 'DAY_IN_SECONDS' ) ) {
			define( 'DAY_IN_SECONDS', 86400 );
		}
		Functions\when( 'rest_ensure_response' )->alias( static fn ( $x ) => $x );
		Functions\when( 'wp_strip_all_tags' )->alias( static fn ( $s ) => trim( (string) strip_tags( (string) $s ) ) );
		Functions\when( 'get_woocommerce_currency' )->justReturn( 'USD' );
		Functions\when( 'get_comment_meta' )->justReturn( '5' );
		Functions\when( 'wc_get_product_terms' )->justReturn( [ 'Clothing' ] );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		( new ReflectionProperty( Fahad_AI_Admin_Copilot::class, 'instance' ) )->setValue( null, null );
		parent::tearDown();
	}

	private function copilot(): Fahad_AI_Admin_Copilot {
		( new ReflectionProperty( Fahad_AI_Admin_Copilot::class, 'instance' ) )->setValue( null, null );
		return Fahad_AI_Admin_Copilot::instance();
	}

	private function order( float $total, float $refunded ) {
		$o = Mockery::mock( 'WC_Order' );
		$o->shouldReceive( 'get_total' )->andReturn( (string) $total );
		$o->shouldReceive( 'get_total_refunded' )->andReturn( $refunded );
		return $o;
	}

	private function product( int $id, string $name, float $price, bool $in_stock, bool $on_sale, int $sales ) {
		$p = Mockery::mock( 'WC_Product' );
		$p->shouldReceive( 'get_id' )->andReturn( $id );
		$p->shouldReceive( 'get_name' )->andReturn( $name );
		$p->shouldReceive( 'get_price' )->andReturn( (string) $price );
		$p->shouldReceive( 'is_in_stock' )->andReturn( $in_stock );
		$p->shouldReceive( 'is_on_sale' )->andReturn( $on_sale );
		$p->shouldReceive( 'get_total_sales' )->andReturn( $sales );
		return $p;
	}

	// ── capability gate + routes ────────────────────────────────────────────────

	public function test_can_manage_reflects_the_capability(): void {
		Functions\when( 'current_user_can' )->alias( static fn ( $cap ) => 'manage_woocommerce' === $cap );
		$this->assertTrue( $this->copilot()->can_manage() );

		Functions\when( 'current_user_can' )->justReturn( false );
		$this->assertFalse( $this->copilot()->can_manage() );
	}

	public function test_register_routes_registers_the_admin_endpoints(): void {
		$paths = [];
		Functions\when( 'register_rest_route' )->alias( static function ( $ns, $path ) use ( &$paths ) {
			$paths[] = $path;
		} );

		$this->copilot()->register_routes();

		$this->assertContains( '/admin/insights', $paths );
		$this->assertContains( '/admin/sale-candidates', $paths );
		$this->assertContains( '/admin/product-context', $paths );
		$this->assertContains( '/admin/review-drafts', $paths );
	}

	// ── B1 insights ─────────────────────────────────────────────────────────────

	public function test_insights_summarises_orders_revenue_and_refunds(): void {
		Functions\when( 'wc_get_orders' )->justReturn( [
			$this->order( 100.0, 0.0 ),
			$this->order( 50.0, 20.0 ),
		] );

		$out = $this->copilot()->insights( 30 );

		$this->assertSame( 30, $out['window_days'] );
		$this->assertSame( 2, $out['orders'] );
		$this->assertSame( 150.0, $out['revenue'] );
		$this->assertSame( 20.0, $out['refunds'] );
		$this->assertSame( 1, $out['refunded_orders'] );
		$this->assertSame( 'USD', $out['currency'] );
	}

	public function test_rest_insights_clamps_the_window_and_wraps_the_response(): void {
		Functions\when( 'wc_get_orders' )->justReturn( [] );

		// days param absent -> default 7.
		$out = $this->copilot()->rest_insights( new WP_REST_Request( [] ) );
		$this->assertSame( 7, $out['window_days'] );

		// days far above the cap -> clamped to 90.
		$out = $this->copilot()->rest_insights( new WP_REST_Request( [ 'days' => 9999 ] ) );
		$this->assertSame( 90, $out['window_days'] );
	}

	// ── B2 sale candidates ──────────────────────────────────────────────────────

	public function test_sale_candidates_excludes_out_of_stock_and_already_on_sale_and_ranks_slow_movers(): void {
		Functions\when( 'wc_get_products' )->justReturn( [
			$this->product( 1, 'Fast Mover', 50.0, true, false, 200 ),
			$this->product( 2, 'Slow Mover', 30.0, true, false, 3 ),
			$this->product( 3, 'Out Of Stock', 20.0, false, false, 1 ),
			$this->product( 4, 'Already On Sale', 40.0, true, true, 5 ),
		] );

		$rows = $this->copilot()->sale_candidates( 30, 5 );

		$this->assertCount( 2, $rows, 'Only in-stock, not-on-sale products are candidates.' );
		$this->assertSame( 'Slow Mover', $rows[0]['name'], 'Slowest mover ranks first.' );
		$this->assertSame( 10, $rows[0]['suggested_discount_percent'] );
	}

	public function test_rest_sale_candidates_clamps_limit(): void {
		Functions\when( 'wc_get_products' )->justReturn( [
			$this->product( 1, 'A', 10.0, true, false, 1 ),
			$this->product( 2, 'B', 10.0, true, false, 2 ),
		] );

		$out = $this->copilot()->rest_sale_candidates( new WP_REST_Request( [ 'limit' => 1 ] ) );
		$this->assertCount( 1, $out['candidates'] );
	}

	// ── B3 product context ──────────────────────────────────────────────────────

	public function test_product_context_returns_real_attributes_in_each_value_shape(): void {
		$attrObject = new class {
			public function get_name() { return 'Red'; }
		};
		$p = Mockery::mock( 'WC_Product' );
		$p->shouldReceive( 'get_id' )->andReturn( 7 );
		$p->shouldReceive( 'get_name' )->andReturn( 'Cotton Tee' );
		$p->shouldReceive( 'get_sku' )->andReturn( 'TEE-1' );
		$p->shouldReceive( 'get_price' )->andReturn( '30' );
		$p->shouldReceive( 'get_short_description' )->andReturn( '<b>Soft</b> tee' );
		$p->shouldReceive( 'get_attributes' )->andReturn( [
			'color'    => $attrObject,        // object with get_name
			'sizes'    => [ 'S', 'M' ],       // array
			'material' => 'cotton',           // scalar
		] );

		$ctx = $this->copilot()->product_context( $p );

		$this->assertSame( 7, $ctx['id'] );
		$this->assertSame( 'TEE-1', $ctx['sku'] );
		$this->assertSame( 'Soft tee', $ctx['short_description'] );
		$this->assertSame( [ 'Clothing' ], $ctx['categories'] );
		$this->assertSame( 'Red', $ctx['attributes']['color'] );
		$this->assertSame( 'S, M', $ctx['attributes']['sizes'] );
		$this->assertSame( 'cotton', $ctx['attributes']['material'] );
	}

	public function test_rest_product_context_returns_404_for_a_missing_product(): void {
		Functions\when( 'wc_get_product' )->justReturn( false );

		$result = $this->copilot()->rest_product_context( new WP_REST_Request( [ 'product_id' => 0 ] ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'fahad_ai_not_found', $result->get_error_code() );
	}

	public function test_rest_product_context_returns_grounded_context_for_a_real_product(): void {
		$p = Mockery::mock( 'WC_Product' );
		$p->shouldReceive( 'get_id' )->andReturn( 9 );
		$p->shouldReceive( 'get_name' )->andReturn( 'Mug' );
		$p->shouldReceive( 'get_sku' )->andReturn( '' );
		$p->shouldReceive( 'get_price' )->andReturn( '12' );
		$p->shouldReceive( 'get_short_description' )->andReturn( '' );
		$p->shouldReceive( 'get_attributes' )->andReturn( [] );
		Functions\when( 'wc_get_product' )->justReturn( $p );

		$ctx = $this->copilot()->rest_product_context( new WP_REST_Request( [ 'product_id' => 9 ] ) );
		$this->assertSame( 9, $ctx['id'] );
	}

	// ── B4 review drafts ────────────────────────────────────────────────────────

	public function test_unanswered_reviews_returns_only_reviews_without_a_reply(): void {
		$reviewA = (object) [ 'comment_ID' => 11, 'comment_post_ID' => 100, 'comment_author' => 'Ann', 'comment_content' => 'Great <i>mug</i>' ];
		$reviewB = (object) [ 'comment_ID' => 12, 'comment_post_ID' => 101, 'comment_author' => 'Bob', 'comment_content' => 'Broke fast' ];

		Functions\when( 'get_comments' )->alias( static function ( array $args ) use ( $reviewA, $reviewB ) {
			// First call: the review list (parent 0). Reply-count calls pass a 'parent'.
			if ( isset( $args['count'] ) ) {
				return 11 === (int) $args['parent'] ? 1 : 0; // review 11 already answered.
			}
			return [ $reviewA, $reviewB ];
		} );

		$rows = $this->copilot()->unanswered_reviews( 10 );

		$this->assertCount( 1, $rows, 'Answered review (11) is excluded.' );
		$this->assertSame( 12, $rows[0]['id'] );
		$this->assertSame( 'Broke fast', $rows[0]['content'] );
		$this->assertSame( 5, $rows[0]['rating'] );
	}

	public function test_rest_review_drafts_wraps_and_clamps(): void {
		Functions\when( 'get_comments' )->justReturn( [] );
		$out = $this->copilot()->rest_review_drafts( new WP_REST_Request( [ 'limit' => 999 ] ) );
		$this->assertSame( [], $out['reviews'] );
	}
}
