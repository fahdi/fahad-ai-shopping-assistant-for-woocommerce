<?php
/**
 * MariaDB-native vector store, opt-in scale tier (RAG Phase 3, S3.1, #112).
 *
 * When the host runs MariaDB >= 11.7 (native VECTOR type + VEC_DISTANCE_COSINE +
 * VECTOR INDEX), this backend replaces the PHP brute-force scan with an indexed
 * nearest-neighbour query, removing the brute-force ceiling at large catalogs
 * (RAG-DESIGN.md §2.2). Auto-detected by the store factory; on any other DB
 * (e.g. MySQL 8) it reports unavailable and the default post-meta store is used,
 * so this never affects a typical install.
 *
 * INTEGRATION NOTE: the VECTOR SQL here can only be exercised against a real
 * MariaDB 11.7+ server. The demo runs MySQL 8.0, so capability detection +
 * query construction are unit-tested, but the live VECTOR path is verified by an
 * operator on MariaDB. Same interface + model-versioning as the default store.
 */

defined( 'ABSPATH' ) || exit;

final class Fahad_AI_MariaDb_Vector_Store implements Fahad_AI_Vector_Store {

	// Dedicated MariaDB vector data-access layer. Table names are class-derived (never
	// user input) so are interpolated, placeholders cannot name a table; the IN() list
	// is intval'd; vector literals + model id go through $wpdb->prepare(). These DB
	// sniffs are knowingly suppressed for this file (this class IS the cache/index).
	// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared

	public function __construct(
		private string $model,
		private int $dimensions
	) {}

	private function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'fahad_ai_vectors';
	}

	public function is_available(): bool {
		global $wpdb;
		if ( ! isset( $wpdb ) || ! is_callable( [ $wpdb, 'db_server_info' ] ) ) {
			return false;
		}
		$info = (string) $wpdb->db_server_info();
		if ( false === stripos( $info, 'mariadb' ) ) {
			return false; // native VECTOR is a MariaDB feature
		}
		if ( ! preg_match( '/(\d+)\.(\d+)/', $info, $m ) ) {
			return false;
		}
		$major = (int) $m[1];
		$minor = (int) $m[2];
		return $major > 11 || ( 11 === $major && $minor >= 7 );
	}

	public function upsert( int $product_id, array $vector, string $model, string $content_hash ): void {
		global $wpdb;
		$this->maybe_create_table();
		$literal = $this->to_literal( $vector );
		$wpdb->query(
			$wpdb->prepare(
				"INSERT INTO {$this->table()} (product_id, model, embedding, content_hash, updated_at)
				 VALUES (%d, %s, VEC_FromText(%s), %s, NOW())
				 ON DUPLICATE KEY UPDATE model = VALUES(model), embedding = VALUES(embedding), content_hash = VALUES(content_hash), updated_at = NOW()",
				$product_id,
				$model,
				$literal,
				$content_hash
			)
		);
	}

	public function delete( int $product_id ): void {
		global $wpdb;
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$this->table()} WHERE product_id = %d", $product_id ) );
	}

	public function content_hash( int $product_id ): string {
		global $wpdb;
		return (string) $wpdb->get_var( $wpdb->prepare( "SELECT content_hash FROM {$this->table()} WHERE product_id = %d", $product_id ) );
	}

	public function query( array $query_vector, int $k, array $candidate_ids ): array {
		global $wpdb;
		if ( ! $candidate_ids ) {
			return [];
		}
		$ids = implode( ',', array_map( 'intval', $candidate_ids ) ); // ints only, safe to inline
		$sql = $wpdb->prepare(
			"SELECT product_id FROM {$this->table()}
			 WHERE model = %s AND product_id IN ({$ids})
			 ORDER BY VEC_DISTANCE_COSINE(embedding, VEC_FromText(%s)) ASC
			 LIMIT %d",
			$this->model,
			$this->to_literal( $query_vector ),
			max( 0, $k )
		);
		return array_map( 'intval', (array) $wpdb->get_col( $sql ) );
	}

	public function rebuild_required(): bool {
		return (string) get_option( Fahad_AI_Postmeta_Vector_Store::OPTION_INDEX_MODEL, '' ) !== $this->model;
	}

	/** MariaDB VECTOR text literal, e.g. "[0.1,0.2,0.3]". */
	private function to_literal( array $vector ): string {
		return '[' . implode( ',', array_map( static fn( $v ) => (float) $v, $vector ) ) . ']';
	}

	private function maybe_create_table(): void {
		global $wpdb;
		$table = $this->table();
		// phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter -- $table is $wpdb->prefix + a fixed literal, never user input.
		$wpdb->query(
			"CREATE TABLE IF NOT EXISTS {$table} (
				product_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
				model VARCHAR(64) NOT NULL,
				embedding VECTOR({$this->dimensions}) NOT NULL,
				content_hash CHAR(40) NOT NULL DEFAULT '',
				updated_at DATETIME NOT NULL,
				VECTOR INDEX (embedding)
			)"
		);
	}

	// phpcs:enable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
}
