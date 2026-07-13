<?php
defined( 'ABSPATH' ) || exit;

/**
 * Capability required to view / save the assistant settings.
 *
 * `manage_woocommerce` (shop managers + admins) is the natural fit for a WooCommerce
 * extension; falls back to `manage_options` on the rare site where the WooCommerce
 * capability is not granted. Used by both the page guard and the admin menu.
 */
function fahad_ai_settings_capability(): string {
	return current_user_can( 'manage_woocommerce' ) ? 'manage_woocommerce' : 'manage_options';
}

/**
 * True when the AI provider selected in settings has an API key stored.
 *
 * Drives the activation nudge (issue #190): with no key the widget cannot answer, so
 * the store owner should be prompted to finish setup.
 */
function fahad_ai_is_provider_configured(): bool {
	$provider = (string) get_option( 'fahad_ai_provider', 'anthropic' );
	$key      = trim( (string) get_option( 'fahad_ai_' . $provider . '_api_key', '' ) );
	return '' !== $key;
}

/**
 * The per-visitor request limit within the rate-limit window (issue #249). Base is the
 * owner-set fahad_ai_rate_limit option (default 20 requests); the fahad_ai_rate_limit filter
 * still overrides it for code-level users. Floored at 1 so a misconfigured 0 cannot switch off
 * abuse/cost protection. Consumed by the widget's rate-limit check.
 */
function fahad_ai_rate_limit_value(): int {
	$configured = (int) get_option( 'fahad_ai_rate_limit', 20 );
	return max( 1, (int) apply_filters( 'fahad_ai_rate_limit', $configured ) );
}

/**
 * Whether the assistant is switched on (issue #231). Default ON; a soft pause an owner can
 * toggle for maintenance, a cost scare, or while changing settings, without deactivating the
 * plugin (which would lose no settings but is a heavier, all-or-nothing action). When off,
 * the widget is not rendered and billable chat requests are refused, so no AI calls are made.
 */
function fahad_ai_widget_enabled(): bool {
	return (bool) get_option( 'fahad_ai_enabled', '1' );
}

/**
 * Whether to hide the assistant on the cart and checkout pages (issue #241). Default OFF, so
 * behaviour is unchanged unless the owner opts in. The assistant is a browsing/discovery aid;
 * some stores prefer a distraction-free checkout, so this keeps it on the storefront while
 * removing it from the cart/checkout flow. The page check itself lives in render_widget.
 */
function fahad_ai_hide_on_checkout_enabled(): bool {
	return (bool) get_option( 'fahad_ai_hide_on_checkout', '' );
}

/**
 * Render the WordPress dashboard widget (issue #245): the assistant's headline numbers on the
 * screen owners see on every login, last 7 days of conversations / chat-to-cart / resolution
 * plus this calendar month's AI spend, with a link to the full analytics. Reuses the existing
 * analytics aggregates; renders zeros cleanly on a fresh install.
 */
function fahad_ai_dashboard_widget(): void {
	$analytics  = Fahad_AI_Analytics::instance();
	$week       = [ 'from' => time() - 7 * DAY_IN_SECONDS ];
	$funnel     = $analytics->funnel( $week );
	$resolution = $analytics->resolution_rate( $week );
	$mtd        = $analytics->cost_summary( [ 'from' => (int) strtotime( gmdate( 'Y-m-01 00:00:00' ) ) ] );

	$symbol   = function_exists( 'get_woocommerce_currency_symbol' ) ? get_woocommerce_currency_symbol() : '';
	$settings = admin_url( 'options-general.php?page=fahad-ai-shopping-assistant-for-woocommerce' );

	$rows = [
		esc_html__( 'Conversations (7 days)', 'fahad-ai-shopping-assistant-for-woocommerce' ) => (string) (int) $funnel['conversations'],
		esc_html__( 'Chat-to-cart rate', 'fahad-ai-shopping-assistant-for-woocommerce' )     => round( (float) $funnel['cart_rate'] * 100 ) . '%',
		esc_html__( 'Resolution rate', 'fahad-ai-shopping-assistant-for-woocommerce' )       => round( (float) $resolution * 100 ) . '%',
		esc_html__( 'This month\'s AI spend', 'fahad-ai-shopping-assistant-for-woocommerce' ) => $symbol . number_format( (float) $mtd['total_cost'], 2 ),
	];

	echo '<table class="widefat striped" style="border:none">';
	foreach ( $rows as $label => $value ) {
		echo '<tr><td>' . esc_html( $label ) . '</td><td style="text-align:right"><strong>' . esc_html( $value ) . '</strong></td></tr>';
	}
	echo '</table>';

	// Setup-completion nudge (#245 + #247, issue #251): show progress on the screen owners
	// see every login, so the high-value setup actually gets finished. Branch-free.
	$checklist = fahad_ai_setup_checklist();
	$done      = count( array_filter( $checklist, static fn( $item ) => ! empty( $item['done'] ) ) );
	printf(
		'<p style="margin:10px 0 0">%s <a href="%s">%s</a></p>',
		esc_html(
			sprintf(
				/* translators: 1: completed setup steps, 2: total setup steps. */
				esc_html__( 'Setup: %1$d of %2$d steps complete.', 'fahad-ai-shopping-assistant-for-woocommerce' ),
				$done,
				count( $checklist )
			)
		),
		esc_url( $settings ),
		esc_html__( 'Finish setup', 'fahad-ai-shopping-assistant-for-woocommerce' )
	);
	printf(
		'<p style="margin:6px 0 0"><a href="%s">%s</a></p>',
		esc_url( $settings ),
		esc_html__( 'View full analytics and settings', 'fahad-ai-shopping-assistant-for-woocommerce' )
	);
}

/**
 * The key setup steps and whether each is done (issue #247). Most of the assistant's value
 * depends on these being filled in, so the settings page shows this as an at-a-glance
 * "what is left to do" list. Pure over the stored options; each item carries a plain-text
 * marker ('done' or 'to do') so the render stays branch-free.
 *
 * @return array<int, array{label: string, done: bool, mark: string}>
 */
function fahad_ai_setup_checklist(): array {
	$items = [
		[ 'label' => 'Connect an AI provider', 'done' => fahad_ai_is_provider_configured() ],
		[ 'label' => 'Add Store Information / FAQ (shipping times, sizing, policies)', 'done' => '' !== trim( (string) get_option( 'fahad_ai_store_knowledge', '' ) ) ],
		[ 'label' => 'Set a support contact for handoffs', 'done' => '' !== trim( (string) get_option( 'fahad_ai_support_contact', '' ) ) ],
		[ 'label' => 'Set a free-shipping threshold to lift order value', 'done' => (float) get_option( 'fahad_ai_free_shipping_threshold', 0 ) > 0 ],
	];
	foreach ( $items as &$item ) {
		$item['mark'] = $item['done'] ? 'done' : 'to do';
	}
	unset( $item );
	return $items;
}

/**
 * The WooCommerce features this plugin declares compatibility with (issue #208). Single
 * source of truth, iterated by the before_woocommerce_init hook in the main file. HPOS
 * (`custom_order_tables`) is safe here because every order access goes through CRUD
 * (wc_get_orders, $order->get_*()), never a direct wp_posts/wp_postmeta query. Kept as a
 * list so a new feature is a one-line change with a matching test, and it can never drift
 * silently empty (which would revert the plugin to showing as "incompatible").
 *
 * @return string[]
 */
function fahad_ai_wc_compatible_features(): array {
	return [ 'custom_order_tables' ];
}

/**
 * Where the assistant's owner emails (welcome, weekly digest) should be sent (issue #253).
 * Returns the configured Notifications Email when set and valid, otherwise the WordPress admin
 * email, so the emails reach whoever actually manages the assistant rather than defaulting to a
 * developer or hosting address.
 */
function fahad_ai_notification_email(): string {
	$custom = trim( (string) get_option( 'fahad_ai_notification_email', '' ) );
	if ( '' !== $custom && is_email( $custom ) ) {
		return $custom;
	}
	return (string) get_option( 'admin_email', '' );
}

/**
 * Whether to send the one-time welcome email (issue #229): only once a provider is actually
 * configured (activation has genuinely succeeded) and only if it has not already been sent.
 * The "sent" flag is written by the caller after a successful send, so this stays pure.
 */
function fahad_ai_should_send_welcome(): bool {
	return ! get_option( 'fahad_ai_welcome_sent' ) && fahad_ai_is_provider_configured();
}

/**
 * Build the plain-text welcome email (issue #229). Confirms the assistant is live and points
 * the owner at the highest-value next steps so activation turns into real use. Pure and
 * side-effect free; the caller supplies the settings URL and does the sending.
 */
function fahad_ai_build_welcome_email( string $settings_url ): string {
	$lines   = [];
	$lines[] = 'Your Dukandar shopping assistant is live';
	$lines[] = '';
	$lines[] = 'Nice work. Your AI provider is connected, so the assistant is now answering shoppers on your store.';
	$lines[] = '';
	$lines[] = 'Try it: open your storefront and ask the chat widget a product question.';
	$lines[] = '';
	$lines[] = 'Get the most out of it:';
	$lines[] = '1. Fill in Store Information / FAQ (shipping times, sizing, returns) so it can answer more on its own.';
	$lines[] = '2. Set a Free Shipping Threshold so it can nudge shoppers toward free shipping.';
	$lines[] = '3. Add a Support Contact so it can hand off to a person when needed.';
	$lines[] = '4. Watch the analytics to see conversations, chat-to-cart, and resolution rate.';
	$lines[] = '';
	$lines[] = 'Open your settings: ' . $settings_url;

	return implode( "\n", $lines );
}

/**
 * Whether the weekly owner digest is enabled (issue #206). Default ON: the recurring
 * summary is the main way an owner keeps seeing the plugin's value, so it is opt-out.
 */
function fahad_ai_weekly_digest_enabled(): bool {
	return (bool) get_option( 'fahad_ai_weekly_digest', '1' );
}

/**
 * Gate for the weekly digest send (issue #206): only when it is enabled AND there was real
 * activity in the window. We never email an empty report, which would read as spam and
 * train the owner to ignore (or disable) the digest.
 */
function fahad_ai_should_send_weekly_digest( bool $enabled, int $conversations ): bool {
	return $enabled && $conversations > 0;
}

/**
 * Week-over-week trend note for the digest's chat-to-cart rate (issue #291). Both inputs are
 * 0..1 fractions; the note is in whole percentage points so it reads plainly. Returns an empty
 * string when last week has no basis (rate 0), so a first-ever week never shows "up from nothing".
 */
function fahad_ai_cart_rate_trend( float $current, float $previous ): string {
	if ( $previous <= 0.0 ) {
		return '';
	}

	$delta = (int) round( ( $current - $previous ) * 100 );

	if ( $delta > 0 ) {
		return 'up ' . $delta . ' points from last week';
	}
	if ( $delta < 0 ) {
		return 'down ' . abs( $delta ) . ' points from last week';
	}

	return 'level with last week';
}

/**
 * Build the plain-text body of the weekly owner digest (issue #206) from a pre-gathered
 * stats array. Pure and side-effect free so it is trivially testable; the caller supplies
 * the analytics and the currency/settings URL. Turns the numbers we already record into a
 * glanceable "what your assistant did this week" summary, and always tells the owner how
 * to turn the email off.
 *
 * @param array $stats { conversations:int, added_to_cart:int, cart_rate:float(0..1),
 *                       orders:int|null, total_cost:float, currency:string,
 *                       top_questions:array<array{question:string,count:int}>,
 *                       settings_url:string }
 */
