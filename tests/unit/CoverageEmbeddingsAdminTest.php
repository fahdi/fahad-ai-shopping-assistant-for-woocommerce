<?php
/**
 * Line-coverage tests for Fahad_AI_Embeddings_Admin (companion to EmbeddingsAdminTest).
 *
 * The sibling EmbeddingsAdminTest covers save()/run_build()/index_status()/enabled().
 * This file drives the remaining branches: register(), the admin-post handle_build()
 * (capability-denied wp_die path + the nonce-gated success/redirect path), the
 * embedded_count() guard when WooCommerce is absent, and full render_settings()
 * output across both the "stale" and "has-failures" branches.
 */

use Brain\Monkey;
use Brain\Monkey\Functions;
use Brain\Monkey\Actions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;

/** Thrown by the wp_die / wp_safe_redirect stubs to halt the handler the way WP would. */
final class Fahad_AI_CoverageHalt extends \RuntimeException {
	/** @var array<int,mixed> */
	public array $args;

	/** @param array<int,mixed> $args */
	public function __construct( string $tag, array $args = [] ) {
		parent::__construct( $tag );
		$this->args = $args;
	}
}

class CoverageEmbeddingsAdminTest extends TestCase {

	use MockeryPHPUnitIntegration;

	/** @var array<string,mixed> */
	private array $options = [];

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		$this->options = [];

		Functions\when( 'update_option' )->alias(
			function ( $k, $v ) {
				$this->options[ $k ] = $v;
				return true;
			}
		);
		Functions\when( 'get_option' )->alias(
			fn( $k, $d = '' ) => $this->options[ $k ] ?? $d
		);
		Functions\when( 'delete_option' )->alias(
			function ( $k ) {
				unset( $this->options[ $k ] );
				return true;
			}
		);
		Functions\when( 'apply_filters' )->alias( static fn( $hook, $value = null ) => $value );
		Functions\when( 'sanitize_text_field' )->alias( static fn( $s ) => is_string( $s ) ? trim( $s ) : '' );

		// Output/escaping passthroughs used by render_settings().
		Functions\when( 'esc_attr' )->alias( static fn( $s ) => (string) $s );
		Functions\when( 'esc_html' )->alias( static fn( $s ) => (string) $s );
		Functions\when( 'esc_url' )->alias( static fn( $s ) => (string) $s );
		Functions\when( 'checked' )->alias(
			static function ( $checked, $current = true ) {
				$out = ( (string) $checked === (string) $current ) ? " checked='checked'" : '';
				echo $out;
				return $out;
			}
		);
		Functions\when( 'selected' )->alias(
			static function ( $selected, $current = true ) {
				$out = ( (string) $selected === (string) $current ) ? " selected='selected'" : '';
				echo $out;
				return $out;
			}
		);
		Functions\when( 'add_query_arg' )->alias(
			static function ( $key, $value = null, $url = null ) {
				if ( is_array( $key ) ) {
					$url   = $value ?? '';
					$query = http_build_query( $key );
					return $url . ( str_contains( (string) $url, '?' ) ? '&' : '?' ) . $query;
				}
				return $url . ( str_contains( (string) $url, '?' ) ? '&' : '?' ) . rawurlencode( (string) $key ) . '=' . rawurlencode( (string) $value );
			}
		);
		Functions\when( 'admin_url' )->alias( static fn( $path = '' ) => 'https://shop.example/wp-admin/' . $path );
		Functions\when( 'wp_nonce_url' )->alias( static fn( $url, $action = -1 ) => $url . '&_wpnonce=NONCE' );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * Provide a settings capability for handle_build's guard.
	 *
	 * handle_build() calls current_user_can( fahad_ai_settings_capability() ); the
	 * test controls the guard via the current_user_can stub, so the capability
	 * string itself is immaterial — it is only the (ignored) argument. We therefore
	 * only need fahad_ai_settings_capability() to RESOLVE.
	 *
	 * Run alone, that function is undefined (admin-settings.php is not loaded), so we
	 * stub it. Run in the full suite, CoverageAdminSettingsTest's top-level
	 * require_once of admin-settings.php has already defined the REAL function before
	 * Patchwork was initialised — re-stubbing it then throws Patchwork's
	 * "DefinedTooEarly". We swallow that one case and let the real (pure) function
	 * run; it returns a valid capability string that current_user_can ignores anyway.
	 *
	 * A plain function_exists() guard is NOT enough: Brain\Monkey/Patchwork leave a
	 * once-stubbed function "defined" after tearDown, so a sibling test in THIS class
	 * would then skip stubbing yet have no live expectation — hence the try/catch on
	 * the Patchwork-specific exception rather than a pre-check.
	 */
	private function stub_settings_capability( string $cap ): void {
		try {
			Functions\when( 'fahad_ai_settings_capability' )->justReturn( $cap );
		} catch ( \Patchwork\Exceptions\DefinedTooEarly $e ) {
			// Real function from admin-settings.php is already loaded; it resolves fine.
		}
	}

	// ── embedded_count() guard (declared FIRST so wc_get_products is still absent) ──

