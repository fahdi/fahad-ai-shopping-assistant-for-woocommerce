<?php
/**
 * Coverage-focused unit tests for Fahad_AI_Stock_Alerts (issue #51 store/notifier).
 *
 * Sibling StockAlertsTest.php already exercises the happy-path lifecycle (subscribe /
 * confirm / unsubscribe / notify / erase / tokens). THIS file drives the remaining
 * guard clauses, branch paths and WooCommerce/WordPress hook callbacks that the
 * sibling does not reach:
 *   - init_hooks() wires the WC + WP hooks (asserted via captured add_action/add_filter).
 *   - subscribe() refuses once MAX_SUBSCRIPTIONS is reached.
 *   - confirm()/unsubscribe() on a missing id are a no-op even with a valid token.
 *   - erase_email() short-circuits on an empty (sanitized-away) address.
 *   - dispatch_notifications() skips type-mismatch and variation-mismatch rows.
 *   - on_product_stock_change() ignores a non-product / out-of-stock object and routes
 *     a variation to its parent vs. a simple product to itself.
 *   - snapshot_price() / on_product_price_change() snapshot then compare on save.
 *   - maybe_handle_request() resolves confirm/unsubscribe/unknown/empty actions and
 *     renders the page via wp_die.
 *   - register_eraser() + gdpr_erase() expose the WP personal-data eraser.
 *
 * Conventions mirror StockAlertsTest / ApiHandlerTest: Brain\Monkey for WP functions,
 * the MockeryPHPUnitIntegration trait, an in-memory options map, and the singleton
 * reset via ReflectionProperty (NEVER setAccessible — host runs PHP 8.5).
 */

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;

class CoverageStockAlertsTest extends TestCase {

	use MockeryPHPUnitIntegration;

	/** @var array<string, mixed> In-memory stand-in for the WP options table. */
	private array $options = [];

	/** @var array<int, array{0:string,1:string,2:string}> Captured wp_mail() calls. */
	private array $mail = [];

	/** @var array<int, array{0:string,1:mixed,2:int}> Captured add_action() calls. */
	private array $actions = [];

	/** @var array<int, array{0:string,1:mixed}> Captured add_filter() calls. */
	private array $filters = [];

	/** @var array<int, array{0:string,1:string}> Captured wp_die() calls [ message, title ]. */
	private array $died = [];

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$this->options = [];
		$this->mail    = [];
		$this->actions = [];
		$this->filters = [];
		$this->died    = [];

		Functions\stubs( [
			'is_email'            => fn( $e ) => is_string( $e ) && (bool) preg_match( '/^[^@\s]+@[^@\s]+\.[^@\s]+$/', trim( $e ) ) ? trim( $e ) : false,
			'sanitize_email'      => fn( $e ) => is_string( $e ) ? trim( strtolower( $e ) ) : '',
			'sanitize_text_field' => fn( $s ) => is_string( $s ) ? trim( $s ) : $s,
			'wp_unslash'          => fn( $s ) => $s,
			'absint'              => fn( $n ) => abs( (int) $n ),
			'wp_hash'             => fn( $s ) => 'hashed:' . $s,
			'home_url'            => fn( $p = '' ) => 'https://shop.test' . $p,
			'add_query_arg'       => fn( $args, $url ) => $url . '?' . http_build_query( $args ),
			'esc_url_raw'         => fn( $u ) => $u,
			'esc_url'             => fn( $u ) => $u,
			'esc_html'            => fn( $s ) => $s,
			'wp_kses_post'        => fn( $s ) => $s,
			'get_bloginfo'        => fn( $k = '' ) => 'Test Shop',
		] );

		Functions\when( 'get_option' )->alias(
			fn( $name, $default = false ) => $this->options[ $name ] ?? $default
		);
		Functions\when( 'update_option' )->alias(
			function ( $name, $value ) {
				$this->options[ $name ] = $value;
				return true;
			}
		);
		Functions\when( 'wp_mail' )->alias(
			function ( $to, $subject, $body, $headers = '' ) {
				$this->mail[] = [ (string) $to, (string) $subject, (string) $body ];
				return true;
			}
		);

