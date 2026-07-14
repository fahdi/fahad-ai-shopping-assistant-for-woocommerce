<?php
/**
 * Unit tests for Fahad_AI_Fit_Tools (issue #54: size/fit advisor, grounded).
 *
 * Red → Green → Refactor cycle. Conventions mirror ReviewsToolsTest /
 * ComparisonToolsTest: WP/WC functions mocked via Brain\Monkey; WC objects via
 * Mockery; singletons reset via reflection (never ReflectionMethod::setAccessible , 
 * the host is PHP 8.5); the registry's static pack-provider list snapshotted in
 * setUp and restored in tearDown.
 *
 * get_fit_advice is NOT a built-in, it ships as a drop-in feature pack that
 * self-registers a provider via Fahad_AI_Tool_Registry::register_pack() at file
 * load. To exercise that registration genuinely (rather than inlining tool entries
 * by hand) every test registers the fit pack's REAL provider through
 * register_pack(), then dispatches through
 * Fahad_AI_Tool_Registry::instance()->dispatch(), so the production registration
 * + merge + dispatch path is what is under test.
 *
 * GROUNDING is the whole point of this tool. The tests assert the two things the
 * issue makes non-negotiable: a "runs small / true to size / runs large" hint is
 * emitted ONLY when real review/attribute data supports it, and the tool ABSTAINS
 * (never fabricates a fit claim) when there is no supporting data. A recommended
 * size must map to an in-stock variation, or the tool reports it unavailable.
 */

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;

class FitToolsTest extends TestCase {

    use MockeryPHPUnitIntegration;

    /**
     * Snapshot of the registry's static pack providers, restored in tearDown so a
     * test here neither inherits another suite's packs nor leaks the fit pack we
     * register for our own cases.
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
            'sanitize_text_field' => fn( $s ) => is_string( $s ) ? trim( $s ) : $s,
            // Registry get_tools() reads the merchant tool-gating option (issue #56);
            // default (no disabled tools) so dispatch()/specs() are unaffected.
            'get_option'          => fn( $key, $default = '' ) => $default,
            'wp_json_encode'      => fn( $d ) => json_encode( $d ),
            'wp_strip_all_tags'   => fn( $s ) => strip_tags( (string) $s ),
            // Attribute label resolver: turn pa_size / attribute_size into "Size".
            'wc_attribute_label'  => fn( $name ) => ucwords( str_replace( [ 'pa_', 'attribute_', '_', '-' ], [ '', '', ' ', ' ' ], (string) $name ) ),
            // Most fit tests use custom (non-taxonomy) attribute values, so default
            // taxonomy resolution to "no taxonomy"; the taxonomy test overrides these.
            'taxonomy_exists'     => fn() => false,
            'get_term_by'         => fn() => false,
        ] );
    }

    protected function tearDown(): void {
        ( new ReflectionProperty( Fahad_AI_Tool_Registry::class, 'pack_providers' ) )->setValue( null, $this->pack_snapshot );
        Monkey\tearDown();
        parent::tearDown();
    }

    /**
     * Fresh registry whose built tool list includes the fit tools.
     *
     * Resets the Tools + registry singletons, clears the static pack list, then
     * registers the fit pack's REAL provider via register_pack(), exactly what the
     * pack's file-scope self-registration does in production.
     */
    private function registry(): Fahad_AI_Tool_Registry {
        ( new ReflectionProperty( Fahad_AI_Tools::class, 'instance' ) )->setValue( null, null );
        ( new ReflectionProperty( Fahad_AI_Tool_Registry::class, 'instance' ) )->setValue( null, null );

        Fahad_AI_Tool_Registry::reset_packs();
        Fahad_AI_Tool_Registry::register_pack( [ 'Fahad_AI_Fit_Tools', 'register' ] );

        return Fahad_AI_Tool_Registry::instance();
    }

