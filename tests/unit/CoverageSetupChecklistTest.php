<?php
/**
 * Coverage for the setup-progress checklist (issue #247): fahad_ai_setup_checklist() in
 * admin-settings.php. It tells the owner which high-value setup steps are done and which
 * would make the assistant more capable, so the value settings actually get filled in. Pure
 * over the stored options; the settings-page render of it is exercised elsewhere.
 */

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__, 2 ) . '/includes/admin-settings.php';

class CoverageSetupChecklistTest extends TestCase {

	/** @var array<string,mixed> */
	private array $options = [];

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		$this->options = [];
		Functions\when( 'get_option' )->alias( fn( $k, $d = '' ) => $this->options[ $k ] ?? $d );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/** Pull an item's done flag by label substring. */
	private function done( array $list, string $needle ): bool {
		foreach ( $list as $item ) {
			if ( false !== strpos( (string) $item['label'], $needle ) ) {
				return (bool) $item['done'];
			}
		}
		$this->fail( "No checklist item matching: {$needle}" );
	}

	public function test_all_items_are_todo_on_a_fresh_install(): void {
		$list = fahad_ai_setup_checklist();

		$this->assertNotEmpty( $list );
		foreach ( $list as $item ) {
			$this->assertFalse( (bool) $item['done'] );
			$this->assertSame( 'to do', $item['mark'] );
		}
	}

	public function test_marks_reflect_the_configured_settings(): void {
		// Provider connected + free-shipping set; Store Info + support contact still empty. This
		// single call exercises both the done and the to-do marker branches.
		$this->options = [
			'fahad_ai_provider'               => 'anthropic',
			'fahad_ai_anthropic_api_key'      => 'sk-abc',
			'fahad_ai_free_shipping_threshold' => 50.0,
		];

		$list = fahad_ai_setup_checklist();

		$this->assertTrue( $this->done( $list, 'provider' ) );
		$this->assertTrue( $this->done( $list, 'free-shipping' ) );
		$this->assertFalse( $this->done( $list, 'Store Information' ) );
		$this->assertFalse( $this->done( $list, 'support contact' ) );

		$marks = array_column( $list, 'mark' );
		$this->assertContains( 'done', $marks );
		$this->assertContains( 'to do', $marks );
	}
}
