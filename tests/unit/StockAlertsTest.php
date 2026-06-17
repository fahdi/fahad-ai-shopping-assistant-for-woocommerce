<?php
/**
 * Unit tests for Fahad_AI_Stock_Alerts (issue #51: the subscription STORE +
 * notifier behind the back-in-stock / price-drop alerts).
 *
 * Red → Green → Refactor. Conventions mirror MemoryToolsTest / OrderToolsTest: WP
 * functions mocked via Brain\Monkey; the singleton reset via reflection between
 * cases (NEVER ReflectionMethod::setAccessible — host runs PHP 8.5). The store is
 * option-backed, so a single in-memory $this->options map stands in for the WP
 * options table and a test asserts exactly what was persisted.
 *
 * CONSENT + ANTI-SPAM IS THE POINT (issue #51 hardening). The headline tests are
 * first-class:
 *   - DOUBLE OPT-IN: subscribe() records a PENDING row (never confirmed), so no
 *     notification can fire until the shopper clicks the emailed confirm link.
 *   - EMAIL VALIDATION + SANITIZATION: an invalid email is refused and stores
 *     nothing.
 *   - DEDUPE: the same email+product+variation+type subscribes once, not N times.
 *   - NEVER NOTIFY THE UNCONFIRMED: a stock change emails ONLY confirmed matching
 *     subscribers, then marks them sent (so they are not emailed twice), and never
 *     touches a pending or already-sent row.
 *   - ONE-CLICK UNSUBSCRIBE: a valid signed token removes the row; a forged token
 *     is rejected and removes nothing.
 *   - SIGNED TOKENS: confirm/unsubscribe tokens are HMAC-signed and bound to the
 *     subscription id + action, so one cannot be replayed as the other.
 *   - GDPR DELETE: erase_email() removes every row for an email address.
 */

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;

class StockAlertsTest extends TestCase {

	use MockeryPHPUnitIntegration;

	/**
	 * In-memory stand-in for the WP options table, keyed by option name. The store
	 * is option-backed, so get_option / update_option / delete_option are stubbed to
	 * read/write this map and a test can assert precisely what was persisted (and,
	 * for the invalid-email test, that nothing was).
	 *
	 * @var array<string, mixed>
	 */
	private array $options = [];

	/**
	 * Captured wp_mail() calls: each [ to, subject, body ]. Stubbed so a test can
	 * assert WHO was emailed (and that pending/sent rows were NOT).
	 *
	 * @var array<int, array{0:string,1:string,2:string}>
	 */
	private array $mail = [];

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$this->options = [];
		$this->mail    = [];

		Functions\stubs( [
			// Email helpers: a realistic-enough is_email + a trimming sanitizer.
			'is_email'            => fn( $e ) => is_string( $e ) && (bool) preg_match( '/^[^@\s]+@[^@\s]+\.[^@\s]+$/', trim( $e ) ) ? trim( $e ) : false,
			'sanitize_email'      => fn( $e ) => is_string( $e ) ? trim( strtolower( $e ) ) : '',
			'sanitize_text_field' => fn( $s ) => is_string( $s ) ? trim( $s ) : $s,
			'absint'              => fn( $n ) => abs( (int) $n ),
			'wp_hash'             => fn( $s ) => 'hashed:' . $s,
			// Deterministic id (the store may also fall back to a hash of the dedupe key).
			'wp_generate_uuid4'   => fn() => 'uuid-' . md5( uniqid( '', true ) ),
			'home_url'            => fn( $p = '' ) => 'https://shop.test' . $p,
			'add_query_arg'       => function ( $args, $url ) {
				return $url . '?' . http_build_query( $args );
			},
			'esc_url_raw'         => fn( $u ) => $u,
			'esc_url'             => fn( $u ) => $u,
			'esc_html'            => fn( $s ) => $s,
			'wp_kses_post'        => fn( $s ) => $s,
			'get_bloginfo'        => fn( $k = '' ) => 'Test Shop',
		] );

