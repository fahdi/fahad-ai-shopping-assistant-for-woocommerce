<?php
/**
 * Supplemental coverage for Fahad_AI_Embeddings::provider() — the Cohere branch.
 *
 * EmbeddingsTest only exercises the OpenAI-compatible path; the Cohere branch
 * (provider type === 'cohere') and, specifically, its keyless `: null` fallback
 * are left uncovered there. These tests drive both sides of that branch.
 */

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;

class CoverageEmbeddingsTest extends TestCase {

	use MockeryPHPUnitIntegration;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		// The factory always runs the active default through this filter; pass it through.
		Functions\when( 'apply_filters' )->alias( static fn( $hook, $value = null ) => $value );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * enabled() reflects the opt-in flag. Embeddings cost money, so a chat-only
	 * OpenAI key must not silently incur them — semantic search is off by default.
	 */
	public function test_enabled_reflects_option_flag(): void {
		Functions\when( 'get_option' )->alias(
			static fn( $k, $d = 0 ) => 'fahad_ai_embeddings_enabled' === $k ? 1 : $d
		);
		$this->assertTrue( Fahad_AI_Embeddings::enabled() );
	}

	public function test_enabled_defaults_off(): void {
		// Default (option absent) -> the provided default 0 is returned -> false.
		Functions\when( 'get_option' )->alias( static fn( $k, $d = 0 ) => $d );
		$this->assertFalse( Fahad_AI_Embeddings::enabled() );
	}

	/**
	 * Cohere selected but no Cohere key configured -> the `: null` fallback (line 32).
	 * Semantic search must stay off (keyword-only) rather than half-instantiate a
	 * keyless provider.
	 */
	public function test_factory_returns_null_when_cohere_selected_without_key(): void {
		Functions\when( 'get_option' )->alias(
			static fn( $k, $d = '' ) => [
				'fahad_ai_embedding_provider_type' => 'cohere',
				'fahad_ai_cohere_api_key'          => '', // empty -> null branch
			][ $k ] ?? $d
		);

		$this->assertNull( Fahad_AI_Embeddings::provider() );
	}

	/**
	 * Cohere selected WITH a key -> a configured Cohere provider, honouring the
	 * stored model. This covers the truthy side of the same ternary so both
	 * branches of the Cohere path are exercised.
	 */
	public function test_factory_returns_cohere_provider_when_keyed(): void {
		Functions\when( 'get_option' )->alias(
			static fn( $k, $d = '' ) => [
				'fahad_ai_embedding_provider_type' => 'cohere',
				'fahad_ai_cohere_api_key'          => 'co-live-key',
				'fahad_ai_embedding_model'         => 'embed-multilingual-v3.0',
			][ $k ] ?? $d
		);

		$p = Fahad_AI_Embeddings::provider();

		$this->assertInstanceOf( Fahad_AI_Cohere_Embedding_Provider::class, $p );
		$this->assertTrue( $p->is_available() );
		$this->assertSame( 'embed-multilingual-v3.0', $p->model() );
	}

	/**
	 * The Cohere model option is threaded through to the provider — a non-default
	 * model name proves the factory passes the stored value, not a constant.
	 */
	public function test_factory_passes_configured_cohere_model(): void {
		Functions\when( 'get_option' )->alias(
			static fn( $k, $d = '' ) => [
				'fahad_ai_embedding_provider_type' => 'cohere',
				'fahad_ai_cohere_api_key'          => 'co-live-key',
				'fahad_ai_embedding_model'         => 'embed-english-v3.0',
			][ $k ] ?? $d
		);

		$p = Fahad_AI_Embeddings::provider();

		$this->assertInstanceOf( Fahad_AI_Cohere_Embedding_Provider::class, $p );
		$this->assertSame( 'embed-english-v3.0', $p->model() );
	}
}
