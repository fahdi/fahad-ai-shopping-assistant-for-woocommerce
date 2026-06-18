<?php
/**
 * Unit tests for Fahad_AI_Embedding_Document (RAG Phase 0, S0.3).
 *
 * Composes the single text document embedded per product (RAG-DESIGN.md §4.2):
 * title / categories / short desc / long desc / attributes / tags, in salience
 * order, with SKU, price and stock DELIBERATELY excluded. The content_hash lets
 * the indexer skip re-embedding when the embedded text is unchanged.
 */

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;

class EmbeddingDocumentTest extends TestCase {

	use MockeryPHPUnitIntegration;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		// Mimic wp_strip_all_tags: remove tags, collapse whitespace.
		Functions\when( 'wp_strip_all_tags' )->alias(
			static fn( $s ) => trim( preg_replace( '/\s+/', ' ', preg_replace( '/<[^>]*>/', ' ', (string) $s ) ) )
		);
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	private function fields(): array {
		return [
			'title'             => 'Premium Pullover Hoodie',
			'categories'        => [ 'Clothing', 'Outerwear' ],
			'short_description' => 'Warm fleece-lined hoodie.',
			'description'       => '<p>Soft cotton blend, ideal for <strong>winter</strong>.</p>',
			'attributes'        => [ 'Material' => 'cotton', 'Season' => 'winter' ],
			'tags'              => [ 'warm', 'casual' ],
			'sku'              => 'SKU-XYZ-999',
			'price'            => '45.00',
			'stock'            => 'STOCKMARKER-INSTOCK',
		];
	}

	public function test_composes_sections_in_salience_order(): void {
		$doc = Fahad_AI_Embedding_Document::compose( $this->fields() );

		// Title first, then categories, then descriptions, attributes, tags.
		$this->assertStringStartsWith( 'Premium Pullover Hoodie', $doc );
		$this->assertMatchesRegularExpression(
			'/Hoodie.*Categories:.*Clothing.*Warm fleece.*winter.*Attributes:.*Material.*Tags:.*warm/s',
			$doc
		);
	}

	public function test_excludes_sku_price_and_stock(): void {
		$doc = Fahad_AI_Embedding_Document::compose( $this->fields() );
		$this->assertStringNotContainsStringIgnoringCase( 'SKU-XYZ-999', $doc, 'SKU must not pollute the embedding' );
		$this->assertStringNotContainsString( '45.00', $doc, 'price is live data, never embedded' );
		$this->assertStringNotContainsString( 'STOCKMARKER', $doc, 'stock is live data, never embedded' );
	}

	public function test_strips_html_and_truncates_long_description(): void {
		$fields                = $this->fields();
		$fields['description'] = '<p>' . str_repeat( 'winterword ', 1000 ) . '</p>'; // ~11k chars
		$doc                   = Fahad_AI_Embedding_Document::compose( $fields );

		$this->assertStringNotContainsString( '<p>', $doc, 'HTML stripped' );
		$this->assertLessThanOrEqual( 1800, strlen( $doc ), 'long description truncated (~1500) so the doc stays bounded' );
		$this->assertStringContainsString( 'winterword', $doc, 'truncation keeps the leading description text' );
	}

	public function test_omits_empty_sections(): void {
		$doc = Fahad_AI_Embedding_Document::compose(
			[ 'title' => 'Plain Item', 'categories' => [], 'attributes' => [], 'tags' => [] ]
		);
		$this->assertStringContainsString( 'Plain Item', $doc );
		$this->assertStringNotContainsString( 'Categories:', $doc );
		$this->assertStringNotContainsString( 'Attributes:', $doc );
		$this->assertStringNotContainsString( 'Tags:', $doc );
	}

	public function test_content_hash_is_stable_and_field_sensitive(): void {
		$base = $this->fields();

		$h1 = Fahad_AI_Embedding_Document::content_hash( Fahad_AI_Embedding_Document::compose( $base ) );

		// Same embedded fields -> identical hash.
		$h2 = Fahad_AI_Embedding_Document::content_hash( Fahad_AI_Embedding_Document::compose( $base ) );
		$this->assertSame( $h1, $h2 );
		$this->assertMatchesRegularExpression( '/^[0-9a-f]{40}$/', $h1, 'SHA-1 hex' );

		// Price/stock-only edit -> SAME hash (those are not embedded).
		$priceEdit          = $base;
		$priceEdit['price'] = '99.99';
		$priceEdit['stock'] = 'STOCKMARKER-OUTOFSTOCK';
		$this->assertSame(
			$h1,
			Fahad_AI_Embedding_Document::content_hash( Fahad_AI_Embedding_Document::compose( $priceEdit ) ),
			'a price/stock-only change must not trigger a re-embed'
		);

		// Title change -> DIFFERENT hash.
		$titleEdit          = $base;
		$titleEdit['title'] = 'Different Hoodie';
		$this->assertNotSame(
			$h1,
			Fahad_AI_Embedding_Document::content_hash( Fahad_AI_Embedding_Document::compose( $titleEdit ) )
		);
	}
}
