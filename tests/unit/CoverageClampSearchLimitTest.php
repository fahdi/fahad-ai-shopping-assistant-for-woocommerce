<?php
/**
 * Coverage for Fahad_AI_Tools::clamp_search_limit() (issue #285): the pure guard that keeps a
 * model-supplied search limit inside a safe 1..10 range, so a zero or negative limit can never
 * trigger an unbounded WooCommerce fetch (the whole catalogue into the LLM context) or an empty
 * result for a valid query.
 */

use PHPUnit\Framework\TestCase;

class CoverageClampSearchLimitTest extends TestCase {

	public function test_keeps_in_range_values_unchanged(): void {
		$this->assertSame( 5, Fahad_AI_Tools::clamp_search_limit( 5 ) );
		$this->assertSame( 1, Fahad_AI_Tools::clamp_search_limit( 1 ) );
		$this->assertSame( 10, Fahad_AI_Tools::clamp_search_limit( 10 ) );
	}

	public function test_clamps_above_ten_down_to_ten(): void {
		$this->assertSame( 10, Fahad_AI_Tools::clamp_search_limit( 25 ) );
	}

	public function test_clamps_zero_and_negative_up_to_one(): void {
		// The dangerous cases: 0 => empty results, -1 => unbounded catalogue fetch.
		$this->assertSame( 1, Fahad_AI_Tools::clamp_search_limit( 0 ) );
		$this->assertSame( 1, Fahad_AI_Tools::clamp_search_limit( -1 ) );
		$this->assertSame( 1, Fahad_AI_Tools::clamp_search_limit( -999 ) );
	}
}
