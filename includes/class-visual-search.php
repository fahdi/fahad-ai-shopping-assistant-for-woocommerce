<?php
defined( 'ABSPATH' ) || exit;

/**
 * Visual / image search — "shop the look" (issue #63), the pluggable VISION RETRIEVER
 * SEAM plus upload validation and live product resolution.
 *
 * WHAT THIS IS (and is not). This class is the boundary that lets a VISION-embeddings
 * backend plug into product discovery from an IMAGE, WITHOUT coupling it to core. It is
 * the exact same shape as the semantic (text) retriever seam (issue #60,
 * Fahad_AI_Semantic_Search) — a filter returning ranked product IDs that are resolved
 * LIVE — but the input is an uploaded image instead of a free-text query. It does NOT
 * itself embed images or talk to ANY vision API: real "find visually-similar products"
 * needs an external vision-embeddings provider (CLIP-style image embeddings + a vector
 * index over the catalog), which is a separate dependency and key and is NOT bundled.
 * NO live vision-API call is made anywhere in this plugin.
 *
 * Until a provider registers a retriever on the filter below, this seam returns a graceful
 * "visual search isn't available" result — never an error spew, never a fatal. So the
 * capability stays dormant and is activated cleanly by an add-on, mirroring the
 * wallet-decoupling pattern (`fahad_ai_wallet_provider`) and the semantic seam
 * (`fahad_ai_semantic_retriever`): "AI + vision search" is a swappable bundle, not hard
 * core coupling.
 *
 * THE CONTRACT. A provider registers a retriever on:
 *
 *     apply_filters( 'fahad_ai_visual_retriever', null, array $image, array $filters )
 *
 * Two registration shapes are accepted (mirroring the #60 seam — use whichever fits):
 *
 *   1. Return the ranked product IDs directly. The filter is applied per request, so the
 *      provider can embed `$image`, run its vector lookup (pre-filtered by `$filters`),
 *      and return the top-k product IDs, best first:
 *
 *          add_filter( 'fahad_ai_visual_retriever', function ( $ids, $image, $filters ) {
 *              return My_Vision_Backend::rank( $image, $filters ); // int[] best-first
 *          }, 10, 3 );
 *
 *   2. Return a callable retriever `fn( array $image, array $filters ): int[]` (handy when
 *      the provider wants to register once, not re-resolve per call):
 *
 *          add_filter( 'fahad_ai_visual_retriever', fn() => [ $backend, 'rank' ] );
 *
 * `$image` is the VALIDATED upload descriptor (`tmp_name`/`url`/`data`, `type`, `size`,
 * `name`) — the provider decides how to read the bytes (it never gets unvalidated input).
 * `$filters` carries structured constraints — `category` (string), `min_price`/`max_price`
 * (float), `limit` (int) — so a provider can pre-filter its scan and bound its result
 * count. The retriever returns IDs ONLY; it must never return price/stock.
 *
 * UPLOAD VALIDATION (security, before anything else). Every image is validated BEFORE the
 * seam is consulted: a present, non-empty, size-bounded payload with an allowed image MIME
 * type. An oversized image is a 413, an invalid/unsafe MIME a 415, a missing/zero-byte
 * payload a 400. The size ceiling defaults to 5 MB (`fahad_ai_visual_max_bytes`) and the
 * MIME allowlist to jpeg/png/webp/gif (`fahad_ai_visual_allowed_mimes`) — a COST CEILING
 * and a content-safety guard. Validation failing short-circuits: no retrieval, no
 * resolution.
 *
 * NO RETENTION OF THE USER IMAGE (default). The validated image is handed to the seam for
 * the duration of the request and is NEVER moved, copied, or persisted by this class
 * (default: do not store). A provider that wants to retain an image for re-ranking must do
 * so under its own explicit consent flow; core stores nothing and logs no PII.
 *
 * LIVE TRUTH IS NEVER CACHED. The returned IDs are resolved here through wc_get_product()
 * at call time and shaped by Fahad_AI_Tools::format_product_summary(), which reads price /
 * sale / stock / rating straight from the live WC_Product. So even though retrieval used a
 * (potentially stale) vector index, the price and stock the shopper sees are always
 * current — never embedded or cached. Products that no longer resolve, or are not
 * visible/published, are dropped so the index can lag live truth safely (the #60
 * invariant, applied identically here).
 *
 * GRACEFUL DEGRADATION. No retriever → `available => false` ("not available"). A retriever
 * that returns nothing, a malformed return, or a throwing retriever → `available => true`
 * with an honest "no match". The shopper never sees a visual-search error.
 */