    /**
     * A variable product mock with a configurable size attribute, optional size-chart
     * meta, and a set of size variations.
     *
     * $size_options is the ordered list of size labels the product offers (drives
     * get_attributes()/get_attribute() for the size attribute). $variations is a list
     * of [ size => label, variation_id => int, in_stock => bool ] used both for
     * get_available_variations() (raw [ variation_id, attributes ] shape) and to
     * register each child via the caller's wc_get_product() alias.
     *
     * @param array<int,string> $size_options
     * @param array{0?:mixed}   $opts { size_attr_name?:string, size_chart?:string, fit_attr?:string }
     */
    private function mockVariableProduct( int $id, string $name, array $size_options, array $opts = [] ): WC_Product {
        $size_attr_name = $opts['size_attr_name'] ?? 'pa_size';

        $p = Mockery::mock( WC_Product::class );
        $p->shouldReceive( 'get_id' )->andReturn( $id );
        $p->shouldReceive( 'get_name' )->andReturn( $name );
        $p->shouldReceive( 'is_visible' )->andReturn( $opts['visible'] ?? true );
        $p->shouldReceive( 'is_in_stock' )->andReturn( true );
        $p->shouldReceive( 'is_type' )->andReturnUsing( fn( $t ) => 'variable' === $t );
        $p->shouldReceive( 'get_type' )->andReturn( 'variable' );
        $p->shouldReceive( 'get_average_rating' )->andReturn( '0' );
        $p->shouldReceive( 'get_review_count' )->andReturn( 0 );

        // Attribute map keyed by attribute name (the keys are all the enumeration
        // needs). A "size" attribute exposes its options via get_attribute() as a
        // comma-separated display string, the same shape WooCommerce returns.
        $attr_map = [];
        if ( ! empty( $size_options ) ) {
            $attr_map[ $size_attr_name ] = $size_attr_name;
        }
        $fit_attr = $opts['fit_attr'] ?? '';
        if ( '' !== $fit_attr ) {
            $attr_map['pa_fit'] = 'pa_fit';
        }
        $p->shouldReceive( 'get_attributes' )->andReturn( $attr_map );
        $p->shouldReceive( 'get_attribute' )->andReturnUsing( static function ( $name ) use ( $size_attr_name, $size_options, $fit_attr ) {
            if ( $name === $size_attr_name ) {
                return implode( ', ', $size_options );
            }
            if ( 'pa_fit' === $name ) {
                return $fit_attr;
            }
            return '';
        } );

        // Size-chart meta (empty unless the fixture sets it). The tool reads it via
        // get_meta() and surfaces it verbatim, it never invents a chart.
        $size_chart = $opts['size_chart'] ?? '';
        $p->shouldReceive( 'get_meta' )->andReturnUsing( static fn( $key = '' ) => '_fahad_ai_size_chart' === $key ? $size_chart : '' );

        return $p;
    }

    /** A child variation mock: its own id, stock, and (for label building) attributes. */
    private function mockVariation( int $id, bool $in_stock ): WC_Product {
        $v = Mockery::mock( WC_Product::class );
        $v->shouldReceive( 'get_id' )->andReturn( $id );
        $v->shouldReceive( 'get_parent_id' )->andReturn( 0 );
        $v->shouldReceive( 'is_in_stock' )->andReturn( $in_stock );
        return $v;
    }

    /** Build a WP_Comment-like approved review row (stdClass is enough for the tool). */
    private function review( int $id, string $content ): object {
        return (object) [
            'comment_ID'      => $id,
            'comment_author'  => 'Reviewer ' . $id,
            'comment_content' => $content,
            'comment_date'    => '2026-01-01 00:00:00',
        ];
    }

    // ── registration ──────────────────────────────────────────────────────────

    public function test_fit_tool_is_registered_via_register_pack(): void {
        $names = array_column( $this->registry()->specs(), 'name' );

        $this->assertContains( 'get_fit_advice', $names );
        // Additive, the six built-ins remain.
        $this->assertContains( 'search_products', $names );
        $this->assertCount( 7, $names );
    }

