<?php
/**
 * Path normalisation, safety checks, Googlebot verification.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ICP_Util {

	/** Path of the site's home URL (for sub-folder installs), normalised. */
	public static function home_path() {
		static $hp = null;
		if ( null === $hp ) {
			$p  = wp_parse_url( home_url( '/' ), PHP_URL_PATH );
			$hp = self::clean( $p ? $p : '' );
		}
		return $hp;
	}

	/** Decode, lower-case, strip query/fragment and surrounding slashes. */
	public static function clean( $path ) {
		$path = (string) $path;
		$cut  = strcspn( $path, '?#' );
		$path = substr( $path, 0, $cut );
		$path = rawurldecode( $path );
		$path = function_exists( 'mb_strtolower' ) ? mb_strtolower( $path, 'UTF-8' ) : strtolower( $path );
		$path = preg_replace( '#/+#', '/', $path );
		return trim( $path, "/ \t\r\n" );
	}

	/**
	 * Turn a full URL or a path into a site-relative normalised path.
	 * Returns [ 'path' => string, 'host' => string|null ].
	 */
	public static function normalize_input( $raw ) {
		$raw  = trim( (string) $raw, " \t\r\n\"'<>" );
		$host = null;
		if ( preg_match( '#^(https?:)?//#i', $raw ) ) {
			$parts = wp_parse_url( ( 0 === strpos( $raw, '//' ) ? 'https:' : '' ) . $raw );
			$host  = isset( $parts['host'] ) ? strtolower( $parts['host'] ) : null;
			$raw   = isset( $parts['path'] ) ? $parts['path'] : '';
		}
		// Bare domain without scheme, e.g. "example.com/page" or "www.example.com/page/".
		if ( null === $host && '' !== $raw && '/' !== $raw[0] ) {
			$slash = strpos( $raw, '/' );
			$first = strtolower( false === $slash ? $raw : substr( $raw, 0, $slash ) );
			$bare  = preg_replace( '/^www\./', '', $first );
			if ( $bare === self::site_host() ) {
				$host = $first;
				$raw  = false === $slash ? '' : substr( $raw, $slash );
			} elseif ( false !== $slash && preg_match( '/^(www\.)?([a-z0-9-]+\.)+[a-z]{2,}$/', $first ) ) {
				$host = $first;
				$raw  = substr( $raw, $slash );
			}
		}
		$path = self::clean( $raw );
		$home = self::home_path();
		if ( '' !== $home && ( $path === $home || 0 === strpos( $path, $home . '/' ) ) ) {
			$path = ltrim( substr( $path, strlen( $home ) ), '/' );
		}
		return array(
			'path' => $path,
			'host' => $host,
		);
	}

	/** Normalised path of the current request, relative to home. */
	public static function request_path() {
		$uri = isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : ''; // phpcs:ignore
		$n   = self::normalize_input( $uri );
		return $n['path'];
	}

	public static function site_host() {
		$h = wp_parse_url( home_url(), PHP_URL_HOST );
		return strtolower( preg_replace( '/^www\./i', '', (string) $h ) );
	}

	public static function host_matches( $host ) {
		if ( null === $host ) {
			return true;
		}
		return preg_replace( '/^www\./i', '', $host ) === self::site_host();
	}

	/** Build the absolute URL for a stored path. */
	public static function full_url( $path ) {
		$enc = implode( '/', array_map( 'rawurlencode', explode( '/', $path ) ) );
		return trailingslashit( home_url( '/' . $enc ) );
	}

	/* ------------------------------------------------------------------ */
	/* Safety                                                              */
	/* ------------------------------------------------------------------ */

	/** Paths that must never get a 410, whatever is on the list. */
	public static function is_protected( $path ) {
		if ( '' === $path ) {
			return 'homepage';
		}
		$first = strtok( $path, '/' );
		$fixed = array( 'wp-admin', 'wp-login.php', 'wp-json', 'wp-content', 'wp-includes', 'wp-cron.php', 'xmlrpc.php', 'robots.txt', 'favicon.ico', 'sitemap_index.xml', 'wp-sitemap.xml', 'dead-urls.xml' );
		if ( in_array( $first, $fixed, true ) || in_array( $path, $fixed, true ) ) {
			return 'WordPress system path';
		}
		if ( function_exists( 'rest_get_url_prefix' ) && $first === rest_get_url_prefix() ) {
			return 'REST API';
		}
		if ( preg_match( '#^dead-urls(-\d+)?\.xml$#', $path ) ) {
			return 'plugin sitemap';
		}
		if ( function_exists( 'wc_get_page_id' ) ) {
			foreach ( array( 'cart', 'checkout', 'myaccount', 'shop' ) as $wc ) {
				$pid = wc_get_page_id( $wc );
				if ( $pid > 0 ) {
					$link = get_permalink( $pid );
					if ( $link ) {
						$n = self::normalize_input( $link );
						if ( '' !== $n['path'] && ( $path === $n['path'] || 0 === strpos( $path, $n['path'] . '/' ) ) ) {
							return 'WooCommerce ' . $wc . ' page';
						}
					}
				}
			}
		}
		return false;
	}

	/**
	 * Real-page guard. Returns a WP_Post if the path resolves to published,
	 * public content (post, page, product, any public CPT), else null.
	 */
	public static function find_conflict( $path ) {
		if ( '' === $path ) {
			return null;
		}
		$url = self::full_url( $path );
		$id  = url_to_postid( $url );
		if ( ! $id ) {
			// Hierarchical fallback (pages / products without rewrite match).
			$types = get_post_types( array( 'public' => true ), 'names' );
			unset( $types['attachment'] );
			$p = get_page_by_path( $path, OBJECT, array_values( $types ) );
			if ( $p ) {
				$id = $p->ID;
			}
		}
		if ( ! $id ) {
			return null;
		}
		$post = get_post( $id );
		if ( ! $post || 'publish' !== $post->post_status ) {
			return null;
		}
		$pto = get_post_type_object( $post->post_type );
		if ( ! $pto || ! $pto->public ) {
			return null;
		}
		return $post;
	}

	/* ------------------------------------------------------------------ */
	/* Visitor / Googlebot                                                 */
	/* ------------------------------------------------------------------ */

	public static function client_ip() {
		$src = ICP_DB::setting( 'ip_source' );
		$ip  = '';
		if ( 'cf' === $src && ! empty( $_SERVER['HTTP_CF_CONNECTING_IP'] ) ) {
			$ip = $_SERVER['HTTP_CF_CONNECTING_IP']; // phpcs:ignore
		} elseif ( 'xff' === $src && ! empty( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) {
			$parts = explode( ',', $_SERVER['HTTP_X_FORWARDED_FOR'] ); // phpcs:ignore
			$ip    = trim( $parts[0] );
		}
		if ( ! $ip && ! empty( $_SERVER['REMOTE_ADDR'] ) ) {
			$ip = $_SERVER['REMOTE_ADDR']; // phpcs:ignore
		}
		$ip = trim( (string) $ip );
		return filter_var( $ip, FILTER_VALIDATE_IP ) ? $ip : '';
	}

	public static function ip_hash( $ip ) {
		return $ip ? hash( 'sha256', $ip . wp_salt( 'auth' ) ) : '';
	}

	public static function claims_googlebot( $ua ) {
		return (bool) preg_match( '/Googlebot|Google-InspectionTool|GoogleOther|AdsBot-Google|Storebot-Google/i', (string) $ua );
	}

	/**
	 * Reverse + forward DNS check, cached for 7 days per IP.
	 * Only called when the user agent claims to be Google.
	 */
	public static function is_verified_googlebot( $ip ) {
		if ( ! $ip ) {
			return false;
		}
		$key    = 'icp_gb_' . md5( $ip );
		$cached = get_transient( $key );
		if ( false !== $cached ) {
			return '1' === $cached;
		}
		$ok   = false;
		$host = @gethostbyaddr( $ip ); // phpcs:ignore
		if ( $host && $host !== $ip && preg_match( '/\.(googlebot\.com|google\.com|googleusercontent\.com)$/i', $host ) ) {
			if ( false !== strpos( $ip, ':' ) ) {
				$recs = function_exists( 'dns_get_record' ) ? @dns_get_record( $host, DNS_AAAA ) : array(); // phpcs:ignore
				foreach ( (array) $recs as $r ) {
					if ( isset( $r['ipv6'] ) && inet_pton( $r['ipv6'] ) === inet_pton( $ip ) ) {
						$ok = true;
						break;
					}
				}
			} else {
				$fwd = @gethostbynamel( $host ); // phpcs:ignore
				$ok  = is_array( $fwd ) && in_array( $ip, $fwd, true );
			}
		}
		set_transient( $key, $ok ? '1' : '0', 7 * DAY_IN_SECONDS );
		return $ok;
	}
}