		// Hook seams: capture registrations so init_hooks() can be asserted.
		Functions\when( 'add_action' )->alias(
			function ( $hook, $cb, $priority = 10, $args = 1 ) {
				$this->actions[] = [ (string) $hook, $cb, (int) $priority ];
				return true;
			}
		);
		Functions\when( 'add_filter' )->alias(
			function ( $hook, $cb, $priority = 10, $args = 1 ) {
				$this->filters[] = [ (string) $hook, $cb ];
				return true;
			}
		);

		// wp_die seam: render_page() calls it. Record instead of exiting.
		Functions\when( 'wp_die' )->alias(
			function ( $message = '', $title = '', $args = [] ) {
				$this->died[] = [ (string) $message, (string) $title ];
			}
		);
	}

	protected function tearDown(): void {
		( new ReflectionProperty( Fahad_AI_Stock_Alerts::class, 'instance' ) )->setValue( null, null );
		$_GET = [];
		Monkey\tearDown();
		parent::tearDown();
	}

	/** Fresh store singleton (reset between cases via reflection). */
	private function store(): Fahad_AI_Stock_Alerts {
		( new ReflectionProperty( Fahad_AI_Stock_Alerts::class, 'instance' ) )->setValue( null, null );
		return Fahad_AI_Stock_Alerts::instance();
	}

	/** All stored subscription rows (the raw option value). */
	private function rows(): array {
		return $this->options[ Fahad_AI_Stock_Alerts::OPTION ] ?? [];
	}

	/** Build a confirmed row directly in the option store for notifier tests. */
	private function seedConfirmed( int $product_id, int $variation_id, string $type, string $email = 'who@x.com', int $sent = 0 ): string {
		$store = Fahad_AI_Stock_Alerts::instance();
		$id    = $store->subscribe( $product_id, $email, $variation_id, $type )['id'];
		$store->confirm( $id, $store->token( 'confirm', $id ) );
		if ( $sent > 0 ) {
			$subs                  = $this->options[ Fahad_AI_Stock_Alerts::OPTION ];
			$subs[ $id ]['sent']   = $sent;
			$this->options[ Fahad_AI_Stock_Alerts::OPTION ] = $subs;
		}
		return $id;
	}

	// ── init_hooks(): wires every WC + WP hook ──────────────────────────────────

	public function test_init_hooks_registers_all_woocommerce_and_wp_hooks(): void {
		// Reset singleton so instance() inside init_hooks builds a clean object.
		( new ReflectionProperty( Fahad_AI_Stock_Alerts::class, 'instance' ) )->setValue( null, null );

		Fahad_AI_Stock_Alerts::init_hooks();

		$hooks = array_column( $this->actions, 0 );
		$this->assertContains( 'woocommerce_product_set_stock', $hooks );
		$this->assertContains( 'woocommerce_variation_set_stock', $hooks );
		$this->assertContains( 'woocommerce_before_product_object_save', $hooks );
		$this->assertContains( 'woocommerce_update_product', $hooks );
		$this->assertContains( 'woocommerce_update_product_variation', $hooks );
		$this->assertContains( 'init', $hooks );

		// The GDPR eraser is a filter, not an action.
		$this->assertContains( 'wp_privacy_personal_data_erasers', array_column( $this->filters, 0 ) );

		// All callbacks target the same singleton instance.
		$self = Fahad_AI_Stock_Alerts::instance();
		foreach ( $this->actions as [ $hook, $cb, $priority ] ) {
			$this->assertSame( $self, $cb[0] );
		}
	}

	// ── subscribe(): MAX_SUBSCRIPTIONS guard ────────────────────────────────────

	public function test_subscribe_refuses_a_new_watch_once_the_cap_is_reached(): void {
		// Pre-fill the option with exactly MAX_SUBSCRIPTIONS rows (none matching the
		// new dedupe id), so a genuinely new subscribe is over the cap.
		$full = [];
		for ( $i = 0; $i < Fahad_AI_Stock_Alerts::MAX_SUBSCRIPTIONS; $i++ ) {
			$full[ 'row' . $i ] = [ 'email' => "f$i@x.com", 'type' => 'back_in_stock', 'product_id' => $i ];
		}
		$this->options[ Fahad_AI_Stock_Alerts::OPTION ] = $full;

		$res = $this->store()->subscribe( 777, 'new@x.com', 0, 'back_in_stock' );

		$this->assertFalse( $res['ok'] ?? true );
		$this->assertArrayHasKey( 'error', $res );
		// Nothing added: still exactly the cap. Assert the integer count (not assertCount
		// on the 5000-row array) so the assertion event does not export the whole array
		// and exhaust memory under coverage collection.
		$this->assertSame( Fahad_AI_Stock_Alerts::MAX_SUBSCRIPTIONS, count( $this->rows() ) );
	}

	public function test_subscribe_at_cap_still_dedupes_an_existing_watch_idempotently(): void {
		// An existing row at the cap can be re-subscribed (the cap only blocks NEW ids).
		$store = $this->store();
		$first = $store->subscribe( 42, 'jane@example.com', 0, 'back_in_stock' );
		$id    = $first['id'];

		// Pad the option up to the cap WITHOUT removing the existing row.
		$subs = $this->options[ Fahad_AI_Stock_Alerts::OPTION ];
		for ( $i = count( $subs ); $i < Fahad_AI_Stock_Alerts::MAX_SUBSCRIPTIONS; $i++ ) {
			$subs[ 'pad' . $i ] = [ 'email' => "p$i@x.com", 'type' => 'price_drop', 'product_id' => $i ];
		}
		$this->options[ Fahad_AI_Stock_Alerts::OPTION ] = $subs;

		$again = $store->subscribe( 42, 'jane@example.com', 0, 'back_in_stock' );

		$this->assertTrue( $again['ok'] ?? false );
		$this->assertSame( $id, $again['id'] );
	}

	// ── confirm()/unsubscribe(): valid token but missing id is a no-op ───────────

	public function test_confirm_with_valid_token_but_unknown_id_returns_false(): void {
		$store = $this->store();
		$id    = 'ghost-id';
		// A correctly-signed token for an id that was never stored.
		$ok = $store->confirm( $id, $store->token( 'confirm', $id ) );

		$this->assertFalse( $ok );
		$this->assertSame( [], $this->rows() );
	}

	public function test_unsubscribe_with_valid_token_but_unknown_id_returns_false(): void {
		$store = $this->store();
		$id    = 'ghost-id';
		$ok    = $store->unsubscribe( $id, $store->token( 'unsubscribe', $id ) );

		$this->assertFalse( $ok );
		$this->assertSame( [], $this->rows() );
	}

	// ── erase_email(): empty address short-circuits ─────────────────────────────

	public function test_erase_email_returns_zero_for_an_empty_address(): void {
		$store = $this->store();
		$store->subscribe( 42, 'jane@example.com', 0, 'back_in_stock' );

		// sanitize_email('   ') trims to '' → guard returns 0 without touching rows.
		$removed = $store->erase_email( '   ' );

		$this->assertSame( 0, $removed );
		$this->assertCount( 1, $this->rows() );
	}

	// ── dispatch_notifications(): type + variation mismatch are skipped ──────────

	public function test_notify_skips_rows_of_a_different_type(): void {
		$store = $this->store();

		// A confirmed PRICE_DROP watcher on product 42 must be skipped by a
		// back-in-stock dispatch (type mismatch → continue).
		$priceId = $this->seedConfirmed( 42, 0, 'price_drop', 'price@x.com' );
		// A confirmed BACK_IN_STOCK watcher that SHOULD be emailed.
		$stockId = $this->seedConfirmed( 42, 0, 'back_in_stock', 'stock@x.com' );

		$count = $store->notify_back_in_stock( 42, 0 );

		$this->assertSame( 1, $count );
		$this->assertCount( 1, $this->mail );
		$this->assertSame( 'stock@x.com', $this->mail[0][0] );
		// The price-drop row was not marked sent (skipped, not notified).
		$this->assertEmpty( $this->rows()[ $priceId ]['sent'] ?? 0 );
		$this->assertNotEmpty( $this->rows()[ $stockId ]['sent'] );
	}

	public function test_notify_skips_rows_for_a_different_variation(): void {
		$store = $this->store();

		// Same product, same type, but a DIFFERENT variation id → variation mismatch.
		$variationId = $this->seedConfirmed( 42, 7, 'back_in_stock', 'var7@x.com' );
		// The product-level (variation 0) watcher that matches the event.
		$productId = $this->seedConfirmed( 42, 0, 'back_in_stock', 'prod@x.com' );

		$count = $store->notify_back_in_stock( 42, 0 );

		$this->assertSame( 1, $count );
		$this->assertSame( 'prod@x.com', $this->mail[0][0] );
		$this->assertEmpty( $this->rows()[ $variationId ]['sent'] ?? 0 );
		$this->assertNotEmpty( $this->rows()[ $productId ]['sent'] );
	}

	// ── on_product_stock_change(): guards + variation/simple routing ─────────────

	public function test_on_product_stock_change_ignores_a_non_product(): void {
		$store = $this->store();
		$this->seedConfirmed( 42, 0, 'back_in_stock' );

		$store->on_product_stock_change( 'not-a-product' );

		$this->assertCount( 0, $this->mail );
	}

	public function test_on_product_stock_change_ignores_an_out_of_stock_product(): void {
		$store = $this->store();
		$this->seedConfirmed( 42, 0, 'back_in_stock' );

		$product = Mockery::mock( WC_Product::class );
		$product->shouldReceive( 'is_in_stock' )->andReturn( false );

		$store->on_product_stock_change( $product );

		$this->assertCount( 0, $this->mail );
	}

	public function test_on_product_stock_change_notifies_a_simple_product(): void {
		$store = $this->store();
		$id    = $this->seedConfirmed( 42, 0, 'back_in_stock', 'simple@x.com' );

		$product = Mockery::mock( WC_Product::class );
		$product->shouldReceive( 'is_in_stock' )->andReturn( true );
		$product->shouldReceive( 'get_id' )->andReturn( 42 );
		$product->shouldReceive( 'get_parent_id' )->andReturn( 0 );
		$product->shouldReceive( 'is_type' )->with( 'variation' )->andReturn( false );

		$store->on_product_stock_change( $product );

		$this->assertCount( 1, $this->mail );
		$this->assertSame( 'simple@x.com', $this->mail[0][0] );
		$this->assertNotEmpty( $this->rows()[ $id ]['sent'] );
	}

	public function test_on_product_stock_change_routes_a_variation_to_its_parent(): void {
		$store = $this->store();
		// Watcher is on parent 42, variation 7.
		$id = $this->seedConfirmed( 42, 7, 'back_in_stock', 'variation@x.com' );

		$product = Mockery::mock( WC_Product::class );
		$product->shouldReceive( 'is_in_stock' )->andReturn( true );
		$product->shouldReceive( 'get_id' )->andReturn( 7 );      // variation id
		$product->shouldReceive( 'get_parent_id' )->andReturn( 42 ); // parent product
		$product->shouldReceive( 'is_type' )->with( 'variation' )->andReturn( true );

		$store->on_product_stock_change( $product );

		$this->assertCount( 1, $this->mail );
		$this->assertSame( 'variation@x.com', $this->mail[0][0] );
		$this->assertNotEmpty( $this->rows()[ $id ]['sent'] );
	}

	// ── snapshot_price() / on_product_price_change() ─────────────────────────────

	public function test_snapshot_price_ignores_a_non_product(): void {
		$store = $this->store();
		// No snapshot stored → a later price-change for any id is a no-op.
		$store->snapshot_price( 'not-a-product' );

		$store->on_product_price_change( 99 );
		$this->assertCount( 0, $this->mail );
	}

	public function test_snapshot_price_ignores_a_product_with_no_id(): void {
		$store = $this->store();
		$product = Mockery::mock( WC_Product::class );
		$product->shouldReceive( 'get_id' )->andReturn( 0 );
		// get_price must not be relied on; guard is on the id.
		$store->snapshot_price( $product );

		// Nothing snapshotted → a price-change for id 0 short-circuits on the id guard.
		$store->on_product_price_change( 0 );
		$this->assertCount( 0, $this->mail );
	}

	public function test_on_product_price_change_without_a_snapshot_is_a_no_op(): void {
		$store = $this->store();
		$this->seedConfirmed( 42, 0, 'price_drop' );

		// No snapshot for 42 → array_key_exists guard returns early.
		$store->on_product_price_change( 42 );

		$this->assertCount( 0, $this->mail );
	}

	public function test_on_product_price_change_returns_when_product_lookup_fails(): void {
		$store = $this->store();

		$snap = Mockery::mock( WC_Product::class );
		$snap->shouldReceive( 'get_id' )->andReturn( 42 );
		$snap->shouldReceive( 'get_price' )->andReturn( '50.00' );
		$store->snapshot_price( $snap );

		// wc_get_product returns a non-product → guard returns, snapshot consumed.
		Functions\when( 'wc_get_product' )->justReturn( null );
		$store->on_product_price_change( 42 );

		$this->assertCount( 0, $this->mail );
	}

	public function test_on_product_price_change_notifies_on_a_real_drop_for_a_simple_product(): void {
		$store = $this->store();
		$id    = $this->seedConfirmed( 42, 0, 'price_drop', 'drop@x.com' );

		$snap = Mockery::mock( WC_Product::class );
		$snap->shouldReceive( 'get_id' )->andReturn( 42 );
		$snap->shouldReceive( 'get_price' )->andReturn( '50.00' );
		$store->snapshot_price( $snap );

		$saved = Mockery::mock( WC_Product::class );
		$saved->shouldReceive( 'get_price' )->andReturn( '40.00' );
		$saved->shouldReceive( 'get_parent_id' )->andReturn( 0 );
		$saved->shouldReceive( 'is_type' )->with( 'variation' )->andReturn( false );
		Functions\when( 'wc_get_product' )->justReturn( $saved );

		$store->on_product_price_change( 42 );

		$this->assertCount( 1, $this->mail );
		$this->assertSame( 'drop@x.com', $this->mail[0][0] );
		$this->assertNotEmpty( $this->rows()[ $id ]['sent'] );
	}

	public function test_on_product_price_change_routes_a_variation_drop_to_its_parent(): void {
		$store = $this->store();
		$id    = $this->seedConfirmed( 42, 7, 'price_drop', 'vardrop@x.com' );

		$snap = Mockery::mock( WC_Product::class );
		$snap->shouldReceive( 'get_id' )->andReturn( 7 );
		$snap->shouldReceive( 'get_price' )->andReturn( '30.00' );
		$store->snapshot_price( $snap );

		$saved = Mockery::mock( WC_Product::class );
		$saved->shouldReceive( 'get_price' )->andReturn( '20.00' );
		$saved->shouldReceive( 'get_parent_id' )->andReturn( 42 );
		$saved->shouldReceive( 'is_type' )->with( 'variation' )->andReturn( true );
		Functions\when( 'wc_get_product' )->justReturn( $saved );

		$store->on_product_price_change( 7 );

		$this->assertCount( 1, $this->mail );
		$this->assertSame( 'vardrop@x.com', $this->mail[0][0] );
		$this->assertNotEmpty( $this->rows()[ $id ]['sent'] );
	}

	public function test_on_product_price_change_no_notify_when_price_did_not_drop(): void {
		$store = $this->store();
		$id    = $this->seedConfirmed( 42, 0, 'price_drop', 'flat@x.com' );

		$snap = Mockery::mock( WC_Product::class );
		$snap->shouldReceive( 'get_id' )->andReturn( 42 );
		$snap->shouldReceive( 'get_price' )->andReturn( '40.00' );
		$store->snapshot_price( $snap );

		$saved = Mockery::mock( WC_Product::class );
		$saved->shouldReceive( 'get_price' )->andReturn( '45.00' ); // price rose
		$saved->shouldReceive( 'get_parent_id' )->andReturn( 0 );
		$saved->shouldReceive( 'is_type' )->with( 'variation' )->andReturn( false );
		Functions\when( 'wc_get_product' )->justReturn( $saved );

		$store->on_product_price_change( 42 );

		$this->assertCount( 0, $this->mail );
		$this->assertEmpty( $this->rows()[ $id ]['sent'] ?? 0 );
	}

	// ── maybe_handle_request(): init router + render_page ───────────────────────

	public function test_maybe_handle_request_does_nothing_without_the_query_var(): void {
		$store  = $this->store();
		$_GET   = [];
		$store->maybe_handle_request();
		$this->assertCount( 0, $this->died );
	}

	public function test_maybe_handle_request_does_nothing_for_an_unknown_action(): void {
		$store = $this->store();
		$_GET  = [ Fahad_AI_Stock_Alerts::QUERY_VAR => 'bogus', 'sub' => 'x', 'token' => 'y' ];

		$store->maybe_handle_request();

		// Neither confirm nor unsubscribe branch ran → no page rendered.
		$this->assertCount( 0, $this->died );
	}

	public function test_maybe_handle_request_confirm_success_renders_a_page(): void {
		$store = $this->store();
		$id    = $store->subscribe( 42, 'jane@example.com', 0, 'back_in_stock' )['id'];
		$token = $store->token( 'confirm', $id );

		$_GET = [ Fahad_AI_Stock_Alerts::QUERY_VAR => 'confirm', 'sub' => $id, 'token' => $token ];
		$store->maybe_handle_request();

		$this->assertCount( 1, $this->died );
		$this->assertStringContainsString( 'confirmed', $this->died[0][0] );
		$this->assertSame( 'confirmed', $this->rows()[ $id ]['status'] );
	}

	public function test_maybe_handle_request_confirm_failure_renders_invalid_message(): void {
		$store = $this->store();
		$id    = $store->subscribe( 42, 'jane@example.com', 0, 'back_in_stock' )['id'];

		$_GET = [ Fahad_AI_Stock_Alerts::QUERY_VAR => 'confirm', 'sub' => $id, 'token' => 'forged' ];
		$store->maybe_handle_request();

		$this->assertCount( 1, $this->died );
		$this->assertStringContainsString( 'invalid', $this->died[0][0] );
		// Still pending — a forged token confirms nothing.
		$this->assertSame( 'pending', $this->rows()[ $id ]['status'] );
	}

	public function test_maybe_handle_request_unsubscribe_success_renders_and_removes(): void {
		$store = $this->store();
		$id    = $store->subscribe( 42, 'jane@example.com', 0, 'back_in_stock' )['id'];
		$token = $store->token( 'unsubscribe', $id );

		$_GET = [ Fahad_AI_Stock_Alerts::QUERY_VAR => 'unsubscribe', 'sub' => $id, 'token' => $token ];
		$store->maybe_handle_request();

		$this->assertCount( 1, $this->died );
		$this->assertStringContainsString( 'unsubscribed', $this->died[0][0] );
		$this->assertArrayNotHasKey( $id, $this->rows() );
	}

	public function test_maybe_handle_request_unsubscribe_failure_renders_invalid_message(): void {
		$store = $this->store();
		$id    = $store->subscribe( 42, 'jane@example.com', 0, 'back_in_stock' )['id'];

		$_GET = [ Fahad_AI_Stock_Alerts::QUERY_VAR => 'unsubscribe', 'sub' => $id, 'token' => 'forged' ];
		$store->maybe_handle_request();

		$this->assertCount( 1, $this->died );
		$this->assertStringContainsString( 'invalid', $this->died[0][0] );
		$this->assertArrayHasKey( $id, $this->rows() );
	}

	public function test_maybe_handle_request_defaults_missing_sub_and_token_to_empty(): void {
		$store = $this->store();
		// Only the action present → id + token default to '' (the isset() false arms).
		$_GET = [ Fahad_AI_Stock_Alerts::QUERY_VAR => 'confirm' ];

		$store->maybe_handle_request();

		// Empty id/token → confirm() fails on verify_token, page still renders invalid.
		$this->assertCount( 1, $this->died );
		$this->assertStringContainsString( 'invalid', $this->died[0][0] );
	}

	public function test_render_page_uses_the_stock_alert_title(): void {
		$store = $this->store();
		$id    = $store->subscribe( 42, 'jane@example.com', 0, 'back_in_stock' )['id'];
		$_GET  = [ Fahad_AI_Stock_Alerts::QUERY_VAR => 'confirm', 'sub' => $id, 'token' => $store->token( 'confirm', $id ) ];

		$store->maybe_handle_request();

		$this->assertSame( 'Stock alert', $this->died[0][1] );
	}

	// ── GDPR eraser registration + callback ─────────────────────────────────────

	public function test_register_eraser_adds_the_stock_alert_eraser(): void {
		$store   = $this->store();
		$erasers = $store->register_eraser( [ 'existing' => [ 'x' => 'y' ] ] );

		$this->assertArrayHasKey( 'fahad-ai-stock-alerts', $erasers );
		$this->assertArrayHasKey( 'existing', $erasers ); // preserves existing erasers
		$this->assertSame(
			[ $store, 'gdpr_erase' ],
			$erasers['fahad-ai-stock-alerts']['callback']
		);
	}

	public function test_gdpr_erase_removes_rows_and_reports_the_wp_eraser_shape(): void {
		$store = $this->store();
		$store->subscribe( 42, 'jane@example.com', 0, 'back_in_stock' );
		$store->subscribe( 43, 'jane@example.com', 0, 'price_drop' );

		$result = $store->gdpr_erase( 'jane@example.com' );

		$this->assertTrue( $result['items_removed'] );
		$this->assertFalse( $result['items_retained'] );
		$this->assertSame( [], $result['messages'] );
		$this->assertTrue( $result['done'] );
		$this->assertSame( [], $this->rows() );
	}

	public function test_gdpr_erase_reports_no_removal_when_address_is_unknown(): void {
		$store = $this->store();
		$store->subscribe( 42, 'jane@example.com', 0, 'back_in_stock' );

		$result = $store->gdpr_erase( 'nobody@x.com' );

		// items_removed is false when the email matched no rows.
		$this->assertFalse( $result['items_removed'] );
		$this->assertTrue( $result['done'] );
		$this->assertCount( 1, $this->rows() );
	}

	// ── verify_token(): empty id/token short-circuit ────────────────────────────

	public function test_confirm_with_empty_token_is_rejected(): void {
		$store = $this->store();
		$id    = $store->subscribe( 42, 'jane@example.com', 0, 'back_in_stock' )['id'];

		// Empty token → verify_token returns false on the '' === $token guard.
		$this->assertFalseIsh( $store->confirm( $id, '' ) );
		$this->assertSame( 'pending', $this->rows()[ $id ]['status'] );
	}

	/** Tiny readability helper: confirm() returns bool false here. */
	private function assertFalseIsh( $v ): void {
		$this->assertFalse( $v );
	}
}