final class Fahad_AI_Visual_Search {

	private static $instance = null;

	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {}

	/**
	 * Register the visual-search REST route.
	 *
	 * The route is gated by the SAME boundary as the chat endpoints — the nonce +
	 * rate-limit `authorize_request` permission callback supplied by the bootstrap — so a
	 * billable vision lookup is CSRF-protected and rate-capped exactly like a chat turn.
	 * The route is wired by the plugin bootstrap (mirroring how the WhatsApp channel
	 * self-registers), so this class owns its own endpoint.
	 *
	 * @param callable $permission_callback The shared authorize_request gate.
	 */
	public function register_routes( callable $permission_callback ): void {
		register_rest_route( 'fahad-ai/v1', '/visual-search', [
			'methods'             => 'POST',
			'callback'            => [ $this, 'handle_search' ],
			'permission_callback' => $permission_callback,
		] );
	}

	/**
	 * REST handler: resolve an uploaded image to visually-similar products.
	 *
	 * Reads the uploaded file from the multipart `image` part and the optional structured
	 * filters (category/min_price/max_price/limit) from the request, then runs the search
	 * core. Returns the WP_Error from validation straight to the client (413/415/400), or a
	 * 200 response carrying the result shape from search(). NO live vision call is made.
	 *
	 * @param WP_REST_Request $request The multipart upload request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function handle_search( WP_REST_Request $request ) {
		$files = $request->get_file_params();
		$image = is_array( $files ) && isset( $files['image'] ) && is_array( $files['image'] )
			? $files['image']
			: [];

		$filters = [];

		$category = $request->get_param( 'category' );
		if ( is_string( $category ) && '' !== trim( $category ) ) {
			$filters['category'] = sanitize_text_field( $category );
		}
		$min = $request->get_param( 'min_price' );
		if ( is_numeric( $min ) ) {
			$filters['min_price'] = (float) $min;
		}
		$max = $request->get_param( 'max_price' );
		if ( is_numeric( $max ) ) {
			$filters['max_price'] = (float) $max;
		}
		$limit = $request->get_param( 'limit' );
		if ( is_numeric( $limit ) ) {
			$filters['limit'] = absint( $limit );
		}

		$result = $this->search( $image, $filters );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return rest_ensure_response( $result );
	}

	/**
	 * Core "shop the look": validate the image, ask the registered vision retriever for
	 * ranked product IDs, and resolve them LIVE into card summaries.
	 *
	 * This is the public, REST-independent entry point (so it is unit-testable without a
	 * WP_REST_Request). It returns:
	 *
	 *   - a WP_Error when the image is missing / oversized / an invalid MIME (validation
	 *     fails BEFORE the seam is consulted);
	 *   - `[ 'available' => false, 'found' => 0, 'products' => [], 'message' => … ]` when no
	 *     vision provider is registered (graceful "not available");
	 *   - `[ 'available' => true, 'found' => 0, 'products' => [], 'message' => … ]` when a
	 *     provider is registered but produced no usable, still-visible match (graceful
	 *     "no match");
	 *   - `[ 'available' => true, 'found' => N, 'products' => [ …cards… ] ]` on success.
	 *
	 * @param array $image   The uploaded-image descriptor (tmp_name/url/data, type, size).
	 * @param array $filters Structured constraints: category (string), min_price/max_price
	 *                       (float), limit (int).
	 * @return array|WP_Error The result shape above, or a validation WP_Error.
	 */
	public function search( array $image, array $filters = [] ) {
		// 1. Validate FIRST — security and cost guard. A bad upload never reaches the seam.
		$valid = $this->validate_image( $image );
		if ( is_wp_error( $valid ) ) {
			return $valid;
		}

		// Normalise the structured constraints to clean types so the seam always hands the
		// provider float prices / int limit regardless of how the caller supplied them.
		$filters = $this->normalize_filters( $filters );

		// 2. Ask the registered vision retriever for ranked product IDs. A null/false return
		// (no provider) is distinguished from an empty array (provider present, no match):
		// the former is "not available", the latter is a graceful "no match".
		$retriever = $this->raw_retriever( $image, $filters );

		if ( null === $retriever || false === $retriever ) {
			return [
				'available' => false,
				'found'     => 0,
				'products'  => [],
				'message'   => __( 'Visual search is not available right now.', 'fahad-ai-shopping-assistant-for-woocommerce' ),
			];
		}

		$ids       = $this->normalize_ids( $retriever, $image, $filters );
		$products  = $this->resolve_live( $ids, $filters );

		if ( empty( $products ) ) {
			return [
				'available' => true,
				'found'     => 0,
				'products'  => [],
				'message'   => __( "We couldn't find a visual match. Try a clearer photo or a different angle.", 'fahad-ai-shopping-assistant-for-woocommerce' ),
			];
		}

		return [
			'available' => true,
			'found'     => count( $products ),
			'products'  => $products,
		];
	}

