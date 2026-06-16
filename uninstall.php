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
	'fahad_ai_bot_name',
	'fahad_ai_greeting',
	'fahad_ai_system_prompt',
	'fahad_ai_accent_color',
];

foreach ( $fahad_ai_options as $fahad_ai_option ) {
	delete_option( $fahad_ai_option );
}
