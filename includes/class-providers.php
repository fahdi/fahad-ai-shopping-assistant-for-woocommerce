<?php
defined( 'ABSPATH' ) || exit;

/**
 * Multi-provider catalog — the single source of truth for which AI services the
 * assistant can dispatch to.
 *
 * The assistant speaks two wire protocols: Anthropic's native Messages API and the
 * widely-adopted OpenAI Chat Completions shape. Every provider here is therefore one
 * of two `type`s:
 *   - 'anthropic' — the native path (run_anthropic_agent / call_anthropic). Only
 *     Anthropic (Claude) itself uses it.
 *   - 'openai'    — the OpenAI-compatible path (run_openai_agent / call_openai /
 *     stream_one_turn). Moonshot, OpenAI, Gemini, Groq, Mistral, DeepSeek, xAI,
 *     Together, OpenRouter, Perplexity, Ollama and a merchant-defined `custom`
 *     endpoint all ride this single, parameterised path — differing ONLY in their
 *     base URL, API key and model. Adding a provider is therefore DATA, not code.
 *
 * Each preset declares:
 *   - label         Human-readable name for the admin <select>.
 *   - type          'anthropic' | 'openai' (see above).
 *   - base_url      OpenAI base URL (the /chat/completions prefix). Native provider
 *                   omits it; `custom` resolves it from a merchant option at runtime;
 *                   `moonshot` resolves it from its region option at runtime.
 *   - default_model Used when the merchant has not chosen a model.
 *   - models        The models offered in the admin UI (advisory; any string works).
 *   - key_option    Option name holding this provider's API key.
 *   - model_option  Option name holding the merchant's chosen model.
 *
 * BACKWARD COMPATIBILITY (mandatory): the `anthropic` and `moonshot` presets keep
 * their pre-existing option names (fahad_ai_anthropic_api_key / _model,
 * fahad_ai_moonshot_api_key / _model / _region) so an install configured before the
 * multi-provider work keeps working with no migration. New providers follow the
 * fahad_ai_{id}_api_key / fahad_ai_{id}_model convention (which the two legacy ids
 * happen to already match).
 *
 * EXTENSIBILITY: the catalog runs through apply_filters( 'fahad_ai_providers', … )
 * so an add-on can register an entirely new provider at the DATA level — no
 * provider-class plumbing. Malformed entries (non-array, or missing required keys)
 * are dropped so a broken add-on can never poison dispatch.
 */
final class Fahad_AI_Providers {

	/**
	 * Filter hook name for registering/altering providers.
	 */
	public const FILTER = 'fahad_ai_providers';

	/**
	 * The keys every catalog entry must declare to be considered valid. A filtered
	 * entry missing any of these is discarded (defence in depth).
	 *
	 * @var string[]
	 */
	private const REQUIRED_KEYS = [ 'label', 'type', 'default_model', 'models', 'key_option', 'model_option' ];

