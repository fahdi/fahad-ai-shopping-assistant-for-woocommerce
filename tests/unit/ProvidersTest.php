<?php
/**
 * Unit tests for Fahad_AI_Providers — the multi-provider catalog.
 *
 * The catalog is the single source of truth for which AI services the assistant
 * can dispatch to. It is keyed by a stable provider id and, per provider, declares
 * a label, a transport `type` ('anthropic' native vs. 'openai'-compatible), an
 * OpenAI base URL, a default model + a model list, and the option NAMES that hold
 * the merchant's per-provider API key and chosen model. These tests pin:
 *
 *   - the built-in presets ship (anthropic + moonshot + the OpenAI-compatible set),
 *   - the catalog is filterable via `fahad_ai_providers` (the add-on seam),
 *   - resolve() reads the right key/model/base_url per provider (incl. moonshot's
 *     region base URL and custom's merchant-set base URL),
 *   - BACKWARD COMPAT: anthropic/moonshot keep their existing option names.
 *
 * Brain\Monkey + Mockery; get_option/apply_filters are stubbed additively. No
 * setAccessible (host runs PHP 8.5) — the catalog API is public/static.
 */

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;

class ProvidersTest extends TestCase {

	use MockeryPHPUnitIntegration;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		// Default: no merchant options set (every get_option returns its default),
		// and the providers filter is an identity pass-through. Individual tests
		// override get_option / apply_filters as needed (additive).
		Functions\when( 'get_option' )->alias( static fn( $key, $default = '' ) => $default );
		Functions\when( 'apply_filters' )->alias( static fn( $hook, $value = null ) => $value );
		// __() is a real stub from tests/stubs/wc-stubs.php (returns its text); it is
		// defined before Patchwork, so it must NOT be redefined via Brain\Monkey.
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/** Back get_option with an in-memory map (default for unset keys). */
	private function set_options( array $map ): void {
		Functions\when( 'get_option' )->alias(
			static fn( $key, $default = '' ) => $map[ $key ] ?? $default
		);
	}

	// ── catalog() ───────────────────────────────────────────────────────────────

	public function test_catalog_contains_the_built_in_presets(): void {
		$catalog = Fahad_AI_Providers::catalog();

		// The presets the assistant ships with. anthropic + moonshot are the existing
		// two; the rest are the OpenAI-compatible additions (issue: multi-provider).
		$expected = [
			'anthropic',
			'moonshot',
			'openai',
			'gemini',
			'groq',
			'mistral',
			'deepseek',
			'xai',
			'together',
			'openrouter',
			'perplexity',
			'ollama',
			'custom',
		];

		foreach ( $expected as $id ) {
			$this->assertArrayHasKey( $id, $catalog, "Catalog must contain the '{$id}' preset." );
		}
	}

	public function test_every_preset_declares_the_required_shape(): void {
		foreach ( Fahad_AI_Providers::catalog() as $id => $preset ) {
			$this->assertArrayHasKey( 'label', $preset, "{$id}: label" );
			$this->assertArrayHasKey( 'type', $preset, "{$id}: type" );
			$this->assertContains( $preset['type'], [ 'anthropic', 'openai' ], "{$id}: type is anthropic|openai" );
			$this->assertArrayHasKey( 'default_model', $preset, "{$id}: default_model" );
			$this->assertArrayHasKey( 'models', $preset, "{$id}: models" );
			$this->assertIsArray( $preset['models'], "{$id}: models is array" );
			$this->assertArrayHasKey( 'key_option', $preset, "{$id}: key_option" );
			$this->assertArrayHasKey( 'model_option', $preset, "{$id}: model_option" );
			$this->assertNotSame( '', (string) $preset['label'], "{$id}: non-empty label" );
		}
	}

	public function test_anthropic_is_the_only_native_provider(): void {
		$catalog = Fahad_AI_Providers::catalog();

		$this->assertSame( 'anthropic', $catalog['anthropic']['type'], 'anthropic uses the native path.' );

		// Every other built-in provider routes through the OpenAI-compatible path.
		foreach ( $catalog as $id => $preset ) {
			if ( 'anthropic' === $id ) {
				continue;
			}
			$this->assertSame( 'openai', $preset['type'], "{$id} must be an OpenAI-compatible provider." );
		}
	}

