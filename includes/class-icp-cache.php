<?php
/**
 * Page-cache purging for common cache plugins.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ICP_Cache {

	/** Purge full page cache once. Returns list of caches purged. */
	public static function purge_all() {
		if ( ! ICP_DB::setting( 'purge_cache' ) ) {
			return array();
		}
		$done = array();

		// WP Fastest Cache.
		if ( function_exists( 'wpfc_clear_all_cache' ) ) {
			wpfc_clear_all_cache( true );
			$done[] = 'WP Fastest Cache';
		} elseif ( isset( $GLOBALS['wp_fastest_cache'] ) && method_exists( $GLOBALS['wp_fastest_cache'], 'deleteCache' ) ) {
			$GLOBALS['wp_fastest_cache']->deleteCache( true );
			$done[] = 'WP Fastest Cache';
		}

		// LiteSpeed Cache.
		if ( defined( 'LSCWP_V' ) || class_exists( 'LiteSpeed\Core' ) ) {
			do_action( 'litespeed_purge_all' );
			$done[] = 'LiteSpeed Cache';
		}

		// W3 Total Cache.
		if ( function_exists( 'w3tc_flush_all' ) ) {
			w3tc_flush_all();
			$done[] = 'W3 Total Cache';
		}

		// WP Rocket.
		if ( function_exists( 'rocket_clean_domain' ) ) {
			rocket_clean_domain();
			$done[] = 'WP Rocket';
		}

		// WP Super Cache.
		if ( function_exists( 'wp_cache_clear_cache' ) ) {
			wp_cache_clear_cache();
			$done[] = 'WP Super Cache';
		}

		return $done;
	}

	/** Headers/constants that stop caches storing the 410 response. */
	public static function no_cache_headers() {
		if ( ! defined( 'DONOTCACHEPAGE' ) ) {
			define( 'DONOTCACHEPAGE', true );
		}
		if ( ! headers_sent() ) {
			nocache_headers();
			header( 'X-LiteSpeed-Cache-Control: no-cache' );
		}
	}
}
