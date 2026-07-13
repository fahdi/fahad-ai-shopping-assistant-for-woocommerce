<?php
/**
 * Line-coverage tests for includes/admin-settings.php.
 *
 * admin-settings.php is a plain function file (guarded by ABSPATH, which the WC
 * stubs define). It holds the field sanitizers, the analytics-dashboard helpers,
 * and the two large admin render functions (the analytics page + the settings
 * page) plus the export/delete admin-post handlers.
 *
 * The render functions are exercised end-to-end with output buffering and the
 * full set of WP helpers stubbed via Brain\Monkey; the Analytics singleton is
 * swapped for a Mockery mock (via reflection on its private static $instance) so
 * the non-empty dashboard branches (top-questions / unanswered loops, the funnel
 * orders branch) actually run. The export/delete handlers' 403 guards are driven
 * by a wp_die that throws; their success bodies are run up to (but not past) the
 * terminating exit by making the last call before exit throw.
 *
 * Conventions mirror MerchantConfigTest / AnalyticsDashboardTest: Brain\Monkey +
 * Mockery, singletons reset via reflection, no whole-suite dependence.
 */

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__, 2 ) . '/includes/admin-settings.php';

/** Thrown by the stubbed wp_die so a 403 guard can be asserted without halting. */
class Fahad_AI_Cov_WpDie extends \RuntimeException {}

/** Thrown by the last call before a handler's exit so the body runs but exit does not. */
class Fahad_AI_Cov_Halt extends \RuntimeException {}

class CoverageAdminSettingsTest extends TestCase {

	use MockeryPHPUnitIntegration;

	/** @var array<string,mixed> Option store backing get_option/update_option. */
	private array $options = [];

	/** @var array<int, callable> */
	private array $pack_snapshot = [];

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$_GET  = [];
		$_POST = [];

		// Clear first-party packs so the gateable-tools list is the built-in set plus
		// whatever a test registers, not every shipped pack.
		$this->pack_snapshot = (array) ( new ReflectionProperty( Fahad_AI_Tool_Registry::class, 'pack_providers' ) )->getValue();
		Fahad_AI_Tool_Registry::reset_packs();
		( new ReflectionProperty( Fahad_AI_Tool_Registry::class, 'instance' ) )->setValue( null, null );

		$this->options = [];

