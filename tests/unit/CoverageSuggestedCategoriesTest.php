<?php
/**
 * Coverage for Fahad_AI_Tools::suggested_categories() (issue #267): the pure test behind the
 * dead-end-search recovery. Turns the get_terms() result into up to N real, non-empty product
 * category names the assistant can offer when a search finds nothing, and returns nothing for a
 * WP_Error or malformed input, so the assistant only ever redirects to categories that exist.
 */

use PHPUnit\Framework\TestCase;

class CoverageSuggestedCategoriesTest extends TestCase {

	/** A minimal term-like object, as get_terms() returns. */
	private function term( string $name ): object {
		return (object) [ 'name' => $name ];
	}

	public function test_returns_real_category_names_capped_at_the_limit(): void {
		$terms = [ $this->term( 'Shoes' ), $this->term( 'Bags' ), $this->term( 'Jackets' ), $this->term( 'Hats' ) ];
		$this->assertSame( [ 'Shoes', 'Bags', 'Jackets' ], Fahad_AI_Tools::suggested_categories( $terms, 3 ) );
	}

	public function test_skips_blank_and_malformed_terms(): void {
		$terms = [ $this->term( '  Shoes  ' ), $this->term( '' ), 'not-an-object', (object) [ 'slug' => 'no-name' ], $this->term( 'Bags' ) ];
		$this->assertSame( [ 'Shoes', 'Bags' ], Fahad_AI_Tools::suggested_categories( $terms, 5 ) );
	}

	public function test_empty_for_wp_error_or_non_array(): void {
		// get_terms() can return a WP_Error; the assistant must never offer a fabricated category.
		$this->assertSame( [], Fahad_AI_Tools::suggested_categories( new WP_Error( 'x', 'boom' ), 5 ) );
		$this->assertSame( [], Fahad_AI_Tools::suggested_categories( null, 5 ) );
	}

	public function test_empty_for_non_positive_limit(): void {
		$this->assertSame( [], Fahad_AI_Tools::suggested_categories( [ $this->term( 'Shoes' ) ], 0 ) );
	}
}
