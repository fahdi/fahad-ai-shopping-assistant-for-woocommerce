<?php
/**
 * Fired when the plugin is uninstalled.
 * Removes all options stored by Fahad AI Shopping Assistant for WooCommerce.
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

$fahad_ai_options = [
	'fahad_ai_provider',
	'fahad_ai_anthropic_api_key',
	'fahad_ai_anthropic_model',
	'fahad_ai_moonshot_api_key',
	'fahad_ai_moonshot_model',
	'fahad_ai_moonshot_region',
	// Additional AI providers (multi-provider): each preset stores a per-provider API
	// key (a SECRET) and chosen model under the fahad_ai_{id}_api_key / _model
	// convention; `custom` also stores its merchant-set base URL. Removed on uninstall
	// like every other option. Add-on providers registered via the fahad_ai_providers
	// filter own their own cleanup.
	'fahad_ai_openai_api_key',
	'fahad_ai_openai_model',
	'fahad_ai_gemini_api_key',
	'fahad_ai_gemini_model',
	'fahad_ai_groq_api_key',
	'fahad_ai_groq_model',
	'fahad_ai_mistral_api_key',
	'fahad_ai_mistral_model',
	'fahad_ai_deepseek_api_key',
	'fahad_ai_deepseek_model',
	'fahad_ai_xai_api_key',
	'fahad_ai_xai_model',
	'fahad_ai_together_api_key',
	'fahad_ai_together_model',
	'fahad_ai_openrouter_api_key',
	'fahad_ai_openrouter_model',
	'fahad_ai_perplexity_api_key',
	'fahad_ai_perplexity_model',
	'fahad_ai_ollama_api_key',
	'fahad_ai_ollama_model',
	'fahad_ai_custom_api_key',
	'fahad_ai_custom_model',
	'fahad_ai_custom_base_url',
	'fahad_ai_bot_name',
	'fahad_ai_greeting',
	'fahad_ai_system_prompt',
	'fahad_ai_accent_color',
	// Back-in-stock / price-drop alert subscriptions (issue #51). Holds subscriber
	// emails (PII), so removing it on uninstall is part of the GDPR story.
	'fahad_ai_stock_alert_subs',
	// Reply feedback / guardrail telemetry (issue #50). A bounded, rolling window of
	// thumbs ratings (no PII); removed on uninstall like every other fahad_ai_ option.
	'fahad_ai_feedback',
	// Owner analytics & "unanswered questions" telemetry (issue #49). A bounded,
	// rolling window of privacy-safe per-turn events (masked question snippet, outcome,
	// tools, funnel flags — never PII) plus the opt-out flag. Both removed on uninstall.
	'fahad_ai_analytics',
	'fahad_ai_analytics_enabled',
	// Merchant scope / tone / business-rules config (issue #56): tone/persona,
	// off-limits topics, per-category promo emphasis, the disabled-tools list, and the
	// surfaced cost/model knobs (token budget + fast-model routing).
	'fahad_ai_tone',
	'fahad_ai_off_limits',
	'fahad_ai_promo_emphasis',
	'fahad_ai_disabled_tools',
	'fahad_ai_token_budget',
	'fahad_ai_fast_model_routing',
	'fahad_ai_fast_model',
	// Multilingual default/allowed languages (issue #61). A single plain-text setting
	// (token 'auto' or a preferred language list); no PII. Removed like every option.
	'fahad_ai_languages',
	// Proactive, value-gated nudge (issue #65): the merchant kill-switch (default OFF)
	// and the per-visit frequency cap. No PII (the nudge is computed per request from
	// grounded store data; nothing is persisted about a shopper here).
	'fahad_ai_proactive_enabled',
	'fahad_ai_proactive_frequency',
	// Voice input/output (issue #64): the merchant kill-switch (default OFF) and the
	// voice-output (speak replies) sub-toggle. No PII — voice uses the browser's
	// in-browser Web Speech API and nothing about a shopper is persisted here.
	'fahad_ai_voice_enabled',
	'fahad_ai_voice_tts',
	// WhatsApp omnichannel channel (issue #62): the merchant kill-switch (default OFF),
	// the webhook verify token, and the Meta App Secret (the inbound HMAC key). The
	// latter two are SECRETS, so removing them on uninstall is part of the security story;
	// no shopper PII (phone numbers, message text) is ever persisted by this channel.
	'fahad_ai_whatsapp_enabled',
	'fahad_ai_whatsapp_verify_token',
	'fahad_ai_whatsapp_app_secret',
];

foreach ( $fahad_ai_options as $fahad_ai_option ) {
	delete_option( $fahad_ai_option );
}

// Clear the daily analytics-purge cron event (issue #49) so no orphaned schedule
// lingers after the plugin is removed.
$fahad_ai_purge_ts = wp_next_scheduled( 'fahad_ai_analytics_purge' );
if ( $fahad_ai_purge_ts ) {
	wp_unschedule_event( $fahad_ai_purge_ts, 'fahad_ai_analytics_purge' );
}
