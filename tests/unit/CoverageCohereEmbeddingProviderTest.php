<?php
/**
 * Line-coverage tests for Fahad_AI_Cohere_Embedding_Provider.
 *
 * Targets the guard/branch paths the happy-path suite (MultilingualProviderTest)
 * does not reach:
 *   - dimensions() accessor
 *   - empty-input early return ([])
 *   - no-key terminal exception
 *   - transport (WP_Error) retryable exception
 *   - malformed-response terminal exception
 *
 * Faithful TDD: every test asserts real behaviour (return shape, exception
 * type/message/retryability), not a bare smoke call.
 */

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;

class CoverageCohereEmbeddingProviderTest extends TestCase {

	use MockeryPHPUnitIntegration;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		// The provider escapes the transport error message before raising it; pass
		// the input straight through so the assertions read the real text.
		Functions\when( 'esc_html' )->alias( static fn( $s ) => $s );
		Functions\when( 'wp_json_encode' )->alias( static fn( $d ) => json_encode( $d ) );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	// ── model() accessor (line 29) ──────────────────────────────────────────────

	public function test_model_returns_the_default_when_unspecified(): void {
		$p = new Fahad_AI_Cohere_Embedding_Provider( 'co-key' );
		$this->assertSame( 'embed-multilingual-v3.0', $p->model() );
	}

	public function test_model_returns_the_configured_value(): void {
		$p = new Fahad_AI_Cohere_Embedding_Provider( 'co-key', 'embed-english-v3.0' );
		$this->assertSame( 'embed-english-v3.0', $p->model() );
	}

	// ── dimensions() accessor (line 33) ─────────────────────────────────────────

	public function test_dimensions_returns_the_default_when_unspecified(): void {
		// The provider's documented default vector width is 1024.
		$p = new Fahad_AI_Cohere_Embedding_Provider( 'co-key' );
		$this->assertSame( 1024, $p->dimensions() );
	}

	public function test_dimensions_returns_the_configured_value(): void {
		$p = new Fahad_AI_Cohere_Embedding_Provider( 'co-key', 'embed-multilingual-v3.0', 256 );
		$this->assertSame( 256, $p->dimensions() );
	}

	// ── empty input short-circuits before any HTTP call (line 43) ────────────────

	public function test_embed_returns_empty_array_for_no_texts(): void {
		// No texts → no work: returns [] WITHOUT touching the HTTP API. We assert
		// wp_remote_post is never reached by expecting it zero times.
		Functions\expect( 'wp_remote_post' )->never();

		$out = ( new Fahad_AI_Cohere_Embedding_Provider( 'co-key' ) )->embed( [] );

		$this->assertSame( [], $out );
	}

	public function test_embed_returns_empty_array_when_input_keys_are_non_sequential_but_empty(): void {
		// array_values() on an empty (but oddly-keyed) array still yields no texts,
		// so the same early return applies.
		Functions\expect( 'wp_remote_post' )->never();

		$out = ( new Fahad_AI_Cohere_Embedding_Provider( 'co-key' ) )->embed( [] + [] );

		$this->assertSame( [], $out );
	}

	// ── no API key → terminal exception, before any HTTP call (line 46) ──────────

	public function test_embed_throws_terminal_exception_when_no_key_configured(): void {
		Functions\expect( 'wp_remote_post' )->never();

		$provider = new Fahad_AI_Cohere_Embedding_Provider( '' );

		try {
			$provider->embed( [ 'something to embed' ] );
			$this->fail( 'Expected a Fahad_AI_Embedding_Exception when no key is configured.' );
		} catch ( Fahad_AI_Embedding_Exception $e ) {
			$this->assertSame( 'No Cohere API key configured.', $e->getMessage() );
			$this->assertFalse( $e->is_retryable(), 'A missing key is terminal, not retryable.' );
		}
	}

	// ── transport failure (WP_Error) → retryable exception (line 69) ─────────────

	public function test_embed_throws_retryable_exception_on_transport_error(): void {
		$wp_error = new WP_Error( 'http_request_failed', 'Connection timed out' );
		Functions\when( 'wp_remote_post' )->justReturn( $wp_error );

		$provider = new Fahad_AI_Cohere_Embedding_Provider( 'co-key' );

		try {
			$provider->embed( [ 'x' ] );
			$this->fail( 'Expected a transport exception.' );
		} catch ( Fahad_AI_Embedding_Exception $e ) {
			// The error message from the WP_Error is surfaced in the exception text.
			$this->assertStringContainsString( 'Cohere transport error', $e->getMessage() );
			$this->assertStringContainsString( 'Connection timed out', $e->getMessage() );
			// A transport blip is transient → the indexer should back off and retry.
			$this->assertTrue( $e->is_retryable(), 'A transport error must be retryable.' );
		}
	}

	// ── non-200 HTTP status → exception with retryability per code (lines 74, 76) ─