    public function test_fit_tool_spec_requires_product_id_and_hides_callback(): void {
        $specs = array_column( $this->registry()->specs(), null, 'name' );

        $this->assertArrayHasKey( 'get_fit_advice', $specs );
        $spec = $specs['get_fit_advice'];
        $this->assertArrayNotHasKey( 'callback', $spec );
        $this->assertArrayNotHasKey( 'personal', $spec ); // catalog data, not personal.
        $this->assertSame( 'object', $spec['parameters']['type'] );
        $this->assertArrayHasKey( 'product_id', $spec['parameters']['properties'] );
        $this->assertArrayHasKey( 'usual_size', $spec['parameters']['properties'] );
        $this->assertContains( 'product_id', $spec['parameters']['required'] );
    }

    // ── invalid product ─────────────────────────────────────────────────────────

    public function test_invalid_product_returns_error(): void {
        Functions\when( 'wc_get_product' )->justReturn( false );

        $result = $this->registry()->dispatch( 'get_fit_advice', [ 'product_id' => 9999 ] );

        $this->assertArrayHasKey( 'error', $result );
        $this->assertArrayNotHasKey( 'size_options', $result );
    }

    public function test_invisible_product_returns_error(): void {
        $product = $this->mockVariableProduct( 3, 'Hidden Tee', [ 'S', 'M', 'L' ], [ 'visible' => false ] );
        Functions\when( 'wc_get_product' )->justReturn( $product );
        Functions\when( 'get_comments' )->justReturn( [] );

        $result = $this->registry()->dispatch( 'get_fit_advice', [ 'product_id' => 3 ] );

        $this->assertArrayHasKey( 'error', $result );
    }

    // ── surfacing size attribute options + size chart ───────────────────────────

    public function test_surfaces_size_attribute_options(): void {
        $product = $this->mockVariableProduct( 5, 'Cotton Tee', [ 'Small', 'Medium', 'Large' ] );
        Functions\when( 'wc_get_product' )->justReturn( $product );
        Functions\when( 'get_comments' )->justReturn( [] );

        $result = $this->registry()->dispatch( 'get_fit_advice', [ 'product_id' => 5 ] );

        $this->assertArrayNotHasKey( 'error', $result );
        $this->assertSame( [ 'Small', 'Medium', 'Large' ], $result['size_options'] );
    }

    public function test_surfaces_size_chart_meta_when_present(): void {
        $product = $this->mockVariableProduct( 5, 'Cotton Tee', [ 'S', 'M', 'L' ], [
            'size_chart' => 'S = 36in chest, M = 40in chest, L = 44in chest',
        ] );
        Functions\when( 'wc_get_product' )->justReturn( $product );
        Functions\when( 'get_comments' )->justReturn( [] );

        $result = $this->registry()->dispatch( 'get_fit_advice', [ 'product_id' => 5 ] );

        $this->assertSame( 'S = 36in chest, M = 40in chest, L = 44in chest', $result['size_chart'] );
    }

    public function test_size_chart_is_null_when_absent(): void {
        $product = $this->mockVariableProduct( 5, 'Cotton Tee', [ 'S', 'M', 'L' ] );
        Functions\when( 'wc_get_product' )->justReturn( $product );
        Functions\when( 'get_comments' )->justReturn( [] );

        $result = $this->registry()->dispatch( 'get_fit_advice', [ 'product_id' => 5 ] );

        $this->assertNull( $result['size_chart'] );
    }

    // ── ABSTAIN: no supporting data ─────────────────────────────────────────────

