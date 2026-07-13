<?php
/**
 * Coverage for Fahad_AI_Tools::bestseller_flag() (issue #263): the pure test behind the
 * grounded per-product "bestseller" social-proof signal. True only when the owner has set a
 * positive units-sold threshold AND the product's real lifetime sales meet or exceed it, so
 * the assistant can honestly highlight a proven best-seller and never invent one. Opt-in:
 * with no threshold configured the flag is always false.
 */

use PHPUnit\Framework\TestCase;

class CoverageBestsellerFlagTest extends TestCase {

	public function test_true_when_sales_meet_or_exceed_a_positive_threshold(): void {
		$this->assertTrue( Fahad_AI_Tools::bestseller_flag( 100, 100 ) );
		$this->assertTrue( Fahad_AI_Tools::bestseller_flag( 640, 100 ) );
	}

	public function test_false_when_sales_below_the_threshold(): void {
		$this->assertFalse( Fahad_AI_Tools::bestseller_flag( 99, 100 ) );
		$this->assertFalse( Fahad_AI_Tools::bestseller_flag( 0, 100 ) );
	}

	public function test_false_when_feature_off_even_with_sales(): void {
		// Threshold 0 (default) means the owner has not opted in: never badge a product.
		$this->assertFalse( Fahad_AI_Tools::bestseller_flag( 9999, 0 ) );
		$this->assertFalse( Fahad_AI_Tools::bestseller_flag( 9999, -5 ) );
	}
}
