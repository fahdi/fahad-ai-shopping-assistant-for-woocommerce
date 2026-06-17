<?php
/**
 * Unit tests for Fahad_AI_Reorder_Tools (issue #52: reorder / buy-it-again, auth-gated).
 *
 * Red → Green → Refactor. Conventions mirror OrderToolsTest / CouponToolsTest:
 * WP/WC functions mocked via Brain\Monkey; WC objects via Mockery; the registry
 * singleton + its static pack list snapshotted and restored so a case here neither
 * inherits another suite's packs nor leaks the reorder pack we register.
 *
 * The two reorder tools (get_past_purchases, reorder) are NOT built-ins — they ship
 * as a drop-in feature pack that self-registers a provider via
 * Fahad_AI_Tool_Registry::register_pack() at file load. Every test registers the
 * reorder pack's REAL provider through register_pack(), then dispatches through
 * Fahad_AI_Tool_Registry::instance()->dispatch() — so the production registration +
 * merge + dispatch path (INCLUDING the central login gate for `personal` tools) is
 * what is under test.
 *
 * SECURITY IS THE POINT. Reorder reads a customer's order history (PII) and mutates
 * their cart, so the highest-severity tests are first-class:
 *   - GUEST-BLOCK: a guest dispatching either personal tool is stopped centrally by
 *     the registry's login gate BEFORE the callback runs (the WC accessors must never
 *     be hit).
 *   - OWNERSHIP-BYPASS: a logged-in user (id 5) reordering an order owned by user 9
 *     gets a "not found" result — the other user's items are NEVER added to the cart.
 *
 * GUARDRAILS. reorder must revalidate the LIVE catalog: missing / hidden / out-of-stock
 * / changed-variation items are REPORTED (in `unavailable`), not silently dropped, and
 * the reported price is the CURRENT one (never the stale order-line price).
 */

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;

class ReorderToolsTest extends TestCase {

    use MockeryPHPUnitIntegration;

    /**
     * Snapshot of the registry's static pack providers, restored in tearDown so a
     * test here neither inherits another suite's packs nor leaks the reorder pack we
     * register for our own cases. (Pack providers are static so they survive a
     * singleton instance reset — see Fahad_AI_Tool_Registry::register_pack.)
     *
     * @var array<int, callable>
     */
    private array $pack_snapshot = [];

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();

        $this->pack_snapshot = (array) ( new ReflectionProperty( Fahad_AI_Tool_Registry::class, 'pack_providers' ) )->getValue();

        Functions\stubs( [
            'absint'              => fn( $n ) => abs( (int) $n ),
            'sanitize_text_field' => fn( $s ) => is_string( $s ) ? trim( $s ) : $s,
            'wp_strip_all_tags'   => fn( $s ) => strip_tags( (string) $s ),
            'wc_price'            => fn( $p ) => '$' . $p,
            'wc_get_cart_url'     => fn() => 'http://example.com/cart',
            'wc_get_checkout_url' => fn() => 'http://example.com/checkout',
        ] );

