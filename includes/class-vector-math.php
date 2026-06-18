<?php
/**
 * Float-vector primitives for semantic retrieval (RAG Phase 0, S0.1).
 *
 * Two responsibilities, both pure (no WordPress / WooCommerce):
 *  - the float32 BLOB codec used to store embeddings in the MySQL vector store
 *    (`embedding LONGBLOB`, see RAG-DESIGN.md §5.1), and
 *  - cosine similarity for the brute-force scan (§2.1).
 *
 * Vectors are plain 0-indexed `float[]`. The BLOB form is little-endian float32
 * (`pack('g*', ...)`), which halves storage vs float64 and matches what every
 * embeddings provider returns at the precision we need.
 */

defined( 'ABSPATH' ) || exit;

final class Fahad_AI_Vector_Math {

	/**
	 * Pack a float vector to its little-endian float32 binary form.
	 *
	 * @param array<int, float|int> $vector
	 * @return string 4 bytes per element.
	 */
	public static function pack_vector( array $vector ): string {
		// 'g' = float, machine size, little-endian byte order — stable across hosts.
		return pack( 'g*', ...array_map( 'floatval', array_values( $vector ) ) );
	}

	/**
	 * Unpack a little-endian float32 BLOB back to a 0-indexed float vector.
	 *
	 * @return array<int, float>
	 */
	public static function unpack_vector( string $blob ): array {
		if ( '' === $blob ) {
			return [];
		}
		// unpack() returns a 1-based array; re-index to 0-based for callers.
		return array_values( unpack( 'g*', $blob ) );
	}

	/**
	 * Cosine similarity of two equal-length vectors.
	 *
	 * Returns a value in [-1, 1]; 1 for identical direction, 0 for orthogonal,
	 * -1 for opposite. A zero-magnitude vector yields 0.0 (no divide-by-zero),
	 * so an un-embedded/empty row never produces NAN in the scan.
	 *
	 * @param array<int, float|int> $a
	 * @param array<int, float|int> $b
	 * @throws InvalidArgumentException When the vectors differ in length.
	 */
	public static function cosine( array $a, array $b ): float {
		if ( count( $a ) !== count( $b ) ) {
			throw new InvalidArgumentException(
				sprintf( 'cosine() requires equal-length vectors, got %d and %d.', count( $a ), count( $b ) )
			);
		}

		$dot = 0.0;
		$na  = 0.0;
		$nb  = 0.0;
		$a   = array_values( $a );
		$b   = array_values( $b );
		foreach ( $a as $i => $av ) {
			$av   = (float) $av;
			$bv   = (float) $b[ $i ];
			$dot += $av * $bv;
			$na  += $av * $av;
			$nb  += $bv * $bv;
		}

		if ( $na <= 0.0 || $nb <= 0.0 ) {
			return 0.0;
		}

		return $dot / ( sqrt( $na ) * sqrt( $nb ) );
	}
}
