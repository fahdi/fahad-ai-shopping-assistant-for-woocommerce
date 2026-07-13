<?php
/**
 * Coverage for Fahad_AI_Tools::highly_rated_flag() (issue #255): the pure test behind the
 * grounded "top-rated" social-proof signal. True only for a product whose real average rating
 * meets the bar AND which has enough reviews to be meaningful, so the assistant can honestly
 * highlight a well-reviewed product and never fabricate popularity.
 */

use PHPUnit\Framework\TestCase;

class CoverageHighlyRatedTest extends TestCase {

	public function test_high_when_rating_and_reviews_meet_the_bar(): void {
		$this->assertTrue( Fahad_AI_Tools::highly_rated_flag( 4.5, 5, 4.5, 5 ) );
		$this->assertTrue( Fahad_AI_Tools::highly_rated_flag( 4.9, 40, 4.5, 5 ) );
	}

	public function test_not_high_when_rating_below_the_bar(): void {
		$this->assertFalse( Fahad_AI_Tools::highly_rated_flag( 4.2, 50, 4.5, 5 ) );
	}

	public function test_not_high_without_enough_reviews(): void {
		$this->assertFalse( Fahad_AI_Tools::highly_rated_flag( 5.0, 3, 4.5, 5 ) );
	}

	public function test_min_reviews_floored_at_one(): void {
		// A configured 0 still requires at least one real review.
		$this->assertFalse( Fahad_AI_Tools::highly_rated_flag( 5.0, 0, 4.5, 0 ) );
		$this->assertTrue( Fahad_AI_Tools::highly_rated_flag( 5.0, 1, 4.5, 0 ) );
	}
}
