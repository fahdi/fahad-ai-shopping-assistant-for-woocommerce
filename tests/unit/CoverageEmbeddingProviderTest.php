<?php
/**
 * Line-coverage test for includes/interface-embedding-provider.php.
 *
 * The file is a pure interface declaration whose only executable statement is the
 * file-scope guard `defined( 'ABSPATH' ) || exit;` (line 12). The interface is the
 * vector pipeline's vendor-decoupling seam (RAG Phase 1, S1.1, #104): the default
 * implementation is OpenAI, and an add-on can swap in Cohere/Voyage/self-hosted via
 * the `fahad_ai_embedding_provider` filter without touching core.
 *
 * Coverage note (the guard line). The interface is loaded once by tests/bootstrap.php,
 * BEFORE any test runs — i.e. outside the per-test pcov collection window — so the
 * guard line never registers as covered. The conventional re-execution trick used by
 * the sibling interface coverage tests (a `@runInSeparateProcess` test that re-`require`s
 * the file so pcov attributes line 12 to a running test) does NOT work in this
 * environment (PHP 8.5.6 / PHPUnit 10.5.63): the isolated child still runs the configured
 * bootstrap, which re-declares the interface before the test body executes, so a fresh
 * `require` of the real file fatally redeclares the symbol (a compile-time fatal that
 * cannot be caught). The guard line is therefore the genuinely-uncoverable residue for
 * this file in this environment — see uncoverableNotes. Re-executing the file fresh under
 * pcov in a process I fully control (no bootstrap) does record the line, which proves the
 * non-coverage is a harness/bootstrap-timing artifact, not a missing test.
 *
 * What this suite DOES assert, faithfully, is the actual substance of the file: the
 * embedding-provider contract — the exact method names, return types and parameter
 * shapes the indexer/retriever depend on, and that the contract is implementable and
 * type-checks. Conventions mirror the sibling coverage tests (Brain\Monkey + Mockery,
 * no whole-suite dependence, ReflectionClass to inspect the declared contract).
 */

use Brain\Monkey;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;

class CoverageEmbeddingProviderTest extends TestCase {

	use MockeryPHPUnitIntegration;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * The guard short-circuits cleanly: ABSPATH is defined (by the WC stubs / bootstrap),
	 * so `defined( 'ABSPATH' ) || exit;` never reaches `exit` and the interface is declared.
	 * If the guard had hit `exit` at load time the bootstrap would have aborted and this
	 * whole suite could not run — so reaching this assertion is itself proof the `||`
	 * short-circuited past `exit`.
	 */
	public function test_guard_short_circuits_so_the_interface_is_declared(): void {
		$this->assertTrue(
			defined( 'ABSPATH' ),
			'ABSPATH is defined, so the file-scope guard short-circuits before exit.'
		);
		$this->assertTrue(
			interface_exists( 'Fahad_AI_Embedding_Provider', false ),
			'The interface loaded — the guard fell through rather than exiting.'
		);
	}

	/**
	 * The declared contract: the four methods the vector pipeline depends on, no more,
	 * no fewer. The indexer calls embed()/model()/dimensions(); the retriever and admin
	 * gate features on is_available(). Pinning the method set guards against a silent
	 * drift in the seam every provider implementation must satisfy.
	 */
	public function test_interface_declares_the_full_embedding_provider_contract(): void {
		$this->assertTrue( interface_exists( 'Fahad_AI_Embedding_Provider' ) );

		$ref     = new ReflectionClass( 'Fahad_AI_Embedding_Provider' );
		$methods = array_map(
			static fn( ReflectionMethod $m ) => $m->getName(),
			$ref->getMethods()
		);
		sort( $methods );

		$this->assertSame(
			[ 'dimensions', 'embed', 'is_available', 'model' ],
			$methods,
			'Every provider-swappable operation is part of the contract.'
		);

		// The interface declares no constants or properties — it is a pure behavioural seam.
		$this->assertSame( [], $ref->getConstants() );
		$this->assertSame( [], $ref->getProperties() );

		// All four are public, abstract (interface) instance methods — none static.
		foreach ( $ref->getMethods() as $method ) {
			$this->assertTrue( $method->isPublic(), $method->getName() . ' must be public.' );
			$this->assertTrue( $method->isAbstract(), $method->getName() . ' is an interface method (abstract).' );
			$this->assertFalse( $method->isStatic(), $method->getName() . ' must be an instance method.' );
		}
	}