	/**
	 * Normalise structured constraints into clean types before they reach the seam.
	 *
	 * Keeps only the recognised keys and coerces each to the type the contract promises a
	 * provider — category (non-empty string), min_price/max_price (float), limit (positive
	 * int) — dropping anything malformed. So whether the caller passed strings (from a REST
	 * form) or ints, the retriever always sees the documented shape.
	 *
	 * @param array $filters Raw caller-supplied constraints.
	 * @return array Normalised constraints (only recognised, well-typed keys).
	 */
	private function normalize_filters( array $filters ): array {
		$out = [];

		if ( isset( $filters['category'] ) && is_string( $filters['category'] ) && '' !== trim( $filters['category'] ) ) {
			$out['category'] = trim( $filters['category'] );
		}
		if ( isset( $filters['min_price'] ) && is_numeric( $filters['min_price'] ) ) {
			$out['min_price'] = (float) $filters['min_price'];
		}
		if ( isset( $filters['max_price'] ) && is_numeric( $filters['max_price'] ) ) {
			$out['max_price'] = (float) $filters['max_price'];
		}
		if ( isset( $filters['limit'] ) && is_numeric( $filters['limit'] ) ) {
			$limit = (int) $filters['limit'];
			if ( $limit > 0 ) {
				$out['limit'] = $limit;
			}
		}

		return $out;
	}

