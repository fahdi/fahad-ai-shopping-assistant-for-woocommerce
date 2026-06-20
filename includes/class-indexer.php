<?php
/**
 * Embedding indexer + Action Scheduler sync (RAG Phase 1, S1.3, #106).
 *
 * Keeps the vector store in step with the catalog. Embedding is a network call,
 * so it is ALWAYS async (Action Scheduler) — never inline on a product save.
 * A content-hash check means a save that did not change the embedded text (e.g.
 * a price-only edit) is a no-op, and a per-day cap protects the merchant's bill
 * during a bulk import (RAG-DESIGN.md §5.2, §5.3, §5.6).
 */

defined( 'ABSPATH' ) || exit;

final class Fahad_AI_Indexer {

	public const ACTION_EMBED     = 'fahad_ai_embed_product';
	public const ACTION_DELETE    = 'fahad_ai_delete_embedding';
	public const GROUP            = 'fahad-ai-embeddings';
	public const OPTION_DAILY_CAP = 'fahad_ai_embed_daily_cap';

	public function __construct(
		private Fahad_AI_Embedding_Provider $provider,
		private Fahad_AI_Vector_Store $store
	) {}

	/** Wire product lifecycle + the async action handlers. */
	public static function init(): void {
		add_action( 'woocommerce_update_product', [ self::class, 'enqueue_reembed' ] );
		add_action( 'woocommerce_new_product', [ self::class, 'enqueue_reembed' ] );
		add_action( 'wp_trash_post', [ self::class, 'enqueue_delete' ] );
		add_action( 'before_delete_post', [ self::class, 'enqueue_delete' ] );
		add_action( self::ACTION_EMBED, [ self::class, 'handle_embed_action' ] );
		add_action( self::ACTION_DELETE, [ self::class, 'handle_delete_action' ] );
	}

	/**
	 * Embed a product from explicit fields, into the store. Returns true if it
	 * embedded, false if it skipped (unchanged text, over the cap, or no text).
	 */
	public function index_fields( int $product_id, array $fields ): bool {
		$doc = Fahad_AI_Embedding_Document::compose( $fields );
		if ( '' === $doc ) {
			$this->store->delete( $product_id );
			return false;
		}

		$hash = Fahad_AI_Embedding_Document::content_hash( $doc );
		if ( $hash === $this->store->content_hash( $product_id ) ) {
			return false; // embedded text unchanged — a price/stock-only edit never re-embeds
		}

		if ( ! $this->within_daily_cap() ) {
			return false; // protect the bill; the next run picks it up
		}

		$vectors = $this->provider->embed( [ $doc ] );
		if ( ! empty( $vectors[0] ) ) {
			$this->store->upsert( $product_id, $vectors[0], $this->provider->model(), $hash );
			$this->bump_daily_count();
			return true;
		}
		return false;
	}

	/** Embed (or delete) one product by id, resolving its fields from WooCommerce. */
	public function reindex_product( int $product_id ): void {
		$product = function_exists( 'wc_get_product' ) ? wc_get_product( $product_id ) : null;
		if ( ! $product ) {
			$this->store->delete( $product_id );
			return;
		}
		$this->index_fields_safe( $product_id, $this->product_fields( $product ) );
	}

	/**
	 * index_fields() with failure handling (#110): a terminal embedding error is
	 * recorded and swallowed; a retryable one is recorded and rethrown so Action
	 * Scheduler reschedules the job. Either way the shopper never sees an error.
	 */
	public function index_fields_safe( int $product_id, array $fields ): bool {
		try {
			return $this->index_fields( $product_id, $fields );
		} catch ( Fahad_AI_Embedding_Exception $e ) {
			Fahad_AI_Index_Health::record_failure( $e->getMessage() );
			if ( $e->is_retryable() ) {
				throw $e;
			}
			return false;
		}
	}