		Functions\stubs( [
			'sanitize_text_field'             => fn( $s ) => is_string( $s ) ? trim( strip_tags( $s ) ) : '',
			'sanitize_textarea_field'         => fn( $s ) => is_string( $s ) ? trim( strip_tags( $s ) ) : '',
			// Mirror WP: lowercase FIRST, then strip to the [a-z0-9_-] slug charset.
			'sanitize_key'                    => fn( $s ) => preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $s ) ),
			'sanitize_hex_color'              => fn( $s ) => is_string( $s ) ? $s : '',
			'wp_unslash'                      => fn( $s ) => $s,
			'absint'                          => fn( $v ) => abs( (int) $v ),
			'get_bloginfo'                    => fn() => 'Test Store',
			'get_woocommerce_currency_symbol' => fn() => '$',
			'get_site_url'                    => fn() => 'http://example.com',
			'admin_url'                       => fn( $p = '' ) => 'http://example.com/wp-admin/' . $p,
			'rest_url'                        => fn( $p = '' ) => 'http://example.com/wp-json/' . $p,
			'add_query_arg'                   => fn( ...$a ) => 'http://example.com/with-args',
			'esc_html'                        => fn( $s ) => (string) $s,
			'esc_attr'                        => fn( $s ) => (string) $s,
			'esc_url'                         => fn( $s ) => (string) $s,
			'esc_url_raw'                     => fn( $s ) => (string) $s,
			'esc_js'                          => fn( $s ) => (string) $s,
			'esc_textarea'                    => fn( $s ) => (string) $s,
			'wp_nonce_field'                  => fn( ...$a ) => '',
			'wp_nonce_url'                    => fn( $url, ...$a ) => (string) $url,
			'wp_parse_url'                    => fn( $url, $component = -1 ) => parse_url( (string) $url ),
			'wp_json_encode'                  => fn( $d ) => json_encode( $d ),
			'nocache_headers'                 => fn() => null,
			'checked'                         => fn( ...$a ) => '',
			'selected'                        => fn( ...$a ) => '',
		] );

		// Option store (update_option is called with up to 3 args by Analytics::save).
		Functions\when( 'get_option' )->alias(
			fn( $key, $default = false ) => array_key_exists( $key, $this->options ) ? $this->options[ $key ] : $default
		);
		Functions\when( 'update_option' )->alias(
			function ( $key, $value, $autoload = null ) {
				$this->options[ $key ] = $value;
				return true;
			}
		);
		Functions\when( 'delete_option' )->alias(
			function ( $key ) {
				unset( $this->options[ $key ] );
				return true;
			}
		);
		// apply_filters returns the value unchanged (no hooks in unit context).
		Functions\when( 'apply_filters' )->alias( static fn( $tag, $value = null, ...$rest ) => $value );

		// submit_button just echoes a button so the rendered markup is non-empty.
		Functions\when( 'submit_button' )->alias(
			static function ( $text = '', $type = 'primary', $name = '', $wrap = true ) {
				echo '<button name="' . (string) $name . '">' . (string) $text . '</button>';
			}
		);

		// fahad_ai_settings_page() renders the embeddings-admin section, whose
		// embedded_count() calls wc_get_products() ONLY when that function exists.
		// In a clean process it is undefined (guarded → 0), but a sibling coverage
		// test stubs it via Patchwork and the definition lingers after tearDown, so
		// function_exists() then reports true and embedded_count() calls an unmocked
		// wc_get_products, Brain\Monkey throws mid-render and leaks the open output
		// buffer. Stub it to an empty product set so the count is a deterministic 0
		// (identical to the no-WooCommerce behaviour) regardless of suite order.
		Functions\when( 'wc_get_products' )->justReturn( [] );

		// Baseline buffer depth so tearDown can drain anything a mid-render throw left
		// open, keeping the buffer balanced even if the SUT early-exits unexpectedly.
		$this->ob_baseline = ob_get_level();
	}

	/** @var int Output-buffer nesting level recorded at the start of each test. */
	private int $ob_baseline = 0;

	protected function tearDown(): void {
		// Drain any output buffer a mid-render throw left open, back to the baseline,
		// so a failing test never reports "did not close its own output buffers".
		while ( ob_get_level() > $this->ob_baseline ) {
			ob_end_clean();
		}

		$_GET  = [];
		$_POST = [];
		( new ReflectionProperty( Fahad_AI_Tool_Registry::class, 'pack_providers' ) )->setValue( null, $this->pack_snapshot );
		( new ReflectionProperty( Fahad_AI_Analytics::class, 'instance' ) )->setValue( null, null );
		Monkey\tearDown();
		parent::tearDown();
	}

	// ── helpers ──────────────────────────────────────────────────────────────────

	/** Allow / deny the capability check used by every page + handler. */
	private function grant_cap( bool $can ): void {
		Functions\when( 'current_user_can' )->justReturn( $can );
	}

	/**
	 * Reset the (final) Analytics singleton so it reads the seeded option store, and
	 * seed its stored rows + enabled flag. Fahad_AI_Analytics is `final`, so it cannot
	 * be Mockery-mocked; instead the REAL singleton runs against crafted option data.
	 *
	 * @param array<int|string, array> $rows    Stored analytics rows (id => row).
	 * @param bool                      $enabled The OPTION_ENABLED flag value.
	 */
	private function seed_analytics( array $rows, bool $enabled ): void {
		( new ReflectionProperty( Fahad_AI_Analytics::class, 'instance' ) )->setValue( null, null );
		$this->options[ Fahad_AI_Analytics::OPTION ]         = $rows;
		$this->options[ Fahad_AI_Analytics::OPTION_ENABLED ] = $enabled ? 1 : 0;
	}

	/** @var int Monotonic counter for unique row ids. */
	private int $row_seq = 0;

	/** Build one stored analytics row with sensible defaults. */
	private function row( array $overrides = [] ): array {
		return array_merge(
			[
				'id'               => 'r' . ( ++$this->row_seq ),
				'question'         => 'A question',
				'tools'            => [],
				'outcome'          => Fahad_AI_Analytics::OUTCOME_ANSWERED,
				'product_surfaced' => false,
				'added_to_cart'    => false,
				'tokens'           => 0,
				'cost'             => 0.0,
				'conversation_ref' => 'conv-1',
				'created'          => 1700000000,
			],
			$overrides
		);
	}

	/** A wp_die that throws so a 403 guard is observable without halting PHP. */
	private function wp_die_throws(): void {
		Functions\when( 'wp_die' )->alias(
			static function ( $msg = '', $title = '', $args = [] ) {
				throw new Fahad_AI_Cov_WpDie( is_string( $msg ) ? $msg : 'wp_die' );
			}
		);
	}

	// ── fahad_ai_settings_capability ─────────────────────────────────────────────

	public function test_capability_prefers_manage_woocommerce_when_granted(): void {
		Functions\when( 'current_user_can' )->alias( static fn( $cap ) => 'manage_woocommerce' === $cap );
		$this->assertSame( 'manage_woocommerce', fahad_ai_settings_capability() );
	}

	public function test_capability_falls_back_to_manage_options(): void {
		Functions\when( 'current_user_can' )->alias( static fn( $cap ) => 'manage_woocommerce' !== $cap );
		$this->assertSame( 'manage_options', fahad_ai_settings_capability() );
	}

	// ── fahad_ai_sanitize_tone ───────────────────────────────────────────────────

	public function test_sanitize_tone_accepts_a_known_key(): void {
		$this->assertSame( 'professional', fahad_ai_sanitize_tone( 'professional' ) );
	}

	public function test_sanitize_tone_rejects_unknown_and_nonscalar(): void {
		$this->assertSame( '', fahad_ai_sanitize_tone( 'bogus' ) );
		$this->assertSame( '', fahad_ai_sanitize_tone( [ 'x' ] ) );
	}

	// ── fahad_ai_sanitize_provider ───────────────────────────────────────────────

	public function test_sanitize_provider_accepts_a_catalog_id(): void {
		$this->assertSame( 'anthropic', fahad_ai_sanitize_provider( 'anthropic' ) );
	}

	public function test_sanitize_provider_falls_back_to_anthropic(): void {
		$this->assertSame( 'anthropic', fahad_ai_sanitize_provider( 'totally-unknown' ) );
		$this->assertSame( 'anthropic', fahad_ai_sanitize_provider( [ 'arr' ] ) );
	}

	// ── fahad_ai_sanitize_languages ──────────────────────────────────────────────

	public function test_sanitize_languages_keeps_a_value(): void {
		$this->assertSame( 'English, Urdu', fahad_ai_sanitize_languages( 'English, Urdu' ) );
	}

	public function test_sanitize_languages_empty_becomes_auto(): void {
		$this->assertSame( 'auto', fahad_ai_sanitize_languages( '' ) );
		$this->assertSame( 'auto', fahad_ai_sanitize_languages( [ 'x' ] ) );
	}

	// ── fahad_ai_sanitize_tool_list ──────────────────────────────────────────────

	public function test_sanitize_tool_list_non_array_is_empty(): void {
		$this->assertSame( [], fahad_ai_sanitize_tool_list( 'nope' ) );
	}

	public function test_sanitize_tool_list_slugs_and_dedupes(): void {
		$clean = fahad_ai_sanitize_tool_list( [ 'Track Order', 'track-order', '', 123, '!!!' ] );
		// 'Track Order' -> 'trackorder' (the stub strips spaces), 'track-order' kept,
		// the empty string + non-string skipped, '!!!' slugs to '' and is dropped.
		$this->assertSame( [ 'trackorder', 'track-order' ], $clean );
	}

	// ── fahad_ai_analytics_range_from_request ────────────────────────────────────

	public function test_range_from_request_blank_when_no_params(): void {
		$range = fahad_ai_analytics_range_from_request();
		$this->assertNull( $range['from'] );
		$this->assertNull( $range['to'] );
		$this->assertSame( '', $range['from_str'] );
		$this->assertSame( '', $range['to_str'] );
	}

	public function test_range_from_request_parses_valid_dates(): void {
		$_GET['from'] = '2026-01-01';
		$_GET['to']   = '2026-01-31';
		$range        = fahad_ai_analytics_range_from_request();
		$this->assertIsInt( $range['from'] );
		$this->assertIsInt( $range['to'] );
		$this->assertSame( '2026-01-01', $range['from_str'] );
		$this->assertSame( '2026-01-31', $range['to_str'] );
		$this->assertGreaterThan( $range['from'], $range['to'] );
	}

	public function test_range_from_request_drops_invalid_strings(): void {
		$_GET['from'] = 'garbage';
		$_GET['to']   = '2026/02/02';
		$range        = fahad_ai_analytics_range_from_request();
		$this->assertNull( $range['from'] );
		$this->assertNull( $range['to'] );
		// from_str/to_str fall back to '' because the parsed value is null.
		$this->assertSame( '', $range['from_str'] );
		$this->assertSame( '', $range['to_str'] );
	}

	// ── fahad_ai_analytics_parse_date ────────────────────────────────────────────

	public function test_parse_date_null_for_blank_and_malformed(): void {
		$this->assertNull( fahad_ai_analytics_parse_date( '', false ) );
		$this->assertNull( fahad_ai_analytics_parse_date( 'xx', true ) );
	}

	public function test_parse_date_end_of_day_is_inclusive(): void {
		$s = fahad_ai_analytics_parse_date( '2026-03-04', false );
		$e = fahad_ai_analytics_parse_date( '2026-03-04', true );
		$this->assertSame( 86399, $e - $s );
	}

	// ── fahad_ai_analytics_outcome_label ─────────────────────────────────────────

	public function test_outcome_label_known_and_unknown(): void {
		$this->assertSame( 'Answered', fahad_ai_analytics_outcome_label( Fahad_AI_Analytics::OUTCOME_ANSWERED ) );
		$this->assertSame( 'Error', fahad_ai_analytics_outcome_label( Fahad_AI_Analytics::OUTCOME_ERROR ) );
		$this->assertSame( 'whatever', fahad_ai_analytics_outcome_label( 'whatever' ) );
	}

	// ── fahad_ai_analytics_format_time ───────────────────────────────────────────

	public function test_format_time_blank_for_nonpositive(): void {
		$this->assertSame( '', fahad_ai_analytics_format_time( 0 ) );
		$this->assertSame( '', fahad_ai_analytics_format_time( -5 ) );
	}

	public function test_format_time_uses_wp_date_when_available(): void {
		$this->options['date_format'] = 'Y-m-d';
		$this->options['time_format'] = 'H:i';
		Functions\when( 'wp_date' )->alias( static fn( $fmt, $ts ) => 'WPDATE:' . $ts );
		$this->assertSame( 'WPDATE:1700000000', fahad_ai_analytics_format_time( 1700000000 ) );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 *
	 * wp_date() is a real WordPress function the unit env never bootstraps, but
	 * Brain\Monkey DEFINES it the moment another test stubs it, and Patchwork leaves
	 * it defined for the rest of the process, so function_exists() then reports true.
	 * To exercise the gmdate() FALLBACK branch deterministically we run in a SEPARATE
	 * process where wp_date is genuinely undefined (the same isolation the sibling
	 * coverage tests use for wp_generate_uuid4()/get_posts()).
	 */
	public function test_format_time_falls_back_to_gmdate_without_wp_date(): void {
		$this->assertFalse(
			function_exists( 'wp_date' ),
			'precondition: wp_date must be undefined so the gmdate fallback fires.'
		);
		// 1700000000 → 2023-11-14 22:13 UTC; gmdate uses the fixed Y-m-d H:i format.
		$this->assertSame( gmdate( 'Y-m-d H:i', 1700000000 ), fahad_ai_analytics_format_time( 1700000000 ) );
	}

	// ── fahad_ai_attribute_orders ────────────────────────────────────────────────

	public function test_attribute_orders_defaults_to_zero(): void {
		// apply_filters passes the value (0) through unchanged in this harness.
		$this->assertSame( 0, fahad_ai_attribute_orders( [ 'a', 'b' ] ) );
	}

	public function test_attribute_orders_is_filterable(): void {
		Functions\when( 'apply_filters' )->alias(
			static fn( $tag, $value, $refs = [] ) => 'fahad_ai_attributed_orders' === $tag ? count( $refs ) : $value
		);
		$this->assertSame( 3, fahad_ai_attribute_orders( [ 'a', 'b', 'c' ] ) );
	}

	// ── fahad_ai_analytics_page (guard) ──────────────────────────────────────────

	public function test_analytics_page_returns_for_no_capability(): void {
		$this->grant_cap( false );
		ob_start();
		fahad_ai_analytics_page();
		$out = ob_get_clean();
		$this->assertSame( '', $out, 'No markup is rendered without capability.' );
	}

	// ── fahad_ai_analytics_page (full render, empty data) ────────────────────────

	public function test_analytics_page_renders_empty_state(): void {
		$this->grant_cap( true );
		// No stored rows + logging OFF → empty aggregates + the "logging off" warning.
		$this->seed_analytics( [], false );

		ob_start();
		fahad_ai_analytics_page();
		$out = ob_get_clean();

		$this->assertStringContainsString( 'AI Assistant Analytics', $out );
		$this->assertStringContainsString( 'Analytics logging is currently turned off', $out );
		$this->assertStringContainsString( 'No questions recorded for this range yet', $out );
		$this->assertStringContainsString( 'the assistant answered everything it was asked', $out );
		// The page always passes an order resolver, so the funnel reports 0 orders (not n/a).
		$this->assertStringContainsString( 'Conversations', $out );
	}

	// ── fahad_ai_analytics_page (full render, populated, with range + notices) ───

	public function test_analytics_page_renders_populated_with_range_and_notices(): void {
		$this->grant_cap( true );

		// A range filter is active (drives the Reset link + non-empty *_str), and the
		// one-shot purge notice is present. Dates span 2026-01 so the seeded rows fall in.
		$_GET['from']             = '2026-01-01';
		$_GET['to']               = '2026-12-31';
		$_GET['fahad_ai_purged']  = '1';

		$ts = strtotime( '2026-06-15 12:00:00' );
		$this->seed_analytics(
			[
				// Two turns asking the same answered question (top-questions count = 2),
				// each surfacing a product + adding to cart (funnel stages).
				'a' => $this->row( [ 'question' => 'Where is my order?', 'conversation_ref' => 'conv-A', 'product_surfaced' => true, 'added_to_cart' => true, 'created' => $ts, 'cost' => 0.5, 'tokens' => 1000 ] ),
				'b' => $this->row( [ 'question' => 'Where is my order?', 'conversation_ref' => 'conv-A', 'created' => $ts, 'cost' => 0.25, 'tokens' => 500 ] ),
				// An abstained turn → appears in the "couldn't answer" list.
				'c' => $this->row( [ 'question' => 'Do you ship to Mars?', 'outcome' => Fahad_AI_Analytics::OUTCOME_ABSTAINED, 'conversation_ref' => 'conv-B', 'created' => $ts ] ),
				// An escalated turn with NO question text → the "(no question text)" branch.
				'd' => $this->row( [ 'question' => '', 'outcome' => Fahad_AI_Analytics::OUTCOME_ESCALATED, 'conversation_ref' => 'conv-C', 'created' => $ts ] ),
			],
			true // enabled → no "logging off" warning
		);

		Functions\when( 'wp_date' )->alias( static fn( $fmt, $t ) => 'TS:' . $t );

		// Thumbs-down rows: one with a reason, one without, so both branches of the
		// "replies shoppers rated unhelpful" list render (#237).
		$this->options[ Fahad_AI_Feedback::OPTION ] = [
			[ 'rating' => 'down', 'reason' => 'The size guide was wrong', 'created' => $ts, 'message_ref' => 'm1', 'conversation_ref' => 'conv-A' ],
			[ 'rating' => 'down', 'reason' => '', 'created' => $ts, 'message_ref' => 'm2', 'conversation_ref' => 'conv-B' ],
		];
		( new ReflectionProperty( Fahad_AI_Feedback::class, 'instance' ) )->setValue( null, null );

		ob_start();
		fahad_ai_analytics_page();
		$out = ob_get_clean();

		$this->assertStringContainsString( 'The size guide was wrong', $out );       // down-rated reply reason (#237)
		$this->assertStringContainsString( '(no reason given)', $out );              // empty-reason fallback (#237)

		$this->assertStringContainsString( 'Analytics data deleted.', $out );        // purge notice
		$this->assertStringNotContainsString( 'Analytics logging is currently turned off', $out ); // enabled
		$this->assertStringContainsString( 'Where is my order?', $out );             // top-questions loop
		$this->assertStringContainsString( 'Do you ship to Mars?', $out );           // unanswered loop
		$this->assertStringContainsString( '(no question text)', $out );             // empty-question fallback
		$this->assertStringContainsString( 'Abstained', $out );                      // outcome label
		$this->assertStringContainsString( 'Escalated', $out );                      // outcome label (2nd)
		$this->assertStringContainsString( 'TS:' . $ts, $out );                       // formatted time
		$this->assertStringContainsString( 'Reset', $out );                          // active-range reset link
		$this->assertStringContainsString( '33%', $out );                            // chat-to-cart rate (1 of 3 convs)
		$this->assertStringContainsString( '50%', $out );                            // resolution rate (2 answered of 4)
		$this->assertStringContainsString( 'up,', $out );                            // shopper helpfulness counts (0 up, 0 down)
		$this->assertStringContainsString( 'down)', $out );
	}

	// ── fahad_ai_analytics_page (save the opt-out toggle) ────────────────────────

	public function test_analytics_page_saves_opt_out_toggle(): void {
		$this->grant_cap( true );
		Functions\when( 'check_admin_referer' )->justReturn( true );
		$this->seed_analytics( [], true );

		$_POST['fahad_ai_analytics_save'] = '1';
		// analytics_enabled absent → stored as 0.

		ob_start();
		fahad_ai_analytics_page();
		$out = ob_get_clean();

		$this->assertStringContainsString( 'Analytics settings saved.', $out );
		$this->assertSame( 0, $this->options[ Fahad_AI_Analytics::OPTION_ENABLED ] );
	}

	public function test_analytics_page_save_toggle_on(): void {
		$this->grant_cap( true );
		Functions\when( 'check_admin_referer' )->justReturn( true );
		$this->seed_analytics( [], false );

		$_POST['fahad_ai_analytics_save'] = '1';
		$_POST['analytics_enabled']       = '1'; // present → stored as 1

		ob_start();
		fahad_ai_analytics_page();
		ob_get_clean();

		$this->assertSame( 1, $this->options[ Fahad_AI_Analytics::OPTION_ENABLED ] );
	}

	// ── export handler ───────────────────────────────────────────────────────────

	public function test_export_handler_403_without_capability(): void {
		$this->grant_cap( false );
		$this->wp_die_throws();

		$this->expectException( Fahad_AI_Cov_WpDie::class );
		fahad_ai_analytics_export_handler();
	}

	public function test_export_handler_streams_json_then_exits(): void {
		$this->grant_cap( true );
		Functions\when( 'check_admin_referer' )->justReturn( true );

		$_POST['from'] = '2026-01-01';
		$_POST['to']   = '2026-12-31';

		// A seeded row that falls in the window so export() returns it and the body
		// builds the JSON document. Output buffering keeps header() from warning
		// ("headers already sent" only fires once output has been flushed to the SAPI).
		$this->seed_analytics(
			[ 'x' => $this->row( [ 'created' => strtotime( '2026-06-01' ) ] ) ],
			true
		);

		// wp_json_encode is the last call before the terminating exit; making it throw
		// runs the whole body (guard, nonce, date parse, export(), nocache + both
		// header() calls, and the echo's array argument) but never reaches exit.
		Functions\when( 'wp_json_encode' )->alias(
			static function ( $payload ) {
				throw new Fahad_AI_Cov_Halt( json_encode( $payload ) );
			}
		);

		$captured = null;
		try {
			ob_start();
			fahad_ai_analytics_export_handler();
			ob_end_clean();
			$this->fail( 'Expected the pre-exit halt.' );
		} catch ( Fahad_AI_Cov_Halt $e ) {
			ob_end_clean();
			$captured = json_decode( $e->getMessage(), true );
		}

		// The streamed document carries the generated time, the row count, and the rows.
		$this->assertSame( 1, $captured['count'] );
		$this->assertCount( 1, $captured['rows'] );
		$this->assertArrayHasKey( 'generated', $captured );
	}

	// ── delete handler ───────────────────────────────────────────────────────────

	public function test_delete_handler_403_without_capability(): void {
		$this->grant_cap( false );
		$this->wp_die_throws();

		$this->expectException( Fahad_AI_Cov_WpDie::class );
		fahad_ai_analytics_delete_handler();
	}

	public function test_delete_handler_purges_then_redirects(): void {
		$this->grant_cap( true );
		Functions\when( 'check_admin_referer' )->justReturn( true );

		// Seed a stored row; purge() (real) must delete the whole option.
		$this->seed_analytics( [ 'x' => $this->row() ], true );

		// wp_safe_redirect is the last call before exit, make it throw so the redirect
		// line runs (purge + redirect covered) but the terminating exit is never hit.
		$redirected_to = null;
		Functions\when( 'wp_safe_redirect' )->alias(
			static function ( $url ) use ( &$redirected_to ) {
				$redirected_to = $url;
				throw new Fahad_AI_Cov_Halt();
			}
		);

		try {
			fahad_ai_analytics_delete_handler();
			$this->fail( 'Expected the redirect halt.' );
		} catch ( Fahad_AI_Cov_Halt $e ) {
			// expected
		}

		$this->assertArrayNotHasKey( Fahad_AI_Analytics::OPTION, $this->options, 'The store is purged before redirecting.' );
		$this->assertNotNull( $redirected_to, 'It redirects back to the dashboard.' );
	}

	// ── fahad_ai_settings_page (guard) ───────────────────────────────────────────

	public function test_settings_page_returns_for_no_capability(): void {
		$this->grant_cap( false );
		ob_start();
		fahad_ai_settings_page();
		$out = ob_get_clean();
		$this->assertSame( '', $out );
	}

	// ── fahad_ai_settings_page (full render, defaults) ───────────────────────────

	public function test_settings_page_renders_form(): void {
		$this->grant_cap( true );

		ob_start();
		fahad_ai_settings_page();
		$out = ob_get_clean();

		$this->assertStringContainsString( 'Dukandar AI Shopping Assistant Settings', $out );
		$this->assertStringContainsString( 'AI Provider', $out );
		$this->assertStringContainsString( 'Anthropic API Key', $out );
		$this->assertStringContainsString( 'Moonshot Region', $out );
		$this->assertStringContainsString( 'WhatsApp', $out );
		// The provider <select> is built from the catalog; anthropic is always present.
		$this->assertStringContainsString( 'name="provider"', $out );
		// Save button rendered by the submit_button stub.
		$this->assertStringContainsString( 'fahad_ai_save', $out );
		// Month-to-date spend context, right where the cost limits are set (#235).
		$this->assertStringContainsString( 'AI Spend This Month', $out );
	}

	// ── fahad_ai_settings_page (index-queued notice + gateable tools list) ───────

	public function test_settings_page_shows_index_notice_and_gateable_tools(): void {
		$this->grant_cap( true );

		$_GET['fahad_ai_indexed'] = '7'; // >= 0 → the "build queued" notice

		// Register a non-built-in tool so the gateable list is non-empty (exercises the
		// else branch + the checkbox foreach). The provider receives the running tool
		// map and returns it with its own entry appended (the registry contract).
		Fahad_AI_Tool_Registry::register_pack(
			static function ( array $tools ) {
				$tools[] = [
					'name'        => 'track_order',
					'description' => 'Track an order',
					'parameters'  => [ 'type' => 'object', 'properties' => [] ],
					'callback'    => static fn( $input ) => [],
				];
				return $tools;
			}
		);
		( new ReflectionProperty( Fahad_AI_Tool_Registry::class, 'instance' ) )->setValue( null, null );

		ob_start();
		fahad_ai_settings_page();
		$out = ob_get_clean();

		$this->assertStringContainsString( 'Search index build queued for 7 products', $out );
		$this->assertStringContainsString( 'track_order', $out );
		$this->assertStringContainsString( 'Track an order', $out );
	}

	// ── fahad_ai_settings_page (full save round-trip) ────────────────────────────

	public function test_settings_page_saves_all_options(): void {
		$this->grant_cap( true );
		Functions\when( 'check_admin_referer' )->justReturn( true );

		$_POST = [
			'fahad_ai_save'         => '1',
			'provider'              => 'anthropic',
			'anthropic_api_key'     => 'sk-test',
			'anthropic_model'       => 'claude-sonnet-4-6',
			'moonshot_region'       => 'china',
			'custom_base_url'       => 'https://api.example.com/v1',
			'bot_name'              => 'Helper',
			'greeting'              => 'Hello there',
			'system_prompt'         => 'Be nice',
			'accent_color'          => '#abcdef',
			'tone'                  => 'professional',
			'off_limits'            => 'politics',
			'promo_emphasis'        => 'Footwear: clearance',
			'return_policy'         => '14-day returns, unopened only.',
			'support_contact'       => 'help@store.example',
			'store_knowledge'       => 'Free gift wrapping on request. Ships worldwide.',
			'disabled_tools'        => [ 'track_order' ],
			'languages'             => 'English, Urdu',
			'token_budget'          => '8000',
			'daily_message_cap'     => '250',
			'free_shipping_threshold' => '50',
			'fast_model_routing'    => '1',
			'fast_model'            => 'claude-haiku-4-5-20251001',
			'proactive_enabled'     => '1',
			'proactive_frequency'   => '2',
			'voice_enabled'         => '1',
			'voice_tts'             => '1',
			'weekly_digest'         => '1',
			'enabled'               => '1',
			'hide_on_checkout'      => '1',
			'whatsapp_enabled'      => '1',
			'whatsapp_verify_token' => 'verify123',
			'whatsapp_app_secret'   => 'secret456',
		];

		ob_start();
		fahad_ai_settings_page();
		$out = ob_get_clean();

		$this->assertStringContainsString( 'Settings saved.', $out );

		// Spot-check a representative slice of the persisted options across each block.
		$this->assertSame( 'anthropic', $this->options['fahad_ai_provider'] );
		$this->assertSame( 'sk-test', $this->options['fahad_ai_anthropic_api_key'] );
		$this->assertSame( 'claude-sonnet-4-6', $this->options['fahad_ai_anthropic_model'] );
		$this->assertSame( 'china', $this->options['fahad_ai_moonshot_region'] );
		$this->assertSame( 'Helper', $this->options['fahad_ai_bot_name'] );
		$this->assertSame( 'Be nice', $this->options['fahad_ai_system_prompt'] );
		$this->assertSame( 'professional', $this->options['fahad_ai_tone'] );
		$this->assertSame( 'English, Urdu', $this->options['fahad_ai_languages'] );
		$this->assertSame( 8000, $this->options['fahad_ai_token_budget'] );
		$this->assertSame( 250, $this->options['fahad_ai_daily_message_cap'] );
		$this->assertSame( 50.0, $this->options['fahad_ai_free_shipping_threshold'] );
		$this->assertSame( '14-day returns, unopened only.', $this->options['fahad_ai_return_policy'] );
		$this->assertSame( 'help@store.example', $this->options['fahad_ai_support_contact'] );
		$this->assertSame( 'Free gift wrapping on request. Ships worldwide.', $this->options['fahad_ai_store_knowledge'] );
		$this->assertSame( 1, $this->options['fahad_ai_fast_model_routing'] );
		$this->assertSame( 1, $this->options['fahad_ai_proactive_enabled'] );
		$this->assertSame( 2, $this->options['fahad_ai_proactive_frequency'] );
		$this->assertSame( 1, $this->options['fahad_ai_voice_enabled'] );
		$this->assertSame( 1, $this->options['fahad_ai_weekly_digest'] );
		$this->assertSame( 1, $this->options['fahad_ai_enabled'] );
		$this->assertSame( 1, $this->options['fahad_ai_hide_on_checkout'] );
		$this->assertSame( 1, $this->options['fahad_ai_whatsapp_enabled'] );
		$this->assertSame( 'verify123', $this->options['fahad_ai_whatsapp_verify_token'] );
		// disabled_tools is run through the slug sanitizer.
		$this->assertSame( [ 'track_order' ], $this->options['fahad_ai_disabled_tools'] );
	}

	// ── fahad_ai_settings_page (save with falsey toggles → model defaults) ───────

	public function test_settings_page_save_with_empty_toggles_and_default_model(): void {
		$this->grant_cap( true );
		Functions\when( 'check_admin_referer' )->justReturn( true );

		// Minimal POST: the boolean toggles are absent (→ 0) and the per-provider model
		// fields are blank (→ the preset default model is stored).
		$_POST = [
			'fahad_ai_save' => '1',
			'provider'      => 'moonshot',
		];

		ob_start();
		fahad_ai_settings_page();
		$out = ob_get_clean();

		$this->assertStringContainsString( 'Settings saved.', $out );
		// Absent provider select still defaults the moonshot region to 'global'.
		$this->assertSame( 'global', $this->options['fahad_ai_moonshot_region'] );
		// Blank model → the anthropic preset default is persisted.
		$this->assertArrayHasKey( 'fahad_ai_anthropic_model', $this->options );
		$this->assertNotSame( '', $this->options['fahad_ai_anthropic_model'] );
		// All boolean toggles default off.
		$this->assertSame( 0, $this->options['fahad_ai_fast_model_routing'] );
		$this->assertSame( 0, $this->options['fahad_ai_proactive_enabled'] );
		$this->assertSame( 0, $this->options['fahad_ai_voice_enabled'] );
		$this->assertSame( 0, $this->options['fahad_ai_voice_tts'] );
		$this->assertSame( 0, $this->options['fahad_ai_whatsapp_enabled'] );
	}

	// ── fahad_ai_settings_page (custom + ollama provider field rendering) ────────

	public function test_settings_page_renders_custom_and_ollama_provider_fields(): void {
		$this->grant_cap( true );

		// Select the custom provider so its base-URL field (the $is_custom branch) and
		// the generic key/model rows render in the visible (non display:none) state.
		$this->options['fahad_ai_provider']        = 'custom';
		$this->options['fahad_ai_custom_base_url']  = 'https://api.custom.test/v1';

		ob_start();
		fahad_ai_settings_page();
		$out = ob_get_clean();

		// Custom provider's base-URL field.
		$this->assertStringContainsString( 'name="custom_base_url"', $out );
		$this->assertStringContainsString( 'https://api.custom.test/v1', $out );
		// Ollama (local) provider's "leave blank" hint ($is_local branch).
		$this->assertStringContainsString( 'local Ollama server', $out );
		// Generic per-provider key field for at least one catalog provider (e.g. openai).
		$this->assertStringContainsString( 'name="openai_api_key"', $out );
	}
}
