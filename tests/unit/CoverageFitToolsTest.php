<?php
/**
 * Coverage-focused unit tests for Fahad_AI_Fit_Tools (issue #54: size/fit advisor).
 *
 * Sibling to FitToolsTest. Where the sibling proves the headline grounding behaviour,
 * this file drives the remaining edge/guard branches so the file reaches 100% line
 * coverage WITHOUT loosening any real assertion:
 *
 *   - size_options(): a size attribute that exists but whose display value is empty
 *     yields [] (no fabricated options).
 *   - fit_from_attribute(): a "Fit" attribute that exists but is blank grounds nothing
 *     (falls through to reviews / abstains).
 *   - classify_text(): a whitespace-only review contributes no vote.
 *   - adjust_size(): a usual size that is not on the offered scale, and a grounded step
 *     that would fall off the largest/smallest offered size, both leave the usual size
 *     unchanged (never invent a size).
 *   - match_variation(): a variation whose child product cannot be resolved is skipped;
 *     a variation map carrying no size-named attribute yields no size match.
 *
 * Conventions mirror FitToolsTest exactly: WP/WC functions via Brain\Monkey; WC objects
 * via Mockery; the registry's static pack list snapshotted in setUp and restored in
 * tearDown; the fit pack's REAL provider registered via register_pack() and exercised
 * through the production dispatch path.
 */

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;

class CoverageFitToolsTest extends TestCase {

    use MockeryPHPUnitIntegration;

    /** @var array<int, callable> */
    private array $pack_snapshot = [];

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();

        $this->pack_snapshot = (array) ( new ReflectionProperty( Fahad_AI_Tool_Registry::class, 'pack_providers' ) )->getValue();

