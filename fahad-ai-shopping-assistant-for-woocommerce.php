<?php
/**
 * Plugin Name: Dukandar AI Shopping Assistant for WooCommerce
 * Plugin URI:  https://github.com/fahdi/dukandar-shopping-assistant-for-woocommerce
 * Description: AI-powered shopping assistant for WooCommerce, answers questions and manages the cart using OpenAI, Claude, Gemini, Moonshot, and other major AI providers.
 * Version:           2.14.44
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

define( 'FAHAD_AI_VERSION', '2.14.44' );
define( 'FAHAD_AI_PATH', plugin_dir_path( __FILE__ ) );
define( 'FAHAD_AI_URL', plugin_dir_url( __FILE__ ) );

require_once FAHAD_AI_PATH . 'includes/class-auth.php';
require_once FAHAD_AI_PATH . 'includes/class-providers.php';
require_once FAHAD_AI_PATH . 'includes/class-vector-math.php';
require_once FAHAD_AI_PATH . 'includes/class-embedding-exception.php';
require_once FAHAD_AI_PATH . 'includes/interface-embedding-provider.php';
require_once FAHAD_AI_PATH . 'includes/class-openai-embedding-provider.php';
require_once FAHAD_AI_PATH . 'includes/class-cohere-embedding-provider.php';
require_once FAHAD_AI_PATH . 'includes/class-embeddings.php';
require_once FAHAD_AI_PATH . 'includes/interface-vector-store.php';
require_once FAHAD_AI_PATH . 'includes/class-postmeta-vector-store.php';
require_once FAHAD_AI_PATH . 'includes/class-mariadb-vector-store.php';
require_once FAHAD_AI_PATH . 'includes/class-qdrant-vector-store.php';
require_once FAHAD_AI_PATH . 'includes/class-vector-stores.php';
require_once FAHAD_AI_PATH . 'includes/class-index-health.php';
require_once FAHAD_AI_PATH . 'includes/class-indexer.php';
require_once FAHAD_AI_PATH . 'includes/class-retriever.php';
require_once FAHAD_AI_PATH . 'includes/class-embeddings-admin.php';
require_once FAHAD_AI_PATH . 'includes/class-rrf.php';
require_once FAHAD_AI_PATH . 'includes/class-embedding-document.php';
require_once FAHAD_AI_PATH . 'includes/class-relevance-metrics.php';
require_once FAHAD_AI_PATH . 'includes/class-rag-spike-retriever.php';
require_once FAHAD_AI_PATH . 'includes/class-rag-spike.php';
require_once FAHAD_AI_PATH . 'includes/class-rag-spike-cli.php';
require_once FAHAD_AI_PATH . 'includes/class-feedback.php';
require_once FAHAD_AI_PATH . 'includes/class-analytics.php';
require_once FAHAD_AI_PATH . 'includes/class-proactive.php';
require_once FAHAD_AI_PATH . 'includes/class-voice.php';
require_once FAHAD_AI_PATH . 'includes/class-tool-registry.php';
require_once FAHAD_AI_PATH . 'includes/class-semantic-search.php';
require_once FAHAD_AI_PATH . 'includes/class-visual-search.php';
require_once FAHAD_AI_PATH . 'includes/class-tools.php';
require_once FAHAD_AI_PATH . 'includes/class-api-handler.php';
require_once FAHAD_AI_PATH . 'includes/class-whatsapp.php';
require_once FAHAD_AI_PATH . 'includes/class-admin-copilot.php';
require_once FAHAD_AI_PATH . 'includes/class-agent-gateway.php';
require_once FAHAD_AI_PATH . 'includes/admin-settings.php';

// WooCommerce HPOS (High-Performance Order Storage) compatibility (#208). Declared on
// before_woocommerce_init, at file scope (not the plugins_loaded constructor) so it runs
// before WooCommerce evaluates plugin compatibility. Guarded by class_exists so it is a
// no-op on WooCommerce versions without the features API. The plugin is HPOS-safe: it
// only touches orders via CRUD (wc_get_orders, $order->get_*()), never a direct DB query.
add_action( 'before_woocommerce_init', static function () {
	if ( ! class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
		return;
	}
	foreach ( fahad_ai_wc_compatible_features() as $feature ) {
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( $feature, __FILE__, true );
	}
} );

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

		// Owner analytics (#49): the export/delete dashboard controls POST to
		// admin-post.php; each handler re-checks capability + nonce.
		add_action( 'admin_post_fahad_ai_analytics_export', 'fahad_ai_analytics_export_handler' );
		add_action( 'admin_post_fahad_ai_analytics_delete', 'fahad_ai_analytics_delete_handler' );

		// Owner analytics (#49): a daily scheduled purge ages out telemetry older than
		// the retention window even on a quiet store where the lazy purge in record()
		// rarely runs. The hook is registered once; the event is scheduled on init.
		add_action( 'fahad_ai_analytics_purge', [ $this, 'run_analytics_purge' ] );
		add_action( 'init',                     [ $this, 'schedule_analytics_purge' ] );

		// Weekly owner digest (#206): a recurring inbox summary of the assistant's results
		// is the strongest retention lever. Register a weekly schedule + event; the send is
		// gated (opt-out + activity) inside the callback so a quiet store is never emailed.
		add_filter( 'cron_schedules',            [ $this, 'register_weekly_schedule' ] );
		add_action( 'fahad_ai_weekly_digest',    [ $this, 'run_weekly_digest' ] );
		add_action( 'init',                      [ $this, 'schedule_weekly_digest' ] );

		// One-time welcome email (#229): confirm the assistant is live and guide next steps the
		// first time a provider is configured. Gated + de-duplicated; the send is thin wiring.
		add_action( 'admin_init',                [ $this, 'maybe_send_welcome' ] );

		// Dashboard glance (#245): put the assistant's headline numbers on the WordPress
		// dashboard the owner sees on every login. The render is unit-tested; this is wiring.
		add_action( 'wp_dashboard_setup',        [ $this, 'register_dashboard_widget' ] );
	}

	/** Register the at-a-glance dashboard widget for users who can manage the assistant (#245). */
	public function register_dashboard_widget(): void {
		if ( ! current_user_can( fahad_ai_settings_capability() ) ) {
			return;
		}
		wp_add_dashboard_widget( 'fahad_ai_dashboard', 'Dukandar Assistant', 'fahad_ai_dashboard_widget' );
	}

	/**
	 * Send the one-time welcome email once a provider is configured (#229). Gated by
	 * fahad_ai_should_send_welcome so it fires at most once; marks itself sent immediately
	 * (before the send) so a slow mailer cannot double-fire. Gate + body are unit-tested.
	 */
	public function maybe_send_welcome(): void {
		if ( ! fahad_ai_should_send_welcome() ) {
			return;
		}
		update_option( 'fahad_ai_welcome_sent', '1' );
		wp_mail(
			fahad_ai_notification_email(),
			'Your Dukandar assistant is live',
			fahad_ai_build_welcome_email( admin_url( 'options-general.php?page=fahad-ai-shopping-assistant-for-woocommerce' ) )
		);
	}

	/** Add a 'fahad_ai_weekly' cron interval (WordPress core has no guaranteed weekly). */
	public function register_weekly_schedule( array $schedules ): array {
		if ( ! isset( $schedules['fahad_ai_weekly'] ) ) {
			$schedules['fahad_ai_weekly'] = [ 'interval' => WEEK_IN_SECONDS, 'display' => 'Once Weekly' ];
		}
		return $schedules;
	}

	/** Ensure the weekly digest event is scheduled (idempotent), mirroring the purge cron. */
	public function schedule_weekly_digest(): void {
		if ( ! wp_next_scheduled( 'fahad_ai_weekly_digest' ) ) {
			wp_schedule_event( time() + DAY_IN_SECONDS, 'fahad_ai_weekly', 'fahad_ai_weekly_digest' );
		}
	}

	/**
	 * Cron callback: gather the last 7 days of analytics and email the store admin a plain
	 * summary (#206). Gated by fahad_ai_should_send_weekly_digest so an opted-out or quiet
	 * store is never mailed. The body builder + gate are unit-tested; this is the wiring.
	 */
	public function run_weekly_digest(): void {
		$enabled = fahad_ai_weekly_digest_enabled();
		$range   = [ 'from' => time() - 7 * DAY_IN_SECONDS ];
		$store   = Fahad_AI_Analytics::instance();
		$funnel  = $store->funnel( $range, 'fahad_ai_attribute_orders' );

		if ( ! fahad_ai_should_send_weekly_digest( $enabled, (int) $funnel['conversations'] ) ) {
			return;
		}

		$cost = $store->cost_summary( $range );
		$body = fahad_ai_build_weekly_digest( [
			'conversations' => (int) $funnel['conversations'],
			'added_to_cart' => (int) $funnel['added_to_cart'],
			'cart_rate'     => (float) $funnel['cart_rate'],
			'resolution_rate' => $store->resolution_rate( $range ),
			'orders'        => $funnel['orders'],
			'total_cost'    => (float) $cost['total_cost'],
			'currency'      => function_exists( 'get_woocommerce_currency_symbol' ) ? get_woocommerce_currency_symbol() : '',
			'top_questions' => $store->top_questions( 5, $range ),
			'unanswered'    => $store->unanswered( 5, $range ),
			'down_rated'    => Fahad_AI_Feedback::instance()->recent_down( 5 ),
			'settings_url'  => admin_url( 'options-general.php?page=fahad-ai-shopping-assistant-for-woocommerce' ),
		] );

		wp_mail(
			fahad_ai_notification_email(),
			'Your Dukandar assistant: weekly summary',
			$body
		);
	}

	/**
	 * Ensure the daily analytics-purge cron event is scheduled (issue #49). Idempotent:
	 * only schedules when not already pending, so it self-heals if a site never ran a
	 * formal activation hook (this plugin boots from plugins_loaded).
	 */
	public function schedule_analytics_purge(): void {
		if ( ! wp_next_scheduled( 'fahad_ai_analytics_purge' ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'fahad_ai_analytics_purge' );
		}
	}

	/** Cron callback: purge analytics rows older than the retention window (#49). */
	public function run_analytics_purge(): void {
		Fahad_AI_Analytics::instance()->purge_expired();
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
		// Direct cart actions (#48): card buttons mutate the cart without an agent
		// round-trip. Same gate as the chat endpoints (nonce + rate limit).
		register_rest_route( 'fahad-ai/v1', '/cart', [
			'methods'             => 'POST',
			'callback'            => [ Fahad_AI_API_Handler::instance(), 'handle_cart_action' ],
			'permission_callback' => [ $this, 'authorize_request' ],
		] );
		// Reply feedback / guardrail telemetry (#48 sibling, #50): the 👍/👎 controls
		// on each bot reply POST here. Same gate as the chat endpoints (nonce + rate
		// limit); telemetry-only, stores no PII.
		register_rest_route( 'fahad-ai/v1', '/feedback', [
			'methods'             => 'POST',
			'callback'            => [ Fahad_AI_API_Handler::instance(), 'handle_feedback' ],
			'permission_callback' => [ $this, 'authorize_request' ],
		] );

		// WhatsApp omnichannel webhook (#62): Meta's verify handshake (GET) + signed
		// inbound deliveries (POST) on /whatsapp. Its security boundary is NOT the chat
		// nonce (Meta cannot send one), it is the verify-token check (GET) and the
		// X-Hub-Signature-256 HMAC (POST), enforced inside the handlers. The outbound send
		// is a pluggable seam (fahad_ai_whatsapp_send); no live Meta call ships in core.
		Fahad_AI_WhatsApp::instance()->register_routes();

		// Visual / image search, "shop the look" (#63): POST an image to /visual-search and
		// get visually-similar in-stock products. Same gate as the chat endpoints (nonce +
		// rate limit), since a vision lookup is billable. The actual image ranking is a
		// pluggable seam (fahad_ai_visual_retriever); NO live vision API ships in core, so
		// the route degrades to a graceful "not available" until a provider is registered.
		Fahad_AI_Visual_Search::instance()->register_routes( [ $this, 'authorize_request' ] );

		// Merchant AI copilot (Epic B): admin-only, read-only/draft-only insight and
		// content endpoints under /admin, each gated by the manage_woocommerce capability
		// (NOT the storefront nonce). Grounded in real WooCommerce data; nothing writes.
		Fahad_AI_Admin_Copilot::instance()->register_routes();

		// Store-as-an-agent gateway (Epic C): read-only, grounded endpoints under /agent so
		// external AI agents can discover (llms.txt + catalog feed), search/inspect (reusing
		// the chat tools), and get a HUMAN checkout-handoff link, no agent-side payment.
		Fahad_AI_Agent_Gateway::instance()->register_routes();
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

		// Soft pause (#231): when the owner has switched the assistant off, refuse chat
		// requests even from a stale page that still has the widget loaded, so a pause is a
		// real stop to AI calls, not just a hidden widget.
		if ( ! fahad_ai_widget_enabled() ) {
			return new WP_Error(
				'fahad_ai_disabled',
				__( 'The assistant is currently unavailable. Please try again later.', 'fahad-ai-shopping-assistant-for-woocommerce' ),
				[ 'status' => 503 ]
			);
		}

		if ( $this->is_rate_limited() ) {
			return new WP_Error(
				'fahad_ai_rate_limited',
				__( 'Too many requests. Please wait a moment and try again.', 'fahad-ai-shopping-assistant-for-woocommerce' ),
				[ 'status' => 429 ]
			);
		}

		// Store-wide daily cost ceiling (#194): only the billable AI-answering endpoints
		// count toward and are gated by the cap; cart and feedback are exempt.
		$route = (string) $request->get_route();
		if ( false !== strpos( $route, '/message' ) || false !== strpos( $route, '/stream' ) ) {
			if ( Fahad_AI_Auth::daily_cap_reached() ) {
				return new WP_Error(
					'fahad_ai_daily_cap',
					__( 'The assistant has reached its limit for today. Please contact us and a person will be glad to help.', 'fahad-ai-shopping-assistant-for-woocommerce' ),
					[ 'status' => 503 ]
				);
			}
			Fahad_AI_Auth::record_daily_message();
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
		$limit  = fahad_ai_rate_limit_value();
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
	 * Best-effort client IP. Uses REMOTE_ADDR only, proxy headers such as
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
		// The selected provider's key, resolved from the catalog (multi-provider). An
		// unknown/unset provider resolves to anthropic. Ollama (local) needs no key, so
		// having a configured base URL is enough for it to be considered ready.
		$provider = get_option( 'fahad_ai_provider', 'anthropic' );
		$resolved = Fahad_AI_Providers::resolve( $provider );

		if ( null === $resolved ) {
			return false;
		}

		return '' !== $resolved['api_key'] || ( 'ollama' === $provider && '' !== $resolved['base_url'] );
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
			'cartUrl'     => rest_url( 'fahad-ai/v1/cart' ),
			'feedbackUrl' => rest_url( 'fahad-ai/v1/feedback' ),
			'provider'    => get_option( 'fahad_ai_provider', 'anthropic' ),
			// Whether to use the SSE streaming endpoint: every OpenAI-compatible provider
			// streams; the native Anthropic path does not (multi-provider). The widget
			// keys off this flag rather than hardcoding a provider id.
			'streaming'   => Fahad_AI_Providers::is_openai( get_option( 'fahad_ai_provider', 'anthropic' ) ),
			'nonce'       => wp_create_nonce( 'wp_rest' ),
			'botName'     => get_option( 'fahad_ai_bot_name', __( 'Store Assistant', 'fahad-ai-shopping-assistant-for-woocommerce' ) ),
			'greeting'    => get_option( 'fahad_ai_greeting', __( 'Hi! How can I help you today?', 'fahad-ai-shopping-assistant-for-woocommerce' ) ),
			'accentColor' => get_option( 'fahad_ai_accent_color', '#2563eb' ),
			// Proactive, consented, value-gated nudge (issue #65). Empty array unless the
			// merchant enabled it AND a REAL value signal (an applicable coupon, or unused
			// store credit) exists right now, so the widget can never invent a nudge.
			'proactive'   => $this->proactive_config(),
			// Voice input/output (issue #64). Empty array unless the merchant enabled voice;
			// when present the widget builds the mic (and, if tts is true, speaker) controls
			// using the browser's Web Speech API, subject to browser support, with text
			// always working as the fallback. No audio is ever stored and no external
			// service is added (the API is in-browser).
			'voice'       => Fahad_AI_Voice::instance()->config(),
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
				'addedToCart'        => __( 'Added to your cart.', 'fahad-ai-shopping-assistant-for-woocommerce' ),
				'viewCart'           => __( 'View Cart', 'fahad-ai-shopping-assistant-for-woocommerce' ),
				'checkout'           => __( 'Checkout', 'fahad-ai-shopping-assistant-for-woocommerce' ),
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
				// Reply feedback / thumbs (issue #50).
				'feedbackPrompt'     => __( 'Was this helpful?', 'fahad-ai-shopping-assistant-for-woocommerce' ),
				'feedbackUp'         => __( 'Mark this reply as helpful', 'fahad-ai-shopping-assistant-for-woocommerce' ),
				'feedbackDown'       => __( 'Mark this reply as not helpful', 'fahad-ai-shopping-assistant-for-woocommerce' ),
				'feedbackThanks'     => __( 'Thanks for the feedback.', 'fahad-ai-shopping-assistant-for-woocommerce' ),
				// Proactive nudge (issue #65). The nudge MESSAGE itself is grounded server-side
				// (proactive.message); these are the UI chrome strings around it.
				'proactiveLabel'     => __( 'A message from the store assistant', 'fahad-ai-shopping-assistant-for-woocommerce' ),
				'proactiveOpen'      => __( 'Open chat', 'fahad-ai-shopping-assistant-for-woocommerce' ),
				'proactiveDismiss'   => __( 'Dismiss this message', 'fahad-ai-shopping-assistant-for-woocommerce' ),
				// Voice input/output (issue #64). Labels for the mic (speech-to-text) and
				// speaker (text-to-speech) controls, plus the spoken recording status.
				'voiceStart'         => __( 'Start voice input', 'fahad-ai-shopping-assistant-for-woocommerce' ),
				'voiceStop'          => __( 'Stop voice input', 'fahad-ai-shopping-assistant-for-woocommerce' ),
				'voiceListening'     => __( 'Listening…', 'fahad-ai-shopping-assistant-for-woocommerce' ),
				'voiceUnsupported'   => __( 'Voice input is not supported in this browser.', 'fahad-ai-shopping-assistant-for-woocommerce' ),
				'voiceError'         => __( 'Could not hear that. Please try again or type your message.', 'fahad-ai-shopping-assistant-for-woocommerce' ),
				'voiceDenied'        => __( 'Microphone access was blocked. You can still type your message.', 'fahad-ai-shopping-assistant-for-woocommerce' ),
				'speakOn'            => __( 'Turn on spoken replies', 'fahad-ai-shopping-assistant-for-woocommerce' ),
				'speakOff'           => __( 'Turn off spoken replies', 'fahad-ai-shopping-assistant-for-woocommerce' ),
			],
		] );
	}

	/**
	 * Build the proactive-nudge config for the widget (issue #65), or [] when no nudge
	 * may be shown.
	 *
	 * Short-circuits to [] the instant the merchant kill-switch is off (default), so a
	 * store that has not opted in pays NOTHING: no cart load, no coupon query, no wallet
	 * call. Only when enabled does it resolve a REAL value signal from the store's OWN
	 * grounded tools (so the nudge can never be fabricated):
	 *
	 *   - list_active_coupons: returns only codes WooCommerce itself accepts right now
	 *     (published, unexpired, within usage limits, applicable to the actual cart).
	 *   - get_wallet_balance: login-gated centrally, so it yields a balance only for a
	 *     logged-in shopper with a wallet provider; a guest / no-provider returns an
	 *     error, which is treated as "no credit" (never a nudge).
	 *
	 * Fahad_AI_Proactive applies the value-gate + frequency cap and produces the
	 * grounded, urgency-free message. The widget then enforces the cap + dismissal
	 * client-side via the returned storageKey.
	 *
	 * @return array Empty array, or the widget's proactive config.
	 */
	private function proactive_config(): array {
		$proactive = Fahad_AI_Proactive::instance();

		// Kill-switch first: do no work at all when the merchant has not opted in.
		if ( ! $proactive->enabled() ) {
			return [];
		}

		// WooCommerce does not init the cart on every front-end request; load it so
		// coupon applicability is determinable against the shopper's REAL cart (same
		// reason the REST handlers call wc_load_cart()).
		if ( function_exists( 'wc_load_cart' ) ) {
			wc_load_cart();
		}

		$registry = Fahad_AI_Tool_Registry::instance();

		// Grounded coupon list (honors merchant tool-gating: a disabled coupon tool
		// simply yields no coupon signal).
		$coupons = $registry->dispatch( 'list_active_coupons', [] );
		$coupons = is_array( $coupons ) ? $coupons : [ 'found' => 0, 'coupons' => [] ];

		// Grounded wallet balance. dispatch() login-gates this centrally; an error
		// (guest / no provider / not found) is treated as "no credit".
		$balance     = $registry->dispatch( 'get_wallet_balance', [] );
		$balance_arr = ( is_array( $balance ) && ! isset( $balance['error'] ) && isset( $balance['amount'] ) )
			? $balance
			: null;

		$signal = $proactive->value_signal( $coupons, $balance_arr );

		return $proactive->config( $signal );
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
		if ( ! $this->has_api_key() || ! fahad_ai_widget_enabled() ) {
			return;
		}
		// Distraction-free checkout (#241): optionally skip the cart/checkout pages.
		if ( fahad_ai_hide_on_checkout_enabled() && function_exists( 'is_cart' ) && ( is_cart() || is_checkout() ) ) {
			return;
		}
		echo '<div id="fahad-ai-chatbot-root"></div>';
	}

	public function add_admin_menu(): void {
		// Shop managers (manage_woocommerce) as well as admins can tune the assistant , 
		// the natural cap for a WooCommerce extension; falls back to manage_options where
		// the WooCommerce cap is not granted. Each page callback re-checks the same
		// capability (defence in depth).
		$capability = fahad_ai_settings_capability();

		add_options_page(
			esc_html__( 'Dukandar AI Shopping Assistant', 'fahad-ai-shopping-assistant-for-woocommerce' ),
			esc_html__( 'Dukandar Assistant', 'fahad-ai-shopping-assistant-for-woocommerce' ),
			$capability,
			'fahad-ai-shopping-assistant-for-woocommerce',
			'fahad_ai_settings_page'
		);

		// Owner analytics & "unanswered questions" dashboard (issue #49). A standalone
		// page (own URL, own nonce-gated export/delete actions) rather than a tab on the
		// settings form. Registered as a top-level admin page so its slug
		// (admin.php?page=fahad-ai-analytics) is stable for the redirect after a delete.
		add_menu_page(
			esc_html__( 'AI Assistant Analytics', 'fahad-ai-shopping-assistant-for-woocommerce' ),
			esc_html__( 'AI Analytics', 'fahad-ai-shopping-assistant-for-woocommerce' ),
			$capability,
			'fahad-ai-analytics',
			'fahad_ai_analytics_page',
			'dashicons-chart-bar',
			58
		);
	}
}