	/**
	 * The built-in provider presets, before the fahad_ai_providers filter runs.
	 *
	 * Built once and memoised within a request (the filter result included). Tests
	 * reset Brain\Monkey between cases, so the static cache is keyed on whether the
	 * filter machinery is live; we deliberately do NOT cache across resolve() calls
	 * because get_option values (keys/models) change per turn — only the catalog
	 * SHAPE is stable, and rebuilding it is cheap.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private static function presets(): array {
		return [
			// Native Anthropic (Claude). The ONLY 'anthropic'-type provider. Keeps the
			// pre-existing option names for backward compatibility.
			'anthropic' => [
				'label'         => __( 'Anthropic (Claude)', 'fahad-ai-shopping-assistant-for-woocommerce' ),
				'type'          => 'anthropic',
				'default_model' => 'claude-haiku-4-5-20251001',
				'models'        => [ 'claude-haiku-4-5-20251001', 'claude-sonnet-4-6', 'claude-opus-4-6' ],
				'key_option'    => 'fahad_ai_anthropic_api_key',
				'model_option'  => 'fahad_ai_anthropic_model',
			],

			// Moonshot AI (Kimi). OpenAI-compatible. Base URL is region-selected at
			// runtime in resolve() (global vs. china) and carries the /v1 segment; keeps
			// its existing options.
			'moonshot' => [
				'label'         => __( 'Moonshot AI (Kimi)', 'fahad-ai-shopping-assistant-for-woocommerce' ),
				'type'          => 'openai',
				'base_url'      => 'https://api.moonshot.ai/v1',
				'default_model' => 'kimi-k2.6',
				'models'        => [ 'kimi-k2.6', 'kimi-k2.5', 'kimi-k2-thinking-turbo', 'kimi-k2-thinking', 'moonshot-v1-auto', 'moonshot-v1-8k', 'moonshot-v1-32k', 'moonshot-v1-128k' ],
				'key_option'    => 'fahad_ai_moonshot_api_key',
				'model_option'  => 'fahad_ai_moonshot_model',
			],

			// OpenAI.
			'openai' => [
				'label'         => __( 'OpenAI', 'fahad-ai-shopping-assistant-for-woocommerce' ),
				'type'          => 'openai',
				'base_url'      => 'https://api.openai.com/v1',
				'default_model' => 'gpt-4o-mini',
				'models'        => [ 'gpt-4o-mini', 'gpt-4o', 'o4-mini' ],
				'key_option'    => 'fahad_ai_openai_api_key',
				'model_option'  => 'fahad_ai_openai_model',
			],

			// Google Gemini (OpenAI-compatible endpoint).
			'gemini' => [
				'label'         => __( 'Google Gemini', 'fahad-ai-shopping-assistant-for-woocommerce' ),
				'type'          => 'openai',
				'base_url'      => 'https://generativelanguage.googleapis.com/v1beta/openai',
				'default_model' => 'gemini-2.0-flash',
				'models'        => [ 'gemini-2.0-flash', 'gemini-1.5-pro', 'gemini-1.5-flash' ],
				'key_option'    => 'fahad_ai_gemini_api_key',
				'model_option'  => 'fahad_ai_gemini_model',
			],

			// Groq (fast inference).
			'groq' => [
				'label'         => __( 'Groq', 'fahad-ai-shopping-assistant-for-woocommerce' ),
				'type'          => 'openai',
				'base_url'      => 'https://api.groq.com/openai/v1',
				'default_model' => 'llama-3.3-70b-versatile',
				'models'        => [ 'llama-3.3-70b-versatile', 'llama-3.1-8b-instant', 'mixtral-8x7b-32768' ],
				'key_option'    => 'fahad_ai_groq_api_key',
				'model_option'  => 'fahad_ai_groq_model',
			],

			// Mistral.
			'mistral' => [
				'label'         => __( 'Mistral AI', 'fahad-ai-shopping-assistant-for-woocommerce' ),
				'type'          => 'openai',
				'base_url'      => 'https://api.mistral.ai/v1',
				'default_model' => 'mistral-small-latest',
				'models'        => [ 'mistral-small-latest', 'mistral-large-latest', 'open-mistral-nemo' ],
				'key_option'    => 'fahad_ai_mistral_api_key',
				'model_option'  => 'fahad_ai_mistral_model',
			],

			// DeepSeek.
			'deepseek' => [
				'label'         => __( 'DeepSeek', 'fahad-ai-shopping-assistant-for-woocommerce' ),
				'type'          => 'openai',
				'base_url'      => 'https://api.deepseek.com/v1',
				'default_model' => 'deepseek-chat',
				'models'        => [ 'deepseek-chat', 'deepseek-reasoner' ],
				'key_option'    => 'fahad_ai_deepseek_api_key',
				'model_option'  => 'fahad_ai_deepseek_model',
			],

			// xAI (Grok).
			'xai' => [
				'label'         => __( 'xAI (Grok)', 'fahad-ai-shopping-assistant-for-woocommerce' ),
				'type'          => 'openai',
				'base_url'      => 'https://api.x.ai/v1',
				'default_model' => 'grok-2-latest',
				'models'        => [ 'grok-2-latest', 'grok-beta' ],
				'key_option'    => 'fahad_ai_xai_api_key',
				'model_option'  => 'fahad_ai_xai_model',
			],

			// Together AI.
			'together' => [
				'label'         => __( 'Together AI', 'fahad-ai-shopping-assistant-for-woocommerce' ),
				'type'          => 'openai',
				'base_url'      => 'https://api.together.xyz/v1',
				'default_model' => 'meta-llama/Llama-3.3-70B-Instruct-Turbo',
				'models'        => [ 'meta-llama/Llama-3.3-70B-Instruct-Turbo', 'mistralai/Mixtral-8x7B-Instruct-v0.1' ],
				'key_option'    => 'fahad_ai_together_api_key',
				'model_option'  => 'fahad_ai_together_model',
			],

			// OpenRouter (multi-model gateway).
			'openrouter' => [
				'label'         => __( 'OpenRouter', 'fahad-ai-shopping-assistant-for-woocommerce' ),
				'type'          => 'openai',
				'base_url'      => 'https://openrouter.ai/api/v1',
				'default_model' => 'openai/gpt-4o-mini',
				'models'        => [ 'openai/gpt-4o-mini', 'anthropic/claude-3.5-sonnet', 'google/gemini-2.0-flash-exp' ],
				'key_option'    => 'fahad_ai_openrouter_api_key',
				'model_option'  => 'fahad_ai_openrouter_model',
			],

			// Perplexity.
			'perplexity' => [
				'label'         => __( 'Perplexity', 'fahad-ai-shopping-assistant-for-woocommerce' ),
				'type'          => 'openai',
				'base_url'      => 'https://api.perplexity.ai',
				'default_model' => 'sonar',
				'models'        => [ 'sonar', 'sonar-pro' ],
				'key_option'    => 'fahad_ai_perplexity_api_key',
				'model_option'  => 'fahad_ai_perplexity_model',
			],

			// Ollama (local, self-hosted). No key required, but the option still exists.
			'ollama' => [
				'label'         => __( 'Ollama (local)', 'fahad-ai-shopping-assistant-for-woocommerce' ),
				'type'          => 'openai',
				'base_url'      => 'http://localhost:11434/v1',
				'default_model' => 'llama3.2',
				'models'        => [ 'llama3.2', 'llama3.1', 'qwen2.5', 'mistral' ],
				'key_option'    => 'fahad_ai_ollama_api_key',
				'model_option'  => 'fahad_ai_ollama_model',
			],

			// Custom — any OpenAI-compatible endpoint, base URL set by the merchant.
			'custom' => [
				'label'         => __( 'Custom (OpenAI-compatible)', 'fahad-ai-shopping-assistant-for-woocommerce' ),
				'type'          => 'openai',
				'base_url'      => '', // resolved from fahad_ai_custom_base_url at runtime.
				'default_model' => '',
				'models'        => [],
				'key_option'    => 'fahad_ai_custom_api_key',
				'model_option'  => 'fahad_ai_custom_model',
			],
		];
	}

	/**
	 * The full provider catalog: built-in presets plus anything an add-on registers
	 * via the fahad_ai_providers filter, with malformed entries dropped.
	 *
	 * @return array<string, array<string, mixed>> Keyed by provider id.
	 */
	public static function catalog(): array {
		/**
		 * Filter the provider catalog (the multi-provider extensibility seam).
		 *
		 * Add-ons append a new provider id => preset array here. A preset is the same
		 * shape as the built-ins (see the class docblock). Entries that are not arrays,
		 * or that omit a required key, are discarded after the filter so a broken
		 * add-on cannot poison provider dispatch.
		 *
		 * @param array<string, array<string, mixed>> $catalog The provider catalog.
		 */
		$catalog = apply_filters( self::FILTER, self::presets() );

		if ( ! is_array( $catalog ) ) {
			return self::presets();
		}

		$clean = [];
		foreach ( $catalog as $id => $preset ) {
			if ( ! is_string( $id ) || '' === $id || ! is_array( $preset ) ) {
				continue;
			}
			if ( ! self::is_valid_preset( $preset ) ) {
				continue;
			}
			$clean[ $id ] = $preset;
		}

		// Never let a filter strip the built-ins entirely (a hostile/broken add-on
		// returning [] would otherwise break the configured provider). The built-in
		// anthropic preset is the irreducible floor.
		if ( empty( $clean ) ) {
			return self::presets();
		}

		return $clean;
	}

