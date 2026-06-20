<?php
/**
 * Coverage-completion tests for Fahad_AI_Reorder_Tools (issue #52: reorder /
 * buy-it-again, auth-gated).
 *
 * SIBLING: ReorderToolsTest.php already exercises the happy paths and the
 * headline security cases. This sibling file targets the remaining guard /
 * branch lines so the source reaches 100% line coverage:
 *
 *   - get_past_purchases skips a non-WC_Order row handed back by wc_get_orders
 *     (defence: the query can return mixed types) and skips a line item that
 *     resolves to no product (a fee / shipping line).
 *   - reorder with a non-array `product_ids` resolves to NO refs (the empty
 *     "tell me what to reorder" error), never iterating a non-list.
 *   - line_to_ref rejects a non-product line (no get_product_id) and a line
 *     whose product id is non-positive — both drop the line.
 *   - validate_item rejects a chosen variation that no longer resolves, and one
 *     that resolves to a DIFFERENT parent (the cross-parent guardrail) — both
 *     reported as unavailable, never added.
 *   - plain_price returns an empty string for an empty / null price (a product
 *     with no price set), so no "$" is fabricated.
 *
 * Conventions mirror the sibling exactly: WP/WC functions via Brain\Monkey, WC
 * objects via Mockery, the registry pack list snapshotted + restored, and every
 * case dispatched through the REAL registered pack provider so the production
 * registration + login-gate + dispatch path is what runs.
 */

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;

class CoverageReorderToolsTest extends TestCase {

    use MockeryPHPUnitIntegration;

    /**
     * Snapshot of the registry's static pack providers, restored in tearDown so a
     * case here neither inherits another suite's packs nor leaks the reorder pack
     * we register. (Pack providers are static so they survive a singleton reset.)
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
            // Registry get_tools() reads the merchant tool-gating option (issue #56);
            // default (no disabled tools) so dispatch() is unaffected.
            'get_option'          => fn( $key, $default = '' ) => $default,
            'wp_strip_all_tags'   => fn( $s ) => strip_tags( (string) $s ),
            'wc_price'            => fn( $p ) => '$' . $p,
            'wc_get_cart_url'     => fn() => 'http://example.com/cart',
            'wc_get_checkout_url' => fn() => 'http://example.com/checkout',
        ] );

        // Default to a logged-in customer (id 5), exactly like the sibling.
        Functions\when( 'is_user_logged_in' )->justReturn( true );
        Functions\when( 'get_current_user_id' )->justReturn( 5 );
    }

    protected function tearDown(): void {
        ( new ReflectionProperty( Fahad_AI_Tool_Registry::class, 'pack_providers' ) )->setValue( null, $this->pack_snapshot );
        Monkey\tearDown();
        parent::tearDown();
    }

    /**
     * Fresh registry whose built tool list includes the reorder tools — resets the
     * Tools + registry singletons, then registers the reorder pack's REAL provider
     * (what its file-scope self-registration does in production).
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
        $p->shouldReceive( 'get_price' )->andReturn( $spec['price'] ?? '10.00' );
        $p->shouldReceive( 'is_in_stock' )->andReturn( $spec['in_stock'] ?? true );
        $p->shouldReceive( 'is_visible' )->andReturn( $spec['visible'] ?? true );
        $p->shouldReceive( 'get_parent_id' )->andReturn( (int) ( $spec['parent_id'] ?? 0 ) );
        return $p;
    }

    /**
     * Build a Mockery line item exposing the getters reorder reads from an order.
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
     * Build a Mockery WC_Order. $items is the raw list assigned to get_items() — it
     * may contain mock line items OR (deliberately) non-product entries to exercise
     * the line guards.
     *
     * @param array $spec { id?, customer_id?, items?: array }
     */
    private function mockOrder( array $spec ): WC_Order {
        $o = Mockery::mock( WC_Order::class );
        $o->shouldReceive( 'get_id' )->andReturn( (int) ( $spec['id'] ?? 100 ) );
        $o->shouldReceive( 'get_customer_id' )->andReturn( (int) ( $spec['customer_id'] ?? 5 ) );
        $o->shouldReceive( 'get_items' )->andReturn( $spec['items'] ?? [] );
        return $o;
    }

