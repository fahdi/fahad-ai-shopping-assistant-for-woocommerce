<?php
defined( 'ABSPATH' ) || exit;

/**
 * Capability required to view / save the assistant settings.
 *
 * `manage_woocommerce` (shop managers + admins) is the natural fit for a WooCommerce
 * extension; falls back to `manage_options` on the rare site where the WooCommerce
 * capability is not granted. Used by both the page guard and the admin menu.
 */
function dukandaar_settings_capability(): string {
	return current_user_can( 'manage_woocommerce' ) ? 'manage_woocommerce' : 'manage_options';
}

/**
 * Sanitize the merchant tone/persona setting to the fixed allowlist (issue #56).
 *
 * The tone maps to a vetted instruction line in the system prompt, so only the known
 * keys (Dukandaar_API_Handler::TONES) are accepted; anything else, including any
 * attempt to type a free-form instruction, collapses to '' (no tone line).
 *
 * @param mixed $raw Raw POST value.
 * @return string A valid tone key, or ''.
 */
function dukandaar_sanitize_tone( $raw ): string {
	$key = sanitize_key( is_scalar( $raw ) ? (string) $raw : '' );
	return isset( Dukandaar_API_Handler::TONES[ $key ] ) ? $key : '';
}

/**
 * Sanitize the selected AI provider to a known catalog id (issue: multi-provider).
 *
 * The provider <select> is built from Dukandaar_Providers::catalog(), so only an id
 * the catalog actually declares is accepted; anything else, including a tampered
 * value, collapses to the safe default 'anthropic'. This keeps routing keyed on a
 * real preset (handle_message looks the id up in the catalog).
 *
 * @param mixed $raw Raw POST value.
 * @return string A valid provider id, or 'anthropic'.
 */
function dukandaar_sanitize_provider( $raw ): string {
	$id = sanitize_text_field( is_scalar( $raw ) ? (string) $raw : '' );
	return in_array( $id, Dukandaar_Providers::ids(), true ) ? $id : 'anthropic';
}

/**
 * Sanitize the merchant default/allowed-languages setting (issue #61).
 *
 * The value is either the token 'auto' (detect the shopper's language and match it
 * across the supported set) or a short, human-readable list of languages the merchant
 * wants the assistant to favour (e.g. "English, Urdu"). It is folded into the system
 * prompt as advisory text, it sits BEFORE the absolute guardrails, so it can never
 * weaken the trust policy, but it is still sanitized to a single plain-text line.
 * An empty value collapses to 'auto' (the safe default).
 *
 * @param mixed $raw Raw POST value.
 * @return string A sanitized language list, or 'auto'.
 */
function dukandaar_sanitize_languages( $raw ): string {
	$value = sanitize_text_field( is_scalar( $raw ) ? (string) $raw : '' );
	return '' === $value ? 'auto' : $value;
}

/**
 * Sanitize the merchant disabled-tools list (issue #56).
 *
 * Accepts an array of tool-name strings (e.g. from a group of checkboxes), keeps only
 * non-empty strings, and runs each through sanitize_key so a name is a safe slug. Any
 * non-array input yields []. The registry separately protects the built-in tools, so
 * a tampered list can never disable the core WooCommerce tools.
 *
 * @param mixed $raw Raw POST value.
 * @return array<int, string> Clean, de-duplicated tool names.
 */
function dukandaar_sanitize_tool_list( $raw ): array {
	if ( ! is_array( $raw ) ) {
		return [];
	}

	$clean = [];
	foreach ( $raw as $name ) {
		if ( ! is_string( $name ) || '' === $name ) {
			continue;
		}
		$slug = sanitize_key( $name );
		if ( '' !== $slug ) {
			$clean[ $slug ] = $slug;
		}
	}

	return array_values( $clean );
}

/**
 * Resolve the dashboard's date range from the request (issue #49).
 *
 * Reads `from` / `to` Y-m-d GET params (the date-range picker), converting them to an
 * inclusive unix-timestamp window for the analytics aggregates. A blank/invalid bound
 * is left open (null), so "all time" is the default. `to` is pushed to the END of its
 * day so a single-day or inclusive range captures the whole final day. This is a
 * read-only filter, so it is GET (no nonce needed); the values are validated as dates
 * and never trusted as anything else.
 *
 * @return array{ from: ?int, to: ?int, from_str: string, to_str: string }
 */
