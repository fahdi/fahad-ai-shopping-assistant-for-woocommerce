<?php
/**
 * PHPUnit bootstrap — sets up Brain\Monkey and loads plugin files.
 */

require_once dirname( __DIR__ ) . '/vendor/autoload.php';
require_once __DIR__ . '/stubs/wc-stubs.php';

// Load the plugin classes (no WordPress bootstrap needed for unit tests).
require_once dirname( __DIR__ ) . '/includes/class-auth.php';
require_once dirname( __DIR__ ) . '/includes/class-feedback.php';
require_once dirname( __DIR__ ) . '/includes/class-tool-registry.php';
require_once dirname( __DIR__ ) . '/includes/class-tools.php';

// Load every drop-in feature tool pack, exactly like the plugin bootstrap. Each
// file under includes/tools/ self-registers via Fahad_AI_Tool_Registry::register_pack(),
// so a new pack drops in here with no edits to this bootstrap. Sorted for a
// deterministic load order. (The registry class is required above first, so the
// packs' file-scope register_pack() calls resolve.)
$fahad_ai_pack_files = glob( dirname( __DIR__ ) . '/includes/tools/*.php' ) ?: [];
sort( $fahad_ai_pack_files );
foreach ( $fahad_ai_pack_files as $fahad_ai_pack_file ) {
	require_once $fahad_ai_pack_file;
}

require_once dirname( __DIR__ ) . '/includes/class-api-handler.php';

// Eval harness (used by the `eval` test suite; harmless to load for `unit`).
require_once __DIR__ . '/eval/EvalHarness.php';