    /** Mock WC()->cart with an add_to_cart map (product_id => key|false). */
    private function stubCart( $add_to_cart = null ): WC_Cart {
        $cart = Mockery::mock( WC_Cart::class );
        $cart->shouldReceive( 'get_cart_total' )->andReturn( '$0.00' )->byDefault();
        if ( is_callable( $add_to_cart ) ) {
            $cart->shouldReceive( 'add_to_cart' )->andReturnUsing( $add_to_cart );
        }
        Functions\when( 'WC' )->justReturn( (object) [ 'cart' => $cart ] );
        return $cart;
    }

    // ── get_past_purchases: row / line guards ───────────────────────────────────

    /**
     * wc_get_orders() can hand back a heterogeneous list (a stray false / non-order
     * mixed in). get_past_purchases must SKIP any row that is not a WC_Order — never
     * call get_items() on it — and still surface the real order's products.
     * (covers the `! $order instanceof WC_Order` → continue guard.)
     */
    public function test_get_past_purchases_skips_a_non_order_row(): void {
        $real_order = $this->mockOrder( [
            'id'    => 100,
            'items' => [ $this->mockLineItem( [ 'product_id' => 10, 'name' => 'Blue Hoodie' ] ) ],
        ] );

        // A genuine WC_Order alongside junk the query layer could return.
        Functions\when( 'wc_get_orders' )->justReturn( [ false, 'not-an-order', $real_order ] );

        Functions\when( 'wc_get_product' )->alias(
            fn( $id ) => 10 === (int) $id ? $this->mockProduct( [ 'id' => 10, 'name' => 'Blue Hoodie', 'price' => '39.99' ] ) : false
        );

        $result = $this->registry()->dispatch( 'get_past_purchases', [] );

        // The junk rows are ignored; only the real order's product is offered.
        $this->assertSame( 1, $result['found'] );
        $this->assertSame( 10, $result['products'][0]['product_id'] );
    }

    /**
     * An order can carry non-product line types (fee / shipping items) with no
     * usable product id. get_past_purchases must SKIP such lines (line_to_ref → null)
     * and still offer the genuine product lines.
     * (covers the `null === $ref` → continue guard in get_past_purchases.)
     */
    public function test_get_past_purchases_skips_a_non_product_line(): void {
        // A line that exposes get_product_id() but carries no product (id 0) — a fee /
        // shipping line shape. line_to_ref returns null, so it is skipped.
        $fee_line = $this->mockLineItem( [ 'product_id' => 0, 'name' => 'Gift wrap fee' ] );

        $order = $this->mockOrder( [
            'id'    => 100,
            'items' => [
                $fee_line,
                $this->mockLineItem( [ 'product_id' => 10, 'name' => 'Blue Hoodie' ] ),
            ],
        ] );
        Functions\when( 'wc_get_orders' )->justReturn( [ $order ] );

        Functions\when( 'wc_get_product' )->alias(
            fn( $id ) => 10 === (int) $id ? $this->mockProduct( [ 'id' => 10, 'name' => 'Blue Hoodie', 'price' => '39.99' ] ) : false
        );

        $result = $this->registry()->dispatch( 'get_past_purchases', [] );

        // The fee line contributed nothing; the product line is still offered.
        $this->assertSame( 1, $result['found'] );
        $this->assertSame( 10, $result['products'][0]['product_id'] );
    }

    // ── reorder: refs_from_product_ids non-array guard ──────────────────────────

