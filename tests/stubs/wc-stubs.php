<?php
/**
 * Minimal WooCommerce class stubs for unit tests.
 * These exist only so PHP resolves type hints — real behaviour is mocked via Mockery.
 */

if ( ! defined( 'ABSPATH' ) ) {
    define( 'ABSPATH', sys_get_temp_dir() . '/' );
}

// WordPress time constant. Defined here for the unit tests (which don't bootstrap WP);
// the returns tools (issue #53) use it for return-window math. Additive — guarded so it
// never collides with a real WP define.
if ( ! defined( 'DAY_IN_SECONDS' ) ) {
    define( 'DAY_IN_SECONDS', 86400 );
}

// ── WordPress stubs ──────────────────────────────────────────────────────────

if ( ! class_exists( 'WP_Error' ) ) {
    class WP_Error {
        public string $code;
        public string $message;
        public array  $data;
        public function __construct( string $code = '', string $message = '', array $data = [] ) {
            $this->code    = $code;
            $this->message = $message;
            $this->data    = $data;
        }
        public function get_error_message(): string { return $this->message; }
        public function get_error_code(): string    { return $this->code; }
    }
}

if ( ! function_exists( 'is_wp_error' ) ) {
    function is_wp_error( mixed $thing ): bool { return $thing instanceof WP_Error; }
}

// Minimal WP_REST_Request stub: a params bag the admin-copilot endpoints read via
// get_param(). The real class has far more; this is enough for unit tests.
if ( ! class_exists( 'WP_REST_Request' ) ) {
    class WP_REST_Request {
        private array $params;
        public function __construct( array $params = [] ) { $this->params = $params; }
        public function get_param( string $key ) { return $this->params[ $key ] ?? null; }
        public function set_param( string $key, $value ): void { $this->params[ $key ] = $value; }
    }
}

// Minimal WP_REST_Response stub. The WhatsApp webhook handlers (issue #62) construct
// real WP_REST_Response objects (rather than relying on rest_ensure_response), so unit
// tests need the class to resolve and expose get_data()/get_status(). Guarded so it never
// collides with a real WP define, and only the surface the tests read is implemented.
if ( ! class_exists( 'WP_REST_Response' ) ) {
    class WP_REST_Response {
        public mixed $data;
        public int   $status;
        public function __construct( mixed $data = null, int $status = 200 ) {
            $this->data   = $data;
            $this->status = $status;
        }
        public function get_data(): mixed { return $this->data; }
        public function get_status(): int { return $this->status; }
        /** @var array<string,string> */
        public array $headers = [];
        public function header( string $key, string $value ): void { $this->headers[ $key ] = $value; }
    }
}

// Gettext stubs for tests — pass through the original string.
if ( ! function_exists( '__' ) ) {
    function __( string $text, string $domain = '' ): string { return $text; }
}
if ( ! function_exists( '_e' ) ) {
    function _e( string $text, string $domain = '' ): void { echo $text; }
}
if ( ! function_exists( 'esc_html__' ) ) {
    function esc_html__( string $text, string $domain = '' ): string { return $text; }
}
if ( ! function_exists( 'esc_html_e' ) ) {
    function esc_html_e( string $text, string $domain = '' ): void { echo $text; }
}
if ( ! function_exists( 'esc_attr__' ) ) {
    function esc_attr__( string $text, string $domain = '' ): string { return $text; }
}
if ( ! function_exists( 'esc_attr_e' ) ) {
    function esc_attr_e( string $text, string $domain = '' ): void { echo $text; }
}

// ── WooCommerce stubs ────────────────────────────────────────────────────────

