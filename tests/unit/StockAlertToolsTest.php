<?php
/**
 * Unit tests for Fahad_AI_Stock_Alert_Tools (issue #51: back-in-stock & price-drop
 * alerts — the agent TOOL `subscribe_stock_alert`).
 *
 * Red → Green → Refactor. Conventions mirror CatalogToolsTest / MemoryToolsTest:
 * WP/WC functions mocked via Brain\Monkey; WC objects via Mockery; the registry
 * singleton + its static pack list snapshotted/restored so a case here neither
 * inherits another suite's packs nor leaks the stock-alert pack we register; the
 * Fahad_AI_Stock_Alerts store singleton reset via reflection (NEVER
 * ReflectionMethod::setAccessible — host runs PHP 8.5).
 *
 * The tool is NOT a built-in — it ships as a drop-in feature pack that
 * self-registers via Fahad_AI_Tool_Registry::register_pack() at file load. Every
 * test registers the pack's REAL provider, then dispatches through
 * Fahad_AI_Tool_Registry::instance()->dispatch(), so the production
 * registration + merge + dispatch path is what is under test.
 *
 * NO FAKE SCARCITY IS THE POINT (issue #51, ROADMAP §6 anti-features). The headline
 * test is first-class: a back_in_stock alert is REFUSED for an item that is
 * currently IN stock — the tool never manufactures urgency, and only an
 * out-of-stock item (or a price_drop) is a valid back-in-stock subscription.
 * Consent is captured as a double-opt-in PENDING row (see Fahad_AI_Stock_Alerts),
 * and the shopper is told to confirm via email; PII (the raw email) is never echoed
 * back unmasked.
 */

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;

class StockAlertToolsTest extends TestCase {

	use MockeryPHPUnitIntegration;

	/**
	 * Snapshot of the registry's static pack providers, restored in tearDown.
	 *
	 * @var array<int, callable>
	 */
	private array $pack_snapshot = [];

	/** In-memory WP options table (the store is option-backed). @var array<string, mixed> */
	private array $options = [];

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$this->pack_snapshot = (array) ( new ReflectionProperty( Fahad_AI_Tool_Registry::class, 'pack_providers' ) )->getValue();

		$this->options = [];

		Functions\stubs( [
			'is_email'            => fn( $e ) => is_string( $e ) && (bool) preg_match( '/^[^@\s]+@[^@\s]+\.[^@\s]+$/', trim( $e ) ) ? trim( $e ) : false,
			'sanitize_email'      => fn( $e ) => is_string( $e ) ? trim( strtolower( $e ) ) : '',
			'sanitize_text_field' => fn( $s ) => is_string( $s ) ? trim( $s ) : $s,
			'absint'              => fn( $n ) => abs( (int) $n ),
			'wp_hash'             => fn( $s ) => 'hashed:' . $s,
			'home_url'            => fn( $p = '' ) => 'https://shop.test' . $p,
			'add_query_arg'       => fn( $args, $url ) => $url . '?' . http_build_query( $args ),
			'esc_url_raw'         => fn( $u ) => $u,
			'esc_url'             => fn( $u ) => $u,
			'wp_kses_post'        => fn( $s ) => $s,
			'get_bloginfo'        => fn( $k = '' ) => 'Test Shop',
		] );

