<?php
/**
 * Coverage for Fahad_AI_Tools::free_shipping_progress() (issue #218): the pure calculation
 * behind the in-cart "you are $X away from free shipping" nudge. Given the cart total and
 * the configured threshold it returns the exact remaining amount, so the assistant states
 * a grounded number from real cart data instead of estimating.
 */

use PHPUnit\Framework\TestCase;

class CoverageFreeShippingProgressTest extends TestCase {

	public function test_returns_null_when_no_threshold(): void {
		$this->assertNull( Fahad_AI_Tools::free_shipping_progress( 40.0, 0.0 ) );
		$this->assertNull( Fahad_AI_Tools::free_shipping_progress( 40.0, -5.0 ) );
	}

	public function test_reports_remaining_below_the_threshold(): void {
		$progress = Fahad_AI_Tools::free_shipping_progress( 30.0, 50.0 );

		$this->assertSame( 50.0, $progress['threshold'] );
		$this->assertSame( 20.0, $progress['remaining'] );
		$this->assertFalse( $progress['qualified'] );
	}

	public function test_qualified_at_or_above_the_threshold(): void {
		$progress = Fahad_AI_Tools::free_shipping_progress( 50.0, 50.0 );

		$this->assertSame( 0.0, $progress['remaining'] );
		$this->assertTrue( $progress['qualified'] );
	}

	public function test_remaining_never_goes_negative(): void {
		$progress = Fahad_AI_Tools::free_shipping_progress( 80.0, 50.0 );

		$this->assertSame( 0.0, $progress['remaining'] );
		$this->assertTrue( $progress['qualified'] );
	}
}
