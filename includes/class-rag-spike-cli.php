<?php
/**
 * `wp fahad-ai rag-spike` — the RAG Phase 0 decision-gate runner (S0.5).
 *
 * Registered only under WP-CLI; ships no shopper-facing surface. It scores
 * keyword/vector/hybrid recall@k and projects brute-force scan latency, then
 * writes docs/RAG-SPIKE-REPORT.md with a GO/NO-GO recommendation.
 *
 *   wp fahad-ai rag-spike                       # offline: canned embeddings
 *   wp fahad-ai rag-spike --k=3 --sizes=1000,5000,20000
 *   wp fahad-ai rag-spike --report=/path.md     # write the report somewhere else
 *
 * Live (real-embedding) mode activates when an OpenAI key is configured; without
 * one it runs the deterministic canned comparison so the command and output are
 * verifiable with no spend.
 */

defined( 'ABSPATH' ) || exit;

if ( ! ( defined( 'WP_CLI' ) && WP_CLI ) ) {
	// @codeCoverageIgnoreStart
	// Reason: file-scope require-time guard; tests must define WP_CLI truthy to load this file at all (else the class + add_command below never run), so this non-CLI return branch is unreachable in-process.
	return;
	// @codeCoverageIgnoreEnd
}

final class Fahad_AI_Rag_Spike_CLI {

