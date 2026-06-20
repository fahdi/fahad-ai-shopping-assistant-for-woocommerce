<?php
/**
 * Coverage tests for Fahad_AI_Semantic_Search — drives the seam's defensive
 * branches the sibling SemanticSearchTest does not reach: the per-query limit
 * break, the callable (Shape 2) retriever path (including a throwing provider),
 * a callable that resolves to a non-array, and non-numeric candidate skipping.
 *
 * Unlike the sibling (which exercises the seam end-to-end through
 * Fahad_AI_Tools::execute), these tests call the public static
 * Fahad_AI_Semantic_Search::retrieve() directly so each branch in retrieve()
 * and its private ranked_ids() is executed in isolation. Conventions mirror
 * SemanticSearchTest / ApiHandlerTest: Brain\Monkey for WP/WC functions,
 * Mockery for WC_Product objects, the Tools singleton reset via reflection.
 */

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;

class CoverageSemanticSearchTest extends TestCase {

	use MockeryPHPUnitIntegration;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		// The tool-layer formatter (format_product_summary) runs against the
		// products the retriever's ids resolve to, so the same WP/WC stubs the
		// sibling uses are needed for a card to format cleanly.
		Functions\stubs( [
			'absint'                      => fn( $n ) => abs( (int) $n ),
			'sanitize_text_field'         => fn( $s ) => is_string( $s ) ? trim( $s ) : $s,
			'get_option'                  => fn( $key, $default = '' ) => $default,
			'wp_json_encode'              => fn( $d ) => json_encode( $d ),
			'wc_price'                    => fn( $p ) => '$' . $p,
			'wp_strip_all_tags'           => fn( $s ) => strip_tags( (string) $s ),
			'wp_get_attachment_image_url' => fn() => '',
			'wc_placeholder_img_src'      => fn() => 'http://example.com/placeholder.png',
			'get_permalink'               => fn( $id ) => 'http://example.com/?p=' . $id,
			'wp_list_pluck'               => fn( $list, $field ) => array_column( (array) $list, $field ),
			'get_the_terms'               => fn() => [],
		] );

		// Reset the Tools singleton so retrieve()'s Fahad_AI_Tools::instance()
		// call cannot leak state between tests.
		( new ReflectionProperty( Fahad_AI_Tools::class, 'instance' ) )->setValue( null, null );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * Happy-path product mock (mirrors SemanticSearchTest::mockProduct). The
	 * card formatter reads every getter below, so all are stubbed.
	 */
	private function mockProduct( int $id, string $name, string $price, bool $inStock = true ): WC_Product {
		$p = Mockery::mock( WC_Product::class );
		$p->shouldReceive( 'get_id' )->andReturn( $id );
		$p->shouldReceive( 'get_name' )->andReturn( $name );
		$p->shouldReceive( 'get_price' )->andReturn( $price );
		$p->shouldReceive( 'get_regular_price' )->andReturn( $price );
		$p->shouldReceive( 'get_sale_price' )->andReturn( '' );
		$p->shouldReceive( 'is_on_sale' )->andReturn( false );
		$p->shouldReceive( 'is_visible' )->andReturn( true )->byDefault();
		$p->shouldReceive( 'is_in_stock' )->andReturn( $inStock )->byDefault();
		$p->shouldReceive( 'get_short_description' )->andReturn( '' );
		$p->shouldReceive( 'get_image_id' )->andReturn( 0 );
		$p->shouldReceive( 'get_average_rating' )->andReturn( '4.5' )->byDefault();
		$p->shouldReceive( 'get_review_count' )->andReturn( 8 )->byDefault();
		return $p;
	}

	/**
	 * Register a stub retriever on the seam filter. $retriever receives
	 * ( $passthrough, $query, $filters ) and returns whatever the test needs the
	 * provider to return (ids, a callable, a scalar, …).
	 */
	private function registerRetriever( callable $retriever ): void {
		Monkey\Filters\expectApplied( 'fahad_ai_semantic_retriever' )
			->andReturnUsing( $retriever );
	}

