<?php
/**
 * 404 monitor: records URLs that were requested but do not exist, scores them
 * for spam and lets an admin add them to the dead list in one click.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ICP_Monitor {

	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'icp_404';
	}

	public static function init() {
		add_action( 'template_redirect', array( __CLASS__, 'maybe_log' ), 999 );
	}

	public static function maybe_log() {
		if ( is_admin() || ! is_404() || ! ICP_DB::setting( 'monitor_enabled' ) ) {
			return;
		}
		$method = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( $_SERVER['REQUEST_METHOD'] ) : 'GET'; // phpcs:ignore
		if ( 'GET' !== $method || is_feed() ) {
			return;
		}
		$path = ICP_Util::request_path();
		if ( '' === $path || strlen( $path ) > 500 || ICP_Util::is_protected( $path ) ) {
			return;
		}
		// Images, scripts and other files are not pages.
		if ( preg_match( '/\.(jpe?g|png|gif|webp|avif|svg|ico|css|js|map|woff2?|ttf|eot|mp4|webm|mp3|pdf|zip|txt|json|xml)$/i', $path ) ) {
			return;
		}
		$ua = isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : ''; // phpcs:ignore
		self::log( $path, $ua );
	}

	public static function log( $path, $ua = '' ) {
		global $wpdb;
		$t    = self::table();
		$hash = md5( $path );
		$now  = current_time( 'mysql', true );
		$ua   = substr( (string) $ua, 0, 190 );

		$upd = $wpdb->query( $wpdb->prepare( "UPDATE {$t} SET hits = hits + 1, last_seen = %s, last_ua = %s WHERE path_hash = %s", $now, $ua, $hash ) ); // phpcs:ignore
		if ( $upd ) {
			return;
		}

		// New URL: only if the table is below its cap (cached, so floods stay cheap).
		$cap   = max( 500, (int) ICP_DB::setting( 'monitor_max' ) );
		$count = get_transient( 'icp_404_count' );
		if ( false === $count ) {
			$count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$t}" ); // phpcs:ignore
			set_transient( 'icp_404_count', $count, 5 * MINUTE_IN_SECONDS );
		}
		if ( $count >= $cap ) {
			return;
		}
		list( $score, $why ) = ICP_Spam::score( $path );
		$wpdb->query( // phpcs:ignore
			$wpdb->prepare(
				"INSERT IGNORE INTO {$t} (path_hash, path, hits, first_seen, last_seen, score, reasons, last_ua, status) VALUES (%s,%s,1,%s,%s,%d,%s,%s,'new')",
				$hash,
				$path,
				$now,
				$now,
				$score,
				substr( implode( ', ', $why ), 0, 255 ),
				$ua
			)
		);
		set_transient( 'icp_404_count', $count + 1, 5 * MINUTE_IN_SECONDS );
	}

	public static function counts() {
		global $wpdb;
		$t = self::table();
		return $wpdb->get_row( "SELECT COUNT(*) AS total, COALESCE(SUM(status='new'),0) AS fresh, COALESCE(SUM(status='new' AND score >= 40),0) AS likely FROM {$t}" ); // phpcs:ignore
	}

	public static function rows( $min_score, $limit = 200 ) {
		global $wpdb;
		$t = self::table();
		return $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$t} WHERE status = 'new' AND score >= %d ORDER BY score DESC, hits DESC, id DESC LIMIT %d", $min_score, $limit ) ); // phpcs:ignore
	}

	public static function ids_by_score( $min_score, $limit = 5000 ) {
		global $wpdb;
		$t = self::table();
		return array_map( 'intval', $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$t} WHERE status = 'new' AND score >= %d ORDER BY score DESC LIMIT %d", $min_score, $limit ) ) ); // phpcs:ignore
	}

	public static function paths_for_ids( array $ids ) {
		global $wpdb;
		$ids = array_filter( array_map( 'absint', $ids ) );
		if ( ! $ids ) {
			return array();
		}
		return $wpdb->get_col( 'SELECT path FROM ' . self::table() . ' WHERE id IN (' . implode( ',', $ids ) . ')' ); // phpcs:ignore
	}

	public static function delete_ids( array $ids ) {
		global $wpdb;
		$ids = array_filter( array_map( 'absint', $ids ) );
		if ( ! $ids ) {
			return 0;
		}
		delete_transient( 'icp_404_count' );
		return (int) $wpdb->query( 'DELETE FROM ' . self::table() . ' WHERE id IN (' . implode( ',', $ids ) . ')' ); // phpcs:ignore
	}

	public static function ignore_ids( array $ids ) {
		global $wpdb;
		$ids = array_filter( array_map( 'absint', $ids ) );
		if ( ! $ids ) {
			return 0;
		}
		return (int) $wpdb->query( "UPDATE " . self::table() . " SET status = 'ignored' WHERE id IN (" . implode( ',', $ids ) . ')' ); // phpcs:ignore
	}

	public static function clear() {
		global $wpdb;
		$wpdb->query( 'TRUNCATE TABLE ' . self::table() ); // phpcs:ignore
		delete_transient( 'icp_404_count' );
	}

	public static function prune() {
		global $wpdb;
		$cut = gmdate( 'Y-m-d H:i:s', time() - 90 * DAY_IN_SECONDS );
		$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . self::table() . " WHERE last_seen < %s AND score < 40", $cut ) ); // phpcs:ignore
	}
}