function fahad_ai_build_weekly_digest( array $stats ): string {
	$conversations = (int) ( $stats['conversations'] ?? 0 );
	$cart          = (int) ( $stats['added_to_cart'] ?? 0 );
	$rate          = (int) round( ( (float) ( $stats['cart_rate'] ?? 0 ) ) * 100 );
	$orders        = $stats['orders'] ?? null;
	$cost          = (float) ( $stats['total_cost'] ?? 0 );
	$currency      = (string) ( $stats['currency'] ?? '' );
	$settings_url  = (string) ( $stats['settings_url'] ?? '' );

	$lines   = [];
	$lines[] = 'Your Dukandar shopping assistant, last 7 days';
	$lines[] = '';
	$lines[] = 'Conversations: ' . $conversations;

	// Chat-to-cart rate, with a week-over-week trend when a prior rate is supplied (issue #291),
	// so the owner sees direction of travel, not just a static number.
	$cart_line = 'Added to cart: ' . $cart . ' (' . $rate . '% chat-to-cart';
	if ( isset( $stats['prev_cart_rate'] ) ) {
		$trend = fahad_ai_cart_rate_trend( (float) ( $stats['cart_rate'] ?? 0 ), (float) $stats['prev_cart_rate'] );
		if ( '' !== $trend ) {
			$cart_line .= ', ' . $trend;
		}
	}
	$lines[] = $cart_line . ')';
	$lines[] = 'Resolution rate: ' . (int) round( ( (float) ( $stats['resolution_rate'] ?? 0 ) ) * 100 ) . '%';
	$lines[] = 'Chat-attributed orders: ' . ( null === $orders ? 'n/a' : (int) $orders );
	$lines[] = 'AI cost: ' . $currency . number_format( $cost, 2 );

	$top = (array) ( $stats['top_questions'] ?? [] );
	if ( ! empty( $top ) ) {
		$lines[] = '';
		$lines[] = 'Top questions shoppers asked:';
		$rank    = 1;
		foreach ( $top as $row ) {
			$lines[] = $rank . '. ' . (string) ( $row['question'] ?? '' ) . ' (' . (int) ( $row['count'] ?? 0 ) . ')';
			++$rank;
		}
	}

	// Content-gap list (issue #216): the questions the assistant could not answer. This is
	// the actionable half of the digest, the owner can add answers under Store Information
	// so next week the assistant handles them. Distinct, non-blank questions only.
	$unanswered = (array) ( $stats['unanswered'] ?? [] );
	$gaps       = [];
	foreach ( $unanswered as $row ) {
		$question = trim( (string) ( $row['question'] ?? '' ) );
		if ( '' !== $question && ! in_array( $question, $gaps, true ) ) {
			$gaps[] = $question;
		}
	}
	if ( ! empty( $gaps ) ) {
		$lines[] = '';
		$lines[] = 'Questions the assistant could not answer (add answers under Settings > Store Information so it can next time):';
		$rank    = 1;
		foreach ( $gaps as $question ) {
			$lines[] = $rank . '. ' . $question;
			++$rank;
		}
	}

	// Unmet-demand list (issue #283): the searches shoppers ran that returned no products.
	// The merchandising companion to the content gaps above, what to stock, rename, or add
	// synonyms for so next week these turn into sales. Distinct, non-blank searches only.
	$unmet         = (array) ( $stats['unmet_searches'] ?? [] );
	$unmet_queries = [];
	foreach ( $unmet as $row ) {
		$query = trim( (string) ( $row['question'] ?? '' ) );
		if ( '' !== $query && ! in_array( $query, $unmet_queries, true ) ) {
			$unmet_queries[] = $query;
		}
	}
	if ( ! empty( $unmet_queries ) ) {
		$lines[] = '';
		$lines[] = 'Searches with no results (real demand to act on: stock these, rename products so they are findable, or add synonyms):';
		$rank    = 1;
		foreach ( $unmet_queries as $query ) {
			$lines[] = $rank . '. ' . $query;
			++$rank;
		}
	}

	// Quality-gap list (issue #239): the reasons shoppers gave when they rated a reply
	// unhelpful. The feedback companion to the unanswered questions above; both point the
	// owner at concrete fixes. Distinct, non-blank reasons only.
	$down_rated = (array) ( $stats['down_rated'] ?? [] );
	$reasons    = [];
	foreach ( $down_rated as $row ) {
		$reason = trim( (string) ( $row['reason'] ?? '' ) );
		if ( '' !== $reason && ! in_array( $reason, $reasons, true ) ) {
			$reasons[] = $reason;
		}
	}
	if ( ! empty( $reasons ) ) {
		$lines[] = '';
		$lines[] = 'Replies shoppers rated unhelpful (fix these at the source, for example in Store Information):';
		$rank    = 1;
		foreach ( $reasons as $reason ) {
			$lines[] = $rank . '. ' . $reason;
			++$rank;
		}
	}

	$lines[] = '';
	$lines[] = 'Manage or turn this weekly email off in your store admin: ' . $settings_url;

	return implode( "\n", $lines );
}

/**
 * Admin activation nudge (issue #190): when the plugin is active but the selected
 * provider has no key, the assistant cannot reply, so show a dismissible notice with a
 * one-click link to the settings page. Shown only to users who can manage the assistant
 * and never on the settings page itself. The notice is state-driven, so it disappears
 * once a key is saved (no persistent dismissal to track).
 */
function fahad_ai_setup_notice(): void {
	if ( ! current_user_can( fahad_ai_settings_capability() ) ) {
		return;
	}
	if ( fahad_ai_is_provider_configured() ) {
		return;
	}
	if ( isset( $_GET['page'] ) && 'fahad-ai-shopping-assistant-for-woocommerce' === sanitize_key( wp_unslash( $_GET['page'] ) ) ) {
		return;
	}

	$url = admin_url( 'options-general.php?page=fahad-ai-shopping-assistant-for-woocommerce' );
	printf(
		'<div class="notice notice-warning is-dismissible"><p><strong>%s</strong> %s <a href="%s" class="button button-primary" style="margin-left:8px">%s</a></p></div>',
		esc_html__( 'Dukandar AI Shopping Assistant', 'fahad-ai-shopping-assistant-for-woocommerce' ),
		esc_html__( 'is active but has no AI provider set up yet, so it cannot answer customers. Add your API key to switch it on.', 'fahad-ai-shopping-assistant-for-woocommerce' ),
		esc_url( $url ),
		esc_html__( 'Set up Dukandar', 'fahad-ai-shopping-assistant-for-woocommerce' )
	);
}

/**
 * Whether to invite a WordPress.org review (issue #192). Asks only after real,
 * sustained use: a provider is configured and it has been at least two weeks since the
 * plugin first went active, and only once (a dismissal is permanent).
 */
function fahad_ai_should_request_review(): bool {
	if ( ! fahad_ai_is_provider_configured() ) {
		return false;
	}
	if ( '1' === (string) get_option( 'fahad_ai_review_dismissed', '' ) ) {
		return false;
	}
	$since = (int) get_option( 'fahad_ai_activated_at', 0 );
	if ( $since <= 0 ) {
		return false;
	}
	return ( time() - $since ) >= 14 * DAY_IN_SECONDS;
}

/**
 * Review request notice (issue #192): a gentle, dismissible ask shown to managers once
 * the plugin has proven its worth. Ratings drive the plugin's discoverability, so this
 * captures happy stores at the moment of demonstrated value without nagging new installs.
 */
function fahad_ai_review_notice(): void {
	if ( ! current_user_can( fahad_ai_settings_capability() ) ) {
		return;
	}
	if ( ! fahad_ai_should_request_review() ) {
		return;
	}
	$review  = 'https://wordpress.org/support/plugin/fahad-ai-shopping-assistant-for-woocommerce/reviews/#new-post';
	$dismiss = wp_nonce_url( add_query_arg( 'fahad_ai_dismiss_review', '1' ), 'fahad_ai_dismiss_review' );
	printf(
		'<div class="notice notice-info is-dismissible"><p>%s <a href="%s" target="_blank" rel="noopener noreferrer" class="button button-primary" style="margin-left:8px">%s</a> <a href="%s" style="margin-left:6px">%s</a></p></div>',
		esc_html__( 'Dukandar has been answering your shoppers for a while now. If it is pulling its weight, a quick review helps other stores find it.', 'fahad-ai-shopping-assistant-for-woocommerce' ),
		esc_url( $review ),
		esc_html__( 'Leave a review', 'fahad-ai-shopping-assistant-for-woocommerce' ),
		esc_url( $dismiss ),
		esc_html__( 'No thanks', 'fahad-ai-shopping-assistant-for-woocommerce' )
	);
}

/**
 * Whether month-to-date AI spend has reached the owner's monthly budget (issue #243). Pure:
 * true only when a budget is set (> 0) and spend is at or above it. The caller supplies the
 * spend so this stays trivially testable.
 */
function fahad_ai_monthly_budget_exceeded( float $budget, float $mtd_cost ): bool {
	return $budget > 0 && $mtd_cost >= $budget;
}

/**
 * Monthly-budget over-spend warning (issue #243). Owners budget in monthly dollars, so warn
 * the moment this calendar month's AI spend reaches the configured budget, before the
 * provider invoice does. Self-resets each month (the window starts at the first). Silent when
 * no budget is set or spend is under it. Reads recorded cost, so it is best-effort when
 * analytics logging is off.
 */
function fahad_ai_budget_notice(): void {
	if ( ! current_user_can( fahad_ai_settings_capability() ) ) {
		return;
	}
	$budget = (float) get_option( 'fahad_ai_monthly_budget', 0 );
	if ( $budget <= 0 ) {
		return;
	}
	$month_start = (int) strtotime( gmdate( 'Y-m-01 00:00:00' ) );
	$mtd         = Fahad_AI_Analytics::instance()->cost_summary( [ 'from' => $month_start ] );
	$spent       = (float) $mtd['total_cost'];
	if ( ! fahad_ai_monthly_budget_exceeded( $budget, $spent ) ) {
		return;
	}
	$symbol   = function_exists( 'get_woocommerce_currency_symbol' ) ? get_woocommerce_currency_symbol() : '';
	$settings = admin_url( 'options-general.php?page=fahad-ai-shopping-assistant-for-woocommerce' );
	printf(
		'<div class="notice notice-warning"><p><strong>%s</strong> %s <a href="%s" class="button" style="margin-left:8px">%s</a></p></div>',
		esc_html(
			sprintf(
				/* translators: 1: month-to-date spend, 2: monthly budget, both currency-formatted. */
				esc_html__( 'This month the assistant has spent %1$s, over your %2$s budget.', 'fahad-ai-shopping-assistant-for-woocommerce' ),
				$symbol . number_format( $spent, 2 ),
				$symbol . number_format( $budget, 2 )
			)
		),
		esc_html__( 'Raise the budget, lower the daily message limit, or pause the assistant to control spend.', 'fahad-ai-shopping-assistant-for-woocommerce' ),
		esc_url( $settings ),
		esc_html__( 'Review settings', 'fahad-ai-shopping-assistant-for-woocommerce' )
	);
}

/**
 * Provider-health warning (issue #200): when the assistant has hit a cluster of failed
 * responses in the last 24 hours, warn the owner that their AI provider is likely
 * misconfigured (wrong, expired, or credit-exhausted key). Left unnoticed this is the
 * classic silent-churn cause: the widget just stops answering and the store blames the
 * plugin. The threshold is filterable and the notice self-clears once errors subside, so
 * it needs no dismiss. Best-effort: it reads recorded telemetry, so it is silent when
 * analytics logging is off.
 */
function fahad_ai_provider_health_notice(): void {
	if ( ! current_user_can( fahad_ai_settings_capability() ) ) {
		return;
	}
	if ( ! fahad_ai_is_provider_configured() ) {
		return;
	}
	$threshold = max( 1, (int) apply_filters( 'fahad_ai_error_alert_threshold', 3 ) );
	$errors    = Fahad_AI_Analytics::instance()->error_count_since( time() - DAY_IN_SECONDS );
	if ( $errors < $threshold ) {
		return;
	}
	$settings = admin_url( 'options-general.php?page=fahad-ai-shopping-assistant-for-woocommerce' );
	printf(
		'<div class="notice notice-error"><p><strong>%s</strong> %s <a href="%s" class="button" style="margin-left:8px">%s</a></p></div>',
		esc_html(
			sprintf(
				/* translators: %d: number of failed assistant responses in the last 24 hours. */
				_n(
					'The shopping assistant failed %d response in the last 24 hours.',
					'The shopping assistant failed %d responses in the last 24 hours.',
					$errors,
					'fahad-ai-shopping-assistant-for-woocommerce'
				),
				$errors
			)
		),
		esc_html__( 'This usually means an API key problem: it may be wrong, expired, or out of credit. Please check your AI provider settings.', 'fahad-ai-shopping-assistant-for-woocommerce' ),
		esc_url( $settings ),
		esc_html__( 'Open settings', 'fahad-ai-shopping-assistant-for-woocommerce' )
	);
}