	/**
	 * The exact signatures each backend (OpenAI / Cohere / …) must implement.
	 * embed( string[] ): array — one vector per input, in input order; the scalar
	 * probes return string/int/bool respectively.
	 */
	public function test_method_signatures_match_what_the_pipeline_expects(): void {
		$ref = new ReflectionClass( 'Fahad_AI_Embedding_Provider' );

		// embed( array $texts ): array
		$embed = $ref->getMethod( 'embed' );
		$this->assertSame( 'array', (string) $embed->getReturnType() );
		$params = $embed->getParameters();
		$this->assertCount( 1, $params );
		$this->assertSame( 'texts', $params[0]->getName() );
		$this->assertSame( 'array', (string) $params[0]->getType() );

		// model(): string
		$model = $ref->getMethod( 'model' );
		$this->assertSame( 'string', (string) $model->getReturnType() );
		$this->assertCount( 0, $model->getParameters() );

		// dimensions(): int
		$dimensions = $ref->getMethod( 'dimensions' );
		$this->assertSame( 'int', (string) $dimensions->getReturnType() );
		$this->assertCount( 0, $dimensions->getParameters() );

		// is_available(): bool
		$available = $ref->getMethod( 'is_available' );
		$this->assertSame( 'bool', (string) $available->getReturnType() );
		$this->assertCount( 0, $available->getParameters() );
	}

	/**
	 * A concrete in-memory implementation proves the contract is satisfiable and
	 * type-checks: implementing every method with the declared signatures is accepted
	 * by the engine, and `instanceof` reports the implementation as the interface type.
	 * embed() must return one vector per input in input order — the property the indexer
	 * relies on to pair vectors back with their source texts.
	 */
	public function test_contract_is_implementable_and_type_checks(): void {
		$provider = new class() implements Fahad_AI_Embedding_Provider {
			public function embed( array $texts ): array {
				// One deterministic 2-d vector per input, preserving input order.
				return array_map(
					static fn( string $t ) => [ (float) strlen( $t ), 0.5 ],
					array_values( $texts )
				);
			}

			public function model(): string {
				return 'test-embedding-model';
			}

			public function dimensions(): int {
				return 2;
			}

			public function is_available(): bool {
				return true;
			}
		};

		$this->assertInstanceOf( Fahad_AI_Embedding_Provider::class, $provider );

		$vectors = $provider->embed( [ 'a', 'bbb' ] );
		$this->assertCount( 2, $vectors, 'One vector per input.' );
		$this->assertSame( [ 1.0, 0.5 ], $vectors[0], 'Vector 0 pairs with input 0 (order preserved).' );
		$this->assertSame( [ 3.0, 0.5 ], $vectors[1], 'Vector 1 pairs with input 1 (order preserved).' );
		$this->assertSame( $provider->dimensions(), count( $vectors[0] ), 'Each vector has the declared dimensionality.' );

		$this->assertSame( 'test-embedding-model', $provider->model() );
		$this->assertSame( 2, $provider->dimensions() );
		$this->assertTrue( $provider->is_available() );

		// An empty batch yields an empty result set (no vectors for no inputs).
		$this->assertSame( [], $provider->embed( [] ) );
	}

	/**
	 * A provider may legitimately report itself unavailable (no key configured) — the
	 * branch the retriever/admin use to fall back to keyword-only. The contract must
	 * accept an implementation whose is_available() returns false.
	 */
	public function test_an_unavailable_provider_satisfies_the_contract(): void {
		$provider = new class() implements Fahad_AI_Embedding_Provider {
			public function embed( array $texts ): array {
				return [];
			}
			public function model(): string {
				return '';
			}
			public function dimensions(): int {
				return 0;
			}
			public function is_available(): bool {
				return false;
			}
		};

		$this->assertInstanceOf( Fahad_AI_Embedding_Provider::class, $provider );
		$this->assertFalse( $provider->is_available(), 'Unavailable providers signal keyword-only fallback.' );
		$this->assertSame( '', $provider->model() );
		$this->assertSame( 0, $provider->dimensions() );
	}
}