	public function test_openai_presets_declare_a_base_url(): void {
		foreach ( Fahad_AI_Providers::catalog() as $id => $preset ) {
			if ( 'openai' !== $preset['type'] ) {
				continue;
			}
			// custom's base URL is merchant-set (may be blank in the catalog default);
			// every other openai preset ships a concrete base URL.
			if ( 'custom' === $id ) {
				continue;
			}
			$this->assertArrayHasKey( 'base_url', $preset, "{$id}: base_url" );
			$this->assertStringStartsWith( 'http', (string) $preset['base_url'], "{$id}: base_url is a URL" );
		}
	}

	public function test_known_base_urls_match_the_provider_apis(): void {
		$catalog = Fahad_AI_Providers::catalog();

		// Each base URL is the FULL prefix up to (not including) /chat/completions —
		// i.e. it carries each provider's version segment. The OpenAI path appends only
		// '/chat/completions', so a single rule is correct for every provider.
		$this->assertSame( 'https://api.openai.com/v1', $catalog['openai']['base_url'] );
		$this->assertSame( 'https://generativelanguage.googleapis.com/v1beta/openai', $catalog['gemini']['base_url'] );
		$this->assertSame( 'https://api.groq.com/openai/v1', $catalog['groq']['base_url'] );
		$this->assertSame( 'https://api.mistral.ai/v1', $catalog['mistral']['base_url'] );
		$this->assertSame( 'https://api.deepseek.com/v1', $catalog['deepseek']['base_url'] );
		$this->assertSame( 'https://api.x.ai/v1', $catalog['xai']['base_url'] );
		$this->assertSame( 'https://api.together.xyz/v1', $catalog['together']['base_url'] );
		$this->assertSame( 'https://openrouter.ai/api/v1', $catalog['openrouter']['base_url'] );
		$this->assertSame( 'https://api.perplexity.ai', $catalog['perplexity']['base_url'] );
		$this->assertSame( 'http://localhost:11434/v1', $catalog['ollama']['base_url'] );
	}

	public function test_openai_default_model_is_gpt_4o_mini(): void {
		$this->assertSame( 'gpt-4o-mini', Fahad_AI_Providers::catalog()['openai']['default_model'] );
		$this->assertContains( 'gpt-4o-mini', Fahad_AI_Providers::catalog()['openai']['models'] );
	}

	// ── backward-compat option names (anthropic + moonshot) ──────────────────────

	public function test_anthropic_and_moonshot_keep_their_existing_option_names(): void {
		$catalog = Fahad_AI_Providers::catalog();

		// These option names predate the multi-provider work and MUST NOT change, or
		// every existing install loses its configured key/model on upgrade.
		$this->assertSame( 'fahad_ai_anthropic_api_key', $catalog['anthropic']['key_option'] );
		$this->assertSame( 'fahad_ai_anthropic_model', $catalog['anthropic']['model_option'] );
		$this->assertSame( 'fahad_ai_moonshot_api_key', $catalog['moonshot']['key_option'] );
		$this->assertSame( 'fahad_ai_moonshot_model', $catalog['moonshot']['model_option'] );
	}

	public function test_anthropic_and_moonshot_keep_their_existing_default_models(): void {
		$catalog = Fahad_AI_Providers::catalog();

		$this->assertSame( 'claude-haiku-4-5-20251001', $catalog['anthropic']['default_model'] );
		$this->assertSame( 'kimi-k2.6', $catalog['moonshot']['default_model'] );
	}

	public function test_new_provider_option_names_follow_the_id_convention(): void {
		// Each NEW preset reads fahad_ai_{id}_api_key / fahad_ai_{id}_model.
		foreach ( [ 'openai', 'gemini', 'groq', 'deepseek', 'xai', 'custom' ] as $id ) {
			$preset = Fahad_AI_Providers::catalog()[ $id ];
			$this->assertSame( "fahad_ai_{$id}_api_key", $preset['key_option'], "{$id}: key option" );
			$this->assertSame( "fahad_ai_{$id}_model", $preset['model_option'], "{$id}: model option" );
		}
	}

