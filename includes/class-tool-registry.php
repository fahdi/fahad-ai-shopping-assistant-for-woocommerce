<?php
defined( 'ABSPATH' ) || exit;

/**
 * Central registry of agent tools.
 *
 * Single source of truth for both the tool SPECS handed to the LLM and tool
 * EXECUTION (dispatch). The five built-in WooCommerce tools are seeded in code
 * from Fahad_AI_Tools, then the list is exposed to third-party add-ons through:
 *
 *     apply_filters( 'fahad_ai_register_tools', array $tools )
 *
 * Each tool entry is:
 *     [
 *         'name'        => string,         // unique tool name
 *         'description' => string,         // shown to the model
 *         'parameters'  => array,          // JSON Schema (type + properties)
 *         'callback'    => callable,       // fn( array $input ): array
 *     ]
 *
 * Add-ons (WalletPro, shipping, loyalty, …) register tools without forking core:
 *
 *     add_filter( 'fahad_ai_register_tools', function ( array $tools ) {
 *         $tools[] = [
 *             'name'        => 'wallet_balance',
 *             'description' => 'Get the logged-in customer wallet balance.',
 *             'parameters'  => [ 'type' => 'object', 'properties' => new stdClass() ],
 *             'callback'    => fn( array $in ) => [ 'balance' => '...' ],
 *         ];
 *         return $tools;
 *     } );
 *
 * Invalid entries are silently skipped, and a throwing callback is isolated so a
 * misbehaving add-on cannot fatal the request.
 *
 * FIRST-PARTY FEATURE PACKS — drop-in tool modules shipped with the plugin
 * (catalog, shipping, …) — use a deterministic, WordPress-filter-free path:
 * register_pack(). A pack lives in its own file under includes/tools/ and
 * self-registers a provider at file scope:
 *
 *     Fahad_AI_Tool_Registry::register_pack( [ 'Fahad_AI_Catalog_Tools', 'register' ] );
 *
 * where the provider is a callable `fn( array $tools ): array` that appends its
 * definitions. The bootstrap (and the test bootstrap) glob-require every file in
 * includes/tools/, so a NEW pack drops in with NO edits to the bootstrap, the
 * test bootstrap, or any shared registry wiring — just a new file. get_tools()
 * layers the sources in order: built-ins → first-party packs → the third-party
 * filter. Pack providers are stored STATICALLY so they survive a singleton
 * instance reset (only the built/validated tool LIST is cached per instance).
 */
final class Fahad_AI_Tool_Registry {

	private static $instance = null;

	/**
	 * First-party feature-pack providers, in registration order. Each is a
	 * callable `fn( array $tools ): array` that appends its tool definitions.
	 *
	 * STATIC on purpose: packs self-register once when their file is loaded, and
	 * the registration must outlive a reset of the singleton instance (the eval
	 * harness and unit suites reset $instance between cases). If this were stored
	 * on the instance, every feature pack would disappear after the first reset.
	 * Only the built/validated tool LIST below is per-instance.
	 *
	 * @var array<int, callable>
	 */
	private static array $pack_providers = [];

	/**
	 * Cached, validated tool list (name => entry). Null until first build.
	 * Stored on the instance (NOT static) so resetting the singleton clears it.
	 *
	 * @var array<string, array>|null
	 */
	private ?array $tools = null;

	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {}

	/**
	 * Register a first-party feature-pack tool provider.
	 *
	 * Called once per pack at file load (see the includes/tools/ directory). The
	 * provider receives the running tool list and returns it with its own tools
	 * appended:
	 *
	 *     Fahad_AI_Tool_Registry::register_pack(
	 *         fn( array $tools ) => array_merge( $tools, $my_entries )
	 *     );
	 *
	 * Providers run in registration order, AFTER the built-ins and BEFORE the
	 * `fahad_ai_register_tools` filter, so third parties can still override. The
	 * provider list is static and survives a singleton instance reset.
	 *
	 * @param callable $provider fn( array $tools ): array
	 */
	public static function register_pack( callable $provider ): void {
		self::$pack_providers[] = $provider;
	}

	/**
	 * Tool SPECS for the LLM — name/description/parameters only, never the
	 * callback. This is what Fahad_AI_API_Handler::tool_specs() returns.
	 *
	 * @return array<int, array{name: string, description: string, parameters: array}>
	 */
	public function specs(): array {
		$specs = [];
		foreach ( $this->get_tools() as $tool ) {
			$specs[] = [
				'name'        => $tool['name'],
				'description' => $tool['description'],
				'parameters'  => $tool['parameters'],
			];
		}
		return $specs;
	}

