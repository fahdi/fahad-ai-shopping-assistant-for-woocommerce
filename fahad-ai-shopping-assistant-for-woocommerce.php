<?php
/**
 * Plugin Name: Fahad AI Shopping Assistant for WooCommerce
 * Plugin URI:  https://github.com/fahdi/fahad-ai-shopping-assistant-for-woocommerce
 * Description: AI-powered shopping assistant for WooCommerce — answers questions and manages the cart using Claude or Kimi K2.
 * Version:     2.0.0
 * Author:      Fahdi Murtaza
 * Author URI:  https://github.com/fahdi
 * License:     GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: fahad-ai-shopping-assistant-for-woocommerce
 * Domain Path: /languages
 * Requires at least: 6.0
 * Tested up to:      7.0
 * Requires PHP:      8.0
 * Requires Plugins:  woocommerce
 * WC requires at least: 7.0
 */

defined( 'ABSPATH' ) || exit;

define( 'FAHAD_AI_VERSION', '2.0.0' );
define( 'FAHAD_AI_PATH', plugin_dir_path( __FILE__ ) );
define( 'FAHAD_AI_URL', plugin_dir_url( __FILE__ ) );

require_once FAHAD_AI_PATH . 'includes/class-auth.php';
require_once FAHAD_AI_PATH . 'includes/class-tool-registry.php';
require_once FAHAD_AI_PATH . 'includes/class-tools.php';
require_once FAHAD_AI_PATH . 'includes/class-api-handler.php';
require_once FAHAD_AI_PATH . 'includes/admin-settings.php';

final class Fahad_AI_Chatbot {