add_action( 'plugins_loaded', function () {
	if ( ! class_exists( 'WooCommerce' ) ) {
		add_action( 'admin_notices', function () {
			echo '<div class="notice notice-error"><p><strong>' .
				esc_html__( 'Dukandar AI Shopping Assistant', 'fahad-ai-shopping-assistant-for-woocommerce' ) .
				'</strong> ' .
				esc_html__( 'requires WooCommerce to be active.', 'fahad-ai-shopping-assistant-for-woocommerce' ) .
				'</p></div>';
		} );
		return;
	}

	// Load every drop-in feature tool pack. Each pack lives in its own file under
	// includes/tools/ and self-registers a provider via
	// Fahad_AI_Tool_Registry::register_pack() at file scope, see
	// Fahad_AI_Catalog_Tools. Adding a feature pack is therefore just adding a
	// file here; no edits to this bootstrap are needed. Sorted for deterministic
	// load (hence tool) order across platforms.
	$pack_files = glob( FAHAD_AI_PATH . 'includes/tools/*.php' ) ?: [];
	sort( $pack_files );
	foreach ( $pack_files as $pack_file ) {
		require_once $pack_file;
	}

	Fahad_AI_Chatbot::instance();

	// Activation nudge (issue #190): prompt the admin to add a provider key when none is
	// set, so an installed-but-unconfigured store does not look broken.
	add_action( 'admin_notices', 'fahad_ai_setup_notice' );

	// Timed review request (issue #192): stamp first-active time once, then invite a
	// WordPress.org review after two weeks of configured use. Ratings drive discoverability.
	if ( ! get_option( 'fahad_ai_activated_at' ) ) {
		update_option( 'fahad_ai_activated_at', time() );
	}
	add_action( 'admin_notices', 'fahad_ai_review_notice' );
	add_action( 'admin_init', 'fahad_ai_maybe_dismiss_review' );

	// Provider-health warning (issue #200): alert the owner when the AI provider has been
	// failing recently (usually a bad/expired/credit-exhausted API key), which otherwise
	// only surfaces as a silently dead widget and drives churn.
	add_action( 'admin_notices', 'fahad_ai_provider_health_notice' );

	// Approaching-daily-cap warning (issue #210): warn before the daily cap turns shoppers
	// away at peak time, so the owner can raise it in time. Self-clears at the day boundary.
	add_action( 'admin_notices', 'fahad_ai_daily_cap_notice' );

	// Monthly-budget over-spend warning (issue #243): warn when this month's AI spend reaches
	// the owner's budget, before the provider invoice does. Self-resets each month.
	add_action( 'admin_notices', 'fahad_ai_budget_notice' );

	// Keep product embeddings in step with the catalog (async; no-op without a key).
	Fahad_AI_Indexer::init();

	// Make product search hybrid (keyword + vector) via the semantic-search seam.
	// No-op without an embeddings provider, search stays keyword-only.
	Fahad_AI_Retriever::register();

	// Semantic-search admin: settings save/render + the build-index action.
	Fahad_AI_Embeddings_Admin::register();

	// Opt-in external vector backend (Qdrant), no-op unless a URL is configured.
	Fahad_AI_Qdrant_Vector_Store::register();
} );