    /**
     * A non-array `product_ids` (e.g. the model passed a scalar) must resolve to NO
     * refs — refs_from_product_ids returns [] without iterating — so reorder falls
     * through to the "tell me what to reorder" error and never touches the cart.
     * (covers the `! is_array( $product_ids )` → return [] guard.)
     */
    public function test_reorder_with_non_array_product_ids_returns_the_empty_error(): void {
        $cart = $this->stubCart();
        $cart->shouldNotReceive( 'add_to_cart' );
        // No order_id path, so the catalogue must never be consulted either.
        Functions\expect( 'wc_get_product' )->never();
        Functions\expect( 'wc_get_order' )->never();

        $result = $this->registry()->dispatch( 'reorder', [ 'product_ids' => 'oops-not-a-list' ] );

        $this->assertArrayHasKey( 'error', $result );
        $this->assertArrayNotHasKey( 'added', $result );
    }

    // ── line_to_ref guards (via reorder by order_id) ────────────────────────────

    /**
     * An order line that is NOT a product item (no get_product_id) is dropped by
     * line_to_ref, so it never reaches the cart. With ONLY such a line, the order
     * yields no refs and reorder returns the empty-selection error.
     * (covers the `! is_callable([ $line, 'get_product_id' ])` → return null guard.)
     */
    public function test_reorder_by_order_id_drops_a_non_product_line(): void {
        // A bare object that does NOT expose get_product_id() at all (no __call), so
        // is_callable([ $line, 'get_product_id' ]) is false — line_to_ref bails.
        $shipping_line = new stdClass();

        $order = $this->mockOrder( [
            'id'          => 100,
            'customer_id' => 5,
            'items'       => [ $shipping_line ],
        ] );
        Functions\when( 'wc_get_order' )->justReturn( $order );

        $cart = $this->stubCart();
        $cart->shouldNotReceive( 'add_to_cart' );
        // No usable product line → catalogue is never consulted.
        Functions\expect( 'wc_get_product' )->never();

        $result = $this->registry()->dispatch( 'reorder', [ 'order_id' => 100 ] );

        // Owned order, but nothing reorderable → the empty-selection error.
        $this->assertArrayHasKey( 'error', $result );
    }

    /**
     * An order line whose product id is non-positive (0) is dropped by line_to_ref
     * BEFORE any variation / quantity read, so it never reaches the cart.
     * (covers the `$product_id <= 0` → return null guard.)
     */
    public function test_reorder_by_order_id_drops_a_line_with_no_product_id(): void {
        $zero_line = $this->mockLineItem( [ 'product_id' => 0, 'name' => 'Orphan line' ] );

        $order = $this->mockOrder( [
            'id'          => 100,
            'customer_id' => 5,
            'items'       => [ $zero_line ],
        ] );
        Functions\when( 'wc_get_order' )->justReturn( $order );

        $cart = $this->stubCart();
        $cart->shouldNotReceive( 'add_to_cart' );
        Functions\expect( 'wc_get_product' )->never();

        $result = $this->registry()->dispatch( 'reorder', [ 'order_id' => 100 ] );

        $this->assertArrayHasKey( 'error', $result );
    }

    // ── validate_item: variation guardrails (via reorder by product_ids) ────────

