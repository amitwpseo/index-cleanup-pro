<?php
/**
 * Front-end: 410 responses and the dead-URL sitemap.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ICP_Frontend {

	const SITEMAP_MAX = 50000;

	public static function init() {
		// wp_loaded: all plugins/CPTs registered, but before WordPress parses the
		// request, runs the main query, redirects or loads any theme template.
		add_action( 'wp_loaded', array( __CLASS__, 'handle_request' ), 0 );
	}

	public static function handle_request() {
		if ( is_admin() || wp_doing_ajax() || wp_doing_cron() || ( defined( 'WP_CLI' ) && WP_CLI ) ) {
			return;
		}
		if ( defined( 'XMLRPC_REQUEST' ) && XMLRPC_REQUEST ) {
			return;
		}
		$method = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( $_SERVER['REQUEST_METHOD'] ) : 'GET'; // phpcs:ignore
		if ( ! in_array( $method, array( 'GET', 'HEAD' ), true ) ) {
			return;
		}

		$path = ICP_Util::request_path();
		if ( '' === $path ) {
			return;
		}

		// Sitemap.
		if ( preg_match( '#^dead-urls(?:-(\d+))?\.xml$#', $path, $m ) ) {
			self::maybe_serve_sitemap( isset( $m[1] ) ? (int) $m[1] : 0 );
			return;
		}

		// List first: one indexed lookup. Safety checks only run on a match,
		// so ordinary page views never pay for them.
		$row = ICP_DB::find_by_path( $path );
		if ( ! $row ) {
			// Pattern rules (cached in one option, so no query when there are none).
			$rid = ICP_Rules::match( $path );
			if ( $rid && ! ICP_Util::is_protected( $path ) && ! ICP_Util::find_conflict( $path ) ) {
				ICP_Rules::record_hit( $rid );
				self::serve_gone( $method );
			}
			return;
		}

		if ( ICP_Util::is_protected( $path ) ) {
			return;
		}

		// Runtime real-page guard (cached in the plugin table): a page published later wins.
		if ( ! (int) $row->override && self::is_real_page_cached( $row, $path ) ) {
			return;
		}

		$is_test = self::is_self_test();
		if ( ! $is_test ) {
			$ua      = isset( $_SERVER['HTTP_USER_AGENT'] ) ? wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) : ''; // phpcs:ignore
			$ip      = ICP_Util::client_ip();
			$is_gbot = ICP_Util::claims_googlebot( $ua ) && ICP_Util::is_verified_googlebot( $ip );
			ICP_DB::record_hit( (int) $row->id, $is_gbot, ICP_Util::ip_hash( $ip ), sanitize_text_field( $ua ) );
		}

		self::serve_gone( $method );
	}

	private static function is_real_page_cached( $row, $path ) {
		$fresh = ! empty( $row->guard_at ) && ( time() - strtotime( $row->guard_at . ' UTC' ) ) < 12 * HOUR_IN_SECONDS;
		if ( $fresh ) {
			return (bool) (int) $row->guard;
		}
		$real = (bool) ICP_Util::find_conflict( $path );
		ICP_DB::set_guard( (int) $row->id, $real );
		return $real;
	}

	private static function is_self_test() {
		$token = (string) ICP_DB::setting( 'test_token' );
		$sent  = isset( $_SERVER['HTTP_X_ICP_TEST'] ) ? (string) $_SERVER['HTTP_X_ICP_TEST'] : ''; // phpcs:ignore
		return '' !== $token && '' !== $sent && hash_equals( $token, $sent );
	}

	public static function serve_gone( $method ) {
		ICP_Cache::no_cache_headers();
		status_header( 410 );
		header( 'X-Robots-Tag: noindex, nofollow', true );
		header( 'Content-Type: text/html; charset=utf-8' );
		if ( 'HEAD' !== $method ) {
			echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="robots" content="noindex,nofollow"><meta name="viewport" content="width=device-width,initial-scale=1"><title>410 Gone</title>'
				. '<style>body{font:16px/1.5 system-ui,sans-serif;max-width:560px;margin:15vh auto;padding:0 16px;color:#222}a{color:#0a58ca}</style></head><body>'
				. '<h1>410 – Page removed</h1><p>This page has been permanently removed.</p>'
				. '<p><a href="' . esc_url( home_url( '/' ) ) . '">Go to the homepage</a></p></body></html>';
		}
		exit;
	}

	/* ------------------------------------------------------------------ */
	/* Sitemap                                                             */
	/* ------------------------------------------------------------------ */

	public static function sitemap_active() {
		$s = ICP_DB::settings();
		if ( empty( $s['sitemap_enabled'] ) ) {
			return false;
		}
		if ( ! empty( $s['sitemap_until'] ) && gmdate( 'Y-m-d' ) > $s['sitemap_until'] ) {
			return false;
		}
		return true;
	}

	private static function maybe_serve_sitemap( $part ) {
		if ( ! self::sitemap_active() ) {
			return; // falls through to WordPress' normal 404.
		}
		global $wpdb;
		$t     = ICP_DB::urls_table();
		$total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$t}" );
		$pages = max( 1, (int) ceil( $total / self::SITEMAP_MAX ) );

		if ( 0 === $part && $pages > 1 ) {
			self::xml_headers();
			echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
			echo '<sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
			for ( $i = 1; $i <= $pages; $i++ ) {
				echo '<sitemap><loc>' . esc_url( home_url( '/dead-urls-' . $i . '.xml' ) ) . '</loc></sitemap>' . "\n";
			}
			echo '</sitemapindex>';
			exit;
		}

		if ( 0 === $part ) {
			$part = 1;
		}
		if ( $part < 1 || $part > $pages ) {
			return;
		}

		self::xml_headers();
		echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
		echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
		$offset = ( $part - 1 ) * self::SITEMAP_MAX;
		$batch  = 2000;
		$done   = 0;
		while ( $done < self::SITEMAP_MAX ) {
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT path, lastmod FROM {$t} ORDER BY id ASC LIMIT %d OFFSET %d", $batch, $offset + $done ) );
			if ( ! $rows ) {
				break;
			}
			foreach ( $rows as $r ) {
				$lm = gmdate( 'Y-m-d\TH:i:s+00:00', strtotime( $r->lastmod . ' UTC' ) );
				echo '<url><loc>' . icp_esc_xml( ICP_Util::full_url( $r->path ) ) . '</loc><lastmod>' . $lm . '</lastmod></url>' . "\n";
			}
			$done += count( $rows );
			if ( count( $rows ) < $batch ) {
				break;
			}
		}
		echo '</urlset>';
		exit;
	}

	private static function xml_headers() {
		ICP_Cache::no_cache_headers();
		status_header( 200 );
		header( 'Content-Type: application/xml; charset=utf-8' );
		header( 'X-Robots-Tag: noindex, follow', true );
	}
}

/** XML-safe escaping that also works on WordPress < 5.5 (no esc_xml). */
function icp_esc_xml( $s ) {
	return htmlspecialchars( (string) $s, ENT_XML1 | ENT_QUOTES, 'UTF-8' );
}
