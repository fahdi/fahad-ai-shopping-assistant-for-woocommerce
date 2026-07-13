<?php
/**
 * Coverage for Fahad_AI_Coupon_Tools::coupon_discount_amount() (issue #261): the pure test
 * behind the "that saved you $X" confirmation shown after a coupon is applied. Returns the
 * rounded money a code actually took off the cart, and null when the discount is zero or
 * negative (e.g. a free-shipping-only code), so the assistant confirms a real saving and
 * never claims a "$0 off".
 */

use PHPUnit\Framework\TestCase;

class CoverageCouponDiscountAmountTest extends TestCase {

	public function test_returns_rounded_amount_for_a_positive_discount(): void {
		$this->assertSame( 8.5, Fahad_AI_Coupon_Tools::coupon_discount_amount( 8.5 ) );
		$this->assertSame( 3.33, Fahad_AI_Coupon_Tools::coupon_discount_amount( 3.333333 ) );
	}

	public function test_null_for_zero_discount(): void {
		// A free-shipping-only code applies successfully but reduces no line: no "$0 off".
		$this->assertNull( Fahad_AI_Coupon_Tools::coupon_discount_amount( 0.0 ) );
	}

	public function test_null_for_negative_discount(): void {
		$this->assertNull( Fahad_AI_Coupon_Tools::coupon_discount_amount( -5.0 ) );
	}
}
