<?php
/**
 * Coverage gate. Parses a Clover XML report and fails (exit 1) if the overall line
 * coverage is below a threshold. Used by `composer test:coverage` to keep the plugin
 * at 100% line coverage of includes/.
 *
 * Usage: php tests/check-coverage.php <clover.xml> [minPercent=100]
 *
 * Genuinely-unmeasurable lines (file-scope ABSPATH guards and pack self-registration
 * that run at bootstrap before pcov's per-test window; methods that end in exit and
 * tear down output buffers; branches blocked by a shared test stub) are marked with
 * @codeCoverageIgnore in source, each with a Reason comment — so 100% here means every
 * line that CAN be measured by in-process pcov is exercised by a test.
 */

$clover = $argv[1] ?? '';
$min    = isset( $argv[2] ) ? (float) $argv[2] : 100.0;

if ( '' === $clover || ! is_readable( $clover ) ) {
	fwrite( STDERR, "check-coverage: clover file not found: {$clover}\n" );
	exit( 2 );
}

$xml = @simplexml_load_file( $clover );
if ( false === $xml ) {
	fwrite( STDERR, "check-coverage: could not parse clover: {$clover}\n" );
	exit( 2 );
}

$metrics = $xml->project->metrics;
if ( ! $metrics ) {
	fwrite( STDERR, "check-coverage: no <project><metrics> in clover\n" );
	exit( 2 );
}

$statements = (int) $metrics['statements'];
$covered    = (int) $metrics['coveredstatements'];
$pct        = $statements > 0 ? ( 100.0 * $covered / $statements ) : 100.0;

printf( "Line coverage: %.2f%% (%d/%d) — threshold %.2f%%\n", $pct, $covered, $statements, $min );

if ( $pct + 1e-9 < $min ) {
	// List the files dragging it down so a regression is actionable.
	foreach ( $xml->xpath( '//file' ) as $file ) {
		$m = $file->metrics;
		$s = (int) $m['statements'];
		$c = (int) $m['coveredstatements'];
		if ( $s > 0 && $c < $s ) {
			fwrite( STDERR, sprintf( "  %5.1f%%  %d uncovered  %s\n", 100.0 * $c / $s, $s - $c, (string) $file['name'] ) );
		}
	}
	fwrite( STDERR, sprintf( "check-coverage: FAIL — %.2f%% < %.2f%%\n", $pct, $min ) );
	exit( 1 );
}

echo "check-coverage: PASS\n";
exit( 0 );