	/**
	 * Whether a catalog entry declares every required key (and a sane type).
	 */
	private static function is_valid_preset( array $preset ): bool {
		foreach ( self::REQUIRED_KEYS as $key ) {
			if ( ! array_key_exists( $key, $preset ) ) {
				return false;
			}
		}
		return in_array( $preset['type'], [ 'anthropic', 'openai' ], true );
	}

	/**
	 * A single provider preset, or null when the id is unknown.
	 *
	 * @return array<string, mixed>|null
	 */
	public static function get( string $id ): ?array {
		$catalog = self::catalog();
		return $catalog[ $id ] ?? null;
	}

	/**
	 * Every provider id in the catalog, in catalog order.
	 *
	 * @return string[]
	 */
	public static function ids(): array {
		return array_keys( self::catalog() );
	}

	/**
	 * The transport type for a provider ('anthropic' | 'openai'), or '' if unknown.
	 */
	public static function type( string $id ): string {
		$preset = self::get( $id );
		return $preset ? (string) $preset['type'] : '';
	}

	/**
	 * Whether a provider routes through the OpenAI-compatible path (SSE-capable).
	 * Used by the bootstrap to tell the widget which providers can stream.
	 */
	public static function is_openai( string $id ): bool {
		return 'openai' === self::type( $id );
	}

