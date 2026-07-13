<?php
/**
 * Coverage for Fahad_AI_Tools::discount_percent() (issue #257): the pure test behind the
 * grounded "X% off" urgency signal. Returns the whole-number percent off computed from a
 * product's real regular vs sale price, and null whenever there is no genuine discount, so
 * the assistant can honestly say how big a deal is and never fabricate one.
 */

use PHPUnit\Framework\TestCase;

class CoverageDiscountPercentTest extends TestCase {

	public function test_percent_for_a_real_reduction(): void {
		$this->assertSame( 30, Fahad_AI_Tools::discount_percent( 100.0, 70.0 ) );
		$this->assertSame( 50, Fahad_AI_Tools::discount_percent( 20.0, 10.0 ) );
	}

	public function test_rounds_to_nearest_whole_percent(): void {
		// 33.33% off rounds to 33.
		$this->assertSame( 33, Fahad_AI_Tools::discount_percent( 30.0, 20.0 ) );
		// 16.67% off rounds to 17.
		$this->assertSame( 17, Fahad_AI_Tools::discount_percent( 12.0, 10.0 ) );
	}

	public function test_null_when_sale_not_below_regular(): void {
		$this->assertNull( Fahad_AI_Tools::discount_percent( 50.0, 50.0 ) );
		$this->assertNull( Fahad_AI_Tools::discount_percent( 50.0, 60.0 ) );
	}

	public function test_null_when_regular_price_not_positive(): void {
		$this->assertNull( Fahad_AI_Tools::discount_percent( 0.0, 0.0 ) );
		$this->assertNull( Fahad_AI_Tools::discount_percent( -10.0, 5.0 ) );
	}

	public function test_null_when_sale_price_negative(): void {
		$this->assertNull( Fahad_AI_Tools::discount_percent( 100.0, -1.0 ) );
	}
}