    /**
     * A chosen variation that NO LONGER resolves (wc_get_product returns false for
     * the variation id) is reported as unavailable — never added.
     * (covers the `! $variation instanceof WC_Product` half of the variation guard.)
     */
    public function test_reorder_reports_a_variation_that_no_longer_resolves(): void {
        // Parent 5 exists; variation 51 is gone.
        $parent = $this->mockProduct( [ 'id' => 5, 'name' => 'Cotton Tee' ] );
        Functions\when( 'wc_get_product' )->alias(
            fn( $id ) => 5 === (int) $id ? $parent : false
        );

        // Drive the variation path through an OWNED order line carrying variation 51.
        $order = $this->mockOrder( [
            'id'          => 100,
            'customer_id' => 5,
            'items'       => [ $this->mockLineItem( [ 'product_id' => 5, 'variation_id' => 51, 'name' => 'Cotton Tee - Large' ] ) ],
        ] );
        Functions\when( 'wc_get_order' )->justReturn( $order );

        $cart = $this->stubCart();
        $cart->shouldNotReceive( 'add_to_cart' );

        $result = $this->registry()->dispatch( 'reorder', [ 'order_id' => 100 ] );

        $this->assertSame( [], $result['added'] );
        $this->assertCount( 1, $result['unavailable'] );
        $this->assertSame( 5, $result['unavailable'][0]['product_id'] );
        $this->assertSame( 51, $result['unavailable'][0]['variation_id'] );
        // Plain, grounded reason — the option, not the parent, is what's gone.
        $this->assertStringContainsString( 'option', strtolower( $result['unavailable'][0]['reason'] ) );
    }

    /**
     * A chosen variation that resolves but belongs to a DIFFERENT parent is the
     * cross-parent guardrail: it must be REJECTED (reported unavailable), never
     * added — a variation must still belong to the product it was bought under.
     * (covers the `(int) $variation->get_parent_id() !== $product_id` half.)
     */
    public function test_reorder_rejects_a_variation_whose_parent_changed(): void {
        $parent    = $this->mockProduct( [ 'id' => 5, 'name' => 'Cotton Tee' ] );
        // Variation 51 now reports a DIFFERENT parent (99) than the line's product (5).
        $variation = $this->mockProduct( [ 'id' => 51, 'name' => 'Cotton Tee - Large', 'price' => '25.00', 'parent_id' => 99 ] );
        Functions\when( 'wc_get_product' )->alias(
            fn( $id ) => 5 === (int) $id ? $parent : ( 51 === (int) $id ? $variation : false )
        );

        $order = $this->mockOrder( [
            'id'          => 100,
            'customer_id' => 5,
            'items'       => [ $this->mockLineItem( [ 'product_id' => 5, 'variation_id' => 51, 'name' => 'Cotton Tee - Large' ] ) ],
        ] );
        Functions\when( 'wc_get_order' )->justReturn( $order );

        $cart = $this->stubCart();
        $cart->shouldNotReceive( 'add_to_cart' );

        $result = $this->registry()->dispatch( 'reorder', [ 'order_id' => 100 ] );

        $this->assertSame( [], $result['added'] );
        $this->assertCount( 1, $result['unavailable'] );
        $this->assertSame( 5, $result['unavailable'][0]['product_id'] );
        $this->assertStringContainsString( 'option', strtolower( $result['unavailable'][0]['reason'] ) );
    }

    // ── plain_price: empty / null price ─────────────────────────────────────────

    /**
     * A product with NO price set (get_price returns '') must surface an EMPTY price
     * string — plain_price short-circuits before wc_price, so no "$" is fabricated
     * for a priceless product. The item is still added (price absence is not an
     * availability failure), proving the empty-price branch is taken on a real path.
     * (covers the `'' === $price || null === $price` → return '' guard.)
     */
    public function test_reorder_reports_empty_price_for_a_priceless_product(): void {
        // Live, in-stock, visible — but with no price set.
        $product = $this->mockProduct( [ 'id' => 10, 'name' => 'Free Sample', 'price' => '' ] );
        Functions\when( 'wc_get_product' )->alias( fn( $id ) => 10 === (int) $id ? $product : false );

        $this->stubCart( fn( $pid, $qty = 1, $vid = 0 ) => 'key_' . $pid );

        $result = $this->registry()->dispatch( 'reorder', [ 'product_ids' => [ 10 ] ] );

        $this->assertCount( 1, $result['added'] );
        $this->assertSame( 10, $result['added'][0]['product_id'] );
        // No "$" fabricated for a product with no price.
        $this->assertSame( '', $result['added'][0]['price'] );
    }
}
