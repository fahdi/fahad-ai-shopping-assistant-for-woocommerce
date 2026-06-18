<?php
/**
 * Golden relevance set for the RAG spike (RAG Phase 0, S0.4).
 *
 * A small, deterministic, OFFLINE fixture: a mock catalog modelled on the demo
 * store plus canned embeddings on four interpretable semantic axes
 * [apparel, footwear, audio, hydration]. Real embeddings are never called in
 * tests (consistent with the harness rule, RAG-DESIGN.md §6.1); these canned
 * vectors let the keyword/vector/hybrid comparison run reproducibly so we can
 * prove the harness discriminates before wiring a live provider.
 *
 * The fixture is intentionally constructed so each retrieval mode has a known
 * blind spot: keyword misses pure-intent queries (Q2) and a relevant item with
 * no literal token (Q3 #14); vector misses an un-embedded relevant item (Q3 #15).
 * Only hybrid covers all three. See RagRelevanceGateTest.
 */

final class RagGoldenSet {

	/**
	 * @return array<int, array{text: string, vec: array<int, float>}>
	 */
	public static function catalog(): array {
		return [
			// id => searchable text (keyword leg)         + canned embedding (vector leg)
			10 => [ 'text' => 'Classic White T-Shirt cotton crew neck top', 'vec' => [ 1.0, 0.0, 0.0, 0.0 ] ],
			11 => [ 'text' => 'Blue Denim Jeans slim fit pants',            'vec' => [ 0.95, 0.1, 0.0, 0.0 ] ],
			12 => [ 'text' => 'Wireless Bluetooth Headphones noise cancelling audio', 'vec' => [ 0.0, 0.0, 1.0, 0.0 ] ],
			13 => [ 'text' => 'Stainless Steel Water Bottle insulated hydration flask', 'vec' => [ 0.0, 0.0, 0.0, 1.0 ] ],
			14 => [ 'text' => 'Running Sneakers lightweight footwear', 'vec' => [ 0.1, 1.0, 0.0, 0.0 ] ],
			38 => [ 'text' => 'Premium Pullover Hoodie warm fleece', 'vec' => [ 0.9, 0.0, 0.0, 0.0 ] ],
			// Un-embedded relevant item: keyword can find it (literal tokens), vector cannot.
			15 => [ 'text' => 'Cushioned Marathon Shoes', 'vec' => [ 0.0, 0.0, 0.0, 0.0 ] ],
		];
	}

	/**
	 * @return array<int, array{name: string, query: string, vec: array<int,float>, relevant: int[]}>
	 */
	public static function queries(): array {
		return [
			[
				// Both legs find the warm item; baseline parity case.
				'name'     => 'warm apparel',
				'query'    => 'something to keep me warm',
				'vec'      => [ 1.0, 0.0, 0.0, 0.0 ],
				'relevant' => [ 38 ],
			],
			[
				// Pure-intent query: no literal token overlap -> keyword blind, vector wins.
				'name'     => 'music device (semantic)',
				'query'    => 'music device for the gym',
				'vec'      => [ 0.0, 0.0, 1.0, 0.0 ],
				'relevant' => [ 12 ],
			],
			[
				// Split case: keyword finds #15 (literal "cushioned shoes marathon"),
				// vector finds #14 (footwear embedding). Only hybrid covers both.
				'name'     => 'cushioned running shoes (split)',
				'query'    => 'cushioned shoes for marathon',
				'vec'      => [ 0.0, 1.0, 0.0, 0.0 ],
				'relevant' => [ 14, 15 ],
			],
		];
	}

	/** Catalog as id => text (keyword leg input). */
	public static function texts(): array {
		return array_map( static fn( $d ) => $d['text'], self::catalog() );
	}

	/** Catalog as id => vector (vector leg input). */
	public static function vectors(): array {
		return array_map( static fn( $d ) => $d['vec'], self::catalog() );
	}
}