        Functions\stubs( [
            'absint'              => fn( $n ) => abs( (int) $n ),
            'sanitize_text_field' => fn( $s ) => is_string( $s ) ? trim( $s ) : $s,
            'get_option'          => fn( $key, $default = '' ) => $default,
            'wp_json_encode'      => fn( $d ) => json_encode( $d ),
            'wp_strip_all_tags'   => fn( $s ) => strip_tags( (string) $s ),
            'wc_attribute_label'  => fn( $name ) => ucwords( str_replace( [ 'pa_', 'attribute_', '_', '-' ], [ '', '', ' ', ' ' ], (string) $name ) ),
            'taxonomy_exists'     => fn() => false,
            'get_term_by'         => fn() => false,
        ] );
    }

    protected function tearDown(): void {
        ( new ReflectionProperty( Fahad_AI_Tool_Registry::class, 'pack_providers' ) )->setValue( null, $this->pack_snapshot );
        Monkey\tearDown();
        parent::tearDown();
    }

    /** Fresh registry whose built tool list includes the fit tools (REAL provider). */
    private function registry(): Fahad_AI_Tool_Registry {
        ( new ReflectionProperty( Fahad_AI_Tools::class, 'instance' ) )->setValue( null, null );
        ( new ReflectionProperty( Fahad_AI_Tool_Registry::class, 'instance' ) )->setValue( null, null );

        Fahad_AI_Tool_Registry::reset_packs();
        Fahad_AI_Tool_Registry::register_pack( [ 'Fahad_AI_Fit_Tools', 'register' ] );

        return Fahad_AI_Tool_Registry::instance();
    }

    /** Build an approved-review-like row (stdClass is all the tool reads). */
    private function review( int $id, string $content ): object {
        return (object) [
            'comment_ID'      => $id,
            'comment_author'  => 'Reviewer ' . $id,
            'comment_content' => $content,
            'comment_date'    => '2026-01-01 00:00:00',
        ];
    }

    // ── size_options(): attribute present but empty value (line 175) ─────────────

    public function test_empty_size_attribute_value_yields_no_options(): void {
        // The product HAS a size attribute (so find_attribute resolves a name), but its
        // display value is blank. The tool must surface no options rather than invent
        // any. Exercises the `'' === $value` guard in size_options().
        $product = Mockery::mock( WC_Product::class );
        $product->shouldReceive( 'get_id' )->andReturn( 11 );
        $product->shouldReceive( 'get_name' )->andReturn( 'Mystery Tee' );
        $product->shouldReceive( 'is_visible' )->andReturn( true );
        $product->shouldReceive( 'is_type' )->andReturnUsing( fn( $t ) => 'simple' === $t );
        $product->shouldReceive( 'get_type' )->andReturn( 'simple' );
        // A size attribute exists in the map…
        $product->shouldReceive( 'get_attributes' )->andReturn( [ 'pa_size' => 'pa_size' ] );
        // …but its display value is empty (and no fit attribute exists).
        $product->shouldReceive( 'get_attribute' )->andReturn( '' );
        $product->shouldReceive( 'get_meta' )->andReturn( '' );

        Functions\when( 'wc_get_product' )->justReturn( $product );
        Functions\when( 'get_comments' )->justReturn( [] );

        $result = $this->registry()->dispatch( 'get_fit_advice', [ 'product_id' => 11 ] );

        $this->assertArrayNotHasKey( 'error', $result );
        $this->assertSame( [], $result['size_options'] );
        $this->assertFalse( $result['fit_available'] );
    }

    // ── fit_from_attribute(): attribute present but blank value (line 254) ────────

    public function test_blank_fit_attribute_grounds_nothing_and_falls_through(): void {
        // A "Fit" attribute exists on the product but its value is empty. It must NOT
        // ground a hint; with no fit reviews either, the tool abstains. Exercises the
        // `'' === $value` guard in fit_from_attribute().
        $product = Mockery::mock( WC_Product::class );
        $product->shouldReceive( 'get_id' )->andReturn( 12 );
        $product->shouldReceive( 'get_name' )->andReturn( 'Slim Jeans' );
        $product->shouldReceive( 'is_visible' )->andReturn( true );
        $product->shouldReceive( 'is_type' )->andReturnUsing( fn( $t ) => 'variable' === $t );
        $product->shouldReceive( 'get_type' )->andReturn( 'variable' );
        // A fit attribute is enumerated (so find_attribute('fit') resolves a name)…
        $product->shouldReceive( 'get_attributes' )->andReturn( [ 'pa_size' => 'pa_size', 'pa_fit' => 'pa_fit' ] );
        // …but pa_fit's display value is blank; size has real options.
        $product->shouldReceive( 'get_attribute' )->andReturnUsing( static function ( $name ) {
            if ( 'pa_size' === $name ) {
                return '30, 32, 34';
            }
            return ''; // pa_fit (and anything else) is empty.
        } );
        $product->shouldReceive( 'get_meta' )->andReturn( '' );

        Functions\when( 'wc_get_product' )->justReturn( $product );
        Functions\when( 'get_comments' )->justReturn( [] );

        $result = $this->registry()->dispatch( 'get_fit_advice', [ 'product_id' => 12 ] );

        $this->assertArrayNotHasKey( 'error', $result );
        $this->assertSame( [ '30', '32', '34' ], $result['size_options'] );
        // Blank attribute grounds no hint, and no reviews back one → abstain.
        $this->assertFalse( $result['fit_available'] );
        $this->assertNull( $result['fit_hint'] );
    }

    // ── classify_text(): whitespace-only review casts no vote (line 333) ──────────

    public function test_whitespace_only_review_casts_no_fit_vote(): void {
        // A review whose content is only whitespace (after tag-stripping) must carry no
        // signal: classify_text returns null on the empty-trim guard, so it does not
        // count toward the fit threshold. Here the only OTHER review is a lone "runs
        // small", which alone is below MIN_FIT_REVIEWS → the tool abstains. This proves
        // the blank review did not silently add a vote that would have crossed the bar.
        $product = Mockery::mock( WC_Product::class );
        $product->shouldReceive( 'get_id' )->andReturn( 13 );
        $product->shouldReceive( 'get_name' )->andReturn( 'Cotton Tee' );
        $product->shouldReceive( 'is_visible' )->andReturn( true );
        $product->shouldReceive( 'is_type' )->andReturnUsing( fn( $t ) => 'variable' === $t );
        $product->shouldReceive( 'get_type' )->andReturn( 'variable' );
        $product->shouldReceive( 'get_attributes' )->andReturn( [ 'pa_size' => 'pa_size' ] );
        $product->shouldReceive( 'get_attribute' )->andReturnUsing( static fn( $name ) => 'pa_size' === $name ? 'S, M, L' : '' );
        $product->shouldReceive( 'get_meta' )->andReturn( '' );

        Functions\when( 'wc_get_product' )->justReturn( $product );
        Functions\when( 'get_comments' )->justReturn( [
            $this->review( 1, "   \n\t  " ),               // whitespace-only → no vote
            $this->review( 2, 'Runs small, had to size up.' ), // one real fit signal
        ] );

        $result = $this->registry()->dispatch( 'get_fit_advice', [ 'product_id' => 13 ] );

        // Only one genuine fit vote → below the corroboration threshold → abstain.
        $this->assertFalse( $result['fit_available'] );
        $this->assertNull( $result['fit_hint'] );
    }

    // ── adjust_size(): usual size not on the offered scale (line 407) ─────────────

    public function test_grounded_hint_does_not_step_a_usual_size_not_on_the_scale(): void {
        // A GROUNDED runs_small hint is present, but the shopper's usual size ("XXL") is
        // not among the offered options, so there is no index to step from. adjust_size
        // returns the usual size unchanged (no fabricated step), and since XXL is not
        // offered the recommendation is unavailable. Exercises the `false === $index`
        // guard (line 407) — distinct from line 412's off-the-end guard.
        $parent = Mockery::mock( WC_Product::class );
        $parent->shouldReceive( 'get_id' )->andReturn( 14 );
        $parent->shouldReceive( 'get_name' )->andReturn( 'Cotton Tee' );
        $parent->shouldReceive( 'is_visible' )->andReturn( true );
        $parent->shouldReceive( 'is_in_stock' )->andReturn( true );
        $parent->shouldReceive( 'is_type' )->andReturnUsing( fn( $t ) => 'variable' === $t );
        $parent->shouldReceive( 'get_type' )->andReturn( 'variable' );
        $parent->shouldReceive( 'get_attributes' )->andReturn( [ 'pa_size' => 'pa_size' ] );
        $parent->shouldReceive( 'get_attribute' )->andReturnUsing( static fn( $name ) => 'pa_size' === $name ? 'small, medium, large' : '' );
        $parent->shouldReceive( 'get_meta' )->andReturn( '' );
        $parent->shouldReceive( 'get_available_variations' )->andReturn( [
            [ 'variation_id' => 141, 'attributes' => [ 'attribute_pa_size' => 'small' ] ],
            [ 'variation_id' => 142, 'attributes' => [ 'attribute_pa_size' => 'medium' ] ],
            [ 'variation_id' => 143, 'attributes' => [ 'attribute_pa_size' => 'large' ] ],
        ] );

        $children = [ 14 => $parent ];
        foreach ( [ 141, 142, 143 ] as $vid ) {
            $child = Mockery::mock( WC_Product::class );
            $child->shouldReceive( 'get_id' )->andReturn( $vid );
            $child->shouldReceive( 'is_in_stock' )->andReturn( true );
            $children[ $vid ] = $child;
        }
        Functions\when( 'wc_get_product' )->alias( fn( $id ) => $children[ (int) $id ] ?? false );

        // A clear runs_small majority grounds the hint.
        Functions\when( 'get_comments' )->justReturn( [
            $this->review( 1, 'Runs small, size up.' ),
            $this->review( 2, 'Too tight, size up.' ),
            $this->review( 3, 'Small fit, order a size up.' ),
        ] );

        $result = $this->registry()->dispatch( 'get_fit_advice', [ 'product_id' => 14, 'usual_size' => 'XXL' ] );

        $this->assertSame( 'runs_small', $result['fit_hint'] );
        // Not on the scale → unchanged, and not offered → unavailable.
        $this->assertSame( 'XXL', $result['recommended_size'] );
        $this->assertFalse( $result['size_available'] );
        $this->assertNull( $result['recommended_variation'] );
    }

    // ── adjust_size(): a step would fall off the largest offered size (line 412) ──

    public function test_runs_small_hint_does_not_step_past_the_largest_size(): void {
        // Usual size is the LARGEST offered ("large") and the grounded hint is runs_small
        // (which steps UP). Stepping up would index past the end of the offered list, so
        // adjust_size keeps the usual size rather than inventing a size beyond what the
        // product sells. Exercises the `$target >= count()` arm of the line-412 guard.
        $parent = Mockery::mock( WC_Product::class );
        $parent->shouldReceive( 'get_id' )->andReturn( 15 );
        $parent->shouldReceive( 'get_name' )->andReturn( 'Cotton Tee' );
        $parent->shouldReceive( 'is_visible' )->andReturn( true );
        $parent->shouldReceive( 'is_in_stock' )->andReturn( true );
        $parent->shouldReceive( 'is_type' )->andReturnUsing( fn( $t ) => 'variable' === $t );
        $parent->shouldReceive( 'get_type' )->andReturn( 'variable' );
        $parent->shouldReceive( 'get_attributes' )->andReturn( [ 'pa_size' => 'pa_size' ] );
        $parent->shouldReceive( 'get_attribute' )->andReturnUsing( static fn( $name ) => 'pa_size' === $name ? 'small, medium, large' : '' );
        $parent->shouldReceive( 'get_meta' )->andReturn( '' );
        $parent->shouldReceive( 'get_available_variations' )->andReturn( [
            [ 'variation_id' => 151, 'attributes' => [ 'attribute_pa_size' => 'small' ] ],
            [ 'variation_id' => 152, 'attributes' => [ 'attribute_pa_size' => 'medium' ] ],
            [ 'variation_id' => 153, 'attributes' => [ 'attribute_pa_size' => 'large' ] ],
        ] );

        $children = [ 15 => $parent ];
        foreach ( [ 151, 152, 153 ] as $vid ) {
            $child = Mockery::mock( WC_Product::class );
            $child->shouldReceive( 'get_id' )->andReturn( $vid );
            $child->shouldReceive( 'is_in_stock' )->andReturn( true );
            $children[ $vid ] = $child;
        }
        Functions\when( 'wc_get_product' )->alias( fn( $id ) => $children[ (int) $id ] ?? false );

        Functions\when( 'get_comments' )->justReturn( [
            $this->review( 1, 'Runs small, size up.' ),
            $this->review( 2, 'Too tight, size up.' ),
            $this->review( 3, 'Small fit, order a size up.' ),
        ] );

        $result = $this->registry()->dispatch( 'get_fit_advice', [ 'product_id' => 15, 'usual_size' => 'large' ] );

        $this->assertSame( 'runs_small', $result['fit_hint'] );
        // Largest size + step up would fall off the end → stays "large".
        $this->assertSame( 'large', $result['recommended_size'] );
        // "large" is offered and in stock → still recommendable.
        $this->assertTrue( $result['size_available'] );
        $this->assertSame( 153, $result['recommended_variation']['variation_id'] );
    }

    public function test_runs_large_hint_does_not_step_below_the_smallest_size(): void {
        // Mirror of the above for the `$target < 0` arm of line 412: usual size is the
        // SMALLEST offered and the grounded hint is runs_large (steps DOWN), which would
        // index to -1. The size is kept rather than stepping below the smallest offered.
        $parent = Mockery::mock( WC_Product::class );
        $parent->shouldReceive( 'get_id' )->andReturn( 16 );
        $parent->shouldReceive( 'get_name' )->andReturn( 'Cotton Tee' );
        $parent->shouldReceive( 'is_visible' )->andReturn( true );
        $parent->shouldReceive( 'is_in_stock' )->andReturn( true );
        $parent->shouldReceive( 'is_type' )->andReturnUsing( fn( $t ) => 'variable' === $t );
        $parent->shouldReceive( 'get_type' )->andReturn( 'variable' );
        $parent->shouldReceive( 'get_attributes' )->andReturn( [ 'pa_size' => 'pa_size' ] );
        $parent->shouldReceive( 'get_attribute' )->andReturnUsing( static fn( $name ) => 'pa_size' === $name ? 'small, medium, large' : '' );
        $parent->shouldReceive( 'get_meta' )->andReturn( '' );
        $parent->shouldReceive( 'get_available_variations' )->andReturn( [
            [ 'variation_id' => 161, 'attributes' => [ 'attribute_pa_size' => 'small' ] ],
            [ 'variation_id' => 162, 'attributes' => [ 'attribute_pa_size' => 'medium' ] ],
            [ 'variation_id' => 163, 'attributes' => [ 'attribute_pa_size' => 'large' ] ],
        ] );

        $children = [ 16 => $parent ];
        foreach ( [ 161, 162, 163 ] as $vid ) {
            $child = Mockery::mock( WC_Product::class );
            $child->shouldReceive( 'get_id' )->andReturn( $vid );
            $child->shouldReceive( 'is_in_stock' )->andReturn( true );
            $children[ $vid ] = $child;
        }
        Functions\when( 'wc_get_product' )->alias( fn( $id ) => $children[ (int) $id ] ?? false );

        Functions\when( 'get_comments' )->justReturn( [
            $this->review( 1, 'Runs large, size down.' ),
            $this->review( 2, 'Too big, size down.' ),
            $this->review( 3, 'Roomy fit, order a size down.' ),
        ] );

        $result = $this->registry()->dispatch( 'get_fit_advice', [ 'product_id' => 16, 'usual_size' => 'small' ] );

        $this->assertSame( 'runs_large', $result['fit_hint'] );
        // Smallest size + step down would fall below 0 → stays "small".
        $this->assertSame( 'small', $result['recommended_size'] );
        $this->assertTrue( $result['size_available'] );
        $this->assertSame( 161, $result['recommended_variation']['variation_id'] );
    }

    // ── match_variation(): child product cannot be resolved (line 446) ────────────

    public function test_variation_whose_child_cannot_be_resolved_is_skipped(): void {
        // A variation advertises the requested size, but wc_get_product() on its
        // variation_id does NOT return a WC_Product (e.g. a deleted/corrupt child). That
        // variation is skipped (line 446 `continue`); since it is the only one offering
        // "medium", the size resolves to no variation → unavailable. The tool must report
        // it unavailable rather than recommending a phantom variation.
        $parent = Mockery::mock( WC_Product::class );
        $parent->shouldReceive( 'get_id' )->andReturn( 17 );
        $parent->shouldReceive( 'get_name' )->andReturn( 'Cotton Tee' );
        $parent->shouldReceive( 'is_visible' )->andReturn( true );
        $parent->shouldReceive( 'is_in_stock' )->andReturn( true );
        $parent->shouldReceive( 'is_type' )->andReturnUsing( fn( $t ) => 'variable' === $t );
        $parent->shouldReceive( 'get_type' )->andReturn( 'variable' );
        $parent->shouldReceive( 'get_attributes' )->andReturn( [ 'pa_size' => 'pa_size' ] );
        $parent->shouldReceive( 'get_attribute' )->andReturnUsing( static fn( $name ) => 'pa_size' === $name ? 'small, medium' : '' );
        $parent->shouldReceive( 'get_meta' )->andReturn( '' );
        $parent->shouldReceive( 'get_available_variations' )->andReturn( [
            [ 'variation_id' => 171, 'attributes' => [ 'attribute_pa_size' => 'small' ] ],
            [ 'variation_id' => 172, 'attributes' => [ 'attribute_pa_size' => 'medium' ] ],
        ] );

        // Only the parent and the "small" child resolve; the "medium" child (172) is
        // unresolvable, forcing the `! $child instanceof WC_Product` skip.
        $small_child = Mockery::mock( WC_Product::class );
        $small_child->shouldReceive( 'get_id' )->andReturn( 171 );
        $small_child->shouldReceive( 'is_in_stock' )->andReturn( true );
        $children = [ 17 => $parent, 171 => $small_child ]; // 172 deliberately absent.

        Functions\when( 'wc_get_product' )->alias( fn( $id ) => $children[ (int) $id ] ?? false );
        Functions\when( 'get_comments' )->justReturn( [] );

        $result = $this->registry()->dispatch( 'get_fit_advice', [ 'product_id' => 17, 'usual_size' => 'medium' ] );

        $this->assertSame( 'medium', $result['recommended_size'] );
        $this->assertFalse( $result['size_available'] );
        $this->assertNull( $result['recommended_variation'] );
    }

    // ── variation_size(): no size-named attribute in the map (line 477) ───────────

    public function test_variation_with_no_size_attribute_does_not_match(): void {
        // A variation carries attributes but none of them is the size attribute (e.g.
        // colour only). variation_size() returns '' (line 477), so the variation never
        // matches the requested size → the size is reported unavailable rather than
        // mapped to an unrelated variation.
        $parent = Mockery::mock( WC_Product::class );
        $parent->shouldReceive( 'get_id' )->andReturn( 18 );
        $parent->shouldReceive( 'get_name' )->andReturn( 'Cotton Tee' );
        $parent->shouldReceive( 'is_visible' )->andReturn( true );
        $parent->shouldReceive( 'is_in_stock' )->andReturn( true );
        $parent->shouldReceive( 'is_type' )->andReturnUsing( fn( $t ) => 'variable' === $t );
        $parent->shouldReceive( 'get_type' )->andReturn( 'variable' );
        $parent->shouldReceive( 'get_attributes' )->andReturn( [ 'pa_size' => 'pa_size' ] );
        $parent->shouldReceive( 'get_attribute' )->andReturnUsing( static fn( $name ) => 'pa_size' === $name ? 'small, medium' : '' );
        $parent->shouldReceive( 'get_meta' )->andReturn( '' );
        // The variation's raw attribute map has only a colour key — no size key.
        $parent->shouldReceive( 'get_available_variations' )->andReturn( [
            [ 'variation_id' => 181, 'attributes' => [ 'attribute_pa_colour' => 'small' ] ],
        ] );

        $child = Mockery::mock( WC_Product::class );
        $child->shouldReceive( 'get_id' )->andReturn( 181 );
        $child->shouldReceive( 'is_in_stock' )->andReturn( true );
        $children = [ 18 => $parent, 181 => $child ];

        Functions\when( 'wc_get_product' )->alias( fn( $id ) => $children[ (int) $id ] ?? false );
        Functions\when( 'get_comments' )->justReturn( [] );

        $result = $this->registry()->dispatch( 'get_fit_advice', [ 'product_id' => 18, 'usual_size' => 'small' ] );

        // No variation exposes a size attribute → "small" maps to nothing → unavailable.
        $this->assertSame( 'small', $result['recommended_size'] );
        $this->assertFalse( $result['size_available'] );
        $this->assertNull( $result['recommended_variation'] );
    }

    public function test_variation_with_empty_size_attribute_value_does_not_match(): void {
        // The variation DOES carry a size-named attribute, but its value is blank, so
        // variation_size() returns a value that trims to '' and the `'' === $var_size`
        // arm of the match guard skips it — again no phantom match.
        $parent = Mockery::mock( WC_Product::class );
        $parent->shouldReceive( 'get_id' )->andReturn( 19 );
        $parent->shouldReceive( 'get_name' )->andReturn( 'Cotton Tee' );
        $parent->shouldReceive( 'is_visible' )->andReturn( true );
        $parent->shouldReceive( 'is_in_stock' )->andReturn( true );
        $parent->shouldReceive( 'is_type' )->andReturnUsing( fn( $t ) => 'variable' === $t );
        $parent->shouldReceive( 'get_type' )->andReturn( 'variable' );
        $parent->shouldReceive( 'get_attributes' )->andReturn( [ 'pa_size' => 'pa_size' ] );
        $parent->shouldReceive( 'get_attribute' )->andReturnUsing( static fn( $name ) => 'pa_size' === $name ? 'small, medium' : '' );
        $parent->shouldReceive( 'get_meta' )->andReturn( '' );
        $parent->shouldReceive( 'get_available_variations' )->andReturn( [
            [ 'variation_id' => 191, 'attributes' => [ 'attribute_pa_size' => '' ] ],
        ] );

        $child = Mockery::mock( WC_Product::class );
        $child->shouldReceive( 'get_id' )->andReturn( 191 );
        $child->shouldReceive( 'is_in_stock' )->andReturn( true );
        $children = [ 19 => $parent, 191 => $child ];

        Functions\when( 'wc_get_product' )->alias( fn( $id ) => $children[ (int) $id ] ?? false );
        Functions\when( 'get_comments' )->justReturn( [] );

        $result = $this->registry()->dispatch( 'get_fit_advice', [ 'product_id' => 19, 'usual_size' => 'small' ] );

        $this->assertSame( 'small', $result['recommended_size'] );
        $this->assertFalse( $result['size_available'] );
        $this->assertNull( $result['recommended_variation'] );
    }
}
