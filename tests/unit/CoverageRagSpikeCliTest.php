<?php
/**
 * Coverage tests for Fahad_AI_Rag_Spike_CLI (the `wp fahad-ai rag-spike` runner).
 *
 * The CLI file is NOT loaded by tests/bootstrap.php: it guards itself with a
 * file-scope `return;` unless `WP_CLI` is defined, and ends with a
 * `\WP_CLI::add_command()` call. So this test stands up a minimal `WP_CLI`
 * stub (constant + class) and `FAHAD_AI_PATH`, then require_once's the file —
 * which is what exercises the file-scope guards and the add_command line.
 *
 * Every WordPress/WooCommerce dependency the command touches is stubbed via
 * Brain\Monkey so the dataset builder, the OpenAI embed() call, the report
 * renderer and both the canned (offline) and live (keyed) paths run with no
 * spend and no network.
 */

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;

// ── Minimal WP_CLI surface, defined once before the CLI file loads ───────────
// The command calls WP_CLI::log/success/warning; the file's last line calls
// WP_CLI::add_command. We capture those calls statically so tests can assert
// the command logged progress and reported the write outcome. Guarded so a real
// WP_CLI (it won't exist in unit tests) is never shadowed.
if ( ! class_exists( 'WP_CLI' ) ) {
	class WP_CLI {
		/** @var array<int,string> */
		public static array $logs = [];
		public static ?string $success = null;
		public static ?string $warning = null;
		/** @var array<string,string> command => handler class */
		public static array $commands = [];

		public static function reset(): void {
			self::$logs    = [];
			self::$success = null;
			self::$warning = null;
		}
		public static function log( string $message ): void { self::$logs[] = $message; }
		public static function success( string $message ): void { self::$success = $message; }
		public static function warning( string $message ): void { self::$warning = $message; }
		public static function add_command( string $name, $handler ): void { self::$commands[ $name ] = $handler; }
	}
}

if ( ! defined( 'WP_CLI' ) ) {
	define( 'WP_CLI', true );
}

if ( ! defined( 'FAHAD_AI_PATH' ) ) {
	define( 'FAHAD_AI_PATH', sys_get_temp_dir() . '/fahad-ai-rag-spike-cli-test/' );
}

class CoverageRagSpikeCliTest extends TestCase {

	use MockeryPHPUnitIntegration;

	/** @var string[] temp report paths to clean up */
	private array $tmp_files = [];

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		WP_CLI::reset();

		// Load the CLI file lazily, inside a running test, so pcov attributes its
		// file-scope guards (the WP_CLI gate) and the trailing add_command() line to
		// this suite. WP_CLI is defined truthy + ABSPATH is set (wc-stubs), so the
		// file-scope guards fall through and the class is declared exactly once.
		require_once dirname( __DIR__, 2 ) . '/includes/class-rag-spike-cli.php';

