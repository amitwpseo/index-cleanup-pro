<?php
/**
 * Search spam protection: hack spam often fills Google with internal search
 * pages such as /?s=casino+bonus. Spam phrases get 410 + noindex; optionally
 * every search results page is set to noindex.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ICP_Search {

	const COUNTER = 'icp_search_blocked';

	public static function init() {
		add_action( 'wp_loaded', array( __CLASS__, 'early' ), 0 );      // ?s= requests, before WordPress runs the search query.
		add_action( 'template_redirect', array( __CLASS__, 'late' ), 1 ); // /search/phrase/ style URLs.
	}

	private static function mode() {
		$m = (string) ICP_DB::setting( 'search_mode' );
		return in_array( $m, array( 'off', 'spam', 'all' ), true ) ? $m : 'spam';
	}

	private static function is_spam_phrase( $q ) {
		$q = trim( (string) $q );
		if ( '' === $q ) {
			return false;
		}
		list( $score ) = ICP_Spam::score( $q, true );
		return $score >= max( 20, min( 100, (int) ICP_DB::setting( 'search_threshold' ) ) );
	}

	private static function method() {
		return isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( $_SERVER['REQUEST_METHOD'] ) : 'GET'; // phpcs:ignore
	}

	public static function early() {
		if ( 'off' === self::mode() || is_admin() || wp_doing_ajax() || wp_doing_cron() || ( defined( 'WP_CLI' ) && WP_CLI ) ) {
			return;
		}
		$method = self::method();
		if ( ! in_array( $method, array( 'GET', 'HEAD' ), true ) || ! isset( $_GET['s'] ) || ! is_string( $_GET['s'] ) ) { // phpcs:ignore
			return;
		}
		if ( self::is_spam_phrase( wp_unslash( $_GET['s'] ) ) ) { // phpcs:ignore
			self::block( $method );
		}
	}

	public static function late() {
		if ( 'off' === self::mode() || is_admin() || ! is_search() ) {
			return;
		}
		$method = self::method();
		if ( self::is_spam_phrase( get_search_query( false ) ) ) {
			self::block( $method );
		}
		if ( 'all' === self::mode() && ! headers_sent() ) {
			header( 'X-Robots-Tag: noindex, follow', true );
		}
	}

	private static function block( $method ) {
		update_option( self::COUNTER, (int) get_option( self::COUNTER, 0 ) + 1, false );
		ICP_Frontend::serve_gone( $method );
	}

	public static function blocked_count() {
		return (int) get_option( self::COUNTER, 0 );
	}
}
