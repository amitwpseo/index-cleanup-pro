<?php
/**
 * Status tester (shared by the dashboard and the weekly background re-test)
 * and the email alert.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ICP_Tester {

	const STATE = 'icp_retest_state';
	const LAST  = 'icp_last_retest';

	public static function init() {
		add_action( 'icp_weekly_retest', array( __CLASS__, 'start' ) );
		add_action( 'icp_retest_tick', array( __CLASS__, 'tick' ) );
	}

	/** Request one URL from this server and judge the answer. Returns [ code, note ]. */
	public static function test_url( $url, $token ) {
		$resp = wp_remote_get(
			$url,
			array(
				'timeout'             => 15,
				'redirection'         => 0,
				'limit_response_size' => 8192,
				'user-agent'          => 'IndexCleanupPro-StatusTest/' . ICP_VERSION . '; ' . home_url( '/' ),
				'headers'             => array( 'X-ICP-Test' => $token ),
				'sslverify'           => apply_filters( 'https_local_ssl_verify', false ),
			)
		);
		if ( is_wp_error( $resp ) ) {
			return array( 0, 'error: ' . $resp->get_error_message() );
		}
		$code = (int) wp_remote_retrieve_response_code( $resp );
		$body = (string) wp_remote_retrieve_body( $resp );
		if ( 410 === $code ) {
			return array( 410, 'OK' );
		}
		if ( $code >= 300 && $code < 400 ) {
			$loc = wp_remote_retrieve_header( $resp, 'location' );
			return array( $code, 'redirects to ' . ( $loc ? $loc : '?' ) . ' — Google keeps redirected URLs' );
		}
		$challenge = preg_match( '/One moment, please|captcha|cf-chl|challenge-platform|Just a moment|imunify|bot verification/i', $body );
		if ( in_array( $code, array( 403, 429, 503 ), true ) || ( 200 === $code && $challenge ) ) {
			return array( $code, 'blocked by host (firewall/bot check) — check this URL in a browser' );
		}
		if ( 404 === $code ) {
			return array( 404, 'returns 404 not 410 — plugin not reached (cache or server rule?)' );
		}
		if ( 200 === $code ) {
			return array( 200, 'page loads (200) — a cached copy or a real page; purge cache and check' );
		}
		return array( $code, 'unexpected status' );
	}

	/* ------------------------------------------------------------------ */
	/* Background re-test (WP-Cron, small batches)                         */
	/* ------------------------------------------------------------------ */

	/** Begin a full re-test. $force ignores the on/off setting (used by the button). */
	public static function start( $force = false ) {
		if ( true !== $force && ! ICP_DB::setting( 'retest_enabled' ) ) {
			return false;
		}
		global $wpdb;
		$total = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . ICP_DB::urls_table() ); // phpcs:ignore
		if ( ! $total ) {
			return false;
		}
		update_option(
			self::STATE,
			array(
				'after'   => 0,
				'total'   => $total,
				'done'    => 0,
				'ok'      => 0,
				'bad'     => 0,
				'blocked' => 0,
				'started' => time(),
			),
			false
		);
		wp_clear_scheduled_hook( 'icp_retest_tick' );
		wp_schedule_single_event( time() + 10, 'icp_retest_tick' );
		return true;
	}

	public static function running() {
		$s = get_option( self::STATE );
		return is_array( $s ) && ! empty( $s['started'] ) && empty( $s['finished'] );
	}

	public static function tick() {
		$s = get_option( self::STATE );
		if ( ! is_array( $s ) || ! empty( $s['finished'] ) ) {
			return;
		}
		global $wpdb;
		$t     = ICP_DB::urls_table();
		$token = (string) ICP_DB::setting( 'test_token' );
		$begin = time();

		do {
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT id, path FROM {$t} WHERE id > %d ORDER BY id ASC LIMIT 5", (int) $s['after'] ) ); // phpcs:ignore
			if ( ! $rows ) {
				$s['finished'] = time();
				break;
			}
			foreach ( $rows as $r ) {
				list( $code, $note ) = self::test_url( ICP_Util::full_url( $r->path ), $token );
				if ( 0 === $code ) { // one retry for timeouts.
					list( $code, $note ) = self::test_url( ICP_Util::full_url( $r->path ), $token );
				}
				ICP_DB::save_test( $r->id, $code, $note );
				if ( 410 === $code ) {
					$s['ok']++;
				} elseif ( 0 === strpos( $note, 'blocked' ) ) {
					$s['blocked']++;
				} else {
					$s['bad']++;
				}
				$s['done']++;
				$s['after'] = (int) $r->id;
			}
		} while ( time() - $begin < 20 );

		update_option( self::STATE, $s, false );

		if ( ! empty( $s['finished'] ) ) {
			self::finish( $s );
		} else {
			wp_schedule_single_event( time() + 20, 'icp_retest_tick' );
		}
	}

	private static function finish( array $s ) {
		update_option(
			self::LAST,
			array(
				'at'      => time(),
				'total'   => (int) $s['done'],
				'ok'      => (int) $s['ok'],
				'bad'     => (int) $s['bad'],
				'blocked' => (int) $s['blocked'],
			),
			false
		);
		if ( (int) $s['bad'] > 0 && ICP_DB::setting( 'alert_enabled' ) ) {
			self::send_alert( (int) $s['bad'], (int) $s['done'] );
		}
	}

	/* ------------------------------------------------------------------ */
	/* Alert email                                                         */
	/* ------------------------------------------------------------------ */

	public static function alert_address() {
		$to = sanitize_email( (string) ICP_DB::setting( 'alert_email' ) );
		return $to ? $to : get_option( 'admin_email' );
	}

	public static function send_alert( $bad, $total, $is_test = false ) {
		global $wpdb;
		$t    = ICP_DB::urls_table();
		$rows = $wpdb->get_results( "SELECT path, test_code, test_note FROM {$t} WHERE test_code <> 0 AND test_code <> 410 AND test_note NOT LIKE 'blocked%' ORDER BY id ASC LIMIT 15" ); // phpcs:ignore
		$site = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );

		$body  = $is_test ? "This is a test email from Hacked Site Index Cleaner. Alerts are working.\n\n" : '';
		$body .= sprintf( "%d of %d listed URLs no longer return 410 Gone on %s.\n\n", $bad, $total, home_url( '/' ) );
		$body .= "Examples:\n";
		foreach ( (array) $rows as $r ) {
			$body .= sprintf( "- %s  →  %d (%s)\n", ICP_Util::full_url( $r->path ), $r->test_code, $r->test_note );
		}
		$body .= "\nCommon causes: a cache plugin serving old pages, a theme/plugin update, a new redirect rule, or the real page being published again.\n";
		$body .= "Open the dashboard and use \"Re-test non-410 only\" after fixing: " . admin_url( 'tools.php?page=hacked-site-index-cleaner' ) . "\n";

		return wp_mail( self::alert_address(), '[' . $site . '] Index Cleaner: ' . $bad . ' URL(s) not returning 410', $body );
	}
}