	/**
	 * Validate an uploaded image: present, non-empty, size-bounded, allowed MIME.
	 *
	 * Order is fail-closed: a missing payload is a 400, a zero-byte or unreadable payload a
	 * 400, an oversized payload a 413, and a disallowed/unsafe MIME a 415. The size ceiling
	 * and the MIME allowlist are filterable so a merchant can tighten/loosen them, but the
	 * defaults are conservative (5 MB; jpeg/png/webp/gif) — a cost ceiling AND a
	 * content-safety guard against disguised non-image uploads.
	 *
	 * @param array $image The uploaded-image descriptor.
	 * @return true|WP_Error True when the image is acceptable, else a WP_Error with a status.
	 */
	private function validate_image( array $image ) {
		// Must carry a usable reference to image bytes: an uploaded temp file, a URL, or an
		// inline data ref. No reference at all → a bad request (never a fatal on a missing key).
		$has_ref = ( isset( $image['tmp_name'] ) && '' !== (string) $image['tmp_name'] )
			|| ( isset( $image['url'] ) && '' !== (string) $image['url'] )
			|| ( isset( $image['data'] ) && '' !== (string) $image['data'] );

		if ( ! $has_ref ) {
			return new WP_Error(
				'fahad_ai_visual_no_image',
				__( 'No image was provided. Please upload a photo to search.', 'fahad-ai-shopping-assistant-for-woocommerce' ),
				[ 'status' => 400 ]
			);
		}

		// A declared size is required and must be positive — a zero-byte / truncated upload
		// is rejected before any provider sees it.
		$size = isset( $image['size'] ) ? (int) $image['size'] : 0;
		if ( $size <= 0 ) {
			return new WP_Error(
				'fahad_ai_visual_empty_image',
				__( 'The uploaded image was empty. Please try again.', 'fahad-ai-shopping-assistant-for-woocommerce' ),
				[ 'status' => 400 ]
			);
		}

		// Size ceiling (cost guard). Default 5 MB; filterable. Oversized → 413.
		$max_bytes = (int) apply_filters( 'fahad_ai_visual_max_bytes', 5 * 1024 * 1024 );
		if ( $max_bytes > 0 && $size > $max_bytes ) {
			return new WP_Error(
				'fahad_ai_visual_too_large',
				sprintf(
					/* translators: %s: maximum upload size, e.g. "5 MB". */
					__( 'That image is too large. Please upload an image under %s.', 'fahad-ai-shopping-assistant-for-woocommerce' ),
					$this->format_bytes( $max_bytes )
				),
				[ 'status' => 413 ]
			);
		}

		// MIME allowlist (content-safety guard against disguised/unsafe uploads). Default to
		// the common web image types; filterable. A disallowed/empty type → 415.
		$allowed = apply_filters( 'fahad_ai_visual_allowed_mimes', [
			'image/jpeg',
			'image/png',
			'image/webp',
			'image/gif',
		] );
		$allowed = is_array( $allowed ) ? $allowed : [];
		$type    = isset( $image['type'] ) ? strtolower( trim( (string) $image['type'] ) ) : '';

		if ( '' === $type || ! in_array( $type, $allowed, true ) ) {
			return new WP_Error(
				'fahad_ai_visual_bad_type',
				__( 'That file type is not supported. Please upload a JPEG, PNG, WebP, or GIF image.', 'fahad-ai-shopping-assistant-for-woocommerce' ),
				[ 'status' => 415 ]
			);
		}

		return true;
	}

	/**
	 * Apply the retriever filter and return its RAW value (null/false/array/callable).
	 *
	 * Kept separate from normalization so the caller can distinguish "no provider"
	 * (null/false) from "provider present, no match" (empty array) — the two map to the
	 * "not available" vs "no match" graceful states.
	 *
	 * @param array $image   The validated image descriptor handed to the provider.
	 * @param array $filters Structured constraints handed to the provider.
	 * @return mixed Null by default; IDs or a callable when a provider is registered.
	 */
	private function raw_retriever( array $image, array $filters ) {
		/**
		 * Filter: register a VISION product retriever (issue #63, "shop the look").
		 *
		 * Default null ⇒ no vision backend ⇒ visual search reports "not available". A
		 * provider returns either ranked product IDs (int[]) or a callable
		 * `fn( array $image, array $filters ): int[]`. See the class docblock for the full
		 * contract. Real ranking requires an external vision-embeddings provider; NONE ships
		 * with the plugin and NO live vision API is called by core.
		 *
		 * @param mixed $retriever Null by default; IDs or a callable when registered.
		 * @param array $image     The validated uploaded-image descriptor.
		 * @param array $filters   Structured constraints (category/price/limit).
		 */
		return apply_filters( 'fahad_ai_visual_retriever', null, $image, $filters );
	}