/**
 * Approaching-daily-cap warning (issue #210): when today's AI usage nears the configured
 * cap, warn the owner so they can raise it before the assistant starts pointing shoppers to
 * human support at peak time (a lost-sales risk). Self-clears at the day boundary (the
 * counter resets), so no dismissal is needed. A stronger message shows once the cap is
 * fully reached and shoppers are actively being turned away.
 */
function fahad_ai_daily_cap_notice(): void {
	if ( ! current_user_can( fahad_ai_settings_capability() ) ) {
		return;
	}
	if ( ! Fahad_AI_Auth::daily_cap_approaching() ) {
		return;
	}
	$count    = Fahad_AI_Auth::daily_count();
	$cap      = Fahad_AI_Auth::daily_cap();
	$reached  = Fahad_AI_Auth::daily_cap_reached();
	$settings = admin_url( 'options-general.php?page=fahad-ai-shopping-assistant-for-woocommerce' );

	$headline = $reached
		? sprintf(
			/* translators: 1: answers used today, 2: the configured daily cap. */
			esc_html__( 'Daily AI answer limit reached (%1$d of %2$d). Shoppers are now pointed to human support instead of the assistant until tomorrow.', 'fahad-ai-shopping-assistant-for-woocommerce' ),
			$count,
			$cap
		)
		: sprintf(
			/* translators: 1: answers used today, 2: the configured daily cap. */
			esc_html__( 'The assistant has used %1$d of its %2$d daily answers. Once the limit is reached, shoppers are pointed to human support instead of the assistant.', 'fahad-ai-shopping-assistant-for-woocommerce' ),
			$count,
			$cap
		);

	printf(
		'<div class="notice %s"><p><strong>%s</strong> <a href="%s" class="button" style="margin-left:8px">%s</a></p></div>',
		$reached ? 'notice-error' : 'notice-warning',
		esc_html( $headline ),
		esc_url( $settings ),
		esc_html__( 'Raise the limit', 'fahad-ai-shopping-assistant-for-woocommerce' )
	);
}

/**
 * Handle the "No thanks" dismissal of the review request (issue #192). Nonce-protected
 * and capability-gated; persists so the notice never returns.
 */