	/**
	 * Execute a tool by name and return its result array.
	 *
	 * Unknown tool → error array (same message/format the legacy
	 * Fahad_AI_Tools::execute switch produced). A callback that throws is caught
	 * so a third-party tool cannot fatal the agent request (error isolation).
	 *
	 * Personal-data tools (those declaring `'personal' => true`) are login-gated
	 * here, BEFORE their callback runs: a guest gets the standard login-required
	 * error and the callback is never reached. This is the central half of the
	 * authorization boundary (defence in depth) — per-RECORD ownership still lives
	 * inside each personal tool's callback (see Fahad_AI_Auth::user_owns), because
	 * the registry cannot know who a given order/wallet/memory row belongs to.
	 *
	 * @param string $name  Tool name.
	 * @param array  $input Tool input from the model.
	 * @return array
	 */
	public function dispatch( string $name, array $input ): array {
		$tools = $this->get_tools();

		if ( ! isset( $tools[ $name ] ) ) {
			return [
				'error' => sprintf(
					/* translators: %s: name of the unknown tool requested by the AI */
					__( 'Unknown tool: %s', 'fahad-ai-shopping-assistant-for-woocommerce' ),
					$name
				),
			];
		}

		// Central login gate for tools that expose personal data. A personal tool
		// cannot leak by forgetting to check login — the registry blocks guests
		// before the callback is ever invoked.
		if ( ! empty( $tools[ $name ]['personal'] ) ) {
			$gate = Fahad_AI_Auth::guard_logged_in();
			if ( true !== $gate ) {
				return $gate;
			}
		}

		try {
			$result = call_user_func( $tools[ $name ]['callback'], $input );
			return is_array( $result ) ? $result : [];
		} catch ( \Throwable $e ) {
			return [
				'error' => sprintf(
					/* translators: %s: name of the tool that failed */
					__( 'The tool "%s" could not be completed.', 'fahad-ai-shopping-assistant-for-woocommerce' ),
					$name
				),
			];
		}
	}

	/**
	 * Lazily build (once) and return the validated tool map keyed by name.
	 *
	 * Layers the sources in order:
	 *   1. the five built-ins (seeded in code),
	 *   2. every first-party feature pack registered via register_pack(), in
	 *      registration order,
	 *   3. the `fahad_ai_register_tools` filter (third-party add-ons).
	 *
	 * Then validates. The result is cached on the instance (per-instance, so a
	 * reset rebuilds it), while the pack providers themselves are static.
	 *
	 * @return array<string, array>
	 */
	private function get_tools(): array {
		if ( null !== $this->tools ) {
			return $this->tools;
		}

		$tools = Fahad_AI_Tools::instance()->builtin_definitions();

		// First-party feature packs (deterministic, not via the WP filter) so they
		// are picked up identically in production and tests.
		foreach ( self::$pack_providers as $provider ) {
			$next  = $provider( $tools );
			$tools = is_array( $next ) ? $next : $tools;
		}

		/**
		 * Filter the list of agent tools.
		 *
		 * Add-ons append tool definitions here. Each entry must provide a unique
		 * `name` (string), a `description` (string), a `parameters` JSON Schema
		 * array (with `type` and `properties`) and a `callback` (callable taking
		 * the tool input array and returning a result array). Invalid entries are
		 * skipped; later entries with a duplicate name override earlier ones.
		 *
		 * @param array $tools The built-in + first-party-pack tool definitions.
		 */
		$filtered = apply_filters( 'fahad_ai_register_tools', $tools );

		$this->tools = $this->validate( is_array( $filtered ) ? $filtered : $tools );

		return $this->tools;
	}

	/**
	 * Keep only well-formed tool entries, keyed by name. A later entry with the
	 * same name overrides an earlier one (so an add-on can replace a built-in).
	 *
	 * @param array $tools Raw tool entries.
	 * @return array<string, array>
	 */
	private function validate( array $tools ): array {
		$valid = [];

		foreach ( $tools as $tool ) {
			if ( ! is_array( $tool ) ) {
				continue;
			}

			$name = $tool['name'] ?? '';
			if ( ! is_string( $name ) || '' === $name ) {
				continue;
			}

			if ( ! isset( $tool['description'] ) || ! is_string( $tool['description'] ) ) {
				continue;
			}

			$parameters = $tool['parameters'] ?? null;
			if ( ! is_array( $parameters ) || ! isset( $parameters['type'] ) || ! array_key_exists( 'properties', $parameters ) ) {
				continue;
			}

			if ( ! isset( $tool['callback'] ) || ! is_callable( $tool['callback'] ) ) {
				continue;
			}

			$valid[ $name ] = $tool;
		}

		return $valid;
	}

	/**
	 * Clear the cached tool list. Primarily for tests; resetting the singleton
	 * via reflection achieves the same, but this lets a caller force a rebuild
	 * (e.g. after registering a filter at runtime).
	 */
	public function reset(): void {
		$this->tools = null;
	}

	/**
	 * Clear the static first-party pack-provider list.
	 *
	 * Test support only: lets a test that asserts on the bare built-in set + the
	 * third-party filter run against a registry with no feature packs, then
	 * restore the original providers afterwards. Production never calls this —
	 * packs self-register once at file load and stay for the request lifetime.
	 */
	public static function reset_packs(): void {
		self::$pack_providers = [];
	}
}
