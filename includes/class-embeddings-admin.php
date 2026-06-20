<?php
/**
 * Semantic-search admin: settings, index controls, status (RAG Phase 1, S1.5, #108).
 *
 * Renders the "Semantic Search" section on the settings page (enable, model,
 * dimensions, per-day cap), a nonce-gated "Build index" action that backfills
 * the catalog via Action Scheduler, and an index-status readout. The OpenAI key
 * itself is the existing provider key — semantic search reuses it but is opt-in
 * (Fahad_AI_Embeddings::enabled()) so a chat-only key never incurs embedding cost.
 *
 * Privacy: building the index sends product TEXT (title/description/attributes —
 * never price/stock/PII) to the configured embeddings provider. This is disclosed
 * in readme.txt (external services) and in the admin copy below.
 */

defined( 'ABSPATH' ) || exit;

final class Fahad_AI_Embeddings_Admin {

	public const OPT_ENABLED       = 'fahad_ai_embeddings_enabled';
	public const OPT_MODEL         = 'fahad_ai_embedding_model';
	public const OPT_DIMS          = 'fahad_ai_embedding_dims';
	public const OPT_CAP           = 'fahad_ai_embed_daily_cap';
	public const OPT_LAST_BUILD    = 'fahad_ai_index_built_at';
	public const OPT_PROVIDER_TYPE = 'fahad_ai_embedding_provider_type';
	public const OPT_BASE_URL      = 'fahad_ai_embedding_base_url';
	public const OPT_API_KEY       = 'fahad_ai_embedding_api_key';
	public const OPT_COHERE_KEY    = 'fahad_ai_cohere_api_key';
	public const ACTION_BUILD      = 'fahad_ai_build_index';

	private const DEFAULT_MODEL = 'text-embedding-3-small';

	public static function register(): void {
		add_action( 'admin_post_' . self::ACTION_BUILD, [ self::class, 'handle_build' ] );
	}

	/** Persist the sanitized semantic-search settings (called from the settings save). */
	public static function save( array $post ): void {
		update_option( self::OPT_ENABLED, empty( $post['embeddings_enabled'] ) ? 0 : 1 );

		$model = sanitize_text_field( (string) ( $post['embedding_model'] ?? '' ) );
		update_option( self::OPT_MODEL, '' !== $model ? $model : self::DEFAULT_MODEL );

		update_option( self::OPT_DIMS, max( 1, (int) ( $post['embedding_dims'] ?? 512 ) ) );
		update_option( self::OPT_CAP, max( 0, (int) ( $post['embed_daily_cap'] ?? 0 ) ) );

		// Provider flexibility (#111): type + endpoint + keys.
		$type = sanitize_text_field( (string) ( $post['embedding_provider_type'] ?? 'openai' ) );
		update_option( self::OPT_PROVIDER_TYPE, in_array( $type, [ 'openai', 'cohere' ], true ) ? $type : 'openai' );

		$base = sanitize_text_field( (string) ( $post['embedding_base_url'] ?? '' ) );
		update_option( self::OPT_BASE_URL, '' !== $base ? $base : 'https://api.openai.com/v1' );

		update_option( self::OPT_API_KEY, sanitize_text_field( (string) ( $post['embedding_api_key'] ?? '' ) ) );
		update_option( self::OPT_COHERE_KEY, sanitize_text_field( (string) ( $post['cohere_api_key'] ?? '' ) ) );

		// External vector backend (Qdrant, #113) — opt-in scale tier.
		update_option( 'fahad_ai_qdrant_url', sanitize_text_field( (string) ( $post['qdrant_url'] ?? '' ) ) );
		update_option( 'fahad_ai_qdrant_key', sanitize_text_field( (string) ( $post['qdrant_key'] ?? '' ) ) );
		$collection = sanitize_text_field( (string) ( $post['qdrant_collection'] ?? '' ) );
		update_option( 'fahad_ai_qdrant_collection', '' !== $collection ? $collection : 'fahad_ai_products' );
	}