	private static $instance = null;

	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'rest_api_init',         [ $this, 'register_routes' ] );
		add_action( 'wp_enqueue_scripts',    [ $this, 'enqueue_assets' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_admin_assets' ] );
		add_action( 'wp_footer',             [ $this, 'render_widget' ] );
		add_action( 'admin_menu',            [ $this, 'add_admin_menu' ] );
	}

	public function register_routes(): void {
		register_rest_route( 'fahad-ai/v1', '/message', [
			'methods'             => 'POST',
			'callback'            => [ Fahad_AI_API_Handler::instance(), 'handle_message' ],
			'permission_callback' => [ $this, 'authorize_request' ],
		] );
		register_rest_route( 'fahad-ai/v1', '/stream', [
			'methods'             => 'POST',
			'callback'            => [ Fahad_AI_API_Handler::instance(), 'handle_stream' ],
			'permission_callback' => [ $this, 'authorize_request' ],
		] );
	}

	/**
	 * Gate the chat endpoints.
	 *
	 * These endpoints are intentionally public: a storefront assistant must
	 * answer guests who are not logged in. The nonce alone is therefore not an
	 * authorization boundary (it is exposed to every visitor), so it is paired
	 * with per-client rate limiting. The nonce still blocks cross-origin/CSRF
	 * abuse; the rate limit caps how many billable AI calls and cart mutations
	 * any single client can trigger, which is the concern raised in review.
	 *
	 * @return true|WP_Error
	 */
	public function authorize_request( WP_REST_Request $request ) {
		$nonce = $request->get_header( 'X-WP-Nonce' );

		if ( ! wp_verify_nonce( $nonce, 'wp_rest' ) ) {
			return new WP_Error(
				'fahad_ai_invalid_nonce',
				__( 'Invalid or expired security token. Please reload the page and try again.', 'fahad-ai-shopping-assistant-for-woocommerce' ),
				[ 'status' => 403 ]
			);
		}

		if ( $this->is_rate_limited() ) {
			return new WP_Error(
				'fahad_ai_rate_limited',
				__( 'Too many requests. Please wait a moment and try again.', 'fahad-ai-shopping-assistant-for-woocommerce' ),
				[ 'status' => 429 ]
			);
		}

		return true;
	}

	/**
	 * Fixed-window per-client rate limit backed by a transient.
	 *
	 * Keyed on the remote IP (logged-in users get a per-user key so they are
	 * not lumped together behind a shared NAT). Defaults: 20 requests / 60s,
	 * both overridable via the `fahad_ai_rate_limit` / `fahad_ai_rate_window`
	 * filters. Returns true when the caller has exhausted its window.
	 */
	private function is_rate_limited(): bool {
		$limit  = (int) apply_filters( 'fahad_ai_rate_limit', 20 );
		$window = (int) apply_filters( 'fahad_ai_rate_window', MINUTE_IN_SECONDS );

		if ( $limit <= 0 ) {
			return false;
		}

		$user_id = get_current_user_id();
		$bucket  = $user_id > 0 ? 'u' . $user_id : 'ip' . $this->client_ip();
		$key     = 'fahad_ai_rl_' . md5( $bucket );

		$count = (int) get_transient( $key );

		if ( $count >= $limit ) {
			return true;
		}

		set_transient( $key, $count + 1, $window );

		return false;
	}

	/**
	 * Best-effort client IP. Uses REMOTE_ADDR only — proxy headers such as
	 * X-Forwarded-For are spoofable and must not be trusted for a security
	 * control. Unresolvable addresses share one bucket.
	 */
	private function client_ip(): string {
		$ip = isset( $_SERVER['REMOTE_ADDR'] )
			? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) )
			: '';

		return filter_var( $ip, FILTER_VALIDATE_IP ) ? $ip : 'unknown';
	}

	private function has_api_key(): bool {
		$provider = get_option( 'fahad_ai_provider', 'anthropic' );
		$key      = ( 'moonshot' === $provider )
			? get_option( 'fahad_ai_moonshot_api_key', '' )
			: get_option( 'fahad_ai_anthropic_api_key', '' );
		return ! empty( $key );
	}

	public function enqueue_assets(): void {
		if ( ! $this->has_api_key() ) {
			return;
		}

		wp_enqueue_style(
			'fahad-ai-chatbot',
			FAHAD_AI_URL . 'assets/css/chatbot.css',
			[],
			FAHAD_AI_VERSION
		);

		wp_enqueue_script(
			'fahad-ai-chatbot',
			FAHAD_AI_URL . 'assets/js/chatbot.js',
			[],
			FAHAD_AI_VERSION,
			true
		);

		wp_localize_script( 'fahad-ai-chatbot', 'fahadAiChatbot', [
			'apiUrl'      => rest_url( 'fahad-ai/v1/message' ),
			'streamUrl'   => rest_url( 'fahad-ai/v1/stream' ),
			'provider'    => get_option( 'fahad_ai_provider', 'anthropic' ),
			'nonce'       => wp_create_nonce( 'wp_rest' ),
			'botName'     => get_option( 'fahad_ai_bot_name', __( 'Store Assistant', 'fahad-ai-shopping-assistant-for-woocommerce' ) ),
			'greeting'    => get_option( 'fahad_ai_greeting', __( 'Hi! How can I help you today?', 'fahad-ai-shopping-assistant-for-woocommerce' ) ),
			'accentColor' => get_option( 'fahad_ai_accent_color', '#2563eb' ),
			'i18n'        => [
				'openChat'           => __( 'Open chat assistant', 'fahad-ai-shopping-assistant-for-woocommerce' ),
				'closeChat'          => __( 'Close chat', 'fahad-ai-shopping-assistant-for-woocommerce' ),
				'sendMessage'        => __( 'Send message', 'fahad-ai-shopping-assistant-for-woocommerce' ),
				'yourMessage'        => __( 'Your message', 'fahad-ai-shopping-assistant-for-woocommerce' ),
				'chatDialogLabel'    => __( 'Chat with store assistant', 'fahad-ai-shopping-assistant-for-woocommerce' ),
				'placeholder'        => __( 'Ask me anything…', 'fahad-ai-shopping-assistant-for-woocommerce' ),
				'connectionError'    => __( 'Connection error. Please try again.', 'fahad-ai-shopping-assistant-for-woocommerce' ),
				'genericError'       => __( 'Something went wrong. Please try again.', 'fahad-ai-shopping-assistant-for-woocommerce' ),
				'noResponseStream'   => __( 'No response received. Please try again.', 'fahad-ai-shopping-assistant-for-woocommerce' ),
				'noResponseRegular'  => __( 'No response. Please try again.', 'fahad-ai-shopping-assistant-for-woocommerce' ),
				'toolWorking'        => __( 'Working…', 'fahad-ai-shopping-assistant-for-woocommerce' ),
				'toolSearchProducts' => __( 'Searching products…', 'fahad-ai-shopping-assistant-for-woocommerce' ),
				'toolGetDetails'     => __( 'Getting product details…', 'fahad-ai-shopping-assistant-for-woocommerce' ),
				'toolAddToCart'      => __( 'Adding to cart…', 'fahad-ai-shopping-assistant-for-woocommerce' ),
				'toolViewCart'       => __( 'Checking your cart…', 'fahad-ai-shopping-assistant-for-woocommerce' ),
				'toolRemoveFromCart' => __( 'Removing from cart…', 'fahad-ai-shopping-assistant-for-woocommerce' ),
				'viewProduct'        => __( 'View', 'fahad-ai-shopping-assistant-for-woocommerce' ),
				/* translators: %s is the product name. Accessible label for the card's View link. */
				'viewProductNamed'   => __( 'View %s', 'fahad-ai-shopping-assistant-for-woocommerce' ),
				'addToCart'          => __( 'Add to cart', 'fahad-ai-shopping-assistant-for-woocommerce' ),
				/* translators: %s is the product name. Accessible label for the card's Add-to-cart button. */
				'addToCartNamed'     => __( 'Add %s to cart', 'fahad-ai-shopping-assistant-for-woocommerce' ),
				'inStock'            => __( 'In stock', 'fahad-ai-shopping-assistant-for-woocommerce' ),
				'outOfStock'         => __( 'Out of stock', 'fahad-ai-shopping-assistant-for-woocommerce' ),
				// Variation selector (issue #12).
				/* translators: %s is the product name. Accessible label for a variable product's option <select>. */
				'chooseOptionFor'    => __( 'Choose an option for %s', 'fahad-ai-shopping-assistant-for-woocommerce' ),
				'chooseOption'       => __( 'Choose an option…', 'fahad-ai-shopping-assistant-for-woocommerce' ),
				/* translators: %s is a variation label, e.g. "Size: Large, Color: Blue". Appended to a sold-out option. */
				'variationOutOfStock' => __( '%s (out of stock)', 'fahad-ai-shopping-assistant-for-woocommerce' ),
				'selectOptionFirst'  => __( 'Please choose an option first.', 'fahad-ai-shopping-assistant-for-woocommerce' ),
				/* translators: 1: average rating out of 5 (e.g. 4.5), 2: number of reviews. Accessible label for a product card's star rating. */
				'ratingLabel'        => __( 'Rated %1$s out of 5 (%2$d reviews)', 'fahad-ai-shopping-assistant-for-woocommerce' ),
				'productsGroupLabel' => __( 'Recommended products', 'fahad-ai-shopping-assistant-for-woocommerce' ),
				'productsIntro'      => __( 'Here are some products that might help:', 'fahad-ai-shopping-assistant-for-woocommerce' ),
				// Comparison table (issue #13).
				'comparisonLabel'    => __( 'Product comparison', 'fahad-ai-shopping-assistant-for-woocommerce' ),
				'comparisonCaption'  => __( 'Side-by-side comparison of the selected products', 'fahad-ai-shopping-assistant-for-woocommerce' ),
				'comparisonProduct'  => __( 'Product', 'fahad-ai-shopping-assistant-for-woocommerce' ),
				'comparisonPrice'    => __( 'Price', 'fahad-ai-shopping-assistant-for-woocommerce' ),
				'comparisonRating'   => __( 'Rating', 'fahad-ai-shopping-assistant-for-woocommerce' ),
				'comparisonStock'    => __( 'Availability', 'fahad-ai-shopping-assistant-for-woocommerce' ),
				'comparisonIntro'    => __( 'Here is how they compare:', 'fahad-ai-shopping-assistant-for-woocommerce' ),
			],
		] );
	}

	public function enqueue_admin_assets( string $hook ): void {
		if ( 'settings_page_fahad-ai-shopping-assistant-for-woocommerce' !== $hook ) {
			return;
		}

		wp_enqueue_script(
			'fahad-ai-admin',
			FAHAD_AI_URL . 'assets/js/admin-settings.js',
			[],
			FAHAD_AI_VERSION,
			true
		);
	}

	public function render_widget(): void {
		if ( ! $this->has_api_key() ) {
			return;
		}
		echo '<div id="fahad-ai-chatbot-root"></div>';
	}

	public function add_admin_menu(): void {
		add_options_page(
			esc_html__( 'Fahad AI Shopping Assistant', 'fahad-ai-shopping-assistant-for-woocommerce' ),
			esc_html__( 'Fahad AI Assistant', 'fahad-ai-shopping-assistant-for-woocommerce' ),
			'manage_options',
			'fahad-ai-shopping-assistant-for-woocommerce',
			'fahad_ai_settings_page'
		);
	}
}

add_action( 'plugins_loaded', function () {
	if ( ! class_exists( 'WooCommerce' ) ) {
		add_action( 'admin_notices', function () {
			echo '<div class="notice notice-error"><p><strong>' .
				esc_html__( 'Fahad AI Shopping Assistant', 'fahad-ai-shopping-assistant-for-woocommerce' ) .
				'</strong> ' .
				esc_html__( 'requires WooCommerce to be active.', 'fahad-ai-shopping-assistant-for-woocommerce' ) .
				'</p></div>';
		} );
		return;
	}

	// Load every drop-in feature tool pack. Each pack lives in its own file under
	// includes/tools/ and self-registers a provider via
	// Fahad_AI_Tool_Registry::register_pack() at file scope — see
	// Fahad_AI_Catalog_Tools. Adding a feature pack is therefore just adding a
	// file here; no edits to this bootstrap are needed. Sorted for deterministic
	// load (hence tool) order across platforms.
	$pack_files = glob( FAHAD_AI_PATH . 'includes/tools/*.php' ) ?: [];
	sort( $pack_files );
	foreach ( $pack_files as $pack_file ) {
		require_once $pack_file;
	}

	Fahad_AI_Chatbot::instance();
} );