    public function test_abstains_when_no_reviews_or_fit_data(): void {
        // The core hardening requirement: with no reviews and no explicit fit data,
        // the tool must NOT fabricate a fit claim. fit_hint is null and the tool says
        // fit information is not available.
        $product = $this->mockVariableProduct( 5, 'Cotton Tee', [ 'S', 'M', 'L' ] );
        Functions\when( 'wc_get_product' )->justReturn( $product );
        Functions\when( 'get_comments' )->justReturn( [] );

        $result = $this->registry()->dispatch( 'get_fit_advice', [ 'product_id' => 5 ] );

        $this->assertArrayNotHasKey( 'error', $result );
        $this->assertFalse( $result['fit_available'] );
        $this->assertNull( $result['fit_hint'] );
        $this->assertArrayNotHasKey( 'fit_basis', $result ); // no fabricated rationale.
        $this->assertArrayHasKey( 'message', $result );
        $this->assertStringContainsString( 'fit', strtolower( $result['message'] ) );
    }

    public function test_abstains_when_reviews_exist_but_none_mention_fit(): void {
        // Reviews exist, but none talk about sizing/fit. There is no fit SIGNAL, so
        // the tool still abstains rather than inferring a hint from unrelated praise.
        $product = $this->mockVariableProduct( 5, 'Cotton Tee', [ 'S', 'M', 'L' ] );
        Functions\when( 'wc_get_product' )->justReturn( $product );
        Functions\when( 'get_comments' )->justReturn( [
            $this->review( 1, 'Great colour and the fabric feels lovely.' ),
            $this->review( 2, 'Shipping was fast, very happy.' ),
        ] );

        $result = $this->registry()->dispatch( 'get_fit_advice', [ 'product_id' => 5 ] );

        $this->assertFalse( $result['fit_available'] );
        $this->assertNull( $result['fit_hint'] );
    }

    public function test_abstains_when_a_single_fit_review_is_below_threshold(): void {
        // One lone "runs small" mention is not enough signal to make a store-wide fit
        // claim; the tool abstains until the evidence is corroborated.
        $product = $this->mockVariableProduct( 5, 'Cotton Tee', [ 'S', 'M', 'L' ] );
        Functions\when( 'wc_get_product' )->justReturn( $product );
        Functions\when( 'get_comments' )->justReturn( [
            $this->review( 1, 'Runs small, I had to size up.' ),
            $this->review( 2, 'Nice tee, fast delivery.' ),
        ] );

        $result = $this->registry()->dispatch( 'get_fit_advice', [ 'product_id' => 5 ] );

        $this->assertFalse( $result['fit_available'] );
        $this->assertNull( $result['fit_hint'] );
    }

    public function test_abstains_when_fit_reviews_conflict_without_a_clear_majority(): void {
        // Signals split evenly between "small" and "large", no defensible direction,
        // so the tool abstains instead of guessing.
        $product = $this->mockVariableProduct( 5, 'Cotton Tee', [ 'S', 'M', 'L' ] );
        Functions\when( 'wc_get_product' )->justReturn( $product );
        Functions\when( 'get_comments' )->justReturn( [
            $this->review( 1, 'Runs small, size up.' ),
            $this->review( 2, 'Runs large, I sized down.' ),
            $this->review( 3, 'Tight fit, runs small for me.' ),
            $this->review( 4, 'Too big, runs large.' ),
        ] );

        $result = $this->registry()->dispatch( 'get_fit_advice', [ 'product_id' => 5 ] );

        $this->assertFalse( $result['fit_available'] );
        $this->assertNull( $result['fit_hint'] );
    }

    // ── GROUNDED hint from review signals ───────────────────────────────────────