	// ── fahad_ai_providers filter (add-on extensibility seam) ────────────────────

	public function test_catalog_is_filterable_to_register_a_new_provider(): void {
		// An add-on can register an entirely new provider purely at the DATA level
		// (no provider-class plumbing) by appending to the catalog via the filter.
		Functions\when( 'apply_filters' )->alias(
			static function ( $hook, $value = null ) {
				if ( 'fahad_ai_providers' === $hook && is_array( $value ) ) {
					$value['acme'] = [
						'label'         => 'Acme LLM',
						'type'          => 'openai',
						'base_url'      => 'https://api.acme.example/v1',
						'default_model' => 'acme-1',
						'models'        => [ 'acme-1' ],
						'key_option'    => 'fahad_ai_acme_api_key',
						'model_option'  => 'fahad_ai_acme_model',
					];
				}
				return $value;
			}
		);

		$catalog = Fahad_AI_Providers::catalog();

		$this->assertArrayHasKey( 'acme', $catalog, 'A filter-registered provider must appear in the catalog.' );
		$this->assertSame( 'https://api.acme.example/v1', $catalog['acme']['base_url'] );
		// The built-ins are still present alongside the add-on entry.
		$this->assertArrayHasKey( 'anthropic', $catalog );
		$this->assertArrayHasKey( 'openai', $catalog );
	}

	public function test_filter_is_applied_with_the_documented_hook_name(): void {
		$seen = null;
		Functions\when( 'apply_filters' )->alias(
			static function ( $hook, $value = null ) use ( &$seen ) {
				if ( 'fahad_ai_providers' === $hook ) {
					$seen = $hook;
				}
				return $value;
			}
		);

		Fahad_AI_Providers::catalog();

		$this->assertSame( 'fahad_ai_providers', $seen, 'The catalog must run the fahad_ai_providers filter.' );
	}

	public function test_a_malformed_filter_entry_is_dropped(): void {
		// Defence in depth: a junk entry from a broken add-on must not poison the
		// catalog. A non-array value (or one missing the required keys) is discarded.
		Functions\when( 'apply_filters' )->alias(
			static function ( $hook, $value = null ) {
				if ( 'fahad_ai_providers' === $hook && is_array( $value ) ) {
					$value['broken']    = 'not-an-array';
					$value['half_done'] = [ 'label' => 'Half' ]; // missing type/options
				}
				return $value;
			}
		);

		$catalog = Fahad_AI_Providers::catalog();

		$this->assertArrayNotHasKey( 'broken', $catalog, 'A non-array entry must be dropped.' );
		$this->assertArrayNotHasKey( 'half_done', $catalog, 'An entry missing required keys must be dropped.' );
		$this->assertArrayHasKey( 'anthropic', $catalog, 'Built-ins survive a malformed filter entry.' );
	}

	// ── get() / ids() ─────────────────────────────────────────────────────────────

	public function test_get_returns_a_known_preset(): void {
		$preset = Fahad_AI_Providers::get( 'openai' );

		$this->assertIsArray( $preset );
		$this->assertSame( 'openai', $preset['type'] );
		$this->assertSame( 'https://api.openai.com/v1', $preset['base_url'] );
	}

	public function test_get_returns_null_for_an_unknown_provider(): void {
		$this->assertNull( Fahad_AI_Providers::get( 'does-not-exist' ) );
	}

	public function test_ids_lists_every_catalog_provider(): void {
		$ids = Fahad_AI_Providers::ids();

		$this->assertContains( 'anthropic', $ids );
		$this->assertContains( 'moonshot', $ids );
		$this->assertContains( 'openai', $ids );
		$this->assertContains( 'custom', $ids );
		$this->assertSame( array_keys( Fahad_AI_Providers::catalog() ), $ids );
	}

	// ── resolve() — per-provider key/model/base_url from options ─────────────────

