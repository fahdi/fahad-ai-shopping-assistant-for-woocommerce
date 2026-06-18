<?php
/**
 * Unit tests for Fahad_AI_Vector_Math (RAG Phase 0, S0.1).
 *
 * Pure float-vector helpers: the float32 BLOB codec used by the MySQL vector
 * store and the cosine similarity used by the brute-force scan. No WordPress
 * or WooCommerce dependencies.
 */

use PHPUnit\Framework\TestCase;

class VectorMathTest extends TestCase {

	public function test_pack_unpack_round_trips_a_vector(): void {
		// Values exactly representable in float32 so the round-trip is exact.
		$v = [ 0.5, -0.25, 1.0, 0.0, -1.0, 0.125 ];

		$blob = Fahad_AI_Vector_Math::pack_vector( $v );
		$this->assertIsString( $blob );
		$this->assertSame( 4 * count( $v ), strlen( $blob ), 'float32 = 4 bytes per element' );

		$out = Fahad_AI_Vector_Math::unpack_vector( $blob );
		$this->assertSame( $v, $out, 'round-trip is exact for float32-representable values' );
		$this->assertSame( 0, array_key_first( $out ), 'unpacked array is 0-indexed' );
	}

	public function test_pack_unpack_round_trips_arbitrary_values_within_float32_precision(): void {
		$v   = [ 0.1, 0.2, 0.333333, -0.7, 12345.678 ];
		$out = Fahad_AI_Vector_Math::unpack_vector( Fahad_AI_Vector_Math::pack_vector( $v ) );
		foreach ( $v as $i => $expected ) {
			$this->assertEqualsWithDelta( $expected, $out[ $i ], abs( $expected ) * 1e-5 + 1e-6 );
		}
	}

	public function test_cosine_of_identical_vectors_is_one(): void {
		$v = [ 1.0, 2.0, 3.0 ];
		$this->assertEqualsWithDelta( 1.0, Fahad_AI_Vector_Math::cosine( $v, $v ), 1e-9 );
	}

	public function test_cosine_of_orthogonal_vectors_is_zero(): void {
		$this->assertEqualsWithDelta( 0.0, Fahad_AI_Vector_Math::cosine( [ 1.0, 0.0 ], [ 0.0, 1.0 ] ), 1e-9 );
	}

	public function test_cosine_of_opposite_vectors_is_minus_one(): void {
		$this->assertEqualsWithDelta( -1.0, Fahad_AI_Vector_Math::cosine( [ 1.0, 1.0 ], [ -1.0, -1.0 ] ), 1e-9 );
	}

	public function test_cosine_with_a_zero_vector_is_zero_not_nan(): void {
		$c = Fahad_AI_Vector_Math::cosine( [ 0.0, 0.0, 0.0 ], [ 1.0, 2.0, 3.0 ] );
		$this->assertSame( 0.0, $c, 'a zero-magnitude vector yields 0.0, never a divide-by-zero / NAN' );
	}

	public function test_cosine_rejects_mismatched_lengths(): void {
		$this->expectException( InvalidArgumentException::class );
		Fahad_AI_Vector_Math::cosine( [ 1.0, 2.0 ], [ 1.0, 2.0, 3.0 ] );
	}
}