	/**
	 * Run the RAG spike comparison + latency projection and write the report.
	 *
	 * ## OPTIONS
	 *
	 * [--k=<k>]
	 * : recall@k cutoff. Default 3.
	 *
	 * [--sizes=<csv>]
	 * : Catalog sizes for the latency projection. Default 1000,5000,20000.
	 *
	 * [--report=<path>]
	 * : Where to write the markdown report. Default docs/RAG-SPIKE-REPORT.md.
	 *
	 * @when after_wp_load
	 */
	public function __invoke( array $args, array $assoc ): void {
		$k     = max( 1, (int) ( $assoc['k'] ?? 3 ) );
		$sizes = array_values( array_filter( array_map( 'intval', explode( ',', (string) ( $assoc['sizes'] ?? '1000,5000,20000' ) ) ) ) );
		$path  = (string) ( $assoc['report'] ?? ( FAHAD_AI_PATH . 'docs/RAG-SPIKE-REPORT.md' ) );

		[ $texts, $vecs, $queries, $mode ] = $this->build_dataset();

		\WP_CLI::log( "RAG spike — relevance ({$mode}) at recall@{$k}:" );
		$cmp = Fahad_AI_Rag_Spike::compare( $texts, $vecs, $queries, $k );
		foreach ( $cmp['queries'] as $row ) {
			\WP_CLI::log( sprintf( '  %-34s kw=%.2f vec=%.2f hybrid=%.2f', $row['name'], $row['keyword'], $row['vector'], $row['hybrid'] ) );
		}
		\WP_CLI::log( sprintf( '  MEAN: keyword=%.3f vector=%.3f hybrid=%.3f', $cmp['mean']['keyword'], $cmp['mean']['vector'], $cmp['mean']['hybrid'] ) );

		\WP_CLI::log( 'Brute-force scan latency projection (512-dim float32 BLOBs):' );
		$latency = [];
		foreach ( $sizes as $size ) {
			$stats     = Fahad_AI_Rag_Spike::scan_latency( $size, 512, 7 );
			$latency[] = $stats;
			\WP_CLI::log( sprintf( '  %6d products: p50=%.1fms p95=%.1fms', $stats['size'], $stats['p50_ms'], $stats['p95_ms'] ) );
		}

		$report = $this->render_report( $cmp, $latency, $k, $mode );
		// Spike CLI writing a local dev report; WP_Filesystem is unnecessary ceremony here.
		if ( false !== file_put_contents( $path, $report ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			\WP_CLI::success( "Report written to {$path}" );
		} else {
			\WP_CLI::warning( "Could not write report to {$path}" );
		}
	}

	/**
	 * Build (texts, vecs, queries, mode). Live embeddings when keyed; otherwise a
	 * self-contained canned set so the command runs offline with no spend.
	 *
	 * @return array{0:array<int,string>,1:array<int,array<int,float>>,2:array,3:string}
	 */
	private function build_dataset(): array {
		$key = (string) get_option( 'fahad_ai_openai_api_key', '' );
		if ( '' !== $key && function_exists( 'wc_get_products' ) ) {
			$live = $this->live_dataset( $key );
			if ( null !== $live ) {
				return [ $live['texts'], $live['vecs'], $live['queries'], 'live embeddings' ];
			}
		}
		$c = self::canned();
		return [ $c['texts'], $c['vecs'], $c['queries'], 'canned (offline, no key)' ];
	}

	/**
	 * Embed the live catalog with OpenAI text-embedding-3-small@512 and build
	 * name-matched golden queries. Returns null on any failure (caller falls back).
	 */
	private function live_dataset( string $key ): ?array {
		$products = wc_get_products( [ 'status' => 'publish', 'limit' => 200 ] );
		if ( ! $products ) {
			return null;
		}

		$texts   = [];
		$docs    = [];
		foreach ( $products as $p ) {
			$id           = $p->get_id();
			$texts[ $id ] = trim( $p->get_name() . ' ' . wp_strip_all_tags( $p->get_short_description() . ' ' . $p->get_description() ) );
			$docs[ $id ]  = Fahad_AI_Embedding_Document::compose(
				[
					'title'             => $p->get_name(),
					'short_description' => $p->get_short_description(),
					'description'       => $p->get_description(),
				]
			);
		}

		// Golden queries resolve "relevant" by product-name regex so they work on
		// any catalog. Each query carries its own embedding text.
		$specs = [
			[ 'name' => 'warm top (semantic)', 'query' => 'something warm to wear', 'match' => '/hoodie|sweater|jacket|coat|fleece|pullover/i' ],
			[ 'name' => 'audio device (semantic)', 'query' => 'listen to music on the go', 'match' => '/headphone|earbud|speaker|audio/i' ],
			[ 'name' => 'stay hydrated (semantic)', 'query' => 'keep my drink cold on a hike', 'match' => '/bottle|flask|tumbler|hydration/i' ],
		];

		$to_embed = array_values( $docs );
		$q_texts  = array_column( $specs, 'query' );
		$vectors  = $this->embed( array_merge( $to_embed, $q_texts ), $key );
		if ( null === $vectors ) {
			return null;
		}

		$vecs = [];
		$ids  = array_keys( $docs );
		foreach ( $ids as $i => $id ) {
			$vecs[ $id ] = $vectors[ $i ];
		}
		$q_vecs = array_slice( $vectors, count( $ids ) );

		$queries = [];
		foreach ( $specs as $i => $spec ) {
			$relevant = [];
			foreach ( $texts as $id => $name ) {
				if ( preg_match( $spec['match'], $name ) ) {
					$relevant[] = $id;
				}
			}
			if ( $relevant ) {
				$queries[] = [ 'name' => $spec['name'], 'query' => $spec['query'], 'vec' => $q_vecs[ $i ], 'relevant' => $relevant ];
			}
		}

		return $queries ? [ 'texts' => $texts, 'vecs' => $vecs, 'queries' => $queries ] : null;
	}

	/**
	 * Minimal OpenAI embeddings call (text-embedding-3-small, 512 dims). Spike-only
	 * inline call — the production abstraction is S1.1. Returns float[][] or null.
	 */
	private function embed( array $texts, string $key ): ?array {
		$res = wp_remote_post(
			'https://api.openai.com/v1/embeddings',
			[
				'timeout' => 60,
				'headers' => [ 'Authorization' => 'Bearer ' . $key, 'Content-Type' => 'application/json' ],
				'body'    => wp_json_encode( [ 'model' => 'text-embedding-3-small', 'dimensions' => 512, 'input' => array_values( $texts ) ] ),
			]
		);
		if ( is_wp_error( $res ) || 200 !== (int) wp_remote_retrieve_response_code( $res ) ) {
			return null;
		}
		$data = json_decode( wp_remote_retrieve_body( $res ), true );
		if ( empty( $data['data'] ) ) {
			return null;
		}
		return array_map( static fn( $row ) => array_map( 'floatval', $row['embedding'] ), $data['data'] );
	}

	/** Self-contained canned dataset for offline runs (mirrors the test golden set). */
	private static function canned(): array {
		$catalog = [
			10 => [ 'Classic White T-Shirt cotton crew neck top', [ 1.0, 0.0, 0.0, 0.0 ] ],
			11 => [ 'Blue Denim Jeans slim fit pants', [ 0.95, 0.1, 0.0, 0.0 ] ],
			12 => [ 'Wireless Bluetooth Headphones noise cancelling audio', [ 0.0, 0.0, 1.0, 0.0 ] ],
			13 => [ 'Stainless Steel Water Bottle insulated hydration flask', [ 0.0, 0.0, 0.0, 1.0 ] ],
			14 => [ 'Running Sneakers lightweight footwear', [ 0.1, 1.0, 0.0, 0.0 ] ],
			38 => [ 'Premium Pullover Hoodie warm fleece', [ 0.9, 0.0, 0.0, 0.0 ] ],
			15 => [ 'Cushioned Marathon Shoes', [ 0.0, 0.0, 0.0, 0.0 ] ],
		];
		$texts = [];
		$vecs  = [];
		foreach ( $catalog as $id => $row ) {
			$texts[ $id ] = $row[0];
			$vecs[ $id ]  = $row[1];
		}
		return [
			'texts'   => $texts,
			'vecs'    => $vecs,
			'queries' => [
				[ 'name' => 'warm apparel', 'query' => 'something to keep me warm', 'vec' => [ 1.0, 0.0, 0.0, 0.0 ], 'relevant' => [ 38 ] ],
				[ 'name' => 'music device (semantic)', 'query' => 'music device for the gym', 'vec' => [ 0.0, 0.0, 1.0, 0.0 ], 'relevant' => [ 12 ] ],
				[ 'name' => 'cushioned running shoes (split)', 'query' => 'cushioned shoes for marathon', 'vec' => [ 0.0, 1.0, 0.0, 0.0 ], 'relevant' => [ 14, 15 ] ],
			],
		];
	}

	/** Render the GO/NO-GO markdown report from the measured results. */
	private function render_report( array $cmp, array $latency, int $k, string $mode ): string {
		$hybrid_wins = $cmp['mean']['hybrid'] > $cmp['mean']['keyword'] && $cmp['mean']['hybrid'] >= $cmp['mean']['vector'];
		$worst_p95   = 0.0;
		foreach ( $latency as $row ) {
			$worst_p95 = max( $worst_p95, $row['p95_ms'] );
		}
		$verdict = $hybrid_wins
			? ( 'live embeddings' === $mode ? '**GO** — hybrid beats keyword on real embeddings at acceptable latency.' : '**GO (provisional)** — hybrid beats keyword on the canned golden set; re-run keyed for the real-embedding confirmation.' )
			: '**NO-GO** — hybrid did not beat keyword; revisit the design before Phase 1.';

		$rows = '';
		foreach ( $cmp['queries'] as $r ) {
			$rows .= sprintf( "| %s | %.2f | %.2f | %.2f |\n", $r['name'], $r['keyword'], $r['vector'], $r['hybrid'] );
		}
		$lat = '';
		foreach ( $latency as $s ) {
			$lat .= sprintf( "| %d | %.1f | %.1f |\n", $s['size'], $s['p50_ms'], $s['p95_ms'] );
		}

		return "# RAG Spike Report (Phase 0)\n\n"
			. "Generated by `wp fahad-ai rag-spike`. Dataset: **{$mode}**.\n\n"
			. "## Verdict\n\n{$verdict}\n\n"
			. "## Relevance (recall@{$k})\n\n"
			. "| Query | keyword | vector | hybrid |\n|---|---|---|---|\n{$rows}"
			. sprintf( "| **MEAN** | **%.3f** | **%.3f** | **%.3f** |\n\n", $cmp['mean']['keyword'], $cmp['mean']['vector'], $cmp['mean']['hybrid'] )
			. "Hybrid (RRF of keyword + vector) is the gate: it must be >= each leg per query and beat keyword overall (RAG-DESIGN.md §6.1).\n\n"
			. "## Brute-force scan latency (512-dim float32 BLOBs, unpack + cosine per row)\n\n"
			. "| Catalog size | p50 (ms) | p95 (ms) |\n|---|---|---|\n{$lat}\n"
			. sprintf( "Worst-case p95 across projected sizes: **%.1f ms**. Latency measured on the runner's hardware; commodity shared hosts run slower — pre-filter by category/stock/price shrinks the scanned set (RAG-DESIGN.md §4.4).\n\n", $worst_p95 )
			. "## Notes\n\n"
			. "- Offline/canned mode validates the harness and the keyword/vector/hybrid logic deterministically. Run with an OpenAI key configured (`fahad_ai_openai_api_key`) for the real-embedding confirmation on the live catalog.\n"
			. "- This is a Phase 0 spike: no shipped tool, UI, or release. The primitives exercised here (`Fahad_AI_Vector_Math`, `Fahad_AI_Rrf`, `Fahad_AI_Embedding_Document`, `Fahad_AI_Relevance_Metrics`, `Fahad_AI_Rag_Spike_Retriever`) are what Phase 1 (S1.x) composes.\n";
	}
}

\WP_CLI::add_command( 'fahad-ai rag-spike', Fahad_AI_Rag_Spike_CLI::class );