	public function test_resolve_reads_key_and_model_for_an_openai_provider(): void {
		$this->set_options( [
			'fahad_ai_openai_api_key' => 'sk-openai-123',
			'fahad_ai_openai_model'   => 'gpt-4o',
		] );

		$resolved = Fahad_AI_Providers::resolve( 'openai' );

		$this->assertSame( 'openai', $resolved['type'] );
		$this->assertSame( 'https://api.openai.com/v1', $resolved['base_url'] );
		$this->assertSame( 'sk-openai-123', $resolved['api_key'] );
		$this->assertSame( 'gpt-4o', $resolved['model'] );
	}

	public function test_resolve_defaults_model_to_the_preset_default(): void {
		// Key set but no model chosen → the preset's default_model is used.
		$this->set_options( [ 'fahad_ai_openai_api_key' => 'sk-openai-123' ] );

		$resolved = Fahad_AI_Providers::resolve( 'openai' );

		$this->assertSame( 'gpt-4o-mini', $resolved['model'] );
	}

	public function test_resolve_anthropic_uses_existing_option_names(): void {
		// BACKWARD COMPAT: an install configured before multi-provider stored its key
		// under fahad_ai_anthropic_api_key / model under fahad_ai_anthropic_model.
		$this->set_options( [
			'fahad_ai_anthropic_api_key' => 'sk-ant-legacy',
			'fahad_ai_anthropic_model'   => 'claude-sonnet-4-6',
		] );

		$resolved = Fahad_AI_Providers::resolve( 'anthropic' );

		$this->assertSame( 'anthropic', $resolved['type'] );
		$this->assertSame( 'sk-ant-legacy', $resolved['api_key'] );
		$this->assertSame( 'claude-sonnet-4-6', $resolved['model'] );
	}

	public function test_resolve_moonshot_uses_existing_options_and_region_base_url(): void {
		// BACKWARD COMPAT: moonshot keeps fahad_ai_moonshot_api_key / _model AND its
		// region-selected base URL (fahad_ai_moonshot_region), unchanged.
		$this->set_options( [
			'fahad_ai_moonshot_api_key' => 'sk-moon-legacy',
			'fahad_ai_moonshot_model'   => 'kimi-k2.5',
			'fahad_ai_moonshot_region'  => 'china',
		] );

		$resolved = Fahad_AI_Providers::resolve( 'moonshot' );

		$this->assertSame( 'openai', $resolved['type'] );
		$this->assertSame( 'sk-moon-legacy', $resolved['api_key'] );
		$this->assertSame( 'kimi-k2.5', $resolved['model'] );
		// Region host + the /v1 segment the OpenAI path expects.
		$this->assertSame( 'https://api.moonshot.cn/v1', $resolved['base_url'], 'China region base URL.' );
	}

	public function test_resolve_moonshot_defaults_to_global_base_url(): void {
		$this->set_options( [ 'fahad_ai_moonshot_api_key' => 'sk-moon' ] );

		$resolved = Fahad_AI_Providers::resolve( 'moonshot' );

		$this->assertSame( 'https://api.moonshot.ai/v1', $resolved['base_url'] );
	}

	public function test_resolve_custom_reads_merchant_base_url(): void {
		// The `custom` provider's base URL is the merchant-set option, letting them
		// point the assistant at any OpenAI-compatible endpoint.
		$this->set_options( [
			'fahad_ai_custom_api_key'  => 'sk-custom',
			'fahad_ai_custom_model'    => 'my-model',
			'fahad_ai_custom_base_url' => 'https://llm.mystore.example/v1',
		] );

		$resolved = Fahad_AI_Providers::resolve( 'custom' );

		$this->assertSame( 'openai', $resolved['type'] );
		$this->assertSame( 'https://llm.mystore.example/v1', $resolved['base_url'] );
		$this->assertSame( 'sk-custom', $resolved['api_key'] );
		$this->assertSame( 'my-model', $resolved['model'] );
	}

	public function test_resolve_returns_null_for_an_unknown_provider(): void {
		$this->assertNull( Fahad_AI_Providers::resolve( 'nope' ) );
	}

