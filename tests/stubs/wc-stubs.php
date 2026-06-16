<?php
/**
 * Minimal WooCommerce class stubs for unit tests.
 * These exist only so PHP resolves type hints — real behaviour is mocked via Mockery.
 */

if ( ! defined( 'ABSPATH' ) ) {
    define( 'ABSPATH', sys_get_temp_dir() . '/' );
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
    }
}