	/**
	 * Resolve a provider to the concrete values a turn needs: its transport type,
	 * the live API key, the chosen model (falling back to the preset default), and —
	 * for OpenAI-type providers — the base URL.
	 *
	 * Two providers compute their base URL dynamically rather than from a static
	 * catalog value:
	 *   - moonshot: region-selected (global vs. china) from fahad_ai_moonshot_region,
	 *     preserving the existing two-platform behaviour (separate keys/catalogues).
	 *   - custom:   the merchant-set fahad_ai_custom_base_url.
	 *
	 * Returns null for an unknown provider id (the caller treats that as "no provider").
	 *
	 * @return array{ type: string, label: string, base_url: string, api_key: string, model: string }|null
	 */
	public static function resolve( string $id ): ?array {
		$preset = self::get( $id );
		if ( null === $preset ) {
			return null;
		}

		$api_key = (string) get_option( $preset['key_option'], '' );
		$model   = (string) get_option( $preset['model_option'], '' );
		if ( '' === $model ) {
			$model = (string) $preset['default_model'];
		}

		$base_url = (string) ( $preset['base_url'] ?? '' );
		if ( 'moonshot' === $id ) {
			// Region-selected host + the /v1 segment the OpenAI path expects.
			$base_url = self::moonshot_base_url() . '/v1';
		} elseif ( 'custom' === $id ) {
			$base_url = (string) get_option( 'fahad_ai_custom_base_url', '' );
		}

		return [
			'type'     => (string) $preset['type'],
			'label'    => (string) $preset['label'],
			'base_url' => $base_url,
			'api_key'  => $api_key,
			'model'    => $model,
		];
	}

	/**
	 * Validate a merchant-supplied OpenAI-compatible base URL (the `custom` provider).
	 *
	 * Security: the base URL is concatenated into the outbound request target, so it
	 * must be a real http(s) URL — never a `javascript:`/`data:` scheme or junk. HTTPS
	 * is required for any remote host (the API key travels in the Authorization
	 * header), with ONE exception: a localhost/127.0.0.1/::1 host may use plain http,
	 * because a self-hosted endpoint (a local proxy, an Ollama-style server) on the
	 * same machine never leaves it. A failed check returns '' (no custom endpoint).
	 *
	 * @param string $raw Raw URL from the settings form.
	 * @return string The sanitized https (or localhost-http) URL, or '' if invalid.
	 */
	public static function sanitize_base_url( string $raw ): string {
		$raw = trim( $raw );
		if ( '' === $raw ) {
			return '';
		}

		$parts  = wp_parse_url( $raw );
		$scheme = strtolower( (string) ( $parts['scheme'] ?? '' ) );
		$host   = strtolower( (string) ( $parts['host'] ?? '' ) );

		if ( '' === $host || ! in_array( $scheme, [ 'http', 'https' ], true ) ) {
			return '';
		}

		// Plain http is only allowed for a loopback host (never leaves the machine).
		$is_local = in_array( $host, [ 'localhost', '127.0.0.1', '::1' ], true );
		if ( 'https' !== $scheme && ! $is_local ) {
			return '';
		}

		// esc_url_raw is the WordPress canonical sanitizer for a stored/used URL.
		return (string) esc_url_raw( $raw );
	}

	/**
	 * Base URL for the Moonshot API, selected by the configured region.
	 *
	 * Moonshot runs two independent platforms with separate keys and model
	 * catalogues: the global endpoint (api.moonshot.ai) and the China endpoint
	 * (api.moonshot.cn). A key issued on one is rejected by the other. This mirrors
	 * Fahad_AI_API_Handler::moonshot_base_url() (kept there for its own unit tests);
	 * the logic is intentionally trivial and identical in both places.
	 */
	private static function moonshot_base_url(): string {
		$region = get_option( 'fahad_ai_moonshot_region', 'global' );
		return 'china' === $region
			? 'https://api.moonshot.cn'
			: 'https://api.moonshot.ai';
	}
}
