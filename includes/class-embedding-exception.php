<?php
/**
 * Typed embedding failure (RAG Phase 1, S1.1, #104).
 *
 * `is_retryable()` distinguishes transient failures (timeout, 429, 5xx) from
 * terminal ones (no key, 4xx, malformed response) so the indexer backs off vs.
 * gives up, and the retriever degrades to keyword search. These never reach the
 * shopper.
 */

defined( 'ABSPATH' ) || exit;

class Fahad_AI_Embedding_Exception extends RuntimeException {

	private bool $retryable;

	public function __construct( string $message, bool $retryable = false ) {
		parent::__construct( $message );
		$this->retryable = $retryable;
	}

	public function is_retryable(): bool {
		return $this->retryable;
	}
}
