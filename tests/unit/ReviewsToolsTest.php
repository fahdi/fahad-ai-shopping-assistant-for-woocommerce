<?php
/**
 * Unit tests for Fahad_AI_Reviews_Tools (issue #11: reviews & ratings).
 *
 * Red → Green → Refactor cycle. Conventions mirror CatalogToolsTest: the reviews
 * tool ships as a drop-in feature pack that self-registers a provider via
 * Fahad_AI_Tool_Registry::register_pack() at file load. Each test registers the
 * pack's REAL provider through register_pack() (after snapshotting/clearing the
 * static pack list), then dispatches through
 * Fahad_AI_Tool_Registry::instance()->dispatch(), so the production
 * registration + merge + dispatch path is what is under test.
 *
 * WooCommerce reviews are comments: get_product_reviews queries approved review
 * comments via get_comments() and reads each per-review rating from the `rating`
 * comment meta via get_comment_meta(). Both are mocked here with Brain\Monkey.
 */

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;

class ReviewsToolsTest extends TestCase {

    use MockeryPHPUnitIntegration;

    /**
     * Snapshot of the registry's static pack providers, restored in tearDown so a
     * test here neither inherits another suite's packs nor leaks the reviews pack
     * we register for our own cases.
     *
     * @var array<int, callable>
     */
    private array $pack_snapshot = [];

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();

        $this->pack_snapshot = (array) ( new ReflectionProperty( Fahad_AI_Tool_Registry::class, 'pack_providers' ) )->getValue();