	public function test_embed_throws_retryable_exception_on_http_429(): void {
		// 429 (rate limit) is transient → retryable so the indexer backs off.
		Functions\when( 'wp_remote_post' )->justReturn( [ 'ok' => true ] );
		Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 429 );
		Functions\when( 'wp_remote_retrieve_body' )->justReturn( '{}' );

		try {
			( new Fahad_AI_Cohere_Embedding_Provider( 'co-key' ) )->embed( [ 'x' ] );
			$this->fail( 'Expected an HTTP-status exception.' );
		} catch ( Fahad_AI_Embedding_Exception $e ) {
			$this->assertStringContainsString( 'Cohere API returned HTTP 429', $e->getMessage() );
			$this->assertTrue( $e->is_retryable(), '429 is transient → retryable.' );
		}
	}

	public function test_embed_throws_retryable_exception_on_http_503(): void {
		// Any 5xx is a server-side blip → retryable.
		Functions\when( 'wp_remote_post' )->justReturn( [ 'ok' => true ] );
		Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 503 );
		Functions\when( 'wp_remote_retrieve_body' )->justReturn( '{}' );

		try {
			( new Fahad_AI_Cohere_Embedding_Provider( 'co-key' ) )->embed( [ 'x' ] );
			$this->fail( 'Expected an HTTP-status exception.' );
		} catch ( Fahad_AI_Embedding_Exception $e ) {
			$this->assertStringContainsString( 'Cohere API returned HTTP 503', $e->getMessage() );
			$this->assertTrue( $e->is_retryable(), '5xx is transient → retryable.' );
		}
	}

	public function test_embed_throws_terminal_exception_on_http_400(): void {
		// A 4xx (other than 429) is a client error → terminal, not retryable.
		Functions\when( 'wp_remote_post' )->justReturn( [ 'ok' => true ] );
		Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 400 );
		Functions\when( 'wp_remote_retrieve_body' )->justReturn( '{}' );

		try {
			( new Fahad_AI_Cohere_Embedding_Provider( 'co-key' ) )->embed( [ 'x' ] );
			$this->fail( 'Expected an HTTP-status exception.' );
		} catch ( Fahad_AI_Embedding_Exception $e ) {
			$this->assertStringContainsString( 'Cohere API returned HTTP 400', $e->getMessage() );
			$this->assertFalse( $e->is_retryable(), '4xx (non-429) is terminal.' );
		}
	}

	// ── malformed upstream body → terminal exception (line 81) ───────────────────

	public function test_embed_throws_terminal_exception_on_malformed_response(): void {
		// 200 OK but the embeddings payload is missing the float vectors entirely.
		Functions\when( 'wp_remote_post' )->justReturn( [ 'ok' => true ] );
		Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 200 );
		Functions\when( 'wp_remote_retrieve_body' )->justReturn( json_encode( [ 'embeddings' => [] ] ) );

		$provider = new Fahad_AI_Cohere_Embedding_Provider( 'co-key' );

		try {
			$provider->embed( [ 'x' ] );
			$this->fail( 'Expected a malformed-response exception.' );
		} catch ( Fahad_AI_Embedding_Exception $e ) {
			$this->assertSame( 'Malformed Cohere response.', $e->getMessage() );
			// A bad shape from a 200 is not worth retrying — it is terminal.
			$this->assertFalse( $e->is_retryable() );
		}
	}

	public function test_embed_throws_when_float_vectors_are_not_an_array(): void {
		// The empty()-OR-not-array guard also fires when `float` is present but
		// scalar (e.g. a stray null/string), not a list of vectors.
		Functions\when( 'wp_remote_post' )->justReturn( [ 'ok' => true ] );
		Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 200 );
		Functions\when( 'wp_remote_retrieve_body' )->justReturn( json_encode( [ 'embeddings' => [ 'float' => 'nope' ] ] ) );

		$this->expectException( Fahad_AI_Embedding_Exception::class );
		$this->expectExceptionMessage( 'Malformed Cohere response.' );

		( new Fahad_AI_Cohere_Embedding_Provider( 'co-key' ) )->embed( [ 'x' ] );
	}

	// ── happy path also re-covered here so the file is self-contained ───────────

	public function test_embed_parses_float_vectors_and_casts_to_float(): void {
		Functions\when( 'wp_remote_post' )->justReturn( [ 'ok' => true ] );
		Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 200 );
		// Mix ints and numeric strings to prove the floatval() cast runs.
		Functions\when( 'wp_remote_retrieve_body' )->justReturn(
			json_encode( [ 'embeddings' => [ 'float' => [ [ 1, '2.5' ], [ 0.1, 0.2 ] ] ] ] )
		);

		$out = ( new Fahad_AI_Cohere_Embedding_Provider( 'co-key' ) )->embed( [ 'a', 'b' ] );

		$this->assertSame( [ [ 1.0, 2.5 ], [ 0.1, 0.2 ] ], $out );
	}
}
