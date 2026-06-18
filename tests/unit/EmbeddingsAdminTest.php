<?php
/**
 * Unit tests for the embeddings admin (RAG Phase 1, S1.5, #108).
 *
 * Settings save/sanitize, the "build index" action (enqueues a backfill +
 * records the index model), index status, and the central enabled() gate — a
 * chat-only OpenAI key must NOT silently incur embedding costs; semantic search
 * is opt-in.
 */

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;

class EmbeddingsAdminTest extends TestCase {

	use MockeryPHPUnitIntegration;

	/** @var array<string,mixed> */
	private array $options = [];

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		$this->options = [];
		Functions\when( 'update_option' )->alias(
			function ( $k, $v ) { $this->options[ $k ] = $v; return true; }
		);
		Functions\when( 'get_option' )->alias(
			fn( $k, $d = '' ) => $this->options[ $k ] ?? $d
		);
		Functions\when( 'delete_option' )->alias( function ( $k ) { unset( $this->options[ $k ] ); return true; } );
		Functions\when( 'apply_filters' )->alias( static fn( $hook, $value = null ) => $value );
		Functions\when( 'sanitize_text_field' )->alias( static fn( $s ) => is_string( $s ) ? trim( $s ) : '' );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_save_persists_sanitized_settings(): void {
		Fahad_AI_Embeddings_Admin::save(
			[
				'embeddings_enabled' => '1',
				'embedding_model'    => '  text-embedding-3-small  ',
				'embedding_dims'     => '512',
				'embed_daily_cap'    => '1000',
			]
		);
		$this->assertSame( 1, $this->options[ Fahad_AI_Embeddings_Admin::OPT_ENABLED ] );
		$this->assertSame( 'text-embedding-3-small', $this->options[ Fahad_AI_Embeddings_Admin::OPT_MODEL ] );
		$this->assertSame( 512, $this->options[ Fahad_AI_Embeddings_Admin::OPT_DIMS ] );
		$this->assertSame( 1000, $this->options[ Fahad_AI_Embeddings_Admin::OPT_CAP ] );
	}

	public function test_save_persists_provider_type_endpoint_and_keys(): void {
		Fahad_AI_Embeddings_Admin::save(
			[
				'embedding_provider_type' => 'cohere',
				'embedding_base_url'      => 'https://api.moonshot.ai/v1',
				'embedding_api_key'       => 'sk-moon',
				'cohere_api_key'          => 'co-key',
			]
		);
		$this->assertSame( 'cohere', $this->options[ Fahad_AI_Embeddings_Admin::OPT_PROVIDER_TYPE ] );
		$this->assertSame( 'https://api.moonshot.ai/v1', $this->options[ Fahad_AI_Embeddings_Admin::OPT_BASE_URL ] );
		$this->assertSame( 'sk-moon', $this->options[ Fahad_AI_Embeddings_Admin::OPT_API_KEY ] );
		$this->assertSame( 'co-key', $this->options[ Fahad_AI_Embeddings_Admin::OPT_COHERE_KEY ] );
	}

	public function test_save_rejects_unknown_provider_type_and_defaults_base_url(): void {
		Fahad_AI_Embeddings_Admin::save( [ 'embedding_provider_type' => 'bogus' ] );
		$this->assertSame( 'openai', $this->options[ Fahad_AI_Embeddings_Admin::OPT_PROVIDER_TYPE ] );
		$this->assertSame( 'https://api.openai.com/v1', $this->options[ Fahad_AI_Embeddings_Admin::OPT_BASE_URL ] );
	}

	public function test_save_clamps_and_defaults(): void {
		Fahad_AI_Embeddings_Admin::save( [ 'embedding_dims' => '0', 'embed_daily_cap' => '-5', 'embedding_model' => '' ] );
		$this->assertSame( 0, $this->options[ Fahad_AI_Embeddings_Admin::OPT_ENABLED ], 'unchecked box -> disabled' );
		$this->assertGreaterThanOrEqual( 1, $this->options[ Fahad_AI_Embeddings_Admin::OPT_DIMS ] );
		$this->assertSame( 0, $this->options[ Fahad_AI_Embeddings_Admin::OPT_CAP ], 'negative cap floored to 0' );
		$this->assertSame( 'text-embedding-3-small', $this->options[ Fahad_AI_Embeddings_Admin::OPT_MODEL ], 'blank model -> default' );
	}

	public function test_run_build_enqueues_backfill_and_records_index_model(): void {
		$this->options['fahad_ai_embeddings_enabled'] = 1;
		$this->options['fahad_ai_openai_api_key']     = 'sk-live';
		$enqueued                                     = 0;
		Functions\when( 'as_enqueue_async_action' )->alias( static function () use ( &$enqueued ) { ++$enqueued; return 1; } );
		Functions\when( 'wc_get_products' )->justReturn( [ 10, 11, 12 ] );

		$count = Fahad_AI_Embeddings_Admin::run_build();

		$this->assertSame( 3, $count );
		$this->assertSame( 3, $enqueued );
		$this->assertSame( 'text-embedding-3-small', $this->options[ Fahad_AI_Postmeta_Vector_Store::OPTION_INDEX_MODEL ] );
		$this->assertGreaterThan( 0, $this->options[ Fahad_AI_Embeddings_Admin::OPT_LAST_BUILD ] );
	}

	public function test_run_build_is_a_noop_without_a_provider(): void {
		// enabled but no key -> no provider -> nothing enqueued.
		$this->options['fahad_ai_embeddings_enabled'] = 1;
		$enqueued                                     = 0;
		Functions\when( 'as_enqueue_async_action' )->alias( static function () use ( &$enqueued ) { ++$enqueued; return 1; } );

		$this->assertSame( 0, Fahad_AI_Embeddings_Admin::run_build() );
		$this->assertSame( 0, $enqueued );
	}

	public function test_index_status_reports_stale_when_models_differ(): void {
		$this->options['fahad_ai_embeddings_enabled']        = 1;
		$this->options['fahad_ai_openai_api_key']            = 'sk-live';
		$this->options['fahad_ai_index_model']               = 'old-model';
		Functions\when( 'wc_get_products' )->justReturn( [ 10, 11 ] );

		$status = Fahad_AI_Embeddings_Admin::index_status();
		$this->assertTrue( $status['enabled'] );
		$this->assertTrue( $status['available'] );
		$this->assertSame( 'text-embedding-3-small', $status['active_model'] );
		$this->assertTrue( $status['stale'], 'index built under a different model -> stale' );
		$this->assertSame( 2, $status['count'] );
	}

	public function test_enabled_gate_blocks_retrieval_even_with_a_key(): void {
		// Key present, but semantic search NOT enabled -> seam passes through to keyword.
		$this->options['fahad_ai_openai_api_key'] = 'sk-live';
		$this->options['fahad_ai_embeddings_enabled'] = 0;
		$this->assertFalse( Fahad_AI_Embeddings::enabled() );
		$this->assertNull( Fahad_AI_Retriever::resolve_seam( null, 'warm', [] ), 'disabled -> keyword fallback' );
	}
}