	public function test_index_status_count_is_zero_when_woocommerce_absent(): void {
		// In a clean process wc_get_products() is undefined, so embedded_count() takes
		// the guard return (line 130). When this file is run alongside the sibling (which
		// defines wc_get_products via Patchwork — the definition lingers after tearDown),
		// the guard can no longer be re-entered: a leftover-but-unmocked function would
		// throw, so it must be re-stubbed. Either way the *behaviour* under no products
		// is the same: a zero count.
		$wc_present = function_exists( 'wc_get_products' );
		if ( $wc_present ) {
			Functions\when( 'wc_get_products' )->justReturn( [] );
		}

		$status = Fahad_AI_Embeddings_Admin::index_status();

		$this->assertSame( 0, $status['count'], 'no embedded products -> zero count' );
		$this->assertFalse( $status['enabled'] );
		$this->assertFalse( $status['available'], 'no key -> no provider -> unavailable' );
		$this->assertSame( '', $status['active_model'] );
		$this->assertFalse( $status['stale'], 'no active model -> not stale' );
	}

	// ── register() ───────────────────────────────────────────────────────────────

	public function test_register_hooks_the_admin_post_build_handler(): void {
		Actions\expectAdded( 'admin_post_' . Fahad_AI_Embeddings_Admin::ACTION_BUILD )
			->once()
			->with( [ Fahad_AI_Embeddings_Admin::class, 'handle_build' ] );

		Fahad_AI_Embeddings_Admin::register();
	}

	// ── handle_build(): capability guard ─────────────────────────────────────────

	public function test_handle_build_dies_403_without_capability(): void {
		$this->stub_settings_capability( 'manage_woocommerce' );
		Functions\when( 'current_user_can' )->justReturn( false );
		Functions\when( 'wp_die' )->alias(
			static function ( $message = '', $title = '', $args = [] ) {
				throw new Fahad_AI_CoverageHalt( 'wp_die', [ $message, $title, $args ] );
			}
		);
		// If the guard fails to stop, these would run — assert they never do.
		Functions\expect( 'check_admin_referer' )->never();
		Functions\expect( 'wp_safe_redirect' )->never();

		try {
			Fahad_AI_Embeddings_Admin::handle_build();
			$this->fail( 'handle_build must wp_die when the user lacks capability' );
		} catch ( Fahad_AI_CoverageHalt $e ) {
			$this->assertSame( 'wp_die', $e->getMessage() );
			$this->assertStringContainsString( 'permission', (string) $e->args[0] );
			$this->assertSame( [ 'response' => 403 ], $e->args[2] );
		}
	}

	// ── handle_build(): success path (nonce -> build -> redirect -> exit) ─────────

	public function test_handle_build_builds_and_redirects_on_success(): void {
		// Provider available (key present) so run_build() returns a real count.
		$this->options['fahad_ai_embedding_api_key'] = 'sk-live';
		$referer_checked                             = false;

		$this->stub_settings_capability( 'manage_woocommerce' );
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'check_admin_referer' )->alias(
			static function ( $action ) use ( &$referer_checked ) {
				$referer_checked = $action;
				return true;
			}
		);
		Functions\when( 'as_enqueue_async_action' )->justReturn( 1 );
		Functions\when( 'wc_get_products' )->justReturn( [ 7, 8, 9, 10 ] );

		$redirected = null;
		Functions\when( 'wp_safe_redirect' )->alias(
			static function ( $url ) use ( &$redirected ) {
				$redirected = $url;
				// Mimic WP halting after the redirect so the trailing exit; is unreachable in-test.
				throw new Fahad_AI_CoverageHalt( 'redirect', [ $url ] );
			}
		);

		try {
			Fahad_AI_Embeddings_Admin::handle_build();
			$this->fail( 'handle_build must redirect (and exit) after a successful build' );
		} catch ( Fahad_AI_CoverageHalt $e ) {
			$this->assertSame( 'redirect', $e->getMessage() );
		}