	public function test_resolve_a_filter_registered_provider(): void {
		// resolve() works for an add-on provider too — it reads that provider's
		// declared key/model option names.
		Functions\when( 'apply_filters' )->alias(
			static function ( $hook, $value = null ) {
				if ( 'fahad_ai_providers' === $hook && is_array( $value ) ) {
					$value['acme'] = [
						'label'         => 'Acme LLM',
						'type'          => 'openai',
						'base_url'      => 'https://api.acme.example/v1',
						'default_model' => 'acme-1',
						'models'        => [ 'acme-1' ],
						'key_option'    => 'fahad_ai_acme_api_key',
						'model_option'  => 'fahad_ai_acme_model',
					];
				}
				return $value;
			}
		);
		// get_option must see the add-on's key option.
		Functions\when( 'get_option' )->alias(
			static fn( $key, $default = '' ) => 'fahad_ai_acme_api_key' === $key ? 'sk-acme' : $default
		);

		$resolved = Fahad_AI_Providers::resolve( 'acme' );

		$this->assertSame( 'sk-acme', $resolved['api_key'] );
		$this->assertSame( 'acme-1', $resolved['model'], 'Falls back to the add-on default model.' );
		$this->assertSame( 'https://api.acme.example/v1', $resolved['base_url'] );
	}

	// ── sanitize_base_url() — custom endpoint validation (shared with admin) ─────

	public function test_sanitize_base_url_accepts_https(): void {
		Functions\when( 'wp_parse_url' )->alias( static fn( $u, $c = -1 ) => parse_url( $u ) );
		Functions\when( 'esc_url_raw' )->returnArg();

		$this->assertSame( 'https://api.example.com/v1', Fahad_AI_Providers::sanitize_base_url( 'https://api.example.com/v1' ) );
	}

	public function test_sanitize_base_url_rejects_plain_http_remote_and_junk(): void {
		Functions\when( 'wp_parse_url' )->alias( static fn( $u, $c = -1 ) => parse_url( $u ) );
		Functions\when( 'esc_url_raw' )->returnArg();

		$this->assertSame( '', Fahad_AI_Providers::sanitize_base_url( 'http://api.example.com/v1' ), 'remote http rejected' );
		$this->assertSame( '', Fahad_AI_Providers::sanitize_base_url( 'ftp://api.example.com' ), 'ftp rejected' );
		$this->assertSame( '', Fahad_AI_Providers::sanitize_base_url( 'javascript:alert(1)' ), 'dangerous scheme rejected' );
		$this->assertSame( '', Fahad_AI_Providers::sanitize_base_url( 'garbage' ), 'junk rejected' );
		$this->assertSame( '', Fahad_AI_Providers::sanitize_base_url( '' ), 'empty rejected' );
	}

	public function test_sanitize_base_url_allows_localhost_http(): void {
		Functions\when( 'wp_parse_url' )->alias( static fn( $u, $c = -1 ) => parse_url( $u ) );
		Functions\when( 'esc_url_raw' )->returnArg();

		$this->assertSame( 'http://localhost:11434/v1', Fahad_AI_Providers::sanitize_base_url( 'http://localhost:11434/v1' ) );
		$this->assertSame( 'http://127.0.0.1:8080/v1', Fahad_AI_Providers::sanitize_base_url( 'http://127.0.0.1:8080/v1' ) );
	}

	// ── type()/is_openai() ────────────────────────────────────────────────────────

	public function test_type_and_is_openai(): void {
		$this->assertSame( 'anthropic', Fahad_AI_Providers::type( 'anthropic' ) );
		$this->assertSame( 'openai', Fahad_AI_Providers::type( 'openai' ) );
		$this->assertSame( '', Fahad_AI_Providers::type( 'nope' ), 'Unknown id has no type.' );

		$this->assertFalse( Fahad_AI_Providers::is_openai( 'anthropic' ) );
		$this->assertTrue( Fahad_AI_Providers::is_openai( 'openai' ) );
		$this->assertTrue( Fahad_AI_Providers::is_openai( 'moonshot' ) );
		$this->assertFalse( Fahad_AI_Providers::is_openai( 'nope' ) );
	}
}