if ( ! class_exists( 'WC_Product' ) ) {
    class WC_Product {
        public function get_id(): int                     { return 0; }
        public function get_name(): string                { return ''; }
        public function get_price(): string               { return '0'; }
        public function get_regular_price(): string       { return '0'; }
        public function get_sale_price(): string          { return ''; }
        public function is_on_sale(): bool                { return false; }
        public function get_description(): string         { return ''; }
        public function get_short_description(): string   { return ''; }
        public function get_sku(): string                 { return ''; }
        public function is_in_stock(): bool               { return true; }
        public function get_stock_quantity(): ?int        { return null; }
        public function get_type(): string                { return 'simple'; }
        public function is_visible(): bool                { return true; }
        public function is_type( string $type ): bool     { return $this->get_type() === $type; }
        public function get_available_variations(): array { return []; }
        public function get_average_rating(): string      { return '0'; }
        public function get_review_count(): int           { return 0; }
        public function get_total_sales(): int            { return 0; }
        // Product attributes (issue #13: comparison). get_attributes() returns the
        // WC_Product_Attribute[] map keyed by attribute name; get_attribute( $name )
        // returns the product's comma-separated DISPLAY value for that attribute (''
        // when absent). Declared so the comparison tool can read them and the eval
        // harness's makePartial() product mock falls through to a safe empty default
        // for fixtures that set no attributes, like the rating getters above.
        public function get_attributes(): array            { return []; }
        public function get_attribute( string $name ): string { return ''; }
        // Recommendations (issue #16): merchant-curated relation IDs. Declared so the
        // eval harness's makePartial() product mock (tests/eval/EvalHarness.php) can
        // fall through to a safe empty default when a fixture does not set them, the
        // same way the ratings getters above do.
        public function get_upsell_ids(): array           { return []; }
        public function get_cross_sell_ids(): array       { return []; }
        // Child product ids of a grouped product (issue #57: curated bundles). A
        // grouped product is a container whose children are the bundle items;
        // simple/other products return an empty list. Declared so the bundle tool
        // can read them and the eval harness's makePartial() product mock falls
        // through to a safe empty default for fixtures that set no children, the
        // same way the relation getters above do.
        public function get_children(): array             { return []; }
        // Parent id of a variation (child) product; 0 for top-level products.
        // Read by add_to_cart (issue #12) to verify a chosen variation belongs to
        // the product before adding it to the cart.
        public function get_parent_id(): int              { return 0; }
        // Arbitrary product meta. Read by the size/fit advisor (issue #54) to
        // surface any size-chart meta a product stores, and by the eval harness's
        // makePartial() product mock so a fixture that sets no meta falls through to
        // a safe empty default — the same pattern the rating getters above use. The
        // WC_Order stub already declares an identical signature for the same reason.
        public function get_meta( string $key = '', bool $single = true ) { return ''; }
    }
}

if ( ! class_exists( 'WC_Cart' ) ) {
    class WC_Cart {
        public function add_to_cart( int $id, int $qty = 1, int $var = 0 ): string|false { return false; }
        public function get_cart(): array                 { return []; }
        public function is_empty(): bool                  { return true; }
        public function remove_cart_item( string $key ): bool { return false; }
        public function get_cart_contents_count(): int    { return 0; }
        public function get_cart_subtotal(): string       { return '$0.00'; }
        public function get_cart_total(): string          { return '$0.00'; }
        public function apply_coupon( string $code ): bool { return false; }
        // Cross-sell (issue #16): IDs WooCommerce aggregates from the cart items'
        // cross_sell_ids (deduped, excluding items already in the cart). Declared so
        // the cross-sell tool's per-instance Mockery mock can stub it.
        public function get_cross_sells(): array           { return []; }
    }
}

// Coupon stub so the coupon tools (issue #14) can be unit tested with per-instance
// Mockery mocks (Mockery::mock( WC_Coupon::class )). Only the getters the tools
// read are declared; real behaviour is mocked. NOTE: WC_Discounts is intentionally
// NOT stubbed here — the cart-applicability test overloads it (overload: requires
// the class to be undefined), so leaving it absent is deliberate.
if ( ! class_exists( 'WC_Coupon' ) ) {
    class WC_Coupon {
        public function __construct( $data = '' ) {}
        public function get_id(): int                       { return 0; }
        public function get_code(): string                  { return ''; }
        public function get_status(): string                { return 'publish'; }
        public function get_discount_type(): string         { return 'fixed_cart'; }
        public function get_amount(): string                { return '0'; }
        public function get_description(): string            { return ''; }
        public function get_date_expires()                  { return null; }
        public function get_usage_limit(): int              { return 0; }
        public function get_usage_count(): int              { return 0; }
        public function get_usage_limit_per_user(): int     { return 0; }
        public function get_used_by(): array                { return []; }
        public function get_minimum_amount(): string        { return ''; }
        public function get_maximum_amount(): string        { return ''; }
        public function get_product_ids(): array            { return []; }
        public function get_product_categories(): array     { return []; }
    }
}