		// Pass-through / harmless WordPress stubs the command and its helpers reach.
		Functions\when( 'wp_strip_all_tags' )->alias( static fn( $s ) => trim( preg_replace( '/<[^>]*>/', '', (string) $s ) ) );
		Functions\when( 'wp_json_encode' )->alias( static fn( $d ) => json_encode( $d ) );
		Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 200 );
	}

	protected function tearDown(): void {
		foreach ( $this->tmp_files as $f ) {
			if ( is_file( $f ) ) {
				unlink( $f );
			}
		}
		$this->tmp_files = [];
		Monkey\tearDown();
		parent::tearDown();
	}

	private function cli(): Fahad_AI_Rag_Spike_CLI {
		return new Fahad_AI_Rag_Spike_CLI();
	}

	private function tmp_path( string $name ): string {
		$path              = sys_get_temp_dir() . '/' . $name;
		$this->tmp_files[] = $path;
		return $path;
	}

	// ── file-scope guards + registration ──────────────────────────────────────

	public function test_file_registers_the_command_at_load_time(): void {
		// Line 240 ran when the file was required: the command is registered to the
		// CLI class. This proves the file-scope guards (ABSPATH ok, WP_CLI truthy)
		// fell through rather than `return;`-ing or `exit`-ing.
		$this->assertArrayHasKey( 'fahad-ai rag-spike', WP_CLI::$commands );
		$this->assertSame( Fahad_AI_Rag_Spike_CLI::class, WP_CLI::$commands['fahad-ai rag-spike'] );
	}

	// ── __invoke: offline/canned happy path ───────────────────────────────────

	public function test_invoke_offline_writes_report_and_reports_success(): void {
		// No key configured → canned (offline) dataset path through build_dataset().
		Functions\when( 'get_option' )->justReturn( '' );

		$path = $this->tmp_path( 'rag-spike-canned-' . uniqid() . '.md' );
		$this->cli()->__invoke( [], [ 'k' => '2', 'sizes' => '10,20', 'report' => $path ] );

		// Progress was logged with the canned mode label and the relevance header.
		$joined = implode( "\n", WP_CLI::$logs );
		$this->assertStringContainsString( 'canned (offline, no key)', $joined );
		$this->assertStringContainsString( 'recall@2', $joined );
		$this->assertStringContainsString( 'MEAN:', $joined );
		$this->assertStringContainsString( 'latency projection', $joined );

		// Two sizes were projected → two latency log lines containing p50/p95.
		$latency_lines = array_filter( WP_CLI::$logs, static fn( $l ) => str_contains( $l, 'p50=' ) );
		$this->assertCount( 2, $latency_lines );

		// Report was written; success (not warning) reported.
		$this->assertFileExists( $path );
		$this->assertNotNull( WP_CLI::$success );
		$this->assertStringContainsString( $path, WP_CLI::$success );
		$this->assertNull( WP_CLI::$warning );

		$report = file_get_contents( $path );
		$this->assertStringContainsString( '# RAG Spike Report (Phase 0)', $report );
		// Canned, hybrid-wins golden set → provisional GO verdict.
		$this->assertStringContainsString( 'GO (provisional)', $report );
		$this->assertStringContainsString( 'canned (offline, no key)', $report );
	}

	public function test_invoke_defaults_when_no_assoc_args_given(): void {
		// Empty $assoc exercises the ?? fallbacks for k, sizes and report path.
		Functions\when( 'get_option' )->justReturn( '' );

		$default_path = FAHAD_AI_PATH . 'docs/RAG-SPIKE-REPORT.md';
		$this->tmp_files[] = $default_path;
		if ( ! is_dir( dirname( $default_path ) ) ) {
			mkdir( dirname( $default_path ), 0777, true );
		}

		$this->cli()->__invoke( [], [] );

		// Default recall@3 and the default three sizes were used.
		$joined = implode( "\n", WP_CLI::$logs );
		$this->assertStringContainsString( 'recall@3', $joined );
		$latency_lines = array_filter( WP_CLI::$logs, static fn( $l ) => str_contains( $l, 'p50=' ) );
		$this->assertCount( 3, $latency_lines, 'default 1000,5000,20000 → three projections' );

		// Wrote to the default FAHAD_AI_PATH report location.
		$this->assertFileExists( $default_path );
		$this->assertNotNull( WP_CLI::$success );
	}

	public function test_invoke_warns_when_report_cannot_be_written(): void {
		Functions\when( 'get_option' )->justReturn( '' );

		// An unwritable path makes file_put_contents return false → warning branch.
		// file_put_contents emits a PHP warning when the dir is missing; that warning
		// is the point of the branch, so swallow it locally (PHPUnit would otherwise
		// surface it) while still asserting the command's own WP_CLI::warning path.
		$bad_path = '/this/directory/definitely/does/not/exist/report.md';
		set_error_handler( static fn() => true );
		try {
			$this->cli()->__invoke( [], [ 'report' => $bad_path ] );
		} finally {
			restore_error_handler();
		}

		$this->assertNull( WP_CLI::$success );
		$this->assertNotNull( WP_CLI::$warning );
		$this->assertStringContainsString( $bad_path, WP_CLI::$warning );
	}

	public function test_invoke_clamps_k_below_one_to_one(): void {
		Functions\when( 'get_option' )->justReturn( '' );

		$path = $this->tmp_path( 'rag-spike-kclamp-' . uniqid() . '.md' );
		$this->cli()->__invoke( [], [ 'k' => '0', 'report' => $path ] );

		// max(1, 0) → recall@1 in the logged header and the report.
		$joined = implode( "\n", WP_CLI::$logs );
		$this->assertStringContainsString( 'recall@1', $joined );
	}

	public function test_invoke_filters_empty_sizes_from_csv(): void {
		Functions\when( 'get_option' )->justReturn( '' );

		$path = $this->tmp_path( 'rag-spike-sizes-' . uniqid() . '.md' );
		// Trailing/empty tokens and a zero are filtered out by array_filter.
		$this->cli()->__invoke( [], [ 'sizes' => '5,,0,7', 'report' => $path ] );

		$latency_lines = array_filter( WP_CLI::$logs, static fn( $l ) => str_contains( $l, 'p50=' ) );
		$this->assertCount( 2, $latency_lines, 'only 5 and 7 survive intval+filter' );
	}

	// ── __invoke: live (keyed) happy path ─────────────────────────────────────

	public function test_invoke_live_path_uses_real_embeddings(): void {
		// A configured key + wc_get_products + a 200 embeddings response drives the
		// full live_dataset()/embed() path and the 'live embeddings' verdict copy.
		Functions\when( 'get_option' )->justReturn( 'sk-test-key' );

		$products = [
			$this->product( 38, 'Premium Pullover Hoodie', 'warm fleece', '' ),
			$this->product( 12, 'Wireless Headphones', 'noise cancelling audio', '' ),
			$this->product( 13, 'Steel Water Bottle', 'insulated hydration flask', '' ),
		];
		Functions\when( 'wc_get_products' )->justReturn( $products );

		// 3 product docs + 3 query texts = 6 embeddings, each a 4-dim vector.
		$embeddings = [
			[ 'embedding' => [ 0.9, 0.0, 0.0, 0.0 ] ], // hoodie
			[ 'embedding' => [ 0.0, 0.0, 1.0, 0.0 ] ], // headphones
			[ 'embedding' => [ 0.0, 0.0, 0.0, 1.0 ] ], // bottle
			[ 'embedding' => [ 1.0, 0.0, 0.0, 0.0 ] ], // warm-top query
			[ 'embedding' => [ 0.0, 0.0, 1.0, 0.0 ] ], // audio query
			[ 'embedding' => [ 0.0, 0.0, 0.0, 1.0 ] ], // hydrate query
		];
		Functions\when( 'wp_remote_post' )->justReturn( [ 'body' => 'ok' ] );
		Functions\when( 'wp_remote_retrieve_body' )->justReturn( json_encode( [ 'data' => $embeddings ] ) );

		$path = $this->tmp_path( 'rag-spike-live-' . uniqid() . '.md' );
		$this->cli()->__invoke( [], [ 'k' => '3', 'sizes' => '10', 'report' => $path ] );

		$joined = implode( "\n", WP_CLI::$logs );
		$this->assertStringContainsString( 'live embeddings', $joined );
		$this->assertNotNull( WP_CLI::$success );

		$report = file_get_contents( $path );
		$this->assertStringContainsString( 'live embeddings', $report );
		// Live + hybrid wins → the non-provisional GO copy.
		$this->assertStringContainsString( 'GO', $report );
		$this->assertStringNotContainsString( 'GO (provisional)', $report );
	}

	public function test_invoke_falls_back_to_canned_when_live_dataset_is_null(): void {
		// Key configured but wc_get_products returns empty → live_dataset() returns
		// null → build_dataset() falls through to canned. Proves the fallback.
		Functions\when( 'get_option' )->justReturn( 'sk-test-key' );
		Functions\when( 'wc_get_products' )->justReturn( [] );

		$path = $this->tmp_path( 'rag-spike-fallback-' . uniqid() . '.md' );
		$this->cli()->__invoke( [], [ 'report' => $path ] );

		$joined = implode( "\n", WP_CLI::$logs );
		$this->assertStringContainsString( 'canned (offline, no key)', $joined );
		$this->assertNotNull( WP_CLI::$success );
	}

	// ── live_dataset() branch coverage via reflection ─────────────────────────

	private function call_private( string $method, array $args ) {
		$ref = new ReflectionMethod( Fahad_AI_Rag_Spike_CLI::class, $method );
		$ref->setAccessible( true );
		return $ref->invokeArgs( $this->cli(), $args );
	}

	public function test_live_dataset_returns_null_when_no_products(): void {
		Functions\when( 'wc_get_products' )->justReturn( false );
		$this->assertNull( $this->call_private( 'live_dataset', [ 'sk-key' ] ) );
	}

	public function test_live_dataset_returns_null_when_embed_fails(): void {
		Functions\when( 'wc_get_products' )->justReturn( [ $this->product( 1, 'Thing', '', '' ) ] );
		// embed() returns null (non-200) → live_dataset short-circuits to null.
		Functions\when( 'wp_remote_post' )->justReturn( [ 'body' => '' ] );
		Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 500 );

		$this->assertNull( $this->call_private( 'live_dataset', [ 'sk-key' ] ) );
	}

	public function test_live_dataset_returns_null_when_no_query_matches_catalog(): void {
		// A product whose name matches none of the golden-query regexes → every
		// query ends up with an empty `relevant`, so $queries stays empty → null.
		Functions\when( 'wc_get_products' )->justReturn( [ $this->product( 1, 'Plain Notebook', '', '' ) ] );
		Functions\when( 'wp_remote_post' )->justReturn( [ 'body' => 'ok' ] );
		Functions\when( 'wp_remote_retrieve_body' )->justReturn(
			json_encode( [ 'data' => [
				[ 'embedding' => [ 0.1, 0.2, 0.3, 0.4 ] ], // the one product doc
				[ 'embedding' => [ 0.0, 0.0, 0.0, 0.0 ] ], // 3 query texts
				[ 'embedding' => [ 0.0, 0.0, 0.0, 0.0 ] ],
				[ 'embedding' => [ 0.0, 0.0, 0.0, 0.0 ] ],
			] ] )
		);

		$this->assertNull( $this->call_private( 'live_dataset', [ 'sk-key' ] ) );
	}

	public function test_live_dataset_builds_texts_vecs_and_queries_on_success(): void {
		Functions\when( 'wc_get_products' )->justReturn( [
			$this->product( 38, 'Cozy Hoodie', 'fleece pullover', 'super warm' ),
			$this->product( 12, 'Bluetooth Earbuds', 'audio', '' ),
		] );
		Functions\when( 'wp_remote_post' )->justReturn( [ 'body' => 'ok' ] );
		Functions\when( 'wp_remote_retrieve_body' )->justReturn(
			json_encode( [ 'data' => [
				[ 'embedding' => [ 0.9, 0.0, 0.0, 0.0 ] ], // hoodie doc
				[ 'embedding' => [ 0.0, 0.0, 1.0, 0.0 ] ], // earbuds doc
				[ 'embedding' => [ 1.0, 0.0, 0.0, 0.0 ] ], // warm query
				[ 'embedding' => [ 0.0, 0.0, 1.0, 0.0 ] ], // audio query
				[ 'embedding' => [ 0.0, 0.0, 0.0, 1.0 ] ], // hydrate query (no match)
			] ] )
		);

		$result = $this->call_private( 'live_dataset', [ 'sk-key' ] );

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'texts', $result );
		$this->assertArrayHasKey( 'vecs', $result );
		$this->assertArrayHasKey( 'queries', $result );

		// Texts keyed by product id, name-then-stripped-description composed.
		$this->assertSame( [ 38, 12 ], array_keys( $result['texts'] ) );
		$this->assertStringContainsString( 'Cozy Hoodie', $result['texts'][38] );

		// Vecs realigned to product ids from the leading slice of the embedding list.
		$this->assertSame( [ 0.9, 0.0, 0.0, 0.0 ], $result['vecs'][38] );
		$this->assertSame( [ 0.0, 0.0, 1.0, 0.0 ], $result['vecs'][12] );

		// The hydrate query matched nothing → only the warm + audio queries survive.
		$names = array_column( $result['queries'], 'name' );
		$this->assertContains( 'warm top (semantic)', $names );
		$this->assertContains( 'audio device (semantic)', $names );
		$this->assertNotContains( 'stay hydrated (semantic)', $names );
		// Each surviving query carries its own embedding (from the tail slice).
		$this->assertSame( [ 1.0, 0.0, 0.0, 0.0 ], $result['queries'][0]['vec'] );
		$this->assertSame( [ 38 ], $result['queries'][0]['relevant'] );
	}

	// ── embed() branch coverage ───────────────────────────────────────────────

	public function test_embed_returns_null_on_wp_error(): void {
		Functions\when( 'wp_remote_post' )->justReturn( new WP_Error( 'http', 'boom' ) );
		$this->assertNull( $this->call_private( 'embed', [ [ 'hi' ], 'sk-key' ] ) );
	}

	public function test_embed_returns_null_on_non_200(): void {
		Functions\when( 'wp_remote_post' )->justReturn( [ 'body' => 'nope' ] );
		Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 429 );
		$this->assertNull( $this->call_private( 'embed', [ [ 'hi' ], 'sk-key' ] ) );
	}

	public function test_embed_returns_null_when_data_is_empty(): void {
		Functions\when( 'wp_remote_post' )->justReturn( [ 'body' => '{}' ] );
		Functions\when( 'wp_remote_retrieve_body' )->justReturn( json_encode( [ 'data' => [] ] ) );
		$this->assertNull( $this->call_private( 'embed', [ [ 'hi' ], 'sk-key' ] ) );
	}

	public function test_embed_returns_floatified_vectors_on_success(): void {
		Functions\when( 'wp_remote_post' )->justReturn( [ 'body' => 'ok' ] );
		Functions\when( 'wp_remote_retrieve_body' )->justReturn(
			json_encode( [ 'data' => [
				[ 'embedding' => [ '1', '2', '3' ] ],
				[ 'embedding' => [ '4', '5', '6' ] ],
			] ] )
		);

		$vectors = $this->call_private( 'embed', [ [ 'a', 'b' ], 'sk-key' ] );

		$this->assertSame( [ [ 1.0, 2.0, 3.0 ], [ 4.0, 5.0, 6.0 ] ], $vectors );
		// Every element is a real float, not the JSON string form.
		$this->assertIsFloat( $vectors[0][0] );
	}

	// ── render_report() verdict branches ──────────────────────────────────────

	private function render( array $cmp, array $latency, int $k, string $mode ): string {
		return $this->call_private( 'render_report', [ $cmp, $latency, $k, $mode ] );
	}

	private function cmp_fixture( float $kw, float $vec, float $hyb ): array {
		return [
			'queries' => [
				[ 'name' => 'q1', 'keyword' => $kw, 'vector' => $vec, 'hybrid' => $hyb ],
			],
			'mean' => [ 'keyword' => $kw, 'vector' => $vec, 'hybrid' => $hyb ],
			'k'    => 3,
		];
	}

	public function test_render_report_go_for_live_embeddings_when_hybrid_wins(): void {
		$report = $this->render(
			$this->cmp_fixture( 0.5, 0.6, 0.9 ),
			[ [ 'size' => 1000, 'p50_ms' => 1.2, 'p95_ms' => 3.4 ] ],
			3,
			'live embeddings'
		);

		$this->assertStringContainsString( '**GO** —', $report );
		$this->assertStringNotContainsString( 'provisional', $report );
		$this->assertStringContainsString( '| q1 | 0.50 | 0.60 | 0.90 |', $report );
		$this->assertStringContainsString( '| 1000 | 1.2 | 3.4 |', $report );
		$this->assertStringContainsString( 'Worst-case p95', $report );
		$this->assertStringContainsString( '3.4 ms', $report );
	}

	public function test_render_report_provisional_go_for_canned_when_hybrid_wins(): void {
		$report = $this->render(
			$this->cmp_fixture( 0.5, 0.6, 0.9 ),
			[
				[ 'size' => 1000, 'p50_ms' => 1.0, 'p95_ms' => 2.0 ],
				[ 'size' => 5000, 'p50_ms' => 5.0, 'p95_ms' => 9.5 ],
			],
			3,
			'canned (offline, no key)'
		);

		$this->assertStringContainsString( '**GO (provisional)**', $report );
		// Worst-case p95 is the max across rows (9.5, not 2.0).
		$this->assertStringContainsString( '9.5 ms', $report );
	}

	public function test_render_report_no_go_when_hybrid_does_not_beat_keyword(): void {
		// hybrid == keyword (not strictly greater) → NO-GO.
		$report = $this->render(
			$this->cmp_fixture( 0.8, 0.4, 0.8 ),
			[ [ 'size' => 1000, 'p50_ms' => 1.0, 'p95_ms' => 2.0 ] ],
			3,
			'live embeddings'
		);

		$this->assertStringContainsString( '**NO-GO**', $report );
		$this->assertStringNotContainsString( '**GO**', $report );
	}

	public function test_render_report_no_go_when_hybrid_below_vector(): void {
		// hybrid > keyword but hybrid < vector → fails the >= vector half → NO-GO.
		$report = $this->render(
			$this->cmp_fixture( 0.3, 0.9, 0.5 ),
			[ [ 'size' => 1000, 'p50_ms' => 1.0, 'p95_ms' => 2.0 ] ],
			3,
			'live embeddings'
		);

		$this->assertStringContainsString( '**NO-GO**', $report );
	}

	// ── canned() golden set shape ─────────────────────────────────────────────

	public function test_canned_returns_aligned_texts_vecs_and_queries(): void {
		$ref = new ReflectionMethod( Fahad_AI_Rag_Spike_CLI::class, 'canned' );
		$ref->setAccessible( true );
		$canned = $ref->invoke( null );

		$this->assertSame( array_keys( $canned['texts'] ), array_keys( $canned['vecs'] ) );
		$this->assertCount( 7, $canned['texts'] );
		$this->assertCount( 3, $canned['queries'] );
		$this->assertStringContainsString( 'Hoodie', $canned['texts'][38] );
		$this->assertSame( [ 38 ], $canned['queries'][0]['relevant'] );
	}

	// ── product test double ───────────────────────────────────────────────────

	/** Build a Mockery WC_Product double exposing only what the command reads. */
	private function product( int $id, string $name, string $short, string $long ): WC_Product {
		$p = Mockery::mock( WC_Product::class );
		$p->shouldReceive( 'get_id' )->andReturn( $id );
		$p->shouldReceive( 'get_name' )->andReturn( $name );
		$p->shouldReceive( 'get_short_description' )->andReturn( $short );
		$p->shouldReceive( 'get_description' )->andReturn( $long );
		return $p;
	}
}
