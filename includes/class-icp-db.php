<?php
/**
 * Database tables, options and data access.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ICP_DB {

	const OPT_SETTINGS = 'icp_settings';
	const OPT_DBVER    = 'icp_db_version';

	public static function urls_table() {
		global $wpdb;
		return $wpdb->prefix . 'icp_urls';
	}

	public static function log_table() {
		global $wpdb;
		return $wpdb->prefix . 'icp_log';
	}

	/* ------------------------------------------------------------------ */
	/* Settings                                                            */
	/* ------------------------------------------------------------------ */

	public static function defaults() {
		return array(
			'sitemap_enabled'    => 1,
			'sitemap_until'      => gmdate( 'Y-m-d', time() + 30 * DAY_IN_SECONDS ),
			'log_retention_days' => 30,
			'ip_source'          => 'remote_addr', // remote_addr | cf | xff
			'purge_cache'        => 1,
			'delete_on_uninstall'=> 0,
			'test_token'         => '',
			'monitor_enabled'    => 1,
			'monitor_max'        => 5000,
			'search_mode'        => 'spam', // off | spam | all
			'search_threshold'   => 40,
			'spam_words'         => '',
			'retest_enabled'     => 1,
			'alert_enabled'      => 1,
			'alert_email'        => '',
			'report_prepared_for'  => '',
			'report_brand'         => 1,
			'report_company'       => 'BLSS – Best Local SEO Services & Development',
			'report_logo'          => '',
			'report_email_enabled' => 0,
			'report_email_to'      => '',
			'report_email_freq'    => 'monthly', // weekly | monthly | quarterly
		);
	}

	public static function settings() {
		$s = get_option( self::OPT_SETTINGS, array() );
		if ( ! is_array( $s ) ) {
			$s = array();
		}
		return array_merge( self::defaults(), $s );
	}

	public static function setting( $key ) {
		$s = self::settings();
		return isset( $s[ $key ] ) ? $s[ $key ] : null;
	}

	public static function update_settings( array $new ) {
		update_option( self::OPT_SETTINGS, array_merge( self::settings(), $new ), false );
	}

	/* ------------------------------------------------------------------ */
	/* Install / upgrade                                                   */
	/* ------------------------------------------------------------------ */

	public static function activate() {
		self::install();
		if ( false === get_option( self::OPT_SETTINGS, false ) ) {
			$d               = self::defaults();
			$d['test_token'] = wp_generate_password( 32, false );
			add_option( self::OPT_SETTINGS, $d, '', false );
		} elseif ( '' === (string) self::setting( 'test_token' ) ) {
			self::update_settings( array( 'test_token' => wp_generate_password( 32, false ) ) );
		}
		if ( ! wp_next_scheduled( 'icp_daily_cleanup' ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'icp_daily_cleanup' );
		}
		if ( ! wp_next_scheduled( 'icp_weekly_retest' ) ) {
			wp_schedule_event( time() + DAY_IN_SECONDS, 'weekly', 'icp_weekly_retest' );
		}
		if ( class_exists( 'ICP_Rules', false ) ) {
			ICP_Rules::rebuild_cache();
		}
		if ( class_exists( 'ICP_Report', false ) ) {
			ICP_Report::snapshot();
		}
	}

	public static function deactivate() {
		wp_clear_scheduled_hook( 'icp_daily_cleanup' );
		wp_clear_scheduled_hook( 'icp_weekly_retest' );
		wp_clear_scheduled_hook( 'icp_retest_tick' );
	}

	public static function maybe_upgrade() {
		if ( get_option( self::OPT_DBVER ) !== ICP_DB_VERSION ) {
			self::activate();
		}
	}

	public static function install() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$charset = $wpdb->get_charset_collate();
		$urls    = self::urls_table();
		$log     = self::log_table();

		dbDelta(
			"CREATE TABLE {$urls} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  path_hash char(32) NOT NULL,
  path text NOT NULL,
  note varchar(255) NOT NULL DEFAULT '',
  override tinyint(1) NOT NULL DEFAULT 0,
  date_added datetime NOT NULL,
  lastmod datetime NOT NULL,
  hits bigint(20) unsigned NOT NULL DEFAULT 0,
  last_hit datetime NULL DEFAULT NULL,
  gbot_hits bigint(20) unsigned NOT NULL DEFAULT 0,
  last_gbot datetime NULL DEFAULT NULL,
  test_code smallint(5) NOT NULL DEFAULT 0,
  test_note varchar(255) NOT NULL DEFAULT '',
  test_at datetime NULL DEFAULT NULL,
  guard tinyint(1) NOT NULL DEFAULT 0,
  guard_at datetime NULL DEFAULT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY path_hash (path_hash),
  KEY gbot_hits (gbot_hits),
  KEY test_code (test_code)
) {$charset};"
		);

		dbDelta(
			"CREATE TABLE {$log} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  url_id bigint(20) unsigned NOT NULL,
  hit_at datetime NOT NULL,
  is_gbot tinyint(1) NOT NULL DEFAULT 0,
  ip_hash char(64) NOT NULL DEFAULT '',
  user_agent varchar(255) NOT NULL DEFAULT '',
  PRIMARY KEY  (id),
  KEY url_id (url_id),
  KEY hit_at (hit_at)
) {$charset};"
		);

		$rules = $wpdb->prefix . 'icp_rules';
		dbDelta(
			"CREATE TABLE {$rules} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  rtype varchar(10) NOT NULL DEFAULT 'prefix',
  pattern varchar(255) NOT NULL,
  note varchar(255) NOT NULL DEFAULT '',
  active tinyint(1) NOT NULL DEFAULT 1,
  date_added datetime NOT NULL,
  hits bigint(20) unsigned NOT NULL DEFAULT 0,
  last_hit datetime NULL DEFAULT NULL,
  PRIMARY KEY  (id)
) {$charset};"
		);

		$m404 = $wpdb->prefix . 'icp_404';
		dbDelta(
			"CREATE TABLE {$m404} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  path_hash char(32) NOT NULL,
  path varchar(500) NOT NULL,
  hits bigint(20) unsigned NOT NULL DEFAULT 1,
  first_seen datetime NOT NULL,
  last_seen datetime NOT NULL,
  score smallint(5) NOT NULL DEFAULT 0,
  reasons varchar(255) NOT NULL DEFAULT '',
  last_ua varchar(190) NOT NULL DEFAULT '',
  status varchar(10) NOT NULL DEFAULT 'new',
  PRIMARY KEY  (id),
  UNIQUE KEY path_hash (path_hash),
  KEY score (score),
  KEY status (status)
) {$charset};"
		);

		$snap = $wpdb->prefix . 'icp_snap';
		dbDelta(
			"CREATE TABLE {$snap} (
  snap_date date NOT NULL,
  total int(10) unsigned NOT NULL DEFAULT 0,
  hits bigint(20) unsigned NOT NULL DEFAULT 0,
  gbot_seen int(10) unsigned NOT NULL DEFAULT 0,
  test_ok int(10) unsigned NOT NULL DEFAULT 0,
  test_bad int(10) unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY  (snap_date)
) {$charset};"
		);

		update_option( self::OPT_DBVER, ICP_DB_VERSION, false );
	}

	/* ------------------------------------------------------------------ */
	/* URL rows                                                            */
	/* ------------------------------------------------------------------ */

	public static function find_by_path( $path ) {
		global $wpdb;
		$t = self::urls_table();
		return $wpdb->get_row( $wpdb->prepare( "SELECT id, path, override, guard, guard_at FROM {$t} WHERE path_hash = %s LIMIT 1", md5( $path ) ) );
	}

	/** Cache the real-page-guard result for a row. */
	public static function set_guard( $id, $is_real ) {
		global $wpdb;
		$wpdb->update(
			self::urls_table(),
			array(
				'guard'    => $is_real ? 1 : 0,
				'guard_at' => current_time( 'mysql', true ),
			),
			array( 'id' => absint( $id ) ),
			array( '%d', '%s' ),
			array( '%d' )
		);
	}

	public static function existing_hashes( array $hashes ) {
		global $wpdb;
		$t     = self::urls_table();
		$found = array();
		foreach ( array_chunk( $hashes, 500 ) as $chunk ) {
			$ph   = implode( ',', array_fill( 0, count( $chunk ), '%s' ) );
			$rows = $wpdb->get_col( $wpdb->prepare( "SELECT path_hash FROM {$t} WHERE path_hash IN ($ph)", $chunk ) );
			foreach ( $rows as $h ) {
				$found[ $h ] = true;
			}
		}
		return $found;
	}

	/**
	 * Insert paths. $items = [ [ 'path' => ..., 'override' => 0|1 ], ... ]
	 * Returns number inserted.
	 */
	public static function insert_paths( array $items, $note = '' ) {
		global $wpdb;
		$t     = self::urls_table();
		$now   = current_time( 'mysql', true );
		$note  = substr( (string) $note, 0, 255 );
		$count = 0;
		foreach ( array_chunk( $items, 200 ) as $chunk ) {
			$values = array();
			$args   = array();
			foreach ( $chunk as $it ) {
				$values[] = '(%s,%s,%s,%d,%s,%s)';
				array_push( $args, md5( $it['path'] ), $it['path'], $note, empty( $it['override'] ) ? 0 : 1, $now, $now );
			}
			$sql = "INSERT IGNORE INTO {$t} (path_hash, path, note, override, date_added, lastmod) VALUES " . implode( ',', $values );
			$res = $wpdb->query( $wpdb->prepare( $sql, $args ) );
			if ( $res ) {
				$count += (int) $res;
			}
		}
		return $count;
	}

	public static function delete_ids( array $ids ) {
		global $wpdb;
		$ids = array_filter( array_map( 'absint', $ids ) );
		if ( ! $ids ) {
			return 0;
		}
		$in = implode( ',', $ids );
		$wpdb->query( 'DELETE FROM ' . self::log_table() . " WHERE url_id IN ($in)" );
		return (int) $wpdb->query( 'DELETE FROM ' . self::urls_table() . " WHERE id IN ($in)" );
	}

	public static function delete_all() {
		global $wpdb;
		$wpdb->query( 'TRUNCATE TABLE ' . self::log_table() );
		$wpdb->query( 'TRUNCATE TABLE ' . self::urls_table() );
	}

	public static function restamp_ids( array $ids ) {
		global $wpdb;
		$ids = array_filter( array_map( 'absint', $ids ) );
		if ( ! $ids ) {
			return 0;
		}
		$in = implode( ',', $ids );
		return (int) $wpdb->query( $wpdb->prepare( 'UPDATE ' . self::urls_table() . " SET lastmod = %s WHERE id IN ($in)", current_time( 'mysql', true ) ) );
	}

	public static function update_note( $id, $note ) {
		global $wpdb;
		return $wpdb->update( self::urls_table(), array( 'note' => substr( (string) $note, 0, 255 ) ), array( 'id' => absint( $id ) ), array( '%s' ), array( '%d' ) );
	}

	public static function get_row( $id ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::urls_table() . ' WHERE id = %d', absint( $id ) ) );
	}

	public static function record_hit( $url_id, $is_gbot, $ip_hash, $ua ) {
		global $wpdb;
		$now = current_time( 'mysql', true );
		$t   = self::urls_table();
		if ( $is_gbot ) {
			$wpdb->query( $wpdb->prepare( "UPDATE {$t} SET hits = hits + 1, last_hit = %s, gbot_hits = gbot_hits + 1, last_gbot = %s WHERE id = %d", $now, $now, $url_id ) );
		} else {
			$wpdb->query( $wpdb->prepare( "UPDATE {$t} SET hits = hits + 1, last_hit = %s WHERE id = %d", $now, $url_id ) );
		}
		$wpdb->insert(
			self::log_table(),
			array(
				'url_id'     => $url_id,
				'hit_at'     => $now,
				'is_gbot'    => $is_gbot ? 1 : 0,
				'ip_hash'    => $ip_hash,
				'user_agent' => substr( (string) $ua, 0, 255 ),
			),
			array( '%d', '%s', '%d', '%s', '%s' )
		);
	}

	public static function save_test( $id, $code, $note ) {
		global $wpdb;
		$wpdb->update(
			self::urls_table(),
			array(
				'test_code' => (int) $code,
				'test_note' => substr( (string) $note, 0, 255 ),
				'test_at'   => current_time( 'mysql', true ),
			),
			array( 'id' => absint( $id ) ),
			array( '%d', '%s', '%s' ),
			array( '%d' )
		);
	}

	public static function stats() {
		global $wpdb;
		$t = self::urls_table();
		$r = $wpdb->get_row(
			"SELECT COUNT(*) AS total,
			        COALESCE(SUM(hits),0) AS hits,
			        COALESCE(SUM(CASE WHEN gbot_hits > 0 THEN 1 ELSE 0 END),0) AS gbot_seen,
			        COALESCE(SUM(CASE WHEN test_code = 410 THEN 1 ELSE 0 END),0) AS test_ok,
			        COALESCE(SUM(CASE WHEN test_code <> 0 AND test_code <> 410 THEN 1 ELSE 0 END),0) AS test_bad,
			        COALESCE(SUM(CASE WHEN test_code = 0 THEN 1 ELSE 0 END),0) AS untested
			   FROM {$t}"
		);
		return $r;
	}

	public static function recent_hits( $limit = 20 ) {
		global $wpdb;
		return $wpdb->get_results(
			$wpdb->prepare(
				'SELECT l.hit_at, l.is_gbot, l.user_agent, u.path FROM ' . self::log_table() . ' l LEFT JOIN ' . self::urls_table() . ' u ON u.id = l.url_id ORDER BY l.id DESC LIMIT %d',
				$limit
			)
		);
	}

	public static function prune_log() {
		global $wpdb;
		$days = max( 1, (int) self::setting( 'log_retention_days' ) );
		$cut  = gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS );
		$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . self::log_table() . ' WHERE hit_at < %s', $cut ) );
		if ( class_exists( 'ICP_Monitor', false ) ) {
			ICP_Monitor::prune();
		}
	}
}