    public function test_runs_small_hint_when_reviews_clearly_indicate_it(): void {
        $product = $this->mockVariableProduct( 5, 'Cotton Tee', [ 'S', 'M', 'L' ] );
        Functions\when( 'wc_get_product' )->justReturn( $product );
        Functions\when( 'get_comments' )->justReturn( [
            $this->review( 1, 'Runs small, I had to size up to a Large.' ),
            $this->review( 2, 'Fits tight, order a size up.' ),
            $this->review( 3, 'Too small, definitely size up.' ),
        ] );

        $result = $this->registry()->dispatch( 'get_fit_advice', [ 'product_id' => 5 ] );

        $this->assertTrue( $result['fit_available'] );
        $this->assertSame( 'runs_small', $result['fit_hint'] );
        // The hint is grounded, a basis string ties it back to the review evidence.
        $this->assertArrayHasKey( 'fit_basis', $result );
        $this->assertNotSame( '', $result['fit_basis'] );
    }

    public function test_runs_large_hint_when_reviews_clearly_indicate_it(): void {
        $product = $this->mockVariableProduct( 5, 'Cotton Tee', [ 'S', 'M', 'L' ] );
        Functions\when( 'wc_get_product' )->justReturn( $product );
        Functions\when( 'get_comments' )->justReturn( [
            $this->review( 1, 'Runs large, I sized down to a Small.' ),
            $this->review( 2, 'Too big, order a size down.' ),
            $this->review( 3, 'Roomy fit, runs large.' ),
        ] );

        $result = $this->registry()->dispatch( 'get_fit_advice', [ 'product_id' => 5 ] );

        $this->assertTrue( $result['fit_available'] );
        $this->assertSame( 'runs_large', $result['fit_hint'] );
    }

    public function test_true_to_size_hint_when_reviews_clearly_indicate_it(): void {
        $product = $this->mockVariableProduct( 5, 'Cotton Tee', [ 'S', 'M', 'L' ] );
        Functions\when( 'wc_get_product' )->justReturn( $product );
        Functions\when( 'get_comments' )->justReturn( [
            $this->review( 1, 'True to size, fits perfectly.' ),
            $this->review( 2, 'Fits as expected, true to size.' ),
            $this->review( 3, 'Perfect fit, my usual size was right.' ),
        ] );

        $result = $this->registry()->dispatch( 'get_fit_advice', [ 'product_id' => 5 ] );

        $this->assertTrue( $result['fit_available'] );
        $this->assertSame( 'true_to_size', $result['fit_hint'] );
    }

    public function test_only_approved_reviews_are_queried_for_fit_signals(): void {
        // Trust gate: fit signals may only come from APPROVED review comments (same
        // moderation gate the reviews tool enforces), never pending/spam.
        $product = $this->mockVariableProduct( 7, 'Cotton Tee', [ 'S', 'M', 'L' ] );
        Functions\when( 'wc_get_product' )->justReturn( $product );

        Functions\expect( 'get_comments' )
            ->once()
            ->andReturnUsing( function ( array $args ): array {
                $this->assertSame( 7, $args['post_id'] );
                $this->assertSame( 'approve', $args['status'] );
                $this->assertSame( 'review', $args['type'] );
                return [];
            } );

        $this->registry()->dispatch( 'get_fit_advice', [ 'product_id' => 7 ] );
    }

    // ── GROUNDED hint from an explicit fit attribute (no reviews needed) ─────────

    public function test_explicit_fit_attribute_grounds_a_hint_without_reviews(): void {
        // A merchant-set "Fit" attribute is real product data, so it can ground a hint
        // even with zero reviews, this is explicit data, not an inference.
        $product = $this->mockVariableProduct( 5, 'Slim Jeans', [ '30', '32', '34' ], [ 'fit_attr' => 'Runs small' ] );
        Functions\when( 'wc_get_product' )->justReturn( $product );
        Functions\when( 'get_comments' )->justReturn( [] );

        $result = $this->registry()->dispatch( 'get_fit_advice', [ 'product_id' => 5 ] );

        $this->assertTrue( $result['fit_available'] );
        $this->assertSame( 'runs_small', $result['fit_hint'] );
    }

    // ── recommended size → in-stock variation mapping ───────────────────────────