	/**
	 * admin-post handler: build/rebuild the search index. Capability + nonce gated.
	 */
	public static function handle_build(): void {
		if ( ! current_user_can( fahad_ai_settings_capability() ) ) {
			wp_die( esc_html__( 'You do not have permission to build the search index.', 'fahad-ai-shopping-assistant-for-woocommerce' ), '', [ 'response' => 403 ] );
		}
		check_admin_referer( self::ACTION_BUILD );

		$count = self::run_build();

		wp_safe_redirect(
			add_query_arg(
				[ 'page' => 'fahad-ai-shopping-assistant-for-woocommerce', 'fahad_ai_indexed' => $count ],
				admin_url( 'options-general.php' )
			)
		);
		// @codeCoverageIgnoreStart
		// Reason: admin-post handler terminates the request; exit would kill the PHPUnit process, so the redirect stub throws before it and this line cannot be measured in-process.
		exit;
		// @codeCoverageIgnoreEnd
	}

	/**
	 * Enqueue a backfill for the whole catalog and record the index model + time.
	 * No-op (returns 0) without an available provider. Extracted from handle_build
	 * so it is unit-testable without the nonce/redirect ceremony.
	 */
	public static function run_build(): int {
		$provider = Fahad_AI_Embeddings::provider();
		if ( ! $provider || ! $provider->is_available() ) {
			return 0;
		}

		Fahad_AI_Index_Health::clear(); // fresh build — reset the failure tally

		$store = Fahad_AI_Vector_Stores::resolve( $provider->model(), $provider->dimensions() );
		$count = ( new Fahad_AI_Indexer( $provider, $store ) )->backfill();

		update_option( Fahad_AI_Postmeta_Vector_Store::OPTION_INDEX_MODEL, $provider->model() );
		update_option( self::OPT_LAST_BUILD, time() );

		return $count;
	}

	/**
	 * @return array{enabled:bool,available:bool,active_model:string,index_model:string,stale:bool,last_build:int,count:int}
	 */
	public static function index_status(): array {
		$provider     = Fahad_AI_Embeddings::provider();
		$active_model = $provider ? $provider->model() : '';
		$index_model  = (string) get_option( Fahad_AI_Postmeta_Vector_Store::OPTION_INDEX_MODEL, '' );

		return [
			'enabled'      => Fahad_AI_Embeddings::enabled(),
			'available'    => (bool) ( $provider && $provider->is_available() ),
			'active_model' => $active_model,
			'index_model'  => $index_model,
			'stale'        => '' !== $active_model && $index_model !== $active_model,
			'last_build'   => (int) get_option( self::OPT_LAST_BUILD, 0 ),
			'count'        => self::embedded_count(),
			'failures'     => Fahad_AI_Index_Health::failures(),
			'last_error'   => Fahad_AI_Index_Health::last_error(),
		];
	}

	/** Count published products that currently have an embedding. */
	private static function embedded_count(): int {
		if ( ! function_exists( 'wc_get_products' ) ) {
			// @codeCoverageIgnoreStart
			// Reason: WooCommerce-absent guard. The return is exercised (the count-is-zero test enters it with wc_get_products undefined) but PCOV does not attribute a hit to this early-return guard line in the private static method, so it is unmeasurable.
			return 0;
			// @codeCoverageIgnoreEnd
		}
		return count(
			(array) wc_get_products(
				[
					'status'       => 'publish',
					'limit'        => -1,
					'return'       => 'ids',
					'meta_key'     => '_fahad_ai_embedding', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- admin status count only, not a request hot path.
					'meta_compare' => 'EXISTS',
				]
			)
		);
	}