		Functions\when( 'get_option' )->alias( fn( $name, $default = false ) => $this->options[ $name ] ?? $default );
		Functions\when( 'update_option' )->alias(
			function ( $name, $value ) {
				$this->options[ $name ] = $value;
				return true;
			}
		);
		Functions\when( 'delete_option' )->alias(
			function ( $name ) {
				unset( $this->options[ $name ] );
				return true;
			}
		);
		Functions\when( 'wp_mail' )->justReturn( true );
	}

	protected function tearDown(): void {
		( new ReflectionProperty( Fahad_AI_Tool_Registry::class, 'pack_providers' ) )->setValue( null, $this->pack_snapshot );
		( new ReflectionProperty( Fahad_AI_Stock_Alerts::class, 'instance' ) )->setValue( null, null );
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * Fresh registry whose built tool list includes the stock-alert tool, plus a
	 * fresh store singleton. Resets the Tools + registry + store singletons, then
	 * registers the pack's REAL provider — exactly what the file-scope
	 * self-registration does in production.
	 */
	private function registry(): Fahad_AI_Tool_Registry {
		( new ReflectionProperty( Fahad_AI_Tools::class, 'instance' ) )->setValue( null, null );
		( new ReflectionProperty( Fahad_AI_Tool_Registry::class, 'instance' ) )->setValue( null, null );
		( new ReflectionProperty( Fahad_AI_Stock_Alerts::class, 'instance' ) )->setValue( null, null );

		Fahad_AI_Tool_Registry::reset_packs();
		Fahad_AI_Tool_Registry::register_pack( [ 'Fahad_AI_Stock_Alert_Tools', 'register' ] );

		return Fahad_AI_Tool_Registry::instance();
	}

	/** Mock a product with a given in-stock state (and optional type/parent for variations). */
	private function mockProduct( int $id, bool $in_stock, string $type = 'simple', int $parent = 0 ): WC_Product {
		$p = Mockery::mock( WC_Product::class );
		$p->shouldReceive( 'get_id' )->andReturn( $id );
		$p->shouldReceive( 'is_in_stock' )->andReturn( $in_stock );
		$p->shouldReceive( 'get_type' )->andReturn( $type );
		$p->shouldReceive( 'get_parent_id' )->andReturn( $parent );
		$p->shouldReceive( 'get_name' )->andReturn( 'Test Product' );
		return $p;
	}

	/** Stored subscription rows (raw option value). */
	private function rows(): array {
		return $this->options[ Fahad_AI_Stock_Alerts::OPTION ] ?? [];
	}

	// ── registration ────────────────────────────────────────────────────────────

	public function test_subscribe_stock_alert_is_registered_via_register_pack(): void {
		$names = array_column( $this->registry()->specs(), 'name' );

		$this->assertContains( 'subscribe_stock_alert', $names );
		// Additive: the five built-ins remain.
		$this->assertContains( 'search_products', $names );
	}

	public function test_tool_spec_never_leaks_a_callback(): void {
		$specs = array_column( $this->registry()->specs(), null, 'name' );

		$this->assertArrayHasKey( 'subscribe_stock_alert', $specs );
		$this->assertArrayNotHasKey( 'callback', $specs['subscribe_stock_alert'] );
		// Public, NOT personal — guests can subscribe by email — so no `personal` key.
		$this->assertArrayNotHasKey( 'personal', $specs['subscribe_stock_alert'] );
		$this->assertSame( 'object', $specs['subscribe_stock_alert']['parameters']['type'] );
	}

	// ── NO FAKE SCARCITY: refuse a back_in_stock alert for an IN-STOCK item ──────

	/**
	 * THE headline anti-scarcity test. The product is currently IN stock, so a
	 * back-in-stock alert is meaningless and must be REFUSED — the assistant never
	 * manufactures urgency around an available item. Nothing is stored.
	 */
	public function test_refuses_back_in_stock_alert_for_an_in_stock_item(): void {
		Functions\when( 'wc_get_product' )->justReturn( $this->mockProduct( 42, true ) );

		$res = $this->registry()->dispatch( 'subscribe_stock_alert', [
			'product_id' => 42,
			'email'      => 'jane@example.com',
			'type'       => 'back_in_stock',
		] );

		// Refused with a clear, grounded reason; no subscription created.
		$this->assertTrue( $res['refused'] ?? false );
		$this->assertArrayNotHasKey( 'subscribed', $res );
		$this->assertSame( [], $this->rows() );
	}

	/**
	 * The flip side: an OUT-OF-STOCK item is a valid back-in-stock subscription. It
	 * records a PENDING (double-opt-in) row and tells the shopper to confirm by
	 * email — it does NOT activate the alert immediately.
	 */
	public function test_records_pending_subscription_for_an_out_of_stock_item(): void {
		Functions\when( 'wc_get_product' )->justReturn( $this->mockProduct( 42, false ) );

		$res = $this->registry()->dispatch( 'subscribe_stock_alert', [
			'product_id' => 42,
			'email'      => 'jane@example.com',
			'type'       => 'back_in_stock',
		] );

		$this->assertTrue( $res['subscribed'] ?? false );
		// The shopper is told it is PENDING confirmation (double opt-in).
		$this->assertTrue( $res['pending'] ?? false );
		$this->assertArrayHasKey( 'message', $res );

		$rows = $this->rows();
		$this->assertCount( 1, $rows );
		$row = reset( $rows );
		$this->assertSame( 'pending', $row['status'] );
		$this->assertSame( 'back_in_stock', $row['type'] );
	}

	// ── email validation + dedupe (through the tool) ─────────────────────────────

	public function test_rejects_an_invalid_email(): void {
		Functions\when( 'wc_get_product' )->justReturn( $this->mockProduct( 42, false ) );

		$res = $this->registry()->dispatch( 'subscribe_stock_alert', [
			'product_id' => 42,
			'email'      => 'nope',
			'type'       => 'back_in_stock',
		] );

		$this->assertArrayHasKey( 'error', $res );
		$this->assertArrayNotHasKey( 'subscribed', $res );
		$this->assertSame( [], $this->rows() );
	}

	public function test_subscribing_twice_dedupes_to_one_pending_row(): void {
		Functions\when( 'wc_get_product' )->justReturn( $this->mockProduct( 42, false ) );

		$reg = $this->registry();
		$reg->dispatch( 'subscribe_stock_alert', [ 'product_id' => 42, 'email' => 'jane@example.com' ] );
		$reg->dispatch( 'subscribe_stock_alert', [ 'product_id' => 42, 'email' => 'JANE@example.com' ] );

		$this->assertCount( 1, $this->rows() );
	}

	// ── price_drop is valid regardless of stock state ────────────────────────────

	public function test_price_drop_alert_is_allowed_for_an_in_stock_item(): void {
		// A price-drop watch on an in-stock item is legitimate (not scarcity), so it
		// is NOT refused — it records a pending subscription like any other.
		Functions\when( 'wc_get_product' )->justReturn( $this->mockProduct( 42, true ) );

		$res = $this->registry()->dispatch( 'subscribe_stock_alert', [
			'product_id' => 42,
			'email'      => 'jane@example.com',
			'type'       => 'price_drop',
		] );

		$this->assertTrue( $res['subscribed'] ?? false );
		$row = reset( $this->options[ Fahad_AI_Stock_Alerts::OPTION ] );
		$this->assertSame( 'price_drop', $row['type'] );
	}

	// ── grounding: a missing product is reported, not faked ──────────────────────

	public function test_unknown_product_is_reported_not_subscribed(): void {
		Functions\when( 'wc_get_product' )->justReturn( false );

		$res = $this->registry()->dispatch( 'subscribe_stock_alert', [
			'product_id' => 999999,
			'email'      => 'jane@example.com',
		] );

		$this->assertArrayHasKey( 'error', $res );
		$this->assertArrayNotHasKey( 'subscribed', $res );
		$this->assertSame( [], $this->rows() );
	}

	// ── PII: the raw email is never echoed back unmasked ─────────────────────────

	public function test_does_not_echo_the_raw_email_back(): void {
		Functions\when( 'wc_get_product' )->justReturn( $this->mockProduct( 42, false ) );

		$res = $this->registry()->dispatch( 'subscribe_stock_alert', [
			'product_id' => 42,
			'email'      => 'jane@example.com',
		] );

		// The full raw address must not appear in the model-facing result (PII).
		$this->assertStringNotContainsString( 'jane@example.com', json_encode( $res ) );
	}
}