    /**
     * Wire a variable product + size variations and return a wc_get_product() alias
     * that resolves the parent and each variation child by id.
     *
     * @param array<int,array{size:string,variation_id:int,in_stock:bool}> $variations
     */
    private function wireVariations( WC_Product $parent, int $parent_id, array $variations ): void {
        $raw      = [];
        $children = [ $parent_id => $parent ];
        foreach ( $variations as $v ) {
            $raw[] = [
                'variation_id' => $v['variation_id'],
                'attributes'   => [ 'attribute_pa_size' => $v['size'] ],
            ];
            $children[ $v['variation_id'] ] = $this->mockVariation( $v['variation_id'], $v['in_stock'] );
        }
        $parent->shouldReceive( 'get_available_variations' )->andReturn( $raw );

        Functions\when( 'wc_get_product' )->alias( fn( $id ) => $children[ (int) $id ] ?? false );
    }

    public function test_recommended_size_maps_to_in_stock_variation(): void {
        // Shopper gives their usual size; with no fit hint the recommendation is that
        // same size, and it must resolve to an in-stock variation.
        $parent = $this->mockVariableProduct( 5, 'Cotton Tee', [ 'small', 'medium', 'large' ] );
        $this->wireVariations( $parent, 5, [
            [ 'size' => 'small',  'variation_id' => 51, 'in_stock' => true ],
            [ 'size' => 'medium', 'variation_id' => 52, 'in_stock' => true ],
            [ 'size' => 'large',  'variation_id' => 53, 'in_stock' => true ],
        ] );
        Functions\when( 'get_comments' )->justReturn( [] );

        $result = $this->registry()->dispatch( 'get_fit_advice', [ 'product_id' => 5, 'usual_size' => 'medium' ] );

        $this->assertSame( 'medium', strtolower( (string) $result['recommended_size'] ) );
        $this->assertTrue( $result['size_available'] );
        $this->assertNotNull( $result['recommended_variation'] );
        $this->assertSame( 52, $result['recommended_variation']['variation_id'] );
        $this->assertTrue( $result['recommended_variation']['in_stock'] );
    }

    public function test_recommended_size_reports_unavailable_when_variation_out_of_stock(): void {
        // The shopper's size exists but is sold out, the tool must SAY it is
        // unavailable rather than silently recommending an out-of-stock variation.
        $parent = $this->mockVariableProduct( 5, 'Cotton Tee', [ 'small', 'medium', 'large' ] );
        $this->wireVariations( $parent, 5, [
            [ 'size' => 'small',  'variation_id' => 51, 'in_stock' => true ],
            [ 'size' => 'medium', 'variation_id' => 52, 'in_stock' => false ],
            [ 'size' => 'large',  'variation_id' => 53, 'in_stock' => true ],
        ] );
        Functions\when( 'get_comments' )->justReturn( [] );

        $result = $this->registry()->dispatch( 'get_fit_advice', [ 'product_id' => 5, 'usual_size' => 'medium' ] );

        $this->assertSame( 'medium', strtolower( (string) $result['recommended_size'] ) );
        $this->assertFalse( $result['size_available'] );
        $this->assertNull( $result['recommended_variation'] );
    }

    public function test_recommended_size_reports_unavailable_when_size_not_offered(): void {
        // A size the product simply does not offer cannot be recommended, reported
        // unavailable, never invented.
        $parent = $this->mockVariableProduct( 5, 'Cotton Tee', [ 'small', 'medium' ] );
        $this->wireVariations( $parent, 5, [
            [ 'size' => 'small',  'variation_id' => 51, 'in_stock' => true ],
            [ 'size' => 'medium', 'variation_id' => 52, 'in_stock' => true ],
        ] );
        Functions\when( 'get_comments' )->justReturn( [] );

        $result = $this->registry()->dispatch( 'get_fit_advice', [ 'product_id' => 5, 'usual_size' => 'XXL' ] );

        $this->assertFalse( $result['size_available'] );
        $this->assertNull( $result['recommended_variation'] );
    }