	// ── retrieve(): the per-query limit break (line 99) ─────────────────────────

	public function test_limit_caps_the_returned_summaries_and_breaks_early(): void {
		// Retriever ranks three resolvable, visible products but filters['limit']
		// is 2. retrieve() must stop after the second card (the break), returning
		// the top-2 in rank order and never formatting the third.
		$byId = [
			31 => $this->mockProduct( 31, 'Arch Support Trainer', '89.00' ),
			12 => $this->mockProduct( 12, 'Orthopedic Walking Shoe', '120.00' ),
			77 => $this->mockProduct( 77, 'Should Not Appear', '10.00' ),
		];
		Functions\when( 'wc_get_product' )->alias( fn( $id ) => $byId[ (int) $id ] ?? false );

		$this->registerRetriever( fn( $value, $query, $filters ) => [ 31, 12, 77 ] );

		$result = Fahad_AI_Semantic_Search::retrieve( 'shoes for flat feet', [ 'limit' => 2 ] );

		$this->assertCount( 2, $result );
		$this->assertSame( [ 31, 12 ], array_column( $result, 'id' ) );
	}

	public function test_limit_floor_of_one_caps_a_single_card(): void {
		// limit is coerced through max( 1, … ), so a zero/negative limit still
		// yields at least one — the floor protects the break from a 0/negative cap.
		$byId = [
			5 => $this->mockProduct( 5, 'First', '10.00' ),
			6 => $this->mockProduct( 6, 'Second', '20.00' ),
		];
		Functions\when( 'wc_get_product' )->alias( fn( $id ) => $byId[ (int) $id ] ?? false );

		$this->registerRetriever( fn( $value, $query, $filters ) => [ 5, 6 ] );

		// limit 0 is set, so max(1,0)=1 → exactly one card, then break.
		$result = Fahad_AI_Semantic_Search::retrieve( 'x', [ 'limit' => 0 ] );

		$this->assertCount( 1, $result );
		$this->assertSame( [ 5 ], array_column( $result, 'id' ) );
	}

	public function test_no_limit_key_returns_all_resolved_cards(): void {
		// When filters carries no limit, $limit is 0 (the ?:0 branch) and the break
		// is skipped — every resolvable, visible card is returned.
		$byId = [
			5 => $this->mockProduct( 5, 'First', '10.00' ),
			6 => $this->mockProduct( 6, 'Second', '20.00' ),
		];
		Functions\when( 'wc_get_product' )->alias( fn( $id ) => $byId[ (int) $id ] ?? false );

		$this->registerRetriever( fn( $value, $query, $filters ) => [ 5, 6 ] );

		$result = Fahad_AI_Semantic_Search::retrieve( 'x', [] );

		$this->assertCount( 2, $result );
		$this->assertSame( [ 5, 6 ], array_column( $result, 'id' ) );
	}

	// ── ranked_ids(): Shape 2 callable retriever (lines 142-144) ────────────────

	public function test_callable_retriever_shape_two_is_invoked_with_query_and_filters(): void {
		// A provider may register a callable fn( $query, $filters ): int[] rather
		// than ids directly. retrieve() must call it with the query+filters and use
		// its return — proving the is_callable branch and the invocation (line 144).
		$seen = [];
		$byId = [ 7 => $this->mockProduct( 7, 'Rain Jacket', '60.00' ) ];
		Functions\when( 'wc_get_product' )->alias( fn( $id ) => $byId[ (int) $id ] ?? false );

		$callable = function ( $query, $filters ) use ( &$seen ) {
			$seen = [ 'query' => $query, 'filters' => $filters ];
			return [ 7 ];
		};
		$this->registerRetriever( fn( $value, $query, $filters ) => $callable );

		$filters = [ 'category' => 'jackets', 'min_price' => 20.0, 'max_price' => 100.0 ];
		$result  = Fahad_AI_Semantic_Search::retrieve( 'rainy hike', $filters );

		// The callable received the SAME query + filters retrieve() was handed.
		$this->assertSame( 'rainy hike', $seen['query'] );
		$this->assertSame( $filters, $seen['filters'] );
		// And its ids drove the result.
		$this->assertSame( [ 7 ], array_column( $result, 'id' ) );
		$this->assertSame( 'Rain Jacket', $result[0]['name'] );
	}

