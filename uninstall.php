<?php
/**
 * Fired when the plugin is uninstalled.
 * Removes all options stored by Dukandaar AI Shopping Assistant for WooCommerce.
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

$dukandaar_options = [
	'dukandaar_provider',
	'dukandaar_anthropic_api_key',
	'dukandaar_anthropic_model',
	'dukandaar_moonshot_api_key',
	'dukandaar_moonshot_model',
	'dukandaar_moonshot_region',
	// Additional AI providers (multi-provider): each preset stores a per-provider API
	// key (a SECRET) and chosen model under the dukandaar_{id}_api_key / _model
	// convention; `custom` also stores its merchant-set base URL. Removed on uninstall
	// like every other option. Add-on providers registered via the dukandaar_providers
	// filter own their own cleanup.
	'dukandaar_openai_api_key',
	'dukandaar_openai_model',
	'dukandaar_gemini_api_key',
	'dukandaar_gemini_model',
	'dukandaar_groq_api_key',
	'dukandaar_groq_model',
	'dukandaar_mistral_api_key',
	'dukandaar_mistral_model',
	'dukandaar_deepseek_api_key',
	'dukandaar_deepseek_model',
	'dukandaar_xai_api_key',
	'dukandaar_xai_model',
	'dukandaar_together_api_key',
	'dukandaar_together_model',
	'dukandaar_openrouter_api_key',
	'dukandaar_openrouter_model',
	'dukandaar_perplexity_api_key',
	'dukandaar_perplexity_model',
	'dukandaar_ollama_api_key',
	'dukandaar_ollama_model',
	'dukandaar_custom_api_key',
	'dukandaar_custom_model',
	'dukandaar_custom_base_url',
	'dukandaar_bot_name',
	'dukandaar_greeting',
	'dukandaar_system_prompt',
	'dukandaar_accent_color',
	// Back-in-stock / price-drop alert subscriptions (issue #51). Holds subscriber
	// emails (PII), so removing it on uninstall is part of the GDPR story.
	'dukandaar_stock_alert_subs',
	// Reply feedback / guardrail telemetry (issue #50). A bounded, rolling window of
	// thumbs ratings (no PII); removed on uninstall like every other dukandaar_ option.
	'dukandaar_feedback',
	// Owner analytics & "unanswered questions" telemetry (issue #49). A bounded,
	// rolling window of privacy-safe per-turn events (masked question snippet, outcome,
	// tools, funnel flags, never PII) plus the opt-out flag. Both removed on uninstall.
	'dukandaar_analytics',
	'dukandaar_analytics_enabled',
	// Merchant scope / tone / business-rules config (issue #56): tone/persona,
	// off-limits topics, per-category promo emphasis, the disabled-tools list, and the
	// surfaced cost/model knobs (token budget + fast-model routing).
	'dukandaar_tone',
	'dukandaar_off_limits',
	'dukandaar_promo_emphasis',
	'dukandaar_disabled_tools',
	'dukandaar_token_budget',
	'dukandaar_fast_model_routing',
	'dukandaar_fast_model',
	// Multilingual default/allowed languages (issue #61). A single plain-text setting
	// (token 'auto' or a preferred language list); no PII. Removed like every option.
	'dukandaar_languages',
	// Proactive, value-gated nudge (issue #65): the merchant kill-switch (default OFF)
	// and the per-visit frequency cap. No PII (the nudge is computed per request from
	// grounded store data; nothing is persisted about a shopper here).
	'dukandaar_proactive_enabled',
	'dukandaar_proactive_frequency',
	// Voice input/output (issue #64): the merchant kill-switch (default OFF) and the
	// voice-output (speak replies) sub-toggle. No PII, voice uses the browser's
	// in-browser Web Speech API and nothing about a shopper is persisted here.
	'dukandaar_voice_enabled',
	'dukandaar_voice_tts',
	// WhatsApp omnichannel channel (issue #62): the merchant kill-switch (default OFF),
	// the webhook verify token, and the Meta App Secret (the inbound HMAC key). The
	// latter two are SECRETS, so removing them on uninstall is part of the security story;
	// no shopper PII (phone numbers, message text) is ever persisted by this channel.
	'dukandaar_whatsapp_enabled',
	'dukandaar_whatsapp_verify_token',
	'dukandaar_whatsapp_app_secret',
];

foreach ( $dukandaar_options as $dukandaar_option ) {
	delete_option( $dukandaar_option );
}

// Clear the daily analytics-purge cron event (issue #49) so no orphaned schedule
// lingers after the plugin is removed.
$dukandaar_purge_ts = wp_next_scheduled( 'dukandaar_analytics_purge' );
if ( $dukandaar_purge_ts ) {
	wp_unschedule_event( $dukandaar_purge_ts, 'dukandaar_analytics_purge' );
}