    public function test_runs_small_hint_bumps_recommended_size_up_and_maps_to_stock(): void {
        // With a GROUNDED "runs small" hint and a usual size of Medium, the
        // recommendation steps up to Large and maps to its in-stock variation. The
        // size step is only ever taken because the hint is grounded in real reviews.
        $parent = $this->mockVariableProduct( 5, 'Cotton Tee', [ 'small', 'medium', 'large' ] );
        $this->wireVariations( $parent, 5, [
            [ 'size' => 'small',  'variation_id' => 51, 'in_stock' => true ],
            [ 'size' => 'medium', 'variation_id' => 52, 'in_stock' => true ],
            [ 'size' => 'large',  'variation_id' => 53, 'in_stock' => true ],
        ] );
        Functions\when( 'get_comments' )->justReturn( [
            $this->review( 1, 'Runs small, size up.' ),
            $this->review( 2, 'Too tight, order a size up.' ),
            $this->review( 3, 'Small fit, size up.' ),
        ] );

        $result = $this->registry()->dispatch( 'get_fit_advice', [ 'product_id' => 5, 'usual_size' => 'medium' ] );

        $this->assertSame( 'runs_small', $result['fit_hint'] );
        $this->assertSame( 'large', strtolower( (string) $result['recommended_size'] ) );
        $this->assertTrue( $result['size_available'] );
        $this->assertSame( 53, $result['recommended_variation']['variation_id'] );
    }

    public function test_no_recommendation_when_usual_size_not_supplied(): void {
        // Without a usual size there is nothing to map; the tool surfaces options and
        // any grounded hint but does NOT invent a recommended size.
        $parent = $this->mockVariableProduct( 5, 'Cotton Tee', [ 'small', 'medium', 'large' ] );
        $this->wireVariations( $parent, 5, [
            [ 'size' => 'small',  'variation_id' => 51, 'in_stock' => true ],
            [ 'size' => 'medium', 'variation_id' => 52, 'in_stock' => true ],
        ] );
        Functions\when( 'get_comments' )->justReturn( [] );

        $result = $this->registry()->dispatch( 'get_fit_advice', [ 'product_id' => 5 ] );

        $this->assertArrayNotHasKey( 'recommended_variation', $result );
        $this->assertArrayNotHasKey( 'recommended_size', $result );
    }

    public function test_simple_product_without_sizes_abstains_gracefully(): void {
        // A non-variable product with no size attribute is not an error: it returns
        // empty size options, abstains on fit, and offers no recommendation.
        $product = Mockery::mock( WC_Product::class );
        $product->shouldReceive( 'get_id' )->andReturn( 8 );
        $product->shouldReceive( 'get_name' )->andReturn( 'Coffee Mug' );
        $product->shouldReceive( 'is_visible' )->andReturn( true );
        $product->shouldReceive( 'is_type' )->andReturnUsing( fn( $t ) => 'simple' === $t );
        $product->shouldReceive( 'get_type' )->andReturn( 'simple' );
        $product->shouldReceive( 'get_attributes' )->andReturn( [] );
        $product->shouldReceive( 'get_attribute' )->andReturn( '' );
        $product->shouldReceive( 'get_meta' )->andReturn( '' );
        Functions\when( 'wc_get_product' )->justReturn( $product );
        Functions\when( 'get_comments' )->justReturn( [] );

        $result = $this->registry()->dispatch( 'get_fit_advice', [ 'product_id' => 8, 'usual_size' => 'M' ] );

        $this->assertArrayNotHasKey( 'error', $result );
        $this->assertSame( [], $result['size_options'] );
        $this->assertFalse( $result['fit_available'] );
        $this->assertNull( $result['fit_hint'] );
        $this->assertFalse( $result['size_available'] );
        $this->assertNull( $result['recommended_variation'] );
    }
}
