<?php
/**
 * Pattern rules: remove whole groups of URLs (starts with / contains / regex).
 * Real pages and protected paths always win.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ICP_Rules {

	const CACHE = 'icp_rules_cache';

	/** Paths of ordinary pages. A rule that matches any of these is too broad and is refused. */
	private static $probes = array( 'about', 'about-us', 'contact', 'contact-us', 'blog', 'services', 'products', 'shop', 'privacy-policy', 'news', 'faq', 'pricing', 'home', 'support' );

	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'icp_rules';
	}

	/* ------------------------------------------------------------------ */
	/* Matching (pure)                                                     */
	/* ------------------------------------------------------------------ */

	public static function matches( $type, $pattern, $path ) {
		$path = (string) $path;
		switch ( $type ) {
			case 'prefix':
				return '' !== $pattern && 0 === strpos( $path . '/', $pattern );
			case 'contains':
				return '' !== $pattern && false !== strpos( $path, $pattern );
			case 'regex':
				$r = @preg_match( self::regex( $pattern ), $path ); // phpcs:ignore
				return 1 === $r;
		}
		return false;
	}

	public static function regex( $pattern ) {
		return '~' . str_replace( '~', '\~', $pattern ) . '~iu';
	}

	/**
	 * Clean and validate user input.
	 * @return array [ bool ok, string pattern_or_error ]
	 */
	public static function normalize( $type, $pattern ) {
		$pattern = trim( (string) $pattern );
		if ( ! in_array( $type, array( 'prefix', 'contains', 'regex' ), true ) ) {
			return array( false, 'Unknown rule type.' );
		}
		if ( '' === $pattern ) {
			return array( false, 'Enter a pattern.' );
		}
		if ( strlen( $pattern ) > 200 ) {
			return array( false, 'Pattern is too long (200 characters max).' );
		}
		if ( 'regex' === $type ) {
			if ( false === @preg_match( self::regex( $pattern ), '' ) ) { // phpcs:ignore
				return array( false, 'This regular expression is not valid.' );
			}
		} else {
			if ( preg_match( '#^(https?:)?//#i', $pattern ) ) {
				$parts   = wp_parse_url( ( 0 === strpos( $pattern, '//' ) ? 'https:' : '' ) . $pattern );
				$pattern = isset( $parts['path'] ) ? $parts['path'] : '';
			}
			$cut     = strcspn( $pattern, '?#' );
			$pattern = substr( $pattern, 0, $cut );
			$pattern = rawurldecode( $pattern );
			$pattern = function_exists( 'mb_strtolower' ) ? mb_strtolower( $pattern, 'UTF-8' ) : strtolower( $pattern );
			$pattern = ltrim( preg_replace( '#/+#', '/', $pattern ), '/' );
			$home    = ICP_Util::home_path();
			if ( '' !== $home && 0 === strpos( $pattern, $home . '/' ) ) {
				$pattern = substr( $pattern, strlen( $home ) + 1 );
			}
			if ( strlen( trim( $pattern, '/' ) ) < 3 ) {
				return array( false, 'The pattern is too short. Use at least 3 characters so real pages are not caught by accident.' );
			}
		}
		foreach ( self::$probes as $probe ) {
			if ( self::matches( $type, $pattern, $probe ) ) {
				return array( false, 'This pattern also matches ordinary pages such as “' . $probe . '”. Make it more specific.' );
			}
		}
		if ( self::matches( $type, $pattern, '' ) ) {
			return array( false, 'This pattern matches the homepage. Make it more specific.' );
		}
		return array( true, $pattern );
	}

	/* ------------------------------------------------------------------ */
	/* Storage                                                             */
	/* ------------------------------------------------------------------ */

	public static function add( $type, $pattern, $note = '' ) {
		global $wpdb;
		$ok = $wpdb->insert(
			self::table(),
			array(
				'rtype'      => $type,
				'pattern'    => $pattern,
				'note'       => substr( (string) $note, 0, 255 ),
				'active'     => 1,
				'date_added' => current_time( 'mysql', true ),
			),
			array( '%s', '%s', '%s', '%d', '%s' )
		);
		self::rebuild_cache();
		return $ok ? (int) $wpdb->insert_id : 0;
	}

	public static function exists( $type, $pattern ) {
		global $wpdb;
		return (bool) $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . self::table() . ' WHERE rtype = %s AND pattern = %s LIMIT 1', $type, $pattern ) ); // phpcs:ignore
	}

	public static function all() {
		global $wpdb;
		return $wpdb->get_results( 'SELECT * FROM ' . self::table() . ' ORDER BY id DESC' ); // phpcs:ignore
	}

	public static function delete( $id ) {
		global $wpdb;
		$wpdb->delete( self::table(), array( 'id' => absint( $id ) ), array( '%d' ) );
		self::rebuild_cache();
	}

	public static function toggle( $id ) {
		global $wpdb;
		$wpdb->query( $wpdb->prepare( 'UPDATE ' . self::table() . ' SET active = 1 - active WHERE id = %d', absint( $id ) ) ); // phpcs:ignore
		self::rebuild_cache();
	}

	public static function count() {
		global $wpdb;
		return (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . self::table() ); // phpcs:ignore
	}

	/** Active rules kept in one autoloaded option so a normal page view costs no query. */
	public static function rebuild_cache() {
		global $wpdb;
		$rows  = $wpdb->get_results( 'SELECT id, rtype, pattern FROM ' . self::table() . ' WHERE active = 1' ); // phpcs:ignore
		$cache = array();
		foreach ( (array) $rows as $r ) {
			$cache[] = array( (int) $r->id, $r->rtype, $r->pattern );
		}
		update_option( self::CACHE, $cache, true );
	}

	/** Returns the id of the first active rule that matches, or 0. */
	public static function match( $path ) {
		$cache = get_option( self::CACHE, array() );
		if ( ! $cache || ! is_array( $cache ) ) {
			return 0;
		}
		foreach ( $cache as $r ) {
			if ( self::matches( $r[1], $r[2], $path ) ) {
				return (int) $r[0];
			}
		}
		return 0;
	}

	public static function record_hit( $id ) {
		global $wpdb;
		$wpdb->query( $wpdb->prepare( 'UPDATE ' . self::table() . ' SET hits = hits + 1, last_hit = %s WHERE id = %d', current_time( 'mysql', true ), absint( $id ) ) ); // phpcs:ignore
	}

	/* ------------------------------------------------------------------ */
	/* Preview (admin)                                                     */
	/* ------------------------------------------------------------------ */

	/**
	 * What would this rule touch?
	 * Returns counts and samples for: the 404 log, the current dead list, and real pages that would be protected.
	 */
	public static function preview( $type, $pattern ) {
		global $wpdb;
		$out = array(
			'log'         => array( 'n' => 0, 'sample' => array() ),
			'list'        => array( 'n' => 0, 'sample' => array() ),
			'real'        => array( 'n' => 0, 'sample' => array(), 'capped' => false ),
		);

		$paths = $wpdb->get_col( 'SELECT path FROM ' . ICP_Monitor::table() . ' LIMIT 20000' ); // phpcs:ignore
		foreach ( (array) $paths as $p ) {
			if ( self::matches( $type, $pattern, $p ) ) {
				$out['log']['n']++;
				if ( count( $out['log']['sample'] ) < 8 ) {
					$out['log']['sample'][] = $p;
				}
			}
		}

		$paths = $wpdb->get_col( 'SELECT path FROM ' . ICP_DB::urls_table() . ' LIMIT 50000' ); // phpcs:ignore
		foreach ( (array) $paths as $p ) {
			if ( self::matches( $type, $pattern, $p ) ) {
				$out['list']['n']++;
				if ( count( $out['list']['sample'] ) < 5 ) {
					$out['list']['sample'][] = $p;
				}
			}
		}

		$types = get_post_types( array( 'public' => true ), 'names' );
		unset( $types['attachment'] );
		$ids = get_posts(
			array(
				'post_type'      => array_values( $types ),
				'post_status'    => 'publish',
				'posts_per_page' => 3000,
				'fields'         => 'ids',
				'orderby'        => 'ID',
				'no_found_rows'  => true,
			)
		);
		$out['real']['capped'] = count( $ids ) >= 3000;
		foreach ( $ids as $id ) {
			$link = get_permalink( $id );
			if ( ! $link ) {
				continue;
			}
			$n = ICP_Util::normalize_input( $link );
			if ( '' !== $n['path'] && self::matches( $type, $pattern, $n['path'] ) ) {
				$out['real']['n']++;
				if ( count( $out['real']['sample'] ) < 5 ) {
					$out['real']['sample'][] = $n['path'];
				}
			}
		}
		return $out;
	}
}
