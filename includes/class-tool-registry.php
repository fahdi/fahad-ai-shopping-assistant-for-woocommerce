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
 */
final class Fahad_AI_Tool_Registry {

	private static $instance = null;

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
	 * Seeds the five built-ins in code, then runs the registration filter so
	 * third parties can append/modify, then validates. The result is cached on
	 * the instance.
	 *
	 * @return array<string, array>
	 */
	private function get_tools(): array {
		if ( null !== $this->tools ) {
			return $this->tools;
		}

		$builtins = Fahad_AI_Tools::instance()->builtin_definitions();

		/**
		 * Filter the list of agent tools.
		 *
		 * Add-ons append tool definitions here. Each entry must provide a unique
		 * `name` (string), a `description` (string), a `parameters` JSON Schema
		 * array (with `type` and `properties`) and a `callback` (callable taking
		 * the tool input array and returning a result array). Invalid entries are
		 * skipped; later entries with a duplicate name override earlier ones.
		 *
		 * @param array $builtins The built-in tool definitions.
		 */
		$tools = apply_filters( 'fahad_ai_register_tools', $builtins );

		$this->tools = $this->validate( is_array( $tools ) ? $tools : $builtins );

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
}