	/** Render the Semantic Search settings section + build control + status. */
	public static function render_settings(): void {
		$status     = self::index_status();
		$model      = (string) get_option( self::OPT_MODEL, self::DEFAULT_MODEL );
		$dims       = (int) get_option( self::OPT_DIMS, 512 );
		$cap        = (int) get_option( self::OPT_CAP, 0 );
		$type       = (string) get_option( self::OPT_PROVIDER_TYPE, 'openai' );
		$base_url   = (string) get_option( self::OPT_BASE_URL, 'https://api.openai.com/v1' );
		$api_key    = (string) get_option( self::OPT_API_KEY, '' );
		$cohere_key = (string) get_option( self::OPT_COHERE_KEY, '' );
		$qdrant_url = (string) get_option( 'fahad_ai_qdrant_url', '' );
		$qdrant_key = (string) get_option( 'fahad_ai_qdrant_key', '' );
		$qdrant_col = (string) get_option( 'fahad_ai_qdrant_collection', 'fahad_ai_products' );
		$build_url  = admin_url( 'admin-post.php' );
		?>
		<h2 class="title"><?php esc_html_e( 'Semantic Search (beta)', 'fahad-ai-shopping-assistant-for-woocommerce' ); ?></h2>
		<p class="description" style="max-width:50em;">
			<?php esc_html_e( 'Find products by meaning, not just keywords ("something warm for winter" finds the fleece). Uses your OpenAI key to build a vector index of product text. Product titles, descriptions and attributes are sent to OpenAI when the index is built or a product changes — never prices, stock, or customer data. Off by default; search stays keyword-only until you enable it.', 'fahad-ai-shopping-assistant-for-woocommerce' ); ?>
		</p>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><?php esc_html_e( 'Enable semantic search', 'fahad-ai-shopping-assistant-for-woocommerce' ); ?></th>
				<td>
					<label>
						<input type="checkbox" name="embeddings_enabled" value="1" <?php checked( $status['enabled'] ); ?>>
						<?php esc_html_e( 'Use vector search alongside keyword search (requires an OpenAI API key above).', 'fahad-ai-shopping-assistant-for-woocommerce' ); ?>
					</label>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="embedding_provider_type"><?php esc_html_e( 'Embeddings Provider', 'fahad-ai-shopping-assistant-for-woocommerce' ); ?></label></th>
				<td>
					<select id="embedding_provider_type" name="embedding_provider_type">
						<option value="openai" <?php selected( $type, 'openai' ); ?>><?php esc_html_e( 'OpenAI-compatible (OpenAI, Moonshot, Together, self-hosted…)', 'fahad-ai-shopping-assistant-for-woocommerce' ); ?></option>
						<option value="cohere" <?php selected( $type, 'cohere' ); ?>><?php esc_html_e( 'Cohere (best for Urdu / non-Latin scripts)', 'fahad-ai-shopping-assistant-for-woocommerce' ); ?></option>
					</select>
					<p class="description"><?php esc_html_e( 'The OpenAI-compatible option works with any provider that exposes an /embeddings endpoint — set the base URL and key below to reuse, e.g., your Moonshot or Together key.', 'fahad-ai-shopping-assistant-for-woocommerce' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="embedding_base_url"><?php esc_html_e( 'Embeddings Base URL', 'fahad-ai-shopping-assistant-for-woocommerce' ); ?></label></th>
				<td>
					<input type="text" id="embedding_base_url" name="embedding_base_url" value="<?php echo esc_attr( $base_url ); ?>" class="regular-text" placeholder="https://api.openai.com/v1">
					<p class="description"><?php esc_html_e( 'OpenAI-compatible API base (the part before /embeddings). Only used for the OpenAI-compatible provider.', 'fahad-ai-shopping-assistant-for-woocommerce' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="embedding_api_key"><?php esc_html_e( 'Embeddings API Key', 'fahad-ai-shopping-assistant-for-woocommerce' ); ?></label></th>
				<td>
					<input type="password" id="embedding_api_key" name="embedding_api_key" value="<?php echo esc_attr( $api_key ); ?>" class="regular-text" autocomplete="new-password">
					<p class="description"><?php esc_html_e( 'Key for the OpenAI-compatible endpoint above. Leave blank to reuse the OpenAI key from the Provider section.', 'fahad-ai-shopping-assistant-for-woocommerce' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="cohere_api_key"><?php esc_html_e( 'Cohere API Key', 'fahad-ai-shopping-assistant-for-woocommerce' ); ?></label></th>
				<td><input type="password" id="cohere_api_key" name="cohere_api_key" value="<?php echo esc_attr( $cohere_key ); ?>" class="regular-text" autocomplete="new-password"></td>
			</tr>
			<tr>
				<th scope="row"><label for="embedding_model"><?php esc_html_e( 'Embedding Model', 'fahad-ai-shopping-assistant-for-woocommerce' ); ?></label></th>
				<td><input type="text" id="embedding_model" name="embedding_model" value="<?php echo esc_attr( $model ); ?>" class="regular-text" placeholder="text-embedding-3-small"></td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'External vector store', 'fahad-ai-shopping-assistant-for-woocommerce' ); ?></th>
				<td>
					<p class="description" style="margin-bottom:8px;"><?php esc_html_e( 'Advanced / very large catalogs only. Leave blank to use the built-in store (MySQL/MariaDB). When a Qdrant URL is set, the index is stored and searched there instead.', 'fahad-ai-shopping-assistant-for-woocommerce' ); ?></p>
					<input type="text" name="qdrant_url" value="<?php echo esc_attr( $qdrant_url ); ?>" class="regular-text" placeholder="https://your-qdrant-host:6333" style="margin-bottom:4px;"><br>
					<input type="password" name="qdrant_key" value="<?php echo esc_attr( $qdrant_key ); ?>" class="regular-text" autocomplete="new-password" placeholder="<?php esc_attr_e( 'Qdrant API key', 'fahad-ai-shopping-assistant-for-woocommerce' ); ?>" style="margin-bottom:4px;"><br>
					<input type="text" name="qdrant_collection" value="<?php echo esc_attr( $qdrant_col ); ?>" class="regular-text" placeholder="fahad_ai_products">
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="embedding_dims"><?php esc_html_e( 'Dimensions', 'fahad-ai-shopping-assistant-for-woocommerce' ); ?></label></th>
				<td>
					<input type="number" id="embedding_dims" name="embedding_dims" min="1" step="1" value="<?php echo esc_attr( (string) $dims ); ?>" class="small-text">
					<p class="description"><?php esc_html_e( '512 is recommended — a lighter index with negligible quality loss.', 'fahad-ai-shopping-assistant-for-woocommerce' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="embed_daily_cap"><?php esc_html_e( 'Daily embedding cap', 'fahad-ai-shopping-assistant-for-woocommerce' ); ?></label></th>
				<td>
					<input type="number" id="embed_daily_cap" name="embed_daily_cap" min="0" step="100" value="<?php echo esc_attr( (string) $cap ); ?>" class="small-text">
					<p class="description"><?php esc_html_e( 'Maximum products embedded per day (0 = unlimited). Protects your bill during a bulk import.', 'fahad-ai-shopping-assistant-for-woocommerce' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Search index', 'fahad-ai-shopping-assistant-for-woocommerce' ); ?></th>
				<td>
					<p class="description" style="margin-bottom:8px;">
						<?php
						printf(
							/* translators: 1: embedded product count, 2: status word */
							esc_html__( '%1$d products indexed. Status: %2$s.', 'fahad-ai-shopping-assistant-for-woocommerce' ),
							(int) $status['count'],
							$status['stale'] ? esc_html__( 'rebuild needed (model changed)', 'fahad-ai-shopping-assistant-for-woocommerce' ) : esc_html__( 'up to date', 'fahad-ai-shopping-assistant-for-woocommerce' )
						);
						?>
					</p>
					<?php if ( (int) $status['failures'] > 0 ) : ?>
						<p class="description" style="color:#b32d2e;margin-bottom:8px;">
							<?php
							printf(
								/* translators: 1: failure count, 2: last error message */
								esc_html__( '%1$d embedding failure(s). Last error: %2$s', 'fahad-ai-shopping-assistant-for-woocommerce' ),
								(int) $status['failures'],
								esc_html( (string) $status['last_error'] )
							);
							?>
						</p>
					<?php endif; ?>
					<a href="<?php echo esc_url( wp_nonce_url( add_query_arg( 'action', self::ACTION_BUILD, $build_url ), self::ACTION_BUILD ) ); ?>" class="button">
						<?php esc_html_e( 'Build / rebuild index', 'fahad-ai-shopping-assistant-for-woocommerce' ); ?>
					</a>
					<p class="description"><?php esc_html_e( 'Queues a background job to embed every published product. Safe to run repeatedly — unchanged products are skipped.', 'fahad-ai-shopping-assistant-for-woocommerce' ); ?></p>
				</td>
			</tr>
		</table>
		<?php
	}
}
