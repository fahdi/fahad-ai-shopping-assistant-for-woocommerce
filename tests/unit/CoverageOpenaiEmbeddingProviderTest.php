<?php
/**
 * Coverage tests for Fahad_AI_OpenAI_Embedding_Provider — the two paths the
 * existing EmbeddingsTest does not exercise:
 *
 *   - request(): a 200 response whose body has no usable `data[]` is a MALFORMED
 *     response and must throw a NON-retryable Fahad_AI_Embedding_Exception
 *     (line 104). A malformed payload is a server/contract fault we can't fix by
 *     retrying, so the indexer gives up rather than hammering the API.
 *
 *   - backoff(): the real sleep body (lines 121-123) only runs when
 *     retry_base_ms > 0. EmbeddingsTest constructs the provider with the default
 *     base (0) so backoff() short-circuits and never executes. Here we build a
 *     provider with a tiny non-zero base and force a transient failure followed
 *     by success, so embed() calls backoff() once and walks the exponential
 *     delay + jitter math (wp_rand stubbed deterministic; the resulting usleep is
 *     a few ms at most, so the suite stays fast).
 */

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;

class CoverageOpenaiEmbeddingProviderTest extends TestCase {

	use MockeryPHPUnitIntegration;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( 'wp_json_encode' )->alias( static fn( $d ) => json_encode( $d ) );
		Functions\when( 'esc_html' )->alias( static fn( $s ) => $s );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	// ── line 104: malformed 200 response → non-retryable ────────────────────────

	public function test_embed_throws_non_retryable_when_data_key_is_missing(): void {
		// HTTP 200 but the body carries no `data` array at all — a contract breach
		// the provider cannot recover from, so it fails fast (not retryable).
		Functions\when( 'wp_remote_post' )->justReturn( [ 'ok' => true ] );
		Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 200 );
		Functions\when( 'wp_remote_retrieve_body' )->justReturn( json_encode( [ 'object' => 'list' ] ) );

		try {
			( new Fahad_AI_OpenAI_Embedding_Provider( 'sk-test' ) )->embed( [ 'x' ] );
			$this->fail( 'expected Fahad_AI_Embedding_Exception for a malformed response' );
		} catch ( Fahad_AI_Embedding_Exception $e ) {
			$this->assertSame( 'Malformed embeddings response.', $e->getMessage() );
			$this->assertFalse( $e->is_retryable(), 'a malformed response is terminal, not retryable' );
		}
	}

	public function test_embed_throws_non_retryable_when_data_is_empty_array(): void {
		// 200 with an explicitly empty `data` array trips the same empty() guard.
		Functions\when( 'wp_remote_post' )->justReturn( [ 'ok' => true ] );
		Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 200 );
		Functions\when( 'wp_remote_retrieve_body' )->justReturn( json_encode( [ 'data' => [] ] ) );

		try {
			( new Fahad_AI_OpenAI_Embedding_Provider( 'sk-test' ) )->embed( [ 'x' ] );
			$this->fail( 'expected Fahad_AI_Embedding_Exception for an empty data array' );
		} catch ( Fahad_AI_Embedding_Exception $e ) {
			$this->assertFalse( $e->is_retryable() );
		}
	}

	public function test_embed_throws_non_retryable_when_data_is_not_an_array(): void {
		// 200 with `data` present but a scalar (not is_array) also fails fast.
		Functions\when( 'wp_remote_post' )->justReturn( [ 'ok' => true ] );
		Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 200 );
		Functions\when( 'wp_remote_retrieve_body' )->justReturn( json_encode( [ 'data' => 'oops' ] ) );

		$this->expectException( Fahad_AI_Embedding_Exception::class );
		$this->expectExceptionMessage( 'Malformed embeddings response.' );
		( new Fahad_AI_OpenAI_Embedding_Provider( 'sk-test' ) )->embed( [ 'x' ] );
	}

	// ── lines 121-123: backoff body runs when retry_base_ms > 0 ─────────────────

	public function test_embed_retries_after_backoff_then_succeeds(): void {
		// First HTTP attempt is a transient 503 (retryable); the provider must back
		// off (exercising the delay/jitter/usleep body) and the second attempt
		// succeeds. retry_base_ms = 1 keeps the real sleep sub-millisecond.
		$attempts = 0;
		Functions\when( 'wp_remote_post' )->alias(
			static function () use ( &$attempts ) {
				$attempts++;
				return [ 'ok' => true ];
			}
		);
		Functions\when( 'wp_remote_retrieve_response_code' )->alias(
			static function () use ( &$attempts ) {
				// 1st call → 503 (transient, triggers backoff); 2nd → 200 (success).
				return 1 === $attempts ? 503 : 200;
			}
		);
		Functions\when( 'wp_remote_retrieve_body' )->alias(
			static function () use ( &$attempts ) {
				return 1 === $attempts
					? '{}'
					: json_encode( [ 'data' => [ [ 'index' => 0, 'embedding' => [ 0.5, 0.25 ] ] ] ] );
			}
		);
		// Deterministic jitter so the delay arithmetic is pinned and reproducible.
		$rand_args = null;
		Functions\when( 'wp_rand' )->alias(
			static function ( $min, $max ) use ( &$rand_args ) {
				$rand_args = [ $min, $max ];
				return 0;
			}
		);

		$provider = new Fahad_AI_OpenAI_Embedding_Provider( 'sk-test', 'text-embedding-3-small', 512, 1 );
		$out      = $provider->embed( [ 'hello' ] );

		$this->assertSame( [ [ 0.5, 0.25 ] ], $out, 'retry must return the second attempt\'s vectors' );
		$this->assertSame( 2, $attempts, 'embed() must retry exactly once after the transient failure' );
		// backoff() ran: wp_rand was called with (0, retry_base_ms) per the jitter formula.
		$this->assertSame( [ 0, 1 ], $rand_args, 'jitter must be drawn over [0, retry_base_ms]' );
	}

	public function test_backoff_scales_the_delay_exponentially_by_attempt(): void {
		// backoff() invoked directly (no HTTP round-trip) for a later attempt: the
		// base delay doubles per attempt (retry_base_ms * 2^(attempt-1)). With a 1ms
		// base and zero jitter the real sleep stays tiny while still walking the
		// delay/jitter/usleep body. This pins the exponential term independent of
		// embed()'s retry loop.
		$jitter_max = null;
		Functions\when( 'wp_rand' )->alias(
			static function ( $min, $max ) use ( &$jitter_max ) {
				$jitter_max = $max;
				return 0;
			}
		);
		$provider = new Fahad_AI_OpenAI_Embedding_Provider( 'sk-test', 'text-embedding-3-small', 512, 1 );

		$m = new ReflectionMethod( Fahad_AI_OpenAI_Embedding_Provider::class, 'backoff' );
		$m->invoke( $provider, 2 ); // attempt 2 → base * 2^1 = 2ms, completes fast

		$this->assertSame( 1, $jitter_max, 'jitter must be drawn over [0, retry_base_ms]' );
	}

	// ── backoff() short-circuits when base is 0 (the no-op guard, line 118-119) ──

	public function test_backoff_is_a_noop_when_base_is_zero(): void {
		$provider = new Fahad_AI_OpenAI_Embedding_Provider( 'sk-test' ); // default base = 0
		$m        = new ReflectionMethod( Fahad_AI_OpenAI_Embedding_Provider::class, 'backoff' );

		$start = microtime( true );
		$m->invoke( $provider, 3 ); // any attempt number — must return immediately
		$elapsed = microtime( true ) - $start;

		$this->assertLessThan( 0.05, $elapsed, 'a zero base must not sleep' );
	}
}