// Order stub so the order-status tools (issue #17) can be unit tested with
// per-instance Mockery mocks (Mockery::mock( WC_Order::class )). Only the getters
// the tools read are declared; real behaviour is mocked per-test. NOTE: the lookup
// FUNCTIONS wc_get_orders()/wc_get_order() are intentionally NOT defined here —
// they are stubbed per-test via Brain\Monkey (Functions\when), exactly like
// wc_get_products()/wc_get_product(). Defining them at global scope in this
// bootstrap-loaded file would make Brain\Monkey throw "DefinedTooEarly" the moment a
// test tried to override them (the file loads before Patchwork activates), so the
// CLASS lives here for type resolution while the FUNCTIONS stay Monkey-controlled.
if ( ! class_exists( 'WC_Order' ) ) {
    class WC_Order {
        public function get_id(): int                       { return 0; }
        public function get_order_number(): string          { return ''; }
        public function get_status(): string                { return ''; }
        public function get_total(): string                 { return '0'; }
        public function get_total_refunded()                { return 0; }
        public function get_customer_id(): int              { return 0; }
        public function get_date_created()                  { return null; }
        /** @return array WC_Order_Item_Product[] keyed by line-item id. */
        public function get_items( $types = 'line_item' ): array { return []; }
        public function get_meta( string $key = '', bool $single = true ) { return ''; }
        public function get_billing_email(): string         { return ''; }
        // RMA recording (issue #53: returns/exchange). The returns tools persist a return
        // REQUEST as order meta + a best-effort order note — and NEVER touch money. Declared
        // so a Mockery WC_Order mock can stub them (Mockery intercepts declared methods);
        // the methods that would mutate money/status (set_status, payment_complete, refunds)
        // are deliberately NOT declared here, so the suite's ->never() money-safety
        // assertions hold and a stray call would surface loudly. Additive — every existing
        // getter above is untouched.
        public function update_meta_data( string $key, $value, $meta_id = 0 ): void {}
        public function save(): int { return 0; }
        public function add_order_note( string $note, $is_customer_note = 0, $added_by_user = false ): int { return 0; }
    }
}

// ── WooCommerce shipping stubs (issue #19) ───────────────────────────────────
// The shipping tool (Fahad_AI_Shipping_Tools) isolates ALL WC shipping access
// behind one overridable seam, so the UNIT tests stub it via a subclass and never
// touch these classes. They exist for the EVAL fixture (tests/eval/fixtures/shipping.php),
// which drives the REAL tool end-to-end: there is no harness hook to inject zones,
// so the tool reads them from this static stub. The data is deliberately fixed —
// one flat_rate method at 5.00 with NO delivery window — so the eval can assert a
// GROUNDED cost ($5.00 appears in the tool result) and that no delivery date is
// invented (WooCommerce core has none). Only Fahad_AI_Shipping_Tools reads these,
// so a fixed return is safe for the rest of the suite.

if ( ! class_exists( 'WC_Shipping_Method' ) ) {
    class WC_Shipping_Method {
        /** @var string Shipping method id (e.g. 'flat_rate', 'free_shipping'). */
        public string $id;
        /** @var array<string,string> Method option values, read via get_option(). */
        private array $options;
        private string $method_title;

        public function __construct( string $id = 'flat_rate', string $title = '', array $options = [] ) {
            $this->id           = $id;
            $this->method_title = '' !== $title ? $title : ucwords( str_replace( '_', ' ', $id ) );
            $this->options      = $options;
        }

        public function get_method_title(): string { return $this->method_title; }

        /** @return mixed Option value, or the supplied default when unset. */
        public function get_option( string $key, $default = '' ) {
            return $this->options[ $key ] ?? $default;
        }
    }
}

if ( ! class_exists( 'WC_Shipping_Zone' ) ) {
    class WC_Shipping_Zone {
        /** @var WC_Shipping_Method[] */
        private array $methods;

        /** @param WC_Shipping_Method[] $methods Enabled methods this zone serves. */
        public function __construct( array $methods = [] ) {
            $this->methods = $methods;
        }

        /**
         * @param bool $enabled_only Whether to return only enabled methods (the
         *                           shipping tool passes true). The stub holds only
         *                           enabled methods, so the flag is a no-op here.
         * @return WC_Shipping_Method[]
         */
        public function get_shipping_methods( bool $enabled_only = false ): array {
            return $this->methods;
        }
    }
}

if ( ! class_exists( 'WC_Shipping_Zones' ) ) {
    class WC_Shipping_Zones {
        /**
         * Match a package to a shipping zone. The stub returns a single zone
         * offering one flat_rate method costing 5.00 with no delivery window — a
         * deterministic, grounded shape for the eval fixture.
         *
         * @param array $package WC shipping package (destination + contents).
         * @return WC_Shipping_Zone
         */
        public static function get_zone_matching_package( array $package ): WC_Shipping_Zone {
            return new WC_Shipping_Zone( [
                new WC_Shipping_Method( 'flat_rate', 'Flat rate', [ 'cost' => '5.00' ] ),
            ] );
        }
    }
}