	public function test_callable_retriever_that_throws_degrades_to_empty(): void {
		// A throwing callable provider must be isolated: ranked_ids catches the
		// Throwable and returns [] (lines 145-146), so retrieve() yields [] and the
		// caller falls back to keyword search — the shopper never sees the error.
		Functions\expect( 'wc_get_product' )->never(); // no ids ⇒ no resolution

		$boom = function ( $query, $filters ) {
			throw new \RuntimeException( 'embeddings backend down' );
		};
		$this->registerRetriever( fn( $value, $query, $filters ) => $boom );

		$result = Fahad_AI_Semantic_Search::retrieve( 'anything', [] );

		$this->assertSame( [], $result );
	}

	public function test_callable_retriever_resolving_to_non_array_degrades_to_empty(): void {
		// Shape 2 callable that returns a scalar (not int[]) is unusable: after the
		// callable resolves, the is_array guard (line 150-151) returns []. Proves the
		// non-array path is reached via the callable branch, not only the direct one.
		Functions\expect( 'wc_get_product' )->never();

		$callable = fn( $query, $filters ) => 'not-an-array';
		$this->registerRetriever( fn( $value, $query, $filters ) => $callable );

		$result = Fahad_AI_Semantic_Search::retrieve( 'q', [] );

		$this->assertSame( [], $result );
	}

	// ── ranked_ids(): direct non-array + non-numeric candidate skip ─────────────

	public function test_direct_non_array_return_degrades_to_empty(): void {
		// A provider that returns a bare scalar (not a callable, not an array) hits
		// the is_array guard directly and returns [] (line 151).
		Functions\expect( 'wc_get_product' )->never();

		$this->registerRetriever( fn( $value, $query, $filters ) => 42 );

		$result = Fahad_AI_Semantic_Search::retrieve( 'q', [] );

		$this->assertSame( [], $result );
	}

	public function test_non_numeric_candidates_are_skipped_in_ranked_ids(): void {
		// The id list may contain junk a third party returned: nulls, strings,
		// objects, zero, negatives. Only positive numerics survive (line 157 skips
		// the rest), de-duped in first-seen order.
		$byId = [
			3 => $this->mockProduct( 3, 'Keep Three', '30.00' ),
			9 => $this->mockProduct( 9, 'Keep Nine', '90.00' ),
		];
		Functions\when( 'wc_get_product' )->alias( fn( $id ) => $byId[ (int) $id ] ?? false );

		// 'abc' (non-numeric) + null + an object are skipped; 0 and -5 are dropped
		// by the >0 guard; '3' duplicates 3 and is de-duped; '9' is numeric → 9.
		$this->registerRetriever(
			fn( $value, $query, $filters ) => [ 'abc', null, new stdClass(), 3, 0, -5, '3', '9' ]
		);

		$result = Fahad_AI_Semantic_Search::retrieve( 'mixed junk', [] );

		// Only 3 and 9 survive, in first-seen order, with no duplicate of 3.
		$this->assertSame( [ 3, 9 ], array_column( $result, 'id' ) );
	}

	// ── no retriever / null return: retrieve() returns [] (the empty guard) ──────

	public function test_no_retriever_returns_empty_array(): void {
		// Default state: the filter returns null ⇒ ranked_ids returns [] ⇒
		// retrieve() short-circuits on the empty( $ids ) guard, returning [].
		Functions\expect( 'wc_get_product' )->never();

		$this->registerRetriever( fn( $value, $query, $filters ) => null );

		$result = Fahad_AI_Semantic_Search::retrieve( 'q', [] );

		$this->assertSame( [], $result );
	}
}