        // Default to a logged-in customer (id 5). Guest cases override this. The
        // per-record ownership tests set the order's owner relative to id 5.
        Functions\when( 'is_user_logged_in' )->justReturn( true );
        Functions\when( 'get_current_user_id' )->justReturn( 5 );
    }

    protected function tearDown(): void {
        ( new ReflectionProperty( Fahad_AI_Tool_Registry::class, 'pack_providers' ) )->setValue( null, $this->pack_snapshot );
        Monkey\tearDown();
        parent::tearDown();
    }

    /**
     * Fresh registry whose built tool list includes the reorder tools.
     *
     * Resets the Tools + registry singletons, then registers the reorder pack's REAL
     * provider via register_pack() — exactly what the pack's file-scope
     * self-registration does in production. Registering it explicitly (after clearing
     * the static list) keeps the test hermetic and order-independent.
     */
    private function registry(): Fahad_AI_Tool_Registry {
        ( new ReflectionProperty( Fahad_AI_Tools::class, 'instance' ) )->setValue( null, null );
        ( new ReflectionProperty( Fahad_AI_Tool_Registry::class, 'instance' ) )->setValue( null, null );

        Fahad_AI_Tool_Registry::reset_packs();
        Fahad_AI_Tool_Registry::register_pack( [ 'Fahad_AI_Reorder_Tools', 'register' ] );

        return Fahad_AI_Tool_Registry::instance();
    }

    /**
     * Build a Mockery WC_Product. Only the getters reorder reads are stubbed; the
     * rest default to a simple, visible, in-stock, top-level product.
     *
     * @param array $spec { id?, name?, price?, in_stock?, visible?, parent_id? }
     */
    private function mockProduct( array $spec ): WC_Product {
        $p = Mockery::mock( WC_Product::class );
        $p->shouldReceive( 'get_id' )->andReturn( (int) ( $spec['id'] ?? 1 ) );
        $p->shouldReceive( 'get_name' )->andReturn( (string) ( $spec['name'] ?? 'Product' ) );
        $p->shouldReceive( 'get_price' )->andReturn( (string) ( $spec['price'] ?? '10.00' ) );
        $p->shouldReceive( 'is_in_stock' )->andReturn( $spec['in_stock'] ?? true );
        $p->shouldReceive( 'is_visible' )->andReturn( $spec['visible'] ?? true );
        $p->shouldReceive( 'get_parent_id' )->andReturn( (int) ( $spec['parent_id'] ?? 0 ) );
        return $p;
    }

    /**
     * Build a Mockery line item exposing the getters reorder reads from an order:
     * product id, variation id, quantity, name.
     *
     * @param array $spec { product_id, variation_id?, qty?, name? }
     */
    private function mockLineItem( array $spec ) {
        $line = Mockery::mock( 'WC_Order_Item_Product' );
        $line->shouldReceive( 'get_product_id' )->andReturn( (int) ( $spec['product_id'] ?? 0 ) );
        $line->shouldReceive( 'get_variation_id' )->andReturn( (int) ( $spec['variation_id'] ?? 0 ) );
        $line->shouldReceive( 'get_quantity' )->andReturn( (int) ( $spec['qty'] ?? 1 ) );
        $line->shouldReceive( 'get_name' )->andReturn( (string) ( $spec['name'] ?? '' ) );
        return $line;
    }

    /**
     * Build a Mockery WC_Order from a plain spec. Only the getters the tools read are
     * stubbed; the rest default to a simple, owned, completed order.
     *
     * @param array $spec { id?, customer_id?, items?: array<int, array{product_id:int,
     *                      variation_id?:int, qty?:int, name?:string}> }
     */
    private function mockOrder( array $spec ): WC_Order {
        $o = Mockery::mock( WC_Order::class );
        $o->shouldReceive( 'get_id' )->andReturn( (int) ( $spec['id'] ?? 100 ) );
        $o->shouldReceive( 'get_customer_id' )->andReturn( (int) ( $spec['customer_id'] ?? 5 ) );

        $items = [];
        foreach ( $spec['items'] ?? [] as $i => $item ) {
            $items[ $i ] = $this->mockLineItem( $item );
        }
        $o->shouldReceive( 'get_items' )->andReturn( $items );

        return $o;
    }

    /** Mock WC()->cart with an add_to_cart expectation map (product_id => key|false). */
    private function stubCart( $add_to_cart = null ): WC_Cart {
        $cart = Mockery::mock( WC_Cart::class );
        $cart->shouldReceive( 'get_cart_total' )->andReturn( '$0.00' )->byDefault();
        if ( is_callable( $add_to_cart ) ) {
            $cart->shouldReceive( 'add_to_cart' )->andReturnUsing( $add_to_cart );
        }
        Functions\when( 'WC' )->justReturn( (object) [ 'cart' => $cart ] );
        return $cart;
    }

    // ── registration ──────────────────────────────────────────────────────────

    public function test_reorder_tools_are_registered_via_register_pack(): void {
        $names = array_column( $this->registry()->specs(), 'name' );

        $this->assertContains( 'get_past_purchases', $names );
        $this->assertContains( 'reorder', $names );
        // Additive: the five built-ins remain.
        $this->assertContains( 'search_products', $names );
    }

    public function test_reorder_tool_specs_never_leak_a_callback_or_personal_flag(): void {
        $specs = array_column( $this->registry()->specs(), null, 'name' );

        foreach ( [ 'get_past_purchases', 'reorder' ] as $name ) {
            $this->assertArrayHasKey( $name, $specs );
            $this->assertArrayNotHasKey( 'callback', $specs[ $name ] );
            // The `personal` flag is an internal authorization detail; never advertised.
            $this->assertArrayNotHasKey( 'personal', $specs[ $name ] );
            $this->assertArrayHasKey( 'description', $specs[ $name ] );
            $this->assertSame( 'object', $specs[ $name ]['parameters']['type'] );
            $this->assertArrayHasKey( 'properties', $specs[ $name ]['parameters'] );
        }
    }

    // ── get_past_purchases (own orders only, revalidated) ───────────────────────

    public function test_get_past_purchases_returns_distinct_revalidated_products(): void {
        // Two orders, both the current user's. Product 10 appears twice (must dedupe);
        // product 20 appears once. Both are live + in stock.
        $order_a = $this->mockOrder( [
            'id'    => 100,
            'items' => [
                [ 'product_id' => 10, 'qty' => 1, 'name' => 'Blue Hoodie' ],
                [ 'product_id' => 20, 'qty' => 2, 'name' => 'Red Cap' ],
            ],
        ] );
        $order_b = $this->mockOrder( [
            'id'    => 101,
            'items' => [
                [ 'product_id' => 10, 'qty' => 1, 'name' => 'Blue Hoodie' ],
            ],
        ] );
        Functions\when( 'wc_get_orders' )->justReturn( [ $order_a, $order_b ] );

        $catalog = [
            10 => $this->mockProduct( [ 'id' => 10, 'name' => 'Blue Hoodie', 'price' => '39.99' ] ),
            20 => $this->mockProduct( [ 'id' => 20, 'name' => 'Red Cap', 'price' => '14.99' ] ),
        ];
        Functions\when( 'wc_get_product' )->alias( fn( $id ) => $catalog[ (int) $id ] ?? false );

        $result = $this->registry()->dispatch( 'get_past_purchases', [] );

        // Two DISTINCT products (10 deduped across the two orders).
        $this->assertSame( 2, $result['found'] );
        $this->assertCount( 2, $result['products'] );
        $ids = array_column( $result['products'], 'product_id' );
        $this->assertContains( 10, $ids );
        $this->assertContains( 20, $ids );
        // Current price is reported (grounded), not a stale value.
        $byId = array_column( $result['products'], null, 'product_id' );
        $this->assertSame( '$39.99', $byId[10]['price'] );
    }

    public function test_get_past_purchases_scopes_the_query_to_the_current_user(): void {
        // The query MUST be scoped to the current user id so it can only ever return
        // that user's own purchase history — the first line of defence against leakage.
        Functions\expect( 'wc_get_orders' )
            ->once()
            ->andReturnUsing( function ( array $args ): array {
                $this->assertSame( 5, $args['customer_id'] );
                return [];
            } );

        $this->registry()->dispatch( 'get_past_purchases', [] );
    }

    public function test_get_past_purchases_excludes_unavailable_products(): void {
        // Product 10 is live + in stock; product 30 is out of stock; product 40 no
        // longer exists. Only 10 should be offered for one-tap reorder.
        $order = $this->mockOrder( [
            'id'    => 100,
            'items' => [
                [ 'product_id' => 10, 'name' => 'Blue Hoodie' ],
                [ 'product_id' => 30, 'name' => 'Sold Out Tee' ],
                [ 'product_id' => 40, 'name' => 'Deleted Mug' ],
            ],
        ] );
        Functions\when( 'wc_get_orders' )->justReturn( [ $order ] );

        $catalog = [
            10 => $this->mockProduct( [ 'id' => 10, 'name' => 'Blue Hoodie', 'price' => '39.99' ] ),
            30 => $this->mockProduct( [ 'id' => 30, 'name' => 'Sold Out Tee', 'in_stock' => false ] ),
            // 40 deliberately absent → wc_get_product returns false.
        ];
        Functions\when( 'wc_get_product' )->alias( fn( $id ) => $catalog[ (int) $id ] ?? false );

        $result = $this->registry()->dispatch( 'get_past_purchases', [] );

        $ids = array_column( $result['products'], 'product_id' );
        $this->assertContains( 10, $ids );
        $this->assertNotContains( 30, $ids );
        $this->assertNotContains( 40, $ids );
    }

    public function test_get_past_purchases_caps_limit(): void {
        $captured = 0;
        Functions\when( 'wc_get_orders' )->alias( function ( array $args ) use ( &$captured ): array {
            $captured = (int) $args['limit'];
            return [];
        } );

        $this->registry()->dispatch( 'get_past_purchases', [ 'limit' => 999 ] );

        // Bounded — never an unbounded history scan.
        $this->assertLessThanOrEqual( 50, $captured );
        $this->assertGreaterThan( 0, $captured );
    }

    public function test_get_past_purchases_returns_empty_state_when_none(): void {
        Functions\when( 'wc_get_orders' )->justReturn( [] );

        $result = $this->registry()->dispatch( 'get_past_purchases', [] );

        $this->assertSame( 0, $result['found'] );
        $this->assertSame( [], $result['products'] );
        $this->assertArrayHasKey( 'message', $result );
    }

    // ── reorder by product_ids (revalidate + report) ───────────────────────────

    public function test_reorder_by_product_ids_adds_available_and_reports_unavailable(): void {
        // 10 is live + in stock → added; 30 is out of stock → reported, not dropped.
        $catalog = [
            10 => $this->mockProduct( [ 'id' => 10, 'name' => 'Blue Hoodie', 'price' => '39.99' ] ),
            30 => $this->mockProduct( [ 'id' => 30, 'name' => 'Sold Out Tee', 'in_stock' => false ] ),
        ];
        Functions\when( 'wc_get_product' )->alias( fn( $id ) => $catalog[ (int) $id ] ?? false );

        $this->stubCart( fn( $pid, $qty = 1, $vid = 0 ) => 'key_' . $pid );

        $result = $this->registry()->dispatch( 'reorder', [ 'product_ids' => [ 10, 30 ] ] );

        // 10 added with its CURRENT price.
        $this->assertCount( 1, $result['added'] );
        $this->assertSame( 10, $result['added'][0]['product_id'] );
        $this->assertSame( '$39.99', $result['added'][0]['price'] );

        // 30 reported as unavailable — NOT silently dropped.
        $this->assertCount( 1, $result['unavailable'] );
        $this->assertSame( 30, $result['unavailable'][0]['product_id'] );
        $this->assertArrayHasKey( 'reason', $result['unavailable'][0] );
    }

    public function test_reorder_reports_deleted_product_as_unavailable(): void {
        // Product no longer exists in the catalog.
        Functions\when( 'wc_get_product' )->justReturn( false );

        // Cart must never be touched for a product that cannot be revalidated.
        $cart = $this->stubCart();
        $cart->shouldNotReceive( 'add_to_cart' );

        $result = $this->registry()->dispatch( 'reorder', [ 'product_ids' => [ 999 ] ] );

        $this->assertSame( [], $result['added'] );
        $this->assertCount( 1, $result['unavailable'] );
        $this->assertSame( 999, $result['unavailable'][0]['product_id'] );
    }

    public function test_reorder_requires_an_order_id_or_product_ids(): void {
        // Neither argument → a clear error, and the cart is never touched.
        $cart = $this->stubCart();
        $cart->shouldNotReceive( 'add_to_cart' );

        $result = $this->registry()->dispatch( 'reorder', [] );

        $this->assertArrayHasKey( 'error', $result );
    }

    public function test_reorder_reports_when_cart_rejects_the_item(): void {
        // Product revalidates fine, but WC()->cart->add_to_cart returns false (e.g. a
        // cart-level constraint). It must surface as unavailable, never as "added".
        $product = $this->mockProduct( [ 'id' => 10, 'name' => 'Blue Hoodie', 'price' => '39.99' ] );
        Functions\when( 'wc_get_product' )->alias( fn( $id ) => 10 === (int) $id ? $product : false );

        $this->stubCart( fn( $pid, $qty = 1, $vid = 0 ) => false );

        $result = $this->registry()->dispatch( 'reorder', [ 'product_ids' => [ 10 ] ] );

        $this->assertSame( [], $result['added'] );
        $this->assertCount( 1, $result['unavailable'] );
        $this->assertSame( 10, $result['unavailable'][0]['product_id'] );
    }

    // ── reorder by order_id (ownership + extraction) ────────────────────────────

    public function test_reorder_by_order_id_readds_the_orders_items(): void {
        // Current user (5) owns order 100, which had products 10 (qty 2) and 20 (qty 1).
        $order = $this->mockOrder( [
            'id'          => 100,
            'customer_id' => 5,
            'items'       => [
                [ 'product_id' => 10, 'qty' => 2, 'name' => 'Blue Hoodie' ],
                [ 'product_id' => 20, 'qty' => 1, 'name' => 'Red Cap' ],
            ],
        ] );
        Functions\when( 'wc_get_order' )->justReturn( $order );

        $catalog = [
            10 => $this->mockProduct( [ 'id' => 10, 'name' => 'Blue Hoodie', 'price' => '39.99' ] ),
            20 => $this->mockProduct( [ 'id' => 20, 'name' => 'Red Cap', 'price' => '14.99' ] ),
        ];
        Functions\when( 'wc_get_product' )->alias( fn( $id ) => $catalog[ (int) $id ] ?? false );

        // Capture the quantity passed through to the cart so we know the order line's
        // quantity is honored.
        $added_qty = [];
        $this->stubCart( function ( $pid, $qty = 1, $vid = 0 ) use ( &$added_qty ) {
            $added_qty[ (int) $pid ] = (int) $qty;
            return 'key_' . $pid;
        } );

        $result = $this->registry()->dispatch( 'reorder', [ 'order_id' => 100 ] );

        $this->assertCount( 2, $result['added'] );
        $ids = array_column( $result['added'], 'product_id' );
        $this->assertContains( 10, $ids );
        $this->assertContains( 20, $ids );
        $this->assertSame( 2, $added_qty[10] );
        $this->assertSame( 1, $added_qty[20] );
    }

    public function test_reorder_by_order_id_forwards_the_variation(): void {
        // The order line was a variation (51) of parent product 5. reorder must
        // revalidate the variation belongs to its parent and forward the variation id.
        $order = $this->mockOrder( [
            'id'          => 100,
            'customer_id' => 5,
            'items'       => [
                [ 'product_id' => 5, 'variation_id' => 51, 'qty' => 1, 'name' => 'Cotton Tee - Large' ],
            ],
        ] );
        Functions\when( 'wc_get_order' )->justReturn( $order );

        $parent    = $this->mockProduct( [ 'id' => 5, 'name' => 'Cotton Tee' ] );
        $variation = $this->mockProduct( [ 'id' => 51, 'name' => 'Cotton Tee - Large', 'price' => '25.00', 'parent_id' => 5 ] );
        Functions\when( 'wc_get_product' )->alias(
            fn( $id ) => 5 === (int) $id ? $parent : ( 51 === (int) $id ? $variation : false )
        );

        $forwarded_vid = null;
        $this->stubCart( function ( $pid, $qty = 1, $vid = 0 ) use ( &$forwarded_vid ) {
            $forwarded_vid = (int) $vid;
            return 'key_var';
        } );

        $result = $this->registry()->dispatch( 'reorder', [ 'order_id' => 100 ] );

        $this->assertCount( 1, $result['added'] );
        $this->assertSame( 51, $forwarded_vid );
    }

    /**
     * THE headline security test for reorder. Current user is 5; order #100 belongs to
     * user 9. reorder must return a "not found"-style result and NEVER add the other
     * user's items to the cart, nor confirm the order exists.
     */
    public function test_reorder_blocks_access_to_another_users_order(): void {
        $someone_elses_order = $this->mockOrder( [
            'id'          => 100,
            'customer_id' => 9, // belongs to a DIFFERENT user
            'items'       => [ [ 'product_id' => 10, 'qty' => 1, 'name' => 'Secret Gift' ] ],
        ] );
        Functions\when( 'wc_get_order' )->justReturn( $someone_elses_order );

        // The cart must never be touched — the foreign order's items must not be added.
        $cart = $this->stubCart();
        $cart->shouldNotReceive( 'add_to_cart' );
        // wc_get_product must never run either — we must bail before touching the catalog.
        Functions\expect( 'wc_get_product' )->never();

        $result = $this->registry()->dispatch( 'reorder', [ 'order_id' => 100 ] );

        $this->assertArrayHasKey( 'error', $result );
        $this->assertStringContainsString( 'not found', strtolower( $result['error'] ) );
        // Nothing was added.
        $this->assertArrayNotHasKey( 'added', $result );
        // "Not found" must not leak existence — no forbidden/permission disclosure.
        $this->assertStringNotContainsString( 'forbidden', strtolower( $result['error'] ) );
        $this->assertStringNotContainsString( 'permission', strtolower( $result['error'] ) );
        // None of the foreign order's data leaked.
        $this->assertStringNotContainsString( 'Secret Gift', json_encode( $result ) );
    }

    public function test_reorder_returns_not_found_for_missing_order(): void {
        Functions\when( 'wc_get_order' )->justReturn( false );

        $cart = $this->stubCart();
        $cart->shouldNotReceive( 'add_to_cart' );

        $result = $this->registry()->dispatch( 'reorder', [ 'order_id' => 999 ] );

        $this->assertArrayHasKey( 'error', $result );
        $this->assertStringContainsString( 'not found', strtolower( $result['error'] ) );
    }

    // ── GUEST-BLOCK (central login gate, callback never reached) ────────────────

    /**
     * A guest dispatching either personal tool must be stopped CENTRALLY by the
     * registry's login gate, before the tool callback runs. We assert the standard
     * login-required error AND — critically — that the underlying WC data accessors
     * are NEVER invoked, proving the callback was never reached. is_user_logged_in()
     * is stubbed false.
     *
     * @dataProvider personalToolProvider
     */
    public function test_guest_is_blocked_before_a_personal_tool_callback_runs( string $tool, array $input ): void {
        Functions\when( 'is_user_logged_in' )->justReturn( false );
        Functions\when( 'get_current_user_id' )->justReturn( 0 );

        // If the callback were reached, it would call one of these — they must NOT be
        // hit. expect(...)->never() turns any call into a hard failure.
        Functions\expect( 'wc_get_orders' )->never();
        Functions\expect( 'wc_get_order' )->never();
        Functions\expect( 'wc_get_product' )->never();

        $result = $this->registry()->dispatch( $tool, $input );

        $this->assertArrayHasKey( 'requires_login', $result );
        $this->assertTrue( $result['requires_login'] );
        $this->assertArrayHasKey( 'error', $result );
        $this->assertStringNotContainsString( 'Unknown tool', $result['error'] );
        // No purchase/cart data of any kind in a guest result.
        $this->assertArrayNotHasKey( 'products', $result );
        $this->assertArrayNotHasKey( 'added', $result );
    }

    /** Both personal tools must be guest-gated. */
    public static function personalToolProvider(): array {
        return [
            'get_past_purchases' => [ 'get_past_purchases', [] ],
            'reorder'            => [ 'reorder', [ 'order_id' => 100 ] ],
        ];
    }
}