function fahad_ai_maybe_dismiss_review(): void {
	if ( ! isset( $_GET['fahad_ai_dismiss_review'] ) ) {
		return;
	}
	if ( ! current_user_can( fahad_ai_settings_capability() ) ) {
		return;
	}
	$nonce = isset( $_GET['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ) : '';
	if ( ! wp_verify_nonce( $nonce, 'fahad_ai_dismiss_review' ) ) {
		return;
	}
	update_option( 'fahad_ai_review_dismissed', '1' );
}

/**
 * Sanitize the merchant tone/persona setting to the fixed allowlist (issue #56).
 *
 * The tone maps to a vetted instruction line in the system prompt, so only the known
 * keys (Fahad_AI_API_Handler::TONES) are accepted; anything else, including any
 * attempt to type a free-form instruction, collapses to '' (no tone line).
 *
 * @param mixed $raw Raw POST value.
 * @return string A valid tone key, or ''.
 */
function fahad_ai_sanitize_tone( $raw ): string {
	$key = sanitize_key( is_scalar( $raw ) ? (string) $raw : '' );
	return isset( Fahad_AI_API_Handler::TONES[ $key ] ) ? $key : '';
}

/**
 * Sanitize the selected AI provider to a known catalog id (issue: multi-provider).
 *
 * The provider <select> is built from Fahad_AI_Providers::catalog(), so only an id
 * the catalog actually declares is accepted; anything else, including a tampered
 * value, collapses to the safe default 'anthropic'. This keeps routing keyed on a
 * real preset (handle_message looks the id up in the catalog).
 *
 * @param mixed $raw Raw POST value.
 * @return string A valid provider id, or 'anthropic'.
 */
function fahad_ai_sanitize_provider( $raw ): string {
	$id = sanitize_text_field( is_scalar( $raw ) ? (string) $raw : '' );
	return in_array( $id, Fahad_AI_Providers::ids(), true ) ? $id : 'anthropic';
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
function fahad_ai_sanitize_languages( $raw ): string {
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
function fahad_ai_sanitize_tool_list( $raw ): array {
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
function fahad_ai_analytics_range_from_request(): array {
	$from_str = isset( $_GET['from'] ) ? sanitize_text_field( wp_unslash( $_GET['from'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only date-range filter, no state change.
	$to_str   = isset( $_GET['to'] ) ? sanitize_text_field( wp_unslash( $_GET['to'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only date-range filter, no state change.

	$from = fahad_ai_analytics_parse_date( $from_str, false );
	$to   = fahad_ai_analytics_parse_date( $to_str, true );

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
function fahad_ai_analytics_parse_date( string $date, bool $end_of_day ): ?int {
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
 * Renders the privacy-safe aggregates from Fahad_AI_Analytics for a selectable date
 * range: top questions, the "questions we couldn't answer" list, the chat →
 * add-to-cart → order funnel, and cost per conversation. Also hosts the retention
 * controls, an opt-out toggle, an Export (download) and a Delete-all, each a
 * nonce-protected POST routed through admin-post.php. Gated by the same capability as
 * the settings page (manage_woocommerce, falling back to manage_options), re-checked
 * here as defence in depth.
 */
function fahad_ai_analytics_page(): void {
	if ( ! current_user_can( fahad_ai_settings_capability() ) ) {
		return;
	}

	$analytics = Fahad_AI_Analytics::instance();

	// Toggle the opt-out from this page (its own nonce). The aggregates below still
	// render whatever history exists even when logging is paused.
	if ( isset( $_POST['fahad_ai_analytics_save'] ) && check_admin_referer( 'fahad_ai_analytics_settings' ) ) {
		update_option( Fahad_AI_Analytics::OPTION_ENABLED, empty( $_POST['analytics_enabled'] ) ? 0 : 1 );
		echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Analytics settings saved.', 'fahad-ai-shopping-assistant-for-woocommerce' ) . '</p></div>';
	}

	// One-shot admin notices after an export/delete round-trip via admin-post.php.
	if ( isset( $_GET['fahad_ai_purged'] ) ) {
		echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Analytics data deleted.', 'fahad-ai-shopping-assistant-for-woocommerce' ) . '</p></div>';
	}

	$enabled = $analytics->enabled();
	$range   = fahad_ai_analytics_range_from_request();
	$window  = [ 'from' => $range['from'], 'to' => $range['to'] ];

	$top         = $analytics->top_questions( 20, $window );
	$unmet       = $analytics->unmet_searches( 20, $window );
	$unanswered  = $analytics->unanswered( 50, $window );
	$funnel      = $analytics->funnel( $window, 'fahad_ai_attribute_orders' );
	$cost        = $analytics->cost_summary( $window );
	$resolution  = $analytics->resolution_rate( $window );
	$feedback    = Fahad_AI_Feedback::instance();
	$fb          = $feedback->aggregates();
	$helpful     = $feedback->helpfulness_rate();
	$down_rated  = $feedback->recent_down( 20 );
	$export_url  = admin_url( 'admin-post.php' );
	$page_slug   = 'fahad-ai-analytics';
	$currency    = function_exists( 'get_woocommerce_currency_symbol' ) ? get_woocommerce_currency_symbol() : '';
	?>
	<div class="wrap">
		<h1><?php esc_html_e( 'AI Assistant Analytics', 'fahad-ai-shopping-assistant-for-woocommerce' ); ?></h1>

		<?php if ( ! $enabled ) : ?>
			<div class="notice notice-warning inline"><p>
				<?php esc_html_e( 'Analytics logging is currently turned off. New conversations are not being recorded; the figures below reflect previously stored data only.', 'fahad-ai-shopping-assistant-for-woocommerce' ); ?>
			</p></div>
		<?php endif; ?>

		<p class="description" style="max-width:55em;">
			<?php esc_html_e( 'A privacy-safe view of how the assistant is performing. Questions are stored with emails masked and trimmed, never with names, IP addresses or customer identifiers, and this data is never sent back to the AI model. Data is kept on a rolling retention window and can be exported or deleted below.', 'fahad-ai-shopping-assistant-for-woocommerce' ); ?>
		</p>

		<!-- Date-range filter (read-only → GET, no nonce). -->
		<form method="get" style="margin:16px 0;">
			<input type="hidden" name="page" value="<?php echo esc_attr( $page_slug ); ?>">
			<label for="fahad-ai-from"><?php esc_html_e( 'From', 'fahad-ai-shopping-assistant-for-woocommerce' ); ?></label>
			<input type="date" id="fahad-ai-from" name="from" value="<?php echo esc_attr( $range['from_str'] ); ?>">
			<label for="fahad-ai-to" style="margin-left:8px;"><?php esc_html_e( 'To', 'fahad-ai-shopping-assistant-for-woocommerce' ); ?></label>
			<input type="date" id="fahad-ai-to" name="to" value="<?php echo esc_attr( $range['to_str'] ); ?>">
			<?php submit_button( esc_html__( 'Apply', 'fahad-ai-shopping-assistant-for-woocommerce' ), 'secondary', '', false ); ?>
			<?php if ( '' !== $range['from_str'] || '' !== $range['to_str'] ) : ?>
				<a class="button-link" href="<?php echo esc_url( admin_url( 'admin.php?page=' . $page_slug ) ); ?>"><?php esc_html_e( 'Reset', 'fahad-ai-shopping-assistant-for-woocommerce' ); ?></a>
			<?php endif; ?>
		</form>

		<!-- Funnel + cost summary cards. -->
		<h2><?php esc_html_e( 'Conversion funnel', 'fahad-ai-shopping-assistant-for-woocommerce' ); ?></h2>
		<table class="widefat striped" style="max-width:46em;">
			<tbody>
				<tr><td><?php esc_html_e( 'Conversations', 'fahad-ai-shopping-assistant-for-woocommerce' ); ?></td><td><strong><?php echo esc_html( (string) $funnel['conversations'] ); ?></strong></td></tr>
				<tr><td><?php esc_html_e( 'Saw a product', 'fahad-ai-shopping-assistant-for-woocommerce' ); ?></td><td><strong><?php echo esc_html( (string) $funnel['product_surfaced'] ); ?></strong></td></tr>
				<tr><td><?php esc_html_e( 'Added to cart', 'fahad-ai-shopping-assistant-for-woocommerce' ); ?></td><td><strong><?php echo esc_html( (string) $funnel['added_to_cart'] ); ?></strong></td></tr>
				<tr><td><?php esc_html_e( 'Chat-to-cart rate', 'fahad-ai-shopping-assistant-for-woocommerce' ); ?></td><td><strong><?php echo esc_html( round( $funnel['cart_rate'] * 100 ) . '%' ); ?></strong></td></tr>
				<tr><td><?php esc_html_e( 'Chat-attributed orders', 'fahad-ai-shopping-assistant-for-woocommerce' ); ?></td><td><strong><?php echo null === $funnel['orders'] ? esc_html__( 'n/a', 'fahad-ai-shopping-assistant-for-woocommerce' ) : esc_html( (string) $funnel['orders'] ); ?></strong></td></tr>
				<tr><td><?php esc_html_e( 'Resolution rate', 'fahad-ai-shopping-assistant-for-woocommerce' ); ?></td><td><strong><?php echo esc_html( round( $resolution * 100 ) . '%' ); ?></strong></td></tr>
				<tr><td><?php esc_html_e( 'Shopper helpfulness', 'fahad-ai-shopping-assistant-for-woocommerce' ); ?></td><td><strong><?php echo esc_html( round( $helpful * 100 ) . '% (' . (int) $fb['up'] . ' up, ' . (int) $fb['down'] . ' down)' ); ?></strong></td></tr>
			</tbody>
		</table>

		<h2><?php esc_html_e( 'Cost', 'fahad-ai-shopping-assistant-for-woocommerce' ); ?></h2>
		<table class="widefat striped" style="max-width:46em;">
			<tbody>
				<tr><td><?php esc_html_e( 'Total cost', 'fahad-ai-shopping-assistant-for-woocommerce' ); ?></td><td><strong><?php echo esc_html( $currency . number_format( (float) $cost['total_cost'], 4 ) ); ?></strong></td></tr>
				<tr><td><?php esc_html_e( 'Cost per conversation', 'fahad-ai-shopping-assistant-for-woocommerce' ); ?></td><td><strong><?php echo esc_html( $currency . number_format( (float) $cost['cost_per_conversation'], 4 ) ); ?></strong></td></tr>
				<tr><td><?php esc_html_e( 'Total tokens', 'fahad-ai-shopping-assistant-for-woocommerce' ); ?></td><td><strong><?php echo esc_html( (string) $cost['total_tokens'] ); ?></strong></td></tr>
				<tr><td><?php esc_html_e( 'Turns', 'fahad-ai-shopping-assistant-for-woocommerce' ); ?></td><td><strong><?php echo esc_html( (string) $cost['turns'] ); ?></strong></td></tr>
			</tbody>
		</table>
		<p class="description"><?php esc_html_e( 'Cost and token figures appear when your provider returns usage data; otherwise they read as zero.', 'fahad-ai-shopping-assistant-for-woocommerce' ); ?></p>

		<!-- Top questions. -->
		<h2><?php esc_html_e( 'Top questions', 'fahad-ai-shopping-assistant-for-woocommerce' ); ?></h2>
		<?php if ( empty( $top ) ) : ?>
			<p class="description"><?php esc_html_e( 'No questions recorded for this range yet.', 'fahad-ai-shopping-assistant-for-woocommerce' ); ?></p>
		<?php else : ?>
			<table class="widefat striped" style="max-width:55em;">
				<thead><tr>
					<th><?php esc_html_e( 'Question', 'fahad-ai-shopping-assistant-for-woocommerce' ); ?></th>
					<th style="width:6em;"><?php esc_html_e( 'Count', 'fahad-ai-shopping-assistant-for-woocommerce' ); ?></th>
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

		<!-- Unmet searches: shoppers searched and got nothing back (demand signal). -->
		<h2><?php esc_html_e( 'Searches with no results', 'fahad-ai-shopping-assistant-for-woocommerce' ); ?></h2>
		<p class="description" style="max-width:55em;"><?php esc_html_e( 'What shoppers searched for but found nothing. This is real demand your catalogue did not meet, consider stocking these, renaming products so they are findable, or adding synonyms.', 'fahad-ai-shopping-assistant-for-woocommerce' ); ?></p>
		<?php if ( empty( $unmet ) ) : ?>
			<p class="description"><?php esc_html_e( 'No empty-result searches for this range, shoppers found products for everything they searched.', 'fahad-ai-shopping-assistant-for-woocommerce' ); ?></p>
		<?php else : ?>
			<table class="widefat striped" style="max-width:55em;">
				<thead><tr>
					<th><?php esc_html_e( 'Search', 'fahad-ai-shopping-assistant-for-woocommerce' ); ?></th>
					<th style="width:6em;"><?php esc_html_e( 'Count', 'fahad-ai-shopping-assistant-for-woocommerce' ); ?></th>
				</tr></thead>
				<tbody>
					<?php foreach ( $unmet as $item ) : ?>
						<tr>
							<td><?php echo esc_html( $item['question'] ); ?></td>
							<td><?php echo esc_html( (string) $item['count'] ); ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>

		<!-- The "couldn't answer" list. -->
		<h2><?php esc_html_e( 'Questions we couldn\'t answer', 'fahad-ai-shopping-assistant-for-woocommerce' ); ?></h2>
		<p class="description" style="max-width:55em;"><?php esc_html_e( 'Turns where the assistant abstained, escalated to support, or had no matching action. These are your content and coverage opportunities.', 'fahad-ai-shopping-assistant-for-woocommerce' ); ?></p>
		<?php if ( empty( $unanswered ) ) : ?>
			<p class="description"><?php esc_html_e( 'Nothing here for this range, the assistant answered everything it was asked.', 'fahad-ai-shopping-assistant-for-woocommerce' ); ?></p>
		<?php else : ?>
			<table class="widefat striped" style="max-width:55em;">
				<thead><tr>
					<th><?php esc_html_e( 'Question', 'fahad-ai-shopping-assistant-for-woocommerce' ); ?></th>
					<th style="width:10em;"><?php esc_html_e( 'Outcome', 'fahad-ai-shopping-assistant-for-woocommerce' ); ?></th>
					<th style="width:14em;"><?php esc_html_e( 'When', 'fahad-ai-shopping-assistant-for-woocommerce' ); ?></th>
				</tr></thead>
				<tbody>
					<?php foreach ( $unanswered as $item ) : ?>
						<tr>
							<td><?php echo esc_html( '' !== $item['question'] ? $item['question'] : __( '(no question text)', 'fahad-ai-shopping-assistant-for-woocommerce' ) ); ?></td>
							<td><?php echo esc_html( fahad_ai_analytics_outcome_label( $item['outcome'] ) ); ?></td>
							<td><?php echo esc_html( fahad_ai_analytics_format_time( $item['created'] ) ); ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>

		<!-- Replies shoppers rated unhelpful (#237): the actionable half of the thumbs data. -->
		<h2><?php esc_html_e( 'Replies shoppers rated unhelpful', 'fahad-ai-shopping-assistant-for-woocommerce' ); ?></h2>
		<p class="description" style="max-width:55em;"><?php esc_html_e( 'Recent replies a shopper gave a thumbs down, with the reason they left. These are your clearest quality-improvement opportunities: fix the answer at the source (for example in Store Information).', 'fahad-ai-shopping-assistant-for-woocommerce' ); ?></p>
		<?php if ( empty( $down_rated ) ) : ?>
			<p class="description"><?php esc_html_e( 'No thumbs-down feedback yet.', 'fahad-ai-shopping-assistant-for-woocommerce' ); ?></p>
		<?php else : ?>
			<table class="widefat striped" style="max-width:55em;">
				<thead><tr>
					<th><?php esc_html_e( 'Why the shopper was not helped', 'fahad-ai-shopping-assistant-for-woocommerce' ); ?></th>
					<th style="width:14em;"><?php esc_html_e( 'When', 'fahad-ai-shopping-assistant-for-woocommerce' ); ?></th>
				</tr></thead>
				<tbody>
					<?php foreach ( $down_rated as $item ) : ?>
						<tr>
							<td><?php echo esc_html( '' !== (string) ( $item['reason'] ?? '' ) ? (string) $item['reason'] : __( '(no reason given)', 'fahad-ai-shopping-assistant-for-woocommerce' ) ); ?></td>
							<td><?php echo esc_html( fahad_ai_analytics_format_time( (int) ( $item['created'] ?? 0 ) ) ); ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>

		<!-- Retention controls: opt-out, export, delete. -->
		<h2><?php esc_html_e( 'Data &amp; retention', 'fahad-ai-shopping-assistant-for-woocommerce' ); ?></h2>

		<form method="post" style="margin-bottom:18px;">
			<?php wp_nonce_field( 'fahad_ai_analytics_settings' ); ?>
			<label>
				<input type="checkbox" name="analytics_enabled" value="1" <?php checked( $enabled ); ?>>
				<?php esc_html_e( 'Record conversation analytics (privacy-safe; no PII stored). Untick to stop logging.', 'fahad-ai-shopping-assistant-for-woocommerce' ); ?>
			</label>
			<?php submit_button( esc_html__( 'Save', 'fahad-ai-shopping-assistant-for-woocommerce' ), 'primary', 'fahad_ai_analytics_save', false ); ?>
		</form>

		<p>
			<!-- Export (download a JSON of the privacy-safe rows). -->
			<form method="post" action="<?php echo esc_url( $export_url ); ?>" style="display:inline-block;margin-right:10px;">
				<?php wp_nonce_field( 'fahad_ai_analytics_export' ); ?>
				<input type="hidden" name="action" value="fahad_ai_analytics_export">
				<input type="hidden" name="from" value="<?php echo esc_attr( $range['from_str'] ); ?>">
				<input type="hidden" name="to" value="<?php echo esc_attr( $range['to_str'] ); ?>">
				<?php submit_button( esc_html__( 'Export (JSON)', 'fahad-ai-shopping-assistant-for-woocommerce' ), 'secondary', '', false ); ?>
			</form>

			<!-- Delete all stored rows (retention control). -->
			<form method="post" action="<?php echo esc_url( $export_url ); ?>" style="display:inline-block;"
				onsubmit="return confirm('<?php echo esc_js( __( 'Delete all stored analytics data? This cannot be undone.', 'fahad-ai-shopping-assistant-for-woocommerce' ) ); ?>');">
				<?php wp_nonce_field( 'fahad_ai_analytics_delete' ); ?>
				<input type="hidden" name="action" value="fahad_ai_analytics_delete">
				<?php submit_button( esc_html__( 'Delete all data', 'fahad-ai-shopping-assistant-for-woocommerce' ), 'delete', '', false ); ?>
			</form>
		</p>
	</div>
	<?php
}

/** Human-readable label for an outcome key (dashboard display only). */
function fahad_ai_analytics_outcome_label( string $outcome ): string {
	$labels = [
		Fahad_AI_Analytics::OUTCOME_ANSWERED      => __( 'Answered', 'fahad-ai-shopping-assistant-for-woocommerce' ),
		Fahad_AI_Analytics::OUTCOME_ESCALATED     => __( 'Escalated', 'fahad-ai-shopping-assistant-for-woocommerce' ),
		Fahad_AI_Analytics::OUTCOME_ABSTAINED     => __( 'Abstained', 'fahad-ai-shopping-assistant-for-woocommerce' ),
		Fahad_AI_Analytics::OUTCOME_NO_TOOL_MATCH => __( 'No matching action', 'fahad-ai-shopping-assistant-for-woocommerce' ),
		Fahad_AI_Analytics::OUTCOME_ERROR         => __( 'Error', 'fahad-ai-shopping-assistant-for-woocommerce' ),
	];
	return $labels[ $outcome ] ?? $outcome;
}

/** Format a stored unix timestamp using the site's date/time format. */
function fahad_ai_analytics_format_time( int $ts ): string {
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
function fahad_ai_attribute_orders( array $cart_conversation_refs ): int {
	/**
	 * Filter the chat-attributed order count for the analytics funnel (issue #49).
	 *
	 * @param int      $orders Default 0 (no built-in order↔conversation link yet).
	 * @param string[] $cart_conversation_refs Opaque refs that reached add-to-cart.
	 */
	return (int) apply_filters( 'fahad_ai_attributed_orders', 0, $cart_conversation_refs );
}

/**
 * admin-post handler: export the analytics rows as a downloadable JSON file (issue #49).
 *
 * Capability + nonce gated. Streams a privacy-safe JSON document (the rows are already
 * masked/bounded by the store) with a download header and exits. Honours the same
 * date-range as the dashboard so an export matches what the merchant is viewing.
 */
function fahad_ai_analytics_export_handler(): void {
	if ( ! current_user_can( fahad_ai_settings_capability() ) ) {
		wp_die( esc_html__( 'You do not have permission to export this data.', 'fahad-ai-shopping-assistant-for-woocommerce' ), '', [ 'response' => 403 ] );
	}
	check_admin_referer( 'fahad_ai_analytics_export' );

	$from = isset( $_POST['from'] ) ? sanitize_text_field( wp_unslash( $_POST['from'] ) ) : '';
	$to   = isset( $_POST['to'] ) ? sanitize_text_field( wp_unslash( $_POST['to'] ) ) : '';

	$window = [
		'from' => fahad_ai_analytics_parse_date( $from, false ),
		'to'   => fahad_ai_analytics_parse_date( $to, true ),
	];

	$rows = Fahad_AI_Analytics::instance()->export( $window );

	nocache_headers();
	header( 'Content-Type: application/json; charset=utf-8' );
	header( 'Content-Disposition: attachment; filename="fahad-ai-analytics-' . gmdate( 'Ymd-His' ) . '.json"' );

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
function fahad_ai_analytics_delete_handler(): void {
	if ( ! current_user_can( fahad_ai_settings_capability() ) ) {
		wp_die( esc_html__( 'You do not have permission to delete this data.', 'fahad-ai-shopping-assistant-for-woocommerce' ), '', [ 'response' => 403 ] );
	}
	check_admin_referer( 'fahad_ai_analytics_delete' );

	Fahad_AI_Analytics::instance()->purge();

	wp_safe_redirect( add_query_arg( 'fahad_ai_purged', '1', admin_url( 'admin.php?page=fahad-ai-analytics' ) ) );
	// @codeCoverageIgnoreStart
	// Reason: terminating exit after a redirect header; cannot be measured in-process (an exit kills the PHPUnit run, so tests halt one call earlier).
	exit;
	// @codeCoverageIgnoreEnd
}

function fahad_ai_settings_page(): void {
	if ( ! current_user_can( fahad_ai_settings_capability() ) ) {
		return;
	}

	// One-shot notice after the "Build index" action (#108) round-trips via admin-post.php.
	// Read-only display flag after a nonce-verified redirect; value is cast to int.
	$fahad_ai_indexed = isset( $_GET['fahad_ai_indexed'] ) ? (int) $_GET['fahad_ai_indexed'] : -1; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	if ( $fahad_ai_indexed >= 0 ) {
		printf(
			'<div class="notice notice-success is-dismissible"><p>%s</p></div>',
			esc_html(
				sprintf(
					/* translators: %d: number of products queued for embedding */
					__( 'Search index build queued for %d products. It runs in the background.', 'fahad-ai-shopping-assistant-for-woocommerce' ),
					$fahad_ai_indexed
				)
			)
		);
	}

	if ( isset( $_POST['fahad_ai_save'] ) && check_admin_referer( 'fahad_ai_settings' ) ) {
		// Selected provider: only a known catalog id is accepted (an unknown value
		// falls back to anthropic), so a tampered select can't set a bogus provider.
		update_option( 'fahad_ai_provider', fahad_ai_sanitize_provider( sanitize_text_field( wp_unslash( $_POST['provider'] ?? 'anthropic' ) ) ) );

		// Per-provider API key + model, driven by the catalog (issue: multi-provider).
		// Each provider's form fields are {id}_api_key / {id}_model and persist to its
		// declared option names. anthropic/moonshot keep their existing option names
		// (backward compat) because those ids already follow the convention. The model
		// defaults to the preset default when the field is blank.
		foreach ( Fahad_AI_Providers::catalog() as $provider_id => $preset ) {
			update_option(
				$preset['key_option'],
				sanitize_text_field( wp_unslash( $_POST[ $provider_id . '_api_key' ] ?? '' ) )
			);

			$model = sanitize_text_field( wp_unslash( $_POST[ $provider_id . '_model' ] ?? '' ) );
			update_option( $preset['model_option'], '' !== $model ? $model : (string) $preset['default_model'] );
		}

		// Moonshot region (global vs. china, separate platforms/keys/catalogues).
		update_option( 'fahad_ai_moonshot_region', 'china' === sanitize_text_field( wp_unslash( $_POST['moonshot_region'] ?? 'global' ) ) ? 'china' : 'global' );

		// Custom provider base URL, validated to https (or a localhost http) and
		// otherwise dropped to '' (Fahad_AI_Providers::sanitize_base_url). Never trusted
		// as a raw string: it becomes part of the outbound request target.
		update_option( 'fahad_ai_custom_base_url', Fahad_AI_Providers::sanitize_base_url( sanitize_text_field( wp_unslash( $_POST['custom_base_url'] ?? '' ) ) ) );
		update_option( 'fahad_ai_bot_name',          sanitize_text_field( wp_unslash( $_POST['bot_name']          ?? 'Store Assistant' ) ) );
		update_option( 'fahad_ai_greeting',          sanitize_textarea_field( wp_unslash( $_POST['greeting']      ?? 'Hi! How can I help you today?' ) ) );
		update_option( 'fahad_ai_system_prompt',     sanitize_textarea_field( wp_unslash( $_POST['system_prompt'] ?? '' ) ) );
		update_option( 'fahad_ai_accent_color',      sanitize_hex_color( wp_unslash( $_POST['accent_color']       ?? '#2563eb' ) ) );

		// Merchant scope / tone / business-rules config (issue #56).
		update_option( 'fahad_ai_tone',           fahad_ai_sanitize_tone( sanitize_text_field( wp_unslash( $_POST['tone'] ?? '' ) ) ) );
		update_option( 'fahad_ai_off_limits',     sanitize_textarea_field( wp_unslash( $_POST['off_limits']      ?? '' ) ) );
		update_option( 'fahad_ai_promo_emphasis', sanitize_textarea_field( wp_unslash( $_POST['promo_emphasis']  ?? '' ) ) );
		update_option( 'fahad_ai_free_shipping_threshold', max( 0, (float) ( $_POST['free_shipping_threshold'] ?? 0 ) ) );
		update_option( 'fahad_ai_bestseller_threshold', max( 0, (int) ( $_POST['bestseller_threshold'] ?? 0 ) ) );
		update_option( 'fahad_ai_return_policy', sanitize_textarea_field( wp_unslash( $_POST['return_policy'] ?? '' ) ) );
		update_option( 'fahad_ai_support_contact', sanitize_text_field( wp_unslash( $_POST['support_contact'] ?? '' ) ) );
		update_option( 'fahad_ai_store_knowledge', sanitize_textarea_field( wp_unslash( $_POST['store_knowledge'] ?? '' ) ) );
		update_option( 'fahad_ai_weekly_digest', empty( $_POST['weekly_digest'] ) ? 0 : 1 );
		update_option( 'fahad_ai_notification_email', sanitize_email( wp_unslash( $_POST['notification_email'] ?? '' ) ) );
		update_option( 'fahad_ai_enabled', empty( $_POST['enabled'] ) ? 0 : 1 );
		update_option( 'fahad_ai_hide_on_checkout', empty( $_POST['hide_on_checkout'] ) ? 0 : 1 );
		update_option( 'fahad_ai_disabled_tools', fahad_ai_sanitize_tool_list( array_map( 'sanitize_text_field', (array) wp_unslash( $_POST['disabled_tools'] ?? [] ) ) ) );

		// Multilingual: default/allowed languages (issue #61). Default 'auto' = detect and
		// match the shopper's language across the supported set (English / Urdu / Roman
		// Urdu); a specific list (e.g. "English, Urdu") pins the preferred set.
		update_option( 'fahad_ai_languages', fahad_ai_sanitize_languages( sanitize_text_field( wp_unslash( $_POST['languages'] ?? 'auto' ) ) ) );

		// Cost / model knobs (issue #23, surfaced for #56).
		update_option( 'fahad_ai_token_budget',        absint( $_POST['token_budget'] ?? 0 ) );
		update_option( 'fahad_ai_daily_message_cap',   absint( $_POST['daily_message_cap'] ?? 0 ) );
		update_option( 'fahad_ai_monthly_budget',      max( 0, (float) ( $_POST['monthly_budget'] ?? 0 ) ) );
		update_option( 'fahad_ai_rate_limit',          max( 1, absint( $_POST['rate_limit'] ?? 20 ) ) );
		update_option( 'fahad_ai_fast_model_routing',  empty( $_POST['fast_model_routing'] ) ? 0 : 1 );
		update_option( 'fahad_ai_fast_model',          sanitize_text_field( wp_unslash( $_POST['fast_model'] ?? '' ) ) );

		// Proactive, value-gated nudge (issue #65). Default OFF (opt-in); the frequency
		// cap is floored at 0 (0 = effectively off, never nudge).
		update_option( 'fahad_ai_proactive_enabled',   empty( $_POST['proactive_enabled'] ) ? 0 : 1 );
		update_option( 'fahad_ai_proactive_frequency', absint( $_POST['proactive_frequency'] ?? Fahad_AI_Proactive::DEFAULT_FREQUENCY ) );

		// Voice input/output (issue #64). Both default OFF (opt-in): the master switch
		// gates whether the widget builds the mic/speaker controls at all, and the TTS
		// sub-toggle controls whether replies are spoken aloud.
		update_option( 'fahad_ai_voice_enabled', empty( $_POST['voice_enabled'] ) ? 0 : 1 );
		update_option( 'fahad_ai_voice_tts',     empty( $_POST['voice_tts'] ) ? 0 : 1 );

		// WhatsApp omnichannel channel (issue #62). Default OFF (opt-in). The verify token
		// and app secret are SECRETS used only server-side (the webhook handshake + the
		// inbound HMAC), sanitized as plain text, never localized to the client or fed to
		// the model. Going live also needs a provider for the outbound send seam + Meta
		// access tokens (held by that provider), which are intentionally out of core.
		update_option( 'fahad_ai_whatsapp_enabled',      empty( $_POST['whatsapp_enabled'] ) ? 0 : 1 );
		update_option( 'fahad_ai_whatsapp_verify_token', sanitize_text_field( wp_unslash( $_POST['whatsapp_verify_token'] ?? '' ) ) );
		update_option( 'fahad_ai_whatsapp_app_secret',   sanitize_text_field( wp_unslash( $_POST['whatsapp_app_secret'] ?? '' ) ) );

		// Semantic search settings (#108). Sanitization lives in the admin class.
		Fahad_AI_Embeddings_Admin::save( wp_unslash( $_POST ) );

		echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Settings saved.', 'fahad-ai-shopping-assistant-for-woocommerce' ) . '</p></div>';
	}

	$provider_catalog = Fahad_AI_Providers::catalog(); // multi-provider: drives the select + fields
	$provider        = get_option( 'fahad_ai_provider',          'anthropic' );
	$anthropic_key   = get_option( 'fahad_ai_anthropic_api_key', '' );
	$anthropic_model = get_option( 'fahad_ai_anthropic_model',   'claude-haiku-4-5-20251001' );
	$moonshot_key    = get_option( 'fahad_ai_moonshot_api_key',  '' );
	$moonshot_model  = get_option( 'fahad_ai_moonshot_model',    'kimi-k2.6' );
	$moonshot_region = get_option( 'fahad_ai_moonshot_region',   'global' );
	$bot_name        = get_option( 'fahad_ai_bot_name',          'Store Assistant' );
	$greeting        = get_option( 'fahad_ai_greeting',          'Hi! How can I help you today?' );
	$system_prompt   = get_option( 'fahad_ai_system_prompt',     '' );
	$accent_color    = get_option( 'fahad_ai_accent_color',      '#2563eb' );

	// Merchant config (#56) + cost knobs (#23).
	$tone               = get_option( 'fahad_ai_tone',                 '' );
	$off_limits         = get_option( 'fahad_ai_off_limits',           '' );
	$promo_emphasis     = get_option( 'fahad_ai_promo_emphasis',       '' );
	$free_shipping_threshold = (float) get_option( 'fahad_ai_free_shipping_threshold', 0 );
	$bestseller_threshold    = (int) get_option( 'fahad_ai_bestseller_threshold', 0 );
	$return_policy      = get_option( 'fahad_ai_return_policy',          '' );
	$support_contact    = get_option( 'fahad_ai_support_contact',        '' );
	$store_knowledge    = get_option( 'fahad_ai_store_knowledge',        '' );
	$weekly_digest      = fahad_ai_weekly_digest_enabled();
	$notification_email = get_option( 'fahad_ai_notification_email', '' );
	$assistant_enabled  = fahad_ai_widget_enabled();
	$hide_on_checkout   = fahad_ai_hide_on_checkout_enabled();
	$languages          = get_option( 'fahad_ai_languages',            'auto' ); // multilingual (#61)
	$disabled_tools     = (array) get_option( 'fahad_ai_disabled_tools', [] );
	$token_budget       = (int) get_option( 'fahad_ai_token_budget',   0 );
	$daily_message_cap  = (int) get_option( 'fahad_ai_daily_message_cap', 0 );
	$monthly_budget     = (float) get_option( 'fahad_ai_monthly_budget', 0 );
	$rate_limit         = fahad_ai_rate_limit_value();

	// Month-to-date AI spend (issue #235): read-only context shown next to the cost limits so
	// the owner sets the token budget / daily cap against what they are actually spending.
	$mtd_start   = (int) strtotime( gmdate( 'Y-m-01 00:00:00' ) );
	$mtd_cost    = Fahad_AI_Analytics::instance()->cost_summary( [ 'from' => $mtd_start ] );
	$fast_model_routing = (bool) get_option( 'fahad_ai_fast_model_routing', false );
	$fast_model         = get_option( 'fahad_ai_fast_model',           '' );

	// Proactive nudge (#65).
	$proactive_enabled   = (bool) get_option( 'fahad_ai_proactive_enabled', 0 );
	$proactive_frequency = max( 0, (int) get_option( 'fahad_ai_proactive_frequency', Fahad_AI_Proactive::DEFAULT_FREQUENCY ) );

		// Voice input/output (#64). Both default OFF (opt-in).
		$voice_enabled = (bool) get_option( 'fahad_ai_voice_enabled', 0 );
		$voice_tts     = (bool) get_option( 'fahad_ai_voice_tts', 0 );

	// WhatsApp omnichannel channel (#62). Default OFF (opt-in). The verify token + app
	// secret are server-side secrets (webhook handshake + inbound HMAC).
	$whatsapp_enabled      = (bool) get_option( 'fahad_ai_whatsapp_enabled', 0 );
	$whatsapp_verify_token = (string) get_option( 'fahad_ai_whatsapp_verify_token', '' );
	$whatsapp_app_secret   = (string) get_option( 'fahad_ai_whatsapp_app_secret', '' );

	// The five built-in WooCommerce tools are a protected floor and are never shown as
	// disable-able. Everything else advertised to the model (packs + add-ons) can be
	// gated. Derive the gateable list from the live registry so a new pack appears
	// automatically with no edits here.
	$builtin_tools  = [ 'search_products', 'get_product_details', 'add_to_cart', 'view_cart', 'remove_from_cart' ];
	$gateable_tools = [];
	foreach ( Fahad_AI_Tool_Registry::instance()->specs() as $spec ) {
		if ( ! in_array( $spec['name'], $builtin_tools, true ) ) {
			$gateable_tools[ $spec['name'] ] = $spec['description'];
		}
	}
	ksort( $gateable_tools );
	?>
	<div class="wrap">
		<h1><?php esc_html_e( 'Dukandar AI Shopping Assistant Settings', 'fahad-ai-shopping-assistant-for-woocommerce' ); ?></h1>

		<h2 class="title"><?php esc_html_e( 'Setup progress', 'fahad-ai-shopping-assistant-for-woocommerce' ); ?></h2>
		<ul style="max-width:46em;margin:0 0 8px;list-style:none;padding:0">
			<?php foreach ( fahad_ai_setup_checklist() as $step ) : ?>
				<li style="padding:4px 0"><code style="margin-right:8px"><?php echo esc_html( $step['mark'] ); ?></code><?php echo esc_html( $step['label'] ); ?></li>
			<?php endforeach; ?>
		</ul>

		<form method="post">
			<?php wp_nonce_field( 'fahad_ai_settings' ); ?>

			<h2 class="title"><?php esc_html_e( 'Provider', 'fahad-ai-shopping-assistant-for-woocommerce' ); ?></h2>
			<table class="form-table" role="presentation">

				<tr>
					<th scope="row"><label for="provider"><?php esc_html_e( 'AI Provider', 'fahad-ai-shopping-assistant-for-woocommerce' ); ?></label></th>
					<td>
						<select id="provider" name="provider">
							<?php foreach ( $provider_catalog as $provider_id => $preset ) : ?>
								<option value="<?php echo esc_attr( $provider_id ); ?>" <?php selected( $provider, $provider_id ); ?>><?php echo esc_html( $preset['label'] ); ?></option>
							<?php endforeach; ?>
						</select>
						<p class="description">
							<?php esc_html_e( 'Anthropic (Claude) uses its native API; every other provider uses the OpenAI-compatible API. Configure the key and model for your chosen provider below.', 'fahad-ai-shopping-assistant-for-woocommerce' ); ?>
						</p>
					</td>
				</tr>

				<!-- Anthropic fields -->
				<tbody id="fahad-ai-anthropic" style="<?php echo 'anthropic' !== $provider ? 'display:none' : ''; ?>">
					<tr>
						<th scope="row"><label for="anthropic_api_key"><?php esc_html_e( 'Anthropic API Key', 'fahad-ai-shopping-assistant-for-woocommerce' ); ?></label></th>
						<td>
							<input type="password" id="anthropic_api_key" name="anthropic_api_key"
								value="<?php echo esc_attr( $anthropic_key ); ?>" class="regular-text" autocomplete="new-password">
							<p class="description">
								<?php
								printf(
									/* translators: %s: URL to Anthropic console */
									esc_html__( 'Get your key from %s.', 'fahad-ai-shopping-assistant-for-woocommerce' ),
									'<a href="https://platform.claude.com" target="_blank" rel="noopener">platform.claude.com</a>'
								);
								?>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="anthropic_model"><?php esc_html_e( 'Claude Model', 'fahad-ai-shopping-assistant-for-woocommerce' ); ?></label></th>
						<td>
							<select id="anthropic_model" name="anthropic_model">
								<option value="claude-haiku-4-5-20251001" <?php selected( $anthropic_model, 'claude-haiku-4-5-20251001' ); ?>>
									<?php esc_html_e( 'Claude Haiku, Fast & affordable (recommended)', 'fahad-ai-shopping-assistant-for-woocommerce' ); ?>
								</option>
								<option value="claude-sonnet-4-6" <?php selected( $anthropic_model, 'claude-sonnet-4-6' ); ?>>
									<?php esc_html_e( 'Claude Sonnet, Balanced performance', 'fahad-ai-shopping-assistant-for-woocommerce' ); ?>
								</option>
								<option value="claude-opus-4-6" <?php selected( $anthropic_model, 'claude-opus-4-6' ); ?>>
									<?php esc_html_e( 'Claude Opus, Most capable', 'fahad-ai-shopping-assistant-for-woocommerce' ); ?>
								</option>
							</select>
						</td>
					</tr>
				</tbody>

				<!-- Moonshot / Kimi fields -->
				<tbody id="fahad-ai-moonshot" style="<?php echo 'moonshot' !== $provider ? 'display:none' : ''; ?>">
					<tr>
						<th scope="row"><label for="moonshot_region"><?php esc_html_e( 'Moonshot Region', 'fahad-ai-shopping-assistant-for-woocommerce' ); ?></label></th>
						<td>
							<select id="moonshot_region" name="moonshot_region">
								<option value="global" <?php selected( $moonshot_region, 'global' ); ?>><?php esc_html_e( 'Global, api.moonshot.ai (rest of world)', 'fahad-ai-shopping-assistant-for-woocommerce' ); ?></option>
								<option value="china"  <?php selected( $moonshot_region, 'china' );  ?>><?php esc_html_e( 'China, api.moonshot.cn', 'fahad-ai-shopping-assistant-for-woocommerce' ); ?></option>
							</select>
							<p class="description">
								<?php esc_html_e( 'Choose the platform your API key was issued on. Keys and available models are not shared between the global and China platforms.', 'fahad-ai-shopping-assistant-for-woocommerce' ); ?>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="moonshot_api_key"><?php esc_html_e( 'Moonshot API Key', 'fahad-ai-shopping-assistant-for-woocommerce' ); ?></label></th>
						<td>
							<input type="password" id="moonshot_api_key" name="moonshot_api_key"
								value="<?php echo esc_attr( $moonshot_key ); ?>" class="regular-text" autocomplete="new-password">
							<p class="description">
								<?php
								printf(
									/* translators: 1: URL to global Moonshot platform, 2: URL to China Moonshot platform */
									esc_html__( 'Get your key from %1$s (global) or %2$s (China).', 'fahad-ai-shopping-assistant-for-woocommerce' ),
									'<a href="https://platform.kimi.ai" target="_blank" rel="noopener">platform.kimi.ai</a>',
									'<a href="https://platform.moonshot.cn" target="_blank" rel="noopener">platform.moonshot.cn</a>'
								);
								?>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="moonshot_model"><?php esc_html_e( 'Kimi Model', 'fahad-ai-shopping-assistant-for-woocommerce' ); ?></label></th>
						<td>
							<select id="moonshot_model" name="moonshot_model">
								<optgroup label="<?php esc_attr_e( 'Kimi K2', 'fahad-ai-shopping-assistant-for-woocommerce' ); ?>">
									<option value="kimi-k2.6"              <?php selected( $moonshot_model, 'kimi-k2.6' );              ?>><?php esc_html_e( 'kimi-k2.6, Latest, general (recommended)', 'fahad-ai-shopping-assistant-for-woocommerce' ); ?></option>
									<option value="kimi-k2.5"              <?php selected( $moonshot_model, 'kimi-k2.5' );              ?>><?php esc_html_e( 'kimi-k2.5, General', 'fahad-ai-shopping-assistant-for-woocommerce' ); ?></option>
									<option value="kimi-k2-thinking-turbo" <?php selected( $moonshot_model, 'kimi-k2-thinking-turbo' ); ?>><?php esc_html_e( 'kimi-k2-thinking-turbo, Reasoning (availability varies by region)', 'fahad-ai-shopping-assistant-for-woocommerce' ); ?></option>
									<option value="kimi-k2-thinking"       <?php selected( $moonshot_model, 'kimi-k2-thinking' );       ?>><?php esc_html_e( 'kimi-k2-thinking, Reasoning (availability varies by region)', 'fahad-ai-shopping-assistant-for-woocommerce' ); ?></option>
								</optgroup>
								<optgroup label="<?php esc_attr_e( 'Moonshot V1', 'fahad-ai-shopping-assistant-for-woocommerce' ); ?>">
									<option value="moonshot-v1-auto"  <?php selected( $moonshot_model, 'moonshot-v1-auto' );  ?>><?php esc_html_e( 'moonshot-v1-auto, Auto context', 'fahad-ai-shopping-assistant-for-woocommerce' ); ?></option>
									<option value="moonshot-v1-8k"    <?php selected( $moonshot_model, 'moonshot-v1-8k' );    ?>>moonshot-v1-8k</option>
									<option value="moonshot-v1-32k"   <?php selected( $moonshot_model, 'moonshot-v1-32k' );   ?>>moonshot-v1-32k</option>
									<option value="moonshot-v1-128k"  <?php selected( $moonshot_model, 'moonshot-v1-128k' );  ?>>moonshot-v1-128k</option>
								</optgroup>
							</select>
							<p class="description">
								<?php esc_html_e( 'Available models depend on your region and key. If you get a "model not found" error, pick another model.', 'fahad-ai-shopping-assistant-for-woocommerce' ); ?>
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
					<tbody id="fahad-ai-<?php echo esc_attr( $provider_id ); ?>" style="<?php echo $provider_id !== $provider ? 'display:none' : ''; ?>">
						<?php if ( $is_custom ) : ?>
							<tr>
								<th scope="row"><label for="custom_base_url"><?php esc_html_e( 'Base URL', 'fahad-ai-shopping-assistant-for-woocommerce' ); ?></label></th>
								<td>
									<input type="url" id="custom_base_url" name="custom_base_url"
										value="<?php echo esc_attr( (string) get_option( 'fahad_ai_custom_base_url', '' ) ); ?>" class="regular-text" placeholder="https://api.example.com/v1">
									<p class="description">
										<?php esc_html_e( 'The OpenAI-compatible base URL (the prefix before /chat/completions). Must be HTTPS (a localhost address may use http). Invalid values are discarded on save.', 'fahad-ai-shopping-assistant-for-woocommerce' ); ?>
									</p>
								</td>
							</tr>
						<?php endif; ?>
						<tr>
							<th scope="row">
								<label for="<?php echo esc_attr( $pid_key ); ?>">
									<?php
									/* translators: %s: provider label, e.g. "OpenAI" */
									printf( esc_html__( '%s API Key', 'fahad-ai-shopping-assistant-for-woocommerce' ), esc_html( $preset['label'] ) );
									?>
								</label>
							</th>
							<td>
								<input type="password" id="<?php echo esc_attr( $pid_key ); ?>" name="<?php echo esc_attr( $pid_key ); ?>"
									value="<?php echo esc_attr( $saved_key ); ?>" class="regular-text" autocomplete="new-password">
								<?php if ( $is_local ) : ?>
									<p class="description"><?php esc_html_e( 'A local Ollama server usually needs no key, leave blank unless your setup requires one.', 'fahad-ai-shopping-assistant-for-woocommerce' ); ?></p>
								<?php endif; ?>
							</td>
						</tr>
						<tr>
							<th scope="row">
								<label for="<?php echo esc_attr( $pid_model ); ?>"><?php esc_html_e( 'Model', 'fahad-ai-shopping-assistant-for-woocommerce' ); ?></label>
							</th>
							<td>
								<?php if ( ! empty( $preset['models'] ) ) : ?>
									<input type="text" id="<?php echo esc_attr( $pid_model ); ?>" name="<?php echo esc_attr( $pid_model ); ?>"
										value="<?php echo esc_attr( $saved_model ); ?>" class="regular-text" list="fahad-ai-<?php echo esc_attr( $provider_id ); ?>-models">
									<datalist id="fahad-ai-<?php echo esc_attr( $provider_id ); ?>-models">
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
									printf( esc_html__( 'Defaults to %s when left blank.', 'fahad-ai-shopping-assistant-for-woocommerce' ), '<code>' . esc_html( (string) $preset['default_model'] ) . '</code>' );
									?>
								</p>
							</td>
						</tr>
					</tbody>
				<?php endforeach; ?>

			</table>

			<h2 class="title"><?php esc_html_e( 'Widget', 'fahad-ai-shopping-assistant-for-woocommerce' ); ?></h2>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="bot_name"><?php esc_html_e( 'Bot Name', 'fahad-ai-shopping-assistant-for-woocommerce' ); ?></label></th>
					<td>
						<input type="text" id="bot_name" name="bot_name"
							value="<?php echo esc_attr( $bot_name ); ?>" class="regular-text">
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="greeting"><?php esc_html_e( 'Greeting Message', 'fahad-ai-shopping-assistant-for-woocommerce' ); ?></label></th>
					<td>
						<textarea id="greeting" name="greeting" class="large-text" rows="2"><?php echo esc_textarea( $greeting ); ?></textarea>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="accent_color"><?php esc_html_e( 'Accent Color', 'fahad-ai-shopping-assistant-for-woocommerce' ); ?></label></th>
					<td>
						<input type="color" id="accent_color" name="accent_color"
							value="<?php echo esc_attr( $accent_color ); ?>">
					</td>
				</tr>
			</table>

			<h2 class="title">
				<?php esc_html_e( 'System Prompt', 'fahad-ai-shopping-assistant-for-woocommerce' ); ?>
				<span style="font-weight:400;font-size:13px;"><?php esc_html_e( '(optional)', 'fahad-ai-shopping-assistant-for-woocommerce' ); ?></span>
			</h2>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="system_prompt"><?php esc_html_e( 'Custom Prompt', 'fahad-ai-shopping-assistant-for-woocommerce' ); ?></label></th>
					<td>
						<textarea id="system_prompt" name="system_prompt" class="large-text" rows="7"><?php echo esc_textarea( $system_prompt ); ?></textarea>
						<p class="description">
							<?php esc_html_e( 'Leave blank to use the default prompt. Add store policies, shipping info, FAQs, or tone guidelines here.', 'fahad-ai-shopping-assistant-for-woocommerce' ); ?>
						</p>
					</td>
				</tr>
			</table>

			<h2 class="title"><?php esc_html_e( 'Assistant Behaviour', 'fahad-ai-shopping-assistant-for-woocommerce' ); ?></h2>
			<p class="description" style="max-width:50em;">
				<?php esc_html_e( 'Tune the assistant\'s tone and scope. These preferences guide the assistant, but the built-in trust safeguards (no fake urgency, respect the customer\'s budget, honest about extras, no invented facts, always allow human support) always apply and cannot be turned off.', 'fahad-ai-shopping-assistant-for-woocommerce' ); ?>
			</p>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="tone"><?php esc_html_e( 'Tone / Persona', 'fahad-ai-shopping-assistant-for-woocommerce' ); ?></label></th>
					<td>
						<select id="tone" name="tone">
							<option value="" <?php selected( $tone, '' ); ?>><?php esc_html_e( 'Default (friendly, no specific persona)', 'fahad-ai-shopping-assistant-for-woocommerce' ); ?></option>
							<option value="friendly"     <?php selected( $tone, 'friendly' );     ?>><?php esc_html_e( 'Friendly &amp; approachable', 'fahad-ai-shopping-assistant-for-woocommerce' ); ?></option>
							<option value="professional" <?php selected( $tone, 'professional' ); ?>><?php esc_html_e( 'Professional &amp; precise', 'fahad-ai-shopping-assistant-for-woocommerce' ); ?></option>
							<option value="concise"      <?php selected( $tone, 'concise' );      ?>><?php esc_html_e( 'Concise &amp; to the point', 'fahad-ai-shopping-assistant-for-woocommerce' ); ?></option>
							<option value="playful"      <?php selected( $tone, 'playful' );      ?>><?php esc_html_e( 'Playful &amp; upbeat', 'fahad-ai-shopping-assistant-for-woocommerce' ); ?></option>
							<option value="luxury"       <?php selected( $tone, 'luxury' );       ?>><?php esc_html_e( 'Premium / concierge', 'fahad-ai-shopping-assistant-for-woocommerce' ); ?></option>
						</select>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="off_limits"><?php esc_html_e( 'Off-limits Topics', 'fahad-ai-shopping-assistant-for-woocommerce' ); ?></label></th>
					<td>
						<textarea id="off_limits" name="off_limits" class="large-text" rows="2"><?php echo esc_textarea( $off_limits ); ?></textarea>
						<p class="description">
							<?php esc_html_e( 'Topics the assistant should politely decline and steer back to shopping (e.g. medical advice, competitor pricing, politics). Comma-separated or free text.', 'fahad-ai-shopping-assistant-for-woocommerce' ); ?>
						</p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="promo_emphasis"><?php esc_html_e( 'Promotion Emphasis', 'fahad-ai-shopping-assistant-for-woocommerce' ); ?></label></th>
					<td>
						<textarea id="promo_emphasis" name="promo_emphasis" class="large-text" rows="3"><?php echo esc_textarea( $promo_emphasis ); ?></textarea>
						<p class="description">
							<?php esc_html_e( 'Optional per-category emphasis, e.g. "Footwear: highlight the winter clearance." The assistant will only mention these when genuinely relevant and never as pressure.', 'fahad-ai-shopping-assistant-for-woocommerce' ); ?>
						</p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="free_shipping_threshold"><?php esc_html_e( 'Free Shipping Threshold', 'fahad-ai-shopping-assistant-for-woocommerce' ); ?></label></th>
					<td>
						<input type="number" id="free_shipping_threshold" name="free_shipping_threshold" min="0" step="0.01"
							value="<?php echo esc_attr( (string) $free_shipping_threshold ); ?>" class="small-text">
						<p class="description">
							<?php esc_html_e( 'Order amount that unlocks free shipping at your store. When set, the assistant can helpfully tell a shopper how much more they need to add to qualify, to lift order value, stated as a fact and never as pressure. 0 = do not mention.', 'fahad-ai-shopping-assistant-for-woocommerce' ); ?>
						</p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="bestseller_threshold"><?php esc_html_e( 'Bestseller Threshold', 'fahad-ai-shopping-assistant-for-woocommerce' ); ?></label></th>
					<td>
						<input type="number" id="bestseller_threshold" name="bestseller_threshold" min="0" step="1"
							value="<?php echo esc_attr( (string) $bestseller_threshold ); ?>" class="small-text">
						<p class="description">
							<?php esc_html_e( 'Lifetime units sold at which a product counts as a bestseller. When set, the assistant can point shoppers to proven best-sellers as honest social proof, grounded in real sales. 0 = do not mention.', 'fahad-ai-shopping-assistant-for-woocommerce' ); ?>
						</p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="return_policy"><?php esc_html_e( 'Return &amp; Refund Policy', 'fahad-ai-shopping-assistant-for-woocommerce' ); ?></label></th>
					<td>
						<textarea id="return_policy" name="return_policy" class="large-text" rows="3"><?php echo esc_textarea( $return_policy ); ?></textarea>
						<p class="description">
							<?php esc_html_e( 'Your returns, refunds, and exchange policy in plain words, e.g. "30-day returns on unworn items with a receipt." The assistant answers return questions using only what you enter here, never invents terms, and refers anything it does not cover to human support. Leave blank to have it decline return questions.', 'fahad-ai-shopping-assistant-for-woocommerce' ); ?>
						</p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="support_contact"><?php esc_html_e( 'Support Contact', 'fahad-ai-shopping-assistant-for-woocommerce' ); ?></label></th>
					<td>
						<input type="text" id="support_contact" name="support_contact" class="regular-text"
							value="<?php echo esc_attr( $support_contact ); ?>">
						<p class="description">
							<?php esc_html_e( 'How a shopper reaches a human, e.g. an email, phone number, or contact page URL. The assistant shares this exactly when someone needs a person or it cannot help, and never invents a contact. Leave blank to give no contact.', 'fahad-ai-shopping-assistant-for-woocommerce' ); ?>
						</p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="store_knowledge"><?php esc_html_e( 'Store Information / FAQ', 'fahad-ai-shopping-assistant-for-woocommerce' ); ?></label></th>
					<td>
						<textarea id="store_knowledge" name="store_knowledge" class="large-text" rows="5"><?php echo esc_textarea( $store_knowledge ); ?></textarea>
						<p class="description">
							<?php esc_html_e( 'Facts about your store the assistant can share, beyond product data: shipping and delivery times, sizing and fit, materials and care, brand or warranty info, and other common questions. The assistant answers from this when relevant and never invents details beyond it. Leave blank to skip.', 'fahad-ai-shopping-assistant-for-woocommerce' ); ?>
						</p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="languages"><?php esc_html_e( 'Languages', 'fahad-ai-shopping-assistant-for-woocommerce' ); ?></label></th>
					<td>
						<input type="text" id="languages" name="languages"
							value="<?php echo esc_attr( $languages ); ?>" class="regular-text"
							placeholder="auto">
						<p class="description">
							<?php esc_html_e( 'Languages the assistant should reply in. Use "auto" to detect each shopper\'s language and match it (English, Urdu, or Roman Urdu). Or list a preferred set, e.g. "English, Urdu". Product facts and prices stay grounded in the store data and are never translated; reply quality depends on the AI model you use.', 'fahad-ai-shopping-assistant-for-woocommerce' ); ?>
						</p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Available Actions', 'fahad-ai-shopping-assistant-for-woocommerce' ); ?></th>
					<td>
						<?php if ( empty( $gateable_tools ) ) : ?>
							<p class="description"><?php esc_html_e( 'No optional actions are installed. The core product search and cart actions are always available.', 'fahad-ai-shopping-assistant-for-woocommerce' ); ?></p>
						<?php else : ?>
							<fieldset>
								<legend class="screen-reader-text"><?php esc_html_e( 'Disable assistant actions', 'fahad-ai-shopping-assistant-for-woocommerce' ); ?></legend>
								<p class="description" style="margin-bottom:8px;">
									<?php esc_html_e( 'Untick an action to stop the assistant from using it. The core product search and cart actions cannot be disabled.', 'fahad-ai-shopping-assistant-for-woocommerce' ); ?>
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
									<?php esc_html_e( 'Note: ticked actions are DISABLED.', 'fahad-ai-shopping-assistant-for-woocommerce' ); ?>
								</p>
							</fieldset>
						<?php endif; ?>
					</td>
				</tr>
			</table>

			<h2 class="title"><?php esc_html_e( 'Cost &amp; Performance', 'fahad-ai-shopping-assistant-for-woocommerce' ); ?></h2>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><?php esc_html_e( 'AI Spend This Month', 'fahad-ai-shopping-assistant-for-woocommerce' ); ?></th>
					<td>
						<strong><?php echo esc_html( get_woocommerce_currency_symbol() . number_format( (float) $mtd_cost['total_cost'], 4 ) ); ?></strong>
						<?php
						printf(
							/* translators: %d: number of conversations so far this month. */
							' ' . esc_html__( 'across %d conversations so far this month.', 'fahad-ai-shopping-assistant-for-woocommerce' ),
							(int) $mtd_cost['conversations']
						);
						?>
						<p class="description"><?php esc_html_e( 'Set your token budget and daily cap below with this in mind. This is a running total for the current calendar month.', 'fahad-ai-shopping-assistant-for-woocommerce' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Enable Assistant', 'fahad-ai-shopping-assistant-for-woocommerce' ); ?></th>
					<td>
						<label>
							<input type="checkbox" name="enabled" value="1" <?php checked( $assistant_enabled ); ?>>
							<?php esc_html_e( 'Show the assistant and answer shoppers. Untick to pause it everywhere without deactivating the plugin: the widget disappears and no AI calls are made, but all your settings are kept.', 'fahad-ai-shopping-assistant-for-woocommerce' ); ?>
						</label>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Hide at Checkout', 'fahad-ai-shopping-assistant-for-woocommerce' ); ?></th>
					<td>
						<label>
							<input type="checkbox" name="hide_on_checkout" value="1" <?php checked( $hide_on_checkout ); ?>>
							<?php esc_html_e( 'Do not show the assistant on the cart and checkout pages, for a distraction-free checkout. It stays available everywhere else.', 'fahad-ai-shopping-assistant-for-woocommerce' ); ?>
						</label>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="token_budget"><?php esc_html_e( 'Conversation Token Budget', 'fahad-ai-shopping-assistant-for-woocommerce' ); ?></label></th>
					<td>
						<input type="number" id="token_budget" name="token_budget" min="0" step="500"
							value="<?php echo esc_attr( (string) $token_budget ); ?>" class="small-text">
						<p class="description">
							<?php esc_html_e( 'Approximate cap on the context sent to the model per turn (older history is trimmed first; the current turn is always kept). 0 = unlimited.', 'fahad-ai-shopping-assistant-for-woocommerce' ); ?>
						</p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="daily_message_cap"><?php esc_html_e( 'Daily Message Limit', 'fahad-ai-shopping-assistant-for-woocommerce' ); ?></label></th>
					<td>
						<input type="number" id="daily_message_cap" name="daily_message_cap" min="0" step="10"
							value="<?php echo esc_attr( (string) $daily_message_cap ); ?>" class="small-text">
						<p class="description">
							<?php esc_html_e( 'Maximum AI answers the whole store will serve per day, to keep costs predictable. When reached, shoppers are pointed to human support instead of making more billable calls; the count resets each day. 0 = unlimited.', 'fahad-ai-shopping-assistant-for-woocommerce' ); ?>
						</p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="monthly_budget"><?php esc_html_e( 'Monthly Budget', 'fahad-ai-shopping-assistant-for-woocommerce' ); ?></label></th>
					<td>
						<input type="number" id="monthly_budget" name="monthly_budget" min="0" step="1"
							value="<?php echo esc_attr( (string) $monthly_budget ); ?>" class="small-text">
						<p class="description">
							<?php esc_html_e( 'A monthly AI spend budget, in your store currency. When this calendar month\'s spend reaches it, you get an admin warning so there are no surprises on the provider invoice. 0 = no budget.', 'fahad-ai-shopping-assistant-for-woocommerce' ); ?>
						</p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="rate_limit"><?php esc_html_e( 'Requests Per Minute', 'fahad-ai-shopping-assistant-for-woocommerce' ); ?></label></th>
					<td>
						<input type="number" id="rate_limit" name="rate_limit" min="1" step="1"
							value="<?php echo esc_attr( (string) $rate_limit ); ?>" class="small-text">
						<p class="description">
							<?php esc_html_e( 'How many messages a single visitor may send per minute before being asked to slow down. Protects against a single client spamming the assistant and running up cost. Lower it if you see abuse. Default 20.', 'fahad-ai-shopping-assistant-for-woocommerce' ); ?>
						</p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="notification_email"><?php esc_html_e( 'Notifications Email', 'fahad-ai-shopping-assistant-for-woocommerce' ); ?></label></th>
					<td>
						<input type="email" id="notification_email" name="notification_email" class="regular-text"
							value="<?php echo esc_attr( $notification_email ); ?>" placeholder="<?php echo esc_attr( (string) get_option( 'admin_email', '' ) ); ?>">
						<p class="description">
							<?php esc_html_e( 'Where the assistant\'s emails (welcome and weekly digest) are sent. Leave blank to use the WordPress admin email. Set this if a shop manager, rather than the site admin, should receive them.', 'fahad-ai-shopping-assistant-for-woocommerce' ); ?>
						</p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Weekly Email Digest', 'fahad-ai-shopping-assistant-for-woocommerce' ); ?></th>
					<td>
						<label>
							<input type="checkbox" name="weekly_digest" value="1" <?php checked( $weekly_digest ); ?>>
							<?php esc_html_e( 'Email the store admin a weekly summary of what the assistant did: conversations, chat-to-cart rate, attributed orders, cost, and top questions. Sent only when there was activity.', 'fahad-ai-shopping-assistant-for-woocommerce' ); ?>
						</label>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Fast-model Routing', 'fahad-ai-shopping-assistant-for-woocommerce' ); ?></th>
					<td>
						<label>
							<input type="checkbox" name="fast_model_routing" value="1" <?php checked( $fast_model_routing ); ?>>
							<?php esc_html_e( 'Route simple turns (e.g. greetings, with no tool use) to a cheaper, faster model.', 'fahad-ai-shopping-assistant-for-woocommerce' ); ?>
						</label>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="fast_model"><?php esc_html_e( 'Fast Model', 'fahad-ai-shopping-assistant-for-woocommerce' ); ?></label></th>
					<td>
						<input type="text" id="fast_model" name="fast_model"
							value="<?php echo esc_attr( $fast_model ); ?>" class="regular-text"
							placeholder="claude-haiku-4-5-20251001">
						<p class="description">
							<?php esc_html_e( 'Model id to use for simple turns when fast-model routing is enabled (e.g. claude-haiku-4-5-20251001 or kimi-k2.6). Leave blank to keep the configured model.', 'fahad-ai-shopping-assistant-for-woocommerce' ); ?>
						</p>
					</td>
				</tr>
			</table>

			<h2 class="title"><?php esc_html_e( 'Proactive Assist', 'fahad-ai-shopping-assistant-for-woocommerce' ); ?></h2>
			<p class="description" style="max-width:50em;">
				<?php esc_html_e( 'When enabled, the assistant may show a single, dismissible message offering genuine help, but ONLY when there is real value to surface (a discount code that actually applies, or store credit the shopper has not used). It never invents urgency or scarcity, is capped per visit, and stops the moment a shopper dismisses it. Leave it off if you would rather the assistant only ever speaks when spoken to.', 'fahad-ai-shopping-assistant-for-woocommerce' ); ?>
			</p>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><?php esc_html_e( 'Proactive Nudges', 'fahad-ai-shopping-assistant-for-woocommerce' ); ?></th>
					<td>
						<label>
							<input type="checkbox" name="proactive_enabled" value="1" <?php checked( $proactive_enabled ); ?>>
							<?php esc_html_e( 'Let the assistant proactively offer a real, applicable deal or unused store credit (off by default).', 'fahad-ai-shopping-assistant-for-woocommerce' ); ?>
						</label>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="proactive_frequency"><?php esc_html_e( 'Max Nudges Per Visit', 'fahad-ai-shopping-assistant-for-woocommerce' ); ?></label></th>
					<td>
						<input type="number" id="proactive_frequency" name="proactive_frequency" min="0" max="5" step="1"
							value="<?php echo esc_attr( (string) $proactive_frequency ); ?>" class="small-text">
						<p class="description">
							<?php esc_html_e( 'How many times, at most, a proactive message may appear in a single visit. 1 (once per session) is recommended; 0 turns proactive messages off.', 'fahad-ai-shopping-assistant-for-woocommerce' ); ?>
						</p>
					</td>
				</tr>
			</table>

			<h2 class="title"><?php esc_html_e( 'Voice', 'fahad-ai-shopping-assistant-for-woocommerce' ); ?></h2>
			<p class="description" style="max-width:50em;">
				<?php esc_html_e( 'Let shoppers talk to the assistant. When enabled, a microphone button appears in the chat so a shopper can speak a question (it is transcribed into the message box using their browser\'s built-in speech recognition), and you can optionally have the assistant read its replies aloud. This uses the browser\'s own Web Speech API, so no audio is recorded or sent to any external service, the microphone permission is always requested by the browser, and typing always works. The controls are hidden automatically in browsers that do not support speech.', 'fahad-ai-shopping-assistant-for-woocommerce' ); ?>
			</p>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><?php esc_html_e( 'Voice Input', 'fahad-ai-shopping-assistant-for-woocommerce' ); ?></th>
					<td>
						<label>
							<input type="checkbox" name="voice_enabled" value="1" <?php checked( $voice_enabled ); ?>>
							<?php esc_html_e( 'Show a microphone button so shoppers can speak their message (off by default).', 'fahad-ai-shopping-assistant-for-woocommerce' ); ?>
						</label>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Spoken Replies', 'fahad-ai-shopping-assistant-for-woocommerce' ); ?></th>
					<td>
						<label>
							<input type="checkbox" name="voice_tts" value="1" <?php checked( $voice_tts ); ?>>
							<?php esc_html_e( 'Also let the assistant read its replies aloud, with a speaker button to toggle it (requires Voice Input; off by default).', 'fahad-ai-shopping-assistant-for-woocommerce' ); ?>
						</label>
					</td>
				</tr>
			</table>

			<h2 class="title"><?php esc_html_e( 'WhatsApp (beta)', 'fahad-ai-shopping-assistant-for-woocommerce' ); ?></h2>
			<p class="description" style="max-width:50em;">
				<?php esc_html_e( 'Let shoppers reach the assistant over WhatsApp. This is the webhook + verification scaffolding only: going live needs a WhatsApp Business (Meta Cloud API) account, and an outbound message provider to actually send replies. Configure the webhook below in your Meta app, using this callback URL and verify token. Personal account data stays available only to verified, logged-in customers, a WhatsApp number is treated as a guest.', 'fahad-ai-shopping-assistant-for-woocommerce' ); ?>
			</p>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><?php esc_html_e( 'Enable WhatsApp', 'fahad-ai-shopping-assistant-for-woocommerce' ); ?></th>
					<td>
						<label>
							<input type="checkbox" name="whatsapp_enabled" value="1" <?php checked( $whatsapp_enabled ); ?>>
							<?php esc_html_e( 'Process inbound WhatsApp messages through the assistant (off by default).', 'fahad-ai-shopping-assistant-for-woocommerce' ); ?>
						</label>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Callback URL', 'fahad-ai-shopping-assistant-for-woocommerce' ); ?></th>
					<td>
						<input type="text" class="regular-text code" value="<?php echo esc_attr( rest_url( 'fahad-ai/v1/whatsapp' ) ); ?>" readonly onclick="this.select();">
						<p class="description"><?php esc_html_e( 'Enter this as the Callback URL when configuring the WhatsApp webhook in your Meta app.', 'fahad-ai-shopping-assistant-for-woocommerce' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="fahad_ai_whatsapp_verify_token"><?php esc_html_e( 'Verify Token', 'fahad-ai-shopping-assistant-for-woocommerce' ); ?></label></th>
					<td>
						<input type="text" id="fahad_ai_whatsapp_verify_token" name="whatsapp_verify_token" value="<?php echo esc_attr( $whatsapp_verify_token ); ?>" class="regular-text" autocomplete="off">
						<p class="description"><?php esc_html_e( 'A secret string you choose; enter the same value as the Verify Token in the Meta webhook setup.', 'fahad-ai-shopping-assistant-for-woocommerce' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="fahad_ai_whatsapp_app_secret"><?php esc_html_e( 'App Secret', 'fahad-ai-shopping-assistant-for-woocommerce' ); ?></label></th>
					<td>
						<input type="password" id="fahad_ai_whatsapp_app_secret" name="whatsapp_app_secret" value="<?php echo esc_attr( $whatsapp_app_secret ); ?>" class="regular-text" autocomplete="off">
						<p class="description"><?php esc_html_e( 'Your Meta app secret. Used to verify the signature on each inbound message; never shared with the assistant or shown to shoppers.', 'fahad-ai-shopping-assistant-for-woocommerce' ); ?></p>
					</td>
				</tr>
			</table>

			<?php Fahad_AI_Embeddings_Admin::render_settings(); ?>

			<?php submit_button( esc_html__( 'Save Settings', 'fahad-ai-shopping-assistant-for-woocommerce' ), 'primary', 'fahad_ai_save' ); ?>
		</form>
	</div>
	<?php
}
