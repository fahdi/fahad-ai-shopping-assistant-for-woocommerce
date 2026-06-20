<?php
/**
 * Supplemental coverage tests for Fahad_AI_Providers::catalog() — the two defensive
 * fallbacks that the primary ProvidersTest does not exercise:
 *
 *   - line 238: the filter returns a NON-array → catalog() ignores it and rebuilds
 *     from the built-in presets.
 *   - line 256: the filter returns an array whose every entry is junk (so $clean ends
 *     up empty) → catalog() refuses to ship an empty catalog and falls back to the
 *     built-in presets ("the built-in floor").
 *
 * Same conventions as the sibling ProvidersTest: Brain\Monkey + Mockery, additive
 * function stubs, public/static catalog API (no setAccessible). __() is the real stub
 * from tests/stubs/wc-stubs.php (do not redefine via Brain\Monkey).
 */

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;

class CoverageProvidersTest extends TestCase {

	use MockeryPHPUnitIntegration;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		// No merchant options; identity filter by default (individual tests override
		// apply_filters to drive the defensive branches).
		Functions\when( 'get_option' )->alias( static fn( $key, $default = '' ) => $default );
		Functions\when( 'apply_filters' )->alias( static fn( $hook, $value = null ) => $value );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/** The id set every built-in catalog must contain (used to assert "rebuilt from presets"). */
	private const BUILT_IN_IDS = [
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

	// ── line 238: filter returns a non-array → rebuild from presets ──────────────

	public function test_catalog_falls_back_to_presets_when_filter_returns_a_non_array(): void {
		// A broken/hostile add-on replaces the whole catalog with a scalar. catalog()
		// must NOT trust it — it returns the built-in presets unchanged.
		Functions\when( 'apply_filters' )->alias(
			static function ( $hook, $value = null ) {
				if ( 'fahad_ai_providers' === $hook ) {
					return 'totally-not-an-array';
				}
				return $value;
			}
		);

		$catalog = Fahad_AI_Providers::catalog();

		$this->assertIsArray( $catalog, 'A non-array filter result is replaced by the preset array.' );
		foreach ( self::BUILT_IN_IDS as $id ) {
			$this->assertArrayHasKey( $id, $catalog, "Built-in '{$id}' present after non-array filter." );
		}
		// It is the genuine preset catalog (same id ordering as presets()), not just
		// "happens to contain anthropic".
		$this->assertSame( self::BUILT_IN_IDS, array_keys( $catalog ) );
		$this->assertSame( 'anthropic', $catalog['anthropic']['type'] );
	}

	public function test_catalog_falls_back_to_presets_when_filter_returns_null(): void {
		// null is also a non-array; same defensive path (line 238).
		Functions\when( 'apply_filters' )->alias(
			static function ( $hook, $value = null ) {
				if ( 'fahad_ai_providers' === $hook ) {
					return null;
				}
				return $value;
			}
		);

		$catalog = Fahad_AI_Providers::catalog();

		$this->assertIsArray( $catalog );
		$this->assertArrayHasKey( 'anthropic', $catalog );
		$this->assertSame( self::BUILT_IN_IDS, array_keys( $catalog ) );
	}

	// ── line 256: filter empties the catalog → rebuild from presets ──────────────

	public function test_catalog_falls_back_to_presets_when_filter_returns_an_empty_array(): void {
		// A hostile/broken add-on returns []. $clean is empty after the sanitising loop,
		// so catalog() restores the built-in floor rather than shipping no providers.
		Functions\when( 'apply_filters' )->alias(
			static function ( $hook, $value = null ) {
				if ( 'fahad_ai_providers' === $hook ) {
					return [];
				}
				return $value;
			}
		);

		$catalog = Fahad_AI_Providers::catalog();

		$this->assertNotEmpty( $catalog, 'An empty filter result must not yield an empty catalog.' );
		$this->assertArrayHasKey( 'anthropic', $catalog, 'The built-in floor survives an emptying filter.' );
		$this->assertSame( self::BUILT_IN_IDS, array_keys( $catalog ) );
	}

	public function test_catalog_falls_back_to_presets_when_every_entry_is_junk(): void {
		// The filter returns a non-empty array, but EVERY entry is dropped by the
		// sanitising loop (bad id, non-array preset, missing required keys). $clean is
		// therefore empty → line 256 restores the presets.
		Functions\when( 'apply_filters' )->alias(
			static function ( $hook, $value = null ) {
				if ( 'fahad_ai_providers' === $hook ) {
					return [
						''            => [ 'label' => 'empty id' ],   // empty id → dropped
						'scalar'      => 'not-an-array',             // non-array preset → dropped
						'missingkeys' => [ 'label' => 'Half' ],      // missing required keys → dropped
						'badtype'     => [                            // present keys, invalid type → dropped
							'label'         => 'Bad',
							'type'          => 'sql',
							'default_model' => 'm',
							'models'        => [ 'm' ],
							'key_option'    => 'k',
							'model_option'  => 'mo',
						],
					];
				}
				return $value;
			}
		);

		$catalog = Fahad_AI_Providers::catalog();

		// None of the junk ids leaked through...
		$this->assertArrayNotHasKey( '', $catalog );
		$this->assertArrayNotHasKey( 'scalar', $catalog );
		$this->assertArrayNotHasKey( 'missingkeys', $catalog );
		$this->assertArrayNotHasKey( 'badtype', $catalog );
		// ...and because $clean was empty, the built-in presets were restored wholesale.
		$this->assertSame( self::BUILT_IN_IDS, array_keys( $catalog ) );
		$this->assertArrayHasKey( 'anthropic', $catalog );
	}

	public function test_a_single_valid_filter_entry_prevents_the_empty_fallback(): void {
		// Contrast case: if even ONE entry survives sanitising, $clean is non-empty, so
		// line 256 is NOT taken — only the surviving entry is returned (the filter is
		// authoritative once it yields a usable catalog). This pins that the fallback is
		// strictly the empty-catalog floor, not "always merge in the built-ins".
		Functions\when( 'apply_filters' )->alias(
			static function ( $hook, $value = null ) {
				if ( 'fahad_ai_providers' === $hook ) {
					return [
						'junk' => 'nope',
						'acme' => [
							'label'         => 'Acme LLM',
							'type'          => 'openai',
							'base_url'      => 'https://api.acme.example/v1',
							'default_model' => 'acme-1',
							'models'        => [ 'acme-1' ],
							'key_option'    => 'fahad_ai_acme_api_key',
							'model_option'  => 'fahad_ai_acme_model',
						],
					];
				}
				return $value;
			}
		);

		$catalog = Fahad_AI_Providers::catalog();

		$this->assertSame( [ 'acme' ], array_keys( $catalog ), 'A surviving entry is authoritative; no preset fallback.' );
		$this->assertArrayNotHasKey( 'anthropic', $catalog );
		$this->assertArrayNotHasKey( 'junk', $catalog );
	}
}
