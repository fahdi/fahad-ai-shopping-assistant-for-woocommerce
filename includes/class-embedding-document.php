<?php
/**
 * Composes the per-product text that gets embedded (RAG Phase 0, S0.3).
 *
 * One vector per product, built from a single composed document ordered
 * most- to least-salient (RAG-DESIGN.md §4.2):
 *
 *   {title}
 *   Categories: {names}
 *   {short description}
 *   {long description, HTML-stripped + truncated}
 *   Attributes: {name: value, ...}
 *   Tags: {names}
 *
 * SKU, price and stock are DELIBERATELY excluded: SKUs are opaque tokens best
 * matched by the keyword leg, and price/stock are live data read at retrieval
 * time (§4.4, §5.4). The content_hash lets the indexer skip re-embedding when
 * the embedded text has not changed — so a price-only edit never re-embeds.
 */

defined( 'ABSPATH' ) || exit;

final class Fahad_AI_Embedding_Document {

	/** Truncate the long description to bound embedding token cost. */
	public const LONG_DESC_MAX = 1500;

	/**
	 * Compose the embedding document from product fields.
	 *
	 * @param array{
	 *   title?: string, categories?: string[], short_description?: string,
	 *   description?: string, attributes?: array<string,string>, tags?: string[]
	 * } $fields Live/keyword fields (sku, price, stock) are ignored if present.
	 */
	public static function compose( array $fields ): string {
		$lines = [];

		$title = self::clean( $fields['title'] ?? '' );
		if ( '' !== $title ) {
			$lines[] = $title;
		}

		$categories = self::clean_list( $fields['categories'] ?? [] );
		if ( $categories ) {
			$lines[] = 'Categories: ' . implode( ', ', $categories );
		}

		$short = self::clean( $fields['short_description'] ?? '' );
		if ( '' !== $short ) {
			$lines[] = $short;
		}

		$long = self::clean( $fields['description'] ?? '' );
		if ( '' !== $long ) {
			if ( mb_strlen( $long ) > self::LONG_DESC_MAX ) {
				$long = mb_substr( $long, 0, self::LONG_DESC_MAX );
			}
			$lines[] = $long;
		}

		$attributes = [];
		foreach ( (array) ( $fields['attributes'] ?? [] ) as $name => $value ) {
			$name  = self::clean( (string) $name );
			$value = self::clean( (string) $value );
			if ( '' !== $name && '' !== $value ) {
				$attributes[] = $name . ': ' . $value;
			}
		}
		if ( $attributes ) {
			$lines[] = 'Attributes: ' . implode( ', ', $attributes );
		}

		$tags = self::clean_list( $fields['tags'] ?? [] );
		if ( $tags ) {
			$lines[] = 'Tags: ' . implode( ', ', $tags );
		}

		return implode( "\n", $lines );
	}

	/**
	 * Stable fingerprint of the composed document. Identical text → identical
	 * hash (re-embed skip); any change to an embedded field changes it.
	 */
	public static function content_hash( string $composed ): string {
		return sha1( $composed );
	}

	/** Strip HTML + collapse whitespace via WordPress's helper. */
	private static function clean( $value ): string {
		return trim( wp_strip_all_tags( (string) $value ) );
	}

	/**
	 * Clean a list of strings, dropping empties.
	 *
	 * @param mixed $values
	 * @return string[]
	 */
	private static function clean_list( $values ): array {
		return array_values( array_filter( array_map( [ self::class, 'clean' ], (array) $values ), static fn( $s ) => '' !== $s ) );
	}
}
