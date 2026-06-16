<?php
/**
 * PHPUnit bootstrap — sets up Brain\Monkey and loads plugin files.
 */

require_once dirname( __DIR__ ) . '/vendor/autoload.php';
require_once __DIR__ . '/stubs/wc-stubs.php';

// Load the plugin classes (no WordPress bootstrap needed for unit tests).
require_once dirname( __DIR__ ) . '/includes/class-tool-registry.php';
require_once dirname( __DIR__ ) . '/includes/class-tools.php';
require_once dirname( __DIR__ ) . '/includes/class-api-handler.php';

// Eval harness (used by the `eval` test suite; harmless to load for `unit`).
require_once __DIR__ . '/eval/EvalHarness.php';