		// Option seam backed by $this->options.
		Functions\when( 'get_option' )->alias(
			fn( $name, $default = false ) => $this->options[ $name ] ?? $default
		);
		Functions\when( 'update_option' )->alias(
			function ( $name, $value ) {
				$this->options[ $name ] = $value;
				return true;
			}
		);
		Functions\when( 'delete_option' )->alias(
			function ( $name ) {
				unset( $this->options[ $name ] );
				return true;
			}
		);

		// wp_mail seam: record the call, report success.
		Functions\when( 'wp_mail' )->alias(
			function ( $to, $subject, $body, $headers = '' ) {
				$this->mail[] = [ (string) $to, (string) $subject, (string) $body ];
				return true;
			}
		);
	}

	protected function tearDown(): void {
		( new ReflectionProperty( Fahad_AI_Stock_Alerts::class, 'instance' ) )->setValue( null, null );
		Monkey\tearDown();
		parent::tearDown();
	}

	/** Fresh store singleton (reset between cases via reflection). */
	private function store(): Fahad_AI_Stock_Alerts {
		( new ReflectionProperty( Fahad_AI_Stock_Alerts::class, 'instance' ) )->setValue( null, null );
		return Fahad_AI_Stock_Alerts::instance();
	}

	/** All stored subscription rows (the raw option value). */
	private function rows(): array {
		return $this->options[ Fahad_AI_Stock_Alerts::OPTION ] ?? [];
	}

	// ── subscribe(): double opt-in, validation, dedupe ──────────────────────────

	public function test_subscribe_records_a_pending_double_opt_in_row(): void {
		$res = $this->store()->subscribe( 42, 'Jane@Example.com', 0, 'back_in_stock' );

		$this->assertTrue( $res['ok'] ?? false );
		// Returns the id + a confirm token so the caller can email a confirm link.
		$this->assertArrayHasKey( 'id', $res );

		$rows = $this->rows();
		$this->assertCount( 1, $rows );

		$row = reset( $rows );
		$this->assertSame( 42, $row['product_id'] );
		$this->assertSame( 'back_in_stock', $row['type'] );
		// DOUBLE OPT-IN: the row is PENDING, never confirmed on creation.
		$this->assertSame( 'pending', $row['status'] );
		// Email is sanitized (lower-cased here) and stored — never confirmed yet.
		$this->assertSame( 'jane@example.com', $row['email'] );
	}

	public function test_subscribe_rejects_an_invalid_email_and_stores_nothing(): void {
		$res = $this->store()->subscribe( 42, 'not-an-email', 0, 'back_in_stock' );

		$this->assertFalse( $res['ok'] ?? true );
		$this->assertArrayHasKey( 'error', $res );
		// Nothing persisted for a bad email.
		$this->assertSame( [], $this->rows() );
	}

	public function test_subscribe_dedupes_same_email_product_variation_type(): void {
		$store = $this->store();
		$store->subscribe( 42, 'jane@example.com', 7, 'back_in_stock' );
		$store->subscribe( 42, 'JANE@example.com', 7, 'back_in_stock' ); // same after sanitize

		// Deduped to a single row, not two.
		$this->assertCount( 1, $this->rows() );
	}

	public function test_subscribe_keeps_distinct_type_or_variation_as_separate_rows(): void {
		$store = $this->store();
		$store->subscribe( 42, 'jane@example.com', 7, 'back_in_stock' );
		$store->subscribe( 42, 'jane@example.com', 7, 'price_drop' );   // different type
		$store->subscribe( 42, 'jane@example.com', 9, 'back_in_stock' ); // different variation

		$this->assertCount( 3, $this->rows() );
	}

	public function test_subscribe_defaults_type_to_back_in_stock_and_rejects_unknown_type(): void {
		$store = $this->store();

		$default = $store->subscribe( 1, 'a@b.com' );
		$this->assertTrue( $default['ok'] ?? false );
		$row = reset( $this->options[ Fahad_AI_Stock_Alerts::OPTION ] );
		$this->assertSame( 'back_in_stock', $row['type'] );

		$bad = $store->subscribe( 1, 'c@d.com', 0, 'something_else' );
		$this->assertFalse( $bad['ok'] ?? true );
	}

	// ── confirm(): double opt-in completion via signed token ────────────────────

	public function test_confirm_with_a_valid_token_marks_the_row_confirmed(): void {
		$store = $this->store();
		$res   = $store->subscribe( 42, 'jane@example.com', 0, 'back_in_stock' );
		$id    = $res['id'];

		$token = $store->token( 'confirm', $id );
		$ok    = $store->confirm( $id, $token );

		$this->assertTrue( $ok );
		$rows = $this->rows();
		$this->assertSame( 'confirmed', $rows[ $id ]['status'] );
	}

	public function test_confirm_with_a_forged_token_is_rejected(): void {
		$store = $this->store();
		$res   = $store->subscribe( 42, 'jane@example.com', 0, 'back_in_stock' );
		$id    = $res['id'];

		$ok = $store->confirm( $id, 'forged-token' );

		$this->assertFalse( $ok );
		$this->assertSame( 'pending', $this->rows()[ $id ]['status'] );
	}

	public function test_confirm_token_cannot_be_replayed_as_an_unsubscribe_token(): void {
		$store = $this->store();
		$res   = $store->subscribe( 42, 'jane@example.com', 0, 'back_in_stock' );
		$id    = $res['id'];

		// A confirm token must NOT validate the unsubscribe action (bound to action).
		$confirm_token = $store->token( 'confirm', $id );
		$removed       = $store->unsubscribe( $id, $confirm_token );

		$this->assertFalse( $removed );
		$this->assertArrayHasKey( $id, $this->rows() );
	}

	// ── unsubscribe(): one-click, signed, honored ───────────────────────────────

	public function test_unsubscribe_with_a_valid_token_removes_the_row(): void {
		$store = $this->store();
		$res   = $store->subscribe( 42, 'jane@example.com', 0, 'back_in_stock' );
		$id    = $res['id'];

		$token   = $store->token( 'unsubscribe', $id );
		$removed = $store->unsubscribe( $id, $token );

		$this->assertTrue( $removed );
		$this->assertArrayNotHasKey( $id, $this->rows() );
	}

	public function test_unsubscribe_with_a_forged_token_removes_nothing(): void {
		$store = $this->store();
		$res   = $store->subscribe( 42, 'jane@example.com', 0, 'back_in_stock' );
		$id    = $res['id'];

		$removed = $store->unsubscribe( $id, 'forged' );

		$this->assertFalse( $removed );
		$this->assertArrayHasKey( $id, $this->rows() );
	}

	// ── notify on stock change: ONLY confirmed matches, then mark sent ──────────

	/**
	 * The headline notifier test. Three back-in-stock subs on product 42:
	 *   - confirmed (must be emailed),
	 *   - pending (must NOT be emailed — never confirmed),
	 *   - confirmed but already sent (must NOT be emailed again).
	 * Plus a confirmed sub on a DIFFERENT product (must NOT be emailed).
	 * After the run only the first is emailed and is marked sent.
	 */
	public function test_back_in_stock_notifies_only_confirmed_unsent_matches(): void {
		$store = $this->store();

		// Confirmed product-42 watcher → MUST be emailed.
		$confirmed = $store->subscribe( 42, 'confirmed@x.com', 0, 'back_in_stock' )['id'];
		$store->confirm( $confirmed, $store->token( 'confirm', $confirmed ) );

		// Pending product-42 watcher (never confirmed) → MUST NOT be emailed.
		$pending = $store->subscribe( 42, 'pending@x.com', 0, 'back_in_stock' )['id'];

		// Confirmed watcher on a DIFFERENT product → MUST NOT be emailed.
		$otherProduct = $store->subscribe( 99, 'other@x.com', 0, 'back_in_stock' )['id'];
		$store->confirm( $otherProduct, $store->token( 'confirm', $otherProduct ) );

		$store->notify_back_in_stock( 42, 0 );

		// Exactly ONE email, to the confirmed product-42 subscriber.
		$this->assertCount( 1, $this->mail );
		$this->assertSame( 'confirmed@x.com', $this->mail[0][0] );

		// That row is now marked sent; the pending + other-product rows are untouched.
		$rows = $this->rows();
		$this->assertNotEmpty( $rows[ $confirmed ]['sent'] );
		$this->assertEmpty( $rows[ $pending ]['sent'] ?? 0 );
		$this->assertEmpty( $rows[ $otherProduct ]['sent'] ?? 0 );
	}

	public function test_back_in_stock_email_includes_a_one_click_unsubscribe_link(): void {
		$store = $this->store();
		$id    = $store->subscribe( 42, 'jane@example.com', 0, 'back_in_stock' )['id'];
		$store->confirm( $id, $store->token( 'confirm', $id ) );

		$store->notify_back_in_stock( 42, 0 );

		$this->assertCount( 1, $this->mail );
		$body = $this->mail[0][2];
		// The unsubscribe URL carries the signed unsubscribe token (one-click honor).
		$this->assertStringContainsString( 'fahad_ai_stock_alert=unsubscribe', $body );
		$this->assertStringContainsString( $store->token( 'unsubscribe', $id ), $body );
	}

	public function test_a_marked_sent_row_is_not_emailed_again_on_a_second_change(): void {
		$store = $this->store();
		$id    = $store->subscribe( 42, 'jane@example.com', 0, 'back_in_stock' )['id'];
		$store->confirm( $id, $store->token( 'confirm', $id ) );

		$store->notify_back_in_stock( 42, 0 );
		$store->notify_back_in_stock( 42, 0 ); // second restock event

		// Still only ONE email total — a confirmed sub is notified once per restock.
		$this->assertCount( 1, $this->mail );
	}

	// ── price-drop notify: only on a real drop ──────────────────────────────────

	public function test_price_drop_notifies_only_when_the_new_price_is_lower(): void {
		$store = $this->store();
		$id    = $store->subscribe( 42, 'jane@example.com', 0, 'price_drop' )['id'];
		$store->confirm( $id, $store->token( 'confirm', $id ) );

		// A price drop from 50 to 40 → notify.
		$store->notify_price_drop( 42, 0, 50.0, 40.0 );
		$this->assertCount( 1, $this->mail );
	}

	public function test_price_drop_does_not_notify_when_the_price_rises(): void {
		$store = $this->store();
		$id    = $store->subscribe( 42, 'jane@example.com', 0, 'price_drop' )['id'];
		$store->confirm( $id, $store->token( 'confirm', $id ) );

		// A price INCREASE must NOT notify (no fabricated "drop").
		$store->notify_price_drop( 42, 0, 40.0, 50.0 );

		$this->assertCount( 0, $this->mail );
		// And the row is NOT marked sent — it is still armed for a real future drop.
		$this->assertEmpty( $this->rows()[ $id ]['sent'] ?? 0 );
	}

	// ── GDPR erase ───────────────────────────────────────────────────────────────

	public function test_erase_email_removes_every_row_for_that_address(): void {
		$store = $this->store();
		$store->subscribe( 42, 'jane@example.com', 0, 'back_in_stock' );
		$store->subscribe( 43, 'jane@example.com', 0, 'price_drop' );
		$store->subscribe( 44, 'bob@example.com', 0, 'back_in_stock' );

		$store->erase_email( 'JANE@example.com' ); // case-insensitive

		$rows   = $this->rows();
		$emails = array_column( $rows, 'email' );
		$this->assertNotContains( 'jane@example.com', $emails );
		$this->assertContains( 'bob@example.com', $emails );
		$this->assertCount( 1, $rows );
	}
}