	/**
	 * Normalise a retriever's return into a clean list of unique positive product IDs in
	 * rank order.
	 *
	 * Accepts either registration shape (direct IDs, or a callable retriever — see the class
	 * docblock) and is defensive about everything a third-party provider might return: a
	 * non-array, a callable that throws, duplicate or non-numeric entries. Anything unusable
	 * collapses to [] so the caller renders a graceful no-match instead of erroring.
	 *
	 * @param mixed $retriever The raw filter return (array or callable).
	 * @param array $image     The image descriptor (passed to a callable retriever).
	 * @param array $filters   Structured constraints (passed to a callable retriever).
	 * @return int[] Unique positive product IDs, best first (possibly empty).
	 */
	private function normalize_ids( $retriever, array $image, array $filters ): array {
		// Shape 2: a callable retriever resolved per request. Isolated like the semantic
		// seam isolates its callable — a throwing provider degrades to a graceful no-match.
		if ( is_callable( $retriever ) ) {
			try {
				$retriever = $retriever( $image, $filters );
			} catch ( \Throwable $e ) {
				return [];
			}
		}

		if ( ! is_array( $retriever ) ) {
			return [];
		}

		$ids = [];
		foreach ( $retriever as $candidate ) {
			if ( ! is_numeric( $candidate ) ) {
				continue;
			}
			$id = (int) $candidate;
			if ( $id > 0 ) {
				$ids[ $id ] = $id; // de-dupe while preserving first-seen rank order.
			}
		}

		return array_values( $ids );
	}

	/**
	 * Resolve ranked product IDs LIVE into card summaries, dropping anything unbuyable.
	 *
	 * Each id is resolved through wc_get_product() at call time and shaped by
	 * Fahad_AI_Tools::format_product_summary(), which reads price/stock straight from the
	 * live WC_Product — the retriever supplied ONLY the id, never any cached price/stock.
	 * An id that no longer resolves, or resolves to a non-visible/unpublished product, is
	 * skipped (the index can lag live truth). The optional `limit` bounds the result count.
	 *
	 * @param int[] $ids     Ranked product IDs, best first.
	 * @param array $filters Structured constraints (reads `limit`).
	 * @return array<int, array> Card summaries in rank order (possibly empty).
	 */
	private function resolve_live( array $ids, array $filters ): array {
		if ( empty( $ids ) ) {
			return [];
		}

		$limit     = isset( $filters['limit'] ) ? max( 1, (int) $filters['limit'] ) : 0;
		$tools     = Fahad_AI_Tools::instance();
		$summaries = [];

		foreach ( $ids as $id ) {
			$product = wc_get_product( $id );

			// The index can lag live truth: an id may no longer resolve, or be
			// unpublished/hidden now. Skip those — never surface an unbuyable product.
			if ( ! $product instanceof WC_Product || ! $product->is_visible() ) {
				continue;
			}

			// format_product_summary reads price/stock LIVE from this product.
			$summaries[] = $tools->format_product_summary( $product );

			if ( $limit && count( $summaries ) >= $limit ) {
				break;
			}
		}

		return $summaries;
	}

	/**
	 * Render a byte count as a short human string for the "too large" message (e.g. 5 MB).
	 * Kept dependency-free (no size_format) so it works identically in unit tests.
	 */
	private function format_bytes( int $bytes ): string {
		if ( $bytes >= 1024 * 1024 ) {
			return rtrim( rtrim( number_format( $bytes / ( 1024 * 1024 ), 1 ), '0' ), '.' ) . ' MB';
		}
		if ( $bytes >= 1024 ) {
			return rtrim( rtrim( number_format( $bytes / 1024, 1 ), '0' ), '.' ) . ' KB';
		}
		return $bytes . ' B';
	}
}