        Functions\stubs( [
            'absint'              => fn( $n ) => abs( (int) $n ),
            'sanitize_text_field' => fn( $s ) => $s,
            'wp_json_encode'      => fn( $d ) => json_encode( $d ),
            'wp_strip_all_tags'   => fn( $s ) => strip_tags( (string) $s ),
            'get_option'          => fn( $key, $default = '' ) => $default,
            // wp_trim_words: trim to N words, append the ellipsis when truncated.
            'wp_trim_words'       => function ( $text, $num = 55, $more = null ) {
                $words = preg_split( '/\s+/', trim( (string) $text ), -1, PREG_SPLIT_NO_EMPTY );
                if ( count( $words ) <= $num ) {
                    return implode( ' ', $words );
                }
                return implode( ' ', array_slice( $words, 0, $num ) ) . ( $more ?? '…' );
            },
            // mysql2date: deterministic identity for tests (real WP formats the date).
            'mysql2date'          => fn( $format, $date ) => $date,
        ] );
    }

    protected function tearDown(): void {
        ( new ReflectionProperty( Fahad_AI_Tool_Registry::class, 'pack_providers' ) )->setValue( null, $this->pack_snapshot );
        Monkey\tearDown();
        parent::tearDown();
    }

    /**
     * Fresh registry whose built tool list includes the reviews tools.
     * Resets the Tools + registry singletons, clears the static pack list, then
     * registers the reviews pack's REAL provider, exactly what the pack's
     * file-scope self-registration does in production.
     */
    private function registry(): Fahad_AI_Tool_Registry {
        ( new ReflectionProperty( Fahad_AI_Tools::class, 'instance' ) )->setValue( null, null );
        ( new ReflectionProperty( Fahad_AI_Tool_Registry::class, 'instance' ) )->setValue( null, null );

        Fahad_AI_Tool_Registry::reset_packs();
        Fahad_AI_Tool_Registry::register_pack( [ 'Fahad_AI_Reviews_Tools', 'register' ] );

        return Fahad_AI_Tool_Registry::instance();
    }

    /** A product mock that reports an average rating and review count. */
    private function mockProduct( int $id, float $avg, int $count, bool $visible = true ): WC_Product {
        $p = Mockery::mock( WC_Product::class );
        $p->shouldReceive( 'get_id' )->andReturn( $id );
        $p->shouldReceive( 'get_name' )->andReturn( 'Reviewed Product' );
        $p->shouldReceive( 'is_visible' )->andReturn( $visible );
        $p->shouldReceive( 'get_average_rating' )->andReturn( (string) $avg );
        $p->shouldReceive( 'get_review_count' )->andReturn( $count );
        return $p;
    }

    /** Build a WP_Comment-like review row (stdClass is enough for the tool). */
    private function review( int $id, string $author, string $content, string $date ): object {
        return (object) [
            'comment_ID'           => $id,
            'comment_author'       => $author,
            'comment_content'      => $content,
            'comment_date'         => $date,
        ];
    }

    // ── registration ──────────────────────────────────────────────────────────

    public function test_reviews_tool_is_registered_via_register_pack(): void {
        $names = array_column( $this->registry()->specs(), 'name' );

        $this->assertContains( 'get_product_reviews', $names );
        // Additive, the six built-ins remain.
        $this->assertContains( 'search_products', $names );
        $this->assertCount( 7, $names );
    }

    public function test_reviews_tool_spec_requires_product_id_and_hides_callback(): void {
        $specs = array_column( $this->registry()->specs(), null, 'name' );

        $this->assertArrayHasKey( 'get_product_reviews', $specs );
        $spec = $specs['get_product_reviews'];
        $this->assertArrayNotHasKey( 'callback', $spec );
        $this->assertSame( 'object', $spec['parameters']['type'] );
        $this->assertArrayHasKey( 'product_id', $spec['parameters']['properties'] );
        $this->assertContains( 'product_id', $spec['parameters']['required'] );
    }

    // ── get_product_reviews, happy path ────────────────────────────────────────

    public function test_returns_average_rating_and_review_count(): void {
        $product = $this->mockProduct( 5, 4.5, 12 );
        Functions\when( 'wc_get_product' )->justReturn( $product );
        Functions\when( 'get_comments' )->justReturn( [] );

        $result = $this->registry()->dispatch( 'get_product_reviews', [ 'product_id' => 5 ] );

        $this->assertArrayNotHasKey( 'error', $result );
        $this->assertSame( 4.5, $result['rating'] );
        $this->assertSame( 12, $result['review_count'] );
        $this->assertArrayHasKey( 'reviews', $result );
    }

    public function test_returns_recent_approved_review_snippets(): void {
        $product = $this->mockProduct( 5, 4.0, 2 );
        Functions\when( 'wc_get_product' )->justReturn( $product );

        Functions\when( 'get_comments' )->justReturn( [
            $this->review( 101, 'Alice', 'Absolutely love this, great quality and fast shipping.', '2026-01-10 09:00:00' ),
            $this->review( 102, 'Bob',   'Decent value for the price.',                              '2026-01-09 12:00:00' ),
        ] );

        Functions\when( 'get_comment_meta' )->alias( function ( $id, $key, $single = false ) {
            $ratings = [ 101 => '5', 102 => '3' ];
            return $ratings[ $id ] ?? '';
        } );

        $result = $this->registry()->dispatch( 'get_product_reviews', [ 'product_id' => 5 ] );

        $this->assertCount( 2, $result['reviews'] );
        $this->assertSame( 'Alice', $result['reviews'][0]['author'] );
        $this->assertSame( 5, $result['reviews'][0]['rating'] );
        $this->assertStringContainsString( 'love this', $result['reviews'][0]['excerpt'] );
        $this->assertSame( '2026-01-10 09:00:00', $result['reviews'][0]['date'] );
        $this->assertSame( 'Bob', $result['reviews'][1]['author'] );
        $this->assertSame( 3, $result['reviews'][1]['rating'] );
    }

    public function test_only_approved_reviews_are_queried(): void {
        // The definition of trustworthy reviews: approved + type=review only.
        // Assert the query asks WooCommerce for exactly that, so moderation is
        // enforced, not just documented.
        $product = $this->mockProduct( 7, 4.0, 1 );
        Functions\when( 'wc_get_product' )->justReturn( $product );

        Functions\expect( 'get_comments' )
            ->once()
            ->andReturnUsing( function ( array $args ): array {
                $this->assertSame( 7, $args['post_id'] );
                $this->assertSame( 'approve', $args['status'] );
                $this->assertSame( 'review', $args['type'] );
                return [];
            } );

        $this->registry()->dispatch( 'get_product_reviews', [ 'product_id' => 7 ] );
    }

    public function test_default_snippet_limit_is_capped(): void {
        // Many approved reviews exist, but only a few recent snippets are returned
        // (keeps the payload small and the model focused on representative reviews).
        $product = $this->mockProduct( 5, 4.2, 50 );
        Functions\when( 'wc_get_product' )->justReturn( $product );

        // Realistic get_comments: it honours the `number` arg (bounds the query),
        // so the tool gets back only as many rows as it asked for.
        Functions\when( 'get_comments' )->alias( function ( array $args ) {
            $number = (int) ( $args['number'] ?? 0 );
            $rows   = [];
            for ( $i = 1; $i <= $number; $i++ ) {
                $rows[] = $this->review( $i, "Reviewer {$i}", "Review body {$i}", '2026-01-01 00:00:00' );
            }
            return $rows;
        } );
        Functions\when( 'get_comment_meta' )->justReturn( '4' );

        $result = $this->registry()->dispatch( 'get_product_reviews', [ 'product_id' => 5 ] );

        // review_count still reflects the true total from the product object…
        $this->assertSame( 50, $result['review_count'] );
        // …but the returned snippets are capped to the default (3).
        $this->assertCount( 3, $result['reviews'] );
    }

    public function test_snippet_limit_passes_number_to_get_comments(): void {
        $product = $this->mockProduct( 5, 4.2, 50 );
        Functions\when( 'wc_get_product' )->justReturn( $product );

        Functions\expect( 'get_comments' )
            ->once()
            ->andReturnUsing( function ( array $args ): array {
                // The query is bounded so we never pull every review from the DB.
                $this->assertArrayHasKey( 'number', $args );
                $this->assertSame( 3, $args['number'] );
                return [];
            } );

        $this->registry()->dispatch( 'get_product_reviews', [ 'product_id' => 5 ] );
    }

    // ── graceful empty + error states ────────────────────────────────────────────

    public function test_product_with_no_reviews_returns_empty_state_without_error(): void {
        $product = $this->mockProduct( 5, 0.0, 0 );
        Functions\when( 'wc_get_product' )->justReturn( $product );
        Functions\when( 'get_comments' )->justReturn( [] );

        $result = $this->registry()->dispatch( 'get_product_reviews', [ 'product_id' => 5 ] );

        $this->assertArrayNotHasKey( 'error', $result );
        $this->assertSame( 0, $result['review_count'] );
        $this->assertSame( 0.0, $result['rating'] );
        $this->assertSame( [], $result['reviews'] );
    }

    public function test_invalid_product_returns_error(): void {
        Functions\when( 'wc_get_product' )->justReturn( false );

        $result = $this->registry()->dispatch( 'get_product_reviews', [ 'product_id' => 9999 ] );

        $this->assertArrayHasKey( 'error', $result );
        $this->assertArrayNotHasKey( 'reviews', $result );
    }

    public function test_invisible_product_returns_error(): void {
        $product = $this->mockProduct( 3, 4.0, 5, false );
        Functions\when( 'wc_get_product' )->justReturn( $product );

        $result = $this->registry()->dispatch( 'get_product_reviews', [ 'product_id' => 3 ] );

        $this->assertArrayHasKey( 'error', $result );
    }

    public function test_review_without_rating_meta_reports_zero_rating(): void {
        // A review comment may legitimately have no rating meta; the snippet should
        // still surface with rating 0 rather than being dropped or erroring.
        $product = $this->mockProduct( 5, 4.0, 1 );
        Functions\when( 'wc_get_product' )->justReturn( $product );
        Functions\when( 'get_comments' )->justReturn( [
            $this->review( 200, 'Carol', 'No star rating left, just text.', '2026-02-01 00:00:00' ),
        ] );
        Functions\when( 'get_comment_meta' )->justReturn( '' );

        $result = $this->registry()->dispatch( 'get_product_reviews', [ 'product_id' => 5 ] );

        $this->assertCount( 1, $result['reviews'] );
        $this->assertSame( 0, $result['reviews'][0]['rating'] );
    }
}
