<?php
/**
 * Embedding index health (RAG Phase 2, S2.2, #110).
 *
 * A tiny option-backed counter of embedding failures + the last error, surfaced
 * in the admin index-status readout so a merchant can see when indexing is
 * struggling (bad key, rate limits, provider outage). Cleared on a fresh build.
 */

defined( 'ABSPATH' ) || exit;

final class Fahad_AI_Index_Health {

	private const OPT_FAILURES     = 'fahad_ai_index_failures';
	private const OPT_LAST_ERROR   = 'fahad_ai_index_last_error';
	private const OPT_LAST_ERROR_AT = 'fahad_ai_index_last_error_at';

	public static function record_failure( string $message ): void {
		update_option( self::OPT_FAILURES, self::failures() + 1 );
		update_option( self::OPT_LAST_ERROR, $message );
		update_option( self::OPT_LAST_ERROR_AT, time() );
	}

	public static function failures(): int {
		return (int) get_option( self::OPT_FAILURES, 0 );
	}

	public static function last_error(): string {
		return (string) get_option( self::OPT_LAST_ERROR, '' );
	}

	public static function last_error_at(): int {
		return (int) get_option( self::OPT_LAST_ERROR_AT, 0 );
	}

	public static function clear(): void {
		delete_option( self::OPT_FAILURES );
		delete_option( self::OPT_LAST_ERROR );
		delete_option( self::OPT_LAST_ERROR_AT );
	}
}