		$this->assertSame( Fahad_AI_Embeddings_Admin::ACTION_BUILD, $referer_checked, 'nonce action checked' );
		$this->assertNotNull( $redirected, 'a redirect must be issued' );
		$this->assertStringContainsString( 'page=fahad-ai-shopping-assistant-for-woocommerce', (string) $redirected );
		$this->assertStringContainsString( 'fahad_ai_indexed=4', (string) $redirected, 'indexed count flows into the redirect arg' );
		// The build ran: index model + last-build time were recorded.
		$this->assertSame( 'text-embedding-3-small', $this->options[ Fahad_AI_Postmeta_Vector_Store::OPTION_INDEX_MODEL ] );
		$this->assertGreaterThan( 0, $this->options[ Fahad_AI_Embeddings_Admin::OPT_LAST_BUILD ] );
	}

	public function test_handle_build_redirects_with_zero_when_no_provider(): void {
		// No key -> run_build() short-circuits to 0; the handler still redirects.
		$this->stub_settings_capability( 'manage_options' );
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'check_admin_referer' )->justReturn( true );

		$redirected = null;
		Functions\when( 'wp_safe_redirect' )->alias(
			static function ( $url ) use ( &$redirected ) {
				$redirected = $url;
				throw new Fahad_AI_CoverageHalt( 'redirect', [ $url ] );
			}
		);

		try {
			Fahad_AI_Embeddings_Admin::handle_build();
		} catch ( Fahad_AI_CoverageHalt $e ) {
			$this->assertSame( 'redirect', $e->getMessage() );
		}

		$this->assertStringContainsString( 'fahad_ai_indexed=0', (string) $redirected );
		$this->assertArrayNotHasKey( Fahad_AI_Postmeta_Vector_Store::OPTION_INDEX_MODEL, $this->options, 'no build -> no index model recorded' );
	}

	// ── embedded_count() with WooCommerce present ────────────────────────────────

	public function test_embedded_count_counts_products_with_embedding_meta(): void {
		Functions\when( 'wc_get_products' )->justReturn( [ 101, 102, 103 ] );

		$status = Fahad_AI_Embeddings_Admin::index_status();

		$this->assertSame( 3, $status['count'], 'counts the ids returned by the EXISTS meta query' );
	}

	// ── render_settings(): up-to-date / no-failures branch ───────────────────────

	public function test_render_settings_outputs_form_when_up_to_date_and_no_failures(): void {
		Functions\when( 'wc_get_products' )->justReturn( [ 1, 2 ] );

		ob_start();
		Fahad_AI_Embeddings_Admin::render_settings();
		$html = (string) ob_get_clean();

		// Section heading + every named input the form renders.
		$this->assertStringContainsString( 'Semantic Search (beta)', $html );
		$this->assertStringContainsString( 'name="embeddings_enabled"', $html );
		$this->assertStringContainsString( 'name="embedding_provider_type"', $html );
		$this->assertStringContainsString( 'name="embedding_base_url"', $html );
		$this->assertStringContainsString( 'name="embedding_api_key"', $html );
		$this->assertStringContainsString( 'name="cohere_api_key"', $html );
		$this->assertStringContainsString( 'name="embedding_model"', $html );
		$this->assertStringContainsString( 'name="qdrant_url"', $html );
		$this->assertStringContainsString( 'name="qdrant_collection"', $html );
		$this->assertStringContainsString( 'name="embedding_dims"', $html );
		$this->assertStringContainsString( 'name="embed_daily_cap"', $html );

		// Status line: 2 products indexed, up to date.
		$this->assertStringContainsString( '2 products indexed', $html );
		$this->assertStringContainsString( 'up to date', $html );
		$this->assertStringNotContainsString( 'rebuild needed', $html );

		// No-failures branch: the red failure paragraph must be absent.
		$this->assertStringNotContainsString( 'embedding failure(s)', $html );

		// Build/rebuild action with a nonced admin-post URL.
		$this->assertStringContainsString( 'Build / rebuild index', $html );
		$this->assertStringContainsString( '_wpnonce=NONCE', $html );
		$this->assertStringContainsString( 'action=' . Fahad_AI_Embeddings_Admin::ACTION_BUILD, $html );
	}

	// ── render_settings(): stale model + failures branch ─────────────────────────

	public function test_render_settings_shows_stale_and_failure_notice(): void {
		// Cohere selected + key present -> provider available with active model
		// embed-multilingual-v3.0 (the cohere default).
		$this->options[ Fahad_AI_Embeddings_Admin::OPT_PROVIDER_TYPE ] = 'cohere';
		$this->options[ Fahad_AI_Embeddings_Admin::OPT_COHERE_KEY ]    = 'co-live';
		// Index built under a different model -> stale.
		$this->options[ Fahad_AI_Postmeta_Vector_Store::OPTION_INDEX_MODEL ] = 'old-embed-model';
		// Recorded failures + a last error -> the red notice branch.
		$this->options['fahad_ai_index_failures']   = 4;
		$this->options['fahad_ai_index_last_error'] = 'HTTP 429 rate limited';
		// Persisted form values flow into the rendered inputs.
		$this->options[ Fahad_AI_Embeddings_Admin::OPT_DIMS ] = 768;
		$this->options[ Fahad_AI_Embeddings_Admin::OPT_CAP ]  = 5000;

		Functions\when( 'wc_get_products' )->justReturn( [ 1, 2, 3, 4, 5 ] );

		ob_start();
		Fahad_AI_Embeddings_Admin::render_settings();
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( '5 products indexed', $html );
		$this->assertStringContainsString( 'rebuild needed (model changed)', $html, 'differing models -> stale copy' );
		$this->assertStringNotContainsString( 'up to date', $html );

		// Failure notice rendered with count + last error.
		$this->assertStringContainsString( '4 embedding failure(s)', $html );
		$this->assertStringContainsString( 'HTTP 429 rate limited', $html );

		// Persisted scalar settings surface in their inputs.
		$this->assertStringContainsString( 'value="768"', $html, 'persisted dims rendered' );
		$this->assertStringContainsString( 'value="5000"', $html, 'persisted cap rendered' );
		// Cohere option is the selected provider.
		$this->assertStringContainsString( "selected='selected'", $html );
	}
}
