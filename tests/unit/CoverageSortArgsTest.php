<?php
/**
 * Coverage for Fahad_AI_Tools::sort_args() (issue #279): the pure test behind search result
 * sorting. Maps a small set of shopper-friendly sort values to WooCommerce's own orderby/order,
 * and returns an empty array (no override, keep relevance) for anything unknown or empty, so the
 * sort is strictly opt-in and never reorders results the shopper did not ask to reorder.
 */

use PHPUnit\Framework\TestCase;

class CoverageSortArgsTest extends TestCase {

	public function test_maps_price_low_to_ascending_price(): void {
		$this->assertSame( [ 'orderby' => 'price', 'order' => 'ASC' ], Fahad_AI_Tools::sort_args( 'price_low' ) );
	}

	public function test_maps_price_high_to_descending_price(): void {
		$this->assertSame( [ 'orderby' => 'price', 'order' => 'DESC' ], Fahad_AI_Tools::sort_args( 'price_high' ) );
	}

	public function test_maps_rating_and_popularity_descending(): void {
		$this->assertSame( [ 'orderby' => 'rating', 'order' => 'DESC' ], Fahad_AI_Tools::sort_args( 'rating' ) );
		$this->assertSame( [ 'orderby' => 'popularity', 'order' => 'DESC' ], Fahad_AI_Tools::sort_args( 'popularity' ) );
	}

	public function test_empty_for_unknown_or_blank_sort(): void {
		$this->assertSame( [], Fahad_AI_Tools::sort_args( 'sideways' ) );
		$this->assertSame( [], Fahad_AI_Tools::sort_args( '' ) );
	}
}
