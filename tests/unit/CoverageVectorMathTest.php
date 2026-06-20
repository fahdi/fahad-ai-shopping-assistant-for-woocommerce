<?php
/**
 * Coverage top-up for Fahad_AI_Vector_Math.
 *
 * The sibling VectorMathTest exercises pack/unpack round-trips and cosine, but
 * never feeds unpack_vector() an empty string, so the early `return []` guard
 * (the '' === $blob branch) stays uncovered. This file pins that branch with a
 * meaningful assertion: an empty BLOB must decode to an empty 0-indexed vector.
 *
 * Pure static helpers — no WordPress / WooCommerce, so no Brain\Monkey here.
 */

use PHPUnit\Framework\TestCase;

class CoverageVectorMathTest extends TestCase {

	public function test_unpack_empty_blob_returns_empty_array(): void {
		$out = Fahad_AI_Vector_Math::unpack_vector( '' );

		$this->assertSame( [], $out, 'an empty BLOB decodes to an empty vector, not a one-element array' );
	}

	public function test_pack_empty_vector_then_unpack_round_trips_to_empty(): void {
		// pack('g*') of an empty vector is the empty string, which must take the
		// '' === $blob guard and come back as [] rather than tripping unpack().
		$blob = Fahad_AI_Vector_Math::pack_vector( [] );
		$this->assertSame( '', $blob, 'packing an empty vector yields an empty BLOB' );

		$out = Fahad_AI_Vector_Math::unpack_vector( $blob );
		$this->assertSame( [], $out, 'empty BLOB round-trips back to an empty vector' );
	}
}
