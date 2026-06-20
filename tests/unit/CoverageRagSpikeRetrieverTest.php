<?php
/**
 * Supplemental coverage tests for Fahad_AI_Rag_Spike_Retriever.
 *
 * Targets the empty-query guard in keyword_rank() (the early `return []`
 * when the query tokenizes to nothing), which the primary suite does not
 * exercise. The legs are pure static methods over plain arrays, so no
 * WordPress/Woo stubbing is required; dependencies (Vector_Math, Rrf) are
 * loaded by tests/bootstrap.php.
 */

use PHPUnit\Framework\TestCase;

class CoverageRagSpikeRetrieverTest extends TestCase {

	/**
	 * An empty query string tokenizes to nothing, so the guard returns []
	 * without scoring any docs.
	 */
	public function test_keyword_rank_empty_query_returns_empty(): void {
		$docs = [
			1 => 'wool winter coat warm',
			2 => 'warm gloves',
		];
		$this->assertSame( [], Fahad_AI_Rag_Spike_Retriever::keyword_rank( '', $docs ) );
	}

	/**
	 * A query made up entirely of non-word characters (punctuation/whitespace)
	 * also tokenizes to nothing and hits the same guard.
	 */
	public function test_keyword_rank_punctuation_only_query_returns_empty(): void {
		$docs = [ 7 => 'warm winter coat' ];
		$this->assertSame( [], Fahad_AI_Rag_Spike_Retriever::keyword_rank( '   !!! ,,, --- ', $docs ) );
	}

	/**
	 * The guard fires before the docs loop, so even a query that would match
	 * is dropped when it has no usable tokens. Whitespace-only is empty.
	 */
	public function test_keyword_rank_whitespace_query_returns_empty_even_with_matching_docs(): void {
		$docs = [ 1 => 'anything at all', 2 => 'more text here' ];
		$this->assertSame( [], Fahad_AI_Rag_Spike_Retriever::keyword_rank( "\t\n  ", $docs ) );
	}
}