	/**
	 * Enqueue async embed jobs for a set of products (or all published when null).
	 *
	 * @param array<int,int>|null $product_ids
	 * @return int Number of jobs enqueued.
	 */
	public function backfill( ?array $product_ids = null ): int {
		if ( null === $product_ids ) {
			$product_ids = function_exists( 'wc_get_products' )
				? (array) wc_get_products( [ 'return' => 'ids', 'status' => 'publish', 'limit' => -1 ] )
				// @codeCoverageIgnoreStart
				// Reason: the `: []` arm runs only when wc_get_products() is undefined; once any sibling test stubs it via Patchwork the definition lingers (PHP cannot undefine a function) so function_exists() reports true for the rest of the process — unreachable in the full suite.
				: [];
				// @codeCoverageIgnoreEnd
		}
		$count = 0;
		foreach ( $product_ids as $id ) {
			self::enqueue_reembed( (int) $id );
			++$count;
		}
		return $count;
	}

	public function within_daily_cap(): bool {
		$cap = (int) get_option( self::OPTION_DAILY_CAP, 0 );
		if ( $cap <= 0 ) {
			return true; // 0 = unlimited
		}
		return (int) get_transient( self::cap_key() ) < $cap;
	}

	private function bump_daily_count(): void {
		$key = self::cap_key();
		set_transient( $key, ( (int) get_transient( $key ) ) + 1, 86400 );
	}

	private static function cap_key(): string {
		return 'fahad_ai_embed_count_' . gmdate( 'Ymd' );
	}

	/** Coalesced async re-embed (unique=true folds rapid repeated saves). */
	public static function enqueue_reembed( $product_id ): void {
		if ( function_exists( 'as_enqueue_async_action' ) ) {
			as_enqueue_async_action( self::ACTION_EMBED, [ 'product_id' => (int) $product_id ], self::GROUP, true );
		}
	}

	public static function enqueue_delete( $product_id ): void {
		if ( function_exists( 'as_enqueue_async_action' ) ) {
			as_enqueue_async_action( self::ACTION_DELETE, [ 'product_id' => (int) $product_id ], self::GROUP, true );
		}
	}

	/** Action Scheduler handler: embed one product (no-op without a provider). */
	public static function handle_embed_action( $product_id ): void {
		if ( ! Fahad_AI_Embeddings::enabled() ) {
			return; // semantic search off — don't embed
		}
		$provider = Fahad_AI_Embeddings::provider();
		if ( ! $provider || ! $provider->is_available() ) {
			return;
		}
		$store = Fahad_AI_Vector_Stores::resolve( $provider->model(), $provider->dimensions() );
		( new self( $provider, $store ) )->reindex_product( (int) $product_id );
	}

	/** Action Scheduler handler: remove one product's embedding. */
	public static function handle_delete_action( $product_id ): void {
		$provider = Fahad_AI_Embeddings::provider();
		$model    = $provider ? $provider->model() : '';
		$dims     = $provider ? $provider->dimensions() : 0;
		Fahad_AI_Vector_Stores::resolve( $model, $dims )->delete( (int) $product_id );
	}

	/** Compose the embeddable fields from a WooCommerce product. */
	private function product_fields( $product ): array {
		$id = $product->get_id();
		return [
			'title'             => $product->get_name(),
			'categories'        => $this->term_names( $id, 'product_cat' ),
			'short_description' => $product->get_short_description(),
			'description'       => $product->get_description(),
			'tags'              => $this->term_names( $id, 'product_tag' ),
		];
	}

	/** @return string[] */
	private function term_names( int $product_id, string $taxonomy ): array {
		$terms = function_exists( 'get_the_terms' ) ? get_the_terms( $product_id, $taxonomy ) : [];
		if ( ! is_array( $terms ) ) {
			return [];
		}
		return array_values( array_filter( array_map( static fn( $t ) => is_object( $t ) ? (string) $t->name : '', $terms ) ) );
	}
}
