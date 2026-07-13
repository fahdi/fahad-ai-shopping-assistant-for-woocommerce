<?php
/**
 * Coverage for fahad_ai_cart_rate_trend() (issue #291): the pure test behind the week-over-week
 * trend on the digest's chat-to-cart line. Turns this week's and last week's rates (0..1
 * fractions) into a short whole-points note, and returns nothing when last week has no basis, so
 * the owner sees direction of travel and never a misleading "up from nothing".
 */

use PHPUnit\Framework\TestCase;

class CoverageCartRateTrendTest extends TestCase {

	public function test_up_when_this_week_is_higher(): void {
		// 0.30 vs 0.21 => up 9 points.
		$this->assertSame( 'up 9 points from last week', fahad_ai_cart_rate_trend( 0.30, 0.21 ) );
	}

	public function test_down_when_this_week_is_lower(): void {
		// 0.15 vs 0.25 => down 10 points.
		$this->assertSame( 'down 10 points from last week', fahad_ai_cart_rate_trend( 0.15, 0.25 ) );
	}

	public function test_level_when_equal(): void {
		$this->assertSame( 'level with last week', fahad_ai_cart_rate_trend( 0.22, 0.22 ) );
	}

	public function test_empty_when_previous_has_no_basis(): void {
		$this->assertSame( '', fahad_ai_cart_rate_trend( 0.30, 0.0 ) );
	}
}
