<?php
/**
 * Vector store factory (RAG Phase 3, #112).
 *
 * Picks the best available backend for the host: the MariaDB-native store when
 * MariaDB >= 11.7 is detected, otherwise the default post-meta store. The
 * `dukandaar_vector_store` filter lets an add-on swap in an external backend
 * (e.g. Qdrant, #113) without touching core. Default-safe: a typical MySQL host
 * always gets the post-meta store.
 */

defined( 'ABSPATH' ) || exit;

final class Dukandaar_Vector_Stores {

	public static function resolve( string $model, int $dimensions ): Dukandaar_Vector_Store {
		$mariadb = new Dukandaar_MariaDb_Vector_Store( $model, $dimensions );
		$default = $mariadb->is_available()
			? $mariadb
			: new Dukandaar_Postmeta_Vector_Store( $model, $dimensions );

		/**
		 * Filter the active vector store backend.
		 *
		 * @param Dukandaar_Vector_Store $default     The auto-detected backend.
		 * @param string                $model       Active embedding model id.
		 * @param int                   $dimensions  Vector dimensionality.
		 */
		return apply_filters( 'dukandaar_vector_store', $default, $model, $dimensions );
	}
}