function dukandaar_analytics_range_from_request(): array {
	$from_str = isset( $_GET['from'] ) ? sanitize_text_field( wp_unslash( $_GET['from'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only date-range filter, no state change.
	$to_str   = isset( $_GET['to'] ) ? sanitize_text_field( wp_unslash( $_GET['to'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only date-range filter, no state change.

	$from = dukandaar_analytics_parse_date( $from_str, false );
	$to   = dukandaar_analytics_parse_date( $to_str, true );

	return [
		'from'     => $from,
		'to'       => $to,
		'from_str' => $from ? $from_str : '',
		'to_str'   => $to ? $to_str : '',
	];
}

/**
 * Parse a Y-m-d string to a unix timestamp, or null when blank/invalid. When
 * $end_of_day is true the timestamp is the last second of that day (inclusive upper
 * bound). Uses the site timezone via strtotime so the merchant's chosen dates line up
 * with how WordPress shows times.
 */
function dukandaar_analytics_parse_date( string $date, bool $end_of_day ): ?int {
	$date = trim( $date );
	if ( '' === $date || ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) {
		return null;
	}

	$ts = strtotime( $date . ( $end_of_day ? ' 23:59:59' : ' 00:00:00' ) );

	return false === $ts ? null : $ts;
}

/**
 * Owner analytics & "unanswered questions" dashboard (issue #49).
 *
 * Renders the privacy-safe aggregates from Dukandaar_Analytics for a selectable date
 * range: top questions, the "questions we couldn't answer" list, the chat →
 * add-to-cart → order funnel, and cost per conversation. Also hosts the retention
 * controls, an opt-out toggle, an Export (download) and a Delete-all, each a
 * nonce-protected POST routed through admin-post.php. Gated by the same capability as
 * the settings page (manage_woocommerce, falling back to manage_options), re-checked
 * here as defence in depth.
 */
function dukandaar_analytics_page(): void {
	if ( ! current_user_can( dukandaar_settings_capability() ) ) {
		return;
	}

	$analytics = Dukandaar_Analytics::instance();

	// Toggle the opt-out from this page (its own nonce). The aggregates below still
	// render whatever history exists even when logging is paused.
	if ( isset( $_POST['dukandaar_analytics_save'] ) && check_admin_referer( 'dukandaar_analytics_settings' ) ) {
		update_option( Dukandaar_Analytics::OPTION_ENABLED, empty( $_POST['analytics_enabled'] ) ? 0 : 1 );
		echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Analytics settings saved.', 'dukandaar-ai-shopping-assistant-for-woocommerce' ) . '</p></div>';
	}

	// One-shot admin notices after an export/delete round-trip via admin-post.php.
	if ( isset( $_GET['dukandaar_purged'] ) ) {
		echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Analytics data deleted.', 'dukandaar-ai-shopping-assistant-for-woocommerce' ) . '</p></div>';
	}

	$enabled = $analytics->enabled();
	$range   = dukandaar_analytics_range_from_request();
	$window  = [ 'from' => $range['from'], 'to' => $range['to'] ];

	$top         = $analytics->top_questions( 20, $window );
	$unanswered  = $analytics->unanswered( 50, $window );
	$funnel      = $analytics->funnel( $window, 'dukandaar_attribute_orders' );
	$cost        = $analytics->cost_summary( $window );
	$export_url  = admin_url( 'admin-post.php' );
	$page_slug   = 'dukandaar-analytics';
	$currency    = function_exists( 'get_woocommerce_currency_symbol' ) ? get_woocommerce_currency_symbol() : '';
	?>
	<div class="wrap">
		<h1><?php esc_html_e( 'AI Assistant Analytics', 'dukandaar-ai-shopping-assistant-for-woocommerce' ); ?></h1>

		<?php if ( ! $enabled ) : ?>
			<div class="notice notice-warning inline"><p>
				<?php esc_html_e( 'Analytics logging is currently turned off. New conversations are not being recorded; the figures below reflect previously stored data only.', 'dukandaar-ai-shopping-assistant-for-woocommerce' ); ?>
			</p></div>
		<?php endif; ?>

		<p class="description" style="max-width:55em;">
			<?php esc_html_e( 'A privacy-safe view of how the assistant is performing. Questions are stored with emails masked and trimmed, never with names, IP addresses or customer identifiers, and this data is never sent back to the AI model. Data is kept on a rolling retention window and can be exported or deleted below.', 'dukandaar-ai-shopping-assistant-for-woocommerce' ); ?>
		</p>

		<!-- Date-range filter (read-only → GET, no nonce). -->
		<form method="get" style="margin:16px 0;">
			<input type="hidden" name="page" value="<?php echo esc_attr( $page_slug ); ?>">
			<label for="dukandaar-from"><?php esc_html_e( 'From', 'dukandaar-ai-shopping-assistant-for-woocommerce' ); ?></label>
			<input type="date" id="dukandaar-from" name="from" value="<?php echo esc_attr( $range['from_str'] ); ?>">
			<label for="dukandaar-to" style="margin-left:8px;"><?php esc_html_e( 'To', 'dukandaar-ai-shopping-assistant-for-woocommerce' ); ?></label>
			<input type="date" id="dukandaar-to" name="to" value="<?php echo esc_attr( $range['to_str'] ); ?>">
			<?php submit_button( esc_html__( 'Apply', 'dukandaar-ai-shopping-assistant-for-woocommerce' ), 'secondary', '', false ); ?>
			<?php if ( '' !== $range['from_str'] || '' !== $range['to_str'] ) : ?>
				<a class="button-link" href="<?php echo esc_url( admin_url( 'admin.php?page=' . $page_slug ) ); ?>"><?php esc_html_e( 'Reset', 'dukandaar-ai-shopping-assistant-for-woocommerce' ); ?></a>
			<?php endif; ?>
		</form>

		<!-- Funnel + cost summary cards. -->
		<h2><?php esc_html_e( 'Conversion funnel', 'dukandaar-ai-shopping-assistant-for-woocommerce' ); ?></h2>
		<table class="widefat striped" style="max-width:46em;">
			<tbody>
				<tr><td><?php esc_html_e( 'Conversations', 'dukandaar-ai-shopping-assistant-for-woocommerce' ); ?></td><td><strong><?php echo esc_html( (string) $funnel['conversations'] ); ?></strong></td></tr>
				<tr><td><?php esc_html_e( 'Saw a product', 'dukandaar-ai-shopping-assistant-for-woocommerce' ); ?></td><td><strong><?php echo esc_html( (string) $funnel['product_surfaced'] ); ?></strong></td></tr>
				<tr><td><?php esc_html_e( 'Added to cart', 'dukandaar-ai-shopping-assistant-for-woocommerce' ); ?></td><td><strong><?php echo esc_html( (string) $funnel['added_to_cart'] ); ?></strong></td></tr>
				<tr><td><?php esc_html_e( 'Chat-attributed orders', 'dukandaar-ai-shopping-assistant-for-woocommerce' ); ?></td><td><strong><?php echo null === $funnel['orders'] ? esc_html__( 'n/a', 'dukandaar-ai-shopping-assistant-for-woocommerce' ) : esc_html( (string) $funnel['orders'] ); ?></strong></td></tr>
			</tbody>
		</table>

		<h2><?php esc_html_e( 'Cost', 'dukandaar-ai-shopping-assistant-for-woocommerce' ); ?></h2>
		<table class="widefat striped" style="max-width:46em;">
			<tbody>
				<tr><td><?php esc_html_e( 'Total cost', 'dukandaar-ai-shopping-assistant-for-woocommerce' ); ?></td><td><strong><?php echo esc_html( $currency . number_format( (float) $cost['total_cost'], 4 ) ); ?></strong></td></tr>
				<tr><td><?php esc_html_e( 'Cost per conversation', 'dukandaar-ai-shopping-assistant-for-woocommerce' ); ?></td><td><strong><?php echo esc_html( $currency . number_format( (float) $cost['cost_per_conversation'], 4 ) ); ?></strong></td></tr>
				<tr><td><?php esc_html_e( 'Total tokens', 'dukandaar-ai-shopping-assistant-for-woocommerce' ); ?></td><td><strong><?php echo esc_html( (string) $cost['total_tokens'] ); ?></strong></td></tr>
				<tr><td><?php esc_html_e( 'Turns', 'dukandaar-ai-shopping-assistant-for-woocommerce' ); ?></td><td><strong><?php echo esc_html( (string) $cost['turns'] ); ?></strong></td></tr>
			</tbody>
		</table>
		<p class="description"><?php esc_html_e( 'Cost and token figures appear when your provider returns usage data; otherwise they read as zero.', 'dukandaar-ai-shopping-assistant-for-woocommerce' ); ?></p>

		<!-- Top questions. -->
		<h2><?php esc_html_e( 'Top questions', 'dukandaar-ai-shopping-assistant-for-woocommerce' ); ?></h2>
		<?php if ( empty( $top ) ) : ?>
			<p class="description"><?php esc_html_e( 'No questions recorded for this range yet.', 'dukandaar-ai-shopping-assistant-for-woocommerce' ); ?></p>
		<?php else : ?>
			<table class="widefat striped" style="max-width:55em;">
				<thead><tr>
					<th><?php esc_html_e( 'Question', 'dukandaar-ai-shopping-assistant-for-woocommerce' ); ?></th>
					<th style="width:6em;"><?php esc_html_e( 'Count', 'dukandaar-ai-shopping-assistant-for-woocommerce' ); ?></th>
				</tr></thead>
				<tbody>
					<?php foreach ( $top as $item ) : ?>
						<tr>
							<td><?php echo esc_html( $item['question'] ); ?></td>
							<td><?php echo esc_html( (string) $item['count'] ); ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>

		<!-- The "couldn't answer" list. -->
		<h2><?php esc_html_e( 'Questions we couldn\'t answer', 'dukandaar-ai-shopping-assistant-for-woocommerce' ); ?></h2>
		<p class="description" style="max-width:55em;"><?php esc_html_e( 'Turns where the assistant abstained, escalated to support, or had no matching action. These are your content and coverage opportunities.', 'dukandaar-ai-shopping-assistant-for-woocommerce' ); ?></p>
		<?php if ( empty( $unanswered ) ) : ?>
			<p class="description"><?php esc_html_e( 'Nothing here for this range, the assistant answered everything it was asked.', 'dukandaar-ai-shopping-assistant-for-woocommerce' ); ?></p>
		<?php else : ?>
			<table class="widefat striped" style="max-width:55em;">
				<thead><tr>
					<th><?php esc_html_e( 'Question', 'dukandaar-ai-shopping-assistant-for-woocommerce' ); ?></th>
					<th style="width:10em;"><?php esc_html_e( 'Outcome', 'dukandaar-ai-shopping-assistant-for-woocommerce' ); ?></th>
					<th style="width:14em;"><?php esc_html_e( 'When', 'dukandaar-ai-shopping-assistant-for-woocommerce' ); ?></th>
				</tr></thead>
				<tbody>
					<?php foreach ( $unanswered as $item ) : ?>
						<tr>
							<td><?php echo esc_html( '' !== $item['question'] ? $item['question'] : __( '(no question text)', 'dukandaar-ai-shopping-assistant-for-woocommerce' ) ); ?></td>
							<td><?php echo esc_html( dukandaar_analytics_outcome_label( $item['outcome'] ) ); ?></td>
							<td><?php echo esc_html( dukandaar_analytics_format_time( $item['created'] ) ); ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>

		<!-- Retention controls: opt-out, export, delete. -->
		<h2><?php esc_html_e( 'Data &amp; retention', 'dukandaar-ai-shopping-assistant-for-woocommerce' ); ?></h2>

		<form method="post" style="margin-bottom:18px;">
			<?php wp_nonce_field( 'dukandaar_analytics_settings' ); ?>
			<label>
				<input type="checkbox" name="analytics_enabled" value="1" <?php checked( $enabled ); ?>>
				<?php esc_html_e( 'Record conversation analytics (privacy-safe; no PII stored). Untick to stop logging.', 'dukandaar-ai-shopping-assistant-for-woocommerce' ); ?>
			</label>
			<?php submit_button( esc_html__( 'Save', 'dukandaar-ai-shopping-assistant-for-woocommerce' ), 'primary', 'dukandaar_analytics_save', false ); ?>
		</form>

		<p>
			<!-- Export (download a JSON of the privacy-safe rows). -->
			<form method="post" action="<?php echo esc_url( $export_url ); ?>" style="display:inline-block;margin-right:10px;">
				<?php wp_nonce_field( 'dukandaar_analytics_export' ); ?>
				<input type="hidden" name="action" value="dukandaar_analytics_export">
				<input type="hidden" name="from" value="<?php echo esc_attr( $range['from_str'] ); ?>">
				<input type="hidden" name="to" value="<?php echo esc_attr( $range['to_str'] ); ?>">
				<?php submit_button( esc_html__( 'Export (JSON)', 'dukandaar-ai-shopping-assistant-for-woocommerce' ), 'secondary', '', false ); ?>
			</form>

			<!-- Delete all stored rows (retention control). -->
			<form method="post" action="<?php echo esc_url( $export_url ); ?>" style="display:inline-block;"
				onsubmit="return confirm('<?php echo esc_js( __( 'Delete all stored analytics data? This cannot be undone.', 'dukandaar-ai-shopping-assistant-for-woocommerce' ) ); ?>');">
				<?php wp_nonce_field( 'dukandaar_analytics_delete' ); ?>
				<input type="hidden" name="action" value="dukandaar_analytics_delete">
				<?php submit_button( esc_html__( 'Delete all data', 'dukandaar-ai-shopping-assistant-for-woocommerce' ), 'delete', '', false ); ?>
			</form>
		</p>
	</div>
	<?php
}

/** Human-readable label for an outcome key (dashboard display only). */
function dukandaar_analytics_outcome_label( string $outcome ): string {
	$labels = [
		Dukandaar_Analytics::OUTCOME_ANSWERED      => __( 'Answered', 'dukandaar-ai-shopping-assistant-for-woocommerce' ),
		Dukandaar_Analytics::OUTCOME_ESCALATED     => __( 'Escalated', 'dukandaar-ai-shopping-assistant-for-woocommerce' ),
		Dukandaar_Analytics::OUTCOME_ABSTAINED     => __( 'Abstained', 'dukandaar-ai-shopping-assistant-for-woocommerce' ),
		Dukandaar_Analytics::OUTCOME_NO_TOOL_MATCH => __( 'No matching action', 'dukandaar-ai-shopping-assistant-for-woocommerce' ),
		Dukandaar_Analytics::OUTCOME_ERROR         => __( 'Error', 'dukandaar-ai-shopping-assistant-for-woocommerce' ),
	];
	return $labels[ $outcome ] ?? $outcome;
}

/** Format a stored unix timestamp using the site's date/time format. */
function dukandaar_analytics_format_time( int $ts ): string {
	if ( $ts <= 0 ) {
		return '';
	}
	if ( function_exists( 'wp_date' ) ) {
		return (string) wp_date( get_option( 'date_format', 'Y-m-d' ) . ' ' . get_option( 'time_format', 'H:i' ), $ts );
	}
	return gmdate( 'Y-m-d H:i', $ts );
}

/**
 * Best-effort chat → order attribution callback for the funnel (issue #49).
 *
 * Given the OPAQUE conversation refs whose conversations reached add-to-cart, returns
 * how many converted to a real WooCommerce order. The current build records no
 * order↔conversation link (the message endpoint carries no conversation token), so a
 * faithful, NON-fabricated answer is 0 attributable orders, we return 0 rather than
 * inventing a number. A future change that stamps the conversation ref onto the order
 * (e.g. via order meta at checkout) can implement real attribution here without
 * touching the store or the dashboard. Filterable so an integration can supply it.
 *
 * @param string[] $cart_conversation_refs Opaque refs that added to cart.
 * @return int Attributable orders.
 */
function dukandaar_attribute_orders( array $cart_conversation_refs ): int {
	/**
	 * Filter the chat-attributed order count for the analytics funnel (issue #49).
	 *
	 * @param int      $orders Default 0 (no built-in order↔conversation link yet).
	 * @param string[] $cart_conversation_refs Opaque refs that reached add-to-cart.
	 */
	return (int) apply_filters( 'dukandaar_attributed_orders', 0, $cart_conversation_refs );
}

/**
 * admin-post handler: export the analytics rows as a downloadable JSON file (issue #49).
 *
 * Capability + nonce gated. Streams a privacy-safe JSON document (the rows are already
 * masked/bounded by the store) with a download header and exits. Honours the same
 * date-range as the dashboard so an export matches what the merchant is viewing.
 */
function dukandaar_analytics_export_handler(): void {
	if ( ! current_user_can( dukandaar_settings_capability() ) ) {
		wp_die( esc_html__( 'You do not have permission to export this data.', 'dukandaar-ai-shopping-assistant-for-woocommerce' ), '', [ 'response' => 403 ] );
	}
	check_admin_referer( 'dukandaar_analytics_export' );

	$from = isset( $_POST['from'] ) ? sanitize_text_field( wp_unslash( $_POST['from'] ) ) : '';
	$to   = isset( $_POST['to'] ) ? sanitize_text_field( wp_unslash( $_POST['to'] ) ) : '';

	$window = [
		'from' => dukandaar_analytics_parse_date( $from, false ),
		'to'   => dukandaar_analytics_parse_date( $to, true ),
	];

	$rows = Dukandaar_Analytics::instance()->export( $window );

	nocache_headers();
	header( 'Content-Type: application/json; charset=utf-8' );
	header( 'Content-Disposition: attachment; filename="dukandaar-analytics-' . gmdate( 'Ymd-His' ) . '.json"' );

	echo wp_json_encode( [
		'generated' => gmdate( 'c' ),
		'count'     => count( $rows ),
		'rows'      => $rows,
	] );
	// @codeCoverageIgnoreStart
	// Reason: terminating exit after streaming JSON download headers/body; cannot be measured in-process (an exit kills the PHPUnit run, so tests halt one call earlier).
	exit;
	// @codeCoverageIgnoreEnd
}

/**
 * admin-post handler: delete ALL stored analytics rows (issue #49 retention control).
 *
 * Capability + nonce gated. Purges the store and redirects back to the dashboard with
 * a success flag.
 */
function dukandaar_analytics_delete_handler(): void {
	if ( ! current_user_can( dukandaar_settings_capability() ) ) {
		wp_die( esc_html__( 'You do not have permission to delete this data.', 'dukandaar-ai-shopping-assistant-for-woocommerce' ), '', [ 'response' => 403 ] );
	}
	check_admin_referer( 'dukandaar_analytics_delete' );

	Dukandaar_Analytics::instance()->purge();

	wp_safe_redirect( add_query_arg( 'dukandaar_purged', '1', admin_url( 'admin.php?page=dukandaar-analytics' ) ) );
	// @codeCoverageIgnoreStart
	// Reason: terminating exit after a redirect header; cannot be measured in-process (an exit kills the PHPUnit run, so tests halt one call earlier).
	exit;
	// @codeCoverageIgnoreEnd
}

function dukandaar_settings_page(): void {
	if ( ! current_user_can( dukandaar_settings_capability() ) ) {
		return;
	}

	// One-shot notice after the "Build index" action (#108) round-trips via admin-post.php.
	// Read-only display flag after a nonce-verified redirect; value is cast to int.
	$dukandaar_indexed = isset( $_GET['dukandaar_indexed'] ) ? (int) $_GET['dukandaar_indexed'] : -1; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	if ( $dukandaar_indexed >= 0 ) {
		printf(
			'<div class="notice notice-success is-dismissible"><p>%s</p></div>',
			esc_html(
				sprintf(
					/* translators: %d: number of products queued for embedding */
					__( 'Search index build queued for %d products. It runs in the background.', 'dukandaar-ai-shopping-assistant-for-woocommerce' ),
					$dukandaar_indexed
				)
			)
		);
	}

	if ( isset( $_POST['dukandaar_save'] ) && check_admin_referer( 'dukandaar_settings' ) ) {
		// Selected provider: only a known catalog id is accepted (an unknown value
		// falls back to anthropic), so a tampered select can't set a bogus provider.
		update_option( 'dukandaar_provider', dukandaar_sanitize_provider( sanitize_text_field( wp_unslash( $_POST['provider'] ?? 'anthropic' ) ) ) );

		// Per-provider API key + model, driven by the catalog (issue: multi-provider).
		// Each provider's form fields are {id}_api_key / {id}_model and persist to its
		// declared option names. anthropic/moonshot keep their existing option names
		// (backward compat) because those ids already follow the convention. The model
		// defaults to the preset default when the field is blank.
		foreach ( Dukandaar_Providers::catalog() as $provider_id => $preset ) {
			update_option(
				$preset['key_option'],
				sanitize_text_field( wp_unslash( $_POST[ $provider_id . '_api_key' ] ?? '' ) )
			);

			$model = sanitize_text_field( wp_unslash( $_POST[ $provider_id . '_model' ] ?? '' ) );
			update_option( $preset['model_option'], '' !== $model ? $model : (string) $preset['default_model'] );
		}

		// Moonshot region (global vs. china, separate platforms/keys/catalogues).
		update_option( 'dukandaar_moonshot_region', 'china' === sanitize_text_field( wp_unslash( $_POST['moonshot_region'] ?? 'global' ) ) ? 'china' : 'global' );

		// Custom provider base URL, validated to https (or a localhost http) and
		// otherwise dropped to '' (Dukandaar_Providers::sanitize_base_url). Never trusted
		// as a raw string: it becomes part of the outbound request target.
		update_option( 'dukandaar_custom_base_url', Dukandaar_Providers::sanitize_base_url( sanitize_text_field( wp_unslash( $_POST['custom_base_url'] ?? '' ) ) ) );
		update_option( 'dukandaar_bot_name',          sanitize_text_field( wp_unslash( $_POST['bot_name']          ?? 'Store Assistant' ) ) );
		update_option( 'dukandaar_greeting',          sanitize_textarea_field( wp_unslash( $_POST['greeting']      ?? 'Hi! How can I help you today?' ) ) );
		update_option( 'dukandaar_system_prompt',     sanitize_textarea_field( wp_unslash( $_POST['system_prompt'] ?? '' ) ) );
		update_option( 'dukandaar_accent_color',      sanitize_hex_color( wp_unslash( $_POST['accent_color']       ?? '#2563eb' ) ) );

		// Merchant scope / tone / business-rules config (issue #56).
		update_option( 'dukandaar_tone',           dukandaar_sanitize_tone( sanitize_text_field( wp_unslash( $_POST['tone'] ?? '' ) ) ) );
		update_option( 'dukandaar_off_limits',     sanitize_textarea_field( wp_unslash( $_POST['off_limits']      ?? '' ) ) );
		update_option( 'dukandaar_promo_emphasis', sanitize_textarea_field( wp_unslash( $_POST['promo_emphasis']  ?? '' ) ) );
		update_option( 'dukandaar_disabled_tools', dukandaar_sanitize_tool_list( array_map( 'sanitize_text_field', (array) wp_unslash( $_POST['disabled_tools'] ?? [] ) ) ) );

		// Multilingual: default/allowed languages (issue #61). Default 'auto' = detect and
		// match the shopper's language across the supported set (English / Urdu / Roman
		// Urdu); a specific list (e.g. "English, Urdu") pins the preferred set.
		update_option( 'dukandaar_languages', dukandaar_sanitize_languages( sanitize_text_field( wp_unslash( $_POST['languages'] ?? 'auto' ) ) ) );

		// Cost / model knobs (issue #23, surfaced for #56).
		update_option( 'dukandaar_token_budget',        absint( $_POST['token_budget'] ?? 0 ) );
		update_option( 'dukandaar_fast_model_routing',  empty( $_POST['fast_model_routing'] ) ? 0 : 1 );
		update_option( 'dukandaar_fast_model',          sanitize_text_field( wp_unslash( $_POST['fast_model'] ?? '' ) ) );

		// Proactive, value-gated nudge (issue #65). Default OFF (opt-in); the frequency
		// cap is floored at 0 (0 = effectively off, never nudge).
		update_option( 'dukandaar_proactive_enabled',   empty( $_POST['proactive_enabled'] ) ? 0 : 1 );
		update_option( 'dukandaar_proactive_frequency', absint( $_POST['proactive_frequency'] ?? Dukandaar_Proactive::DEFAULT_FREQUENCY ) );

		// Voice input/output (issue #64). Both default OFF (opt-in): the master switch
		// gates whether the widget builds the mic/speaker controls at all, and the TTS
		// sub-toggle controls whether replies are spoken aloud.
		update_option( 'dukandaar_voice_enabled', empty( $_POST['voice_enabled'] ) ? 0 : 1 );
		update_option( 'dukandaar_voice_tts',     empty( $_POST['voice_tts'] ) ? 0 : 1 );

		// WhatsApp omnichannel channel (issue #62). Default OFF (opt-in). The verify token
		// and app secret are SECRETS used only server-side (the webhook handshake + the
		// inbound HMAC), sanitized as plain text, never localized to the client or fed to
		// the model. Going live also needs a provider for the outbound send seam + Meta
		// access tokens (held by that provider), which are intentionally out of core.
		update_option( 'dukandaar_whatsapp_enabled',      empty( $_POST['whatsapp_enabled'] ) ? 0 : 1 );
		update_option( 'dukandaar_whatsapp_verify_token', sanitize_text_field( wp_unslash( $_POST['whatsapp_verify_token'] ?? '' ) ) );
		update_option( 'dukandaar_whatsapp_app_secret',   sanitize_text_field( wp_unslash( $_POST['whatsapp_app_secret'] ?? '' ) ) );

		// Semantic search settings (#108). Sanitization lives in the admin class.
		Dukandaar_Embeddings_Admin::save( wp_unslash( $_POST ) );

		echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Settings saved.', 'dukandaar-ai-shopping-assistant-for-woocommerce' ) . '</p></div>';
	}

	$provider_catalog = Dukandaar_Providers::catalog(); // multi-provider: drives the select + fields
	$provider        = get_option( 'dukandaar_provider',          'anthropic' );
	$anthropic_key   = get_option( 'dukandaar_anthropic_api_key', '' );
	$anthropic_model = get_option( 'dukandaar_anthropic_model',   'claude-haiku-4-5-20251001' );
	$moonshot_key    = get_option( 'dukandaar_moonshot_api_key',  '' );
	$moonshot_model  = get_option( 'dukandaar_moonshot_model',    'kimi-k2.6' );
	$moonshot_region = get_option( 'dukandaar_moonshot_region',   'global' );
	$bot_name        = get_option( 'dukandaar_bot_name',          'Store Assistant' );
	$greeting        = get_option( 'dukandaar_greeting',          'Hi! How can I help you today?' );
	$system_prompt   = get_option( 'dukandaar_system_prompt',     '' );
	$accent_color    = get_option( 'dukandaar_accent_color',      '#2563eb' );

	// Merchant config (#56) + cost knobs (#23).
	$tone               = get_option( 'dukandaar_tone',                 '' );
	$off_limits         = get_option( 'dukandaar_off_limits',           '' );
	$promo_emphasis     = get_option( 'dukandaar_promo_emphasis',       '' );
	$languages          = get_option( 'dukandaar_languages',            'auto' ); // multilingual (#61)
	$disabled_tools     = (array) get_option( 'dukandaar_disabled_tools', [] );
	$token_budget       = (int) get_option( 'dukandaar_token_budget',   0 );
	$fast_model_routing = (bool) get_option( 'dukandaar_fast_model_routing', false );
	$fast_model         = get_option( 'dukandaar_fast_model',           '' );

	// Proactive nudge (#65).
	$proactive_enabled   = (bool) get_option( 'dukandaar_proactive_enabled', 0 );
	$proactive_frequency = max( 0, (int) get_option( 'dukandaar_proactive_frequency', Dukandaar_Proactive::DEFAULT_FREQUENCY ) );

		// Voice input/output (#64). Both default OFF (opt-in).
		$voice_enabled = (bool) get_option( 'dukandaar_voice_enabled', 0 );
		$voice_tts     = (bool) get_option( 'dukandaar_voice_tts', 0 );

	// WhatsApp omnichannel channel (#62). Default OFF (opt-in). The verify token + app
	// secret are server-side secrets (webhook handshake + inbound HMAC).
	$whatsapp_enabled      = (bool) get_option( 'dukandaar_whatsapp_enabled', 0 );
	$whatsapp_verify_token = (string) get_option( 'dukandaar_whatsapp_verify_token', '' );
	$whatsapp_app_secret   = (string) get_option( 'dukandaar_whatsapp_app_secret', '' );

	// The five built-in WooCommerce tools are a protected floor and are never shown as
	// disable-able. Everything else advertised to the model (packs + add-ons) can be
	// gated. Derive the gateable list from the live registry so a new pack appears
	// automatically with no edits here.
	$builtin_tools  = [ 'search_products', 'get_product_details', 'add_to_cart', 'view_cart', 'remove_from_cart' ];
	$gateable_tools = [];
	foreach ( Dukandaar_Tool_Registry::instance()->specs() as $spec ) {
		if ( ! in_array( $spec['name'], $builtin_tools, true ) ) {
			$gateable_tools[ $spec['name'] ] = $spec['description'];
		}
	}
	ksort( $gateable_tools );
	?>
	<div class="wrap">
		<h1><?php esc_html_e( 'Dukandaar AI Shopping Assistant Settings', 'dukandaar-ai-shopping-assistant-for-woocommerce' ); ?></h1>

		<form method="post">
			<?php wp_nonce_field( 'dukandaar_settings' ); ?>

			<h2 class="title"><?php esc_html_e( 'Provider', 'dukandaar-ai-shopping-assistant-for-woocommerce' ); ?></h2>
			<table class="form-table" role="presentation">

				<tr>
					<th scope="row"><label for="provider"><?php esc_html_e( 'AI Provider', 'dukandaar-ai-shopping-assistant-for-woocommerce' ); ?></label></th>
					<td>
						<select id="provider" name="provider">
							<?php foreach ( $provider_catalog as $provider_id => $preset ) : ?>
								<option value="<?php echo esc_attr( $provider_id ); ?>" <?php selected( $provider, $provider_id ); ?>><?php echo esc_html( $preset['label'] ); ?></option>
							<?php endforeach; ?>
						</select>
						<p class="description">
							<?php esc_html_e( 'Anthropic (Claude) uses its native API; every other provider uses the OpenAI-compatible API. Configure the key and model for your chosen provider below.', 'dukandaar-ai-shopping-assistant-for-woocommerce' ); ?>
						</p>
					</td>
				</tr>

				<!-- Anthropic fields -->
				<tbody id="dukandaar-anthropic" style="<?php echo 'anthropic' !== $provider ? 'display:none' : ''; ?>">
					<tr>
						<th scope="row"><label for="anthropic_api_key"><?php esc_html_e( 'Anthropic API Key', 'dukandaar-ai-shopping-assistant-for-woocommerce' ); ?></label></th>
						<td>
							<input type="password" id="anthropic_api_key" name="anthropic_api_key"
								value="<?php echo esc_attr( $anthropic_key ); ?>" class="regular-text" autocomplete="new-password">
							<p class="description">
								<?php
								printf(
									/* translators: %s: URL to Anthropic console */
									esc_html__( 'Get your key from %s.', 'dukandaar-ai-shopping-assistant-for-woocommerce' ),
									'<a href="https://platform.claude.com" target="_blank" rel="noopener">platform.claude.com</a>'
								);
								?>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="anthropic_model"><?php esc_html_e( 'Claude Model', 'dukandaar-ai-shopping-assistant-for-woocommerce' ); ?></label></th>
						<td>
							<select id="anthropic_model" name="anthropic_model">
								<option value="claude-haiku-4-5-20251001" <?php selected( $anthropic_model, 'claude-haiku-4-5-20251001' ); ?>>
									<?php esc_html_e( 'Claude Haiku, Fast & affordable (recommended)', 'dukandaar-ai-shopping-assistant-for-woocommerce' ); ?>
								</option>
								<option value="claude-sonnet-4-6" <?php selected( $anthropic_model, 'claude-sonnet-4-6' ); ?>>
									<?php esc_html_e( 'Claude Sonnet, Balanced performance', 'dukandaar-ai-shopping-assistant-for-woocommerce' ); ?>
								</option>
								<option value="claude-opus-4-6" <?php selected( $anthropic_model, 'claude-opus-4-6' ); ?>>
									<?php esc_html_e( 'Claude Opus, Most capable', 'dukandaar-ai-shopping-assistant-for-woocommerce' ); ?>
								</option>
							</select>
						</td>
					</tr>
				</tbody>

				<!-- Moonshot / Kimi fields -->
				<tbody id="dukandaar-moonshot" style="<?php echo 'moonshot' !== $provider ? 'display:none' : ''; ?>">
					<tr>
						<th scope="row"><label for="moonshot_region"><?php esc_html_e( 'Moonshot Region', 'dukandaar-ai-shopping-assistant-for-woocommerce' ); ?></label></th>
						<td>
							<select id="moonshot_region" name="moonshot_region">
								<option value="global" <?php selected( $moonshot_region, 'global' ); ?>><?php esc_html_e( 'Global, api.moonshot.ai (rest of world)', 'dukandaar-ai-shopping-assistant-for-woocommerce' ); ?></option>
								<option value="china"  <?php selected( $moonshot_region, 'china' );  ?>><?php esc_html_e( 'China, api.moonshot.cn', 'dukandaar-ai-shopping-assistant-for-woocommerce' ); ?></option>
							</select>
							<p class="description">
								<?php esc_html_e( 'Choose the platform your API key was issued on. Keys and available models are not shared between the global and China platforms.', 'dukandaar-ai-shopping-assistant-for-woocommerce' ); ?>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="moonshot_api_key"><?php esc_html_e( 'Moonshot API Key', 'dukandaar-ai-shopping-assistant-for-woocommerce' ); ?></label></th>
						<td>
							<input type="password" id="moonshot_api_key" name="moonshot_api_key"
								value="<?php echo esc_attr( $moonshot_key ); ?>" class="regular-text" autocomplete="new-password">
							<p class="description">
								<?php
								printf(
									/* translators: 1: URL to global Moonshot platform, 2: URL to China Moonshot platform */
									esc_html__( 'Get your key from %1$s (global) or %2$s (China).', 'dukandaar-ai-shopping-assistant-for-woocommerce' ),
									'<a href="https://platform.kimi.ai" target="_blank" rel="noopener">platform.kimi.ai</a>',
									'<a href="https://platform.moonshot.cn" target="_blank" rel="noopener">platform.moonshot.cn</a>'
								);
								?>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="moonshot_model"><?php esc_html_e( 'Kimi Model', 'dukandaar-ai-shopping-assistant-for-woocommerce' ); ?></label></th>
						<td>
							<select id="moonshot_model" name="moonshot_model">
								<optgroup label="<?php esc_attr_e( 'Kimi K2', 'dukandaar-ai-shopping-assistant-for-woocommerce' ); ?>">
									<option value="kimi-k2.6"              <?php selected( $moonshot_model, 'kimi-k2.6' );              ?>><?php esc_html_e( 'kimi-k2.6, Latest, general (recommended)', 'dukandaar-ai-shopping-assistant-for-woocommerce' ); ?></option>
									<option value="kimi-k2.5"              <?php selected( $moonshot_model, 'kimi-k2.5' );              ?>><?php esc_html_e( 'kimi-k2.5, General', 'dukandaar-ai-shopping-assistant-for-woocommerce' ); ?></option>
									<option value="kimi-k2-thinking-turbo" <?php selected( $moonshot_model, 'kimi-k2-thinking-turbo' ); ?>><?php esc_html_e( 'kimi-k2-thinking-turbo, Reasoning (availability varies by region)', 'dukandaar-ai-shopping-assistant-for-woocommerce' ); ?></option>
									<option value="kimi-k2-thinking"       <?php selected( $moonshot_model, 'kimi-k2-thinking' );       ?>><?php esc_html_e( 'kimi-k2-thinking, Reasoning (availability varies by region)', 'dukandaar-ai-shopping-assistant-for-woocommerce' ); ?></option>
								</optgroup>
								<optgroup label="<?php esc_attr_e( 'Moonshot V1', 'dukandaar-ai-shopping-assistant-for-woocommerce' ); ?>">
									<option value="moonshot-v1-auto"  <?php selected( $moonshot_model, 'moonshot-v1-auto' );  ?>><?php esc_html_e( 'moonshot-v1-auto, Auto context', 'dukandaar-ai-shopping-assistant-for-woocommerce' ); ?></option>
									<option value="moonshot-v1-8k"    <?php selected( $moonshot_model, 'moonshot-v1-8k' );    ?>>moonshot-v1-8k</option>
									<option value="moonshot-v1-32k"   <?php selected( $moonshot_model, 'moonshot-v1-32k' );   ?>>moonshot-v1-32k</option>
									<option value="moonshot-v1-128k"  <?php selected( $moonshot_model, 'moonshot-v1-128k' );  ?>>moonshot-v1-128k</option>
								</optgroup>
							</select>
							<p class="description">
								<?php esc_html_e( 'Available models depend on your region and key. If you get a "model not found" error, pick another model.', 'dukandaar-ai-shopping-assistant-for-woocommerce' ); ?>
							</p>
						</td>
					</tr>
				</tbody>

				<?php
				// Every OTHER provider (anthropic + moonshot are hand-rendered above for
				// their richer UI). Each gets a generic API key + model field set, driven
				// by the catalog so a filter-registered add-on provider also appears here
				// with no edits. The `custom` provider additionally gets a base URL field.
				foreach ( $provider_catalog as $provider_id => $preset ) :
					if ( in_array( $provider_id, [ 'anthropic', 'moonshot' ], true ) ) {
						continue;
					}
					$pid_key      = $provider_id . '_api_key';
					$pid_model    = $provider_id . '_model';
					$saved_key    = (string) get_option( $preset['key_option'], '' );
					$saved_model  = (string) get_option( $preset['model_option'], $preset['default_model'] );
					$is_custom    = ( 'custom' === $provider_id );
					$is_local     = ( 'ollama' === $provider_id );
					?>
					<tbody id="dukandaar-<?php echo esc_attr( $provider_id ); ?>" style="<?php echo $provider_id !== $provider ? 'display:none' : ''; ?>">
						<?php if ( $is_custom ) : ?>
							<tr>
								<th scope="row"><label for="custom_base_url"><?php esc_html_e( 'Base URL', 'dukandaar-ai-shopping-assistant-for-woocommerce' ); ?></label></th>
								<td>
									<input type="url" id="custom_base_url" name="custom_base_url"
										value="<?php echo esc_attr( (string) get_option( 'dukandaar_custom_base_url', '' ) ); ?>" class="regular-text" placeholder="https://api.example.com/v1">
									<p class="description">
										<?php esc_html_e( 'The OpenAI-compatible base URL (the prefix before /chat/completions). Must be HTTPS (a localhost address may use http). Invalid values are discarded on save.', 'dukandaar-ai-shopping-assistant-for-woocommerce' ); ?>
									</p>
								</td>
							</tr>
						<?php endif; ?>
						<tr>
							<th scope="row">
								<label for="<?php echo esc_attr( $pid_key ); ?>">
									<?php
									/* translators: %s: provider label, e.g. "OpenAI" */
									printf( esc_html__( '%s API Key', 'dukandaar-ai-shopping-assistant-for-woocommerce' ), esc_html( $preset['label'] ) );
									?>
								</label>
							</th>
							<td>
								<input type="password" id="<?php echo esc_attr( $pid_key ); ?>" name="<?php echo esc_attr( $pid_key ); ?>"
									value="<?php echo esc_attr( $saved_key ); ?>" class="regular-text" autocomplete="new-password">
								<?php if ( $is_local ) : ?>
									<p class="description"><?php esc_html_e( 'A local Ollama server usually needs no key, leave blank unless your setup requires one.', 'dukandaar-ai-shopping-assistant-for-woocommerce' ); ?></p>
								<?php endif; ?>
							</td>
						</tr>
						<tr>
							<th scope="row">
								<label for="<?php echo esc_attr( $pid_model ); ?>"><?php esc_html_e( 'Model', 'dukandaar-ai-shopping-assistant-for-woocommerce' ); ?></label>
							</th>
							<td>
								<?php if ( ! empty( $preset['models'] ) ) : ?>
									<input type="text" id="<?php echo esc_attr( $pid_model ); ?>" name="<?php echo esc_attr( $pid_model ); ?>"
										value="<?php echo esc_attr( $saved_model ); ?>" class="regular-text" list="dukandaar-<?php echo esc_attr( $provider_id ); ?>-models">
									<datalist id="dukandaar-<?php echo esc_attr( $provider_id ); ?>-models">
										<?php foreach ( $preset['models'] as $model_option ) : ?>
											<option value="<?php echo esc_attr( $model_option ); ?>"></option>
										<?php endforeach; ?>
									</datalist>
								<?php else : ?>
									<input type="text" id="<?php echo esc_attr( $pid_model ); ?>" name="<?php echo esc_attr( $pid_model ); ?>"
										value="<?php echo esc_attr( $saved_model ); ?>" class="regular-text">
								<?php endif; ?>
								<p class="description">
									<?php
									/* translators: %s: the provider's default model id */
									printf( esc_html__( 'Defaults to %s when left blank.', 'dukandaar-ai-shopping-assistant-for-woocommerce' ), '<code>' . esc_html( (string) $preset['default_model'] ) . '</code>' );
									?>
								</p>
							</td>
						</tr>
					</tbody>
				<?php endforeach; ?>

			</table>

			<h2 class="title"><?php esc_html_e( 'Widget', 'dukandaar-ai-shopping-assistant-for-woocommerce' ); ?></h2>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="bot_name"><?php esc_html_e( 'Bot Name', 'dukandaar-ai-shopping-assistant-for-woocommerce' ); ?></label></th>
					<td>
						<input type="text" id="bot_name" name="bot_name"
							value="<?php echo esc_attr( $bot_name ); ?>" class="regular-text">
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="greeting"><?php esc_html_e( 'Greeting Message', 'dukandaar-ai-shopping-assistant-for-woocommerce' ); ?></label></th>
					<td>
						<textarea id="greeting" name="greeting" class="large-text" rows="2"><?php echo esc_textarea( $greeting ); ?></textarea>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="accent_color"><?php esc_html_e( 'Accent Color', 'dukandaar-ai-shopping-assistant-for-woocommerce' ); ?></label></th>
					<td>
						<input type="color" id="accent_color" name="accent_color"
							value="<?php echo esc_attr( $accent_color ); ?>">
					</td>
				</tr>
			</table>

			<h2 class="title">
				<?php esc_html_e( 'System Prompt', 'dukandaar-ai-shopping-assistant-for-woocommerce' ); ?>
				<span style="font-weight:400;font-size:13px;"><?php esc_html_e( '(optional)', 'dukandaar-ai-shopping-assistant-for-woocommerce' ); ?></span>
			</h2>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="system_prompt"><?php esc_html_e( 'Custom Prompt', 'dukandaar-ai-shopping-assistant-for-woocommerce' ); ?></label></th>
					<td>
						<textarea id="system_prompt" name="system_prompt" class="large-text" rows="7"><?php echo esc_textarea( $system_prompt ); ?></textarea>
						<p class="description">
							<?php esc_html_e( 'Leave blank to use the default prompt. Add store policies, shipping info, FAQs, or tone guidelines here.', 'dukandaar-ai-shopping-assistant-for-woocommerce' ); ?>
						</p>
					</td>
				</tr>
			</table>

			<h2 class="title"><?php esc_html_e( 'Assistant Behaviour', 'dukandaar-ai-shopping-assistant-for-woocommerce' ); ?></h2>
			<p class="description" style="max-width:50em;">
				<?php esc_html_e( 'Tune the assistant\'s tone and scope. These preferences guide the assistant, but the built-in trust safeguards (no fake urgency, respect the customer\'s budget, honest about extras, no invented facts, always allow human support) always apply and cannot be turned off.', 'dukandaar-ai-shopping-assistant-for-woocommerce' ); ?>
			</p>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="tone"><?php esc_html_e( 'Tone / Persona', 'dukandaar-ai-shopping-assistant-for-woocommerce' ); ?></label></th>
					<td>
						<select id="tone" name="tone">
							<option value="" <?php selected( $tone, '' ); ?>><?php esc_html_e( 'Default (friendly, no specific persona)', 'dukandaar-ai-shopping-assistant-for-woocommerce' ); ?></option>
							<option value="friendly"     <?php selected( $tone, 'friendly' );     ?>><?php esc_html_e( 'Friendly &amp; approachable', 'dukandaar-ai-shopping-assistant-for-woocommerce' ); ?></option>
							<option value="professional" <?php selected( $tone, 'professional' ); ?>><?php esc_html_e( 'Professional &amp; precise', 'dukandaar-ai-shopping-assistant-for-woocommerce' ); ?></option>
							<option value="concise"      <?php selected( $tone, 'concise' );      ?>><?php esc_html_e( 'Concise &amp; to the point', 'dukandaar-ai-shopping-assistant-for-woocommerce' ); ?></option>
							<option value="playful"      <?php selected( $tone, 'playful' );      ?>><?php esc_html_e( 'Playful &amp; upbeat', 'dukandaar-ai-shopping-assistant-for-woocommerce' ); ?></option>
							<option value="luxury"       <?php selected( $tone, 'luxury' );       ?>><?php esc_html_e( 'Premium / concierge', 'dukandaar-ai-shopping-assistant-for-woocommerce' ); ?></option>
						</select>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="off_limits"><?php esc_html_e( 'Off-limits Topics', 'dukandaar-ai-shopping-assistant-for-woocommerce' ); ?></label></th>
					<td>
						<textarea id="off_limits" name="off_limits" class="large-text" rows="2"><?php echo esc_textarea( $off_limits ); ?></textarea>
						<p class="description">
							<?php esc_html_e( 'Topics the assistant should politely decline and steer back to shopping (e.g. medical advice, competitor pricing, politics). Comma-separated or free text.', 'dukandaar-ai-shopping-assistant-for-woocommerce' ); ?>
						</p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="promo_emphasis"><?php esc_html_e( 'Promotion Emphasis', 'dukandaar-ai-shopping-assistant-for-woocommerce' ); ?></label></th>
					<td>
						<textarea id="promo_emphasis" name="promo_emphasis" class="large-text" rows="3"><?php echo esc_textarea( $promo_emphasis ); ?></textarea>
						<p class="description">
							<?php esc_html_e( 'Optional per-category emphasis, e.g. "Footwear: highlight the winter clearance." The assistant will only mention these when genuinely relevant and never as pressure.', 'dukandaar-ai-shopping-assistant-for-woocommerce' ); ?>
						</p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="languages"><?php esc_html_e( 'Languages', 'dukandaar-ai-shopping-assistant-for-woocommerce' ); ?></label></th>
					<td>
						<input type="text" id="languages" name="languages"
							value="<?php echo esc_attr( $languages ); ?>" class="regular-text"
							placeholder="auto">
						<p class="description">
							<?php esc_html_e( 'Languages the assistant should reply in. Use "auto" to detect each shopper\'s language and match it (English, Urdu, or Roman Urdu). Or list a preferred set, e.g. "English, Urdu". Product facts and prices stay grounded in the store data and are never translated; reply quality depends on the AI model you use.', 'dukandaar-ai-shopping-assistant-for-woocommerce' ); ?>
						</p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Available Actions', 'dukandaar-ai-shopping-assistant-for-woocommerce' ); ?></th>
					<td>
						<?php if ( empty( $gateable_tools ) ) : ?>
							<p class="description"><?php esc_html_e( 'No optional actions are installed. The core product search and cart actions are always available.', 'dukandaar-ai-shopping-assistant-for-woocommerce' ); ?></p>
						<?php else : ?>
							<fieldset>
								<legend class="screen-reader-text"><?php esc_html_e( 'Disable assistant actions', 'dukandaar-ai-shopping-assistant-for-woocommerce' ); ?></legend>
								<p class="description" style="margin-bottom:8px;">
									<?php esc_html_e( 'Untick an action to stop the assistant from using it. The core product search and cart actions cannot be disabled.', 'dukandaar-ai-shopping-assistant-for-woocommerce' ); ?>
								</p>
								<?php foreach ( $gateable_tools as $tool_name => $tool_desc ) : ?>
									<label style="display:block;margin-bottom:6px;">
										<input type="checkbox" name="disabled_tools[]" value="<?php echo esc_attr( $tool_name ); ?>"
											<?php checked( in_array( $tool_name, $disabled_tools, true ) ); ?>>
										<code><?php echo esc_html( $tool_name ); ?></code>
										<span class="description">, <?php echo esc_html( $tool_desc ); ?></span>
									</label>
								<?php endforeach; ?>
								<p class="description" style="margin-top:8px;">
									<?php esc_html_e( 'Note: ticked actions are DISABLED.', 'dukandaar-ai-shopping-assistant-for-woocommerce' ); ?>
								</p>
							</fieldset>
						<?php endif; ?>
					</td>
				</tr>
			</table>

			<h2 class="title"><?php esc_html_e( 'Cost &amp; Performance', 'dukandaar-ai-shopping-assistant-for-woocommerce' ); ?></h2>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="token_budget"><?php esc_html_e( 'Conversation Token Budget', 'dukandaar-ai-shopping-assistant-for-woocommerce' ); ?></label></th>
					<td>
						<input type="number" id="token_budget" name="token_budget" min="0" step="500"
							value="<?php echo esc_attr( (string) $token_budget ); ?>" class="small-text">
						<p class="description">
							<?php esc_html_e( 'Approximate cap on the context sent to the model per turn (older history is trimmed first; the current turn is always kept). 0 = unlimited.', 'dukandaar-ai-shopping-assistant-for-woocommerce' ); ?>
						</p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Fast-model Routing', 'dukandaar-ai-shopping-assistant-for-woocommerce' ); ?></th>
					<td>
						<label>
							<input type="checkbox" name="fast_model_routing" value="1" <?php checked( $fast_model_routing ); ?>>
							<?php esc_html_e( 'Route simple turns (e.g. greetings, with no tool use) to a cheaper, faster model.', 'dukandaar-ai-shopping-assistant-for-woocommerce' ); ?>
						</label>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="fast_model"><?php esc_html_e( 'Fast Model', 'dukandaar-ai-shopping-assistant-for-woocommerce' ); ?></label></th>
					<td>
						<input type="text" id="fast_model" name="fast_model"
							value="<?php echo esc_attr( $fast_model ); ?>" class="regular-text"
							placeholder="claude-haiku-4-5-20251001">
						<p class="description">
							<?php esc_html_e( 'Model id to use for simple turns when fast-model routing is enabled (e.g. claude-haiku-4-5-20251001 or kimi-k2.6). Leave blank to keep the configured model.', 'dukandaar-ai-shopping-assistant-for-woocommerce' ); ?>
						</p>
					</td>
				</tr>
			</table>

			<h2 class="title"><?php esc_html_e( 'Proactive Assist', 'dukandaar-ai-shopping-assistant-for-woocommerce' ); ?></h2>
			<p class="description" style="max-width:50em;">
				<?php esc_html_e( 'When enabled, the assistant may show a single, dismissible message offering genuine help, but ONLY when there is real value to surface (a discount code that actually applies, or store credit the shopper has not used). It never invents urgency or scarcity, is capped per visit, and stops the moment a shopper dismisses it. Leave it off if you would rather the assistant only ever speaks when spoken to.', 'dukandaar-ai-shopping-assistant-for-woocommerce' ); ?>
			</p>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><?php esc_html_e( 'Proactive Nudges', 'dukandaar-ai-shopping-assistant-for-woocommerce' ); ?></th>
					<td>
						<label>
							<input type="checkbox" name="proactive_enabled" value="1" <?php checked( $proactive_enabled ); ?>>
							<?php esc_html_e( 'Let the assistant proactively offer a real, applicable deal or unused store credit (off by default).', 'dukandaar-ai-shopping-assistant-for-woocommerce' ); ?>
						</label>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="proactive_frequency"><?php esc_html_e( 'Max Nudges Per Visit', 'dukandaar-ai-shopping-assistant-for-woocommerce' ); ?></label></th>
					<td>
						<input type="number" id="proactive_frequency" name="proactive_frequency" min="0" max="5" step="1"
							value="<?php echo esc_attr( (string) $proactive_frequency ); ?>" class="small-text">
						<p class="description">
							<?php esc_html_e( 'How many times, at most, a proactive message may appear in a single visit. 1 (once per session) is recommended; 0 turns proactive messages off.', 'dukandaar-ai-shopping-assistant-for-woocommerce' ); ?>
						</p>
					</td>
				</tr>
			</table>

			<h2 class="title"><?php esc_html_e( 'Voice', 'dukandaar-ai-shopping-assistant-for-woocommerce' ); ?></h2>
			<p class="description" style="max-width:50em;">
				<?php esc_html_e( 'Let shoppers talk to the assistant. When enabled, a microphone button appears in the chat so a shopper can speak a question (it is transcribed into the message box using their browser\'s built-in speech recognition), and you can optionally have the assistant read its replies aloud. This uses the browser\'s own Web Speech API, so no audio is recorded or sent to any external service, the microphone permission is always requested by the browser, and typing always works. The controls are hidden automatically in browsers that do not support speech.', 'dukandaar-ai-shopping-assistant-for-woocommerce' ); ?>
			</p>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><?php esc_html_e( 'Voice Input', 'dukandaar-ai-shopping-assistant-for-woocommerce' ); ?></th>
					<td>
						<label>
							<input type="checkbox" name="voice_enabled" value="1" <?php checked( $voice_enabled ); ?>>
							<?php esc_html_e( 'Show a microphone button so shoppers can speak their message (off by default).', 'dukandaar-ai-shopping-assistant-for-woocommerce' ); ?>
						</label>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Spoken Replies', 'dukandaar-ai-shopping-assistant-for-woocommerce' ); ?></th>
					<td>
						<label>
							<input type="checkbox" name="voice_tts" value="1" <?php checked( $voice_tts ); ?>>
							<?php esc_html_e( 'Also let the assistant read its replies aloud, with a speaker button to toggle it (requires Voice Input; off by default).', 'dukandaar-ai-shopping-assistant-for-woocommerce' ); ?>
						</label>
					</td>
				</tr>
			</table>

			<h2 class="title"><?php esc_html_e( 'WhatsApp (beta)', 'dukandaar-ai-shopping-assistant-for-woocommerce' ); ?></h2>
			<p class="description" style="max-width:50em;">
				<?php esc_html_e( 'Let shoppers reach the assistant over WhatsApp. This is the webhook + verification scaffolding only: going live needs a WhatsApp Business (Meta Cloud API) account, and an outbound message provider to actually send replies. Configure the webhook below in your Meta app, using this callback URL and verify token. Personal account data stays available only to verified, logged-in customers, a WhatsApp number is treated as a guest.', 'dukandaar-ai-shopping-assistant-for-woocommerce' ); ?>
			</p>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><?php esc_html_e( 'Enable WhatsApp', 'dukandaar-ai-shopping-assistant-for-woocommerce' ); ?></th>
					<td>
						<label>
							<input type="checkbox" name="whatsapp_enabled" value="1" <?php checked( $whatsapp_enabled ); ?>>
							<?php esc_html_e( 'Process inbound WhatsApp messages through the assistant (off by default).', 'dukandaar-ai-shopping-assistant-for-woocommerce' ); ?>
						</label>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Callback URL', 'dukandaar-ai-shopping-assistant-for-woocommerce' ); ?></th>
					<td>
						<input type="text" class="regular-text code" value="<?php echo esc_attr( rest_url( 'dukandaar/v1/whatsapp' ) ); ?>" readonly onclick="this.select();">
						<p class="description"><?php esc_html_e( 'Enter this as the Callback URL when configuring the WhatsApp webhook in your Meta app.', 'dukandaar-ai-shopping-assistant-for-woocommerce' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="dukandaar_whatsapp_verify_token"><?php esc_html_e( 'Verify Token', 'dukandaar-ai-shopping-assistant-for-woocommerce' ); ?></label></th>
					<td>
						<input type="text" id="dukandaar_whatsapp_verify_token" name="whatsapp_verify_token" value="<?php echo esc_attr( $whatsapp_verify_token ); ?>" class="regular-text" autocomplete="off">
						<p class="description"><?php esc_html_e( 'A secret string you choose; enter the same value as the Verify Token in the Meta webhook setup.', 'dukandaar-ai-shopping-assistant-for-woocommerce' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="dukandaar_whatsapp_app_secret"><?php esc_html_e( 'App Secret', 'dukandaar-ai-shopping-assistant-for-woocommerce' ); ?></label></th>
					<td>
						<input type="password" id="dukandaar_whatsapp_app_secret" name="whatsapp_app_secret" value="<?php echo esc_attr( $whatsapp_app_secret ); ?>" class="regular-text" autocomplete="off">
						<p class="description"><?php esc_html_e( 'Your Meta app secret. Used to verify the signature on each inbound message; never shared with the assistant or shown to shoppers.', 'dukandaar-ai-shopping-assistant-for-woocommerce' ); ?></p>
					</td>
				</tr>
			</table>

			<?php Dukandaar_Embeddings_Admin::render_settings(); ?>

			<?php submit_button( esc_html__( 'Save Settings', 'dukandaar-ai-shopping-assistant-for-woocommerce' ), 'primary', 'dukandaar_save' ); ?>
		</form>
	</div>
	<?php
}
